/** Customers, contacts, leads, activities and follow-ups through the API. */
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { closePool } from '../../apps/api/src/db/pool.js';
import { addDays, businessToday } from '../../apps/api/src/utils/dates.js';
import { loginAllRoles } from '../helpers/api.js';
import { closeDb, sql, truncateAll } from '../helpers/db.js';

let as;
const today = businessToday();

beforeAll(async () => {
  await truncateAll();
  as = await loginAllRoles();
});
afterAll(async () => {
  await closePool();
  await closeDb();
});

async function createCustomer(api = as.sales, body = {}) {
  const res = await api.post('/api/customers', { name: `PT Test ${Math.random().toString(36).slice(2, 8)}`, ...body });
  expect(res.status, JSON.stringify(res.body)).toBe(201);
  return res.body.data;
}

describe('customers', () => {
  it('creates, reads, updates, archives and restores a customer', async () => {
    const created = await as.sales.post('/api/customers', {
      name: '  PT Uji Coba  ',
      customer_code: 'UJI-01',
      industry: 'Kosmetik',
      email: 'Purchasing@UjiCoba.test',
      phone: '',
    });
    expect(created.status).toBe(201);
    expect(created.body.data).toMatchObject({
      name: 'PT Uji Coba',
      customer_code: 'UJI-01',
      email: 'purchasing@ujicoba.test',
      phone: null,
      status: 'ACTIVE',
      is_active: true,
      created_by: as.sales.user.id,
    });
    const id = created.body.data.id;

    const detail = await as.viewer.get(`/api/customers/${id}`);
    expect(detail.status).toBe(200);
    expect(detail.body.data.summary).toMatchObject({ contacts: 0, active_leads: 0, open_purchase_orders: 0 });

    const updated = await as.marketing.put(`/api/customers/${id}`, { status: 'DORMANT', notes: 'Belum order lagi' });
    expect(updated.body.data).toMatchObject({ status: 'DORMANT', notes: 'Belum order lagi', name: 'PT Uji Coba' });
    expect(updated.body.data.updated_by).toBe(as.marketing.user.id);

    const archived = await as.sales.delete(`/api/customers/${id}`);
    expect(archived.body.data.is_active).toBe(false);
    const activeList = await as.sales.get('/api/customers?q=Uji');
    expect(activeList.body.data.map((c) => c.id)).not.toContain(id);
    const archivedList = await as.sales.get('/api/customers?q=Uji&is_active=false');
    expect(archivedList.body.data.map((c) => c.id)).toContain(id);
    const restored = await as.sales.post(`/api/customers/${id}/restore`);
    expect(restored.body.data.is_active).toBe(true);
    // Nothing was physically deleted.
    expect((await sql('SELECT count(*)::int AS n FROM customers WHERE id = $1', [id])).rows[0].n).toBe(1);
  });

  it('validates input and explains duplicate codes', async () => {
    const missing = await as.sales.post('/api/customers', { name: '   ' });
    expect(missing.status).toBe(400);
    expect(missing.body.error.details.fields.name).toBe('Nama customer wajib diisi.');
    const badStatus = await as.sales.post('/api/customers', { name: 'PT X', status: 'VIP' });
    expect(badStatus.status).toBe(400);

    await createCustomer(as.sales, { customer_code: 'DUP-1' });
    const duplicate = await as.sales.post('/api/customers', { name: 'PT Lain', customer_code: 'DUP-1' });
    expect(duplicate.status).toBe(409);
    expect(duplicate.body.error.message).toBe('Kode customer sudah digunakan.');
  });

  it('enforces the authorization matrix', async () => {
    for (const role of ['management', 'viewer']) {
      expect((await as[role].post('/api/customers', { name: 'PT Tidak Boleh' })).status, role).toBe(403);
      expect((await as[role].get('/api/customers')).status, role).toBe(200);
    }
    for (const role of ['admin', 'marketing', 'sales']) {
      expect((await as[role].post('/api/customers', { name: `PT Boleh ${role}` })).status, role).toBe(201);
    }
  });

  it('searches, filters, sorts and pages', async () => {
    await createCustomer(as.sales, { name: 'PT Cari Aku', industry: 'Farmasi', status: 'POTENTIAL' });
    const found = await as.sales.get('/api/customers?q=cari aku');
    expect(found.body.data).toHaveLength(1);
    const filtered = await as.sales.get('/api/customers?status=POTENTIAL&industry=Farmasi');
    expect(filtered.body.data.every((c) => c.status === 'POTENTIAL')).toBe(true);
    const paged = await as.sales.get('/api/customers?page_size=2&page=1&sort=-name');
    expect(paged.body.data).toHaveLength(2);
    expect(paged.body.meta.total).toBeGreaterThanOrEqual(5);
    expect(paged.body.data[0].name >= paged.body.data[1].name).toBe(true);
    // LIKE wildcards in the search text are literal.
    expect((await as.sales.get('/api/customers?q=%25')).body.data).toHaveLength(0);
    const facets = await as.sales.get('/api/customers/facets');
    expect(facets.body.data.industries).toContain('Farmasi');
  });
});

