import { useState } from 'react';
import { STATUS_LABEL } from '@/config/labels';
import { errorMessage, isAppError } from '@/domain/errors';
import { MANUAL_STATUSES } from '@/domain/projectCommands';
import { useAction, useLookups, useWorkflows } from '@/hooks/queries';
import {
  moveCurrentProcess,
  type ProcessView,
  type ProjectDetail,
  setProblem,
  updateNextAction,
  updateProject,
  updateStatus,
} from '@/services/api/projects';
import type { ProjectStatus } from '@/types';
import { Button } from '../ui/Button';
import { InlineAlert, Skeleton } from '../ui/Feedback';
import { Field, Input, Select, Textarea } from '../ui/Form';
import { ConfirmDialog, Modal } from '../ui/Overlay';
import { useToast } from '../ui/Toast';
import { hasClientErrors, ProjectFormFields, useProjectForm } from './ProjectForm';

type DetailProps = { detail: ProjectDetail; onClose: () => void };

const STATUS_HELP: Partial<Record<ProjectStatus, string>> = {
  on_progress: 'Project berjalan normal. Dari Hold, status kembali mengikuti current process.',
  waiting_approval: 'Wajib ada approval record berstatus Pending.',
  waiting_external: 'Wajib mengisi pihak yang ditunggu (Waiting For).',
  hold: 'Project dijeda. Proses tidak dapat dilanjutkan selama Hold.',
  cancelled: 'Project dibatalkan permanen. Tindakan ini tidak dapat dibatalkan.',
};

