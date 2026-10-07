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
| AUTH-01 | Login email + password | 2.4 | Must | 1 | `modules/Core/Auth.php`, `public/login.php` | Done | `tests/Integration/AuthTest.php`, `tests/Http/AuthHttpTest.php` |
| AUTH-02 | Password di-hash (bcrypt/argon2) — `password_hash()` / `password_verify()`, tidak pernah plaintext | 2.4, 13.2 | Must | 1 | `Auth`, `UserService` | Done | `AuthTest` |
| AUTH-03 | Admin membuat, menonaktifkan, mereset password user | 2.4 | Must | 1 | `modules/User/UserService.php`, `public/settings/users.php` | Done | `UserServiceTest` |
| AUTH-04 | User mengganti password sendiri | 2.4 | Must | 1 | `public/profile.php` | Done | `UserServiceTest` |
| AUTH-05 | Sesi berakhir otomatis setelah tidak aktif (default 8 jam, dapat diatur) | 2.4 | Must | 1 | `modules/Core/Session.php` | Done | `SessionTest`, `AuthHttpTest` |
| AUTH-06 | Penguncian sementara setelah beberapa kali gagal login (rate limiting) | 2.4, 13.2 | Must | 1 | `modules/Core/LoginThrottle.php` | Done | `LoginThrottleTest`, `AuthHttpTest` |
| AUTH-07 | Akun nonaktif tidak bisa login, nama tetap tampil di riwayat/audit | 2.4 | Must | 1 | `Auth`, audit snapshot `user_name` | Done | `AuthTest` |
| AUTH-08 | Cookie sesi HttpOnly, Secure (HTTPS), SameSite; regenerasi ID sesi setelah login; logout menghancurkan sesi | 13.2 | Must | 1 | `Session` | Done | `AuthHttpTest` |

## 2. Role & hak akses (PRD §2.1–2.3)

| ID | Kebutuhan | PRD | Prio | Fase | Modul / file | Status | Test |
| --- | --- | --- | --- | --- | --- | --- | --- |
| ROLE-01 | 8 role: Admin, Admin Sales, NPD Staff, Drafter, Purchasing, Production, Quality, Management | 2.1 | Must | 1 | `database/seeds/roles.php`, tabel `roles` | Done | `GateTest` |
| ROLE-02 | Semua role melihat semua project | 2.2 | Must | 1/3 | `Gate::can('project.view')` | Done | `GateTest` |
| ROLE-03 | Sales hanya mengubah NPR/project di mana ia Sales PIC; Drafter hanya proses yang di-assign | 2.2 | Must | 1/2/4 | `Gate` (scope `own`) | Partial | `GateTest`, `NprServiceTest` |
| ROLE-04 | NPD Staff & Admin mengubah seluruh project; Management read-only | 2.2 | Must | 1 | `Gate` | Done | `GateTest`, `AuthorizationHttpTest` |
| ROLE-05 | Matriks hak akses §2.3 ditegakkan di server pada setiap request/API/unduhan | 2.3, 13.2 | Must | 1+ | `includes/permissions.php`, `Gate`, service layer | Done | `AuthorizationHttpTest` |

## 3. Model data, status, penomoran (PRD §3)

| ID | Kebutuhan | PRD | Prio | Fase | Modul / file | Status | Test |
| --- | --- | --- | --- | --- | --- | --- | --- |
| DATA-01 | Hierarki Project → Part → Proses; part beda jenis dalam satu project | 3.1 | Must | 3 | `projects`, `project_parts`, `processes`, `ProjectService`, `ProjectQuery`, `public/project.php` | Done | `WorkflowEngineTest`, `ProjectHttpTest` |
| DATA-02 | Part ditolak/dibatalkan tidak membatalkan project; project Cancelled bila semua part Cancelled | 3.1 | Must | 3 | `StatusService`, `ProjectService::cancelPartForNprPart`, `WorkflowEngine::onPartCancelled` | Done | `StatusServiceTest`, `WorkflowEngineTest::testPartCancelledAfterStartDetachesFromGate`, `NprServiceTest` |
| DATA-03 | Project satu part tampil sederhana | 3.1 | Must | 6 | `public/project.php` | Done | `TimelineHttpTest`, `ProjectHttpTest` (project satu part tampil sederhana: satu baris part) |
| DATA-04 | Status proses: Not Started, Current, Completed, Revision, Problem, Skipped; >1 proses aktif per part | 3.3 | Must | 4 | `WorkflowEngine` | Done | `WorkflowEngineTest` (N1 & N3 aktif bersamaan) |
| DATA-05 | Status turunan part/project sesuai tabel §3.3 (Waiting Approval > Waiting External > On Progress, Hold, Siap Finish, Cancelled) | 3.3 | Must | 4 | `App\Project\StatusService` | Done | `StatusServiceTest`, `WorkflowEngineTest` |
| DATA-06 | Overdue = tanda tambahan terhitung (bukan status dasar) | 3.3, 7.1 | Must | 8 | `App\Scheduling\Lateness`, `ProjectQuery` | Partial | `ProjectQuery` menampilkan Overdue (hari kerja); notifikasi & panel fase 8 |
| DATA-07 | Kode project NPD-YYYY-XXX urut per tahun | 3.4 | Must | 2 | `NumberSequence` | Done | `NumberSequenceTest` (konkurensi) |
| DATA-08 | Nomor NPR NO/PIK/NPR/Bulan Romawi/Tahun, reset tiap tahun, dibuat saat pertama dikirim | 3.4 | Must | 2 | `NumberSequence`, `NprService::submit` | Done | `NumberSequenceTest`, `NprServiceTest` |
| DATA-09 | No. dokumen export: NPR = PIK-FORM-NPD-01 rev 00; timeline = PIK-FORM-NPD-07 tanpa revisi | 3.4 | Must | 2/6 | `NprPdf`, `TimelineExport` | Done | `PdfExportTest`, `ExcelExportTest` |

