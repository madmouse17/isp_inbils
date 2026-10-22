# Module: Ticketing

> Status: DRAFT v2 (ISP pivot 2026-06-30). Phase 6 eksekusi. Dependency:
> Phase 1 (Core) + Phase 2 (Customer/Service) + Phase 3 (NetworkAsset) +
> Phase 4 (SPK). Lokasi module: `Modules/Ticketing/` (nwidart).
> ISP: Ticketing = workflow pelayanan ISP. Source: customer complaint /
> NOC monitoring / internal report. Kategori ISP (no internet, slow,
> packet loss, device issue, fiber issue).

## Tujuan

Mengelola tiket pelayanan ISP: dari customer complaint, NOC monitoring,
internal report. Auto-routing ke unit terkait, Kepala Unit assignment,
SLA tracking, spawn SPK untuk field work. Link ke customer + subscription
+ network asset + location untuk trace cepat. History: ticket + status +
assignment + response time + resolution time.

## User Role

| Role | Hak |
|------|-----|
| admin | full CRUD + assign + resolve + close + spawn SPK + delete |
| manager (Kepala Unit) | view + assign + resolve + close + spawn SPK (no delete) |
| noc | view + create (from monitoring) + assign to self + resolve + spawn SPK |
| staff | view + create (customer complaint intake) + comment (no assign/resolve) |
| technician | view assigned to self + comment + resolve (limited) |
| customer (v2 portal) | create own ticket + view own + comment (no assign) |

## Entity

### Ticket (Modules/Ticketing/Models/Ticket.php)
Tiket pelayanan. Source (customer/noc/internal). Kategori ISP. Link ke
customer + subscription + network_asset + location (issue location) +
spk (spawned). Status workflow. SLA tracking (deadline + breach).

### TicketCategory (Modules/Ticketing/Models/TicketCategory.php)
Kategori ISP: no_internet, slow_connection, packet_loss, device_issue,
fiber_issue. Default SLA per category (hours). Configurable + admin add
custom (v2).

### TicketComment (Modules/Ticketing/Models/TicketComment.php)
Komentar internal/public. Public = visible customer (v2 portal). Internal
= staff-only. Untuk collaboration + resolution notes.

### TicketAttachment (Modules/Ticketing/Models/TicketAttachment.php)
Lampiran (screenshot error, foto dari customer, log). Link ke comment
(optional) atau langsung ke ticket.

## Database Relation

```
companies ──< ticket_categories
companies ──< tickets
customers ──< tickets (FK customer_id nullable — wajib jika source=customer)
service_subscriptions ──< tickets (FK subscription_id nullable)
network_assets ──< tickets (FK network_asset_id nullable — traced asset)
locations ──< tickets (FK location_id nullable — issue location, POP/area)
users ──< tickets (FK created_by, assigned_to active)
ticket_categories ──< tickets (FK category_id)
work_orders ──< tickets (FK spawned_spk_id nullable — backlink SPK spawned)
tickets ──< ticket_comments (cascade)
users ──< ticket_comments (FK author_id)
tickets ──< ticket_attachments (cascade)
ticket_comments ──< ticket_attachments (FK comment_id nullable)
```

Skema: `docs/DATABASE.md` Section 10 (Ticketing).

## Entity Fields

### ticket_categories
- id, company_id FK NOT NULL, name NOT NULL, code NOT NULL, default_sla_hours
  int NOT NULL (jam kalender), default_priority enum(low/medium/high/urgent)
  NOT NULL default 'medium', is_active boolean default true, created_at,
  updated_at, deleted_at.
- Unique: `(company_id, code)`. Trait `BelongsToCompany`. Soft delete.
- Seed v1: no_internet (4h, urgent), slow_connection (8h, high),
  packet_loss (8h, high), device_issue (12h, medium), fiber_issue (12h,
  high).

