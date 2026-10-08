# Instalasi di cPanel — NPD Project Control v3.0

Untuk **npd.permataindokemas.com**, database **perb8631_npd**. Cukup unggah zip ke folder domain lalu
jalankan satu perintah di Terminal cPanel. Waktu: ±10 menit.

## 0. Sebelum mulai (sekali saja, di cPanel)

1. **PHP 8.2 atau 8.3** untuk domain: *cPanel › MultiPHP Manager* → centang `npd.permataindokemas.com` →
   pilih `PHP 8.3` (atau 8.2) → *Apply*.
2. **Ekstensi PHP** (biasanya sudah aktif): `pdo_mysql, mbstring, fileinfo, sodium, gd, zip, xml, dom, iconv,
   zlib, openssl, curl`. Bila setup melaporkan ada yang kurang: *cPanel › Select PHP Version* (CloudLinux) atau
   minta penyedia hosting mengaktifkannya.
3. **Database**: *cPanel › MySQL Databases* — database `perb8631_npd` sudah ada. Pastikan user database
   sudah **ditambahkan ke database** (*Add User To Database*) dengan **ALL PRIVILEGES**. Nama user di cPanel
   selalu berawalan nama akun, mis. `perb8631_namauser`.
4. **SSL**: *cPanel › SSL/TLS Status* → pastikan `npd.permataindokemas.com` hijau (AutoSSL). Aplikasi memaksa HTTPS.
5. **Folder domain**: lihat di *cPanel › Domains* kolom *Document Root*, mis. `/home/perb8631/npd.permataindokemas.com`.
   Kosongkan folder itu (boleh biarkan `cgi-bin` dan `.well-known`).

## 1. Unggah & ekstrak

1. *cPanel › File Manager* → buka folder domain.
2. Klik *Settings* (kanan atas) → centang **Show Hidden Files (dotfiles)** → *Save* (agar `.htaccess` terlihat).
3. *Upload* → pilih `npd-project-control-cpanel.zip` → tunggu 100%.
4. Klik kanan zip → **Extract** → ke folder domain itu sendiri. Isi zip langsung berada di folder domain
   (tidak ada subfolder), sehingga terlihat `public/`, `vendor/`, `setup.sh`, `.htaccess`, `.env.production`, dst.
5. Hapus file zip.

## 2. Jalankan setup (Terminal cPanel)

*cPanel › Terminal*, lalu:

```bash
cd ~/npd.permataindokemas.com        # sesuaikan dengan Document Root di langkah 0.5
bash setup.sh
```

Setup akan:
- mencari PHP 8.2+ yang tepat dan memeriksa ekstensi,
- membuat `.env` dari `.env.production` lalu **menanyakan user & password database** (ketik user lengkap
  berawalan `perb8631_`; password tidak tampil saat diketik),
- membuat kunci aplikasi (`APP_KEY`), membuat tabel & data awal (tidak menghapus data yang ada),
- menanyakan **nama, email, dan password Admin pertama** (password min. 8 karakter, huruf + angka),
- menampilkan **4 baris Cron Jobs** dan menjalankan pemeriksaan akhir (`[OK]` / `[PERINGATAN]` / `[GAGAL]`).

Setup aman dijalankan ulang kapan saja (mis. setelah memperbaiki sesuatu).

## 3. Cron Jobs

*cPanel › Cron Jobs* → *Add New Cron Job*. Salin **persis** 4 perintah yang dicetak setup (berisi lokasi PHP
dan folder Anda). Contohnya:

| Common Settings / waktu | Perintah (contoh — pakai yang dicetak setup) |
| --- | --- |
| `0 6-20 * * 1-5` | `/usr/local/bin/php /home/perb8631/npd.permataindokemas.com/cron/overdue.php >> …/storage/logs/cron.log 2>&1` |
| `*/5 * * * *` | `… /cron/notifications.php …` |
| `5 7 * * 1-5` | `… /cron/daily-report.php …` |
| `30 1 * * *` | `… /bin/backup.php …` |

Jam cron mengikuti zona waktu server hosting; bila server tidak memakai WIB, geser jamnya.
Status tiap tugas terlihat di aplikasi: *Pengaturan › Antrean Email › Tugas terjadwal*.

## 4. Selesai — login

Buka **https://npd.permataindokemas.com** → login dengan email & password Admin. Langkah awal di aplikasi:
1. *Profil* — ganti password bila perlu.
2. *Pengaturan › User* — buat akun Sales, NPD, Drafter, Purchasing, Production, Quality, Management.
3. *Pengaturan › Customer* dan *Hari Libur* tahun berjalan.
4. *Pengaturan › Notifikasi & Email* — isi SMTP (mis. email cPanel: host `mail.permataindokemas.com`, port 465/SSL
   atau 587/TLS) lalu kirim email uji.

