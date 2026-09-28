/**
 * Display formatting in Indonesian conventions (1.250.000,50 · 28 Sep 2026 · Rp).
 * Business dates ("YYYY-MM-DD") are formatted without timezone conversion; instants are shown in
 * the business timezone reported by the server (set once after login).
 */
let appTimeZone = 'Asia/Jakarta';

export function setAppTimeZone(timeZone) {
  if (timeZone) appTimeZone = timeZone;
}

const EMPTY = '–';
const numberFormat = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 3 });
const currencyFormat = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
const compactFormat = new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 });

export function formatNumber(value) {
  return value === null || value === undefined || value === '' ? EMPTY : numberFormat.format(Number(value));
}

export function formatQuantity(value, unit) {
  if (value === null || value === undefined) return EMPTY;
  return unit ? `${numberFormat.format(Number(value))} ${unit}` : numberFormat.format(Number(value));
}

export function formatCurrency(value) {
  return value === null || value === undefined ? EMPTY : currencyFormat.format(Number(value));
}

/** "Rp 1,2 M" style for KPI cards. */
export function formatCurrencyCompact(value) {
  if (value === null || value === undefined) return EMPTY;
  return Math.abs(Number(value)) < 1_000_000 ? currencyFormat.format(Number(value)) : `Rp ${compactFormat.format(Number(value))}`;
}

export function parseIsoDate(iso) {
  if (!iso) return null;
  const [year, month, day] = iso.slice(0, 10).split('-').map(Number);
  return new Date(year, month - 1, day);
}

const dateFormat = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
const dateLongFormat = new Intl.DateTimeFormat('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
const dayMonthFormat = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short' });

export function formatDate(iso) {
  return iso ? dateFormat.format(parseIsoDate(iso)) : EMPTY;
}

export function formatDateLong(iso) {
  return iso ? dateLongFormat.format(parseIsoDate(iso)) : EMPTY;
}

export function formatDayMonth(iso) {
  return iso ? dayMonthFormat.format(parseIsoDate(iso)) : EMPTY;
}

/** Instant (ISO timestamp) → "28 Sep 2026 14.30" in the business timezone. */
export function formatDateTime(instant) {
  if (!instant) return EMPTY;
  return new Intl.DateTimeFormat('id-ID', {
    timeZone: appTimeZone,
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(instant));
}

/** Business date of an instant in the business timezone ("YYYY-MM-DD"). */
export function instantToBusinessDate(instant) {
  return new Intl.DateTimeFormat('en-CA', { timeZone: appTimeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(
    new Date(instant),
  );
}

export function formatTime(time) {
  return time ? time.slice(0, 5) : '';
}

function daysBetween(fromIso, toIso) {
  return Math.round((parseIsoDate(toIso) - parseIsoDate(fromIso)) / 86_400_000);
}

/** "Hari ini", "Besok", "Kemarin", "3 hari lagi", "5 hari lalu". */
export function relativeDay(iso, today) {
  if (!iso || !today) return '';
  const diff = daysBetween(today, iso);
  if (diff === 0) return 'Hari ini';
  if (diff === 1) return 'Besok';
  if (diff === -1) return 'Kemarin';
  return diff > 0 ? `${diff} hari lagi` : `${-diff} hari lalu`;
}

export function addDaysIso(iso, days) {
  const date = parseIsoDate(iso);
  date.setDate(date.getDate() + days);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/** Local "YYYY-MM-DDTHH:MM" for <input type="datetime-local"> from an ISO instant. */
export function toDateTimeLocal(instant) {
  const date = new Date(instant);
  const pad = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** ISO instant with offset from a datetime-local value (interpreted in the device timezone). */
export function fromDateTimeLocal(value) {
  return value ? new Date(value).toISOString() : '';
}

export function initials(name) {
  return (name ?? '?')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('');
}

const AVATAR_COLORS = ['#0A5CC9', '#5E5CE6', '#1D7A33', '#C4271D', '#975700', '#0A5BB5', '#8944AB', '#3A3A3C'];

export function avatarColor(seed) {
  let hash = 0;
  for (const char of seed ?? '') hash = (hash * 31 + char.charCodeAt(0)) | 0;
  return AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length];
}

export function percent(part, total) {
  if (!total) return 0;
  return Math.max(0, Math.min(100, Math.round((part / total) * 100)));
}
