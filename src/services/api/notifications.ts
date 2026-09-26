import { scanDeadlines } from '@/domain/notificationScan';
import type { AppNotification } from '@/types';
import { command, query } from './client';

export function listNotifications(): Promise<AppNotification[]> {
  return query((db, user) =>
    db.notifications
      .filter((n) => n.userId === user.id)
      .sort((a, b) => b.createdAt.localeCompare(a.createdAt))
      .slice(0, 100),
  );
}

export const markNotificationsRead = (ids: string[]) =>
  command((db, ctx) => {
    for (const n of db.notifications) if (n.userId === ctx.actor.id && ids.includes(n.id)) n.read = true;
  });

export const markAllNotificationsRead = () =>
  command((db, ctx) => {
    for (const n of db.notifications) if (n.userId === ctx.actor.id) n.read = true;
  });

/** Generates time-based notifications (deadline, overdue, next action, missing documents). */
export const runDeadlineScan = () => command((db, ctx) => scanDeadlines(db, ctx));
