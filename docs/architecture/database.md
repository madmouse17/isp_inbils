# Database Schema — inbils

> Source of truth for schema conventions. Per-module entity detail:
> `docs/business/*.md`.

## Conventions

- Engine: MySQL 8 (Laragon dev). Charset utf8mb4, collation
  utf8mb4_unicode_ci.
- Every master/transaction table: `company_id FK → companies.id NOT
  NULL` + trait `BelongsToCompany` (global scope, auto-filter per
  effective company, FAIL-CLOSED: no company context = no rows, and a
  create without company_id aborts 403; system code names its company with
  `CompanyService::runAs()`). Exceptions: `companies`, `users` (company_id
  nullable pre-wizard), `permissions`, `role_templates`, `activity_log`
  (global). `roles` is NOT an exception: it carries `company_id` (see
  `roles` below).
- Business code (code/number/sku) = unique per company:
  `(company_id, code)`, NOT global unique.
- Soft delete (`deleted_at`) for master + transaction entities with
  lifecycle. NOT soft delete for: audit trail immutable
  (stock_movements, network_asset_installations, work_order_assignments,
  payments, activity_log).
- Timestamps: `created_at`, `updated_at` default. Audit trail tables =
  `created_at` + `updated_at` only (append-only).
- FK constraint: `ON DELETE RESTRICT` for parent master, `ON DELETE
  CASCADE` for child line items.
- Polymorphic reference: `reference_type` (string class name) +
  `reference_id` (bigint unsigned). No DB-level FK constraint, app-level
  validation.
- Decimal: `decimal(15,2)` for money/qty.
- Enum: MySQL enum + CHECK constraint (MySQL 8 supports CHECK).
- Index: composite always starts with `company_id` (tenant scope).
- Opaque ids (`public_id`): every table whose rows a URL, an API path or a
  payload names has `public_id char(26) UNIQUE NOT NULL`, a lowercase ULID.
  The integer `id` stays the primary key and every foreign key; only
  `public_id` leaves the server. Models use the trait `HasPublicId` (route key
  = `public_id`, filled on create; a route value that is not a ULID is a 404
  before any query). Package models (`media`, `activity_log`,
  `personal_access_tokens`) get the same id from the creating hooks in
  `AppServiceProvider`. Raw writes (seeders, bulk inserts) take
  `App\Support\PublicId::new()`. New tables put `$table->ulid('public_id')
  ->unique()` in their `create` migration; existing ones were converted by
  `PublicIdColumn::add()` (nullable column, chunked backfill, NOT NULL +
  unique). The list of tables is pinned by `PublicIdInventoryTest`, which also
  fails for a new `BelongsToCompany` model that lacks the trait.

## Master lifecycle (deactivate, delete, restore)

One mechanism covers the masters and the transactional records that can be hidden. A master is deactivated while anything
points at it and hard-deleted only when nothing ever did.

- Columns: `is_active` plus SoftDeletes (`deleted_at`). The only new flag is `units.is_active` (default true, index
  `(company_id, is_active)`). The models use `App\Traits\HasLifecycle` together with `SoftDeletes` and `HasPublicId`.
