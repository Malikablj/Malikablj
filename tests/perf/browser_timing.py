"""Waktu muat halaman di browser nyata (Chromium headless) terhadap data volume — PRD §13.3:
halaman utama ≤ 2 dtk, daftar/Gantt besar tidak membekukan antarmuka (long task dicatat).
Mengukur Navigation Timing (DOMContentLoaded, load) dan long task terpanjang per halaman, desktop & HP.
Pemakaian: python3 tests/perf/browser_timing.py BASE_URL PASSWORD PROJECT_ID_TERBESAR [OUT_JSON]
Server harus memakai database *_perf, mis.: DB_NAME=npd_perf php -d opcache.enable_cli=1 -S 127.0.0.1:8090 -t public
"""
import sys, re, json, statistics
from playwright.sync_api import sync_playwright

base, password, big = sys.argv[1:4]
out = sys.argv[4] if len(sys.argv) > 4 else None
CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'
RUNS = 3
PAGES = [
    ('Dashboard', '/dashboard.php'),
    ('Daftar project', '/projects.php'),
    ('Detail project', '/project.php?id={big}'),
    ('Timeline project', '/timeline.php?project={big}'),
    ('Gantt lintas project', '/gantt.php'),
    ('Gantt per part', '/gantt.php?level=part'),
    ('Tracker', '/tracker.php'),
    ('Kalender', '/calendar.php?month=2026-10'),
    ('Daftar NPR', '/npr.php'),
    ('Laporan mingguan', '/reports.php'),
    ('KPI per PIC Jan–Okt', '/reports.php?tab=kpi&period=range&from=2026-01-01&to=2026-10-07'),
]
LONGTASK = """
window.__lt = [];
try { new PerformanceObserver(l => l.getEntries().forEach(e => window.__lt.push(e.duration))).observe({type: 'longtask', buffered: true}); } catch (e) {}
"""

def login(page):
    page.goto(base + '/login.php')
    page.fill('#email', 'andi@pik.local'); page.fill('#password', password)
    page.click('button[type=submit].btn-primary')
    page.wait_for_url(re.compile(r'dashboard\.php'))

results, failures = [], []
with sync_playwright() as p:
    b = p.chromium.launch(executable_path=CHROME)
    for device, opts in [('desktop', {'viewport': {'width': 1440, 'height': 900}}),
                         ('hp', {'viewport': {'width': 390, 'height': 844}, 'is_mobile': True, 'has_touch': True})]:
        ctx = b.new_context(**opts)
        ctx.add_init_script(LONGTASK)
        page = ctx.new_page()
        errors = []
        page.on('pageerror', lambda e: errors.append(str(e)))
        login(page)
        for label, path in PAGES:
            url = base + path.format(big=big)
            page.goto(url)  # pemanasan
            dcl, load, lt = [], [], []
            for _ in range(RUNS):
                page.goto(url, wait_until='load')
                t = page.evaluate("(() => { const n = performance.getEntriesByType('navigation')[0]; return [n.domContentLoadedEventEnd, n.loadEventEnd]; })()")
                dcl.append(t[0]); load.append(t[1])
                lt.append(max(page.evaluate('window.__lt') or [0]))
            nodes = page.evaluate('document.getElementsByTagName("*").length')
            r = {'device': device, 'label': label, 'dcl_ms': round(statistics.median(dcl)), 'load_ms': round(statistics.median(load)),
                 'load_max_ms': round(max(load)), 'longest_task_ms': round(max(lt)), 'dom_nodes': nodes}
            results.append(r)
            if max(load) > 2000:
                failures.append(f"{device} {label}: load {round(max(load))} ms > 2000 ms")
        if errors:
            failures.append(f'{device}: error JavaScript {errors}')
        ctx.close()
    b.close()

print('| Perangkat | Halaman | DOMContentLoaded (ms) | Load median (ms) | Load maks (ms) | Long task terpanjang (ms) | Elemen DOM |')
print('|---|---|---|---|---|---|---|')
for r in results:
    print(f"| {r['device']} | {r['label']} | {r['dcl_ms']} | {r['load_ms']} | {r['load_max_ms']} | {r['longest_task_ms']} | {r['dom_nodes']} |")
if out:
    with open(out, 'w') as f:
        json.dump({'results': results, 'failures': failures}, f, indent=2, ensure_ascii=False)
print('GAGAL: ' + '; '.join(failures) if failures else 'Semua halaman ≤ 2 dtk.')
sys.exit(1 if failures else 0)
