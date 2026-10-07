/* Halaman proses: keputusan → kolom terkait, baris dependency, pratinjau jadwal (server).
 * Hanya tampilan & interaksi; validasi dan perhitungan jadwal dilakukan di server. */
(function () {
  'use strict';

  var doc = document;

  // --- Keputusan: tampilkan kolom yang relevan (loop_to, gate parts) & penanda komentar wajib ---
  var form = doc.querySelector('[data-complete-form]');
  if (form) {
    var sync = function () {
      var checked = form.querySelector('input[name="outcome"]:checked');
      var code = checked ? checked.value : '';
      form.querySelectorAll('[data-when-outcome]').forEach(function (el) {
        var on = el.getAttribute('data-when-outcome') === code;
        el.hidden = !on;
        el.querySelectorAll('select, input, textarea').forEach(function (i) { i.disabled = !on; });
        if (on) {
          el.querySelectorAll('select').forEach(function (s) {
            var def = s.querySelector('option[data-default]');
            if (def && !s.dataset.touched) { s.value = def.value; }
          });
        }
      });
      var comment = form.querySelector('textarea[name="comment"]');
      var optional = form.querySelector('[data-comment-optional]');
      var required = !!(checked && checked.hasAttribute('data-comment-required'));
      if (comment) { comment.required = required; }
      if (optional) { optional.hidden = required; }
    };
    form.addEventListener('change', function (e) {
      if (e.target.matches('select')) { e.target.dataset.touched = '1'; }
      sync();
    });
    sync();
  }

  // --- Baris dependency ---
  doc.querySelectorAll('[data-preview-form="dependency"]').forEach(function (f) {
    var rows = f.querySelector('[data-dep-rows]');
    var tpl = f.querySelector('template[data-dep-template]');
    var counter = rows ? rows.querySelectorAll('[data-dep-row]').length + 1000 : 0;
    f.addEventListener('click', function (e) {
      if (e.target.closest('[data-dep-add]') && tpl && rows) {
        counter++;
        var html = tpl.innerHTML.replace(/999/g, String(counter));
        var wrap = doc.createElement('div');
        wrap.innerHTML = html;
        var row = wrap.firstElementChild;
        rows.appendChild(row);
        var sel = row.querySelector('select');
        if (sel) { sel.focus(); }
      }
      var rm = e.target.closest('[data-dep-remove]');
      if (rm) {
        var r = rm.closest('[data-dep-row]');
        if (r) { r.remove(); }
      }
    });
  });

  // --- Pratinjau jadwal ---
  var labels = doc.getElementById('preview-labels');
  function L(k) { return labels ? (labels.getAttribute('data-' + k) || '') : k; }

  function cell(tag, text, cls) {
    var el = doc.createElement(tag);
    el.textContent = text;
    if (cls) { el.className = cls; }
    return el;
  }

  function render(box, data) {
    box.textContent = '';
    box.hidden = false;
    box.appendChild(cell('p', L('title'), 'small'));
    var summary = cell('p', L('forecast') + ': ' + data.forecast_old + ' → ' + data.forecast_new, 'small');
    if (data.past_target) {
      var b = cell('span', L('risk') + ' (' + data.target + ')', 'badge badge-warning');
      summary.appendChild(doc.createTextNode(' '));
      summary.appendChild(b);
    }
    box.appendChild(summary);
    if (!data.changes || !data.changes.length) {
      box.appendChild(cell('p', L('none'), 'muted small'));
      return;
    }
    var wrap = doc.createElement('div');
    wrap.className = 'table-wrap';
    var table = doc.createElement('table');
    table.className = 'table';
    var thead = doc.createElement('thead');
    var hr = doc.createElement('tr');
    [L('process'), L('old'), L('new'), L('shift')].forEach(function (h, i) {
      var th = cell('th', h, i === 3 ? 'right' : '');
      th.scope = 'col';
      hr.appendChild(th);
    });
    thead.appendChild(hr);
    table.appendChild(thead);
    var tbody = doc.createElement('tbody');
    data.changes.forEach(function (c) {
      var tr = doc.createElement('tr');
      tr.appendChild(cell('td', c.process, 'small'));
      tr.appendChild(cell('td', c.old, 'small nowrap'));
      tr.appendChild(cell('td', c['new'], 'small nowrap'));
      tr.appendChild(cell('td', c.shift, 'small right'));
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    wrap.appendChild(table);
    box.appendChild(wrap);
  }

  doc.querySelectorAll('[data-preview-form]').forEach(function (f) {
    var btn = f.querySelector('[data-preview-button]');
    var box = f.querySelector('[data-preview-result]');
    if (!btn || !box) { return; }
    btn.addEventListener('click', function () {
      var fd = new FormData(f);
      fd.delete('action');
      fd.append('kind', f.getAttribute('data-preview-form'));
      fd.append('process_id', f.getAttribute('data-process-id'));
      btn.disabled = true;
      box.hidden = false;
      box.textContent = '';
      box.appendChild(cell('p', '…', 'muted small'));
      window.NPD.api('api/schedule-preview.php', { method: 'POST', body: fd })
        .then(function (data) { render(box, data); })
        .catch(function (err) {
          box.textContent = '';
          var msg = err.message;
          if (err.data && err.data.errors) {
            msg = Object.keys(err.data.errors).map(function (k) { return err.data.errors[k]; }).join(' ');
          }
          box.appendChild(cell('p', msg, 'flash flash-error small'));
        })
        .then(function () { btn.disabled = false; });
    });
  });
})();
