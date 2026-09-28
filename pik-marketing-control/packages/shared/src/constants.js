/**
 * Enumerations shared by the database, the API and the UI.
 *
 * The values are the canonical codes stored in PostgreSQL (see CHECK constraints in
 * database/schema.sql). Labels are what the UI shows. Pipeline and status terms that the
 * business already uses in English (per the Technical Specification) keep their English
 * labels; everything else is Bahasa Indonesia.
 *
 * `tone` drives badge colour in the UI: neutral | info | accent | success | warning | danger.
 */

function defineEnum(entries) {
  const values = entries.map(([value]) => value);
  const labels = Object.fromEntries(entries.map(([value, label]) => [value, label]));
  const tones = Object.fromEntries(entries.map(([value, , tone]) => [value, tone ?? 'neutral']));
  const options = entries.map(([value, label]) => ({ value, label }));
  return Object.freeze({ values, labels, tones, options });
}

export const ROLE = defineEnum([
  ['ADMIN', 'Admin', 'accent'],
  ['MARKETING', 'Marketing', 'info'],
  ['SALES', 'Sales', 'info'],
  ['MANAGEMENT', 'Management', 'neutral'],
  ['VIEWER', 'Viewer', 'neutral'],
]);

export const CUSTOMER_STATUS = defineEnum([
  ['ACTIVE', 'Aktif', 'success'],
  ['POTENTIAL', 'Potensial', 'info'],
  ['DORMANT', 'Dormant', 'warning'],
  ['INACTIVE', 'Tidak aktif', 'neutral'],
]);

export const LEAD_STATUS = defineEnum([
  ['NEW', 'New', 'info'],
  ['CONTACTED', 'Contacted', 'info'],
  ['QUALIFIED', 'Qualified', 'accent'],
  ['QUOTATION', 'Quotation', 'accent'],
  ['NEGOTIATION', 'Negotiation', 'warning'],
  ['WON', 'Won', 'success'],
  ['LOST', 'Lost', 'danger'],
  ['DORMANT', 'Dormant', 'neutral'],
]);

/** Pipeline stages that count as an "Active Lead". */
export const LEAD_OPEN_STATUSES = Object.freeze(['NEW', 'CONTACTED', 'QUALIFIED', 'QUOTATION', 'NEGOTIATION']);

export const PRIORITY = defineEnum([
  ['LOW', 'Rendah', 'neutral'],
  ['MEDIUM', 'Sedang', 'warning'],
  ['HIGH', 'Tinggi', 'danger'],
]);

export const ACTIVITY_TYPE = defineEnum([
  ['WHATSAPP', 'WhatsApp', 'success'],
  ['CALL', 'Telepon', 'info'],
  ['EMAIL', 'Email', 'info'],
  ['MEETING', 'Meeting', 'accent'],
  ['VISIT', 'Kunjungan', 'accent'],
  ['QUOTATION', 'Penawaran', 'warning'],
  ['SAMPLE', 'Sampel', 'warning'],
  ['PRESENTATION', 'Presentasi', 'accent'],
  ['FOLLOW_UP', 'Follow Up', 'info'],
  ['COMPLAINT', 'Komplain', 'danger'],
  ['NOTE', 'Catatan', 'neutral'],
  ['OTHER', 'Lainnya', 'neutral'],
]);

/** Stored follow-up status (what a user sets). */
export const FOLLOW_UP_STATUS = defineEnum([
  ['PLANNED', 'Planned', 'info'],
  ['DONE', 'Done', 'success'],
  ['RESCHEDULE', 'Reschedule', 'warning'],
  ['CANCELLED', 'Cancelled', 'neutral'],
  ['OVERDUE', 'Overdue', 'danger'],
]);

/** Statuses that mean the follow-up still has to be done. */
export const FOLLOW_UP_OPEN_STATUSES = Object.freeze(['PLANNED', 'RESCHEDULE', 'OVERDUE']);

