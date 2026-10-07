"""Alur project/proses di browser nyata (Chromium headless), memakai project hasil feedback NPR:
daftar project → detail (3 tab) → proses: pratinjau & simpan planning, editor dependency (pratinjau,
lingkaran ditolak), unggah dokumen, tetapkan PIC part, selesaikan proses + keputusan Not Approved (loop),
mobile & dark mode, tanpa error JavaScript.
Pemakaian: python3 tests/browser/project_flow.py BASE_URL PASSWORD OUTDIR PROJECT_ID
"""
import sys, os, re
from playwright.sync_api import sync_playwright

base, password, outdir, project_id = sys.argv[1:5]
os.makedirs(outdir, exist_ok=True)
CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'
FIXTURE = os.path.join(os.path.dirname(__file__), 'fixtures', 'bottle.png')

def login(page, email):
    page.goto(base + '/login.php')
    page.fill('#email', email); page.fill('#password', password)
    page.click('button[type=submit].btn-primary')
    page.wait_for_url(re.compile(r'dashboard\.php'))

def process_url(page, code, part_index=None):
    """Cari tautan proses berdasarkan kode di tab Proses."""
    page.goto(f'{base}/project.php?id={project_id}&tab=processes')
    sections = page.locator('section.card')
    sec = sections.nth(0 if part_index is None else part_index + 1)
    link = sec.locator('a', has_text=re.compile('^' + code + ' · '))
    return link.first.get_attribute('href')

def go(page, href):
    page.goto(href if href.startswith('http') else base + href)

def flash(page):
    el = page.locator('.flash').first
    return el.inner_text() if el.count() else ''

