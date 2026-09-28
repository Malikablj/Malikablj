/**
 * Collects migration issues. Each issue has a fingerprint so that re-running the migration
 * updates the existing migration_issues row instead of creating a duplicate, and keeps any
 * resolution an admin already recorded.
 */
import { createHash } from 'node:crypto';

export class IssueCollector {
  constructor(sourceFile) {
    this.sourceFile = sourceFile;
    this.issues = [];
    this.fingerprints = new Set();
  }

  /**
   * @param {{ entity: string, sheet?: string, row?: number, legacyKey?: string, severity: 'INFO'|'WARNING'|'ERROR',
   *           type: string, description: string, candidate?: string, sourceData?: object }} issue
   */
  add(issue) {
    const identity = issue.legacyKey ?? `row:${issue.row ?? ''}`;
    const fingerprint = createHash('sha256')
      .update([issue.entity, issue.sheet ?? '', identity, issue.type, issue.description].join('\u0000'))
      .digest('hex');
    if (this.fingerprints.has(fingerprint)) return;
    this.fingerprints.add(fingerprint);
    this.issues.push({
      fingerprint,
      entity_type: issue.entity,
      source_file: this.sourceFile,
      source_sheet: issue.sheet ?? null,
      legacy_row: issue.row ?? null,
      issue_type: issue.type,
      severity: issue.severity,
      description: issue.description,
      candidate_reference: issue.candidate ?? null,
      source_data: issue.sourceData ?? null,
    });
  }

  countBy(field) {
    const counts = {};
    for (const issue of this.issues) counts[issue[field]] = (counts[issue[field]] ?? 0) + 1;
    return counts;
  }

  forEntity(entity) {
    return this.issues.filter((issue) => issue.entity_type === entity);
  }
}
