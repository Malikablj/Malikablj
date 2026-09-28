/** Follow-up list with quick actions (done, reschedule, edit, WhatsApp) used across pages. */
import { followUpCompleteSchema, followUpRescheduleSchema, FOLLOW_UP_STATE, MODULE } from '@pik/shared';
import { CalendarClock, Check, MessageCircle, Pencil } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router';
import { useAuth, useToast } from '../../context/contexts.js';
import { useForm } from '../../hooks/useForm.js';
import { api } from '../../services/api.js';
import { whatsappLink } from '../../utils/contact.js';
import { addDaysIso, formatDateLong, formatTime, parseIsoDate, relativeDay } from '../../utils/format.js';
import { Badge, PriorityDot } from '../ui/Badge.jsx';
import { Button } from '../ui/Button.jsx';
import { Field, Input, Textarea } from '../ui/Field.jsx';
import { Modal } from '../ui/Modal.jsx';
import { EmptyState } from '../ui/States.jsx';
import { FollowUpForm, FormActions, FormError } from './CrmForms.jsx';

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

function DateTile({ date, state }) {
  const parsed = parseIsoDate(date);
  return (
    <div className={`fu-date ${state}`} aria-hidden="true">
      <strong>{parsed.getDate()}</strong>
      <span>{MONTHS[parsed.getMonth()]}</span>
    </div>
  );
}

function CompleteDialog({ followUp, onClose, onDone }) {
  const toast = useToast();
  const form = useForm({ outcome: '' });
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(followUpCompleteSchema, async (data) => {
      await api.post(`/follow-ups/${followUp.id}/complete`, data);
      toast.success('Follow up ditandai selesai.');
      onDone();
    });
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      <p className="text-sm muted">
        {followUp.customer_name}
        {followUp.lead_name ? ` · ${followUp.lead_name}` : ''}
      </p>
      <Field label="Hasil follow up" hint="Opsional, ditambahkan ke catatan" error={form.errors.outcome}>
        <Textarea rows={3} {...form.bind('outcome')} autoFocus placeholder="mis. Customer minta sampel minggu depan" />
      </Field>
      <FormActions onCancel={onClose} submitting={form.submitting} submitLabel="Tandai selesai" />
    </form>
  );
}

function RescheduleDialog({ followUp, today, onClose, onDone }) {
  const toast = useToast();
  const form = useForm({ follow_up_date: addDaysIso(today, 1), follow_up_time: followUp.follow_up_time?.slice(0, 5) ?? '', notes: '' });
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(
      followUpRescheduleSchema,
      async (data) => {
        await api.post(`/follow-ups/${followUp.id}/reschedule`, data);
        toast.success('Follow up dijadwalkan ulang.');
        onDone();
      },
      (values) => ({ ...values, follow_up_time: values.follow_up_time || null }),
    );
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      <div className="form-grid">
        <Field label="Tanggal baru" required error={form.errors.follow_up_date}>
          <Input type="date" min={today} {...form.bind('follow_up_date')} />
        </Field>
        <Field label="Jam" error={form.errors.follow_up_time}>
          <Input type="time" {...form.bind('follow_up_time')} />
        </Field>
        <Field label="Alasan" error={form.errors.notes} className="span-2">
          <Input {...form.bind('notes')} placeholder="mis. Customer sedang cuti" />
        </Field>
      </div>
      <FormActions onCancel={onClose} submitting={form.submitting} submitLabel="Jadwalkan ulang" />
    </form>
  );
}

/**
 * items: follow-up rows from the API (with derived `state`)
 * onChanged(): called after an action so the caller can reload
 */