## 4. NPR digital (PRD §4, Lampiran A)

| ID | Kebutuhan | PRD | Prio | Fase | Modul / file | Status | Test |
| --- | --- | --- | --- | --- | --- | --- | --- |
| FR-NPR-01 | Form NPR memuat seluruh bagian PIK-FORM-NPD-01 + legenda biru/pink | 4.2, Lamp. A | Must | 2 | `public/npr-edit.php`, `modules/Npr/NprFields.php` | Done | `NprFieldsTest` (cakupan Lampiran A) |
| FR-NPR-02 | Kolom biru hanya Sales (dan Admin); pink hanya NPD (dan Admin); server menolak di luar hak | 4.2 | Must | 2 | `NprService` + `Gate` | Done | `NprServiceTest`, `NprHttpTest` (UAT-02) |
| FR-NPR-03 | Jumlah & nama part dinamis; master nama part oleh Admin; opsi "Lainnya" | 4.3 | Must | 2 | `NprService`, `MasterService` | Done | `NprServiceTest` |
| FR-NPR-04 | Nomor NPR otomatis, urut per tahun, tanpa duplikat meski bersamaan | 3.4, 4.7 | Must | 1/2 | `NumberSequence` (atomic upsert) | Done (mesin penomoran; dipakai saat Kirim NPR di fase 2) | `NumberSequenceTest` (6 proses paralel) |
| FR-NPR-05 | Setelah kirim kolom biru terkunci; NPD dapat mengembalikan dengan alasan; Revision History otomatis tanpa nomor revisi | 4.1 | Must | 2 | `NprService::return/submit`, `revision_history` | Done | `NprServiceTest` (UAT-05) |
| FR-NPR-06 | Feedback per part tersimpan draft, dipublikasikan saat Selesaikan Feedback | 4.1, 4.5 | Must | 2 | `NprFeedbackService` | Done | `NprServiceTest` |
| FR-NPR-07 | Tidak Feasible membatalkan part terkait saja; project batal bila semua part batal | 4.5 | Must | 2/3 | `NprFeedbackService`, `StatusService` | Done | `NprServiceTest` (UAT-03) |
| FR-NPR-08 | Export PDF NPR mengikuti format form, memuat seluruh data Sales + feedback NPD | 4.6 | Must | 2 | `modules/Report/NprPdf.php` (mPDF) | Done | `PdfExportTest` (UAT-04) |
| FR-NPR-09 | Harga mould % PIK + % Customer = 100% (validasi saat simpan) | 4.7 | Should | 2 | `NprFeedbackService` | Done | `NprServiceTest` |
| FR-NPR-10 | Upload lampiran gambar/file maks. 25 MB (Contoh Bentuk Produk, Referensi Spek) | 4.7 | Must | 2/7 | `DocumentService` | Done | `DocumentServiceTest` |
| FR-NPR-11 | Requested by / Received by otomatis dari akun; tanpa blok tanda tangan | 4.2 | Must | 2 | `NprService` | Done | `NprServiceTest`, `PdfExportTest` |
| FR-NPR-12 | Pratinjau PDF berwatermark "BELUM SELESAI FEEDBACK" sebelum Selesai Feedback | 4.6 | Could | 2 | `NprPdf` | Done | `PdfExportTest` |
| NPR-13 | Status NPR: Draft, Dikirim, Dikembalikan, Selesai Feedback + siapa yang boleh mengubah | 4.1 | Must | 2 | `NprService` | Done | `NprServiceTest` |
| NPR-14 | Simpan draft & autosave; stepper di HP | 4.1, 11.6 | Must | 2/11 | `public/assets/js/npr-form.js`, `public/api/npr-autosave.php` | Done | `NprHttpTest` |
| NPR-15 | Daftar master NPR dikelola Admin (nonaktif, bukan hapus; data lama tetap terbaca) | 4.4 | Must | 2 | `MasterService`, `public/settings/masters.php` | Done | `MasterServiceTest` |
| NPR-16 | Perlu Revisi → NPR kembali ke Sales; part lain tetap; part yang diubah ditandai "Perlu ditinjau ulang" | 4.5 | Must | 2 | `NprFeedbackService` | Done | `NprServiceTest` |
| NPR-17 | "Perlu Masterbatch Baru" per part menentukan Masterbatch dijalankan / Tidak dijalankan | 4.5 | Must | 2/4 | `WorkflowInstantiator` | Done | `WorkflowEngineTest::testNoMasterbatchSkipsGroupAndPlanFollows` |
| NPR-18 | Hapus part yang sudah punya feedback/proses tidak diizinkan → Cancel dengan alasan | 4.3 | Must | 2 | `NprService` | Done | `NprServiceTest` |
| NPR-19 | Jenis part (New Mold/Subcont) per part; Mould & Feedback per part | 4.2 | Must | 2 | `npr_parts`, `npr_feedback` | Done | `NprServiceTest` |
| NPR-20 | Nama file PDF `NPR_<nomor>_<produk>.pdf`; export dicatat di aktivitas | 4.6 | Must | 2 | `public/export.php` | Done | `PdfExportTest` |