- `HasLifecycle` makes each model declare three things: the noun used in messages (`lifecycleNoun()`, for example "Profil
  bandwidth"), the `?status=` filters (`lifecycleFilters()`) and the references (`lifecycleReferences()`).
  `App\Services\Core\LifecycleService` does every write: `deactivate`, `activate`, `destroyIfUnreferenced`, `restore`.
  `deactivate` and `activate` only flip `is_active` (no cascade to children) and are no-ops when the flag already has that
  value. Both throw for a model without the flag.
- Routes: `App\Http\Controllers\Admin\LifecycleController::routes($uri, $model, $actions, $name)` registers
  `POST {uri}/{record}/{deactivate|activate|restore}` named `admin.{uri}.{action}` (the `{record}` is a ULID). The model comes
  from a route default, never from input, and the record is looked up through the `BelongsToCompany` scope, so another
  company's id answers 404. `deactivate` and `activate` authorize `update`, `restore` authorizes `restore`. Every policy
  maps `restore` to its `delete` rule, so whoever may delete may restore. Each action flashes "{Noun} dinonaktifkan.",
  "{Noun} diaktifkan." or "{Noun} dipulihkan.".
- Masters with `is_active` and the `deactivate`/`activate`/`restore` routes: `bandwidth_profiles`, `speed_profiles`,
  `sla_tiers`, `service_packages`, `products`, `categories`, `units`, `locations`, `customers`, `document_types`,
  `vehicles`, `organization_units`. `customer_addresses` have no `is_active`: they are hard-deleted when unreferenced and
  restored. Network assets follow the same hard delete rule (no `is_active`, status filter) and also gain `restore`; their
  delete adds the old guard (an installed or maintenance asset is refused) and releases its IP addresses. Tickets, work
  orders, invoices, employee profiles and employee evaluations only gain `restore`: their delete rules did not change (a
  ticket is deleted only when closed, an invoice only as a draft).
- Reference counts: `lifecycleReferences()` maps a label to a list of `table.column` pairs whose rows keep the record from
  a hard delete (for example `paket => service_packages.bandwidth_profile_id`). The count uses the query builder, so trashed
  children count too. A JSON path is allowed (`media.custom_properties->document_type_id` for document types). Index pages
  call `withReferenceCounts()`, one correlated `COUNT(*)` per column, and the resource exposes the result as `references`
  (`[{label, count}]`) next to `deleted_at`. `LifecycleReferencesMatchSchemaTest` fails when a RESTRICT, NO ACTION or SET
  NULL foreign key to a lifecycle table is not declared. The one allowlist entry is `ip_addresses.network_asset_id`, which a
  network asset delete releases instead of counting.
- Hard delete only when unreferenced: `destroyIfUnreferenced` locks the row first, counts again under the lock, then runs
  the entity's own check (products with stock above 0, installed or maintenance network assets, the customer address
  primary promotion) and `forceDelete()`. Any count above zero answers 422 on the key `lifecycle`:
  "{Noun} masih dipakai 3 paket, 12 langganan. Nonaktifkan saja." (entities without `is_active` end with ", jadi tidak bisa
  dihapus."). A foreign key error 1451 from a child inserted a moment later gives "{Noun} baru saja dipakai data lain."
  with the same ending. Success is audited as `force_deleted`; the other actions are audited as `deactivated`, `activated`
  and `restored`.
- Reserved keys: no unique index includes `deleted_at`, so a trashed or inactive row keeps its code or name reserved. The
  Store and Update requests of masters do not ignore trashed rows when checking uniqueness, so a duplicate is a field
  error and never a 500 from the index.
- Pickers and FormRequests of deactivatable masters accept only active, non-deleted rows, plus the value the record already
  holds. Deactivating a master therefore stops new use and leaves history alone.
- Status filter: every index reads `?status=` through the scope `lifecycleStatus()`. An empty or unknown value is the
  default list (SoftDeletes hides trashed rows). `aktif` and `nonaktif` filter `is_active`. A key of the entity's own status
  list filters its status column (`customer_status` for customers, `status` for tickets, SPK, invoices, employees and
  network assets). `dihapus` shows only trashed rows and `semua` shows trashed rows too. The UI select is `StatusFilter`:
  "Semua status", "Aktif", "Nonaktif", the entity statuses, "Dihapus", "Semua, termasuk dihapus".
- Restore: a locked, idempotent `restore()` (a live row is left alone). A row with `parent_id` whose parent is still trashed
  is refused ("{Noun} tidak bisa dipulihkan karena induknya, {nama}, masih dihapus. Pulihkan induknya dulu."). A unique
  violation is refused with "kode atau namanya sudah dipakai data lain". Restored `locations` and `organization_units`
  recompute their `path` from the parent's current path (`LocationService::recomputePath`,
  `OrganizationService::recomputePath`), because an ancestor may have been renamed or moved while they were deleted. A restored
  `customer_addresses` row settles `is_primary` and `is_installation_point`: a marker another address holds is cleared, and a
  marker nobody holds is set, so the customer keeps exactly one of each.

### Package versions

- `service_package_versions` is append-only (updating a row throws `LogicException`). Columns: company_id,
  service_package_id (cascade, the package owns its versions), version (1..n, unique with service_package_id), price_mrc,
  price_otc, bandwidth_profile_id, speed_profile_id, sla_tier_id, service_package_category_id (all RESTRICT), valid_from,
  timestamps. There is no `public_id`: a version is reached through its package or subscription. The next version's
  `valid_from` closes the previous one.
