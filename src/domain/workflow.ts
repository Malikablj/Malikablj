import { APPROVAL_TYPE_LABEL, DOC_TYPE_LABEL, formatRevision, PROJECT_TYPE_LABEL } from '@/config/labels';
import { addDays } from '@/lib/date';
import { uid } from '@/lib/utils';
import type {
  ApprovalType,
  DbState,
  DocType,
  FormFieldDef,
  Project,
  ProcessOutcome,
  ProcessRecord,
  ProjectProcess,
  WorkflowTemplate,
} from '@/types';
import {
  assertActive,
  type CommandContext,
  customerName,
  defaultPicId,
  findProcess,
  findProject,
  logActivity,
  notify,
  picLabel,
  projectPics,
  projectProcesses,
  userName,
  versionsById,
} from './context';
import { AppError } from './errors';
import { docRevisionOptions, prefillData } from './forms';
import { hasRequiredDocument, isRunning } from './metrics';
import { canActOnProcess, canFinishProject, canOverrideWorkflow } from './permissions';
import { buildPlan } from './planning';

/**
 * Workflow engine. Project processes are instantiated from a workflow
 * template; outcomes move the "current process" forward, into alternative
 * branches, or back into revision loops. Every transition is recorded in
 * Activity History and the project status / Waiting For / Next Action are
 * kept in sync (PRD §3–§7, §16).
 */

const APPROVAL_DOC_TYPES: Record<ApprovalType, DocType[]> = {
  npr: ['npr'],
  artwork: ['artwork'],
  masterbatch: ['material_spec'],
  '3d': ['drawing_3d'],
  '2d': ['drawing_2d'],
  mold_drawing: ['mold_drawing', 'drawing_2d'],
  t0: ['trial_report'],
  trial: ['trial_report'],
  commissioning: ['trial_report'],
  validation: ['validation_report'],
};

const RECORD_PREFIX: Record<string, string> = {
  t0_trial: 'T0',
  commissioning_trial: 'COM',
  trial_evaluation: 'TR',
  bulk_material_request: 'MR',
  material_preparation: 'MP',
  validation_mass_production: 'VAL',
};

export function instantiateProcesses(
  db: DbState,
  project: Project,
  template: WorkflowTemplate,
): ProjectProcess[] {
  const plan = buildPlan(template.processes, project.startDate, project.targetDate);
  return template.processes.map((def, index) => {
    const window = plan.get(def.key)!;
    return {
      ...structuredClone(def),
      id: uid('prc'),
      projectId: project.id,
      sequence: index + 1,
      status: 'not_started',
      picId: defaultPicId(db, project, def.picRole),
      plannedStart: window.start,
      plannedFinish: window.finish,
      iteration: 0,
      loopCount: 0,
      data: {},
    };
  });
}

function nextInSequence(procs: ProjectProcess[], from: ProjectProcess): ProjectProcess | undefined {
  if (from.nextKey) return procs.find((p) => p.key === from.nextKey);
  return procs.find((p) => p.sequence > from.sequence && !p.loopOnly && p.status !== 'skipped');
}

/** Latest non-rejected document version of the given types in a project (for approval revision labels). */
function latestDocVersion(db: DbState, projectId: string, types: DocType[], preferProcessId?: string) {
  const docs = db.documents.filter((d) => d.projectId === projectId && types.includes(d.type));
  const versions = versionsById(db);
  const pick = (candidates: typeof docs) => {
    let best: { docType: DocType; version: (typeof db.documentVersions)[number] } | undefined;
    for (const d of candidates) {
      const v = versions.get(d.latestVersionId);
      if (!v || v.status === 'rejected') continue;
      if (!best || v.uploadedAt > best.version.uploadedAt) best = { docType: d.type, version: v };
    }
    return best;
  };
  return (preferProcessId ? pick(docs.filter((d) => d.processId === preferProcessId)) : undefined) ?? pick(docs);
}

export function describeVersion(db: DbState, versionId: string): string {
  const v = db.documentVersions.find((x) => x.id === versionId);
  if (!v) return 'Dokumen';
  const doc = db.documents.find((d) => d.id === v.documentId);
  // Use the type label ("Artwork Rev 02") unless the project has several documents of that type.
  const ambiguous = !!doc && db.documents.filter((d) => d.projectId === doc.projectId && d.type === doc.type).length > 1;
  const label = doc ? (ambiguous ? doc.name : DOC_TYPE_LABEL[doc.type]) : 'Dokumen';
  return `${label} ${formatRevision(v.revision)}${v.version > 1 ? ` · v${v.version}` : ''}`;
}

