/**
 * Email + password sign-in, for deployments where Google does not tell the app who the visitor is — a web app on a
 * regular Gmail account ("Execute as: Me", access "Anyone"). Accounts whose Google identity the app can see (Google
 * Workspace, or the script owner) can keep signing in with Google (Auth.gs). Both end in the same USERS record, and
 * the role is always read from USERS.
 *
 * Passwords: PBKDF2-HMAC-SHA256 (Crypto.gs) with a 16-byte random salt, stored in USERS.password_hash as
 *   pbkdf2_sha256$<iterations>$<salt, base64url>$<hash, base64url>
 * password_hash is a sensitive column: masked in AUDIT_LOG and never sent to the browser. The iteration count travels
 * with each hash, so PASSWORD_HASH_ITERATIONS can change later without invalidating existing passwords.
 *
 * Sessions: stateless tokens  v1.<user id>.<issued ms>.<expires ms>.<nonce>.<signature>  where the signature is
 * HMAC-SHA256 with a random secret kept in the Script Property AUTH_TOKEN_SECRET. A token is accepted only with a
 * valid signature, before it expires, for an active user, and if it was issued after the user's last password change —
 * so changing or resetting a password ends every other session.
 *
 * Failed sign-ins are counted per email in the script cache; after LOGIN_MAX_FAILURES the email is locked for
 * LOGIN_LOCK_MINUTES. The message never reveals whether an email is registered.
 */

const PASSWORD_HASH_ITERATIONS = 100000;
const PASSWORD_MIN_LENGTH = 8;
const PASSWORD_MAX_LENGTH = 128;
const SESSION_TOKEN_DAYS = 30;
const LOGIN_MAX_FAILURES = 5;
const LOGIN_LOCK_MINUTES = 15;
const AUTH_SECRET_PROPERTY = 'AUTH_TOKEN_SECRET';
const LOGIN_FAILED_MESSAGE = 'Email atau password salah.';
/** Letters and digits that cannot be confused when read aloud or copied by hand (no 0/o, 1/l/i). */
const TEMPORARY_PASSWORD_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

// ---------------------------------------------------------------------------------------------------------------
// Password hashing and policy
// ---------------------------------------------------------------------------------------------------------------

function hashPassword_(password) {
  const salt = randomBytes_(16);
  const hash = pbkdf2Sha256Bytes_(utf8Bytes_(password), salt, PASSWORD_HASH_ITERATIONS, 32);
  return ['pbkdf2_sha256', PASSWORD_HASH_ITERATIONS, bytesToBase64Url_(salt), bytesToBase64Url_(hash)].join('$');
}

/**
 * True when `password` matches the stored hash. Accounts without a password are checked against a dummy hash, so a
 * sign-in takes the same time whether or not the email exists.
 */
function verifyPasswordHash_(password, stored) {
  const hash = typeof stored === 'string' && stored !== '' ? stored : dummyPasswordHash_();
  const parts = hash.split('$');
  const iterations = Number(parts[1]);
  const salt = base64UrlToBytes_(parts[2] || '');
  if (parts.length !== 4 || parts[0] !== 'pbkdf2_sha256' || !(iterations >= 1000 && iterations <= 1000000) || !salt || !parts[3]) {
    return false;
  }
  const derived = pbkdf2Sha256Bytes_(utf8Bytes_(String(password)), salt, Math.floor(iterations), 32);
  return constantTimeEqual_(bytesToBase64Url_(derived), parts[3]) && hash === stored;
}

function dummyPasswordHash_() {
  return ['pbkdf2_sha256', PASSWORD_HASH_ITERATIONS, new Array(23).join('A'), new Array(44).join('A')].join('$');
}

/** Error message for a new password that breaks the policy, or null. */
function passwordPolicyError_(password, email) {
  if (typeof password !== 'string' || password === '') return 'Password wajib diisi.';
  if (password.length < PASSWORD_MIN_LENGTH) return 'Password minimal ' + PASSWORD_MIN_LENGTH + ' karakter.';
  if (password.length > PASSWORD_MAX_LENGTH) return 'Password maksimal ' + PASSWORD_MAX_LENGTH + ' karakter.';
  if (!/[A-Za-z]/.test(password) || !/[0-9]/.test(password)) return 'Password harus berisi huruf dan angka.';
  if (email && password.toLowerCase() === String(email).toLowerCase()) return 'Password tidak boleh sama dengan email.';
  return null;
}

