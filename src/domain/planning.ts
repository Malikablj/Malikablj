import { addDays, diffDays } from '@/lib/date';
import type { ISODate, ProcessDef } from '@/types';

export interface PlannedWindow {
  start: ISODate;
  finish: ISODate;
}

/**
 * Total planned workflow length in days along the longest path.
 * Alternative branches (same `branchGroup`) run in the same window;
 * loop-only processes are not part of the base plan.
 */
export function workflowLength(defs: ProcessDef[]): number {
  let total = 0;
  const groups = new Map<string, Map<string, number>>();
  for (const d of defs) {
    if (d.loopOnly) continue;
    if (d.branchGroup) {
      const g = groups.get(d.branchGroup) ?? new Map<string, number>();
      g.set(d.branch ?? d.key, (g.get(d.branch ?? d.key) ?? 0) + d.durationDays);
      groups.set(d.branchGroup, g);
      continue;
    }
    total += d.durationDays;
  }
  for (const g of groups.values()) total += Math.max(...g.values());
  return total;
}

/**
 * Builds planned start/finish dates for every process. When a target date
 * is given, durations are scaled so the plan ends on the target.
 */
export function buildPlan(defs: ProcessDef[], startDate: ISODate, targetDate?: ISODate): Map<string, PlannedWindow> {
  const base = workflowLength(defs);
  const available = targetDate ? Math.max(1, diffDays(startDate, targetDate)) : base;
  const scale = base > 0 ? available / base : 1;
  const len = (d: ProcessDef) => Math.max(1, Math.round(d.durationDays * scale));

  const plan = new Map<string, PlannedWindow>();
  let cursor = startDate;
  let i = 0;
  while (i < defs.length) {
    const d = defs[i];
    if (d.branchGroup) {
      // Schedule every branch of the group from the same start.
      const groupStart = cursor;
      const branchCursor = new Map<string, ISODate>();
      let groupEnd = groupStart;
      while (i < defs.length && defs[i].branchGroup === d.branchGroup) {
        const cur = defs[i];
        const b = cur.branch ?? cur.key;
        const s = branchCursor.get(b) ?? groupStart;
        const f = addDays(s, len(cur));
        plan.set(cur.key, { start: s, finish: f });
        branchCursor.set(b, f);
        if (f > groupEnd) groupEnd = f;
        i++;
      }
      cursor = groupEnd;
      continue;
    }
    if (d.loopOnly) {
      // Loop-only work is planned right after the previous step without moving the cursor.
      plan.set(d.key, { start: cursor, finish: addDays(cursor, len(d)) });
      i++;
      continue;
    }
    const finish = addDays(cursor, len(d));
    plan.set(d.key, { start: cursor, finish });
    cursor = finish;
    i++;
  }
  // Keep the last window aligned with the target date when scaling rounded off.
  if (targetDate && defs.length) {
    const last = defs[defs.length - 1];
    const w = plan.get(last.key);
    if (w && w.finish !== targetDate && targetDate > w.start) plan.set(last.key, { start: w.start, finish: targetDate });
  }
  return plan;
}
