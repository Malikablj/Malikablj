import { MODULE } from '@pik/shared';
import { PackagePlus, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router';
import { InboundMaklonForm } from '../../components/domain/OperationsForms.jsx';
import { Button } from '../../components/ui/Button.jsx';
import { Card } from '../../components/ui/Card.jsx';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { DateRange, PageHeader, SearchBar } from '../../components/ui/Misc.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { Pagination } from '../../components/ui/Pagination.jsx';
import { useAuth } from '../../context/contexts.js';
import { useApi } from '../../hooks/useApi.js';
import { useListParams } from '../../hooks/useListParams.js';
import { formatDate, formatQuantity } from '../../utils/format.js';

export function InboundMaklonPage() {
  const { can } = useAuth();
  const [params, setParams] = useListParams();
  const [modal, setModal] = useState(null);
  const sort = params.sort ?? '-inbound_date';
  const { data, meta, error, loading, reload } = useApi('/inbound-maklon', {
    q: params.q,
    from: params.from,
    to: params.to,
    sort,
    page: params.page,
    page_size: 25,
  });
  const canWrite = can(MODULE.INBOUND_MAKLON, 'write');
  const filtered = Boolean(params.q || params.from || params.to);
  const close = () => setModal(null);

  return (
    <>
      <PageHeader
        title="Maklon Masuk"
        eyebrow="Barang/bahan maklon yang diterima"
        actions={
          canWrite && (
            <Button variant="primary" onClick={() => setModal({})}>
              <Plus size={16} aria-hidden="true" /> Catat maklon masuk
            </Button>
          )
        }
      />
      <div className="form-alert warning" role="note" style={{ marginBottom: 16 }}>
        Struktur data maklon masuk masih sementara sampai sheet sumber di workbook diprofilkan (lihat docs/DECISIONS.md).
      </div>
      <div className="filter-bar">
        <SearchBar value={params.q} onChange={(q) => setParams({ q })} placeholder="Cari dokumen, barang, customer, PO…" />
        <DateRange from={params.from} to={params.to} onChange={setParams} label="Tanggal masuk" />
      </div>
      <Card>
        <DataTable
          caption="Daftar maklon masuk"
          rows={data}
          loading={loading}
          error={error}
          onRetry={reload}
          sort={sort}
          onSort={(next) => setParams({ sort: next })}
          empty={{
            icon: PackagePlus,
            title: filtered ? 'Tidak ada data yang cocok' : 'Belum ada data maklon masuk',
            text: filtered ? 'Ubah atau hapus filter.' : undefined,
          }}
          columns={[
            { key: 'inbound_date', header: 'Tanggal', sortKey: 'inbound_date', render: (row) => formatDate(row.inbound_date) },
            {
              key: 'customer_name',
              header: 'Customer',
              sortKey: 'customer_name',
              render: (row) => (
                <>
                  {row.customer_id ? <Link to={`/customers/${row.customer_id}`}>{row.customer_name}</Link> : <span className="muted">–</span>}
                  {row.po_number && (
                    <div className="cell-sub">
                      PO <Link to={`/purchase-orders/${row.purchase_order_id}`}>{row.po_number}</Link>
                    </div>
                  )}
                </>
              ),
            },
            { key: 'product_name', header: 'Barang' },
            { key: 'quantity', header: 'Qty', sortKey: 'quantity', align: 'right', render: (row) => formatQuantity(row.quantity, row.unit) },
            { key: 'document_number', header: 'No dokumen' },
            ...(canWrite
              ? [
                  {
                    key: 'actions',
                    header: <span className="sr-only">Aksi</span>,
                    render: (row) => (
                      <Button size="sm" variant="ghost" icon onClick={() => setModal({ record: row })} aria-label="Ubah data maklon masuk" title="Ubah">
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
                <div className="cell-title">{row.product_name ?? '–'}</div>
                <div className="cell-sub">{[formatDate(row.inbound_date), row.customer_name, row.document_number].filter(Boolean).join(' · ')}</div>
              </div>
              <strong className="num">{formatQuantity(row.quantity, row.unit)}</strong>
              {canWrite && (
                <Button size="sm" variant="ghost" icon onClick={() => setModal({ record: row })} aria-label="Ubah data maklon masuk">
                  <Pencil size={15} />
                </Button>
              )}
            </div>
          )}
        />
        <Pagination meta={meta} onPageChange={(page) => setParams({ page }, { resetPage: false })} />
      </Card>
      <Modal open={Boolean(modal)} onClose={close} title={modal?.record ? 'Ubah maklon masuk' : 'Catat maklon masuk'} size="wide">
        {modal && (
          <InboundMaklonForm
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
