/** Create/edit forms for products, purchase orders, deliveries, returns, stock, lead time and maklon. */
import {
  DELIVERY_STATUS,
  deliveryCreateSchema,
  deliveryUpdateSchema,
  inboundMaklonCreateSchema,
  inboundMaklonUpdateSchema,
  leadTimeCreateSchema,
  leadTimeUpdateSchema,
  MODULE,
  poLineSchema,
  poLineUpdateSchema,
  PRODUCT_STATUS,
  productCreateSchema,
  productUpdateSchema,
  purchaseOrderStatusSchema,
  purchaseOrderUpdateSchema,
  RETURN_STATUS,
  returnCreateSchema,
  returnUpdateSchema,
  STOCK_TYPE,
  stockCreateSchema,
  stockUpdateSchema,
} from '@pik/shared';
import { useId } from 'react';
import { useAuth, useToast } from '../../context/contexts.js';
import { useApi } from '../../hooks/useApi.js';
import { useForm } from '../../hooks/useForm.js';
import { api } from '../../services/api.js';
import { formatQuantity } from '../../utils/format.js';
import { Field, Input, Select, Textarea } from '../ui/Field.jsx';
import { FormActions, FormError } from './CrmForms.jsx';
import { CustomerSelect, PoLineSelect, ProductSelect, PurchaseOrderSelect, UserSelect } from './pickers.jsx';

/** POs a transaction can be recorded against (cancelled POs are excluded). */
const ACTIVE_PO_STATUSES = ['OPEN', 'ON_PROCESS', 'PARTIAL', 'CLOSED'];

/** Suggestions for a free-text field from existing values. */
function Suggestions({ id, values }) {
  return (
    <datalist id={id}>
      {(values ?? []).map((value) => (
        <option key={value} value={value} />
      ))}
    </datalist>
  );
}

// ------------------------------------------------------------------- product
export function ProductForm({ product, onSaved, onCancel }) {
  const toast = useToast();
  const categoriesId = useId();
  const unitsId = useId();
  const { data: facets } = useApi('/products/facets');
  const form = useForm({
    name: product?.name ?? '',
    product_code: product?.product_code ?? '',
    category: product?.category ?? '',
    unit: product?.unit ?? '',
    customer_id: product?.customer_id ?? '',
    customer_name: product?.customer_name ?? '',
    lead_time_days: product?.lead_time_days ?? '',
    status: product?.status ?? 'ACTIVE',
    description: product?.description ?? '',
  });
  const v = form.values;
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(
      product ? productUpdateSchema : productCreateSchema,
      async (data) => {
        const response = product ? await api.put(`/products/${product.id}`, data) : await api.post('/products', data);
        toast.success(product ? 'Produk disimpan.' : 'Produk ditambahkan.');
        onSaved(response.data);
      },
      ({ customer_name: _c, ...values }) => values,
    );
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      <div className="form-grid">
        <Field label="Nama produk" required error={form.errors.name} className="span-2">
          <Input {...form.bind('name')} autoFocus />
        </Field>
        <Field label="Kode produk" error={form.errors.product_code}>
          <Input {...form.bind('product_code')} />
        </Field>
        <Field label="Status" error={form.errors.status}>
          <Select options={PRODUCT_STATUS.options} {...form.bind('status')} />
        </Field>
        <Field label="Kategori" error={form.errors.category}>
          <Input list={categoriesId} {...form.bind('category')} />
        </Field>
        <Field label="Satuan" error={form.errors.unit} hint="mis. pcs, kg, roll">
          <Input list={unitsId} {...form.bind('unit')} />
        </Field>
        <Suggestions id={categoriesId} values={facets?.categories} />
        <Suggestions id={unitsId} values={facets?.units} />
        <Field label="Customer (produk khusus)" error={form.errors.customer_id} hint="Kosongkan untuk produk umum">
          <CustomerSelect
            value={v.customer_id}
            selectedLabel={v.customer_name}
            onChange={(id, row) => {
              form.setValue('customer_id', id);
              form.setValue('customer_name', row?.name ?? '');
            }}
          />
        </Field>
        <Field label="Lead time standar (hari)" error={form.errors.lead_time_days}>
          <Input type="number" inputMode="numeric" min="0" step="1" {...form.bind('lead_time_days')} />
        </Field>
        <Field label="Deskripsi / spesifikasi" error={form.errors.description} className="span-2">
          <Textarea rows={3} {...form.bind('description')} />
        </Field>
      </div>
      <FormActions onCancel={onCancel} submitting={form.submitting} />
    </form>
  );
}

