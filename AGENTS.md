# inbils — Project Agent Guide

Stack: Laravel 12 + Inertia.js + React 18 + TypeScript + Tailwind CSS v4 + Vite.
Auth: Laravel Breeze (React/Inertia). DB: MySQL (Laragon).

## AI Artifact Cleanup Rule (MANDATORY)

- File/folder sementara buatan AI untuk scan, debug, ekstraksi, patching, atau laporan antara WAJIB dihapus segera setelah selesai digunakan.
- Evidence/dump AI bersifat sementara: setelah diperiksa, diringkas, atau tidak lagi digunakan, WAJIB langsung dihapus. Pertahankan hanya evidence yang secara eksplisit diwajibkan sebagai deliverable permanen.
- Gunakan direktori temporary di luar repository jika artefak tidak perlu menjadi bagian proyek.
- Sebelum tugas dinyatakan selesai, periksa `git status` dan hapus hanya artefak usang dari tugas tersebut.
- Jangan hapus source code, test resmi, migration, dokumentasi, evidence wajib, atau pekerjaan aktif lain hanya karena untracked.

## Docker Runtime Refresh Rule (MANDATORY)

- Lokal: verifikasi dengan Laravel Sail (`./vendor/bin/sail up -d`), lalu cek `/up` dan `/ready` di `http://localhost`. Source ter-mount langsung (tanpa rebuild); jalankan `./vendor/bin/sail npm run build` bila aset frontend berubah.
- Image deploy (`compose.yaml` + `Dockerfile`): setelah tugas implementasi selesai dan stack Docker tersedia, rebuild image serta recreate hanya service yang terdampak agar runtime mencerminkan source terbaru.
- Jalankan `docker compose --env-file .env.docker ps` dan verifikasi endpoint `/up` serta `/ready` (port 8080) sebelum menyatakan tugas selesai.
- DILARANG menghapus named volume (`down -v`, `docker volume rm`, atau `docker system prune --volumes`) kecuali pengguna meminta penghancuran data secara eksplisit.

## Test Database Rule (MANDATORY)

- Semua test otomatis, QA, browser smoke, dan E2E WAJIB memakai database MySQL `inbils_test`.
- Jika `inbils_test` belum ada, buat database tersebut lalu gunakan kembali pada run berikutnya.
- DILARANG memakai database utama `inbils` untuk test/QA/E2E.
- DILARANG membuat database sementara bernama acak seperti `inbils_*`, `inbils_testing_*`, `inbils_qa_*`, atau `inbils_e2e`.
- Konfigurasi default PHPUnit berada di `phpunit.xml` dengan `DB_DATABASE=inbils_test`; override test harus tetap menunjuk ke nama yang sama.

Dokumen acuan (baca semua sebelum mulai tugas):
- `docs/architecture/overview.md` — layered backend, Inertia-as-Island,
  request flow, transaksi & integritas, caching/queue.
- `docs/architecture/service-layer.md` — Service, Action, Query class,
  Resource.
- `docs/architecture/domain-boundaries.md` — naming convention, dependency
  rules, cross-module communication, `module.json` dependencies.
- `docs/architecture/modules.md` + `docs/architecture/folder-structure.md`
  — struktur module nwidart + shared Core.
- `docs/architecture/database.md` — schema + ERD (company_id + per-company
  unique), seed & Setup Wizard strategy.
- `docs/architecture/decisions/ADR-*.md` — keputusan arsitektur.
- `docs/business/*.md` — spec per modul (customer, inventory, network,
  billing, ticketing, dll.) termasuk state machine ringkas;
  `docs/business/approval-flow.md` untuk approval.
- `docs/standards/*.md` — coding, security, quality gates, delegation.
- `docs/SECURITY.md` — RBAC matrix, capability bundle, invariant SoD.
- Tidak ada di repo (jangan dicari): `VISION`, `COMPANY_PROFILE`, `API`,
  `WORKFLOW`, `TECH_STACK`, `LIBRARY_DECISION`.
  Aturan company/tenant yang berlaku adalah ringkasan di bawah.