export function UpdateStatusDialog({ detail, onClose }: DetailProps) {
  const toast = useToast();
  const p = detail.item.project;
  const [status, setStatus] = useState<ProjectStatus>(p.status === 'hold' ? 'on_progress' : MANUAL_STATUSES.find((s) => s !== p.status)!);
  const [waitingFor, setWaitingFor] = useState(p.waitingFor ?? '');
  const [reason, setReason] = useState('');
  const [confirmCancel, setConfirmCancel] = useState(false);
  const mutation = useAction(() => updateStatus(p.id, { status, waitingFor, reason }));
  const fe = isAppError(mutation.error) ? (mutation.error.fieldErrors ?? {}) : {};

  const run = () =>
    mutation.mutate(undefined, {
      onSuccess: (res) => {
        toast.success('Status diperbarui', `${p.code}: ${STATUS_LABEL[res.status]}`);
        onClose();
      },
      onError: () => setConfirmCancel(false),
    });

  return (
    <>
      <Modal
        open
        onClose={onClose}
        size="sm"
        title="Update Status Project"
        description={`${p.code} · saat ini ${STATUS_LABEL[p.status]}`}
        footer={
          <>
            <Button variant="secondary" onClick={onClose}>
              Batal
            </Button>
            <Button variant={status === 'cancelled' ? 'destructive' : 'primary'} loading={mutation.isPending} onClick={() => (status === 'cancelled' ? setConfirmCancel(true) : run())}>
              Simpan Status
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          {mutation.error && !Object.keys(fe).length ? <InlineAlert tone="red">{errorMessage(mutation.error)}</InlineAlert> : null}
          <Field label="Status baru" htmlFor="st" helper={STATUS_HELP[status]}>
            <Select id="st" value={status} onChange={(e) => setStatus(e.target.value as ProjectStatus)}>
              {MANUAL_STATUSES.filter((s) => s !== p.status || s === 'waiting_external').map((s) => (
                <option key={s} value={s}>
                  {STATUS_LABEL[s]}
                </option>
              ))}
            </Select>
          </Field>
          {(status === 'waiting_external' || status === 'waiting_approval' || status === 'on_progress') && (
            <Field label="Waiting For" htmlFor="wf" required={status === 'waiting_external'} error={fe.waitingFor} helper={status === 'waiting_approval' ? 'Kosongkan untuk memakai approver pada approval record.' : undefined}>
              <Input id="wf" value={waitingFor} onChange={(e) => setWaitingFor(e.target.value)} invalid={!!fe.waitingFor} placeholder="mis. Supplier — PT Polyprima" />
            </Field>
          )}
          <Field label="Alasan" htmlFor="rs" required={status === 'hold' || status === 'cancelled'} error={fe.reason}>
            <Textarea id="rs" value={reason} onChange={(e) => setReason(e.target.value)} invalid={!!fe.reason} />
          </Field>
          <p className="text-[12px] text-ink-3">Overdue dihitung otomatis dari Target Finish; Completed hanya melalui proses Finish.</p>
        </div>
      </Modal>
      <ConfirmDialog
        open={confirmCancel}
        onClose={() => setConfirmCancel(false)}
        onConfirm={run}
        loading={mutation.isPending}
        destructive
        title="Batalkan project?"
        confirmLabel="Ya, batalkan project"
        message={`${p.code} · ${p.name} akan berstatus Cancelled dan workflow dihentikan. History tetap tersimpan.`}
      />
    </>
  );
}

export function NextActionDialog({ detail, onClose }: DetailProps) {
  const toast = useToast();
  const p = detail.item.project;
  const [nextAction, setNextAction] = useState(p.nextAction);
  const [due, setDue] = useState(p.nextActionDue);
  const [waitingFor, setWaitingFor] = useState(p.waitingFor ?? '');
  const mutation = useAction(() => updateNextAction(p.id, { nextAction, nextActionDue: due, waitingFor }));
  const fe = isAppError(mutation.error) ? (mutation.error.fieldErrors ?? {}) : {};
  const waitingRequired = p.status === 'waiting_external' || p.status === 'waiting_approval';
  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      title="Update Next Action"
      description={`${p.code} · ${detail.item.current?.name ?? ''}`}
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
                  toast.success('Next Action diperbarui');
                  onClose();
                },
              })
            }
          >
            Simpan
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {mutation.error && !Object.keys(fe).length ? <InlineAlert tone="red">{errorMessage(mutation.error)}</InlineAlert> : null}
        <Field label="Next Action" htmlFor="na" required error={fe.nextAction}>
          <Textarea id="na" rows={2} value={nextAction} onChange={(e) => setNextAction(e.target.value)} invalid={!!fe.nextAction} />
        </Field>
        <Field label="Next Action Due" htmlFor="nad" required error={fe.nextActionDue}>
          <Input id="nad" type="date" value={due} onChange={(e) => setDue(e.target.value)} invalid={!!fe.nextActionDue} />
        </Field>
        <Field label="Waiting For" htmlFor="nwf" required={waitingRequired} error={fe.waitingFor} helper="Pihak yang sedang ditunggu">
          <Input id="nwf" value={waitingFor} onChange={(e) => setWaitingFor(e.target.value)} invalid={!!fe.waitingFor} />
        </Field>
      </div>
    </Modal>
  );
}

export function EditProjectDialog({ detail, onClose }: DetailProps) {
  const toast = useToast();
  const lookups = useLookups();
  const workflows = useWorkflows();
  const p = detail.item.project;
  const form = useProjectForm({
    type: p.type,
    customerId: p.customerId,
    name: p.name,
    productName: p.productName,
    productDescription: p.productDescription ?? '',
    customerRequest: p.customerRequest,
    npdPicId: p.npdPicId,
    salesPicId: p.salesPicId,
    drafterId: p.drafterId,
    supplier: p.supplier ?? '',
    priority: p.priority,
    startDate: p.startDate,
    targetDate: p.targetDate,
    remarks: p.remarks ?? '',
  });
  const mutation = useAction(() => {
    const { type: _t, nextAction: _n, nextActionDue: _d, ...patch } = form.values;
    void _t;
    void _n;
    void _d;
    return updateProject(p.id, patch);
  });
  return (
    <Modal
      open
      onClose={onClose}
      size="xl"
      title="Edit Project"
      description={`${p.code} · Project ID tidak dapat diubah`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button
            variant="primary"
            loading={mutation.isPending}
            onClick={() => {
              form.setSubmitted(true);
              if (hasClientErrors(form.values)) return;
              mutation.mutate(undefined, {
                onSuccess: () => {
                  toast.success('Project diperbarui', 'Perubahan dicatat di Activity History.');
                  onClose();
                },
                onError: (e) => form.onError(e),
              });
            }}
          >
            Simpan Perubahan
          </Button>
        </>
      }
    >
      {mutation.error && !(isAppError(mutation.error) && mutation.error.fieldErrors) ? (
        <div className="mb-4">
          <InlineAlert tone="red">{errorMessage(mutation.error)}</InlineAlert>
        </div>
      ) : null}
      {lookups.data && workflows.data ? (
        <ProjectFormFields form={form} users={lookups.data.users} customers={lookups.data.customers} workflows={workflows.data} mode="edit" />
      ) : (
        <Skeleton className="h-64" />
      )}
    </Modal>
  );
}