## 5. Workflow & dependency (PRD §5)

| ID | Kebutuhan | PRD | Prio | Fase | Modul / file | Status | Test |
| --- | --- | --- | --- | --- | --- | --- | --- |
| FR-WF-01 | Banyak proses aktif bersamaan per part; Tracker & detail part menampilkan semuanya | 5 | Must | 4/6 | `WorkflowEngine`, `public/project.php`, `public/process.php` | Done | `WorkflowEngineTest` (UAT-06), `TimelineTest` (Tracker menampilkan semua proses aktif) |
| FR-WF-02 | Dependency FS, SS, FF, Paralel; lag ±; banyak predecessor | 5.3 | Must | 4/5 | `DependencyService`, `Scheduler` | Done | `SchedulerTest`, `DependencyServiceTest` |
| FR-WF-03 | Aktivasi otomatis saat syarat terpenuhi; FF memblokir penyelesaian dini | 5.4 | Must | 4 | `WorkflowEngine::activateReady/complete/ffBlockers`, `cron/overdue.php` | Done | `WorkflowEngineTest`, `DailyActivationTest`, `SchedulerTest` (FF) |
| FR-WF-04 | Tolak dependency melingkar (template & override) | 5.3 | Must | 4 | `DependencyService`, `Scheduler::topologicalOrder` (CycleException) | Partial | `DependencyServiceTest::testCycleIsRejected` (UAT-13); validasi template fase 4/Pengaturan Workflow |
| FR-WF-05 | Override dependency per project oleh NPD dengan pratinjau + audit sebelum/sesudah | 5.5 | Must | 4/5 | `DependencyService::preview/save`, `public/api/schedule-preview.php` | Done | `DependencyServiceTest` (UAT-12), `ProjectHttpTest`, `tests/browser/project_flow.py` |
| FR-WF-06 | "Tidak dijalankan": hanya proses yang diizinkan, alasan wajib, Admin/NPD, pasangan, pembatalan | 5.6 | Must | 4 | `WorkflowEngine::skip/unskip/canSkipNow` | Done | `WorkflowEngineTest` (UAT-11, OQ-26) |
| FR-WF-07 | Loop approval/trial/T0/validasi dengan iterasi | 5.2 | Must | 4 | `WorkflowEngine::complete` (repeat/loop/activate/gate_fail) | Done | `WorkflowEngineTest` (UAT-15 setara N4, T0 Not OK, Commissioning NG) |
| FR-WF-08 | Gate Assembly/Fit Test opsional, predecessor milestone part aktif | 5.7 | Should | 4 | `WorkflowEngine::applyGateFail/recordGate`, `project_gates` | Done | `WorkflowEngineTest::testGateFailReopensChosenPartsAndPassContinues` |
| FR-WF-09 | Template workflow berversi; project menyimpan versi | 5.8 | Must | 4 | `WorkflowInstantiator` (snapshot versi template) | Partial | `WorkflowEngineTest`; UI Pengaturan Workflow fase 4b/11 |
| FR-WF-10 | Koreksi manual proses aktif oleh Admin dengan alasan | 5.4 | Should | 4 | `WorkflowEngine::manualMove` | Done | `WorkflowEngineTest`, `ProjectHttpTest` (403 non-Admin) |
| WF-11 | Workflow bawaan level project (P1, P2, G1, PF), New Mold (N1–N14), Subcont (S1–S11) | 5.1 | Must | 4 | `database/seeds/workflows.php` | Done | `WorkflowEngineTest` |
| WF-12 | Aturan aktivasi §5.4 (dokumen wajib, approval, mandatory sebelum Finish) | 5.4 | Must | 4/7 | `WorkflowEngine` | Partial | `WorkflowEngineTest::testRequiredDocumentBlocksCompletion`, `testFinishPartAndProjectLifecycle`; approval terhubung fase 7 |
| WF-13 | Validasi cross-part: dependency antar part hanya lewat gate level project | 5.3 | Must | 4 | `DependencyService::scopeAllowed` | Done | `DependencyServiceTest::testCrossPartAndInvalidInputRejected` |
| WF-14 | Lag negatif tidak membuat mulai sebelum part/predecessor mulai | 5.3 | Must | 5 | `Scheduler` | Done | `SchedulerTest` |
| WF-15 | Pengaturan Workflow Admin: atribut proses §5.8; proses bawaan hanya dinonaktifkan | 5.8 | Must | 4 | `public/settings/workflow.php` | Planned | `WorkflowTemplateTest` |
| WF-16 | Perubahan template tidak mengubah project berjalan kecuali Admin menerapkan ke proses belum mulai (dengan pratinjau) | 5.5 | Must | 4 | `WorkflowTemplateService::applyToRunning` | Planned | `WorkflowTemplateTest` |

