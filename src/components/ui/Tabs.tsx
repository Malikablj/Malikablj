import { type KeyboardEvent, type ReactNode, useId, useRef } from 'react';
import { cn } from '@/lib/utils';

export interface TabItem<T extends string> {
  value: T;
  label: ReactNode;
  count?: number;
}

/** Accessible tabs (arrow keys, Home/End). Renders the tab list only; panels use `tabPanelProps`. */
export function Tabs<T extends string>({
  items,
  value,
  onChange,
  label,
  className,
}: {
  items: TabItem<T>[];
  value: T;
  onChange: (v: T) => void;
  label: string;
  className?: string;
}) {
  const id = useId();
  const refs = useRef<Array<HTMLButtonElement | null>>([]);
  const onKey = (e: KeyboardEvent, index: number) => {
    let next = index;
    if (e.key === 'ArrowRight') next = (index + 1) % items.length;
    else if (e.key === 'ArrowLeft') next = (index - 1 + items.length) % items.length;
    else if (e.key === 'Home') next = 0;
    else if (e.key === 'End') next = items.length - 1;
    else return;
    e.preventDefault();
    onChange(items[next].value);
    refs.current[next]?.focus();
  };
  return (
    <div role="tablist" aria-label={label} className={cn('flex gap-1 overflow-x-auto border-b border-line no-scrollbar', className)}>
      {items.map((t, i) => {
        const active = t.value === value;
        return (
          <button
            key={t.value}
            ref={(el) => {
              refs.current[i] = el;
            }}
            id={`${id}-tab-${t.value}`}
            role="tab"
            type="button"
            aria-selected={active}
            tabIndex={active ? 0 : -1}
            onClick={() => onChange(t.value)}
            onKeyDown={(e) => onKey(e, i)}
            className={cn(
              'relative -mb-px inline-flex h-11 shrink-0 items-center gap-1.5 px-3 text-[14px] font-medium whitespace-nowrap transition-colors',
              active ? 'text-ink' : 'text-ink-3 hover:text-ink-2',
            )}
          >
            {t.label}
            {t.count !== undefined && (
              <span className={cn('tabular rounded-full px-1.5 text-[11px]', active ? 'bg-ink text-surface' : 'bg-surface-2 text-ink-2')}>{t.count}</span>
            )}
            {active && <span className="absolute inset-x-2 bottom-0 h-[2px] rounded-full bg-ink" aria-hidden="true" />}
          </button>
        );
      })}
    </div>
  );
}
