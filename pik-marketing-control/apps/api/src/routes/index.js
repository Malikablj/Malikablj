/** Mounts every API module under /api. */
import { Router } from 'express';
import * as systemController from '../controllers/systemController.js';
import { requireCsrfHeader } from '../middleware/csrf.js';

export function createApiRouter() {
  const router = Router();

  router.get('/health', systemController.health);

  router.use(requireCsrfHeader);

  return router;
}
