import { AlertTriangle, CheckCircle2, Info, X } from 'lucide-react';
import { createContext, type ReactNode, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { errorMessage, isAppError } from '@/domain/errors';
import { cn, uid } from '@/lib/utils';

type ToastTone = 'success' | 'error' | 'info';
interface ToastItem {
  id: string;
  tone: ToastTone;
  title: string;
  description?: string;
  details?: string[];
}

interface ToastApi {
  success: (title: string, description?: string) => void;
  error: (title: string, description?: string, details?: string[]) => void;
  info: (title: string, description?: string) => void;
  /** Shows an error toast from any thrown value. */
  fromError: (e: unknown, title?: string) => void;
}

const ToastContext = createContext<ToastApi | null>(null);

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([]);
  const timers = useRef(new Map<string, number>());

  const dismiss = useCallback((id: string) => {
    setItems((list) => list.filter((t) => t.id !== id));
    const t = timers.current.get(id);
    if (t) window.clearTimeout(t);
    timers.current.delete(id);
  }, []);

  const push = useCallback(
    (t: Omit<ToastItem, 'id'>) => {
      const id = uid('toast');
      setItems((list) => [...list.slice(-3), { ...t, id }]);
      timers.current.set(id, window.setTimeout(() => dismiss(id), t.tone === 'error' ? 7000 : 4000));
    },
    [dismiss],
  );

  const api = useMemo<ToastApi>(
    () => ({
      success: (title, description) => push({ tone: 'success', title, description }),
      error: (title, description, details) => push({ tone: 'error', title, description, details }),
      info: (title, description) => push({ tone: 'info', title, description }),
      fromError: (e, title) => {
        const msg = errorMessage(e);
        const details = isAppError(e) ? e.details : undefined;
        push({ tone: 'error', title: title ?? msg, description: title ? msg : undefined, details });
      },
    }),
    [push],
  );

  // Toasts live in the top layer (popover) so they stay visible above open modal dialogs.
  const regionRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const el = regionRef.current as (HTMLDivElement & { showPopover?: () => void; hidePopover?: () => void }) | null;
    if (!el?.showPopover) return;
    try {
      if (el.matches(':popover-open')) el.hidePopover!();
      if (items.length) el.showPopover();
    } catch {
      /* popover unsupported: falls back to fixed positioning */
    }
  }, [items]);

  return (
    <ToastContext.Provider value={api}>
      {children}
      <div
        ref={regionRef}
        popover="manual"
        aria-live="polite"
        aria-atomic="false"
        className="pointer-events-none fixed inset-x-0 top-auto bottom-0 z-[60] m-0 flex h-auto w-full max-w-none flex-col items-center gap-2 overflow-visible border-0 bg-transparent p-4 max-md:pb-[max(16px,env(safe-area-inset-bottom))] md:right-4 md:bottom-4 md:left-auto md:w-auto md:items-end [&:not(:popover-open)]:hidden"
      >
        {items.map((t) => (
          <div
            key={t.id}
            role={t.tone === 'error' ? 'alert' : 'status'}
            className="pointer-events-auto flex w-full max-w-[380px] animate-rise items-start gap-3 rounded-2xl border border-line bg-surface p-3.5 shadow-pop"
          >
            <span className={cn('mt-0.5', t.tone === 'success' ? 'text-tone-green' : t.tone === 'error' ? 'text-tone-red' : 'text-tone-blue')}>
              {t.tone === 'success' ? <CheckCircle2 className="size-5" /> : t.tone === 'error' ? <AlertTriangle className="size-5" /> : <Info className="size-5" />}
            </span>
            <div className="min-w-0 flex-1">
              <p className="text-[14px] font-semibold text-ink">{t.title}</p>
              {t.description && <p className="mt-0.5 text-[13px] text-ink-2">{t.description}</p>}
              {t.details && t.details.length > 0 && (
                <ul className="mt-1.5 space-y-0.5 text-[12px] text-ink-2">
                  {t.details.map((d) => (
                    <li key={d}>• {d}</li>
                  ))}
                </ul>
              )}
            </div>
            <button type="button" onClick={() => dismiss(t.id)} aria-label="Tutup notifikasi" className="-m-1 rounded-full p-1 text-ink-3 hover:bg-surface-2 hover:text-ink">
              <X className="size-4" />
            </button>
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  );
}

export function useToast(): ToastApi {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error('useToast must be used within ToastProvider');
  return ctx;
}
