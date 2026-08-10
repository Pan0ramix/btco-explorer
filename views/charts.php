<?php
/**
 * Charts hub: every server-rendered analytics chart in one place - mempool/fee
 * time-series, difficulty/hashrate, and per-block economics. The same charts also
 * appear contextually on the Mempool and Mining dashboards; this is the browse-all
 * destination. $net in scope (UTXO). SPDX-License-Identifier: AGPL-3.0-or-later
 */
$base = lx_u($net);

// Fetch everything BEFORE lx_head so a node outage yields a clean 503 rather than
// a half-rendered page.
$hist   = function_exists('lx_stats_series') ? lx_stats_series($net, 48) : [];  // mempool/fee history (snapshot cron)
$dser   = lx_difficulty_series($net, 24, 12000);   // oldest-first
$stats  = lx_recent_block_stats($net, 30);         // newest-first
$epochs = lx_difficulty_epochs($net, 12);          // retarget history
$poolSeries = function_exists('lx_pool_share_series') ? lx_pool_share_series($net, 6) : ['rows' => [], 'pools' => []];

lx_head($net, ['title' => 'Charts - ' . $net['label'] . ' Explorer']);

// ---- per-block bar/stack series (Blocks section) ----------------------------
// A ?period= (24h..all) sources these from the bucketed block index; otherwise the
// last 30 blocks straight from the node. Falls back to the 30-block path if the index
// has no coverage yet for the chosen period.
$period   = (isset($_GET['period']) && lx_period_valid((string) $_GET['period'])) ? (string) $_GET['period'] : '';
$biSeries = ($period !== '' && function_exists('lx_blockindex_series')) ? lx_blockindex_series($net, $period) : [];
$actSeries = function_exists('lx_activity_series') ? lx_activity_series($net, $period) : [];   // Tier E: active addresses + CDD + tx/day
$ageBands  = function_exists('lx_utxo_age_bands') ? lx_utxo_age_bands($net) : [];              // HODL waves: supply by coin age
$logScale = (($_GET['scale'] ?? '') === 'log');   // log y-axis toggle for the wide-range trend charts
// Build a /charts query string preserving both selectors so switching one keeps the other.
$mkqs = function (string $p, bool $log): string { $q = []; if ($p !== '') { $q[] = 'period=' . $p; } if ($log) { $q[] = 'scale=log'; } return $q ? '?' . implode('&', $q) : ''; };
$feeBars = []; $sizeBars = []; $frBars = []; $wtBars = []; $fsRows = []; $fsTips = [];
$txBars = []; $fullBars = []; $fpBars = []; $spreadRows = []; $spreadTips = []; $sentBars = []; $avgValBars = [];
$spreadKeys   = ['s10', 's25', 's50', 's75', 's90'];   // cumulative fee-rate percentile bands
$spreadColors = [lx_feerate_color(0.5), lx_feerate_color(2), lx_feerate_color(5), lx_feerate_color(12), lx_feerate_color(30)];
if ($biSeries) {
    foreach ($biSeries as $b) {
        $lab = 'Block ~#' . number_format((int) $b['avgHeight']);
        $feeBars[]  = ['value' => $b['avgFees'],   'title' => $lab . ' · avg ' . lx_coin((int) round($b['avgFees'])) . ' ' . $net['unit'] . ' fees'];
        $sizeBars[] = ['value' => $b['avgSize'],   'title' => $lab . ' · avg ' . lx_size_str((int) round($b['avgSize']), 'B')];
        $frBars[]   = ['value' => $b['p50'],       'title' => $lab . ' · median ' . number_format((float) $b['p50'], 2) . ' sat/vB'];
        $wtBars[]   = ['value' => $b['avgWeight'], 'title' => $lab . ' · avg ' . commas((int) round($b['avgWeight'])) . ' WU'];
        $fsRows[]   = ['height' => (int) $b['avgHeight'], 'subsidy' => max(0, $b['avgRewards'] - $b['avgFees']), 'total_fee' => $b['avgFees']];
        $fsTips[]   = lx_tip_json('Block ~#' . number_format((int) $b['avgHeight']), [
            ['c' => '#3b82f6', 'k' => 'Subsidy', 'v' => lx_coin_compact(max(0, $b['avgRewards'] - $b['avgFees'])) . ' ' . $net['unit']],
            ['c' => '#f59e0b', 'k' => 'Fees',    'v' => lx_coin_compact((float) $b['avgFees']) . ' ' . $net['unit']],
        ]);
        $txBars[]   = ['value' => (float) $b['avgTxs'], 'title' => $lab . ' · ' . commas((int) round($b['avgTxs'])) . ' tx'];
        $fullPct    = min(100.0, (float) $b['avgWeight'] / 40000.0);   // avgWeight / 4,000,000 WU * 100
        $fullBars[] = ['value' => $fullPct, 'title' => $lab . ' · ' . number_format($fullPct, 1) . '% full'];
        $fpPct      = $b['avgRewards'] > 0 ? (float) $b['avgFees'] / (float) $b['avgRewards'] * 100.0 : 0.0;
        $fpBars[]   = ['value' => $fpPct, 'title' => $lab . ' · fees ' . number_format($fpPct, 1) . '% of reward'];
        $sentBars[] = ['value' => (float) ($b['avgOut'] ?? 0), 'title' => $lab . ' · ' . lx_coin_compact((float) ($b['avgOut'] ?? 0)) . ' ' . $net['unit'] . ' sent'];
        $avgTxVal   = $b['avgTxs'] > 0 ? (float) ($b['avgOut'] ?? 0) / (float) $b['avgTxs'] : 0.0;
        $avgValBars[] = ['value' => $avgTxVal, 'title' => $lab . ' · avg ' . lx_coin_compact($avgTxVal) . ' ' . $net['unit'] . '/tx'];
        $spreadRows[] = ['x' => (int) $b['avgHeight'],
            's10' => (float) $b['p10'],
            's25' => max(0.0, (float) $b['p25'] - (float) $b['p10']),
            's50' => max(0.0, (float) $b['p50'] - (float) $b['p25']),
            's75' => max(0.0, (float) $b['p75'] - (float) $b['p50']),
            's90' => max(0.0, (float) $b['p90'] - (float) $b['p75'])];
        $spreadTips[] = lx_tip_json($lab, [
            ['c' => $spreadColors[4], 'k' => 'p90',    'v' => number_format((float) $b['p90'], 2) . ' s/vB'],
            ['c' => $spreadColors[3], 'k' => 'p75',    'v' => number_format((float) $b['p75'], 2) . ' s/vB'],
            ['c' => $spreadColors[2], 'k' => 'median', 'v' => number_format((float) $b['p50'], 2) . ' s/vB'],
            ['c' => $spreadColors[1], 'k' => 'p25',    'v' => number_format((float) $b['p25'], 2) . ' s/vB'],
            ['c' => $spreadColors[0], 'k' => 'p10',    'v' => number_format((float) $b['p10'], 2) . ' s/vB'],
        ]);
    }
} else {
    foreach (array_reverse($stats) as $b) {
        $bh = number_format($b['height']);
        $feeBars[]  = ['value' => $b['total_fee'], 'title' => 'Block ' . $bh . ' · ' . lx_coin((int) $b['total_fee']) . ' ' . $net['unit'] . ' fees · ' . commas($b['txs']) . ' tx'];
        $sizeBars[] = ['value' => $b['size'],      'title' => 'Block ' . $bh . ' · ' . lx_size_str((int) $b['size'], 'B') . ' · ' . commas($b['txs']) . ' tx'];
        $frBars[]   = ['value' => $b['med_feerate'], 'title' => 'Block ' . $bh . ' · median ' . number_format((float) $b['med_feerate'], 2) . ' sat/vB · range ' . number_format((float) $b['min_feerate'], 2) . '–' . number_format((float) $b['max_feerate'], 2)];
        $wtBars[]   = ['value' => $b['weight'],    'title' => 'Block ' . $bh . ' · ' . commas($b['weight']) . ' WU · ' . commas($b['txs']) . ' tx'];
        $fsRows[]   = ['height' => $b['height'], 'subsidy' => $b['subsidy'], 'total_fee' => $b['total_fee']];
        $fsTips[]   = lx_tip_json('Block #' . $bh, [
            ['c' => '#3b82f6', 'k' => 'Subsidy', 'v' => lx_coin((int) $b['subsidy']) . ' ' . $net['unit']],
            ['c' => '#f59e0b', 'k' => 'Fees',    'v' => lx_coin((int) $b['total_fee']) . ' ' . $net['unit']],
        ]);
        $txBars[]   = ['value' => (float) $b['txs'], 'title' => 'Block ' . $bh . ' · ' . commas((int) $b['txs']) . ' tx'];
        $fullPct    = min(100.0, (float) $b['weight'] / 40000.0);
        $fullBars[] = ['value' => $fullPct, 'title' => 'Block ' . $bh . ' · ' . number_format($fullPct, 1) . '% full'];
        $rew        = (int) $b['subsidy'] + (int) $b['total_fee'];
        $fpPct      = $rew > 0 ? (float) $b['total_fee'] / $rew * 100.0 : 0.0;
        $fpBars[]   = ['value' => $fpPct, 'title' => 'Block ' . $bh . ' · fees ' . number_format($fpPct, 1) . '% of reward'];
        $sentBars[] = ['value' => (float) ($b['out_value'] ?? 0), 'title' => 'Block ' . $bh . ' · ' . lx_coin_compact((float) ($b['out_value'] ?? 0)) . ' ' . $net['unit'] . ' sent'];
        $avgTxVal   = $b['txs'] > 0 ? (float) ($b['out_value'] ?? 0) / (float) $b['txs'] : 0.0;
        $avgValBars[] = ['value' => $avgTxVal, 'title' => 'Block ' . $bh . ' · avg ' . lx_coin_compact($avgTxVal) . ' ' . $net['unit'] . '/tx'];
        $mn = (float) ($b['min_feerate'] ?? 0); $md = (float) ($b['med_feerate'] ?? 0); $mx = (float) ($b['max_feerate'] ?? 0);
        $spreadRows[] = ['x' => (int) $b['height'], 's10' => $mn, 's25' => 0.0, 's50' => max(0.0, $md - $mn), 's75' => 0.0, 's90' => max(0.0, $mx - $md)];
        $spreadTips[] = lx_tip_json('Block #' . $bh, [
            ['c' => $spreadColors[4], 'k' => 'max',    'v' => number_format($mx, 2) . ' s/vB'],
            ['c' => $spreadColors[2], 'k' => 'median', 'v' => number_format($md, 2) . ' s/vB'],
            ['c' => $spreadColors[0], 'k' => 'min',    'v' => number_format($mn, 2) . ' s/vB'],
        ]);
    }
}
$blkXticks = [];
if (count($spreadRows) >= 2) {
    $xs = array_column($spreadRows, 'x'); $minX = min($xs); $spanX = max(1, max($xs) - $minX);
    for ($i = 0; $i <= 4; $i++) { $blkXticks[] = ['f' => $i / 4, 'label' => '#' . lx_num_compact($minX + $spanX * $i / 4)]; }
}
$blkSub = $biSeries ? (count($biSeries) . ' pts · ' . strtoupper($period)) : ('last ' . count($stats));

