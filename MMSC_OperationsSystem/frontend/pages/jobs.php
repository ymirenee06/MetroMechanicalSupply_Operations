<?php
$filter = $_GET['filter'] ?? 'open';
$jobs = JobService::jobs($filter, has_role('Technician') ? uid() : null);
if(has_role('Technician')) $filter='assigned';
layout_start('Jobs', 'Items can only leave the stockroom for a registered, open job.',
             has_role('Executive Officer') ? '<a class="btn btn-primary" href="' . e(url('job_new')) . '">Register job</a>' : '');
?>
<?php if(!has_role('Technician')): ?><form class="filters" method="get">
  <input type="hidden" name="page" value="jobs">
  <div><label for="filter">Show</label>
    <select id="filter" name="filter" onchange="this.form.submit()">
      <?php foreach (['open' => 'Open jobs', 'unbilled' => 'Open and not billed', 'closed' => 'Closed jobs', 'all' => 'All jobs'] as $k => $v): ?>
        <option value="<?= e($k) ?>"<?= $filter === $k ? ' selected' : '' ?>><?= e($v) ?></option>
      <?php endforeach; ?>
    </select></div>
  <noscript><button class="btn btn-secondary" type="submit">Apply</button></noscript>
</form><?php endif; ?>
<div class="panel" style="padding:0">
  <div class="table-wrap"><table>
    <thead><tr><th>Job order</th><th>Client</th><th>Technicians</th><th>Date</th><th class="num">Tools out</th><th>Billing</th><th>Status</th></tr></thead>
    <tbody>
    <?php if (!$jobs): ?><tr><td colspan="7"><div class="empty">No jobs here.</div></td></tr><?php endif; ?>
    <?php foreach ($jobs as $j): ?>
      <tr><td><a href="<?= e(url('job', ['id' => $j['job_id']])) ?>"><strong class="code"><?= e($j['job_ref_no']) ?></strong></a><span class="sub"><?= e($j['service_type']) ?></span></td>
          <td><?= e($j['client_name']) ?><span class="sub"><?= e($j['job_site'] ?? '') ?></span></td>
          <td><?= e($j['technicians'] ?? '—') ?></td><td><?= e(fmt_d($j['job_date'])) ?></td>
          <td class="num"><?= (float)$j['tools_out'] > 0 ? '<strong>' . e(qty($j['tools_out'])) . '</strong>' : '<span class="muted">0</span>' ?></td>
          <td><?= status_badge($j['billing_status']) ?></td><td><?= status_badge($j['status']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php layout_end();
