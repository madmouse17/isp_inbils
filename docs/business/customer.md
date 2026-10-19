# Module: Customer Management

> Status: DRAFT v2 (ISP pivot 2026-06-30). Phase 2 eksekusi. Dependency:
> Phase 1 (Core: Company + CompanyScope + Setting + Location topology).
> Lokasi module: `Modules/Customer/` (nwidart). Models di `app/Models/Core/`
> shared (keputusan A1 — Customer consumed oleh 5 module: SPK/Ticketing/
> Billing/NetworkAsset/Reporting).

## Tujuan

Mengelola master data pelanggan ISP: Individual/Company, alamat layanan
(installation point), kontak, dan subscription (paket internet aktif per
customer). Customer + Subscription = entitas sentral ISP — direferensikan
oleh SPK (instalasi/maintenance), Ticketing (complaint), Billing
(recurring invoice + suspend), NetworkAsset (ONT terpasang), Reporting.

## User Role

| Role | Hak |
|------|-----|
| admin | full CRUD customer/address/contact/subscription + suspend/reactivate/terminate |
| manager | full CRUD + suspend/reactivate/terminate (no delete) |
| noc | view customer + subscription + address (no edit) |
| staff | view + create/edit customer/address/contact (no subscription lifecycle) |
| technician | view customer + subscription + address (untuk SPK assignment) |

## Entity

### Customer (app/Models/Core/Customer.php — shared)
Pelanggan ISP. Individual (perorangan) atau Company (badan usaha).
Code unique per company. Type menentukan field wajib (Company wajib
tax_id/NPWP + contact_person).

### CustomerAddress (app/Models/Core/CustomerAddress.php — shared)
Alamat pelanggan. Bisa multiple per customer (rumah/kantor/cabang).
Salah satu = installation point (lokasi delivery layanan, dipakai SPK
instalasi + ticketing trace). Lat/lng disimpan v1 (GIS map v2).

### CustomerContact (app/Models/Core/CustomerContact.php — shared)
Kontak person pelanggan. Multiple per customer. Untuk Company = PIC
teknis/administratif. Untuk Individual = opsional ( kontak = data
customer sendiri).

### ServiceSubscription (app/Models/Core/ServiceSubscription.php — shared)
Subscription layanan ISP aktif per customer. Link ke ServicePackage
(catalog, Modules/Service) + installation address + ONT asset (installed
at premise) + serving POP (topology location). Status lifecycle.
Billing fields (E1 — service_subscription IS the billing subscription,
no separate entity).

## Database Relation

```
companies ──< customers
customers ──< customer_addresses
customers ──< customer_contacts
customers ──< service_subscriptions
service_packages ──< service_subscriptions (FK service_package_id)
customer_addresses ──< service_subscriptions (FK installation_address_id)
locations ──< service_subscriptions (FK serving_pop_id, type=pop)
network_assets ──< service_subscriptions (FK ont_asset_id, nullable — ONT at premise)
users ──< customers (acquired_by_user_id — nullable, restrict; sales attribution. Tidak ada kolom created_by)
work_orders ──< service_subscriptions (spk references subscription)
tickets ──< service_subscriptions (ticket references subscription)
invoices ──< service_subscriptions (recurring invoice source)
```

Skema: `docs/DATABASE.md` Section 5 (Customer).

## Entity Fields (ringkas)

### customers
- id, company_id FK NOT NULL, code NOT NULL, name NOT NULL, type
  enum(Individual/Company) NOT NULL, email nullable, phone nullable,
  tax_id nullable (NPWP — wajib jika Company), contact_person nullable,
  area_coverage_id nullable FK→locations.id (region/area service coverage),
  notes text nullable, is_active boolean default true, created_at,
  updated_at, deleted_at.
