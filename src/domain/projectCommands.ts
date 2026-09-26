import { PRIORITY_LABEL, PROJECT_TYPE_LABEL, STATUS_LABEL } from '@/config/labels';
import { formatDate, isValidISODate } from '@/lib/date';
import { normalize, uid } from '@/lib/utils';
import type { DbState, ISODate, Priority, Project, ProjectStatus, ProjectType, Role } from '@/types';
import {
  assertActive,
  type CommandContext,
  currentProcess,
  customerName,
  findProcess,
  findProject,
  logActivity,
  notify,
  projectProcesses,
  userName,
} from './context';
import { AppError } from './errors';
import { isRunning } from './metrics';
import {
  canActOnProcess,
  canChangeStatus,
  canComment,
  canCreateProject,
  canEditProject,
  canPlanProcess,
  canUpdateNextAction,
  canUpdatePurchasing,
} from './permissions';
import { applyAutoStatus, enterProcess, instantiateProcesses } from './workflow';

export interface ProjectInput {
  type: ProjectType;
  customerId: string;
  name: string;
  productName: string;
  productDescription?: string;
  customerRequest: string;
  npdPicId: string;
  salesPicId: string;
  drafterId: string;
  supplier?: string;
  priority: Priority;
  startDate: ISODate;
  targetDate: ISODate;
  nextAction?: string;
  nextActionDue?: ISODate;
  remarks?: string;
}

const PIC_ROLES: Record<'npdPicId' | 'salesPicId' | 'drafterId', Role[]> = {
  npdPicId: ['npd_staff', 'admin'],
  salesPicId: ['admin_sales'],
  drafterId: ['drafter'],
};

export function validateProjectInput(db: DbState, input: Partial<ProjectInput>, opts: { requireNextAction?: boolean } = {}): Record<string, string> {
  const e: Record<string, string> = {};
  if (input.type !== 'new_mold' && input.type !== 'subcont') e.type = 'Pilih Project Type.';
  const customer = db.customers.find((c) => c.id === input.customerId);
  if (!customer) e.customerId = 'Pilih customer.';
  else if (!customer.active) e.customerId = 'Customer tidak aktif.';
  if (!input.name?.trim()) e.name = 'Project Name wajib diisi.';
  else if (input.name.trim().length > 120) e.name = 'Maksimal 120 karakter.';
  if (!input.productName?.trim()) e.productName = 'Product Name wajib diisi.';
  if (!input.customerRequest?.trim()) e.customerRequest = 'Customer Request wajib diisi.';
  for (const key of ['npdPicId', 'salesPicId', 'drafterId'] as const) {
    const user = db.users.find((u) => u.id === input[key]);
    if (!user) e[key] = 'Pilih PIC.';
    else if (!user.active) e[key] = 'User tidak aktif.';
    else if (!PIC_ROLES[key].includes(user.role)) e[key] = 'Role user tidak sesuai.';
  }
  if (!input.priority || !(input.priority in PRIORITY_LABEL)) e.priority = 'Pilih priority.';
  if (!isValidISODate(input.startDate)) e.startDate = 'Start Date wajib diisi.';
  if (!isValidISODate(input.targetDate)) e.targetDate = 'Target Finish wajib diisi.';
  else if (isValidISODate(input.startDate) && input.targetDate <= input.startDate) e.targetDate = 'Target Finish harus setelah Start Date.';
  if (opts.requireNextAction) {
    if (!input.nextAction?.trim()) e.nextAction = 'Next Action wajib diisi.';
    if (!isValidISODate(input.nextActionDue)) e.nextActionDue = 'Next Action Due wajib diisi.';
  }
  return e;
}

function findDuplicate(db: DbState, customerId: string, name: string, excludeId?: string): Project | undefined {
  const n = normalize(name);
  return db.projects.find((p) => p.id !== excludeId && p.customerId === customerId && p.status !== 'cancelled' && normalize(p.name) === n);
}

export function nextProjectCode(db: DbState, year: string): string {
  let n = db.counters.project[year] ?? 0;
  let code: string;
  do {
    n += 1;
    code = `NPD-${year}-${String(n).padStart(3, '0')}`;
  } while (db.projects.some((p) => p.code === code));
  db.counters.project[year] = n;
  return code;
}

