'use strict';

/**
 * Phase 04 backend — finance, dashboard, reports, audit log and administration: invoice status derived from amounts,
 * payments, the dashboard KPIs computed from the data (scope mine/all), reports with CSV export, the audit trail, users
 * and roles (the application is never left without an Admin), settings, enum values and migration issues.
 * All data is invented.
 */

const assert = require('node:assert/strict');
const { test } = require('node:test');

const { OWNER, TODAY, createApp, seedOrder } = require('./support/api-harness');

test('invoice: jenis dari nomor, status bayar dihitung dari nilai (bukan dari klien), pembayaran bertahap, jatuh tempo', () => {
  const app = createApp();
  const seed = seedOrder(app);
  const invoice = app.ok('admin', 'invoices.create', {
    data: {
      purchase_order_id: seed.po.id, invoice_number: 'PIK/09/2026/INV/0001', invoice_date: '2026-09-01', due_date: '2026-09-15',
      amount: '1500000', payment_status: 'PAID',
    },
  });
  assert.equal(invoice.invoice_type, 'INV');
  assert.equal(invoice.payment_status, 'UNPAID', 'status dari klien diabaikan');
  assert.equal(invoice.outstanding_amount, 1500000);
  assert.equal(invoice.is_overdue, true);
  assert.equal(invoice.customer_name, 'PT Contoh Kemasan');

  const noDate = app.fail('admin', 'invoices.recordPayment', { id: invoice.id, paid_amount: 500000 }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(noDate), ['payment_date:RULE']);
  const partial = app.ok('admin', 'invoices.recordPayment', { id: invoice.id, paid_amount: 500000, payment_date: '2026-09-10', payment_receipt_number: 'BKM-01' });
  assert.equal(partial.payment_status, 'PARTIAL');
  assert.equal(partial.outstanding_amount, 1000000);
  const tooMuch = app.fail('admin', 'invoices.recordPayment', { id: invoice.id, paid_amount: 1500001, payment_date: TODAY }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(tooMuch), ['paid_amount:RULE']);
  const ambiguous = app.fail('admin', 'invoices.recordPayment', { id: invoice.id, paid_amount: '1.500.000', payment_date: TODAY }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(ambiguous), ['paid_amount:TYPE'], 'angka berformat ribuan ditolak, bukan ditebak');

  const tum = app.ok('admin', 'invoices.create', {
    data: { purchase_order_id: seed.po.id, invoice_number: 'PIK/09/2026/TUM/0002', invoice_date: TODAY, amount: 200000 },
  });
  assert.equal(tum.invoice_type, 'TUM');
  app.fail('admin', 'invoices.create', { data: { purchase_order_id: seed.po.id, invoice_number: 'pik/09/2026/tum/0002', invoice_date: TODAY, amount: 1 } }, 'VALIDATION_ERROR');

  let summary = app.ok('management', 'dashboard.summary', {});
  assert.deepEqual(summary.finance, { receivable: 1200000, unpaidInvoices: 2, overdueInvoices: 1 });
  const paid = app.ok('admin', 'invoices.recordPayment', { id: invoice.id, paid_amount: 1500000, payment_date: TODAY, expectedUpdatedAt: partial.updated_at });
  assert.equal(paid.payment_status, 'PAID');
  assert.equal(paid.is_overdue, false);
  summary = app.ok('management', 'dashboard.summary', {});
  assert.deepEqual(summary.finance, { receivable: 200000, unpaidInvoices: 1, overdueInvoices: 0 });

  assert.equal(app.ok('management', 'invoices.list', { filters: { payment_status: 'UNPAID' } }).total, 1);
  assert.equal(app.ok('management', 'invoices.list', { search: 'pt contoh' }).total, 2);
  const detail = app.ok('management', 'invoices.get', { id: invoice.id });
  assert.equal(detail.purchaseOrder.id, seed.po.id);
  assert.equal(app.ok('management', 'purchaseOrders.get', { id: seed.po.id }).invoices.length, 2);
  app.fail('management', 'invoices.recordPayment', { id: tum.id, paid_amount: 1, payment_date: TODAY }, 'FORBIDDEN');
});

