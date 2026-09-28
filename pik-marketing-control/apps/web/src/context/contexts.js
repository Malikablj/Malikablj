/** React contexts and their hooks (providers live in the *Provider.jsx files). */
import { createContext, useContext } from 'react';

export const AuthContext = createContext(null);
export const ToastContext = createContext(null);
export const ConfirmContext = createContext(null);

/** Current user, login/logout and permission helpers. */
export function useAuth() {
  const value = useContext(AuthContext);
  if (!value) throw new Error('useAuth must be used inside AuthProvider');
  return value;
}

/** toast.success(message) / toast.error(message) / toast.warning(message) */
export function useToast() {
  return useContext(ToastContext);
}

/** await confirm({ title, message, confirmLabel, tone }) → boolean */
export function useConfirm() {
  return useContext(ConfirmContext);
}
