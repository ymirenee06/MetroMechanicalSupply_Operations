<?php
$group = array_key_exists($_GET['group'] ?? '', SummaryService::GROUPS) ? $_GET['group'] : 'monthly';
[$defFrom, $defTo] = SummaryService::defaultRange($group);
$isDate = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
$from = $isDate($_GET['from'] ?? null) && !isset($_GET['regroup']) ? $_GET['from'] : $defFrom;
$to = $isDate($_GET['to'] ?? null) && !isset($_GET['regroup']) ? $_GET['to'] : $defTo;
if ($from > $to) [$from, $to] = [$to, $from];

$trend = SummaryService::trend($group, $from, $to);
$tot = SummaryService::totals($from, $to);
$topQty = SummaryService::topItems($from, $to, 'qty', 10);
$topRev = SummaryService::topItems($from, $to, 'revenue', 10);
$mix = SummaryService::paymentMix($from, $to);
$change = SummaryService::changeGiven($from, $to);
$customers = SummaryService::topCustomers($from, $to);
$weekday = SummaryService::byWeekday($from, $to);

$exportUrl = url('export', ['type' => 'summary', 'group' => $group, 'from' => $from, 'to' => $to]);
layout_start('Sales summary', 'Sales from ' . e(fmt_d($from)) . ' to ' . e(fmt_d($to)) . '. Voided sales are excluded; amounts include VAT.',
             '<a class="btn btn-primary" href="' . e($exportUrl) . '">Export to Excel</a>');
$share = fn($v, $max) => '<span class="share"><span style="width:' . ($max > 0 ? round(100 * (float)$v / $max, 1) : 0) . '%"></span></span>';
$pct = fn($v, $tot) => $tot > 0 ? round(100 * $v / $tot) . '%' : '—';
$maxQty = max(array_map(fn($r) => (float)$r['units'], $topQty) ?: [0]);
$maxRev = max(array_map(fn($r) => (float)$r['revenue'], $topRev) ?: [0]);
$mixTotal = array_sum(array_map(fn($r) => (float)$r['gross_received'], $mix));
$maxDay = max(array_map(fn($r) => (float)$r['net_sales'], $weekday) ?: [0]);
$maxCust = max(array_map(fn($r) => (float)$r['net_sales'], $customers) ?: [0]);
?>
<form class="filters summary-filters" method="get">
  <input type="hidden" name="page" value="sales_summary">
  <div><label for="group">Group by</label><select id="group" name="group" onchange="this.form.insertAdjacentHTML('beforeend','<input type=hidden name=regroup value=1>');this.form.submit()">
    <?php foreach (SummaryService::GROUPS as $k => $v): ?><option value="<?= e($k) ?>"<?= $group === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
  <div><label for="from">From</label><input id="from" type="date" name="from" value="<?= e($from) ?>"></div>
  <div><label for="to">To</label><input id="to" type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn-secondary" type="submit">Show summary</button>
</form>

<div class="kpis">
  <div class="kpi accent"><div class="l">Net sales (with VAT)</div><div class="n"><?= e(money($tot['net_sales'])) ?></div><div class="d">VAT <?= e(money($tot['vat'])) ?> · discounts <?= e(money($tot['discounts'])) ?></div></div>
  <div class="kpi"><div class="l">Transactions</div><div class="n"><?= e(number_format($tot['transactions'])) ?></div><div class="d">Average <?= e(money($tot['avg_ticket'])) ?></div></div>
  <div class="kpi"><div class="l">Counter sales</div><div class="n"><?= e(money($tot['counter_total'])) ?></div><div class="d"><?= e($pct($tot['counter_total'], $tot['net_sales'])) ?> of net sales</div></div>
  <div class="kpi"><div class="l">Job billing</div><div class="n"><?= e(money($tot['job_total'])) ?></div><div class="d"><?= e($pct($tot['job_total'], $tot['net_sales'])) ?> of net sales</div></div>
  <div class="kpi"><div class="l">Units sold / billed</div><div class="n"><?= e(qty($tot['units'])) ?></div><div class="d">Refunds <?= e(money($tot['refunds'])) ?></div></div>
</div>

<section class="panel">
  <div class="panel-head"><h2><?= e(SummaryService::GROUPS[$group]) ?> sales</h2><span class="muted small">Hover a bar to see its total</span></div>
  <?= SummaryService::trendChart($trend) ?>
  <details class="numbers"><summary>Show the numbers per period</summary>
  <div class="table-wrap"><table>
    <thead><tr><th>Period</th><th class="num">Transactions</th><th class="num">Counter sales</th><th class="num">Job billing</th><th class="num">Discounts</th><th class="num">VAT</th><th class="num">Net sales</th></tr></thead>
    <tbody><?php foreach (array_reverse($trend) as $t): ?>
      <tr><td><?= e($t['period']) ?></td><td class="num"><?= e($t['transactions']) ?></td><td class="num"><?= e(money($t['counter_total'])) ?></td><td class="num"><?= e(money($t['job_total'])) ?></td>
          <td class="num"><?= e(money($t['discounts'])) ?></td><td class="num"><?= e(money($t['vat'])) ?></td><td class="num"><strong><?= e(money($t['net_sales'])) ?></strong></td></tr>
    <?php endforeach; ?></tbody>
  </table></div></details>
