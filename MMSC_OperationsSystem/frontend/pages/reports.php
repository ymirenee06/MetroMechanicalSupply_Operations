<?php
$isDate = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
$type = $_GET['type'] ?? '';
$from = $isDate($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-01');
$to = $isDate($_GET['to'] ?? null) ? $_GET['to'] : today();
$error = null;
$role = current_user()['role'];
$types = SalesWorkflowService::reportTypes($role);
if (!isset($types[$type])) {
    if ($type !== '') { http_response_code(403); render_message('Report restricted', 'This report is not available for your role.'); exit; }
    $type = array_key_first($types) ?? 'stock';
}
try { $rows = SalesWorkflowService::reportRows($type, $from, $to); } catch (Throwable $e) { $rows = []; $error = friendly_error($e); }
$hide = fn($k) => str_ends_with($k, '_key') || preg_match('/^(\w+_id|session_id|recorded_by|sales_staff_id|requested_by|manual_form_no|original_datetime|billing_kind|vat_rate|ip_address|old_values)$/', $k) && $k !== 'employee_id';
// The widest reports show their key columns on screen; Excel export always has every column.
$screenCols = [
    'sales'      => ['business_date', 'sale_ref_no', 'or_number', 'sale_type', 'customer_name', 'gross_amount', 'discount_amount', 'vat_amount', 'amount_due', 'status', 'full_name'],
    'movement'   => ['created_at', 'item_code', 'name', 'movement_type', 'qty_change', 'full_name'],
    'deliveries' => ['date_received', 'dr_po_number', 'item_name', 'qty_received', 'unit_of_measure', 'supplier', 'received_name', 'encoded_name', 'verified_name', 'status'],
    'job_billing'=> ['business_date', 'sale_ref_no', 'or_number', 'job_ref_no', 'client_name', 'gross_amount', 'vat_amount', 'downpayments_applied', 'amount_due', 'status'],
    'jobs'       => ['job_ref_no', 'client_name', 'service_type', 'job_date', 'service_fee', 'estimated_total', 'status', 'billing_status'],
    'audit'      => ['created_at', 'full_name', 'action', 'table_name', 'record_id'],
    'daily_sales'=> ['business_date', 'status', 'transactions', 'gross_sales', 'discounts', 'vat_collected', 'sales_refunds', 'net_sales', 'downpayments_received', 'total_collections'],
    'materials'  => ['job_ref_no', 'client_name', 'item_name', 'qty_released', 'qty_returned', 'qty_used', 'selling_price'],
    'cash_drawer'=> ['business_date', 'drawer_no', 'sales_staff', 'opening_float', 'cash_received', 'change_given', 'cash_refunds', 'expected_cash', 'counted_cash', 'cash_difference'],
    'technician' => ['technician', 'item_name', 'job_ref_no', 'qty_held', 'expected_return_date', 'status', 'is_overdue', 'open_incidents'],
    'stock'      => ['item_code', 'name', 'item_type', 'unit_of_measure', 'reorder_point', 'physical_quantity', 'qty_checked_out', 'qty_available', 'stock_status'],
][$type] ?? null;
// Pagination: 20 rows a page by default (50 or 100 on request). Export to Excel always has every row.
$perOptions = [20, 50, 100];
$per = (int)($_GET['per'] ?? 20);
if (!in_array($per, $perOptions, true)) $per = 20;
$total = count($rows);
$pages = max(1, (int)ceil($total / $per));
$pg = min($pages, max(1, (int)($_GET['p'] ?? 1)));
$rows = array_slice($rows, ($pg - 1) * $per, $per);
$baseParams = ['type' => $type] + (SalesWorkflowService::isSnapshot($type) ? [] : ['from' => $from, 'to' => $to]) + ($per !== 20 ? ['per' => $per] : []);
$pageUrl = fn(int $n) => url('reports', $baseParams + ($n > 1 ? ['p' => $n] : []));
if ($screenCols && $rows) $rows = array_map(fn($r) => array_intersect_key(array_replace(array_fill_keys($screenCols, null), $r), array_flip($screenCols)), $rows);
$snapshot = SalesWorkflowService::isSnapshot($type);
$exportUrl = url('export', $snapshot ? ['type' => $type] : ['type' => $type, 'from' => $from, 'to' => $to]);
layout_start('Reports', 'Operational reports from the transaction ledger. Dates apply where the report is time-based. Wide reports read best in Excel.');
echo error_box($error);
?>
<form class="filters report-filters no-print" method="get" data-report-filters data-snapshot="<?= e(implode(',', SalesWorkflowService::SNAPSHOT_REPORTS)) ?>" style="grid-template-columns:minmax(240px,1.4fr) minmax(160px,1fr) minmax(160px,1fr) auto auto">
  <input type="hidden" name="page" value="reports">
  <div><label for="type">Report</label><select id="type" name="type"><?php foreach ($types as $k => $v): ?><option value="<?= e($k) ?>"<?= $type === $k ? ' selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?></select></div>
  <div><label for="from">From</label><input id="from" type="date" name="from" value="<?= e($from) ?>"<?= $snapshot ? ' disabled' : '' ?>></div>
  <div><label for="to">To</label><input id="to" type="date" name="to" value="<?= e($to) ?>"<?= $snapshot ? ' disabled' : '' ?>></div>
  <button class="btn btn-secondary" type="submit">Show report</button>
  <a class="btn btn-primary" href="<?= e($exportUrl) ?>">Export to Excel</a>
</form>
<?php if ($snapshot): ?><div class="alert alert-info no-print">This report shows the <strong>current status as of <?= e(date('M j, Y g:i A')) ?></strong>. From and To dates don't apply to it, so they are switched off.</div><?php endif; ?>
<div class="report-meta no-print">
  <p class="muted small"><?php if ($total): ?>Showing <strong><?= e(($pg - 1) * $per + 1) ?>–<?= e(($pg - 1) * $per + count($rows)) ?></strong> of <strong><?= e($total) ?></strong> record(s).<?php else: ?>0 records.<?php endif; ?> Internal ID columns are hidden here; Export to Excel includes every row and column.</p>
  <?php if ($total > 20): ?><form method="get" class="per-page"><?php foreach (['page' => 'reports'] + $baseParams as $k => $v): if ($k === 'per') continue; ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <label for="per">Rows per page</label><select id="per" name="per" onchange="this.form.submit()"><?php foreach ($perOptions as $o): ?><option<?= $o === $per ? ' selected' : '' ?>><?= $o ?></option><?php endforeach; ?></select></form><?php endif; ?>
</div>
<section class="panel report-sheet" style="padding:0"><div class="table-wrap"><table class="report-table"><thead><tr><?php if (!$rows): ?><th>Result</th><?php else: foreach (array_keys($rows[0]) as $h): if ($hide($h)) continue; ?><th><?= e(ExportService::heading($h)) ?></th><?php endforeach; endif; ?></tr></thead>
<tbody><?php if (!$rows): ?><tr><td><div class="empty">No records for the selected report and period.</div></td></tr><?php else: foreach ($rows as $r): ?><tr><?php foreach ($r as $k => $v): if ($hide($k)) continue; ?><td<?= preg_match('/(date|_at$|_no$|ref_no|or_number|item_code|^code$)/', $k) ? ' class="nowrap"' : '' ?>><?= is_numeric($v) && preg_match('/(amount|price|cost|total|fee|cash|float|refund|difference|charge|gross|taxable|due|applied|held)/', $k) && !preg_match('/^(qty_|is_)|_qty$|_count$/', $k) ? e(money($v)) : (str_starts_with($k, 'is_') && ($v === 0 || $v === 1 || $v === '0' || $v === '1') ? ($v ? 'Yes' : 'No') : (is_numeric($v) && preg_match('/^qty_|_qty$/', $k) ? e(qty($v)) : e($v === null ? '—' : $v))) ?></td><?php endforeach; ?></tr><?php endforeach; endif; ?></tbody></table></div></section>
<?= pager_html($pg, $pages, $pageUrl, 'Report pages') ?>
<?php layout_end();
