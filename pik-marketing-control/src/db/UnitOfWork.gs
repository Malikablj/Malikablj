/**
 * Multi-table inserts as one unit (e.g. a PO with its lines, an activity with its next follow-up).
 *
 * Under one script lock, every record of every step is prepared and validated first — a later step may reference the
 * records of an earlier step (its generated IDs) — and only when everything is valid are the rows written, one
 * setValues per table plus the AUDIT_LOG entries. A validation error in any step writes nothing at all.
 *
 * steps: [{ table: 'PURCHASE_ORDERS', inputs: [ {...} ] },
 *         { table: 'PO_LINES', inputs: function (previous) { return [...] } }]   // previous = records of earlier steps
 * Returns the stored records per step.
 */
function dbInsertUnit_(steps, context) {
  const ctx = normalizeWriteContext_(context);
  return withScriptLock_(function () {
    resetDbCache_();
    const now = ctx.now || nowIso_();
    const prepared = [];
    const pending = {};
    const errors = [];
    steps.forEach(function (step, stepIndex) {
      const table = getTableDef_(step.table);
      assertTableWritable_(table, ctx, 'insert');
      const state = loadTable_(step.table);
      const inputs = typeof step.inputs === 'function'
        ? step.inputs(prepared.map(function (item) { return item.records; }))
        : step.inputs;
      if (!Array.isArray(inputs)) throw appError_(ERROR_CODE.INTERNAL, 'Input langkah ' + step.table + ' bukan daftar.');
      if (inputs.length > MAX_WRITE_BATCH) {
        throw appError_(ERROR_CODE.VALIDATION, 'Maksimal ' + MAX_WRITE_BATCH + ' record per penyimpanan.');
      }
      const pendingIds = Object.keys(pending[step.table] || {});
      const usedIds = new Set(Object.keys(state.byId).concat(state.duplicateIds).concat(pendingIds));
      const records = inputs.map(function (input, index) {
        const result = prepareInsertRecord_(table, input, ctx, usedIds, now, state.timeZone);
        result.errors.forEach(function (error) { errors.push(unitError_(step.table, stepIndex, index, error)); });
        return result.record;
      });
      const byId = pending[step.table] || (pending[step.table] = {});
      records.forEach(function (record) { if (record.id) byId[record.id] = record; });
      prepared.push({ table: table, state: state, step: stepIndex, records: records });
    });

    if (errors.length === 0) {
      const lookup = function (tableName) {
        const state = loadTable_(tableName);
        return pending[tableName] ? { byId: Object.assign({}, state.byId, pending[tableName]), records: state.records } : state;
      };
      const env = { mode: 'insert', ctx: ctx, previous: null, lookup: lookup, enums: getEnumState_ };
      const insertedSoFar = {};
      prepared.forEach(function (item) {
        item.records.forEach(function (record, index) {
          validateRecord_(item.table, record, env).forEach(function (error) {
            errors.push(unitError_(item.table.name, item.step, index, error));
          });
        });
        const earlier = insertedSoFar[item.table.name] || [];
        validateUniqueness_(item.table, item.records, item.state.records.concat(earlier), null).forEach(function (error) {
          errors.push(unitError_(item.table.name, item.step, error.index, error));
        });
        insertedSoFar[item.table.name] = earlier.concat(item.records);
      });
    }
    if (errors.length > 0) {
      const first = getTableDef_(errors[0].table);
      throw appError_(ERROR_CODE.VALIDATION, 'Data ' + first.label + ' tidak valid: ' + errors[0].message +
        (errors.length > 1 ? ' (dan ' + (errors.length - 1) + ' kesalahan lain)' : ''), { table: first.name, errors: errors });
    }

    prepared.forEach(function (item) {
      if (item.records.length === 0) return;
      appendRowsToSheet_(item.state.sheet, item.table, item.records.map(function (record) { return recordToRow_(item.table, record); }));
      writeAuditEntries_(item.records.map(function (record) {
        return { action: 'CREATE', entityType: item.table.name, entityId: record.id, changes: compactRecord_(record), note: ctx.auditNote };
      }), ctx, now);
    });
    resetDbCache_();
    return prepared.map(function (item) { return item.records.map(copyRecord_); });
  });
}

function unitError_(tableName, step, index, error) {
  return { table: tableName, step: step, index: index, field: error.field || null, code: error.code, message: error.message };
}
