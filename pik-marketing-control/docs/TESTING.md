# TESTING — PIK Marketing Control

## Perintah

| Perintah | Butuh `npm install`? | Isi |
|---|---|---|
| `npm test` | tidak | Seluruh test Node (`node:test`, tanpa dependency) terhadap emulator Apps Script |
| `npm run typecheck` | ya | Type-check semua `.gs` terhadap typings resmi Apps Script (`@types/google-apps-script`) |
| `npm run emulate:init` | tidak | Menjalankan `setupDatabase` → `verifyDatabase` → `initializeDatabase` ulang → `runDatabaseSelfTest` di emulator dan mencetak hasilnya |
| `npm run docs:schema` | tidak | Membuat ulang `docs/DATABASE_SCHEMA.md` dari skema di kode |
| `npm run migrate -- migrate` | tidak | Gladi migrasi lengkap dengan workbook asli (lokal, di `migration/source/`) di emulator; laporan rahasia di `docs/MIGRATION_REPORT.md` |
| `runDatabaseSelfTest()` | — (di Apps Script) | Kasus uji database yang sama, dijalankan di Google Sheets sungguhan |

## Cakupan

| Berkas | Jumlah | Isi |
|---|---:|---|
| `src/tests/DatabaseTests.gs` (via `tests/database.test.js`) | 35 | Suite bersama Node & Apps Script: **schema** (21 sheet, urutan, prefiks ID, tata letak kolom, relasi/enum/setting konsisten); **init** (membuat semua sheet, header, format, dropdown/checkbox, catatan, proteksi, seed ENUMS/SETTINGS, README, audit; rerun tanpa perubahan; header berbeda dibatalkan tanpa perubahan; kolom ditambahkan di akhir tanpa menyentuh data; sheet kosong/tanpa header; label & nilai Admin tidak ditimpa; upgrade & penolakan versi skema); **verify** (database baru bersih; mendeteksi ID ganda, relasi yatim, enum/tipe salah, kunci turunan basi, sheet hilang, baris checkbox kosong; seluruh data uji lolos); **id** (format, keunikan, ID bentrok tidak dipakai, prefiks per tabel, ID tetap setelah update); **relasi** (FK ada/berformat/aktif, konsistensi contact-lead-baris PO-PO, aturan legacy, keunikan nomor PO, satu contact utama); **validasi** (wajib, tipe, format tanggal/jam/email/URL, panjang, formula, enum aktif/nonaktif, desimal, pemisah ribuan, batas angka, aturan tabel, kolom sistem/migrasi/internal, batch atomik); **data** (teks tetap teks, update/konflik/arsip/pulihkan/daftar/audit, update batch atomik, tabel read-only & isu migrasi, SETTINGS, teks legacy apa adanya, audit ringkas migrasi); **akses** |
| `tests/migration.test.js` | 21 | Pipeline migrasi pada workbook sintetis (`tests/fixtures/synthetic-workbook.js`: header asli AppSheet, data karangan, `.xlsx` dibuat oleh test): sumber tidak berubah (SHA-256, waktu ubah); setiap baris tercatat tepat sekali; ID workbook sebagai ID permanen, lineage & `import_ref`; pemetaan status, nilai legacy apa adanya, log transformasi = nilai tersimpan; relasi tidak ditebak (PO/produk/baris kosong, ID yatim, R-3 persis vs ganda) dan setiap relasi kosong punya isu; ID ganda di sumber memblokir paket; baris header/total/kosong, sheet tanpa pemetaan, ENUMS, nilai kolom tak dimigrasi disimpan di isu; nilai wajib tanpa padanan dan baris tanpa ID memblokir migrasi; paket rusak ditolak (hash, versi skema, kolom, jumlah, urutan muat, ID ganda); dry run tidak menulis; migrasi ditolak tanpa dry run/dry run gagal/paket lain; migrasi + verifikasi + rerun idempoten; rekonsiliasi PO/delivery/retur/outstanding/invoice dengan angka hitungan tangan; lanjut setelah batas waktu; suntingan pengguna tidak ditimpa & konflik; verifikasi mendeteksi record hilang, isi berubah, referensi yatim, baris ganda; laporan; CLI; workbook asli (lokal saja, dilewati bila tidak ada) |
| `tests/maintenance.test.js` | 12 | `setupDatabase` (buat, simpan ID, idempoten, pindah folder Drive, sheet bawaan berlokal lain), konfigurasi hilang/salah, akses pemeliharaan (pengguna lain, email kosong, `ADMIN_EMAILS`), `setInitialProperties`, semua penulisan di bawah lock & `LOCK_TIMEOUT`, batch = 1 `setValues` data + 1 audit, tabel tumbuh melewati 1.000 baris, `runDatabaseSelfTest` tidak menyentuh database produksi, log `verifyDatabase`, respons web, `doGet` |
| `tests/project.test.js` | 8 | Semua `.gs` dapat di-parse; tanpa sintaks yang dihindari (`?.`, `??`, `replaceAll`, dll.); tiap berkas dapat dimuat sendirian; tidak ada nama global ganda; daftar fungsi publik tertutup; urutan muat terbalik tetap lulus; manifest; HTML tanpa `innerHTML` |
| `tests/emulator.test.js` | 8 | Asumsi perilaku Google Sheets yang ditiru emulator (lihat di bawah) |
| `tests/docs.test.js` | 1 | `docs/DATABASE_SCHEMA.md` sesuai kode |

## Hasil Phase 02 (2026-09-28)

