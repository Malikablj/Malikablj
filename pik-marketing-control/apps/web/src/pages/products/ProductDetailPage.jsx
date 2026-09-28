import { MODULE, PO_STATUS, PRODUCT_STATUS, STOCK_TYPE } from '@pik/shared';
import { Archive, ArchiveRestore, Boxes, ChevronLeft, FileText, Pencil, Plus, Timer } from 'lucide-react';
import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { LeadTimeForm, ProductForm, StockForm } from '../../components/domain/OperationsForms.jsx';
import { Badge, StatusBadge } from '../../components/ui/Badge.jsx';
import { Button } from '../../components/ui/Button.jsx';
import { Card, CardHeader, StatStrip } from '../../components/ui/Card.jsx';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { DetailList, PageHeader } from '../../components/ui/Misc.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { EmptyState, ErrorState, LoadingState } from '../../components/ui/States.jsx';
import { useAuth, useConfirm, useToast } from '../../context/contexts.js';
import { useApi } from '../../hooks/useApi.js';
import { api } from '../../services/api.js';
import { formatDate, formatDateTime, formatNumber, formatQuantity } from '../../utils/format.js';

export function ProductDetailPage() {
  const { id } = useParams();
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const { data: product, error, reload } = useApi(`/products/${id}`);
  const [modal, setModal] = useState(null);

  if (error && !product) return <ErrorState error={error} onRetry={reload} />;
  if (!product) return <LoadingState rows={8} />;

  const canWrite = can(MODULE.PRODUCTS, 'write');
  const canWriteStock = can(MODULE.STOCK, 'write');
  const canWriteLeadTime = can(MODULE.LEAD_TIME, 'write');
  const close = () => setModal(null);
  const saved = () => {
    close();
    reload();
  };
  const unit = product.unit;
  const openQuantity = product.open_order_lines.reduce((sum, line) => sum + Number(line.outstanding_quantity), 0);
  const sameUnit = product.open_order_lines.every((line) => (line.unit ?? unit) === unit);

  const toggleArchive = async () => {
    const archiving = product.is_active;
    const ok = await confirm({
      title: archiving ? 'Arsipkan produk?' : 'Pulihkan produk?',
      message: archiving
        ? `${product.name} tidak akan muncul lagi di pilihan produk. Riwayat PO, stok dan lead time tetap tersimpan.`
        : `${product.name} akan aktif kembali.`,
      confirmLabel: archiving ? 'Arsipkan' : 'Pulihkan',
      tone: archiving ? 'danger' : 'primary',
    });
    if (!ok) return;
    try {
      if (archiving) await api.delete(`/products/${product.id}`);
      else await api.post(`/products/${product.id}/restore`);
      toast.success(archiving ? 'Produk diarsipkan.' : 'Produk dipulihkan.');
      reload();
    } catch (err) {
      toast.error(err.message);
    }
  };

  return (
    <>
      <Link to="/products" className="row text-sm" style={{ marginBottom: 12, gap: 4 }}>
        <ChevronLeft size={16} aria-hidden="true" /> Produk
      </Link>
      <PageHeader
        title={product.name}
        actions={
          <>
            {canWriteStock && product.is_active && (
              <Button onClick={() => setModal({ type: 'stock' })}>
                <Boxes size={16} aria-hidden="true" /> Catat stok
              </Button>
            )}
            {canWrite && (
              <>
                <Button onClick={() => setModal({ type: 'edit' })}>
                  <Pencil size={15} aria-hidden="true" /> Ubah
                </Button>
                <Button variant="ghost" onClick={toggleArchive}>
                  {product.is_active ? <Archive size={15} aria-hidden="true" /> : <ArchiveRestore size={15} aria-hidden="true" />}
                  {product.is_active ? 'Arsipkan' : 'Pulihkan'}
                </Button>
              </>
            )}
          </>
        }
      >
        <div className="row" style={{ gap: 8 }}>
          <StatusBadge enumDef={PRODUCT_STATUS} value={product.status} />
          {!product.is_active && <Badge tone="danger">Diarsipkan</Badge>}
          <span className="text-sm muted">{[product.product_code, product.category, product.unit].filter(Boolean).join(' · ')}</span>
        </div>
      </PageHeader>

      <StatStrip
        items={[
          { label: 'Stok FG', value: product.stock_fg === null ? '–' : formatQuantity(product.stock_fg, unit) },
          { label: 'WIP', value: product.stock_wip === null ? '–' : formatQuantity(product.stock_wip, unit) },
          { label: 'Ready', value: product.stock_ready === null ? '–' : formatQuantity(product.stock_ready, unit) },
          { label: 'Reserved', value: product.stock_reserved === null ? '–' : formatQuantity(product.stock_reserved, unit) },
          { label: 'Outstanding PO', value: sameUnit ? formatQuantity(openQuantity, unit) : `${product.open_order_lines.length} item` },
          { label: 'Lead time standar', value: product.lead_time_days === null ? '–' : `${formatNumber(product.lead_time_days)} hari` },
        ]}
      />

      <div className="section-grid">
        <div>
          <Card>
            <CardHeader title="PO berjalan" subtitle="Item PO open dengan sisa kirim" />
            <DataTable
              caption="PO berjalan untuk produk ini"
              rows={product.open_order_lines}
              rowHref={(row) => `/purchase-orders/${row.purchase_order_id}`}
              empty={{ icon: FileText, title: 'Tidak ada PO berjalan', compact: true }}
              mobileCard={(row) => (
                <div className="stack-sm">
                  <div className="row-between">
                    <span className="cell-title">{row.po_number}</span>
                    <strong className="num">sisa {formatNumber(row.outstanding_quantity)}</strong>
                  </div>
                  <div className="cell-sub">
                    {row.customer_name} · target {formatDate(row.expected_delivery_date)}
                  </div>
                </div>
              )}
              columns={[
                {
                  key: 'po_number',
                  header: 'No PO',
                  render: (row) => (
                    <>
                      {row.po_number}
                      <div className="cell-sub">{row.customer_name}</div>
                    </>
                  ),
                },
                { key: 'status', header: 'Status', render: (row) => <StatusBadge enumDef={PO_STATUS} value={row.status} /> },
                { key: 'expected_delivery_date', header: 'Target', className: 'nowrap', render: (row) => formatDate(row.expected_delivery_date) },
                { key: 'order_quantity', header: 'Order', align: 'right', render: (row) => formatQuantity(row.order_quantity, row.unit) },
                { key: 'outstanding_quantity', header: 'Sisa', align: 'right', render: (row) => <strong>{formatNumber(row.outstanding_quantity)}</strong> },
              ]}
            />
          </Card>
          <Card>
            <CardHeader
              title="Stok saat ini"
              subtitle="Catatan terakhir per tipe dan gudang"
              actions={
                <Link to={`/stock?view=history&q=${encodeURIComponent(product.product_code ?? product.name)}`} className="text-sm">
                  Riwayat
                </Link>
              }
            />
            {product.stock.length ? (
              <ul className="list-plain">
                {product.stock.map((row) => (
                  <li key={row.id} className="list-item" style={{ alignItems: 'center' }}>
                    <StatusBadge enumDef={STOCK_TYPE} value={row.stock_type} />
                    <div className="grow">
                      <div className="num" style={{ fontWeight: 600 }}>
                        {formatQuantity(row.quantity, unit)}
                      </div>
                      <div className="cell-sub">{[row.warehouse ?? 'Tanpa gudang', `per ${formatDate(row.stock_date)}`].join(' · ')}</div>
                    </div>
                    {canWriteStock && (
                      <Button size="sm" variant="ghost" icon onClick={() => setModal({ type: 'stock', record: { ...row, product_name: product.name } })} aria-label="Koreksi stok" title="Koreksi">
                        <Pencil size={15} />
                      </Button>
                    )}
                  </li>
                ))}
              </ul>
            ) : (
              <EmptyState compact icon={Boxes} title="Belum ada data stok" />
            )}
          </Card>
        </div>
        <div>
          <Card>
            <CardHeader title="Detail produk" />
            <div className="card-body">
              <DetailList
                items={[
                  { label: 'Kode', value: product.product_code },
                  { label: 'Kategori', value: product.category },
                  { label: 'Satuan', value: product.unit },
                  { label: 'Customer', value: product.customer_id && <Link to={`/customers/${product.customer_id}`}>{product.customer_name}</Link> },
                  { label: 'Deskripsi', value: product.description && <span className="pre-wrap">{product.description}</span> },
                  { label: 'Dibuat', value: formatDateTime(product.created_at) },
                  {
                    label: 'Sumber data',
                    value: product.source_sheet && `Migrasi: ${product.source_file} · ${product.source_sheet} baris ${product.legacy_row}`,
                    hidden: !product.source_sheet,
                  },
                ]}
              />
            </div>
          </Card>
          <Card>
            <CardHeader
              title="Lead time"
              actions={
                canWriteLeadTime && (
                  <Button size="sm" onClick={() => setModal({ type: 'lead-time' })}>
                    <Plus size={15} aria-hidden="true" /> Lead time
                  </Button>
                )
              }
            />
            {product.lead_times.length ? (
              <ul className="list-plain">
                {product.lead_times.map((row) => (
                  <li key={row.id} className="list-item" style={{ alignItems: 'center' }}>
                    <Timer size={16} className="muted" aria-hidden="true" />
                    <div className="grow">
                      <div className="text-sm">{row.customer_name ?? 'Umum'}</div>
                      {row.notes && <div className="cell-sub">{row.notes}</div>}
                    </div>
                    <strong className="num">{formatNumber(row.lead_time_days)} hari</strong>
                    {canWriteLeadTime && (
                      <Button size="sm" variant="ghost" icon onClick={() => setModal({ type: 'lead-time', record: { ...row, product_name: product.name } })} aria-label="Ubah lead time" title="Ubah">
                        <Pencil size={15} />
                      </Button>
                    )}
                  </li>
                ))}
              </ul>
            ) : (
              <EmptyState compact icon={Timer} title="Belum ada lead time khusus" />
            )}
          </Card>
        </div>
      </div>

      <Modal open={modal?.type === 'edit'} onClose={close} title="Ubah produk" size="wide">
        {modal?.type === 'edit' && <ProductForm product={product} onCancel={close} onSaved={saved} />}
      </Modal>
      <Modal open={modal?.type === 'stock'} onClose={close} title={modal?.record ? 'Koreksi data stok' : 'Catat stok'} size="wide">
        {modal?.type === 'stock' && <StockForm record={modal.record} product={product} onCancel={close} onSaved={saved} />}
      </Modal>
      <Modal open={modal?.type === 'lead-time'} onClose={close} title={modal?.record ? 'Ubah lead time' : 'Tambah lead time'}>
        {modal?.type === 'lead-time' && <LeadTimeForm record={modal.record} product={product} onCancel={close} onSaved={saved} />}
      </Modal>
    </>
  );
}
