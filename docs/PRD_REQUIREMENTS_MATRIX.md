# PRD Requirements Matrix — NPD Project Control v3.0

Sumber: `PRD_NPD_Project_Control_v3.0.docx` (versi 3.0, 4 Okt 2026) dan form `PIK-FORM-NPD-01 rev 00`.
Matriks ini memetakan **setiap kebutuhan PRD** ke fase implementasi, modul/file, dan bukti pengujian.

Legenda status:

| Status | Arti |
| --- | --- |
| `Planned` | Belum dikerjakan |
| `In progress` | Sedang dikerjakan |
| `Done` | Terimplementasi **dan** lulus test otomatis yang disebut di kolom Test |
| `Partial` | Sebagian terimplementasi (lihat catatan) |
| `Decision` | Menunggu keputusan bisnis (lihat `OPEN_QUESTIONS.md`) |

Prioritas mengikuti PRD (Must / Should / Could). Kebutuhan tanpa ID di PRD diberi ID turunan
(`AUTH-`, `ROLE-`, `DATA-`, dst.) agar dapat dilacak.

---

## 1. Autentikasi & akun (PRD §2.4, §13.2)

| ID | Kebutuhan | PRD | Prio | Fase | Modul / file | Status | Test |
| --- | --- | --- | --- | --- | --- | --- | --- |
| AUTH-01 | Login email + password | 2.4 | Must | 1 | `modules/Core/Auth.php`, `public/login.php` | Planned | `tests/Integration/AuthTest.php`, `tests/Http/AuthHttpTest.php` |
| AUTH-02 | Password di-hash (bcrypt/argon2) — `password_hash()` / `password_verify()`, tidak pernah plaintext | 2.4, 13.2 | Must | 1 | `Auth`, `UserService` | Planned | `AuthTest` |
| AUTH-03 | Admin membuat, menonaktifkan, mereset password user | 2.4 | Must | 1 | `modules/User/UserService.php`, `public/settings/users.php` | Planned | `UserServiceTest` |
| AUTH-04 | User mengganti password sendiri | 2.4 | Must | 1 | `public/profile.php` | Planned | `UserServiceTest` |
| AUTH-05 | Sesi berakhir otomatis setelah tidak aktif (default 8 jam, dapat diatur) | 2.4 | Must | 1 | `modules/Core/Session.php` | Planned | `SessionTest`, `AuthHttpTest` |
| AUTH-06 | Penguncian sementara setelah beberapa kali gagal login (rate limiting) | 2.4, 13.2 | Must | 1 | `modules/Core/LoginThrottle.php` | Planned | `LoginThrottleTest`, `AuthHttpTest` |
| AUTH-07 | Akun nonaktif tidak bisa login, nama tetap tampil di riwayat/audit | 2.4 | Must | 1 | `Auth`, audit snapshot `user_name` | Planned | `AuthTest` |
| AUTH-08 | Cookie sesi HttpOnly, Secure (HTTPS), SameSite; regenerasi ID sesi setelah login; logout menghancurkan sesi | 13.2 | Must | 1 | `Session` | Planned | `AuthHttpTest` |

## 2. Role & hak akses (PRD §2.1–2.3)

| ID | Kebutuhan | PRD | Prio | Fase | Modul / file | Status | Test |
| --- | --- | --- | --- | --- | --- | --- | --- |
| ROLE-01 | 8 role: Admin, Admin Sales, NPD Staff, Drafter, Purchasing, Production, Quality, Management | 2.1 | Must | 1 | `database/seeds/roles.php`, tabel `roles` | Planned | `GateTest` |
| ROLE-02 | Semua role melihat semua project | 2.2 | Must | 1/3 | `Gate::can('project.view')` | Planned | `GateTest` |
| ROLE-03 | Sales hanya mengubah NPR/project di mana ia Sales PIC; Drafter hanya proses yang di-assign | 2.2 | Must | 1/2/4 | `Gate` (scope `own`) | Planned | `GateTest`, `NprServiceTest` |
| ROLE-04 | NPD Staff & Admin mengubah seluruh project; Management read-only | 2.2 | Must | 1 | `Gate` | Planned | `GateTest`, `AuthorizationHttpTest` |
| ROLE-05 | Matriks hak akses §2.3 ditegakkan di server pada setiap request/API/unduhan | 2.3, 13.2 | Must | 1+ | `includes/permissions.php`, `Gate`, service layer | Planned | `AuthorizationHttpTest` |

## 3. Model data, status, penomoran (PRD §3)