export function createProject(db: DbState, ctx: CommandContext, input: ProjectInput): Project {
  if (!canCreateProject(ctx.actor)) throw new AppError('FORBIDDEN', 'Role Anda tidak dapat membuat project.');
  const fieldErrors = validateProjectInput(db, input);
  if (Object.keys(fieldErrors).length) throw new AppError('VALIDATION', 'Periksa kembali data project.', { fieldErrors });
  const dup = findDuplicate(db, input.customerId, input.name);
  if (dup)
    throw new AppError('CONFLICT', `Project "${dup.name}" untuk customer ini sudah ada (${dup.code}).`, {
      fieldErrors: { name: `Sudah digunakan oleh ${dup.code}.` },
    });
  const template = db.workflows.find((w) => w.projectType === input.type);
  if (!template) throw new AppError('INVALID_STATE', 'Workflow template untuk project type ini belum tersedia.');

  const project: Project = {
    id: uid('prj'),
    code: nextProjectCode(db, ctx.today.slice(0, 4)),
    type: input.type,
    customerId: input.customerId,
    name: input.name.trim(),
    productName: input.productName.trim(),
    productDescription: input.productDescription?.trim() || undefined,
    customerRequest: input.customerRequest.trim(),
    npdPicId: input.npdPicId,
    salesPicId: input.salesPicId,
    drafterId: input.drafterId,
    supplier: input.supplier?.trim() || undefined,
    priority: input.priority,
    startDate: input.startDate,
    targetDate: input.targetDate,
    currentProcessId: null,
    status: 'not_started',
    nextAction: '',
    nextActionDue: input.startDate,
    remarks: input.remarks?.trim() || undefined,
    workflowId: template.id,
    workflowVersion: template.version,
    createdAt: ctx.now,
    createdBy: ctx.actor.id,
    updatedAt: ctx.now,
  };
  db.projects.push(project);
  const procs = instantiateProcesses(db, project, template);
  db.processes.push(...procs);

  logActivity(db, ctx, project, 'project_created', `Project dibuat — ${template.name} v${template.version}`, {
    detail: `${customerName(db, project.customerId)} · ${PROJECT_TYPE_LABEL[project.type]} · Priority ${PRIORITY_LABEL[project.priority]}`,
  });
  enterProcess(db, ctx, project, procs[0]);
  if (project.startDate > ctx.today) project.status = 'not_started';
  if (input.nextAction?.trim()) {
    project.nextAction = input.nextAction.trim();
    procs[0].nextAction = project.nextAction;
  }
  if (input.nextActionDue && isValidISODate(input.nextActionDue)) {
    project.nextActionDue = input.nextActionDue;
    procs[0].nextActionDue = input.nextActionDue;
  }
  notify(
    db,
    ctx,
    [project.npdPicId, project.salesPicId, project.drafterId],
    'project_assigned',
    'Project baru di-assign ke Anda',
    `${project.code} · ${project.name} (${customerName(db, project.customerId)}, ${PROJECT_TYPE_LABEL[project.type]})`,
    { projectId: project.id },
  );
  return project;
}

const EDITABLE: Array<keyof ProjectInput> = [
  'customerId',
  'name',
  'productName',
  'productDescription',
  'customerRequest',
  'npdPicId',
  'salesPicId',
  'drafterId',
  'supplier',
  'priority',
  'startDate',
  'targetDate',
  'remarks',
];

const FIELD_LABEL: Partial<Record<keyof ProjectInput, string>> = {
  customerId: 'Customer',
  name: 'Project Name',
  productName: 'Product Name',
  productDescription: 'Product Description',
  customerRequest: 'Customer Request',
  npdPicId: 'NPD PIC',
  salesPicId: 'Sales PIC',
  drafterId: 'Drafter',
  supplier: 'Mold Maker / Supplier',
  priority: 'Priority',
  startDate: 'Start Date',
  targetDate: 'Target Finish',
  remarks: 'Remarks',
};