- `ROADMAP.md` — 7 Phase.
- `TASKS.md` — task list OpenCode per Phase.

## Model Selection Policy

- Primary engineering model: `cx/gpt-5.5-high`.
- Review model: `cx/gpt-5.5-review`.
- Fallbacks: `cx/gpt-5.5`, `cx/gpt-5.5-medium`, `cx/gpt-5.4`, `cx/gpt-5.4-review`, `cx/gpt-5.4-mini`.
- If OpenCode supports Codex as implementation model, prefer Codex for production code generation while Hermes remains on primary model.
- If runtime cannot route models, use current model and do not attempt prompt-based model switching.

## Role & Batasan

| Role | Tugas | Dilarang |
|------|-------|----------|
| Senior Architect (Hermes) | Analisa, blueprint, arsitektur, keputusan teknis, task generation, review, QA gate | Menulis source code, mengubah file implementasi |
| OpenCode (developer) | Eksekusi task dari TASKS.md, tulis kode + test, update CHANGELOG | Mengubah arsitektur, tambah library tanpa approval, langsung commit ke main |
| QA-ISP (profile Hermes) | Review hasil coding, cari bug, cek requirement + arsitektur + security + duplication | Coding, memperbaiki source code |

## Company Rules (MANDATORY — multi-tenant ready)

Source of truth: ringkasan di bawah + `docs/architecture/database.md`
(company_id, Setup Wizard). Ringkasan:

- Company identity dinamis via DB (`companies` table). TIDAK hardcode
  nama/logo/currency/timezone di kode atau `.env`.
- Company pertama dibuat Setup Wizard (one-shot). Multi-company AKTIF
  (keputusan owner 2026-10-10, `.scratch/head-company/spec.md`):
  hierarki satu level `companies.parent_company_id` (Head → Subsidiary,
  Head = company tanpa parent yang punya subsidiary), membership user di
  `company_user`, subsidiary dibuat HANYA lewat artisan
  `inbils:company:create-subsidiary`.
- Company efektif = `active_company_id` di session (divalidasi terhadap
  `company_user` + company aktif di SETIAP pembacaan) atau home company
  (`users.company_id`). DILARANG membaca `users.company_id` sebagai tenant:
  pakai `CompanyService::currentId()` / `CompanyService::idFor($user)`.
- Setiap model master/transaksi WAJIB pakai trait `BelongsToCompany`
  (auto-scope query ke `CompanyService::currentId()` + auto-set company_id
  on create). Scope FAIL-CLOSED: tanpa company context query tenant kosong
  dan create → 403. Code tanpa user (artisan, queue job, seeder) WAJIB
  `CompanyService::runAs($companyId, fn)`. Exception: User, Role,
  Permission, ActivityLog, Company.
- Setiap migration tabel master/transaksi WAJIB tambah `company_id`
  bigint unsigned FK → `companies.id` NOT NULL + unique index per-company
  (mis. `(company_id, sku)` bukan `sku` global).
- Company identity (nama, logo, currency, timezone, settings) baca via
  `CompanyService::current()` / `CompanyService::setting($key)`. Jangan
  hardcode, jangan baca `.env` untuk company-specific.
- TIDAK boleh query cross-company tanpa `Model::withoutCompany()` /
  `forCompany($id)` / `CompanyService::runAs()` eksplisit (hanya artisan,
  queue job, seeder). Head melihat subsidiary HANYA lewat laporan
  konsolidasi read-only (`report.consolidated.view`, Head + subsidiary
  aktif, semua currency harus sama); menulis di subsidiary = switch
  (`company.switch`) ke company yang di-membership-kan. Role/permission
  tetap global (spatie teams=false).
- Role definition (buat/ubah/hapus role + permission) bersifat global, jadi
  HANYA boleh dari company top-level (Head atau company tunggal), tidak dari
  subsidiary (`RolePolicy`); jumlah user per role dihitung per company efektif.
  Administrasi user (lihat/ubah/hapus, termasuk email, password, role, status)
  menuntut aktor member SETIAP company milik target
  (`CompanyMembershipService::administers`): satu login berlaku di semua
  company membernya. Head = company tanpa parent dengan >= 1 subsidiary AKTIF.
