# TESTING — PIK Marketing Control

## Perintah

| Perintah | Butuh `npm install`? | Isi |
|---|---|---|
| `npm test` | tidak | Seluruh test Node (`node:test`, tanpa dependency) terhadap emulator Apps Script |
| `npm run typecheck` | ya | Type-check semua `.gs` terhadap typings resmi Apps Script (`@types/google-apps-script`) |
| `npm run emulate:init` | tidak | Menjalankan `setupDatabase` → `verifyDatabase` → `initializeDatabase` ulang → `runDatabaseSelfTest` di emulator dan mencetak hasilnya |
| `npm run docs:schema` | tidak | Membuat ulang `docs/DATABASE_SCHEMA.md` dari skema di kode |
| `npm run migrate -- migrate` | tidak | Gladi migrasi lengkap dengan workbook asli (lokal, di `migration/source/`) di emulator; laporan rahasia di `docs/MIGRATION_REPORT.md` |
| `npm run dev` | tidak | Aplikasi lengkap di <http://127.0.0.1:8080>: kode `.gs` dan HTML asli di emulator, data sintetis hasil migrasi, satu akun uji per role. Ganti akun: `/__dev/sign-in?email=sales@example.com` (juga `marketing@`, `management@`, `viewer@`; tanpa cookie = pemilik skrip `owner@example.com`); `/__dev/sign-in?anonymous=1` = tanpa identitas Google, seperti web app di akun Gmail biasa (login email + password). Opsi: `--seed none\|synthetic\|workbook=<file.xlsx>`, `--port`, `--latency <ms>`, `--now <ISO>` |
| `npm run test:e2e` | ya, plus `npx playwright install chromium` (sekali) | Test browser (Playwright) terhadap dev server: alur bisnis, role, daftar, state UI, ponsel/tablet. Screenshot di `e2e/artifacts/` (di-ignore git) |
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
| `tests/api-core.test.js` | 10 | Lewat `api()` sebagai user nyata (`tests/support/api-harness.js`): login (akun tak terdaftar ditolak; pemilik skrip menjadi Admin pertama hanya bila belum ada Admin aktif; role dari `USERS`; user diarsipkan ditolak; `last_login_at` paling sering tiap 10 menit); sesi (izin per role, enum, metadata form); **otorisasi: setiap route × setiap role** dicocokkan dengan matriks (ditolak `FORBIDDEN` tanpa menulis apa pun, tidak pernah `FORBIDDEN` bila berizin) + contoh PRD; amplop (error per kolom, error tak terduga disamarkan, aksi tak dikenal); semua penulisan di bawah lock, batch `setValues`, lock sibuk = `LOCK_TIMEOUT` tanpa menulis; `CONFLICT`; daftar (cari, filter, urut dengan nilai kosong di akhir, halaman, arsip opt-in, batas halaman dari SETTINGS); picker & pencarian global hanya modul yang boleh dibaca |
| `tests/api-crm.test.js` | 6 | Customer CRUD + arsip/pulihkan + riwayat; workspace customer; contact utama (pertukaran atomik, input gagal tidak mengubah contact utama lama); lead (default, relasi, board, pindah status); aktivitas + follow-up berikutnya dalam satu unit; follow-up (bucket Today/Overdue/Upcoming dari tanggal hari ini, complete + berikutnya atomik, reschedule, cancel) |
| `tests/api-operations.test.js` | 8 | PO + item dalam satu unit; delivery wajib item PO, outstanding, konfirmasi kelebihan qty; retur (menambah outstanding, konfirmasi, `CANCELLED` tidak dihitung); outstanding tidak negatif + kelebihan kirim; item PO (produk terkunci setelah transaksi, qty order < terkirim perlu konfirmasi, arsip dijaga); daftar PO (filter, urut, cari); produk & stok terbaru; lead time dan inbound |
| `tests/api-admin.test.js` | 8 | Invoice (jenis dari nomor, status bayar dari nilai, pembayaran bertahap, jatuh tempo); dashboard (KPI dari data, scope "mine"); laporan (filter, grup, baris ringkas, CSV aman untuk Excel); audit log & riwayat; user (email unik, pengaman Admin); setting (tipe & batas, audit); enum (hanya enum yang dapat diperluas, dropdown sheet); isu migrasi (hitungan, catatan wajib, dibuka ulang) |
| `tests/api-auth.test.js` | 7 | Login email + password (D15): SHA-256/HMAC/PBKDF2/UTF-8/base64url sama dengan `crypto` Node (vektor RFC + acak); hash PBKDF2 bergaram dan tidak pernah ada di respons, metadata form, maupun AUDIT_LOG (disamarkan); `AUTH_REQUIRED` tanpa identitas; pesan gagal sama untuk email tak terdaftar/password salah; password sementara wajib diganti (`PASSWORD_CHANGE_REQUIRED`); kebijakan password; token dipalsukan/diperpanjang/kedaluwarsa/rahasia diganti ditolak; ganti/reset password mengakhiri sesi lain; role dari `USERS`; user nonaktif ditolak; kunci 15 menit setelah 5 gagal; reset Admin (bukan akun sendiri, hanya Admin); `setupAdminAccount` (Admin pertama, pemulihan, hanya pemilik) |
| `tests/api-migrated.test.js` | 4 | Workbook sintetis dimigrasikan lalu dipakai lewat API: semua daftar/detail/laporan berjalan pada record legacy; outstanding PO dari API = rekonsiliasi migrasi; lineage & riwayat `MIGRATION_RUN`; Admin menautkan delivery legacy ke item PO yang benar (D3) |