function requireValidNewPassword_(password, email, field) {
  const message = passwordPolicyError_(password, email);
  if (message) throw appError_(ERROR_CODE.VALIDATION, message, { errors: [{ field: field, code: 'RULE', message: message }] });
}

/** Temporary password such as 'k7mq-2hxa-9rtd': 12 random characters without look-alikes, letters and digits. */
function generateTemporaryPassword_() {
  for (;;) {
    const characters = [];
    randomBytes_(64).forEach(function (byte) {
      // 248 = 8 × 31: dropping the bytes above it keeps every character equally likely.
      if (characters.length < 12 && byte < 248) characters.push(TEMPORARY_PASSWORD_ALPHABET.charAt(byte % TEMPORARY_PASSWORD_ALPHABET.length));
    });
    if (characters.length < 12) continue;
    const password = [characters.slice(0, 4).join(''), characters.slice(4, 8).join(''), characters.slice(8, 12).join('')].join('-');
    if (!passwordPolicyError_(password, null)) return password;
  }
}

// ---------------------------------------------------------------------------------------------------------------
// Session tokens
// ---------------------------------------------------------------------------------------------------------------

/** The signing secret, created once (under the script lock, so two first sign-ins cannot create two secrets). */
function authSecret_() {
  const properties = PropertiesService.getScriptProperties();
  const existing = properties.getProperty(AUTH_SECRET_PROPERTY);
  if (existing) return existing;
  return withScriptLock_(function () {
    const again = properties.getProperty(AUTH_SECRET_PROPERTY);
    if (again) return again;
    const secret = bytesToBase64Url_(randomBytes_(32));
    properties.setProperty(AUTH_SECRET_PROPERTY, secret);
    return secret;
  });
}

function signSessionPayload_(payload) {
  return bytesToBase64Url_(hmacSha256Bytes_(utf8Bytes_(authSecret_()), utf8Bytes_(payload)));
}

function issueSessionToken_(record) {
  const issuedAt = currentDate_().getTime();
  const expiresAt = issuedAt + SESSION_TOKEN_DAYS * 24 * 60 * 60 * 1000;
  const payload = ['v1', record.id, issuedAt, expiresAt, bytesToBase64Url_(randomBytes_(9))].join('.');
  return { token: payload + '.' + signSessionPayload_(payload), expiresAt: new Date(expiresAt).toISOString() };
}

function sessionEndedError_() {
  return appError_(ERROR_CODE.AUTH_REQUIRED, 'Sesi Anda sudah berakhir. Silakan masuk lagi.', { reason: 'SESSION_ENDED' });
}

/** The USERS record a session token belongs to; AUTH_REQUIRED when the token is not (or no longer) valid. */
function userFromSessionToken_(token, users) {
  const parts = String(token).split('.');
  if (parts.length !== 6 || parts[0] !== 'v1') throw sessionEndedError_();
  if (!constantTimeEqual_(signSessionPayload_(parts.slice(0, 5).join('.')), parts[5])) throw sessionEndedError_();
  const issuedAt = Number(parts[2]);
  const expiresAt = Number(parts[3]);
  if (!(issuedAt > 0) || !(expiresAt > currentDate_().getTime())) throw sessionEndedError_();
  const record = users.filter(function (user) { return user.id === parts[1]; })[0];
  if (!record) throw sessionEndedError_();
  if (record.is_active === false) {
    throw appError_(ERROR_CODE.NOT_REGISTERED, 'Akun ' + record.email + ' sudah dinonaktifkan. Hubungi Admin.', { email: record.email });
  }
  const changedAt = record.password_changed_at ? new Date(record.password_changed_at).getTime() : 0;
  if (issuedAt < changedAt) throw sessionEndedError_();
  return record;
}

// ---------------------------------------------------------------------------------------------------------------
// Failed sign-in limit (script cache; if the cache is unavailable the limit is skipped, sign-in still works)
// ---------------------------------------------------------------------------------------------------------------

function loginAttemptKey_(email) {
  return 'login-attempts:' + bytesToBase64Url_(sha256Bytes_(utf8Bytes_(email)));
}

