<?php
/**
 * Rich-list builder. Parses a Litecoin Core `dumptxoutset` file, aggregates balances by
 * scriptPubKey, and writes the top-N holders + wealth-distribution stats (rich_list +
 * wealth_summary in db/richlist.sqlite). OFFLINE cron only - never per-request. The site
 * reads the warm rows via lib/richlist.php.
 *
 * Produce a dump on the node (locks cs_main for minutes; needs a NON-pruned node):
 *   litecoin-cli dumptxoutset /var/lib/litecoind/utxo.dat
 * Then:
 *   php tools/richlist.php /var/lib/litecoind/utxo.dat [slug] [--top=10000] [--verify=25]
 *
 *   --top=N       how many top addresses to persist (default 10000)
 *   --verify=N    after building, cross-check N random top addresses against electrum and
 *                 REFUSE to publish if they disagree (guards against a parser/format drift)
 *   --selftest    parse a synthetic in-memory dump and assert the codecs are correct, then exit
 *
 * Format: Litecoin Core 0.21.5 flat dump - 44-byte header (base_blockhash[32] + coins_count
 * u64LE + nchaintx u32LE), then coins_count flat records: outpoint(txid[32]+vout u32LE) +
 * Coin(VARINT code + VARINT compressed-amount + compressed script). VARINT is the Bitcoin
 * base-128 (MSB-first, +1 carry), NOT CompactSize/LEB128. LTC code has a pegout bit at 1<<31.
 * MWEB confidential coins are NOT in the dump; the pegged pool shows as one witness-v8 HogAddr
 * (script 58 20 <32>) which we exclude from holder ranks.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}
require dirname(__DIR__) . '/lib/bootstrap.php';

// ---- Bitcoin base-128 VARINT + amount + script codecs -------------------------

function lx_rl_read_varint(callable $rd): int
{
    $n = 0;
    while (true) {
        $b = $rd(1);
        if ($b === '') { throw new RuntimeException('unexpected EOF in varint'); }
        $c = ord($b);
        $n = ($n << 7) | ($c & 0x7F);
        if ($c & 0x80) { $n += 1; } else { return $n; }
    }
}

function lx_rl_decompress_amount(int $x): int
{
    if ($x === 0) { return 0; }
    $x -= 1;
    $e = $x % 10; $x = intdiv($x, 10);
    if ($e < 9) { $d = ($x % 9) + 1; $x = intdiv($x, 9); $n = $x * 10 + $d; }
    else { $n = $x + 1; }
    while ($e > 0) { $n *= 10; $e -= 1; }
    return $n;
}

// Encoders (only used by --selftest to synthesize a dump).
function lx_rl_write_varint(int $n): string
{
    $tmp = []; $len = 0;
    while (true) {
        $tmp[$len] = ($n & 0x7F) | ($len ? 0x80 : 0x00);
        if ($n <= 0x7F) { break; }
        $n = intdiv($n, 128) - 1; $len++;
    }
    $out = '';
    for ($i = $len; $i >= 0; $i--) { $out .= chr($tmp[$i]); }
    return $out;
}
function lx_rl_compress_amount(int $n): int
{
    if ($n === 0) { return 0; }
    $e = 0;
    while ($n % 10 === 0 && $e < 9) { $n = intdiv($n, 10); $e++; }
    if ($e < 9) { $d = $n % 10; $n = intdiv($n, 10); return 1 + ($n * 9 + $d - 1) * 10 + $e; }
    return 1 + ($n - 1) * 10 + 9;
}

/**
 * Read one Coin's compressed script and return [aggKey, kind, skip]. aggKey is the reconstructed
 * scriptPubKey hex for standard types (so identical scripts aggregate), a synthetic 'p2pk:' key
 * for bare pubkeys, or the raw hex otherwise. skip=true for MWEB pool outputs (not a holder).
 */
function lx_rl_read_script(callable $rd): array
{
    $nSize = lx_rl_read_varint($rd);
    if ($nSize === 0) { return ['76a914' . bin2hex($rd(20)) . '88ac', 'P2PKH', false]; }
    if ($nSize === 1) { return ['a914' . bin2hex($rd(20)) . '87', 'P2SH', false]; }
    if ($nSize === 2 || $nSize === 3) { return ['p2pk:' . $nSize . bin2hex($rd(32)), 'P2PK', false]; }
    if ($nSize === 4 || $nSize === 5) { return ['p2pk:' . $nSize . bin2hex($rd(32)), 'P2PK', false]; }
    $len = $nSize - 6;
    $hex = $len > 0 ? bin2hex($rd($len)) : '';
    // MWEB HogAddr (witness v8: 58 20 <32>) or peg-in (v9: 59 20 <32>): the shielded pool, not a holder.
    if ($len === 34 && (strncmp($hex, '5820', 4) === 0 || strncmp($hex, '5920', 4) === 0)) {
        return [$hex, 'MWEB', true];
    }
    return [$hex, 'raw', false];
}

