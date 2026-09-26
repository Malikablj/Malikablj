import { ArrowUpDown, ChevronRight, Download, FolderKanban, Plus, SlidersHorizontal } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/components/layout/PageHeader';
import { DueText, PriorityChip, ProjectStatusChips, TypeTag } from '@/components/project/Chips';
import { Button, ButtonLink } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState, ErrorState, ListSkeleton, NoResults } from '@/components/ui/Feedback';
import { SearchInput, Select } from '@/components/ui/Form';
import { ProgressBar } from '@/components/ui/Misc';
import { useToast } from '@/components/ui/Toast';
import { PRIORITY_LABEL, PRIORITY_RANK, PROJECT_TYPE_LABEL, STATUS_LABEL } from '@/config/labels';
import { canCreateProject } from '@/domain/permissions';
import { useProjects } from '@/hooks/queries';
import { useUser } from '@/hooks/useAuth';
import { useDebounced } from '@/hooks/useUtils';
import { formatDate, todayISO } from '@/lib/date';
import { cn, normalize } from '@/lib/utils';
import { buildXlsx, downloadBlob } from '@/lib/xlsx';
import type { ProjectListItem } from '@/services/api/views';
import type { Priority, ProjectType } from '@/types';

const STATUS_FILTERS: Array<{ value: string; label: string; test: (i: ProjectListItem) => boolean }> = [
  { value: 'active', label: 'Semua aktif', test: (i) => i.project.status !== 'completed' && i.project.status !== 'cancelled' },
  { value: 'not_started', label: STATUS_LABEL.not_started, test: (i) => i.project.status === 'not_started' },
  { value: 'on_progress', label: STATUS_LABEL.on_progress, test: (i) => i.project.status === 'on_progress' },
  { value: 'waiting', label: 'Waiting (semua)', test: (i) => i.project.status === 'waiting_approval' || i.project.status === 'waiting_external' },
  { value: 'waiting_approval', label: STATUS_LABEL.waiting_approval, test: (i) => i.project.status === 'waiting_approval' },
  { value: 'waiting_external', label: STATUS_LABEL.waiting_external, test: (i) => i.project.status === 'waiting_external' },
  { value: 'hold', label: STATUS_LABEL.hold, test: (i) => i.project.status === 'hold' },
  { value: 'overdue', label: STATUS_LABEL.overdue, test: (i) => i.flags.overdue },
  { value: 'due_soon', label: 'Due Soon', test: (i) => i.flags.dueSoon },
  { value: 'completed', label: STATUS_LABEL.completed, test: (i) => i.project.status === 'completed' },
  { value: 'cancelled', label: STATUS_LABEL.cancelled, test: (i) => i.project.status === 'cancelled' },
];

const SORTS: Array<{ value: string; label: string; cmp: (a: ProjectListItem, b: ProjectListItem) => number }> = [
  { value: 'code_desc', label: 'Terbaru', cmp: (a, b) => b.project.code.localeCompare(a.project.code) },
  { value: 'target_asc', label: 'Target terdekat', cmp: (a, b) => a.project.targetDate.localeCompare(b.project.targetDate) },
  { value: 'next_action', label: 'Next action terdekat', cmp: (a, b) => a.project.nextActionDue.localeCompare(b.project.nextActionDue) },
  { value: 'priority', label: 'Priority tertinggi', cmp: (a, b) => PRIORITY_RANK[b.project.priority] - PRIORITY_RANK[a.project.priority] || a.project.targetDate.localeCompare(b.project.targetDate) },
  { value: 'overdue', label: 'Paling terlambat', cmp: (a, b) => b.overdueDays - a.overdueDays || a.daysToTarget - b.daysToTarget },
  { value: 'aging', label: 'Aging terlama', cmp: (a, b) => b.aging - a.aging },
  { value: 'updated', label: 'Update terakhir', cmp: (a, b) => b.project.updatedAt.localeCompare(a.project.updatedAt) },
  { value: 'name', label: 'Nama A–Z', cmp: (a, b) => a.project.name.localeCompare(b.project.name) },
];

