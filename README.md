# NPD Project Control (Python)

> **Repositori ini berisi dua aplikasi terpisah:**
> - **NPD Project Control** (Python/Flask) — dokumentasi di bawah ini.
> - **PR PIK — Aplikasi Purchase Requisition** (PHP 8.2+ & MySQL 8.0+) — lihat folder [`pik-pr/`](pik-pr/README.md).

Aplikasi web internal untuk memantau project **New Product Development** — tipe **New Mold** dan **Subcont** —
dari request sampai project selesai (PRD v2.1). Semua kode ditulis dengan **Python** (Flask) dan
halaman HTML dirender di server, jadi tidak ada JavaScript yang perlu dipelajari.

> Satu project = satu catatan digital: current process, PIC, waiting for, next action, approval customer,
> dokumen & revisi, timeline, dan riwayat aktivitas.

---

## Cara menjalankan

Butuh **Python 3.10+**.

```bash
# 1. Buat virtual environment & install library
python -m venv .venv
source .venv/bin/activate          # Windows: .venv\Scripts\activate
pip install -r requirements.txt

# 2. Jalankan aplikasi
python app.py
```

Buka **http://127.0.0.1:5000**. Saat pertama kali dijalankan, database SQLite dibuat otomatis di
folder `data/` dan diisi **13 project contoh** (tanggalnya relatif terhadap hari ini).

**Login demo** — semua akun memakai password `demo123` (akun juga bisa dipilih langsung di halaman login):

| Email | Role |
| --- | --- |
| andi@npd.local | Admin |
| sari@npd.local, budi@npd.local | Admin Sales |
| rizky@npd.local, maya@npd.local | NPD Staff |
| dimas@npd.local, nadia@npd.local | Drafter |
| hendra@npd.local | Purchasing |
| agus@npd.local | Production |
| lestari@npd.local | Quality |
| bambang@npd.local | Management (read-only) |

**Menjalankan test**

```bash
python -m pytest -q
```

**Pengaturan lewat environment variable** (opsional)

| Variable | Fungsi | Default |
| --- | --- | --- |
| `NPD_SECRET_KEY` | Kunci cookie session — **wajib diganti di production** | `dev-secret-…` |
| `NPD_DATA_DIR` | Folder database & file upload | `./data` |
| `NPD_DATABASE_URL` | URL database SQLAlchemy (mis. PostgreSQL) | SQLite di `data/` |
| `NPD_SEED_DEMO` | `1` = isi data demo saat database kosong | `1` |
| `NPD_HOST` / `NPD_PORT` | Alamat server | `127.0.0.1` / `5000` |
| `NPD_DEBUG` | `1` = mode debug Flask | mati |

Untuk memulai dari data kosong: hapus folder `data/` lalu jalankan dengan `NPD_SEED_DEMO=0`
(buat user Admin pertama lewat `flask --app app shell`), atau pakai **Pengaturan → Sistem → Reset data demo**.

---

## Struktur file

Aplikasi dibagi menjadi 3 lapisan. Setiap lapisan hanya memanggil lapisan di bawahnya:

```
 Halaman (routes/ + templates/)     ← menerima klik & form, menampilkan HTML
        │
 Logika bisnis (services/)          ← aturan workflow, validasi, hak akses, laporan
        │
 Data (models.py)                   ← tabel database
```