export function updateProject(db: DbState, ctx: CommandContext, projectId: string, patch: Partial<ProjectInput>): Project {
  const project = findProject(db, projectId);
  if (!canEditProject(ctx.actor, project)) throw new AppError('FORBIDDEN', 'Anda tidak memiliki akses untuk mengubah project ini.');
  if (project.status === 'cancelled') throw new AppError('INVALID_STATE', 'Project Cancelled tidak dapat diubah.');
  const merged: ProjectInput = { ...(project as unknown as ProjectInput), ...patch, type: project.type };
  const fieldErrors = validateProjectInput(db, merged);
  if (Object.keys(fieldErrors).length) throw new AppError('VALIDATION', 'Periksa kembali data project.', { fieldErrors });
  const dup = findDuplicate(db, merged.customerId, merged.name, project.id);
  if (dup) throw new AppError('CONFLICT', `Nama project sudah digunakan oleh ${dup.code} untuk customer ini.`, { fieldErrors: { name: `Sudah digunakan oleh ${dup.code}.` } });

  const changes: string[] = [];
  const describe = (key: keyof ProjectInput, value: unknown): string => {
    if (value === undefined || value === '') return '—';
    if (key === 'customerId') return customerName(db, String(value));
    if (key === 'npdPicId' || key === 'salesPicId' || key === 'drafterId') return userName(db, String(value));
    if (key === 'priority') return PRIORITY_LABEL[value as Priority];
    if (key === 'startDate' || key === 'targetDate') return formatDate(String(value));
    return String(value);
  };
  const record = project as unknown as Record<string, unknown>;
  for (const key of EDITABLE) {
    if (!(key in patch)) continue;
    let value = patch[key];
    if (typeof value === 'string') value = value.trim() || undefined;
    if ((record[key] ?? undefined) === (value ?? undefined)) continue;
    changes.push(`${FIELD_LABEL[key]}: ${describe(key, record[key])} → ${describe(key, value)}`);
    record[key] = value;
    if (key === 'npdPicId' || key === 'salesPicId' || key === 'drafterId') {
      notify(db, ctx, [String(value)], 'project_assigned', 'Project di-assign ke Anda', `${project.code} · ${project.name} — Anda menjadi ${FIELD_LABEL[key]}.`, {
        projectId: project.id,
      });
      // Re-assign default PICs of processes that are not finished yet.
      const role = key === 'npdPicId' ? 'npd_staff' : key === 'salesPicId' ? 'admin_sales' : 'drafter';
      for (const proc of projectProcesses(db, project.id)) {
        if (proc.picRole === role && proc.status !== 'completed' && proc.status !== 'skipped') proc.picId = String(value);
      }
      const cur = currentProcess(db, project);
      if (cur && isActiveStatus(project.status) && project.status !== 'hold') applyAutoStatus(db, project, cur);
    }
  }
  if (changes.length === 0) return project;
  logActivity(db, ctx, project, 'project_updated', 'Informasi project diperbarui', { detail: changes.join('\n') });
  return project;
}

const isActiveStatus = (s: ProjectStatus) => s !== 'completed' && s !== 'cancelled';

export const MANUAL_STATUSES: ProjectStatus[] = ['on_progress', 'waiting_approval', 'waiting_external', 'hold', 'cancelled'];

export interface StatusInput {
  status: ProjectStatus;
  waitingFor?: string;
  reason?: string;
}

export function updateProjectStatus(db: DbState, ctx: CommandContext, projectId: string, input: StatusInput): Project {
  const project = findProject(db, projectId);
  if (!canChangeStatus(ctx.actor)) throw new AppError('FORBIDDEN', 'Hanya Admin atau NPD Staff yang dapat mengubah status project.');
  if (!isActiveStatus(project.status)) throw new AppError('INVALID_STATE', `Project sudah ${STATUS_LABEL[project.status]}.`);
  if (!MANUAL_STATUSES.includes(input.status)) throw new AppError('VALIDATION', 'Status tidak dapat dipilih manual. Completed hanya melalui Finish.');
  if (input.status === project.status && input.status !== 'waiting_external')
    throw new AppError('VALIDATION', 'Status yang dipilih sama dengan status saat ini.');
  const fe: Record<string, string> = {};
  const reason = input.reason?.trim();
  const waitingFor = input.waitingFor?.trim();
  if ((input.status === 'hold' || input.status === 'cancelled') && !reason) fe.reason = 'Alasan wajib diisi.';
  if (input.status === 'waiting_external' && !waitingFor) fe.waitingFor = 'Waiting For wajib diisi untuk status Waiting External.';
  if (Object.keys(fe).length) throw new AppError('VALIDATION', 'Lengkapi data status.', { fieldErrors: fe });
  if (input.status === 'waiting_approval') {
    const pending = db.approvals.find((a) => a.projectId === project.id && a.status === 'pending');
    if (!pending)
      throw new AppError('VALIDATION', 'Status Waiting Approval memerlukan approval record berstatus Pending. Ajukan approval terlebih dahulu.');
    project.waitingFor = waitingFor || pending.approverName;
  }

  const prev = project.status;
  const cur = currentProcess(db, project);
  if (input.status === 'on_progress' && prev === 'hold' && cur) {
    // Resume from Hold: status follows the current process again.
    applyAutoStatus(db, project, cur);
  } else {
    project.status = input.status;
    if (input.status === 'waiting_external') project.waitingFor = waitingFor;
    if (input.status === 'on_progress' && waitingFor) project.waitingFor = waitingFor;
    if (input.status === 'cancelled') project.waitingFor = undefined;
  }
  project.statusReason = reason || undefined;
  if (cur && project.waitingFor) cur.waitingFor = project.waitingFor;
  logActivity(db, ctx, project, 'status_changed', `Status: ${STATUS_LABEL[prev]} → ${STATUS_LABEL[project.status]}`, {
    detail: [reason, project.waitingFor ? `Waiting For: ${project.waitingFor}` : ''].filter(Boolean).join('\n') || undefined,
  });
  return project;
}