/** Decode an aggregation key to [address, kind] for display. Only called for the top-N. */
function lx_rl_key_to_address(string $key, array $net): array
{
    if (strncmp($key, 'p2pk:', 5) === 0) { return ['', 'P2PK']; }
    $b = @hex2bin($key);
    if ($b === false) { return ['', 'other']; }
    $n = strlen($b);
    if ($n === 25 && $b[0] === "\x76" && $b[1] === "\xa9" && $b[2] === "\x14" && substr($b, 23) === "\x88\xac") {
        return [lx_base58check_encode(chr($net['p2pkh']) . substr($b, 3, 20)), 'P2PKH'];
    }
    if ($n === 23 && $b[0] === "\xa9" && $b[1] === "\x14" && $b[22] === "\x87") {
        return [lx_base58check_encode(chr($net['p2sh']) . substr($b, 2, 20)), 'P2SH'];
    }
    if ($n === 22 && $b[0] === "\x00" && $b[1] === "\x14") {
        return [lx_segwit_encode($net['bech32'], 0, substr($b, 2, 20)), 'P2WPKH'];
    }
    if ($n === 34 && $b[0] === "\x00" && $b[1] === "\x20") {
        return [lx_segwit_encode($net['bech32'], 0, substr($b, 2, 32)), 'P2WSH'];
    }
    if ($n === 34 && $b[0] === "\x51" && $b[1] === "\x20") {
        return [lx_segwit_encode($net['bech32'], 1, substr($b, 2, 32)), 'P2TR'];
    }
    return ['', 'other'];
}

// ---- self-test: synthesize a dump, parse it, assert -------------------------

function lx_rl_selftest(): int
{
    // Build three coins: two to the same P2PKH (must aggregate), one P2WPKH.
    $h20a = str_repeat("\x11", 20); $h20b = str_repeat("\x22", 20); $prog = str_repeat("\x33", 20);
    $coin = function (int $height, bool $cb, int $sat, string $scriptField): string {
        $code = ($height << 1) | ($cb ? 1 : 0);
        return str_repeat("\x00", 36)   // outpoint (txid+vout) - value irrelevant to parse
            . lx_rl_write_varint($code)
            . lx_rl_write_varint(lx_rl_compress_amount($sat))
            . $scriptField;
    };
    $p2pkh = function ($h) { return lx_rl_write_varint(0) . $h; };            // nSize=0 special
    $p2wpkh = function ($p) { $s = "\x00\x14" . $p; return lx_rl_write_varint(strlen($s) + 6) . $s; };
    $body = $coin(100, false, 500000000, $p2pkh($h20a))       // 5 LTC -> A
          . $coin(101, false, 250000000, $p2pkh($h20a))       // 2.5 LTC -> A (same addr)
          . $coin(102, true,  1000000000, $p2wpkh($prog));    // 10 LTC -> W
    $header = str_repeat("\xaa", 32) . pack('P', 3) . pack('V', 12345);
    $dump = $header . $body;

    // parse it
    $pos = 0;
    $rd = function (int $len) use (&$pos, $dump): string { $s = substr($dump, $pos, $len); $pos += strlen($s); return $s; };
    $rd(32); $cc = unpack('P', $rd(8))[1]; $rd(4);
    $agg = [];
    for ($i = 0; $i < $cc; $i++) {
        $rd(36); lx_rl_read_varint($rd); $amt = lx_rl_decompress_amount(lx_rl_read_varint($rd));
        [$key, , $skip] = lx_rl_read_script($rd);
        if ($skip) { continue; }
        $agg[$key] = ($agg[$key] ?? 0) + $amt;
    }
    $net = ['p2pkh' => 0x30, 'p2sh' => 0x32, 'bech32' => 'ltc'];
    $ok = true; $fail = function ($m) use (&$ok) { $ok = false; fwrite(STDERR, "  FAIL $m\n"); };
    if ($cc !== 3) { $fail("coins_count $cc != 3"); }
    $keyA = '76a914' . bin2hex($h20a) . '88ac';
    if (($agg[$keyA] ?? 0) !== 750000000) { $fail('P2PKH aggregation ' . ($agg[$keyA] ?? 0) . ' != 750000000'); }
    [$addrA, $kindA] = lx_rl_key_to_address($keyA, $net);
    if ($kindA !== 'P2PKH' || strlen($addrA) < 26 || $addrA[0] !== 'L') { $fail("P2PKH addr '$addrA' ($kindA)"); }
    [$addrW, $kindW] = lx_rl_key_to_address("0014" . bin2hex($prog), $net);
    if ($kindW !== 'P2WPKH' || strncmp($addrW, 'ltc1', 4) !== 0) { $fail("P2WPKH addr '$addrW' ($kindW)"); }
    // amount roundtrips
    foreach ([0, 1, 546, 100000000, 2500000000, 8400000000000000] as $v) {
        if (lx_rl_decompress_amount(lx_rl_compress_amount($v)) !== $v) { $fail("amount roundtrip $v"); }
    }
    // varint roundtrips
    foreach ([0, 127, 128, 255, 16383, 16384, 65535, 4294967296] as $v) {
        $p2 = 0; $s = lx_rl_write_varint($v);
        $r = function (int $len) use (&$p2, $s): string { $o = substr($s, $p2, $len); $p2 += strlen($o); return $o; };
        if (lx_rl_read_varint($r) !== $v) { $fail("varint roundtrip $v"); }
    }
    fwrite(STDERR, $ok ? "selftest: PASS (header, varint, amount, script, aggregation, address encode)\n" : "selftest: FAILED\n");
    return $ok ? 0 : 1;
}