// ------------------------------------------------------------ PO header/line
export function PurchaseOrderHeaderForm({ po, onSaved, onCancel }) {
  const toast = useToast();
  const { access } = useAuth();
  const form = useForm({
    po_number: po.po_number ?? '',
    customer_id: po.customer_id,
    customer_name: po.customer_name,
    po_date: po.po_date ?? '',
    expected_delivery_date: po.expected_delivery_date ?? '',
    owner_user_id: po.owner_user_id ?? '',
    notes: po.notes ?? '',
  });
  const v = form.values;
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(
      purchaseOrderUpdateSchema,
      async (data) => {
        const response = await api.put(`/purchase-orders/${po.id}`, data);
        toast.success('Data PO disimpan.');
        onSaved(response.data);
      },
      ({ customer_name: _c, ...values }) => values,
    );
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      <div className="form-grid">
        <Field label="Nomor PO" required error={form.errors.po_number}>
          <Input {...form.bind('po_number')} autoFocus />
        </Field>
        <Field label="Customer" required error={form.errors.customer_id} hint="Tidak dapat diganti bila sudah ada pengiriman, retur atau invoice">
          <CustomerSelect
            value={v.customer_id}
            selectedLabel={v.customer_name}
            onChange={(id, row) => {
              form.setValue('customer_id', id);
              form.setValue('customer_name', row?.name ?? '');
            }}
          />
        </Field>
        <Field label="Tanggal PO" error={form.errors.po_date}>
          <Input type="date" {...form.bind('po_date')} />
        </Field>
        <Field label="Target pengiriman" error={form.errors.expected_delivery_date}>
          <Input type="date" min={v.po_date || undefined} {...form.bind('expected_delivery_date')} />
        </Field>
        <Field label="PIC" error={form.errors.owner_user_id}>
          <UserSelect {...form.bind('owner_user_id')} disabled={access(MODULE.PURCHASE_ORDERS) === 'OWN'} />
        </Field>
        <Field label="Catatan" error={form.errors.notes} className="span-2">
          <Textarea rows={3} {...form.bind('notes')} />
        </Field>
      </div>
      <FormActions onCancel={onCancel} submitting={form.submitting} />
    </form>
  );
}