// ---- difficulty / hashrate labels + tips (Mining section) -------------------
$diffLabels = []; $hashLabels = []; $diffTips = []; $hashTips = [];
$prevD = null; $prevHr = null;
foreach ($dser as $r) {
    $d = (float) $r['difficulty']; $hr = (float) $r['hashrate'];
    $hdr = 'Block #' . number_format($r['height']);
    $diffLabels[] = '#' . number_format($r['height']) . ' · diff ' . lx_diff_str($d);
    $hashLabels[] = '#' . number_format($r['height']) . ' · ' . lx_hashrate($hr);
    $diffTips[] = lx_tip_json($hdr, [['c' => 'var(--accent)', 'k' => 'Difficulty', 'v' => lx_diff_str($d), 'd' => $prevD !== null ? lx_pct_delta($d, $prevD) : '']]);
    $hashTips[] = lx_tip_json($hdr, [['c' => 'var(--accent)', 'k' => 'Hashrate', 'v' => lx_hashrate($hr), 'd' => $prevHr !== null ? lx_pct_delta($hr, $prevHr) : '']]);
    $prevD = $d; $prevHr = $hr;
}
$dXticks = [];
if (count($dser) >= 2) {
    $hh = array_column($dser, 'height');
    $minH = min($hh); $spanH = max(1, max($hh) - $minH);
    for ($i = 0; $i <= 4; $i++) { $dXticks[] = ['f' => $i / 4, 'label' => '#' . lx_num_compact($minH + $spanH * $i / 4)]; }
}

