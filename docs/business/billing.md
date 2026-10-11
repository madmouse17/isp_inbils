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
| customer (portal pilot) | view own invoices + pay online (Midtrans sandbox pilot, lihat "Pembayaran online") |

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

### Recurring MRC generation (job bulanan)
- `GenerateRecurringInvoicesJob` (schedule: daily, run for subscriptions
  with `next_invoice_date <= today`):
  - Query active `service_subscriptions` (status=active, next_invoice_date
    <= today).
  - Dedup: cek invoice existing untuk (subscription_id, billing_period).
    Skip jika sudah ada.
  - Create Invoice type=recurring, source=subscription, customer_id,
    subscription_id, issue_date=today, due_date=today+default_due_days,
    billing_period = next_invoice_date month span.
  - InvoiceItem: description "MRC {package.name} {period}", quantity=1,
    unit_price=subscription.mrc_amount, tax_rate=Setting.
    line_total=mrc_amount.
  - subtotal + tax + total computed.
  - Status=draft (atau sent langsung jika Setting `billing.auto_send`).
  - Advance subscription.next_invoice_date += 1 month.
  - AuditLog.
- Run daily (bukan bulanan) untuk catch subscriptions dengan billing_day
  berbeda.

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

### Overdue + auto-suspend
- `CheckOverdueInvoicesJob` (daily): invoice status in (sent, partial) +
  due_date < today + paid < total → status=overdue + AuditLog.
- `CheckOverdueAndSuspendJob` (daily, opt-in Setting
  `billing.auto_suspend_enabled`): subscription dengan invoice overdue >
  `billing.auto_suspend_overdue_days` (default 7) →
  `SuspendSubscriptionAction` → subscription.status=suspended (stops
  recurring generation, flags NOC/ticketing) + AuditLog.

### Reactivate
- `ReactivateSubscriptionAction`:
  - Trigger: payment recorded untuk overdue invoice (auto if Setting
    `billing.auto_reactivate_on_payment`) OR manual.
  - subscription.status=active, next_invoice_date = next billing_day.
  - Resume recurring generation. AuditLog.

### Manual suspend/reactivate
- Admin/manager bisa manual suspend (reason) / reactivate subscription
  via SubscriptionService (Customer module shared). Billing UI host
  buttons.

### Tax (PPN)
- Default rate Setting `tax.ppn_rate` (11). Per invoice_item: tax_rate
  default = invoice rate, override per line.
- `tax_amount = sum(line_total * tax_rate / 100)`.
- `total = subtotal + tax_amount - discount_amount`.

### Number generation
- `INV-{YEAR}-{NNNNN}`. Lock + transaction per tahun.

### PDF
- Route `GET /admin/invoices/{invoice}/pdf`. View
  `resources/views/billing/invoice/pdf.blade.php` (A4, logo, period,
  items table, total). Policy `viewPdf`.

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
- `POST   /admin/invoices/{inv}/items` (add line)
- `PUT    /admin/invoices/{inv}/items/{item}` (update line, draft only)
- `DELETE /admin/invoices/{inv}/items/{item}` (remove line, draft only)
- `POST   /admin/invoices/{inv}/send`
- `POST   /admin/invoices/{inv}/payments` (record payment — trigger reactivate if auto)
- `POST   /admin/invoices/{inv}/cancel` (body: reason)
- `GET    /admin/invoices/{inv}/pdf`
- `POST   /admin/invoices/{inv}/create-from-spk` (manual trigger OTC from SPK, if not auto)
- `POST   /admin/subscriptions/{sub}/suspend` (body: reason — manual)
- `POST   /admin/subscriptions/{sub}/reactivate` (manual)
- `GET    /admin/invoices/export`
- `GET    /portal/invoices`, `GET /portal/invoices/{id}`, `POST /portal/invoices/{id}/pay`, `GET /portal/payments/return`, `GET|PUT /portal/password` (role customer, route name `portal.*`)
- `POST   /webhooks/midtrans` (publik, bertanda tangan Midtrans; lihat "Pembayaran online")

## Pembayaran online (Midtrans Snap, pilot sandbox)

Keputusan owner 2026-10-11 (`.scratch/midtrans-payment/spec.md`): **pilot
sandbox** (`MIDTRANS_IS_PRODUCTION=false`). Go-live produksi butuh persetujuan
terpisah: merchant asli, URL HTTPS publik untuk notifikasi, kunci produksi
hanya di host, dan security review yang ditandatangani. Satu merchant untuk
seluruh instalasi (kunci dari `.env`, dibaca hanya lewat
`config/services.php` blok `midtrans`).

Pengaman lingkungan: kunci sandbox hanya untuk host non-produksi. Pada host
`APP_ENV=production` dengan `MIDTRANS_IS_PRODUCTION=false` gateway **mati**
(Pay 404, webhook 404) kecuali owner menyetujui eksplisit lewat
`MIDTRANS_ALLOW_SANDBOX_IN_PRODUCTION=true` (sandbox di data produksi: pembayaran
uji tercatat sebagai pembayaran sungguhan pada invoice). `MIDTRANS_BASE_URL`
(mock E2E) hanya dihormati saat `APP_ENV` = `local`, `testing`, atau `e2e`.