| ID | Kebutuhan | PRD | Prio | Fase | Modul / file | Status | Test |
| --- | --- | --- | --- | --- | --- | --- | --- |
| DATA-01 | Hierarki Project → Part → Proses; part beda jenis dalam satu project | 3.1 | Must | 3 | `projects`, `project_parts`, `processes` | Planned | `ProjectStructureTest` |
| DATA-02 | Part ditolak/dibatalkan tidak membatalkan project; project Cancelled bila semua part Cancelled | 3.1 | Must | 3 | `StatusService` | Planned | `StatusServiceTest` |
| DATA-03 | Project satu part tampil sederhana | 3.1 | Must | 6 | `public/project.php` | Planned | manual + `ProjectPageHttpTest` |
| DATA-04 | Status proses: Not Started, Current, Completed, Revision, Problem, Skipped; >1 proses aktif per part | 3.3 | Must | 4 | `WorkflowEngine` | Planned | `WorkflowEngineTest` |
| DATA-05 | Status turunan part/project sesuai tabel §3.3 (Waiting Approval > Waiting External > On Progress, Hold, Siap Finish, Cancelled) | 3.3 | Must | 4 | `StatusService` | Planned | `StatusServiceTest` |
| DATA-06 | Overdue = tanda tambahan terhitung (bukan status dasar) | 3.3, 7.1 | Must | 8 | `OverdueService` | Planned | `OverdueServiceTest` |
| DATA-07 | Kode project NPD-YYYY-XXX urut per tahun | 3.4 | Must | 2 | `NumberSequence` | Planned | `NumberSequenceTest` (konkurensi) |
| DATA-08 | Nomor NPR NO/PIK/NPR/Bulan Romawi/Tahun, reset tiap tahun, dibuat saat pertama dikirim | 3.4 | Must | 2 | `NumberSequence`, `NprService::submit` | Planned | `NumberSequenceTest`, `NprServiceTest` |
| DATA-09 | No. dokumen export: NPR = PIK-FORM-NPD-01 rev 00; timeline = PIK-FORM-NPD-07 tanpa revisi | 3.4 | Must | 2/6 | `NprPdf`, `TimelineExport` | Planned | `PdfExportTest`, `ExcelExportTest` |

## 4. NPR digital (PRD §4, Lampiran A)

| ID | Kebutuhan | PRD | Prio | Fase | Modul / file | Status | Test |
| --- | --- | --- | --- | --- | --- | --- | --- |
| FR-NPR-01 | Form NPR memuat seluruh bagian PIK-FORM-NPD-01 + legenda biru/pink | 4.2, Lamp. A | Must | 2 | `public/npr-edit.php`, `modules/Npr/NprFields.php` | Planned | `NprFieldsTest` (cakupan Lampiran A) |
| FR-NPR-02 | Kolom biru hanya Sales (dan Admin); pink hanya NPD (dan Admin); server menolak di luar hak | 4.2 | Must | 2 | `NprService` + `Gate` | Planned | `NprServiceTest`, `NprHttpTest` (UAT-02) |
| FR-NPR-03 | Jumlah & nama part dinamis; master nama part oleh Admin; opsi "Lainnya" | 4.3 | Must | 2 | `NprService`, `MasterService` | Planned | `NprServiceTest` |
| FR-NPR-04 | Nomor NPR otomatis, urut per tahun, tanpa duplikat meski bersamaan | 3.4, 4.7 | Must | 2 | `NumberSequence` (atomic upsert) | Planned | `NumberSequenceTest` (proses paralel) |
| FR-NPR-05 | Setelah kirim kolom biru terkunci; NPD dapat mengembalikan dengan alasan; Revision History otomatis tanpa nomor revisi | 4.1 | Must | 2 | `NprService::return/submit`, `revision_history` | Planned | `NprServiceTest` (UAT-05) |
| FR-NPR-06 | Feedback per part tersimpan draft, dipublikasikan saat Selesaikan Feedback | 4.1, 4.5 | Must | 2 | `NprFeedbackService` | Planned | `NprServiceTest` |
| FR-NPR-07 | Tidak Feasible membatalkan part terkait saja; project batal bila semua part batal | 4.5 | Must | 2/3 | `NprFeedbackService`, `StatusService` | Planned | `NprServiceTest` (UAT-03) |
| FR-NPR-08 | Export PDF NPR mengikuti format form, memuat seluruh data Sales + feedback NPD | 4.6 | Must | 2 | `modules/Report/NprPdf.php` (mPDF) | Planned | `PdfExportTest` (UAT-04) |
| FR-NPR-09 | Harga mould % PIK + % Customer = 100% (validasi saat simpan) | 4.7 | Should | 2 | `NprFeedbackService` | Planned | `NprServiceTest` |
| FR-NPR-10 | Upload lampiran gambar/file maks. 25 MB (Contoh Bentuk Produk, Referensi Spek) | 4.7 | Must | 2/7 | `DocumentService` | Planned | `DocumentServiceTest` |
| FR-NPR-11 | Requested by / Received by otomatis dari akun; tanpa blok tanda tangan | 4.2 | Must | 2 | `NprService` | Planned | `NprServiceTest`, `PdfExportTest` |
| FR-NPR-12 | Pratinjau PDF berwatermark "BELUM SELESAI FEEDBACK" sebelum Selesai Feedback | 4.6 | Could | 2 | `NprPdf` | Planned | `PdfExportTest` |
| NPR-13 | Status NPR: Draft, Dikirim, Dikembalikan, Selesai Feedback + siapa yang boleh mengubah | 4.1 | Must | 2 | `NprService` | Planned | `NprServiceTest` |
| NPR-14 | Simpan draft & autosave; stepper di HP | 4.1, 11.6 | Must | 2/11 | `public/assets/js/npr-form.js`, `public/api/npr-autosave.php` | Planned | `NprHttpTest` |
| NPR-15 | Daftar master NPR dikelola Admin (nonaktif, bukan hapus; data lama tetap terbaca) | 4.4 | Must | 2 | `MasterService`, `public/settings/masters.php` | Planned | `MasterServiceTest` |
| NPR-16 | Perlu Revisi → NPR kembali ke Sales; part lain tetap; part yang diubah ditandai "Perlu ditinjau ulang" | 4.5 | Must | 2 | `NprFeedbackService` | Planned | `NprServiceTest` |
| NPR-17 | "Perlu Masterbatch Baru" per part menentukan Masterbatch dijalankan / Tidak dijalankan | 4.5 | Must | 2/4 | `WorkflowInstantiator` | Planned | `WorkflowEngineTest` |
| NPR-18 | Hapus part yang sudah punya feedback/proses tidak diizinkan → Cancel dengan alasan | 4.3 | Must | 2 | `NprService` | Planned | `NprServiceTest` |
| NPR-19 | Jenis part (New Mold/Subcont) per part; Mould & Feedback per part | 4.2 | Must | 2 | `npr_parts`, `npr_feedback` | Planned | `NprServiceTest` |
| NPR-20 | Nama file PDF `NPR_<nomor>_<produk>.pdf`; export dicatat di aktivitas | 4.6 | Must | 2 | `public/export.php` | Planned | `PdfExportTest` |

