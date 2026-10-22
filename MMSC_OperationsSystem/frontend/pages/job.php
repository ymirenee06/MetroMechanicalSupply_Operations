<?php
$id = (int)($_GET['id'] ?? 0);
$error = null;
if (is_post()) {
    $action = post('action');
    $canAct = has_role('Executive Officer');
    if (!$canAct) { http_response_code(403); render_message('Not available for your role', 'Your role cannot perform that job action.'); return; }
    try {
        switch ($action) {
            case 'job_items': JobService::saveJobItems($id, post_rows('items'), uid()); flash('ok', 'Required items saved. The estimate was recomputed.'); break;
            case 'add_tech':  JobService::addTechnician($id, (int)post('technician_id'), uid()); flash('ok', 'Technician added.'); break;
            case 'fee':       JobService::changeFee($id, post('field'), to_num(post('value')), post('reason'), uid()); flash('ok', 'Amount changed. The old value and reason are kept.'); break;
            case 'no_charge': JobService::markNoCharge($id, post('reason'), uid()); flash('ok', 'Marked No Charge.'); break;
            case 'request_cancel_refund': SalesWorkflowService::requestCancellationRefund(uid(), $id, post('reason')); flash('ok', 'Cancellation refund request submitted for the recorded downpayments.'); break;
            case 'reopen':    JobService::reopen($id, post('reason'), uid()); flash('ok', 'Job reopened. The reason was added to the audit log.'); break;
            case 'cancel':    JobService::cancel($id, post('reason'), uid()); flash('ok', 'Job cancelled and closed.'); break;
            case 'close':     JobService::close($id, uid()); flash('ok', 'Job closed.'); break;
        }
        redirect('job', ['id' => $id]);
    } catch (Throwable $e) { $error = friendly_error($e); }
}

try { $d = JobService::detail($id); } catch (UserError $e) { render_message('Job not found', $e->getMessage()); return; }
$j = $d['job'];
if(has_role('Technician') && !q_val("SELECT 1 FROM job_technicians WHERE job_id=? AND technician_id=? AND removed_at IS NULL",[$id,uid()])) { http_response_code(403); render_message('Job restricted','Technicians can only view jobs assigned to their account.'); return; }
$canSeeJobFinance=has_role('Executive Officer','Sales','Accounting');
$open = $j['status'] === 'Open';
$dpHeld = array_sum(array_map(fn($x) => $x['status'] === 'Recorded' ? (float)$x['amount'] : 0, $d['downpayments']));

$actions = '';
if ($open && has_role('Logistics','Executive Officer')) $actions .= '<a class="btn btn-primary" href="' . e(url('release', ['job' => $id])) . '">Release items</a>';
if ($open && has_role('Executive Officer') && $dpHeld > 0) $actions .= '<a class="btn btn-secondary" href="' . e(url('cancellation_refunds')) . '">Refund downpayments</a>';
if ($open && has_role('Sales','Executive Officer') && $j['billing_status'] === 'Unbilled') $actions .= '<a class="btn btn-primary" href="' . e(url('billing', ['job' => $id])) . '">Bill this job</a>';
layout_start($j['job_ref_no'] . ' · ' . $j['client_name'], e($j['service_type']) . ' · ' . e($j['job_site'] ?? 'No site') . ' · ' . e(fmt_d($j['job_date'])), $actions);
echo error_box($error);
?>
<div class="strip">
  <a href="#"><span class="n"><?= status_badge($j['status']) ?></span><span class="l">Job status</span></a>
  <?php if($canSeeJobFinance): ?><a href="#"><span class="n"><?= status_badge($j['billing_status']) ?></span><span class="l">Billing</span></a><?php endif; ?>
  <?php if($canSeeJobFinance): ?><a href="#"><span class="n"><?= e(money($j['service_fee'])) ?></span><span class="l">Agreed service fee</span></a><a href="#"><span class="n"><?= $j['estimated_total'] !== null ? e(money($j['estimated_total'])) : '—' ?></span><span class="l">Estimated total</span></a><a href="#"><span class="n"><?= e(money($dpHeld)) ?></span><span class="l">Downpayments held</span></a><?php endif; ?>
</div>

