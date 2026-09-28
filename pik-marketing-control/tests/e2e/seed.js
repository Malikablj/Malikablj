/**
 * Seeds a realistic but clearly synthetic data set through the public API, so page tests
 * exercise the same validation and business rules as real users. Used only against the
 * disposable *_e2e database. Every name carries "Uji" so it can never pass for business data.
 */
import { addDays, E2E_PASSWORD, E2E_USERS } from './fixtures.js';

/** API client on a Playwright APIRequestContext, signed in as `user`. */
export async function apiAs(playwright, baseURL, user) {
  const context = await playwright.request.newContext({ baseURL, extraHTTPHeaders: { 'X-Requested-With': 'XMLHttpRequest' } });
  const call = async (method, path, data) => {
    const response = await context.fetch(`/api${path}`, { method, data });
    const body = await response.json().catch(() => null);
    if (!response.ok()) throw new Error(`${method} ${path} → ${response.status()}: ${JSON.stringify(body?.error ?? body)}`);
    return body.data;
  };
  await call('POST', '/auth/login', { email: user.email, password: E2E_PASSWORD });
  return {
    get: (path) => call('GET', path),
    post: (path, data) => call('POST', path, data ?? {}),
    put: (path, data) => call('PUT', path, data),
    patch: (path, data) => call('PATCH', path, data),
    dispose: () => context.dispose(),
  };
}

/**
 * Creates customers, contacts, products, leads in every stage, activities, follow-ups,
 * purchase orders with deliveries/returns, stock, lead time, maklon and invoices.
 * Nothing is owned by the Sales user, so the smoke test's "my follow-ups" stays isolated.
 */