## 5. Workflow & dependency (PRD §5)

| ID | Kebutuhan | PRD | Prio | Fase | Modul / file | Status | Test |
| --- | --- | --- | --- | --- | --- | --- | --- |
| FR-WF-01 | Banyak proses aktif bersamaan per part; Tracker & detail part menampilkan semuanya | 5 | Must | 4/6 | `WorkflowEngine`, `public/tracker.php` | Planned | `WorkflowEngineTest` (UAT-06) |
| FR-WF-02 | Dependency FS, SS, FF, Paralel; lag ±; banyak predecessor | 5.3 | Must | 4/5 | `DependencyService`, `Scheduler` | Planned | `SchedulerTest`, `DependencyServiceTest` |
| FR-WF-03 | Aktivasi otomatis saat syarat terpenuhi; FF memblokir penyelesaian dini | 5.4 | Must | 4 | `WorkflowEngine::activateReady/complete` | Planned | `WorkflowEngineTest` (UAT-14) |
| FR-WF-04 | Tolak dependency melingkar (template & override) | 5.3 | Must | 4 | `DependencyValidator` | Planned | `DependencyServiceTest` (UAT-13) |
| FR-WF-05 | Override dependency per project oleh NPD dengan pratinjau + audit sebelum/sesudah | 5.5 | Must | 4/5 | `DependencyService::preview/save` | Planned | `DependencyServiceTest` (UAT-12) |
| FR-WF-06 | "Tidak dijalankan": hanya proses yang diizinkan, alasan wajib, Admin/NPD, pasangan, pembatalan | 5.6 | Must | 4 | `WorkflowEngine::skip/unskip` | Planned | `WorkflowEngineTest` (UAT-11) |
| FR-WF-07 | Loop approval/trial/T0/validasi dengan iterasi | 5.2 | Must | 4 | `WorkflowEngine::applyOutcome` | Planned | `WorkflowEngineTest` (UAT-15) |
| FR-WF-08 | Gate Assembly/Fit Test opsional, predecessor milestone part aktif | 5.7 | Should | 4 | `GateService` | Planned | `GateServiceTest` |
| FR-WF-09 | Template workflow berversi; project menyimpan versi | 5.8 | Must | 4 | `WorkflowTemplateService` | Planned | `WorkflowTemplateTest` |
| FR-WF-10 | Koreksi manual proses aktif oleh Admin dengan alasan | 5.4 | Should | 4 | `WorkflowEngine::manualMove` | Planned | `WorkflowEngineTest` |
| WF-11 | Workflow bawaan level project (P1, P2, G1, PF), New Mold (N1–N14), Subcont (S1–S11) | 5.1 | Must | 4 | `database/seeds/workflows.php` | Planned | `WorkflowTemplateTest` |
| WF-12 | Aturan aktivasi §5.4 (dokumen wajib, approval, mandatory sebelum Finish) | 5.4 | Must | 4/7 | `WorkflowEngine` | Planned | `WorkflowEngineTest` |
| WF-13 | Validasi cross-part: dependency antar part hanya lewat gate level project | 5.3 | Must | 4 | `DependencyValidator` | Planned | `DependencyServiceTest` |
| WF-14 | Lag negatif tidak membuat mulai sebelum part/predecessor mulai | 5.3 | Must | 5 | `Scheduler` | Planned | `SchedulerTest` |
| WF-15 | Pengaturan Workflow Admin: atribut proses §5.8; proses bawaan hanya dinonaktifkan | 5.8 | Must | 4 | `public/settings/workflow.php` | Planned | `WorkflowTemplateTest` |
| WF-16 | Perubahan template tidak mengubah project berjalan kecuali Admin menerapkan ke proses belum mulai (dengan pratinjau) | 5.5 | Must | 4 | `WorkflowTemplateService::applyToRunning` | Planned | `WorkflowTemplateTest` |

