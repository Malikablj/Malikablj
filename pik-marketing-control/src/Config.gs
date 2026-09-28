/**
 * Central configuration, read from Script Properties (never hard-coded, never committed).
 *
 *   DATABASE_SPREADSHEET_ID  database spreadsheet; setupDatabase() creates one and fills this when it is empty
 *   DRIVE_ROOT_FOLDER_ID     optional Drive folder that receives the database file (later also attachments)
 *   APP_NAME                 display name
 *   TIMEZONE                 IANA time zone for business dates (default Asia/Jakarta)
 *   ADMIN_EMAILS             optional comma-separated emails that may run the maintenance functions
 */

const DEFAULT_APP_NAME = 'PIK Marketing Control';
const DEFAULT_TIME_ZONE = 'Asia/Jakarta';

var CONFIG_CACHE_ = null;

function getConfig_() {
  if (CONFIG_CACHE_) return CONFIG_CACHE_;
  const properties = PropertiesService.getScriptProperties();
  CONFIG_CACHE_ = Object.freeze({
    APP_NAME: properties.getProperty('APP_NAME') || DEFAULT_APP_NAME,
    DATABASE_SPREADSHEET_ID: (properties.getProperty('DATABASE_SPREADSHEET_ID') || '').trim(),
    DRIVE_ROOT_FOLDER_ID: (properties.getProperty('DRIVE_ROOT_FOLDER_ID') || '').trim(),
    TIMEZONE: (properties.getProperty('TIMEZONE') || '').trim() || DEFAULT_TIME_ZONE,
    ADMIN_EMAILS: parseEmailList_(properties.getProperty('ADMIN_EMAILS'))
  });
  return CONFIG_CACHE_;
}

/** Forgets the cached configuration (after Script Properties change within one execution). */
function resetConfigCache_() {
  CONFIG_CACHE_ = null;
}

function getAppTimeZone_() {
  return getConfig_().TIMEZONE;
}

function parseEmailList_(text) {
  return String(text || '')
    .split(/[\s,;]+/)
    .map(function (email) { return email.trim().toLowerCase(); })
    .filter(function (email) { return email !== ''; });
}

/** Fills APP_NAME and TIMEZONE when they are missing. Existing values are never overwritten. */
function setInitialProperties() {
  requireMaintenanceAccess_();
  const properties = PropertiesService.getScriptProperties();
  /** @type {Object<string, string>} */
  const missing = {};
  if (!properties.getProperty('APP_NAME')) missing.APP_NAME = DEFAULT_APP_NAME;
  if (!properties.getProperty('TIMEZONE')) missing.TIMEZONE = DEFAULT_TIME_ZONE;
  if (Object.keys(missing).length > 0) properties.setProperties(missing, false);
  resetConfigCache_();
  return { added: Object.keys(missing) };
}
