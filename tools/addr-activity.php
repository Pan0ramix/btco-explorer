<?php
/**
 * Address-activity full-chain indexer (Tier E): active addresses/day, coin-days-destroyed,
 * new addresses/day, median/percentile tx value, and the HODL-wave coin-age distribution.
 *
 * These cannot come from block_index aggregates - they need per-transaction input resolution
 * (an input references its prevout only by txid:vout). So this walks the chain in height order
 * maintaining a UTXO map (`u`): each output is inserted (value, creation time, address) and its
 * address recorded in `seen_addr` (first appearance => new address); each spend looks the prevout
 * up to learn its value + age (-> coin-days-destroyed) and its address (-> counted active), then
 * removes it. Distinct sending/receiving addresses per UTC day give "active addresses"; the daily
 * non-coinbase output-value distribution gives median/p10/p90; and at the end of a run the live
 * UTXO map is bucketed by coin age into utxo_age (HODL waves). Results -> db/activity.sqlite.
 *
 * Robust + node-friendly, same posture as the block-index backfill and rich-list aggregator:
 *   - resumable at DAY granularity. Each day is one DB transaction covering all its UTXO
 *     mutations + its result row + the progress marker, committed only once a LATER day is seen
 *     (proof the day is complete). The trailing, still-open current day is rolled back, so a
 *     re-run recomputes it from scratch - never a double-applied spend, never a torn day.
 *   - a reorg margin (--conf, default 30): days are only finalized once they are that many blocks
 *     below the tip, so an already-committed day can't be orphaned by a shallow reorg.
 *   - days are bucketed by a MONOTONIC effective time (each block's stamp clamped to be >= the
 *     previous), since Litecoin timestamps aren't block-to-block monotonic; this keeps the day
 *     sequence non-decreasing so backward drift near midnight can't tear an already-written day.
 *   - fetches getblock verbosity 2, falling back to verbosity 1 + a batched getrawtransaction
 *     (some litecoind builds 500 on verbosity 2).
 *   - throttled (--sleep between batches) and interruptible (Ctrl-C rolls back the open day).
 *   - --selftest exercises the CDD + active-set math on a synthetic chain, no node required.
 *
 * Usage:
 *   php tools/addr-activity.php [slug] [--batch=200] [--sleep=200] [--conf=30] [--from=H] [--to=H] [--reset] [--dry-run]
 *   php tools/addr-activity.php --selftest
 *
 * Caveats (also shown on the page): address != entity; MWEB outputs are shielded (no address,
 * hidden value) so they never enter these transparent-set metrics.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}
require dirname(__DIR__) . '/lib/bootstrap.php';

$args    = array_slice($argv, 1);
$hasFlag = function (string $n) use ($args): bool { return in_array('--' . $n, $args, true); };
$optVal  = function (string $n, $d) use ($args) {
    $pfx = '--' . $n . '=';
    foreach ($args as $a) { if (strpos($a, $pfx) === 0) { return substr($a, strlen($pfx)); } }
    return $d;
};

/**
 * Core per-block aggregation - pure enough to unit-test. Applies one block's outputs (into the
 * UTXO map) and inputs (resolved against it) to the running day accumulators. $stmts holds the
 * prepared ins/sel/del statements against table `u`.
 */