/** Adds a line to a PO, or edits one. onSaved receives the refreshed PO detail. */
export function PoLineForm({ purchaseOrderId, line, onSaved, onCancel }) {
  const toast = useToast();
  const used = Boolean(line) && Number(line.delivered_quantity) + Number(line.in_progress_quantity) + Number(line.returned_quantity) > 0;
  const form = useForm({
    product_id: line?.product_id ?? '',
    product_name: line?.product_name ?? line?.item_name ?? '',
    order_quantity: line?.order_quantity ?? '',
    unit: line?.unit ?? '',
    unit_price: line?.unit_price ?? '',
    notes: line?.notes ?? '',
  });
  const v = form.values;
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(
      line ? poLineUpdateSchema : poLineSchema,
      async (data) => {
        const response = line ? await api.put(`/po-lines/${line.id}`, data) : await api.post(`/purchase-orders/${purchaseOrderId}/lines`, data);
        toast.success(line ? 'Item PO disimpan.' : 'Item ditambahkan ke PO.');
        onSaved(response.data);
      },
      ({ product_name: _p, ...values }) => values,
    );
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      {line && !line.product_id && line.item_name && (
        <div className="form-alert warning">
          Item hasil migrasi “{line.item_name}” belum terhubung ke master produk. Pilih produk yang sesuai.
        </div>
      )}
      <div className="form-grid">
        <Field label="Produk" required error={form.errors.product_id} className="span-2" hint={used ? 'Produk tidak dapat diganti karena item sudah dikirim atau diretur' : undefined}>
          <ProductSelect
            value={v.product_id}
            selectedLabel={v.product_name}
            disabled={used && Boolean(line.product_id)}
            onChange={(id, row) => {
              form.setValue('product_id', id);
              form.setValue('product_name', row?.name ?? '');
              if (row?.unit && !v.unit) form.setValue('unit', row.unit);
            }}
          />
        </Field>
        <Field label="Qty order" required error={form.errors.order_quantity}>
          <Input type="number" inputMode="decimal" min="0" step="any" {...form.bind('order_quantity')} />
        </Field>
        <Field label="Satuan" error={form.errors.unit} hint="Kosong = satuan produk">
          <Input {...form.bind('unit')} />
        </Field>
        <Field label="Harga satuan (Rp)" error={form.errors.unit_price}>
          <Input type="number" inputMode="decimal" min="0" step="any" {...form.bind('unit_price')} />
        </Field>
        <Field label="Catatan item" error={form.errors.notes}>
          <Input {...form.bind('notes')} />
        </Field>
      </div>
      <FormActions onCancel={onCancel} submitting={form.submitting} submitLabel={line ? 'Simpan' : 'Tambah item'} />
    </form>
  );
}

/** Confirms a PO status change; cancelling asks for the reason. */
export function PoStatusForm({ po, status, onSaved, onCancel }) {
  const toast = useToast();
  const cancelling = status === 'CANCELLED';
  const form = useForm({ status, cancel_reason: '' });
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(purchaseOrderStatusSchema, async (data) => {
      if (cancelling && !data.cancel_reason) {
        form.setErrors({ cancel_reason: 'Alasan pembatalan wajib diisi.' });
        throw new Error('Alasan pembatalan wajib diisi.');
      }
      const response = await api.patch(`/purchase-orders/${po.id}/status`, data);
      toast.success(cancelling ? 'PO dibatalkan.' : 'Status PO diperbarui.');
      onSaved(response.data);
    });
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      {cancelling ? (
        <>
          <p className="text-sm">
            PO <strong>{po.po_number}</strong> akan dibatalkan. Data PO, item dan riwayatnya tetap tersimpan; hanya Admin yang dapat membukanya kembali.
          </p>
          <Field label="Alasan pembatalan" required error={form.errors.cancel_reason}>
            <Textarea rows={3} autoFocus {...form.bind('cancel_reason')} placeholder="mis. customer membatalkan order" />
          </Field>
        </>
      ) : (
        <p className="text-sm">Ubah status PO {po.po_number}?</p>
      )}
      <FormActions onCancel={onCancel} submitting={form.submitting} submitLabel={cancelling ? 'Batalkan PO' : 'Ubah status'} />
    </form>
  );
}

// ------------------------------------------------------------------ delivery
/**
 * Records a delivery against a PO line. The product always follows the line.
 * `purchaseOrder` / `line` preselect the PO (from the PO page).
 */
