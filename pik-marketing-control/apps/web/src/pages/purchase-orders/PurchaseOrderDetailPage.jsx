import { checkPoTransition, DELIVERY_STATUS, MODULE, PAYMENT_STATUS, PO_STATUS, RETURN_STATUS } from '@pik/shared';
import { ChevronLeft, Pencil, Plus, Trash2, Truck, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { InvoiceForm, PoFinancialForm } from '../../components/domain/FinanceForms.jsx';
import { DeliveryForm, PoLineForm, PoStatusForm, PurchaseOrderHeaderForm, ReturnForm } from '../../components/domain/OperationsForms.jsx';
import { Badge, StatusBadge } from '../../components/ui/Badge.jsx';
import { Button } from '../../components/ui/Button.jsx';
import { Card, CardHeader, StatStrip } from '../../components/ui/Card.jsx';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { Select } from '../../components/ui/Field.jsx';
import { DetailList, PageHeader, Progress } from '../../components/ui/Misc.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { ErrorState, LoadingState } from '../../components/ui/States.jsx';
import { useAuth, useConfirm, useToast } from '../../context/contexts.js';
import { useApi } from '../../hooks/useApi.js';
import { api } from '../../services/api.js';
import { formatCurrency, formatDate, formatDateTime, formatNumber, formatQuantity, relativeDay } from '../../utils/format.js';

function LinesCard({ po, editable, canDeliver, onAction }) {
  const confirm = useConfirm();
  const toast = useToast();
  const removeLine = async (line) => {
    const ok = await confirm({
      title: 'Hapus item PO?',
      message: `Item #${line.line_no} (${line.product_name ?? line.item_name}) akan dihapus dari PO. Hanya item tanpa pengiriman/retur yang dapat dihapus.`,
      confirmLabel: 'Hapus item',
      tone: 'danger',
    });
    if (!ok) return;
    try {
      await api.delete(`/po-lines/${line.id}`);
      toast.success('Item PO dihapus.');
      onAction({ type: 'reload' });
    } catch (err) {
      toast.error(err.message);
    }
  };
  const lineUsed = (line) => Number(line.delivered_quantity) + Number(line.in_progress_quantity) + Number(line.returned_quantity) > 0;

  return (
    <Card>
      <CardHeader
        title="Item PO"
        subtitle={`${po.lines.length} item`}
        actions={
          editable &&
          po.status !== 'CLOSED' && (
            <Button size="sm" onClick={() => onAction({ type: 'line' })}>
              <Plus size={15} aria-hidden="true" /> Item
            </Button>
          )
        }
      />
      <DataTable
        caption="Item PO"
        rows={po.lines}
        empty={{ title: 'PO belum memiliki item' }}
        columns={[
          { key: 'line_no', header: '#', render: (line) => line.line_no ?? '–' },
          {
            key: 'product',
            header: 'Produk',
            render: (line) => (
              <>
                {line.product_id ? <Link to={`/products/${line.product_id}`}>{line.product_name}</Link> : <span>{line.item_name}</span>}
                {!line.product_id && (
                  <div>
                    <Badge tone="warning">Belum terhubung ke master produk</Badge>
                  </div>
                )}
                {line.product_code && <div className="cell-sub">{line.product_code}</div>}
                {line.notes && <div className="cell-sub">{line.notes}</div>}
              </>
            ),
          },
          { key: 'order_quantity', header: 'Order', align: 'right', render: (line) => formatQuantity(line.order_quantity, line.unit) },
          {
            key: 'delivered_quantity',
            header: 'Terkirim',
            align: 'right',
            render: (line) => (
              <>
                {formatNumber(line.delivered_quantity)}
                {line.in_progress_quantity > 0 && <div className="cell-sub">+{formatNumber(line.in_progress_quantity)} dalam proses</div>}
              </>
            ),
          },
          { key: 'returned_quantity', header: 'Retur', align: 'right', render: (line) => formatNumber(line.returned_quantity) },
          {
            key: 'outstanding_quantity',
            header: 'Outstanding',
            align: 'right',
            render: (line) => (
              <div style={{ minWidth: 90 }}>
                <strong className={line.outstanding_quantity > 0 ? '' : 'text-success'}>{formatNumber(line.outstanding_quantity)}</strong>
                <Progress value={line.order_quantity - line.outstanding_quantity} total={line.order_quantity} />
              </div>
            ),
          },
          { key: 'unit_price', header: 'Harga', align: 'right', render: (line) => formatCurrency(line.unit_price) },
          { key: 'line_value', header: 'Nilai', align: 'right', render: (line) => formatCurrency(line.line_value) },
          {
            key: 'actions',
            header: <span className="sr-only">Aksi</span>,
            render: (line) => (
              <span className="row nowrap" style={{ gap: 2, justifyContent: 'flex-end' }}>
                {canDeliver && line.outstanding_quantity > 0 && (
                  <Button size="sm" variant="ghost" icon onClick={() => onAction({ type: 'delivery', line })} aria-label={`Catat pengiriman item ${line.line_no}`} title="Catat pengiriman">
                    <Truck size={15} />
                  </Button>
                )}
                {editable && (
                  <Button size="sm" variant="ghost" icon onClick={() => onAction({ type: 'line', line })} aria-label={`Ubah item ${line.line_no}`} title="Ubah item">
                    <Pencil size={15} />
                  </Button>
                )}
                {editable && po.lines.length > 1 && !lineUsed(line) && (
                  <Button size="sm" variant="ghost" icon onClick={() => removeLine(line)} aria-label={`Hapus item ${line.line_no}`} title="Hapus item">
                    <Trash2 size={15} />
                  </Button>
                )}
              </span>
            ),
          },
        ]}
        mobileCard={(line) => (
          <div className="stack-sm">
            <div className="row-between">
              <span className="cell-title">
                #{line.line_no} · {line.product_name ?? line.item_name}
              </span>
              <span className="row" style={{ gap: 2 }}>
                {canDeliver && line.outstanding_quantity > 0 && (
                  <Button size="sm" variant="ghost" icon onClick={() => onAction({ type: 'delivery', line })} aria-label={`Catat pengiriman item ${line.line_no}`}>
                    <Truck size={15} />
                  </Button>
                )}
                {editable && (
                  <Button size="sm" variant="ghost" icon onClick={() => onAction({ type: 'line', line })} aria-label={`Ubah item ${line.line_no}`}>
                    <Pencil size={15} />
                  </Button>
                )}
              </span>
            </div>
            <Progress value={line.order_quantity - line.outstanding_quantity} total={line.order_quantity} />
            <div className="cell-sub num">
              Order {formatQuantity(line.order_quantity, line.unit)} · kirim {formatNumber(line.delivered_quantity)} · retur {formatNumber(line.returned_quantity)} · sisa{' '}
              <strong>{formatNumber(line.outstanding_quantity)}</strong>
            </div>
            {line.line_value !== null && <div className="cell-sub">{formatCurrency(line.line_value)}</div>}
          </div>
        )}
      />
    </Card>
  );
}

function DeliveriesCard({ po, canWrite, onAction }) {
  return (
    <Card>
      <CardHeader
        title="Pengiriman"
        subtitle={`${po.deliveries.length} catatan`}
        actions={
          canWrite &&
          po.status !== 'CANCELLED' && (
            <Button size="sm" onClick={() => onAction({ type: 'delivery' })}>
              <Plus size={15} aria-hidden="true" /> Pengiriman
            </Button>
          )
        }
      />
      <DataTable
        caption="Pengiriman PO"
        rows={po.deliveries}
        empty={{ icon: Truck, title: 'Belum ada pengiriman' }}
        columns={[
          { key: 'delivery_date', header: 'Tanggal', className: 'nowrap', render: (row) => formatDate(row.delivery_date) },
          { key: 'product_name', header: 'Item', render: (row) => `${row.line_no ? `#${row.line_no} · ` : ''}${row.product_name ?? '–'}` },
          { key: 'quantity', header: 'Qty', align: 'right', render: (row) => formatQuantity(row.quantity, row.unit) },
          { key: 'delivery_number', header: 'Surat jalan' },
          { key: 'status', header: 'Status', render: (row) => <StatusBadge enumDef={DELIVERY_STATUS} value={row.status} /> },
          ...(canWrite
            ? [
                {
                  key: 'actions',
                  header: <span className="sr-only">Aksi</span>,
                  render: (row) => (
                    <Button size="sm" variant="ghost" icon onClick={() => onAction({ type: 'delivery', delivery: row })} aria-label="Ubah pengiriman" title="Ubah">
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
              <div className="cell-title">
                {formatDate(row.delivery_date)} · {formatQuantity(row.quantity, row.unit)}
              </div>
              <div className="cell-sub">{[row.product_name, row.delivery_number].filter(Boolean).join(' · ')}</div>
            </div>
            <StatusBadge enumDef={DELIVERY_STATUS} value={row.status} />
            {canWrite && (
              <Button size="sm" variant="ghost" icon onClick={() => onAction({ type: 'delivery', delivery: row })} aria-label="Ubah pengiriman">
                <Pencil size={15} />
              </Button>
            )}
          </div>
        )}
      />
    </Card>
  );
}

function ReturnsCard({ po, canWrite, onAction }) {
  return (
    <Card>
      <CardHeader
        title="Retur"
        subtitle={`${po.returns.length} catatan`}
        actions={
          canWrite && (
            <Button size="sm" onClick={() => onAction({ type: 'return' })}>
              <Undo2 size={15} aria-hidden="true" /> Retur
            </Button>
          )
        }
      />
      <DataTable
        caption="Retur PO"
        rows={po.returns}
        empty={{ icon: Undo2, title: 'Belum ada retur' }}
        mobileCard={(row) => (
          <div className="row-between">
            <div className="grow">
              <div className="cell-title">
                {formatDate(row.return_date)} · {formatQuantity(row.quantity, row.unit)}
              </div>
              <div className="cell-sub">{[row.product_name, row.reason].filter(Boolean).join(' · ')}</div>
            </div>
            <StatusBadge enumDef={RETURN_STATUS} value={row.status} />
            {canWrite && (
              <Button size="sm" variant="ghost" icon onClick={() => onAction({ type: 'return', record: row })} aria-label="Ubah retur">
                <Pencil size={15} />
              </Button>
            )}
          </div>
        )}
        columns={[
          { key: 'return_date', header: 'Tanggal', className: 'nowrap', render: (row) => formatDate(row.return_date) },
          { key: 'product_name', header: 'Produk' },
          { key: 'quantity', header: 'Qty', align: 'right', render: (row) => formatQuantity(row.quantity, row.unit) },
          { key: 'reason', header: 'Alasan' },
          { key: 'status', header: 'Status', render: (row) => <StatusBadge enumDef={RETURN_STATUS} value={row.status} /> },
          ...(canWrite
            ? [
                {
                  key: 'actions',
                  header: <span className="sr-only">Aksi</span>,
                  render: (row) => (
                    <Button size="sm" variant="ghost" icon onClick={() => onAction({ type: 'return', record: row })} aria-label="Ubah retur" title="Ubah">
                      <Pencil size={15} />
                    </Button>
                  ),
                },
              ]
            : []),
        ]}
      />
    </Card>
  );
}

function FinanceCard({ po, canWrite, onAction }) {
  const financial = po.financials?.[0];
  return (
    <Card>
      <CardHeader
        title="Invoice & pembayaran"
        actions={
          canWrite && (
            <Button size="sm" onClick={() => onAction({ type: 'invoice' })}>
              <Plus size={15} aria-hidden="true" /> Invoice
            </Button>
          )
        }
      />
      {po.invoices.length ? (
        <ul className="list-plain">
          {po.invoices.map((invoice) => (
            <li key={invoice.id} className="list-item" style={{ alignItems: 'center' }}>
              <div className="grow">
                <div className="cell-title">{invoice.invoice_number}</div>
                <div className={`cell-sub ${invoice.is_overdue ? 'text-danger' : ''}`}>
                  {formatDate(invoice.invoice_date)}
                  {invoice.due_date && ` · jatuh tempo ${formatDate(invoice.due_date)}`}
                </div>
              </div>
              <div style={{ textAlign: 'right' }}>
                <div className="num">{formatCurrency(invoice.amount)}</div>
                <StatusBadge enumDef={PAYMENT_STATUS} value={invoice.payment_status} />
              </div>
              {canWrite && (
                <Button size="sm" variant="ghost" icon onClick={() => onAction({ type: 'invoice', invoice })} aria-label={`Ubah invoice ${invoice.invoice_number}`} title="Ubah">
                  <Pencil size={15} />
                </Button>
              )}
            </li>
          ))}
        </ul>
      ) : (
        <p className="card-body text-sm muted" style={{ margin: 0 }}>
          Belum ada invoice.
        </p>
      )}
      {(financial || canWrite) && (
        <div className="card-footer">
          {financial ? (
            <div className="row-between text-sm">
              <span>
                Ringkasan keuangan: total {formatCurrency(financial.total_amount)} · dibayar {formatCurrency(financial.paid_amount)} · sisa{' '}
                {formatCurrency(financial.outstanding_amount)}
              </span>
              {canWrite && (
                <Button size="sm" variant="ghost" onClick={() => onAction({ type: 'financial', record: financial })}>
                  Ubah
                </Button>
              )}
            </div>
          ) : (
            <Button size="sm" variant="ghost" onClick={() => onAction({ type: 'financial' })}>
              <Plus size={15} aria-hidden="true" /> Ringkasan keuangan PO
            </Button>
          )}
        </div>
      )}
    </Card>
  );
}

export function PurchaseOrderDetailPage() {
  const { id } = useParams();
  const { can, user, today } = useAuth();
  const { data: po, error, reload, setData } = useApi(`/purchase-orders/${id}`);
  const [modal, setModal] = useState(null);

  if (error && !po) return <ErrorState error={error} onRetry={reload} />;
  if (!po) return <LoadingState rows={8} />;

  const cancelled = po.status === 'CANCELLED';
  const editable = po.can_edit && !cancelled;
  const canDeliver = can(MODULE.DELIVERIES, 'write') && !cancelled;
  const canReturn = can(MODULE.RETURNS, 'write');
  const canFinance = can(MODULE.FINANCE, 'write');
  const close = () => setModal(null);
  const saved = () => {
    close();
    reload();
  };
  /** Line and status endpoints return the refreshed PO: use it directly. */
  const savedDetail = (detail) => {
    close();
    if (detail?.lines) setData(detail);
    else reload();
  };
  const onAction = (action) => (action.type === 'reload' ? reload() : setModal(action));
  const statusOptions = PO_STATUS.values
    .filter((status) => status !== po.status && checkPoTransition({ from: po.status, to: status, role: user.role, cancelReason: 'x' }).ok)
    .map((status) => ({ value: status, label: status === 'CANCELLED' ? 'Batalkan PO…' : `Ubah ke ${PO_STATUS.labels[status]}` }));

  return (
    <>
      <Link to="/purchase-orders" className="row text-sm" style={{ marginBottom: 12, gap: 4 }}>
        <ChevronLeft size={16} aria-hidden="true" /> Purchase Order
      </Link>
      <PageHeader
        title={`PO ${po.po_number}`}
        actions={
          <>
            {po.can_edit && statusOptions.length > 0 && (
              <Select
                aria-label="Ubah status PO"
                placeholder="Ubah status…"
                options={statusOptions}
                value=""
                onChange={(event) => event.target.value && setModal({ type: 'status', status: event.target.value })}
                style={{ width: 'auto' }}
              />
            )}
            {canDeliver && po.outstanding_quantity > 0 && (
              <Button variant="primary" onClick={() => setModal({ type: 'delivery' })}>
                <Truck size={16} aria-hidden="true" /> Catat pengiriman
              </Button>
            )}
            {editable && (
              <Button onClick={() => setModal({ type: 'header' })}>
                <Pencil size={15} aria-hidden="true" /> Ubah
              </Button>
            )}
          </>
        }
      >
        <div className="row" style={{ gap: 8 }}>
          <StatusBadge enumDef={PO_STATUS} value={po.status} />
          {po.is_late && <Badge tone="danger">Lewat target kirim</Badge>}
          <Link to={`/customers/${po.customer_id}`} className="text-sm">
            {po.customer_name}
          </Link>
        </div>
      </PageHeader>

      {cancelled && (
        <div className="form-alert" role="status" style={{ marginBottom: 16 }}>
          PO dibatalkan{po.cancelled_at ? ` pada ${formatDateTime(po.cancelled_at)}` : ''}. Alasan: {po.cancel_reason ?? '–'}
        </div>
      )}
      {(po.unallocated_delivered_quantity > 0 || po.unallocated_returned_quantity > 0) && (
        <div className="form-alert warning" role="status" style={{ marginBottom: 16 }}>
          Ada pengiriman ({formatNumber(po.unallocated_delivered_quantity)}) atau retur ({formatNumber(po.unallocated_returned_quantity)}) hasil migrasi yang belum terhubung ke
          item PO, sehingga belum dihitung dalam outstanding per item. Periksa di menu Migration Issues.
        </div>
      )}

      <StatStrip
        items={[
          { label: 'Qty order', value: formatNumber(po.ordered_quantity) },
          { label: 'Terkirim', value: formatNumber(po.delivered_quantity) },
          { label: 'Dalam pengiriman', value: formatNumber(po.in_progress_quantity) },
          { label: 'Retur', value: formatNumber(po.returned_quantity) },
          { label: 'Outstanding', value: formatNumber(po.outstanding_quantity), className: po.outstanding_quantity > 0 ? '' : 'text-success' },
          {
            label: po.unpriced_line_count > 0 ? 'Nilai (sebagian item tanpa harga)' : 'Nilai PO',
            value: po.line_count > po.unpriced_line_count ? formatCurrency(po.total_value) : '–',
          },
        ]}
      />

      <LinesCard po={po} editable={editable} canDeliver={canDeliver} onAction={onAction} />

      <div className="section-grid">
        <div>
          <DeliveriesCard po={po} canWrite={canDeliver} onAction={onAction} />
          <ReturnsCard po={po} canWrite={canReturn} onAction={onAction} />
        </div>
        <div>
          <Card>
            <CardHeader title="Detail PO" />
            <div className="card-body">
              <DetailList
                items={[
                  { label: 'Customer', value: <Link to={`/customers/${po.customer_id}`}>{po.customer_name}</Link> },
                  { label: 'Tanggal PO', value: po.po_date && formatDate(po.po_date) },
                  {
                    label: 'Target kirim',
                    value: po.expected_delivery_date && (
                      <span className={po.is_late ? 'text-danger' : ''}>
                        {formatDate(po.expected_delivery_date)} ({relativeDay(po.expected_delivery_date, today)})
                      </span>
                    ),
                  },
                  { label: 'PIC', value: po.owner_name },
                  { label: 'Catatan', value: po.notes && <span className="pre-wrap">{po.notes}</span> },
                  { label: 'Dibuat', value: formatDateTime(po.created_at) },
                  { label: 'Diubah', value: formatDateTime(po.updated_at) },
                  { label: 'Sumber data', value: po.source_sheet && `Migrasi: ${po.source_file} · ${po.source_sheet} baris ${po.legacy_row}`, hidden: !po.source_sheet },
                ]}
              />
            </div>
          </Card>
          {po.invoices && <FinanceCard po={po} canWrite={canFinance} onAction={onAction} />}
        </div>
      </div>

      <Modal open={modal?.type === 'header'} onClose={close} title="Ubah data PO" size="wide">
        {modal?.type === 'header' && <PurchaseOrderHeaderForm po={po} onCancel={close} onSaved={saved} />}
      </Modal>
      <Modal open={modal?.type === 'status'} onClose={close} title={modal?.status === 'CANCELLED' ? 'Batalkan PO' : 'Ubah status PO'} size="narrow">
        {modal?.type === 'status' && <PoStatusForm po={po} status={modal.status} onCancel={close} onSaved={saved} />}
      </Modal>
      <Modal open={modal?.type === 'line'} onClose={close} title={modal?.line ? `Ubah item #${modal.line.line_no}` : 'Tambah item PO'} size="wide">
        {modal?.type === 'line' && <PoLineForm purchaseOrderId={po.id} line={modal.line} onCancel={close} onSaved={savedDetail} />}
      </Modal>
      <Modal open={modal?.type === 'delivery'} onClose={close} title={modal?.delivery ? 'Ubah pengiriman' : 'Catat pengiriman'} size="wide">
        {modal?.type === 'delivery' && <DeliveryForm delivery={modal.delivery} purchaseOrder={po} line={modal.line} onCancel={close} onSaved={saved} />}
      </Modal>
      <Modal open={modal?.type === 'return'} onClose={close} title={modal?.record ? 'Ubah retur' : 'Catat retur'} size="wide">
        {modal?.type === 'return' && <ReturnForm record={modal.record} purchaseOrder={po} onCancel={close} onSaved={saved} />}
      </Modal>
      <Modal open={modal?.type === 'invoice'} onClose={close} title={modal?.invoice ? 'Ubah invoice' : 'Tambah invoice'} size="wide">
        {modal?.type === 'invoice' && <InvoiceForm invoice={modal.invoice} purchaseOrder={po} onCancel={close} onSaved={saved} />}
      </Modal>
      <Modal open={modal?.type === 'financial'} onClose={close} title="Ringkasan keuangan PO" size="wide">
        {modal?.type === 'financial' && <PoFinancialForm record={modal.record} purchaseOrder={po} onCancel={close} onSaved={saved} />}
      </Modal>
    </>
  );
}
