<?php
/**
 * Address-activity data layer: its own SQLite DB (db/activity.sqlite) plus the read helpers for
 * the "active addresses / day" and "coin-days-destroyed" charts. The heavy full-chain walk that
 * POPULATES it (a UTXO-map pass over every block) is an offline job, tools/addr-activity.php -
 * never a per-request operation. The site only ever reads the warm daily rows here.
 *
 * Coin-days-destroyed (CDD): when an output is spent, it "destroys" value x age-in-days of
 * accumulated coin-days. It is the classic on-chain economic-activity gauge - large old-coin
 * moves spike it, exchange shuffles of fresh coins barely register. Active addresses/day counts
 * the distinct addresses that either sent or received in that UTC day. Both need per-transaction
 * input resolution (an input only references a prevout by txid:vout), which is exactly what the
 * walker's UTXO map provides; that is why this cannot be derived from the block_index aggregates.
 *
 * Honest caveats surfaced on the page: address != entity (one wallet spreads across many
 * addresses), and MWEB outputs are shielded (no address, hidden value) so they never enter the
 * transparent set that these metrics are computed over.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Open db/activity.sqlite. With $create the file + result schema are created if missing; the
 * read path passes false and returns null until the walker has built the DB. The walker's own
 * working UTXO table (`u`) is created by the tool, not here - the site never touches it.
 */
