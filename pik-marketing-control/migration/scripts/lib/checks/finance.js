'use strict';

/** Invoices/payments and the PO financial summary (legacy "ORDER SUMMARY"). */

const { approxEqual, cleanText, documentKey, round, stripFloatSuffix, swapDayMonth } = require('../normalize');
const { countBy, groupBy, sumBy } = require('../matching');
const { checkPoReference } = require('./references');

const isNumber = (value) => typeof value === 'number' && Number.isFinite(value);

function checkInvoices(ctx) {
  const { tables, issues } = ctx;
  const invoices = tables.INVOICES_PAYMENTS.records;
  const references = {};
  let floatReceipts = 0;
  for (const invoice of invoices) {
    const base = ctx.lineage('INVOICES_PAYMENTS', invoice);
    const reference = checkPoReference(ctx, 'INVOICES_PAYMENTS', invoice, 'INVOICE_PO_UNRESOLVED');
    references[reference] = (references[reference] || 0) + 1;
    const amount = invoice.InvoiceAmount;
    const paid = invoice.PaymentAmount;
    const outstanding = invoice.InvoiceOutstanding;
    const summary =
      `${invoice.InvoiceNumber}; tgl ${invoice.InvoiceDate ?? '-'}; nilai ${amount ?? '-'}; bayar ${paid ?? '-'} ` +
      `tgl ${invoice.PaymentDate ?? '-'}; outstanding ${outstanding ?? '-'}`;

    if (isNumber(paid) && !invoice.PaymentDate) {
      issues.add('INVOICE_PAYMENT_DATE_MISSING', {
        ...base,
        field: 'PaymentDate',
        value: paid,
        description:
          `PaymentAmount ${paid} terisi tetapi tanggal bayar kosong; belum pasti sudah dibayar ` +
          'atau PaymentAmount hanya salinan nilai invoice.',
        evidence: summary,
      });
    }
    if (paid === null) {
      issues.add('INVOICE_PAYMENT_AMOUNT_MISSING', { ...base, field: 'PaymentAmount', description: 'PaymentAmount kosong.', evidence: summary });
    }
    if (isNumber(amount) && isNumber(outstanding)) {
      const expected = amount - (paid ?? 0);
      if (!approxEqual(expected, outstanding, 1)) {
        issues.add('INVOICE_OUTSTANDING_INCONSISTENT', {
          ...base,
          field: 'InvoiceOutstanding',
          value: outstanding,
          candidate_reference: expected,
          candidate_method: 'InvoiceAmount - PaymentAmount',
          description: `InvoiceAmount ${amount} - PaymentAmount ${paid ?? 0} = ${expected}, sedangkan InvoiceOutstanding ${outstanding}.`,
        });
      }
    }
    if (invoice.PaymentDate && invoice.InvoiceDate && invoice.PaymentDate < invoice.InvoiceDate) {
      issues.add('INVOICE_PAYMENT_BEFORE_INVOICE', {
        ...base,
        field: 'PaymentDate',
        value: invoice.PaymentDate,
        description: `Tanggal bayar ${invoice.PaymentDate} sebelum tanggal invoice ${invoice.InvoiceDate}; salah satu tanggal mungkin tertukar hari/bulan, atau pembayaran uang muka.`,
        evidence: summary,
      });
    }
    const receipt = cleanText(invoice.PaymentReceiptNumber);
    if (receipt && stripFloatSuffix(receipt) !== receipt) floatReceipts += 1;
  }
  if (floatReceipts) {
    issues.add('RECEIPT_NUMBER_FLOAT_FORMAT', {
      workbook_sheet: 'INVOICES_PAYMENTS',
      field: 'PaymentReceiptNumber',
      value: floatReceipts,
      description: `${floatReceipts} nomor bukti bayar tersimpan dengan akhiran ".0" (angka pernah diperlakukan sebagai float). Dinormalisasi dengan membuang ".0"; digit tidak berubah.`,
    });
  }
  // Would the payment-before-invoice cases disappear if the dates were read day-first? Evidence only.
  const paymentBeforeInvoice = invoices.filter(
    (invoice) => invoice.PaymentDate && invoice.InvoiceDate && invoice.PaymentDate < invoice.InvoiceDate,
  );
  const resolvedBy = { invoiceDateSwapCandidate: 0, alsoSwappingPaymentDate: 0, unresolved: 0 };
  for (const invoice of paymentBeforeInvoice) {
    const invoiceDate = ctx.dateSuggestions?.get(`INVOICES_PAYMENTS|${invoice._row}|InvoiceDate`) ?? invoice.InvoiceDate;
    const paymentDate = swapDayMonth(invoice.PaymentDate) ?? invoice.PaymentDate;
    if (invoice.PaymentDate >= invoiceDate) resolvedBy.invoiceDateSwapCandidate += 1;
    else if (paymentDate >= invoiceDate) resolvedBy.alsoSwappingPaymentDate += 1;
    else resolvedBy.unresolved += 1;
  }

  const receipts = groupBy(invoices, (invoice) => stripFloatSuffix(invoice.PaymentReceiptNumber));
  ctx.stats.invoices = {
    total: invoices.length,
    invoiceNumbersUnique: new Set(invoices.map((invoice) => documentKey(invoice.InvoiceNumber))).size === invoices.length,
    documentTypes: countBy(invoices, (invoice) => (documentKey(invoice.InvoiceNumber) || '').split('/')[3] || null),
    invoiceAmountTotal: sumBy(invoices, (invoice) => invoice.InvoiceAmount),
    paymentAmountTotal: sumBy(invoices, (invoice) => invoice.PaymentAmount),
    withPaymentDate: invoices.filter((invoice) => invoice.PaymentDate).length,
    withPaymentAmount: invoices.filter((invoice) => isNumber(invoice.PaymentAmount)).length,
    paymentEqualsInvoice: invoices.filter((invoice) => approxEqual(invoice.PaymentAmount ?? NaN, invoice.InvoiceAmount ?? NaN, 1)).length,
    withOutstanding: invoices.filter((invoice) => (invoice.InvoiceOutstanding ?? 0) > 0).length,
    outstandingTotal: sumBy(invoices, (invoice) => invoice.InvoiceOutstanding),
    cumulativeOutstandingValues: countBy(invoices, (invoice) => invoice.CumulativeOutstanding),
    withReceipt: invoices.filter((invoice) => cleanText(invoice.PaymentReceiptNumber)).length,
    receiptsCoveringSeveralInvoices: [...receipts.values()].filter((group) => group.length > 1).length,
    maxInvoicesPerReceipt: Math.max(0, ...[...receipts.values()].map((group) => group.length)),
    withInvoiceAttachment: invoices.filter((invoice) => cleanText(invoice.InvoiceAttachment)).length,
    distinctPos: new Set(invoices.map((invoice) => invoice.POID).filter(Boolean)).size,
    distinctPoNumbers: new Set(invoices.map((invoice) => documentKey(invoice.PONumberLegacy)).filter(Boolean)).size,
    poReference: references,
    paymentBeforeInvoice: paymentBeforeInvoice.length,
    paymentBeforeInvoiceResolvedBy: resolvedBy,
  };
}