- `acquired_by_user_id` nullable FK→users.id (`restrictOnDelete`), setelah `user_id`: pegawai yang
  dikreditkan sebagai acquirer pelanggan. Bukan mass-assignable (hanya ditulis
  `AssignCustomerAcquirerAction`). Index `(company_id, acquired_by_user_id)`. Pelanggan lama tetap
  Unassigned (tanpa backfill).
- Unique: `(company_id, code)`. Index: `(company_id, name)`,
  `(company_id, email)`. Trait `BelongsToCompany`. Soft delete.

### customer_addresses
- id, company_id FK NOT NULL, customer_id FK NOT NULL (cascade), label
  varchar (rumah/kantor/cabang), address text NOT NULL, city, postal_code,
  lat decimal(10,7) nullable, lng decimal(10,7) nullable,
  is_installation_point boolean default false, is_primary boolean default
  false, notes nullable, created_at, updated_at, deleted_at.
- Unique: `(customer_id, label)`. Index: `(company_id, customer_id)`.
  Trait `BelongsToCompany`. Soft delete.
- Rule: max 1 `is_installation_point=true` per customer (app guard at
  Service, set new true → clear others).

### customer_contacts
- id, company_id FK NOT NULL, customer_id FK NOT NULL (cascade), name
  NOT NULL, position nullable, phone, email, is_primary boolean default
  false, notes nullable, created_at, updated_at, deleted_at.
- Unique: `(customer_id, phone)`. Index: `(company_id, customer_id)`.
  Trait `BelongsToCompany`. Soft delete.

### service_subscriptions
- id, company_id FK NOT NULL, customer_id FK NOT NULL (restrict),
  service_package_id FK NOT NULL (restrict), installation_address_id FK
  NOT NULL (restrict), code NOT NULL, status enum
  (pending/active/suspended/terminated) NOT NULL default 'pending',
  activation_date date nullable, expiration_date date nullable,
  billing_day tinyint NOT NULL default 1 (1-28 — tanggal recurring
  invoice), next_invoice_date date nullable, ont_asset_id nullable
  FK→network_assets.id (restrict — ONT installed at premise),
  serving_pop_id nullable FK→locations.id (restrict — POP topology yang
  melayani), mrc_amount decimal(15,2) NOT NULL (snapshot dari package
  saat create, bisa override), otc_installation_fee decimal(15,2) default
  0, contract_months int nullable, notes nullable, terminated_at nullable,
  terminated_reason nullable, created_at, updated_at, deleted_at.
- Unique: `(company_id, code)`. Index: `(company_id, customer_id)`,
  `(company_id, status)`, `(company_id, serving_pop_id)`,
  `(company_id, ont_asset_id)`. Trait `BelongsToCompany`. Soft delete.
- Check constraint: `status IN ('pending','active','suspended','terminated')`,
  `billing_day BETWEEN 1 AND 28`.

## Workflow

Lihat `docs/WORKFLOW.md` Section 2 (Customer/Subscription). Ringkas:

```
[customer created] → active
  │
  └─< subscription >── [pending] ──SPK install complete──→ [active]
                                │
                                ├── billing overdue job──→ [suspended]
                                │                          │
                                │                          └──payment recorded / manual──→ [active]
                                │
                                └── manual terminate──→ [terminated]
```

### Akun portal customer (CustomerService::createWithUser)

- Saat customer dibuat, user portal ikut dibuat dengan password acak 32 karakter yang tidak pernah
  ditampilkan. Nomor telepon BUKAN password.
- Setelah transaksi commit, notifikasi antrean `CustomerAccountCreated` mengirim link atur kata sandi
  (token password broker + route `password.reset`) ke email customer. Link berlaku sesuai
  `auth.passwords.users.expire` (60 menit).
- `POST /admin/customers/{customer}/access-link` (`customers.access-link`, permission
  `customer.update`, throttle 3/menit) mengirim ulang link. Customer tanpa akun beremail tidak
  dikirimi apa pun dan staf menerima pesan error.
