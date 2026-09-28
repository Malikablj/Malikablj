import * as systemService from '../services/systemService.js';

export async function health(req, res) {
  const result = await systemService.checkHealth();
  const ok = result.status === 'ok';
  res.status(ok ? 200 : 503).json({ success: ok, data: result });
}