## 6. Penjadwalan & timeline (PRD §6)

| ID | Kebutuhan | PRD | Prio | Fase | Modul / file | Status | Test |
| --- | --- | --- | --- | --- | --- | --- | --- |
| FR-SCH-01 | Input planning opsional (durasi, Planned Start, Planned Finish); yang kosong otomatis | 6.1 | Must | 5 | `Scheduler`, `PlanningService` | Planned | `SchedulerTest` (5 kombinasi tabel §6.1) |
| FR-SCH-02 | Hari kerja Senin–Jumat + hari libur Admin | 6.2 | Must | 5 | `WorkingCalendar` | Planned | `WorkingCalendarTest` |
| FR-SCH-03 | Tanggal manual = "tidak mulai sebelum"; peringatan bila bertentangan dependency | 6.1 | Must | 5 | `Scheduler` | Planned | `SchedulerTest` (UAT-08) |
| FR-SCH-04 | Aktual ≠ rencana → proses bergantung bergeser; proses tak terkait tidak; semua tercatat | 6.4 | Must | 5 | `Scheduler`, `schedule_changes` | Planned | `SchedulerTest` (contoh §6.4, UAT-07) |
| FR-SCH-05 | Forecast Finish proses & perkiraan selesai part/project; Target Finish tetap; tanda Berisiko | 6.3, 6.4 | Must | 5 | `Scheduler` | Planned | `SchedulerTest` (UAT-10) |
| FR-SCH-06 | Timeline dua level (Project & Part) dengan klik part | 6.6 | Must | 6 | `public/project.php?tab=timeline` | Planned | `TimelineHttpTest` (UAT-18) |
| FR-SCH-07 | Export timeline PDF & Excel dengan No. Dokumen PIK-FORM-NPD-07 pojok kanan atas | 6.7 | Must | 6 | `TimelinePdf`, `TimelineExcel` | Planned | `PdfExportTest`, `ExcelExportTest` (UAT-19) |
| FR-SCH-08 | Baseline berversi + batang baseline di Gantt | 6.5 | Should | 5/6 | `BaselineService`, Gantt | Planned | `BaselineServiceTest` |
| FR-SCH-09 | Penyorotan jalur kritis pada Gantt | 6 | Could | 6 | `Scheduler::criticalPath` | Planned | `SchedulerTest` |
| FR-SCH-10 | Pengaturan "tarik maju jadwal bila selesai lebih awal" | 6.4 | Should | 5 | `Scheduler` + setting | Planned | `SchedulerTest` |
| SCH-11 | Planned Start/Finish selalu hari kerja; tanggal aktual boleh hari apa pun | 6.2 | Must | 5 | `Scheduler` | Planned | `SchedulerTest` |
| SCH-12 | Urutan topologis; FF memperpanjang durasi; Skipped durasi nol meneruskan tanggal | 6.3 | Must | 5 | `Scheduler` | Planned | `SchedulerTest` |
| SCH-13 | Proses berjalan mempertahankan Planned; Forecast = max(Planned Finish, hari ini) | 6.3 | Must | 5 | `Scheduler` | Planned | `SchedulerTest` |
| SCH-14 | Ikon kunci tanggal manual; proses Overdue merah; Skipped bergaris | 6.6 | Must | 6 | Gantt/timeline | Planned | `TimelineHttpTest` |
| SCH-15 | Gantt lintas project (Project/Part), filter customer/PIC/status/jenis, baseline opsional | 6.8 | Must | 6 | `public/gantt.php`, `public/assets/js/gantt.js` | Planned | `GanttApiTest` |
| SCH-16 | Process Tracker: kartu per part-proses aktif | 6.8 | Must | 6 | `public/tracker.php` | Planned | `TrackerHttpTest` |
| SCH-17 | Kalender: deadline, approval, trial, commissioning, material, validasi, agenda manual, libur | 6.8 | Must | 6 | `public/calendar.php`, `CalendarService` | Planned | `CalendarServiceTest` |
| SCH-18 | Hitung ulang jadwal satu project ≤ 1 detik (10 part × 20 proses), dalam satu transaksi | 13.1, 13.3 | Must | 5/12 | `Scheduler` | Planned | `SchedulerPerformanceTest` |
| SCH-19 | Persetujuan Target Finish baru oleh NPD/Admin dengan alasan; tidak mengubah baseline | 6.4 | Must | 5 | `ProjectService::changeTarget` | Planned | `SchedulerTest` (UAT-10) |