- Untuk customer yang setuju WhatsApp, `CustomerAccountCreated` juga mengantrekan satu pesan WhatsApp `akses_portal_info`
  (antrean `whatsapp`) yang hanya memberi tahu bahwa link ada di email (email disamarkan `b***@domain`). Link dan token TIDAK
  pernah dikirim lewat WhatsApp: password broker menyimpan satu token per user dan setiap pembuatan token menghapus yang lama.
  Paling banyak satu pesan per user per jam saat diantrekan (kunci dedupe memuat jam itu, jadi retry di jam berikutnya memakai baris
  yang sama), berapa pun resend staf.

### Subscription lifecycle (SubscriptionService — app/Services/Core/)

| Dari | Ke | Trigger | Side effect |
|------|-----|---------|-------------|
| pending | active | activate(subscription) | `activation_date=now`, link ont_asset (if SPK installed), `next_invoice_date = next billing_day`, AuditLog. Dipanggil CompleteSpkAction (instalation) atau manual. |
| active | suspended | suspend(subscription, reason, source = 'manual') | `status=suspended`, `suspension_source` (`manual` dari halaman Subscriptions; `overdue` dari "Isolir" di Receivables atau `billing:suspend-overdue` harian 08:00), stop recurring invoice generation (job skip), flag NOC/ticketing, AuditLog (`reason`, `source`). |
| suspended | active | reactivate(subscription) | `status=active`, `suspension_source=null`, `next_invoice_date = next billing_day`, resume recurring, AuditLog. Trigger: manual OR otomatis setelah pembayaran (setting company `auto_reactivate_on_payment`, default true) hanya bila `suspension_source=overdue` dan tidak ada invoice subscription itu yang masih lewat grace (`auto_suspend_overdue_days`, default 7); suspend `manual` tidak pernah dibuka otomatis. |
| active/suspended | terminated | terminate(subscription, reason, release_ont) | `status=terminated`, `suspension_source=null`, `terminated_at=now`, `terminated_reason`, stop recurring, release ont_asset (if release_ont → NetworkAsset status=Available + NetworkAssetInstallation removed_at), AuditLog. |

### Block / unblock (isolasi manual, bukan status)

`service_subscriptions.blocked_at|blocked_by|block_reason` (tidak fillable; hanya ditulis `SubscriptionService::block|unblock|terminate`).
Block mengisolasi jaringan pelanggan apa pun statusnya; penagihan tidak berubah. Permission `customer.subscription.block` (admin, manager).

- `block(subscription, reason)`: alasan wajib (maks 500 karakter, disimpan di `block_reason` dan audit `service_subscription / blocked`). Boleh pada `pending`, `active`, `suspended`; 422 bila `terminated` atau sudah diblok.
- `unblock(subscription, note)`: catatan opsional (hanya audit `unblocked`); 422 bila tidak diblok.
- Status tidak berubah dan rollup status customer mengabaikan block (tidak memanggil `CustomerStatusRecalculator`).
- `billing:suspend-overdue` tetap men-suspend subscription `active` yang diblok (sumber `overdue`); `suspend`, `reactivate` dan aturan auto-reactivate tidak pernah membaca atau menulis kolom block.
- Unblock pada subscription `suspended` tetap membiarkannya `suspended` (jaringan tetap terisolasi sampai direaktivasi).
- `terminate` mengosongkan ketiga kolom dalam save yang sama.
- `POST /admin/subscriptions/{sub}/block` (body: reason), `POST /admin/subscriptions/{sub}/unblock` (body: reason opsional).

### Customer status (rollup dari subscription)

`customers.customer_status` (`prospective|active|inactive|suspended|terminated`, default `prospective`,
index `(company_id, customer_status)`) diturunkan dari subscription non-deleted oleh satu service,
`CustomerStatusRecalculator` (aturan isp_ari ADR-011). Kolom tidak fillable dan tidak diterima
Store/UpdateCustomerRequest. `is_active` tetap saklar manual akun (label UI "Account").

Urutan aturan (pertama yang cocok menang):