// ---- pool dominance over time (index-backed, whole chain) --------------------
$poolColors = []; $poolTips = []; $poolXticks = [];
if (!empty($poolSeries['rows'])) {
    $okabe = ['#56B4E9', '#E69F00', '#009E73', '#CC79A7', '#F0E442', '#0072B2', '#D55E00'];   // Okabe-Ito, CVD-safe fixed order
    foreach ($poolSeries['pools'] as $pi => $pn) { $poolColors[] = $pn === 'Other' ? '#8c8f99' : ($okabe[$pi] ?? ('hsl(' . (($pi * 61) % 360) . ',60%,58%)')); }
    foreach ($poolSeries['rows'] as $r) {
        $trows = [];
        foreach ($poolSeries['pools'] as $pi => $pn) {
            $sh = (float) ($r[$pn] ?? 0);
            if ($sh >= 0.5) { $trows[] = ['c' => $poolColors[$pi], 'k' => $pn, 'v' => number_format($sh, 1) . '%']; }
        }
        $poolTips[] = lx_tip_json(gmdate('Y-m-d', (int) $r['ts']), $trows);
    }
    $pts = array_column($poolSeries['rows'], 'ts'); $minPt = min($pts); $spanPt = max(1, max($pts) - $minPt);
    for ($i = 0; $i <= 4; $i++) { $poolXticks[] = ['f' => $i / 4, 'label' => gmdate('M Y', (int) ($minPt + $spanPt * $i / 4))]; }
}

// ---- mempool / fee labels + tips (Mempool section) --------------------------
$memLabels = []; $feeLabels = []; $memTips = []; $feeTips = [];
$prevM = null; $prevF = null;
foreach ($hist as $r) {
    $mv = (int) $r['mempool_vsize']; $ff = (int) $r['fast_fee'];
    $hdr = gmdate('M j, H:i', (int) $r['ts']);
    $memLabels[] = gmdate('m-d H:i', (int) $r['ts']) . ' · ' . lx_size_str($mv, 'vB');
    $feeLabels[] = gmdate('m-d H:i', (int) $r['ts']) . ' · ' . number_format($ff) . ' sat/vB';
    $memTips[] = lx_tip_json($hdr, [['c' => 'var(--accent)', 'k' => 'Mempool', 'v' => lx_size_str($mv, 'vB'), 'd' => $prevM !== null ? lx_pct_delta($mv, $prevM) : '']]);
    $feeTips[] = lx_tip_json($hdr, [['c' => 'var(--accent)', 'k' => 'Fast fee', 'v' => number_format($ff) . ' sat/vB', 'd' => $prevF !== null ? lx_pct_delta($ff, $prevF) : '']]);
    $prevM = $mv; $prevF = $ff;
}
$hXticks = [];
if (count($hist) >= 2) {
    $tt = array_column($hist, 'ts');
    $minTs = min($tt); $spanTs = max(1, max($tt) - $minTs);
    for ($i = 0; $i <= 4; $i++) { $hXticks[] = ['f' => $i / 4, 'label' => gmdate('m-d H:i', (int) ($minTs + $spanTs * $i / 4))]; }
}
// Mempool-by-fee-tier (stacked) - only when there's tier data.
$tierKeys = ['t0', 't1', 't2', 't3', 't4', 't5'];
$tierColors = [lx_feerate_color(0.5), lx_feerate_color(1.5), lx_feerate_color(3.5), lx_feerate_color(7), lx_feerate_color(15), lx_feerate_color(40)];
$tierLabels = ['<1', '1–2', '2–5', '5–10', '10–20', '20+'];
$hasTiers = false;
foreach ($hist as $r) {
    foreach ($tierKeys as $k) { if (($r[$k] ?? 0) > 0) { $hasTiers = true; break 2; } }
}
$tierLegend = [];
foreach ($tierKeys as $ti => $tk) { $tierLegend[] = ['color' => $tierColors[$ti], 'label' => $tierLabels[$ti] . ' sat/vB']; }
$stackTips = [];
foreach ($hist as $r) {
    $trows = [];
    foreach ($tierKeys as $ti => $tk) {
        $tvs = (int) ($r[$tk] ?? 0);
        if ($tvs > 0) { $trows[] = ['c' => $tierColors[$ti], 'k' => $tierLabels[$ti] . ' sat/vB', 'v' => lx_size_str($tvs, 'vB')]; }
    }
    $stackTips[] = lx_tip_json(gmdate('M j, H:i', (int) $r['ts']), $trows);
}

