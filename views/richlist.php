<?php
/**
 * Rich list + wealth distribution, served entirely from warm rows written by the offline
 * UTXO-set aggregation (tools/richlist.php). Never computes on request. $net in scope (UTXO).
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
$base = lx_u($net);
$sum  = lx_wealth_summary($net);
$top  = $sum ? lx_richlist_top($net, 100) : [];
$total = $sum ? max(1, (int) $sum['total_sat']) : 1;
$pct = function (int $sat) use ($total) { return $sat / $total * 100.0; };

lx_head($net, [
    'title' => 'Rich list - ' . $net['label'] . ' Explorer',
    'desc'  => 'Largest ' . $net['label'] . ' holders by transparent on-chain balance, with wealth-distribution stats (Gini, share held by the top addresses). Snapshot from the full UTXO set.',
    'og_image' => '/og/richlist.png',
]);
?>
<h1>Rich list</h1>
<p class="muted sub">Largest <?= h($net['label']) ?> holders by transparent on-chain balance, and how concentrated the coin supply is across addresses.</p>

<?php if (!$sum): ?>
<div class="card"><div class="card-b"><div class="empty">
  <?= lx_icon('award') ?>
  <span>The rich list has not been built yet. It is generated periodically from a full UTXO-set snapshot (<span class="mono">tools/richlist.php</span>); once the first run completes it appears here.</span>
</div></div></div>
<?php else: ?>

<div class="stat-grid">
  <div class="stat">
    <div class="muted sub"><?= lx_icon('database') ?>Transparent supply</div>
    <div class="big-num sm"><?= h(lx_coin_compact((float) $sum['total_sat'])) ?></div>
    <div class="muted sub"><?= h($net['unit']) ?> across <?= commas((int) $sum['addr_count']) ?> addresses</div>
  </div>
  <div class="stat">
    <div class="muted sub"><?= lx_icon('award') ?>Top 100 hold</div>
    <div class="big-num sm"><?= h(number_format($pct((int) $sum['top100_sat']), 1)) ?>%</div>
    <div class="muted sub">top 10: <?= h(number_format($pct((int) $sum['top10_sat']), 1)) ?>% · top 1k: <?= h(number_format($pct((int) $sum['top1000_sat']), 1)) ?>%</div>
  </div>
  <div class="stat">
    <div class="muted sub"><?= lx_icon('trending-up') ?>Gini coefficient</div>
    <div class="big-num sm"><?= h(number_format((float) $sum['gini'], 3)) ?></div>
    <div class="muted sub">0 = equal · 1 = concentrated</div>
  </div>
  <div class="stat">
    <div class="muted sub"><?= lx_icon('clock') ?>Snapshot</div>
    <div class="big-num sm" style="font-size:1.2rem"><?= h(time_ago((int) $sum['updated'])) ?></div>
    <div class="muted sub">at block <?= commas((int) $sum['tip_height']) ?></div>
  </div>
</div>

<?php if (!empty($sum['bands'])): ?>
<div class="card">
  <div class="card-h"><span><?= lx_icon('layers') ?>Wealth distribution</span> <span class="sub">addresses by balance band · <?= h($net['unit']) ?></span></div>
  <div class="card-b nopad table-wrap">
    <table>
      <thead><tr><th>Balance band</th><th class="amt">Addresses</th><th class="amt">Coins held</th><th class="amt">% of supply</th></tr></thead>
      <tbody>
      <?php foreach ($sum['bands'] as $b): $bsat = (int) ($b['sat'] ?? 0); ?>
        <tr>
          <td class="mono"><?= h($b['label']) ?></td>
          <td class="amt"><?= commas((int) ($b['count'] ?? 0)) ?></td>
          <td class="amt"><?= lx_amount_el($net, $bsat, false) ?></td>
          <td class="amt"><?= h(number_format($pct($bsat), $pct($bsat) < 0.1 ? 3 : 1)) ?>%</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-h"><span><?= lx_icon('award') ?>Top holders</span> <span class="sub">top <?= commas(count($top)) ?> addresses</span></div>
  <div class="card-b nopad table-wrap">
    <table>
      <thead><tr><th>#</th><th>Address</th><th>Type</th><th class="amt">Balance</th><th class="amt">% of supply</th></tr></thead>
      <tbody>
      <?php foreach ($top as $r): $sat = (int) $r['sat']; ?>
        <tr>
          <td class="mono muted"><?= commas((int) $r['rank']) ?></td>
          <td class="mono break"><?php if (($r['address'] ?? '') !== ''): ?><?= lx_addr_cell($net, $r['address'], '', true) ?><?= lx_address_label_badge($r['address']) ?><?php else: ?><span class="muted">non-standard script</span><?php endif; ?></td>
          <td><?php if (($r['kind'] ?? '') !== ''): ?><span class="badge soft"><?= h($r['kind']) ?></span><?php endif; ?></td>
          <td class="amt"><?= lx_amount_el($net, $sat, false) ?> <span class="muted sub denom-unit"><?= h($net['unit']) ?></span></td>
          <td class="amt"><?= h(number_format($pct($sat), $pct($sat) < 0.01 ? 4 : 2)) ?>%</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="pnote">
  <?= lx_icon('info') ?>
  <span>Balances are per-<strong>address</strong>, not per owner: exchanges and custodians spread holdings across many addresses, so real-world concentration is lower than the ranks and Gini suggest. <strong>MWEB</strong> outputs are confidential (no address, hidden amount) and are excluded from this transparent-set view. Figures are a point-in-time snapshot of the UTXO set, refreshed periodically.</span>
</div>

<?php endif; ?>
<?php lx_foot($net); ?>