export default function ProjectsPage() {
  const user = useUser();
  const today = todayISO();
  const navigate = useNavigate();
  const toast = useToast();
  const [params, setParams] = useSearchParams();
  const { data, isLoading, error, refetch } = useProjects();
  const [showFilters, setShowFilters] = useState(false);

  const type = (params.get('type') as ProjectType | null) ?? '';
  const status = params.get('status') ?? '';
  const priority = (params.get('priority') as Priority | null) ?? '';
  const customer = params.get('customer') ?? '';
  const pic = params.get('pic') ?? '';
  const process = params.get('process') ?? '';
  const mine = params.get('mine') === '1';
  const sort = params.get('sort') ?? 'code_desc';
  const [q, setQ] = useState(params.get('q') ?? '');
  const dq = useDebounced(q);

  const set = (key: string, value: string) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    setParams(next, { replace: true });
  };

  const all = useMemo(() => data ?? [], [data]);
  const customers = useMemo(() => [...new Set(all.map((i) => i.customer))].sort(), [all]);
  const pics = useMemo(() => [...new Set(all.map((i) => i.npdPic))].sort(), [all]);
  const processes = useMemo(() => [...new Set(all.filter((i) => !type || i.project.type === type).map((i) => i.current?.name).filter(Boolean) as string[])].sort(), [all, type]);

  const items = useMemo(() => {
    const n = normalize(dq);
    const st = STATUS_FILTERS.find((s) => s.value === status);
    const cmp = SORTS.find((s) => s.value === sort)?.cmp ?? SORTS[0].cmp;
    return all
      .filter(
        (i) =>
          (!type || i.project.type === type) &&
          (!st || st.test(i)) &&
          (!priority || i.project.priority === priority) &&
          (!customer || i.customer === customer) &&
          (!pic || i.npdPic === pic) &&
          (!process || i.current?.name === process) &&
          (!mine || (i.current?.pic === user.name && i.project.status !== 'completed' && i.project.status !== 'cancelled')) &&
          (!n || normalize(`${i.project.code} ${i.project.name} ${i.customer} ${i.project.productName} ${i.current?.name ?? ''} ${i.npdPic}`).includes(n)),
      )
      .sort(cmp);
  }, [all, dq, type, status, priority, customer, pic, process, mine, sort, user.name]);

  const activeFilterCount = [status, priority, customer, pic, process, mine ? '1' : ''].filter(Boolean).length;
  const resetFilters = () => {
    setQ('');
    const next = new URLSearchParams();
    if (type) next.set('type', type);
    setParams(next, { replace: true });
  };

  const exportXlsx = () => {
    const rows = [
      ['Project ID', 'Project Type', 'Customer', 'Project Name', 'Product', 'NPD PIC', 'Sales PIC', 'Drafter', 'Priority', 'Start Date', 'Target Finish', 'Actual Finish', 'Current Process', 'Status', 'Overdue (hari)', 'Waiting For', 'Next Action', 'Next Action Due', 'Progress (%)', 'Aging (hari)', 'Remarks'],
      ...items.map((i) => [
        i.project.code,
        PROJECT_TYPE_LABEL[i.project.type],
        i.customer,
        i.project.name,
        i.project.productName,
        i.npdPic,
        i.salesPic,
        i.drafter,
        PRIORITY_LABEL[i.project.priority],
        i.project.startDate,
        i.project.targetDate,
        i.project.actualFinish ?? '',
        i.current?.name ?? '',
        STATUS_LABEL[i.project.status],
        i.overdueDays || '',
        i.project.waitingFor ?? '',
        i.project.nextAction,
        i.project.nextActionDue,
        i.progress.pct,
        i.aging,
        i.project.remarks ?? '',
      ]),
    ];
    try {
      downloadBlob(buildXlsx([{ name: 'Projects', rows, widths: [14, 12, 18, 32, 22, 16, 16, 16, 10, 12, 12, 12, 28, 16, 12, 28, 40, 14, 10, 10, 30] }]), `NPD-Projects-${today}.xlsx`);
      toast.success('Export berhasil', `${items.length} project diexport ke Excel.`);
    } catch (e) {
      toast.fromError(e, 'Export gagal');
    }
  };

  const title = type ? `Project ${PROJECT_TYPE_LABEL[type]}` : 'Semua Project';

  return (
    <div>
      <PageHeader
        title={title}
        description={type === 'new_mold' ? 'Workflow New Mold: request → 3D/masterbatch → drawing → mold → T0 → validation.' : type === 'subcont' ? 'Workflow Subcont: NPR → artwork → customer approval → trial → material → validation.' : 'New Mold dan Subcont dalam satu sistem.'}
        actions={
          <>
            <Button icon={<Download className="size-4" />} onClick={exportXlsx} disabled={!items.length}>
              Export Excel
            </Button>
            {canCreateProject(user) && (
              <ButtonLink to={type ? `/projects/new?type=${type}` : '/projects/new'} variant="primary" icon={<Plus className="size-4" />}>
                Project Baru
              </ButtonLink>
            )}
          </>
        }
      />

      <div className="mb-4 flex flex-col gap-2">
        <div className="flex gap-2">
          <SearchInput value={q} onChange={(v) => { setQ(v); set('q', v); }} placeholder="Cari kode, nama project, customer, produk…" className="flex-1" label="Cari project" />
          <Button className="lg:hidden" icon={<SlidersHorizontal className="size-4" />} onClick={() => setShowFilters((s) => !s)} aria-expanded={showFilters}>
            Filter{activeFilterCount ? ` (${activeFilterCount})` : ''}
          </Button>
        </div>
        <div className={cn('grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6', !showFilters && 'max-lg:hidden')}>
          <Select aria-label="Project type" value={type} onChange={(e) => set('type', e.target.value)}>
            <option value="">Semua type</option>
            <option value="new_mold">New Mold</option>
            <option value="subcont">Subcont</option>
          </Select>
          <Select aria-label="Status" value={status} onChange={(e) => set('status', e.target.value)}>
            <option value="">Semua status</option>
            {STATUS_FILTERS.map((s) => (
              <option key={s.value} value={s.value}>
                {s.label}
              </option>
            ))}
          </Select>
          <Select aria-label="Priority" value={priority} onChange={(e) => set('priority', e.target.value)}>
            <option value="">Semua priority</option>
            {(Object.keys(PRIORITY_LABEL) as Priority[]).map((p) => (
              <option key={p} value={p}>
                {PRIORITY_LABEL[p]}
              </option>
            ))}
          </Select>
          <Select aria-label="Customer" value={customer} onChange={(e) => set('customer', e.target.value)}>
            <option value="">Semua customer</option>
            {customers.map((c) => (
              <option key={c}>{c}</option>
            ))}
          </Select>
          <Select aria-label="NPD PIC" value={pic} onChange={(e) => set('pic', e.target.value)}>
            <option value="">Semua NPD PIC</option>
            {pics.map((c) => (
              <option key={c}>{c}</option>
            ))}
          </Select>
          <Select aria-label="Current process" value={process} onChange={(e) => set('process', e.target.value)}>
            <option value="">Semua process</option>
            {processes.map((c) => (
              <option key={c}>{c}</option>
            ))}
          </Select>
        </div>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-[13px] text-ink-2" aria-live="polite">
            {isLoading ? 'Memuat…' : `${items.length} dari ${all.length} project`}
            {mine && ' · hanya tugas saya'}
            {(activeFilterCount > 0 || dq) && (
              <button type="button" onClick={resetFilters} className="ml-2 font-medium text-accent-ink hover:underline">
                Reset filter
              </button>
            )}
          </p>
          <label className="flex items-center gap-2 text-[13px] text-ink-2">
            <ArrowUpDown className="size-4" aria-hidden="true" />
            <span className="sr-only">Urutkan</span>
            <select value={sort} onChange={(e) => set('sort', e.target.value)} className="h-8 rounded-lg bg-transparent pr-1 text-[13px] font-medium text-ink outline-none focus-visible:ring-2 focus-visible:ring-[var(--c-focus)]">
              {SORTS.map((s) => (
                <option key={s.value} value={s.value}>
                  {s.label}
                </option>
              ))}
            </select>
          </label>
        </div>
      </div>

      {isLoading ? (
        <ListSkeleton rows={6} />
      ) : error ? (
        <Card>
          <ErrorState error={error} onRetry={() => refetch()} />
        </Card>
      ) : all.length === 0 ? (
        <Card>
          <EmptyState
            icon={<FolderKanban className="size-5" />}
            title="Belum ada project"
            description="Project yang Anda buat atau di-assign ke Anda akan muncul di sini."
            action={canCreateProject(user) && <ButtonLink to="/projects/new" variant="primary">Buat project pertama</ButtonLink>}
          />
        </Card>
      ) : items.length === 0 ? (
        <Card>
          <NoResults onReset={resetFilters} />
        </Card>
      ) : (
        <>
          {/* Desktop table */}
          <Card className="hidden overflow-hidden lg:block">
            <table className="w-full table-fixed text-left">
              <thead>
                <tr className="border-b border-line text-[12px] text-ink-3">
                  <th scope="col" className="w-[26%] px-5 py-3 font-medium">Project</th>
                  <th scope="col" className="w-[19%] px-3 py-3 font-medium">Current Process</th>
                  <th scope="col" className="w-[14%] px-3 py-3 font-medium">Status</th>
                  <th scope="col" className="w-[8%] px-3 py-3 font-medium">Priority</th>
                  <th scope="col" className="w-[19%] px-3 py-3 font-medium">Next Action</th>
                  <th scope="col" className="w-[14%] px-5 py-3 font-medium">Target</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-line">
                {items.map((i) => (
                  <tr key={i.project.id} onClick={() => navigate(`/projects/${i.project.code}`)} className="cursor-pointer align-top transition-colors hover:bg-surface-2">
                    <td className="px-5 py-3.5">
                      <Link to={`/projects/${i.project.code}`} onClick={(e) => e.stopPropagation()} className="block truncate text-[14px] font-medium text-ink hover:underline">
                        {i.project.name}
                      </Link>
                      <p className="mt-0.5 flex items-center gap-1.5 truncate text-[12px] text-ink-3">
                        {i.project.code} · {i.customer} · <TypeTag type={i.project.type} />
                      </p>
                    </td>
                    <td className="px-3 py-3.5">
                      <p className="truncate text-[13px] text-ink">{i.current?.name ?? '—'}</p>
                      <p className="mt-0.5 truncate text-[12px] text-ink-3">
                        {i.current?.pic} · {i.progress.done}/{i.progress.total} proses
                      </p>
                      <ProgressBar value={i.progress.pct} className="mt-1.5 max-w-[160px]" tone={i.project.status === 'completed' ? 'green' : 'blue'} label={`Progress ${i.progress.pct}%`} />
                    </td>
                    <td className="px-3 py-3.5">
                      <ProjectStatusChips status={i.displayStatus} baseStatus={i.project.status} overdueDays={i.overdueDays} size="sm" />
                      {i.project.waitingFor && i.project.status !== 'completed' && <p className="mt-1 truncate text-[12px] text-ink-3">{i.project.waitingFor}</p>}
                    </td>
                    <td className="px-3 py-3.5">
                      <PriorityChip priority={i.project.priority} size="sm" />
                    </td>
                    <td className="px-3 py-3.5">
                      <p className="line-clamp-2 text-[13px] text-ink">{i.project.nextAction}</p>
                      {i.project.status !== 'completed' && i.project.status !== 'cancelled' && (
                        <DueText date={i.project.nextActionDue} today={today} state={i.nextActionState} className="mt-0.5" />
                      )}
                    </td>
                    <td className="px-5 py-3.5">
                      <p className="tabular text-[13px] text-ink">{formatDate(i.project.targetDate)}</p>
                      <p className={cn('mt-0.5 text-[12px]', i.overdueDays ? 'font-medium text-tone-red' : i.flags.dueSoon ? 'font-medium text-tone-yellow' : 'text-ink-3')}>
                        {i.project.status === 'completed'
                          ? `Selesai ${formatDate(i.project.actualFinish)}`
                          : i.project.status === 'cancelled'
                            ? 'Cancelled'
                            : i.overdueDays
                              ? `Terlambat ${i.overdueDays} hari`
                              : `${i.daysToTarget} hari lagi`}
                      </p>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>

          {/* Mobile / tablet cards */}
          <ul className="space-y-3 lg:hidden">
            {items.map((i) => (
              <li key={i.project.id}>
                <Link to={`/projects/${i.project.code}`} className="block rounded-2xl border border-line bg-surface p-4 shadow-card active:bg-surface-2">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="text-[12px] text-ink-3">
                        {i.project.code} · {PROJECT_TYPE_LABEL[i.project.type]}
                      </p>
                      <p className="mt-0.5 text-[15px] font-semibold text-ink">{i.project.name}</p>
                      <p className="text-[13px] text-ink-2">{i.customer}</p>
                    </div>
                    <ChevronRight className="mt-1 size-4 shrink-0 text-ink-3" aria-hidden="true" />
                  </div>
                  <div className="mt-3 flex flex-wrap gap-1.5">
                    <ProjectStatusChips status={i.displayStatus} baseStatus={i.project.status} overdueDays={i.overdueDays} size="sm" />
                    <PriorityChip priority={i.project.priority} size="sm" />
                  </div>
                  <div className="mt-3 rounded-xl bg-surface-2 px-3 py-2.5">
                    <p className="text-[12px] text-ink-3">Current process</p>
                    <p className="text-[14px] font-medium text-ink">{i.current?.name ?? '—'}</p>
                    <p className="text-[12px] text-ink-2">PIC {i.current?.pic}</p>
                    <ProgressBar value={i.progress.pct} className="mt-2" tone={i.project.status === 'completed' ? 'green' : 'blue'} label={`Progress ${i.progress.pct}%`} />
                  </div>
                  {i.project.status !== 'completed' && i.project.status !== 'cancelled' && (
                    <div className="mt-3">
                      <p className="text-[13px] text-ink">{i.project.nextAction}</p>
                      <DueText date={i.project.nextActionDue} today={today} state={i.nextActionState} />
                    </div>
                  )}
                </Link>
              </li>
            ))}
          </ul>
        </>
      )}
    </div>
  );
}