// ---- long-range: coin-supply curve (deterministic) + price history ----------
$supplyRows = []; $supplyLabels = []; $supplyXticks = [];
$tipH = lx_tip_height($net);
$isLtc = ($net['coin'] ?? '') === 'ltc';
$interval = 840000; $sub0 = 5000000000;
// Total satoshis mined by height $h on the deterministic Litecoin subsidy schedule. Hoisted so the
// supply curve AND the market-cap chart (supply-at-time x price) can both use it.
$minedAt = function ($h) use ($interval, $sub0) {
    $blocks = $h + 1; $sub = $sub0; $mined = 0;
    while ($blocks > 0 && $sub > 0) {
        $take = $blocks < $interval ? $blocks : $interval;
        $mined += $take * $sub; $blocks -= $take; $sub = intdiv($sub, 2);
    }
    return $mined;
};
if ($tipH > 0 && $isLtc) {
    for ($i = 0; $i <= 40; $i++) {
        $hh = (int) round($tipH * $i / 40);
        $co = $minedAt($hh) / 100000000;
        $supplyRows[]   = ['h' => $hh, 'supply' => $co];
        $supplyLabels[] = '#' . number_format($hh) . ' · ' . number_format($co) . ' LTC';
    }
    for ($i = 0; $i <= 4; $i++) { $supplyXticks[] = ['f' => $i / 4, 'label' => '#' . lx_num_compact($tipH * $i / 4)]; }
}

$priceRows = []; $priceLabels = []; $priceXticks = [];
$pcur = strtoupper(lx_price_config()['display']);
$phist = function_exists('lx_price_history') ? lx_price_history($net) : ['prices' => []];
foreach (($phist['prices'] ?? []) as $pp) {
    if (isset($pp[$pcur])) {
        $priceRows[]   = ['t' => (int) $pp['time'], 'p' => (float) $pp[$pcur]];
        $priceLabels[] = gmdate('M j', (int) $pp['time']) . ' · ' . lx_price_symbol(strtolower($pcur)) . number_format((float) $pp[$pcur], 2);
    }
}
// If the local snapshot history is too sparse (the price cron hasn't accumulated
// points yet), fall back to CoinGecko's historical daily series so the chart works.
if (count($priceRows) < 2 && function_exists('lx_price_market_chart')) {
    $priceLabels = [];
    foreach (lx_price_market_chart($net, $pcur, 365) as $pp) {
        $priceRows[]   = $pp;
        $priceLabels[] = gmdate('M j', $pp['t']) . ' · ' . lx_price_symbol(strtolower($pcur)) . number_format($pp['p'], 2);
    }
}
if (count($priceRows) >= 2) {
    $pt = array_column($priceRows, 't'); $minP = min($pt); $spanP = max(1, max($pt) - $minP);
    for ($i = 0; $i <= 4; $i++) { $priceXticks[] = ['f' => $i / 4, 'label' => gmdate('M j', (int) ($minP + $spanP * $i / 4))]; }
}
$psym = lx_price_symbol(strtolower($pcur));

// ---- market cap over time (supply-at-time x price) + value sent in fiat ------
// Nearest price point to a timestamp (price series is coarse daily-ish, so nearest is fine).
$priceAt = function (int $t) use ($priceRows) {
    $best = null; $bd = PHP_INT_MAX;
    foreach ($priceRows as $pr) { $d = abs((int) $pr['t'] - $t); if ($d < $bd) { $bd = $d; $best = (float) $pr['p']; } }
    return $best;
};
$mcapRows = []; $mcapLabels = [];
if (count($priceRows) >= 2 && $tipH > 0 && $isLtc) {
    $nowT = time();
    foreach ($priceRows as $pr) {
        $t = (int) $pr['t'];
        $hAtT = max(0, $tipH - (int) (($nowT - $t) / 150));   // ~150s per LTC block back from the tip
        $mc = $minedAt($hAtT) / 100000000 * (float) $pr['p'];
        $mcapRows[]   = ['t' => $t, 'm' => $mc];
        $mcapLabels[] = gmdate('M j, Y', $t) . ' · ' . $psym . lx_num_compact($mc);
    }
}
// Value sent per block/day, converted to fiat at the price of that point's time.
$sentTimed = [];
if ($biSeries) {
    foreach ($biSeries as $b) { $sentTimed[] = ['t' => (int) $b['timestamp'], 'ltc' => (float) ($b['avgOut'] ?? 0) / 100000000]; }
} else {
    foreach (array_reverse($stats) as $b) { $sentTimed[] = ['t' => (int) $b['time'], 'ltc' => (float) ($b['out_value'] ?? 0) / 100000000]; }
}
$sentUsdRows = []; $sentUsdLabels = [];
if ($sentTimed && count($priceRows) >= 2) {
    // Only value blocks that fall WITHIN the price coverage window. $priceAt does a nearest match
    // and never returns null, so a block older than our price history (e.g. ?period=all reaching
    // pre-coverage years) would otherwise be clamped to the oldest price - "time-of-block price"
    // off by orders of magnitude. Skip those rather than mis-value them (+1 day tolerance on edges).
    $ptAll = array_column($priceRows, 't'); $minPt = min($ptAll) - 86400; $maxPt = max($ptAll) + 86400;
    foreach ($sentTimed as $s) {
        if ($s['t'] < $minPt || $s['t'] > $maxPt) { continue; }
        $p = $priceAt($s['t']); if ($p === null) { continue; }
        $usd = $s['ltc'] * $p;
        $sentUsdRows[]   = ['t' => $s['t'], 'u' => $usd];
        $sentUsdLabels[] = gmdate('M j', $s['t']) . ' · ' . $psym . lx_num_compact($usd);
    }
}
$sentUsdXticks = [];
if (count($sentUsdRows) >= 2) {
    $su = array_column($sentUsdRows, 't'); $mnU = min($su); $spU = max(1, max($su) - $mnU);
    for ($i = 0; $i <= 4; $i++) { $sentUsdXticks[] = ['f' => $i / 4, 'label' => gmdate('M j', (int) ($mnU + $spU * $i / 4))]; }
}

