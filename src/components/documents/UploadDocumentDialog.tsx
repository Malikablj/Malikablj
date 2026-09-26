import { FileUp, UploadCloud, X } from 'lucide-react';
import { type DragEvent, useEffect, useMemo, useRef, useState } from 'react';
import { DOC_TYPE_LABEL, DOC_TYPES, formatRevision } from '@/config/labels';
import { MAX_FILE_SIZE, type UploadMode } from '@/domain/documentCommands';
import { errorMessage, isAppError } from '@/domain/errors';
import { canUploadToProcess } from '@/domain/permissions';
import { useAction, useProjectDetail } from '@/hooks/queries';
import { useUser } from '@/hooks/useAuth';
import { cn, formatBytes } from '@/lib/utils';
import { ACCEPTED_EXTENSIONS, uploadDocument } from '@/services/api/documents';
import type { DocType, DocumentVersion } from '@/types';
import { Button } from '../ui/Button';
import { InlineAlert, Skeleton } from '../ui/Feedback';
import { Field, Input, SegmentedControl, Select, Textarea } from '../ui/Form';
import { Modal } from '../ui/Overlay';
import { useToast } from '../ui/Toast';

export interface UploadTarget {
  projectCode: string;
  processId?: string;
  documentId?: string;
  mode?: UploadMode;
  defaultType?: DocType;
  title?: string;
}

export function UploadDocumentDialog({ target, onClose, onUploaded }: { target: UploadTarget | null; onClose: () => void; onUploaded?: (v: DocumentVersion) => void }) {
  return target ? <UploadDialogInner target={target} onClose={onClose} onUploaded={onUploaded} /> : null;
}

