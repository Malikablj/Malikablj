'use strict';

/** Purchase orders, PO lines, deliveries and returns. */

const { approxEqual, cleanText, documentKey, nameKey, textKey } = require('../normalize');
const {
  countBy,
  findProductCandidates,
  groupBy,
  linesForLabel,
  linesForProduct,
  productCode,
  sumBy,
} = require('../matching');
const { checkPoReference } = require('./references');

// Legacy spellings -> target PO status. "Hold"/"On Hold" deliberately have no mapping (decision D4).
const PO_STATUS_MAP = {
  closed: 'CLOSED',
  'on process': 'ON_PROCESS',
  'on proses': 'ON_PROCESS',
  process: 'ON_PROCESS',
  proses: 'ON_PROCESS',
  open: 'OPEN',
  partial: 'PARTIAL',
  cancel: 'CANCELLED',
  canceled: 'CANCELLED',
  cancelled: 'CANCELLED',
};
const CLOSED_STATUSES = new Set(['CLOSED', 'CANCELLED']);

function mapPoStatus(raw) {
  const key = cleanText(raw)?.toLowerCase();
  return key ? (PO_STATUS_MAP[key] ?? null) : null;
}

/** Mapped status, or "?<raw>" for values without a mapping, for statistics and comparisons. */
function statusLabel(raw) {
  return mapPoStatus(raw) ?? (cleanText(raw) ? `?${cleanText(raw)}` : null);
}

function lineLabel(line) {
  if (!line) return '';
  return `${line.ProductNameLegacy ?? ''}${cleanText(line.VariantLegacy) ? ` / ${line.VariantLegacy}` : ''}`;
}

function numberShape(value) {
  const text = cleanText(value);
  return text ? text.replace(/\d+/g, '9').replace(/[A-Za-z]+/g, 'A') : '(kosong)';
}

