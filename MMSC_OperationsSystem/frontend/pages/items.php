<?php
$search = trim($_GET['q'] ?? '');
$filter = $_GET['filter'] ?? '';
$type = in_array($_GET['type'] ?? '', ['tool', 'tool_sale', 'consumable'], true) ? $_GET['type'] : '';
$items = InventoryService::items($search, $filter, $type);
$tabUrl = fn($t) => url('items', array_filter(['q' => $search, 'filter' => $filter, 'type' => $t]));

$actions = has_role('Executive Officer') ? '<a class="btn btn-secondary" href="' . e(url('stock_in')) . '">Record a delivery</a> <a class="btn btn-primary" href="' . e(url('item_new')) . '">Add item</a>' : '';
if (has_role('Logistics', 'Purchasing Officer')) $actions = '<a class="btn btn-primary" href="' . e(url('stock_in')) . '">Record a delivery</a>';
layout_start('Items and stock', 'Stock is calculated from every recorded movement. Nobody types a quantity.', $actions);
?>
<?php $countAll = count(InventoryService::items($search, $filter, '')); $countTool = count(InventoryService::items($search, $filter, 'tool')); $countSale = count(InventoryService::items($search, $filter, 'tool_sale')); ?>
<nav class="tabs" aria-label="Item type">
  <a href="<?= e($tabUrl('')) ?>" class="<?= $type === '' ? 'on' : '' ?>"<?= $type === '' ? ' aria-current="page"' : '' ?>>All items <span class="count"><?= e($countAll) ?></span></a>
  <a href="<?= e($tabUrl('tool')) ?>" class="<?= $type === 'tool' ? 'on' : '' ?>"<?= $type === 'tool' ? ' aria-current="page"' : '' ?>>Company tools <span class="count"><?= e($countTool) ?></span></a>
  <a href="<?= e($tabUrl('tool_sale')) ?>" class="<?= $type === 'tool_sale' ? 'on' : '' ?>"<?= $type === 'tool_sale' ? ' aria-current="page"' : '' ?>>Tools for sale <span class="count"><?= e($countSale) ?></span></a>
  <a href="<?= e($tabUrl('consumable')) ?>" class="<?= $type === 'consumable' ? 'on' : '' ?>"<?= $type === 'consumable' ? ' aria-current="page"' : '' ?>>Consumables <span class="count"><?= e($countAll - $countTool - $countSale) ?></span></a>
</nav>
<p class="muted small tab-note"><?= ['tool' => 'Company tools are lent to technicians for jobs and must come back. They are not sold.', 'tool_sale' => 'Tools for sale are merchandise sold at the counter. They are never lent to technicians.', 'consumable' => 'Consumables are used up on jobs or sold at the counter.'][$type] ?? 'Company tools are lent and returned; tools for sale and consumables are sold or used up.' ?></p>
<form class="filters" method="get">
  <input type="hidden" name="page" value="items"><input type="hidden" name="type" value="<?= e($type) ?>">
  <div><label for="q">Search</label><input id="q" type="search" name="q" value="<?= e($search) ?>" placeholder="Name, code, or barcode"></div>
  <div><label for="filter">Show</label>
    <select id="filter" name="filter">
      <option value="">Active items</option>
      <?php if (has_role('Executive Officer','Purchasing Officer','Logistics','Accounting')): ?><option value="low"<?= $filter === 'low' ? ' selected' : '' ?>>Low and out of stock</option><?php endif; ?>
      <?php if (has_role('Executive Officer')): ?><option value="archived"<?= $filter === 'archived' ? ' selected' : '' ?>>Archived items</option><?php endif; ?>
    </select></div>
  <button class="btn btn-secondary" type="submit">Search</button>
</form>

<div class="panel" style="padding:0">
  <div class="table-wrap"><table>
    <thead><tr><th>Item</th><th>Type</th><th class="num">Available</th><?php if (has_role('Executive Officer','Purchasing Officer','Logistics','Accounting')): ?><th class="num">With technicians</th><?php endif; ?><?php if (has_role('Executive Officer','Sales','Accounting')): ?><th class="num">Price</th><?php endif; ?><th>Status</th></tr></thead>
    <tbody>
    <?php if (!$items): ?><tr><td colspan="<?= 4 + (has_role('Executive Officer','Sales','Accounting') ? 1 : 0) + (has_role('Executive Officer','Purchasing Officer','Logistics','Accounting') ? 1 : 0) ?>"><div class="empty">No items match. Try a shorter search.</div></td></tr><?php endif; ?>
    <?php foreach ($items as $i): ?>
      <tr>
        <td><a class="item-cell" href="<?= e(url('item', ['id' => $i['item_id']])) ?>"><?= item_thumb($i['image_path'], $i['name']) ?><span><strong><?= e($i['name']) ?></strong><span class="sub"><span class="code"><?= e($i['item_code']) ?></span><?= $i['category'] ? ' · ' . e($i['category']) : '' ?></span></span></a></td>
        <td><?= type_badge($i['item_type'], $i['is_sellable']) ?></td>
        <td class="num"><strong><?= e(qty($i['qty_available'])) ?></strong> <span class="muted"><?= e($i['unit_of_measure']) ?></span></td>
        <?php if (has_role('Executive Officer','Purchasing Officer','Logistics','Accounting')): ?><td class="num"><?= is_company_tool($i) ? e(qty($i['qty_checked_out'])) : '<span class="muted">—</span>' ?></td><?php endif; ?>
        <?php if (has_role('Executive Officer','Sales','Accounting')): ?><td class="num"><?= $i['is_sellable'] ? e(money($i['selling_price'])) : '<span class="muted">Not for sale</span>' ?></td><?php endif; ?>
        <td><?= status_badge($i['stock_status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php layout_end();
