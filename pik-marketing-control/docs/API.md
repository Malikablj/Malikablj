# API — PIK Marketing Control

Kontrak antara frontend (HtmlService) dan backend Apps Script. Kode: `src/api/Api.gs` (tabel route), `src/services/*.gs` (handler).

## 1. Transport

Frontend hanya memanggil satu fungsi server:

```js
google.script.run
  .withSuccessHandler(function (envelope) { /* { success, data } atau { success: false, error } */ })
  .withFailureHandler(function (error) { /* jaringan / eksekusi gagal */ })
  .api('customers.list', { search: 'kemasan', page: 1 }, token);
```

`token` = token sesi dari `auth.login` (login email + password); `null` untuk sesi akun Google. Di klien dibungkus
`PIK.api(action, payload)` (Promise; `src/web/JsCore.html`), yang menyertakan token tersimpan secara otomatis. Nilai yang dikirim dan diterima hanya JSON
(string, angka, boolean, null, objek, array). Server tidak pernah mengembalikan `Date`: tanggal = teks `yyyy-MM-dd`, waktu = teks
ISO 8601 UTC. Test memeriksa setiap respons API terhadap aturan ini.

## 2. Amplop respons dan error

```json
{ "success": true, "data": { } }
{ "success": false, "error": { "code": "VALIDATION_ERROR", "message": "…", "details": { } } }
```

| `code` | Arti | `details` |
|---|---|---|
| `VALIDATION_ERROR` | Input tidak valid | `errors: [{ field, code, message, index?, table? }]`. Kode kolom: `REQUIRED`, `TYPE`, `MAX_LENGTH`, `PATTERN`, `ENUM`, `ENUM_INACTIVE`, `MIN`, `MAX`, `REF_NOT_FOUND`, `REF_INACTIVE`, `REF_MISMATCH`, `RULE`, `UNIQUE`, `UNKNOWN_FIELD`, `SYSTEM_FIELD`, `LEGACY_FORBIDDEN`, `IN_USE`, `OVER_QUANTITY`. Untuk `OVER_QUANTITY`: `confirmable: true`, `available` |
| `NOT_FOUND` | Record atau aksi tidak ada | — |
| `CONFLICT` | Record sudah diubah orang lain sejak dibaca | `updatedAt` terbaru |
| `FORBIDDEN` | Role tidak berhak | `module`, `access` |
| `NOT_REGISTERED` | Akun tidak terdaftar / dinonaktifkan | `email` |
| `AUTH_REQUIRED` | Belum masuk (tidak ada token dan Google tidak mengenali pengunjung), atau sesi berakhir | `reason`: `SIGN_IN` / `SESSION_ENDED` |
| `PASSWORD_CHANGE_REQUIRED` | Masuk dengan password sementara: ganti password dulu | — |
| `TOO_MANY_ATTEMPTS` | Terlalu banyak login gagal untuk email ini | `retryAfterMinutes` |
| `LOCK_TIMEOUT` | Penulisan lain sedang berjalan; coba lagi | — |
| `SCHEMA_MISMATCH`, `CONFIG_MISSING`, `READ_ONLY` | Masalah database/konfigurasi | — |
| `INTERNAL_ERROR` | Error tak terduga (detail hanya di log server) | — |

**Konfirmasi jumlah.** Delivery/retur/qty order yang melebihi batas item PO ditolak dengan `OVER_QUANTITY` + `confirmable`. Klien
menampilkan dialog konfirmasi lalu mengirim ulang dengan `confirmOverQuantity: true`.

**Optimistic concurrency.** Update mengirim `expectedUpdatedAt` (nilai `updated_at` yang dilihat klien). Bila berbeda → `CONFLICT`.

## 3. Identitas dan otorisasi

- Identitas, berurutan (`src/auth/Auth.gs`, `src/auth/PasswordAuth.gs`):
  1. token sesi dari `auth.login` (email + password) — dipakai bila web app dipasang di akun Gmail biasa;
  2. akun Google pemanggil (`Session.getActiveUser()`: web app di Google Workspace yang sama, atau pemilik skrip);
  3. tidak ada keduanya → `AUTH_REQUIRED` (browser menampilkan form email + password).
  Keduanya dicocokkan dengan `USERS` (email tanpa beda huruf besar/kecil). Role selalu dari `USERS`, tidak pernah dari payload
  atau token.
- Token: `v1.<id user>.<dibuat ms>.<berakhir ms>.<nonce>.<tanda tangan HMAC-SHA256>`, berlaku 30 hari; ditolak bila dibuat
  sebelum password terakhir diganti/di-reset atau user nonaktif.
