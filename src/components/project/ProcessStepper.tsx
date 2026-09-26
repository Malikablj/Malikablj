import { AlertTriangle, Check, Repeat } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { PROCESS_STATUS_LABEL } from '@/config/labels';
import { formatDateShort } from '@/lib/date';
import { cn } from '@/lib/utils';
import type { ProcessStatus } from '@/types';
import type { ProcessView } from '@/services/api/projects';

/** Tracker legend (PRD §7): ✓ Completed · ● Current · ■ Not Started · ■ Revision/Problem. */
export function StepIcon({ status, size = 'md' }: { status: ProcessStatus; size?: 'sm' | 'md' }) {
  const s = size === 'sm' ? 'size-5' : 'size-7';
  const i = size === 'sm' ? 'size-3' : 'size-3.5';
  if (status === 'completed')
    return (
      <span className={cn(s, 'flex shrink-0 items-center justify-center rounded-full bg-mark-green text-white')}>
        <Check className={i} strokeWidth={3} />
      </span>
    );
  if (status === 'current')
    return (
      <span className={cn(s, 'flex shrink-0 items-center justify-center rounded-full bg-accent ring-4 ring-accent-soft')}>
        <span className="size-2 rounded-full bg-white" />
      </span>
    );
  if (status === 'revision')
    return (
      <span className={cn(s, 'flex shrink-0 items-center justify-center rounded-full bg-mark-orange text-white ring-4 ring-tone-orange-soft')}>
        <Repeat className={i} strokeWidth={2.5} />
      </span>
    );
  if (status === 'problem')
    return (
      <span className={cn(s, 'flex shrink-0 items-center justify-center rounded-full bg-mark-red text-white ring-4 ring-tone-red-soft')}>
        <AlertTriangle className={i} strokeWidth={2.5} />
      </span>
    );
  if (status === 'skipped') return <span className={cn(s, 'shrink-0 rounded-full border-2 border-dashed border-line-strong bg-surface')} />;
  return <span className={cn(s, 'shrink-0 rounded-full border-2 border-line-strong bg-surface')} />;
}

function stepMeta(p: ProcessView): string {
  if (p.status === 'completed') return `Selesai ${formatDateShort(p.actualFinish)}`;
  if (p.status === 'skipped') return p.loopOnly ? 'Hanya saat loop' : 'Tidak dijalankan';
  if (p.status === 'current' || p.status === 'revision' || p.status === 'problem') return `Target ${formatDateShort(p.nextActionDue ?? p.plannedFinish)}`;
  if (p.loopOnly) return 'Hanya saat loop';
  return `Plan ${formatDateShort(p.plannedFinish)}`;
}

export function ProcessStepper({ processes, onSelect }: { processes: ProcessView[]; onSelect: (p: ProcessView) => void }) {
  const scroller = useRef<HTMLOListElement>(null);
  const currentRef = useRef<HTMLLIElement>(null);
  const currentIdx = processes.findIndex((p) => ['current', 'revision', 'problem'].includes(p.status));

  useEffect(() => {
    const el = currentRef.current;
    const box = scroller.current;
    if (el && box && box.scrollWidth > box.clientWidth) box.scrollTo({ left: el.offsetLeft - box.clientWidth / 2 + el.clientWidth / 2, behavior: 'smooth' });
  }, [currentIdx]);

  return (
    <>
      {/* Horizontal stepper (desktop) */}
      <ol ref={scroller} className="hidden overflow-x-auto pb-2 scrollbar-thin md:flex" aria-label="Process tracker">
        {processes.map((p, i) => {
          const active = i === currentIdx;
          const done = p.status === 'completed';
          return (
            <li key={p.id} ref={active ? currentRef : undefined} className="relative flex min-w-[128px] flex-1 flex-col items-center">
              {i > 0 && <span className={cn('absolute top-[14px] right-1/2 left-[-50%] h-[2px]', done || active ? 'bg-mark-green/60' : 'bg-line')} aria-hidden="true" />}
              <button
                type="button"
                onClick={() => onSelect(p)}
                aria-current={active ? 'step' : undefined}
                aria-label={`${String(p.sequence).padStart(2, '0')} ${p.name}: ${PROCESS_STATUS_LABEL[p.status]}`}
                className="group relative z-[1] flex w-full flex-col items-center gap-2 rounded-xl px-1.5 pb-1 text-center"
              >
                <StepIcon status={p.status} />
                <span className={cn('line-clamp-2 text-[12px] leading-snug', active ? 'font-semibold text-ink' : p.status === 'skipped' || p.status === 'not_started' ? 'text-ink-3' : 'text-ink-2', 'group-hover:text-ink')}>
                  {p.shortName}
                </span>
                <span className={cn('text-[11px]', active ? 'text-accent-ink' : 'text-ink-3')}>{stepMeta(p)}</span>
                {p.loopCount > 0 && (
                  <span className="rounded-full bg-tone-orange-soft px-1.5 text-[10px] font-medium text-tone-orange">Loop {p.loopCount}×</span>
                )}
              </button>
            </li>
          );
        })}
      </ol>

      {/* Vertical stepper (mobile) */}
      <ol className="md:hidden" aria-label="Process tracker">
        {processes.map((p, i) => {
          const active = i === currentIdx;
          return (
            <li key={p.id} className="relative flex gap-3 pb-1">
              {i < processes.length - 1 && <span className={cn('absolute top-8 bottom-0 left-[13px] w-[2px]', p.status === 'completed' ? 'bg-mark-green/60' : 'bg-line')} aria-hidden="true" />}
              <StepIcon status={p.status} />
              <button type="button" onClick={() => onSelect(p)} aria-current={active ? 'step' : undefined} className={cn('mb-2 min-h-11 flex-1 rounded-xl px-3 py-2 text-left', active ? 'bg-accent-soft' : 'hover:bg-surface-2')}>
                <span className="flex items-center justify-between gap-2">
                  <span className={cn('text-[14px]', active ? 'font-semibold text-ink' : p.status === 'skipped' || p.status === 'not_started' ? 'text-ink-3' : 'text-ink')}>
                    <span className="tabular mr-1.5 text-ink-3">{String(p.sequence).padStart(2, '0')}</span>
                    {p.name}
                  </span>
                  {p.loopCount > 0 && <span className="shrink-0 rounded-full bg-tone-orange-soft px-1.5 text-[10px] font-medium text-tone-orange">Loop {p.loopCount}×</span>}
                </span>
                <span className="mt-0.5 block text-[12px] text-ink-3">
                  {PROCESS_STATUS_LABEL[p.status]} · {stepMeta(p)}
                </span>
              </button>
            </li>
          );
        })}
      </ol>

      <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 border-t border-line pt-3 text-[12px] text-ink-3" aria-label="Legenda">
        {(['completed', 'current', 'not_started', 'revision', 'problem', 'skipped'] as ProcessStatus[]).map((s) => (
          <span key={s} className="inline-flex items-center gap-1.5">
            <StepIcon status={s} size="sm" /> {PROCESS_STATUS_LABEL[s]}
          </span>
        ))}
      </div>
    </>
  );
}