## 6. Penjadwalan & timeline (PRD §6)

| ID | Kebutuhan | PRD | Prio | Fase | Modul / file | Status | Test |
| --- | --- | --- | --- | --- | --- | --- | --- |
| FR-SCH-01 | Input planning opsional (durasi, Planned Start, Planned Finish); yang kosong otomatis | 6.1 | Must | 5 | `Scheduler`, `WorkflowEngine::plan/previewPlan`, `public/process.php` | Done | `SchedulerTest` (tabel §6.1), `WorkflowEngineTest::testPlanChangesDurationAndShiftsSuccessorsWithPreview` |
| FR-SCH-02 | Hari kerja Senin–Jumat + hari libur Admin | 6.2 | Must | 5 | `WorkingCalendar` | Done | `WorkingCalendarTest` |
| FR-SCH-03 | Tanggal manual = "tidak mulai sebelum"; peringatan bila bertentangan dependency | 6.1 | Must | 5 | `Scheduler` | Done | `SchedulerTest` (UAT-08) |
| FR-SCH-04 | Aktual ≠ rencana → proses bergantung bergeser; proses tak terkait tidak; semua tercatat | 6.4 | Must | 5 | `Scheduler`, `ScheduleService::recalculate`, `schedule_changes` | Done | `SchedulerTest` (contoh §6.4), `WorkflowEngineTest::testCompleteActivatesSuccessorAndShiftsLateSchedule` (UAT-07) |
| FR-SCH-05 | Forecast Finish proses & perkiraan selesai part/project; Target Finish tetap; tanda Berisiko | 6.3, 6.4 | Must | 5 | `Scheduler`, `ScheduleService::checkTargetRisk`, `ProjectQuery::atRisk` | Done | `SchedulerTest`, `ScheduleServiceTest` (UAT-10) |
| FR-SCH-06 | Timeline dua level (Project & Part) dengan klik part | 6.6 | Must | 6 | `TimelineService`, `public/timeline.php`, `includes/gantt.php`, `public/assets/js/gantt.js` | Done | `TimelineTest`, `TimelineHttpTest` (UAT-18), `tests/browser/timeline_flow.py` |
| FR-SCH-07 | Export timeline PDF & Excel dengan No. Dokumen PIK-FORM-NPD-07 pojok kanan atas | 6.7 | Must | 6 | `TimelineExport`, `TimelinePdf` (mPDF), `TimelineExcel` (PhpSpreadsheet), `public/export.php` | Done | `TimelineTest` (No. dokumen tiap halaman/sheet, tanggal asli, freeze, filter), `TimelineHttpTest` (UAT-19) |
| FR-SCH-08 | Baseline berversi + batang baseline di Gantt | 6.5 | Should | 5/6 | `ScheduleService::createBaseline/setBaseline`, batang baseline Gantt | Done | `ScheduleServiceTest`, `TimelineTest`, browser toggle baseline |
| FR-SCH-09 | Penyorotan jalur kritis pada Gantt | 6 | Could | 6 | `TimelineService::criticalInPart`, toggle Gantt | Done | `TimelineTest::testPartLevelRowsDependenciesCriticalAndBars` |
| FR-SCH-10 | Pengaturan "tarik maju jadwal bila selesai lebih awal" | 6.4 | Should | 5 | `Scheduler` + setting `schedule.pull_forward_on_early_finish` | Done | `SchedulerTest` |
| SCH-11 | Planned Start/Finish selalu hari kerja; tanggal aktual boleh hari apa pun | 6.2 | Must | 5 | `Scheduler` | Done | `SchedulerTest`, `WorkflowEngineTest` |
| SCH-12 | Urutan topologis; FF memperpanjang durasi; Skipped durasi nol meneruskan tanggal | 6.3 | Must | 5 | `Scheduler` | Done | `SchedulerTest` |
| SCH-13 | Proses berjalan mempertahankan Planned; Forecast = max(Planned Finish, hari ini) | 6.3 | Must | 5 | `Scheduler` | Done | `SchedulerTest` |
| SCH-14 | Ikon kunci tanggal manual; proses Overdue merah; Skipped bergaris | 6.6 | Must | 6 | `includes/gantt.php`, `public/timeline.php` | Done | `TimelineTest`, `timeline_flow.py` (kunci tanggal manual, Overdue merah, Skipped bergaris) |
| SCH-15 | Gantt lintas project (Project/Part), filter customer/PIC/status/jenis, baseline opsional | 6.8 | Must | 6 | `PortfolioQuery::gantt`, `public/gantt.php` | Done | `TimelineTest::testPortfolioGanttFiltersAndTracker`, `TimelineHttpTest` |
| SCH-16 | Process Tracker: kartu per part-proses aktif | 6.8 | Must | 6 | `PortfolioQuery::tracker`, `public/tracker.php` | Done | `TimelineTest`, `TimelineHttpTest` |
| SCH-17 | Kalender: deadline, approval, trial, commissioning, material, validasi, agenda manual, libur | 6.8 | Must | 6 | `CalendarService`, `public/calendar.php` | Done | `TimelineTest::testCalendarEventsAndAgendaPermissions`, `TimelineHttpTest` |
| SCH-18 | Hitung ulang jadwal satu project ≤ 1 detik (10 part × 20 proses), dalam satu transaksi | 13.1, 13.3 | Must | 5/12 | `Scheduler` | Done | `SchedulerTest` (201 node < 1 dtk), recalc dalam transaksi + `FOR UPDATE` |
| SCH-19 | Persetujuan Target Finish baru oleh NPD/Admin dengan alasan; tidak mengubah baseline | 6.4 | Must | 5 | `ScheduleService::changeTarget` | Done | `ScheduleServiceTest` |

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
| DOC-01 | 18 tipe dokumen (Lampiran B) per project › part › proses | 9.1 | Must | 7 | `master_options(document_type)`, `DocumentService::addProcessDocument` | Done | `DocumentApprovalRecordTest` |
| DOC-02 | Revisi tidak menimpa; status Current/Superseded/Rejected/Approved | 9.1 | Must | 7 | `DocumentService` (versi, superseded; approved/rejected dari keputusan approval) | Done | `DocumentApprovalRecordTest`::testDocumentVersioningSearchAndRemoval, ::testApprovalPendingOnActivationThenDecidedWithEvidence |
| DOC-03 | Maks. 25 MB; ekstensi diatur Admin; validasi MIME/ekstensi/nama di server | 9.1, 13.2 | Must | 7 | `UploadValidator` | Done | `UploadValidatorTest` |
| DOC-04 | Dokumen wajib mencegah penyelesaian proses; kategori Attention "Missing Mandatory Document" | 9.1, 5.4 | Must | 7 | `WorkflowEngine`, `AttentionService` | Partial | `WorkflowEngineTest::testRequiredDocumentBlocksCompletion`; kategori Attention di dashboard fase 10 |
| DOC-05 | Halaman Dokumen: pencarian & filter, pratinjau gambar/PDF | 9.1 | Must | 7 | `DocumentService::search`, `public/documents.php` | Done | `DocumentApprovalRecordTest`, `RecordHttpTest` |
| DOC-06 | File di luar webroot, unduh hanya lewat sesi + cek hak akses | 9.1, 13.2 | Must | 7 | `public/download.php`, `storage/documents` | Done | `DocumentHttpTest` |
| APR-01 | 10 tipe approval; pemberi customer/internal | 9.2 | Must | 7 | `ApprovalService::TYPES`, `WorkflowEngine::recordApproval` | Done | `DocumentApprovalRecordTest` |
| APR-02 | Approval customer dicatat Sales/NPD/Admin dengan bukti & komentar | 9.2 | Must | 7 | `WorkflowEngine::recordApproval` (bukti `evidence_document_id`), `public/process.php` | Done | `DocumentApprovalRecordTest`::testApprovalPendingOnActivationThenDecidedWithEvidence |
| APR-03 | Status Pending/Approved/Rejected/Revision Required terhubung ke workflow (loop) | 9.2 | Must | 7 | `ApprovalService::requestFor/withdrawPending` + `WorkflowEngine` | Done | `DocumentApprovalRecordTest`, `WorkflowEngineTest` (loop) |
| APR-04 | Approval menyimpan revisi dokumen, pemohon, tanggal, iterasi; halaman antrean & riwayat | 9.2 | Must | 7 | `ApprovalService::search`, `public/approvals.php`, tab Approval project | Done | `DocumentApprovalRecordTest`, `RecordHttpTest` |
| REC-01 | Record Trial/T0/Commissioning, Material, Validation per part; Purchasing update material | 9.3 | Must | 7 | `RecordService`, kartu catatan di `public/process.php`, tab Trial & Material | Done | `DocumentApprovalRecordTest`, `RecordHttpTest::testRecordPermissions` |
| FR-AUD-01 | Audit log append-only: siapa, kapan, IP, aksi, entitas, sebelum/sesudah, alasan | 9.4 | Must | 1+ | `AuditLogger`, `database/hardening.sql` | Done | `AuditLoggerTest` |
| FR-AUD-02 | Cakupan audit: NPR, feedback, jadwal, dependency, shift otomatis, skip, hold, target, approval, dokumen, pengaturan, arsip, export, login | 9.4 | Must | 1+ | semua service | Partial | per modul |
| FR-AUD-03 | Tab Activity semua role; audit log penuh hanya Admin; Revision History | 9.4 | Must | 6/7 | `public/project.php?tab=activity`, `public/settings/audit.php`, Revision History | Done | `RecordHttpTest::testPagesForAllRoles`, `AuditAndSettingsTest` |
| NA-01 | Next Action, jatuh tempo, Waiting For per part; notifikasi pemilik | 9.5 | Must | 8 | `NextActionService`, `public/project.php` | Partial | `DocumentApprovalRecordTest`::testNextActionReplaceCompleteAndPermissions; notifikasi jatuh tempo/terlambat lewat cron fase 8 |
| PD-01 | Halaman detail project dengan 9 tab (Ringkasan … Activity) | 9.6 | Must | 6 | `public/project.php` + `includes/project_header.php` (Ringkasan, Proses, Timeline, Approval, Dokumen, Trial & Material, Riwayat, Activity; NPR & Feedback lewat tombol Buka NPR) | Done | `ProjectHttpTest`, `RecordHttpTest` |

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
| FR-UI-01 | Token warna, font Inter self-hosted, aturan aksen | 11.2–11.4 | Must | 1/11 | `public/assets/css/app.css` | Partial | `UiContractTest` |
| FR-UI-02 | Mode gelap #000000 di semua halaman/komponen, tanpa kilatan terang | 11.3 | Must | 1/11 | CSS tokens + `data-theme` | Partial | `UiContractTest`, browser test (UAT-21) |
| FR-UI-03 | Responsif laptop/tablet/HP (drawer, kartu, stepper, Gantt scroll, target sentuh ≥ 44px) | 11.6 | Must | 11 | CSS | Planned | browser test (UAT-22) |
| FR-UI-04 | Animasi 150–250 ms, menghormati reduce motion | 11.5 | Should | 11 | CSS | Planned | `UiContractTest` |
| FR-UI-05 | Kolom NPR biru/pink dengan legenda dan varian gelap | 11.2 | Must | 2 | CSS `--sales-tint`/`--npd-tint` | Planned | `UiContractTest` |
| UI-06 | Logo PIK di login, header, kop PDF/Excel; varian mode gelap | 11.4 | Must | 1/6 | `public/assets/images/logo-*.png` | Partial | `UiContractTest` |
| UI-07 | Navigasi: Dashboard · Project · Process Tracker · Gantt · Kalender · Dokumen · Approval · Laporan · Notifikasi · Pengaturan (Admin) | 11.7 | Must | 1 | `includes/layout/sidebar.php` | Done | `LayoutHttpTest` |
| UI-08 | Aksesibilitas: kontras WCAG AA, keyboard, fokus terlihat, status tidak hanya warna | 11.8 | Must | 11 | CSS/markup | Planned | `UiContractTest` |
| I18N-01 | Bahasa Indonesia (bawaan) & Inggris; pilihan di header, tersimpan di profil | 12 | Must | 1 | `lang/id.php`, `lang/en.php`, `I18n` | Done | `I18nTest` (UAT-23) |
| I18N-02 | Diterjemahkan: label, menu, tombol, status, pesan, email, judul/kolom export; isian user tidak | 12 | Must | 1+ | `t()` | Partial | `I18nTest` (kunci lengkap kedua bahasa) |
| I18N-03 | Format tanggal per bahasa (05 Okt 2026 / 05 Oct 2026) | 12 | Must | 1 | `I18n::date()` | Done | `I18nTest` |
| I18N-04 | PDF NPR selalu Bahasa Indonesia | 12 | Must | 2 | `NprPdf` | Done | `PdfExportTest` |

