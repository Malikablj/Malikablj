/**
 * Translates PostgreSQL errors into user-facing API errors.
 * Constraint names are defined explicitly in database/schema.sql so they can be mapped here.
 */
import { AppError } from './errors.js';

const UNIQUE_MESSAGES = {
  uq_users_email: 'Email sudah digunakan oleh user lain.',
  uq_customers_customer_code: 'Kode customer sudah digunakan.',
  uq_contacts_one_primary: 'Customer ini sudah memiliki kontak utama.',
  uq_purchase_orders_customer_po: 'Nomor PO ini sudah terdaftar untuk customer tersebut.',
  uq_invoices_number: 'Nomor invoice sudah terdaftar.',
};

const FOREIGN_KEY_MESSAGES = {
  fk_activities_lead_customer: 'Lead yang dipilih bukan milik customer ini.',
  fk_activities_contact_customer: 'Kontak yang dipilih bukan milik customer ini.',
  fk_follow_ups_lead_customer: 'Lead yang dipilih bukan milik customer ini.',
  fk_follow_ups_activity_customer: 'Aktivitas yang dipilih bukan milik customer ini.',
  fk_leads_contact_customer: 'Kontak yang dipilih bukan milik customer ini.',
  fk_deliveries_line_po: 'Item PO yang dipilih bukan bagian dari PO ini.',
  fk_returns_po_customer: 'PO yang dipilih bukan milik customer ini.',
  fk_returns_line_po: 'Item PO yang dipilih bukan bagian dari PO ini.',
};

const CHECK_MESSAGES = {
  ck_po_lines_quantity: 'Qty order tidak boleh negatif.',
  ck_deliveries_quantity: 'Qty kirim tidak boleh negatif.',
  ck_returns_quantity: 'Qty retur tidak boleh negatif.',
  ck_stock_quantity: 'Qty stok tidak boleh negatif.',
  ck_leadtime_target: 'Lead time harus terkait produk atau customer.',
};

const CONNECTION_CODES = new Set(['ECONNREFUSED', 'ENOTFOUND', 'ETIMEDOUT', '57P01', '57P03', '08000', '08001', '08003', '08006']);

/** Returns an AppError for known database errors, or null when the error is not a DB error we map. */
export function mapDatabaseError(error) {
  const { code, constraint, detail } = error;
  if (!code) return null;

  if (CONNECTION_CODES.has(code)) {
    return new AppError(503, 'DATABASE_UNAVAILABLE', 'Database sedang tidak dapat dihubungi. Silakan coba beberapa saat lagi.');
  }
  switch (code) {
    case '23505':
      return new AppError(409, 'CONFLICT', UNIQUE_MESSAGES[constraint] || 'Data yang sama sudah ada.');
    case '23503':
      if (FOREIGN_KEY_MESSAGES[constraint]) {
        return new AppError(409, 'REFERENCE_ERROR', FOREIGN_KEY_MESSAGES[constraint]);
      }
      if (detail && detail.includes('is still referenced')) {
        return new AppError(409, 'REFERENCE_ERROR', 'Data ini masih digunakan oleh data lain sehingga tidak dapat diubah atau dihapus.');
      }
      return new AppError(409, 'REFERENCE_ERROR', 'Data referensi yang dipilih tidak ditemukan.');
    case '23514':
      return new AppError(400, 'VALIDATION_ERROR', CHECK_MESSAGES[constraint] || 'Nilai data tidak valid.');
    case '23502':
      return new AppError(400, 'VALIDATION_ERROR', 'Ada data wajib yang belum diisi.');
    case '22P02':
    case '22007':
    case '22008':
      return new AppError(400, 'VALIDATION_ERROR', 'Format data tidak valid.');
    case '22003':
      return new AppError(400, 'VALIDATION_ERROR', 'Angka terlalu besar.');
    case '40001':
    case '40P01':
      return new AppError(409, 'CONCURRENT_UPDATE', 'Data sedang diubah oleh pengguna lain. Silakan coba lagi.');
    default:
      return null;
  }
}