/** Recomputes project status / waiting for from the current process (used after Hold). */
export function applyAutoStatus(db: DbState, project: Project, proc: ProjectProcess): void {
  if (proc.kind === 'decision' && proc.approverType === 'customer') {
    project.status = 'waiting_approval';
    project.waitingFor = `Customer — ${customerName(db, project.customerId)}`;
  } else if (proc.waitingType === 'external') {
    project.status = 'waiting_external';
    project.waitingFor = project.supplier ? `${proc.waitingLabel ?? 'External'} — ${project.supplier}` : (proc.waitingLabel ?? 'External');
  } else {
    project.status = 'on_progress';
    project.waitingFor = picLabel(db, proc);
  }
  proc.waitingFor = project.waitingFor;
}

interface EnterOptions {
  mode?: 'normal' | 'revision' | 'problem';
  submittedVersionId?: string;
  reason?: string;
}

/** Makes `proc` the current process and syncs project fields. */
export function enterProcess(db: DbState, ctx: CommandContext, project: Project, proc: ProjectProcess, opts: EnterOptions = {}): void {
  const mode = opts.mode ?? 'normal';
  proc.status = mode === 'revision' ? 'revision' : mode === 'problem' ? 'problem' : 'current';
  proc.iteration += 1;
  proc.enteredAt = ctx.now;
  proc.actualStart ??= ctx.today;
  proc.actualFinish = undefined;
  proc.picId ??= defaultPicId(db, project, proc.picRole);
  project.currentProcessId = proc.id;
  applyAutoStatus(db, project, proc);

  const nextAction =
    mode === 'revision'
      ? `Revisi ${proc.shortName} sesuai feedback`
      : mode === 'problem'
        ? `Tindak lanjuti hasil ${proc.shortName} & ulangi`
        : proc.defaultNextAction;
  const fallbackDue = addDays(ctx.today, Math.max(1, Math.min(proc.durationDays, 3)));
  const due = proc.plannedFinish >= ctx.today && mode === 'normal' ? proc.plannedFinish : fallbackDue;
  project.nextAction = nextAction;
  project.nextActionDue = due;
  proc.nextAction = nextAction;
  proc.nextActionDue = due;

  const verb = mode === 'revision' ? `masuk revisi (loop ke-${proc.loopCount})` : mode === 'problem' ? 'diulang' : 'dimulai';
  logActivity(db, ctx, project, mode === 'normal' ? 'process_started' : 'process_loop', `${proc.name} ${verb}`, {
    processId: proc.id,
    detail: opts.reason,
  });

  if (mode === 'revision' && proc.key === 'artwork') {
    notify(db, ctx, [proc.picId, project.npdPicId], 'artwork_revision', 'Revisi artwork diperlukan', `${project.code} · ${project.name}: ${opts.reason ?? 'Customer meminta revisi artwork.'}`, {
      projectId: project.id,
    });
  }

  if (proc.kind === 'decision' && proc.requiresApproval && proc.approvalType) {
    createWorkflowApproval(db, ctx, project, proc, opts.submittedVersionId);
  }
}

function createWorkflowApproval(db: DbState, ctx: CommandContext, project: Project, proc: ProjectProcess, submittedVersionId?: string): void {
  const type = proc.approvalType!;
  let documentVersionId = submittedVersionId;
  let revision: string;
  if (documentVersionId) {
    revision = describeVersion(db, documentVersionId);
  } else {
    const latest = latestDocVersion(db, project.id, APPROVAL_DOC_TYPES[type], proc.id);
    documentVersionId = latest?.version.id;
    revision = latest ? describeVersion(db, latest.version.id) : `Iterasi ${proc.iteration}`;
  }
  db.counters.approval += 1;
  const isCustomer = proc.approverType === 'customer';
  db.approvals.push({
    id: uid('apv'),
    code: `APV-${ctx.today.slice(0, 4)}-${String(db.counters.approval).padStart(4, '0')}`,
    projectId: project.id,
    processId: proc.id,
    type,
    revision,
    documentVersionId,
    requestedDate: ctx.today,
    requestedById: ctx.actor.id,
    approverType: proc.approverType ?? 'internal',
    approverName: isCustomer ? `Customer — ${customerName(db, project.customerId)}` : picLabel(db, proc),
    status: 'pending',
    iteration: proc.iteration,
    linkedToWorkflow: true,
    createdAt: ctx.now,
  });
  logActivity(db, ctx, project, 'approval_requested', `${APPROVAL_TYPE_LABEL[type]} diajukan (${revision})`, { processId: proc.id });
  notify(
    db,
    ctx,
    isCustomer ? [project.salesPicId, project.npdPicId] : [proc.picId, project.npdPicId],
    'approval_requested',
    `${APPROVAL_TYPE_LABEL[type]} diminta`,
    `${project.code} · ${project.name} — ${revision}${isCustomer ? ', menunggu keputusan Customer' : ''}.`,
    { projectId: project.id },
  );
}

