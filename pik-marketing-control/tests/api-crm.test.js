'use strict';

/**
 * Phase 04 backend — CRM: customers and the customer workspace, contacts (one primary per customer), leads and the
 * pipeline board, activities (with the next follow-up in one unit) and follow-ups (Today / Overdue / Upcoming computed
 * from the pinned clock, D14). All data is invented.
 */

const assert = require('node:assert/strict');
const { test } = require('node:test');

const { TODAY, createApp, seedOrder } = require('./support/api-harness');

test('customer: CRUD dengan validasi, arsip/pulihkan, dan riwayat perubahan di AUDIT_LOG', () => {
  const app = createApp();
  const error = app.fail('sales', 'customers.create', { data: { name: '   ', customer_code: 'X1' } }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(error), ['name:REQUIRED']);

  const customer = app.ok('sales', 'customers.create', {
    data: { name: '  PT Sumber Makmur ', customer_code: 'sm-01', email: 'Info@SumberMakmur.example', status: 'POTENTIAL' },
  });
  assert.equal(customer.name, 'PT Sumber Makmur', 'teks dirapikan');
  assert.equal(customer.email, 'info@sumbermakmur.example');
  assert.equal(customer.owner_name, 'Sandi Sales');
  const duplicate = app.fail('marketing', 'customers.create', { data: { name: 'PT Lain', customer_code: 'SM-01' } }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(duplicate), ['customer_code:UNIQUE']);

  const updated = app.ok('marketing', 'customers.update', {
    id: customer.id, expectedUpdatedAt: customer.updated_at, data: { status: 'ACTIVE', industry: 'Kosmetik' },
  });
  assert.equal(updated.status, 'ACTIVE');
  app.fail('marketing', 'customers.update', { id: customer.id, data: { status: 'VIP' } }, 'VALIDATION_ERROR');

  app.ok('sales', 'customers.archive', { id: customer.id });
  assert.equal(app.ok('viewer', 'customers.get', { id: customer.id }).customer.is_active, false, 'arsip tetap dapat dibuka');
  app.ok('sales', 'customers.restore', { id: customer.id });

  const history = app.ok('viewer', 'audit.history', { entityType: 'CUSTOMERS', entityId: customer.id });
  assert.deepEqual(history.items.map((item) => item.action), ['RESTORE', 'ARCHIVE', 'UPDATE', 'CREATE']);
  const update = history.items.find((item) => item.action === 'UPDATE');
  assert.deepEqual(update.changes.status, ['POTENTIAL', 'ACTIVE']);
  assert.equal(update.actor_name, 'Mira Marketing');
  app.fail('viewer', 'audit.history', { entityType: 'INVOICES_PAYMENTS', entityId: 'PAY-0000000000' }, 'FORBIDDEN');
  app.fail('viewer', 'audit.history', { entityType: 'AUDIT_LOG', entityId: 'x' }, 'VALIDATION_ERROR');
});

