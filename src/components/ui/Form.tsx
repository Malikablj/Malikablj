import { Check, ChevronDown, Search, X } from 'lucide-react';
import {
  forwardRef,
  type InputHTMLAttributes,
  type ReactNode,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
  useId,
} from 'react';
import { cn } from '@/lib/utils';

const control =
  'w-full rounded-[10px] border bg-surface text-[14px] text-ink placeholder:text-ink-3 transition-[border-color,box-shadow] outline-none focus:border-accent focus:ring-4 focus:ring-[var(--c-focus)] disabled:bg-surface-2 disabled:text-ink-3 max-md:text-[16px]';

export function Field({
  label,
  htmlFor,
  required,
  error,
  helper,
  children,
  className,
}: {
  label: ReactNode;
  htmlFor?: string;
  required?: boolean;
  error?: string;
  helper?: ReactNode;
  children: ReactNode;
  className?: string;
}) {
  return (
    <div className={cn('min-w-0', className)}>
      <label htmlFor={htmlFor} className="mb-1.5 block text-[13px] font-medium text-ink">
        {label}
        {required && (
          <span className="ml-0.5 text-tone-red" aria-hidden="true">
            *
          </span>
        )}
      </label>
      {children}
      {error ? (
        <p id={htmlFor ? `${htmlFor}-error` : undefined} className="mt-1.5 text-[12px] text-tone-red" role="alert">
          {error}
        </p>
      ) : helper ? (
        <p className="mt-1.5 text-[12px] text-ink-3">{helper}</p>
      ) : null}
    </div>
  );
}

type InputProps = InputHTMLAttributes<HTMLInputElement> & { invalid?: boolean };

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input({ invalid, className, id, ...rest }, ref) {
  return (
    <input
      ref={ref}
      id={id}
      aria-invalid={invalid || undefined}
      aria-describedby={invalid && id ? `${id}-error` : undefined}
      className={cn(control, 'h-10 px-3 max-md:h-11', invalid ? 'border-tone-red' : 'border-line-strong', className)}
      {...rest}
    />
  );
});

export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement> & { invalid?: boolean }>(function Textarea(
  { invalid, className, id, rows = 3, ...rest },
  ref,
) {
  return (
    <textarea
      ref={ref}
      id={id}
      rows={rows}
      aria-invalid={invalid || undefined}
      aria-describedby={invalid && id ? `${id}-error` : undefined}
      className={cn(control, 'resize-y px-3 py-2.5 leading-relaxed', invalid ? 'border-tone-red' : 'border-line-strong', className)}
      {...rest}
    />
  );
});

export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement> & { invalid?: boolean }>(function Select(
  { invalid, className, id, children, ...rest },
  ref,
) {
  return (
    <div className="relative">
      <select
        ref={ref}
        id={id}
        aria-invalid={invalid || undefined}
        aria-describedby={invalid && id ? `${id}-error` : undefined}
        className={cn(control, 'h-10 appearance-none pr-9 pl-3 max-md:h-11', invalid ? 'border-tone-red' : 'border-line-strong', className)}
        {...rest}
      >
        {children}
      </select>
      <ChevronDown className="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-ink-3" aria-hidden="true" />
    </div>
  );
});

export function SearchInput({
  value,
  onChange,
  placeholder = 'Cari…',
  className,
  label = 'Cari',
}: {
  value: string;
  onChange: (v: string) => void;
  placeholder?: string;
  className?: string;
  label?: string;
}) {
  return (
    <div className={cn('relative', className)}>
      <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-3" aria-hidden="true" />
      <input
        type="search"
        aria-label={label}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className={cn(control, 'h-10 border-line-strong pr-9 pl-9 max-md:h-11 [&::-webkit-search-cancel-button]:hidden')}
      />
      {value && (
        <button
          type="button"
          onClick={() => onChange('')}
          aria-label="Hapus pencarian"
          className="absolute top-1/2 right-2 flex size-7 -translate-y-1/2 items-center justify-center rounded-full text-ink-3 hover:bg-surface-2 hover:text-ink"
        >
          <X className="size-4" />
        </button>
      )}
    </div>
  );
}

export function Checkbox({ checked, onChange, label, description, disabled }: { checked: boolean; onChange: (v: boolean) => void; label: ReactNode; description?: ReactNode; disabled?: boolean }) {
  const id = useId();
  return (
    <label htmlFor={id} className={cn('flex cursor-pointer items-start gap-3 py-1', disabled && 'cursor-not-allowed opacity-50')}>
      <span className="relative mt-0.5 flex size-5 shrink-0 items-center justify-center">
        <input id={id} type="checkbox" className="peer sr-only" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
        <span className="absolute inset-0 rounded-md border border-line-strong bg-surface transition-colors peer-checked:border-accent peer-checked:bg-accent peer-focus-visible:ring-4 peer-focus-visible:ring-[var(--c-focus)]" />
        <Check className={cn('relative size-3.5 text-white transition-opacity', checked ? 'opacity-100' : 'opacity-0')} strokeWidth={3} />
      </span>
      <span className="min-w-0">
        <span className="block text-[14px] text-ink">{label}</span>
        {description && <span className="block text-[12px] text-ink-3">{description}</span>}
      </span>
    </label>
  );
}

