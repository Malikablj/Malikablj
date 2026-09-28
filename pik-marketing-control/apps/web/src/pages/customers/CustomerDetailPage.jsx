import { CUSTOMER_STATUS, DELIVERY_STATUS, LEAD_STATUS, MODULE, PO_STATUS, RETURN_STATUS } from '@pik/shared';
import { Archive, ArchiveRestore, CalendarPlus, ChevronLeft, Mail, MessageCircle, Pencil, Phone, Plus, Star } from 'lucide-react';
import { useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router';
import { ActivityTimeline } from '../../components/domain/Activities.jsx';
import { ActivityForm, ContactForm, CustomerForm, FollowUpForm, LeadForm } from '../../components/domain/CrmForms.jsx';
import { FollowUpList } from '../../components/domain/FollowUps.jsx';
import { Badge, StatusBadge } from '../../components/ui/Badge.jsx';
import { Button } from '../../components/ui/Button.jsx';
import { Card, CardHeader, StatStrip } from '../../components/ui/Card.jsx';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { DetailList, PageHeader, Progress } from '../../components/ui/Misc.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { Pagination } from '../../components/ui/Pagination.jsx';
import { EmptyState, ErrorState, LoadingState } from '../../components/ui/States.jsx';
import { Segmented, Tabs } from '../../components/ui/Tabs.jsx';
import { useAuth, useConfirm, useToast } from '../../context/contexts.js';
import { useApi } from '../../hooks/useApi.js';
import { api } from '../../services/api.js';
import { telLink, whatsappLink } from '../../utils/contact.js';
import {
  addDaysIso,
  formatCurrency,
  formatCurrencyCompact,
  formatDate,
  formatDateTime,
  formatNumber,
  formatQuantity,
  relativeDay,
  toDateTimeLocal,
} from '../../utils/format.js';

function ContactsTab({ customer, canWrite }) {
  const { data, error, reload } = useApi(`/customers/${customer.id}/contacts`);
  const [editing, setEditing] = useState(null); // {} for new, contact for edit
  const confirm = useConfirm();
  const toast = useToast();
  const archive = async (contact) => {
    if (!(await confirm({ title: 'Arsipkan kontak?', message: `${contact.name} tidak akan muncul lagi di pilihan kontak. Riwayat tetap tersimpan.`, confirmLabel: 'Arsipkan', tone: 'danger' }))) return;
    try {
      await api.delete(`/contacts/${contact.id}`);
      toast.success('Kontak diarsipkan.');
      reload();
    } catch (err) {
      toast.error(err.message);
    }
  };
  return (
    <Card>
      <CardHeader
        title="Kontak"
        actions={
          canWrite && (
            <Button size="sm" onClick={() => setEditing({})}>
              <Plus size={15} aria-hidden="true" /> Kontak
            </Button>
          )
        }
      />
      {error ? (
        <ErrorState error={error} onRetry={reload} compact />
      ) : !data ? (
        <LoadingState />
      ) : !data.length ? (
        <EmptyState compact title="Belum ada kontak" text="Tambahkan PIC purchasing atau owner customer." />
      ) : (
        <ul className="list-plain">
          {data.map((contact) => {
            const wa = whatsappLink(contact.whatsapp ?? contact.phone);
            const tel = telLink(contact.phone ?? contact.whatsapp);
            return (
              <li key={contact.id} className="list-item" style={{ alignItems: 'center' }}>
                <div className="grow">
                  <div className="row" style={{ gap: 6 }}>
                    <span className="cell-title">{contact.name}</span>
                    {contact.is_primary && (
                      <Badge tone="info">
                        <Star size={11} aria-hidden="true" /> Utama
                      </Badge>
                    )}
                  </div>
                  <div className="cell-sub">{[contact.position, contact.whatsapp ?? contact.phone, contact.email].filter(Boolean).join(' · ') || '–'}</div>
                </div>
                <div className="row" style={{ gap: 4 }}>
                  {wa && (
                    <a className="btn btn-ghost btn-icon btn-sm" href={wa} target="_blank" rel="noopener noreferrer" aria-label={`WhatsApp ${contact.name}`} title="WhatsApp">
                      <MessageCircle size={16} />
                    </a>
                  )}
                  {tel && (
                    <a className="btn btn-ghost btn-icon btn-sm" href={tel} aria-label={`Telepon ${contact.name}`} title="Telepon">
                      <Phone size={16} />
                    </a>
                  )}
                  {contact.email && (
                    <a className="btn btn-ghost btn-icon btn-sm" href={`mailto:${contact.email}`} aria-label={`Email ${contact.name}`} title="Email">
                      <Mail size={16} />
                    </a>
                  )}
                  {canWrite && (
                    <>
                      <Button size="sm" variant="ghost" icon onClick={() => setEditing(contact)} aria-label={`Ubah ${contact.name}`} title="Ubah">
                        <Pencil size={15} />
                      </Button>
                      <Button size="sm" variant="ghost" icon onClick={() => archive(contact)} aria-label={`Arsipkan ${contact.name}`} title="Arsipkan">
                        <Archive size={15} />
                      </Button>
                    </>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      )}
      <Modal open={Boolean(editing)} onClose={() => setEditing(null)} title={editing?.id ? 'Ubah kontak' : 'Tambah kontak'}>
        {editing && (
          <ContactForm
            customerId={customer.id}
            contact={editing.id ? editing : null}
            onCancel={() => setEditing(null)}
            onSaved={() => {
              setEditing(null);
              reload();
            }}
          />
        )}
      </Modal>
    </Card>
  );
}

function ActivitiesTab({ customer, canWrite, onChanged, refreshKey }) {
  const [page, setPage] = useState(1);
  const { data, meta, error, reload } = useApi('/activities', { customer_id: customer.id, page, page_size: 20 }, { refreshKey });
  const [form, setForm] = useState(null);
  return (
    <Card>
      <CardHeader
        title="Aktivitas"
        actions={
          canWrite && (
            <Button size="sm" onClick={() => setForm({ activity_at: toDateTimeLocal(new Date()) })}>
              <Plus size={15} aria-hidden="true" /> Aktivitas
            </Button>
          )
        }
      />
      {error ? <ErrorState error={error} onRetry={reload} compact /> : !data ? <LoadingState /> : <ActivityTimeline items={data} showCustomer={false} />}
      <Pagination meta={meta} onPageChange={setPage} />
      <Modal open={Boolean(form)} onClose={() => setForm(null)} title="Catat aktivitas" size="wide">
        {form && (
          <ActivityForm
            customer={customer}
            defaults={form}
            onCancel={() => setForm(null)}
            onSaved={() => {
              setForm(null);
              reload();
              onChanged();
            }}
          />
        )}
      </Modal>
    </Card>
  );
}

function LeadsTab({ customer, canWrite, onChanged, refreshKey }) {
  const { data, error, loading, reload } = useApi('/leads', { customer_id: customer.id, page_size: 100 }, { refreshKey });
  const [creating, setCreating] = useState(false);
  return (
    <Card>
      <CardHeader
        title="Lead"
        actions={
          canWrite && (
            <Button size="sm" onClick={() => setCreating(true)}>
              <Plus size={15} aria-hidden="true" /> Lead
            </Button>
          )
        }
      />
      <DataTable
        rows={data}
        loading={loading}
        error={error}
        onRetry={reload}
        rowHref={(row) => `/leads/${row.id}`}
        empty={{ title: 'Belum ada lead', text: 'Catat peluang penjualan untuk customer ini.' }}
        columns={[
          { key: 'name', header: 'Lead' },
          { key: 'status', header: 'Status', render: (row) => <StatusBadge enumDef={LEAD_STATUS} value={row.status} /> },
          { key: 'estimated_value', header: 'Estimasi', align: 'right', render: (row) => formatCurrency(row.estimated_value) },
          { key: 'expected_closing_date', header: 'Closing', className: 'nowrap', render: (row) => formatDate(row.expected_closing_date) },
          { key: 'owner_name', header: 'PIC' },
        ]}
        mobileCard={(row) => (
          <div className="row-between">
            <div className="grow">
              <div className="cell-title">{row.name}</div>
              <div className="cell-sub">{[formatCurrency(row.estimated_value), row.owner_name].join(' · ')}</div>
            </div>
            <StatusBadge enumDef={LEAD_STATUS} value={row.status} />
          </div>
        )}
      />
      <Modal open={creating} onClose={() => setCreating(false)} title="Tambah lead" size="wide">
        {creating && (
          <LeadForm
            customer={customer}
            onCancel={() => setCreating(false)}
            onSaved={() => {
              setCreating(false);
              reload();
              onChanged();
            }}
          />
        )}
      </Modal>
    </Card>
  );
}

function FollowUpsTab({ customer, canWrite, today, onChanged, refreshKey }) {
  const [view, setView] = useState('open');
  const { data, error, reload } = useApi(
    '/follow-ups',
    {
      customer_id: customer.id,
      state: view === 'open' ? 'OVERDUE,TODAY,UPCOMING' : 'DONE,CANCELLED',
      sort: view === 'open' ? 'follow_up_date' : '-follow_up_date',
      page_size: 50,
    },
    { refreshKey },
  );
  const [form, setForm] = useState(null);
  const changed = () => {
    reload();
    onChanged();
  };
  return (
    <Card>
      <CardHeader
        title="Follow Up"
        actions={
          <>
            <Segmented
              label="Tampilan"
              value={view}
              onChange={setView}
              options={[
                { value: 'open', label: 'Belum selesai' },
                { value: 'closed', label: 'Selesai' },
              ]}
            />
            {canWrite && (
              <Button size="sm" onClick={() => setForm({ follow_up_date: addDaysIso(today, 1) })}>
                <Plus size={15} aria-hidden="true" /> Follow up
              </Button>
            )}
          </>
        }
      />
      {error ? <ErrorState error={error} onRetry={reload} compact /> : !data ? <LoadingState /> : <FollowUpList items={data} onChanged={changed} showCustomer={false} />}
      <Modal open={Boolean(form)} onClose={() => setForm(null)} title="Jadwalkan follow up">
        {form && (
          <FollowUpForm
            customer={customer}
            defaults={form}
            onCancel={() => setForm(null)}
            onSaved={() => {
              setForm(null);
              changed();
            }}
          />
        )}
      </Modal>
    </Card>
  );
}

function PurchaseOrdersTab({ customer, canCreate }) {
  const [page, setPage] = useState(1);
  const { data, meta, error, loading, reload } = useApi('/purchase-orders', { customer_id: customer.id, page, page_size: 20 });
  return (
    <Card>
      <CardHeader
        title="Purchase Order"
        actions={
          canCreate && (
            <Button size="sm" to={`/purchase-orders/new?customer_id=${customer.id}`}>
              <Plus size={15} aria-hidden="true" /> Buat PO
            </Button>
          )
        }
      />
      <DataTable
        rows={data}
        loading={loading}
        error={error}
        onRetry={reload}
        rowHref={(row) => `/purchase-orders/${row.id}`}
        empty={{ title: 'Belum ada PO' }}
        mobileCard={(row) => (
          <div className="stack-sm">
            <div className="row-between">
              <span className="cell-title">{row.po_number}</span>
              <StatusBadge enumDef={PO_STATUS} value={row.status} />
            </div>
            <Progress value={row.ordered_quantity - row.outstanding_quantity} total={row.ordered_quantity} />
            <div className="cell-sub num">
              {formatDate(row.po_date)} · outstanding {formatNumber(row.outstanding_quantity)}
            </div>
          </div>
        )}
        columns={[
          { key: 'po_number', header: 'No PO' },
          { key: 'po_date', header: 'Tanggal', className: 'nowrap', render: (row) => formatDate(row.po_date) },
          { key: 'status', header: 'Status', render: (row) => <StatusBadge enumDef={PO_STATUS} value={row.status} /> },
          {
            key: 'progress',
            header: 'Terkirim',
            render: (row) => <Progress value={row.ordered_quantity - row.outstanding_quantity} total={row.ordered_quantity} />,
          },
          { key: 'outstanding_quantity', header: 'Outstanding', align: 'right', render: (row) => formatNumber(row.outstanding_quantity) },
          { key: 'total_value', header: 'Nilai', align: 'right', render: (row) => formatCurrency(row.total_value) },
        ]}
      />
      <Pagination meta={meta} onPageChange={setPage} />
    </Card>
  );
}

function DeliveriesTab({ customer }) {
  const [page, setPage] = useState(1);
  const { data, meta, error, loading, reload } = useApi('/deliveries', { customer_id: customer.id, page, page_size: 20 });
  return (
    <Card>
      <CardHeader title="Pengiriman" />
      <DataTable
        rows={data}
        loading={loading}
        error={error}
        onRetry={reload}
        empty={{ title: 'Belum ada pengiriman' }}
        mobileCard={(row) => (
          <div className="stack-sm">
            <div className="row-between">
              <span className="cell-title">
                {formatDate(row.delivery_date)} · {formatQuantity(row.quantity, row.unit)}
              </span>
              <StatusBadge enumDef={DELIVERY_STATUS} value={row.status} />
            </div>
            <div className="cell-sub">{[row.po_number, row.product_name, row.delivery_number].filter(Boolean).join(' · ')}</div>
          </div>
        )}
        columns={[
          { key: 'delivery_date', header: 'Tanggal', className: 'nowrap', render: (row) => formatDate(row.delivery_date) },
          { key: 'po_number', header: 'No PO', render: (row) => <Link to={`/purchase-orders/${row.purchase_order_id}`}>{row.po_number}</Link> },
          { key: 'product_name', header: 'Produk' },
          { key: 'quantity', header: 'Qty', align: 'right', render: (row) => formatQuantity(row.quantity, row.unit) },
          { key: 'delivery_number', header: 'Surat jalan' },
          { key: 'status', header: 'Status', render: (row) => <StatusBadge enumDef={DELIVERY_STATUS} value={row.status} /> },
        ]}
      />
      <Pagination meta={meta} onPageChange={setPage} />
    </Card>
  );
}

function ReturnsTab({ customer }) {
  const [page, setPage] = useState(1);
  const { data, meta, error, loading, reload } = useApi('/returns', { customer_id: customer.id, page, page_size: 20 });
  return (
    <Card>
      <CardHeader title="Retur" />
      <DataTable
        rows={data}
        loading={loading}
        error={error}
        onRetry={reload}
        empty={{ title: 'Belum ada retur' }}
        mobileCard={(row) => (
          <div className="stack-sm">
            <div className="row-between">
              <span className="cell-title">
                {formatDate(row.return_date)} · {formatQuantity(row.quantity, row.unit)}
              </span>
              <StatusBadge enumDef={RETURN_STATUS} value={row.status} />
            </div>
            <div className="cell-sub">{[row.product_name, row.reason].filter(Boolean).join(' · ')}</div>
          </div>
        )}
        columns={[
          { key: 'return_date', header: 'Tanggal', className: 'nowrap', render: (row) => formatDate(row.return_date) },
          { key: 'po_number', header: 'No PO', render: (row) => (row.po_number ? <Link to={`/purchase-orders/${row.purchase_order_id}`}>{row.po_number}</Link> : '–') },
          { key: 'product_name', header: 'Produk' },
          { key: 'quantity', header: 'Qty', align: 'right', render: (row) => formatQuantity(row.quantity, row.unit) },
          { key: 'reason', header: 'Alasan' },
          { key: 'status', header: 'Status', render: (row) => <StatusBadge enumDef={RETURN_STATUS} value={row.status} /> },
        ]}
      />
      <Pagination meta={meta} onPageChange={setPage} />
    </Card>
  );
}

function OverviewTab({ customer, today, refreshKey }) {
  const { data: activities } = useApi('/activities', { customer_id: customer.id, page_size: 5 }, { refreshKey });
  const s = customer.summary;
  return (
    <div className="section-grid">
      <Card>
        <CardHeader title="Profil" />
        <div className="card-body">
          <DetailList
            items={[
              { label: 'Kode', value: customer.customer_code },
              { label: 'Industri', value: customer.industry },
              { label: 'Status', value: <StatusBadge enumDef={CUSTOMER_STATUS} value={customer.status} /> },
              { label: 'Telepon', value: customer.phone && <a href={telLink(customer.phone)}>{customer.phone}</a> },
              { label: 'Email', value: customer.email && <a href={`mailto:${customer.email}`}>{customer.email}</a> },
              {
                label: 'Website',
                value: customer.website && (
                  <a href={/^https?:\/\//.test(customer.website) ? customer.website : `https://${customer.website}`} target="_blank" rel="noopener noreferrer">
                    {customer.website}
                  </a>
                ),
              },
              { label: 'Alamat', value: customer.address && <span className="pre-wrap">{customer.address}</span> },
              { label: 'Catatan', value: customer.notes && <span className="pre-wrap">{customer.notes}</span> },
              { label: 'Follow up berikutnya', value: s.next_follow_up_date && `${formatDate(s.next_follow_up_date)} (${relativeDay(s.next_follow_up_date, today)})` },
              { label: 'Dibuat', value: `${formatDateTime(customer.created_at)}${customer.created_by_name ? ` oleh ${customer.created_by_name}` : ''}` },
              { label: 'Sumber data', value: customer.source_sheet && `Migrasi: ${customer.source_file} · ${customer.source_sheet} baris ${customer.legacy_row}`, hidden: !customer.source_sheet },
            ]}
          />
        </div>
      </Card>
      <Card>
        <CardHeader title="Aktivitas terakhir" actions={<Link to={`?tab=activities`} className="text-sm">Semua</Link>} />
        {activities ? <ActivityTimeline items={activities} showCustomer={false} /> : <LoadingState rows={3} />}
      </Card>
    </div>
  );
}

const TABS = [
  { id: 'overview', label: 'Ringkasan' },
  { id: 'contacts', label: 'Kontak', module: MODULE.CONTACTS },
  { id: 'activities', label: 'Aktivitas', module: MODULE.ACTIVITIES },
  { id: 'leads', label: 'Lead', module: MODULE.LEADS },
  { id: 'follow-ups', label: 'Follow Up', module: MODULE.FOLLOW_UPS },
  { id: 'purchase-orders', label: 'Purchase Order', module: MODULE.PURCHASE_ORDERS },
  { id: 'deliveries', label: 'Pengiriman', module: MODULE.DELIVERIES },
  { id: 'returns', label: 'Retur', module: MODULE.RETURNS },
];

export function CustomerDetailPage() {
  const { id } = useParams();
  const { can, access, today } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const [searchParams, setSearchParams] = useSearchParams();
  const tab = searchParams.get('tab') ?? 'overview';
  const { data: customer, error, reload } = useApi(`/customers/${id}`);
  const [modal, setModal] = useState(null); // edit | activity | follow-up
  // Bumped when the header actions save, so the open tab re-fetches its list.
  const [refreshKey, setRefreshKey] = useState(0);

  if (error && !customer) return <ErrorState error={error} onRetry={reload} />;
  if (!customer) return <LoadingState rows={8} />;

  const canWriteCustomer = can(MODULE.CUSTOMERS, 'write');
  const s = customer.summary;
  const tabs = TABS.filter((t) => !t.module || can(t.module)).map((t) =>
    t.id === 'follow-ups' ? { ...t, count: s.overdue_follow_ups || undefined, countTone: 'danger' } : t,
  );

  const toggleArchive = async () => {
    const archiving = customer.is_active;
    const ok = await confirm({
      title: archiving ? 'Arsipkan customer?' : 'Pulihkan customer?',
      message: archiving
        ? `${customer.name} akan disembunyikan dari daftar dan pilihan. Semua riwayat, lead dan PO tetap tersimpan dan customer dapat dipulihkan.`
        : `${customer.name} akan aktif kembali.`,
      confirmLabel: archiving ? 'Arsipkan' : 'Pulihkan',
      tone: archiving ? 'danger' : 'primary',
    });
    if (!ok) return;
    try {
      if (archiving) await api.delete(`/customers/${customer.id}`);
      else await api.post(`/customers/${customer.id}/restore`);
      toast.success(archiving ? 'Customer diarsipkan.' : 'Customer dipulihkan.');
      reload();
    } catch (err) {
      toast.error(err.message);
    }
  };

  return (
    <>
      <Link to="/customers" className="row text-sm" style={{ marginBottom: 12, gap: 4 }}>
        <ChevronLeft size={16} aria-hidden="true" /> Customer
      </Link>
      <PageHeader
        title={customer.name}
        actions={
          <>
            {can(MODULE.ACTIVITIES, 'write') && customer.is_active && (
              <Button onClick={() => setModal({ type: 'activity', activity_at: toDateTimeLocal(new Date()) })}>
                <Plus size={16} aria-hidden="true" /> Aktivitas
              </Button>
            )}
            {can(MODULE.FOLLOW_UPS, 'write') && customer.is_active && (
              <Button onClick={() => setModal({ type: 'follow-up', follow_up_date: addDaysIso(today, 1) })}>
                <CalendarPlus size={16} aria-hidden="true" /> Follow up
              </Button>
            )}
            {canWriteCustomer && (
              <>
                <Button onClick={() => setModal({ type: 'edit' })}>
                  <Pencil size={15} aria-hidden="true" /> Ubah
                </Button>
                <Button variant="ghost" onClick={toggleArchive}>
                  {customer.is_active ? <Archive size={15} aria-hidden="true" /> : <ArchiveRestore size={15} aria-hidden="true" />}
                  {customer.is_active ? 'Arsipkan' : 'Pulihkan'}
                </Button>
              </>
            )}
          </>
        }
      >
        <div className="row" style={{ gap: 8 }}>
          <StatusBadge enumDef={CUSTOMER_STATUS} value={customer.status} />
          {!customer.is_active && <Badge tone="danger">Diarsipkan</Badge>}
          <span className="text-sm muted">{[customer.customer_code, customer.industry].filter(Boolean).join(' · ')}</span>
        </div>
      </PageHeader>

      <StatStrip
        items={[
          { label: 'Lead aktif', value: formatNumber(s.active_leads) },
          { label: 'Nilai pipeline', value: formatCurrencyCompact(s.pipeline_value) },
          { label: 'PO open', value: formatNumber(s.open_purchase_orders) },
          { label: 'Outstanding qty', value: formatNumber(s.outstanding_quantity) },
          { label: 'Follow up terlambat', value: formatNumber(s.overdue_follow_ups), className: s.overdue_follow_ups ? 'text-danger' : '' },
          { label: 'Aktivitas terakhir', value: s.last_activity_at ? formatDate(s.last_activity_at.slice(0, 10)) : 'Belum ada' },
        ]}
      />

      <Tabs label="Bagian customer" tabs={tabs} value={tab} onChange={(next) => setSearchParams(next === 'overview' ? {} : { tab: next }, { replace: true })} />

      {tab === 'overview' && <OverviewTab customer={customer} today={today} refreshKey={refreshKey} />}
      {tab === 'contacts' && <ContactsTab customer={customer} canWrite={can(MODULE.CONTACTS, 'write')} />}
      {tab === 'activities' && <ActivitiesTab customer={customer} canWrite={can(MODULE.ACTIVITIES, 'write')} onChanged={reload} refreshKey={refreshKey} />}
      {tab === 'leads' && <LeadsTab customer={customer} canWrite={can(MODULE.LEADS, 'write')} onChanged={reload} refreshKey={refreshKey} />}
      {tab === 'follow-ups' && (
        <FollowUpsTab customer={customer} canWrite={can(MODULE.FOLLOW_UPS, 'write')} today={today} onChanged={reload} refreshKey={refreshKey} />
      )}
      {tab === 'purchase-orders' && <PurchaseOrdersTab customer={customer} canCreate={Boolean(access(MODULE.PURCHASE_ORDERS)?.match(/RW|OWN/))} />}
      {tab === 'deliveries' && <DeliveriesTab customer={customer} />}
      {tab === 'returns' && <ReturnsTab customer={customer} />}

      <Modal open={modal?.type === 'edit'} onClose={() => setModal(null)} title="Ubah customer" size="wide">
        {modal?.type === 'edit' && (
          <CustomerForm
            customer={customer}
            onCancel={() => setModal(null)}
            onSaved={() => {
              setModal(null);
              reload();
            }}
          />
        )}
      </Modal>
      <Modal open={modal?.type === 'activity'} onClose={() => setModal(null)} title="Catat aktivitas" size="wide">
        {modal?.type === 'activity' && (
          <ActivityForm
            customer={customer}
            defaults={modal}
            onCancel={() => setModal(null)}
            onSaved={() => {
              setModal(null);
              reload();
              setRefreshKey((value) => value + 1);
              if (tab !== 'activities') setSearchParams({ tab: 'activities' }, { replace: true });
            }}
          />
        )}
      </Modal>
      <Modal open={modal?.type === 'follow-up'} onClose={() => setModal(null)} title="Jadwalkan follow up">
        {modal?.type === 'follow-up' && (
          <FollowUpForm
            customer={customer}
            defaults={modal}
            onCancel={() => setModal(null)}
            onSaved={() => {
              setModal(null);
              reload();
              setRefreshKey((value) => value + 1);
              if (tab !== 'follow-ups') setSearchParams({ tab: 'follow-ups' }, { replace: true });
            }}
          />
        )}
      </Modal>
    </>
  );
}