export function missingRequiredFields(fields: FormFieldDef[], data: Record<string, unknown>): Record<string, string> {
  const errors: Record<string, string> = {};
  for (const f of fields) {
    if (!f.required) continue;
    const v = data[f.key];
    const empty = v === undefined || v === null || (typeof v === 'string' && v.trim() === '') || (Array.isArray(v) && v.length === 0);
    if (empty) errors[f.key] = `${f.label} wajib diisi.`;
  }
  return errors;
}

export interface CompleteProcessInput {
  processId: string;
  outcomeKey?: string;
  targetKey?: string;
  data?: Record<string, unknown>;
  comment?: string;
  attachmentVersionId?: string;
}

export function completeProcess(db: DbState, ctx: CommandContext, input: CompleteProcessInput): void {
  const proc = findProcess(db, input.processId);
  const project = findProject(db, proc.projectId);
  if (!canActOnProcess(ctx.actor, project, proc))
    throw new AppError('FORBIDDEN', `Role Anda tidak memiliki akses untuk menyelesaikan proses ${proc.name}.`);
  assertActive(project);
  if (!isRunning(proc) || project.currentProcessId !== proc.id)
    throw new AppError('INVALID_STATE', `${proc.name} bukan proses aktif project ini.`);
  if (proc.kind === 'finish') {
    finishProject(db, ctx, project.id, { closingNote: input.comment ?? (input.data?.closingNote as string | undefined) });
    return;
  }

  const data = { ...prefillData(db, project, proc, ctx.today), ...(input.data ?? {}) };
  const fieldErrors = missingRequiredFields(proc.fields, data);
  for (const f of proc.fields) {
    if (f.type !== 'docRevision' || !data[f.key]) continue;
    if (!docRevisionOptions(db, project.id, f).some((o) => o.versionId === data[f.key]))
      fieldErrors[f.key] = `${f.label} yang dipilih sudah tidak berlaku (Rejected/Superseded).`;
  }
  if (Object.keys(fieldErrors).length)
    throw new AppError('VALIDATION', 'Lengkapi data wajib sebelum menyelesaikan proses.', { fieldErrors });

  if (!hasRequiredDocument(proc, db.documents, versionsById(db))) {
    const types = proc.requiredDocTypes.map((t) => DOC_TYPE_LABEL[t]).join(' / ') || 'dokumen';
    throw new AppError('VALIDATION', `Upload dokumen wajib (${types}) pada proses ${proc.name} terlebih dahulu.`);
  }

  let outcome: ProcessOutcome | undefined;
  if (proc.kind === 'decision') {
    outcome = proc.outcomes?.find((o) => o.key === input.outcomeKey);
    if (!outcome) throw new AppError('VALIDATION', 'Pilih hasil keputusan terlebih dahulu.');
    if (outcome.requiresComment && !input.comment?.trim())
      throw new AppError('VALIDATION', `Komentar wajib diisi untuk keputusan "${outcome.label}".`, { fieldErrors: { comment: 'Komentar wajib diisi.' } });
  }

  const procs = projectProcesses(db, project.id);
  proc.data = data;
  proc.lastOutcome = outcome?.key;

  // Record snapshot (trial / material / validation records keep every iteration).
  const record = createRecord(db, ctx, project, proc, data, outcome, input.comment);

  // Linked approval decision.
  if (outcome?.approvalStatus) {
    const approval = db.approvals.find((a) => a.processId === proc.id && a.status === 'pending' && a.linkedToWorkflow);
    if (approval) {
      if (!approval.documentVersionId) {
        // Link the evidence uploaded while the approval was pending (e.g. T0 / validation report).
        const latest = latestDocVersion(db, project.id, APPROVAL_DOC_TYPES[approval.type], proc.id);
        if (latest) {
          approval.documentVersionId = latest.version.id;
          approval.revision = describeVersion(db, latest.version.id);
        }
      }
      approval.status = outcome.approvalStatus;
      approval.decisionDate = ctx.today;
      approval.decidedById = ctx.actor.id;
      approval.comment = input.comment?.trim() || undefined;
      approval.attachmentVersionId = input.attachmentVersionId;
      if (approval.documentVersionId) {
        const v = db.documentVersions.find((x) => x.id === approval.documentVersionId);
        if (v) v.status = outcome.approvalStatus === 'approved' ? 'approved' : 'rejected';
      }
      const approved = outcome.approvalStatus === 'approved';
      logActivity(db, ctx, project, 'approval_decided', `${APPROVAL_TYPE_LABEL[approval.type]}: ${outcome.label}`, {
        processId: proc.id,
        detail: [approval.revision, input.comment?.trim()].filter(Boolean).join(' — '),
      });
      notify(
        db,
        ctx,
        projectPics(project),
        approved ? 'approval_approved' : 'approval_rejected',
        `${APPROVAL_TYPE_LABEL[approval.type]} ${approved ? 'disetujui' : 'ditolak'}`,
        `${project.code} · ${project.name} — ${approval.revision}${input.comment ? `: ${input.comment}` : ''}`,
        { projectId: project.id },
      );
    }
  }

  if (outcome?.setsNewMasterbatch !== undefined) project.newMasterbatch = outcome.setsNewMasterbatch;

  const target = outcome?.target ?? { kind: 'next' as const };
  const outcomeText = outcome ? ` — ${outcome.label}` : '';

  if (target.kind === 'cancel') {
    proc.status = 'completed';
    proc.actualFinish = ctx.today;
    project.status = 'cancelled';
    project.statusReason = input.comment?.trim();
    project.waitingFor = undefined;
    logActivity(db, ctx, project, 'process_completed', `${proc.name} selesai${outcomeText}`, { processId: proc.id, detail: input.comment });
    logActivity(db, ctx, project, 'status_changed', 'Status project → Cancelled', { detail: input.comment });
    return;
  }

  if (target.kind === 'repeat') {
    proc.loopCount += 1;
    logActivity(db, ctx, project, 'process_problem', `${proc.name}${outcomeText}`, { processId: proc.id, detail: input.comment });
    enterProcess(db, ctx, project, proc, { mode: 'problem', reason: input.comment });
    return;
  }

  let next: ProjectProcess | undefined;
  if (target.kind === 'next') {
    next = nextInSequence(procs, proc);
  } else {
    const allowed = [target.key, ...(target.alternatives ?? [])];
    const key = input.targetKey && allowed.includes(input.targetKey) ? input.targetKey : target.key;
    next = procs.find((p) => p.key === key);
  }
  if (!next) throw new AppError('INVALID_STATE', 'Proses berikutnya tidak ditemukan pada workflow ini.');

  const submittedVersionId = proc.fields
    .filter((f) => f.type === 'docRevision')
    .map((f) => data[f.key])
    .find((v): v is string => typeof v === 'string' && v.length > 0);

  if (next.sequence > proc.sequence) {
    // Forward: complete the step and mark bypassed branches as not executed.
    proc.status = 'completed';
    proc.actualFinish = ctx.today;
    logActivity(db, ctx, project, 'process_completed', `${proc.name} selesai${outcomeText}`, {
      processId: proc.id,
      detail: [record?.number, input.comment?.trim()].filter(Boolean).join(' — ') || undefined,
    });
    const bypassed = procs.filter((p) => p.sequence > proc.sequence && p.sequence < next!.sequence && p.status !== 'completed');
    for (const p of bypassed) {
      if (p.status === 'skipped') continue;
      p.status = 'skipped';
    }
    const bypassedNames = bypassed.filter((p) => !p.loopOnly).map((p) => p.name);
    if (bypassedNames.length)
      logActivity(db, ctx, project, 'process_skipped', `Tidak dijalankan: ${bypassedNames.join(', ')}`, { detail: outcome?.label });
    if (next.branchGroup) {
      // Choosing a branch means the alternative branch will not be executed.
      for (const p of procs) {
        if (p.branchGroup === next.branchGroup && p.branch !== next.branch && p.status === 'not_started') p.status = 'skipped';
      }
    }
    const isLoopEntry = next.loopOnly === true;
    if (isLoopEntry) next.loopCount += 1;
    enterProcess(db, ctx, project, next, { mode: isLoopEntry ? 'revision' : 'normal', submittedVersionId, reason: input.comment });
    return;
  }

  // Backward: revision loop. Steps between target and here must be redone.
  const isDecisionReject = proc.kind === 'decision';
  if (isDecisionReject) {
    proc.status = 'not_started';
    proc.actualFinish = undefined;
    logActivity(db, ctx, project, 'process_completed', `${proc.name}${outcomeText}`, { processId: proc.id, detail: input.comment });
  } else {
    proc.status = 'completed';
    proc.actualFinish = ctx.today;
    logActivity(db, ctx, project, 'process_completed', `${proc.name} selesai${outcomeText}`, { processId: proc.id, detail: input.comment });
  }
  for (const p of procs) {
    if (p.sequence > next.sequence && p.sequence < proc.sequence && p.status === 'completed' && !p.loopOnly) {
      p.status = 'not_started';
      p.actualFinish = undefined;
    }
  }
  next.loopCount += 1;
  enterProcess(db, ctx, project, next, { mode: 'revision', reason: input.comment });
}

