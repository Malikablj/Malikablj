import { MODULE, PAYMENT_STATUS } from '@pik/shared';
import { Pencil, Plus, Wallet } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router';
import { InvoiceForm, PoFinancialForm } from '../components/domain/FinanceForms.jsx';
import { StatusBadge } from '../components/ui/Badge.jsx';
import { Button } from '../components/ui/Button.jsx';
import { Card, StatStrip } from '../components/ui/Card.jsx';
import { DataTable } from '../components/ui/DataTable.jsx';
import { DateRange, PageHeader, SearchBar } from '../components/ui/Misc.jsx';
import { Modal } from '../components/ui/Modal.jsx';
import { Pagination } from '../components/ui/Pagination.jsx';
import { FilterChips, Tabs } from '../components/ui/Tabs.jsx';
import { useAuth } from '../context/contexts.js';
import { useApi } from '../hooks/useApi.js';
import { useListParams } from '../hooks/useListParams.js';
import { formatCurrency, formatDate } from '../utils/format.js';

const INVOICE_SORT = '-invoice_date';

function editColumn(canWrite, onEdit, label) {
  if (!canWrite) return [];
  return [
    {
      key: 'actions',
      header: <span className="sr-only">Aksi</span>,
      render: (row) => (
        <Button size="sm" variant="ghost" icon onClick={() => onEdit(row)} aria-label={label(row)} title="Ubah">
          <Pencil size={15} />
        </Button>
      ),
    },
  ];
}

function InvoicesTab({ result, params, setParams, canWrite, onEdit }) {
  const { data, meta, error, loading, reload } = result;
  const overdue = params.overdue === 'true';
  const sort = params.sort ?? INVOICE_SORT;
  const totals = meta?.totals;
  const filtered = Boolean(params.q || params.payment_status.length || overdue || params.from || params.to);
  return (
    <>
      {totals && (
        <StatStrip
          items={[
            { label: filtered ? 'Nilai invoice (filter)' : 'Nilai invoice', value: formatCurrency(totals.amount) },
            { label: 'Sudah dibayar', value: formatCurrency(totals.paid_amount) },
            { label: 'Belum dibayar', value: formatCurrency(totals.open_balance) },
            { label: 'Lewat jatuh tempo', value: formatCurrency(totals.overdue_balance), className: totals.overdue_balance > 0 ? 'text-danger' : '' },
          ]}
        />
      )}
      <div className="filter-bar">
        <SearchBar value={params.q} onChange={(q) => setParams({ q })} placeholder="Cari invoice, PO atau customer…" />
        <DateRange from={params.from} to={params.to} onChange={setParams} label="Tanggal invoice" />
      </div>
      <div className="filter-bar">
        <FilterChips
          label="Status pembayaran"
          options={PAYMENT_STATUS.options}
          value={params.payment_status}
          onChange={(paymentStatus) => setParams({ payment_status: paymentStatus })}
        />
        <FilterChips
          label="Jatuh tempo"
          options={[{ value: 'overdue', label: 'Lewat jatuh tempo' }]}
          value={overdue ? ['overdue'] : []}
          onChange={(next) => setParams({ overdue: next.length ? 'true' : '' })}
        />
      </div>
      <Card>
        <DataTable
          caption="Daftar invoice"
          rows={data}
          loading={loading}
          error={error}
          onRetry={reload}
          sort={sort}
          onSort={(next) => setParams({ sort: next })}
          empty={{ icon: Wallet, title: filtered ? 'Tidak ada invoice yang cocok' : 'Belum ada invoice', text: filtered ? 'Ubah atau hapus filter.' : undefined }}
          columns={[
            {
              key: 'invoice_number',
              header: 'Invoice',
              sortKey: 'invoice_number',
              render: (row) => (
                <>
                  <span className="cell-title">{row.invoice_number}</span>
                  <div className="cell-sub">
                    <Link to={`/purchase-orders/${row.purchase_order_id}`}>{row.po_number}</Link> · {row.customer_name}
                  </div>
                </>
              ),
            },
            { key: 'invoice_date', header: 'Tanggal', className: 'nowrap', sortKey: 'invoice_date', render: (row) => formatDate(row.invoice_date) },
            {
              key: 'due_date',
              className: 'nowrap',
              header: 'Jatuh tempo',
              sortKey: 'due_date',
              render: (row) => <span className={row.is_overdue ? 'text-danger' : ''}>{formatDate(row.due_date)}</span>,
            },
            { key: 'amount', header: 'Nilai', sortKey: 'amount', align: 'right', render: (row) => formatCurrency(row.amount) },
            { key: 'paid_amount', header: 'Dibayar', align: 'right', render: (row) => formatCurrency(row.paid_amount) },
            {
              key: 'balance',
              header: 'Sisa',
              align: 'right',
              render: (row) => (row.payment_status === 'CANCELLED' ? <span className="muted">–</span> : formatCurrency(row.balance)),
            },
            { key: 'payment_status', header: 'Status', render: (row) => <StatusBadge enumDef={PAYMENT_STATUS} value={row.payment_status} /> },
            ...editColumn(canWrite, (row) => onEdit({ type: 'invoice', invoice: row }), (row) => `Ubah invoice ${row.invoice_number}`),
          ]}
          mobileCard={(row) => (
            <div className="stack-sm">
              <div className="row-between">
                <span className="cell-title">{row.invoice_number}</span>
                <StatusBadge enumDef={PAYMENT_STATUS} value={row.payment_status} />
              </div>
              <div className="cell-sub">
                {row.po_number} · {row.customer_name}
              </div>
              <div className="row-between">
                <span className={`text-sm ${row.is_overdue ? 'text-danger' : ''}`}>Jatuh tempo {formatDate(row.due_date)}</span>
                <strong className="num">{formatCurrency(row.amount)}</strong>
              </div>
              {canWrite && (
                <Button size="sm" onClick={() => onEdit({ type: 'invoice', invoice: row })}>
                  <Pencil size={14} aria-hidden="true" /> Ubah
                </Button>
              )}
            </div>
          )}
        />
        <Pagination meta={meta} onPageChange={(page) => setParams({ page }, { resetPage: false })} />
      </Card>
    </>
  );
}