### Alur
1. Pelanggan (role `customer` yang tertaut ke `customers.user_id`, permission
   `portal.invoice.view` / `portal.invoice.pay`) membuka `/portal/invoices`,
   membuka invoice `sent|partial|overdue`, menekan **Bayar**. Akun lama yang
   passwordnya nomor telepon ditandai `users.must_change_password` (migrasi
   backfill) dan **password lamanya dimatikan**: `customers:rotate-legacy-passwords`
   (langkah deploy) menggantinya dengan password acak 32 karakter dan
   mengantrekan link atur kata sandi. Selama bendera menyala semua halaman
   invoice mengarah ke `/portal/password`, yang hanya menjelaskan dan menawarkan
   "Kirim link baru" (throttle 3/menit). Bendera hanya dibersihkan oleh reset
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
`{company_id}-{invoice_id}-{12 acak}`), `amount`, `status`
(`pending|paid|failed|expired|exception`), `status_reason`, `snap_token` dan
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

### Rekonsiliasi
`php artisan midtrans:reconcile {--company=} {--dry-run}`, dijadwalkan tiap 15
menit (`withoutOverlapping`). Attempt `pending` lebih tua dari 10 menit
ditanyakan ke Status API per perusahaan aktif; jawabannya melewati
`applyNotification` yang sama. Midtrans tidak punya transaksi + halaman sudah
kedaluwarsa → `expired`. Gagal jaringan satu attempt tidak menghentikan
yang lain. Tanpa server key: keluar 0, tidak melakukan apa pun.

### Panel staf
Halaman invoice menampilkan panel read-only "Online payments" (status,
jumlah, transaction id, jenis pembayaran, alasan exception). Tidak ada aksi
yang bisa menandai attempt lunas.

### Deploy dan rollback
- `php artisan migrate`; `php artisan db:seed --class=RolePermissionSeeder --force`;
  **`php artisan customers:rotate-legacy-passwords --dry-run`, lalu tanpa
  `--dry-run`** (sekali: mematikan password nomor telepon akun lama dan
  mengantrekan link atur kata sandi; butuh mail dan queue worker berjalan; aman
  diulang, hanya akun yang belum menyelesaikan reset yang diproses lagi dan
  dikirimi link baru); isi `MIDTRANS_MERCHANT_ID`, `MIDTRANS_CLIENT_KEY`,
  `MIDTRANS_SERVER_KEY`, `MIDTRANS_IS_PRODUCTION=false` hanya di host
  non-produksi (host `APP_ENV=production` butuh kunci produksi, atau
  `MIDTRANS_ALLOW_SANDBOX_IN_PRODUCTION=true` yang disetujui owner); set
  "Payment Notification URL" di dashboard Midtrans ke
  `https://<host>/webhooks/midtrans`, **atau** isi `MIDTRANS_NOTIFICATION_URL`
  dengan URL HTTPS itu (dikirim sebagai header `X-Override-Notification` di tiap
  order Snap, menimpa setelan dashboard; URL non-HTTPS diabaikan); pastikan
  scheduler jalan.
- Rollback migrasi: `payment_attempts` bisa dihapus; `payments.method`
  kembali ke empat nilai dan `payments.received_by` kembali NOT NULL **hanya
  jika tidak ada pembayaran `midtrans`/tanpa penerima** (migrasi menolak dan
  menjelaskan; ledger immutable).

## Testing Scenario

### Recurring MRC generation
1. Subscription active, billing_day=5, next_invoice_date=2026-07-05,
   mrc_amount=250000.
2. Run GenerateRecurringInvoicesJob (2026-07-05): create invoice
   type=recurring, source=subscription, billing_period=2026-07-01 to
   2026-07-31, line MRC 250000, tax 11% (27500), total 277500. Status
   draft. next_invoice_date=2026-08-05.
3. Run again same day → dedup (invoice exists for subscription+period)
   → skip. No duplicate.
4. Subscription suspended → job skip (status != active).

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
2. Invoice overdue (due_date yesterday, paid < total). Pay rest →
   status=paid. If auto_reactivate_on_payment + subscription was
   suspended → ReactivateSubscriptionAction → subscription active.

### Overdue + auto-suspend
1. Invoice recurring, due_date=now-10days, unpaid. status=overdue.
2. CheckOverdueAndSuspendJob (auto_suspend_enabled, threshold 7 days):
   SuspendSubscriptionAction → subscription.status=suspended. Recurring
   job skip subscription.
3. Pay overdue invoice (auto_reactivate) → subscription active.

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
  suspend/reactivate buttons for recurring), form (add line dynamic),
  portal pelanggan (invoice list, detail, bayar online, status pembayaran).
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
