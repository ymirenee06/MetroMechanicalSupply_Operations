<?php
$error = null;
if (is_post()) {
    try {
        $charge = post('charge_amount') === '' ? null : to_num(post('charge_amount'));
        ReleaseService::resolveIncident((int)post('incident_id'), post('resolution'), $charge, uid());
        flash('ok', post('resolution') === 'Repaired' ? 'Resolved as Repaired. The item is back in stock.' : 'Resolved as Written Off. The item is removed for good.');
        redirect('incidents');
    } catch (Throwable $e) { $error = friendly_error($e); }
}
$open = ReleaseService::openIncidents();
$recent = q_all("SELECT ir.*, i.name AS item_name, u.full_name AS resolver FROM incident_reports ir JOIN items i ON i.item_id = ir.item_id
                 LEFT JOIN users u ON u.user_id = ir.resolved_by WHERE ir.status = 'Resolved' ORDER BY ir.resolved_at DESC LIMIT 20");
layout_start('Incident reports', 'Damaged, missing, or lost items. Resolving one is the only way stock is removed for a loss.');
echo error_box($error);
?>
<section class="panel">
  <h2>Open</h2>
  <?php if (!$open): ?><div class="empty">No open incident reports.</div><?php endif; ?>
  <?php foreach ($open as $r): ?>
    <div class="panel" style="margin-bottom:12px">
      <div class="panel-head"><strong><?= e($r['item_name']) ?> × <?= e(qty($r['qty'])) ?> · <?= e($r['type']) ?></strong><span class="badge badge-<?= in_array($r['severity'], ['High', 'Critical'], true) ? 'bad' : 'warn' ?>"><?= e($r['severity']) ?> severity</span></div>
      <p style="margin:0 0 10px"><?= e($r['description']) ?></p>
      <dl class="facts small"><dt>From</dt><dd><?= e($r['source']) ?></dd><dt>Responsible</dt><dd><?= e($r['employee'] ?? 'Not known') ?></dd>
        <dt>Job</dt><dd><?= e($r['job_ref_no'] ?? '—') ?></dd><dt>Found</dt><dd><?= e(fmt_d($r['date_discovered'])) ?> · severity <?= e($r['severity']) ?></dd></dl>
      <form method="post" class="inline-form" style="margin-top:12px">
        <?= csrf_field() ?><input type="hidden" name="incident_id" value="<?= e($r['incident_id']) ?>">
        <div><label>Charge to employee <span class="hint">Optional</span></label><input name="charge_amount" type="number" min="0" step="0.01"></div>
        <button class="btn btn-primary" name="resolution" value="Repaired" type="submit">Repaired, back to stock</button>
        <button class="btn btn-danger" name="resolution" value="Written Off" type="submit">Write off</button>
      </form>
    </div>
  <?php endforeach; ?>
</section>
<?php if ($recent): ?>
<section class="panel" style="padding:0"><div class="panel-head" style="padding:16px 20px 0"><h2>Recently resolved</h2></div>
  <div class="table-wrap"><table><thead><tr><th>Item</th><th>Type</th><th>Resolution</th><th>By</th></tr></thead><tbody>
  <?php foreach ($recent as $r): ?><tr><td><?= e($r['item_name']) ?> × <?= e(qty($r['qty'])) ?></td><td><?= e($r['type']) ?></td><td><?= e($r['resolution']) ?></td><td><?= e($r['resolver']) ?><span class="sub"><?= e(fmt_dt($r['resolved_at'])) ?></span></td></tr><?php endforeach; ?>
  </tbody></table></div></section>
<?php endif; ?>
<?php layout_end();
