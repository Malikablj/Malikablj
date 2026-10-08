# Deployment — NPD Project Control v3.0

Panduan memasang aplikasi di server produksi/UAT PT. Permata Indo Kemas. Semua langkah dan contoh konfigurasi
di folder [`deploy/`](../deploy) **sudah dijalankan** pada lingkungan uji (lihat §10), bukan hanya ditulis.

## 1. Gambaran

```
Browser ──HTTPS──► Nginx / Apache ──FastCGI──► PHP-FPM 8.2+ ──► MySQL 8 (InnoDB, utf8mb4)
                    (document root: public/)       │
                                                  ├── storage/ (dokumen, log, sesi, export)  — di luar web root
cron (php-cli) ── cron/*.php, bin/backup.php ─────┘
```

Tidak ada Node.js, build step, atau daemon tambahan. Satu server cukup untuk ±30 pengguna dan < 200 project
aktif (hasil uji kinerja: `docs/PERFORMANCE.md`).

> **Hosting cPanel (folder domain = document root):** ikuti [`INSTALL-CPANEL.md`](../INSTALL-CPANEL.md) —
> unggah zip dari `bin/build-release.sh`, lalu `bash setup.sh` di Terminal cPanel. `.htaccess` di folder aplikasi
> mengarahkan semua alamat ke `public/`, sehingga tidak perlu mengubah document root.

## 2. Kebutuhan server

