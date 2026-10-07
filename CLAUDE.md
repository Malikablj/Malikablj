# Catatan untuk sesi berikutnya

## Aplikasi `marketing.permataindokemas.com/` (PHP — PIK Marketing Control)

Aplikasi PHP 8 + MySQL yang berjalan di hosting cPanel milik user. (Folder lain di root repo —
`app.py`, `npd/`, `tests/` — adalah proyek Python terpisah, tidak terkait.)

### Wajib: setiap perubahan dikirim sebagai `update-zip/pik-update.zip`

User meng-update server **hanya** dengan meng-upload satu zip lalu menjalankan satu perintah Terminal
yang sama setiap kali. Jangan memberi langkah upload file satu per satu atau perintah lain.

Setiap kali mengubah aplikasi:

1. Bila struktur database berubah: tambahkan langkah di `app/helpers/Migrator.php` (idempotent, tidak
   menghapus data), naikkan `Migrator::VERSION`, dan samakan `database/schema.sql`.
2. Jalankan test: `php tests/run.php` (butuh MariaDB + database `pik_marketing_test`, lihat README bagian 7).
3. Tulis catatan rilis singkat di `update-zip/LANGKAH-SETELAH-UPDATE.txt` (apa yang harus user lakukan
   setelah update + ringkasan perubahan).
4. Commit perubahan aplikasi, lalu `bash update-zip/buat-zip.sh` (zip dibuat dari versi yang sudah di-commit;
   file yang dihapus dari repo otomatis masuk `hapus-file-lama.txt`), lalu commit `update-zip/pik-update.zip`.
5. Kirim `update-zip/pik-update.zip` ke user dan ulangi langkah yang sama (lihat `update-zip/CARA-UPDATE.md`):
   - upload `pik-update.zip` ke folder home cPanel (timpa yang lama)
   - cPanel › Terminal:
     `cd ~ && rm -rf pik-update && unzip -oq pik-update.zip -d pik-update && bash pik-update/pasang-update.sh`
   - rollback: `cd ~ && bash pik-update/pasang-update.sh --rollback`

### Lainnya

- `.env`, log, dan data perusahaan tidak pernah di-commit (repo public). Pemasang tidak menyentuh `.env`,
  `.htaccess` utama, dan `storage/` di server.
- User berbahasa Indonesia; tulis jawaban, pesan UI, dan catatan rilis dalam Bahasa Indonesia.