## 7. Overdue & notifikasi (PRD §7, Lampiran C)

| ID | Kebutuhan | PRD | Prio | Fase | Modul / file | Status | Test |
| --- | --- | --- | --- | --- | --- | --- | --- |
| FR-OVD-01 | Overdue per proses (hari kerja), merah di dashboard, detail, timeline, tracker, daftar, export | 7.1, 7.2 | Must | 8 | `OverdueService` + view | Planned | `OverdueServiceTest` (UAT-09) |
| FR-OVD-02 | Panel Overdue: project, part, proses, PIC, hari terlambat, menunggu siapa | 7.2 | Must | 8/10 | `public/dashboard.php` | Planned | `DashboardServiceTest` |
| FR-OVD-03 | Notifikasi web + email ke PIC hari pertama overdue; ringkasan harian | 7.3 | Must | 8 | `cron/overdue.php`, `cron/daily-report.php` | Planned | `NotificationTest` |
| FR-OVD-04 | Notifikasi web semua kejadian §7.3; email hanya yang bertanda Ya | 7.3 | Must | 8 | `Notifier` | Planned | `NotificationTest` |
| FR-OVD-05 | Masa Hold dikecualikan dari overdue & aging | 7.1, 8.1 | Must | 8/9 | `OverdueService` | Planned | `HoldServiceTest` |
| FR-OVD-06 | Pengaturan ambang & email oleh Admin; antrean email dengan retry | 7.3 | Should | 8 | `MailQueue`, `cron/notifications.php` | Planned | `MailQueueTest` |
| NTF-07 | Bell, daftar, tandai dibaca, tautan ke proses | 7.3 | Must | 8 | `public/notifications.php`, `public/api/notifications.php` | Planned | `NotificationHttpTest` |
| NTF-08 | Email memakai bahasa penerima; deduplikasi; pemindaian terjadwal tiap jam di hari kerja | 7.3 | Must | 8 | `Notifier`, cron | Planned | `NotificationTest` |
| NTF-09 | Kegagalan email tidak menggagalkan proses bisnis dan terlihat Admin | 7.3 | Must | 8 | `MailQueue`, `public/settings/email-queue.php` | Planned | `MailQueueTest` |
| NTF-10 | Overdue selalu merah + ikon + teks "Overdue" (tidak hanya warna) | 7.1, 11.8 | Must | 8/11 | CSS/view | Planned | `UiContractTest` |

## 8. Hold, Resume, Cancel, Arsip (PRD §8)

| ID | Kebutuhan | PRD | Prio | Fase | Modul / file | Status | Test |
| --- | --- | --- | --- | --- | --- | --- | --- |
| FR-HLD-01 | Hold project/part, alasan wajib; proses beku; dikecualikan overdue/aging/KPI | 8.1 | Must | 9 | `HoldService` | Planned | `HoldServiceTest` (UAT-16) |
| FR-HLD-02 | Resume wajib Target Finish baru + jadwal ulang dengan pratinjau; baseline baru | 8.2 | Must | 9 | `HoldService::previewResume/resume` | Planned | `HoldServiceTest` (UAT-17) |
| FR-HLD-03 | Pengingat Hold 30 hari (dapat diatur), ulang tiap 7 hari, web + email | 8.3 | Must | 9 | `cron/overdue.php` | Planned | `HoldServiceTest` |
| FR-HLD-04 | Arsip & pulihkan oleh Admin dengan alasan & audit; tanpa hapus permanen | 8.4 | Must | 9 | `ProjectService::archive/restore` | Planned | `ProjectServiceTest` (UAT-24) |
| FR-HLD-05 | Cancel part/project dengan alasan; Cancelled hanya dibuka Admin | 8.4 | Must | 9 | `ProjectService::cancel` | Planned | `ProjectServiceTest` |
| HLD-06 | Komentar & unggah dokumen tetap diizinkan saat Hold | 8.1 | Must | 9 | `CommentService`, `DocumentService` | Planned | `HoldServiceTest` |
| HLD-07 | Sisa durasi bawaan = durasi rencana − hari kerja terpakai sebelum Hold | 8.2 | Must | 9 | `HoldService` | Planned | `HoldServiceTest` |

## 9. Dokumen, approval, record, audit (PRD §9, Lampiran B)

