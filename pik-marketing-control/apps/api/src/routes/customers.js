import {
  contactCreateSchema,
  contactUpdateSchema,
  customerCreateSchema,
  customerUpdateSchema,
  MODULE,
} from '@pik/shared';
import { Router } from 'express';
import * as customerController from '../controllers/customerController.js';
import { requirePermission } from '../middleware/auth.js';
import { validateBody, validateIdParams, validateQuery } from '../middleware/validate.js';
import { customerListQuery } from '../validators/crmQueries.js';

const read = requirePermission(MODULE.CUSTOMERS, 'read');
const write = requirePermission(MODULE.CUSTOMERS, 'write');
const readContacts = requirePermission(MODULE.CONTACTS, 'read');
const writeContacts = requirePermission(MODULE.CONTACTS, 'write');

export const customersRouter = Router();
customersRouter.get('/', read, validateQuery(customerListQuery), customerController.list);
customersRouter.get('/facets', read, customerController.facets);
customersRouter.get('/:id', read, validateIdParams('id'), customerController.get);
customersRouter.post('/', write, validateBody(customerCreateSchema), customerController.create);
customersRouter.put('/:id', write, validateIdParams('id'), validateBody(customerUpdateSchema), customerController.update);
// DELETE archives (is_active = false); records are never physically deleted.
customersRouter.delete('/:id', write, validateIdParams('id'), customerController.archive);
customersRouter.post('/:id/restore', write, validateIdParams('id'), customerController.restore);

customersRouter.get('/:customerId/contacts', readContacts, validateIdParams('customerId'), customerController.listContacts);
customersRouter.post(
  '/:customerId/contacts',
  writeContacts,
  validateIdParams('customerId'),
  validateBody(contactCreateSchema),
  customerController.createContact,
);

export const contactsRouter = Router();
contactsRouter.put('/:id', writeContacts, validateIdParams('id'), validateBody(contactUpdateSchema), customerController.updateContact);
contactsRouter.delete('/:id', writeContacts, validateIdParams('id'), customerController.archiveContact);
