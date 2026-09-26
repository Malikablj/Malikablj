import { findProcess, findProject, projectProcesses, userName, versionsById } from '@/domain/context';
import { AppError } from '@/domain/errors';
import { docRevisionOptions, type DocRevisionOption, prefillData } from '@/domain/forms';
import { hasRequiredDocument, processDuration } from '@/domain/metrics';
import { canViewProject, visibleProjects } from '@/domain/permissions';
import {
  addComment as addCommentCmd,
  createProject as createProjectCmd,
  type NextActionInput,
  type ProcessPatch,
  type ProjectInput,
  type StatusInput,
  updateNextAction as updateNextActionCmd,
  updateProcess as updateProcessCmd,
  updateProject as updateProjectCmd,
  updateProjectStatus as updateStatusCmd,
  updatePurchasingStatus as updatePurchasingCmd,
} from '@/domain/projectCommands';
import {
  type CompleteProcessInput,
  completeProcess as completeProcessCmd,
  finishProject as finishProjectCmd,
  overrideCurrentProcess,
  setProblem as setProblemCmd,
  unfinishedMandatory,
} from '@/domain/workflow';
import type { Approval, DbState, ISODate, ProcessRecord, Project, ProjectProcess, PublicUser, PurchasingStatus } from '@/types';
import { command, query } from './client';
import { type DocumentRow, toDocumentRows } from './documents';
import { type ProjectListItem, toListItem } from './views';

function assertVisible(user: PublicUser, project: Project) {
  if (!canViewProject(user, project)) throw new AppError('FORBIDDEN', 'Anda tidak memiliki akses ke project ini.');
}

export function listProjects(): Promise<ProjectListItem[]> {
  return query((db, user, today) => {
    const versions = versionsById(db);
    return visibleProjects(user, db.projects)
      .map((p) => toListItem(db, p, today, versions))
      .sort((a, b) => b.project.code.localeCompare(a.project.code));
  });
}

export interface ProcessView extends ProjectProcess {
  picName: string;
  duration: number | null;
  docCount: number;
  hasRequiredDoc: boolean;
  pendingApproval?: Approval;
}

export interface ApprovalView extends Approval {
  projectCode: string;
  projectName: string;
  customer: string;
  processName: string;
  processKey: string;
  requestedBy: string;
  decidedBy?: string;
}

export interface TimelineEntry {
  id: string;
  kind: 'activity' | 'comment';
  type: string;
  at: string;
  message: string;
  detail?: string;
  user: string;
  userId: string;
  processName?: string;
}

export interface RecordView extends ProcessRecord {
  processName: string;
  createdBy: string;
}

export interface ProjectDetail {
  item: ProjectListItem;
  workflowName: string;
  processes: ProcessView[];
  approvals: ApprovalView[];
  documents: DocumentRow[];
  records: RecordView[];
  timeline: TimelineEntry[];
  unfinishedMandatory: string[];
}

export function toApprovalView(db: DbState, a: Approval): ApprovalView {
  const project = db.projects.find((p) => p.id === a.projectId)!;
  const proc = db.processes.find((p) => p.id === a.processId);
  return {
    ...a,
    projectCode: project.code,
    projectName: project.name,
    customer: db.customers.find((c) => c.id === project.customerId)?.name ?? '—',
    processName: proc?.name ?? '—',
    processKey: proc?.key ?? '',
    requestedBy: userName(db, a.requestedById),
    decidedBy: a.decidedById ? userName(db, a.decidedById) : undefined,
  };
}