function lx_aa_apply_block(array $blk, array $stmts, array &$daySet, float &$cdd, int &$txs, array &$dayVals, int &$dayNew): void
{
    $t = (int) $blk['time'];
    foreach ($blk['txs'] as $tx) {
        $txs++;
        $coinbase = false;
        foreach ($tx['vin'] as $vi) { if (!empty($vi['coinbase'])) { $coinbase = true; break; } }
        // Outputs -> create UTXOs; mark address active (received) and, on its FIRST-ever appearance,
        // count it as a new address. (An address can only first appear as an output - to spend it
        // must have received first - so first-seen is detected here, not on inputs.)
        $txOut = 0;
        foreach ($tx['vout'] as $vo) {
            $txOut += (int) $vo['sat'];
            if ($vo['addr'] === null || $vo['sat'] <= 0) { continue; }
            $stmts['ins']->execute([$tx['txid'] . ':' . $vo['n'], $vo['sat'], $t, $vo['addr']]);
            $daySet[$vo['addr']] = true;
            $stmts['seen']->execute([$vo['addr']]);
            if ($stmts['seen']->rowCount() > 0) { $dayNew++; }   // inserted => never seen before
        }
        // Non-coinbase tx output value feeds the daily value distribution (median/p10/p90). Coinbase
        // is excluded (it is issuance, one per block, and would skew the median).
        if (!$coinbase && $txOut > 0) { $dayVals[] = $txOut; }
        // Inputs -> resolve prevout for value+age (CDD) and address (active/sent), then delete.
        foreach ($tx['vin'] as $vi) {
            if (!empty($vi['coinbase'])) { continue; }
            $op = $vi['txid'] . ':' . $vi['vout'];
            $stmts['sel']->execute([$op]);
            $row = $stmts['sel']->fetch(PDO::FETCH_ASSOC);
            if (!$row) { continue; }   // prevout we never tracked (MWEB / non-standard / pre-range)
            $age = max(0, $t - (int) $row['t']);
            $cdd += ((float) $row['sat'] / 1e8) * ($age / 86400.0);
            if ($row['addr'] !== '' && $row['addr'] !== null) { $daySet[(string) $row['addr']] = true; }
            $stmts['del']->execute([$op]);
        }
    }
}

// --------------------------------------------------------------------------- selftest
if ($hasFlag('selftest')) {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('CREATE TABLE u (op TEXT PRIMARY KEY, sat INTEGER, t INTEGER, addr TEXT) WITHOUT ROWID');
    $db->exec('CREATE TABLE seen_addr (addr TEXT PRIMARY KEY) WITHOUT ROWID');
    $stmts = [
        'ins'  => $db->prepare('INSERT OR REPLACE INTO u (op, sat, t, addr) VALUES (?, ?, ?, ?)'),
        'sel'  => $db->prepare('SELECT sat, t, addr FROM u WHERE op = ?'),
        'del'  => $db->prepare('DELETE FROM u WHERE op = ?'),
        'seen' => $db->prepare('INSERT OR IGNORE INTO seen_addr (addr) VALUES (?)'),
    ];
    $fail = 0;
    $chk = function (string $label, bool $ok) use (&$fail) {
        fwrite(STDERR, ($ok ? '  ok   ' : '  FAIL ') . $label . "\n");
        if (!$ok) { $fail++; }
    };

    // Day 1 (t=0): coinbase mints 10 LTC to X at aa:0.
    $set = []; $cdd = 0.0; $txs = 0; $vals = []; $new = 0;
    lx_aa_apply_block([
        'time' => 0,
        'txs'  => [[
            'txid' => 'aa',
            'vin'  => [['coinbase' => true]],
            'vout' => [['n' => 0, 'sat' => 1000000000, 'addr' => 'X']],
        ]],
    ], $stmts, $set, $cdd, $txs, $vals, $new);
    $chk('day1: 1 active (X received)', count($set) === 1 && isset($set['X']));
    $chk('day1: cdd 0 (nothing spent)', abs($cdd) < 1e-9);
    $chk('day1: 1 tx', $txs === 1);
    $chk('day1: 1 new address (X)', $new === 1);
    $chk('day1: coinbase excluded from value dist', $vals === []);

    // Day 3 (t=2 days later): X spends aa:0 (10 LTC, aged 2 days) -> Y gets 9, fee 1.
    $set = []; $cdd = 0.0; $txs = 0; $vals = []; $new = 0;
    lx_aa_apply_block([
        'time' => 2 * 86400,
        'txs'  => [[
            'txid' => 'bb',
            'vin'  => [['coinbase' => false, 'txid' => 'aa', 'vout' => 0]],
            'vout' => [['n' => 0, 'sat' => 900000000, 'addr' => 'Y']],
        ]],
    ], $stmts, $set, $cdd, $txs, $vals, $new);
    $chk('day3: 2 active (X sent, Y received)', count($set) === 2 && isset($set['X']) && isset($set['Y']));
    $chk('day3: cdd = 10 LTC * 2 days = 20', abs($cdd - 20.0) < 1e-6);
    $chk('day3: aa:0 consumed', $db->query('SELECT COUNT(*) FROM u')->fetchColumn() == 1);   // only bb:0 remains
    $chk('day3: 1 new address (Y; X already seen)', $new === 1);
    $chk('day3: value dist holds the non-coinbase tx (9 LTC)', $vals === [900000000]);

    // Spending an untracked prevout must not crash or move CDD.
    $set = []; $cdd = 0.0; $txs = 0; $vals = []; $new = 0;
    lx_aa_apply_block([
        'time' => 3 * 86400,
        'txs'  => [[
            'txid' => 'cc',
            'vin'  => [['coinbase' => false, 'txid' => 'zz', 'vout' => 7]],
            'vout' => [['n' => 0, 'sat' => 0, 'addr' => null]],   // OP_RETURN-like: skipped
        ]],
    ], $stmts, $set, $cdd, $txs, $vals, $new);
    $chk('unknown prevout: no crash, cdd 0', abs($cdd) < 1e-9);
    $chk('unaddressed/zero output: not tracked', count($set) === 0);
    $chk('zero-value tx not in value dist', $vals === []);

    // Median/percentile pick over a known distribution (mirrors the $flush computation).
    $vals = [100, 200, 300, 400, 500]; sort($vals); $vn = count($vals);
    $pick = function (float $q) use ($vals, $vn) { return $vn ? (int) $vals[min($vn - 1, (int) floor($q * $vn))] : 0; };
    $chk('median pick = 300', $pick(0.5) === 300);
    $chk('p10 pick = 100', $pick(0.10) === 100);
    $chk('p90 pick = 500', $pick(0.90) === 500);

    fwrite(STDERR, $fail ? "\nSELFTEST FAILED ($fail)\n" : "\nselftest passed\n");
    exit($fail ? 1 : 0);
}