export interface NextActionInput {
  nextAction: string;
  nextActionDue: ISODate;
  waitingFor?: string;
}

export function updateNextAction(db: DbState, ctx: CommandContext, projectId: string, input: NextActionInput): Project {
  const project = findProject(db, projectId);
  const cur = currentProcess(db, project);
  if (!canUpdateNextAction(ctx.actor, project, cur)) throw new AppError('FORBIDDEN', 'Anda tidak memiliki akses untuk mengubah Next Action.');
  assertActive(project, 'mengubah Next Action');
  const fe: Record<string, string> = {};
  if (!input.nextAction?.trim()) fe.nextAction = 'Next Action wajib diisi.';
  if (!isValidISODate(input.nextActionDue)) fe.nextActionDue = 'Tanggal due wajib diisi.';
  const waitingFor = input.waitingFor?.trim();
  if ((project.status === 'waiting_external' || project.status === 'waiting_approval') && !waitingFor)
    fe.waitingFor = 'Waiting For wajib diisi selama status Waiting.';
  if (Object.keys(fe).length) throw new AppError('VALIDATION', 'Lengkapi Next Action.', { fieldErrors: fe });
  const before = `${project.nextAction} (${formatDate(project.nextActionDue)})`;
  project.nextAction = input.nextAction.trim();
  project.nextActionDue = input.nextActionDue;
  if (waitingFor !== undefined) project.waitingFor = waitingFor || undefined;
  if (cur) {
    cur.nextAction = project.nextAction;
    cur.nextActionDue = project.nextActionDue;
    cur.waitingFor = project.waitingFor;
  }
  logActivity(db, ctx, project, 'next_action_updated', 'Next Action diperbarui', {
    processId: cur?.id,
    detail: `${before} → ${project.nextAction} (${formatDate(project.nextActionDue)})${project.waitingFor ? `\nWaiting For: ${project.waitingFor}` : ''}`,
  });
  return project;
}

export interface ProcessPatch {
  picId?: string;
  plannedStart?: ISODate;
  plannedFinish?: ISODate;
  remarks?: string;
  data?: Record<string, unknown>;
}

