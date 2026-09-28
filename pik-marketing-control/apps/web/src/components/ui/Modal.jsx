import { X } from 'lucide-react';
import { useEffect, useId, useRef } from 'react';

/**
 * Modal dialog on the native <dialog> element: focus is trapped, Esc closes, background is inert.
 * Content is only rendered while open, so forms start fresh each time.
 * size: md | wide | narrow · drawer: slides in from the right (bottom sheet on phones).
 */
export function Modal({ open, onClose, title, description, size = 'md', drawer = false, children }) {
  const ref = useRef(null);
  const titleId = useId();

  useEffect(() => {
    const dialog = ref.current;
    if (!dialog) return;
    if (open && !dialog.open) dialog.showModal();
    if (!open && dialog.open) dialog.close();
  }, [open]);

  return (
    <dialog
      ref={ref}
      className={['dialog', size !== 'md' && size, drawer && 'drawer'].filter(Boolean).join(' ')}
      aria-labelledby={titleId}
      onCancel={(event) => {
        event.preventDefault();
        onClose?.();
      }}
    >
      {open && (
        <>
          <div className="dialog-header">
            <div>
              <h2 id={titleId}>{title}</h2>
              {description && <p>{description}</p>}
            </div>
            <button type="button" className="btn btn-ghost btn-icon btn-sm" onClick={onClose} aria-label="Tutup">
              <X size={18} />
            </button>
          </div>
          <div className="dialog-body">{children}</div>
        </>
      )}
    </dialog>
  );
}
