<?php
/**
 * Litecoin halving countdown - a dedicated, shareable/SEO page for the "when is the next LTC
 * halving" query. Block-remaining is the exact, honest figure; the date is an estimate from the
 * 2.5-minute target spacing. UTXO lanes only. $net in scope.
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
$base   = lx_u($net);
$supply = lx_supply_info($net);
$tip    = lx_tip_height($net);

lx_head($net, [
    'title' => 'Litecoin halving countdown - ' . $net['label'] . ' Explorer',
    'desc'  => 'Live countdown to the next Litecoin halving: blocks remaining, estimated date, current and next block reward, and progress through the current reward era.',
    'og_image' => '/og/halving.png',
]);
?>
<h1>Halving countdown</h1>
<?php if (!$supply || $tip <= 0): ?>
<div class="card"><div class="card-b"><p class="muted">Halving data is unavailable right now (the node is unreachable). Please try again shortly.</p></div></div>
<?php else:
    $toH   = max(0, (int) $supply['blocks_to_halving']);
    $eta   = (int) $supply['halving_eta'];
    $secs  = max(0, $eta - time());
    $days  = intdiv($secs, 86400);
    $yrs   = intdiv($days, 365); $remD = $days % 365;
    $dur   = ($yrs > 0 ? $yrs . 'y ' : '') . $remD . 'd';
    $prog  = min(100, max(0, (float) $supply['halving_progress']));
    $era   = (int) $supply['era'];
    // Ordinal for the upcoming halving (era 0 now -> the 1st halving is next, etc.)
    $nth   = $era + 1;
    $ord   = ($nth % 10 === 1 && $nth % 100 !== 11) ? 'st' : (($nth % 10 === 2 && $nth % 100 !== 12) ? 'nd' : (($nth % 10 === 3 && $nth % 100 !== 13) ? 'rd' : 'th'));
?>
<p class="muted sub">Litecoin&rsquo;s block reward halves every 840,000 blocks (about every four years). This is the <?= $nth . $ord ?> halving.</p>

<div class="card">
  <div class="card-b" style="text-align:center;padding:var(--s6) var(--s4)">
    <div class="hero-eyebrow">Blocks until the next halving</div>
    <div class="big-num" style="font-size:clamp(2.4rem,9vw,4.5rem);line-height:1.05;font-variant-numeric:tabular-nums"><?= commas($toH) ?></div>
    <p class="muted" style="font-size:1.05rem;margin-top:var(--s2)">&asymp; <?= h($dur) ?> &middot; estimated <strong><?= h(gmdate('M j, Y', $eta)) ?></strong></p>
    <div class="progress mt-3" role="img" aria-label="Progress through the current reward era: <?= h(number_format($prog, 1)) ?>%"><span style="width:<?= h(number_format($prog, 2)) ?>%"></span></div>
    <p class="muted sub mt-2"><?= h(number_format($prog, 1)) ?>% through reward era <?= $era ?></p>
  </div>
</div>

<div class="stat-grid mt-3">
  <div class="stat"><div class="muted sub"><?= lx_icon('gift') ?>Current reward</div><div class="big-num sm"><?= lx_amount_el($net, (int) $supply['reward_sat'], false) ?> <span class="muted sub denom-unit"><?= h($net['unit']) ?></span></div><div class="muted sub">per block</div></div>
  <div class="stat hot"><div class="muted sub"><?= lx_icon('trending-up') ?>Reward after halving</div><div class="big-num sm"><?= lx_amount_el($net, (int) $supply['next_reward_sat'], false) ?> <span class="muted sub denom-unit"><?= h($net['unit']) ?></span></div><div class="muted sub">per block</div></div>
  <div class="stat"><div class="muted sub"><?= lx_icon('layers') ?>Halving block</div><div class="big-num sm"><?= commas((int) $supply['next_halving']) ?></div><div class="muted sub">current tip <?= commas($tip) ?></div></div>
  <div class="stat"><div class="muted sub"><?= lx_icon('database') ?>Mined supply</div><div class="big-num sm"><?= commas(intdiv((int) $supply['mined_sat'], 100000000)) ?></div><div class="muted sub"><?= h(number_format($supply['pct_mined'], 2)) ?>% of <?= commas(intdiv((int) $supply['max_supply_sat'], 100000000)) ?></div></div>
</div>

<div class="card mt-3">
  <div class="card-h"><span><?= lx_icon('info') ?>How the halving works</span></div>
  <div class="card-b">
    <p class="muted">Every 840,000 blocks the coinbase subsidy that miners earn for each new block is cut in half. It started at 50 LTC, and continues halving until the last fraction is issued and the ~84,000,000 LTC cap is reached. The date above is an <em>estimate</em> - it assumes Litecoin&rsquo;s 2.5-minute target block time, so it drifts slightly as real block times vary; the block height is exact.</p>
    <p class="muted sub">See the full issuance curve on the <a href="<?= h($base) ?>/charts#supply">supply chart</a>.</p>
  </div>
</div>
<?php endif; ?>
<?php lx_foot($net); ?>