1. ada subscription `active` → `active`
2. ada `suspended` → `suspended`
3. tidak ada subscription, atau semuanya `pending` → `prospective`
4. semuanya `terminated` → `terminated`
5. selain itu (mis. `terminated` + `pending`) → `inactive`

- Trigger: `SubscriptionService::create|activate|suspend|reactivate|terminate` memanggil
  `CustomerStatusRecalculator::recalculate()` di dalam transaksi yang sama (termasuk jalur
  `CompleteSpkAction` dan onboarding). Recalculator mengunci baris customer sebelum membaca
  subscription, menyimpan dan menulis audit `customer / status_changed {from,to}` hanya jika nilainya
  berubah. Tidak ada penulis lain.
- Penulisan langsung (seeder, tinker, factory) melewati trigger. Perbaiki dengan
  `php artisan customers:recalculate-status {--company=} {--dry-run}` (idempotent, per company via
  `CompanyService::runAs`).
- Migrasi `2026_10_12_400000` mengisi customer yang sudah ada dengan aturan yang sama; `down()`
  menghapus kolom.
- Daftar `/admin/customers`: filter `status` (lima nilai) dan `account` (`active|inactive`, filter
  `is_active`), sort `customer_status`. Export CSV/PDF punya kolom Account dan Status.

### Code generation
- `CUS-{YEAR}-{NNNNN}` untuk customer. `SUB-{YEAR}-{NNNNN}` untuk
  subscription. Lock + transaction per tahun.

### Area coverage
- `customers.area_coverage_id` FK→locations (region/area). Untuk filter
  pelanggan per wilayah layanan (reporting + routing tiket ke unit
  terkait). Opsional v1.

### Sales attribution (acquired by)

Atribusi level customer: `customers.acquired_by_user_id` menyimpan pegawai yang dikreditkan sebagai
acquirer. Bukan atribusi subscription/invoice, bukan komisi, bukan KPI (lane `kpi-sales` terpisah dan
membaca kolom ini).

- **Eligible acquirer** (satu sumber: `App\Queries\Admin\CustomerAcquirerQuery::eligible`): user aktif
  satu company dengan EmployeeProfile aktif (company sama, tidak soft-deleted) yang memegang
  `customer.create` (langsung atau lewat role). Admin tanpa EmployeeProfile bukan eligible. Dipakai
  daftar Create/Edit, rule `IsEligibleCustomerAcquirer`, dan default saat create.
- **Capability**: `customer.sales.assign` mengisi customer yang masih Unassigned;
  `customer.sales.correct` mengubah atau mengosongkan acquirer yang sudah ada dan wajib disertai
  `acquirer_correction_reason` (maks 500 karakter). Seeder: admin dan manager keduanya, staff hanya
  assign, technician dan customer tidak ada.
- **Visibility**: kolom Index, filter, display Show, field `CustomerResource`, dan kolom export
  "Acquired By" hanya untuk pemegang assign atau correct (`CustomerPolicy::viewAcquirer`). Untuk user
  lain filter `acquired_by` diabaikan sehingga tidak bisa dipakai menebak atribusi.
- **Create**: key `acquired_by_user_id` dihormati hanya untuk pemegang assign (`null` = Unassigned).
  Jika key tidak ada (atau pembuat tanpa assign), server mengkredit pembuat bila eligible, selain itu
  null.
- **Update**: mengirim ulang nilai yang sama = no-op tanpa audit. Capability yang tidak sesuai
  (assign-only mengubah acquirer yang ada, correct-only mengisi dari Unassigned) = 403 dan seluruh
  update dibatalkan. User tanpa kedua capability: key diabaikan diam-diam. Id tidak eligible, milik
  company lain, atau tidak ada = 422 dengan pesan yang sama (tidak membocorkan user tenant lain).
- **Audit**: setiap assign/koreksi menulis activity log `customer` (`acquirer_assigned` atau
  `acquirer_corrected`) berisi `old`, `new`, `reason`, `actor_id`, `company_id`.
