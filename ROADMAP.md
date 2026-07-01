# inbils — Roadmap

> Status: DRAFT v2 (ISP pivot 2026-06-30). 8 Phase. Approval baginda =
> gate sebelum Phase 1 eksekusi.

## Prinsip

- Satu Phase selesai + QA pass → baru Phase berikutnya mulai.
- Dalam Phase: task berurutan via TASKS.md, OpenCode eksekusi satu per
  satu, Hermes review antar task.
- Setiap Phase = milestone git tag `v0.{phase}.0` (sebelum v1.0.0 final).
- Cross-phase dependency WAJIB (Phase 3 butuh Phase 1+2 selesai).

## Phase 0 — Foundation (sudah selesai, tidak ada eksekusi)

- Laravel 12 + Breeze (Inertia/React/TS) scaffold ✓
- 28 ui/ primitives + AdminLayout + Components gallery ✓
- Dark mode `class` strategy ✓
- Build clean ✓
- DB MySQL Laragon + seeder admin@inbils.test ✓ (akan dihapus Phase 1 —
  init rule: no default company/user data, lihat COMPANY_PROFILE.md 1.1)

## Phase 1 — Core (Company + Setup Wizard + Auth ext + User + Role + Permission + Audit + Location topology + Setting)

**Deliverable:**
- spatie/laravel-permission terpasang + migration publish + seed roles/
  permissions sesuai matrix SECURITY.md (P1-01). ~80+ permissions (8
  module × ~10).
- **Company system (P1-02):** `companies` table + `Company` model +
  trait `BelongsToCompany` (global scope + auto-set company_id) +
  `CompanyService` (current/setting/updateProfile/updateSettings) +
  `users.company_id` column. Lihat `docs/COMPANY_PROFILE.md`.
- **System setting (P1-02):** `settings` table (system-global key-value) +
  `Setting` model + `SettingService` (get/set/flush cache) +
  `SystemSettingSeeder` (defaults: app.name, default_currency,
  default_timezone, default_tax_ppn_rate, registration_disabled, dll).
  Dua-layer: CompanyService::setting fallback SettingService::get.
  Initialization rule: TIDAK seed company data, hanya roles + permissions
  + system setting. Lihat COMPANY_PROFILE.md 1.1.
- **Setup Wizard (P1-03):** `php artisan inbils:setup` bootstrap command
  + 3 middleware (RedirectIfNoCompany, RequireNoCompany, RequireHasCompany)
  + wizard 4-step (Company info → System config → Initial admin →
  Confirmation) + `SetupWizardService::create()` DB transaction +
  `Setup/Wizard.tsx` frontend.
- spatie/laravel-activitylog terpasang + migration (properties json) +
  trait di User/Company + `AuditService` wrapper (P1-04).
- **Location topology (P1-07):** `locations` table (topology hierarchy
  region/area/pop/rack/site, materialized path) + `Location` model
  (app/Models/Core/Location.php) + `LocationService` (topology CRUD,
  path materialized, cycle prevention, recurse on move/rename) +
  `LocationPolicy` + `StoreLocationRequest`/`UpdateLocationRequest` +
  `LocationResource` + route `/admin/locations` (tree + move). Shared
  Core (A1) — customer.md Phase 2 butuh, network-asset.md Phase 3 butuh,
  inventory.md Phase 3 butuh. Phase 1 eksekusi (sebelum Phase 2 Customer).
- **Seed master defaults (P1-08):** `CompanySeeder::runFor($company)`
  dipanggil CompanyCreated event atau wizard Step 4. Seed: default units
  (pcs/meter/roll/box), ticket_categories ISP (no_internet=4h urgent,
  slow_connection=8h high, packet_loss=8h high, device_issue=12h medium,
  fiber_issue=12h high), sla_tiers sample (Bronze/Silver/Gold), locations
  sample (region/area/pop). Per-company (company_id).
- Model: Company, User (extend: is_active, last_login_at, company_id),
  Setting (system-global key-value), Role, Permission (dari spatie),
  ActivityLog (dari spatie), Location (topology). Dua-layer config:
  `companies.settings` json (company) + `settings` table (system default,
  seeded).
- Controller: DashboardController, CompanyController (profile+settings),
  UserController, RoleController, PermissionController, SetupWizardController,
  LocationController, SystemSettingController.
- Service: Core\\CompanyService, Core\\SettingService, Core\\SetupWizardService,
  Core\\AuditService, Core\\UserService, Core\\LocationService.