$hasAny = (count($hist) >= 2) || (count($dser) >= 2) || !empty($stats) || count($supplyRows) >= 2 || count($priceRows) >= 2 || count($actSeries) >= 2;
?>
<h1>Charts</h1>
<p class="muted sub">Every server-rendered chart for <?= h($net['label']) ?>. The mempool &amp; fee series are fed by periodic snapshots; block series come straight from the node. The same charts also appear on the <a href="<?= h($base) ?>/mempool">Mempool</a> and <a href="<?= h($base) ?>/mining">Mining</a> dashboards.</p>
<div class="row mt-2" style="align-items:center"><span class="muted sub">Y-axis</span><div class="range-tog"><a class="<?= !$logScale ? 'on' : '' ?>" href="<?= h($base) ?>/charts<?= h($mkqs($period, false)) ?>">Linear</a><a class="<?= $logScale ? 'on' : '' ?>" href="<?= h($base) ?>/charts<?= h($mkqs($period, true)) ?>">Log</a></div></div>

<?php if (!$hasAny): ?>
<div class="card"><div class="card-b"><p class="muted">No chart data yet. The snapshot history builds up over time.</p></div></div>
<?php endif; ?>

<?php if (count($hist) >= 2): ?>
<div class="section-h">Mempool &amp; fees</div>
<div class="txio">
  <div class="card">
    <div class="card-h"><span><?= lx_icon('layers') ?>Mempool size</span> <span class="sub">over time · vB</span></div>
    <div class="card-b">
      <?= lx_chart_area($hist, 'ts', 'mempool_vsize', 'Mempool virtual size over time', $memLabels, [
          'yfmt'   => function ($v, $step = 0.0) { return lx_size_str((int) $v, 'vB', $step); },
          'xticks' => $hXticks,
          'tips'   => $memTips,
      ]) ?>
    </div>
  </div>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('trending-up') ?>Next-block fee</span> <span class="sub">over time · sat/vB</span></div>
    <div class="card-b">
      <?= lx_chart_area($hist, 'ts', 'fast_fee', 'Next-block fee rate over time', $feeLabels, [
          'yfmt'   => function ($v, $step = 0.0) { return number_format($v, lx_step_dec($step, 0)) . ' s/vB'; },
          'xticks' => $hXticks,
          'tips'   => $feeTips,
      ]) ?>
    </div>
  </div>
</div>
<?php if ($hasTiers): ?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('layers') ?>Mempool by fee rate</span> <span class="sub">over time · vsize, green low &rarr; red high</span></div>
  <div class="card-b">
    <?= lx_chart_stacked($hist, 'ts', $tierKeys, $tierColors, 'Mempool vsize by fee tier over time', [
        'yfmt'   => function ($v) { return lx_size_str((int) $v, 'vB'); },
        'xticks' => $hXticks,
        'legend' => $tierLegend,
        'tips'   => $stackTips,
    ]) ?>
  </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if (count($dser) >= 2): ?>
<div class="section-h">Mining</div>
<div class="txio">
  <div class="card">
    <div class="card-h"><span><?= lx_icon('target') ?>Difficulty</span> <span class="sub">recent blocks</span></div>
    <div class="card-b">
      <?= lx_chart_area($dser, 'height', 'difficulty', 'Difficulty over recent blocks', $diffLabels, [
          'yfmt'   => 'lx_num_compact',
          'xticks' => $dXticks,
          'tips'   => $diffTips,
          'log'    => $logScale,
      ]) ?>
    </div>
  </div>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('zap') ?>Hashrate</span> <span class="sub">estimated</span></div>
    <div class="card-b">
      <?= lx_chart_area($dser, 'height', 'hashrate', 'Estimated hashrate over recent blocks', $hashLabels, [
          'yfmt'   => 'lx_hashrate',
          'xticks' => $dXticks,
          'tips'   => $hashTips,
          'log'    => $logScale,
      ]) ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($epochs)):
    // Retarget history as a diverging chart (increase up / decrease down), oldest -> newest.
    $epochRows = []; $epochLabels = []; $epochTips = [];
    foreach (array_reverse($epochs) as $e) {
        $pct = (float) $e['pct_change'];
        $epochRows[]   = ['up' => max(0.0, $pct), 'down' => max(0.0, -$pct)];
        $epochLabels[] = '#' . number_format((int) $e['height']) . ' · ' . ($pct >= 0 ? '+' : '') . number_format($pct, 2) . '%';
        $epochTips[]   = lx_tip_json(gmdate('Y-m-d', (int) $e['time']) . ' · retarget #' . number_format((int) $e['height']), [
            ['c' => $pct >= 0 ? 'var(--ok)' : 'var(--bad)', 'k' => 'Change',     'v' => ($pct >= 0 ? '+' : '') . number_format($pct, 2) . '%'],
            ['c' => 'var(--muted)',                          'k' => 'Difficulty', 'v' => lx_diff_str((float) $e['difficulty'])],
        ]);
    }
?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('target') ?>Difficulty adjustments</span> <span class="sub">per 2,016-block retarget · %</span></div>
  <div class="card-b"><?= lx_chart_diverging($epochRows, 'up', 'down', 'Difficulty change per retarget (increase above, decrease below)', $epochLabels, [
      'yfmt'   => function ($v, $step = 0.0) { return number_format($v, lx_step_dec($step, 1)) . '%'; },
      'tips'   => $epochTips,
      'legend' => [['color' => 'var(--ok)', 'label' => 'Increase'], ['color' => 'var(--bad)', 'label' => 'Decrease']],
  ]) ?></div>
</div>
<?php endif; ?>

<?php if (!empty($poolSeries['rows'])): ?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('cpu') ?>Pool dominance over time</span> <span class="sub">block share &middot; % &middot; whole index</span></div>
  <div class="card-b"><?= lx_chart_stacked($poolSeries['rows'], 'ts', $poolSeries['pools'], $poolColors, 'Mining pool block share over time', [
      'yfmt'   => function ($v, $step = 0.0) { return number_format($v, lx_step_dec($step, 0)) . '%'; },
      'xticks' => $poolXticks,
      'legend' => array_map(function ($pn, $pi) use ($poolColors) { return ['color' => $poolColors[$pi], 'label' => $pn]; }, $poolSeries['pools'], array_keys($poolSeries['pools'])),
      'tips'   => $poolTips,
  ]) ?></div>
