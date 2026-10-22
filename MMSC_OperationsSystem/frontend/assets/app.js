// MMSC Operations: small, dependency-free helpers.
// The server re-checks every number; these scripts only make typing faster.
(function () {
  'use strict';
  const peso = (n) => '₱' + (Math.round(n * 100) / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const num = (v) => { const n = parseFloat(String(v || '').replace(/[,₱\s]/g, '')); return isNaN(n) ? 0 : n; };
  const round2 = (n) => Math.round(n * 100) / 100;

  // ---- sidebar keeps its scroll position between pages
  const side = document.getElementById('sidebar');
  if (side) {
    try {
      const y = sessionStorage.getItem('mmsc.sidebarY');
      if (y !== null) side.scrollTop = parseInt(y, 10) || 0;
    } catch (e) { /* storage unavailable */ }
    const active = side.querySelector('.nav-link.is-active');
    if (active) {
      const r = active.getBoundingClientRect(), sr = side.getBoundingClientRect();
      // Only the sidebar scrolls; the page itself stays at the top.
      if (r.top < sr.top || r.bottom > sr.bottom) side.scrollTop += (r.top - sr.top) - (side.clientHeight / 2) + (r.height / 2);
    }
    const save = () => { try { sessionStorage.setItem('mmsc.sidebarY', String(side.scrollTop)); } catch (e) { /* ignore */ } };
    side.addEventListener('scroll', save, { passive: true });
    side.addEventListener('click', save);
    window.addEventListener('beforeunload', save);
  }

  // ---- mobile menu
  const menuBtn = document.querySelector('[data-menu]');
  const sidebar = document.getElementById('sidebar');
  if (menuBtn && sidebar) {
    menuBtn.addEventListener('click', () => {
      const open = sidebar.classList.toggle('open');
      menuBtn.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') sidebar.classList.remove('open'); });
    document.addEventListener('click', (e) => {
      if (sidebar.classList.contains('open') && !sidebar.contains(e.target) && e.target !== menuBtn) sidebar.classList.remove('open');
    });
  }

  // ---- show/hide password controls
  function setupPasswordToggles(root = document) {
    root.querySelectorAll('input[type="password"]').forEach((input) => {
      if (input.dataset.passwordToggleReady === '1') return;
      input.dataset.passwordToggleReady = '1';
      const wrap = document.createElement('div');
      wrap.className = 'password-field';
      input.parentNode.insertBefore(wrap, input);
      wrap.appendChild(input);
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'password-toggle';
      button.setAttribute('aria-label', 'Show password');
      button.setAttribute('title', 'Show password');
      button.setAttribute('aria-pressed', 'false');
      button.innerHTML = '<svg class="password-icon password-icon-show" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="2.5"/></svg>' +
        '<svg class="password-icon password-icon-hide" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18"/><path d="M10.6 6.2A10.8 10.8 0 0 1 12 6c6.5 0 10 6 10 6a17.3 17.3 0 0 1-3.1 3.6"/><path d="M6.7 6.7C3.8 8.5 2 12 2 12s3.5 6 10 6c1.8 0 3.4-.4 4.8-1"/><path d="M9.9 9.9a2.5 2.5 0 0 0 3.5 3.5"/></svg>';
      wrap.appendChild(button);
      const setState = (visible) => {
        input.type = visible ? 'text' : 'password';
        button.classList.toggle('is-visible', visible);
        button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
        button.setAttribute('title', visible ? 'Hide password' : 'Show password');
        button.setAttribute('aria-pressed', String(visible));
      };
      button.addEventListener('click', () => { setState(input.type === 'password'); input.focus(); });
    });
  }
  setupPasswordToggles();

  // ---- confirm before important actions
  document.querySelectorAll('form[data-confirm]').forEach((f) => {
    f.addEventListener('submit', (e) => { if (!window.confirm(f.dataset.confirm)) e.preventDefault(); });
  });

  // ---- item rows: Type -> Item -> Quantity
  // Works for any row that has [data-line-type] and/or an item <select> whose options carry data-* flags.
  function itemSelect(row) { return row.querySelector('[data-line-item]') || row.querySelector('select[name$="[item_id]"]'); }
  function qtyInput(row) { return row.querySelector('[data-line-qty]') || row.querySelector('input[name$="[qty]"]'); }

  // Whole numbers unless the item is sold in measured amounts (meters, kg ...).
  function applyQtyStep(input, opt) {
    if (!input) return;
    const decimal = opt && opt.dataset.decimal === '1';
    input.step = decimal ? '0.01' : '1';
    input.inputMode = decimal ? 'decimal' : 'numeric';
    if (!decimal && input.value !== '' && Math.floor(num(input.value)) !== num(input.value)) input.value = String(Math.max(1, Math.round(num(input.value))));
  }

  function styleRow(row) {
    const typeSel = row.querySelector('[data-line-type]');
    const sel = itemSelect(row);
    if (!sel) return;
    if (typeSel) {
      const want = typeSel.value === 'Consumable' ? 'Consumable' : 'Tool/Equipment';
      row.classList.toggle('consumable', want === 'Consumable');
      sel.querySelectorAll('option[data-type]').forEach((o) => { o.hidden = o.dataset.type !== want; o.disabled = o.hidden; });
      const cur = sel.selectedOptions[0];
      if (cur && cur.dataset.type && cur.dataset.type !== want) sel.value = '';
      // Condition / Due back stay in place (so columns line up) but are switched off for consumables.
      row.querySelectorAll('[data-tool-only]').forEach((el) => {
        el.querySelectorAll('input,select').forEach((i) => { i.disabled = want !== 'Tool/Equipment'; });
      });
    }
    const o = sel.selectedOptions[0];
    const hint = row.querySelector('span[data-unit]');
    if (hint) hint.textContent = o && o.value ? (o.dataset.unit || '') + ' · ' + (o.dataset.stock || '0') + ' in stock' + (o.dataset.decimal === '1' ? ' · decimals allowed' : '') : '';
    applyQtyStep(qtyInput(row), o && o.value ? o : null);
  }
  const styleAll = (root = document) => root.querySelectorAll('.lines .line').forEach(styleRow);

  // Sets a row's fields from {line_type, item_id, qty, ...}
  function fillRow(row, data) {
    if (data.line_type) { const t = row.querySelector('[data-line-type]'); if (t) t.value = data.line_type; }
    styleRow(row);
    row.querySelectorAll('[name]').forEach((el) => {
      const m = el.name.match(/\[(\w+)\]$/);
      if (m && m[1] !== 'line_type' && data[m[1]] !== undefined && data[m[1]] !== null) el.value = data[m[1]];
    });
    styleRow(row);
  }

  // ---- repeating rows: <div data-lines="name"> with a <template> and a [data-add] button
  let counter = 1000;
  function wireLines(box) {
    const tpl = box.querySelector('template');
    const list = box.querySelector('.lines');
    const add = box.querySelector('[data-add]');
    box.addRow = (data, focus) => {
      const html = tpl.innerHTML.replace(/__i__/g, String(counter++));
      list.insertAdjacentHTML('beforeend', html);
      const row = list.lastElementChild;
      if (data) fillRow(row, data); else styleRow(row);
      box.dispatchEvent(new CustomEvent('rowadded', { detail: row, bubbles: true }));
      if (focus) { const first = row.querySelector('select, input'); if (first) first.focus(); }
      recalc();
      return row;
    };
    box.clearRows = () => { list.innerHTML = ''; };
    box.rows = () => Array.from(list.children);
    if (add) add.addEventListener('click', () => box.addRow(null, true));
    list.addEventListener('click', (e) => {
      if (e.target.matches('.x')) { e.target.closest('.line').remove(); recalc(); }
    });
    // After a failed save, rebuild the rows the user already typed.
    let initial = [];
    try { initial = JSON.parse(box.dataset.initial || '[]'); } catch (e) { initial = []; }
    initial.forEach((data) => box.addRow(data, false));
    if (!list.children.length && box.dataset.start !== '0') box.addRow(null, false);
  }

  document.addEventListener('change', (e) => {
    const row = e.target.closest('.lines .line');
    if (row && (e.target.matches('[data-line-type]') || e.target === itemSelect(row))) { styleRow(row); recalc(); }
  });

  // ---- live totals for the counter sale and billing pages
  function totals(form) {
    let gross = num(form.dataset.fixedGross);
    form.querySelectorAll('.pos-line').forEach((row) => {
      const sel = row.querySelector('select');
      const opt = sel && sel.selectedOptions[0];
      const price = opt ? num(opt.dataset.price) : 0;
      gross += price * num(row.querySelector('[name$="[qty]"]').value);
    });
    const disc = num((form.querySelector('[name=discount_amount]') || {}).value);
    const dp = num(form.dataset.downpayments);
    const vatRate = num(form.dataset.vatRate || '12');
    const taxable = Math.max(0, gross - disc);
    const vat = Math.round(taxable * vatRate) / 100;
    const totalWithVat = round2(taxable + vat);
    const due = form.dataset.fixedDue !== undefined ? num(form.dataset.fixedDue) : Math.max(0, round2(totalWithVat - dp));
    let paid = 0, cash = 0;
    form.querySelectorAll('.pay-line').forEach((row) => {
      const a = num(row.querySelector('[name$="[amount]"]').value);
      paid += a;
      if (row.querySelector('select').value === 'Cash') cash += a;
    });
    return { gross, disc, taxable, vat, vatRate, totalWithVat, due, paid: round2(paid), cash: round2(cash) };
  }

  function recalc() {
    const form = document.querySelector('[data-totals]');
    if (form) {
      form.querySelectorAll('.pos-line').forEach((row) => {
        const sel = row.querySelector('select');
        const opt = sel && sel.selectedOptions[0];
        const t = (opt ? num(opt.dataset.price) : 0) * num(row.querySelector('[name$="[qty]"]').value);
        row.querySelector('.line-total').textContent = peso(t);
      });
      form.querySelectorAll('.pay-line').forEach((row) => {
        const ref = row.querySelector('[name$="[reference_no]"]');
        const cash = row.querySelector('select').value === 'Cash';
        row.classList.toggle('is-cash', cash);
        if (ref) ref.closest('div').style.visibility = cash ? 'hidden' : 'visible';
      });
      const t = totals(form);
      const set = (sel, v) => { const el = form.querySelector(sel); if (el) el.textContent = v; };
      set('[data-gross]', peso(t.gross));
      set('[data-disc]', '−' + peso(t.disc));
      set('[data-taxable]', peso(t.taxable));
      set('[data-vat]', peso(t.vat));
      set('[data-total-vat]', peso(t.totalWithVat));
      set('[data-vat-rate-label]', String(t.vatRate));
      set('[data-due]', peso(t.due));
      set('[data-paid]', peso(t.paid));
      const change = round2(t.paid - t.due);
      const ch = form.querySelector('[data-change]');
      if (ch) {
        if (t.paid === 0) ch.textContent = '—';
        else if (change < -0.004) ch.textContent = 'Short by ' + peso(-change);
        else if (change > t.cash + 0.004) ch.textContent = 'Change can only come from cash';
        else ch.textContent = peso(change);
        ch.parentElement.classList.toggle('change', change >= 0 && change <= t.cash + 0.004);
      }
      const limit = num(form.dataset.discLimit);
      const needs = t.disc > 0 && t.gross > 0 && t.disc > Math.round(t.gross * limit) / 100;
      const box = form.querySelector('[data-approval]');
      if (box) box.hidden = !needs;
    }
    recalcEstimate();
  }

  // Split payment: a new payment line starts with whatever is still unpaid.
  document.addEventListener('rowadded', (e) => {
    const row = e.detail;
    if (!row.classList.contains('pay-line')) return;
    const form = row.closest('[data-totals]');
    const amt = row.querySelector('[name$="[amount]"]');
    if (!form || !amt || amt.value !== '') return;
    const others = Array.from(form.querySelectorAll('.pay-line')).filter((r) => r !== row);
    if (!others.length) return;                       // the first line stays empty so the cashier types what was handed over
    const t = totals(form);
    const left = round2(t.due - t.paid);
    if (left > 0) { amt.value = left.toFixed(2); recalc(); }
  });

  // "Exact amount" puts whatever is still unpaid into the last payment line.
  document.querySelectorAll('[data-exact]').forEach((b) => b.addEventListener('click', () => {
    const form = b.closest('[data-totals]');
    const lines = Array.from(form.querySelectorAll('.pay-line'));
    if (!lines.length) return;
    const last = lines[lines.length - 1].querySelector('[name$="[amount]"]');
    last.value = '';
    const t = totals(form);
    last.value = Math.max(0, round2(t.due - t.paid)).toFixed(2);
    recalc();
  }));

  // ---- job registration: estimate = service fee + consumables + VAT
  function recalcEstimate() {
    const form = document.querySelector('[data-job-estimate]');
    if (!form) return;
    let materials = 0;
    form.querySelectorAll('.lines .line').forEach((row) => {
      const sel = itemSelect(row);
      const o = sel && sel.selectedOptions[0];
      const isConsumable = o && o.value && o.dataset.type === 'Consumable';
      const amt = isConsumable ? round2(num(o.dataset.price) * num(qtyInput(row).value)) : 0;
      materials += amt;
      const cell = row.querySelector('[data-line-amount]');
      if (cell) cell.textContent = o && o.value ? (isConsumable ? peso(amt) : 'Lent, not billed') : '₱0.00';
    });
    const fee = num((form.querySelector('[data-fee]') || {}).value);
    const sub = round2(fee + materials);
    const vat = Math.round(sub * num(form.dataset.vatRate || '12')) / 100;
    const set = (sel, v) => { const el = form.querySelector(sel); if (el) el.textContent = v; };
    set('[data-est-fee]', peso(fee));
    set('[data-est-materials]', peso(materials));
    set('[data-est-sub]', peso(sub));
    set('[data-est-vat]', peso(vat));
    set('[data-est-total]', peso(round2(sub + vat)));
  }

  document.addEventListener('input', (e) => { if (e.target.closest('[data-totals], [data-job-estimate]')) recalc(); });
  document.addEventListener('change', (e) => { if (e.target.closest('[data-totals], [data-job-estimate]')) recalc(); });

  // ---- job templates: picking one fills service type, usual fee and required items (all still editable)
  const tplPick = document.querySelector('[data-template-pick]');
  if (tplPick) {
    const form = tplPick.closest('form');
    const map = JSON.parse(form.dataset.templates || '{}');
    const box = form.querySelector('[data-lines="items"]');
    let last = tplPick.value;
    tplPick.addEventListener('change', () => {
      const t = map[tplPick.value];
      if (!t) { last = tplPick.value; return; }
      const hasRows = box.rows().some((r) => itemSelect(r).value);
      if (hasRows && !window.confirm('Replace the required items you already entered with this template?')) { tplPick.value = last; return; }
      last = tplPick.value;
      const st = form.querySelector('[name=service_type]'); if (st) st.value = t.service_type;
      const fee = form.querySelector('[data-fee]'); if (fee && (num(fee.value) === 0 || window.confirm('Use the template\'s usual service fee (' + peso(t.fee) + ')?'))) fee.value = t.fee.toFixed(2);
      box.clearRows();
      t.items.forEach((row) => box.addRow(row, false));
      if (!t.items.length) box.addRow(null, false);
      recalc();
    });
  }

  // ---- customer picker: "+ Add a new customer…" shows the extra fields
  document.querySelectorAll('[data-customer-picker]').forEach((box) => {
    const sel = box.querySelector('select');
    const extra = box.querySelector('[data-new-customer]');
    const contact = document.querySelector('[name=customer_contact]');
    const sync = () => {
      const isNew = sel.value === 'new';
      extra.hidden = !isNew;
      const nm = extra.querySelector('[name=new_customer_name]');
      if (nm) nm.required = isNew;
      const o = sel.selectedOptions[0];
      if (contact && o && o.dataset.contact !== undefined && sel.value !== 'new') contact.value = o.dataset.contact || contact.value;
      const cf = document.querySelector('[data-contact-field]');
      if (cf) cf.hidden = isNew;   // the new-customer panel has its own contact box
    };
    sel.addEventListener('change', () => { sync(); if (sel.value === 'new') extra.querySelector('input').focus(); });
    sync();
  });

  // ---- supplier picker: "+ Add a new supplier…" shows the extra fields; picking an item pre-selects its usual supplier
  document.querySelectorAll('[data-supplier-picker]').forEach((box) => {
    const sel = box.querySelector('select');
    const extra = box.querySelector('[data-new-supplier]');
    const sync = () => {
      extra.hidden = sel.value !== 'new';
      const nm = extra.querySelector('[name=new_supplier_name]');
      if (nm) nm.required = sel.value === 'new';
    };
    sel.addEventListener('change', () => { sync(); if (sel.value === 'new') extra.querySelector('input').focus(); });
    sync();
    const item = document.querySelector('[data-delivery] [name=item_id]');
    if (item) item.addEventListener('change', () => {
      const o = item.selectedOptions[0];
      if (o && o.dataset.supplier && sel.value !== 'new') { sel.value = o.dataset.supplier; sync(); }
      const hint = document.querySelector('[data-unit-hint]');
      if (hint) hint.textContent = o && o.value ? 'In ' + o.dataset.unit + (o.dataset.decimal === '1' ? ' (decimals allowed)' : ' (whole number)') : "In the item's unit";
    });
  });

  // ---- deliveries: whole numbers unless the item allows decimals
  document.querySelectorAll('[data-qty-item]').forEach((input) => {
    const sel = document.getElementById(input.dataset.qtyItem);
    if (!sel) return;
    const sync = () => applyQtyStep(input, sel.selectedOptions[0] && sel.value ? sel.selectedOptions[0] : null);
    sel.addEventListener('change', sync); sync();
  });

  // Wire repeating rows now: every document-level listener above already exists.
  document.querySelectorAll('[data-lines]').forEach(wireLines);

  // ---- release form: technicians follow the job; required items and approved requests pre-fill the rows
  const rel = document.querySelector('[data-release]');
  if (rel) {
    const map = JSON.parse(rel.dataset.techs || '{}');
    const needs = JSON.parse(rel.dataset.jobItems || '{}');
    const requests = JSON.parse(rel.dataset.requests || '{}');
    const job = rel.querySelector('[name=job_id]');
    const tech = rel.querySelector('[name=technician_id]');
    const reqSel = rel.querySelector('[name=request_id]');
    const box = rel.querySelector('[data-lines]');
    const loadBtn = rel.querySelector('[data-load-required]');
    const note = rel.querySelector('[data-required-note]');
    const fillTechs = () => {
      const keep = tech.dataset.selected || tech.value;
      tech.innerHTML = '<option value="">Choose a technician</option>';
      (map[job.value] || []).forEach((t) => {
        const o = new Option(t.name, t.id);
        if (String(t.id) === keep) o.selected = true;
        tech.add(o);
      });
      if (tech.options.length === 2) tech.selectedIndex = 1;
      tech.disabled = !job.value;
    };
    const rowsEmpty = () => box.rows().every((r) => !itemSelect(r).value);
    const loadRequired = () => {
      const list = needs[job.value] || [];
      if (!list.length) return false;
      box.clearRows();
      list.forEach((n) => box.addRow({ line_type: n.type === 'Tool/Equipment' ? 'Tool' : 'Consumable', item_id: String(n.item_id), qty: n.remaining }, false));
      return true;
    };
    const showNote = () => {
      const list = needs[job.value] || [];
      if (note) {
        note.hidden = !list.length;
        note.querySelector('span').textContent = list.length ? list.length + ' required item(s) still to release for this job.' : '';
      }
    };
    job.addEventListener('change', () => {
      tech.dataset.selected = '';
      fillTechs(); showNote();
      if (rowsEmpty()) loadRequired();
    });
    if (loadBtn) loadBtn.addEventListener('click', () => { if (!loadRequired()) alert('This job has no required items left to release.'); });
    if (reqSel) reqSel.addEventListener('change', () => {
      const r = requests[reqSel.value];
      if (!r) return;
      job.value = String(r.job_id); tech.dataset.selected = String(r.technician_id); fillTechs(); showNote();
      if (rowsEmpty()) box.clearRows();
      box.addRow({ line_type: r.type === 'Tool/Equipment' ? 'Tool' : 'Consumable', item_id: String(r.item_id), qty: r.qty }, false);
    });
    fillTechs(); showNote();
    if (job.value && rowsEmpty() && (box.dataset.initial || '[]') === '[]') loadRequired();
  }

  // ---- a form is sent once: a second Enter or click while the page is still loading is ignored
  // (the server also refuses a repeated form, so a double submit can never save twice)
  document.addEventListener('submit', (e) => {
    const f = e.target;
    if (e.defaultPrevented || !(f instanceof HTMLFormElement) || (f.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
    if (f.dataset.sending === '1') { e.preventDefault(); return; }
    f.dataset.sending = '1'; f.classList.add('is-sending');
    setTimeout(() => { delete f.dataset.sending; f.classList.remove('is-sending'); }, 8000);   // e.g. a file download that stays on the page
  });
  window.addEventListener('pageshow', () => document.querySelectorAll('form[data-sending]').forEach((f) => { delete f.dataset.sending; f.classList.remove('is-sending'); }));

  // ---- Reasons and notes must be real text: at least 3 letters or numbers (same rule as the server).
  // Invisible "filler" characters (Hangul filler U+3164, Braille blank U+2800, zero-width spaces…) don't count,
  // even though Unicode files some of them as letters.
  const REASON_MIN = 3;
  const reasonOk = (v) => (String(v || '').replace(/[\p{Cf}ᅟᅠㅤﾠ⠀]/gu, '').match(/[\p{L}\p{N}]/gu) || []).length >= REASON_MIN;
  const reasonMsg = (what) => what + ' (at least ' + REASON_MIN + ' letters or numbers, not just spaces or symbols).';
  const complain = (field, msg) => {
    field.setCustomValidity(msg); field.reportValidity();
    field.addEventListener('input', () => field.setCustomValidity(''), { once: true });
  };
  // Reject / Dispute / Deny buttons need the reason filled in
  document.addEventListener('click', (e) => {
    const b = e.target.closest('button[name="decision"]');
    if (!b || !['reject', 'dispute', 'deny'].includes(b.value) || !b.form) return;
    const field = b.form.querySelector('[name="reason"], [name="note"]');
    if (!field || reasonOk(field.value)) return;
    e.preventDefault();
    complain(field, reasonMsg(b.value === 'dispute' ? 'Say what is wrong before disputing' : 'Type the reason before rejecting'));
  }, true);
  // Required reason/note boxes, and the discount reason whenever a discount is entered
  document.addEventListener('submit', (e) => {
    const f = e.target;
    if (!(f instanceof HTMLFormElement)) return;
    const fields = Array.from(f.querySelectorAll('textarea[required], input[required]'))
      .filter((el) => /(^|\[|_)(reason|note|notes|explanation|why)(\]|$)/.test(el.name));
    const disc = f.querySelector('[name="discount_amount"]'), dr = f.querySelector('[name="discount_reason"]');
    if (disc && dr && num(disc.value) > 0) fields.push(dr);
    const bad = fields.find((el) => !el.disabled && !reasonOk(el.value));
    if (!bad) return;
    e.preventDefault(); e.stopImmediatePropagation();
    complain(bad, reasonMsg(bad.name === 'discount_reason' ? 'Give a reason for the discount' : 'Type a real reason'));
  }, true);

  // ---- selling price below unit cost: warn while typing (the server refuses it for items that are sold)
  document.querySelectorAll('form').forEach((f) => {
    const cost = f.querySelector('input[name="unit_cost"]'), price = f.querySelector('input[name="selling_price"]');
    if (!cost || !price) return;
    const note = document.createElement('span'); note.className = 'hint hint-bad'; note.setAttribute('aria-live', 'polite');
    price.insertAdjacentElement('afterend', note);
    const check = () => {
      const c = num(cost.value), p = num(price.value);
      note.textContent = p > 0 && p < c ? 'Below the unit cost: each sale would lose ' + peso(c - p) + '.' : '';
    };
    cost.addEventListener('input', check); price.addEventListener('input', check); check();
  });

  // ---- Add item: while typing the name, show items already in the catalog that match (with their stock),
  // so nobody adds a second "Pipe 6"" when one exists. An exact name match is refused by the server too.
  document.querySelectorAll('[data-similar]').forEach((input) => {
    let list = []; try { list = JSON.parse(input.dataset.similar || '[]'); } catch (err) { /* no data */ }
    const box = input.parentElement.querySelector('[data-similar-box]');
    if (!box || !list.length) return;
    const norm = (t) => String(t || '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
    const esc = (t) => String(t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const show = () => {
      const q = norm(input.value);
      if (q.length < 2) { box.hidden = true; box.innerHTML = ''; return; }
      const words = q.split(' ');
      const hits = list.filter((i) => { const h = norm(i.n + ' ' + i.c); return words.every((w) => h.includes(w)); })
        .map((i) => ({ ...i, exact: norm(i.n) === q, starts: norm(i.n).startsWith(q) }))
        .sort((a, b) => (b.exact - a.exact) || (b.starts - a.starts) || a.n.localeCompare(b.n)).slice(0, 6);
      if (!hits.length) { box.hidden = true; box.innerHTML = ''; return; }
      const exact = hits.find((h) => h.exact);
      box.innerHTML = (exact
        ? '<p class="similar-warn">An item with this exact name already exists (' + esc(exact.c) + '). Record a delivery for it instead of adding it again.</p>'
        : '<p class="similar-title">Already in the catalog. Check these before adding a new item:</p>')
        + '<ul>' + hits.map((h) => '<li><span class="similar-name">' + esc(h.n) + '</span><span class="similar-meta">' + esc(h.c) + ' · '
          + (h.a ? 'archived' : esc(h.q) + ' ' + esc(h.u) + ' in stock') + '</span></li>').join('') + '</ul>';
      box.hidden = false;
    };
    input.addEventListener('input', show); show();
  });

  // ---- additional-item request: quantity can't go above what is in stock
  document.querySelectorAll('[data-request-form]').forEach((f) => {
    const sel = f.querySelector('[data-line-item]'), q = f.querySelector('[data-line-qty]');
    if (!sel || !q) return;
    const sync = () => { const o = sel.selectedOptions[0]; if (o && o.value) q.max = o.dataset.avail || '0'; else q.removeAttribute('max'); };
    sel.addEventListener('change', sync); sync();
  });

  // ---- Reports: dates are switched off for "current status" reports
  document.querySelectorAll('[data-report-filters]').forEach((f) => {
    const type = f.querySelector('[name="type"]'), from = f.querySelector('[name="from"]'), to = f.querySelector('[name="to"]');
    const snaps = (f.dataset.snapshot || '').split(',');
    // Only the report choice is watched. Changing a date box's min/max while someone is typing a year
    // makes Chrome drop the half-typed date, so From/To order is checked by the server instead.
    const sync = () => { const off = snaps.includes(type.value); from.disabled = off; to.disabled = off; };
    type.addEventListener('change', sync); sync();
  });

  // ---- stay in place after saving
  // Every save posts the form and reloads the page, which used to land at the top.
  // Before the form is sent we remember which section it was in and where that section sat
  // on screen; when the same page comes back we put that section back in the same spot
  // and show the success/error message as a toast, since the banner at the top is out of view.
  const PLACE_KEY = 'mmsc.keepPlace';
  const routeOf = (href) => { const p = new URL(href, location.href).searchParams; return ['page', 'id', 'edit', 'new'].map((k) => p.get(k) || '').join('|'); };
  const sections = () => Array.from(document.querySelectorAll('main section'));
  document.addEventListener('submit', (e) => {
    const f = e.target;
    if (e.defaultPrevented || !(f instanceof HTMLFormElement)) return;
    if ((f.getAttribute('method') || 'get').toLowerCase() !== 'post' || f.target === '_blank' || f.hasAttribute('data-to-top')) return;
    const sec = f.closest('main section');
    const place = { route: routeOf(location.href), y: window.scrollY, t: Date.now(), sec: sec ? sections().indexOf(sec) : -1, top: sec ? sec.getBoundingClientRect().top : 0 };
    try { sessionStorage.setItem(PLACE_KEY, JSON.stringify(place)); } catch (err) { /* storage unavailable */ }
  });
  function showToasts() {
    const msgs = Array.from(document.querySelectorAll('main [data-flash]')).filter((a) => a.getBoundingClientRect().bottom < 60);
    if (!msgs.length) return;
    const box = document.createElement('div'); box.className = 'toasts'; box.setAttribute('aria-live', 'polite');
    msgs.forEach((a) => {
      const t = a.cloneNode(true); t.removeAttribute('data-flash'); t.classList.add('toast');
      const x = document.createElement('button'); x.type = 'button'; x.className = 'toast-x'; x.setAttribute('aria-label', 'Close message'); x.textContent = '×';
      x.addEventListener('click', () => t.remove()); t.appendChild(x); box.appendChild(t);
      if (!t.classList.contains('alert-error')) setTimeout(() => { t.classList.add('toast-out'); setTimeout(() => t.remove(), 400); }, 6000);
    });
    document.body.appendChild(box);
  }
  function restorePlace() { try {
    const raw = sessionStorage.getItem(PLACE_KEY);
    sessionStorage.removeItem(PLACE_KEY);
    const place = raw ? JSON.parse(raw) : null;
    if (place && Date.now() - place.t < 120000 && place.route === routeOf(location.href)) {
      if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
      const sec = place.sec >= 0 ? sections()[place.sec] : null;
      const target = sec ? window.scrollY + sec.getBoundingClientRect().top - place.top : place.y;
      window.scrollTo(0, Math.max(0, target));
      showToasts();
    }
  } catch (err) { /* storage unavailable: page simply opens at the top */ } }

  styleAll();
  recalc();
  restorePlace();
})();
