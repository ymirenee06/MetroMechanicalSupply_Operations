<?php

require __DIR__ . '/backend/bootstrap.php';
require __DIR__ . '/frontend/layout.php';

$ROUTES = [
    'dashboard'        => [],
    'account'         => [],
    'items'           => ['Executive Officer', 'Purchasing Officer', 'Accounting', 'Logistics', 'Sales', 'Technician'],
    'item'            => ['Executive Officer', 'Purchasing Officer', 'Accounting', 'Logistics', 'Sales', 'Technician'],
    'item_new'        => ['Executive Officer'],
    'stock_in'        => ['Executive Officer', 'Purchasing Officer', 'Logistics'],
    'jobs'            => ['Executive Officer', 'Logistics', 'Sales', 'Accounting', 'Technician'],
    'job'             => ['Executive Officer', 'Logistics', 'Sales', 'Accounting', 'Technician'],
    'job_new'         => ['Executive Officer'],
    'release'         => ['Executive Officer', 'Logistics'],
    'returns'         => ['Executive Officer', 'Logistics'],
    'incidents'       => ['Executive Officer'],
    'my_items'        => ['Technician'],
    'drawer'          => ['Executive Officer', 'Sales'],
    'handover'        => ['Sales', 'Executive Officer'],
    'pos'             => ['Executive Officer', 'Sales'],
    'downpayment'     => ['Executive Officer', 'Sales'],
    'billing'         => ['Executive Officer', 'Sales'],
    'cancellation_refunds' => ['Sales', 'Executive Officer'],
    'sales'           => ['Executive Officer', 'Sales', 'Accounting'],
    'receipt'         => ['Executive Officer', 'Sales', 'Accounting'],
    'job_templates'   => ['Executive Officer'],
    'sale'            => ['Executive Officer', 'Sales', 'Accounting'],
    'sales_returns'   => ['Executive Officer', 'Sales'],
    'voids'           => ['Executive Officer', 'Sales'],
    'approvals'       => ['Executive Officer'],
    'physical_count'  => ['Executive Officer', 'Logistics'],
    'sales_summary'   => ['Executive Officer', 'Accounting'],
    'customers'       => ['Executive Officer'],
    'export'          => ['Executive Officer', 'Accounting', 'Sales', 'Logistics', 'Purchasing Officer'],
    'reports'         => ['Executive Officer', 'Accounting', 'Sales', 'Logistics', 'Purchasing Officer'],
    'settings'        => ['Executive Officer'],
    'daily'           => ['Executive Officer', 'Accounting'],
    'users'           => ['Executive Officer'],
    'audit'           => ['Executive Officer'],
    'setup'           => [],
];

$page = preg_replace('/[^a-z_]/', '', $_GET['page'] ?? 'dashboard');

try {
    if ($page === 'setup') { require __DIR__ . '/frontend/pages/setup.php'; exit; }
    if ($page === 'login') {
        if ((int)q_val('SELECT COUNT(*) FROM users') === 0) redirect('setup');
        require __DIR__ . '/frontend/pages/login.php'; exit;
    }
    if ($page === 'logout') {
        if (is_post()) { csrf_check(); logout(); }
        redirect('login');
    }

    $user = current_user();
    if (!$user) redirect('login');

    // A new or reset password must be changed before anything else.
    if ($user['must_change_password'] && $page !== 'account') {
        redirect('account');
    }

    if (!isset($ROUTES[$page])) { http_response_code(404); render_message('Page not found', 'That page does not exist.'); exit; }
    if ($ROUTES[$page] && !in_array($user['role'], $ROUTES[$page], true)) {
        http_response_code(403);
        render_message('Not available for your role', 'Your account (' . $user['role'] . ') cannot open this page.');
        exit;
    }
    if (is_post()) {
        csrf_check();
        if (!once_check()) {
            // The same form arrived twice (for example Enter pressed twice): keep the first save only.
            flash('warn', 'That form was already submitted, so it was saved only once.');
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url($page)));
            exit;
        }
        clean_post_input();
    }

    require __DIR__ . '/frontend/pages/' . $page . '.php';
} catch (PDOException $e) {
    http_response_code(500);
    render_message('Database problem', friendly_error($e));
}