</div>
<?php endif; ?>

<?php if ($stats || $biSeries): ?>
<div class="section-h" id="blocks">Blocks <a class="btn ghost sm" style="float:right;font-weight:600" href="<?= h($base) ?>/charts/export?metric=blocks<?= $period !== '' ? '&amp;period=' . h($period) : '' ?>" title="Download this block series as CSV"><?= lx_icon('database') ?>CSV</a></div>
<div class="period-sel"><?php $pers = ['' => 'Recent', '24h' => '24h', '3d' => '3d', '1w' => '1w', '1m' => '1m', '3m' => '3m', '6m' => '6m', '1y' => '1y', 'all' => 'All']; foreach ($pers as $pk => $pl): ?><a class="<?= $period === $pk ? 'on' : '' ?>" href="<?= h($base) ?>/charts<?= h($mkqs($pk, $logScale)) ?>#blocks"><?= h($pl) ?></a><?php endforeach; ?></div>
<div class="txio">
  <div class="card">
    <div class="card-h"><span><?= lx_icon('trending-up') ?>Fees per block</span> <span class="sub"><?= h($blkSub) ?> · <?= h($net['unit']) ?></span></div>
    <div class="card-b"><?= lx_chart_bars($feeBars, 'Total fees per block', ['yfmt' => 'lx_coin_compact']) ?></div>
  </div>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('layers') ?>Block size</span> <span class="sub"><?= h($blkSub) ?></span></div>
    <div class="card-b"><?= lx_chart_bars($sizeBars, 'Block size', ['yfmt' => function ($v) { return lx_size_str((int) $v, 'B'); }]) ?></div>
  </div>
</div>
<div class="txio">
  <div class="card">
    <div class="card-h"><span><?= lx_icon('trending-up') ?>Block fee rates</span> <span class="sub">median · sat/vB</span></div>
    <div class="card-b"><?= lx_chart_bars($frBars, 'Median fee rate per block', ['yfmt' => function ($v, $step = 0.0) { return number_format($v, lx_step_dec($step, 1)) . ' s/vB'; }]) ?></div>
  </div>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('layers') ?>Block weight</span> <span class="sub"><?= h($blkSub) ?> · WU</span></div>
    <div class="card-b"><?= lx_chart_bars($wtBars, 'Block weight per block', ['yfmt' => function ($v) { return lx_size_str((int) $v, 'WU'); }]) ?></div>
  </div>
</div>
<div class="card">
  <div class="card-h"><span><?= lx_icon('gift') ?>Block reward composition</span> <span class="sub">subsidy + fees · <?= h($net['unit']) ?></span></div>
  <div class="card-b"><?= lx_chart_stacked($fsRows, 'height', ['subsidy', 'total_fee'], ['#3b82f6', '#f59e0b'], 'Block reward: subsidy and fees per block', [
      'yfmt'   => 'lx_coin_compact',
      'tips'   => $fsTips,
      'legend' => [['color' => '#3b82f6', 'label' => 'Subsidy'], ['color' => '#f59e0b', 'label' => 'Fees']],
  ]) ?></div>
</div>
<div class="txio">
  <div class="card">
    <div class="card-h"><span><?= lx_icon('activity') ?>Transactions per block</span> <span class="sub"><?= h($blkSub) ?></span></div>
    <div class="card-b"><?= lx_chart_bars($txBars, 'Transactions per block', ['yfmt' => 'lx_num_compact']) ?></div>
  </div>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('layers') ?>Block fullness</span> <span class="sub">weight utilization · %</span></div>
    <div class="card-b"><?= lx_chart_bars($fullBars, 'Block weight utilization (percent of the 4M WU limit)', ['yfmt' => function ($v, $step = 0.0) { return number_format($v, lx_step_dec($step, 0)) . '%'; }]) ?></div>
  </div>
</div>
<div class="card">
  <div class="card-h"><span><?= lx_icon('trending-up') ?>Fee-rate spread</span> <span class="sub"><?= $biSeries ? 'p10 &ndash; p90' : 'min / median / max' ?> · sat/vB · green low &rarr; red high</span></div>
  <div class="card-b"><?= lx_chart_stacked($spreadRows, 'x', $spreadKeys, $spreadColors, 'Fee-rate spread per block (percentile bands)', [
      'yfmt'   => function ($v, $step = 0.0) { return number_format($v, lx_step_dec($step, 1)) . ' s/vB'; },
      'xticks' => $blkXticks,
      'legend' => [['color' => $spreadColors[0], 'label' => $biSeries ? 'p10' : 'min'], ['color' => $spreadColors[2], 'label' => 'median'], ['color' => $spreadColors[4], 'label' => $biSeries ? 'p90' : 'max']],
      'tips'   => $spreadTips,
  ]) ?></div>
</div>
<div class="card">
  <div class="card-h"><span><?= lx_icon('trending-up') ?>Fees as share of block reward</span> <span class="sub">fee pressure · %</span></div>
  <div class="card-b"><?= lx_chart_bars($fpBars, 'Fees as a percentage of the block reward', ['yfmt' => function ($v, $step = 0.0) { return number_format($v, lx_step_dec($step, 1)) . '%'; }]) ?></div>
</div>
<div class="txio">
  <div class="card">
    <div class="card-h"><span><?= lx_icon('trending-up') ?>Value sent per block</span> <span class="sub"><?= h($blkSub) ?> · <?= h($net['unit']) ?></span></div>
    <div class="card-b"><?= lx_chart_bars($sentBars, 'Total value sent per block (excludes coinbase)', ['yfmt' => 'lx_coin_compact']) ?></div>
  </div>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('activity') ?>Average transaction value</span> <span class="sub">sent / tx · <?= h($net['unit']) ?></span></div>
    <div class="card-b"><?= lx_chart_bars($avgValBars, 'Average value per transaction', ['yfmt' => 'lx_coin_compact']) ?></div>
  </div>
