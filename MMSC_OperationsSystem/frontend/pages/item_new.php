<?php
$error = null;
$d = is_post() ? $_POST + ['item_type' => 'Consumable'] : ['item_type' => 'Consumable', 'is_sellable' => '1'];
if (!has_role('Executive Officer')) { http_response_code(403); render_message('Action restricted','Only an Executive Officer can create catalog items.'); exit; }
if (is_post()) {
    try {
        $id = InventoryService::createItem($_POST, uid());
        try { InventoryService::saveImage($id, $_FILES['image'] ?? null); }
        catch (UserError $ie) { flash('warn', 'Item added, but the picture was not saved: ' . $ie->getMessage()); }
        flash('ok', 'Item added. Record a delivery to give it stock.');
        redirect('item', ['id' => $id]);
    } catch (Throwable $e) { $error = friendly_error($e); }
}
$cats = InventoryService::categories();
layout_start('Add item', 'Stock starts at zero. It goes up only when a delivery is verified.', '<a class="btn btn-quiet" href="' . e(url('items')) . '">Cancel</a>');
echo error_box($error);
?>
<form method="post" class="panel" enctype="multipart/form-data">
  <div class="form-grid aligned">
  <?= csrf_field() ?>
    <div class="name-field"><label for="name">Item name</label><input id="name" name="name" type="text" value="<?= e($d['name'] ?? '') ?>" required autocomplete="off"
      data-similar="<?= e(json_encode(array_map(fn($i) => ['id' => (int)$i['item_id'], 'n' => $i['name'], 'c' => $i['item_code'], 'q' => qty($i['qty_available']), 'u' => $i['unit_of_measure'], 'a' => (int)$i['is_archived']], InventoryService::items('', '')))) ?>">
      <div class="similar" data-similar-box hidden></div></div>
    <div><label for="item_code">Item code <span class="hint">Assigned automatically</span></label>
      <input id="item_code" name="item_code" type="text" readonly></div>
    <div><label for="category_id">Category</label><select id="category_id" name="category_id"><?= options($cats, 'category_id', 'name', $d['category_id'] ?? '', 'No category') ?></select></div>
    <div><label for="item_type">Type</label>
      <select id="item_type" name="item_type">
        <option value="Consumable"<?= $d['item_type'] === 'Consumable' ? ' selected' : '' ?>>Consumable (used up or sold)</option>
        <option value="Tool/Equipment"<?= $d['item_type'] === 'Tool/Equipment' ? ' selected' : '' ?>>Tool or equipment</option>
      </select></div>
    <div><label for="unit_of_measure">Unit <span class="hint">e.g. PC, Unit, Set, Meter</span></label>
      <input id="unit_of_measure" name="unit_of_measure" type="text" value="<?= e($d['unit_of_measure'] ?? '') ?>" required></div>
    <div><label for="reorder_point">Low-stock level <span class="hint">Shows as Low at or below this</span></label><input id="reorder_point" name="reorder_point" type="number" min="0" step="1" value="<?= e($d['reorder_point'] ?? '0') ?>"></div>
    <div><label for="unit_cost">Unit cost</label><input id="unit_cost" name="unit_cost" type="number" min="0" step="0.01" value="<?= e($d['unit_cost'] ?? '0') ?>"></div>
    <div><label for="selling_price">Selling price</label><input id="selling_price" name="selling_price" type="number" min="0" step="0.01" value="<?= e($d['selling_price'] ?? '0') ?>"></div>
    <?= supplier_picker(null, false) ?>
    <div><label for="brand_origin">Brand / origin</label><input id="brand_origin" name="brand_origin" type="text" value="<?= e($d['brand_origin'] ?? '') ?>"></div>
    <div><label for="storage_location">Storage location</label><input id="storage_location" name="storage_location" type="text" value="<?= e($d['storage_location'] ?? '') ?>"></div>
    <div><label for="barcode">Barcode (optional)</label><input id="barcode" name="barcode" type="text" value="<?= e($d['barcode'] ?? '') ?>"></div>
    <div class="full"><label for="image">Picture <span class="hint">Optional. JPG, PNG, or WEBP, up to 3 MB.</span></label><input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp"></div>
    <div class="full"><label for="description">Description <span class="hint">Optional</span></label><textarea id="description" name="description"><?= e($d['description'] ?? '') ?></textarea></div>
    <div class="full">
      <label class="check"><input type="checkbox" name="is_sellable" value="1"<?= !empty($d['is_sellable']) ? ' checked' : '' ?>> <span>Can be sold at the counter <span class="hint">For tools: ticked = <strong>tool for sale</strong> (merchandise, never lent). Not ticked = <strong>company tool</strong> (lent to technicians, not sold).</span></span></label>
      <label class="check"><input type="checkbox" name="allows_decimal" value="1"<?= !empty($d['allows_decimal']) ? ' checked' : '' ?>> Sold in measured amounts, like 2.50 kg (allows decimals)</label>
    </div>
  </div>
  <div class="btn-row"><button class="btn btn-primary" type="submit">Add item</button></div>
</form>
<?php layout_end();
