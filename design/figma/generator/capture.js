// Ubah halaman aplikasi menjadi SVG yang bisa diedit di Figma (teks, kotak, ikon vektor).
const fs = require('fs');
const path = require('path');
const { chromium } = require(process.env.PW_PATH);
const BASE = process.env.BASE || 'http://127.0.0.1:18788';
const TOOL = __dirname;
const OUT = process.env.OUT;
const APP = process.env.APP;
const bundle = fs.readFileSync(path.join(TOOL, 'dom-to-svg.bundle.js'), 'utf8');
const ICONS = path.join(TOOL, 'node_modules/bootstrap-icons/icons');
const logoSvg = fs.readFileSync(path.join(APP, 'public/assets/img/logo.svg'), 'utf8');

const screens = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));

async function prepare(page) {
  // ikon Bootstrap Icons (font) → SVG vektor
  const names = await page.$$eval('i.bi, i[class*="bi-"]', els => [...new Set(els.flatMap(e => [...e.classList].filter(c => c.startsWith('bi-'))))]);
  const icons = {};
  for (const n of names) {
    const f = path.join(ICONS, n.slice(3) + '.svg');
    if (fs.existsSync(f)) icons[n] = fs.readFileSync(f, 'utf8');
  }
  await page.evaluate(({ icons, logoSvg }) => {
    const svgFrom = (markup) => { const t = document.createElement('template'); t.innerHTML = markup.trim(); return t.content.firstElementChild; };
    // elemen yang tidak terlihat (skip link, label pembaca layar, menu/modal tertutup) tidak ikut ke desain
    document.querySelectorAll('.skip-link, .visually-hidden, .visually-hidden-focusable, .offcanvas:not(.show):not(.showing), .dropdown-menu:not(.show), .modal:not(.show), .flash-stack:empty, template, noscript').forEach(e => e.remove());
    // panel geser (menu HP) tanpa transform/animasi agar tergambar di posisi akhirnya
    document.querySelectorAll('.offcanvas.show, .offcanvas.showing').forEach(e => { e.style.transition = 'none'; e.style.transform = 'none'; e.style.visibility = 'visible'; });
    document.querySelectorAll('.offcanvas-backdrop').forEach(e => { e.style.transition = 'none'; });
    // position:fixed → absolute di posisi yang sama (kanvas = tinggi halaman penuh), agar urutan lapisan benar
    [...document.querySelectorAll('body *')].filter(e => getComputedStyle(e).position === 'fixed').map(e => [e, e.getBoundingClientRect()]).forEach(([e, r]) => {
      Object.assign(e.style, { position: 'absolute', top: r.top + window.scrollY + 'px', left: r.left + 'px', right: 'auto', bottom: 'auto', width: r.width + 'px', height: r.height + 'px', margin: '0', transform: 'none' });
    });
    // lapisan atas (latar gelap + menu HP) dipindah ke akhir dokumen agar tergambar di atas konten
    ['.offcanvas-backdrop', '.offcanvas.show'].forEach(sel => document.querySelectorAll(sel).forEach(e => { e.style.zIndex = 'auto'; document.body.appendChild(e); }));
    document.querySelectorAll('.offcanvas.show').forEach(e => { e.style.backgroundColor = getComputedStyle(e).backgroundColor === 'rgba(0, 0, 0, 0)' ? '#FFFFFF' : getComputedStyle(e).backgroundColor; });
    // elemen yang tidak tampil (display:none di layar ini) dibuang: SVG inline tetap tergambar walau induknya tersembunyi
    document.querySelectorAll('i.bi, i[class*="bi-"], img, svg').forEach(e => { if (!e.getClientRects().length) e.remove(); });
    document.querySelectorAll('i.bi, i[class*="bi-"]').forEach((i) => {
      const name = [...i.classList].find(c => c.startsWith('bi-') && icons[c]);
      if (!name) return;
      const cs = getComputedStyle(i);
      if (cs.display === 'none') return;
      const size = parseFloat(cs.fontSize) || 16;
      const r = i.getBoundingClientRect();
      const svg = svgFrom(icons[name]);
      svg.setAttribute('width', size); svg.setAttribute('height', size);
      svg.removeAttribute('class');
      svg.querySelectorAll('path, circle, rect').forEach(p => p.setAttribute('fill', cs.color));
      svg.setAttribute('fill', cs.color);
      svg.style.cssText = `display:block;width:${size}px;height:${size}px;`;
      // kotak pembungkus seukuran ikon aslinya agar tata letak tidak bergeser
      const box = document.createElement('span');
      let css = `display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;width:${r.width}px;height:${r.height}px;vertical-align:${cs.verticalAlign};`
        + `margin:${cs.marginTop} ${cs.marginRight} ${cs.marginBottom} ${cs.marginLeft};`;
      if (cs.position === 'absolute' || cs.position === 'fixed') {
        const parent = i.offsetParent || document.body;
        const pr = parent.getBoundingClientRect();
        const pcs = getComputedStyle(parent);
        css += `position:absolute;z-index:${cs.zIndex === 'auto' ? 1 : cs.zIndex};margin:0;transform:none;left:${r.left - pr.left - parseFloat(pcs.borderLeftWidth)}px;top:${r.top - pr.top - parseFloat(pcs.borderTopWidth)}px;`;
      }
      box.style.cssText = css;
      if (cs.position === 'absolute' || cs.position === 'fixed') box.setAttribute('data-abs-icon', '1');
      box.appendChild(svg);
      i.replaceWith(box);
    });
    // tombol tutup Bootstrap (gambar latar SVG) → ikon × vektor
    document.querySelectorAll('.btn-close').forEach((b) => {
      if (!b.getClientRects().length) return;
      const cs = getComputedStyle(b);
      b.style.backgroundImage = 'none'; b.style.display = 'inline-flex'; b.style.alignItems = 'center'; b.style.justifyContent = 'center';
      const x = svgFrom('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="14" height="14"><path fill="#3A3A3C" d="M.293.293a1 1 0 0 1 1.414 0L8 6.586 14.293.293a1 1 0 1 1 1.414 1.414L9.414 8l6.293 6.293a1 1 0 0 1-1.414 1.414L8 9.414l-6.293 6.293a1 1 0 0 1-1.414-1.414L6.586 8 .293 1.707a1 1 0 0 1 0-1.414"/></svg>');
      x.style.cssText = 'display:block;width:14px;height:14px;opacity:' + cs.opacity;
      b.style.opacity = '1';
      b.appendChild(x);
    });
    // logo <img src=logo.svg> → SVG inline
    document.querySelectorAll('img[src*="logo.svg"]').forEach((img) => {
      const svg = svgFrom(logoSvg);
      const w = img.getBoundingClientRect().width || img.width;
      svg.setAttribute('width', w); svg.setAttribute('height', w);
      svg.style.cssText = `display:block;flex-shrink:0;width:${w}px;height:${w}px;`;
      img.replaceWith(svg);
    });
    // kontrol form → kotak + teks (Figma tidak mengenal <input>)
    const copy = ['width', 'height', 'boxSizing', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
      'borderTopStyle', 'borderRightStyle', 'borderBottomStyle', 'borderLeftStyle', 'borderTopColor', 'borderRightColor', 'borderBottomColor', 'borderLeftColor',
      'borderTopLeftRadius', 'borderTopRightRadius', 'borderBottomLeftRadius', 'borderBottomRightRadius', 'backgroundColor', 'fontFamily', 'fontSize', 'fontWeight', 'color',
      'marginTop', 'marginRight', 'marginBottom', 'marginLeft', 'flexGrow', 'flexShrink', 'flexBasis', 'minWidth', 'maxWidth', 'lineHeight', 'letterSpacing', 'textAlign', 'verticalAlign', 'float'];
    document.querySelectorAll('input, select, textarea').forEach((el) => {
      const type = (el.getAttribute('type') || '').toLowerCase();
      if (['hidden', 'submit', 'button'].includes(type)) return;
      const cs = getComputedStyle(el);
      if (cs.display === 'none' || cs.visibility === 'hidden') return;
      const r = el.getBoundingClientRect();
      if (r.width === 0 && r.height === 0) return;
      const box = document.createElement('div');
      box.setAttribute('data-input-box', '1');
      for (const p of copy) box.style[p] = cs[p];
      box.style.width = r.width + 'px'; box.style.height = r.height + 'px';
      box.style.display = cs.display.startsWith('inline') ? 'inline-flex' : 'flex';
      box.style.alignItems = el.tagName === 'TEXTAREA' ? 'flex-start' : 'center';
      box.style.overflow = 'hidden'; box.style.whiteSpace = el.tagName === 'TEXTAREA' ? 'pre-wrap' : 'nowrap';
      box.style.backgroundImage = 'none'; box.style.boxShadow = 'none';
      if (type === 'checkbox' || type === 'radio') {
        const on = el.checked || el.indeterminate;
        box.style.justifyContent = 'center';
        box.style.backgroundColor = on ? '#0071E3' : '#FFFFFF';
        box.style.borderColor = on ? '#0071E3' : '#C7C7CC';
        box.style.borderStyle = 'solid'; box.style.borderWidth = '1px';
        box.style.borderRadius = type === 'radio' ? '50%' : (el.closest('.form-switch') ? '1em' : '4px');
        if (on) { box.innerHTML = el.indeterminate ? '<span style="color:#fff;font:700 11px Inter;line-height:1">–</span>' : '<span style="color:#fff;font:700 11px Inter;line-height:1">✓</span>'; }
        el.replaceWith(box);
        return;
      }
      let text = '';
      let muted = false;
      if (el.tagName === 'SELECT') {
        text = el.selectedOptions[0] ? el.selectedOptions[0].textContent.trim() : '';
        box.style.justifyContent = 'space-between';
      } else if (type === 'date') {
        text = el.value ? el.value.split('-').reverse().join('/') : 'dd/mm/yyyy';
        muted = !el.value;
        box.style.justifyContent = 'space-between';
      } else if (type === 'password') {
        text = el.value ? '•'.repeat(el.value.length) : (el.placeholder || '');
        muted = !el.value;
      } else {
        text = el.value || el.placeholder || '';
        muted = !el.value;
      }
      const span = document.createElement('span');
      span.textContent = text;
      span.style.overflow = 'hidden'; span.style.textOverflow = 'clip'; span.style.flex = '1 1 auto'; span.style.minWidth = '0';
      if (muted) span.style.color = '#8E8E93';
      box.appendChild(span);
      if (el.tagName === 'SELECT' || type === 'date') {
        const ch = svgFrom(el.tagName === 'SELECT'
          ? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="12" height="12"><path fill="#6E6E73" fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708"/></svg>'
          : '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="14" height="14"><path fill="#6E6E73" d="M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5M1 4v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4z"/></svg>');
        ch.style.cssText = 'flex-shrink:0;margin-left:8px;';
        box.appendChild(ch);
      }
      if (el.tagName === 'SELECT') { box.style.paddingRight = '12px'; }
      el.replaceWith(box);
    });
    // ikon melayang (mis. kaca pembesar di kolom cari) digambar setelah kotak input di sebelahnya
    document.querySelectorAll('[data-abs-icon]').forEach((e) => {
      const input = [...e.parentNode.children].find(c => c.hasAttribute('data-input-box'));
      if (input) {
        const ir = e.getBoundingClientRect(), br = input.getBoundingClientRect(), bcs = getComputedStyle(input);
        if (bcs.position === 'static') input.style.position = 'relative';
        e.style.left = (ir.left - br.left - parseFloat(bcs.borderLeftWidth)) + 'px';
        e.style.top = (ir.top - br.top - parseFloat(bcs.borderTopWidth)) + 'px';
        e.style.zIndex = 'auto';
        input.insertBefore(e, input.firstChild);
      } else {
        e.parentNode.appendChild(e);
      }
      e.removeAttribute('data-abs-icon');
    });
    document.querySelectorAll('[data-input-box]').forEach(e => e.removeAttribute('data-input-box'));
    // animasi/transisi tidak relevan untuk gambar statis
    const st = document.createElement('style');
    st.textContent = '*{transition:none!important;animation:none!important;caret-color:transparent!important}';
    document.head.appendChild(st);
  }, { icons, logoSvg });
}