test('customer detail: workspace lengkap (contact, lead, aktivitas, follow-up, PO + outstanding, delivery, retur)', () => {
  const app = createApp();
  const seed = seedOrder(app);
  const lead = app.ok('sales', 'leads.create', { data: { customer_id: seed.customer.id, name: 'Botol serum 30 ml', estimated_value: 25000000 } });
  app.ok('sales', 'activities.create', { data: { lead_id: lead.id, type: 'VISIT', subject: 'Kunjungan pabrik' } });
  app.ok('sales', 'followUps.create', { data: { customer_id: seed.customer.id, follow_up_date: '2026-09-25', purpose: 'Kirim penawaran' } });
  app.ok('admin', 'deliveries.create', { data: { po_line_id: seed.lineA.id, quantity: 40, sj_number: 'SJ-T-1' } });
  app.ok('admin', 'returns.create', { data: { po_line_id: seed.lineA.id, quantity: 5, reason: 'Cacat cetak' } });

  const workspace = app.ok('sales', 'customers.get', { id: seed.customer.id });
  assert.equal(workspace.customer.contact_count, 1);
  assert.equal(workspace.customer.open_leads, 1);
  assert.equal(workspace.customer.open_purchase_orders, 1);
  assert.equal(workspace.customer.outstanding_quantity, 115, '100 - 40 + 5 + 50');
  assert.equal(workspace.customer.overdue_follow_ups, 1);
  assert.equal(workspace.contacts[0].is_primary, true);
  assert.equal(workspace.leads.total, 1);
  assert.equal(workspace.activities.items[0].customer_id, seed.customer.id, 'customer diwarisi dari lead');
  assert.equal(workspace.followUps.items[0].due_state, 'OVERDUE');
  assert.equal(workspace.purchaseOrders.items[0].outstanding_quantity, 115);
  assert.equal(workspace.deliveries.items[0].customer_name, 'PT Contoh Kemasan');
  assert.equal(workspace.returns.total, 1);
  assert.equal(workspace.invoices, null, 'Sales tidak melihat invoice');

  const list = app.ok('viewer', 'customers.list', { filters: { has_open_po: true, has_overdue_follow_up: true } });
  assert.deepEqual(list.items.map((item) => item.id), [seed.customer.id]);
  assert.equal(app.ok('viewer', 'customers.list', { sort: { field: 'outstanding_quantity', direction: 'desc' } }).items[0].outstanding_quantity, 115);
});

test('contact: satu contact utama per customer, pertukaran atomik, input gagal tidak mengubah contact utama lama', () => {
  const app = createApp();
  const customer = app.ok('sales', 'customers.create', { data: { name: 'PT Utama' } });
  const other = app.ok('sales', 'customers.create', { data: { name: 'PT Lain' } });
  const first = app.ok('sales', 'contacts.create', { data: { customer_id: customer.id, name: 'Ani', is_primary: true } });

  const failed = app.fail('sales', 'contacts.create', { data: { customer_id: customer.id, name: 'Budi', email: 'salah', is_primary: true } }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(failed), ['email:TYPE']);
  let contacts = app.ok('viewer', 'contacts.list', { filters: { customer_id: customer.id } }).items;
  assert.deepEqual(contacts.map((c) => [c.name, c.is_primary]), [['Ani', true]], 'contact utama lama utuh');

  const second = app.ok('sales', 'contacts.create', { data: { customer_id: customer.id, name: 'Budi', is_primary: 'true' } });
  contacts = app.ok('viewer', 'contacts.list', { filters: { customer_id: customer.id }, sort: { field: 'name' } }).items;
  assert.deepEqual(contacts.map((c) => [c.name, c.is_primary]), [['Ani', false], ['Budi', true]]);

  const firstNow = app.ok('viewer', 'contacts.list', { filters: { customer_id: customer.id }, sort: { field: 'name' } }).items[0];
  app.fail('marketing', 'contacts.setPrimary', { id: first.id, expectedUpdatedAt: first.updated_at }, 'CONFLICT');
  assert.equal(app.ok('viewer', 'contacts.list', { filters: { customer_id: customer.id, is_primary: true } }).items[0].name, 'Budi',
    'konflik tidak mengubah contact mana pun');
  const back = app.ok('marketing', 'contacts.setPrimary', { id: first.id, expectedUpdatedAt: firstNow.updated_at });
  assert.equal(back.is_primary, true);
  contacts = app.ok('viewer', 'contacts.list', { filters: { customer_id: customer.id, is_primary: true } }).items;
  assert.deepEqual(contacts.map((c) => c.name), ['Ani']);

  // An archived primary cannot come back as a second primary.
  app.ok('sales', 'contacts.archive', { id: first.id });
  app.ok('sales', 'contacts.setPrimary', { id: second.id });
  const restore = app.fail('sales', 'contacts.restore', { id: first.id }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(restore), ['is_primary:UNIQUE']);

  const options = app.ok('sales', 'contacts.options', { customer_id: customer.id });
  assert.deepEqual(options.items.map((item) => item.label), ['Budi']);
  app.ok('sales', 'contacts.create', { data: { customer_id: other.id, name: 'Cici', is_primary: true } });
  assert.equal(app.ok('viewer', 'contacts.list', { filters: { is_primary: true } }).total, 2, 'contact utama per customer, bukan global');
});

