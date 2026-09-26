import { AlertTriangle, ChevronLeft, ChevronRight, Copy, Download, Printer } from 'lucide-react';
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { BarList, StatTile } from '@/components/charts/Charts';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button, IconButton } from '@/components/ui/Button';
import { Card, CardHeader } from '@/components/ui/Card';
import { EmptyState, ErrorState, PageSkeleton } from '@/components/ui/Feedback';
import { SegmentedControl } from '@/components/ui/Form';
import { Tabs } from '@/components/ui/Tabs';
import { useToast } from '@/components/ui/Toast';
import { PROJECT_TYPE_LABEL } from '@/config/labels';
import { type Analytics, processStatLabel, type WeeklyReport, weeklyReportText } from '@/domain/analytics';
import { projectScopeLabel } from '@/domain/permissions';
import { useAnalytics, useWeeklyReport } from '@/hooks/queries';
import { useUser } from '@/hooks/useAuth';
import { addDays, describeDue, formatDate, startOfWeek, todayISO } from '@/lib/date';
import { cn } from '@/lib/utils';
import { buildXlsx, downloadBlob } from '@/lib/xlsx';
import type { ProjectType } from '@/types';

export default function ReportsPage() {
  const user = useUser();
  const [tab, setTab] = useState<'weekly' | 'analytics'>('weekly');
  return (
    <div>
      <PageHeader title="Laporan" description={`Weekly NPD Report & analytics · ${projectScopeLabel(user)}`} />
      <Tabs
        className="no-print mb-5"
        label="Jenis laporan"
        value={tab}
        onChange={setTab}
        items={[
          { value: 'weekly', label: 'Weekly NPD Report' },
          { value: 'analytics', label: 'Analytics' },
        ]}
      />
      {tab === 'weekly' ? <Weekly /> : <AnalyticsView />}
    </div>
  );
}

