# Module: Billing

> Status: DRAFT v2 (ISP pivot 2026-06-30). Phase 5 eksekusi. Dependency:
> Phase 1 (Core) + Phase 2 (Customer/Service) + Phase 4 (SPK — from-SPK
> one-time invoice). Lokasi module: `Modules/Billing/` (nwidart).
> ISP: Billing = subscription recurring (MRC bulanan) + one-time invoice
> (OTC dari SPK) + suspend/reactivate service. Keputusan E1:
> service_subscription = billing subscription (no separate entity —
> billing fields di service_subscriptions, recurring job baca langsung).

## Tujuan

Mengelola siklus hidup faktur ISP: recurring MRC (Monthly Recurring
Charge, generate bulanan dari active service_subscriptions), one-time
OTC (Onboarding/installation/upgrade/relocation fee dari SPK), payment
penerimaan immutable, due date + overdue, suspend service (non-pay) +
reactivation. Pajak PPN configurable. PDF faktur. Source of truth
subscription status (active/suspended/terminated) = service_subscriptions
(shared Core), Billing module orchestrates suspend/reactivate via
SubscriptionService.

## User Role

| Role | Hak |
|------|-----|
| admin | full CRUD + send + record payment + suspend/reactivate + cancel + delete |
| manager | full CRUD + send + record payment + suspend/reactivate + cancel (no delete) |
| noc | view invoice + subscription status (suspend flag) (no edit) |
| staff | view + create draft + send + record payment (no suspend/delete) |
| technician | no access (billing = back-office) |
| customer | tidak ada akses web; tagihan dan bayar online lewat aplikasi Android Inbils Pelanggan (lihat "Pembayaran online"). Web hanya menyisakan halaman return/finish Midtrans. |

## Entity

### Invoice (Modules/Billing/Models/Invoice.php)
Faktur. Type (one_time/recurring). Source (spk/subscription/manual).
Customer + subscription (for recurring) + work_order (for OTC from SPK).
Status state machine. Billing period (for recurring MRC period).

### InvoiceItem (Modules/Billing/Models/InvoiceItem.php)
Line item. Product optional (non-item line = deskripsi bebas, mis. jasa
instalasi/MRC). Quantity, unit_price, discount, tax_rate per line.

### Payment (Modules/Billing/Models/Payment.php)
Penerimaan pembayaran. Immutable (tidak delete, correction = reverse +
new). Method (cash/transfer/cheque/other). Reference (no. transfer).

TIDAK ada RecurringInvoiceSchedule entity (E1 — service_subscriptions
carries billing_day + next_invoice_date + status; recurring job baca
langsung + dedup via query invoice existing untuk subscription+period).

## Database Relation

```
companies ──< invoices
customers ──< invoices (FK customer_id NOT NULL)
service_subscriptions ──< invoices (FK subscription_id nullable — recurring source)
work_orders ──< invoices (FK work_order_id nullable — OTC from SPK, unique per SPK)
users ──< invoices (FK created_by, sent_by)
invoices ──< invoice_items (cascade)
invoices ──< payments
users ──< payments (FK received_by)
products ──< invoice_items (FK product_id nullable)
```

Skema: `docs/DATABASE.md` Section 11 (Billing).

## Entity Fields

### invoices
- id, company_id FK NOT NULL, number NOT NULL, type enum
  (one_time/recurring) NOT NULL, source enum(spk/subscription/manual)
  NOT NULL, customer_id FK NOT NULL (restrict), subscription_id nullable
  FK→service_subscriptions.id (restrict — wajib jika type=recurring),
  work_order_id nullable FK→work_orders.id (restrict — unique per SPK,
  untuk OTC from SPK), issue_date date NOT NULL, due_date date NOT NULL,
  billing_period_start date nullable (recurring MRC period start),
  billing_period_end date nullable (recurring MRC period end), status
  enum(draft/sent/partial/paid/overdue/cancelled) NOT NULL default
  'draft', subtotal decimal(15,2) NOT NULL, tax_amount decimal(15,2)
  NOT NULL default 0, discount_amount decimal(15,2) NOT NULL default 0,
  total decimal(15,2) NOT NULL, paid_amount decimal(15,2) NOT NULL
  default 0, notes nullable, created_by FK→users.id NOT NULL, sent_at
  timestamp nullable, cancelled_at timestamp nullable, cancel_reason
  nullable, created_at, updated_at, deleted_at.
- Unique: `(company_id, number)`, `(work_order_id)` partial (unique per
  SPK — satu invoice per SPK). Index: `(company_id, status)`,
  `(company_id, customer_id)`, `(company_id, subscription_id)`,
  `(company_id, source)`, `(company_id, issue_date)`. Trait
  `BelongsToCompany`. Soft delete.
- Check: `type IN ('one_time','recurring')`, `source IN ('spk',
  'subscription','manual')`, `status IN ('draft','sent','partial','paid',
  'overdue','cancelled')`.
- Kolom beku (nullable): `customer_name_snapshot` varchar(255),
  `customer_address_snapshot` text, `customer_tax_id_snapshot` varchar(255),
  `package_name_snapshot` varchar(255). Diisi saat invoice keluar dari draf
  (lihat "Pihak tagihan beku").
- `active_recurring_period` date, generated STORED: `billing_period_start`
  bila `type='recurring'`, `status<>'cancelled'`, dan `deleted_at` kosong;
  selain itu NULL. Unique `invoices_active_recurring_period_unique`
  `(company_id, subscription_id, active_recurring_period)`: dua invoice
  recurring aktif untuk satu langganan dan periode ditolak, sedangkan invoice
  batal, terhapus, manual, dan sekali bayar (NULL) tidak bentrok. Migrasi
  berhenti sebelum mengubah apa pun bila sudah ada dua invoice aktif untuk
  periode yang sama.

### invoice_items
- id, company_id FK NOT NULL, invoice_id FK NOT NULL (cascade),
  product_id nullable FK→products.id (restrict — nullable untuk jasa/
  MRC line), description varchar NOT NULL, quantity decimal(15,2) NOT
  NULL default 1, unit_price decimal(15,2) NOT NULL, discount_amount
  decimal(15,2) default 0, tax_rate decimal(5,2) default 0, line_total
  decimal(15,2) NOT NULL, created_at, updated_at.
- Index: `(company_id, invoice_id)`, `(company_id, product_id)`. Trait
  `BelongsToCompany`.