function checkPoFinancials(ctx) {
  const { tables, issues, index } = ctx;
  const rows = tables.PO_FINANCIALS.records;
  const invoices = tables.INVOICES_PAYMENTS.records;
  const invoicesFor = (row) =>
    invoices.filter(
      (invoice) =>
        (cleanText(row.POID) && invoice.POID === row.POID) ||
        documentKey(invoice.PONumberLegacy) === documentKey(row.PONumberLegacy),
    );
  const references = {};
  const priceCheck = {};
  const ppnRates = {};
  const outstandingCheck = { equalsTotalMinusPayments: 0, differs: 0 };
  const undeliveredCheck = { equalsOrderMinusDelivered: 0, differs: 0 };
  const deliveredVsDeliveries = { same: 0, differs: 0 };

  for (const row of rows) {
    const base = ctx.lineage('PO_FINANCIALS', row);
    const reference = checkPoReference(ctx, 'PO_FINANCIALS', row, 'POF_PO_UNRESOLVED');
    references[reference] = (references[reference] || 0) + 1;
    const { OrderQuantity: quantity, UnitPrice: price, TotalOrderAmount: total, PPN: ppn, TotalInclPPN: totalIncl } = row;
    const where = `PO ${row.PONumberLegacy} / ${row.ProductLegacy ?? '-'}`;

    if ([quantity, price, total].every(isNumber)) {
      const ratio = total / (quantity * price);
      if (Math.abs(ratio - 1) <= 0.01) {
        priceCheck['cocok (Qty x UnitPrice = Total)'] = (priceCheck['cocok (Qty x UnitPrice = Total)'] || 0) + 1;
      } else if (Math.abs(ratio - 1000) <= 10) {
        priceCheck['UnitPrice dalam ribuan'] = (priceCheck['UnitPrice dalam ribuan'] || 0) + 1;
        issues.add('POF_UNIT_PRICE_SCALE', {
          ...base,
          field: 'UnitPrice',
          value: price,
          candidate_reference: round(total / quantity, 4),
          candidate_method: 'TotalOrderAmount / OrderQuantity',
          description: `TotalOrderAmount ${total} / OrderQuantity ${quantity} = ${round(total / quantity, 4)}, yaitu UnitPrice x 1000; UnitPrice tertulis dalam ribuan rupiah.`,
          evidence: where,
        });
      } else {
        priceCheck['tidak cocok'] = (priceCheck['tidak cocok'] || 0) + 1;
        issues.add('POF_TOTAL_INCONSISTENT', {
          ...base,
          field: 'TotalOrderAmount',
          value: total,
          candidate_reference: round(quantity * price, 2),
          candidate_method: 'OrderQuantity x UnitPrice',
          description: `OrderQuantity ${quantity} x UnitPrice ${price} = ${round(quantity * price, 2)}, sedangkan TotalOrderAmount ${total}.`,
          evidence: where,
        });
      }
    } else if (isNumber(price)) {
      priceCheck['tidak bisa diverifikasi'] = (priceCheck['tidak bisa diverifikasi'] || 0) + 1;
      issues.add('POF_UNIT_PRICE_UNVERIFIABLE', {
        ...base,
        field: 'UnitPrice',
        value: price,
        description:
          `UnitPrice ${price} tidak bisa diverifikasi karena OrderQuantity/TotalOrderAmount kosong` +
          `${price < 100 ? '; nilainya < 100 sehingga kemungkinan dalam ribuan rupiah' : ''}.`,
        evidence: where,
      });
    }

    if (isNumber(total) && isNumber(ppn) && total > 0) {
      const rate = round(ppn / total, 4);
      ppnRates[rate] = (ppnRates[rate] || 0) + 1;
      if (!approxEqual(rate, 0.11, 0.0005) && !approxEqual(rate, 0.12, 0.0005)) {
        issues.add('POF_PPN_INCONSISTENT', {
          ...base,
          field: 'PPN',
          value: ppn,
          description: `PPN ${ppn} = ${round(rate * 100, 2)}% dari TotalOrderAmount ${total} (bukan 11% atau 12%).`,
          evidence: where,
        });
      }
    }
    if (!isNumber(totalIncl) || totalIncl === 0) {
      issues.add('POF_TOTAL_INCL_MISSING', {
        ...base,
        field: 'TotalInclPPN',
        value: totalIncl ?? '',
        candidate_reference: isNumber(total) && isNumber(ppn) ? total + ppn : '',
        candidate_method: isNumber(total) && isNumber(ppn) ? 'TotalOrderAmount + PPN' : '',
        description: `TotalInclPPN ${totalIncl === 0 ? 'bernilai 0' : 'kosong'}.`,
        evidence: where,
      });
    } else if (isNumber(total) && isNumber(ppn) && !approxEqual(total + ppn, totalIncl, 1)) {
      issues.add('POF_TOTAL_INCL_INCONSISTENT', {
        ...base,
        field: 'TotalInclPPN',
        value: totalIncl,
        candidate_reference: total + ppn,
        candidate_method: 'TotalOrderAmount + PPN',
        description: `TotalOrderAmount ${total} + PPN ${ppn} = ${total + ppn}, sedangkan TotalInclPPN ${totalIncl}.`,
        evidence: where,
      });
    }

    if (isNumber(row.UndeliveredQuantity)) {
      if (row.UndeliveredQuantity < 0) {
        issues.add('POF_UNDELIVERED_NEGATIVE', {
          ...base,
          field: 'UndeliveredQuantity',
          value: row.UndeliveredQuantity,
          description: `UndeliveredQuantity ${row.UndeliveredQuantity}: pengiriman melebihi order.`,
          evidence: where,
        });
      }
      if (isNumber(quantity) && isNumber(row.DeliveredQuantity)) {
        if (approxEqual(quantity - row.DeliveredQuantity, row.UndeliveredQuantity)) undeliveredCheck.equalsOrderMinusDelivered += 1;
        else undeliveredCheck.differs += 1;
      }
    }

    const matchedInvoices = invoicesFor(row);
    const paid = sumBy(matchedInvoices, (invoice) => invoice.PaymentAmount);
    if (isNumber(row.Outstanding)) {
      if (approxEqual((totalIncl ?? 0) - paid, row.Outstanding, 1)) outstandingCheck.equalsTotalMinusPayments += 1;
      else outstandingCheck.differs += 1;
      if (row.Outstanding < -1) {
        issues.add('POF_OUTSTANDING_SUSPECT', {
          ...base,
          severity: Math.abs(row.Outstanding) >= 10000 ? 'MEDIUM' : 'LOW',
          field: 'Outstanding',
          value: row.Outstanding,
          description: `Outstanding ${row.Outstanding} negatif (pembayaran melebihi TotalInclPPN).`,
          evidence: `${where}; TotalInclPPN ${totalIncl ?? '-'}; ${matchedInvoices.length} invoice dengan total pembayaran ${paid}`,
        });
      }
    }
    const status = cleanText(row.Status)?.toUpperCase();
    if (status === 'BELUM LUNAS' && isNumber(row.Outstanding) && Math.abs(row.Outstanding) < 1) {
      issues.add('POF_STATUS_ROUNDING', {
        ...base,
        field: 'Status',
        value: row.Status,
        description: `Status BELUM LUNAS dengan Outstanding ${row.Outstanding} (< Rp 1): hanya selisih pembulatan.`,
        evidence: where,
      });
    }
    if (status === 'LUNAS' && isNumber(row.Outstanding) && row.Outstanding >= 1) {
      issues.add('POF_STATUS_INCONSISTENT', {
        ...base,
        field: 'Status',
        value: row.Status,
        description: `Status LUNAS tetapi Outstanding ${row.Outstanding}.`,
        evidence: where,
      });
    }

    if (cleanText(row.POID) && isNumber(row.DeliveredQuantity)) {
      const delivered = sumBy(index.deliveriesByPo.get(row.POID) || [], (delivery) => delivery.DeliveredQuantity);
      if (approxEqual(delivered, row.DeliveredQuantity)) deliveredVsDeliveries.same += 1;
      else deliveredVsDeliveries.differs += 1;
    }
  }

  ctx.stats.poFinancials = {
    total: rows.length,
    byBrand: countBy(rows, (row) => cleanText(row.Brand)),
    byStatus: countBy(rows, (row) => cleanText(row.Status)),
    withOrderQuantity: rows.filter((row) => isNumber(row.OrderQuantity)).length,
    withUnitPrice: rows.filter((row) => isNumber(row.UnitPrice)).length,
    withTotal: rows.filter((row) => isNumber(row.TotalOrderAmount)).length,
    withTotalInclPpn: rows.filter((row) => isNumber(row.TotalInclPPN) && row.TotalInclPPN !== 0).length,
    priceCheck,
    ppnRates,
    outstandingCheck,
    undeliveredCheck,
    deliveredVsDeliveriesOfPo: deliveredVsDeliveries,
    poReference: references,
    productNamesWithCaseVariants: [...groupBy(rows, (row) => cleanText(row.ProductLegacy)?.toLowerCase()).values()].filter(
      (group) => new Set(group.map((row) => cleanText(row.ProductLegacy))).size > 1,
    ).length,
  };
}

module.exports = function checkFinance(ctx) {
  checkInvoices(ctx);
  checkPoFinancials(ctx);
};