- The package row keeps the current terms, so catalog readers do not change. The `RecordsVersions` concern appends version
  n+1 on a model save that changes `price_mrc`, `price_otc`, `bandwidth_profile_id`, `speed_profile_id`, `sla_tier_id` or
  `service_package_category_id`; creating a package writes version 1. Changing the code, name, description, contract
  minimum or `is_active` is no new version. A query builder update or a raw insert skips the hook.
- `service_subscriptions.service_package_version_id` is NOT NULL, FK RESTRICT. A new subscription takes the package's latest
  version. `service_package_id` stays and both are always written together. Only
  `SubscriptionService::changePackage` moves a subscription to a newer version (see `docs/business/billing.md`).
- Reads that follow the version, not the live package: RADIUS rate limits, the ticket SLA tier, the package category filter
  of reports, and the API package terms of a subscription.

### Snapshot columns

Nullable columns with the suffix `_snapshot` keep a name as it was when the document reached its final state. Readers use
`snapshot ?? live value`, so an older row without one still shows the live value.

- `invoices.customer_name_snapshot`, `customer_address_snapshot`, `customer_tax_id_snapshot`, `package_name_snapshot`:
  written when the invoice leaves draft (`BillingService::send`, `generateForPeriod`) and when a draft is cancelled
  (`BillingService::cancel`). `Modules\Billing\Support\InvoiceParty` fills them once and never overwrites.
- `work_orders.technician_name_snapshot`, `location_code_snapshot` and `organization_unit_id` (FK RESTRICT, the technician's
  unit): written by `SpkService::approve` when the SPK becomes `completed`.
- `tickets.category_name_snapshot`, `assignee_name_snapshot`: written by `TicketService::close`.
- The migrations backfill existing rows once with the live values, only rows still NULL, in chunks of 500, so a run that
  stopped half way is repaired by running it again.

### History relations

Relations that history pages read use `->withTrashed()`, so a document still names a deleted parent. Examples: a subscription's
customer, package, installation address, serving POP and ONT; an invoice's customer, subscription and work order; a work
order's customer, subscription, location, ticket and organization unit; a ticket's category, customer, subscription, asset and
location; stock movements and stock rows (product, locations); every `ServicePackageVersion` relation. `EmployeeProfile::organization`
stays live because it drives report access. Pickers query the parent model directly and are unaffected.

## Tables by Module

### Core (app/Models/Core/)

- `companies` — id, parent_company_id (nullable self FK, RESTRICT; one
  level: Head → Subsidiary; self-parenting and depth are refused by
  `CompanyHierarchyService`, MySQL cannot CHECK against the auto-increment
  id), name, code, address, phone, email,
  tax_id, logo, timezone, settings (json), is_active. Unique: code (global).
  A Head = a company without parent that has at least one subsidiary.
  Written only by `CompanyHierarchyService` (artisan
  `inbils:company:create-subsidiary`).
- `users` — id, name, email, email_verified_at, password, company_id
  (nullable FK, the HOME company: where the user lands at login, not the
  effective tenant), is_active, remember_token, two_factor_secret
  (encrypted text), two_factor_recovery_codes (encrypted JSON array),
  two_factor_confirmed_at, two_factor_last_used_step (replay guard).
  Migration `2026_10_12_500001`; secrets are never fillable, hidden on
  serialisation and excluded from the activity log (see
  `docs/SECURITY.md` §3). Unique: email. Soft delete.
- `company_user` — id, company_id FK, user_id FK (both CASCADE), is_default,
  timestamps. Unique `(company_id, user_id)`; `is_default` marks the home
  company and is unique per user through the generated column
  `default_user_id`. Migration `2026_10_11_000003` backfills one default row
  per user with a company_id (reversible). The home company is a member even
  without a row; any other company needs a row and `companies.is_active`.
  Effective company = session `active_company_id` validated against this
  table on every read, else the home company (`CompanyService`). Roles are
  per company: a user holds roles in each company he belongs to.
- `roles` — spatie table plus `company_id` FK → `companies.id` NOT NULL
  (CASCADE) and `template_key` varchar(32) NULL (the template the role was
  copied from; provenance only, survives renames). Unique `(company_id, name,
  guard_name)` and `(company_id, template_key)`. Model `App\Models\Role` uses
  `BelongsToCompany` (spatie stays `teams=false`); platform code opts out
  with `Role::withoutCompany()`. Written through `CompanyRoleService`.
  `model_has_roles` and `role_has_permissions` stay spatie standard; a
  user's roles in company C are the pivot rows whose role has `company_id = C`.
