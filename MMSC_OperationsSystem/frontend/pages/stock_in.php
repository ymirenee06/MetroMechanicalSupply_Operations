<?php
$error = null;
if (is_post()) {
    try {
        if (post('action') === 'encode') {
            if (!has_role('Purchasing Officer', 'Logistics', 'Executive Officer')) throw new UserError('Only Purchasing, Logistics, or an Executive Officer can record incoming deliveries.');
            InventoryService::encodeStockIn($_POST, uid());
            flash('ok', 'Delivery recorded. Stock goes up once an Executive Officer verifies it against the delivery receipt.');
        } elseif (post('action') === 'verify') {
            if (!has_role('Executive Officer')) throw new UserError('Only an Executive Officer can verify incoming deliveries.');
            $posting = post('decision') === 'post';
            InventoryService::verifyStockIn((int)post('stock_in_id'), $posting, post('reason'), uid());
            flash('ok', $posting ? 'Posted. The stock has been added.' : 'Rejected. Nothing was added to stock.');
        } else { throw new UserError('Unknown delivery action.'); }
        redirect('stock_in');
    } catch (Throwable $e) { $error = friendly_error($e); }
}

$pending = InventoryService::stockIns('Pending Verification');
$recent = array_slice(InventoryService::stockIns(), 0, 30);
$items = q_all("SELECT item_id, item_code, name, item_type, is_sellable, unit_of_measure, allows_decimal, supplier FROM items WHERE is_archived = 0 ORDER BY item_type DESC, name");
$supplierIds = array_column(InventoryService::suppliers(), 'supplier_id', 'name');
$receivers = InventoryService::receivers();

