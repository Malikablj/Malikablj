"""Audit UI otomatis (PRD §11.3, §11.6, §11.8; UAT-21/22) di Chromium headless.
Untuk setiap halaman × tema (terang/gelap) × perangkat (desktop/tablet/HP) memeriksa:
  - tidak ada scroll horizontal halaman,
  - mode gelap: latar body #000000 dan tidak ada permukaan terang,
  - kontras teks WCAG AA (4.5:1; 3:1 untuk teks besar),
  - target sentuh ≥ 44 px untuk tombol/tautan navigasi pada tablet & HP,
  - setiap kontrol form berlabel, setiap gambar ber-alt,
  - fokus keyboard terlihat (desktop), tanpa error JavaScript.
Pemakaian: python3 tests/browser/ui_audit.py BASE_URL EMAIL PASSWORD OUTDIR path1 [path2 ...]
  path diawali "~"      → diaudit tanpa login (mis. ~/login.php)
  path "url|selektor"   → klik selektor dulu (mis. membuka dialog) lalu audit
Keluar dengan kode 1 bila ada temuan; ringkasan JSON ditulis ke OUTDIR/ui-audit.json.
"""
import sys, os, re, json
from playwright.sync_api import sync_playwright

base, email, password, outdir = sys.argv[1:5]
paths = sys.argv[5:]
os.makedirs(outdir, exist_ok=True)
CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'
DEVICES = {
    'desktop': dict(viewport={'width': 1440, 'height': 900}),
    'tablet': dict(viewport={'width': 820, 'height': 1180}, is_mobile=True, has_touch=True),
    'mobile': dict(viewport={'width': 390, 'height': 844}, is_mobile=True, has_touch=True),
}

AUDIT_JS = r"""
(opts) => {
  const out = {lightSurfaces: [], contrast: [], targets: [], labels: [], alts: []};
  const parse = (c) => { const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(',').map(x => parseFloat(x)); return {r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1}; };
  const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const blend = (top, bottom) => ({r: top.r * top.a + bottom.r * (1 - top.a), g: top.g * top.a + bottom.g * (1 - top.a), b: top.b * top.a + bottom.b * (1 - top.a), a: 1});
  const visible = (el) => { const r = el.getBoundingClientRect(); if (r.width < 1 || r.height < 1) return false; const s = getComputedStyle(el); return s.visibility !== 'hidden' && s.display !== 'none' && parseFloat(s.opacity) > 0.05 && !el.closest('[hidden], dialog:not([open]), .visually-hidden, [aria-hidden="true"]'); };
  const bgOf = (el) => {
    const stack = [];
    for (let e = el; e; e = e.parentElement) { const c = parse(getComputedStyle(e).backgroundColor); if (c && c.a > 0) { stack.push(c); if (c.a >= 0.99) break; } }
    let col = {r: 255, g: 255, b: 255, a: 1};
    if (stack.length && stack[stack.length - 1].a >= 0.99) col = stack.pop();
    else col = parse(getComputedStyle(document.body).backgroundColor) || col;
    while (stack.length) col = blend(stack.pop(), col);
    return col;
  };
  const desc = (el) => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '');
  const all = Array.from(document.querySelectorAll('body *'));
  for (const el of all) {
    if (!visible(el) || el.closest('svg, .brand-link, img, .gantt-bar, .bar-fill, .legend-swatch, .swatch, .progress')) continue;
    const s = getComputedStyle(el);
    if (opts.dark) {
      const c = parse(s.backgroundColor); const r = el.getBoundingClientRect();
      if (c && c.a > 0.6 && lum(c) > 0.45 && r.width * r.height > 300) out.lightSurfaces.push(desc(el) + ' ' + s.backgroundColor);
    }
    const ownText = Array.from(el.childNodes).some(n => n.nodeType === 3 && n.textContent.trim().length > 1);
    if (ownText && !el.closest('[disabled], fieldset[disabled]') && !el.matches(':disabled')) {
      const fg = parse(s.color); if (!fg) continue;
      const bg = bgOf(el); const f = blend(fg, bg);
      const L1 = lum(f), L2 = lum(bg); const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
      const size = parseFloat(s.fontSize), bold = parseInt(s.fontWeight, 10) >= 700;
      const need = (size >= 24 || (bold && size >= 18.66)) ? 3 : 4.5;
      if (ratio < need - 0.05) out.contrast.push(desc(el) + ' ' + ratio.toFixed(2) + ' "' + el.textContent.trim().slice(0, 30) + '"');
    }
  }
  if (opts.touch) {
    for (const el of document.querySelectorAll('.btn, .icon-btn, .sidebar-nav a, .tabs a, .segmented-item, .user-chip, .pagination a')) {
      if (!visible(el)) continue; const r = el.getBoundingClientRect();
      if (r.height < 43.5 || r.width < 43.5) out.targets.push(desc(el) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height) + ' "' + el.textContent.trim().slice(0, 20) + '"');
    }
  }
  for (const el of document.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]), select, textarea')) {
    if (!visible(el)) continue;
    const ok = el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.closest('label') || (el.id && document.querySelector('label[for="' + CSS.escape(el.id) + '"]')) || el.getAttribute('title');
    if (!ok) out.labels.push(desc(el) + ' name=' + el.getAttribute('name'));
  }
  for (const img of document.querySelectorAll('img')) { if (!img.hasAttribute('alt')) out.alts.push(desc(img)); }
  out.bodyBg = getComputedStyle(document.body).backgroundColor;
  out.hscroll = document.documentElement.scrollWidth - window.innerWidth;
  return out;
}
"""

