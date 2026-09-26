import { useState } from 'react';
import type { Tone } from '@/config/labels';
import { cn } from '@/lib/utils';
import { TONE_VAR } from '../ui/tone';

export interface BarDatum {
  key: string;
  label: string;
  value: number;
  tone?: Tone;
  hint?: string;
  onClick?: () => void;
}

/**
 * Horizontal bar list — single series magnitude by category.
 * Thin bars (≤ 12px), rounded data-end, value at the tip, hover tooltip.
 */
export function BarList({ data, max: maxProp, emptyText = 'Belum ada data', unit, label }: { data: BarDatum[]; max?: number; emptyText?: string; unit?: string; label: string }) {
  const [hover, setHover] = useState<string | null>(null);
  const max = maxProp ?? Math.max(1, ...data.map((d) => d.value));
  const total = data.reduce((s, d) => s + d.value, 0);
  if (data.length === 0) return <p className="py-6 text-center text-[13px] text-ink-3">{emptyText}</p>;
  return (
    <ul className="space-y-2.5" aria-label={label}>
      {data.map((d) => {
        const pct = (d.value / max) * 100;
        const share = total ? Math.round((d.value / total) * 100) : 0;
        const Inner = (
          <>
            <div className="mb-1 flex items-baseline justify-between gap-3">
              <span className="truncate text-[13px] text-ink">{d.label}</span>
              <span className="tabular shrink-0 text-[13px] font-medium text-ink">
                {d.value}
                {unit && <span className="ml-0.5 text-[12px] font-normal text-ink-3">{unit}</span>}
              </span>
            </div>
            <div className="relative h-2.5 w-full">
              <div
                className="absolute inset-y-0 left-0 rounded-r-[4px] transition-[width,opacity] duration-500"
                style={{ width: `${Math.max(pct, d.value > 0 ? 1.5 : 0)}%`, background: TONE_VAR[d.tone ?? 'blue'], opacity: hover && hover !== d.key ? 0.45 : 1 }}
              />
            </div>
            {hover === d.key && (
              <span role="tooltip" className="pointer-events-none absolute -top-8 right-0 z-10 rounded-md bg-ink px-2 py-1 text-[11px] whitespace-nowrap text-surface shadow-pop">
                {d.label}: {d.value}
                {unit ? ` ${unit}` : ''} {!unit && `· ${share}%`}
                {d.hint ? ` · ${d.hint}` : ''}
              </span>
            )}
          </>
        );
        return (
          <li key={d.key} className="relative" onMouseEnter={() => setHover(d.key)} onMouseLeave={() => setHover(null)}>
            {d.onClick ? (
              <button type="button" onClick={d.onClick} onFocus={() => setHover(d.key)} onBlur={() => setHover(null)} className="block w-full rounded-md text-left">
                {Inner}
              </button>
            ) : (
              <div tabIndex={0} onFocus={() => setHover(d.key)} onBlur={() => setHover(null)} className="rounded-md">
                {Inner}
              </div>
            )}
          </li>
        );
      })}
    </ul>
  );
}

/** Part-to-whole distribution: one stacked bar (2px surface gaps) + legend with counts. */
export function Distribution({ data, label, onSelect }: { data: BarDatum[]; label: string; onSelect?: (key: string) => void }) {
  const [hover, setHover] = useState<string | null>(null);
  const items = data.filter((d) => d.value > 0);
  const total = items.reduce((s, d) => s + d.value, 0);
  if (total === 0) return <p className="py-6 text-center text-[13px] text-ink-3">Belum ada data</p>;
  return (
    <div>
      <div className="relative flex h-3 w-full gap-[2px]" role="img" aria-label={`${label}: ${items.map((d) => `${d.label} ${d.value}`).join(', ')}`}>
        {items.map((d, i) => (
          <div
            key={d.key}
            onMouseEnter={() => setHover(d.key)}
            onMouseLeave={() => setHover(null)}
            className={cn('h-full transition-opacity', i === 0 && 'rounded-l-[4px]', i === items.length - 1 && 'rounded-r-[4px]')}
            style={{ width: `${(d.value / total) * 100}%`, background: TONE_VAR[d.tone ?? 'blue'], opacity: hover && hover !== d.key ? 0.4 : 1 }}
          />
        ))}
      </div>
      <ul className="mt-4 grid grid-cols-1 gap-y-0.5">
        {items.map((d) => {
          const content = (
            <>
              <span className="size-2.5 shrink-0 rounded-[3px]" style={{ background: TONE_VAR[d.tone ?? 'blue'] }} aria-hidden="true" />
              <span className="flex-1 truncate text-[13px] text-ink-2">{d.label}</span>
              <span className="tabular text-[13px] font-medium text-ink">{d.value}</span>
              <span className="tabular w-10 text-right text-[12px] text-ink-3">{Math.round((d.value / total) * 100)}%</span>
            </>
          );
          return (
            <li key={d.key} onMouseEnter={() => setHover(d.key)} onMouseLeave={() => setHover(null)}>
              {onSelect ? (
                <button type="button" onClick={() => onSelect(d.key)} className="flex w-full items-center gap-2 rounded-md py-1.5 text-left hover:bg-surface-2">
                  {content}
                </button>
              ) : (
                <div className="flex items-center gap-2 py-1.5">{content}</div>
              )}
            </li>
          );
        })}
      </ul>
    </div>
  );
}

/** KPI stat tile. */
export function StatTile({
  label,
  value,
  hint,
  tone,
  onClick,
  active,
}: {
  label: string;
  value: number | string;
  hint?: string;
  tone?: Tone;
  onClick?: () => void;
  active?: boolean;
}) {
  const Tag = onClick ? 'button' : 'div';
  return (
    <Tag
      type={onClick ? 'button' : undefined}
      onClick={onClick}
      aria-pressed={onClick ? active : undefined}
      className={cn(
        'group flex min-w-0 flex-col rounded-2xl border bg-surface p-4 text-left shadow-card transition-[border-color,box-shadow]',
        active ? 'border-accent ring-2 ring-[var(--c-focus)]' : 'border-line',
        onClick && 'hover:border-line-strong',
      )}
    >
      <span className="flex items-start gap-1.5 text-[12px] leading-snug text-ink-2">
        {tone && <span className="mt-[5px] size-2 shrink-0 rounded-full" style={{ background: TONE_VAR[tone] }} aria-hidden="true" />}
        <span className="min-w-0">{label}</span>
      </span>
      <span className="tabular mt-2 text-[28px] leading-none font-semibold tracking-[-0.02em] break-words text-ink">{value}</span>
      {hint && <span className="mt-1.5 line-clamp-2 text-[12px] leading-snug text-ink-3">{hint}</span>}
    </Tag>
  );
}