export function DeliveryForm({ delivery, purchaseOrder, line, onSaved, onCancel }) {
  const toast = useToast();
  const { today } = useAuth();
  const form = useForm({
    purchase_order_id: delivery?.purchase_order_id ?? purchaseOrder?.id ?? '',
    po_number: delivery?.po_number ?? purchaseOrder?.po_number ?? '',
    po_line_id: delivery?.po_line_id ?? line?.id ?? '',
    delivery_date: delivery?.delivery_date ?? today,
    quantity: delivery?.quantity ?? (line && line.outstanding_quantity > 0 ? line.outstanding_quantity : ''),
    status: delivery?.status ?? 'SCHEDULED',
    delivery_number: delivery?.delivery_number ?? '',
    notes: delivery?.notes ?? '',
  });
  const v = form.values;
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(
      delivery ? deliveryUpdateSchema : deliveryCreateSchema,
      async (data) => {
        const response = delivery ? await api.put(`/deliveries/${delivery.id}`, data) : await api.post('/deliveries', data);
        toast.success(delivery ? 'Pengiriman disimpan.' : 'Pengiriman dicatat.');
        for (const warning of response.meta?.warnings ?? []) toast.warning(warning);
        onSaved(response.data);
      },
      ({ po_number: _p, ...values }) => values,
    );
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      <div className="form-grid">
        <Field label="Nomor PO" required error={form.errors.purchase_order_id}>
          <PurchaseOrderSelect
            statuses={ACTIVE_PO_STATUSES}
            value={v.purchase_order_id}
            selectedLabel={v.po_number}
            disabled={Boolean(purchaseOrder)}
            onChange={(id, row) => {
              form.setValue('purchase_order_id', id);
              form.setValue('po_number', row?.po_number ?? '');
              form.setValue('po_line_id', '');
            }}
          />
        </Field>
        <Field label="Item PO" required error={form.errors.po_line_id}>
          <PoLineSelect
            purchaseOrderId={v.purchase_order_id}
            value={v.po_line_id}
            onChange={(id, selected) => {
              form.setValue('po_line_id', id);
              if (selected && !v.quantity && selected.outstanding_quantity > 0) form.setValue('quantity', selected.outstanding_quantity);
            }}
          />
        </Field>
        <Field label="Tanggal kirim" required error={form.errors.delivery_date}>
          <Input type="date" {...form.bind('delivery_date')} />
        </Field>
        <Field label="Qty kirim" required error={form.errors.quantity}>
          <Input type="number" inputMode="decimal" min="0" step="any" {...form.bind('quantity')} />
        </Field>
        <Field label="Status" required error={form.errors.status} hint="Hanya status Delivered yang mengurangi outstanding">
          <Select options={DELIVERY_STATUS.options} {...form.bind('status')} />
        </Field>
        <Field label="Nomor surat jalan" error={form.errors.delivery_number}>
          <Input {...form.bind('delivery_number')} />
        </Field>
        <Field label="Catatan" error={form.errors.notes} className="span-2">
          <Textarea rows={2} {...form.bind('notes')} />
        </Field>
      </div>
      <FormActions onCancel={onCancel} submitting={form.submitting} />
    </form>
  );
}