- Policy: CompanyPolicy, UserPolicy, RolePolicy, LocationPolicy.
- FormRequest: StoreCompanySetupRequest, UpdateCompanyProfileRequest,
  UpdateCompanySettingsRequest, Store/UpdateUserRequest, Store/UpdateRoleRequest,
  Store/UpdateLocationRequest.
- Resource: CompanyResource, UserResource, RoleResource, PermissionResource,
  LocationResource.
- Route: `routes/admin.php` (baru, middleware RequireHasCompany),
  `routes/web.php` `/setup` group (middleware RequireNoCompany).
- Inertia shared props: auth.user + roles + permissions + company, flash,
  app.
- Frontend:
  - `resources/js/hooks/usePermission.ts`, `useCompany.ts`
  - `resources/js/lib/format.ts` (formatRupiah, formatDate — baca company
    settings dinamis)
  - `resources/js/Components/composite/` (PageHeader, DataTable, FormField,
    StatusBadge, MoneyInput, DateRangeFilter).
  - Pages: `Setup/Wizard.tsx`, `Admin/Dashboard/Index.tsx`,
    `Admin/Company/Profile.tsx`, `Admin/Company/Settings.tsx`,
    `Admin/Users/*`, `Admin/Roles/*`, `Admin/Permissions/*`,
    `Admin/Locations/*` (tree view).
- Seeder: RolePermissionSeeder only (di DatabaseSeeder). TIDAK seed
  user/company (bootstrap command + wizard). CompanySeeder::runFor
  dipanggil wizard Step 4.
- Test: feature test Company/Setup Wizard/User/Role/Location CRUD +
  authorization + audit log + cross-tenant isolation.

**Exit criteria:**
- `php artisan migrate:fresh --seed` + `php artisan inbils:setup` →
  bootstrap user. Login → wizard → company created + admin role assigned
  + master defaults seeded.
- Login sebagai admin/manager/staff/technician → dashboard + menu sesuai
  permission. Topbar display company name/logo dinamis.
- CRUD user + assign role → activity log tercatat.
- Company profile + settings edit + save → CompanyService cache flush +
  apply.
- Setup wizard one-shot (re-visit /setup → 403).
- Location topology CRUD (region/area/pop/rack/site) + path materialized
  + cycle prevention + recurse on move/rename.
- Cross-tenant: user TIDAK bisa lihat data company lain (CompanyScope
  trait tested).
- QA-ISP pass.

## Phase 2 — Master Data (Customer + Service Catalog + composite scaffolding finalisasi)

**Deliverable:**
- maatwebsite/excel terpasang.
- Model: Customer, CustomerAddress, CustomerContact, ServiceSubscription
  (shared Core, app/Models/Core/). ServicePackage, BandwidthProfile,
  SpeedProfile, SLATier (Modules/Service/).
- Controller + Service + Policy + FormRequest + Resource per entity.
  SubscriptionService (app/Services/Core/) untuk lifecycle.
- Frontend: Pages `Admin/Customers/*`, `Admin/Service/*`.
- Composite: finalisasi DataTable (sort/filter/pagination), FormField
  (label+input+error), PageHeader (title+actions+breadcrumb),
  StatusBadge, MoneyInput, DateRangeFilter.
- Export Excel: Customer, ServicePackage.
- Factory + seeder (20 customer: 12 Individual + 8 Company, 30
  subscription: 20 active, 5 suspended, 3 pending, 2 terminated; 5
  bandwidth, 5 speed, 4 sla tier, 8 package).
- Test: CRUD + authorization + export + subscription lifecycle.

**Dependency:** Phase 1 (user/role/permission + composite primitives +
Location topology).

**Exit criteria:**
- CRUD customer + address + contact + subscription sesuai.
- Subscription lifecycle (pending→active→suspended→active→terminated)
  dengan side effect benar (ont release, recurring stop/resume).
- Max 1 installation_point per customer (app guard).
- Suspend/reactivate trigger dari billing job (auto) + manual.
- Soft delete customer restrict jika active subscription.
- Pricing snapshot di subscription (package price change tidak affect
  existing).
- Composite reusable untuk Phase 3-8.
- QA-ISP pass.

## Phase 3 — Inventory + NetworkAsset (Product + Category + Unit + Stock + StockMovement + NetworkAsset + NetworkAssetInstallation + Location topology CRUD finalisasi)