- **Histori**: acquirer yang kemudian nonaktif tetap tampil dengan nama dan penanda Inactive, tidak
  ditawarkan sebagai pilihan. Acquirer dari company lain tidak pernah di-resolve (tampil Unassigned).
- **Hapus user**: FK `restrictOnDelete`. `UserController::destroy` menolak menghapus user yang pernah
  menjadi acquirer (pesan validasi); nonaktifkan akun sebagai gantinya.
- **Deployment**: setelah `php artisan migrate`, jalankan ulang
  `php artisan db:seed --class=RolePermissionSeeder --force` (idempotent). Custom role tidak diubah
  seeder: beri kedua permission lewat layar Roles.

### Notifikasi WhatsApp: persetujuan, STOP, pengaturan, template

Keputusan: `.scratch/whatsapp-notifications/spec.md` (Q1-Q13, disetujui owner 2026-10-12) dan `.scratch/tenant-integrations/spec.md` (2026-10-17).
Satu akun WhatsApp Cloud API (Meta) per company: access token, phone number id, app secret dan verify token disimpan terenkripsi di
`company_integrations` (provider `whatsapp`, diisi admin di Pengaturan → Integrasi, izin `company.integrations.manage`) dan dibaca lewat
`IntegrationConfig`. `WHATSAPP_*` di `.env` hanya sumber impor (`php artisan inbils:integrations:import-env --company=<kode>`);
`WHATSAPP_GRAPH_VERSION` tetap dibaca saat runtime. Company tanpa baris integrasi aktif tidak mengirim apa pun. Per company juga ada saklar
non-rahasia di `companies.settings`. Pesan hanya berupa template `utility` yang sudah disetujui Meta (`config/whatsapp.php`); aplikasi
tidak pernah mengirim teks bebas. Template billing: `docs/business/billing.md`; tiket dan SPK: `docs/business/ticketing.md`.

- **Persetujuan (opt-in).** Tidak ada pesan ke customer tanpa `whatsapp_opt_in_at` atau dengan `whatsapp_opt_out_at` terisi. Customer
  lama mulai tanpa persetujuan sampai staf mencatatnya. Hanya `SetCustomerWhatsAppConsentAction` yang menulisnya (kolom tidak
  mass-assignable): dipanggil dari onboarding (`customer.create`) dan dari edit customer (`customer.update`) lewat field
  `whatsapp_opt_in`. Mencentang mencatat waktu, sumber (`staff`) dan user pencatat (`whatsapp_opt_in_by`) serta menghapus opt-out;
  melepas centang mengisi `whatsapp_opt_out_at`. Nilai yang tidak berubah tidak menulis dan tidak masuk audit. Audit `whatsapp`
  (`opted_in`/`opted_out`) hanya berisi id.
- **STOP / BERHENTI.** Balasan `STOP` atau `BERHENTI` (tanpa beda huruf besar/kecil, spasi pinggir diabaikan) dari sebuah nomor
  mengisi `whatsapp_opt_out_at` pada semua customer yang nomornya cocok (setelah normalisasi) HANYA di company-company yang memakai
  nomor pengirim yang dituju balasan itu (`metadata.phone_number_id`) dan yang app secret-nya menandatangani body; company dengan nomor
  pengirim lain tidak terpengaruh walau customernya bernomor sama. Audit `opted_out_by_reply` per company. Tidak ada balasan otomatis.
  Staf dapat mencentang lagi (persetujuan baru). Meta mengirim callback
  minimal satu kali, jadi satu STOP tidak boleh berlaku dua kali atau mencabut persetujuan yang staf catat sesudahnya: (a) tiap id
  pesan masuk dicatat per company di `whatsapp_inbound_receipts` (hanya HMAC id pesan, tanpa nomor dan teks; dihapus setelah 30 hari
  oleh `model:prune` harian) dalam transaksi yang sama dengan pencabutan, sehingga pencabutan yang gagal membatalkan receipt-nya,
  webhook menjawab error dan Meta mengirim ulang; (b) STOP yang dikirim (timestamp bertanda tangan) sebelum `whatsapp_opt_in_at`
  terakhir diabaikan. STOP tanpa id pesan tetap berlaku dan hanya tunduk pada aturan (b).
