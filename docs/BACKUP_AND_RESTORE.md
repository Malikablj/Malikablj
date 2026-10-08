# Backup & Pemulihan — NPD Project Control v3.0

Memenuhi NFR-09 (PRD §13.4): backup harian database + file, retensi ≥ 30 hari, uji pemulihan.

## 1. Apa yang di-backup

`php bin/backup.php` (cron harian 01:30, `deploy/cron.d-npd`) membuat satu folder per backup:

```
BACKUP_PATH/npd-<database>-<YYYYmmdd-HHMMSS>/     (mode 0700)
├── database.sql.gz     mysqldump --single-transaction --routines --triggers (konsisten, tanpa mengunci tabel)
├── documents.tar.gz    isi storage/documents (diambil SETELAH dump → semua file yang dirujuk dump pasti ada)
└── manifest.json       versi aplikasi & MySQL, jumlah baris tiap tabel, daftar trigger,
                        jumlah & ukuran dokumen, SHA-256 dan ukuran tiap arsip
```

- Dump dianggap gagal bila `mysqldump` keluar dengan kode ≠ 0 **atau** baris penutup `-- Dump completed` tidak ada;
  backup setengah jadi dihapus dan run tercatat **Gagal** di `job_runs` (terlihat di Pengaturan › Antrean Email ›
  Tugas terjadwal, dan oleh `bin/check-deployment.php`).
- Password MySQL diberikan lewat file opsi sementara (0600), tidak pernah muncul di daftar proses atau log.
- Akun backup `BACKUP_DB_USER` cukup `SELECT, SHOW VIEW, TRIGGER` (`php bin/db-grants.php --role=backup`).
  **Tanpa hak TRIGGER, mysqldump tidak menyertakan trigger append-only `audit_logs` tanpa pesan error** — itulah
  sebabnya daftar trigger dicatat di manifest dan diverifikasi saat pemulihan.
- Isi `.env` (terutama `APP_KEY`) **tidak** termasuk backup: simpan di brankas password. Tanpa `APP_KEY` yang sama,
  password SMTP terenkripsi harus diisi ulang (data lain tidak terpengaruh).

## 2. Retensi

Setelah backup sukses, folder `npd-<db>-<tanggal>` yang lebih tua dari `BACKUP_RETENTION_DAYS` (bawaan 30) dihapus,
berdasarkan tanggal di nama folder. Pengaman: backup **terbaru selalu disimpan** (walau lebih tua dari retensi,
mis. setelah backup sempat berhenti), dan hanya folder bernama pola tersebut yang berisi `manifest.json` yang
disentuh — folder lain di `BACKUP_PATH` tidak pernah dihapus.

## 3. Pemulihan (restore)

Prinsip: **pemulihan tidak pernah menimpa data.** Target database harus baru/kosong dan folder dokumen harus
baru/kosong; bila tidak, perintah menolak. Setelah hasil pulih lolos verifikasi, aplikasi dialihkan ke sana lewat
`.env`. Database lama tetap utuh untuk dibandingkan atau rollback.

```bash
cd /var/www/npd
# 1. pilih backup
ls /var/backups/npd/
# 2. pulihkan ke database & folder BARU (akun admin MySQL)
DB_USER=npd_admin DB_PASS='…' php bin/restore.php \
    --from=/var/backups/npd/npd-npd_project_control-20261008-013000 \
    --database=npd_restore_20261008 \
    --documents=/srv/npd/storage-20261008/documents
# 3. bila "Cocok dengan manifest": siapkan folder storage lain & alihkan aplikasi
mkdir -p /srv/npd/storage-20261008/{exports,logs,cache,sessions} && chown -R www-data:www-data /srv/npd/storage-20261008
editor .env      # DB_NAME=npd_restore_20261008, STORAGE_PATH=/srv/npd/storage-20261008
DB_USER=npd_admin DB_PASS='…' php bin/db-grants.php --user=npd_app --database=npd_restore_20261008 | mysql -u root -p
systemctl reload php8.3-fpm
sudo -u www-data php bin/check-deployment.php --url=https://…
```

Yang dilakukan `bin/restore.php`:
1. Memeriksa `manifest.json` dan SHA-256 setiap arsip — arsip rusak/berubah ditolak **sebelum** menyentuh apa pun.
2. Menolak bila database target sudah berisi tabel atau folder dokumen target tidak kosong.
3. Mengimpor dump (klausa `DEFINER` server asal dibuang agar trigger dibuat atas nama akun pemulih) dan
   mengekstrak dokumen.
4. Memverifikasi: jumlah baris setiap tabel, tabel yang hilang/berlebih, daftar trigger, jumlah & ukuran dokumen.
   Kode keluar 1 bila ada yang tidak cocok.
