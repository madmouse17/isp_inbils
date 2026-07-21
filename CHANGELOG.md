# Changelog

Semua perubahan notable di project inbils. Format [Keep a Changelog](https://keepachangelog.com/id/1.1.0/),
versi mengikuti [Semantic Versioning](https://semver.org/lang/id/) (v0.x.x
selama development, v1.0.0 saat release).

## [Unreleased]

### Fixed — inventory unit ownership on category (2026-07-21)

- Category form now requires and displays `unit_id`.
- Product create/edit unit field is read-only and inherits from selected category.
- Category unit change cascades to existing products.
- Product factory inherits unit from category.
- Additive migration hardens `categories.unit_id` (backfill + NOT NULL). `products.unit_id` kept.
- Company/E2E seeders seed category unit ownership.

### Changed â€” t_25cf4d0b: deterministic PHP test topology (2026-07-15)

- Added a PHPUnit Modules suite plus focused topology regression so root and module tests are discovered by default.
- Re-included readiness evidence documents after the repository-wide `docs/*` ignore rule and recorded dirty-tree inventory plus shared-DB verification boundary.

### Fixed â€” t_f519540a: P0 QA critical fixes (2026-07-14)

- Added scoped E2E coverage for the missing Ziggy registration-route regression with Chromium Playwright harness, destructive guard plus isolated built-assets lifecycle, and browser journeys including stock receive and network asset related navigation.
- Disabled public registration and locked stock access behind seeded permissions.
- Hardened stock integrity with non-negative quantities, reservation bounds, and scoped idempotency.
- Locked payment reconciliation with per-company reference uniqueness.
- Enforced tenant boundaries for admin routes and component gallery access.
- Orchestrated SPK completion across stock, asset, subscription, and invoice updates.
- Fixed subscription code generation to use current company context and keep codes tenant-scoped.

### Changed â€” t_4e61368f: permissions paging and grouped role permissions (2026-07-09)

- Paginated admin permissions server-side while keeping permission group metadata.
- Added focused coverage for paginated permissions and grouped role edit permission payloads.

### Changed â€” t_aa41ccea: admin sidebar and profile topbar UX (2026-07-09)

- Grouped admin sidebar navigation into dashboard, admin, operations, service, inventory, work order, finance/report, and developer sections.
- Added admin topbar profile link and logout action using existing Breeze `profile.edit` and `logout` routes.

### Fixed â€” t_44af8fb7: admin menu permission alignment (2026-07-09)

- Aligned inventory stock, stock movement, Item Finder, and report child pages to seeded read permissions.
- Added Item Finder sidebar navigation and focused authorization coverage for admin menu destinations.

### Phase 1 â€” Core Complete (2026-06-30)

- P1-00 â€” Installed `nwidart/laravel-modules` and generated 8 module stubs.
- P1-01 â€” Installed permission and activity log packages, published migrations, seeded roles and permissions.
- P1-02 â€” Added company, settings, company scope, company service, and system setting defaults.
- P1-03 â€” Added setup wizard, bootstrap command, middleware, and first company creation flow.
- P1-04 â€” Added audit service and core activity logging.
- P1-05 â€” Added company profile/settings, user, role, and permission admin UI.
- P1-06 â€” Added admin route wiring, shared Inertia props, sidebar, middleware registration, and hooks.
- P1-07 â€” Added location topology CRUD and service layer.
- P1-08 â€” Added per-company default seeder, `CompanyCreated` event, and default location seeding.
- P1-09 â€” Added composite UI components, `useInertiaForm`, and formatting helpers.
- P1-10 â€” Added dashboard controller, real dashboard widgets, module placeholders, and final Phase 1 changelog.

### Added â€” P1-00: nwidart/laravel-modules + 8 module stubs (2026-06-30)

- `composer require nwidart/laravel-modules` (v12).
- `config/modules.php` published.
- 8 module stub generated: Customer, Service, NetworkAsset, Inventory,
  SPK, Billing, Ticketing, Reporting.
- `module.json` dependencies per D-C2:
  - Customer=[], Service=[], NetworkAsset=[], Inventory=[]
  - SPK=[Inventory, NetworkAsset]
  - Billing=[Inventory, SPK, Service]
  - Ticketing=[SPK, NetworkAsset]
  - Reporting=[]
- Scaffold: Config/Console/Entities/Database/Http/Models/Services/Actions/
  Policies/Resources/Providers/Tests/Routes (nwidart default).
- `Modules/*/Routes/web.php` stub (empty group prefix /admin).
- `php artisan module:list` â†’ 8 enabled.
- `php artisan route:list` â†’ 65 routes, no error.
- `composer.json` allow-plugins: wikimedia/composer-merge-plugin=true (fix
  blocked plugin issue).
- Shared Core (app/Models/Core/) tetap â€” tidak terkena.

### Changed â€” Architecture Review Fixes â€” Opsi A (2026-06-30)

Eksekusi semua perubahan PRIORITAS 1 (Critical) + PRIORITAS 2 (High)
dari `docs/ARCHITECTURE_REVIEW.md` sebelum Phase 1. 9 batch (B1-B9)
doc consistency fixes. Executor: Hermes langsung (doc authoring = architect
work, NOT OpenCode).

- `docs/WORKFLOW.md` â€” Rewrite total: 11 section ISP (Core, Customer,
  Service, NetworkAsset, Inventory, SPK 8-state, Billing recurring+OTC,
  Ticketing 5-state on_progress+SLA, Performance, Reporting, Notification).
  Section numbering match module docs.
- `docs/API.md` â€” Rewrite total: section per module (Customer Â§2, Service
  Â§3, NetworkAsset Â§4, Inventory Â§5, SPK Â§6, Billing Â§7, Ticketing Â§8,
  Performance Â§9, Reporting Â§10). Route ISP-correct. Hapus Item/Warehouse.
- `ROADMAP.md` â€” Rewrite Phase 2-8: 8 phases (Phase 1 Core+Location,
  Phase 2 Customer+Service, Phase 3 Inventory+NetworkAsset, Phase 4 SPK,
  Phase 5 Billing, Phase 6 Ticketing, Phase 7 Performance, Phase 8
  Reporting). Location topology di Phase 1. Dependency ISP.
- `docs/ARCHITECTURE.md` â€” Hapus RecurringInvoiceSchedule (E1). Document
  exception ont_asset_id FK (D-R5). Soft delete list complete ISP.
  Folder structure Config/Console/Entities/Providers. Itemâ†’Product.
  module.json dependencies (D-C2).
- `docs/COMPANY_PROFILE.md` â€” CompanyScope list ISP (28 model). itemsâ†’
  products. Phase 8 confirm.
- `docs/SECURITY.md` â€” Role matrix expand 8 module (~80+ permissions).
  LogsActivity list ISP. itemsâ†’products. AuditLogQuery cross-tenant
  filter (D-R11).
- `docs/DATABASE.md` â€” movement_type verb form (D-H1). Ticketing Phase 6.
  Migration order SPKâ†’Billingâ†’Ticketing. Location type CHECK app-level
  (D-R10).
- `TASKS.md` â€” P1-00 8 module stub (D-C2). P1-01 ~80+ permissions.
  P1-07 Location topology (new). P1-08 Seed master defaults (new).
  P1-09 composite (renumber). P1-10 dashboard (renumber). æå‰â†’lebih awal.
- `docs/VISION.md` â€” Section 8 open questions RESOLVED (D-H3). Phase 1-8.
- `docs/TECH_STACK.md` â€” PDF Phase 4+5. Phase 1-8.
- `docs/LIBRARY_DECISION.md` â€” ItemImport/Exportâ†’Product.
- `docs/modules/*.md` â€” movement_type verb form. serial_number removed.
  sla_tiersâ†’ticket_categories FK claim removed. Billing SLA credit
  claim removed (v1 no credit). ä¼˜åŒ–â†’optimasi. seperti Itemâ†’Product.
  Cross-ref WORKFLOW Â§ + API Â§ updated. Ticketing Phase 6.

### Added â€” Company Configuration System (2026-06-30)

Sistem konfigurasi company dinamis + Setup Wizard + multi-tenant ready.
Di-request baginda, diintegrasikan ke seluruh blueprint.

- `docs/COMPANY_PROFILE.md` (NEW, source of truth) â€” Company Configuration
  Design (companies table + settings json), Setup Wizard Flow (4 step +
  bootstrap command + middleware), Tenant Strategy (v1 single, v2 multi
  hooks ready).
- `docs/ARCHITECTURE.md` â€” Section 11 (Company & Multi-Tenant): folder
  structure + CompanyScope trait + wizard request flow + tenant strategy.
  Frontend Pages: `Company/` + `Setup/Wizard.tsx`.
- `docs/DATABASE.md` â€” `companies` table + `users.company_id` + company_id
  di semua tabel master/transaksi (customers/units/categories/warehouses/
  items/stocks/stock_movements/work_orders/work_order_items/work_order_
  statuses/invoices/invoice_items/payments/ticket_categories/tickets/
  ticket_comments/ticket_attachments) + unique index per-company
  `(company_id, code)` + trait `BelongsToCompany`. ERD update (companies
  root). Dua-layer config: `settings` table (system-global, seeded saat
  install) + `companies.settings` json (company-specific, wizard isi).
  Initialization rule: TIDAK seed company data â€” seeder hanya system roles
  + permission template + system setting defaults. Company dibuat wizard.
- `docs/SECURITY.md` â€” Section 2 Company Scope (RBAC + data isolation) +
  Section 14 Multi-Tenant Isolation (CompanyScope, wizard authorization,
  cross-tenant leak prevention, v2 activation security). Checklist +
  company items.
- `docs/WORKFLOW.md` â€” Section 1 rewrite: Setup Wizard flow (bootstrap
  command â†’ login â†’ wizard â†’ company created) + Company profile/settings
  edit + User/Role rules update. Cross-modul matrix: Company settings
  baca semua modul.
- `docs/VISION.md` â€” In-scope: Company configuration dinamis. Out-of-scope:
  multi-company formal = v2 (v1 single, schema ready). Asumsi:
  single-company v1 multi-company ready. Open question (d) refine.
- `TASKS.md` â€” Phase 1 rewrite: 8 task (P1-01..P1-08). P1-01 = Install
  permission+activitylog. P1-02 = Company + CompanyScope + CompanyService
  + Setting (system) + SettingService + SystemSettingSeeder (NEW). P1-03 = Setup Wizard (bootstrap cmd + middleware
  + service + Wizard.tsx) (NEW). P1-04 = AuditService full. P1-05 =
  Company profile/settings UI + User/Role/Permission UI. P1-06 = Route
  admin.php + Inertia shared + sidebar + middleware wiring. P1-07 =
  Composite UI. P1-08 = Dashboard + finalisasi seed + CHANGELOG. Setting
  task lama dihapus (merged ke Company).
- `ROADMAP.md` â€” Phase 1 deliverable: Company system + Setup Wizard +
  bootstrap command. Exit criteria: wizard one-shot + cross-tenant
  isolation tested.
- `AGENTS.md` â€” Company Rules section (MANDATORY): trait BelongsToCompany
  wajib, company_id + unique per-company di migration, CompanyService
  baca identity (no hardcode), wizard flow wajib, Breeze register
  disabled, company config = companies.settings json. Folder structure:
  Models/Core + Traits, Pages Company/ + Setup/. Login section: bootstrap
  + wizard (no seeded admin).
- QA-ISP profile Hermes dibuat (clone isp, model glm-5.2, SOUL.md =
  no-code reviewer).

### Added â€” Modular Architecture + Inventory Redesign (2026-06-30)

Dua direktif besar baginda terintegrasi penuh ke blueprint.

**Modular Architecture (nwidart/laravel-modules):**
- Domain logic (Inventory/SPK/Billing/Ticketing) â†’ `Modules/{Name}/`,
  BUKAN `app/`. Shared Core (Company/User/Auth/Audit/Setting/Customer)
  tetap `app/`.
- Module structure: Config/Database/Entities/Http/Models/Services/Actions/
  Policies/Resources/Tests/Routes. `module.json` dependencies key.
- Module TIDAK coupling langsung â€” komunikasi via Service/Action/Event.
- Inventory â†’ SPK/Billing via polymorphic reference (no FK hard-coupling).
- Dokumentasi: `docs/ARCHITECTURE.md` Section 12 + `AGENTS.md` Modular
  Architecture Rules + folder structure update.

**Inventory Redesign (dynamic category/attribute/serial/stock movement):**
- Domain modeling rule: entity dynamic â†’ configurable master data, NO
  hardcode kategori bisnis.
- Dynamic category + attribute: `categories` â†’ `attribute_definitions`
  â†’ `product_attributes`. NO kolom per-kategori (NO router_port/router_ram).
- Product + ProductVariant + ItemSerial. `tracking_type`: serial/quantity/
  both.
- Stock movement immutable, 5 types: receive/issue/transfer/adjustment/
  return. JANGAN update stock langsung, semua via StockMovement.
- Reserve/release = `stocks.reserved_quantity` (SPK hold, NO movement).
- `items` table â†’ `products` (rename, akurat semantik).
- Dokumentasi: `docs/modules/inventory.md` rewrite (17.5KB) +
  `docs/DATABASE.md` Section 4 redesigned.

**Open question baru:** (f) nwidart install timing â€” Phase 1 awal (P1-00)
atau P0.5. (g) itemsâ†’products rename confirmed. (h) attribute type enum
6 type.

### Added â€” Blueprint & Documentation (2026-06-30)

Blueprint sistem lengkap dibuat oleh Senior Software Architect (Hermes),
menunggu approval baginda sebelum eksekusi Phase 1.

- `docs/VISION.md` â€” tujuan produk, user role, lingkup, success criteria,
  asumsi, open questions.
- `docs/ARCHITECTURE.md` â€” layered backend, Inertia-as-Island decision
  (konflik React Island vs Inertia flagged untuk approval), struktur
  folder backend + frontend, request flow, state management, naming
  convention, dependency rules, transaksi & integritas.
- `docs/DATABASE.md` â€” konvensi schema, ERD tekstual, tabel Core +
  Inventory + SPK + Billing + Ticketing, index strategy, seed strategy.
- `docs/API.md` â€” routing layout (routes/admin.php), Inertia response
  shape, pagination, file upload, export/PDF route, konvensi API publik
  v2.
- `docs/SECURITY.md` â€” auth, RBAC role matrix, validation, CSRF, SQL
  injection, XSS, file upload security, audit log, secrets, session,
  logging, PDP law, security checklist per task.
- `docs/WORKFLOW.md` â€” state machine per modul + cross-modul trigger
  (SPKâ†’Billing, Ticketâ†’SPK, Stock reserve/consume/release).
- `docs/TECH_STACK.md` â€” stack inti (existing), stack tambahan (TODO
  install), yang tidak dipakai + alasan, dev tooling, environment.
- `docs/LIBRARY_DECISION.md` â€” 10 kategori (Auth, Permission, Audit,
  API, Queue, Storage, Import/Export, PDF, Testing, UI) dengan analisa
  fungsi/masalah/alternatif/kelebihan/risiko/alasan pilih.
- `docs/modules/inventory.md` â€” spec modul Inventory (tujuan, role,
  entity, relation, workflow, permission, API, testing, acceptance).
- `docs/modules/spk.md` â€” spec modul SPK.
- `docs/modules/billing.md` â€” spec modul Billing.
- `docs/modules/ticketing.md` â€” spec modul Ticketing.
- `ROADMAP.md` â€” 7 Phase (Core, Master Data, Inventory, SPK, Billing,
  Ticketing, Report) + estimasi.
- `TASKS.md` â€” Phase 1 task list (7 task: P1-01 sampai P1-07) untuk
  OpenCode.
- `AGENTS.md` â€” extend dari UI-only ke full project guide (role split,
  architecture rules, UI convention retained, dev commands, OpenCode
  rules, QA rules).

### Keputusan Teknis Pending Approval

1. **React Island vs Inertia** (`docs/ARCHITECTURE.md` Section 2):
   rekomendasi A (pertahankan Inertia existing), alternatif B (rewrite
   pure Blade+React island, +2 minggu). Default dokumen = A.
2. **Open questions VISION.md Section 8** (6 pertanyaan bisnis: SPKâ†”Billing
   otomatis, stok keluar saat apa, customer portal, multi-cabang, PPN,
   SLA). Multi-cabang (d) sudah di-jawab design: v1 single company,
   multi-company ready (lihat COMPANY_PROFILE.md).

### Pending

- Task P1-01 sampai P1-08 belum dieksekusi (gate approval).

## [0.0.1] â€” 2026-06-30 (initial commit ec466eb)

### Added â€” Foundation (Phase 0, sudah selesai sebelum blueprint)

- Laravel 12 + Breeze (Inertia/React/TypeScript) scaffold.
- 28 UI primitives di `resources/js/Components/ui/` (Button, Input, Card,
  Table, Modal, Toast, Tabs, Switch, Select, Badge, Avatar, Dropdown,
  Tooltip, Pagination, Skeleton, Spinner, StatCard, Alert, EmptyState,
  Breadcrumb, Sidebar, Topbar, Checkbox, RadioGroup, Textarea, Label,
  IconButton, Divider) + barrel `index.ts` + `cn()` helper.
- AdminLayout (sidebar + topbar shell).
- Admin/Components gallery page (9 section demo).
- Tailwind config: token brand/surface/success/warning/danger,
  darkMode='class', Figtree font.
- Database MySQL (Laragon) + seeder admin@inbils.test.
- AGENTS.md (UI convention, mandatory).

## [0.0.1-fix] â€” 2026-06-30 (commit 41c5bb1)

### Fixed

- `tailwind.config.js`: tambah `darkMode: 'class'` (sebelumnya default
  'media' â†’ dark toggle ignore html.dark class).
- `Admin/Components.tsx`: rebuild jadi full gallery 9 section + sticky
  left nav.
- `AdminLayout.tsx`: tambah menu "Komponen".
- `app.tsx`: remove redundant page-level ToastProvider (conflict root
  provider).
- `Dashboard.tsx`: template dashboard (stats + table + recent activity).