- Setup Wizard flow wajib: fresh install → `php artisan inbils:setup`
  (bootstrap user) → login → wizard → company created. Route `/setup`
  one-shot (RequireNoCompany middleware abort 403 if company exists).
- Breeze register route DISABLED (user creation via bootstrap command
  + admin User CRUD post-wizard).
- Dua-layer config: `companies.settings` json (company-specific, diisi
  wizard) + `settings` table (system-global defaults, seeded saat install).
  Resolution: `CompanyService::setting(key)` → fallback
  `SettingService::get('default_'.key)` → hardcoded default.
- **Initialization rule (MANDATORY):** TIDAK seed company data saat
  install. Seeder hanya berisi: (1) system roles, (2) permission template,
  (3) system setting defaults. Company dibuat SATU kali via Setup Wizard.
  Bootstrap user via `php artisan inbils:setup` (interactive, BUKAN
  seeder). Breeze register route DISABLED. Lihat `docs/architecture/database.md`.
- System-global infra config (APP_NAME env, mail driver, queue connection)
  tetap `.env`/config. System setting table = business defaults/fallback
  (app.name fallback, default_currency, default_timezone, dll).

## Modular Architecture Rules (MANDATORY — nwidart/laravel-modules)

Source of truth: `docs/architecture/modules.md` +
`docs/architecture/domain-boundaries.md`. Ringkasan:

- Domain logic (Inventory/SPK/Billing/Ticketing) WAJIB di `Modules/{Name}/`,
  BUKAN `app/`. Shared cross-cutting (Company/User/Auth/Audit/Setting/
  Customer) tetap `app/` (dipakai semua module).
- Module generate: `php artisan module:make {Name}`. Namespace
  `Modules\{Name}\...`. Isi `Modules/{Name}/module.json` `dependencies` key.
- **Module TIDAK boleh coupling langsung.** Controller/Service module A
  TIDAK boleh `use Modules\B\Models\...`. Komunikasi via:
  - Service interface / contract (module A panggil Service B).
  - Action class cross-module (mis. `CompleteWorkOrderAction` orchestrate
    InventoryService + BillingService).
  - Event/listener (non-critical loose coupling).
- Dokumentasikan dependency antar module di `docs/business/{module}.md`
  + `docs/architecture/domain-boundaries.md` + `module.json`.
- Tabel TIDAK di-prefix (global `products`, bukan `inventory_products`).
- Inventory = sumber kebenaran stok. SPK/Billing → Inventory via
  `IssueStockAction`/`ReceiveStockAction`. Inventory → SPK/Billing via
  polymorphic `stock_movements.reference` (string, no FK hard-coupling).
- Module detail design: `docs/business/{module}.md` (Inventory sudah
  rewrite dengan dynamic category/attribute/serial/stock movement).
- Review Hermes akan REJECT: domain logic di `app/`, direct cross-module
  Model import, tabel di-prefix tanpa alasan.

## Architecture Rules (Laravel)

- Thin Controller → FormRequest (validation) → Policy (authorization) →
  Service (business logic) → Model (data + relation) → Migration (schema).
- Action Class untuk proses multi-step lintas service (mis.
  `CompleteWorkOrderAction` panggil InventoryService + BillingService +
  AuditService).
- Query Class untuk read kompleks (>30 baris atau dipakai >1 controller),
  taruh di `app/Queries/`.
- Resource untuk semua response entity/list (kontrak frontend).
- Model = relation + scope + accessor/mutator + factory. TIDAK business
  logic. TIDAK service call.
- Controller = parse request → service → Inertia/resource/redirect. TIDAK
  business logic.
- `$fillable` eksplisit di model. `Model::guarded([])` dilarang.
- Transaksi `DB::transaction()` di Service untuk write lintas tabel.
- Foreign key constraint WAJIB. Soft delete HANYA entity bersejarah
  (Item, WorkOrder, Invoice, Ticket, Customer, User).
  StockMovement = immutable (tidak soft delete).
- Constraint di DB (unique index, check) > app code validation untuk
  integritas data.

