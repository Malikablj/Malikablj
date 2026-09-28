/**
 * Field helpers for zod schemas. Used by the API (authoritative) and by forms in the
 * web app (early feedback). Error messages are user-facing Bahasa Indonesia.
 *
 * Conventions:
 * - Strings are trimmed; blank strings become null ("clear this field").
 * - Optional fields may be omitted (undefined = leave unchanged on update) or null.
 * - Dates travel as "YYYY-MM-DD", times as "HH:MM", timestamps as ISO 8601 with offset.
 */
import { z } from 'zod';

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
const TIME_RE = /^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/;
const DATETIME_RE = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/;

export function trimToNull(value) {
  if (typeof value !== 'string') return value;
  const trimmed = value.trim();
  return trimmed === '' ? null : trimmed;
}

function toNumberOrNull(value) {
  if (typeof value === 'string') {
    const trimmed = value.trim();
    if (trimmed === '') return null;
    const parsed = Number(trimmed);
    return Number.isFinite(parsed) ? parsed : value;
  }
  return value;
}

export function isValidIsoDate(value) {
  if (typeof value !== 'string' || !DATE_RE.test(value)) return false;
  const parsed = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value;
}

export function isValidIsoDateTime(value) {
  return typeof value === 'string' && DATETIME_RE.test(value) && !Number.isNaN(Date.parse(value));
}

export const requiredText = (label, max = 255) =>
  z.preprocess(
    trimToNull,
    z
      .string({ error: `${label} wajib diisi.` })
      .min(1, `${label} wajib diisi.`)
      .max(max, `${label} maksimal ${max} karakter.`),
  );

export const optionalText = (label, max = 255) =>
  z.preprocess(trimToNull, z.string().max(max, `${label} maksimal ${max} karakter.`).nullable()).optional();

export const optionalEmail = (label = 'Email') =>
  z
    .preprocess(
      (v) => (typeof trimToNull(v) === 'string' ? trimToNull(v).toLowerCase() : trimToNull(v)),
      z.email(`Format ${label.toLowerCase()} tidak valid.`).max(255).nullable(),
    )
    .optional();

export const requiredEmail = (label = 'Email') =>
  z.preprocess(
    (v) => (typeof trimToNull(v) === 'string' ? trimToNull(v).toLowerCase() : trimToNull(v)),
    z.email({ error: (issue) => (issue.input == null ? `${label} wajib diisi.` : `Format ${label.toLowerCase()} tidak valid.`) }).max(255),
  );

export const requiredId = (label) =>
  z.preprocess(trimToNull, z.guid({ error: (issue) => (issue.input == null ? `${label} wajib dipilih.` : `${label} tidak valid.`) }));

export const optionalId = (label) => z.preprocess(trimToNull, z.guid(`${label} tidak valid.`).nullable()).optional();

export const requiredDate = (label) =>
  z.preprocess(
    trimToNull,
    z
      .string({ error: `${label} wajib diisi.` })
      .refine(isValidIsoDate, `${label} tidak valid (format YYYY-MM-DD).`),
  );

export const optionalDate = (label) =>
  z
    .preprocess(trimToNull, z.string().refine(isValidIsoDate, `${label} tidak valid (format YYYY-MM-DD).`).nullable())
    .optional();

export const optionalTime = (label) =>
  z.preprocess(trimToNull, z.string().regex(TIME_RE, `${label} tidak valid (format JJ:MM).`).nullable()).optional();

export const requiredDateTime = (label) =>
  z.preprocess(
    trimToNull,
    z.string({ error: `${label} wajib diisi.` }).refine(isValidIsoDateTime, `${label} tidak valid.`),
  );

/** Numbers accept JSON numbers or numeric strings (form inputs). */
export const requiredNumber = (label, { min, max, positive = false, integer = false } = {}) => {
  let schema = z.number({ error: (issue) => (issue.input == null ? `${label} wajib diisi.` : `${label} harus berupa angka.`) });
  if (integer) schema = schema.int(`${label} harus bilangan bulat.`);
  if (positive) schema = schema.positive(`${label} harus lebih dari 0.`);
  if (min !== undefined) schema = schema.min(min, `${label} minimal ${min}.`);
  if (max !== undefined) schema = schema.max(max, `${label} maksimal ${max}.`);
  return z.preprocess(toNumberOrNull, schema);
};

export const optionalNumber = (label, { min, max, integer = false } = {}) => {
  let schema = z.number({ error: `${label} harus berupa angka.` });
  if (integer) schema = schema.int(`${label} harus bilangan bulat.`);
  if (min !== undefined) schema = schema.min(min, `${label} minimal ${min}.`);
  if (max !== undefined) schema = schema.max(max, `${label} maksimal ${max}.`);
  return z.preprocess(toNumberOrNull, schema.nullable()).optional();
};

export const enumField = (label, values) => z.enum(values, { error: `${label} tidak valid.` });

export const booleanField = () =>
  z.preprocess((v) => (v === 'true' ? true : v === 'false' ? false : v), z.boolean({ error: 'Nilai harus ya/tidak.' }));

/**
 * Converts a zod error into { field: message } using the first message per field.
 * Nested paths are joined with dots (e.g. "lines.0.order_quantity").
 */
export function fieldErrors(zodError) {
  const errors = {};
  for (const issue of zodError.issues) {
    const key = issue.path.length ? issue.path.join('.') : '_form';
    if (!(key in errors)) errors[key] = issue.message;
  }
  return errors;
}