### tickets
- id, company_id FK NOT NULL, code NOT NULL, title NOT NULL, description
  text nullable, source enum(customer/noc/internal) NOT NULL, category_id
  FK NOT NULL (restrict), status enum
  (open/assigned/on_progress/resolved/closed) NOT NULL default 'open',
  priority enum(low/medium/high/urgent) NOT NULL default 'medium',
  customer_id nullable FK→customers.id (restrict — wajib jika source=
  customer), subscription_id nullable FK→service_subscriptions.id
  (restrict), network_asset_id nullable FK→network_assets.id (restrict —
  traced asset), location_id nullable FK→locations.id (restrict — issue
  location), assigned_to nullable FK→users.id (restrict — active handler),
  spawned_spk_id nullable FK→work_orders.id (restrict — SPK spawned from
  ticket), `response_deadline` timestamp nullable, `sla_deadline` timestamp nullable,
  `first_response_at` timestamp nullable, `first_response_source` string nullable
  (`reply|start`), `response_due_notified_at` timestamp nullable,
  `response_breached_notified_at` timestamp nullable, `resolved_at` timestamp
  nullable, `reopened_at` timestamp nullable, `closed_at` timestamp nullable,
  `resolution_note` text nullable, `created_by` FK→users.id NOT NULL, created_at,
  updated_at, deleted_at. Saat `close`, dua kolom nama beku diisi:
  `category_name_snapshot` string nullable dan `assignee_name_snapshot` string
  nullable (lihat "Snapshot tiket tertutup"). Penilaian pelanggan: `customer_rating`
  tinyint unsigned nullable (1 sampai 5), `customer_rating_comment` text nullable,
  `customer_rated_at` timestamp nullable; kosong sampai pelanggan menilai (lihat
  "Penilaian pelanggan (K14)").
- Unique: `(company_id, code)`. Index: `(company_id, status)`,
  `(company_id, source)`, `(company_id, category_id)`,
  `(company_id, assigned_to)`, `(company_id, customer_id)`,
  `(company_id, sla_deadline)`, `(company_id, spawned_spk_id)`,
  `(company_id, customer_rated_at, customer_rating)` (angka kepuasan satu periode). Trait
  `BelongsToCompany`. Soft delete.
- Check: `source IN ('customer','noc','internal')`, `status IN ('open',
  'assigned','on_progress','resolved','closed')`, `priority IN ('low',
  'medium','high','urgent')`.
- SLA breach: computed (sla_deadline < now AND status NOT IN (resolved,
  closed)) — badge di list/dashboard, bukan kolom status.

### ticket_comments
- id, company_id FK NOT NULL, ticket_id FK NOT NULL (cascade), author_id
  FK→users.id NOT NULL (restrict), body text NOT NULL, is_internal
  boolean default false (true = staff-only, false = public visible
  customer v2), created_at, updated_at, deleted_at.
- Index: `(company_id, ticket_id)`, `(ticket_id, created_at)`. Trait
  `BelongsToCompany`. Soft delete.

### ticket_attachments
- id, company_id FK NOT NULL, ticket_id FK NOT NULL (cascade), comment_id
  nullable FK→ticket_comments.id (cascade), file_path varchar NOT NULL,
  original_name varchar, mime_type varchar, size_bytes int, uploaded_by
  FK→users.id NOT NULL, created_at, updated_at.
- Index: `(company_id, ticket_id)`, `(ticket_id, comment_id)`. Trait
  `BelongsToCompany`.
- File storage: local disk `tickets/{ticket_id}/` v1 (S3 v2).

## Workflow

Lihat `docs/WORKFLOW.md` Section 8 (Ticketing). Ringkas state machine:

```
[open] ──assign──→ [assigned] ──start work──→ [on_progress] ──resolve──→ [resolved] ──close──→ [closed]
                             ↑                                      │
                             └──── customer public reply ───────────┘
```

### Transisi dan efek samping

| Dari | Ke | Pemicu | Efek samping |
|------|-----|---------|-------------|
| open | assigned | assign(handler) | `assigned_to` diisi, audit, notifikasi petugas. |
| assigned | on_progress | `startWork` | `first_response_at` dan `first_response_source=start` diisi bila masih kosong. |
| open/assigned/on_progress | sama | balasan publik staf | Balasan publik pertama mengisi `first_response_at` dan `first_response_source=reply`; catatan internal tidak dihitung. |
| on_progress | resolved | resolve(resolution_note) | `resolved_at` dan `resolution_note` diisi. |
| resolved | assigned atau open | balasan pelanggan | Komentar disimpan, lalu tiket dibuka kembali ke `assigned` bila masih punya petugas atau `open` bila tidak; `resolved_at` dan `resolution_note` dikosongkan, `reopened_at` diisi, tenggat tidak diulang. |
| resolved atau closed | assigned atau open | staf dengan `ticket.reopen`, memakai alasan (`reopenByStaff`) | Sama dengan baris di atas, ditambah `closed_at` dikosongkan; alasan dan pelaku masuk audit `reopened`; petugas dan pelanggan diberi tahu. |
| resolved | closed | close | `closed_at` diisi, snapshot nama kategori dan petugas dibekukan. |

Tiket `closed` menolak komentar staf dengan 422 "Tiket sudah ditutup." dan menolak balasan atau lampiran pelanggan dengan API 422 `ticket_closed`, "Tiket ditutup. Masih bermasalah? Buat tiket baru.". Hanya pemegang `ticket.reopen` yang bisa membukanya kembali, dengan alasan (lihat "Buka kembali oleh staf" di gelombang 3); pelanggan menilai tiket tertutup lewat `confirmation` tetapi tidak membukanya.

