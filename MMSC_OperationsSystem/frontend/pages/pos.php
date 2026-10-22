<?php
$error = null;
if (is_post()) {
    try {
        $r = SalesService::counterSale(uid(), $_POST, post_rows('lines'), post_rows('payments'));
        flash('ok', 'Sale ' . $r['ref'] . ' saved.' . ($r['received'] > 0 ? ' Received ' . money($r['received']) . '.' : '') . ($r['change'] > 0 ? ' Give ' . money($r['change']) . ' change.' : ''));
        redirect('sale', ['id' => $r['sale_id']]);
    } catch (Throwable $e) { $error = friendly_error($e); }
}
$s = SalesService::mySession(uid());
layout_start('Counter sale', $s ? 'Drawer ' . e($s['drawer_no']) . ' · prices come from the item list and cannot be typed.' : '');
echo error_box($error);
if (!$s): ?>
  <div class="panel"><div class="empty">Open your drawer before recording sales. <a href="<?= e(url('drawer')) ?>">Open a drawer</a></div></div>
<?php layout_end(); return; endif;

$items = SalesService::sellableItems();
$opts = '<option value="">Choose an item</option>';
foreach (['Consumable' => 'Consumables', 'Tool/Equipment' => 'Tools for sale'] as $t => $grp) {
    $opts .= '<optgroup label="' . e($grp) . '">';
    foreach ($items as $i) {
        if ($i['item_type'] !== $t) continue;
        $opts .= '<option value="' . e($i['item_id']) . '" data-price="' . e($i['selling_price']) . '" data-unit="' . e($i['unit_of_measure']) . '" data-stock="' . e(qty($i['qty_available']))
               . '" data-decimal="' . ($i['allows_decimal'] ? '1' : '0') . '">' . e($i['name'] . ' · ' . money($i['selling_price']) . '/' . $i['unit_of_measure']) . '</option>';
    }
    $opts .= '</optgroup>';
}
$limit = (float) setting('discount_limit_pct', 5);
?>
<form method="post" data-totals data-disc-limit="<?= e($limit) ?>" data-vat-rate="<?= e(setting('vat_rate', 12)) ?>">
  <?= csrf_field() ?>
  <div class="split">
    <section class="panel">
      <div data-lines="lines" data-initial="<?= e(json_encode(post_rows('lines'))) ?>">
        <h2 style="font-size:17px">Items</h2>
        <div class="line-head pos-head" aria-hidden="true"><span>Item</span><span>Quantity</span><span class="num">Amount</span><span></span></div>
        <div class="lines"></div>
        <template><div class="line pos-line">
          <div><label class="m-label">Item</label><select name="lines[__i__][item_id]" aria-label="Item"><?= $opts ?></select><span class="hint" data-unit></span></div>
          <div><label class="m-label">Quantity</label><input name="lines[__i__][qty]" type="number" min="0" step="1" value="1" inputmode="numeric" aria-label="Quantity"></div>
          <div class="line-total">₱0.00</div>
          <button type="button" class="x" aria-label="Remove this item">×</button>
        </div></template>
        <button type="button" class="btn btn-secondary btn-sm" data-add style="margin-top:10px">Add another item</button>
      </div>
      <?= payment_editor() ?>
    </section>

    <aside class="panel">
      <h2>Receipt</h2>
      <div class="stack">
        <div><label for="or_number">OR number <span class="hint">From the receipt booklet.</span></label><input id="or_number" name="or_number" type="text" value="<?= e(post('or_number')) ?>" required></div>
        <?= customer_picker('Customer', true, 'Walk-in, a saved customer, or a new one') ?>
        <div data-contact-field><label for="customer_contact">Contact <span class="hint">Optional</span></label><input id="customer_contact" name="customer_contact" type="text" value="<?= e(post('customer_contact')) ?>"></div>
        <div><label for="discount_amount">Discount</label><input id="discount_amount" name="discount_amount" type="number" min="0" step="0.01" value="<?= e(post('discount_amount', '0')) ?>"></div>
        <div><label for="discount_reason">Discount reason</label><input id="discount_reason" name="discount_reason" type="text" value="<?= e(post('discount_reason')) ?>" placeholder="Contractor discount"></div>
        <fieldset data-approval hidden>
          <legend>Executive Officer approval</legend>
          <p class="small muted" style="margin-top:0">Discounts above <?= e($limit) ?>% need an Executive Officer to enter their login here.</p>
          <div><label for="approver_employee_id">Executive Officer Employee ID</label><input id="approver_employee_id" name="approver_employee_id" type="text" autocomplete="off"></div>
          <div style="margin-top:8px"><label for="approver_password">Executive Officer password</label><input id="approver_password" name="approver_password" type="password" autocomplete="off"></div>
        </fieldset>
      </div>
      <div class="totals">
        <div><span>Subtotal</span><span data-gross>₱0.00</span></div>
        <div><span>Discount</span><span data-disc>−₱0.00</span></div>
        <div><span>Taxable amount</span><span data-taxable>₱0.00</span></div>
        <div><span>VAT (<span data-vat-rate-label><?= e(setting('vat_rate', 12)) ?></span>%)</span><span data-vat>₱0.00</span></div>
        <div><strong>Total with VAT</strong><strong data-total-vat>₱0.00</strong></div>
        <div class="due"><span>Amount due</span><span data-due>₱0.00</span></div>
      </div>
      <div class="btn-row"><button class="btn btn-primary" type="submit">Save sale</button></div>
    </aside>
  </div>
</form>
<?php layout_end();
