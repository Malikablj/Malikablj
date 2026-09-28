/** Mounts every API module under /api. */
import { Router } from 'express';
import * as systemController from '../controllers/systemController.js';
import { loadSession, requireAuth } from '../middleware/auth.js';
import { requireCsrfHeader } from '../middleware/csrf.js';
import { authRouter } from './auth.js';
import { activitiesRouter, followUpsRouter, leadsRouter } from './crm.js';
import { contactsRouter, customersRouter } from './customers.js';
import { dashboardRouter, migrationIssuesRouter, notificationsRouter, reportsRouter, searchRouter } from './insights.js';
import {
  deliveriesRouter,
  inboundMaklonRouter,
  invoicesRouter,
  leadTimesRouter,
  poFinancialsRouter,
  poLinesRouter,
  productsRouter,
  purchaseOrdersRouter,
  returnsRouter,
  stockRouter,
} from './operations.js';
import { usersRouter } from './users.js';

export function createApiRouter() {
  const router = Router();

  router.get('/health', systemController.health);

  router.use(requireCsrfHeader);
  router.use(loadSession);
  router.use('/auth', authRouter);

  // Everything below requires a signed-in user; each route checks its module permission.
  router.use(requireAuth);
  router.use('/users', usersRouter);
  router.use('/customers', customersRouter);
  router.use('/contacts', contactsRouter);
  router.use('/leads', leadsRouter);
  router.use('/activities', activitiesRouter);
  router.use('/follow-ups', followUpsRouter);
  router.use('/products', productsRouter);
  router.use('/purchase-orders', purchaseOrdersRouter);
  router.use('/po-lines', poLinesRouter);
  router.use('/deliveries', deliveriesRouter);
  router.use('/returns', returnsRouter);
  router.use('/stock', stockRouter);
  router.use('/lead-times', leadTimesRouter);
  router.use('/inbound-maklon', inboundMaklonRouter);
  router.use('/invoices', invoicesRouter);
  router.use('/po-financials', poFinancialsRouter);
  router.use('/dashboard', dashboardRouter);
  router.use('/reports', reportsRouter);
  router.use('/search', searchRouter);
  router.use('/notifications', notificationsRouter);
  router.use('/migration-issues', migrationIssuesRouter);

  return router;
}
