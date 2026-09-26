import { DOC_TYPE_LABEL, DOC_TYPES, formatRevision } from '@/config/labels';
import { normalize, uid } from '@/lib/utils';
import type { DbState, DocType, DocumentVersion, ProjectDocument } from '@/types';
import { type CommandContext, findProcess, findProject, logActivity, notify, projectPics } from './context';
import { AppError } from './errors';
import { canUploadToProcess } from './permissions';

export const MAX_FILE_SIZE = 25 * 1024 * 1024;

export interface FileMeta {
  fileName: string;
  mimeType: string;
  size: number;
  blobKey?: string;
}

export type UploadMode = 'new' | 'revision' | 'version';

export interface UploadInput {
  projectId: string;
  processId: string;
  mode: UploadMode;
  documentId?: string;
  type: DocType;
  name: string;
  description?: string;
  note?: string;
  file: FileMeta;
}

export function validateFile(file: FileMeta | undefined): string | null {
  if (!file || !file.fileName) return 'Pilih file untuk diupload.';
  if (file.size <= 0) return 'File kosong tidak dapat diupload.';
  if (file.size > MAX_FILE_SIZE) return 'Ukuran file maksimal 25 MB.';
  return null;
}

/**
 * Uploads a document to a project process. Revisions never overwrite:
 * a new revision/version is appended and the previous one is marked
 * Superseded (Rejected/Approved history is kept as-is) — PRD §8.
 */
export function uploadDocument(db: DbState, ctx: CommandContext, input: UploadInput): { document: ProjectDocument; version: DocumentVersion } {
  const project = findProject(db, input.projectId);
  const proc = findProcess(db, input.processId);
  if (proc.projectId !== project.id) throw new AppError('VALIDATION', 'Proses tidak sesuai dengan project.');
  if (!canUploadToProcess(ctx.actor, project, proc))
    throw new AppError('FORBIDDEN', `Role Anda tidak dapat mengupload dokumen pada proses ${proc.name}.`);
  if (project.status === 'cancelled') throw new AppError('INVALID_STATE', 'Project Cancelled tidak dapat menerima dokumen baru.');

  const fe: Record<string, string> = {};
  const fileError = validateFile(input.file);
  if (fileError) fe.file = fileError;
  if (input.mode === 'new') {
    if (!input.name?.trim()) fe.name = 'Nama dokumen wajib diisi.';
    if (!DOC_TYPES.includes(input.type)) fe.type = 'Pilih Document Type.';
  }
  if (input.mode !== 'new' && !input.documentId) fe.documentId = 'Pilih dokumen yang direvisi.';
  if (Object.keys(fe).length) throw new AppError('VALIDATION', 'Periksa kembali data upload.', { fieldErrors: fe });

  let document: ProjectDocument;
  let revision = 0;
  let versionNo = 1;
  if (input.mode === 'new') {
    const n = normalize(input.name);
    const dup = db.documents.find((d) => d.processId === proc.id && normalize(d.name) === n);
    if (dup)
      throw new AppError('CONFLICT', `Dokumen "${dup.name}" sudah ada di proses ${proc.name}. Upload sebagai revisi baru agar history tetap tersimpan.`, {
        fieldErrors: { name: 'Nama dokumen sudah digunakan pada proses ini.' },
      });
    document = {
      id: uid('doc'),
      projectId: project.id,
      processId: proc.id,
      type: input.type,
      name: input.name.trim(),
      description: input.description?.trim() || undefined,
      createdAt: ctx.now,
      createdBy: ctx.actor.id,
      latestVersionId: '',
    };
    db.documents.push(document);
  } else {
    const existing = db.documents.find((d) => d.id === input.documentId);
    if (!existing || existing.projectId !== project.id) throw new AppError('NOT_FOUND', 'Dokumen yang direvisi tidak ditemukan.');
    document = existing;
    const versions = db.documentVersions.filter((v) => v.documentId === document.id);
    const maxRev = Math.max(...versions.map((v) => v.revision));
    if (input.mode === 'revision') {
      revision = maxRev + 1;
    } else {
      revision = maxRev;
      versionNo = Math.max(...versions.filter((v) => v.revision === maxRev).map((v) => v.version)) + 1;
    }
    for (const v of versions) if (v.status === 'current') v.status = 'superseded';
    if (input.description?.trim()) document.description = input.description.trim();
  }

  const version: DocumentVersion = {
    id: uid('ver'),
    documentId: document.id,
    revision,
    version: versionNo,
    fileName: input.file.fileName,
    mimeType: input.file.mimeType || 'application/octet-stream',
    size: input.file.size,
    blobKey: input.file.blobKey,
    uploadedById: ctx.actor.id,
    uploadedAt: ctx.now,
    status: 'current',
    note: input.note?.trim() || undefined,
  };
  db.documentVersions.push(version);
  document.latestVersionId = version.id;

  const label = `${document.name} — ${formatRevision(revision)}${versionNo > 1 ? ` v${versionNo}` : ''}`;
  const isRevision = input.mode !== 'new';
  logActivity(db, ctx, project, isRevision ? 'document_revision' : 'document_uploaded', `${isRevision ? 'Revisi dokumen' : 'Dokumen diupload'}: ${label}`, {
    processId: proc.id,
    detail: [`${DOC_TYPE_LABEL[document.type]} · ${proc.name}`, version.note].filter(Boolean).join('\n'),
  });
  notify(
    db,
    ctx,
    [...projectPics(project), proc.picId],
    isRevision ? 'document_revision' : 'document_uploaded',
    isRevision ? 'Revisi dokumen baru' : 'Dokumen baru diupload',
    `${project.code} · ${label} (${proc.name})`,
    { projectId: project.id },
  );
  return { document, version };
}
