import { APPROVAL_STATUS_LABEL, APPROVAL_TYPE_LABEL } from '@/config/labels';
import { uid } from '@/lib/utils';
import type { Approval, ApprovalStatus, ApprovalType, ApproverType, DbState } from '@/types';
import { type CommandContext, customerName, findProcess, findProject, logActivity, notify, projectPics } from './context';
import { AppError } from './errors';
import { isRunning } from './metrics';
import { canDecideApproval, canRequestApproval, canViewProject } from './permissions';
import { completeProcess, describeVersion } from './workflow';

export interface ApprovalRequestInput {
  projectId: string;
  processId: string;
  type: ApprovalType;
  documentVersionId?: string;
  revision?: string;
  approverType: ApproverType;
  approverName?: string;
  comment?: string;
}

/** Manual approval request (e.g. 2D Approval) that is not driven by the workflow. */
export function requestApproval(db: DbState, ctx: CommandContext, input: ApprovalRequestInput): Approval {
  const project = findProject(db, input.projectId);
  const proc = findProcess(db, input.processId);
  if (!canRequestApproval(ctx.actor) || !canViewProject(ctx.actor, project))
    throw new AppError('FORBIDDEN', 'Role Anda tidak dapat mengajukan approval.');
  if (project.status === 'completed' || project.status === 'cancelled')
    throw new AppError('INVALID_STATE', 'Project sudah ditutup.');
  const fe: Record<string, string> = {};
  if (!input.type) fe.type = 'Pilih Approval Type.';
  if (!input.documentVersionId && !input.revision?.trim()) fe.revision = 'Pilih dokumen atau isi revision yang diajukan.';
  if (input.approverType === 'internal' && !input.approverName?.trim()) fe.approverName = 'Isi nama approver internal.';
  if (Object.keys(fe).length) throw new AppError('VALIDATION', 'Lengkapi data approval.', { fieldErrors: fe });
  const dup = db.approvals.find((a) => a.processId === proc.id && a.type === input.type && a.status === 'pending');
  if (dup) throw new AppError('CONFLICT', `${APPROVAL_TYPE_LABEL[input.type]} untuk proses ini masih Pending (${dup.code}).`);

  db.counters.approval += 1;
  const approval: Approval = {
    id: uid('apv'),
    code: `APV-${ctx.today.slice(0, 4)}-${String(db.counters.approval).padStart(4, '0')}`,
    projectId: project.id,
    processId: proc.id,
    type: input.type,
    revision: input.documentVersionId ? describeVersion(db, input.documentVersionId) : input.revision!.trim(),
    documentVersionId: input.documentVersionId,
    requestedDate: ctx.today,
    requestedById: ctx.actor.id,
    approverType: input.approverType,
    approverName: input.approverType === 'customer' ? `Customer — ${customerName(db, project.customerId)}` : input.approverName!.trim(),
    status: 'pending',
    comment: input.comment?.trim() || undefined,
    iteration: proc.iteration,
    linkedToWorkflow: false,
    createdAt: ctx.now,
  };
  db.approvals.push(approval);
  logActivity(db, ctx, project, 'approval_requested', `${APPROVAL_TYPE_LABEL[approval.type]} diajukan (${approval.revision})`, { processId: proc.id });
  notify(db, ctx, projectPics(project), 'approval_requested', `${APPROVAL_TYPE_LABEL[approval.type]} diminta`, `${project.code} · ${project.name} — ${approval.revision}`, {
    projectId: project.id,
  });
  return approval;
}

export interface DecisionInput {
  approvalId: string;
  /** Workflow outcome (for workflow-linked approvals). */
  outcomeKey?: string;
  /** Decision (for manual approvals, or to resolve the outcome). */
  decision?: Exclude<ApprovalStatus, 'pending'>;
  targetKey?: string;
  comment?: string;
  attachmentVersionId?: string;
}

export function decideApproval(db: DbState, ctx: CommandContext, input: DecisionInput): void {
  const approval = db.approvals.find((a) => a.id === input.approvalId);
  if (!approval) throw new AppError('NOT_FOUND', 'Approval tidak ditemukan.');
  const project = findProject(db, approval.projectId);
  const proc = findProcess(db, approval.processId);
  if (!canDecideApproval(ctx.actor, project, approval, proc))
    throw new AppError('FORBIDDEN', 'Anda tidak memiliki akses untuk memutuskan approval ini.');
  if (approval.status !== 'pending') throw new AppError('INVALID_STATE', `Approval ini sudah diputuskan (${APPROVAL_STATUS_LABEL[approval.status]}).`);

  if (approval.linkedToWorkflow) {
    if (!isRunning(proc) || project.currentProcessId !== proc.id)
      throw new AppError('INVALID_STATE', 'Proses approval ini sudah tidak aktif.');
    const outcome =
      proc.outcomes?.find((o) => o.key === input.outcomeKey) ?? proc.outcomes?.find((o) => o.approvalStatus === input.decision);
    if (!outcome) throw new AppError('VALIDATION', 'Pilih keputusan approval.');
    completeProcess(db, ctx, {
      processId: proc.id,
      outcomeKey: outcome.key,
      targetKey: input.targetKey,
      comment: input.comment,
      attachmentVersionId: input.attachmentVersionId,
    });
    return;
  }

  if (!input.decision) throw new AppError('VALIDATION', 'Pilih keputusan approval.');
  if (input.decision !== 'approved' && !input.comment?.trim())
    throw new AppError('VALIDATION', 'Komentar wajib diisi untuk penolakan / revision required.', { fieldErrors: { comment: 'Komentar wajib diisi.' } });
  approval.status = input.decision;
  approval.decisionDate = ctx.today;
  approval.decidedById = ctx.actor.id;
  approval.comment = input.comment?.trim() || undefined;
  approval.attachmentVersionId = input.attachmentVersionId;
  if (approval.documentVersionId) {
    const v = db.documentVersions.find((x) => x.id === approval.documentVersionId);
    if (v) v.status = input.decision === 'approved' ? 'approved' : 'rejected';
  }
  const approved = input.decision === 'approved';
  logActivity(db, ctx, project, 'approval_decided', `${APPROVAL_TYPE_LABEL[approval.type]}: ${APPROVAL_STATUS_LABEL[input.decision]}`, {
    processId: proc.id,
    detail: [approval.revision, approval.comment].filter(Boolean).join(' — '),
  });
  notify(
    db,
    ctx,
    projectPics(project),
    approved ? 'approval_approved' : 'approval_rejected',
    `${APPROVAL_TYPE_LABEL[approval.type]} ${approved ? 'disetujui' : 'ditolak'}`,
    `${project.code} · ${project.name} — ${approval.revision}`,
    { projectId: project.id },
  );
  if (project.status === 'waiting_approval' && !db.approvals.some((a) => a.projectId === project.id && a.status === 'pending')) {
    project.status = 'on_progress';
  }
}
