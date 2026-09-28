/** Query-string schemas for operations and finance list endpoints. */
import { DELIVERY_STATUS, PAYMENT_STATUS, PO_STATUS, PRODUCT_STATUS, RETURN_STATUS, STOCK_TYPE } from '@pik/shared';
import { listQuery, queryBoolean, queryDate, queryEnumList, queryId, queryText } from './common.js';

export const productListQuery = listQuery({
  category: queryText(150),
  customer_id: queryId(),
  status: queryEnumList(PRODUCT_STATUS.values),
  is_active: queryBoolean(),
});

export const purchaseOrderListQuery = listQuery({
  status: queryEnumList(PO_STATUS.values),
  customer_id: queryId(),
  owner_user_id: queryId(),
  po_date_from: queryDate(),
  po_date_to: queryDate(),
  expected_from: queryDate(),
  expected_to: queryDate(),
  has_outstanding: queryBoolean(),
  late: queryBoolean(),
});

export const deliveryListQuery = listQuery({
  status: queryEnumList(DELIVERY_STATUS.values),
  customer_id: queryId(),
  purchase_order_id: queryId(),
  product_id: queryId(),
  from: queryDate(),
  to: queryDate(),
});

export const returnListQuery = listQuery({
  status: queryEnumList(RETURN_STATUS.values),
  customer_id: queryId(),
  purchase_order_id: queryId(),
  product_id: queryId(),
  from: queryDate(),
  to: queryDate(),
});

export const stockListQuery = listQuery({
  stock_type: queryEnumList(STOCK_TYPE.values),
  warehouse: queryText(150),
  product_id: queryId(),
});

export const leadTimeListQuery = listQuery({ product_id: queryId(), customer_id: queryId() });

export const inboundMaklonListQuery = listQuery({
  customer_id: queryId(),
  purchase_order_id: queryId(),
  from: queryDate(),
  to: queryDate(),
});

export const invoiceListQuery = listQuery({
  payment_status: queryEnumList(PAYMENT_STATUS.values),
  customer_id: queryId(),
  purchase_order_id: queryId(),
  overdue: queryBoolean(),
  from: queryDate(),
  to: queryDate(),
});

export const poFinancialListQuery = listQuery({ customer_id: queryId(), purchase_order_id: queryId() });
