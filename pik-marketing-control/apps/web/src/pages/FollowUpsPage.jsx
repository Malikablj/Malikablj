import { MODULE, PRIORITY } from '@pik/shared';
import { CalendarCheck, CalendarClock, Plus, X } from 'lucide-react';
import { useState } from 'react';
import { FollowUpForm } from '../components/domain/CrmForms.jsx';
import { FollowUpList } from '../components/domain/FollowUps.jsx';
import { OwnerFilter } from '../components/domain/pickers.jsx';
import { Button } from '../components/ui/Button.jsx';
import { Card, CardHeader } from '../components/ui/Card.jsx';
import { PageHeader, SearchBar } from '../components/ui/Misc.jsx';
import { Modal } from '../components/ui/Modal.jsx';
import { Pagination } from '../components/ui/Pagination.jsx';
import { EmptyState, ErrorState, LoadingState } from '../components/ui/States.jsx';
import { FilterChips, Tabs } from '../components/ui/Tabs.jsx';
import { useAuth } from '../context/contexts.js';
import { useApi } from '../hooks/useApi.js';
import { useListParams } from '../hooks/useListParams.js';
import { addDaysIso } from '../utils/format.js';

const TABS = {
  TODAY: { label: 'Hari ini', states: ['TODAY'], empty: 'Tidak ada follow up hari ini.' },
  OVERDUE: { label: 'Terlambat', states: ['OVERDUE'], empty: 'Tidak ada follow up yang terlambat.' },
  UPCOMING: { label: 'Akan datang', states: ['UPCOMING'], empty: 'Belum ada follow up terjadwal.' },
  DONE: { label: 'Selesai / batal', states: ['DONE', 'CANCELLED'], sort: '-follow_up_date', empty: 'Belum ada follow up yang selesai.' },
};

/** The follow-up opened from a notification, shown above the list until dismissed. */
function FocusedFollowUp({ id, onDismiss, onChanged }) {
  const { data, error, reload } = useApi(`/follow-ups/${id}`);
  return (
    <Card style={{ borderColor: 'var(--color-accent)' }}>
      <CardHeader
        title="Dari notifikasi"
        actions={
          <Button size="sm" variant="ghost" icon onClick={onDismiss} aria-label="Tutup" title="Tutup">
            <X size={16} />
          </Button>
        }
      />
      {error ? (
        <ErrorState error={error} onRetry={reload} compact />
      ) : !data ? (
        <LoadingState rows={2} />
      ) : (
        <FollowUpList
          items={[data]}
          onChanged={() => {
            reload();
            onChanged();
          }}
        />
      )}
    </Card>
  );
}

export function FollowUpsPage() {
  const { can, user, today } = useAuth();
  const [params, setParams] = useListParams({ tab: 'TODAY' }, ['priority']);
  const [creating, setCreating] = useState(null);
  const tab = TABS[params.tab] ? params.tab : 'TODAY';
  const owner = params.owner ?? '';
  const ownerUserId = owner === 'me' ? user.id : owner || undefined;

  const summary = useApi('/follow-ups/summary', owner === 'me' ? { owner: 'me' } : { owner_user_id: ownerUserId });
  const { data, meta, error, loading, reload } = useApi('/follow-ups', {
    state: TABS[tab].states,
    q: params.q,
    priority: params.priority,
    owner_user_id: ownerUserId,
    sort: TABS[tab].sort,
    page: params.page,
    page_size: 30,
  });
  const canWrite = can(MODULE.FOLLOW_UPS, 'write');
  const refresh = () => {
    reload();
    summary.reload();
  };

  const counts = summary.data ?? {};
  const tabs = Object.entries(TABS).map(([id, definition]) => ({
    id,
    label: definition.label,
    count: { TODAY: counts.today, OVERDUE: counts.overdue, UPCOMING: counts.upcoming }[id],
    countTone: id === 'OVERDUE' ? 'danger' : undefined,
  }));

  return (
    <>
      <PageHeader
        title="Follow Up"
        eyebrow="Jadwal tindak lanjut"
        actions={
          canWrite && (
            <Button variant="primary" onClick={() => setCreating({ follow_up_date: addDaysIso(today, 1) })}>
              <Plus size={16} aria-hidden="true" /> Jadwalkan follow up
            </Button>
          )
        }
      />
      {params.focus && <FocusedFollowUp key={params.focus} id={params.focus} onDismiss={() => setParams({ focus: '' }, { resetPage: false })} onChanged={refresh} />}
      <div className="filter-bar">
        <SearchBar value={params.q} onChange={(q) => setParams({ q })} placeholder="Cari customer, lead atau catatan…" />
        <OwnerFilter value={owner} onChange={(next) => setParams({ owner: next })} />
        <FilterChips label="Prioritas" options={PRIORITY.options} value={params.priority} onChange={(priority) => setParams({ priority })} />
      </div>
      <Tabs label="Status follow up" tabs={tabs} value={tab} onChange={(next) => setParams({ tab: next })} />
      <Card>
        {error && !data ? (
          <ErrorState error={error} onRetry={reload} />
        ) : !data ? (
          <LoadingState rows={5} />
        ) : (
          <div style={{ opacity: loading ? 0.6 : 1, transition: 'opacity 160ms' }} aria-busy={loading}>
            <FollowUpList
              items={data}
              onChanged={refresh}
              empty={
                <EmptyState
                  icon={tab === 'DONE' ? CalendarCheck : CalendarClock}
                  title={TABS[tab].empty}
                  text={params.q || params.priority?.length || owner ? 'Coba ubah filter.' : undefined}
                />
              }
            />
          </div>
        )}
        <Pagination meta={meta} onPageChange={(page) => setParams({ page }, { resetPage: false })} />
      </Card>
      <Modal open={Boolean(creating)} onClose={() => setCreating(null)} title="Jadwalkan follow up">
        {creating && (
          <FollowUpForm
            defaults={creating}
            onCancel={() => setCreating(null)}
            onSaved={() => {
              setCreating(null);
              refresh();
            }}
          />
        )}
      </Modal>
    </>
  );
}