| ID | Kebutuhan | PRD | Prio | Fase | Modul / file | Status | Test |
| --- | --- | --- | --- | --- | --- | --- | --- |
| DOC-01 | 18 tipe dokumen (Lampiran B) per project › part › proses | 9.1 | Must | 7 | `master_options(document_type)` | Planned | `DocumentServiceTest` |
| DOC-02 | Revisi tidak menimpa; status Current/Superseded/Rejected/Approved | 9.1 | Must | 7 | `DocumentService` | Planned | `DocumentServiceTest` |
| DOC-03 | Maks. 25 MB; ekstensi diatur Admin; validasi MIME/ekstensi/nama di server | 9.1, 13.2 | Must | 7 | `UploadValidator` | Planned | `UploadValidatorTest` |
| DOC-04 | Dokumen wajib mencegah penyelesaian proses; kategori Attention "Missing Mandatory Document" | 9.1, 5.4 | Must | 7 | `WorkflowEngine`, `AttentionService` | Planned | `WorkflowEngineTest` |
| DOC-05 | Halaman Dokumen: pencarian & filter, pratinjau gambar/PDF | 9.1 | Must | 7 | `public/documents.php` | Planned | `DocumentHttpTest` |
| DOC-06 | File di luar webroot, unduh hanya lewat sesi + cek hak akses | 9.1, 13.2 | Must | 7 | `public/download.php`, `storage/documents` | Planned | `DocumentHttpTest` |
| APR-01 | 10 tipe approval; pemberi customer/internal | 9.2 | Must | 7 | `ApprovalService` | Planned | `ApprovalServiceTest` |
| APR-02 | Approval customer dicatat Sales/NPD/Admin dengan bukti & komentar | 9.2 | Must | 7 | `ApprovalService` | Planned | `ApprovalServiceTest` |
| APR-03 | Status Pending/Approved/Rejected/Revision Required terhubung ke workflow (loop) | 9.2 | Must | 7 | `ApprovalService` + `WorkflowEngine` | Planned | `ApprovalServiceTest` (UAT-15) |
| APR-04 | Approval menyimpan revisi dokumen, pemohon, tanggal, iterasi; halaman antrean & riwayat | 9.2 | Must | 7 | `public/approvals.php` | Planned | `ApprovalServiceTest` |
| REC-01 | Record Trial/T0/Commissioning, Material, Validation per part; Purchasing update material | 9.3 | Must | 7 | `RecordService` | Planned | `RecordServiceTest` |
| FR-AUD-01 | Audit log append-only: siapa, kapan, IP, aksi, entitas, sebelum/sesudah, alasan | 9.4 | Must | 1+ | `AuditLogger`, `database/hardening.sql` | Planned | `AuditLoggerTest` |
| FR-AUD-02 | Cakupan audit: NPR, feedback, jadwal, dependency, shift otomatis, skip, hold, target, approval, dokumen, pengaturan, arsip, export, login | 9.4 | Must | 1+ | semua service | Planned | per modul |
| FR-AUD-03 | Tab Activity semua role; audit log penuh hanya Admin; Revision History | 9.4 | Must | 6/7 | `public/project.php`, `public/settings/audit.php` | Planned | `AuditHttpTest` |
| NA-01 | Next Action, jatuh tempo, Waiting For per part; notifikasi pemilik | 9.5 | Must | 8 | `NextActionService` | Planned | `NextActionTest` |
| PD-01 | Halaman detail project dengan 9 tab (Ringkasan … Activity) | 9.6 | Must | 6 | `public/project.php` | Planned | `ProjectPageHttpTest` |

## 10. Dashboard, laporan, KPI (PRD §10)

| ID | Kebutuhan | PRD | Prio | Fase | Modul / file | Status | Test |
| --- | --- | --- | --- | --- | --- | --- | --- |
| FR-RPT-01 | Dashboard: 8 kartu KPI, Panel Overdue, Attention Required, tugas saya, 6 grafik; sadar part/paralel | 10.1 | Must | 10 | `DashboardService`, `public/dashboard.php` | Planned | `DashboardServiceTest` |
| FR-RPT-02 | Weekly NPD Report & Analytics; periode mingguan/bulanan/bebas | 10.2 | Must | 10 | `ReportService` | Planned | `ReportServiceTest` |
| FR-RPT-03 | KPI per PIC (on-time rate, aktual vs planned, jumlah overdue) + drill-down, filter, tren | 10.3 | Must | 10 | `KpiService` | Planned | `KpiServiceTest` |
| FR-RPT-04 | KPI PIC hanya Management & Admin (UI dan API) | 10.3 | Must | 10 | `Gate('kpi.view')` | Planned | `AuthorizationHttpTest` (UAT-20) |
| FR-RPT-05 | Export laporan ke Excel; KPI juga PDF | 10.4 | Should | 10 | `ReportExcel`, `KpiPdf` | Planned | `ExcelExportTest`, `PdfExportTest` |
| RPT-06 | Filter dashboard: jenis, customer, NPD PIC, prioritas; kartu klik ke daftar terfilter | 10.1 | Must | 10 | `public/dashboard.php`, `public/projects.php` | Planned | `DashboardServiceTest` |
| RPT-07 | Excel profesional: header, lebar kolom, format angka/tanggal, border, freeze pane, filter | brief §16 | Must | 6/10 | `modules/Report/ExcelWriter.php` | Planned | `ExcelExportTest` |