Test browser (`npm run test:e2e`, Playwright, Chromium headless, dev server dengan workbook sintetis yang dimigrasikan):

| Berkas | Jumlah | Isi |
|---|---:|---|
| `e2e/flow.e2e.js` | 15 | Alur utama sebagai Admin: Login → Dashboard → Customer (form → workspace) → Contact utama → Lead (nilai format Indonesia) → pipeline (drag & drop) → Aktivitas + follow-up berikutnya → Follow-up selesai + berikutnya → PO + dua item → Delivery (kelebihan qty: batal lalu konfirmasi) → Retur → Outstanding → Dashboard (KPI berubah). Juga: tambah/ubah item PO dengan konfirmasi, validasi form (browser & server, isian tidak hilang), arsip + urungkan + riwayat, audit log. Setiap langkah dicek ulang lewat API |
| `e2e/roles.e2e.js` | 5 | Akun belum terdaftar → didaftarkan Admin lewat Pengaturan › User → dapat masuk; Viewer hanya baca (tombol tersembunyi, URL langsung ditolak, server menolak); Sales (CRM ya, PO/delivery baca, keuangan tidak); Marketing (PO ya, delivery baca) dan Management (keuangan baca); keluar; user dinonaktifkan langsung kehilangan akses |
| `e2e/lists.e2e.js` | 9 | Pagination server (halaman di URL, bertahan saat muat ulang); urutan kolom (angka: terbesar dulu); pencarian debounce (≤ 2 panggilan server); filter dan kombinasinya; arsip opt-in; pencarian global Ctrl+K; palet yang ditutup tombol Back tidak meninggalkan listener; pipeline "Pindah ke" (tanpa seret); laporan (filter, grafik, CSV terunduh) |
| `e2e/ui-states.e2e.js` | 9 | Loading (skeleton + bar muat); error jaringan + Coba lagi; simpan gagal tanpa kehilangan isian; empty state dengan langkah berikutnya; dialog konfirmasi (Batal tidak mengubah apa pun); keyboard ("/", Escape, fokus kembali); fokus awal drawer tidak merebut kolom yang sudah dipilih; ponsel 390 px (navigasi bawah, menu, kartu, filter di balik tombol, header detail tidak terjepit, bottom sheet, tanpa scroll ke samping); tablet 820 px (rel ikon) |
| `e2e/password.e2e.js` | 4 | Tanpa identitas Google (akun Gmail): Admin membuat user dengan password awal (Buat otomatis); user masuk, salah password, wajib membuat password baru (validasi), role Sales, tetap masuk setelah muat ulang, Keluar dan masuk lagi; reset Admin mengakhiri sesi yang sedang terbuka; ganti password di Profil; 5 kali salah → terkunci; layar masuk ponsel |
| `e2e/admin-data.e2e.js` | 7 | Data migrasi (tanda Legacy, transaksi belum tertaut, pembanding outstanding workbook, isu migrasi di detail, sumber legacy, riwayat); Admin menautkan delivery legacy (D3) dan outstanding dihitung ulang; isu migrasi diselesaikan; jenis aktivitas baru langsung dipakai; setting; invoice + pembayaran sebagian; stok & lead time |

Setiap berkas E2E juga memeriksa bahwa sesi browsernya tidak mencatat error JavaScript atau error console (kecuali test yang sengaja
memutus koneksi ke server).

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

## Hasil Phase 04 — backend (2026-09-29)