```
app.py                     ← titik masuk: `python app.py`
requirements.txt           ← daftar library Python
npd/
├── __init__.py            ← create_app(): menyiapkan Flask, database, dan semua halaman
├── config.py              ← konfigurasi (database, folder upload, secret key)
├── extensions.py          ← objek database (SQLAlchemy)
├── constants.py           ← daftar nilai tetap + label & warnanya (role, status, tipe dokumen, dll.)
├── models.py              ← TABEL DATABASE: User, Customer, Project, ProjectProcess, Approval,
│                            Document, DocumentVersion, ProcessRecord, Activity, Comment,
│                            Notification, CalendarEvent, Setting, ...
├── workflows.py           ← TEMPLATE WORKFLOW Subcont (13 proses) & New Mold (16 proses):
│                            urutan proses, PIC, dokumen wajib, pilihan keputusan & ke mana workflow lanjut
├── template_helpers.py    ← fungsi/filter yang dipakai di template (format tanggal, ikon, CSRF)
│
├── services/              ← LOGIKA BISNIS (tidak tergantung tampilan, mudah di-test)
│   ├── workflow_engine.py ← jantung aplikasi: menyelesaikan proses, revision loop, cabang,
│   │                        status otomatis, approval otomatis, Finish project
│   ├── projects.py        ← buat/ubah project, update status, next action, komentar
│   ├── documents.py       ← upload dokumen, revisi (Rev 00, 01, …) tanpa menghapus revisi lama
│   ├── approvals.py       ← ajukan & putuskan approval
│   ├── permissions.py     ← hak akses per role (siapa boleh melihat/mengubah apa)
│   ├── metrics.py         ← overdue, due soon, aging, progress, attention required
│   ├── planning.py        ← menghitung planned start/finish tiap proses
│   ├── notifications.py   ← notifikasi deadline, overdue, next action, dokumen wajib
│   ├── analytics.py       ← Weekly NPD Report & analytics (lead time, bottleneck, loop)
│   ├── assistant.py       ← AI Assistant berbasis data (menjawab dari database, tanpa mengarang)
│   ├── calendar.py        ← item kalender (deadline, approval, trial, material, meeting, …)
│   ├── admin.py           ← kelola user, customer, template workflow, pengaturan, event kalender
│   ├── excel.py           ← export .xlsx
│   ├── seed.py            ← data demo (13 skenario project)
│   ├── sample_files.py    ← file contoh (PDF/SVG) untuk dokumen demo
│   ├── context.py         ← "siapa melakukan aksi & kapan" + pencatatan Activity History
│   ├── dates.py           ← fungsi tanggal format Indonesia
│   └── errors.py          ← AppError: pesan error yang ditampilkan ke user
│
├── routes/                ← HALAMAN (satu file per menu di sidebar)
│   ├── __init__.py        ← mendaftarkan semua halaman + cek login + proteksi CSRF
│   ├── helpers.py         ← fungsi bantu route (transaksi database, pesan sukses/error)
│   ├── auth.py            ← /login, /logout
│   ├── dashboard.py       ← /                     Dashboard: KPI, Attention Required, grafik
│   ├── projects.py        ← /projects             daftar, buat, detail, edit, status, finish, export
│   ├── process.py         ← /projects/<kode>/process/<id>   detail proses, selesaikan, problem
│   ├── tracker.py         ← /tracker              Process Tracker (board per proses)
│   ├── gantt.py           ← /gantt                Timeline / Gantt
│   ├── calendar.py        ← /calendar             Kalender + meeting/follow-up
│   ├── documents.py       ← /documents            dokumen, preview, download, upload revisi
│   ├── approvals.py       ← /approvals            daftar & keputusan approval
│   ├── reports.py         ← /reports              Weekly report & analytics (+ Excel)
│   ├── assistant.py       ← /assistant            AI Assistant
│   ├── settings.py        ← /settings             profil, user, customer, workflow, sistem
│   └── notifications.py   ← /notifications        notifikasi
│
├── templates/             ← TAMPILAN HTML (Jinja2), foldernya sama dengan nama route
│   ├── base.html          ← kerangka halaman: sidebar, topbar, pesan sukses/error
│   ├── _macros.html       ← komponen kecil yang dipakai ulang (chip status, field form, dll.)
│   ├── dashboard.html, tracker.html, gantt.html, error.html
│   ├── auth/  projects/  process/  documents/  approvals/
│   └── calendar/  reports/  assistant/  settings/  notifications/
│
└── static/css/style.css   ← seluruh desain (warna, layout, responsif mobile/tablet/desktop)

tests/
├── conftest.py            ← database test di memori
├── test_workflow.py       ← test logika bisnis (revision loop, cabang, status, finish, AI)
└── test_routes.py         ← test halaman (login, hak akses, form, upload, export, CSRF)
```

### Contoh alur: "Selesaikan Proses"

