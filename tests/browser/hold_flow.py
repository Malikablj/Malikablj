"""Alur Hold/Resume/Cancel/Arsip di browser nyata (Chromium headless) — UAT-16/17/24:
menu Tindakan project → dialog Hold (alasan wajib) → banner Hold → halaman Resume (pratinjau server lalu simpan)
→ riwayat Hold → Cancel part → Arsip & pulihkan (Admin) → mobile & dark mode, tanpa error JavaScript.
Pemakaian: python3 tests/browser/hold_flow.py BASE_URL PASSWORD OUTDIR PROJECT_ID
"""
import sys, os, re
from playwright.sync_api import sync_playwright

base, password, outdir, project_id = sys.argv[1:5]
os.makedirs(outdir, exist_ok=True)
CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'
project_url = f'{base}/project.php?id={project_id}'

def login(page, email):
    page.goto(base + '/login.php')
    page.fill('#email', email); page.fill('#password', password)
    page.click('button[type=submit].btn-primary')
    page.wait_for_url(re.compile(r'dashboard\.php'))

def logout(page):
    page.locator('.user-menu summary').click()
    page.locator('.user-menu button[type=submit]').click()
    page.wait_for_url(re.compile(r'login\.php'))

def flash(page):
    el = page.locator('.flash').first
    return el.inner_text() if el.count() else ''

def no_hscroll(page, label):
    w = page.evaluate('document.documentElement.scrollWidth - window.innerWidth')
    assert w <= 1, f'{label}: scroll horizontal {w}px'

