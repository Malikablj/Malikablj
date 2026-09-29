'use strict';

/**
 * Harness for the client API tests: an emulated Apps Script project with a database created by setupDatabase(), the
 * script owner signed in as first Admin, and one user per role created through the API. Every call goes through the
 * public api(action, payload) exactly as google.script.run sends it, and every response is checked to be transport
 * safe (google.script.run only carries JSON-like values: a Date, function or NaN anywhere makes the call fail).
 *
 * The clock is pinned (Asia/Jakarta 10:00 on TODAY) and advances one second per call, so timestamps are distinct and
 * ordering is deterministic. All data is invented.
 */

const assert = require('node:assert/strict');

const { loadGasProject } = require('../../tools/gas-emulator/load-gas');

const OWNER = 'owner@example.com';
const TODAY = '2026-09-28';
const START = `${TODAY}T03:00:00.000Z`; // 10:00 WIB

const ROLE_USERS = {
  marketing: { name: 'Mira Marketing', email: 'marketing@example.com', role: 'MARKETING' },
  sales: { name: 'Sandi Sales', email: 'sales@example.com', role: 'SALES' },
  management: { name: 'Maya Management', email: 'management@example.com', role: 'MANAGEMENT' },
  viewer: { name: 'Vera Viewer', email: 'viewer@example.com', role: 'VIEWER' },
};

function plain(value) {
  return value === undefined ? undefined : JSON.parse(JSON.stringify(value));
}

/** Fails on values google.script.run cannot carry. */
function assertTransportSafe(value, where = 'response') {
  if (value === null) return;
  const type = typeof value;
  if (type === 'function' || type === 'symbol' || type === 'bigint') assert.fail(`${where}: tipe ${type} tidak dapat dikirim`);
  if (type === 'number') {
    assert.ok(Number.isFinite(value), `${where}: angka tidak valid (${value})`);
    return;
  }
  if (type !== 'object') return;
  const tag = Object.prototype.toString.call(value);
  assert.ok(tag === '[object Object]' || tag === '[object Array]', `${where}: ${tag} tidak dapat dikirim`);
  if (Array.isArray(value)) {
    value.forEach((item, index) => assertTransportSafe(item, `${where}[${index}]`));
    return;
  }
  for (const key of Object.keys(value)) assertTransportSafe(value[key], `${where}.${key}`);
}

function createApp(options = {}) {
  const { context, env } = loadGasProject({ env: { properties: {}, ...(options.env || {}) } });
  let clockMs = Date.parse(options.now || START);
  context.setClockForTesting_(new Date(clockMs).toISOString());
  context.setupDatabase();

  const emails = { admin: OWNER };
  const app = {
    context,
    env,
    users: {},
    /** Google identity of `who`: role key or email; 'anonymous' = none (regular Gmail deployment, not the owner). */
    emailOf(who) {
      if (who === 'anonymous') return '';
      return emails[who] || who;
    },
    /** Moves the pinned clock (ISO timestamp); later calls continue from there. */
    setNow(iso) {
      clockMs = Date.parse(iso);
      context.setClockForTesting_(new Date(clockMs).toISOString());
    },
    now() {
      return new Date(clockMs).toISOString();
    },
    /** Raw envelope of api(action, payload) called by `who` (role key or email). */
    call(who, action, payload) {
      clockMs += 1000;
      context.setClockForTesting_(new Date(clockMs).toISOString());
      env.activeUserEmail = app.emailOf(who);
      const response = context.api(action, payload === undefined ? {} : payload);
      assertTransportSafe(response, action);
      return plain(response);
    },
    /** Raw envelope of api(action, payload, token) from a browser without a Google identity (password sign-in). */
    callWithToken(token, action, payload) {
      clockMs += 1000;
      context.setClockForTesting_(new Date(clockMs).toISOString());
      env.activeUserEmail = '';
      const response = context.api(action, payload === undefined ? {} : payload, token);
      assertTransportSafe(response, action);
      return plain(response);
    },
    okWithToken(token, action, payload) {
      const response = app.callWithToken(token, action, payload);
      assert.equal(response.success, true, `${action} dengan token gagal: ${JSON.stringify(response.error)}`);
      return response.data;
    },
    failWithToken(token, action, payload, code) {
      const response = app.callWithToken(token, action, payload);
      assert.equal(response.success, false, `${action} dengan token seharusnya gagal (${code})`);
      if (code) assert.equal(response.error.code, code, `${action}: ${JSON.stringify(response.error)}`);
      return response.error;
    },
    ok(who, action, payload) {
      const response = app.call(who, action, payload);
      assert.equal(response.success, true, `${action} oleh ${who} gagal: ${JSON.stringify(response.error)}`);
      return response.data;
    },
    fail(who, action, payload, code) {
      const response = app.call(who, action, payload);
      assert.equal(response.success, false, `${action} oleh ${who} seharusnya gagal (${code})`);
      if (code) assert.equal(response.error.code, code, `${action}: ${JSON.stringify(response.error)}`);
      return response.error;
    },
    /** Field codes of a validation error: ['field:CODE', ...]. */
    fieldErrors(error) {
      return ((error.details && error.details.errors) || []).map((item) => `${item.field}:${item.code}`);
    },
    /** Rows of a sheet as records (header row as keys). */
    rows(tableName) {
      const sheet = context.getDatabaseSpreadsheet_().getSheetByName(tableName);
      const values = plain(sheet.getDataRange().getValues());
      const [header, ...rows] = values;
      return rows.filter((row) => row.some((cell) => cell !== '' && cell !== false))
        .map((row) => Object.fromEntries(header.map((name, index) => [name, row[index]])));
    },
    audit(entityId) {
      return app.rows('AUDIT_LOG').filter((row) => !entityId || row.entity_id === entityId);
    },
    mutatingCalls() {
      return env.calls.filter((item) => item.mutating);
    },
  };

  if (options.bootstrap !== false) {
    app.users.admin = app.ok(OWNER, 'session.login').user;
    for (const [key, user] of Object.entries(ROLE_USERS)) {
      app.users[key] = app.ok('admin', 'users.create', { data: user });
      emails[key] = user.email;
    }
  }
  return app;
}

/** A customer with a primary contact, a product and an open PO with two lines (quantities 100 and 50). */
function seedOrder(app, who = 'admin') {
  const customer = app.ok(who, 'customers.create', { data: { name: 'PT Contoh Kemasan', customer_code: 'C-001', status: 'ACTIVE' } });
  const contact = app.ok(who, 'contacts.create', { data: { customer_id: customer.id, name: 'Budi Contoh', is_primary: true } });
  const productA = app.ok('admin', 'products.create', { data: { product_code: 'BTL-100', name: 'Botol 100 ml', unit: 'pcs' } });
  const productB = app.ok('admin', 'products.create', { data: { product_code: 'CAP-28', name: 'Tutup 28 mm', unit: 'pcs' } });
  const po = app.ok('admin', 'purchaseOrders.create', {
    data: { po_number: 'PO/TEST/001', customer_id: customer.id, po_date: TODAY },
    lines: [
      { product_id: productA.id, order_quantity: 100, unit_price: 1500 },
      { product_id: productB.id, order_quantity: 50 },
    ],
  });
  const detail = app.ok('admin', 'purchaseOrders.get', { id: po.id });
  const lineA = detail.lines.find((line) => line.product_id === productA.id);
  const lineB = detail.lines.find((line) => line.product_id === productB.id);
  return { customer, contact, productA, productB, po, lineA, lineB };
}

module.exports = { OWNER, TODAY, START, ROLE_USERS, assertTransportSafe, createApp, plain, seedOrder };
