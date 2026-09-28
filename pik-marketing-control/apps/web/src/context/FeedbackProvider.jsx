import { CircleAlert, CircleCheck, TriangleAlert, X } from 'lucide-react';
import { useCallback, useMemo, useRef, useState } from 'react';
import { Button } from '../components/ui/Button.jsx';
import { Modal } from '../components/ui/Modal.jsx';
import { ConfirmContext, ToastContext } from './contexts.js';

const ICONS = { success: CircleCheck, error: CircleAlert, warning: TriangleAlert };

/** Toast notifications (aria-live) and confirmation dialogs for destructive actions. */
export function FeedbackProvider({ children }) {
  const [toasts, setToasts] = useState([]);
  const [confirmRequest, setConfirmRequest] = useState(null);
  const counter = useRef(0);

  const dismiss = useCallback((id) => setToasts((current) => current.filter((toast) => toast.id !== id)), []);

  const show = useCallback(
    (type, message) => {
      counter.current += 1;
      const id = counter.current;
      setToasts((current) => [...current.slice(-3), { id, type, message }]);
      setTimeout(() => dismiss(id), type === 'error' ? 6500 : 3800);
    },
    [dismiss],
  );

  const toast = useMemo(
    () => ({
      success: (message) => show('success', message),
      error: (message) => show('error', message),
      warning: (message) => show('warning', message),
    }),
    [show],
  );

  const confirm = useCallback((options) => new Promise((resolve) => setConfirmRequest({ ...options, resolve })), []);

  const closeConfirm = (result) => {
    confirmRequest?.resolve(result);
    setConfirmRequest(null);
  };

  return (
    <ToastContext.Provider value={toast}>
      <ConfirmContext.Provider value={confirm}>
        {children}
        <div className="toast-region" role="status" aria-live="polite">
          {toasts.map((item) => {
            const Icon = ICONS[item.type];
            return (
              <div key={item.id} className={`toast ${item.type}`}>
                <Icon size={18} className="toast-icon" aria-hidden="true" />
                <span>{item.message}</span>
                <button type="button" onClick={() => dismiss(item.id)} aria-label="Tutup notifikasi">
                  <X size={16} />
                </button>
              </div>
            );
          })}
        </div>
        <Modal open={Boolean(confirmRequest)} onClose={() => closeConfirm(false)} title={confirmRequest?.title ?? ''} size="narrow">
          {confirmRequest && (
            <div className="stack">
              {confirmRequest.message && <p className="text-sm muted pre-wrap">{confirmRequest.message}</p>}
              <div className="form-actions">
                <Button variant="secondary" onClick={() => closeConfirm(false)}>
                  {confirmRequest.cancelLabel ?? 'Batal'}
                </Button>
                <Button variant={confirmRequest.tone === 'danger' ? 'danger' : 'primary'} onClick={() => closeConfirm(true)} autoFocus>
                  {confirmRequest.confirmLabel ?? 'Ya, lanjutkan'}
                </Button>
              </div>
            </div>
          )}
        </Modal>
      </ConfirmContext.Provider>
    </ToastContext.Provider>
  );
}
