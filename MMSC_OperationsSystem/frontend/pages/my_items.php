<?php
$error = null;
if (is_post()) {
    try {
        switch (post('action')) {
            case 'confirm_release':
                $ok = post('decision') === 'confirm';
                ReleaseService::confirm((int)post('release_id'), uid(), $ok, post('note'));
                flash('ok', $ok ? 'Release confirmed.' : 'Release disputed.'); break;
            case 'leftover':
                ReleaseService::declareLeftover((int)post('release_item_id'), to_num(post('qty')), uid());
                flash('ok', 'Leftover declaration saved.'); break;
            case 'transfer':
                ReleaseService::createTransfer(uid(), (int)post('assignment_id'), (int)post('to_technician_id'), to_num(post('qty')), post('condition'), post('notes'));
                flash('ok', 'Transfer request sent to the receiving technician.'); break;
            case 'cancel_transfer':
                ReleaseService::cancelTransfer((int)post('transfer_id'), uid());
                flash('ok', 'Transfer cancelled. The tool stays with you.'); break;
            case 'confirm_transfer':
                ReleaseService::confirmTransfer((int)post('transfer_id'), uid(), post('decision') === 'confirm');
                flash('ok', 'Transfer response recorded.'); break;
            case 'extension':
                ReleaseService::requestExtension(uid(), (int)post('assignment_id'), post('new_date'), post('reason'));
                flash('ok', 'Return-date extension sent for Executive Officer approval.'); break;
            case 'confirm_return':
                ReleaseService::confirmReturn((int)post('tool_return_id'), uid(), post('decision') === 'confirm', post('note'));
                flash('ok', post('decision') === 'confirm' ? 'Return confirmed.' : 'Return disputed.'); break;
            case 'additional':
                ReleaseService::requestAdditionalItem(uid(), (int)post('job_id'), (int)post('item_id'), to_num(post('qty')), post('reason'));
                flash('ok', 'Request sent. It now waits for an Executive Officer; you can follow its status under My requests.'); break;
            default: throw new UserError('Unknown action.');
        }
        redirect('my_items');
    } catch (Throwable $e) { $error = friendly_error($e); }
}
$pending = ReleaseService::pendingFor(uid());
$tools = ReleaseService::holdings(uid());
$undeclared = ReleaseService::undeclaredFor(uid());
$pendingTransfers = ReleaseService::transfersFor(uid());
$sentTransfers = ReleaseService::transfersSentBy(uid());
$pendingReturns = ReleaseService::pendingReturnsFor(uid());
 $jobs = q_all("SELECT j.* FROM jobs j JOIN job_technicians jt ON jt.job_id=j.job_id WHERE jt.technician_id=? AND jt.removed_at IS NULL AND j.status='Open' AND j.billing_status='Unbilled' ORDER BY j.job_id DESC", [uid()]);
$myRequests = ReleaseService::requestsFor(uid());
$items = InventoryService::items('', '');
$technicians = ReleaseService::technicians();

layout_start('My items', 'Technician workspace for confirmations, requests, transfers, returns, and consumable declarations.');
echo error_box($error);
?>
<section class="panel">
  <div class="panel-head"><h2>Release confirmations</h2><?php if ($pending): ?><span class="badge badge-warn"><?= count($pending) ?> waiting</span><?php endif; ?></div>
  <?php if (!$pending): ?><div class="empty">Nothing is waiting for your confirmation.</div><?php endif; ?>
  <?php foreach ($pending as $r): ?>
    <div class="panel" style="margin-bottom:12px">
      <p style="margin-top:0"><strong><?= e($r['job_ref_no']) ?> · <?= e($r['client_name']) ?></strong><br><span class="muted small"><?= e($r['release_type']) ?> released by <?= e($r['released_name']) ?> · <?= e(fmt_dt($r['released_at'])) ?></span></p>
      <table><tbody><?php foreach ($r['lines'] as $l): ?><tr><td><?= e($l['name']) ?><span class="sub"><?= e($l['condition_out'] ?: 'Consumable') ?></span></td><td class="num"><strong><?= e(qty($l['qty'])) ?></strong> <?= e($l['unit_of_measure']) ?></td></tr><?php endforeach; ?></tbody></table>
      <form method="post" class="inline-form" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="confirm_release"><input type="hidden" name="release_id" value="<?= e($r['release_id']) ?>"><input name="note" type="text" placeholder="Required when disputing"><button class="btn btn-primary" name="decision" value="confirm">Confirm received</button><button class="btn btn-danger" name="decision" value="dispute">Dispute</button></form>
    </div>
  <?php endforeach; ?>
</section>

