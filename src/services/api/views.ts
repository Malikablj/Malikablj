import { currentProcess, customerName, projectProcesses, userName, versionsById } from '@/domain/context';
import {
  type AttentionFlags,
  attentionFlags,
  daysSinceUpdate,
  daysToTarget,
  displayStatus,
  type DueState,
  missingDocumentProcesses,
  nextActionState,
  overdueDays,
  processProgress,
  projectAging,
} from '@/domain/metrics';
import type { DbState, DisplayStatus, ISODate, Project, ProcessStatus } from '@/types';

/** Enriched read models returned by the service layer. */

export interface ProjectListItem {
  project: Project;
  customer: string;
  npdPic: string;
  salesPic: string;
  drafter: string;
  current?: { id: string; key: string; name: string; shortName: string; status: ProcessStatus; sequence: number; pic: string };
  processCount: number;
  displayStatus: DisplayStatus;
  overdueDays: number;
  daysToTarget: number;
  aging: number;
  progress: { done: number; total: number; pct: number };
  nextActionState: DueState;
  flags: AttentionFlags;
  missingDocs: string[];
  daysSinceUpdate: number;
  pendingApprovals: number;
}

export function toListItem(db: DbState, p: Project, today: ISODate, versions = versionsById(db)): ProjectListItem {
  const procs = projectProcesses(db, p.id);
  const cur = currentProcess(db, p);
  const docs = db.documents.filter((d) => d.projectId === p.id);
  const missing = missingDocumentProcesses(procs, docs, versions);
  return {
    project: p,
    customer: customerName(db, p.customerId),
    npdPic: userName(db, p.npdPicId),
    salesPic: userName(db, p.salesPicId),
    drafter: userName(db, p.drafterId),
    current: cur
      ? { id: cur.id, key: cur.key, name: cur.name, shortName: cur.shortName, status: cur.status, sequence: cur.sequence, pic: userName(db, cur.picId) }
      : undefined,
    processCount: procs.length,
    displayStatus: displayStatus(p, today),
    overdueDays: overdueDays(p, today),
    daysToTarget: daysToTarget(p, today),
    aging: projectAging(p, today),
    progress: processProgress(procs),
    nextActionState: nextActionState(p, today, db.settings.dueSoonDays),
    flags: attentionFlags(p, today, db.settings, missing.length),
    missingDocs: missing.map((m) => m.name),
    daysSinceUpdate: daysSinceUpdate(p, today),
    pendingApprovals: db.approvals.filter((a) => a.projectId === p.id && a.status === 'pending').length,
  };
}
