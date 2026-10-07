/* Gantt (PRD §6.6, §6.8): zoom hari/minggu/bulan, gulir ke hari ini, toggle baseline / jalur kritis /
 * dependency, panah dependency (SVG) dan tampilan tabel ↔ Gantt. Data & posisi batang dibuat di server. */
(function () {
  'use strict';

  var doc = document;
  var SVG = 'http://www.w3.org/2000/svg';

  function store(key, value) {
    try {
      if (value === undefined) { return window.localStorage.getItem(key); }
      window.localStorage.setItem(key, value);
    } catch (e) { /* penyimpanan browser tidak tersedia: abaikan */ }
    return null;
  }

  function dayWidth(g) {
    return parseFloat(getComputedStyle(g).getPropertyValue('--day')) || 12;
  }

  function labelWidth(g) {
    return parseFloat(getComputedStyle(g).getPropertyValue('--label')) || 0;
  }

  function scrollToday(g, smooth) {
    var sc = g.querySelector('.gantt-scroll');
    var off = parseInt(g.getAttribute('data-today-offset'), 10);
    if (!sc || isNaN(off)) { return; }
    var x = off * dayWidth(g) - (sc.clientWidth - labelWidth(g)) * 0.3;
    sc.scrollTo({ left: Math.max(0, x), behavior: smooth ? 'smooth' : 'auto' });
  }

  function drawArrows(g) {
    var inner = g.querySelector('.gantt-inner');
    var svg = g.querySelector('.gantt-arrows');
    if (!inner || !svg) { return; }
    while (svg.firstChild) { svg.removeChild(svg.firstChild); }
    if (!inner.classList.contains('show-deps')) { return; }
    var base = inner.getBoundingClientRect();
    svg.setAttribute('width', inner.scrollWidth);
    svg.setAttribute('height', inner.scrollHeight);
    var markerId = (g.id || 'gantt') + '-arrow';
    var defs = doc.createElementNS(SVG, 'defs');
    var marker = doc.createElementNS(SVG, 'marker');
    marker.setAttribute('id', markerId);
    marker.setAttribute('viewBox', '0 0 8 8');
    marker.setAttribute('refX', '7');
    marker.setAttribute('refY', '4');
    marker.setAttribute('markerWidth', '7');
    marker.setAttribute('markerHeight', '7');
    marker.setAttribute('orient', 'auto');
    var tip = doc.createElementNS(SVG, 'path');
    tip.setAttribute('d', 'M0,0 L8,4 L0,8 z');
    marker.appendChild(tip);
    defs.appendChild(marker);
    svg.appendChild(defs);

    var bars = {};
    inner.querySelectorAll('[data-bar]').forEach(function (b) { bars[b.getAttribute('data-bar')] = b; });
    inner.querySelectorAll('[data-deps]').forEach(function (succ) {
      var s = succ.getBoundingClientRect();
      succ.getAttribute('data-deps').split(',').forEach(function (pair) {
        var parts = pair.split(':');
        var pred = bars[parts[0]];
        var type = parts[1] || 'FS';
        if (!pred || type === 'PARALLEL') { return; }
        var p = pred.getBoundingClientRect();
        var x1 = (type === 'SS' ? p.left : p.right) - base.left;
        var y1 = p.top + p.height / 2 - base.top;
        var x2 = (type === 'FF' ? s.right : s.left) - base.left;
        var y2 = s.top + s.height / 2 - base.top;
        var bend = type === 'FF' ? Math.max(x1, x2) + 8 : (type === 'SS' ? Math.min(x1, x2) - 8 : x1 + 6);
        var d = 'M' + x1 + ',' + y1 + ' H' + bend + ' V' + y2 + ' H' + x2;
        var path = doc.createElementNS(SVG, 'path');
        path.setAttribute('d', d);
        path.setAttribute('marker-end', 'url(#' + markerId + ')');
        if (type !== 'FS') { path.setAttribute('class', 'is-ss'); }
        svg.appendChild(path);
      });
    });
  }

  function init(g) {
    var key = 'npd.gantt.zoom';
    var saved = store(key);
    if (saved && /^(day|week|month)$/.test(saved) && !g.hasAttribute('data-zoom-fixed')) {
      setZoom(g, saved);
    }
    g.addEventListener('click', function (e) {
      var z = e.target.closest('[data-gantt-zoom]');
      if (z) {
        setZoom(g, z.getAttribute('data-gantt-zoom'));
        store(key, z.getAttribute('data-gantt-zoom'));
        scrollToday(g, false);
      }
      if (e.target.closest('[data-gantt-today]')) { scrollToday(g, true); }
    });
    g.addEventListener('change', function (e) {
      var t = e.target.getAttribute('data-gantt-toggle');
      if (!t) { return; }
      var inner = g.querySelector('.gantt-inner');
      inner.classList.toggle('show-' + t, e.target.checked);
      drawArrows(g);
    });
    // panah digambar setelah animasi batang selesai agar posisi akurat
    setTimeout(function () { drawArrows(g); }, 320);
    scrollToday(g, false);
  }

  function setZoom(g, z) {
    g.setAttribute('data-zoom', z);
    g.querySelectorAll('[data-gantt-zoom]').forEach(function (b) {
      var on = b.getAttribute('data-gantt-zoom') === z;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    requestAnimationFrame(function () { drawArrows(g); });
  }

  var gantts = doc.querySelectorAll('[data-gantt]');
  gantts.forEach(init);

  var t = null;
  window.addEventListener('resize', function () {
    clearTimeout(t);
    t = setTimeout(function () { gantts.forEach(drawArrows); }, 150);
  });

  // --- Tampilan tabel ↔ Gantt (bawaan HP: tabel/daftar, PRD §11.6) ---
  doc.querySelectorAll('[data-view]').forEach(function (box) {
    var key = 'npd.timeline.view';
    var pref = store(key);
    var mobile = window.matchMedia('(max-width: 640px)').matches;
    var view = pref || box.getAttribute('data-view') || 'gantt';
    if (mobile && !pref) { view = 'table'; }
    apply(view);
    box.addEventListener('click', function (e) {
      var b = e.target.closest('[data-view-set]');
      if (!b) { return; }
      apply(b.getAttribute('data-view-set'));
      store(key, b.getAttribute('data-view-set'));
    });
    function apply(v) {
      box.setAttribute('data-view', v);
      box.querySelectorAll('[data-view-set]').forEach(function (b) {
        var on = b.getAttribute('data-view-set') === v;
        b.classList.toggle('is-active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      if (v === 'gantt') {
        box.querySelectorAll('[data-gantt]').forEach(function (g) {
          requestAnimationFrame(function () { scrollToday(g, false); drawArrows(g); });
        });
      }
    }
  });
})();