function lx_activity_pdo(bool $create = false): ?PDO
{
    static $pdo = null;            // memoize only a real handle; a null return re-attempts next call
    if ($pdo instanceof PDO) {     // so a worker started before the DB was created self-heals once it exists
        return $pdo;
    }
    $cache = lx_config()['cache_db'] ?? null;
    $path = $cache ? dirname($cache) . '/activity.sqlite' : null;
    if (!$path || (!$create && !is_file($path))) {
        return null;
    }
    try {
        if ($create && !is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        $db = new PDO('sqlite:' . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout = 5000');
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('CREATE TABLE IF NOT EXISTS addr_activity ('
            . 'net TEXT NOT NULL, day INTEGER NOT NULL, '           // day = unix midnight (UTC)
            . 'active INTEGER NOT NULL DEFAULT 0, '                  // distinct addresses sending or receiving
            . 'cdd REAL NOT NULL DEFAULT 0, '                       // coin-days-destroyed (LTC-days)
            . 'txs INTEGER NOT NULL DEFAULT 0, '                    // transactions in the day
            . 'new_addr INTEGER NOT NULL DEFAULT 0, '               // addresses appearing for the first time ever
            . 'med_val INTEGER NOT NULL DEFAULT 0, '                // median non-coinbase tx output value (sat)
            . 'p10_val INTEGER NOT NULL DEFAULT 0, '                // 10th percentile tx value (sat)
            . 'p90_val INTEGER NOT NULL DEFAULT 0, '                // 90th percentile tx value (sat)
            . 'PRIMARY KEY (net, day))');
        // Idempotent migrations so a DB from before these columns upgrades in place.
        foreach (['new_addr', 'med_val', 'p10_val', 'p90_val'] as $col) {
            try { $db->exec("ALTER TABLE addr_activity ADD COLUMN $col INTEGER NOT NULL DEFAULT 0"); } catch (Throwable $e) { /* exists */ }
        }
        $db->exec('CREATE TABLE IF NOT EXISTS activity_meta ('
            . 'net TEXT PRIMARY KEY, updated INTEGER NOT NULL DEFAULT 0, '
            . 'height INTEGER NOT NULL DEFAULT -1, '                 // last fully-processed block height
            . 'day_from INTEGER NOT NULL DEFAULT 0, day_to INTEGER NOT NULL DEFAULT 0, '
            . "realized_cap REAL NOT NULL DEFAULT 0, "               // realized cap (sum of value x price-at-creation)
            . "realized_price REAL NOT NULL DEFAULT 0, "            // realized cap / circulating supply
            . "realized_cur TEXT NOT NULL DEFAULT 'usd', realized_at INTEGER NOT NULL DEFAULT 0)");
        foreach (['realized_cap REAL NOT NULL DEFAULT 0', 'realized_price REAL NOT NULL DEFAULT 0',
                  "realized_cur TEXT NOT NULL DEFAULT 'usd'", 'realized_at INTEGER NOT NULL DEFAULT 0'] as $col) {
            try { $db->exec('ALTER TABLE activity_meta ADD COLUMN ' . $col); } catch (Throwable $e) { /* exists */ }
        }
        // HODL waves: transparent supply bucketed by coin age at snapshot time (from the walker's
        // UTXO map). One row-set per network, replaced each snapshot.
        $db->exec('CREATE TABLE IF NOT EXISTS utxo_age ('
            . 'net TEXT NOT NULL, band INTEGER NOT NULL, min_days INTEGER NOT NULL, '
            . "label TEXT NOT NULL DEFAULT '', sat INTEGER NOT NULL DEFAULT 0, "
            . 'coins INTEGER NOT NULL DEFAULT 0, as_of INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (net, band))');
        return $pdo = $db;
    } catch (Throwable $e) {
        return null;
    }
}

/** True once the walker has produced at least one day of activity for this network. */
function lx_activity_ready(array $net): bool
{
    $m = lx_activity_meta($net);
    return $m !== null && (int) $m['day_to'] > 0;
}

/** Progress/coverage row for a network (or null). */
function lx_activity_meta(array $net): ?array
{
    $db = lx_activity_pdo(false);
    if (!$db) {
        return null;
    }
    try {
        $st = $db->prepare('SELECT * FROM activity_meta WHERE net = ?');
        $st->execute([$net['slug']]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    return $r ?: null;
}

/** Period -> number of trailing days, mirroring the block-index period vocabulary. */
function lx_activity_period_days(string $period): int
{
    static $m = ['24h' => 2, '3d' => 3, '1w' => 7, '1m' => 30, '3m' => 90,
                 '6m' => 180, '1y' => 365, '2y' => 730, '3y' => 1095, 'all' => PHP_INT_MAX];
    return $m[$period] ?? PHP_INT_MAX;   // charts default to the full history
}

/**
 * Daily activity series for a :period, oldest-first, downsampled to <= $maxPoints buckets.
 * Each point averages the days in its bucket, so active/txs read as "typical day in the bucket"
 * and CDD as "average LTC-days destroyed per day" - both stay comparable as the window widens.
 * Returns [['day'=>unix, 'active'=>float, 'cdd'=>float, 'txs'=>float], ...] or [] if not built.
 */
function lx_activity_series(array $net, string $period = '', int $maxPoints = 240): array
{
    $db = lx_activity_pdo(false);
    if (!$db) {
        return [];
    }
    $maxPoints = max(24, min(1000, $maxPoints));
    try {
        $days = lx_activity_period_days($period);
        $floor = 0;
        if ($days !== PHP_INT_MAX) {
            $mx = (int) $db->query("SELECT COALESCE(MAX(day),0) FROM addr_activity WHERE net = " . $db->quote($net['slug']))->fetchColumn();
            $floor = $mx > 0 ? $mx - $days * 86400 : 0;
        }
        $st = $db->prepare('SELECT day, active, cdd, txs, new_addr, med_val, p10_val, p90_val FROM addr_activity WHERE net = ? AND day >= ? ORDER BY day ASC');
        $st->execute([$net['slug'], $floor]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $n = count($rows);
    if ($n === 0) {
        return [];
    }
    $bucket = max(1, (int) ceil($n / $maxPoints));
    $out = [];
    // A bucket averages its days: median/percentiles become "typical daily value in the bucket"
    // (a downsample approximation, like active/cdd) - fine for a trend chart.
    for ($i = 0; $i < $n; $i += $bucket) {
        $chunk = array_slice($rows, $i, $bucket);
        $c = count($chunk);
        $s = ['day' => 0, 'active' => 0.0, 'cdd' => 0.0, 'txs' => 0.0, 'new' => 0.0, 'med' => 0.0, 'p10' => 0.0, 'p90' => 0.0];
        foreach ($chunk as $r) {
            $s['day']    += (int) $r['day'];
            $s['active'] += (float) $r['active'];
            $s['cdd']    += (float) $r['cdd'];
            $s['txs']    += (float) $r['txs'];
            $s['new']    += (float) $r['new_addr'];
            $s['med']    += (float) $r['med_val'];
            $s['p10']    += (float) $r['p10_val'];
            $s['p90']    += (float) $r['p90_val'];
        }
        $out[] = [
            'day'     => (int) round($s['day'] / $c),
            'active'  => $s['active'] / $c,
            'cdd'     => $s['cdd'] / $c,
            'txs'     => $s['txs'] / $c,
            'newAddr' => $s['new'] / $c,
            'medVal'  => $s['med'] / $c,   // sat
            'p10Val'  => $s['p10'] / $c,
            'p90Val'  => $s['p90'] / $c,
        ];
    }
    return $out;
}

/**
 * HODL-wave / coin-age bands: transparent supply bucketed by age at the last snapshot. Returns
 * [['band'=>int,'min_days'=>int,'label'=>string,'sat'=>int,'coins'=>int], ...] band-ordered, plus
 * 'as_of' on each row, or [] if no snapshot has been built.
 */
function lx_utxo_age_bands(array $net): array
{
    $db = lx_activity_pdo(false);
    if (!$db) {
        return [];
    }
    try {
        $st = $db->prepare('SELECT band, min_days, label, sat, coins, as_of FROM utxo_age WHERE net = ? ORDER BY band ASC');
        $st->execute([$net['slug']]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** The canonical HODL-wave age-band definitions ([min_days, label], ascending). */
function lx_utxo_age_band_defs(): array
{
    return [
        [0, '< 1d'], [1, '1d-1w'], [7, '1w-1m'], [30, '1-3m'], [90, '3-6m'],
        [180, '6-12m'], [365, '1-2y'], [730, '2-3y'], [1095, '3-5y'], [1825, '5y+'],
    ];
}

/**
 * Realized cap / realized price snapshot (each coin valued at the price when it last moved), or
 * null if not yet computed. ['cap'=>float, 'price'=>float, 'cur'=>string, 'at'=>int-unix].
 */
function lx_realized(array $net): ?array
{
    $m = lx_activity_meta($net);
    if (!$m || (float) ($m['realized_cap'] ?? 0) <= 0) {
        return null;
    }
    return [
        'cap'   => (float) $m['realized_cap'],
        'price' => (float) $m['realized_price'],
        'cur'   => (string) ($m['realized_cur'] ?? 'usd'),
        'at'    => (int) ($m['realized_at'] ?? 0),
    ];
}

/** The most recent fully-processed day's row (for a headline stat), or null. */
function lx_activity_latest(array $net): ?array
{
    $db = lx_activity_pdo(false);
    if (!$db) {
        return null;
    }
    try {
        $st = $db->prepare('SELECT day, active, cdd, txs FROM addr_activity WHERE net = ? ORDER BY day DESC LIMIT 1');
        $st->execute([$net['slug']]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    return $r ?: null;
}