## Architecture Rules (Frontend — Inertia-as-Island)

- Laravel = source of truth (routing, auth, permission, workflow).
- React = page-level island per route. Compose `Components/ui/*` +
  `Components/composite/*`.
- TIDAK SPA penuh. TIDAK axios/fetch manual (Inertia `router` cukup).
- TIDAK Redux/Zustand/React Query v1 (state server-driven via Inertia
  props).
- Form state: `useForm` dari `@inertiajs/react`.
- UI state lokal: `useState`. Global UI: `useToast` + dark mode
  `localStorage`.
- Hooks: `usePermission` (baca `auth.permissions` Inertia prop),
  `useInertiaForm` (wrapper toast), `useFilter`.
- Layering:
  - `Components/ui/*` = primitives, props-only, TIDAK fetch, TIDAK import
    `lib/api.ts` atau composite.
  - `Components/composite/*` = susunan primitives untuk pola bisnis
    (DataTable, FormField, PageHeader). Boleh `router` untuk pagination.
  - `Pages/**` = compose composite + ui. Boleh `router` untuk persisten
    aksi.

## UI Component Convention (MANDATORY — retained from Phase 0)

All UI MUST be built from reusable components under `resources/js/Components/ui/`.
This is the single source of truth for the design system.

- Pages (`resources/js/Pages/**`) compose `Components/ui/*` +
  `Components/composite/*` — they NEVER inline raw `<button>`, `<input>`,
  `<table>`, or hand-rolled styled elements.
- If a primitive is missing from `Components/ui/`, ADD it there first,
  then use it.
- Never duplicate styling logic across pages. Variants belong on the
  component (via a `variant` prop or `cva`), not as page-level `className`
  overrides.
- Breeze-shipped components (`Components/*` outside `ui/`, e.g.
  `PrimaryButton`, `TextInput`, `InputLabel`) are legacy auth scaffolding.
  Do not extend them for new dashboard UI — use `Components/ui/*`
  equivalents instead.

## Design System

- Theme: modern flat. No heavy gradients, no faux-3D, no drop shadows
  deeper than `shadow-xs` (Tailwind v4 name of the old `shadow-sm`). Rounded corners (`rounded-lg`/`rounded-xl`),
  generous whitespace, restrained palette.
- Dark mode: every component MUST support `dark:` variants. The app
  toggles via the `class` strategy (set on `<html>`); `resources/css/app.css`
  declares `@custom-variant dark`.
- Color tokens: `resources/css/app.css` `@theme` (CSS-first config; no `tailwind.config.js`). Use semantic
  names (`brand`, `surface`, `success`, `warning`, `danger`), never raw
  hex in components.
- Typography: Figtree (already wired). Headings via `font-semibold`/
  `font-bold`.
- Focus states: always visible `focus:ring` for keyboard a11y.
- Interactive elements: clear `hover:` + `active:` + `disabled:` states.

## Code Standards

- TypeScript everywhere. Props typed with explicit interfaces (no `any`).
- React components: function components, named exports, `.tsx` extension.
- One component per file. File name = PascalCase = component name.
- Props: spread forwarded refs where relevant (`React.forwardRef` for
  inputs/buttons so they integrate with forms).
- Keep components presentational; fetch/data logic stays in Inertia props
  or Laravel controllers. No `fetch`/`axios` calls inside
  `Components/ui/*`.
- Accessibility: semantic HTML, `aria-*` where needed, labels for inputs,
  `role` for non-native widgets (modals, dropdowns).

## Naming Convention

- Migration: `create_{table}_table` / `add_{col}_to_{table}_table` /
  `{action}_{table}_table`.
- Model: Singular PascalCase. Table: snake_case plural.
- FK: `{singular}_id` unsigned. Pivot: `{a}_{b}` alfabetis.
- Controller: `{Entity}Controller` resource methods.
- Service: `{Domain}Service`. Action: `{Verb}{Entity}Action`.
- Policy: `{Model}Policy`. Resource: `{Model}Resource`.
- Route name: `admin.{module}.{action}`.
- Permission: `{module}.{action}` (`inventory.view`, `spk.assign`).

