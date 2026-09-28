/** Dashboard KPIs, reports (JSON + CSV), global search, notifications and migration issue review. */
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { closePool } from '../../apps/api/src/db/pool.js';
import { addDays, businessToday } from '../../apps/api/src/utils/dates.js';
import { loginAllRoles } from '../helpers/api.js';
import { closeDb, sql, truncateAll } from '../helpers/db.js';

let as;
let customer;
let po;
const today = businessToday();

beforeAll(async () => {
  await truncateAll();
  as = await loginAllRoles();
  customer = (await as.sales.post('/api/customers', { name: 'PT Dasbor Satu', industry: 'Kosmetik' })).body.data;
  await as.sales.post('/api/customers', { name: 'PT Dasbor Dua' });
  const archived = (await as.sales.post('/api/customers', { name: 'PT Dasbor Arsip' })).body.data;
  await as.sales.delete(`/api/customers/${archived.id}`);

  const lead = (await as.sales.post('/api/leads', { customer_id: customer.id, name: 'Lead Dasbor', estimated_value: 1000, status: 'QUOTATION' })).body.data;
  await as.sales.post('/api/leads', { customer_id: customer.id, name: 'Lead Menang', estimated_value: 5000, status: 'WON' });
  await as.marketing.post('/api/leads', { customer_id: customer.id, name: 'Lead Marketing', estimated_value: 200 });

  for (const [date, notes] of [
    [today, 'FU hari ini'],
    [addDays(today, -3), 'FU terlambat'],
    [addDays(today, 2), 'FU minggu ini'],
    [addDays(today, 20), 'FU bulan depan'],
  ]) {
    await as.sales.post('/api/follow-ups', { customer_id: customer.id, lead_id: lead.id, follow_up_date: date, notes });
  }
  await as.sales.post('/api/activities', { customer_id: customer.id, type: 'CALL', subject: 'Telepon dasbor', activity_at: new Date().toISOString() });

  const product = (await as.admin.post('/api/products', { name: 'Botol Dasbor', product_code: 'BD-1', unit: 'pcs' })).body.data;
  const kg = (await as.admin.post('/api/products', { name: 'Resin Dasbor', product_code: 'RD-1', unit: 'kg' })).body.data;
  po = (
    await as.admin.post('/api/purchase-orders', {
      po_number: 'PO-DASBOR',
      customer_id: customer.id,
      owner_user_id: as.sales.user.id,
      po_date: addDays(today, -30),
      expected_delivery_date: addDays(today, -1),
      lines: [
        { product_id: product.id, order_quantity: 1000, unit_price: 100 },
        { product_id: kg.id, order_quantity: 50, unit_price: 20000 },
      ],
    })
  ).body.data;
  const detail = (await as.admin.get(`/api/purchase-orders/${po.id}`)).body.data;
  await as.admin.post('/api/deliveries', { purchase_order_id: po.id, po_line_id: detail.lines[0].id, delivery_date: today, quantity: 400, status: 'DELIVERED' });
  await as.admin.post('/api/deliveries', { purchase_order_id: po.id, po_line_id: detail.lines[0].id, delivery_date: addDays(today, 2), quantity: 100, status: 'SCHEDULED' });
});

afterAll(async () => {
  await closePool();
  await closeDb();
});

describe('dashboard', () => {
  it('computes company-wide KPIs from live data', async () => {
    const res = await as.viewer.get('/api/dashboard/summary');
    expect(res.status).toBe(200);
    const { kpis } = res.body.data;
    expect(kpis).toMatchObject({
      total_customers: 2, // archived customer excluded
      active_leads: 2, // WON excluded
      pipeline_value: 1200,
      follow_up_today: 1,
      follow_up_overdue: 1,
      follow_up_upcoming: 1, // within 7 days only
      open_purchase_orders: 1,
      late_purchase_orders: 1,
    });
    // Outstanding per unit, never mixed: 1000 - 400 pcs, 50 kg.
    expect(kpis.outstanding_quantity).toEqual([
      { unit: 'pcs', quantity: 600 },
      { unit: 'kg', quantity: 50 },
    ]);
  });

  it('returns pipeline, follow-up lists, activities, open POs and delivery status', async () => {
    const { data } = (await as.viewer.get('/api/dashboard/summary')).body;
    expect(data.pipeline.map((column) => column.status)).toHaveLength(8);
    expect(data.pipeline.find((column) => column.status === 'QUOTATION')).toEqual({ status: 'QUOTATION', count: 1, total_value: 1000 });
    expect(data.follow_ups.today.map((f) => f.notes)).toEqual(['FU hari ini']);
    expect(data.follow_ups.overdue.map((f) => f.notes)).toEqual(['FU terlambat']);
    expect(data.follow_ups.upcoming.map((f) => f.notes)).toEqual(['FU minggu ini']);
    expect(data.recent_activities[0].subject).toBe('Telepon dasbor');
    expect(data.open_purchase_orders[0]).toMatchObject({ po_number: 'PO-DASBOR', outstanding_quantity: 650, is_late: true });
    expect(data.deliveries.by_status).toEqual(expect.arrayContaining([{ status: 'DELIVERED', count: 1 }, { status: 'SCHEDULED', count: 1 }]));
    expect(data.deliveries.upcoming).toHaveLength(1);
  });

  it('scopes owner-based numbers to the caller with scope=me', async () => {
    const mine = (await as.marketing.get('/api/dashboard/summary?scope=me')).body.data;
    expect(mine.scope).toBe('me');
    expect(mine.kpis).toMatchObject({ active_leads: 1, pipeline_value: 200, follow_up_today: 0, open_purchase_orders: 0 });
    const sales = (await as.sales.get('/api/dashboard/summary?scope=me')).body.data;
    expect(sales.kpis).toMatchObject({ active_leads: 1, follow_up_overdue: 1, open_purchase_orders: 1 });
  });
});

