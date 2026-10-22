<?php
$error = null;
$techs = JobService::technicians();
$chosen = array_map('intval', (array)($_POST['technicians'] ?? []));
if (is_post()) {
    try {
        $id = JobService::create($_POST, $chosen, uid());
        flash('ok', 'Job registered. Logistics can now release the required items for it.');
        redirect('job', ['id' => $id]);
    } catch (Throwable $e) { $error = friendly_error($e); }
}
$items = InventoryService::items();
$templates = JobService::templates(true);
$vatRate = (float) setting('vat_rate', 12);
layout_start('Register job', 'Use the number printed on the job order form. List the items the job needs; the estimate is computed for you.', '<a class="btn btn-quiet" href="' . e(url('jobs')) . '">Cancel</a>');
echo error_box($error);
?>
<form method="post" data-job-estimate data-vat-rate="<?= e($vatRate) ?>" data-templates="<?= e(json_encode(JobService::templateMap())) ?>">
  <?= csrf_field() ?>
  <div class="split">
    <section class="panel">
      <h2>Job details</h2>
      <?php if ($templates): ?>
      <div class="template-pick">
        <div><label for="template_id">Start from a template <span class="hint">Optional · fills the service type, usual fee and required items. You can still change everything.</span></label>
          <select id="template_id" name="template_id" data-template-pick>
            <option value="">No template (fill in manually)</option>
            <?php foreach (['Installation', 'Repair', 'Maintenance', 'Other'] as $grp): $inGrp = array_filter($templates, fn($t) => $t['service_type'] === $grp); if (!$inGrp) continue; ?>
              <optgroup label="<?= e($grp) ?>"><?php foreach ($inGrp as $t): ?><option value="<?= e($t['template_id']) ?>"<?= (string)post('template_id') === (string)$t['template_id'] ? ' selected' : '' ?>><?= e($t['name']) ?> · <?= e($t['item_count']) ?> items</option><?php endforeach; ?></optgroup>
            <?php endforeach; ?>
          </select></div>
        <?php if (has_role('Executive Officer')): ?><a class="small" href="<?= e(url('job_templates')) ?>">Manage templates</a><?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="form-grid">
        <div><label for="job_ref_no">Job order number <span class="hint">Match the paper form</span></label><input id="job_ref_no" name="job_ref_no" type="text" value="<?= e(post('job_ref_no', JobService::nextRef())) ?>" required></div>
        <?= customer_picker('Client', false, 'Saved or new') ?>
        <div class="full"><label for="job_site">Job site</label><input id="job_site" name="job_site" type="text" value="<?= e(post('job_site')) ?>"></div>
        <div><label for="service_type">Service type</label>
          <select id="service_type" name="service_type"><?php foreach (['Installation', 'Repair', 'Maintenance', 'Other'] as $t): ?>
            <option<?= post('service_type') === $t ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
        <div><label for="job_date">Job date</label><input id="job_date" name="job_date" type="date" value="<?= e(post('job_date', today())) ?>"></div>
        <fieldset class="full">
          <legend>Assigned technicians</legend>
          <?php foreach ($techs as $t): ?>
            <label class="check"><input type="checkbox" name="technicians[]" value="<?= e($t['user_id']) ?>"<?= in_array((int)$t['user_id'], $chosen, true) ? ' checked' : '' ?>> <?= e($t['full_name']) ?></label>
          <?php endforeach; ?>
        </fieldset>
      </div>

    </section>

    <aside class="panel">
      <h2>Price and estimate</h2>
      <div class="stack">
        <div><label for="service_fee">Agreed service fee <span class="hint">Labor only. Billed as agreed; the counter cannot change it.</span></label>
          <input id="service_fee" name="service_fee" type="number" min="0" step="0.01" value="<?= e(post('service_fee', '0')) ?>" data-fee></div>
      </div>
      <div class="est-box" style="margin-top:16px">
        <div class="row"><span>Service fee</span><span data-est-fee>₱0.00</span></div>
        <div class="row"><span>Consumables</span><span data-est-materials>₱0.00</span></div>
        <div class="row"><span>Subtotal</span><span data-est-sub>₱0.00</span></div>
        <div class="row"><span>VAT (<?= e(qty($vatRate)) ?>%)</span><span data-est-vat>₱0.00</span></div>
        <div class="row total"><span>Estimated total</span><span data-est-total>₱0.00</span></div>
      </div>
      <p class="muted small">Computed automatically. Downpayments can be taken up to this amount. The final bill uses what was actually used.</p>
      <div class="btn-row"><button class="btn btn-primary" type="submit">Register job</button></div>
    </aside>
  </div>
  <section class="panel">
      <div data-lines="items" class="line-editor" data-start="1" data-initial="<?= e(json_encode(post_rows('items'))) ?>">
        <div class="editor-head"><div><h2>Required items</h2><p class="muted small">Tools are listed so Logistics can prepare them (lent, not billed). Consumables are priced from the item list.</p></div></div>
        <div class="line-head job-line" aria-hidden="true"><span>Type</span><span>Item</span><span>Qty</span><span class="num">Est. amount</span><span></span></div>
        <div class="lines"></div>
        <template>
          <div class="line job-line">
            <div><select name="items[__i__][line_type]" data-line-type aria-label="Type"><option value="Consumable">Consumable</option><option value="Tool">Tool</option></select></div>
            <div><select name="items[__i__][item_id]" data-line-item aria-label="Item"><?= item_picker_options($items, true) ?></select><span class="hint" data-unit></span></div>
            <div><input name="items[__i__][qty]" type="number" step="1" min="0" value="1" data-line-qty aria-label="Quantity"></div>
            <div class="line-total" data-line-amount>₱0.00</div>
            <button type="button" class="x" aria-label="Remove this item">×</button>
          </div>
        </template>
        <div class="editor-foot"><button type="button" class="btn btn-secondary btn-sm" data-add>Add another item</button></div>
      </div>
  </section>
</form>
<?php layout_end();
