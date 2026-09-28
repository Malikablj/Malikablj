import { canRead } from '@pik/shared';
import config from '../config/index.js';
import * as reportRepository from '../repositories/reportRepository.js';
import { toCsv } from '../utils/csv.js';
import { businessToday } from '../utils/dates.js';
import { forbidden, notFound, validationError } from '../utils/errors.js';

const CSV_ROW_LIMIT = 20_000;

function reportFor(type, user) {
  const report = reportRepository.REPORTS[type];
  if (!report) throw notFound('Laporan tidak ditemukan.');
  if (!canRead(user.role, report.module)) throw forbidden();
  return report;
}

function assertStatuses(report, filters) {
  const invalid = (filters.status ?? []).filter((status) => !report.statusEnum.values.includes(status));
  if (invalid.length) throw validationError(`Filter status tidak valid: ${invalid.join(', ')}.`);
}

const context = () => ({ today: businessToday(), timeZone: config.appTimezone });

const describeColumn = ({ key, header, type, labels }) => ({ key, header, type: type ?? null, labels: labels ?? null });

/** What a report shows and which filters it accepts, so the UI can render any report generically. */
function describe(type, report) {
  return {
    type,
    title: report.title,
    filters: {
      date: report.dateColumn?.label ?? null,
      customer: Boolean(report.customerColumn),
      owner: Boolean(report.ownerColumn),
      status: { label: report.statusLabel, options: report.statusEnum.options },
    },
    columns: report.columns.map(describeColumn),
    total_columns: report.totalColumns.map(describeColumn),
  };
}

/** Reports the user may open (each report also requires read access to its own module). */
export function catalog(user) {
  return Object.entries(reportRepository.REPORTS)
    .filter(([, report]) => canRead(user.role, report.module))
    .map(([type, report]) => describe(type, report));
}

export async function run(type, filters, user) {
  const report = reportFor(type, user);
  assertStatuses(report, filters);
  const result = await reportRepository.page(report, filters, context());
  const { columns, total_columns: totalColumns, title } = describe(type, report);
  return { rows: result.rows, meta: { ...result.meta, report: type, title, columns, total_columns: totalColumns } };
}

const timestampFormat = new Intl.DateTimeFormat('sv-SE', {
  timeZone: config.appTimezone,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
});

/** CSV export of every matching row (up to CSV_ROW_LIMIT). Timestamps are shown in the business timezone. */
export async function exportCsv(type, filters, user) {
  const report = reportFor(type, user);
  assertStatuses(report, filters);
  const { rows, truncated } = await reportRepository.all(report, filters, context(), CSV_ROW_LIMIT);
  const columns = report.columns.map((column) => ({
    ...column,
    value: (row) => {
      const value = row[column.key];
      if (value === null || value === undefined) return null;
      if (column.type === 'timestamp') return timestampFormat.format(value);
      if (column.type === 'time') return String(value).slice(0, 5);
      return column.labels ? (column.labels[value] ?? value) : value;
    },
  }));
  return {
    filename: `laporan-${type}-${businessToday()}.csv`,
    csv: toCsv(columns, rows),
    truncated,
  };
}
