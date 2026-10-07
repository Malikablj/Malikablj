/* NPD Project Control — perilaku umum (Vanilla JS, tanpa framework).
 * JavaScript hanya untuk interaksi & tampilan; seluruh aturan bisnis ada di server. */
(function () {
  'use strict';

  var doc = document;
  var root = doc.documentElement;

  function meta(name) {
    var m = doc.querySelector('meta[name="' + name + '"]');
    return m ? m.getAttribute('content') : '';
  }

  var NPD = window.NPD = window.NPD || {};
  NPD.basePath = meta('base-path') || '';
  NPD.csrf = function () { return meta('csrf-token'); };
  NPD.url = function (path) { return NPD.basePath + '/' + String(path).replace(/^\//, ''); };

  /** fetch JSON dengan token CSRF; melempar Error berisi pesan aman dari server. */
  NPD.api = function (path, options) {
    options = options || {};
    var headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    var body = options.body;
    if (body && !(body instanceof FormData) && typeof body !== 'string') {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(body);
    }
    if ((options.method || 'GET').toUpperCase() !== 'GET') {
      headers['X-CSRF-Token'] = NPD.csrf();
    }
    return fetch(NPD.url(path), {
      method: options.method || 'GET',
      headers: headers,
      body: body,
      credentials: 'same-origin'
    }).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try { data = text ? JSON.parse(text) : {}; } catch (e) { data = { error: text || res.statusText }; }
        if (!res.ok) {
          var err = new Error((data && data.error) || res.statusText);
          err.status = res.status;
          err.data = data;
          throw err;
        }
        return data;
      });
    });
  };

  /** Toast notifikasi singkat. */
  NPD.toast = function (message, type) {
    var region = doc.querySelector('[data-toasts]');
    if (!region) { return; }
    var el = doc.createElement('div');
    el.className = 'toast toast-' + (type || 'info');
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.textContent = message;
    region.appendChild(el);
    setTimeout(function () { el.style.opacity = '0'; el.style.transition = 'opacity 200ms'; }, 3800);
    setTimeout(function () { el.remove(); }, 4100);
  };

  /** Dialog konfirmasi bertema (bukan window.confirm agar ikut mode gelap). */
  NPD.confirm = function (message, okLabel, cancelLabel, danger) {
    return new Promise(function (resolve) {
      var dlg = doc.createElement('dialog');
      dlg.className = 'modal';
      dlg.innerHTML = '<div class="modal-body"><p></p></div><div class="modal-footer">' +
        '<button type="button" class="btn" data-no></button><button type="button" class="btn" data-yes></button></div>';
      dlg.querySelector('p').textContent = message;
      var yes = dlg.querySelector('[data-yes]');
      var no = dlg.querySelector('[data-no]');
      yes.textContent = okLabel || 'OK';
      yes.classList.add(danger ? 'btn-danger' : 'btn-primary');
      no.textContent = cancelLabel || 'Batal';
      doc.body.appendChild(dlg);
      function done(v) { dlg.close(); dlg.remove(); resolve(v); }
      yes.addEventListener('click', function () { done(true); });
      no.addEventListener('click', function () { done(false); });
      dlg.addEventListener('cancel', function (e) { e.preventDefault(); done(false); });
      dlg.showModal();
      yes.focus();
    });
  };

  // --- Tema: system → light → dark → system; disimpan per pengguna di server ---
  function applyTheme(pref) {
    root.setAttribute('data-theme-pref', pref);
    var t = pref === 'system'
      ? (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
      : pref;
    root.setAttribute('data-theme', t);
    doc.querySelectorAll('meta[name="theme-color"]').forEach(function (m) {
      m.setAttribute('content', t === 'dark' ? '#000000' : '#F7F7F5');
      m.removeAttribute('media');
    });
    doc.dispatchEvent(new CustomEvent('npd:themechange', { detail: { theme: t } }));
  }
  if (window.matchMedia) {
    matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
      if (root.getAttribute('data-theme-pref') === 'system') { applyTheme('system'); }
    });
  }
  doc.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-theme-toggle]');
    if (!btn) { return; }
    var order = ['system', 'light', 'dark'];
    var cur = root.getAttribute('data-theme-pref') || 'system';
    var next = order[(order.indexOf(cur) + 1) % order.length];
    applyTheme(next);
    btn.title = btn.getAttribute('data-label-' + next) || next;
    NPD.api('api/preferences.php', { method: 'POST', body: { theme: next } }).catch(function () {});
  });

  // --- Drawer navigasi (tablet/HP) ---
  var shell = doc.querySelector('[data-shell]');
  function setDrawer(open) {
    if (!shell) { return; }
    shell.classList.toggle('drawer-open', open);
    var backdrop = doc.querySelector('.shell-backdrop');
    if (backdrop) { backdrop.hidden = !open; }
    var opener = doc.querySelector('[data-drawer-open]');
    if (opener) { opener.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    if (open) {
      var first = doc.querySelector('#sidebar a, #sidebar button');
      if (first) { first.focus(); }
    }
  }
  doc.addEventListener('click', function (e) {
    if (e.target.closest('[data-drawer-open]')) { setDrawer(true); }
    else if (e.target.closest('[data-drawer-close]')) { setDrawer(false); }
  });

  // --- Menu <details>: tutup saat klik di luar / Escape ---
  doc.addEventListener('click', function (e) {
    doc.querySelectorAll('details.menu[open]').forEach(function (d) {
      if (!d.contains(e.target)) { d.removeAttribute('open'); }
    });
  });
  doc.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      doc.querySelectorAll('details.menu[open]').forEach(function (d) { d.removeAttribute('open'); });
      if (shell && shell.classList.contains('drawer-open')) { setDrawer(false); }
    }
  });

  // --- Flash ---
  doc.addEventListener('click', function (e) {
    var b = e.target.closest('[data-flash-close]');
    if (b) { b.closest('[data-flash]').remove(); }
  });

  // --- Form dengan konfirmasi: <form data-confirm="Pesan"> ---
  doc.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) { return; }
    var msg = form.getAttribute('data-confirm');
    if (msg && !form.dataset.confirmed) {
      e.preventDefault();
      NPD.confirm(msg, form.getAttribute('data-confirm-ok'), form.getAttribute('data-confirm-cancel'), form.hasAttribute('data-confirm-danger'))
        .then(function (ok) {
          if (ok) { form.dataset.confirmed = '1'; form.requestSubmit ? form.requestSubmit(e.submitter || undefined) : form.submit(); }
        });
      return;
    }
    // cegah kirim ganda
    var submitter = e.submitter;
    if (submitter && !form.hasAttribute('data-allow-resubmit')) {
      setTimeout(function () { submitter.disabled = true; }, 0);
      setTimeout(function () { submitter.disabled = false; }, 4000);
    }
  });

  // --- Tampilkan/sembunyikan password ---
  doc.addEventListener('click', function (e) {
    var b = e.target.closest('[data-toggle-password]');
    if (!b) { return; }
    var input = b.parentElement.querySelector('input');
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    b.setAttribute('aria-pressed', show ? 'true' : 'false');
  });

  // --- Dialog yang dibuka otomatis (mis. setelah validasi gagal) ---
  doc.querySelectorAll('dialog[data-autoopen]').forEach(function (d) { if (d.showModal) { d.showModal(); } });

  // --- Dialog: <button data-open-dialog="id"> & [data-close-dialog] ---
  doc.addEventListener('click', function (e) {
    var opener = e.target.closest('[data-open-dialog]');
    if (opener) {
      var d = doc.getElementById(opener.getAttribute('data-open-dialog'));
      if (d && d.showModal) { d.showModal(); }
    }
    var closer = e.target.closest('[data-close-dialog]');
    if (closer) {
      var dlg = closer.closest('dialog');
      if (dlg) { dlg.close(); }
    }
  });
})();
