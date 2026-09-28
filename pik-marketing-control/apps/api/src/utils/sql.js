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

/**
 * SQL list literal for CODE CONSTANTS only (enum codes like 'OPEN'), e.g. "('OPEN','PARTIAL')".
 * Never pass user input here; values are validated to be upper-case codes.
 */
export function codeList(values) {
  if (!values.length || !values.every((value) => /^[A-Z][A-Z_]*$/.test(value))) {
    throw new Error(`codeList accepts upper-case enum codes only: ${values}`);
  }
  return `(${values.map((value) => `'${value}'`).join(', ')})`;
}

/** SQL date literal for a SERVER-COMPUTED "YYYY-MM-DD" (e.g. business today). */
export function sqlDate(isoDate) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(isoDate)) throw new Error(`sqlDate expects YYYY-MM-DD, got ${isoDate}`);
  return `DATE '${isoDate}'`;
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
 * Runs a paged list query plus a count with the same WHERE.
 * `from` must not multiply rows (aggregate one-to-many joins in subqueries instead);
 * `countFrom` may omit joins that only add display columns.
 */
export async function findPage({ select, from, countFrom = from, where, params, order, page, pageSize, db }) {
  const countResult = await query(`SELECT count(*)::int AS total ${countFrom} ${where}`, params, db);
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

/** Inserts the writable keys of `values`, stamping created_by/updated_by. Returns the new id. */
export async function insertRow(table, values, writable, actorId, db) {
  const columns = writable.filter((column) => values[column] !== undefined);
  const params = columns.map((column) => values[column]);
  const actor = `$${params.length + 1}`;
  const { rows } = await query(
    `INSERT INTO ${table} (${[...columns, 'created_by', 'updated_by'].join(', ')})
     VALUES (${[...params.map((_, i) => `$${i + 1}`), actor, actor].join(', ')}) RETURNING id`,
    [...params, actorId],
    db,
  );
  return rows[0].id;
}

/** Updates the writable keys of `values` (undefined = unchanged), stamping updated_by. */
export async function updateRow(table, id, values, writable, actorId, db) {
  const assignments = updateAssignments(values, writable);
  if (!assignments) return;
  const n = assignments.params.length;
  await query(
    `UPDATE ${table} SET ${assignments.sql}, updated_by = $${n + 1} WHERE id = $${n + 2}`,
    [...assignments.params, actorId, id],
    db,
  );
}