### SLA
- Saat create: `TicketService::computeDeadlines` menghitung dua tenggat dari
  `created_at`: `sla_deadline` (resolusi) dan `response_deadline` (respons
  pertama, kosong bila targetnya kosong). Targetnya dihitung dalam jam kalender
  (24 jam), kecuali tingkat SLA berhitungan `business` pada perusahaan yang
  sudah mengisi `service_hours` (lihat gelombang 3 di bawah). Sumber
  targetnya (`slaTarget`):
  1. Tingkat SLA dari **versi paket** yang dipakai langganan tiket
     (`subscription.packageVersion.slaTier`: `response_time_hours` dan
     `resolution_time_hours`).
  2. Bila tiket tidak punya langganan, kategori tiket (`default_response_hours`
     dan `default_sla_hours`).
  Prioritas urgent memotong kedua target jadi setengah, dibulatkan ke atas.
  Halaman detail tiket menulis sumbernya (nama tingkat SLA atau nama kategori).
- Versi paket menyimpan tingkat SLA yang dijual. Tenggat tiket baru tetap
  memakai tingkat itu setelah paket pindah ke versi baru (langganan lama tetap
  di versinya) dan setelah tingkat SLA itu dinonaktifkan: menonaktifkan hanya
  menghentikan penjualan baru. Tenggat dihitung ulang hanya saat tiket baru
  dibuat atau saat prioritas tiket `open` diubah. Langganan yang dipindah lewat
  Ganti paket memberi tiket berikutnya tingkat SLA versi barunya.
- Saat resolve: `resolution_time = resolved_at - created_at`.
- First Response Time (FRT) adalah `first_response_at - created_at`. Sumbernya `reply` untuk balasan publik staf pertama atau `start` untuk tombol Mulai. Balasan pelanggan dan catatan internal tidak dihitung. Migrasi mengisi `start` pada respons lama.
- Job `CheckTicketSlaBreachesJob` berjalan tiap 15 menit dan mengklaim penanda notifikasi di bawah kunci baris agar tidak mengirim ganda.
- Peringatan respons maksimal 30 menit sebelum tenggat: tiket `assigned` memberi tahu petugas aktif; tiket `open` memberi tahu pemegang `ticket.assign`.
- Pelanggaran target respons dan penyelesaian memberi tahu petugas aktif serta pemegang `ticket.assign`, dideduplikasi. Pembuat atau pelanggan tidak menjadi penerima hanya karena membuat tiket.
- Semua pemberitahuan SLA memakai database bell dan push. Email SLA dihapus.

### Antrean kerja, tenggat dan data pelanggan (flow-audit-fixes gelombang 2)
- Terbuka berarti status selain `resolved` dan `closed`. `Ticket::scopeDueSoon($menit = 30)` memilih tiket terbuka yang `response_deadline` (selama `first_response_at` kosong) atau `sla_deadline` jatuh antara sekarang dan sekarang + `$menit`. `Ticket::scopeAwaitingStaffReply()` memilih tiket terbuka yang komentar publik terbarunya ditulis akun portal pelanggan tiket itu dan tidak ada komentar publik sesudahnya. Keduanya hanya memakai query builder dan dilayani indeks `tickets (company_id, response_deadline)`, `tickets (company_id, sla_deadline)` dan `ticket_comments (ticket_id, created_at)`.
- `GET admin.tickets.index` dan `admin.tickets.export` memakai satu `filteredQuery()` dengan parameter tambahan `unassigned` (terbuka tanpa petugas), `due_soon`, `awaiting_reply` dan `sort_by=response_deadline`. "Tiket saya" memakai `assigned_to` milik sendiri. Cakupan petugas di `TicketController` tetap berlaku di atasnya, jadi petugas tanpa `ticket.assign` mendapat daftar kosong untuk `unassigned`. Ekspor memuat kolom "Petugas" dan "Respons sebelum".
- Dashboard memakai predikat yang sama untuk kartu `sla_due_soon` ("Hampir terlampaui (≤ 30 menit)") dan `customer_replied` ("Pelanggan membalas"), dengan audiens dan cakupan kartu `sla_breached`. Tidak ada lonceng baru untuk keduanya.
- `TicketService::deadlineSource()` mengembalikan `{type: tier|category, name, response_hours, resolution_hours, urgent_halved, calendar}`. Halaman menulis hitungannya, misalnya "6 jam ÷ 2 karena Mendesak = 3 jam"; `computeDeadlines()` memakai `calendar` yang sama (lihat gelombang 3 di bawah).
- Data pelanggan di payload tiket: baris daftar membawa id, kode dan nama. Halaman tiket menambah `user_id` akun portal dan `phone`. Record penuh (email, NPWP, catatan, alamat) hanya untuk pemegang `customer.view`. Penulis komentar hanya id dan nama.
- `GET admin.evaluations.create?ticket=<id>` mengisi karyawan dan tiket untuk tiket perusahaan ini yang `closed` dan punya petugas, tanpa nilai. Id yang asing, terbuka, tanpa petugas atau tidak dikenal memberi form kosong, bukan galat.
- API teknisi `GET /api/v1/technician/my-work`: baris tiket membawa `response_due_at` (ISO, = `response_deadline` selama `first_response_at` kosong, selain itu `null`). Aplikasi menampilkan hitungan mundur dan kartu tiket mendesak di Beranda.

