'use strict';

/**
 * Lookup indexes and candidate finders. A "candidate" is evidence for a human to
 * confirm; profiling never applies it. Every result states the method that produced it.
 */

const {
  cleanText,
  documentKey,
  leadingCode,
  nameKey,
  normalizeCode,
  textKey,
  withoutLeadingCode,
} = require('./normalize');

function groupBy(items, keyOf) {
  const groups = new Map();
  for (const item of items) {
    const key = keyOf(item);
    if (key === null || key === undefined || key === '') continue;
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key).push(item);
  }
  return groups;
}

function countBy(items, keyOf) {
  const counts = {};
  for (const item of items) {
    const key = keyOf(item) ?? '(kosong)';
    counts[key] = (counts[key] || 0) + 1;
  }
  return counts;
}

function sumBy(items, valueOf) {
  return items.reduce((total, item) => total + (valueOf(item) || 0), 0);
}

function median(values) {
  if (!values.length) return null;
  const sorted = [...values].sort((a, b) => a - b);
  const middle = Math.floor(sorted.length / 2);
  return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
}

/** Product code from ProductCode, falling back to a code written at the start of the name. */
function productCode(product) {
  if (!product) return null;
  return normalizeCode(product.ProductCode) || leadingCode(product.ProductName);
}

function buildIndexes(tables) {
  const customers = tables.CUSTOMERS.records;
  const products = tables.PRODUCTS.records;
  const purchaseOrders = tables.PURCHASE_ORDERS.records;
  const lines = tables.PO_LINES.records;
  const deliveries = tables.DELIVERIES.records;
  return {
    customerById: new Map(customers.map((customer) => [customer.CustomerID, customer])),
    customersByNameKey: groupBy(customers, (customer) => nameKey(customer.CustomerName)),
    productById: new Map(products.map((product) => [product.ProductID, product])),
    productsByCode: groupBy(products, productCode),
    productsByNameKey: groupBy(products, (product) => textKey(product.ProductName)),
    productsByVariantKey: groupBy(products, (product) => textKey(product.Variant)),
    poById: new Map(purchaseOrders.map((po) => [po.POID, po])),
    posByNumberKey: groupBy(purchaseOrders, (po) => documentKey(po.PONumber)),
    lineById: new Map(lines.map((line) => [line.POLineID, line])),
    linesByPo: groupBy(lines, (line) => line.POID),
    deliveriesByLine: groupBy(deliveries, (delivery) => delivery.POLineID),
    deliveriesByPo: groupBy(deliveries, (delivery) => delivery.POID),
  };
}

/** POs whose number matches a legacy PO number: exact text first, then ignoring spaces/case. */
function findPoCandidates(index, legacyNumber) {
  const text = cleanText(legacyNumber);
  if (!text) return { method: null, pos: [] };
  const pos = index.posByNumberKey.get(documentKey(text)) || [];
  const exact = pos.filter((po) => cleanText(po.PONumber) === text);
  if (exact.length) return { method: 'po-number-exact', pos: exact };
  if (pos.length) return { method: 'po-number-normalised', pos };
  return { method: null, pos: [] };
}

function looksLikeCode(label) {
  const text = cleanText(label);
  return Boolean(text) && /^[[(]?\s*[A-Z0-9._-]{5,}\s*[\])]?$/.test(text) && /\d/.test(text);
}

/** Products matching a legacy product label: by code, then exact name, then exact variant text. */
function findProductCandidates(index, label) {
  const text = cleanText(label);
  if (!text) return { method: null, products: [] };
  const code = leadingCode(text) || (looksLikeCode(text) ? normalizeCode(text) : null);
  if (code && index.productsByCode.has(code)) return { method: 'product-code', products: index.productsByCode.get(code) };
  const key = textKey(withoutLeadingCode(text));
  if (key && index.productsByNameKey.has(key)) return { method: 'product-name', products: index.productsByNameKey.get(key) };
  if (key && index.productsByVariantKey.has(key)) {
    return { method: 'product-variant', products: index.productsByVariantKey.get(key) };
  }
  return { method: null, products: [] };
}

/** PO lines (of one PO) whose product plausibly is the given product. */
function linesForProduct(index, lines, product) {
  const code = productCode(product);
  if (code) {
    const byCode = lines.filter((line) => productCode(index.productById.get(line.ProductID)) === code);
    if (byCode.length) return { method: 'same-product-code', lines: byCode };
  }
  const key = textKey(product.ProductName);
  const byName = lines.filter((line) => textKey(index.productById.get(line.ProductID)?.ProductName) === key);
  if (byName.length) return { method: 'same-product-name', lines: byName };
  return { method: null, lines: [] };
}

/** PO lines (of one PO) matching a legacy product label by code or exact text. */
function linesForLabel(index, lines, label) {
  const code = leadingCode(label);
  const key = textKey(withoutLeadingCode(label));
  const matched = lines.filter((line) => {
    const product = index.productById.get(line.ProductID);
    if (code && productCode(product) === code) return true;
    return [product?.ProductName, product?.Variant, line.ProductNameLegacy, line.VariantLegacy].some(
      (value) => key && textKey(value) === key,
    );
  });
  return { method: matched.length ? (code ? 'label-code-or-text' : 'label-text') : null, lines: matched };
}

module.exports = {
  buildIndexes,
  countBy,
  findPoCandidates,
  findProductCandidates,
  groupBy,
  linesForLabel,
  linesForProduct,
  median,
  productCode,
  sumBy,
};