test('lead: default PIC dan status, relasi konsisten, pipeline board, pindah status, filter terbuka', () => {
  const app = createApp();
  const seed = seedOrder(app);
  const otherCustomer = app.ok('sales', 'customers.create', { data: { name: 'PT Tetangga' } });
  const lead = app.ok('sales', 'leads.create', { data: { contact_id: seed.contact.id, name: 'Jar 50 g', estimated_value: 10000000 } });
  assert.equal(lead.customer_id, seed.customer.id, 'customer diambil dari contact');
  assert.equal(lead.status, 'NEW');
  assert.equal(lead.owner_user_id, app.users.sales.id);

  const mismatch = app.fail('sales', 'leads.create', {
    data: { customer_id: otherCustomer.id, contact_id: seed.contact.id, name: 'Salah relasi' },
  }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(mismatch), ['contact_id:REF_MISMATCH']);
  app.fail('sales', 'leads.create', { data: { name: 'Tanpa customer' } }, 'VALIDATION_ERROR');
  app.fail('sales', 'leads.create', { data: { customer_id: seed.customer.id, name: 'Nilai negatif', estimated_value: -1 } }, 'VALIDATION_ERROR');

  app.ok('marketing', 'leads.create', { data: { customer_id: seed.customer.id, name: 'Tube 100 ml', estimated_value: 5000000, status: 'QUOTATION' } });
  app.ok('marketing', 'leads.create', { data: { customer_id: otherCustomer.id, name: 'Pouch', estimated_value: 2000000, status: 'LOST' } });

  const moved = app.ok('sales', 'leads.move', { id: lead.id, status: 'NEGOTIATION', expectedUpdatedAt: lead.updated_at });
  assert.equal(moved.status, 'NEGOTIATION');
  app.fail('sales', 'leads.move', { id: lead.id, status: 'MENANG' }, 'VALIDATION_ERROR');
  app.fail('sales', 'leads.move', { id: lead.id, status: 'WON', expectedUpdatedAt: lead.updated_at }, 'CONFLICT');
  const moveAudit = app.ok('viewer', 'audit.history', { entityType: 'LEADS', entityId: lead.id }).items[0];
  assert.deepEqual(moveAudit.changes.status, ['NEW', 'NEGOTIATION']);

  const board = app.ok('viewer', 'leads.board', {});
  const column = (status) => board.columns.find((item) => item.status === status);
  assert.deepEqual(board.columns.map((item) => item.status), ['NEW', 'CONTACTED', 'QUALIFIED', 'QUOTATION', 'NEGOTIATION', 'WON', 'LOST', 'DORMANT']);
  assert.equal(column('NEGOTIATION').count, 1);
  assert.equal(column('NEGOTIATION').total_value, 10000000);
  assert.equal(column('QUOTATION').items[0].customer_name, 'PT Contoh Kemasan');
  assert.equal(board.total, 3);
  const mine = app.ok('viewer', 'leads.board', { filters: { owner_user_id: app.users.marketing.id } });
  assert.equal(mine.total, 2);

  assert.equal(app.ok('viewer', 'leads.list', { filters: { open: true } }).total, 2);
  const byStatus = app.ok('viewer', 'leads.list', { sort: { field: 'status', direction: 'asc' } }).items.map((item) => item.status);
  assert.deepEqual(byStatus, ['QUOTATION', 'NEGOTIATION', 'LOST'], 'urutan status mengikuti pipeline, bukan abjad');

  const detail = app.ok('viewer', 'leads.get', { id: lead.id });
  assert.equal(detail.lead.contact_name, 'Budi Contoh');
});

