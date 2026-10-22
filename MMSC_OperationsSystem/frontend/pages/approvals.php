<?php
$error=null;
if(is_post()){
  try{
    switch(post('action')){
      case 'stock': InventoryService::verifyStockIn((int)post('id'), post('decision')==='post', post('reason'), uid()); break;
      case 'request': ReleaseService::decideRequest((int)post('id'), post('decision')==='approve', post('reason'), uid()); break;
      case 'extension': ReleaseService::decideExtension((int)post('id'), post('decision')==='approve', post('reason'), uid()); break;
      case 'void': SalesWorkflowService::decideVoid((int)post('id'), post('decision')==='approve', post('reason'), uid()); break;
      case 'return': SalesWorkflowService::decideSalesReturn((int)post('id'), post('decision')==='approve', post('reason'), uid()); break;
      case 'dp': SalesWorkflowService::decideDownpaymentCorrection((int)post('id'), post('decision')==='approve', post('reason'), uid()); break;
      case 'cancel_refund': SalesWorkflowService::decideCancellationRefund((int)post('id'), post('decision')==='approve', post('reason'), uid()); break;
      case 'release_dispute': ReleaseService::resolveDispute((int)post('id'), post('decision'), post('reason'), uid()); break;
      case 'return_dispute': ReleaseService::resolveReturnDispute((int)post('id'), post('decision'), post('reason'), uid()); break;
      default: throw new UserError('Unknown approval action.');
    }
    flash('ok','Approval action recorded.'); redirect('approvals');
  }catch(Throwable $e){$error=friendly_error($e);}
}
$stock=InventoryService::stockIns('Pending Verification');
$requests=ReleaseService::itemRequests('Pending');
$extensions=ReleaseService::extensions('Pending');
$voids=SalesWorkflowService::pendingVoids();
$returns=SalesWorkflowService::pendingReturns();
$dps=SalesWorkflowService::pendingDownpaymentCorrections();
$cancelRefunds=SalesWorkflowService::pendingCancellationRefunds();
$disputedReleases=ReleaseService::disputedReleases();
$disputedReturns=ReleaseService::disputedReturns();
$handoverDisputes=q_all("SELECT ds.*,u.full_name AS outgoing,inm.full_name AS incoming FROM drawer_sessions ds JOIN users u ON u.user_id=ds.sales_staff_id LEFT JOIN users inm ON inm.user_id=ds.handover_to_user_id WHERE ds.handover_confirm='Disputed' ORDER BY ds.session_id DESC");
layout_start('Approval queue', 'Second-person approvals. The approving Executive Officer must be different from the person who recorded or requested it.');
echo error_box($error);

function approval_reason_form(string $action, int $id, string $approveLabel = 'Approve', string $rejectLabel = 'Reject'): string
{
    $approveValue = $action === 'stock' ? 'post' : 'approve';   // stock-in uses post/reject
    return '<form method="post" class="approve-form">' . csrf_field()
        . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="id" value="' . e($id) . '">'
        . '<input name="reason" type="text" aria-label="Reason or note" placeholder="Note (required to reject)">'
        . '<button class="btn btn-primary btn-sm" name="decision" value="' . e($approveValue) . '">' . e($approveLabel) . '</button>'
        . '<button class="btn btn-danger btn-sm" name="decision" value="reject">' . e($rejectLabel) . '</button></form>';
}
function dispute_form(string $action, int $id, array $choices): string
{
    $o = ''; foreach ($choices as $c) $o .= '<option>' . e($c) . '</option>';
    return '<form method="post" class="approve-form">' . csrf_field() . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="id" value="' . e($id) . '">'
        . '<input name="reason" type="text" required aria-label="Resolution note" placeholder="Resolution note"><select name="decision" aria-label="Decision">' . $o . '</select>'
        . '<button class="btn btn-primary btn-sm">Resolve</button></form>';
}

$groups = [
    'stock' => ['Deliveries to post', count($stock)], 'requests' => ['Additional-item requests', count($requests)], 'extensions' => ['Return-date extensions', count($extensions)],
    'voids' => ['Sales voids', count($voids)], 'returns' => ['Customer returns', count($returns)], 'dps' => ['Downpayment corrections', count($dps)],
    'refunds' => ['Cancellation refunds', count($cancelRefunds)], 'rdisputes' => ['Disputed releases', count($disputedReleases)], 'tdisputes' => ['Disputed return inspections', count($disputedReturns)],
    'hdisputes' => ['Disputed drawer handovers', count($handoverDisputes)],
];
$total = array_sum(array_column($groups, 1));
?>
<div class="queue-summary">
  <?php foreach ($groups as $k => [$label, $n]): ?>
    <a class="queue-chip<?= $n ? ' has' : '' ?>" href="#<?= e($k) ?>"<?= $n ? '' : ' aria-disabled="true"' ?>><span class="n"><?= e($n) ?></span><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<?php if (!$total): ?><div class="panel"><div class="empty">Nothing is waiting for approval. You're all caught up.</div></div><?php endif; ?>