5. Bila impor gagal di tengah, database dan folder yang **dibuat oleh perintah itu sendiri** dibersihkan sehingga
   pemulihan dapat diulang; database/folder yang sudah ada sebelumnya tidak pernah dihapus.

**Trigger & binary log.** Pada MySQL dengan binary log aktif (bawaan MySQL 8), membuat trigger membutuhkan hak
`SUPER` atau `log_bin_trust_function_creators=1`. Bila akun pemulih tidak memilikinya, pemulihan berhenti dengan
pesan yang menyarankan:

```bash
DB_USER=npd_admin DB_PASS='…' php bin/restore.php --from=… --database=… --documents=… --without-triggers
mysql -u root -p npd_restore_20261008 < database/hardening.sql      # pasang trigger append-only audit_logs
DB_USER=npd_admin DB_PASS='…' php bin/restore.php --from=… --verify-only --database=npd_restore_20261008 --documents=…
```

`--verify-only` memeriksa ulang database & folder yang sudah dipulihkan terhadap manifest tanpa mengubah apa pun.

## 4. Uji pemulihan (restore drill)

**Otomatis setiap `vendor/bin/phpunit`** (`tests/Ops/BackupRestoreTest.php`, database `npd_test_ops*`):
backup → restore ke database & folder baru → `CHECKSUM TABLE` seluruh tabel identik (kecuali `job_runs`, lihat
catatan) dan SHA-256 setiap dokumen identik, teks utf8mb4 (Ünicode/日本) utuh; restore kedua ke database/folder
berisi data ditolak dan tidak membuat database; perubahan setelah pulih terdeteksi `--verify-only`; arsip yang
diubah satu byte ditolak; trigger berformat mysqldump dipulihkan atau — tanpa SUPER — gagal jelas dan bersih;
`--without-triggers` berhasil; retensi menghapus hanya backup lama yang sah dan selalu menyimpan yang terbaru.

**Drill manual yang dijalankan 2026-10-08** pada data dev hasil alur browser (2 project, NPR, 2 dokumen unggahan):

| Langkah | Hasil |
| --- | --- |
| `php bin/backup.php` | 45 tabel, 792 baris, 3 file di folder dokumen (2 unggahan + `.gitkeep`) — 0,34 dtk |
| `php bin/restore.php` ke `npd_restore_drill` + folder baru | "Cocok dengan manifest" — 1,4 dtk |
| `CHECKSUM TABLE` 45 tabel sumber vs hasil | 44 identik; `job_runs` berbeda (baris backup itu sendiri berstatus "running" saat dump, lalu "success") |
| Aplikasi dijalankan di atas hasil pulih (`DB_NAME`/`STORAGE_PATH` dialihkan) | login 303, 7 halaman (dashboard, project, dokumen, NPR, laporan, audit) 200 |
| Unduh dokumen lewat aplikasi hasil pulih | SHA-256 identik dengan file asli |
| Restore ulang ke database berisi tabel / folder berisi file | ditolak, kode keluar 1 |
| Backup dengan akun aplikasi tanpa hak TRIGGER | trigger **tidak** ikut ter-dump → ditangani dengan `BACKUP_DB_USER` (`npd_backup`): 2 trigger ter-dump & tercatat di manifest |
| Restore backup bertrigger oleh akun tanpa SUPER (binary log aktif) | gagal dengan saran `--without-triggers`; database & folder sebagian dibersihkan |
| `--without-triggers` lalu `hardening.sql` oleh admin, lalu `--verify-only` | cocok, termasuk 2 trigger |
| Restore bertrigger oleh admin ber-SUPER | trigger dibuat (DEFINER = akun pemulih); `DELETE FROM audit_logs` ditolak |

Rekomendasi: ulangi drill manual **setiap 3 bulan** dan setelah upgrade versi MySQL/aplikasi — pulihkan backup
terbaru ke database baru di server UAT, jalankan `bin/check-deployment.php` dan beberapa alur utama.

## 5. Salinan di luar server

Backup di disk yang sama dengan database tidak melindungi dari kerusakan disk/server. Salin folder backup ke
lokasi lain setiap hari setelah 01:30, mis.:

```bash
# /etc/cron.d/npd-offsite — contoh rsync ke NAS (sesuaikan host & kunci SSH)
15 3 * * *  www-data  rsync -a --delete-after /var/backups/npd/ backup@nas.local:/backup/npd/
```

Atur retensi di lokasi tujuan ≥ 30 hari. Pilihan tujuan & enkripsi mengikuti kebijakan TI perusahaan
(OPEN QUESTION OQ-33: lokasi salinan off-site belum ditentukan di PRD).
