# NPD Project Control

Web application internal untuk mengelola, memonitor, dan mendokumentasikan project **New Product Development** — **New Mold** dan **Subcont** dalam satu sistem (PRD v2.1, Apple-inspired UI/UX).

> Konsep utama: **ONE PROJECT = ONE DIGITAL RECORD** — status, current process, PIC, waiting for, next action, timeline, approval, dokumen & revisi, serta activity history dalam satu halaman project.

## Menjalankan

```bash
npm install
npm run dev          # http://localhost:5173
npm test             # unit test workflow engine, business rules, AI assistant
npm run build        # production build (dist/), route-level code splitting
npm run build:single # satu file HTML mandiri (dist-single/index.html) untuk demo
npm run build:artifact # fragment untuk hosting sandbox (MemoryRouter, tanpa download/print)
```

Login memakai akun demo (password semua `demo123`), atau klik salah satu akun di halaman login:

| Role | Akun | Scope |
|---|---|---|
| Admin | andi@npd.local | Semua project + Pengaturan (user, customer, workflow, sistem) |
| Admin Sales | sari@npd.local, budi@npd.local | Project dengan dirinya sebagai Sales PIC |
| NPD Staff | rizky@npd.local, maya@npd.local | Semua project, project control |
| Drafter | dimas@npd.local, nadia@npd.local | Project dengan dirinya sebagai Drafter |
| Purchasing | hendra@npd.local | Semua project, material request & purchasing status |
| Production | agus@npd.local | Trial / T0 / commissioning / validation |
| Quality | lestari@npd.local | Trial / validation |
| Management | bambang@npd.local | Read-only: dashboard, report, analytics |

Data demo (13 project dengan loop revisi artwork, T0 NG → mold correction, trial rejection, masterbatch, project Hold/overdue/completed) dibuat relatif terhadap tanggal hari ini dengan menjalankan command yang sama dengan UI melalui workflow engine. Reset di **Pengaturan → Sistem**.

## Fitur

- **Workflow engine configurable** — template Subcont & New Mold (PRD §4–5) dengan decision, cabang (New Masterbatch YES/NO), revision loop (artwork, 3D, mold drawing, masterbatch, trial), T0 loop (Mold Correction → Mold Machining → T0), repeat (Validation FAIL, Commissioning NG). Admin dapat mengubah atribut proses dan menambah proses; project menyimpan snapshot versi workflow.
- **Project** — create (Project ID otomatis `NPD-YYYY-XXX`), edit, update status (aturan Waiting External/Waiting Approval/Hold/Cancelled), next action, admin override current process, Finish yang menolak bila mandatory process belum selesai.
- **Project Detail** — current process sebagai focal point, PIC/Waiting For/Deadline/Aging/Next Action, process tracker (horizontal desktop, vertikal mobile), timeline planned vs actual (grafik & tabel), approval history, dokumen per folder proses, revision history, record trial/material/validation, activity & komentar, ringkasan AI.
- **Dokumen** — upload per proses (IndexedDB), revisi baru / versi baru tanpa menghapus revisi lama, status Current/Superseded/Rejected/Approved otomatis dari approval, preview (gambar/PDF/teks) & download.
- **Approval** — record otomatis saat workflow masuk proses approval, keputusan dengan komentar wajib untuk penolakan & bukti approval, approval manual (mis. 2D Approval).
- **Dashboard** — 8 KPI, filter real-time, Attention Required (Overdue, Due Soon, Waiting Approval/External, No Update, Missing Mandatory Document), 6 chart, tugas saya.
- **Process Tracker** board, **Timeline/Gantt** (filter customer/type/PIC/status/priority/date range, zoom, expand proses), **Kalender** (deadline, approval, trial/T0, commissioning, material arrival, validation + CRUD meeting/follow-up).
- **Laporan** — Weekly NPD Report (salin teks, print/PDF, export Excel), analytics (lead time, durasi per proses, bottleneck, waiting approval customer, artwork revision, T0 loop, trial rejection, overdue).
- **AI Assistant** — menjawab pertanyaan natural language dari database sesuai scope role, tidak mengarang data ("Data tersebut belum tersedia di sistem."), read-only.
- **Notifikasi** — event (assignment, approval requested/approved/rejected, revisi artwork, dokumen baru/revisi) + scan waktu (deadline mendekat, overdue, next action due/overdue, dokumen wajib belum ada).
- **Export Excel** `.xlsx` asli tanpa dependency (daftar project, weekly report, analytics).

## Arsitektur

```
src/
  types/            domain model (tabel PRD §14)
  config/           label/tone, workflow template default
  domain/           logika murni & dapat diuji: workflow engine, command, permission,
                    metrics, analytics, seed
  assistant/        engine AI berbasis data (di belakang interface AssistantProvider)
  services/
    storage/        localStorage (atomic draft → commit) & IndexedDB file store
    api/            service/API abstraction: auth, latency & failure simulation,
                    permission check, read model
  hooks/            TanStack Query hooks, auth context
  components/       ui/, layout/, project/, documents/, approvals/, charts/, assistant/
  pages/            satu file per halaman (lazy loaded)
```

Mengganti ke backend sungguhan cukup mengganti implementasi di `src/services/api/*` (signature tetap `Promise`); UI dan domain tidak berubah. Permission diterapkan di command (backend), query (scope data), UI, dokumen, dan AI.

## Asumsi utama

- **New Masterbatch** diputuskan pada NPD Feedback (outcome “Accepted · New Masterbatch YES/NO”). Mengikuti diagram PRD, cabang YES = Masterbatch Development → Customer Masterbatch Approval, cabang NO = 3D Prototype → Customer 3D Approval; keduanya bertemu di 2D Drawing. Cabang yang tidak dipilih ditandai *Tidak dijalankan*.
- **Overdue** dihitung otomatis dari Target Finish dan ditampilkan bersama status dasar (mis. “Overdue 5 hari · Waiting External”) agar informasi menunggu siapa tidak hilang.
- **Customer Trial Approval Not Approved** kembali ke Trial & Evaluation (default) atau Trial Material Preparation (dipilih user). **Validation FAIL** & **Commissioning Not OK** mengulang proses (status Problem). **NPD Feedback Rejected** → project Cancelled.
- Status project otomatis dari current process: approval customer → Waiting Approval, proses eksternal (Mold Machining, Mold Correction, Mold Shipment, Material Preparation) → Waiting External, lainnya → On Progress.
- Planned date tiap proses dibuat otomatis dari durasi template dan diskalakan ke Target Finish; dapat diedit per proses oleh Admin/NPD Staff.
- Label utama UI berbahasa Indonesia; istilah domain (status, approval type, document type, nama proses) mengikuti PRD.
- AI Assistant memakai engine deterministik berbasis data (tidak memerlukan API key) di balik interface `AssistantProvider`, sehingga dapat diganti LLM dengan tool yang memakai data ber-scope sama.
- Tanpa backend, data disimpan di browser (localStorage + IndexedDB) per perangkat.
