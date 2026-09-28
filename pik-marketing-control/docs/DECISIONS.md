# DECISIONS — PIK Marketing Control

Register keputusan material (arsitektur, data, keamanan, alur bisnis). Keputusan bisnis yang belum dijawab ditandai **OPEN**.
Default yang sudah dipasang di kode selalu dapat dibalik. Rincian opsi dan dampak tiap keputusan bisnis ada di dokumen rencana Phase 01
(tidak dipublikasikan selama repositori ini publik, lihat D2).

## 1. Keputusan bisnis

| ID | Topik | Status | Wujud di database (Phase 02) dan migrasi (Phase 03) |
|---|---|---|---|
| D1 | Stack aplikasi | **Diputuskan: Google Apps Script + Google Sheets** (instruksi Phase 02) | Kode di `src/` (clasp); satu spreadsheet database, satu sheet per tabel |
| D2 | Lokasi kode & kerahasiaan data | **OPEN** | Repositori publik: workbook, laporan, dan dokumen Phase 01 berisi data bisnis di-ignore git; fixture test sintetis |
| D3 | Record legacy dengan relasi wajib kosong; outstanding legacy | **OPEN**, default dipasang — **DECISION REQUIRED sebelum migrasi produksi** | Kolom `is_legacy`; kolom "wajib (kecuali legacy)"; baris legacy divalidasi secara struktural saja. Paket migrasi memakai opsi A: record diimpor dengan relasi kosong + isu; outstanding dihitung dari transaksi tertaut, nilai legacy di kolom `*_legacy` |
| D4 | Status PO "Hold/On Hold" | **OPEN**, default dipasang — **DECISION REQUIRED sebelum migrasi produksi** | Nilai `ON_HOLD` di enum `PO_STATUS` (dapat dinonaktifkan di ENUMS); paket migrasi memetakan Hold/On Hold ke `ON_HOLD`, teks asli di `status_legacy` |
| D5 | Hak akses modul yang belum diatur | **OPEN** | Belum berdampak ke database (Phase 04) |
| D6 | Tanggal hari/bulan tertukar | Default | Tidak ada koreksi otomatis; tanggal disimpan apa adanya |
| D7 | Nomor PO kosong/ganda | Default | `po_number` wajib kecuali legacy; `(customer_id, po_number_key)` unik untuk PO yang tidak `CANCELLED` |
| D8 | Duplikat customer/produk | Default | Kode produk tidak unik; tidak ada penggabungan otomatis |
| D9 | Delivery negatif legacy | Default | Qty delivery > 0 untuk data baru; nilai legacy disimpan apa adanya |
| D10 | Makna sheet LEADTIME | Default | `LEADTIME` = jadwal pengiriman terencana; tidak dihitung sebagai qty terkirim |
| D11 | Keuangan legacy | Default | `PO_FINANCIALS` read-only (hanya proses migrasi yang menulis); `INVOICES_PAYMENTS` tabel biasa. Status bayar diturunkan dari nilai legacy: invoice dari outstanding & pembayaran; ringkasan PO dari label LUNAS/BELUM LUNAS, dengan BELUM LUNAS dibedakan menjadi `UNPAID` (outstanding ≥ total) atau `PARTIAL` (sebagian terbayar) |
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

## 3. Keputusan teknis Phase 03 (migrasi)

