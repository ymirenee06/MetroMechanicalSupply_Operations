<?php
$error = null;
$jobId = (int)($_GET['job'] ?? post('job_id', 0));
if (is_post()) {
    try {
        $r = SalesService::billJob(uid(), $jobId, $_POST, post_rows('payments'));
        flash('ok', 'Job billed as ' . $r['ref'] . '.' . ($r['received'] > 0 ? ' Received ' . money($r['received']) . '.' : '') . ($r['change'] > 0 ? ' Give ' . money($r['change']) . ' change.' : ''));
        redirect('sale', ['id' => $r['sale_id']]);
    } catch (Throwable $e) { $error = friendly_error($e); }
}
$s = SalesService::mySession(uid());
layout_start('Job billing', 'Materials are billed from what the technician actually used. The service fee is the amount agreed on the job.');
echo error_box($error);

if (!$s): ?>
  <div class="panel"><div class="empty">Open your drawer first. <a href="<?= e(url('drawer')) ?>">Open a drawer</a></div></div>
<?php layout_end(); return; endif;

if (!$jobId):
    $ready = SalesService::billableJobs(); ?>
  <section class="panel" style="padding:0">
    <div class="panel-head" style="padding:16px 20px 0"><h2>Ready to bill</h2><span class="muted small">Material usage must be declared; other follow-up can continue afterward.</span></div>
    <div class="table-wrap"><table><thead><tr><th>Job order</th><th>Client</th><th class="num">Service fee</th><th class="num">Estimated total</th><th></th></tr></thead><tbody>
    <?php if (!$ready): ?><tr><td colspan="5"><div class="empty">No jobs are ready to bill.</div></td></tr><?php endif; ?>
    <?php foreach ($ready as $j): ?>
      <tr><td class="code"><?= e($j['job_ref_no']) ?></td><td><?= e($j['client_name']) ?></td><td class="num"><?= e(money($j['service_fee'])) ?></td><td class="num"><?= $j['estimated_total'] !== null ? e(money($j['estimated_total'])) : '—' ?></td>
          <td class="num"><a class="btn btn-primary btn-sm" href="<?= e(url('billing', ['job' => $j['job_id']])) ?>">Bill</a></td></tr>
    <?php endforeach; ?></tbody></table></div>
  </section>
<?php layout_end(); return; endif;

$p = SalesService::billingPreview($jobId); ?>
<form method="post" data-totals data-fixed-gross="<?= e($p['gross']) ?>" data-downpayments="<?= e($p['dp_total']) ?>" data-vat-rate="<?= e($p['vat_rate']) ?>">
  <?= csrf_field() ?><input type="hidden" name="job_id" value="<?= e($jobId) ?>">
  <div class="split">
    <section class="panel">
      <div class="panel-head"><h2><?= e($p['job']['job_ref_no']) ?> · <?= e($p['job']['client_name']) ?></h2><a href="<?= e(url('job', ['id' => $jobId])) ?>">Open job</a></div>
      <?php foreach ($p['problems'] as $pr): ?><div class="alert alert-warn"><?= e($pr) ?></div><?php endforeach; ?>
      <?php foreach ($p['warnings'] as $wr): ?><div class="alert alert-warn"><?= e($wr) ?></div><?php endforeach; ?>
      <div class="table-wrap"><table>
        <thead><tr><th>Line</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Total</th></tr></thead>
        <?php $mat = array_filter($p['lines'], fn($l) => $l['type'] === 'Job Material'); $svc = array_filter($p['lines'], fn($l) => $l['type'] !== 'Job Material'); ?>
        <tbody>
          <tr><td colspan="4" class="small" style="background:#F7F9FB"><strong>Materials used</strong></td></tr>
          <?php if (!$mat): ?><tr><td colspan="4" class="muted small">No consumables were released and used on this job, so no materials are billed.</td></tr><?php endif; ?>
          <?php foreach ($mat as $l): ?>
          <tr><td><?= e($l['label']) ?><span class="sub"><?= e($l['detail']) ?></span></td><td class="num"><?= e(qty($l['qty'])) ?> <?= e($l['unit']) ?></td><td class="num"><?= e(money($l['price'])) ?></td><td class="num"><?= e(money($l['total'])) ?></td></tr>
          <?php endforeach; ?>
          <tr><td colspan="4" class="small" style="background:#F7F9FB"><strong>Service</strong></td></tr>
          <?php foreach ($svc as $l): ?>
          <tr><td><?= e($l['label']) ?><span class="sub"><?= e($l['detail']) ?></span></td><td class="num"><?= e(qty($l['qty'])) ?></td><td class="num"><?= e(money($l['price'])) ?></td><td class="num"><?= e(money($l['total'])) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot><tr><td colspan="3">Total</td><td class="num"><?= e(money($p['gross'])) ?></td></tr></tfoot>
      </table></div>
      <?php if (!$p['problems']) echo payment_editor(); ?>
    </section>
    <aside class="panel">
      <h2>Receipt</h2>
      <div class="stack"><div><label for="or_number">OR number</label><input id="or_number" name="or_number" type="text" value="<?= e(post('or_number')) ?>" required<?= $p['problems'] ? ' disabled' : '' ?>></div></div>
      <div class="totals">
        <div><span>Subtotal</span><span data-gross><?= e(money($p['gross'])) ?></span></div>
        <div><span>Discount</span><span data-disc>−₱0.00</span></div>
        <div><span>Taxable amount</span><span data-taxable><?= e(money($p['taxable'])) ?></span></div>
        <div><span>VAT (<?= e($p['vat_rate']) ?>%)</span><span data-vat><?= e(money($p['vat_amount'])) ?></span></div>
        <div><strong>Total with VAT</strong><strong data-total-vat><?= e(money($p['total_with_vat'])) ?></strong></div>
        <?php foreach ($p['downpayments'] as $dp): ?><div><span>Less downpayment <?= e($dp['or_number']) ?></span><span>−<?= e(money($dp['amount'])) ?></span></div><?php endforeach; ?>
        <div class="due"><span>Balance due</span><span data-due><?= e(money($p['due'])) ?></span></div>
      </div>
      <?php if ($p['due'] <= 0 && !$p['problems']): ?><p class="muted small">Downpayments cover the whole bill, so no payment is needed.</p><?php endif; ?>
      <div class="btn-row"><button class="btn btn-primary" type="submit"<?= $p['problems'] ? ' disabled' : '' ?>>Bill job</button></div>
    </aside>
  </div>
</form>
<?php layout_end();