export function Switch({ checked, onChange, label, description, disabled }: { checked: boolean; onChange: (v: boolean) => void; label: ReactNode; description?: ReactNode; disabled?: boolean }) {
  const id = useId();
  return (
    <div className="flex items-center justify-between gap-4 py-1">
      <label htmlFor={id} className="min-w-0 cursor-pointer">
        <span className="block text-[14px] text-ink">{label}</span>
        {description && <span className="block text-[12px] text-ink-3">{description}</span>}
      </label>
      <button
        id={id}
        type="button"
        role="switch"
        aria-checked={checked}
        disabled={disabled}
        onClick={() => onChange(!checked)}
        className={cn('relative h-[26px] w-[44px] shrink-0 rounded-full transition-colors', checked ? 'bg-mark-green' : 'bg-line-strong')}
      >
        <span className={cn('absolute top-[3px] left-[3px] size-5 rounded-full bg-white shadow transition-transform', checked && 'translate-x-[18px]')} />
      </button>
    </div>
  );
}

export function SegmentedControl<T extends string>({
  value,
  onChange,
  options,
  label,
  className,
  size = 'md',
}: {
  value: T;
  onChange: (v: T) => void;
  options: Array<{ value: T; label: ReactNode; count?: number }>;
  label: string;
  className?: string;
  size?: 'sm' | 'md';
}) {
  return (
    <div role="radiogroup" aria-label={label} className={cn('inline-flex max-w-full overflow-x-auto rounded-[10px] bg-surface-3/70 p-0.5 no-scrollbar', className)}>
      {options.map((o) => {
        const active = o.value === value;
        return (
          <button
            key={o.value}
            type="button"
            role="radio"
            aria-checked={active}
            onClick={() => onChange(o.value)}
            className={cn(
              'inline-flex shrink-0 items-center gap-1.5 rounded-[8px] font-medium whitespace-nowrap transition-all',
              size === 'sm' ? 'h-7 px-2.5 text-[12px]' : 'h-8 px-3 text-[13px] max-md:h-9',
              active ? 'bg-surface text-ink shadow-[0_1px_3px_rgba(0,0,0,0.1)]' : 'text-ink-2 hover:text-ink',
            )}
          >
            {o.label}
            {o.count !== undefined && <span className={cn('tabular text-[11px]', active ? 'text-ink-2' : 'text-ink-3')}>{o.count}</span>}
          </button>
        );
      })}
    </div>
  );
}

/** Single-choice pill group that wraps (for many options with counts). */
export function PillTabs<T extends string>({
  value,
  onChange,
  options,
  label,
}: {
  value: T;
  onChange: (v: T) => void;
  options: Array<{ value: T; label: ReactNode; count?: number }>;
  label: string;
}) {
  return (
    <div role="radiogroup" aria-label={label} className="flex flex-wrap gap-1.5">
      {options.map((o) => {
        const active = o.value === value;
        return (
          <button
            key={o.value}
            type="button"
            role="radio"
            aria-checked={active}
            onClick={() => onChange(o.value)}
            className={cn(
              'inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-[12px] font-medium transition-colors max-md:h-9',
              active ? 'bg-ink text-surface' : 'bg-surface-2 text-ink-2 hover:text-ink',
            )}
          >
            {o.label}
            {o.count !== undefined && <span className={cn('tabular', active ? 'text-surface/70' : 'text-ink-3')}>{o.count}</span>}
          </button>
        );
      })}
    </div>
  );
}

/** Multi-choice toggle chips (e.g. test types, calendar categories). */
export function ChipToggleGroup<T extends string>({
  options,
  value,
  onChange,
  label,
}: {
  options: Array<{ value: T; label: string; dotClass?: string }>;
  value: T[];
  onChange: (v: T[]) => void;
  label: string;
}) {
  return (
    <div role="group" aria-label={label} className="flex flex-wrap gap-1.5">
      {options.map((o) => {
        const on = value.includes(o.value);
        return (
          <button
            key={o.value}
            type="button"
            aria-pressed={on}
            onClick={() => onChange(on ? value.filter((v) => v !== o.value) : [...value, o.value])}
            className={cn(
              'inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-[12px] font-medium transition-colors max-md:h-9',
              on ? 'border-accent bg-accent-soft text-accent-ink' : 'border-line-strong bg-surface text-ink-2 hover:text-ink',
            )}
          >
            {o.dotClass && <span className={cn('size-2 rounded-full', o.dotClass)} aria-hidden="true" />}
            {o.label}
            {on && <Check className="size-3.5" />}
          </button>
        );
      })}
    </div>
  );
}