function checkPurchaseOrders(ctx) {
  const { tables, issues, index } = ctx;
  const pos = tables.PURCHASE_ORDERS.records;
  const customerOf = (po) => ctx.customerName(po.CustomerID) ?? '(tanpa customer)';
  const linesOf = (po) => index.linesByPo.get(po.POID) || [];
  const summaryOf = (po) =>
    `${customerOf(po)}; tanggal ${po.PODate ?? '-'}; status ${po.Status ?? '-'}; ${linesOf(po).length} line; ${po.SourceSheet} baris ${po.LegacyRow}`;

  const prefixOf = (number) => {
    const key = documentKey(number);
    const prefix = key ? key.replace(/\d.*$/, '') : '';
    return prefix.length >= 3 ? prefix : null;
  };
  const customersByPrefix = new Map();
  for (const po of pos) {
    const prefix = prefixOf(po.PONumber);
    if (!prefix || !cleanText(po.CustomerID)) continue;
    if (!customersByPrefix.has(prefix)) customersByPrefix.set(prefix, new Set());
    customersByPrefix.get(prefix).add(po.CustomerID);
  }
  const customersByProduct = new Map();
  for (const line of tables.PO_LINES.records) {
    const customerId = index.poById.get(line.POID)?.CustomerID;
    if (!cleanText(customerId)) continue;
    if (!customersByProduct.has(line.ProductID)) customersByProduct.set(line.ProductID, new Set());
    customersByProduct.get(line.ProductID).add(customerId);
  }
  const customerCandidates = (po) => {
    const prefix = prefixOf(po.PONumber);
    const byPrefix = prefix ? [...(customersByPrefix.get(prefix) || [])] : [];
    if (byPrefix.length === 1) return { ids: byPrefix, method: `awalan nomor "${prefix}" hanya dipakai customer ini` };
    const byProduct = new Set(linesOf(po).flatMap((line) => [...(customersByProduct.get(line.ProductID) || [])]));
    if (byProduct.size === 1) return { ids: [...byProduct], method: 'produk di PO ini hanya pernah dipesan customer ini' };
    if (byProduct.size === 0) {
      const codes = linesOf(po).map((line) => productCode(index.productById.get(line.ProductID))).filter(Boolean);
      const byCode = new Set(
        codes.flatMap((code) =>
          (index.productsByCode.get(code) || []).flatMap((product) => [...(customersByProduct.get(product.ProductID) || [])]),
        ),
      );
      if (byCode.size === 1) {
        return { ids: [...byCode], method: `bukti lemah: kode produk ${codes.join(', ')} hanya pernah dipesan customer ini` };
      }
    }
    return { ids: [], method: '' };
  };

  for (const po of pos) {
    const base = ctx.lineage('PURCHASE_ORDERS', po);
    const number = cleanText(po.PONumber);
    if (!number) {
      issues.add('PO_NUMBER_MISSING', { ...base, field: 'PONumber', description: 'PO tidak memiliki nomor PO.', evidence: summaryOf(po) });
    } else if (!/\d/.test(number)) {
      issues.add('PO_NUMBER_PLACEHOLDER', {
        ...base,
        field: 'PONumber',
        value: number,
        description: `Nomor PO berisi teks "${number}", bukan nomor dokumen.`,
        evidence: summaryOf(po),
      });
    } else if (/\s/.test(String(po.PONumber))) {
      issues.add('PO_NUMBER_WHITESPACE', {
        ...base,
        field: 'PONumber',
        value: po.PONumber,
        candidate_reference: documentKey(po.PONumber),
        candidate_method: 'tanpa-spasi (hanya untuk pencocokan)',
        description: 'Nomor PO mengandung spasi. Nilai asli dipertahankan; pencocokan memakai versi tanpa spasi.',
      });
    }

    if (!cleanText(po.CustomerID)) {
      const candidates = customerCandidates(po);
      issues.add('PO_CUSTOMER_MISSING', {
        ...base,
        field: 'CustomerID',
        value: number ?? '',
        candidate_reference: candidates.ids.map((id) => `${id} (${ctx.customerName(id)})`).join('; '),
        candidate_method: candidates.method,
        description: 'PO tidak memiliki customer.',
        evidence: `${summaryOf(po)}; produk: ${linesOf(po).map(lineLabel).join(' | ')}`,
      });
    }
    if (!po.PODate) {
      issues.add('PO_DATE_MISSING', { ...base, field: 'PODate', description: 'PO tidak memiliki tanggal.', evidence: summaryOf(po) });
    }
    if (cleanText(po.Status) && !mapPoStatus(po.Status)) {
      issues.add('PO_STATUS_UNMAPPED', {
        ...base,
        field: 'Status',
        value: po.Status,
        description: `Status "${po.Status}" tidak punya padanan di enum PO target (OPEN, ON_PROCESS, PARTIAL, CLOSED, CANCELLED).`,
        evidence: summaryOf(po),
      });
    }
    const term = cleanText(po.PaymentTerm);
    if (term && /invoice|belum|sudah|lunas/i.test(term)) {
      issues.add('PO_PAYMENT_TERM_INVALID', {
        ...base,
        field: 'PaymentTerm',
        value: term,
        description: `PaymentTerm "${term}" menggambarkan status invoice, bukan termin pembayaran.`,
      });
    }
    const lines = linesOf(po);
    if (!lines.length) {
      issues.add('PO_WITHOUT_LINES', { ...base, description: 'PO tidak memiliki PO line.', evidence: summaryOf(po) });
    }
    const differing = lines.filter((line) => statusLabel(line.StatusSource) !== statusLabel(po.Status));
    if (differing.length) {
      issues.add('PO_LINE_STATUS_MISMATCH', {
        ...base,
        field: 'Status',
        value: po.Status ?? '',
        candidate_reference: differing.map((line) => `${line.POLineID}: ${line.StatusSource}`).join('; '),
        description: `Status header "${po.Status}" berbeda dengan status ${differing.length} dari ${lines.length} line.`,
      });
    }
  }

  const numbered = pos.filter((po) => /\d/.test(cleanText(po.PONumber) ?? ''));
  const duplicateGroups = [];
  for (const group of groupBy(numbered, (po) => `${po.CustomerID ?? ''}|${documentKey(po.PONumber)}`).values()) {
    if (group.length < 2) continue;
    duplicateGroups.push(group.map((po) => po.POID));
    for (const po of group) {
      issues.add('PO_DUPLICATE_NUMBER', {
        ...ctx.lineage('PURCHASE_ORDERS', po),
        field: 'PONumber',
        value: po.PONumber,
        candidate_reference: group.filter((other) => other !== po).map((other) => other.POID).join('; '),
        candidate_method: 'customer-dan-nomor-sama',
        description: `Nomor PO ${po.PONumber} milik ${customerOf(po)} tercatat ${group.length} kali. Tidak digabung otomatis.`,
        evidence: group.map((other) => `${other.POID}: ${summaryOf(other)}`).join(' | '),
      });
    }
  }
  for (const [number, group] of groupBy(numbered, (po) => documentKey(po.PONumber))) {
    const customers = new Set(group.map((po) => po.CustomerID ?? ''));
    if (customers.size < 2) continue;
    for (const po of group) {
      issues.add('PO_NUMBER_SHARED_ACROSS_CUSTOMERS', {
        ...ctx.lineage('PURCHASE_ORDERS', po),
        field: 'PONumber',
        value: po.PONumber,
        candidate_reference: group.filter((other) => other !== po).map((other) => `${other.POID} (${customerOf(other)})`).join('; '),
        description: `Nomor ${number} dipakai ${customers.size} customer berbeda.`,
      });
    }
  }

  let splitGroups = 0;
  const unnumbered = pos.filter((po) => !cleanText(po.PONumber));
  const sameOrigin = (po) =>
    [po.CustomerID ?? '', po.PODate ?? '', ctx.sources.resolve(po.SourceFile) ?? '', cleanText(po.SourceSheet)].join('|');
  for (const group of groupBy(unnumbered, sameOrigin).values()) {
    const sorted = [...group].sort((a, b) => a.LegacyRow - b.LegacyRow);
    const runs = [[sorted[0]]];
    for (const po of sorted.slice(1)) {
      const run = runs[runs.length - 1];
      if (po.LegacyRow === run[run.length - 1].LegacyRow + 1) run.push(po);
      else runs.push([po]);
    }
    for (const run of runs.filter((candidate) => candidate.length > 1)) {
      splitGroups += 1;
      for (const po of run) {
        issues.add('PO_SPLIT_CANDIDATE', {
          ...ctx.lineage('PURCHASE_ORDERS', po),
          field: 'POID',
          candidate_reference: run.filter((other) => other !== po).map((other) => other.POID).join('; '),
          candidate_method: 'tanpa-nomor, customer & tanggal sama, baris legacy berurutan',
          description: `PO tanpa nomor ini berurutan dengan ${run.length - 1} PO tanpa nomor lain; bisa jadi satu PO dengan beberapa line. Tidak digabung otomatis.`,
          evidence: run.map((other) => `${other.POID}: ${lineLabel(linesOf(other)[0])}`).join(' | '),
        });
      }
    }
  }

  const shapes = [...groupBy(pos, (po) => numberShape(po.PONumber))]
    .map(([shape, group]) => ({
      shape,
      count: group.length,
      example: cleanText(group[0].PONumber),
      customers: [...new Set(group.map(customerOf))].length,
    }))
    .sort((a, b) => b.count - a.count);

  ctx.stats.purchaseOrders = {
    total: pos.length,
    bySource: countBy(pos, (po) => ctx.sources.resolve(po.SourceFile)),
    bySourceSheet: countBy(pos, (po) => cleanText(po.SourceSheet)),
    withNumber: numbered.length,
    withoutNumber: unnumbered.length,
    withoutCustomer: pos.filter((po) => !cleanText(po.CustomerID)).length,
    withoutDate: pos.filter((po) => !po.PODate).length,
    duplicateNumberGroups: duplicateGroups,
    splitCandidateGroups: splitGroups,
    statusRaw: countBy(pos, (po) => cleanText(po.Status)),
    statusMapped: countBy(pos, (po) => statusLabel(po.Status)),
    paymentTerms: countBy(pos, (po) => cleanText(po.PaymentTerm)),
    linesPerPo: countBy(pos, (po) => linesOf(po).length),
    numberShapes: shapes,
    prefixesOwnedByOneCustomer: [...customersByPrefix].filter(([, owners]) => owners.size === 1).length,
  };
}

