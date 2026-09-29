'use strict';

/**
 * Static checks on the Apps Script sources: syntax that is safe for the Apps Script V8 runtime, files that load in
 * any order, a closed list of public (client-callable) functions, and safe HTML.
 */

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

const { SRC_DIR, listGasFiles, loadGasProject } = require('../tools/gas-emulator/load-gas');

const files = listGasFiles();
const sources = files.map((file) => ({ file: path.relative(SRC_DIR, file), code: fs.readFileSync(file, 'utf8') }));

/** Code with comments and string literals blanked out, so checks only see real syntax. */
function stripCommentsAndStrings(code) {
  let output = '';
  let i = 0;
  while (i < code.length) {
    const char = code[i];
    const next = code[i + 1];
    if (char === '/' && next === '*') {
      const end = code.indexOf('*/', i + 2);
      i = end === -1 ? code.length : end + 2;
      output += ' ';
    } else if (char === '/' && next === '/') {
      const end = code.indexOf('\n', i);
      i = end === -1 ? code.length : end;
    } else if (char === '\'' || char === '"' || char === '`') {
      let j = i + 1;
      while (j < code.length && code[j] !== char) j += code[j] === '\\' ? 2 : 1;
      output += char + char;
      i = j + 1;
    } else {
      output += char;
      i += 1;
    }
  }
  return output;
}

// Newer syntax/APIs are avoided so the code behaves the same on every Apps Script V8 runtime version.
const DISALLOWED = [
  [/\?\.[A-Za-z_$[(]/, 'optional chaining (?.)'],
  [/\?\?/, 'nullish coalescing (??)'],
  [/\.replaceAll\(/, 'String.prototype.replaceAll'],
  [/\.at\(/, 'Array/String.prototype.at'],
  [/Object\.hasOwn\(/, 'Object.hasOwn'],
  [/structuredClone\(/, 'structuredClone'],
  [/^\s*(import|export)\s/m, 'ES modules'],
  [/\brequire\(|module\.exports/, 'CommonJS'],
  [/^\s*class\s/m, 'classes (plain functions are used throughout)'],
];

test('semua file .gs dapat di-parse', () => {
  for (const { file, code } of sources) {
    assert.doesNotThrow(() => new vm.Script(code, { filename: file }), file);
  }
});

test('tidak memakai sintaks/API yang dihindari untuk Apps Script', () => {
  for (const { file, code } of sources) {
    const stripped = stripCommentsAndStrings(code);
    for (const [pattern, label] of DISALLOWED) assert.ok(!pattern.test(stripped), `${file}: ${label}`);
  }
});

test('setiap file dapat dimuat sendirian (tidak ada dependensi antar-file saat load)', () => {
  for (const { file, code } of sources) {
    const context = vm.createContext({});
    assert.doesNotThrow(() => vm.runInContext(code, context, { filename: file }), file);
  }
});

test('nama fungsi dan variabel global tidak duplikat antar-file', () => {
  const seen = new Map();
  for (const { file, code } of sources) {
    const stripped = stripCommentsAndStrings(code);
    const names = [...stripped.matchAll(/^(?:function\s+([A-Za-z0-9_$]+)|(?:const|let|var)\s+([A-Za-z0-9_$]+))/gm)]
      .map((match) => match[1] || match[2]);
    for (const name of names) {
      assert.ok(!seen.has(name), `${name} dideklarasikan di ${seen.get(name)} dan ${file}`);
      seen.set(name, file);
    }
  }
});

test('fungsi publik (dapat dipanggil google.script.run) hanya yang terdaftar', () => {
  const allowed = [
    'doGet', 'getAppHealth', 'api',
    'setInitialProperties', 'setupDatabase', 'initializeDatabase', 'verifyDatabase', 'runDatabaseSelfTest', 'setupAdminAccount',
    'profileSourceWorkbook', 'validateMigrationMapping', 'dryRunMigration', 'runMigration', 'verifyMigration',
  ].sort();
  const publicFunctions = [];
  for (const { code } of sources) {
    for (const match of stripCommentsAndStrings(code).matchAll(/^function\s+([A-Za-z0-9_$]+)/gm)) {
      if (!match[1].endsWith('_')) publicFunctions.push(match[1]);
    }
  }
  assert.deepEqual(publicFunctions.sort(), allowed);
});

test('file dapat dimuat dalam urutan terbalik dan tetap berfungsi', () => {
  const { context } = loadGasProject({ order: 'reverse' });
  const runner = context.createTestRunner_();
  try {
    for (const testCase of context.getDatabaseTestCases_().filter((c) => /^(schema|init: membuat|data: teks)/.test(c.name))) {
      const result = context.runTestCase_(runner, testCase);
      assert.equal(result.status, 'PASSED', `${testCase.name}: ${result.error}`);
    }
  } finally {
    runner.cleanup();
  }
});

test('appsscript.json memakai V8, zona Asia/Jakarta, dan web app terbatas', () => {
  const manifest = JSON.parse(fs.readFileSync(path.join(SRC_DIR, 'appsscript.json'), 'utf8'));
  assert.equal(manifest.runtimeVersion, 'V8');
  assert.equal(manifest.timeZone, 'Asia/Jakarta');
  assert.equal(manifest.webapp.access, 'DOMAIN');
  assert.equal(manifest.webapp.executeAs, 'USER_DEPLOYING');
});

test('HTML tidak menyisipkan teks server dengan innerHTML dan include yang dipakai tersedia', () => {
  const webDir = path.join(SRC_DIR, 'web');
  for (const name of fs.readdirSync(webDir)) {
    const html = fs.readFileSync(path.join(webDir, name), 'utf8');
    assert.ok(!/\.innerHTML\s*=|insertAdjacentHTML|document\.write/.test(html), `${name}: penyisipan HTML mentah`);
    for (const match of html.matchAll(/include_\('([^']+)'\)/g)) {
      assert.ok(fs.existsSync(path.join(SRC_DIR, `${match[1]}.html`)), `${name}: ${match[1]}.html tidak ada`);
    }
  }
});