- `npm test`: **121/121 lulus** (36 test API baru; test database, migrasi, dan pemeliharaan tetap lulus).
- `npm run typecheck`: **35 berkas `.gs`, 0 masalah tipe**.
- Uji mutasi (sekali jalan, skrip tidak disimpan di repo): 34 cacat disisipkan satu per satu ke backend, antara lain izin route
  tidak diperiksa, R dianggap RW, invoice tampil untuk semua role, outstanding tanpa `MAX(0, …)`, retur tidak menambah outstanding,
  delivery terjadwal dihitung terkirim, cek kelebihan qty mati, jadwal tidak mengurangi sisa, overdue termasuk hari ini, contact utama
  atau follow-up berikutnya tidak divalidasi sebelum menulis, status bayar dari klien, Admin terakhir dapat diturunkan, filter tanggal
  memakai UTC, riwayat tanpa cek izin, CSV tanpa pelindung formula, PO bertransaksi dapat diarsipkan, unit of work tidak atomik, filter
  tak dikenal diabaikan, delivery ke PO tertutup, dan item PO milik PO lain diterima. **33/34 tertangkap**. Tiga mutasi yang awalnya
  lolos (Admin mengarsipkan akunnya sendiri, follow-up tanpa customer, konflik pada update multi-record) menghasilkan test tambahan.
  Satu mutasi ekuivalen: menghapus syarat "belum ada Admin aktif" di luar lock tidak mengubah perilaku, karena syarat yang sama
  diperiksa ulang di dalam lock sebelum Admin pertama didaftarkan.

## Hasil Phase 05 — frontend (2026-09-29)

- 18 layar (Login, Dashboard, Customer + detail, Contact, Lead/pipeline, Aktivitas, Follow-up, PO + detail, Delivery, Retur, Produk,
  Stok, Lead Time, Invoice/Pembayaran, Laporan, Pengaturan) diperiksa lewat screenshot pada 1440, 820, dan 390 px dengan data
  sintetis: tanpa error console, tanpa scroll ke samping.
- Pemeriksaan lokal dengan workbook asli (dimigrasikan di dev server, `--seed workbook=…`; hanya angka agregat yang dicatat di sini):
  semua route dan halaman detail PO/customer dibuka, **50 panggilan server, 0 gagal, 0 error browser**, tanpa scroll ke samping.
  Temuan yang diperbaiki: tanggal kosong muncul paling atas pada urutan terbaru (P4-10); payload laporan terlalu besar (P4-11);
  kandidat tanggal tertukar kini terlihat Admin sebagai isu migrasi di detail record (D6). Screenshot data asli tidak disimpan di repo.

## Hasil Phase 06 — integrasi end-to-end (2026-09-29)

- `npm run test:e2e`: **45/45 lulus** (Chromium headless, satu proses per berkas, ±40 detik), tiga kali berturut-turut setelah perbaikan
  terakhir.
- Bug yang ditemukan test E2E dan diperbaiki (akar masalah → perbaikan → test ulang):
  - Picker (mis. pilih customer) terbuka lagi setelah pilihan dibuat, karena pencarian ber-debounce yang masih tertunda tetap
    berjalan → debounce dibatalkan saat memilih/Escape/blur.
  - Lencana notifikasi follow-up tidak diperbarui setelah simpan yang membuat follow-up → semua form terkait memicu pembaruan.
  - Judul halaman di ponsel disembunyikan dengan `display: none` sehingga hilang bagi pembaca layar → disembunyikan secara visual saja.
  - Ponsel: nama di header detail customer terjepit menjadi satu huruf per baris bila ada tiga tombol aksi → tombol turun ke baris
    sendiri; tombol ikon tetap persegi. Test ponsel kini memeriksa ukuran judul (gagal sebelum perbaikan, lulus sesudahnya).
  - Isian masuk ke kolom yang salah. Muncul sebagai kegagalan sesekali pada test validasi form; diagnostik saat gagal menunjukkan
    teks untuk Email tersimpan di Nama customer. Akar masalah: fokus awal drawer dijalankan pada frame animasi pertama dan dapat
    menarik fokus kembali ke kolom pertama setelah pengguna berpindah ke kolom lain (perangkat lambat, pengguna cepat) → fokus awal
    hanya bila fokus belum berada di dalam drawer. Test baru memaksa urutan itu secara deterministik (gagal sebelum perbaikan).
  - Overlay yang ditutup oleh perpindahan halaman (mis. tombol Back) hanya dihapus elemennya. Listener palet pencarian tertinggal,
    sehingga Enter di halaman berikutnya membuka hasil pencarian lama → perpindahan halaman menutup drawer, dialog, menu, dan palet
    lewat fungsi close-nya (listener ikut dilepas). Test baru gagal sebelum perbaikan (Enter membuka PO lama).
