<?php
$error = null;
$u = current_user();
if (is_post()) {
    try {
        change_password(uid(), (string)($_POST['current'] ?? ''), (string)($_POST['new'] ?? ''), (string)($_POST['confirm'] ?? ''));
        flash('ok', 'Password changed.');
        redirect($u['role'] === 'Technician' ? 'my_items' : 'dashboard');
    } catch (Throwable $e) { $error = friendly_error($e); }
}
layout_start('My account', e($u['full_name']) . ' · ' . e($u['employee_id']) . ' · ' . e($u['role']));
echo error_box($error);
if ($u['must_change_password']): ?>
  <div class="alert alert-warn">You are using a temporary password. Set your own to continue.</div>
<?php endif; ?>
<form method="post" class="panel" style="max-width:480px">
  <?= csrf_field() ?>
  <h2>Change password</h2>
  <div class="stack">
    <div><label for="current"><?= $u['must_change_password'] ? 'Temporary password' : 'Current password' ?></label><input id="current" name="current" type="password" autocomplete="current-password" required></div>
    <div><label for="new">New password <span class="hint">At least 12 characters.</span></label><input id="new" name="new" type="password" autocomplete="new-password" minlength="12" required></div>
    <div><label for="confirm">Type the new password again</label><input id="confirm" name="confirm" type="password" autocomplete="new-password" required></div>
    <button class="btn btn-primary" type="submit">Change password</button>
  </div>
</form>
<?php layout_end();
