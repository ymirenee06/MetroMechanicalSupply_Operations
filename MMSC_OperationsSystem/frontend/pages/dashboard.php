<?php
$u = current_user();
if ($u['role'] === 'Technician') redirect('my_items');

$c = AdminService::counts();
$low = q_all("SELECT item_id, item_code, name, qty_available, reorder_point, unit_of_measure, stock_status FROM v_item_stock
              WHERE stock_status <> 'Normal' AND is_archived = 0 ORDER BY qty_available / GREATEST(reorder_point, 1) LIMIT 8");
$overdue = q_all("SELECT * FROM v_tool_holdings WHERE is_overdue = 1 ORDER BY expected_return_date LIMIT 8");

function tile(string $page, $n, string $label, bool $hot = false): string
{
    return '<a class="' . ($hot && $n ? 'hot' : '') . '" href="' . e(url($page)) . '"><span class="n">' . e($n) . '</span><span class="l">' . e($label) . '</span></a>';
}

layout_start('Good ' . (date('G') < 12 ? 'morning' : (date('G') < 18 ? 'afternoon' : 'evening')) . ', ' . $u['full_name'],
             e(date('l, F j, Y')));

if ($u['role'] === 'Sales'):
    $s = SalesService::mySession(uid());
    $mySalesToday = q_one("SELECT COUNT(*) AS tx_count, COALESCE(SUM(s.gross_amount-s.discount_amount),0) AS net_amount FROM sales s JOIN drawer_sessions ds ON ds.session_id=s.session_id JOIN sales_days sd ON sd.sales_day_id=ds.sales_day_id WHERE sd.business_date=? AND s.recorded_by=? AND s.status<>'Voided'", [today(), uid()]); ?>
  <div class="hero-strip">
    <div>
      <h2><?= $s ? 'Drawer ' . e($s['drawer_no']) . ' is open' : 'Your drawer is not open' ?></h2>
      <p><?= $s ? 'Opened ' . e(fmt_dt($s['opened_at'])) . ' with a ' . e(money($s['opening_float'])) . ' float.' : 'Open a drawer to start recording sales.' ?></p>
    </div>
    <?php if ($s): $cash = SalesService::sessionCash((int)$s['session_id']); ?>
      <div><span class="big"><?= e(money($cash['expected_cash'])) ?></span><p>Cash that should be in the drawer</p></div>
      <a class="btn btn-primary" href="<?= e(url('pos')) ?>">New counter sale</a>
    <?php else: ?>
      <a class="btn btn-primary" href="<?= e(url('drawer')) ?>">Open drawer</a>
    <?php endif; ?>
  </div>
  <div class="strip">
    <?= tile('sales', (int)$mySalesToday['tx_count'], 'Your transactions today') ?>
    <?= tile('sales', money($mySalesToday['net_amount']), 'Your sales subtotal today') ?>
    <?= tile('billing', count(SalesService::billableJobs()), 'Jobs ready to bill', true) ?>
    <?= tile('items', $c['low_stock'], 'Items low or out', true) ?>
  </div>
<?php elseif ($u['role'] === 'Logistics'): ?>
  <div class="strip">
    <?= tile('returns', $c['leftovers'], 'Leftovers to receive', true) ?>
    <?= tile('returns', qty($c['tools_out']), 'Tools out now') ?>
    <?= tile('returns', $c['overdue'], 'Tools overdue', true) ?>
    <?= tile('jobs', $c['unconfirmed'], 'Releases awaiting confirmation', true) ?>
    <?= tile('items', $c['low_stock'], 'Items low or out', true) ?>
  </div>
<?php elseif ($u['role'] === 'Purchasing Officer'): ?>
  <div class="strip">
    <?= tile('stock_in', $c['pending_stock_in'], 'Deliveries waiting for verification', true) ?>
    <?= tile('items', $c['low_stock'], 'Items low or out', true) ?>
  </div>
<?php elseif ($u['role'] === 'Accounting'): ?>
  <div class="strip">
    <?= tile('sales', $c['tx_today'], 'Transactions today') ?>
    <?= tile('daily', money($c['net_today']), 'Net sales today') ?>
    <?= tile('jobs', $c['open_jobs'], 'Open jobs') ?>
    <?= tile('items', $c['low_stock'], 'Items low or out', true) ?>
  </div>
<?php else: ?>
  <div class="strip">
    <?= tile('stock_in', $c['pending_stock_in'], 'Deliveries to verify', true) ?>
    <?= tile('items', $c['low_stock'], 'Items low or out', true) ?>
    <?= tile('incidents', $c['incidents'], 'Open incident reports', true) ?>
    <?= tile('jobs', $c['open_jobs'], 'Open jobs') ?>
    <?= tile('daily', money($c['net_today']), 'Net sales today') ?>
  </div>
<?php endif; ?>

<div class="grid-2">
  <section class="panel">
    <div class="panel-head"><h2>Low and out of stock</h2><a href="<?= e(url('items', in_array($u['role'],['Executive Officer','Purchasing Officer','Logistics','Accounting'],true) ? ['filter'=>'low'] : [])) ?>"><?= $u['role']==='Sales' ? 'Browse items' : 'See all' ?></a></div>
    <?php if (!$low): ?><div class="empty">Every item is above its low-stock level.</div><?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Item</th><th class="num">Available</th><?php if(in_array($u['role'],['Executive Officer','Purchasing Officer','Logistics','Accounting'],true)): ?><th class="num">Low at</th><?php endif; ?><th>Status</th></tr></thead>
      <tbody><?php foreach ($low as $r): ?>
        <tr><td><a href="<?= e(url('item', ['id' => $r['item_id']])) ?>"><?= e($r['name']) ?></a><span class="sub code"><?= e($r['item_code']) ?></span></td>
            <td class="num"><?= e(qty($r['qty_available'])) ?> <?= e($r['unit_of_measure']) ?></td>
            <?php if(in_array($u['role'],['Executive Officer','Purchasing Officer','Logistics','Accounting'],true)): ?><td class="num"><?= e(qty($r['reorder_point'])) ?></td><?php endif; ?><td><?= status_badge($r['stock_status']) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
  </section>
  <?php if(in_array($u['role'],['Executive Officer','Logistics'],true)): ?><section class="panel">
    <div class="panel-head"><h2>Overdue tools</h2><?php if ($u['role'] === 'Logistics'): ?><a href="<?= e(url('returns')) ?>">Returns</a><?php endif; ?></div>
    <?php if (!$overdue): ?><div class="empty">No tools are past their return date.</div><?php else: ?>
    <div class="table-wrap"><table>
      <thead><tr><th>Tool</th><th>Held by</th><th>Job</th><th>Due</th></tr></thead>
      <tbody><?php foreach ($overdue as $r): ?>
        <tr><td><?= e($r['item_name']) ?></td><td><?= e($r['technician']) ?></td><td class="nowrap"><?= e($r['job_ref_no']) ?></td><td class="nowrap"><?= status_badge('Overdue') ?> <span class="sub"><?= e(fmt_d($r['expected_return_date'])) ?></span></td></tr>
      <?php endforeach; ?></tbody>
    </table></div><?php endif; ?>
  </section><?php endif; ?>
</div>
<?php layout_end();
