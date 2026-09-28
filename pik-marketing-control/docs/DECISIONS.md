# DECISIONS — PIK Marketing Control

Register keputusan material (arsitektur, data, keamanan, alur bisnis). Keputusan bisnis yang belum dijawab ditandai **OPEN**.
Default yang sudah dipasang di kode selalu dapat dibalik. Rincian opsi dan dampak tiap keputusan bisnis ada di dokumen rencana Phase 01
(tidak dipublikasikan selama repositori ini publik, lihat D2).

## 1. Keputusan bisnis

| ID | Topik | Status | Wujud di database Phase 02 |
|---|---|---|---|
| D1 | Stack aplikasi | **Diputuskan: Google Apps Script + Google Sheets** (instruksi Phase 02) | Kode di `src/` (clasp); satu spreadsheet database, satu sheet per tabel |
| D2 | Lokasi kode & kerahasiaan data | **OPEN** | Repositori publik: workbook, laporan, dan dokumen Phase 01 berisi data bisnis di-ignore git; fixture test sintetis |
| D3 | Record legacy dengan relasi wajib kosong; outstanding legacy | **OPEN**, default dipasang | Kolom `is_legacy`; kolom "wajib (kecuali legacy)"; baris legacy divalidasi secara struktural saja |
| D4 | Status PO "Hold/On Hold" | **OPEN**, default dipasang | Nilai `ON_HOLD` di enum `PO_STATUS` (dapat dinonaktifkan di ENUMS) |
| D5 | Hak akses modul yang belum diatur | **OPEN** | Belum berdampak ke database (Phase 04) |
| D6 | Tanggal hari/bulan tertukar | Default | Tidak ada koreksi otomatis; tanggal disimpan apa adanya |
| D7 | Nomor PO kosong/ganda | Default | `po_number` wajib kecuali legacy; `(customer_id, po_number_key)` unik untuk PO yang tidak `CANCELLED` |
| D8 | Duplikat customer/produk | Default | Kode produk tidak unik; tidak ada penggabungan otomatis |
| D9 | Delivery negatif legacy | Default | Qty delivery > 0 untuk data baru; nilai legacy disimpan apa adanya |
| D10 | Makna sheet LEADTIME | Default | `LEADTIME` = jadwal pengiriman terencana; tidak dihitung sebagai qty terkirim |
| D11 | Keuangan legacy | Default | `PO_FINANCIALS` read-only (hanya proses migrasi yang menulis); `INVOICES_PAYMENTS` tabel biasa |
| D12 | ID dan prefiks | Default | `PREFIX-XXXXXXXXXX` (10 hex); ID workbook dipakai apa adanya; `PAY-` untuk invoice |
| D13 | Status customer hasil default | Default | `status` customer opsional; migrasi mengisi `ACTIVE` |
| D14 | Follow-up today/overdue | Default | `OVERDUE` bukan status tersimpan; dihitung dari tanggal dan status selain `DONE`/`CANCELLED` |

## 2. Keputusan teknis Phase 02

Keputusan teknis yang dapat dibalik, diambil sendiri sesuai aturan kerja proyek.

