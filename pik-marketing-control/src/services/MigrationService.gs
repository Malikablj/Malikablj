/**
 * Migration entry points (Phase 03). Disabled until the migration engine, dry-run and verification exist.
 */

function profileSourceWorkbook() {
  requireMaintenanceAccess_();
  throw migrationNotImplemented_();
}

function validateMigrationMapping() {
  requireMaintenanceAccess_();
  throw migrationNotImplemented_();
}

function dryRunMigration() {
  requireMaintenanceAccess_();
  throw migrationNotImplemented_();
}

function runMigration() {
  requireMaintenanceAccess_();
  throw appError_(ERROR_CODE.NOT_IMPLEMENTED,
    'Migrasi data nyata dinonaktifkan sampai dry-run selesai dibuat dan diverifikasi.');
}

function verifyMigration() {
  requireMaintenanceAccess_();
  throw migrationNotImplemented_();
}

function migrationNotImplemented_() {
  return appError_(ERROR_CODE.NOT_IMPLEMENTED, 'Fitur migrasi belum tersedia (Phase 03).');
}
