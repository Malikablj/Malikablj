import {
  deliveryCreateSchema,
  deliveryUpdateSchema,
  inboundMaklonCreateSchema,
  inboundMaklonUpdateSchema,
  invoiceCreateSchema,
  invoiceUpdateSchema,
  leadTimeCreateSchema,
  leadTimeUpdateSchema,
  MODULE,
  poFinancialCreateSchema,
  poFinancialUpdateSchema,
  poLineSchema,
  poLineUpdateSchema,
  productCreateSchema,
  productUpdateSchema,
  purchaseOrderCreateSchema,
  purchaseOrderStatusSchema,
  purchaseOrderUpdateSchema,
  returnCreateSchema,
  returnUpdateSchema,
  stockCreateSchema,
  stockUpdateSchema,
} from '@pik/shared';
import { Router } from 'express';
import {
  deliveries,
  inboundMaklon,
  invoices,
  leadTimes,
  poFinancials,
  products,
  purchaseOrders,
  returns,
  stock,
} from '../controllers/operationsController.js';
import { requirePermission } from '../middleware/auth.js';
import { validateBody, validateIdParams, validateQuery } from '../middleware/validate.js';
import {
  deliveryListQuery,
  inboundMaklonListQuery,
  invoiceListQuery,
  leadTimeListQuery,
  poFinancialListQuery,
  productListQuery,
  purchaseOrderListQuery,
  returnListQuery,
  stockListQuery,
} from '../validators/operationsQueries.js';

const id = validateIdParams('id');
const perm = (module) => ({ read: requirePermission(module, 'read'), write: requirePermission(module, 'write') });

/** Standard list/get/create/update routes for a resource. */
function crudRouter(module, handlers, { listQuery, createSchema, updateSchema }) {
  const router = Router();
  const { read, write } = perm(module);
  router.get('/', read, validateQuery(listQuery), handlers.list);
  router.get('/:id', read, id, handlers.get);
  router.post('/', write, validateBody(createSchema), handlers.create);
  router.put('/:id', write, id, validateBody(updateSchema), handlers.update);
  return router;
}

export const productsRouter = Router();
{
  const { read, write } = perm(MODULE.PRODUCTS);
  productsRouter.get('/', read, validateQuery(productListQuery), products.list);
  productsRouter.get('/facets', read, products.facets);
  productsRouter.get('/:id', read, id, products.get);
  productsRouter.post('/', write, validateBody(productCreateSchema), products.create);
  productsRouter.put('/:id', write, id, validateBody(productUpdateSchema), products.update);
  productsRouter.delete('/:id', write, id, products.archive);
  productsRouter.post('/:id/restore', write, id, products.restore);
}

export const purchaseOrdersRouter = Router();
{
  const { read, write } = perm(MODULE.PURCHASE_ORDERS);
  purchaseOrdersRouter.get('/', read, validateQuery(purchaseOrderListQuery), purchaseOrders.list);
  purchaseOrdersRouter.get('/:id', read, id, purchaseOrders.get);
  purchaseOrdersRouter.post('/', write, validateBody(purchaseOrderCreateSchema), purchaseOrders.create);
  purchaseOrdersRouter.put('/:id', write, id, validateBody(purchaseOrderUpdateSchema), purchaseOrders.update);
  purchaseOrdersRouter.patch('/:id/status', write, id, validateBody(purchaseOrderStatusSchema), purchaseOrders.changeStatus);
  purchaseOrdersRouter.post('/:id/lines', write, id, validateBody(poLineSchema), purchaseOrders.addLine);
}

export const poLinesRouter = Router();
{
  const { write } = perm(MODULE.PURCHASE_ORDERS);
  poLinesRouter.put('/:id', write, id, validateBody(poLineUpdateSchema), purchaseOrders.updateLine);
  // Only lines without deliveries/returns can be removed (correcting a PO being entered).
  poLinesRouter.delete('/:id', write, id, purchaseOrders.deleteLine);
}

export const deliveriesRouter = crudRouter(MODULE.DELIVERIES, deliveries, {
  listQuery: deliveryListQuery,
  createSchema: deliveryCreateSchema,
  updateSchema: deliveryUpdateSchema,
});

export const returnsRouter = crudRouter(MODULE.RETURNS, returns, {
  listQuery: returnListQuery,
  createSchema: returnCreateSchema,
  updateSchema: returnUpdateSchema,
});

export const stockRouter = Router();
{
  const { read, write } = perm(MODULE.STOCK);
  stockRouter.get('/', read, validateQuery(stockListQuery), stock.list);
  stockRouter.get('/overview', read, stock.overview);
  stockRouter.get('/history', read, validateQuery(stockListQuery), stock.history);
  stockRouter.get('/:id', read, id, stock.get);
  stockRouter.post('/', write, validateBody(stockCreateSchema), stock.create);
  stockRouter.put('/:id', write, id, validateBody(stockUpdateSchema), stock.update);
}

export const leadTimesRouter = crudRouter(MODULE.LEAD_TIME, leadTimes, {
  listQuery: leadTimeListQuery,
  createSchema: leadTimeCreateSchema,
  updateSchema: leadTimeUpdateSchema,
});

export const inboundMaklonRouter = crudRouter(MODULE.INBOUND_MAKLON, inboundMaklon, {
  listQuery: inboundMaklonListQuery,
  createSchema: inboundMaklonCreateSchema,
  updateSchema: inboundMaklonUpdateSchema,
});

export const invoicesRouter = crudRouter(MODULE.FINANCE, invoices, {
  listQuery: invoiceListQuery,
  createSchema: invoiceCreateSchema,
  updateSchema: invoiceUpdateSchema,
});

export const poFinancialsRouter = crudRouter(MODULE.FINANCE, poFinancials, {
  listQuery: poFinancialListQuery,
  createSchema: poFinancialCreateSchema,
  updateSchema: poFinancialUpdateSchema,
});
