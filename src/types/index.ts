// Domain model for NPD Project Control.
// Mirrors the PRD database structure (users, customers, projects, workflows,
// project_processes, approvals, documents, document_versions, trial/material/
// validation records, activities, notifications, comments, master_data).

export type ISODate = string; // YYYY-MM-DD
export type ISODateTime = string; // full ISO timestamp

export type Role =
  | 'admin'
  | 'admin_sales'
  | 'npd_staff'
  | 'drafter'
  | 'purchasing'
  | 'production'
  | 'quality'
  | 'management';

export interface User {
  id: string;
  name: string;
  email: string;
  role: Role;
  title: string;
  active: boolean;
  /** Demo-only credential. A real backend would never expose this. */
  password: string;
}

export type PublicUser = Omit<User, 'password'>;

export interface Customer {
  id: string;
  code: string;
  name: string;
  contactName?: string;
  contactEmail?: string;
  active: boolean;
  createdAt: ISODateTime;
}

export type ProjectType = 'new_mold' | 'subcont';

/** Stored status. "Overdue" is derived from dates (see domain/metrics). */
export type ProjectStatus =
  | 'not_started'
  | 'on_progress'
  | 'waiting_approval'
  | 'waiting_external'
  | 'hold'
  | 'completed'
  | 'cancelled';

/** Status as shown to users — includes the derived Overdue state. */
export type DisplayStatus = ProjectStatus | 'overdue';

export type Priority = 'low' | 'medium' | 'high' | 'urgent';

export type ProcessStatus = 'not_started' | 'current' | 'completed' | 'revision' | 'problem' | 'skipped';

export type ProcessKind = 'task' | 'decision' | 'finish';

export type WaitingType = 'internal' | 'external' | 'customer';

export type DocType =
  | 'npr'
  | 'feedback'
  | 'artwork'
  | 'technical_drawing'
  | 'drawing_3d'
  | 'drawing_2d'
  | 'mold_drawing'
  | 'approval'
  | 'trial_report'
  | 'trial_photo'
  | 'trial_video'
  | 'material_request'
  | 'coa'
  | 'material_spec'
  | 'validation_report'
  | 'customer_document'
  | 'supplier_document'
  | 'other';

export type ApprovalType =
  | 'npr'
  | 'artwork'
  | 'masterbatch'
  | '3d'
  | '2d'
  | 'mold_drawing'
  | 't0'
  | 'trial'
  | 'commissioning'
  | 'validation';

export type ApprovalStatus = 'pending' | 'approved' | 'rejected' | 'revision_required';

export type ApproverType = 'customer' | 'internal';

export type RecordType = 'general' | 'trial' | 'material_request' | 'material_preparation' | 'validation';

export type OutcomeTone = 'positive' | 'negative' | 'neutral';

/** Where an outcome sends the workflow. */
export type OutcomeTarget =
  | { kind: 'next' }
  | { kind: 'process'; key: string; alternatives?: string[] }
  | { kind: 'repeat' }
  | { kind: 'cancel' };

export interface ProcessOutcome {
  key: string;
  label: string;
  tone: OutcomeTone;
  target: OutcomeTarget;
  /** Maps the outcome onto the linked approval record. */
  approvalStatus?: ApprovalStatus;
  requiresComment?: boolean;
  /** Stored on the project when chosen (e.g. New Masterbatch = yes). */
  setsNewMasterbatch?: boolean;
}

export type FieldType = 'text' | 'textarea' | 'number' | 'date' | 'select' | 'multiselect' | 'user' | 'docRevision';

export interface FormFieldDef {
  key: string;
  label: string;
  type: FieldType;
  required?: boolean;
  options?: string[];
  placeholder?: string;
  helper?: string;
  /** Pre-fill from project fields (e.g. customer name). */
  prefill?: 'customer' | 'projectName' | 'productName' | 'customerRequest' | 'salesPic' | 'npdPic' | 'drafter' | 'today' | 'supplier';
  role?: Role; // for user pickers
  docType?: DocType; // for docRevision pickers
}

/** Process definition inside a workflow template (the `processes` table). */
export interface ProcessDef {
  key: string;
  name: string;
  shortName: string;
  description: string;
  kind: ProcessKind;
  picRole: Role;
  actorRoles: Role[];
  uploadRoles: Role[];
  isMandatory: boolean;
  /** Only executed inside a loop (e.g. Mold Correction). */
  loopOnly?: boolean;
  requiresDocument: boolean;
  requiredDocTypes: DocType[];
  suggestedDocTypes: DocType[];
  requiresApproval: boolean;
  approvalType?: ApprovalType;
  approverType?: ApproverType;
  outcomes?: ProcessOutcome[];
  /** Explicit next step for tasks; defaults to the next process in sequence. */
  nextKey?: string;
  waitingType: WaitingType;
  waitingLabel?: string;
  defaultNextAction: string;
  durationDays: number;
  /** Alternative branches that share the same planned window. */
  branchGroup?: string;
  branch?: string;
  folder: string;
  recordType: RecordType;
  fields: FormFieldDef[];
  /** Calendar category derived from this process' planned finish. */
  calendarCategory?: CalendarCategory;
  /** Custom processes added by an Admin through Settings. */
  custom?: boolean;
}

export interface WorkflowTemplate {
  id: string;
  projectType: ProjectType;
  name: string;
  version: number;
  processes: ProcessDef[];
  updatedAt: ISODateTime;
  updatedBy?: string;
}

