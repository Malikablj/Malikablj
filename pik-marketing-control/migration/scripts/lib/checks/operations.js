'use strict';

/** Stock snapshot, lead-time schedule and inbound-to-maklon records. */

const { approxEqual, cleanText, documentKey, nameKey, normalizeCode, textKey } = require('../normalize');
const { countBy, findPoCandidates, findProductCandidates, groupBy, sumBy } = require('../matching');
const { checkPoReference, describePo } = require('./references');

// Cell texts that are column headers of the legacy stock sheet, not data.
const STOCK_HEADER_TEXTS = new Set(['namabarang', 'status', 'qty', 'quantity', 'box', 'qtyperbox', 'stocktype']);

const describeProduct = (product) => `${product.ProductID} (${product.ProductName})`;

function checkStock(ctx) {
  const { tables, issues, index } = ctx;
  const stock = tables.STOCK.records;
  const outcome = { headerRows: 0, linked: 0, candidateFound: 0, noCandidate: 0 };
  for (const row of stock) {
    const base = ctx.lineage('STOCK', row);
    if (STOCK_HEADER_TEXTS.has(textKey(row.ProductLegacy)) || STOCK_HEADER_TEXTS.has(textKey(row.Status))) {
      outcome.headerRows += 1;
      issues.add('STOCK_HEADER_ROW', {
        ...base,
        field: 'ProductLegacy',
        value: row.ProductLegacy ?? '',
        description: `Baris berisi teks header legacy ("${row.ProductLegacy}"/"${row.Status ?? ''}"), bukan data stok. Tidak diimpor.`,
      });
      continue;
    }
    const summary = `${row.StockType ?? '-'} | "${row.ProductLegacy ?? '-'}" | qty ${row.Quantity ?? '-'} | box ${row.Box ?? '-'} x ${row.QtyPerBox ?? '-'}`;
    if (cleanText(row.ProductID)) {
      outcome.linked += 1;
    } else {
      const found = findProductCandidates(index, row.ProductLegacy);
      if (found.products.length) outcome.candidateFound += 1;
      else outcome.noCandidate += 1;
      issues.add('STOCK_PRODUCT_UNRESOLVED', {
        ...base,
        field: 'ProductID',
        value: row.ProductLegacy ?? '',
        candidate_reference: found.products.slice(0, 5).map(describeProduct).join('; '),
        candidate_method: found.method ? `${found.method}${found.products.length > 1 ? ` (${found.products.length} kandidat)` : ''}` : '',
        description: 'Baris stok belum tertaut ke master produk.',
        evidence: summary,
      });
    }
    const boxTotal = typeof row.Box === 'number' && typeof row.QtyPerBox === 'number' ? row.Box * row.QtyPerBox : null;
    if (row.Quantity === null) {
      issues.add('STOCK_QTY_MISSING', {
        ...base,
        field: 'Quantity',
        candidate_reference: boxTotal ?? '',
        candidate_method: boxTotal !== null ? 'Box x QtyPerBox' : '',
        description: 'Quantity stok kosong.',
        evidence: summary,
      });
    } else if (boxTotal !== null && !approxEqual(boxTotal, row.Quantity)) {
      issues.add('STOCK_QTY_INCONSISTENT', {
        ...base,
        field: 'Quantity',
        value: row.Quantity,
        candidate_reference: boxTotal,
        candidate_method: 'Box x QtyPerBox',
        description: `Box ${row.Box} x QtyPerBox ${row.QtyPerBox} = ${boxTotal}, sedangkan Quantity ${row.Quantity}.`,
      });
    }
  }
  issues.add('STOCK_DATE_UNKNOWN', {
    workbook_sheet: 'STOCK',
    field: 'stock_date',
    description:
      'Sheet STOCK tidak punya tanggal snapshot atau gudang; tanggal stok tidak bisa ditentukan dari workbook ' +
      '(tanggal pembuatan workbook bukan tanggal stok).',
  });
  const dataRows = stock.filter((row) => !STOCK_HEADER_TEXTS.has(textKey(row.ProductLegacy)) && !STOCK_HEADER_TEXTS.has(textKey(row.Status)));
  ctx.stats.stock = {
    total: stock.length,
    ...outcome,
    dataRows: dataRows.length,
    byStockType: countBy(dataRows, (row) => cleanText(row.StockType)),
    byStatus: countBy(stock, (row) => cleanText(row.Status)),
    quantityByType: Object.fromEntries(
      [...groupBy(dataRows, (row) => cleanText(row.StockType))].map(([type, rows]) => [type, sumBy(rows, (row) => row.Quantity)]),
    ),
    withQuantity: dataRows.filter((row) => row.Quantity !== null).length,
    zeroQuantity: dataRows.filter((row) => row.Quantity === 0).length,
    boxConsistent: dataRows.filter(
      (row) => typeof row.Box === 'number' && typeof row.QtyPerBox === 'number' && approxEqual(row.Box * row.QtyPerBox, row.Quantity ?? NaN),
    ).length,
    legacyRowsWithTwoBlocks: [...groupBy(stock, (row) => row.LegacyRow).values()].filter((rows) => rows.length > 1).length,
  };
}