def login(page):
    page.goto(base + '/login.php')
    page.fill('#email', email); page.fill('#password', password)
    page.click('button[type=submit].btn-primary')
    page.wait_for_url(re.compile(r'dashboard\.php'))

report = {}
issues = 0
with sync_playwright() as p:
    b = p.chromium.launch(executable_path=CHROME)
    for dev, opts in DEVICES.items():
        for theme in ('light', 'dark'):
            ctx = b.new_context(color_scheme=theme, **opts)
            page = ctx.new_page()
            errors = []
            page.on('pageerror', lambda e, errors=errors: errors.append(str(e)))
            page.on('console', lambda m, errors=errors: errors.append(m.text) if m.type == 'error' else None)
            guest = ctx.browser.new_context(color_scheme=theme, **opts).new_page()
            guest.on('pageerror', lambda e, errors=errors: errors.append(str(e)))
            login(page)
            for path in paths:
                errors.clear()
                pg = guest if path.startswith('~') else page
                url, _, click = path.lstrip('~').partition('|')
                resp = pg.goto(base + url)
                pg.wait_for_load_state('networkidle')
                if click:
                    pg.locator(click).first.click()
                pg.wait_for_timeout(300)  # animasi masuk selesai
                r = pg.evaluate(AUDIT_JS, {'dark': theme == 'dark', 'touch': dev != 'desktop'})
                found = {}
                if resp and resp.status >= 400: found['status'] = resp.status
                if r['hscroll'] > 1: found['hscroll'] = r['hscroll']
                if theme == 'dark' and r['bodyBg'] != 'rgb(0, 0, 0)': found['bodyBg'] = r['bodyBg']
                for k in ('lightSurfaces', 'contrast', 'targets', 'labels', 'alts'):
                    if r[k]: found[k] = sorted(set(r[k]))[:12]
                if errors: found['js'] = errors[:5]
                if dev == 'desktop' and theme == 'light' and not click:
                    pg.keyboard.press('Tab'); pg.keyboard.press('Tab')
                    fv = pg.evaluate("(() => { const a = document.activeElement; if (!a || a === document.body) return 'none'; const s = getComputedStyle(a); return (s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0) || (s.boxShadow && s.boxShadow !== 'none') ? 'ok' : a.tagName + '.' + a.className; })()")
                    if fv != 'ok': found['focus'] = fv
                if found:
                    report[f'{dev}/{theme}{path}'] = found
                    issues += sum(len(v) if isinstance(v, list) else 1 for v in found.values())
            guest.context.close()
            ctx.close()
    b.close()

with open(os.path.join(outdir, 'ui-audit.json'), 'w') as f:
    json.dump(report, f, indent=1, ensure_ascii=False)
for k, v in report.items():
    print(k, json.dumps(v, ensure_ascii=False)[:600])
print('pages x variants:', len(paths) * 6, 'issues:', issues)
sys.exit(1 if issues else 0)