- `npm test`: **59/59 lulus**.
- `npm run typecheck`: **20 berkas `.gs`, 0 masalah tipe**. Kontrol negatif: nama method yang salah ketik (`setNumberFormatz`) dan tipe
  argumen yang salah terdeteksi.
- `npm run emulate:init`: 21 sheet dibuat, 83 nilai ENUMS dan 6 SETTINGS di-seed, `verifyDatabase` 0 error/0 peringatan,
  `initializeDatabase` kedua tanpa perubahan, self-test 30/30.
- Uji mutasi (sekali jalan, skrip tidak disimpan di repo): 28 cacat disisipkan satu per satu ke kode. Contoh: menghapus cek formula,
  cek keunikan PO, cek FK, cek konsistensi, atomisitas batch, cek bentrok ID, penulisan audit, optimistic concurrency, deteksi header
  berbeda, format teks sebelum tulis, pola ID per tabel, idempotensi proteksi. **28/28 tertangkap** oleh test. Satu mutasi yang awalnya
  lolos (ID legacy berprefiks tabel lain) menghasilkan test tambahan.

## Hasil Phase 03 (2026-09-28)

- `npm test`: **85/85 lulus** (termasuk test workbook asli yang berjalan lokal).
- `npm run typecheck`: **21 berkas `.gs`, 0 masalah tipe**.
- Gladi migrasi dengan workbook asli (`npm run migrate -- migrate`): 0 error struktural, dry run 0 error validasi dan 0 penulisan ke
  spreadsheet, migrasi selesai dalam satu run, **verifikasi 20/20 lulus** (+1 rekonsiliasi informasi), rerun 0 penulisan, SHA-256
  workbook sama sebelum dan sesudah. Angka rinci hanya di `docs/MIGRATION_REPORT.md` (rahasia).
- Temuan dari analisis dry run yang diperbaiki sebelum migrasi: status bayar ringkasan PO "BELUM LUNAS" yang sebagian sudah dibayar
  (P3-09); ekspektasi verifikasi untuk kolom turunan, kolom relasi yang tidak ada di sheet sumber, ID yatim, dan relasi R-3 (ditemukan
  oleh workbook sintetis); suntingan Admin setelah migrasi tidak boleh dianggap kerusakan.
- Uji mutasi Phase 03 (sekali jalan, skrip tidak disimpan di repo): 16 cacat disisipkan satu per satu, antara lain pemetaan Hold,
  pembulatan uang, R-3 menerima kecocokan ganda, baris dikecualikan tanpa baris asli, gerbang dry run dihapus, suntingan pengguna
  ditimpa, isi/total tidak dibandingkan, outstanding tanpa `MAX(0, …)`, hash paket tidak diperiksa, update batch tidak atomik,
  penanda dry run selalu OK. **16/16 tertangkap**.

## Emulator (`tools/gas-emulator/`)

Emulator adalah test double untuk `SpreadsheetApp`, `PropertiesService`, `LockService`, `Session`, `Utilities`, `DriveApp`,
`HtmlService`, dan `Logger`. Pemanggilan API yang tidak dikenal langsung gagal. Berkas `.gs` dimuat ke satu konteks `vm` sebagai
skrip terpisah dengan satu scope global, seperti di Apps Script. Emulator juga mencatat setiap penulisan beserta status lock-nya, sehingga
batching dan locking dapat diuji.

Perilaku Sheets yang ditiru dengan sengaja (dikunci oleh `tests/emulator.test.js`):
- Sel berformat otomatis mengonversi teks mirip angka, boolean, dan tanggal. Sel Plain text (`@`) menyimpan teks apa adanya.
- Apostrof di depan ditelan. Formula tidak didukung (penulisan formula langsung gagal).
- Ukuran sheet tetap: rentang di luar ukuran gagal, dan dimensi data `setValues` harus cocok.
- `getLastRow`/`getLastColumn` hanya menghitung sel bernilai.
- Baris sisipan tidak mewarisi format/validasi. Kode tetap menerapkan ulang format dan validasi, jadi aman untuk kedua perilaku.
- Validasi data tidak ditegakkan untuk penulisan skrip.

Yang **tidak** ditiru: mesin formula, kuota & latensi, izin berbagi, penegakan proteksi, dan konkurensi antar-eksekusi (lock disimulasikan
dalam satu proses).

## Belum diverifikasi

Kode belum dijalankan di Google Apps Script sungguhan karena kredensial Google tidak tersedia di environment pengembangan.
Migrasi data ke database produksi juga belum dijalankan. Setelah `npm run push`, jalankan dari editor Apps Script:

1. `setupDatabase` → log `setupDatabase: {...}`.
2. `runDatabaseSelfTest` → log per kasus (`PASSED`/`FAILED`) dan ringkasan `Self-test: 35/35 lulus`. Spreadsheet uji dibuat sementara
   lalu dipindah ke trash. Database produksi dan Script Properties tidak disentuh.
3. `verifyDatabase` → `ok: true`.
4. Migrasi: langkah di `docs/DEPLOYMENT.md` §7. Hasil `dryRunMigration`, `runMigration`, dan `verifyMigration` harus sama dengan
   `docs/MIGRATION_REPORT.md` (jumlah per tabel, total, dan pemeriksaan).

Bila self-test melewati batas waktu eksekusi, kasus yang tersisa ditandai `SKIPPED`. Jalankan per kelompok dengan memanggil
`runDatabaseSelfTest('init')`, `('relasi')`, `('validasi')`, dan seterusnya dari fungsi pembungkus sementara.
Kasus yang gagal di Apps Script tetapi lulus di emulator menandakan asumsi emulator yang keliru. Perbaiki emulator dan
`tests/emulator.test.js`, lalu kodenya.