function checkPoLines(ctx) {
  const { tables, issues, index } = ctx;
  const lines = tables.PO_LINES.records;
  const bySource = {};
  const bucket = (source) =>
    (bySource[source] ??= {
      lines: 0,
      ordered: 0,
      withLegacyDelivered: 0,
      legacyDelivered: 0,
      legacyMatchesLinked: 0,
      linesWithLinkedDeliveries: 0,
      linkedDelivered: 0,
      openLines: 0,
      legacyOutstandingOpen: 0,
      computedOutstandingOpen: 0,
    });
  const totals = { formulaChecked: 0, formulaMismatch: 0, negativeLegacyOutstanding: 0, overDeliveredComputed: 0, productNameDiffers: 0 };

  for (const line of lines) {
    const base = ctx.lineage('PO_LINES', line);
    const po = index.poById.get(line.POID);
    const ordered = line.OrderQuantity;
    const legacyDelivered = line.DeliveredQuantitySource;
    const legacyReturned = line.ReturnQuantitySource;
    const legacyOutstanding = line.OutstandingSource;
    const linked = (index.deliveriesByLine.get(line.POLineID) || []).filter(
      (delivery) => typeof delivery.DeliveredQuantity === 'number',
    );
    const linkedDelivered = sumBy(linked, (delivery) => delivery.DeliveredQuantity);
    const unresolvedOnPo = (index.deliveriesByPo.get(line.POID) || []).filter((delivery) => !cleanText(delivery.POLineID));
    const open = !CLOSED_STATUSES.has(statusLabel(line.StatusSource));
    const where = `PO ${po?.PONumber ?? line.POID} / ${lineLabel(line)}`;

    for (const stats of [bucket(base.source_file), bucket('(semua sumber)')]) {
      stats.lines += 1;
      stats.ordered += ordered ?? 0;
      stats.linkedDelivered += linkedDelivered;
      if (linked.length) stats.linesWithLinkedDeliveries += 1;
      if (typeof legacyDelivered === 'number') {
        stats.withLegacyDelivered += 1;
        stats.legacyDelivered += legacyDelivered;
        if (approxEqual(legacyDelivered, linkedDelivered)) stats.legacyMatchesLinked += 1;
      }
      if (open) {
        stats.openLines += 1;
        stats.legacyOutstandingOpen += Math.max(0, legacyOutstanding ?? 0);
        stats.computedOutstandingOpen += Math.max(0, (ordered ?? 0) - linkedDelivered);
      }
    }

    if (typeof legacyOutstanding === 'number' && legacyOutstanding < 0) {
      totals.negativeLegacyOutstanding += 1;
      issues.add('LINE_OVER_DELIVERED_LEGACY', {
        ...base,
        field: 'OutstandingSource',
        value: legacyOutstanding,
        description: `Outstanding legacy ${legacyOutstanding}: pengiriman melebihi order. Rumus MAX(0, ...) akan menampilkan 0.`,
        evidence: `${where}; order ${ordered}; delivered legacy ${legacyDelivered ?? '-'}; retur legacy ${legacyReturned ?? '-'}`,
      });
    }
    if ([ordered, legacyDelivered, legacyReturned, legacyOutstanding].every((value) => typeof value === 'number')) {
      totals.formulaChecked += 1;
      const expected = ordered - legacyDelivered + legacyReturned;
      if (!approxEqual(expected, legacyOutstanding)) {
        totals.formulaMismatch += 1;
        issues.add('LINE_LEGACY_FORMULA_MISMATCH', {
          ...base,
          field: 'OutstandingSource',
          value: legacyOutstanding,
          candidate_reference: expected,
          candidate_method: 'order - delivered + retur',
          description: `Order ${ordered} - delivered ${legacyDelivered} + retur ${legacyReturned} = ${expected}, sedangkan outstanding legacy ${legacyOutstanding}.`,
          evidence: where,
        });
      }
    }
    if (typeof legacyDelivered === 'number' && !approxEqual(legacyDelivered, linkedDelivered)) {
      issues.add('LINE_RECONCILIATION_GAP', {
        ...base,
        field: 'DeliveredQuantitySource',
        value: legacyDelivered,
        candidate_reference: linkedDelivered,
        candidate_method: `jumlah ${linked.length} delivery tertaut`,
        description: `Delivered legacy ${legacyDelivered} vs total delivery tertaut ${linkedDelivered} (selisih ${legacyDelivered - linkedDelivered}).`,
        evidence: `${where}; ${unresolvedOnPo.length} delivery di PO ini belum tertaut ke line`,
      });
    }
    if (typeof ordered === 'number' && linkedDelivered > ordered) {
      totals.overDeliveredComputed += 1;
      issues.add('LINE_OVER_DELIVERED_COMPUTED', {
        ...base,
        field: 'OrderQuantity',
        value: ordered,
        candidate_reference: linkedDelivered,
        description: `Total delivery tertaut ${linkedDelivered} melebihi order ${ordered}.`,
        evidence: where,
      });
    }
    const product = index.productById.get(line.ProductID);
    if (product && textKey(product.ProductName) !== textKey(line.ProductNameLegacy)) {
      totals.productNameDiffers += 1;
      issues.add('LINE_PRODUCT_NAME_DIFFERS', {
        ...base,
        field: 'ProductNameLegacy',
        value: line.ProductNameLegacy ?? '',
        candidate_reference: `${product.ProductID} (${product.ProductName})`,
        description: 'Nama produk di line berbeda dengan nama di master produk yang ditautkan.',
      });
    }
  }

  ctx.stats.poLines = {
    total: lines.length,
    statusRaw: countBy(lines, (line) => cleanText(line.StatusSource)),
    statusMapped: countBy(lines, (line) => statusLabel(line.StatusSource)),
    withLegacyDelivered: lines.filter((line) => typeof line.DeliveredQuantitySource === 'number').length,
    withLegacyReturned: lines.filter((line) => typeof line.ReturnQuantitySource === 'number').length,
    withLegacyOutstanding: lines.filter((line) => typeof line.OutstandingSource === 'number').length,
    ...totals,
    reconciliationBySource: bySource,
  };
}

