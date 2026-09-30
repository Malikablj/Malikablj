# PR PIK — Aplikasi Purchase Requisition

Aplikasi web internal **PT Permata Indo Kemas (PIK)** untuk membuat, mengajukan, menyetujui, menolak,
merevisi, mencetak, dan mengarsipkan **Purchase Requisition (PR)** — dibangun sesuai *PRD Aplikasi
Pengajuan PR PIK v1.1 (MySQL Edition)*.

| | |
| --- | --- |
| Backend | PHP 8.2+ (MVC sederhana / layered, tanpa framework) |
| Database | **MySQL 8.0+**, InnoDB, utf8mb4, PDO `pdo_mysql` |
| Frontend | HTML5, CSS3, Vanilla JavaScript (tanpa build step) |
| PDF | [dompdf](https://github.com/dompdf/dompdf) via Composer |
| Test | PHPUnit 11 (140 test terhadap MySQL sungguhan) + smoke test HTTP end-to-end |

Alur utama yang berjalan penuh dengan data tersimpan di MySQL:

```
Login → Dashboard → Buat PR → Isi informasi → Tambah item → Review → Submit
      → Approval tahap 1 "Diketahui" → Approval tahap 2 "Disetujui" → Approved
      → Generate PDF → Completed / Arsip
```

---

## Daftar isi

1. [Fitur](#fitur)
2. [Requirements](#requirements)
3. [Instalasi](#instalasi)
4. [MySQL setup & pembuatan database](#mysql-setup--pembuatan-database)
5. [Konfigurasi environment](#konfigurasi-environment)
6. [Migration](#migration)
7. [Seed data demo](#seed-data-demo)
8. [Menjalankan aplikasi](#menjalankan-aplikasi)
9. [Login demo](#login-demo)
10. [Cara pakai singkat](#cara-pakai-singkat)
11. [Aturan bisnis](#aturan-bisnis)
12. [JSON API](#json-api)
13. [Testing](#testing)
14. [Keamanan](#keamanan)
15. [Struktur folder](#struktur-folder)
16. [Troubleshooting](#troubleshooting)
17. [Production deployment](#production-deployment)

---

## Fitur

- **Autentikasi & role** — Requester, Approver, Admin, Super Admin. Password `password_hash()`, session aman,
  pembatasan percobaan login, logout yang benar. Otorisasi **selalu dicek di server**.
- **Dashboard per role** — Total PR, Draft, Menunggu Approval, Revision Required, Approved, Rejected, Total Nilai PR,
  PR terbaru, antrian approval, tombol cepat *Buat PR*.
- **PR** — form bertahap, banyak item (pilih dari master item atau ketik bebas), preview perhitungan realtime,
  simpan draft, review, submit, edit saat draft/revisi, batal, selesai/arsip.
- **Perhitungan** — `Line Total = Qty × Harga`, `Subtotal = Σ Line Total`, `Pajak = Subtotal × Tarif / 100`,
  `Total = Subtotal + Pajak`. Dihitung ulang di server dengan aritmetika desimal eksak (tanpa float), disimpan
  sebagai `DECIMAL(15,2)` dan dijaga `CHECK` constraint.
- **Nomor PR otomatis** — `PR/PIK/SEPT/2026-PDPR077`, unik (`UNIQUE KEY`) dan aman terhadap request bersamaan.
- **Approval workflow configurable** — per department (atau default), tahap berurutan, approver berdasarkan
  *user tertentu* atau *role* (opsional dibatasi department PR), tahap kondisional berdasarkan nilai PR.
- **Approve / Reject / Request Revision** — alasan wajib untuk reject & revisi; riwayat approval tidak pernah dihapus
  (setiap pengajuan ulang tercatat sebagai putaran baru).
- **Notifikasi in-app** — PR diajukan, menunggu approval, tahap disetujui, disetujui, ditolak, perlu revisi,
  dibatalkan, selesai. Tandai dibaca / tandai semua.
- **Lampiran** — validasi ekstensi + MIME dari isi file + ukuran, nama file acak, disimpan di luar folder `public`,
  diunduh lewat pemeriksaan hak akses.
- **PDF** — format dokumen PR PIK (perusahaan/tanggal, judul, department, nomor PR, pemohon, supplier, tabel item,
  subtotal/pajak/total, kolom *Dibuat oleh / Diketahui oleh / Disetujui oleh*), diambil dari database.
- **Audit trail** — login/logout, create/update/submit/approve/reject/revisi/cancel PR, perubahan user, supplier,
  item, department, workflow, pengaturan, unduh PDF, export. Read-only di aplikasi (opsional: trigger MySQL).
- **Master data** — Users, Departments, Suppliers, Items, Approval Workflows (CRUD, aktif/nonaktif, hapus aman FK).
- **Laporan** — filter tanggal, department, supplier, requester, status; jumlah PR & total nilai; rekap per status,
  department, supplier, requester, bulan; **export CSV**.
- **Pengaturan** — ganti password (semua user); nama/alamat perusahaan, prefix nomor PR, pajak default (Super Admin).
- **UI** — minimalis, premium, industrial-corporate; system font; satu warna aksen korporat; responsif desktop,
  tablet, dan mobile (tabel berubah menjadi kartu di layar kecil).

## Requirements

- **PHP 8.2 atau lebih baru** dengan ekstensi: `pdo_mysql`, `mbstring`, `fileinfo`, `json`, `dom`, `gd`
  (untuk gambar di PDF), `zip` (validasi lampiran .docx/.xlsx)
- **MySQL 8.0 atau lebih baru** (InnoDB, utf8mb4)
- **Composer 2**
- Web server: Apache 2.4 atau Nginx + PHP-FPM (untuk development cukup built-in server PHP)

Cek cepat:

```bash
php -v
php -m | grep -Ei 'pdo_mysql|mbstring|fileinfo|dom|gd|zip'
mysql --version
composer --version
```

## Instalasi

```bash
git clone <url-repo>
cd <repo>/pik-pr
composer install            # production: composer install --no-dev --optimize-autoloader
cp .env.example .env        # lalu sesuaikan kredensial MySQL
```

## MySQL setup & pembuatan database

1. Install MySQL 8.0+ (Ubuntu: `sudo apt install mysql-server`; Windows/macOS: MySQL Installer atau
   `brew install mysql`), lalu pastikan service berjalan.
2. Buat database dan user khusus aplikasi (jangan memakai `root` di production):

```sql
CREATE DATABASE pik_pr
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER 'pik_app'@'localhost' IDENTIFIED BY 'ganti-dengan-password-kuat';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
  ON pik_pr.* TO 'pik_app'@'localhost';

-- database untuk automated test (opsional, hanya di mesin developer)
CREATE DATABASE pik_pr_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON pik_pr_test.* TO 'pik_app'@'localhost';
FLUSH PRIVILEGES;
```

> Alternatif: `php bin/migrate.php --create-db` membuat database `DB_DATABASE` (utf8mb4) otomatis
> bila user MySQL memiliki hak `CREATE`.

## Konfigurasi environment

Semua kredensial dibaca dari file `.env` (tidak di-commit) atau environment variable server — environment
variable asli selalu diprioritaskan.

```env
APP_NAME="PR PIK"
APP_ENV=local              # production di server live
APP_DEBUG=true             # WAJIB false di production
APP_URL=http://localhost:8000
APP_TIMEZONE=Asia/Jakarta

DB_CONNECTION=mysql        # satu-satunya driver yang didukung
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pik_pr
DB_USERNAME=pik_app
DB_PASSWORD=ganti-dengan-password-kuat
DB_TEST_DATABASE=pik_pr_test

SESSION_NAME=pik_pr_session
SESSION_LIFETIME=120       # menit idle sebelum logout otomatis
SESSION_SECURE_COOKIE=false  # true bila diakses via HTTPS

UPLOAD_PATH=storage/uploads  # relatif ke folder aplikasi, atau path absolut
UPLOAD_MAX_MB=5

SEED_PASSWORD=             # password akun demo (kosong = PikDemo2026!)
```

Koneksi dibuat di `app/Core/Database.php` dengan DSN `mysql:host=…;port=…;dbname=…;charset=utf8mb4`,
`ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES=false`, `sql_mode` ketat, dan zona waktu MySQL
disamakan dengan `APP_TIMEZONE`.

## Migration

Migration berupa file SQL MySQL di `database/migrations` dan dicatat di tabel `schema_migrations`.

```bash
php bin/migrate.php                 # jalankan migration yang belum dijalankan
php bin/migrate.php --create-db     # buat database dulu (jika belum ada), lalu migrate
php bin/migrate.php --fresh         # HAPUS semua tabel lalu migrate dari nol (ditolak di APP_ENV=production)
```

File SQL juga bisa dijalankan manual: `mysql -u pik_app -p pik_pr < database/migrations/001_create_departments_table.sql` (urut 001 → 011).

Tabel yang dibuat: `departments`, `users`, `suppliers`, `items`, `approval_workflows`, `approval_steps`,
`purchase_requisitions`, `purchase_requisition_items`, `pr_number_sequences`, `approval_logs`, `notifications`,
`attachments`, `audit_logs`, `settings`, `login_attempts` (+ `schema_migrations`).
Semua tabel InnoDB utf8mb4 dengan foreign key, unique constraint, CHECK constraint, dan index pada kolom pencarian
(`pr_number`, `status`, `department_id`, `requester_id`, `supplier_id`, `created_at`, dst).

## Seed data demo

```bash
php bin/seed.php                    # isi data demo ke database kosong
php bin/migrate.php --fresh --seed  # reset total + data demo (development)
```

Data demo: 7 department (termasuk **Production Injection** kode `PD`), 6 user (1 Super Admin, 1 Admin,
2 Requester, 2 Approver), 4 supplier (termasuk **Shopee**), 5 item (termasuk **Lem korea** dan **Autosol**),
workflow default *Diketahui → Disetujui*, dan 8 PR di berbagai status — salah satunya menyerupai dokumen
referensi: Production Injection, pemohon Mitha Alzahra, supplier Shopee, Lem korea 2 × Rp65.000 + Autosol
3 × Rp50.000, subtotal Rp280.000, pajak 0%, total Rp280.000 (status Approved, PDF siap diunduh).
PR demo dibuat lewat service aplikasi sehingga nomor PR, approval log, notifikasi, dan audit trail ikut terisi.

## Menjalankan aplikasi

Development (built-in server PHP):

```bash
composer serve        # = php -S 127.0.0.1:8000 -t public public/index.php
```

Buka **http://127.0.0.1:8000**. Document root aplikasi adalah folder **`public/`** — jangan mengarahkan web server
ke folder project.

## Login demo

Semua akun memakai password **`PikDemo2026!`** (atau nilai `SEED_PASSWORD`). Ganti/nonaktifkan akun demo
sebelum dipakai di production.

| Email | Role | Keterangan |
| --- | --- | --- |
| `superadmin@pik.local` | Super Admin | Semua akses administratif + pengaturan sistem |
| `admin@pik.local` | Admin | Master data, workflow, seluruh PR, laporan, audit log |
| `mitha@pik.local` | Requester | Production Injection — membuat & mengajukan PR |
| `dewi@pik.local` | Requester | Quality Control |
| `budi@pik.local` | Approver | Tahap 1 **Diketahui** |
| `hendra@pik.local` | Approver | Tahap 2 **Disetujui** |

## Cara pakai singkat

1. **Requester** (`mitha@pik.local`) → *Buat PR* → pilih department, supplier, tanggal → tambah item (ketik nama atau
   pilih dari master; satuan & harga terisi otomatis) → atur pajak → lampirkan file bila perlu →
   **Simpan & review**.
2. Di halaman review, periksa checklist lalu **Submit**. Nomor PR terbit dan approver tahap pertama mendapat notifikasi.
3. **Approver** (`budi@pik.local`) → menu *Approval* → *Tinjau* → **Setujui**, **Minta revisi** (alasan wajib),
   atau **Tolak** (alasan wajib).
4. Setelah tahap terakhir (`hendra@pik.local`) menyetujui, status menjadi **Approved** → **Lihat PDF / Unduh**.
5. Requester atau Admin menandai **Selesai & arsipkan** → status **Completed** (filter *Arsip* di daftar PR).
6. Bila diminta revisi: requester membuka PR, klik **Perbaiki PR**, simpan, lalu **Submit ulang** — approval
   dimulai lagi dari tahap pertama, riwayat sebelumnya tetap tampil.

## Aturan bisnis

**Status**: `Draft` → `Submitted` (menunggu tahap 1) → `In Review` (tahap berikutnya) → `Approved` → `Completed`;
cabang `Revision Required`, `Rejected`, `Cancelled`.

| Aksi | Siapa | Status asal |
| --- | --- | --- |
| Buat, ubah, submit | Requester pemilik PR | Draft, Revision Required |
| Approve / revisi / tolak | Approver yang berhak pada tahap berjalan | Submitted, In Review |
| Batalkan | Pemilik (Draft/Submitted/In Review/Revision Required) · Admin (+ Approved) | — |
| Selesai & arsip | Pemilik atau Admin | Approved |
| Lihat PDF | Pihak yang boleh melihat PR | Approved, Completed |

**Nomor PR** — format `{prefix}/{BULAN}/{TAHUN}-{KODE DEPT}PR{urut 3 digit}`, contoh `PR/PIK/SEPT/2026-PDPR077`.
Urutan per department per tahun. Nomor diterbitkan saat **submit pertama** (bukan saat draft) agar draft yang
dibatalkan tidak menghabiskan nomor; nomor tidak berubah saat revisi. Counter di tabel `pr_number_sequences`
di-increment dengan `INSERT … ON DUPLICATE KEY UPDATE` di dalam transaksi submit (row lock InnoDB, retry otomatis
bila deadlock) dan `UNIQUE KEY` pada `pr_number` menjadi pengaman terakhir. Prefix diatur di *Pengaturan*,
singkatan bulan di `config/app.php`. Department dan tanggal terkunci setelah nomor terbit.

**Perhitungan** — dilakukan ulang di server (`app/Services/PrCalculator.php`) dengan integer satuan sen,
pembulatan half-up ke 2 desimal. JavaScript (BigInt) hanya pratinjau dengan rumus yang sama. Nilai total yang
dikirim browser diabaikan.

**Approval workflow**
- Workflow aktif khusus department dipakai lebih dulu; jika tidak ada, dipakai workflow default. Hanya boleh ada
  satu workflow aktif per department (dijamin kolom generated + unique index).
- Approver tahap: *user tertentu* atau *role* (Approver/Admin/Super Admin), opsional *hanya dari department PR*.
- Tahap *kondisional* hanya berlaku bila total PR ≥ nilai minimum (mis. tahap "Final" untuk PR ≥ Rp50 juta).
- Pemohon tidak dapat menyetujui PR sendiri; satu user tidak dapat memutuskan dua tahap pada putaran yang sama.
- Submit ditolak bila workflow belum ada atau ada tahap tanpa approver yang valid.
- Workflow yang sudah dipakai PR dikunci strukturnya (jumlah/urutan tahap); label & approver tetap bisa diganti.
- Setiap keputusan menyimpan PR, tahap, approver, aksi, komentar, status hasil, waktu, dan putaran pengajuan
  (`approval_logs`, unik per PR + putaran + tahap).

**Transaksi** — create/update/submit/approve/cancel masing-masing berjalan dalam satu transaksi MySQL
(header → item → state workflow → audit log → notifikasi). Jika satu langkah gagal, semuanya di-rollback
(termasuk file lampiran yang sudah terlanjur disalin).

## JSON API

Memakai session login yang sama. Request yang mengubah data wajib mengirim header `X-CSRF-Token`
(nilai tersedia di `<meta name="csrf-token">`). Aturan otorisasi identik dengan halaman web.

| Method | Endpoint | Keterangan |
| --- | --- | --- |
| GET | `/api/pr?status=&q=&page=` | Daftar PR yang boleh dilihat user |
| POST | `/api/pr` | Buat draft (`"submit": true` untuk langsung submit) |
| GET | `/api/pr/{id}` | Detail + item + riwayat approval |
| PUT | `/api/pr/{id}` | Ubah draft / revisi |
| POST | `/api/pr/{id}/submit` | Submit |
| POST | `/api/pr/{id}/approve` | `{"comment": "..."}` opsional |
| POST | `/api/pr/{id}/reject` | `{"comment": "..."}` wajib |
| POST | `/api/pr/{id}/revision` | `{"comment": "..."}` wajib |
| GET | `/api/pr/{id}/pdf` | PDF (hanya Approved/Completed) |
| GET | `/api/notifications/unread-count` | Jumlah notifikasi belum dibaca |

Contoh body `POST /api/pr`:

```json
{
  "department_id": 1, "supplier_id": 1, "pr_date": "2026-09-30", "tax_rate": "0", "notes": "",
  "items": [
    {"name": "Lem korea", "quantity": "2", "unit": "pcs", "unit_price": "65000"},
    {"name": "Autosol", "quantity": "3", "unit": "pcs", "unit_price": "50000"}
  ]
}
```

Error validasi → HTTP 422 `{"message": "...", "errors": {"items.0.quantity": "..."}}`; tanpa login → 401;
tanpa hak → 403.

## Testing

Automated test memakai **database MySQL terpisah** (`DB_TEST_DATABASE`, harus berakhiran `_test`) yang dibuat
ulang dari nol (migration + seed) setiap kali test dijalankan; tiap test berjalan dalam transaksi yang di-rollback.

```bash
composer test          # atau: vendor/bin/phpunit
```

Cakupan (140 test): login benar/salah, akun nonaktif, rate limit, logout, sesi idle, CSRF, akses tanpa login;
create/edit/draft/submit PR, perhitungan & pembulatan, validasi, overflow, rollback transaksi; approve, reject,
revisi (riwayat tetap ada), workflow per department & kondisional; matriks otorisasi (requester tidak bisa approve,
approver tidak bisa ke fungsi admin, admin mengelola master data, IDOR, XSS, open redirect); PDF dibuat & isinya
sesuai database (dicek juga dengan `pdftotext` bila tersedia); foreign key, unique nomor PR, CHECK constraint,
kolom DECIMAL, index; **nomor PR bersamaan** dari 6 proses paralel (90 nomor unik tanpa celah); lampiran
(MIME palsu, ekstensi terlarang, ukuran); notifikasi; laporan; export CSV; JSON API.

Smoke test end-to-end lewat HTTP sungguhan (server harus berjalan dan berisi data seed):

```bash
composer serve &
php tests/e2e/smoke.php http://127.0.0.1:8000
```

## Keamanan

- Password `password_hash()` / `password_verify()` (+ rehash otomatis), kebijakan minimal 8 karakter huruf+angka.
- Session: cookie `HttpOnly`, `SameSite=Lax`, `Secure` saat HTTPS, strict mode, regenerasi ID saat login,
  timeout idle, invalidasi saat logout; user yang dinonaktifkan langsung keluar pada request berikutnya.
- CSRF token di setiap form & header `X-CSRF-Token` untuk API; semua aksi yang mengubah data hanya lewat POST/PUT.
- Seluruh query memakai prepared statement (`EMULATE_PREPARES=false`); identifier SQL hanya dari kode.
- Output di-escape (`e()`); Content-Security-Policy tanpa inline script/style, `X-Frame-Options: DENY`,
  `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Cache-Control: no-store`, HSTS saat HTTPS.
- Otorisasi per role **dan** per workflow di server (`PrPolicy`, `ApprovalService`, middleware `role:`).
- Upload: whitelist ekstensi, MIME dari isi file (`finfo`) + verifikasi gambar/arsip Office, batas ukuran,
  nama acak 128-bit, disimpan di `storage/` (di luar `public/`, dilindungi `.htaccess`), unduhan lewat cek hak akses.
- Pembatasan login gagal (5× per 15 menit per email+IP), pesan gagal yang tidak membocorkan keberadaan email.
- Audit log append-only; proteksi tingkat database opsional di `database/hardening/audit_logs_protection.sql`.
- Export CSV dilindungi dari formula injection; redirect setelah login hanya ke path internal.
- Tidak ada secret di repository: `.env` di-ignore, kredensial dari environment.

## Struktur folder

```
pik-pr/
├── app/
│   ├── Core/            # kernel HTTP, router, request/response, session, CSRF, auth, database (PDO MySQL), migrator
│   ├── Controllers/     # controller web + Api/ (JSON)
│   ├── Models/          # enum Role & PrStatus
│   ├── Repositories/    # akses data (prepared statement)
│   ├── Services/        # logika bisnis: PR, approval, kalkulasi, nomor PR, lampiran, PDF, audit, notifikasi, laporan
│   └── Support/         # helper view, Decimal, Timeline
├── bin/                 # migrate.php, seed.php
├── bootstrap/app.php    # autoload, .env, config, timezone
├── config/              # app.php, database.php
├── database/
│   ├── migrations/      # SQL MySQL (001–011)
│   ├── seeders/         # DemoSeeder
│   └── hardening/       # trigger opsional audit log read-only
├── public/              # DOCUMENT ROOT: index.php, .htaccess, assets/{css,js,img}
├── routes/web.php       # semua route web & API
├── storage/             # uploads/, logs/, cache/ (tidak dapat diakses dari web)
├── tests/               # Unit/, Feature/, scripts/, e2e/
└── views/               # template PHP (layouts, pr, approvals, master, pdf, ...)
```

## Troubleshooting

| Gejala | Solusi |
| --- | --- |
| `Dependency belum terpasang` | Jalankan `composer install` di folder `pik-pr`. |
| `could not find driver` / `Ekstensi PHP pdo_mysql belum aktif` | Install & aktifkan `pdo_mysql` (Ubuntu: `sudo apt install php8.3-mysql`; Windows: aktifkan `extension=pdo_mysql` di `php.ini`). |
| `SQLSTATE[HY000] [2002] Connection refused` | MySQL belum berjalan atau `DB_HOST`/`DB_PORT` salah. Coba `DB_HOST=127.0.0.1`. |
| `SQLSTATE[HY000] [1045] Access denied` | Periksa `DB_USERNAME`/`DB_PASSWORD` dan `GRANT` user. |
| `SQLSTATE[HY000] [1049] Unknown database` | Buat database (lihat MySQL setup) atau `php bin/migrate.php --create-db`. |
| Error `CHECK constraint` / `GENERATED` saat migrate | Versi MySQL < 8.0.16. Gunakan MySQL 8.0.16+. |
| `DB_CONNECTION harus "mysql"` | Set `DB_CONNECTION=mysql` di `.env`. |
| Seeder menolak: *Database sudah berisi user* | Gunakan `php bin/migrate.php --fresh --seed` (menghapus semua data!). |
| Test: *Database test harus berakhiran _test* | Isi `DB_TEST_DATABASE=pik_pr_test` dan beri hak akses user MySQL ke database tsb. |
| Halaman 404 untuk semua URL di Apache | Aktifkan `mod_rewrite` dan `AllowOverride All` untuk folder `public/`. |
| Selalu kembali ke login / "Sesi formulir kedaluwarsa" | Pastikan cookie tidak diblokir; bila memakai HTTP (bukan HTTPS) set `SESSION_SECURE_COOKIE=false`; samakan `APP_URL` dengan alamat yang dibuka. |
| Upload gagal "melebihi batas" | Naikkan `UPLOAD_MAX_MB` dan juga `upload_max_filesize` / `post_max_size` di `php.ini`. |
| Upload gagal disimpan | Folder `storage/uploads` harus dapat ditulis user web server (`www-data`). |
| PDF kosong / error font | Pastikan ekstensi `dom`, `mbstring`, `gd` aktif dan `storage/cache` dapat ditulis. |
| Error 500 | Lihat `storage/logs/app.log`; untuk development set `APP_DEBUG=true`. |

## Production deployment

1. **Server**: PHP 8.2+ (PHP-FPM), MySQL 8.0+, Nginx atau Apache, HTTPS (mis. Let's Encrypt).
2. **Kode & dependency**
   ```bash
   composer install --no-dev --optimize-autoloader
   cp .env.example .env   # APP_ENV=production, APP_DEBUG=false, SESSION_SECURE_COOKIE=true, kredensial DB
   php bin/migrate.php    # jangan gunakan --fresh di production
   ```
   Buat akun Super Admin pertama dengan seeder di server staging lalu ganti password, atau jalankan
   `php bin/seed.php --force` sekali lalu segera ubah password dan nonaktifkan akun demo yang tidak dipakai.
3. **Permission**
   ```bash
   chown -R www-data:www-data storage
   find storage -type d -exec chmod 750 {} \;
   chmod 640 .env
   ```
4. **Nginx** (document root = `public/`):
   ```nginx
   server {
       listen 443 ssl http2;
       server_name pr.pik.local;
       root /var/www/pik-pr/public;
       index index.php;
       client_max_body_size 20M;

       location / { try_files $uri /index.php?$query_string; }
       location ~ \.php$ {
           include fastcgi_params;
           fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
           fastcgi_param HTTPS on;
           fastcgi_pass unix:/run/php/php8.3-fpm.sock;
       }
       location ~ /\. { deny all; }
   }
   ```
5. **Apache**: `DocumentRoot /var/www/pik-pr/public`, `<Directory /var/www/pik-pr/public> AllowOverride All </Directory>`,
   aktifkan `mod_rewrite`. File `public/.htaccess` sudah disertakan; `.htaccess` di root project & `storage/`
   menolak akses bila document root salah diarahkan.
6. **MySQL hardening**: user aplikasi dengan hak minimum (`SELECT, INSERT, UPDATE, DELETE` setelah migration);
   opsional kunci audit log di level database:
   `mysql -u root -p pik_pr < database/hardening/audit_logs_protection.sql`.
7. **Backup** rutin: `mysqldump --single-transaction --routines --triggers pik_pr > backup.sql` + folder `storage/uploads`.
8. **PHP**: `display_errors=Off`, `expose_php=Off`, `session.cookie_secure=1`, sesuaikan `upload_max_filesize`
   dan `post_max_size` dengan `UPLOAD_MAX_MB`.
9. **Cek pasca-deploy**: login, buat PR uji, submit, approve, unduh PDF; atau jalankan
   `php tests/e2e/smoke.php https://pr.pik.local` terhadap server staging berisi data demo.