// -------------------------------------------------------------------- return
export function ReturnForm({ record, customer, purchaseOrder, onSaved, onCancel }) {
  const toast = useToast();
  const { today } = useAuth();
  const form = useForm({
    customer_id: record?.customer_id ?? purchaseOrder?.customer_id ?? customer?.id ?? '',
    customer_name: record?.customer_name ?? purchaseOrder?.customer_name ?? customer?.name ?? '',
    purchase_order_id: record?.purchase_order_id ?? purchaseOrder?.id ?? '',
    po_number: record?.po_number ?? purchaseOrder?.po_number ?? '',
    po_line_id: record?.po_line_id ?? '',
    product_id: record?.product_id ?? '',
    product_name: record?.product_name ?? '',
    return_date: record?.return_date ?? today,
    quantity: record?.quantity ?? '',
    reason: record?.reason ?? '',
    status: record?.status ?? 'REPORTED',
    return_number: record?.return_number ?? '',
    notes: record?.notes ?? '',
  });
  const v = form.values;
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(
      record ? returnUpdateSchema : returnCreateSchema,
      async (data) => {
        const response = record ? await api.put(`/returns/${record.id}`, data) : await api.post('/returns', data);
        toast.success(record ? 'Retur disimpan.' : 'Retur dicatat.');
        onSaved(response.data);
      },
      ({ customer_name: _c, po_number: _p, product_name: _n, ...values }) => values,
    );
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      <div className="form-grid">
        <Field label="Customer" required error={form.errors.customer_id}>
          <CustomerSelect
            value={v.customer_id}
            selectedLabel={v.customer_name}
            disabled={Boolean(purchaseOrder || customer)}
            onChange={(id, row) => {
              form.setValue('customer_id', id);
              form.setValue('customer_name', row?.name ?? '');
              form.setValue('purchase_order_id', '');
              form.setValue('po_number', '');
              form.setValue('po_line_id', '');
            }}
          />
        </Field>
        <Field label="Nomor PO" error={form.errors.purchase_order_id} hint="Opsional">
          <PurchaseOrderSelect
            customerId={v.customer_id}
            value={v.purchase_order_id}
            selectedLabel={v.po_number}
            disabled={Boolean(purchaseOrder) || !v.customer_id}
            onChange={(id, row) => {
              form.setValue('purchase_order_id', id);
              form.setValue('po_number', row?.po_number ?? '');
              form.setValue('po_line_id', '');
            }}
          />
        </Field>
        <Field label="Item PO" error={form.errors.po_line_id} hint="Opsional; menentukan produk">
          <PoLineSelect
            purchaseOrderId={v.purchase_order_id}
            value={v.po_line_id}
            placeholder="Tanpa item PO"
            onChange={(id, selected) => {
              form.setValue('po_line_id', id);
              if (selected?.product_id) {
                form.setValue('product_id', selected.product_id);
                form.setValue('product_name', selected.product_name ?? '');
              }
            }}
          />
        </Field>
        <Field label="Produk" required error={form.errors.product_id}>
          <ProductSelect
            value={v.product_id}
            selectedLabel={v.product_name}
            disabled={Boolean(v.po_line_id)}
            onChange={(id, row) => {
              form.setValue('product_id', id);
              form.setValue('product_name', row?.name ?? '');
            }}
          />
        </Field>
        <Field label="Tanggal retur" required error={form.errors.return_date}>
          <Input type="date" {...form.bind('return_date')} />
        </Field>
        <Field label="Qty retur" required error={form.errors.quantity}>
          <Input type="number" inputMode="decimal" min="0" step="any" {...form.bind('quantity')} />
        </Field>
        <Field label="Status" required error={form.errors.status} hint="Diterima/Selesai menambah kembali outstanding PO">
          <Select options={RETURN_STATUS.options} {...form.bind('status')} />
        </Field>
        <Field label="Nomor retur" error={form.errors.return_number}>
          <Input {...form.bind('return_number')} />
        </Field>
        <Field label="Alasan retur" required error={form.errors.reason} className="span-2">
          <Textarea rows={2} {...form.bind('reason')} placeholder="mis. cacat cetak, ukuran tidak sesuai" />
        </Field>
        <Field label="Catatan" error={form.errors.notes} className="span-2">
          <Textarea rows={2} {...form.bind('notes')} />
        </Field>
      </div>
      <FormActions onCancel={onCancel} submitting={form.submitting} />
    </form>
  );
}

