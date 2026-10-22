<?php
$action = $_GET['action'] ?? '';
$user = (int)($_GET['user'] ?? 0);
$rows = AdminService::auditLog($action, $user);
$per = 25;
$total = count($rows);
$pages = max(1, (int)ceil($total / $per));
$pg = min($pages, max(1, (int)($_GET['p'] ?? 1)));
$rows = array_slice($rows, ($pg - 1) * $per, $per);
$pageUrl = fn(int $n) => url('audit', array_filter(['action' => $action, 'user' => $user ?: null, 'p' => $n > 1 ? $n : null]));
$users = q_all("SELECT user_id, full_name FROM users ORDER BY full_name");
layout_start('Audit log', 'A permanent record of every important action. It cannot be edited or deleted, even by an Executive Officer.');
?>
<form class="filters" method="get">
  <input type="hidden" name="page" value="audit">
  <div><label for="action">Action</label><select id="action" name="action"><option value="">All actions</option>
    <?php foreach (AdminService::auditActions() as $a): ?><option<?= $a === $action ? ' selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
  <div><label for="user">Person</label><select id="user" name="user"><?= options($users, 'user_id', 'full_name', $user ?: '', 'Everyone') ?></select></div>
  <button class="btn btn-secondary" type="submit">Filter</button>
</form>
<p class="muted small" style="margin:-4px 0 12px"><?php if ($total): ?>Showing <strong><?= e(($pg - 1) * $per + 1) ?>–<?= e(($pg - 1) * $per + count($rows)) ?></strong> of <strong><?= e($total) ?></strong> entries, newest first.<?php else: ?>0 entries.<?php endif; ?></p>
<div class="panel" style="padding:0">
  <div class="table-wrap"><table>
    <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Record</th><th>Details</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="5"><div class="empty">Nothing matches.</div></td></tr><?php endif; ?>
    <?php foreach ($rows as $r):
        $details = array_filter([$r['old_values'] ? 'before ' . $r['old_values'] : null, $r['new_values'] ? $r['new_values'] : null]); ?>
      <tr><td class="small nowrap"><?= e(fmt_dt($r['created_at'])) ?></td><td><?= e($r['full_name'] ?? 'Unknown') ?><span class="sub"><?= e($r['ip_address'] ?? '') ?></span></td>
          <td><span class="code"><?= e($r['action']) ?></span></td><td class="small"><?= e($r['table_name'] ? $r['table_name'] . ' #' . $r['record_id'] : '—') ?></td>
          <td class="small" style="max-width:420px;word-break:break-word"><?= e(implode(' → ', $details)) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?= pager_html($pg, $pages, $pageUrl, 'Audit log pages') ?>
<?php layout_end();
