# TESTING — PIK Marketing Control

## Perintah

| Perintah | Butuh `npm install`? | Isi |
|---|---|---|
| `npm test` | tidak | Seluruh test Node (`node:test`, tanpa dependency) terhadap emulator Apps Script |
| `npm run typecheck` | ya | Type-check semua `.gs` terhadap typings resmi Apps Script (`@types/google-apps-script`) |
| `npm run emulate:init` | tidak | Menjalankan `setupDatabase` → `verifyDatabase` → `initializeDatabase` ulang → `runDatabaseSelfTest` di emulator dan mencetak hasilnya |
| `npm run docs:schema` | tidak | Membuat ulang `docs/DATABASE_SCHEMA.md` dari skema di kode |
| `runDatabaseSelfTest()` | — (di Apps Script) | Kasus uji database yang sama, dijalankan di Google Sheets sungguhan |

## Cakupan

| Berkas | Jumlah | Isi |
|---|---:|---|
| `src/tests/DatabaseTests.gs` (via `tests/database.test.js`) | 30 | Suite bersama Node & Apps Script: **schema** (21 sheet, urutan, prefiks ID, tata letak kolom, relasi/enum/setting konsisten); **init** (membuat semua sheet, header, format, dropdown/checkbox, catatan, proteksi, seed ENUMS/SETTINGS, README, audit; rerun tanpa perubahan; header berbeda dibatalkan tanpa perubahan; kolom ditambahkan di akhir tanpa menyentuh data; sheet kosong/tanpa header; label & nilai Admin tidak ditimpa; upgrade & penolakan versi skema); **verify** (database baru bersih; mendeteksi ID ganda, relasi yatim, enum/tipe salah, kunci turunan basi, sheet hilang, baris checkbox kosong; seluruh data uji lolos); **id** (format, keunikan, ID bentrok tidak dipakai, prefiks per tabel, ID tetap setelah update); **relasi** (FK ada/berformat/aktif, konsistensi contact-lead-baris PO-PO, aturan legacy, keunikan nomor PO, satu contact utama); **validasi** (wajib, tipe, format tanggal/jam/email/URL, panjang, formula, enum aktif/nonaktif, desimal, pemisah ribuan, batas angka, aturan tabel, kolom sistem/migrasi/internal, batch atomik); **data** (teks tetap teks, update/konflik/arsip/pulihkan/daftar/audit, tabel read-only & isu migrasi, SETTINGS); **akses** |
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

## Emulator (`tests/gas-emulator/`)

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
Setelah `npm run push`, jalankan dari editor Apps Script:

1. `setupDatabase` → log `setupDatabase: {...}`.
2. `runDatabaseSelfTest` → log per kasus (`PASSED`/`FAILED`) dan ringkasan `Self-test: 30/30 lulus`. Spreadsheet uji dibuat sementara
   lalu dipindah ke trash. Database produksi dan Script Properties tidak disentuh.
3. `verifyDatabase` → `ok: true`.

Bila self-test melewati batas waktu eksekusi, kasus yang tersisa ditandai `SKIPPED`. Jalankan per kelompok dengan memanggil
`runDatabaseSelfTest('init')`, `('relasi')`, `('validasi')`, dan seterusnya dari fungsi pembungkus sementara.
Kasus yang gagal di Apps Script tetapi lulus di emulator menandakan asumsi emulator yang keliru. Perbaiki emulator dan
`tests/emulator.test.js`, lalu kodenya.
