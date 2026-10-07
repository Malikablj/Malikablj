"""Timeline, Gantt, Tracker, Kalender di browser nyata (Chromium headless).
Memeriksa: panah dependency tergambar, zoom hari/minggu/bulan, toggle baseline, tabel ↔ Gantt,
HP: bawaan mode tabel & tanpa gulir horizontal halaman, mode gelap, agenda kalender, tanpa error JS.
Pemakaian: python3 tests/browser/timeline_flow.py BASE_URL PASSWORD OUTDIR PROJECT_ID
"""
import sys, os, re
from playwright.sync_api import sync_playwright

base, password, outdir, project_id = sys.argv[1:5]
os.makedirs(outdir, exist_ok=True)
CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'

def login(page, email):
    page.goto(base + '/login.php')
    page.fill('#email', email); page.fill('#password', password)
    page.click('button[type=submit].btn-primary')
    page.wait_for_url(re.compile(r'dashboard\.php'))

with sync_playwright() as p:
    b = p.chromium.launch(executable_path=CHROME)
    errors = []
    for scheme in ['light', 'dark']:
        ctx = b.new_context(viewport={'width': 1440, 'height': 900}, color_scheme=scheme)
        page = ctx.new_page()
        page.on('pageerror', lambda e: errors.append(str(e)))
        page.on('console', lambda m: errors.append(m.text) if m.type == 'error' else None)
        login(page, 'rizki@pik.local')
        page.evaluate('localStorage.clear()')
        page.goto(f'{base}/timeline.php?project={project_id}')
        page.wait_for_timeout(500)
        page.screenshot(path=f'{outdir}/timeline-l1-{scheme}.png')
        part = page.locator('.gantt-row.kind-part .gantt-label a').first
        part.click()
        page.wait_for_url(re.compile(r'timeline\.php\?part=\d+'))
        page.wait_for_timeout(600)
        arrows = page.locator('.gantt-arrows > path').count()
        print(scheme, 'arrows', arrows)
        assert arrows >= 10, 'panah dependency tergambar'
        g = page.locator('[data-gantt]')
        assert g.get_attribute('data-zoom') == 'week'
        w_week = page.evaluate("getComputedStyle(document.querySelector('[data-gantt]')).getPropertyValue('--day')")
        page.click('[data-gantt-zoom="day"]')
        assert g.get_attribute('data-zoom') == 'day'
        w_day = page.evaluate("getComputedStyle(document.querySelector('[data-gantt]')).getPropertyValue('--day')")
        assert w_day != w_week, (w_day, w_week)
        page.wait_for_timeout(200)
        assert page.locator('.gantt-arrows > path').count() == arrows, 'panah digambar ulang setelah zoom'
        # baseline toggle
        page.uncheck('[data-gantt-toggle="baseline"]')
        assert not page.locator('.gantt-inner').evaluate("e => e.classList.contains('show-baseline')")
        page.check('[data-gantt-toggle="baseline"]')
        page.check('[data-gantt-toggle="critical"]')
        page.screenshot(path=f'{outdir}/timeline-l2-{scheme}.png')
        # tabel
        page.click('[data-view-set="table"]')
        assert page.locator('[data-view-table] table').is_visible()
        assert not page.locator('[data-view-gantt]').is_visible()
        page.click('[data-view-set="gantt"]')
        # Gantt lintas project, tracker, kalender
        page.goto(base + '/gantt.php?level=part')
        assert page.locator('.gantt-row').count() >= 1
        page.goto(base + '/tracker.php')
        assert page.locator('.tracker-card').count() >= 1
        page.screenshot(path=f'{outdir}/tracker-{scheme}.png')
        page.goto(base + '/calendar.php')
        page.screenshot(path=f'{outdir}/calendar-{scheme}.png')
        if scheme == 'light':
            page.click('[data-open-dialog="dlg-agenda"]')
            page.fill('#ag-title', 'Review jadwal mingguan')
            page.click('#dlg-agenda button[type=submit]')
            page.wait_for_load_state('networkidle')
            assert 'Review jadwal mingguan' in page.content()
        ctx.close()

    m = b.new_context(viewport={'width': 390, 'height': 844}, is_mobile=True, color_scheme='dark')
    mp = m.new_page()
    mp.on('pageerror', lambda e: errors.append(str(e)))
    login(mp, 'rizki@pik.local')
    mp.evaluate('localStorage.clear()')
    for path in [f'/timeline.php?project={project_id}', '/gantt.php', '/tracker.php', '/calendar.php']:
        mp.goto(base + path)
        mp.wait_for_timeout(300)
        sw = mp.evaluate('document.documentElement.scrollWidth')
        assert sw <= 392, (path, sw)
    mp.goto(f'{base}/timeline.php?project={project_id}')
    assert mp.locator('[data-view]').get_attribute('data-view') == 'table', 'HP: bawaan mode tabel'
    mp.screenshot(path=f'{outdir}/timeline-mobile.png', full_page=True)
    print('JS errors:', errors)
    assert not errors, errors
    b.close()
    print('OK')
