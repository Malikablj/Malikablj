# Impor Data Project Lama dari Excel

Memasukkan project, NPR, part, dan progres proses dari data lama ke NPD Project Control, lalu **melanjutkannya di
sistem**. Menu: **Pengaturan › Impor Data Lama** (hanya Admin, izin `project.import`). Untuk file besar tersedia juga
perintah Terminal `php bin/import-legacy.php`.

## 1. Alur kerja

1. **Siapkan master data dulu**: semua Customer (*Pengaturan › Customer*) dan User (*Pengaturan › User*) dengan
   role yang benar (Admin Sales, NPD Staff, Drafter, Purchasing, Production, Quality). Impor **tidak** membuat
   customer atau user; baris yang merujuk kode customer/email yang belum ada ditolak.
2. **Unduh template** di halaman impor. Template berisi dropdown dari data aplikasi **saat diunduh** (customer, user
   per role, proses dari template workflow aktif). Unduh ulang setelah menambah customer/user.
3. **Isi** sheet *Project*, *Part*, *Proses* (lihat §2). Sheet *Petunjuk* dan *Daftar* hanya acuan dan tidak dibaca.
4. **Periksa file**: unggah `.xlsx` (maks. 10 MB, 5.000 baris per sheet). Sistem menampilkan **semua** kesalahan per
   sheet/baris/kolom dan peringatan, **tanpa menyimpan apa pun**. Perbaiki di Excel, unggah ulang.
5. **Impor**: bila tidak ada kesalahan, klik *Impor N project*. File diperiksa ulang di server lalu seluruh project
   disimpan dalam **satu transaksi** — bila satu gagal, tidak ada yang tersimpan.

Terminal (cPanel):

```bash
cd ~/public_html/npd.permataindokemas.com
php bin/import-legacy.php --template=template-impor.xlsx                  # template
php bin/import-legacy.php --file=data-lama.xlsx                           # periksa saja
php bin/import-legacy.php --file=data-lama.xlsx --commit --as=email-admin@permataindokemas.com
```

## 2. Format file

Kolom dikenali dari **judul di baris 1** (urutan boleh berubah, huruf besar/kecil dan tanda `*` diabaikan, kolom
tambahan diabaikan). Jangan mengubah judul atau nama sheet. Judul hitam = wajib, kuning = wajib pada kondisi
tertentu, abu-abu = opsional; arahkan kursor ke judul untuk melihat keterangan.

**Tanggal**: tanggal Excel (mis. `25/03/2026`) atau teks `2026-03-25` / `25/03/2026` (hari dulu). Tidak boleh di masa
depan kecuali *Target Finish* dan *Rencana Selesai*. **Rumus** tidak dihitung saat impor (demi keamanan): yang dipakai
nilai tersimpan Excel; bila tidak ada, salin lalu *Paste Values*.

### Sheet Project — satu baris per project (= satu NPR)

| Kolom | Wajib | Isi |
| --- | --- | --- |
| Ref Project | Ya | Kunci unik di file (disarankan no. project lama); dipakai di sheet Part & Proses. Ref yang sudah pernah diimpor ditolak. |
| Nama Produk / Project | Ya | Nama produk NPR. |
| Kode Customer | Ya | Kode customer yang sudah ada. |
| Email Sales PIC | Ya | User aktif ber-role Admin Sales. |
| Email NPD PIC | Ya | User aktif ber-role NPD Staff atau Admin. |
| Prioritas | – | Rendah / Normal / Tinggi / Mendesak (kosong = Normal). |
| Tanggal NPR | Ya | NPR dikirim / project mulai. |
| Tanggal Feedback NPD | – | Feedback NPR selesai = part mulai (kosong = Tanggal NPR). |
| Target Finish | – | Target selesai project. |
| No. NPR | – | Nomor lama, dipakai apa adanya (unik). Kosong = dibuat sistem. |
| Kode Project | – | Kode lama, maks. 20 karakter (unik). Kosong = dibuat sistem. |
| Status Project | Ya | Berjalan / Hold / Selesai / Batal. |
| Tanggal Selesai Project | Bila Selesai | Tanggal project selesai. |
| Alasan Hold / Batal | Bila Hold/Batal | Alasan (tulis tanggal asli Hold/Batal di sini bila perlu). |
| Qty per Bulan, Qty per Tahun | – | Angka. |
| Catatan | – | Catatan NPR. |

### Sheet Part — satu baris per part

