<?php
$error=null;
if(is_post()){
 try{
  if(post('action')==='request_sale' && has_role('Sales','Executive Officer')) SalesWorkflowService::requestVoid(uid(),(int)post('sale_id'),null,post('reason'));
  else throw new UserError('Unknown void action.');
  flash('ok','Void request submitted for Executive Officer approval.'); redirect('voids');
 }catch(Throwable $e){$error=friendly_error($e);}
}
$mySales=has_role('Sales','Executive Officer')?SalesService::sales(today(), SalesService::mySession(uid())['session_id']??0):[];
$mySales=array_values(array_filter($mySales,fn($s)=>(int)$s['recorded_by']===uid()&&$s['status']==='Completed'));
$pending=has_role('Executive Officer')?SalesWorkflowService::pendingVoids():[];
layout_start('Voids','Same-day Sales Staff requests → different Executive Officer approves. Original transactions remain visible.'); echo error_box($error);
?>
<div class="grid-2">
<?php if(has_role('Sales','Executive Officer')): ?><section class="panel"><h2>Request a sale void</h2><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="request_sale"><div><label>Today’s completed sale</label><select name="sale_id" required><?=options($mySales,'sale_id', 'sale_ref_no',null,'Choose a sale')?></select></div><div><label>Reason</label><textarea name="reason" required placeholder="Wrong item / wrong customer / duplicate entry"></textarea></div><button class="btn btn-primary">Request void</button></form></section><?php endif; ?>
<?php if(has_role('Executive Officer')):?><section class="panel"><div class="panel-head"><h2>Pending void requests</h2><span class="badge badge-warn"><?=count($pending)?></span></div><?php if(!$pending):?><div class="empty">No pending voids.</div><?php else:?><div class="table-wrap"><table><thead><tr><th>Requester</th><th>Transaction</th><th>Reason</th><th>Decision</th></tr></thead><tbody><?php foreach($pending as $x):?><tr><td><?=e($x['requester'])?></td><td><?=e($x['sale_ref_no'] ?: ('DP '.$x['dp_or']))?><span class="sub"><?=e($x['or_number'] ?: $x['dp_or'])?></span></td><td><?=e($x['reason'])?></td><td><a class="btn btn-quiet btn-sm" href="<?=e(url('approvals'))?>">Review</a></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section><?php endif; ?>
</div>
<?php layout_end();