function checkDeliveries(ctx) {
  const { tables, issues, index } = ctx;
  const deliveries = tables.DELIVERIES.records;
  const existing = ctx.existingIssueByRecord || new Map();
  const outcomes = {};
  const tally = (key) => {
    outcomes[key] = (outcomes[key] || 0) + 1;
  };

  for (const delivery of deliveries) {
    const base = ctx.lineage('DELIVERIES', delivery);
    const existingIssueId = existing.get(delivery.DeliveryID)?.IssueID ?? '';
    const product = cleanText(delivery.ProductID) ? index.productById.get(delivery.ProductID) : null;
    const summary =
      `SJ ${delivery.SJNumber ?? '-'}; tgl ${delivery.DeliveryDate ?? '-'}; qty ${delivery.DeliveredQuantity ?? '-'}; ` +
      `tujuan ${delivery.Destination ?? '-'}${product ? `; produk ${product.ProductName}` : ''}`;
    const isEmpty =
      !delivery.DeliveryDate &&
      delivery.DeliveredQuantity === null &&
      !cleanText(delivery.SJNumber) &&
      !cleanText(delivery.Destination);
    if (isEmpty) {
      tally('baris kosong');
      issues.add('DELIVERY_EMPTY_ROW', {
        ...base,
        existing_issue_id: existingIssueId,
        description: 'Baris tanpa tanggal, qty, SJ dan tujuan (hanya ID dan lineage). Tidak diimpor sebagai delivery.',
      });
      continue;
    }

    if (!cleanText(delivery.POID)) {
      tally(product ? 'PO tidak ditemukan / produk diketahui' : 'PO tidak ditemukan / produk tidak diketahui');
      issues.add('DELIVERY_PO_UNRESOLVED', {
        ...base,
        field: 'POID',
        existing_issue_id: existingIssueId,
        description: 'PO tidak ditemukan saat konversi, dan nomor PO asli tidak tersimpan di workbook.',
        evidence: summary,
      });
    } else if (!cleanText(delivery.POLineID)) {
      const po = index.poById.get(delivery.POID);
      const lines = index.linesByPo.get(delivery.POID) || [];
      let candidates = [];
      let method = '';
      if (lines.length === 1) {
        candidates = lines;
        method = 'single-line-po';
      } else if (product) {
        const match = linesForProduct(index, lines, product);
        candidates = match.lines;
        method = match.method ?? '';
      }
      const lineProduct = lines.length === 1 ? index.productById.get(lines[0].ProductID) : null;
      const note =
        product && lineProduct && lineProduct !== product ? `; produk delivery ≠ produk line (${lineProduct.ProductName})` : '';
      const outcome = candidates.length === 1 ? method : candidates.length > 1 ? 'beberapa kandidat' : 'tanpa kandidat';
      tally(`${product ? 'line tidak cocok' : 'produk & line tidak cocok'} / ${outcome}`);
      issues.add(product ? 'DELIVERY_LINE_UNRESOLVED' : 'DELIVERY_PRODUCT_UNRESOLVED', {
        ...base,
        field: product ? 'POLineID' : 'ProductID,POLineID',
        existing_issue_id: existingIssueId,
        candidate_reference: candidates.map((line) => `${line.POLineID} (${lineLabel(line)})`).join('; '),
        candidate_method: candidates.length ? `${method}${candidates.length > 1 ? ` (${candidates.length} kandidat)` : ''}` : '',
        description: product
          ? `Produk diketahui, tetapi tidak ada PO line dengan produk itu di PO ${po?.PONumber ?? delivery.POID}.`
          : `PO ${po?.PONumber ?? delivery.POID} ditemukan, tetapi produk/line tidak cocok saat konversi.`,
        evidence: `${summary}; PO punya ${lines.length} line: ${lines.map(lineLabel).join(' | ')}${note}`,
      });
    } else {
      tally('tertaut penuh (OK)');
      const line = index.lineById.get(delivery.POLineID);
      if (line && (line.POID !== delivery.POID || (cleanText(delivery.ProductID) && line.ProductID !== delivery.ProductID))) {
        issues.add('DELIVERY_LINK_INCONSISTENT', {
          ...base,
          field: 'POLineID',
          value: delivery.POLineID,
          description: `POID/ProductID delivery tidak sama dengan milik line ${delivery.POLineID}.`,
        });
      }
    }

    if (typeof delivery.DeliveredQuantity === 'number' && delivery.DeliveredQuantity < 0) {
      issues.add('DELIVERY_NEGATIVE_QTY', {
        ...base,
        field: 'DeliveredQuantity',
        value: delivery.DeliveredQuantity,
        description: `Qty ${delivery.DeliveredQuantity} dengan SJ "${delivery.SJNumber ?? '-'}": retur yang dicatat sebagai delivery negatif.`,
        evidence: summary,
      });
    }
    if (!cleanText(delivery.SJNumber)) {
      issues.add('DELIVERY_SJ_MISSING', { ...base, field: 'SJNumber', description: 'Delivery tanpa nomor SJ.', evidence: summary });
    } else if (/^PIK-SJ-\d+$/.test(documentKey(delivery.SJNumber)) && !/^PIK-SJ-\d{5}$/.test(documentKey(delivery.SJNumber))) {
      issues.add('DELIVERY_SJ_NUMBER_NONSTANDARD', {
        ...base,
        field: 'SJNumber',
        value: delivery.SJNumber,
        description: `Nomor ${delivery.SJNumber} tidak berformat PIK-SJ + 5 digit seperti seri utama; perlu dicek ke dokumen SJ. Tidak dipakai untuk uji urutan tanggal.`,
        evidence: summary,
      });
    }
    if (!delivery.DeliveryDate) {
      issues.add('DELIVERY_DATE_MISSING', { ...base, field: 'DeliveryDate', description: 'Delivery tanpa tanggal.', evidence: summary });
    }
    if (cleanText(delivery.Note)) {
      issues.add('DELIVERY_NOTE_REVIEW', {
        ...base,
        field: 'Note',
        value: delivery.Note,
        description: `Kolom Note berisi "${delivery.Note}"; periksa apakah seharusnya nomor SJ.`,
        evidence: summary,
      });
    }
  }

  const withSj = deliveries.filter((delivery) => /\d/.test(cleanText(delivery.SJNumber) ?? ''));
  const identicalKey = (delivery) =>
    [
      documentKey(delivery.SJNumber),
      delivery.DeliveryDate,
      delivery.DeliveredQuantity,
      nameKey(delivery.Destination),
      delivery.POID ?? '',
      delivery.ProductID ?? '',
      delivery.POLineID ?? '',
    ].join('|');
  let identicalGroups = 0;
  for (const group of groupBy(withSj, identicalKey).values()) {
    if (group.length < 2) continue;
    identicalGroups += 1;
    for (const delivery of group) {
      issues.add('DELIVERY_DUPLICATE_CANDIDATE', {
        ...ctx.lineage('DELIVERIES', delivery),
        field: 'DeliveryID',
        candidate_reference: group.filter((other) => other !== delivery).map((other) => other.DeliveryID).join('; '),
        candidate_method: 'SJ, tanggal, qty, tujuan, PO, produk & line identik',
        description:
          `Baris ini identik dengan ${group.length - 1} baris lain (SJ ${delivery.SJNumber}, qty ${delivery.DeliveredQuantity}). ` +
          `Bisa entri ganda${delivery.ProductID ? '' : ', atau dua item berbeda ber-qty sama karena produknya kosong'}; tidak dihapus.`,
      });
    }
  }
  const sjGroups = groupBy(withSj, (delivery) => documentKey(delivery.SJNumber));
  const conflicts = { date: 0, destination: 0 };
  for (const [sj, group] of sjGroups) {
    if (group.length < 2) continue;
    const dates = [...new Set(group.map((delivery) => delivery.DeliveryDate).filter(Boolean))];
    const destinations = [...new Set(group.map((delivery) => cleanText(delivery.Destination)).filter(Boolean))];
    if (dates.length > 1) {
      conflicts.date += 1;
      for (const delivery of group) {
        issues.add('DELIVERY_SJ_DATE_CONFLICT', {
          ...ctx.lineage('DELIVERIES', delivery),
          field: 'DeliveryDate',
          value: delivery.DeliveryDate ?? '',
          candidate_reference: dates.join('; '),
          description: `SJ ${sj} dipakai ${group.length} baris dengan ${dates.length} tanggal berbeda.`,
        });
      }
    }
    if (new Set(destinations.map(nameKey)).size > 1) {
      conflicts.destination += 1;
      for (const delivery of group) {
        issues.add('DELIVERY_SJ_DESTINATION_CONFLICT', {
          ...ctx.lineage('DELIVERIES', delivery),
          field: 'Destination',
          value: delivery.Destination ?? '',
          candidate_reference: destinations.join('; '),
          description: `SJ ${sj} dipakai ${group.length} baris dengan tujuan berbeda.`,
        });
      }
    }
  }

  const poCustomerKey = (delivery) =>
    nameKey(ctx.customerName(index.poById.get(delivery.POID)?.CustomerID) ?? '');
  const destinations = [...groupBy(deliveries, (delivery) => cleanText(delivery.Destination))]
    .map(([destination, group]) => ({
      destination,
      deliveries: group.length,
      matchesCustomer: (index.customersByNameKey.get(nameKey(destination)) || []).map((customer) => customer.CustomerName),
      sameAsPoCustomer: group.filter((delivery) => delivery.POID && poCustomerKey(delivery) === nameKey(destination)).length,
    }))
    .sort((a, b) => b.deliveries - a.deliveries);
  const withPoAndDestination = deliveries.filter((delivery) => delivery.POID && cleanText(delivery.Destination));

  ctx.stats.deliveries = {
    total: deliveries.length,
    byFlag: countBy(deliveries, (delivery) => cleanText(delivery.MigrationFlag)),
    byFlagAndSource: countBy(deliveries, (delivery) => `${ctx.sources.resolve(delivery.SourceFile)} | ${delivery.MigrationFlag}`),
    bySourceSheet: countBy(deliveries, (delivery) => cleanText(delivery.SourceSheet)),
    linkOutcomes: outcomes,
    negativeQuantity: deliveries.filter((delivery) => delivery.DeliveredQuantity < 0).length,
    quantityTotal: sumBy(deliveries, (delivery) => delivery.DeliveredQuantity),
    sjFormats: countBy(deliveries, (delivery) => numberShape(delivery.SJNumber)),
    sjNumbersOnSeveralRows: [...sjGroups.values()].filter((group) => group.length > 1).length,
    identicalRowGroups: identicalGroups,
    sjConflicts: conflicts,
    destinationCount: destinations.length,
    destinations,
    destinationIsPoCustomer: withPoAndDestination.filter((delivery) => poCustomerKey(delivery) === nameKey(delivery.Destination)).length,
    deliveriesWithPoAndDestination: withPoAndDestination.length,
  };
}

