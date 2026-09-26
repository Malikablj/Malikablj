import { Download, Eye, FileImage, FileText, FolderOpen, History, Upload } from 'lucide-react';
import { DOC_TYPE_LABEL, formatRevision } from '@/config/labels';
import { formatDate } from '@/lib/date';
import type { DocumentRow } from '@/services/api/documents';
import { IconButton } from '../ui/Button';
import { useToast } from '../ui/Toast';
import { DocStatusChip } from '../project/Chips';
import { downloadVersion } from './DocumentPreview';

export function DocIcon({ mime }: { mime: string }) {
  const Icon = mime.startsWith('image/') ? FileImage : FileText;
  return (
    <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-surface-2 text-ink-2">
      <Icon className="size-[18px]" strokeWidth={1.75} aria-hidden="true" />
    </span>
  );
}

export function DocumentRowItem({
  row,
  onPreview,
  onRevise,
  showProject,
}: {
  row: DocumentRow;
  onPreview: (row: DocumentRow) => void;
  onRevise?: (row: DocumentRow) => void;
  showProject?: boolean;
}) {
  const toast = useToast();
  return (
    <li className="flex items-center gap-3 px-5 py-3">
      <DocIcon mime={row.latest.mimeType} />
      <button type="button" onClick={() => onPreview(row)} className="min-w-0 flex-1 text-left">
        <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
          <span className="truncate text-[14px] font-medium text-ink hover:underline">{row.doc.name}</span>
          <span className="tabular text-[12px] font-semibold text-ink-2">{formatRevision(row.latest.revision)}{row.latest.version > 1 ? ` v${row.latest.version}` : ''}</span>
          <DocStatusChip status={row.latest.status} size="sm" />
        </span>
        <span className="mt-0.5 block truncate text-[12px] text-ink-3">
          {showProject ? `${row.projectCode} · ${row.projectName} · ${row.processName} · ` : ''}
          {DOC_TYPE_LABEL[row.doc.type]} · {row.latest.uploadedBy} · {formatDate(row.latest.uploadedAt.slice(0, 10))}
          {row.versions.length > 1 && (
            <span className="ml-1 inline-flex items-center gap-0.5 text-ink-2">
              · <History className="size-3" aria-hidden="true" /> {row.versions.length} revisi
            </span>
          )}
        </span>
      </button>
      <div className="flex shrink-0 items-center">
        <IconButton label={`Preview ${row.doc.name}`} size="sm" onClick={() => onPreview(row)} className="max-sm:hidden">
          <Eye className="size-4" />
        </IconButton>
        <IconButton label={`Download ${row.doc.name}`} size="sm" onClick={() => downloadVersion(row.latest.id, toast)}>
          <Download className="size-4" />
        </IconButton>
        {onRevise && (
          <IconButton label={`Upload revisi ${row.doc.name}`} size="sm" onClick={() => onRevise(row)}>
            <Upload className="size-4" />
          </IconButton>
        )}
      </div>
    </li>
  );
}

/** Documents grouped per process folder (PRD §8: every process has its own document area). */
export function DocumentFolders({
  rows,
  processes,
  onPreview,
  onUpload,
  canUpload,
}: {
  rows: DocumentRow[];
  processes: Array<{ id: string; name: string; sequence: number; folder: string; status: string; requiresDocument: boolean; hasRequiredDoc: boolean }>;
  onPreview: (row: DocumentRow) => void;
  onUpload: (processId: string, documentId?: string) => void;
  canUpload: (processId: string) => boolean;
}) {
  const folders = [...new Set(processes.map((p) => p.folder))].sort();
  return (
    <div className="space-y-5">
      {folders.map((folder) => {
        const procs = processes.filter((p) => p.folder === folder && (p.status !== 'skipped' || rows.some((r) => r.processId === p.id)));
        if (procs.length === 0) return null;
        const count = rows.filter((r) => procs.some((p) => p.id === r.processId)).length;
        return (
          <section key={folder} aria-label={`Folder ${folder}`}>
            <h3 className="mb-2 flex items-center gap-2 px-5 text-[13px] font-semibold text-ink">
              <FolderOpen className="size-4 text-ink-3" aria-hidden="true" /> {folder}
              <span className="font-normal text-ink-3">· {count} dokumen</span>
            </h3>
            <div className="divide-y divide-line border-y border-line">
              {procs.map((p) => {
                const docs = rows.filter((r) => r.processId === p.id);
                const missing = p.requiresDocument && !p.hasRequiredDoc && ['current', 'revision', 'problem', 'completed'].includes(p.status);
                return (
                  <div key={p.id}>
                    <div className="flex items-center justify-between gap-2 bg-surface-2/60 px-5 py-2">
                      <p className="min-w-0 truncate text-[12px] font-medium text-ink-2">
                        {String(p.sequence).padStart(2, '0')} · {p.name}
                        {missing && <span className="ml-2 font-semibold text-tone-orange">Dokumen wajib belum ada</span>}
                      </p>
                      {canUpload(p.id) && (
                        <button type="button" onClick={() => onUpload(p.id)} className="inline-flex h-8 shrink-0 items-center gap-1 rounded-lg px-2 text-[12px] font-medium text-accent-ink hover:bg-accent-soft">
                          <Upload className="size-3.5" /> Upload
                        </button>
                      )}
                    </div>
                    {docs.length === 0 ? (
                      <p className="px-5 py-3 text-[13px] text-ink-3">Belum ada dokumen.</p>
                    ) : (
                      <ul className="divide-y divide-line">
                        {docs.map((r) => (
                          <DocumentRowItem key={r.doc.id} row={r} onPreview={onPreview} onRevise={canUpload(p.id) ? (row) => onUpload(p.id, row.doc.id) : undefined} />
                        ))}
                      </ul>
                    )}
                  </div>
                );
              })}
            </div>
          </section>
        );
      })}
    </div>
  );
}
