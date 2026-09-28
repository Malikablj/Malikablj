/** Reference pickers. Large lists (customers, products, POs) are searched on the server. */
import { PO_STATUS, ROLE } from '@pik/shared';
import { useEffect, useMemo } from 'react';
import { AsyncSelect } from '../ui/AsyncSelect.jsx';
import { Select } from '../ui/Field.jsx';
import { useApi } from '../../hooks/useApi.js';
import { formatQuantity } from '../../utils/format.js';

export function CustomerSelect(props) {
  return (
    <AsyncSelect
      path="/customers"
      placeholder="Cari customer…"
      getLabel={(row) => row.name}
      getDescription={(row) => [row.customer_code, row.industry].filter(Boolean).join(' · ') || null}
      {...props}
    />
  );
}

export function ProductSelect(props) {
  return (
    <AsyncSelect
      path="/products"
      placeholder="Cari produk…"
      getLabel={(row) => (row.product_code ? `${row.product_code} · ${row.name}` : row.name)}
      getDescription={(row) => [row.category, row.unit, row.customer_name].filter(Boolean).join(' · ') || null}
      {...props}
    />
  );
}

/** statuses: optional list of PO statuses to offer (e.g. exclude cancelled POs). */
export function PurchaseOrderSelect({ customerId, statuses, ...props }) {
  return (
    <AsyncSelect
      path="/purchase-orders"
      params={{ customer_id: customerId || undefined, status: statuses }}
      placeholder="Cari nomor PO…"
      getLabel={(row) => row.po_number}
      getDescription={(row) => `${row.customer_name} · ${PO_STATUS.labels[row.status]} · outstanding ${formatQuantity(row.outstanding_quantity)}`}
      {...props}
    />
  );
}

/** Label of a PO line: "#2 · Botol 100ml · sisa 1.500 pcs". */
function poLineLabel(line) {
  const name = line.product_name ?? line.item_name ?? 'Item';
  return `#${line.line_no ?? '–'} · ${name} · sisa ${formatQuantity(line.outstanding_quantity, line.unit)}`;
}

/**
 * Lines of one PO (loaded from the PO detail). onChange(lineId, line).
 * A PO with a single line selects it automatically.
 */
export function PoLineSelect({ purchaseOrderId, value, onChange, placeholder = 'Pilih item PO', ...props }) {
  const { data } = useApi(purchaseOrderId ? `/purchase-orders/${purchaseOrderId}` : null);
  // Ignore the previous PO's lines while the newly selected PO is loading.
  const lines = useMemo(() => (data?.id === purchaseOrderId ? data.lines : []), [data, purchaseOrderId]);
  useEffect(() => {
    if (!value && lines.length === 1) onChange(lines[0].id, lines[0]);
  }, [value, lines, onChange]);
  return (
    <Select
      options={lines.map((line) => ({ value: line.id, label: poLineLabel(line) }))}
      placeholder={purchaseOrderId ? placeholder : 'Pilih PO terlebih dahulu'}
      disabled={!purchaseOrderId}
      value={value ?? ''}
      onChange={(event) => onChange(event.target.value, lines.find((line) => line.id === event.target.value) ?? null)}
      {...props}
    />
  );
}

/** Active users for owner/PIC fields. */
export function UserSelect({ placeholder = 'Pilih PIC', ...props }) {
  const { data } = useApi('/users/options');
  const options = (data ?? []).map((user) => ({ value: user.id, label: `${user.name} (${ROLE.labels[user.role]})` }));
  return <Select options={options} placeholder={placeholder} {...props} />;
}

export function ContactSelect({ customerId, placeholder = 'Tanpa kontak', ...props }) {
  const { data } = useApi(customerId ? `/customers/${customerId}/contacts` : null);
  const options = (data ?? []).map((contact) => ({
    value: contact.id,
    label: contact.position ? `${contact.name} · ${contact.position}` : contact.name,
  }));
  return <Select options={options} placeholder={placeholder} disabled={!customerId} {...props} />;
}

export function LeadSelect({ customerId, placeholder = 'Tanpa lead', ...props }) {
  const { data } = useApi(customerId ? '/leads' : null, { customer_id: customerId, page_size: 100, sort: 'name' });
  const options = (data ?? []).map((lead) => ({ value: lead.id, label: lead.name }));
  return <Select options={options} placeholder={placeholder} disabled={!customerId} {...props} />;
}

/** Owner filter for lists: all, "mine" (value "me") or a specific user. */
export function OwnerFilter({ value, onChange, label = 'PIC' }) {
  const { data } = useApi('/users/options');
  const options = [{ value: 'me', label: 'Milik saya' }, ...(data ?? []).map((user) => ({ value: user.id, label: user.name }))];
  return <Select aria-label={label} placeholder="Semua PIC" options={options} value={value ?? ''} onChange={(event) => onChange(event.target.value)} />;
}