// --------------------------------------------------------------------- stock
/** Records a stock count (snapshot) for a product; corrections edit an existing snapshot. */
export function StockForm({ record, product, onSaved, onCancel }) {
  const toast = useToast();
  const { today } = useAuth();
  const warehousesId = useId();
  const { data: overview } = useApi('/stock/overview');
  const form = useForm({
    product_id: record?.product_id ?? product?.id ?? '',
    product_name: record?.product_name ?? product?.name ?? '',
    stock_type: record?.stock_type ?? 'FG',
    quantity: record?.quantity ?? '',
    warehouse: record?.warehouse ?? '',
    stock_date: record?.stock_date ?? today,
    notes: record?.notes ?? '',
  });
  const v = form.values;
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(
      record ? stockUpdateSchema : stockCreateSchema,
      async (data) => {
        const response = record ? await api.put(`/stock/${record.id}`, data) : await api.post('/stock', data);
        toast.success(record ? 'Data stok dikoreksi.' : 'Stok dicatat.');
        onSaved(response.data);
      },
      ({ product_name: _p, ...values }) => values,
    );
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      {!record && (
        <p className="text-sm muted" style={{ margin: 0 }}>
          Catat hasil hitung stok terbaru. Stok saat ini = catatan terakhir per produk, tipe dan gudang; riwayat sebelumnya tetap tersimpan.
        </p>
      )}
      <div className="form-grid">
        <Field label="Produk" required error={form.errors.product_id} className="span-2">
          <ProductSelect
            value={v.product_id}
            selectedLabel={v.product_name}
            disabled={Boolean(product)}
            onChange={(id, row) => {
              form.setValue('product_id', id);
              form.setValue('product_name', row?.name ?? '');
            }}
          />
        </Field>
        <Field label="Tipe stok" required error={form.errors.stock_type}>
          <Select options={STOCK_TYPE.options} {...form.bind('stock_type')} />
        </Field>
        <Field label="Qty" required error={form.errors.quantity}>
          <Input type="number" inputMode="decimal" min="0" step="any" {...form.bind('quantity')} />
        </Field>
        <Field label="Gudang" error={form.errors.warehouse}>
          <Input list={warehousesId} {...form.bind('warehouse')} />
        </Field>
        <Suggestions id={warehousesId} values={overview?.warehouses} />
        <Field label="Tanggal hitung" required error={form.errors.stock_date}>
          <Input type="date" max={today} {...form.bind('stock_date')} />
        </Field>
        <Field label="Catatan" error={form.errors.notes} className="span-2">
          <Textarea rows={2} {...form.bind('notes')} />
        </Field>
      </div>
      <FormActions onCancel={onCancel} submitting={form.submitting} />
    </form>
  );
}

// ----------------------------------------------------------------- lead time
export function LeadTimeForm({ record, product, onSaved, onCancel }) {
  const toast = useToast();
  const form = useForm({
    product_id: record?.product_id ?? product?.id ?? '',
    product_name: record?.product_name ?? product?.name ?? '',
    customer_id: record?.customer_id ?? '',
    customer_name: record?.customer_name ?? '',
    lead_time_days: record?.lead_time_days ?? '',
    notes: record?.notes ?? '',
  });
  const v = form.values;
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(
      record ? leadTimeUpdateSchema : leadTimeCreateSchema,
      async (data) => {
        const response = record ? await api.put(`/lead-times/${record.id}`, data) : await api.post('/lead-times', data);
        toast.success(record ? 'Lead time disimpan.' : 'Lead time ditambahkan.');
        onSaved(response.data);
      },
      ({ product_name: _p, customer_name: _c, ...values }) => values,
    );
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      {record && !record.product_id && record.item_name && (
        <div className="form-alert warning">Data migrasi “{record.item_name}” belum terhubung ke master produk.</div>
      )}
      <div className="form-grid">
        <Field label="Produk" error={form.errors.product_id}>
          <ProductSelect
            value={v.product_id}
            selectedLabel={v.product_name}
            disabled={Boolean(product)}
            onChange={(id, row) => {
              form.setValue('product_id', id);
              form.setValue('product_name', row?.name ?? '');
            }}
          />
        </Field>
        <Field label="Customer" error={form.errors.customer_id} hint="Opsional: lead time khusus customer">
          <CustomerSelect
            value={v.customer_id}
            selectedLabel={v.customer_name}
            onChange={(id, row) => {
              form.setValue('customer_id', id);
              form.setValue('customer_name', row?.name ?? '');
            }}
          />
        </Field>
        <Field label="Lead time (hari)" required error={form.errors.lead_time_days}>
          <Input type="number" inputMode="numeric" min="0" step="1" {...form.bind('lead_time_days')} autoFocus={Boolean(product)} />
        </Field>
        <Field label="Catatan" error={form.errors.notes} className="span-2">
          <Textarea rows={2} {...form.bind('notes')} />
        </Field>
      </div>
      <FormActions onCancel={onCancel} submitting={form.submitting} />
    </form>
  );
}

