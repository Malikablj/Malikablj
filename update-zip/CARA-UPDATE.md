# Cara update aplikasi PIK Marketing Control (cPanel)

Langkah ini **selalu sama** untuk setiap update. File yang perlu di-upload hanya **`pik-update.zip`** (folder ini).

## 1. Upload zip

cPanel › **File Manager** → buka folder **home** (folder paling atas, tempat folder `public_html` berada) → **Upload** → pilih `pik-update.zip`.
Bila `pik-update.zip` lama masih ada, timpa saja (centang *overwrite*).

## 2. Jalankan di Terminal

cPanel › **Terminal**, tempel perintah ini lalu tekan **Enter**:

```bash
cd ~ && rm -rf pik-update && unzip -oq pik-update.zip -d pik-update && bash pik-update/pasang-update.sh
```

Tunggu sampai muncul **"Update selesai."** (hijau), lalu ikuti *Langkah berikutnya* yang tampil di layar.

Otomatis dilakukan: backup file + database ke folder `pik-backup`, pemeriksaan file, pemasangan file baru
(`.env`, `.htaccess` utama, dan folder `storage/` tidak disentuh), penghapusan file lama, dan pembaruan database tanpa menghapus data.

## Bila ada masalah

- Muncul **"GAGAL"** (merah): salin seluruh teks di Terminal dan kirimkan.
- Kembalikan aplikasi ke versi sebelum update:

```bash
cd ~ && bash pik-update/pasang-update.sh --rollback
```