## 12. Non-fungsional (PRD §13)

| ID | Kebutuhan | PRD | Prio | Fase | Modul / file | Status | Test |
| --- | --- | --- | --- | --- | --- | --- | --- |
| NFR-01 | PHP 8.2+, MySQL 8 InnoDB utf8mb4 | 13.1 | Must | 0/1 | `database/schema.sql` | Done | `SchemaTest` |
| NFR-02 | Mesin jadwal di server sebagai satu-satunya sumber kebenaran, satu transaksi | 13.1 | Must | 5 | `Scheduler` | Planned | `SchedulerTest` |
| NFR-03 | Tugas terjadwal: overdue/due soon, Hold reminder, ringkasan harian, antrean email | 13.1 | Must | 8 | `cron/*.php` | Planned | `CronTest` |
| NFR-04 | PDF & Excel dibuat server (mPDF, PhpSpreadsheet) | 13.1 | Must | 2/6 | `modules/Report` | Done | `PdfExportTest` |
| NFR-05 | Zona waktu Asia/Jakarta | 13.1 | Must | 1 | `config/config.php` | Done | `ConfigTest` |
| NFR-06 | Konfigurasi lewat environment (.env) untuk dev/UAT/prod | 13.1 | Must | 1 | `config/config.php`, `.env.example` | Done | `ConfigTest` |
| NFR-07 | HTTPS, CSRF, XSS escaping, query terparameter | 13.2 | Must | 1+ | `Csrf`, `e()`, `Db` | Done | `SecurityHttpTest` |
| NFR-08 | Kinerja: halaman utama ≤ 2 dtk, API p95 ≤ 500 ms, export ≤ 15 dtk | 13.3 | Must | 12 | — | Planned | `PerformanceTest` |
| NFR-09 | Backup harian DB + file, retensi ≥ 30 hari, uji pemulihan | 13.4 | Must | 13 | `docs/BACKUP_AND_RESTORE.md`, `bin/backup.sh` | Planned | restore drill |
| NFR-10 | Penguncian optimistik (peringatan bila data berubah) | 13.4 | Must | 2+ | kolom `lock_version` | Done | `OptimisticLockTest` |
| NFR-11 | Pencatatan error & pemantauan dasar; migrasi skema tanpa kehilangan data | 13.4 | Must | 1/13 | `storage/logs`, `database/migrations` | Partial | `MigrationTest` |
| NFR-12 | Tidak ada AI Assistant (menu, halaman, endpoint) | 1.4 | Must | 1 | — | Done | `NoAiAssistantTest` (UAT-25) |

