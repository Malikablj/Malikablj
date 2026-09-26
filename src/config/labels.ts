import type {
  ApprovalStatus,
  ApprovalType,
  CalendarCategory,
  DisplayStatus,
  DocType,
  DocumentStatus,
  NotificationType,
  Priority,
  ProcessStatus,
  ProjectType,
  PurchasingStatus,
  Role,
} from '@/types';

/** Semantic tones from the PRD status color system. */
export type Tone = 'green' | 'blue' | 'yellow' | 'red' | 'gray' | 'orange';

export const ROLE_LABEL: Record<Role, string> = {
  admin: 'Admin',
  admin_sales: 'Admin Sales',
  npd_staff: 'NPD Staff',
  drafter: 'Drafter',
  purchasing: 'Purchasing',
  production: 'Production',
  quality: 'Quality',
  management: 'Management',
};

export const ROLE_DESCRIPTION: Record<Role, string> = {
  admin: 'Akses penuh: user, workflow, master data, semua project',
  admin_sales: 'Create NPR, submit artwork & trial result, dokumen Sales',
  npd_staff: 'Feedback NPR, project control, trial, material, validation',
  drafter: 'Artwork, drawing, revision, dokumen drafting',
  purchasing: 'Material request, purchasing status, dokumen purchasing',
  production: 'Data trial & produksi yang relevan',
  quality: 'Quality, test, dan validation result',
  management: 'Read-only: dashboard, report, analytics',
};

export const PROJECT_TYPE_LABEL: Record<ProjectType, string> = {
  new_mold: 'New Mold',
  subcont: 'Subcont',
};

export const STATUS_LABEL: Record<DisplayStatus, string> = {
  not_started: 'Not Started',
  on_progress: 'On Progress',
  waiting_approval: 'Waiting Approval',
  waiting_external: 'Waiting External',
  hold: 'Hold',
  overdue: 'Overdue',
  completed: 'Completed',
  cancelled: 'Cancelled',
};

export const STATUS_TONE: Record<DisplayStatus, Tone> = {
  not_started: 'gray',
  on_progress: 'blue',
  waiting_approval: 'yellow',
  waiting_external: 'yellow',
  hold: 'orange',
  overdue: 'red',
  completed: 'green',
  cancelled: 'gray',
};

export const PRIORITY_LABEL: Record<Priority, string> = {
  low: 'Low',
  medium: 'Medium',
  high: 'High',
  urgent: 'Urgent',
};

export const PRIORITY_TONE: Record<Priority, Tone> = {
  low: 'gray',
  medium: 'blue',
  high: 'orange',
  urgent: 'red',
};

export const PRIORITY_RANK: Record<Priority, number> = { low: 0, medium: 1, high: 2, urgent: 3 };

export const PROCESS_STATUS_LABEL: Record<ProcessStatus, string> = {
  not_started: 'Not Started',
  current: 'Current',
  completed: 'Completed',
  revision: 'Revision',
  problem: 'Problem',
  skipped: 'Tidak dijalankan',
};

export const PROCESS_STATUS_TONE: Record<ProcessStatus, Tone> = {
  not_started: 'gray',
  current: 'blue',
  completed: 'green',
  revision: 'orange',
  problem: 'red',
  skipped: 'gray',
};

export const DOC_TYPE_LABEL: Record<DocType, string> = {
  npr: 'NPR',
  feedback: 'Feedback',
  artwork: 'Artwork',
  technical_drawing: 'Technical Drawing',
  drawing_3d: '3D Drawing',
  drawing_2d: '2D Drawing',
  mold_drawing: 'Mold Drawing',
  approval: 'Approval',
  trial_report: 'Trial Report',
  trial_photo: 'Trial Photo',
  trial_video: 'Trial Video',
  material_request: 'Material Request',
  coa: 'COA',
  material_spec: 'Material Specification',
  validation_report: 'Validation Report',
  customer_document: 'Customer Document',
  supplier_document: 'Supplier Document',
  other: 'Other',
};

