<?php
// Excel downloads for Reports and the Sales summary. Same role rules as the screens.
$isDate = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
$type = $_GET['type'] ?? '';
$from = $isDate($_GET['from'] ?? null) ? $_GET['from'] : date('Y-m-01');
$to = $isDate($_GET['to'] ?? null) ? $_GET['to'] : today();
$user = current_user();
$period = 'Period: ' . fmt_d($from) . ' to ' . fmt_d($to) . ' · Exported ' . date('M j, Y g:i A') . ' by ' . $user['full_name'];

if ($type === 'summary') {
    if (!has_role('Executive Officer', 'Accounting')) { http_response_code(403); render_message('Export restricted', 'The sales summary is for Executive Officers and Accounting.'); exit; }
    $group = array_key_exists($_GET['group'] ?? '', SummaryService::GROUPS) ? $_GET['group'] : 'monthly';
    $trend = array_map(fn($t) => ['period' => $t['period'], 'transactions' => (int)$t['transactions'], 'counter_sales_count' => (int)$t['counter_sales'], 'job_billings_count' => (int)$t['job_billings'],
        'gross_sales' => $t['gross_sales'], 'discounts' => $t['discounts'], 'vat_amount' => $t['vat'], 'counter_total' => $t['counter_total'], 'job_total' => $t['job_total'], 'net_sales' => $t['net_sales']], SummaryService::trend($group, $from, $to));
    $tot = SummaryService::totals($from, $to);
    ExportService::download('sales_summary_' . $group . '_' . $from . '_to_' . $to . '.xlsx', [
        ['name' => SummaryService::GROUPS[$group] . ' sales', 'title' => company_name() . ' — ' . SummaryService::GROUPS[$group] . ' sales summary', 'subtitle' => $period, 'rows' => $trend],
        ['name' => 'Totals', 'title' => 'Totals for the period', 'subtitle' => $period, 'rows' => [[
            'net_sales' => $tot['net_sales'], 'gross_sales' => $tot['gross_sales'], 'discounts' => $tot['discounts'], 'vat_amount' => $tot['vat'],
            'counter_total' => $tot['counter_total'], 'job_total' => $tot['job_total'], 'transactions' => $tot['transactions'], 'average_sale_amount' => round($tot['avg_ticket'], 2),
            'units_sold' => $tot['units'], 'refund_amount' => $tot['refunds']]]],
        ['name' => 'Most bought', 'title' => 'Most bought items (by quantity)', 'subtitle' => $period, 'rows' => SummaryService::topItems($from, $to, 'qty', 50)],
        ['name' => 'Top earners', 'title' => 'Top earning items (amount before VAT)', 'subtitle' => $period, 'rows' => SummaryService::topItems($from, $to, 'revenue', 50)],
        ['name' => 'Payment methods', 'title' => 'Payment methods', 'subtitle' => $period . ' · Change given: ' . money(SummaryService::changeGiven($from, $to)), 'rows' => SummaryService::paymentMix($from, $to)],
        ['name' => 'Top customers', 'title' => 'Top customers', 'subtitle' => $period, 'rows' => SummaryService::topCustomers($from, $to, 100)],
        ['name' => 'By weekday', 'title' => 'Sales by day of the week', 'subtitle' => $period, 'rows' => SummaryService::byWeekday($from, $to)],
    ]);
}

$types = SalesWorkflowService::reportTypes($user['role']);
if (!isset($types[$type])) { http_response_code(403); render_message('Export restricted', 'This report is not available for your role.'); exit; }
if ($p = SalesWorkflowService::periodProblem($type, $from, $to)) { http_response_code(400); render_message('Check the dates', $p); exit; }
if (SalesWorkflowService::isSnapshot($type)) $period = 'Current status as of ' . date('M j, Y g:i A') . ' · Exported by ' . $user['full_name'];
$rows = SalesWorkflowService::reportRows($type, $from, $to);
ExportService::download($type . (SalesWorkflowService::isSnapshot($type) ? '_as_of_' . today() : '_' . $from . '_to_' . $to) . '.xlsx', [
    ['name' => $types[$type], 'title' => company_name() . ' — ' . $types[$type], 'subtitle' => $period . ' · ' . count($rows) . ' record(s)', 'rows' => $rows],
]);
