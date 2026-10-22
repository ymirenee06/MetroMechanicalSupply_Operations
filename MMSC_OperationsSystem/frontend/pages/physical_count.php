<?php
$error=null;
$isLogistics=has_role('Logistics');
$isExecutive=has_role('Executive Officer');

if(is_post()){
 try{
  switch(post('action')){
   case 'open':
    if(!$isLogistics) throw new UserError('Only Logistics can open a physical count.');
    $id=tx(function(){
      $existing=q_one("SELECT count_id FROM physical_counts WHERE status<>'Closed' ORDER BY count_id DESC LIMIT 1 FOR UPDATE");
      if($existing) throw new UserError('A physical count is already active.');
      $scope=trim(post('scope','All items'));
      q("INSERT INTO physical_counts(scope,status,opened_by) VALUES(?,?,?)",[$scope,'Open',uid()]);
      $id=(int)db()->lastInsertId();
      foreach(q_all("SELECT item_id FROM items WHERE is_archived=0 ORDER BY item_id") as $i){
        q("INSERT INTO physical_count_lines(count_id,item_id,system_qty) VALUES(?,?,?)",[$id,$i['item_id'],stock_available((int)$i['item_id'])]);
      }
      audit('PHYSICAL_COUNT_OPEN','physical_counts',$id,null,['scope'=>$scope]);
      return $id;
    });
    flash('ok','Blind physical count opened. Enter the physical quantities without using system quantities.');
    redirect('physical_count',['id'=>$id]);

   case 'submit_counts':
    if(!$isLogistics) throw new UserError('Only Logistics can submit the blind physical quantities.');
    $cid=(int)post('count_id');
    tx(function() use($cid){
      $count=q_one("SELECT * FROM physical_counts WHERE count_id=? FOR UPDATE",[$cid]);
      if(!$count) throw new UserError('Physical count not found.');
      if($count['status']!=='Open') throw new UserError('This physical count has already been submitted and is locked for Executive Officer review.');

      foreach((array)post('counted',[]) as $lid=>$qtyv){
        if($qtyv==='') throw new UserError('Enter every physical quantity before submitting the count.');
        $q=to_num($qtyv);
        if($q<0) throw new UserError('Counted quantity cannot be negative.');
        q("UPDATE physical_count_lines SET counted_qty=? WHERE count_line_id=? AND count_id=? AND counted_qty IS NULL",[$q,(int)$lid,$cid]);
      }

      $missing=(int)q_val("SELECT COUNT(*) FROM physical_count_lines WHERE count_id=? AND counted_qty IS NULL",[$cid]);
      if($missing) throw new UserError('Enter every physical quantity before submitting the count.');

      q("UPDATE physical_counts SET status='Counting',counted_by=? WHERE count_id=?",[uid(),$cid]);
      audit('PHYSICAL_COUNT_SUBMIT','physical_counts',$cid,null,['counted_by'=>uid()]);
    });
    flash('ok','Physical count submitted. The system quantities are now locked for Executive Officer review.');
    redirect('physical_count',['id'=>$cid]);

   case 'resolve':
    if(!$isExecutive) throw new UserError('Only an Executive Officer can review and resolve physical count discrepancies.');
    $cid=(int)post('count_id');
    $lid=(int)post('line_id');
    tx(function() use($cid,$lid){
      $res=post('resolution');
      $note=trim(post('note'));
      $row=q_one("SELECT pcl.*,pc.status,pc.counted_by FROM physical_count_lines pcl JOIN physical_counts pc ON pc.count_id=pcl.count_id WHERE pcl.count_line_id=? AND pcl.count_id=? FOR UPDATE",[$lid,$cid]);
      if(!$row||$row['counted_qty']===null) throw new UserError('Enter and submit the physical count first.');
      if($row['status']!=='Counting') throw new UserError('This count is not awaiting review.');
      if((int)$row['counted_by']===uid()) throw new UserError('Self-approval is blocked. A different Executive Officer must review this physical count.');
      if(!$note) throw new UserError('Investigation note is required.');

      if($res==='Match'){
        $note=$note?:'Count matches computed stock.';
        q("UPDATE physical_count_lines SET resolution='Match', explanation=?, approved_by=? WHERE count_line_id=?",[$note,uid(),$lid]);
      } elseif($res==='Surplus Approved'){
        $diff=(float)$row['counted_qty']-(float)$row['system_qty'];
        if($diff<=0) throw new UserError('Only a positive difference can be approved as surplus.');
        q("UPDATE physical_count_lines SET resolution='Surplus Approved', explanation=?, approved_by=? WHERE count_line_id=?",[$note,uid(),$lid]);
        add_movement((int)$row['item_id'],$diff,'Count Surplus','physical_count_lines',$lid,uid());
      } elseif($res==='Shortage Incident'){
        $diff=(float)$row['counted_qty']-(float)$row['system_qty'];
        if($diff>=0) throw new UserError('Only a shortage can create a shortage incident.');
        q("UPDATE physical_count_lines SET resolution='Shortage Incident', explanation=?, approved_by=? WHERE count_line_id=?",[$note,uid(),$lid]);
        q("INSERT INTO incident_reports(item_id,qty,type,source,count_line_id,date_discovered,description,severity,created_by) VALUES(?,?,?,?,?,?,?,?,?)",[(int)$row['item_id'],abs($diff),'Unexplained Count Shortage','Physical Count',$lid,today(),$note,'High',uid()]);
      } else {
        throw new UserError('Invalid resolution.');
      }
      audit('PHYSICAL_COUNT_RESOLVE','physical_count_lines',$lid,null,['resolution'=>$res,'note'=>$note,'approved_by'=>uid()]);
    });
    flash('ok','Count line resolved by Executive Officer.');
    redirect('physical_count',['id'=>$cid]);

   case 'close':
    if(!$isExecutive) throw new UserError('Only an Executive Officer can close a physical count after discrepancy review.');
    $id=(int)post('count_id');
    tx(function() use($id){
      $count=q_one("SELECT * FROM physical_counts WHERE count_id=? FOR UPDATE",[$id]);
      if(!$count) throw new UserError('Physical count not found.');
      if($count['status']!=='Counting') throw new UserError('The physical count must be submitted before it can be closed.');
      if((int)$count['counted_by']===uid()) throw new UserError('Self-approval is blocked. A different Executive Officer must close the reviewed count.');
      $pending=(int)q_val("SELECT COUNT(*) FROM physical_count_lines WHERE count_id=? AND (counted_qty IS NULL OR resolution IS NULL)",[$id]);
      if($pending) throw new UserError('Resolve or document every line before closing the count.');
      q("UPDATE physical_counts SET status='Closed',closed_by=?,closed_at=NOW() WHERE count_id=?",[uid(),$id]);
      audit('PHYSICAL_COUNT_CLOSE','physical_counts',$id,null,['closed_by'=>uid()]);
    });
    flash('ok','Physical count closed after independent Executive Officer review.');
    redirect('physical_count');
  }
 }catch(Throwable $e){$error=friendly_error($e);}
}