| Kolom | Wajib | Isi |
| --- | --- | --- |
| Ref Project | Ya | Ref dari sheet Project. |
| Nama Part | Ya | Unik dalam satu project. Nama yang cocok dengan master (Body, Cap, Plug, …) memakai master; lainnya disimpan sebagai nama bebas. |
| Jenis Part | Ya | New Mold / Subcont — menentukan template workflow. |
| Supplier Mold | – | |
| Email PIC Drafter / Purchasing / Production / Quality | – | User dengan role tersebut; menjadi PIC bawaan proses part. |
| Status Part | – | Aktif / Batal (kosong = Aktif). |
| Alasan Batal Part | Bila Batal | |
| Keterangan | – | Disimpan sebagai feedback NPD part. |

### Sheet Proses — satu baris per proses yang Selesai, Berjalan, atau Dilewati

Proses yang **tidak ditulis = belum mulai**. P1 (NPR), P2 (Feedback), dan PF (Project Finish) diisi otomatis dari
sheet Project — jangan ditulis.

| Kolom | Wajib | Isi |
| --- | --- | --- |
| Ref Project | Ya | |
| Nama Part | Ya, kecuali G1 | Nama dari sheet Part. Kosongkan hanya untuk G1 *Assembly / Fit Test* (level project). |
| Proses | Ya | Dari dropdown, mis. `N3 — 3D Prototype Development`; kode saja (`N3`) atau namanya juga diterima. |
| Status | Ya | Selesai / Berjalan / Dilewati. |
| Tanggal Mulai | Selesai & Berjalan | |
| Tanggal Selesai | Selesai | Kosong untuk Berjalan. |
| Rencana Selesai | – | Hanya Berjalan. Kosong = Tanggal Mulai + durasi bawaan template (hari kerja). |
| Email PIC | – | PIC bila berbeda dari bawaan; role harus sesuai proses (proses NPD boleh Admin). |
| Keterangan | – | Untuk Dilewati = alasan; lainnya menjadi komentar proses bertanda *[Data lama]*. |

## 3. Aturan pemeriksaan

- **Urutan proses mengikuti dependency template workflow aktif**: proses Selesai/Berjalan hanya boleh bila setiap
  pendahulu FS sudah Selesai atau Dilewati (SS: sudah mulai; FF: untuk Selesai, pendahulu sudah selesai). Contoh pesan:
  *"Part Body: N5 2D Drawing Berjalan, tetapi pendahulunya N4 Customer 3D Approval belum ditulis di sheet Proses. Isi N4
  sebagai Selesai."*
- Hanya proses yang boleh dilewati menurut template yang boleh *Dilewati*; satu grup dilewati bersama (Masterbatch
  N1+N2, 3D N3+N4). Grup Masterbatch dilewati = "tidak perlu masterbatch baru".
- **Part selesai** = proses Finish (N14/S11) Selesai, dan semua proses lain part itu Selesai/Dilewati.
- **Gate G1** (bila aktif di template project): proses part yang bergantung pada gate (Material Preparation) butuh
  G1 Selesai/Dilewati; G1 Selesai/Berjalan butuh milestone semua part aktif (T0 Trial / Customer Trial Approval)
  Selesai. Project Selesai juga butuh G1 Selesai/Dilewati.
- **Project Selesai**: semua part aktif selesai + Tanggal Selesai Project. **Batal**: tidak boleh punya part yang
  sudah selesai (FR-HLD-05). **Hold**: harus punya part yang belum selesai.
- **Mold Correction (N9)** tidak dapat diimpor (hanya terjadi lewat keputusan T0 Not OK di sistem). Bila koreksi
  sedang berjalan, isi T0 Trial (N8) sebagai Berjalan dan catat di Keterangan.
- Ref, No. NPR, dan Kode Project tidak boleh kembar (di file maupun dengan data aplikasi).
- **Peringatan** (tidak menghalangi): proses Berjalan yang rencana selesainya sudah lewat (akan langsung overdue),
  part tanpa proses Berjalan (proses berikutnya dimulai tanggal impor), tanggal mulai sebelum pendahulu selesai, dll.

## 4. Hasil impor di sistem

