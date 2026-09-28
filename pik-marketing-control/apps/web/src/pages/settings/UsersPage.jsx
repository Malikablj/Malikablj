import { PASSWORD_MIN_LENGTH, ROLE, userCreateSchema, userResetPasswordSchema, userUpdateSchema } from '@pik/shared';
import { KeyRound, Pencil, Plus, UserCog } from 'lucide-react';
import { useState } from 'react';
import { FormActions, FormError } from '../../components/domain/CrmForms.jsx';
import { Badge } from '../../components/ui/Badge.jsx';
import { Button } from '../../components/ui/Button.jsx';
import { Card } from '../../components/ui/Card.jsx';
import { DataTable } from '../../components/ui/DataTable.jsx';
import { Checkbox, Field, Input, Select } from '../../components/ui/Field.jsx';
import { Avatar, PageHeader, SearchBar } from '../../components/ui/Misc.jsx';
import { Modal } from '../../components/ui/Modal.jsx';
import { Pagination } from '../../components/ui/Pagination.jsx';
import { useAuth, useToast } from '../../context/contexts.js';
import { useApi } from '../../hooks/useApi.js';
import { useForm } from '../../hooks/useForm.js';
import { useListParams } from '../../hooks/useListParams.js';
import { api } from '../../services/api.js';
import { formatDateTime } from '../../utils/format.js';

const PASSWORD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

/** Random password from the browser's CSPRNG (called from a click handler, never during render). */
function generatePassword(length = 14) {
  const bytes = new Uint32Array(length);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (value) => PASSWORD_ALPHABET[value % PASSWORD_ALPHABET.length]).join('');
}

function PasswordInput({ form, name }) {
  const [visible, setVisible] = useState(false);
  return (
    <div className="row" style={{ gap: 6, flexWrap: 'nowrap' }}>
      <Input type={visible ? 'text' : 'password'} autoComplete="new-password" {...form.bind(name)} />
      <Button
        size="sm"
        onClick={() => {
          form.setValue(name, generatePassword());
          setVisible(true);
        }}
      >
        Buat acak
      </Button>
    </div>
  );
}

function UserForm({ user, onSaved, onCancel }) {
  const toast = useToast();
  const form = useForm({
    name: user?.name ?? '',
    email: user?.email ?? '',
    role: user?.role ?? 'SALES',
    password: '',
    is_active: user?.is_active ?? true,
  });
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(
      user ? userUpdateSchema : userCreateSchema,
      async (data) => {
        const response = user ? await api.put(`/users/${user.id}`, data) : await api.post('/users', data);
        toast.success(user ? 'Pengguna disimpan.' : 'Pengguna ditambahkan. Sampaikan password awal secara langsung kepada pengguna.');
        onSaved(response.data);
      },
      ({ password, ...values }) => (user ? values : { ...values, password }),
    );
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      <div className="form-grid">
        <Field label="Nama" required error={form.errors.name}>
          <Input {...form.bind('name')} autoFocus />
        </Field>
        <Field label="Email" required error={form.errors.email}>
          <Input type="email" autoComplete="off" {...form.bind('email')} />
        </Field>
        <Field label="Role" required error={form.errors.role}>
          <Select options={ROLE.options} {...form.bind('role')} />
        </Field>
        {!user && (
          <Field label="Password awal" required error={form.errors.password} hint={`Minimal ${PASSWORD_MIN_LENGTH} karakter`}>
            <PasswordInput form={form} name="password" />
          </Field>
        )}
        <Checkbox
          label="Aktif (dapat login)"
          checked={Boolean(form.values.is_active)}
          onChange={(event) => form.setValue('is_active', event.target.checked)}
        />
      </div>
      {user && (
        <p className="text-sm muted" style={{ margin: 0 }}>
          Menonaktifkan pengguna atau mengubah role akan mengeluarkan semua sesi login pengguna tersebut.
        </p>
      )}
      <FormActions onCancel={onCancel} submitting={form.submitting} />
    </form>
  );
}

function ResetPasswordForm({ user, onDone, onCancel }) {
  const toast = useToast();
  const form = useForm({ password: '' });
  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(userResetPasswordSchema, async (data) => {
      await api.post(`/users/${user.id}/reset-password`, data);
      toast.success(`Password ${user.name} direset. Sampaikan password baru secara langsung.`);
      onDone();
    });
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      <p className="text-sm" style={{ margin: 0 }}>
        Semua sesi login {user.name} akan dikeluarkan. Password tidak disimpan dalam bentuk asli dan tidak dapat dilihat lagi setelah form ditutup.
      </p>
      <Field label="Password baru" required error={form.errors.password} hint={`Minimal ${PASSWORD_MIN_LENGTH} karakter`}>
        <PasswordInput form={form} name="password" />
      </Field>
      <FormActions onCancel={onCancel} submitting={form.submitting} submitLabel="Reset password" />
    </form>
  );
}

