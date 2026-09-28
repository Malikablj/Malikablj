'use strict';

/** Customers and products: duplicates, naming, codes, defaults and usage. */

const { cleanText, daysBetween, leadingCode, nameKey, normalizeCode, textKey } = require('../normalize');
const { countBy, groupBy, productCode } = require('../matching');

function customerKind(name) {
  const text = cleanText(name) ?? '';
  if (splitCompositeName(text).length > 1 && !/^(pt|cv|ud)\b/i.test(text)) return 'gabungan';
  if (/^(bapak|bpk|ibu|pak|bu)\b/i.test(text)) return 'perorangan';
  if (/^toko\b/i.test(text)) return 'toko';
  if (/^(pt|cv|ud)\b/i.test(text)) return 'badan usaha';
  return 'lainnya';
}

function splitCompositeName(name) {
  return String(name ?? '')
    .split(/\s*\/\s*|\s+-\s+/)
    .map(cleanText)
    .filter(Boolean);
}

function checkCustomers(ctx) {
  const { tables, issues, index, asOf } = ctx;
  const customers = tables.CUSTOMERS.records;
  const posByCustomer = groupBy(tables.PURCHASE_ORDERS.records, (po) => po.CustomerID);

  // Union-find over customers linked by an equal name key or a composite-name part.
  const parent = new Map(customers.map((customer) => [customer.CustomerID, customer.CustomerID]));
  const root = (id) => (parent.get(id) === id ? id : root(parent.get(id)));
  const reasons = new Map(customers.map((customer) => [customer.CustomerID, new Set()]));
  const link = (a, b, reason) => {
    parent.set(root(a.CustomerID), root(b.CustomerID));
    reasons.get(a.CustomerID).add(reason);
    reasons.get(b.CustomerID).add(reason);
  };
  for (const group of index.customersByNameKey.values()) {
    for (const customer of group.slice(1)) link(customer, group[0], 'nama sama setelah PT/CV, tanda baca & kapitalisasi diabaikan');
  }
  for (const customer of customers) {
    const parts = splitCompositeName(customer.CustomerName);
    if (parts.length < 2) continue;
    // Composite: two parts joined by "/" or " - ", e.g. "CV A/CV B" or "Brand - PT Company".
    issues.add('CUSTOMER_COMPOSITE_NAME', {
      ...ctx.lineage('CUSTOMERS', customer),
      field: 'CustomerName',
      value: customer.CustomerName,
      description: `Nama "${customer.CustomerName}" terdiri dari ${parts.length} bagian (${parts.join(' | ')}); perlu dipastikan apakah satu entitas, brand + perusahaan, atau dua perusahaan.`,
    });
    for (const part of parts) {
      for (const other of index.customersByNameKey.get(nameKey(part)) || []) {
        if (other !== customer) link(customer, other, `bagian nama "${part}" sama dengan customer lain`);
      }
    }
  }
  const groups = groupBy(customers, (customer) => root(customer.CustomerID));
  const duplicateGroups = [...groups.values()].filter((group) => group.length > 1);
  for (const group of duplicateGroups) {
    for (const customer of group) {
      const others = group.filter((other) => other !== customer);
      issues.add('CUSTOMER_DUPLICATE_CANDIDATE', {
        ...ctx.lineage('CUSTOMERS', customer),
        field: 'CustomerName',
        value: customer.CustomerName,
        candidate_reference: others.map((other) => `${other.CustomerID} (${other.CustomerName})`).join('; '),
        candidate_method: [...reasons.get(customer.CustomerID)].join('; '),
        description: `Kemungkinan entitas yang sama dengan ${others.map((other) => `"${other.CustomerName}"`).join(', ')}. Tidak di-merge otomatis.`,
        evidence: group.map((member) => `${member.CustomerName}: ${(posByCustomer.get(member.CustomerID) || []).length} PO`).join(' | '),
      });
    }
  }

  const profiles = customers.map((customer) => {
    const pos = posByCustomer.get(customer.CustomerID) || [];
    const dates = pos.map((po) => po.PODate).filter(Boolean).sort();
    return {
      id: customer.CustomerID,
      name: customer.CustomerName,
      kind: customerKind(customer.CustomerName),
      purchaseOrders: pos.length,
      firstPoDate: dates[0] ?? null,
      lastPoDate: dates[dates.length - 1] ?? null,
      sourceFiles: [...new Set(pos.map((po) => ctx.sources.resolve(po.SourceFile)))].filter(Boolean),
    };
  });
  const staleCutoff = 365;
  const stale = profiles.filter((profile) => profile.lastPoDate && daysBetween(profile.lastPoDate, asOf) > staleCutoff);
  const undated = profiles.filter((profile) => !profile.lastPoDate);
  const statuses = new Set(customers.map((customer) => cleanText(customer.CustomerStatus)));
  if (statuses.size === 1) {
    issues.add('CUSTOMER_STATUS_DEFAULTED', {
      workbook_sheet: 'CUSTOMERS',
      field: 'CustomerStatus',
      value: [...statuses][0],
      description:
        `Semua ${customers.length} customer berstatus "${[...statuses][0]}" dengan Source "Imported"; status ini hasil ` +
        'default konversi, bukan penilaian bisnis.',
      evidence: `${stale.length} customer PO terakhirnya > ${staleCutoff} hari sebelum ${asOf}; ${undated.length} customer tanpa PO bertanggal.`,
    });
  }

  ctx.stats.customers = {
    total: customers.length,
    byKind: countBy(profiles, (profile) => profile.kind),
    nameEqualsCompany: customers.filter((customer) => customer.CustomerName === customer.Company).length,
    duplicateGroups: duplicateGroups.map((group) => group.map((customer) => customer.CustomerName)),
    compositeNames: customers.filter((customer) => splitCompositeName(customer.CustomerName).length > 1).length,
    lastPoOlderThanOneYear: stale.length,
    withoutDatedPo: undated.length,
    profiles: profiles.sort((a, b) => b.purchaseOrders - a.purchaseOrders || a.name.localeCompare(b.name)),
  };
}