- Perbaikan dari tinjauan screenshot: label PO (bukan ID) di daftar terkait, filter ponsel di balik tombol "Filter (n)", kolom angka
  diurutkan terbesar dulu pada klik pertama, pembanding outstanding workbook pada item PO legacy (D3).
- Kegagalan sesekali diperlakukan sebagai bug sampai terbukti sebaliknya, tidak diulang begitu saja. Satu ternyata bug aplikasi
  (fokus, di atas). Dua lainnya kesalahan test: (1) bar muat diperiksa pada frame pertama animasi lebarnya (lebar 0) → test menunggu
  bar terlihat; (2) setelah user dinonaktifkan, helper navigasi menunggu tampilan halaman yang justru diganti layar "belum terdaftar"
  bila respons server datang lebih dulu → test menunggu layar itu langsung.

## Hasil login email + password — D15 (2026-09-29)

- `npm test`: **128/128 lulus** (7 test baru di `tests/api-auth.test.js`). `npm run typecheck`: **37 berkas `.gs`, 0 masalah tipe**.
- `npm run test:e2e`: **49/49 lulus** (4 test baru di `e2e/password.e2e.js`; 45 test lama tetap lulus).
- Uji mutasi keamanan (sekali jalan, skrip tidak disimpan di repo): 28 cacat disisipkan satu per satu, antara lain tanda tangan token
  tidak diperiksa, kedaluwarsa diabaikan, ganti/reset password tidak mengakhiri sesi, password salah atau user nonaktif diterima,
  batas percobaan mati, wajib ganti tidak ditegakkan, hash terkirim ke browser atau tidak disamarkan di audit, Admin mengatur password
  sendiri, cek password lama dilewati, kebijakan password dilemahkan, iterasi PBKDF2 diabaikan, salt tetap, satu rotasi SHA-256 salah,
  `setupAdminAccount` tanpa cek pemilik. **28/28 tertangkap**.
- Kinerja: PBKDF2 100.000 iterasi ±0,3 detik per login di Node; di Apps Script diperkirakan 1–3 detik (diperiksa di daftar periksa
  `docs/DEPLOYMENT.md` §6.4).

## Emulator (`tools/gas-emulator/`)

Emulator adalah test double untuk `SpreadsheetApp`, `PropertiesService`, `LockService`, `CacheService`, `Session`, `Utilities`,
`DriveApp`, `HtmlService`, dan `Logger`. Pemanggilan API yang tidak dikenal langsung gagal. Berkas `.gs` dimuat ke satu konteks `vm` sebagai
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
Migrasi data ke database produksi dan deployment web app juga belum dijalankan. Backend dan UI diuji end-to-end, tetapi di atas
emulator: yang belum terbukti adalah perilaku Apps Script sendiri (identitas `Session` di web app domain, `google.script.history`
di iframe HtmlService, kuota, dan waktu respons Sheets). Setelah `npm run push`, jalankan dari editor Apps Script:

1. `setupDatabase` → log `setupDatabase: {...}`.
2. `runDatabaseSelfTest` → log per kasus (`PASSED`/`FAILED`) dan ringkasan `Self-test: 35/35 lulus`. Spreadsheet uji dibuat sementara
   lalu dipindah ke trash. Database produksi dan Script Properties tidak disentuh.
3. `verifyDatabase` → `ok: true`.
4. Migrasi: langkah di `docs/DEPLOYMENT.md` §7. Hasil `dryRunMigration`, `runMigration`, dan `verifyMigration` harus sama dengan
   `docs/MIGRATION_REPORT.md` (jumlah per tabel, total, dan pemeriksaan).
5. Web app: deploy dan jalankan daftar periksa di `docs/DEPLOYMENT.md` §6.4 dengan akun per role.

Bila self-test melewati batas waktu eksekusi, kasus yang tersisa ditandai `SKIPPED`. Jalankan per kelompok dengan memanggil
`runDatabaseSelfTest('init')`, `('relasi')`, `('validasi')`, dan seterusnya dari fungsi pembungkus sementara.
Kasus yang gagal di Apps Script tetapi lulus di emulator menandakan asumsi emulator yang keliru. Perbaiki emulator dan
`tests/emulator.test.js`, lalu kodenya.
