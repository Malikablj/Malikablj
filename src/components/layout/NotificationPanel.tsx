import { Bell, CheckCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { NOTIFICATION_LABEL, NOTIFICATION_TONE } from '@/config/labels';
import { useAction, useNotifications, useProjects } from '@/hooks/queries';
import { formatRelative } from '@/lib/date';
import { cn } from '@/lib/utils';
import { markAllNotificationsRead, markNotificationsRead } from '@/services/api/notifications';
import { Button, IconButton } from '../ui/Button';
import { EmptyState, ErrorState, ListSkeleton } from '../ui/Feedback';
import { SegmentedControl } from '../ui/Form';
import { Sheet } from '../ui/Overlay';
import { TONE_MARK } from '../ui/tone';
import { useToast } from '../ui/Toast';

export function NotificationBell() {
  const [open, setOpen] = useState(false);
  const { data } = useNotifications();
  const unread = data?.filter((n) => !n.read).length ?? 0;
  return (
    <>
      <IconButton label={unread ? `Notifikasi, ${unread} belum dibaca` : 'Notifikasi'} onClick={() => setOpen(true)} className="relative">
        <Bell className="size-[18px]" strokeWidth={1.75} />
        {unread > 0 && (
          <span className="tabular absolute top-1 right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-mark-red px-1 text-[10px] font-semibold text-white" aria-hidden="true">
            {unread > 9 ? '9+' : unread}
          </span>
        )}
      </IconButton>
      <NotificationSheet open={open} onClose={() => setOpen(false)} />
    </>
  );
}

function NotificationSheet({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { data, isLoading, error, refetch } = useNotifications();
  const { data: projects } = useProjects();
  const [filter, setFilter] = useState<'all' | 'unread'>('unread');
  const navigate = useNavigate();
  const toast = useToast();
  const markOne = useAction(markNotificationsRead);
  const markAll = useAction(markAllNotificationsRead);
  const codeById = useMemo(() => new Map(projects?.map((p) => [p.project.id, p.project.code])), [projects]);
  const list = (data ?? []).filter((n) => filter === 'all' || !n.read);
  const unread = data?.filter((n) => !n.read).length ?? 0;

  return (
    <Sheet
      open={open}
      onClose={onClose}
      size="md"
      title="Notifikasi"
      description={unread ? `${unread} belum dibaca` : 'Semua sudah dibaca'}
    >
      <div className="flex items-center justify-between gap-2 border-b border-line px-5 py-3">
        <SegmentedControl
          label="Filter notifikasi"
          size="sm"
          value={filter}
          onChange={setFilter}
          options={[
            { value: 'unread', label: 'Belum dibaca', count: unread },
            { value: 'all', label: 'Semua', count: data?.length ?? 0 },
          ]}
        />
        <Button
          size="sm"
          variant="ghost"
          icon={<CheckCheck className="size-4" />}
          disabled={!unread}
          loading={markAll.isPending}
          onClick={() => markAll.mutate(undefined, { onError: (e) => toast.fromError(e) })}
        >
          Tandai semua dibaca
        </Button>
      </div>
      {isLoading ? (
        <div className="p-5">
          <ListSkeleton />
        </div>
      ) : error ? (
        <ErrorState error={error} onRetry={() => refetch()} compact />
      ) : list.length === 0 ? (
        <EmptyState compact icon={<Bell className="size-5" />} title={filter === 'unread' ? 'Tidak ada notifikasi baru' : 'Belum ada notifikasi'} description="Notifikasi muncul saat ada assignment, approval, revisi dokumen, dan deadline." />
      ) : (
        <ul className="divide-y divide-line">
          {list.map((n) => {
            const code = n.projectId ? codeById.get(n.projectId) : undefined;
            return (
              <li key={n.id}>
                <button
                  type="button"
                  onClick={() => {
                    if (!n.read) markOne.mutate([n.id]);
                    if (code) {
                      navigate(`/projects/${code}`);
                      onClose();
                    }
                  }}
                  className={cn('flex w-full gap-3 px-5 py-3.5 text-left transition-colors hover:bg-surface-2', !n.read && 'bg-accent-soft/40')}
                >
                  <span className={cn('mt-1.5 size-2 shrink-0 rounded-full', n.read ? 'bg-transparent' : TONE_MARK[NOTIFICATION_TONE[n.type]])} aria-hidden="true" />
                  <span className="min-w-0 flex-1">
                    <span className="flex items-baseline justify-between gap-2">
                      <span className={cn('text-[14px] text-ink', !n.read && 'font-semibold')}>{n.title}</span>
                      <span className="shrink-0 text-[11px] text-ink-3">{formatRelative(n.createdAt)}</span>
                    </span>
                    <span className="mt-0.5 block text-[13px] text-ink-2">{n.body}</span>
                    <span className="mt-1 block text-[11px] text-ink-3">
                      {NOTIFICATION_LABEL[n.type]}
                      {!n.read && <span className="sr-only"> · belum dibaca</span>}
                    </span>
                  </span>
                </button>
              </li>
            );
          })}
        </ul>
      )}
    </Sheet>
  );
}