$open=q_one("SELECT pc.*,u.full_name AS opener,c.full_name AS closer,cu.full_name AS counter FROM physical_counts pc JOIN users u ON u.user_id=pc.opened_by LEFT JOIN users c ON c.user_id=pc.closed_by LEFT JOIN users cu ON cu.user_id=pc.counted_by WHERE pc.status<>'Closed' ORDER BY pc.count_id DESC LIMIT 1");
$counts=q_all("SELECT pc.*,u.full_name AS opener,c.full_name AS closer,cu.full_name AS counter FROM physical_counts pc JOIN users u ON u.user_id=pc.opened_by LEFT JOIN users c ON c.user_id=pc.closed_by LEFT JOIN users cu ON cu.user_id=pc.counted_by ORDER BY pc.count_id DESC LIMIT 20");
$count=$open; if(isset($_GET['id'])) $count=q_one("SELECT pc.*,u.full_name AS opener,c.full_name AS closer,cu.full_name AS counter FROM physical_counts pc JOIN users u ON u.user_id=pc.opened_by LEFT JOIN users c ON c.user_id=pc.closed_by LEFT JOIN users cu ON cu.user_id=pc.counted_by WHERE pc.count_id=?",[(int)$_GET['id']]);
$lines=$count?q_all("SELECT pcl.*,i.name,i.unit_of_measure FROM physical_count_lines pcl JOIN items i ON i.item_id=pcl.item_id WHERE pcl.count_id=? ORDER BY i.name",[$count['count_id']]):[];

layout_start('Physical count','Blind discrepancy detection. Logistics performs the blind count; an Executive Officer independently reviews discrepancies and closes the count.'); echo error_box($error);
?>
<div class="grid-2">
<section class="panel">
  <h2>Open a blind count</h2>
  <?php if($open): ?>
    <div class="empty">Count #<?=e($open['count_id'])?> is already active.</div>
    <p class="sub">Opened by <?=e($open['opener'])?> · Status <?=e($open['status'])?><?php if($open['counter']): ?> · Submitted by <?=e($open['counter'])?><?php endif; ?></p>
  <?php elseif($isLogistics): ?>
    <p>Logistics opens the count and enters the physical quantities without seeing the system quantities.</p>
    <form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="open"><div><label for="count_scope">Scope</label><input id="count_scope" name="scope" type="text" value="All items" required></div><button class="btn btn-primary">Open count</button></form>
  <?php else: ?>
    <div class="empty">No active count. Wait for Logistics to open and submit the blind physical count.</div>
  <?php endif; ?>
</section>
<section class="panel">
  <h2>Recent counts</h2>
  <table><thead><tr><th>#</th><th>Scope</th><th>Status</th><th>Opened by</th><th>Counted by</th><th>Closed by</th></tr></thead><tbody>
  <?php if(!$counts):?><tr><td colspan="6"><div class="empty">No counts yet.</div></td></tr><?php endif;?>
  <?php foreach($counts as $c):?><tr><td><a href="<?=e(url('physical_count',['id'=>$c['count_id']]))?>">#<?=e($c['count_id'])?></a></td><td><?=e($c['scope'])?></td><td><?=status_badge($c['status'])?></td><td><?=e($c['opener'])?></td><td><?=e($c['counter']??'—')?></td><td><?=e($c['closer']??'—')?></td></tr><?php endforeach; ?>
  </tbody></table>