// ---- main ------------------------------------------------------------------

$args = array_slice($argv, 1);
$flag = function (string $n) use ($args): bool { return in_array('--' . $n, $args, true); };
$val  = function (string $n, $d) use ($args) { $p = '--' . $n . '='; foreach ($args as $a) { if (strpos($a, $p) === 0) { return substr($a, strlen($p)); } } return $d; };

if ($flag('selftest')) { exit(lx_rl_selftest()); }

$positional = [];
foreach ($args as $a) { if (strpos($a, '--') !== 0) { $positional[] = $a; } }
$dumpFile = $positional[0] ?? '';
$slug = $positional[1] ?? null;
$topN = max(100, min(100000, (int) $val('top', 10000)));
$verifyN = max(0, (int) $val('verify', 25));

if ($dumpFile === '' || !is_file($dumpFile)) {
    fwrite(STDERR, "usage: php tools/richlist.php <dumptxoutset-file> [slug] [--top=10000] [--verify=25]\n");
    fwrite(STDERR, "produce it with:  litecoin-cli dumptxoutset <file>\n");
    exit(1);
}
$net = $slug ? lx_net($slug) : (array_values(lx_networks())[0] ?? null);
if (!$net) { fwrite(STDERR, "unknown network\n"); exit(1); }

$fh = fopen($dumpFile, 'rb');
if (!$fh) { fwrite(STDERR, "cannot open $dumpFile\n"); exit(1); }
$buf = ''; $bpos = 0; $BUFSZ = 1 << 20;
$rd = function (int $len) use (&$buf, &$bpos, $fh, $BUFSZ): string {
    $out = '';
    while ($len > 0) {
        $avail = strlen($buf) - $bpos;
        if ($avail <= 0) {
            $buf = fread($fh, max($BUFSZ, $len)); $bpos = 0;
            if ($buf === '' || $buf === false) { return $out; }
            $avail = strlen($buf);
        }
        $take = min($len, $avail);
        $out .= substr($buf, $bpos, $take); $bpos += $take; $len -= $take;
    }
    return $out;
};

// Header (44 bytes): base_blockhash[32] + coins_count u64LE + nchaintx u32LE.
$baseHashHex = bin2hex(strrev($rd(32)));   // stored in internal (reversed) order -> display hex
$cc = @unpack('P', $rd(8))[1] ?? 0;
$rd(4);   // nchaintx - consume (off-by-4 corrupts everything if skipped)
if ($cc <= 0) { fwrite(STDERR, "bad coins_count ($cc) - not a 0.21 dumptxoutset file?\n"); exit(2); }
// The balances are as-of the dump's base block, NOT the live tip; resolve its height so the
// page labels the snapshot correctly (and churn since the dump reads as expected, not a bug).
$snapHeight = 0;
$hdr = lx_rpc_soft($net, 'getblockheader', [$baseHashHex]);
if (is_array($hdr) && isset($hdr['height'])) { $snapHeight = (int) $hdr['height']; }
fwrite(STDERR, 'snapshot block: ' . ($snapHeight ?: '(unresolved) ') . ' ' . substr($baseHashHex, 0, 16) . "...\n");
fwrite(STDERR, "dump: $cc UTXOs to aggregate\n");