### Pembukaan kembali, tenggat jam layanan dan aturan lapangan (flow-audit-fixes gelombang 3)
- **Buka kembali oleh staf (K13).** `POST admin.tickets.reopen` (`TicketPolicy::reopen`): izin `ticket.reopen`, perusahaan yang sama, pengguna aktif, status `resolved` atau `closed`. Alasan wajib, 5 sampai 500 karakter ("Alasan" di dialog "Buka kembali tiket {kode}?"). Status kembali ke `assigned` bila tiket masih punya petugas, selain itu `open`; `resolved_at`, `resolution_note` dan `closed_at` dikosongkan, `reopened_at` diisi, tenggat tidak dihitung ulang dan penilaian pelanggan tetap. Status lain: 422 "Hanya tiket yang diselesaikan atau ditutup yang bisa dibuka kembali." Audit `ticket` / `reopened` (`by`, `reason`, `previous_status`). Notifikasi: petugas aktif (bila tidak ada, pemegang `ticket.assign`; pelaku tidak diberi tahu soal tindakannya sendiri) dan pelanggan ("Tiket {kode} dibuka kembali dan akan ditangani lagi."). Balasan pelanggan pada tiket `resolved` tetap membuka tiket seperti sebelumnya, hanya staf yang diberi tahu. Template Admin, Manajer dan NOC memegang izin ini (migrasi `2026_11_02_100700`); peran milik perusahaan yang bukan salinan Manajer atau NOC mendapatkannya dari admin perusahaan di **Peran**.
- **Tugaskan ke saya.** `POST admin.tickets.assign-self` untuk tiket yang belum punya petugas; pemegang `ticket.assign`. Bila petugas lain lebih cepat: 409. Daftar petugas di dialog "Tugaskan tiket" menulis "{nama} · {n} pekerjaan aktif" (tiket yang belum selesai ditambah SPK yang belum selesai milik orang itu) dan, saat sistem memberi saran, menandai yang paling sedikit dengan "Paling sedikit pekerjaan". Email petugas tidak lagi ikut dikirim ke halaman.
- **Tenggat dan sumbernya.** `TicketService::deadlineSource()` kini mengembalikan `{type, name, response_hours, resolution_hours, urgent_halved, calendar}`. `calendar` bernilai `24h` atau `business`: tingkat SLA punya kolom `sla_tiers.calendar` (default `24h`, tampil sebagai "Hitungan tenggat": "24 jam" atau "Jam layanan"), sedangkan kategori selalu `24h`. Pada `business`, `App\Support\ServiceHours::add` menghitung target dalam menit buka saja, menurut pengaturan `service_hours` perusahaan (`{mon..sun: null atau ["HH:MM","HH:MM"]}`, `null` = tutup, jam tutup tidak ikut dihitung, zona waktu perusahaan). Tiket yang masuk di luar jam buka mulai dihitung saat buka berikutnya. Selama `service_hours` kosong, tenggat dihitung 24 jam, `deadlineSource().calendar` tetap `24h` dan Pengaturan Perusahaan menulis "Jam layanan belum diisi; tenggat dihitung 24 jam." Teks `support_hours` untuk pelanggan tidak berubah dan tidak dibaca perhitungan.
- **Pratinjau SLA.** Form tiket baru memanggil `GET admin.tickets.sla-preview` dan menulis "Target respons {jam}, selesai {jam}" (atau "Tanpa target respons").
- **Jarak check-in (K10).** Saat teknisi memulai tiket atau SPK (`Mulai`), jarak ke lokasi pelanggan dibandingkan dengan `checkin_max_distance_m` (default 500 m, 50 sampai 100000, Pengaturan Perusahaan, bagian Lapangan). Lebih jauh dari batas tanpa `check_in.far_reason` (sampai 300 karakter): 422 pada `check_in.far_reason`, "Anda berjarak {jarak} dari lokasi pelanggan. Tulis alasannya untuk melanjutkan." Check-in yang tersimpan memuat `far` dan `far_reason`, dan halaman tiket, SPK serta lembar tinjauan persetujuan menulis "Jauh dari lokasi: {alasan}". Pemberitahuan berangkat (en route) tidak dibatasi. Titik GPS yang tidak bisa diambil, atau lokasi tanpa koordinat, tidak pernah dianggap jauh. Jarak ditulis "820 m" di bawah 1 km dan "1,2 km" dari 1 km.
- **Lampiran.** Web dan API menerima jpg, jpeg, png dan pdf (sampai 10 MB); doc dan txt ditolak. Keterangan (`caption`, opsional: 200 karakter di web, 255 di API) disimpan bersama berkasnya. `TicketAttachmentResource` melaporkan `caption`, `uploaded_at` dan `uploader_first_name` untuk aplikasi. Pada tiket `closed` halaman menulis "Tiket sudah ditutup. Lampiran baru tidak bisa ditambahkan." dan tidak menampilkan kotak unggah.
- **Muatan data.** Payload tiket memuat ringkasan pelanggan (`{id, kode, nama}`), ringkasan langganan (`{id, kode}`) dan ringkasan aset jaringan (`{id, kode, nama}`), bukan record penuh. API teknisi tiket menambah `site {address, lat, lng, source}` (`installation` atau `pop`).