export function FollowUpList({ items, onChanged, empty, showCustomer = true }) {
  const { can, today } = useAuth();
  const canWrite = can(MODULE.FOLLOW_UPS, 'write');
  const [action, setAction] = useState(null); // { type: 'complete'|'reschedule'|'edit', followUp }
  const close = () => setAction(null);
  const done = () => {
    close();
    onChanged?.();
  };

  if (!items?.length) return empty ?? <EmptyState compact icon={CalendarClock} title="Tidak ada follow up" />;

  return (
    <>
      <ul className="list-plain">
        {items.map((followUp) => {
          const open = followUp.state === 'OVERDUE' || followUp.state === 'TODAY' || followUp.state === 'UPCOMING';
          const wa = whatsappLink(followUp.contact_phone);
          return (
            <li key={followUp.id} className="fu-item">
              <DateTile date={followUp.follow_up_date} state={followUp.state} />
              <div className="grow" style={{ minWidth: 0 }}>
                <div className="row" style={{ gap: 6 }}>
                  <PriorityDot priority={followUp.priority} />
                  {showCustomer ? (
                    <Link to={`/customers/${followUp.customer_id}`} className="cell-title truncate">
                      {followUp.customer_name}
                    </Link>
                  ) : (
                    <span className="cell-title">{followUp.lead_name ?? 'Follow up'}</span>
                  )}
                  <Badge tone={FOLLOW_UP_STATE.tones[followUp.state]}>{FOLLOW_UP_STATE.labels[followUp.state]}</Badge>
                </div>
                {followUp.notes && <div className="text-sm pre-wrap" style={{ marginTop: 2 }}>{followUp.notes.split('\n\n')[0]}</div>}
                <div className="cell-sub">
                  {[
                    relativeDay(followUp.follow_up_date, today) + (followUp.follow_up_time ? ` · ${formatTime(followUp.follow_up_time)}` : ''),
                    showCustomer && followUp.lead_name ? (
                      <Link key="lead" to={`/leads/${followUp.lead_id}`}>
                        {followUp.lead_name}
                      </Link>
                    ) : null,
                    followUp.contact_name,
                    followUp.owner_name,
                  ]
                    .filter(Boolean)
                    .map((part, index) => (
                      <span key={index}>
                        {index > 0 && ' · '}
                        {part}
                      </span>
                    ))}
                </div>
              </div>
              <div className="fu-actions">
                {wa && open && (
                  <a
                    className="btn btn-ghost btn-icon btn-sm"
                    href={wa}
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label={`WhatsApp ${followUp.contact_name ?? ''}`.trim()}
                    title="WhatsApp"
                  >
                    <MessageCircle size={16} />
                  </a>
                )}
                {canWrite && open && (
                  <>
                    <Button size="sm" variant="ghost" icon onClick={() => setAction({ type: 'reschedule', followUp })} aria-label="Jadwalkan ulang" title="Jadwalkan ulang">
                      <CalendarClock size={16} />
                    </Button>
                    <Button size="sm" variant="ghost" icon onClick={() => setAction({ type: 'edit', followUp })} aria-label="Ubah" title="Ubah">
                      <Pencil size={15} />
                    </Button>
                    <Button size="sm" variant="success" onClick={() => setAction({ type: 'complete', followUp })}>
                      <Check size={15} aria-hidden="true" /> Selesai
                    </Button>
                  </>
                )}
              </div>
            </li>
          );
        })}
      </ul>
      <Modal open={action?.type === 'complete'} onClose={close} title="Tandai follow up selesai" size="narrow">
        {action?.type === 'complete' && <CompleteDialog followUp={action.followUp} onClose={close} onDone={done} />}
      </Modal>
      <Modal
        open={action?.type === 'reschedule'}
        onClose={close}
        title="Jadwalkan ulang"
        description={action?.followUp ? `Jadwal sekarang: ${formatDateLong(action.followUp.follow_up_date)}` : undefined}
        size="narrow"
      >
        {action?.type === 'reschedule' && <RescheduleDialog followUp={action.followUp} today={today} onClose={close} onDone={done} />}
      </Modal>
      <Modal open={action?.type === 'edit'} onClose={close} title="Ubah follow up">
        {action?.type === 'edit' && <FollowUpForm followUp={action.followUp} onSaved={done} onCancel={close} />}
      </Modal>
    </>
  );
}
