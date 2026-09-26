import { X } from 'lucide-react';
import { type ReactNode, useEffect, useRef } from 'react';
import { cn } from '@/lib/utils';
import { Button, IconButton } from './Button';

let openCount = 0;

function useDialog(open: boolean, onClose: () => void) {
  const ref = useRef<HTMLDialogElement>(null);
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    if (open && !el.open) {
      el.showModal();
      openCount += 1;
      document.body.style.overflow = 'hidden';
      return () => {
        if (el.open) el.close();
        openCount -= 1;
        if (openCount <= 0) {
          openCount = 0;
          document.body.style.overflow = '';
        }
      };
    }
  }, [open]);

  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    const onCancel = (e: Event) => {
      e.preventDefault();
      onCloseRef.current();
    };
    el.addEventListener('cancel', onCancel);
    return () => el.removeEventListener('cancel', onCancel);
  }, []);

  const onBackdrop = (e: React.MouseEvent<HTMLDialogElement>) => {
    if (e.target === ref.current) onCloseRef.current();
  };
  return { ref, onBackdrop };
}

interface OverlayProps {
  open: boolean;
  onClose: () => void;
  title: ReactNode;
  description?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  size?: 'sm' | 'md' | 'lg' | 'xl';
}

const MODAL_W = { sm: 'md:w-[420px]', md: 'md:w-[560px]', lg: 'md:w-[720px]', xl: 'md:w-[920px]' };

/** Centered dialog on desktop; bottom sheet (full width) on mobile. */
export function Modal({ open, onClose, title, description, children, footer, size = 'md' }: OverlayProps) {
  const { ref, onBackdrop } = useDialog(open, onClose);
  if (!open) return null;
  return (
    <dialog
      ref={ref}
      onClick={onBackdrop}
      aria-labelledby="modal-title"
      className={cn(
        'm-0 flex max-h-[92dvh] w-full max-w-none flex-col overflow-hidden bg-surface p-0 text-ink shadow-pop',
        'max-md:mt-auto max-md:rounded-t-[20px] max-md:animate-slide-up',
        'md:m-auto md:max-h-[86vh] md:rounded-2xl md:animate-rise',
        MODAL_W[size],
      )}
    >
      <div className="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
        <div className="min-w-0">
          <h2 id="modal-title" className="text-[17px] font-semibold tracking-[-0.01em]">
            {title}
          </h2>
          {description && <div className="mt-0.5 text-[13px] text-ink-2">{description}</div>}
        </div>
        <IconButton label="Tutup" size="sm" onClick={onClose} className="-mr-1">
          <X className="size-4" />
        </IconButton>
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">{children}</div>
      {footer && <div className="flex flex-wrap items-center justify-end gap-2 border-t border-line bg-surface px-5 py-3 max-md:pb-[max(12px,env(safe-area-inset-bottom))]">{footer}</div>}
    </dialog>
  );
}

/** Side drawer on desktop; full-screen sheet on mobile. */
export function Sheet({ open, onClose, title, description, children, footer, size = 'lg' }: OverlayProps) {
  const { ref, onBackdrop } = useDialog(open, onClose);
  if (!open) return null;
  const w = size === 'xl' ? 'md:w-[760px]' : size === 'lg' ? 'md:w-[620px]' : 'md:w-[480px]';
  return (
    <dialog
      ref={ref}
      onClick={onBackdrop}
      aria-labelledby="sheet-title"
      className={cn(
        'm-0 flex h-[100dvh] max-h-none w-full max-w-none flex-col overflow-hidden bg-surface p-0 text-ink shadow-pop',
        'max-md:animate-slide-up md:ml-auto md:animate-slide-in md:border-l md:border-line',
        w,
      )}
    >
      <div className="flex items-start justify-between gap-4 border-b border-line px-5 py-4 max-md:pt-[max(16px,env(safe-area-inset-top))]">
        <div className="min-w-0">
          <h2 id="sheet-title" className="text-[17px] font-semibold tracking-[-0.01em]">
            {title}
          </h2>
          {description && <div className="mt-0.5 text-[13px] text-ink-2">{description}</div>}
        </div>
        <IconButton label="Tutup" size="sm" onClick={onClose} className="-mr-1">
          <X className="size-4" />
        </IconButton>
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto">{children}</div>
      {footer && <div className="flex flex-wrap items-center justify-end gap-2 border-t border-line bg-surface px-5 py-3 max-md:pb-[max(12px,env(safe-area-inset-bottom))]">{footer}</div>}
    </dialog>
  );
}

export function ConfirmDialog({
  open,
  onClose,
  onConfirm,
  title,
  message,
  confirmLabel = 'Konfirmasi',
  destructive,
  loading,
  children,
}: {
  open: boolean;
  onClose: () => void;
  onConfirm: () => void;
  title: string;
  message: ReactNode;
  confirmLabel?: string;
  destructive?: boolean;
  loading?: boolean;
  children?: ReactNode;
}) {
  return (
    <Modal
      open={open}
      onClose={onClose}
      title={title}
      size="sm"
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={loading}>
            Batal
          </Button>
          <Button variant={destructive ? 'destructive' : 'primary'} onClick={onConfirm} loading={loading}>
            {confirmLabel}
          </Button>
        </>
      }
    >
      <div className="text-[14px] leading-relaxed text-ink-2">{message}</div>
      {children && <div className="mt-4">{children}</div>}
    </Modal>
  );
}