| Komponen | Versi / catatan |
| --- | --- |
| OS | Linux (diuji Ubuntu 24.04); Windows + XAMPP dapat dipakai untuk UAT (atur `MYSQLDUMP_BIN`, `MYSQL_BIN`, `TAR_BIN`) |
| Web server | Nginx ≥ 1.18 (diuji 1.24) **atau** Apache 2.4 + `mod_proxy_fcgi` (diuji 2.4.58) |
| PHP | 8.2+ (diuji 8.3.6) — FPM untuk web, CLI untuk cron |
| Ekstensi PHP | `pdo_mysql`, `mbstring`, `json`, `fileinfo`, `sodium`, `gd`, `zip`, `xml`, `dom`, `iconv`, `zlib`, `openssl` (`curl` untuk `bin/check-deployment.php --url`) |
| MySQL / MariaDB | MySQL 8.0+ (diuji 8.0.46) **atau** MariaDB 10.6+ (diuji 10.11.14 — seluruh 349 test lulus, termasuk backup dengan `mariadb-dump`), InnoDB, `utf8mb4_unicode_ci` |
| Program | `mysqldump`, `mysql` (klien), `tar`, `cron`, `composer` (saat instalasi) |
| Disk | kode + vendor ±420 MB; dokumen tumbuh sesuai unggahan (maks. 25 MB/file); backup harian × 30 hari |
| Sertifikat | HTTPS wajib (sertifikat internal perusahaan atau Let's Encrypt) |

## 3. Akun MySQL (hak minimal)

| Akun | Dipakai oleh | Hak |
| --- | --- | --- |
| `npd_admin` | instalasi, migrasi, pemulihan, `hardening.sql` | `ALL` pada database aplikasi (+ `SUPER` **atau** `log_bin_trust_function_creators=1` bila binary log aktif, untuk membuat trigger) |
| `npd_app` | web & cron (`DB_USER` di `.env`) | per tabel `SELECT, INSERT, UPDATE, DELETE`; `audit_logs` hanya `SELECT, INSERT` — dibuat oleh `php bin/db-grants.php` |
| `npd_backup` | `bin/backup.php` (`BACKUP_DB_USER`) | `SELECT, SHOW VIEW, TRIGGER` — `php bin/db-grants.php --role=backup` |

Perintah yang membutuhkan admin dijalankan dengan menimpa variabel lingkungan, tanpa mengubah `.env`:
`DB_USER=npd_admin DB_PASS='…' php bin/migrate.php`. (Variabel lingkungan selalu mengalahkan isi `.env`.)

> Tanpa hak `TRIGGER`, `mysqldump` **diam-diam tidak menyertakan** trigger append-only `audit_logs`.
> Karena itu backup memakai akun `npd_backup`, manifest backup mencatat daftar trigger, dan pemulihan
> memverifikasinya (lihat `docs/BACKUP_AND_RESTORE.md`).

## 4. Instalasi pertama

Contoh memakai `/var/www/npd`, user web `www-data`, PHP 8.3. Jalankan sebagai root kecuali disebut lain.

```bash
# 4.1 Kode (rilis bertag) + dependency produksi
git clone --branch <tag-rilis> https://github.com/<org>/<repo>.git /var/www/npd
cd /var/www/npd
composer install --no-dev --optimize-autoloader --no-interaction

# 4.2 Konfigurasi
cp .env.example .env
php bin/generate-key.php          # salin ke APP_KEY
editor .env                        # isi sesuai tabel 4.3
chown root:www-data .env && chmod 640 .env

# 4.3 Database (sebagai admin MySQL)
mysql -u root -p -e "CREATE USER 'npd_admin'@'localhost' IDENTIFIED BY '<kuat>';
                     GRANT ALL PRIVILEGES ON npd_project_control.* TO 'npd_admin'@'localhost';
                     CREATE USER 'npd_app'@'localhost' IDENTIFIED BY '<kuat>';
                     CREATE USER 'npd_backup'@'localhost' IDENTIFIED BY '<kuat>';"
DB_USER=npd_admin DB_PASS='<kuat>' php bin/install.php --with-hardening
DB_USER=npd_admin DB_PASS='<kuat>' php bin/db-grants.php --user=npd_app --host=localhost | mysql -u root -p
DB_USER=npd_admin DB_PASS='<kuat>' php bin/db-grants.php --role=backup --user=npd_backup --host=localhost | mysql -u root -p
php bin/create-admin.php --name="Admin NPD" --email=admin@permataindokemas.co.id   # password ditanya

# 4.4 Hak akses file: kode milik root (tidak dapat diubah web server), hanya storage yang dapat ditulis
chown -R root:root /var/www/npd && chown root:www-data /var/www/npd/.env
mkdir -p storage/{documents,exports,logs,cache,sessions} && chown -R www-data:www-data storage && chmod -R o-rwx storage
mkdir -p /var/backups/npd && chown www-data:www-data /var/backups/npd && chmod 700 /var/backups/npd

# 4.5 PHP, web server, HTTPS, cron, rotasi log
cp deploy/php-npd.ini /etc/php/8.3/fpm/conf.d/90-npd.ini          # + versi CLI bila perlu
cp deploy/nginx.conf /etc/nginx/sites-available/npd && ln -s ../sites-available/npd /etc/nginx/sites-enabled/
#   atau Apache: cp deploy/apache-vhost.conf /etc/apache2/sites-available/npd.conf && a2enmod ssl proxy_fcgi headers deflate expires && a2ensite npd
cp deploy/cron.d-npd /etc/cron.d/npd && cp deploy/logrotate-npd /etc/logrotate.d/npd
timedatectl set-timezone Asia/Jakarta
nginx -t && systemctl reload nginx php8.3-fpm

# 4.6 Periksa
sudo -u www-data php bin/check-deployment.php --url=https://npd.permataindokemas.co.id
```

### Isi `.env` produksi

| Variabel | Nilai produksi |
| --- | --- |
| `APP_ENV` | `production` (detail error tidak tampil, `--fresh`/demo seed ditolak) |
| `APP_DEBUG` | `0` |
| `APP_URL` | `https://npd.…` (dipakai tautan email) |
| `APP_KEY` | hasil `php bin/generate-key.php` — **simpan salinannya di brankas**: tanpa kunci ini password SMTP terenkripsi tidak dapat dibaca |
| `DB_HOST`, `DB_PORT`, `DB_NAME` | koneksi MySQL |
| `DB_USER`, `DB_PASS` | akun `npd_app` |
| `SESSION_SECURE` | `1` |
| `TRUSTED_PROXIES` | IP reverse proxy/load balancer bila HTTPS diterminasi di depan server ini |
| `STORAGE_PATH` | kosong (= `./storage`) atau path di disk data |
| `BACKUP_PATH`, `BACKUP_RETENTION_DAYS` | mis. `/var/backups/npd` (disk lain), `30` |
| `BACKUP_DB_USER`, `BACKUP_DB_PASS` | akun `npd_backup` |
| `MAIL_*` | bawaan SMTP; dapat diatur Admin di **Pengaturan › Notifikasi & Email** (password tersimpan terenkripsi) |

## 5. Web server

- **Document root hanya `public/`.** Folder lain (config, modules, storage, vendor, database, bin, .env) tidak boleh
  dapat diakses dari web — `bin/check-deployment.php --url` mencoba 9 jalur sensitif tersebut.
- `deploy/nginx.conf` / `deploy/apache-vhost.conf`: redirect HTTP→HTTPS, TLS 1.2/1.3, tolak file tersembunyi,
  gzip, cache 30 hari untuk `/assets/` (aman karena URL aset memakai `?v=` per versi file), batas body 30 MB,
  timeout 120 dtk untuk export besar.
- Header keamanan (CSP bernonce, HSTS saat HTTPS, X-Frame-Options, nosniff, Referrer-Policy) dikirim oleh aplikasi.
  Agar PHP mengenali HTTPS: Nginx `fastcgi_param HTTPS $https if_not_empty;` (sudah ada di contoh), Apache mod_ssl
  otomatis; di belakang reverse proxy isi `TRUSTED_PROXIES`.
- Batas unggah: aplikasi `upload.max_mb` (bawaan 25 MB, Pengaturan) ≤ PHP `upload_max_filesize` (25M) <
  `post_max_size` (30M) ≤ web server (30 MB). Pada Apache + `proxy_fcgi`, `LimitRequestBody` hanya berlaku untuk
  file statis; untuk halaman PHP batas efektifnya `post_max_size` — aplikasi menjawab 413 "Kiriman terlalu besar"
  (bukan "sesi kedaluwarsa"), dan browser sudah menolak file > batas sebelum diunggah.

## 6. Tugas terjadwal

`deploy/cron.d-npd` (sebagai `www-data`, jam server Asia/Jakarta):

| Jadwal | Perintah | Tugas |
| --- | --- | --- |
| `0 6-20 * * 1-5` | `php cron/overdue.php` | aktivasi proses, overdue/due soon, pengingat Hold |
| `*/5 * * * *` | `php cron/notifications.php` | kirim antrean email (retry otomatis) |
| `5 7 * * 1-5` | `php cron/daily-report.php` | ringkasan overdue harian (hari libur dilewati otomatis) |
| `30 1 * * *` | `php bin/backup.php` | backup database + dokumen, retensi 30 hari |

Semua tugas memakai kunci MySQL (tidak berjalan ganda) dan tercatat di `job_runs`. Admin melihat statusnya di
**Pengaturan › Antrean Email › Tugas terjadwal**: "Perlu dicek" muncul bila run terakhir gagal, macet > 2 jam,
atau sukses terakhir lebih lama dari jeda wajar (overdue 64 jam termasuk akhir pekan, email 1 jam,
ringkasan 80 jam, backup 26 jam).

## 7. Log & pemantauan

| Sumber | Lokasi | Isi |
| --- | --- | --- |
| Error aplikasi | `storage/logs/app.log` | exception & error PHP (pengguna hanya melihat pesan umum) |
| Cron | `storage/logs/cron.log` | ringkasan tiap run |
| Web server | `/var/log/nginx/npd-*.log` atau `/var/log/apache2/npd-*.log` | akses & error |
| Tugas terjadwal | `job_runs` → halaman Tugas terjadwal | status, pesan, waktu |
| Audit | `audit_logs` → Pengaturan › Audit Log | siapa, kapan, IP, aksi, sebelum/sesudah |

`deploy/logrotate-npd` merotasi log harian, simpan 30 hari. `bin/check-deployment.php` dapat dijalankan
berkala (mis. harian) dan memberi kode keluar 1 bila ada GAGAL — cocok untuk sistem pemantauan yang ada.

## 8. Checklist go-live

- [ ] `bin/check-deployment.php --url=https://…` → 0 GAGAL, 0 PERINGATAN (setelah cron berjalan sekali)
- [ ] Login Admin, ganti password awal; buat user per role; atur hari libur tahun berjalan
- [ ] SMTP diisi & email uji terkirim (Pengaturan › Notifikasi & Email)
- [ ] Backup pertama ada di `BACKUP_PATH` **dan** uji pemulihan (`docs/BACKUP_AND_RESTORE.md` §4) lulus
- [ ] Salinan backup dikirim ke luar server (NAS/cloud) — lihat BACKUP_AND_RESTORE §5
- [ ] `APP_KEY` & password akun MySQL disimpan di brankas password perusahaan
- [ ] Opsional: `python3 tests/browser/deploy_smoke.py https://… <email-admin> <password> /tmp/smoke` di mesin uji
      (Playwright) — membuat user, customer, dan NPR uji; jalankan di UAT, bukan di produksi berisi data nyata

## 9. Pembaruan versi (upgrade) & rollback

```bash
cd /var/www/npd
sudo -u www-data php bin/backup.php                         # 1. backup dulu
git fetch --tags && git checkout <tag-baru>                 # 2. kode baru
composer install --no-dev --optimize-autoloader --no-interaction
DB_USER=npd_admin DB_PASS='…' php bin/migrate.php --status  # 3. lihat migrasi baru
DB_USER=npd_admin DB_PASS='…' php bin/migrate.php           #    jalankan (berhenti & tidak dicatat bila gagal)
DB_USER=npd_admin DB_PASS='…' php bin/db-grants.php --user=npd_app | mysql -u root -p   # bila ada tabel baru
systemctl reload php8.3-fpm                                 # 4. kosongkan OPcache
sudo -u www-data php bin/check-deployment.php --url=https://…   # 5. periksa
```

Migrasi: file `database/migrations/YYYYMMDD_NNN_keterangan.sql`, berurutan sesuai nama, dicatat di
`schema_migrations`; perubahan yang sama dicerminkan di `database/schema.sql` sehingga instalasi baru langsung
terbaru (dan otomatis menandai semua migrasi). DDL MySQL tidak transaksional, karena itu langkah 1 wajib.

Rollback: `git checkout <tag-lama>` + `composer install`, lalu bila skema sudah berubah pulihkan backup langkah 1 ke
database **baru** dan arahkan `DB_NAME` ke sana (pemulihan tidak pernah menimpa database yang ada).

## 10. Verifikasi yang sudah dilakukan (2026-10-08)

Lingkungan: Ubuntu 24.04 (container), MySQL 8.0.46 dengan binary log aktif, PHP 8.3.6 FPM, sertifikat self-signed.

| Uji | Hasil |
| --- | --- |
| Instalasi sesuai §4 di `/var/www/npd` (`composer install --no-dev`, `.env` produksi, `install.php --with-hardening` sebagai admin, `db-grants.php` app & backup, `create-admin.php`, hak file root/www-data) | berhasil; vendor tanpa paket dev |
| `nginx -t` dengan `deploy/nginx.conf`; `apache2ctl -t` dengan `deploy/apache-vhost.conf` | Syntax OK keduanya |
| `bin/check-deployment.php --url=https://npd.local --insecure --upload-limit` sebagai `www-data`, di belakang **Nginx 1.24** dan **Apache 2.4.58** | 52 OK, 0 peringatan, 0 gagal (keduanya) |
| Keempat perintah `deploy/cron.d-npd` dijalankan sebagai `www-data` dengan akun `npd_app` berhak minimal | semua sukses, tercatat di `job_runs`, backup memakai `npd_backup` dan menyertakan trigger |
| `tests/browser/deploy_smoke.py` lewat HTTPS (Nginx dan Apache): user, customer, NPR, unggah & unduh lampiran (identik SHA-256), tamu ditolak, 13 halaman tanpa error JS/CSP, batas ukuran di browser | OK |
| Alur browser lengkap (project, timeline, Hold, NPR, laporan) pada server dengan akun `npd_app` berhak minimal | OK; `UPDATE/DELETE audit_logs`, `DROP`, `ALTER` ditolak MySQL (1142) |
