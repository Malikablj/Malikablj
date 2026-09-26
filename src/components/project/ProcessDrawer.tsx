import { AlertTriangle, CheckCircle2, Upload } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { APPROVAL_TYPE_LABEL, DOC_TYPE_LABEL, formatRevision, ROLE_LABEL } from '@/config/labels';
import { errorMessage, isAppError } from '@/domain/errors';
import { isRunning } from '@/domain/metrics';
import { canActOnProcess, canPlanProcess, canUploadToProcess } from '@/domain/permissions';
import { useAction, useLookups } from '@/hooks/queries';
import { useUser } from '@/hooks/useAuth';
import { formatDate, formatDateTime } from '@/lib/date';
import type { DocumentRow } from '@/services/api/documents';
import { type ProcessView, type ProjectDetail, updateProcess } from '@/services/api/projects';
import { ApprovalHistory } from '../approvals/ApprovalComponents';
import { DocumentRowItem } from '../documents/DocumentList';
import { Button } from '../ui/Button';
import { InfoItem } from '../ui/Card';
import { InlineAlert } from '../ui/Feedback';
import { Field, Input, Select, Textarea } from '../ui/Form';
import { Sheet } from '../ui/Overlay';
import { Tabs } from '../ui/Tabs';
import { useToast } from '../ui/Toast';
import { ProcessStatusChip } from './Chips';
import { DataList } from './DynamicForm';

type Tab = 'detail' | 'data' | 'documents' | 'history';

/** Process detail drawer: planning, data, documents, approvals and history of one process. */
export function ProcessDrawer({
  detail,
  processId,
  onClose,
  onComplete,
  onUpload,
  onPreview,
  today,
}: {
  detail: ProjectDetail;
  processId: string | null;
  onClose: () => void;
  onComplete: (p: ProcessView) => void;
  onUpload: (processId: string, documentId?: string) => void;
  onPreview: (row: DocumentRow) => void;
  today: string;
}) {
  const proc = detail.processes.find((p) => p.id === processId);
  if (!proc) return null;
  return <Inner key={proc.id} detail={detail} proc={proc} onClose={onClose} onComplete={onComplete} onUpload={onUpload} onPreview={onPreview} today={today} />;
}

