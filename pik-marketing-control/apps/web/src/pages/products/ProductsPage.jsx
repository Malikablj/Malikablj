import { MODULE, PRODUCT_STATUS } from '@pik/shared';
import { Package, Plus } from 'lucide-react';
import { useState } from 'react';
import { useNavigate } from 'react-router';
import { ProductForm } from '../../components/domain/OperationsForms.jsx';
import { Badge, StatusBadge } from '../../components/ui/Badge.jsx';
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
import { formatNumber, formatQuantity } from '../../utils/format.js';

const stockCell = (value, unit) => (value === null || value === undefined ? <span className="muted">–</span> : formatQuantity(value, unit));

export function ProductsPage() {
  const { can } = useAuth();
  const navigate = useNavigate();
  const [params, setParams] = useListParams({}, ['status']);
  const [creating, setCreating] = useState(false);
  const archived = params.archived === 'true';
  const sort = params.sort ?? 'name';
  const { data: facets } = useApi('/products/facets');
  const { data, meta, error, loading, reload } = useApi('/products', {
    q: params.q,
    category: params.category,
    status: params.status,
    is_active: archived ? 'false' : undefined,
    sort,
    page: params.page,
    page_size: 25,
  });
  const filtered = Boolean(params.q || params.category || params.status.length || archived);

  return (
    <>
      <PageHeader
        title="Produk"
        eyebrow="Master produk kemasan"
        actions={
          can(MODULE.PRODUCTS, 'write') && (
            <Button variant="primary" onClick={() => setCreating(true)}>
              <Plus size={16} aria-hidden="true" /> Tambah produk
            </Button>
          )
        }
      />
      <div className="filter-bar">
        <SearchBar value={params.q} onChange={(q) => setParams({ q })} placeholder="Cari nama, kode atau kategori…" />
        {facets?.categories?.length > 0 && (
          <Select
            aria-label="Kategori"
            placeholder="Semua kategori"
            options={facets.categories.map((category) => ({ value: category, label: category }))}
            value={params.category ?? ''}
            onChange={(event) => setParams({ category: event.target.value })}
          />
        )}
        <FilterChips label="Status produk" options={PRODUCT_STATUS.options} value={params.status} onChange={(status) => setParams({ status })} />
        <Checkbox label="Tampilkan arsip" checked={archived} onChange={(event) => setParams({ archived: event.target.checked ? 'true' : '' })} />
      </div>
      <Card>
        <DataTable
          caption="Daftar produk"
          rows={data}
          loading={loading}
          error={error}
          onRetry={reload}
          sort={sort}
          onSort={(next) => setParams({ sort: next })}
          rowHref={(row) => `/products/${row.id}`}
          empty={{
            icon: Package,
            title: filtered ? 'Tidak ada produk yang cocok' : 'Belum ada produk',
            text: filtered ? 'Ubah atau hapus filter.' : 'Tambahkan produk agar dapat dipakai di lead dan PO.',
          }}
          columns={[
            {
              key: 'name',
              header: 'Produk',
              sortKey: 'name',
              render: (row) => (
                <>
                  {row.name}
                  {row.product_code && <div className="cell-sub">{row.product_code}</div>}
                </>
              ),
            },
            { key: 'category', header: 'Kategori', sortKey: 'category' },
            { key: 'unit', header: 'Satuan' },
            { key: 'customer_name', header: 'Customer' },
            { key: 'stock_fg', header: 'Stok FG', align: 'right', render: (row) => stockCell(row.stock_fg, row.unit) },
            { key: 'stock_wip', header: 'WIP', align: 'right', render: (row) => stockCell(row.stock_wip, row.unit) },
            { key: 'lead_time_days', header: 'Lead time', align: 'right', render: (row) => (row.lead_time_days === null ? '–' : `${formatNumber(row.lead_time_days)} hari`) },
            {
              key: 'status',
              header: 'Status',
              render: (row) => (row.is_active ? <StatusBadge enumDef={PRODUCT_STATUS} value={row.status} /> : <Badge tone="danger">Diarsipkan</Badge>),
            },
          ]}
          mobileCard={(row) => (
            <div className="stack-sm">
              <div className="row-between">
                <span className="cell-title">{row.name}</span>
                <StatusBadge enumDef={PRODUCT_STATUS} value={row.status} />
              </div>
              <div className="cell-sub">
                {[row.product_code, row.category, row.stock_fg !== null ? `FG ${formatQuantity(row.stock_fg, row.unit)}` : null].filter(Boolean).join(' · ') || '–'}
              </div>
            </div>
          )}
        />
        <Pagination meta={meta} onPageChange={(page) => setParams({ page }, { resetPage: false })} />
      </Card>
      <Modal open={creating} onClose={() => setCreating(false)} title="Tambah produk" size="wide">
        {creating && <ProductForm onCancel={() => setCreating(false)} onSaved={(product) => navigate(`/products/${product.id}`)} />}
      </Modal>
    </>
  );
}
