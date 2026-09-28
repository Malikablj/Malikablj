/**
 * Authentication and authorization for the web app.
 *
 * Identity is the caller's Google account (Session.getActiveUser, web app deployed "execute as me" for the PIK
 * domain), matched case-insensitively with USERS.email. Only active users can call the API, and the role always comes
 * from USERS, never from the client. First run: while USERS has no active ADMIN, the account the web app runs as (the
 * script owner) is registered as ADMIN when it signs in, so the application can be set up without editing sheets.
 *
 * MODULE_PERMISSIONS is the authorization matrix of the Technical Specification (§9). Modules the matrix does not
 * cover follow the recommended default of decision D5 (docs/DECISIONS.md): finance Admin RW / Management R / others
 * none; lead time and inbound Admin RW / others R; migration issues, settings and the audit log Admin only; "RW/R" for
 * Marketing on PO read as RW. No per-owner data restriction: every role that can read a module reads all its records.
 */

const ROLE = Object.freeze({ ADMIN: 'ADMIN', MARKETING: 'MARKETING', SALES: 'SALES', MANAGEMENT: 'MANAGEMENT', VIEWER: 'VIEWER' });
const ACCESS = Object.freeze({ READ: 'R', WRITE: 'RW' });

const MODULE_PERMISSIONS = Object.freeze({
  dashboard: { ADMIN: 'RW', MARKETING: 'R', SALES: 'R', MANAGEMENT: 'R', VIEWER: 'R' },
  customers: { ADMIN: 'RW', MARKETING: 'RW', SALES: 'RW', MANAGEMENT: 'R', VIEWER: 'R' },
  contacts: { ADMIN: 'RW', MARKETING: 'RW', SALES: 'RW', MANAGEMENT: 'R', VIEWER: 'R' },
  leads: { ADMIN: 'RW', MARKETING: 'RW', SALES: 'RW', MANAGEMENT: 'R', VIEWER: 'R' },
  activities: { ADMIN: 'RW', MARKETING: 'RW', SALES: 'RW', MANAGEMENT: 'R', VIEWER: 'R' },
  followUps: { ADMIN: 'RW', MARKETING: 'RW', SALES: 'RW', MANAGEMENT: 'R', VIEWER: 'R' },
  purchaseOrders: { ADMIN: 'RW', MARKETING: 'RW', SALES: 'R', MANAGEMENT: 'R', VIEWER: 'R' },
  deliveries: { ADMIN: 'RW', MARKETING: 'R', SALES: 'R', MANAGEMENT: 'R', VIEWER: 'R' },
  returns: { ADMIN: 'RW', MARKETING: 'R', SALES: 'R', MANAGEMENT: 'R', VIEWER: 'R' },
  products: { ADMIN: 'RW', MARKETING: 'R', SALES: 'R', MANAGEMENT: 'R', VIEWER: 'R' },
  stock: { ADMIN: 'RW', MARKETING: 'R', SALES: 'R', MANAGEMENT: 'R', VIEWER: 'R' },
  leadTime: { ADMIN: 'RW', MARKETING: 'R', SALES: 'R', MANAGEMENT: 'R', VIEWER: 'R' },
  inbound: { ADMIN: 'RW', MARKETING: 'R', SALES: 'R', MANAGEMENT: 'R', VIEWER: 'R' },
  finance: { ADMIN: 'RW', MANAGEMENT: 'R' },
  reports: { ADMIN: 'RW', MARKETING: 'R', SALES: 'R', MANAGEMENT: 'R', VIEWER: 'R' },
  users: { ADMIN: 'RW' },
  settings: { ADMIN: 'RW' },
  migrationIssues: { ADMIN: 'RW' },
  audit: { ADMIN: 'R' }
});

const MODULE_LABELS = Object.freeze({
  dashboard: 'Dashboard', customers: 'Customer', contacts: 'Contact', leads: 'Lead', activities: 'Aktivitas',
  followUps: 'Follow-up', purchaseOrders: 'Purchase order', deliveries: 'Delivery', returns: 'Retur',
  products: 'Produk', stock: 'Stok', leadTime: 'Lead time', inbound: 'Inbound maklon', finance: 'Invoice & pembayaran',
  reports: 'Laporan', users: 'User', settings: 'Pengaturan', migrationIssues: 'Isu migrasi', audit: 'Audit log'
});