| ID | Keputusan | Alasan |
|---|---|---|
| P3-01 | PROFILE dan MAP berjalan di Node dan menghasilkan satu paket JSON berhash SHA-256. DRY RUN, MIGRATE, dan VERIFY adalah kode Apps Script (`src/migration/`) yang membaca paket dari Drive; kode yang sama digladikan di emulator. | Apps Script tidak dapat membaca `.xlsx` tanpa konversi; workbook sumber tetap tidak tersentuh dan hasil pemetaan dapat ditinjau sebelum dimuat. |
| P3-02 | `runMigration()` hanya berjalan bila dry run paket dengan hash yang sama lolos tanpa error (penanda di Script Property `MIGRATION_DRY_RUN`). Dry run diulang di bawah lock sebelum menulis. | Aturan fase: tidak ada migrasi nyata sebelum dry run bersih; rencana tidak boleh basi. |
| P3-03 | ID record = ID workbook (R-1). Relasi hanya dari ID workbook atau kecocokan nomor PO/kode komponen yang persis dan unik (R-3, hanya INBOUND_MAKLON). ID rujukan yang tidak ada dikosongkan dengan isu `FK_NOT_FOUND`. Kandidat relasi hanya dicatat di isu. | Tidak menebak relasi; nomor baris Excel tidak pernah menjadi ID. |
| P3-04 | Setiap baris sumber tercatat: MIGRATED, EXCLUDED (baris header/total/kosong; baris asli lengkap disimpan di isunya), ISSUE_ONLY, REPRESENTED, atau NOT_MIGRATED (dokumentasi). Baris tanpa ID memblokir paket sebagai error struktural. | Tidak ada baris yang dibuang diam-diam. |
| P3-05 | Setiap nilai yang berbeda dari sel sumbernya tercatat di log transformasi (aturan, nilai asal, nilai hasil). Record menyimpan `import_ref`, `source_file`, `source_sheet`, `legacy_row`, `migrated_at`, `migration_hash`. AUDIT_LOG mencatat satu `MIGRATION_RUN` per batch insert dan `UPDATE` per record saat rerun. | Semua transformasi dapat ditelusuri ke sel sumbernya. |
| P3-06 | Rerun idempoten: `migration_hash` = SHA-256 baris sumber + record hasil pemetaan. Record yang sudah diedit pengguna tidak ditimpa (isu `MIGRATION_CONFLICT` menyimpan nilai sumber baru); isu yang sudah ditinjau Admin dipertahankan. | Aman dijalankan ulang setelah sumber atau pemetaan berubah. |
| P3-07 | Verifikasi: jumlah, record hilang, duplikat, isi, accounting, relasi kosong beserta isu penjelasnya, orphan & referensi tidak valid (`verifyDatabase`), total numerik yang dihitung langsung dari baris sumber, relasi terisi, idempotensi, dan rekonsiliasi PO/delivery/retur/outstanding/invoice. Toleransi total hanya untuk pembulatan uang 2 desimal. | Validasi minimal Phase 03, diukur terhadap sumber, bukan terhadap hasil pemetaan. |
| P3-08 | Nilai enum wajib yang tidak punya padanan tidak diberi default: dry run gagal (`REQUIRED`) dan migrasi diblokir sampai ada keputusan. Nilai opsional tanpa padanan dikosongkan dengan isu `VALUE_NOT_MAPPED`. | Data yang benar-benar ambigu butuh keputusan bisnis, bukan tebakan. |
| P3-09 | Hasil analisis dry run: status bayar `PO_FINANCIALS` "BELUM LUNAS" tidak lagi selalu `UNPAID`; dibedakan dengan nilai outstanding legacy (D11). | Sebagian ringkasan PO BELUM LUNAS ternyata sudah dibayar sebagian; `UNPAID` akan menjadi data palsu. |
| P3-10 | Proses migrasi boleh menyalin `is_active` sumber saat insert dan update; pengguna tetap mengarsipkan/memulihkan lewat fungsi arsip. | Perubahan kolom Active di sumber harus ikut termigrasi. |
| P3-11 | `dbUpdateMany_`: update batch dengan satu pembacaan, validasi seluruh batch sebelum menulis, satu `setValues` per blok baris, audit per record. | Rerun dengan banyak perubahan harus selesai dalam batas waktu Apps Script. |
| P3-12 | Seluruh pemuatan memegang satu script lock; batas waktu 270 detik per eksekusi, berhenti di antara batch, dan dilanjutkan oleh run berikutnya. | Batas eksekusi Apps Script ±6 menit; rencana tidak boleh berubah di tengah pemuatan. |
