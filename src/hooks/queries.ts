import { QueryClient, useMutation, useQuery } from '@tanstack/react-query';
import { isAppError } from '@/domain/errors';
import * as admin from '@/services/api/admin';
import * as approvals from '@/services/api/approvals';
import * as calendar from '@/services/api/calendar';
import * as documents from '@/services/api/documents';
import * as notifications from '@/services/api/notifications';
import * as projects from '@/services/api/projects';
import * as reports from '@/services/api/reports';
import type { ISODate } from '@/types';

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 15_000,
      refetchOnWindowFocus: false,
      retry: (count, error) => {
        // Only transient network errors are retried; permission/validation errors are final.
        if (isAppError(error) && error.code !== 'NETWORK') return false;
        return count < 1;
      },
    },
  },
});

export const keys = {
  projects: ['projects'] as const,
  project: (code: string) => ['project', code] as const,
  processForm: (id: string) => ['processForm', id] as const,
  documents: ['documents'] as const,
  approvals: ['approvals'] as const,
  notifications: ['notifications'] as const,
  calendar: (from: string, to: string) => ['calendar', from, to] as const,
  weekly: (start: string) => ['weekly', start] as const,
  analytics: ['analytics'] as const,
  lookups: ['lookups'] as const,
  workflows: ['workflows'] as const,
};

export const useProjects = () => useQuery({ queryKey: keys.projects, queryFn: projects.listProjects });
export const useProjectDetail = (code: string) => useQuery({ queryKey: keys.project(code), queryFn: () => projects.getProjectDetail(code) });
export const useProcessForm = (processId: string | undefined) =>
  useQuery({ queryKey: keys.processForm(processId ?? ''), queryFn: () => projects.getProcessForm(processId!), enabled: !!processId, staleTime: 0 });
export const useDocuments = () => useQuery({ queryKey: keys.documents, queryFn: documents.listDocuments });
export const useApprovals = () => useQuery({ queryKey: keys.approvals, queryFn: approvals.listApprovals });
export const useNotifications = () => useQuery({ queryKey: keys.notifications, queryFn: notifications.listNotifications, refetchInterval: 60_000 });
export const useCalendarItems = (from: ISODate, to: ISODate) => useQuery({ queryKey: keys.calendar(from, to), queryFn: () => calendar.listCalendarItems(from, to) });
export const useWeeklyReport = (start: ISODate) => useQuery({ queryKey: keys.weekly(start), queryFn: () => reports.getWeeklyReport(start) });
export const useAnalytics = () => useQuery({ queryKey: keys.analytics, queryFn: reports.getAnalytics });
export const useLookups = () => useQuery({ queryKey: keys.lookups, queryFn: admin.getLookups, staleTime: 60_000 });
export const useWorkflows = () => useQuery({ queryKey: keys.workflows, queryFn: admin.getWorkflows });

/**
 * Mutation hook. Every successful write publishes a store change which the
 * AuthProvider turns into a global query invalidation, so the UI always
 * reflects the latest state (also across browser tabs).
 */
export function useAction<TVars, TResult>(fn: (vars: TVars) => Promise<TResult>) {
  return useMutation({ mutationFn: fn });
}