### Snapshot tiket tertutup
- `TicketService::close` mengunci baris tiket, lalu menyimpan nama kategori
  (`category_name_snapshot`) dan nama petugas (`assignee_name_snapshot`) pada
  saat itu, bersama status `closed`. Mengganti nama kategori tiket atau nama
  pengguna petugas sesudahnya tidak mengubah tiket yang sudah ditutup. Tiket
  tanpa petugas menyimpan `assignee_name_snapshot` kosong.
- Tiket yang belum ditutup membaca nama kategori dan petugas yang berlaku saat
  ini. Pembaca memakai `snapshot ?? nama hidup`:
  - halaman detail tiket staf (Kategori dan Petugas; teks "(saat ditutup)"
    muncul bila nama sekarang berbeda);
  - API pelanggan (`category.name` dan `handler_first_name`, nama depan);
  - laporan Kepatuhan SLA dan Kinerja Teknisi, yang mengelompokkan tiket per
    kategori lewat `COALESCE(tickets.category_name_snapshot,
    ticket_categories.name)`.
- Migrasi `2026_10_23_100400_add_snapshot_columns_to_tickets_table` mengisi
  tiket tertutup yang sudah ada sekali, dengan nama saat migrasi dijalankan, dan
  hanya baris yang kolomnya masih kosong.
- Membuka kembali tiket tertutup (staf atau, untuk tiket `resolved`, balasan pelanggan) mengosongkan `category_name_snapshot` dan `assignee_name_snapshot`: tiket yang terbuka lagi mengikuti nama hidup, dan `close` berikutnya membekukannya lagi.
- Hapus dan pulihkan: tiket `closed` dihapus lunak (aturan lama tidak berubah).
  Filter **Status** daftar tiket punya pilihan **Dihapus** dan **Semua,
  termasuk dihapus**, dan baris yang dihapus punya aksi **Pulihkan**
  (`POST /admin/tickets/{ticket}/restore`, izin yang sama dengan hapus,
  `ticket.delete`). Tiket yang dipulihkan kembali dengan statusnya semula.

### Auto-routing (system suggest)
- Saat create: system tentukan unit terkait berdasarkan:
  - Category (no_internet/fiber_issue → NOC/field-fiber team;
    device_issue → field-device team; slow/packet_loss → NOC).
  - Location (area coverage → region team).
  - Customer/subscription (serving_pop → POP team).
  - Network asset (asset_type → team specialist).
- `SuggestHandlerQuery` return ranked kandidat (unit + skill + workload
  + availability). Kepala Unit final assign.
- v1: suggest via query, manual assign by Kepala Unit. v2: auto-assign
  berdasarkan rule engine.

### Spawn SPK from ticket
- Tiket category device_issue/fiber_issue yang butuh field work →
  `TicketService::spawnSpk`:
  - Create SPK type=maintenance, source=ticket.
  - Pre-fill customer, subscription, location dari ticket.
  - spawned_spk_id set (backlink).
  - SPK status=generated (menunggu Kepala Unit assign technician).
- Trigger: Kepala Unit manual (button "Spawn SPK") OR auto saat resolve-
  but-needs-field-work.