export function UsersPage() {
  const { user: currentUser } = useAuth();
  const [params, setParams] = useListParams();
  const [modal, setModal] = useState(null);
  const showInactive = params.inactive === 'true';
  const sort = params.sort ?? 'name';
  const { data, meta, error, loading, reload } = useApi('/users', {
    q: params.q,
    role: params.role,
    is_active: showInactive ? undefined : 'true',
    sort,
    page: params.page,
    page_size: 50,
  });
  const close = () => setModal(null);
  const saved = () => {
    close();
    reload();
  };

  return (
    <>
      <PageHeader
        title="Pengguna"
        eyebrow="Pengaturan"
        actions={
          <Button variant="primary" onClick={() => setModal({ type: 'form' })}>
            <Plus size={16} aria-hidden="true" /> Tambah pengguna
          </Button>
        }
      />
      <div className="filter-bar">
        <SearchBar value={params.q} onChange={(q) => setParams({ q })} placeholder="Cari nama atau email…" />
        <Select aria-label="Role" placeholder="Semua role" options={ROLE.options} value={params.role ?? ''} onChange={(event) => setParams({ role: event.target.value })} />
        <Checkbox label="Tampilkan nonaktif" checked={showInactive} onChange={(event) => setParams({ inactive: event.target.checked ? 'true' : '' })} />
      </div>
      <Card>
        <DataTable
          caption="Daftar pengguna"
          rows={data}
          loading={loading}
          error={error}
          onRetry={reload}
          sort={sort}
          onSort={(next) => setParams({ sort: next })}
          empty={{ icon: UserCog, title: 'Tidak ada pengguna yang cocok' }}
          columns={[
            {
              key: 'name',
              header: 'Nama',
              sortKey: 'name',
              render: (row) => (
                <span className="row" style={{ gap: 10, flexWrap: 'nowrap' }}>
                  <Avatar name={row.name} size="sm" />
                  <span>
                    {row.name}
                    {row.id === currentUser.id && <span className="muted"> (Anda)</span>}
                  </span>
                </span>
              ),
            },
            { key: 'email', header: 'Email', sortKey: 'email' },
            { key: 'role', header: 'Role', sortKey: 'role', render: (row) => <Badge tone={ROLE.tones[row.role]}>{ROLE.labels[row.role]}</Badge> },
            { key: 'is_active', header: 'Status', render: (row) => (row.is_active ? <Badge tone="success">Aktif</Badge> : <Badge>Nonaktif</Badge>) },
            { key: 'last_login_at', header: 'Login terakhir', sortKey: 'last_login_at', render: (row) => formatDateTime(row.last_login_at) },
            {
              key: 'actions',
              header: <span className="sr-only">Aksi</span>,
              render: (row) => (
                <span className="row nowrap" style={{ gap: 2, justifyContent: 'flex-end' }}>
                  <Button size="sm" variant="ghost" icon onClick={() => setModal({ type: 'reset', user: row })} aria-label={`Reset password ${row.name}`} title="Reset password">
                    <KeyRound size={15} />
                  </Button>
                  <Button size="sm" variant="ghost" icon onClick={() => setModal({ type: 'form', user: row })} aria-label={`Ubah ${row.name}`} title="Ubah">
                    <Pencil size={15} />
                  </Button>
                </span>
              ),
            },
          ]}
          mobileCard={(row) => (
            <div className="row-between">
              <div className="grow">
                <div className="cell-title">{row.name}</div>
                <div className="cell-sub">
                  {row.email} · {ROLE.labels[row.role]}
                  {!row.is_active && ' · Nonaktif'}
                </div>
              </div>
              <Button size="sm" variant="ghost" icon onClick={() => setModal({ type: 'reset', user: row })} aria-label={`Reset password ${row.name}`}>
                <KeyRound size={15} />
              </Button>
              <Button size="sm" variant="ghost" icon onClick={() => setModal({ type: 'form', user: row })} aria-label={`Ubah ${row.name}`}>
                <Pencil size={15} />
              </Button>
            </div>
          )}
        />
        <Pagination meta={meta} onPageChange={(page) => setParams({ page }, { resetPage: false })} />
      </Card>
      <Modal open={modal?.type === 'form'} onClose={close} title={modal?.user ? `Ubah ${modal.user.name}` : 'Tambah pengguna'}>
        {modal?.type === 'form' && <UserForm user={modal.user} onCancel={close} onSaved={saved} />}
      </Modal>
      <Modal open={modal?.type === 'reset'} onClose={close} title="Reset password" size="narrow">
        {modal?.type === 'reset' && <ResetPasswordForm user={modal.user} onCancel={close} onDone={saved} />}
      </Modal>
    </>
  );
}
