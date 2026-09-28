import request from 'supertest';
import { afterAll, describe, expect, it } from 'vitest';
import { createApp } from '../../apps/api/src/app.js';
import { closePool } from '../../apps/api/src/db/pool.js';

const app = createApp();

afterAll(closePool);

describe('GET /api/health', () => {
  it('reports the API and database as healthy with the baseline schema', async () => {
    const res = await request(app).get('/api/health');
    expect(res.status).toBe(200);
    expect(res.body.success).toBe(true);
    expect(res.body.data).toMatchObject({ status: 'ok', database: 'ok', schema_version: '0000_baseline' });
    expect(res.body.data.today).toMatch(/^\d{4}-\d{2}-\d{2}$/);
  });

  it('does not reveal API routes to anonymous callers', async () => {
    const res = await request(app).get('/api/does-not-exist');
    expect(res.status).toBe(401);
    expect(res.body).toEqual({ success: false, error: { code: 'UNAUTHORIZED', message: 'Silakan login terlebih dahulu.' } });
  });

  it('rejects state-changing requests without the CSRF header', async () => {
    const res = await request(app).post('/api/anything').send({});
    expect(res.status).toBe(403);
    expect(res.body.error.code).toBe('CSRF_REJECTED');
  });
});