- `permissions` — spatie standard. Global: one catalogue in code
  (`App\Support\PermissionCatalogue`), never created by tenants.
- `role_templates` (id, public_id, `key` unique and immutable, `name`
  unique, timestamps) and `role_template_has_permissions` (role_template_id,
  permission_id, CASCADE). Platform-owned, no `company_id`. Copied into a
  company when it is created; never resynced. A release that adds a permission
  adds it to the templates and to the company copies by `template_key` with
  `PermissionCatalogue::release()` in a migration. Created by migrations
  `2026_10_29_100000` to `2026_10_29_100500` (the last makes `roles.company_id` NOT NULL).
- `activity_log` — spatie standard. Global. Polymorphic.
- `locations` — company_id, parent_id (self-ref), code, name, type
  (region/area/pop/rack/site), path, is_active.
- `customers` — company_id, code, name, type (individual/company),
  tax_id, phone, email, address, is_active. Soft delete.
- `customer_addresses` — customer_id, label, address, city, regency,
  province, postal_code, is_default. Soft delete.
- `customer_contacts` — customer_id, name, phone, email, role,
  is_primary. Soft delete.
- `service_subscriptions` — company_id, customer_id, service_package_id,
  service_package_version_id (NOT NULL, FK RESTRICT, the version it was sold
  under), code, status (active/suspended/terminated), activation_date,
  suspension_date, billing_day, ont_asset_id (FK network_assets,
  exception D-R5). Soft delete.
- `employee_evaluations` — company_id, employee_id, reference_type,
  reference_id, rating, notes, evaluated_at.

### Service (Modules/Service/Models/)

- `service_packages` — company_id, name, type, mrc, otc, description,
  is_active. Soft delete. Holds the current terms; a change of price, profile,
  SLA tier or category also appends a row to `service_package_versions`.
- `service_package_versions`: company_id, service_package_id (cascade),
  version, price_mrc, price_otc, bandwidth_profile_id, speed_profile_id,
  sla_tier_id, service_package_category_id, valid_from. Append-only (no
  update, no soft delete). Unique: (service_package_id, version). See
  "Package versions".
- `bandwidth_profiles` — company_id, name, down_mbps, up_mbps,
  is_active. Soft delete.
- `speed_profiles` — company_id, name, down_mbps, up_mbps,
  is_active. Soft delete.
- `sla_tiers` — company_id, name, response_time_hours,
  resolution_time_hours, is_active. Soft delete.

### NetworkAsset (Modules/NetworkAsset/Models/)

- `network_assets` — company_id, serial_number, mac_address,
  ip_address, type, model, brand, status (in_stock/installed/
  maintenance/retired), location_id, customer_id, subscription_id.
  Unique: (company_id, serial_number). Soft delete.
- `network_asset_installations` — network_asset_id, location_id,
  customer_id, subscription_id, spk_id, installed_at, removed_at.
  Append-only (no soft delete).

### Inventory (Modules/Inventory/Models/)

- `products` — company_id, sku, name, category_id, unit_id,
  description, is_active. Unique: (company_id, sku). Soft delete.
- `categories` — company_id, name, parent_id (self-ref), is_active.
  Soft delete.
- `units` — company_id, name, symbol, is_active. Soft delete.
- `stocks` — company_id, product_id, location_id, quantity.
  Unique: (company_id, product_id, location_id).
- `stock_movements` — company_id, product_id, from_location_id,
  to_location_id, quantity, reference_type, reference_id, type
  (receive/issue/transfer/adjust/return), created_at. Append-only
  (immutable, no soft delete).

### SPK (Modules/SPK/Models/)

