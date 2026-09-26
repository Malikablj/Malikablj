import { BadgeCheck, ChevronRight, MessageSquare } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { StatTile } from '@/components/charts/Charts';
import { ManualDecisionDialog, waitingDays } from '@/components/approvals/ApprovalComponents';
import { PageHeader } from '@/components/layout/PageHeader';
import { ApprovalStatusChip } from '@/components/project/Chips';
import { CompleteProcessDialog } from '@/components/project/CompleteProcessDialog';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState, ErrorState, ListSkeleton, NoResults } from '@/components/ui/Feedback';
import { SearchInput, Select } from '@/components/ui/Form';
import { Tabs } from '@/components/ui/Tabs';
import { APPROVAL_STATUS_LABEL, APPROVAL_TYPE_LABEL, APPROVAL_TYPES } from '@/config/labels';
import { useApprovals } from '@/hooks/queries';
import { useDebounced } from '@/hooks/useUtils';
import { formatDate, todayISO } from '@/lib/date';
import { average, cn, normalize, round1 } from '@/lib/utils';
import type { ApprovalRow } from '@/services/api/approvals';
import type { ApprovalStatus, ApprovalType } from '@/types';

type TabKey = 'mine' | 'pending' | 'all';

export default function ApprovalsPage() {
  const today = todayISO();
  const { data, isLoading, error, refetch } = useApprovals();
  const [tab, setTab] = useState<TabKey>('mine');
  const [q, setQ] = useState('');
  const dq = useDebounced(q);
  const [type, setType] = useState<'' | ApprovalType>('');
  const [status, setStatus] = useState<'' | ApprovalStatus>('');
  const [approver, setApprover] = useState<'' | 'customer' | 'internal'>('');
  const [linked, setLinked] = useState<ApprovalRow | null>(null);
  const [manual, setManual] = useState<ApprovalRow | null>(null);

  const all = useMemo(() => data ?? [], [data]);
  const counts = {
    mine: all.filter((a) => a.canDecide).length,
    pending: all.filter((a) => a.status === 'pending').length,
    all: all.length,
  };
  const rows = useMemo(() => {
    const n = normalize(dq);
    return all.filter(
      (a) =>
        (tab === 'all' || (tab === 'pending' ? a.status === 'pending' : a.canDecide)) &&
        (!type || a.type === type) &&
        (!status || a.status === status) &&
        (!approver || a.approverType === approver) &&
        (!n || normalize(`${a.code} ${a.projectCode} ${a.projectName} ${a.customer} ${a.revision} ${a.processName}`).includes(n)),
    );
  }, [all, tab, type, status, approver, dq]);

  const pendingWaits = all.filter((a) => a.status === 'pending').map((a) => waitingDays(a, today));
  const decidedCustomer = all.filter((a) => a.approverType === 'customer' && a.decisionDate).map((a) => waitingDays(a, today));
  const monthPrefix = today.slice(0, 7);
  const rejectedMonth = all.filter((a) => (a.status === 'rejected' || a.status === 'revision_required') && a.decisionDate?.startsWith(monthPrefix)).length;

  const decide = (a: ApprovalRow) => (a.linkedToWorkflow ? setLinked(a) : setManual(a));

  return (
    <div>
      <PageHeader title="Approval" description="Semua approval & rejection Customer maupun internal — setiap loop tercatat dengan komentar." />
      <section className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4" aria-label="Ringkasan approval">
        <StatTile label="Menunggu keputusan Anda" value={counts.mine} tone="yellow" onClick={() => setTab('mine')} active={tab === 'mine'} />
        <StatTile label="Total pending" value={counts.pending} tone="yellow" onClick={() => setTab('pending')} active={tab === 'pending'} />
        <StatTile label="Rata-rata menunggu (pending)" value={pendingWaits.length ? `${round1(average(pendingWaits)!)} hari` : '—'} />
        <StatTile label="Ditolak bulan ini" value={rejectedMonth} tone="red" hint={decidedCustomer.length ? `Avg waiting customer ${round1(average(decidedCustomer)!)} hari` : undefined} />
      </section>

      <Card>
        <Tabs
          className="px-3"
          label="Filter approval"
          value={tab}
          onChange={setTab}
          items={[
            { value: 'mine', label: 'Perlu keputusan saya', count: counts.mine },
            { value: 'pending', label: 'Semua pending', count: counts.pending },
            { value: 'all', label: 'Semua', count: counts.all },
          ]}
        />
        <div className="grid grid-cols-1 gap-2 p-4 md:grid-cols-[1fr_180px_180px_160px]">
          <SearchInput value={q} onChange={setQ} placeholder="Cari kode, project, revisi…" label="Cari approval" />
          <Select aria-label="Approval type" value={type} onChange={(e) => setType(e.target.value as ApprovalType | '')}>
            <option value="">Semua type</option>
            {APPROVAL_TYPES.map((t) => (
              <option key={t} value={t}>
                {APPROVAL_TYPE_LABEL[t]}
              </option>
            ))}
          </Select>
          <Select aria-label="Status approval" value={status} onChange={(e) => setStatus(e.target.value as ApprovalStatus | '')}>
            <option value="">Semua status</option>
            {(Object.keys(APPROVAL_STATUS_LABEL) as ApprovalStatus[]).map((s) => (
              <option key={s} value={s}>
                {APPROVAL_STATUS_LABEL[s]}
              </option>
            ))}
          </Select>
          <Select aria-label="Approver" value={approver} onChange={(e) => setApprover(e.target.value as '' | 'customer' | 'internal')}>
            <option value="">Semua approver</option>
            <option value="customer">Customer</option>
            <option value="internal">Internal</option>
          </Select>
        </div>
        {isLoading ? (
          <div className="p-4">
            <ListSkeleton />
          </div>
        ) : error ? (
          <ErrorState error={error} onRetry={() => refetch()} />
        ) : all.length === 0 ? (
          <EmptyState icon={<BadgeCheck className="size-5" />} title="Belum ada approval" />
        ) : rows.length === 0 ? (
          tab === 'mine' && !dq && !type && !status && !approver ? (
            <EmptyState compact icon={<BadgeCheck className="size-5" />} title="Tidak ada approval yang menunggu keputusan Anda" description="Approval baru muncul di sini saat workflow memerlukan keputusan Anda." />
          ) : (
            <NoResults
              onReset={() => {
                setQ('');
                setType('');
                setStatus('');
                setApprover('');
              }}
            />
          )
        ) : (
          <ul className="divide-y divide-line border-t border-line">
            {rows.map((a) => (
              <li key={a.id} className={cn('flex flex-col gap-3 px-5 py-4 md:flex-row md:items-center', a.status === 'pending' && 'bg-tone-yellow-soft/20')}>
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span className="text-[14px] font-semibold text-ink">{APPROVAL_TYPE_LABEL[a.type]}</span>
                    <span className="text-[13px] text-ink-2">· {a.revision}</span>
                    <ApprovalStatusChip status={a.status} size="sm" />
                    {!a.linkedToWorkflow && <span className="text-[11px] text-ink-3">manual</span>}
                  </div>
                  <Link to={`/projects/${a.projectCode}`} className="mt-1 inline-flex items-center gap-1 text-[13px] text-ink hover:underline">
                    {a.projectCode} · {a.projectName} <ChevronRight className="size-3.5 text-ink-3" />
                  </Link>
                  <p className="text-[12px] text-ink-3">
                    {a.code} · {a.processName} · approver {a.approverName} · diajukan {formatDate(a.requestedDate)} ({a.requestedBy})
                  </p>
                  <p className={cn('text-[12px]', a.status === 'pending' && waitingDays(a, today) > 5 ? 'font-medium text-tone-red' : 'text-ink-3')}>
                    {a.status === 'pending' ? `Menunggu ${waitingDays(a, today)} hari` : `Diputuskan ${formatDate(a.decisionDate)} oleh ${a.decidedBy ?? '—'} · ${waitingDays(a, today)} hari`}
                  </p>
                  {a.comment && (
                    <p className="mt-1.5 flex items-start gap-1.5 text-[13px] text-ink-2">
                      <MessageSquare className="mt-0.5 size-3.5 shrink-0 text-ink-3" aria-hidden="true" /> {a.comment}
                    </p>
                  )}
                </div>
                {a.canDecide && (
                  <Button variant="primary" size="sm" onClick={() => decide(a)} className="self-start md:self-center">
                    Putuskan
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>
      {linked && <CompleteProcessDialog projectCode={linked.projectCode} processId={linked.processId} onClose={() => setLinked(null)} />}
      <ManualDecisionDialog approval={manual} onClose={() => setManual(null)} />
    </div>
  );
}