with sync_playwright() as p:
    b = p.chromium.launch(executable_path=CHROME)
    ctx = b.new_context(viewport={'width': 1440, 'height': 960})
    page = ctx.new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    # 422 dari pratinjau lingkaran memang diharapkan (validasi server), bukan error JavaScript
    page.on('console', lambda m: errors.append(m.text) if m.type == 'error' and 'status of 422' not in m.text else None)
    page.on('dialog', lambda d: d.accept())

    login(page, 'rizki@pik.local')
    page.goto(base + '/projects.php')
    assert page.locator('table tbody tr').count() >= 1, 'project tampil di daftar'
    page.screenshot(path=f'{outdir}/01-projects.png', full_page=True)

    page.goto(f'{base}/project.php?id={project_id}')
    page.screenshot(path=f'{outdir}/02-project-overview.png', full_page=True)
    # PIC part: tetapkan drafter untuk part pertama
    page.locator('[data-open-dialog^="dlg-pics-"]').first.click()
    dlg = page.locator('dialog[open]')
    dlg.locator('select[name="pic[drafter]"]').select_option(label='Dimas Saputra')
    dlg.locator('button[type=submit]').click()
    page.wait_for_load_state('networkidle')
    print('pics:', flash(page))
    assert 'Dimas Saputra' in page.content()

    page.goto(f'{base}/project.php?id={project_id}&tab=processes')
    page.screenshot(path=f'{outdir}/03-project-processes.png', full_page=True)
    page.goto(f'{base}/project.php?id={project_id}&tab=history')
    page.screenshot(path=f'{outdir}/04-project-history.png', full_page=True)

    # --- N5: planning (pratinjau → simpan) ---
    n5 = process_url(page, 'N5', 0)
    go(page, n5)
    page.fill('#pl-duration', '6')
    page.click('[data-preview-form="plan"] [data-preview-button]')
    page.wait_for_selector('[data-preview-form="plan"] [data-preview-result] table')
    preview_text = page.locator('[data-preview-form="plan"] [data-preview-result]').inner_text()
    print('plan preview rows:', preview_text.count('\n'))
    assert 'N6' in preview_text, 'pratinjau menampilkan proses turunan'
    page.screenshot(path=f'{outdir}/05-process-plan-preview.png', full_page=True)
    page.click('[data-preview-form="plan"] button[type=submit]')
    page.wait_for_load_state('networkidle')
    print('plan:', flash(page))
    assert '6 hari kerja' in page.content()

    # --- dependency: lingkaran ditolak di pratinjau ---
    page.click('text=Sesuaikan dependency')
    rows = page.locator('[data-dep-row]')
    last = rows.nth(rows.count() - 1)
    # pilih N6 (successor N5) sebagai predecessor → lingkaran
    opt = last.locator('select[name$="[predecessor_id]"] option', has_text=re.compile(r'N6 '))
    last.locator('select[name$="[predecessor_id]"]').select_option(opt.first.get_attribute('value'))
    page.click('[data-preview-form="dependency"] [data-preview-button]')
    page.wait_for_selector('[data-preview-form="dependency"] .flash-error')
    cycle = page.locator('[data-preview-form="dependency"] .flash-error').inner_text()
    print('cycle:', cycle)
    assert 'lingkaran' in cycle
    page.screenshot(path=f'{outdir}/06-process-deps-cycle.png', full_page=True)
    # tambah baris lalu hapus (JS)
    before = page.locator('[data-dep-row]').count()
    page.click('[data-dep-add]')
    assert page.locator('[data-dep-row]').count() == before + 1
    page.locator('[data-dep-row]').last.locator('[data-dep-remove]').click()
    page.locator('[data-dep-row]').last.locator('[data-dep-remove]').click()
    assert page.locator('[data-dep-row]').count() == before - 1

    # --- N3 (drafter ditetapkan): unggah dokumen & selesaikan ---
    n3 = process_url(page, 'N3', 0)
    go(page, n3)
    page.select_option('#doc-type', 'prototype_3d_document')
    page.set_input_files('#doc-file', FIXTURE)
    page.click('button:has-text("Unggah")')
    page.wait_for_load_state('networkidle')
    print('upload:', flash(page))
    assert 'bottle.png' in page.content()
    page.click('[data-complete-form] button[type=submit]')
    page.wait_for_load_state('networkidle')
    print('complete N3:', flash(page))

    # --- N4: Not Approved → komentar wajib → loop ke N3 ---
    n4 = process_url(page, 'N4', 0)
    go(page, n4)
    if page.locator('form[data-confirm] button:has-text("Mulai lebih awal")').count():
        page.click('form[data-confirm] button:has-text("Mulai lebih awal")')
        page.wait_for_selector('.modal-confirm, dialog[open]', timeout=3000)
        page.locator('dialog[open] .btn-primary, dialog[open] .btn-danger').first.click()
        page.wait_for_load_state('networkidle')
        print('start early:', flash(page))
    page.check('input[name="outcome"][value="not_approved"]')
    assert page.locator('#comment').evaluate('e => e.required'), 'komentar wajib untuk Not Approved'
    page.fill('#comment', 'Bentuk leher kurang ramping')
    page.fill('#decision-maker', 'Ibu Rina (Sanitya)')
    page.screenshot(path=f'{outdir}/07-process-decision.png', full_page=True)
    page.click('[data-complete-form] button[type=submit]')
    page.wait_for_load_state('networkidle')
    print('N4 not approved:', flash(page))
    assert 'kembali' in flash(page).lower()

    # --- mobile + dark ---
    m = b.new_context(viewport={'width': 390, 'height': 844}, color_scheme='dark', is_mobile=True)
    mp = m.new_page()
    mp.on('pageerror', lambda e: errors.append(str(e)))
    login(mp, 'rizki@pik.local')
    mp.goto(f'{base}/project.php?id={project_id}')
    mp.screenshot(path=f'{outdir}/08-mobile-dark-project.png', full_page=True)
    go(mp, n4)
    mp.screenshot(path=f'{outdir}/09-mobile-dark-process.png', full_page=True)
    sw = mp.evaluate('document.documentElement.scrollWidth')
    print('mobile scrollWidth:', sw)
    assert sw <= 392, 'tidak ada scroll horizontal halaman'

    # --- Sales (read + own) tidak melihat tombol planning ---
    s = b.new_context(viewport={'width': 1280, 'height': 900})
    sp = s.new_page()
    login(sp, 'budi@pik.local')
    go(sp, n4)
    assert sp.locator('[data-preview-form="plan"]').count() == 0, 'Sales lain tidak melihat planning'
    assert sp.locator('[data-complete-form]').count() == 0, 'Sales bukan PIC tidak bisa menyelesaikan'

    print('JS errors:', errors)
    assert not errors, errors
    b.close()
    print('OK')
