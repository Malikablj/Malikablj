/* =============================================================================
   PIK Marketing Control — JavaScript (vanilla, tanpa framework)
   Semua fitur tetap berfungsi tanpa JavaScript; script ini hanya
   meningkatkan kenyamanan (progressive enhancement).
   ============================================================================= */
(function () {
  'use strict';

  var csrfMeta = document.querySelector('meta[name="csrf-token"]');
  var basePathMeta = document.querySelector('meta[name="base-path"]');
  var CSRF = csrfMeta ? csrfMeta.getAttribute('content') : '';

  window.PIK = {
    csrf: CSRF,
    /** POST form-encoded dengan CSRF token, mengembalikan JSON. */
    post: function (url, data) {
      var body = new URLSearchParams();
      body.append('_token', CSRF);
      Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
      return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-Token': CSRF },
        body: body
      }).then(function (res) {
        return res.json().catch(function () { return { ok: false, message: 'Respons server tidak valid.' }; })
          .then(function (json) { json.httpStatus = res.status; return json; });
      });
    },
    toast: function (message, type) {
      var stack = document.querySelector('.flash-stack');
      if (!stack) {
        stack = document.createElement('div');
        stack.className = 'flash-stack';
        var main = document.getElementById('main-content');
        if (!main) { return; }
        main.insertBefore(stack, main.firstChild);
      }
      var el = document.createElement('div');
      el.className = 'alert alert-' + (type || 'success') + ' alert-dismissible fade show';
      el.setAttribute('role', 'alert');
      el.textContent = message;
      var btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'btn-close'; btn.setAttribute('data-bs-dismiss', 'alert'); btn.setAttribute('aria-label', 'Tutup');
      el.appendChild(btn);
      stack.appendChild(el);
      setTimeout(function () { if (el.parentNode) { el.classList.remove('show'); setTimeout(function () { el.remove(); }, 200); } }, 5000);
    }
  };

  /* Sidebar collapse (disimpan di cookie agar server merender state yang sama) */
  document.querySelectorAll('[data-sidebar-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var collapsed = document.body.classList.toggle('sidebar-collapsed');
      var path = basePathMeta ? basePathMeta.getAttribute('content') : '/';
      document.cookie = 'pik_sidebar=' + (collapsed ? 'collapsed' : 'expanded') + '; path=' + path + '; max-age=31536000; SameSite=Lax';
    });
  });

  /* Konfirmasi sebelum aksi berbahaya: <form data-confirm="Yakin?"> */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.matches && form.matches('form[data-confirm]')) {
      if (!window.confirm(form.getAttribute('data-confirm'))) {
        e.preventDefault();
        return;
      }
    }
    // cegah double submit
    if (form.matches && form.matches('form') && !form.hasAttribute('data-allow-resubmit')) {
      if (form.dataset.submitting === '1') { e.preventDefault(); return; }
      form.dataset.submitting = '1';
      setTimeout(function () { form.dataset.submitting = '0'; }, 4000);
    }
  });

  document.querySelectorAll('[data-history-back]').forEach(function (btn) {
    btn.addEventListener('click', function () { window.history.back(); });
  });

  /* Tampilkan / sembunyikan password */
  document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.querySelector(btn.getAttribute('data-toggle-password'));
      if (!input) { return; }
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
      btn.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
    });
  });

  /* Tombol print (Simpan sebagai PDF) */
  document.querySelectorAll('[data-print]').forEach(function (btn) {
    btn.addEventListener('click', function () { window.print(); });
  });

  /* Auto-hide pesan sukses */
  document.querySelectorAll('.flash-stack .alert-success').forEach(function (el) {
    setTimeout(function () { el.classList.remove('show'); setTimeout(function () { el.remove(); }, 200); }, 6000);
  });

  /* Select dengan kotak pencarian: <select data-searchable> */
  document.querySelectorAll('select[data-searchable]').forEach(function (select) {
    var input = document.createElement('input');
    input.type = 'search';
    input.className = 'form-control form-control-sm select-filter';
    input.placeholder = select.getAttribute('data-searchable') || 'Ketik untuk mencari…';
    input.setAttribute('aria-label', 'Filter pilihan');
    select.parentNode.insertBefore(input, select);
    var options = Array.prototype.slice.call(select.querySelectorAll('option'));
    options.forEach(function (opt) { opt.dataset.origDisabled = opt.disabled ? '1' : '0'; });
    input.addEventListener('input', function () {
      var q = input.value.trim().toLowerCase();
      options.forEach(function (opt) {
        if (!opt.value) { return; }
        var text = (opt.textContent + ' ' + (opt.parentNode.label || '')).toLowerCase();
        var match = q === '' || text.indexOf(q) !== -1 || opt.selected;
        opt.hidden = !match;
        opt.disabled = !match || opt.dataset.origDisabled === '1';
      });
      select.querySelectorAll('optgroup').forEach(function (g) {
        g.hidden = !Array.prototype.some.call(g.children, function (o) { return !o.hidden; });
      });
    });
  });

  /* Auto-submit filter: <select data-autosubmit> */
  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { if (el.form) { el.form.submit(); } });
  });
  /* Kanban leads: drag & drop antar kolom (fallback: pilihan status di kartu) */
  var board = document.querySelector('[data-kanban]');
  if (board) {
    var dragged = null;
    var refreshColumn = function (col, deltaCount, deltaValue) {
      var countEl = col.querySelector('[data-col-count]');
      var valueEl = col.querySelector('[data-col-value]');
      if (countEl) { countEl.textContent = String(Math.max(0, parseInt(countEl.textContent || '0', 10) + deltaCount)); }
      if (valueEl) {
        var v = Math.max(0, parseFloat(valueEl.getAttribute('data-value') || '0') + deltaValue);
        valueEl.setAttribute('data-value', String(v));
        valueEl.textContent = v > 0 ? 'Rp ' + new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 2 }).format(v) : '';
      }
      var cards = col.querySelector('.kanban-cards');
      var empty = cards.querySelector('[data-empty]');
      var hasCards = cards.querySelector('.kanban-card') !== null;
      if (empty) { empty.hidden = hasCards; }
    };
    var moveCard = function (card, toCol, fromCol) {
      var value = parseFloat(card.getAttribute('data-value') || '0');
      toCol.querySelector('.kanban-cards').insertBefore(card, toCol.querySelector('.kanban-card'));
      refreshColumn(toCol, 1, value);
      refreshColumn(fromCol, -1, -value);
    };
    board.querySelectorAll('.kanban-card[draggable="true"]').forEach(function (card) {
      card.addEventListener('dragstart', function (e) {
        dragged = card;
        card.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', card.getAttribute('data-id'));
      });
      card.addEventListener('dragend', function () { card.classList.remove('dragging'); dragged = null; });
    });
    board.querySelectorAll('.kanban-col').forEach(function (col) {
      col.addEventListener('dragover', function (e) { if (dragged) { e.preventDefault(); col.classList.add('drag-over'); } });
      col.addEventListener('dragleave', function (e) { if (!col.contains(e.relatedTarget)) { col.classList.remove('drag-over'); } });
      col.addEventListener('drop', function (e) {
        e.preventDefault();
        col.classList.remove('drag-over');
        if (!dragged) { return; }
        var card = dragged;
        var fromCol = card.closest('.kanban-col');
        if (fromCol === col) { return; }
        var status = col.getAttribute('data-status');
        moveCard(card, col, fromCol);
        window.PIK.post(card.getAttribute('data-status-url'), { status: status }).then(function (res) {
          if (!res.ok) {
            moveCard(card, fromCol, col);
            window.PIK.toast(res.message || 'Status gagal diubah.', 'danger');
            return;
          }
          var select = card.querySelector('[data-kanban-select]');
          if (select) { select.value = status; }
          window.PIK.toast(res.message, 'success');
        }).catch(function () {
          moveCard(card, fromCol, col);
          window.PIK.toast('Koneksi terputus. Status tidak diubah.', 'danger');
        });
      });
    });
  }
})();