function postProcess(svg) {
  // @font-face web tidak dipakai Figma
  svg = svg.replace(/<style>[\s\S]*?<\/style>/g, '').replace(/<style\/>/g, '');
  // mask kotak (overflow) → clipPath (didukung penuh Figma); mask ikon/garis tidak perlu dipotong
  const masks = {};
  svg = svg.replace(/<mask id="([^"]+)">([\s\S]*?)<\/mask>/g, (m, id, body) => {
    const rects = body.match(/^(<rect [^>]*\/>)+$/);
    if (!rects) return m;
    if (/^mask-for-(svg|dropdown-divider)/.test(id)) { masks[id] = 'drop'; return ''; }
    masks[id] = 'clip';
    return `<clipPath id="${id}">${body.replace(/ fill="[^"]*"/g, '')}</clipPath>`;
  });
  svg = svg.replace(/ mask="url\(#([^)]+)\)"/g, (m, id) => masks[id] === 'drop' ? '' : masks[id] === 'clip' ? ` clip-path="url(#${id})"` : m);
  // font web "Inter Variable" → "Inter" (tersedia di Figma); berat huruf variabel → kelipatan 100
  svg = svg.replace(/font-family="[^"]*"/g, (m) => /bootstrap-icons/.test(m) ? m : 'font-family="Inter"');
  svg = svg.replace(/font-weight="(\d+)"/g, (m, w) => `font-weight="${Math.min(900, Math.max(100, Math.round((Number(w) - 1) / 100) * 100))}"`);
  return svg;
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const sessions = {};
  for (const s of screens) {
    const key = (s.role || 'guest') + '@' + s.width;
    if (!sessions[key]) {
      const ctx = await browser.newContext({ viewport: { width: s.width, height: 900 }, bypassCSP: true, deviceScaleFactor: 1 });
      const p = await ctx.newPage();
      if (s.role) {
        await p.goto(BASE + '/login');
        await p.fill('input[name=email]', s.role + '@pik.test');
        await p.fill('input[name=password]', 'PikDev2026!');
        await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
      }
      sessions[key] = p;
    }
    const page = sessions[key];
    await page.setViewportSize({ width: s.width, height: 900 });
    await page.goto(s.file ? 'file://' + path.join(TOOL, s.file) : BASE + s.path);
    await page.evaluate(() => document.fonts.ready);
    if (s.actions) {
      for (const a of s.actions) {
        if (a.check) { const boxes = await page.$$(a.check); for (const b of boxes.slice(0, a.count || boxes.length)) await b.check(); }
        if (a.click) { await page.click(a.click); }
        if (a.fill) { await page.fill(a.fill, a.value); }
        if (a.wait) { await page.waitForTimeout(a.wait); }
      }
    }
    // tinggi kanvas = tinggi halaman penuh (sidebar & latar ikut memanjang)
    const h = s.height || await page.evaluate(() => Math.max(document.documentElement.scrollHeight, document.body.scrollHeight));
    await page.setViewportSize({ width: s.width, height: Math.min(h, 6000) });
    await page.waitForTimeout(200);
    await page.screenshot({ path: path.join(OUT, 'png', s.name + '.png') });
    await prepare(page);
    await page.addScriptTag({ content: bundle });
    let svg = await page.evaluate(async () => {
      const doc = DomToSvg.documentToSVG(document);
      const root = doc.documentElement;
      await DomToSvg.inlineResources(root);
      return new XMLSerializer().serializeToString(doc);
    });
    svg = postProcess(svg);
    fs.writeFileSync(path.join(OUT, 'svg', s.name + '.svg'), svg);
    console.log('✓', s.name, s.width + 'x' + h, Math.round(svg.length / 1024) + ' KB');
  }
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
