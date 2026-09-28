'use strict';

/**
 * Apps Script service emulator for running src/*.gs under Node: SpreadsheetApp, PropertiesService, LockService,
 * Session, Utilities, DriveApp, HtmlService and Logger. It is a test double, not a full implementation: unknown
 * API calls fail loudly instead of pretending to work.
 *
 * Every mutating spreadsheet call is recorded in env.calls with the sheet name and whether the script lock was
 * held, so tests can check batching and locking.
 */

const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const { formatDate } = require('./format-date');
const {
  DataValidationBuilder,
  DataValidationCriteria,
  ProtectionType,
  Spreadsheet,
  importCellValue,
  isDate,
} = require('./spreadsheet');

function createGasEnvironment(options = {}) {
  const env = {
    scriptTimeZone: options.scriptTimeZone || 'Asia/Jakarta',
    defaultSheetName: options.defaultSheetName || 'Sheet1',
    activeUserEmail: options.activeUserEmail === undefined ? 'owner@example.com' : options.activeUserEmail,
    effectiveUserEmail: options.effectiveUserEmail === undefined ? 'owner@example.com' : options.effectiveUserEmail,
    properties: { ...(options.properties || {}) },
    lockAvailable: true,
    lockHeld: false,
    lockAcquisitions: 0,
    spreadsheets: new Map(),
    files: new Map(),
    folders: new Set(options.folders || []),
    logs: [],
    calls: [],
    htmlRoot: options.htmlRoot || null,
    realm: null,
    context: null,
  };

  env.record = (method, sheet, range, mutating = false) => {
    env.calls.push({
      method,
      sheet: sheet ? sheet.name : null,
      spreadsheetId: sheet ? sheet.spreadsheet.id : null,
      rows: range ? range.numRows : null,
      columns: range ? range.numColumns : null,
      mutating,
      locked: env.lockHeld,
    });
  };
  env.resetCalls = () => { env.calls = []; };
  /** Dates handed to script code must come from the script's own realm (instanceof Date inside the vm). */
  env.makeDate = (ms) => (env.realm ? new env.realm.Date(ms) : new Date(ms));
  env.importValue = (value, format, timeZone) => importCellValue(value, format, timeZone, env.makeDate);
  env.exportValue = (value) => (isDate(value) ? env.makeDate(value.getTime()) : value);

  const SpreadsheetApp = {
    ProtectionType,
    DataValidationCriteria,
    create(name) {
      const id = `ss-${crypto.randomUUID()}`;
      const spreadsheet = new Spreadsheet(env, id, String(name));
      env.spreadsheets.set(id, spreadsheet);
      env.files.set(id, { id, name: String(name), trashed: false, parent: null });
      return spreadsheet;
    },
    openById(id) {
      const spreadsheet = env.spreadsheets.get(id);
      const file = env.files.get(id);
      if (!spreadsheet || (file && file.trashed)) {
        throw new Error(`Unexpected error while getting the method or property openById on object SpreadsheetApp.`);
      }
      return spreadsheet;
    },
    flush() {},
    newDataValidation() { return new DataValidationBuilder(); },
    getActiveSpreadsheet() { return null; },
  };

  const PropertiesService = {
    getScriptProperties() {
      return {
        getProperty: (key) => (Object.prototype.hasOwnProperty.call(env.properties, key) ? env.properties[key] : null),
        setProperty(key, value) { env.properties[key] = String(value); return this; },
        setProperties(values, deleteAllOthers) {
          if (deleteAllOthers) env.properties = {};
          Object.keys(values).forEach((key) => { env.properties[key] = String(values[key]); });
          return this;
        },
        deleteProperty(key) { delete env.properties[key]; return this; },
        getProperties: () => ({ ...env.properties }),
      };
    },
  };

  const LockService = {
    getScriptLock() {
      let held = false;
      return {
        tryLock() {
          if (!env.lockAvailable || env.lockHeld) return false;
          env.lockHeld = true;
          held = true;
          env.lockAcquisitions += 1;
          return true;
        },
        waitLock(timeoutMs) {
          if (!this.tryLock(timeoutMs)) throw new Error('Lock timeout: another process was holding the lock for too long.');
        },
        hasLock: () => held,
        releaseLock() {
          if (held) {
            held = false;
            env.lockHeld = false;
          }
        },
      };
    },
  };

  const Session = {
    getActiveUser: () => ({ getEmail: () => env.activeUserEmail }),
    getEffectiveUser: () => ({ getEmail: () => env.effectiveUserEmail }),
    getScriptTimeZone: () => env.scriptTimeZone,
  };

  const Utilities = {
    getUuid: () => crypto.randomUUID(),
    formatDate: (date, timeZone, pattern) => formatDate(date, timeZone, pattern),
    sleep: () => {},
  };

  const DriveApp = {
    getFileById(id) {
      const file = env.files.get(id);
      if (!file) throw new Error('No item with the given ID could be found, or you do not have permission to access it.');
      return {
        getId: () => file.id,
        getName: () => file.name,
        isTrashed: () => file.trashed,
        setTrashed(trashed) { file.trashed = Boolean(trashed); return this; },
        moveTo(folder) { file.parent = folder.getId(); return this; },
      };
    },
    getFolderById(id) {
      if (!env.folders.has(id)) throw new Error('No item with the given ID could be found, or you do not have permission to access it.');
      return { getId: () => id };
    },
  };

  const Logger = {
    log(message) { env.logs.push(String(message)); return Logger; },
  };

  const HtmlService = {
    createHtmlOutputFromFile(name) {
      const content = readHtml(env, name);
      return createHtmlOutput(content);
    },
    createTemplateFromFile(name) {
      const source = readHtml(env, name);
      return {
        evaluate() {
          // Only printing scriptlets (<?!= expression ?>) are supported; they run in the script's global scope.
          const content = source.replace(/<\?!=([\s\S]*?)\?>/g, (match, expression) => {
            const code = expression.trim().replace(/;$/, '');
            return String(env.evaluate(code));
          });
          if (/<\?/.test(content)) throw new Error(`Emulator: unsupported scriptlet in ${name}`);
          return createHtmlOutput(content);
        },
      };
    },
  };

  env.globals = { SpreadsheetApp, PropertiesService, LockService, Session, Utilities, DriveApp, Logger, HtmlService };
  return env;
}

function readHtml(env, name) {
  if (!env.htmlRoot) throw new Error('Emulator: htmlRoot is not configured');
  const file = path.join(env.htmlRoot, `${name}.html`);
  if (!fs.existsSync(file)) throw new Error(`No HTML file named ${name} was found.`);
  return fs.readFileSync(file, 'utf8');
}

function createHtmlOutput(content) {
  const output = {
    title: '',
    metaTags: [],
    getContent: () => content,
    setTitle(title) { output.title = title; return output; },
    getTitle: () => output.title,
    addMetaTag(name, value) { output.metaTags.push({ name, value }); return output; },
  };
  return output;
}

module.exports = { createGasEnvironment };
