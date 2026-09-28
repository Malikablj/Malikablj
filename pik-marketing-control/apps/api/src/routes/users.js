import { MODULE, userCreateSchema, userResetPasswordSchema, userUpdateSchema } from '@pik/shared';
import { Router } from 'express';
import * as userController from '../controllers/userController.js';
import { requirePermission } from '../middleware/auth.js';
import { validateBody, validateIdParams, validateQuery } from '../middleware/validate.js';
import { userListQuery } from '../validators/userQueries.js';

export const usersRouter = Router();

// Any signed-in user may list active users to pick an owner/PIC.
usersRouter.get('/options', userController.options);

usersRouter.get('/', requirePermission(MODULE.USERS, 'read'), validateQuery(userListQuery), userController.list);
usersRouter.get('/:id', requirePermission(MODULE.USERS, 'read'), validateIdParams('id'), userController.get);
usersRouter.post('/', requirePermission(MODULE.USERS, 'write'), validateBody(userCreateSchema), userController.create);
usersRouter.put('/:id', requirePermission(MODULE.USERS, 'write'), validateIdParams('id'), validateBody(userUpdateSchema), userController.update);
usersRouter.post(
  '/:id/reset-password',
  requirePermission(MODULE.USERS, 'write'),
  validateIdParams('id'),
  validateBody(userResetPasswordSchema),
  userController.resetPassword,
);
