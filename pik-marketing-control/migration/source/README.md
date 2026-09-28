# migration/source

Tempat file sumber migrasi. Isi folder ini di-ignore oleh git karena berisi data bisnis rahasia PIK.

- `PIK_Master_Database_AppSheet.xlsx` — sumber migrasi utama (lokasi default profiler).
- Opsional, bila tersedia: tiga file legacy asli yang menjadi dasar workbook di atas (namanya tercantum di sheet `README`
  workbook, baris "Sources").
  File-file ini dibutuhkan untuk menautkan ulang delivery yang belum tertaut dan memverifikasi tanggal
  (lihat `docs/MIGRATION_ISSUES.md`).

File sumber tidak pernah diubah oleh skrip mana pun; profiler memverifikasi hash SHA-256 sebelum dan sesudah membaca.
