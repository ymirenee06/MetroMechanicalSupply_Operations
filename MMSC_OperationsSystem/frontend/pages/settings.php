<?php
$error=null;
if(is_post()){
 try { SalesWorkflowService::updateSetting(post('key'),post('value'),uid()); flash('ok','Setting updated.'); redirect('settings'); }
 catch(Throwable $e){$error=friendly_error($e);}
}
$settings=SalesWorkflowService::settings();
layout_start('System settings','Executive Officer controls configurable workflow limits and the confirmed 12% VAT rate.'); echo error_box($error);
$labels=['vat_rate'=>'VAT rate (%)','discount_limit_pct'=>'Discount approval threshold (%)','sales_return_days'=>'Sales return period (days)','release_timeout_hours'=>'Release / transfer confirmation timeout (hours)','unbilled_alert_days'=>'Unbilled-job alert after (days)','or_job_basis'=>'Job OR representation basis'];
?>
<div class="panel"><div class="table-wrap"><table><thead><tr><th>Setting</th><th>Current</th><th>Update</th></tr></thead><tbody>
<?php foreach($settings as $x): if(!isset($labels[$x['setting_key']])) continue; ?><tr><td><strong><?=e($labels[$x['setting_key']])?></strong><span class="sub code"><?=e($x['setting_key'])?></span></td><td><?=e($x['setting_key']==='or_job_basis' ? (['balance_collected'=>'Balance collected','job_total'=>'Full job total'][$x['setting_value']] ?? $x['setting_value']) : $x['setting_value'])?></td><td><form method="post" class="setting-form"><?=csrf_field()?><input type="hidden" name="key" value="<?=e($x['setting_key'])?>"><?php if($x['setting_key']==='or_job_basis'):?><select name="value"><option value="balance_collected"<?= $x['setting_value']==='balance_collected'?' selected':'' ?>>Balance collected</option><option value="job_total"<?= $x['setting_value']==='job_total'?' selected':'' ?>>Full job total</option></select><?php else:?><input name="value" value="<?=e($x['setting_value'])?>" type="number" min="0" step="0.01"><?php endif;?><button class="btn btn-primary btn-sm">Save</button></form></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php layout_end();