export interface Project {
  id: string;
  code: string; // NPD-YYYY-XXX
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
  actualFinish?: ISODate;
  currentProcessId: string | null;
  status: ProjectStatus;
  waitingFor?: string;
  nextAction: string;
  nextActionDue: ISODate;
  remarks?: string;
  statusReason?: string;
  newMasterbatch?: boolean;
  workflowId: string;
  workflowVersion: number;
  createdAt: ISODateTime;
  createdBy: string;
  updatedAt: ISODateTime;
}

/** Process instance of a project (the `project_processes` table). */
export interface ProjectProcess extends Omit<ProcessDef, 'fields'> {
  id: string;
  projectId: string;
  sequence: number;
  fields: FormFieldDef[];
  status: ProcessStatus;
  picId?: string;
  plannedStart: ISODate;
  plannedFinish: ISODate;
  actualStart?: ISODate;
  actualFinish?: ISODate;
  waitingFor?: string;
  nextAction?: string;
  nextActionDue?: ISODate;
  remarks?: string;
  /** Number of times this process has been (re)started. */
  iteration: number;
  /** Number of times the workflow looped back into this process. */
  loopCount: number;
  lastOutcome?: string;
  problemNote?: string;
  data: Record<string, unknown>;
  enteredAt?: ISODateTime;
}

export interface Approval {
  id: string;
  code: string;
  projectId: string;
  processId: string;
  type: ApprovalType;
  revision: string;
  documentVersionId?: string;
  requestedDate: ISODate;
  requestedById: string;
  approverType: ApproverType;
  approverName: string;
  status: ApprovalStatus;
  decisionDate?: ISODate;
  decidedById?: string;
  comment?: string;
  attachmentVersionId?: string;
  iteration: number;
  /** Workflow-driven approvals move the workflow when decided. */
  linkedToWorkflow: boolean;
  createdAt: ISODateTime;
}

export type DocumentStatus = 'current' | 'superseded' | 'rejected' | 'approved';

export interface ProjectDocument {
  id: string;
  projectId: string;
  processId: string;
  type: DocType;
  name: string;
  description?: string;
  createdAt: ISODateTime;
  createdBy: string;
  latestVersionId: string;
}

export interface DocumentVersion {
  id: string;
  documentId: string;
  revision: number; // Rev 00, 01, ...
  version: number; // file version inside a revision
  fileName: string;
  mimeType: string;
  size: number;
  blobKey?: string;
  uploadedById: string;
  uploadedAt: ISODateTime;
  status: DocumentStatus;
  note?: string;
}

export interface ProcessRecord {
  id: string;
  number: string;
  projectId: string;
  processId: string;
  recordType: RecordType;
  iteration: number;
  data: Record<string, unknown>;
  outcome?: string;
  outcomeLabel?: string;
  comment?: string;
  purchasingStatus?: PurchasingStatus;
  createdById: string;
  createdAt: ISODateTime;
}

export type PurchasingStatus = 'requested' | 'po_issued' | 'in_transit' | 'received' | 'cancelled';

export type ActivityType =
  | 'project_created'
  | 'project_updated'
  | 'status_changed'
  | 'next_action_updated'
  | 'process_started'
  | 'process_completed'
  | 'process_updated'
  | 'process_problem'
  | 'process_loop'
  | 'process_skipped'
  | 'approval_requested'
  | 'approval_decided'
  | 'document_uploaded'
  | 'document_revision'
  | 'record_created'
  | 'record_updated'
  | 'project_finished'
  | 'comment';

export interface Activity {
  id: string;
  projectId: string;
  processId?: string;
  type: ActivityType;
  message: string;
  detail?: string;
  userId: string;
  at: ISODateTime;
}

export interface Comment {
  id: string;
  projectId: string;
  processId?: string;
  userId: string;
  body: string;
  createdAt: ISODateTime;
}

export type NotificationType =
  | 'project_assigned'
  | 'approval_requested'
  | 'approval_rejected'
  | 'approval_approved'
  | 'artwork_revision'
  | 'document_uploaded'
  | 'document_revision'
  | 'deadline_approaching'
  | 'project_overdue'
  | 'next_action_due'
  | 'next_action_overdue'
  | 'missing_document';

export interface AppNotification {
  id: string;
  userId: string;
  type: NotificationType;
  title: string;
  body: string;
  projectId?: string;
  createdAt: ISODateTime;
  read: boolean;
  dedupeKey?: string;
}

export type CalendarCategory =
  | 'deadline'
  | 'customer_approval'
  | 'trial'
  | 'commissioning'
  | 'material_arrival'
  | 'validation'
  | 'meeting'
  | 'follow_up';

export interface CalendarEvent {
  id: string;
  title: string;
  category: 'meeting' | 'follow_up';
  date: ISODate;
  time?: string;
  projectId?: string;
  notes?: string;
  createdById: string;
  createdAt: ISODateTime;
}

export interface AppSettings {
  dueSoonDays: number;
  noUpdateDays: number;
  /** Diagnostic: percentage of API calls that fail, to exercise error states. */
  simulatedFailureRate: number;
  simulatedLatency: boolean;
}

export interface Counters {
  project: Record<string, number>;
  approval: number;
  record: Record<string, number>;
}

export interface DbState {
  schemaVersion: number;
  seededAt: ISODateTime;
  users: User[];
  customers: Customer[];
  workflows: WorkflowTemplate[];
  projects: Project[];
  processes: ProjectProcess[];
  approvals: Approval[];
  documents: ProjectDocument[];
  documentVersions: DocumentVersion[];
  records: ProcessRecord[];
  activities: Activity[];
  comments: Comment[];
  notifications: AppNotification[];
  events: CalendarEvent[];
  settings: AppSettings;
  counters: Counters;
}
