/**
 * Authentication and authorization middleware. The user's role always comes from the
 * database session, never from the request.
 */
import { canRead, canWrite } from '@pik/shared';
import config from '../config/index.js';
import * as authService from '../services/authService.js';
import { forbidden, unauthorized } from '../utils/errors.js';

export const SESSION_COOKIE = 'pik_session';

export function sessionCookieOptions(expiresAt) {
  return {
    httpOnly: true,
    sameSite: 'lax',
    secure: config.cookieSecure,
    path: '/',
    ...(expiresAt ? { expires: expiresAt } : {}),
  };
}

/** Attaches req.user when the request carries a valid session cookie. */
export async function loadSession(req, res, next) {
  const token = req.cookies?.[SESSION_COOKIE];
  if (token) {
    req.user = await authService.resolveSession(token);
    if (!req.user) res.clearCookie(SESSION_COOKIE, sessionCookieOptions());
  }
  next();
}

export function requireAuth(req, res, next) {
  if (!req.user) throw unauthorized();
  next();
}

/** @param {string} module MODULE value from @pik/shared  @param {'read'|'write'} action */
export function requirePermission(module, action = 'read') {
  return (req, res, next) => {
    if (!req.user) throw unauthorized();
    const allowed = action === 'write' ? canWrite(req.user.role, module) : canRead(req.user.role, module);
    if (!allowed) throw forbidden();
    next();
  };
}
