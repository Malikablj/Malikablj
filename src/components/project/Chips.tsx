import { CalendarClock } from 'lucide-react';
import {
  APPROVAL_STATUS_LABEL,
  APPROVAL_STATUS_TONE,
  DOC_STATUS_LABEL,
  DOC_STATUS_TONE,
  PRIORITY_LABEL,
  PRIORITY_TONE,
  PROCESS_STATUS_LABEL,
  PROCESS_STATUS_TONE,
  PROJECT_TYPE_LABEL,
  STATUS_LABEL,
  STATUS_TONE,
} from '@/config/labels';
import { describeDue, formatDate } from '@/lib/date';
import { cn } from '@/lib/utils';
import type { ApprovalStatus, DisplayStatus, DocumentStatus, Priority, ProcessStatus, ProjectType } from '@/types';
import { Badge, Tag } from '../ui/Badge';

export function StatusChip({ status, size }: { status: DisplayStatus; size?: 'sm' | 'md' }) {
  return (
    <Badge tone={STATUS_TONE[status]} dot size={size}>
      {STATUS_LABEL[status]}
    </Badge>
  );
}

export function PriorityChip({ priority, size }: { priority: Priority; size?: 'sm' | 'md' }) {
  return (
    <Badge tone={PRIORITY_TONE[priority]} size={size}>
      {PRIORITY_LABEL[priority]}
    </Badge>
  );
}

export function TypeTag({ type }: { type: ProjectType }) {
  return <Tag>{PROJECT_TYPE_LABEL[type]}</Tag>;
}

export function ProcessStatusChip({ status, size }: { status: ProcessStatus; size?: 'sm' | 'md' }) {
  return (
    <Badge tone={PROCESS_STATUS_TONE[status]} size={size}>
      {PROCESS_STATUS_LABEL[status]}
    </Badge>
  );
}

export function ApprovalStatusChip({ status, size }: { status: ApprovalStatus; size?: 'sm' | 'md' }) {
  return (
    <Badge tone={APPROVAL_STATUS_TONE[status]} dot size={size}>
      {APPROVAL_STATUS_LABEL[status]}
    </Badge>
  );
}

export function DocStatusChip({ status, size }: { status: DocumentStatus; size?: 'sm' | 'md' }) {
  return (
    <Badge tone={DOC_STATUS_TONE[status]} size={size}>
      {DOC_STATUS_LABEL[status]}
    </Badge>
  );
}

/** Project status plus the derived overdue days (text, not color only). */
export function ProjectStatusChips({ status, baseStatus, overdueDays, size }: { status: DisplayStatus; baseStatus: DisplayStatus; overdueDays: number; size?: 'sm' | 'md' }) {
  if (status !== 'overdue') return <StatusChip status={status} size={size} />;
  return (
    <span className="inline-flex flex-wrap items-center gap-1">
      <Badge tone="red" dot size={size}>
        Overdue {overdueDays} hari
      </Badge>
      <StatusChip status={baseStatus} size={size} />
    </span>
  );
}

export function DueText({ date, today, state, className }: { date: string; today: string; state: 'overdue' | 'due_soon' | 'ok'; className?: string }) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 text-[12px]',
        state === 'overdue' ? 'font-medium text-tone-red' : state === 'due_soon' ? 'font-medium text-tone-yellow' : 'text-ink-3',
        className,
      )}
    >
      <CalendarClock className="size-3.5 shrink-0" aria-hidden="true" />
      {formatDate(date)} · {describeDue(date, today)}
    </span>
  );
}
