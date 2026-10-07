/* Form NPR: autosave draft, tampil/sembunyi field kondisional, stepper di HP.
 * Validasi & hak akses tetap di server; skrip ini hanya kenyamanan pengguna. */
(function () {
  'use strict';
  var form = document.getElementById('npr-form');
  if (!form) { return; }
  var NPD = window.NPD;
  var statusEl = form.querySelector('[data-autosave-status]');
  var lockInputs = document.querySelectorAll('[data-lock]');

  function setLock(v) { lockInputs.forEach(function (i) { i.value = v; }); }
  function setStatus(text, cls) {
    if (!statusEl) { return; }
    statusEl.textContent = text;
    statusEl.className = 'autosave-status small ' + (cls || 'muted');
  }

  // ---------- Autosave (draft biru / draft feedback pink) ----------
  var timer = null, inflight = false, dirty = false;
  function collect() {
    var fd = new FormData(form);
    fd.delete('action');
    Array.from(fd.keys()).forEach(function (k) { if (k.indexOf('attachment_') === 0) { fd.delete(k); } });
    return fd;
  }
  function autosave() {
    if (inflight) { dirty = true; return; }
    inflight = true; dirty = false;
    setStatus(form.dataset.msgSaving);
    var fd = collect();
    fd.append('npr_id', form.dataset.nprId);
    NPD.api('api/npr-autosave.php', { method: 'POST', body: fd }).then(function (res) {
      setLock(res.lock_version);
      var t = new Date();
      setStatus(form.dataset.msgSaved.replace('{time}', ('0' + t.getHours()).slice(-2) + ':' + ('0' + t.getMinutes()).slice(-2)), 'ok');
    }).catch(function (err) {
      if (err.status === 409) { setStatus(form.dataset.msgConflict, 'error'); stopAutosave(); return; }
      if (err.status === 422) { setStatus(form.dataset.msgError + ': ' + err.message, 'error'); return; }
      setStatus(form.dataset.msgError, 'error');
    }).finally(function () {
      inflight = false;
      if (dirty) { schedule(); }
    });
  }
  function schedule() { clearTimeout(timer); timer = setTimeout(autosave, 1500); }
  var autosaveOn = form.dataset.autosave === '1';
  function stopAutosave() { autosaveOn = false; clearTimeout(timer); }
  if (autosaveOn) {
    form.addEventListener('input', function (e) { if (e.target.type !== 'file') { schedule(); } });
    form.addEventListener('change', function (e) { if (e.target.type !== 'file') { schedule(); } });
    form.addEventListener('submit', function () { stopAutosave(); });
  }

  // ---------- Field kondisional per part ----------
  function refreshPart(part) {
    var nameSel = part.querySelector('[data-part-name]');
    if (!nameSel) { return; }
    var neckCodes = [];
    try { neckCodes = JSON.parse(nameSel.getAttribute('data-neck-codes') || '[]'); } catch (e) { neckCodes = []; }
    var isOther = nameSel.value === '__other';
    var isNeck = neckCodes.indexOf(nameSel.value) !== -1;
    part.querySelectorAll('[data-show-when-other]').forEach(function (el) { el.hidden = !isOther; });
    part.querySelectorAll('[data-show-when-neck]').forEach(function (el) { el.hidden = !isNeck; });
    part.querySelectorAll('[data-hide-when-neck]').forEach(function (el) { el.hidden = isNeck; });
  }
  form.querySelectorAll('[data-part]').forEach(function (part) {
    refreshPart(part);
    var sel = part.querySelector('[data-part-name]');
    if (sel) { sel.addEventListener('change', function () { refreshPart(part); }); }
  });

  // ---------- Decoration: buka/tutup detail sesuai checkbox ----------
  form.querySelectorAll('[data-toggle-target]').forEach(function (cb) {
    var target = document.getElementById(cb.getAttribute('data-toggle-target'));
    function sync() { if (target) { target.toggleAttribute('data-collapsed', !cb.checked); } }
    cb.addEventListener('change', sync);
    sync();
  });

  // ---------- Customer: isi alamat & telepon dari master bila kosong ----------
  var custSel = form.querySelector('[data-customer-select]');
  if (custSel && !custSel.disabled) {
    var customers = {};
    try { customers = JSON.parse(custSel.getAttribute('data-customers') || '{}'); } catch (e) { customers = {}; }
    custSel.addEventListener('change', function () {
      var c = customers[custSel.value];
      if (!c) { return; }
      [['npr[invoice_address]', c.invoice_address], ['npr[shipping_address]', c.shipping_address], ['npr[phone]', c.phone]].forEach(function (pair) {
        var el = form.querySelector('[name="' + pair[0] + '"]');
        if (el && !el.value && pair[1]) { el.value = pair[1]; el.dispatchEvent(new Event('input', { bubbles: true })); }
      });
    });
  }

  // ---------- Harga mould: % customer = 100 − % PIK (bila kosong) ----------
  form.querySelectorAll('[data-pct="pik"]').forEach(function (pik) {
    pik.addEventListener('change', function () {
      var part = pik.closest('[data-part]');
      var cust = part && part.querySelector('[data-pct="cust"]');
      var v = parseFloat(String(pik.value).replace(',', '.'));
      if (cust && !cust.disabled && cust.value === '' && !isNaN(v) && v >= 0 && v <= 100) {
        cust.value = String(Math.round((100 - v) * 100) / 100);
      }
    });
  });

  // ---------- Dialog batal part: isi part_id ----------
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-open-dialog="dlg-cancel-part"]');
    if (b) {
      var inp = document.querySelector('[data-part-id-input]');
      if (inp) { inp.value = b.getAttribute('data-part-id'); }
    }
  });

  // ---------- Konfirmasi Kirim / Selesaikan Feedback (dialog bertema) ----------
  form.querySelectorAll('[data-confirm-submit]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      if (btn.dataset.confirmed) { return; }
      e.preventDefault();
      NPD.confirm(btn.getAttribute('data-confirm-submit'), btn.getAttribute('data-ok'), btn.getAttribute('data-cancel')).then(function (ok) {
        if (!ok) { return; }
        btn.dataset.confirmed = '1';
        stopAutosave();
        if (form.requestSubmit) { form.requestSubmit(btn); } else { btn.click(); }
      });
    });
  });

  // ---------- Stepper di HP (PRD §11.6): satu seksi per langkah ----------
  var steps = Array.from(form.querySelectorAll('[data-step]'));
  var mq = window.matchMedia('(max-width: 640px)');
  var nav = null, current = 0;
  function firstErrorStep() {
    for (var i = 0; i < steps.length; i++) { if (steps[i].querySelector('.has-error, .field-error')) { return i; } }
    return 0;
  }
  function show(i) {
    current = Math.max(0, Math.min(steps.length - 1, i));
    steps.forEach(function (s, idx) { s.classList.toggle('step-hidden', idx !== current); });
    if (nav) {
      nav.querySelector('[data-step-label]').textContent = form.dataset.stepLabel.replace('{n}', current + 1).replace('{total}', steps.length) + ' · ' + (steps[current].querySelector('h2') || {}).textContent;
      nav.querySelector('[data-prev]').disabled = current === 0;
      nav.querySelector('[data-next]').disabled = current === steps.length - 1;
    }
  }
  function enableStepper() {
    if (nav || steps.length < 2) { return; }
    nav = document.createElement('div');
    nav.className = 'stepper-nav';
    nav.innerHTML = '<button type="button" class="btn btn-sm" data-prev aria-label="prev">‹</button><span class="small" data-step-label></span><button type="button" class="btn btn-sm" data-next aria-label="next">›</button>';
    form.insertBefore(nav, steps[0]);
    nav.querySelector('[data-prev]').addEventListener('click', function () { show(current - 1); window.scrollTo({ top: 0, behavior: 'smooth' }); });
    nav.querySelector('[data-next]').addEventListener('click', function () { show(current + 1); window.scrollTo({ top: 0, behavior: 'smooth' }); });
    show(firstErrorStep());
  }
  function disableStepper() {
    if (!nav) { return; }
    nav.remove(); nav = null;
    steps.forEach(function (s) { s.classList.remove('step-hidden'); });
  }
  function applyMq() { if (mq.matches) { enableStepper(); } else { disableStepper(); } }
  mq.addEventListener('change', applyMq);
  applyMq();

  // gulir ke error pertama di desktop
  if (!mq.matches) {
    var firstErr = form.querySelector('.has-error');
    if (firstErr) { firstErr.scrollIntoView({ block: 'center' }); }
  }
})();
