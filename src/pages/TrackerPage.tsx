import { ChevronDown, Workflow } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { PageHeader } from '@/components/layout/PageHeader';
import { DueText, ProjectStatusChips } from '@/components/project/Chips';
import { Card } from '@/components/ui/Card';
import { EmptyState, ErrorState, ListSkeleton } from '@/components/ui/Feedback';
import { SearchInput, SegmentedControl } from '@/components/ui/Form';
import { useProjects, useWorkflows } from '@/hooks/queries';
import { todayISO } from '@/lib/date';
import { cn, normalize } from '@/lib/utils';
import type { ProjectListItem } from '@/services/api/views';
import type { ProjectType } from '@/types';

/** Cross-project board: active projects grouped by their current process. */
export default function TrackerPage() {
  const today = todayISO();
  const { data, isLoading, error, refetch } = useProjects();
  const workflows = useWorkflows();
  const [type, setType] = useState<ProjectType>('subcont');
  const [q, setQ] = useState('');
  const [includeHold, setIncludeHold] = useState(true);
  const [open, setOpen] = useState<Record<string, boolean>>({});

  const wf = workflows.data?.find((w) => w.projectType === type);
  const items = useMemo(() => {
    const n = normalize(q);
    return (data ?? []).filter(
      (i) =>
        i.project.type === type &&
        i.project.status !== 'completed' &&
        i.project.status !== 'cancelled' &&
        (includeHold || i.project.status !== 'hold') &&
        (!n || normalize(`${i.project.code} ${i.project.name} ${i.customer}`).includes(n)),
    );
  }, [data, type, q, includeHold]);

  // Columns: template processes (+ any process key found on projects created from older template versions).
  const columns = useMemo(() => {
    const cols = (wf?.processes ?? []).map((p) => ({ key: p.key, name: p.name, loopOnly: !!p.loopOnly }));
    for (const i of items) if (i.current && !cols.some((c) => c.key === i.current!.key)) cols.push({ key: i.current.key, name: i.current.name, loopOnly: false });
    return cols;
  }, [wf, items]);
  const byKey = (key: string) => items.filter((i) => i.current?.key === key);
  const counts = { subcont: (data ?? []).filter((i) => i.project.type === 'subcont' && i.project.status !== 'completed' && i.project.status !== 'cancelled').length, new_mold: (data ?? []).filter((i) => i.project.type === 'new_mold' && i.project.status !== 'completed' && i.project.status !== 'cancelled').length };

  return (
    <div>
      <PageHeader title="Process Tracker" description="Posisi setiap project aktif pada workflow — siapa PIC dan apa next action-nya." />
      <div className="mb-4 flex flex-col gap-2 md:flex-row md:items-center">
        <SegmentedControl
          label="Workflow"
          value={type}
          onChange={setType}
          options={[
            { value: 'subcont', label: 'Subcont', count: counts.subcont },
            { value: 'new_mold', label: 'New Mold', count: counts.new_mold },
          ]}
        />
        <SearchInput value={q} onChange={setQ} placeholder="Cari project…" className="md:w-72" />
        <label className="flex items-center gap-2 text-[13px] text-ink-2 md:ml-auto">
          <input type="checkbox" checked={includeHold} onChange={(e) => setIncludeHold(e.target.checked)} className="size-4 accent-[var(--c-accent)]" />
          Tampilkan project Hold
        </label>
      </div>

      {isLoading || workflows.isLoading ? (
        <ListSkeleton rows={4} />
      ) : error ? (
        <Card>
          <ErrorState error={error} onRetry={() => refetch()} />
        </Card>
      ) : items.length === 0 ? (
        <Card>
          <EmptyState icon={<Workflow className="size-5" />} title="Tidak ada project aktif" description="Project aktif pada workflow ini akan tampil per proses." />
        </Card>
      ) : (
        <>
          {/* Desktop board */}
          <div className="hidden overflow-x-auto pb-4 scrollbar-thin md:block">
            <div className="flex gap-3">
              {columns.map((c, idx) => {
                const list = byKey(c.key);
                return (
                  <section key={c.key} aria-label={c.name} className={cn('flex shrink-0 flex-col rounded-2xl bg-surface-3/50 p-2', list.length === 0 ? 'w-[150px]' : 'w-[248px]')}>
                    <header className="flex items-start justify-between gap-2 px-2 pt-1 pb-2">
                      <h2 className="text-[12px] leading-snug font-semibold text-ink-2">
                        <span className="tabular text-ink-3">{String(idx + 1).padStart(2, '0')}</span> {c.name}
                        {c.loopOnly && <span className="block font-normal text-ink-3">loop</span>}
                      </h2>
                      <span className="tabular rounded-full bg-surface px-1.5 text-[11px] font-semibold text-ink-2">{list.length}</span>
                    </header>
                    <div className="space-y-2">
                      {list.length === 0 && <p className="px-2 pb-2 text-[12px] text-ink-3">Tidak ada project</p>}
                      {list.map((i) => (
                        <TrackerCard key={i.project.id} i={i} today={today} />
                      ))}
                    </div>
                  </section>
                );
              })}
            </div>
          </div>

          {/* Mobile accordion by process */}
          <div className="space-y-2 md:hidden">
            {columns
              .filter((c) => byKey(c.key).length > 0)
              .map((c) => {
                const list = byKey(c.key);
                const isOpen = open[c.key] ?? true;
                return (
                  <Card key={c.key}>
                    <button type="button" aria-expanded={isOpen} onClick={() => setOpen((o) => ({ ...o, [c.key]: !isOpen }))} className="flex min-h-12 w-full items-center justify-between px-4 text-left">
                      <span className="text-[14px] font-semibold text-ink">{c.name}</span>
                      <span className="flex items-center gap-2">
                        <span className="tabular rounded-full bg-surface-2 px-2 text-[12px] font-semibold text-ink-2">{list.length}</span>
                        <ChevronDown className={cn('size-4 text-ink-3 transition-transform', isOpen && 'rotate-180')} />
                      </span>
                    </button>
                    {isOpen && (
                      <div className="space-y-2 px-3 pb-3">
                        {list.map((i) => (
                          <TrackerCard key={i.project.id} i={i} today={today} />
                        ))}
                      </div>
                    )}
                  </Card>
                );
              })}
          </div>
        </>
      )}
    </div>
  );
}

function TrackerCard({ i, today }: { i: ProjectListItem; today: string }) {
  return (
    <Link to={`/projects/${i.project.code}`} className="block rounded-xl border border-line bg-surface p-3 shadow-card transition-colors hover:border-line-strong">
      <p className="text-[11px] text-ink-3">
        {i.project.code} · {i.customer}
      </p>
      <p className="mt-0.5 line-clamp-2 text-[13px] font-semibold text-ink">{i.project.name}</p>
      <div className="mt-2">
        <ProjectStatusChips status={i.displayStatus} baseStatus={i.project.status} overdueDays={i.overdueDays} size="sm" />
      </div>
      <p className="mt-2 text-[12px] text-ink-2">PIC {i.current?.pic}</p>
      <p className="mt-1 line-clamp-2 text-[12px] text-ink">{i.project.nextAction}</p>
      <DueText date={i.project.nextActionDue} today={today} state={i.nextActionState} className="mt-0.5 text-[11px]" />
    </Link>
  );
}
