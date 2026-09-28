import { ACTIVITY_TYPE, MODULE } from '@pik/shared';
import { Activity, Plus } from 'lucide-react';
import { useState } from 'react';
import { ActivityTimeline } from '../components/domain/Activities.jsx';
import { ActivityForm } from '../components/domain/CrmForms.jsx';
import { CustomerSelect, OwnerFilter } from '../components/domain/pickers.jsx';
import { Button } from '../components/ui/Button.jsx';
import { Card } from '../components/ui/Card.jsx';
import { Select } from '../components/ui/Field.jsx';
import { DateRange, PageHeader, SearchBar } from '../components/ui/Misc.jsx';
import { Modal } from '../components/ui/Modal.jsx';
import { Pagination } from '../components/ui/Pagination.jsx';
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States.jsx';
import { useAuth } from '../context/contexts.js';
import { useApi } from '../hooks/useApi.js';
import { useListParams } from '../hooks/useListParams.js';
import { addDaysIso, formatDateLong, instantToBusinessDate, relativeDay, toDateTimeLocal } from '../utils/format.js';

/** Groups activities (already sorted newest first) by business date. */
function groupByDay(activities) {
  const groups = [];
  for (const activity of activities) {
    const day = instantToBusinessDate(activity.activity_at);
    const last = groups[groups.length - 1];
    if (last?.day === day) last.items.push(activity);
    else groups.push({ day, items: [activity] });
  }
  return groups;
}

export function ActivitiesPage() {
  const { can, user, today } = useAuth();
  const [params, setParams] = useListParams();
  const [modal, setModal] = useState(null); // { activity_at } for new, { activity } for edit
  const owner = params.owner ?? '';
  const { data, meta, error, loading, reload } = useApi('/activities', {
    q: params.q,
    type: params.type,
    customer_id: params.customer_id,
    owner_user_id: owner === 'me' ? user.id : owner || undefined,
    from: params.from,
    to: params.to,
    page: params.page,
    page_size: 30,
  });
  const canWrite = can(MODULE.ACTIVITIES, 'write');
  const close = () => setModal(null);
  const filtered = Boolean(params.q || params.type || params.customer_id || owner || params.from || params.to);

  let content;
  if (error && !data) content = <ErrorState error={error} onRetry={reload} />;
  else if (!data) content = <LoadingState rows={6} />;
  else if (!data.length) {
    content = (
      <EmptyState
        icon={Activity}
        title={filtered ? 'Tidak ada aktivitas yang cocok' : 'Belum ada aktivitas'}
        text={filtered ? 'Ubah atau hapus filter.' : 'Catat telepon, WhatsApp, kunjungan dan penawaran ke customer.'}
      />
    );
  } else {
    content = (
      <div style={{ opacity: loading ? 0.6 : 1, transition: 'opacity 160ms' }} aria-busy={loading}>
        {groupByDay(data).map((group) => (
          <section key={group.day} aria-label={formatDateLong(group.day)}>
            <h2 className="text-sm" style={{ margin: 0, padding: '12px 20px 0', fontWeight: 600 }}>
              {formatDateLong(group.day)}
              {(group.day === today || group.day === addDaysIso(today, -1)) && (
                <span className="muted" style={{ fontWeight: 400 }}>
                  {' '}
                  · {relativeDay(group.day, today)}
                </span>
              )}
            </h2>
            <ActivityTimeline items={group.items} onSelect={canWrite ? (activity) => setModal({ activity }) : undefined} />
          </section>
        ))}
      </div>
    );
  }

  return (
    <>
      <PageHeader
        title="Aktivitas"
        eyebrow="Riwayat interaksi customer"
        actions={
          canWrite && (
            <Button variant="primary" onClick={() => setModal({ activity_at: toDateTimeLocal(new Date()) })}>
              <Plus size={16} aria-hidden="true" /> Catat aktivitas
            </Button>
          )
        }
      />
      <div className="filter-bar">
        <SearchBar value={params.q} onChange={(q) => setParams({ q })} placeholder="Cari judul, isi atau customer…" />
        <Select aria-label="Jenis aktivitas" placeholder="Semua jenis" options={ACTIVITY_TYPE.options} value={params.type ?? ''} onChange={(event) => setParams({ type: event.target.value })} />
        <OwnerFilter value={owner} onChange={(next) => setParams({ owner: next })} />
      </div>
      <div className="filter-bar">
        <div style={{ minWidth: 240, flex: '0 1 320px' }}>
          <CustomerSelect
            aria-label="Filter customer"
            placeholder="Semua customer"
            value={params.customer_id ?? ''}
            selectedLabel={params.customer_name}
            onChange={(id, row) => setParams({ customer_id: id, customer_name: row?.name })}
          />
        </div>
        <DateRange from={params.from} to={params.to} onChange={setParams} label="Tanggal aktivitas" />
        {filtered && (
          <Button
            variant="ghost"
            size="sm"
            onClick={() => setParams({ q: '', type: '', customer_id: '', customer_name: '', owner: '', from: '', to: '' })}
          >
            Hapus filter
          </Button>
        )}
      </div>
      <Card>
        {content}
        <Pagination meta={meta} onPageChange={(page) => setParams({ page }, { resetPage: false })} />
      </Card>
      <Modal open={Boolean(modal)} onClose={close} title={modal?.activity ? 'Ubah aktivitas' : 'Catat aktivitas'} size="wide">
        {modal && (
          <ActivityForm
            activity={modal.activity}
            defaults={modal}
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
