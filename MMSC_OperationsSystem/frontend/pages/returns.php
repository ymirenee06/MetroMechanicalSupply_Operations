<?php
$error = null;
if (is_post()) {
    try {
        if (post('action') === 'tool') {
            ReleaseService::returnTool((int)post('assignment_id'), to_num(post('qty')), post('condition'), post('notes'), uid());
            $c = post('condition');
            flash('ok', 'Return inspection recorded (' . $c . '). It is final once the technician confirms it on their own account under My items. '
                . (in_array($c, ['Good', 'Minor Wear'], true) ? 'The tool then goes back to available stock.' : 'An incident report is then created, and the tool stays under the technician until an Executive Officer resolves it.'));
        } elseif (post('action') === 'leftover') {
            ReleaseService::receiveLeftover((int)post('leftover_id'), uid());
            flash('ok', 'Leftovers received back into stock.');
        }
        redirect('returns');
    } catch (Throwable $e) { $error = friendly_error($e); }
}
$holdings = ReleaseService::holdings();
$awaiting = ReleaseService::returnsAwaitingTechnician();
$awaitingByAssignment = [];
foreach ($awaiting as $a) $awaitingByAssignment[(int)$a['assignment_id']] = $a;
$leftovers = ReleaseService::leftoversToReceive();
layout_start('Returns', 'Inspect tools as they come back, and receive declared leftovers into the stockroom.');
echo error_box($error);
?>
<section class="panel">
  <div class="panel-head"><h2>Tools out</h2><span class="muted small"><?= count($holdings) ?> assignment<?= count($holdings) === 1 ? '' : 's' ?></span></div>
  <?php if (!$holdings): ?><div class="empty">Every tool is in the stockroom.</div><?php endif; ?>
  <?php foreach ($holdings as $h): ?>
    <form method="post" class="return-line">
      <?= csrf_field() ?><input type="hidden" name="action" value="tool"><input type="hidden" name="assignment_id" value="<?= e($h['assignment_id']) ?>">
      <div><strong><?= e($h['item_name']) ?></strong> × <?= e(qty($h['qty_held'])) ?>
        <span class="sub"><?= e($h['technician']) ?> · <?= e($h['job_ref_no']) ?> · due <?= e(fmt_d($h['expected_return_date'])) ?>
        <?= $h['is_overdue'] ? status_badge('Overdue') : '' ?> <?= $h['status'] === 'Under Incident' ? status_badge('Under Incident') : '' ?></span></div>
      <?php if (isset($awaitingByAssignment[(int)$h['assignment_id']])): $aw = $awaitingByAssignment[(int)$h['assignment_id']]; ?>
        <div class="small" style="grid-column:2/-1"><?= status_badge($aw['tech_confirm'] === 'Disputed' ? 'Disputed' : 'Awaiting Confirmation') ?>
          Return of <?= e(qty($aw['qty_returned'])) ?> (<?= e($aw['condition_in']) ?>) recorded<?= $aw['tech_confirm'] === 'Disputed' ? '. The technician disputed it; an Executive Officer resolves it in the Approval queue.' : '. Waiting for ' . e($h['technician']) . ' to confirm it under My items.' ?></div>
      <?php elseif ($h['status'] === 'Out'): ?>
        <div><label>Qty back</label><input name="qty" type="number" min="1" step="1" max="<?= e(qty($h['qty_held'])) ?>" value="<?= e(qty($h['qty_held'])) ?>"></div>
        <div><label>Condition</label><select name="condition"><?php foreach (['Good', 'Minor Wear', 'Damaged-Repairable', 'Damaged-Beyond Repair', 'Missing'] as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select></div>
        <div><label>Notes <span class="hint">if not Good</span></label><input name="notes" type="text"></div>
        <button class="btn btn-primary btn-sm" type="submit">Record return</button>
      <?php else: ?>
        <div class="muted small" style="grid-column:2/-1">Waiting for an Executive Officer to resolve the incident report.</div>
      <?php endif; ?>
    </form>
  <?php endforeach; ?>
</section>

<section class="panel">
  <div class="panel-head"><h2>Returns waiting for technician confirmation</h2><span class="muted small">Stock updates when the technician confirms.</span></div>
  <?php if (!$awaiting): ?><div class="empty">No recorded returns are waiting.</div><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th>Tool</th><th>Technician / job</th><th class="num">Qty</th><th>Condition</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($awaiting as $a): ?>
    <tr><td><?= e($a['item_name']) ?></td><td><?= e($a['technician']) ?><span class="sub"><?= e($a['job_ref_no']) ?></span></td><td class="num"><?= e(qty($a['qty_returned'])) ?></td>
        <td><?= e($a['condition_in']) ?><?= $a['inspection_notes'] ? '<span class="sub">' . e($a['inspection_notes']) . '</span>' : '' ?></td>
        <td><?= status_badge($a['tech_confirm']) ?><?= $a['dispute_note'] ? '<span class="sub">' . e($a['dispute_note']) . '</span>' : '' ?></td></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>

<section class="panel">
  <div class="panel-head"><h2>Leftovers to receive</h2><span class="muted small">Declared by technicians; not yet back in the stockroom count.</span></div>
  <?php if (!$leftovers): ?><div class="empty">No declared leftovers are waiting.</div><?php else: ?>
  <div class="table-wrap"><table><thead><tr><th>Item</th><th>Job</th><th>Declared by</th><th class="num">Qty</th><th></th></tr></thead><tbody>
  <?php foreach ($leftovers as $l): ?>
    <tr><td><?= e($l['name']) ?></td><td><?= e($l['job_ref_no']) ?></td><td><?= e($l['technician']) ?><span class="sub"><?= e(fmt_dt($l['declared_at'])) ?></span></td>
        <td class="num"><strong><?= e(qty($l['qty_returned'])) ?></strong> <?= e($l['unit_of_measure']) ?></td>
        <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="leftover"><input type="hidden" name="leftover_id" value="<?= e($l['leftover_id']) ?>">
            <button class="btn btn-secondary btn-sm" type="submit">Receive</button></form></td></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php layout_end();
