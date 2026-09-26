import { ArrowRight, CheckCircle2, Circle, Paperclip, Upload, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { APPROVAL_TYPE_LABEL, DOC_TYPE_LABEL } from '@/config/labels';
import { errorMessage, isAppError } from '@/domain/errors';
import { missingRequiredFields } from '@/domain/workflow';
import { useAction, useLookups, useProcessForm, useProjectDetail } from '@/hooks/queries';
import { formatDate } from '@/lib/date';
import { cn, formatBytes } from '@/lib/utils';
import { uploadDocument } from '@/services/api/documents';
import { completeProcess, finishProject, type ProcessView, updateProcess } from '@/services/api/projects';
import type { ProcessOutcome } from '@/types';
import { UploadDocumentDialog, type UploadTarget } from '../documents/UploadDocumentDialog';
import { Button } from '../ui/Button';
import { InlineAlert, Skeleton } from '../ui/Feedback';
import { Field, Select, Textarea } from '../ui/Form';
import { Modal } from '../ui/Overlay';
import { useToast } from '../ui/Toast';
import { DynamicForm } from './DynamicForm';

function outcomeTargetLabel(o: ProcessOutcome, processes: ProcessView[], current: ProcessView, targetKey?: string): string {
  const name = (key: string) => processes.find((p) => p.key === key)?.name ?? key;
  switch (o.target.kind) {
    case 'next': {
      const next = current.nextKey
        ? processes.find((p) => p.key === current.nextKey)
        : processes.find((p) => p.sequence > current.sequence && !p.loopOnly && p.status !== 'skipped');
      return next ? `Lanjut ke ${next.name}` : 'Lanjut';
    }
    case 'process': {
      const key = targetKey ?? o.target.key;
      const target = processes.find((p) => p.key === key);
      return target && target.sequence < current.sequence ? `Kembali ke ${name(key)} (revision loop)` : `Lanjut ke ${name(key)}`;
    }
    case 'repeat':
      return `Ulangi ${current.name} (status Problem)`;
    case 'cancel':
      return 'Project menjadi Cancelled';
  }
}

const TONE_RING: Record<ProcessOutcome['tone'], string> = {
  positive: 'border-mark-green ring-4 ring-tone-green-soft',
  negative: 'border-mark-red ring-4 ring-tone-red-soft',
  neutral: 'border-mark-orange ring-4 ring-tone-orange-soft',
};

/**
 * Completes the current process: data form, required documents, decision
 * outcome (for approval/decision steps) and comment. The Finish step shows
 * the mandatory-process checklist.
 */
export function CompleteProcessDialog({ projectCode, processId, onClose, initialOutcome }: { projectCode: string; processId: string | null; onClose: () => void; initialOutcome?: string }) {
  if (!processId) return null;
  return <Inner projectCode={projectCode} processId={processId} onClose={onClose} initialOutcome={initialOutcome} />;
}

function Inner({ projectCode, processId, onClose, initialOutcome }: { projectCode: string; processId: string; onClose: () => void; initialOutcome?: string }) {
  const toast = useToast();
  const detail = useProjectDetail(projectCode);
  const form = useProcessForm(processId);
  const lookups = useLookups();
  const [values, setValues] = useState<Record<string, unknown> | null>(null);
  const [outcome, setOutcome] = useState<string | undefined>(initialOutcome);
  const [targetKey, setTargetKey] = useState<string | undefined>();
  const [comment, setComment] = useState('');
  const [evidence, setEvidence] = useState<File | null>(null);
  const [submitted, setSubmitted] = useState(false);
  const [upload, setUpload] = useState<UploadTarget | null>(null);
  const complete = useAction(completeProcess);
  const saveDraft = useAction(({ id, data }: { id: string; data: Record<string, unknown> }) => updateProcess(id, { data }));
  const finish = useAction(({ id, note }: { id: string; note?: string }) => finishProject(id, note));
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (form.data && values === null) setValues(form.data.data);
  }, [form.data, values]);

  const proc = detail.data?.processes.find((p) => p.id === processId);
  const project = detail.data?.item.project;
  const chosen = proc?.outcomes?.find((o) => o.key === outcome);
  const serverFe = isAppError(complete.error) ? complete.error.fieldErrors ?? {} : {};
  const clientFe = useMemo(() => (proc && values ? missingRequiredFields(proc.fields, values) : {}), [proc, values]);
  const errors = { ...(submitted ? clientFe : {}), ...serverFe };
  if (submitted && proc?.kind === 'decision' && !outcome) errors.outcome = 'Pilih hasil keputusan.';
  if (submitted && chosen?.requiresComment && !comment.trim()) errors.comment = 'Komentar wajib diisi untuk keputusan ini.';

  const loading = detail.isLoading || form.isLoading || lookups.isLoading || values === null;
  const requiredDocs = proc?.requiredDocTypes ?? [];
  const docsOk = proc?.hasRequiredDoc ?? true;
  const isFinish = proc?.kind === 'finish';
  const missingMandatory = detail.data?.unfinishedMandatory ?? [];

  const doSubmit = async () => {
    if (!proc || !project || !values) return;
    setSubmitted(true);
    if (isFinish) {
      finish.mutate(
        { id: project.id, note: comment.trim() || undefined },
        {
          onSuccess: () => {
            toast.success('Project selesai', `${project.code} berstatus Completed.`);
            onClose();
          },
          onError: (e) => toast.fromError(e, 'Project belum dapat diselesaikan'),
        },
      );
      return;
    }
    const localErrors = { ...clientFe };
    if (proc.kind === 'decision' && !outcome) localErrors.outcome = 'x';
    if (chosen?.requiresComment && !comment.trim()) localErrors.comment = 'x';
    if (Object.keys(localErrors).length) {
      toast.error('Lengkapi data wajib', 'Periksa kolom yang ditandai merah.');
      return;
    }
    setBusy(true);
    try {
      let attachmentVersionId: string | undefined;
      if (evidence && proc.pendingApproval) {
        const res = await uploadDocument({
          projectId: project.id,
          processId: proc.id,
          mode: 'new',
          type: 'approval',
          name: `Bukti ${proc.pendingApproval.code}`,
          description: `Bukti ${APPROVAL_TYPE_LABEL[proc.pendingApproval.type]}`,
          file: evidence,
        });
        attachmentVersionId = res.version.id;
      }
      await complete.mutateAsync({ processId: proc.id, outcomeKey: outcome, targetKey, data: values, comment: comment.trim() || undefined, attachmentVersionId });
      toast.success(chosen ? `${proc.name}: ${chosen.label}` : `${proc.name} selesai`, chosen ? outcomeTargetLabel(chosen, detail.data!.processes, proc, targetKey) : 'Workflow lanjut ke proses berikutnya.');
      onClose();
    } catch (e) {
      toast.fromError(e, 'Proses gagal disimpan');
    } finally {
      setBusy(false);
    }
  };

  const onSaveDraft = () => {
    if (!proc || !values) return;
    saveDraft.mutate(
      { id: proc.id, data: values },
      { onSuccess: () => toast.success('Draft tersimpan'), onError: (e) => toast.fromError(e, 'Draft gagal disimpan') },
    );
  };

  const alternatives = chosen?.target.kind === 'process' ? [chosen.target.key, ...(chosen.target.alternatives ?? [])] : [];

  return (
    <>
      <Modal
        open
        onClose={onClose}
        size="lg"
        title={isFinish ? 'Selesaikan Project' : proc?.kind === 'decision' ? `Keputusan: ${proc.name}` : `Selesaikan ${proc?.name ?? 'proses'}`}
        description={project ? `${project.code} · ${project.name}` : undefined}
        footer={
          <>
            {!isFinish && proc && proc.fields.length > 0 && (
              <Button variant="ghost" onClick={onSaveDraft} loading={saveDraft.isPending} disabled={busy} className="mr-auto">
                Simpan draft
              </Button>
            )}
            <Button variant="secondary" onClick={onClose} disabled={busy || finish.isPending}>
              Batal
            </Button>
            <Button variant="primary" onClick={doSubmit} loading={busy || finish.isPending} disabled={loading || (isFinish && missingMandatory.length > 0)}>
              {isFinish ? 'Selesaikan Project' : proc?.kind === 'decision' ? 'Simpan Keputusan' : 'Selesaikan Proses'}
            </Button>
          </>
        }
      >
        {loading || !proc || !values ? (
          <div className="space-y-3">
            <Skeleton className="h-16" />
            <Skeleton className="h-10" />
            <Skeleton className="h-10" />
          </div>
        ) : (
          <div className="space-y-6">
            {complete.error && !Object.keys(serverFe).length ? <InlineAlert tone="red">{errorMessage(complete.error)}</InlineAlert> : null}

            {isFinish ? (
              <section>
                <p className="text-[14px] text-ink-2">Project hanya dapat menjadi Completed jika seluruh mandatory process selesai.</p>
                <ul className="mt-3 space-y-1.5">
                  {detail.data!.processes
                    .filter((p) => p.kind !== 'finish' && !(p.loopOnly && p.status !== 'completed'))
                    .map((p) => {
                      const ok = p.status === 'completed' || p.status === 'skipped';
                      return (
                        <li key={p.id} className="flex items-center gap-2.5 text-[14px]">
                          {ok ? <CheckCircle2 className="size-4 text-tone-green" aria-hidden="true" /> : <Circle className="size-4 text-tone-red" aria-hidden="true" />}
                          <span className={ok ? 'text-ink' : 'font-medium text-tone-red'}>
                            {String(p.sequence).padStart(2, '0')} · {p.name}
                          </span>
                          <span className="text-[12px] text-ink-3">{p.status === 'skipped' ? 'tidak dijalankan' : ok ? 'selesai' : p.isMandatory ? 'belum selesai (mandatory)' : 'opsional'}</span>
                        </li>
                      );
                    })}
                </ul>
                {missingMandatory.length > 0 && (
                  <div className="mt-4">
                    <InlineAlert tone="red" title="Project belum dapat diselesaikan">
                      {missingMandatory.length} mandatory process belum selesai: {missingMandatory.join(', ')}.
                    </InlineAlert>
                  </div>
                )}
                <Field label="Catatan penutupan" htmlFor="closing" helper="Opsional" className="mt-4">
                  <Textarea id="closing" value={comment} onChange={(e) => setComment(e.target.value)} />
                </Field>
              </section>
            ) : (
              <>
                <p className="text-[14px] text-ink-2">{proc.description}</p>

                {proc.pendingApproval && (
                  <div className="rounded-2xl bg-surface-2 p-4">
                    <p className="text-[12px] text-ink-3">Approval record · {proc.pendingApproval.code}</p>
                    <p className="mt-0.5 text-[15px] font-semibold text-ink">
                      {APPROVAL_TYPE_LABEL[proc.pendingApproval.type]} — {proc.pendingApproval.revision}
                    </p>
                    <p className="mt-0.5 text-[13px] text-ink-2">
                      Approver: {proc.pendingApproval.approverName} · diajukan {formatDate(proc.pendingApproval.requestedDate)}
                    </p>
                  </div>
                )}

                {proc.requiresDocument && (
                  <section aria-label="Dokumen wajib" className={cn('flex items-center justify-between gap-3 rounded-2xl border p-4', docsOk ? 'border-line' : 'border-tone-orange bg-tone-orange-soft/40')}>
                    <div className="flex items-start gap-3">
                      {docsOk ? <CheckCircle2 className="mt-0.5 size-5 text-tone-green" aria-hidden="true" /> : <Circle className="mt-0.5 size-5 text-tone-orange" aria-hidden="true" />}
                      <div>
                        <p className="text-[14px] font-medium text-ink">Dokumen wajib: {requiredDocs.map((t) => DOC_TYPE_LABEL[t]).join(' / ') || 'dokumen proses'}</p>
                        <p className="text-[13px] text-ink-2">{docsOk ? 'Sudah tersedia di proses ini.' : 'Belum ada dokumen valid (bukan Rejected) di proses ini.'}</p>
                      </div>
                    </div>
                    <Button size="sm" icon={<Upload className="size-4" />} onClick={() => setUpload({ projectCode, processId: proc.id, defaultType: requiredDocs[0] })}>
                      Upload
                    </Button>
                  </section>
                )}

                {proc.fields.length > 0 && (
                  <section aria-label="Data proses">
                    <h3 className="mb-3 text-[14px] font-semibold text-ink">Data {proc.shortName}</h3>
                    <DynamicForm
                      fields={proc.fields}
                      values={values}
                      onChange={(k, v) => setValues((s) => ({ ...s, [k]: v }))}
                      errors={errors}
                      users={lookups.data?.users ?? []}
                      docOptions={form.data?.docRevisionOptions ?? {}}
                    />
                  </section>
                )}

                {proc.kind === 'decision' && proc.outcomes && (
                  <section aria-label="Keputusan">
                    <h3 className="mb-3 text-[14px] font-semibold text-ink">
                      {proc.approverType === 'customer' ? 'Keputusan Customer (via Sales)' : 'Hasil / Keputusan'}
                      <span className="text-tone-red"> *</span>
                    </h3>
                    <div role="radiogroup" aria-label="Hasil keputusan" className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                      {proc.outcomes.map((o) => {
                        const on = outcome === o.key;
                        return (
                          <button
                            key={o.key}
                            type="button"
                            role="radio"
                            aria-checked={on}
                            onClick={() => {
                              setOutcome(o.key);
                              setTargetKey(undefined);
                            }}
                            className={cn('rounded-2xl border p-3.5 text-left transition-[border-color,box-shadow]', on ? TONE_RING[o.tone] : 'border-line-strong hover:border-ink-3')}
                          >
                            <span className="block text-[14px] font-semibold text-ink">{o.label}</span>
                            <span className="mt-0.5 flex items-center gap-1 text-[12px] text-ink-2">
                              <ArrowRight className="size-3.5 shrink-0" aria-hidden="true" />
                              {outcomeTargetLabel(o, detail.data!.processes, proc)}
                            </span>
                          </button>
                        );
                      })}
                    </div>
                    {errors.outcome && <p className="mt-1.5 text-[12px] text-tone-red">{errors.outcome}</p>}
                    {alternatives.length > 1 && (
                      <Field label="Kembali ke proses" htmlFor="target" className="mt-4" helper="Trial revision loop mengikuti workflow.">
                        <Select id="target" value={targetKey ?? alternatives[0]} onChange={(e) => setTargetKey(e.target.value)}>
                          {alternatives.map((k) => (
                            <option key={k} value={k}>
                              {detail.data!.processes.find((p) => p.key === k)?.name ?? k}
                            </option>
                          ))}
                        </Select>
                      </Field>
                    )}
                  </section>
                )}

                <Field
                  label={proc.kind === 'decision' ? 'Komentar / feedback' : 'Catatan'}
                  htmlFor="comment"
                  required={chosen?.requiresComment}
                  error={errors.comment}
                  helper={proc.kind === 'decision' ? 'Dicatat di Approval History & Activity History.' : 'Opsional — dicatat di Activity History.'}
                >
                  <Textarea id="comment" value={comment} onChange={(e) => setComment(e.target.value)} invalid={!!errors.comment} placeholder={chosen?.tone === 'negative' ? 'mis. Warna terlalu gelap, minta lebih soft' : undefined} />
                </Field>

                {proc.pendingApproval && (
                  <Field label="Bukti approval" htmlFor="evidence" helper="Opsional — email/screenshot/PDF persetujuan customer. Disimpan sebagai dokumen Approval.">
                    {evidence ? (
                      <div className="flex items-center gap-2 rounded-xl border border-line px-3 py-2">
                        <Paperclip className="size-4 text-ink-3" aria-hidden="true" />
                        <span className="min-w-0 flex-1 truncate text-[13px]">{evidence.name}</span>
                        <span className="text-[12px] text-ink-3">{formatBytes(evidence.size)}</span>
                        <button type="button" aria-label="Hapus lampiran" onClick={() => setEvidence(null)} className="rounded-full p-1 text-ink-3 hover:bg-surface-2">
                          <X className="size-4" />
                        </button>
                      </div>
                    ) : (
                      <input
                        id="evidence"
                        type="file"
                        onChange={(e) => {
                          const f = e.target.files?.[0];
                          if (f && f.size > 25 * 1024 * 1024) toast.error('File terlalu besar', 'Maksimal 25 MB.');
                          else setEvidence(f ?? null);
                        }}
                        className="block w-full text-[13px] text-ink-2 file:mr-3 file:h-9 file:rounded-lg file:border file:border-line-strong file:bg-surface file:px-3 file:text-[13px] file:font-medium file:text-ink"
                      />
                    )}
                  </Field>
                )}
              </>
            )}
          </div>
        )}
      </Modal>
      <UploadDocumentDialog target={upload} onClose={() => setUpload(null)} />
    </>
  );
}
