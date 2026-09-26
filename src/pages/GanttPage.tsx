import { ChevronRight, ChartGantt } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { PageHeader } from '@/components/layout/PageHeader';
import { ProjectStatusChips } from '@/components/project/Chips';
import { Card } from '@/components/ui/Card';
import { EmptyState, ErrorState, ListSkeleton, NoResults } from '@/components/ui/Feedback';
import { Field, Input, SegmentedControl, Select } from '@/components/ui/Form';
import { ProgressBar } from '@/components/ui/Misc';
import { PRIORITY_LABEL, STATUS_LABEL } from '@/config/labels';
import { useProjectDetail, useProjects } from '@/hooks/queries';
import { useIsDesktop } from '@/hooks/useUtils';
import { addDays, addMonths, diffDays, formatDate, formatDateShort, maxDate, minDate, monthShort, parseISODate, startOfMonth, todayISO } from '@/lib/date';
import { cn } from '@/lib/utils';
import type { ProjectListItem } from '@/services/api/views';
import type { DisplayStatus, Priority, ProjectType } from '@/types';

const ZOOM = { week: 26, month: 9, quarter: 3.2 } as const;
type Zoom = keyof typeof ZOOM;

export default function GanttPage() {
  const today = todayISO();
  const desktop = useIsDesktop();
  const { data, isLoading, error, refetch } = useProjects();
  const [view, setView] = useState<'gantt' | 'list'>(desktop ? 'gantt' : 'list');
  const [zoom, setZoom] = useState<Zoom>('month');
  const [type, setType] = useState<'' | ProjectType>('');
  const [customer, setCustomer] = useState('');
  const [pic, setPic] = useState('');
  const [status, setStatus] = useState<'' | DisplayStatus | 'active'>('active');
  const [priority, setPriority] = useState<'' | Priority>('');
  const [from, setFrom] = useState(addMonths(startOfMonth(today), -3));
  const [to, setTo] = useState(addDays(addMonths(startOfMonth(today), 4), -1));
  const [expanded, setExpanded] = useState<string | null>(null);

  const all = data ?? [];
  const customers = useMemo(() => [...new Set(all.map((i) => i.customer))].sort(), [all]);
  const pics = useMemo(() => [...new Set(all.map((i) => i.npdPic))].sort(), [all]);
  const rangeInvalid = from > to;
  const items = useMemo(
    () =>
      all
        .filter(
          (i) =>
            (!type || i.project.type === type) &&
            (!customer || i.customer === customer) &&
            (!pic || i.npdPic === pic) &&
            (!priority || i.project.priority === priority) &&
            (!status || (status === 'active' ? i.project.status !== 'completed' && i.project.status !== 'cancelled' : i.displayStatus === status)) &&
            // overlaps date range
            i.project.startDate <= to &&
            (i.project.actualFinish ?? maxDate(i.project.targetDate, i.project.status === 'completed' || i.project.status === 'cancelled' ? i.project.targetDate : today)) >= from,
        )
        .sort((a, b) => a.project.startDate.localeCompare(b.project.startDate)),
    [all, type, customer, pic, priority, status, from, to, today],
  );

  return (
    <div>
      <PageHeader title="Timeline / Gantt" description="Timeline project dan proses — planned vs actual." />
      <Card className="mb-4 p-4">
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
          <Field label="Project Type" htmlFor="g-type">
            <Select id="g-type" value={type} onChange={(e) => setType(e.target.value as ProjectType | '')}>
              <option value="">Semua</option>
              <option value="new_mold">New Mold</option>
              <option value="subcont">Subcont</option>
            </Select>
          </Field>
          <Field label="Customer" htmlFor="g-cus">
            <Select id="g-cus" value={customer} onChange={(e) => setCustomer(e.target.value)}>
              <option value="">Semua</option>
              {customers.map((c) => (
                <option key={c}>{c}</option>
              ))}
            </Select>
          </Field>
          <Field label="NPD PIC" htmlFor="g-pic">
            <Select id="g-pic" value={pic} onChange={(e) => setPic(e.target.value)}>
              <option value="">Semua</option>
              {pics.map((c) => (
                <option key={c}>{c}</option>
              ))}
            </Select>
          </Field>
          <Field label="Status" htmlFor="g-st">
            <Select id="g-st" value={status} onChange={(e) => setStatus(e.target.value as DisplayStatus | '' | 'active')}>
              <option value="active">Semua aktif</option>
              <option value="">Semua status</option>
              {(Object.keys(STATUS_LABEL) as DisplayStatus[]).map((s) => (
                <option key={s} value={s}>
                  {STATUS_LABEL[s]}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Priority" htmlFor="g-pr">
            <Select id="g-pr" value={priority} onChange={(e) => setPriority(e.target.value as Priority | '')}>
              <option value="">Semua</option>
              {(Object.keys(PRIORITY_LABEL) as Priority[]).map((p) => (
                <option key={p} value={p}>
                  {PRIORITY_LABEL[p]}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Dari" htmlFor="g-from" error={rangeInvalid ? 'Tanggal awal > akhir' : undefined}>
            <Input id="g-from" type="date" value={from} onChange={(e) => e.target.value && setFrom(e.target.value)} invalid={rangeInvalid} />
          </Field>
          <Field label="Sampai" htmlFor="g-to">
            <Input id="g-to" type="date" value={to} onChange={(e) => e.target.value && setTo(e.target.value)} invalid={rangeInvalid} />
          </Field>
          <div className="flex flex-col justify-end gap-1.5">
            <span className="text-[13px] font-medium text-ink">Tampilan</span>
            <SegmentedControl
              label="Tampilan"
              value={view}
              onChange={setView}
              options={[
                { value: 'gantt', label: 'Gantt' },
                { value: 'list', label: 'Daftar' },
              ]}
            />
          </div>
        </div>
      </Card>

      {isLoading ? (
        <ListSkeleton rows={6} />
      ) : error ? (
        <Card>
          <ErrorState error={error} onRetry={() => refetch()} />
        </Card>
      ) : all.length === 0 ? (
        <Card>
          <EmptyState icon={<ChartGantt className="size-5" />} title="Belum ada project" />
        </Card>
      ) : rangeInvalid ? (
        <Card>
          <EmptyState compact title="Rentang tanggal tidak valid" description="Tanggal awal harus sebelum tanggal akhir." />
        </Card>
      ) : items.length === 0 ? (
        <Card>
          <NoResults
            onReset={() => {
              setType('');
              setCustomer('');
              setPic('');
              setPriority('');
              setStatus('active');
            }}
          />
        </Card>
      ) : view === 'gantt' ? (
        <GanttChart items={items} from={from} to={to} today={today} zoom={zoom} setZoom={setZoom} expanded={expanded} setExpanded={setExpanded} />
      ) : (
        <ul className="space-y-3">
          {items.map((i) => {
            const span = Math.max(1, diffDays(i.project.startDate, i.project.targetDate));
            const elapsed = Math.min(100, Math.max(0, (diffDays(i.project.startDate, today) / span) * 100));
            return (
              <li key={i.project.id}>
                <Link to={`/projects/${i.project.code}`} className="block rounded-2xl border border-line bg-surface p-4 shadow-card hover:border-line-strong">
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <p className="text-[12px] text-ink-3">
                        {i.project.code} · {i.customer}
                      </p>
                      <p className="truncate text-[15px] font-semibold text-ink">{i.project.name}</p>
                    </div>
                    <ChevronRight className="size-4 shrink-0 text-ink-3" />
                  </div>
                  <p className="mt-1 text-[13px] text-ink-2">
                    {formatDate(i.project.startDate)} → {formatDate(i.project.targetDate)} · {i.current?.name}
                  </p>
                  <div className="mt-3 space-y-1.5">
                    <div className="flex items-center justify-between text-[12px] text-ink-3">
                      <span>Progress proses {i.progress.pct}%</span>
                      <span>Waktu berjalan {Math.round(elapsed)}%</span>
                    </div>
                    <ProgressBar value={i.progress.pct} tone={i.overdueDays ? 'red' : 'blue'} label="Progress proses" />
                    <ProgressBar value={elapsed} tone="gray" label="Waktu berjalan" />
                  </div>
                  <div className="mt-3">
                    <ProjectStatusChips status={i.displayStatus} baseStatus={i.project.status} overdueDays={i.overdueDays} size="sm" />
                  </div>
                </Link>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

function GanttChart({
  items,
  from,
  to,
  today,
  zoom,
  setZoom,
  expanded,
  setExpanded,
}: {
  items: ProjectListItem[];
  from: string;
  to: string;
  today: string;
  zoom: Zoom;
  setZoom: (z: Zoom) => void;
  expanded: string | null;
  setExpanded: (id: string | null) => void;
}) {
  const scroller = useRef<HTMLDivElement>(null);
  const px = ZOOM[zoom];
  const days = diffDays(from, to) + 1;
  const width = days * px;
  const x = (d: string) => diffDays(from, d) * px;
  const months: Array<{ start: string; label: string; left: number; width: number }> = [];
  let m = startOfMonth(from);
  while (m <= to) {
    const s = maxDate(m, from);
    const e = minDate(addDays(addMonths(m, 1), -1), to);
    const d = parseISODate(m);
    months.push({ start: m, label: `${monthShort(d.getMonth())} ${d.getFullYear()}`, left: x(s), width: (diffDays(s, e) + 1) * px });
    m = addMonths(m, 1);
  }
  const weeks: string[] = [];
  if (zoom !== 'quarter') for (let d = from; d <= to; d = addDays(d, 1)) if (parseISODate(d).getDay() === 1) weeks.push(d);
  const todayX = today >= from && today <= to ? x(today) + px / 2 : null;

  // Open the chart around "today" (and keep it there when zooming).
  useEffect(() => {
    const el = scroller.current;
    if (el && todayX !== null) el.scrollLeft = Math.max(0, todayX + 240 - el.clientWidth * 0.6);
  }, [todayX]);

  return (
    <Card className="overflow-hidden">
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[12px] text-ink-3">
          <span className="inline-flex items-center gap-1.5">
            <span className="h-2.5 w-6 rounded-full bg-mark-blue/25" /> Rentang project (start → target)
          </span>
          <span className="inline-flex items-center gap-1.5">
            <span className="h-2.5 w-6 rounded-full bg-mark-blue" /> Progress proses
          </span>
          <span className="inline-flex items-center gap-1.5">
            <span className="h-2.5 w-6 rounded-full bg-mark-red" /> Overdue
          </span>
          <span className="inline-flex items-center gap-1.5">
            <span className="h-2.5 w-6 rounded-full bg-mark-green" /> Completed
          </span>
        </div>
        <div className="flex items-center gap-2">
          <SegmentedControl
            label="Zoom"
            size="sm"
            value={zoom}
            onChange={setZoom}
            options={[
              { value: 'week', label: 'Minggu' },
              { value: 'month', label: 'Bulan' },
              { value: 'quarter', label: 'Kuartal' },
            ]}
          />
          {todayX !== null && (
            <button type="button" onClick={() => scroller.current?.scrollTo({ left: Math.max(0, todayX - 300), behavior: 'smooth' })} className="h-7 rounded-lg px-2 text-[12px] font-medium text-accent-ink hover:bg-accent-soft">
              Hari ini
            </button>
          )}
        </div>
      </div>
      <div ref={scroller} className="overflow-x-auto scrollbar-thin" role="region" aria-label="Gantt chart" tabIndex={0}>
        <div style={{ width: width + 240 }} className="relative">
          {/* header */}
          <div className="sticky top-0 z-10 flex border-b border-line bg-surface">
            <div className="sticky left-0 z-20 w-[240px] shrink-0 border-r border-line bg-surface px-4 py-2 text-[12px] font-medium text-ink-3">Project</div>
            <div className="relative h-12" style={{ width }}>
              {months.map((mm) => (
                <div key={mm.start} className="absolute top-0 h-6 border-l border-line px-2 text-[12px] leading-6 font-medium text-ink-2" style={{ left: mm.left, width: mm.width }}>
                  {mm.width > 40 ? mm.label : ''}
                </div>
              ))}
              {weeks.map((w) => (
                <div key={w} className="absolute top-6 h-6 border-l border-line/70 pl-1 text-[10px] leading-6 text-ink-3" style={{ left: x(w) }}>
                  {px * 7 > 34 ? formatDateShort(w) : ''}
                </div>
              ))}
            </div>
          </div>
          {/* rows */}
          <div className="relative">
            {todayX !== null && <div className="pointer-events-none absolute inset-y-0 z-[5] w-[2px] bg-mark-red/70" style={{ left: 240 + todayX }} aria-hidden="true" />}
            {items.map((i) => (
              <GanttRow key={i.project.id} i={i} x={x} px={px} from={from} to={to} width={width} today={today} expanded={expanded === i.project.id} onToggle={() => setExpanded(expanded === i.project.id ? null : i.project.id)} />
            ))}
          </div>
        </div>
      </div>
    </Card>
  );
}

function GanttRow({
  i,
  x,
  px,
  from,
  to,
  width,
  today,
  expanded,
  onToggle,
}: {
  i: ProjectListItem;
  x: (d: string) => number;
  px: number;
  from: string;
  to: string;
  width: number;
  today: string;
  expanded: boolean;
  onToggle: () => void;
}) {
  const p = i.project;
  const clamp = (d: string) => minDate(maxDate(d, from), to);
  const start = clamp(p.startDate);
  const target = clamp(p.targetDate);
  const end = clamp(p.actualFinish ?? p.targetDate);
  const done = p.status === 'completed';
  const barLeft = x(start);
  const barWidth = Math.max(px, x(done ? end : target) - barLeft + px);
  const overdueW = i.overdueDays > 0 ? Math.max(0, x(clamp(today)) - x(target)) : 0;
  return (
    <div className="border-b border-line last:border-b-0">
      <div className="flex hover:bg-surface-2/60">
        <div className="sticky left-0 z-[6] flex w-[240px] shrink-0 items-center gap-1 border-r border-line bg-surface px-2 py-2">
          <button type="button" onClick={onToggle} aria-expanded={expanded} aria-label={`${expanded ? 'Tutup' : 'Buka'} proses ${p.name}`} className="flex size-7 shrink-0 items-center justify-center rounded-md text-ink-3 hover:bg-surface-2">
            <ChevronRight className={cn('size-4 transition-transform', expanded && 'rotate-90')} />
          </button>
          <Link to={`/projects/${p.code}`} className="min-w-0 flex-1">
            <span className="block truncate text-[13px] font-medium text-ink hover:underline">{p.name}</span>
            <span className="block truncate text-[11px] text-ink-3">
              {p.code} · {i.current?.shortName}
            </span>
          </Link>
        </div>
        <div className="relative h-12" style={{ width }}>
          <div
            className={cn('group absolute top-1/2 h-3 -translate-y-1/2 rounded-full', done ? 'bg-mark-green' : 'bg-mark-blue/25')}
            style={{ left: barLeft, width: barWidth }}
            title={`${p.name}: ${formatDate(p.startDate)} → ${formatDate(p.targetDate)} · ${i.progress.pct}%`}
          >
            {!done && <div className="h-full rounded-full bg-mark-blue" style={{ width: `${i.progress.pct}%` }} />}
          </div>
          {overdueW > 0 && <div className="absolute top-1/2 h-3 -translate-y-1/2 rounded-r-full bg-mark-red" style={{ left: x(target) + px, width: overdueW }} title={`Overdue ${i.overdueDays} hari`} />}
        </div>
      </div>
      {expanded && <ProcessRows code={p.code} x={x} px={px} from={from} to={to} width={width} today={today} />}
    </div>
  );
}

function ProcessRows({ code, x, px, from, to, width, today }: { code: string; x: (d: string) => number; px: number; from: string; to: string; width: number; today: string }) {
  const { data, isLoading } = useProjectDetail(code);
  if (isLoading || !data)
    return (
      <div className="flex">
        <div className="sticky left-0 w-[240px] shrink-0 border-r border-line bg-surface px-4 py-2 text-[12px] text-ink-3">Memuat proses…</div>
      </div>
    );
  const clamp = (d: string) => minDate(maxDate(d, from), to);
  return (
    <div className="bg-surface-2/40">
      {data.processes
        .filter((pr) => pr.status !== 'skipped' && !(pr.loopOnly && pr.status === 'not_started'))
        .map((pr) => {
          const aEnd = pr.actualFinish ?? (pr.actualStart && ['current', 'revision', 'problem'].includes(pr.status) ? today : undefined);
          const visible = pr.plannedFinish >= from && pr.plannedStart <= to;
          return (
            <div key={pr.id} className="flex">
              <div className="sticky left-0 z-[6] w-[240px] shrink-0 truncate border-r border-line bg-surface-2 py-1.5 pr-2 pl-11 text-[12px] text-ink-2">
                {String(pr.sequence).padStart(2, '0')} · {pr.shortName}
              </div>
              <div className="relative h-8" style={{ width }}>
                {visible && <div className="absolute top-[9px] h-2 rounded-full bg-mark-track" style={{ left: x(clamp(pr.plannedStart)), width: Math.max(px, x(clamp(pr.plannedFinish)) - x(clamp(pr.plannedStart)) + px) }} title={`Planned ${formatDate(pr.plannedStart)} – ${formatDate(pr.plannedFinish)}`} />}
                {pr.actualStart && aEnd && aEnd >= from && pr.actualStart <= to && (
                  <div
                    className={cn('absolute top-[17px] h-2 rounded-full', pr.status === 'completed' ? 'bg-mark-green' : pr.status === 'problem' ? 'bg-mark-red' : 'bg-mark-blue')}
                    style={{ left: x(clamp(pr.actualStart)), width: Math.max(px, x(clamp(aEnd)) - x(clamp(pr.actualStart)) + px) }}
                    title={`Actual ${formatDate(pr.actualStart)} – ${pr.actualFinish ? formatDate(pr.actualFinish) : 'berjalan'}`}
                  />
                )}
              </div>
            </div>
          );
        })}
    </div>
  );
}
