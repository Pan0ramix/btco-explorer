<?php
/**
 * Full-chain block-index backfill.
 *
 * Walks the whole chain (genesis -> tip) and fills every missing row in block_index, so the
 * analytics and charts have COMPLETE history instead of just the rolling retention window.
 * Reuses the same per-block writer as the cron (lx_blockindex_put: getblockstats + pool +
 * fee-rate percentiles). Node-friendly and safe to run alongside everything else:
 *   - throttled  (pauses between batches so it never floods litecoind)
 *   - resumable  (skips blocks already indexed; re-run or Ctrl-C any time)
 *   - gap-filling (fills holes anywhere in the range, not just below the current minimum)
 *
 * Usage:
 *   php tools/backfill-index.php [slug] [--batch=200] [--sleep=250] [--from=0] [--to=TIP] [--dry-run]
 *     slug        network slug (default: first configured network, e.g. ltc-mainnet)
 *     --batch=N   blocks to index between throttle pauses            (default 200)
 *     --sleep=MS  milliseconds to pause between batches, eases node load (default 250)
 *     --from=H    lowest height to fill                              (default 0 = genesis)
 *     --to=H      highest height to fill                             (default = current tip)
 *     --dry-run   report how many blocks are missing, fetch nothing
 *
 * For the full history to PERSIST, set 'blockindex_retain' => 0 in config.php (0 = never
 * prune); otherwise the snapshot cron will prune everything older than the retention window.
 * A non-pruned node is required for deep history (getblockstats needs the block data).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}
require dirname(__DIR__) . '/lib/bootstrap.php';

$args = array_slice($argv, 1);
$hasFlag = function (string $name) use ($args): bool { return in_array('--' . $name, $args, true); };
$optVal  = function (string $name, $def) use ($args) {
    $pfx = '--' . $name . '=';
    foreach ($args as $a) { if (strpos($a, $pfx) === 0) { return substr($a, strlen($pfx)); } }
    return $def;
};

$slug = null;
foreach ($args as $a) { if (strpos($a, '--') !== 0) { $slug = $a; break; } }
$net = $slug ? lx_net($slug) : (array_values(lx_networks())[0] ?? null);
if (!$net) {
    fwrite(STDERR, 'unknown network' . ($slug ? " '$slug'" : '') . "\n");
    exit(1);
}

$batch   = max(1, (int) $optVal('batch', 200));
$sleepMs = max(0, (int) $optVal('sleep', 250));
$dry     = $hasFlag('dry-run');

$db = lx_blockindex_pdo(true);
if (!$db) {
    fwrite(STDERR, "cannot open the index database (is db/ writable by this user?)\n");
    exit(1);
}

$tip = lx_tip_height($net);
if ($tip <= 0) {
    fwrite(STDERR, "node tip unavailable (is litecoind reachable?)\n");
    exit(1);
}

$slugv = $net['slug'];
$from  = max(0, (int) $optVal('from', 0));
$to    = min($tip, (int) $optVal('to', $tip));
if ($to < $from) {
    fwrite(STDERR, "empty range ($from..$to)\n");
    exit(1);
}

// Scope report (also the resume picture: what's already there).
$countRange = $db->prepare('SELECT COUNT(*) FROM block_index WHERE net = ? AND height BETWEEN ? AND ?');
$countRange->execute([$slugv, $from, $to]);
$have    = (int) $countRange->fetchColumn();
$span    = $to - $from + 1;
$missing = max(0, $span - $have);
$retain  = lx_blockindex_retain();

fwrite(STDERR, "network : $slugv\n");
fwrite(STDERR, "range   : $from .. $to  ($span blocks)\n");
fwrite(STDERR, "indexed : $have    missing : $missing\n");
fwrite(STDERR, 'retain  : ' . ($retain > 0
    ? "$retain  (WARNING: the cron prunes below tip-$retain; set blockindex_retain=0 to keep full history)"
    : "0  (full chain, never prune)") . "\n");

if ($dry) {
    fwrite(STDERR, "dry-run: nothing fetched.\n");
    exit(0);
}
if ($missing === 0) {
    fwrite(STDERR, "nothing to do; range already fully indexed.\n");
    exit(0);
}

$t0 = microtime(true);
$done = 0; $fail = 0; $consecFail = 0;
$chunk = 5000;   // bound the "already present" lookup set per pass

// Newest-first so the most-viewed recent history fills first; skip blocks already present.
for ($hi = $to; $hi >= $from; $hi -= $chunk) {
    $lo = max($from, $hi - $chunk + 1);
    $present = [];
    $ps = $db->prepare('SELECT height FROM block_index WHERE net = ? AND height BETWEEN ? AND ?');
    $ps->execute([$slugv, $lo, $hi]);
    foreach ($ps->fetchAll(PDO::FETCH_COLUMN) as $h) { $present[(int) $h] = true; }

    for ($h = $hi; $h >= $lo; $h--) {
        if (isset($present[$h])) { continue; }

        $ok = false;
        for ($try = 0; $try < 3 && !$ok; $try++) {
            $ok = lx_blockindex_put($db, $net, $h);
            if (!$ok && $try < 2) { usleep(300000); }   // brief backoff on a transient RPC miss
        }

        if ($ok) {
            $done++; $consecFail = 0;
            if ($done % $batch === 0) {
                $rate = $done / max(0.001, microtime(true) - $t0);
                $eta  = $rate > 0 ? ($missing - $done) / $rate : 0.0;
                fwrite(STDERR, sprintf("\r  %d / %d   h=%d   %.1f blk/s   eta %s        ",
                    $done, $missing, $h, $rate, lx_bf_hms($eta)));
                if ($sleepMs > 0) { usleep($sleepMs * 1000); }
            }
        } else {
            $fail++; $consecFail++;
            fwrite(STDERR, "\n  ! height $h failed after retries (getblockstats miss); continuing\n");
            if ($consecFail >= 50) {
                fwrite(STDERR, "\naborting: 50 consecutive failures - is the node pruned below this height, or is litecoind down?\n");
                fwrite(STDERR, sprintf("indexed %d, failed %d before abort.\n", $done, $fail));
                exit(2);
            }
        }
    }
}

fwrite(STDERR, sprintf("\ndone: indexed %d block(s), %d failure(s) in %s.\n", $done, $fail, lx_bf_hms(microtime(true) - $t0)));

// Final coverage.
$mm = $db->prepare('SELECT MIN(height), MAX(height), COUNT(*) FROM block_index WHERE net = ?');
$mm->execute([$slugv]);
[$mn, $mx, $total] = $mm->fetch(PDO::FETCH_NUM);
fwrite(STDERR, sprintf("coverage: %d row(s), heights %s..%s (tip %d).\n",
    (int) $total, $mn ?? '-', $mx ?? '-', $tip));

/** Compact H/M/S for a duration in seconds. */
function lx_bf_hms(float $s): string
{
    $s = (int) round($s);
    $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); $x = $s % 60;
    return ($h ? "{$h}h" : '') . (($h || $m) ? "{$m}m" : '') . "{$x}s";
}
