"""Dashboard & Laporan di browser nyata (Chromium headless) — PRD §10, UAT-20/21/22:
kartu KPI → daftar terfilter, Panel Overdue → proses, filter dashboard, Laporan (periode Mingguan/Bulanan/Rentang,
salin teks, export Excel), KPI per PIC + drill-down + export PDF (Admin), Sales tidak melihat KPI,
mobile tanpa scroll horizontal, mode gelap, tanpa error JavaScript.
Pemakaian: python3 tests/browser/report_flow.py BASE_URL PASSWORD OUTDIR
"""
import sys, os, re
from playwright.sync_api import sync_playwright

base, password, outdir = sys.argv[1:4]
os.makedirs(outdir, exist_ok=True)
CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'

def login(page, email):
    page.goto(base + '/login.php')
    page.fill('#email', email); page.fill('#password', password)
    page.click('button[type=submit].btn-primary')
    page.wait_for_url(re.compile(r'dashboard\.php'))

def logout(page):
    page.locator('.user-menu summary').click()
    page.locator('.user-menu button[type=submit]').click()
    page.wait_for_url(re.compile(r'login\.php'))

def no_hscroll(page, label):
    w = page.evaluate('document.documentElement.scrollWidth - window.innerWidth')
    assert w <= 1, f'{label}: scroll horizontal {w}px'

with sync_playwright() as p:
    b = p.chromium.launch(executable_path=CHROME)
    ctx = b.new_context(viewport={'width': 1440, 'height': 960}, accept_downloads=True)
    page = ctx.new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    # 403 dari akses KPI oleh Sales memang diharapkan (otorisasi server), bukan error JavaScript
    page.on('console', lambda m: errors.append(m.text) if m.type == 'error' and 'status of 403' not in m.text else None)

    login(page, 'andi@pik.local')
    assert page.locator('[data-kpi]').count() == 8, '8 kartu KPI'
    total = int(page.locator('[data-kpi="total"] .kpi-value').get_attribute('data-value'))
    overdue = int(page.locator('[data-kpi="overdue"] .kpi-value').get_attribute('data-value'))
    print('cards: total', total, 'overdue', overdue)
    assert page.locator('.chart-card').count() == 6
    page.screenshot(path=f'{outdir}/01-dashboard.png', full_page=True)
    # kartu Overdue → daftar project terfilter dengan jumlah yang sama
    page.locator('[data-kpi="overdue"]').click()
    page.wait_for_url(re.compile(r'projects\.php\?.*overdue=1'))
    rows = page.locator('table tbody tr:not(:has(.table-empty))').count()
    assert rows == overdue, f'daftar overdue {rows} ≠ kartu {overdue}'
    # Panel Overdue → proses
    page.goto(base + '/dashboard.php')
    if overdue:
        page.locator('#overdue tbody tr a[href*="process.php"]').first.click()
        page.wait_for_url(re.compile(r'process\.php'))
    # filter dashboard (autosubmit)
    page.goto(base + '/dashboard.php')
    page.select_option('#d-type', 'subcont')
    page.wait_for_url(re.compile(r'part_type=subcont'))
    assert int(page.locator('[data-kpi="new_mold"] .kpi-value').get_attribute('data-value')) <= total

    # Laporan: Weekly
    page.goto(base + '/reports.php')
    assert page.locator('#r-week').is_visible() and not page.locator('#r-month').is_visible(), 'isian minggu saja'
    page.select_option('#r-period', 'month')
    assert page.locator('#r-month').is_visible() and not page.locator('#r-week').is_visible()
    page.select_option('#r-period', 'range')
    assert page.locator('#r-from').is_visible() and page.locator('#r-to').is_visible()
    page.fill('#r-from', '2026-09-01'); page.fill('#r-to', '2026-10-31')
    page.locator('.period-form button[type=submit]').click()
    page.wait_for_url(re.compile(r'period=range'))
    assert '2026' in page.locator('.period-nav strong').inner_text()
    page.locator('[data-copy-target="weekly-text"]').click()
    page.wait_for_selector('.toast', timeout=3000)
    page.screenshot(path=f'{outdir}/02-weekly.png', full_page=True)
    with page.expect_download() as dl:
        page.locator('.page-actions a[href*="weekly_xlsx"]').click()
    d = dl.value
    assert d.suggested_filename.endswith('.xlsx'), d.suggested_filename
    print('weekly export:', d.suggested_filename)
    page.locator('.period-nav a').first.click()
    page.wait_for_load_state('networkidle')

    # Analytics
    page.goto(base + '/reports.php?tab=analytics&period=month&month=2026-10')
    assert page.locator('[data-loop]').count() == 3
    page.screenshot(path=f'{outdir}/03-analytics.png', full_page=True)

    # KPI per PIC + drill-down + PDF
    page.goto(base + '/reports.php?tab=kpi&period=month&month=2026-10')
    rows = page.locator('[data-kpi-table] tbody tr')
    print('kpi rows:', rows.count())
    if page.locator('[data-kpi-table] tbody a').count():
        page.locator('[data-kpi-table] tbody a').first.click()
        page.wait_for_selector('#kpi-drill')
    page.screenshot(path=f'{outdir}/04-kpi.png', full_page=True)
    with page.expect_download() as dl:
        page.locator('.page-actions a[href*="kpi_pdf"]').click()
    assert dl.value.suggested_filename.endswith('.pdf')
    print('kpi pdf:', dl.value.suggested_filename)
    logout(page)

    # Sales: tab KPI tidak ada, akses langsung 403
    login(page, 'sari@pik.local')
    page.goto(base + '/reports.php')
    assert page.locator('.tabs a[href*="tab=kpi"]').count() == 0
    resp = page.goto(base + '/reports.php?tab=kpi')
    assert resp.status == 403, resp.status
    page.goto(base + '/dashboard.php')
    logout(page)

    # dark mode + mobile
    login(page, 'farhan@pik.local')
    page.evaluate("document.documentElement.setAttribute('data-theme', 'dark')")
    assert page.evaluate("getComputedStyle(document.body).backgroundColor") == 'rgb(0, 0, 0)'
    page.screenshot(path=f'{outdir}/05-dashboard-dark.png', full_page=True)
    m = b.new_context(viewport={'width': 390, 'height': 844}, is_mobile=True, has_touch=True).new_page()
    m.on('pageerror', lambda e: errors.append(str(e)))
    login(m, 'farhan@pik.local')
    for path in ['/dashboard.php', '/reports.php', '/reports.php?tab=analytics', '/reports.php?tab=kpi', '/projects.php']:
        m.goto(base + path)
        no_hscroll(m, path)
    m.goto(base + '/dashboard.php')
    m.screenshot(path=f'{outdir}/06-dashboard-mobile.png', full_page=True)

    assert not errors, errors
    print('OK report_flow')
    b.close()
