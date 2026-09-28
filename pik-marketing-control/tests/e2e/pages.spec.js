/**
 * Opens every page with realistic data for several roles and fails on console errors, error
 * states, horizontal overflow on phones, or write actions shown to read-only roles.
 * Set E2E_SCREENSHOTS=<dir> to also save a full-page screenshot of every visit.
 */
import fs from 'node:fs';
import path from 'node:path';
import { expect, test } from '@playwright/test';
import { businessToday, E2E_PASSWORD, E2E_PORT, E2E_USERS } from './fixtures.js';
import { apiAs, seedOperationalData } from './seed.js';

const SCREENSHOT_DIR = process.env.E2E_SCREENSHOTS;
const PHONE = { width: 390, height: 844 };
let seed;

test.describe.configure({ mode: 'serial' });

test.beforeAll(async ({ playwright }) => {
  const admin = await apiAs(playwright, `http://localhost:${E2E_PORT}`, E2E_USERS.admin);
  seed = await seedOperationalData(admin, businessToday());
  await admin.dispose();
});

async function login(page, user) {
  await page.goto('/login');
  await page.getByLabel('Email').fill(user.email);
  await page.getByLabel('Password').fill(E2E_PASSWORD);
  await page.getByRole('button', { name: 'Masuk' }).click();
  await page.waitForURL('**/dashboard');
  await expect(page.getByRole('heading', { name: /^Halo, / })).toBeVisible();
}

/** Collects console errors and failed API calls for the whole test. */
function watchErrors(page) {
  const errors = [];
  page.on('console', (message) => message.type() === 'error' && errors.push(`console: ${message.text()}`));
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
  page.on('response', (response) => {
    if (response.url().includes('/api/') && response.status() >= 400) errors.push(`${response.status()} ${response.url()}`);
  });
  return errors;
}

async function visit(page, url, name) {
  await page.goto(url);
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
  expect(new URL(page.url()).pathname, `redirected away from ${url}`).toBe(new URL(url, page.url()).pathname);
  await page.waitForLoadState('networkidle');
  await expect(page.locator('.skeleton-rows')).toHaveCount(0);
  await expect(page.getByText('Data gagal dimuat'), `error state on ${url}`).toHaveCount(0);
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow, `horizontal overflow on ${url}`).toBeLessThanOrEqual(0);
  if (SCREENSHOT_DIR) {
    fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
    // Phones: the visible screen (a full-page capture would draw the fixed bottom menu mid-page).
    const phone = (page.viewportSize()?.width ?? PHONE.width) <= PHONE.width;
    await page.screenshot({ path: path.join(SCREENSHOT_DIR, `${name}.png`), fullPage: !phone });
  }
}

test('admin can open every page without errors', async ({ page }) => {
  const errors = watchErrors(page);
  await login(page, E2E_USERS.admin);
  const { customers, leads, purchaseOrders, products } = seed;
  const pages = [
    ['/dashboard', 'admin-dashboard'],
    ['/customers', 'admin-customers'],
    [`/customers/${customers.cosmetics.id}`, 'admin-customer-overview'],
    [`/customers/${customers.cosmetics.id}?tab=contacts`, 'admin-customer-contacts'],
    [`/customers/${customers.cosmetics.id}?tab=activities`, 'admin-customer-activities'],
    [`/customers/${customers.cosmetics.id}?tab=leads`, 'admin-customer-leads'],
    [`/customers/${customers.cosmetics.id}?tab=follow-ups`, 'admin-customer-follow-ups'],
    [`/customers/${customers.cosmetics.id}?tab=purchase-orders`, 'admin-customer-purchase-orders'],
    [`/customers/${customers.cosmetics.id}?tab=deliveries`, 'admin-customer-deliveries'],
    [`/customers/${customers.cosmetics.id}?tab=returns`, 'admin-customer-returns'],
    ['/leads?view=board', 'admin-leads-board'],
    ['/leads?view=list', 'admin-leads-list'],
    [`/leads/${leads.quotation.id}`, 'admin-lead-detail'],
    ['/activities', 'admin-activities'],
    ['/follow-ups?tab=TODAY', 'admin-follow-ups-today'],
    ['/follow-ups?tab=OVERDUE', 'admin-follow-ups-overdue'],
    ['/follow-ups?tab=DONE', 'admin-follow-ups-done'],
    ['/purchase-orders', 'admin-purchase-orders'],
    ['/purchase-orders/new', 'admin-purchase-order-new'],
    [`/purchase-orders/${purchaseOrders.running.id}`, 'admin-purchase-order-detail'],
    [`/purchase-orders/${purchaseOrders.cancelled.id}`, 'admin-purchase-order-cancelled'],
    ['/deliveries', 'admin-deliveries'],
    ['/returns', 'admin-returns'],
    ['/products', 'admin-products'],
    [`/products/${products.bottle.id}`, 'admin-product-detail'],
    ['/stock', 'admin-stock'],
    ['/stock?view=history', 'admin-stock-history'],
    ['/lead-times', 'admin-lead-times'],
    ['/inbound-maklon', 'admin-inbound-maklon'],
    ['/finance', 'admin-finance'],
    ['/finance?tab=po', 'admin-finance-po'],
    ['/reports', 'admin-reports'],
    ['/reports?report=purchase-orders', 'admin-report-purchase-orders'],
    ['/settings', 'admin-account'],
    ['/settings/users', 'admin-users'],
    ['/settings/migration-issues', 'admin-migration-issues'],
  ];
  for (const [url, name] of pages) await visit(page, url, name);

  // Numbers on the PO page come from the database views (10.000 ordered − 4.000 delivered + 150 returned).
  await page.goto(`/purchase-orders/${purchaseOrders.running.id}`);
  await expect(page.locator('.stat-strip')).toContainText('Outstanding');
  const lines = page.getByRole('table', { name: 'Item PO' });
  await expect(lines.getByRole('row', { name: /Botol PET 100ml Uji/ })).toContainText('6.150');
  expect(errors).toEqual([]);
});

