import { MessageSquare, Paperclip } from 'lucide-react';
import { useState } from 'react';
import { APPROVAL_STATUS_LABEL, APPROVAL_TYPE_LABEL, APPROVAL_TYPES } from '@/config/labels';
import { errorMessage, isAppError } from '@/domain/errors';
import { useAction } from '@/hooks/queries';
import { diffDays, formatDate } from '@/lib/date';
import { cn } from '@/lib/utils';
import { decideApproval, requestApproval } from '@/services/api/approvals';
import type { DocumentRow } from '@/services/api/documents';
import type { ApprovalView, ProjectDetail } from '@/services/api/projects';
import type { ApprovalStatus, ApprovalType, ApproverType } from '@/types';
import { ApprovalStatusChip } from '../project/Chips';
import { Button } from '../ui/Button';
import { EmptyState, InlineAlert } from '../ui/Feedback';
import { Field, Input, SegmentedControl, Select, Textarea } from '../ui/Form';
import { Modal } from '../ui/Overlay';
import { useToast } from '../ui/Toast';

export function waitingDays(a: ApprovalView, today: string): number {
  return diffDays(a.requestedDate, a.decisionDate ?? today);
}

/** Approval history list (every loop is kept). */
export function ApprovalHistory({
  approvals,
  today,
  onDecide,
  canDecide,
  onOpenAttachment,
}: {
  approvals: ApprovalView[];
  today: string;
  onDecide?: (a: ApprovalView) => void;
  canDecide?: (a: ApprovalView) => boolean;
  onOpenAttachment?: (versionId: string) => void;
}) {
  if (approvals.length === 0) return <EmptyState compact title="Belum ada approval" description="Approval record dibuat otomatis saat workflow masuk ke proses approval." />;
  return (
    <ol className="divide-y divide-line">
      {approvals.map((a) => (
        <li key={a.id} className="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-start">
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
              <span className="text-[14px] font-semibold text-ink">{APPROVAL_TYPE_LABEL[a.type]}</span>
              <span className="text-[13px] text-ink-2">· {a.revision}</span>
              <ApprovalStatusChip status={a.status} size="sm" />
            </div>
            <p className="mt-1 text-[12px] text-ink-3">
              {a.code} · {a.processName} · diajukan {formatDate(a.requestedDate)} oleh {a.requestedBy} · approver {a.approverName}
            </p>
            <p className="mt-0.5 text-[12px] text-ink-3">
              {a.status === 'pending'
                ? `Menunggu ${waitingDays(a, today)} hari`
                : `Diputuskan ${formatDate(a.decisionDate)}${a.decidedBy ? ` oleh ${a.decidedBy}` : ''} · waiting time ${waitingDays(a, today)} hari`}
            </p>
            {a.comment && (
              <p className="mt-2 flex items-start gap-1.5 rounded-xl bg-surface-2 px-3 py-2 text-[13px] text-ink">
                <MessageSquare className="mt-0.5 size-3.5 shrink-0 text-ink-3" aria-hidden="true" />
                {a.comment}
              </p>
            )}
            {a.attachmentVersionId && onOpenAttachment && (
              <button type="button" onClick={() => onOpenAttachment(a.attachmentVersionId!)} className="mt-2 inline-flex items-center gap-1 text-[12px] font-medium text-accent-ink hover:underline">
                <Paperclip className="size-3.5" /> Bukti approval
              </button>
            )}
          </div>
          {a.status === 'pending' && onDecide && canDecide?.(a) && (
            <Button size="sm" variant="primary" onClick={() => onDecide(a)} className="self-start">
              Putuskan
            </Button>
          )}
        </li>
      ))}
    </ol>
  );
}

/** Decision for manual (non-workflow) approvals. */
export function ManualDecisionDialog({ approval, onClose }: { approval: ApprovalView | null; onClose: () => void }) {
  const toast = useToast();
  const [decision, setDecision] = useState<Exclude<ApprovalStatus, 'pending'>>('approved');
  const [comment, setComment] = useState('');
  const mutation = useAction(() => decideApproval({ approvalId: approval!.id, decision, comment }));
  if (!approval) return null;
  const fe = isAppError(mutation.error) ? (mutation.error.fieldErrors ?? {}) : {};
  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      title={`Keputusan ${APPROVAL_TYPE_LABEL[approval.type]}`}
      description={`${approval.code} · ${approval.projectCode} · ${approval.revision}`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button
            variant="primary"
            loading={mutation.isPending}
            onClick={() =>
              mutation.mutate(undefined, {
                onSuccess: () => {
                  toast.success(`Approval ${APPROVAL_STATUS_LABEL[decision]}`, approval.revision);
                  onClose();
                },
              })
            }
          >
            Simpan Keputusan
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {mutation.error && !Object.keys(fe).length ? <InlineAlert tone="red">{errorMessage(mutation.error)}</InlineAlert> : null}
        <SegmentedControl
          label="Keputusan"
          value={decision}
          onChange={setDecision}
          options={[
            { value: 'approved', label: 'Approved' },
            { value: 'revision_required', label: 'Revision Required' },
            { value: 'rejected', label: 'Rejected' },
          ]}
        />
        <Field label="Komentar" htmlFor="dc" required={decision !== 'approved'} error={fe.comment}>
          <Textarea id="dc" value={comment} onChange={(e) => setComment(e.target.value)} invalid={!!fe.comment} />
        </Field>
      </div>
    </Modal>
  );
}

