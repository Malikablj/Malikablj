"""Ambil screenshot halaman (light/dark, desktop/mobile) untuk pemeriksaan visual.
Pemakaian: python3 tests/browser/screens.py BASE_URL EMAIL PASSWORD OUTDIR path1 path2 ...
"""
import sys, os
from playwright.sync_api import sync_playwright

base, email, password, outdir = sys.argv[1:5]
paths = sys.argv[5:] or ['dashboard.php']
os.makedirs(outdir, exist_ok=True)
with sync_playwright() as p:
    browser = p.chromium.launch(executable_path='/opt/pw-browsers/chromium-1194/chrome-linux/chrome')
    for scheme in ('light', 'dark'):
        for name, vp in (('desktop', {'width': 1366, 'height': 860}), ('mobile', {'width': 390, 'height': 844})):
            ctx = browser.new_context(viewport=vp, color_scheme=scheme, device_scale_factor=1)
            page = ctx.new_page()
            page.goto(base + '/login.php')
            page.screenshot(path=f'{outdir}/login-{scheme}-{name}.png', full_page=True)
            page.fill('#email', email); page.fill('#password', password)
            page.click('button[type=submit].btn-primary')
            page.wait_for_load_state('networkidle')
            for path in paths:
                page.goto(base + '/' + path)
                page.wait_for_load_state('networkidle')
                slug = path.replace('/', '_').replace('.php', '').replace('?', '_').replace('=', '-').replace('&', '_')
                page.screenshot(path=f'{outdir}/{slug}-{scheme}-{name}.png', full_page=True)
            ctx.close()
    browser.close()
print('ok')