### Penilaian pelanggan (K14)
- Pelanggan menilai 1 sampai 5 lewat `POST /api/v1/customer/tickets/{ticket}/confirmation` (izin `evaluation.customer.submit`), dengan komentar opsional. Tiket `resolved` ditutup oleh jawaban itu ("Sudah beres" di aplikasi) memakai rutinitas penutupan yang sama dengan staf, termasuk snapshot nama. Tiket `closed` yang belum dinilai hanya menerima penilaian.
- Kolom `tickets.customer_rating`, `customer_rating_comment` dan `customer_rated_at` kosong sampai pelanggan menilai (kosong bukan nol, jadi rata-rata melewatinya). Satu tiket dinilai satu kali; penilaian bertahan saat tiket dibuka kembali.
- Halaman tiket staf menulis "Penilaian pelanggan: 4 dari 5" beserta komentar. Form evaluasi yang dibuka dari tiket mengisi `customer_rating` dari penilaian itu; staf tetap bisa mengetik nilai atas nama pelanggan.
- Laporan Kepatuhan SLA menghitung CSAT dari kolom ini (lihat `docs/business/reporting.md`). EmployeeEvaluation reference_type=Ticket bisa mencatat rating + score.

## Permission

```
ticket.view
ticket.create
ticket.update
ticket.delete
ticket.assign         (Kepala Unit)
ticket.start          (handler, self-assigned)
ticket.resolve        (handler)
ticket.close          (Kepala Unit)
ticket.reopen         (Admin, Manajer, NOC; staf membuka kembali tiket selesai atau tertutup dengan alasan)
ticket.comment.create
ticket.comment.internal   (post internal comment)
ticket.attachment.upload
ticket.attachment.view
ticket.spawn_spk      (Kepala Unit)
ticket.export
ticket.manage         (super)
```

## API (Route)

Lihat `docs/API.md` Section 8 (Ticketing group). Ringkas:

- `GET    /admin/tickets` (list, filter: status, source, category, assigned_to, customer, sla_breached, date range)
- `POST   /admin/tickets` (create open)
- `GET    /admin/tickets/{ticket}` (detail + comments + attachments + linked customer/subscription/asset/location/spk)
- `PUT    /admin/tickets/{ticket}` (update open only)
- `DELETE /admin/tickets/{ticket}` (soft delete closed only)
- `POST   /admin/tickets/{ticket}/assign` (body: handler_id)
- `GET    /admin/tickets/{ticket}/suggest-handler` (system suggest)
- `POST   /admin/tickets/{ticket}/start` (on_progress, set first_response_at)
- `POST   /admin/tickets/{ticket}/resolve` (body: resolution_note)
- `POST   /admin/tickets/{ticket}/close`
- `POST   /admin/tickets/{ticket}/comments` (body: body, is_internal)
- `POST   /admin/tickets/{ticket}/attachments` (multipart: `file` jpg, jpeg, png or pdf up to 10 MB, `caption` optional up to 200 characters)
- `GET    /admin/tickets/{ticket}/attachments`
- `DELETE /admin/tickets/{ticket}/attachments/{att}`
- `POST   /admin/tickets/{ticket}/reopen` (body: `reason` 5 to 500 characters; `ticket.reopen`; only `resolved` or `closed`)
- `POST   /admin/tickets/{ticket}/assign-self` (`admin.tickets.assign-self`: "Tugaskan ke saya", an unassigned ticket; 409 when somebody was quicker)
- `GET    /admin/tickets/sla-preview` (`category_id`, optional `subscription_id` and `priority`; answers `response_due`, `resolution_due`, `source` and `calendar` without saving anything; `ticket.create`)
- `GET    /api/v1/customer/tickets/{ticket}` dan daftar menambah `can_reply`, `reopened_at`, `rating`, `can_confirm` dan `can_rate`
- `POST   /api/v1/customer/tickets/{ticket}/comments`: tiket `resolved` dibuka kembali; tiket `closed` menjawab 422 `ticket_closed`
- `POST   /api/v1/customer/tickets/{ticket}/confirmation` (body: `rating` 1 sampai 5, `comment` opsional sampai 1000 karakter; `evaluation.customer.submit`; `Idempotency-Key`): tiket `resolved` ditutup dan dinilai, tiket `closed` yang belum dinilai hanya dinilai. 409 `ticket_already_rated` "Tiket ini sudah dinilai."; 422 `ticket_not_resolved` "Tiket belum diselesaikan."
- Unggah lampiran pelanggan mengikuti penolakan tiket `closed` yang sama dan tipe berkas yang sama (jpg, jpeg, png, pdf, sampai 10 MB; `caption` opsional sampai 255 karakter)
- `POST   /admin/tickets/{ticket}/spawn-spk` (create SPK maintenance from ticket)
- `GET    /admin/ticket-categories` (+ POST/PUT/DELETE)
- `GET    /admin/tickets/export`

