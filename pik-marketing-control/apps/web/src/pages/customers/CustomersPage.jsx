import { CUSTOMER_STATUS, MODULE } from '@pik/shared';
import { Building2, Plus } from 'lucide-react';
import { useState } from 'react';
import { useNavigate } from 'react-router';
import { CustomerForm } from '../../components/domain/CrmForms.jsx';
import { StatusBadge } from '../../components/ui/Badge.jsx';
import { Button } from '../../components/ui/Button.jsx';
import { Card } from '../../components/ui/Card.jsx';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { Checkbox, Select } from '../../components/ui/Field.jsx';
import { PageHeader, SearchBar } from '../../components/ui/Misc.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { Pagination } from '../../components/ui/Pagination.jsx';
import { FilterChips } from '../../components/ui/Tabs.jsx';
import { useAuth } from '../../context/contexts.js';
import { useApi } from '../../hooks/useApi.js';
import { useListParams } from '../../hooks/useListParams.js';
import { formatDateTime, formatNumber } from '../../utils/format.js';

export function CustomersPage() {
  const { can } = useAuth();
  const navigate = useNavigate();
  const [creating, setCreating] = useState(false);
  const [params, setParams] = useListParams({}, ['status']);
  const archived = params.archived === '1';
  const { data, meta, error, loading, reload } = useApi('/customers', {
    q: params.q,
    status: params.status,
    industry: params.industry,
    is_active: archived ? 'false' : undefined,
    sort: params.sort ?? 'name',
    page: params.page,
    page_size: 25,
  });
  const { data: facets } = useApi('/customers/facets');
  const filtered = Boolean(params.q || params.status.length || params.industry || archived);

  const columns = [
    {
      key: 'name',
      header: 'Customer',
      sortKey: 'name',
      render: (row) => (
        <>
          {row.name}
          {row.customer_code && <div className="cell-sub">{row.customer_code}</div>}
        </>
      ),
    },
    { key: 'industry', header: 'Industri' },
    { key: 'status', header: 'Status', sortKey: 'status', render: (row) => <StatusBadge enumDef={CUSTOMER_STATUS} value={row.status} /> },
    {
      key: 'primary_contact_name',
      header: 'Kontak utama',
      render: (row) =>
        row.primary_contact_name ? (
          <>
            {row.primary_contact_name}
            <div className="cell-sub">{row.primary_contact_phone}</div>
          </>
        ) : (
          <span className="muted">–</span>
        ),
    },
    { key: 'active_leads', header: 'Lead aktif', align: 'right', render: (row) => formatNumber(row.active_leads) },
    { key: 'open_purchase_orders', header: 'PO open', align: 'right', render: (row) => formatNumber(row.open_purchase_orders) },
    {
      key: 'last_activity_at',
      header: 'Aktivitas terakhir',
      sortKey: 'last_activity_at',
      render: (row) => (row.last_activity_at ? formatDateTime(row.last_activity_at) : <span className="muted">Belum ada</span>),
    },
  ];

  return (
    <>
      <PageHeader
        title="Customer"
        eyebrow={meta ? `${formatNumber(meta.total)} customer${archived ? ' diarsipkan' : ''}` : ' '}
        actions={
          can(MODULE.CUSTOMERS, 'write') && (
            <Button variant="primary" onClick={() => setCreating(true)}>
              <Plus size={16} aria-hidden="true" /> Tambah Customer
            </Button>
          )
        }
      />
      <div className="filter-bar">
        <SearchBar value={params.q} onChange={(q) => setParams({ q })} placeholder="Cari nama, kode, email, telepon…" />
        <Select
          aria-label="Industri"
          placeholder="Semua industri"
          options={(facets?.industries ?? []).map((value) => ({ value, label: value }))}
          value={params.industry ?? ''}
          onChange={(event) => setParams({ industry: event.target.value })}
        />
        <Checkbox label="Tampilkan arsip" checked={archived} onChange={(event) => setParams({ archived: event.target.checked ? '1' : '' })} />
      </div>
      <div className="filter-bar">
        <FilterChips label="Status" options={CUSTOMER_STATUS.options} value={params.status} onChange={(status) => setParams({ status })} />
      </div>
      <Card>
        <DataTable
          caption="Daftar customer"
          columns={columns}
          rows={data}
          loading={loading}
          error={error}
          onRetry={reload}
          sort={params.sort ?? 'name'}
          onSort={(sort) => setParams({ sort })}
          rowHref={(row) => `/customers/${row.id}`}
          empty={{
            icon: Building2,
            title: filtered ? 'Tidak ada customer yang cocok' : 'Belum ada customer',
            text: filtered ? 'Ubah kata kunci atau filter.' : 'Tambahkan customer pertama, atau jalankan migrasi data dari workbook.',
          }}
          mobileCard={(row) => (
            <div className="row-between">
              <div className="grow">
                <div className="cell-title">{row.name}</div>
                <div className="cell-sub">{[row.customer_code, row.industry, row.primary_contact_name].filter(Boolean).join(' · ') || '–'}</div>
              </div>
              <StatusBadge enumDef={CUSTOMER_STATUS} value={row.status} />
            </div>
          )}
        />
        <Pagination meta={meta} onPageChange={(page) => setParams({ page }, { resetPage: false })} />
      </Card>
      <Modal open={creating} onClose={() => setCreating(false)} title="Tambah customer" size="wide">
        <CustomerForm onCancel={() => setCreating(false)} onSaved={(customer) => navigate(`/customers/${customer.id}`)} />
      </Modal>
    </>
  );
}
