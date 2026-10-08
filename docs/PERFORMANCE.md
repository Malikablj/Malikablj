# Kinerja — NPD Project Control v3.0

Hasil uji kinerja terhadap target PRD §13.3 (NFR-08). Seluruh angka di bawah adalah hasil pengukuran nyata
pada 2026-10-08, bukan perkiraan.

## Target PRD §13.3

| Ukuran | Target |
| --- | --- |
| Pengguna / kapasitas | ±30 pengguna, < 200 project aktif |
| Waktu muat halaman utama | ≤ 2 detik pada jaringan kantor |
| Respon API (p95) | ≤ 500 ms untuk operasi umum |
| Hitung ulang jadwal satu project | ≤ 1 detik (hingga 10 part × 20 proses) |
| Gantt/daftar besar | pemuatan bertahap & paginasi; tidak membekukan antarmuka |
| Export PDF/Excel | ≤ 15 detik untuk satu project |

## Data uji

Database terpisah `npd_perf` (skrip menolak database lain dan `APP_ENV=production`), diisi **lewat service
aplikasi** — NPR → feedback → project → penyelesaian proses dengan jam dimajukan (Januari–Oktober 2026),
sesekali hasil NG/Not Approved (loop/repeat), komentar, Hold — sehingga jadwal, run KPI, revisi, notifikasi
dan audit log terbentuk seperti pemakaian nyata.

| Data | Jumlah |
| --- | --- |
| Pengguna | 30 |
| Project (aktif) | 311 (167 aktif, 144 selesai, 13 Hold) |
| Part | 566 |
| Proses | 8.439 |
| Run proses (dasar KPI) | 6.565 |
| Notifikasi | 22.719 |
| Audit log | 14.544 |
| Revisi | 1.357 |
| Project terbesar | 10 part, 129 proses |

Lingkungan: 4 vCPU, 16 GB RAM, PHP 8.3.6 (OPcache aktif), MySQL 8.0.46, server bawaan PHP (`php -S`,
satu proses, tanpa paralelisme). Klien di mesin yang sama, sehingga latensi jaringan kantor tidak termasuk.

## 1. Waktu respons server (`tests/perf/measure.php`)

1 pemanasan + 5 ulangan per halaman, login sebagai Admin (melihat seluruh data).

| Halaman | KB | Median (ms) | Maks (ms) |
|---|---|---|---|
| Dashboard | 51 | 22 | 23 |
| Dashboard (filter New Mold) | 52 | 19 | 20 |
| Daftar project | 45 | 10 | 11 |
| Daftar project hal. 10 | 35 | 10 | 11 |
| Daftar project cari "Botol" | 44 | 10 | 12 |
| Daftar project overdue | 53 | 10 | 12 |
| Detail project terbesar | 151 | 21 | 27 |
| Detail project — Proses | 111 | 22 | 24 |
| Detail project — Riwayat | 80 | 23 | 24 |
| Detail project — Aktivitas | 109 | 18 | 22 |
| Detail proses | 33 | 14 | 14 |
| Timeline project terbesar | 55 | 23 | 29 |
| Gantt lintas project | 78 | 38 | 51 |
| Gantt per part | 74 | 57 | 62 |
| Tracker | 212 | 26 | 30 |
| Kalender | 329 | 26 | 27 |
| Daftar NPR | 33 | 7 | 9 |
| Detail NPR | 149 | 16 | 19 |
| Approval | 58 | 9 | 10 |
| Dokumen | 109 | 17 | 21 |
| Notifikasi | 11 | 5 | 9 |
| Laporan mingguan | 87 | 48 | 56 |
| Analitik Jan–Okt | 34 | 144 | 163 |
| KPI per PIC Jan–Okt | 28 | 280 | 283 |
| Audit log | 71 | 8 | 9 |
| Pengguna | 69 | 8 | 11 |

**API** (60 panggilan berurutan masing-masing):

| API | p50 (ms) | p95 (ms) | Maks (ms) |
|---|---|---|---|
| Pratinjau jadwal — planning (project 10 part) | 17 | 23 | 27 |
| Pratinjau jadwal — dependency (project 10 part) | 18 | 26 | 33 |
| Simpan preferensi tema | 4 | 5 | 7 |

**Hitung ulang jadwal** project terbesar (10 part, 129 proses, `ScheduleService::recalculate` termasuk baca
graf, hitung, diff, tulis): median 17 ms, maks 21 ms. Mesin jadwal murni dengan 10 part × 20 proses
(201 node) diuji di `SchedulerTest::testPerformanceTenPartsTwentyProcesses` (< 1 dtk, dijalankan setiap `phpunit`).

**Export** (2 ulangan, nilai maksimum):

| Export | KB | Maks (ms) |
|---|---|---|
| Timeline PDF project terbesar | 141 | 484 |
| Timeline Excel project terbesar | 102 | 124 |
| NPR PDF | 142 | 305 |
| Daftar project Excel | 119 | 211 |
| Laporan mingguan Excel | 115 | 102 |
| Analitik Excel Jan–Okt | 105 | 227 |
| KPI Excel Jan–Okt (6.021 baris rincian) | 344 | 3.626 |
| KPI PDF Jan–Okt | 131 | 415 |

Tanpa OPcache (`--no-opcache`, 3 ulangan) hasilnya setara: halaman maks 312 ms, API p95 maks 24 ms,
export maks 3.554 ms.

## 2. Waktu muat di browser (`tests/perf/browser_timing.py`)

