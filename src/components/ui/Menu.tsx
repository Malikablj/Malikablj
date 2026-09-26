import { type ReactNode, useEffect, useId, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

export interface MenuItem {
  label: string;
  icon?: ReactNode;
  onSelect: () => void;
  destructive?: boolean;
  disabled?: boolean;
  hint?: string;
}

/** Dropdown menu with keyboard support (arrows, Esc, Enter). */
export function Menu({
  trigger,
  items,
  align = 'end',
  label,
}: {
  trigger: (props: { onClick: () => void; 'aria-expanded': boolean; 'aria-haspopup': 'menu'; 'aria-controls': string; id: string }) => ReactNode;
  items: MenuItem[];
  align?: 'start' | 'end';
  label: string;
}) {
  const [open, setOpen] = useState(false);
  const id = useId();
  const root = useRef<HTMLDivElement>(null);
  const itemRefs = useRef<Array<HTMLButtonElement | null>>([]);

  useEffect(() => {
    if (!open) return;
    const onDoc = (e: MouseEvent) => {
      if (!root.current?.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        setOpen(false);
        document.getElementById(`${id}-trigger`)?.focus();
      }
    };
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    requestAnimationFrame(() => itemRefs.current.find((b) => b && !b.disabled)?.focus());
    return () => {
      document.removeEventListener('mousedown', onDoc);
      document.removeEventListener('keydown', onKey);
    };
  }, [open, id]);

  const move = (from: number, dir: 1 | -1) => {
    const n = items.length;
    for (let step = 1; step <= n; step++) {
      const i = (from + dir * step + n) % n;
      if (!items[i].disabled) {
        itemRefs.current[i]?.focus();
        return;
      }
    }
  };

  return (
    <div ref={root} className="relative inline-flex">
      {trigger({ onClick: () => setOpen((o) => !o), 'aria-expanded': open, 'aria-haspopup': 'menu', 'aria-controls': `${id}-menu`, id: `${id}-trigger` })}
      {open && (
        <div
          id={`${id}-menu`}
          role="menu"
          aria-label={label}
          className={cn(
            'absolute top-full z-40 mt-1.5 min-w-[220px] animate-rise rounded-xl border border-line bg-surface p-1 shadow-pop',
            align === 'end' ? 'right-0' : 'left-0',
          )}
        >
          {items.map((item, i) => (
            <button
              key={item.label}
              ref={(el) => {
                itemRefs.current[i] = el;
              }}
              role="menuitem"
              type="button"
              disabled={item.disabled}
              onKeyDown={(e) => {
                if (e.key === 'ArrowDown') {
                  e.preventDefault();
                  move(i, 1);
                } else if (e.key === 'ArrowUp') {
                  e.preventDefault();
                  move(i, -1);
                }
              }}
              onClick={() => {
                setOpen(false);
                item.onSelect();
              }}
              className={cn(
                'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[14px] transition-colors focus:outline-none disabled:opacity-40',
                item.destructive ? 'text-tone-red hover:bg-tone-red-soft focus:bg-tone-red-soft' : 'text-ink hover:bg-surface-2 focus:bg-surface-2',
              )}
            >
              {item.icon && <span className="flex size-4 items-center justify-center text-current opacity-80">{item.icon}</span>}
              <span className="flex-1">{item.label}</span>
              {item.hint && <span className="text-[12px] text-ink-3">{item.hint}</span>}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
