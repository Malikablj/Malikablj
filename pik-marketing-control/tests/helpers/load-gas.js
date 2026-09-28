'use strict';

/**
 * Loads every src/**\/*.gs file into one vm context, the way Apps Script loads a project: each file is a separate
 * script sharing one global scope (top-level function/const/let declarations are visible across files).
 */

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const { createGasEnvironment } = require('../gas-emulator');

const PROJECT_ROOT = path.resolve(__dirname, '..', '..');
// PIK_GAS_SRC_DIR lets mutation checks run the suite against a modified copy of src/.
const SRC_DIR = process.env.PIK_GAS_SRC_DIR ? path.resolve(process.env.PIK_GAS_SRC_DIR) : path.join(PROJECT_ROOT, 'src');

function listGasFiles(dir = SRC_DIR) {
  const files = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) files.push(...listGasFiles(full));
    else if (entry.name.endsWith('.gs')) files.push(full);
  }
  return files.sort();
}

const silentConsole = { log() {}, info() {}, warn() {}, error() {}, debug() {} };

/**
 * @param {{ order?: 'sorted'|'reverse', env?: object, console?: object }} options
 * @returns {{ context: object, env: object, run: (code: string) => any, files: string[] }}
 */
function loadGasProject(options = {}) {
  const env = createGasEnvironment({ htmlRoot: SRC_DIR, ...(options.env || {}) });
  const context = vm.createContext({ console: options.console || silentConsole, ...env.globals });
  let files = listGasFiles();
  if (options.order === 'reverse') files = files.slice().reverse();
  for (const file of files) {
    vm.runInContext(fs.readFileSync(file, 'utf8'), context, { filename: path.relative(PROJECT_ROOT, file) });
  }
  env.realm = vm.runInContext('({ Date: Date })', context);
  env.context = context;
  env.evaluate = (code) => vm.runInContext(code, context);
  return { context, env, run: env.evaluate, files };
}

module.exports = { PROJECT_ROOT, SRC_DIR, listGasFiles, loadGasProject };