| ID | Keputusan | Alasan |
|---|---|---|
| P2-01 | Tanggal disimpan sebagai teks `yyyy-MM-dd`, waktu sebagai teks ISO 8601 UTC, angka sebagai number, boolean sebagai checkbox. Kolom teks diformat Plain text (`@`) sebelum setiap penulisan. | Sheets mengubah teks seperti `00123`, `2026-09-28`, atau `TRUE` secara otomatis; tanggal sebagai `Date` bergantung pada zona waktu. |
| P2-02 | Tata letak kolom baku (`id` → bisnis → `is_active` → lineage → audit). Kolom baru hanya ditambahkan di akhir. | Header adalah kontrak; initializer tidak pernah mengubah urutan yang sudah berisi data. |
| P2-03 | Initializer dua tahap: rencana dulu, lalu eksekusi. Header yang berbeda, data tanpa header, atau versi skema database yang lebih baru membatalkan run tanpa perubahan apa pun. | Starter menimpa header tanpa pemeriksaan; database tidak boleh rusak diam-diam. |
| P2-04 | Soft delete (`is_active`) di semua tabel bisnis; tidak ada API hapus permanen. Nilai unik tetap "dipakai" oleh record arsip. | PRD: tidak ada penghapusan destruktif transaksi. Record arsip dapat dipulihkan tanpa bentrok. |
| P2-05 | `created_by`, `updated_by`, dan `AUDIT_LOG.actor_email` berisi email, bukan ID user. | Pelaku bisa proses sistem/migrasi yang tidak ada di `USERS`. |
| P2-06 | `USERS` tanpa `password_hash`. | Login memakai akun Google (Apps Script `Session`); tidak ada password yang disimpan. |
| P2-07 | `products.status` dari spesifikasi tidak dibuat; `is_active` mewakili kolom `Active` workbook. | Nilai status produk tidak didefinisikan di mana pun; kolom dapat ditambahkan di akhir tabel bila dibutuhkan. |
| P2-08 | `STOCK.status` (enum `STOCK_STATUS`: `READY`, `RESERVED`). | Di ENUMS workbook, Ready/Reserved tercatat sebagai StockType, padahal di data dipakai sebagai status. |
| P2-09 | Kolom tambahan di luar spesifikasi: kunci pencarian `*_key`, `variant`, `payment_term`, `sj_number`, `destination`, lampiran, kolom `*_legacy`, dan lineage (`is_legacy`, `source_file`, `source_sheet`, `legacy_row`, `import_ref`, `migrated_at`, `migration_hash`). | Berasal dari profil data Phase 01; menjaga jejak sumber dan idempotensi migrasi. |
| P2-10 | Baris legacy hanya divalidasi secara struktural (tipe, enum, relasi, keunikan). Batas angka, "wajib (kecuali legacy)", dan aturan tabel hanya berlaku untuk data baru. | Migrasi tidak boleh menolak atau mengubah nilai sumber; masalah dicatat di `MIGRATION_ISSUES`. |
| P2-11 | Relasi wajib ada dan berformat ID tabel tujuan. Relasi baru/berubah wajib menunjuk record aktif. Relasi yang saling terkait wajib konsisten (mis. baris PO milik PO dan produk yang sama). | Mencegah record yatim dan data yang saling bertentangan. |
| P2-12 | Beberapa kolom dibuat lebih ketat daripada spesifikasi: status PO wajib (default `OPEN`), PIC aktivitas dan follow-up wajib, status lead default `NEW`, status follow-up default `PLANNED`, `paid_amount` default 0, status bayar default `UNPAID`. | Nilai kosong di kolom ini membuat dashboard dan daftar kerja tidak dapat dihitung. |
| P2-13 | Teks yang diawali `=` atau `'` ditolak. Teks angka dengan pemisah ribuan (`1.500`) atau koma desimal ditolak, bukan ditebak. | Mencegah formula tersimpan dan salah baca angka format Indonesia. |
| P2-14 | Proteksi "hanya peringatan" untuk header semua sheet serta seluruh README dan AUDIT_LOG. Proteksi yang lebih ketat buatan Admin tidak diubah. | Mencegah edit manual yang tidak sengaja tanpa risiko mengunci pemilik. |
| P2-15 | Fungsi pemeliharaan publik hanya dapat dijalankan pemilik/deployer skrip atau email di Script Property `ADMIN_EMAILS`. | Setiap fungsi tanpa `_` dapat dipanggil lewat `google.script.run`. |
| P2-16 | `include` menjadi `include_` (privat); dashboard mengembalikan `NOT_IMPLEMENTED`, bukan angka 0; pesan error di UI ditampilkan dengan `textContent`. | Mengurangi permukaan serangan, tidak menampilkan metrik palsu, dan mencegah XSS. |
| P2-17 | Pengujian memakai emulator Apps Script di Node ditambah `runDatabaseSelfTest()`, yang menjalankan kasus yang sama di Apps Script sungguhan. Ada juga type-check terhadap typings resmi Apps Script. | Kredensial Google tidak tersedia di environment pengembangan; kasus uji tetap satu sumber. |
| P2-18 | `@google/clasp` dipin ke 3.4.1; tidak ada dependency runtime. | Build yang dapat diulang; starter memakai versi `latest`. |