export const DOC_TYPES = Object.keys(DOC_TYPE_LABEL) as DocType[];

export const DOC_STATUS_LABEL: Record<DocumentStatus, string> = {
  current: 'Current',
  superseded: 'Superseded',
  rejected: 'Rejected',
  approved: 'Approved',
};

export const DOC_STATUS_TONE: Record<DocumentStatus, Tone> = {
  current: 'blue',
  superseded: 'gray',
  rejected: 'red',
  approved: 'green',
};

export const APPROVAL_TYPE_LABEL: Record<ApprovalType, string> = {
  npr: 'NPR Approval',
  artwork: 'Artwork Approval',
  masterbatch: 'Masterbatch Approval',
  '3d': '3D Approval',
  '2d': '2D Approval',
  mold_drawing: 'Mold Drawing Approval',
  t0: 'T0 Approval',
  trial: 'Trial Approval',
  commissioning: 'Commissioning Approval',
  validation: 'Validation Approval',
};

export const APPROVAL_TYPES = Object.keys(APPROVAL_TYPE_LABEL) as ApprovalType[];

export const APPROVAL_STATUS_LABEL: Record<ApprovalStatus, string> = {
  pending: 'Pending',
  approved: 'Approved',
  rejected: 'Rejected',
  revision_required: 'Revision Required',
};

export const APPROVAL_STATUS_TONE: Record<ApprovalStatus, Tone> = {
  pending: 'yellow',
  approved: 'green',
  rejected: 'red',
  revision_required: 'orange',
};

export const PURCHASING_STATUS_LABEL: Record<PurchasingStatus, string> = {
  requested: 'Requested',
  po_issued: 'PO Issued',
  in_transit: 'In Transit',
  received: 'Received',
  cancelled: 'Cancelled',
};

export const PURCHASING_STATUS_TONE: Record<PurchasingStatus, Tone> = {
  requested: 'yellow',
  po_issued: 'blue',
  in_transit: 'blue',
  received: 'green',
  cancelled: 'gray',
};

export const CALENDAR_CATEGORY_LABEL: Record<CalendarCategory, string> = {
  deadline: 'Deadline',
  customer_approval: 'Customer Approval',
  trial: 'Trial / T0',
  commissioning: 'Commissioning',
  material_arrival: 'Material Arrival',
  validation: 'Validation',
  meeting: 'Meeting',
  follow_up: 'Follow-up',
};

export const CALENDAR_CATEGORY_TONE: Record<CalendarCategory, Tone> = {
  deadline: 'red',
  customer_approval: 'yellow',
  trial: 'blue',
  commissioning: 'orange',
  material_arrival: 'green',
  validation: 'green',
  meeting: 'gray',
  follow_up: 'orange',
};

export const NOTIFICATION_LABEL: Record<NotificationType, string> = {
  project_assigned: 'Project baru',
  approval_requested: 'Approval diminta',
  approval_rejected: 'Approval ditolak',
  approval_approved: 'Approval disetujui',
  artwork_revision: 'Revisi artwork',
  document_uploaded: 'Dokumen baru',
  document_revision: 'Revisi dokumen',
  deadline_approaching: 'Deadline mendekat',
  project_overdue: 'Project overdue',
  next_action_due: 'Next action jatuh tempo',
  next_action_overdue: 'Next action terlambat',
  missing_document: 'Dokumen wajib belum ada',
};

export const NOTIFICATION_TONE: Record<NotificationType, Tone> = {
  project_assigned: 'blue',
  approval_requested: 'yellow',
  approval_rejected: 'red',
  approval_approved: 'green',
  artwork_revision: 'orange',
  document_uploaded: 'blue',
  document_revision: 'blue',
  deadline_approaching: 'yellow',
  project_overdue: 'red',
  next_action_due: 'yellow',
  next_action_overdue: 'red',
  missing_document: 'orange',
};

export const formatRevision = (rev: number) => `Rev ${String(rev).padStart(2, '0')}`;
