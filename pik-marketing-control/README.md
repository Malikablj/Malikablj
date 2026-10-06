# PIK Marketing Control

Aplikasi web internal **PT Permata Indo Kemas** untuk mengelola customer, CRM (lead, aktivitas, follow up), **Order Entry Form (OEF)** dengan review PPIC, delivery & Surat Jalan, **retur & komplain** (bukti foto/PDF + email ke QC), stok, lead time, inbound maklon, **inbound supplier**, laporan, dan notifikasi — satu tempat yang menggantikan spreadsheet AppSheet.

> Menu Finance (Invoice & Payment, PO Financials) sudah dihapus karena ranah divisi keuangan. Data keuangan lama **tidak dihapus** — tetap tersimpan di tabel `invoices_payments` dan `po_financials`.

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

# 4) Cek ekstensi PHP, lalu buat tabel + pengaturan awal
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
| `APP_URL` | `https://marketing.permataindokemas.com` | Alamat situs **tanpa subfolder**, untuk link di email QC. |
| `MAIL_DRIVER` | `log` | `log` = email **tidak dikirim**, hanya dicatat di `storage/logs/mail-*.log` (untuk uji coba). `smtp` = kirim lewat server email perusahaan. `mail` = fungsi `mail()` hosting. |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION` | `mail.domain.com`, `587`, `tls` | Server SMTP. `tls` untuk port 587, `ssl` untuk 465, `none` tanpa enkripsi. |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | | Akun email pengirim (AUTH LOGIN). |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | `noreply@domain.com` | Alamat & nama pengirim. Wajib diisi untuk `smtp`/`mail`. |

Pengaturan bisnis (nama perusahaan, **email QC default**, tarif PPN untuk import database PO, pengingat delivery, interval otomasi) diubah Admin di **Settings › Pengaturan**, bukan di `.env`. Halaman itu juga menampilkan cara email dikirim saat ini (mis. "MAIL_DRIVER=log — email tidak benar-benar dikirim").

**Mengaktifkan email ke QC** (contoh server email perusahaan):

```ini
MAIL_DRIVER=smtp
MAIL_HOST=mail.permataindokemas.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=noreply@permataindokemas.com
MAIL_PASSWORD=isi_password_email_di_server_saja
MAIL_FROM_ADDRESS=noreply@permataindokemas.com
MAIL_FROM_NAME="PIK Marketing Control"
```

Setiap percobaan kirim tercatat di detail retur/komplain (Terkirim / Dicatat (log) / Gagal + alasannya). Bila gagal, data komplain tetap tersimpan dan bisa dikirim ulang dengan tombol **Kirim ulang**.

## 4. Setup database

- `database/schema.sql` — tabel dasar (users, customers, contacts, products, purchase_orders, po_lines, deliveries, returns, stock, leadtime, inbound_maklon, invoices_payments, po_financials, leads, activities, follow_up + notifications, audit_logs, migration_issues, settings, login_attempts). Aman dijalankan ulang (`CREATE TABLE IF NOT EXISTS`).
- `database/migrations/` — tambahan setelah schema: nilai dokumen PO & log import (`2026_10_01_…`), lalu OEF + review PPIC, role baru, retur & komplain (bukti, email QC, hasil), dan Inbound Supplier (`2026_10_06_…`).
- `database/seed.sql` — **hanya** pengaturan awal (nama perusahaan, jatuh tempo default 30 hari, PPN 11%, dll.). Tidak berisi data bisnis maupun akun.
- `php database/install.php` menjalankan keduanya memakai kredensial `.env`, lalu semua **migrasi**.
- Tanpa akses SSH (mis. hosting dengan phpMyAdmin): impor `schema.sql` lalu `seed.sql` lewat tab *Import* phpMyAdmin, login sebagai Admin, lalu jalankan **Settings › Pembaruan database**.

### Pembaruan database (migrasi)

Perubahan struktur setelah `schema.sql` ditulis sebagai migrasi di `database/migrations/` (tercatat di tabel `schema_migrations`). Migrasi hanya **menambah** tabel/kolom/index — tidak ada DROP/TRUNCATE — dan setiap langkah dicek dulu sehingga aman dijalankan ulang di MySQL maupun MariaDB.

Saat meng-update aplikasi di server:

1. Backup seluruh database (bagian 10).
2. Upload kode versi baru.
3. Jalankan `php database/migrate.php` (atau login Admin → **Settings › Pembaruan database** → *Jalankan pembaruan*). Tabel terkait otomatis dibackup ke `storage/backups/` sebelum migrasi.

Selama migrasi belum dijalankan, Admin otomatis diarahkan ke halaman Pembaruan database dan user lain melihat pesan "Aplikasi sedang diperbarui" (tidak ada error SQL). `php database/migrate.php --status` hanya menampilkan status.

#### Update ke versi OEF / Retur & Komplain (Oktober 2026)

1. Backup database **dan** folder `storage/` (bagian 10).
2. Upload kode baru, lalu jalankan pembaruan database (langkah 3 di atas). Migrasi `2026_10_06_000001_…` hanya menambah: nilai role `PPIC`, `Produksi`, `Gudang`, `Purchasing`; kolom OEF (`order_number`, `sales_name`, `review_status`, …); kolom komplain di `returns`; tabel `return_attachments`, `email_logs`, `inbound_supplier`. PO yang sudah ada otomatis berstatus review **Bisa diproses** (sudah berjalan sebelum review PPIC).
3. Tambahkan `APP_URL` dan `MAIL_*` ke `.env` (bagian 3) bila email ke QC ingin benar-benar dikirim.
4. Pastikan folder `storage/uploads/` dapat ditulis web server (file bukti komplain).
5. Login Admin → **Settings › Users**: buat akun untuk PPIC, Produksi, Gudang, dan Purchasing; isi **Email QC default** di Settings › Pengaturan.

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
- Banyak issue selesai otomatis saat datanya dilengkapi (mis. menghubungkan delivery/retur/stok/lead time ke OEF/produk). Issue data keuangan lama (invoice, PO financial) tetap tercatat; karena menu Finance sudah dihapus, tandai *Ignored* bila tidak lagi relevan.

### Import database PO (`PIK_PO_DATABASE_*.xlsx`)

File hasil digitalisasi dokumen PO (sheet `PO_MASTER`, `PO_ITEMS`, `CUSTOMERS`, `PRODUCTS`, `VALIDATION`) **boleh** diimport ke database yang sudah berisi data: PO yang sudah ada dilengkapi (nilai, PPN, harga satuan, termin, alamat kirim, link dokumen), bukan dibuat ulang.

| Excel | Tabel aplikasi |
|---|---|
| PO_MASTER | `purchase_orders` (nomor PO, tanggal, customer, subtotal, diskon, PPN, ongkir, grand total, termin, alamat & tanggal kirim, contact, link dokumen; `import_ref` = PO_ID) |
| PO_ITEMS | `po_lines` (qty, satuan, harga satuan, subtotal/DPP, kode item, spesifikasi; `import_ref` = ITEM_ID) |
| CUSTOMERS / PRODUCTS | `customers` / `products` (dicocokkan dulu; dibuat hanya bila benar-benar baru) |
| VALIDATION | `migration_issues` (satu issue per temuan, terhubung ke PO-nya) |

Langkah aman (web: **Settings › Import Data › Import database PO**; atau CLI):

```bash
php database/migrate.php                                                   # struktur terbaru
php database/import_po_database.php --file="/path/PIK_PO_DATABASE.xlsx"    # DRY RUN: tidak mengubah data
php database/import_po_database.php --file="/path/PIK_PO_DATABASE.xlsx" --import   # konfirmasi "IMPORT"
```

- **Dry run wajib** untuk file yang sama (SHA-256) dalam 24 jam sebelum import nyata.
- Import nyata: backup otomatis tabel terkait ke `storage/backups/`, lalu semua ditulis dalam **satu transaksi** (gagal = rollback, status `ROLLED_BACK`). Setiap proses tercatat di `import_logs` (RUNNING / COMPLETED / COMPLETED_WITH_WARNING / FAILED / ROLLED_BACK) dengan rekonsiliasi jumlah PO, jumlah item, dan total grand total (Excel vs database), beserta penyebab setiap selisih.
- Pencocokan: `import_ref` → nomor PO persis → nomor PO beda tanda baca (customer sama) → PO tanpa nomor dengan customer + tanggal + qty sama. Customer: nama ternormalisasi persis, atau ≥ 2 nomor PO yang sama. Produk: kode, lalu nama persis. **Tidak ada fuzzy matching.**
- Yang ragu **ditahan** dan masuk Migration Issues, mis. *POSSIBLE EXISTING PO* (nomor tidak lengkap) dan *PO MATCH AMBIGUOUS* (nomor PO dipakai beberapa PO yang tidak bisa dibedakan): pilih PO yang benar dengan tombol **Hubungkan**, lalu jalankan import lagi.
- Nilai yang sudah ada tidak pernah ditimpa; perbedaan (tanggal, nilai, item, status) menjadi issue. Nilai yang ditulis import sebelumnya dan belum diubah user boleh diperbarui oleh file versi baru (dicek lewat `import_snapshot`).
- Aman dijalankan ulang: import kedua dengan file yang sama tidak menambah atau mengubah apa pun.

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
| viewer@pik.test | Viewer |
| ppic@pik.test | PPIC |
| produksi@pik.test | Produksi |
| gudang@pik.test | Gudang |
| purchasing@pik.test | Purchasing |

Password semua akun: nilai variabel `DEV_PASSWORD`, atau default `PikDev2026!`. Script hanya mau berjalan bila `APP_ENV` = `development`, `local`, atau `testing`.

## 7. Menjalankan test

Test berjalan pada database terpisah yang **wajib** berakhiran `_test` (dikosongkan setiap kali test dijalankan):

```bash
mysql -u root -p -e "CREATE DATABASE pik_marketing_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  GRANT ALL PRIVILEGES ON pik_marketing_test.* TO 'pik_app'@'localhost';"

