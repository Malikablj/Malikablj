import { CalendarDays, ChevronLeft, ChevronRight, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { PageHeader } from '@/components/layout/PageHeader';
import { Badge } from '@/components/ui/Badge';
import { Button, IconButton } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState, ErrorState, InlineAlert, Skeleton } from '@/components/ui/Feedback';
import { ChipToggleGroup, Field, Input, Select, Textarea } from '@/components/ui/Form';
import { ConfirmDialog, Modal } from '@/components/ui/Overlay';
import { TONE_MARK } from '@/components/ui/tone';
import { useToast } from '@/components/ui/Toast';
import { CALENDAR_CATEGORY_LABEL, CALENDAR_CATEGORY_TONE } from '@/config/labels';
import { errorMessage, isAppError } from '@/domain/errors';
import { canManageCalendar } from '@/domain/permissions';
import { useAction, useCalendarItems, useProjects } from '@/hooks/queries';
import { useUser } from '@/hooks/useAuth';
import { useLocalPref } from '@/hooks/useUtils';
import { addDays, addMonths, endOfMonth, endOfWeek, formatDate, formatMonthYear, parseISODate, startOfMonth, startOfWeek, todayISO, WEEKDAYS_SHORT } from '@/lib/date';
import { cn } from '@/lib/utils';
import { type CalendarItem, deleteEvent, type EventInput, saveEvent } from '@/services/api/calendar';
import type { CalendarCategory } from '@/types';

const CATEGORIES = Object.keys(CALENDAR_CATEGORY_LABEL) as CalendarCategory[];