export async function seedOperationalData(admin, today) {
  const users = Object.fromEntries((await admin.get('/users/options')).map((user) => [user.name, user.id]));
  const marketingId = users[E2E_USERS.marketing.name];
  const adminId = users[E2E_USERS.admin.name];

  const product = (values) => admin.post('/products', values);
  const bottle = await product({ name: 'Botol PET 100ml Uji', product_code: 'UJI-BTL-100', category: 'Botol', unit: 'pcs', lead_time_days: 14 });
  const cap = await product({ name: 'Tutup Flip Top 24mm Uji', product_code: 'UJI-CAP-24', category: 'Tutup', unit: 'pcs', lead_time_days: 10 });
  const jar = await product({ name: 'Jar Kaca 50ml Uji', product_code: 'UJI-JAR-50', category: 'Jar', unit: 'pcs', status: 'DEVELOPMENT' });

  const customer = async (values, contact) => {
    const created = await admin.post('/customers', values);
    const createdContact = contact ? await admin.post(`/customers/${created.id}/contacts`, { is_primary: true, ...contact }) : null;
    return { ...created, contact: createdContact };
  };
  const cosmetics = await customer(
    { name: 'PT Uji Kosmetika Nusantara', customer_code: 'UJI-C001', industry: 'Kosmetik', phone: '021-5550101', email: 'purchasing@uji-kosmetika.test', address: 'Jl. Uji Coba No. 1, Jakarta' },
    { name: 'Uji Rina Purchasing', position: 'Purchasing', whatsapp: '081200000001', email: 'rina@uji-kosmetika.test' },
  );
  const pharma = await customer(
    { name: 'CV Uji Farma Sejahtera', customer_code: 'UJI-C002', industry: 'Farmasi', phone: '031-5550202' },
    { name: 'Uji Budi Owner', position: 'Owner', whatsapp: '081200000002' },
  );
  const food = await customer({ name: 'PT Uji Pangan Lestari', customer_code: 'UJI-C003', industry: 'Makanan & Minuman', status: 'POTENTIAL' });

  const lead = (values) => admin.post('/leads', { owner_user_id: marketingId, priority: 'MEDIUM', ...values });
  const leads = {
    new: await lead({ customer_id: food.id, name: 'Jar kaca selai (uji)', status: 'NEW', estimated_value: 12_000_000, source: 'Website' }),
    contacted: await lead({ customer_id: pharma.id, contact_id: pharma.contact.id, name: 'Botol sirup 60ml (uji)', status: 'CONTACTED', estimated_value: 25_000_000 }),
    qualified: await lead({ customer_id: cosmetics.id, name: 'Tube krim 30g (uji)', status: 'QUALIFIED', priority: 'HIGH', estimated_value: 40_000_000 }),
    quotation: await lead({
      customer_id: cosmetics.id,
      contact_id: cosmetics.contact.id,
      product_id: bottle.id,
      name: 'Botol serum 100ml (uji)',
      status: 'QUOTATION',
      priority: 'HIGH',
      estimated_value: 85_000_000,
      expected_closing_date: addDays(today, 21),
    }),
    negotiation: await lead({ customer_id: pharma.id, product_id: cap.id, name: 'Tutup flip top (uji)', status: 'NEGOTIATION', estimated_value: 18_500_000 }),
    won: await lead({ customer_id: cosmetics.id, product_id: bottle.id, name: 'Botol toner repeat order (uji)', status: 'WON', estimated_value: 60_000_000 }),
    lost: await lead({ customer_id: food.id, name: 'Botol saus (uji)', status: 'LOST', estimated_value: 9_000_000, lost_reason: 'Harga kompetitor lebih rendah (uji)' }),
  };

  const at = (daysAgo, hour) => new Date(`${addDays(today, -daysAgo)}T${String(hour).padStart(2, '0')}:00:00+07:00`).toISOString();
  const activity = (values) => admin.post('/activities', { owner_user_id: marketingId, ...values });
  await activity({ customer_id: cosmetics.id, contact_id: cosmetics.contact.id, lead_id: leads.quotation.id, type: 'QUOTATION', subject: 'Kirim penawaran botol serum (uji)', description: 'Harga per 10.000 pcs, termin 30 hari.', activity_at: at(0, 9) });
  await activity({ customer_id: cosmetics.id, lead_id: leads.qualified.id, type: 'WHATSAPP', subject: 'Konfirmasi spesifikasi tube (uji)', activity_at: at(1, 14) });
  await activity({ customer_id: pharma.id, contact_id: pharma.contact.id, type: 'VISIT', subject: 'Kunjungan pabrik customer (uji)', description: 'Bertemu owner, bahas kebutuhan Q4.', activity_at: at(3, 10) });
  await activity({ customer_id: food.id, type: 'CALL', subject: 'Telepon perkenalan (uji)', activity_at: at(6, 11) });

  const followUp = (values) => admin.post('/follow-ups', { owner_user_id: marketingId, ...values });
  await followUp({ customer_id: cosmetics.id, lead_id: leads.quotation.id, follow_up_date: today, follow_up_time: '10:00', priority: 'HIGH', notes: 'Tanyakan keputusan penawaran (uji)' });
  await followUp({ customer_id: pharma.id, lead_id: leads.contacted.id, follow_up_date: addDays(today, -2), notes: 'Kirim katalog botol sirup (uji)' });
  await followUp({ customer_id: food.id, lead_id: leads.new.id, follow_up_date: addDays(today, 3), priority: 'LOW', notes: 'Jadwalkan presentasi (uji)' });
  const done = await followUp({ customer_id: cosmetics.id, follow_up_date: addDays(today, -5), notes: 'Konfirmasi sampel diterima (uji)' });
  await admin.post(`/follow-ups/${done.id}/complete`, { outcome: 'Sampel sesuai, lanjut penawaran (uji)' });

  const purchaseOrder = async (values) => {
    const created = await admin.post('/purchase-orders', { owner_user_id: marketingId, ...values });
    return admin.get(`/purchase-orders/${created.id}`);
  };
  const running = await purchaseOrder({
    po_number: 'UJI-PO-001',
    customer_id: cosmetics.id,
    po_date: addDays(today, -20),
    expected_delivery_date: addDays(today, 5),
    lines: [
      { product_id: bottle.id, order_quantity: 10_000, unit_price: 1_250 },
      { product_id: cap.id, order_quantity: 10_000, unit_price: 450 },
    ],
  });
  const late = await purchaseOrder({
    po_number: 'UJI-PO-002',
    customer_id: pharma.id,
    po_date: addDays(today, -40),
    expected_delivery_date: addDays(today, -3),
    lines: [{ product_id: cap.id, order_quantity: 5_000, unit_price: 500 }],
  });
  const closed = await purchaseOrder({
    po_number: 'UJI-PO-003',
    customer_id: cosmetics.id,
    po_date: addDays(today, -60),
    expected_delivery_date: addDays(today, -30),
    lines: [{ product_id: bottle.id, order_quantity: 2_000, unit_price: 1_200 }],
  });
  const cancelled = await purchaseOrder({
    po_number: 'UJI-PO-004',
    customer_id: food.id,
    po_date: addDays(today, -10),
    lines: [{ product_id: jar.id, order_quantity: 1_000 }],
  });

  const delivery = (po, line, values) => admin.post('/deliveries', { purchase_order_id: po.id, po_line_id: line.id, ...values });
  await delivery(running, running.lines[0], { delivery_date: addDays(today, -7), quantity: 4_000, status: 'DELIVERED', delivery_number: 'UJI-SJ-001' });
  await delivery(running, running.lines[0], { delivery_date: addDays(today, 2), quantity: 3_000, status: 'SCHEDULED' });
  await delivery(running, running.lines[1], { delivery_date: today, quantity: 5_000, status: 'ON_DELIVERY', delivery_number: 'UJI-SJ-002' });
  await delivery(late, late.lines[0], { delivery_date: addDays(today, -1), quantity: 2_000, status: 'DELAYED' });
  await delivery(closed, closed.lines[0], { delivery_date: addDays(today, -35), quantity: 2_000, status: 'DELIVERED', delivery_number: 'UJI-SJ-003' });
  await admin.patch(`/purchase-orders/${running.id}/status`, { status: 'PARTIAL' });
  await admin.patch(`/purchase-orders/${closed.id}/status`, { status: 'CLOSED' });
  await admin.patch(`/purchase-orders/${cancelled.id}/status`, { status: 'CANCELLED', cancel_reason: 'Customer menunda proyek (uji)' });

  await admin.post('/returns', {
    customer_id: cosmetics.id,
    purchase_order_id: running.id,
    po_line_id: running.lines[0].id,
    product_id: bottle.id,
    return_date: addDays(today, -4),
    quantity: 150,
    reason: 'Cacat cetak pada label (uji)',
    status: 'RECEIVED',
  });

  await admin.post('/stock', { product_id: bottle.id, stock_type: 'FG', quantity: 12_500, warehouse: 'Gudang Uji A', stock_date: addDays(today, -1) });
  await admin.post('/stock', { product_id: bottle.id, stock_type: 'WIP', quantity: 3_000, warehouse: 'Gudang Uji A', stock_date: addDays(today, -1) });
  await admin.post('/stock', { product_id: cap.id, stock_type: 'FG', quantity: 8_000, warehouse: 'Gudang Uji B', stock_date: today });
  await admin.post('/lead-times', { product_id: bottle.id, customer_id: cosmetics.id, lead_time_days: 21, notes: 'Termasuk cetak sablon (uji)' });
  await admin.post('/inbound-maklon', { customer_id: pharma.id, purchase_order_id: late.id, item_name: 'Label cetak customer (uji)', inbound_date: addDays(today, -8), quantity: 5_000, unit: 'lembar', document_number: 'UJI-DOK-01' });

  await admin.post('/invoices', { purchase_order_id: closed.id, invoice_number: 'UJI-INV-001', invoice_date: addDays(today, -29), due_date: addDays(today, 1), amount: 2_400_000, paid_amount: 2_400_000, payment_date: addDays(today, -10) });
  await admin.post('/invoices', { purchase_order_id: running.id, invoice_number: 'UJI-INV-002', invoice_date: addDays(today, -6), due_date: addDays(today, -1), amount: 5_000_000, paid_amount: 1_000_000 });

  return { adminId, marketingId, customers: { cosmetics, pharma, food }, products: { bottle, cap, jar }, leads, purchaseOrders: { running, late, closed, cancelled } };
}
