import { FileText, Upload } from 'lucide-react';
import { useMemo, useState } from 'react';
import { DocumentPreview } from '@/components/documents/DocumentPreview';
import { DocumentRowItem } from '@/components/documents/DocumentList';
import { UploadDocumentDialog, type UploadTarget } from '@/components/documents/UploadDocumentDialog';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState, ErrorState, ListSkeleton, NoResults } from '@/components/ui/Feedback';
import { Field, SearchInput, Select } from '@/components/ui/Form';
import { Modal } from '@/components/ui/Overlay';
import { DOC_STATUS_LABEL, DOC_TYPE_LABEL } from '@/config/labels';
import { DOCUMENT_FOLDERS } from '@/config/workflows';
import { isReadOnly } from '@/domain/permissions';
import { useDocuments, useProjects } from '@/hooks/queries';
import { useUser } from '@/hooks/useAuth';
import { useDebounced } from '@/hooks/useUtils';
import { normalize } from '@/lib/utils';
import type { DocumentRow } from '@/services/api/documents';
import type { DocType, DocumentStatus } from '@/types';

export default function DocumentsPage() {
  const user = useUser();
  const { data, isLoading, error, refetch } = useDocuments();
  const projects = useProjects();
  const [q, setQ] = useState('');
  const dq = useDebounced(q);
  const [type, setType] = useState<'' | DocType>('');
  const [status, setStatus] = useState<'' | DocumentStatus>('');
  const [folder, setFolder] = useState('');
  const [project, setProject] = useState('');
  const [sort, setSort] = useState<'recent' | 'name' | 'revisions'>('recent');
  const [preview, setPreview] = useState<DocumentRow | null>(null);
  const [upload, setUpload] = useState<UploadTarget | null>(null);
  const [pickProject, setPickProject] = useState(false);
  const [chosenProject, setChosenProject] = useState('');

  const all = useMemo(() => data ?? [], [data]);
  const projectOptions = useMemo(() => [...new Map(all.map((r) => [r.projectCode, r.projectName])).entries()].sort(), [all]);
  const rows = useMemo(() => {
    const n = normalize(dq);
    const list = all.filter(
      (r) =>
        (!type || r.doc.type === type) &&
        (!status || r.latest.status === status) &&
        (!folder || r.folder === folder) &&
        (!project || r.projectCode === project) &&
        (!n || normalize(`${r.doc.name} ${r.projectCode} ${r.projectName} ${r.customer} ${r.processName} ${r.latest.fileName}`).includes(n)),
    );
    if (sort === 'name') list.sort((a, b) => a.doc.name.localeCompare(b.doc.name));
    else if (sort === 'revisions') list.sort((a, b) => b.versions.length - a.versions.length);
    return list;
  }, [all, dq, type, status, folder, project, sort]);
  const reset = () => {
    setQ('');
    setType('');
    setStatus('');
    setFolder('');
    setProject('');
  };

  return (
    <div>
      <PageHeader
        title="Dokumen"
        description="Semua dokumen project per proses — revision lama tetap tersimpan."
        actions={
          !isReadOnly(user) && (
            <Button variant="primary" icon={<Upload className="size-4" />} onClick={() => setPickProject(true)}>
              Upload Dokumen
            </Button>
          )
        }
      />
      <div className="mb-4 space-y-2">
        <SearchInput value={q} onChange={setQ} placeholder="Cari nama dokumen, file, project, proses…" label="Cari dokumen" />
        <div className="grid grid-cols-2 gap-2 md:grid-cols-5">
          <Select aria-label="Project" value={project} onChange={(e) => setProject(e.target.value)}>
            <option value="">Semua project</option>
            {projectOptions.map(([code, name]) => (
              <option key={code} value={code}>
                {code} · {name}
              </option>
            ))}
          </Select>
          <Select aria-label="Folder proses" value={folder} onChange={(e) => setFolder(e.target.value)}>
            <option value="">Semua folder</option>
            {DOCUMENT_FOLDERS.map((f) => (
              <option key={f}>{f}</option>
            ))}
          </Select>
          <Select aria-label="Document type" value={type} onChange={(e) => setType(e.target.value as DocType | '')}>
            <option value="">Semua type</option>
            {(Object.keys(DOC_TYPE_LABEL) as DocType[]).map((t) => (
              <option key={t} value={t}>
                {DOC_TYPE_LABEL[t]}
              </option>
            ))}
          </Select>
          <Select aria-label="Status dokumen" value={status} onChange={(e) => setStatus(e.target.value as DocumentStatus | '')}>
            <option value="">Semua status</option>
            {(Object.keys(DOC_STATUS_LABEL) as DocumentStatus[]).map((s) => (
              <option key={s} value={s}>
                {DOC_STATUS_LABEL[s]}
              </option>
            ))}
          </Select>
          <Select aria-label="Urutkan" value={sort} onChange={(e) => setSort(e.target.value as typeof sort)} className="max-md:col-span-2">
            <option value="recent">Upload terbaru</option>
            <option value="name">Nama A–Z</option>
            <option value="revisions">Revisi terbanyak</option>
          </Select>
        </div>
        <p className="text-[13px] text-ink-2" aria-live="polite">
          {isLoading ? 'Memuat…' : `${rows.length} dari ${all.length} dokumen`}
        </p>
      </div>

      {isLoading ? (
        <ListSkeleton rows={6} />
      ) : error ? (
        <Card>
          <ErrorState error={error} onRetry={() => refetch()} />
        </Card>
      ) : all.length === 0 ? (
        <Card>
          <EmptyState icon={<FileText className="size-5" />} title="Belum ada dokumen" description="Dokumen diupload per proses dari halaman project." />
        </Card>
      ) : rows.length === 0 ? (
        <Card>
          <NoResults onReset={reset} />
        </Card>
      ) : (
        <Card className="overflow-hidden">
          <ul className="divide-y divide-line">
            {rows.map((r) => (
              <DocumentRowItem key={r.doc.id} row={r} onPreview={setPreview} showProject onRevise={!isReadOnly(user) ? (row) => setUpload({ projectCode: row.projectCode, processId: row.processId, documentId: row.doc.id, mode: 'revision' }) : undefined} />
            ))}
          </ul>
        </Card>
      )}

      <Modal
        open={pickProject}
        onClose={() => setPickProject(false)}
        size="sm"
        title="Pilih project"
        description="Dokumen selalu terhubung ke Project dan Process."
        footer={
          <>
            <Button variant="secondary" onClick={() => setPickProject(false)}>
              Batal
            </Button>
            <Button
              variant="primary"
              disabled={!chosenProject}
              onClick={() => {
                setPickProject(false);
                setUpload({ projectCode: chosenProject });
              }}
            >
              Lanjut
            </Button>
          </>
        }
      >
        <Field label="Project" htmlFor="pick-proj">
          <Select id="pick-proj" value={chosenProject} onChange={(e) => setChosenProject(e.target.value)}>
            <option value="">Pilih project</option>
            {projects.data
              ?.filter((p) => p.project.status !== 'cancelled')
              .map((p) => (
                <option key={p.project.id} value={p.project.code}>
                  {p.project.code} · {p.project.name}
                </option>
              ))}
          </Select>
        </Field>
      </Modal>
      <UploadDocumentDialog target={upload} onClose={() => setUpload(null)} />
      <DocumentPreview row={preview} onClose={() => setPreview(null)} />
    </div>
  );
}