layout_start('Deliveries', 'Purchasing or Logistics records the incoming delivery. An Executive Officer verifies the document before stock goes up.');
echo error_box($error);
?>
  <?php if (has_role('Logistics', 'Purchasing Officer', 'Executive Officer')): ?>
  <section class="panel">
    <h2>Record an incoming delivery</h2>
    <form method="post" class="form-grid form-grid-3" data-delivery data-to-top>
      <?= csrf_field() ?><input type="hidden" name="action" value="encode">
      <div class="span-2"><label for="item_id">Item <span class="hint">Grouped by consumables and tools</span></label>
        <select id="item_id" name="item_id" required>
          <option value="">Choose an item</option>
          <?php foreach (['c' => 'Consumables', 'ts' => 'Tools for sale', 'tc' => 'Company tools'] as $t => $grp): ?><optgroup label="<?= e($grp) ?>">
            <?php foreach ($items as $it): $k = $it['item_type'] === 'Consumable' ? 'c' : ($it['is_sellable'] ? 'ts' : 'tc'); if ($k !== $t) continue; ?>
              <option value="<?= e($it['item_id']) ?>" data-unit="<?= e($it['unit_of_measure']) ?>" data-decimal="<?= $it['allows_decimal'] ? '1' : '0' ?>" data-supplier="<?= e($supplierIds[$it['supplier'] ?? ''] ?? '') ?>"<?= (string)post('item_id') === (string)$it['item_id'] ? ' selected' : '' ?>><?= e($it['name']) ?> (<?= e($it['item_code']) ?>)</option>
            <?php endforeach; ?></optgroup><?php endforeach; ?>
        </select></div>
      <?= supplier_picker() ?>
      <div><label for="qty_received">Quantity received <span class="hint" data-unit-hint>In the item's unit</span></label>
        <input id="qty_received" name="qty_received" type="number" step="1" min="0" data-qty-item="item_id" value="<?= e(post('qty_received')) ?>" required></div>
      <div><label for="dr_qty_text">As written on the DR <span class="hint">Optional, e.g. "1 roll"</span></label>
        <input id="dr_qty_text" name="dr_qty_text" type="text" value="<?= e(post('dr_qty_text')) ?>"></div>
      <div><label for="dr_po_number">DR / PO number <span class="hint">From the supplier's paper</span></label><input id="dr_po_number" name="dr_po_number" type="text" value="<?= e(post('dr_po_number')) ?>" required></div>
      <div><label for="date_received">Date received <span class="hint">Today, unless encoding late</span></label><input id="date_received" name="date_received" type="date" max="<?= e(today()) ?>" value="<?= e(post('date_received', today())) ?>"></div>
      <div><label for="received_by">Received by <span class="hint">Who accepted it at the stockroom</span></label><select id="received_by" name="received_by" required><?= options($receivers, 'user_id', 'label', post('received_by', (string)uid()), 'Choose who received it') ?></select></div>
      <p class="full muted small" style="margin:0">You are recorded as the encoder. Stock goes up after an Executive Officer posts it.</p>
      <div class="full"><button class="btn btn-primary" type="submit">Record delivery</button></div>
    </form>
  </section>
  <?php endif; ?>

  <section class="panel">
    <div class="panel-head"><h2>Waiting for verification</h2><span class="badge badge-warn"><?= count($pending) ?> pending</span></div>
    <?php if (!$pending): ?><div class="empty">No deliveries are waiting.</div><?php endif; ?>
    <?php foreach ($pending as $p): ?>
      <div class="panel" style="margin-bottom:12px">
        <div class="panel-head" style="margin-bottom:6px">
          <strong><?= e($p['name']) ?> · <?= e(qty($p['qty_received'])) ?> <?= e($p['unit_of_measure']) ?></strong>
        </div>
        <dl class="facts small">
          <dt>DR / PO</dt><dd><?= e($p['dr_po_number']) ?><?= $p['dr_qty_text'] ? ' · on DR: ' . e($p['dr_qty_text']) : '' ?></dd>
          <dt>Supplier</dt><dd><?= e($p['supplier'] ?? '—') ?></dd>
          <dt>Received</dt><dd><?= e(fmt_d($p['date_received'])) ?> by <?= e($p['received_name']) ?></dd>
          <dt>Encoded</dt><dd><?= e(fmt_dt($p['received_at'])) ?> by <?= e($p['encoded_name']) ?></dd>
        </dl>
        <?php if (has_role('Executive Officer')): ?>
        <form method="post" class="inline-form" style="margin-top:12px">
          <?= csrf_field() ?><input type="hidden" name="action" value="verify"><input type="hidden" name="stock_in_id" value="<?= e($p['stock_in_id']) ?>">
          <div><label for="r<?= e($p['stock_in_id']) ?>">Reason, if rejecting</label><input id="r<?= e($p['stock_in_id']) ?>" name="reason" type="text" placeholder="Quantity does not match the DR"></div>
          <button class="btn btn-primary" name="decision" value="post" type="submit">Post to stock</button>
          <button class="btn btn-danger" name="decision" value="reject" type="submit">Reject</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>

<section class="panel" style="padding:0">
  <div class="panel-head pad"><h2>Recent deliveries</h2><span class="muted small">Latest 30. For the full list use Reports → Incoming Deliveries (Export to Excel).</span></div>
  <div class="table-wrap"><table>
    <thead><tr><th>Item</th><th class="num">Qty</th><th>DR / PO</th><th>Received by</th><th>Encoded by</th><th>Verified by</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($recent as $r): ?>
      <tr><td><?= e($r['name']) ?> <?= type_badge($r['item_type'], $r['is_sellable'] ?? null) ?><span class="sub"><?= e(fmt_d($r['date_received'])) ?></span></td>
          <td class="num"><?= e(qty($r['qty_received'])) ?> <?= e($r['unit_of_measure']) ?></td>
          <td class="nowrap"><?= e($r['dr_po_number']) ?></td><td><?= e($r['received_name']) ?></td><td><?= e($r['encoded_name']) ?></td><td><?= e($r['verified_name'] ?? '—') ?></td>
          <td><?= status_badge($r['status']) ?><?= $r['rejection_reason'] ? '<span class="sub">' . e($r['rejection_reason']) . '</span>' : '' ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>
<?php layout_end();