describe('contacts', () => {
  it('keeps exactly one primary contact per customer', async () => {
    const customer = await createCustomer();
    const first = await as.sales.post(`/api/customers/${customer.id}/contacts`, { name: 'Budi', is_primary: true });
    expect(first.status).toBe(201);
    const second = await as.sales.post(`/api/customers/${customer.id}/contacts`, { name: 'Sari', is_primary: true, whatsapp: '0812' });
    expect(second.status).toBe(201);
    const list = await as.viewer.get(`/api/customers/${customer.id}/contacts`);
    expect(list.body.data.map((c) => [c.name, c.is_primary])).toEqual([
      ['Sari', true],
      ['Budi', false],
    ]);
    await as.sales.put(`/api/contacts/${first.body.data.id}`, { is_primary: true });
    const again = await as.viewer.get(`/api/customers/${customer.id}/contacts`);
    expect(again.body.data.find((c) => c.is_primary).name).toBe('Budi');
  });

  it('archives contacts instead of deleting them', async () => {
    const customer = await createCustomer();
    const contact = (await as.sales.post(`/api/customers/${customer.id}/contacts`, { name: 'Andi' })).body.data;
    const archived = await as.sales.delete(`/api/contacts/${contact.id}`);
    expect(archived.body.data.is_active).toBe(false);
    expect((await as.sales.get(`/api/customers/${customer.id}/contacts`)).body.data).toHaveLength(0);
    expect((await as.sales.get(`/api/customers/${customer.id}/contacts?is_active=false`)).body.data).toHaveLength(1);
    expect((await as.viewer.delete(`/api/contacts/${contact.id}`)).status).toBe(403);
  });

  it('returns 404 for unknown customers', async () => {
    expect((await as.sales.get('/api/customers/00000000-0000-4000-8000-000000000000/contacts')).status).toBe(404);
  });
});