## 11. UI/UX, bahasa (PRD §11, §12)

| ID | Kebutuhan | PRD | Prio | Fase | Modul / file | Status | Test |
| --- | --- | --- | --- | --- | --- | --- | --- |
| FR-UI-01 | Token warna, font Inter self-hosted, aturan aksen | 11.2–11.4 | Must | 1/11 | `public/assets/css/app.css` | Planned | `UiContractTest` |
| FR-UI-02 | Mode gelap #000000 di semua halaman/komponen, tanpa kilatan terang | 11.3 | Must | 1/11 | CSS tokens + `data-theme` | Planned | `UiContractTest`, browser test (UAT-21) |
| FR-UI-03 | Responsif laptop/tablet/HP (drawer, kartu, stepper, Gantt scroll, target sentuh ≥ 44px) | 11.6 | Must | 11 | CSS | Planned | browser test (UAT-22) |
| FR-UI-04 | Animasi 150–250 ms, menghormati reduce motion | 11.5 | Should | 11 | CSS | Planned | `UiContractTest` |
| FR-UI-05 | Kolom NPR biru/pink dengan legenda dan varian gelap | 11.2 | Must | 2 | CSS `--sales-tint`/`--npd-tint` | Planned | `UiContractTest` |
| UI-06 | Logo PIK di login, header, kop PDF/Excel; varian mode gelap | 11.4 | Must | 1/6 | `public/assets/images/logo-*.png` | Planned | `UiContractTest` |
| UI-07 | Navigasi: Dashboard · Project · Process Tracker · Gantt · Kalender · Dokumen · Approval · Laporan · Notifikasi · Pengaturan (Admin) | 11.7 | Must | 1 | `includes/layout/sidebar.php` | Planned | `LayoutHttpTest` |
| UI-08 | Aksesibilitas: kontras WCAG AA, keyboard, fokus terlihat, status tidak hanya warna | 11.8 | Must | 11 | CSS/markup | Planned | `UiContractTest` |
| I18N-01 | Bahasa Indonesia (bawaan) & Inggris; pilihan di header, tersimpan di profil | 12 | Must | 1 | `lang/id.php`, `lang/en.php`, `I18n` | Planned | `I18nTest` (UAT-23) |
| I18N-02 | Diterjemahkan: label, menu, tombol, status, pesan, email, judul/kolom export; isian user tidak | 12 | Must | 1+ | `t()` | Planned | `I18nTest` (kunci lengkap kedua bahasa) |
| I18N-03 | Format tanggal per bahasa (05 Okt 2026 / 05 Oct 2026) | 12 | Must | 1 | `I18n::date()` | Planned | `I18nTest` |
| I18N-04 | PDF NPR selalu Bahasa Indonesia | 12 | Must | 2 | `NprPdf` | Planned | `PdfExportTest` |

## 12. Non-fungsional (PRD §13)

| ID | Kebutuhan | PRD | Prio | Fase | Modul / file | Status | Test |
| --- | --- | --- | --- | --- | --- | --- | --- |
| NFR-01 | PHP 8.2+, MySQL 8 InnoDB utf8mb4 | 13.1 | Must | 0/1 | `database/schema.sql` | Planned | `SchemaTest` |
| NFR-02 | Mesin jadwal di server sebagai satu-satunya sumber kebenaran, satu transaksi | 13.1 | Must | 5 | `Scheduler` | Planned | `SchedulerTest` |
| NFR-03 | Tugas terjadwal: overdue/due soon, Hold reminder, ringkasan harian, antrean email | 13.1 | Must | 8 | `cron/*.php` | Planned | `CronTest` |
| NFR-04 | PDF & Excel dibuat server (mPDF, PhpSpreadsheet) | 13.1 | Must | 2/6 | `modules/Report` | Planned | `PdfExportTest` |
| NFR-05 | Zona waktu Asia/Jakarta | 13.1 | Must | 1 | `config/config.php` | Planned | `ConfigTest` |
| NFR-06 | Konfigurasi lewat environment (.env) untuk dev/UAT/prod | 13.1 | Must | 1 | `config/config.php`, `.env.example` | Planned | `ConfigTest` |
| NFR-07 | HTTPS, CSRF, XSS escaping, query terparameter | 13.2 | Must | 1+ | `Csrf`, `e()`, `Db` | Planned | `SecurityHttpTest` |
| NFR-08 | Kinerja: halaman utama ≤ 2 dtk, API p95 ≤ 500 ms, export ≤ 15 dtk | 13.3 | Must | 12 | — | Planned | `PerformanceTest` |
| NFR-09 | Backup harian DB + file, retensi ≥ 30 hari, uji pemulihan | 13.4 | Must | 13 | `docs/BACKUP_AND_RESTORE.md`, `bin/backup.sh` | Planned | restore drill |
| NFR-10 | Penguncian optimistik (peringatan bila data berubah) | 13.4 | Must | 2+ | kolom `lock_version` | Planned | `OptimisticLockTest` |
| NFR-11 | Pencatatan error & pemantauan dasar; migrasi skema tanpa kehilangan data | 13.4 | Must | 1/13 | `storage/logs`, `database/migrations` | Planned | `MigrationTest` |
| NFR-12 | Tidak ada AI Assistant (menu, halaman, endpoint) | 1.4 | Must | 1 | — | Planned | `NoAiAssistantTest` (UAT-25) |

