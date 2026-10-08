# Keamanan — NPD Project Control v3.0

Ringkasan kontrol keamanan yang terimplementasi (PRD §2.3, §2.4, §13.2) dan test otomatis yang
membuktikannya. Semua test di bawah dijalankan oleh `vendor/bin/phpunit` (lihat hasil terakhir di
`docs/IMPLEMENTATION_PLAN.md`, Phase 12).

## 1. Autentikasi & sesi

| Kontrol | Implementasi | Test |
| --- | --- | --- |
| Password tidak pernah disimpan plaintext | `password_hash(PASSWORD_DEFAULT)`, hash lama di-upgrade saat login (`Auth`) | `AuthTest::testPasswordIsHashedNeverPlaintext`, `testWeakHashIsUpgradedOnLogin` |
| Kebijakan password | min. 8 karakter (setting `security.password_min_length`), maks. 128, huruf + angka (`UserService::assertPasswordStrength`) | `UserServiceTest::testPasswordComplexity` |
| Pesan login generik (tidak membocorkan email terdaftar) | email tak dikenal & password salah → pesan sama, waktu verifikasi setara (hash dummy) | `AuthTest::testUnknownEmailGivesSameMessage`, `AuthHttpTest::testWrongPasswordReturns401WithGenericMessage` |
| Rate limiting login | 5 gagal → terkunci 15 menit (setting `security.login_max_attempts`, `security.login_lockout_minutes`), per email + IP (`LoginThrottle`) | `AuthTest::testLockoutAfterMaxFailedAttempts`, `AuthHttpTest::testRateLimitLocksAfterFiveFailures` |
| Cookie sesi aman | `HttpOnly`, `SameSite=Lax`, `Secure` otomatis saat HTTPS (`SESSION_SECURE=auto|1`), `session.use_strict_mode` | `AuthHttpTest::testLoginPageSecurityHeadersAndCookieFlags` |
| Fiksasi sesi dicegah | ID sesi diregenerasi saat login dan berkala (30 menit) | `AuthHttpTest::testLoginSuccessRegeneratesSessionId` |
| Sesi berakhir saat tidak aktif | bawaan 8 jam (setting `security.session_timeout_minutes`) | `AuthHttpTest::testSessionIdleTimeout` |
| User nonaktif kehilangan akses seketika | sesi aktif ditolak pada request berikutnya | `AuthTest::testDeactivatedUserLosesSessionAccess`, `AuthHttpTest::testInactiveUserRejected` |
| Logout hanya POST + CSRF, sesi dihancurkan | `public/logout.php` | `AuthHttpTest::testLogoutRequiresPostAndDestroysSession` |

## 2. Otorisasi (server-side)

- Matriks role × izin × scope (`all` / `own`) disimpan di tabel `role_permissions` dan ditegakkan oleh
  `App\Core\Gate` di **service** (bukan hanya menyembunyikan tombol). Halaman memakai `require_permission()`,
  aksi memanggil `Gate::authorize()` sebelum validasi data; penolakan → HTTP 403 dan dicatat `authz.denied`
  di audit log.
- Scope `own`: Sales hanya mengubah NPR/project di mana ia Sales PIC; Drafter/Purchasing/Production/Quality
  hanya menyelesaikan proses yang menjadi PIC-nya.
- KPI per PIC hanya Admin & Management (`kpi.view`), juga untuk export yang URL-nya diketik langsung.

Test:
- `RoleMatrixHttpTest::testEveryRoleIsDeniedWhatItHasNoPermissionFor` — untuk 8 role, 30 halaman/aksi istimewa
  (pengguna, customer, master, hari libur, workflow, notifikasi, antrean email, audit, KPI + export KPI, Hold,
  Resume, batal project/part, arsip, ubah target, baseline, PIC part, planning, manual move, skip, dependency,
  komentar, agenda kalender, buat NPR) dikirim langsung **dengan token CSRF sah**; setiap kombinasi yang tidak
  diizinkan menurut tabel `role_permissions` wajib 403 dan checksum 22 tabel data tidak berubah.
  Uji mutasi: menghapus `Gate::authorize('hold.manage')` membuat test ini gagal (303 ≠ 403).
- `RoleMatrixHttpTest::testOwnScopeCannotBeBypassedById` — Sales lain mengubah project/NPR orang lain lewat ID,
  PIC role lain menyelesaikan proses yang bukan miliknya → ditolak, data tidak berubah (IDOR).
- `RoleMatrixHttpTest::testRolesWithPermissionReachThePages`, `AuthorizationHttpTest`, `GateTest`,
  `ReportsHttpTest` (KPI 403 untuk Sales).

## 3. CSRF

- Token acak 32 byte per sesi (`Csrf`), dibandingkan dengan `hash_equals`, dikirim sebagai field `_csrf`
  atau header `X-CSRF-Token` (fetch/JSON).
- **Pertahanan berlapis:** `includes/bootstrap.php` memverifikasi CSRF untuk *setiap* request
  POST/PUT/PATCH/DELETE sebelum kode halaman berjalan, sehingga endpoint yang lupa memanggil
  `require_post()` tetap terlindungi. Gagal → 419 (HTML) atau 419 JSON untuk API.

Test: `SecuritySweepHttpTest::testEveryPostEndpointRequiresCsrfToken` mencari otomatis semua file di
`public/` yang menangani POST (≥ 20 endpoint) dan memastikan tanpa token / token palsu → 419;
`AuthHttpTest::testLoginWithoutCsrfRejected`, `AuthorizationHttpTest::testCsrfRequiredForAdminPost`.

