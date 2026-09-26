import type { DocRevisionOption } from '@/domain/forms';
import type { FormFieldDef, PublicUser } from '@/types';
import { ChipToggleGroup, Field, Input, Select, Textarea } from '../ui/Form';

/** Renders a process form from its field schema (workflow template). */
export function DynamicForm({
  fields,
  values,
  onChange,
  errors,
  users,
  docOptions,
  disabled,
  idPrefix = 'f',
}: {
  fields: FormFieldDef[];
  values: Record<string, unknown>;
  onChange: (key: string, value: unknown) => void;
  errors: Record<string, string>;
  users: PublicUser[];
  docOptions: Record<string, DocRevisionOption[]>;
  disabled?: boolean;
  idPrefix?: string;
}) {
  return (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
      {fields.map((f) => {
        const id = `${idPrefix}-${f.key}`;
        const v = values[f.key];
        const err = errors[f.key];
        const wide = f.type === 'textarea' || f.type === 'multiselect' || f.type === 'docRevision';
        let control: React.ReactNode;
        switch (f.type) {
          case 'textarea':
            control = <Textarea id={id} rows={2} value={(v as string) ?? ''} onChange={(e) => onChange(f.key, e.target.value)} invalid={!!err} disabled={disabled} placeholder={f.placeholder} />;
            break;
          case 'number':
            control = (
              <Input
                id={id}
                type="number"
                inputMode="decimal"
                min={0}
                value={v === undefined || v === null ? '' : String(v)}
                onChange={(e) => onChange(f.key, e.target.value === '' ? undefined : Number(e.target.value))}
                invalid={!!err}
                disabled={disabled}
              />
            );
            break;
          case 'date':
            control = <Input id={id} type="date" value={(v as string) ?? ''} onChange={(e) => onChange(f.key, e.target.value || undefined)} invalid={!!err} disabled={disabled} />;
            break;
          case 'select':
            control = (
              <Select id={id} value={(v as string) ?? ''} onChange={(e) => onChange(f.key, e.target.value || undefined)} invalid={!!err} disabled={disabled}>
                <option value="">Pilih…</option>
                {f.options?.map((o) => (
                  <option key={o}>{o}</option>
                ))}
              </Select>
            );
            break;
          case 'multiselect':
            control = (
              <ChipToggleGroup
                label={f.label}
                options={(f.options ?? []).map((o) => ({ value: o, label: o }))}
                value={Array.isArray(v) ? (v as string[]) : []}
                onChange={(next) => onChange(f.key, next)}
              />
            );
            break;
          case 'user':
            control = (
              <Select id={id} value={(v as string) ?? ''} onChange={(e) => onChange(f.key, e.target.value || undefined)} invalid={!!err} disabled={disabled}>
                <option value="">Pilih user…</option>
                {users
                  .filter((u) => u.active && (!f.role || u.role === f.role || u.id === v))
                  .map((u) => (
                    <option key={u.id} value={u.id}>
                      {u.name}
                    </option>
                  ))}
              </Select>
            );
            break;
          case 'docRevision': {
            const opts = docOptions[f.key] ?? [];
            control =
              opts.length === 0 ? (
                <p className="rounded-xl bg-tone-yellow-soft px-3 py-2.5 text-[13px] text-tone-yellow">Belum ada dokumen yang valid untuk dipilih. Upload dokumen terlebih dahulu.</p>
              ) : (
                <Select id={id} value={(v as string) ?? ''} onChange={(e) => onChange(f.key, e.target.value || undefined)} invalid={!!err} disabled={disabled}>
                  <option value="">Pilih revisi…</option>
                  {opts.map((o) => (
                    <option key={o.versionId} value={o.versionId}>
                      {o.label}
                    </option>
                  ))}
                </Select>
              );
            break;
          }
          default:
            control = <Input id={id} value={(v as string) ?? ''} onChange={(e) => onChange(f.key, e.target.value)} invalid={!!err} disabled={disabled} placeholder={f.placeholder} />;
        }
        return (
          <Field key={f.key} label={f.label} htmlFor={id} required={f.required} error={err} helper={f.helper} className={wide ? 'sm:col-span-2' : undefined}>
            {control}
          </Field>
        );
      })}
    </div>
  );
}

/** Read-only rendering of submitted process data. */
export function DataList({ fields, data, users, docLabels }: { fields: FormFieldDef[]; data: Record<string, unknown>; users: PublicUser[]; docLabels?: Map<string, string> }) {
  const shown = fields.filter((f) => {
    const v = data[f.key];
    return v !== undefined && v !== null && v !== '' && !(Array.isArray(v) && v.length === 0);
  });
  if (shown.length === 0) return <p className="text-[13px] text-ink-3">Belum ada data yang diisi.</p>;
  return (
    <dl className="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
      {shown.map((f) => {
        const v = data[f.key];
        let text: string;
        if (f.type === 'user') text = users.find((u) => u.id === v)?.name ?? String(v);
        else if (f.type === 'docRevision') text = docLabels?.get(String(v)) ?? 'Dokumen';
        else if (Array.isArray(v)) text = v.join(', ');
        else text = String(v);
        return (
          <div key={f.key} className={f.type === 'textarea' ? 'sm:col-span-2' : undefined}>
            <dt className="text-[12px] text-ink-3">{f.label}</dt>
            <dd className="mt-0.5 text-[14px] break-words whitespace-pre-line text-ink">{text}</dd>
          </div>
        );
      })}
    </dl>
  );
}
