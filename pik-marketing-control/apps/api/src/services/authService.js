/**
 * Login, logout and session resolution.
 * The browser holds a random 256-bit token; the database stores only HMAC-SHA256(token), so a
 * leaked sessions table cannot be replayed.
 */
import { createHmac, randomBytes } from 'node:crypto';
import config, { sessionSecret } from '../config/index.js';
import * as sessionRepository from '../repositories/sessionRepository.js';
import * as userRepository from '../repositories/userRepository.js';
import { AppError, tooManyRequests, validationError } from '../utils/errors.js';
import { dummyPasswordHash, hashPassword, verifyPassword } from '../utils/password.js';
import { createRateLimiter } from '../utils/rateLimiter.js';

const WINDOW_MS = 15 * 60 * 1000;
const perAccount = createRateLimiter({ limit: 10, windowMs: WINDOW_MS });
const perAddress = createRateLimiter({ limit: 100, windowMs: WINDOW_MS });

export function hashToken(token) {
  return createHmac('sha256', sessionSecret()).update(token).digest('hex');
}

export function publicUser(user) {
  return { id: user.id, name: user.name, email: user.email, role: user.role };
}

function assertNotLimited(accountKey, address) {
  const wait = Math.max(perAccount.retryAfter(accountKey), perAddress.retryAfter(address));
  if (wait > 0) {
    throw tooManyRequests(`Terlalu banyak percobaan login. Coba lagi dalam ${Math.ceil(wait / 60000)} menit.`);
  }
}

/** @returns {Promise<{ user: object, token: string, expiresAt: Date }>} */
export async function login({ email, password, ipAddress, userAgent }) {
  const accountKey = `${email}|${ipAddress}`;
  assertNotLimited(accountKey, ipAddress);

  const user = await userRepository.findByEmailWithHash(email);
  // Verify against a dummy hash for unknown emails so response time does not reveal accounts.
  const valid = await verifyPassword(password, user?.password_hash ?? (await dummyPasswordHash()));
  if (!user || !valid) {
    perAccount.fail(accountKey);
    perAddress.fail(ipAddress);
    throw new AppError(401, 'INVALID_CREDENTIALS', 'Email atau password salah.');
  }
  if (!user.is_active) {
    throw new AppError(403, 'ACCOUNT_INACTIVE', 'Akun Anda tidak aktif. Hubungi Admin.');
  }
  perAccount.reset(accountKey);

  const token = randomBytes(32).toString('base64url');
  const expiresAt = new Date(Date.now() + config.sessionTtlHours * 3600 * 1000);
  await sessionRepository.deleteExpired();
  await sessionRepository.create({
    userId: user.id,
    tokenHash: hashToken(token),
    expiresAt,
    ipAddress: ipAddress?.slice(0, 64) ?? null,
    userAgent: userAgent?.slice(0, 500) ?? null,
  });
  await userRepository.touchLastLogin(user.id);
  return { user: publicUser(user), token, expiresAt };
}

/** The signed-in user for a session token, or null. */
export async function resolveSession(token) {
  if (!token || typeof token !== 'string' || token.length > 200) return null;
  const session = await sessionRepository.findActive(hashToken(token));
  if (!session) return null;
  await sessionRepository.touch(session.session_id);
  return { ...publicUser(session), sessionId: session.session_id };
}

export async function logout(token) {
  if (token && typeof token === 'string' && token.length <= 200) {
    await sessionRepository.deleteByTokenHash(hashToken(token));
  }
}

/** Changes the caller's password and signs out their other sessions. */
export async function changePassword(userId, { current_password: currentPassword, new_password: newPassword }, token) {
  const user = await userRepository.findByIdWithHash(userId);
  if (!user || !(await verifyPassword(currentPassword, user.password_hash))) {
    throw validationError('Password lama tidak sesuai.', { fields: { current_password: 'Password lama tidak sesuai.' } });
  }
  await userRepository.updatePassword(userId, await hashPassword(newPassword), userId);
  await sessionRepository.deleteForUser(userId, { exceptTokenHash: token ? hashToken(token) : null });
}

/** Test hook: clears login rate-limit counters. */
export function resetLoginRateLimits() {
  perAccount.clear();
  perAddress.clear();
}