</div>
<?php endif; ?>

<?php if (count($actSeries) >= 2):
    $actRows = []; $actLabels = []; $actTips = []; $cddTips = []; $txdTips = []; $newTips = []; $medTips = [];
    $hasMed = false; $hasNew = false;
    foreach ($actSeries as $a) {
        $d = (int) $a['day']; $lab = gmdate('Y-m-d', $d);
        $medSat = (float) ($a['medVal'] ?? 0);   // satoshis: lx_coin_compact (the yfmt) divides by 1e8 itself
        $newA   = (float) ($a['newAddr'] ?? 0);
        if ($medSat > 0) { $hasMed = true; }
        if ($newA > 0)   { $hasNew = true; }
        $actRows[]   = ['day' => $d, 'active' => $a['active'], 'cdd' => $a['cdd'], 'txd' => $a['txs'], 'med' => $medSat, 'new' => $newA];
        $actLabels[] = $lab;
        $actTips[] = lx_tip_json($lab, [['c' => '#6b86ff', 'k' => 'Active', 'v' => commas((int) round($a['active'])) . ' addrs']]);
        $cddTips[] = lx_tip_json($lab, [['c' => '#e07a3e', 'k' => 'Destroyed', 'v' => lx_num_compact($a['cdd']) . ' LTC-days']]);
        $txdTips[] = lx_tip_json($lab, [['c' => '#6b86ff', 'k' => 'Txs', 'v' => commas((int) round($a['txs']))]]);
        $newTips[] = lx_tip_json($lab, [['c' => '#009E73', 'k' => 'New', 'v' => commas((int) round($newA)) . ' addrs']]);
        $medTips[] = lx_tip_json($lab, [
            ['c' => '#6b86ff', 'k' => 'Median', 'v' => lx_coin_compact((float) ($a['medVal'] ?? 0)) . ' ' . $net['unit']],
            ['c' => '#8c8f99', 'k' => 'p10 / p90', 'v' => lx_coin_compact((float) ($a['p10Val'] ?? 0)) . ' / ' . lx_coin_compact((float) ($a['p90Val'] ?? 0))],
        ]);
    }
    $actXticks = [];
    $ds = array_column($actSeries, 'day'); $minD = min($ds); $spanD = max(1, max($ds) - $minD);
    for ($i = 0; $i <= 4; $i++) { $actXticks[] = ['f' => $i / 4, 'label' => gmdate('M Y', (int) ($minD + $spanD * $i / 4))]; }
?>
<div class="section-h" id="activity">Network activity <a class="btn ghost sm" style="float:right;font-weight:600" href="<?= h($base) ?>/charts/export?metric=activity<?= $period !== '' ? '&amp;period=' . h($period) : '' ?>" title="Download the activity series as CSV"><?= lx_icon('database') ?>CSV</a></div>
<p class="muted sub">Distinct addresses transacting, economic throughput and confirmed transactions per day, over the indexed history. Coin-days-destroyed weights each spend by how long the coins sat still, so it tracks real economic movement rather than exchange shuffling. Address counts are per-address, not per-entity, and MWEB (shielded) activity is excluded.</p>
<div class="card">
  <div class="card-h"><span><?= lx_icon('users') ?>Active addresses / day</span> <span class="sub">distinct senders + receivers</span></div>
  <div class="card-b"><?= lx_chart_area($actRows, 'day', 'active', 'Active addresses per day', $actLabels, [
      'yfmt' => 'lx_num_compact', 'xticks' => $actXticks, 'tips' => $actTips, 'log' => $logScale, 'ma' => 7, 'baseline' => 'zero',
  ]) ?></div>
</div>
<div class="card">
  <div class="card-h"><span><?= lx_icon('zap') ?>Coin-days destroyed / day</span> <span class="sub">LTC-days &middot; economic activity</span></div>
  <div class="card-b"><?= lx_chart_area($actRows, 'day', 'cdd', 'Coin-days destroyed per day', $actLabels, [
      'yfmt' => 'lx_num_compact', 'xticks' => $actXticks, 'tips' => $cddTips, 'log' => $logScale, 'ma' => 7,
  ]) ?></div>
</div>
<div class="card">
  <div class="card-h"><span><?= lx_icon('activity') ?>Transactions / day</span> <span class="sub">confirmed &middot; whole index</span></div>
  <div class="card-b"><?= lx_chart_area($actRows, 'day', 'txd', 'Transactions per day', $actLabels, [
      'yfmt' => 'lx_num_compact', 'xticks' => $actXticks, 'tips' => $txdTips, 'log' => $logScale, 'ma' => 7, 'baseline' => 'zero',
  ]) ?></div>
</div>
<?php if ($hasMed || $hasNew): ?>
<div class="txio">
  <?php if ($hasMed): ?>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('trending-up') ?>Median transaction value</span> <span class="sub">non-coinbase &middot; <?= h($net['unit']) ?></span></div>
    <div class="card-b"><?= lx_chart_area($actRows, 'day', 'med', 'Median transaction value per day', $actLabels, [
        'yfmt' => 'lx_coin_compact', 'xticks' => $actXticks, 'tips' => $medTips, 'log' => $logScale, 'ma' => 7,
    ]) ?></div>
  </div>
  <?php endif; ?>
  <?php if ($hasNew): ?>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('users') ?>New addresses / day</span> <span class="sub">first appearance on-chain</span></div>
    <div class="card-b"><?= lx_chart_area($actRows, 'day', 'new', 'New addresses per day', $actLabels, [
        'yfmt' => 'lx_num_compact', 'xticks' => $actXticks, 'tips' => $newTips, 'log' => $logScale, 'ma' => 7, 'baseline' => 'zero',
    ]) ?></div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php