</section>

<div class="grid-2">
  <section class="panel" style="padding:0"><div class="panel-head pad"><h2>Most bought</h2><span class="muted small">By quantity</span></div>
    <?php if (!$topQty): ?><div class="empty" style="margin:0 20px 20px">No items sold in this period.</div><?php else: ?>
    <table class="rank"><thead><tr><th>#</th><th>Item</th><th class="num">Qty</th><th class="share-col"></th></tr></thead><tbody>
    <?php foreach ($topQty as $n => $r): ?><tr><td class="muted"><?= $n + 1 ?></td><td><?= e($r['name']) ?><span class="sub"><?= e($r['transactions']) ?> sales</span></td><td class="num"><strong><?= e(qty($r['units'])) ?></strong> <span class="muted small"><?= e($r['unit_of_measure']) ?></span></td><td class="share-col"><?= $share($r['units'], $maxQty) ?></td></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?></section>
  <section class="panel" style="padding:0"><div class="panel-head pad"><h2>Top earners</h2><span class="muted small">By amount, before VAT</span></div>
    <?php if (!$topRev): ?><div class="empty" style="margin:0 20px 20px">No items sold in this period.</div><?php else: ?>
    <table class="rank"><thead><tr><th>#</th><th>Item</th><th class="num">Amount</th><th class="share-col"></th></tr></thead><tbody>
    <?php foreach ($topRev as $n => $r): ?><tr><td class="muted"><?= $n + 1 ?></td><td><?= e($r['name']) ?><span class="sub"><?= e(qty($r['units'])) ?> <?= e($r['unit_of_measure']) ?></span></td><td class="num"><strong><?= e(money($r['revenue'])) ?></strong></td><td class="share-col"><?= $share($r['revenue'], $maxRev) ?></td></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?></section>
</div>

<div class="grid-2">
  <section class="panel" style="padding:0"><div class="panel-head pad"><h2>Payment methods</h2><span class="muted small"><?= e(money($change)) ?> given back as change</span></div>
    <table class="rank"><thead><tr><th>Method</th><th class="num">Payments</th><th class="num">Received</th><th class="num">Share</th></tr></thead><tbody>
    <?php foreach ($mix as $r): ?><tr><td><?= e($r['method']) ?></td><td class="num"><?= e($r['payments']) ?></td><td class="num"><strong><?= e(money($r['gross_received'])) ?></strong></td><td class="num"><?= e($pct($r['gross_received'], $mixTotal)) ?></td></tr><?php endforeach; ?>
    <?php if (!$mix): ?><tr><td colspan="4" class="muted">No payments in this period.</td></tr><?php endif; ?>
    </tbody></table></section>
  <section class="panel" style="padding:0"><div class="panel-head pad"><h2>Busiest days of the week</h2></div>
    <table class="rank"><thead><tr><th>Day</th><th class="num">Sales</th><th class="num">Net sales</th><th class="share-col"></th></tr></thead><tbody>
    <?php foreach ($weekday as $r): ?><tr><td><?= e($r['day']) ?></td><td class="num"><?= e($r['transactions']) ?></td><td class="num"><?= e(money($r['net_sales'])) ?></td><td class="share-col"><?= $share($r['net_sales'], $maxDay) ?></td></tr><?php endforeach; ?>
    </tbody></table></section>
</div>

<section class="panel" style="padding:0"><div class="panel-head pad"><h2>Top customers</h2><span class="muted small">Walk-in sales not included</span></div>
  <?php if (!$customers): ?><div class="empty" style="margin:0 20px 20px">No named customers in this period.</div><?php else: ?>
  <table class="rank"><thead><tr><th>#</th><th>Customer</th><th class="num">Transactions</th><th class="num">Net sales</th><th class="share-col"></th></tr></thead><tbody>
  <?php foreach ($customers as $n => $r): ?><tr><td class="muted"><?= $n + 1 ?></td><td><?= e($r['customer_name']) ?></td><td class="num"><?= e($r['transactions']) ?></td><td class="num"><strong><?= e(money($r['net_sales'])) ?></strong></td><td class="share-col"><?= $share($r['net_sales'], $maxCust) ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?></section>
<?php layout_end();