- **Teknisi**: tanpa opt-in per orang (instruksi kerja); dilewati bila user nonaktif, profil pegawai tidak aktif, atau nomor tidak valid.
- **Nomor**: dinormalisasi saat kirim, tanpa mengubah data tersimpan. Hanya seluler Indonesia (`^628[1-9][0-9]{6,10}$` setelah
  `+62`/`0062`/`62`/`0` → `62`); telepon rumah, nomor asing dan `812…` tanpa awalan dilewati sebagai `invalid_phone` dan ditandai
  `reachable: false` di halaman customer.
- **Pengaturan company** (`/admin/company/settings`, `company.manage`; akun WhatsApp sendiri di `/admin/company/integrations`): `whatsapp_enabled` (default mati), `whatsapp_events` (default
  semua event), jam tenang `whatsapp_quiet_start`/`whatsapp_quiet_end` (default 21:00-07:00 waktu company; pesan batch — invoice
  terbit dan pengingat — menunggu selesai, pesan berbasis kejadian tidak), `whatsapp_monthly_cap` (default 1000, 0 = tidak ada pesan,
  lewat batas = `skipped: budget_exceeded`; satu slot dipakai tiap pesan yang diterima Meta sejak awal bulan kalender company menurut
  waktu kirim `sent_at`, bukan waktu baris dibuat, dan tiap pesan `queued` yang masih bisa dikirim ulang — 2 jam sejak percobaan
  terakhir; slot dipesan di bawah kunci baris company, sehingga worker paralel tidak melewati batas). Halaman menampilkan jumlah
  sent/delivered/failed/billable bulan berjalan dan apakah company ini punya akun WhatsApp yang aktif (boolean saja). Pesan "sent" = diterima Meta (status
  sent, delivered, read).
- **Log pesan** `whatsapp_messages` (per company, dihapus setelah 12 bulan oleh `model:prune` harian): event, subjek, template,
  status (`queued|sent|delivered|read|failed|skipped`), alasan skip (`disabled|event_disabled|no_consent|opted_out|invalid_phone|
  budget_exceeded`), kode error, id pesan Meta, hash HMAC nomor dan 4 digit terakhir. Tidak menyimpan nomor lengkap, teks, atau
  parameter. Kunci dedupe `(company_id, dedupe_key)` mencegah kirim ganda; baris `skipped` ditimpa percobaan berikutnya, baris `queued`
  dengan kode error (gagal sementara) diambil ulang oleh retry. Halaman customer menampilkan 20 baris terakhir.
- **Webhook** `GET|POST /webhooks/whatsapp`, satu URL bersama untuk semua company (tanpa session/CSRF; throttle hanya untuk permintaan
  yang gagal verifikasi): GET `hub.verify_token` (cocok bila verify token salah satu company aktif sama); POST `X-Hub-Signature-256`
  (HMAC-SHA256 body mentah dengan app secret). POST diarahkan menurut `metadata.phone_number_id` ke baris `company_integrations` aktif
  dengan `external_id` itu, dan hanya company yang app secret-nya menandatangani body yang diproses (`WhatsAppCloudClient::verifiedCompanies`).
  Beberapa company satu grup boleh memakai nomor yang sama; tiap company tetap punya secret sendiri. Status bergerak maju saja
  (`sent → delivered → read`; `failed` hanya dari `queued|sent`); status delivered/read yang tiba saat baris masih `queued` juga
  mengisi `sent_at`.