function codeFormat(raw) {
  const text = cleanText(raw);
  if (!text) return 'kosong';
  if (/^\[.*\]$/.test(text)) return '[KODE]';
  if (/^\(.*\)$/.test(text)) return '(KODE)';
  return 'KODE tanpa kurung';
}

function checkProducts(ctx) {
  const { tables, issues, index } = ctx;
  const products = tables.PRODUCTS.records;

  const sharedCodes = [...index.productsByCode.entries()].filter(([, group]) => group.length > 1);
  for (const [code, group] of sharedCodes) {
    for (const product of group) {
      const others = group.filter((other) => other !== product);
      issues.add('PRODUCT_CODE_SHARED', {
        ...ctx.lineage('PRODUCTS', product),
        field: 'ProductCode',
        value: code,
        candidate_reference: others.map((other) => other.ProductID).join('; '),
        candidate_method: 'same-product-code',
        description: `Kode ${code} juga dipakai ${others.length} record produk lain; bisa SKU yang sama dengan penamaan berbeda, atau varian cetak/kemasan yang memang beda.`,
        evidence: group.map((member) => `${member.ProductName}${member.Variant ? ` / ${member.Variant}` : ''}`).join(' | '),
      });
    }
  }

  const exactGroups = [
    ...groupBy(products, (product) =>
      [textKey(product.ProductName), textKey(product.Variant) ?? '', productCode(product) ?? ''].join('|'),
    ).values(),
  ].filter((group) => group.length > 1);
  for (const group of exactGroups) {
    for (const product of group) {
      issues.add('PRODUCT_DUPLICATE_EXACT', {
        ...ctx.lineage('PRODUCTS', product),
        field: 'ProductName',
        value: product.ProductName,
        candidate_reference: group.filter((other) => other !== product).map((other) => other.ProductID).join('; '),
        candidate_method: 'same-name-variant-code',
        description: 'Nama, varian dan kode identik (abaikan kapitalisasi/tanda baca) dengan record produk lain.',
      });
    }
  }

  for (const product of products) {
    if (!normalizeCode(product.ProductCode) && leadingCode(product.ProductName)) {
      issues.add('PRODUCT_CODE_IN_NAME', {
        ...ctx.lineage('PRODUCTS', product),
        field: 'ProductCode',
        value: product.ProductName,
        candidate_reference: leadingCode(product.ProductName),
        candidate_method: 'leading-code-in-name',
        description: 'ProductCode kosong, tetapi nama produk diawali kode.',
      });
    }
  }

  const usage = new Map(products.map((product) => [product.ProductID, {}]));
  for (const [sheet, column] of [
    ['PO_LINES', 'ProductID'],
    ['DELIVERIES', 'ProductID'],
    ['RETURNS', 'ProductID'],
    ['STOCK', 'ProductID'],
    ['LEADTIME', 'ProductID'],
  ]) {
    for (const record of tables[sheet].records) {
      const entry = usage.get(record[column]);
      if (entry) entry[sheet] = (entry[sheet] || 0) + 1;
    }
  }
  const nameGroups = [...index.productsByNameKey.values()].filter((group) => group.length > 1);

  ctx.stats.products = {
    total: products.length,
    distinctNames: index.productsByNameKey.size,
    namesWithSeveralRecords: nameGroups.length,
    recordsSharingAName: nameGroups.reduce((sum, group) => sum + group.length, 0),
    namesWithSeveralVariants: nameGroups.filter((group) => new Set(group.map((p) => textKey(p.Variant))).size > 1).length,
    withCode: products.filter((product) => productCode(product)).length,
    codeFormats: countBy(products, (product) => codeFormat(product.ProductCode)),
    distinctCodes: index.productsByCode.size,
    sharedCodes: sharedCodes.length,
    recordsWithSharedCode: sharedCodes.reduce((sum, [, group]) => sum + group.length, 0),
    sharedCodeExamples: sharedCodes
      .sort((a, b) => b[1].length - a[1].length)
      .slice(0, 6)
      .map(([code, group]) => ({ code, names: group.map((product) => product.ProductName) })),
    exactDuplicateGroups: exactGroups.length,
    withVariant: products.filter((product) => cleanText(product.Variant)).length,
    units: countBy(products, (product) => cleanText(product.Unit)),
    referencedBy: {
      poLines: [...usage.values()].filter((entry) => entry.PO_LINES).length,
      deliveries: [...usage.values()].filter((entry) => entry.DELIVERIES).length,
      stock: [...usage.values()].filter((entry) => entry.STOCK).length,
      returns: [...usage.values()].filter((entry) => entry.RETURNS).length,
      unreferenced: [...usage.values()].filter((entry) => Object.keys(entry).length === 0).length,
    },
  };
}

module.exports = function checkMasterData(ctx) {
  checkCustomers(ctx);
  checkProducts(ctx);
};
