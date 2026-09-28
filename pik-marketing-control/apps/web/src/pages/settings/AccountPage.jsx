import { changePasswordSchema, PASSWORD_MIN_LENGTH, ROLE } from '@pik/shared';
import { FormActions, FormError } from '../../components/domain/CrmForms.jsx';
import { Badge } from '../../components/ui/Badge.jsx';
import { Card, CardHeader } from '../../components/ui/Card.jsx';
import { Field, Input } from '../../components/ui/Field.jsx';
import { Avatar, DetailList, PageHeader } from '../../components/ui/Misc.jsx';
import { useAuth, useToast } from '../../context/contexts.js';
import { useForm } from '../../hooks/useForm.js';
import { api } from '../../services/api.js';
import { formatDateTime } from '../../utils/format.js';

const EMPTY = { current_password: '', new_password: '', confirm_password: '' };

function ChangePasswordForm() {
  const toast = useToast();
  const form = useForm(EMPTY);
  const onSubmit = (event) => {
    event.preventDefault();
    if (form.values.new_password !== form.values.confirm_password) {
      form.setErrors({ confirm_password: 'Konfirmasi password tidak sama.' });
      return;
    }
    form.submit(
      changePasswordSchema,
      async (data) => {
        await api.post('/auth/change-password', data);
        toast.success('Password diganti. Sesi di perangkat lain telah dikeluarkan.');
        form.setValues(EMPTY);
      },
      ({ confirm_password: _c, ...values }) => values,
    );
  };
  return (
    <form className="stack" onSubmit={onSubmit} noValidate>
      <FormError message={form.formError} />
      <Field label="Password saat ini" required error={form.errors.current_password}>
        <Input type="password" autoComplete="current-password" {...form.bind('current_password')} />
      </Field>
      <Field label="Password baru" required error={form.errors.new_password} hint={`Minimal ${PASSWORD_MIN_LENGTH} karakter`}>
        <Input type="password" autoComplete="new-password" {...form.bind('new_password')} />
      </Field>
      <Field label="Ulangi password baru" required error={form.errors.confirm_password}>
        <Input type="password" autoComplete="new-password" {...form.bind('confirm_password')} />
      </Field>
      <FormActions submitting={form.submitting} submitLabel="Ganti password" />
    </form>
  );
}

export function AccountPage() {
  const { user } = useAuth();
  return (
    <>
      <PageHeader title="Akun Saya" eyebrow="Pengaturan" />
      <div className="grid-2" style={{ alignItems: 'start' }}>
        <Card>
          <CardHeader title="Profil" />
          <div className="card-body stack">
            <div className="row" style={{ gap: 12 }}>
              <Avatar name={user.name} />
              <div>
                <div className="cell-title">{user.name}</div>
                <div className="cell-sub">{user.email}</div>
              </div>
            </div>
            <DetailList
              items={[
                { label: 'Role', value: <Badge tone="info">{ROLE.labels[user.role]}</Badge> },
                { label: 'Login terakhir', value: user.last_login_at && formatDateTime(user.last_login_at) },
              ]}
            />
            <p className="text-sm muted" style={{ margin: 0 }}>
              Nama, email dan role diatur oleh Admin.
            </p>
          </div>
        </Card>
        <Card>
          <CardHeader title="Ganti password" />
          <div className="card-body">
            <ChangePasswordForm />
          </div>
        </Card>
      </div>
    </>
  );
}
