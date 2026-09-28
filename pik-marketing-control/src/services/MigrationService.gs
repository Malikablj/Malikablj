/**
 * Migration entry points (Phase 03), run from the Apps Script editor by the script owner or an ADMIN_EMAILS account.
 *
 * The migration package is built locally from the source workbook (npm run migrate -- package <workbook.xlsx>): the
 * workbook is read-only there and Apps Script never opens it. Upload migration-package.json to Drive and put its file
 * ID in the Script Property MIGRATION_PACKAGE_FILE_ID. Then, in this order:
 *   profileSourceWorkbook()     PROFILE summary carried by the package (source file, SHA-256, rows per sheet, issues)
 *   validateMigrationMapping()  MAP + VALIDATE: package integrity, mapping version, accounting, transformations
 *   dryRunMigration()           DRY RUN: plan and full validation against the database; writes nothing to the database
 *   runMigration()              MIGRATE + VERIFY: only after a successful dry run of the same package; run again to
 *                               continue when it stops at the time limit
 *   verifyMigration()           VERIFY (read-only), any time
 * Every run saves its JSON report in Drive (DRIVE_ROOT_FOLDER_ID, or My Drive) and logs a summary.
 */

const MIGRATION_PACKAGE_PROPERTY = 'MIGRATION_PACKAGE_FILE_ID';
const MIGRATION_DRY_RUN_PROPERTY = 'MIGRATION_DRY_RUN';

function profileSourceWorkbook() {
  requireMaintenanceAccess_();
  const pkg = readMigrationPackageFile_();
  const summary = describeMigrationPackage_(pkg);
  const report = {
    kind: 'PROFILE', checkedAt: nowIso_(), note: 'Profiling dijalankan lokal saat paket dibuat; workbook sumber tidak dibuka di sini.',
    source: summary.source, sourceSheets: summary.sourceSheets, sourceRows: summary.sourceRows,
    issuesBySeverity: summary.issuesBySeverity, issuesByType: summary.issuesByType
  };
  logMigrationReport_(report, [
    'Sumber: ' + JSON.stringify(summary.source),
    'Baris per sheet: ' + JSON.stringify(summary.sourceSheets),
    'Isu per tingkat: ' + JSON.stringify(summary.issuesBySeverity)
  ]);
  return report;
}

function validateMigrationMapping() {
  requireMaintenanceAccess_();
  const pkg = readMigrationPackageFile_();
  const integrity = checkMigrationPackage_(pkg);
  const report = {
    kind: 'VALIDATE', checkedAt: nowIso_(), ok: integrity.ok, integrity: integrity,
    package: integrity.ok ? describeMigrationPackage_(pkg) : null
  };
  logMigrationReport_(report, [
    'Paket ' + (integrity.ok ? 'VALID' : 'TIDAK VALID') + ' (' + integrity.errorCount + ' error)',
    report.package ? 'Record per tabel: ' + JSON.stringify(report.package.records) : '',
    report.package ? 'Disposisi baris sumber: ' + JSON.stringify(report.package.dispositions) : ''
  ].concat(integrity.errors.slice(0, 20).map(function (error) { return 'ERROR ' + error.code + ': ' + error.message; }))
    .concat(openDecisionLines_(report.package)));
  return report;
}

function dryRunMigration() {
  requireMaintenanceAccess_();
  const pkg = readMigrationPackageFile_();
  const report = dryRunMigrationPackage_(pkg);
  PropertiesService.getScriptProperties().setProperty(MIGRATION_DRY_RUN_PROPERTY, JSON.stringify({
    packageHash: report.packageHash, ok: report.ok, errorCount: report.errorCount, at: report.startedAt
  }));
  logMigrationReport_(report, [
    'DRY RUN ' + (report.ok ? 'OK — runMigration() boleh dijalankan' : 'GAGAL — migrasi nyata diblokir'),
    'Integritas paket: ' + (report.integrity.ok ? 'OK' : report.integrity.errorCount + ' error'),
    report.database ? 'Database: ' + (report.database.ok ? 'OK' : report.database.errors + ' error') : '',
    'Rencana: ' + JSON.stringify(report.plan),
    'Error validasi: ' + report.errorCount + ' ' + JSON.stringify(report.errorsByCode)
  ].concat(report.integrity.errors.slice(0, 20).map(function (error) { return 'ERROR ' + error.code + ': ' + error.message; }))
    .concat(report.errors.slice(0, 20).map(function (error) { return 'ERROR ' + JSON.stringify(error); }))
    .concat(report.warnings.map(function (warning) { return 'WARNING ' + warning.message; }))
    .concat(openDecisionLines_(report.package)));
  return report;
}