function Inner({
  detail,
  proc,
  onClose,
  onComplete,
  onUpload,
  onPreview,
  today,
}: {
  detail: ProjectDetail;
  proc: ProcessView;
  onClose: () => void;
  onComplete: (p: ProcessView) => void;
  onUpload: (processId: string, documentId?: string) => void;
  onPreview: (row: DocumentRow) => void;
  today: string;
}) {
  const user = useUser();
  const toast = useToast();
  const lookups = useLookups();
  const project = detail.item.project;
  const [tab, setTab] = useState<Tab>('detail');
  const canPlan = canPlanProcess(user) && project.status !== 'cancelled';
  const canAct = canActOnProcess(user, project, proc);
  const canUpload = canUploadToProcess(user, project, proc);
  const running = isRunning(proc) && project.currentProcessId === proc.id;

  const [plan, setPlan] = useState({ picId: proc.picId ?? '', plannedStart: proc.plannedStart, plannedFinish: proc.plannedFinish, remarks: proc.remarks ?? '' });
  useEffect(() => {
    setPlan({ picId: proc.picId ?? '', plannedStart: proc.plannedStart, plannedFinish: proc.plannedFinish, remarks: proc.remarks ?? '' });
  }, [proc.picId, proc.plannedStart, proc.plannedFinish, proc.remarks]);
  const save = useAction(() =>
    updateProcess(proc.id, canPlan ? { picId: plan.picId || undefined, plannedStart: plan.plannedStart, plannedFinish: plan.plannedFinish, remarks: plan.remarks } : { remarks: plan.remarks }),
  );
  const fe = isAppError(save.error) ? (save.error.fieldErrors ?? {}) : {};
  const dirty =
    plan.picId !== (proc.picId ?? '') || plan.plannedStart !== proc.plannedStart || plan.plannedFinish !== proc.plannedFinish || plan.remarks !== (proc.remarks ?? '');

  const docs = detail.documents.filter((d) => d.processId === proc.id);
  const approvals = detail.approvals.filter((a) => a.processId === proc.id);
  const records = detail.records.filter((r) => r.processId === proc.id);
  const history = detail.timeline.filter((t) => t.processName === proc.name);
  const docLabels = useMemo(() => {
    const m = new Map<string, string>();
    for (const d of detail.documents) for (const v of d.versions) m.set(v.id, `${d.doc.name} — ${formatRevision(v.revision)}`);
    return m;
  }, [detail.documents]);
  const users = lookups.data?.users ?? [];

  return (
    <Sheet
      open
      onClose={onClose}
      size="lg"
      title={
        <span className="flex flex-wrap items-center gap-2">
          <span className="tabular text-ink-3">{String(proc.sequence).padStart(2, '0')}</span> {proc.name}
        </span>
      }
      description={`${project.code} · ${proc.folder}`}
      footer={
        running && canAct ? (
          <>
            {canUpload && (
              <Button icon={<Upload className="size-4" />} onClick={() => onUpload(proc.id)}>
                Upload
              </Button>
            )}
            <Button variant="primary" onClick={() => onComplete(proc)} disabled={project.status === 'hold'}>
              {proc.kind === 'decision' ? 'Catat Keputusan' : proc.kind === 'finish' ? 'Selesaikan Project' : 'Selesaikan Proses'}
            </Button>
          </>
        ) : undefined
      }
    >
      <div className="px-5 pt-4">
        <div className="flex flex-wrap items-center gap-2">
          <ProcessStatusChip status={proc.status} />
          {proc.isMandatory ? <span className="text-[12px] text-ink-3">Mandatory</span> : <span className="text-[12px] text-ink-3">Opsional</span>}
          {proc.loopCount > 0 && <span className="text-[12px] text-tone-orange">Loop {proc.loopCount}×</span>}
          {proc.requiresApproval && proc.approvalType && <span className="text-[12px] text-ink-3">· {APPROVAL_TYPE_LABEL[proc.approvalType]}</span>}
        </div>
        <p className="mt-2 text-[14px] text-ink-2">{proc.description}</p>
        {proc.status === 'problem' && proc.problemNote && (
          <div className="mt-3">
            <InlineAlert tone="red" title="Problem">
              {proc.problemNote}
            </InlineAlert>
          </div>
        )}
        {proc.requiresDocument && (
          <p className={`mt-3 flex items-center gap-1.5 text-[13px] ${proc.hasRequiredDoc ? 'text-tone-green' : 'text-tone-orange'}`}>
            {proc.hasRequiredDoc ? <CheckCircle2 className="size-4" /> : <AlertTriangle className="size-4" />}
            Dokumen wajib: {proc.requiredDocTypes.map((t) => DOC_TYPE_LABEL[t]).join(' / ')} {proc.hasRequiredDoc ? 'tersedia' : 'belum ada'}
          </p>
        )}
      </div>
      <Tabs
        className="mt-3 px-3"
        label="Detail proses"
        value={tab}
        onChange={setTab}
        items={[
          { value: 'detail', label: 'Detail' },
          { value: 'data', label: 'Data', count: records.length || undefined },
          { value: 'documents', label: 'Dokumen', count: docs.length },
          { value: 'history', label: 'History', count: approvals.length + history.length },
        ]}
      />
      <div className="px-5 py-5">
        {tab === 'detail' && (
          <div className="space-y-6">
            <dl className="grid grid-cols-2 gap-x-6 gap-y-4">
              <InfoItem label="PIC">
                {proc.picName} <span className="text-ink-3">· {ROLE_LABEL[proc.picRole]}</span>
              </InfoItem>
              <InfoItem label="Waiting For">{running ? (project.waitingFor ?? '—') : (proc.waitingFor ?? '—')}</InfoItem>
              <InfoItem label="Planned">
                {formatDate(proc.plannedStart)} – {formatDate(proc.plannedFinish)}
              </InfoItem>
              <InfoItem label="Actual">
                {proc.actualStart ? formatDate(proc.actualStart) : 'Belum mulai'} – {proc.actualFinish ? formatDate(proc.actualFinish) : proc.actualStart ? 'berjalan' : '—'}
              </InfoItem>
              <InfoItem label="Durasi">{proc.duration !== null ? `${proc.duration} hari` : '—'}</InfoItem>
              <InfoItem label="Iterasi">{proc.iteration || '—'}</InfoItem>
              <InfoItem label="Next Action" className="col-span-2">
                {running ? project.nextAction : (proc.nextAction ?? '—')}
                {running && <span className="text-ink-3"> · due {formatDate(project.nextActionDue)}</span>}
              </InfoItem>
            </dl>

            {(canPlan || canAct) && project.status !== 'completed' && (
              <section className="rounded-2xl border border-line p-4">
                <h3 className="text-[14px] font-semibold text-ink">{canPlan ? 'Planning & PIC' : 'Remarks'}</h3>
                {save.error && !Object.keys(fe).length ? (
                  <div className="mt-3">
                    <InlineAlert tone="red">{errorMessage(save.error)}</InlineAlert>
                  </div>
                ) : null}
                <div className="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-2">
                  {canPlan && (
                    <>
                      <Field label="PIC" htmlFor="pd-pic" error={fe.picId} className="sm:col-span-2">
                        <Select id="pd-pic" value={plan.picId} onChange={(e) => setPlan((s) => ({ ...s, picId: e.target.value }))}>
                          {users
                            .filter((u) => u.active && (proc.actorRoles.includes(u.role) || u.role === proc.picRole || u.role === 'admin' || u.id === proc.picId))
                            .map((u) => (
                              <option key={u.id} value={u.id}>
                                {u.name} · {ROLE_LABEL[u.role]}
                              </option>
                            ))}
                        </Select>
                      </Field>
                      <Field label="Planned Start" htmlFor="pd-ps" error={fe.plannedStart}>
                        <Input id="pd-ps" type="date" value={plan.plannedStart} onChange={(e) => setPlan((s) => ({ ...s, plannedStart: e.target.value }))} />
                      </Field>
                      <Field label="Planned Finish" htmlFor="pd-pf" error={fe.plannedFinish}>
                        <Input id="pd-pf" type="date" value={plan.plannedFinish} min={plan.plannedStart} onChange={(e) => setPlan((s) => ({ ...s, plannedFinish: e.target.value }))} invalid={!!fe.plannedFinish} />
                      </Field>
                    </>
                  )}
                  <Field label="Remarks" htmlFor="pd-rm" className="sm:col-span-2">
                    <Textarea id="pd-rm" rows={2} value={plan.remarks} onChange={(e) => setPlan((s) => ({ ...s, remarks: e.target.value }))} />
                  </Field>
                </div>
                <div className="mt-3 flex justify-end">
                  <Button
                    size="sm"
                    variant="primary"
                    disabled={!dirty}
                    loading={save.isPending}
                    onClick={() => save.mutate(undefined, { onSuccess: () => toast.success('Proses diperbarui', 'Perubahan dicatat di Activity History.') })}
                  >
                    Simpan
                  </Button>
                </div>
              </section>
            )}
          </div>
        )}

        {tab === 'data' && (
          <div className="space-y-5">
            {proc.fields.length > 0 && (
              <section>
                <h3 className="mb-3 text-[13px] font-semibold text-ink">{running ? 'Draft / data saat ini' : 'Data terakhir'}</h3>
                <DataList fields={proc.fields} data={proc.data} users={users} docLabels={docLabels} />
              </section>
            )}
            {records.length > 0 && (
              <section>
                <h3 className="mb-2 text-[13px] font-semibold text-ink">Record per iterasi</h3>
                <ol className="space-y-2">
                  {records.map((r) => (
                    <li key={r.id} className="rounded-xl border border-line p-3">
                      <p className="text-[13px] font-semibold text-ink">
                        {r.number} · iterasi {r.iteration}
                        {r.outcomeLabel && <span className="font-normal text-ink-2"> · {r.outcomeLabel}</span>}
                      </p>
                      <p className="text-[12px] text-ink-3">
                        {r.createdBy} · {formatDateTime(r.createdAt)}
                      </p>
                      {r.comment && <p className="mt-1 text-[13px] text-ink-2">{r.comment}</p>}
                    </li>
                  ))}
                </ol>
              </section>
            )}
            {proc.fields.length === 0 && records.length === 0 && <p className="text-[13px] text-ink-3">Proses ini tidak memiliki form data.</p>}
          </div>
        )}

        {tab === 'documents' && (
          <div className="-mx-5">
            {canUpload && (
              <div className="px-5 pb-3">
                <Button size="sm" icon={<Upload className="size-4" />} onClick={() => onUpload(proc.id)}>
                  Upload dokumen ke proses ini
                </Button>
              </div>
            )}
            {docs.length === 0 ? (
              <p className="px-5 text-[13px] text-ink-3">Belum ada dokumen pada proses ini.</p>
            ) : (
              <ul className="divide-y divide-line border-y border-line">
                {docs.map((r) => (
                  <DocumentRowItem key={r.doc.id} row={r} onPreview={onPreview} onRevise={canUpload ? (row) => onUpload(proc.id, row.doc.id) : undefined} />
                ))}
              </ul>
            )}
          </div>
        )}

        {tab === 'history' && (
          <div className="-mx-5 space-y-4">
            {approvals.length > 0 && (
              <section>
                <h3 className="px-5 text-[13px] font-semibold text-ink">Approval History</h3>
                <ApprovalHistory approvals={approvals} today={today} />
              </section>
            )}
            <section>
              <h3 className="px-5 text-[13px] font-semibold text-ink">Activity</h3>
              {history.length === 0 ? (
                <p className="px-5 py-2 text-[13px] text-ink-3">Belum ada aktivitas.</p>
              ) : (
                <ol className="mt-2 space-y-3 px-5">
                  {history.map((h) => (
                    <li key={h.id} className="text-[13px]">
                      <p className="text-ink">{h.message}</p>
                      {h.detail && <p className="whitespace-pre-line text-ink-2">{h.detail}</p>}
                      <p className="text-[12px] text-ink-3">
                        {h.user} · {formatDateTime(h.at)}
                      </p>
                    </li>
                  ))}
                </ol>
              )}
            </section>
          </div>
        )}
      </div>
    </Sheet>
  );
}