### payments
- id, company_id FK NOT NULL, invoice_id FK NOT NULL (restrict), amount
  decimal(15,2) NOT NULL, method enum(cash/transfer/cheque/other/midtrans) NOT
  NULL, reference varchar nullable (no. transfer/cheque; order_id untuk
  `midtrans`), paid_at timestamp NOT NULL, received_by FK→users.id
  **nullable** (NULL = dicatat sistem, yaitu pembayaran gateway), notes
  nullable, cancelled_at timestamp nullable, cancel_reason nullable,
  created_at, updated_at.
- Index: `(company_id, invoice_id)`, `(company_id, paid_at)`. Trait
  `BelongsToCompany`.
- TIDAK soft delete (immutable financial record). Correction = reverse
  (cancel + reason) + new payment.
- Check: `method IN ('cash','transfer','cheque','other','midtrans')`.
  `RecordPaymentRequest` membatasi staf ke empat metode manual; `midtrans`
  hanya ditulis oleh jalur gateway.

## Workflow

Lihat `docs/WORKFLOW.md` Section 7 (Billing). Ringkas state machine:

```
[draft] ──send──→ [sent] ──payment(full)──→ [paid]
                    │
                    ├──payment(partial)──→ [partial] ──payment(rest)──→ [paid]
                    │
                    └──due_date pass + unpaid──→ [overdue] (auto job harian)
                    │                         │
                    │                         └──overdue > threshold──→ [auto-suspend subscription] (job, opt-in)
                    │
                    └──cancel──→ [cancelled]
[draft] ──cancel──→ [cancelled]
```

### Recurring MRC generation (harian)
- `billing:generate {--period=YYYY-MM} {--dry-run} {--company=}` dijadwalkan
  harian 02:00 (`withoutOverlapping(120)`). Tanpa `--period` memakai bulan
  sebelumnya. Staf dengan `billing.create` menjalankan periode pilihan dari
  jendela **Buat tagihan bulanan** (pratinjau dulu). Keduanya memanggil
  `BillingService::generateForPeriod`.
- Sasaran: langganan `active` atau `terminated` yang `activation_date`-nya
  tidak lewat dari akhir periode dan, bila sudah berhenti, belum berhenti
  sebelum awal periode. Langganan `pending` dan `suspended` tidak ditagih.
- Satu invoice per langganan per periode. Dedup:
  - Periode dianggap sudah diterbitkan bila langganan itu punya invoice
    `recurring` untuk periode itu dalam status apa pun, **termasuk
    `cancelled`**. Jadwal tidak menerbitkan ulang periode yang dibatalkan
    orang, baik dari job harian maupun dari tombol manual. Periode yang
    disengketakan ditagih manual sebagai invoice sekali bayar.
  - Cek diulang di bawah kunci baris langganan sebelum invoice dibuat, sehingga
    dua proses yang berlomba menghasilkan satu invoice.
  - Backstop database: kolom `invoices.active_recurring_period` (lihat Entity
    Fields) unik per `(company_id, subscription_id, periode)` untuk invoice
    recurring yang tidak batal dan tidak dihapus. Pelanggaran unique key
    dihitung sebagai dilewati.
  - Langganan yang nominalnya 0 (misalnya sudah berhenti sebelum periode)
    dilewati.
- Isi invoice: type=recurring, source=subscription, customer_id,
  subscription_id, issue_date=hari ini, due_date=hari ini + `invoice_due_days`
  perusahaan (bawaan 14), periode awal dan akhir bulan, status `sent` langsung
  (dengan `sent_at`), `created_by` kosong. Satu item: "MRC {nama paket} {awal}
  s/d {akhir}", ditambah " (prorata {hari aktif}/{hari dalam bulan} hari)" bila
  prorata. Harga = `mrc_amount` langganan, dikali hari aktif per hari dalam
  bulan untuk prorata. PPN memakai tarif perusahaan (lihat Tax).
- Pihak tagihan dibekukan saat invoice dibuat (lihat "Pihak tagihan beku").
  Audit `recurring_generated`. Event `InvoiceIssued` memicu WhatsApp.
- Satu langganan yang gagal dilaporkan (`report()`) dan dihitung di `failed`;
  langganan lain tetap ditagih. Hasilnya `{created, skipped, failed}`. Toast
  tombol manual: "Tagihan {periode}: {n} dibuat, {n} dilewati" dan ", {n}
  gagal" bila ada. Command mengembalikan `FAILURE` bila ada yang gagal, dan
  hanya admin perusahaan itu yang diberi tahu.
- Run harian, bukan bulanan, supaya periode yang terlewat tersusul.

### Versi paket dan Ganti paket
- Paket menyimpan harga dan profil terkini. Perubahan harga bulanan, harga
  pemasangan, profil bandwidth, profil kecepatan, tingkat SLA, atau kategori
  menambah satu baris `service_package_versions` (hanya tambah, tidak bisa
  diubah). Mengubah kode, nama, deskripsi, masa kontrak minimum, atau status
  aktif tidak membuat versi. Langganan menunjuk versi yang dijualnya
  (`service_subscriptions.service_package_version_id`) dan tetap di sana saat
  paket berubah.
- Penagihan tidak berubah: invoice membaca `service_subscriptions.mrc_amount`,
  bukan harga paket. Mengedit paket tidak mengubah tagihan langganan yang ada.