test('aktivitas: default PIC/waktu, customer wajib, follow-up berikutnya dalam satu unit (keduanya atau tidak sama sekali)', () => {
  const app = createApp();
  const seed = seedOrder(app);
  const activity = app.ok('sales', 'activities.create', { data: { customer_id: seed.customer.id, type: 'WHATSAPP', subject: 'Tanya jadwal' } });
  assert.equal(activity.owner_user_id, app.users.sales.id);
  assert.equal(activity.activity_at, app.now());
  const noCustomer = app.fail('sales', 'activities.create', { data: { type: 'EMAIL', subject: 'Tanpa customer' } }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(noCustomer), ['customer_id:REQUIRED']);
  const badType = app.fail('sales', 'activities.create', { data: { customer_id: seed.customer.id, type: 'TELEPATI', subject: 'x' } }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(badType), ['type:ENUM']);

  const before = app.rows('ACTIVITIES').length;
  const failedUnit = app.fail('sales', 'activities.create', {
    data: { customer_id: seed.customer.id, type: 'PHONE_CALL', subject: 'Telepon' },
    followUp: { follow_up_date: '2026-02-30' },
  }, 'VALIDATION_ERROR');
  assert.equal(failedUnit.details.errors[0].table, 'FOLLOW_UP');
  assert.equal(app.rows('ACTIVITIES').length, before, 'aktivitas tidak tersimpan bila follow-up tidak valid');

  const withNext = app.ok('sales', 'activities.create', {
    data: { customer_id: seed.customer.id, contact_id: seed.contact.id, type: 'MEETING', subject: 'Presentasi sampel' },
    followUp: { follow_up_date: '2026-10-01', follow_up_time: '09:30', purpose: 'Konfirmasi sampel', priority: 'HIGH' },
  });
  assert.equal(withNext.follow_up.activity_id, withNext.id);
  assert.equal(withNext.follow_up.customer_id, seed.customer.id);
  assert.equal(withNext.follow_up.type, 'MEETING');
  assert.equal(withNext.follow_up.status, 'PLANNED');

  const updated = app.ok('marketing', 'activities.update', { id: activity.id, data: { description: 'Customer minta jadwal ulang' } });
  assert.equal(updated.description, 'Customer minta jadwal ulang');
  app.fail('marketing', 'activities.update', { id: activity.id, data: { customer_id: null } }, 'VALIDATION_ERROR');

  // Date filters use the Jakarta calendar date of the stored UTC timestamp.
  const late = app.ok('sales', 'activities.create', {
    data: { customer_id: seed.customer.id, type: 'EMAIL', subject: 'Email malam', activity_at: '2026-09-26T17:30:00.000Z' },
  });
  const onSunday = app.ok('viewer', 'activities.list', { filters: { activity_at: { from: '2026-09-27', to: '2026-09-27' } } });
  assert.deepEqual(onSunday.items.map((item) => item.id), [late.id], '00:30 WIB tanggal 27 walau UTC masih tanggal 26');
  app.fail('viewer', 'activities.list', { filters: { activity_at: { from: '27-09-2026' } } }, 'VALIDATION_ERROR');
});

