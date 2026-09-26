import { APPROVAL_TYPE_LABEL, PROJECT_TYPE_LABEL } from '@/config/labels';
import { addDays, diffDays, formatDate, isBetween } from '@/lib/date';
import { average, round1 } from '@/lib/utils';
import type { AppSettings, Approval, DbState, ISODate, Project, ProjectProcess, ProjectType } from '@/types';
import { currentProcess, userName } from './context';
import { isActiveProject, isDueSoon, isOverdue, isRunning, overdueDays, processDuration } from './metrics';

/** Reports & analytics (PRD §17). All figures are computed from stored data. */

export interface WeeklyIssue {
  projectId: string;
  code: string;
  title: string;
  detail: string;
  severity: 'high' | 'medium';
}

export interface WeeklyAction {
  projectId: string;
  code: string;
  projectName: string;
  action: string;
  due: ISODate;
  owner: string;
  overdue: boolean;
}

export interface WeeklyReport {
  periodStart: ISODate;
  periodEnd: ISODate;
  totalProject: number;
  newProject: number;
  completed: number;
  onProgress: number;
  waitingCustomer: number;
  waitingSupplier: number;
  overdue: number;
  dueSoon: number;
  topIssues: WeeklyIssue[];
  actionRequired: WeeklyAction[];
}

export function weeklyReport(db: DbState, projects: Project[], periodStart: ISODate, today: ISODate): WeeklyReport {
  const periodEnd = addDays(periodStart, 6);
  const ids = new Set(projects.map((p) => p.id));
  const existing = projects.filter((p) => p.status !== 'cancelled' && p.createdAt.slice(0, 10) <= periodEnd);
  const active = projects.filter(isActiveProject);
  const settings = db.settings;

  const issues: WeeklyIssue[] = [];
  for (const p of active) {
    const d = overdueDays(p, today);
    if (d > 0) issues.push({ projectId: p.id, code: p.code, title: `${p.name} overdue ${d} hari`, detail: `Target ${formatDate(p.targetDate)} · ${p.nextAction}`, severity: 'high' });
  }
  for (const proc of db.processes) {
    if (!ids.has(proc.projectId) || proc.status !== 'problem') continue;
    const p = projects.find((x) => x.id === proc.projectId)!;
    if (!isActiveProject(p)) continue;
    issues.push({ projectId: p.id, code: p.code, title: `Problem di ${proc.name}`, detail: proc.problemNote ?? proc.lastOutcome ?? 'Perlu tindak lanjut', severity: 'high' });
  }
  for (const a of db.approvals) {
    if (!ids.has(a.projectId) || (a.status !== 'rejected' && a.status !== 'revision_required')) continue;
    if (!a.decisionDate || !isBetween(a.decisionDate, periodStart, periodEnd)) continue;
    const p = projects.find((x) => x.id === a.projectId)!;
    issues.push({ projectId: p.id, code: p.code, title: `${APPROVAL_TYPE_LABEL[a.type]} ditolak (${a.revision})`, detail: a.comment ?? '—', severity: 'medium' });
  }

  const actions: WeeklyAction[] = active
    .filter((p) => p.status !== 'hold' && p.nextActionDue <= periodEnd)
    .map((p) => {
      const cur = currentProcess(db, p);
      return {
        projectId: p.id,
        code: p.code,
        projectName: p.name,
        action: p.nextAction,
        due: p.nextActionDue,
        owner: cur ? userName(db, cur.picId) : userName(db, p.npdPicId),
        overdue: p.nextActionDue < today,
      };
    })
    .sort((a, b) => a.due.localeCompare(b.due));

  return {
    periodStart,
    periodEnd,
    totalProject: existing.length,
    newProject: projects.filter((p) => isBetween(p.createdAt.slice(0, 10), periodStart, periodEnd)).length,
    completed: projects.filter((p) => p.actualFinish && isBetween(p.actualFinish, periodStart, periodEnd)).length,
    onProgress: active.filter((p) => p.status === 'on_progress').length,
    waitingCustomer: active.filter((p) => p.status === 'waiting_approval').length,
    waitingSupplier: active.filter((p) => p.status === 'waiting_external').length,
    overdue: active.filter((p) => isOverdue(p, today)).length,
    dueSoon: active.filter((p) => isDueSoon(p, today, settings.dueSoonDays)).length,
    topIssues: issues.sort((a, b) => (a.severity === b.severity ? 0 : a.severity === 'high' ? -1 : 1)).slice(0, 10),
    actionRequired: actions,
  };
}

export function weeklyReportText(r: WeeklyReport, fmt: (d: ISODate) => string): string {
  const lines = [
    `WEEKLY NPD REPORT`,
    `Periode: ${fmt(r.periodStart)} – ${fmt(r.periodEnd)}`,
    '',
    `Total Project     : ${r.totalProject}`,
    `New Project       : ${r.newProject}`,
    `Completed         : ${r.completed}`,
    `On Progress       : ${r.onProgress}`,
    `Waiting Customer  : ${r.waitingCustomer}`,
    `Waiting Supplier  : ${r.waitingSupplier}`,
    `Overdue           : ${r.overdue}`,
    `Due Soon          : ${r.dueSoon}`,
    '',
    'TOP ISSUES',
    ...(r.topIssues.length ? r.topIssues.map((i, n) => `${n + 1}. [${i.code}] ${i.title} — ${i.detail}`) : ['- Tidak ada issue.']),
    '',
    'ACTION REQUIRED',
    ...(r.actionRequired.length
      ? r.actionRequired.map((a, n) => `${n + 1}. [${a.code}] ${a.action} — ${a.owner}, due ${fmt(a.due)}${a.overdue ? ' (TERLAMBAT)' : ''}`)
      : ['- Tidak ada action.']),
  ];
  return lines.join('\n');
}

