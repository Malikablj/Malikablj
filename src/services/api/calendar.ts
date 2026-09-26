import { CALENDAR_CATEGORY_LABEL } from '@/config/labels';
import { deleteEvent as deleteCmd, type EventInput, saveEvent as saveCmd } from '@/domain/adminCommands';
import { customerName, userName } from '@/domain/context';
import { isActiveProject } from '@/domain/metrics';
import { visibleProjects } from '@/domain/permissions';
import { isBetween } from '@/lib/date';
import type { CalendarCategory, ISODate } from '@/types';
import { command, query } from './client';

export interface CalendarItem {
  id: string;
  date: ISODate;
  time?: string;
  category: CalendarCategory;
  title: string;
  subtitle: string;
  projectCode?: string;
  projectName?: string;
  /** Custom (meeting / follow-up) events can be edited. */
  eventId?: string;
  notes?: string;
  createdBy?: string;
  createdById?: string;
  projectId?: string;
}

/**
 * Calendar = derived project dates (deadline, approvals, trial/T0,
 * commissioning, material arrival, validation) + user-created meetings and
 * follow-ups.
 */
export function listCalendarItems(from: ISODate, to: ISODate): Promise<CalendarItem[]> {
  return query((db, user) => {
    const projects = visibleProjects(user, db.projects);
    const ids = new Set(projects.map((p) => p.id));
    const items: CalendarItem[] = [];
    for (const p of projects) {
      const customer = customerName(db, p.customerId);
      if (isActiveProject(p) && isBetween(p.targetDate, from, to))
        items.push({ id: `dl-${p.id}`, date: p.targetDate, category: 'deadline', title: `Deadline ${p.name}`, subtitle: `${p.code} · ${customer}`, projectCode: p.code, projectName: p.name, projectId: p.id });
      if (isActiveProject(p) && p.status !== 'hold' && isBetween(p.nextActionDue, from, to))
        items.push({ id: `fu-${p.id}`, date: p.nextActionDue, category: 'follow_up', title: p.nextAction, subtitle: `${p.code} · Next Action`, projectCode: p.code, projectName: p.name, projectId: p.id });
    }
    for (const proc of db.processes) {
      if (!ids.has(proc.projectId) || !proc.calendarCategory || proc.status === 'skipped') continue;
      const p = projects.find((x) => x.id === proc.projectId)!;
      if (!isActiveProject(p) && proc.status !== 'completed') continue;
      // Actual date for completed steps, planned finish otherwise.
      const trialDate = typeof proc.data.trialDate === 'string' ? proc.data.trialDate : undefined;
      const date = proc.status === 'completed' ? (proc.actualFinish ?? proc.plannedFinish) : (trialDate ?? proc.plannedFinish);
      if (!isBetween(date, from, to)) continue;
      const done = proc.status === 'completed';
      items.push({
        id: `pr-${proc.id}`,
        date,
        category: proc.calendarCategory,
        title: `${proc.name}${done ? ' ✓' : ''}`,
        subtitle: `${p.code} · ${done ? 'Selesai' : 'Planned'} · ${customerName(db, p.customerId)}`,
        projectCode: p.code,
        projectName: p.name,
        projectId: p.id,
      });
    }
    for (const r of db.records) {
      if (!ids.has(r.projectId) || r.recordType !== 'material_request') continue;
      const d = r.data.requiredDate;
      if (typeof d !== 'string' || !isBetween(d, from, to) || r.purchasingStatus === 'received') continue;
      const p = projects.find((x) => x.id === r.projectId)!;
      items.push({ id: `mr-${r.id}`, date: d, category: 'material_arrival', title: `Material ${r.number} dibutuhkan`, subtitle: `${p.code} · ${String(r.data.material ?? '')}`, projectCode: p.code, projectName: p.name, projectId: p.id });
    }
    for (const ev of db.events) {
      if (!isBetween(ev.date, from, to)) continue;
      if (ev.projectId && !ids.has(ev.projectId)) continue;
      const p = ev.projectId ? db.projects.find((x) => x.id === ev.projectId) : undefined;
      items.push({
        id: `ev-${ev.id}`,
        eventId: ev.id,
        date: ev.date,
        time: ev.time,
        category: ev.category,
        title: ev.title,
        subtitle: [p?.code, CALENDAR_CATEGORY_LABEL[ev.category], userName(db, ev.createdById)].filter(Boolean).join(' · '),
        projectCode: p?.code,
        projectName: p?.name,
        projectId: p?.id,
        notes: ev.notes,
        createdBy: userName(db, ev.createdById),
        createdById: ev.createdById,
      });
    }
    return items.sort((a, b) => a.date.localeCompare(b.date) || (a.time ?? '99').localeCompare(b.time ?? '99'));
  });
}

export const saveEvent = (input: EventInput, eventId?: string) => command((db, ctx) => saveCmd(db, ctx, input, eventId));
export const deleteEvent = (eventId: string) => command((db, ctx) => deleteCmd(db, ctx, eventId));
export type { EventInput };
