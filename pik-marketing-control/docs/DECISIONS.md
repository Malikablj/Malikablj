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
| D5 | Hak akses modul yang tidak diatur spesifikasi; arti "RW/R" PO untuk Marketing | **OPEN**, default dipasang — **DECISION REQUIRED sebelum dipakai pengguna** | Matriks `MODULE_PERMISSIONS` (`src/auth/Auth.gs`, tabel di `docs/API.md` §3), ditegakkan di server. Default: lihat §1.1 |
| D6 | Tanggal hari/bulan tertukar | Default | Tidak ada koreksi otomatis; tanggal disimpan apa adanya. Kandidat tercatat di `MIGRATION_ISSUES` dan tampil di detail record untuk Admin; koreksi lewat form record, lalu isu diselesaikan satu per satu (konfirmasi massal belum ada) |
| D7 | Nomor PO kosong/ganda | Default | `po_number` wajib kecuali legacy; `(customer_id, po_number_key)` unik untuk PO yang tidak `CANCELLED` |
| D8 | Duplikat customer/produk | Default | Kode produk tidak unik; tidak ada penggabungan otomatis. Alat merge Admin dari rencana Phase 01 belum dibuat |
| D9 | Delivery negatif legacy | Default | Qty delivery > 0 untuk data baru; nilai legacy disimpan apa adanya |
| D10 | Makna sheet LEADTIME | Default | `LEADTIME` = jadwal pengiriman terencana; tidak dihitung sebagai qty terkirim |
| D11 | Keuangan legacy | Default | `PO_FINANCIALS` read-only (hanya proses migrasi yang menulis); `INVOICES_PAYMENTS` tabel biasa. Status bayar diturunkan dari nilai legacy: invoice dari outstanding & pembayaran; ringkasan PO dari label LUNAS/BELUM LUNAS, dengan BELUM LUNAS dibedakan menjadi `UNPAID` (outstanding ≥ total) atau `PARTIAL` (sebagian terbayar) |
| D12 | ID dan prefiks | Default | `PREFIX-XXXXXXXXXX` (10 hex); ID workbook dipakai apa adanya; `PAY-` untuk invoice |
| D13 | Status customer hasil default | Default | `status` customer opsional; migrasi mengisi `ACTIVE` |
| D14 | Follow-up today/overdue | Default | `OVERDUE` bukan status tersimpan; dihitung dari tanggal dan status selain `DONE`/`CANCELLED` |

### 1.1 D5 — DECISION REQUIRED: hak akses

Matriks akses spesifikasi tidak mengatur beberapa modul, dan sel PO untuk Marketing tertulis "RW/R". Default yang dipasang dapat
diubah di satu tempat (`MODULE_PERMISSIONS`); test otorisasi membaca matriks yang sama.

| Pertanyaan | Default dipasang | Alternatif yang mungkin |
|---|---|---|
| Invoice & pembayaran (finance) | Admin RW, Management R, role lain tanpa akses. Data keuangan di detail customer/PO dan dashboard juga disembunyikan | Marketing/Sales boleh melihat status bayar |
| Lead time dan inbound maklon | Admin RW, role lain R | Marketing RW untuk jadwal lead time |
| Isu migrasi, pengaturan, user, audit log | Hanya Admin | Management boleh melihat audit log |
| "RW/R" PO untuk Marketing | RW: Marketing boleh membuat dan mengubah PO serta itemnya | R: hanya Admin yang menulis PO |
| Pembatasan data per pemilik | Tidak ada: setiap role yang boleh membaca modul melihat semua record | Sales hanya melihat customer/lead miliknya |

Delivery, retur, produk, dan stok mengikuti spesifikasi: Admin RW, role lain R.

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

## 4. Keputusan teknis Phase 04 (backend)