- **Deploy**: `php artisan migrate`; lalu per company `php artisan inbils:integrations:import-env --company=<kode>` (sekali; sampai itu atau
  admin mengisi Pengaturan → Integrasi, WhatsApp mati untuk company itu); worker WAJIB mendengarkan antrean `whatsapp`
  (`queue:work --queue=default,whatsapp`; ubah `compose.yaml` dan `deploy/systemd/queue-worker.service.example`); daftarkan
  `<APP_URL>/webhooks/whatsapp` dan verify token company di aplikasi Meta; admin company mencentang `whatsapp_enabled`. Tanpa akun
  aktif (token dan phone number id) fitur mati dan setiap pesan tercatat `skipped: disabled`. Image produksi membawa `docker/php/conf.d/inbils.ini`
  (`zend.exception_ignore_args=On`) supaya stack trace di `failed_jobs` dan log tidak memuat argumen pemanggilan (nomor telepon);
  parameter yang memuat nomor, token atau isi webhook juga bertanda `#[\SensitiveParameter]`. Sail memakai php.ini paket Ubuntu yang
  sudah bersetelan sama.
- **Batas Meta** (dicek 2026-10-11): portofolio baru boleh mengirim ke 250 nomor unik per 24 jam di luar jendela layanan; 2.000 setelah
  verifikasi bisnis; lalu naik otomatis. Batch lebih besar dari batas harus tersebar beberapa hari (pengingat sudah tersebar menurut
  jatuh tempo).

`akses_portal_info` — params: nama company, email tersamar (tanpa URL)

```
Akun portal pelanggan Anda di {{1}} sudah dibuat. Link untuk mengatur kata sandi sudah dikirim ke email {{2}}.
Cek kotak masuk atau folder spam Anda.
```
Contoh: `PT Contoh Net`, `b***@example.com`

## Permission

```
customer.view
customer.create
customer.update
customer.delete
customer.address.manage      (address + contact CRUD)
customer.sales.assign        (set acquirer dari Unassigned; lihat Sales attribution)
customer.sales.correct       (ubah/kosongkan acquirer + wajib alasan)
customer.subscription.view
customer.subscription.manage (create/edit subscription)
customer.subscription.activate
customer.subscription.suspend
customer.subscription.reactivate
customer.subscription.terminate
customer.export
customer.manage              (super)
```

## API (Route)

Lihat `docs/API.md` Section 2 (Customer group). Ringkas:

- `GET    /admin/customers` (list, filter: type, area, status (customer_status), account (is_active), search name/code/phone)
- `POST   /admin/customers` (create)
- `GET    /admin/customers/{customer}` (detail + addresses + contacts + subscriptions)
- `PUT    /admin/customers/{customer}` (update)
- `DELETE /admin/customers/{customer}` (soft delete — restrict if active subscription)
- `GET    /admin/customers/{customer}/addresses`
- `POST   /admin/customers/{customer}/addresses`
- `PUT    /admin/customers/{customer}/addresses/{address}`
- `DELETE /admin/customers/{customer}/addresses/{address}`
- `GET    /admin/customers/{customer}/contacts`
- `POST   /admin/customers/{customer}/contacts`
- `PUT    /admin/customers/{customer}/contacts/{contact}`
- `DELETE /admin/customers/{customer}/contacts/{contact}`
- `GET    /admin/customers/{customer}/subscriptions`
- `POST   /admin/customers/{customer}/subscriptions` (create pending)
- `GET    /admin/subscriptions/{sub}` (detail + package + ont + pop + invoices + spks + tickets)
- `PUT    /admin/subscriptions/{sub}` (update mrc/contract/notes — no status change via PUT)
- `POST   /admin/subscriptions/{sub}/activate`
- `POST   /admin/subscriptions/{sub}/suspend` (body: reason)
- `POST   /admin/subscriptions/{sub}/reactivate`
- `POST   /admin/subscriptions/{sub}/terminate` (body: reason, release_ont bool)
- `GET    /admin/customers/export`
- `POST   /admin/customers/{customer}/access-link` (kirim ulang link atur kata sandi)
- `GET    /admin/customers?acquired_by={user_id|unassigned}` (hanya pemegang assign/correct; selain itu diabaikan)
- `POST   /admin/customers` menerima `acquired_by_user_id` opsional; `PUT /admin/customers/{customer}`
  menerima `acquired_by_user_id` dan `acquirer_correction_reason` (wajib saat koreksi)