## Pemecahan masalah

| Gejala | Penyebab & solusi |
| --- | --- |
| `PHP 8.2 ... tidak ditemukan` di setup | PHP Terminal berbeda dengan PHP domain. Cek `ls /opt/cpanel/` lalu `PHP_BIN=/opt/cpanel/ea-php83/root/usr/bin/php bash setup.sh` |
| `koneksi gagal ... 1045` | User/password salah atau user belum ditambahkan ke database (langkah 0.3). Ulangi `bash setup.sh`; untuk mengganti isian, hapus baris `DB_USER`/`DB_PASS` di `.env` |
| Halaman **500 Internal Server Error** | Lihat *cPanel › Metrics › Errors* atau file `error_log`. Bila tertulis `Options not allowed`, hapus baris `Options -Indexes` di `.htaccess` dan `public/.htaccess`. Bila `Permission denied ... .env`: `chmod 644 .env` |
| Tampilan tanpa CSS / 404 di semua halaman | `mod_rewrite` tidak aktif — hubungi penyedia hosting (dibutuhkan `.htaccess` dengan RewriteEngine) |
| **Redirect terus-menerus** (too many redirects) | Bila memakai Cloudflare, set SSL mode ke *Full*, bukan *Flexible* |
| Unggah file besar gagal | *cPanel › MultiPHP INI Editor* → domain → `upload_max_filesize 25M`, `post_max_size 30M`, `memory_limit 256M` |
| Cron tidak jalan | Cek `storage/logs/cron.log`; pastikan perintah cron sama persis dengan yang dicetak setup |
| Pemeriksaan ulang kapan saja | `bash setup.sh` atau `php bin/check-deployment.php --url=https://npd.permataindokemas.com` |

**Catatan keamanan.** Folder domain menjadi document root, sehingga `.htaccess` di folder utama mengarahkan semua
alamat ke `public/`; folder lain (`.env`, `storage/`, `vendor/`, `config/`) tidak dapat dibuka dari browser
(diperiksa otomatis oleh setup). Bila ingin lebih rapi, ubah *Document Root* domain ke `.../public` di
*cPanel › Domains* — aplikasi tetap berjalan tanpa perubahan lain.

## Memperbarui ke versi baru

1. Backup dulu: `php bin/backup.php` (otomatis harian juga berjalan lewat cron, tersimpan di `~/npd-backups`).
2. Unggah zip versi baru ke folder domain → Extract (timpa). `.env` dan isi `storage/` **tidak tertimpa**
   (zip hanya membawa `.env.production` dan folder `storage/` kosong).
3. `bash setup.sh` — menjalankan migrasi database bila ada.

## Impor data project lama (Excel)

1. Buat dulu semua **Customer** dan **User** (role yang benar) di menu Pengaturan.
2. *Pengaturan › Impor Data Lama* → **Unduh template Excel** (dropdown berisi customer, user, dan proses dari aplikasi).
3. Isi sheet *Project*, *Part*, *Proses* (petunjuk ada di sheet *Petunjuk*), simpan sebagai `.xlsx`.
4. Unggah → **Periksa file**: semua kesalahan tampil per baris tanpa menyimpan apa pun. Perbaiki sampai bersih → **Impor**.
5. File besar (ratusan project) lebih aman lewat Terminal:
   `php bin/import-legacy.php --file=data.xlsx` lalu `php bin/import-legacy.php --file=data.xlsx --commit --as=email-admin`.

Backup dulu sebelum impor (`php bin/backup.php`). Panduan lengkap & aturan: `docs/IMPORT_DATA_LAMA.md`.

## Backup & pemulihan

- Backup harian otomatis (cron 01:30) ke `~/npd-backups`, disimpan 30 hari: database + semua dokumen + manifest.
  Bila hosting menonaktifkan `proc_open` (setup menampilkan *backup otomatis memakai mode PHP*), backup tetap berjalan
  tanpa `mysqldump` — tidak perlu pengaturan tambahan. Uji kapan saja: `php bin/backup.php`.
- Simpan salinan juga di luar server (unduh berkala, atau gunakan *cPanel › Backup*).
- Pemulihan selalu ke database **baru** (buat dulu di cPanel), tidak pernah menimpa: lihat `docs/BACKUP_AND_RESTORE.md`.
- Simpan isi file `.env` (terutama `APP_KEY`) di password manager perusahaan.
