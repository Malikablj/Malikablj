'use strict';

/**
 * Type-checks src/**\/*.gs against the official Apps Script typings (@types/google-apps-script), so a misspelled or
 * misused Apps Script API (method name, argument types) is caught without running in Google.
 * The .gs files are checked together as scripts sharing one global scope, as Apps Script loads them.
 * Usage: npm install && npm run typecheck
 */

const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const PROJECT_ROOT = path.resolve(__dirname, '..');
const SRC_DIR = path.join(PROJECT_ROOT, 'src');

let ts;
try {
  ts = require('typescript');
  require.resolve('@types/google-apps-script/package.json');
} catch (error) {
  console.error('typescript dan @types/google-apps-script belum terpasang. Jalankan: npm install');
  process.exit(2);
}

function listGasFiles(dir) {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return listGasFiles(full);
    return entry.name.endsWith('.gs') ? [full] : [];
  });
}

const workDir = fs.mkdtempSync(path.join(os.tmpdir(), 'pik-typecheck-'));
const sources = new Map();
for (const file of listGasFiles(SRC_DIR)) {
  const target = path.join(workDir, `${path.relative(SRC_DIR, file).replace(/[\\/]/g, '__').replace(/\.gs$/, '')}.js`);
  fs.copyFileSync(file, target);
  sources.set(target, path.relative(PROJECT_ROOT, file));
}

const program = ts.createProgram([...sources.keys()], {
  allowJs: true,
  checkJs: true,
  noEmit: true,
  target: ts.ScriptTarget.ES2019,
  lib: ['lib.es2019.d.ts'],
  types: ['google-apps-script'],
  typeRoots: [path.join(PROJECT_ROOT, 'node_modules', '@types')],
  skipLibCheck: true,
});

const diagnostics = ts.getPreEmitDiagnostics(program);
for (const diagnostic of diagnostics) {
  const message = ts.flattenDiagnosticMessageText(diagnostic.messageText, '\n');
  if (diagnostic.file) {
    const { line, character } = diagnostic.file.getLineAndCharacterOfPosition(diagnostic.start);
    const file = sources.get(diagnostic.file.fileName) || diagnostic.file.fileName;
    console.error(`${file}:${line + 1}:${character + 1} TS${diagnostic.code}: ${message}`);
  } else {
    console.error(`TS${diagnostic.code}: ${message}`);
  }
}
fs.rmSync(workDir, { recursive: true, force: true });
console.log(`${sources.size} file .gs diperiksa, ${diagnostics.length} masalah tipe.`);
process.exitCode = diagnostics.length === 0 ? 0 : 1;