## 4. XSS & header

- Semua output di-escape (`e()`, `t()` mengescape parameter, `fmt_date()`); atribut JSON lewat `json_attr()`.
- Content-Security-Policy: `default-src 'self'; script-src 'self' 'nonce-…'; object-src 'none';
  frame-ancestors 'none'; base-uri 'self'; form-action 'self'` — skrip inline wajib ber-nonce, tidak ada
  handler event inline.
- `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`,
  `Permissions-Policy`, `Cross-Origin-Opener-Policy: same-origin`, HSTS saat HTTPS, `X-Powered-By` dihapus.

Test: `SecuritySweepHttpTest::testStoredXssIsEscapedOnAllPages` (payload `<script>`/`onerror` disuntikkan ke
11 tabel lalu ±27 halaman diperiksa), `testSecurityHeadersOnEveryResponseType` (HTML, JSON, unduhan, error),
`CoreHelpersTest::testEscapeHelperNeutralisesHtml`, `testJsonAttrIsSafeInsideAttributes`.

## 5. SQL injection & input

- Seluruh query memakai prepared statement PDO (`Db`), emulasi prepare dimatikan; nama kolom/tabel dinamis
  hanya lewat whitelist (`Db::assertIdentifier`). Tidak ada SQL dari konkatenasi input.
- Mass assignment: service hanya membaca field yang diizinkan.
- Redirect "kembali ke" hanya jalur lokal (`Request::safeReturnPath`).
- Excel: teks ditulis sebagai string eksplisit (mencegah formula injection `=`, `+`, `-`, `@`).

Test: `SecuritySweepHttpTest::testInjectionProbesAndInvalidInputNeverCrash` (probe SQLi di parameter, ID tidak
valid → 303/400/403/404 tanpa `SQLSTATE`), `testMassAssignmentIsIgnored`, `AuthorizationHttpTest::testSqlInjectionAttemptIsHarmless`,
`CoreHelpersTest::testSqlIdentifierWhitelist`, `testSafeReturnPathRejectsOpenRedirects`,
`ReportsTest::testWorkbookFormatsByColumnRangeAndFlagsOnlyMarkedRows`.

## 6. Dokumen & upload

- File disimpan di `storage/documents` (di luar web root, nama acak), diunduh hanya lewat
  `public/download.php` setelah cek sesi + izin, dengan `Content-Disposition: attachment`,
  `Cache-Control: no-store`, `nosniff`.
- Validasi upload (`UploadValidator`): batas ukuran (bawaan 25 MB, setting `upload.max_mb`), ekstensi harus
  di whitelist Admin, ekstensi berbahaya selalu ditolak (php, phtml, phar, html, svg, js, exe, …), MIME dari
  **isi** file (finfo) harus cocok dengan ekstensi, file harus hasil `is_uploaded_file`.

Test: `UploadValidatorTest` (10 test), `NprHttpTest::testAttachmentUploadDownloadAndAccessControl`,
`RoleMatrixHttpTest::testDocumentsAreServedOnlyThroughAuthorizedDownload`,
`SecuritySweepHttpTest` (storage tidak di bawah `public/`, file di luar web root tidak terbaca).

## 7. Audit & rahasia

- Audit log append-only: aplikasi tidak memiliki kode UPDATE/DELETE untuk `audit_logs`; opsional trigger
  database (`database/hardening.sql`) menolak UPDATE/DELETE. Nilai sensitif (password, token, `_csrf`) disamarkan.
- Rahasia di database (password SMTP) dienkripsi libsodium secretbox dengan `APP_KEY` dari environment.
- Konfigurasi dari `.env` (tidak di web root, tidak di-commit); bawaan aman `APP_ENV=production`, `APP_DEBUG=false`
  (detail error tidak tampil).

Test: `AuditAndSettingsTest` (testAuditRecordsAllRequiredFields, testSensitiveValuesRedacted,
testNoApplicationCodeUpdatesOrDeletesAuditLogs, testSecretSettingEncryptedAtRest), `CoreHelpersTest::testCryptoRoundTripAndTamperDetection`,
`ConfigAndMastersTest::testConfigurationComesFromEnvironment`.

## 8. Deployment

- HTTPS wajib (contoh `deploy/nginx.conf`, `deploy/apache-vhost.conf`); `SESSION_SECURE=1`, `TRUSTED_PROXIES` bila di
  belakang proxy. Web root hanya folder `public/`; kode milik root, hanya `storage/` yang dapat ditulis web server.
- Tiga akun MySQL berhak minimal: admin (instalasi/migrasi/pemulihan), aplikasi (`bin/db-grants.php`: DML per tabel,
  `audit_logs` hanya SELECT/INSERT), backup (`--role=backup`: SELECT, SHOW VIEW, TRIGGER). Terverifikasi: dengan akun
  aplikasi, `UPDATE/DELETE audit_logs`, `DROP`, `ALTER` ditolak MySQL (1142) sementara seluruh alur browser & cron berjalan.
- Kiriman di atas `post_max_size` dijawab 413 dengan batas ukuran (PHP membuang token CSRF pada kiriman seperti itu);
  browser menolak file di atas batas sebelum diunggah. Test: `SecuritySweepHttpTest::testOversizedSubmissionExplainsLimitInsteadOfSessionExpired`.
- `bin/check-deployment.php --url=…` memeriksa konfigurasi, header, cookie, jalur sensitif, HTTPS, batas unggah.
- Detail: `docs/DEPLOYMENT.md`.