// --------------------------------------------------------------------------- setup
$slug = null;
foreach ($args as $a) { if (strpos($a, '--') !== 0) { $slug = $a; break; } }
$net = $slug ? lx_net($slug) : (array_values(lx_networks())[0] ?? null);
if (!$net) {
    fwrite(STDERR, 'unknown network' . ($slug ? " '$slug'" : '') . "\n");
    exit(1);
}

$batch   = max(1, (int) $optVal('batch', 200));
$sleepMs = max(0, (int) $optVal('sleep', 200));
$dry     = $hasFlag('dry-run');

$db = lx_activity_pdo(true);
if (!$db) {
    fwrite(STDERR, "cannot open db/activity.sqlite (is db/ writable by this user?)\n");
    exit(1);
}
// Working UTXO set. WITHOUT ROWID keeps the op-keyed table compact and PK lookups fast. Speed
// pragmas for this long offline session; a crash just resumes from the last committed day.
$db->exec('CREATE TABLE IF NOT EXISTS u (op TEXT PRIMARY KEY, sat INTEGER, t INTEGER, addr TEXT) WITHOUT ROWID');
// Every address ever seen on-chain, so an address's FIRST appearance counts toward new-addresses/day.
$db->exec('CREATE TABLE IF NOT EXISTS seen_addr (addr TEXT PRIMARY KEY) WITHOUT ROWID');
$db->exec('PRAGMA synchronous = OFF');
$db->exec('PRAGMA temp_store = MEMORY');
$db->exec('PRAGMA cache_size = -262144');   // ~256 MB page cache

$slugv = $net['slug'];

if ($hasFlag('reset')) {
    $db->exec('DELETE FROM addr_activity WHERE net = ' . $db->quote($slugv));
    $db->exec('DELETE FROM activity_meta WHERE net = ' . $db->quote($slugv));
    $db->exec('DELETE FROM u');
    $db->exec('DELETE FROM seen_addr');
    fwrite(STDERR, "reset: cleared activity + UTXO map + seen-address set for $slugv\n");
}

$tip = lx_tip_height($net);
if ($tip <= 0) {
    fwrite(STDERR, "node tip unavailable (is litecoind reachable?)\n");
    exit(1);
}

$meta = lx_activity_meta($net);
$done  = $meta ? (int) $meta['height'] : -1;
$conf  = max(0, (int) $optVal('conf', 30));   // reorg margin: never finalize a day within $conf of tip
$fromArg = (int) $optVal('from', 0);
$from  = max($done + 1, $fromArg);
$to    = min($tip - $conf, (int) $optVal('to', $tip));

