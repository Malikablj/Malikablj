/** HTTP handlers for products, purchase orders, deliveries, returns, stock, lead time, maklon and finance. */
import * as financeService from '../services/financeService.js';
import * as operationsService from '../services/operationsService.js';
import * as productService from '../services/productService.js';
import * as purchaseOrderService from '../services/purchaseOrderService.js';
import { sendCreated, sendOk } from '../utils/http.js';

const listHandler = (fn) => async (req, res) => {
  const { rows, meta } = await fn(req.validatedQuery);
  sendOk(res, rows, meta);
};

export const products = {
  list: listHandler(productService.list),
  facets: async (req, res) => sendOk(res, await productService.facets()),
  get: async (req, res) => sendOk(res, await productService.getDetail(req.params.id)),
  create: async (req, res) => sendCreated(res, await productService.create(req.body, req.user)),
  update: async (req, res) => sendOk(res, await productService.update(req.params.id, req.body, req.user)),
  archive: async (req, res) => sendOk(res, await productService.setActive(req.params.id, false, req.user)),
  restore: async (req, res) => sendOk(res, await productService.setActive(req.params.id, true, req.user)),
};

export const purchaseOrders = {
  list: listHandler(purchaseOrderService.list),
  get: async (req, res) => sendOk(res, await purchaseOrderService.getDetail(req.params.id, req.user)),
  create: async (req, res) => sendCreated(res, await purchaseOrderService.create(req.body, req.user)),
  update: async (req, res) => sendOk(res, await purchaseOrderService.update(req.params.id, req.body, req.user)),
  changeStatus: async (req, res) => sendOk(res, await purchaseOrderService.changeStatus(req.params.id, req.body, req.user)),
  addLine: async (req, res) => sendCreated(res, await purchaseOrderService.addLine(req.params.id, req.body, req.user)),
  updateLine: async (req, res) => sendOk(res, await purchaseOrderService.updateLine(req.params.id, req.body, req.user)),
  deleteLine: async (req, res) => sendOk(res, await purchaseOrderService.deleteLine(req.params.id, req.user)),
};

export const deliveries = {
  list: listHandler(operationsService.listDeliveries),
  get: async (req, res) => sendOk(res, await operationsService.getDelivery(req.params.id)),
  async create(req, res) {
    const { delivery, warnings } = await operationsService.createDelivery(req.body, req.user);
    sendCreated(res, delivery, { warnings });
  },
  async update(req, res) {
    const { delivery, warnings } = await operationsService.updateDelivery(req.params.id, req.body, req.user);
    sendOk(res, delivery, { warnings });
  },
};

export const returns = {
  list: listHandler(operationsService.listReturns),
  get: async (req, res) => sendOk(res, await operationsService.getReturn(req.params.id)),
  create: async (req, res) => sendCreated(res, await operationsService.createReturn(req.body, req.user)),
  update: async (req, res) => sendOk(res, await operationsService.updateReturn(req.params.id, req.body, req.user)),
};

export const stock = {
  list: listHandler(operationsService.listCurrentStock),
  history: listHandler(operationsService.listStockHistory),
  overview: async (req, res) => sendOk(res, await operationsService.stockOverview()),
  get: async (req, res) => sendOk(res, await operationsService.getStock(req.params.id)),
  create: async (req, res) => sendCreated(res, await operationsService.createStock(req.body, req.user)),
  update: async (req, res) => sendOk(res, await operationsService.updateStock(req.params.id, req.body, req.user)),
};

export const leadTimes = {
  list: listHandler(operationsService.listLeadTimes),
  get: async (req, res) => sendOk(res, await operationsService.getLeadTime(req.params.id)),
  create: async (req, res) => sendCreated(res, await operationsService.createLeadTime(req.body, req.user)),
  update: async (req, res) => sendOk(res, await operationsService.updateLeadTime(req.params.id, req.body, req.user)),
};

export const inboundMaklon = {
  list: listHandler(operationsService.listInboundMaklon),
  get: async (req, res) => sendOk(res, await operationsService.getInboundMaklon(req.params.id)),
  create: async (req, res) => sendCreated(res, await operationsService.createInboundMaklon(req.body, req.user)),
  update: async (req, res) => sendOk(res, await operationsService.updateInboundMaklon(req.params.id, req.body, req.user)),
};

export const invoices = {
  list: listHandler(financeService.listInvoices),
  get: async (req, res) => sendOk(res, await financeService.getInvoice(req.params.id)),
  create: async (req, res) => sendCreated(res, await financeService.createInvoice(req.body, req.user)),
  update: async (req, res) => sendOk(res, await financeService.updateInvoice(req.params.id, req.body, req.user)),
};

export const poFinancials = {
  list: listHandler(financeService.listPoFinancials),
  get: async (req, res) => sendOk(res, await financeService.getPoFinancial(req.params.id)),
  create: async (req, res) => sendCreated(res, await financeService.createPoFinancial(req.body, req.user)),
  update: async (req, res) => sendOk(res, await financeService.updatePoFinancial(req.params.id, req.body, req.user)),
};
