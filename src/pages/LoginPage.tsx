import { ArrowRight, Eye, EyeOff } from 'lucide-react';
import { type FormEvent, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { BrandMark } from '@/components/layout/Sidebar';
import { Button } from '@/components/ui/Button';
import { InlineAlert } from '@/components/ui/Feedback';
import { Field, Input } from '@/components/ui/Form';
import { Avatar } from '@/components/ui/Misc';
import { ROLE_DESCRIPTION, ROLE_LABEL } from '@/config/labels';
import { errorMessage, isAppError } from '@/domain/errors';
import { useAuth } from '@/hooks/useAuth';
import { useDocumentTitle } from '@/hooks/useUtils';
import { demoAccounts } from '@/services/api/auth';

export function LoginPage() {
  useDocumentTitle('Login');
  const { login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const from = (location.state as { from?: string } | null)?.from ?? '/';
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [show, setShow] = useState(false);
  const [loading, setLoading] = useState<string | null>(null);
  const [error, setError] = useState<unknown>(null);
  const accounts = useMemo(() => demoAccounts(), []);
  const fieldErrors = isAppError(error) ? error.fieldErrors : undefined;

  const submit = async (e?: FormEvent, override?: { email: string; password: string }) => {
    e?.preventDefault();
    const creds = override ?? { email, password };
    setLoading(override ? creds.email : 'form');
    setError(null);
    try {
      await login(creds.email, creds.password);
      navigate(from, { replace: true });
    } catch (err) {
      setError(err);
      setLoading(null);
    }
  };

  return (
    <div className="grid min-h-dvh bg-canvas lg:grid-cols-[1fr_minmax(480px,560px)]">
      <section className="hidden flex-col justify-between bg-surface p-12 lg:flex">
        <BrandMark />
        <div className="max-w-lg">
          <p className="text-[13px] font-medium text-ink-3">One project = one digital record</p>
          <h1 className="mt-3 text-[44px] leading-[1.08] font-semibold tracking-[-0.03em] text-ink">
            Posisi setiap project NPD, jelas dalam satu tempat.
          </h1>
          <p className="mt-5 text-[16px] leading-relaxed text-ink-2">
            Current process, PIC, waiting for, next action, approval customer, dokumen & revisi — untuk project New Mold dan Subcont.
          </p>
          <dl className="mt-10 grid grid-cols-3 gap-6 border-t border-line pt-6">
            {[
              ['2', 'Workflow template'],
              ['9', 'Approval type'],
              ['8', 'Role & permission'],
            ].map(([n, l]) => (
              <div key={l}>
                <dt className="text-[12px] text-ink-3">{l}</dt>
                <dd className="mt-1 text-[28px] font-semibold tracking-tight text-ink">{n}</dd>
              </div>
            ))}
          </dl>
        </div>
        <p className="text-[12px] text-ink-3">Internal Web Application · Packaging Manufacturing · New Product Development</p>
      </section>

      <section className="flex flex-col justify-center px-4 py-10 sm:px-10">
        <div className="mx-auto w-full max-w-[420px]">
          <div className="mb-8 lg:hidden">
            <BrandMark />
          </div>
          <h2 className="text-[26px] font-semibold tracking-[-0.02em] text-ink">Masuk</h2>
          <p className="mt-1 text-[14px] text-ink-2">Gunakan akun perusahaan Anda.</p>

          <form onSubmit={submit} className="mt-6 space-y-4" noValidate>
            {error && !fieldErrors ? <InlineAlert tone="red">{errorMessage(error)}</InlineAlert> : null}
            <Field label="Email" htmlFor="email" error={fieldErrors?.email}>
              <Input id="email" type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} invalid={!!fieldErrors?.email} placeholder="nama@npd.local" />
            </Field>
            <Field label="Password" htmlFor="password" error={fieldErrors?.password}>
              <div className="relative">
                <Input
                  id="password"
                  type={show ? 'text' : 'password'}
                  autoComplete="current-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  invalid={!!fieldErrors?.password}
                  className="pr-11"
                />
                <button
                  type="button"
                  onClick={() => setShow((s) => !s)}
                  aria-label={show ? 'Sembunyikan password' : 'Tampilkan password'}
                  className="absolute top-1/2 right-1.5 flex size-8 -translate-y-1/2 items-center justify-center rounded-lg text-ink-3 hover:text-ink"
                >
                  {show ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                </button>
              </div>
            </Field>
            <Button type="submit" variant="primary" block size="lg" loading={loading === 'form'}>
              Masuk
            </Button>
          </form>

          <div className="mt-10">
            <div className="flex items-baseline justify-between">
              <h3 className="text-[13px] font-semibold text-ink">Akun demo per role</h3>
              <span className="text-[12px] text-ink-3">Password: demo123</span>
            </div>
            <ul className="mt-3 divide-y divide-line overflow-hidden rounded-2xl border border-line bg-surface">
              {accounts.map((u) => (
                <li key={u.id}>
                  <button
                    type="button"
                    disabled={!!loading}
                    onClick={() => submit(undefined, { email: u.email, password: 'demo123' })}
                    className="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-surface-2 disabled:opacity-60"
                  >
                    <Avatar name={u.name} />
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-[14px] font-medium text-ink">
                        {u.name} <span className="font-normal text-ink-3">· {ROLE_LABEL[u.role]}</span>
                      </span>
                      <span className="block truncate text-[12px] text-ink-3">{ROLE_DESCRIPTION[u.role]}</span>
                    </span>
                    {loading === u.email ? (
                      <span className="text-[12px] text-ink-3">Masuk…</span>
                    ) : (
                      <ArrowRight className="size-4 text-ink-3" aria-hidden="true" />
                    )}
                  </button>
                </li>
              ))}
            </ul>
          </div>
        </div>
      </section>
    </div>
  );
}
