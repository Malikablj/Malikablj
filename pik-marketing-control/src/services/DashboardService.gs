/**
 * Dashboard KPIs. Not implemented yet: KPIs will be computed from the database (no placeholder numbers).
 */

function getDashboardSummary() {
  return handleRequest_(function () {
    throw appError_(ERROR_CODE.NOT_IMPLEMENTED,
      'Dashboard belum tersedia. KPI akan dihitung dari database setelah migrasi dan modul terkait selesai.');
  });
}