<?php if ($stock): ?>
<section class="panel" id="stock" style="padding:0"><div class="panel-head pad"><h2>Deliveries to post</h2><span class="muted small">Check against the paper delivery receipt. Posting adds the stock.</span></div>
<div class="table-wrap"><table class="queue"><thead><tr><th>Item</th><th class="num">Qty</th><th>DR / PO · Supplier</th><th>Received / encoded</th><th class="act">Decision</th></tr></thead><tbody>
<?php foreach ($stock as $x): ?><tr>
  <td><strong><?= e($x['name']) ?></strong> <?= type_badge($x['item_type'], $x['is_sellable'] ?? null) ?><span class="sub code"><?= e($x['item_code']) ?></span></td>
  <td class="num"><strong><?= e(qty($x['qty_received'])) ?></strong> <?= e($x['unit_of_measure']) ?><?= $x['dr_qty_text'] ? '<span class="sub">DR says: ' . e($x['dr_qty_text']) . '</span>' : '' ?></td>
  <td><?= e($x['dr_po_number']) ?><span class="sub"><?= e($x['supplier'] ?? '—') ?> · <?= e(fmt_d($x['date_received'])) ?></span></td>
  <td><?= e($x['received_name']) ?><span class="sub">Encoded by <?= e($x['encoded_name']) ?></span></td>
  <td class="act"><?= approval_reason_form('stock', (int)$x['stock_in_id'], 'Post', 'Reject') ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>

