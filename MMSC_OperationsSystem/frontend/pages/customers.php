<?php
$error = null;
if (is_post()) {
    try {
        if (post('action') === 'add') {
            CustomerService::create(post('name'), post('contact') ?: null, post('address') ?: null, post('customer_type', 'Business'));
            flash('ok', 'Customer saved. They now appear in the counter-sale and job dropdowns.');
        } elseif (post('action') === 'edit') {
            CustomerService::update((int)post('customer_id'), $_POST);
            flash('ok', 'Customer updated.');
        } elseif (post('action') === 'deactivate' || post('action') === 'activate') {
            $on = post('action') === 'activate';
            CustomerService::setActive((int)post('customer_id'), $on);
            flash('ok', $on ? 'Customer reactivated. They appear in the dropdowns again.' : 'Customer deactivated. They no longer appear in the counter-sale and job dropdowns; their past sales and jobs are kept.');
        } elseif (post('action') === 'delete') {
            CustomerService::delete((int)post('customer_id'));
            flash('ok', 'Customer deleted.');
        }
        redirect('customers');
    } catch (Throwable $e) { $error = friendly_error($e); }
}
$q = trim($_GET['q'] ?? '');
$all = CustomerService::all();
if ($q !== '') $all = array_values(array_filter($all, fn($c) => stripos($c['name'] . ' ' . $c['contact'], $q) !== false));
$editId = (int)($_GET['edit'] ?? 0);
layout_start('Customers', 'Correct, deactivate, or delete saved customers. A customer with no sales or jobs can be deleted; anyone with history is deactivated instead, so their records are kept.');
echo error_box($error);
?>
<div class="split">
  <section class="panel" style="padding:0">
    <form class="filters" method="get" style="padding:16px 20px 0"><input type="hidden" name="page" value="customers">
      <div><label for="q">Search</label><input id="q" type="search" name="q" value="<?= e($q) ?>" placeholder="Name or contact"></div>
      <button class="btn btn-secondary" type="submit">Search</button></form>
    <div class="table-wrap"><table>
      <thead><tr><th>Customer</th><th>Contact</th><th class="num">Sales</th><th class="num">Jobs</th><th></th></tr></thead>
      <tbody>
      <?php if (!$all): ?><tr><td colspan="5"><div class="empty">No customers match.</div></td></tr><?php endif; ?>
      <?php foreach ($all as $c): ?>
        <?php if ($editId === (int)$c['customer_id']): ?>
        <tr><td colspan="5"><form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="customer_id" value="<?= e($c['customer_id']) ?>">
          <div><label>Name</label><input name="name" type="text" value="<?= e($c['name']) ?>" required></div>
          <div><label>Type</label><select name="customer_type"><?php foreach (['Walk-in','Contractor','Business','Residential'] as $t): ?><option<?= $c['customer_type'] === $t ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
          <div><label>Contact</label><input name="contact" type="tel" value="<?= e($c['contact']) ?>"></div>
          <div><label>Address</label><input name="address" type="text" value="<?= e($c['address']) ?>"></div>
          <div class="full btn-row"><button class="btn btn-primary btn-sm" type="submit">Save</button><a class="btn btn-quiet btn-sm" href="<?= e(url('customers')) ?>">Cancel</a></div>
        </form></td></tr>
        <?php else: ?>
        <?php $active = (int)$c['is_active'] === 1; $hasHistory = (int)$c['any_sale_count'] > 0 || (int)$c['job_count'] > 0; ?>
        <tr<?= $active ? '' : ' class="row-inactive"' ?>><td><strong><?= e($c['name']) ?></strong><?= $active ? '' : ' ' . status_badge('Inactive') ?><span class="sub"><?= e($c['customer_type']) ?><?= $c['address'] ? ' · ' . e($c['address']) : '' ?></span></td><td class="nowrap"><?= e($c['contact'] ?? '—') ?></td>
            <td class="num"><?= e($c['sale_count']) ?></td><td class="num"><?= e($c['job_count']) ?></td>
            <td class="num"><div class="row-actions fixed cols-cust">
              <a class="btn btn-quiet btn-sm" href="<?= e(url('customers', ['edit' => $c['customer_id']])) ?>">Edit</a>
              <?php if (!$active): ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="activate"><input type="hidden" name="customer_id" value="<?= e($c['customer_id']) ?>"><button class="btn btn-quiet btn-sm" type="submit">Reactivate</button></form>
              <?php elseif ($hasHistory): ?>
              <form method="post" data-confirm="Deactivate <?= e($c['name']) ?>? They will be hidden from the dropdowns; their sales and jobs are kept."><?= csrf_field() ?><input type="hidden" name="action" value="deactivate"><input type="hidden" name="customer_id" value="<?= e($c['customer_id']) ?>"><button class="btn btn-quiet btn-sm" type="submit">Deactivate</button></form>
              <?php else: ?>
              <form method="post" data-confirm="Delete <?= e($c['name']) ?>? They have no sales or jobs, so nothing else is affected."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="customer_id" value="<?= e($c['customer_id']) ?>"><button class="btn btn-danger btn-sm" type="submit">Delete</button></form>
              <?php endif; ?>
            </div></td></tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
  <aside class="panel">
    <h2>Add a customer</h2>
    <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="add">
      <div><label for="name">Name</label><input id="name" name="name" type="text" required></div>
      <div><label for="customer_type">Type</label><select id="customer_type" name="customer_type"><option>Business</option><option>Contractor</option><option>Residential</option><option>Walk-in</option></select></div>
      <div><label for="contact">Contact number <span class="hint">Optional</span></label><input id="contact" name="contact" type="tel"></div>
      <div><label for="address">Address <span class="hint">Optional</span></label><input id="address" name="address" type="text"></div>
      <button class="btn btn-primary" type="submit">Save customer</button>
    </form>
  </aside>
</div>
<?php layout_end();
