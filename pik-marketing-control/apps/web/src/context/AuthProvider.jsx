import { accessLevel, canRead, canWrite, canWriteRecord } from '@pik/shared';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { api, onUnauthorized } from '../services/api.js';
import { setAppTimeZone } from '../utils/format.js';
import { AuthContext } from './contexts.js';

const ANONYMOUS = { status: 'anonymous', user: null, meta: null };
const RECHECK_MS = 15 * 60 * 1000;

/**
 * Holds the signed-in user. The role used for showing/hiding actions comes from the server
 * session (/api/auth/me); the API enforces the same permissions independently.
 */
export function AuthProvider({ children }) {
  const [state, setState] = useState({ status: 'loading', user: null, meta: null });

  useEffect(() => {
    let cancelled = false;
    const check = () =>
      api
        .get('/auth/me')
        .then((response) => !cancelled && setState({ status: 'authenticated', user: response.data.user, meta: response.meta }))
        .catch(() => !cancelled && setState(ANONYMOUS));
    check();
    // Re-check periodically: keeps "today" current and notices expired sessions.
    const timer = setInterval(check, RECHECK_MS);
    const unsubscribe = onUnauthorized(() => setState(ANONYMOUS));
    return () => {
      cancelled = true;
      clearInterval(timer);
      unsubscribe();
    };
  }, []);

  useEffect(() => {
    setAppTimeZone(state.meta?.timezone);
  }, [state.meta]);

  const login = useCallback(async (email, password) => {
    const response = await api.post('/auth/login', { email, password });
    setState({ status: 'authenticated', user: response.data.user, meta: response.meta });
  }, []);

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout');
    } finally {
      setState(ANONYMOUS);
    }
  }, []);

  const value = useMemo(() => {
    const role = state.user?.role;
    return {
      ...state,
      today: state.meta?.today ?? null,
      login,
      logout,
      can: (module, action = 'read') => (role ? (action === 'write' ? canWrite(role, module) : canRead(role, module)) : false),
      access: (module) => (role ? accessLevel(role, module) : null),
      canEdit: (module, record) => canWriteRecord(state.user, module, record),
    };
  }, [state, login, logout]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
