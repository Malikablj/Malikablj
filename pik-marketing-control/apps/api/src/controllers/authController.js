import config from '../config/index.js';
import { SESSION_COOKIE, sessionCookieOptions } from '../middleware/auth.js';
import * as authService from '../services/authService.js';
import { businessToday } from '../utils/dates.js';
import { sendOk } from '../utils/http.js';

const sessionMeta = () => ({ timezone: config.appTimezone, today: businessToday() });

export async function login(req, res) {
  const { user, token, expiresAt } = await authService.login({
    email: req.body.email,
    password: req.body.password,
    ipAddress: req.ip,
    userAgent: req.get('User-Agent'),
  });
  res.cookie(SESSION_COOKIE, token, sessionCookieOptions(expiresAt));
  sendOk(res, { user }, sessionMeta());
}

export async function logout(req, res) {
  await authService.logout(req.cookies?.[SESSION_COOKIE]);
  res.clearCookie(SESSION_COOKIE, sessionCookieOptions());
  sendOk(res, null);
}

/** Session check for the web app: always 200, with the user or null (no 401 noise on the login page). */
export async function session(req, res) {
  if (!req.user) {
    sendOk(res, { user: null }, sessionMeta());
    return;
  }
  const { sessionId, ...user } = req.user;
  sendOk(res, { user }, sessionMeta());
}

export async function me(req, res) {
  const { sessionId, ...user } = req.user;
  sendOk(res, { user }, sessionMeta());
}

export async function changePassword(req, res) {
  await authService.changePassword(req.user.id, req.body, req.cookies?.[SESSION_COOKIE]);
  sendOk(res, null);
}
