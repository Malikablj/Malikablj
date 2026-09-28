/** Supertest helpers: a CSRF-aware client and role-based login. */
import request from 'supertest';
import { createApp } from '../../apps/api/src/app.js';
import { insertUser, TEST_PASSWORD } from './factories.js';

export const app = createApp();

/** Wraps a supertest agent (keeps cookies) and adds the CSRF header to mutations. */
export function client(agent = request.agent(app)) {
  const withCsrf = (req) => req.set('X-Requested-With', 'XMLHttpRequest');
  return {
    agent,
    get: (url) => agent.get(url),
    post: (url, body) => withCsrf(agent.post(url)).send(body ?? {}),
    put: (url, body) => withCsrf(agent.put(url)).send(body ?? {}),
    patch: (url, body) => withCsrf(agent.patch(url)).send(body ?? {}),
    delete: (url) => withCsrf(agent.delete(url)),
  };
}

/** Creates a user with the given role and returns a logged-in client for it. */
export async function loginAs(role, overrides = {}) {
  const user = await insertUser({ role, ...overrides });
  const api = client();
  const res = await api.post('/api/auth/login', { email: user.email, password: TEST_PASSWORD });
  if (res.status !== 200) throw new Error(`Login as ${role} failed: ${res.status} ${JSON.stringify(res.body)}`);
  return { ...api, user };
}

/** Logged-in clients for every role. */
export async function loginAllRoles() {
  const clients = {};
  // Sequential: the test database helper uses a single pg client.
  for (const role of ['ADMIN', 'MARKETING', 'SALES', 'MANAGEMENT', 'VIEWER']) {
    clients[role.toLowerCase()] = await loginAs(role);
  }
  return clients;
}