describe('leads', () => {
  it('creates a lead owned by the creator and moves it through the pipeline', async () => {
    const customer = await createCustomer();
    const created = await as.sales.post('/api/leads', {
      customer_id: customer.id,
      name: 'Botol 100ml',
      estimated_value: '150000000',
      expected_closing_date: addDays(today, 30),
    });
    expect(created.status).toBe(201);
    expect(created.body.data).toMatchObject({
      status: 'NEW',
      priority: 'MEDIUM',
      owner_user_id: as.sales.user.id,
      owner_name: as.sales.user.name,
      customer_name: customer.name,
      estimated_value: 150000000,
      closed_at: null,
    });
    const id = created.body.data.id;

    const quoted = await as.sales.patch(`/api/leads/${id}/status`, { status: 'QUOTATION' });
    expect(quoted.body.data.status).toBe('QUOTATION');

    const lostWithoutReason = await as.sales.patch(`/api/leads/${id}/status`, { status: 'LOST' });
    expect(lostWithoutReason.status).toBe(422);
    expect(lostWithoutReason.body.error.message).toBe('Alasan lost wajib diisi.');

    const won = await as.sales.patch(`/api/leads/${id}/status`, { status: 'WON' });
    expect(won.body.data.status).toBe('WON');
    expect(won.body.data.closed_at).not.toBeNull();

    const reopenBySales = await as.sales.patch(`/api/leads/${id}/status`, { status: 'NEGOTIATION' });
    expect(reopenBySales.status).toBe(422);
    const reopenByAdmin = await as.admin.patch(`/api/leads/${id}/status`, { status: 'NEGOTIATION' });
    expect(reopenByAdmin.status).toBe(200);
    expect(reopenByAdmin.body.data.closed_at).toBeNull();
  });

  it('rejects a contact that belongs to another customer', async () => {
    const [a, b] = [await createCustomer(), await createCustomer()];
    const contactOfB = (await as.sales.post(`/api/customers/${b.id}/contacts`, { name: 'Orang B' })).body.data;
    const res = await as.sales.post('/api/leads', { customer_id: a.id, contact_id: contactOfB.id, name: 'Salah kontak' });
    expect(res.status).toBe(409);
    expect(res.body.error.message).toBe('Kontak yang dipilih bukan milik customer ini.');
  });

  it('rejects leads for archived customers and inactive owners', async () => {
    const customer = await createCustomer();
    await as.sales.delete(`/api/customers/${customer.id}`);
    const archived = await as.sales.post('/api/leads', { customer_id: customer.id, name: 'X' });
    expect(archived.status).toBe(422);

    const active = await createCustomer();
    await sql(`UPDATE users SET is_active = FALSE WHERE id = $1`, [as.management.user.id]);
    const inactiveOwner = await as.sales.post('/api/leads', { customer_id: active.id, name: 'Y', owner_user_id: as.management.user.id });
    expect(inactiveOwner.status).toBe(422);
    await sql(`UPDATE users SET is_active = TRUE WHERE id = $1`, [as.management.user.id]);
  });

  it('serves a Kanban board grouped by status with counts and values', async () => {
    const customer = await createCustomer();
    await as.sales.post('/api/leads', { customer_id: customer.id, name: 'Board A', status: 'QUALIFIED', estimated_value: 100 });
    await as.sales.post('/api/leads', { customer_id: customer.id, name: 'Board B', status: 'QUALIFIED', estimated_value: 250 });
    const board = await as.viewer.get(`/api/leads/board?customer_id=${customer.id}`);
    expect(board.status).toBe(200);
    expect(board.body.data.map((column) => column.status)).toEqual([
      'NEW', 'CONTACTED', 'QUALIFIED', 'QUOTATION', 'NEGOTIATION', 'WON', 'LOST', 'DORMANT',
    ]);
    const qualified = board.body.data.find((column) => column.status === 'QUALIFIED');
    expect(qualified).toMatchObject({ count: 2, total_value: 350 });
    expect(qualified.leads.map((lead) => lead.name).sort()).toEqual(['Board A', 'Board B']);
  });

  it('is read-only for Management and Viewer', async () => {
    const customer = await createCustomer();
    expect((await as.viewer.post('/api/leads', { customer_id: customer.id, name: 'X' })).status).toBe(403);
    expect((await as.management.get('/api/leads')).status).toBe(200);
  });
});

describe('activities', () => {
  it('logs an activity for a customer, lead and contact', async () => {
    const customer = await createCustomer();
    const lead = (await as.sales.post('/api/leads', { customer_id: customer.id, name: 'Lead aktivitas' })).body.data;
    const contact = (await as.sales.post(`/api/customers/${customer.id}/contacts`, { name: 'Kontak aktivitas' })).body.data;
    const res = await as.sales.post('/api/activities', {
      customer_id: customer.id,
      lead_id: lead.id,
      contact_id: contact.id,
      type: 'WHATSAPP',
      subject: 'Kirim katalog',
      activity_at: `${today}T09:30:00+07:00`,
    });
    expect(res.status).toBe(201);
    expect(res.body.data).toMatchObject({
      type: 'WHATSAPP',
      lead_name: 'Lead aktivitas',
      contact_name: 'Kontak aktivitas',
      owner_user_id: as.sales.user.id,
    });
    expect(new Date(res.body.data.activity_at).toISOString()).toBe(new Date(`${today}T02:30:00Z`).toISOString());

    const list = await as.viewer.get(`/api/activities?customer_id=${customer.id}&from=${today}&to=${today}`);
    expect(list.body.data).toHaveLength(1);
    const outside = await as.viewer.get(`/api/activities?customer_id=${customer.id}&from=${addDays(today, 1)}`);
    expect(outside.body.data).toHaveLength(0);
  });

  it('rejects a lead of another customer and an invalid time', async () => {
    const [a, b] = [await createCustomer(), await createCustomer()];
    const leadOfB = (await as.sales.post('/api/leads', { customer_id: b.id, name: 'Lead B' })).body.data;
    const wrongLead = await as.sales.post('/api/activities', {
      customer_id: a.id,
      lead_id: leadOfB.id,
      type: 'CALL',
      subject: 'Salah lead',
      activity_at: `${today}T10:00:00Z`,
    });
    expect(wrongLead.status).toBe(409);
    expect(wrongLead.body.error.message).toBe('Lead yang dipilih bukan milik customer ini.');
    const badTime = await as.sales.post('/api/activities', { customer_id: a.id, type: 'CALL', subject: 'x', activity_at: '2026-13-01 10:00' });
    expect(badTime.status).toBe(400);
  });
});

