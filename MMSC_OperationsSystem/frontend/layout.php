<?php
// Shared page frame: sidebar navigation (by role), header, and messages.

function logo_svg(string $fill = 'currentColor', string $class = ''): string
{
    // The MMSC mark, traced from the official logo (4 x 4 grid with 45° cuts).
    return '<svg class="' . e($class) . '" viewBox="0 0 4 4" aria-hidden="true" focusable="false"><g fill="' . e($fill) . '">'
        . '<polygon points="1,0 1.996,0 1.996,1 1,1.996 0,1.996 0,1"/>'
        . '<polygon points="3,0 4,0 4,1 3,1.996 2.004,1.996 2.004,1"/>'
        . '<polygon points="1,2.004 1.996,2.004 1.996,3 1,4 0,4 0,3"/>'
        . '<polygon points="3,2.004 4,2.004 4,3 3,4 2.004,4 2.004,3"/>'
        . '</g></svg>';
}

function nav_items(string $role): array
{
    $n = [
        'Executive Officer' => [
            ['dashboard', 'Dashboard'],
            ['Approvals'], ['approvals', 'Approval queue'],
            ['Inventory'], ['items', 'Items and stock'], ['stock_in', 'Deliveries'], ['physical_count', 'Physical count'],
            ['Jobs'], ['jobs', 'Jobs'], ['job_new', 'Register job'], ['job_templates', 'Job templates'], ['release', 'Release items'], ['returns', 'Returns'], ['incidents', 'Incident reports'],
            ['Counter'], ['pos', 'Counter sale'], ['downpayment', 'Downpayment'], ['billing', 'Job billing'], ['cancellation_refunds', 'Cancellation refunds'], ['drawer', 'My drawer'], ['handover', 'Shift handover'],
            ['Sales'], ['sales', 'Sales'], ['sales_returns', 'Sales returns'], ['voids', 'Voids'], ['daily', 'Daily summary'],
            ['Reports'], ['sales_summary', 'Sales summary'], ['reports', 'Reports'],
            ['Administration'], ['users', 'Users'], ['customers', 'Customers'], ['settings', 'Settings'], ['audit', 'Audit log'],
        ],
        'Sales' => [
            ['dashboard', 'Dashboard'],
            ['Jobs'], ['jobs', 'Jobs'],
            ['Counter'], ['pos', 'Counter sale'], ['downpayment', 'Downpayment'], ['billing', 'Job billing'], ['cancellation_refunds', 'Cancellation refunds'], ['drawer', 'My drawer'],
            ['Records'], ['sales', 'My sales'], ['sales_returns', 'Sales returns'], ['voids', 'Voids'], ['handover', 'Shift handover'],
        ],
        'Purchasing Officer' => [
            ['dashboard', 'Dashboard'],
            ['Purchasing'], ['stock_in', 'Deliveries'], ['items', 'Items and stock'], ['reports', 'Reports'],
        ],
        'Accounting' => [
            ['dashboard', 'Dashboard'],
            ['Finance'], ['sales', 'Sales records'], ['daily', 'Daily summary'], ['sales_summary', 'Sales summary'],
            ['Jobs'], ['jobs', 'Jobs'], ['items', 'Items and stock'], ['reports', 'Reports'],
        ],
        'Logistics' => [
            ['dashboard', 'Dashboard'],
            ['Stockroom'], ['items', 'Items and stock'], ['stock_in', 'Deliveries'], ['physical_count', 'Physical count'],
            ['Jobs'], ['release', 'Release items'], ['returns', 'Returns'], ['jobs', 'Jobs'], ['reports', 'Reports'],
        ],
        'Technician' => [
            ['my_items', 'My items'], ['items', 'Stock lookup'], ['jobs', 'My jobs'],
        ],
    ];
    return $n[$role] ?? [];
}

function layout_start(string $title, string $subtitle = '', string $actions = ''): void
{
    $u = current_user();
    $cfg = $GLOBALS['CONFIG'];
    $current = $_GET['page'] ?? 'dashboard';
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($cfg['app_name']) ?></title>
<link rel="icon" href="frontend/assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('frontend/assets/app.css')) ?>">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="<?= e(url($u['role'] === 'Technician' ? 'my_items' : 'dashboard')) ?>">
      <span class="brand-tile"><?= logo_svg('#fff', 'brand-mark') ?></span>
      <span class="brand-text"><strong>Metro Mechanical</strong><span>Operations</span></span>
    </a>
    <nav aria-label="Main">
      <?php foreach (nav_items($u['role']) as $n):
          if (count($n) === 1) { echo '<p class="nav-group">' . e($n[0]) . '</p>'; continue; } ?>
        <a class="nav-link<?= $current === $n[0] ? ' is-active' : '' ?>" href="<?= e(url($n[0])) ?>"<?= $current === $n[0] ? ' aria-current="page"' : '' ?>><?= e($n[1]) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="me">
      <div class="me-name"><?= e($u['full_name']) ?></div>
      <div class="me-role"><?= e($u['role']) ?> · <a href="<?= e(url('account')) ?>">My account</a></div>
      <form method="post" action="<?= e(url('logout')) ?>"><?= csrf_field() ?><button class="linklike" type="submit">Log out</button></form>
    </div>
  </aside>
  <div class="content">
    <header class="topbar">
      <button class="menu-btn" type="button" aria-controls="sidebar" aria-expanded="false" data-menu>Menu</button>
      <div class="titles">
        <h1><?= e($title) ?></h1>
        <?php if ($subtitle): ?><p class="subtitle"><?= $subtitle ?></p><?php endif; ?>
      </div>
      <?php if ($actions): ?><div class="actions"><?= $actions ?></div><?php endif; ?>
    </header>
    <main id="main" tabindex="-1">
    <?php foreach (take_flashes() as [$type, $text]): ?>
      <div class="alert alert-<?= e($type) ?>" data-flash role="<?= $type === 'error' ? 'alert' : 'status' ?>"><?= e($text) ?></div>
    <?php endforeach;
}