## Testing Scenario

### Create, first response, reopen, close
1. Create ticket with response and resolution deadlines, then assign handler H01.
2. A public staff reply before **Mulai** sets `first_response_source=reply`; an internal note or customer reply does not.
3. If no reply exists, H01 starts the ticket and sets `first_response_source=start`, status `on_progress`.
4. H01 resolves the ticket. A customer reply reopens it to `assigned`, clears the resolution fields, retains both deadlines, and notifies H01 plus `ticket.assign` holders.
5. The customer answers "Masalah sudah beres?" with a rating: the resolved ticket closes and `customer_rating` is stored. Closing makes the ticket final for comments: staff comments and customer comments or attachments are refused. A holder of `ticket.reopen` can still bring it back with a reason; the deadlines and the rating stay.

### Spawn SPK from ticket
1. Ticket fiber_issue, on_progress, needs field work (fiber cut).
2. Kepala Unit spawn-spk: `TicketService::spawnSpk` → SPK type=maintenance,
   source=ticket, pre-fill customer/subscription/location,
   spawned_spk_id set. SPK status=generated.
3. SPK assigned + completed → ticket resolved (or separate resolve).

### SLA breach
1. An unanswered `open` or `assigned` ticket crosses `response_deadline`; `CheckTicketSlaBreachesJob` sends one bell and push to the handler plus `ticket.assign` holders.
2. An unfinished ticket crosses `sla_deadline`; the same recipient rule applies for the resolution notice.
3. No SLA notice uses mail or targets the ticket creator only because they created it.

### Validation
1. Create ticket source=customer tanpa customer_id → 422.
2. Assign closed ticket → 422.
3. Resolve open ticket (not assigned/on_progress) → 422.
4. Close non-resolved ticket → 422.

### Authorization
1. Customer (v2) create own ticket → 200. View other → 403.
2. NOC create + assign to self + resolve → 200. Close → 403.
3. Technician view assigned-to-self → 200. Assign → 403.
4. Staff create + comment → 200. Resolve → 403.

### Number race
1. Concurrent create 2 ticket → 2 code beda (TKT-{YEAR}-{NNNNN}).

## Acceptance Criteria

- [ ] 3 source (customer/noc/internal) + 5 kategori ISP (no_internet/
  slow_connection/packet_loss/device_issue/fiber_issue) + custom (v2).
- [ ] Status state machine (open→assigned→on_progress→resolved→closed).
- [ ] SLA: deadline per category, breach detection (job + badge),
  resolution_time + FRT tracking.
- [ ] Auto-routing suggest (category + location + asset → ranked handler).
- [ ] Spawn SPK from ticket (`TicketService::spawnSpk`, backlink).
- [ ] Link customer/subscription/asset/location (trace 1-klik).
- [ ] Comment internal/public + attachment.
- [ ] Code generation unique + race-safe (TKT-{YEAR}-{NNNNN}).
- [ ] Soft delete closed only.
- [ ] Policy per aksi (technician self-limit, customer v2 own-only).
- [ ] Activity log: create, assign, start, comment, resolve, close,
  spawn_spk, sla_breach.
- [ ] Factory + seeder (30 ticket: 10 open, 8 assigned, 7 on_progress,
  3 resolved, 2 closed — mix source/category, 3 SLA breached).
- [ ] Feature test ≥ 80% coverage.
- [ ] UI: ticket list (filter status/source/category/sla_breached/date),
  detail (comments thread + attachments + linked entities + SLA badge +
  lifecycle buttons + spawn-SPK button), NOC dashboard (active by
  category + breach count + my assigned), form, customer portal (v2).
- [ ] UI pakai Components/ui + composite (TicketSlaBadge).
- [ ] Dark mode + responsive.

## Module Dependencies

- **Depends on (Phase 1):** Core (Company, User, Role, Permission,
  ActivityLog, Setting), Location (shared Core — issue location).
- **Depends on (Phase 2):** Customer/Subscription (shared Core —
  customer_id + subscription_id direct FK, trace).
- **Depends on (Phase 3):** NetworkAsset (network_asset_id direct FK —
  trace aset terkait tiket).
- **Depends on (Phase 4):** SPK (spawned_spk_id backlink — `TicketService::spawnSpk`
  membuat SPK; ticket.spawned_spk_id nullable FK).
- **Consumed by (Phase 4):** SPK (SPK ticket_id backlink — GenerateSpkFromTicketAction
  reads ticket for pre-fill). Reporting (technician performance dari
  ticket history + FRT + SLA + rating).