export function updateProcess(db: DbState, ctx: CommandContext, processId: string, patch: ProcessPatch): void {
  const proc = findProcess(db, processId);
  const project = findProject(db, proc.projectId);
  const planning = patch.picId !== undefined || patch.plannedStart !== undefined || patch.plannedFinish !== undefined;
  if (planning && !canPlanProcess(ctx.actor)) throw new AppError('FORBIDDEN', 'Hanya Admin atau NPD Staff yang dapat mengubah PIC dan planning.');
  if (!planning && !canActOnProcess(ctx.actor, project, proc) && !canPlanProcess(ctx.actor))
    throw new AppError('FORBIDDEN', 'Anda tidak memiliki akses pada proses ini.');
  if (project.status === 'cancelled') throw new AppError('INVALID_STATE', 'Project Cancelled tidak dapat diubah.');

  const start = patch.plannedStart ?? proc.plannedStart;
  const finish = patch.plannedFinish ?? proc.plannedFinish;
  const fe: Record<string, string> = {};
  if (patch.plannedStart !== undefined && !isValidISODate(patch.plannedStart)) fe.plannedStart = 'Tanggal tidak valid.';
  if (patch.plannedFinish !== undefined && !isValidISODate(patch.plannedFinish)) fe.plannedFinish = 'Tanggal tidak valid.';
  if (!fe.plannedStart && !fe.plannedFinish && finish < start) fe.plannedFinish = 'Planned Finish harus sama/setelah Planned Start.';
  if (patch.picId !== undefined && !db.users.some((u) => u.id === patch.picId && u.active)) fe.picId = 'Pilih PIC yang aktif.';
  if (Object.keys(fe).length) throw new AppError('VALIDATION', 'Periksa kembali data proses.', { fieldErrors: fe });

  const changes: string[] = [];
  if (patch.picId !== undefined && patch.picId !== proc.picId) {
    changes.push(`PIC: ${userName(db, proc.picId)} → ${userName(db, patch.picId)}`);
    proc.picId = patch.picId;
    if (project.currentProcessId === proc.id && isRunning(proc) && project.status !== 'hold') applyAutoStatus(db, project, proc);
  }
  if (patch.plannedStart !== undefined && patch.plannedStart !== proc.plannedStart) {
    changes.push(`Planned Start: ${formatDate(proc.plannedStart)} → ${formatDate(patch.plannedStart)}`);
    proc.plannedStart = patch.plannedStart;
  }
  if (patch.plannedFinish !== undefined && patch.plannedFinish !== proc.plannedFinish) {
    changes.push(`Planned Finish: ${formatDate(proc.plannedFinish)} → ${formatDate(patch.plannedFinish)}`);
    proc.plannedFinish = patch.plannedFinish;
  }
  if (patch.remarks !== undefined && (patch.remarks.trim() || undefined) !== proc.remarks) {
    changes.push('Remarks diperbarui');
    proc.remarks = patch.remarks.trim() || undefined;
  }
  if (patch.data !== undefined) {
    proc.data = { ...proc.data, ...patch.data };
    changes.push('Draft data proses disimpan');
  }
  if (changes.length === 0) return;
  logActivity(db, ctx, project, 'process_updated', `${proc.name} diperbarui`, { processId: proc.id, detail: changes.join('\n') });
}

export function addComment(db: DbState, ctx: CommandContext, input: { projectId: string; processId?: string; body: string }) {
  const project = findProject(db, input.projectId);
  if (!canComment(ctx.actor)) throw new AppError('FORBIDDEN', 'Role Anda hanya memiliki akses baca.');
  const body = input.body.trim();
  if (!body) throw new AppError('VALIDATION', 'Komentar tidak boleh kosong.', { fieldErrors: { body: 'Komentar tidak boleh kosong.' } });
  if (body.length > 2000) throw new AppError('VALIDATION', 'Komentar maksimal 2000 karakter.', { fieldErrors: { body: 'Maksimal 2000 karakter.' } });
  const comment = { id: uid('cmt'), projectId: project.id, processId: input.processId, userId: ctx.actor.id, body, createdAt: ctx.now };
  db.comments.push(comment);
  project.updatedAt = ctx.now;
  return comment;
}

export function updatePurchasingStatus(db: DbState, ctx: CommandContext, recordId: string, status: NonNullable<import('@/types').ProcessRecord['purchasingStatus']>) {
  if (!canUpdatePurchasing(ctx.actor)) throw new AppError('FORBIDDEN', 'Hanya Purchasing, NPD Staff, atau Admin yang dapat mengubah purchasing status.');
  const record = db.records.find((r) => r.id === recordId);
  if (!record || record.recordType !== 'material_request') throw new AppError('NOT_FOUND', 'Material request tidak ditemukan.');
  const project = findProject(db, record.projectId);
  if (record.purchasingStatus === status) return record;
  const prev = record.purchasingStatus;
  record.purchasingStatus = status;
  logActivity(db, ctx, project, 'record_updated', `Purchasing status ${record.number} diperbarui`, {
    processId: record.processId,
    detail: `${prev ?? '—'} → ${status}`,
  });
  return record;
}

export { assertActive };
