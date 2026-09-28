import { DELIVERY_STATUS, MODULE } from '@pik/shared';
import { Pencil, Plus, Truck } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router';
import { DeliveryForm } from '../../components/domain/OperationsForms.jsx';
import { StatusBadge } from '../../components/ui/Badge.jsx';
import { Button } from '../../components/ui/Button.jsx';
import { Card } from '../../components/ui/Card.jsx';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { DateRange, PageHeader, SearchBar } from '../../components/ui/Misc.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { Pagination } from '../../components/ui/Pagination.jsx';
import { FilterChips } from '../../components/ui/Tabs.jsx';
import { useAuth } from '../../context/contexts.js';
import { useApi } from '../../hooks/useApi.js';
import { useListParams } from '../../hooks/useListParams.js';
import { formatDate, formatQuantity } from '../../utils/format.js';

export function DeliveriesPage() {
  const { can, today } = useAuth();
  const [params, setParams] = useListParams({}, ['status']);
  const [modal, setModal] = useState(null); // {} new, { delivery } edit
  const sort = params.sort ?? '-delivery_date';
  const { data, meta, error, loading, reload } = useApi('/deliveries', {
    q: params.q,
    status: params.status,
    from: params.from,
    to: params.to,
    sort,
    page: params.page,
    page_size: 25,
  });
  const canWrite = can(MODULE.DELIVERIES, 'write');
  const filtered = Boolean(params.q || params.status.length || params.from || params.to);
  const close = () => setModal(null);
  const isLate = (row) => (row.status === 'SCHEDULED' || row.status === 'ON_DELIVERY' || row.status === 'DELAYED') && row.delivery_date < today;

  return (
    <>
      <PageHeader
        title="Pengiriman"
        eyebrow="Jadwal dan realisasi kirim"
        actions={
          canWrite && (
            <Button variant="primary" onClick={() => setModal({})}>
              <Plus size={16} aria-hidden="true" /> Catat pengiriman
            </Button>
          )
        }
      />
      <div className="filter-bar">
        <SearchBar value={params.q} onChange={(q) => setParams({ q })} placeholder="Cari PO, customer, produk, surat jalan…" />
        <DateRange from={params.from} to={params.to} onChange={setParams} label="Tanggal kirim" />
      </div>
      <div className="filter-bar">
        <FilterChips label="Status pengiriman" options={DELIVERY_STATUS.options} value={params.status} onChange={(status) => setParams({ status })} />
      </div>
      <Card>
        <DataTable
          caption="Daftar pengiriman"
          rows={data}
          loading={loading}
          error={error}
          onRetry={reload}
          sort={sort}
          onSort={(next) => setParams({ sort: next })}
          empty={{
            icon: Truck,
            title: filtered ? 'Tidak ada pengiriman yang cocok' : 'Belum ada pengiriman',
            text: filtered ? 'Ubah atau hapus filter.' : 'Pengiriman dicatat per item PO.',
          }}
          columns={[
            {
              key: 'delivery_date',
              header: 'Tanggal',
              sortKey: 'delivery_date',
              render: (row) => <span className={isLate(row) ? 'text-danger' : ''}>{formatDate(row.delivery_date)}</span>,
            },
            {
              key: 'po_number',
              header: 'No PO',
              sortKey: 'po_number',
              render: (row) => (
                <>
                  <Link to={`/purchase-orders/${row.purchase_order_id}`}>{row.po_number}</Link>
                  <div className="cell-sub">{row.customer_name}</div>
                </>
              ),
            },
            { key: 'product_name', header: 'Item', render: (row) => `${row.line_no ? `#${row.line_no} · ` : ''}${row.product_name ?? '–'}` },
            { key: 'quantity', header: 'Qty', sortKey: 'quantity', align: 'right', render: (row) => formatQuantity(row.quantity, row.unit) },
            { key: 'delivery_number', header: 'Surat jalan' },
            { key: 'status', header: 'Status', sortKey: 'status', render: (row) => <StatusBadge enumDef={DELIVERY_STATUS} value={row.status} /> },
            ...(canWrite
              ? [
                  {
                    key: 'actions',
                    header: <span className="sr-only">Aksi</span>,
                    render: (row) => (
                      <Button size="sm" variant="ghost" icon onClick={() => setModal({ delivery: row })} aria-label={`Ubah pengiriman ${row.po_number}`} title="Ubah">
                        <Pencil size={15} />
                      </Button>
                    ),
                  },
                ]
              : []),
          ]}
          mobileCard={(row) => (
            <div className="stack-sm">
              <div className="row-between">
                <Link to={`/purchase-orders/${row.purchase_order_id}`} className="cell-title">
                  {row.po_number}
                </Link>
                <StatusBadge enumDef={DELIVERY_STATUS} value={row.status} />
              </div>
              <div className="cell-sub">
                {row.customer_name} · {row.product_name}
              </div>
              <div className="row-between">
                <span className={`text-sm ${isLate(row) ? 'text-danger' : ''}`}>
                  {formatDate(row.delivery_date)} · {formatQuantity(row.quantity, row.unit)}
                </span>
                {canWrite && (
                  <Button size="sm" variant="ghost" icon onClick={() => setModal({ delivery: row })} aria-label={`Ubah pengiriman ${row.po_number}`}>
                    <Pencil size={15} />
                  </Button>
                )}
              </div>
            </div>
          )}
        />
        <Pagination meta={meta} onPageChange={(page) => setParams({ page }, { resetPage: false })} />
      </Card>
      <Modal open={Boolean(modal)} onClose={close} title={modal?.delivery ? 'Ubah pengiriman' : 'Catat pengiriman'} size="wide">
        {modal && (
          <DeliveryForm
            delivery={modal.delivery}
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
