# Inbils project guide

Single-file briefing for an engineer or AI agent that has only a fresh clone. Read it fully before changing code.
Everything below was checked against the code on 2026-10-22. Where a deeper doc disagrees with code, **the code wins**
(the conflicts found are listed in section 17). First-time machine setup is in [README.md](README.md); this file is
about how to work in the codebase.

Conventions: shell commands run from the repo root. `sail` = `./vendor/bin/sail`. UI copy is Indonesian; code,
commits, docs and this guide are English.

AI agents load `AGENTS.md` or `CLAUDE.md` by themselves; both are git-ignored here, so on a new machine point them at
this file once: `ln -s PROJECT_GUIDE.md AGENTS.md && ln -s PROJECT_GUIDE.md CLAUDE.md` (the links stay untracked).

## 1. What Inbils is

Multi-tenant SaaS for Indonesian ISPs. One installation hosts many tenants (ISP companies). A tenant is a top-level
company plus its optional subsidiaries.

| Area | What it covers |
| --- | --- |
| Customers and subscriptions | customers, addresses, contacts, documents, service packages, subscription lifecycle (pending, active, suspended, terminated), isolir (suspended-customer page) |
| Network | network assets and installation history, NAS devices, RADIUS accounts and CoA disconnects, IPAM (prefixes, VLANs, addresses), ODN topology, hardware panel |
| Field work | SPK work orders (state machine, assignment, evidence, check-in distance rule), dispatch queue, "Pekerjaan Saya", technician evaluations |
| Inventory | products, warehouses, stock, immutable stock movements, requests, adjustments with approval, material returns |
| Billing | recurring invoices, payments, receipts, receivables, Midtrans online payment, WhatsApp reminders |
| Ticketing | tickets, SLA tiers and breach checks, comments, attachments, customer rating |
| Reports | read-only reporting module with drill-down, CSV and PDF export, consolidated head-company report |
| Platform | operator panel at `/platform` (separate guard): tenant provisioning, suspension, exports, impersonation, role templates, support tools |
| Mobile | two Flutter apps (customer, technician) in the separate repo `inbils-mobile` use `api/v1` |

Authoritative product behaviour per domain: `docs/business/*.md` (section 16).

## 2. Stack and versions

| Layer | Technology (from `composer.json` / `package.json`) |
| --- | --- |
| Runtime | PHP `^8.4` (Sail and the production image run PHP 8.5.12 or newer; FrankenPHP in production) |
| Framework | Laravel `^13.0`, Inertia Laravel `^2.0` |
| Modules | `nwidart/laravel-modules ^12.0` (+ composer merge plugin reading `Modules/*/composer.json`) |
| Auth | Laravel Sanctum `^4.0` (mobile tokens), session guards for web; Breeze scaffolding for the login screens |
| Packages | `spatie/laravel-permission ^6.25`, `spatie/laravel-medialibrary ^11.23`, `spatie/laravel-activitylog ^4.12`, `spatie/laravel-query-builder ^6.4`, `tightenco/ziggy ^2.0`, `barryvdh/laravel-dompdf`, `kreait/laravel-firebase` (FCM push), `laravolt/indonesia`, `mlocati/ip-lib`, `pragmarx/google2fa` |
| Dev tools | `dedoc/scramble ^0.13` (OpenAPI), `phpunit/phpunit ^11.5.50`, `brianium/paratest ^7.8`, `larastan/larastan ^3.0` (level 5), `laravel/pint`, `laravel/sail`, `laravel/boost`, `laravel/pail` |
| Database | MySQL 8.4 (Sail and deploy images), Redis 7.4 (cache), Mailpit (dev mail) |
| Frontend | React `^18.2`, TypeScript `^5`, `@inertiajs/react ^2`, Vite `^7`, Tailwind CSS `^4.3` (CSS-first, `@tailwindcss/vite`), Radix UI primitives, `class-variance-authority`, `tailwind-merge`, `lucide-react`, `@tanstack/react-table`, `recharts`, `leaflet`, `filepond`, `date-fns`, `motion`, `react-day-picker` |
| E2E | Playwright `^1.61` (chromium) |

There is no `tailwind.config.js`: theme tokens live in `resources/css/app.css` (`@theme`). No Pest: PHPUnit only.

## 3. Repository map

```
app/                       Shared core (cross-cutting): companies, users, auth, audit, customers, subscriptions, locations, platform
  Actions/                 Multi-service orchestration (CreateInvoiceFromSpkAction, CreateCustomerOnboardingAction, ...)
  Console/Commands/        inbils:* and ops:* commands (auto-registered)
  Exceptions/              ApiException, RenderApiError (the one renderer of api/* errors)
  Http/Controllers/        Admin/ (tenant back-office), Api/V1/ (mobile), Auth/, Platform/, Setup/, Operations/ (/ready), WhatsAppWebhookController
  Http/Middleware/         ResolveActiveCompany, RedirectIfNoCompany, HandleInertiaRequests, Api/*, Platform/*, ...
  Http/Requests/           Admin/, Api/, Auth/, Platform/, Setup/ (FormRequests)
  Http/Resources/          API resources (Api/V1/ for the apps)
  Jobs/ Listeners/ Events/ Notifications/ Rules/
  Models/                  Core/ (Company, Customer, ServiceSubscription, Location, CompanyIntegration, ...), Platform/, User, Role, RoleTemplate, Permission, PlatformAdmin
  Policies/                Core policies (Company, Customer, Subscription, User, Role, Location, ...)
  Providers/               AppServiceProvider, PlatformServiceProvider
  Queries/                 Admin/ and Platform/ read-heavy query classes (dashboard, approval inbox, dispatch, my-work)
  Services/                Core/ (CompanyService, CompanyRoleService, ApiTokenService, AuditService, SubscriptionService, SettingService, TwoFactorService, ...), Platform/, Push/, WhatsApp/
  Support/                 PermissionCatalogue, PublicId, OptimisticLock, RecordNotFound, Money, SodConflicts, AuditEventText, Notifications/StaffRecipients, ...
  Traits/                  BelongsToCompany, HasPublicId, HasLifecycle
Modules/<Name>/            Domain code (section 4). Namespace Modules\<Name>\...
bootstrap/app.php          Middleware groups and aliases, exception rendering, routing (the replacement of Kernel.php)
config/                    platform.php, security.php, scramble.php, sanctum.php (app_token_days), modules.php, ziggy.php, permission.php, ...
database/                  migrations/ (shared core), seeders/, factories/
docker/                    Caddyfile + app/ (production image), mysql/{init,sail}/, devproxy/ (local *.test HTTPS proxy), php/
deploy/systemd/            host unit examples
docs/                      Tracked docs are allowlisted in .gitignore (section 15/16)
lang/                      Laravel translations
resources/css/app.css      Tailwind v4 theme tokens (light and dark)
resources/js/              Frontend (section 9)
resources/views/           app.blade.php (Inertia root, `@routes` picks the Ziggy group), pdf/, exports/
routes/                    web.php, auth.php, admin.php, api.php, webhooks.php, platform.php + platform/*.php, console.php (schedule)
scripts/                   e2e-*.mjs (Playwright harness), docs-screenshots.*, dev-payments.sh, operations/ (backup, restore, health-check, deploy-local)
tests/                     Feature/, Unit/, Support/, Traits/ (shared builders), e2e/ (Playwright specs + setup.php), TestCase.php
compose.yaml + Dockerfile  Production-style build (project `inbils`, port 8080)
compose.sail.yaml          Local development (project `inbils-sail`, port 80)
```

