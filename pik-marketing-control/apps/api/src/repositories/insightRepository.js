/** Global search, notification sources and migration issue review. */
import { FOLLOW_UP_OPEN_STATUSES, PO_OPEN_STATUSES } from '@pik/shared';
import { query } from '../db/pool.js';
import { codeList, conditions, findPage, likePattern, orderBy, sqlDate } from '../utils/sql.js';

// ---------------------------------------------------------------------- search
const SEARCHES = {
  customers: `SELECT id, name AS title, concat_ws(' · ', customer_code, industry) AS subtitle
              FROM customers WHERE is_active AND (name ILIKE $1 OR customer_code ILIKE $1) ORDER BY name LIMIT $2`,
  contacts: `SELECT ct.id, ct.customer_id, ct.name AS title, concat_ws(' · ', c.name, ct.position) AS subtitle
             FROM contacts ct JOIN customers c ON c.id = ct.customer_id
             WHERE ct.is_active AND ct.name ILIKE $1 ORDER BY ct.name LIMIT $2`,
  leads: `SELECT l.id, l.name AS title, concat_ws(' · ', c.name, l.status) AS subtitle
          FROM leads l JOIN customers c ON c.id = l.customer_id WHERE l.name ILIKE $1 ORDER BY l.updated_at DESC LIMIT $2`,
  purchase_orders: `SELECT po.id, po.po_number AS title, concat_ws(' · ', c.name, po.status) AS subtitle
                    FROM purchase_orders po JOIN customers c ON c.id = po.customer_id
                    WHERE po.po_number ILIKE $1 ORDER BY po.po_date DESC NULLS LAST LIMIT $2`,
  products: `SELECT id, name AS title, concat_ws(' · ', product_code, category) AS subtitle
             FROM products WHERE is_active AND (name ILIKE $1 OR product_code ILIKE $1) ORDER BY name LIMIT $2`,
};

export async function search(group, text, limit) {
  const { rows } = await query(SEARCHES[group], [likePattern(text), limit]);
  return rows;
}

// --------------------------------------------------------------- notifications
export async function notificationSources(userId, today) {
  const t = sqlDate(today);
  const [followUps, latePos] = await Promise.all([
    query(
      `SELECT f.id, f.follow_up_date, f.follow_up_time, f.priority, c.name AS customer_name, l.name AS lead_name,
              follow_up_state(f.follow_up_date, f.status, ${t}) AS state
       FROM follow_ups f JOIN customers c ON c.id = f.customer_id LEFT JOIN leads l ON l.id = f.lead_id
       WHERE f.owner_user_id = $1 AND f.status IN ${codeList(FOLLOW_UP_OPEN_STATUSES)} AND f.follow_up_date <= ${t}
       ORDER BY f.follow_up_date, f.follow_up_time NULLS LAST
       LIMIT 20`,
      [userId],
    ),
    query(
      `SELECT po.id, po.po_number, po.expected_delivery_date, c.name AS customer_name, s.outstanding_quantity
       FROM purchase_orders po JOIN customers c ON c.id = po.customer_id
       JOIN v_purchase_order_summary s ON s.purchase_order_id = po.id
       WHERE po.owner_user_id = $1 AND po.status IN ${codeList(PO_OPEN_STATUSES)}
         AND s.outstanding_quantity > 0 AND po.expected_delivery_date < ${t}
       ORDER BY po.expected_delivery_date
       LIMIT 10`,
      [userId],
    ),
  ]);
  return { followUps: followUps.rows, latePurchaseOrders: latePos.rows };
}

export async function openMigrationErrors() {
  const { rows } = await query(`SELECT count(*) AS n FROM migration_issues WHERE resolution_status = 'OPEN' AND severity = 'ERROR'`);
  return rows[0].n;
}

// ------------------------------------------------------------ migration issues
const ISSUE_SELECT = `SELECT i.*, u.name AS resolved_by_name`;
const ISSUE_FROM = 'FROM migration_issues i LEFT JOIN users u ON u.id = i.resolved_by';

export async function listIssues(filters) {
  const w = conditions();
  if (filters.q) {
    const p = w.param(likePattern(filters.q));
    w.add(`(i.description ILIKE ${p} OR i.candidate_reference ILIKE ${p} OR i.source_data::text ILIKE ${p})`);
  }
  if (filters.resolution_status?.length) w.add(`i.resolution_status = ANY(${w.param(filters.resolution_status)})`);
  if (filters.severity?.length) w.add(`i.severity = ANY(${w.param(filters.severity)})`);
  if (filters.issue_type) w.add(`i.issue_type = ${w.param(filters.issue_type)}`);
  if (filters.entity_type) w.add(`i.entity_type = ${w.param(filters.entity_type)}`);
  return findPage({
    select: ISSUE_SELECT,
    from: ISSUE_FROM,
    countFrom: 'FROM migration_issues i',
    where: w.sql(),
    params: w.params,
    order: orderBy(
      filters.sort,
      { severity: "array_position(ARRAY['ERROR','WARNING','INFO'], i.severity)", entity_type: 'i.entity_type', legacy_row: 'i.legacy_row', created_at: 'i.created_at' },
      "array_position(ARRAY['ERROR','WARNING','INFO'], i.severity), i.entity_type, i.source_sheet, i.legacy_row",
      'i.id',
    ),
    page: filters.page,
    pageSize: filters.page_size,
  });
}

export async function issueSummary() {
  const [counts, types, run] = await Promise.all([
    query(`SELECT resolution_status, severity, count(*) AS count FROM migration_issues GROUP BY 1, 2`),
    query(`SELECT issue_type, entity_type, count(*) AS count FROM migration_issues WHERE resolution_status = 'OPEN' GROUP BY 1, 2 ORDER BY 3 DESC`),
    query(`SELECT id, source_file, source_checksum, mapping_version, started_at, finished_at, summary
           FROM migration_runs WHERE finished_at IS NOT NULL ORDER BY started_at DESC LIMIT 1`),
  ]);
  return { counts: counts.rows, open_by_type: types.rows, last_run: run.rows[0] ?? null };
}

export async function findIssue(id) {
  const { rows } = await query(`${ISSUE_SELECT} ${ISSUE_FROM} WHERE i.id = $1`, [id]);
  return rows[0] ?? null;
}

/** Reopening clears who resolved it; resolving/ignoring records the user and time. */
export async function resolveIssue(id, { status, notes, userId }) {
  const reopened = status === 'OPEN';
  await query(
    `UPDATE migration_issues
     SET resolution_status = $2, resolution_notes = $3, resolved_by = $4,
         resolved_at = CASE WHEN $4::uuid IS NULL THEN NULL ELSE now() END
     WHERE id = $1`,
    [id, status, notes ?? null, reopened ? null : userId],
  );
}
