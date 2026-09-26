import { useEffect, useMemo, useState } from 'react';
import { Field, Input, Select, Textarea } from '@/components/ui/Form';
import { PRIORITY_LABEL } from '@/config/labels';
import { isAppError } from '@/domain/errors';
import { workflowLength } from '@/domain/planning';
import { addDays, formatDate, todayISO } from '@/lib/date';
import { cn } from '@/lib/utils';
import type { ProjectInput } from '@/services/api/projects';
import type { Customer, Priority, PublicUser, WorkflowTemplate } from '@/types';

export type ProjectFormValues = ProjectInput;

export function emptyProjectValues(type: ProjectInput['type'] = 'subcont'): ProjectFormValues {
  const today = todayISO();
  return {
    type,
    customerId: '',
    name: '',
    productName: '',
    productDescription: '',
    customerRequest: '',
    npdPicId: '',
    salesPicId: '',
    drafterId: '',
    supplier: '',
    priority: 'medium',
    startDate: today,
    targetDate: '',
    nextAction: '',
    nextActionDue: '',
    remarks: '',
  };
}

/** Client-side validation mirrors the service rules (the service validates again). */
export function useProjectForm(initial: ProjectFormValues) {
  const [values, setValues] = useState(initial);
  const [touched, setTouched] = useState<Record<string, boolean>>({});
  const [submitted, setSubmitted] = useState(false);
  const [serverErrors, setServerErrors] = useState<Record<string, string>>({});
  const set = <K extends keyof ProjectFormValues>(key: K, v: ProjectFormValues[K]) => {
    setValues((s) => ({ ...s, [key]: v }));
    setServerErrors((e) => ({ ...e, [key]: '' }));
  };
  const onError = (e: unknown) => {
    if (isAppError(e) && e.fieldErrors) setServerErrors(e.fieldErrors);
  };
  return { values, set, touched, setTouched, submitted, setSubmitted, serverErrors, onError, setValues };
}