function loginAttempts_(email) {
  try {
    const raw = CacheService.getScriptCache().get(loginAttemptKey_(email));
    const state = raw ? JSON.parse(raw) : null;
    return state && typeof state.failures === 'number' ? state : { failures: 0, lockedUntil: 0 };
  } catch (error) {
    return { failures: 0, lockedUntil: 0 };
  }
}

function requireLoginAllowed_(email) {
  const state = loginAttempts_(email);
  const now = currentDate_().getTime();
  if (state.lockedUntil > now) {
    const minutes = Math.ceil((state.lockedUntil - now) / 60000);
    throw appError_(ERROR_CODE.TOO_MANY_ATTEMPTS, 'Terlalu banyak percobaan masuk yang gagal. Coba lagi dalam ' + minutes + ' menit.',
      { retryAfterMinutes: minutes });
  }
}

function recordLoginFailure_(email) {
  const state = loginAttempts_(email);
  const now = currentDate_().getTime();
  const failures = (state.lockedUntil && state.lockedUntil <= now ? 0 : state.failures) + 1;
  const next = { failures: failures, lockedUntil: failures >= LOGIN_MAX_FAILURES ? now + LOGIN_LOCK_MINUTES * 60000 : 0 };
  try {
    CacheService.getScriptCache().put(loginAttemptKey_(email), JSON.stringify(next), 6 * 60 * 60);
  } catch (error) {
    console.warn('Batas percobaan login tidak dapat dicatat: ' + error);
  }
}

function clearLoginFailures_(email) {
  try {
    CacheService.getScriptCache().remove(loginAttemptKey_(email));
  } catch (error) {
    console.warn('Batas percobaan login tidak dapat dihapus: ' + error);
  }
}

// ---------------------------------------------------------------------------------------------------------------
// API handlers
// ---------------------------------------------------------------------------------------------------------------

/**
 * auth.login (public): { email, password } → { token, expiresAt, session }. Records last_login_at (at most every
 * 10 minutes). A correct password for a deactivated account says so; everything else answers LOGIN_FAILED_MESSAGE.
 */
function loginWithPassword_(input) {
  const params = objectInput_(input);
  const email = String(params.email || '').trim().toLowerCase();
  const password = typeof params.password === 'string' ? params.password : '';
  const missing = [];
  if (!email) missing.push({ field: 'email', code: 'REQUIRED', message: 'Email wajib diisi.' });
  if (!password) missing.push({ field: 'password', code: 'REQUIRED', message: 'Password wajib diisi.' });
  if (missing.length > 0) throw appError_(ERROR_CODE.VALIDATION, 'Email dan password wajib diisi.', { errors: missing });
  requireLoginAllowed_(email);
  const record = findUserByEmail_(loadTable_('USERS').records, email);
  const stored = record ? record.password_hash : null;
  if (!verifyPasswordHash_(password, stored) || !record) {
    recordLoginFailure_(email);
    throw appError_(ERROR_CODE.VALIDATION, LOGIN_FAILED_MESSAGE, { errors: [] });
  }
  if (record.is_active === false) {
    throw appError_(ERROR_CODE.NOT_REGISTERED, 'Akun ' + record.email + ' sudah dinonaktifkan. Hubungi Admin.', { email: record.email });
  }
  clearLoginFailures_(email);
  let current = record;
  const last = record.last_login_at ? new Date(record.last_login_at).getTime() : 0;
  if (!last || currentDate_().getTime() - last > LOGIN_RECORD_INTERVAL_MS) {
    current = dbUpdate_('USERS', record.id, { last_login_at: nowIso_() }, { actor: record.email, internal: true });
  }
  const session = issueSessionToken_(current);
  const user = toSessionUser_(current, 'password');
  return { token: session.token, expiresAt: session.expiresAt, session: getSession_({}, user) };
}

/**
 * auth.changePassword: { currentPassword, newPassword } → { token, expiresAt, user }. Ends every other session of the
 * account (a new token is returned for this one). A Google-identity user without a password may set one without
 * `currentPassword`.
 */