with sync_playwright() as p:
    b = p.chromium.launch(executable_path=CHROME)
    ctx = b.new_context(viewport={'width': 1440, 'height': 960})
    page = ctx.new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.on('console', lambda m: errors.append(m.text) if m.type == 'error' and 'status of 422' not in m.text else None)

    # --- NPD: Hold project lewat menu Tindakan project
    login(page, 'rizki@pik.local')
    page.goto(project_url)
    page.locator('.project-menu summary').click()
    page.screenshot(path=f'{outdir}/01-actions-menu.png')
    page.locator('.project-menu [data-open-dialog="dlg-hold-0"]').click()
    dlg = page.locator('dialog[open]')
    assert dlg.count() == 1, 'dialog Hold terbuka'
    assert page.locator('.project-menu[open]').count() == 0, 'menu tertutup saat dialog dibuka'
    # alasan wajib (validasi browser + server)
    dlg.locator('button[type=submit]').click()
    assert page.locator('dialog[open]').count() == 1, 'tanpa alasan tidak terkirim'
    dlg.locator('textarea[name=reason]').fill('Customer menunda launching <b>Q1</b>')
    dlg.locator('input[name=expected_resume_date]').fill('2026-11-30')
    page.screenshot(path=f'{outdir}/02-hold-dialog.png')
    dlg.locator('button[type=submit]').click()
    page.wait_for_load_state('networkidle')
    print('hold:', flash(page))
    banner = page.locator('.hold-banner')
    assert banner.count() == 1, 'banner Hold tampil'
    assert 'Customer menunda launching <b>Q1</b>' in banner.inner_text(), 'alasan tampil apa adanya (di-escape)'
    assert page.locator('.overdue-banner').count() == 0, 'tidak ada banner overdue selama Hold'
    assert 'Hold' in page.locator('.page-header .badge').first.inner_text()
    page.screenshot(path=f'{outdir}/03-project-on-hold.png', full_page=True)

    # --- Resume: pratinjau server lalu simpan
    page.locator('.project-menu summary').click()
    page.locator('.project-menu a[href*="resume.php"]').click()
    page.wait_for_url(re.compile(r'resume\.php'))
    assert page.locator('button[value=save]').count() == 0, 'Simpan muncul setelah pratinjau'
    rows = page.locator('input[name^="remaining["]')
    assert rows.count() >= 2, 'proses berjalan dengan durasi sisa'
    page.fill('#rs-target', '2027-06-30')
    page.fill('#rs-note', 'Customer melanjutkan')
    page.screenshot(path=f'{outdir}/04-resume-form.png', full_page=True)
    page.locator('button[value=preview]').click()
    page.wait_for_load_state('networkidle')
    assert page.locator('#sec-preview').count() == 1, 'pratinjau tampil'
    preview_text = page.locator('#sec-preview').inner_text()
    print('preview:', preview_text.splitlines()[1] if len(preview_text.splitlines()) > 1 else preview_text)
    page.screenshot(path=f'{outdir}/05-resume-preview.png', full_page=True)
    page.locator('button[value=save]').click()
    page.wait_for_url(re.compile(r'tab=history'))
    print('resume:', flash(page))
    assert page.locator('.hold-banner').count() == 0, 'banner Hold hilang setelah Resume'
    hold_table = page.locator('#sec-hold').locator('xpath=ancestor::section[1]')
    assert 'Customer melanjutkan' in hold_table.inner_text()
    page.screenshot(path=f'{outdir}/06-history-hold.png', full_page=True)

    # --- Cancel part kedua (Cap)
    page.goto(project_url)
    page.locator('.card-footer [data-open-dialog^="dlg-cancel-"]').nth(1).click()
    dlg = page.locator('dialog[open]')
    dlg.locator('textarea[name=reason]').fill('Customer membatalkan Cap')
    page.screenshot(path=f'{outdir}/07-cancel-part-dialog.png')
    dlg.locator('button[type=submit]').click()
    page.wait_for_load_state('networkidle')
    print('cancel part:', flash(page))
    assert 'Customer membatalkan Cap' in page.content()
    logout(page)

    # --- Admin: arsip & pulihkan
    login(page, 'andi@pik.local')
    page.goto(project_url)
    code = page.locator('.page-header .eyebrow').inner_text().split(' · ')[0].strip()
    page.locator('.project-menu summary').click()
    page.locator('.project-menu [data-open-dialog="dlg-archive"]').click()
    page.locator('dialog[open] textarea[name=reason]').fill('Arsip uji UAT-24')
    page.locator('dialog[open] button[type=submit]').click()
    page.wait_for_load_state('networkidle')
    print('archive:', flash(page))
    page.screenshot(path=f'{outdir}/08-archived.png', full_page=True)
    page.goto(base + '/projects.php')
    assert code not in page.locator('table').inner_text(), 'project arsip hilang dari daftar bawaan'
    page.goto(base + '/projects.php?archived=1')
    assert code in page.locator('table').inner_text(), 'project muncul di filter Arsip'
    page.goto(project_url)
    page.locator('.project-menu summary').click()
    page.locator('.project-menu [data-open-dialog="dlg-restore"]').click()
    page.locator('dialog[open] textarea[name=reason]').fill('Aktif kembali')
    page.locator('dialog[open] button[type=submit]').click()
    page.wait_for_load_state('networkidle')
    print('restore:', flash(page))
    page.goto(base + '/projects.php')
    assert code in page.locator('table').inner_text(), 'project kembali di daftar'

    # --- dark mode & mobile
    page.goto(project_url)
    page.evaluate("document.documentElement.setAttribute('data-theme', 'dark')")
    page.locator('.project-menu summary').click()
    page.screenshot(path=f'{outdir}/09-dark-menu.png')
    bg = page.evaluate("getComputedStyle(document.body).backgroundColor")
    assert bg in ('rgb(0, 0, 0)',), f'dark bg {bg}'
    mctx = b.new_context(viewport={'width': 390, 'height': 844}, is_mobile=True, has_touch=True)
    m = mctx.new_page()
    m.on('pageerror', lambda e: errors.append(str(e)))
    login(m, 'rizki@pik.local')
    m.goto(project_url)
    no_hscroll(m, 'project mobile')
    m.locator('.project-menu summary').click()
    m.locator('.project-menu [data-open-dialog="dlg-hold-0"]').click()
    m.screenshot(path=f'{outdir}/10-mobile-hold-dialog.png')
    m.locator('dialog[open] textarea[name=reason]').fill('Uji mobile')
    m.locator('dialog[open] button[type=submit]').click()
    m.wait_for_load_state('networkidle')
    m.goto(f'{base}/resume.php?project={project_id}')
    no_hscroll(m, 'resume mobile')
    m.fill('#rs-target', '2027-06-30')
    m.locator('button[value=preview]').click()
    m.wait_for_load_state('networkidle')
    no_hscroll(m, 'resume preview mobile')
    m.screenshot(path=f'{outdir}/11-mobile-resume-preview.png', full_page=True)

    assert not errors, errors
    print('OK hold_flow')
    b.close()