- Pertama kali: selama `USERS` belum punya ADMIN aktif, pemilik skrip otomatis didaftarkan sebagai ADMIN saat membuka aplikasi
  dengan akun Google-nya (tercatat di AUDIT_LOG). Pada akun Gmail biasa, jalankan `setupAdminAccount()` dari editor: akun pemilik
  menjadi ADMIN dengan password sementara di log eksekusi.
- Setiap route punya izin `[modul, 'R' | 'RW']` yang dicek di server sebelum handler berjalan (matriks: `src/auth/Auth.gs`,
  `MODULE_PERMISSIONS`; keputusan D5). Data keuangan di layar bersama (detail customer/PO, dashboard) hanya dikirim ke role yang
  boleh membaca modul `finance`.

| Modul | Admin | Marketing | Sales | Management | Viewer |
|---|---|---|---|---|---|
| dashboard, reports, products, stock, leadTime, inbound, deliveries, returns | RW | R | R | R | R |
| customers, contacts, leads, activities, followUps | RW | RW | RW | R | R |
| purchaseOrders (termasuk item PO) | RW | RW | R | R | R |
| finance (invoice & pembayaran) | RW | — | — | R | — |
| users, settings, migrationIssues | RW | — | — | — | — |
| audit | R | — | — | — | — |

## 4. Parameter daftar

Semua aksi `*.list` (dan `stock.summary`) menerima:

```js
{
  search: 'teks',                  // semua kata harus cocok (tanpa beda huruf/aksen)
  filters: { status: 'OPEN', po_date: { from: '2026-01-01', to: '2026-03-31' } },
  sort: { field: 'po_date', direction: 'desc' },
  page: 1, pageSize: 25,           // pageSize dibatasi SETTINGS.MAX_PAGE_SIZE; default DEFAULT_PAGE_SIZE
  includeInactive: false           // true = termasuk record yang diarsipkan
}
// → { items, total, page, pageSize, pageCount }
```

Filter atau kolom urut yang tidak dikenal ditolak (`VALIDATION_ERROR`), bukan diabaikan. Nilai kosong selalu di akhir urutan.
Filter tanggal pada kolom waktu (mis. `activity_at`) memakai tanggal kalender zona aplikasi (Asia/Jakarta).

Picker (`*.options`) menerima `{ search, limit (≤ 50), include }` → `{ items: [{ id, label, sublabel }], total }`; `include` = ID
terpilih yang tetap dikirim walau diarsipkan.

## 5. Aksi

Kolom **Izin** = modul dan akses yang dibutuhkan. `data` = kolom yang boleh diisi form (kolom lain diabaikan server).

### Sesi dan umum

| Aksi | Izin | Input | Output |
|---|---|---|---|
| `auth.login` | **publik** | `{ email, password }` | `{ token, expiresAt, session }`. Gagal: `VALIDATION_ERROR` "Email atau password salah." (sama untuk email tak terdaftar), `NOT_REGISTERED` (nonaktif, hanya bila password benar), `TOO_MANY_ATTEMPTS` (5× gagal → 15 menit) |
| `auth.changePassword` | terdaftar (juga saat wajib ganti) | `{ currentPassword, newPassword }` | `{ token, expiresAt, user }`; sesi lain berakhir. User Google tanpa password boleh membuat tanpa `currentPassword`. Kebijakan: ≥ 8 karakter, huruf dan angka, bukan email |
| `session.get` | terdaftar (juga saat wajib ganti) | — | `{ user, permissions, enums, settings, schema, app: { name, schemaVersion, timeZone, today, now } }`; `user` memuat `signInMethod` (`google`/`password`), `hasPassword`, `mustChangePassword` |
| `session.login` | terdaftar | — | Sama dengan `session.get`; mencatat `last_login_at` (paling sering tiap 10 menit) |
| `users.options` | terdaftar | picker | User aktif untuk pilihan PIC |
| `search.global` | terdaftar | `{ query }` (≥ 2 huruf) | `{ groups: [{ key, label, total, items: [{ id, title, subtitle }] }] }` — hanya modul yang boleh dibaca |
| `audit.history` | baca modul record | `{ entityType, entityId }` | Riwayat record terbaru dulu (termasuk batch migrasi yang membuatnya) |

### Dashboard dan laporan