export default function CalendarPage() {
  const user = useUser();
  const today = todayISO();
  const [month, setMonth] = useState(startOfMonth(today));
  const [selected, setSelected] = useState(today);
  const [cats, setCats] = useLocalPref<CalendarCategory[]>('calendar-categories', CATEGORIES);
  const [editing, setEditing] = useState<{ item?: CalendarItem; date: string } | null>(null);
  const gridFrom = startOfWeek(month);
  const gridTo = endOfWeek(endOfMonth(month));
  const { data, isLoading, error, refetch } = useCalendarItems(gridFrom, gridTo);
  const items = useMemo(() => (data ?? []).filter((i) => cats.includes(i.category)), [data, cats]);
  const byDate = useMemo(() => {
    const m = new Map<string, CalendarItem[]>();
    for (const i of items) m.set(i.date, [...(m.get(i.date) ?? []), i]);
    return m;
  }, [items]);
  const days: string[] = [];
  for (let d = gridFrom; d <= gridTo; d = addDays(d, 1)) days.push(d);
  const monthItems = items.filter((i) => i.date >= month && i.date <= endOfMonth(month));
  const dayItems = byDate.get(selected) ?? [];
  const canEdit = canManageCalendar(user);

  return (
    <div>
      <PageHeader
        title="Kalender"
        description="Deadline, customer approval, trial/T0, commissioning, material arrival, validation, meeting, dan follow-up."
        actions={
          canEdit && (
            <Button variant="primary" icon={<Plus className="size-4" />} onClick={() => setEditing({ date: selected })}>
              Tambah Event
            </Button>
          )
        }
      />
      <div className="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex items-center gap-1">
          <IconButton label="Bulan sebelumnya" onClick={() => setMonth(addMonths(month, -1))}>
            <ChevronLeft className="size-5" />
          </IconButton>
          <h2 className="min-w-[170px] text-center text-[18px] font-semibold tracking-tight text-ink" aria-live="polite">
            {formatMonthYear(month)}
          </h2>
          <IconButton label="Bulan berikutnya" onClick={() => setMonth(addMonths(month, 1))}>
            <ChevronRight className="size-5" />
          </IconButton>
          <Button
            size="sm"
            variant="ghost"
            onClick={() => {
              setMonth(startOfMonth(today));
              setSelected(today);
            }}
          >
            Hari ini
          </Button>
        </div>
        <ChipToggleGroup label="Kategori event" value={cats} onChange={setCats} options={CATEGORIES.map((c) => ({ value: c, label: CALENDAR_CATEGORY_LABEL[c], dotClass: TONE_MARK[CALENDAR_CATEGORY_TONE[c]] }))} />
      </div>

      {error ? (
        <Card>
          <ErrorState error={error} onRetry={() => refetch()} />
        </Card>
      ) : (
        <div className="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
          {/* Month grid (tablet/desktop) */}
          <Card className="hidden overflow-hidden md:block">
            <div className="grid grid-cols-7 border-b border-line">
              {WEEKDAYS_SHORT.map((w) => (
                <div key={w} className="px-2 py-2 text-center text-[12px] font-medium text-ink-3">
                  {w}
                </div>
              ))}
            </div>
            <div className="grid grid-cols-7" role="grid" aria-label={`Kalender ${formatMonthYear(month)}`}>
              {days.map((d) => {
                const list = byDate.get(d) ?? [];
                const inMonth = d.slice(0, 7) === month.slice(0, 7);
                const isToday = d === today;
                const isSel = d === selected;
                return (
                  <button
                    key={d}
                    type="button"
                    role="gridcell"
                    aria-selected={isSel}
                    aria-label={`${formatDate(d)}, ${list.length} event`}
                    onClick={() => setSelected(d)}
                    onDoubleClick={() => canEdit && setEditing({ date: d })}
                    className={cn('flex min-h-[112px] flex-col gap-1 border-r border-b border-line p-1.5 text-left transition-colors [&:nth-child(7n)]:border-r-0', !inMonth && 'bg-surface-2/50', isSel ? 'bg-accent-soft/60' : 'hover:bg-surface-2')}
                  >
                    <span className={cn('tabular flex size-6 items-center justify-center self-end rounded-full text-[12px]', isToday ? 'bg-mark-red font-semibold text-white' : inMonth ? 'text-ink' : 'text-ink-3')}>
                      {parseISODate(d).getDate()}
                    </span>
                    {isLoading ? (
                      <Skeleton className="h-4" />
                    ) : (
                      <>
                        {list.slice(0, 3).map((i) => (
                          <span key={i.id} className="flex items-center gap-1 truncate rounded-md bg-surface-2 px-1.5 py-0.5 text-[11px] text-ink" title={`${CALENDAR_CATEGORY_LABEL[i.category]}: ${i.title}`}>
                            <span className={cn('size-1.5 shrink-0 rounded-full', TONE_MARK[CALENDAR_CATEGORY_TONE[i.category]])} aria-hidden="true" />
                            <span className="truncate">{i.title}</span>
                          </span>
                        ))}
                        {list.length > 3 && <span className="px-1.5 text-[11px] font-medium text-ink-2">+{list.length - 3} lagi</span>}
                      </>
                    )}
                  </button>
                );
              })}
            </div>
          </Card>

          {/* Selected day agenda */}
          <Card className="hidden self-start md:block">
            <div className="flex items-center justify-between px-5 pt-4 pb-2">
              <h2 className="text-[15px] font-semibold text-ink">{formatDate(selected)}</h2>
              {canEdit && (
                <Button size="sm" variant="ghost" icon={<Plus className="size-4" />} onClick={() => setEditing({ date: selected })}>
                  Event
                </Button>
              )}
            </div>
            {dayItems.length === 0 ? <EmptyState compact icon={<CalendarDays className="size-5" />} title="Tidak ada event" /> : <Agenda items={dayItems} onEdit={(i) => setEditing({ item: i, date: i.date })} canEditItem={(i) => !!i.eventId && canEdit && (i.createdById === user.id || user.role === 'admin')} />}
          </Card>

          {/* Mobile agenda for the whole month */}
          <div className="md:hidden">
            {isLoading ? (
              <Skeleton className="h-64" />
            ) : monthItems.length === 0 ? (
              <Card>
                <EmptyState compact icon={<CalendarDays className="size-5" />} title="Tidak ada event bulan ini" />
              </Card>
            ) : (
              <div className="space-y-4">
                {[...new Set(monthItems.map((i) => i.date))].map((d) => (
                  <section key={d}>
                    <h3 className={cn('mb-1.5 text-[13px] font-semibold', d === today ? 'text-tone-red' : 'text-ink-2')}>
                      {formatDate(d)}
                      {d === today && ' · Hari ini'}
                    </h3>
                    <Card>
                      <Agenda items={monthItems.filter((i) => i.date === d)} onEdit={(i) => setEditing({ item: i, date: i.date })} canEditItem={(i) => !!i.eventId && canEdit && (i.createdById === user.id || user.role === 'admin')} />
                    </Card>
                  </section>
                ))}
              </div>
            )}
          </div>
        </div>
      )}
      {editing && <EventDialog initial={editing} onClose={() => setEditing(null)} />}
    </div>
  );
}

function Agenda({ items, onEdit, canEditItem }: { items: CalendarItem[]; onEdit: (i: CalendarItem) => void; canEditItem: (i: CalendarItem) => boolean }) {
  return (
    <ul className="divide-y divide-line">
      {items.map((i) => (
        <li key={i.id} className="flex items-start gap-3 px-5 py-3">
          <span className={cn('mt-1.5 size-2.5 shrink-0 rounded-full', TONE_MARK[CALENDAR_CATEGORY_TONE[i.category]])} aria-hidden="true" />
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-1.5">
              <Badge tone={CALENDAR_CATEGORY_TONE[i.category]} size="sm">
                {CALENDAR_CATEGORY_LABEL[i.category]}
              </Badge>
              {i.time && <span className="tabular text-[12px] text-ink-2">{i.time}</span>}
            </div>
            <p className="mt-1 text-[14px] font-medium text-ink">{i.title}</p>
            <p className="text-[12px] text-ink-3">{i.subtitle}</p>
            {i.notes && <p className="mt-1 text-[13px] text-ink-2">{i.notes}</p>}
            {i.projectCode && (
              <Link to={`/projects/${i.projectCode}`} className="mt-1 inline-block text-[12px] font-medium text-accent-ink hover:underline">
                Buka project
              </Link>
            )}
          </div>
          {canEditItem(i) && (
            <IconButton label={`Edit ${i.title}`} size="sm" onClick={() => onEdit(i)}>
              <Pencil className="size-4" />
            </IconButton>
          )}
        </li>
      ))}
    </ul>
  );
}

