/** Authentication, sessions, authorization and user administration. */
import request from 'supertest';
import { afterAll, beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { closePool } from '../../apps/api/src/db/pool.js';
import { resetLoginRateLimits } from '../../apps/api/src/services/authService.js';
import { app, client, loginAllRoles, loginAs } from '../helpers/api.js';
import { closeDb, sql, truncateAll } from '../helpers/db.js';
import { insertUser, TEST_PASSWORD } from '../helpers/factories.js';

beforeAll(truncateAll);
beforeEach(() => resetLoginRateLimits());
afterAll(async () => {
  await closePool();
  await closeDb();
});

describe('login', () => {
  it('signs in with valid credentials and sets a secure session cookie', async () => {
    const user = await insertUser({ role: 'SALES', email: 'Sales.Valid@test.local' });
    const res = await client().post('/api/auth/login', { email: 'sales.valid@TEST.local', password: TEST_PASSWORD });
    expect(res.status).toBe(200);
    expect(res.body.data.user).toEqual({ id: user.id, name: user.name, email: user.email, role: 'SALES' });
    expect(res.body.data.user.password_hash).toBeUndefined();
    expect(res.body.meta.timezone).toBe('Asia/Jakarta');
    const cookie = res.headers['set-cookie'][0];
    expect(cookie).toMatch(/^pik_session=[\w-]{43};/);
    expect(cookie).toContain('HttpOnly');
    expect(cookie).toContain('SameSite=Lax');
    // Only an HMAC of the token is stored.
    const token = /pik_session=([^;]+)/.exec(cookie)[1];
    const stored = await sql('SELECT token_hash FROM user_sessions WHERE user_id = $1', [user.id]);
    expect(stored.rows[0].token_hash).toHaveLength(64);
    expect(stored.rows[0].token_hash).not.toContain(token);
  });

  it('rejects a wrong password and an unknown email with the same message', async () => {
    await insertUser({ email: 'known@test.local' });
    const wrong = await client().post('/api/auth/login', { email: 'known@test.local', password: 'not-the-password' });
    const unknown = await client().post('/api/auth/login', { email: 'nobody@test.local', password: 'whatever-123' });
    for (const res of [wrong, unknown]) {
      expect(res.status).toBe(401);
      expect(res.body.error).toEqual({ code: 'INVALID_CREDENTIALS', message: 'Email atau password salah.' });
    }
  });

  it('rejects inactive users', async () => {
    const user = await insertUser({ isActive: false });
    const res = await client().post('/api/auth/login', { email: user.email, password: TEST_PASSWORD });
    expect(res.status).toBe(403);
    expect(res.body.error.code).toBe('ACCOUNT_INACTIVE');
  });

  it('validates the request body', async () => {
    const res = await client().post('/api/auth/login', { email: 'not-an-email' });
    expect(res.status).toBe(400);
    expect(res.body.error.code).toBe('VALIDATION_ERROR');
    expect(res.body.error.details.fields).toMatchObject({ email: 'Format email tidak valid.', password: 'Password wajib diisi.' });
  });

  it('requires the CSRF header', async () => {
    const res = await request(app).post('/api/auth/login').send({ email: 'a@test.local', password: 'x' });
    expect(res.status).toBe(403);
    expect(res.body.error.code).toBe('CSRF_REJECTED');
  });

  it('rate-limits repeated failures for the same account', async () => {
    await insertUser({ email: 'target@test.local' });
    const api = client();
    for (let i = 0; i < 10; i += 1) {
      expect((await api.post('/api/auth/login', { email: 'target@test.local', password: 'wrong-password' })).status).toBe(401);
    }
    const blocked = await api.post('/api/auth/login', { email: 'target@test.local', password: TEST_PASSWORD });
    expect(blocked.status).toBe(429);
    expect(blocked.body.error.code).toBe('TOO_MANY_REQUESTS');
  });
});

describe('session', () => {
  it('returns the current user and ends the session on logout', async () => {
    const sales = await loginAs('SALES');
    const me = await sales.get('/api/auth/me');
    expect(me.status).toBe(200);
    expect(me.body.data.user).toMatchObject({ id: sales.user.id, role: 'SALES' });
    expect(me.body.meta.today).toMatch(/^\d{4}-\d{2}-\d{2}$/);

    const logout = await sales.post('/api/auth/logout');
    expect(logout.status).toBe(200);
    expect((await sales.get('/api/auth/me')).status).toBe(401);
  });

  it('rejects requests without a session, with a forged token, or with an expired session', async () => {
    expect((await request(app).get('/api/auth/me')).status).toBe(401);
    const forged = await request(app).get('/api/auth/me').set('Cookie', 'pik_session=forged-token-value');
    expect(forged.status).toBe(401);

    const viewer = await loginAs('VIEWER');
    await sql(`UPDATE user_sessions SET expires_at = now() - interval '1 minute' WHERE user_id = $1`, [viewer.user.id]);
    expect((await viewer.get('/api/auth/me')).status).toBe(401);
  });

  it('answers unknown routes with 404 for signed-in users', async () => {
    const viewer = await loginAs('VIEWER');
    const res = await viewer.get('/api/does-not-exist');
    expect(res.status).toBe(404);
    expect(res.body.error.code).toBe('NOT_FOUND');
  });

  it('changes the password, keeps the current session and ends the others', async () => {
    const first = await loginAs('MARKETING');
    const second = client();
    await second.post('/api/auth/login', { email: first.user.email, password: TEST_PASSWORD });

    const wrong = await first.post('/api/auth/change-password', { current_password: 'wrong', new_password: 'new-password-456' });
    expect(wrong.status).toBe(400);

    const ok = await first.post('/api/auth/change-password', { current_password: TEST_PASSWORD, new_password: 'new-password-456' });
    expect(ok.status).toBe(200);
    expect((await first.get('/api/auth/me')).status).toBe(200);
    expect((await second.get('/api/auth/me')).status).toBe(401);

    const oldLogin = await client().post('/api/auth/login', { email: first.user.email, password: TEST_PASSWORD });
    expect(oldLogin.status).toBe(401);
    const newLogin = await client().post('/api/auth/login', { email: first.user.email, password: 'new-password-456' });
    expect(newLogin.status).toBe(200);
  });
});

describe('user administration', () => {
  let roles;
  beforeAll(async () => {
    roles = await loginAllRoles();
  });

  it('is limited to Admin', async () => {
    for (const role of ['marketing', 'sales', 'management', 'viewer']) {
      expect((await roles[role].get('/api/users')).status, role).toBe(403);
      expect((await roles[role].post('/api/users', {})).status, role).toBe(403);
    }
    expect((await roles.admin.get('/api/users')).status).toBe(200);
  });

  it('lets every signed-in user read the owner picker list', async () => {
    const res = await roles.viewer.get('/api/users/options');
    expect(res.status).toBe(200);
    expect(res.body.data.length).toBeGreaterThan(0);
    expect(Object.keys(res.body.data[0]).sort()).toEqual(['id', 'name', 'role']);
  });

  it('creates users with a hashed password and rejects duplicate emails', async () => {
    const body = { name: 'Rina Marketing', email: 'rina@test.local', role: 'MARKETING', password: 'strong-password-1' };
    const created = await roles.admin.post('/api/users', body);
    expect(created.status).toBe(201);
    expect(created.body.data).toMatchObject({ name: 'Rina Marketing', email: 'rina@test.local', role: 'MARKETING', is_active: true });
    expect(created.body.data.password_hash).toBeUndefined();
    const stored = await sql(`SELECT password_hash FROM users WHERE email = 'rina@test.local'`);
    expect(stored.rows[0].password_hash).toMatch(/^scrypt\$/);

    const duplicate = await roles.admin.post('/api/users', { ...body, email: 'RINA@test.local' });
    expect(duplicate.status).toBe(409);
    expect(duplicate.body.error.message).toBe('Email sudah digunakan oleh user lain.');

    const weak = await roles.admin.post('/api/users', { ...body, email: 'weak@test.local', password: 'short' });
    expect(weak.status).toBe(400);
    expect(weak.body.error.details.fields.password).toContain('minimal 10 karakter');
  });

  it('prevents an admin from deactivating or demoting themselves', async () => {
    const self = roles.admin.user.id;
    expect((await roles.admin.put(`/api/users/${self}`, { is_active: false })).status).toBe(422);
    expect((await roles.admin.put(`/api/users/${self}`, { role: 'VIEWER' })).status).toBe(422);
  });

  it('ends the sessions of a user who is deactivated or whose role changes', async () => {
    const sales = await loginAs('SALES');
    expect((await sales.get('/api/auth/me')).status).toBe(200);
    const res = await roles.admin.put(`/api/users/${sales.user.id}`, { is_active: false });
    expect(res.status).toBe(200);
    expect(res.body.data.is_active).toBe(false);
    expect((await sales.get('/api/auth/me')).status).toBe(401);

    const management = await loginAs('MANAGEMENT');
    await roles.admin.put(`/api/users/${management.user.id}`, { role: 'VIEWER' });
    expect((await management.get('/api/auth/me')).status).toBe(401);
  });

  it('resets a password and signs the user out', async () => {
    const viewer = await loginAs('VIEWER');
    const res = await roles.admin.post(`/api/users/${viewer.user.id}/reset-password`, { password: 'reset-password-789' });
    expect(res.status).toBe(200);
    expect((await viewer.get('/api/auth/me')).status).toBe(401);
    const login = await client().post('/api/auth/login', { email: viewer.user.email, password: 'reset-password-789' });
    expect(login.status).toBe(200);
  });

  it('filters and pages the user list', async () => {
    const res = await roles.admin.get('/api/users?role=ADMIN&page_size=1&sort=name');
    expect(res.status).toBe(200);
    expect(res.body.data).toHaveLength(1);
    expect(res.body.data[0].role).toBe('ADMIN');
    expect(res.body.meta).toMatchObject({ page: 1, page_size: 1 });
    expect((await roles.admin.get('/api/users?role=KING')).status).toBe(400);
    expect((await roles.admin.get('/api/users/not-a-uuid')).status).toBe(404);
  });
});
