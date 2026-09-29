'use strict';

/**
 * Apps Script service emulator for running src/*.gs under Node: SpreadsheetApp, PropertiesService, LockService,
 * CacheService, Session, Utilities, DriveApp, HtmlService and Logger. It is a test double, not a full implementation: unknown
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
      env.files.set(id, { id, name: String(name), trashed: false, parent: null, content: null, mimeType: 'application/vnd.google-apps.spreadsheet' });
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

  // Script cache: string values with an expiry (at most 6 hours, as in Apps Script). env.cache lets tests inspect or
  // clear it; entries expire on the real clock.
  env.cache = new Map();
  const CacheService = {
    getScriptCache() {
      return {
        get(key) {
          const entry = env.cache.get(String(key));
          if (!entry) return null;
          if (entry.expiresAt <= Date.now()) {
            env.cache.delete(String(key));
            return null;
          }
          return entry.value;
        },
        put(key, value, expirationInSeconds) {
          if (typeof value !== 'string') throw new Error('Emulator: CacheService stores strings only');
          if (String(key).length > 250) throw new Error('Emulator: cache key too long');
          const seconds = expirationInSeconds === undefined ? 600 : Math.min(Number(expirationInSeconds), 21600);
          env.cache.set(String(key), { value, expiresAt: Date.now() + seconds * 1000 });
        },
        remove(key) { env.cache.delete(String(key)); },
      };
    },
  };

  const Session = {
    getActiveUser: () => ({ getEmail: () => env.activeUserEmail }),
    getEffectiveUser: () => ({ getEmail: () => env.effectiveUserEmail }),
    getScriptTimeZone: () => env.scriptTimeZone,
  };

  const DigestAlgorithm = Object.freeze({ MD5: 'MD5', SHA_1: 'SHA_1', SHA_256: 'SHA_256' });
  const Charset = Object.freeze({ UTF_8: 'UTF_8', US_ASCII: 'US_ASCII' });
  const Utilities = {
    DigestAlgorithm,
    Charset,
    getUuid: () => crypto.randomUUID(),
    formatDate: (date, timeZone, pattern) => formatDate(date, timeZone, pattern),
    sleep: () => {},
    /** Like Apps Script: returns the digest as an array of signed bytes (-128..127). */
    computeDigest(algorithm, value, charset) {
      const names = { MD5: 'md5', SHA_1: 'sha1', SHA_256: 'sha256' };
      if (!names[algorithm]) throw new Error(`Emulator: unsupported digest ${algorithm}`);
      if (charset !== undefined && charset !== Charset.UTF_8) throw new Error('Emulator: only UTF-8 is supported');
      const input = typeof value === 'string' ? Buffer.from(value, 'utf8') : Buffer.from(value.map((b) => b & 0xff));
      return [...crypto.createHash(names[algorithm]).update(input).digest()].map((b) => (b > 127 ? b - 256 : b));
    },
  };

  const fileHandle = (file) => ({
    getId: () => file.id,
    getName: () => file.name,
    getMimeType: () => file.mimeType,
    getUrl: () => `https://drive.google.com/file/d/${file.id}/view`,
    isTrashed: () => file.trashed,
    setTrashed(trashed) { file.trashed = Boolean(trashed); return this; },
    moveTo(folder) { file.parent = folder.getId(); return this; },
    getBlob() {
      if (file.content === null) throw new Error('Emulator: this file has no blob content');
      return {
        getDataAsString: (charset) => {
          if (charset !== undefined && charset !== 'UTF-8' && charset !== Charset.UTF_8) throw new Error('Emulator: only UTF-8');
          return file.content;
        },
        getBytes: () => [...Buffer.from(file.content, 'utf8')].map((b) => (b > 127 ? b - 256 : b)),
        getName: () => file.name,
      };
    },
  });
  const createFile = (parent, name, content, mimeType) => {
    const id = `file-${crypto.randomUUID()}`;
    const file = { id, name: String(name), trashed: false, parent, content: String(content), mimeType: mimeType || 'text/plain' };
    env.files.set(id, file);
    return fileHandle(file);
  };
  const DriveApp = {
    getFileById(id) {
      const file = env.files.get(id);
      if (!file) throw new Error('No item with the given ID could be found, or you do not have permission to access it.');
      return fileHandle(file);
    },
    getFolderById(id) {
      if (!env.folders.has(id)) throw new Error('No item with the given ID could be found, or you do not have permission to access it.');
      return { getId: () => id, createFile: (name, content, mimeType) => createFile(id, name, content, mimeType) };
    },
    createFile: (name, content, mimeType) => createFile(null, name, content, mimeType),
  };
  /** Test helper: puts a text file (e.g. a migration package) into the emulated Drive and returns its id. */
  env.addDriveFile = (name, content, mimeType = 'application/json') => createFile(null, name, content, mimeType).getId();

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

  env.globals = { SpreadsheetApp, PropertiesService, LockService, CacheService, Session, Utilities, DriveApp, Logger, HtmlService };
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
