import type { ISODate } from '@/types';

/** Date helpers working on local calendar dates as `YYYY-MM-DD` strings. */

const pad = (n: number) => String(n).padStart(2, '0');

export function toISODate(d: Date): ISODate {
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

export function parseISODate(s: ISODate): Date {
  const [y, m, d] = s.slice(0, 10).split('-').map(Number);
  return new Date(y, (m ?? 1) - 1, d ?? 1);
}

export function todayISO(): ISODate {
  return toISODate(new Date());
}

export function addDays(s: ISODate, days: number): ISODate {
  const d = parseISODate(s);
  d.setDate(d.getDate() + days);
  return toISODate(d);
}

export function addMonths(s: ISODate, months: number): ISODate {
  const d = parseISODate(s);
  d.setDate(1);
  d.setMonth(d.getMonth() + months);
  return toISODate(d);
}

/** Whole calendar days from `a` to `b` (b − a). */
export function diffDays(a: ISODate, b: ISODate): number {
  const ms = parseISODate(b).getTime() - parseISODate(a).getTime();
  return Math.round(ms / 86_400_000);
}

export function isValidISODate(s: unknown): s is ISODate {
  if (typeof s !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(s)) return false;
  const d = parseISODate(s);
  return toISODate(d) === s;
}

export function minDate(a: ISODate, b: ISODate): ISODate {
  return a <= b ? a : b;
}

export function maxDate(a: ISODate, b: ISODate): ISODate {
  return a >= b ? a : b;
}

export function startOfWeek(s: ISODate): ISODate {
  const d = parseISODate(s);
  const day = (d.getDay() + 6) % 7; // Monday = 0
  d.setDate(d.getDate() - day);
  return toISODate(d);
}

export function endOfWeek(s: ISODate): ISODate {
  return addDays(startOfWeek(s), 6);
}

export function startOfMonth(s: ISODate): ISODate {
  return `${s.slice(0, 7)}-01`;
}

export function endOfMonth(s: ISODate): ISODate {
  return addDays(addMonths(startOfMonth(s), 1), -1);
}

export function isBetween(s: ISODate, from: ISODate, to: ISODate): boolean {
  return s >= from && s <= to;
}

const MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const MONTHS_LONG = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
export const WEEKDAYS_SHORT = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

/** 22 Sep 2026 */
export function formatDate(s?: ISODate | null): string {
  if (!s) return '—';
  const d = parseISODate(s);
  return `${d.getDate()} ${MONTHS_SHORT[d.getMonth()]} ${d.getFullYear()}`;
}

/** 22 Sep */
export function formatDateShort(s?: ISODate | null): string {
  if (!s) return '—';
  const d = parseISODate(s);
  return `${d.getDate()} ${MONTHS_SHORT[d.getMonth()]}`;
}

export function formatMonthYear(s: ISODate): string {
  const d = parseISODate(s);
  return `${MONTHS_LONG[d.getMonth()]} ${d.getFullYear()}`;
}

export function monthShort(index: number): string {
  return MONTHS_SHORT[index];
}

export function formatDateTime(iso?: string | null): string {
  if (!iso) return '—';
  const d = new Date(iso);
  return `${d.getDate()} ${MONTHS_SHORT[d.getMonth()]} ${d.getFullYear()}, ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

export function formatRelative(iso: string, now = new Date()): string {
  const d = new Date(iso);
  const diffMin = Math.round((now.getTime() - d.getTime()) / 60_000);
  if (diffMin < 1) return 'baru saja';
  if (diffMin < 60) return `${diffMin} menit lalu`;
  const diffH = Math.round(diffMin / 60);
  if (diffH < 24) return `${diffH} jam lalu`;
  const diffD = Math.round(diffH / 24);
  if (diffD < 7) return `${diffD} hari lalu`;
  return formatDate(toISODate(d));
}

/** "3 hari lagi", "hari ini", "terlambat 2 hari" relative to today. */
export function describeDue(due: ISODate, today: ISODate): string {
  const d = diffDays(today, due);
  if (d === 0) return 'hari ini';
  if (d === 1) return 'besok';
  if (d > 1) return `${d} hari lagi`;
  return `terlambat ${-d} hari`;
}

export function isoDateFromDateTime(iso: string): ISODate {
  return toISODate(new Date(iso));
}
