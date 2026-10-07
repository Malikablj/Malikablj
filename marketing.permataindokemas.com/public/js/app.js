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

  /* Konfirmasi sebelum aksi berbahaya: <form data-confirm="Yakin?"> atau <button data-confirm="..."> */
  document.addEventListener('submit', function (e) {
    if (e.defaultPrevented) { return; }
    var form = e.target;
    var submitter = e.submitter;
    var message = (submitter && submitter.getAttribute && submitter.getAttribute('data-confirm'))
      || (form.matches && form.matches('form[data-confirm]') ? form.getAttribute('data-confirm') : null);
    if (message && !window.confirm(message)) {
      e.preventDefault();
      return;
    }
    // cegah double submit
    if (form.matches && form.matches('form') && !form.hasAttribute('data-allow-resubmit')) {
      if (form.dataset.submitting === '1') { e.preventDefault(); return; }
      form.dataset.submitting = '1';
      setTimeout(function () { form.dataset.submitting = '0'; }, 4000);
    }
  });

  /* Centang semua: <input type="checkbox" data-check-all="ids[]"> (dalam form yang sama) */
  document.querySelectorAll('[data-check-all]').forEach(function (box) {
    box.addEventListener('change', function () {
      var scope = box.closest('form') || document;
      var name = box.getAttribute('data-check-all');
      scope.querySelectorAll('input[type="checkbox"]').forEach(function (c) {
        if (c.name === name) { c.checked = box.checked; }
      });
    });
  });

  /* Aksi massal: <form data-bulk="ids[]"> berisi <span data-bulk-count>; tombol [data-bulk-action] aktif bila ada
     yang dicentang; [data-bulk-require="#field"] menolak submit bila field itu kosong (mis. alasan penolakan). */
  document.querySelectorAll('form[data-bulk]').forEach(function (form) {
    var name = form.getAttribute('data-bulk');
    var counter = form.querySelector('[data-bulk-count]');
    var feedback = form.querySelector('[data-bulk-feedback]');
    var all = Array.prototype.filter.call(form.querySelectorAll('[data-check-all]'), function (c) { return c.getAttribute('data-check-all') === name; })[0];
    var refresh = function () {
      var boxes = Array.prototype.filter.call(form.querySelectorAll('input[type="checkbox"]'), function (c) { return c.name === name; });
      var n = boxes.filter(function (c) { return c.checked; }).length;
      if (counter) { counter.textContent = n; }
      form.querySelectorAll('[data-bulk-action]').forEach(function (b) { b.disabled = n === 0; });
      if (all) { all.checked = n > 0 && n === boxes.length; all.indeterminate = n > 0 && n < boxes.length; }
      form.classList.toggle('has-selection', n > 0);
    };
    form.addEventListener('change', refresh);
    form.addEventListener('input', function (e) {
      if (e.target.classList && e.target.classList.contains('is-invalid') && e.target.value.trim() !== '') {
        e.target.classList.remove('is-invalid');
        if (feedback) { feedback.classList.remove('d-block'); }
      }
    });
    form.addEventListener('submit', function (e) {
      var selector = e.submitter && e.submitter.getAttribute('data-bulk-require');
      var field = selector ? form.querySelector(selector) : null;
      if (field && field.value.trim() === '') {
        e.preventDefault();
        field.classList.add('is-invalid');
        if (feedback) { feedback.classList.add('d-block'); }
        field.focus();
      }
    });
    refresh();
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
  /* Form PO: tambah / hapus baris produk */
  var lineList = document.querySelector('[data-line-list]');
  var lineTpl = document.querySelector('template[data-line-template]');
  var lineAdd = document.querySelector('[data-line-add]');
  if (lineList && lineTpl && lineAdd) {
    var nextIndex = lineList.querySelectorAll('[data-line]').length;
    lineAdd.addEventListener('click', function () {
      var wrap = document.createElement('div');
      wrap.innerHTML = lineTpl.innerHTML.replace(/__INDEX__/g, String(nextIndex++)).trim();
      var row = wrap.firstElementChild;
      lineList.appendChild(row);
      var select = row.querySelector('select');
      if (select) { select.focus(); }
    });
    lineList.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-line-remove]');
      if (!btn) { return; }
      var row = btn.closest('[data-line]');
      if (lineList.querySelectorAll('[data-line]').length > 1) {
        row.remove();
      } else {
        row.querySelectorAll('input, select').forEach(function (el) { el.value = ''; });
      }
    });
  }

  /* Order Entry Form: kolom Supplier hanya tampil bila order dikerjakan subcont */
  var subcontBox = document.getElementById('f_is_subcont');
  var subcontField = document.querySelector('[data-subcont-field]');
  if (subcontBox && subcontField) {
    var syncSubcont = function () {
      subcontField.hidden = !subcontBox.checked;
      if (subcontBox.checked) { var inp = subcontField.querySelector('input'); if (inp && !inp.value) { inp.focus(); } }
    };
    subcontBox.addEventListener('change', syncSubcont);
  }

  /* Complaint: field qty retur hanya untuk jenis "Return" (barang dikembalikan) */
  var typeSelect = document.getElementById('f_record_type');
  if (typeSelect) {
    var syncType = function () {
      var isReturn = typeSelect.value === 'Return';
      document.querySelectorAll('[data-return-only]').forEach(function (el) { el.hidden = !isReturn; });
    };
    typeSelect.addEventListener('change', syncType);
    syncType();
  }

  /* Upload bukti complaint: tampilkan nama file terpilih & validasi ukuran di browser */
  document.querySelectorAll('input[type="file"][data-max-bytes]').forEach(function (input) {
    var list = document.querySelector(input.getAttribute('data-file-list') || '');
    input.addEventListener('change', function () {
      var max = parseInt(input.getAttribute('data-max-bytes'), 10) || 0;
      var tooBig = [];
      if (list) { list.textContent = ''; }
      Array.prototype.forEach.call(input.files || [], function (f) {
        if (max && f.size > max) { tooBig.push(f.name); }
        if (list) {
          var li = document.createElement('li');
          li.textContent = f.name + ' (' + Math.max(1, Math.round(f.size / 1024)).toLocaleString('id-ID') + ' KB)';
          if (max && f.size > max) { li.className = 'text-danger'; }
          list.appendChild(li);
        }
      });
      input.setCustomValidity(tooBig.length ? 'File terlalu besar: ' + tooBig.join(', ') : '');
      if (tooBig.length) { input.reportValidity(); }
    });
  });

  /* Form stok: tampilkan hasil Box × Qty per box sebagai petunjuk di kolom Qty.
     Perhitungan final tetap dilakukan server bila Qty dikosongkan. */
  var boxInput = document.getElementById('f_box');
  var perBoxInput = document.getElementById('f_qty_per_box');
  var qtyInput = document.getElementById('f_quantity');
  if (boxInput && perBoxInput && qtyInput) {
    var updateQtyHint = function () {
      var box = parseInt(boxInput.value, 10);
      var perBox = parseInt(perBoxInput.value, 10);
      qtyInput.placeholder = (box >= 0 && perBox > 0) ? '= ' + (box * perBox).toLocaleString('id-ID') + ' (otomatis)' : '';
    };
    boxInput.addEventListener('input', updateQtyHint);
    perBoxInput.addEventListener('input', updateQtyHint);
    updateQtyHint();
  }
})();
