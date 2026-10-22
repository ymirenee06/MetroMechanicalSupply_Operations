<?php
try { $s = SalesService::sale((int)($_GET['id'] ?? 0)); } catch (UserError $e) { render_message('Sale not found', $e->getMessage()); return; }
if(has_role('Sales') && (int)$s['recorded_by']!==uid()){ http_response_code(403); render_message('Sale restricted','Sales Staff can only view their own recorded transactions.'); return; }
$cfg = $GLOBALS['CONFIG'];
layout_start($s['sale_ref_no'], e($s['sale_type']) . ' · ' . e(fmt_dt($s['recorded_at'])),
             '<a class="btn btn-primary" href="' . e(url('receipt', ['id' => $s['sale_id'], 'print' => 1])) . '">Print receipt</a>'
             . (has_role('Sales','Executive Officer') ? '<a class="btn btn-secondary" href="' . e(url('pos')) . '">New sale</a>' : ''));
?>
<section class="panel receipt">
  <div class="receipt-head">
    <div><strong><?= e(company_name()) ?></strong><div class="muted small">Official Receipt <?= e($s['or_number']) ?></div></div>
    <div style="text-align:right"><?= status_badge($s['status']) ?><div class="muted small"><?= e($s['sale_ref_no']) ?></div></div>
  </div>
  <dl class="facts small" style="margin-bottom:12px">
    <dt>Customer</dt><dd><?= e($s['customer_name']) ?><?= $s['customer_contact'] ? ' · ' . e($s['customer_contact']) : '' ?></dd>
    <?php if ($s['job_ref_no']): ?><dt>Job</dt><dd><?= e($s['job_ref_no']) ?></dd><?php endif; ?>
    <dt>Recorded by</dt><dd><?= e($s['recorded_name']) ?>, drawer <?= e($s['drawer_no']) ?></dd>
  </dl>
  <table>
    <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Price</th><th class="num">Amount</th></tr></thead>
    <tbody><?php foreach ($s['lines'] as $l): ?>
      <tr><td><?= e($l['name'] ?? $l['line_type']) ?><?= $l['line_type'] !== 'Item' ? '<span class="sub">' . e($l['line_type']) . '</span>' : '' ?></td>
          <td class="num"><?= e(qty($l['qty'])) ?></td><td class="num"><?= e(money($l['unit_price'])) ?></td><td class="num"><?= e(money($l['line_total'])) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
  <div class="totals">
    <div><span>Subtotal</span><span><?= e(money($s['gross_amount'])) ?></span></div>
    <?php if ($s['discount_amount'] > 0): ?><div><span>Discount (<?= e($s['discount_reason']) ?><?= $s['approver_name'] ? ', approved by ' . e($s['approver_name']) : '' ?>)</span><span>−<?= e(money($s['discount_amount'])) ?></span></div><?php endif; ?>
    <div><span>Taxable amount</span><span><?= e(money($s['taxable_amount'])) ?></span></div>
    <div><span>VAT (<?= e($s['vat_rate']) ?>%)</span><span><?= e(money($s['vat_amount'])) ?></span></div>
    <div><strong>Total with VAT</strong><strong><?= e(money((float)$s['taxable_amount'] + (float)$s['vat_amount'])) ?></strong></div>
    <?php foreach ($s['downpayments'] as $d): ?><div><span>Less downpayment <?= e($d['or_number']) ?></span><span>−<?= e(money($d['applied_amount'])) ?></span></div><?php endforeach; ?>
    <div class="due"><span>Amount due</span><span><?= e(money($s['amount_due'])) ?></span></div>
    <?php foreach ($s['payments'] as $p): ?><div><span>Paid by <?= e($p['method']) ?><?= $p['reference_no'] ? ' (' . e($p['reference_no']) . ')' : '' ?></span><span><?= e(money($p['amount'])) ?></span></div><?php endforeach; ?>
    <?php $received = array_sum(array_map(fn($p) => (float)$p['amount'], $s['payments'])); if ($s['payments']): ?><div class="received-line"><span>Amount received</span><span><?= e(money($received)) ?></span></div><?php endif; ?>
    <?php if ($s['change_given'] > 0): ?><div class="change"><span>Change</span><span><?= e(money($s['change_given'])) ?></span></div><?php endif; ?>
  </div>
</section>
<?php layout_end();