test('read-only roles see data but no write actions', async ({ page }) => {
  const errors = watchErrors(page);
  await login(page, E2E_USERS.viewer);
  await visit(page, '/customers', 'viewer-customers');
  await expect(page.getByRole('button', { name: /Tambah Customer/i })).toHaveCount(0);
  await visit(page, `/purchase-orders/${seed.purchaseOrders.running.id}`, 'viewer-purchase-order');
  await expect(page.getByRole('button', { name: /Catat pengiriman/ })).toHaveCount(0);
  await expect(page.getByRole('combobox', { name: 'Ubah status PO' })).toHaveCount(0);
  // Finance amounts are hidden from the Viewer role.
  await expect(page.getByText('Invoice & pembayaran')).toHaveCount(0);
  await page.goto('/finance');
  await expect(page.getByText('Tidak ada akses')).toBeVisible();
  expect(errors).toEqual([]);
});

test('marketing can edit only its own purchase orders', async ({ page }) => {
  const errors = watchErrors(page);
  await login(page, E2E_USERS.marketing);
  await visit(page, `/purchase-orders/${seed.purchaseOrders.running.id}`, 'marketing-own-po');
  await expect(page.getByRole('button', { name: 'Ubah', exact: true })).toBeVisible();
  await visit(page, '/dashboard', 'marketing-dashboard');
  await expect(page.getByRole('link', { name: /Follow Up Hari Ini/ })).toContainText('1');
  expect(errors).toEqual([]);
});

test.describe('phone layout', () => {
  test.use({ viewport: PHONE, isMobile: true, hasTouch: true });

  test('sales and management pages fit a phone screen', async ({ page }) => {
    const errors = watchErrors(page);
    await login(page, E2E_USERS.management);
    const { customers, purchaseOrders, leads } = seed;
    for (const [url, name] of [
      ['/dashboard', 'phone-dashboard'],
      ['/customers', 'phone-customers'],
      [`/customers/${customers.cosmetics.id}`, 'phone-customer'],
      [`/customers/${customers.cosmetics.id}?tab=follow-ups`, 'phone-customer-follow-ups'],
      ['/leads', 'phone-leads'],
      [`/leads/${leads.quotation.id}`, 'phone-lead'],
      ['/follow-ups?tab=OVERDUE', 'phone-follow-ups'],
      ['/purchase-orders', 'phone-purchase-orders'],
      [`/purchase-orders/${purchaseOrders.running.id}`, 'phone-purchase-order'],
      ['/deliveries', 'phone-deliveries'],
      ['/stock', 'phone-stock'],
      ['/reports', 'phone-reports'],
    ]) {
      await visit(page, url, name);
    }
    // The menu drawer opens from the bottom navigation.
    await page.getByRole('button', { name: 'Menu', exact: true }).click();
    await expect(page.getByRole('link', { name: 'Laporan' })).toBeVisible();
    expect(errors).toEqual([]);
  });
});
