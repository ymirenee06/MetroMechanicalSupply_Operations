<?php
$error = null;
if (is_post()) {
    try {
        switch (post('action')) {
            case 'create':
                $temp = AdminService::createUser($_POST, uid());
                flash('ok', 'Account created. Give this temporary password to ' . post('full_name') . ': ' . $temp . '. They must change it when they first log in.');
                break;
            case 'reset':
                $temp = AdminService::resetPassword((int)post('user_id'), uid());
                flash('ok', 'Password reset and account unlocked. Temporary password: ' . $temp);
                break;
            case 'toggle':
                AdminService::setActive((int)post('user_id'), post('active') === '1', uid());
                flash('ok', post('active') === '1' ? 'Account reactivated.' : 'Account deactivated. Its history is kept.');
                break;
        }
        redirect('users');
    } catch (Throwable $e) { $error = friendly_error($e); }
}
$users = AdminService::users();
layout_start('Users', 'Accounts are deactivated, never deleted, so every record keeps its name.');
echo error_box($error);
?>
<div class="split">
  <section class="panel" style="padding:0">
    <div class="table-wrap"><table>
      <thead><tr><th>Name</th><th>Role</th><th>Status</th><th></th></tr></thead>
      <tbody><?php foreach ($users as $u): ?>
        <tr><td><strong><?= e($u['full_name']) ?></strong><span class="sub"><?= e($u['employee_id']) ?><?= $u['must_change_password'] ? ' · must change password' : '' ?></span></td>
            <td><?= e($u['role']) ?></td><td><?= status_badge($u['status']) ?><?= $u['failed_login_count'] ? '<span class="sub">' . e($u['failed_login_count']) . ' failed tries</span>' : '' ?></td>
            <td class="num">
              <?php if ((int)$u['user_id'] !== uid()): ?><div class="row-actions fixed cols-users">
              <form method="post" data-confirm="Reset this password? The old one stops working."><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="user_id" value="<?= e($u['user_id']) ?>">
                <button class="btn btn-quiet btn-sm" type="submit"><?= $u['status'] === 'Locked' ? 'Unlock and reset' : 'Reset password' ?></button></form>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?= e($u['user_id']) ?>"><input type="hidden" name="active" value="<?= $u['status'] === 'Inactive' ? '1' : '0' ?>">
                <button class="btn btn-quiet btn-sm" type="submit"><?= $u['status'] === 'Inactive' ? 'Reactivate' : 'Deactivate' ?></button></form>
              </div><?php else: ?><span class="muted small">You</span><?php endif; ?>
            </td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </section>
  <form method="post" class="panel">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <h2>Add an account</h2>
    <div class="stack">
      <div><label for="full_name">Full name</label><input id="full_name" name="full_name" type="text" required></div>
      <div><label for="employee_id">Employee ID</label><input id="employee_id" name="employee_id" type="text" autocomplete="off" required></div>
      <div><label for="role">Role</label><select id="role" name="role"><?php foreach (['Executive Officer', 'Sales', 'Purchasing Officer', 'Accounting', 'Logistics', 'Technician'] as $r): ?><option><?= $r ?></option><?php endforeach; ?></select></div>
      <p class="muted small">A temporary password is created for them.</p>
      <button class="btn btn-primary" type="submit">Add account</button>
    </div>
  </form>
</div>
<?php layout_end();
