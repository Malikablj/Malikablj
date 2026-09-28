/** Invoice/payment and PO financial summary forms. */
import { derivePaymentStatus, invoiceCreateSchema, invoiceUpdateSchema, PAYMENT_STATUS, poFinancialCreateSchema, poFinancialUpdateSchema } from '@pik/shared';
import { useAuth, useToast } from '../../context/contexts.js';
import { useForm } from '../../hooks/useForm.js';
import { api } from '../../services/api.js';
import { formatCurrency } from '../../utils/format.js';
import { StatusBadge } from '../ui/Badge.jsx';
import { Checkbox, Field, Input, Textarea } from '../ui/Field.jsx';
import { FormActions, FormError } from './CrmForms.jsx';
import { PurchaseOrderSelect } from './pickers.jsx';

export function InvoiceForm({ invoice, purchaseOrder, onSaved, onCancel }) {
  const toast = useToast();
  const { today } = useAuth();
  const form = useForm({
    purchase_order_id: invoice?.purchase_order_id ?? purchaseOrder?.id ?? '',
    po_number: invoice?.po_number ?? purchaseOrder?.po_number ?? '',
    po_total_value: purchaseOrder?.total_value ?? null,
    invoice_number: invoice?.invoice_number ?? '',
    invoice_date: invoice?.invoice_date ?? today,
    due_date: invoice?.due_date ?? '',
    amount: invoice?.amount ?? '',
    paid_amount: invoice?.paid_amount ?? '',
    payment_date: invoice?.payment_date ?? '',
    is_cancelled: invoice?.payment_status === 'CANCELLED',
    notes: invoice?.notes ?? '',
  });
  const v = form.values;
  const previewStatus = derivePaymentStatus({ amount: v.amount, paidAmount: v.paid_amount, cancelled: v.is_cancelled });
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(
      invoice ? invoiceUpdateSchema : invoiceCreateSchema,
      async (data) => {
        const response = invoice ? await api.put(`/invoices/${invoice.id}`, data) : await api.post('/invoices', data);
        toast.success(invoice ? 'Invoice disimpan.' : 'Invoice ditambahkan.');
        onSaved(response.data);
      },
      ({ po_number: _p, po_total_value: _t, ...values }) => values,
    );
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      <div className="form-grid">
        <Field label="Nomor PO" required error={form.errors.purchase_order_id}>
          <PurchaseOrderSelect
            value={v.purchase_order_id}
            selectedLabel={v.po_number}
            disabled={Boolean(purchaseOrder)}
            onChange={(id, row) => {
              form.setValue('purchase_order_id', id);
              form.setValue('po_number', row?.po_number ?? '');
              form.setValue('po_total_value', row?.total_value ?? null);
            }}
          />
        </Field>
        <Field label="Nomor invoice" required error={form.errors.invoice_number}>
          <Input {...form.bind('invoice_number')} autoFocus />
        </Field>
        <Field label="Tanggal invoice" required error={form.errors.invoice_date}>
          <Input type="date" {...form.bind('invoice_date')} />
        </Field>
        <Field label="Jatuh tempo" error={form.errors.due_date}>
          <Input type="date" min={v.invoice_date || undefined} {...form.bind('due_date')} />
        </Field>
        <Field
          label="Nilai invoice (Rp)"
          required
          error={form.errors.amount}
          hint={v.po_total_value ? `Nilai PO dari item: ${formatCurrency(v.po_total_value)}` : undefined}
        >
          <Input type="number" inputMode="decimal" min="0" step="any" {...form.bind('amount')} />
        </Field>
        <Field label="Jumlah dibayar (Rp)" error={form.errors.paid_amount}>
          <Input type="number" inputMode="decimal" min="0" step="any" {...form.bind('paid_amount')} />
        </Field>
        <Field label="Tanggal pembayaran" error={form.errors.payment_date}>
          <Input type="date" {...form.bind('payment_date')} />
        </Field>
        <div className="field">
          <span className="field-label">Status pembayaran</span>
          <div style={{ minHeight: 40, display: 'flex', alignItems: 'center' }}>
            <StatusBadge enumDef={PAYMENT_STATUS} value={previewStatus} />
          </div>
          <span className="field-hint">Dihitung otomatis dari nilai dan pembayaran</span>
        </div>
        <Field label="Catatan" error={form.errors.notes} className="span-2">
          <Textarea rows={2} {...form.bind('notes')} />
        </Field>
        {invoice && (
          <Checkbox label="Invoice dibatalkan" checked={Boolean(v.is_cancelled)} onChange={(event) => form.setValue('is_cancelled', event.target.checked)} />
        )}
      </div>
      <FormActions onCancel={onCancel} submitting={form.submitting} />
    </form>
  );
}

/** PO financial summary (structure provisional until the source sheet is profiled). */
export function PoFinancialForm({ record, purchaseOrder, onSaved, onCancel }) {
  const toast = useToast();
  const form = useForm({
    purchase_order_id: record?.purchase_order_id ?? purchaseOrder?.id ?? '',
    po_number: record?.po_number ?? purchaseOrder?.po_number ?? '',
    currency: record?.currency ?? '',
    po_value: record?.po_value ?? '',
    tax_amount: record?.tax_amount ?? '',
    total_amount: record?.total_amount ?? '',
    invoiced_amount: record?.invoiced_amount ?? '',
    paid_amount: record?.paid_amount ?? '',
    outstanding_amount: record?.outstanding_amount ?? '',
    notes: record?.notes ?? '',
  });
  const v = form.values;
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(
      record ? poFinancialUpdateSchema : poFinancialCreateSchema,
      async (data) => {
        const response = record ? await api.put(`/po-financials/${record.id}`, data) : await api.post('/po-financials', data);
        toast.success('Ringkasan keuangan PO disimpan.');
        onSaved(response.data);
      },
      ({ po_number: _p, ...values }) => values,
    );
  };
  const money = (name, label) => (
    <Field label={label} error={form.errors[name]}>
      <Input type="number" inputMode="decimal" step="any" {...form.bind(name)} />
    </Field>
  );
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      <div className="form-grid">
        <Field label="Nomor PO" required error={form.errors.purchase_order_id}>
          <PurchaseOrderSelect
            value={v.purchase_order_id}
            selectedLabel={v.po_number}
            disabled={Boolean(purchaseOrder || record)}
            onChange={(id, row) => {
              form.setValue('purchase_order_id', id);
              form.setValue('po_number', row?.po_number ?? '');
            }}
          />
        </Field>
        <Field label="Mata uang" error={form.errors.currency} hint="mis. IDR">
          <Input {...form.bind('currency')} maxLength={10} />
        </Field>
        {money('po_value', 'Nilai PO')}
        {money('tax_amount', 'Pajak')}
        {money('total_amount', 'Total')}
        {money('invoiced_amount', 'Sudah ditagih')}
        {money('paid_amount', 'Sudah dibayar')}
        {money('outstanding_amount', 'Sisa tagihan')}
        <Field label="Catatan" error={form.errors.notes} className="span-2">
          <Textarea rows={2} {...form.bind('notes')} />
        </Field>
      </div>
      <FormActions onCancel={onCancel} submitting={form.submitting} />
    </form>
  );
}