var CURRENT_USER_ = null;

function resetCurrentUser_() {
  CURRENT_USER_ = null;
}

/** 'RW', 'R' or null for a role on a module. */
function permissionOf_(role, module) {
  const row = MODULE_PERMISSIONS[module];
  if (!row) throw appError_(ERROR_CODE.INTERNAL, 'Modul tidak dikenal: ' + module);
  return row[role] || null;
}

function can_(user, module, access) {
  const permission = permissionOf_(user.role, module);
  return access === ACCESS.WRITE ? permission === ACCESS.WRITE : permission !== null;
}

function requirePermission_(user, module, access) {
  if (can_(user, module, access)) return;
  const verb = access === ACCESS.WRITE ? 'mengubah' : 'melihat';
  throw appError_(ERROR_CODE.FORBIDDEN, 'Role ' + user.role + ' tidak memiliki akses untuk ' + verb + ' data ' +
    MODULE_LABELS[module] + '.', { module: module, access: access });
}

/** Every module with the caller's access ('RW' / 'R'); modules without access are left out. */
function permissionsFor_(role) {
  const result = {};
  Object.keys(MODULE_PERMISSIONS).forEach(function (module) {
    const permission = permissionOf_(role, module);
    if (permission) result[module] = permission;
  });
  return result;
}

function toSessionUser_(record) {
  return { id: record.id, name: record.name, email: record.email, role: record.role, lastLoginAt: record.last_login_at || null };
}

/** The signed-in user (cached per execution). Throws NOT_REGISTERED for unknown, archived or unidentified accounts. */
function requireUser_() {
  if (CURRENT_USER_) return CURRENT_USER_;
  const email = getActiveUserEmail_();
  if (!email) {
    throw appError_(ERROR_CODE.NOT_REGISTERED,
      'Akun Google Anda tidak dapat dikenali. Buka aplikasi dengan akun Google Workspace PIK.', { email: null });
  }
  const users = loadTable_('USERS').records;
  let record = findUserByEmail_(users, email);
  if (!record && isScriptOwner_(email) && !hasActiveAdmin_(users)) record = registerFirstAdmin_(email);
  if (!record) {
    throw appError_(ERROR_CODE.NOT_REGISTERED,
      'Akun ' + email + ' belum terdaftar di PIK Marketing Control. Hubungi Admin untuk didaftarkan.', { email: email });
  }
  if (record.is_active === false) {
    throw appError_(ERROR_CODE.NOT_REGISTERED, 'Akun ' + email + ' sudah dinonaktifkan. Hubungi Admin.', { email: email });
  }
  CURRENT_USER_ = toSessionUser_(record);
  return CURRENT_USER_;
}

function findUserByEmail_(users, email) {
  for (let i = 0; i < users.length; i++) {
    if (String(users[i].email || '').toLowerCase() === email) return users[i];
  }
  return null;
}

function hasActiveAdmin_(users) {
  return users.some(function (user) { return user.role === ROLE.ADMIN && user.is_active !== false; });
}

function isScriptOwner_(email) {
  return email !== '' && email === getEffectiveUserEmail_();
}

/** First sign-in of the script owner while no active ADMIN exists: registered as ADMIN (audited). */
function registerFirstAdmin_(email) {
  return withScriptLock_(function () {
    resetDbCache_();
    const users = loadTable_('USERS').records;
    const existing = findUserByEmail_(users, email);
    if (existing || hasActiveAdmin_(users)) return existing;
    return dbInsert_('USERS', [{ name: email, email: email, role: ROLE.ADMIN }], {
      actor: email, internal: true, auditNote: 'Admin pertama: pemilik skrip saat USERS belum punya ADMIN aktif.'
    })[0];
  });
}

/** Write context for changes made by a user through the API. */
function userContext_(user, extra) {
  return Object.assign({ actor: user.email }, extra || {});
}
