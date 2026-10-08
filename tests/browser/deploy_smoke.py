"""Smoke test pasca-deploy lewat web server produksi (HTTPS, sertifikat boleh self-signed):
login Admin → buat user Sales & customer → NPR baru → upload lampiran → unduh & bandingkan isi →
buka halaman utama tanpa error JavaScript/CSP → logout. Hanya memakai UI (tanpa akses database).
Pemakaian: python3 tests/browser/deploy_smoke.py BASE_URL ADMIN_EMAIL ADMIN_PASSWORD OUTDIR
"""
import sys, os, re, hashlib, secrets
from playwright.sync_api import sync_playwright

base, email, password, outdir = sys.argv[1:5]
os.makedirs(outdir, exist_ok=True)
CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'
FIXTURE = os.path.join(os.path.dirname(__file__), 'fixtures', 'bottle.png')
tag = secrets.token_hex(3)

with sync_playwright() as p:
    b = p.chromium.launch(executable_path=CHROME)
    ctx = b.new_context(viewport={'width': 1440, 'height': 900}, ignore_https_errors=True, accept_downloads=True)
    page = ctx.new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.on('console', lambda m: errors.append(m.text) if m.type == 'error' else None)

    page.goto(base + '/login.php')
    page.fill('#email', email); page.fill('#password', password)
    page.click('button[type=submit].btn-primary')
    page.wait_for_url(re.compile(r'(dashboard|profile)\.php'))
    print('login:', page.url)

    # user Sales baru
    page.goto(base + '/settings/users.php')
    page.click('[data-open-dialog="dlg-new-user"]')
    page.fill('#n-name', f'Sales Smoke {tag}')
    page.fill('#n-email', f'sales.{tag}@npd.local')
    page.select_option('#n-role', 'admin_sales')
    page.fill('#n-title', 'Admin Sales')
    page.fill('#n-pass', 'Smoke12345')
    page.click('#dlg-new-user button[type=submit].btn-primary')
    page.wait_for_load_state('networkidle')
    assert page.locator(f'text=sales.{tag}@npd.local').count() > 0, 'user baru tampil'

    # customer baru
    page.goto(base + '/settings/customers.php')
    page.click('[data-open-dialog="dlg-new-customer"]')
    dlg = page.locator('#dlg-new-customer')
    dlg.locator('[name=code]').fill(f'S{tag[:4]}'.upper())
    dlg.locator('[name=name]').fill(f'PT Smoke {tag}')
    dlg.locator('[name=invoice_address]').fill('Jl. Invoice 1')
    dlg.locator('[name=shipping_address]').fill('Jl. Gudang 2')
    dlg.locator('[name=phone]').fill('021-555')
    dlg.locator('button[type=submit].btn-primary').click()
    page.wait_for_load_state('networkidle')

    # NPR baru atas nama Sales, upload lampiran
    page.goto(base + '/npr.php')
    page.click('[data-open-dialog="dlg-new-npr"]')
    page.select_option('#new-sales', label=f'Sales Smoke {tag}')
    page.click('#dlg-new-npr button[type=submit].btn-primary')
    page.wait_for_url(re.compile(r'npr-edit\.php\?id=\d+'))
    page.fill('[name="npr[product_name]"]', f'Botol Smoke {tag}')
    # file melebihi batas ditolak di browser sebelum diunggah (server tetap memvalidasi)
    big = os.path.join(outdir, 'besar.png')
    limit = int(page.get_attribute('#att-product_shape', 'data-max-bytes'))
    with open(big, 'wb') as fh:
        fh.truncate(limit + 1)
    page.set_input_files('#att-product_shape', big)
    msg = page.eval_on_selector('#att-product_shape', 'el => el.validationMessage')
    assert 'besar.png' in msg and 'MB' in msg, msg
    assert not page.eval_on_selector('#att-product_shape', 'el => el.form.checkValidity()'), 'form tidak boleh terkirim'
    os.remove(big)
    print('batas unggah di browser:', msg)
    page.set_input_files('#att-product_shape', FIXTURE)
    assert page.eval_on_selector('#att-product_shape', 'el => el.validationMessage') == ''
    page.click('button[value="upload"]')
    page.wait_for_load_state('networkidle')
    link = page.locator('a[href*="download.php"]').first
    assert link.count() > 0, 'lampiran tampil'
    href = link.get_attribute('href')
    url = href if href.startswith('http') else base + '/' + href.lstrip('/')
    # unduh lewat sesi browser (tautan membuka gambar inline di tab baru)
    res = ctx.request.get(url.replace('inline=1', 'inline=0'))
    assert res.status == 200, res.status
    assert res.headers.get('content-disposition', '').startswith('attachment'), res.headers.get('content-disposition')
    assert res.headers.get('x-content-type-options') == 'nosniff'
    assert hashlib.sha256(res.body()).hexdigest() == hashlib.sha256(open(FIXTURE, 'rb').read()).hexdigest(), 'isi unduhan sama dengan file yang diunggah'
    print('upload/unduh:', link.inner_text(), 'identik')
    # tamu (tanpa sesi) tidak bisa mengunduh
    anon = b.new_context(ignore_https_errors=True)
    r = anon.request.get(url, max_redirects=0)
    assert r.status in (302, 303), f'tamu: {r.status}'
    anon.close()
    page.screenshot(path=f'{outdir}/npr.png', full_page=True)

    for path in ['/dashboard.php', '/projects.php', '/npr.php', '/gantt.php', '/tracker.php', '/calendar.php', '/documents.php',
                 '/approvals.php', '/reports.php', '/reports.php?tab=kpi', '/notifications.php', '/settings/audit.php', '/settings/email-queue.php']:
        r = page.goto(base + path)
        assert r.status == 200, f'{path}: {r.status}'
    page.screenshot(path=f'{outdir}/email-queue.png', full_page=True)

    page.locator('.user-menu summary').click()
    page.locator('.user-menu button[type=submit]').click()
    page.wait_for_url(re.compile(r'login\.php'))
    assert not errors, errors
    print('OK deploy_smoke')
    b.close()