function Weekly() {
  const today = todayISO();
  const toast = useToast();
  const [start, setStart] = useState(startOfWeek(today));
  const { data: r, isLoading, error, refetch } = useWeeklyReport(start);
  const isCurrent = start === startOfWeek(today);

  const exportXlsx = (rep: WeeklyReport) => {
    const blob = buildXlsx([
      {
        name: 'Summary',
        widths: [24, 14],
        rows: [
          ['Weekly NPD Report', ''],
          ['Reporting Period', `${rep.periodStart} s/d ${rep.periodEnd}`],
          ['Total Project', rep.totalProject],
          ['New Project', rep.newProject],
          ['Completed', rep.completed],
          ['On Progress', rep.onProgress],
          ['Waiting Customer', rep.waitingCustomer],
          ['Waiting Supplier', rep.waitingSupplier],
          ['Overdue', rep.overdue],
          ['Due Soon', rep.dueSoon],
        ],
      },
      { name: 'Top Issues', widths: [14, 48, 60], rows: [['Project', 'Issue', 'Detail'], ...rep.topIssues.map((i) => [i.code, i.title, i.detail])] },
      {
        name: 'Action Required',
        widths: [14, 34, 44, 20, 12, 10],
        rows: [['Project', 'Project Name', 'Action', 'Owner', 'Due', 'Terlambat'], ...rep.actionRequired.map((a) => [a.code, a.projectName, a.action, a.owner, a.due, a.overdue ? 'Ya' : ''])],
      },
    ]);
    try {
      downloadBlob(blob, `Weekly-NPD-Report-${rep.periodStart}.xlsx`);
      toast.success('Weekly report diexport');
    } catch (e) {
      toast.fromError(e, 'Export gagal');
    }
  };

  return (
    <div>
      <div className="no-print mb-4 flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-1">
          <IconButton label="Minggu sebelumnya" onClick={() => setStart(addDays(start, -7))}>
            <ChevronLeft className="size-5" />
          </IconButton>
          <p className="min-w-[210px] text-center text-[15px] font-semibold text-ink" aria-live="polite">
            {formatDate(start)} – {formatDate(addDays(start, 6))}
          </p>
          <IconButton label="Minggu berikutnya" onClick={() => setStart(addDays(start, 7))} disabled={isCurrent}>
            <ChevronRight className="size-5" />
          </IconButton>
          {!isCurrent && (
            <Button size="sm" variant="ghost" onClick={() => setStart(startOfWeek(today))}>
              Minggu ini
            </Button>
          )}
        </div>
        {r && (
          <div className="flex flex-wrap gap-2">
            <Button
              icon={<Copy className="size-4" />}
              onClick={() =>
                navigator.clipboard?.writeText(weeklyReportText(r, formatDate)).then(
                  () => toast.success('Report disalin', 'Siap ditempel ke email / chat.'),
                  () => toast.error('Gagal menyalin'),
                )
              }
            >
              Salin teks
            </Button>
            {!__SANDBOX__ && (
              <Button icon={<Printer className="size-4" />} onClick={() => window.print()}>
                Print / PDF
              </Button>
            )}
            <Button variant="primary" icon={<Download className="size-4" />} onClick={() => exportXlsx(r)}>
              Export Excel
            </Button>
          </div>
        )}
      </div>

      {isLoading ? (
        <PageSkeleton />
      ) : error || !r ? (
        <Card>
          <ErrorState error={error} onRetry={() => refetch()} />
        </Card>
      ) : (
        <div className="print-area space-y-5">
          <p className="hidden text-[18px] font-semibold print:block">
            Weekly NPD Report · {formatDate(r.periodStart)} – {formatDate(r.periodEnd)}
          </p>
          <section className="grid grid-cols-2 gap-3 md:grid-cols-4 min-[1400px]:grid-cols-8" aria-label="Ringkasan minggu ini">
            <StatTile label="Total Project" value={r.totalProject} />
            <StatTile label="New Project" value={r.newProject} hint="Dibuat pada periode" />
            <StatTile label="Completed" value={r.completed} tone="green" hint="Selesai pada periode" />
            <StatTile label="On Progress" value={r.onProgress} tone="blue" />
            <StatTile label="Waiting Customer" value={r.waitingCustomer} tone="yellow" />
            <StatTile label="Waiting Supplier" value={r.waitingSupplier} tone="yellow" />
            <StatTile label="Overdue" value={r.overdue} tone="red" />
            <StatTile label="Due Soon" value={r.dueSoon} tone="yellow" />
          </section>
          <p className="text-[12px] text-ink-3">New & Completed dihitung dalam periode. Status (On Progress, Waiting, Overdue, Due Soon) adalah snapshot per {formatDate(today)}.</p>
          <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
            <Card>
              <CardHeader title="Top Issues" description="Overdue, problem proses, dan rejection pada periode" />
              {r.topIssues.length === 0 ? (
                <EmptyState compact title="Tidak ada issue" />
              ) : (
                <ol className="divide-y divide-line">
                  {r.topIssues.map((i, n) => (
                    <li key={`${i.code}-${n}`} className="flex gap-3 px-5 py-3">
                      <AlertTriangle className={cn('mt-0.5 size-4 shrink-0', i.severity === 'high' ? 'text-tone-red' : 'text-tone-orange')} aria-hidden="true" />
                      <div className="min-w-0">
                        <Link to={`/projects/${i.code}`} className="text-[14px] font-medium text-ink hover:underline">
                          {i.title}
                        </Link>
                        <p className="text-[12px] text-ink-3">
                          {i.code} · {i.detail}
                        </p>
                      </div>
                    </li>
                  ))}
                </ol>
              )}
            </Card>
            <Card>
              <CardHeader title="Action Required" description="Next action yang jatuh tempo s/d akhir periode" />
              {r.actionRequired.length === 0 ? (
                <EmptyState compact title="Tidak ada action" />
              ) : (
                <ol className="divide-y divide-line">
                  {r.actionRequired.map((a) => (
                    <li key={a.projectId} className="px-5 py-3">
                      <Link to={`/projects/${a.code}`} className="text-[14px] font-medium text-ink hover:underline">
                        {a.action}
                      </Link>
                      <p className="text-[12px] text-ink-3">
                        {a.code} · {a.projectName} · {a.owner}
                      </p>
                      <p className={cn('text-[12px]', a.overdue ? 'font-medium text-tone-red' : 'text-ink-2')}>
                        Due {formatDate(a.due)} · {describeDue(a.due, today)}
                      </p>
                    </li>
                  ))}
                </ol>
              )}
            </Card>
          </div>
        </div>
      )}
    </div>
  );
}