- `work_orders` — company_id, code, type (install/maintain/upgrade/
  relocate), status (pending/assigned/in_progress/completed/cancelled),
  customer_id, subscription_id, location_id, assigned_to, scheduled_at,
  started_at, completed_at, priority, notes, organization_unit_id (FK
  RESTRICT, technician's unit at completion), technician_name_snapshot,
  location_code_snapshot (written by `approve`). Unique: (company_id, code).
  Soft delete.
- `work_order_items` — work_order_id, product_id, quantity, type
  (issue/return). Cascade delete.
- `work_order_assignments` — work_order_id, user_id, role, assigned_at.
  Append-only.
- `work_order_evidence` — work_order_id, file_path, type, notes.

### Billing (Modules/Billing/Models/)

- `invoices` — company_id, number, customer_id, subscription_id,
  type (mrc/otc/adjustment), status (draft/sent/paid/overdue/
  cancelled), issue_date, due_date, subtotal, tax_amount, total,
  paid_amount, notes, customer_name_snapshot, customer_address_snapshot,
  customer_tax_id_snapshot, package_name_snapshot (written when the invoice
  leaves draft, see "Snapshot columns"), active_recurring_period (generated,
  see below). Unique: (company_id, number). Soft delete.
  `active_recurring_period` is a STORED generated date: `billing_period_start`
  while the invoice is `recurring`, not `cancelled` and not deleted, else NULL.
  Unique `(company_id, subscription_id, active_recurring_period)` is the
  backstop against two active recurring invoices for one subscription and
  period. NULL never collides, so manual and one-time invoices, and cancelled
  or deleted ones, are free.
- `invoice_items` — invoice_id, description, quantity, unit_price,
  total. Cascade delete.
- `payments` — company_id, invoice_id, amount, method, paid_at,
  reference_no. Append-only (no soft delete).

### Ticketing (Modules/Ticketing/Models/)

- `tickets` — company_id, code, customer_id, category_id,
  network_asset_id, subscription_id, title, description, status
  (open/assigned/in_progress/resolved/closed), priority, sla_deadline,
  assigned_to, resolved_at, closed_at, category_name_snapshot,
  assignee_name_snapshot (written by `close`, see "Snapshot columns").
  Unique: (company_id, code). Soft delete.
- `ticket_categories` — company_id, name, sla_hours, priority_default,
  is_active. Soft delete.
- `ticket_comments` — ticket_id, user_id, body, is_internal. Cascade
  delete.
- `ticket_attachments` — ticket_id, file_path, filename, mime.

### Reporting (Modules/Reporting/)

- NO migrations. NO writes. Query classes are the read path
  (`TechnicianPerformanceQuery`, `BusinessMetricsQuery`,
  `AssetUtilizationQuery`, `SlaComplianceQuery`, `StockCardQuery`,
  `AuditLogQuery`, `SalesKpiQuery`).
- One read-only model exception: `SalesCustomer` (table `customers`) declares
  the Billing relations (`invoices`, `payments`) the Sales KPI aggregates with
  Eloquent `withSum`/`withCount`, so Core `Customer` never depends on Billing.
  It refuses `save`/`delete`.
- Exception to "no direct cross-module Model import" rule.

## ERD (textual — core relations)

```
companies 1──N companies (parent_company_id: Head → subsidiaries)
companies 1──N users
companies N──N users (company_user: membership)
companies 1──N locations (self-ref parent)
companies 1──N customers
customers 1──N customer_addresses
customers 1──N customer_contacts
customers 1──N service_subscriptions
service_packages 1──N service_package_versions
service_package_versions 1──N service_subscriptions
service_packages 1──N service_subscriptions
network_assets N──1 locations
network_assets N──1 customers (installed at)
network_assets N──1 service_subscriptions (linked)
customers 1──N work_orders
service_subscriptions 1──N work_orders
work_orders 1──N work_order_items
work_orders 1──N work_order_assignments
work_orders 1──N work_order_evidence
customers 1──N invoices
service_subscriptions 1──N invoices
invoices 1──N invoice_items
invoices 1──N payments
customers 1──N tickets
ticket_categories 1──N tickets
tickets 1──N ticket_comments
tickets 1──N ticket_attachments
```

## Index Strategy

- Composite index always starts with `company_id`.
- FK auto-indexed.
- Unique per-company: `(company_id, code)`, `(company_id, serial_number)`,
  `(company_id, sku)`, `(company_id, number)`.
- MySQL index name limit: 64 chars. Use custom short names for long
  composite indexes.

## Migration & Seed Strategy

- Migration order: Core → Service → NetworkAsset → Inventory → SPK →
  Billing → Ticketing → Reporting.
- Seed: permission catalogue + role templates + system settings ONLY. NO
  company data and NO roles; a company gets its roles from the templates when it is created.
- Company created via Setup Wizard (`php artisan inbils:setup`).
- Per-company master data seeded by `CompanySeeder::runFor($company)`
  triggered by `CompanyCreated` event (units, ticket_categories,
  sla_tiers, sample locations).