// A --from that jumps past the contiguous resume point would leave the UTXO map with a permanent
// hole (outputs in the skipped range are never inserted, so later spends of them resolve to
// nothing - dropping their coin-days and sender-active). The map is only valid for a genesis-
// contiguous walk. This holds for --reset too: after a reset $done is -1, so any --from>0 is a
// discontinuity from genesis and must be refused (a reset ALWAYS rebuilds from block 0).
if ($fromArg > $done + 1) {
    fwrite(STDERR, "refusing: --from=$fromArg skips past the contiguous resume height " . ($done + 1)
        . ", which would leave the UTXO map with a hole and silently undercount CDD/active.\n"
        . ($hasFlag('reset')
            ? "A --reset always rebuilds from genesis; drop --from (or use --from=0).\n"
            : "Re-run without --from to resume, or with --reset to rebuild from genesis.\n"));
    exit(1);
}

fwrite(STDERR, "network : $slugv\n");
fwrite(STDERR, 'resume  : ' . ($done >= 0 ? "last complete height $done" : 'fresh (genesis)') . "\n");
fwrite(STDERR, "range   : $from .. $to  (tip $tip, reorg margin $conf)\n");
if ($dry) { fwrite(STDERR, "dry-run: nothing fetched.\n"); exit(0); }
if ($from > $to) { fwrite(STDERR, "nothing to do; up to date within the reorg margin.\n"); exit(0); }

$stmts = [
    'ins'  => $db->prepare('INSERT OR REPLACE INTO u (op, sat, t, addr) VALUES (?, ?, ?, ?)'),
    'sel'  => $db->prepare('SELECT sat, t, addr FROM u WHERE op = ?'),
    'del'  => $db->prepare('DELETE FROM u WHERE op = ?'),
    'seen' => $db->prepare('INSERT OR IGNORE INTO seen_addr (addr) VALUES (?)'),
];
$putRow  = $db->prepare('INSERT OR REPLACE INTO addr_activity (net, day, active, cdd, txs, new_addr, med_val, p10_val, p90_val) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$putMeta = $db->prepare('INSERT INTO activity_meta (net, updated, height, day_from, day_to) VALUES (:n,:u,:h,:df,:dt) '
    . 'ON CONFLICT(net) DO UPDATE SET updated=:u, height=:h, day_from=MIN(CASE WHEN day_from=0 THEN :df ELSE day_from END,:df), day_to=:dt');

// --------------------------------------------------------------------------- walk
$curDay  = null;          // unix-midnight of the day currently accumulating
$daySet  = [];            // distinct active addresses in $curDay
$dayCdd  = 0.0; $dayTxs = 0;
$dayVals = [];            // non-coinbase tx output values in $curDay (for median/percentiles), sat
$dayNew  = 0;             // addresses first seen ever in $curDay
$lastH   = $done;         // last height folded into $curDay
$open    = false;         // is a day transaction in flight?
$prevEff = 0;             // monotonic effective time (see day bucketing below)
$t0 = microtime(true); $processed = 0; $daysWritten = 0; $consecFail = 0;

$flush = function (int $day, int $lastHeight) use ($db, $putRow, $putMeta, $slugv, &$daySet, &$dayCdd, &$dayTxs, &$dayVals, &$dayNew, &$daysWritten) {
    sort($dayVals);
    $vn = count($dayVals);
    $pick = function (float $q) use ($dayVals, $vn) { return $vn ? (int) $dayVals[min($vn - 1, (int) floor($q * $vn))] : 0; };
    $putRow->execute([$slugv, $day, count($daySet), $dayCdd, $dayTxs, $dayNew, $pick(0.5), $pick(0.10), $pick(0.90)]);
    $putMeta->execute([':n' => $slugv, ':u' => $day, ':h' => $lastHeight, ':df' => $day, ':dt' => $day]);
    $db->commit();
    $daysWritten++;
    $daySet = []; $dayCdd = 0.0; $dayTxs = 0; $dayVals = []; $dayNew = 0;
};

