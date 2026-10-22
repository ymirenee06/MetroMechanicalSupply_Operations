<?php
$error = null;
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : today();
if (is_post() && has_role('Executive Officer', 'Accounting')) {
    try {
        SalesService::closeDay((int)post('sales_day_id'), uid());
        flash('ok', 'Day closed. Its totals are now final.');
        redirect('daily', ['date' => $date]);
    } catch (Throwable $e) { $error = friendly_error($e); }
}
$sum = SalesService::summary($date);
$sessions = SalesService::daySessions($date);
$day = q_one("SELECT * FROM sales_days WHERE business_date = ?", [$date]);

layout_start('Daily summary', e(date('l, F j, Y', strtotime($date))) . ($day ? ' · ' . status_badge($day['status']) : ''));
echo error_box($error);
?>
<form class="filters filters-compact" method="get">
  <input type="hidden" name="page" value="daily">
  <div><label for="date">Business day</label><input id="date" type="date" name="date" value="<?= e($date) ?>"></div>
  <button class="btn btn-secondary" type="submit">Show</button>
</form>
<?php if (!$sum): ?>
  <div class="panel"><div class="empty">No sales activity on this day.</div></div>
<?php else: ?>
<div class="hero-strip">
  <div><h2>Net sales</h2><p><?= e($sum['transactions']) ?> transaction<?= $sum['transactions'] == 1 ? '' : 's' ?>, both drawers combined</p></div>
  <div class="big"><?= e(money($sum['net_sales'])) ?></div>
</div>
<div class="grid-2">
  <section class="panel">
    <h2>Sales</h2>
    <div class="totals" style="border-top:0;margin-top:0">
      <div><span>Item sales</span><span><?= e(money($sum['item_sales'])) ?></span></div>
      <div><span>Job materials</span><span><?= e(money($sum['job_materials'])) ?></span></div>
      <div><span>Service and other fees</span><span><?= e(money($sum['service_and_other_fees'])) ?></span></div>
      <div><strong>Gross sales</strong><strong><?= e(money($sum['gross_sales'])) ?></strong></div>
      <div><span>VAT collected</span><span>+<?= e(money($sum['vat_collected'])) ?></span></div>
      <div><span>Discounts</span><span>−<?= e(money($sum['discounts'])) ?></span></div>
      <div><span>Refunds from returns</span><span>−<?= e(money($sum['sales_refunds'])) ?></span></div>
      <div class="due"><span>Net sales</span><span><?= e(money($sum['net_sales'])) ?></span></div>
    </div>
  </section>
  <section class="panel">
    <h2>Collections</h2>
    <div class="totals" style="border-top:0;margin-top:0">
      <div><span>Net sales</span><span><?= e(money($sum['net_sales'])) ?></span></div>
      <div><span>Less downpayments applied to bills</span><span>−<?= e(money($sum['downpayments_applied'])) ?></span></div>
      <div><span>Plus downpayments received</span><span>+<?= e(money($sum['downpayments_received'])) ?></span></div>
      <div><span>Less downpayment refunds</span><span>−<?= e(money($sum['downpayment_refunds'])) ?></span></div>
      <div class="due"><span>Total collected</span><span><?= e(money($sum['total_collections'])) ?></span></div>
    </div>
    <p class="muted small">Downpayment refunds do not reduce net sales, because a downpayment is not a sale until the job is billed.</p>
  </section>
</div>
<?php endif; ?>

<section class="panel" style="padding:0">
  <div class="panel-head" style="padding:16px 20px 0"><h2>Drawers</h2></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Drawer</th><th>Staff</th><th class="num">Float</th><th class="num">Expected</th><th class="num">Counted</th><th class="num">Difference</th><th>Status</th></tr></thead>
    <tbody>
    <?php if (!$sessions): ?><tr><td colspan="7"><div class="empty">No drawers were opened.</div></td></tr><?php endif; ?>
    <?php foreach ($sessions as $x): ?>
      <tr><td>Drawer <?= e($x['drawer_no']) ?></td><td><?= e($x['full_name']) ?></td><td class="num"><?= e(money($x['opening_float'])) ?></td>
          <td class="num"><?= e(money($x['expected_cash'])) ?></td><td class="num"><?= $x['counted_cash'] !== null ? e(money($x['counted_cash'])) : '—' ?></td>
          <td class="num"><?php if ($x['difference'] !== null): ?><span style="color:<?= $x['difference'] < 0 ? 'var(--bad)' : ($x['difference'] > 0 ? 'var(--warn)' : 'var(--ok)') ?>"><?= e(money($x['difference'])) ?></span><?php else: ?>—<?php endif; ?>
            <?= $x['difference_explanation'] ? '<span class="sub">' . e($x['difference_explanation']) . '</span>' : '' ?></td>
          <td><?= $x['closed_at'] ? status_badge('Closed') : status_badge('Open') ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<?php if ($day && $day['status'] === 'Open' && has_role('Executive Officer', 'Accounting')): ?>
<form method="post" class="panel" data-confirm="Close this business day? Its totals become final.">
  <?= csrf_field() ?><input type="hidden" name="sales_day_id" value="<?= e($day['sales_day_id']) ?>">
  <h2>Close the day</h2><p class="muted">Every drawer must be counted and closed first.</p>
  <button class="btn btn-primary" type="submit">Close day</button>
</form>
<?php endif; ?>
<?php layout_end();