describe('follow-ups', () => {
  it('derives today / overdue / upcoming / done states from the business date', async () => {
    const customer = await createCustomer();
    const make = (date, extra = {}) =>
      as.sales.post('/api/follow-ups', { customer_id: customer.id, follow_up_date: date, notes: `FU ${date}`, ...extra });
    const overdue = (await make(addDays(today, -2))).body.data;
    const todays = (await make(today, { priority: 'HIGH' })).body.data;
    const upcoming = (await make(addDays(today, 3))).body.data;
    const done = (await make(addDays(today, -5), { status: 'DONE' })).body.data;
    expect([overdue.state, todays.state, upcoming.state, done.state]).toEqual(['OVERDUE', 'TODAY', 'UPCOMING', 'DONE']);
    expect(done.completed_at).not.toBeNull();

    const overdueList = await as.viewer.get(`/api/follow-ups?customer_id=${customer.id}&state=OVERDUE`);
    expect(overdueList.body.data.map((f) => f.id)).toEqual([overdue.id]);
    const openList = await as.viewer.get(`/api/follow-ups?customer_id=${customer.id}&state=OVERDUE,TODAY,UPCOMING`);
    expect(openList.body.data.map((f) => f.id)).toEqual([overdue.id, todays.id, upcoming.id]);

    const summary = await as.sales.get('/api/follow-ups/summary?owner=me');
    expect(summary.body.data).toMatchObject({ today: 1, overdue: 1, upcoming: 1, upcoming_7_days: 1 });
  });

  it('completes a follow-up with an outcome and refuses to complete it twice', async () => {
    const customer = await createCustomer();
    const fu = (await as.sales.post('/api/follow-ups', { customer_id: customer.id, follow_up_date: today, notes: 'Telepon' })).body.data;
    const done = await as.sales.post(`/api/follow-ups/${fu.id}/complete`, { outcome: 'Customer minta sampel' });
    expect(done.status).toBe(200);
    expect(done.body.data).toMatchObject({ status: 'DONE', state: 'DONE', notes: 'Telepon\n\nHasil: Customer minta sampel' });
    expect((await as.sales.post(`/api/follow-ups/${fu.id}/complete`, {})).status).toBe(422);
  });

  it('reschedules to today or later and keeps the history', async () => {
    const customer = await createCustomer();
    const oldDate = addDays(today, -1);
    const fu = (await as.sales.post('/api/follow-ups', { customer_id: customer.id, follow_up_date: oldDate })).body.data;
    expect(fu.state).toBe('OVERDUE');
    const past = await as.sales.post(`/api/follow-ups/${fu.id}/reschedule`, { follow_up_date: addDays(today, -3) });
    expect(past.status).toBe(422);
    const moved = await as.sales.post(`/api/follow-ups/${fu.id}/reschedule`, { follow_up_date: addDays(today, 2), notes: 'Customer cuti' });
    expect(moved.body.data).toMatchObject({ status: 'RESCHEDULE', state: 'UPCOMING', follow_up_date: addDays(today, 2) });
    expect(moved.body.data.notes).toBe(`Dijadwalkan ulang dari ${oldDate}: Customer cuti.`);
  });

  it('rejects a lead that belongs to another customer', async () => {
    const [a, b] = [await createCustomer(), await createCustomer()];
    const leadOfB = (await as.sales.post('/api/leads', { customer_id: b.id, name: 'Lead lain' })).body.data;
    const res = await as.sales.post('/api/follow-ups', { customer_id: a.id, lead_id: leadOfB.id, follow_up_date: today });
    expect(res.status).toBe(409);
  });

  it('is read-only for Viewer', async () => {
    const customer = await createCustomer();
    expect((await as.viewer.post('/api/follow-ups', { customer_id: customer.id, follow_up_date: today })).status).toBe(403);
  });
});