function createRecord(
  db: DbState,
  ctx: CommandContext,
  project: Project,
  proc: ProjectProcess,
  data: Record<string, unknown>,
  outcome: ProcessOutcome | undefined,
  comment?: string,
): ProcessRecord | undefined {
  if (proc.fields.length === 0 && !outcome) return undefined;
  const prefix = RECORD_PREFIX[proc.key] ?? 'REC';
  const count = db.records.filter((r) => r.projectId === project.id && r.processId === proc.id).length + 1;
  const auto = `${prefix}-${String(count).padStart(2, '0')}`;
  const provided = typeof data.trialNumber === 'string' && data.trialNumber.trim() ? data.trialNumber.trim() : undefined;
  if (proc.recordType === 'trial' && !provided) data.trialNumber = auto;
  const number = provided ?? (typeof data.mrNumber === 'string' && data.mrNumber.trim() ? data.mrNumber.trim() : auto);
  const record: ProcessRecord = {
    id: uid('rec'),
    number,
    projectId: project.id,
    processId: proc.id,
    recordType: proc.recordType,
    iteration: proc.iteration,
    data: structuredClone(data),
    outcome: outcome?.key,
    outcomeLabel: outcome?.label,
    comment: comment?.trim() || undefined,
    purchasingStatus: proc.recordType === 'material_request' ? 'requested' : undefined,
    createdById: ctx.actor.id,
    createdAt: ctx.now,
  };
  db.records.push(record);
  // Material preparation marks the related material request as received.
  if (proc.recordType === 'material_preparation') {
    for (const r of db.records) {
      if (r.projectId === project.id && r.recordType === 'material_request' && r.purchasingStatus !== 'cancelled') r.purchasingStatus = 'received';
    }
  }
  return record;
}