**Deliverable:** lihat `docs/modules/inventory.md` + `docs/modules/network-asset.md`.
Module: `Modules/Inventory/` + `Modules/NetworkAsset/` (nwidart/laravel-modules).

**Dependency:** Phase 1 (Core + Location) + Phase 2 (Customer/Subscription
shared Core).

**Exit criteria:**
- CRUD Product + Category + Unit. Product = consumable flat (NO
  Variant/Attribute/Serial — B1: Serial merged ke NetworkAsset).
- StockMovement immutable, 7 types (receive/issue/transfer/adjustment/
  reserve/release/return), balance_after + reserved_after dihitung
  service.
- Reserve/release = reserved_quantity (no qty change), SPK consume = issue.
- Stock constraint DB (qty >= 0, reserved <= qty) tested.
- Multi-location + transfer (2 row) berfungsi.
- Low stock badge + dashboard widget.
- NetworkAsset CRUD + status lifecycle (available→installed→maintenance→
  damaged→retired) + NetworkAssetInstallation append-only history.
- serial_number unique per company.
- Location topology CRUD finalisasi (Phase 1 built, Phase 3 extend
  dengan asset/stock placement validation).
- Trace endpoint: search by serial/mac/ip/customer/subscription →
  return asset + location path + status + links.
- Export Excel + stock card PDF.
- Module `Modules/Inventory/` + `Modules/NetworkAsset/` terstruktur,
  no logic di `app/`.
- QA-ISP pass.

## Phase 4 — SPK (Surat Perintah Kerja — 8 state ISP)

**Deliverable:** lihat `docs/modules/spk.md`.
Module: `Modules/SPK/` (nwidart).

**Dependency:** Phase 1 (Core + Location) + Phase 2 (Customer/Service) +
Phase 3 (Inventory + NetworkAsset).

**Exit criteria:**
- 4 type SPK (installation/maintenance/upgrade_service/relocation)
  dengan type-specific CompleteSpkAction behavior.
- Status state machine 8 status (draft→generated→assigned→
  in_progress→waiting_review→completed, rejected, cancelled).
- Assignment: system suggest (workload+skill+availability) + Kepala
  Unit final assign + WorkOrderAssignment append-only history (re-assign
  tracked). NO WorkOrderStatus table (replaced by assignment history +
  activity_log).
- Evidence upload wajib sebelum submit (app guard).
- CompleteSpkAction orchestrator: IssueStockAction + InstallNetworkAssetAction
  + SubscriptionService::activate + CreateInvoiceFromSpkAction (if
  installation + auto_invoice).
- SPK generation auto dari ticket (maintenance) + subscription
  (installation).
- Reserve/release/consume stok sesuai mode.
- Code generation unique + race-safe (SPK-{YEAR}-{NNNNN}).
- PDF cetak SPK.
- QA-ISP pass.

## Phase 5 — Billing (Invoice + InvoiceItem + Payment + Recurring MRC + OTC + Suspend/Reactivate + Tax + PDF)

**Deliverable:** lihat `docs/modules/billing.md`.
Module: `Modules/Billing/` (nwidart).

**Dependency:** Phase 1 (Core) + Phase 2 (Customer/Service) + Phase 3
(Inventory — Product) + Phase 4 (SPK — from-SPK one-time invoice).

**Exit criteria:**
- Recurring MRC job bulanan (daily schedule, catch billing_day berbeda)
  + dedup (no duplicate per subscription+period). E1: service_subscription
  = billing subscription, NO RecurringInvoiceSchedule entity.
- One-time OTC from SPK (CreateInvoiceFromSpkAction, unique per SPK).
- State machine (draft→sent→partial→paid, overdue, cancelled).
- Payment immutable (no delete, cancel+reverse only). Overpay validation.
- Overdue job harian + auto-suspend job (opt-in, threshold days).
- Suspend/reactivate (auto from payment/overdue + manual) via
  SubscriptionService.
- Tax calculation per line + total (PPN 11% default).
- Number generation unique + race-safe (INV-{YEAR}-{NNNNN}).
- PDF faktur (A4, period for recurring, items, total).
- barryvdh/laravel-dompdf terpasang.
- QA-ISP pass.

## Phase 6 — Ticketing (Ticket + Comment + Attachment + SLA + Spawn SPK — 5 state ISP)

**Deliverable:** lihat `docs/modules/ticketing.md`.
Module: `Modules/Ticketing/` (nwidart).

