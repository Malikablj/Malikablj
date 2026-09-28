/**
 * Builds a small SYNTHETIC workbook for migration tests. All names are fixture data
 * ("PT Fixture Satu", "PO-001", ...); nothing here is business data.
 *
 * The rows deliberately contain the problems the migration must handle: identical and
 * conflicting duplicate keys, missing required values, ambiguous names, unknown references,
 * ambiguous text dates/numbers, unmapped status labels, a second primary contact,
 * negative quantities, products that cannot be matched, and an unrelated sheet.
 */
import ExcelJS from 'exceljs';

const d = (y, m, day) => new Date(Date.UTC(y, m - 1, day));

export const FIXTURE_SHEETS = {
  Pelanggan: [
    ['Kode', 'Nama Customer', 'Industri', 'Telepon', 'Email', 'Status', 'Catatan'],
    ['C001', 'PT Fixture Satu', 'Kosmetik', '0215550001', 'Info@Fixture1.test', 'Aktif', null], // 2
    ['C002', 'CV Fixture Dua', 'Farmasi', 81234567, null, 'Potensial', null], // 3 phone stored as number
    ['C001', 'PT Fixture Satu', 'Kosmetik', '0215550001', 'Info@Fixture1.test', 'Aktif', null], // 4 identical duplicate
    ['C001', 'PT Fixture Palsu', 'Kosmetik', null, null, 'Aktif', null], // 5 same key, different content
    [null, null, null, null, null, null, null], // 6 empty
    ['C004', null, 'Lainnya', null, null, 'Aktif', null], // 7 missing name
    ['C005', 'PT Kembar Jaya', null, null, null, 'Aktif', null], // 8
    ['C006', 'PT. KEMBAR JAYA', null, null, null, 'Status Aneh', null], // 9 same normalized name, unknown status
    [null, 'CV Tanpa Kode', null, null, null, 'Dormant', null], // 10 keyed by name
  ],
  Kontak: [
    ['Kode Customer', 'Nama Customer', 'Nama Kontak', 'Jabatan', 'WhatsApp', 'Utama'],
    ['C001', null, 'Budi Fixture', 'Purchasing', '08123', 'Ya'], // 2
    [null, 'CV Fixture Dua', 'Sari Fixture', 'Owner', null, 'Tidak'], // 3 customer by name
    [null, 'PT Kembar Jaya', 'Kontak Ambigu', null, null, null], // 4 ambiguous customer
    [null, 'PT Tidak Ada', 'Kontak Yatim', null, null, null], // 5 unknown customer
    ['C001', null, 'Andi Fixture', 'QC', null, 'Ya'], // 6 second primary
  ],
  Produk: [
    ['Kode Produk', 'Nama Produk', 'Kategori', 'Satuan', 'Customer', 'Lead Time'],
    ['P001', 'Botol 100ml Fixture', 'Botol', 'pcs', 'PT Fixture Satu', 14], // 2
    ['P002', 'Jar 30g Fixture', 'Jar', 'pcs', null, 21], // 3
    ['P003', 'Cap 24mm Fixture', 'Cap', 'pcs', null, null], // 4
    ['P002', 'Jar 30g Berbeda', 'Jar', 'pcs', null, null], // 5 conflicting duplicate code
  ],
  Leads: [
    ['Nama Lead', 'Kode Customer', 'Status', 'Prioritas', 'Nilai', 'PIC', 'Target Closing'],
    ['Botol serum baru', 'C001', 'Penawaran', 'Tinggi', 150000000, 'fixture.sales@test.local', d(2026, 12, 31)], // 2
    ['Jar krim', 'C002', 'New', null, null, 'Orang Tidak Dikenal', null], // 3 unknown owner
  ],
  Aktivitas: [
    ['Tanggal', 'Jam', 'Kode Customer', 'Nama Lead', 'Jenis', 'Judul', 'Deskripsi', 'PIC'],
    [d(2026, 3, 2), '10:30', 'C001', 'Botol serum baru', 'WA', 'Kirim penawaran', 'Penawaran botol 100ml', 'fixture.sales@test.local'], // 2
    ['15/03/2026', null, 'C002', null, 'Kunjungan', null, 'Kunjungan pabrik\nbaris kedua', null], // 3 text date, no subject
    ['03/04/2026', null, 'C001', null, 'Meeting', 'Meeting ambigu', null, null], // 4 ambiguous text date
  ],
  'Follow Up': [
    ['Tanggal', 'Kode Customer', 'Nama Lead', 'Status', 'Catatan', 'PIC'],
    [d(2026, 10, 1), 'C001', 'Botol serum baru', 'Rencana', 'Telepon ulang', 'fixture.sales@test.local'], // 2
    [d(2026, 9, 1), 'C002', null, 'Selesai', 'Sudah dihubungi', null], // 3
  ],
  PO: [
    ['No PO', 'Nama Customer', 'Tanggal PO', 'Target Kirim', 'Status', 'PIC'],
    ['PO-001', 'PT Fixture Satu', d(2026, 1, 10), d(2026, 2, 10), 'Open', 'fixture.sales@test.local'], // 2
    ['PO-002', 'CV Fixture Dua', d(2026, 1, 15), null, 'Partial', null], // 3
    ['PO-001', 'CV Fixture Dua', d(2026, 1, 20), null, 'Open', null], // 4 same number, other customer
    ['PO-003', 'PT Tidak Ada', null, null, 'Open', null], // 5 unknown customer
  ],
  'PO Detail': [
    ['No PO', 'Nama Customer', 'Kode Produk', 'Nama Produk', 'Qty', 'Satuan', 'Harga', 'Outstanding'],
    ['PO-001', 'PT Fixture Satu', 'P001', null, 1000, 'pcs', 1500, 350], // 2
    ['PO-001', 'PT Fixture Satu', 'P002', null, 500, 'pcs', 2000, 400], // 3 legacy outstanding differs
    ['PO-002', 'CV Fixture Dua', 'P003', null, 2000, 'pcs', null, 0], // 4
    ['PO-001', 'CV Fixture Dua', null, 'Produk Hilang', 10, 'pcs', null, 10], // 5 unknown product
    ['PO-999', 'PT Fixture Satu', 'P001', null, 5, null, null, null], // 6 unknown PO
    ['PO-002', 'CV Fixture Dua', 'P001', null, -5, null, null, null], // 7 negative qty
    ['PO-002', 'CV Fixture Dua', 'P002', null, '1.500', null, null, null], // 8 ambiguous text number
  ],
  Pengiriman: [
    ['No PO', 'Nama Customer', 'Kode Produk', 'Nama Produk', 'Tanggal Kirim', 'Qty', 'Status', 'No Surat Jalan'],
    ['PO-001', 'PT Fixture Satu', 'P001', null, d(2026, 1, 20), 400, 'Terkirim', 'SJ-001'], // 2
    ['PO-001', 'PT Fixture Satu', 'P001', null, d(2026, 1, 25), 300, null, 'SJ-002'], // 3 default status
    ['PO-001', 'PT Fixture Satu', 'P002', null, d(2026, 2, 1), 100, 'Dijadwalkan', null], // 4 scheduled
    ['PO-002', 'CV Fixture Dua', 'P003', null, d(2026, 1, 30), 1000, 'Terkirim', 'SJ-003'], // 5
    ['PO-002', 'CV Fixture Dua', 'P003', null, d(2026, 1, 30), 1000, 'Terkirim', 'SJ-003'], // 6 identical row
    ['PO-001', 'PT Fixture Satu', null, 'Barang Lain', d(2026, 2, 5), 5, 'Terkirim', null], // 7 unknown product
  ],
  Retur: [
    ['Tanggal Retur', 'Nama Customer', 'No PO', 'Kode Produk', 'Qty', 'Alasan', 'Status'],
    [d(2026, 2, 10), 'PT Fixture Satu', 'PO-001', 'P001', 50, 'Cacat cetak', 'Diterima'], // 2
    [d(2026, 2, 12), 'CV Fixture Dua', null, 'P003', 20, 'Retak', null], // 3 no PO
  ],
  Stok: [
    ['Kode Produk', 'Nama Produk', 'Gudang', 'Tanggal', 'FG', 'WIP', 'Ready', 'Reserved'],
    ['P001', null, 'Gudang A', d(2026, 9, 1), 5000, 200, null, null], // 2 -> FG + WIP
    ['P002', null, 'Gudang A', d(2026, 9, 1), null, null, 300, -10], // 3 -> READY ok, RESERVED negative
  ],
  'Lead Time': [
    ['Kode Produk', 'Nama Customer', 'Hari', 'Catatan'],
    ['P001', 'PT Fixture Satu', 14, 'Standar'],
  ],
  'Maklon Masuk': [
    ['Tanggal', 'Nama Customer', 'Nama Barang', 'Qty', 'Satuan', 'No Dokumen'],
    [d(2026, 3, 1), 'CV Fixture Dua', 'Resin titipan', 250, 'kg', 'SJM-01'],
  ],
  Invoice: [
    ['No Invoice', 'No PO', 'Nama Customer', 'Tanggal Invoice', 'Jatuh Tempo', 'Nilai', 'Dibayar', 'Status Bayar'],
    ['INV-001', 'PO-001', 'PT Fixture Satu', d(2026, 2, 15), d(2026, 3, 15), 1000000, 400000, 'Lunas'], // status contradicts amounts
  ],
  'PO Keuangan': [
    ['No PO', 'Nama Customer', 'Nilai PO', 'PPN', 'Total'],
    ['PO-001', 'PT Fixture Satu', 2500000, 275000, 2775000],
  ],
  Catatan: [['Catatan internal'], ['Sheet ini bukan data dan harus diabaikan secara eksplisit.']],
};

export async function buildFixtureWorkbook(filePath, sheets = FIXTURE_SHEETS) {
  const workbook = new ExcelJS.Workbook();
  for (const [name, rows] of Object.entries(sheets)) {
    const worksheet = workbook.addWorksheet(name);
    rows.forEach((row, index) => {
      worksheet.getRow(index + 1).values = row.map((value) => (value === null ? undefined : value));
    });
  }
  await workbook.xlsx.writeFile(filePath);
  return filePath;
}