/** Mandatory processes that block Finish (PRD §4.2-13, §16). */
export function unfinishedMandatory(procs: ProjectProcess[]): ProjectProcess[] {
  return procs.filter(
    (p) => p.isMandatory && !p.loopOnly && p.kind !== 'finish' && p.status !== 'completed' && p.status !== 'skipped',
  );
}

export function finishProject(db: DbState, ctx: CommandContext, projectId: string, opts: { closingNote?: string } = {}): void {
  const project = findProject(db, projectId);
  if (!canFinishProject(ctx.actor)) throw new AppError('FORBIDDEN', 'Hanya Admin atau NPD Staff yang dapat menyelesaikan project.');
  assertActive(project, 'menyelesaikan project');
  const procs = projectProcesses(db, project.id);
  const missing = unfinishedMandatory(procs);
  if (missing.length) {
    throw new AppError('INVALID_STATE', 'Project belum dapat diselesaikan. Proses mandatory berikut belum selesai:', {
      details: missing.map((p) => `${String(p.sequence).padStart(2, '0')} · ${p.name}`),
    });
  }
  const finishStep = procs.find((p) => p.kind === 'finish');
  if (finishStep) {
    finishStep.status = 'completed';
    finishStep.actualStart ??= ctx.today;
    finishStep.actualFinish = ctx.today;
    if (opts.closingNote) finishStep.data = { ...finishStep.data, closingNote: opts.closingNote };
    project.currentProcessId = finishStep.id;
  }
  project.status = 'completed';
  project.actualFinish = ctx.today;
  project.waitingFor = undefined;
  project.nextAction = 'Project selesai';
  project.nextActionDue = ctx.today;
  logActivity(db, ctx, project, 'project_finished', `Project ${PROJECT_TYPE_LABEL[project.type]} selesai (Completed)`, {
    processId: finishStep?.id,
    detail: opts.closingNote,
  });
}

