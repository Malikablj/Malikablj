import { QueryClientProvider } from '@tanstack/react-query';
import { lazy, type ReactNode, Suspense } from 'react';
import { HashRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { AppShell } from './components/layout/AppShell';
import { PageSkeleton } from './components/ui/Feedback';
import { ToastProvider } from './components/ui/Toast';
import { queryClient } from './hooks/queries';
import { AuthProvider, useAuth } from './hooks/useAuth';
import { LoginPage } from './pages/LoginPage';

// Route-level code splitting keeps the initial bundle small.
const DashboardPage = lazy(() => import('./pages/DashboardPage'));
const ProjectsPage = lazy(() => import('./pages/ProjectsPage'));
const ProjectCreatePage = lazy(() => import('./pages/ProjectCreatePage'));
const ProjectDetailPage = lazy(() => import('./pages/ProjectDetailPage'));
const TrackerPage = lazy(() => import('./pages/TrackerPage'));
const GanttPage = lazy(() => import('./pages/GanttPage'));
const CalendarPage = lazy(() => import('./pages/CalendarPage'));
const DocumentsPage = lazy(() => import('./pages/DocumentsPage'));
const ApprovalsPage = lazy(() => import('./pages/ApprovalsPage'));
const ReportsPage = lazy(() => import('./pages/ReportsPage'));
const AssistantPage = lazy(() => import('./pages/AssistantPage'));
const SettingsPage = lazy(() => import('./pages/SettingsPage'));
const NotFoundPage = lazy(() => import('./pages/NotFoundPage'));

function RequireAuth({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  const location = useLocation();
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />;
  return <>{children}</>;
}

function AppRoutes() {
  const { user } = useAuth();
  return (
    <Routes>
      <Route path="/login" element={user ? <Navigate to="/" replace /> : <LoginPage />} />
      <Route
        element={
          <RequireAuth>
            <AppShell />
          </RequireAuth>
        }
      >
        <Route index element={<DashboardPage />} />
        <Route path="projects" element={<ProjectsPage />} />
        <Route path="projects/new" element={<ProjectCreatePage />} />
        <Route path="projects/:code" element={<ProjectDetailPage />} />
        <Route path="tracker" element={<TrackerPage />} />
        <Route path="gantt" element={<GanttPage />} />
        <Route path="calendar" element={<CalendarPage />} />
        <Route path="documents" element={<DocumentsPage />} />
        <Route path="approvals" element={<ApprovalsPage />} />
        <Route path="reports" element={<ReportsPage />} />
        <Route path="assistant" element={<AssistantPage />} />
        <Route path="settings" element={<SettingsPage />} />
        <Route path="*" element={<NotFoundPage />} />
      </Route>
    </Routes>
  );
}

export function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <ToastProvider>
        <HashRouter>
          <AuthProvider>
            <Suspense fallback={<div className="p-8"><PageSkeleton /></div>}>
              <AppRoutes />
            </Suspense>
          </AuthProvider>
        </HashRouter>
      </ToastProvider>
    </QueryClientProvider>
  );
}