- **Ganti paket** (`POST /admin/subscriptions/{subscription}/change-package`,
  izin `customer.subscription.manage`, tombol di kartu **Paket** pada detail
  langganan) memindahkan langganan ke versi terbaru paket yang dipilih,
  `SubscriptionService::changePackage` di dalam satu transaksi dengan kunci
  pelanggan dan langganan:
  - `service_package_id` dan versi diganti.
  - `mrc_amount` menjadi harga bulanan versi itu, atau nilai yang diisi di
    **Biaya bulanan**.
  - `otc_installation_fee` mengikuti harga pemasangan versi itu hanya saat
    langganan `pending`.
  - Audit `package_changed` mencatat paket, versi, biaya bulanan, dan biaya
    pemasangan sebelum dan sesudah.
  - Ditolak: langganan `terminated` ("Langganan yang sudah berhenti tidak bisa
    pindah paket."), langganan yang sudah di versi terbaru paket itu
    ("Langganan sudah memakai versi terbaru paket ini."), dan paket yang
    tidak aktif atau dihapus ("Pilih paket layanan yang masih aktif."). Paket
    perusahaan lain menjawab 404.
  - Tidak ada prorata. Invoice yang sudah terbit tidak berubah. Periode yang
    belum ditagih memakai `mrc_amount` yang berlaku saat invoice periode itu
    dibuat. Biaya pemasangan SPK (`CreateInvoiceFromSpkAction`) membaca
    `otc_installation_fee` langganan dan tidak berubah.
- Ganti paket adalah satu-satunya cara memindahkan langganan ke paket lain:
  formulir ubah langganan tidak lagi memuat paket.
- Yang ikut versi, bukan paket hidup: profil kecepatan RADIUS, tingkat SLA
  tiket, kategori paket pada filter laporan, dan syarat paket di API
  langganan pelanggan.
- Paket hanya bisa dihapus permanen bila tidak ada langganan yang pernah
  memakainya (termasuk yang berhenti); selain itu hanya dinonaktifkan.

### Pihak tagihan beku (nama, alamat, NPWP, paket)
- `Modules\Billing\Support\InvoiceParty`. Invoice draf mencetak pelanggan dan
  paket yang berlaku saat ini. Saat invoice keluar dari draf, empat kolom
  snapshot diisi sekali: `customer_name_snapshot`,
  `customer_address_snapshot`, `customer_tax_id_snapshot` (NPWP), dan
  `package_name_snapshot`. Titik pengisiannya:
  - `BillingService::send` (draf menjadi terkirim);
  - `BillingService::generateForPeriod` (invoice berulang langsung terkirim);
  - `BillingService::cancel`: draf yang dibatalkan juga membeku. Invoice yang
    sudah terbit punya snapshot dan tidak ditimpa.
- Alamat = alamat pemasangan langganan, kalau tidak ada alamat utama
  pelanggan (alamat yang sudah dihapus tetap dipakai bila itu satu-satunya),
  ditulis `AddressLine::of`: alamat, kelurahan, kecamatan, kota, provinsi,
  kode pos, dipisah koma, bagian kosong dilewati. Paket = nama paket
  langganan; kosong bila invoice tanpa langganan.
- Mengganti nama pelanggan, memindahkan alamat, atau mengganti nama paket
  sesudahnya tidak mengubah invoice yang sudah terbit. Pembaca membaca lewat
  `InvoiceParty::of` (snapshot bila ada, selain itu data hidup):
  - PDF staf dan kwitansi PDF pelanggan;
  - detail invoice staf: blok **Detail** (Pelanggan, Alamat, NPWP, Paket) dan
    tulisan "(saat terbit)" di samping nama bila nama pelanggan sekarang
    berbeda; `InvoiceResource` mengirim keempat kolom snapshot mentah;
  - API pelanggan (`PortalInvoiceResource`): `customer_name`,
    `customer_address`, dan `package_name`;
  - **Tunggakan**: nama pelanggan diambil dari invoice terbuka terbaru
    pelanggan itu.
- NPWP tidak pernah dikirim ke aplikasi pelanggan: `PortalInvoiceResource` tidak
  punya `customer_tax_id`, dan kwitansi PDF pelanggan tidak mencetak NPWP. PDF
  staf mencetak "NPWP: ..." bila ada.
- Label item MRC memakai `package_name_snapshot` saat invoice dibuat.
- Invoice non-draf yang sudah ada sebelum fitur ini diisi sekali oleh migrasi
  `2026_10_23_100200_add_snapshot_columns_to_invoices_table` dengan data hidup
  saat migrasi berjalan, hanya baris yang snapshot-nya masih kosong.

### One-time OTC from SPK
- `CreateInvoiceFromSpkAction` (dipanggil CompleteSpkAction saat approve,
  if Setting `spk.auto_invoice` OR manual trigger button):
  - Validate work_order.status=completed.
  - Validate tidak ada invoice existing untuk work_order_id (unique).
  - Create Invoice type=one_time, source=spk, customer_id (dari SPK),
    work_order_id link.
  - InvoiceItem map dari work_order_items (consumable: description=
    product.name, quantity=quantity_used, unit_price=product.sell_price)
    + 1 line jasa instalasi (description="Biaya Instalasi {type}",
    unit_price=subscription.otc_installation_fee).
  - subtotal + tax + total. Status=draft.

### Payment processing
- `record_payment(amount, method, reference)`: paid_amount += amount.
  - Hanya invoice `sent|partial|overdue` yang menerima pembayaran
    (`draft`/`cancelled` → 422), manual maupun gateway.
  - amount >= sisa → status=paid.
  - 0 < amount < sisa → status=partial.
  - amount > sisa → 422 (overpay validation).
- Payment immutable. Cancel = reverse (cancel_reason) + new payment.
- Pembayaran apa pun yang dicatat, dan pembatalan invoice, menutup halaman
  pembayaran online yang masih terbuka (lihat "Halaman pembayaran yang ditutup
  Inbils").

### Overdue + auto-suspend (isolir otomatis)
- `billing:check-overdue` (harian 03:00): invoice `sent|partial` dengan
  due_date < hari ini dan paid < total → status=overdue + AuditLog.
- `billing:suspend-overdue {--company=} {--dry-run}` (harian **08:00**, jam
  kantor agar customer service bisa menerima telepon yang ditimbulkannya;
  setelah check-overdue; `withoutOverlapping`). Per perusahaan aktif, hanya
  bila setting company `auto_suspend_enabled` (default `false`).
  Subscription `active` yang punya invoice milik subscription itu sendiri
  (belum soft-delete, status `sent|partial|overdue`, `paid_amount < total`)
  dengan `due_date` lebih dari `auto_suspend_overdue_days` hari lalu
  (default 7; tepat N hari → belum, N+1 → ya) di-suspend lewat
  `SubscriptionService::suspend(..., 'overdue')`, satu transaksi per
  subscription, AuditLog `suspended` (`source=overdue`, tanpa causer).
  Aturan membaca `due_date`, bukan `status`, karena pembayaran parsial
  mengembalikan invoice `overdue` menjadi `partial` sampai check-overdue
  berikutnya. `--dry-run` hanya mencetak subscription yang akan di-suspend.
  Kegagalan → `BillingScheduleFailedNotification` ke admin aktif.
- Subscription suspended tidak ditagih (`generateForPeriod` hanya
  `active|terminated`), jadi isolir otomatis juga menghentikan invoice
  recurring-nya.
- `service_subscriptions.suspension_source` (`manual|overdue`, tidak
  fillable, hanya ditulis `SubscriptionService`): "Isolir" di Receivables dan
  job otomatis menulis `overdue`; Suspend di halaman Subscriptions menulis
  `manual` (field opsional `source` pada `POST /admin/subscriptions/{sub}/suspend`,
  default `manual`). `reactivate` dan `terminate` mengosongkannya.

### Reactivate
- Otomatis: setiap pembayaran (manual maupun Midtrans) mengirim event
  `PaymentRecorded(companyId, invoiceId, paymentId)` setelah commit; listener
  antrean `ReactivateSubscriptionAfterPayment` memanggil
  `OverdueSuspensionService::reactivateIfCleared` di dalam
  `CompanyService::runAs` perusahaan invoice. Syarat: setting
  `auto_reactivate_on_payment` (default `true`), subscription `suspended`
  dengan `suspension_source=overdue` (suspend `manual` tidak pernah dibuka
  otomatis), dan tidak ada lagi invoice subscription itu yang memenuhi aturan
  isolir di atas (pembayaran parsial belum cukup). Pembayaran yang
  transaksinya di-rollback tidak menyambungkan apa pun.
- Manual: `SubscriptionService::reactivate`. Keduanya: status=active,
  `next_invoice_date` = billing_day berikutnya, `suspension_source`
  dikosongkan, resume recurring generation, AuditLog.

### Manual suspend/reactivate
- Admin/manager bisa manual suspend (reason) / reactivate subscription
  via SubscriptionService (Customer module shared). Billing UI host
  buttons.

### Block subscription
- Block (`customer.subscription.block`) tidak menghentikan invoice recurring dan bukan status; lihat `docs/business/customer.md` § Block / unblock.
- Pembayaran tidak pernah mencabut block: pembayaran yang menyambungkan kembali isolir `overdue` (Reactivate) hanya mengubah status, `blocked_at` tetap.
- Receivables menampilkan `subscriptions[].blocked_at` per baris dan `can.block`.

### Tax (PPN)
- Default rate Setting `tax.ppn_rate` (11). Per invoice_item: tax_rate
  default = invoice rate, override per line.
- `tax_amount = sum(line_total * tax_rate / 100)`.
- `total = subtotal + tax_amount - discount_amount`.

### Number generation
- `INV-{YEAR}-{NNNNN}`. Lock + transaction per tahun.

### PDF
- Route `GET /admin/invoices/{invoice}/pdf`. View
  `resources/views/pdf/invoice.blade.php` (A4, logo, period, items table,
  total). Policy `view`. Blok "Kepada" mencetak `InvoiceParty::of($invoice)`:
  nama pelanggan, alamat, "NPWP: ..." bila ada, dan "{kode langganan} - {nama
  paket}" (lihat "Pihak tagihan beku").
- Kwitansi untuk aplikasi pelanggan: `GET /api/v1/customer/invoices/{id}/receipt`,
  hanya invoice `paid` milik pelanggan itu, memakai view yang sama. NPWP tidak
  pernah sampai ke aplikasi pelanggan, jadi kwitansi tidak mencetak NPWP.

## Permission

```
billing.view
billing.create
billing.update
billing.delete
billing.send
billing.payment.record
billing.cancel
billing.suspend          (subscription)
billing.reactivate       (subscription)
billing.pdf.view
billing.export
billing.manage           (super)
```

## API (Route)

Lihat `docs/API.md` Section 7 (Billing group). Ringkas:

- `GET    /admin/invoices` (list, filter: type, status, source, customer, subscription, periode)
- `POST   /admin/invoices` (create draft one_time manual)
- `GET    /admin/invoices/{inv}` (detail + items + payments + linked subscription/spk)
- `PUT    /admin/invoices/{inv}` (update draft only)
- `DELETE /admin/invoices/{inv}` (soft delete draft only)
- `POST   /admin/invoices/{inv}/restore` (pulihkan draf yang dihapus; izin sama dengan hapus)
- `POST   /admin/invoices/{inv}/items` (add line)
- `PUT    /admin/invoices/{inv}/items/{item}` (update line, draft only)
- `DELETE /admin/invoices/{inv}/items/{item}` (remove line, draft only)
- `POST   /admin/invoices/{inv}/send`
- `POST   /admin/invoices/{inv}/payments` (record payment — trigger reactivate if auto)
- `POST   /admin/invoices/{inv}/cancel` (body: reason)
- `GET    /admin/invoices/{inv}/pdf`
- `POST   /admin/invoices/{inv}/create-from-spk` (manual trigger OTC from SPK, if not auto)
- `POST   /admin/subscriptions/{sub}/suspend` (body: reason, `source` opsional `manual|overdue`, default `manual`)
- `POST   /admin/subscriptions/{sub}/reactivate` (manual)
- `POST   /admin/subscriptions/{sub}/change-package` (body: `service_package_id`, `mrc_amount` opsional; izin `customer.subscription.manage`; lihat "Versi paket dan Ganti paket")
- `GET    /admin/invoices/export`
- `GET /portal/payments/return` (role customer, route `portal.payments.return`; target Midtrans untuk pembayaran lama) dan `GET /pembayaran/selesai` (publik). Halaman web `/portal/invoices*` dan `/portal/password*` dihapus: pelanggan memakai aplikasi (`/api/v1/customer/*`); login teknisi/pelanggan murni di web diarahkan ke `/app-only`.
- `POST   /webhooks/midtrans/{company public_id}` (publik, bertanda tangan Midtrans dengan server key company itu; lihat "Pembayaran online") dan `POST /webhooks/midtrans` (URL lama untuk masa peralihan: company dibaca dari `order_id` percobaan bayar)

## Pembayaran online (Midtrans Snap, satu akun per company)

Keputusan owner 2026-10-11 (`.scratch/midtrans-payment/spec.md`): **pilot
sandbox**. Go-live produksi butuh persetujuan
terpisah: merchant asli, URL HTTPS publik untuk notifikasi, kunci produksi
hanya di host, dan security review yang ditandatangani. Keputusan owner 2026-10-17
(`.scratch/tenant-integrations/spec.md`): tiap company memakai akun Midtrans sendiri.
Server key dan mode (`is_production`) disimpan terenkripsi di `company_integrations` (provider
`midtrans`), diisi admin di Pengaturan → Integrasi (izin `company.integrations.manage`) dan dibaca lewat
`IntegrationConfig`. Blok `midtrans` di `config/services.php` dan `MIDTRANS_*` di `.env`
hanya sumber impor untuk `php artisan inbils:integrations:import-env --company=<kode>`. Company tanpa baris integrasi
aktif tidak punya pembayaran online: Pay 404, webhook 404, dan `payment.enabled=false` di aplikasi pelanggan.

Pengaman lingkungan: kunci sandbox hanya untuk host non-produksi. Pada host
`APP_ENV=production` dengan baris `is_production=false` gateway company itu **mati**
(Pay 404, webhook 404) kecuali baris diimpor dengan `allow_sandbox_in_production`
(dari `MIDTRANS_ALLOW_SANDBOX_IN_PRODUCTION=true` yang disetujui owner; formulir web tidak menulisnya) (sandbox di data produksi: pembayaran
uji tercatat sebagai pembayaran sungguhan pada invoice). `base_url` baris (dari `MIDTRANS_BASE_URL`, mock E2E)
hanya dihormati saat `APP_ENV` = `local`, `testing`, atau `e2e` dan `is_production=false`. Formulir web menolak pasangan kunci dan mode
yang tidak cocok (`SB-Mid-server-` hanya dengan mode sandbox).

URL notifikasi per company: `https://<host>/webhooks/midtrans/<company public_id>`, ditandatangani dengan server key company
itu. Setiap order Snap membawa header `X-Override-Notification` berisi URL itu bila `APP_URL` memakai HTTPS, jadi dashboard Midtrans tidak perlu
diubah. URL lama `/webhooks/midtrans` tetap hidup selama masa peralihan; tanggal pencabutannya belum ditetapkan.

### Alur
1. Pelanggan (role `customer` yang tertaut ke `customers.user_id`, permission
   `portal.invoice.view` / `portal.invoice.pay`) membuka tagihan di aplikasi
   Inbils Pelanggan (`GET /api/v1/customer/invoices`), membuka invoice
   `sent|partial|overdue`, menekan **Bayar**. Akun lama yang
   passwordnya nomor telepon ditandai `users.must_change_password` (migrasi
   backfill) dan **password lamanya dimatikan**: `customers:rotate-legacy-passwords`
   (langkah deploy) menggantinya dengan password acak 32 karakter dan
   mengantrekan link atur kata sandi. Selama bendera menyala API menolak login
   aplikasi (lihat `ApiTokenService`). Bendera hanya dibersihkan oleh reset
   password yang selesai lewat link email (event `PasswordReset`), yang juga
   memutar `remember_token` dan menghapus sesi database akun itu. Batas: sesi
   driver `file`/`redis` tidak bisa dicari per user, jadi hanya cookie
   remember-me yang dicabut.
2. `PaymentAttemptService::openFor` mengunci baris invoice, memakai ulang
   attempt `pending` yang belum kedaluwarsa dengan nominal = `Invoice::sisa`,
   atau membuat attempt baru + halaman Snap (`MidtransGateway`, plain `Http`
   client, HTTP Basic server key, timeout 10 s, tanpa retry). Hanya nominal
   rupiah bulat = sisa penuh; invoice `draft`/`paid`/`cancelled` ditolak (422).
   Panggilan Snap gagal → attempt di-rollback, pelanggan mencoba lagi.
3. Browser diarahkan ke `redirect_url` Midtrans (tanpa snap.js, tanpa client
   key di halaman) dan kembali ke `/portal/payments/return`, yang menampilkan
   status dari database (query string Midtrans tidak dipercaya).
4. Midtrans memanggil `POST /webhooks/midtrans`. Pembayaran dicatat lewat
   `BillingService::recordPayment` (satu-satunya tempat matematika invoice)
   di dalam `CompanyService::runAs(attempt.company_id)`.

### payment_attempts
`company_id`, `invoice_id`, `order_id` (unik global, ≤ 50 karakter,
`INB-{ULID}`, tanpa id company atau invoice; attempt lama tetap memakai
`{company_id}-{invoice_id}-{12 acak}` dan dicari lewat `order_id` yang sama), `amount`, `status`
(`pending|paid|failed|expired|exception|cancelled_locally`; label **Ditutup** untuk yang terakhir), `status_reason`, `snap_token` dan
`redirect_url` (tidak pernah diserialisasi/ditampilkan), `transaction_id`,
`payment_type`, `last_notification` (JSON tanpa `signature_key`),
`expires_at` (24 jam), `settled_at`, `payment_id` (nullable, unik). Kolom
finansial immutable; attempt tidak bisa dihapus.

### Webhook `POST /webhooks/midtrans`
- Tanpa session/CSRF (di luar group `web`); hanya POST dengan signature salah
  atau hilang yang di-throttle (30/menit per IP), notifikasi bertanda tangan
  valid tidak pernah kena 429 meski proxy membuat semua pengirim berbagi satu IP, hanya
  JSON. Gateway mati (server key kosong) → 404.
- Urutan: field wajib (422) → `signature_key` = `sha512(order_id +
  status_code + gross_amount + server key)` dengan `hash_equals` (403, body
  kosong) → cari attempt dari `order_id` (404) → ambil status ulang dari
  Status API (notifikasi hanya pemicu) → `applyNotification`. Status API
  gagal → 503 (Midtrans mencoba ulang; rekonsiliasi sebagai cadangan).
- `applyNotification` (dipakai webhook dan rekonsiliasi): kunci invoice lalu
  attempt; `payment_id` terisi = tidak ada perubahan (idempoten). Dibayar =
  `settlement`, atau `capture` dengan `fraud_status=accept`, dan
  `status_code` 200. Nominal harus sama dengan attempt. `pending` tetap
  pending; `deny|cancel|failure` → `failed`; `expire` → `expired`.
- Selalu 200 untuk notifikasi bertanda tangan valid. Nominal berbeda, invoice
  sudah tidak menerima pembayaran, referensi ganda, `refund|chargeback` →
  attempt `exception` + alasan + audit `payment_attempt`; **tidak ada
  reversal otomatis** (reversal tetap tindakan manusia maker-checker) dan
  status langganan tidak berubah.
- Pembayaran yang berhasil (attempt `paid`) lewat `recordPayment` seperti
  pembayaran manual, sehingga bisa menyambungkan kembali langganan yang
  diisolir `overdue` bila tidak ada invoice lain yang lewat grace (lihat
  "Reactivate"). Ini menggantikan keputusan midtrans Q12 untuk kasus tersebut.

### Rekonsiliasi
`php artisan midtrans:reconcile {--company=} {--dry-run}`, dijadwalkan tiap 15
menit (`withoutOverlapping`). Attempt `pending` lebih tua dari 10 menit
ditanyakan ke Status API per perusahaan aktif; jawabannya melewati
`applyNotification` yang sama. Midtrans tidak punya transaksi + halaman sudah
kedaluwarsa → `expired`. Gagal jaringan satu attempt tidak menghentikan
yang lain. Tanpa server key: keluar 0, tidak melakukan apa pun.

### Halaman pembayaran yang ditutup Inbils
Status attempt `cancelled_locally` (label **Ditutup**, netral) berarti Inbils sendiri menutup halaman Snap karena tagihannya
dibayar atau dibatalkan saat halaman masih terbuka.
- `BillingService::recordPayment` (manual maupun gateway) dan `BillingService::cancel` memanggil
  `PaymentAttemptService::closeOpenFor` di dalam transaksi yang memegang kunci invoice, setelah invoice berubah. Semua attempt
  `pending` invoice itu menjadi `cancelled_locally` dengan alasan "Tagihan dibayar atau dibatalkan saat halaman pembayaran
  masih terbuka.", kecuali attempt yang sedang dilunasi (order_id sama dengan referensi pembayaran `midtrans`). Pembayaran
  sebagian pun menutup semua halaman terbuka, karena nominal halaman (sisa saat dibuat) tidak lagi sama dengan sisa. Pelanggan
  menekan **Bayar** lagi untuk halaman baru. Audit: event `cancelled_locally`.
- Pembatalan di Midtrans berjalan dari antrean, bukan dari permintaan web: `CancelMidtransTransactionJob(companyId, attemptId)`,
  dikirim setelah commit, 3 percobaan (jeda 10, 60, 300 detik), timeout 30 detik. Job memanggil `POST /v2/{order_id}/cancel`
  lewat `MidtransGateway::cancelTransaction`. Jawaban 404 atau kode 412 (tidak ada transaksi, atau sudah lunas, kedaluwarsa,
  atau batal) bukan galat. Job berhenti diam-diam bila attempt bukan lagi `cancelled_locally` atau gateway mati. Bila gagal,
  log `midtrans.cancel_failed` (hanya order id), halaman tetap Ditutup, dan pembayaran tunai yang sudah dicatat tetap sah.
- Notifikasi Midtrans untuk halaman Ditutup: lunas diproses seperti biasa (dibukukan bila invoice masih menerima pembayaran,
  selain itu menjadi `exception`), `refund`/`chargeback` menjadi `exception`, sedangkan gagal, kedaluwarsa, pending, dan
  challenge hanya menyimpan isi notifikasi dan halaman tetap Ditutup. Pending mengantre pembatalan lagi, karena pelanggan
  sempat memilih metode bayar setelah pembatalan pertama.
- `midtrans:reconcile` juga menanyakan halaman Ditutup sampai 1 hari setelah `expires_at`, supaya webhook terlambat yang hilang
  tetap berakhir sebagai `exception` dan peringatan. Halaman Ditutup yang tidak dikenal Midtrans tetap Ditutup (tidak pernah
  `expired`).

### Pembayaran online yang perlu dicek (exception)
- Setiap attempt yang pindah ke `exception` (invoice tidak lagi menerima pembayaran, nominal berbeda, referensi ganda,
  `refund`/`chargeback`, atau pembalikan setelah lunas) mengirim `PaymentAttemptExceptionNotification` setelah commit ke
  pengguna aktif perusahaan itu yang punya izin `billing.payment.record`: bel dan, bila ada token, push. Satu pemberitahuan
  per perubahan, bukan per notifikasi Midtrans yang diulang.
- Judul bel: "Pembayaran online perlu dicek". Pesan: "Pembayaran {order_id} sebesar Rp {jumlah} untuk tagihan {nomor} tidak bisa
  dibukukan otomatis. Periksa di menu Tunggakan."
- Halaman **Tunggakan** menerima `paymentExceptions {count, items}` dari `PaymentAttemptService::openExceptions()` (10 terbaru).
  Bila `count` di atas 0, kotak merah "{n} pembayaran online perlu dicek" menulis tautan invoice, jumlah, order id, dan alasan,
  disusul "Catat pembayarannya dengan referensi order ID tersebut, atau refund di Midtrans."
- Exception terbuka (yang dihitung badge) adalah attempt `exception` dengan salah satu keadaan: (a) belum ada `payment_id`,
  tidak ada pembayaran aktif yang memakai order id sebagai referensi, dan status transaksi terakhir bukan `refund` atau
  `chargeback` (uang masuk belum dibukukan; ditutup dengan mencatat pembayaran berreferensi order id, atau refund penuh di
  Midtrans); atau (b) `payment_id` terisi dan pembayaran itu belum dibatalkan (uang dibukukan lalu dikembalikan; ditutup dengan
  membatalkan pembayarannya). Exception yang sudah selesai tidak dihitung.

### Panel staf
Halaman invoice menampilkan panel read-only "Online payments" (status, jumlah, transaction id, jenis pembayaran, alasan
exception; status `cancelled_locally` tampil **Ditutup**). Tidak ada aksi yang bisa menandai attempt lunas.

### Deploy dan rollback
- `php artisan migrate`; `php artisan db:seed --class=RolePermissionSeeder --force`;
  **`php artisan customers:rotate-legacy-passwords --dry-run`, lalu tanpa
  `--dry-run`** (sekali: mematikan password nomor telepon akun lama dan
  mengantrekan link atur kata sandi; butuh mail dan queue worker berjalan; aman
  diulang, hanya akun yang belum menyelesaikan reset yang diproses lagi dan
  dikirimi link baru); sesudah `migrate`, jalankan per company
  `php artisan inbils:integrations:import-env --company=<kode>` (sekali; membaca `MIDTRANS_SERVER_KEY`,
  `MIDTRANS_IS_PRODUCTION`, `MIDTRANS_ALLOW_SANDBOX_IN_PRODUCTION`, `MIDTRANS_BASE_URL` dari `.env`; sampai itu atau admin
  mengisi Pengaturan → Integrasi, Midtrans mati untuk company itu). Sandbox hanya di host non-produksi (host `APP_ENV=production`
  butuh kunci produksi, atau `MIDTRANS_ALLOW_SANDBOX_IN_PRODUCTION=true` yang disetujui owner). Dashboard Midtrans tidak perlu diubah
  (header `X-Override-Notification` membawa URL per company bila `APP_URL` HTTPS); atau isi "Payment Notification URL" dengan
  `https://<host>/webhooks/midtrans/<company public_id>`; pastikan
  scheduler jalan.
- Rollback migrasi: `payment_attempts` bisa dihapus; `payments.method`
  kembali ke empat nilai dan `payments.received_by` kembali NOT NULL **hanya
  jika tidak ada pembayaran `midtrans`/tanpa penerima** (migrasi menolak dan
  menjelaskan; ledger immutable).

## Testing Scenario

### Recurring MRC generation
1. Subscription active, activation_date=2026-06-05, mrc_amount=250000, PPN 11%.
2. `billing:generate --period=2026-07`: satu invoice recurring, source=subscription,
   periode 2026-07-01 s/d 2026-07-31, item MRC 250000, tax 27500, total 277500,
   status `sent`, `customer_name_snapshot` terisi.
3. Jalankan lagi periode yang sama: dilewati (invoice recurring periode itu
   sudah ada). Tidak ada invoice ganda.
4. Batalkan invoice itu, jalankan lagi: tetap dilewati. Periode yang dibatalkan
   tidak diterbitkan ulang. Insert langsung invoice recurring aktif kedua untuk
   periode yang sama ditolak unique key `active_recurring_period`, sedangkan satu
   invoice batal dan satu aktif boleh berdampingan.
5. Subscription suspended: tidak ditagih (hanya `active` dan `terminated`).
6. Ganti nama pelanggan lalu cetak PDF invoice terbit: nama lama tetap tercetak.

### One-time OTC from SPK
1. SPK installation completed. CompleteSpkAction (if auto_invoice) OR
   manual button → CreateInvoiceFromSpkAction.
2. Invoice type=one_time, source=spk, work_order_id link, customer_id.
3. Items: consumable lines (kabel 20m @5000=100000) + 1 jasa line
   ("Biaya Instalasi installation", unit_price=otc_installation_fee
   500000). subtotal=600000, tax=66000, total=666000. Status draft.
4. Duplicate call (SPK sudah punya invoice) → 422 (unique per SPK).

### Payment + reactivate
1. Invoice recurring total 277500, status=sent. Pay 277500 → status=paid.
2. Subscription suspended dengan `suspension_source=overdue`, satu invoice
   overdue (due_date 10 hari lalu). Bayar lunas → status=paid,
   `PaymentRecorded` → subscription active, `suspension_source` kosong.
   Source `manual` → tetap suspended.

### Overdue + auto-suspend
1. Invoice recurring, due_date=now-10days, unpaid. status=overdue.
2. `billing:suspend-overdue` (auto_suspend_enabled, threshold 7 hari):
   due_date tepat 7 hari lalu → belum; 8 hari → subscription suspended
   (source=overdue). Recurring job skip subscription.
3. Bayar invoice overdue (auto_reactivate_on_payment) → subscription active.

### Validation
1. Pay 300000 (sisa 277500) → 422 overpay.
2. Cancel sent dengan payment → 422 (reverse payment first).
3. Create recurring invoice tanpa subscription_id → 422.

### Authorization
1. Staff create + send + record payment → 200. Suspend → 403.
2. Technician view invoices → 403.
3. Manager suspend/reactivate → 200. Delete → 403.

### Number race
1. Concurrent create 2 invoice → 2 number beda, tidak duplikat.

## Acceptance Criteria

- [ ] Recurring MRC job bulanan (daily schedule, catch billing_day
  berbeda) + dedup (no duplicate per subscription+period).
- [ ] One-time OTC from SPK (CreateInvoiceFromSpkAction, unique per SPK).
- [ ] State machine (draft→sent→partial→paid, overdue, cancelled).
- [ ] Payment immutable (no delete, cancel+reverse only).
- [ ] Overpay validation (amount <= sisa).
- [ ] Overdue job harian + auto-suspend job (opt-in, threshold days).
- [ ] Suspend/reactivate (auto from payment/overdue + manual) via
  SubscriptionService.
- [ ] Tax calculation (per line + total).
- [ ] Number generation unique + race-safe (INV-{YEAR}-{NNNNN}).
- [ ] PDF faktur (A4, period for recurring, items, total).
- [ ] Soft delete draft only.
- [ ] Policy per aksi.
- [ ] Activity log: create, send, payment, cancel, overdue, suspend,
  reactivate, recurring_generate.
- [ ] Factory + seeder (20 invoice: 5 recurring draft, 6 recurring sent,
  3 partial, 4 paid, 1 overdue, 1 cancelled; 4 one_time from SPK).
- [ ] Feature test ≥ 80% coverage.
- [ ] UI: invoice list (filter type/status/source/customer/periode),
  detail (items table + payments timeline + linked subscription/spk +
  suspend/reactivate buttons for recurring), form (add line dynamic).
  Layanan mandiri pelanggan (daftar tagihan, detail, bayar online) pindah ke
  aplikasi Android; web hanya punya halaman return/finish Midtrans.
- [ ] UI pakai Components/ui + composite.
- [ ] Dark mode + responsive.

## Module Dependencies

- **Depends on (Phase 1):** Core (Company, User, Role, Permission,
  ActivityLog, Setting), Location (tidak langsung).
- **Depends on (Phase 2):** Customer/Subscription (shared Core —
  customer_id direct FK; subscription_id direct FK untuk recurring
  source; SubscriptionService::suspend/reactivate/activate called by
  SuspendSubscriptionAction/ReactivateSubscriptionAction. Keputusan E1:
  service_subscription = billing subscription, no separate Billing
  subscription entity).
- **Depends on (Phase 3):** Inventory (Product — invoice_items.product_id
  nullable FK untuk consumable line, direct FK seperti Product. IssueStockAction
  rare untuk invoice barang keluar).
- **Depends on (Phase 4):** SPK (work_order_id nullable FK — unique per
  SPK; CreateInvoiceFromSpkAction reads work_order + work_order_items untuk
  pre-fill OTC invoice. Dipanggil CompleteSpkAction).
- **Consumed by:** Reporting (revenue metrics dari invoices + subscription
  status).
- **Note E1:** ARCHITECTURE Section 3 + 12 previously mentioned
  `RecurringInvoiceSchedule` model — SUPERSEDED by E1 (no separate
  entity, recurring job baca service_subscriptions + dedup via invoice
  query). HAPUS dari ARCHITECTURE (Opsi A fix). DATABASE.md authoritative
  (no recurring_invoice_schedules table).

## Notifikasi WhatsApp (tagihan, pengingat, pembayaran)

Keputusan: `.scratch/whatsapp-notifications/spec.md`. Aturan umum (persetujuan customer, STOP, jam tenang, batas bulanan,
antrean `whatsapp`, webhook) ada di `docs/business/customer.md` § Notifikasi WhatsApp. Pesan hanya ke customer tagihan itu,
hanya bila `whatsapp_enabled` company aktif, eventnya dicentang, dan customer setuju (`whatsapp_opt_in_at`).

| Event (kunci) | Pemicu | Waktu kirim | Dedupe |
|---|---|---|---|
| `invoice_issued` | event `InvoiceIssued` setelah commit: batch `generateForPeriod` (invoice `sent`) dan `BillingService::send` (draft → sent). Tidak untuk draft, pembatalan, reversal. | batch: menunggu jam tenang selesai | `invoice_issued:{invoice}` |
| `invoice_reminder` | `billing:whatsapp-reminders` harian 01:00 UTC (08:00 WIB): H-3, H-1, H+1 | batch | `invoice_reminder:{invoice}:{H-3\|H-1\|H+1}:{due_date}` |
| `payment_received` | event `PaymentRecorded` setelah commit (staf maupun Midtrans), satu pesan per baris `payments` | segera | `payment_received:{payment}` |

- **Pengingat**: invoice `sent`/`partial`/`overdue` dengan `paid_amount < total` yang `due_date`-nya hari ini+3, hari ini+1 atau hari
  ini−1; "hari ini" dihitung di timezone company (run 17:30 UTC sudah hari berikutnya di WIB). `--company=ID` membatasi,
  `--dry-run` hanya menghitung. Satu company gagal tidak menghentikan company lain; admin diberi tahu lewat
  `BillingScheduleFailedNotification`. Invoice lunas/dibatalkan antara penjadwalan dan pengiriman tidak dikirimi (tanpa baris log).
- **Bel dan push**: pengingat yang sama juga masuk ke bel dan push aplikasi pelanggan (tipe tampilan `invoice_due_soon` untuk H-3 dan H-1, `invoice_overdue` untuk H+1). Menjalankan ulang `billing:whatsapp-reminders` di hari yang sama tidak membunyikan bel atau push dua kali: invoice dan tahap yang sudah punya baris bel `InvoiceDueReminderNotification` (`data.invoice_id` dan `data.stage`) dilewati. Pelanggan tanpa baris bel tetap dijaga kunci dedupe WhatsApp di atas. Batas: baris bel ditulis oleh antrean, jadi menjalankan ulang sebelum worker menulis baris pertama masih bisa mengirim dua kali.
- **Kegagalan per perusahaan**: perintah ini, seperti `billing:generate`, `billing:check-overdue`, dan `billing:suspend-overdue`, menangkap kegagalan per perusahaan, melaporkannya, melanjutkan ke perusahaan berikutnya, dan memberi tahu hanya admin aktif perusahaan yang gagal (`BillingScheduleFailedNotification`). Perintah mengembalikan `FAILURE` di akhir bila ada yang gagal.
- **Idempotensi**: kunci dedupe per invoice/tahap/jatuh tempo; menjalankan ulang perintah atau job tidak mengirim dua kali. Ubah
  jatuh tempo = pengingat baru.
- Pesan WhatsApp adalah efek samping: kegagalan mengantrekannya dilaporkan (`report()`), tidak menggagalkan generate invoice atau
  pembayaran yang sudah commit.
- Biaya: satu baris `whatsapp_messages` per percobaan (termasuk `skipped` dengan alasan). Company yang WhatsApp-nya mati tetap
  menulis baris `skipped: disabled`.

### Template Meta (kategori `utility`, bahasa `id`) — didaftarkan owner di WhatsApp Manager

Parameter positional `{{1}}`… urut seperti di `config/whatsapp.php`. Contoh nilai wajib diisi saat mendaftar.

`tagihan_terbit` — params: nama company, nomor tagihan, total, jatuh tempo, link aplikasi pelanggan (setting `play_store_url_customer`; kosong → halaman login)

```
Halo, tagihan layanan internet Anda dari {{1}} sudah terbit.
Nomor tagihan: {{2}}
Total: {{3}}
Jatuh tempo: {{4}}
Rincian tagihan: {{5}}
Terima kasih.
```
Contoh: `PT Contoh Net`, `INV-2026-00012`, `Rp 250.000`, `14 Okt 2026`, `https://play.google.com/store/apps/details?id=id.inbils.customer`

`tagihan_pengingat` — params: nama company, nomor tagihan, sisa tagihan, jatuh tempo, link aplikasi pelanggan (dipakai H-3, H-1, H+1)

```
Pengingat dari {{1}}: tagihan {{2}} dengan sisa pembayaran {{3}} jatuh tempo pada {{4}}.
Rincian tagihan: {{5}}
Abaikan pesan ini jika Anda sudah membayar.
```
Contoh: `PT Contoh Net`, `INV-2026-00012`, `Rp 250.000`, `14 Okt 2026`, `https://play.google.com/store/apps/details?id=id.inbils.customer`

`pembayaran_diterima` — params: nama company, nomor tagihan, jumlah dibayar, sisa tagihan, tanggal bayar (timezone company)

```
Pembayaran Anda kepada {{1}} sudah kami terima.
Tagihan: {{2}}
Jumlah dibayar: {{3}}
Sisa tagihan: {{4}}
Tanggal pembayaran: {{5}}
Terima kasih.
```
Contoh: `PT Contoh Net`, `INV-2026-00012`, `Rp 100.000`, `Rp 150.000`, `14 Okt 2026`

Tanpa kata promosi (diskon, promo, gratis): Meta mengubah template utility yang terdengar promosi menjadi kategori marketing
yang lebih mahal. Mengubah kata, urutan, atau jumlah parameter = template baru + persetujuan ulang + perubahan `config/whatsapp.php`.
