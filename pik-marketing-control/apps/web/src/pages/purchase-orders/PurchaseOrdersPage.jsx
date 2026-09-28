import { MODULE, PO_STATUS } from '@pik/shared';
import { FileText, Plus } from 'lucide-react';
import { OwnerFilter } from '../../components/domain/pickers.jsx';
import { Badge, StatusBadge } from '../../components/ui/Badge.jsx';
import { Button } from '../../components/ui/Button.jsx';
import { Card } from '../../components/ui/Card.jsx';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { PageHeader, Progress, SearchBar } from '../../components/ui/Misc.jsx';
import { Pagination } from '../../components/ui/Pagination.jsx';
import { FilterChips } from '../../components/ui/Tabs.jsx';
import { useAuth } from '../../context/contexts.js';
import { useApi } from '../../hooks/useApi.js';
import { useListParams } from '../../hooks/useListParams.js';
import { formatCurrency, formatDate, formatNumber } from '../../utils/format.js';

const QUICK_FILTERS = [
  { value: 'outstanding', label: 'Masih outstanding' },
  { value: 'late', label: 'Lewat target kirim' },
];

export function PurchaseOrdersPage() {
  const { can, user } = useAuth();
  const [params, setParams] = useListParams({}, ['status']);
  const owner = params.owner ?? '';
  const quick = [params.has_outstanding === 'true' && 'outstanding', params.late === 'true' && 'late'].filter(Boolean);
  const sort = params.sort ?? '-po_date';
  const { data, meta, error, loading, reload } = useApi('/purchase-orders', {
    q: params.q,
    status: params.status,
    owner_user_id: owner === 'me' ? user.id : owner || undefined,
    has_outstanding: params.has_outstanding,
    late: params.late,
    sort,
    page: params.page,
    page_size: 25,
  });
  const filtered = Boolean(params.q || params.status.length || owner || quick.length);

  return (
    <>
      <PageHeader
        title="Purchase Order"
        eyebrow="Order customer dan outstanding"
        actions={
          can(MODULE.PURCHASE_ORDERS, 'write') && (
            <Button variant="primary" to="/purchase-orders/new">
              <Plus size={16} aria-hidden="true" /> Buat PO
            </Button>
          )
        }
      />
      <div className="filter-bar">
        <SearchBar value={params.q} onChange={(q) => setParams({ q })} placeholder="Cari nomor PO atau customer…" />
        <OwnerFilter value={owner} onChange={(next) => setParams({ owner: next })} />
        <FilterChips
          label="Filter cepat"
          options={QUICK_FILTERS}
          value={quick}
          onChange={(next) => setParams({ has_outstanding: next.includes('outstanding') ? 'true' : '', late: next.includes('late') ? 'true' : '' })}
        />
      </div>
      <div className="filter-bar">
        <FilterChips label="Status PO" options={PO_STATUS.options} value={params.status} onChange={(status) => setParams({ status })} />
      </div>
      <Card>
        <DataTable
          caption="Daftar purchase order"
          rows={data}
          loading={loading}
          error={error}
          onRetry={reload}
          sort={sort}
          onSort={(next) => setParams({ sort: next })}
          rowHref={(row) => `/purchase-orders/${row.id}`}
          empty={{
            icon: FileText,
            title: filtered ? 'Tidak ada PO yang cocok' : 'Belum ada purchase order',
            text: filtered ? 'Ubah atau hapus filter.' : 'PO customer yang dicatat akan tampil di sini.',
          }}
          columns={[
            {
              key: 'po_number',
              header: 'No PO',
              sortKey: 'po_number',
              render: (row) => (
                <>
                  {row.po_number}
                  <div className="cell-sub">{row.customer_name}</div>
                </>
              ),
            },
            { key: 'po_date', header: 'Tgl PO', sortKey: 'po_date', render: (row) => formatDate(row.po_date) },
            {
              key: 'expected_delivery_date',
              header: 'Target kirim',
              sortKey: 'expected_delivery_date',
              render: (row) => (
                <span className={row.is_late ? 'text-danger' : ''}>
                  {formatDate(row.expected_delivery_date)}
                  {row.is_late && <span className="sr-only"> (terlambat)</span>}
                </span>
              ),
            },
            {
              key: 'status',
              header: 'Status',
              sortKey: 'status',
              render: (row) => (
                <span className="row" style={{ gap: 4 }}>
                  <StatusBadge enumDef={PO_STATUS} value={row.status} />
                  {row.is_late && <Badge tone="danger">Terlambat</Badge>}
                </span>
              ),
            },
            {
              key: 'progress',
              header: 'Terkirim',
              render: (row) => (
                <div style={{ minWidth: 90 }}>
                  <Progress value={row.ordered_quantity - row.outstanding_quantity} total={row.ordered_quantity} />
                  <div className="cell-sub num">
                    {formatNumber(row.delivered_quantity)} / {formatNumber(row.ordered_quantity)}
                  </div>
                </div>
              ),
            },
            { key: 'outstanding_quantity', header: 'Outstanding', sortKey: 'outstanding_quantity', align: 'right', render: (row) => formatNumber(row.outstanding_quantity) },
            {
              key: 'total_value',
              header: 'Nilai',
              sortKey: 'total_value',
              align: 'right',
              render: (row) => (row.line_count > row.unpriced_line_count ? formatCurrency(row.total_value) : <span className="muted">–</span>),
            },
            { key: 'owner_name', header: 'PIC' },
          ]}
          mobileCard={(row) => (
            <div className="stack-sm">
              <div className="row-between">
                <span className="cell-title">{row.po_number}</span>
                <span className="row" style={{ gap: 4 }}>
                  {row.is_late && <Badge tone="danger">Terlambat</Badge>}
                  <StatusBadge enumDef={PO_STATUS} value={row.status} />
                </span>
              </div>
              <div className="cell-sub">
                {row.customer_name} · target {formatDate(row.expected_delivery_date)}
              </div>
              <Progress value={row.ordered_quantity - row.outstanding_quantity} total={row.ordered_quantity} />
              <div className="cell-sub num">Outstanding {formatNumber(row.outstanding_quantity)}</div>
            </div>
          )}
        />
        <Pagination meta={meta} onPageChange={(page) => setParams({ page }, { resetPage: false })} />
      </Card>
    </>
  );
}
