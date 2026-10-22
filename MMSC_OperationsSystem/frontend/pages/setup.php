<?php
// First-run setup. This page is only usable while the users table is empty.
if (current_user()) redirect('dashboard');

$error = null;

$cfg = $GLOBALS['CONFIG'];
$requireSetupKey = !empty($cfg['require_setup_key']);

if (is_post()) {
    try {
        csrf_check();
        if ($requireSetupKey) {
            $configured = trim((string)($cfg['setup_key'] ?? ''));
            if ($configured === '') {
                throw new UserError('Production setup is locked until MMSC_SETUP_KEY is configured on the server.');
            }
            if (!hash_equals($configured, (string)($_POST['setup_key'] ?? ''))) {
                throw new UserError('The setup key is incorrect.');
            }
        }
        AdminService::bootstrapFirstExecutive(
            (string)post('employee_id'),
            (string)post('full_name'),
            (string)($_POST['password'] ?? ''),
            (string)($_POST['password_confirm'] ?? ''),
            (string)post('company_name')
        );
        flash('ok', 'Initial Executive Officer account created. You can now log in and create the client\'s staff accounts.');
        redirect('login');
    } catch (Throwable $e) {
        $error = friendly_error($e);
    }
}

try {
    $hasUsers = (int)q_val('SELECT COUNT(*) FROM users') > 0;
} catch (Throwable $e) {
    $hasUsers = false;
    $error = friendly_error($e);
}

if ($hasUsers) {
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Setup complete · ' . e($cfg['app_name']) . '</title><link rel="stylesheet" href="' . e(asset('frontend/assets/app.css')) . '">
<script src="' . e(asset('frontend/assets/app.js')) . '" defer></script></head><body class="plain">'
       . '<div class="plain-box"><span class="brand-tile">' . logo_svg('#fff', 'brand-mark') . '</span><h1>Setup already completed</h1>'
       . '<p>This database already has a user account. Use the normal login screen.</p><p><a class="btn btn-primary" href="' . e(url('login')) . '">Go to login</a></p></div></body></html>';
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>First-run setup · <?= e($cfg['app_name']) ?></title>
<link rel="icon" href="frontend/assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('frontend/assets/app.css')) ?>">
</head>
<body class="plain">
<div class="plain-box" style="max-width:620px;text-align:left">
  <span class="brand-tile" style="display:inline-flex"><?= logo_svg('#fff', 'brand-mark') ?></span>
  <h1 style="margin-top:16px">Set up this installation</h1>
  <p>This first-run screen is for a blank database. If you imported the supplied company seed, its roster and Opening Inventory are already initialized and you should use the regular login screen.</p>
  <?= $error ? '<div class="alert alert-error" role="alert">' . e($error) . '</div>' : '' ?>
  <form method="post" class="panel" style="margin-top:20px">
    <?= csrf_field() ?>
    <h2>Create the first Executive Officer</h2>
    <p class="muted small">This account becomes the administrator for this installation. After logging in, use <strong>Users</strong> to create Sales, Purchasing, Accounting, Logistics, and Technician accounts.</p>
    <div class="stack">
      <div><label for="company_name">Company name</label><input id="company_name" name="company_name" type="text" maxlength="150" required value="<?= e(post('company_name')) ?>"></div>
      <?php if ($requireSetupKey): ?>
      <div><label for="setup_key">Setup key</label><input id="setup_key" name="setup_key" type="password" autocomplete="off" required><p class="muted small">This one-time key is configured on the server and prevents an unknown visitor from claiming the first administrator account.</p></div>
      <?php endif; ?>
      <div><label for="full_name">Full name</label><input id="full_name" name="full_name" type="text" required autofocus value="<?= e(post('full_name')) ?>"></div>
      <div><label for="employee_id">Employee ID</label><input id="employee_id" name="employee_id" type="text" autocomplete="employee_id" required value="<?= e(post('employee_id')) ?>"></div>
      <div><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required></div>
      <div><label for="password_confirm">Confirm password</label><input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" minlength="8" required></div>
      <button class="btn btn-primary" type="submit">Create Executive Officer</button>
    </div>
  </form>
  <p class="muted small" style="margin-top:18px">After setup, the normal login screen is the only way into the application.</p>
</div>
</body>
</html>
