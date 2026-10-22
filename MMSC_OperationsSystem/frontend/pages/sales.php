<?php
$date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : today();
$sales = SalesService::sales($date);
if(has_role('Sales')) $sales=array_values(array_filter($sales,static fn($s)=>(int)$s['recorded_by']===uid()));
layout_start('Sales', 'Every sale is linked to an OR number, a drawer, a person, and a time.',
             has_role('Sales','Executive Officer') ? '<a class="btn btn-primary" href="' . e(url('pos')) . '">New counter sale</a>' : '');
?>
<form class="filters filters-compact" method="get">
  <input type="hidden" name="page" value="sales">
  <div><label for="date">Business day</label><input id="date" type="date" name="date" value="<?= e($date) ?>"></div>
  <button class="btn btn-secondary" type="submit">Show</button>
</form>
<div class="panel" style="padding:0">
  <div class="table-wrap"><table>
    <thead><tr><th>Reference</th><th>OR</th><th>Type</th><th>Customer</th><th>Recorded by</th><th class="num">Total</th><th class="num">Collected</th><th>Status</th></tr></thead>
    <tbody>
    <?php if (!$sales): ?><tr><td colspan="8"><div class="empty">No sales on <?= e(fmt_d($date)) ?>.</div></td></tr><?php endif; ?>
    <?php foreach ($sales as $s): ?>
      <tr><td><a class="code" href="<?= e(url('sale', ['id' => $s['sale_id']])) ?>"><?= e($s['sale_ref_no']) ?></a><span class="sub"><?= e(date('g:i A', strtotime($s['recorded_at']))) ?></span></td>
          <td><?= e($s['or_number']) ?></td><td><?= e($s['sale_type']) ?></td><td><?= e($s['customer_name']) ?></td>
          <td><?= e($s['recorded_name']) ?><span class="sub">Drawer <?= e($s['drawer_no']) ?></span></td>
          <td class="num"><?= e(money($s['gross_amount'] - $s['discount_amount'])) ?></td><td class="num"><?= e(money($s['amount_due'])) ?></td><td><?= status_badge($s['status']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php layout_end();
