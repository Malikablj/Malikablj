import { MODULE, purchaseOrderCreateSchema } from '@pik/shared';
import { ChevronLeft, Plus, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { FormError } from '../../components/domain/CrmForms.jsx';
import { CustomerSelect, ProductSelect, UserSelect } from '../../components/domain/pickers.jsx';
import { Button } from '../../components/ui/Button.jsx';
import { Card, CardHeader } from '../../components/ui/Card.jsx';
import { Field, Input, Textarea } from '../../components/ui/Field.jsx';
import { PageHeader } from '../../components/ui/Misc.jsx';
import { ErrorState, LoadingState } from '../../components/ui/States.jsx';
import { useAuth, useToast } from '../../context/contexts.js';
import { useApi } from '../../hooks/useApi.js';
import { useForm } from '../../hooks/useForm.js';
import { api } from '../../services/api.js';
import { formatCurrency } from '../../utils/format.js';

const emptyLine = (key) => ({ key, product_id: '', product_name: '', order_quantity: '', unit: '', unit_price: '', notes: '' });

function LineEditor({ index, line, errors, canRemove, onChange, onRemove }) {
  const error = (field) => errors[`lines.${index}.${field}`];
  const value = Number(line.order_quantity) * Number(line.unit_price);
  return (
    <div className="card" style={{ padding: 16, marginBottom: 12, background: 'var(--color-surface-sunken)', boxShadow: 'none' }}>
      <div className="row-between" style={{ marginBottom: 8 }}>
        <strong className="text-sm">Item {index + 1}</strong>
        {canRemove && (
          <Button size="sm" variant="ghost" icon onClick={onRemove} aria-label={`Hapus item ${index + 1}`} title="Hapus item">
            <Trash2 size={15} />
          </Button>
        )}
      </div>
      <div className="form-grid">
        <Field label="Produk" required error={error('product_id')} className="span-2">
          <ProductSelect
            value={line.product_id}
            selectedLabel={line.product_name}
            onChange={(id, row) => onChange({ product_id: id, product_name: row?.name ?? '', ...(row?.unit && !line.unit ? { unit: row.unit } : {}) })}
          />
        </Field>
        <Field label="Qty order" required error={error('order_quantity')}>
          <Input type="number" inputMode="decimal" min="0" step="any" value={line.order_quantity} onChange={(event) => onChange({ order_quantity: event.target.value })} />
        </Field>
        <Field label="Satuan" error={error('unit')} hint="Kosong = satuan produk">
          <Input value={line.unit} onChange={(event) => onChange({ unit: event.target.value })} />
        </Field>
        <Field label="Harga satuan (Rp)" error={error('unit_price')} hint={Number.isFinite(value) && value > 0 ? `Nilai item ${formatCurrency(value)}` : 'Opsional'}>
          <Input type="number" inputMode="decimal" min="0" step="any" value={line.unit_price} onChange={(event) => onChange({ unit_price: event.target.value })} />
        </Field>
        <Field label="Catatan item" error={error('notes')}>
          <Input value={line.notes} onChange={(event) => onChange({ notes: event.target.value })} />
        </Field>
      </div>
    </div>
  );
}

function PurchaseOrderCreateForm({ customer }) {
  const { user, today, access } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();
  const nextKey = useRef(1);
  const [lines, setLines] = useState([emptyLine(0)]);
  const form = useForm({
    po_number: '',
    customer_id: customer?.id ?? '',
    customer_name: customer?.name ?? '',
    po_date: today,
    expected_delivery_date: '',
    owner_user_id: user.id,
    notes: '',
  });
  const v = form.values;
  const ownOnly = access(MODULE.PURCHASE_ORDERS) === 'OWN';
  const total = lines.reduce((sum, line) => {
    const value = Number(line.order_quantity) * Number(line.unit_price);
    return Number.isFinite(value) ? sum + value : sum;
  }, 0);

  const updateLine = (index, changes) => {
    setLines((current) => current.map((line, i) => (i === index ? { ...line, ...changes } : line)));
    form.setErrors((current) => {
      const next = { ...current };
      for (const field of Object.keys(changes)) delete next[`lines.${index}.${field}`];
      return next;
    });
  };
  const addLine = () => {
    const key = nextKey.current;
    nextKey.current += 1;
    setLines((current) => [...current, emptyLine(key)]);
  };
  const removeLine = (index) => {
    setLines((current) => current.filter((_, i) => i !== index));
    form.setErrors({});
  };

  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(
      purchaseOrderCreateSchema,
      async (data) => {
        const response = await api.post('/purchase-orders', data);
        toast.success(`PO ${response.data.po_number} dibuat.`);
        navigate(`/purchase-orders/${response.data.id}`, { replace: true });
      },
      ({ customer_name: _c, ...header }) => ({
        ...header,
        lines: lines.map(({ key: _k, product_name: _p, ...line }) => line),
      }),
    );
  };

  return (
    <form onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      <div className="section-grid" style={{ marginTop: form.formError ? 16 : 0 }}>
        <Card>
          <CardHeader title="Item PO" subtitle={`${lines.length} item${total > 0 ? ` · ${formatCurrency(total)}` : ''}`} />
          <div className="card-body">
            {lines.map((line, index) => (
              <LineEditor
                key={line.key}
                index={index}
                line={line}
                errors={form.errors}
                canRemove={lines.length > 1}
                onChange={(changes) => updateLine(index, changes)}
                onRemove={() => removeLine(index)}
              />
            ))}
            {form.errors.lines && <p className="field-error">{form.errors.lines}</p>}
            <Button onClick={addLine}>
              <Plus size={16} aria-hidden="true" /> Tambah item
            </Button>
          </div>
        </Card>
        <Card>
          <CardHeader title="Data PO" />
          <div className="card-body stack">
            <Field label="Nomor PO" required error={form.errors.po_number}>
              <Input {...form.bind('po_number')} autoFocus />
            </Field>
            <Field label="Customer" required error={form.errors.customer_id}>
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
            <Field label="PIC" error={form.errors.owner_user_id} hint={ownOnly ? 'PO dibuat atas nama Anda' : undefined}>
              <UserSelect {...form.bind('owner_user_id')} disabled={ownOnly} />
            </Field>
            <Field label="Catatan" error={form.errors.notes}>
              <Textarea rows={3} {...form.bind('notes')} />
            </Field>
            <div className="form-actions">
              <Button onClick={() => navigate(-1)} disabled={form.submitting}>
                Batal
              </Button>
              <Button type="submit" variant="primary" loading={form.submitting}>
                Simpan PO
              </Button>
            </div>
          </div>
        </Card>
      </div>
    </form>
  );
}

export function PurchaseOrderFormPage() {
  const [searchParams] = useSearchParams();
  const customerId = searchParams.get('customer_id');
  const { data: customer, error, reload } = useApi(customerId ? `/customers/${customerId}` : null);

  let body;
  if (customerId && error && !customer) body = <ErrorState error={error} onRetry={reload} />;
  else if (customerId && !customer) body = <LoadingState rows={6} />;
  else body = <PurchaseOrderCreateForm customer={customer} />;

  return (
    <>
      <Link to={customer ? `/customers/${customer.id}?tab=purchase-orders` : '/purchase-orders'} className="row text-sm" style={{ marginBottom: 12, gap: 4 }}>
        <ChevronLeft size={16} aria-hidden="true" /> {customer ? customer.name : 'Purchase Order'}
      </Link>
      <PageHeader title="Buat Purchase Order" eyebrow="PO baru" />
      {body}
    </>
  );
}
