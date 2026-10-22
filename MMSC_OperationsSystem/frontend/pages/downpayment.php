<?php
$error = null;
if (is_post()) {
    try {
        SalesService::recordDownpayment(uid(), (int)post('job_id'), $_POST, post_rows('payments'));
        flash('ok', 'Downpayment recorded. It will be deducted when the job is billed.');
        redirect('job', ['id' => (int)post('job_id')]);
    } catch (Throwable $e) { $error = friendly_error($e); }
}
$s = SalesService::mySession(uid());
$jobs = SalesService::downpaymentJobs();
foreach ($jobs as &$j) $j['label'] = $j['job_ref_no'] . ' · ' . $j['client_name'] . ' · up to ' . money((float)$j['estimated_total'] - (float)$j['held']) . ' more';
unset($j);
layout_start('Downpayment', 'For big jobs. The total cannot go above the job\'s estimated total.');
echo error_box($error);
if (!$s): ?>
  <div class="panel"><div class="empty">Open your drawer first. <a href="<?= e(url('drawer')) ?>">Open a drawer</a></div></div>
<?php elseif (!$jobs): ?>
  <div class="panel"><div class="empty">No open jobs have an estimated total yet. An Executive Officer sets it on the job page.</div></div>
<?php else: ?>
<form method="post" class="panel" style="max-width:760px" data-totals data-fixed-gross="0">
  <?= csrf_field() ?>
  <div class="form-grid">
    <div class="full"><label for="job_id">Job</label><select id="job_id" name="job_id" required><?= options($jobs, 'job_id', 'label', post('job_id', $_GET['job'] ?? ''), 'Choose a job') ?></select></div>
    <div><label for="amount">Downpayment amount</label><input id="amount" name="amount" type="number" min="0" step="0.01" value="<?= e(post('amount')) ?>" required></div>
    <div><label for="or_number">OR number</label><input id="or_number" name="or_number" type="text" value="<?= e(post('or_number')) ?>" required></div>
  </div>
  <?= payment_editor(false) ?>
  <div class="btn-row"><button class="btn btn-primary" type="submit">Record downpayment</button></div>
</form>
<?php endif;
layout_end();
