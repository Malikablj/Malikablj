/**
 * Query-string validation helpers (list endpoints). Body schemas are shared with the web app
 * and live in @pik/shared; query schemas are server-only and live here.
 */
import { PAGE_SIZE_DEFAULT, PAGE_SIZE_MAX, isValidIsoDate } from '@pik/shared';
import { z } from 'zod';

const blankToUndefined = (value) => (typeof value === 'string' && value.trim() === '' ? undefined : value);

export const queryText = (max = 200) => z.preprocess(blankToUndefined, z.string().trim().max(max).optional());

export const queryEnum = (values) => z.preprocess(blankToUndefined, z.enum(values, { error: 'Filter tidak valid.' }).optional());

/** Comma-separated list of enum values, e.g. ?status=OPEN,PARTIAL */
export const queryEnumList = (values) =>
  z.preprocess(
    (value) => {
      const text = blankToUndefined(value);
      return typeof text === 'string' ? text.split(',').map((part) => part.trim()).filter(Boolean) : text;
    },
    z.array(z.enum(values, { error: 'Filter tidak valid.' })).optional(),
  );

export const queryId = () => z.preprocess(blankToUndefined, z.guid('ID filter tidak valid.').optional());

export const queryDate = () =>
  z.preprocess(blankToUndefined, z.string().refine(isValidIsoDate, 'Tanggal filter tidak valid (YYYY-MM-DD).').optional());

export const queryBoolean = () =>
  z.preprocess((value) => (value === 'true' ? true : value === 'false' ? false : blankToUndefined(value)), z.boolean().optional());

export const paging = {
  page: z.preprocess(blankToUndefined, z.coerce.number().int().min(1).max(100_000).default(1)),
  page_size: z.preprocess(blankToUndefined, z.coerce.number().int().min(1).max(PAGE_SIZE_MAX).default(PAGE_SIZE_DEFAULT)),
  sort: z.preprocess(blankToUndefined, z.string().regex(/^-?[a-z_]{1,40}$/, 'Urutan tidak valid.').optional()),
  q: queryText(),
};

/** Standard list query: paging + search + the given filters. Unknown parameters are ignored. */
export const listQuery = (filters = {}) => z.object({ ...paging, ...filters });

export const exportFormat = { format: queryEnum(['json', 'csv']) };
