'use strict';

/**
 * Local development server for the web app. It runs the real Apps Script code (src/**\/*.gs) in the emulator
 * (tools/gas-emulator) and serves doGet() the way HtmlService does, with google.script.run bridged to POST /__rpc and
 * google.script.history / google.script.url backed by the location hash. Nothing here is used in production: the
 * deployed app runs on Apps Script against the Google Sheets database.
 *
 *   node tools/dev-server/server.js [--port 8080] [--seed none|synthetic|workbook=<file.xlsx>] [--demo-users]
 *                                   [--latency <ms>] [--now <ISO timestamp>]
 *
 * Data lives in memory for the life of the process. --seed synthetic migrates the invented workbook of the tests with
 * the real migration runner; --seed workbook=<file> migrates a local workbook (e.g. the real one, which never leaves
 * this machine). --demo-users adds one user per role (invented names) so every role can be tried.
 *
 * Identity: Apps Script identifies the Google account; here the browser picks it with the cookie pik_dev_user
 * (GET /__dev/sign-in?email=...). Without the cookie the script owner is signed in.
 */

const fs = require('node:fs');
const http = require('node:http');
const os = require('node:os');
const path = require('node:path');
const { URL } = require('node:url');

const { loadGasProject } = require('../gas-emulator/load-gas');

const OWNER = 'owner@example.com';
const DEMO_USERS = [
  { name: 'Mira Marketing', email: 'marketing@example.com', role: 'MARKETING' },
  { name: 'Sandi Sales', email: 'sales@example.com', role: 'SALES' },
  { name: 'Maya Management', email: 'management@example.com', role: 'MANAGEMENT' },
  { name: 'Vera Viewer', email: 'viewer@example.com', role: 'VIEWER' },
];
const SHIM = fs.readFileSync(path.join(__dirname, 'google-script-shim.js'), 'utf8');

function parseArgs(argv) {
  const options = { port: 8080, seed: 'none', demoUsers: false, latency: 0, now: null, quiet: false };
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    const value = () => argv[++i];
    if (arg === '--port') options.port = Number(value());
    else if (arg === '--seed') options.seed = value();
    else if (arg.startsWith('--seed=')) options.seed = arg.slice(7);
    else if (arg === '--demo-users') options.demoUsers = true;
    else if (arg === '--latency') options.latency = Number(value());
    else if (arg === '--now') options.now = value();
    else if (arg === '--quiet') options.quiet = true;
    else throw new Error(`Argumen tidak dikenal: ${arg}`);
  }
  return options;
}

/** Values google.script.run cannot carry make the real call fail; the bridge refuses them the same way. */
function transportProblem(value, where = 'result') {
  if (value === null || value === undefined) return null;
  const type = typeof value;
  if (type === 'function' || type === 'symbol' || type === 'bigint') return `${where}: ${type}`;
  if (type === 'number') return Number.isFinite(value) ? null : `${where}: ${value}`;
  if (type !== 'object') return null;
  const tag = Object.prototype.toString.call(value);
  if (tag !== '[object Object]' && tag !== '[object Array]') return `${where}: ${tag}`;
  for (const key of Object.keys(value)) {
    const problem = transportProblem(value[key], `${where}.${key}`);
    if (problem) return problem;
  }
  return null;
}

function createBackend(options) {
  const project = loadGasProject({ env: { properties: {}, activeUserEmail: OWNER, effectiveUserEmail: OWNER } });
  const { context, env } = project;
  if (options.now) context.setClockForTesting_(options.now);
  context.setupDatabase();
  const backend = { context, env, seed: options.seed };

  const asOwner = (callback) => {
    const previous = env.activeUserEmail;
    env.activeUserEmail = OWNER;
    try {
      return callback();
    } finally {
      env.activeUserEmail = previous;
    }
  };
  const api = (action, payload) => {
    const response = JSON.parse(JSON.stringify(context.api(action, payload || {})));
    if (!response.success) throw new Error(`${action}: ${response.error.message}`);
    return response.data;
  };

  if (options.seed && options.seed !== 'none') asOwner(() => seedDatabase(backend, options.seed));
  asOwner(() => {
    api('session.login');
    if (options.demoUsers) {
      const existing = new Set(api('users.list', { includeInactive: true, pageSize: 100 }).items.map((user) => user.email));
      DEMO_USERS.filter((user) => !existing.has(user.email)).forEach((user) => api('users.create', { data: user }));
    }
  });
  return backend;
}

/** Migrates a workbook (the synthetic test workbook or a local file) with the real Phase 03 pipeline. */
function seedDatabase(backend, seed) {
  const { analyzeWorkbook } = require('../../migration/scripts/lib/analyze');
  const { buildMigrationPackage } = require('../../migration/scripts/lib/migration/build-package');
  const { loadTarget } = require('../../migration/scripts/lib/migration/target');
  let file;
  let asOf;
  if (seed === 'synthetic') {
    const { syntheticSheets, writeWorkbook } = require('../../tests/fixtures/synthetic-workbook');
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'pik-dev-'));
    file = writeWorkbook(path.join(dir, 'Synthetic.xlsx'), syntheticSheets());
    asOf = '2025-06-30';
  } else if (seed.startsWith('workbook=')) {
    file = path.resolve(seed.slice('workbook='.length));
  } else {
    throw new Error(`Seed tidak dikenal: ${seed}`);
  }
  const pkg = buildMigrationPackage(analyzeWorkbook(file, asOf ? { asOf } : {}), loadTarget());
  const { context, env } = backend;
  const fileId = env.addDriveFile('migration-package.json', JSON.stringify(pkg));
  env.properties.MIGRATION_PACKAGE_FILE_ID = fileId;
  context.resetConfigCache_();
  const dryRun = JSON.parse(JSON.stringify(context.dryRunMigration()));
  if (!dryRun.ok) throw new Error(`Dry run seed gagal: ${JSON.stringify(dryRun.errors ? dryRun.errors.slice(0, 3) : dryRun)}`);
  for (let run = 0; run < 20; run++) {
    const result = JSON.parse(JSON.stringify(context.runMigration()));
    if (result.completed) return result;
  }
  throw new Error('Migrasi seed tidak selesai.');
}