describe('reports', () => {
  it('filters, pages and totals a report', async () => {
    const res = await as.management.get('/api/reports/leads?status=WON,QUOTATION&page_size=1');
    expect(res.status).toBe(200);
    expect(res.body.data).toHaveLength(1);
    expect(res.body.meta).toMatchObject({ total: 2, report: 'leads', title: 'Laporan Lead' });
    expect(res.body.meta.totals).toMatchObject({ count: 2, total_value: 6000, won_value: 5000, won: 1 });
    expect(res.body.meta.columns[0]).toEqual({ key: 'name', header: 'Lead', type: null });
  });

  it('applies date ranges in the business timezone', async () => {
    const inRange = await as.viewer.get(`/api/reports/activities?from=${today}&to=${today}`);
    expect(inRange.body.meta.total).toBe(1);
    const outOfRange = await as.viewer.get(`/api/reports/activities?from=${addDays(today, 1)}`);
    expect(outOfRange.body.meta.total).toBe(0);
    const followUps = await as.viewer.get(`/api/reports/follow-ups?status=OVERDUE`);
    expect(followUps.body.data.map((row) => row.notes)).toEqual(['FU terlambat']);
  });

  it('exports CSV with Indonesian headers, labels and a UTF-8 BOM', async () => {
    const res = await as.viewer.get('/api/reports/purchase-orders?format=csv');
    expect(res.status).toBe(200);
    expect(res.headers['content-type']).toContain('text/csv');
    expect(res.headers['content-disposition']).toMatch(/attachment; filename="laporan-purchase-orders-\d{4}-\d{2}-\d{2}\.csv"/);
    const text = res.text;
    expect(text.charCodeAt(0)).toBe(0xfeff);
    const [header, row] = text.slice(1).trim().split('\r\n');
    expect(header).toBe('No PO,Customer,Tanggal PO,Target Kirim,Status,PIC,Qty Order,Qty Terkirim,Qty Retur,Outstanding,Nilai PO');
    expect(row).toContain('PO-DASBOR,PT Dasbor Satu');
    expect(row).toContain(',Open,');
    expect(row).toContain(',1050,400,0,650,1100000');
  });

  it('rejects unknown reports and statuses that do not belong to the report', async () => {
    expect((await as.viewer.get('/api/reports/payroll')).status).toBe(404);
    const wrongStatus = await as.viewer.get('/api/reports/leads?status=DELIVERED');
    expect(wrongStatus.status).toBe(400);
  });
});

describe('search and notifications', () => {
  it('searches across modules the user may read', async () => {
    const res = await as.viewer.get('/api/search?q=dasbor');
    expect(res.status).toBe(200);
    const groups = Object.fromEntries(res.body.data.map((group) => [group.group, group.items]));
    expect(groups.customers.map((item) => item.title)).toEqual(['PT Dasbor Dua', 'PT Dasbor Satu']);
    expect(groups.purchase_orders[0]).toMatchObject({ title: 'PO-DASBOR', url: `/purchase-orders/${po.id}` });
    expect(groups.products.map((item) => item.title)).toContain('Botol Dasbor');
    expect((await as.viewer.get('/api/search?q=a')).status).toBe(400);
  });

  it('notifies the owner about overdue/today follow-ups and late POs', async () => {
    const res = await as.sales.get('/api/notifications');
    expect(res.status).toBe(200);
    const types = res.body.data.items.map((item) => item.type);
    expect(types).toEqual(expect.arrayContaining(['FOLLOW_UP_OVERDUE', 'FOLLOW_UP_TODAY', 'PO_LATE']));
    expect(res.body.data.count).toBe(3);
    const viewer = await as.viewer.get('/api/notifications');
    expect(viewer.body.data.count).toBe(0);
  });
});

describe('migration issues', () => {
  it('lists issues for Admin/Management and lets only Admin resolve them', async () => {
    await sql(
      `INSERT INTO migration_issues (fingerprint, entity_type, source_sheet, legacy_row, issue_type, severity, description, source_data)
       VALUES (repeat('a', 64), 'contacts', 'Kontak', 4, 'AMBIGUOUS_REFERENCE', 'ERROR', 'Customer ambigu', '{"Nama":"X"}'),
              (repeat('b', 64), 'customers', 'Pelanggan', 3, 'NUMBER_AS_TEXT', 'WARNING', 'Telepon angka', NULL)`,
    );
    const list = await as.management.get('/api/migration-issues?resolution_status=OPEN');
    expect(list.status).toBe(200);
    expect(list.body.data.map((issue) => issue.severity)).toEqual(['ERROR', 'WARNING']);
    expect((await as.sales.get('/api/migration-issues')).status).toBe(403);

    const id = list.body.data[0].id;
    expect((await as.management.put(`/api/migration-issues/${id}`, { resolution_status: 'RESOLVED' })).status).toBe(403);
    const resolved = await as.admin.put(`/api/migration-issues/${id}`, { resolution_status: 'RESOLVED', resolution_notes: 'Kontak dibuat manual' });
    expect(resolved.body.data).toMatchObject({ resolution_status: 'RESOLVED', resolved_by: as.admin.user.id, resolved_by_name: as.admin.user.name });

    const summary = await as.admin.get('/api/migration-issues/summary');
    expect(summary.body.data.open_by_type).toEqual([{ issue_type: 'NUMBER_AS_TEXT', entity_type: 'customers', count: 1 }]);
    expect(summary.body.data.last_run).toBeNull();

    const notifications = await as.admin.get('/api/notifications');
    expect(notifications.body.data.items.some((item) => item.type === 'MIGRATION_ERRORS')).toBe(false);
  });
});
