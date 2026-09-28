import { ShieldAlert } from 'lucide-react';
import { Navigate, useLocation } from 'react-router';
import { Button } from '../components/ui/Button.jsx';
import { EmptyState, PageLoading } from '../components/ui/States.jsx';
import { useAuth } from '../context/contexts.js';

/** Sends anonymous users to the login page and brings them back afterwards. */
export function RequireAuth({ children }) {
  const { status } = useAuth();
  const location = useLocation();
  if (status === 'loading') {
    return (
      <div style={{ minHeight: '100vh', display: 'grid', placeItems: 'center' }}>
        <PageLoading />
      </div>
    );
  }
  if (status !== 'authenticated') {
    return <Navigate to="/login" replace state={{ from: `${location.pathname}${location.search}` }} />;
  }
  return children;
}

/** Shows a friendly message instead of a page the role may not open (the API also refuses). */
export function RequirePermission({ module, children }) {
  const { can } = useAuth();
  if (!can(module)) {
    return (
      <EmptyState
        icon={ShieldAlert}
        title="Tidak ada akses"
        text="Role Anda tidak memiliki akses ke halaman ini. Hubungi Admin bila Anda membutuhkannya."
        action={<Button to="/dashboard">Ke Dashboard</Button>}
      />
    );
  }
  return children;
}