- **Cross-module:** `TicketService::spawnSpk` (Ticketing) membuat SPK lewat model
  `WorkOrder` dan mengisi ticket.spawned_spk_id. Tidak ada action lintas-modul
  terpisah.

## Notifikasi WhatsApp (tiket dan SPK)

Keputusan: `.scratch/whatsapp-notifications/spec.md`. Aturan umum (persetujuan, STOP, jam tenang, antrean `whatsapp`) ada di
`docs/business/customer.md` § Notifikasi WhatsApp. Semua pesan di bawah ini berbasis kejadian (tidak menunggu jam tenang) dan
dikirim setelah transaksi commit; transaksi yang dibatalkan (konflik 409, rollback) tidak mengirim apa pun.

| Event (kunci) | Pemicu | Penerima | Dedupe |
|---|---|---|---|
| `ticket_created` | `TicketService::create`, hanya bila tiket punya `customer_id` | customer tiket | `ticket_created:{ticket}` |
| `ticket_assigned` | `TicketService::assign` pada penugasan PERTAMA saja (`assigned_to` sebelumnya null); reassign tidak mengirim lagi | customer tiket | `ticket_assigned:{ticket}` |
| `ticket_resolved` | `TicketService::resolve` | customer tiket | `ticket_resolved:{ticket}` |
| `spk_scheduled` | `SpkService::assign` setiap teknisi baru (teknisi yang sama lagi: tidak) | teknisi (`employee_profiles.phone`, user aktif + profil aktif; tanpa persetujuan per orang) | `spk_scheduled:{wo}:{user teknisi}` |
| `spk_scheduled_customer` | `SpkService::assign` pada penugasan pertama, hanya bila SPK punya `customer_id` dan `scheduled_date` | customer SPK | `spk_scheduled_customer:{wo}` |

- Teknisi tidak dikirimi WhatsApp untuk tiket (sudah menerima email + in-app `TicketAssignedNotification`, tidak diubah).
- Tanggal kunjungan SPK adalah tanggal saja (tanpa slot jam). SPK tanpa `scheduled_date`: teknisi menerima "belum dijadwalkan",
  customer tidak dikirimi apa pun. Pesan teknisi tidak memuat telepon, nama, atau alamat customer; pesan customer tidak memuat telepon
  teknisi. Teknisi yang sudah diganti sebelum job berjalan tidak dikirimi.
- Judul tiket dipotong `Str::limit($title, 60)` (maks 63 karakter dengan "..."). Nama penangani = kata pertama nama user.

### Template Meta (kategori `utility`, bahasa `id`) — didaftarkan owner di WhatsApp Manager

`tiket_dibuat` — params: nama company, kode tiket, judul

```
Keluhan Anda kepada {{1}} sudah kami catat dengan kode {{2}}: {{3}}.
Petugas kami akan segera menindaklanjuti. Simpan kode ini untuk menanyakan perkembangannya.
```
Contoh: `PT Contoh Net`, `TKT-2026-00031`, `Internet mati sejak pagi`

`tiket_ditangani` — params: nama company, kode tiket, judul, nama penangani

```
Keluhan Anda kepada {{1}} dengan kode {{2}} ({{3}}) sedang ditangani oleh {{4}}.
Kami akan mengabari Anda setelah selesai.
```
Contoh: `PT Contoh Net`, `TKT-2026-00031`, `Internet mati sejak pagi`, `Budi`

`tiket_selesai` — params: nama company, kode tiket, judul, tanggal selesai (timezone company)

```
Keluhan Anda kepada {{1}} dengan kode {{2}} ({{3}}) sudah diselesaikan pada {{4}}.
Jika masalah masih ada, hubungi kami dan sebutkan kode tiket.
```
Contoh: `PT Contoh Net`, `TKT-2026-00031`, `Internet mati sejak pagi`, `16 Okt 2026`

`spk_penugasan_teknisi` — params: kode SPK, judul, tanggal kunjungan atau `belum dijadwalkan`, link SPK (perlu login)

```
Anda mendapat tugas SPK {{1}}: {{2}}.
Jadwal kunjungan: {{3}}
Detail tugas (perlu login): {{4}}
Selamat bekerja.
```
Contoh: `SPK-2026-0001`, `Pasang ONT baru`, `20 Okt 2026`, `https://inbils.example/admin/spk/7`

`spk_jadwal_pelanggan` — params: nama company, kode SPK, tanggal kunjungan, nama depan teknisi

```
Teknisi dari {{1}} akan mengerjakan SPK {{2}} di lokasi Anda pada {{3}}. Teknisi yang bertugas: {{4}}.
Mohon pastikan ada yang menerima di lokasi.
```
Contoh: `PT Contoh Net`, `SPK-2026-0001`, `20 Okt 2026`, `Andi`
