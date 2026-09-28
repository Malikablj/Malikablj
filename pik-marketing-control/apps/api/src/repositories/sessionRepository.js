import { query } from '../db/pool.js';

export async function create({ userId, tokenHash, expiresAt, ipAddress, userAgent }) {
  await query(
    `INSERT INTO user_sessions (user_id, token_hash, expires_at, ip_address, user_agent) VALUES ($1, $2, $3, $4, $5)`,
    [userId, tokenHash, expiresAt, ipAddress, userAgent],
  );
}

/** The session and its user, only when the session is unexpired and the user is active. */
export async function findActive(tokenHash) {
  const { rows } = await query(
    `SELECT s.id AS session_id, s.last_seen_at, u.id, u.name, u.email, u.role
     FROM user_sessions s JOIN users u ON u.id = s.user_id
     WHERE s.token_hash = $1 AND s.expires_at > now() AND u.is_active`,
    [tokenHash],
  );
  return rows[0] ?? null;
}

/** Records activity at most every 5 minutes to avoid a write on every request. */
export async function touch(sessionId) {
  await query(
    `UPDATE user_sessions SET last_seen_at = now() WHERE id = $1 AND last_seen_at < now() - interval '5 minutes'`,
    [sessionId],
  );
}

export async function deleteByTokenHash(tokenHash) {
  await query(`DELETE FROM user_sessions WHERE token_hash = $1`, [tokenHash]);
}

export async function deleteForUser(userId, { exceptTokenHash = null } = {}) {
  await query(`DELETE FROM user_sessions WHERE user_id = $1 AND token_hash IS DISTINCT FROM $2`, [userId, exceptTokenHash]);
}

export async function deleteExpired() {
  await query(`DELETE FROM user_sessions WHERE expires_at <= now()`);
}