## Layout & Folder Structure

```
resources/js/
  Components/
    ui/            # 28 design system primitives (existing)
      index.ts     # barrel export
    composite/     # composite bisnis (DataTable, FormField, PageHeader, ...)
      index.ts
  Layouts/
    AdminLayout.tsx
  Pages/
    Admin/
      Dashboard/
      Company/      # profile + settings (post-wizard)
      Users/
      Roles/
      Permissions/
      Setup/        # Wizard.tsx (first-login, no AdminLayout)
      Inventory/   # Phase 3
      Spk/         # Phase 4
      Billing/     # Phase 5
      Ticketing/   # Phase 6
      Customers/   # Phase 2
      Categories/  # Phase 2
      Units/       # Phase 2
      Warehouses/  # Phase 2
  hooks/           # usePermission, useInertiaForm, useFilter
  lib/
    utils.ts       # cn helper
    format.ts      # formatRupiah, formatDate, formatNumber
    api.ts         # Inertia visit wrappers (typed)
  types/
    global.d.ts    # Ziggy + Inertia page props
    models.d.ts    # entity TS interfaces (mirror Resource)
```

```
app/                          # SHARED Core (cross-cutting, bukan domain)
  Http/
    Controllers/Admin/        # Dashboard, Company, User, Role, Permission,
                              # Setup, SystemSetting (shared admin)
    Middleware/               # RedirectIfNoCompany, RequireNoCompany,
                              # RequireHasCompany, Authenticate, etc.
    Requests/Admin/           # shared Core requests
    Resources/                # shared Core resources
  Models/
    Core/          # Company, User, Role, Permission, ActivityLog, Setting, Customer
    Traits/        # BelongsToCompany (CompanyScope)
  Services/Core/  # CompanyService, SettingService, SetupWizardService,
                   # AuditService, UserService
  Actions/         # cross-module orchestration (CompleteWorkOrderAction, etc.)
  Policies/        # CompanyPolicy, UserPolicy, RolePolicy
  Providers/, Exceptions/
Modules/                      # DOMAIN (nwidart/laravel-modules)
  Inventory/                  # Product, Category, AttributeDefinition,
    │                          # ProductAttribute, ProductVariant, ItemSerial,
    │                          # Stock, StockMovement, Warehouse, Unit
    ├── Config/ Database/{Migrations,Factories,Seeders}/ Entities/
    ├── Http/{Controllers,Requests}/ Models/ Services/ Actions/
    ├── Policies/ Resources/ Tests/ Routes/{web,api}.php
    └── module.json           # dependencies: []
  SPK/                        # WorkOrder, WorkOrderItem, WorkOrderStatus
  Billing/                    # Invoice, InvoiceItem, Payment
  Ticketing/                  # Ticket, TicketCategory, TicketComment, TicketAttachment
database/
  migrations/                 # shared Core migrations (companies, users, settings, permission, activity_log)
  factories/  seeders/        # shared Core seeders (RolePermissionSeeder, SystemSettingSeeder)
tests/
  Feature/  Unit/             # shared Core tests (module tests di Modules/{Name}/Tests/)
```

## Dev Commands

- Backend: `php artisan serve` (port 8000)
- Frontend HMR: `npm run dev`
- Build: `npm run build` (tsc + vite)
- Migrate + seed: `php artisan migrate:fresh --seed`
- Tests: `php artisan test`
- Format PHP: `vendor/bin/pint`
- Log tail: `php artisan pail`

## Login (bootstrap + wizard)

- TIDAK ada seeded admin user (Breeze seeder admin@inbils.test dihapus/
  disabled).
- Fresh install: `php artisan migrate:fresh --seed` (roles + permissions
  only) → `php artisan inbils:setup` (create bootstrap user, interactive
  prompt name/email/password) → login → Setup Wizard → company created +
  admin role assigned.
- Sample manager/staff/technician user: tambah via admin User CRUD
  post-wizard (atau seeder dev optional di Phase 1 finalisasi).

## OpenCode Rules

- Baca `docs/` semua + `AGENTS.md` + `TASKS.md` (task terkait) sebelum
  mulai.
