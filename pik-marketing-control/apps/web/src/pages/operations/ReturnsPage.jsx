import { MODULE, RETURN_STATUS } from '@pik/shared';
import { Pencil, Plus, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router';
import { ReturnForm } from '../../components/domain/OperationsForms.jsx';
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

export function ReturnsPage() {
  const { can } = useAuth();
  const [params, setParams] = useListParams({}, ['status']);
  const [modal, setModal] = useState(null); // {} new, { record } edit
  const sort = params.sort ?? '-return_date';
  const { data, meta, error, loading, reload } = useApi('/returns', {
    q: params.q,
    status: params.status,
    from: params.from,
    to: params.to,
    sort,
    page: params.page,
    page_size: 25,
  });
  const canWrite = can(MODULE.RETURNS, 'write');
  const filtered = Boolean(params.q || params.status.length || params.from || params.to);
  const close = () => setModal(null);

  return (
    <>
      <PageHeader
        title="Retur"
        eyebrow="Barang kembali dari customer"
        actions={
          canWrite && (
            <Button variant="primary" onClick={() => setModal({})}>
              <Plus size={16} aria-hidden="true" /> Catat retur
            </Button>
          )
        }
      />
      <div className="filter-bar">
        <SearchBar value={params.q} onChange={(q) => setParams({ q })} placeholder="Cari customer, PO, produk, alasan…" />
        <DateRange from={params.from} to={params.to} onChange={setParams} label="Tanggal retur" />
      </div>
      <div className="filter-bar">
        <FilterChips label="Status retur" options={RETURN_STATUS.options} value={params.status} onChange={(status) => setParams({ status })} />
      </div>
      <Card>
        <DataTable
          caption="Daftar retur"
          rows={data}
          loading={loading}
          error={error}
          onRetry={reload}
          sort={sort}
          onSort={(next) => setParams({ sort: next })}
          empty={{
            icon: Undo2,
            title: filtered ? 'Tidak ada retur yang cocok' : 'Belum ada retur',
            text: filtered ? 'Ubah atau hapus filter.' : undefined,
          }}
          columns={[
            { key: 'return_date', header: 'Tanggal', className: 'nowrap', sortKey: 'return_date', render: (row) => formatDate(row.return_date) },
            {
              key: 'customer_name',
              header: 'Customer',
              sortKey: 'customer_name',
              render: (row) => (
                <>
                  <Link to={`/customers/${row.customer_id}`}>{row.customer_name}</Link>
                  {row.po_number && (
                    <div className="cell-sub">
                      PO <Link to={`/purchase-orders/${row.purchase_order_id}`}>{row.po_number}</Link>
                    </div>
                  )}
                </>
              ),
            },
            { key: 'product_name', header: 'Produk' },
            { key: 'quantity', header: 'Qty', sortKey: 'quantity', align: 'right', render: (row) => formatQuantity(row.quantity, row.unit) },
            { key: 'reason', header: 'Alasan', className: 'truncate', render: (row) => <span title={row.reason}>{row.reason}</span> },
            { key: 'status', header: 'Status', sortKey: 'status', render: (row) => <StatusBadge enumDef={RETURN_STATUS} value={row.status} /> },
            ...(canWrite
              ? [
                  {
                    key: 'actions',
                    header: <span className="sr-only">Aksi</span>,
                    render: (row) => (
                      <Button size="sm" variant="ghost" icon onClick={() => setModal({ record: row })} aria-label="Ubah retur" title="Ubah">
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
                <span className="cell-title">{row.customer_name}</span>
                <StatusBadge enumDef={RETURN_STATUS} value={row.status} />
              </div>
              <div className="cell-sub">
                {formatDate(row.return_date)} · {row.product_name} · {formatQuantity(row.quantity, row.unit)}
              </div>
              <div className="row-between">
                <span className="text-sm">{row.reason}</span>
                {canWrite && (
                  <Button size="sm" variant="ghost" icon onClick={() => setModal({ record: row })} aria-label="Ubah retur">
                    <Pencil size={15} />
                  </Button>
                )}
              </div>
            </div>
          )}
        />
        <Pagination meta={meta} onPageChange={(page) => setParams({ page }, { resetPage: false })} />
      </Card>
      <Modal open={Boolean(modal)} onClose={close} title={modal?.record ? 'Ubah retur' : 'Catat retur'} size="wide">
        {modal && (
          <ReturnForm
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