function checkLeadtime(ctx) {
  const { tables, issues, index } = ctx;
  const rows = [...tables.LEADTIME.records].sort((a, b) => a._row - b._row);
  const isSummary = (row) => /total/i.test(`${row.PONumberLegacy ?? ''} ${row.ProductLegacy ?? ''}`);
  const dataRows = rows.filter((row) => !isSummary(row));
  const dataQuantity = sumBy(dataRows, (row) => row.Quantity);
  const references = {};
  for (const row of rows) {
    const base = ctx.lineage('LEADTIME', row);
    if (isSummary(row)) {
      issues.add('LEADTIME_SUMMARY_ROW', {
        ...base,
        field: 'PONumberLegacy',
        value: row.PONumberLegacy ?? row.ProductLegacy ?? '',
        description: `Baris total ("${row.PONumberLegacy ?? row.ProductLegacy}") ikut terimpor sebagai data. Tidak diimpor; dipakai sebagai checksum.`,
        evidence: `Quantity baris total ${row.Quantity}; jumlah ${dataRows.length} baris lain ${dataQuantity} (${approxEqual(row.Quantity ?? NaN, dataQuantity) ? 'sama' : 'berbeda'})`,
      });
      continue;
    }
    const reference = checkPoReference(ctx, 'LEADTIME', row, null);
    references[reference] = (references[reference] || 0) + 1;
    if (!cleanText(row.POID)) {
      const above = dataRows.filter(
        (other) => other._row < row._row && cleanText(other.POID) && textKey(other.ProductLegacy) === textKey(row.ProductLegacy),
      );
      const source = above[above.length - 1];
      issues.add('LEADTIME_PO_UNRESOLVED', {
        ...base,
        field: 'POID',
        candidate_reference: source ? describePo(ctx, index.poById.get(source.POID)) : '',
        candidate_method: source ? `isi-ke-bawah dari baris ${source._row} (produk sama; kemungkinan sel digabung di sumber)` : '',
        description: 'Baris jadwal tidak menyebut PO.',
        evidence: `"${row.ProductLegacy ?? '-'}" qty ${row.Quantity ?? '-'} tgl ${row.DeliveryDate ?? '-'} status ${row.Status ?? '-'}`,
      });
    }
    if (!cleanText(row.ProductID)) {
      const found = findProductCandidates(index, row.ProductLegacy);
      issues.add('LEADTIME_PRODUCT_UNRESOLVED', {
        ...base,
        field: 'ProductID',
        value: row.ProductLegacy ?? '',
        candidate_reference: found.products.slice(0, 5).map(describeProduct).join('; '),
        candidate_method: found.method ? `${found.method}${found.products.length > 1 ? ` (${found.products.length} kandidat)` : ''}` : '',
        description: 'Baris jadwal belum tertaut ke master produk.',
      });
    }
  }
  issues.add('LEADTIME_SEMANTICS_MISMATCH', {
    workbook_sheet: 'LEADTIME',
    description:
      'Sheet berisi jadwal estimasi pengiriman (sumber: "Estimasi Leadtime Delivery": PO, produk, qty, tanggal, status), ' +
      'sedangkan tabel leadtime di spesifikasi berisi referensi lead_time_days per produk/customer.',
  });

  const overlaps = dataRows.map((row) => {
    const sameDay = tables.DELIVERIES.records.filter(
      (delivery) => delivery.DeliveryDate === row.DeliveryDate && approxEqual(delivery.DeliveredQuantity ?? NaN, row.Quantity ?? NaN),
    );
    return { row: row._row, date: row.DeliveryDate, quantity: row.Quantity, status: row.Status, matchingDeliveries: sameDay.map((d) => d.SJNumber) };
  });
  ctx.stats.leadtime = {
    total: rows.length,
    dataRows: dataRows.length,
    summaryRows: rows.length - dataRows.length,
    dataQuantity,
    byStatus: countBy(dataRows, (row) => cleanText(row.Status)),
    products: countBy(dataRows, (row) => cleanText(row.ProductLegacy)),
    dateRange: [dataRows.map((row) => row.DeliveryDate).filter(Boolean).sort()[0], dataRows.map((row) => row.DeliveryDate).filter(Boolean).sort().pop()],
    poReference: references,
    rowsMatchingAnActualDelivery: overlaps.filter((entry) => entry.matchingDeliveries.length).length,
    overlaps,
  };
}