Route files:

| File | Content |
| --- | --- |
| `routes/web.php` | `/`, `/ready`, `/app-only`, `/setup` (one-shot wizard), profile; `/up` comes from `bootstrap/app.php` |
| `routes/admin.php` | Tenant back-office, prefix `admin`, names `admin.*`, middleware `auth, verified, redirect.app.only, require.has.company` |
| `routes/platform.php` + `routes/platform/*.php` | Prefix `/platform`, names `platform.*`, middleware group `platform`; every file in the folder is auto-required (add a new file, do not edit the shell). `PLATFORM_DOMAIN` (config `platform.domain`) can bind it to a host |
| `routes/api.php` | `api/v1/*`, names `api.v1.*` |
| `routes/webhooks.php` | Server-to-server callbacks (WhatsApp), throttled, no session or CSRF |
| `Modules/<Name>/routes/{web,api,webhook}.php` | Mapped by each module's `RouteServiceProvider` (web group; api with the same `api/v1` prefix and `api.v1.` name) |
| `routes/console.php` | The scheduler (billing at 02:00/03:00/08:00, `tickets:sla` and `midtrans:reconcile` and `network:sync` every 15 min, heartbeats, prunes, WhatsApp reminders, document expiry, scheduled announcements). Add new schedules here, never in a Kernel |

Tests layout: `tests/Feature` and `tests/Unit` (core), `Modules/<Name>/tests/{Feature,Unit}` (PHPUnit suite `Modules`
globs `Modules` for `*Test.php`), `tests/Traits/*` shared fixture builders (e.g. `CreatesCompanyUser`,
`InteractsWithMobileApi`, `InteractsWithPlatform`, `BuildsCompanyGroup`), `tests/e2e/*.spec.ts` Playwright.

## 4. Modules (nwidart)