| Aksi | Izin | Input | Output |
|---|---|---|---|
| `dashboard.summary` | dashboard R | `{ scope: 'all' \| 'mine' }` | `kpis` (totalCustomers, activeCustomers, activeLeads, openLeadValue, followUpToday, followUpOverdue, openPurchaseOrders, outstandingQuantity), `leadPipeline`, `followUpsToday`, `followUpsOverdue`, `recentActivities`, `openPurchaseOrders`, `finance` (null tanpa akses keuangan). `mine` membatasi lead/follow-up/aktivitas ke PIC = user |
| `reports.customers` / `leads` / `activities` / `purchaseOrders` / `deliveries` | reports R | `{ from, to, customer_id, owner_user_id, status, type, format }` | `{ summary, groups, rows (≤ 500, kolom tampil saja), totalRows, columns, filters }`; `format: 'csv'` → `{ filename, csv, rows }` (UTF-8 BOM, sel formula dinetralkan) |

### CRM

| Aksi | Izin | Catatan |
|---|---|---|
| `customers.list` / `get` / `options` | customers R | Filter: `status`, `owner_user_id`, `industry`, `has_open_po`, `has_overdue_follow_up`. `get` = workspace: customer + statistik, contacts, leads, activities, followUps (`due_state`), purchaseOrders (ringkasan qty), deliveries, returns, invoices (null tanpa akses keuangan); daftar ≤ 100 terbaru + total |
| `customers.create` / `update` / `archive` / `restore` | customers RW | PIC default = user aktif |
| `contacts.list` / `options` | contacts R | Filter: `customer_id`, `is_primary` |
| `contacts.create` / `update` / `setPrimary` / `archive` / `restore` | contacts RW | Satu contact utama aktif per customer; contact utama lama diturunkan dalam penulisan yang sama |
| `leads.list` / `board` / `get` / `options` | leads R | `board` = kolom per status aktif: `count`, `total_value`, ≤ 50 kartu. Filter: `status`, `owner_user_id`, `customer_id`, `priority`, `open`, `expected_closing_date`, `created_at` |
| `leads.create` / `update` / `move` / `archive` / `restore` | leads RW | Default status `NEW`, PIC = user; customer diambil dari contact bila kosong; `move` = `{ id, status, expectedUpdatedAt }` |
| `activities.list` | activities R | Filter: `type`, `owner_user_id`, `customer_id`, `lead_id`, `contact_id`, `activity_at` |
| `activities.create` / `update` / `archive` / `restore` | activities RW | Customer wajib (atau dari lead/contact). `create` dengan `followUp: { follow_up_date, follow_up_time, purpose, priority, type }` membuat follow-up berikutnya dalam satu unit (keduanya atau tidak sama sekali) |
| `followUps.list` / `counts` | followUps R | Filter `bucket`: `TODAY`, `OVERDUE`, `UPCOMING`, `OPEN`, `RESCHEDULE`, `DONE`, `CANCELLED` (dihitung dari tanggal hari ini, D14); `counts` = jumlah per bucket |
| `followUps.create` / `update` / `archive` / `restore` | followUps RW | Default tanggal hari ini, status `PLANNED`, PIC = user; customer wajib |
| `followUps.complete` | followUps RW | `{ id, result, next?, expectedUpdatedAt }` → DONE (+ follow-up berikutnya, divalidasi sebelum penulisan pertama) |
| `followUps.reschedule` / `cancel` | followUps RW | Hanya follow-up terbuka; reschedule = tanggal baru + status `RESCHEDULE` |

### Operasional dan inventori

