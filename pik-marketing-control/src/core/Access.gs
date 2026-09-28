/**
 * Access control for maintenance entry points (setupDatabase, initializeDatabase, verifyDatabase, self-test).
 *
 * Apps Script exposes every top-level function without a trailing underscore to google.script.run, so each
 * public maintenance function calls requireMaintenanceAccess_() first. Allowed callers: the account the code
 * runs as (the script owner / web-app deployer, e.g. when running from the editor) and the emails listed in the
 * ADMIN_EMAILS script property. Application roles from USERS are enforced by the backend phase.
 */

function getActiveUserEmail_() {
  try {
    return String(Session.getActiveUser().getEmail() || '').trim().toLowerCase();
  } catch (error) {
    return '';
  }
}

function getEffectiveUserEmail_() {
  try {
    return String(Session.getEffectiveUser().getEmail() || '').trim().toLowerCase();
  } catch (error) {
    return '';
  }
}

/** Email of the caller for created_by/updated_by and AUDIT_LOG. */
function getActorEmail_() {
  return getActiveUserEmail_() || getEffectiveUserEmail_() || 'system';
}

/** Throws FORBIDDEN unless the caller may run database maintenance. Returns the caller's email. */
function requireMaintenanceAccess_() {
  const active = getActiveUserEmail_();
  if (!active) {
    throw appError_(ERROR_CODE.FORBIDDEN,
      'Identitas pengguna tidak dapat dipastikan. Jalankan fungsi ini dari editor Apps Script dengan akun pemilik.');
  }
  const effective = getEffectiveUserEmail_();
  if (active === effective || getConfig_().ADMIN_EMAILS.indexOf(active) !== -1) return active;
  throw appError_(ERROR_CODE.FORBIDDEN, 'Anda tidak memiliki akses untuk menjalankan fungsi pemeliharaan database.');
}
