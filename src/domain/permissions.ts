import type { Approval, Project, ProjectProcess, PublicUser, Role } from '@/types';

/**
 * Role-based access control (PRD §11). The same rules are enforced by the
 * service layer (writes & queries), the UI (what is shown) and the AI
 * Assistant (which data it may read).
 */

const PROJECT_CONTROL: Role[] = ['admin', 'npd_staff'];

/** Roles that only see the projects they are assigned to. */
const ASSIGNED_SCOPE: Partial<Record<Role, (p: Project, userId: string) => boolean>> = {
  admin_sales: (p, uid) => p.salesPicId === uid,
  drafter: (p, uid) => p.drafterId === uid,
};

export function canViewProject(user: PublicUser, project: Project): boolean {
  const scope = ASSIGNED_SCOPE[user.role];
  return scope ? scope(project, user.id) : true;
}

export function visibleProjects<T extends Project>(user: PublicUser, projects: T[]): T[] {
  return projects.filter((p) => canViewProject(user, p));
}

export function projectScopeLabel(user: PublicUser): string {
  if (user.role === 'admin_sales') return 'Project dengan Anda sebagai Sales PIC';
  if (user.role === 'drafter') return 'Project dengan Anda sebagai Drafter';
  return 'Semua project';
}

export const isReadOnly = (user: PublicUser) => user.role === 'management';

export const canCreateProject = (user: PublicUser) => ['admin', 'admin_sales', 'npd_staff'].includes(user.role);

export function canEditProject(user: PublicUser, project: Project): boolean {
  if (PROJECT_CONTROL.includes(user.role)) return true;
  return user.role === 'admin_sales' && project.salesPicId === user.id;
}

/** Status changes (Hold, Waiting External, Cancelled, …) are project control. */
export const canChangeStatus = (user: PublicUser) => PROJECT_CONTROL.includes(user.role);

export function canUpdateNextAction(user: PublicUser, project: Project, current?: ProjectProcess | null): boolean {
  if (canEditProject(user, project)) return true;
  return !!current && current.actorRoles.includes(user.role) && canViewProject(user, project);
}

export function canActOnProcess(user: PublicUser, project: Project, process: ProjectProcess): boolean {
  if (isReadOnly(user) || !canViewProject(user, project)) return false;
  if (user.role === 'admin') return true;
  return process.actorRoles.includes(user.role);
}

/** Planning fields (PIC, planned dates) belong to project control. */
export const canPlanProcess = (user: PublicUser) => PROJECT_CONTROL.includes(user.role);

export function canUploadToProcess(user: PublicUser, project: Project, process: ProjectProcess): boolean {
  if (isReadOnly(user) || !canViewProject(user, project)) return false;
  if (PROJECT_CONTROL.includes(user.role)) return true;
  return process.uploadRoles.includes(user.role) || process.actorRoles.includes(user.role);
}

export function canDecideApproval(user: PublicUser, project: Project, approval: Approval, process?: ProjectProcess): boolean {
  if (isReadOnly(user) || !canViewProject(user, project)) return false;
  if (PROJECT_CONTROL.includes(user.role)) return true;
  if (approval.approverType === 'customer') return user.role === 'admin_sales';
  return !!process && process.actorRoles.includes(user.role);
}

export const canRequestApproval = (user: PublicUser) => ['admin', 'npd_staff', 'admin_sales', 'drafter'].includes(user.role);

export const canFinishProject = (user: PublicUser) => PROJECT_CONTROL.includes(user.role);

/** Moving the current process manually is an Admin correction tool. */
export const canOverrideWorkflow = (user: PublicUser) => user.role === 'admin';

export const canManageSettings = (user: PublicUser) => user.role === 'admin';

export const canManageCalendar = (user: PublicUser) => !isReadOnly(user);

export const canComment = (user: PublicUser) => !isReadOnly(user);

export const canUpdatePurchasing = (user: PublicUser) => ['admin', 'npd_staff', 'purchasing'].includes(user.role);
