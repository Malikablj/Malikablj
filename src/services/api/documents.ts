import { customerName, userName } from '@/domain/context';
import { MAX_FILE_SIZE, type UploadInput, uploadDocument as uploadCmd } from '@/domain/documentCommands';
import { AppError } from '@/domain/errors';
import { canViewProject, visibleProjects } from '@/domain/permissions';
import type { DbState, DocumentVersion, ProjectDocument } from '@/types';
import { putFile, resolveFile } from '../storage/fileStore';
import { command, query } from './client';

export interface VersionView extends DocumentVersion {
  uploadedBy: string;
}

export interface DocumentRow {
  doc: ProjectDocument;
  latest: VersionView;
  versions: VersionView[];
  processId: string;
  processName: string;
  processKey: string;
  processSequence: number;
  folder: string;
  projectId: string;
  projectCode: string;
  projectName: string;
  customer: string;
}

export function toDocumentRows(db: DbState, docs: ProjectDocument[]): DocumentRow[] {
  return docs
    .map((doc) => {
      const proc = db.processes.find((p) => p.id === doc.processId)!;
      const project = db.projects.find((p) => p.id === doc.projectId)!;
      const versions = db.documentVersions
        .filter((v) => v.documentId === doc.id)
        .map((v) => ({ ...v, uploadedBy: userName(db, v.uploadedById) }))
        .sort((a, b) => b.revision - a.revision || b.version - a.version);
      return {
        doc,
        latest: versions.find((v) => v.id === doc.latestVersionId) ?? versions[0],
        versions,
        processId: proc.id,
        processName: proc.name,
        processKey: proc.key,
        processSequence: proc.sequence,
        folder: proc.folder,
        projectId: project.id,
        projectCode: project.code,
        projectName: project.name,
        customer: customerName(db, project.customerId),
      };
    })
    .sort((a, b) => b.latest.uploadedAt.localeCompare(a.latest.uploadedAt));
}

export function listDocuments(): Promise<DocumentRow[]> {
  return query((db, user) => {
    const ids = new Set(visibleProjects(user, db.projects).map((p) => p.id));
    return toDocumentRows(
      db,
      db.documents.filter((d) => ids.has(d.projectId)),
    );
  });
}

export const ACCEPTED_EXTENSIONS = '.pdf,.ai,.eps,.svg,.png,.jpg,.jpeg,.webp,.dwg,.dxf,.step,.stp,.igs,.stl,.xlsx,.xls,.docx,.doc,.csv,.txt,.mp4,.mov,.zip';

export async function uploadDocument(input: Omit<UploadInput, 'file'> & { file: File | null }) {
  if (!input.file) throw new AppError('VALIDATION', 'Pilih file untuk diupload.', { fieldErrors: { file: 'Pilih file untuk diupload.' } });
  if (input.file.size > MAX_FILE_SIZE) throw new AppError('VALIDATION', 'Ukuran file maksimal 25 MB.', { fieldErrors: { file: 'Ukuran file maksimal 25 MB.' } });
  if (input.file.size === 0) throw new AppError('VALIDATION', 'File kosong tidak dapat diupload.', { fieldErrors: { file: 'File kosong.' } });
  const blobKey = await putFile(input.file);
  return command((db, ctx) =>
    uploadCmd(db, ctx, {
      ...input,
      file: { fileName: input.file!.name, mimeType: input.file!.type, size: input.file!.size, blobKey },
    }),
  );
}

/** Returns the stored file for a document version (preview / download). */
export async function getVersionFile(versionId: string): Promise<{ blob: Blob; fileName: string; mimeType: string }> {
  const meta = await query((db, user) => {
    const version = db.documentVersions.find((v) => v.id === versionId);
    if (!version) throw new AppError('NOT_FOUND', 'Versi dokumen tidak ditemukan.');
    const doc = db.documents.find((d) => d.id === version.documentId)!;
    const project = db.projects.find((p) => p.id === doc.projectId)!;
    if (!canViewProject(user, project)) throw new AppError('FORBIDDEN', 'Anda tidak memiliki akses ke dokumen ini.');
    const proc = db.processes.find((p) => p.id === doc.processId);
    return {
      version,
      doc,
      context: { projectCode: project.code, projectName: project.name, customer: customerName(db, project.customerId), processName: proc?.name ?? '' },
    };
  });
  const blob = await resolveFile(meta.version, meta.doc, meta.context);
  if (!blob) throw new AppError('NOT_FOUND', 'File tidak tersedia di storage (mungkin dihapus dari browser ini).');
  return { blob, fileName: meta.version.fileName, mimeType: blob.type || meta.version.mimeType };
}
