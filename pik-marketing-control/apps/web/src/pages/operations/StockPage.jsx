import { MODULE, STOCK_TYPE } from '@pik/shared';
import { Boxes, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router';
import { StockForm } from '../../components/domain/OperationsForms.jsx';
import { StatusBadge } from '../../components/ui/Badge.jsx';
import { Button } from '../../components/ui/Button.jsx';
import { Card, KpiCard } from '../../components/ui/Card.jsx';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { Select } from '../../components/ui/Field.jsx';
import { PageHeader, SearchBar } from '../../components/ui/Misc.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { Pagination } from '../../components/ui/Pagination.jsx';
import { FilterChips, Tabs } from '../../components/ui/Tabs.jsx';
import { useAuth } from '../../context/contexts.js';
import { useApi } from '../../hooks/useApi.js';
import { useListParams } from '../../hooks/useListParams.js';
import { formatDate, formatNumber, formatQuantity } from '../../utils/format.js';

/** Current stock per type; quantities in different units are shown separately, never summed. */
function StockTotals({ totals }) {
  if (!totals?.length) return null;
  return (
    <div className="kpi-grid" style={{ gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))' }}>
      {STOCK_TYPE.values
        .map((type) => ({ type, rows: totals.filter((row) => row.stock_type === type) }))
        .filter((group) => group.rows.length)
        .map((group) => {
          const [main, ...others] = [...group.rows].sort((a, b) => b.quantity - a.quantity);
          return (
            <KpiCard
              key={group.type}
              label={STOCK_TYPE.labels[group.type]}
              small
              value={formatQuantity(main.quantity, main.unit === '-' ? '' : main.unit)}
              note={others.length ? `+ ${others.map((row) => formatQuantity(row.quantity, row.unit === '-' ? '' : row.unit)).join(' · ')}` : `${formatNumber(main.entries)} catatan terkini`}
            />
          );
        })}
    </div>
  );
}

export function StockPage() {
  const { can } = useAuth();
  const [params, setParams] = useListParams({ view: 'current' }, ['stock_type']);
  const [modal, setModal] = useState(null); // {} new, { record } correction
  const view = params.view === 'history' ? 'history' : 'current';
  const overview = useApi('/stock/overview');
  // History is always chronological (newest first); the current view can be sorted.
  const sort = view === 'history' ? undefined : params.sort;
  const { data, meta, error, loading, reload } = useApi(view === 'history' ? '/stock/history' : '/stock', {
    q: params.q,
    stock_type: params.stock_type,
    warehouse: params.warehouse,
    sort,
    page: params.page,
    page_size: 50,
  });
  const canWrite = can(MODULE.STOCK, 'write');
  const close = () => setModal(null);
  const refresh = () => {
    reload();
    overview.reload();
  };
  const filtered = Boolean(params.q || params.stock_type.length || params.warehouse);

  const columns = [
    {
      key: 'product_name',
      header: 'Produk',
      sortKey: 'product_name',
      render: (row) => (
        <>
          {row.product_id ? <Link to={`/products/${row.product_id}`}>{row.product_name}</Link> : row.product_name}
          <div className="cell-sub">{[row.product_code, row.category].filter(Boolean).join(' · ')}</div>
        </>
      ),
    },
    { key: 'stock_type', header: 'Tipe', sortKey: 'stock_type', render: (row) => <StatusBadge enumDef={STOCK_TYPE} value={row.stock_type} /> },
    { key: 'quantity', header: 'Qty', sortKey: 'quantity', align: 'right', render: (row) => formatQuantity(row.quantity, row.unit) },
    { key: 'warehouse', header: 'Gudang', sortKey: 'warehouse' },
    { key: 'stock_date', header: view === 'history' ? 'Tanggal hitung' : 'Per tanggal', sortKey: 'stock_date', render: (row) => formatDate(row.stock_date) },
    ...(view === 'history'
      ? [
          { key: 'updated_by_name', header: 'Dicatat oleh', render: (row) => row.updated_by_name ?? (row.source_sheet ? 'Migrasi' : '–') },
          { key: 'notes', header: 'Catatan', className: 'truncate' },
        ]
      : []),
    ...(canWrite
      ? [
          {
            key: 'actions',
            header: <span className="sr-only">Aksi</span>,
            render: (row) => (
              <Button size="sm" variant="ghost" icon onClick={() => setModal({ record: row })} aria-label={`Koreksi stok ${row.product_name}`} title="Koreksi">
                <Pencil size={15} />
              </Button>
            ),
          },
        ]
      : []),
  ];

  return (
    <>
      <PageHeader
        title="Stok"
        eyebrow="Posisi stok per produk"
        actions={
          canWrite && (
            <Button variant="primary" onClick={() => setModal({})}>
              <Plus size={16} aria-hidden="true" /> Catat stok
            </Button>
          )
        }
      />
      <StockTotals totals={overview.data?.totals} />
      <Tabs
        label="Tampilan stok"
        value={view}
        onChange={(next) => setParams({ view: next, sort: '' })}
        tabs={[
          { id: 'current', label: 'Stok saat ini' },
          { id: 'history', label: 'Riwayat pencatatan' },
        ]}
      />
      <div className="filter-bar">
        <SearchBar value={params.q} onChange={(q) => setParams({ q })} placeholder="Cari produk, kode atau gudang…" />
        {overview.data?.warehouses?.length > 0 && (
          <Select
            aria-label="Gudang"
            placeholder="Semua gudang"
            options={overview.data.warehouses.map((warehouse) => ({ value: warehouse, label: warehouse }))}
            value={params.warehouse ?? ''}
            onChange={(event) => setParams({ warehouse: event.target.value })}
          />
        )}
        <FilterChips label="Tipe stok" options={STOCK_TYPE.options} value={params.stock_type} onChange={(stockType) => setParams({ stock_type: stockType })} />
      </div>
      <Card>
        <DataTable
          caption={view === 'history' ? 'Riwayat pencatatan stok' : 'Stok saat ini'}
          rows={data}
          loading={loading}
          error={error}
          onRetry={reload}
          sort={sort}
          onSort={view === 'current' ? (next) => setParams({ sort: next }) : undefined}
          columns={columns}
          empty={{
            icon: Boxes,
            title: filtered ? 'Tidak ada stok yang cocok' : 'Belum ada data stok',
            text: filtered ? 'Ubah atau hapus filter.' : 'Catat hasil hitung stok per produk dan tipe (FG, WIP, Ready, Reserved).',
          }}
          mobileCard={(row) => (
            <div className="stack-sm">
              <div className="row-between">
                <span className="cell-title">{row.product_name}</span>
                <StatusBadge enumDef={STOCK_TYPE} value={row.stock_type} />
              </div>
              <div className="row-between">
                <strong className="num">{formatQuantity(row.quantity, row.unit)}</strong>
                <span className="cell-sub">{[row.warehouse, formatDate(row.stock_date)].filter(Boolean).join(' · ')}</span>
              </div>
            </div>
          )}
        />
        <Pagination meta={meta} onPageChange={(page) => setParams({ page }, { resetPage: false })} />
      </Card>
      {view === 'current' && data?.length > 0 && (
        <p className="cell-sub" style={{ marginTop: 8 }}>
          Stok saat ini = catatan terakhir per produk, tipe dan gudang. Koreksi mengubah catatan tersebut; pencatatan baru menambah riwayat.
        </p>
      )}
      <Modal open={Boolean(modal)} onClose={close} title={modal?.record ? 'Koreksi data stok' : 'Catat stok'} size="wide">
        {modal && (
          <StockForm
            record={modal.record}
            onCancel={close}
            onSaved={() => {
              close();
              refresh();
            }}
          />
        )}
      </Modal>
    </>
  );
}
