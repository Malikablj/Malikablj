/**
 * Migration readiness gate: validates the mapping against the actual workbook BEFORE any
 * data is touched. Errors block `npm run migrate`; a dry run skips entities with errors and
 * reports them. Every workbook sheet must be either mapped or explicitly ignored, so no sheet
 * is ever skipped silently.
 */
import { ENTITIES, TARGETS } from './entities.js';

const STRATEGIES_BY_TARGET = {
  customers: ['key', 'code', 'name'],
  contacts: ['key', 'name'],
  products: ['key', 'code', 'name'],
  leads: ['key', 'name'],
  activities: ['key'],
  purchase_orders: ['key', 'po_number'],
  po_lines: ['key'],
  users: ['user'],
};

/** Fields that may stay unmapped because the entity derives them. */
const DERIVED_REQUIRED = {
  activities: { activity_at: ['activity_date'] },
  stock: { stock_type: ['quantityColumns'], quantity: ['quantityColumns'] },
};

export function fieldSpec(mappingValue) {
  if (typeof mappingValue === 'string') return { column: mappingValue };
  return mappingValue ?? {};
}

export function validateMapping(workbook, mapping) {
  const errors = [];
  const warnings = [];
  const entityErrors = {};
  const unmappedColumns = {};
  const sheetNames = workbook.sheets.map((sheet) => sheet.name);
  const usedSheets = new Set();

  const fail = (entity, message) => {
    errors.push(entity ? `[${entity}] ${message}` : message);
    if (entity) entityErrors[entity] = (entityErrors[entity] ?? 0) + 1;
  };

  if (!['PROVISIONAL', 'VERIFIED'].includes(mapping.status)) {
    fail(null, `mapping.status harus "PROVISIONAL" atau "VERIFIED" (sekarang: ${mapping.status}).`);
  }
  for (const name of Object.keys(mapping.entities ?? {})) {
    if (!ENTITIES.some((entity) => entity.name === name)) fail(null, `Entity "${name}" di mapping tidak dikenal.`);
  }

  for (const entity of ENTITIES) {
    const config = mapping.entities?.[entity.name];
    if (!config) {
      warnings.push(`[${entity.name}] Tidak dipetakan: tidak ada data yang diimpor untuk entity ini.`);
      continue;
    }
    const sheet = workbook.sheets.find((candidate) => candidate.name === config.sheet);
    if (!sheet) {
      fail(entity.name, `Sheet "${config.sheet}" tidak ada di workbook. Sheet yang ada: ${sheetNames.join(', ') || '(kosong)'}.`);
      continue;
    }
    usedSheets.add(sheet.name);
    const headers = new Set(sheet.headers.map((header) => header.name));
    const used = new Set();
    const needColumn = (column, what) => {
      if (!column) return fail(entity.name, `${what}: kolom tidak ditentukan.`);
      if (!headers.has(column)) fail(entity.name, `${what}: kolom "${column}" tidak ada di sheet "${sheet.name}".`);
      used.add(column);
    };

    for (const [field, value] of Object.entries(config.fields ?? {})) {
      const definition = entity.fields[field];
      if (!definition) {
        fail(entity.name, `Field "${field}" tidak dikenal untuk ${entity.name}.`);
        continue;
      }
      const spec = fieldSpec(value);
      needColumn(spec.column, `Field ${field}`);
      if (definition.type === 'enum') {
        for (const [label, code] of Object.entries(spec.valueMap ?? {})) {
          if (!definition.values.includes(code)) fail(entity.name, `valueMap ${field}: "${label}" -> "${code}" bukan kode yang valid.`);
        }
        if (spec.default !== undefined && spec.default !== null && !definition.values.includes(spec.default)) {
          fail(entity.name, `Default ${field} "${spec.default}" bukan kode yang valid.`);
        }
      }
    }

    for (const [field, definition] of Object.entries(entity.fields)) {
      if (!definition.required || config.fields?.[field]) continue;
      const alternatives = DERIVED_REQUIRED[entity.name]?.[field] ?? [];
      const derived = alternatives.some((alt) => (alt === 'quantityColumns' ? config.quantityColumns : config.fields?.[alt]));
      if (!derived) fail(entity.name, `Field wajib "${field}" belum dipetakan.`);
    }
    for (const [field, alternatives] of Object.entries(DERIVED_REQUIRED[entity.name] ?? {})) {
      if (entity.fields[field]?.required) continue;
      const mapped = config.fields?.[field] || alternatives.some((alt) => (alt === 'quantityColumns' ? config.quantityColumns : config.fields?.[alt]));
      if (!mapped) fail(entity.name, `Field "${field}" belum dipetakan (atau ${alternatives.join('/')}).`);
    }

    for (const [refField, strategies] of Object.entries(config.refs ?? {})) {
      const ref = entity.refs[refField];
      if (!ref) {
        fail(entity.name, `Relasi "${refField}" tidak dikenal untuk ${entity.name}.`);
        continue;
      }
      if (!Array.isArray(strategies) || strategies.length === 0) {
        fail(entity.name, `Relasi ${refField}: daftar strategi pencocokan kosong.`);
        continue;
      }
      for (const strategy of strategies) {
        if (!STRATEGIES_BY_TARGET[ref.target]?.includes(strategy.via)) {
          fail(entity.name, `Relasi ${refField}: strategi "${strategy.via}" tidak berlaku untuk ${ref.target}.`);
        }
        needColumn(strategy.column, `Relasi ${refField} (${strategy.via})`);
        if (strategy.customerColumn) needColumn(strategy.customerColumn, `Relasi ${refField} (customer)`);
        if (strategy.via === 'key') {
          const targetKey = mapping.entities?.[ref.target]?.key?.[0];
          if (!targetKey || targetKey.length !== 1) {
            fail(entity.name, `Relasi ${refField} via key: ${ref.target} harus punya key pertama berupa satu kolom.`);
          }
        }
        if (strategy.via === 'code' && !TARGETS[ref.target].code) fail(entity.name, `Relasi ${refField}: ${ref.target} tidak punya kode.`);
      }
    }
    for (const [refField, ref] of Object.entries(entity.refs)) {
      if (ref.required && !config.refs?.[refField]) fail(entity.name, `Relasi wajib "${refField}" belum dipetakan.`);
    }

    for (const [index, columns] of (config.key ?? []).entries()) {
      for (const column of columns) needColumn(column, `Key alternatif ${index + 1}`);
    }
    if (!config.key?.length) {
      warnings.push(
        `[${entity.name}] Tidak ada key: baris dikenali dari nomor baris + isi. Re-run setelah mengedit workbook bisa membuat data lama "stale" (dilaporkan oleh migrate:verify).`,
      );
    }

    if (config.quantityColumns) {
      if (entity.expand !== 'stockTypes') fail(entity.name, 'quantityColumns hanya berlaku untuk stock.');
      for (const [type, column] of Object.entries(config.quantityColumns)) {
        if (!entity.fields.stock_type.values.includes(type)) fail(entity.name, `quantityColumns: tipe stok "${type}" tidak dikenal.`);
        needColumn(column, `Qty ${type}`);
      }
    }

    for (const problem of sheet.headerProblems) warnings.push(`[${entity.name}] ${problem}`);
    const dataMerges = sheet.merges.filter((range) => {
      const firstRow = Number(/\d+/.exec(range)?.[0] ?? 0);
      return firstRow > (sheet.headerRow ?? 1);
    });
    if (dataMerges.length) {
      warnings.push(`[${entity.name}] Ada merged cell di area data (${dataMerges.slice(0, 5).join(', ')}): hanya sel kiri-atas yang berisi nilai.`);
    }
    const orphanRows = sheet.rows.filter((row) => row.orphanCells > 0).length;
    if (orphanRows) warnings.push(`[${entity.name}] ${orphanRows} baris punya isi di kolom tanpa judul; isi tersebut tidak diimpor.`);

    unmappedColumns[entity.name] = sheet.headers.map((header) => header.name).filter((name) => !used.has(name));
  }

  for (const name of sheetNames) {
    if (usedSheets.has(name)) continue;
    if (Object.hasOwn(mapping.ignoredSheets ?? {}, name)) continue;
    fail(null, `Sheet "${name}" belum dipetakan. Petakan ke sebuah entity atau masukkan ke ignoredSheets beserta alasannya.`);
  }

  return { ok: errors.length === 0, errors, warnings, entityErrors, unmappedColumns };
}