export function buildProjectDetail(db: DbState, project: Project, today: ISODate): ProjectDetail {
  const versions = versionsById(db);
  const procs = projectProcesses(db, project.id);
  const docs = db.documents.filter((d) => d.projectId === project.id);
  const procName = new Map(procs.map((p) => [p.id, p.name]));
  const approvals = db.approvals.filter((a) => a.projectId === project.id);
  const timeline: TimelineEntry[] = [
    ...db.activities
      .filter((a) => a.projectId === project.id)
      .map((a) => ({
        id: a.id,
        kind: 'activity' as const,
        type: a.type,
        at: a.at,
        message: a.message,
        detail: a.detail,
        user: userName(db, a.userId),
        userId: a.userId,
        processName: a.processId ? procName.get(a.processId) : undefined,
      })),
    ...db.comments
      .filter((c) => c.projectId === project.id)
      .map((c) => ({
        id: c.id,
        kind: 'comment' as const,
        type: 'comment',
        at: c.createdAt,
        message: c.body,
        user: userName(db, c.userId),
        userId: c.userId,
        processName: c.processId ? procName.get(c.processId) : undefined,
      })),
  ].sort((a, b) => b.at.localeCompare(a.at));
  const workflow = db.workflows.find((w) => w.id === project.workflowId);
  return {
    item: toListItem(db, project, today, versions),
    workflowName: `${workflow?.name ?? 'Workflow'} v${project.workflowVersion}`,
    processes: procs.map((p) => ({
      ...p,
      picName: userName(db, p.picId),
      duration: processDuration(p, today),
      docCount: docs.filter((d) => d.processId === p.id).length,
      hasRequiredDoc: hasRequiredDocument(p, docs, versions),
      pendingApproval: approvals.find((a) => a.processId === p.id && a.status === 'pending'),
    })),
    approvals: approvals.map((a) => toApprovalView(db, a)).sort((a, b) => b.createdAt.localeCompare(a.createdAt)),
    documents: toDocumentRows(db, docs),
    records: db.records
      .filter((r) => r.projectId === project.id)
      .map((r) => ({ ...r, processName: procName.get(r.processId) ?? '—', createdBy: userName(db, r.createdById) }))
      .sort((a, b) => b.createdAt.localeCompare(a.createdAt)),
    timeline,
    unfinishedMandatory: unfinishedMandatory(procs).map((p) => p.name),
  };
}

export function getProjectDetail(code: string): Promise<ProjectDetail> {
  return query((db, user, today) => {
    const project = db.projects.find((p) => p.code === code);
    if (!project) throw new AppError('NOT_FOUND', `Project ${code} tidak ditemukan.`);
    assertVisible(user, project);
    return buildProjectDetail(db, project, today);
  });
}

export interface ProcessForm {
  data: Record<string, unknown>;
  docRevisionOptions: Record<string, DocRevisionOption[]>;
}

export function getProcessForm(processId: string): Promise<ProcessForm> {
  return query((db, user, today) => {
    const proc = findProcess(db, processId);
    const project = findProject(db, proc.projectId);
    assertVisible(user, project);
    const options: Record<string, DocRevisionOption[]> = {};
    for (const f of proc.fields) if (f.type === 'docRevision') options[f.key] = docRevisionOptions(db, project.id, f);
    return { data: prefillData(db, project, proc, today), docRevisionOptions: options };
  });
}

export const createProject = (input: ProjectInput) => command((db, ctx) => createProjectCmd(db, ctx, input));
export const updateProject = (id: string, patch: Partial<ProjectInput>) => command((db, ctx) => updateProjectCmd(db, ctx, id, patch));
export const updateStatus = (id: string, input: StatusInput) => command((db, ctx) => updateStatusCmd(db, ctx, id, input));
export const updateNextAction = (id: string, input: NextActionInput) => command((db, ctx) => updateNextActionCmd(db, ctx, id, input));
export const finishProject = (id: string, closingNote?: string) => command((db, ctx) => finishProjectCmd(db, ctx, id, { closingNote }));
export const moveCurrentProcess = (projectId: string, processId: string, reason: string) =>
  command((db, ctx) => overrideCurrentProcess(db, ctx, projectId, processId, reason));
export const addComment = (projectId: string, body: string, processId?: string) =>
  command((db, ctx) => addCommentCmd(db, ctx, { projectId, body, processId }));
export const updateProcess = (processId: string, patch: ProcessPatch) => command((db, ctx) => updateProcessCmd(db, ctx, processId, patch));
export const completeProcess = (input: CompleteProcessInput) => command((db, ctx) => completeProcessCmd(db, ctx, input));
export const setProblem = (processId: string, flag: boolean, note: string) => command((db, ctx) => setProblemCmd(db, ctx, processId, flag, note));
export const updatePurchasingStatus = (recordId: string, status: PurchasingStatus) =>
  command((db, ctx) => updatePurchasingCmd(db, ctx, recordId, status));

export type { ProjectInput, StatusInput, NextActionInput, ProcessPatch, CompleteProcessInput };
