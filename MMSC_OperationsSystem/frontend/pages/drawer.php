<?php
$error = null;
if (is_post()) {
    try {
        if (post('action') === 'open') {
            SalesService::openDrawer((int)post('drawer_no'), to_num(post('opening_float')), uid());
            flash('ok', 'Drawer opened. You can record sales now.');
            redirect('pos');
        } elseif (post('action') === 'close') {
            $r = SalesService::closeDrawer(uid(), to_num(post('counted_cash')), post('explanation'));
            flash('ok', $r['difference'] == 0 ? 'Drawer closed. The count matches.' : 'Drawer closed with a ' . money(abs($r['difference'])) . ($r['difference'] < 0 ? ' shortage.' : ' overage.') . ' The explanation was saved.');
            redirect('drawer');
        }
    } catch (Throwable $e) { $error = friendly_error($e); }
}
$s = SalesService::mySession(uid());
$open = SalesService::openSessions();
$taken = array_column($open, 'drawer_no');
layout_start('My drawer', $s ? 'Drawer ' . e($s['drawer_no']) . ' · opened ' . e(fmt_dt($s['opened_at'])) : 'Open a drawer at the start of your shift.');
echo error_box($error);

if (!$s): ?>
  <form method="post" class="panel" style="max-width:520px">
    <?= csrf_field() ?><input type="hidden" name="action" value="open">
    <h2>Open a drawer</h2>
    <div class="stack">
      <div><label for="drawer_no">Drawer</label><select id="drawer_no" name="drawer_no">
        <?php foreach ([1, 2] as $n): ?><option value="<?= $n ?>"<?= in_array($n, array_map('intval', $taken), true) ? ' disabled' : '' ?>>Drawer <?= $n ?><?= in_array($n, array_map('intval', $taken), true) ? ' (in use)' : '' ?></option><?php endforeach; ?>
      </select></div>
      <div><label for="opening_float">Opening float <span class="hint">The cash you counted into the drawer.</span></label><input id="opening_float" name="opening_float" type="number" min="0" step="0.01" required></div>
      <button class="btn btn-primary" type="submit">Open drawer</button>
    </div>
  </form>
<?php else:
    $cash = SalesService::sessionCash((int)$s['session_id']); ?>
  <div class="split">
    <section class="panel">
      <h2>Cash in this drawer</h2>
      <div class="totals" style="border-top:0;margin-top:0">
        <div><span>Opening float</span><span><?= e(money($cash['opening_float'])) ?></span></div>
        <div><span>Cash received</span><span>+ <?= e(money($cash['cash_received'])) ?></span></div>
        <div><span>Change given</span><span>− <?= e(money($cash['change_given'])) ?></span></div>
        <div><span>Cash refunds</span><span>− <?= e(money($cash['cash_refunds'])) ?></span></div>
        <div class="due"><span>Should be in the drawer</span><span><?= e(money($cash['expected_cash'])) ?></span></div>
      </div>
      <p class="muted small">GCash, bank transfers, and checks are not in the drawer, so they are not counted here.</p>
    </section>
    <form method="post" class="panel" data-confirm="Close your drawer? You can no longer record sales in this session.">
      <?= csrf_field() ?><input type="hidden" name="action" value="close">
      <h2>Count and close</h2>
      <div class="stack">
        <div><label for="counted_cash">Cash you counted</label><input id="counted_cash" name="counted_cash" type="number" min="0" step="0.01" required></div>
        <div><label for="explanation">Explanation <span class="hint">Needed only if the count does not match.</span></label><textarea id="explanation" name="explanation"></textarea></div>
        <button class="btn btn-primary" type="submit">Close drawer</button>
      </div>
    </form>
  </div>
<?php endif;
layout_end();