<div class="grid-2">
<section class="panel">
  <div class="panel-head"><h2>Tools you hold</h2><span class="muted small"><?= count($tools) ?> active holding(s)</span></div>
  <?php if (!$tools): ?><div class="empty">You are not holding any tools.</div><?php else: ?>
  <?php foreach ($tools as $t): ?>
    <div class="panel" style="margin:0 0 10px">
      <strong><?= e($t['item_name']) ?> × <?= e(qty($t['qty_held'])) ?></strong>
      <span class="sub"><?= e($t['job_ref_no']) ?> · due <?= e(fmt_d($t['expected_return_date'])) ?> <?= $t['is_overdue'] ? status_badge('Overdue') : '' ?></span>
      <?php if ($t['status']==='Out'): ?>
      <div class="tool-actions"><details><summary>Transfer to another technician</summary>
      <form method="post" class="stack" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="transfer"><input type="hidden" name="assignment_id" value="<?= e($t['assignment_id']) ?>"><div class="form-grid"><div><label>Transfer to</label><select name="to_technician_id" required><?php $mates = q_all("SELECT u.user_id, u.full_name FROM job_technicians jt JOIN users u ON u.user_id = jt.technician_id WHERE jt.job_id = ? AND jt.removed_at IS NULL AND u.user_id <> ? AND u.status = 'Active'", [$t['job_id'], uid()]); if (!$mates): ?><option value="">No other technician on this job</option><?php endif; foreach($mates as $tt): ?><option value="<?= e($tt['user_id']) ?>"><?= e($tt['full_name']) ?></option><?php endforeach; ?></select></div><div><label>Qty</label><input name="qty" type="number" min="1" max="<?= e(qty($t['qty_held'])) ?>" step="1" value="<?= e(qty($t['qty_held'])) ?>" required></div><div><label>Condition</label><select name="condition"><option>Good</option><option>Minor Wear</option><option>Damaged</option></select></div><div><label>Notes</label><input name="notes" type="text"></div></div><button class="btn btn-secondary btn-sm">Request transfer</button></form>
      </details><details><summary>Ask for a later return date</summary>
      <form method="post" class="stack" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="extension"><input type="hidden" name="assignment_id" value="<?= e($t['assignment_id']) ?>"><div class="form-grid"><div><label>New return date</label><input type="date" name="new_date" min="<?= e(date('Y-m-d', strtotime($t['expected_return_date'].' +1 day'))) ?>" required></div><div><label>Reason</label><input name="reason" type="text" required></div></div><button class="btn btn-quiet btn-sm">Request extension</button></form></details></div>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
</section>

<section class="panel">
  <div class="panel-head"><h2>Transfers to you</h2><?php if ($pendingTransfers): ?><span class="badge badge-warn"><?= count($pendingTransfers) ?> waiting</span><?php endif; ?></div>
  <div class="transfers-in">
  <?php if (!$pendingTransfers): ?><div class="empty">No tool transfers are waiting for you.</div><?php endif; ?>
  <?php foreach ($pendingTransfers as $t): ?>
    <div class="panel transfer-card">
      <strong><?= e($t['item_name']) ?> × <?= e(qty($t['qty'])) ?></strong>
      <span class="sub"><?= e($t['job_ref_no']) ?> · from <?= e($t['from_technician']) ?> · condition <?= e($t['condition']) ?><?= $t['notes'] ? ' · ' . e($t['notes']) : '' ?></span>
      <form method="post" class="inline-form" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="confirm_transfer"><input type="hidden" name="transfer_id" value="<?= e($t['transfer_id']) ?>">
        <button class="btn btn-primary btn-sm" name="decision" value="confirm">Accept</button><button class="btn btn-danger btn-sm" name="decision" value="decline">Decline</button></form>
    </div>
  <?php endforeach; ?>
  </div>

  <div class="panel-head transfers-out-head"><h2>Transfers you sent</h2><span class="muted small">Waiting for the other technician, or already answered</span></div>
  <div class="transfers-out">
  <?php if (!$sentTransfers): ?><div class="empty">You have not sent any tool transfers recently.</div><?php endif; ?>
  <?php foreach ($sentTransfers as $t): $waiting = $t['transfer_status'] === 'Awaiting Confirmation'; ?>
    <div class="panel transfer-card">
      <div class="transfer-top"><strong><?= e($t['item_name']) ?> × <?= e(qty($t['qty'])) ?></strong><?= status_badge($waiting ? 'Pending' : $t['transfer_status']) ?></div>
      <span class="sub"><?= e($t['job_ref_no']) ?> · to <?= e($t['to_technician']) ?> · sent <?= e(fmt_dt($t['created_at'])) ?></span>
      <?php if ($waiting): ?>
      <form method="post" class="inline-form" style="margin-top:8px" data-confirm="Cancel this transfer? The tool stays with you."><?= csrf_field() ?><input type="hidden" name="action" value="cancel_transfer"><input type="hidden" name="transfer_id" value="<?= e($t['transfer_id']) ?>">
        <span class="muted small">Waiting for <?= e($t['to_technician']) ?> to accept.</span><button class="btn btn-quiet btn-sm" type="submit">Cancel transfer</button></form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  </div>
</section>
</div>

