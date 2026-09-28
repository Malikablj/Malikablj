/**
 * Authorization matrix (Technical Specification §9), shared so the UI can hide actions
 * a user is not allowed to take. The API enforces the same matrix server-side; the UI
 * copy is only a convenience and is never trusted.
 *
 * Access levels:
 *   'RW'  - read and write every record
 *   'OWN' - read every record, write only records the user owns (owner_user_id)
 *   'R'   - read only
 *   null  - no access
 *
 * Modules marked "not in spec matrix" follow the closest listed module; see docs/DECISIONS.md.
 */

export const MODULE = Object.freeze({
  DASHBOARD: 'DASHBOARD',
  CUSTOMERS: 'CUSTOMERS',
  CONTACTS: 'CONTACTS',
  LEADS: 'LEADS',
  ACTIVITIES: 'ACTIVITIES',
  FOLLOW_UPS: 'FOLLOW_UPS',
  PURCHASE_ORDERS: 'PURCHASE_ORDERS',
  DELIVERIES: 'DELIVERIES',
  RETURNS: 'RETURNS',
  PRODUCTS: 'PRODUCTS',
  STOCK: 'STOCK',
  LEAD_TIME: 'LEAD_TIME',
  INBOUND_MAKLON: 'INBOUND_MAKLON',
  FINANCE: 'FINANCE',
  REPORTS: 'REPORTS',
  USERS: 'USERS',
  MIGRATION: 'MIGRATION',
});

const CRM_WRITE = { ADMIN: 'RW', MARKETING: 'RW', SALES: 'RW', MANAGEMENT: 'R', VIEWER: 'R' };
const ADMIN_WRITE = { ADMIN: 'RW', MARKETING: 'R', SALES: 'R', MANAGEMENT: 'R', VIEWER: 'R' };

export const PERMISSION_MATRIX = Object.freeze({
  DASHBOARD: ADMIN_WRITE,
  CUSTOMERS: CRM_WRITE,
  CONTACTS: CRM_WRITE,
  LEADS: CRM_WRITE,
  ACTIVITIES: CRM_WRITE,
  FOLLOW_UPS: CRM_WRITE,
  // Spec lists Marketing as "RW/R": interpreted as write own POs, read the rest.
  PURCHASE_ORDERS: { ADMIN: 'RW', MARKETING: 'OWN', SALES: 'R', MANAGEMENT: 'R', VIEWER: 'R' },
  DELIVERIES: ADMIN_WRITE,
  RETURNS: ADMIN_WRITE,
  PRODUCTS: ADMIN_WRITE,
  STOCK: ADMIN_WRITE,
  LEAD_TIME: ADMIN_WRITE, // not in spec matrix: follows Products
  INBOUND_MAKLON: ADMIN_WRITE, // not in spec matrix: follows Delivery
  // Not in spec matrix: financial amounts are hidden from the Viewer role.
  FINANCE: { ADMIN: 'RW', MARKETING: 'R', SALES: 'R', MANAGEMENT: 'R', VIEWER: null },
  REPORTS: ADMIN_WRITE,
  USERS: { ADMIN: 'RW', MARKETING: null, SALES: null, MANAGEMENT: null, VIEWER: null },
  // Not in spec matrix: migration issue review is an admin task; management may read.
  MIGRATION: { ADMIN: 'RW', MARKETING: null, SALES: null, MANAGEMENT: 'R', VIEWER: null },
});

export function accessLevel(role, module) {
  const row = PERMISSION_MATRIX[module];
  if (!row) return null;
  return row[role] ?? null;
}

export function canRead(role, module) {
  return accessLevel(role, module) !== null;
}

/** True when the role may write at least some records of the module. */
export function canWrite(role, module) {
  const level = accessLevel(role, module);
  return level === 'RW' || level === 'OWN';
}

/**
 * True when the user may modify this specific record.
 * For 'OWN' access the record must belong to the user.
 */
export function canWriteRecord(user, module, record) {
  if (!user) return false;
  const level = accessLevel(user.role, module);
  if (level === 'RW') return true;
  if (level === 'OWN') return Boolean(record) && record.owner_user_id === user.id;
  return false;
}
