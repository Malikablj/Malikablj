# ARCHITECTURE — PIK Marketing Control

```
Workbook legacy ──(Phase 03: migration engine)──┐
                                                ▼
Browser (HtmlService, Vanilla JS) ──google.script.run──► entry point (Code.gs, services/)
                                                                 │  handleRequest_ → { success, data | error }
                                                                 ▼
                                   db/Repository ── db/Validation ── db/Audit      (satu lock per penulisan)
                                                                 ▼
                                   Spreadsheet database (satu sheet per tabel) + ENUMS, SETTINGS, AUDIT_LOG
```

## Lapisan dan berkas

| Lapisan | Berkas | Tanggung jawab |
|---|---|---|
| Entry point | `src/Code.gs`, `src/services/*.gs` | Fungsi publik (tanpa `_`) yang dipanggil klien atau editor. Membungkus hasil dengan `handleRequest_`. |
| Konfigurasi | `src/Config.gs` | Script Properties: `DATABASE_SPREADSHEET_ID`, `DRIVE_ROOT_FOLDER_ID`, `APP_NAME`, `TIMEZONE`, `ADMIN_EMAILS`. |
| Inti | `src/core/Errors.gs` | `AppError` (kode + pesan aman) dan amplop respons; error internal disamarkan. |
| | `src/core/Ids.gs` | ID `PREFIX-XXXXXXXXXX`, cek bentrok terhadap ID yang ada. |
| | `src/core/Lock.gs` | Script lock re-entrant untuk setiap penulisan; `flush` sebelum lock dilepas. |
| | `src/core/Access.gs` | Siapa yang boleh menjalankan fungsi pemeliharaan; email pelaku. |
| | `src/core/Text.gs`, `Time.gs` | Normalisasi teks/kunci dokumen, tanggal & waktu. |
| Database | `src/db/Schema.gs` | Definisi 21 sheet: kolom, tipe, relasi, keunikan, aturan, konsistensi. Sumber `docs/DATABASE_SCHEMA.md`. |
| | `src/db/Enums.gs`, `Settings.gs` | Seed dan pembacaan ENUMS/SETTINGS. |
| | `src/db/SheetFormat.gs` | Format angka/teks, dropdown & checkbox, catatan header, proteksi, kapasitas baris. |
| | `src/db/Database.gs` | `setupDatabase`, `initializeDatabase` (idempoten, dua tahap), `verifyDatabase` (read-only). |
| | `src/db/Validation.gs` | Validasi record (tipe, wajib, enum, relasi, konsistensi, aturan, keunikan). |
| | `src/db/Repository.gs` | Baca per tabel (cache per eksekusi), insert batch, update dengan optimistic concurrency, arsip/pulihkan. |
| | `src/db/Audit.gs` | Penulisan `AUDIT_LOG` (append-only) dalam lock yang sama. |
| Web | `src/web/*.html` | Shell HTML; teks server selalu lewat `textContent`. |
| Uji | `src/tests/*.gs` | Kasus uji database yang dijalankan di Node (emulator) dan di Apps Script (`runDatabaseSelfTest`). |

## Aturan

- Frontend tidak pernah menulis ke Sheets secara langsung; semua lewat service → repository.
- Setiap penulisan: lock → baca ulang data segar → validasi seluruh batch → satu `setValues` data + satu `setValues` audit → flush.
  Batch bersifat semua-atau-tidak-sama-sekali.
- Pembacaan: satu `getValues` per tabel per eksekusi (cache dibuang setelah penulisan).
- Header dicek sebelum tabel dipakai. Bila berbeda dari skema, operasi berhenti dengan `SCHEMA_MISMATCH` alih-alih menulis ke kolom
  yang salah.
- Konteks penulisan (`migration`, `internal`) hanya diberikan oleh kode server, tidak pernah dari input klien.
- Semua berkas `.gs` hanya berisi deklarasi di tingkat atas (tanpa pemanggilan lintas berkas saat dimuat), sehingga urutan berkas di
  Apps Script tidak berpengaruh. Hal ini diuji otomatis.
