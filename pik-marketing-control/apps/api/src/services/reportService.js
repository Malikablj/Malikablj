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
  const invalid = (filters.status ?? []).filter((status) => !report.statuses.includes(status));
  if (invalid.length) throw validationError(`Filter status tidak valid: ${invalid.join(', ')}.`);
}

const context = () => ({ today: businessToday(), timeZone: config.appTimezone });

export async function run(type, filters, user) {
  const report = reportFor(type, user);
  assertStatuses(report, filters);
  const result = await reportRepository.page(report, filters, context());
  return {
    rows: result.rows,
    meta: {
      ...result.meta,
      report: type,
      title: report.title,
      columns: report.columns.map(({ key, header, type: columnType }) => ({ key, header, type: columnType ?? null })),
    },
  };
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
      return column.format ? column.format(value) : value;
    },
  }));
  return {
    filename: `laporan-${type}-${businessToday()}.csv`,
    csv: toCsv(columns, rows),
    truncated,
  };
}