function UploadDialogInner({ target, onClose, onUploaded }: { target: UploadTarget; onClose: () => void; onUploaded?: (v: DocumentVersion) => void }) {
  const user = useUser();
  const toast = useToast();
  const { data, isLoading } = useProjectDetail(target.projectCode);
  const [processId, setProcessId] = useState(target.processId ?? '');
  const [mode, setMode] = useState<UploadMode>(target.mode ?? (target.documentId ? 'revision' : 'new'));
  const [documentId, setDocumentId] = useState(target.documentId ?? '');
  const [type, setType] = useState<DocType | ''>(target.defaultType ?? '');
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [note, setNote] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [drag, setDrag] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const upload = useAction(uploadDocument);

  const project = data?.item.project;
  const uploadable = useMemo(
    () => (data && project ? data.processes.filter((p) => p.status !== 'skipped' && canUploadToProcess(user, project, p)) : []),
    [data, project, user],
  );
  const proc = uploadable.find((p) => p.id === processId);
  const processDocs = data?.documents.filter((d) => d.processId === processId) ?? [];
  const existing = processDocs.find((d) => d.doc.id === documentId);

  useEffect(() => {
    if (!processId && uploadable.length) {
      const current = uploadable.find((p) => p.id === project?.currentProcessId);
      setProcessId((current ?? uploadable[0]).id);
    }
  }, [uploadable, processId, project]);

  useEffect(() => {
    if (proc && !type && !target.defaultType) setType(proc.suggestedDocTypes[0] ?? 'other');
  }, [proc, type, target.defaultType]);

  useEffect(() => {
    if (mode !== 'new' && !documentId && processDocs[0]) setDocumentId(processDocs[0].doc.id);
  }, [mode, documentId, processDocs]);

  const fe = isAppError(upload.error) ? upload.error.fieldErrors ?? {} : {};
  const clientErrors: Record<string, string> = {};
  if (!file) clientErrors.file = 'Pilih file untuk diupload.';
  else if (file.size > MAX_FILE_SIZE) clientErrors.file = `Ukuran file ${formatBytes(file.size)} melebihi batas 25 MB.`;
  else if (file.size === 0) clientErrors.file = 'File kosong tidak dapat diupload.';
  if (!processId) clientErrors.processId = 'Pilih proses.';
  if (mode === 'new' && !name.trim()) clientErrors.name = 'Nama dokumen wajib diisi.';
  if (mode === 'new' && !type) clientErrors.type = 'Pilih Document Type.';
  if (mode !== 'new' && !documentId) clientErrors.documentId = 'Pilih dokumen.';
  const err = (k: string) => fe[k] || (submitted ? clientErrors[k] : undefined);

  const pick = (f: File | undefined | null) => {
    if (!f) return;
    setFile(f);
    if (mode === 'new' && !name) setName(f.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' '));
  };
  const onDrop = (e: DragEvent) => {
    e.preventDefault();
    setDrag(false);
    pick(e.dataTransfer.files?.[0]);
  };

  const submit = () => {
    setSubmitted(true);
    if (Object.keys(clientErrors).length || !project) return;
    upload.mutate(
      {
        projectId: project.id,
        processId,
        mode,
        documentId: mode === 'new' ? undefined : documentId,
        type: (mode === 'new' ? type : existing?.doc.type) as DocType,
        name: mode === 'new' ? name : (existing?.doc.name ?? ''),
        description,
        note,
        file,
      },
      {
        onSuccess: (res) => {
          toast.success(mode === 'new' ? 'Dokumen diupload' : 'Revisi dokumen tersimpan', `${res.document.name} — ${formatRevision(res.version.revision)}${res.version.version > 1 ? ` v${res.version.version}` : ''}`);
          onUploaded?.(res.version);
          onClose();
        },
      },
    );
  };

  const nextLabel = existing
    ? mode === 'revision'
      ? formatRevision(Math.max(...existing.versions.map((v) => v.revision)) + 1)
      : `${formatRevision(existing.latest.revision)} v${Math.max(...existing.versions.filter((v) => v.revision === existing.latest.revision).map((v) => v.version)) + 1}`
    : 'Rev 00';

  return (
    <Modal
      open
      onClose={onClose}
      size="md"
      title={target.title ?? 'Upload Dokumen'}
      description={project ? `${project.code} · ${project.name}` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={upload.isPending}>
            Batal
          </Button>
          <Button variant="primary" onClick={submit} loading={upload.isPending} icon={<FileUp className="size-4" />}>
            Upload {mode === 'new' ? '' : nextLabel}
          </Button>
        </>
      }
    >
      {isLoading ? (
        <div className="space-y-3">
          <Skeleton className="h-10" />
          <Skeleton className="h-10" />
          <Skeleton className="h-32" />
        </div>
      ) : uploadable.length === 0 ? (
        <InlineAlert tone="yellow" title="Tidak ada proses yang dapat Anda upload">
          Role Anda tidak memiliki akses upload pada proses di project ini.
        </InlineAlert>
      ) : (
        <div className="space-y-4">
          {upload.error && !Object.keys(fe).length ? <InlineAlert tone="red">{errorMessage(upload.error)}</InlineAlert> : null}
          {upload.error && isAppError(upload.error) && upload.error.code === 'CONFLICT' ? <InlineAlert tone="yellow">{errorMessage(upload.error)}</InlineAlert> : null}
          <Field label="Proses" htmlFor="up-process" required error={err('processId')} helper="Dokumen selalu terhubung ke Project + Process.">
            <Select id="up-process" value={processId} onChange={(e) => { setProcessId(e.target.value); setDocumentId(''); setType(''); }} disabled={!!target.processId}>
              {uploadable.map((p) => (
                <option key={p.id} value={p.id}>
                  {String(p.sequence).padStart(2, '0')} · {p.name} ({p.folder})
                </option>
              ))}
            </Select>
          </Field>
          <SegmentedControl
            label="Jenis upload"
            value={mode}
            onChange={(m) => setMode(m)}
            options={[
              { value: 'new', label: 'Dokumen baru' },
              { value: 'revision', label: 'Revisi baru' },
              { value: 'version', label: 'Versi baru' },
            ]}
          />
          {mode !== 'new' ? (
            processDocs.length === 0 ? (
              <InlineAlert tone="yellow">Belum ada dokumen di proses ini. Upload sebagai dokumen baru terlebih dahulu.</InlineAlert>
            ) : (
              <Field
                label="Dokumen yang direvisi"
                htmlFor="up-doc"
                required
                error={err('documentId')}
                helper={mode === 'revision' ? 'Revisi baru (Rev +1). Revisi lama tetap tersimpan sebagai history.' : 'Versi file baru pada revisi yang sama (mis. perbaikan minor).'}
              >
                <Select id="up-doc" value={documentId} onChange={(e) => setDocumentId(e.target.value)}>
                  {processDocs.map((d) => (
                    <option key={d.doc.id} value={d.doc.id}>
                      {d.doc.name} — terakhir {formatRevision(d.latest.revision)} ({d.latest.status})
                    </option>
                  ))}
                </Select>
              </Field>
            )
          ) : (
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="Document Type" htmlFor="up-type" required error={err('type')}>
                <Select id="up-type" value={type} onChange={(e) => setType(e.target.value as DocType)}>
                  <option value="">Pilih type</option>
                  {proc && proc.suggestedDocTypes.length > 0 && (
                    <optgroup label="Disarankan untuk proses ini">
                      {proc.suggestedDocTypes.map((t) => (
                        <option key={t} value={t}>
                          {DOC_TYPE_LABEL[t]}
                          {proc.requiredDocTypes.includes(t) ? ' (wajib)' : ''}
                        </option>
                      ))}
                    </optgroup>
                  )}
                  <optgroup label="Semua type">
                    {DOC_TYPES.filter((t) => !proc?.suggestedDocTypes.includes(t)).map((t) => (
                      <option key={t} value={t}>
                        {DOC_TYPE_LABEL[t]}
                      </option>
                    ))}
                  </optgroup>
                </Select>
              </Field>
              <Field label="Nama Dokumen" htmlFor="up-name" required error={err('name')}>
                <Input id="up-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="mis. Artwork Tube 8 ml" />
              </Field>
            </div>
          )}

          <Field label="File" htmlFor="up-file" required error={err('file')} helper="AI, PDF, EPS, SVG, PNG, JPG, DWG, STEP, Excel, Word, video · maks. 25 MB">
            <div
              onDragOver={(e) => {
                e.preventDefault();
                setDrag(true);
              }}
              onDragLeave={() => setDrag(false)}
              onDrop={onDrop}
              className={cn(
                'flex flex-col items-center justify-center rounded-2xl border-2 border-dashed px-4 py-6 text-center transition-colors',
                drag ? 'border-accent bg-accent-soft' : err('file') ? 'border-tone-red' : 'border-line-strong bg-surface-2',
              )}
            >
              {file ? (
                <div className="flex w-full items-center gap-3 text-left">
                  <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-surface text-ink-2">
                    <FileUp className="size-5" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[14px] font-medium text-ink">{file.name}</span>
                    <span className="block text-[12px] text-ink-3">{formatBytes(file.size)}</span>
                  </span>
                  <button type="button" onClick={() => setFile(null)} aria-label="Hapus file" className="rounded-full p-1.5 text-ink-3 hover:bg-surface hover:text-ink">
                    <X className="size-4" />
                  </button>
                </div>
              ) : (
                <>
                  <UploadCloud className="size-7 text-ink-3" aria-hidden="true" />
                  <p className="mt-2 text-[13px] text-ink-2">
                    Tarik file ke sini atau{' '}
                    <button type="button" onClick={() => inputRef.current?.click()} className="font-medium text-accent-ink hover:underline">
                      pilih file
                    </button>
                  </p>
                </>
              )}
              <input
                ref={inputRef}
                id="up-file"
                type="file"
                accept={ACCEPTED_EXTENSIONS}
                className="sr-only"
                onChange={(e) => {
                  pick(e.target.files?.[0]);
                  e.target.value = '';
                }}
              />
            </div>
          </Field>
          <Field label={mode === 'new' ? 'Deskripsi' : 'Catatan revisi'} htmlFor="up-note" helper="Opsional">
            {mode === 'new' ? (
              <Textarea id="up-note" rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
            ) : (
              <Textarea id="up-note" rows={2} value={note} onChange={(e) => setNote(e.target.value)} placeholder="mis. Revisi sesuai feedback customer: warna lebih soft" />
            )}
          </Field>
        </div>
      )}
    </Modal>
  );
}
