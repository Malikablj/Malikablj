import { ROLE_LABEL } from '@/config/labels';
import { isValidISODate } from '@/lib/date';
import { normalize, uid } from '@/lib/utils';
import type { AppSettings, CalendarEvent, Customer, DbState, DocType, ProcessDef, Role, User } from '@/types';
import type { CommandContext } from './context';
import { AppError } from './errors';
import { canManageCalendar, canManageSettings } from './permissions';

function requireAdmin(ctx: CommandContext) {
  if (!canManageSettings(ctx.actor)) throw new AppError('FORBIDDEN', 'Hanya Admin yang dapat mengelola pengaturan.');
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export interface UserInput {
  name: string;
  email: string;
  role: Role;
  title?: string;
  active: boolean;
  password?: string;
}

export function saveUser(db: DbState, ctx: CommandContext, input: UserInput, userId?: string): User {
  requireAdmin(ctx);
  const fe: Record<string, string> = {};
  if (!input.name?.trim()) fe.name = 'Nama wajib diisi.';
  if (!EMAIL_RE.test(input.email?.trim() ?? '')) fe.email = 'Email tidak valid.';
  if (!(input.role in ROLE_LABEL)) fe.role = 'Pilih role.';
  if (!userId && (input.password ?? '').length < 6) fe.password = 'Password minimal 6 karakter.';
  if (userId && input.password && input.password.length < 6) fe.password = 'Password minimal 6 karakter.';
  const email = input.email?.trim().toLowerCase();
  if (!fe.email && db.users.some((u) => u.email.toLowerCase() === email && u.id !== userId)) fe.email = 'Email sudah digunakan.';
  if (Object.keys(fe).length) throw new AppError('VALIDATION', 'Periksa kembali data user.', { fieldErrors: fe });

  if (userId) {
    const user = db.users.find((u) => u.id === userId);
    if (!user) throw new AppError('NOT_FOUND', 'User tidak ditemukan.');
    if (user.id === ctx.actor.id && (!input.active || input.role !== 'admin'))
      throw new AppError('INVALID_STATE', 'Anda tidak dapat menonaktifkan atau menurunkan role akun Anda sendiri.');
    Object.assign(user, { name: input.name.trim(), email, role: input.role, title: input.title?.trim() || ROLE_LABEL[input.role], active: input.active });
    if (input.password) user.password = input.password;
    return user;
  }
  const user: User = {
    id: uid('usr'),
    name: input.name.trim(),
    email,
    role: input.role,
    title: input.title?.trim() || ROLE_LABEL[input.role],
    active: input.active,
    password: input.password!,
  };
  db.users.push(user);
  return user;
}

export interface CustomerInput {
  code: string;
  name: string;
  contactName?: string;
  contactEmail?: string;
  active: boolean;
}

export function saveCustomer(db: DbState, ctx: CommandContext, input: CustomerInput, customerId?: string): Customer {
  requireAdmin(ctx);
  const fe: Record<string, string> = {};
  if (!input.name?.trim()) fe.name = 'Nama customer wajib diisi.';
  if (!input.code?.trim()) fe.code = 'Kode customer wajib diisi.';
  else if (!/^[A-Z0-9-]{2,12}$/.test(input.code.trim().toUpperCase())) fe.code = '2–12 karakter huruf/angka.';
  if (input.contactEmail?.trim() && !EMAIL_RE.test(input.contactEmail.trim())) fe.contactEmail = 'Email tidak valid.';
  const n = normalize(input.name ?? '');
  const code = input.code?.trim().toUpperCase();
  if (!fe.name && db.customers.some((c) => c.id !== customerId && normalize(c.name) === n)) fe.name = 'Customer dengan nama ini sudah ada.';
  if (!fe.code && db.customers.some((c) => c.id !== customerId && c.code === code)) fe.code = 'Kode sudah digunakan.';
  if (Object.keys(fe).length) throw new AppError('VALIDATION', 'Periksa kembali data customer.', { fieldErrors: fe });
  const values = {
    code,
    name: input.name.trim(),
    contactName: input.contactName?.trim() || undefined,
    contactEmail: input.contactEmail?.trim() || undefined,
    active: input.active,
  };
  if (customerId) {
    const c = db.customers.find((x) => x.id === customerId);
    if (!c) throw new AppError('NOT_FOUND', 'Customer tidak ditemukan.');
    Object.assign(c, values);
    return c;
  }
  const c: Customer = { id: uid('cus'), createdAt: ctx.now, ...values };
  db.customers.push(c);
  return c;
}

export function deleteCustomer(db: DbState, ctx: CommandContext, customerId: string): void {
  requireAdmin(ctx);
  const used = db.projects.filter((p) => p.customerId === customerId).length;
  if (used > 0) throw new AppError('CONFLICT', `Customer digunakan oleh ${used} project. Nonaktifkan customer sebagai gantinya.`);
  db.customers = db.customers.filter((c) => c.id !== customerId);
}

export interface WorkflowProcessPatch {
  name?: string;
  shortName?: string;
  description?: string;
  picRole?: Role;
  actorRoles?: Role[];
  durationDays?: number;
  isMandatory?: boolean;
  requiresDocument?: boolean;
  requiredDocTypes?: DocType[];
  defaultNextAction?: string;
}

function findWorkflow(db: DbState, workflowId: string) {
  const wf = db.workflows.find((w) => w.id === workflowId);
  if (!wf) throw new AppError('NOT_FOUND', 'Workflow tidak ditemukan.');
  return wf;
}

function validateProcessPatch(patch: WorkflowProcessPatch): Record<string, string> {
  const fe: Record<string, string> = {};
  if (patch.name !== undefined && !patch.name.trim()) fe.name = 'Nama proses wajib diisi.';
  if (patch.durationDays !== undefined && (!Number.isInteger(patch.durationDays) || patch.durationDays < 1 || patch.durationDays > 365))
    fe.durationDays = 'Durasi 1–365 hari.';
  if (patch.actorRoles !== undefined && patch.actorRoles.length === 0) fe.actorRoles = 'Pilih minimal satu role.';
  if (patch.requiresDocument && patch.requiredDocTypes !== undefined && patch.requiredDocTypes.length === 0)
    fe.requiredDocTypes = 'Pilih minimal satu document type wajib.';
  return fe;
}

export function updateWorkflowProcess(db: DbState, ctx: CommandContext, workflowId: string, key: string, patch: WorkflowProcessPatch) {
  requireAdmin(ctx);
  const wf = findWorkflow(db, workflowId);
  const proc = wf.processes.find((p) => p.key === key);
  if (!proc) throw new AppError('NOT_FOUND', 'Proses tidak ditemukan.');
  const fe = validateProcessPatch(patch);
  if (Object.keys(fe).length) throw new AppError('VALIDATION', 'Periksa kembali data proses.', { fieldErrors: fe });
  if (proc.kind === 'finish' && patch.isMandatory === false) throw new AppError('VALIDATION', 'Proses Finish selalu wajib.');
  Object.assign(proc, {
    ...patch,
    name: patch.name?.trim() ?? proc.name,
    shortName: patch.shortName?.trim() || (patch.name?.trim() ?? proc.shortName),
    requiredDocTypes: patch.requiresDocument === false ? [] : (patch.requiredDocTypes ?? proc.requiredDocTypes),
  });
  if (patch.actorRoles) proc.uploadRoles = Array.from(new Set([...patch.actorRoles, ...proc.uploadRoles]));
  wf.version += 1;
  wf.updatedAt = ctx.now;
  wf.updatedBy = ctx.actor.id;
  return wf;
}

export function addWorkflowProcess(
  db: DbState,
  ctx: CommandContext,
  workflowId: string,
  afterKey: string,
  input: Required<Pick<WorkflowProcessPatch, 'name' | 'picRole' | 'durationDays'>> & WorkflowProcessPatch,
) {
  requireAdmin(ctx);
  const wf = findWorkflow(db, workflowId);
  const idx = wf.processes.findIndex((p) => p.key === afterKey);
  if (idx < 0) throw new AppError('NOT_FOUND', 'Posisi proses tidak ditemukan.');
  if (wf.processes[idx].kind === 'finish') throw new AppError('VALIDATION', 'Proses baru tidak dapat ditambahkan setelah Finish.');
  const fe = validateProcessPatch(input);
  if (!input.name?.trim()) fe.name = 'Nama proses wajib diisi.';
  if (wf.processes.some((p) => normalize(p.name) === normalize(input.name ?? ''))) fe.name = 'Nama proses sudah ada di workflow ini.';
  if (Object.keys(fe).length) throw new AppError('VALIDATION', 'Periksa kembali data proses.', { fieldErrors: fe });
  const prev = wf.processes[idx];
  const next = wf.processes[idx + 1];
  const sameBranch = !!prev.branchGroup && next?.branchGroup === prev.branchGroup && next?.branch === prev.branch;
  const actorRoles = input.actorRoles?.length ? input.actorRoles : [input.picRole];
  const def: ProcessDef = {
    key: `custom_${uid().slice(0, 8)}`,
    name: input.name.trim(),
    shortName: input.shortName?.trim() || input.name.trim(),
    description: input.description?.trim() || 'Proses tambahan yang dikonfigurasi Admin.',
    kind: 'task',
    picRole: input.picRole,
    actorRoles,
    uploadRoles: actorRoles,
    isMandatory: input.isMandatory ?? true,
    requiresDocument: input.requiresDocument ?? false,
    requiredDocTypes: input.requiresDocument ? (input.requiredDocTypes ?? []) : [],
    suggestedDocTypes: input.requiredDocTypes?.length ? input.requiredDocTypes : ['other'],
    requiresApproval: false,
    waitingType: 'internal',
    defaultNextAction: input.defaultNextAction?.trim() || `Selesaikan ${input.name.trim()}`,
    durationDays: input.durationDays,
    branchGroup: sameBranch ? prev.branchGroup : undefined,
    branch: sameBranch ? prev.branch : undefined,
    folder: prev.folder,
    recordType: 'general',
    fields: [{ key: 'notes', label: 'Catatan', type: 'textarea' }],
    custom: true,
  };
  wf.processes.splice(idx + 1, 0, def);
  wf.version += 1;
  wf.updatedAt = ctx.now;
  wf.updatedBy = ctx.actor.id;
  return wf;
}

export function moveWorkflowProcess(db: DbState, ctx: CommandContext, workflowId: string, key: string, direction: -1 | 1) {
  requireAdmin(ctx);
  const wf = findWorkflow(db, workflowId);
  const idx = wf.processes.findIndex((p) => p.key === key);
  const proc = wf.processes[idx];
  if (!proc?.custom) throw new AppError('INVALID_STATE', 'Hanya proses tambahan yang dapat dipindahkan. Urutan proses inti mengikuti PRD.');
  const swap = idx + direction;
  if (swap < 1 || swap >= wf.processes.length - 1) throw new AppError('INVALID_STATE', 'Proses tidak dapat dipindah ke posisi tersebut.');
  const other = wf.processes[swap];
  if (other.branchGroup !== proc.branchGroup) {
    proc.branchGroup = other.branchGroup;
    proc.branch = other.branch;
  }
  wf.processes[idx] = other;
  wf.processes[swap] = proc;
  wf.version += 1;
  wf.updatedAt = ctx.now;
  return wf;
}

export function removeWorkflowProcess(db: DbState, ctx: CommandContext, workflowId: string, key: string) {
  requireAdmin(ctx);
  const wf = findWorkflow(db, workflowId);
  const proc = wf.processes.find((p) => p.key === key);
  if (!proc?.custom) throw new AppError('INVALID_STATE', 'Proses inti tidak dapat dihapus.');
  wf.processes = wf.processes.filter((p) => p.key !== key);
  wf.version += 1;
  wf.updatedAt = ctx.now;
  return wf;
}

export function updateSettings(db: DbState, ctx: CommandContext, patch: Partial<AppSettings>): AppSettings {
  requireAdmin(ctx);
  const fe: Record<string, string> = {};
  if (patch.dueSoonDays !== undefined && (!Number.isInteger(patch.dueSoonDays) || patch.dueSoonDays < 1 || patch.dueSoonDays > 30))
    fe.dueSoonDays = 'Isi 1–30 hari.';
  if (patch.noUpdateDays !== undefined && (!Number.isInteger(patch.noUpdateDays) || patch.noUpdateDays < 1 || patch.noUpdateDays > 60))
    fe.noUpdateDays = 'Isi 1–60 hari.';
  if (patch.simulatedFailureRate !== undefined && (patch.simulatedFailureRate < 0 || patch.simulatedFailureRate > 50))
    fe.simulatedFailureRate = 'Isi 0–50%.';
  if (Object.keys(fe).length) throw new AppError('VALIDATION', 'Periksa kembali pengaturan.', { fieldErrors: fe });
  Object.assign(db.settings, patch);
  return db.settings;
}

export interface EventInput {
  title: string;
  category: CalendarEvent['category'];
  date: string;
  time?: string;
  projectId?: string;
  notes?: string;
}

export function saveEvent(db: DbState, ctx: CommandContext, input: EventInput, eventId?: string): CalendarEvent {
  if (!canManageCalendar(ctx.actor)) throw new AppError('FORBIDDEN', 'Role Anda hanya memiliki akses baca.');
  const fe: Record<string, string> = {};
  if (!input.title?.trim()) fe.title = 'Judul wajib diisi.';
  if (!isValidISODate(input.date)) fe.date = 'Tanggal wajib diisi.';
  if (input.time && !/^\d{2}:\d{2}$/.test(input.time)) fe.time = 'Format jam HH:MM.';
  if (input.category !== 'meeting' && input.category !== 'follow_up') fe.category = 'Pilih kategori.';
  if (input.projectId && !db.projects.some((p) => p.id === input.projectId)) fe.projectId = 'Project tidak ditemukan.';
  if (Object.keys(fe).length) throw new AppError('VALIDATION', 'Periksa kembali data event.', { fieldErrors: fe });
  const values = {
    title: input.title.trim(),
    category: input.category,
    date: input.date,
    time: input.time || undefined,
    projectId: input.projectId || undefined,
    notes: input.notes?.trim() || undefined,
  };
  if (eventId) {
    const ev = db.events.find((e) => e.id === eventId);
    if (!ev) throw new AppError('NOT_FOUND', 'Event tidak ditemukan.');
    if (ev.createdById !== ctx.actor.id && ctx.actor.role !== 'admin') throw new AppError('FORBIDDEN', 'Hanya pembuat event atau Admin yang dapat mengubah event ini.');
    Object.assign(ev, values);
    return ev;
  }
  const ev: CalendarEvent = { id: uid('evt'), createdById: ctx.actor.id, createdAt: ctx.now, ...values };
  db.events.push(ev);
  return ev;
}

export function deleteEvent(db: DbState, ctx: CommandContext, eventId: string): void {
  const ev = db.events.find((e) => e.id === eventId);
  if (!ev) throw new AppError('NOT_FOUND', 'Event tidak ditemukan.');
  if (ev.createdById !== ctx.actor.id && ctx.actor.role !== 'admin') throw new AppError('FORBIDDEN', 'Hanya pembuat event atau Admin yang dapat menghapus event ini.');
  db.events = db.events.filter((e) => e.id !== eventId);
}
