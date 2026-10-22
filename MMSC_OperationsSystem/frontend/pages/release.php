<?php
$error = null;
if (is_post()) {
    try {
        $ids = ReleaseService::createMixed((int)post('job_id'), (int)post('technician_id'), post_rows('lines'), post('request_id') !== '' ? (int)post('request_id') : null, uid());
        flash('ok', count($ids) > 1
            ? 'Released as 2 records (tools to be returned, consumables to be used). The technician must confirm each one on their own account.'
            : 'Released. The technician must now confirm what they received on their own account.');
        redirect('job', ['id' => (int)post('job_id')]);
    } catch (Throwable $e) { $error = friendly_error($e); }
}
$jobs = ReleaseService::releasableJobs();
foreach ($jobs as &$jb) $jb['label'] = $jb['job_ref_no'] . ' · ' . $jb['client_name'];
unset($jb);
$items = ReleaseService::releasableItems();
$approvedRequests = ReleaseService::itemRequests('Approved');
$jobSel = post('job_id', $_GET['job'] ?? '');
$reqSel = post('request_id', $_GET['request'] ?? '');

layout_start('Release items', 'Hand tools or materials to a technician for a registered job. Stock goes down, and the technician becomes accountable.');
echo error_box($error);
if (!$jobs): ?>
  <div class="panel"><div class="empty">There are no open, unbilled jobs. Register a job first.</div></div>
<?php layout_end(); return; endif; ?>
<?php if ($approvedRequests): ?>
  <div class="callout"><strong><?= count($approvedRequests) ?> approved request<?= count($approvedRequests) === 1 ? '' : 's' ?> waiting to be released.</strong>
    Pick it under "Approved request" below; the job, technician and item fill in for you.</div>
<?php endif; ?>
<form method="post" class="panel" data-release
      data-techs="<?= e(json_encode(ReleaseService::jobTechMap())) ?>"
      data-job-items="<?= e(json_encode(ReleaseService::remainingRequiredMap())) ?>"
      data-requests="<?= e(json_encode(ReleaseService::approvedRequestMap())) ?>">
  <?= csrf_field() ?>
  <div class="form-grid aligned">
    <div><label for="job_id">Job <span class="hint">Open, unbilled jobs</span></label><select id="job_id" name="job_id" required><?= options($jobs, 'job_id', 'label', $jobSel, 'Choose a job') ?></select></div>
    <div><label for="technician_id">Technician <span class="hint">Assigned to the job</span></label>
      <select id="technician_id" name="technician_id" data-selected="<?= e(post('technician_id')) ?>" required></select></div>
    <div class="full"><label for="request_id">Approved request <span class="hint">Optional · links this release to a technician's approved additional-item request</span></label>
      <select id="request_id" name="request_id"><option value="">Normal release</option><?php foreach ($approvedRequests as $rq): ?><option value="<?= e($rq['request_id']) ?>"<?= (string)$reqSel === (string)$rq['request_id'] ? ' selected' : '' ?>><?= e($rq['job_ref_no']) ?> · <?= e($rq['technician']) ?> · <?= e($rq['item_name']) ?> × <?= e(qty($rq['qty'])) ?></option><?php endforeach; ?></select></div>
  </div>

  <div data-lines="lines" class="line-editor separated" data-initial="<?= e(json_encode(post_rows('lines'))) ?>">
    <div class="editor-head"><div><h2>Items to release</h2><p class="muted small" data-required-note hidden><span></span> They are filled in below; adjust or remove rows as needed.</p></div>
      <button type="button" class="btn btn-quiet btn-sm" data-load-required>Load the job's required items</button></div>
    <div class="line-head rel-line" aria-hidden="true"><span>Type</span><span>Item</span><span>Qty</span><span>Condition</span><span>Due back</span><span></span></div>
    <div class="lines"></div>
    <template>
      <div class="line rel-line">
        <div><select name="lines[__i__][line_type]" data-line-type aria-label="Type"><option value="Tool">Tool</option><option value="Consumable">Consumable</option></select></div>
        <div><select name="lines[__i__][item_id]" data-line-item aria-label="Item"><?= item_picker_options($items) ?></select><span class="hint" data-unit></span></div>
        <div><input name="lines[__i__][qty]" type="number" step="1" min="0" value="1" data-line-qty aria-label="Quantity"></div>
        <div data-tool-only><select name="lines[__i__][condition_out]" aria-label="Condition"><option>Good</option><option>Minor Wear</option></select><span class="na">Not returned</span></div>
        <div data-tool-only><input name="lines[__i__][expected_return_date]" type="date" aria-label="Due back" min="<?= e(today()) ?>" value="<?= e(date('Y-m-d', strtotime('+7 days'))) ?>"><span class="na">—</span></div>
        <button type="button" class="x" aria-label="Remove this item">×</button>
      </div>
    </template>
    <div class="editor-foot"><button type="button" class="btn btn-secondary btn-sm" data-add>Add another item</button>
      <span class="muted small">Tools are lent and must come back by the due date. Consumables are used up on the job.</span></div>
  </div>
  <div class="btn-row"><button class="btn btn-primary" type="submit">Release to technician</button></div>
</form>
<?php layout_end();
