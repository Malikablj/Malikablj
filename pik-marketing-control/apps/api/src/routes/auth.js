import { changePasswordSchema, loginSchema } from '@pik/shared';
import { Router } from 'express';
import * as authController from '../controllers/authController.js';
import { requireAuth } from '../middleware/auth.js';
import { validateBody } from '../middleware/validate.js';

export const authRouter = Router();

authRouter.post('/login', validateBody(loginSchema), authController.login);
authRouter.post('/logout', authController.logout);
authRouter.get('/me', requireAuth, authController.me);
authRouter.post('/change-password', requireAuth, validateBody(changePasswordSchema), authController.changePassword);