| Aksi | Izin | Catatan |
|---|---|---|
| `purchaseOrders.list` / `get` / `options` / `lineOptions` | purchaseOrders R | Ringkasan per PO: `line_count`, `order_quantity`, `delivered_quantity`, `returned_quantity`, `outstanding_quantity`, `scheduled_quantity`, `order_value`, `fulfillment` (`NO_LINES`/`NOT_STARTED`/`PARTIAL`/`COMPLETE`), qty legacy belum tertaut. `get` = PO + item (progres per item), delivery, retur, lead time, inbound, invoice/ringkasan keuangan (sesuai izin). `lineOptions` = item PO terbuka + sisa |
| `purchaseOrders.create` | purchaseOrders RW | `{ data, lines: [{ product_id, order_quantity, unit, unit_price, notes }] }` — header + item satu unit. Default status `OPEN`, tanggal hari ini, PIC = user, satuan dari produk |
| `purchaseOrders.update` / `setStatus` / `archive` / `restore` | purchaseOrders RW | PO dengan delivery/retur aktif tidak dapat diarsipkan (gunakan status Cancelled) |
| `poLines.create` / `update` / `archive` / `restore` | purchaseOrders RW | Produk tidak dapat diganti setelah ada delivery/retur; qty order di bawah qty terkirim bersih → `OVER_QUANTITY`; item dengan transaksi aktif tidak dapat diarsipkan |
| `deliveries.list` | deliveries R | Filter: `status` (`NONE` = tanpa status/legacy), `purchase_order_id`, `po_line_id`, `product_id`, `customer_id`, `linked`, `is_legacy`, `delivery_date` |
| `deliveries.create` / `update` / `archive` / `restore` | deliveries RW | Delivery baru wajib item PO terbuka; PO dan produk dari item. Qty > sisa (outstanding − terjadwal) → `OVER_QUANTITY`. Menautkan delivery legacy ke item hanya bila PO/produknya cocok (`REF_MISMATCH`) — penyelesaian D3 oleh Admin |
| `returns.list` / `create` / `update` / `archive` / `restore` | returns R / RW | Dengan item PO: PO/produk dari item; qty > terkirim − retur → `OVER_QUANTITY`. Retur `CANCELLED` tidak dihitung |
| `inbound.list` | inbound R | Hanya baca (dipelihara migrasi/Admin) |
| `products.list` / `get` / `options` | products R | `stock_fg`, `stock_wip` (stok terbaru), `open_outstanding_quantity`. `get` = produk + riwayat stok + item PO + lead time |
| `products.create` / `update` / `archive` / `restore` | products RW | |
| `stock.list` / `summary` | stock R | `summary` = stok terbaru per produk & jenis + total per jenis + jumlah catatan legacy tanpa produk |
| `stock.create` / `update` / `archive` / `restore` | stock RW | Default tanggal hari ini, jenis FG |
| `leadTime.list` / `create` / `update` / `archive` / `restore` | leadTime R / RW | PO/produk dari item PO, customer dari PO. Jadwal tidak dihitung sebagai terkirim (D10) |

### Keuangan

| Aksi | Izin | Catatan |
|---|---|---|
| `invoices.list` / `get` | finance R | `outstanding_amount`, `is_overdue`, customer lewat PO. Filter: `payment_status`, `invoice_type`, `purchase_order_id`, `customer_id`, `overdue`, `invoice_date`, `due_date` |
| `invoices.create` / `update` / `archive` / `restore` | finance RW | `payment_status` selalu dihitung dari nilai (bukan dari klien); `invoice_type` dari segmen ke-4 nomor bila kosong |
| `invoices.recordPayment` | finance RW | `{ id, paid_amount (total dibayar), payment_date, payment_receipt_number, payment_attachment_url, expectedUpdatedAt }` |

### Administrasi

| Aksi | Izin | Catatan |
|---|---|---|
| `users.list` / `create` / `update` / `archive` / `restore` | users R / RW | Email unik tanpa beda huruf. Admin tidak dapat menonaktifkan akun sendiri atau mengubah email sendiri; Admin aktif terakhir tidak dapat dinonaktifkan atau diganti role-nya. Baris user memuat `has_password`, `must_change_password` (hash tidak pernah dikirim). `create` = `{ data, password? }`: password awal bersifat sementara |
| `users.setPassword` | users RW | `{ id, password, mustChange (default true) }`: password baru untuk user lain (bukan akun sendiri); sesi user itu berakhir, kunci login dibuka |
| `settings.list` / `settings.update` | settings R / RW | `{ key, value }`; setting sistem ditolak (`FORBIDDEN`); nilai dicek per tipe dan batas (mis. `MAX_PAGE_SIZE` ≥ `DEFAULT_PAGE_SIZE`) |
| `enums.list` / `create` / `update` | settings R / RW | Nilai baru hanya untuk enum yang dapat diperluas (`ACTIVITY_TYPE`); nilai sistem tidak dapat dinonaktifkan; dropdown sheet ikut diperbarui; audit `SETTING_UPDATE` |
| `migrationIssues.list` / `resolve` | migrationIssues R / RW | `list` + `counts` (terbuka per tingkat/jenis). `resolve` = `{ id, resolution_status, resolution_note, expectedUpdatedAt }`; catatan wajib kecuali membuka kembali; `resolved_by`/`resolved_at` diisi server |
| `audit.list` | audit R | Filter: `action`, `entity_type`, `entity_id`, `actor_email`, `occurred_at`; pencarian juga membaca isi perubahan |
