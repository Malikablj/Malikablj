import { MODULE } from '@pik/shared';
import { Pencil, Plus, Timer } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router';
import { LeadTimeForm } from '../../components/domain/OperationsForms.jsx';
import { Badge } from '../../components/ui/Badge.jsx';
import { Button } from '../../components/ui/Button.jsx';
import { Card } from '../../components/ui/Card.jsx';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { PageHeader, SearchBar } from '../../components/ui/Misc.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { Pagination } from '../../components/ui/Pagination.jsx';
import { useAuth } from '../../context/contexts.js';
import { useApi } from '../../hooks/useApi.js';
import { useListParams } from '../../hooks/useListParams.js';
import { formatNumber } from '../../utils/format.js';

export function LeadTimesPage() {
  const { can } = useAuth();
  const [params, setParams] = useListParams();
  const [modal, setModal] = useState(null);
  const sort = params.sort ?? 'product_name';
  const { data, meta, error, loading, reload } = useApi('/lead-times', { q: params.q, sort, page: params.page, page_size: 50 });
  const canWrite = can(MODULE.LEAD_TIME, 'write');
  const close = () => setModal(null);

  return (
    <>
      <PageHeader
        title="Lead Time"
        eyebrow="Waktu produksi per produk / customer"
        actions={
          canWrite && (
            <Button variant="primary" onClick={() => setModal({})}>
              <Plus size={16} aria-hidden="true" /> Tambah lead time
            </Button>
          )
        }
      />
      <div className="filter-bar">
        <SearchBar value={params.q} onChange={(q) => setParams({ q })} placeholder="Cari produk atau customer…" />
      </div>
      <Card>
        <DataTable
          caption="Daftar lead time"
          rows={data}
          loading={loading}
          error={error}
          onRetry={reload}
          sort={sort}
          onSort={(next) => setParams({ sort: next })}
          empty={{
            icon: Timer,
            title: params.q ? 'Tidak ada lead time yang cocok' : 'Belum ada data lead time',
            text: params.q ? undefined : 'Lead time dipakai untuk memperkirakan target pengiriman.',
          }}
          columns={[
            {
              key: 'product_name',
              header: 'Produk',
              sortKey: 'product_name',
              render: (row) => (
                <>
                  {row.product_id ? <Link to={`/products/${row.product_id}`}>{row.product_name}</Link> : (row.product_name ?? <span className="muted">Semua produk</span>)}
                  {!row.product_id && row.item_name && (
                    <div>
                      <Badge tone="warning">Belum terhubung ke master produk</Badge>
                    </div>
                  )}
                  {row.product_code && <div className="cell-sub">{row.product_code}</div>}
                </>
              ),
            },
            {
              key: 'customer_name',
              header: 'Customer',
              sortKey: 'customer_name',
              render: (row) => (row.customer_id ? <Link to={`/customers/${row.customer_id}`}>{row.customer_name}</Link> : <span className="muted">Umum</span>),
            },
            { key: 'lead_time_days', header: 'Lead time', sortKey: 'lead_time_days', align: 'right', render: (row) => `${formatNumber(row.lead_time_days)} hari` },
            { key: 'notes', header: 'Catatan', className: 'truncate' },
            ...(canWrite
              ? [
                  {
                    key: 'actions',
                    header: <span className="sr-only">Aksi</span>,
                    render: (row) => (
                      <Button size="sm" variant="ghost" icon onClick={() => setModal({ record: row })} aria-label={`Ubah lead time ${row.product_name ?? ''}`} title="Ubah">
                        <Pencil size={15} />
                      </Button>
                    ),
                  },
                ]
              : []),
          ]}
          mobileCard={(row) => (
            <div className="row-between">
              <div className="grow">
                <div className="cell-title">{row.product_name ?? 'Semua produk'}</div>
                <div className="cell-sub">{row.customer_name ?? 'Umum'}</div>
              </div>
              <strong className="num">{formatNumber(row.lead_time_days)} hari</strong>
              {canWrite && (
                <Button size="sm" variant="ghost" icon onClick={() => setModal({ record: row })} aria-label="Ubah lead time">
                  <Pencil size={15} />
                </Button>
              )}
            </div>
          )}
        />
        <Pagination meta={meta} onPageChange={(page) => setParams({ page }, { resetPage: false })} />
      </Card>
      <Modal open={Boolean(modal)} onClose={close} title={modal?.record ? 'Ubah lead time' : 'Tambah lead time'}>
        {modal && (
          <LeadTimeForm
            record={modal.record}
            onCancel={close}
            onSaved={() => {
              close();
              reload();
            }}
          />
        )}
      </Modal>
    </>
  );
}