**Dependency:** Phase 1 (Core + Location) + Phase 2 (Customer/Subscription) +
Phase 3 (NetworkAsset) + Phase 4 (SPK — spawn/link).

**Exit criteria:**
- 3 source (customer/noc/internal) + 5 kategori ISP (no_internet/
  slow_connection/packet_loss/device_issue/fiber_issue) + custom (v2).
- Status state machine 5 status (open→assigned→on_progress→resolved→
  closed). `on_progress` bukan `in_progress`.
- SLA: deadline per category, breach detection (job + badge),
  resolution_time + FRT tracking. v1: SLA breach = reporting metric +
  flag. Billing credit v2.
- Auto-routing suggest (category + location + asset → ranked handler).
- Spawn SPK from ticket (SpawnSpkFromTicketAction, backlink).
- Link customer/subscription/asset/location (trace 1-klik).
- Comment internal/public + attachment (whitelist mime, regenerate
  filename, Policy download).
- Code generation unique + race-safe (TKT-{YEAR}-{NNNNN}).
- Soft delete closed only.
- Export Excel.
- v2 hook: customer role permission seeded (inactive).
- QA-ISP pass.

## Phase 7 — Performance (EmployeeEvaluation — cross-cutting write entity)

**Deliverable:** lihat `docs/modules/performance.md`.
Lokasi: app/Core (BUKAN module — cross-cutting write entity, dipakai
Reporting + SPK + Ticketing). Keputusan D1: performance = WRITE entity
(evaluation); reporting = READ-ONLY module.

**Dependency:** Phase 1 (Core) + Phase 4 (SPK) + Phase 6 (Ticketing).

**Exit criteria:**
- CRUD evaluation sesuai.
- Polymorphic reference WorkOrder/Ticket (app-level validate exists).
- Score + customer_rating range 1.0-5.0 (DB check).
- Unique per (reference, evaluator) — prevent duplicate.
- Snapshot FRT/resolution_minutes (historically stable).
- Soft delete.
- Policy per aksi (technician own-view, Kepala Unit bawahan, customer
  v2 own-ticket).
- QA-ISP pass.

## Phase 8 — Reporting (cross-modul read-only — 7 report types)

**Deliverable:** lihat `docs/modules/reporting.md`.
Module: `Modules/Reporting/` (nwidart, read-only exception — query all
model directly, documented).

**Dependency:** semua Phase 1-7.

**Exit criteria:**
- 7 report types: Stock Card, SPK Completed, Sales (invoice paid),
  Ticket Stats, Technician Performance, Asset Utilization, Audit Log.
- AuditLog report cross-tenant filter (D-R11): AuditLogQuery join
  subject table + filter `subject.company_id`. Test cross-tenant.
- Filter: periode (DateRangeFilter), per-customer, per-technician,
  per-location.
- Export Excel (per report).
- Dashboard widget diperluas: chart (sales trend, ticket by status, spk
  per technician, SLA compliance).
- recharts terpasang.
- QA-ISP pass.

## v1.0.0 — Release

- Semua Phase 1-8 selesai + QA pass.
- Production readiness: `APP_DEBUG=false`, `APP_ENV=production`, HTTPS,
  strong DB password, `route:cache`, `config:cache`, `view:cache`.
- README.md update (install + deploy).
- Tag `v1.0.0`.

## Estimasi (rough, kalibrasi setelah Phase 1)

| Phase | Estimasi | Catatan |
|-------|----------|---------|
| 1 | 7-9 task | Core + composite + Location topology + seed defaults |
| 2 | 6-8 task | Customer + Service catalog + composite finalisasi |
| 3 | 8-10 task | Inventory + NetworkAsset + stock logic |
| 4 | 6-8 task | SPK 8 state + cross-trigger + CompleteSpkAction |
| 5 | 6-8 task | Billing recurring + OTC + suspend/reactivate + tax |
| 6 | 6-8 task | Ticketing 5 state + SLA + attachment + spawn SPK |
| 7 | 4-6 task | Performance evaluation + snapshot |
| 8 | 4-6 task | Reporting 7 types + chart + audit log cross-tenant |
| Total | ~48-56 task | OpenCode eksekusi per task |

Estimasi waktu = fungsi throughput OpenCode (model health per MEMORY.md:
hanya `router9/ali/qwen3.7-max` works, agent laravel-senior). Kalibrasi
setelah task P1-01 selesai + ukur waktu real.