## Testing Scenario

### Create customer + address + subscription
1. Create customer Company: code CUS-2026-00001, name PT Contoh, tax_id
   01.234.567.8-90.000, area_coverage=region Jakarta.
2. Add address: label "Kantor Pusat", is_installation_point=true,
   is_primary=true, lat/lng set.
3. Add contact: name Budi, position IT Manager, phone 0812, is_primary.
4. Create subscription: customer, package "Home 50Mbps", installation
   address, billing_day=5, mrc=snapshot package price 250000,
   otc_installation_fee=500000, contract_months=12. Status=pending.

### Subscription lifecycle
1. Subscription pending → activate: status=active, activation_date=today,
   next_invoice_date=5 bulan ini (atau bulan depan jika sudah lewat).
2. Suspend (reason "non-pay invoice INV-X"): status=suspended.
3. Reactivate: status=active, next_invoice_date recalculated.
4. Terminate (reason "cancel", release_ont=true): status=terminated,
   ont_asset.status=Available, NetworkAssetInstallation removed_at=now.

### Validation
1. Delete customer with active subscription → 422 "cannot delete customer
   with active subscription".
2. Set 2nd address is_installation_point=true → 1st cleared (app guard).
3. Suspend already-suspended → 422.
4. Terminate already-terminated → 422.

### Authorization
1. Staff create customer → 200. Staff suspend subscription → 403.
2. Technician view customer → 200. Technician edit customer → 403.
3. NOC view subscription → 200. NOC terminate → 403.

### Number race
1. Concurrent create 2 customer → 2 code beda, tidak duplikat.

## Acceptance Criteria

- [ ] Customer + Address + Contact + Subscription CRUD sesuai.
- [ ] Subscription lifecycle (pending→active→suspended→active→terminated)
  dengan side effect benar (ont release, recurring stop/resume).
- [ ] Max 1 installation_point per customer (app guard).
- [ ] Suspend/reactivate trigger dari billing job (auto) + manual.
- [ ] Soft delete customer restrict jika active subscription.
- [ ] Number generation unique + race-safe (CUS + SUB).
- [ ] Policy per aksi.
- [ ] Activity log: create, address add, subscription activate/suspend/
  reactivate/terminate, customer delete.
- [ ] Factory + seeder (20 customer: 12 Individual + 8 Company, 30
  subscription: 20 active, 5 suspended, 3 pending, 2 terminated).
- [ ] Feature test ≥ 80% coverage.
- [ ] UI: customer list (filter type/area/search), detail (tab: profile
  + addresses + contacts + subscriptions + tickets + invoices + spks),
  form. Subscription detail (package + ont + pop + lifecycle buttons).
- [ ] UI pakai Components/ui + composite.
- [ ] Dark mode + responsive.

## Module Dependencies

- **Depends on (Phase 1):** Core (Company, User, Role, Permission,
  ActivityLog, Setting), Location (topology — area_coverage + serving_pop).
- **Depends on (Phase 2):** Service (ServicePackage — FK untuk
  subscription). ServicePackage di Modules/Service, FK direct (catalog,
  seperti Product di Inventory).
- **Consumed by (Phase 3+):** NetworkAsset (ont_asset_id link),
  SPK (subscription reference), Ticketing (subscription link), Billing
  (recurring invoice source), Reporting (read).
- **Shared Core (A1):** Customer/CustomerAddress/CustomerContact/
  ServiceSubscription di app/Models/Core/ — direct import oleh 5 consumer
  module. SubscriptionService di app/Services/Core/ — direct call.
