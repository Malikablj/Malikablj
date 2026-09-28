/** Writes migration reports (JSON summary + issues CSV) and formats the console summary. */
import fs from 'node:fs';
import path from 'node:path';

function csvCell(value) {
  if (value === null || value === undefined) return '';
  let text = typeof value === 'object' ? JSON.stringify(value) : String(value);
  if (/^[=+\-@\t\r]/.test(text)) text = `'${text}`;
  return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

export function issuesToCsv(issues) {
  const columns = [
    'severity',
    'issue_type',
    'entity_type',
    'source_file',
    'source_sheet',
    'legacy_row',
    'description',
    'candidate_reference',
    'source_data',
  ];
  const lines = [columns.join(',')];
  for (const issue of issues) lines.push(columns.map((column) => csvCell(issue[column])).join(','));
  return `\uFEFF${lines.join('\r\n')}\r\n`;
}

export function writeReports(reportsDir, summary, issues) {
  fs.mkdirSync(reportsDir, { recursive: true });
  const summaryPath = path.join(reportsDir, 'migration_summary.json');
  const issuesPath = path.join(reportsDir, 'migration_issues.csv');
  fs.writeFileSync(summaryPath, `${JSON.stringify(summary, null, 2)}\n`);
  fs.writeFileSync(issuesPath, issuesToCsv(issues));
  return { summaryPath, issuesPath };
}

function pad(value, width, alignRight = true) {
  const text = String(value ?? '');
  return alignRight ? text.padStart(width) : text.padEnd(width);
}

export function formatSummary(summary) {
  const lines = [];
  const modeLabel = summary.mode === 'apply' ? 'APPLY' : 'DRY RUN (rolled back, nothing saved)';
  lines.push(`Migration ${modeLabel}`);
  lines.push(`Source : ${summary.source_file} (sha256 ${summary.source_checksum?.slice(0, 12)}...)`);
  lines.push(`Mapping: ${summary.mapping.version} [${summary.mapping.status}]`);
  lines.push(`Result : ${summary.result}`);
  if (summary.blocked_reasons?.length) {
    lines.push('', 'BLOCKED:');
    for (const reason of summary.blocked_reasons) lines.push(`  - ${reason}`);
  }
  if (summary.gate.errors.length) {
    lines.push('', `Mapping errors (${summary.gate.errors.length}):`);
    for (const error of summary.gate.errors) lines.push(`  x ${error}`);
  }
  if (summary.gate.warnings.length) {
    lines.push('', `Mapping warnings (${summary.gate.warnings.length}):`);
    for (const warning of summary.gate.warnings) lines.push(`  ! ${warning}`);
  }
  if (summary.entities.length) {
    lines.push('');
    const header = [
      pad('Entity', 18, false),
      pad('Source', 7),
      pad('Insert', 7),
      pad('Update', 7),
      pad('Same', 6),
      pad('Kept*', 6),
      pad('Skip', 6),
      pad('Dup?', 5),
      pad('Unres.', 7),
      pad('Err', 5),
    ].join(' ');
    lines.push(header, '-'.repeat(header.length));
    for (const e of summary.entities) {
      if (e.status === 'NOT_RUN') {
        lines.push(`${pad(e.entity, 18, false)} NOT RUN: ${e.reason}`);
        continue;
      }
      lines.push(
        [
          pad(e.entity, 18, false),
          pad(e.records, 7),
          pad(e.inserted, 7),
          pad(e.updated, 7),
          pad(e.unchanged, 6),
          pad(e.skipped_modified_in_app, 6),
          pad(e.not_imported, 6),
          pad(e.duplicate_candidates + e.merged_duplicates, 5),
          pad(e.unresolved_relationships, 7),
          pad(e.errors, 5),
        ].join(' '),
      );
    }
    lines.push('* Kept = changed in the app since the last migration, not overwritten.');
  }
  const sev = summary.issues.by_severity ?? {};
  lines.push('', `Issues: ${summary.issues.total} (ERROR ${sev.ERROR ?? 0}, WARNING ${sev.WARNING ?? 0}, INFO ${sev.INFO ?? 0})`);
  return lines.join('\n');
}
