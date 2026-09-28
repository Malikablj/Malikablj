/** Global search, notifications and migration issue review. */
import { canRead, canWrite, MODULE } from '@pik/shared';
import * as insightRepository from '../repositories/insightRepository.js';
import { businessToday } from '../utils/dates.js';
import { notFound } from '../utils/errors.js';

const SEARCH_GROUPS = [
  { group: 'customers', module: MODULE.CUSTOMERS, url: (row) => `/customers/${row.id}` },
  { group: 'contacts', module: MODULE.CONTACTS, url: (row) => `/customers/${row.customer_id}?tab=contacts` },
  { group: 'leads', module: MODULE.LEADS, url: (row) => `/leads/${row.id}` },
  { group: 'purchase_orders', module: MODULE.PURCHASE_ORDERS, url: (row) => `/purchase-orders/${row.id}` },
  { group: 'products', module: MODULE.PRODUCTS, url: (row) => `/products/${row.id}` },
];

/** Searches the modules the user may read (5 results per group). */
export async function search(text, user) {
  const groups = SEARCH_GROUPS.filter((group) => canRead(user.role, group.module));
  const results = await Promise.all(groups.map((group) => insightRepository.search(group.group, text, 5)));
  return groups
    .map((group, index) => ({
      group: group.group,
      items: results[index].map((row) => ({ id: row.id, title: row.title, subtitle: row.subtitle, url: group.url(row) })),
    }))
    .filter((group) => group.items.length);
}

/** Things that need the user's attention now; derived live, nothing is stored. */
export async function notifications(user) {
  const today = businessToday();
  const { followUps, latePurchaseOrders } = await insightRepository.notificationSources(user.id, today);
  const items = [
    ...followUps.map((f) => ({
      type: f.state === 'OVERDUE' ? 'FOLLOW_UP_OVERDUE' : 'FOLLOW_UP_TODAY',
      severity: f.state === 'OVERDUE' ? 'danger' : 'warning',
      title: f.state === 'OVERDUE' ? `Follow up terlambat: ${f.customer_name}` : `Follow up hari ini: ${f.customer_name}`,
      subtitle: [f.lead_name, f.follow_up_time?.slice(0, 5)].filter(Boolean).join(' · ') || null,
      date: f.follow_up_date,
      url: `/follow-ups?focus=${f.id}`,
    })),
    ...latePurchaseOrders.map((po) => ({
      type: 'PO_LATE',
      severity: 'danger',
      title: `PO melewati target kirim: ${po.po_number}`,
      subtitle: po.customer_name,
      date: po.expected_delivery_date,
      url: `/purchase-orders/${po.id}`,
    })),
  ];
  if (canWrite(user.role, MODULE.MIGRATION)) {
    const errors = await insightRepository.openMigrationErrors();
    if (errors > 0) {
      items.push({
        type: 'MIGRATION_ERRORS',
        severity: 'warning',
        title: `${errors} issue migrasi (ERROR) belum ditangani`,
        subtitle: null,
        date: null,
        url: '/settings/migration-issues',
      });
    }
  }
  return { count: items.length, items };
}

export const listIssues = (filters) => insightRepository.listIssues(filters);
export const issueSummary = () => insightRepository.issueSummary();

export async function resolveIssue(id, { resolution_status: status, resolution_notes: notes }, user) {
  if (!(await insightRepository.findIssue(id))) throw notFound('Issue migrasi tidak ditemukan.');
  await insightRepository.resolveIssue(id, { status, notes, userId: user.id });
  return insightRepository.findIssue(id);
}