export function ProjectFormFields({
  form,
  users,
  customers,
  workflows,
  mode,
}: {
  form: ReturnType<typeof useProjectForm>;
  users: PublicUser[];
  customers: Customer[];
  workflows: WorkflowTemplate[];
  mode: 'create' | 'edit';
}) {
  const { values, set, touched, setTouched, submitted, serverErrors } = form;
  const template = workflows.find((w) => w.projectType === values.type);
  const length = template ? workflowLength(template.processes) : 0;
  const [targetAuto, setTargetAuto] = useState(mode === 'create');

  // Suggest a target finish from the workflow template length until the user sets one.
  useEffect(() => {
    if (mode !== 'create' || !targetAuto || !values.startDate || !length) return;
    set('targetDate', addDays(values.startDate, length));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [values.startDate, values.type, length, targetAuto, mode]);

  const clientErrors = useMemo(() => validateLite(values), [values]);
  const err = (k: keyof ProjectFormValues) => serverErrors[k] || ((touched[k] || submitted) && clientErrors[k]) || undefined;
  const blur = (k: string) => () => setTouched((t) => ({ ...t, [k]: true }));
  const byRole = (roles: string[]) => users.filter((u) => u.active && roles.includes(u.role));

  return (
    <div className="space-y-8">
      {mode === 'create' && (
        <fieldset>
          <legend className="mb-3 text-[15px] font-semibold text-ink">Project Type</legend>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Project Type">
            {(['subcont', 'new_mold'] as const).map((t) => {
              const wf = workflows.find((w) => w.projectType === t);
              const on = values.type === t;
              return (
                <button
                  key={t}
                  type="button"
                  role="radio"
                  aria-checked={on}
                  onClick={() => set('type', t)}
                  className={cn('rounded-2xl border p-4 text-left transition-[border-color,box-shadow]', on ? 'border-accent ring-4 ring-[var(--c-focus)]' : 'border-line-strong hover:border-ink-3')}
                >
                  <span className="flex items-center justify-between">
                    <span className="text-[15px] font-semibold text-ink">{t === 'subcont' ? 'Subcont' : 'New Mold'}</span>
                    <span className={cn('flex size-5 items-center justify-center rounded-full border', on ? 'border-accent bg-accent' : 'border-line-strong')}>
                      {on && <span className="size-2 rounded-full bg-white" />}
                    </span>
                  </span>
                  <span className="mt-1 block text-[13px] text-ink-2">
                    {t === 'subcont' ? 'NPR → Feedback → Artwork → Customer Approval → Trial → Material → Validation' : 'Request → Feedback → Masterbatch/3D → 2D → Mold → T0 → Commissioning → Validation'}
                  </span>
                  <span className="mt-2 block text-[12px] text-ink-3">
                    {wf?.name} v{wf?.version} · {wf?.processes.length} proses otomatis
                  </span>
                </button>
              );
            })}
          </div>
        </fieldset>
      )}

      <fieldset className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <legend className="mb-3 text-[15px] font-semibold text-ink md:col-span-2">Informasi Project</legend>
        <Field label="Customer" htmlFor="customerId" required error={err('customerId')}>
          <Select id="customerId" value={values.customerId} onChange={(e) => set('customerId', e.target.value)} onBlur={blur('customerId')} invalid={!!err('customerId')}>
            <option value="">Pilih customer</option>
            {customers
              .filter((c) => c.active || c.id === values.customerId)
              .map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
          </Select>
        </Field>
        <Field label="Priority" htmlFor="priority" required error={err('priority')}>
          <Select id="priority" value={values.priority} onChange={(e) => set('priority', e.target.value as Priority)}>
            {(Object.keys(PRIORITY_LABEL) as Priority[]).map((p) => (
              <option key={p} value={p}>
                {PRIORITY_LABEL[p]}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Project Name" htmlFor="name" required error={err('name')}>
          <Input id="name" value={values.name} onChange={(e) => set('name', e.target.value)} onBlur={blur('name')} invalid={!!err('name')} placeholder="mis. Kymm Here We Glow Pink" maxLength={120} />
        </Field>
        <Field label="Product Name" htmlFor="productName" required error={err('productName')}>
          <Input id="productName" value={values.productName} onChange={(e) => set('productName', e.target.value)} onBlur={blur('productName')} invalid={!!err('productName')} placeholder="mis. Lip Tint Tube 8 ml" />
        </Field>
        <Field label="Customer Request" htmlFor="customerRequest" required error={err('customerRequest')} className="md:col-span-2">
          <Textarea id="customerRequest" value={values.customerRequest} onChange={(e) => set('customerRequest', e.target.value)} onBlur={blur('customerRequest')} invalid={!!err('customerRequest')} placeholder="Request spesifik customer: ukuran, material, warna, dekorasi…" />
        </Field>
        <Field label="Product Description" htmlFor="productDescription" helper="Opsional" className="md:col-span-2">
          <Textarea id="productDescription" rows={2} value={values.productDescription ?? ''} onChange={(e) => set('productDescription', e.target.value)} />
        </Field>
      </fieldset>

      <fieldset className="grid grid-cols-1 gap-4 md:grid-cols-3">
        <legend className="mb-3 text-[15px] font-semibold text-ink md:col-span-3">PIC</legend>
        <Field label="NPD PIC" htmlFor="npdPicId" required error={err('npdPicId')}>
          <Select id="npdPicId" value={values.npdPicId} onChange={(e) => set('npdPicId', e.target.value)} onBlur={blur('npdPicId')} invalid={!!err('npdPicId')}>
            <option value="">Pilih NPD PIC</option>
            {byRole(['npd_staff', 'admin']).map((u) => (
              <option key={u.id} value={u.id}>
                {u.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Sales PIC" htmlFor="salesPicId" required error={err('salesPicId')}>
          <Select id="salesPicId" value={values.salesPicId} onChange={(e) => set('salesPicId', e.target.value)} onBlur={blur('salesPicId')} invalid={!!err('salesPicId')}>
            <option value="">Pilih Sales PIC</option>
            {byRole(['admin_sales']).map((u) => (
              <option key={u.id} value={u.id}>
                {u.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Drafter" htmlFor="drafterId" required error={err('drafterId')}>
          <Select id="drafterId" value={values.drafterId} onChange={(e) => set('drafterId', e.target.value)} onBlur={blur('drafterId')} invalid={!!err('drafterId')}>
            <option value="">Pilih Drafter</option>
            {byRole(['drafter']).map((u) => (
              <option key={u.id} value={u.id}>
                {u.name}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Mold Maker / Supplier" htmlFor="supplier" helper="Opsional — jika relevan" className="md:col-span-3">
          <Input id="supplier" value={values.supplier ?? ''} onChange={(e) => set('supplier', e.target.value)} placeholder="mis. PT Presisi Mold Teknik" />
        </Field>
      </fieldset>

      <fieldset className="grid grid-cols-1 gap-4 md:grid-cols-2">
        <legend className="mb-3 text-[15px] font-semibold text-ink md:col-span-2">Timeline</legend>
        <Field label="Start Date" htmlFor="startDate" required error={err('startDate')}>
          <Input id="startDate" type="date" value={values.startDate} onChange={(e) => set('startDate', e.target.value)} invalid={!!err('startDate')} />
        </Field>
        <Field
          label="Target Finish"
          htmlFor="targetDate"
          required
          error={err('targetDate')}
          helper={mode === 'create' && length ? `Estimasi workflow ${length} hari${targetAuto && values.startDate ? ` → ${formatDate(addDays(values.startDate, length))}` : ''}. Planned date tiap proses disesuaikan ke target.` : undefined}
        >
          <Input
            id="targetDate"
            type="date"
            value={values.targetDate}
            min={values.startDate}
            onChange={(e) => {
              setTargetAuto(false);
              set('targetDate', e.target.value);
            }}
            invalid={!!err('targetDate')}
          />
        </Field>
        {mode === 'create' && (
          <>
            <Field label="Next Action" htmlFor="nextAction" helper={`Kosongkan untuk default: "${template?.processes[0]?.defaultNextAction ?? ''}"`}>
              <Input id="nextAction" value={values.nextAction ?? ''} onChange={(e) => set('nextAction', e.target.value)} />
            </Field>
            <Field label="Next Action Due" htmlFor="nextActionDue" helper="Kosongkan untuk mengikuti planned finish proses pertama">
              <Input id="nextActionDue" type="date" value={values.nextActionDue ?? ''} onChange={(e) => set('nextActionDue', e.target.value)} />
            </Field>
          </>
        )}
        <Field label="Remarks" htmlFor="remarks" helper="Opsional" className="md:col-span-2">
          <Textarea id="remarks" rows={2} value={values.remarks ?? ''} onChange={(e) => set('remarks', e.target.value)} />
        </Field>
      </fieldset>
    </div>
  );
}

function validateLite(v: ProjectFormValues): Record<string, string> {
  // Quick shape checks for instant feedback; reference checks (roles, duplicates) come from the service.
  const e: Record<string, string> = {};
  if (!v.customerId) e.customerId = 'Pilih customer.';
  if (!v.name.trim()) e.name = 'Project Name wajib diisi.';
  if (!v.productName.trim()) e.productName = 'Product Name wajib diisi.';
  if (!v.customerRequest.trim()) e.customerRequest = 'Customer Request wajib diisi.';
  if (!v.npdPicId) e.npdPicId = 'Pilih NPD PIC.';
  if (!v.salesPicId) e.salesPicId = 'Pilih Sales PIC.';
  if (!v.drafterId) e.drafterId = 'Pilih Drafter.';
  if (!v.startDate) e.startDate = 'Start Date wajib diisi.';
  if (!v.targetDate) e.targetDate = 'Target Finish wajib diisi.';
  else if (v.startDate && v.targetDate <= v.startDate) e.targetDate = 'Target Finish harus setelah Start Date.';
  return e;
}

export const hasClientErrors = (v: ProjectFormValues) => Object.keys(validateLite(v)).length > 0;
