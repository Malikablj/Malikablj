'use strict';

/**
 * End-to-end harness: starts the development server in-process (the real src/**.gs in the Apps Script emulator, seeded
 * by migrating the invented test workbook with the real migration runner, plus one user per role) and drives the real
 * web app in Chromium with Playwright. Nothing is mocked between the browser and the server code.
 *
 * Screenshots go to e2e/artifacts/ (git-ignored) for visual review.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const { chromium } = require('playwright');
const { ANONYMOUS, DEMO_USERS, OWNER, start } = require('../tools/dev-server/server');

const ARTIFACTS = path.join(__dirname, 'artifacts');
// 'anonymous' = no Google identity: a visitor of a web app on a regular Gmail account (email + password sign-in).
const USERS = { admin: OWNER, anonymous: ANONYMOUS };
DEMO_USERS.forEach((user) => { USERS[user.role.toLowerCase()] = user.email; });
const VIEWPORTS = { desktop: { width: 1440, height: 900 }, tablet: { width: 820, height: 1180 }, phone: { width: 390, height: 844 } };

async function startApp(options = {}) {
  const server = await start({ port: 0, seed: 'synthetic', demoUsers: true, latency: 0, now: null, quiet: true, ...options });
  const base = `http://127.0.0.1:${server.address().port}/`;
  const browser = await chromium.launch();
  fs.mkdirSync(ARTIFACTS, { recursive: true });
  const app = {
    base,
    browser,
    /** Opens the app as `who` (role key or email; null = no identity cookie → script owner). */
    async open(who = 'admin', options2 = {}) {
      const context = await browser.newContext({ viewport: VIEWPORTS[options2.viewport || 'desktop'], locale: 'id-ID', timezoneId: 'Asia/Jakarta' });
      const email = USERS[who] || who;
      if (email) await context.addCookies([{ name: 'pik_dev_user', value: email, url: base }]);
      const page = await context.newPage();
      const problems = [];
      page.on('pageerror', (error) => problems.push(`pageerror: ${error.message}`));
      page.on('console', (message) => { if (message.type() === 'error' && !/Failed to load resource/.test(message.text())) problems.push(`console: ${message.text()}`); });
      await page.goto(base + (options2.hash ? '#' + options2.hash : ''));
      return { context, page, problems };
    },
    /** Opens and signs in (the login screen's "Masuk"); waits for the shell. */
    async signIn(who = 'admin', options2 = {}) {
      const session = await app.open(who, options2);
      await session.page.getByRole('button', { name: 'Masuk', exact: true }).click();
      await session.page.locator('.shell').waitFor();
      await session.page.locator('.view').first().waitFor();
      return session;
    },
    async reset(body = {}) {
      const response = await fetch(base + '__dev/reset', { method: 'POST', body: JSON.stringify(body) });
      assert.equal(response.status, 200);
    },
    async close() {
      await browser.close();
      await new Promise((resolve) => server.close(resolve));
    },
  };
  return app;
}

/** Calls the server API from the page (same google.script.run path and session token as the UI) and returns the envelope. */
function apiCall(page, action, payload) {
  return page.evaluate(({ action: a, payload: p }) => new Promise((resolve) => {
    google.script.run.withSuccessHandler(resolve).withFailureHandler((error) => resolve({ success: false, error: { code: 'NETWORK', message: String(error) } }))
      .api(a, p || {}, window.PIK && PIK.auth ? PIK.auth.token() : null);
  }), { action, payload });
}

async function api(page, action, payload) {
  const response = await apiCall(page, action, payload);
  assert.equal(response.success, true, `${action}: ${JSON.stringify(response.error)}`);
  return response.data;
}

/** Navigates like a user would (the router), waiting for the new view. */
async function go(page, hash) {
  await page.evaluate((h) => PIK.router.go(h.split('?')[0], PIK.router.parse(h).query), hash);
  await page.locator('.view').first().waitFor();
}

async function idle(page) {
  await page.waitForFunction(() => !document.querySelector('[aria-busy="true"]') && !document.querySelector('.drawer-foot .spinner'), null, { timeout: 15000 });
}

/** The page content (not the sidebar or bottom navigation, which repeat module names). */
function main(page) {
  return page.locator('#main');
}

/** The visible list of a list screen: the table on wide screens, the cards on phones (both exist in the DOM). */
function rows(page, text) {
  return page.locator('#main .table-scroll tbody tr', text ? { hasText: text } : undefined);
}

/** Value of the KPI tile whose label is exactly `label`. */
async function kpiValue(page, label) {
  const tile = page.locator('.kpi').filter({ has: page.locator('.kpi-label', { hasText: new RegExp('^' + label + '$') }) });
  return (await tile.locator('.kpi-value').textContent()).trim();
}

/** Value of a detail-page stat whose label is exactly `label`. */
async function statValue(page, label) {
  const stat = page.locator('.stat').filter({ has: page.locator('.label', { hasText: new RegExp('^' + label + '$') }) });
  return (await stat.locator('.value').textContent()).trim();
}

function drawer(page, title) {
  return page.getByRole('dialog', { name: title });
}

/** Chooses an option in an async picker (combobox) identified by its label. */
async function pick(scope, label, search, optionText) {
  // Required fields end their label with an asterisk, so match the label by its start.
  const input = scope.getByLabel(new RegExp('^' + label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\*?$'));
  await input.click();
  await input.fill(search);
  const option = scope.page ? scope.page().getByRole('option', { name: optionText || search }).first() : scope.getByRole('option', { name: optionText || search }).first();
  await option.waitFor();
  await option.dispatchEvent('mousedown');
}

async function expectToast(page, text) {
  const toast = page.locator('.toast', { hasText: text }).last();
  await toast.waitFor({ timeout: 10000 });
  return toast;
}

async function shot(page, name, options = {}) {
  await page.screenshot({ path: path.join(ARTIFACTS, `${name}.png`), fullPage: options.fullPage === true });
}

function assertNoProblems(session) {
  assert.deepEqual(session.problems, [], `error di browser: ${session.problems.join(' | ')}`);
}

module.exports = {
  USERS, VIEWPORTS, api, apiCall, assertNoProblems, drawer, expectToast, go, idle, kpiValue, main, pick, rows, shot, startApp, statValue,
};