test('dashboard: KPI utama dari data nyata; scope "mine" hanya untuk data pribadi; daftar kerja terurut', () => {
  const app = createApp();
  const seed = seedOrder(app);
  const empty = app.ok('viewer', 'dashboard.summary', {});
  assert.equal(empty.kpis.activeLeads, 0);
  assert.deepEqual(empty.followUpsToday, []);
  assert.equal(empty.generatedAt, app.now());

  app.ok('sales', 'leads.create', { data: { customer_id: seed.customer.id, name: 'Lead Sales', estimated_value: 3000000, status: 'QUALIFIED' } });
  app.ok('marketing', 'leads.create', { data: { customer_id: seed.customer.id, name: 'Lead Marketing', estimated_value: 7000000 } });
  app.ok('marketing', 'leads.create', { data: { customer_id: seed.customer.id, name: 'Lead Menang', estimated_value: 9000000, status: 'WON' } });
  app.ok('sales', 'followUps.create', { data: { customer_id: seed.customer.id, follow_up_date: TODAY, purpose: 'Telepon' } });
  app.ok('marketing', 'followUps.create', { data: { customer_id: seed.customer.id, follow_up_date: '2026-09-01', purpose: 'Terlambat' } });
  app.ok('sales', 'activities.create', { data: { customer_id: seed.customer.id, type: 'VISIT', subject: 'Visit' } });
  app.ok('admin', 'customers.create', { data: { name: 'PT Dorman', status: 'DORMANT' } });
  app.ok('admin', 'deliveries.create', { data: { po_line_id: seed.lineA.id, quantity: 25 } });

  const all = app.ok('viewer', 'dashboard.summary', { scope: 'all' });
  assert.deepEqual(all.kpis, {
    totalCustomers: 2, activeCustomers: 1, activeLeads: 2, openLeadValue: 10000000, followUpToday: 1, followUpOverdue: 1,
    openPurchaseOrders: 1, outstandingQuantity: 125,
  });
  const pipeline = Object.fromEntries(all.leadPipeline.map((item) => [item.status, [item.count, item.value]]));
  assert.deepEqual(pipeline.QUALIFIED, [1, 3000000]);
  assert.deepEqual(pipeline.WON, [1, 9000000]);
  assert.equal(all.recentActivities[0].subject, 'Visit');
  assert.equal(all.openPurchaseOrders[0].outstanding_quantity, 125);
  assert.equal(all.followUpsOverdue[0].purpose, 'Terlambat');

  const mine = app.ok('sales', 'dashboard.summary', { scope: 'mine' });
  assert.equal(mine.scope, 'mine');
  assert.equal(mine.kpis.activeLeads, 1);
  assert.equal(mine.kpis.openLeadValue, 3000000);
  assert.equal(mine.kpis.followUpToday, 1);
  assert.equal(mine.kpis.followUpOverdue, 0);
  assert.equal(mine.kpis.totalCustomers, 2, 'angka perusahaan tetap seluruh perusahaan');
  assert.equal(mine.kpis.outstandingQuantity, 125);
});