$ageTot = 0; foreach ($ageBands as $bd) { $ageTot += (int) $bd['sat']; }
if ($ageTot > 0):
    $ageBars = []; $ageXticks = []; $bn = count($ageBands);
    foreach ($ageBands as $i => $bd) {
        $sat = (int) $bd['sat']; $pct = $sat / $ageTot * 100;
        $ageBars[]   = ['value' => $sat, 'title' => $bd['label'] . ' · ' . lx_coin_compact((float) $sat) . ' ' . $net['unit'] . ' · ' . number_format($pct, 1) . '% of supply · ' . commas((int) $bd['coins']) . ' UTXOs'];   // sat: yfmt lx_coin_compact divides by 1e8
        $ageXticks[] = ['f' => ($i + 0.5) / max(1, $bn), 'label' => (string) $bd['label']];
    }
    $asOf = (int) ($ageBands[0]['as_of'] ?? 0);
?>
<div class="section-h" id="hodl">Coin age <span class="sub" style="font-weight:400">HODL waves</span></div>
<p class="muted sub">Transparent supply grouped by how long each coin has sat unspent<?= $asOf > 0 ? ', as of ' . h(gmdate('M j, Y', $asOf)) : '' ?>. A large old-coin share signals strong holding; MWEB (shielded) coins are excluded. No other Litecoin explorer tracks this.</p>
<div class="card">
  <div class="card-h"><span><?= lx_icon('database') ?>Supply by coin age</span> <span class="sub"><?= h($net['unit']) ?> unspent &middot; by age band</span></div>
  <div class="card-b"><?= lx_chart_bars($ageBars, 'Transparent supply by coin age', ['yfmt' => 'lx_coin_compact', 'xticks' => $ageXticks]) ?></div>
</div>
<?php endif; ?>

<?php if (count($supplyRows) >= 2 || count($priceRows) >= 2): ?>
<div class="section-h">Supply &amp; price</div>
<div class="txio">
  <?php if (count($supplyRows) >= 2): ?>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('database') ?>Coin supply</span> <span class="sub">issued · <?= h($net['unit']) ?></span></div>
    <div class="card-b"><?= lx_chart_area($supplyRows, 'h', 'supply', 'Litecoin supply issued over time', $supplyLabels, [
        'yfmt' => 'lx_num_compact', 'xticks' => $supplyXticks, 'baseline' => 'zero', 'log' => $logScale,
    ]) ?></div>
  </div>
  <?php endif; ?>
  <?php if (count($priceRows) >= 2): ?>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('trending-up') ?>Price</span> <span class="sub"><?= h($pcur) ?> · history</span></div>
    <div class="card-b"><?= lx_chart_area($priceRows, 't', 'p', 'Litecoin price over time', $priceLabels, [
        'yfmt' => function ($v, $step = 0.0) { return number_format($v, $v < 1 ? 3 : 2); }, 'xticks' => $priceXticks,
        'log' => $logScale, 'ma' => 30,
    ]) ?></div>
  </div>
  <?php endif; ?>
</div>
<?php if (count($mcapRows) >= 2 || count($sentUsdRows) >= 2): ?>
<div class="txio">
  <?php if (count($mcapRows) >= 2): ?>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('trending-up') ?>Market cap</span> <span class="sub"><?= h($pcur) ?> &middot; supply &times; price</span></div>
    <div class="card-b"><?= lx_chart_area($mcapRows, 't', 'm', 'Litecoin market capitalisation over time', $mcapLabels, [
        'yfmt' => 'lx_num_compact', 'xticks' => $priceXticks, 'log' => $logScale, 'baseline' => 'zero',
    ]) ?></div>
  </div>
  <?php endif; ?>
  <?php if (count($sentUsdRows) >= 2): ?>
  <div class="card">
    <div class="card-h"><span><?= lx_icon('trending-up') ?>Value sent (<?= h($pcur) ?>)</span> <span class="sub"><?= $biSeries ? h($blkSub) : 'recent' ?> &middot; time-of-block price</span></div>
    <div class="card-b"><?= lx_chart_area($sentUsdRows, 't', 'u', 'Value sent per block in fiat', $sentUsdLabels, [
        'yfmt' => 'lx_num_compact', 'xticks' => $sentUsdXticks, 'log' => $logScale,
    ]) ?></div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php
$realized = function_exists('lx_realized') ? lx_realized($net) : null;
if ($realized):
    $pxUsd = null;
    if (function_exists('lx_price')) { $pp = lx_price($net); $pxUsd = isset($pp['prices']['usd']) ? (float) $pp['prices']['usd'] : null; }
    $mvrv = ($pxUsd !== null && $realized['price'] > 0) ? $pxUsd / $realized['price'] : null;
?>
<div class="section-h" id="valuation">Valuation <span class="sub" style="font-weight:400">realized cap &amp; MVRV</span></div>
<p class="muted sub">Realized cap values every coin at the price on the day it last moved (from the UTXO map + deep price history) rather than the spot price; realized price is that over supply, and MVRV = market price &divide; realized price (&gt; 1 = holders in aggregate profit). As of <?= h(gmdate('M j, Y', (int) $realized['at'])) ?>. Coins predating the price history are valued at the earliest available price. No other Litecoin explorer tracks this.</p>
<div class="stat-grid">
  <div class="stat"><div class="muted sub"><?= lx_icon('database') ?>Realized cap</div><div class="big-num sm">$<?= h(lx_num_compact((float) $realized['cap'])) ?></div><div class="muted sub">USD</div></div>
  <div class="stat"><div class="muted sub"><?= lx_icon('trending-up') ?>Realized price</div><div class="big-num sm">$<?= h(number_format((float) $realized['price'], 2)) ?></div><div class="muted sub">USD / <?= h($net['unit']) ?></div></div>
  <?php if ($mvrv !== null): ?>
  <div class="stat<?= $mvrv >= 1 ? ' hot' : '' ?>"><div class="muted sub"><?= lx_icon('activity') ?>MVRV</div><div class="big-num sm"><?= h(number_format($mvrv, 2)) ?></div><div class="muted sub">market &divide; realized</div></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php lx_foot($net); ?>