php tests/run.php              # semua test (±137 test, ±65 detik)
php tests/run.php "Phase 4"    # hanya grup tertentu
```

Test menjalankan server PHP bawaan dan menguji lewat HTTP sungguhan: CRUD, validasi, otorisasi per role (termasuk PPIC/Produksi/Gudang/Purchasing), alur OEF → review PPIC → jadwal delivery, Surat Jalan khusus PPIC, outstanding, retur & komplain (upload bukti, akses file, hasil Selesai/Tidak selesai), pengiriman email lewat **server SMTP tiruan**, laporan & export, otomasi, import, serta pengujian keamanan (XSS di semua halaman, SQL injection, CSRF, cookie). Opsional, menguji import workbook asli (beberapa file legacy dipisah titik koma):

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

Otomasi menandai follow up yang lewat jadwal (Overdue) dan mengirim notifikasi (tanpa duplikat) kepada user yang berhak:

| Notifikasi | Penerima |
|---|---|
| Follow up hari ini / terlewat | PIC follow up (bila pengingat aktif) |
| Delivery terjadwal dalam N hari (Settings) | PIC marketing customer / pembuat delivery + semua user PPIC (atau semua user Marketing) |
| Target closing lead ≤ 3 hari / terlewat | PIC lead |

Notifikasi yang dikirim langsung saat kejadian (bukan dari otomasi):

| Kejadian | Penerima |
|---|---|
| OEF baru dibuat / OEF diubah setelah direview | Semua user PPIC ("menunggu review") |
| PPIC menandai **Bisa diproses** / **Tidak bisa diproses** (+ alasan) | Pembuat / pengubah terakhir OEF |
| Komplain dicatat (bila dicentang) dan saat diberi hasil Selesai / Tidak selesai | **Email** ke alamat di kolom Email QC (lihat bagian 3) |

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

Yang perlu dibackup: **database**, folder **`storage/uploads/`** (file bukti retur & komplain), dan file **`.env`** (simpan terpisah dan aman). Folder `storage/logs` boleh dirotasi/dihapus berkala. File upload import dihapus otomatis setelah diproses.

```bash
tar czf /backup/pik_uploads_$(date +%F).tar.gz -C /var/www/pik-marketing-control storage/uploads
```

## 11. Hak akses per role

Otorisasi dicek di **backend** pada setiap route (lihat `app/routes.php` + `config/permissions.php`); menu & tombol hanya disembunyikan sebagai kenyamanan tampilan.

| Modul | Admin | Marketing | Sales | Management | Viewer | PPIC | Produksi | Gudang | Purchasing |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| Dashboard (isi sesuai role) | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ |
| Customers & Contacts | ✔ | ✔ | ✔ | ✔ | lihat | — | — | — | — |
| Leads, Activities, Follow Up | ✔ | ✔ | ✔ | — | lihat | — | — | — | — |
| Order Entry Form (buat/ubah) | ✔ | ✔ | — | lihat | lihat | lihat | — | — | — |
| Review OEF (Bisa / Tidak bisa diproses) | — ¹ | — | — | — | — | ✔ | — | — | — |
| Deliveries (jadwal, status) | ✔ | lihat | — | lihat | lihat | ✔ | — | — | — |
| Isi nomor Surat Jalan | — ¹ | — | — | — | — | ✔ | — | — | — |
| Retur & Komplain | ✔ | ✔ | — | lihat | lihat | — | — | — | — |
| Products | ✔ | ✔ | — | lihat | lihat | — | — | — | — |
| Stock | ✔ | — | — | lihat | lihat | — | ✔ | ✔ | — |
| Inbound Maklon | ✔ | — | — | lihat | lihat | — | — | ✔ | — |
| Inbound Supplier | ✔ | — | — | lihat | lihat | — | — | — | ✔ |
| Lead Time | ✔ | ✔ | — | lihat | lihat | — | — | — | — |
| Reports | ✔ | — | — | semua + export | tanpa export | — | — | — | — |
| Nilai PO hasil import (data keuangan) | ✔ | — | — | — | — | — | — | — | — |
| Users, Settings, Import, Migration Issues, Audit Log | ✔ | — | — | — | — | — | — | — | — |

¹ Khusus PPIC sesuai permintaan. Bila Admin juga boleh, hapus baris `'!oef_review.approve'` / `'!deliveries.sj'` di `config/permissions.php`. Admin tetap bisa mengoreksi jadwal delivery (tanpa mengubah nomor Surat Jalan).

Catatan:
- Produk yang diketik di OEF atau di form stok otomatis masuk menu Products; role yang menginput tidak perlu akses menu Products.
- Management sekarang **memantau** (lihat semua data operasional + laporan); input dilakukan role pemilik prosesnya.
- Ubah kebijakan akses cukup di `config/permissions.php` (format `modul.aksi`, `modul.*`, `*`, dan pengecualian `!modul.aksi`).

## 12. Aturan bisnis

### Order Entry Form (OEF) → review PPIC → delivery

1. **Marketing** mengisi OEF: No order (manual, unik), tanggal order, nama sales, nama customer, No PO dari customer, lalu per produk: nama produk, spesifikasi, qty, satuan, supplier (jika subcont); permintaan selesai/kirim, tujuan kirim, dan keterangan.
2. **Customer & produk diketik manual.** Nama dicocokkan tanpa peka huruf besar/kecil & spasi berlebih. Bila belum ada, otomatis ditambahkan ke menu Customer / Products (sumber "OEF"). Bila ada beberapa produk/customer dengan nama sama, user diminta memilih dari daftar saran — aplikasi **tidak menebak**.
3. OEF baru berstatus review **Menunggu review PPIC**; semua user PPIC mendapat notifikasi.
4. **PPIC** membuka OEF dan menekan **Bisa diproses** (hijau) atau **Tidak bisa diproses** (merah, alasan wajib). Pembuat OEF mendapat notifikasi beserta alasannya.
5. Saat **Bisa diproses**: status OEF Open → *On Process*, dan menu Delivery otomatis berisi jadwal *Scheduled* per produk pada tanggal **permintaan selesai/kirim** (qty = sisa yang belum dijadwalkan, tujuan = tujuan kirim).
6. Jadwal bisa diubah PPIC di menu Delivery bila jadwal berubah. **Nomor Surat Jalan hanya diisi PPIC.** Jadwal yang sudah diubah PPIC tidak lagi disesuaikan otomatis.
7. Bila isi OEF diubah setelah direview (selain status), OEF kembali **Menunggu review PPIC**. Saat disetujui ulang, jadwal otomatis yang masih *Scheduled* & belum ber-SJ disesuaikan dengan tanggal/qty terbaru (tidak pernah dobel).

### Perhitungan & status

- **Outstanding Quantity = Order Quantity − Delivered Quantity + Return Quantity** (per produk OEF). Delivered hanya menghitung delivery berstatus *Delivered*/*Partial*; jadwal *Scheduled* belum mengurangi outstanding. Nilai negatif = kelebihan kirim (harus dikonfirmasi saat input).
- **Status OEF otomatis:** Open/On Process → *Partial* saat ada kiriman; → *Closed* saat semua produk outstanding ≤ 0. Status *Closed* dan *Cancelled* tidak pernah diubah otomatis. Setiap perubahan otomatis tercatat di audit log.
- **Retur & Komplain** (satu menu): jenis *Retur* = barang dikembalikan, qty wajib dan **menambah outstanding**; jenis *Komplain* = tanpa barang kembali, qty bermasalah hanya informasi, detail masalah wajib. Bukti berupa gambar JPG/PNG/WEBP atau PDF (maks 5 MB per file, 10 file per kasus). Hasil: **Selesai** (hijau, catatan opsional) atau **Tidak selesai** (merah, alasan wajib); bisa dibuka kembali. Hasil otomatis dikirim ke email QC. Seluruh kasus ada di **Reports › Retur & Komplain**.
- **Products:** "Qty (arsip OEF)" = total qty produk tersebut di semua OEF (tanpa *Cancelled*) — menggantikan tampilan kapasitas produksi per hari (kolom lama tetap tersimpan, tidak dihapus). **Kelompok** produk dibuat otomatis dari kata di nama produk (Botol, Pot / Jar, Tube, Pump & Sprayer, Cap / Tutup, Dropper, Label & Shrink, Printing, Box & Karton, Pouch & Sachet, selain itu *Lainnya*); kategori yang diisi manual di produk selalu didahulukan.
- **Stok:** diisi Produksi & Gudang dengan mengetik nama produk; produk baru otomatis dibuat. Halaman Stock dikelompokkan per kelompok lalu per produk. Qty = jumlah box × isi per box bila Qty dikosongkan; Qty yang berbeda harus dikonfirmasi. Entri tanpa qty ditandai, tidak dihitung sebagai 0.
- **Follow up overdue:** tanggal < hari ini dan status bukan *Done* (status *Cancelled* juga dianggap selesai).
- **Lead time:** estimasi terbuka yang tanggalnya lewat ditandai *Terlambat*.
- **Inbound maklon** (input Gudang): total masuk = qty diterima − qty reject.
- **Inbound supplier** (input Purchasing): diterima bersih = qty datang − qty reject; reject tidak boleh melebihi qty datang.
- **Nilai PO** dari import database PO (harga, PPN, grand total) adalah data keuangan: tidak tampil di form/daftar OEF, hanya sebagai arsip untuk Admin. Bila qty produk berharga diubah, nilai arsipnya dihitung ulang (harga tidak dihapus).
- Uang dihitung dalam sen (integer) agar tidak ada selisih pembulatan; input menerima format Indonesia (`12.500.000` atau `1.250.000,50`).

## 13. Temuan data migrasi

Hasil import workbook asli (44 customer, 262 produk, 365 PO, 407 baris PO, 1.526 delivery, 6 retur, 54 stok, 9 lead time, 44 inbound, 92 invoice, 29 PO financial) — 924 catatan migrasi, di antaranya:

- **Tanggal hari/bulan tertukar** di master (mis. 2024-01-04 vs 2024-04-01). 108 tanggal dikoreksi otomatis karena didukung bukti; 23 kasus master terbukti benar; 27 perlu ditinjau manual.
- **Tanggal bayar di file legacy** banyak yang lebih awal dari tanggal invoice (format tanggal Excel rusak), sehingga tidak dipakai tanpa bukti.
- **Harga satuan 1.000×** pada beberapa baris PO Financials (angka teks format Indonesia terbaca salah) — 12 angka dikoreksi (10 harga satuan, 1 PPN, 1 total) karena qty × harga cocok dengan total.
- **614 delivery belum terhubung ke baris PO** (537 produk tidak cocok persis, 77 PO tidak ditemukan). 435 di antaranya berada di PO satu baris dan bisa ditinjau cepat di halaman *Tinjau delivery legacy*. Sampai dihubungkan, delivery ini belum mengurangi outstanding.
- PO tanpa nomor (36) / tanpa tanggal (37), nomor PO ganda (4), kemungkinan customer (3) & produk (7) ganda.
- Stok: 9 baris judul kolom spreadsheet, 35 nama barang belum cocok ke master produk.
- Seluruh 92 invoice tidak memiliki jatuh tempo; 1 invoice belum lunas ditandai untuk dilengkapi (data keuangan lama — menu Finance sudah dihapus, datanya tetap tersimpan).

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
│   ├── services/           # dashboard, laporan, otomasi, alur OEF, email komplain, importer
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
│   ├── import_workbook.php # import data dari workbook AppSheet
│   ├── import_po_database.php # import database PO (dry run / import)
│   ├── migrate.php         # jalankan pembaruan struktur database
│   └── migrations/         # migrasi (hanya menambah tabel/kolom)
├── public/                 # satu-satunya folder yang perlu diakses browser
│   ├── index.php           # front controller
│   ├── css/app.css
│   ├── js/app.js
│   └── assets/             # Bootstrap, ikon, font, gambar (lokal)
├── storage/                # log, file import sementara, backup otomatis, uploads/ (bukti komplain) — tidak di-commit
└── tests/                  # test runner + skenario per fase
```