function readBody(request) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    request.on('data', (chunk) => {
      size += chunk.length;
      if (size > 5 * 1024 * 1024) {
        reject(new Error('Payload terlalu besar'));
        request.destroy();
      } else {
        chunks.push(chunk);
      }
    });
    request.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    request.on('error', reject);
  });
}

function cookies(request) {
  const result = {};
  String(request.headers.cookie || '').split(';').forEach((part) => {
    const index = part.indexOf('=');
    if (index > 0) result[part.slice(0, index).trim()] = decodeURIComponent(part.slice(index + 1).trim());
  });
  return result;
}

function send(response, status, body, headers = {}) {
  response.writeHead(status, { 'Cache-Control': 'no-store', ...headers });
  response.end(body);
}

function renderPage(backend) {
  const html = backend.context.doGet().getContent();
  // HtmlService serves the page inside its own frame with google.script.* available before any page script runs.
  return html.replace('<head>', `<head>\n<script>${SHIM}</script>`);
}

function createServer(options) {
  let backend = createBackend(options);
  const log = options.quiet ? () => {} : (...args) => console.log(...args);

  const server = http.createServer(async (request, response) => {
    const url = new URL(request.url, 'http://localhost');
    try {
      if (request.method === 'GET' && (url.pathname === '/' || url.pathname === '/exec')) {
        send(response, 200, renderPage(backend), { 'Content-Type': 'text/html; charset=utf-8' });
        return;
      }
      if (request.method === 'POST' && url.pathname === '/__rpc') {
        const { fn, args } = JSON.parse(await readBody(request) || '{}');
        const email = cookies(request).pik_dev_user;
        const outcome = { ok: false };
        if (typeof fn !== 'string' || fn.endsWith('_') || typeof backend.context[fn] !== 'function') {
          outcome.error = `Script function not found: ${fn}`;
        } else {
          backend.env.activeUserEmail = email === undefined ? OWNER : email;
          try {
            const result = backend.context[fn](...(Array.isArray(args) ? args : []));
            const problem = transportProblem(result);
            if (problem) outcome.error = `Nilai tidak dapat dikirim google.script.run (${problem})`;
            else Object.assign(outcome, { ok: true, result: result === undefined ? null : JSON.parse(JSON.stringify(result)) });
          } catch (error) {
            outcome.error = error && error.message ? error.message : String(error);
          } finally {
            backend.env.activeUserEmail = OWNER;
          }
        }
        const action = fn === 'api' && Array.isArray(args) ? args[0] : fn;
        log(`${new Date().toISOString().slice(11, 19)} ${email || OWNER} ${action} ${outcome.ok ? (outcome.result && outcome.result.success === false ? outcome.result.error.code : 'ok') : 'FAIL'}`);
        const reply = () => send(response, 200, JSON.stringify(outcome), { 'Content-Type': 'application/json' });
        if (options.latency > 0) setTimeout(reply, options.latency);
        else reply();
        return;
      }
      if (request.method === 'GET' && url.pathname === '/__dev/sign-in') {
        const email = String(url.searchParams.get('email') || '').trim().toLowerCase();
        const cookie = email ? `pik_dev_user=${encodeURIComponent(email)}; Path=/; SameSite=Lax` : 'pik_dev_user=; Path=/; Max-Age=0';
        send(response, 302, '', { Location: url.searchParams.get('next') || '/', 'Set-Cookie': cookie });
        return;
      }
      if (request.method === 'POST' && url.pathname === '/__dev/reset') {
        const body = JSON.parse(await readBody(request) || '{}');
        backend = createBackend({ ...options, ...body });
        send(response, 200, JSON.stringify({ ok: true }), { 'Content-Type': 'application/json' });
        return;
      }
      if (request.method === 'POST' && url.pathname === '/__dev/clock') {
        const body = JSON.parse(await readBody(request) || '{}');
        backend.context.setClockForTesting_(body.now || null);
        send(response, 200, JSON.stringify({ ok: true }), { 'Content-Type': 'application/json' });
        return;
      }
      if (request.method === 'GET' && url.pathname === '/__dev/health') {
        send(response, 200, JSON.stringify({ ok: true, seed: backend.seed }), { 'Content-Type': 'application/json' });
        return;
      }
      send(response, 404, 'Not found', { 'Content-Type': 'text/plain' });
    } catch (error) {
      console.error(error);
      send(response, 500, 'Dev server error', { 'Content-Type': 'text/plain' });
    }
  });
  return server;
}

function start(options) {
  const server = createServer(options);
  return new Promise((resolve) => {
    server.listen(options.port, '127.0.0.1', () => {
      if (!options.quiet) {
        console.log(`PIK Marketing Control (dev) di http://127.0.0.1:${server.address().port}`);
        console.log(`Masuk sebagai user lain: http://127.0.0.1:${server.address().port}/__dev/sign-in?email=sales@example.com`);
      }
      resolve(server);
    });
  });
}

if (require.main === module) {
  start(parseArgs(process.argv.slice(2))).catch((error) => {
    console.error(error);
    process.exit(1);
  });
}

module.exports = { DEMO_USERS, OWNER, createServer, parseArgs, start };