| ID | Keputusan | Alasan |
|---|---|---|
| P4-01 | Satu fungsi server `api(action, payload)` dengan tabel route tertutup. Setiap route membawa izin `[modul, R\|RW]` yang dicek sebelum handler berjalan. | Satu pintu masuk: izin tidak dapat terlewat per fungsi. Test mencocokkan setiap route × role dengan matriks. |
| P4-02 | Login = akun Google (web app *execute as* deployer, akses domain) dicocokkan dengan `USERS.email`; role selalu dari `USERS`. Admin pertama = pemilik skrip saat `USERS` belum punya Admin aktif (di bawah lock, diaudit). | Tanpa password tersimpan (P2-06); aplikasi dapat disiapkan tanpa mengedit sheet. |
| P4-03 | Izin ditegakkan di server; UI hanya menyembunyikan tombol. Data keuangan di layar bersama hanya dikirim ke role dengan akses `finance`. | UI dapat dilewati; data sensitif tidak boleh sampai ke browser role yang tidak berhak. |
| P4-04 | Nilai turunan dihitung saat dibaca, tidak disimpan. Terkirim = delivery `DELIVERED` atau tanpa status (legacy); `SCHEDULED`/`ON_DELIVERY`/`DELAYED` = terjadwal; retur dihitung kecuali `CANCELLED`; outstanding = MAX(0, order − terkirim + retur); status bayar invoice dari nilai. | Angka tidak dapat basi atau diketik tangan (Technical Spec §7). |
| P4-05 | Qty di atas batas (delivery > sisa, retur > terkirim bersih, qty order < terkirim bersih) ditolak `OVER_QUANTITY` + `confirmable`. Qty itu hanya disimpan bila klien mengirim ulang dengan `confirmOverQuantity: true`. | Kelebihan kirim memang terjadi (terlihat di data legacy), tetapi tidak boleh terjadi tanpa sengaja. |
| P4-06 | Delivery baru wajib item PO; PO dan produk diambil dari item. Aktivitas dan follow-up wajib customer (boleh diturunkan dari lead, contact, atau aktivitas). | Relasi tidak dapat saling bertentangan; outstanding per item selalu dapat dihitung. |
| P4-07 | Operasi multi-record memvalidasi semua record sebelum penulisan pertama (`dbValidateInsert_`, `dbUpdateMany_`, `dbInsertUnit_`). | Apps Script tidak punya transaksi; kegagalan di tengah tidak boleh meninggalkan data setengah jadi. |
| P4-08 | Saat script lock didapat, cache tabel dibuang. Update membawa `expectedUpdatedAt`: record yang sudah berubah → `CONFLICT`. | Pemeriksaan (sisa qty, keunikan, contact utama) harus melihat data terbaru, bukan data sebelum menunggu lock. |
| P4-09 | Filter tanggal pada kolom waktu memakai tanggal kalender zona aplikasi (Asia/Jakarta). | Waktu disimpan UTC (P2-01); aktivitas pukul 06.00 WIB tidak boleh jatuh ke hari sebelumnya. |
| P4-10 | Parameter daftar ketat: filter atau kolom urut yang tidak dikenal ditolak. Nilai kosong selalu di akhir urutan, naik maupun turun. | Bug klien tidak boleh diam-diam menampilkan data yang salah. Banyak tanggal legacy kosong; sebelumnya muncul paling atas pada urutan terbaru. |
| P4-11 | Laporan mengirim ≤ 500 baris dan hanya kolom yang ditampilkan. CSV: UTF-8 dengan BOM; sel yang diawali `=`, `+`, `-`, `@`, tab, atau CR (kecuali angka) diberi apostrof. | Payload `google.script.run` pada data asli turun ±70%; CSV aman dibuka di Excel. |
| P4-12 | Riwayat record (`audit.history`) untuk siapa pun yang boleh membaca modulnya, termasuk batch `MIGRATION_RUN` yang membuat record. Audit log penuh hanya untuk Admin. | Jejak perubahan terlihat di tempat kerja tanpa membuka seluruh log. |
| P4-13 | Pengaman Admin: tidak dapat menonaktifkan akun sendiri atau mengubah email sendiri; Admin aktif terakhir tidak dapat dinonaktifkan atau diganti role-nya. | Aplikasi tidak boleh terkunci tanpa Admin. |
| P4-14 | Nilai enum baru hanya untuk enum yang dapat diperluas (`ACTIVITY_TYPE`); nilai seed tidak dapat dinonaktifkan; dropdown sheet ikut diperbarui. | Kode bergantung pada nilai seed; jenis aktivitas memang berbeda per tim. |
| P4-15 | Menyelesaikan isu migrasi wajib catatan (kecuali membuka ulang); `resolved_by`/`resolved_at` diisi server. | Keputusan atas data legacy harus dapat ditelusuri. |
| P4-16 | PO dengan delivery/retur aktif tidak dapat diarsipkan (gunakan status Cancelled). Item dengan transaksi aktif tidak dapat diarsipkan atau diganti produknya. | Arsip tidak boleh membuat transaksi yatim atau mengubah outstanding diam-diam. |