function AnalyticsView() {
  const toast = useToast();
  const { data: a, isLoading, error, refetch } = useAnalytics();
  const [type, setType] = useState<ProjectType>('subcont');
  if (isLoading) return <PageSkeleton />;
  if (error || !a)
    return (
      <Card>
        <ErrorState error={error} onRetry={() => refetch()} />
      </Card>
    );
  const stats = a.processStats.filter((s) => s.type === type);
  const exportXlsx = (an: Analytics) => {
    try {
    downloadBlob(
      buildXlsx([
        {
          name: 'KPI',
          widths: [40, 16],
          rows: [
            ['Metric', 'Value'],
            ['Average Project Lead Time (hari)', an.avgLeadTime ?? ''],
            ['Completed projects', an.completedCount],
            ['Average Customer Approval Waiting (hari)', an.avgCustomerApprovalWait ?? ''],
            ['Pending Customer Approval Waiting (hari)', an.pendingCustomerApprovalWait ?? ''],
            ['Overdue count', an.overdueCount],
            ['Average overdue duration (hari)', an.avgOverdueDays ?? ''],
            ['Max overdue (hari)', an.maxOverdueDays],
            ['Bottleneck process', an.bottleneck ? processStatLabel(an.bottleneck) : ''],
          ],
        },
        {
          name: 'Process Duration',
          widths: [34, 12, 14, 14, 10, 10],
          rows: [['Process', 'Type', 'Avg Actual (hari)', 'Avg Plan (hari)', 'Sampel', 'Maks'], ...an.processStats.map((s) => [s.name, PROJECT_TYPE_LABEL[s.type], s.avgDuration, s.avgPlanned, s.samples, s.maxDuration])],
        },
        {
          name: 'Loops',
          widths: [14, 36, 26, 10],
          rows: [
            ['Project', 'Name', 'Metric', 'Count'],
            ...an.artworkRevisions.map((l) => [l.code, l.name, 'Artwork revisions', l.count]),
            ...an.t0Loops.map((l) => [l.code, l.name, 'T0 loops', l.count]),
            ...an.trialRejections.map((l) => [l.code, l.name, 'Trial rejection loops', l.count]),
          ],
        },
      ]),
      `NPD-Analytics-${todayISO()}.xlsx`,
    );
    toast.success('Analytics diexport');
    } catch (e) {
      toast.fromError(e, 'Export gagal');
    }
  };

  return (
    <div className="space-y-5">
      <div className="flex justify-end">
        <Button icon={<Download className="size-4" />} onClick={() => exportXlsx(a)}>
          Export Excel
        </Button>
      </div>
      <section className="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="KPI analytics">
        <StatTile label="Avg Project Lead Time" value={a.avgLeadTime !== null ? `${a.avgLeadTime} hari` : '—'} hint={`${a.completedCount} project completed`} />
        <StatTile label="Avg Customer Approval Waiting" value={a.avgCustomerApprovalWait !== null ? `${a.avgCustomerApprovalWait} hari` : '—'} hint={a.pendingCustomerApprovalWait !== null ? `Pending saat ini rata-rata ${a.pendingCustomerApprovalWait} hari` : `${a.customerApprovalSamples} keputusan`} />
        <StatTile label="Overdue" value={a.overdueCount} tone="red" hint={a.avgOverdueDays !== null ? `Rata-rata ${a.avgOverdueDays} hari · maks ${a.maxOverdueDays} hari` : 'Tidak ada overdue'} />
        <StatTile label="Bottleneck Process" value={a.bottleneck ? a.bottleneck.name : '—'} hint={a.bottleneck ? `${PROJECT_TYPE_LABEL[a.bottleneck.type]} · aktual ${a.bottleneck.avgDuration} hari vs plan ${a.bottleneck.avgPlanned} hari` : undefined} />
      </section>

      <Card>
        <CardHeader
          title="Average Process Duration"
          description="Durasi aktual rata-rata per proses (proses berjalan dihitung s/d hari ini)"
          action={
            <SegmentedControl
              label="Workflow"
              size="sm"
              value={type}
              onChange={setType}
              options={[
                { value: 'subcont', label: 'Subcont' },
                { value: 'new_mold', label: 'New Mold' },
              ]}
            />
          }
        />
        <div className="px-5 pb-5">
          <BarList
            label="Average process duration"
            unit="hari"
            data={stats.map((s) => ({
              key: s.key,
              label: s.name,
              value: s.avgDuration,
              tone: s.avgDuration > s.avgPlanned * 1.2 ? 'orange' : 'blue',
              hint: `plan ${s.avgPlanned} hari · ${s.samples} sampel · maks ${s.maxDuration} hari`,
            }))}
            emptyText="Belum ada proses yang berjalan / selesai."
          />
          <p className="mt-3 text-[12px] text-ink-3">Oranye = rata-rata aktual &gt; 120% dari plan. Arahkan kursor untuk detail plan & sampel.</p>
        </div>
      </Card>

      <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <Card>
          <CardHeader title="Artwork Revisions" description="Per project Subcont" as="h3" />
          <div className="px-5 pb-5">
            <BarList label="Artwork revisions" data={a.artworkRevisions.map((l) => ({ key: l.projectId, label: `${l.code} · ${l.name}`, value: l.count, tone: 'orange' }))} emptyText="Belum ada project Subcont." />
          </div>
        </Card>
        <Card>
          <CardHeader title="T0 Loops" description="Per project New Mold" as="h3" />
          <div className="px-5 pb-5">
            <BarList label="T0 loops" data={a.t0Loops.map((l) => ({ key: l.projectId, label: `${l.code} · ${l.name}`, value: l.count, tone: 'orange' }))} emptyText="Belum ada project New Mold." />
          </div>
        </Card>
        <Card>
          <CardHeader title="Trial Rejection Loops" description="Per project Subcont" as="h3" />
          <div className="px-5 pb-5">
            <BarList label="Trial rejection loops" data={a.trialRejections.map((l) => ({ key: l.projectId, label: `${l.code} · ${l.name}`, value: l.count, tone: 'orange' }))} emptyText="Belum ada project Subcont." />
          </div>
        </Card>
      </div>
    </div>
  );
}
