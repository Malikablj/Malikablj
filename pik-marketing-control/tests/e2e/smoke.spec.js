/**
 * Smoke test of the main sales flow through the real UI, API and database:
 * Login → open customer → create lead → create follow-up → dashboard shows the follow-up.
 */
import { expect, test } from '@playwright/test';
import { businessToday, E2E_CUSTOMER, E2E_PASSWORD, E2E_USERS } from './fixtures.js';

const LEAD_NAME = 'Botol serum 30ml (uji E2E)';
const FOLLOW_UP_NOTES = 'Kirim sampel botol serum (uji E2E)';

test('sales flow: customer → lead → follow-up → dashboard', async ({ page }) => {
  const consoleErrors = [];
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });
  page.on('pageerror', (error) => consoleErrors.push(error.message));

  // Login
  await page.goto('/login');
  await page.getByLabel('Email').fill(E2E_USERS.sales.email);
  await page.getByLabel('Password').fill(E2E_PASSWORD);
  await page.getByRole('button', { name: 'Masuk' }).click();
  await expect(page.getByRole('heading', { name: 'Halo, Uji' })).toBeVisible();

  // Open customer
  await page.getByRole('link', { name: 'Customer', exact: true }).click();
  await page.getByRole('link', { name: E2E_CUSTOMER.name }).first().click();
  await expect(page.getByRole('heading', { name: E2E_CUSTOMER.name, level: 1 })).toBeVisible();

  // Create lead
  await page.getByRole('tab', { name: 'Lead' }).click();
  await page.getByRole('button', { name: 'Lead', exact: true }).click();
  const leadDialog = page.getByRole('dialog', { name: 'Tambah lead' });
  await leadDialog.getByLabel('Nama lead / peluang').fill(LEAD_NAME);
  await leadDialog.getByLabel('Estimasi nilai (Rp)').fill('15000000');
  await leadDialog.getByRole('button', { name: 'Simpan' }).click();
  await expect(leadDialog).toBeHidden();
  await expect(page.getByRole('cell', { name: LEAD_NAME })).toBeVisible();

  // Create follow-up for today, linked to the lead
  await page.getByRole('tab', { name: 'Follow Up' }).click();
  await page.getByRole('button', { name: 'Follow up', exact: true }).first().click();
  const followUpDialog = page.getByRole('dialog', { name: 'Jadwalkan follow up' });
  await followUpDialog.getByLabel('Tanggal').fill(businessToday());
  await followUpDialog.getByLabel('Lead').selectOption({ label: LEAD_NAME });
  await followUpDialog.getByLabel('Catatan / yang akan dibahas').fill(FOLLOW_UP_NOTES);
  await followUpDialog.getByRole('button', { name: 'Simpan' }).click();
  await expect(followUpDialog).toBeHidden();
  await expect(page.getByText(FOLLOW_UP_NOTES)).toBeVisible();

  // Dashboard shows the follow-up due today
  await page.getByRole('link', { name: 'Dashboard', exact: true }).click();
  const todayKpi = page.getByRole('link', { name: /Follow Up Hari Ini/ });
  await expect(todayKpi).toContainText('1');
  const followUpItem = page.locator('.fu-item', { hasText: FOLLOW_UP_NOTES });
  await expect(followUpItem).toBeVisible();
  await expect(followUpItem).toContainText(E2E_CUSTOMER.name);
  await expect(followUpItem).toContainText(LEAD_NAME);

  expect(consoleErrors, consoleErrors.join('\n')).toEqual([]);
});
