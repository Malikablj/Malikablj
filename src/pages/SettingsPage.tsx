import { ArrowDown, ArrowUp, Lock, Pencil, Plus, RotateCcw, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PageHeader } from '@/components/layout/PageHeader';
import { Badge } from '@/components/ui/Badge';
import { Button, IconButton } from '@/components/ui/Button';
import { Card, CardHeader, InfoItem } from '@/components/ui/Card';
import { EmptyState, ErrorState, InlineAlert, ListSkeleton } from '@/components/ui/Feedback';
import { Checkbox, Field, Input, SegmentedControl, Select, Switch, Textarea } from '@/components/ui/Form';
import { Avatar } from '@/components/ui/Misc';
import { ConfirmDialog, Modal } from '@/components/ui/Overlay';
import { Tabs } from '@/components/ui/Tabs';
import { useToast } from '@/components/ui/Toast';
import { DOC_TYPE_LABEL, DOC_TYPES, PROJECT_TYPE_LABEL, ROLE_DESCRIPTION, ROLE_LABEL } from '@/config/labels';
import { errorMessage, isAppError } from '@/domain/errors';
import { canManageSettings, projectScopeLabel } from '@/domain/permissions';
import { useAction, useLookups, useProjects, useWorkflows } from '@/hooks/queries';
import { useUser } from '@/hooks/useAuth';
import { useLocalPref } from '@/hooks/useUtils';
import { formatDateTime } from '@/lib/date';
import { cn } from '@/lib/utils';
import {
  addWorkflowProcess,
  type CustomerInput,
  deleteCustomer,
  moveWorkflowProcess,
  removeWorkflowProcess,
  resetDemoData,
  saveCustomer,
  saveUser,
  updateSettings,
  updateWorkflowProcess,
  type UserInput,
  type WorkflowProcessPatch,
} from '@/services/api/admin';
import type { Customer, DocType, ProcessDef, PublicUser, Role, WorkflowTemplate } from '@/types';

type TabKey = 'profile' | 'users' | 'customers' | 'workflow' | 'system';
const ROLES = Object.keys(ROLE_LABEL) as Role[];

export default function SettingsPage() {
  const user = useUser();
  const admin = canManageSettings(user);
  const [tab, setTab] = useState<TabKey>('profile');
  return (
    <div>
      <PageHeader title="Pengaturan" description={admin ? 'Profil, user & role, master data customer, workflow template, dan sistem.' : 'Profil dan preferensi tampilan.'} />
      <Tabs
        className="mb-5"
        label="Pengaturan"
        value={tab}
        onChange={setTab}
        items={[
          { value: 'profile', label: 'Profil & Tampilan' },
          ...(admin
            ? [
                { value: 'users' as const, label: 'User & Role' },
                { value: 'customers' as const, label: 'Customer' },
                { value: 'workflow' as const, label: 'Workflow' },
                { value: 'system' as const, label: 'Sistem' },
              ]
            : []),
        ]}
      />
      {tab === 'profile' && <Profile />}
      {admin && tab === 'users' && <Users />}
      {admin && tab === 'customers' && <Customers />}
      {admin && tab === 'workflow' && <Workflows />}
      {admin && tab === 'system' && <System />}
    </div>
  );
}

function Profile() {
  const user = useUser();
  const [theme, setTheme] = useLocalPref<'light' | 'dark' | 'system'>('theme', __SANDBOX__ ? 'system' : 'light');
  useEffect(() => {
    if (theme === 'system') document.documentElement.removeAttribute('data-theme');
    else document.documentElement.setAttribute('data-theme', theme);
  }, [theme]);
  return (
    <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
      <Card>
        <CardHeader title="Profil" />
        <div className="px-5 pb-5">
          <div className="flex items-center gap-3">
            <Avatar name={user.name} size="lg" />
            <div>
              <p className="text-[16px] font-semibold text-ink">{user.name}</p>
              <p className="text-[13px] text-ink-2">{user.email}</p>
            </div>
          </div>
          <dl className="mt-5 grid grid-cols-2 gap-4">
            <InfoItem label="Role">{ROLE_LABEL[user.role]}</InfoItem>
            <InfoItem label="Jabatan">{user.title}</InfoItem>
            <InfoItem label="Akses" className="col-span-2">
              {ROLE_DESCRIPTION[user.role]}
            </InfoItem>
            <InfoItem label="Scope data project" className="col-span-2">
              {projectScopeLabel(user)}
            </InfoItem>
          </dl>
        </div>
      </Card>
      <Card>
        <CardHeader title="Tampilan" description="Preferensi disimpan di browser ini." />
        <div className="px-5 pb-5">
          <SegmentedControl
            label="Tema"
            value={theme}
            onChange={setTheme}
            options={[
              { value: 'light', label: 'Terang' },
              { value: 'dark', label: 'Gelap' },
              { value: 'system', label: 'Ikuti sistem' },
            ]}
          />
        </div>
      </Card>
    </div>
  );
}