function EventDialog({ initial, onClose }: { initial: { item?: CalendarItem; date: string }; onClose: () => void }) {
  const toast = useToast();
  const projects = useProjects();
  const it = initial.item;
  const [values, setValues] = useState<EventInput>({
    title: it?.title ?? '',
    category: (it?.category as 'meeting' | 'follow_up') ?? 'meeting',
    date: it?.date ?? initial.date,
    time: it?.time ?? '',
    projectId: it?.projectId ?? '',
    notes: it?.notes ?? '',
  });
  const [confirm, setConfirm] = useState(false);
  const save = useAction(() => saveEvent(values, it?.eventId));
  const del = useAction(() => deleteEvent(it!.eventId!));
  const fe = isAppError(save.error) ? (save.error.fieldErrors ?? {}) : {};
  const set = <K extends keyof EventInput>(k: K, v: EventInput[K]) => setValues((s) => ({ ...s, [k]: v }));
  return (
    <>
      <Modal
        open
        onClose={onClose}
        size="sm"
        title={it ? 'Edit Event' : 'Tambah Event'}
        description="Meeting atau follow-up. Event project lain muncul otomatis dari data workflow."
        footer={
          <>
            {it && (
              <Button variant="destructive" icon={<Trash2 className="size-4" />} className="mr-auto" onClick={() => setConfirm(true)}>
                Hapus
              </Button>
            )}
            <Button variant="secondary" onClick={onClose}>
              Batal
            </Button>
            <Button
              variant="primary"
              loading={save.isPending}
              onClick={() =>
                save.mutate(undefined, {
                  onSuccess: () => {
                    toast.success(it ? 'Event diperbarui' : 'Event ditambahkan', values.title);
                    onClose();
                  },
                })
              }
            >
              Simpan
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          {save.error && !Object.keys(fe).length ? <InlineAlert tone="red">{errorMessage(save.error)}</InlineAlert> : null}
          <Field label="Judul" htmlFor="ev-title" required error={fe.title}>
            <Input id="ev-title" value={values.title} onChange={(e) => set('title', e.target.value)} invalid={!!fe.title} placeholder="mis. Review artwork dengan customer" />
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Kategori" htmlFor="ev-cat">
              <Select id="ev-cat" value={values.category} onChange={(e) => set('category', e.target.value as 'meeting' | 'follow_up')}>
                <option value="meeting">Meeting</option>
                <option value="follow_up">Follow-up</option>
              </Select>
            </Field>
            <Field label="Tanggal" htmlFor="ev-date" required error={fe.date}>
              <Input id="ev-date" type="date" value={values.date} onChange={(e) => set('date', e.target.value)} invalid={!!fe.date} />
            </Field>
            <Field label="Jam" htmlFor="ev-time" error={fe.time} helper="Opsional">
              <Input id="ev-time" type="time" value={values.time ?? ''} onChange={(e) => set('time', e.target.value)} />
            </Field>
            <Field label="Project" htmlFor="ev-proj" helper="Opsional">
              <Select id="ev-proj" value={values.projectId ?? ''} onChange={(e) => set('projectId', e.target.value)}>
                <option value="">Tanpa project</option>
                {projects.data
                  ?.filter((p) => p.project.status !== 'cancelled')
                  .map((p) => (
                    <option key={p.project.id} value={p.project.id}>
                      {p.project.code} · {p.project.name}
                    </option>
                  ))}
              </Select>
            </Field>
          </div>
          <Field label="Catatan" htmlFor="ev-notes">
            <Textarea id="ev-notes" rows={2} value={values.notes ?? ''} onChange={(e) => set('notes', e.target.value)} />
          </Field>
        </div>
      </Modal>
      <ConfirmDialog
        open={confirm}
        onClose={() => setConfirm(false)}
        destructive
        title="Hapus event?"
        message={`"${values.title}" akan dihapus dari kalender.`}
        confirmLabel="Hapus"
        loading={del.isPending}
        onConfirm={() =>
          del.mutate(undefined, {
            onSuccess: () => {
              toast.success('Event dihapus');
              onClose();
            },
            onError: (e) => toast.fromError(e),
          })
        }
      />
    </>
  );
}
