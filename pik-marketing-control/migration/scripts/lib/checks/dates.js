'use strict';

/**
 * Date evidence. Excel stored every date as a serial number, but several legacy sources
 * evidently held dd/mm text that the AppSheet conversion read as mm/dd whenever both
 * parts were <= 12. A swap is only reported when independent evidence supports it:
 *   - the month (or full date) encoded in the PO number or invoice number, or
 *   - the company-wide, sequential delivery-note (SJ) numbering.
 * Suggestions are never applied here (decision D6).
 */

const {
  MONTH_TOKENS,
  ROMAN_MONTHS,
  cleanText,
  daysBetween,
  documentKey,
  isAmbiguousDayMonth,
  isoMonthIndex,
  isoToDayNumber,
  makeIsoDate,
  monthIndex,
  swapDayMonth,
} = require('../normalize');
const { groupBy, median } = require('../matching');

const ROMAN = '(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I)';
const num = Number;

// Date information carried by customer PO numbers, applied to the whitespace-free, upper-case number.
const PO_NUMBER_DATE_RULES = [
  { name: 'PO-dd.mm.yy.nnn', pattern: /^PO-(\d{2})\.(\d{2})\.(\d{2})\.\d+$/, date: (m) => [2000 + num(m[3]), num(m[2]), num(m[1])] },
  { name: 'PO/yymmdd/nnnn/...', pattern: /^PO\/(\d{2})(\d{2})(\d{2})\/\d+\//, date: (m) => [2000 + num(m[1]), num(m[2]), num(m[3])] },
  { name: 'PURC/yyyymmdd/...', pattern: /^PURC\/(20\d{2})(\d{2})(\d{2})\//, date: (m) => [num(m[1]), num(m[2]), num(m[3])] },
  { name: 'PO/XXX/yymm#####', pattern: /^P[O0]\/[A-Z]{2,4}\/(\d{2})(0[1-9]|1[0-2])\d{5}$/, month: (m) => [2000 + num(m[1]), num(m[2])] },
  { name: 'XXX/yyyymm-nnn', pattern: /^[A-Z]+\/(20\d{2})(0[1-9]|1[0-2])-\d+$/, month: (m) => [num(m[1]), num(m[2])] },
  { name: 'Pyyyymm#P####', pattern: /^P(20\d{2})(0[1-9]|1[0-2])\dP\d+$/, month: (m) => [num(m[1]), num(m[2])] },
  { name: 'PO.yyyy.mm.nnnnn', pattern: /^PO\.(20\d{2})\.(0[1-9]|1[0-2])\.\d+$/, month: (m) => [num(m[1]), num(m[2])] },
  { name: 'yymm...', pattern: /^(2\d)(0[1-9]|1[0-2])(?:[A-Z]|\/PO)/, month: (m) => [2000 + num(m[1]), num(m[2])] },
  { name: '.../ROMAWI/yyyy', pattern: new RegExp(`/${ROMAN}/(20\\d{2})(?:$|\\D)`), month: (m) => [num(m[2]), ROMAN_MONTHS[m[1]]] },
  { name: '.../yyyy/ROMAWI-n', pattern: new RegExp(`/(20\\d{2})/${ROMAN}-`), month: (m) => [num(m[1]), ROMAN_MONTHS[m[2]]] },
  { name: '.../ROMAWI/yy', pattern: new RegExp(`/${ROMAN}/(\\d{2})(?:$|[-/])`), month: (m) => [2000 + num(m[2]), ROMAN_MONTHS[m[1]]] },
  { name: 'yyyy/.../PO-ROMAWI/...', pattern: new RegExp(`^(20\\d{2})/.*PO-${ROMAN}/`), month: (m) => [num(m[1]), ROMAN_MONTHS[m[2]]] },
  { name: '.../m/yyyy', pattern: /\/(\d{1,2})\/(20\d{2})$/, month: (m) => [num(m[2]), num(m[1])] },
  { name: '.../yyyy/m-n', pattern: /\/(20\d{2})\/(\d{1,2})-\d+$/, month: (m) => [num(m[1]), num(m[2])] },
  { name: 'n/mm/PO-XXX/yy', pattern: /^\d+\/(0[1-9]|1[0-2])\/PO-[A-Z]+\/(\d{2})$/, month: (m) => [2000 + num(m[2]), num(m[1])] },
];

// PIK invoice numbers: PIK/<MONTH>/<yy>/<INV|TUM>/<seq>.
const INVOICE_NUMBER_DATE_RULES = [
  { name: 'PIK/BULAN/yy/...', pattern: /^PIK\/([A-Z]+)\/(\d{2})\//, month: (m) => [2000 + num(m[2]), MONTH_TOKENS[m[1]]] },
];

// Delivery-note series outside the main PIK-SJ numbering that carry the month, e.g. SJ-XYZ-0626-001.
const SJ_NUMBER_DATE_RULES = [
  { name: 'SJ-XXX-mmyy-nnn', pattern: /^SJ-[A-Z]+-(0[1-9]|1[0-2])(\d{2})-\d+$/, month: (m) => [2000 + num(m[2]), num(m[1])] },
];

// Main company-wide delivery-note series: PIK-SJ- followed by exactly five digits.
const MAIN_SJ_SERIES = /^PIK-SJ-(\d{5})$/;
const SJ_WINDOW = 7; // neighbouring SJ numbers on each side
const SJ_TOLERANCE_DAYS = 20;

function encodedDate(number, rules) {
  const key = documentKey(number);
  if (!key) return null;
  for (const rule of rules) {
    const match = key.match(rule.pattern);
    if (!match) continue;
    if (rule.date) {
      const iso = makeIsoDate(...rule.date(match));
      return iso ? { rule: rule.name, kind: 'date', iso, label: iso } : null;
    }
    const [year, month] = rule.month(match);
    if (!(month >= 1 && month <= 12)) return null;
    return { rule: rule.name, kind: 'month', year, month, label: `${year}-${String(month).padStart(2, '0')}` };
  }
  return null;
}

/**
 * Compares a date with the date encoded in a document number.
 * Verdicts: consistent | near | adjacent | ambiguous | swap | mismatch.
 */
function assess(iso, encoded) {
  const swapped = swapDayMonth(iso);
  if (encoded.kind === 'date') {
    const distance = daysBetween(encoded.iso, iso);
    if (distance === 0) return { verdict: 'consistent' };
    if (Math.abs(distance) <= 7) return { verdict: 'near' };
    if (swapped && Math.abs(daysBetween(encoded.iso, swapped)) <= 7) return { verdict: 'swap', suggestion: swapped };
    return {
      verdict: 'mismatch',
      distance: `${distance} hari`,
      swapNote: swapped ? `; jika ditukar menjadi ${swapped}, selisihnya ${daysBetween(encoded.iso, swapped)} hari` : '',
    };
  }
  const target = monthIndex(encoded.year, encoded.month);
  const offset = isoMonthIndex(iso) - target;
  if (offset === 0) return { verdict: 'consistent' };
  const swappedOffset = swapped === null ? null : isoMonthIndex(swapped) - target;
  if (Math.abs(offset) === 1) return swappedOffset === 0 ? { verdict: 'ambiguous', suggestion: swapped } : { verdict: 'adjacent' };
  if (swappedOffset === 0) return { verdict: 'swap', suggestion: swapped };
  return {
    verdict: 'mismatch',
    distance: `${offset} bulan`,
    swapNote: swapped ? `; jika ditukar menjadi ${swapped}, selisihnya ${swappedOffset} bulan` : '',
  };
}

function createEvidenceLedger() {
  const ledger = {};
  return {
    record(key, iso, verdict) {
      const entry = (ledger[key] ??= {
        dates: 0,
        ambiguous: 0,
        ambiguousConfirmedAsIs: 0,
        ambiguousLikelySwapped: 0,
        ambiguousUnverified: 0,
      });
      entry.dates += 1;
      if (!isAmbiguousDayMonth(iso)) return;
      entry.ambiguous += 1;
      if (verdict === 'consistent' || verdict === 'near') entry.ambiguousConfirmedAsIs += 1;
      else if (verdict === 'swap') entry.ambiguousLikelySwapped += 1;
      else entry.ambiguousUnverified += 1;
    },
    toJSON: () => ledger,
  };
}

function checkDocumentNumberDates(ctx, config, ledger) {
  const { issues, asOf } = ctx;
  const { sheet, numberField, dateField, rules, swapType, mismatchType, futureType, label } = config;
  const verdicts = {};
  const rulesUsed = {};
  for (const record of ctx.tables[sheet].records) {
    const iso = record[dateField];
    if (!iso) continue;
    const base = { ...ctx.lineage(sheet, record), field: dateField, value: iso };
    const source = `${sheet}.${dateField} | ${base.source_file}`;
    const encoded = encodedDate(record[numberField], rules);
    const future = iso > asOf;
    if (!encoded) {
      verdicts['tanpa pola tanggal'] = (verdicts['tanpa pola tanggal'] || 0) + 1;
      ledger.record(source, iso, 'unverified');
      if (future && futureType) {
        const swapped = swapDayMonth(iso);
        const usable = swapped && swapped <= asOf;
        issues.add(futureType, {
          ...base,
          candidate_reference: usable ? swapped : '',
          candidate_method: usable ? 'tukar hari/bulan (bukti lemah: hanya karena tanggal di masa depan)' : '',
          description: `${label} ${record[numberField] ?? ''}: tanggal ${iso} berada setelah workbook dibuat (${asOf}); nomornya tidak memuat tanggal pembanding.`,
        });
      }
      continue;
    }
    rulesUsed[encoded.rule] = (rulesUsed[encoded.rule] || 0) + 1;
    const result = assess(iso, encoded);
    verdicts[result.verdict] = (verdicts[result.verdict] || 0) + 1;
    ledger.record(source, iso, result.verdict);
    const context = `${label} ${cleanText(record[numberField])} menunjukkan ${encoded.label} (pola ${encoded.rule})`;
    if (result.verdict === 'swap') {
      ctx.dateSuggestions.set(`${sheet}|${record._row}|${dateField}`, result.suggestion);
      issues.add(swapType, {
        ...base,
        candidate_reference: result.suggestion,
        candidate_method: `tanggal di nomor dokumen (${encoded.kind === 'date' ? 'tanggal penuh' : 'bulan'})`,
        description: `${context}; ${iso} cocok bila hari & bulan ditukar menjadi ${result.suggestion}.`,
        evidence: future ? `tanggal asli ${iso} berada setelah workbook dibuat (${asOf})` : '',
      });
    } else if (result.verdict === 'mismatch') {
      issues.add(mismatchType, {
        ...base,
        description: `${context}, tetapi tanggal tercatat ${iso} (selisih ${result.distance}${result.swapNote}); tukar hari/bulan tidak menjelaskan selisih ini.`,
        evidence: future ? `tanggal berada setelah workbook dibuat (${asOf})` : '',
      });
    } else if (future && futureType) {
      issues.add(futureType, {
        ...base,
        description: `${context}; tanggal ${iso} berada setelah workbook dibuat (${asOf}).`,
      });
    }
  }
  return { verdicts, rulesUsed };
}

function checkSjSequence(ctx, ledger) {
  const { issues, asOf } = ctx;
  const deliveries = ctx.tables.DELIVERIES.records;
  const sjNumber = (value) => {
    const match = MAIN_SJ_SERIES.exec(documentKey(value) ?? '');
    return match ? Number(match[1]) : null;
  };
  const inSequence = deliveries.filter((delivery) => delivery.DeliveryDate && sjNumber(delivery.SJNumber) !== null);
  const bySj = groupBy(inSequence, (delivery) => sjNumber(delivery.SJNumber));
  const numbers = [...bySj.keys()].sort((a, b) => a - b);
  const dayOf = new Map(numbers.map((n) => [n, median(bySj.get(n).map((d) => isoToDayNumber(d.DeliveryDate)))]));
  const verdicts = {};
  const checked = new Set();

  numbers.forEach((sj, i) => {
    const neighbours = [...numbers.slice(Math.max(0, i - SJ_WINDOW), i), ...numbers.slice(i + 1, i + 1 + SJ_WINDOW)];
    if (neighbours.length < 4) return;
    const reference = median(neighbours.map((n) => dayOf.get(n)));
    const referenceIso = new Date(reference * 86400000).toISOString().slice(0, 10);
    for (const delivery of bySj.get(sj)) {
      checked.add(delivery);
      const iso = delivery.DeliveryDate;
      const deviation = Math.round(isoToDayNumber(iso) - reference);
      const swapped = swapDayMonth(iso);
      let verdict = 'consistent';
      if (Math.abs(deviation) > SJ_TOLERANCE_DAYS) {
        verdict =
          swapped && Math.abs(isoToDayNumber(swapped) - reference) <= SJ_TOLERANCE_DAYS ? 'swap' : 'out-of-sequence';
      }
      verdicts[verdict] = (verdicts[verdict] || 0) + 1;
      const base = { ...ctx.lineage('DELIVERIES', delivery), field: 'DeliveryDate', value: iso };
      ledger.record(`DELIVERIES.DeliveryDate | ${base.source_file}`, iso, verdict);
      const evidence =
        `acuan ${referenceIso} = median tanggal ${neighbours.length} SJ tetangga ` +
        `(PIK-SJ-${String(neighbours[0]).padStart(5, '0')} s/d PIK-SJ-${String(neighbours[neighbours.length - 1]).padStart(5, '0')}); ` +
        `selisih ${deviation > 0 ? '+' : ''}${deviation} hari`;
      if (verdict === 'swap') {
        issues.add('DELIVERY_DATE_SWAP_CANDIDATE', {
          ...base,
          candidate_reference: swapped,
          candidate_method: `urutan nomor SJ (±${SJ_WINDOW} SJ, toleransi ${SJ_TOLERANCE_DAYS} hari)`,
          description: `SJ ${delivery.SJNumber} bertanggal ${iso}, jauh dari SJ di sekitarnya; ${swapped} (hari/bulan ditukar) cocok dengan urutan.`,
          evidence: `${evidence}${iso > asOf ? `; tanggal asli setelah workbook dibuat (${asOf})` : ''}`,
        });
      } else if (verdict === 'out-of-sequence') {
        issues.add('DELIVERY_DATE_OUT_OF_SEQUENCE', {
          ...base,
          description: `SJ ${delivery.SJNumber} bertanggal ${iso}, tidak sesuai urutan SJ di sekitarnya; tukar hari/bulan juga tidak cocok.`,
          evidence,
        });
      }
    }
  });

  const monthEncoded = {};
  for (const delivery of deliveries) {
    if (!delivery.DeliveryDate || checked.has(delivery)) continue;
    const iso = delivery.DeliveryDate;
    const base = { ...ctx.lineage('DELIVERIES', delivery), field: 'DeliveryDate', value: iso };
    const key = `DELIVERIES.DeliveryDate | ${base.source_file}`;
    const encoded = encodedDate(delivery.SJNumber, SJ_NUMBER_DATE_RULES);
    if (encoded) {
      const result = assess(iso, encoded);
      monthEncoded[result.verdict] = (monthEncoded[result.verdict] || 0) + 1;
      ledger.record(key, iso, result.verdict);
      const context = `SJ ${delivery.SJNumber} menunjukkan ${encoded.label} (pola ${encoded.rule})`;
      if (result.verdict === 'swap') {
        issues.add('DELIVERY_DATE_SWAP_CANDIDATE', {
          ...base,
          candidate_reference: result.suggestion,
          candidate_method: 'bulan di nomor SJ',
          description: `${context}; ${iso} cocok bila hari & bulan ditukar menjadi ${result.suggestion}.`,
        });
      } else if (result.verdict === 'mismatch') {
        issues.add('DELIVERY_DATE_NUMBER_MISMATCH', {
          ...base,
          description: `${context}, tetapi tanggal tercatat ${iso} (selisih ${result.distance}${result.swapNote}).`,
        });
      }
      continue;
    }
    ledger.record(key, iso, 'unverified');
    if (iso > asOf) {
      const swapped = swapDayMonth(iso);
      issues.add('DELIVERY_DATE_FUTURE', {
        ...base,
        candidate_reference: swapped && swapped <= asOf ? swapped : '',
        candidate_method: swapped && swapped <= asOf ? 'tukar hari/bulan (bukti lemah)' : '',
        description: `Tanggal ${iso} berada setelah workbook dibuat (${asOf}) dan nomor SJ-nya tidak memberi pembanding.`,
      });
    }
  }

  return {
    deliveriesWithDate: deliveries.filter((delivery) => delivery.DeliveryDate).length,
    inMainPikSjSeries: inSequence.length,
    checkedAgainstNeighbours: checked.size,
    distinctSjNumbers: numbers.length,
    sjRange: numbers.length ? [numbers[0], numbers[numbers.length - 1]] : null,
    verdicts,
    monthEncodedSjVerdicts: monthEncoded,
    parameters: { windowPerSide: SJ_WINDOW, toleranceDays: SJ_TOLERANCE_DAYS },
  };
}

function checkPoFinancialDates(ctx) {
  const { issues, index } = ctx;
  const outcome = {};
  for (const record of ctx.tables.PO_FINANCIALS.records) {
    if (!record.PODate || !cleanText(record.POID)) continue;
    const po = index.poById.get(record.POID);
    if (!po?.PODate) continue;
    let verdict = 'sama';
    if (po.PODate !== record.PODate) verdict = swapDayMonth(record.PODate) === po.PODate ? 'tertukar' : 'berbeda';
    outcome[verdict] = (outcome[verdict] || 0) + 1;
    if (verdict !== 'sama') {
      issues.add('POF_DATE_DIFFERS_FROM_PO', {
        ...ctx.lineage('PO_FINANCIALS', record),
        field: 'PODate',
        value: record.PODate,
        candidate_reference: po.PODate,
        candidate_method: verdict === 'tertukar' ? 'hari/bulan tertukar terhadap PURCHASE_ORDERS' : 'tanggal di PURCHASE_ORDERS',
        description: `PODate ${record.PODate} berbeda dengan PURCHASE_ORDERS (${po.PODate}) untuk PO ${po.PONumber}.`,
      });
    }
  }
  return outcome;
}

function inventoryDateColumns(ctx, ledger) {
  const inventory = [];
  const evidence = ledger.toJSON();
  for (const [sheet, profile] of Object.entries(ctx.profiles)) {
    for (const column of profile.columns) {
      if (!column.date) continue;
      const verifiedKeys = Object.keys(evidence).filter((key) => key.startsWith(`${sheet}.${column.name} |`));
      const unverified = verifiedKeys.length
        ? verifiedKeys.reduce((sum, key) => sum + evidence[key].ambiguousUnverified, 0)
        : column.date.ambiguousDayMonth;
      inventory.push({ sheet, column: column.name, ...column.date, ambiguousWithoutEvidence: unverified });
      if (unverified > 0) {
        ctx.issues.add('DATE_AMBIGUOUS_DAY_MONTH', {
          workbook_sheet: sheet,
          field: column.name,
          value: unverified,
          description:
            `${unverified} dari ${column.date.count} tanggal di ${sheet}.${column.name} bisa dibaca dua arah (hari & bulan ≤ 12) ` +
            'dan tidak punya bukti pembanding; tidak dikoreksi, perlu dicek ke dokumen asli bila penting.',
        });
      }
    }
  }
  return inventory;
}

module.exports = function checkDates(ctx) {
  const ledger = createEvidenceLedger();
  ctx.dateSuggestions = new Map();
  const purchaseOrders = checkDocumentNumberDates(
    ctx,
    {
      sheet: 'PURCHASE_ORDERS',
      numberField: 'PONumber',
      dateField: 'PODate',
      rules: PO_NUMBER_DATE_RULES,
      swapType: 'PO_DATE_SWAP_CANDIDATE',
      mismatchType: 'PO_DATE_NUMBER_MISMATCH',
      futureType: 'PO_DATE_FUTURE',
      label: 'PO',
    },
    ledger,
  );
  const invoices = checkDocumentNumberDates(
    ctx,
    {
      sheet: 'INVOICES_PAYMENTS',
      numberField: 'InvoiceNumber',
      dateField: 'InvoiceDate',
      rules: INVOICE_NUMBER_DATE_RULES,
      swapType: 'INVOICE_DATE_SWAP_CANDIDATE',
      mismatchType: 'INVOICE_DATE_NUMBER_MISMATCH',
      futureType: null,
      label: 'Invoice',
    },
    ledger,
  );
  const sjSequence = checkSjSequence(ctx, ledger);
  const poFinancials = checkPoFinancialDates(ctx);
  ctx.stats.dates = {
    asOf: ctx.asOf,
    purchaseOrders,
    invoices,
    sjSequence,
    poFinancialsVsPurchaseOrders: poFinancials,
    ambiguityBySource: ledger.toJSON(),
    columns: inventoryDateColumns(ctx, ledger),
    poNumberRules: PO_NUMBER_DATE_RULES.map((rule) => rule.name),
  };
};
module.exports.encodedDate = encodedDate;
module.exports.assess = assess;
module.exports.PO_NUMBER_DATE_RULES = PO_NUMBER_DATE_RULES;
