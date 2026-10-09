# PIK Marketing Control

Aplikasi web internal **PT Permata Indo Kemas** untuk mengelola customer, CRM (lead, aktivitas, follow up), Order Entry Form, delivery (surat jalan), complaint & retur, stok, lead time, inbound maklon & supplier, laporan, dan notifikasi — satu tempat yang menggantikan spreadsheet AppSheet. Invoice & pembayaran dikelola divisi Keuangan di luar aplikasi ini.

- **Stack:** PHP 8 native (MVC sederhana, tanpa framework), MySQL/MariaDB via PDO, HTML5 + CSS3 + JavaScript, Bootstrap 5 (file lokal), Apache.
- **Tanpa dependensi eksternal:** tidak perlu Composer/Node. Semua library (Bootstrap, Bootstrap Icons, font) ikut di `public/assets/vendor`.
- **Sumber kebenaran:** `PIK_Marketing_Control_PRD.pdf` (kebutuhan) dan `PIK_Master_Database_AppSheet.xlsx` (struktur & data awal).

> Repository ini **public**. Data perusahaan (workbook, hasil export, dump SQL) dan kredensial (`.env`) **tidak pernah di-commit** — lihat `.gitignore`.

---

## Daftar isi

1. [Kebutuhan server](#1-kebutuhan-server)
2. [Instalasi cepat](#2-instalasi-cepat)
3. [Konfigurasi (.env)](#3-konfigurasi-env)
4. [Setup database](#4-setup-database)
5. [Import data awal (migrasi)](#5-import-data-awal-migrasi)
6. [Seed & login development](#6-seed--login-development)
7. [Menjalankan test](#7-menjalankan-test)
8. [Deploy ke Apache](#8-deploy-ke-apache)
9. [Otomasi & notifikasi (cron)](#9-otomasi--notifikasi-cron)
10. [Backup & restore](#10-backup--restore)
11. [Hak akses per role](#11-hak-akses-per-role)
12. [Aturan bisnis](#12-aturan-bisnis)
13. [Temuan data migrasi](#13-temuan-data-migrasi)
14. [Struktur folder](#14-struktur-folder)
15. [Keamanan](#15-keamanan)
16. [Troubleshooting](#16-troubleshooting)
17. [Order Entry Form & Complaint (update Oktober 2026)](#17-order-entry-form--complaint-update-oktober-2026)
18. [Pembagian tugas per divisi, Stock & Inbound Supplier (update Oktober 2026 — 2)](#18-pembagian-tugas-per-divisi-stock--inbound-supplier-update-oktober-2026--2)
19. [Konfirmasi PPIC massal, hapus user & buka blokir (update Oktober 2026 — 3)](#19-konfirmasi-ppic-massal-hapus-user--buka-blokir-update-oktober-2026--3)
20. [Hapus OEF massal & perbaikan tampilan angka besar (update Oktober 2026 — 4)](#20-hapus-oef-massal--perbaikan-tampilan-angka-besar-update-oktober-2026--4)

---

## 1. Kebutuhan server

| Komponen | Versi | Catatan |
|---|---|---|
| PHP | **8.1 atau lebih baru** | Diuji di PHP 8.3 (Apache mod_php) dan 8.4 (CLI). |
| Ekstensi PHP | `pdo_mysql`, `mbstring`, `zip`, `xmlreader`, `json` | `zip` + `xmlreader` untuk membaca/menulis Excel (.xlsx). `curl` hanya untuk test. |
| Database | MySQL 8.0+ atau MariaDB 10.4+ | Diuji di MariaDB 10.11 (MySQL 8 belum diuji langsung). Charset `utf8mb4`. |
| Web server | Apache 2.4 + `mod_rewrite` (+ `mod_headers` opsional) | Nginx kemungkinan bisa (arahkan semua request ke `public/index.php`), tetapi belum diuji. |
| Upload | `upload_max_filesize` ≥ 2M | Workbook master ± 300 KB, file legacy ≤ 1 MB. |

Cek ekstensi: `php -m | grep -Ei "pdo_mysql|mbstring|zip|xmlreader"`

## 2. Instalasi cepat

```bash
# 1) Salin folder proyek ke server, mis. /var/www/pik-marketing-control
cd /var/www/pik-marketing-control

# 2) Buat database & user MySQL (sekali saja, sebagai root MySQL)
mysql -u root -p <<'SQL'
CREATE DATABASE pik_marketing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'pik_app'@'localhost' IDENTIFIED BY 'GANTI_PASSWORD_KUAT';
GRANT ALL PRIVILEGES ON pik_marketing.* TO 'pik_app'@'localhost';
FLUSH PRIVILEGES;
SQL

# 3) Konfigurasi
cp .env.example .env
nano .env                      # isi DB_PASSWORD, APP_ENV=production, dll.

# 4) Buat tabel + pengaturan awal
php database/install.php

# 5) Folder storage harus bisa ditulis web server
chown -R www-data:www-data storage
chmod -R 770 storage

# 6) Buka aplikasi di browser → otomatis diarahkan ke /setup untuk membuat akun Admin pertama
#    (atau lewat CLI: php database/create_admin.php)

# 7) Import data awal: menu Settings › Import Data (atau CLI, lihat bagian 5)
```

Setelah Admin pertama dibuat, halaman `/setup` otomatis tertutup. Buat akun tim lain di **Settings › Users** (password sementara; user wajib menggantinya saat login pertama).

## 3. Konfigurasi (.env)

File `.env` berisi kredensial — **jangan di-commit / dibagikan**. Semua opsi ada di `.env.example`:

| Variabel | Contoh | Keterangan |
|---|---|---|
| `APP_ENV` | `production` | `production` / `development`. Default aman: production. |
| `APP_DEBUG` | `false` | `true` hanya saat development (menampilkan detail error). |
| `APP_TIMEZONE` | `Asia/Jakarta` | Zona waktu aplikasi. |
| `APP_BASE_PATH` | *(kosong)* | Kosong = deteksi otomatis (root domain atau subfolder). Isi `/pik` bila deteksi gagal. |
| `APP_PRETTY_URLS` | `true` | `false` bila server tanpa `mod_rewrite` (URL menjadi `/index.php/customers`). |
| `FORCE_HTTPS` | `false` | `true` bila situs memakai HTTPS (redirect http→https + HSTS). |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | | Koneksi MySQL/MariaDB. |
| `DB_SOCKET` | *(kosong)* | Path unix socket bila tidak memakai host/port. |
| `DB_TIMEZONE` | `+07:00` | Samakan dengan `APP_TIMEZONE`. |
| `SESSION_IDLE_MINUTES` | `120` | Logout otomatis setelah tidak aktif. |
| `SESSION_ABSOLUTE_HOURS` | `12` | Batas maksimal umur sesi. |

Pengaturan bisnis (nama perusahaan, pengingat delivery, interval otomasi, email QC) diubah Admin di **Settings › Pengaturan**, bukan di `.env`.

## 4. Setup database

- `database/schema.sql` — seluruh tabel (users, customers, contacts, products, purchase_orders, po_lines, deliveries, returns, stock, leadtime, inbound_maklon, inbound_supplier, invoices_payments & po_financials (data lama, tanpa menu), leads, activities, follow_up + notifications, audit_logs, migration_issues, settings, login_attempts). Aman dijalankan ulang (`CREATE TABLE IF NOT EXISTS`).
- `database/seed.sql` — **hanya** pengaturan awal (nama perusahaan, jatuh tempo default 30 hari, PPN 11%, dll.). Tidak berisi data bisnis maupun akun.
- `php database/install.php` menjalankan keduanya memakai kredensial `.env`.
- Tanpa akses SSH (mis. hosting dengan phpMyAdmin): impor `schema.sql` lalu `seed.sql` lewat tab *Import* phpMyAdmin.

## 5. Import data awal (migrasi)

Sumber: `PIK_Master_Database_AppSheet.xlsx`. File spreadsheet asli (legacy) bersifat opsional dan dipakai untuk **memverifikasi** tanggal & angka di master.

**Lewat browser (Admin):** Settings › **Import Data** → pilih master (+ file legacy) → **Cek dulu** (tidak menyimpan apa pun, menampilkan laporan pemetaan) → **Import**. Import lewat web hanya untuk database kosong.

**Lewat command line:**

```bash
# cek dulu tanpa menyimpan
php database/import_workbook.php --master="/path/PIK_Master_Database_AppSheet.xlsx" --dry-run

# import ke database kosong, dengan verifikasi file legacy
php database/import_workbook.php --master="/path/PIK_Master_Database_AppSheet.xlsx" \
    --legacy="/path/PIK X PT SCL.xlsx" --legacy="/path/PIK X ALL CUSTOMER.xlsx" \
    --legacy="/path/PIK x Scora x Facetology - RECAP PURCHASE DELIVERY PAYMENT RECAP.xlsx"

# tanpa akses database langsung: hasilkan file SQL untuk phpMyAdmin (jalankan setelah schema.sql)
php database/import_workbook.php --master=... --legacy=... --sql-out=storage/exports/data_migrasi.sql

# mengganti SELURUH data bisnis (user, settings, audit log tetap) — meminta konfirmasi "HAPUS"
php database/import_workbook.php --master=... --legacy=... --fresh
```

Prinsip importer:
- ID bisnis dari workbook (mis. `CUS-894EA1DA5F`) dipertahankan di kolom `code`.
- Relasi hanya dibuat dari ID atau kecocokan **persis** (nomor PO unik, kode produk unik) — **tidak ada fuzzy matching**.
- Record yang tidak aman dipetakan **tetap disimpan** dan ditandai sebagai **migration issue** untuk ditinjau.
- Koreksi dari file legacy hanya diterapkan bila ada **bukti independen** (bulan pada nomor dokumen, urutan nomor surat jalan, tanggal bayar ≥ tanggal invoice, qty × harga ≈ total). Selain itu nilai master dipertahankan dan perbedaannya dicatat sebagai issue "Needs Review".
- Seluruh data ditulis dalam satu transaksi: bila gagal, tidak ada yang tersimpan.

Setelah import, tinjau **Settings › Migration Issues**:
- Filter per status / sheet / jenis; tandai **Selesai** atau **Abaikan** (satu per satu atau massal) dengan catatan.
- Issue tanggal/angka: pilih **Pakai nilai master** atau **Pakai nilai spreadsheet legacy** — kolom record diperbarui dan tercatat di audit log.
- **Tinjau delivery legacy**: delivery tanpa baris PO yang PO-nya hanya punya satu baris ditampilkan berdampingan (nama produk di spreadsheet vs produk baris PO). Hanya yang dicentang yang dihubungkan; outstanding & status PO dihitung ulang.
- Banyak issue selesai otomatis saat datanya dilengkapi (mis. menghubungkan delivery/retur/stok/lead time ke PO/produk, mengisi jatuh tempo invoice).

## 6. Seed & login development

Untuk komputer developer (bukan server produksi):

```bash
cp .env.example .env    # set APP_ENV=development, APP_DEBUG=true, kredensial DB lokal
php database/install.php
php database/seed_dev_users.php          # 1 user per role
php -S 127.0.0.1:8080 -t public public/index.php
```

| Email | Role |
|---|---|
| admin@pik.test | Admin |
| marketing@pik.test | Marketing |
| sales@pik.test | Sales |
| management@pik.test | Management |
| ppic@pik.test | PPIC |
| produksi@pik.test | Produksi |
| gudang@pik.test | Gudang |
| viewer@pik.test | Viewer |

Password semua akun: nilai variabel `DEV_PASSWORD`, atau default `PikDev2026!`. Script hanya mau berjalan bila `APP_ENV` = `development`, `local`, atau `testing`.

## 7. Menjalankan test

Test berjalan pada database terpisah yang **wajib** berakhiran `_test` (dikosongkan setiap kali test dijalankan):

```bash
mysql -u root -p -e "CREATE DATABASE pik_marketing_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  GRANT ALL PRIVILEGES ON pik_marketing_test.* TO 'pik_app'@'localhost';"

php tests/run.php              # semua test (±120 test, ±40 detik)
php tests/run.php "Phase 4"    # hanya grup tertentu
```

Test menjalankan server PHP bawaan dan menguji lewat HTTP sungguhan: CRUD, validasi, otorisasi per role, relasi, perhitungan outstanding/invoice, laporan & export, otomasi, import, serta pengujian keamanan (XSS di semua halaman, SQL injection, CSRF, cookie). Opsional, menguji import workbook asli (beberapa file legacy dipisah titik koma):

```bash
PIK_REAL_WORKBOOK="/path/master.xlsx" PIK_REAL_LEGACY="/path/legacy1.xlsx;/path/legacy2.xlsx" php tests/run.php "Workbook asli"
```

## 8. Deploy ke Apache

Aktifkan modul: `a2enmod rewrite headers && systemctl restart apache2`. Diuji pada tiga cara deploy:

**A. Disarankan — DocumentRoot ke folder `public/`**

```apache
<VirtualHost *:80>
    ServerName pik.perusahaan.co.id
    DocumentRoot /var/www/pik-marketing-control/public
    <Directory /var/www/pik-marketing-control/public>
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>
    ErrorLog ${APACHE_LOG_DIR}/pik-error.log
    CustomLog ${APACHE_LOG_DIR}/pik-access.log combined
</VirtualHost>
```

Folder `app/`, `config/`, `database/`, `storage/`, `.env` berada di luar document root sehingga tidak dapat diakses dari browser.

**B. Hosting bersama — seluruh proyek di document root** (mis. `public_html/`). `.htaccess` di root proyek meneruskan request ke `public/` dan memblokir `app/`, `config/`, `database/`, `storage/`, `tests/`, `cron/`, `README.md`, serta file tersembunyi (`.env`, `.git`).

**C. Subfolder** — mis. `https://domain.co.id/pik-marketing-control/`. Sama seperti B; base path terdeteksi otomatis (atau isi `APP_BASE_PATH=/pik-marketing-control`).

Wajib `AllowOverride All` agar `.htaccess` aktif. Setelah deploy, cek bahwa `https://domain/.env` dan `https://domain/config/database.php` menghasilkan 403/404.

**HTTPS:** pasang sertifikat (mis. Certbot/Let's Encrypt), lalu set `FORCE_HTTPS=true` — cookie sesi otomatis `Secure` dan header HSTS dikirim.

## 9. Otomasi & notifikasi (cron)

Otomasi menandai follow up yang lewat jatuh tempo (Overdue) dan mengirim notifikasi (tanpa duplikat) kepada user yang berhak:

| Notifikasi | Penerima |
|---|---|
| Follow up hari ini / terlewat | PIC follow up (bila pengingat aktif) |
| Delivery terjadwal dalam N hari (Settings) | Semua user **PPIC** (pengisi surat jalan) + PIC marketing customer / pembuat delivery (atau semua user Marketing) |
| OEF baru / revisi menunggu konfirmasi | User PPIC (atau Admin bila belum ada PPIC) |
| Hasil konfirmasi PPIC | Pembuat OEF & sales |
| User terblokir (salah password 5x) | Semua user **Admin** aktif (langsung saat terjadi) |
| Target closing lead ≤ 3 hari / terlewat | PIC lead |

Otomasi berjalan sendiri saat aplikasi dipakai (maksimal sekali per interval, default 60 menit) dan bisa dijalankan manual di Settings › Pengaturan. Untuk server yang tidak selalu dibuka, tambahkan cron:

```cron
*/15 * * * * php /var/www/pik-marketing-control/cron/automation.php >> /var/www/pik-marketing-control/storage/logs/cron.log 2>&1
```

## 10. Backup & restore

```bash
# backup harian (mis. cron 01:30) — simpan di luar server & jangan di-commit
mysqldump -u pik_app -p --single-transaction --routines --triggers pik_marketing \
  | gzip > /backup/pik_marketing_$(date +%F).sql.gz

# restore
gunzip < /backup/pik_marketing_2026-09-30.sql.gz | mysql -u pik_app -p pik_marketing
```

Contoh cron dengan kredensial di `~/.my.cnf` (chmod 600):

```cron
30 1 * * * mysqldump --single-transaction pik_marketing | gzip > /backup/pik_$(date +\%F).sql.gz && find /backup -name 'pik_*.sql.gz' -mtime +30 -delete
```

Yang perlu dibackup: **database** dan file **`.env`** (simpan terpisah dan aman). Folder `storage/logs` boleh dirotasi/dihapus berkala. File upload import dihapus otomatis setelah diproses.

## 11. Hak akses per role

Otorisasi dicek di **backend** pada setiap route (lihat `app/routes.php` + `config/permissions.php`); menu & tombol hanya disembunyikan sebagai kenyamanan tampilan.

| Modul | Admin | Marketing | Sales | PPIC | Produksi | Gudang | Management | Viewer |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Dashboard | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| Customers & Contacts | ✔ | ✔ | ✔ | — | — | — | ✔ | lihat |
| Leads, Activities, Follow Up | ✔ | ✔ | ✔ | — | — | — | — | lihat |
| Order Entry Form (buat / edit) | ✔ | ✔ | buat & edit | lihat | — | — | ✔ | lihat |
| Hapus OEF massal (centang / pilih semua di daftar) | ✔ | — | — | — | — | — | — | — |
| Konfirmasi PPIC (Bisa / Tidak bisa diproses, satu per satu atau massal) | ✔ | — | — | ✔ | — | — | — | — |
| Deliveries / Surat Jalan (catat, edit, ubah jadwal) | ✔ | lihat | lihat | ✔ | — | — | lihat | lihat |
| Complaint & Return | ✔ | ✔ | lihat & catat | — | — | — | ✔ | lihat |
| Tombol Selesai / Tidak selesai complaint | ✔ | ✔ | — | — | — | — | ✔ | — |
| Products | ✔ | ✔ | lihat | — | — | — | — | lihat |
| Stock | ✔ | — | — | — | ✔ | ✔ | lihat | lihat |
| Inbound Maklon | ✔ | — | — | — | — | ✔ | lihat | lihat |
| Inbound Supplier | ✔ | — | — | — | — | ✔ | lihat | lihat |
| Lead Time | ✔ | ✔ | — | — | — | — | ✔ | lihat |
| Reports | ✔ | — | — | — | — | — | semua + export | tanpa export |
| Users (termasuk hapus user & buka blokir login), Settings, Import, Migration Issues, Audit Log | ✔ | — | — | — | — | — | — | — |

Catatan (bisa diubah di `config/permissions.php`):
- **Surat Jalan hanya diisi PPIC** (Admin tetap bisa untuk koreksi data). Jadwal delivery otomatis dari OEF tetap dibuat sistem saat Sales/Marketing menyimpan OEF.
- **PPIC** hanya meninjau OEF (Bisa / Tidak bisa diproses) dan mengelola menu Deliveries; nama customer tetap terlihat di halaman order, tanpa akses ke menu Customers.
- **Produksi & Gudang** mengisi Stock; **Gudang** juga mengisi Inbound Maklon & Inbound Supplier. Management & Viewer hanya melihat.
- Menu **Finance** (Invoice & Payment, PO Financials) dan laporan Financial sudah dihapus — ranah divisi Keuangan.

## 12. Aturan bisnis

- **Outstanding Quantity = Order Quantity − Delivered Quantity + Return Quantity** (per baris PO). Delivered hanya menghitung delivery berstatus *Delivered*/*Partial*. Nilai negatif = kelebihan kirim (harus dikonfirmasi saat input).
- **Status PO otomatis:** Open/On Process → *Partial* saat ada kiriman; → *Closed* saat semua baris outstanding ≤ 0. Status *Closed* dan *Cancelled* tidak pernah diubah otomatis. Setiap perubahan otomatis tercatat di audit log.
- **Follow up overdue:** tanggal < hari ini dan status bukan *Done* (status *Cancelled* juga dianggap selesai).
- **Order Entry Form:** No. order, nama customer, dan nama produk diketik manual. No. order wajib unik (tidak peka huruf besar/kecil). Customer & produk dicocokkan dengan nama yang sama (tidak peka huruf besar/kecil & spasi; customer juga "PT." = "PT"); bila belum ada, dicatat otomatis di menu Customers / Products. Qty & spesifikasi OEF terakhir disimpan di produk sebagai arsip.
- **Stok:** nama produk diketik manual dan dikelompokkan otomatis per produk (nama sama = kelompok sama; nama baru = produk baru bersumber "Stok"). Qty = jumlah box × isi per box bila Qty dikosongkan; Qty yang berbeda harus dikonfirmasi. Entri tanpa qty ditandai, tidak dihitung sebagai 0.
- **Lead time:** estimasi terbuka yang tanggalnya lewat ditandai *Terlambat*.
- **Inbound maklon:** total masuk = qty diterima − qty reject. No. order/PO & nama barang diketik manual; terhubung otomatis bila sama persis dengan No. order/No. PO customer atau nama produk yang ada.
- **Inbound supplier:** total masuk = qty diterima − qty reject (qty boleh desimal, mis. kg); rekap per barang = nama barang (tidak peka huruf besar/kecil) + satuan.
- **Data keuangan lama** (invoice & PO financials hasil migrasi) tetap tersimpan di database tanpa menu, dan tetap mencegah order terkait terhapus.
- Uang dihitung dalam sen (integer) agar tidak ada selisih pembulatan; input menerima format Indonesia (`12.500.000` atau `1.250.000,50`).

## 13. Temuan data migrasi

Hasil import workbook asli (44 customer, 262 produk, 365 PO, 407 baris PO, 1.526 delivery, 6 retur, 54 stok, 9 lead time, 44 inbound, 92 invoice, 29 PO financial) — 924 catatan migrasi, di antaranya:

- **Tanggal hari/bulan tertukar** di master (mis. 2024-01-04 vs 2024-04-01). 108 tanggal dikoreksi otomatis karena didukung bukti; 23 kasus master terbukti benar; 27 perlu ditinjau manual.
- **Tanggal bayar di file legacy** banyak yang lebih awal dari tanggal invoice (format tanggal Excel rusak), sehingga tidak dipakai tanpa bukti.
- **Harga satuan 1.000×** pada beberapa baris PO Financials (angka teks format Indonesia terbaca salah) — 12 angka dikoreksi (10 harga satuan, 1 PPN, 1 total) karena qty × harga cocok dengan total.
- **614 delivery belum terhubung ke baris PO** (537 produk tidak cocok persis, 77 PO tidak ditemukan). 435 di antaranya berada di PO satu baris dan bisa ditinjau cepat di halaman *Tinjau delivery legacy*. Sampai dihubungkan, delivery ini belum mengurangi outstanding.
- PO tanpa nomor (36) / tanpa tanggal (37), nomor PO ganda (4), kemungkinan customer (3) & produk (7) ganda.
- Stok: 9 baris judul kolom spreadsheet, 35 nama barang belum cocok ke master produk.
- Seluruh 92 invoice tidak memiliki jatuh tempo; 1 invoice belum lunas ditandai untuk dilengkapi. *(Menu Finance kini dihapus; issue invoice/PO financial tetap bisa ditinjau di Migration Issues tanpa link ke halaman record.)*

## 14. Struktur folder

```
pik-marketing-control/
├── .htaccess               # fallback bila proyek di document root / subfolder
├── .env.example            # contoh konfigurasi (salin ke .env)
├── app/
│   ├── bootstrap.php       # autoloader, .env, zona waktu, error handling
│   ├── routes.php          # semua URL + permission yang wajib dimiliki
│   ├── controllers/        # satu controller per modul
│   ├── models/             # akses data + aturan bisnis (PDO prepared statements)
│   ├── services/           # dashboard, laporan, otomasi, importer workbook
│   ├── helpers/            # Router, Auth, Session, Csrf, Validator, Database, Xlsx, ...
│   └── views/              # template PHP (layout, halaman per modul)
├── config/
│   ├── app.php             # konfigurasi aplikasi (dibaca dari .env)
│   ├── database.php        # koneksi database (dibaca dari .env)
│   └── permissions.php     # matriks hak akses per role
├── cron/automation.php     # otomasi notifikasi (untuk crontab)
├── database/
│   ├── schema.sql          # struktur tabel
│   ├── seed.sql            # pengaturan awal
│   ├── install.php         # jalankan schema + seed
│   ├── create_admin.php    # buat Admin dari CLI
│   ├── seed_dev_users.php  # user contoh per role (development)
│   └── import_workbook.php # import data dari workbook
├── public/                 # satu-satunya folder yang perlu diakses browser
│   ├── index.php           # front controller
│   ├── css/app.css
│   ├── js/app.js
│   └── assets/             # Bootstrap, ikon, font, gambar (lokal)
├── storage/                # log, file import sementara (tidak di-commit)
└── tests/                  # test runner + skenario per fase
```

## 15. Keamanan

- Password disimpan dengan `password_hash` (bcrypt) dan diverifikasi `password_verify`; kebijakan minimal 8 karakter berisi huruf & angka; password sementara wajib diganti.
- Login dibatasi (5 percobaan gagal per email / 20 per IP dalam 15 menit); Admin mendapat notifikasi saat user terblokir dan bisa membuka blokir di Settings › Users; sesi diganti saat login (anti session fixation); cookie `HttpOnly`, `SameSite=Lax`, `Secure` di HTTPS; timeout idle & absolut.
- Semua form POST memakai token **CSRF**; semua query memakai **PDO prepared statements**; kolom sort memakai whitelist.
- Semua output di-escape; link eksternal hanya `http/https`; Content-Security-Policy tanpa inline script, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`.
- Export CSV dilindungi dari formula injection; setiap export, import, perubahan data, dan login tercatat di **Audit Log**.
- Upload import divalidasi (ekstensi .xlsx + signature zip + ukuran), disimpan di luar document root, dan dihapus setelah diproses.
- Detail error hanya tampil bila `APP_DEBUG=true`; di production error dicatat ke `storage/logs/`.

## 16. Troubleshooting

| Gejala | Penyebab & solusi |
|---|---|
| Halaman 503 "Tidak dapat terhubung ke database" | Cek `DB_*` di `.env`, pastikan MySQL berjalan dan `schema.sql` sudah diimpor (`php database/install.php`). |
| Semua halaman 404 kecuali beranda | `mod_rewrite` belum aktif atau `AllowOverride All` belum diset. Alternatif: `APP_PRETTY_URLS=false`. |
| Tampilan tanpa CSS / link salah di subfolder | Isi `APP_BASE_PATH=/nama-subfolder` di `.env`. |
| "Sesi berakhir / 419" saat submit form | Halaman terbuka terlalu lama; muat ulang halaman lalu kirim lagi. |
| "Terlalu banyak percobaan login" | Tunggu 15 menit, atau Admin mereset password user di Settings › Users. |
| Upload import gagal "terlalu besar" | Naikkan `upload_max_filesize` & `post_max_size` di `php.ini`, restart Apache. |
| Error menulis log / import | Pastikan `storage/` dapat ditulis user web server (`chown -R www-data storage`). |
| Notifikasi tidak muncul | Cek Settings › Pengaturan (waktu otomasi terakhir), tombol *Jalankan sekarang*, atau pasang cron (bagian 9). |
| Angka outstanding PO lama terlihat besar | Delivery legacy belum terhubung ke baris PO — tinjau di Migration Issues › Tinjau delivery legacy. |
| Lupa password Admin | `php database/create_admin.php` untuk membuat Admin baru dari server. |

Log aplikasi: `storage/logs/app-YYYY-MM-DD.log`.

## 17. Order Entry Form & Complaint (update Oktober 2026)

### Update database yang sudah berjalan

Tidak perlu mengimpor ulang data. Setelah file aplikasi diganti, struktur database disesuaikan **otomatis** saat aplikasi pertama kali dibuka (kolom/tabel baru ditambahkan, data lama tidak dihapus). Bisa juga dijalankan manual:

```bash
php database/migrate.php
```

Versi skema tersimpan di tabel `settings` (`schema_version`). Pastikan folder `storage/` dapat ditulis web server karena file bukti complaint disimpan di `storage/uploads/complaints/`.

### Order Entry Form (menggantikan menu Purchase Orders)

Menu **Operations › Order Entry Form** (URL tetap `/purchase-orders`, sehingga link lama tetap berlaku). Isi form:

| Kolom | Keterangan |
|---|---|
| No. order | **Diketik manual** (wajib & unik; lihat bagian 18). |
| Nama sales | Default nama user yang login; bisa diketik. Bila sama dengan nama user aktif, sales tersebut ikut menerima notifikasi hasil PPIC. |
| Nama customer | **Diketik manual**; customer baru dicatat otomatis di menu Customers (lihat bagian 18). |
| No. PO dari customer | Opsional (boleh menyusul), tetap unik bila diisi. |
| Nama produk | **Diketik manual.** Bila nama yang sama (tidak peka huruf besar/kecil & spasi) belum ada, produk baru **dicatat otomatis di menu Products** (sumber `OEF`). |
| Spesifikasi produk | Disimpan di order dan sebagai spesifikasi terakhir di produk. |
| Qty produk | pcs. Ikut tersimpan sebagai **Qty** (arsip) di menu Products. |
| Supplier (jika subcont) | Centang *Dikerjakan subcont* lalu isi supplier (wajib bila subcont). |
| Permintaan selesai / kirim | Membuat **jadwal delivery otomatis** (status *Scheduled*) di menu Deliveries. |
| Tujuan kirim | Kosong = alamat customer (atau nama customer); menjadi tujuan di jadwal delivery. |
| Keterangan | Catatan bebas. |

Alur:

1. Sales/Marketing menyimpan OEF → status PPIC **Menunggu PPIC**; semua user role **PPIC** (atau Admin bila belum ada user PPIC) mendapat notifikasi.
2. PPIC membuka order lalu menekan **Bisa diproses** (hijau → status order *On Process*) atau **Tidak bisa diproses** (merah, **alasan wajib** → status *Cancelled*, jadwal delivery dibatalkan). Pembuat OEF & sales mendapat notifikasi hasilnya.
3. Order yang ditolak bisa direvisi; menyimpan revisi otomatis mengirim ulang ke PPIC dan mengaktifkan kembali jadwal delivery. Mengubah spesifikasi, qty, produk, subcont, atau tanggal permintaan pada order yang sudah dikonfirmasi juga mengirim ulang ke PPIC.
4. **Ubah jadwal** (PPIC): di halaman order (panel *Jadwal delivery › Ubah jadwal*, disertai alasan yang tercatat di catatan delivery) atau langsung edit delivery di menu Deliveries. Tanggal permintaan customer di OEF tetap tersimpan sebagai acuan.
5. Jadwal delivery otomatis tidak mengurangi outstanding sampai statusnya *Delivered/Partial* (aturan lama tetap berlaku). Saat barang dikirim, **PPIC** mengubah jadwal tersebut menjadi *Delivered* dan mengisi nomor surat jalan, atau mencatat delivery baru.

Data PO lama hasil migrasi tetap tampil dengan label **PO lama** (tanpa konfirmasi PPIC) dan tetap bisa dikelola seperti sebelumnya. Order multi-produk tetap didukung lewat *Tambah baris* di halaman detail (nama produk diketik manual).

Role baru **PPIC** dibuat di Settings › Users.

### Complaint & Return (menu Complaint & Retur digabung)

Menu **Operations › Complaint & Return** (URL tetap `/returns`). Setiap catatan adalah complaint customer dengan jenis:

- **Complaint** — keluhan tanpa barang kembali (tidak mengubah outstanding).
- **Complaint + retur barang** — barang dikembalikan; wajib memilih order & produk serta qty retur, dan qty retur **menambah outstanding** (aturan retur lama).

Fitur:

- **Bukti complaint**: unggah beberapa gambar (JPG, PNG, GIF, WEBP) atau PDF, maks. 5 MB per file (mengikuti `upload_max_filesize` bila lebih kecil) dan 10 file per complaint. Isi file diperiksa (bukan hanya ekstensi), disimpan di luar document root, dan hanya bisa dibuka lewat aplikasi oleh user yang berhak.
- **Hasil penanganan**: tombol **Selesai** (hijau, catatan hasil opsional) dan **Tidak selesai** (merah, **alasan wajib**). Complaint bisa *Buka kembali* bila perlu.
- **Email QC**: kolom *Email QC* (bisa lebih dari satu, dipisah koma; default dari Settings). Email dikirim otomatis saat complaint dicatat dan saat ditandai Selesai/Tidak selesai, berisi detail complaint, hasil/alasan, link ke aplikasi, dan bukti sebagai lampiran (total ≤ 8 MB). Status kirim (Terkirim/Gagal + pesan error) tampil di halaman complaint, dengan tombol **Kirim ulang email**.
- **Laporan**: menu **Reports › Complaint & Return** berisi seluruh complaint beserta hasilnya (Selesai / Tidak selesai + alasan / Dalam proses), alasan, qty retur, jumlah bukti, email QC, rata-rata hari penyelesaian, serta rincian per hasil/alasan/customer/produk. Bisa diexport (CSV/Excel) oleh role dengan akses export.

Data retur lama otomatis ditandai *Selesai* (catatan: "Data retur lama") agar tidak muncul sebagai complaint terbuka.

### Pengaturan email (Settings › Pengaturan › Email)

| Pengaturan | Keterangan |
|---|---|
| Email QC default | Terisi otomatis di form complaint baru. |
| Metode kirim | **PHP mail()** (umumnya sudah aktif di hosting cPanel) atau **SMTP** (akun email perusahaan, mis. `mail.permataindokemas.com` port 465 SSL / 587 STARTTLS). |
| Email & nama pengirim | Gunakan alamat di domain sendiri agar tidak masuk spam. |
| SMTP host, port, enkripsi, username, password | Password tidak ditampilkan kembali dan tidak dicatat di audit log; kosongkan untuk mempertahankan password lama. |

Gunakan tombol **Kirim email percobaan** setelah menyimpan. Bila email gagal, complaint tetap tersimpan dan pesan error ditampilkan.

> Catatan keamanan: password SMTP disimpan di tabel `settings` (tidak terenkripsi). Gunakan akun email khusus notifikasi dengan hak terbatas, dan batasi akses database.

## 18. Pembagian tugas per divisi, Stock & Inbound Supplier (update Oktober 2026 — 2)

### Cara update server (berlaku untuk setiap update)

Setiap update dikirim sebagai **`pik-update.zip`** (di repository: folder `update-zip/`).

1. cPanel › **File Manager** → buka folder aplikasi `public_html/marketing.permataindokemas.com` → **Upload** `pik-update.zip` (timpa bila sudah ada). Folder `pik-update/` & zip di sana tidak bisa diakses dari browser (diblokir `.htaccess`).
2. cPanel › **Terminal**, tempel:

```bash
cd ~/public_html/marketing.permataindokemas.com && rm -rf pik-update && unzip -oq pik-update.zip -d pik-update && bash pik-update/pasang-update.sh
```

Pemasang `pasang-update.sh` otomatis: mencari folder aplikasi, **backup file + database** ke `~/pik-backup/`, memeriksa syntax PHP, memasang file (tanpa menyentuh `.env`, `.htaccess` utama, dan `storage/`), menghapus file lama yang tidak dipakai, lalu menjalankan migrasi database. Bila backup gagal, tidak ada file yang diubah.

Mengembalikan file ke versi sebelum update terakhir:

```bash
cd ~/public_html/marketing.permataindokemas.com && bash pik-update/pasang-update.sh --rollback
```

Bila folder aplikasi atau PHP tidak terdeteksi otomatis, tulis misalnya `APP_DIR=/home/USER/marketing.permataindokemas.com` atau `PHP_BIN=/opt/cpanel/ea-php83/root/usr/bin/php` sebelum kata `bash`. Developer membuat zip dengan `bash update-zip/buat-zip.sh` (dari versi yang sudah di-commit).

### Cara update server — manual (tanpa Terminal)

1. **Backup database** (lihat bagian 10) dan simpan `.env` yang sudah ada.
2. Unggah/timpa file aplikasi (folder `app/`, `config/`, `cron/`, `database/`, `public/`, `tests/`, `README.md`). **Jangan menimpa `.env`** dan isi folder `storage/`.
3. Hapus file lama yang tidak dipakai lagi (aman bila tertinggal, karena route-nya sudah tidak ada):
   `app/controllers/InvoiceController.php`, `app/controllers/PoFinancialController.php`, `app/models/Invoice.php`, `app/models/PoFinancial.php`,
   folder `app/views/invoices/`, `app/views/po_financials/`, dan `app/views/customers/tabs/invoices.php`.
4. Buka aplikasi sekali (atau jalankan `php database/migrate.php`). Migrasi skema **2026.10.2** berjalan otomatis:
   - role `Produksi` & `Gudang` ditambahkan ke kolom `users.role`;
   - `purchase_orders.order_number` diperbesar menjadi 60 karakter (No. order diisi manual);
   - kolom `products.qty` ditambahkan dan langsung diisi dengan qty Order Entry Form terakhir setiap produk;
   - tabel `inbound_supplier` dibuat.
   Data lama tidak dihapus (termasuk `capacity_per_day`, invoice, dan PO financials).
5. Buat akun untuk divisi PPIC, Produksi, dan Gudang di **Settings › Users**.

### 1 · Order Entry Form: No. order, customer & produk diketik manual

- **No. order** diisi manual (wajib, maks. 60 karakter, unik — tidak peka huruf besar/kecil & spasi). Form menampilkan No. order terakhir sebagai bantuan.
- **Nama customer** diketik manual (dengan saran nama yang sudah ada). Dicocokkan dengan customer yang namanya sama (tidak peka huruf besar/kecil & spasi, "PT. Abc" = "PT Abc"); bila belum ada, **customer baru dicatat otomatis di menu Customers** (status Active, PIC = sales bila namanya cocok dengan user aktif, catatan "Dicatat otomatis dari Order Entry Form …"). Lengkapi alamat & kontaknya di menu Customers.
- **Nama produk** tetap diketik manual dan dicatat otomatis di menu Products (juga untuk *Tambah baris* di halaman order).
- Menu **Products**: kolom *Kapasitas produksi per hari* diganti **Qty** — arsip qty dari Order Entry Form terakhir (terisi otomatis, bisa diubah manual). Halaman produk juga menampilkan *Total dipesan* dari seluruh order. Nilai kapasitas lama tetap tersimpan di database tetapi tidak ditampilkan.

### 2 · PPIC: tinjau OEF + isi Surat Jalan

- Role **PPIC** hanya: melihat Order Entry Form, menekan **Bisa diproses / Tidak bisa diproses**, dan menu **Deliveries** penuh (catat surat jalan, edit, ubah jadwal, hapus). Tanpa akses Customers, Products, Stock, Lead Time, Complaint, maupun Reports.
- **Surat Jalan hanya diisi PPIC.** Marketing, Sales, dan Management kini hanya *melihat* Deliveries (tombol *Catat delivery* & *Ubah jadwal* tidak tampil dan ditolak di backend). Admin tetap bisa untuk koreksi.
- Dashboard PPIC: *Menunggu PPIC*, *Surat jalan mendatang*, order terbaru, dan pengiriman mendatang. PPIC juga menerima notifikasi pengingat delivery.

### 3 · Produksi & Gudang: Stock dengan nama produk manual

- Role baru **Produksi** (Stock) dan **Gudang** (Stock, Inbound Maklon, Inbound Supplier).
- Form stok: **nama produk diketik manual** (dengan saran). Nama yang sama otomatis masuk ke **kelompok produk** yang sama; nama baru dicatat sebagai produk baru (sumber "Stok").
- Menu Stock: tab **Kelompok per produk** (total FG / WIP / Ready / Reserved per produk, filter kategori), klik nama produk untuk melihat semua entri dalam kelompok tersebut, dan tab *Semua entri* (dengan pencatat & waktu update).
- Entri stok legacy yang belum terhubung bisa dikelompokkan cukup dengan mengetik nama produknya.

### 4 · Menu Finance dihapus

Menu **Invoice & Payment** dan **PO Financials** (beserta laporan Financial, kartu piutang di dashboard, tab Invoice & ringkasan piutang di customer, bagian Finance di halaman order, pencarian invoice, pengaturan jatuh tempo/PPN, dan notifikasi invoice overdue) dihapus karena ranah divisi Keuangan. **Data lama tetap ada di database** (tabel `invoices_payments` & `po_financials`), tetap ikut import workbook, dan tetap mencegah order terkait terhapus.

### 5 · Inbound Maklon diinput manual oleh Gudang

- Hanya **Gudang** (dan Admin) yang mencatat/mengubah; Management & Viewer melihat.
- Dropdown order & produk diganti isian manual: **Nama barang / komponen** (wajib) dan **No. order / PO terkait** (opsional). Bila No. order sama dengan No. order OEF atau No. PO customer, atau nama barang sama dengan produk di master, data otomatis terhubung (tanpa membuat data baru).

### 6 · Menu baru: Inbound Supplier

Menu **Inventory › Inbound Supplier** (`/inbound-supplier`) untuk penerimaan barang dari supplier (bahan baku, kemasan, label, karton, dll.), diinput manual oleh **Gudang**:

| Kolom | Keterangan |
|---|---|
| Supplier, Tanggal barang masuk | Wajib. |
| No. & tanggal surat jalan supplier, No. PO pembelian | Opsional. Tanggal SJ tidak boleh setelah tanggal masuk. |
| Nama barang, kode, jenis barang, lokasi simpan | Nama barang wajib; isian lain dengan saran dari data sebelumnya. |
| Qty diterima, Qty reject, Satuan | Qty boleh desimal (format `1.250,5` atau `1250.5`); reject ≤ diterima. **Total masuk = diterima − reject** (otomatis). |
| Penerima, link lampiran, catatan | Penerima default = user yang login. |

Tersedia tab **Rekap per barang** (total diterima, reject, dan masuk per nama barang + satuan), filter supplier/jenis/periode/ada reject, pencarian global, kartu di dashboard Gudang, dan riwayat perubahan (audit log).

## 19. Konfirmasi PPIC massal, hapus user & buka blokir (update Oktober 2026 — 3)

Cara update server sama seperti [bagian 18](#cara-update-server-berlaku-untuk-setiap-update) (upload `pik-update.zip` + satu perintah Terminal). Tidak ada perubahan struktur database.

### Konfirmasi PPIC massal di daftar Order Entry Form (Admin & PPIC)

- Daftar **Order Entry Form** menampilkan kotak centang di setiap OEF (PO lama tanpa konfirmasi PPIC tidak bisa dicentang). Kotak di judul kolom = **pilih semua order yang tampil di halaman itu**; pilihan *25 / 50 / 100 per halaman* ada di bar filter, sehingga filter (mis. *Menunggu PPIC*) + 100 per halaman + pilih semua bisa memproses hingga 100 order sekaligus.
- Bar di atas tabel menampilkan jumlah order yang dicentang, kolom **Catatan / alasan**, dan tombol **Bisa diproses** (hijau) / **Tidak bisa diproses** (merah, **alasan wajib** — dipakai untuk semua order yang dicentang).
- Aturannya sama dengan konfirmasi satu per satu: *Bisa diproses* → status order *On Process*; *Tidak bisa diproses* → status *Cancelled* dan jadwal delivery dibatalkan; pembuat OEF & sales mendapat notifikasi; tercatat di Audit Log. Order yang sudah berstatus sama dilewati dan disebutkan di pesan hasil. Setelah diproses, halaman kembali ke filter & halaman yang sama.
- Role lain tidak melihat kotak centang dan ditolak di backend.

### Hapus user (Admin)

- **Settings › Users**: tombol tempat sampah di setiap baris, atau bagian **Hapus user** di halaman Edit. Admin tidak bisa menghapus akunnya sendiri, dan minimal satu Admin aktif tetap ada.
- Halaman Edit menampilkan data yang masih ditangani user (customer sebagai PIC marketing, lead, follow up terjadwal, order sebagai sales) sebelum dihapus.
- Setelah dihapus: user langsung tidak bisa login (sesi aktif ikut berakhir); data yang pernah dibuatnya **tetap ada** (kolom pembuat / PIC / sales menjadi kosong); **Audit Log tetap menyimpan nama user**; notifikasi milik user ikut terhapus. Bila user hanya sementara tidak dipakai, cukup hilangkan centang *User aktif*.

### Blokir login, notifikasi Admin & buka blokir

- Salah password **5x dalam 15 menit** → login email tersebut diblokir sementara 15 menit (aturan lama). Kini user langsung diberi tahu di percobaan ke-5, dan **semua Admin aktif mendapat notifikasi** *"User terblokir: …"* (lonceng notifikasi) yang mengarah ke daftar user terblokir.
- **Settings › Users** menampilkan peringatan *"N user sedang terblokir"*, label **Terblokir s/d jam …** di baris user, dan filter status **Terblokir**.
- Tombol **Buka blokir** (di daftar maupun di halaman Edit) membuka blokir saat itu juga; notifikasi terkait ditandai selesai untuk semua Admin dan tercatat di Audit Log (`user_unblock`). Bila tidak dibuka, blokir tetap berakhir otomatis setelah 15 menit. Reset password juga membuka blokir.

## 20. Hapus OEF massal & perbaikan tampilan angka besar (update Oktober 2026 — 4)

Cara update server sama seperti [bagian 18](#cara-update-server-berlaku-untuk-setiap-update). Tidak ada perubahan struktur database.

### Hapus OEF massal (khusus Admin)

- Di daftar **Order Entry Form**, Admin bisa mencentang beberapa order — atau kotak di judul kolom untuk **memilih semua order di halaman itu** (25 / 50 / 100 per halaman) — lalu menekan **Hapus**. Konfirmasi menyebutkan jumlah order yang akan dihapus. PO lama (tanpa konfirmasi PPIC) juga bisa dicentang Admin.
- Aturannya sama dengan hapus satu per satu: jadwal delivery otomatis & baris produk ikut terhapus, tercatat di Audit Log, dan notifikasi yang menunjuk ke order tersebut ikut dibersihkan. Order yang **sudah punya surat jalan, complaint/retur, lead time, atau inbound tidak dihapus** — namanya disebutkan di pesan hasil (gunakan status *Cancelled* untuk order seperti itu).
- Hak akses memakai permission terpisah `purchase_orders_bulk.delete` (default hanya Admin). Marketing & Management tetap bisa menghapus OEF satu per satu dari halaman detail seperti sebelumnya, tetapi tidak bisa menghapus massal.
- Menekan Enter di kolom catatan tidak lagi mengirim form aksi massal (mencegah aksi tidak sengaja).

### Perbaikan tampilan

- **Angka besar** (mis. *Outstanding 310.083.505* atau miliaran) di kartu dashboard dan ringkasan angka di halaman daftar/detail kini otomatis mengecil mengikuti lebar kartu — tidak lagi keluar dari kotak.
- Teks panjang tanpa spasi (No. order, kode, nama) di tabel turun baris di tempat yang wajar (No. order dipotong setelah tanda `/`), sehingga tabel tidak melebar dan teks di HP tidak terpotong.
- Daftar OEF: di layar laptop (< 1400px) badge **Status** ditampilkan di bawah badge **PPIC** dan kolom *Progres* disembunyikan, sehingga seluruh kolom muat tanpa digeser; di tablet status order kini juga terlihat. Judul kolom yang panjang boleh turun baris.
- Diperiksa otomatis untuk 8 role di lebar layar 1440, 1280, 1024, 768, dan 390 px dengan data berangka sangat besar.