function PoFinancialsTab({ result, params, setParams, canWrite, onEdit }) {
  const { data, meta, error, loading, reload } = result;
  return (
    <>
      <div className="form-alert warning" role="note" style={{ marginBottom: 16 }}>
        Struktur ringkasan keuangan PO masih sementara sampai sheet sumber di workbook diprofilkan (lihat docs/DECISIONS.md).
      </div>
      <div className="filter-bar">
        <SearchBar value={params.q} onChange={(q) => setParams({ q })} placeholder="Cari PO atau customer…" />
      </div>
      <Card>
        <DataTable
          caption="Ringkasan keuangan PO"
          rows={data}
          loading={loading}
          error={error}
          onRetry={reload}
          empty={{ icon: Wallet, title: 'Belum ada ringkasan keuangan PO' }}
          mobileCard={(row) => (
            <div className="stack-sm">
              <div className="row-between">
                <Link to={`/purchase-orders/${row.purchase_order_id}`} className="cell-title">
                  {row.po_number}
                </Link>
                <strong className="num">{formatCurrency(row.total_amount)}</strong>
              </div>
              <div className="cell-sub">
                {row.customer_name} · sisa {formatCurrency(row.outstanding_amount)}
              </div>
              {canWrite && (
                <Button size="sm" onClick={() => onEdit({ type: 'financial', record: row })}>
                  <Pencil size={14} aria-hidden="true" /> Ubah
                </Button>
              )}
            </div>
          )}
          columns={[
            {
              key: 'po_number',
              header: 'PO',
              render: (row) => (
                <>
                  <Link to={`/purchase-orders/${row.purchase_order_id}`}>{row.po_number}</Link>
                  <div className="cell-sub">{row.customer_name}</div>
                </>
              ),
            },
            { key: 'currency', header: 'Mata uang' },
            { key: 'total_amount', header: 'Total', align: 'right', render: (row) => formatCurrency(row.total_amount) },
            { key: 'invoiced_amount', header: 'Ditagih', align: 'right', render: (row) => formatCurrency(row.invoiced_amount) },
            { key: 'paid_amount', header: 'Dibayar', align: 'right', render: (row) => formatCurrency(row.paid_amount) },
            { key: 'outstanding_amount', header: 'Sisa', align: 'right', render: (row) => formatCurrency(row.outstanding_amount) },
            { key: 'computed_po_value', header: 'Nilai dari item PO', align: 'right', render: (row) => formatCurrency(row.computed_po_value) },
            ...editColumn(canWrite, (row) => onEdit({ type: 'financial', record: row }), (row) => `Ubah ringkasan ${row.po_number}`),
          ]}
        />
        <Pagination meta={meta} onPageChange={(page) => setParams({ page }, { resetPage: false })} />
      </Card>
    </>
  );
}

