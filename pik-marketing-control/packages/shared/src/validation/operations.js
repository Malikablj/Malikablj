/**
 * Validation schemas for products, purchase orders, deliveries, returns, stock,
 * lead time and inbound maklon.
 */
import { z } from 'zod';
import {
  DELIVERY_STATUS,
  PO_STATUS,
  PRODUCT_STATUS,
  RETURN_STATUS,
  STOCK_TYPE,
} from '../constants.js';
import {
  enumField,
  optionalDate,
  optionalId,
  optionalNumber,
  optionalText,
  requiredDate,
  requiredId,
  requiredNumber,
  requiredText,
} from './common.js';

const MAX_QTY = 999_999_999_999;
const MAX_MONEY = 9_999_999_999_999;

// ----------------------------------------------------------------- products
const productBase = z.object({
  product_code: optionalText('Kode produk', 100),
  name: requiredText('Nama produk', 255),
  category: optionalText('Kategori', 150),
  customer_id: optionalId('Customer'),
  description: optionalText('Deskripsi', 5000),
  unit: optionalText('Satuan', 50),
  lead_time_days: optionalNumber('Lead time', { min: 0, max: 3650, integer: true }),
});

export const productCreateSchema = productBase.extend({
  status: enumField('Status produk', PRODUCT_STATUS.values).default('ACTIVE'),
});

export const productUpdateSchema = productBase
  .extend({ status: enumField('Status produk', PRODUCT_STATUS.values) })
  .partial();

// ----------------------------------------------------------- purchase orders
export const poLineSchema = z.object({
  product_id: requiredId('Produk'),
  order_quantity: requiredNumber('Qty order', { positive: true, max: MAX_QTY }),
  unit: optionalText('Satuan', 50),
  unit_price: optionalNumber('Harga satuan', { min: 0, max: MAX_MONEY }),
  notes: optionalText('Catatan item', 2000),
});

export const poLineUpdateSchema = poLineSchema.partial();

const poHeaderBase = z.object({
  po_number: requiredText('Nomor PO', 150),
  customer_id: requiredId('Customer'),
  po_date: optionalDate('Tanggal PO'),
  expected_delivery_date: optionalDate('Target pengiriman'),
  owner_user_id: optionalId('PIC'),
  notes: optionalText('Catatan', 5000),
});

const deliveryAfterPoDate = (value, ctx) => {
  if (value.po_date && value.expected_delivery_date && value.expected_delivery_date < value.po_date) {
    ctx.addIssue({
      code: 'custom',
      path: ['expected_delivery_date'],
      message: 'Target pengiriman tidak boleh sebelum tanggal PO.',
    });
  }
};

export const purchaseOrderCreateSchema = poHeaderBase
  .extend({
    lines: z.array(poLineSchema, { error: 'Item PO wajib diisi.' }).min(1, 'Minimal satu item PO.').max(200),
  })
  .superRefine(deliveryAfterPoDate);

/** Header edits. Status changes go through purchaseOrderStatusSchema. */
export const purchaseOrderUpdateSchema = poHeaderBase.partial().superRefine(deliveryAfterPoDate);

export const purchaseOrderStatusSchema = z.object({
  status: enumField('Status PO', PO_STATUS.values),
  cancel_reason: optionalText('Alasan pembatalan', 2000),
});

// --------------------------------------------------------------- deliveries
const deliveryBase = z.object({
  purchase_order_id: requiredId('PO'),
  po_line_id: requiredId('Item PO'),
  delivery_date: requiredDate('Tanggal pengiriman'),
  quantity: requiredNumber('Qty kirim', { positive: true, max: MAX_QTY }),
  status: enumField('Status pengiriman', DELIVERY_STATUS.values),
  delivery_number: optionalText('Nomor surat jalan', 100),
  notes: optionalText('Catatan', 5000),
});

export const deliveryCreateSchema = deliveryBase;
export const deliveryUpdateSchema = deliveryBase.partial();

// ------------------------------------------------------------------ returns
const returnBase = z.object({
  customer_id: requiredId('Customer'),
  purchase_order_id: optionalId('PO'),
  po_line_id: optionalId('Item PO'),
  product_id: requiredId('Produk'),
  return_date: requiredDate('Tanggal retur'),
  quantity: requiredNumber('Qty retur', { positive: true, max: MAX_QTY }),
  reason: requiredText('Alasan retur', 2000),
  status: enumField('Status retur', RETURN_STATUS.values),
  return_number: optionalText('Nomor retur', 100),
  notes: optionalText('Catatan', 5000),
});

const lineNeedsPo = (value, ctx) => {
  if (value.po_line_id && !value.purchase_order_id) {
    ctx.addIssue({ code: 'custom', path: ['purchase_order_id'], message: 'Pilih PO untuk item PO ini.' });
  }
};

export const returnCreateSchema = returnBase.superRefine(lineNeedsPo);
export const returnUpdateSchema = returnBase.partial().superRefine(lineNeedsPo);

// -------------------------------------------------------------------- stock
const stockBase = z.object({
  product_id: requiredId('Produk'),
  stock_type: enumField('Tipe stok', STOCK_TYPE.values),
  quantity: requiredNumber('Qty stok', { min: 0, max: MAX_QTY }),
  warehouse: optionalText('Gudang', 150),
  stock_date: requiredDate('Tanggal stok'),
  notes: optionalText('Catatan', 5000),
});

export const stockCreateSchema = stockBase;
export const stockUpdateSchema = stockBase.partial();

// ---------------------------------------------------------------- lead time
const leadTimeBase = z.object({
  product_id: optionalId('Produk'),
  customer_id: optionalId('Customer'),
  lead_time_days: requiredNumber('Lead time', { min: 0, max: 3650, integer: true }),
  notes: optionalText('Catatan', 5000),
});

const needsProductOrCustomer = (value, ctx) => {
  if (value.product_id === null && value.customer_id === null) {
    ctx.addIssue({ code: 'custom', path: ['product_id'], message: 'Pilih produk atau customer.' });
  }
};

export const leadTimeCreateSchema = leadTimeBase.superRefine((value, ctx) => {
  if (!value.product_id && !value.customer_id) {
    ctx.addIssue({ code: 'custom', path: ['product_id'], message: 'Pilih produk atau customer.' });
  }
});
export const leadTimeUpdateSchema = leadTimeBase.partial().superRefine(needsProductOrCustomer);

// ----------------------------------------------------------- inbound maklon
// Provisional structure until the source workbook is profiled (docs/DECISIONS.md).
const inboundMaklonBase = z.object({
  customer_id: optionalId('Customer'),
  purchase_order_id: optionalId('PO'),
  product_id: optionalId('Produk'),
  item_name: optionalText('Nama barang', 255),
  inbound_date: requiredDate('Tanggal masuk'),
  quantity: requiredNumber('Qty masuk', { positive: true, max: MAX_QTY }),
  unit: optionalText('Satuan', 50),
  document_number: optionalText('Nomor dokumen', 100),
  notes: optionalText('Catatan', 5000),
});

export const inboundMaklonCreateSchema = inboundMaklonBase.superRefine((value, ctx) => {
  if (!value.product_id && !value.item_name) {
    ctx.addIssue({ code: 'custom', path: ['item_name'], message: 'Pilih produk atau isi nama barang.' });
  }
});
export const inboundMaklonUpdateSchema = inboundMaklonBase.partial();