function checkInbound(ctx) {
  const { tables, issues, index } = ctx;
  const inbound = tables.INBOUND_MAKLON.records;
  const deliveriesBySj = groupBy(tables.DELIVERIES.records, (delivery) => documentKey(delivery.SJNumber));
  const poOutcome = {};
  const componentOutcome = {};
  let sjMatched = 0;
  let sjMatchedSameQuantity = 0;
  let attachmentsLookLikeSj = 0;
  for (const row of inbound) {
    const base = ctx.lineage('INBOUND_MAKLON', row);
    const summary = `SJ ${row.SJNumber ?? '-'}; PO ${row.PONumberLegacy ?? '-'}; kode ${row.FactoryComponentCode ?? '-'}; qty ${row.Quantity ?? '-'}; ke ${row.Receiver ?? '-'}; tgl ${row.ActualInboundDate ?? '-'}`;
    if (!cleanText(row.FactoryComponentCode) && row.Quantity === null) {
      issues.add('INBOUND_ROW_INCOMPLETE', {
        ...base,
        description: 'Baris inbound tanpa kode komponen dan tanpa quantity.',
        evidence: `${summary}; attachment ${row.Attachment ?? '-'}`,
      });
    }

    const pos = findPoCandidates(index, row.PONumberLegacy);
    const poKey = pos.pos.length === 1 ? `1 PO (${pos.method})` : pos.pos.length ? 'beberapa PO' : cleanText(row.PONumberLegacy) ? 'tidak ditemukan' : 'tanpa nomor PO';
    poOutcome[poKey] = (poOutcome[poKey] || 0) + 1;
    if (pos.pos.length !== 1) {
      issues.add('INBOUND_PO_UNRESOLVED', {
        ...base,
        field: 'PONumberLegacy',
        value: row.PONumberLegacy ?? '',
        candidate_reference: pos.pos.map((po) => describePo(ctx, po)).join('; '),
        candidate_method: pos.method ?? '',
        description: pos.pos.length ? `Nomor PO cocok dengan ${pos.pos.length} PO.` : 'Nomor PO tidak ditemukan di PURCHASE_ORDERS.',
        evidence: summary,
      });
    }

    const code = normalizeCode(row.FactoryComponentCode);
    if (code) {
      const products = index.productsByCode.get(code) || [];
      const componentKey = products.length === 1 ? '1 produk' : products.length ? 'beberapa produk' : 'tidak ditemukan';
      componentOutcome[componentKey] = (componentOutcome[componentKey] || 0) + 1;
      if (products.length !== 1) {
        issues.add('INBOUND_COMPONENT_UNRESOLVED', {
          ...base,
          field: 'FactoryComponentCode',
          value: row.FactoryComponentCode,
          candidate_reference: products.slice(0, 5).map(describeProduct).join('; '),
          candidate_method: products.length ? `same-product-code (${products.length} kandidat)` : '',
          description: products.length
            ? `Kode ${code} dipakai ${products.length} record produk.`
            : `Kode ${code} tidak ada di master produk.`,
          evidence: summary,
        });
      }
    }

    const sjDeliveries = deliveriesBySj.get(documentKey(row.SJNumber)) || [];
    if (sjDeliveries.length) {
      sjMatched += 1;
      if (sjDeliveries.some((delivery) => approxEqual(delivery.DeliveredQuantity ?? NaN, row.Quantity ?? NaN))) sjMatchedSameQuantity += 1;
    }
    if (cleanText(row.Attachment) && /^PIK-SJ-/i.test(cleanText(row.Attachment))) attachmentsLookLikeSj += 1;
  }
  if (attachmentsLookLikeSj) {
    issues.add('INBOUND_ATTACHMENT_NOT_FILE', {
      workbook_sheet: 'INBOUND_MAKLON',
      field: 'Attachment',
      value: attachmentsLookLikeSj,
      description: `${attachmentsLookLikeSj} nilai Attachment berisi nomor SJ, bukan tautan file.`,
    });
  }
  ctx.stats.inbound = {
    total: inbound.length,
    receivers: countBy(inbound, (row) => cleanText(row.Receiver)),
    vendors: countBy(inbound, (row) => cleanText(row.Vendor)),
    poNumbers: countBy(inbound, (row) => cleanText(row.PONumberLegacy)),
    poOutcome,
    componentCodes: countBy(inbound, (row) => cleanText(row.FactoryComponentCode)),
    componentOutcome,
    incompleteRows: inbound.filter((row) => !cleanText(row.FactoryComponentCode) && row.Quantity === null).length,
    quantityTotal: sumBy(inbound, (row) => row.Quantity),
    rejectQuantityTotal: sumBy(inbound, (row) => row.RejectQuantity),
    sjFoundInDeliveries: sjMatched,
    sjFoundWithSameQuantity: sjMatchedSameQuantity,
    attachmentsLookLikeSj,
    receiverMatchesCustomer: Object.fromEntries(
      Object.keys(countBy(inbound, (row) => cleanText(row.Receiver))).map((receiver) => [
        receiver,
        (index.customersByNameKey.get(nameKey(receiver)) || []).map((customer) => customer.CustomerName),
      ]),
    ),
  };
}

module.exports = function checkOperations(ctx) {
  checkStock(ctx);
  checkLeadtime(ctx);
  checkInbound(ctx);
};
