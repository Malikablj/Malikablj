import { Download, FileQuestion } from 'lucide-react';
import { useEffect, useState } from 'react';
import { DOC_TYPE_LABEL, formatRevision } from '@/config/labels';
import { formatDateTime } from '@/lib/date';
import { cn, formatBytes } from '@/lib/utils';
import { downloadBlob } from '@/lib/xlsx';
import { type DocumentRow, getVersionFile } from '@/services/api/documents';
import { Button } from '../ui/Button';
import { ErrorState, Spinner } from '../ui/Feedback';
import { Modal } from '../ui/Overlay';
import { useToast } from '../ui/Toast';
import { DocStatusChip } from '../project/Chips';

export async function downloadVersion(versionId: string, toast: ReturnType<typeof useToast>) {
  try {
    const f = await getVersionFile(versionId);
    downloadBlob(f.blob, f.fileName);
  } catch (e) {
    toast.fromError(e, 'Download gagal');
  }
}

/** Preview + full revision history of one document. Old revisions are never deleted. */
export function DocumentPreview({ row, onClose, initialVersionId }: { row: DocumentRow | null; onClose: () => void; initialVersionId?: string }) {
  if (!row) return null;
  return <PreviewInner row={row} onClose={onClose} initialVersionId={initialVersionId} />;
}

function PreviewInner({ row, onClose, initialVersionId }: { row: DocumentRow; onClose: () => void; initialVersionId?: string }) {
  const toast = useToast();
  const [versionId, setVersionId] = useState(initialVersionId ?? row.latest.id);
  const [state, setState] = useState<{ url?: string; mime?: string; text?: string; error?: unknown; loading: boolean }>({ loading: true });
  const version = row.versions.find((v) => v.id === versionId) ?? row.latest;

  useEffect(() => {
    let url: string | undefined;
    let cancelled = false;
    setState({ loading: true });
    getVersionFile(versionId)
      .then(async (f) => {
        if (cancelled) return;
        url = URL.createObjectURL(f.blob);
        const text = f.mimeType.startsWith('text/') || f.mimeType === 'application/json' ? await f.blob.text() : undefined;
        if (!cancelled) setState({ url, mime: f.mimeType, text, loading: false });
      })
      .catch((error) => !cancelled && setState({ error, loading: false }));
    return () => {
      cancelled = true;
      if (url) URL.revokeObjectURL(url);
    };
  }, [versionId]);

  const mime = state.mime ?? '';
  return (
    <Modal
      open
      onClose={onClose}
      size="xl"
      title={row.doc.name}
      description={`${row.projectCode} · ${row.processName} · ${DOC_TYPE_LABEL[row.doc.type]}`}
      footer={
        <Button icon={<Download className="size-4" />} onClick={() => downloadVersion(version.id, toast)}>
          Download {formatRevision(version.revision)}
        </Button>
      }
    >
      <div className="grid grid-cols-1 gap-4 md:grid-cols-[1fr_260px]">
        <div className="flex min-h-[320px] items-center justify-center overflow-hidden rounded-xl border border-line bg-surface-2 md:min-h-[480px]">
          {state.loading ? (
            <Spinner className="size-6 text-ink-3" />
          ) : state.error ? (
            <ErrorState error={state.error} compact />
          ) : mime.startsWith('image/') ? (
            <img src={state.url} alt={`${row.doc.name} ${formatRevision(version.revision)}`} className="max-h-[520px] max-w-full object-contain" />
          ) : mime === 'application/pdf' && __SANDBOX__ ? (
            <div className="px-6 text-center">
              <FileQuestion className="mx-auto size-8 text-ink-3" aria-hidden="true" />
              <p className="mt-2 text-[14px] font-medium text-ink">Pratinjau PDF tidak tersedia di mode pratinjau ini</p>
              <p className="mt-1 text-[13px] text-ink-2">{version.fileName} · buka aplikasi penuh untuk melihat PDF.</p>
            </div>
          ) : mime === 'application/pdf' ? (
            <iframe src={state.url} title={`Preview ${row.doc.name}`} className="h-[520px] w-full bg-white" />
          ) : state.text !== undefined ? (
            <pre className="h-[480px] w-full overflow-auto p-4 text-[12px] whitespace-pre-wrap text-ink">{state.text}</pre>
          ) : (
            <div className="px-6 text-center">
              <FileQuestion className="mx-auto size-8 text-ink-3" aria-hidden="true" />
              <p className="mt-2 text-[14px] font-medium text-ink">Pratinjau tidak tersedia untuk format ini</p>
              <p className="mt-1 text-[13px] text-ink-2">{version.fileName} — gunakan tombol Download untuk membuka file.</p>
            </div>
          )}
        </div>
        <div>
          <p className="mb-2 text-[12px] font-medium text-ink-3">Riwayat revisi ({row.versions.length})</p>
          <ol className="space-y-1.5">
            {row.versions.map((v) => (
              <li key={v.id}>
                <button
                  type="button"
                  onClick={() => setVersionId(v.id)}
                  aria-pressed={v.id === versionId}
                  className={cn('w-full rounded-xl border px-3 py-2.5 text-left transition-colors', v.id === versionId ? 'border-accent bg-accent-soft' : 'border-line hover:bg-surface-2')}
                >
                  <span className="flex items-center justify-between gap-2">
                    <span className="text-[14px] font-semibold text-ink">
                      {formatRevision(v.revision)}
                      {v.version > 1 && <span className="font-normal text-ink-3"> · v{v.version}</span>}
                    </span>
                    <DocStatusChip status={v.status} size="sm" />
                  </span>
                  <span className="mt-1 block truncate text-[12px] text-ink-2">{v.fileName}</span>
                  <span className="block text-[11px] text-ink-3">
                    {v.uploadedBy} · {formatDateTime(v.uploadedAt)} · {formatBytes(v.size)}
                  </span>
                  {v.note && <span className="mt-1 block text-[12px] text-ink-2">“{v.note}”</span>}
                </button>
              </li>
            ))}
          </ol>
          {row.doc.description && <p className="mt-3 text-[12px] text-ink-2">{row.doc.description}</p>}
        </div>
      </div>
    </Modal>
  );
}