test('laporan: ringkasan, grup, baris, filter periode/customer/PIC/status, ekspor CSV aman untuk Excel', () => {
  const app = createApp();
  const seed = seedOrder(app);
  const risky = app.ok('admin', 'customers.create', { data: { name: '+62 Formula', notes: 'x' } });
  app.ok('sales', 'leads.create', { data: { customer_id: seed.customer.id, name: 'Lead A', estimated_value: 1000, status: 'WON' } });
  app.ok('marketing', 'leads.create', { data: { customer_id: risky.id, name: 'Lead B', estimated_value: 500 } });
  app.ok('sales', 'activities.create', { data: { customer_id: seed.customer.id, type: 'EMAIL', subject: 'Email 1' } });
  app.ok('sales', 'activities.create', { data: { customer_id: seed.customer.id, type: 'EMAIL', subject: 'Email lama', activity_at: '2026-08-01T02:00:00.000Z' } });
  app.ok('admin', 'deliveries.create', { data: { po_line_id: seed.lineA.id, quantity: 10, sj_number: 'SJ-R-1' } });
  app.ok('admin', 'deliveries.create', { data: { po_line_id: seed.lineB.id, quantity: 5, status: 'SCHEDULED', delivery_date: '2026-10-01' } });

  const customers = app.ok('viewer', 'reports.customers', {});
  assert.deepEqual([customers.summary.total, customers.summary.active, customers.summary.newInPeriod], [2, 1, 2]);
  const leads = app.ok('viewer', 'reports.leads', { owner_user_id: app.users.sales.id });
  assert.deepEqual([leads.summary.total, leads.summary.won, leads.summary.wonValue], [1, 1, 1000]);
  const activities = app.ok('viewer', 'reports.activities', { from: '2026-09-01', to: TODAY });
  assert.equal(activities.summary.total, 1);
  assert.deepEqual(activities.groups.byType.map((group) => [group.label, group.count]), [['Email', 1]]);
  const pos = app.ok('viewer', 'reports.purchaseOrders', { customer_id: seed.customer.id });
  assert.deepEqual([pos.summary.open, pos.summary.orderQuantity, pos.summary.deliveredQuantity, pos.summary.outstandingQuantity], [1, 150, 10, 140]);
  const deliveries = app.ok('viewer', 'reports.deliveries', {});
  assert.deepEqual([deliveries.summary.delivered, deliveries.summary.scheduled, deliveries.summary.deliveredQuantity], [1, 1, 10]);
  assert.equal(deliveries.columns[0].key, 'delivery_date');

  const csv = app.ok('viewer', 'reports.customers', { format: 'csv' });
  assert.equal(csv.filename, `pik-customers-${TODAY}.csv`);
  assert.ok(csv.csv.startsWith('﻿Customer,Kode,Status'), 'BOM + header');
  assert.ok(csv.csv.includes("'+62 Formula"), 'sel yang diawali + dinetralkan');
  assert.equal(csv.rows, 2);
  app.fail('viewer', 'reports.leads', { from: '2026-13-01' }, 'VALIDATION_ERROR');
  app.fail('viewer', 'reports.leads', { from: '2026-09-10', to: '2026-09-01' }, 'VALIDATION_ERROR');
});

test('audit log: Admin melihat seluruh jejak dengan filter; setiap perubahan tercatat dengan pelaku dan nilai lama/baru', () => {
  const app = createApp();
  const customer = app.ok('sales', 'customers.create', { data: { name: 'PT Jejak' } });
  app.ok('marketing', 'customers.update', { id: customer.id, data: { name: 'PT Jejak Baru' } });
  app.ok('sales', 'customers.archive', { id: customer.id });

  const all = app.ok('admin', 'audit.list', { filters: { entity_type: 'CUSTOMERS' } });
  assert.deepEqual(all.items.map((item) => item.action), ['ARCHIVE', 'UPDATE', 'CREATE']);
  assert.equal(all.items[1].actor_name, 'Mira Marketing');
  assert.deepEqual(all.items[1].changes.name, ['PT Jejak', 'PT Jejak Baru']);
  assert.equal(all.items[0].entity_label, 'Customer');
  const bySales = app.ok('admin', 'audit.list', { filters: { actor_email: 'SALES@example.com', entity_id: customer.id } });
  assert.equal(bySales.total, 2);
  const today = app.ok('admin', 'audit.list', { filters: { occurred_at: { from: TODAY, to: TODAY } } });
  assert.equal(today.total, app.audit().length, 'semua entri hari ini: inisialisasi, Admin pertama, 4 user, customer');
  assert.ok(today.items.some((item) => item.action === 'DB_INIT'));
  assert.equal(app.ok('admin', 'audit.list', { filters: { occurred_at: { to: '2026-09-27' } } }).total, 0);
  assert.equal(app.ok('admin', 'audit.list', { search: 'jejak baru' }).total, 1);
  app.fail('marketing', 'audit.list', {}, 'FORBIDDEN');
});

