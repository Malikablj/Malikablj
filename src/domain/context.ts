import { ROLE_LABEL } from '@/config/labels';
import { uid } from '@/lib/utils';
import type {
  Activity,
  ActivityType,
  DbState,
  DocumentVersion,
  ISODate,
  ISODateTime,
  NotificationType,
  Project,
  ProjectProcess,
  PublicUser,
  Role,
  User,
} from '@/types';
import { AppError } from './errors';

/** Who performs a command and when (seed data runs commands in the past). */
export interface CommandContext {
  actor: PublicUser;
  now: ISODateTime;
  today: ISODate;
}

export function findProject(db: DbState, projectId: string): Project {
  const p = db.projects.find((x) => x.id === projectId);
  if (!p) throw new AppError('NOT_FOUND', 'Project tidak ditemukan.');
  return p;
}

export function findProcess(db: DbState, processId: string): ProjectProcess {
  const p = db.processes.find((x) => x.id === processId);
  if (!p) throw new AppError('NOT_FOUND', 'Proses tidak ditemukan.');
  return p;
}

export function projectProcesses(db: DbState, projectId: string): ProjectProcess[] {
  return db.processes.filter((p) => p.projectId === projectId).sort((a, b) => a.sequence - b.sequence);
}

export function currentProcess(db: DbState, project: Project): ProjectProcess | null {
  return project.currentProcessId ? (db.processes.find((p) => p.id === project.currentProcessId) ?? null) : null;
}

export function userName(db: DbState, userId?: string): string {
  if (!userId) return '—';
  return db.users.find((u) => u.id === userId)?.name ?? 'User tidak dikenal';
}

export function customerName(db: DbState, customerId: string): string {
  return db.customers.find((c) => c.id === customerId)?.name ?? 'Customer tidak dikenal';
}

export function firstUserWithRole(db: DbState, role: Role): User | undefined {
  return db.users.find((u) => u.role === role && u.active);
}

/** Default PIC of a process, derived from the project PIC fields. */
export function defaultPicId(db: DbState, project: Project, role: Role): string | undefined {
  switch (role) {
    case 'admin_sales':
      return project.salesPicId;
    case 'npd_staff':
      return project.npdPicId;
    case 'drafter':
      return project.drafterId;
    default:
      return firstUserWithRole(db, role)?.id;
  }
}

export function picLabel(db: DbState, proc: ProjectProcess): string {
  return `${ROLE_LABEL[proc.picRole]} — ${userName(db, proc.picId)}`;
}

export function versionsById(db: DbState): Map<string, DocumentVersion> {
  return new Map(db.documentVersions.map((v) => [v.id, v]));
}

export function logActivity(
  db: DbState,
  ctx: CommandContext,
  project: Project,
  type: ActivityType,
  message: string,
  opts: { processId?: string; detail?: string } = {},
): Activity {
  const activity: Activity = {
    id: uid('act'),
    projectId: project.id,
    processId: opts.processId,
    type,
    message,
    detail: opts.detail,
    userId: ctx.actor.id,
    at: ctx.now,
  };
  db.activities.push(activity);
  project.updatedAt = ctx.now;
  return activity;
}

export function notify(
  db: DbState,
  ctx: CommandContext,
  userIds: Array<string | undefined>,
  type: NotificationType,
  title: string,
  body: string,
  opts: { projectId?: string; dedupeKey?: string; includeActor?: boolean } = {},
): void {
  const recipients = new Set(userIds.filter((u): u is string => !!u));
  if (!opts.includeActor) recipients.delete(ctx.actor.id);
  for (const userId of recipients) {
    if (!db.users.some((u) => u.id === userId && u.active)) continue;
    if (opts.dedupeKey && db.notifications.some((n) => n.userId === userId && n.dedupeKey === opts.dedupeKey)) continue;
    db.notifications.push({
      id: uid('ntf'),
      userId,
      type,
      title,
      body,
      projectId: opts.projectId,
      createdAt: ctx.now,
      read: false,
      dedupeKey: opts.dedupeKey,
    });
  }
}

export const projectPics = (p: Project) => [p.npdPicId, p.salesPicId, p.drafterId];

export function assertActive(project: Project, action = 'melanjutkan proses'): void {
  if (project.status === 'completed') throw new AppError('INVALID_STATE', `Project sudah Completed — tidak dapat ${action}.`);
  if (project.status === 'cancelled') throw new AppError('INVALID_STATE', `Project sudah Cancelled — tidak dapat ${action}.`);
  if (project.status === 'hold')
    throw new AppError('INVALID_STATE', `Project sedang Hold. Ubah status project terlebih dahulu untuk ${action}.`);
}