</section>
</div>

<?php if($count): ?>
<section class="panel">
  <div class="panel-head"><h2>Count #<?=e($count['count_id'])?></h2><span><?=status_badge($count['status'])?></span></div>
  <p class="sub">Opened by <?=e($count['opener'])?><?php if(!empty($count['counter'])): ?> · Physical quantities submitted by <?=e($count['counter'])?><?php endif; ?><?php if(!empty($count['closer'])): ?> · Closed by <?=e($count['closer'])?><?php endif; ?></p>

  <?php if($count['status']==='Open' && $isLogistics): ?>
    <p>Enter the actual quantities physically counted. System quantities remain hidden during this step.</p>
    <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="submit_counts"><input type="hidden" name="count_id" value="<?=e($count['count_id'])?>"><div class="table-wrap"><table><thead><tr><th>Item</th><th>UOM</th><th>System</th><th>Physical count</th></tr></thead><tbody>
      <?php foreach($lines as $x):?><tr><td><?=e($x['name'])?></td><td><?=e($x['unit_of_measure'])?></td><td class="muted">Hidden</td><td><input name="counted[<?=e($x['count_line_id'])?>]" type="number" min="0" step="0.01" inputmode="decimal" required></td></tr><?php endforeach; ?>
    </tbody></table></div><button class="btn btn-secondary">Submit blind count for review</button></form>

  <?php elseif($count['status']==='Open' && $isExecutive): ?>
    <div class="empty">This count is still being physically counted by Logistics. System quantities remain hidden until Logistics submits the count.</div>

  <?php elseif($count['status']==='Counting' && $isLogistics): ?>
    <div class="alert alert-ok">Physical quantities submitted. This count is now locked and waiting for an Executive Officer to review discrepancies.</div>

  <?php elseif($count['status']==='Counting' && $isExecutive): ?>
    <section class="panel" style="margin-top:14px"><h2>Executive Officer resolution review</h2><p>Compare the submitted physical count with the computed system quantity. Document the investigation for each difference. A different Executive Officer must review and close the count.</p>
      <div class="table-wrap"><table><thead><tr><th>Item</th><th>System</th><th>Counted</th><th>Difference</th><th>Resolution</th><th>Action</th></tr></thead><tbody>
      <?php foreach($lines as $x):$diff=$x['counted_qty']===null?null:(float)$x['counted_qty']-(float)$x['system_qty'];?><tr><td><?=e($x['name'])?></td><td><?= $diff===null?'—':e(qty($x['system_qty']))?></td><td><?= $diff===null?'—':e(qty($x['counted_qty']))?></td><td><?= $diff===null?'—':e(qty($diff))?></td><td><?= $x['resolution']?status_badge($x['resolution']):'—'?></td><td><?php if($diff!==null && !$x['resolution']): ?><form method="post" class="inline-form"><?=csrf_field()?><input type="hidden" name="action" value="resolve"><input type="hidden" name="count_id" value="<?=e($count['count_id'])?>"><input type="hidden" name="line_id" value="<?=e($x['count_line_id'])?>"><select name="resolution"><option value="Match">Match / No discrepancy</option><option value="Surplus Approved">Approve surplus</option><option value="Shortage Incident">Record shortage as incident</option></select><input type="text" name="note" required placeholder="Investigation / review note"><button class="btn btn-primary btn-sm">Resolve</button></form><?php endif;?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <?php $pending=(int)q_val("SELECT COUNT(*) FROM physical_count_lines WHERE count_id=? AND (counted_qty IS NULL OR resolution IS NULL)",[$count['count_id']]); if(!$pending): ?><form method="post" style="margin-top:14px"><?=csrf_field()?><input type="hidden" name="action" value="close"><input type="hidden" name="count_id" value="<?=e($count['count_id'])?>"><button class="btn btn-primary">Close reviewed count</button></form><?php else: ?><div class="empty" style="margin-top:14px">Resolve every line before closing the count.</div><?php endif; ?>
    </section>

  <?php elseif($count['status']==='Closed'): ?>
    <?php if($isExecutive): ?>
      <section class="panel" style="margin-top:14px"><h2>Finalized count review</h2><div class="table-wrap"><table><thead><tr><th>Item</th><th>System</th><th>Counted</th><th>Difference</th><th>Resolution</th><th>Investigation</th></tr></thead><tbody><?php foreach($lines as $x):$diff=(float)$x['counted_qty']-(float)$x['system_qty'];?><tr><td><?=e($x['name'])?></td><td><?=e(qty($x['system_qty']))?></td><td><?=e(qty($x['counted_qty']))?></td><td><?=e(qty($diff))?></td><td><?=status_badge($x['resolution'])?></td><td><?=e($x['explanation']??'—')?></td></tr><?php endforeach;?></tbody></table></div></section>
    <?php else: ?>
      <div class="empty">This count is finalized. Detailed system-versus-physical results are restricted to Executive Officer review.</div>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php layout_end();
