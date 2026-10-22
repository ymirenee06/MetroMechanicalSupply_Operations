<?php
if (current_user()) redirect('dashboard');

$error = null;
$employee_id = '';
if (is_post()) {
    csrf_check();
    $employee_id = post('employee_id');
    try {
        if ($employee_id === '' || post('password') === '') throw new UserError('Enter your employee ID and password.');
        $u = attempt_login($employee_id, (string)($_POST['password'] ?? ''));
        redirect($u['role'] === 'Technician' ? 'my_items' : 'dashboard');
    } catch (Throwable $e) {
        $error = friendly_error($e);
    }
}
$cfg = $GLOBALS['CONFIG'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in · <?= e($cfg['app_name']) ?></title>
<link rel="icon" href="frontend/assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('frontend/assets/app.css')) ?>">
<script src="<?= e(asset('frontend/assets/app.js')) ?>" defer></script>
</head>
<body>
<div class="login">
  <div class="login-art" role="img" aria-label="Metro Mechanical Supply Corporation logo">
    <div class="caption"><strong><?= e(company_name()) ?></strong><span>Inventory, sales, and employee accountability</span></div>
  </div>
  <main class="login-form">
    <h1>Log in</h1>
    <form method="post" action="<?= e(url('login')) ?>" novalidate>
      <?= csrf_field() ?>
      <?= $error ? '<div class="alert alert-error" role="alert">' . e($error) . '</div>' : '' ?>
      <div class="field">
        <label for="employee_id">Employee ID</label>
        <input id="employee_id" name="employee_id" type="text" autocomplete="employee_id" value="<?= e($employee_id) ?>" required autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
      </div>
      <button class="btn btn-primary" type="submit">Log in</button>
      <p class="muted small" style="margin-top:22px">Forgot your password? Ask an Executive Officer to reset it.</p>
    </form>
  </main>
</div>
</body>
</html>