for ($h = $from; $h <= $to; $h++) {
    $blk = lx_aa_fetch_block($net, $h);
    if ($blk === null) {
        $consecFail++;
        fwrite(STDERR, "\n  ! height $h fetch failed; retrying\n");
        if ($consecFail >= 50) {
            fwrite(STDERR, "\naborting: 50 consecutive fetch failures - node down or pruned below $h?\n");
            if ($open) { $db->rollBack(); }
            exit(2);
        }
        usleep(500000); $h--; continue;   // retry the same height
    }
    $consecFail = 0;
    // Bucket by a MONOTONIC effective time, not the raw block time. Litecoin timestamps are only
    // bounded by median-time-past, so a block just after midnight can be followed by a legal one
    // just before it; bucketing on raw time would let that drift-back block land in a day we have
    // already committed (a permanently torn day). Clamping each stamp to be >= the previous one
    // makes the day sequence non-decreasing, so a committed day is never reopened - the boundary
    // crosser (whose own stamp is past midnight) still opens the new day correctly on resume.
    $eff = max((int) $blk['time'], $prevEff); $prevEff = $eff;
    $day = intdiv($eff, 86400) * 86400;

    if ($curDay === null) {
        $curDay = $day; $db->beginTransaction(); $open = true;
    } elseif ($day > $curDay) {
        // A later effective-day proves $curDay is complete: commit it, open the next.
        $flush($curDay, $lastH);
        $curDay = $day; $db->beginTransaction(); $open = true;
    }
    // $day can never be < $curDay (monotonic), so no committed day is ever reopened.

    lx_aa_apply_block($blk, $stmts, $daySet, $dayCdd, $dayTxs, $dayVals, $dayNew);
    $lastH = $h; $processed++;

    if ($processed % $batch === 0) {
        $rate = $processed / max(0.001, microtime(true) - $t0);
        $eta  = $rate > 0 ? ($to - $h) / $rate : 0.0;
        // NB: never COUNT(*) the UTXO table here - it grows into the tens of millions and a full
        // scan every batch would dominate runtime. The map size is reported once at the end.
        fwrite(STDERR, sprintf("\r  h=%d/%d  %.1f blk/s  days=%d  eta %s      ",
            $h, $to, $rate, $daysWritten, lx_aa_hms($eta)));
        if ($sleepMs > 0) { usleep($sleepMs * 1000); }
    }
}

// The trailing day is the still-open current UTC day: discard it so the next run recomputes it
// intact (its UTXO mutations roll back too, leaving the map exactly at the last committed day).
if ($open) {
    $db->rollBack();
    fwrite(STDERR, "\n  (held back the current incomplete day; it will be written once it closes)\n");
}

fwrite(STDERR, sprintf("\ndone: processed %d block(s), wrote %d complete day(s) in %s.\n",
    $processed, $daysWritten, lx_aa_hms(microtime(true) - $t0)));
$m = lx_activity_meta($net);
if ($m) {
    $uc = (int) $db->query('SELECT COUNT(*) FROM u')->fetchColumn();   // one full scan, at the end only
    fwrite(STDERR, sprintf("coverage: days %s .. %s, last height %d (tip %d), utxo map %s entries.\n",
        $m['day_from'] ? gmdate('Y-m-d', (int) $m['day_from']) : '-',
        $m['day_to'] ? gmdate('Y-m-d', (int) $m['day_to']) : '-', (int) $m['height'], $tip, lx_aa_kmb((float) $uc)));
}

// HODL-wave snapshot: recompute the coin-age band distribution from the UTXO map (one scan, only
// after a run that actually committed days, so a no-op catch-up doesn't rescan the whole map).
if ($daysWritten > 0) {
    $m2   = lx_activity_meta($net);
    $asOf = $m2 && (int) $m2['day_to'] > 0 ? (int) $m2['day_to'] + 86400 : (int) $prevEff;
    lx_aa_write_age_snapshot($db, $slugv, $asOf);
    fwrite(STDERR, 'age snapshot: HODL-wave coin-age bands recomputed as of ' . gmdate('Y-m-d', $asOf) . ".\n");
    lx_aa_write_realized($db, $net, $slugv, $asOf);
}

// --------------------------------------------------------------------------- helpers
/**
 * Fetch one block's transactions in a compact shape:
 *   ['time'=>int, 'txs'=>[ ['txid'=>, 'vin'=>[['coinbase'=>bool]|['txid'=>,'vout'=>]], 'vout'=>[['n'=>,'sat'=>,'addr'=>]] ] ]]
 * Tries getblock verbosity 2; on failure falls back to verbosity 1 + a batched getrawtransaction
 * (some litecoind builds 500 on verbosity 2). Returns null on ANY failure - including a transient
 * transport error (node restart / connection blip), which lx_rpc_soft re-throws rather than
 * softening. Returning null routes every failure through the caller's 50-retry/backoff loop, so a
 * momentary node hiccup never aborts a multi-hour walk.
 */
