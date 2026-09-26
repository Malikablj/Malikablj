import { diffDays, isoDateFromDateTime } from '@/lib/date';
import type {
  AppSettings,
  DisplayStatus,
  DocumentVersion,
  ISODate,
  Project,
  ProjectDocument,
  ProjectProcess,
} from '@/types';

/** Derived project metrics (PRD §7 Aging & Duration, Overdue & Due Soon, §10 Attention). */

export const isActiveProject = (p: Pick<Project, 'status'>) => p.status !== 'completed' && p.status !== 'cancelled';

export function overdueDays(p: Project, today: ISODate): number {
  if (!isActiveProject(p)) return 0;
  const d = diffDays(p.targetDate, today);
  return d > 0 ? d : 0;
}

export const isOverdue = (p: Project, today: ISODate) => overdueDays(p, today) > 0;

export function daysToTarget(p: Project, today: ISODate): number {
  return diffDays(today, p.targetDate);
}

export function isDueSoon(p: Project, today: ISODate, threshold: number): boolean {
  if (!isActiveProject(p)) return false;
  const d = daysToTarget(p, today);
  return d >= 0 && d <= threshold;
}

export function displayStatus(p: Project, today: ISODate): DisplayStatus {
  return isOverdue(p, today) ? 'overdue' : p.status;
}

/** Project Aging = Today − Project Start Date (or actual finish for closed projects). */
export function projectAging(p: Project, today: ISODate): number {
  const end = p.actualFinish ?? today;
  return Math.max(0, diffDays(p.startDate, end));
}

/** Process Duration = Actual Finish − Actual Start (running processes count until today). */
export function processDuration(proc: ProjectProcess, today: ISODate): number | null {
  if (!proc.actualStart) return null;
  const end = proc.actualFinish ?? (isRunning(proc) ? today : null);
  if (!end) return null;
  return Math.max(0, diffDays(proc.actualStart, end));
}

export const isRunning = (proc: Pick<ProjectProcess, 'status'>) =>
  proc.status === 'current' || proc.status === 'revision' || proc.status === 'problem';

export type DueState = 'overdue' | 'due_soon' | 'ok';

export function nextActionState(p: Project, today: ISODate, threshold: number): DueState {
  if (!isActiveProject(p) || !p.nextActionDue) return 'ok';
  const d = diffDays(today, p.nextActionDue);
  if (d < 0) return 'overdue';
  if (d <= Math.min(threshold, 1)) return 'due_soon';
  return 'ok';
}

export function processProgress(processes: ProjectProcess[]): { done: number; total: number; pct: number } {
  const applicable = processes.filter((p) => p.status !== 'skipped' && !(p.loopOnly && p.status === 'not_started'));
  const done = applicable.filter((p) => p.status === 'completed').length;
  const total = applicable.length;
  return { done, total, pct: total ? Math.round((done / total) * 100) : 0 };
}

export function daysSinceUpdate(p: Project, today: ISODate): number {
  return Math.max(0, diffDays(isoDateFromDateTime(p.updatedAt), today));
}

/** Documents that satisfy a process' document requirement. */
export function hasRequiredDocument(
  proc: ProjectProcess,
  documents: ProjectDocument[],
  versionsById: Map<string, DocumentVersion>,
): boolean {
  if (!proc.requiresDocument) return true;
  return documents.some((d) => {
    if (d.processId !== proc.id) return false;
    if (proc.requiredDocTypes.length > 0 && !proc.requiredDocTypes.includes(d.type)) return false;
    const latest = versionsById.get(d.latestVersionId);
    return !!latest && latest.status !== 'rejected';
  });
}

/** Mandatory-document gaps: running process or completed process without its required document. */
export function missingDocumentProcesses(
  processes: ProjectProcess[],
  documents: ProjectDocument[],
  versionsById: Map<string, DocumentVersion>,
): ProjectProcess[] {
  return processes.filter(
    (proc) =>
      proc.requiresDocument &&
      (isRunning(proc) || proc.status === 'completed') &&
      !hasRequiredDocument(proc, documents, versionsById),
  );
}

export interface AttentionFlags {
  overdue: boolean;
  dueSoon: boolean;
  waitingApproval: boolean;
  waitingExternal: boolean;
  noUpdate: boolean;
  missingDocument: boolean;
}

export type AttentionKey = keyof AttentionFlags;

export const ATTENTION_LABEL: Record<AttentionKey, string> = {
  overdue: 'Overdue',
  dueSoon: 'Due Soon',
  waitingApproval: 'Waiting Approval',
  waitingExternal: 'Waiting External',
  noUpdate: 'No Update',
  missingDocument: 'Missing Mandatory Document',
};

export function attentionFlags(
  p: Project,
  today: ISODate,
  settings: AppSettings,
  missingDocs: number,
): AttentionFlags {
  const active = isActiveProject(p);
  return {
    overdue: isOverdue(p, today),
    dueSoon: isDueSoon(p, today, settings.dueSoonDays),
    waitingApproval: active && p.status === 'waiting_approval',
    waitingExternal: active && p.status === 'waiting_external',
    noUpdate: active && daysSinceUpdate(p, today) >= settings.noUpdateDays,
    missingDocument: active && missingDocs > 0,
  };
}