function checkReturns(ctx) {
  const { tables, issues, index } = ctx;
  const returns = tables.RETURNS.records;
  const references = {};
  for (const ret of returns) {
    const base = ctx.lineage('RETURNS', ret);
    const reference = checkPoReference(ctx, 'RETURNS', ret, null);
    references[reference] = (references[reference] || 0) + 1;

    const lines = index.linesByPo.get(ret.POID) || [];
    const product = cleanText(ret.ProductID) ? index.productById.get(ret.ProductID) : null;
    let match = product ? linesForProduct(index, lines, product) : { method: null, lines: [] };
    if (!match.lines.length) match = linesForLabel(index, lines, ret.ProductLegacy);
    if (!match.lines.length && lines.length === 1) match = { method: 'single-line-po', lines };
    const summary =
      `PO ${ret.PONumberLegacy ?? '-'}; produk legacy "${ret.ProductLegacy ?? '-'}"; qty ${ret.ReturnQuantity ?? '-'}; ` +
      `tgl ${ret.ReturnDate ?? '-'}; PO punya ${lines.length} line: ${lines.map(lineLabel).join(' | ')}`;

    if (!product) {
      const fromLines = [...new Set(match.lines.map((line) => index.productById.get(line.ProductID)))];
      const fromCatalogue = fromLines.length ? null : findProductCandidates(index, ret.ProductLegacy);
      const candidates = fromLines.length ? fromLines : fromCatalogue.products;
      issues.add('RETURN_PRODUCT_UNRESOLVED', {
        ...base,
        field: 'ProductID',
        value: ret.ProductLegacy ?? '',
        candidate_reference: candidates.map((candidate) => `${candidate.ProductID} (${candidate.ProductName})`).join('; '),
        candidate_method: fromLines.length ? `via PO line: ${match.method}` : (fromCatalogue.method ?? ''),
        description: 'Produk retur belum tertaut ke master produk.',
        evidence: summary,
      });
    }
    issues.add('RETURN_LINE_UNRESOLVED', {
      ...base,
      field: 'POLineID',
      candidate_reference: match.lines.map((line) => `${line.POLineID} (${lineLabel(line)})`).join('; '),
      candidate_method: match.lines.length
        ? `${match.method}${match.lines.length > 1 ? ` (${match.lines.length} kandidat)` : ''}`
        : '',
      description: 'Sheet RETURNS tidak punya kolom PO line; retur hanya tertaut di level PO.',
      evidence: summary,
    });
    if (!ret.ReturnDate) {
      issues.add('RETURN_DATE_MISSING', { ...base, field: 'ReturnDate', description: 'Retur tanpa tanggal.', evidence: summary });
    }
  }

  const negatives = tables.DELIVERIES.records.filter((delivery) => delivery.DeliveredQuantity < 0);
  const negativeMatchingReturn = negatives.filter((delivery) =>
    returns.some((ret) => ret.POID === delivery.POID && approxEqual(Math.abs(delivery.DeliveredQuantity), ret.ReturnQuantity ?? NaN)),
  );
  ctx.stats.returns = {
    total: returns.length,
    withProduct: returns.filter((ret) => cleanText(ret.ProductID)).length,
    withDate: returns.filter((ret) => ret.ReturnDate).length,
    withSj: returns.filter((ret) => cleanText(ret.SJNumber)).length,
    withAttachment: returns.filter((ret) => cleanText(ret.Attachment)).length,
    quantityTotal: sumBy(returns, (ret) => ret.ReturnQuantity),
    byPoNumber: countBy(returns, (ret) => cleanText(ret.PONumberLegacy)),
    poReference: references,
    negativeDeliveries: negatives.length,
    negativeDeliveryQuantity: sumBy(negatives, (delivery) => delivery.DeliveredQuantity),
    negativeDeliveriesMatchingAReturn: negativeMatchingReturn.length,
    posWithNegativeDeliveries: [...new Set(negatives.map((delivery) => delivery.POID))].length,
    posWithReturns: [...new Set(returns.map((ret) => ret.POID))].length,
    posWithBoth: [...new Set(negatives.map((delivery) => delivery.POID))].filter((po) => returns.some((ret) => ret.POID === po)).length,
  };
}

module.exports = function checkOrders(ctx) {
  checkPurchaseOrders(ctx);
  checkDeliveries(ctx);
  checkPoLines(ctx);
  checkReturns(ctx);
};
module.exports.mapPoStatus = mapPoStatus;
module.exports.statusLabel = statusLabel;