// Scratch aggregation DB (disposable: fast pragmas, dropped at the end).
$scratchPath = (dirname(lx_config()['cache_db']) ?: sys_get_temp_dir()) . '/richlist_scratch.sqlite';
@unlink($scratchPath); @unlink($scratchPath . '-journal'); @unlink($scratchPath . '-wal');
$agg = new PDO('sqlite:' . $scratchPath);
$agg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$agg->exec('PRAGMA synchronous = OFF');
$agg->exec('PRAGMA journal_mode = MEMORY');
$agg->exec('PRAGMA temp_store = FILE');
$agg->exec('CREATE TABLE agg (spk TEXT PRIMARY KEY, sat INTEGER NOT NULL)');
$up = $agg->prepare('INSERT INTO agg(spk, sat) VALUES(?, ?) ON CONFLICT(spk) DO UPDATE SET sat = sat + excluded.sat');

$mwebSat = 0; $done = 0; $t0 = microtime(true);
$agg->beginTransaction();
for ($i = 0; $i < $cc; $i++) {
    $rd(36);                              // outpoint (discard)
    lx_rl_read_varint($rd);              // code (height/coinbase/pegout - unused)
    $amt = lx_rl_decompress_amount(lx_rl_read_varint($rd));
    [$key, $kind, $skip] = lx_rl_read_script($rd);
    if ($skip) { $mwebSat += $amt; continue; }
    $up->execute([$key, $amt]);
    if ((++$done % 200000) === 0) {
        $agg->commit(); $agg->beginTransaction();
        $rate = $done / max(0.001, microtime(true) - $t0);
        fwrite(STDERR, sprintf("\r  aggregated %d / %d  (%.0f/s)   ", $done, $cc, $rate));
    }
}
$agg->commit();
fclose($fh);
fwrite(STDERR, sprintf("\r  aggregated %d UTXOs into distinct scripts.            \n", $done));

// Summary over the aggregated set.
$total = (int) $agg->query('SELECT COALESCE(SUM(sat),0) FROM agg')->fetchColumn();
$addrCount = (int) $agg->query('SELECT COUNT(*) FROM agg')->fetchColumn();
if ($addrCount === 0) { fwrite(STDERR, "no spendable outputs aggregated - aborting.\n"); exit(2); }

// Top-N (descending) -> decode addresses.
$topRows = [];
$q = $agg->query('SELECT spk, sat FROM agg ORDER BY sat DESC LIMIT ' . (int) $topN);
$rank = 0; $top1 = 0; $top10 = 0; $top100 = 0; $top1000 = 0;
foreach ($q as $r) {
    $rank++;
    $sat = (int) $r['sat'];
    if ($rank <= 1) { $top1 += $sat; }
    if ($rank <= 10) { $top10 += $sat; }
    if ($rank <= 100) { $top100 += $sat; }
    if ($rank <= 1000) { $top1000 += $sat; }
    [$addr, $kind] = lx_rl_key_to_address((string) $r['spk'], $net);
    $topRows[] = [$rank, $addr, $kind, $sat];
}

// Gini via a window-function pass (SQLite >= 3.25): sum((2*rank - n - 1) * sat) / (n * total).
$gini = 0.0;
try {
    $st = $agg->prepare('SELECT COALESCE(SUM((2.0 * rn - :n - 1.0) * sat), 0) FROM '
        . '(SELECT sat, ROW_NUMBER() OVER (ORDER BY sat ASC) rn FROM agg)');
    $st->bindValue(':n', $addrCount, PDO::PARAM_INT);
    $st->execute();
    $num = (float) $st->fetchColumn();
    $gini = ($addrCount > 0 && $total > 0) ? $num / ($addrCount * $total) : 0.0;
} catch (Throwable $e) {
    fwrite(STDERR, "  (Gini skipped: " . $e->getMessage() . ")\n");
}