export function setProblem(db: DbState, ctx: CommandContext, processId: string, flag: boolean, note: string): void {
  const proc = findProcess(db, processId);
  const project = findProject(db, proc.projectId);
  if (!canActOnProcess(ctx.actor, project, proc)) throw new AppError('FORBIDDEN', 'Anda tidak memiliki akses pada proses ini.');
  assertActive(project, 'mengubah proses');
  if (!isRunning(proc)) throw new AppError('INVALID_STATE', 'Hanya proses aktif yang dapat ditandai.');
  if (!note.trim()) throw new AppError('VALIDATION', 'Catatan wajib diisi.', { fieldErrors: { note: 'Catatan wajib diisi.' } });
  if (flag) {
    proc.status = 'problem';
    proc.problemNote = note.trim();
    logActivity(db, ctx, project, 'process_problem', `${proc.name} ditandai Problem`, { processId: proc.id, detail: note.trim() });
  } else {
    proc.status = proc.loopCount > 0 ? 'revision' : 'current';
    proc.problemNote = undefined;
    logActivity(db, ctx, project, 'process_updated', `Problem pada ${proc.name} diselesaikan`, { processId: proc.id, detail: note.trim() });
  }
}

/** Admin correction: move the current process. Mandatory steps are never auto-completed. */
export function overrideCurrentProcess(db: DbState, ctx: CommandContext, projectId: string, processId: string, reason: string): void {
  if (!canOverrideWorkflow(ctx.actor)) throw new AppError('FORBIDDEN', 'Hanya Admin yang dapat memindahkan current process.');
  const project = findProject(db, projectId);
  assertActive(project, 'memindahkan proses');
  if (!reason.trim()) throw new AppError('VALIDATION', 'Alasan wajib diisi.', { fieldErrors: { reason: 'Alasan wajib diisi.' } });
  const procs = projectProcesses(db, project.id);
  const target = procs.find((p) => p.id === processId);
  if (!target) throw new AppError('NOT_FOUND', 'Proses tidak ditemukan.');
  const current = procs.find((p) => p.id === project.currentProcessId);
  if (current?.id === target.id) throw new AppError('INVALID_STATE', 'Proses tersebut sudah menjadi current process.');

  const pending = db.approvals.filter((a) => a.projectId === project.id && a.status === 'pending' && a.linkedToWorkflow);
  for (const a of pending) {
    a.status = 'revision_required';
    a.decisionDate = ctx.today;
    a.decidedById = ctx.actor.id;
    a.comment = `Dibatalkan otomatis: current process dipindahkan oleh Admin (${reason.trim()}).`;
  }
  if (current) {
    current.status = 'not_started';
    current.actualFinish = undefined;
  }
  if (current && target.sequence < current.sequence) {
    for (const p of procs) {
      if (p.sequence > target.sequence && p.sequence < current.sequence && p.status === 'completed') {
        p.status = 'not_started';
        p.actualFinish = undefined;
      }
    }
  } else if (current) {
    for (const p of procs) {
      if (p.sequence > current.sequence && p.sequence < target.sequence && !p.isMandatory && p.status === 'not_started') p.status = 'skipped';
    }
  }
  const wasDone = target.status === 'completed';
  if (wasDone) target.loopCount += 1;
  logActivity(db, ctx, project, 'process_updated', `Admin memindahkan current process ke ${target.name}`, { processId: target.id, detail: reason.trim() });
  enterProcess(db, ctx, project, target, { mode: wasDone ? 'revision' : 'normal', reason: reason.trim() });
}

export function describeProcessOwner(db: DbState, proc: ProjectProcess): string {
  return userName(db, proc.picId);
}