## 15. Keamanan

- Password disimpan dengan `password_hash` (bcrypt) dan diverifikasi `password_verify`; kebijakan minimal 8 karakter berisi huruf & angka; password sementara wajib diganti.
- Login dibatasi (5 percobaan gagal per email / 20 per IP dalam 15 menit); sesi diganti saat login (anti session fixation); cookie `HttpOnly`, `SameSite=Lax`, `Secure` di HTTPS; timeout idle & absolut.
- Semua form POST memakai token **CSRF**; semua query memakai **PDO prepared statements**; kolom sort memakai whitelist.
- Semua output di-escape; link eksternal hanya `http/https`; Content-Security-Policy tanpa inline script, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`.
- Export CSV dilindungi dari formula injection; setiap export, import, perubahan data, dan login tercatat di **Audit Log**.
- Upload import divalidasi (ekstensi .xlsx + signature zip + ukuran), disimpan di luar document root, dan dihapus setelah diproses.
- Bukti komplain divalidasi dari **isi file** (signature JPEG/PNG/WEBP/PDF, gambar harus benar-benar terbaca), disimpan dengan nama acak di `storage/uploads/` (di luar `public/`), dan hanya bisa dibuka lewat aplikasi oleh role yang berhak (dengan `nosniff` + CSP sandbox).
- Email: alamat divalidasi, baris baru di subjek dibuang (anti header injection), password SMTP hanya di `.env`. Kegagalan kirim tidak pernah disembunyikan — status tercatat di `email_logs` dan ditampilkan.
- Detail error hanya tampil bila `APP_DEBUG=true`; di production error dicatat ke `storage/logs/`.

## 16. Troubleshooting

| Gejala | Penyebab & solusi |
|---|---|
| "Aplikasi sedang diperbarui" / Admin diarahkan ke Pembaruan database | Kode baru sudah di-upload tetapi migrasi belum dijalankan. Backup database, lalu Settings › Pembaruan database › Jalankan pembaruan (atau `php database/migrate.php`). |
| Pesan "membutuhkan ekstensi PHP zip/xmlreader/..." | Ekstensi belum aktif. Buka `php.ini` (lokasinya tampil saat `php database/install.php`), hapus tanda `;` di depan `extension=zip` (atau ekstensi yang disebut), simpan, restart Apache. |
| Halaman 503 "Tidak dapat terhubung ke database" | Cek `DB_*` di `.env`, pastikan MySQL berjalan dan `schema.sql` sudah diimpor (`php database/install.php`). |
| Semua halaman 404 kecuali beranda | `mod_rewrite` belum aktif atau `AllowOverride All` belum diset. Alternatif: `APP_PRETTY_URLS=false`. |
| Tampilan tanpa CSS / link salah di subfolder | Isi `APP_BASE_PATH=/nama-subfolder` di `.env`. |
| "Sesi berakhir / 419" saat submit form | Halaman terbuka terlalu lama; muat ulang halaman lalu kirim lagi. |
| "Terlalu banyak percobaan login" | Tunggu 15 menit, atau Admin mereset password user di Settings › Users. |
| Upload import gagal "terlalu besar" | Naikkan `upload_max_filesize` & `post_max_size` di `php.ini`, restart Apache. |
| Error menulis log / import | Pastikan `storage/` dapat ditulis user web server (`chown -R www-data storage`). |
| Notifikasi tidak muncul | Cek Settings › Pengaturan (waktu otomasi terakhir), tombol *Jalankan sekarang*, atau pasang cron (bagian 9). |
| Angka outstanding OEF/PO lama terlihat besar | Delivery legacy belum terhubung ke produk OEF — tinjau di Migration Issues › Tinjau delivery legacy. |
| Lupa password Admin | `php database/create_admin.php` untuk membuat Admin baru dari server. |
| Email ke QC tidak sampai | Lihat status di detail komplain › Email QC. "Dicatat (log)" = `MAIL_DRIVER=log` (email tidak dikirim) → isi `MAIL_*` di `.env`. "Gagal" menampilkan alasan dari server email (mis. login ditolak, host salah). Setelah diperbaiki tekan **Kirim ulang**. |
| Upload bukti ditolak "hanya gambar JPG, PNG, WEBP, atau PDF" | File bukan gambar/PDF asli (mis. HEIC dari iPhone atau file yang hanya diganti ekstensinya). Simpan ulang sebagai JPG/PNG/PDF. |
| Halaman 413 "File terlalu besar" saat upload bukti | Total file melebihi `post_max_size` server. Kirim lebih sedikit file sekaligus, atau naikkan `post_max_size` & `upload_max_filesize` di `php.ini`. |
| OEF ditolak "Ada N produk bernama sama" | Master produk punya beberapa produk dengan nama sama (beda varian/kode). Ketik nama lalu pilih salah satu saran yang memuat varian/kode. |
| Tombol "Bisa diproses" tidak muncul | Hanya role **PPIC** yang bisa mengonfirmasi OEF, dan hanya saat OEF *Menunggu review PPIC*. |
| Kolom Surat Jalan tidak bisa diisi | Hanya role **PPIC** yang dapat mengisi nomor Surat Jalan. |

Log aplikasi: `storage/logs/app-YYYY-MM-DD.log`.