function runMigration() {
  const operator = requireMaintenanceAccess_();
  const pkg = readMigrationPackageFile_();
  let result;
  try {
    result = runMigrationPackage_(pkg, { dryRun: readDryRunMarker_(), operator: operator });
  } catch (error) {
    // The editor shows only the message; the details (e.g. the failed re-check) go to the log.
    if (isAppError_(error)) Logger.log('MIGRASI GAGAL: ' + error.message + ' ' + JSON.stringify(error.details).slice(0, 3000));
    throw error;
  }
  const lines = [
    result.completed ? 'MIGRASI SELESAI' : 'MIGRASI BERHENTI di ' + result.stoppedAt + ' (batas waktu) — jalankan runMigration() lagi untuk melanjutkan',
    'Per tabel: ' + JSON.stringify(result.tables)
  ];
  if (result.verification) lines.push(verificationHeadline_(result.verification));
  logMigrationReport_(result, lines.concat(result.verification ? verificationLines_(result.verification) : []));
  return result;
}

function verifyMigration() {
  requireMaintenanceAccess_();
  const pkg = readMigrationPackageFile_();
  const report = verifyMigrationPackage_(pkg);
  logMigrationReport_(report, [verificationHeadline_(report)].concat(verificationLines_(report)));
  return report;
}

// ---------------------------------------------------------------------------------------------------------------

function readMigrationPackageFile_() {
  const fileId = String(PropertiesService.getScriptProperties().getProperty(MIGRATION_PACKAGE_PROPERTY) || '').trim();
  if (!fileId) {
    throw appError_(ERROR_CODE.CONFIG_MISSING, 'Script Property ' + MIGRATION_PACKAGE_PROPERTY + ' belum diisi. Unggah ' +
      'migration-package.json ke Google Drive lalu isi property tersebut dengan ID file-nya.');
  }
  let text;
  try {
    text = DriveApp.getFileById(fileId).getBlob().getDataAsString('UTF-8');
  } catch (error) {
    throw appError_(ERROR_CODE.CONFIG_MISSING, 'File paket migrasi (' + MIGRATION_PACKAGE_PROPERTY + ') tidak dapat dibuka. ' +
      'Periksa ID file dan hak akses.', { fileId: fileId });
  }
  try {
    return JSON.parse(text);
  } catch (error) {
    throw appError_(ERROR_CODE.VALIDATION, 'File paket migrasi bukan JSON yang valid.', { fileId: fileId });
  }
}

function readDryRunMarker_() {
  const text = PropertiesService.getScriptProperties().getProperty(MIGRATION_DRY_RUN_PROPERTY);
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch (error) {
    return null;
  }
}

/** Business decisions still open for this package: the default is applied, the owner's confirmation is pending. */
function openDecisionLines_(summary) {
  return (summary && summary.openDecisions ? summary.openDecisions : []).map(function (decision) {
    return 'DECISION REQUIRED ' + decision.id + ' (' + decision.topic + '): default diterapkan — ' + decision.applied;
  });
}

function verificationHeadline_(report) {
  const counts = report.summary || { pass: 0, fail: 0, info: 0 };
  return 'VERIFIKASI ' + (report.ok ? 'LULUS' : 'GAGAL') + ' (' + counts.pass + ' lulus, ' + counts.fail + ' gagal, ' +
    counts.info + ' informasi)';
}

function verificationLines_(report) {
  return report.checks.map(function (check) {
    return check.status + ' ' + check.name + (check.status === 'FAIL' ? ' ' + JSON.stringify(check.details).slice(0, 1500) : '');
  });
}

/** Logs the summary lines and saves the full report as JSON in Drive; a failed save never hides the result. */
function logMigrationReport_(report, lines) {
  lines.forEach(function (line) {
    if (line) Logger.log(line);
  });
  try {
    const stamp = Utilities.formatDate(currentDate_(), getAppTimeZone_(), 'yyyyMMdd-HHmmss');
    const name = 'pik-migration-' + String(report.kind).toLowerCase().replace(/_/g, '-') + '-' + stamp + '.json';
    const content = JSON.stringify(report, null, 2);
    const folderId = getConfig_().DRIVE_ROOT_FOLDER_ID;
    const file = folderId ? DriveApp.getFolderById(folderId).createFile(name, content, 'application/json')
      : DriveApp.createFile(name, content, 'application/json');
    report.reportFile = { id: file.getId(), name: name, url: file.getUrl() };
    Logger.log('Laporan lengkap: ' + name + ' ' + file.getUrl());
  } catch (error) {
    report.reportFile = null;
    Logger.log('Laporan tidak dapat disimpan ke Drive: ' + (error && error.message ? error.message : String(error)));
  }
}
