'use strict';

/** docs/DATABASE_SCHEMA.md is generated from the schema; this fails when someone changes one without the other. */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const { test } = require('node:test');

const { OUTPUT, renderSchemaDoc } = require('../scripts/render-schema-doc');

test('docs/DATABASE_SCHEMA.md sesuai skema di kode (jalankan npm run docs:schema bila gagal)', () => {
  assert.ok(fs.existsSync(OUTPUT), 'docs/DATABASE_SCHEMA.md belum dibuat');
  assert.equal(fs.readFileSync(OUTPUT, 'utf8'), renderSchemaDoc());
});