| Data | Hasil |
| --- | --- |
| NPR | Status *Selesai Feedback*, semua part *Feasible*; alamat & telepon dari data customer; Requested by = Sales PIC, Received by = NPD PIC. Detail spesifikasi lain (resin, warna, kemasan, …) kosong. |
| Nomor | No. NPR/Kode Project lama dipakai apa adanya. Nomor berformat sistem (`041/PIK/NPR/I/2026`, `NPD-2026-090`) menggeser penomoran berikutnya agar tidak bentrok; nomor kosong dibuat sistem sesuai **tahun Tanggal NPR**. |
| Proses Selesai | Tanggal aktual dari file. **Planned Start/Finish dikosongkan** — rencana lama tidak diketahui sehingga tidak dikarang. |
| Proses Berjalan | Aktif sejak Tanggal Mulai; Planned Finish = Rencana Selesai atau Tanggal Mulai + durasi template. Approval Pending dibuat untuk proses approval. |
| Proses belum mulai | Dijadwalkan sistem **mulai tanggal impor** (batas bawah jadwal) mengikuti dependency, lalu otomatis aktif bila pendahulunya selesai — sama seperti project biasa. |
| Baseline | *Baseline v1 — impor data lama* per part, dari jadwal setelah impor. |
| Hold / Batal | Dijalankan lewat fitur Hold/Cancel biasa (riwayat, aturan sama); tanggalnya = tanggal impor. |
| KPI | Run proses dari data lama — yang Selesai **dan** yang sedang Berjalan saat impor — ditandai `process_runs.is_imported = 1` dan **tidak dihitung** di KPI per PIC, Analytics, maupun Weekly Report. Proses yang diaktifkan setelah impor dihitung normal. |
| Notifikasi | Tidak ada notifikasi/email selama impor. Tugas langsung tampil di dasbor PIC; pengingat overdue & ringkasan harian berjalan normal lewat cron. |
| Dokumen, approval lama, record trial | Tidak diimpor. Unggah dokumen yang diperlukan di halaman proses (proses dengan dokumen wajib tetap memerlukannya saat diselesaikan di sistem). |
| Jejak | `projects.legacy_ref` + `imported_at`; Revision History *Impor data lama*; audit `project.import` per project dan `import.legacy` per file (nama file, SHA-256, jumlah). |

Data hasil impor tidak dapat dihapus massal (aturan sistem: data bisnis tidak dihapus permanen). Bila salah impor,
batalkan/arsipkan project yang bersangkutan. Disarankan: **backup dulu** (`php bin/backup.php`) dan uji impor di server
UAT/salinan database bila datanya banyak.

## 5. Kesalahan yang sering muncul

| Pesan | Perbaikan |
| --- | --- |
| Kode customer "…" tidak ditemukan | Buat customer di Pengaturan › Customer, atau perbaiki kode (lihat sheet Daftar). |
| User dengan email … tidak ditemukan / bukan Admin Sales | Buat user / perbaiki role, lalu unduh ulang template. |
| … tetapi pendahulunya … belum ditulis di sheet Proses | Tambahkan baris pendahulu sebagai Selesai (atau Dilewati bila boleh). |
| Proses grup … harus dilewati bersama | Tulis semua proses grup sebagai Dilewati, atau tidak ada yang Dilewati. |
| Sel berisi rumus tanpa nilai tersimpan | Salin kolom → Paste Values. |
| Tanggal tidak valid | Gunakan format tanggal Excel atau `25/03/2026`. |
| Ref Project "…" sudah pernah diimpor | Project itu sudah ada di sistem; hapus barisnya dari file. |
| Kolom wajib tidak ditemukan | Judul kolom diubah/terhapus; salin data ke template baru. |

## 6. Teknis

- Kode: `modules/Import/` — `LegacyFormat` (definisi kolom & nilai), `LegacyTemplate` (template + dropdown),
  `LegacyWorkbook` (pembaca .xlsx: judul kolom, tipe nilai; rumus tidak dihitung — mesin rumus PhpSpreadsheet memiliki
  fungsi jaringan seperti `WEBSERVICE`), `LegacyImportService` (`analyze()` tanpa menyimpan, `commit()` satu transaksi).
- Halaman `public/settings/import.php`: file pemeriksaan disimpan sementara di `storage/imports/` (di luar web root,
  0600), diikat ke sesi dengan token acak + SHA-256, dihapus setelah impor/batal atau setelah 1 hari.
- Skema: migrasi `database/migrations/20261008_001_legacy_import.sql` (idempoten, MySQL 8 & MariaDB).
- Test: `tests/Integration/LegacyImportTest.php`, `tests/Http/LegacyImportHttpTest.php`, `tests/Ops/LegacyImportCliTest.php`.
- Keputusan desain: OQ-36 (penomoran), OQ-37 (tanggal Hold/Batal & rencana proses lama), OQ-38 (pengecualian KPI).