export function FinancePage() {
  const { can } = useAuth();
  const [params, setParams] = useListParams({ tab: 'invoices' }, ['payment_status']);
  const [modal, setModal] = useState(null);
  const tab = params.tab === 'po' ? 'po' : 'invoices';
  // One request for the visible tab, so saving from the page-level dialogs can reload it.
  const result = useApi(
    tab === 'po' ? '/po-financials' : '/invoices',
    tab === 'po'
      ? { q: params.q, page: params.page, page_size: 25 }
      : {
          q: params.q,
          payment_status: params.payment_status,
          overdue: params.overdue === 'true' ? 'true' : undefined,
          from: params.from,
          to: params.to,
          sort: params.sort ?? INVOICE_SORT,
          page: params.page,
          page_size: 25,
        },
  );
  const canWrite = can(MODULE.FINANCE, 'write');
  const close = () => setModal(null);
  const saved = () => {
    close();
    result.reload();
  };

  return (
    <>
      <PageHeader
        title="Invoice & Pembayaran"
        eyebrow="Keuangan"
        actions={
          canWrite && (
            <Button variant="primary" onClick={() => setModal({ type: tab === 'po' ? 'financial' : 'invoice' })}>
              <Plus size={16} aria-hidden="true" /> {tab === 'po' ? 'Ringkasan PO' : 'Tambah invoice'}
            </Button>
          )
        }
      />
      <Tabs
        label="Bagian keuangan"
        value={tab}
        onChange={(next) => setParams({ tab: next, q: '', payment_status: [], overdue: '', from: '', to: '', sort: '' })}
        tabs={[
          { id: 'invoices', label: 'Invoice' },
          { id: 'po', label: 'Ringkasan keuangan PO' },
        ]}
      />
      {tab === 'invoices' ? (
        <InvoicesTab result={result} params={params} setParams={setParams} canWrite={canWrite} onEdit={setModal} />
      ) : (
        <PoFinancialsTab result={result} params={params} setParams={setParams} canWrite={canWrite} onEdit={setModal} />
      )}
      <Modal open={modal?.type === 'invoice'} onClose={close} title={modal?.invoice ? 'Ubah invoice' : 'Tambah invoice'} size="wide">
        {modal?.type === 'invoice' && <InvoiceForm invoice={modal.invoice} onCancel={close} onSaved={saved} />}
      </Modal>
      <Modal open={modal?.type === 'financial'} onClose={close} title="Ringkasan keuangan PO" size="wide">
        {modal?.type === 'financial' && <PoFinancialForm record={modal.record} onCancel={close} onSaved={saved} />}
      </Modal>
    </>
  );
}