Generated with `sail artisan module:make <Name>`. Code lives in `Modules/<Name>/app/` (namespace `Modules\<Name>\`:
`Actions, Console, Http/{Controllers,Requests}, Models, Policies, Providers, Queries, Resources, Services, ...`),
plus `database/{migrations,factories,seeders}`, `routes/`, `tests/`, `module.json`.

| Module | Owns | `module.json` dependencies |
| --- | --- | --- |
| Billing | Invoice, InvoiceItem, Payment, payment attempts, Midtrans, receivables | Inventory, SPK, Service |
| Customer | thin shell (provider, resources); customer model and service live in `app/` core | none |
| Inventory | Product, Category, Unit, Warehouse, Stock, StockMovement, requests, adjustments | none |
| Ipam | IP prefixes, VLANs, addresses | NetworkAsset |
| NetworkAsset | NetworkAsset, installations, NAS devices, RADIUS sync, ODN | Service, Ipam |
| Reporting | read-only report queries, registry, drill-down, exports | Billing, Inventory, NetworkAsset, SPK, Ticketing |
| SPK | WorkOrder, items, assignments, evidence | Inventory, NetworkAsset |
| Service | service packages, bandwidth and speed profiles, SLA tiers, announcements, promo banners | none |
| Ticketing | Ticket, categories, comments, attachments, SLA | SPK, NetworkAsset |

Quirks (do not "fix" without a reason): `Modules/Service` keeps `Models/`, `Http/`, `Policies/` at the module root and maps them in its
own `composer.json` PSR-4 block, other modules use `app/`. `Modules/Ipam` has no `tests/` folder. `Ipam` and
`NetworkAsset` list each other as dependencies. Shared core models (Customer, CustomerAddress, CustomerContact,
ServiceSubscription, Location, EmployeeEvaluation) live in `app/Models/Core/` and may be imported from any module.

Boundary rules (target for new code; section 17 notes where old code differs):

- Domain logic belongs in `Modules/<Name>/`, not in `app/`. Cross-cutting (company, user, auth, audit, settings, customer, subscription) stays in `app/`.
- Do not import another module's `Models` from a controller or service. Go through the other module's service/contract, a cross-module Action in `app/Actions/`, or an event/listener. Stock links use the polymorphic `stock_movements.reference` string, not a hard FK.
- Declare a new dependency in `module.json` and in `docs/architecture/domain-boundaries.md` (`docs/business/<module>.md` for behaviour).
- Tables are not prefixed (`products`, not `inventory_products`).
- Inventory is the source of truth for stock: other modules change stock only through Inventory actions/services.
- Reporting is the one allowed read-only exception that reads every module.

## 5. Backend layering and flow

```
Route -> middleware (auth, company, can:) -> Controller (thin)
      -> FormRequest (validation + authorize) -> Policy (per record)
      -> Service (business rules, DB::transaction) [-> Action when it spans services]
      -> Model (relations, scopes, casts(), factory; NO business logic, NO service call)
      -> API Resource / Inertia props
```

- Controllers parse the request, call one service, return Inertia / Resource / redirect. No business logic.
- `FormRequest` for every write; explicit `$fillable`; never `Model::guarded([])`. Casts in `casts()`.
- Authorize at the route (`can:<permission>`), in the FormRequest, and with a Policy on every record action. UI checks are not security.
- Services own `DB::transaction()` for writes across tables and call `AuditService` for audit events.
- Action class `{Verb}{Entity}Action` for multi-service processes. Query class (`app/Queries/`, module `Queries/`) for complex reads (>30 lines or used by 2+ controllers).
- API responses use Eloquent API Resources. Embed related records as summaries (`{id, code, name}`), never whole models (SEC-1).
- Naming: Service `{Domain}Service`, Policy `{Model}Policy`, Resource `{Model}Resource`, route name `admin.<area>.<action>`, permission `<area>.<action>`, pivot tables alphabetical, FK `{singular}_id`.
- Use `php artisan make:*` with `--no-interaction` (via `sail artisan`); new models get factories (and seeders when they need data). Prefer named routes and `route()`.
- Concurrency/integrity helpers that already exist: `App\Support\OptimisticLock` (forms send `expected_updated_at`, stale = 409), `PreventDuplicateWebSubmit` (identical `admin.*` write within 10 s is refused), `X-Inbils-Company` header check in `ResolveActiveCompany` (409 when another tab switched company), `DetectRecordEnumeration` (429 after many record 404s), `lockForUpdate` for counters and code generation.
- Money: use `App\Support\Money`; numbering through `NumberSequenceService` (templates per company), never hand-built codes.
- Cache: `config/cache.php` sets `serializable_classes` to `false`, and the array store of the PHPUnit suite serializes like Redis, so a cache entry holds scalars and arrays only: attribute rows, never models, collections, Carbon or DTOs (see `HomeFeedController`). `tests/Feature/Support/CacheRefusesObjectsTest.php` pins it. Version the key (`...:v2:...`) when the shape of an entry changes, so a deploy or rollback never reads the old shape.
- Errors: a state/rule refusal is `abort(422, '...')` or a domain exception; the 409/403/404 rendering for Inertia writes (toast, no error page) is in `bootstrap/app.php`.

## 6. Multi-tenancy (the most important rule set)

| Rule | Detail |
| --- | --- |
| Model scope | Every master/transaction model uses `App\Traits\BelongsToCompany`: a global scope `company` adds `<table>.company_id = CompanyService::currentId()` and `creating` fills `company_id`. **Fail closed**: without a company context the scope matches nothing (`whereIn(col, [])`) and a create answers 403 |
| Exempt models | User, Permission, ActivityLog, Company, PlatformAdmin, TenantExport (membership is their tenant boundary) |
| Resolving the tenant | `CompanyService::currentId()` (null when nobody or nothing is in scope), `CompanyService::idFor($user)`, `CompanyService::current()` for the `Company`, `CompanyService::setting($key)` for per-company settings. The effective company is the `active_company_id` session value (re-validated against `company_user` and an active company on every read) or the home company |
| Never | Never read `users.company_id` as "the tenant". It is only the home company |
| No user in scope | Artisan, queue jobs, listeners, webhooks, seeders MUST wrap work in `CompanyService::runAs($companyId, fn () => ...)` (nests, restores) |
| Crossing companies | `Model::withoutCompany()` / `Model::forCompany($id)` only in platform code, artisan, jobs, seeders, and always with the tenant named explicitly (platform list metrics may use `withoutCompany()->whereIn('company_id', $explicitIds)` and `GROUP BY company_id`). A tenant controller gets no exception |
| Migrations | A master/transaction table gets `company_id` (unsigned bigint FK to `companies.id`, NOT NULL) and **per-company unique indexes** (`(company_id, sku)`, not `sku`) |
| Company identity | Name, logo, currency, timezone live in the DB (`companies`, `companies.settings`). Never hardcode them or read them from `.env`. Setting resolution: `CompanyService::setting()` -> `SettingService::get('default_'.$key)` -> code default. Secrets and infra stay in `.env` |
| Head vs subsidiary | A tenant = one top-level company (`parent_company_id` null) plus subsidiaries. `Company::isHead()` = top-level with active children. A head sees subsidiaries only in the read-only consolidated report (`report.consolidated.view`, same currency required). Writing in a subsidiary means switching (`company.switch` permission plus a `company_user` membership); the switch is validated on every read |
| Platform admin | A separate identity in `platform_admins`, guard `platform`, routes under `/platform`. It is **never a User**, has no `company_id`, membership or tenant role, and is never a policy exception. Tenants and subsidiaries are created only by platform admins (CLI `inbils:company:create-subsidiary` exists for operations). Breeze registration stays disabled |
| Platform step-up | Sensitive platform actions need a recent password confirmation (own session key, 5 minutes, 5 tries/min) via middleware alias `platform.password.confirm`; suspend, delete request, export and write-mode impersonation also require retyping the exact company code |
| Impersonation | Platform admin stays the real actor, max 30 min, read-only by default, mode changes audited in platform and tenant logs. Never change the impersonated user's account/2FA/password/email and never issue API tokens from it |
| First install | `inbils:setup` creates a bootstrap user without a company -> login -> `/setup` wizard (one-shot; `RequireNoCompany` 403 once a company exists) creates the first company, copies role templates. Install seeds only the permission catalogue, role templates and system setting defaults |
| Active-company tab safety | Frontend sends `X-Inbils-Company` on every non-GET visit; do not strip it from custom `axios` posts |

Tenant-purge and tenant export are platform operations: `docs/operations/tenant-purge.md`, `inbils:platform:purge-company {code}`.

## 7. Authorization (ADR-004: roles belong to a company)

- **Check permissions, never role names.** `hasRole`, `hasAnyRole`, `->role('x')`, `whereHas('roles', ... name ...)`, the Spatie `Role` model and `role:`/`role_or_permission:` middleware are forbidden in `app/` and `Modules/`. `tests/Feature/Roles/RoleNameChecksArchitectureTest.php` scans for them and fails the suite.
- Spatie runs with `teams=false`. Roles are company-owned through `App\Models\Role` (`BelongsToCompany`, `roles.company_id` NOT NULL, `roles.template_key`). Permissions are one catalogue in code: `App\Support\PermissionCatalogue::ALL` (+ `templates()`). A company composes roles from the catalogue and never creates permissions.
- Audience markers (these are permissions, not roles): `app.web` (back-office), `app.technician` (field staff), `portal.access` + `customers.user_id` link (customer account), `evaluation.evaluable` (employee who can be evaluated). A login without `app.web` lands on the no-access/app-only page. "Company administrator" = holder of `roles.manage`.
- **Adding a permission**: add it to `PermissionCatalogue::ALL` and to the relevant `templates()` entries, then ship a migration that calls `PermissionCatalogue::release($permission, $templateKeys)` (example: `database/migrations/2026_11_02_100700_release_ticket_reopen_to_templates.php`). `release()` creates it, adds it to the named templates and to every company role whose `template_key` matches, never removes anything, never resyncs. Custom roles do not get it; the company admin adds it in Administrasi > Peran. **Deploy never runs `db:seed` for roles** (`RolePermissionSeeder` is for fresh installs and tests).
- Company role administration (holders of `roles.manage`) has guards: cannot grant a permission you do not hold, cannot edit a more powerful role/user, SoD conflicts (`App\Support\SodConflicts`) per company, at least one active user keeps `roles.manage` + `app.web`, roles in use are reassigned before delete, all logged to the `security` log. Service: `CompanyRoleService`. Platform maintains templates (`RoleTemplateService`) and repairs company roles as audited support actions.
- **No signed-in user of that company** (API login, job, command, webhook): check permissions inside `CompanyService::runAs($companyId, fn)` or with `User::withPermissionIn($permissions, $companyId)` (query scope). Without a company no role matches (fail closed).
- **Notification audiences**: always `App\Support\Notifications\StaffRecipients::holding($companyId, $permission, $exceptUserId = null)`; it resolves active members of that company (home or `company_user`) who hold the permission there. Never build recipient lists from role names or `users.company_id`.
- User administration (view/update/delete incl. email, password, status) requires the actor to be a member of **every** company of the target (`CompanyMembershipService::administers`). Assigning roles needs an active membership of the role's company (`UserPolicy::assignRoles`).
- Policies: every record action. A scope denial answers a uniform 404 via `Response::denyAsNotFound()` or `App\Support\RecordNotFound` (same body whether the record is missing, foreign or hidden); a missing permission on a visible record stays 403.
- Frontend must also hide what the user cannot do (`usePermission`), but the server check is the control.
- Reference: `docs/SECURITY.md` section 2 (matrix, SoD invariants, release procedure), `docs/architecture/decisions/ADR-004-tenant-scoped-roles.md`.

## 8. Public ids

- Every record named in a URL, route parameter, Inertia prop or API payload is addressed by `public_id`, a 26-char lowercase ULID. The integer `id` is the primary key and every FK and **never leaves the server**.
- Model trait: `App\Traits\HasPublicId` (route key = `public_id`, fills the column on create, `scopeWherePublicId`). Generator: `App\Support\PublicId::new()` (random part per id; never `Str::ulid()`, which counts up inside a millisecond). Seeders and bulk inserts must use `PublicId::new()` / `PublicId::onInsert()`. Request mapping: `PublicId::resolve()` / `resolveOne()` (company-scoped, ULID strings only), `ResolvesPublicIds` and `PublicIdExists` in FormRequests.
- Routes constrain parameters with `whereUlid(...)` (see `routes/admin.php`); a non-ULID is a 404 before any query. Resources emit `public_id` as `id` and for every `*_id` reference.
- Human codes (`CUS-00001`, `INV-2026-00001`, `REQ-2026-00001`) are display values, not identifiers. A public id is not authorization: still run the policy.

## 9. Frontend (Inertia as islands)

Laravel owns routing, auth, permission and workflow. Each route renders one React page (`resources/js/Pages/**`). No SPA,
no Redux/Zustand/React Query, no client router. Server state arrives as Inertia props.

```
resources/js/
  app.tsx            Inertia bootstrap, ToastProvider, ErrorBoundary, X-Inbils-Company interceptor (axios + Inertia visits)
  Components/ui/     Primitives (Button, Input, Table, Card, Modal, Dialog, Badge, EmptyCell, EmptyState, Skeleton, Toast, ...), barrel index.ts, controlSize.ts
  Components/composite/  Business patterns: DataTable, DataTableActions, PageHeader, FormField, FormSection, ConfirmDialog, MoneyInput, NativeSelect, StatusFilter, NotificationBell, ...; subfolders ipam/ odn/ locations/ platform/ hardware-panel/ statuses/
  Layouts/           AdminLayout, GuestLayout, SetupLayout, PlatformLayout, PlatformAuthLayout
  Pages/             Admin/<Domain>/, Platform/, Portal/ (customer web portal), Auth/, Profile/, Setup/, Isolir/, Error.tsx, AppOnly.tsx
  hooks/             usePermission (= useCan), useCompany, useInertiaForm, useToast, useTheme, useServerTable, useTableQuery, useListVisitState, useNow, ...
  lib/               utils.ts (cn), format.ts, dateFormat.ts, filters.ts, copy/contrast/deadline helpers + *.selfcheck.mjs
  types/             TS interfaces mirroring Resources (index.ts exports PageProps)
```

Rules:

1. Pages compose `Components/ui/*` and `Components/composite/*`. **No raw `<button>`, `<input>`, `<table>`, `<select>` or hand-styled controls in `Pages/**`.** If a primitive is missing, add it to `Components/ui/` first (props-only: no fetch, no `lib/api`, no composite imports). Legacy Breeze components at `Components/*` root are not extended.
2. No duplicated styling in pages: variants live on the component (`cva`). No page-level `className` colour overrides on `Button`.
3. Forms: `useForm` from `@inertiajs/react` (or the toast wrapper `useInertiaForm`). Server validation errors come back in `errors`. Edit forms send `expected_updated_at` (see `OptimisticLock`).
4. Permissions in the UI: `const can = usePermission(); can('invoice.view')` (reads `auth.user.permissions` from shared props). Only to hide/disable; the server enforces.
5. Navigation: `<Link>` / `router` from Inertia and `route('admin.x.y')` from Ziggy (`@routes` in `app.blade.php`; the tenant shell and the platform shell get separate route groups, moving between them is a full page load). No hardcoded URLs. `resources/js/ziggy.js` is generated and git-ignored.
6. `axios` only for the few JSON helpers (quick-create dialogs, previews). Its `X-Inbils-Company` header is added by the interceptor in `app.tsx`. No raw `fetch` in `Components/ui`.
7. TypeScript strict, no `any` (ESLint warns), explicit prop interfaces, function components, named exports (pages may default-export), PascalCase file = component name, one component per file, `import type` for types, alias `@/` = `resources/js/`.
8. Icons: `lucide-react` only. No heroicons, no inline SVG icons.
9. Dark mode: every component supports `dark:` (class strategy on `<html>`, `@custom-variant dark` in `app.css`).
10. Colours: semantic tokens from `resources/css/app.css` (`brand`, `surface`, `success`, `warning`, `danger`, `--status-*`), never raw hex in components. Fills: `--primary`/`--destructive` with white label. **Text/links/borders in brand or red use `text-primary-text` / `border-primary-text` / `text-destructive-text` / `border-destructive-text`, never `text-primary` / `text-destructive`** (contrast: 4.5:1 text, 3:1 borders and icons, both themes). Check pairings with the contrast helper before inventing colours.
11. `Button` variants: `primary` (the one main action), `outline` / `secondary` (every other action), `destructive-outline` (delete-like secondary), `destructive` (confirmation only), `ghost` (icon-only toolbar). Aliases `default`, `danger`, `danger-outline` exist.
12. Touch targets: controls are 44px high below `sm` and on coarse pointers (`ui/controlSize.ts`). Text never below 12px. Visible `focus` ring, `hover`, `active`, `disabled` states on interactive elements. Semantic HTML, labels for inputs, `aria-*` for custom widgets.
13. Flat theme: no heavy gradients, static controls carry no shadow; overlays use at most `shadow-md`. Font Figtree, self-hosted (no CDN).
14. Empty values use `<EmptyCell />` (hyphen announced as "Kosong"). Lists and tables need loading, empty and error states; deferred props need a skeleton.
15. **Copy**: Indonesian UI text, sentence case (not uppercase tracking), **no em dash**, errors say what to do. Browser validation messages are localised by `lib/nativeValidity`. `node --test resources/js/lib/copy.selfcheck.mjs` scans converted files for English words.
16. Pagination, sorting and filters are server driven (`useServerTable` / `DataTable`), not client-side over a full list.
17. Format with Prettier (`.prettierrc`: 4 spaces, single quotes, width 100, tailwind class sorting) and keep ESLint clean.

## 10. Data and migrations

- MySQL only. Dev schema `inbils`; test schema `inbils_test` (section 12).
- Migration names: `YYYY_MM_DD_HHMMSS_create_<table>_table` / `add_<col>_to_<table>_table` / `<action>_<table>_table`. Core migrations are in `database/migrations/`, module ones in `Modules/<Name>/database/migrations/`. The newest existing file is dated `2026_11_03_100100_...` (date list sorted across both folders): **a new migration must sort after the newest one already shipped**, or it runs out of order on databases that migrated past it.
- Foreign keys are mandatory. Unique indexes and checks in the DB beat application validation. Tenant tables: `company_id` + per-company unique (section 6).
- Soft deletes only for historical entities (customers, addresses, work orders, invoices, tickets, products, network assets, subscriptions, ...). **`StockMovement` is immutable** (model throws on update/delete): corrections are new movements. Stock changes only through Inventory services/actions.
- **Additive, reversible migrations only** on shared or deployed databases. Never `migrate:fresh`, `migrate:refresh`, `db:wipe`, `DROP` on a dev, shared or production database unless the owner names that target. When modifying a column, restate all its previous attributes.
- Heavy new queries: eager load, select needed columns, paginate, index `where/join/order` columns, run `EXPLAIN` (or `--pretend`). No N+1: query-count tests exist (e.g. `tests/Feature/Admin/DashboardExceptionCountsTest.php`, `tests/Feature/Admin/OnboardingChecklistTest.php`); a test that depends on "now" calls `Carbon::setTestNow(...)` so a date boundary cannot change a count.
- Slow or bulk work (mail, PDF, exports, imports, external APIs) goes to queued jobs. Cache what is read often with an explicit invalidation.
- Schema reference and ERD: `docs/architecture/database.md`.

## 11. Mobile API v1

Consumed by the two Flutter apps (repo `inbils-mobile`, never inside this repo). Routes: `routes/api.php` and `Modules/*/routes/api.php`, prefix `api/v1`, names `api.v1.*`. Controllers `app/Http/Controllers/Api/V1/` and module `Http/Controllers/Api/V1/`. Contract: `docs/api/mobile-v1.openapi.json`.

| Topic | Rule |
| --- | --- |
| Auth | Sanctum bearer tokens. A token has exactly one ability: `customer` or `technician` (`ApiTokenService::CUSTOMER` / `TECHNICIAN`); lifetimes in `config/sanctum.php` `app_token_days` (customer 90, technician 30) |
| Middleware groups (`bootstrap/app.php`) | `api.session` = `auth:sanctum` + `api.active` + `api.app`; `api.any` = session + `api.idempotent`; `api.customer` / `api.technician` = `api.any` + `abilities:<app>`. Route files name only these groups. `api.app` re-checks app access on every request (a permission removed or profile deactivated stops the phone at once) |
| Public routes | Only `auth/login`, `auth/two-factor`, `auth/forgot-password` take no token |
| Error envelope | Every error under `api/*` is JSON `{message, code, errors?, request_id}` from `App\Exceptions\RenderApiError`; stable `code` values (`validation_failed`, `unauthenticated`, `forbidden`, `not_found`, `conflict`, `unprocessable`, `too_many_requests`, `server_error`, ...). Throw `App\Exceptions\ApiException` for a custom code. Never leak class names, SQL or traces |
| Idempotency | Every `POST/PUT/PATCH/DELETE` needs an `Idempotency-Key` UUID header (missing = 428 `idempotency_key_required`, not a UUID = 422). `EnforceIdempotency` caches the response for 24 h per user and key; replay returns the stored response; same key with another body = 422; in-flight duplicate = 409; 5xx, 429 and `Cache-Control: no-store` responses are not stored. Writes an outbox can replay later also persist the key in a durable column with a unique index per author (`idempotency_key` on ticket comments, inventory requests, evidence, material declarations) read from `EnforceIdempotency::KEY_ATTRIBUTE` |
| Ids | `public_id` ULIDs in paths and bodies (`whereUlid`); notification ids are UUIDs. An integer id answers 404 `not_found` (path) or 422 (body) |
| Tenant | Resolve the company from the token's user, check permissions inside `runAs`/`withPermissionIn` |
| Contract | After any change to an `api/v1` route, request or resource run `sail artisan scramble:export` and commit `docs/api/mobile-v1.openapi.json`. `tests/Feature/Api/V1/OpenApiContractTest.php` fails when the export is stale or a route is undocumented |
| Additive changes | Old app builds must keep working: add fields, do not rename or remove. Mention app impact in the CHANGELOG |

## 12. Dev environment

Primary path: Laravel Sail (`compose.sail.yaml`, selected by `SAIL_FILES` in `.env`). Full first-time steps: [README.md](README.md).

| What | Where |
| --- | --- |
| App (Sail) | <http://localhost> (port 80), health `/up`, readiness `/ready` (checks `database`, `cache`, `queue`, `failed_jobs`, `scheduler`, `backup`, `storage_link`, `mail`; `unknown` is normal until a heartbeat exists); `sail` runs PHP 8.5, Node 24 (image `sail-8.5/app`) |
| Mailpit | <http://localhost:8025> |
| MySQL (Sail) | `127.0.0.1:3306`; schema `inbils` for dev, `inbils_test` for tests (created by `docker/mysql/sail/10-create-test-database.sh` on first volume init) |
| Redis | `127.0.0.1:6379` (cache) |
| Vite HMR | <http://localhost:5173> while `sail npm run dev` runs |
| Deploy-like stack | `compose.yaml` + `.env.docker` (copy of `.env.docker.example`, git-ignored), app <http://localhost:8080>, MySQL `127.0.0.1:3307` |
| `*.test` proxy (optional) | `https://inbils.test` (Sail), `https://deploy.inbils.test` (8080 stack); `docker/devproxy/compose.yaml`, setup in `docs/operations/DOCKER.md` |

Rules and commands:

- Use `DOCKER_CONTEXT=default` for `docker compose` against the deploy stack and the proxy (the Docker Desktop context hides the root daemon's containers).
- Queue (`QUEUE_CONNECTION=database`): `sail artisan queue:work --queue=default,whatsapp --tries=3 --timeout=90`; scheduler: `sail artisan schedule:work`; or opt-in containers: `COMPOSE_PROFILES=workers ./vendor/bin/sail up -d`. Platform long jobs use their own worker: `sail artisan queue:work platform --queue=platform --tries=1 --timeout=3600`. A worker keeps the code it booted with: run `sail artisan queue:restart` after changing a job/listener/notification.
- `sail artisan storage:link` once (`/ready` reports `storage_link: fail` otherwise). Never let `public/storage` be a real directory.
- `sail exec -T laravel.test ...` runs as root, `sail artisan` and `sail exec -u sail` run as the `sail` user. Root-owned files under `storage/app/...` break host tools; use `-u sail` for tests.
- Do not run `sail:install` or `sail:add` (they rewrite `compose.yaml`). Do not put `APP_PORT` in `.env` (shared with `compose.yaml`).
- Never `down -v`, `docker volume rm` or `docker system prune --volumes` unless the owner explicitly wants data destroyed.
- The deploy stack is the verification target for image changes: `docker compose --env-file .env.docker build app queue scheduler platform-queue`, `up -d --no-deps` the same services, then `migrate --force` and check `/up`, `/ready`, `/platform/login`. Details and the backup step: `docs/operations/DEPLOYMENT.md`, `docs/operations/DOCKER.md`.
- PHP security releases: Sail takes them by rebuilding the image (`sail build --no-cache laravel.test && sail up -d`, check `sail php -v`); the deploy image takes them by `docker compose --env-file .env.docker build --pull ...` (the `Dockerfile` stops the build below PHP 8.5.12, so a stale cached base cannot ship). The Playwright E2E server and `docs:screenshots` run on the host's `php`, so the host needs its distribution's PHP update too.
- Temporary scan/debug/patch files belong outside the repo; delete AI scratch artefacts when done.
- Mail: `MAIL_*` in `.env` is the platform sender (credential mails always use it). Midtrans, WhatsApp, RADIUS, mail-per-company accounts are stored **per company** in Pengaturan > Integrasi (`company.integrations.manage`); the `.env` keys are one-time import sources (`inbils:integrations:import-env --company=<code>`). Firebase push uses `FIREBASE_CREDENTIALS` (service-account JSON outside git).
- Logs and request ids: `X-Request-Id` in every response; grep the id in logs (`docs/operations/OBSERVABILITY.md`).

## 13. Testing and quality gates

- **PHPUnit 11 only.** Write `PHPUnit` classes (`sail artisan make:test --phpunit Name`); convert any Pest test. Most tests are Feature tests. Use factories and their states, existing `tests/Traits/*` builders, `$this->faker` or `fake()` as the neighbours do. Never delete tests without approval. Cover happy path, failure path, edge cases, and a permission/tenant-isolation case for anything tenant-scoped.
- **Database**: automated tests, QA and E2E use MySQL schema **`inbils_test` only**. Never the dev schema `inbils`, never ad-hoc `inbils_*` schemas. The single exception is `artisan test --parallel`, which uses Laravel's deterministic worker schemas `inbils_test_test_<N>` (N = 1..4): they are created on first use and reused. **Never pass `--drop-databases` or `--recreate-databases`.** `phpunit.xml` pins host `mysql`, user `inbils`, `STORAGE_ENV_SUFFIX=/testing`; `Tests\TestCase::createApplication()` refuses to boot on any other target or with a cached config (`bootstrap/cache/config.php`), and gives each parallel worker its own storage tree `storage/app/{private,public}/testing_<N>`. Your tests extend `Tests\TestCase`.
- Serial, targeted (default while developing):
  ```bash
  ./vendor/bin/sail artisan test --compact --filter=SomeTest
  ./vendor/bin/sail artisan test --compact tests/Feature/Path/SomeTest.php
  ```
- Full suite (about 5400 tests, ~33 min with 3 workers, ~82 min serial). Check `uptime` load first (< 6), run as the `sail` user:
  ```bash
  flock /tmp/inbils-e2e.lock env DOCKER_CONTEXT=default ./vendor/bin/sail exec -T -u sail laravel.test \
    flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --parallel --processes=3 --compact
  ```
  Max 4 processes. A full run is the gate for changes that touch shared code; targeted runs for the rest.
- Time: a test that depends on "now" (billing periods, SLA, dashboards, query counts) must freeze it with `Carbon::setTestNow(...)` and reset it.
- PHP deprecations: Laravel drops them while testing. To see them (after a PHP or package upgrade) add `-e LOG_DEPRECATIONS_WHILE_TESTING=true -e LOG_DEPRECATIONS_CHANNEL=stderr` to `sail exec`: a serial run prints them in its output. `artisan test --parallel` swallows the workers' stderr, so there use `-e LOG_DEPRECATIONS_CHANNEL=single`, note the size of `storage/logs/laravel.log` first and read what was appended (`tail -c +<size+1> storage/logs/laravel.log | grep ' in /var/www/html/'`). Silence in the console of a parallel run proves nothing.
- Format and static analysis (PHP): `sail bin pint --dirty` (config `pint.json`, Laravel preset), `sail composer analyse` (PHPStan level 5 over `app/` and `Modules/`). PHPStan has no baseline file and reports about 585 findings (590 before the Laravel 13 upgrade, mostly model-property false positives): compare the list before and after your change instead of expecting zero.
- Frontend gates: `sail npm run typecheck`, `sail npm run lint`, `sail npm run format:check`, `sail npm run build` (`tsc && vite build`). `*.selfcheck.mjs` files next to libs run with `node --test <file>`.
- **Playwright E2E** (chromium, serial, host-run, canonical server on port 8010, schema `inbils_test`). One-time: `cp .env.e2e.example .env.e2e`, fill `DB_PASSWORD` (the deploy stack's MySQL `127.0.0.1:3307`, user `inbils`), `php artisan key:generate --env=e2e`, `npx playwright install chromium`. Run (never concurrently with PHPUnit or screenshots; the runner holds a mutex):
  ```bash
  flock /tmp/inbils-e2e.lock sh -c 'npm run build && node scripts/e2e-setup.mjs && npm run test:e2e -- --project=chromium --workers=1'
  ```
  Do not append a positional spec path: `scripts/e2e-canonical.mjs` forwards arguments to Playwright, which then reads them as a file filter. Use `--grep <text>` (or `npm run test:e2e:smoke` for `@smoke`). Use `node scripts/e2e-setup.mjs` to reset data, never `php tests/e2e/setup.php` directly. After a PHPUnit run on the same MySQL the demo users may be wiped: reset before Playwright.
- UI changes need browser proof (Playwright or manual) at 390px and desktop width, light and dark.
- A change is not done on "tests pass in my head": report the exact command and result.

## 14. Security checklist (apply on every change)

- Validate every input in a FormRequest; authorize in route, request and policy; never trust client ids, roles, prices, file paths.
- Parameterised queries only; no raw user input in `whereRaw`/`orderByRaw`/shell. No `dangerouslySetInnerHTML` or `{!! !!}` with user content.
- Secrets only in `.env` / `.env.docker` / per-company integration rows (encrypted at rest); never in source, fixtures, logs, docs, screenshots. `radius_secret` is excluded from flashed input.
- Uploads: validate mime and size; stored on private disks; media served through authorised routes (`api/v1/media/{media:public_id}`, `admin` media controller).
- Rate-limit auth and public write endpoints (named limiters in the service providers: `api-login`, `api-forgot-password`, `whatsapp-webhook`, `midtrans-webhook`, ...).
- Webhooks verify signatures and run inside the right company scope.
- Mass assignment guarded; audit sensitive actions through `AuditService` (log names such as `security`, `inventory`).
- Any change touching auth, permissions, payments, uploads or personal data needs a security review pass. Run `composer audit` and `npm audit` whenever `composer.json`, `package.json` or a lock file changes, and list every advisory (any severity) with the update command.
- Do not add a dependency for a few lines. Reuse the repo first; if a new one is justified, state package, version, maintenance, licence and size.
- Never read or print `.env*` files other than `*.example`.

## 15. Docs duty, changelog, git

**User-visible change = docs in the same change.** Owner rule:

1. Update the Indonesian guides in `docs/user-guide/`: `web.md` (admin, manager, staff), `platform.md` (operators), `teknisi.md` (technician app), `pelanggan.md` (customer app).
2. Retake affected screenshots. Web: `npm run build && npm run docs:screenshots` (screens listed in `scripts/docs-screenshots.json`; takes the E2E mutex, resets `inbils_test`, writes `docs/user-guide/images/web/<slug>.png`). App screenshots come from `inbils-mobile` (`flutter drive ... integration_test/docs_screenshots_test.dart`, see `docs/user-guide/README.md` section 2).
3. Render PDFs and commit them: `sail exec -T -u sail laravel.test php -d memory_limit=1G artisan inbils:docs:pdf` (`--only=web|platform|teknisi|pelanggan`); the cover is `resources/views/pdf/user-guide.blade.php`. Order: web shots, app shots, Markdown, PDF, commit.
4. Add a `CHANGELOG.md` entry at the top (Keep a Changelog; heading form `### Changed - <Title> (YYYY-MM-DD)`, bullets in English with `**Area.**` lead-ins, include migrations, API additions, deploy notes and docs touched). Update the ops docs in `docs/operations/` when deploy, jobs, env keys or the API contract change.
5. API change: regenerate `docs/api/mobile-v1.openapi.json` (section 11).

**`.gitignore` and docs.** `docs/*` is ignored; each tracked doc folder or file is re-allowed by an explicit `!` line (`!docs/operations/`, `!docs/architecture/overview.md`, `!docs/user-guide/`, ...). A **new** docs file is invisible to git until you add its allowlist line. Also ignored and local-only: `.env`, `.env.docker`, `.env.e2e`, `.env.testing`, `/vendor`, `/node_modules`, `/public/build`, `/public/storage`, `/resources/js/ziggy.js`, `/storage/*.key`, `/tests/e2e/.runtime`, `/playwright-report`, `/test-results`, and all AI tooling: `/AGENTS.md`, `/CLAUDE.md`, `/TASKS.md`, `/QA_REPORT.md`, `/boost.json`, `/opencode.json`, `/skills-lock.json`, `/.claude/`, `/.opencode/`, `/.rtk/`, `/.scratch/`, `/.superpowers/`, `/.tokensave/`, `/.agents`, `/.codex`, `/.hermes`, `.omp/`. Never force-add any of these. This file (`PROJECT_GUIDE.md`) and `README.md` are tracked.

**Git.** Default branch `main`. Conventional Commits (`feat(scope):`, `fix(scope):`, `docs(scope):`, `chore:`, `refactor:`, `test:`). Small focused commits; review the diff before committing; never commit secrets or `.env*` (only the `*.example` files). Never force-push shared branches. Do not commit, push or open PRs unless the owner asks. Never reset, stash or clean a dirty tree you did not dirty. No destructive DB commands (section 10).

## 16. Deep-doc index (tracked)

| Doc | Use it for |
| --- | --- |
| `README.md` | First-time setup on a new machine, daily commands, troubleshooting |
| `CHANGELOG.md` | History of every shipped change (latest first) |
| `docs/architecture/overview.md` | Layering, Inertia-as-Island, request flow, transactions, caching and queues |
| `docs/architecture/service-layer.md` | Service, Action, Query class and Resource responsibilities |
| `docs/architecture/domain-boundaries.md` | Naming table, dependency rules, cross-module communication, `module.json` map |
| `docs/architecture/modules.md`, `folder-structure.md` | Module structure (parts are older than the code; trust `Modules/` itself) |
| `docs/architecture/database.md` | Schema, ERD, `company_id` + per-company unique rules, seeding |
| `docs/architecture/decisions/ADR-001.md`, `ADR-002.md` | Early architecture decisions |
| `docs/architecture/decisions/ADR-003-platform-superadmin.md` | Platform admin identity, guard, separation from tenants |
| `docs/architecture/decisions/ADR-004-tenant-scoped-roles.md` | Company-scoped roles, templates, release migrations |
| `docs/SECURITY.md` | RBAC and SoD, 2FA, mobile API security, platform panel, public ids and 404 rules, audit, integrations |
| `docs/ENTERPRISE_ISP_READINESS.md` | Enterprise readiness assessment |
| `docs/business/<domain>.md` | Behaviour and state machines: customer, service, network, inventory, warehouse, procurement, spk, billing, finance, ticketing, crm, reporting, approval-flow |
| `docs/standards/*.md` | Short rules: coding, engineering, security, quality gates, git workflow |
| `docs/playbooks/*.md` | Generic checklists: new-module, new-crud, new-api, new-migration, new-report, new-service, bug-fixing, performance, testing |
| `docs/ui/*.md` | Design system, components, forms, tables, dashboard, responsive |
| `docs/api/mobile-v1.openapi.json` | The generated mobile contract |
| `docs/operations/DOCKER.md` | Sail vs production stacks, ports, parallel PHPUnit, `*.test` proxy, logs, rebuilds |
| `docs/operations/DEPLOYMENT.md` | Release and rollback procedure, per-release notes |
| `docs/operations/OBSERVABILITY.md` | Request ids, log files, `/up` and `/ready` checks |
| `docs/operations/JOBS_NOTIFICATIONS.md`, `RELIABLE_JOBS.md`, `platform-queue.md`, `push-notifications.md` | Queues, jobs, notifications, FCM |
| `docs/operations/BACKUP_RESTORE.md`, `RESTORE_DRILL_TEMPLATE.md`, `INCIDENT_ROLLBACK.md`, `SUPERVISION.md`, `tenant-purge.md` | Backup, restore, incidents, process supervision, tenant deletion |
| `docs/user-guide/README.md` | How guides, screenshots and PDFs are produced |
| `docs/user-guide/{web,platform,teknisi,pelanggan}.md` | The Indonesian user guides (PDFs in `docs/user-guide/pdf/`) |

## 17. Doc-vs-code conflicts (resolved here in favour of code)

- The global-roles wording in older notes (`teams=false` means roles are global) is **false**: roles are per company (`roles.company_id`, ADR-004); only the Spatie `teams` flag is off.
- `docs/architecture/domain-boundaries.md` forbids cross-module model imports and lists `Reporting` and most modules with fewer dependencies than the code has. In code, modules do import each other's `Models` (for example Inventory reads SPK and Ticketing models, Reporting reads six modules), and `module.json` does not list all of them. Treat the rule as the standard for **new** code (use services, actions, events); do not mass-refactor.
- `docs/standards/quality-gates.md` says only `build` and `dev` scripts exist; `lint`, `typecheck`, `format:check`, `test:e2e`, `docs:screenshots` exist now.
- `docs/architecture/folder-structure.md` and `modules.md` describe an older layout (`Entities/`, `Http/Requests/Admin` per module, 28 UI primitives); the real module layout is in section 4 and `Components/ui` has 50+ files.
- `docs/standards/` references a `delegation-rules.md` that does not exist.
- `docs/ui/design-system.md` is brief; the colour-token and Button-variant rules in section 9 come from `Components/ui/Button.tsx` and `resources/css/app.css`.
- Tools state `php artisan serve` on port 8000 in old docs; today use Sail (port 80) or the deploy stack (8080). The E2E server uses 8010.
- The `Customer` module is an empty shell; customer code is in `app/` (Customer, CustomerService, CustomerController).

## 18. Mistakes to avoid

1. Reading `users.company_id` as the tenant, or filtering by it. Use `CompanyService::currentId()` / `idFor()`.
2. Querying a tenant model in a job, listener, command or webhook without `CompanyService::runAs()` (empty results, or 403 on create).
3. Reaching for `withoutCompany()` / `forCompany()` in a tenant controller or service.
4. A migration for a tenant table without `company_id` FK and per-company unique indexes, or one that sorts before an already shipped migration.
5. Checking role names (`hasRole`, `role:` middleware). Check permissions; the architecture test will fail.
6. Adding a permission only to `PermissionCatalogue` and forgetting the `PermissionCatalogue::release()` migration (nobody in existing companies holds it), or running `db:seed` on a deployed system to "sync roles".
7. Building notification recipient lists by hand instead of `StaffRecipients::holding()`.
8. Treating a platform admin as a User, giving it a `company_id`, or letting it bypass a tenant policy.
9. Exposing integer ids or whole related models in Inertia props, Resources or API payloads; routes without `whereUlid`; numeric ids in URLs.
10. Missing `Idempotency-Key` handling for a new API write, a new API response shape without regenerating `docs/api/mobile-v1.openapi.json`, removing/renaming a field the apps read.
11. Raw `<button>/<input>/<table>` or custom-coloured buttons in pages; `text-primary` / `text-destructive` for text; raw hex colours; heroicons; an em dash or English labels in UI copy; uppercase tracked labels.
12. Adding Redux/React Query/fetch layers, or an axios call where an Inertia visit works.
13. Business logic in controllers, models or React components; a service without a transaction around multi-table writes; updating or deleting a `StockMovement`.
14. Running tests against `inbils`; passing `--drop-databases`/`--recreate-databases`; running more than 4 parallel processes; running PHPUnit, E2E and `docs:screenshots` at the same time (they share `inbils_test`).
15. `migrate:fresh`, `db:wipe`, `down -v`, `docker volume rm` on any non-throwaway database or volume.
16. Running `sail exec` as root for PHP tasks (root-owned files in `storage/`); forgetting `DOCKER_CONTEXT=default` for the deploy stack; putting `APP_PORT` in `.env`.
17. Appending a spec path to `npm run test:e2e` (Playwright reports "No tests found"; use `--grep`).
18. Query-count or date-sensitive tests without `Carbon::setTestNow`.
19. Putting a docs file under `docs/` and expecting it to be committed without an allowlist line in `.gitignore`; committing `AGENTS.md`, `CLAUDE.md`, `.scratch/`, `.env*`.
20. Changing UI text or flows without updating `docs/user-guide/*.md`, screenshots, PDFs and `CHANGELOG.md`.
21. Putting company-specific values (name, currency, timezone, payment keys) in code or `.env` instead of the company record and Pengaturan > Integrasi.
22. Editing the Flutter apps from this repo; they live in `inbils-mobile`.
23. Putting a model, collection, Carbon or DTO into the cache (it comes back as `__PHP_Incomplete_Class`); cache attribute rows and arrays, and version the key when the shape changes.

## 19. Before you call a change done

- [ ] Behaviour matches `docs/business/<domain>.md`; the diff touches only what the task needs.
- [ ] Tenant safe: `BelongsToCompany` on new models, `company_id` + per-company unique on new tables, `runAs` in jobs/commands, no `users.company_id` reads, a tenant-isolation test.
- [ ] Authorization: route `can:`, FormRequest, Policy, UI `usePermission`; permissions not role names; new permission has the `release()` migration; uniform 404 for hidden records.
- [ ] Public ids only in URLs, props and payloads; `whereUlid` on routes.
- [ ] Transactions around multi-table writes; no N+1 (eager load, paginate, indexes); slow work queued.
- [ ] API change: Idempotency/envelope honoured, `sail artisan scramble:export` run, OpenAPI file committed, additive only.
- [ ] Tests written or updated (happy, failure, edge, permission/tenant) and the affected ones run on `inbils_test` with the exact command and result noted; full parallel suite when shared code changed.
- [ ] `sail bin pint --dirty`, `sail composer analyse` (PHP) and `sail npm run typecheck && sail npm run lint && sail npm run build` (frontend) are clean.
- [ ] UI: ui/composite components only, tokens and button variants respected, dark mode, 44px targets, focus states, Indonesian sentence-case copy without em dash, checked at 390px and desktop; E2E or manual browser proof.
- [ ] User-visible change: user guides, screenshots, PDFs, `CHANGELOG.md` updated (section 15).
- [ ] Docs updated if a rule, env key, deploy step or architecture changed; new doc files allowlisted in `.gitignore`.
- [ ] `composer audit` / `npm audit` run when a manifest or lock file changed.
- [ ] Deploy impact noted (migrations, new env keys, queue restart, permission cache reset) and, for image changes, the 8080 stack rebuilt and `/up`, `/ready` checked.
- [ ] No secrets, `.env*`, AI tooling or temporary files in the diff; nothing committed or pushed unless asked.
