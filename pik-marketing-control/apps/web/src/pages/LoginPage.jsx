import { loginSchema } from '@pik/shared';
import { Navigate, useLocation, useNavigate } from 'react-router';
import { Button } from '../components/ui/Button.jsx';
import { Field, Input } from '../components/ui/Field.jsx';
import { useAuth } from '../context/contexts.js';
import { useForm } from '../hooks/useForm.js';

export function LoginPage() {
  const { status, login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const form = useForm({ email: '', password: '' });
  const destination = location.state?.from && location.state.from !== '/login' ? location.state.from : '/dashboard';

  if (status === 'authenticated') return <Navigate to={destination} replace />;

  const onSubmit = (event) => {
    event.preventDefault();
    form.submit(loginSchema, async ({ email, password }) => {
      await login(email, password);
      navigate(destination, { replace: true });
    });
  };

  return (
    <main className="login-page">
      <div className="card login-card">
        <div className="brand">
          <span className="brand-mark" style={{ width: 44, height: 44, fontSize: 15, borderRadius: 12 }}>
            PIK
          </span>
        </div>
        <h1>PIK Marketing Control</h1>
        <p className="login-subtitle">PT Permata Indo Kemas</p>
        <form className="stack" onSubmit={onSubmit} noValidate>
          {form.formError && (
            <div className="form-alert" role="alert">
              {form.formError}
            </div>
          )}
          <Field label="Email" error={form.errors.email}>
            <Input type="email" autoComplete="username" inputMode="email" autoFocus {...form.bind('email')} />
          </Field>
          <Field label="Password" error={form.errors.password}>
            <Input type="password" autoComplete="current-password" {...form.bind('password')} />
          </Field>
          <Button type="submit" variant="primary" block loading={form.submitting}>
            Masuk
          </Button>
        </form>
      </div>
    </main>
  );
}