## 5. Keputusan teknis Phase 05 (frontend)

| ID | Keputusan | Alasan |
|---|---|---|
| P5-01 | Vanilla JS tanpa library dan tanpa build step, dipecah per berkas (`JsCore`, `JsComponents`, `JsForms`, `JsViews*`, `JsApp`) yang digabung `include_`. | Stack D1; HtmlService tidak punya bundler; berkas kecil lebih mudah ditinjau. |
| P5-02 | DOM hanya dibuat lewat `PIK.h()` (teks lewat `textContent`); ikon SVG dengan `createElementNS`. | Mencegah XSS dari data (nama, catatan) tanpa escaping manual. Diuji otomatis. |
| P5-03 | Router hash lewat `google.script.history` (fallback `location.hash`). Filter, urutan, halaman, tab, dan tampilan disimpan di query URL. | Back/Forward browser berfungsi di dalam iframe Apps Script; state bertahan saat muat ulang dan dapat dibagikan. |
| P5-04 | Layar Masuk menampilkan akun Google yang terdeteksi dan tombol Masuk; tidak ada form password. Keluar menutup sesi aplikasi, bukan akun Google. | Autentikasi dilakukan Google (P4-02); layar ini memperjelas akun yang dipakai. |
| P5-05 | Design tokens: latar #F5F5F7, permukaan putih, teks #111, muted #6E6E73, border #D2D2D7, radius 8/12/18, bayangan halus, satu warna aksen #0A5BC4. | Gaya minimalis Apple-inspired sesuai instruksi. Warna brand PIK belum diketahui; aksen diganti di satu token. |
| P5-06 | Grafik batang dengan CSS (tanpa library): satu seri = warna aksen, label langsung, tooltip saat hover/fokus, nilai nol tanpa batang. | Ringan, dapat diakses keyboard, konsisten dengan design system. |
| P5-07 | Input angka menerima format Indonesia (`1.500.000`, `2,5`) dan dikirim sebagai number. Server tetap menolak teks angka (P2-13). | Mengikuti kebiasaan pengguna; konversi eksplisit di klien, bukan tebakan di server. |
| P5-08 | Ponsel: navigasi bawah (Dashboard, Customer, Lead, Follow-up, Menu), tabel menjadi kartu, filter di balik tombol "Filter (n)", form sebagai bottom sheet. Tablet: sidebar menjadi rel ikon. | Alur lapangan Sales/Marketing di ponsel, tanpa scroll ke samping. |
| P5-09 | Pipeline: drag & drop antar tahap, dengan menu "Pindah ke" sebagai alternatif keyboard dan layar sentuh. | Aksesibilitas; drag & drop tidak nyaman di layar sentuh. |
| P5-10 | Arsip memakai tombol Urungkan di toast; perubahan berisiko lewat dialog konfirmasi. | Soft delete (P2-04) dengan pemulihan cepat bila salah klik. |
| P5-11 | Record legacy bertanda "Legacy". Detail record legacy menampilkan isu migrasi terbuka (Admin) dan transaksi yang belum tertaut. Item PO legacy menampilkan outstanding workbook bila berbeda dari hitungan (D3). | Admin dapat merekonsiliasi dari tempat kerja; nilai legacy hanya pembanding, tidak dipakai menghitung. |

## 6. Keputusan teknis Phase 06 (pengujian terintegrasi)

| ID | Keputusan | Alasan |
|---|---|---|
| P6-01 | Dev server lokal (`tools/dev-server`) menjalankan kode `.gs` dan HTML asli di emulator. `google.script.run` diganti shim yang memanggil fungsi server yang sama lewat HTTP; identitas dari akun uji. | Kredensial Google tidak tersedia di environment pengembangan; UI tetap diuji terhadap backend sungguhan, bukan mock. |
| P6-02 | Data E2E = workbook sintetis yang dimigrasikan lewat pipeline Phase 03, ditambah akun uji per role. | Tidak ada data rahasia di repo; migrasi, backend, dan UI teruji bersama. |
| P6-03 | Playwright 1.56.1 sebagai devDependency; `npm run test:e2e` terpisah dari `npm test`. | `npm test` tetap tanpa dependency dan cepat; E2E butuh Chromium. |
| P6-04 | Setelah aksi di UI, test E2E memeriksa hasilnya lewat API, bukan hanya teks di layar. | Membuktikan data benar-benar tersimpan dan dihitung di server. |