<div class="split">
  <div>
    <section class="panel">
      <div class="panel-head"><h2>Required items</h2><?php if ($open && has_role('Logistics','Executive Officer') && $d['job_items']): ?><a class="btn btn-secondary btn-sm" href="<?= e(url('release', ['job' => $id])) ?>">Release these</a><?php endif; ?></div>
      <?php if (!$d['job_items']): ?><div class="empty">No required items were listed when this job was registered.</div><?php else: ?>
      <div class="table-wrap"><table><thead><tr><th>Item</th><th class="num">Needed</th><th class="num">Released</th><th>Status</th><?php if ($canSeeJobFinance): ?><th class="num">Est. amount</th><?php endif; ?></tr></thead><tbody>
      <?php $estMat = 0; foreach ($d['job_items'] as $ji): $rel = (float)$ji['qty_released']; $need = (float)$ji['qty']; $estMat += (float)$ji['qty'] * (float)$ji['unit_price']; ?>
        <tr><td><?= e($ji['name']) ?> <?= type_badge($ji['item_type'], false) ?><span class="sub"><?= e($ji['item_code']) ?> · <?= e(qty($ji['qty_available'])) ?> <?= e($ji['unit_of_measure']) ?> in stock</span></td>
            <td class="num"><?= e(qty($need)) ?> <?= e($ji['unit_of_measure']) ?></td><td class="num"><?= e(qty($rel)) ?></td>
            <td><?= status_badge($rel <= 0 ? 'Not released' : ($rel + 0.001 >= $need ? 'Fully released' : 'Partly released')) ?></td>
            <?php if ($canSeeJobFinance): ?><td class="num"><?= $ji['item_type'] === 'Consumable' ? e(money((float)$ji['qty'] * (float)$ji['unit_price'])) : '<span class="muted small">Lent</span>' ?></td><?php endif; ?></tr>
      <?php endforeach; ?></tbody>
      <?php if ($canSeeJobFinance): ?><tfoot><tr><td colspan="4">Consumables estimate (before VAT)</td><td class="num"><?= e(money($estMat)) ?></td></tr></tfoot><?php endif; ?>
      </table></div><?php endif; ?>
      <?php if ($open && has_role('Executive Officer') && $j['billing_status'] === 'Unbilled'): ?>
      <details style="margin-top:12px"><summary class="btn btn-quiet btn-sm" style="display:inline-flex">Edit required items</summary>
        <form method="post" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="job_items">
          <div data-lines="items" class="line-editor" data-initial="<?= e(json_encode(array_map(fn($x) => ['line_type' => $x['item_type'] === 'Tool/Equipment' ? 'Tool' : 'Consumable', 'item_id' => $x['item_id'], 'qty' => qty($x['qty'])], $d['job_items']))) ?>">
            <div class="line-head job-line" aria-hidden="true"><span>Type</span><span>Item</span><span>Qty</span><span></span><span></span></div>
            <div class="lines"></div>
            <template><div class="line job-line">
              <div><select name="items[__i__][line_type]" data-line-type aria-label="Type"><option value="Consumable">Consumable</option><option value="Tool">Tool</option></select></div>
              <div><select name="items[__i__][item_id]" data-line-item aria-label="Item"><?= item_picker_options(InventoryService::items(), true) ?></select><span class="hint" data-unit></span></div>
              <div><input name="items[__i__][qty]" type="number" step="1" min="0" value="1" data-line-qty aria-label="Quantity"></div>
              <div></div>
              <button type="button" class="x" aria-label="Remove this item">×</button>
            </div></template>
            <div class="btn-row" style="margin-top:8px"><button type="button" class="btn btn-secondary btn-sm" data-add>Add another item</button><button class="btn btn-primary btn-sm" type="submit">Save required items</button></div>
          </div>
          <p class="muted small">Saving recomputes the estimated total (service fee + consumables + VAT). The change is kept in the fee history.</p>
        </form>
      </details>
      <?php endif; ?>
    </section>

    <?php if ($d['requests']): ?>
    <section class="panel">
      <h2>Additional item requests</h2>
      <div class="table-wrap"><table><thead><tr><th>Technician</th><th>Item</th><th class="num">Qty</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($d['requests'] as $r): ?>
        <tr><td><?= e($r['technician']) ?><span class="sub"><?= e(fmt_dt($r['requested_at'])) ?></span></td><td><?= e($r['item_name']) ?><span class="sub"><?= e($r['reason']) ?></span></td>
            <td class="num"><?= e(qty($r['qty'])) ?> <?= e($r['unit_of_measure']) ?></td>
            <td><?= status_badge($r['status']) ?><?= $r['decided_name'] ? '<span class="sub">by ' . e($r['decided_name']) . ($r['decision_reason'] ? ': ' . e($r['decision_reason']) : '') . '</span>' : '' ?>
              <?php if ($r['status'] === 'Approved' && has_role('Logistics','Executive Officer')): ?><a class="btn btn-secondary btn-sm" style="margin-top:6px" href="<?= e(url('release', ['job' => $id, 'request' => $r['request_id']])) ?>">Release now</a><?php endif; ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    </section>
    <?php endif; ?>

    <?php if(has_role('Executive Officer','Logistics','Technician')): ?><section class="panel">
      <h2>Tools out on this job</h2>
      <?php if (!$d['holdings']): ?><div class="empty">No tools are out for this job.</div><?php else: ?>
      <div class="table-wrap"><table><thead><tr><th>Tool</th><th>Held by</th><th class="num">Qty</th><th>Due back</th></tr></thead><tbody>
      <?php foreach ($d['holdings'] as $h): ?>
        <tr><td><?= e($h['item_name']) ?></td><td><?= e($h['technician']) ?></td><td class="num"><?= e(qty($h['qty_held'])) ?></td>
            <td><?= $h['is_overdue'] ? status_badge('Overdue') : '' ?> <?= $h['status'] === 'Under Incident' ? status_badge('Under Incident') : '' ?> <?= e(fmt_d($h['expected_return_date'])) ?></td></tr>
      <?php endforeach; ?></tbody></table></div><?php endif; ?>
      <?php if ($d['pending_returns']): ?><p class="small" style="margin-bottom:0"><strong>Returns recorded, waiting for the technician to confirm:</strong>
        <?php foreach ($d['pending_returns'] as $pr): ?><br><?= e($pr['item_name']) ?> × <?= e(qty($pr['qty_returned'])) ?> (<?= e($pr['condition_in']) ?>) · <?= e($pr['technician']) ?> <?= status_badge($pr['tech_confirm']) ?><?php endforeach; ?></p><?php endif; ?>
    </section><?php endif; ?>

    <section class="panel">
      <h2>Materials</h2>
      <?php if (!$d['materials']): ?><div class="empty">No consumables released yet.</div><?php else: ?>
      <div class="table-wrap"><table><thead><tr><th>Item</th><th class="num">Released</th><th class="num">Came back</th><th class="num">Used (billed)</th></tr></thead><tbody>
      <?php foreach ($d['materials'] as $m): ?>
        <tr><td><?= e($m['item_name']) ?><?= $m['lines_not_declared'] ? ' ' . status_badge('Awaiting leftovers') : '' ?></td>
            <td class="num"><?= e(qty($m['qty_released'])) ?></td><td class="num"><?= e(qty($m['qty_returned'])) ?></td><td class="num"><strong><?= e(qty($m['qty_used'])) ?></strong></td></tr>
      <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>

    <?php if(has_role('Executive Officer','Logistics','Technician')): ?><section class="panel">
      <h2>Releases</h2>
      <?php if (!$d['releases']): ?><div class="empty">Nothing has been released for this job.</div><?php else: ?>
      <div class="table-wrap"><table><thead><tr><th>When</th><th>To</th><th>Items</th><th>Confirmation</th></tr></thead><tbody>
      <?php foreach ($d['releases'] as $r): ?>
        <tr><td><?= e(fmt_dt($r['released_at'])) ?><span class="sub"><?= e($r['release_type']) ?>s · by <?= e($r['released_name']) ?></span></td>
            <td><?= e($r['technician']) ?></td><td class="small"><?= e($r['items']) ?></td>
            <td><?= status_badge($r['confirm_status']) ?><?= $r['dispute_note'] ? '<span class="sub">' . e($r['dispute_note']) . '</span>' : '' ?></td></tr>
      <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section><?php endif; ?>
  </div>

  <div>
    <?php if(has_role('Executive Officer','Logistics','Sales')): ?><section class="panel">
      <h2>Technicians</h2>
      <ul style="margin:0 0 10px;padding-left:18px"><?php foreach ($d['techs'] as $t): ?><li><?= e($t['full_name']) ?></li><?php endforeach; ?></ul>
      <?php if ($open && has_role('Executive Officer')): ?>
      <?php $assigned = array_map(fn($x) => (int)$x['technician_id'], $d['techs']);
            $freeTechs = array_values(array_filter(JobService::technicians(), fn($t) => !in_array((int)$t['user_id'], $assigned, true))); ?>
      <?php if (!$freeTechs): ?>
      <p class="muted small" style="margin:0">No technicians available to add. Every active technician is already on this job.</p>
      <?php else: ?>
      <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="add_tech">
        <div><label for="technician_id">Add technician</label><select id="technician_id" name="technician_id" required><option value="">Choose a technician</option><?= options($freeTechs, 'user_id', 'full_name') ?></select></div>
        <button class="btn btn-secondary" type="submit">Add</button></form>
      <?php endif; ?>
      <?php endif; ?>
    </section><?php endif; ?>

    <?php if ($canSeeJobFinance && $d['downpayments']): ?>
    <section class="panel"><h2>Downpayments</h2>
      <table><tbody><?php foreach ($d['downpayments'] as $x): ?>
        <tr><td><?= e($x['or_number']) ?><span class="sub"><?= e(fmt_dt($x['recorded_at'])) ?></span></td><td class="num"><?= e(money($x['amount'])) ?></td><td><?= status_badge($x['status']) ?></td></tr>
      <?php endforeach; ?></tbody></table></section>
    <?php endif; ?>

    <?php if ($canSeeJobFinance && $d['billing']): ?>
    <section class="panel"><h2>Billing</h2>
      <p><a href="<?= e(url('sale', ['id' => $d['billing']['sale_id']])) ?>"><strong><?= e($d['billing']['sale_ref_no']) ?></strong></a> · OR <?= e($d['billing']['or_number']) ?></p>
      <p><?= e(money($d['billing']['gross_amount'])) ?> total, <?= e(money($d['billing']['amount_due'])) ?> collected at billing.</p></section>
    <?php endif; ?>


    <?php if (has_role('Executive Officer') && !$open && $j['billing_status'] !== 'Cancelled'): ?>
    <section class="panel">
      <h2>Reopen closed job</h2>
      <p class="muted">Use this only when a job was closed by mistake. The reason is permanently audited.</p>
      <form method="post" class="inline-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="reopen">
        <div><label for="reopen_reason">Reason</label><input id="reopen_reason" name="reason" type="text" required></div>
        <button class="btn btn-secondary" type="submit">Reopen job</button>
      </form>
    </section>
    <?php endif; ?>

    <?php if (has_role('Executive Officer') && $open): ?>
    <section class="panel">
      <h2>Executive controls</h2>
      <?php if ($j['billing_status'] === 'Unbilled'): ?>
      <form method="post" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="fee">
        <div class="form-grid">
          <div><label for="field">Change</label><select id="field" name="field"><option value="service_fee">Service fee</option><option value="estimated_total">Estimated total</option></select></div>
          <div><label for="value">New amount</label><input id="value" name="value" type="number" min="0" step="0.01" required></div>
          <div class="full"><label for="reason">Reason</label><input id="reason" name="reason" type="text" required></div>
        </div>
        <button class="btn btn-secondary" type="submit" style="min-width:190px">Save change</button>
      </form>
      <hr style="border:0;border-top:1px solid var(--line);margin:18px 0">
      <form method="post" class="reason-action" data-confirm="Mark this job No Charge? It will not be billed.">
        <?= csrf_field() ?><input type="hidden" name="action" value="no_charge">
        <div><label for="nc">No Charge reason</label><input id="nc" name="reason" type="text" placeholder="Warranty callback"></div>
        <button class="btn btn-quiet" type="submit">Mark No Charge</button></form>
      <?php if ($dpHeld > 0): ?>
      <form method="post" class="reason-action">
        <?= csrf_field() ?><input type="hidden" name="action" value="request_cancel_refund">
        <div><label for="crr">Downpayment refund reason</label><input id="crr" name="reason" type="text" required placeholder="Client cancelled the job"></div>
        <button class="btn btn-secondary" type="submit">Request downpayment refund</button></form>
      <p class="muted small">After every Recorded downpayment is refunded by Sales, the Cancel job action becomes available.</p>
      <?php endif; ?>
      <form method="post" class="reason-action" data-confirm="Cancel this job? All required operational cleanup and any recorded downpayment refunds must already be resolved.">
        <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
        <div><label for="excr">Cancellation reason</label><input id="excr" name="reason" type="text" required></div>
        <button class="btn btn-danger" type="submit">Cancel job</button></form>
      <?php endif; ?>
      <?php if ($j['billing_status'] !== 'Unbilled'): ?>
      <form method="post" data-confirm="Close this job? It can only close after operational cleanup is complete.">
        <?= csrf_field() ?><input type="hidden" name="action" value="close">
        <p class="muted">Closing is the final administrative step. Tools must be back, leftovers must be declared and received, and disputed releases must be resolved.</p>
        <button class="btn btn-primary" type="submit">Close job</button>
      </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($d['fee_changes']): ?>
    <section class="panel"><h2>Fee changes</h2><table><tbody>
      <?php foreach ($d['fee_changes'] as $f): ?><tr><td class="small"><?= e($f['field'] === 'service_fee' ? 'Service fee' : 'Estimate') ?> <?= e(money($f['old_value'])) ?> → <?= e(money($f['new_value'])) ?><span class="sub"><?= e($f['reason']) ?> · <?= e($f['full_name']) ?></span></td></tr><?php endforeach; ?>
    </tbody></table></section>
    <?php endif; ?>
  </div>
</div>
<?php layout_end();