<?php if ($requests): ?>
<section class="panel" id="requests" style="padding:0"><div class="panel-head pad"><h2>Additional-item requests</h2><span class="muted small">After approval, Logistics releases the item and the request shows Released.</span></div>
<div class="table-wrap"><table class="queue"><thead><tr><th>Technician / job</th><th>Item</th><th class="num">Qty</th><th>Reason</th><th class="act">Decision</th></tr></thead><tbody>
<?php foreach ($requests as $x): ?><tr>
  <td><strong><?= e($x['technician']) ?></strong><span class="sub"><?= e($x['job_ref_no']) ?> · <?= e($x['client_name']) ?></span></td><td><?= e($x['item_name']) ?></td>
  <td class="num"><?= e(qty($x['qty'])) ?> <?= e($x['unit_of_measure']) ?></td><td><?= e($x['reason']) ?></td>
  <td class="act"><?= approval_reason_form('request', (int)$x['request_id']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>

<?php if ($extensions): ?>
<section class="panel" id="extensions" style="padding:0"><div class="panel-head pad"><h2>Return-date extensions</h2></div>
<div class="table-wrap"><table class="queue"><thead><tr><th>Technician / tool</th><th>Current → new date</th><th>Reason</th><th class="act">Decision</th></tr></thead><tbody>
<?php foreach ($extensions as $x): ?><tr>
  <td><strong><?= e($x['technician']) ?></strong><span class="sub"><?= e($x['item_name']) ?> · <?= e($x['job_ref_no']) ?></span></td>
  <td><?= e(fmt_d($x['old_date'])) ?> → <strong><?= e(fmt_d($x['new_date'])) ?></strong></td><td><?= e($x['reason']) ?></td>
  <td class="act"><?= approval_reason_form('extension', (int)$x['extension_id']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>

<?php if ($voids): ?>
<section class="panel" id="voids" style="padding:0"><div class="panel-head pad"><h2>Sales voids</h2></div>
<div class="table-wrap"><table class="queue"><thead><tr><th>Requested by</th><th>Transaction</th><th>Reason</th><th class="act">Decision</th></tr></thead><tbody>
<?php foreach ($voids as $x): ?><tr><td><?= e($x['requester']) ?></td><td><strong><?= e($x['sale_ref_no'] ?: ('DP ' . $x['dp_or'])) ?></strong><span class="sub">OR <?= e($x['or_number'] ?: $x['dp_or']) ?></span></td><td><?= e($x['reason']) ?></td>
  <td class="act"><?= approval_reason_form('void', (int)$x['void_id']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>

<?php if ($returns): ?>
<section class="panel" id="returns" style="padding:0"><div class="panel-head pad"><h2>Customer returns</h2></div>
<div class="table-wrap"><table class="queue"><thead><tr><th>Sale</th><th>Customer</th><th class="num">Refund</th><th>Reason</th><th class="act">Decision</th></tr></thead><tbody>
<?php foreach ($returns as $x): ?><tr><td><?= e($x['sale_ref_no']) ?><span class="sub">OR <?= e($x['or_number']) ?></span></td><td><?= e($x['customer_name']) ?></td><td class="num"><?= e(money($x['refund_amount'])) ?></td><td><?= e($x['reason']) ?></td>
  <td class="act"><?= approval_reason_form('return', (int)$x['sales_return_id']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>

<?php if ($dps): ?>
<section class="panel" id="dps" style="padding:0"><div class="panel-head pad"><h2>Downpayment corrections</h2></div>
<div class="table-wrap"><table class="queue"><thead><tr><th>OR / amount</th><th>Action</th><th>Jobs</th><th>Reason</th><th class="act">Decision</th></tr></thead><tbody>
<?php foreach ($dps as $x): ?><tr><td><?= e($x['or_number']) ?><span class="sub"><?= e(money($x['amount'])) ?></span></td><td><?= e($x['action']) ?></td><td><?= e($x['old_job_ref']) ?><?= $x['new_job_ref'] ? ' → ' . e($x['new_job_ref']) : '' ?></td><td><?= e($x['reason']) ?></td>
  <td class="act"><?= approval_reason_form('dp', (int)$x['correction_id']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>

<?php if ($cancelRefunds): ?>
<section class="panel" id="refunds" style="padding:0"><div class="panel-head pad"><h2>Cancellation refunds</h2></div>
<div class="table-wrap"><table class="queue"><thead><tr><th>Job / OR</th><th class="num">Amount</th><th>Requested by</th><th>Reason</th><th class="act">Decision</th></tr></thead><tbody>
<?php foreach ($cancelRefunds as $x): ?><tr><td><strong><?= e($x['job_ref_no']) ?></strong><span class="sub">OR <?= e($x['or_number']) ?></span></td><td class="num"><?= e(money($x['amount'])) ?></td><td><?= e($x['requester']) ?></td><td><?= e($x['reason']) ?></td>
  <td class="act"><?= approval_reason_form('cancel_refund', (int)$x['request_id']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>

<?php if ($disputedReleases): ?>
<section class="panel" id="rdisputes" style="padding:0"><div class="panel-head pad"><h2>Disputed releases</h2></div>
<div class="table-wrap"><table class="queue"><thead><tr><th>Job / technician</th><th>Items</th><th>Dispute</th><th class="act">Resolution</th></tr></thead><tbody>
<?php foreach ($disputedReleases as $x): ?><tr><td><?= e($x['job_ref_no']) ?><span class="sub"><?= e($x['technician']) ?></span></td><td><?= e($x['items']) ?></td><td><?= nl2br(e($x['dispute_note'])) ?></td>
  <td class="act"><?= dispute_form('release_dispute', (int)$x['release_id'], ['Accept Recorded', 'Reject and Re-release']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>

<?php if ($disputedReturns): ?>
<section class="panel" id="tdisputes" style="padding:0"><div class="panel-head pad"><h2>Disputed return inspections</h2></div>
<div class="table-wrap"><table class="queue"><thead><tr><th>Technician / tool</th><th class="num">Qty</th><th>Dispute</th><th class="act">Resolution</th></tr></thead><tbody>
<?php foreach ($disputedReturns as $x): ?><tr><td><?= e($x['technician']) ?><span class="sub"><?= e($x['item_name']) ?> · <?= e($x['job_ref_no']) ?></span></td><td class="num"><?= e(qty($x['qty_returned'])) ?></td><td><?= e($x['dispute_note']) ?></td>
  <td class="act"><?= dispute_form('return_dispute', (int)$x['tool_return_id'], ['Accept inspection', 'Reject inspection']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php endif; ?>

<?php if ($handoverDisputes): ?>
<section class="panel" id="hdisputes"><div class="panel-head"><h2>Disputed drawer handovers</h2><a href="<?= e(url('handover')) ?>">Settle on the Shift handover page</a></div>
<ul style="margin:0;padding-left:18px"><?php foreach ($handoverDisputes as $x): ?><li>Drawer <?= e($x['drawer_no']) ?>: <?= e($x['outgoing']) ?> → <?= e($x['incoming'] ?? '—') ?></li><?php endforeach; ?></ul>
</section>
<?php endif; ?>
<?php layout_end();