// Balance-band histogram.
$bandDefs = lx_wealth_bands();
$case = 'CASE'; foreach ($bandDefs as $bi => $bd) { if ($bd['max'] < PHP_INT_MAX) { $case .= " WHEN sat < {$bd['max']} THEN $bi"; } }
$case .= ' ELSE ' . (count($bandDefs) - 1) . ' END';
$bandAgg = [];
foreach ($agg->query("SELECT $case AS band, COUNT(*) c, COALESCE(SUM(sat),0) s FROM agg GROUP BY band") as $r) {
    $bandAgg[(int) $r['band']] = ['count' => (int) $r['c'], 'sat' => (int) $r['s']];
}
$bands = [];
foreach ($bandDefs as $bi => $bd) {
    $bands[] = ['label' => $bd['label'], 'count' => $bandAgg[$bi]['count'] ?? 0, 'sat' => $bandAgg[$bi]['sat'] ?? 0];
}

// Optional node cross-check BEFORE publishing (guards against parser/format drift).
if ($verifyN > 0) {
    $checked = 0; $bad = 0;
    foreach (array_slice($topRows, 0, $verifyN) as $tr) {
        [, $addr, , $sat] = $tr;
        if ($addr === '') { continue; }
        $stat = function_exists('lx_address_stats') ? lx_address_stats($net, $addr) : null;
        if (!is_array($stat) || !isset($stat['chain_stats'])) { continue; }   // node unreachable -> not counted
        $bal = (int) ($stat['chain_stats']['funded_txo_sum'] ?? 0) - (int) ($stat['chain_stats']['spent_txo_sum'] ?? 0);
        $checked++;
        if (abs($bal - $sat) > max(1000, (int) ($sat * 0.001))) {   // >0.1% or 1000 sat off the live balance
            $bad++;
            fwrite(STDERR, "  verify drift $addr: parsed $sat vs node $bal\n");
        }
    }
    // Fail CLOSED: if we couldn't actually check enough samples (electrum blipping - common, since the
    // dump itself hammers the node), do NOT publish an unverified list.
    $floor = min($verifyN, 5);
    if ($checked < $floor) {
        fwrite(STDERR, "\nABORTING: only $checked/$verifyN samples could be checked (electrum/node unreachable?); NOT publishing.\n");
        @unlink($scratchPath);
        exit(3);
    }
    // A few top holders churn between the dump and this check (expected). A parser bug corrupts
    // ~all balances, so only a MAJORITY of mismatches means the parse is wrong.
    if ($bad > intdiv($checked, 2)) {
        fwrite(STDERR, "\nABORTING: $bad/$checked sampled balances disagree with the node - the parser is likely wrong for this dump; NOT publishing.\n");
        @unlink($scratchPath);
        exit(3);
    }
    fwrite(STDERR, "  verify: $checked checked, $bad drifted (tolerated as churn since the dump). Publishing.\n");
}

// Publish: rewrite rich_list + wealth_summary for this network.
$db = lx_richlist_pdo(true);
if (!$db) { fwrite(STDERR, "cannot open db/richlist.sqlite for writing\n"); exit(1); }
$db->beginTransaction();
$db->prepare('DELETE FROM rich_list WHERE net = ?')->execute([$net['slug']]);
$ins = $db->prepare('INSERT INTO rich_list(net, rank, address, kind, sat) VALUES(?,?,?,?,?)');
foreach ($topRows as $tr) { $ins->execute([$net['slug'], $tr[0], $tr[1], $tr[2], $tr[3]]); }
$db->prepare('INSERT OR REPLACE INTO wealth_summary(net,updated,tip_height,total_sat,utxo_count,addr_count,top1_sat,top10_sat,top100_sat,top1000_sat,gini,bands) '
    . 'VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
   ->execute([$net['slug'], time(), ($snapHeight ?: lx_tip_height($net)), $total, $done, $addrCount,
              $top1, $top10, $top100, $top1000, $gini, json_encode($bands, JSON_UNESCAPED_SLASHES)]);
$db->commit();
@unlink($scratchPath); @unlink($scratchPath . '-journal'); @unlink($scratchPath . '-wal');

fwrite(STDERR, sprintf(
    "done: %s addresses, top %d persisted. total %s LTC, MWEB pool %s LTC, Gini %.3f. %s\n",
    number_format($addrCount), count($topRows),
    number_format($total / 1e8, 2), number_format($mwebSat / 1e8, 2), $gini,
    'built in ' . round(microtime(true) - $t0, 1) . 's'));
