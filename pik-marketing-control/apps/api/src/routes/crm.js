import {
  activityCreateSchema,
  activityUpdateSchema,
  followUpCompleteSchema,
  followUpCreateSchema,
  followUpRescheduleSchema,
  followUpUpdateSchema,
  leadCreateSchema,
  leadStatusSchema,
  leadUpdateSchema,
  MODULE,
} from '@pik/shared';
import { Router } from 'express';
import { activities, followUps, leads } from '../controllers/crmController.js';
import { requirePermission } from '../middleware/auth.js';
import { validateBody, validateIdParams, validateQuery } from '../middleware/validate.js';
import { activityListQuery, followUpListQuery, leadListQuery } from '../validators/crmQueries.js';

const id = validateIdParams('id');

export const leadsRouter = Router();
{
  const read = requirePermission(MODULE.LEADS, 'read');
  const write = requirePermission(MODULE.LEADS, 'write');
  leadsRouter.get('/', read, validateQuery(leadListQuery), leads.list);
  leadsRouter.get('/board', read, validateQuery(leadListQuery), leads.board);
  leadsRouter.get('/:id', read, id, leads.get);
  leadsRouter.post('/', write, validateBody(leadCreateSchema), leads.create);
  leadsRouter.put('/:id', write, id, validateBody(leadUpdateSchema), leads.update);
  leadsRouter.patch('/:id/status', write, id, validateBody(leadStatusSchema), leads.changeStatus);
}

export const activitiesRouter = Router();
{
  const read = requirePermission(MODULE.ACTIVITIES, 'read');
  const write = requirePermission(MODULE.ACTIVITIES, 'write');
  activitiesRouter.get('/', read, validateQuery(activityListQuery), activities.list);
  activitiesRouter.get('/:id', read, id, activities.get);
  activitiesRouter.post('/', write, validateBody(activityCreateSchema), activities.create);
  activitiesRouter.put('/:id', write, id, validateBody(activityUpdateSchema), activities.update);
}

export const followUpsRouter = Router();
{
  const read = requirePermission(MODULE.FOLLOW_UPS, 'read');
  const write = requirePermission(MODULE.FOLLOW_UPS, 'write');
  followUpsRouter.get('/', read, validateQuery(followUpListQuery), followUps.list);
  followUpsRouter.get('/summary', read, followUps.summary);
  followUpsRouter.get('/:id', read, id, followUps.get);
  followUpsRouter.post('/', write, validateBody(followUpCreateSchema), followUps.create);
  followUpsRouter.put('/:id', write, id, validateBody(followUpUpdateSchema), followUps.update);
  followUpsRouter.post('/:id/complete', write, id, validateBody(followUpCompleteSchema), followUps.complete);
  followUpsRouter.post('/:id/reschedule', write, id, validateBody(followUpRescheduleSchema), followUps.reschedule);
}
