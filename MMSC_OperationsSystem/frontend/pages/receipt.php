<?php
// Print-only receipt: a clean page with nothing but the receipt, sized for paper.
try { $s = SalesService::sale((int)($_GET['id'] ?? 0)); } catch (UserError $e) { render_message('Sale not found', $e->getMessage()); return; }
if (has_role('Sales') && (int)$s['recorded_by'] !== uid()) { http_response_code(403); render_message('Sale restricted', 'Sales Staff can only view their own recorded transactions.'); return; }
$received = array_sum(array_map(fn($p) => (float)$p['amount'], $s['payments']));
$total = (float)$s['taxable_amount'] + (float)$s['vat_amount'];
$label = function (array $l) use ($s): string {
    if ($l['line_type'] === 'Service Fee') return 'Service fee' . ($s['service_type'] ? ' (' . $s['service_type'] . ')' : '');
    if ($l['line_type'] === 'Cancellation Fee') return 'Cancellation fee';
    return (string)($l['name'] ?? $l['line_type']);
};
$auto = isset($_GET['print']);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt <?= e($s['or_number']) ?> · <?= e($s['sale_ref_no']) ?></title>
<style>
  @page { size: A4 portrait; margin: 14mm; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #E9EDF2; color: #111; font: 12.5px/1.45 "Segoe UI", Arial, Helvetica, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .toolbar { display: flex; gap: 10px; justify-content: center; padding: 14px; }
  .toolbar button, .toolbar a { font: 600 14px "Segoe UI", Arial, sans-serif; padding: 9px 18px; border: 2px solid #1E5AA8; background: #fff; color: #1E5AA8; text-decoration: none; cursor: pointer; }
  .toolbar .primary { background: #FD4416; border-color: #FD4416; color: #071A2E; }
  .sheet { width: 182mm; max-width: 100%; margin: 0 auto 24px; background: #fff; padding: 12mm 13mm; box-shadow: 0 2px 10px rgba(0,0,0,.12); position: relative; }
  .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 2px solid #111; padding-bottom: 10px; }
  .co { font-size: 17px; font-weight: 800; }
  .doc { text-align: right; }
  .doc .t { font-size: 14px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
  .doc .no { font-size: 15px; font-weight: 700; }
  /* One column grid for everything: description 52%, qty 12%, unit price 18%, amount 18%.
     The header details use the same split (label+value = 52%, label+value = 48%), so edges line up. */
  table { width: 100%; border-collapse: collapse; table-layout: fixed; }
  .c-desc { width: 52%; } .c-qty { width: 12%; } .c-price { width: 18%; } .c-amt { width: 18%; }
  .meta { margin: 12px 0 14px; }
  .meta .c-label { width: 13%; } .meta .c-value { width: 39%; } .meta .c-value2 { width: 35%; }
  .meta th { text-align: left; font-weight: 400; color: #555; padding: 3px 6px 3px 0; vertical-align: top; }
  .meta td { padding: 3px 6px 3px 0; vertical-align: top; }
  .lines th { text-align: left; font-size: 11.5px; text-transform: uppercase; letter-spacing: .03em; border-top: 1px solid #111; border-bottom: 1px solid #111; padding: 6px; }
  .lines tbody td { padding: 6px; border-bottom: 1px solid #DDD; vertical-align: top; }
  .num, .lines th.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .lines tfoot .tnote { display: block; text-align: left; margin: 0; color: #666; font-size: 11px; font-weight: 400; }
  td .sub { display: block; color: #666; font-size: 11px; }
  .lines tfoot td { padding: 3px 6px; border: 0; vertical-align: top; }
  .lines tfoot tr.first td { padding-top: 10px; }
  .lines tfoot tr.strong td:not(:first-child) { font-weight: 700; border-top: 1px solid #111; padding-top: 5px; }
  .lines tfoot tr.due td:not(:first-child) { font-size: 15px; font-weight: 800; background: #F1F3F6; padding-top: 7px; padding-bottom: 7px; }
  .lines tfoot tr.pay-first td:not(:first-child) { padding-top: 8px; }
  .foot { display: grid; grid-template-columns: 46% 48%; justify-content: space-between; margin-top: 34px; }
  .sign { border-top: 1px solid #111; padding-top: 4px; text-align: center; color: #333; font-size: 11.5px; }
  p.note { margin-top: 18px; color: #555; font-size: 11px; text-align: center; }
  .void { position: absolute; top: 40%; left: 0; right: 0; text-align: center; font-size: 72px; font-weight: 900; color: rgba(190, 20, 20, .18); transform: rotate(-18deg); pointer-events: none; }
  @media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .sheet { width: auto; margin: 0; padding: 0; box-shadow: none; }
  }
</style>
</head>
<body>
<div class="toolbar">
  <button class="primary" type="button" onclick="window.print()">Print receipt</button>
  <a href="<?= e(url('sale', ['id' => $s['sale_id']])) ?>">Back to the sale</a>
  <?php if (has_role('Sales', 'Executive Officer')): ?><a href="<?= e(url($s['sale_type'] === 'Job Billing' ? 'billing' : 'pos')) ?>"><?= $s['sale_type'] === 'Job Billing' ? 'Bill another job' : 'New sale' ?></a><?php endif; ?>
</div>
<main class="sheet">
  <?php if ($s['status'] === 'Voided'): ?><div class="void">VOIDED</div><?php endif; ?>
  <div class="head">
    <div><div class="co"><?= e(company_name()) ?></div><div><?= e($s['sale_type'] === 'Job Billing' ? 'Job billing' : 'Counter sale') ?></div></div>
    <div class="doc"><div class="t">Official Receipt</div><div class="no">No. <?= e($s['or_number']) ?></div><div><?= e($s['sale_ref_no']) ?></div></div>
  </div>
  <table class="meta">
    <colgroup><col class="c-label"><col class="c-value"><col class="c-label"><col class="c-value2"></colgroup>
    <tr><th>Date</th><td><strong><?= e(fmt_dt($s['recorded_at'])) ?></strong></td><th>Cashier</th><td><?= e($s['recorded_name']) ?> · drawer <?= e($s['drawer_no']) ?></td></tr>
    <tr><th>Sold to</th><td><strong><?= e($s['customer_name']) ?></strong></td><th>Contact</th><td><?= e($s['customer_contact'] ?: '—') ?></td></tr>
    <?php if ($s['job_ref_no']): ?><tr><th>Job order</th><td><strong><?= e($s['job_ref_no']) ?></strong></td><th>Job site</th><td><?= e($s['job_site'] ?: '—') ?></td></tr><?php endif; ?>
  </table>
  <table class="lines">
    <colgroup><col class="c-desc"><col class="c-qty"><col class="c-price"><col class="c-amt"></colgroup>
    <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
    <tbody><?php foreach ($s['lines'] as $l): ?>
      <tr><td><?= e($label($l)) ?><?= $l['line_type'] === 'Job Material' ? '<span class="sub">Material used on the job</span>' : '' ?></td>
          <td class="num"><?= e(qty($l['qty'])) ?> <?= e($l['line_type'] === 'Service Fee' ? '' : ($l['unit_of_measure'] ?? '')) ?></td>
          <td class="num"><?= e(money($l['unit_price'])) ?></td><td class="num"><?= e(money($l['line_total'])) ?></td></tr>
    <?php endforeach; ?></tbody>
    <tfoot>
      <tr class="first"><td></td><td colspan="2">Subtotal</td><td class="num"><?= e(money($s['gross_amount'])) ?></td></tr>
      <?php if ($s['discount_amount'] > 0): ?><tr><td></td><td colspan="2">Less discount<?= $s['discount_reason'] ? '<span class="tnote">' . e($s['discount_reason']) . '</span>' : '' ?></td><td class="num">−<?= e(money($s['discount_amount'])) ?></td></tr><?php endif; ?>
      <tr><td></td><td colspan="2">VATable sales</td><td class="num"><?= e(money($s['taxable_amount'])) ?></td></tr>
      <tr><td></td><td colspan="2">VAT (<?= e(qty($s['vat_rate'])) ?>%)</td><td class="num"><?= e(money($s['vat_amount'])) ?></td></tr>
      <tr class="strong"><td></td><td colspan="2">Total amount</td><td class="num"><?= e(money($total)) ?></td></tr>
      <?php foreach ($s['downpayments'] as $d): ?><tr><td></td><td colspan="2">Less downpayment<span class="tnote">OR <?= e($d['or_number']) ?></span></td><td class="num">−<?= e(money($d['applied_amount'])) ?></td></tr><?php endforeach; ?>
      <tr class="due"><td></td><td colspan="2">Amount due</td><td class="num"><?= e(money($s['amount_due'])) ?></td></tr>
      <?php foreach ($s['payments'] as $i => $p): ?><tr<?= $i === 0 ? ' class="pay-first"' : '' ?>><td></td><td colspan="2"><?= e($p['method']) ?><?= $p['reference_no'] ? '<span class="tnote">Ref ' . e($p['reference_no']) . '</span>' : '' ?></td><td class="num"><?= e(money($p['amount'])) ?></td></tr><?php endforeach; ?>
      <?php if ($s['payments']): ?><tr class="strong"><td></td><td colspan="2">Amount received</td><td class="num"><?= e(money($received)) ?></td></tr><?php endif; ?>
      <?php if ($s['change_given'] > 0): ?><tr><td></td><td colspan="2">Change</td><td class="num"><?= e(money($s['change_given'])) ?></td></tr><?php endif; ?>
    </tfoot>
  </table>
  <div class="foot"><div class="sign">Customer's signature</div><div class="sign">Authorized representative</div></div>
  <p class="note">Thank you for your business. Please keep this receipt for returns and warranty claims.</p>
</main>
<?php if ($auto): ?><script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script><?php endif; ?>
</body>
</html>