function changeOwnPassword_(input, user) {
  const params = objectInput_(input);
  const newPassword = params.newPassword;
  const record = findOrThrow_('USERS', user.id);
  if (record.password_hash) {
    requireLoginAllowed_(record.email);
    if (!verifyPasswordHash_(typeof params.currentPassword === 'string' ? params.currentPassword : '', record.password_hash)) {
      recordLoginFailure_(record.email);
      throw appError_(ERROR_CODE.VALIDATION, 'Password saat ini salah.',
        { errors: [{ field: 'currentPassword', code: 'RULE', message: 'Password saat ini salah.' }] });
    }
  }
  requireValidNewPassword_(newPassword, record.email, 'newPassword');
  if (record.password_hash && params.currentPassword === newPassword) {
    throw appError_(ERROR_CODE.VALIDATION, 'Password baru harus berbeda dari password saat ini.',
      { errors: [{ field: 'newPassword', code: 'RULE', message: 'Harus berbeda dari password saat ini.' }] });
  }
  clearLoginFailures_(record.email);
  const updated = dbUpdate_('USERS', user.id, {
    password_hash: hashPassword_(newPassword), password_changed_at: nowIso_(), must_change_password: false
  }, { actor: user.email, internal: true, auditNote: 'Password diganti oleh pemilik akun.' });
  const session = issueSessionToken_(updated);
  CURRENT_USER_ = toSessionUser_(updated, 'password');
  return { token: session.token, expiresAt: session.expiresAt, user: CURRENT_USER_ };
}

/**
 * users.setPassword (Admin): { id, password, mustChange (default true) }. Ends the user's sessions and clears their
 * failed-sign-in lock. Admins change their own password in the profile instead (it needs the current password).
 */
function setUserPassword_(input, user) {
  const params = objectInput_(input);
  const id = requireId_(params.id);
  if (id === user.id) {
    throw appError_(ERROR_CODE.VALIDATION, 'Ganti password akun Anda sendiri lewat Pengaturan › Profil.',
      { errors: [{ field: 'password', code: 'RULE', message: 'Gunakan Pengaturan › Profil untuk akun sendiri.' }] });
  }
  const target = findOrThrow_('USERS', id);
  requireValidNewPassword_(params.password, target.email, 'password');
  const updated = dbUpdate_('USERS', id, {
    password_hash: hashPassword_(params.password), password_changed_at: nowIso_(), must_change_password: params.mustChange !== false
  }, userContext_(user, { internal: true, auditNote: 'Password diatur oleh Admin.' }));
  clearLoginFailures_(target.email);
  return userForList_(updated);
}

// ---------------------------------------------------------------------------------------------------------------
// Editor function
// ---------------------------------------------------------------------------------------------------------------

/**
 * Run from the Apps Script editor by the script owner (or an ADMIN_EMAILS account): makes that account an active
 * Admin with a new temporary password, written to the execution log. For the first setup on a regular Gmail account
 * and for recovery when the password is lost. The password must be changed at the next sign-in.
 */
function setupAdminAccount() {
  const email = requireMaintenanceAccess_();
  const temporaryPassword = generateTemporaryPassword_();
  const created = withScriptLock_(function () {
    const existing = findUserByEmail_(loadTable_('USERS').records, email);
    const ctx = { actor: email, internal: true, auditNote: 'setupAdminAccount: akses Admin untuk pemilik skrip.' };
    const credentials = { password_hash: hashPassword_(temporaryPassword), password_changed_at: nowIso_(), must_change_password: true };
    if (!existing) {
      dbInsert_('USERS', [Object.assign({ name: email, email: email, role: ROLE.ADMIN }, credentials)], ctx);
      return true;
    }
    if (existing.is_active === false) setRecordActive_('USERS', existing.id, true, ctx);
    dbUpdate_('USERS', existing.id, Object.assign({ role: ROLE.ADMIN }, credentials), ctx);
    return false;
  });
  clearLoginFailures_(email);
  Logger.log('setupAdminAccount: akun Admin ' + (created ? 'dibuat' : 'diperbarui') + '.');
  Logger.log('Email             : ' + email);
  Logger.log('Password sementara: ' + temporaryPassword);
  Logger.log('Buka web app, masuk dengan email dan password sementara ini, lalu buat password baru saat diminta.');
  return { email: email, created: created, mustChangePassword: true };
}
