import { migrationIssueUpdateSchema, MODULE } from '@pik/shared';
import { Router } from 'express';
import * as insightController from '../controllers/insightController.js';
import { requirePermission } from '../middleware/auth.js';
import { validateBody, validateIdParams, validateQuery } from '../middleware/validate.js';
import { migrationIssueListQuery, reportQuery, searchQuery } from '../validators/insightQueries.js';

export const dashboardRouter = Router();
dashboardRouter.get('/summary', requirePermission(MODULE.DASHBOARD, 'read'), insightController.dashboard);

// Each report additionally checks read access to its own module (e.g. deliveries).
export const reportsRouter = Router();
reportsRouter.get('/', requirePermission(MODULE.REPORTS, 'read'), insightController.reportCatalog);
reportsRouter.get('/:type', requirePermission(MODULE.REPORTS, 'read'), validateQuery(reportQuery), insightController.report);

export const searchRouter = Router();
searchRouter.get('/', validateQuery(searchQuery), insightController.search);

export const notificationsRouter = Router();
notificationsRouter.get('/', insightController.notifications);

export const migrationIssuesRouter = Router();
migrationIssuesRouter.get('/', requirePermission(MODULE.MIGRATION, 'read'), validateQuery(migrationIssueListQuery), insightController.listIssues);
migrationIssuesRouter.get('/summary', requirePermission(MODULE.MIGRATION, 'read'), insightController.issueSummary);
migrationIssuesRouter.put(
  '/:id',
  requirePermission(MODULE.MIGRATION, 'write'),
  validateIdParams('id'),
  validateBody(migrationIssueUpdateSchema),
  insightController.resolveIssue,
);
