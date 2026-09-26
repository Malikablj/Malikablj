import { Search } from 'lucide-react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { PROJECT_TYPE_LABEL } from '@/config/labels';
import { useProjects } from '@/hooks/queries';
import { cn, normalize } from '@/lib/utils';
import { StatusChip } from '../project/Chips';

/** Quick project finder (⌘K / Ctrl+K or "/"). */
export function GlobalSearch({ className, autoFocus, onDone }: { className?: string; autoFocus?: boolean; onDone?: () => void }) {
  const [q, setQ] = useState('');
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  const { data } = useProjects();
  const navigate = useNavigate();
  const inputRef = useRef<HTMLInputElement>(null);
  const boxRef = useRef<HTMLDivElement>(null);
  const id = useId();

  const results = useMemo(() => {
    const n = normalize(q);
    if (!n || !data) return [];
    return data
      .filter((i) => normalize(`${i.project.code} ${i.project.name} ${i.customer} ${i.project.productName}`).includes(n))
      .slice(0, 8);
  }, [q, data]);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const target = e.target as HTMLElement;
      const typing = target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable;
      if ((e.key === 'k' && (e.metaKey || e.ctrlKey)) || (e.key === '/' && !typing)) {
        e.preventDefault();
        inputRef.current?.focus();
        setOpen(true);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  useEffect(() => {
    const onDoc = (e: MouseEvent) => {
      if (!boxRef.current?.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onDoc);
    return () => document.removeEventListener('mousedown', onDoc);
  }, []);

  const go = (code: string) => {
    navigate(`/projects/${code}`);
    setQ('');
    setOpen(false);
    inputRef.current?.blur();
    onDone?.();
  };

  return (
    <div ref={boxRef} className={cn('relative', className)}>
      <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-ink-3" aria-hidden="true" />
      <input
        ref={inputRef}
        autoFocus={autoFocus}
        type="search"
        role="combobox"
        aria-expanded={open && q.length > 0}
        aria-controls={`${id}-list`}
        aria-activedescendant={results[active] ? `${id}-opt-${active}` : undefined}
        aria-label="Cari project"
        placeholder="Cari project, kode, customer…"
        value={q}
        onChange={(e) => {
          setQ(e.target.value);
          setOpen(true);
          setActive(0);
        }}
        onFocus={() => setOpen(true)}
        onKeyDown={(e) => {
          if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((a) => Math.min(a + 1, results.length - 1));
          } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((a) => Math.max(a - 1, 0));
          } else if (e.key === 'Enter' && results[active]) {
            e.preventDefault();
            go(results[active].project.code);
          } else if (e.key === 'Escape') {
            setOpen(false);
            inputRef.current?.blur();
          }
        }}
        className="h-9 w-full rounded-[10px] border border-transparent bg-surface-2 pr-12 pl-9 text-[14px] text-ink outline-none placeholder:text-ink-3 focus:border-accent focus:bg-surface focus:ring-4 focus:ring-[var(--c-focus)] max-md:h-11 max-md:text-[16px] [&::-webkit-search-cancel-button]:hidden"
      />
      <span className="pointer-events-none absolute top-1/2 right-2.5 hidden -translate-y-1/2 rounded border border-line-strong px-1.5 text-[11px] text-ink-3 lg:block">⌘K</span>
      {open && q.length > 0 && (
        <div id={`${id}-list`} role="listbox" className="absolute top-full right-0 left-0 z-40 mt-1.5 animate-rise overflow-hidden rounded-xl border border-line bg-surface p-1 shadow-pop">
          {results.length === 0 ? (
            <p className="px-3 py-4 text-center text-[13px] text-ink-3">Tidak ada project yang cocok.</p>
          ) : (
            results.map((r, i) => (
              <button
                key={r.project.id}
                id={`${id}-opt-${i}`}
                type="button"
                role="option"
                aria-selected={i === active}
                onMouseEnter={() => setActive(i)}
                onClick={() => go(r.project.code)}
                className={cn('flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left', i === active && 'bg-surface-2')}
              >
                <div className="min-w-0 flex-1">
                  <p className="truncate text-[14px] font-medium text-ink">{r.project.name}</p>
                  <p className="truncate text-[12px] text-ink-3">
                    {r.project.code} · {r.customer} · {PROJECT_TYPE_LABEL[r.project.type]}
                  </p>
                </div>
                <StatusChip status={r.displayStatus} size="sm" />
              </button>
            ))
          )}
        </div>
      )}
    </div>
  );
}