- Eksekusi 1 task per batch. Branch `feat/{module}-{task-id}`.
- Reuse model/component/service existing sebelum buat baru.
- Tulis test (feature + unit). Coverage ≥ 80% untuk logika bisnis.
- Update `CHANGELOG.md` setiap task.
- Jalankan `php artisan test` + `npm run build` sebelum selesai, lampir
  output.
- TIDAK ubah arsitektur. TIDAK tambah library tanpa approval Hermes.
- Lapor Hermes: file changed (git diff --stat), test result, build
  result, risk/edge case.

## Orchestration Rules

- Ikuti `docs/standards/delegation-rules.md` dan
  `docs/standards/quality-gates.md` (dokumen `ORCHESTRATION` terpisah
  tidak ada di repo).
- Ikuti one-task loop: pick 1 task, freeze scope, execution brief,
  execute, test, review, fix review findings, update docs, mark done.
- Satu task aktif saja. Dilarang phase jump.
- Dilarang "while here" work dan refactor di luar acceptance criteria.
- Ide baru saat task aktif masuk `docs/PARKING_LOT.md` (buat file bila
  belum ada), bukan dikerjakan.
- Delegasi coding ke OpenCode wajib ikuti `docs/standards/delegation-rules.md`.
- Task belum selesai kalau acceptance, test/build, changelog, dan review
  belum PASS.
- Auto-continue boleh dalam phase yang sama setelah task PASS. Stop saat
  test/build gagal, butuh approval, docs/code conflict, acceptance tidak
  jelas, keputusan bisnis/arsitektur dibutuhkan, atau phase boundary.
- Jangan minta pendapat Owner antar task dalam phase yang sama. Setelah
  `P1-00` PASS langsung lanjut `P1-01`, terus sampai Phase 1 gate atau
  stop condition.
- Jangan commit per task. Setelah phase COMPLETE, minta approval Owner;
  kalau disetujui, buat satu commit phase lalu lanjut phase berikutnya.
- Lapor singkat setelah tiap task PASS/NEED FIX/BLOCKED. Lapor lengkap
  saat module/phase complete.

## QA Rules (profile QA-ISP)

- Review hasil coding setelah OpenCode selesai task.
- Cek: requirement sesuai (TASKS.md acceptance), arsitektur sesuai
  (AGENTS.md + docs/), database benar (migration + constraint), security
  (`docs/SECURITY.md` checklist), reusable model/component, testing coverage.
- DILARANG coding atau memperbaiki source code. Hanya buat
  `QA_REPORT.md` dengan status PASS / NEED FIX / BLOCKED + daftar
  perbaikan untuk OpenCode.
- Hermes gate: QA pass → lanjut task berikutnya. QA fail → task revisi
  OpenCode.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.2
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v2
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- tightenco/ziggy (ZIGGY) - v2
- laravel/boost (BOOST) - v2
- laravel/breeze (BREEZE) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- phpunit/phpunit (PHPUNIT) - v11
- @inertiajs/react (INERTIA_REACT) - v2
- react (REACT) - v18
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/Pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v2

- Use all Inertia features from v1 and v2. Check the documentation before making changes to ensure the correct approach.
- New features: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>

<!-- antislop:start -->
## antislop (OpenCode)
Untuk pekerjaan UI, teks antarmuka, aksesibilitas, layout mobile, atau komentar kode, baca `.opencode/skills/antislop/SKILL.md` dan skill terkait:
- UI / visual: `.opencode/skills/antislop-ui/SKILL.md`
- Teks: `.opencode/skills/antislop-copywriting/SKILL.md`
- Aksesibilitas: `.opencode/skills/antislop-human/SKILL.md`
- Mobile / responsive: `.opencode/skills/antislop-layoutmobile/SKILL.md`
- Komentar kode: `.opencode/skills/antislop-code/SKILL.md`
Mode DURING untuk pekerjaan UI sesuai arahan proyek di `CLAUDE.md`; mode AFTER hanya atas permintaan audit eksplisit. Gunakan panduan desain dan token UI proyek sebagai arah visual.
<!-- antislop:end -->
