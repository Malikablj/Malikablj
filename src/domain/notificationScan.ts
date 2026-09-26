import { describeDue, diffDays, formatDate } from '@/lib/date';
import type { DbState } from '@/types';
import { type CommandContext, currentProcess, notify, projectProcesses, versionsById } from './context';
import { hasRequiredDocument, isActiveProject, isDueSoon, isOverdue, isRunning, overdueDays } from './metrics';

/**
 * Time-based notifications (deadline approaching, overdue, next action
 * due/overdue, missing mandatory document). Runs on login/app load and is
 * idempotent thanks to dedupe keys.
 */
export function scanDeadlines(db: DbState, ctx: CommandContext): number {
  const before = db.notifications.length;
  const today = ctx.today;
  const versions = versionsById(db);
  for (const p of db.projects) {
    if (!isActiveProject(p) || p.status === 'hold') continue;
    const pics = [p.npdPicId, p.salesPicId];
    const opts = (key: string) => ({ projectId: p.id, dedupeKey: key, includeActor: true });
    if (isOverdue(p, today)) {
      notify(db, ctx, pics, 'project_overdue', 'Project overdue', `${p.code} · ${p.name} melewati target ${formatDate(p.targetDate)} (${overdueDays(p, today)} hari).`, opts(`overdue:${p.id}:${p.targetDate}`));
    } else if (isDueSoon(p, today, db.settings.dueSoonDays)) {
      notify(db, ctx, pics, 'deadline_approaching', 'Deadline project mendekat', `${p.code} · ${p.name} — target ${formatDate(p.targetDate)} (${describeDue(p.targetDate, today)}).`, opts(`deadline:${p.id}:${p.targetDate}`));
    }
    const cur = currentProcess(db, p);
    const assignees = [cur?.picId, p.npdPicId];
    const na = diffDays(today, p.nextActionDue);
    if (na < 0) {
      notify(db, ctx, assignees, 'next_action_overdue', 'Next Action terlambat', `${p.code}: "${p.nextAction}" ${describeDue(p.nextActionDue, today)}.`, opts(`na-over:${p.id}:${p.nextActionDue}:${p.nextAction}`));
    } else if (na <= 1) {
      notify(db, ctx, assignees, 'next_action_due', 'Next Action jatuh tempo', `${p.code}: "${p.nextAction}" due ${describeDue(p.nextActionDue, today)}.`, opts(`na-due:${p.id}:${p.nextActionDue}:${p.nextAction}`));
    }
    const docs = db.documents.filter((d) => d.projectId === p.id);
    for (const proc of projectProcesses(db, p.id)) {
      if (!isRunning(proc) || !proc.requiresDocument || !proc.actualStart) continue;
      if (diffDays(proc.actualStart, today) < 1 || hasRequiredDocument(proc, docs, versions)) continue;
      notify(db, ctx, [proc.picId], 'missing_document', 'Dokumen wajib belum diupload', `${p.code} · ${proc.name} belum memiliki dokumen wajib.`, opts(`missing:${proc.id}:${proc.iteration}`));
    }
  }
  return db.notifications.length - before;
}
