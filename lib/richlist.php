<?php
/**
 * Rich-list data layer: its own SQLite DB (db/richlist.sqlite), read helpers for the
 * /richlist page + API, and the wealth-band definitions. The heavy UTXO-set aggregation
 * that POPULATES these tables is an offline cron (tools/richlist.php) reading a
 * `dumptxoutset` dump - never a per-request operation. The site only ever reads warm rows.
 *
 * Honest caveats surfaced on the page: balances are per-ADDRESS, not per-entity (exchanges
 * split across many addresses, so ranks/Gini overstate concentration), and MWEB outputs are
 * shielded (no address, hidden value) so they are excluded from this transparent-set view.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

function lx_richlist_pdo(bool $create = false): ?PDO
{
    static $pdo = null;            // memoize only a real handle; a null return re-attempts next call
    if ($pdo instanceof PDO) {     // so a worker started before the offline tool built the DB self-heals
        return $pdo;
    }
    $cache = lx_config()['cache_db'] ?? null;
    $path = $cache ? dirname($cache) . '/richlist.sqlite' : null;
    if (!$path || (!$create && !is_file($path))) {
        return null;
    }
    try {
        if ($create && !is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        $db = new PDO('sqlite:' . $path);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA busy_timeout = 3000');
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('CREATE TABLE IF NOT EXISTS rich_list ('
            . 'net TEXT NOT NULL, rank INTEGER NOT NULL, address TEXT NOT NULL DEFAULT \'\', '
            . 'kind TEXT NOT NULL DEFAULT \'\', sat INTEGER NOT NULL DEFAULT 0, '
            . 'PRIMARY KEY (net, rank))');
        $db->exec('CREATE TABLE IF NOT EXISTS wealth_summary ('
            . 'net TEXT PRIMARY KEY, updated INTEGER NOT NULL DEFAULT 0, tip_height INTEGER NOT NULL DEFAULT 0, '
            . 'total_sat INTEGER NOT NULL DEFAULT 0, utxo_count INTEGER NOT NULL DEFAULT 0, addr_count INTEGER NOT NULL DEFAULT 0, '
            . 'top1_sat INTEGER NOT NULL DEFAULT 0, top10_sat INTEGER NOT NULL DEFAULT 0, top100_sat INTEGER NOT NULL DEFAULT 0, '
            . 'top1000_sat INTEGER NOT NULL DEFAULT 0, gini REAL NOT NULL DEFAULT 0, bands TEXT NOT NULL DEFAULT \'[]\')');
        return $pdo = $db;
    } catch (Throwable $e) {
        return null;
    }
}

/** True when a rich-list snapshot has been built for this network. */
function lx_richlist_ready(array $net): bool
{
    return lx_wealth_summary($net) !== null;
}

/** The wealth summary row for a network (or null), with bands decoded to an array. */
function lx_wealth_summary(array $net): ?array
{
    $db = lx_richlist_pdo(false);
    if (!$db) {
        return null;
    }
    try {
        $st = $db->prepare('SELECT * FROM wealth_summary WHERE net = ?');
        $st->execute([$net['slug']]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }
    if (!$r) {
        return null;
    }
    $r['bands'] = json_decode($r['bands'] ?? '[]', true) ?: [];
    return $r;
}

/** A page of the rich list, rank-ordered (rank 1 = largest). Returns [] if not built yet. */
function lx_richlist_top(array $net, int $limit = 100, int $offset = 0): array
{
    $db = lx_richlist_pdo(false);
    if (!$db) {
        return [];
    }
    $limit = max(1, min(1000, $limit));
    $offset = max(0, $offset);
    try {
        $st = $db->prepare('SELECT rank, address, kind, sat FROM rich_list WHERE net = ? ORDER BY rank ASC LIMIT ? OFFSET ?');
        $st->bindValue(1, $net['slug']);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->bindValue(3, $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Balance-band definitions for the wealth-distribution histogram: ascending upper bounds in
 * satoshis (last is open-ended) with display labels. The aggregator counts addresses + sums
 * value per band; the page renders it as a bar chart.
 */
function lx_wealth_bands(): array
{
    return [
        ['max' => 100000,               'label' => '< 0.001'],       // < 0.001 LTC
        ['max' => 1000000,              'label' => '0.001 - 0.01'],
        ['max' => 10000000,             'label' => '0.01 - 0.1'],
        ['max' => 100000000,            'label' => '0.1 - 1'],
        ['max' => 1000000000,           'label' => '1 - 10'],
        ['max' => 10000000000,          'label' => '10 - 100'],
        ['max' => 100000000000,         'label' => '100 - 1k'],
        ['max' => 1000000000000,        'label' => '1k - 10k'],
        ['max' => 10000000000000,       'label' => '10k - 100k'],
        ['max' => PHP_INT_MAX,          'label' => '100k+'],
    ];
}