test('user: email unik tanpa beda huruf, role tervalidasi, Admin tidak dapat mengunci diri sendiri keluar', () => {
  const app = createApp();
  const duplicate = app.fail('admin', 'users.create', { data: { name: 'Ganda', email: 'SALES@EXAMPLE.COM', role: 'SALES' } }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(duplicate), ['email:UNIQUE']);
  app.fail('admin', 'users.create', { data: { name: 'Tanpa role', email: 'x@example.com' } }, 'VALIDATION_ERROR');
  app.fail('admin', 'users.create', { data: { name: 'Role salah', email: 'y@example.com', role: 'SUPERUSER' } }, 'VALIDATION_ERROR');

  const self = app.users.admin;
  app.fail('admin', 'users.archive', { id: self.id }, 'VALIDATION_ERROR');
  const demote = app.fail('admin', 'users.update', { id: self.id, data: { role: 'VIEWER' } }, 'VALIDATION_ERROR');
  assert.match(demote.message, /minimal satu Admin/);
  app.fail('admin', 'users.update', { id: self.id, data: { email: 'baru@example.com' } }, 'VALIDATION_ERROR');
  app.ok('admin', 'users.update', { id: self.id, data: { name: 'Pemilik Aplikasi', email: OWNER.toUpperCase() } });

  const second = app.ok('admin', 'users.update', { id: app.users.management.id, data: { role: 'ADMIN' } });
  assert.equal(second.role_label, 'Admin');
  const selfArchive = app.fail('admin', 'users.archive', { id: self.id }, 'VALIDATION_ERROR');
  assert.match(selfArchive.message, /akun sendiri/, 'tetap ditolak walau ada Admin lain');
  app.ok('management', 'users.update', { id: self.id, data: { role: 'MARKETING' } });
  assert.equal(app.ok('admin', 'session.get', {}).user.role, 'MARKETING', 'role berubah pada permintaan berikutnya');
  app.fail('admin', 'users.list', {}, 'FORBIDDEN');
  const list = app.ok('management', 'users.list', { filters: { role: 'ADMIN' } });
  assert.deepEqual(list.items.map((user) => user.email), ['management@example.com']);
});

test('pengaturan: hanya setting non-sistem, nilai sesuai tipe dan batas; perubahan diaudit', () => {
  const app = createApp();
  const list = app.ok('admin', 'settings.list', {});
  const keys = list.items.map((item) => item.key);
  assert.ok(keys.includes('COMPANY_NAME') && keys.includes('SCHEMA_VERSION'));
  assert.equal(list.config.timeZone, 'Asia/Jakarta');
  assert.equal(list.items.find((item) => item.key === 'SCHEMA_VERSION').is_system, true);

  const updated = app.ok('admin', 'settings.update', { key: 'COMPANY_NAME', value: '  PT Uji Kemasan  ' });
  assert.equal(updated.value, 'PT Uji Kemasan');
  assert.equal(app.ok('viewer', 'session.get', {}).settings.companyName, 'PT Uji Kemasan');
  app.fail('admin', 'settings.update', { key: 'SCHEMA_VERSION', value: '99' }, 'FORBIDDEN');
  app.fail('admin', 'settings.update', { key: 'DEFAULT_PAGE_SIZE', value: 'dua puluh' }, 'VALIDATION_ERROR');
  app.fail('admin', 'settings.update', { key: 'CURRENCY', value: 'rupiah' }, 'VALIDATION_ERROR');
  app.fail('admin', 'settings.update', { key: 'COMPANY_NAME', value: '' }, 'VALIDATION_ERROR');
  app.fail('admin', 'settings.update', { key: 'TIDAK_ADA', value: '1' }, 'NOT_FOUND');
  const audit = app.audit('COMPANY_NAME');
  assert.equal(audit.length, 1);
  assert.equal(audit[0].action, 'SETTING_UPDATE');
});