export interface ProcessStat {
  key: string;
  name: string;
  type: ProjectType;
  avgDuration: number;
  avgPlanned: number;
  samples: number;
  running: number;
  maxDuration: number;
}

export interface LoopStat {
  projectId: string;
  code: string;
  name: string;
  count: number;
}

export interface Analytics {
  avgLeadTime: number | null;
  completedCount: number;
  processStats: ProcessStat[];
  bottleneck: ProcessStat | null;
  avgCustomerApprovalWait: number | null;
  pendingCustomerApprovalWait: number | null;
  customerApprovalSamples: number;
  artworkRevisions: LoopStat[];
  t0Loops: LoopStat[];
  trialRejections: LoopStat[];
  overdueCount: number;
  avgOverdueDays: number | null;
  maxOverdueDays: number;
}

function loopStats(db: DbState, projects: Project[], type: ProjectType, filter: (a: Approval) => boolean): LoopStat[] {
  return projects
    .filter((p) => p.type === type && p.status !== 'cancelled')
    .map((p) => ({ projectId: p.id, code: p.code, name: p.name, count: db.approvals.filter((a) => a.projectId === p.id && filter(a)).length }))
    .sort((a, b) => b.count - a.count || a.code.localeCompare(b.code));
}

export function computeAnalytics(db: DbState, projects: Project[], today: ISODate): Analytics {
  const ids = new Set(projects.map((p) => p.id));
  const completed = projects.filter((p) => p.status === 'completed' && p.actualFinish);
  const leadTimes = completed.map((p) => diffDays(p.startDate, p.actualFinish!));

  const byKey = new Map<string, { proc: ProjectProcess; type: ProjectType; durations: number[]; planned: number[]; running: number }>();
  const typeOf = new Map(projects.map((p) => [p.id, p.type]));
  for (const proc of db.processes) {
    if (!ids.has(proc.projectId) || proc.kind === 'finish') continue;
    const type = typeOf.get(proc.projectId)!;
    const k = `${type}:${proc.key}`;
    const entry = byKey.get(k) ?? { proc, type, durations: [], planned: [], running: 0 };
    const d = processDuration(proc, today);
    if (proc.status === 'completed' && d !== null) {
      entry.durations.push(d);
      entry.planned.push(Math.max(0, diffDays(proc.plannedStart, proc.plannedFinish)));
    } else if (isRunning(proc) && d !== null) {
      entry.durations.push(d);
      entry.planned.push(Math.max(0, diffDays(proc.plannedStart, proc.plannedFinish)));
      entry.running += 1;
    }
    byKey.set(k, entry);
  }
  const processStats: ProcessStat[] = [...byKey.values()]
    .filter((e) => e.durations.length > 0)
    .map((e) => ({
      key: e.proc.key,
      name: e.proc.name,
      type: e.type,
      avgDuration: round1(average(e.durations)!),
      avgPlanned: round1(average(e.planned)!),
      samples: e.durations.length,
      running: e.running,
      maxDuration: Math.max(...e.durations),
    }))
    .sort((a, b) => b.avgDuration - a.avgDuration);

  // Bottleneck: largest average overrun versus plan (duration/waiting based), ties by duration.
  const bottleneck =
    [...processStats].sort((a, b) => b.avgDuration - b.avgPlanned - (a.avgDuration - a.avgPlanned) || b.avgDuration - a.avgDuration)[0] ?? null;

  const customerApprovals = db.approvals.filter((a) => ids.has(a.projectId) && a.approverType === 'customer');
  const decidedWaits = customerApprovals.filter((a) => a.decisionDate).map((a) => diffDays(a.requestedDate, a.decisionDate!));
  const pendingWaits = customerApprovals.filter((a) => a.status === 'pending').map((a) => diffDays(a.requestedDate, today));

  const overdue = projects.filter((p) => isOverdue(p, today));
  const overdueDurations = overdue.map((p) => overdueDays(p, today));

  return {
    avgLeadTime: leadTimes.length ? round1(average(leadTimes)!) : null,
    completedCount: completed.length,
    processStats,
    bottleneck,
    avgCustomerApprovalWait: decidedWaits.length ? round1(average(decidedWaits)!) : null,
    pendingCustomerApprovalWait: pendingWaits.length ? round1(average(pendingWaits)!) : null,
    customerApprovalSamples: decidedWaits.length,
    artworkRevisions: loopStats(db, projects, 'subcont', (a) => a.type === 'artwork' && (a.status === 'rejected' || a.status === 'revision_required')),
    t0Loops: loopStats(db, projects, 'new_mold', (a) => a.type === 't0' && a.status === 'rejected'),
    trialRejections: loopStats(db, projects, 'subcont', (a) => a.type === 'trial' && a.status === 'rejected'),
    overdueCount: overdue.length,
    avgOverdueDays: overdueDurations.length ? round1(average(overdueDurations)!) : null,
    maxOverdueDays: overdueDurations.length ? Math.max(...overdueDurations) : 0,
  };
}

export function processStatLabel(s: ProcessStat): string {
  return `${s.name} (${PROJECT_TYPE_LABEL[s.type]})`;
}

export type { AppSettings };