## 13. Skenario UAT (PRD §15) → test otomatis

| UAT | Skenario | Fase | Test otomatis | Status |
| --- | --- | --- | --- | --- |
| UAT-01 | NPR 3 part (Body New Mold, Cap Subcont, "Lainnya") dikirim → nomor, biru terkunci, notifikasi NPD | 2/8 | `NprServiceTest::testUat01…`, `NprHttpTest`, `tests/browser/npr_flow.py` | Done |
| UAT-02 | Sales isi pink / NPD isi biru setelah kirim → ditolak server | 2 | `NprServiceTest::testUat02…`, `NprHttpTest::testSalesFullDraftSubmitAndBlueLockedForNpd` | Done |
| UAT-03 | Feedback Body Feasible, Cap Feasible+catatan, Plug Tidak Feasible → Plug batal, lainnya jalan | 2/4 | `NprServiceTest::testUat03…` | Partial (feedback, publikasi, pembatalan part selesai; workflow part mulai berjalan di fase 4) |
| UAT-04 | Export PDF NPR setelah Selesai Feedback | 2 | `NprPdfTest::testUat04…` | Done |
| UAT-05 | NPD kembalikan NPR; Sales ubah & kirim ulang → riwayat tanpa nomor revisi | 2 | `NprServiceTest::testUat05…` | Done |
| UAT-06 | Part New Mold mulai: Masterbatch & 3D aktif bersamaan; 2D menunggu keduanya | 4 | `WorkflowEngineTest::testFeedbackCompletedStartsAcceptedPartsWithBaseline` | Done |
| UAT-07 | Develop MB terlambat 2 hari kerja → turunan bergeser +2, 3D tidak, tercatat, notifikasi | 5 | `SchedulerTest` (contoh §6.4), `WorkflowEngineTest::testCompleteActivatesSuccessorAndShiftsLateSchedule` | Done |
| UAT-08 | Planned Start manual lebih awal dari dependency → peringatan & tanggal dependency | 5 | `SchedulerTest` (manual_before_dependency) | Done |
| UAT-09 | Proses lewat Planned Finish → merah, panel overdue, email hari pertama | 8 | `OverdueServiceTest::testUat09` | Planned |
| UAT-10 | Perkiraan selesai > Target Finish → target tetap, ditandai, target baru lewat persetujuan | 5 | `ScheduleServiceTest` | Done |
| UAT-11 | "Tidak dijalankan" 3D Prototype (pasangan Customer 3D Approval) | 4 | `WorkflowEngineTest::testSkipGroupPassesThroughAuditsAndUnskipRules` | Done |
| UAT-12 | Ubah dependency 2D Drawing jadi paralel dengan approval 3D → pratinjau + audit | 4/5 | `DependencyServiceTest::testParallelOverrideWithPreviewThenSaveAndAudit` | Done |
| UAT-13 | Dependency melingkar ditolak | 4 | `DependencyServiceTest::testCycleIsRejected`, `ProjectHttpTest` | Done |
| UAT-14 | Selesaikan proses FF sebelum predecessor selesai → ditolak | 4 | `WorkflowEngineTest::testFinishToFinishBlocksEarlyCompletion` | Done |
| UAT-15 | Customer Artwork Approval Not Approved → kembali ke Artwork, iterasi +1, jadwal dihitung ulang | 4 | `WorkflowEngineTest::testArtworkNotApprovedReturnsToArtwork` | Done |
| UAT-16 | Hold part lalu project 30 hari → beku, tidak overdue, pengingat 30 hari | 9 | `HoldServiceTest::testUat16` | Planned |
| UAT-17 | Resume project → target baru wajib, baseline baru, jadwal lama tersimpan | 9 | `HoldServiceTest::testUat17` | Planned |
| UAT-18 | Management buka timeline, klik part BODY → Level 2, tidak dapat mengubah | 6 | `TimelineHttpTest::testPagesRenderForAllRoles` (Management melihat Level 1/2 tanpa form planning) | Done |
| UAT-19 | Export timeline PDF & Excel, PIK-FORM-NPD-07 di kanan atas, overdue merah | 6 | `TimelineTest::testTimelinePdfHasDocNumberOnEveryPageAndOverdueRows`, `testTimelineExcelSheetsDatesFreezeFilterAndDocNumber`, `TimelineHttpTest::testExports` | Done |
| UAT-20 | KPI PIC: Management & Admin bisa, Sales ditolak UI & API | 10 | `AuthorizationHttpTest::testUat20` | Planned |
| UAT-21 | Mode gelap semua halaman #000000 | 11 | browser test `tests/browser` | Planned |
| UAT-22 | NPR, timeline, dashboard di tablet & HP | 11 | browser test `tests/browser` | Planned |
| UAT-23 | Ganti bahasa ID/EN: label, menu, status, email berganti; isian tidak | 1/11 | `I18nTest`, `LayoutHttpTest` | Partial (label/menu/tersimpan di profil; email & status menyusul) |
| UAT-24 | Arsip project Hold lalu pulihkan | 9 | `ProjectServiceTest::testUat24` | Planned |
| UAT-25 | Tidak ada AI Assistant | 1 | `SchemaTest::testNoAiAssistantArtifacts`, `AuthorizationHttpTest::testNoAiAssistantEndpoint` | Done |