function layout_end(): void
{
    ?>
    </main>
  </div>
</div>
<script src="<?= e(asset('frontend/assets/app.js')) ?>"></script>
</body>
</html>
<?php
}

function error_box(?string $error): string
{
    return $error ? '<div class="alert alert-error" data-flash role="alert">' . e($error) . '</div>' : '';
}

/** A plain message page, used for 403/404 and connection problems. */
function render_message(string $title, string $text): void
{
    $u = null;
    try { $u = current_user(); } catch (Throwable $e) { $u = null; }
    if ($u) {
        layout_start($title);
        echo '<div class="panel"><p>' . e($text) . '</p><p><a class="btn btn-secondary" href="' . e(url('dashboard')) . '">Go to dashboard</a></p></div>';
        layout_end();
        return;
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($title) . '</title>'
       . '<link rel="stylesheet" href="' . e(asset('frontend/assets/app.css')) . '"></head><body class="plain"><div class="plain-box">'
       . '<span class="brand-tile">' . logo_svg('#fff', 'brand-mark') . '</span><h1>' . e($title) . '</h1><p>' . e($text) . '</p>'
       . '<p><a class="btn btn-primary" href="index.php?page=login">Go to login</a></p></div></body></html>';
}

/** Payment lines (split payments) with live totals. Used by counter sale, billing, and downpayments. */
function payment_editor(bool $showChange = true): string
{
    $methods = '';
    foreach (['Cash', 'GCash', 'Bank Transfer', 'Check'] as $m) $methods .= '<option>' . $m . '</option>';
    return '<div data-lines="payments" data-initial="' . e(json_encode(post_rows('payments'))) . '">
      <h2 style="font-size:17px;margin-top:22px">Payment</h2>
      <div class="line-head pay-head" aria-hidden="true"><span>Method</span><span>Amount</span><span>Reference no.</span><span></span></div>
      <div class="lines"></div>
      <template><div class="line pay-line">
        <div><label class="m-label">Method</label><select name="payments[__i__][method]" aria-label="Payment method">' . $methods . '</select></div>
        <div><label class="m-label">Amount</label><input name="payments[__i__][amount]" type="number" min="0" step="0.01" inputmode="decimal" aria-label="Amount"></div>
        <div><label class="m-label">Reference no.</label><input name="payments[__i__][reference_no]" type="text" placeholder="GCash or bank reference" aria-label="Reference number"></div>
        <button type="button" class="x" aria-label="Remove this payment">×</button>
      </div></template>
      <div class="btn-row" style="margin-top:8px">
        <button type="button" class="btn btn-secondary btn-sm" data-add>Split payment</button>
        <button type="button" class="btn btn-quiet btn-sm" data-exact>Exact amount</button>
      </div>
      <p class="muted small" style="margin:6px 0 0">Type the amount the customer hands over. "Split payment" adds a line already filled with what is still unpaid.</p>
      <div class="totals"><div class="received-line"><span>Amount received</span><span data-paid>₱0.00</span></div>'
      . ($showChange ? '<div><span>Change</span><span data-change>—</span></div>' : '') . '</div>
    </div>';
}

/** Item <option>s carrying type, price, unit, stock and decimal flags for the row scripts. */
function item_picker_options(array $items, bool $showPrice = false): string
{
    $h = '<option value="">Choose an item</option>';
    foreach ($items as $i) {
        if (($i['item_type'] ?? '') === 'Tool/Equipment' && !empty($i['is_sellable'])) continue;   // tools for sale are not lent out
        $label = $i['name'] . ' (' . $i['item_code'] . ')' . ($showPrice && $i['item_type'] === 'Consumable' ? ' · ' . money($i['selling_price']) . '/' . $i['unit_of_measure'] : '');
        $h .= '<option value="' . e($i['item_id']) . '" data-type="' . e($i['item_type']) . '" data-unit="' . e($i['unit_of_measure'])
            . '" data-stock="' . e(qty($i['qty_available'])) . '" data-avail="' . e((float)$i['qty_available']) . '" data-price="' . e($i['selling_price'] ?? 0) . '" data-decimal="' . (!empty($i['allows_decimal']) ? '1' : '0') . '">'
            . e($label) . '</option>';
    }
    return $h;
}

/** Supplier picker with an inline "new supplier" panel. $selectedName pre-selects by name. */
function supplier_picker(?string $selectedName = null, bool $required = true): string
{
    $sel = (string) post('supplier_id', '');
    $opts = '<option value="">' . ($required ? 'Choose a supplier' : 'No supplier') . '</option>';
    foreach (InventoryService::suppliers() as $sp) {
        $on = $sel !== '' ? $sel === (string)$sp['supplier_id'] : ($selectedName !== null && $selectedName === $sp['name']);
        $opts .= '<option value="' . e($sp['supplier_id']) . '"' . ($on ? ' selected' : '') . '>' . e($sp['name']) . '</option>';
    }
    $opts .= '<option value="new"' . ($sel === 'new' ? ' selected' : '') . '>+ Add a new supplier…</option>';
    return '<div data-supplier-picker><label for="supplier_id">Supplier <span class="hint">Picked from the item when known</span></label><select id="supplier_id" name="supplier_id"' . ($required ? ' required' : '') . '>' . $opts . '</select>'
        . '<div class="new-entry" data-new-supplier' . ($sel === 'new' ? '' : ' hidden') . '>'
        . '<div><label for="new_supplier_name">New supplier name</label><input id="new_supplier_name" name="new_supplier_name" type="text" value="' . e(post('new_supplier_name')) . '"></div>'
        . '<div><label for="new_supplier_contact">Contact <span class="hint">Optional</span></label><input id="new_supplier_contact" name="new_supplier_contact" type="tel" value="' . e(post('new_supplier_contact')) . '"></div>'
        . '</div></div>';
}

/** Customer picker with an inline "new customer" panel. */
function customer_picker(string $label, bool $walkIn, string $hint = ''): string
{
    $sel = (string) post('customer_id', '');
    return '<div data-customer-picker><label for="customer_id">' . e($label) . ($hint ? ' <span class="hint">' . e($hint) . '</span>' : '') . '</label>'
        . '<select id="customer_id" name="customer_id"' . ($walkIn ? '' : ' required') . '>' . CustomerService::options($sel, $walkIn) . '</select>'
        . '<div class="new-entry" data-new-customer' . ($sel === 'new' ? '' : ' hidden') . '>'
        . '<div><label for="new_customer_name">New customer name</label><input id="new_customer_name" name="new_customer_name" type="text" value="' . e(post('new_customer_name')) . '"></div>'
        . '<div><label for="new_customer_contact">Contact number <span class="hint">Optional</span></label><input id="new_customer_contact" name="new_customer_contact" type="tel" value="' . e(post('new_customer_contact')) . '"></div>'
        . '<div><label for="new_customer_type">Customer type</label><select id="new_customer_type" name="new_customer_type">'
        . implode('', array_map(fn($t) => '<option' . (post('new_customer_type', $walkIn ? 'Walk-in' : 'Business') === $t ? ' selected' : '') . '>' . $t . '</option>', ['Walk-in', 'Contractor', 'Business', 'Residential']))
        . '</select></div></div></div>';
}

/** Small helper for <option> lists. */
function options(array $rows, string $valueKey, string $labelKey, $selected = null, string $placeholder = ''): string
{
    $h = $placeholder !== '' ? '<option value="">' . e($placeholder) . '</option>' : '';
    foreach ($rows as $r) {
        $v = (string)$r[$valueKey];
        $h .= '<option value="' . e($v) . '"' . ((string)$selected === $v ? ' selected' : '') . '>' . e($r[$labelKey]) . '</option>';
    }
    return $h;
}

/** Page numbers under a long table: first, last, two on each side of the current page, … for gaps. */
function pager_html(int $pg, int $pages, callable $urlFor, string $label = 'Pages'): string
{
    if ($pages <= 1) return '';
    $show = array_unique(array_filter([1, $pg - 2, $pg - 1, $pg, $pg + 1, $pg + 2, $pages], fn($n) => $n >= 1 && $n <= $pages));
    sort($show);
    $h = '<nav class="pager no-print" aria-label="' . e($label) . '">';
    $h .= $pg > 1 ? '<a class="btn btn-secondary btn-sm" href="' . e($urlFor($pg - 1)) . '">‹ Previous</a>'
                  : '<span class="btn btn-secondary btn-sm is-disabled" aria-disabled="true">‹ Previous</span>';
    $h .= '<span class="pager-nums">';
    $prev = 0;
    foreach ($show as $n) {
        if ($n - $prev > 1) $h .= '<span class="pager-gap">…</span>';
        $h .= $n === $pg ? '<span class="pager-num is-current" aria-current="page">' . $n . '</span>'
                         : '<a class="pager-num" href="' . e($urlFor($n)) . '">' . $n . '</a>';
        $prev = $n;
    }
    $h .= '</span>';
    $h .= $pg < $pages ? '<a class="btn btn-secondary btn-sm" href="' . e($urlFor($pg + 1)) . '">Next ›</a>'
                       : '<span class="btn btn-secondary btn-sm is-disabled" aria-disabled="true">Next ›</span>';
    return $h . '</nav>';
}

