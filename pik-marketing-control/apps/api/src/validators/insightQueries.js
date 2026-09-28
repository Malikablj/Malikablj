import { MIGRATION_ISSUE_SEVERITY, MIGRATION_ISSUE_STATUS } from '@pik/shared';
import { z } from 'zod';
import { exportFormat, listQuery, queryDate, queryId, queryText } from './common.js';

/** Report statuses are validated per report type in the service. */
export const reportQuery = listQuery({
  from: queryDate(),
  to: queryDate(),
  customer_id: queryId(),
  owner_user_id: queryId(),
  status: z.preprocess(
    (value) => (typeof value === 'string' && value.trim() ? value.split(',').map((part) => part.trim()).filter(Boolean) : undefined),
    z.array(z.string().regex(/^[A-Z_]{2,30}$/, 'Filter status tidak valid.')).optional(),
  ),
  ...exportFormat,
});

export const searchQuery = z.object({
  q: z.string({ error: 'Kata kunci wajib diisi.' }).trim().min(2, 'Ketik minimal 2 huruf.').max(100),
});

export const migrationIssueListQuery = listQuery({
  resolution_status: z.preprocess(
    (value) => (typeof value === 'string' && value.trim() ? value.split(',') : value),
    z.array(z.enum(MIGRATION_ISSUE_STATUS.values)).optional(),
  ),
  severity: z.preprocess(
    (value) => (typeof value === 'string' && value.trim() ? value.split(',') : value),
    z.array(z.enum(MIGRATION_ISSUE_SEVERITY.values)).optional(),
  ),
  issue_type: queryText(100),
  entity_type: queryText(100),
});
