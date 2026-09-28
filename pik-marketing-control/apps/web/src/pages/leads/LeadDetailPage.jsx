import { checkLeadTransition, LEAD_OPEN_STATUSES, LEAD_STATUS, MODULE, PRIORITY } from '@pik/shared';
import { CalendarPlus, ChevronLeft, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { ActivityTimeline } from '../../components/domain/Activities.jsx';
import { ActivityForm, FollowUpForm, LeadForm } from '../../components/domain/CrmForms.jsx';
import { FollowUpList } from '../../components/domain/FollowUps.jsx';
import { LostReasonForm } from '../../components/domain/LeadStatusDialog.jsx';
import { PriorityDot, StatusBadge } from '../../components/ui/Badge.jsx';
import { Button } from '../../components/ui/Button.jsx';
import { Card, CardHeader, StatStrip } from '../../components/ui/Card.jsx';
import { DetailList, PageHeader } from '../../components/ui/Misc.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { ErrorState, LoadingState } from '../../components/ui/States.jsx';
import { useAuth, useConfirm, useToast } from '../../context/contexts.js';
import { useApi } from '../../hooks/useApi.js';
import { api } from '../../services/api.js';
import { addDaysIso, formatCurrency, formatDate, formatDateTime, relativeDay, toDateTimeLocal } from '../../utils/format.js';

/** Clickable pipeline: open stages in order, then the closing outcomes. */
function PipelineStepper({ lead, canWrite, role, onMove }) {
  const openIndex = LEAD_OPEN_STATUSES.indexOf(lead.status);
  return (
    <div className="card" style={{ padding: 16, marginBottom: 24 }}>
      <div className="row" style={{ gap: 6 }} role="group" aria-label="Tahap lead">
        {LEAD_STATUS.values.map((status) => {
          const index = LEAD_OPEN_STATUSES.indexOf(status);
          const current = status === lead.status;
          const passed = index >= 0 && openIndex >= 0 && index < openIndex;
          const allowed = canWrite && !current && checkLeadTransition({ from: lead.status, to: status, role, lostReason: 'x' }).ok;
          return (
            <button
              key={status}
              type="button"
              className="chip"
              aria-pressed={current}
              aria-current={current ? 'step' : undefined}
              disabled={!allowed}
              onClick={() => onMove(status)}
              style={{
                opacity: !allowed && !current ? 0.55 : 1,
                cursor: allowed ? 'pointer' : 'default',
                ...(passed ? { background: 'var(--color-surface-sunken)', borderColor: 'transparent' } : {}),
                ...(current && status === 'WON' ? { background: 'var(--color-success-soft)', color: 'var(--color-success-text)' } : {}),
                ...(current && status === 'LOST' ? { background: 'var(--color-danger-soft)', color: 'var(--color-danger-text)' } : {}),
              }}
            >
              {LEAD_STATUS.labels[status]}
            </button>
          );
        })}
      </div>
    </div>
  );
}

export function LeadDetailPage() {
  const { id } = useParams();
  const { can, user, today } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const { data: lead, error, reload } = useApi(`/leads/${id}`);
  const activities = useApi('/activities', { lead_id: id, page_size: 50 });
  const followUps = useApi('/follow-ups', { lead_id: id, page_size: 50, sort: 'follow_up_date' });
  const [modal, setModal] = useState(null);

  if (error && !lead) return <ErrorState error={error} onRetry={reload} />;
  if (!lead) return <LoadingState rows={8} />;

  const canWrite = can(MODULE.LEADS, 'write');
  const close = () => setModal(null);
  const refreshAll = () => {
    reload();
    activities.reload();
    followUps.reload();
  };

  const move = async (status) => {
    if (status === 'LOST') {
      setModal({ type: 'lost' });
      return;
    }
    if (status === 'WON' && !(await confirm({ title: 'Tandai lead sebagai Won?', message: 'Lead yang sudah Won hanya dapat dibuka kembali oleh Admin.', confirmLabel: 'Tandai Won' }))) {
      return;
    }
    try {
      await api.patch(`/leads/${lead.id}/status`, { status });
      toast.success(`Lead dipindahkan ke ${LEAD_STATUS.labels[status]}.`);
      reload();
    } catch (err) {
      toast.error(err.message);
    }
  };

  const openFollowUps = (followUps.data ?? []).filter((f) => ['OVERDUE', 'TODAY', 'UPCOMING'].includes(f.state));
  const closedFollowUps = (followUps.data ?? []).filter((f) => !['OVERDUE', 'TODAY', 'UPCOMING'].includes(f.state));

  return (
    <>
      <Link to="/leads" className="row text-sm" style={{ marginBottom: 12, gap: 4 }}>
        <ChevronLeft size={16} aria-hidden="true" /> Lead
      </Link>
      <PageHeader
        title={lead.name}
        actions={
          <>
            {can(MODULE.ACTIVITIES, 'write') && (
              <Button onClick={() => setModal({ type: 'activity', activity_at: toDateTimeLocal(new Date()) })}>
                <Plus size={16} aria-hidden="true" /> Aktivitas
              </Button>
            )}
            {can(MODULE.FOLLOW_UPS, 'write') && (
              <Button onClick={() => setModal({ type: 'follow-up', follow_up_date: addDaysIso(today, 1) })}>
                <CalendarPlus size={16} aria-hidden="true" /> Follow up
              </Button>
            )}
            {canWrite && (
              <Button onClick={() => setModal({ type: 'edit' })}>
                <Pencil size={15} aria-hidden="true" /> Ubah
              </Button>
            )}
          </>
        }
      >
        <div className="row" style={{ gap: 8 }}>
          <StatusBadge enumDef={LEAD_STATUS} value={lead.status} />
          <Link to={`/customers/${lead.customer_id}`} className="text-sm">
            {lead.customer_name}
          </Link>
        </div>
      </PageHeader>

      <PipelineStepper lead={lead} canWrite={canWrite} role={user.role} onMove={move} />

      <StatStrip
        items={[
          { label: 'Estimasi nilai', value: formatCurrency(lead.estimated_value) },
          { label: 'Perkiraan closing', value: lead.expected_closing_date ? `${formatDate(lead.expected_closing_date)}` : '–' },
          { label: 'Follow up berikutnya', value: lead.next_follow_up_date ? relativeDay(lead.next_follow_up_date, today) : '–' },
          { label: 'Di tahap ini sejak', value: formatDate(lead.status_changed_at?.slice(0, 10)) },
        ]}
      />

      <div className="section-grid">
        <div>
          <Card>
            <CardHeader title="Follow up" subtitle={`${openFollowUps.length} belum selesai`} />
            {followUps.data ? <FollowUpList items={openFollowUps} onChanged={refreshAll} showCustomer={false} /> : <LoadingState rows={2} />}
            {closedFollowUps.length > 0 && (
              <details style={{ padding: '8px 20px 16px' }}>
                <summary className="text-sm muted" style={{ cursor: 'pointer' }}>
                  {closedFollowUps.length} follow up selesai / dibatalkan
                </summary>
                <FollowUpList items={closedFollowUps} onChanged={refreshAll} showCustomer={false} />
              </details>
            )}
          </Card>
          <Card>
            <CardHeader title="Aktivitas" />
            {activities.data ? <ActivityTimeline items={activities.data} showCustomer={false} /> : <LoadingState rows={3} />}
          </Card>
        </div>
        <Card>
          <CardHeader title="Detail lead" />
          <div className="card-body">
            <DetailList
              items={[
                { label: 'Customer', value: <Link to={`/customers/${lead.customer_id}`}>{lead.customer_name}</Link> },
                { label: 'Kontak', value: lead.contact_name },
                { label: 'Produk', value: lead.product_id ? <Link to={`/products/${lead.product_id}`}>{lead.product_name}</Link> : null },
                {
                  label: 'Prioritas',
                  value: lead.priority && (
                    <span className="row" style={{ gap: 6 }}>
                      <PriorityDot priority={lead.priority} />
                      {PRIORITY.labels[lead.priority]}
                    </span>
                  ),
                },
                { label: 'Sumber', value: lead.source },
                { label: 'PIC', value: lead.owner_name },
                { label: 'Alasan lost', value: lead.lost_reason, hidden: lead.status !== 'LOST' && !lead.lost_reason },
                { label: 'Ditutup', value: lead.closed_at && formatDateTime(lead.closed_at), hidden: !lead.closed_at },
                { label: 'Catatan', value: lead.notes && <span className="pre-wrap">{lead.notes}</span> },
                { label: 'Dibuat', value: formatDateTime(lead.created_at) },
              ]}
            />
          </div>
        </Card>
      </div>

      <Modal open={modal?.type === 'edit'} onClose={close} title="Ubah lead" size="wide">
        {modal?.type === 'edit' && (
          <LeadForm
            lead={lead}
            onCancel={close}
            onSaved={() => {
              close();
              reload();
            }}
          />
        )}
      </Modal>
      <Modal open={modal?.type === 'lost'} onClose={close} title="Tandai Lost" size="narrow">
        {modal?.type === 'lost' && (
          <LostReasonForm
            lead={lead}
            onCancel={close}
            onDone={() => {
              close();
              reload();
            }}
          />
        )}
      </Modal>
      <Modal open={modal?.type === 'activity'} onClose={close} title="Catat aktivitas" size="wide">
        {modal?.type === 'activity' && (
          <ActivityForm
            lead={lead}
            defaults={modal}
            onCancel={close}
            onSaved={() => {
              close();
              refreshAll();
            }}
          />
        )}
      </Modal>
      <Modal open={modal?.type === 'follow-up'} onClose={close} title="Jadwalkan follow up">
        {modal?.type === 'follow-up' && (
          <FollowUpForm
            lead={lead}
            defaults={modal}
            onCancel={close}
            onSaved={() => {
              close();
              refreshAll();
            }}
          />
        )}
      </Modal>
    </>
  );
}