## 13. Skenario UAT (PRD §15) → test otomatis

| UAT | Skenario | Fase | Test otomatis | Status |
| --- | --- | --- | --- | --- |
| UAT-01 | NPR 3 part (Body New Mold, Cap Subcont, "Lainnya") dikirim → nomor, biru terkunci, notifikasi NPD | 2/8 | `NprServiceTest::testUat01` | Planned |
| UAT-02 | Sales isi pink / NPD isi biru setelah kirim → ditolak server | 2 | `NprServiceTest::testUat02`, `NprHttpTest` | Planned |
| UAT-03 | Feedback Body Feasible, Cap Feasible+catatan, Plug Tidak Feasible → Plug batal, lainnya jalan | 2/4 | `NprServiceTest::testUat03` | Planned |
| UAT-04 | Export PDF NPR setelah Selesai Feedback | 2 | `PdfExportTest::testUat04` | Planned |
| UAT-05 | NPD kembalikan NPR; Sales ubah & kirim ulang → riwayat tanpa nomor revisi | 2 | `NprServiceTest::testUat05` | Planned |
| UAT-06 | Part New Mold mulai: Masterbatch & 3D aktif bersamaan; 2D menunggu keduanya | 4 | `WorkflowEngineTest::testUat06` | Planned |
| UAT-07 | Develop MB terlambat 2 hari kerja → turunan bergeser +2, 3D tidak, tercatat, notifikasi | 5 | `SchedulerTest::testUat07` | Planned |
| UAT-08 | Planned Start manual lebih awal dari dependency → peringatan & tanggal dependency | 5 | `SchedulerTest::testUat08` | Planned |
| UAT-09 | Proses lewat Planned Finish → merah, panel overdue, email hari pertama | 8 | `OverdueServiceTest::testUat09` | Planned |
| UAT-10 | Perkiraan selesai > Target Finish → target tetap, ditandai, target baru lewat persetujuan | 5 | `SchedulerTest::testUat10` | Planned |
| UAT-11 | "Tidak dijalankan" 3D Prototype (pasangan Customer 3D Approval) | 4 | `WorkflowEngineTest::testUat11` | Planned |
| UAT-12 | Ubah dependency 2D Drawing jadi paralel dengan approval 3D → pratinjau + audit | 4/5 | `DependencyServiceTest::testUat12` | Planned |
| UAT-13 | Dependency melingkar ditolak | 4 | `DependencyServiceTest::testUat13` | Planned |
| UAT-14 | Selesaikan proses FF sebelum predecessor selesai → ditolak | 4 | `WorkflowEngineTest::testUat14` | Planned |
| UAT-15 | Customer Artwork Approval Not Approved → kembali ke Artwork, iterasi +1, jadwal dihitung ulang | 4 | `WorkflowEngineTest::testUat15` | Planned |
| UAT-16 | Hold part lalu project 30 hari → beku, tidak overdue, pengingat 30 hari | 9 | `HoldServiceTest::testUat16` | Planned |
| UAT-17 | Resume project → target baru wajib, baseline baru, jadwal lama tersimpan | 9 | `HoldServiceTest::testUat17` | Planned |
| UAT-18 | Management buka timeline, klik part BODY → Level 2, tidak dapat mengubah | 6 | `TimelineHttpTest::testUat18` | Planned |
| UAT-19 | Export timeline PDF & Excel, PIK-FORM-NPD-07 di kanan atas, overdue merah | 6 | `ExcelExportTest`, `PdfExportTest` | Planned |
| UAT-20 | KPI PIC: Management & Admin bisa, Sales ditolak UI & API | 10 | `AuthorizationHttpTest::testUat20` | Planned |
| UAT-21 | Mode gelap semua halaman #000000 | 11 | browser test `tests/browser` | Planned |
| UAT-22 | NPR, timeline, dashboard di tablet & HP | 11 | browser test `tests/browser` | Planned |
| UAT-23 | Ganti bahasa ID/EN: label, menu, status, email berganti; isian tidak | 1/11 | `I18nTest`, `LayoutHttpTest` | Planned |
| UAT-24 | Arsip project Hold lalu pulihkan | 9 | `ProjectServiceTest::testUat24` | Planned |
| UAT-25 | Tidak ada AI Assistant | 1 | `NoAiAssistantTest` | Planned |
