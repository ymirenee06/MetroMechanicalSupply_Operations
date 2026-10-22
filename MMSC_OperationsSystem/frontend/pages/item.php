<?php
$id = (int)($_GET['id'] ?? 0);
$error = null;

if (is_post() && post('action') === 'image') {
    try {
        if (empty($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) throw new UserError('Choose a picture to upload.');
        InventoryService::saveImage($id, $_FILES['image']);
        flash('ok', 'Picture saved.');
        redirect('item', ['id' => $id]);
    } catch (Throwable $e) { $error = friendly_error($e); }
}
if (is_post() && post('action') === 'remove_image') {
    try {
        InventoryService::removeImage($id);
        flash('ok', 'Picture removed.');
        redirect('item', ['id' => $id]);
    } catch (Throwable $e) { $error = friendly_error($e); }
}
if (is_post() && !in_array(post('action'), ['image', 'remove_image'], true) && !has_role('Executive Officer')) { http_response_code(403); render_message('Action restricted','Only an Executive Officer can change item prices or archive an item.'); exit; }
if (is_post() && !in_array(post('action'), ['image', 'remove_image'], true) && has_role('Executive Officer')) {
    try {
        if (post('action') === 'prices') {
            InventoryService::updatePrices($id, to_num(post('unit_cost')), to_num(post('selling_price')), (int) to_num(post('reorder_point')), uid());
            flash('ok', 'Prices saved. The old prices are kept in the price history.');
        } elseif (post('action') === 'usage') {
            InventoryService::setToolUsage($id, post('for_sale') === '1', uid());
            flash('ok', post('for_sale') === '1' ? 'This tool is now merchandise for sale. It will no longer be offered for release to technicians.' : 'This tool is now company equipment. It can be released to technicians and is no longer sold at the counter.');
        } elseif (post('action') === 'archive') {
            InventoryService::setArchived($id, post('archived') === '1');
            flash('ok', post('archived') === '1' ? 'Item archived. It no longer appears in lists.' : 'Item restored.');
        }
        redirect('item', ['id' => $id]);
    } catch (Throwable $e) { $error = friendly_error($e); }
}

try { $i = InventoryService::item($id); } catch (UserError $e) { render_message('Item not found', $e->getMessage()); return; }
$role=current_user()['role'];
$canSeeHistory=has_role('Executive Officer','Purchasing Officer','Accounting','Logistics');
$canSeeCost=has_role('Executive Officer','Purchasing Officer','Accounting');
$canSeeSupplier=has_role('Executive Officer','Purchasing Officer','Logistics');
$canSeeSellingPrice=has_role('Executive Officer','Sales','Accounting');
$history = $canSeeHistory ? InventoryService::history($id) : [];
$prices = has_role('Executive Officer') ? InventoryService::priceHistory($id) : [];

layout_start($i['name'], '<span class="code">' . e($i['item_code']) . '</span> · ' . e($i['category'] ?? 'No category'),
             '<a class="btn btn-quiet" href="' . e(url('items')) . '">Back to items</a>');
echo error_box($error);
?>
<?php $hasPhoto = $i['image_path'] && is_file(dirname(__DIR__, 2) . '/' . $i['image_path']); ?>
<section class="panel item-profile">
  <div class="item-photo-box">
    <?php if ($hasPhoto): ?><img class="item-photo" src="<?= e($i['image_path']) ?>" alt="<?= e($i['name']) ?>" width="220" height="220" style="width:220px;height:220px;object-fit:contain">
    <?php else: ?><div class="item-photo item-photo-empty" style="width:220px;height:220px">No picture yet</div><?php endif; ?>
    <?php if (has_role('Executive Officer', 'Purchasing Officer', 'Logistics')): ?>
    <form method="post" enctype="multipart/form-data" class="photo-form">
      <?= csrf_field() ?><input type="hidden" name="action" value="image">
      <label for="image" class="small"><?= $hasPhoto ? 'Replace picture' : 'Add a picture' ?> <span class="hint">JPG, PNG, WEBP · 3 MB</span></label>
      <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp" required>
      <div class="photo-actions<?= $hasPhoto ? '' : ' single' ?>">
        <button class="btn btn-secondary btn-sm" type="submit">Save picture</button>
        <?php if ($hasPhoto): ?><button class="btn btn-danger btn-sm" type="submit" form="remove-photo">Remove picture</button><?php endif; ?>
      </div>
    </form>
    <?php if ($hasPhoto): ?><form method="post" id="remove-photo" data-confirm="Remove this item's picture? The list will show no picture for it."><?= csrf_field() ?><input type="hidden" name="action" value="remove_image"></form><?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="item-info">
    <div class="item-stats">
      <div><span class="n"><?= e(qty($i['qty_available'])) ?></span><span class="l"><?= e($i['unit_of_measure']) ?> available</span></div>
      <?php if (is_company_tool($i)): ?><div><span class="n"><?= e(qty($i['qty_checked_out'])) ?></span><span class="l">with technicians</span></div><?php endif; ?>
      <?php if (has_role('Executive Officer','Purchasing Officer','Logistics','Accounting')): ?><div><span class="n"><?= e(qty($i['reorder_point'])) ?></span><span class="l">low-stock level</span></div><?php endif; ?>
      <?php if ($canSeeSellingPrice): ?><div><span class="n"><?= $i['is_sellable'] ? e(money($i['selling_price'])) : '—' ?></span><span class="l"><?= $i['is_sellable'] ? 'per ' . e($i['unit_of_measure']) : 'not for sale' ?></span></div><?php endif; ?>
      <div><span class="n"><?= status_badge($i['stock_status']) ?></span><span class="l">stock status<?= $i['is_archived'] ? ' · archived' : '' ?></span></div>
    </div>
    <dl class="facts">
      <dt>Type</dt><dd><?= type_badge($i['item_type'], $i['is_sellable']) ?></dd>
      <dt>Category</dt><dd><?= e($i['category'] ?? '—') ?></dd>
      <dt>Unit</dt><dd><?= e($i['unit_of_measure']) ?><?= $i['allows_decimal'] ? ' (decimals allowed)' : ' (whole numbers)' ?></dd>
      <?php if ($canSeeSupplier): ?><dt>Supplier</dt><dd><?= e($i['supplier'] ?? '—') ?></dd><?php endif; ?>
      <?php if (has_role('Executive Officer','Purchasing Officer','Logistics')): ?><dt>Brand / origin</dt><dd><?= e($i['brand_origin'] ?? '—') ?></dd><dt>Storage location</dt><dd><?= e($i['storage_location'] ?? '—') ?></dd><?php endif; ?>
      <?php if (has_role('Executive Officer','Purchasing Officer','Accounting','Sales')): ?><dt>Barcode</dt><dd><?= e($i['barcode'] ?? '—') ?></dd><?php endif; ?>
      <?php if ($canSeeCost): ?><dt>Unit cost</dt><dd><?= e(money($i['unit_cost'])) ?></dd><?php endif; ?>
      <?php if ($i['description']): ?><dt>Description</dt><dd><?= e($i['description']) ?></dd><?php endif; ?>
    </dl>
  </div>
</section>

<div class="split">
  <?php if ($canSeeHistory): ?><section class="panel" id="history">
    <div class="panel-head"><h2>Stock history</h2><span class="muted small">Newest first. Rows can never be edited or deleted.</span></div>
    <div class="table-wrap"><table>
      <thead><tr><th>When</th><th>What happened</th><th>By</th><th class="num">Change</th><th class="num">Balance</th></tr></thead>
      <tbody>
      <?php if (!$history): ?><tr><td colspan="5"><div class="empty">No stock yet. Record a delivery to add stock.</div></td></tr><?php endif; ?>
      <?php foreach (array_slice($history, 0, 25) as $h): ?>
        <tr><td><?= e(fmt_dt($h['created_at'])) ?></td><td><?= e($h['movement_type']) ?></td><td><?= e($h['full_name']) ?></td>
            <td class="num" style="color:<?= $h['qty_change'] < 0 ? 'var(--bad)' : 'var(--ok)' ?>"><?= $h['qty_change'] > 0 ? '+' : '' ?><?= e(qty($h['qty_change'])) ?></td>
            <td class="num"><strong><?= e(qty($h['balance'])) ?></strong></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if (count($history) > 25): ?><p class="muted small" style="margin-bottom:0">Showing the latest 25 of <?= count($history) ?> movements. The Item History report has all of them.</p><?php endif; ?>
  </section><?php else: ?><div></div><?php endif; ?>

  <div>
    <?php if (has_role('Executive Officer')): ?>
    <section class="panel" id="prices">
      <h2>Change prices</h2>
      <form method="post" class="stack">
        <?= csrf_field() ?><input type="hidden" name="action" value="prices">
        <div><label for="unit_cost">Unit cost</label><input id="unit_cost" name="unit_cost" type="number" step="0.01" min="0" value="<?= e($i['unit_cost']) ?>"></div>
        <div><label for="selling_price">Selling price</label><input id="selling_price" name="selling_price" type="number" step="0.01" min="0" value="<?= e($i['selling_price']) ?>"></div>
        <div><label for="reorder_point">Low-stock level</label><input id="reorder_point" name="reorder_point" type="number" step="1" min="0" value="<?= e(qty($i['reorder_point'])) ?>"></div>
        <button class="btn btn-primary" type="submit">Save prices</button>
      </form>
      <?php if ($prices): ?>
      <h3 style="font-size:15px;margin:20px 0 8px">Price history</h3>
      <table><tbody><?php foreach ($prices as $p): ?>
        <tr><td class="small"><?= e(fmt_d($p['changed_at'])) ?><span class="sub"><?= e($p['full_name']) ?></span></td>
            <td class="num small"><?= $p['old_selling_price'] !== null ? e(money($p['old_selling_price'])) . ' → ' : 'Set to ' ?><?= e(money($p['new_selling_price'])) ?></td></tr>
      <?php endforeach; ?></tbody></table>
      <?php endif; ?>
    </section>
    <?php if ($i['item_type'] === 'Tool/Equipment'): ?>
    <section class="panel">
      <h2>How this tool is used</h2>
      <p class="muted small" style="margin-top:0">Currently: <strong><?= $i['is_sellable'] ? 'Tool for sale' : 'Company tool' ?></strong>. <?= $i['is_sellable'] ? 'Sold at the counter; never lent to technicians.' : 'Lent to technicians for jobs; not sold.' ?></p>
      <form method="post" data-confirm="<?= $i['is_sellable'] ? 'Make this a company tool? It will stop appearing in Counter sale.' : 'Make this a tool for sale? It will stop appearing in Release items.' ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="usage"><input type="hidden" name="for_sale" value="<?= $i['is_sellable'] ? '0' : '1' ?>">
        <button class="btn btn-secondary btn-sm" type="submit"><?= $i['is_sellable'] ? 'Change to company tool' : 'Change to tool for sale' ?></button>
      </form>
    </section>
    <?php endif; ?>
    <section class="panel">
      <form method="post" data-confirm="<?= $i['is_archived'] ? 'Restore this item?' : 'Archive this item? It will be hidden from lists but its history is kept.' ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="archive"><input type="hidden" name="archived" value="<?= $i['is_archived'] ? '0' : '1' ?>">
        <button class="btn btn-quiet" type="submit"><?= $i['is_archived'] ? 'Restore item' : 'Archive item' ?></button>
      </form>
    </section>
    <?php endif; ?>
  </div>
</div>
<?php layout_end();