Chromium headless, Navigation Timing (`loadEventEnd`), 3 ulangan, plus *long task* terpanjang
(PerformanceObserver) sebagai indikator antarmuka membeku.

| Perangkat | Halaman | DOMContentLoaded (ms) | Load median (ms) | Load maks (ms) | Long task terpanjang (ms) | Elemen DOM |
|---|---|---|---|---|---|---|
| desktop | Dashboard | 56 | 87 | 125 | 0 | 933 |
| desktop | Daftar project | 40 | 66 | 72 | 0 | 785 |
| desktop | Detail project | 56 | 95 | 100 | 0 | 2446 |
| desktop | Timeline project | 78 | 84 | 91 | 0 | 995 |
| desktop | Gantt lintas project | 102 | 108 | 120 | 0 | 1239 |
| desktop | Gantt per part | 101 | 112 | 116 | 0 | 1222 |
| desktop | Tracker | 75 | 156 | 168 | 66 | 3659 |
| desktop | Kalender | 70 | 99 | 107 | 0 | 3708 |
| desktop | Daftar NPR | 38 | 68 | 71 | 0 | 619 |
| desktop | Laporan mingguan | 89 | 238 | 243 | 137 | 1706 |
| desktop | KPI per PIC Jan–Okt | 312 | 335 | 407 | 0 | 561 |
| HP 390 px | Dashboard | 53 | 81 | 82 | 0 | 933 |
| HP 390 px | Daftar project | 39 | 69 | 70 | 0 | 785 |
| HP 390 px | Detail project | 49 | 84 | 97 | 0 | 2446 |
| HP 390 px | Timeline project | 64 | 74 | 74 | 0 | 995 |
| HP 390 px | Gantt lintas project | 88 | 94 | 116 | 0 | 1239 |
| HP 390 px | Gantt per part | 85 | 92 | 129 | 0 | 1222 |
| HP 390 px | Tracker | 63 | 124 | 134 | 70 | 3659 |
| HP 390 px | Kalender | 70 | 147 | 155 | 69 | 3708 |
| HP 390 px | Daftar NPR | 35 | 64 | 65 | 0 | 619 |
| HP 390 px | Laporan mingguan | 72 | 125 | 155 | 56 | 1706 |
| HP 390 px | KPI per PIC Jan–Okt | 294 | 314 | 327 | 0 | 561 |

Semua halaman < 0,5 dtk (target 2 dtk); long task terpanjang 137 ms.

## 3. Temuan & perbaikan

| Temuan | Akar masalah | Perbaikan | Sebelum → sesudah |
| --- | --- | --- | --- |
| Daftar NPR memuat seluruh NPR (311 baris, 237 KB, 4.618 elemen, long task 270 ms) dan **menyembunyikan NPR ke-501 dst.** karena `LIMIT 500` tetap | `NprService::list` tanpa paginasi | Paginasi 25 per halaman + total (seperti daftar project); test `NprServiceTest::testListIsPaginatedWithoutHidingOlderNprs` | 237 KB → 33 KB; load 340 → 68 ms; tidak ada NPR yang tersembunyi |
| Export KPI Excel 9 bulan 8,6 dtk (mendekati batas 15 dtk) | `ReportWorkbook` menerapkan format tanggal/angka & bungkus teks per sel (±72.000 operasi gaya pada 6.021 baris) | Format angka/tanggal per rentang kolom, warna baris per blok baris berurutan, rata atas sebagai gaya bawaan workbook, bungkus teks hanya kolom teks; test `ReportsTest::testWorkbookFormatsByColumnRangeAndFlagsOnlyMarkedRows` | 8.550 → 3.626 ms |

Indeks: slow query log MySQL (ambang 10 ms) saat membangun KPI 9 bulan hanya mencatat kueri run KPI
(±50 ms, 6.021 baris dikirim, 43.290 baris diperiksa) — memakai indeks yang ada; tidak perlu indeks baru.

## 4. Cara mengulang

```bash
export DB_NAME=npd_perf
php bin/install.php --database=npd_perf --fresh
NPD_DEMO_PASSWORD='Rahasia123' php bin/demo-seed.php
php tests/perf/seed_volume.php --projects=200 --seed=42
php tests/perf/seed_volume.php --projects=110 --seed=7 --from=2026-05-04 --big
NPD_DEMO_PASSWORD='Rahasia123' php tests/perf/measure.php --runs=5 --api-runs=60 --out=perf.json
# browser: jalankan server terhadap npd_perf lalu ukur (ID project terbesar dicetak oleh seed --big)
php -d opcache.enable_cli=1 -S 127.0.0.1:8090 -t public &
python3 tests/perf/browser_timing.py http://127.0.0.1:8090 'Rahasia123' 311
```

`measure.php` dan `browser_timing.py` keluar dengan kode 1 bila ada target yang terlampaui.

## 5. Batasan pengukuran

- Server bawaan PHP satu proses; produksi memakai PHP-FPM + web server dengan beberapa worker, sehingga
  permintaan bersamaan dari ±30 pengguna tidak saling antre seperti di sini.
- Latensi jaringan kantor tidak termasuk; ukuran halaman terbesar 329 KB (kalender bulan penuh) — gzip di
  web server disarankan (lihat `docs/DEPLOYMENT.md`).
- Daftar pilihan project pada filter Dokumen/Approval/Kalender menampilkan 500 project terbaru; project lama
  tetap dapat dibuka dari daftar project (berhalaman) dan tab Dokumen/Approval di detail project.
