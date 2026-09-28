'use strict';

/** Shared check for sheets that carry both a POID and the legacy PO number text. */

const { cleanText, documentKey } = require('../normalize');
const { findPoCandidates } = require('../matching');

function describePo(ctx, po) {
  return `${po.POID} (${po.PONumber ?? 'tanpa nomor'}, ${ctx.customerName(po.CustomerID) ?? 'tanpa customer'})`;
}

/**
 * Verifies POID against PONumberLegacy, or proposes candidates when POID is empty.
 * Returns a status label for statistics.
 */
function checkPoReference(ctx, sheetName, record, unresolvedType) {
  const legacy = cleanText(record.PONumberLegacy);
  if (cleanText(record.POID)) {
    const po = ctx.index.poById.get(record.POID);
    if (!legacy || !po) return legacy ? 'linked-po-missing' : 'linked-without-legacy-number';
    if (documentKey(po.PONumber) === documentKey(legacy)) return 'linked-consistent';
    ctx.issues.add('PO_REFERENCE_MISMATCH', {
      ...ctx.lineage(sheetName, record),
      field: 'POID',
      value: legacy,
      candidate_reference: describePo(ctx, po),
      description: `PONumberLegacy "${legacy}" berbeda dengan nomor PO dari POID ${record.POID} ("${po.PONumber ?? ''}").`,
    });
    return 'linked-mismatch';
  }
  const found = findPoCandidates(ctx.index, legacy);
  if (unresolvedType) {
    ctx.issues.add(unresolvedType, {
      ...ctx.lineage(sheetName, record),
      field: 'POID',
      value: legacy ?? '',
      candidate_reference: found.pos.map((po) => describePo(ctx, po)).join('; '),
      candidate_method: found.method ?? '',
      description: !legacy
        ? 'POID dan nomor PO legacy sama-sama kosong.'
        : found.pos.length
          ? `POID kosong, padahal nomor "${legacy}" cocok dengan ${found.pos.length} PO (${found.method}).`
          : `Nomor PO legacy "${legacy}" tidak ada di PURCHASE_ORDERS.`,
    });
  }
  if (!legacy) return 'no-po-reference';
  return found.pos.length ? `unlinked-${found.method}` : 'unlinked-not-found';
}

module.exports = { checkPoReference, describePo };