test('follow-up: kelompok Today/Overdue/Upcoming dari tanggal hari ini; complete + berikutnya atomik; reschedule; cancel', () => {
  const app = createApp();
  const seed = seedOrder(app);
  const make = (date, extra = {}) => app.ok('sales', 'followUps.create', {
    data: { customer_id: seed.customer.id, follow_up_date: date, purpose: `Follow-up ${date}`, ...extra },
  });
  const overdue = make('2026-09-27');
  const today = make(TODAY, { follow_up_time: '14:00', priority: 'HIGH' });
  const upcoming = make('2026-10-05');
  const doneLate = make('2026-09-20');
  app.ok('sales', 'followUps.complete', { id: doneLate.id, result: 'Sudah dikonfirmasi' });
  assert.equal(make(TODAY).owner_user_id, app.users.sales.id);
  const defaultDate = app.ok('marketing', 'followUps.create', { data: { customer_id: seed.customer.id } });
  assert.equal(defaultDate.follow_up_date, TODAY, 'tanggal default = hari ini (WIB)');
  const orphan = app.fail('marketing', 'followUps.create', { data: { purpose: 'Tanpa customer' } }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(orphan), ['customer_id:REQUIRED']);
  const lead = app.ok('sales', 'leads.create', { data: { customer_id: seed.customer.id, name: 'Lead follow-up' } });
  const fromLead = app.ok('sales', 'followUps.create', { data: { lead_id: lead.id, follow_up_date: '2026-10-20' } });
  assert.equal(fromLead.customer_id, seed.customer.id, 'customer diwarisi dari lead');
  app.ok('sales', 'followUps.archive', { id: fromLead.id });

  const counts = app.ok('viewer', 'followUps.counts', {});
  assert.deepEqual(counts, { TODAY: 3, OVERDUE: 1, UPCOMING: 1, OPEN: 5, DONE: 1, CANCELLED: 0, RESCHEDULE: 0 });
  assert.deepEqual(app.ok('viewer', 'followUps.counts', { filters: { owner_user_id: app.users.marketing.id } }).TODAY, 1);
  const todayList = app.ok('viewer', 'followUps.list', { filters: { bucket: 'TODAY' } });
  assert.equal(todayList.items[0].id, today.id, 'yang berjam lebih dulu; tanpa jam di akhir');
  assert.ok(todayList.items.every((item) => item.due_state === 'TODAY'));
  app.fail('viewer', 'followUps.list', { filters: { bucket: 'KEMARIN' } }, 'VALIDATION_ERROR');

  // Completing with an invalid next follow-up changes nothing.
  const failed = app.fail('sales', 'followUps.complete', { id: overdue.id, result: 'OK', next: { follow_up_date: 'besok' } }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(failed), ['follow_up_date:TYPE']);
  assert.equal(app.ok('viewer', 'followUps.list', { filters: { bucket: 'OVERDUE' } }).total, 1, 'masih overdue, tidak ada follow-up baru');

  const stale = app.fail('marketing', 'followUps.complete', { id: overdue.id, result: 'x', expectedUpdatedAt: '2026-01-01T00:00:00.000Z' }, 'CONFLICT');
  assert.equal(stale.details.updatedAt, overdue.updated_at);
  const completed = app.ok('sales', 'followUps.complete', {
    id: overdue.id, result: 'Customer setuju sampel', next: { follow_up_date: '2026-10-02', purpose: 'Kirim sampel' },
    expectedUpdatedAt: overdue.updated_at,
  });
  assert.equal(completed.status, 'DONE');
  assert.equal(completed.next.status, 'PLANNED');
  assert.equal(completed.next.customer_id, seed.customer.id);
  app.fail('sales', 'followUps.complete', { id: overdue.id }, 'VALIDATION_ERROR');

  const rescheduled = app.ok('sales', 'followUps.reschedule', { id: upcoming.id, follow_up_date: '2026-10-10', notes: 'Customer cuti' });
  assert.equal(rescheduled.status, 'RESCHEDULE');
  assert.equal(rescheduled.follow_up_date, '2026-10-10');
  app.fail('sales', 'followUps.reschedule', { id: upcoming.id }, 'VALIDATION_ERROR');
  const cancelled = app.ok('sales', 'followUps.cancel', { id: today.id, notes: 'Tidak relevan' });
  assert.equal(cancelled.status, 'CANCELLED');

  const after = app.ok('viewer', 'followUps.counts', {});
  assert.equal(after.OVERDUE, 0);
  assert.equal(after.RESCHEDULE, 1);
  assert.equal(after.CANCELLED, 1);
  assert.equal(after.DONE, 2);

  // The next day, what was due today becomes overdue — nothing is stored, it is computed on read.
  app.setNow('2026-09-29T02:00:00.000Z');
  const tomorrow = app.ok('viewer', 'followUps.counts', {});
  assert.equal(tomorrow.OVERDUE, 2);
  assert.equal(tomorrow.TODAY, 0);
  const dashboard = app.ok('viewer', 'dashboard.summary', {});
  assert.equal(dashboard.kpis.followUpOverdue, 2);
  assert.equal(dashboard.today, '2026-09-29');
});
