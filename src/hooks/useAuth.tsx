import { useQueryClient } from '@tanstack/react-query';
import { createContext, type ReactNode, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import * as auth from '@/services/api/auth';
import { runDeadlineScan } from '@/services/api/notifications';
import { getDb, subscribe } from '@/services/storage/database';
import type { PublicUser } from '@/types';

interface AuthState {
  user: PublicUser | null;
  login: (email: string, password: string) => Promise<PublicUser>;
  logout: () => void;
}

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<PublicUser | null>(() => auth.restoreSession());
  const qc = useQueryClient();

  // Time-based notifications are generated whenever a session starts.
  useEffect(() => {
    if (user) runDeadlineScan().then(() => qc.invalidateQueries({ queryKey: ['notifications'] })).catch(() => undefined);
  }, [user, qc]);

  // Keep the session user in sync when an Admin edits users (or another tab changes data).
  useEffect(
    () =>
      subscribe(() => {
        setUser((current) => {
          if (!current) return current;
          const fresh = getDb().users.find((u) => u.id === current.id);
          if (!fresh || !fresh.active) {
            auth.logout();
            return null;
          }
          const { password: _p, ...pub } = fresh;
          void _p;
          return JSON.stringify(pub) === JSON.stringify(current) ? current : pub;
        });
        qc.invalidateQueries();
      }),
    [qc],
  );

  const login = useCallback(
    async (email: string, password: string) => {
      const u = await auth.login(email, password);
      qc.clear();
      setUser(u);
      return u;
    },
    [qc],
  );

  const logout = useCallback(() => {
    auth.logout();
    qc.clear();
    setUser(null);
  }, [qc]);

  const value = useMemo(() => ({ user, login, logout }), [user, login, logout]);
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}

/** For pages rendered inside the authenticated shell. */
export function useUser(): PublicUser {
  const { user } = useAuth();
  if (!user) throw new Error('No authenticated user');
  return user;
}