export function MoveProcessDialog({ detail, onClose }: DetailProps) {
  const toast = useToast();
  const p = detail.item.project;
  const [target, setTarget] = useState('');
  const [reason, setReason] = useState('');
  const mutation = useAction(() => moveCurrentProcess(p.id, target, reason));
  const fe = isAppError(mutation.error) ? (mutation.error.fieldErrors ?? {}) : {};
  const options = detail.processes.filter((x) => x.id !== p.currentProcessId && x.status !== 'skipped');
  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      title="Pindahkan Current Process"
      description="Koreksi Admin. Mandatory process tidak ditandai selesai otomatis."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button
            variant="primary"
            disabled={!target}
            loading={mutation.isPending}
            onClick={() =>
              mutation.mutate(undefined, {
                onSuccess: () => {
                  toast.success('Current process dipindahkan', 'Dicatat di Activity History.');
                  onClose();
                },
              })
            }
          >
            Pindahkan
          </Button>
        </>
      }
    >
      <div className="space-y-4">
        {mutation.error && !Object.keys(fe).length ? <InlineAlert tone="red">{errorMessage(mutation.error)}</InlineAlert> : null}
        <Field label="Proses tujuan" htmlFor="mv">
          <Select id="mv" value={target} onChange={(e) => setTarget(e.target.value)}>
            <option value="">Pilih proses</option>
            {options.map((x) => (
              <option key={x.id} value={x.id}>
                {String(x.sequence).padStart(2, '0')} · {x.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Alasan" htmlFor="mvr" required error={fe.reason}>
          <Textarea id="mvr" value={reason} onChange={(e) => setReason(e.target.value)} invalid={!!fe.reason} />
        </Field>
        <InlineAlert tone="yellow">Approval yang masih Pending pada workflow akan ditutup sebagai Revision Required.</InlineAlert>
      </div>
    </Modal>
  );
}

export function ProblemDialog({ process, flag, onClose }: { process: ProcessView; flag: boolean; onClose: () => void }) {
  const toast = useToast();
  const [note, setNote] = useState('');
  const mutation = useAction(() => setProblem(process.id, flag, note));
  const fe = isAppError(mutation.error) ? (mutation.error.fieldErrors ?? {}) : {};
  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      title={flag ? 'Tandai Problem' : 'Problem Selesai'}
      description={process.name}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button
            variant={flag ? 'destructive' : 'primary'}
            loading={mutation.isPending}
            onClick={() =>
              mutation.mutate(undefined, {
                onSuccess: () => {
                  toast.success(flag ? 'Proses ditandai Problem' : 'Problem ditandai selesai');
                  onClose();
                },
              })
            }
          >
            {flag ? 'Tandai Problem' : 'Tandai Selesai'}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        {mutation.error && !Object.keys(fe).length ? <InlineAlert tone="red">{errorMessage(mutation.error)}</InlineAlert> : null}
        {!flag && process.problemNote && <InlineAlert tone="red" title="Problem">{process.problemNote}</InlineAlert>}
        <Field label={flag ? 'Deskripsi problem' : 'Tindakan penyelesaian'} htmlFor="pn" required error={fe.note}>
          <Textarea id="pn" value={note} onChange={(e) => setNote(e.target.value)} invalid={!!fe.note} placeholder={flag ? 'mis. Leak test NG 3/50 sampel' : 'mis. Parameter sealing disesuaikan, re-trial OK'} />
        </Field>
        <p className="text-[12px] text-ink-3">Problem tampil di Top Issues weekly report.</p>
      </div>
    </Modal>
  );
}