// ------------------------------------------------------------ inbound maklon
export function InboundMaklonForm({ record, onSaved, onCancel }) {
  const toast = useToast();
  const { today } = useAuth();
  const form = useForm({
    customer_id: record?.customer_id ?? '',
    customer_name: record?.customer_name ?? '',
    purchase_order_id: record?.purchase_order_id ?? '',
    po_number: record?.po_number ?? '',
    product_id: record?.product_id ?? '',
    product_name: record?.product_id ? (record?.product_name ?? '') : '',
    item_name: record?.item_name ?? '',
    inbound_date: record?.inbound_date ?? today,
    quantity: record?.quantity ?? '',
    unit: record?.unit ?? '',
    document_number: record?.document_number ?? '',
    notes: record?.notes ?? '',
  });
  const v = form.values;
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(
      record ? inboundMaklonUpdateSchema : inboundMaklonCreateSchema,
      async (data) => {
        const response = record ? await api.put(`/inbound-maklon/${record.id}`, data) : await api.post('/inbound-maklon', data);
        toast.success(record ? 'Data maklon masuk disimpan.' : 'Maklon masuk dicatat.');
        onSaved(response.data);
      },
      ({ customer_name: _c, po_number: _p, product_name: _n, ...values }) => values,
    );
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      <div className="form-grid">
        <Field label="Customer" error={form.errors.customer_id}>
          <CustomerSelect
            value={v.customer_id}
            selectedLabel={v.customer_name}
            onChange={(id, row) => {
              form.setValue('customer_id', id);
              form.setValue('customer_name', row?.name ?? '');
              form.setValue('purchase_order_id', '');
              form.setValue('po_number', '');
            }}
          />
        </Field>
        <Field label="Nomor PO" error={form.errors.purchase_order_id}>
          <PurchaseOrderSelect
            customerId={v.customer_id}
            value={v.purchase_order_id}
            selectedLabel={v.po_number}
            onChange={(id, row) => {
              form.setValue('purchase_order_id', id);
              form.setValue('po_number', row?.po_number ?? '');
            }}
          />
        </Field>
        <Field label="Produk" error={form.errors.product_id}>
          <ProductSelect
            value={v.product_id}
            selectedLabel={v.product_name}
            onChange={(id, row) => {
              form.setValue('product_id', id);
              form.setValue('product_name', row?.name ?? '');
              if (row?.unit && !v.unit) form.setValue('unit', row.unit);
            }}
          />
        </Field>
        <Field label="Nama barang" error={form.errors.item_name} hint="Isi bila barang tidak ada di master produk">
          <Input {...form.bind('item_name')} />
        </Field>
        <Field label="Tanggal masuk" required error={form.errors.inbound_date}>
          <Input type="date" {...form.bind('inbound_date')} />
        </Field>
        <Field label="Qty masuk" required error={form.errors.quantity}>
          <Input type="number" inputMode="decimal" min="0" step="any" {...form.bind('quantity')} />
        </Field>
        <Field label="Satuan" error={form.errors.unit}>
          <Input {...form.bind('unit')} />
        </Field>
        <Field label="Nomor dokumen" error={form.errors.document_number}>
          <Input {...form.bind('document_number')} />
        </Field>
        <Field label="Catatan" error={form.errors.notes} className="span-2">
          <Textarea rows={2} {...form.bind('notes')} />
        </Field>
      </div>
      <FormActions onCancel={onCancel} submitting={form.submitting} />
    </form>
  );
}

/** Compact read-only summary of a PO line's fulfillment (used in forms and tables). */
export function LineFulfillment({ line }) {
  return (
    <span className="cell-sub num">
      kirim {formatQuantity(line.delivered_quantity)} · retur {formatQuantity(line.returned_quantity)} · sisa {formatQuantity(line.outstanding_quantity, line.unit)}
    </span>
  );
}