function lx_aa_fetch_block(array $net, int $height): ?array
{
    try {
        $hash = lx_rpc_soft($net, 'getblockhash', [$height]);
        if (!$hash) { return null; }

        $b = lx_rpc_soft($net, 'getblock', [$hash, 2]);
        // Require the first tx to be an OBJECT with vout - not a txid string. (A node that silently
        // ignores verbosity 2 and returns the v1 txid list would otherwise slip through: PHP casts
        // isset($string['vout']) to isset($string[0]), true for any non-empty txid, and the block
        // would be misread as empty.) Genuine v2 always has an object coinbase at tx[0].
        if (is_array($b) && isset($b['tx'][0]) && is_array($b['tx'][0]) && isset($b['tx'][0]['vout'])) {
            return lx_aa_shape((int) ($b['time'] ?? 0), $b['tx']);
        }

        // Fallback: verbosity 1 gives the txid list; resolve full txs in one batched round-trip.
        $b1 = lx_rpc_soft($net, 'getblock', [$hash, 1]);
        if (!is_array($b1) || empty($b1['tx'])) { return null; }
        $time = (int) ($b1['time'] ?? 0);
        $calls = [];
        foreach ($b1['tx'] as $txid) { $calls[] = ['getrawtransaction', [$txid, true]]; }
        $txs = [];
        foreach (array_chunk($calls, 200, true) as $chunk) {
            $res = lx_rpc_batch($net, array_values($chunk));
            foreach ($res as $tx) {
                if (!is_array($tx)) { return null; }   // a missing tx would silently skew the day
                $txs[] = $tx;
            }
        }
        return lx_aa_shape($time, $txs);
    } catch (Throwable $e) {
        return null;   // transport/bad-response: let the caller retry rather than crash the walk
    }
}

/** Normalise Core tx objects (verbosity-2 or getrawtransaction) into the compact walk shape. */
function lx_aa_shape(int $time, array $rawTxs): array
{
    $txs = [];
    foreach ($rawTxs as $tx) {
        $vin = [];
        foreach (($tx['vin'] ?? []) as $vi) {
            if (isset($vi['coinbase'])) { $vin[] = ['coinbase' => true]; }
            elseif (isset($vi['txid'])) { $vin[] = ['coinbase' => false, 'txid' => (string) $vi['txid'], 'vout' => (int) ($vi['vout'] ?? 0)]; }
        }
        $vout = [];
        foreach (($tx['vout'] ?? []) as $vo) {
            $spk  = $vo['scriptPubKey'] ?? [];
            $addr = $spk['address'] ?? ($spk['addresses'][0] ?? null);
            $vout[] = ['n' => (int) ($vo['n'] ?? count($vout)), 'sat' => (int) round(((float) ($vo['value'] ?? 0)) * 1e8), 'addr' => $addr !== null ? (string) $addr : null];
        }
        $txs[] = ['txid' => (string) ($tx['txid'] ?? ''), 'vin' => $vin, 'vout' => $vout];
    }
    return ['time' => $time, 'txs' => $txs];
}

function lx_aa_hms(float $s): string
{
    $s = (int) round($s);
    $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); $x = $s % 60;
    return ($h ? "{$h}h" : '') . (($h || $m) ? "{$m}m" : '') . "{$x}s";
}
function lx_aa_kmb(float $n): string
{
    if ($n >= 1e9) { return round($n / 1e9, 1) . 'B'; }
    if ($n >= 1e6) { return round($n / 1e6, 1) . 'M'; }
    if ($n >= 1e3) { return round($n / 1e3, 1) . 'k'; }
    return (string) (int) $n;
}

/**
 * Compute + store the HODL-wave coin-age band snapshot from the current UTXO map `u`. One GROUP BY
 * scan assigns each UTXO to the highest band whose min-age it exceeds (age = $asOf - creation time),
 * then the 10 bands are written to utxo_age (replacing the prior snapshot). Uses only creation times
 * the walker already stores - no price needed - so it is exact.
 */
