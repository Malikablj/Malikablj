/** Response helpers for the standard API envelope. */

export function sendOk(res, data, meta) {
  const body = { success: true, data };
  if (meta) body.meta = meta;
  return res.status(200).json(body);
}

export function sendCreated(res, data, meta) {
  const body = { success: true, data };
  if (meta) body.meta = meta;
  return res.status(201).json(body);
}

/** Sends CSV as a download. Content is already escaped by utils/csv.js. */
export function sendCsv(res, filename, csv) {
  res.setHeader('Content-Type', 'text/csv; charset=utf-8');
  res.setHeader('Content-Disposition', `attachment; filename="${filename}"`);
  // BOM so Excel opens UTF-8 correctly (Indonesian names, currency symbols).
  return res.status(200).send(`\uFEFF${csv}`);
}
