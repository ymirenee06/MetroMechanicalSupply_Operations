<?php
// Job templates: the usual required items (and service fee) for a kind of job.
$error = null;
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : (isset($_GET['new']) ? 0 : null);
if (is_post()) {
    try {
        if (post('action') === 'save') {
            $id = JobService::saveTemplate((int)post('template_id'), $_POST, post_rows('items'), uid());
            flash('ok', 'Template saved. It now appears under "Start from a template" on Register job.');
            redirect('job_templates');
        } elseif (post('action') === 'active') {
            JobService::setTemplateActive((int)post('template_id'), post('active') === '1', uid());
            flash('ok', post('active') === '1' ? 'Template shown again on Register job.' : 'Template hidden from Register job. Past jobs are not affected.');
            redirect('job_templates');
        }
    } catch (Throwable $e) { $error = friendly_error($e); $editId = (int)post('template_id'); }
}
$templates = JobService::templates();
$editing = null;
if ($editId !== null) {
    $editing = $editId ? JobService::template($editId) : ['template_id' => 0, 'name' => '', 'service_type' => 'Installation', 'default_service_fee' => 0, 'notes' => '', 'items' => []];
}
$items = InventoryService::items();

layout_start('Job templates', 'The usual required items for each kind of job. Picking a template on Register job fills them in; everything can still be changed per job.',
    $editing === null ? '<a class="btn btn-primary" href="' . e(url('job_templates', ['new' => 1])) . '">New template</a>' : '<a class="btn btn-quiet" href="' . e(url('job_templates')) . '">Back to templates</a>');
echo error_box($error);

if ($editing !== null):
    $initial = is_post() ? post_rows('items') : array_map(fn($x) => ['line_type' => $x['item_type'] === 'Tool/Equipment' ? 'Tool' : 'Consumable', 'item_id' => $x['item_id'], 'qty' => qty($x['qty'])], $editing['items']);
    $v = fn($k) => is_post() ? post($k) : ($editing[$k] ?? '');
?>
<form method="post" data-job-estimate data-vat-rate="<?= e(setting('vat_rate', 12)) ?>">
  <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="template_id" value="<?= e($editing['template_id']) ?>">
  <section class="panel">
    <h2><?= $editing['template_id'] ? 'Edit template' : 'New template' ?></h2>
    <div class="form-grid form-grid-3">
      <div><label for="name">Template name</label><input id="name" name="name" type="text" value="<?= e($v('name')) ?>" placeholder="Split-type aircon installation" required></div>
      <div><label for="service_type">Service type</label><select id="service_type" name="service_type"><?php foreach (['Installation', 'Repair', 'Maintenance', 'Other'] as $t): ?><option<?= $v('service_type') === $t ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
      <div><label for="default_service_fee">Usual service fee</label><input id="default_service_fee" name="default_service_fee" type="number" min="0" step="0.01" value="<?= e($v('default_service_fee')) ?>" data-fee></div>
      <div class="full"><label for="notes">Notes <span class="hint">Optional · what the job usually covers</span></label><input id="notes" name="notes" type="text" value="<?= e($v('notes')) ?>"></div>
    </div>

    <div data-lines="items" class="line-editor separated" data-start="1" data-initial="<?= e(json_encode($initial)) ?>">
      <div class="editor-head"><div><h2>Usual required items</h2><p class="muted small">Company tools are lent (not billed); tools for sale can't be listed. Quantities are a starting point.</p></div></div>
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
      <div class="editor-foot"><button type="button" class="btn btn-secondary btn-sm" data-add>Add another item</button>
        <span class="muted small">Typical total at today's prices: <strong data-est-total>₱0.00</strong> (with VAT)</span></div>
    </div>
    <div class="btn-row"><button class="btn btn-primary" type="submit">Save template</button><a class="btn btn-quiet" href="<?= e(url('job_templates')) ?>">Cancel</a></div>
  </section>
</form>
<?php else: ?>
<section class="panel" style="padding:0">
  <div class="table-wrap"><table>
    <thead><tr><th>Template</th><th>Service type</th><th class="num">Items</th><th class="num">Usual fee</th><th class="num">Used on jobs</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php if (!$templates): ?><tr><td colspan="7"><div class="empty">No templates yet. Create one for each kind of job you do often.</div></td></tr><?php endif; ?>
    <?php foreach ($templates as $t): ?>
      <tr><td><strong><?= e($t['name']) ?></strong><?= $t['notes'] ? '<span class="sub">' . e($t['notes']) . '</span>' : '' ?></td>
          <td><?= e($t['service_type']) ?></td><td class="num"><?= e($t['item_count']) ?></td><td class="num"><?= e(money($t['default_service_fee'])) ?></td><td class="num"><?= e($t['times_used']) ?></td>
          <td><?= status_badge($t['is_active'] ? 'Active' : 'Hidden') ?></td>
          <td class="num"><div class="row-actions fixed cols-tpl">
            <a class="btn btn-quiet btn-sm" href="<?= e(url('job_templates', ['edit' => $t['template_id']])) ?>">Edit</a>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="active"><input type="hidden" name="template_id" value="<?= e($t['template_id']) ?>"><input type="hidden" name="active" value="<?= $t['is_active'] ? '0' : '1' ?>">
              <button class="btn btn-quiet btn-sm" type="submit"><?= $t['is_active'] ? 'Hide' : 'Show' ?></button></form>
          </div></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif;
layout_end();