function lx_aa_write_age_snapshot(PDO $db, string $net, int $asOf): void
{
    $defs = lx_utxo_age_band_defs();   // [[min_days, label], ...] ascending
    $case = 'CASE';
    for ($i = count($defs) - 1; $i >= 0; $i--) {
        $case .= ' WHEN (' . $asOf . ' - t) >= ' . ((int) $defs[$i][0] * 86400) . ' THEN ' . $i;
    }
    $case .= ' ELSE 0 END';   // negative age (minor stamp drift) -> youngest band
    $agg = [];
    foreach ($db->query('SELECT ' . $case . ' AS band, SUM(sat) AS s, COUNT(*) AS c FROM u GROUP BY band') as $r) {
        $agg[(int) $r['band']] = ['sat' => (int) $r['s'], 'coins' => (int) $r['c']];
    }
    $db->beginTransaction();
    $db->prepare('DELETE FROM utxo_age WHERE net = ?')->execute([$net]);
    $ins = $db->prepare('INSERT INTO utxo_age (net, band, min_days, label, sat, coins, as_of) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($defs as $i => $d) {
        $ins->execute([$net, $i, (int) $d[0], (string) $d[1], $agg[$i]['sat'] ?? 0, $agg[$i]['coins'] ?? 0, $asOf]);
    }
    $db->commit();
}

/**
 * Realized cap: value every UTXO at the price on the day it was created (last moved), summed. With
 * realized price = realized cap / circulating supply it powers MVRV. Needs a DEEP daily price feed
 * spanning most of LTC's life (a CoinGecko tier that serves multi-year daily history); it only
 * computes when the feed reaches back to ~the oldest coin (coverage check below) and otherwise
 * leaves the prior value untouched rather than publishing a shallow-feed error. Coins predating the
 * feed are valued at its earliest price. So on a 365-day-capped tier the metric simply stays hidden.
 */
function lx_aa_write_realized(PDO $db, array $net, string $slugv, int $asOf): void
{
    $cur = 'usd';
    try {
        $pts = function_exists('lx_price_market_chart') ? lx_price_market_chart($net, $cur, 5000) : [];
    } catch (Throwable $e) {
        $pts = [];
    }
    if (count($pts) < 2) {
        fwrite(STDERR, "realized cap: deep price history unavailable; left unchanged.\n");
        return;
    }
    $pd = []; $pp = [];
    foreach ($pts as $q) { $pd[] = (int) $q['t']; $pp[] = (float) $q['p']; }
    array_multisort($pd, SORT_ASC, $pp);
    $np = count($pd);
    // Coverage-span check: realized cap is only honest if the price feed reaches back to (about)
    // the oldest coin. A shallow feed (e.g. a 365-day-capped tier) would value every older coin at
    // the earliest price - a large-factor error - so skip and keep the prior value rather than
    // publish a wrong one. Deep history needs a CoinGecko tier that serves multi-year daily data.
    $oldest = (int) $db->query('SELECT COALESCE(MIN(t), 0) FROM u')->fetchColumn();
    if ($oldest > 0 && $pd[0] > $oldest + 90 * 86400) {
        fwrite(STDERR, 'realized cap: price history too shallow (from ' . gmdate('Y-m-d', $pd[0])
            . ', oldest coin ' . gmdate('Y-m-d', $oldest) . "); left unchanged.\n");
        return;
    }
    $nearest = function (int $d) use ($pd, $pp, $np) {   // price on the nearest available day
        if ($d <= $pd[0]) { return $pp[0]; }
        if ($d >= $pd[$np - 1]) { return $pp[$np - 1]; }
        $lo = 0; $hi = $np - 1;
        while ($lo < $hi) { $mid = intdiv($lo + $hi, 2); if ($pd[$mid] < $d) { $lo = $mid + 1; } else { $hi = $mid; } }
        $a = $lo - 1;
        return (abs($pd[$lo] - $d) < abs($pd[$a] - $d)) ? $pp[$lo] : $pp[$a];
    };
    $realized = 0.0; $totalSat = 0;
    foreach ($db->query('SELECT (t / 86400) * 86400 AS d, SUM(sat) AS s FROM u GROUP BY d') as $row) {
        $s = (int) $row['s']; $totalSat += $s;
        $realized += ($s / 1e8) * $nearest((int) $row['d']);
    }
    $rp = $totalSat > 0 ? $realized / ($totalSat / 1e8) : 0.0;
    $db->prepare('UPDATE activity_meta SET realized_cap = ?, realized_price = ?, realized_cur = ?, realized_at = ? WHERE net = ?')
        ->execute([$realized, $rp, $cur, $asOf, $slugv]);
    fwrite(STDERR, sprintf("realized cap: $%s (realized price $%.4f) as of %s.\n", number_format($realized), $rp, gmdate('Y-m-d', $asOf)));
}
