/**
 * Small helpers for building parameterized SQL safely.
 * User input only ever reaches PostgreSQL as bound parameters ($1, $2, ...);
 * identifiers (sort columns) come from per-resource whitelists.
 */
import { query } from '../db/pool.js';

/**
 * Collects WHERE conditions and their parameters.
 *   const w = conditions();
 *   w.add(`c.status = ${w.param(status)}`);
 *   query(`SELECT ... ${w.sql()}`, w.params)
 */
export function conditions() {
  const params = [];
  const clauses = [];
  return {
    params,
    param(value) {
      params.push(value);
      return `$${params.length}`;
    },
    add(clause) {
      clauses.push(clause);
    },
    sql() {
      return clauses.length ? `WHERE ${clauses.join(' AND ')}` : '';
    },
  };
}

/** "%text%" for ILIKE with LIKE wildcards in the user's text escaped. */
export function likePattern(text) {
  return `%${String(text).replace(/[\\%_]/g, (char) => `\\${char}`)}%`;
}

/**
 * Resolves `sort` ("name" or "-created_at") against a whitelist { key: 'sql expr' }.
 * Always appends `tiebreaker` so paging is stable.
 */
export function orderBy(sort, allowed, fallback, tiebreaker) {
  let clause = fallback;
  if (sort) {
    const descending = sort.startsWith('-');
    const key = descending ? sort.slice(1) : sort;
    if (Object.hasOwn(allowed, key)) {
      clause = `${allowed[key]} ${descending ? 'DESC' : 'ASC'} NULLS LAST`;
    }
  }
  return tiebreaker ? `${clause}, ${tiebreaker}` : clause;
}

/**
 * Runs a paged list query plus a count with the same FROM/WHERE.
 * `from` must not multiply rows (aggregate one-to-many joins in subqueries instead).
 */
export async function findPage({ select, from, where, params, order, page, pageSize, db }) {
  const countResult = await query(`SELECT count(*)::int AS total ${from} ${where}`, params, db);
  const total = countResult.rows[0].total;
  const next = params.length + 1;
  const rowsResult = await query(
    `${select} ${from} ${where} ORDER BY ${order} LIMIT $${next} OFFSET $${next + 1}`,
    [...params, pageSize, (page - 1) * pageSize],
    db,
  );
  return {
    rows: rowsResult.rows,
    meta: { page, page_size: pageSize, total, total_pages: Math.max(1, Math.ceil(total / pageSize)) },
  };
}

/**
 * Builds "col1 = $1, col2 = $2" for the keys of `values` that appear in `writable`.
 * Returns null when nothing is to be updated.
 */
export function updateAssignments(values, writable, startIndex = 1) {
  const columns = writable.filter((column) => values[column] !== undefined);
  if (!columns.length) return null;
  return {
    sql: columns.map((column, i) => `${column} = $${startIndex + i}`).join(', '),
    params: columns.map((column) => values[column]),
  };
}

/** Builds "(col1, col2) VALUES ($1, $2)" for the keys of `values` that appear in `writable`. */
export function insertColumns(values, writable) {
  const columns = writable.filter((column) => values[column] !== undefined);
  return {
    columns: columns.join(', '),
    placeholders: columns.map((_, i) => `$${i + 1}`).join(', '),
    params: columns.map((column) => values[column]),
  };
}