<section class="panel">
  <div class="panel-head"><h2>Returns awaiting your confirmation</h2></div>
  <?php if (!$pendingReturns): ?><div class="empty">No return inspections are waiting for you.</div><?php else: foreach($pendingReturns as $r): ?><div class="panel" style="margin:0 0 10px"><strong><?= e($r['item_name']) ?> × <?= e(qty($r['qty_returned'])) ?></strong><span class="sub"><?= e($r['job_ref_no']) ?> · condition <?= e($r['condition_in']) ?></span><form method="post" class="inline-form" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="confirm_return"><input type="hidden" name="tool_return_id" value="<?= e($r['tool_return_id']) ?>"><input name="note" type="text" placeholder="Required when disputing"><button class="btn btn-primary btn-sm" name="decision" value="confirm">Confirm return</button><button class="btn btn-danger btn-sm" name="decision" value="dispute">Dispute</button></form></div><?php endforeach; endif; ?>
</section>

<div class="grid-2">
<section class="panel"><div class="panel-head"><h2>Consumable leftovers</h2><span class="muted small">Declare the unused quantity only.</span></div><?php if (!$undeclared): ?><div class="empty">Nothing to declare.</div><?php endif; ?><?php foreach($undeclared as $u): ?><form method="post" class="leftover-row"><?= csrf_field() ?><input type="hidden" name="action" value="leftover"><input type="hidden" name="release_item_id" value="<?= e($u['release_item_id']) ?>">
  <div class="lo-name"><strong><?= e($u['name']) ?></strong><span class="sub"><?= e($u['job_ref_no']) ?> · released <?= e(qty($u['qty'])) ?> <?= e($u['unit_of_measure']) ?></span></div>
  <div class="lo-entry"><label for="lo<?= e($u['release_item_id']) ?>">Unused</label><input id="lo<?= e($u['release_item_id']) ?>" name="qty" type="number" min="0" max="<?= e(qty($u['qty'])) ?>" step="<?= $u['allows_decimal']?'0.01':'1' ?>" value="0" required><span class="muted small"><?= e($u['unit_of_measure']) ?></span><button class="btn btn-secondary btn-sm">Declare</button></div>
</form><?php endforeach; ?></section>
<section class="panel"><div class="panel-head"><h2>Additional item request</h2></div>
<?php if (!$jobs): ?><div class="empty">You have no open job to request items for.</div><?php else: ?>
<form method="post" class="form-grid" data-request-form><?= csrf_field() ?><input type="hidden" name="action" value="additional">
  <div class="full"><label>Open job</label><select name="job_id" required><?php foreach($jobs as $j): ?><option value="<?= e($j['job_id']) ?>"<?= (string)post('job_id') === (string)$j['job_id'] ? ' selected' : '' ?>><?= e($j['job_ref_no']) ?> · <?= e($j['client_name']) ?></option><?php endforeach; ?></select></div>
  <div class="full lines" style="display:block"><div class="line rel-line consumable" style="grid-template-columns:1fr;border:0;padding:0">
    <div><label>Type</label><select name="line_type" data-line-type><option value="Consumable">Consumable (to be used)</option><option value="Tool">Tool / equipment (to be returned)</option></select></div>
    <div><label>Item</label><select name="item_id" required data-line-item><?= item_picker_options(array_values(array_filter($items, fn($i) => !$i['is_archived']))) ?></select><span class="hint" data-unit></span></div>
    <div><label>Quantity</label><input name="qty" type="number" min="0" step="1" required data-line-qty></div>
  </div></div>
  <div class="full"><label>Reason</label><textarea name="reason" required></textarea></div>
  <div class="full"><button class="btn btn-primary">Send request</button></div>
</form><?php endif; ?></section>
</div>

<section class="panel">
  <div class="panel-head"><h2>My requests</h2><span class="muted small">Pending → Approved or Denied by an Executive Officer → Released by Logistics</span></div>
  <?php if (!$myRequests): ?><div class="empty">You have not requested any additional items.</div><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th>Requested</th><th>Job</th><th>Item</th><th class="num">Qty</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($myRequests as $r): ?>
    <tr><td><?= e(fmt_dt($r['requested_at'])) ?></td><td><?= e($r['job_ref_no']) ?></td><td><?= e($r['item_name']) ?><span class="sub"><?= e($r['reason']) ?></span></td>
        <td class="num"><?= e(qty($r['qty'])) ?> <?= e($r['unit_of_measure']) ?></td>
        <td><?= status_badge($r['status']) ?><?php if ($r['decided_name']): ?><span class="sub"><?= e($r['status'] === 'Denied' ? 'Denied' : 'Approved') ?> by <?= e($r['decided_name']) ?><?= $r['decision_reason'] ? ': ' . e($r['decision_reason']) : '' ?></span><?php endif; ?>
          <?php if ($r['status'] === 'Approved'): ?><span class="sub">Waiting for Logistics to release it.</span><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php layout_end();
