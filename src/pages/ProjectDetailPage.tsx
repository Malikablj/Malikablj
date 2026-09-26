import {
  AlertTriangle,
  ArrowRightLeft,
  CalendarClock,
  ChevronDown,
  ChevronLeft,
  Clock,
  Ellipsis,
  Flag as FlagIcon,
  FlagOff,
  Pencil,
  Send,
  Sparkles,
  Upload,
  UserRound,
} from 'lucide-react';
import { type ReactNode, useEffect, useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { AnswerView } from '@/components/assistant/AnswerView';
import { ApprovalHistory, ManualDecisionDialog, RequestApprovalDialog } from '@/components/approvals/ApprovalComponents';
import { DocumentPreview } from '@/components/documents/DocumentPreview';
import { DocumentFolders } from '@/components/documents/DocumentList';
import { UploadDocumentDialog, type UploadTarget } from '@/components/documents/UploadDocumentDialog';
import { PageHeader } from '@/components/layout/PageHeader';
import { DocStatusChip, PriorityChip, ProcessStatusChip, ProjectStatusChips, TypeTag } from '@/components/project/Chips';
import { CompleteProcessDialog } from '@/components/project/CompleteProcessDialog';
import { ProcessDrawer } from '@/components/project/ProcessDrawer';
import { ProcessStepper } from '@/components/project/ProcessStepper';
import { ProcessTimeline } from '@/components/project/ProcessTimeline';
import { EditProjectDialog, MoveProcessDialog, NextActionDialog, ProblemDialog, UpdateStatusDialog } from '@/components/project/ProjectDialogs';
import { Badge } from '@/components/ui/Badge';
import { Button, IconButton } from '@/components/ui/Button';
import { Card, CardHeader, InfoItem } from '@/components/ui/Card';
import { EmptyState, ErrorState, InlineAlert, PageSkeleton, Spinner } from '@/components/ui/Feedback';
import { Select, Textarea } from '@/components/ui/Form';
import { Avatar, ProgressBar } from '@/components/ui/Misc';
import { Menu } from '@/components/ui/Menu';
import { Modal } from '@/components/ui/Overlay';
import { Tabs } from '@/components/ui/Tabs';
import { useToast } from '@/components/ui/Toast';
import {
  APPROVAL_TYPE_LABEL,
  DOC_TYPE_LABEL,
  formatRevision,
  PROJECT_TYPE_LABEL,
  PURCHASING_STATUS_LABEL,
  PURCHASING_STATUS_TONE,
  ROLE_LABEL,
} from '@/config/labels';
import { isRunning } from '@/domain/metrics';
import {
  canActOnProcess,
  canChangeStatus,
  canComment,
  canDecideApproval,
  canEditProject,
  canFinishProject,
  canOverrideWorkflow,
  canRequestApproval,
  canUpdateNextAction,
  canUpdatePurchasing,
  canUploadToProcess,
} from '@/domain/permissions';
import { useAction, useProjectDetail } from '@/hooks/queries';
import { useUser } from '@/hooks/useAuth';
import { describeDue, diffDays, formatDate, formatDateTime, todayISO } from '@/lib/date';
import { cn } from '@/lib/utils';
import type { DocumentRow } from '@/services/api/documents';
import { addComment, type ApprovalView, type ProcessView, type ProjectDetail, updatePurchasingStatus } from '@/services/api/projects';
import { assistant } from '@/services/api/reports';
import type { PurchasingStatus } from '@/types';
import type { AssistantAnswer } from '@/assistant/engine';

type TabKey = 'approvals' | 'documents' | 'revisions' | 'records' | 'activity';
type DialogKey = 'status' | 'nextAction' | 'edit' | 'move' | 'requestApproval' | 'summary' | null;

export default function ProjectDetailPage() {
  const { code = '' } = useParams();
  const { data, isLoading, error, refetch } = useProjectDetail(code);
  if (isLoading) return <PageSkeleton />;
  if (error || !data)
    return (
      <Card>
        <ErrorState error={error} onRetry={() => refetch()} />
        <div className="pb-8 text-center">
          <Link to="/projects" className="text-[13px] font-medium text-accent-ink hover:underline">
            Kembali ke daftar project
          </Link>
        </div>
      </Card>
    );
  return <Detail detail={data} />;
}

function Detail({ detail }: { detail: ProjectDetail }) {
  const user = useUser();
  const today = todayISO();
  const { item } = detail;
  const p = item.project;
  const current = detail.processes.find((x) => x.id === p.currentProcessId);
  const [tab, setTab] = useState<TabKey>('approvals');
  const [dialog, setDialog] = useState<DialogKey>(null);
  const [drawer, setDrawer] = useState<string | null>(null);
  const [complete, setComplete] = useState<string | null>(null);
  const [upload, setUpload] = useState<UploadTarget | null>(null);
  const [preview, setPreview] = useState<{ row: DocumentRow; versionId?: string } | null>(null);
  const [problem, setProblem] = useState<{ proc: ProcessView; flag: boolean } | null>(null);
  const [decide, setDecide] = useState<ApprovalView | null>(null);
  const [infoOpen, setInfoOpen] = useState(false);

  const active = p.status !== 'completed' && p.status !== 'cancelled';
  const running = !!current && isRunning(current) && active;
  const canAct = !!current && canActOnProcess(user, p, current);
  const hold = p.status === 'hold';

  const openUpload = (processId: string, documentId?: string) => setUpload({ projectCode: p.code, processId, documentId, mode: documentId ? 'revision' : 'new' });
  const openAttachment = (versionId: string) => {
    const row = detail.documents.find((d) => d.versions.some((v) => v.id === versionId));
    if (row) setPreview({ row, versionId });
  };
  const decideApproval = (a: ApprovalView) => {
    if (a.linkedToWorkflow) setComplete(a.processId);
    else setDecide(a);
  };

  const menuItems = [
    ...(canEditProject(user, p) && p.status !== 'cancelled' ? [{ label: 'Edit project', icon: <Pencil className="size-4" />, onSelect: () => setDialog('edit') }] : []),
    ...(canRequestApproval(user) && active ? [{ label: 'Ajukan approval', icon: <Send className="size-4" />, onSelect: () => setDialog('requestApproval') }] : []),
    ...(canFinishProject(user) && active
      ? [{ label: 'Selesaikan project (Finish)', icon: <FlagIcon className="size-4" />, onSelect: () => setComplete(detail.processes.find((x) => x.kind === 'finish')!.id) }]
      : []),
    ...(canOverrideWorkflow(user) && active ? [{ label: 'Pindahkan current process', icon: <ArrowRightLeft className="size-4" />, onSelect: () => setDialog('move') }] : []),
  ];

  const lastUpdate = diffDays(p.updatedAt.slice(0, 10), today);
  const agingInProcess = current?.enteredAt ? diffDays(current.enteredAt.slice(0, 10), today) : null;

  return (
    <div>
      <PageHeader
        eyebrow={
          <Link to={`/projects?type=${p.type}`} className="inline-flex items-center gap-1 hover:text-ink">
            <ChevronLeft className="size-4" /> Project {PROJECT_TYPE_LABEL[p.type]}
          </Link>
        }
        title={p.name}
        description={
          <span className="flex flex-wrap items-center gap-x-2 gap-y-1.5">
            <span className="tabular font-medium text-ink">{p.code}</span>
            <span aria-hidden="true">·</span>
            <span>{item.customer}</span>
            <TypeTag type={p.type} />
            <ProjectStatusChips status={item.displayStatus} baseStatus={p.status} overdueDays={item.overdueDays} />
            <PriorityChip priority={p.priority} />
          </span>
        }
        actions={
          <>
            <Button icon={<Sparkles className="size-4" />} onClick={() => setDialog('summary')}>
              Ringkasan AI
            </Button>
            {canChangeStatus(user) && active && (
              <Button onClick={() => setDialog('status')} icon={<FlagIcon className="size-4" />}>
                Update Status
              </Button>
            )}
            {menuItems.length > 0 && (
              <Menu
                label="Aksi project"
                items={menuItems}
                trigger={(props) => (
                  <IconButton label="Aksi lainnya" {...props} className="border border-line-strong bg-surface">
                    <Ellipsis className="size-4" />
                  </IconButton>
                )}
              />
            )}
          </>
        }
      />

      {p.status === 'hold' && (
        <div className="mb-5">
          <InlineAlert tone="yellow" title="Project sedang Hold">
            {p.statusReason ?? 'Proses tidak dapat dilanjutkan selama Hold.'} {canChangeStatus(user) && 'Gunakan Update Status → On Progress untuk melanjutkan.'}
          </InlineAlert>
        </div>
      )}
      {p.status === 'cancelled' && (
        <div className="mb-5">
          <InlineAlert tone="red" title="Project Cancelled">
            {p.statusReason ?? 'Project dibatalkan.'}
          </InlineAlert>
        </div>
      )}

      <div className="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_320px] xl:grid-cols-[minmax(0,1fr)_360px]">
        <div className="min-w-0 space-y-5">
          {/* CURRENT PROCESS — focal point */}
          <Card className={cn('overflow-hidden', running && 'border-accent/40')}>
            <div className="p-5 md:p-6">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-[12px] font-medium text-ink-3">
                  {p.status === 'completed' ? 'Project selesai' : `Current Process · ${current ? `${current.sequence} dari ${detail.processes.length}` : '—'}`}
                </p>
                {current && <ProcessStatusChip status={p.status === 'completed' ? 'completed' : current.status} />}
              </div>
              <h2 className="mt-1.5 text-[24px] leading-tight font-semibold tracking-[-0.02em] text-ink md:text-[28px]">
                {p.status === 'completed' ? `Completed ${formatDate(p.actualFinish)}` : (current?.name ?? '—')}
              </h2>
              {current && p.status !== 'completed' && <p className="mt-1 text-[14px] text-ink-2">{current.description}</p>}

              {current?.status === 'problem' && current.problemNote && (
                <div className="mt-4">
                  <InlineAlert tone="red" title="Problem">
                    {current.problemNote}
                  </InlineAlert>
                </div>
              )}
              {running && current.requiresDocument && !current.hasRequiredDoc && (
                <div className="mt-4">
                  <InlineAlert tone="yellow" title="Dokumen wajib belum diupload">
                    {current.requiredDocTypes.map((t) => DOC_TYPE_LABEL[t]).join(' / ')} diperlukan sebelum proses dapat diselesaikan.
                  </InlineAlert>
                </div>
              )}
              {running && current.pendingApproval && (
                <p className="mt-4 rounded-xl bg-tone-yellow-soft px-3.5 py-2.5 text-[13px] text-ink">
                  <span className="font-semibold">{APPROVAL_TYPE_LABEL[current.pendingApproval.type]}</span> · {current.pendingApproval.revision} · menunggu{' '}
                  {current.pendingApproval.approverName} sejak {formatDate(current.pendingApproval.requestedDate)} ({diffDays(current.pendingApproval.requestedDate, today)} hari)
                </p>
              )}

              <dl className="mt-5 grid grid-cols-2 gap-3 md:grid-cols-4">
                <Block icon={<UserRound className="size-4" />} label="PIC Proses">
                  {current ? (
                    <>
                      {current.picName}
                      <span className="block text-[12px] font-normal text-ink-3">{ROLE_LABEL[current.picRole]}</span>
                    </>
                  ) : (
                    '—'
                  )}
                </Block>
                <Block icon={<Clock className="size-4" />} label="Waiting For" tone={p.status === 'waiting_approval' || p.status === 'waiting_external' ? 'yellow' : undefined}>
                  {active ? (p.waitingFor ?? '—') : '—'}
                </Block>
                <Block icon={<CalendarClock className="size-4" />} label="Deadline" tone={item.overdueDays ? 'red' : item.flags.dueSoon ? 'yellow' : undefined}>
                  {formatDate(p.targetDate)}
                  <span className="block text-[12px] font-normal text-ink-3">{active ? (item.overdueDays ? `Overdue ${item.overdueDays} hari` : describeDue(p.targetDate, today)) : `Selesai ${formatDate(p.actualFinish)}`}</span>
                </Block>
                <Block icon={<Clock className="size-4" />} label="Aging">
                  {item.aging} hari
                  <span className="block text-[12px] font-normal text-ink-3">{agingInProcess !== null && active ? `${agingInProcess} hari di proses ini` : `sejak ${formatDate(p.startDate)}`}</span>
                </Block>
              </dl>

              <div className={cn('mt-3 rounded-2xl border p-4', item.nextActionState === 'overdue' && active ? 'border-tone-red/40 bg-tone-red-soft/40' : 'border-line bg-surface-2/60')}>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="text-[12px] text-ink-3">Next Action</p>
                    <p className="mt-0.5 text-[16px] font-semibold text-ink">{p.nextAction}</p>
                    {active && (
                      <p className={cn('mt-0.5 text-[13px]', item.nextActionState === 'overdue' ? 'font-medium text-tone-red' : item.nextActionState === 'due_soon' ? 'font-medium text-tone-yellow' : 'text-ink-2')}>
                        Due {formatDate(p.nextActionDue)} · {describeDue(p.nextActionDue, today)}
                      </p>
                    )}
                  </div>
                  {active && canUpdateNextAction(user, p, current) && !hold && (
                    <Button size="sm" variant="ghost" icon={<Pencil className="size-3.5" />} onClick={() => setDialog('nextAction')}>
                      Ubah
                    </Button>
                  )}
                </div>
              </div>

              {running && (
                <div className="mt-5 flex flex-wrap gap-2 max-md:hidden">
                  {canAct && (
                    <Button variant="primary" onClick={() => setComplete(current.id)} disabled={hold}>
                      {current.kind === 'decision' ? (current.approverType === 'customer' ? 'Catat Keputusan Customer' : 'Catat Hasil / Keputusan') : current.kind === 'finish' ? 'Selesaikan Project' : 'Selesaikan Proses'}
                    </Button>
                  )}
                  {canUploadToProcess(user, p, current) && (
                    <Button icon={<Upload className="size-4" />} onClick={() => openUpload(current.id)}>
                      Upload Dokumen
                    </Button>
                  )}
                  {canAct && !hold && current.kind !== 'finish' && (
                    <Button variant="ghost" icon={current.status === 'problem' ? <FlagOff className="size-4" /> : <AlertTriangle className="size-4" />} onClick={() => setProblem({ proc: current, flag: current.status !== 'problem' })}>
                      {current.status === 'problem' ? 'Problem selesai' : 'Tandai problem'}
                    </Button>
                  )}
                  <Button variant="ghost" onClick={() => setDrawer(current.id)}>
                    Detail proses
                  </Button>
                </div>
              )}
              {running && !canAct && (
                <p className="mt-4 text-[12px] text-ink-3">
                  Proses ini dikerjakan oleh {current.actorRoles.map((r) => ROLE_LABEL[r]).join(' / ')}. Anda {user.role === 'management' ? 'memiliki akses read-only' : 'dapat melihat detail'}.
                </p>
              )}
            </div>
          </Card>

          {/* PROCESS TRACKER */}
          <Card>
            <CardHeader title="Process Tracker" description={`${detail.workflowName} · ${item.progress.done}/${item.progress.total} proses selesai`} />
            <div className="px-5 pb-4">
              <ProgressBar value={item.progress.pct} tone={p.status === 'completed' ? 'green' : 'blue'} label={`Progress ${item.progress.pct}%`} className="mb-5" />
              <ProcessStepper processes={detail.processes} onSelect={(x) => setDrawer(x.id)} />
            </div>
          </Card>

          {/* TIMELINE */}
          <Card>
            <CardHeader title="Timeline" description="Planned vs actual per proses" />
            <div className="px-5 pb-5">
              <ProcessTimeline project={p} processes={detail.processes} today={today} onSelect={(x) => setDrawer(x.id)} />
            </div>
          </Card>

          {/* APPROVALS / DOCUMENTS / REVISIONS / RECORDS / ACTIVITY */}
          <Card>
            <Tabs
              className="px-3 pt-1"
              label="Detail project"
              value={tab}
              onChange={setTab}
              items={[
                { value: 'approvals', label: 'Approval', count: detail.approvals.length },
                { value: 'documents', label: 'Dokumen', count: detail.documents.length },
                { value: 'revisions', label: 'Revision History' },
                { value: 'records', label: 'Trial & Material', count: detail.records.filter((r) => r.recordType !== 'general').length },
                { value: 'activity', label: 'Activity' },
              ]}
            />
            <div className="py-3">
              {tab === 'approvals' && (
                <>
                  {canRequestApproval(user) && active && (
                    <div className="flex justify-end px-5 pb-2">
                      <Button size="sm" icon={<Send className="size-4" />} onClick={() => setDialog('requestApproval')}>
                        Ajukan approval
                      </Button>
                    </div>
                  )}
                  <ApprovalHistory
                    approvals={detail.approvals}
                    today={today}
                    onDecide={decideApproval}
                    canDecide={(a) => active && !hold && canDecideApproval(user, p, a, detail.processes.find((x) => x.id === a.processId)) && (!a.linkedToWorkflow || a.processId === p.currentProcessId)}
                    onOpenAttachment={openAttachment}
                  />
                </>
              )}
              {tab === 'documents' && (
                <DocumentFolders
                  rows={detail.documents}
                  processes={detail.processes}
                  onPreview={(row) => setPreview({ row })}
                  onUpload={openUpload}
                  canUpload={(pid) => {
                    const proc = detail.processes.find((x) => x.id === pid);
                    return !!proc && canUploadToProcess(user, p, proc) && p.status !== 'cancelled';
                  }}
                />
              )}
              {tab === 'revisions' && <RevisionHistory detail={detail} onPreview={(row, versionId) => setPreview({ row, versionId })} />}
              {tab === 'records' && <RecordsTab detail={detail} />}
              {tab === 'activity' && <ActivityTab detail={detail} />}
            </div>
          </Card>
        </div>

        {/* PROJECT INFORMATION */}
        <aside className="space-y-5 lg:sticky lg:top-24 lg:self-start" aria-label="Informasi project">
          <Card>
            <button type="button" onClick={() => setInfoOpen((o) => !o)} aria-expanded={infoOpen} className="flex w-full items-center justify-between px-5 pt-4 pb-3 text-left lg:pointer-events-none">
              <h2 className="text-[15px] font-semibold text-ink">Project Information</h2>
              <ChevronDown className={cn('size-4 text-ink-3 transition-transform lg:hidden', infoOpen && 'rotate-180')} aria-hidden="true" />
            </button>
            <div className={cn('px-5 pb-5', !infoOpen && 'max-lg:hidden')}>
              <dl className="grid grid-cols-2 gap-x-4 gap-y-4">
                <InfoItem label="Project ID">{p.code}</InfoItem>
                <InfoItem label="Project Type">{PROJECT_TYPE_LABEL[p.type]}</InfoItem>
                <InfoItem label="Customer" className="col-span-2">
                  {item.customer}
                </InfoItem>
                <InfoItem label="Product" className="col-span-2">
                  {p.productName}
                </InfoItem>
                <InfoItem label="Customer Request" className="col-span-2">
                  <span className="text-[13px]">{p.customerRequest}</span>
                </InfoItem>
                {p.productDescription && (
                  <InfoItem label="Product Description" className="col-span-2">
                    <span className="text-[13px]">{p.productDescription}</span>
                  </InfoItem>
                )}
              </dl>
              <div className="my-4 border-t border-line" />
              <ul className="space-y-3">
                {[
                  ['NPD PIC', item.npdPic],
                  ['Sales PIC', item.salesPic],
                  ['Drafter', item.drafter],
                ].map(([label, name]) => (
                  <li key={label} className="flex items-center gap-2.5">
                    <Avatar name={name} size="sm" />
                    <span className="min-w-0 flex-1 truncate text-[14px] text-ink">{name}</span>
                    <span className="text-[12px] text-ink-3">{label}</span>
                  </li>
                ))}
              </ul>
              <div className="my-4 border-t border-line" />
              <dl className="grid grid-cols-2 gap-x-4 gap-y-4">
                <InfoItem label="Start Date">{formatDate(p.startDate)}</InfoItem>
                <InfoItem label="Target Finish">{formatDate(p.targetDate)}</InfoItem>
                <InfoItem label="Actual Finish">{formatDate(p.actualFinish)}</InfoItem>
                <InfoItem label="Priority">
                  <PriorityChip priority={p.priority} size="sm" />
                </InfoItem>
                <InfoItem label="Mold Maker / Supplier" className="col-span-2">
                  {p.supplier ?? '—'}
                </InfoItem>
                {p.type === 'new_mold' && <InfoItem label="New Masterbatch">{p.newMasterbatch === undefined ? 'Belum diputuskan' : p.newMasterbatch ? 'YES' : 'NO'}</InfoItem>}
                <InfoItem label="Last Update">{lastUpdate === 0 ? 'Hari ini' : `${lastUpdate} hari lalu`}</InfoItem>
                {p.remarks && (
                  <InfoItem label="Remarks" className="col-span-2">
                    <span className="text-[13px]">{p.remarks}</span>
                  </InfoItem>
                )}
              </dl>
              <p className="mt-4 text-[12px] text-ink-3">{detail.workflowName}</p>
            </div>
          </Card>
        </aside>
      </div>

      {/* Mobile sticky primary action (one-hand reach) */}
      {running && canAct && (
        <div className="fixed inset-x-0 bottom-0 z-20 flex gap-2 border-t border-line bg-surface/95 px-4 pt-3 pb-[max(12px,env(safe-area-inset-bottom))] backdrop-blur md:hidden">
          <Button className="flex-1" onClick={() => setDrawer(current.id)}>
            Detail
          </Button>
          {canUploadToProcess(user, p, current) && (
            <IconButton label="Upload dokumen" onClick={() => openUpload(current.id)} className="border border-line-strong">
              <Upload className="size-4" />
            </IconButton>
          )}
          <Button variant="primary" className="flex-[2]" onClick={() => setComplete(current.id)} disabled={hold}>
            {current.kind === 'decision' ? 'Catat Keputusan' : current.kind === 'finish' ? 'Finish' : 'Selesaikan'}
          </Button>
        </div>
      )}

      {dialog === 'status' && <UpdateStatusDialog detail={detail} onClose={() => setDialog(null)} />}
      {dialog === 'nextAction' && <NextActionDialog detail={detail} onClose={() => setDialog(null)} />}
      {dialog === 'edit' && <EditProjectDialog detail={detail} onClose={() => setDialog(null)} />}
      {dialog === 'move' && <MoveProcessDialog detail={detail} onClose={() => setDialog(null)} />}
      <RequestApprovalDialog detail={detail} open={dialog === 'requestApproval'} onClose={() => setDialog(null)} />
      {dialog === 'summary' && <SummaryDialog projectId={p.id} onClose={() => setDialog(null)} />}
      <ProcessDrawer
        detail={detail}
        processId={drawer}
        onClose={() => setDrawer(null)}
        onComplete={(x) => {
          setDrawer(null);
          setComplete(x.id);
        }}
        onUpload={openUpload}
        onPreview={(row) => setPreview({ row })}
        today={today}
      />
      <CompleteProcessDialog projectCode={p.code} processId={complete} onClose={() => setComplete(null)} />
      <UploadDocumentDialog target={upload} onClose={() => setUpload(null)} />
      <DocumentPreview row={preview?.row ?? null} initialVersionId={preview?.versionId} onClose={() => setPreview(null)} />
      {problem && <ProblemDialog process={problem.proc} flag={problem.flag} onClose={() => setProblem(null)} />}
      <ManualDecisionDialog approval={decide} onClose={() => setDecide(null)} />
    </div>
  );
}

function Block({ icon, label, children, tone }: { icon: ReactNode; label: string; children: ReactNode; tone?: 'yellow' | 'red' }) {
  return (
    <div className={cn('min-w-0 rounded-2xl border px-3.5 py-3', tone === 'red' ? 'border-tone-red/30 bg-tone-red-soft/40' : tone === 'yellow' ? 'border-tone-yellow/25 bg-tone-yellow-soft/50' : 'border-line bg-surface-2/60')}>
      <dt className="flex items-center gap-1.5 text-[12px] text-ink-3">
        <span aria-hidden="true">{icon}</span>
        {label}
      </dt>
      <dd className="mt-1 text-[14px] font-semibold break-words text-ink">{children}</dd>
    </div>
  );
}

function RevisionHistory({ detail, onPreview }: { detail: ProjectDetail; onPreview: (row: DocumentRow, versionId: string) => void }) {
  const rows = [...detail.documents].sort((a, b) => b.versions.length - a.versions.length || a.processSequence - b.processSequence);
  if (rows.length === 0) return <EmptyState compact title="Belum ada dokumen" description="Revision history muncul setelah dokumen diupload." />;
  return (
    <div className="space-y-5 px-5 py-2">
      <p className="text-[13px] text-ink-2">Semua revisi tetap tersimpan — revisi lama tidak pernah dihapus.</p>
      {rows.map((row) => (
        <section key={row.doc.id} aria-label={row.doc.name}>
          <p className="text-[14px] font-semibold text-ink">
            {row.doc.name} <span className="font-normal text-ink-3">· {DOC_TYPE_LABEL[row.doc.type]} · {row.processName}</span>
          </p>
          <ol className="mt-2 flex flex-wrap items-center gap-2">
            {[...row.versions].reverse().map((v, i) => (
              <li key={v.id} className="flex items-center gap-2">
                {i > 0 && <span className="text-ink-3" aria-hidden="true">→</span>}
                <button type="button" onClick={() => onPreview(row, v.id)} className="flex items-center gap-2 rounded-xl border border-line px-3 py-2 text-left hover:bg-surface-2" title={v.note}>
                  <span className="tabular text-[13px] font-semibold text-ink">
                    {formatRevision(v.revision)}
                    {v.version > 1 && <span className="font-normal text-ink-3"> v{v.version}</span>}
                  </span>
                  <DocStatusChip status={v.status} size="sm" />
                  <span className="text-[11px] text-ink-3">{formatDate(v.uploadedAt.slice(0, 10))}</span>
                </button>
              </li>
            ))}
          </ol>
        </section>
      ))}
    </div>
  );
}

function RecordsTab({ detail }: { detail: ProjectDetail }) {
  const user = useUser();
  const toast = useToast();
  const records = detail.records.filter((r) => r.recordType !== 'general');
  const mutation = useAction(({ id, status }: { id: string; status: PurchasingStatus }) => updatePurchasingStatus(id, status));
  if (records.length === 0) return <EmptyState compact title="Belum ada record" description="Trial record, material request, dan validation record tercatat saat proses terkait diselesaikan." />;
  const label: Record<string, string> = { trial: 'Trial Record', material_request: 'Material Request', material_preparation: 'Material Preparation', validation: 'Validation Record' };
  return (
    <ul className="divide-y divide-line">
      {records.map((r) => {
        const d = r.data;
        const summary = [
          d.trialDate && `Tanggal ${formatDate(String(d.trialDate))}`,
          d.validationDate && `Tanggal ${formatDate(String(d.validationDate))}`,
          d.machine && `Mesin ${String(d.machine)}`,
          d.trialResult && `Result ${String(d.trialResult)}`,
          d.material && `Material ${String(d.material)}`,
          d.materialReceived && `Diterima ${String(d.materialReceived)}`,
          d.quantity && `Qty ${String(d.quantity)}`,
          d.productionQuantity && `Qty produksi ${String(d.productionQuantity)}`,
          d.supplier && `Supplier ${String(d.supplier)}`,
          d.requiredDate && `Required ${formatDate(String(d.requiredDate))}`,
          Array.isArray(d.tests) && d.tests.length && `Test: ${(d.tests as string[]).join(', ')}`,
        ].filter(Boolean);
        return (
          <li key={r.id} className="px-5 py-4">
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-[14px] font-semibold text-ink">{r.number}</span>
              <span className="text-[13px] text-ink-2">· {label[r.recordType]} · {r.processName}</span>
              {r.outcomeLabel && <Badge tone={r.outcome?.includes('ng') || r.outcome === 'fail' || r.outcome === 'not_approved' ? 'red' : 'green'} size="sm">{r.outcomeLabel}</Badge>}
              {r.purchasingStatus && <Badge tone={PURCHASING_STATUS_TONE[r.purchasingStatus]} size="sm">Purchasing: {PURCHASING_STATUS_LABEL[r.purchasingStatus]}</Badge>}
            </div>
            <p className="mt-1 text-[13px] text-ink-2">{summary.join(' · ') || '—'}</p>
            {(d.problem || d.evaluation || r.comment) && <p className="mt-1 text-[13px] text-ink">{[d.problem && `Problem: ${String(d.problem)}`, d.evaluation && `Evaluasi: ${String(d.evaluation)}`, r.comment].filter(Boolean).join(' — ')}</p>}
            <p className="mt-1 text-[12px] text-ink-3">
              {r.createdBy} · {formatDateTime(r.createdAt)} · iterasi {r.iteration}
            </p>
            {r.recordType === 'material_request' && canUpdatePurchasing(user) && detail.item.project.status !== 'cancelled' && (
              <div className="mt-2 flex items-center gap-2">
                <label htmlFor={`ps-${r.id}`} className="text-[12px] text-ink-3">
                  Purchasing status
                </label>
                <Select
                  id={`ps-${r.id}`}
                  value={r.purchasingStatus}
                  className="h-8 w-40 text-[13px]"
                  onChange={(e) =>
                    mutation.mutate(
                      { id: r.id, status: e.target.value as PurchasingStatus },
                      { onSuccess: () => toast.success('Purchasing status diperbarui'), onError: (err) => toast.fromError(err) },
                    )
                  }
                >
                  {(Object.keys(PURCHASING_STATUS_LABEL) as PurchasingStatus[]).map((s) => (
                    <option key={s} value={s}>
                      {PURCHASING_STATUS_LABEL[s]}
                    </option>
                  ))}
                </Select>
                {mutation.isPending && <Spinner className="size-4 text-ink-3" />}
              </div>
            )}
          </li>
        );
      })}
    </ul>
  );
}

function ActivityTab({ detail }: { detail: ProjectDetail }) {
  const user = useUser();
  const toast = useToast();
  const [body, setBody] = useState('');
  const [filter, setFilter] = useState<'all' | 'comment' | 'workflow' | 'document'>('all');
  const mutation = useAction(() => addComment(detail.item.project.id, body));
  const items = useMemo(
    () =>
      detail.timeline.filter((t) => {
        if (filter === 'all') return true;
        if (filter === 'comment') return t.kind === 'comment';
        if (filter === 'document') return t.type.startsWith('document');
        return t.kind === 'activity' && !t.type.startsWith('document');
      }),
    [detail.timeline, filter],
  );
  return (
    <div className="px-5 py-2">
      {canComment(user) && (
        <form
          className="mb-5"
          onSubmit={(e) => {
            e.preventDefault();
            if (!body.trim()) return;
            mutation.mutate(undefined, { onSuccess: () => setBody(''), onError: (err) => toast.fromError(err) });
          }}
        >
          <label htmlFor="comment-box" className="sr-only">
            Tambah komentar
          </label>
          <Textarea id="comment-box" rows={2} value={body} onChange={(e) => setBody(e.target.value)} placeholder="Tulis update atau catatan untuk tim…" maxLength={2000} />
          <div className="mt-2 flex items-center justify-between">
            <span className="text-[12px] text-ink-3">{body.length}/2000</span>
            <Button type="submit" size="sm" variant="primary" disabled={!body.trim()} loading={mutation.isPending}>
              Kirim komentar
            </Button>
          </div>
        </form>
      )}
      <div className="mb-4 flex flex-wrap gap-1.5" role="group" aria-label="Filter activity">
        {(
          [
            ['all', 'Semua'],
            ['workflow', 'Workflow & status'],
            ['document', 'Dokumen'],
            ['comment', 'Komentar'],
          ] as const
        ).map(([k, l]) => (
          <button key={k} type="button" aria-pressed={filter === k} onClick={() => setFilter(k)} className={cn('h-8 rounded-full px-3 text-[12px] font-medium', filter === k ? 'bg-ink text-surface' : 'bg-surface-2 text-ink-2 hover:text-ink')}>
            {l}
          </button>
        ))}
      </div>
      {items.length === 0 ? (
        <EmptyState compact title="Belum ada aktivitas" />
      ) : (
        <ol className="relative space-y-4 border-l border-line pl-5">
          {items.map((t) => (
            <li key={t.id} className="relative">
              <span className={cn('absolute top-1.5 -left-[25px] size-2.5 rounded-full ring-4 ring-surface', t.kind === 'comment' ? 'bg-mark-blue' : t.type.includes('loop') || t.type.includes('problem') ? 'bg-mark-orange' : t.type.includes('approval') ? 'bg-mark-yellow' : 'bg-mark-gray')} aria-hidden="true" />
              {t.kind === 'comment' ? (
                <div className="rounded-xl bg-surface-2 px-3 py-2">
                  <p className="text-[12px] font-medium text-ink">{t.user}</p>
                  <p className="mt-0.5 text-[14px] whitespace-pre-line text-ink">{t.message}</p>
                </div>
              ) : (
                <>
                  <p className="text-[14px] text-ink">{t.message}</p>
                  {t.detail && <p className="mt-0.5 text-[13px] whitespace-pre-line text-ink-2">{t.detail}</p>}
                </>
              )}
              <p className="mt-0.5 text-[12px] text-ink-3">
                {t.kind === 'activity' && `${t.user} · `}
                {formatDateTime(t.at)}
                {t.processName && ` · ${t.processName}`}
              </p>
            </li>
          ))}
        </ol>
      )}
    </div>
  );
}

function SummaryDialog({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const [state, setState] = useState<{ answer?: AssistantAnswer; error?: unknown }>({});
  useEffect(() => {
    let cancelled = false;
    assistant.summarizeProject(projectId).then(
      (answer) => !cancelled && setState({ answer }),
      (error) => !cancelled && setState({ error }),
    );
    return () => {
      cancelled = true;
    };
  }, [projectId]);
  return (
    <Modal open onClose={onClose} size="md" title="Ringkasan AI" description="Disusun dari data project di sistem — tanpa data fiktif.">
      {state.answer ? <AnswerView answer={state.answer} onNavigate={onClose} /> : state.error ? <ErrorState error={state.error} compact /> : <div className="flex justify-center py-10"><Spinner className="size-6 text-ink-3" /></div>}
    </Modal>
  );
}

