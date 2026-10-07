"""Alur NPR end-to-end di browser nyata (Chromium headless):
Sales membuat NPR 3 part → kirim → NPD isi feedback → selesaikan → Sales lihat feedback → PDF.
Pemakaian: python3 tests/browser/npr_flow.py BASE_URL PASSWORD OUTDIR
"""
import sys, os, re
from playwright.sync_api import sync_playwright, expect

base, password, outdir = sys.argv[1:4]
os.makedirs(outdir, exist_ok=True)
CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'

def login(page, email):
    page.goto(base + '/login.php')
    page.fill('#email', email); page.fill('#password', password)
    page.click('button[type=submit].btn-primary')
    page.wait_for_url(re.compile(r'dashboard\.php'))

with sync_playwright() as p:
    b = p.chromium.launch(executable_path=CHROME)
    ctx = b.new_context(viewport={'width': 1440, 'height': 900})
    page = ctx.new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.on('console', lambda m: errors.append(m.text) if m.type == 'error' else None)

    login(page, 'sari@pik.local')
    page.goto(base + '/npr.php')
    page.click('text=NPR Baru')
    page.wait_for_url(re.compile(r'npr-edit\.php\?id=\d+'))
    npr_url = page.url
    page.fill('[name="npr[product_name]"]', 'Botol Lotion 250ml Premium')
    page.check('input[name="npr[request_types][]"][value="produk_baru"]')
    page.select_option('[name="npr[customer_id]"]', label='PT Sanitya Utama (SNT)')
    page.wait_for_timeout(200)
    assert page.input_value('[name="npr[phone]"]') == '021-5551234', 'prefill telepon customer'
    page.check('input[name="npr[product_applications][]"][value="kosmetik"]')
    page.check('input[name="npr[product_contents][]"][value="cair"]')
    page.fill('[name="npr[net_volume_ml]"]', '250')
    # part 1
    part1 = page.locator('[data-part]').nth(0)
    part1.locator('select[data-part-name]').select_option('body')
    part1.locator('input[value="new_mold"]').check()
    part1.locator('select[name$="[development_type]"]').select_option('new_mould')
    part1.locator('select[name$="[resin_code]"]').select_option('hdpe')
    part1.locator('select[name$="[color_code]"]').select_option('opaque')
    part1.locator('select[name$="[surface_code]"]').select_option('glossy')
    # autosave terjadi
    page.wait_for_timeout(2500)
    status = page.locator('[data-autosave-status]').inner_text()
    print('autosave:', status)
    # tambah part 2 (Cap Subcont, existing mould)
    page.click('button[value="add_part"]')
    page.wait_for_load_state('networkidle')
    part2 = page.locator('[data-part]').nth(1)
    part2.locator('select[data-part-name]').select_option('cap')
    part2.locator('input[value="subcont"]').check()
    part2.locator('select[name$="[development_type]"]').select_option('existing_mould')
    part2.locator('input[name$="[mold_supplier]"]').fill('PT Supplier Cap')
    part2.locator('select[name$="[resin_code]"]').select_option('pp')
    part2.locator('select[name$="[color_code]"]').select_option('dark_light')
    part2.locator('select[name$="[surface_code]"]').select_option('matte')
    # tambah part 3 "Lainnya"
    page.click('button[value="add_part"]')
    page.wait_for_load_state('networkidle')
    part3 = page.locator('[data-part]').nth(2)
    part3.locator('select[data-part-name]').select_option('__other')
    expect(part3.locator('input[name$="[part_name_custom]"]')).to_be_visible()
    part3.locator('input[name$="[part_name_custom]"]').fill('Plug Khusus')
    part3.locator('input[value="new_mold"]').check()
    part3.locator('select[name$="[development_type]"]').select_option('new_mould')
    part3.locator('select[name$="[resin_code]"]').select_option('ldpe')
    part3.locator('select[name$="[color_code]"]').select_option('translucent')
    part3.locator('select[name$="[surface_code]"]').select_option('glossy')
    page.fill('[name="npr[qty_per_month]"]', '50000')
    page.fill('[name="npr[qty_per_year]"]', '600000')
    page.check('input[name="npr[packaging][]"][value="box"]')
    page.check('input[name="npr[regulation_compliance]"][value="no"]')
    for k in ['attach_sample', 'attach_technical_drawing', 'attach_mockup']:
        page.check(f'input[name="npr[{k}]"][value="ada"]')
    page.fill('[name="npr[launching_target]"]', '2027-03-31')
    page.set_input_files('#att-product_shape', os.path.join(os.path.dirname(__file__), 'fixtures', 'bottle.png'))
    page.click('button[value="upload"]')
    page.wait_for_load_state('networkidle')
    assert page.locator('.attach-thumb').count() == 1, 'gambar lampiran tampil'
    page.screenshot(path=f'{outdir}/npr-draft-sales.png', full_page=True)
    # kirim (dialog konfirmasi bertema)
    page.click('button[value="submit"]')
    page.click('dialog.modal [data-yes]')
    page.wait_for_load_state('networkidle')
    flash = page.locator('.flash').first.inner_text()
    print('submit:', flash)
    assert '/PIK/NPR/' in flash
    assert page.locator('[name="npr[product_name]"]').is_disabled(), 'biru terkunci'
    page.screenshot(path=f'{outdir}/npr-submitted-sales.png', full_page=True)
    ctx.clear_cookies()

    # NPD mengisi feedback
    login(page, 'rizki@pik.local')
    page.goto(npr_url)
    parts = page.locator('[data-part]')
    def fb(i, decision, mould=True):
        pr = parts.nth(i)
        pr.locator('input[name$="[weight_gr]"]').fill('22.5')
        if mould:
            pr.locator('select[name$="[mould_method_code]"]').select_option('extrusion_blow')
            pr.locator('input[name$="[cavity]"]').fill('4')
            pr.locator('input[name$="[mould_price_pik_pct]"]').fill('60')
            pr.locator('input[name$="[mould_price_pik_pct]"]').dispatch_event('change')
            pr.locator('input[name$="[mould_lead_time_days]"]').fill('45')
        pr.locator('select[name$="[needs_new_masterbatch]"]').select_option('1')
        pr.locator('select[name$="[decision]"]').select_option(decision)
        pr.locator('textarea[name$="[feedback_text]"]').fill('Feedback NPD untuk part ' + str(i + 1))
        if decision == 'not_feasible':
            pr.locator('textarea[name$="[decision_reason]"]').fill('Geometri plug tidak dapat dicetak')
    fb(0, 'feasible'); fb(1, 'feasible_with_notes', mould=False); fb(2, 'not_feasible', mould=False)
    assert parts.nth(0).locator('input[name$="[mould_price_cust_pct]"]').input_value() == '40', 'auto 100-PIK'
    page.screenshot(path=f'{outdir}/npr-feedback-npd.png', full_page=True)
    page.click('button[value="complete"]')
    page.click('dialog.modal [data-yes]')
    page.wait_for_load_state('networkidle')
    print('complete:', page.locator('.flash').first.inner_text())
    ctx.clear_cookies()

    # Sales melihat Feedback NPR
    login(page, 'sari@pik.local')
    page.goto(npr_url)
    summary = page.locator('#sec-feedback').inner_text()
    assert 'Tidak Feasible' in summary and 'Feasible dengan catatan' in summary, summary
    page.screenshot(path=f'{outdir}/npr-completed-sales.png', full_page=True)
    # PDF final
    with page.expect_download() as dl:
        page.click('text=Export PDF')
    d = dl.value
    path = os.path.join(outdir, d.suggested_filename)
    d.save_as(path)
    print('pdf:', d.suggested_filename, os.path.getsize(path))
    # mobile stepper
    m = b.new_context(viewport={'width': 390, 'height': 844}, color_scheme='dark')
    mp = m.new_page()
    mp.goto(base + '/login.php'); mp.fill('#email', 'sari@pik.local'); mp.fill('#password', password); mp.click('button[type=submit].btn-primary')
    mp.wait_for_url(re.compile(r'dashboard'))
    mp.goto(npr_url)
    expect(mp.locator('.stepper-nav')).to_be_visible()
    mp.screenshot(path=f'{outdir}/npr-mobile-dark.png', full_page=True)
    print('js errors:', errors)
    b.close()
