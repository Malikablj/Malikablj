import { ArrowRight, ChevronRight, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { BarList, type BarDatum, Distribution, StatTile } from '@/components/charts/Charts';
import { PageHeader } from '@/components/layout/PageHeader';
import { DueText, ProjectStatusChips } from '@/components/project/Chips';
import { ButtonLink } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { EmptyState, ErrorState, PageSkeleton } from '@/components/ui/Feedback';
import { PillTabs, SegmentedControl, Select } from '@/components/ui/Form';
import { PRIORITY_LABEL, PRIORITY_TONE, PROJECT_TYPE_LABEL, STATUS_LABEL, STATUS_TONE } from '@/config/labels';
import { ATTENTION_LABEL, type AttentionKey } from '@/domain/metrics';
import { canCreateProject, projectScopeLabel } from '@/domain/permissions';
import { useProjects } from '@/hooks/queries';
import { useUser } from '@/hooks/useAuth';
import { formatDate, todayISO } from '@/lib/date';
import { cn } from '@/lib/utils';
import type { ProjectListItem } from '@/services/api/views';
import type { DisplayStatus, Priority, ProjectType } from '@/types';

const ATTENTION_KEYS: AttentionKey[] = ['overdue', 'dueSoon', 'waitingApproval', 'waitingExternal', 'noUpdate', 'missingDocument'];

function countBy(items: ProjectListItem[], key: (i: ProjectListItem) => string): Map<string, number> {
  const m = new Map<string, number>();
  for (const i of items) m.set(key(i), (m.get(key(i)) ?? 0) + 1);
  return m;
}

function attentionReason(i: ProjectListItem, key: AttentionKey): string {
  switch (key) {
    case 'overdue':
      return `Terlambat ${i.overdueDays} hari dari target ${formatDate(i.project.targetDate)}`;
    case 'dueSoon':
      return `Target ${formatDate(i.project.targetDate)} (${i.daysToTarget === 0 ? 'hari ini' : `${i.daysToTarget} hari lagi`})`;
    case 'waitingApproval':
      return `Menunggu ${i.project.waitingFor ?? 'approval'}`;
    case 'waitingExternal':
      return `Menunggu ${i.project.waitingFor ?? 'pihak eksternal'}`;
    case 'noUpdate':
      return `Tidak ada update ${i.daysSinceUpdate} hari`;
    case 'missingDocument':
      return `Dokumen wajib belum ada: ${i.missingDocs.join(', ')}`;
  }
}

export default function DashboardPage() {
  const user = useUser();
  const navigate = useNavigate();
  const today = todayISO();
  const { data, isLoading, error, refetch } = useProjects();
  const [type, setType] = useState<'all' | ProjectType>('all');
  const [customer, setCustomer] = useState('');
  const [pic, setPic] = useState('');
  const [priority, setPriority] = useState<'' | Priority>('');
  const [attention, setAttention] = useState<AttentionKey>('overdue');

  const all = data ?? [];
  const customers = useMemo(() => [...new Set(all.map((i) => i.customer))].sort(), [all]);
  const pics = useMemo(() => [...new Set(all.map((i) => i.npdPic))].sort(), [all]);

  const items = useMemo(
    () =>
      all.filter(
        (i) =>
          (type === 'all' || i.project.type === type) &&
          (!customer || i.customer === customer) &&
          (!pic || i.npdPic === pic) &&
          (!priority || i.project.priority === priority),
      ),
    [all, type, customer, pic, priority],
  );
  const active = items.filter((i) => i.project.status !== 'completed' && i.project.status !== 'cancelled');
  const myWork = active.filter((i) => i.current && i.current.pic === user.name && i.project.status !== 'hold');

  if (isLoading) return <PageSkeleton />;
  if (error) return <ErrorState error={error} onRetry={() => refetch()} />;

  const kpi = {
    total: items.length,
    newMold: items.filter((i) => i.project.type === 'new_mold').length,
    subcont: items.filter((i) => i.project.type === 'subcont').length,
    onProgress: active.filter((i) => i.project.status === 'on_progress').length,
    waiting: active.filter((i) => i.project.status === 'waiting_approval' || i.project.status === 'waiting_external').length,
    overdue: active.filter((i) => i.flags.overdue).length,
    dueSoon: active.filter((i) => i.flags.dueSoon).length,
    completed: items.filter((i) => i.project.status === 'completed').length,
  };

  const attentionLists = Object.fromEntries(ATTENTION_KEYS.map((k) => [k, active.filter((i) => i.flags[k])])) as Record<AttentionKey, ProjectListItem[]>;
  const attentionItems = attentionLists[attention];

  const toProjects = (params: Record<string, string>) => navigate(`/projects?${new URLSearchParams(params).toString()}`);
  const bar = (m: Map<string, number>, onClick?: (k: string) => void): BarDatum[] =>
    [...m.entries()].sort((a, b) => b[1] - a[1]).map(([k, v]) => ({ key: k, label: k, value: v, onClick: onClick ? () => onClick(k) : undefined }));

  const byProcess = bar(countBy(active, (i) => i.current?.name ?? '—'));
  const byCustomer = bar(countBy(items, (i) => i.customer), (k) => toProjects({ customer: k }));
  const byPic = bar(countBy(active, (i) => i.current?.pic ?? '—'));
  const statusCounts = countBy(items, (i) => i.displayStatus);
  const byStatus: BarDatum[] = (Object.keys(STATUS_LABEL) as DisplayStatus[])
    .filter((s) => statusCounts.get(s))
    .map((s) => ({ key: s, label: STATUS_LABEL[s], value: statusCounts.get(s)!, tone: STATUS_TONE[s] }));
  const byType: BarDatum[] = (['new_mold', 'subcont'] as ProjectType[]).map((t, idx) => ({
    key: t,
    label: PROJECT_TYPE_LABEL[t],
    value: items.filter((i) => i.project.type === t).length,
    tone: idx === 0 ? 'blue' : 'orange',
  }));
  const byPriority: BarDatum[] = (['urgent', 'high', 'medium', 'low'] as Priority[]).map((p) => ({
    key: p,
    label: PRIORITY_LABEL[p],
    value: items.filter((i) => i.project.priority === p).length,
    tone: PRIORITY_TONE[p],
    onClick: () => toProjects({ priority: p }),
  }));

  const filtered = type !== 'all' || customer || pic || priority;

  return (
    <div>
      <PageHeader
        title="Dashboard"
        description={`${projectScopeLabel(user)} · per ${formatDate(today)}`}
        actions={
          canCreateProject(user) && (
            <ButtonLink to="/projects/new" variant="primary" icon={<Plus className="size-4" />}>
              Project Baru
            </ButtonLink>
          )
        }
      />

      <div className="mb-5 flex flex-col gap-2 md:flex-row md:flex-wrap md:items-center" role="group" aria-label="Filter dashboard">
        <SegmentedControl
          label="Project Type"
          value={type}
          onChange={setType}
          options={[
            { value: 'all', label: 'Semua' },
            { value: 'new_mold', label: 'New Mold' },
            { value: 'subcont', label: 'Subcont' },
          ]}
        />
        <div className="grid grid-cols-3 gap-2 md:flex">
          <Select aria-label="Filter customer" value={customer} onChange={(e) => setCustomer(e.target.value)} className="md:w-44">
            <option value="">Customer</option>
            {customers.map((c) => (
              <option key={c}>{c}</option>
            ))}
          </Select>
          <Select aria-label="Filter NPD PIC" value={pic} onChange={(e) => setPic(e.target.value)} className="md:w-44">
            <option value="">NPD PIC</option>
            {pics.map((c) => (
              <option key={c}>{c}</option>
            ))}
          </Select>
          <Select aria-label="Filter priority" value={priority} onChange={(e) => setPriority(e.target.value as Priority | '')} className="md:w-36">
            <option value="">Priority</option>
            {(Object.keys(PRIORITY_LABEL) as Priority[]).map((p) => (
              <option key={p} value={p}>
                {PRIORITY_LABEL[p]}
              </option>
            ))}
          </Select>
        </div>
        {filtered && (
          <button
            type="button"
            onClick={() => {
              setType('all');
              setCustomer('');
              setPic('');
              setPriority('');
            }}
            className="self-start text-[13px] font-medium text-accent-ink hover:underline md:self-center"
          >
            Reset filter
          </button>
        )}
      </div>

      <section aria-label="KPI" className="grid grid-cols-2 gap-3 sm:grid-cols-4 min-[1400px]:grid-cols-8">
        <StatTile label="Total Project" value={kpi.total} onClick={() => navigate('/projects')} />
        <StatTile label="New Mold" value={kpi.newMold} onClick={() => toProjects({ type: 'new_mold' })} />
        <StatTile label="Subcont" value={kpi.subcont} onClick={() => toProjects({ type: 'subcont' })} />
        <StatTile label="On Progress" value={kpi.onProgress} tone="blue" onClick={() => toProjects({ status: 'on_progress' })} />
        <StatTile label="Waiting" value={kpi.waiting} tone="yellow" hint="Approval + External" onClick={() => toProjects({ status: 'waiting' })} />
        <StatTile label="Overdue" value={kpi.overdue} tone="red" onClick={() => toProjects({ status: 'overdue' })} />
        <StatTile label="Due Soon" value={kpi.dueSoon} tone="yellow" onClick={() => toProjects({ status: 'due_soon' })} />
        <StatTile label="Completed" value={kpi.completed} tone="green" onClick={() => toProjects({ status: 'completed' })} />
      </section>

      <div className="mt-6 grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
        <Card>
          <CardHeader title="Attention Required" description="Project aktif yang perlu ditindaklanjuti" />
          <div className="px-5">
            <PillTabs
              label="Kategori attention"
              value={attention}
              onChange={setAttention}
              options={ATTENTION_KEYS.map((k) => ({ value: k, label: ATTENTION_LABEL[k], count: attentionLists[k].length }))}
            />
          </div>
          {attentionItems.length === 0 ? (
            <EmptyState compact title={`Tidak ada project ${ATTENTION_LABEL[attention]}`} description="Bagus — tidak ada yang perlu ditindaklanjuti pada kategori ini." />
          ) : (
            <ul className="mt-2 divide-y divide-line">
              {attentionItems.map((i) => (
                <li key={i.project.id}>
                  <Link to={`/projects/${i.project.code}`} className="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-surface-2">
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span className="truncate text-[14px] font-medium text-ink">{i.project.name}</span>
                        <span className="text-[12px] text-ink-3">{i.project.code}</span>
                      </div>
                      <p className="mt-0.5 truncate text-[13px] text-ink-2">
                        {i.customer} · {i.current?.name ?? '—'} · <span className="text-ink">{attentionReason(i, attention)}</span>
                      </p>
                    </div>
                    <div className="hidden shrink-0 sm:block">
                      <ProjectStatusChips status={i.displayStatus} baseStatus={i.project.status} overdueDays={i.overdueDays} size="sm" />
                    </div>
                    <ChevronRight className="size-4 shrink-0 text-ink-3" aria-hidden="true" />
                  </Link>
                </li>
              ))}
            </ul>
          )}
          <div className="h-2" />
        </Card>

        <Card>
          <CardHeader
            title="Menunggu tindakan Anda"
            description="Proses aktif dengan Anda sebagai PIC"
            action={
              <Link to="/projects?mine=1" className="text-[13px] font-medium text-accent-ink hover:underline">
                Lihat semua
              </Link>
            }
          />
          {myWork.length === 0 ? (
            <EmptyState compact title="Tidak ada tugas aktif" description="Proses yang di-assign ke Anda akan muncul di sini." />
          ) : (
            <ul className="divide-y divide-line">
              {myWork.slice(0, 6).map((i) => (
                <li key={i.project.id}>
                  <Link to={`/projects/${i.project.code}`} className="block px-5 py-3 transition-colors hover:bg-surface-2">
                    <p className="truncate text-[14px] font-medium text-ink">{i.current?.name}</p>
                    <p className="truncate text-[13px] text-ink-2">
                      {i.project.code} · {i.project.name}
                    </p>
                    <p className="mt-1 truncate text-[13px] text-ink">{i.project.nextAction}</p>
                    <DueText date={i.project.nextActionDue} today={today} state={i.nextActionState} className="mt-0.5" />
                  </Link>
                </li>
              ))}
            </ul>
          )}
          <div className="h-2" />
        </Card>
      </div>

      <section aria-label="Grafik" className="mt-6 grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
        <ChartCard title="Project by Process" description="Current process project aktif">
          <BarList label="Project by process" data={byProcess} />
        </ChartCard>
        <ChartCard title="Project by Status" description="Overdue dihitung dari target date">
          <Distribution label="Project by status" data={byStatus} onSelect={(k) => toProjects({ status: k })} />
        </ChartCard>
        <ChartCard title="Project by Customer">
          <BarList label="Project by customer" data={byCustomer} />
        </ChartCard>
        <ChartCard title="Project by PIC" description="PIC proses aktif">
          <BarList label="Project by PIC" data={byPic} />
        </ChartCard>
        <ChartCard title="Project by Project Type">
          <Distribution label="Project by type" data={byType} onSelect={(k) => toProjects({ type: k })} />
        </ChartCard>
        <ChartCard title="Project by Priority">
          <BarList label="Project by priority" data={byPriority} />
        </ChartCard>
      </section>

      <div className="mt-6 flex justify-end">
        <Link to="/reports" className={cn('inline-flex items-center gap-1 text-[13px] font-medium text-accent-ink hover:underline')}>
          Lihat laporan & analytics <ArrowRight className="size-4" />
        </Link>
      </div>
    </div>
  );
}

function ChartCard({ title, description, children }: { title: string; description?: string; children: React.ReactNode }) {
  return (
    <Card>
      <CardHeader title={title} description={description} as="h3" />
      <div className="px-5 pt-1 pb-5">{children}</div>
    </Card>
  );
}