test('enum: nilai baru hanya untuk enum yang dapat diperluas, nilai sistem tetap aktif, dropdown sheet ikut diperbarui', () => {
  const app = createApp();
  const seed = seedOrder(app);
  const enums = app.ok('admin', 'enums.list', {});
  const activityType = enums.items.find((item) => item.name === 'ACTIVITY_TYPE');
  assert.equal(activityType.extensible, true);
  assert.ok(activityType.usage.some((usage) => usage.startsWith('Aktivitas')));

  app.fail('admin', 'enums.create', { enum_name: 'LEAD_STATUS', enum_value: 'ON_HOLD', label: 'On hold' }, 'VALIDATION_ERROR');
  const created = app.ok('admin', 'enums.create', { enum_name: 'ACTIVITY_TYPE', enum_value: 'site survey', label: 'Site Survey' });
  assert.equal(created.enum_value, 'SITE_SURVEY');
  assert.equal(created.sort_order, 120);
  app.fail('admin', 'enums.create', { enum_name: 'ACTIVITY_TYPE', enum_value: 'SITE_SURVEY', label: 'Lagi' }, 'VALIDATION_ERROR');
  const session = app.ok('sales', 'session.get', {});
  assert.ok(session.enums.ACTIVITY_TYPE.some((item) => item.value === 'SITE_SURVEY' && item.active));
  app.ok('sales', 'activities.create', { data: { customer_id: seed.customer.id, type: 'SITE_SURVEY', subject: 'Survei lini produksi' } });

  const sheet = app.context.getDatabaseSpreadsheet_().getSheetByName('ACTIVITIES');
  const typeColumn = app.context.getTableDef_('ACTIVITIES').columnNames.indexOf('type') + 1;
  const rule = sheet.getRange(2, typeColumn).getDataValidation();
  assert.ok(Array.from(rule.getCriteriaValues()[0]).includes('SITE_SURVEY'), 'dropdown sheet memuat nilai baru');

  app.fail('admin', 'enums.update', { enum_name: 'ACTIVITY_TYPE', enum_value: 'EMAIL', is_active: false }, 'VALIDATION_ERROR');
  const relabeled = app.ok('admin', 'enums.update', { enum_name: 'ACTIVITY_TYPE', enum_value: 'EMAIL', label: 'E-mail', sort_order: 5 });
  assert.equal(relabeled.label, 'E-mail');
  app.ok('admin', 'enums.update', { enum_name: 'ACTIVITY_TYPE', enum_value: 'SITE_SURVEY', is_active: false });
  const inactive = app.fail('sales', 'activities.create', { data: { customer_id: seed.customer.id, type: 'SITE_SURVEY', subject: 'x' } }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(inactive), ['type:ENUM_INACTIVE']);
  assert.equal(app.ok('viewer', 'session.get', {}).enums.ACTIVITY_TYPE[0].label, 'E-mail', 'urutan baru dipakai klien');
  app.fail('admin', 'enums.update', { enum_name: 'TIDAK_ADA', enum_value: 'X' }, 'NOT_FOUND');
  assert.equal(app.audit().filter((row) => row.entity_type === 'ENUMS').length, 3);
});

test('isu migrasi: daftar dengan hitungan, penyelesaian wajib catatan, resolved_by/at diisi server, dapat dibuka ulang', () => {
  const app = createApp();
  const [issue] = app.context.dbInsert_('MIGRATION_ISSUES', [{
    severity: 'HIGH', issue_type: 'FK_NOT_FOUND', entity_type: 'DELIVERIES', record_id: 'DEL-00000000E3', field: 'purchase_order_id',
    value: 'PO-X', description: 'PO tidak ditemukan', source_sheet: 'DELIVERIES', legacy_row: 7,
  }, {
    severity: 'INFO', issue_type: 'UNMAPPED_VALUE', entity_type: 'CUSTOMERS', description: 'Kolom tidak dimigrasikan',
  }], { actor: OWNER, migration: true });
  const list = app.ok('admin', 'migrationIssues.list', {});
  assert.equal(list.items[0].id, issue.id, 'HIGH sebelum INFO');
  assert.deepEqual(list.counts.bySeverity, { HIGH: 1, INFO: 1 });
  assert.equal(list.counts.open, 2);
  assert.equal(app.ok('admin', 'migrationIssues.list', { filters: { severity: 'INFO' } }).total, 1);

  const noNote = app.fail('admin', 'migrationIssues.resolve', { id: issue.id, resolution_status: 'RESOLVED' }, 'VALIDATION_ERROR');
  assert.deepEqual(app.fieldErrors(noNote), ['resolution_note:REQUIRED']);
  app.fail('admin', 'migrationIssues.resolve', { id: issue.id, resolution_status: 'SELESAI', resolution_note: 'x' }, 'VALIDATION_ERROR');
  const resolved = app.ok('admin', 'migrationIssues.resolve', { id: issue.id, resolution_status: 'ACCEPTED_AS_IS', resolution_note: 'Dicek manual, PO tidak ada di arsip' });
  assert.equal(resolved.resolved_by, OWNER);
  assert.equal(resolved.resolved_at, app.now());
  assert.equal(app.ok('admin', 'migrationIssues.list', {}).counts.open, 1);
  const reopened = app.ok('admin', 'migrationIssues.resolve', { id: issue.id, resolution_status: 'OPEN' });
  assert.equal(reopened.resolved_by, null);
  app.fail('marketing', 'migrationIssues.list', {}, 'FORBIDDEN');
  const again = app.ok('admin', 'migrationIssues.resolve', { id: issue.id, resolution_status: 'RESOLVED', resolution_note: 'Sudah dikaitkan', description: 'ubah' });
  assert.equal(again.description, 'PO tidak ditemukan', 'data isu dari migrasi tidak dapat diubah lewat penyelesaian');
});