1. User menekan **Selesaikan Proses** → `routes/process.py` fungsi `complete()` menampilkan
   `templates/process/complete.html` (form berisi field dari `workflows.py`).
2. Form dikirim → `complete()` memanggil `services/workflow_engine.py` → `complete_process()`.
3. Engine memeriksa hak akses, field wajib, dokumen wajib, lalu memindahkan current process,
   mengatur status/waiting for/next action, membuat approval & notifikasi, dan mencatat Activity History.
4. Jika berhasil, perubahan disimpan (`routes/helpers.py` → `run()`) dan user diarahkan kembali ke detail
   project dengan pesan sukses. Jika gagal, pesan error ditampilkan di form yang sama.

### Mengubah workflow

- **Tanpa coding**: Admin → **Pengaturan → Workflow** (ubah nama, PIC, durasi, dokumen wajib,
  tambah/hapus proses tambahan). Setiap perubahan menaikkan versi template; project yang sudah
  berjalan tetap memakai versi saat project dibuat.
- **Lewat kode**: edit `npd/workflows.py` (berlaku untuk database baru / setelah reset data demo).

---

## Fitur

- **Dashboard** — KPI (total, New Mold, Subcont, On Progress, Waiting, Overdue, Due Soon, Completed),
  Attention Required (overdue, due soon, waiting approval/external, no update, dokumen wajib belum ada),
  tugas saya, grafik per proses/status/customer/PIC/type/priority, filter.
- **Project** — daftar dengan pencarian, filter, sort, export Excel; form project dengan validasi &
  cek duplikat; Project ID otomatis `NPD-YYYY-XXX`.
- **Detail project** — current process sebagai fokus (PIC, waiting for, deadline, aging, next action),
  process tracker, timeline planned vs actual, tab Approval / Dokumen / Revision History / Trial &
  Material / Activity + komentar, Ringkasan AI.
- **Workflow engine** — revision loop (mis. artwork ditolak → kembali ke Artwork), cabang New Mold
  (masterbatch YES/NO, T0 OK/NG → Mold Correction), Problem, status otomatis (Waiting Customer /
  Waiting External), approval otomatis, Finish ditolak bila proses mandatory belum selesai,
  koreksi current process oleh Admin.
- **Dokumen** — per proses & folder, revisi Rev 00/01/… (revisi lama tidak dihapus), versi,
  preview PDF/gambar, download, validasi format & ukuran (maks. 25 MB).
- **Approval** — daftar "perlu keputusan saya", semua pending, riwayat; waiting time; lampiran keputusan.
- **Process Tracker**, **Timeline/Gantt** (zoom minggu/bulan/kuartal, expand proses), **Kalender**
  (deadline, approval, trial, commissioning, material, validation, meeting, follow-up).
- **Laporan** — Weekly NPD Report (top issues, action required, teks siap salin, Excel) dan analytics
  (lead time, durasi proses, bottleneck, waktu tunggu customer, loop artwork/T0/trial).
- **AI Assistant** — tanya jawab berbahasa Indonesia berdasarkan data yang boleh dilihat user.
- **Hak akses 8 role** (PRD §11), **notifikasi in-app**, **Pengaturan** (user, customer, workflow, threshold).
- **Keamanan dasar** — password di-hash, session login, proteksi CSRF di semua form, validasi file upload,
  data project dibatasi sesuai role (Admin Sales & Drafter hanya melihat project miliknya).
- **Responsif** — desktop, tablet, dan mobile (menu ☰, tombol aksi utama di bawah layar).

## Asumsi & batasan

- AI Assistant memakai aturan berbasis data (bukan model bahasa eksternal) agar jawaban selalu dari
  database. Fungsi `answer_question()` di `services/assistant.py` bisa diganti LLM dengan data yang sama.
- Notifikasi hanya di dalam aplikasi (belum email/WhatsApp). Notifikasi deadline dibuat saat user login.
- File upload disimpan di folder `data/uploads/` (bukan cloud storage).
- Server bawaan `python app.py` untuk penggunaan lokal/intranet. Untuk production gunakan WSGI server,
  misalnya `pip install waitress` lalu `waitress-serve --port=8000 app:app`, dan set `NPD_SECRET_KEY`.