export function RequestApprovalDialog({ detail, open, onClose }: { detail: ProjectDetail; open: boolean; onClose: () => void }) {
  const toast = useToast();
  const p = detail.item.project;
  const [processId, setProcessId] = useState(p.currentProcessId ?? detail.processes[0]?.id ?? '');
  const [type, setType] = useState<ApprovalType>('2d');
  const [versionId, setVersionId] = useState('');
  const [revision, setRevision] = useState('');
  const [approverType, setApproverType] = useState<ApproverType>('customer');
  const [approverName, setApproverName] = useState('');
  const [comment, setComment] = useState('');
  const mutation = useAction(() =>
    requestApproval({ projectId: p.id, processId, type, documentVersionId: versionId || undefined, revision, approverType, approverName, comment }),
  );
  const fe = isAppError(mutation.error) ? (mutation.error.fieldErrors ?? {}) : {};
  const versions: Array<{ row: DocumentRow; id: string; label: string }> = detail.documents.flatMap((row) =>
    row.versions.filter((v) => v.status !== 'superseded').map((v) => ({ row, id: v.id, label: `${row.doc.name} — Rev ${String(v.revision).padStart(2, '0')} (${v.status})` })),
  );
  if (!open) return null;
  return (
    <Modal
      open
      onClose={onClose}
      size="md"
      title="Ajukan Approval"
      description="Untuk approval di luar workflow otomatis (mis. 2D Approval). Approval workflow dibuat otomatis."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button
            variant="primary"
            loading={mutation.isPending}
            onClick={() =>
              mutation.mutate(undefined, {
                onSuccess: (a) => {
                  toast.success('Approval diajukan', `${a.code} · ${APPROVAL_TYPE_LABEL[a.type]}`);
                  onClose();
                },
              })
            }
          >
            Ajukan
          </Button>
        </>
      }
    >
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {mutation.error && !Object.keys(fe).length ? (
          <div className="sm:col-span-2">
            <InlineAlert tone="red">{errorMessage(mutation.error)}</InlineAlert>
          </div>
        ) : null}
        <Field label="Proses" htmlFor="ap-proc">
          <Select id="ap-proc" value={processId} onChange={(e) => setProcessId(e.target.value)}>
            {detail.processes
              .filter((x) => x.status !== 'skipped')
              .map((x) => (
                <option key={x.id} value={x.id}>
                  {String(x.sequence).padStart(2, '0')} · {x.name}
                </option>
              ))}
          </Select>
        </Field>
        <Field label="Approval Type" htmlFor="ap-type" error={fe.type}>
          <Select id="ap-type" value={type} onChange={(e) => setType(e.target.value as ApprovalType)}>
            {APPROVAL_TYPES.map((t) => (
              <option key={t} value={t}>
                {APPROVAL_TYPE_LABEL[t]}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Dokumen / revisi yang diajukan" htmlFor="ap-doc" className="sm:col-span-2" error={fe.revision}>
          <Select id="ap-doc" value={versionId} onChange={(e) => setVersionId(e.target.value)}>
            <option value="">— Isi manual di bawah —</option>
            {versions.map((v) => (
              <option key={v.id} value={v.id}>
                {v.label}
              </option>
            ))}
          </Select>
        </Field>
        {!versionId && (
          <Field label="Revision (manual)" htmlFor="ap-rev" className="sm:col-span-2">
            <Input id="ap-rev" value={revision} onChange={(e) => setRevision(e.target.value)} placeholder="mis. 2D Drawing Rev 01" />
          </Field>
        )}
        <Field label="Approver" htmlFor="ap-appr">
          <Select id="ap-appr" value={approverType} onChange={(e) => setApproverType(e.target.value as ApproverType)}>
            <option value="customer">Customer ({detail.item.customer})</option>
            <option value="internal">Internal</option>
          </Select>
        </Field>
        {approverType === 'internal' && (
          <Field label="Nama approver internal" htmlFor="ap-name" required error={fe.approverName}>
            <Input id="ap-name" value={approverName} onChange={(e) => setApproverName(e.target.value)} />
          </Field>
        )}
        <Field label="Catatan" htmlFor="ap-c" className="sm:col-span-2">
          <Textarea id="ap-c" rows={2} value={comment} onChange={(e) => setComment(e.target.value)} />
        </Field>
      </div>
    </Modal>
  );
}

export const approvalRowClass = (pending: boolean) => cn(pending && 'bg-tone-yellow-soft/30');
