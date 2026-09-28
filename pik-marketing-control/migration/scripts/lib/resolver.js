/**
 * Reference resolution, following the matching levels of the migration protocol:
 *
 *   Level 1  exact stable identifier  via 'key'  (legacy key of an imported row, e.g. AppSheet ID)
 *                                     via 'code' (customer_code / product_code, case-insensitive)
 *   Level 2  exact normalized name    via 'name' (normalize_key(), scoped to the record's
 *                                     customer for contacts and leads), via 'user' (email or name)
 *   Level 3  deterministic composite  via 'po_number' (+ the record's customer when known);
 *                                     PO + product -> PO line (entities.js)
 *   Level 4  several candidates       NEVER chosen automatically: reported with the candidates.
 *
 * Strategies are tried in the order given by the mapping. A strategy whose source cell is
 * empty is skipped; one that finds nothing falls through to the next; one that finds several
 * candidates stops resolution (a weaker level cannot safely break the tie).
 */
import { TARGETS } from './entities.js';
import { parseText } from './values.js';
import { legacyKeyFromValues } from './keys.js';

export function createResolver(query) {
  const cache = new Map();

  async function lookup(targetName, via, value, scopeId) {
    const target = TARGETS[targetName];
    const cacheKey = `${targetName}|${via}|${value}|${scopeId ?? ''}`;
    if (cache.has(cacheKey)) return cache.get(cacheKey);

    const extra = target.extra ? `, ${target.extra}` : '';
    const select = `SELECT id, ${target.label} AS label${extra} FROM ${target.table}`;
    let result;
    switch (via) {
      case 'key':
        result = await query(`${select} WHERE legacy_key = $1`, [legacyKeyFromValues(targetName, 0, [value])]);
        break;
      case 'code':
        result = await query(`${select} WHERE upper(btrim(${target.code})) = upper(btrim($1))`, [value]);
        break;
      case 'name':
        result =
          target.scope && scopeId
            ? await query(`${select} WHERE ${target.nameKey} = normalize_key($1) AND ${target.scope} = $2`, [value, scopeId])
            : await query(`${select} WHERE ${target.nameKey} = normalize_key($1)`, [value]);
        break;
      case 'user':
        result = await query(`${select} WHERE lower(email) = lower(btrim($1)) OR normalize_key(name) = normalize_key($1)`, [value]);
        break;
      case 'po_number':
        result = scopeId
          ? await query(`${select} WHERE upper(btrim(po_number)) = upper(btrim($1)) AND customer_id = $2`, [value, scopeId])
          : await query(`${select} WHERE upper(btrim(po_number)) = upper(btrim($1))`, [value]);
        break;
      default:
        throw new Error(`Unknown resolution strategy "${via}"`);
    }
    cache.set(cacheKey, result.rows);
    return result.rows;
  }

  /**
   * @returns {{ match: object|null, via?: string, value?: string, ambiguous?: object[],
   *             notFound: {via: string, value: string}[], empty: boolean }}
   */
  async function resolve(ref, strategies, record, rowValues) {
    const notFound = [];
    for (const strategy of strategies) {
      const value = parseText(rowValues[strategy.column]).value;
      if (value === null) continue;

      let scopeId = null;
      if (strategy.via === 'name' && TARGETS[ref.target].scope) scopeId = record.values[TARGETS[ref.target].scope] ?? null;
      if (strategy.via === 'po_number') {
        scopeId = record.values.customer_id ?? null;
        if (!scopeId && strategy.customerColumn) {
          const customerValue = parseText(rowValues[strategy.customerColumn]).value;
          if (customerValue !== null) {
            const customers = await lookup('customers', strategy.customerVia ?? 'name', customerValue);
            if (customers.length === 1) scopeId = customers[0].id;
          }
        }
      }

      const candidates = await lookup(ref.target, strategy.via, value, scopeId);
      if (candidates.length === 1) return { match: candidates[0], via: strategy.via, value, notFound, empty: false };
      if (candidates.length > 1) return { match: null, via: strategy.via, value, ambiguous: candidates, notFound, empty: false };
      notFound.push({ via: strategy.via, value });
    }
    return { match: null, notFound, empty: notFound.length === 0 };
  }

  return { resolve, lookup };
}