/**
 * Derived follow-up state, computed by the database function follow_up_state()
 * relative to "today" in the business timezone. This is what lists and the dashboard use.
 */
export const FOLLOW_UP_STATE = defineEnum([
  ['OVERDUE', 'Terlambat', 'danger'],
  ['TODAY', 'Hari ini', 'warning'],
  ['UPCOMING', 'Akan datang', 'info'],
  ['DONE', 'Selesai', 'success'],
  ['CANCELLED', 'Dibatalkan', 'neutral'],
]);

export const PO_STATUS = defineEnum([
  ['OPEN', 'Open', 'info'],
  ['ON_PROCESS', 'On Process', 'accent'],
  ['PARTIAL', 'Partial', 'warning'],
  ['CLOSED', 'Closed', 'success'],
  ['CANCELLED', 'Cancelled', 'neutral'],
]);

/** PO statuses counted as "Open PO" and included in the outstanding quantity KPI. */
export const PO_OPEN_STATUSES = Object.freeze(['OPEN', 'ON_PROCESS', 'PARTIAL']);

export const DELIVERY_STATUS = defineEnum([
  ['SCHEDULED', 'Scheduled', 'info'],
  ['ON_DELIVERY', 'On Delivery', 'accent'],
  ['DELIVERED', 'Delivered', 'success'],
  ['DELAYED', 'Delayed', 'danger'],
  ['CANCELLED', 'Cancelled', 'neutral'],
]);

/** Only these delivery statuses count towards "Delivered Quantity". */
export const DELIVERY_COUNTED_STATUSES = Object.freeze(['DELIVERED']);

export const RETURN_STATUS = defineEnum([
  ['REPORTED', 'Dilaporkan', 'warning'],
  ['RECEIVED', 'Diterima', 'info'],
  ['RESOLVED', 'Selesai', 'success'],
  ['CANCELLED', 'Dibatalkan', 'neutral'],
]);

/** Only these return statuses count towards "Returned Quantity" (goods physically back). */
export const RETURN_COUNTED_STATUSES = Object.freeze(['RECEIVED', 'RESOLVED']);

export const PRODUCT_STATUS = defineEnum([
  ['ACTIVE', 'Aktif', 'success'],
  ['DEVELOPMENT', 'Pengembangan', 'info'],
  ['DISCONTINUED', 'Discontinued', 'neutral'],
]);

export const STOCK_TYPE = defineEnum([
  ['FG', 'Finished Goods', 'success'],
  ['WIP', 'WIP', 'warning'],
  ['READY', 'Ready', 'info'],
  ['RESERVED', 'Reserved', 'accent'],
]);

export const PAYMENT_STATUS = defineEnum([
  ['UNPAID', 'Belum dibayar', 'warning'],
  ['PARTIAL', 'Dibayar sebagian', 'accent'],
  ['PAID', 'Lunas', 'success'],
  ['CANCELLED', 'Dibatalkan', 'neutral'],
]);

export const MIGRATION_ISSUE_STATUS = defineEnum([
  ['OPEN', 'Terbuka', 'warning'],
  ['RESOLVED', 'Selesai', 'success'],
  ['IGNORED', 'Diabaikan', 'neutral'],
]);

export const MIGRATION_ISSUE_SEVERITY = defineEnum([
  ['INFO', 'Info', 'info'],
  ['WARNING', 'Peringatan', 'warning'],
  ['ERROR', 'Error', 'danger'],
]);

/** UI suggestions for the free-text lead source field (not business data). */
export const LEAD_SOURCE_SUGGESTIONS = Object.freeze([
  'Referral',
  'Website',
  'Instagram',
  'WhatsApp',
  'Pameran',
  'Kunjungan',
  'Repeat order',
  'Lainnya',
]);

export const PAGE_SIZE_DEFAULT = 25;
export const PAGE_SIZE_MAX = 100;