function Users() {
  const { data, isLoading, error, refetch } = useLookups();
  const [editing, setEditing] = useState<PublicUser | 'new' | null>(null);
  if (isLoading) return <ListSkeleton />;
  if (error || !data) return <ErrorState error={error} onRetry={() => refetch()} />;
  return (
    <Card>
      <CardHeader
        title="User & Role"
        description="Permission berlaku di UI, service/API, query data, dokumen, dan AI Assistant."
        action={
          <Button variant="primary" size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>
            User baru
          </Button>
        }
      />
      <ul className="divide-y divide-line border-t border-line">
        {data.users.map((u) => (
          <li key={u.id} className="flex items-center gap-3 px-5 py-3">
            <Avatar name={u.name} />
            <div className="min-w-0 flex-1">
              <p className={cn('truncate text-[14px] font-medium', u.active ? 'text-ink' : 'text-ink-3 line-through')}>{u.name}</p>
              <p className="truncate text-[12px] text-ink-3">
                {u.email} · {u.title}
              </p>
            </div>
            <Badge tone={u.role === 'admin' ? 'blue' : u.role === 'management' ? 'gray' : 'green'} size="sm">
              {ROLE_LABEL[u.role]}
            </Badge>
            {!u.active && <Badge size="sm">Nonaktif</Badge>}
            <IconButton label={`Edit ${u.name}`} size="sm" onClick={() => setEditing(u)}>
              <Pencil className="size-4" />
            </IconButton>
          </li>
        ))}
      </ul>
      {editing && <UserDialog user={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
    </Card>
  );
}

function UserDialog({ user, onClose }: { user: PublicUser | null; onClose: () => void }) {
  const toast = useToast();
  const [v, setV] = useState<UserInput>({ name: user?.name ?? '', email: user?.email ?? '', role: user?.role ?? 'npd_staff', title: user?.title ?? '', active: user?.active ?? true, password: '' });
  const m = useAction(() => saveUser(v, user?.id));
  const fe = isAppError(m.error) ? (m.error.fieldErrors ?? {}) : {};
  return (
    <Modal
      open
      onClose={onClose}
      size="md"
      title={user ? 'Edit User' : 'User Baru'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button
            variant="primary"
            loading={m.isPending}
            onClick={() =>
              m.mutate(undefined, {
                onSuccess: (u) => {
                  toast.success(user ? 'User diperbarui' : 'User dibuat', `${u.name} · ${ROLE_LABEL[u.role]}`);
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
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {m.error && !Object.keys(fe).length ? (
          <div className="sm:col-span-2">
            <InlineAlert tone="red">{errorMessage(m.error)}</InlineAlert>
          </div>
        ) : null}
        <Field label="Nama" htmlFor="u-name" required error={fe.name}>
          <Input id="u-name" value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} invalid={!!fe.name} />
        </Field>
        <Field label="Email" htmlFor="u-email" required error={fe.email}>
          <Input id="u-email" type="email" value={v.email} onChange={(e) => setV({ ...v, email: e.target.value })} invalid={!!fe.email} />
        </Field>
        <Field label="Role" htmlFor="u-role" required helper={ROLE_DESCRIPTION[v.role]}>
          <Select id="u-role" value={v.role} onChange={(e) => setV({ ...v, role: e.target.value as Role })}>
            {ROLES.map((r) => (
              <option key={r} value={r}>
                {ROLE_LABEL[r]}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Jabatan" htmlFor="u-title">
          <Input id="u-title" value={v.title} onChange={(e) => setV({ ...v, title: e.target.value })} />
        </Field>
        <Field label={user ? 'Password baru' : 'Password'} htmlFor="u-pass" required={!user} error={fe.password} helper={user ? 'Kosongkan jika tidak diubah' : 'Minimal 6 karakter'}>
          <Input id="u-pass" type="password" autoComplete="new-password" value={v.password} onChange={(e) => setV({ ...v, password: e.target.value })} invalid={!!fe.password} />
        </Field>
        <div className="flex items-end">
          <Switch checked={v.active} onChange={(active) => setV({ ...v, active })} label="Aktif" description="User nonaktif tidak dapat login" />
        </div>
      </div>
    </Modal>
  );
}

function Customers() {
  const toast = useToast();
  const { data, isLoading, error, refetch } = useLookups();
  const projects = useProjects();
  const [editing, setEditing] = useState<Customer | 'new' | null>(null);
  const [removing, setRemoving] = useState<Customer | null>(null);
  const del = useAction((id: string) => deleteCustomer(id));
  if (isLoading) return <ListSkeleton />;
  if (error || !data) return <ErrorState error={error} onRetry={() => refetch()} />;
  const usage = (id: string) => projects.data?.filter((p) => p.project.customerId === id).length ?? 0;
  return (
    <Card>
      <CardHeader
        title="Customer"
        description="Master data customer untuk project."
        action={
          <Button variant="primary" size="sm" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>
            Customer baru
          </Button>
        }
      />
      {data.customers.length === 0 ? (
        <EmptyState title="Belum ada customer" />
      ) : (
        <ul className="divide-y divide-line border-t border-line">
          {data.customers.map((c) => (
            <li key={c.id} className="flex items-center gap-3 px-5 py-3">
              <span className="flex h-8 w-12 shrink-0 items-center justify-center rounded-lg bg-surface-2 text-[11px] font-semibold text-ink-2">{c.code}</span>
              <div className="min-w-0 flex-1">
                <p className={cn('truncate text-[14px] font-medium', c.active ? 'text-ink' : 'text-ink-3')}>{c.name}</p>
                <p className="truncate text-[12px] text-ink-3">
                  {[c.contactName, c.contactEmail].filter(Boolean).join(' · ') || '—'} · {usage(c.id)} project
                </p>
              </div>
              {!c.active && <Badge size="sm">Nonaktif</Badge>}
              <IconButton label={`Edit ${c.name}`} size="sm" onClick={() => setEditing(c)}>
                <Pencil className="size-4" />
              </IconButton>
              <IconButton label={`Hapus ${c.name}`} size="sm" onClick={() => setRemoving(c)}>
                <Trash2 className="size-4" />
              </IconButton>
            </li>
          ))}
        </ul>
      )}
      {editing && <CustomerDialog customer={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
      <ConfirmDialog
        open={!!removing}
        onClose={() => setRemoving(null)}
        destructive
        title="Hapus customer?"
        confirmLabel="Hapus"
        loading={del.isPending}
        message={removing && usage(removing.id) > 0 ? `${removing.name} digunakan oleh ${usage(removing.id)} project dan tidak dapat dihapus — nonaktifkan sebagai gantinya.` : `${removing?.name} akan dihapus dari master data.`}
        onConfirm={() =>
          del.mutate(removing!.id, {
            onSuccess: () => {
              toast.success('Customer dihapus');
              setRemoving(null);
            },
            onError: (e) => {
              toast.fromError(e, 'Customer tidak dapat dihapus');
              setRemoving(null);
            },
          })
        }
      />
    </Card>
  );
}

function CustomerDialog({ customer, onClose }: { customer: Customer | null; onClose: () => void }) {
  const toast = useToast();
  const [v, setV] = useState<CustomerInput>({ code: customer?.code ?? '', name: customer?.name ?? '', contactName: customer?.contactName ?? '', contactEmail: customer?.contactEmail ?? '', active: customer?.active ?? true });
  const m = useAction(() => saveCustomer(v, customer?.id));
  const fe = isAppError(m.error) ? (m.error.fieldErrors ?? {}) : {};
  return (
    <Modal
      open
      onClose={onClose}
      size="md"
      title={customer ? 'Edit Customer' : 'Customer Baru'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button
            variant="primary"
            loading={m.isPending}
            onClick={() =>
              m.mutate(undefined, {
                onSuccess: (c) => {
                  toast.success('Customer disimpan', c.name);
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
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {m.error && !Object.keys(fe).length ? (
          <div className="sm:col-span-2">
            <InlineAlert tone="red">{errorMessage(m.error)}</InlineAlert>
          </div>
        ) : null}
        <Field label="Nama customer" htmlFor="c-name" required error={fe.name}>
          <Input id="c-name" value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} invalid={!!fe.name} />
        </Field>
        <Field label="Kode" htmlFor="c-code" required error={fe.code} helper="2–12 huruf/angka, mis. KYM">
          <Input id="c-code" value={v.code} onChange={(e) => setV({ ...v, code: e.target.value.toUpperCase() })} invalid={!!fe.code} maxLength={12} />
        </Field>
        <Field label="Contact person" htmlFor="c-contact">
          <Input id="c-contact" value={v.contactName} onChange={(e) => setV({ ...v, contactName: e.target.value })} />
        </Field>
        <Field label="Email contact" htmlFor="c-email" error={fe.contactEmail}>
          <Input id="c-email" type="email" value={v.contactEmail} onChange={(e) => setV({ ...v, contactEmail: e.target.value })} invalid={!!fe.contactEmail} />
        </Field>
        <div className="sm:col-span-2">
          <Switch checked={v.active} onChange={(active) => setV({ ...v, active })} label="Aktif" description="Customer nonaktif tidak dapat dipilih untuk project baru" />
        </div>
      </div>
    </Modal>
  );
}

function Workflows() {
  const { data, isLoading, error, refetch } = useWorkflows();
  const [type, setType] = useState<'subcont' | 'new_mold'>('subcont');
  const [editing, setEditing] = useState<{ wf: WorkflowTemplate; proc?: ProcessDef; afterKey?: string } | null>(null);
  const toast = useToast();
  const move = useAction(({ wf, key, dir }: { wf: string; key: string; dir: -1 | 1 }) => moveWorkflowProcess(wf, key, dir));
  const remove = useAction(({ wf, key }: { wf: string; key: string }) => removeWorkflowProcess(wf, key));
  if (isLoading) return <ListSkeleton />;
  if (error || !data) return <ErrorState error={error} onRetry={() => refetch()} />;
  const wf = data.find((w) => w.projectType === type)!;
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <SegmentedControl
          label="Workflow"
          value={type}
          onChange={setType}
          options={[
            { value: 'subcont', label: 'Subcont Workflow' },
            { value: 'new_mold', label: 'New Mold Workflow' },
          ]}
        />
        <p className="text-[13px] text-ink-2">
          Versi {wf.version} · diperbarui {formatDateTime(wf.updatedAt)}
        </p>
      </div>
      <InlineAlert tone="blue">
        Perubahan berlaku untuk project {PROJECT_TYPE_LABEL[type]} yang dibuat setelah ini; project lama tetap memakai snapshot workflow versinya. Urutan & cabang proses inti (loop revisi, T0, masterbatch) mengikuti PRD dan terkunci; Admin dapat mengubah atribut proses serta menambah proses baru.
      </InlineAlert>
      <Card>
        <ol className="divide-y divide-line">
          {wf.processes.map((p, i) => (
            <li key={p.key} className="flex flex-col gap-2 px-5 py-3.5 md:flex-row md:items-center">
              <div className="min-w-0 flex-1">
                <p className="flex flex-wrap items-center gap-2 text-[14px] font-medium text-ink">
                  <span className="tabular text-ink-3">{String(i + 1).padStart(2, '0')}</span>
                  {p.name}
                  {p.kind === 'decision' && <Badge size="sm" tone="yellow">Decision</Badge>}
                  {p.loopOnly && <Badge size="sm" tone="orange">Loop</Badge>}
                  {p.branch && <Badge size="sm" tone="gray">Cabang {p.branch === '3d' ? '3D' : 'Masterbatch'}</Badge>}
                  {p.custom && <Badge size="sm" tone="blue">Custom</Badge>}
                  {!p.custom && <Lock className="size-3.5 text-ink-3" aria-label="Proses inti" />}
                </p>
                <p className="mt-0.5 text-[12px] text-ink-3">
                  PIC {ROLE_LABEL[p.picRole]} · {p.durationDays} hari · {p.isMandatory ? 'Mandatory' : 'Opsional'}
                  {p.requiresDocument && ` · Wajib dokumen: ${p.requiredDocTypes.map((t) => DOC_TYPE_LABEL[t]).join('/')}`}
                  {p.requiresApproval && ' · Approval'}
                </p>
              </div>
              <div className="flex shrink-0 items-center gap-1">
                {p.custom && (
                  <>
                    <IconButton label="Naikkan" size="sm" onClick={() => move.mutate({ wf: wf.id, key: p.key, dir: -1 }, { onError: (e) => toast.fromError(e) })}>
                      <ArrowUp className="size-4" />
                    </IconButton>
                    <IconButton label="Turunkan" size="sm" onClick={() => move.mutate({ wf: wf.id, key: p.key, dir: 1 }, { onError: (e) => toast.fromError(e) })}>
                      <ArrowDown className="size-4" />
                    </IconButton>
                    <IconButton label={`Hapus ${p.name}`} size="sm" onClick={() => remove.mutate({ wf: wf.id, key: p.key }, { onSuccess: () => toast.success('Proses dihapus dari template'), onError: (e) => toast.fromError(e) })}>
                      <Trash2 className="size-4" />
                    </IconButton>
                  </>
                )}
                <Button size="sm" variant="ghost" icon={<Pencil className="size-3.5" />} onClick={() => setEditing({ wf, proc: p })}>
                  Edit
                </Button>
                {p.kind !== 'finish' && (
                  <Button size="sm" variant="ghost" icon={<Plus className="size-3.5" />} onClick={() => setEditing({ wf, afterKey: p.key })}>
                    Tambah setelah
                  </Button>
                )}
              </div>
            </li>
          ))}
        </ol>
      </Card>
      {editing && <ProcessDefDialog wf={editing.wf} proc={editing.proc} afterKey={editing.afterKey} onClose={() => setEditing(null)} />}
    </div>
  );
}

function ProcessDefDialog({ wf, proc, afterKey, onClose }: { wf: WorkflowTemplate; proc?: ProcessDef; afterKey?: string; onClose: () => void }) {
  const toast = useToast();
  const [v, setV] = useState<Required<Pick<WorkflowProcessPatch, 'name' | 'picRole' | 'durationDays'>> & WorkflowProcessPatch>({
    name: proc?.name ?? '',
    shortName: proc?.shortName ?? '',
    description: proc?.description ?? '',
    picRole: proc?.picRole ?? 'npd_staff',
    actorRoles: proc?.actorRoles ?? ['npd_staff'],
    durationDays: proc?.durationDays ?? 3,
    isMandatory: proc?.isMandatory ?? true,
    requiresDocument: proc?.requiresDocument ?? false,
    requiredDocTypes: proc?.requiredDocTypes ?? [],
    defaultNextAction: proc?.defaultNextAction ?? '',
  });
  const m = useAction(() => (proc ? updateWorkflowProcess(wf.id, proc.key, v) : addWorkflowProcess(wf.id, afterKey!, v)));
  const fe = isAppError(m.error) ? (m.error.fieldErrors ?? {}) : {};
  const toggleRole = (r: Role) => setV((s) => ({ ...s, actorRoles: s.actorRoles!.includes(r) ? s.actorRoles!.filter((x) => x !== r) : [...s.actorRoles!, r] }));
  const toggleDoc = (t: DocType) => setV((s) => ({ ...s, requiredDocTypes: s.requiredDocTypes!.includes(t) ? s.requiredDocTypes!.filter((x) => x !== t) : [...s.requiredDocTypes!, t] }));
  return (
    <Modal
      open
      onClose={onClose}
      size="lg"
      title={proc ? `Edit proses: ${proc.name}` : 'Tambah proses'}
      description={proc ? `${wf.name} v${wf.version}` : `Setelah "${wf.processes.find((p) => p.key === afterKey)?.name}" pada ${wf.name}`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Batal
          </Button>
          <Button
            variant="primary"
            loading={m.isPending}
            onClick={() =>
              m.mutate(undefined, {
                onSuccess: (res) => {
                  toast.success('Workflow diperbarui', `${res.name} → versi ${res.version}`);
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
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {m.error && !Object.keys(fe).length ? (
          <div className="sm:col-span-2">
            <InlineAlert tone="red">{errorMessage(m.error)}</InlineAlert>
          </div>
        ) : null}
        <Field label="Nama proses" htmlFor="p-name" required error={fe.name}>
          <Input id="p-name" value={v.name} onChange={(e) => setV({ ...v, name: e.target.value })} invalid={!!fe.name} />
        </Field>
        <Field label="Nama singkat (tracker)" htmlFor="p-short">
          <Input id="p-short" value={v.shortName} onChange={(e) => setV({ ...v, shortName: e.target.value })} />
        </Field>
        <Field label="Deskripsi" htmlFor="p-desc" className="sm:col-span-2">
          <Textarea id="p-desc" rows={2} value={v.description} onChange={(e) => setV({ ...v, description: e.target.value })} />
        </Field>
        <Field label="Default PIC role" htmlFor="p-pic">
          <Select id="p-pic" value={v.picRole} onChange={(e) => setV({ ...v, picRole: e.target.value as Role })}>
            {ROLES.filter((r) => r !== 'management').map((r) => (
              <option key={r} value={r}>
                {ROLE_LABEL[r]}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Durasi plan (hari)" htmlFor="p-dur" required error={fe.durationDays}>
          <Input id="p-dur" type="number" min={1} max={365} value={v.durationDays} onChange={(e) => setV({ ...v, durationDays: Number(e.target.value) })} invalid={!!fe.durationDays} />
        </Field>
        <fieldset className="sm:col-span-2">
          <legend className="mb-1.5 text-[13px] font-medium text-ink">Role yang dapat mengerjakan</legend>
          {fe.actorRoles && <p className="mb-1 text-[12px] text-tone-red">{fe.actorRoles}</p>}
          <div className="grid grid-cols-2 gap-x-4 sm:grid-cols-4">
            {ROLES.filter((r) => r !== 'management' && r !== 'admin').map((r) => (
              <Checkbox key={r} checked={v.actorRoles!.includes(r)} onChange={() => toggleRole(r)} label={ROLE_LABEL[r]} />
            ))}
          </div>
        </fieldset>
        <Field label="Default Next Action" htmlFor="p-na" className="sm:col-span-2">
          <Input id="p-na" value={v.defaultNextAction} onChange={(e) => setV({ ...v, defaultNextAction: e.target.value })} />
        </Field>
        <div className="sm:col-span-2">
          <Switch checked={v.isMandatory!} disabled={proc?.kind === 'finish' || proc?.loopOnly} onChange={(isMandatory) => setV({ ...v, isMandatory })} label="Mandatory process" description="Project tidak dapat Finish sebelum proses mandatory selesai" />
          <Switch checked={v.requiresDocument!} onChange={(requiresDocument) => setV({ ...v, requiresDocument })} label="Wajib upload dokumen" description="Proses tidak dapat diselesaikan tanpa dokumen type terpilih" />
        </div>
        {v.requiresDocument && (
          <fieldset className="sm:col-span-2">
            <legend className="mb-1.5 text-[13px] font-medium text-ink">Document type wajib (salah satu)</legend>
            {fe.requiredDocTypes && <p className="mb-1 text-[12px] text-tone-red">{fe.requiredDocTypes}</p>}
            <div className="grid grid-cols-2 gap-x-4 sm:grid-cols-3">
              {DOC_TYPES.map((t) => (
                <Checkbox key={t} checked={v.requiredDocTypes!.includes(t)} onChange={() => toggleDoc(t)} label={DOC_TYPE_LABEL[t]} />
              ))}
            </div>
          </fieldset>
        )}
      </div>
    </Modal>
  );
}

function System() {
  const toast = useToast();
  const { data, isLoading, error, refetch } = useLookups();
  const [v, setV] = useState({ dueSoonDays: 3, noUpdateDays: 7, simulatedFailureRate: 0, simulatedLatency: true });
  const [confirmReset, setConfirmReset] = useState(false);
  const [resetting, setResetting] = useState(false);
  useEffect(() => {
    if (data) setV(data.settings);
  }, [data]);
  const m = useAction(() => updateSettings(v));
  const fe = isAppError(m.error) ? (m.error.fieldErrors ?? {}) : {};
  if (isLoading) return <ListSkeleton />;
  if (error || !data) return <ErrorState error={error} onRetry={() => refetch()} />;
  return (
    <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
      <Card>
        <CardHeader title="Threshold" description="Dipakai dashboard, attention required, notifikasi, dan AI." />
        <div className="space-y-4 px-5 pb-5">
          <Field label="Due Soon (hari sebelum target)" htmlFor="s-due" error={fe.dueSoonDays} helper="Default 3 hari">
            <Input id="s-due" type="number" min={1} max={30} value={v.dueSoonDays} onChange={(e) => setV({ ...v, dueSoonDays: Number(e.target.value) })} invalid={!!fe.dueSoonDays} />
          </Field>
          <Field label="No Update (hari tanpa aktivitas)" htmlFor="s-nu" error={fe.noUpdateDays} helper="Default 7 hari">
            <Input id="s-nu" type="number" min={1} max={60} value={v.noUpdateDays} onChange={(e) => setV({ ...v, noUpdateDays: Number(e.target.value) })} invalid={!!fe.noUpdateDays} />
          </Field>
        </div>
      </Card>
      <Card>
        <CardHeader title="Diagnostik" description="Untuk menguji loading & error state aplikasi." />
        <div className="space-y-4 px-5 pb-5">
          <Switch checked={v.simulatedLatency} onChange={(simulatedLatency) => setV({ ...v, simulatedLatency })} label="Simulasikan latency jaringan" description="120–340 ms per request" />
          <Field label="Simulasi request gagal (%)" htmlFor="s-fail" error={fe.simulatedFailureRate} helper="0 = nonaktif. Request gagal menampilkan error state + tombol coba lagi.">
            <Input id="s-fail" type="number" min={0} max={50} value={v.simulatedFailureRate} onChange={(e) => setV({ ...v, simulatedFailureRate: Number(e.target.value) })} invalid={!!fe.simulatedFailureRate} />
          </Field>
        </div>
      </Card>
      <div className="flex justify-end lg:col-span-2">
        <Button variant="primary" loading={m.isPending} onClick={() => m.mutate(undefined, { onSuccess: () => toast.success('Pengaturan disimpan'), onError: (e) => toast.fromError(e) })}>
          Simpan pengaturan
        </Button>
      </div>
      <Card className="lg:col-span-2">
        <CardHeader title="Data demo" description="Data tersimpan di browser ini (localStorage + IndexedDB). Ganti service layer untuk backend sebenarnya." />
        <div className="flex flex-wrap items-center justify-between gap-3 px-5 pb-5">
          <p className="text-[13px] text-ink-2">Reset mengembalikan 13 project contoh relatif terhadap tanggal hari ini. Semua perubahan & file upload akan dihapus.</p>
          <Button variant="destructive" icon={<RotateCcw className="size-4" />} onClick={() => setConfirmReset(true)}>
            Reset data demo
          </Button>
        </div>
      </Card>
      <ConfirmDialog
        open={confirmReset}
        onClose={() => setConfirmReset(false)}
        destructive
        loading={resetting}
        title="Reset data demo?"
        confirmLabel="Reset data"
        message="Seluruh project, dokumen, approval, dan pengaturan akan dikembalikan ke data contoh. Tindakan ini tidak dapat dibatalkan."
        onConfirm={async () => {
          setResetting(true);
          try {
            await resetDemoData();
            toast.success('Data demo direset');
          } catch (e) {
            toast.fromError(e);
          } finally {
            setResetting(false);
            setConfirmReset(false);
          }
        }}
      />
    </div>
  );
}
