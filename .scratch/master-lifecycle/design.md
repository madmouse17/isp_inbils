# Design: master-lifecycle (deactivate instead of delete, restore, versions, frozen history)

Status: design for review · Spec: `.scratch/master-lifecycle/spec.md` (owner decisions D1–D6 FINAL, 2026-10-17) · Evidence: `local://delete-matrix.md` · Author: architect `MasterLifecycleDesign` · 2026-10-18

## 0. Summary

- One mechanism for every in-scope entity:
  - trait `App\Traits\HasLifecycle` (filter scope, reference counts, resource fields);
  - `App\Services\Core\LifecycleService` (deactivate, activate, destroyIfUnreferenced, restore);
  - one generic `App\Http\Controllers\Admin\LifecycleController`, whose static `routes()` registers `POST {uri}/{record}/{deactivate|activate|restore}` per entity.

  Entity controllers keep only `destroy()` (3 lines) and the index `status` filter.
- A row is hard-deleted only when `countReferences()` returns zero. The count uses the query builder, so trashed children count, and it runs inside `DB::transaction` + `lockForUpdate`. FK error 1451 from a concurrent child insert maps to the same 422. Referenced masters can only be deactivated.
- UI: a new composite `StatusFilter` (one `?status=` select) and a `lifecycle` variant on the existing `DataTableActions`. The variant offers Nonaktifkan / Aktifkan / Pulihkan / Hapus permanen through one confirm-dialog pattern and shows the server error inside the dialog.
- Packages get versions:
  - New append-only table `service_package_versions` holds price_mrc, price_otc, bandwidth, speed, SLA tier and package category.
  - The package row keeps the current terms, so catalog readers are unchanged.
  - A model hook appends a version when a term changes.
  - Subscriptions get `service_package_version_id` (NOT NULL, FK restrict) on create.
  - Changing a subscription's package becomes the explicit, audited action `POST admin/subscriptions/{subscription}/change-package`.
  - Billing already reads `mrc_amount` and does not change.
- Snapshots (all backfilled once, additively and idempotently; the PDF and API read them):
  - `invoices.{customer_name,customer_address,customer_tax_id,package_name}_snapshot`, written when an invoice leaves draft (`BillingService::send`, `generateForPeriod`);
  - `work_orders.{technician_name,location_code}_snapshot` + `organization_unit_id`, written in `SpkService::approve`;
  - `tickets.{category_name,assignee_name}_snapshot`, written in `TicketService::close`.
- Reports:
  - the package-category filter reads the version's category;
  - ticket-category groupings use `COALESCE(tickets.category_name_snapshot, ticket_categories.name)`;
  - drill rows and receivables read the snapshots;
  - history relations get `->withTrashed()` (list in §4.8).
- Waves:
  - W1: mechanism, UI primitives, versions, snapshot migrations (4 slices).
  - W2: per-module adoption (15 slices).
  - W3: reports, docs + OpenAPI + user guide, E2E (3 slices).
  - Files shared with the `integrity-hardening` lane are second-owner (§8.4).

## 1. Problem statement

The matrix (`local://delete-matrix.md`) shows three classes of defect:

1. **Soft delete bypasses the FKs.**
   - `BandwidthProfileController.php:63-69`, `SpeedProfileController.php:63-69` and `SLATierController.php:67-73` soft-delete rows that packages still use (flow-audit F2-D04): the package shows "-".
   - Other guards check only part of the children: `ServicePackageController.php:204-211` (active/pending subscriptions only), `ProductController.php:97-107` (stock > 0 only), `LocationService.php:163-182` (assets/stock only).
   - No race is closed, because `restrictOnDelete` never fires on a soft delete.
2. **Deleted rows are invisible and cannot come back.** There is no `onlyTrashed`, no restore route, and no status filter on most index pages (grep: 0 `onlyTrashed`, 0 `->restore(`).
3. **Edits leak into history.**
   - A package price, speed or SLA edit changes what every existing subscription shows.
   - RADIUS reads `subscription.servicePackage.speedProfile` (`NetworkAccountProvisioner.php:302,330`).
   - Ticket SLA reads `subscription.servicePackage.slaTier` (`TicketService.php:110-111`).
   - Invoices print the live customer name (`resources/views/pdf/invoice.blade.php:48-55`).
   - Reports group by live names: `SlaSources` and `TechnicianSources` by `ticket_categories.name`; the package-category filter uses live package membership (`ScopesToPackageCategory.php:14-28`).
   - History relations lack `withTrashed()`: only 4 relations have it (ReportScout §7).

Goal: satisfy D1–D6 with the smallest change.
- D1: deactivate when referenced; hard delete only when never referenced, with the DB as backstop.
- D2: status filter + Pulihkan.
- D3: package versions + explicit migration.
- D4: frozen documents.
- D5: reports by snapshot/version.
- D6: `withTrashed` on history relations.

## 2. Affected modules

| Area | Paths (existing unless NEW) | Change |
|---|---|---|
| Core shared | NEW `app/Traits/HasLifecycle.php`, NEW `app/Services/Core/LifecycleService.php`, NEW `app/Http/Controllers/Admin/LifecycleController.php`, NEW `app/Support/AddressLine.php` | Mechanism, generic routes, address formatter |
| Core entities | `app/Models/Core/{Location,Customer,CustomerAddress,CustomerContact,DocumentType,Vehicle,OrganizationUnit,EmployeeProfile,EmployeeEvaluation,ServiceSubscription}.php`, matching controllers in `app/Http/Controllers/Admin/`, resources in `app/Http/Resources/`, policies in `app/Policies/`, `app/Services/Core/{LocationService,OrganizationService,SubscriptionService}.php`, `routes/admin.php` | Adoption, change-package, history relations |
| Service | `Modules/Service/Models/*`, `Modules/Service/Http/{Controllers,Requests,Resources}/*`, `Modules/Service/Policies/*`, `Modules/Service/routes/web.php`, `Modules/Service/app/Services/SlaTierCompliance.php`, NEW versions model/concern/resource/backfill | Lifecycle + versions |
| Inventory | `Modules/Inventory/app/{Models,Http,Policies}/…`, `Modules/Inventory/routes/web.php` | Lifecycle, units `is_active`, history relations |
| NetworkAsset + Ipam | `Modules/NetworkAsset/app/{Models,Http,Policies,Services,Console,Observers}/…`, `Modules/Ipam/app/Models/{IpAddress,IpPrefix,Vlan}.php` | Lifecycle; provisioning reads the version |
| Billing | `Modules/Billing/app/{Models,Services,Http,Policies,Queries}/…`, `resources/views/pdf/invoice.blade.php`, NEW `Modules/Billing/app/Support/InvoiceParty.php` | Invoice snapshot + filter + restore |
| SPK | `Modules/SPK/app/{Models,Services,Http,Policies}/…`, `Modules/SPK/routes/web.php` | Completion snapshot + filter + restore |
| Ticketing | `Modules/Ticketing/app/{Models,Services,Http,Policies}/…`, `Modules/Ticketing/routes/web.php` | Close snapshot, SLA from version, filter + restore |
| Reporting | `Modules/Reporting/app/{Queries,Drill,Registry}/…` | Snapshot/version grouping |
| Frontend | `resources/js/Components/composite/{DataTableActions,StatusFilter(NEW),index,CustomerAddressEditor}.tsx/.ts`, `resources/js/types/models.ts`, `resources/js/lib/statuses/common.ts`, 26 pages (§5.3) | Filter + row actions + versions/snapshots display |
| Data | 6 NEW migrations (§3.4), `database/seeders/{CompanySeeder,E2eFixtureSeeder}.php` | Additive schema + idempotent backfill |
| Docs/API | `docs/api/mobile-v1.openapi.json` (regenerated via scramble), `docs/user-guide/web.md`, `docs/architecture/database.md`, `docs/business/{billing,ticketing}.md`, `CHANGELOG.md` | Contract + guide |
| Tests | 15 NEW PHPUnit files, NEW `tests/e2e/master-lifecycle.spec.ts`, a few existing tests (§6) | Coverage |

Not touched (D1 "keep current guards"):
- users/roles, VLAN, IP prefix, ODN, announcements, promo banners, payments, stock movements, activity log;
- `service-package-categories` keeps its guard; only its count query changes (§4.4);
- `ticket_categories` and `service_subscriptions` have no delete path, so neither gets a new filter; the subscription status filter already exists.

## 3. Data model / migrations

### 3.1 Lifecycle columns

No new lifecycle columns, with one exception: `units.is_active` (boolean, default true). Units are listed as masters but lack the flag (MastersScoutA §7). Everything else uses the existing `is_active` + `deleted_at` (SoftDeletes).

Unique indexes stay as they are. Every unique key excludes `deleted_at` (MastersScoutA/B), so trashed and inactive rows keep their code/name reserved. A restore can therefore never collide with a DB unique index (see OD-4, R1).

### 3.2 Package versions (D3)

NEW table `service_package_versions` (Service module). Rows are append-only and immutable.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| company_id | FK companies, restrictOnDelete, cascadeOnUpdate | `BelongsToCompany` |
| service_package_id | FK service_packages, **cascadeOnDelete** | Versions are owned by the package; a referenced version still blocks deletion through the subscription FK |
| version | unsigned int | 1..n per package; `unique(service_package_id, version)` |
| price_mrc | decimal(15,2) | |
| price_otc | decimal(15,2) default 0 | |
| bandwidth_profile_id | FK bandwidth_profiles, restrictOnDelete | |
| speed_profile_id | FK speed_profiles, restrictOnDelete | |
| sla_tier_id | FK sla_tiers, restrictOnDelete | |
| service_package_category_id | FK service_package_categories, restrictOnDelete | Versioned term (OD-1) |
| valid_from | timestamp | When this version became current |
| timestamps | | |
| index | (company_id, service_package_category_id) | Report filter |

No `public_id`: versions are never routed or emitted as `id` (the React key is `version`). No `valid_to`: the next version's `valid_from` closes the previous one.

`service_subscriptions` gets `service_package_version_id`:
- bigint, NOT NULL after backfill;
- FK `service_package_versions` restrictOnDelete;
- placed after `service_package_id`.

`service_package_id` stays because many readers filter by it. Both columns are always written together.

**Package edit semantics** (`ServicePackage` model hook, §4.5):
- If any of the `VERSIONED_TERMS` changed (price_mrc, price_otc, bandwidth_profile_id, speed_profile_id, sla_tier_id, service_package_category_id):
  - the package row is updated in place (current catalog terms);
  - version n+1 is appended with `valid_from = now()`;
  - existing subscriptions keep their version.
- Otherwise (code, name, description, contract_min_months, is_active): updated in place, no version (D3: "renaming stays in place").

### 3.3 Snapshot columns (D4/D5)

All columns are nullable. The `_snapshot` suffix follows the precedent `network_asset_installations.asset_code_snapshot` (`2026_09_07_110002_harden_network_asset_installation_retention.php:18-19`).

| Table | Column | Type | Written when |
|---|---|---|---|
| invoices | customer_name_snapshot | string(255) | invoice leaves draft |
| invoices | customer_address_snapshot | text | same |
| invoices | customer_tax_id_snapshot | string(255) (= customers.tax_id) | same |
| invoices | package_name_snapshot | string(255) | same (null without a subscription) |
| work_orders | technician_name_snapshot | string(255) | status → completed |
| work_orders | location_code_snapshot | string(255) | same |
| work_orders | organization_unit_id | FK organization_units, nullable, restrictOnDelete | same (technician's unit at completion) |
| tickets | category_name_snapshot | string(255) | status → closed |
| tickets | assignee_name_snapshot | string(255) | same |

### 3.4 Migrations

All new migrations sort after the newest existing one, `database/migrations/2026_10_22_200000_rewrite_notification_ids_to_public_ids.php`.

Each one follows `app/Support/Database/PublicIdColumn.php`:
- every step first checks what already exists (`Schema::hasTable/hasColumn/hasIndex/getForeignKeys`);
- backfills touch only rows still `NULL`, in `chunkById(500)`;
- a run that stopped half way is repaired by running it again;
- `down()` reverses the DDL.

| # | File (NEW) | up() | down() |
|---|---|---|---|
| M1 | `Modules/Service/database/migrations/2026_10_23_100000_create_service_package_versions_table.php` | create table if missing; `ServicePackageVersionBackfill::versions()` | drop table |
| M2 | `database/migrations/2026_10_23_100100_add_service_package_version_id_to_service_subscriptions_table.php` | add nullable column if missing → `ServicePackageVersionBackfill::subscriptions()` → `->nullable(false)->change()` → FK restrict if missing | drop FK if present, drop column |
| M3 | `Modules/Billing/database/migrations/2026_10_23_100200_add_snapshot_columns_to_invoices_table.php` | add 4 columns if missing; backfill rows where `status <> 'draft' AND customer_name_snapshot IS NULL` | drop columns |
| M4 | `Modules/SPK/database/migrations/2026_10_23_100300_add_snapshot_columns_to_work_orders_table.php` | add 3 columns (+FK) if missing; backfill rows where `status = 'completed' AND technician_name_snapshot IS NULL` | drop FK, columns |
| M5 | `Modules/Ticketing/database/migrations/2026_10_23_100400_add_snapshot_columns_to_tickets_table.php` | add 2 columns if missing; backfill rows where `status = 'closed' AND category_name_snapshot IS NULL` | drop columns |
| M6 | `Modules/Inventory/database/migrations/2026_10_23_100500_add_is_active_to_units_table.php` | add `is_active` boolean default true + index (company_id, is_active) if missing | drop index, column |

Backfill rules ("current live values", D5). All of them use `DB::table` only: no company context, no models, no scopes, and trashed parents are included.
- **M1 `ServicePackageVersionBackfill::versions()`** (NEW `Modules/Service/app/Services/ServicePackageVersionBackfill.php`): one `INSERT … SELECT` of version 1 from each package's current terms.
  - `valid_from = COALESCE(service_packages.created_at, NOW())`.
  - Guard: `WHERE NOT EXISTS (SELECT 1 FROM service_package_versions v WHERE v.service_package_id = service_packages.id)`.
  - Trashed packages are included.
- **M2 `…::subscriptions()`**: in chunks, set `service_package_version_id` to the latest version id of `service_package_id` where it is NULL.
- **M3**: per chunk, load with `whereIn`:
  - customers (name, tax_id);
  - subscriptions (installation_address_id, service_package_id);
  - addresses (the subscription's installation address, else the customer's `is_primary` address, non-deleted first);
  - package names.

  `customer_address_snapshot = AddressLine::of($address)`.
- **M4**: per chunk, `UPDATE work_orders LEFT JOIN users ON users.id = assigned_to LEFT JOIN locations ON locations.id = location_id LEFT JOIN employee_profiles ON employee_profiles.user_id = assigned_to SET …`.
- **M5**: per chunk, `UPDATE tickets JOIN ticket_categories … LEFT JOIN users ON users.id = assigned_to SET …`.

Raw inserts that bypass model hooks must set the new NOT NULL column. Grep found exactly these:
- `database/seeders/CompanySeeder.php:241` (raw package insert): call `ServicePackageVersionBackfill::versions()` after it.
- `database/seeders/E2eFixtureSeeder.php:995` (subscriptions `updateOrInsert`): add `'service_package_version_id' => DB::table('service_package_versions')->where('service_package_id', $packageId)->orderByDesc('version')->value('id')`.
- `tests/Feature/CustomerStatusBackfillMigrationTest.php:56` (raw subscription insert): set the version id the same way.
- `tests/Feature/Service/ServicePackageCategoryMigrationTest.php:175` raw-inserts packages without subscriptions, so it needs no change. W1-VERSIONS confirms by running it.

**Live dev DB `inbils`:**
- Run only `./vendor/bin/sail artisan migrate` (additive). Never `migrate:fresh/refresh`, `db:wipe` or seeders.
- Take a `mysqldump` of `inbils` first.
- Afterwards, run these read-only checks via Boost `database-query`. Each must return 0:
```sql
SELECT COUNT(*) FROM service_packages p WHERE NOT EXISTS (SELECT 1 FROM service_package_versions v WHERE v.service_package_id = p.id);
SELECT COUNT(*) FROM service_subscriptions s JOIN service_package_versions v ON v.id = s.service_package_version_id WHERE v.service_package_id <> s.service_package_id;
SELECT COUNT(*) FROM invoices WHERE status <> 'draft' AND customer_name_snapshot IS NULL;
SELECT COUNT(*) FROM work_orders WHERE status = 'completed' AND assigned_to IS NOT NULL AND technician_name_snapshot IS NULL;
SELECT COUNT(*) FROM tickets WHERE status = 'closed' AND category_name_snapshot IS NULL;
```

## 4. Contracts (backend)

### 4.1 `App\Traits\HasLifecycle` (NEW `app/Traits/HasLifecycle.php`, PHP 8.2 trait constant)

```php
trait HasLifecycle
{
    /** ?status= values of a master with an is_active flag. */
    public const ACTIVE_FILTERS = ['aktif' => ['is_active', true], 'nonaktif' => ['is_active', false]];

    /** Sentence-case noun for messages and dialogs: 'Profil bandwidth'. */
    abstract public static function lifecycleNoun(): string;

    /**
     * ?status= values besides `dihapus` and `semua`: value => [column, stored value].
     * Masters with is_active include self::ACTIVE_FILTERS; entities with a lifecycle column add
     * self::statusFilters('status', self::STATUSES).
     * @return array<string, array{0: string, 1: bool|string}>
     */
    abstract public static function lifecycleFilters(): array;

    /**
     * Masters: label => list of "table.column" (JSON path allowed: "media.custom_properties->document_type_id")
     * whose rows keep this record from a hard delete. Must cover every RESTRICT/NO ACTION/SET NULL FK to the table
     * (LifecycleReferencesMatchSchemaTest). Cascade children that are history are listed too.
     * Null: the record keeps its own soft-delete rules (tickets, SPK, invoices, employees, evaluations).
     * @return array<string, list<string>>|null
     */
    abstract public static function lifecycleReferences(): ?array;

    public static function isDeactivatable(): bool;                 // isset(static::lifecycleFilters()['aktif'])
    protected static function statusFilters(string $column, array $values): array; // value => [column, value]

    /** '' or non-string = default (SoftDeletes hides trashed); 'dihapus' onlyTrashed; 'semua' withTrashed;
     *  a key of lifecycleFilters() → where(qualified column, value); unknown value = default. */
    public function scopeLifecycleStatus(Builder $query, mixed $status): Builder;

    /** Adds one correlated COUNT(*) sub-select per reference column, aliased `reference_{i}_{j}`. The inner table is
     *  aliased `lifecycle_ref` (self-references such as locations.parent_id would otherwise compare a row with itself).
     *  Selects `{table}.*` first when the query has no columns (same as withCount). */
    public function scopeWithReferenceCounts(Builder $query): Builder;

    /** @return list<array{label: string, count: int}>|null  from the sub-selects; null when they did not run */
    public function referenceCounts(): ?array;

    /** @return list<array{label: string, count: int}>  fresh: one DB::table count per column, trashed rows included */
    public function countReferences(): array;

    /** Resource fields: ['deleted_at' => ?ISO-8601, 'references' => referenceCounts()] */
    public function lifecycleAttributes(): array;
}
```
Every adopting model also uses `SoftDeletes` and `HasPublicId`; all in-scope models already do.

### 4.2 `App\Services\Core\LifecycleService` (NEW `app/Services/Core/LifecycleService.php`, static like `SubscriptionService`)

```php
final class LifecycleService
{
    public static function deactivate(Model $model): void;   // LogicException unless isDeactivatable(); is_active=false
    public static function activate(Model $model): void;     // is_active=true
    /** @param (Closure(Model): void)|null $beforeDelete runs inside the lock, after the zero-reference check */
    public static function destroyIfUnreferenced(Model $model, ?Closure $beforeDelete = null): void;
    /** @param (Closure(Model): void)|null $afterRestore */
    public static function restore(Model $model, ?Closure $afterRestore = null): void;
    /** "3 paket, 12 langganan" from countReferences()/referenceCounts(), zero counts skipped */
    public static function describe(array $references): string;
}
```

**`destroyIfUnreferenced`**, inside `DB::transaction(fn, 3)`:
1. Lock the row: `newQueryWithoutScopes()->whereKey()->whereNull('deleted_at')->lockForUpdate()->firstOrFail()`. The lock is the first statement, so the next consistent read sees children committed while we waited.
2. Run `countReferences()`. If the sum is above zero, throw `ValidationException::withMessages(['lifecycle' => "{Noun} masih dipakai {describe}. Nonaktifkan saja."])`. Entities that cannot be deactivated end the message with `", jadi tidak bisa dihapus."` instead.
3. Run `$beforeDelete`, then `forceDelete()`.

Outside the transaction:
- Catch `QueryException` with `errorInfo[1] === 1451` (a child inserted after the count). Throw the same key with `"{Noun} baru saja dipakai data lain. Nonaktifkan saja."` (non-deactivatable: `"…, jadi tidak bisa dihapus."`).
- On success, log `AuditService::log(Str::singular($table), 'force_deleted', ['id' => public_id, 'name' => name ?? code], null, $model->company_id)`.

**`restore`**:
1. Lock the row the same way (trashed rows allowed). If it is not trashed, return; this makes the call idempotent.
2. Self-referencing tree: if the row has `parent_id` and the parent row is trashed, throw `"{Noun} tidak bisa dipulihkan karena induknya, {parent name}, masih dihapus. Pulihkan induknya dulu."`.
3. Call `restore()`. Map `Illuminate\Database\UniqueConstraintViolationException` to `"{Noun} tidak bisa dipulihkan karena kode atau namanya sudah dipakai data lain. Ubah data itu dulu."`.
4. Run `$afterRestore`, then audit `'restored'`.

**`deactivate` / `activate`**: a plain update, then audit `'deactivated'` / `'activated'`. No cascade to children.

All refusals use the error key `lifecycle`, following the repo convention of `ValidationException::withMessages` (InfraScout §5).

### 4.3 `App\Http\Controllers\Admin\LifecycleController` (NEW) and routes

```php
final class LifecycleController extends Controller
{
    /** Called inside a route file's admin group. Registers POST {uri}/{record}/{action}, whereUlid('record'),
     *  ->defaults('lifecycleModel', $model) (precedent: Modules/Reporting/routes/web.php:63-81), name "{name ?? uri}.{action}". */
    public static function routes(string $uri, string $model, array $actions = ['deactivate', 'activate', 'restore'], ?string $name = null): void;

    public function deactivate(string $record, string $lifecycleModel): RedirectResponse; // Gate 'update'; flash "{Noun} dinonaktifkan."
    public function activate(string $record, string $lifecycleModel): RedirectResponse;   // Gate 'update'; flash "{Noun} diaktifkan."
    public function restore(string $record, string $lifecycleModel): RedirectResponse;    // Gate 'restore'; flash "{Noun} dipulihkan."
}
```

- **Resolution:** `$lifecycleModel::query()->wherePublicId($record)->firstOrFail()`, with `->withTrashed()` for restore.
  - The `BelongsToCompany` scope applies, so another tenant's id answers 404 and the uniform-404 rule holds.
  - `$lifecycleModel` comes from server route defaults, never from input. Assert it uses `HasLifecycle` (LogicException otherwise).
- **Policies:** every adopting policy gets `public function restore(User $user, <Model> $model): bool|Response { return $this->delete($user, $model); }` (D2: same permission as delete).
- **Master `destroy()`** becomes:
  ```php
  Gate::authorize('delete', $m);
  LifecycleService::destroyIfUnreferenced($m[, $beforeDelete]);
  return back()->with('success', '{Noun} dihapus permanen.');
  ```
- **Transactional `destroy()`** stays unchanged (soft delete with its status guard). These entities only gain `restore`.

| Entity | Route file | `LifecycleController::routes(...)` call | Resulting names |
|---|---|---|---|
| BandwidthProfile | `Modules/Service/routes/web.php` | `('bandwidth-profiles', BandwidthProfile::class)` | admin.bandwidth-profiles.{deactivate,activate,restore} |
| SpeedProfile | same | `('speed-profiles', SpeedProfile::class)` | admin.speed-profiles.* |
| SLATier | same | `('sla-tiers', SLATier::class)` | admin.sla-tiers.* |
| ServicePackage | same | `('service-packages', ServicePackage::class)`; delete line 29 `service-packages/{pkg}/deactivate`, `'pkg'` from the group's `whereUlid` list (line 13), and `ServicePackageController::deactivate()` (unused by UI/tests) | admin.service-packages.* |
| Product / Category / Unit | `Modules/Inventory/routes/web.php` | `('products', …)`, `('categories', …)`, `('units', …)` | admin.products.*, admin.categories.*, admin.units.* |
| Location | `routes/admin.php` | `('locations', Location::class)` | admin.locations.* |
| Customer | same | `('customers', Customer::class)` | admin.customers.* |
| CustomerAddress | same | `('customer-addresses', CustomerAddress::class, ['restore'], 'customers.addresses')` | admin.customers.addresses.restore |
| DocumentType | same | `('documents', DocumentType::class)` | admin.documents.* |
| Vehicle / OrganizationUnit | same | `('vehicles', …)`, `('organizations', OrganizationUnit::class)` | admin.vehicles.*, admin.organizations.* |
| EmployeeProfile / EmployeeEvaluation | same | `('employees', …, ['restore'])`, `('evaluations', …, ['restore'])` | admin.employees.restore, admin.evaluations.restore |
| NetworkAsset | `Modules/NetworkAsset/routes/web.php` | `('network-assets', NetworkAsset::class, ['restore'])` | admin.network-assets.restore |
| Ticket | `Modules/Ticketing/routes/web.php` | `('tickets', Ticket::class, ['restore'])` | admin.tickets.restore |
| WorkOrder | `Modules/SPK/routes/web.php` | `('spk', WorkOrder::class, ['restore'])` | admin.spk.restore |
| Invoice | `Modules/Billing/routes/web.php` | `('invoices', Invoice::class, ['restore'])` | admin.invoices.restore |
| Subscription | `routes/admin.php` after line 118 | `Route::post('subscriptions/{subscription}/change-package', [SubscriptionController::class, 'changePackage'])->name('subscriptions.change-package');` | admin.subscriptions.change-package |

### 4.4 Entity matrix (declarations each adopting slice writes)

| Model | Noun | lifecycleFilters | lifecycleReferences (label => table.column) | destroy hooks |
|---|---|---|---|---|
| BandwidthProfile | Profil bandwidth | ACTIVE | paket: service_packages.bandwidth_profile_id; versi paket: service_package_versions.bandwidth_profile_id | none |
| SpeedProfile | Profil kecepatan | ACTIVE | paket: service_packages.speed_profile_id; versi paket: service_package_versions.speed_profile_id | none |
| SLATier | Tingkat SLA | ACTIVE | paket: service_packages.sla_tier_id; versi paket: service_package_versions.sla_tier_id | none |
| ServicePackage | Paket layanan | ACTIVE | langganan: service_subscriptions.service_package_id (versions cascade, owned) | none |
| Product | Barang | ACTIVE | mutasi stok: stock_movements.product_id; permintaan barang: inventory_request_items.product_id; retur material: material_return_lines.product_id; penyesuaian stok: stock_adjustment_requests.product_id (cascade, history); item SPK: work_order_items.product_id; aset jaringan: network_assets.product_id; item tagihan: invoice_items.product_id | beforeDelete keeps today's guard `stocks()->where('quantity','>',0)` → 422 "Barang masih punya stok." (`stocks` cascade = owned balances) |
| Category (Inventory) | Kategori barang | ACTIVE | sub-kategori: categories.parent_id; barang: products.category_id | none |
| Unit | Satuan | ACTIVE (new column) | barang: products.unit_id; kategori barang: categories.unit_id | none |
| Location | Lokasi | ACTIVE | lokasi turunan: locations.parent_id; pelanggan: customers.area_coverage_id; langganan: service_subscriptions.serving_pop_id; stok: stocks.location_id; mutasi stok: stock_movements.from_location_id, stock_movements.to_location_id; permintaan barang: inventory_requests.warehouse_location_id, inventory_request_items.source_location_id; retur material: material_return_cases.warehouse_location_id; penyesuaian stok: stock_adjustment_requests.location_id; VLAN: vlans.location_id; prefiks IP: ip_prefixes.location_id; aset jaringan: network_assets.location_id; riwayat pemasangan: network_asset_installations.location_id; SPK: work_orders.location_id; tiket: tickets.location_id; pengumuman: announcement_location.location_id; banner promo: location_promo_banner.location_id | guards in `LocationService::delete` replaced; any non-guard logic kept as beforeDelete |
| Customer | Pelanggan | ACTIVE + statusFilters('customer_status', Customer::STATUSES) | langganan: service_subscriptions.customer_id; tagihan: invoices.customer_id; SPK: work_orders.customer_id; tiket: tickets.customer_id; aset jaringan: network_assets.customer_id; riwayat pemasangan: network_asset_installations.customer_id (addresses/contacts cascade, owned) | old guard (non-terminated subscriptions) removed: any subscription now references |
| CustomerAddress | Alamat | [] (no is_active, OD-3) | langganan: service_subscriptions.installation_address_id | beforeDelete = existing primary/installation-point promotion (`CustomerAddressController.php:168-204`); afterRestore clears is_primary/is_installation_point when the customer has another such address |
| DocumentType | Jenis dokumen | ACTIVE | dokumen: media.custom_properties->document_type_id (int id, `DocumentService.php:41`) | none |
| Vehicle | Kendaraan | ACTIVE | karyawan: employee_profiles.vehicle_id (SET NULL counts) | none |
| OrganizationUnit | Unit organisasi | ACTIVE | sub-unit: organization_units.parent_id; karyawan: employee_profiles.organization_id; SPK selesai: work_orders.organization_unit_id | `OrganizationService::delete` guards replaced |
| NetworkAsset | Aset jaringan | statusFilters('status', NetworkAsset::STATUSES) | riwayat pemasangan: network_asset_installations.network_asset_id; item SPK: work_order_items.network_asset_id; tiket: tickets.network_asset_id; splitter ODN: odn_splitters.network_asset_id; port ODN: odn_ports.network_asset_id; perangkat NAS: nas_devices.network_asset_id; langganan: service_subscriptions.ont_asset_id (no FK, history) | beforeDelete = today's status guard (installed/maintenance → 422) + `IpAssignmentService::releaseForAsset($asset)`; `ip_addresses.network_asset_id` is released, not counted (schema-test allowlist) |
| EmployeeProfile | Karyawan | statusFilters('status', ['active','inactive','terminated']) | null | destroy unchanged |
| EmployeeEvaluation | Evaluasi | [] | null | destroy unchanged |
| Ticket | Tiket | statusFilters('status', ['open','assigned','on_progress','resolved','closed']) | null | destroy unchanged |
| WorkOrder | SPK | statusFilters('status', ['draft','generated','assigned','in_progress','waiting_review','completed','rejected','cancelled']) | null | destroy unchanged |
| Invoice | Tagihan | statusFilters('status', ['draft','sent','partial','paid','overdue','cancelled']) | null | destroy unchanged |

NetworkAsset, EmployeeProfile, Ticket, WorkOrder and Invoice have no PHP status list today. Each gets `public const STATUSES = [...]`, mirroring `resources/js/lib/statuses/*.ts` and the enum migration `2026_08_11_163004`.

`ServicePackageCategoryController.php:73` keeps its hard-delete-only guard, but its count becomes `DB::table('service_package_versions')->where('service_package_category_id', $id)->distinct()->count('service_package_id')`. Every package has a version with its current category, and old versions keep old categories. The message is unchanged.

Index controllers of the models above:
- `filteredQuery` (shared with export) adds `->lifecycleStatus($request->input('status'))`.
- Masters add `->withReferenceCounts()` to the index pagination (except NetworkAsset, §5.3).
- `filters` includes `status`.
- `can` adds `update` and `delete` booleans using the controller's existing permission strings.

Old filter params, clean cutover:
- `is_active` (ServicePackageController:192, ProductController:171) is removed.
- Location `status=active|inactive` (LocationController:125-135) becomes `aktif|nonaktif`.
- Customer keeps `account` (is_active) and its `status` values.
- Tickets, SPK, invoices, employees and network assets keep their `status` values and gain `dihapus` and `semua`.

Master FormRequests:
- Unique rules must not ignore trashed rows, because the DB index reserves the key (R1).
- For masters that become deactivatable in this lane, pickers and FormRequests accept only active, non-deleted rows, plus the value the record already holds (pattern: the `UpdateServicePackageRequest` category rule). This covers:
  - profiles/tier in `Store/UpdateServicePackageRequest` and `ServicePackageController::formOptions` (which also keeps current values selectable);
  - `unit_id` in `Store/UpdateProductRequest` and `Store/UpdateCategoryRequest`, plus the unit pickers in `ProductController` and `CategoryController`;
  - `service_package_id` in `StoreSubscriptionRequest`.

### 4.5 Package versioning behaviour

1. **Model hooks.**
   - NEW concern `Modules/Service/Models/Concerns/RecordsVersions.php` (precedent `Concerns/HasPublicationStatus.php`), used by `ServicePackage`:
     - `public const VERSIONED_TERMS = [...]`;
     - `bootRecordsVersions()`: on `created` → `recordVersion()`; on `updated` → `wasChanged(self::VERSIONED_TERMS)` → `recordVersion()`;
     - `versions(): HasMany` ordered by version; `latestVersion(): HasOne` via `latestOfMany('version')`;
     - `recordVersion()` writes `company_id` explicitly (works without a tenant context, since `BelongsToCompany` keeps an explicit company_id), `version = max + 1`, `valid_from = now()` and the terms.
   - NEW `Modules/Service/Models/ServicePackageVersion.php`:
     - `BelongsToCompany`; fillable = columns; casts decimal:2, int, datetime;
     - relations `package()`, `bandwidthProfile()`, `speedProfile()`, `slaTier()` all `->withTrashed()`, plus `category()` and `subscriptions()`;
     - `static::updating` throws `LogicException('Package versions are immutable.')` (precedent `NetworkAssetInstallation.php:78-82`).
   - Concurrency: the integrity lane already wraps `ServicePackageController::update` in `DB::transaction` + `lockForUpdate`, so concurrent edits serialise. `unique(service_package_id, version)` is the backstop.
2. **Subscription create.**
   - `ServiceSubscription` gets:
     - fillable `service_package_version_id`;
     - `packageVersion(): BelongsTo`;
     - a `booted()` `creating` hook: `service_package_version_id ??= ServicePackageVersion::withoutCompany()->where('service_package_id', …)->orderByDesc('version')->value('id')`. It uses `withoutCompany()` so seeders and jobs without a tenant context work.
   - `SubscriptionService::create` (`:34-37`, `mrc_amount ??= price_mrc`) is unchanged, because the package's current terms equal its latest version.
   - Factories are unchanged: `ServiceSubscriptionFactory` creates a package through its factory, and the hook creates v1.
3. **Explicit migration (D3).** `SubscriptionService::changePackage(ServiceSubscription $s, int $packageId, ?string $mrcAmount): ServiceSubscription`:
   1. Open a transaction and `lockForUpdate` the subscription.
   2. Return 422 if the subscription is `terminated` ("Langganan yang sudah berhenti tidak bisa pindah paket.").
   3. Target = the package's `latestVersion`. Return 422 if it equals the current version ("Langganan sudah memakai versi terbaru paket ini.").
   4. Update `service_package_id`, `service_package_version_id` and `mrc_amount = $mrcAmount ?? version.price_mrc`. Update `otc_installation_fee = version.price_otc` only while the subscription is `pending` (OD-5).
   5. Log `AuditService::log('service_subscription', 'package_changed', ['from' => [package code, version, mrc], 'to' => […]], $s)`.

   Related changes:
   - `SubscriptionController::changePackage(ChangeSubscriptionPackageRequest, ServiceSubscription)`: `ensureSameCompany`, `Gate::authorize('customer.subscription.manage')`, then flash "Langganan dipindahkan ke versi terbaru paket.".
   - `UpdateSubscriptionRequest` drops `service_package_id` (line 66, plus its public-id map entry at line 23).
   - `SubscriptionController::update` drops `$packageChanged` (line 113); the SPK relink stays for address changes.
4. **Billing.**
   - `BillingService` reads `mrc_amount` (`:186-188`) and is unchanged.
   - The invoice line label (`:139-140`) keeps the package name at generation time; the line `description` is already a snapshot.
   - A mid-period package change bills the new `mrc_amount` for the next period, with no proration. This matches today.
   - `CreateInvoiceFromSpkAction.php:73-80` reads the subscription's `otc_installation_fee` and is unchanged.
5. **Provisioning** reads `subscription.packageVersion.speedProfile` instead of `servicePackage.speedProfile` in:
   - `NetworkAccountProvisioner.php:302,330`;
   - `NetworkAccountService.php:154`;
   - `Console/SyncNetworkDevices.php:67` (eager load);
   - `NetworkAccountController.php:47-49`;
   - `StoreNetworkAccountRequest.php:65` and `UpdateNetworkAccountRequest.php:61`.

   `ServiceSubscriptionNetworkObserver.php:20` also watches `service_package_version_id`.
6. **SLA.**
   - `TicketService.php:110-111` reads `subscription.packageVersion.slaTier` and drops the `is_active` gate for it, so the sold tier keeps applying after the tier is deactivated (OD-6).
   - `SlaTierCompliance.php:29` joins `service_package_versions` on `service_subscriptions.service_package_version_id` and groups by the version's `sla_tier_id`.
7. **API `CustomerSubscriptionResource`** (`app/Http/Resources/Api/V1/CustomerSubscriptionResource.php:14-63`): same shape.
   - `package.category`, `download_mbps`, `upload_mbps`, `speed_label` and `sla_tier` come from `packageVersion`.
   - `package.id/name` stay live.
   - `Api/V1/Customer/SubscriptionController.php:22-30,45-53` eager-loads `packageVersion.{bandwidthProfile,speedProfile,slaTier,category}` with the same column lists.
8. **Web `SubscriptionResource`** adds:
   - `'package_version' => ServicePackageVersionResource (whenLoaded)`;
   - `'package_version_is_latest' => bool`, when `servicePackage.latestVersion` is loaded.

   `SubscriptionController::show` (`:79`) loads `packageVersion.{bandwidthProfile,speedProfile,slaTier,category}` and `servicePackage.latestVersion`. When the user may manage subscriptions, it also adds the lazy prop `packageOptions` (active packages: id, code, name, price_mrc), using the same optional-prop pattern as `dropPortOptions`.
9. **NEW `ServicePackageVersionResource`** (`Modules/Service/Http/Resources/`), fields:
   - `version`, `valid_from`, `price_mrc`, `price_otc`;
   - `bandwidth_profile{name,download_mbps,upload_mbps}`;
   - `speed_profile{name,download_max_mbps,upload_max_mbps}`;
   - `sla_tier{name,response_time_hours,resolution_time_hours}`;
   - `category{name}`;
   - `subscriptions_count` (whenCounted).

   `ServicePackageController::show` passes `versions` with `withCount('subscriptions')`.

### 4.6 Snapshot writers and readers

**Helpers:**
- NEW `App\Support\AddressLine::of(object|array|null $address): ?string`. Joins `address, village_name, district_name, city_name ?? city, province_name, postal_code` with ", ", skipping blanks. Used by M3 and `InvoiceParty`.
- NEW `Modules\Billing\Support\InvoiceParty` (`Modules/Billing/app/Support/InvoiceParty.php`):
  - `live(Invoice): array{customer_name, customer_address, customer_tax_id, package_name}` reads the customer, the subscription's `installationAddress` (else the customer's primary address) and the subscription's `servicePackage->name`;
  - `of(Invoice)` returns the snapshot when `customer_name_snapshot !== null`, else `live()` (drafts);
  - `freeze(Invoice)` fills the 4 `_snapshot` columns from `live()` while they are null. It does not save.

**Writers.** No other transition reaches these states (DocsScout §2/§5/§6): there is no reopen from closed and no path back out of completed.
- `BillingService::send` (`:191-205`): `InvoiceParty::freeze($invoice)` before the status update (one save).
- `BillingService::generateForPeriod` (create at `:123-137`, status `sent`): build the invoice, set the `customer`/`subscription` relations from the already eager-loaded subscription, freeze, then save. Add `installationAddress` to the eager load at `:55`. No extra query per invoice.
- `SpkService::approve` (`:437-442` update array) adds:
  - `technician_name_snapshot` (assignee name);
  - `location_code_snapshot` (location code);
  - `organization_unit_id` (`EmployeeProfile::query()->where('user_id', $wo->assigned_to)->value('organization_id')`).
- `TicketService::close` (`:362`) adds `category_name_snapshot` and `assignee_name_snapshot`.

**Readers:**
- **PDF** (`resources/views/pdf/invoice.blade.php:48-55`) prints `$party['customer_name']`, then `$party['customer_address']`, then "NPWP: {customer_tax_id}" when present, then `{subscription code} - {package_name}`. `InvoiceController::pdf` (`:237-250`) and `Api/V1/Customer/InvoiceReceiptController.php:27-35` pass `'party' => InvoiceParty::of($invoice)`, so Blade runs no query.
- **`PortalInvoiceResource`** (API) adds `customer_name`, `customer_address`, `customer_tax_id` and `package_name` from `InvoiceParty::of()`.
- **`InvoiceResource`** (web) adds the 4 raw `*_snapshot` fields.
- **`WorkOrderResource`** adds `technician_name_snapshot` and `location_code_snapshot`. `WorkOrder::technicianFirstName()` (`WorkOrder.php:152`, used by `CustomerWorkOrderResource`) prefers the snapshot.
- **`TicketResource`** adds `category_name_snapshot` and `assignee_name_snapshot`. `Api/V1/CustomerTicketResource.php:32,41` uses snapshot ?? live for `category.name` and `handler_first_name`.
- **Receivables** (`BillingService.php:433-447`, `Modules/Billing/app/Queries/ReceivablesQuery.php`): the group key stays `customer_id`. The name comes from the newest open invoice's `customer_name_snapshot`, and `customer` is eager-loaded via the withTrashed relation (for public_id and the link).

### 4.7 Reports (D5)

| File | Today | Change |
|---|---|---|
| `Modules/Reporting/app/Queries/Concerns/ScopesToPackageCategory.php:14-28` | `packageIdsInCategory` = live package membership | Replace with `packageVersionIdsInCategory(company, category)` = `DB::table('service_package_versions')->where(company)->where('service_package_category_id')->select('id')`; `subscriptionIdsInCategory` filters `service_package_version_id`; remove `packageIdsInCategory` |
| `SalesKpiQuery.php:176-182`, `Sources/BusinessSources.php:31,56,135` | `whereIn('service_subscriptions.service_package_id', packageIdsInCategory…)` | `whereIn('service_subscriptions.service_package_version_id', packageVersionIdsInCategory…)`; invoice and SPK scopes keep `subscriptionIdsInCategory` |
| `Sources/SlaSources.php` `resolvedByCategory` (~124-135), `SlaComplianceQuery.php:37-38` | group by `ticket_categories.name` | Select and group by the constant `SlaSources::CATEGORY_LABEL = 'COALESCE(tickets.category_name_snapshot, ticket_categories.name)'` |
| `Sources/TechnicianSources.php:41-45`, `TechnicianPerformanceQuery.php:93-95` | same | same expression |
| `Drill/DrillRegistry.php:141-144,176-179` | `->where('ticket_categories.name', group)` | `->whereRaw(SlaSources::CATEGORY_LABEL.' = ?', [group])` |
| `Drill/DrillKind.php:182-186,205,224` | live names | Invoice `customer` = `$get('customer_name_snapshot') ?? $get('customer.name')`; WorkOrder `assignee` = `technician_name_snapshot ?? assignee.name`; Ticket `category` = `category_name_snapshot ?? category.name` (precedent line 256, `asset_code_snapshot ?? asset.code`); Subscription `package` stays live through the withTrashed relation |
| `Registry/MetricRegistry.php` (by_category ~633-645, tickets_by_category ~918-933, package-category caveats) | names `ticket_categories.name` / live package membership | Sources and formula text name `tickets.category_name_snapshot` (live fallback while not closed) and "kategori versi paket langganan" |
| `TechnicianPerformanceQuery.php:21-51,151-161` | manager scope by the technician's current unit path | Unchanged: it is access control. `work_orders.organization_unit_id` is stored and backfilled; no current aggregate groups SPKs by unit (OD-2) |
| `AssetUtilizationQuery`, `CustomerAudienceQuery` | live location/package | Unchanged: they report current state, not history (R11) |

### 4.8 `withTrashed()` on history relations (D6)

Each relation definition below gets `->withTrashed()`. Each slice checks every `whereHas`/`has`/`withCount` on a changed relation and keeps the trashed-parent exclusion where an eligibility or picker query needs it (R5).

| Slice | Relations |
|---|---|
| W1-VERSIONS | ServicePackage::{bandwidthProfile, speedProfile, slaTier} (`ServicePackage.php:45-62`); ServiceSubscription::{customer, servicePackage, installationAddress, servingPop, ontAsset} (`ServiceSubscription.php:66-94`); all ServicePackageVersion relations (new) |
| W2-CORE-BE | Customer::areaCoverage (`:110`); CustomerAddress::customer (`:54`); CustomerContact::customer (`:43`); Location::parent (`:47`) |
| W2-PEOPLE-BE | EmployeeProfile::vehicle (`:57`); OrganizationUnit::parent (`:45`). `EmployeeProfile::organization` stays live: it drives report access via `whereHas`, and a unit with employees can never be removed |
| W2-INVENTORY-BE | Category::{parent, unit}; Product::{category, unit}; Stock::{product, location}; StockMovement::{product, fromLocation, toLocation}; StockAdjustmentRequest::{product, location}; InventoryRequest::warehouseLocation; InventoryRequestItem::{product, sourceLocation}; MaterialReturnCase::{workOrder, warehouseLocation}; MaterialReturnLine::product |
| W2-NETWORK-BE | NetworkAsset::{product, location, customer, subscription}; NetworkAssetInstallation::{location, customer, subscription, workOrder}; NasDevice::networkAsset; OdnPort::{networkAsset, subscription}; OdnSplitter::networkAsset; IpAddress::{subscription, networkAsset}; IpPrefix::location; Vlan::location |
| W2-BILLING-BE | Invoice::{customer, subscription, workOrder}; InvoiceItem::{invoice, product}; Payment::invoice; PaymentAttempt::invoice |
| W2-SPK-BE | WorkOrder::{customer, subscription, location, ticket} + new `organizationUnit()` (withTrashed); WorkOrderItem::{workOrder, product, networkAsset}; WorkOrderAssignment::workOrder |
| W2-TICKET-BE | Ticket::{category, customer, subscription, networkAsset, location, spawnedSpk}; TicketComment::ticket |

### 4.9 API / OpenAPI (additive only, mobile unchanged)

- `GET api/v1/customer/invoices` and `/{id}` (`PortalInvoiceResource`): new `customer_name`, `customer_address`, `customer_tax_id`, `package_name` (string|null, "as printed when issued").
- `GET api/v1/customer/subscriptions[/{id}]`: same shape; package terms now come from the subscription's version.
- Customer tickets and work orders, and the technician resources: same shape; closed or completed records read the snapshot.
- Regenerate `docs/api/mobile-v1.openapi.json` with `./vendor/bin/sail artisan scramble:export --path=docs/api/mobile-v1.openapi.json`. `tests/Feature/Api/V1/OpenApiContractTest.php:27` compares it with a fresh export.

## 5. UI contract

### 5.1 Shared types and statuses (W1-UI)

`resources/js/types/models.ts` gains:
```ts
export type ReferenceCount = { label: string; count: number };
export type LifecycleFields = { is_active?: boolean; deleted_at?: string | null; references?: ReferenceCount[] | null };
```

`resources/js/lib/statuses/common.ts` gains:
```ts
export const LIFECYCLE_STATE = {
    aktif: { label: 'Aktif', tone: 'success' },
    nonaktif: { label: 'Nonaktif', tone: 'neutral' },
    dihapus: { label: 'Dihapus', tone: 'neutral' },
} satisfies StatusMap;
export function lifecycleStateOf(row: { is_active?: boolean; deleted_at?: string | null }): keyof typeof LIFECYCLE_STATE;
```

### 5.2 Composites (W1-UI)

**NEW `resources/js/Components/composite/StatusFilter.tsx`** (exported from `composite/index.ts`; wraps `NativeSelect`):
```ts
export interface StatusFilterProps {
    value: string;                     // current ?status=, '' = default
    onChange: (value: string) => void;
    deactivatable?: boolean;           // adds Aktif / Nonaktif
    statuses?: StatusMap;              // entity statuses, e.g. TICKET_STATUS
    id?: string;
    label?: string;                    // default 'Status'
    wrapperClassName?: string;
}
```
Options, in this order:
1. `''` "Semua status" (rows not deleted);
2. `aktif` "Aktif" and `nonaktif` "Nonaktif" (only when `deactivatable`);
3. `optionsOf(statuses)`;
4. `dihapus` "Dihapus";
5. `semua` "Semua, termasuk dihapus".

**`DataTableActions` lifecycle variant** (`resources/js/Components/composite/DataTableActions.tsx`). The existing props and `deleteHref` behaviour stay byte-compatible; `tests/e2e/datatable-actions-delete.spec.ts` must keep passing. New optional prop:
```ts
export interface LifecycleActionsProp {
    row: { id: string } & LifecycleFields;
    name: string;              // row display name for dialog text
    noun: string;              // 'Profil bandwidth'
    routeBase: string;         // 'admin.bandwidth-profiles' → .deactivate/.activate/.restore (POST), .destroy (DELETE)
    can?: { update?: boolean; delete?: boolean }; // undefined = allowed (server still checks)
}
// DataTableActionsProps += lifecycle?: LifecycleActionsProp
```

Which actions a row offers:

| Row | Main button | Menu items |
|---|---|---|
| `deleted_at` set | none (show/edit routes 404 on trashed rows) | Pulihkan (`can.delete`) |
| Master (`references` is an array) | as today | Nonaktifkan when `is_active === true`, Aktifkan when `is_active === false` (both need `can.update`); Hapus permanen when the counts sum to 0 (`can.delete`, destructive) |
| Any other row (transactional, or references not loaded) | as today | today's "Hapus" item, if the page passes `deleteHref` |

Dialog behaviour:
- All four actions share one `AlertDialog` with the existing in-flight contract: the dialog stays open while the request runs and closes on success only.
- On error, the dialog shows `errors.lifecycle ??` the first error as `<p role="alert" className="text-sm text-destructive-text">`. The global toast still fires, as it does today.
- The reference list is built as `refs = references.filter((r) => r.count > 0).map((r) => `${formatNumber(r.count)} ${r.label}`).join(', ')`.
- No new colours: badges use `EnumBadge` tones and errors use `text-destructive-text`. Every pair must keep text ≥ 4.5:1 and icons ≥ 3:1 in light and dark mode (owner contrast rule).

Dialog text (no em dash, sentence case):

| Action | Title | Description | Confirm |
|---|---|---|---|
| Nonaktifkan | `Nonaktifkan {name}?` | With references: `{noun} "{name}" dipakai {refs}, jadi tidak bisa dihapus permanen. Setelah dinonaktifkan, data ini tidak muncul di pilihan baru dan riwayatnya tetap utuh.` Without: `Setelah dinonaktifkan, {noun} "{name}" tidak muncul di pilihan baru. Riwayatnya tetap utuh.` | Nonaktifkan |
| Aktifkan | `Aktifkan {name}?` | `{noun} "{name}" kembali muncul di pilihan baru.` | Aktifkan |
| Pulihkan | `Pulihkan {name}?` | `{noun} "{name}" kembali muncul di daftar dengan status sebelumnya.` | Pulihkan |
| Hapus permanen | `Hapus permanen {name}?` | `{noun} "{name}" belum pernah dipakai data lain. Penghapusan permanen tidak bisa dibatalkan.` | Hapus permanen (destructive) |

### 5.3 Pages

Common changes on every index page listed below:
- the props type gains `filters.status`;
- `<StatusFilter>` goes in the toolbar via `useServerTable().set('status', v)`, and is included in `hasFilters` and reset;
- status column: masters show `EnumBadge map={LIFECYCLE_STATE} value={lifecycleStateOf(row)}`; transactional rows show the entity badge, or "Dihapus" when `deleted_at` is set;
- the name link renders as plain text when `deleted_at` is set (its route would 404);
- `DataTableActions lifecycle={{ row, name, noun, routeBase, can }}` replaces today's master `deleteHref`/`deleteMessage`/`deleteDescription`.

| Page | Filter (`deactivatable` / `statuses`) | Actions | Extra |
|---|---|---|---|
| `Pages/Admin/Service/{BandwidthProfiles,SpeedProfiles,SLATiers}/Index.tsx` | true / none | master | none |
| `Service/Packages/Index.tsx` | true / none (replaces `pkg-active` select) | master | none |
| `Service/Packages/Show.tsx` | none | none | "Riwayat versi" DataTable: Versi, Berlaku sejak, Biaya bulanan, Biaya pemasangan, Bandwidth, Kecepatan, SLA, Kategori, Langganan |
| `Service/Packages/Edit.tsx` | none | none | `Alert` info: "Mengubah biaya bulanan, biaya pemasangan, profil bandwidth, profil kecepatan, tingkat SLA, atau kategori membuat versi baru. Langganan yang sudah ada tetap di versinya; pindahkan dari halaman langganan bila perlu." |
| `Inventory/{Products,Categories,Units}/Index.tsx` | true / none (Products replaces its `is_active` select) | master | Units: new status column |
| `Locations/Index.tsx` | true / none (replaces active/inactive select) | master; the page's own delete AlertDialog (~lines 231-245, 708-740) is replaced by the variant | none |
| `Customers/Index.tsx` | false / CUSTOMER_STATUS (`account` select stays) | master | none |
| `CustomerAddresses/Index.tsx` | false / none | master without is_active (Hapus permanen / Pulihkan) | none |
| `Components/composite/CustomerAddressEditor.tsx` | none | delete dialog copy becomes: "Alamat yang belum pernah dipakai langganan dihapus permanen." | shows `errors.lifecycle` |
| `Documents/Index.tsx`, `Vehicles/Index.tsx`, `Organizations/Index.tsx` | true / none | master | none |
| `Employees/Index.tsx` | false / EMPLOYEE_STATUS | Pulihkan only | none |
| `Evaluations/Index.tsx` | false / none | Pulihkan only | none |
| `NetworkAssets/Index.tsx` | false / NETWORK_ASSET_STATUS | Pulihkan only (the index sends no reference counts, so no new delete entry point) | none |
| `Tickets/Index.tsx`, `SPK/Index.tsx`, `Billing/Invoices/Index.tsx` | false / TICKET_STATUS, WORK_ORDER_STATUS, INVOICE_STATUS | Pulihkan only (no delete UI exists today) | none |
| `Billing/Invoices/Show.tsx` | none | none | "Kepada" block prefers `customer_name_snapshot`, `customer_address_snapshot`, `customer_tax_id_snapshot` ("NPWP") and `package_name_snapshot`; drafts keep the live display |
| `SPK/Show.tsx` | none | none | completed: technician = `technician_name_snapshot`, location = `location_code_snapshot` when present |
| `Tickets/Show.tsx` | none | none | closed: category = `category_name_snapshot`, assignee = `assignee_name_snapshot` when present |
| `Subscriptions/Show.tsx` | none | none | Paket card and "Ganti paket" modal, described below |
| `Subscriptions/Index.tsx` | unchanged | unchanged | edit modal drops the package select (lines ~250-262) |

`Subscriptions/Show.tsx` details:
- Paket card: live package name, "Versi {n} sejak {date}", the version's terms, and a `Badge` "Versi terbaru tersedia" when `!package_version_is_latest`.
- Button "Ganti paket" (needs `can.changePackage`) opens a modal:
  - SearchSelect Paket, defaulting to the current package; options from `packageOptions` via partial reload;
  - MoneyInput "Biaya bulanan", defaulting to the selected package's price;
  - hint: "Langganan pindah ke versi terbaru paket ini. Tagihan berikutnya memakai biaya bulanan ini. Biaya pemasangan berubah hanya bila langganan masih menunggu.";
  - submit "Pindahkan" → `router.post(route('admin.subscriptions.change-package', id))`; errors show under the fields.

States: loading uses the existing `processing` flag and empty lists use the existing `EmptyState`. Errors render inside the dialog or under the modal fields. Keyboard and focus handling come from `AlertDialog` and `NativeSelect`.

## 6. Test plan

All PHPUnit runs use `inbils_test` (`phpunit.xml`) with `RefreshDatabase` and `tests/Traits/CreatesCompanyUser.php`; the setup pattern is `tests/Feature/Service/BandwidthProfileServerTableExportTest.php:21-37`.

Commands:
- per slice: `./vendor/bin/sail artisan test --compact <paths>`;
- PHP format: `./vendor/bin/sail php vendor/bin/pint --dirty --format agent`;
- frontend: `npm run typecheck && npm run lint && npm run build`.

| Slice | File | Tests |
|---|---|---|
| W1-MECH | NEW `tests/Feature/Lifecycle/LifecycleMechanismTest.php`. Probe classes are declared in the file: `LifecycleProbe extends BandwidthProfile` (`$table='bandwidth_profiles'`, ACTIVE filters, refs `paket` → service_packages.bandwidth_profile_id) and `LifecycleTreeProbe extends Location` (`$table='locations'`, refs `lokasi turunan`). Probe routes are registered in `setUp()` through `LifecycleController::routes('lifecycle-probes', …)`, with a test policy via `Gate::policy` | `test_status_scope_maps_default_aktif_nonaktif_dihapus_semua_and_ignores_unknown`; `test_reference_counts_include_trashed_children`; `test_self_reference_counts_use_an_aliased_inner_table`; `test_destroy_hard_deletes_an_unreferenced_row`; `test_destroy_refuses_a_referenced_row_and_names_the_counts` (message "Profil uji masih dipakai 1 paket. Nonaktifkan saja."); `test_destroy_maps_a_child_inserted_after_the_count_to_the_same_refusal` (beforeDelete inserts a package → 1451 → 422; parent still present; child rolled back); `test_restore_refuses_while_the_parent_row_is_trashed`; `test_restore_maps_a_unique_violation_to_a_validation_error` (a `restoring` listener throws `UniqueConstraintViolationException`); `test_restore_of_a_live_row_is_a_no_op`; `test_actions_write_audit_entries`; `test_routes_register_names_authorize_and_404_for_other_company` |
| W1-MECH | NEW `tests/Feature/Lifecycle/LifecycleReferencesMatchSchemaTest.php` | `test_every_restrict_or_set_null_foreign_key_to_a_lifecycle_table_is_declared`: finds models by parsing `namespace`/`class` in `app/Models/**`, `Modules/*/app/Models/**` and `Modules/Service/Models/**`, keeping those where `class_uses_recursive` contains HasLifecycle and references ≠ null; reads FKs from `information_schema.KEY_COLUMN_USAGE` ⨝ `REFERENTIAL_CONSTRAINTS` with DELETE_RULE IN (RESTRICT, NO ACTION, SET NULL); allowlist: `network_assets` → `ip_addresses.network_asset_id` (released on delete). `test_every_declared_reference_column_exists` (JSON paths check the base column) |
| W1-VERSIONS | NEW `tests/Feature/Service/ServicePackageVersionTest.php` | `test_creating_a_package_records_version_one`; `test_changing_a_term_appends_a_version_and_keeps_existing_subscriptions_on_their_version` (version isolation); `test_rename_or_description_change_does_not_version`; `test_new_subscription_gets_the_latest_version`; `test_versions_are_immutable`; `test_backfill_creates_missing_versions_once` (raw-insert a package, run `ServicePackageVersionBackfill::versions()` twice → 1 row) |
| W1-SNAPSHOT-MIG | NEW `tests/Feature/Lifecycle/SnapshotBackfillTest.php` | `test_invoice_backfill_fills_non_draft_only_and_is_idempotent` (calls M3 `up()` twice); `test_work_order_backfill_fills_completed_only`; `test_ticket_backfill_fills_closed_only`; `test_address_line_skips_blanks` |
| W2-SERVICE-BE | NEW `tests/Feature/Service/ServiceMasterLifecycleTest.php` | `test_referenced_bandwidth_profile_cannot_be_hard_deleted_and_can_be_deactivated` (F2-D04); `test_unreferenced_speed_profile_is_hard_deleted`; `test_sla_tier_restore_of_legacy_trashed_row`; `test_bandwidth_index_status_filter_values` (aktif/nonaktif/dihapus/semua/default) and the `references` payload; `test_package_index_status_replaces_is_active`; `test_package_with_terminated_subscription_cannot_be_hard_deleted`; `test_store_and_update_package_reject_inactive_profiles_but_keep_current`; `test_package_show_lists_versions_with_subscription_counts`; `test_category_guard_counts_packages_through_versions`; `test_sla_tier_compliance_follows_the_version_tier` |
| W2-INVENTORY-BE | NEW `tests/Feature/Inventory/InventoryMasterLifecycleTest.php` | product with movements → 422 + deactivate; product without children → hard delete; product stock>0 guard kept; category parent/child counts; unit `is_active` deactivate, and pickers exclude inactive units; products status filter replaces `is_active` |
| W2-CORE-BE | NEW `tests/Feature/Lifecycle/CoreMasterLifecycleTest.php` | location counts children + subscriptions ("1 lokasi turunan, 1 langganan"); `test_location_restore_refused_while_parent_trashed`; customer with only terminated subscriptions → 422 + deactivate; hard delete of an unreferenced customer cascades addresses/contacts; address hard delete promotes the primary; address restore clears primary when another exists; document type with media → refused; filter values on locations (`aktif`) and customers (`dihapus`) |
| W2-PEOPLE-BE | NEW `tests/Feature/Lifecycle/PeopleLifecycleTest.php` | vehicle referenced via a SET NULL FK → refused; org unit with employees/children → refused; employee and evaluation `dihapus` filter + restore; restore needs the delete permission (403 otherwise) |
| W2-SUBS-BE | NEW `tests/Feature/Admin/SubscriptionChangePackageTest.php` | `test_change_package_moves_to_latest_version_and_logs`; `test_mrc_defaults_to_version_price_and_accepts_override`; `test_otc_changes_only_while_pending`; `test_terminated_or_same_version_is_422`; `test_inactive_target_package_rejected`; `test_update_no_longer_changes_package`; `test_customer_api_subscription_reads_version_terms` |
| W2-NETWORK-BE | NEW `tests/Feature/NetworkAsset/NetworkAssetLifecycleTest.php`, NEW `tests/Feature/NetworkAsset/ProvisioningUsesPackageVersionTest.php` | asset with installation history → refused; unreferenced asset → IPs released + hard delete; installed asset → 422; `dihapus` filter + restore; an old subscription's RADIUS rate limit stays on its version's speed profile after the package moves to a faster profile; observer queues apply on version change |
| W2-BILLING-BE | NEW `tests/Feature/Billing/InvoiceSnapshotTest.php` | `test_send_freezes_party`; `test_recurring_generation_freezes_party_without_extra_queries` (query count); `test_pdf_template_prints_frozen_name_after_customer_rename` (`view('pdf.invoice', …)->render()` contains the old name, not the new one); `test_portal_api_returns_frozen_fields`; `test_draft_prints_live_values`; `test_receivables_show_snapshot_name`; `test_trashed_draft_restore` |
| W2-SPK-BE | NEW `tests/Feature/SPK/WorkOrderSnapshotTest.php` | approve freezes technician, location and unit; renaming the technician keeps the snapshot in the web and customer API; `dihapus` filter + restore of a cancelled SPK |
| W2-TICKET-BE | NEW `tests/Feature/Ticketing/TicketSnapshotTest.php` | close freezes category and assignee; renaming the category keeps the closed ticket's name in the API; the SLA deadline uses the version tier even after that tier is deactivated; `dihapus` filter + restore |
| W3-REPORTS-BE | NEW `tests/Feature/Reporting/ReportSnapshotStabilityTest.php` | `test_business_and_sales_category_filter_keep_subscriptions_sold_under_old_category_after_package_moves`; `test_sla_by_category_stable_after_category_rename`; `test_technician_tickets_by_category_stable_after_rename`; `test_drill_rows_read_snapshots` |
| W3-E2E | NEW `tests/e2e/master-lifecycle.spec.ts` (`@lifecycle`, serial, `loginAs(page, 'admin')` from `tests/e2e/support/demo.ts`; data created through `page.request.post` like `datatable-ab.spec.ts:282`) | Bandwidth and invoice flows, described below |

E2E flows in `master-lifecycle.spec.ts`:
- **Bandwidth profile, used:**
  1. Create profile P1 and a package that uses it.
  2. The P1 row menu shows "Nonaktifkan" and no "Hapus permanen"; the dialog reads "dipakai 1 paket".
  3. Confirm. The toast reads "Profil bandwidth dinonaktifkan.", and Status "Nonaktif" lists P1.
  4. The package create form no longer offers P1.
  5. "Aktifkan" restores it.
- **Bandwidth profile, unused:** create P2, run "Hapus permanen", and confirm it is gone even under "Semua, termasuk dihapus".
- **Invoice after customer rename:**
  1. Create customer C and a draft invoice, then send it.
  2. Rename C. The invoice show page still shows the old name.
  3. `page.request.get(pdf url)` returns 200, `application/pdf`, and a body starting `%PDF-`. The PDF text itself is checked by the PHPUnit template test (R12).
- **Restore:** delete a second draft. Status "Dihapus" lists it; "Pulihkan" brings it back under "Draf".

Existing tests to update (each is an owned file, §8.2):
- `tests/Feature/Service/ServicePackageServerTableExportTest.php` and `tests/Feature/Inventory/ProductServerTableExportTest.php`: `is_active` → `status`.
- `tests/Feature/Admin/LocationContractTest.php`: `status=active|inactive` → `aktif|nonaktif`, and destroy semantics.
- `tests/Feature/CustomerAddressAuthorizeTest.php`, `tests/Feature/DocumentTest.php`, `tests/Feature/OrganizationTest.php`, `tests/Feature/VehicleTest.php`: destroy messages and hard delete.
- `tests/Feature/Reporting/ReportFormulaV1Test.php`: formula text, if asserted.
- `tests/e2e/datatable-c.spec.ts:260`: `selectFilter(page, 'Status', 'Aktif', 'status', 'Filter')`.

E2E command: `flock /tmp/inbils-e2e.lock sh -c 'npm run build && node scripts/e2e-setup.mjs && npm run test:e2e -- tests/e2e/master-lifecycle.spec.ts tests/e2e/datatable-c.spec.ts tests/e2e/datatable-actions-delete.spec.ts --project=chromium --workers=1'`.

## 7. Risks and open decisions

**Open decisions.** Each has a recommended default, and the slices implement the default.
- **OD-1 Package category is a versioned term.**
  - Default: yes. It is report-relevant (D3 principle, D5 category filter).
  - Alternative: keep category in place and add a `service_subscriptions.package_category_id` snapshot.
- **OD-2 SPK organization unit.**
  - Default: store and backfill `work_orders.organization_unit_id` only. No current aggregate groups SPKs by unit. The manager scope in `TechnicianPerformanceQuery` / `TechnicianReportAccess` is access control and stays live.
  - Alternative: restrict manager-scoped SPK figures to SPKs completed inside the manager's subtree. This only narrows access, but it changes metric definitions.
- **OD-3 Customer addresses get no `is_active` in this lane.**
  - Default: no. A used address still cannot be deleted; hiding one needs primary-address rules and picker changes.
  - Addresses still get hard-delete-if-unreferenced, the filter and Pulihkan.
- **OD-4 Unique keys stay reserved by trashed and inactive rows** (no index change on the live DB).
  - Default: yes.
  - A restore conflict is then either a trashed tree parent (real) or a unique violation (defensive mapping).
- **OD-5 Costs on change-package.**
  - `mrc_amount` takes the new version's price unless overridden.
  - OTC follows the new version only while the subscription is `pending`.
  - No proration.
- **OD-6 SLA of a sold tier.**
  - Default: tickets use the subscription's version tier even after that tier is deactivated; deactivation affects new sales only.
  - The `is_active` gate at `TicketService.php:113` is dropped for the version tier.
- **OD-7 One dialog for all four lifecycle actions.**
  - Default: yes, so server errors always show in place.
  - The global toast only says "Data gagal disimpan. Periksa input." (`resources/js/hooks/useToast.ts:16-20`).

**Risks:**
- **R1 Unique keys with soft deletes.**
  - Keys stay reserved, so users cannot reuse the code of a deleted or inactive row. The user guide points to the Dihapus and Nonaktif filters.
  - Every master Store/Update unique rule must count trashed rows; with `whereNull('deleted_at')` the DB index would turn a duplicate into a 500. Each W2 BE slice checks its requests.
- **R2 Live dev DB backfill.**
  - The migrations are additive, guarded, chunked and idempotent. Run `migrate` only, take a dump first, and verify with the §3.4 queries.
  - If M2's NOT NULL step fails, some package lacks a version. The migration stops before anything destructive; fixing the data and re-running completes it.
- **R3 Version 1 reflects the package at backfill time,** not historical sale terms. The subscriptions' own `mrc_amount`/`otc_installation_fee` stay the billing truth.
- **R4 Profiles, tiers and categories are still edited in place.**
  - Changing a speed profile's Mbps changes every version and RADIUS group that uses it (`SpeedProfileRadiusObserver`).
  - To change terms for new sales only, create a new profile and point the package at it, which makes a new version. The user guide says so.
- **R5 `withTrashed()` in relation definitions** makes `whereHas`/`has` include trashed parents. Each slice greps its changed relations. Pickers query parent models directly and are unaffected.
- **R6 Index cost.** Location carries 18 correlated counts per row, all on FK indexes. W2-CORE-BE pastes `EXPLAIN` of the location index query; W1-MECH pastes it for the probe.
- **R7 Hooks are bypassed by query-builder writes.**
  - No `ServicePackage::query()->update()` or raw subscription insert exists outside the 4 sites fixed in §3.4.
  - Future bulk edits must go through models; otherwise the NOT NULL FK fails loudly.
- **R8 Version numbering race:** serialised by the integrity lane's lock, with `unique(service_package_id, version)` as backstop.
- **R9 Cross-lane overlap with `integrity-hardening`** (IntegrityDesign). 14 shared files are second-owner (§8.4), so most W2 slices wait for the matching integrity slice.
- **R10 OpenAPI test.** It fails until W3-DOCS regenerates the spec. Run it after all API changes; mobile ignores the additive fields.
- **R11 Deferred** (not built, by design):
  - future-dated versions;
  - versioning the profiles themselves;
  - an invoice CHECK constraint on the snapshots;
  - address `is_active`;
  - unit-level SPK aggregates;
  - audience targeting by version category (`CustomerAudienceQuery`);
  - a ticket-category lifecycle UI;
  - hard delete of trashed rows from the UI;
  - an E2E PDF text parser;
  - regenerating user-guide screenshots and PDFs.
- **R12 E2E cannot read PDF text without a new dependency.** The PHPUnit Blade render test proves the text; E2E checks the show page and the PDF headers.
- **R13 Ledger.** `master-lifecycle` is missing from `docs/plans/INBILS_OMP_BACKLOG.md` (0 grep hits), and `.scratch/master-lifecycle/issues/` does not exist. Main adds the ledger row and one ticket per slice.

## 8. Work breakdown

### 8.1 Waves and order

- **W1** (4 slices, run in parallel): W1-MECH, W1-UI, W1-VERSIONS, W1-SNAPSHOT-MIG.
  - W1-VERSIONS compiles against W1-MECH's trait (contract §4.1), so W1 is verified as one unit.
  - No W1 file overlaps the integrity lane.
- **W2** (15 slices, parallel within the wave, after W1). A slice that holds a second-owner file (§8.4) starts only after the matching integrity slice is accepted.
- **W3** (3 slices, after W2): W3-REPORTS-BE and W3-E2E run in parallel. W3-DOCS runs last, because the OpenAPI export needs every API change.

Skills each brief must carry:
- backend: `skill://laravel-best-practices`, `skill://tdd`;
- frontend: `skill://inertia-react-development`, `skill://tailwindcss-development`, `skill://antislop-ui`, `skill://antislop-copywriting`;
- QA: `skill://playwright-best-practices`, `skill://e2e`;
- all slices: `skill://ponytail`.

No slice runs the full suite mid-flight; Main verifies once per wave (§8.5).

### 8.2 Slices

Owned-file lists are exhaustive. NEW means the slice creates the file.

**W1-MECH** · backend · shared mechanism
- **Owned:** NEW `app/Traits/HasLifecycle.php`; NEW `app/Services/Core/LifecycleService.php`; NEW `app/Http/Controllers/Admin/LifecycleController.php`; NEW `tests/Feature/Lifecycle/LifecycleMechanismTest.php`; NEW `tests/Feature/Lifecycle/LifecycleReferencesMatchSchemaTest.php`.
- **Change:** §4.1–4.3 exactly; messages as in §4.2.
- **Consumes:** nothing.
- **Produces:** the trait API, `LifecycleService::*`, `LifecycleController::routes()`, error key `lifecycle`, flash texts.
- **Acceptance:** both test files pass; `EXPLAIN` of the probe index query (`withReferenceCounts`) is pasted.

**W1-UI** · frontend · composites and types
- **Owned:** `resources/js/Components/composite/DataTableActions.tsx`; NEW `resources/js/Components/composite/StatusFilter.tsx`; `resources/js/Components/composite/index.ts`; `resources/js/types/models.ts`; `resources/js/lib/statuses/common.ts`.
- **Change:** §5.1–5.2.
- **Consumes:** route names from §4.3 (by convention) and resource fields from §4.1.
- **Produces:** `StatusFilter`, `DataTableActions.lifecycle`, `LifecycleFields`, `ReferenceCount`, `LIFECYCLE_STATE`, `lifecycleStateOf`.
- **Acceptance:**
  - `npm run typecheck && npm run lint && npm run build` pass;
  - the existing `datatable-actions-delete.spec.ts` behaviour is unchanged (verified in W3).

**W1-VERSIONS** · backend · package versions data model
- **Owned:**
  - NEW `Modules/Service/database/migrations/2026_10_23_100000_create_service_package_versions_table.php`;
  - NEW `database/migrations/2026_10_23_100100_add_service_package_version_id_to_service_subscriptions_table.php`;
  - NEW `Modules/Service/app/Services/ServicePackageVersionBackfill.php`;
  - NEW `Modules/Service/Models/ServicePackageVersion.php`;
  - NEW `Modules/Service/Models/Concerns/RecordsVersions.php`;
  - NEW `Modules/Service/Http/Resources/ServicePackageVersionResource.php`;
  - `Modules/Service/Models/ServicePackage.php`;
  - `app/Models/Core/ServiceSubscription.php`;
  - `database/seeders/CompanySeeder.php`;
  - `database/seeders/E2eFixtureSeeder.php`;
  - `tests/Feature/CustomerStatusBackfillMigrationTest.php`;
  - NEW `tests/Feature/Service/ServicePackageVersionTest.php`.
- **Change:** §3.2, §3.4 M1–M2, §4.5 items 1–2 and 9.
  - ServicePackage also adopts HasLifecycle: noun "Paket layanan", ACTIVE filters, references from §4.4, plus the §4.8 withTrashed relations.
  - ServiceSubscription gets its §4.8 relations.
- **Consumes:** the W1-MECH trait.
- **Produces:** `ServicePackage::versions()/latestVersion()/VERSIONED_TERMS`, `ServiceSubscription::packageVersion()`, `ServicePackageVersionResource`, `ServicePackageVersionBackfill::{versions,subscriptions}()`.
- **Acceptance:**
  - the version test passes;
  - `tests/Feature/Service/ServicePackageCategoryMigrationTest.php` and `tests/Feature/CustomerStatusBackfillMigrationTest.php` pass;
  - E2E seeding (`node scripts/e2e-setup.mjs` against `inbils_test`) completes. Main runs this at W3.

**W1-SNAPSHOT-MIG** · backend · snapshot columns + backfill + units.is_active
- **Owned:** NEW `app/Support/AddressLine.php`; the NEW M3, M4, M5 and M6 migration files (paths in §3.4); NEW `tests/Feature/Lifecycle/SnapshotBackfillTest.php`.
- **Change:** §3.3, §3.4 M3–M6.
- **Consumes:** nothing.
- **Produces:** the §3.3 columns and `AddressLine::of()`.
- **Acceptance:**
  - the snapshot backfill test passes;
  - `./vendor/bin/sail artisan migrate --pretend` output for M1–M6 is pasted.

**W2-SERVICE-BE** · backend · Service module adoption
- **Owned:**
  - `Modules/Service/Models/{BandwidthProfile,SpeedProfile,SLATier}.php`;
  - `Modules/Service/Http/Controllers/{BandwidthProfileController,SpeedProfileController,SLATierController,ServicePackageController,ServicePackageCategoryController}.php`;
  - `Modules/Service/Http/Requests/{StoreServicePackageRequest,UpdateServicePackageRequest}.php`;
  - `Modules/Service/Http/Resources/{BandwidthProfileResource,SpeedProfileResource,SLATierResource,ServicePackageResource}.php`;
  - `Modules/Service/Policies/{BandwidthProfilePolicy,SpeedProfilePolicy,SLATierPolicy,ServicePackagePolicy}.php`;
  - `Modules/Service/routes/web.php`;
  - `Modules/Service/app/Services/SlaTierCompliance.php`;
  - NEW `tests/Feature/Service/ServiceMasterLifecycleTest.php`;
  - `tests/Feature/Service/ServicePackageServerTableExportTest.php`.
- **Change:**
  - §4.3 routes; remove the old deactivate route, the `deactivate()` method and `hasActiveSubscriptions`;
  - §4.4 rows: index, destroy, `can`, `filters`;
  - `formOptions` and the requests;
  - `show` passes `versions`;
  - the category guard;
  - `SlaTierCompliance` (§4.5 item 6);
  - `restore()` on the 4 policies.
- **Second-owner** (after integrity W2): `ServicePackageController.php`, `UpdateServicePackageRequest.php`.
- **Consumes:** W1-MECH, W1-VERSIONS.
- **Produces:** props `servicePackages[].references`, `versions`, `can.update/delete`.
- **Acceptance:** the new test and the updated export test pass.

**W2-SERVICE-FE** · frontend
- **Owned:** `resources/js/Pages/Admin/Service/BandwidthProfiles/Index.tsx`, `…/SpeedProfiles/Index.tsx`, `…/SLATiers/Index.tsx`, `…/Packages/Index.tsx`, `…/Packages/Show.tsx`, `…/Packages/Edit.tsx`.
- **Change:** the matching §5.3 rows.
- **Second-owner:** `Packages/Edit.tsx`.
- **Consumes:** W1-UI and the W2-SERVICE-BE props.
- **Acceptance:** typecheck, lint and build pass; the E2E bandwidth flow passes (W3).

**W2-INVENTORY-BE** · backend
- **Owned:**
  - `Modules/Inventory/app/Models/{Product,Category,Unit,Stock,StockMovement,StockAdjustmentRequest,InventoryRequest,InventoryRequestItem,MaterialReturnCase,MaterialReturnLine}.php`;
  - `Modules/Inventory/app/Http/Controllers/{ProductController,CategoryController,UnitController}.php`;
  - `Modules/Inventory/app/Http/Requests/{StoreProductRequest,UpdateProductRequest,StoreCategoryRequest,UpdateCategoryRequest}.php`;
  - `Modules/Inventory/app/Http/Resources/{ProductResource,CategoryResource,UnitResource}.php`;
  - `Modules/Inventory/app/Policies/{ProductPolicy,CategoryPolicy,UnitPolicy}.php`;
  - `Modules/Inventory/routes/web.php`;
  - NEW `tests/Feature/Inventory/InventoryMasterLifecycleTest.php`;
  - `tests/Feature/Inventory/ProductServerTableExportTest.php`.
- **Change:** §4.4 rows (Unit gets `is_active` fillable and cast); §4.8 relations; unit pickers show active units only.
- **Second-owner:** `ProductController.php`, `UpdateProductRequest.php`.
- **Consumes:** W1-MECH, M6.
- **Acceptance:** tests pass.

**W2-INVENTORY-FE** · frontend
- **Owned:** `resources/js/Pages/Admin/Inventory/Products/Index.tsx`, `…/Categories/Index.tsx`, `…/Units/Index.tsx`.
- **Change:** §5.3.
- **Acceptance:** typecheck, lint and build pass.

**W2-CORE-BE** · backend · locations, customers, addresses, document types, core routes
- **Owned:**
  - `app/Models/Core/{Location,Customer,CustomerAddress,CustomerContact,DocumentType}.php`;
  - `app/Http/Controllers/Admin/{LocationController,CustomerController,CustomerAddressController,DocumentController}.php`;
  - `app/Services/Core/LocationService.php`;
  - `app/Http/Resources/{LocationResource,CustomerResource,CustomerAddressResource,DocumentTypeResource}.php`;
  - `app/Policies/{LocationPolicy,CustomerPolicy,CustomerAddressPolicy,DocumentTypePolicy}.php`;
  - `routes/admin.php`;
  - NEW `tests/Feature/Lifecycle/CoreMasterLifecycleTest.php`;
  - `tests/Feature/Admin/LocationContractTest.php`;
  - `tests/Feature/CustomerAddressAuthorizeTest.php`;
  - `tests/Feature/DocumentTest.php`.
- **Change:**
  - §4.4 rows and §4.8 relations;
  - `routes/admin.php` registers **all** core lifecycle routes from §4.3 (locations, customers, customer-addresses, documents, vehicles, organizations, employees, evaluations) plus `subscriptions.change-package`.
- **Second-owner:** `CustomerController.php`.
- **Produces:** the route names consumed by W2-PEOPLE-BE, W2-SUBS-BE and the FE slices.
- **Acceptance:** tests pass; `EXPLAIN` of the location index is pasted.

**W2-PEOPLE-BE** · backend · vehicles, org units, employees, evaluations
- **Owned:**
  - `app/Models/Core/{Vehicle,OrganizationUnit,EmployeeProfile,EmployeeEvaluation}.php`;
  - `app/Http/Controllers/Admin/{VehicleController,OrganizationController,EmployeeController,EvaluationController}.php`;
  - `app/Services/Core/OrganizationService.php`;
  - `app/Http/Resources/{VehicleResource,OrganizationResource,EmployeeResource,EmployeeEvaluationResource}.php`;
  - `app/Policies/{VehiclePolicy,OrganizationPolicy,EmployeePolicy,EmployeeEvaluationPolicy}.php`;
  - NEW `tests/Feature/Lifecycle/PeopleLifecycleTest.php`;
  - `tests/Feature/OrganizationTest.php`;
  - `tests/Feature/VehicleTest.php`.
- **Change:** §4.4 rows, §4.8 relations.
- **Second-owner:** `EmployeeController.php`.
- **Consumes:** routes from W2-CORE-BE.
- **Acceptance:** tests pass.

**W2-SUBS-BE** · backend · explicit package migration and version readers
- **Owned:**
  - `app/Http/Controllers/Admin/SubscriptionController.php`;
  - `app/Services/Core/SubscriptionService.php`;
  - `app/Http/Requests/Admin/{StoreSubscriptionRequest,UpdateSubscriptionRequest}.php`;
  - NEW `app/Http/Requests/Admin/ChangeSubscriptionPackageRequest.php`;
  - `app/Http/Resources/SubscriptionResource.php`;
  - `app/Http/Resources/Api/V1/CustomerSubscriptionResource.php`;
  - `app/Http/Controllers/Api/V1/Customer/SubscriptionController.php`;
  - NEW `tests/Feature/Admin/SubscriptionChangePackageTest.php`.
- **Change:** §4.5 items 3, 7 and 8. The new request maps the public id the same way as `StoreSubscriptionRequest.php:22-26`.
- **Second-owner:** `SubscriptionController.php`, `UpdateSubscriptionRequest.php`.
- **Consumes:** W1-VERSIONS and the route from W2-CORE-BE.
- **Produces:** props `subscription.package_version`, `package_version_is_latest`, `packageOptions`, `can.changePackage`.
- **Acceptance:**
  - tests pass;
  - any existing subscription test that sent `service_package_id` to update is either adjusted in this slice's own test file or reported as needs-change.

**W2-NETWORK-BE** · backend
- **Owned:**
  - `Modules/NetworkAsset/app/Models/{NetworkAsset,NetworkAssetInstallation,NasDevice,OdnPort,OdnSplitter}.php`;
  - `Modules/Ipam/app/Models/{IpAddress,IpPrefix,Vlan}.php`;
  - `Modules/NetworkAsset/app/Http/Controllers/{NetworkAssetController,NetworkAccountController}.php`;
  - `Modules/NetworkAsset/app/Http/Resources/NetworkAssetResource.php`;
  - `Modules/NetworkAsset/app/Policies/NetworkAssetPolicy.php`;
  - `Modules/NetworkAsset/routes/web.php`;
  - `Modules/NetworkAsset/app/Services/{NetworkAccountProvisioner,NetworkAccountService}.php`;
  - `Modules/NetworkAsset/app/Console/SyncNetworkDevices.php`;
  - `Modules/NetworkAsset/app/Http/Requests/{StoreNetworkAccountRequest,UpdateNetworkAccountRequest}.php`;
  - `Modules/NetworkAsset/app/Observers/ServiceSubscriptionNetworkObserver.php`;
  - NEW `tests/Feature/NetworkAsset/NetworkAssetLifecycleTest.php`;
  - NEW `tests/Feature/NetworkAsset/ProvisioningUsesPackageVersionTest.php`.
- **Change:** the §4.4 NetworkAsset row (destroy closure), §4.5 item 5, §4.8 relations.
- **Acceptance:** the new tests, existing `tests/Feature/NetworkAsset/*` and `tests/Feature/Ipam/*` pass.

**W2-NETWORK-FE** · frontend
- **Owned:** `resources/js/Pages/Admin/NetworkAssets/Index.tsx`.
- **Change:** §5.3.
- **Acceptance:** typecheck, lint and build pass.

**W2-BILLING-BE** · backend
- **Owned:**
  - NEW `Modules/Billing/app/Support/InvoiceParty.php`;
  - `Modules/Billing/app/Services/BillingService.php`;
  - `Modules/Billing/app/Models/{Invoice,InvoiceItem,Payment,PaymentAttempt}.php`;
  - `Modules/Billing/app/Http/Controllers/InvoiceController.php`;
  - `Modules/Billing/app/Http/Controllers/Api/V1/Customer/InvoiceReceiptController.php`;
  - `Modules/Billing/app/Http/Resources/{InvoiceResource,PortalInvoiceResource}.php`;
  - `Modules/Billing/app/Policies/InvoicePolicy.php`;
  - `Modules/Billing/routes/web.php`;
  - `Modules/Billing/app/Queries/ReceivablesQuery.php`;
  - `resources/views/pdf/invoice.blade.php`;
  - NEW `tests/Feature/Billing/InvoiceSnapshotTest.php`.
- **Change:** §4.6 (invoice and receivables); Invoice `STATUSES` + HasLifecycle filters; §4.8 relations; the restore route.
- **Second-owner:** `BillingService.php` and `InvoiceController.php` (after integrity W1).
- **Acceptance:** the new test and `tests/Feature/Billing/*` pass.

**W2-SPK-BE** · backend
- **Owned:** `Modules/SPK/app/Services/SpkService.php`; `Modules/SPK/app/Models/{WorkOrder,WorkOrderItem,WorkOrderAssignment}.php`; `Modules/SPK/app/Http/Controllers/WorkOrderController.php`; `Modules/SPK/app/Http/Resources/WorkOrderResource.php`; `Modules/SPK/app/Policies/WorkOrderPolicy.php`; `Modules/SPK/routes/web.php`; NEW `tests/Feature/SPK/WorkOrderSnapshotTest.php`.
- **Change:** the §4.6 SPK writer and readers; `STATUSES` + filters; §4.8 relations; the restore route.
- **Second-owner:** `SpkService.php`, `WorkOrderController.php`.
- **Acceptance:** the new test and `tests/Feature/SPK/*` pass.

**W2-TICKET-BE** · backend
- **Owned:** `Modules/Ticketing/app/Services/TicketService.php`; `Modules/Ticketing/app/Models/{Ticket,TicketComment}.php`; `Modules/Ticketing/app/Http/Controllers/TicketController.php`; `Modules/Ticketing/app/Http/Resources/TicketResource.php`; `Modules/Ticketing/app/Http/Resources/Api/V1/CustomerTicketResource.php`; `Modules/Ticketing/app/Policies/TicketPolicy.php`; `Modules/Ticketing/routes/web.php`; NEW `tests/Feature/Ticketing/TicketSnapshotTest.php`.
- **Change:** §4.5 item 6 (SLA); the §4.6 ticket writer and readers; `STATUSES` + filters; §4.8 relations; the restore route.
- **Second-owner:** `TicketService.php`, `TicketController.php`.
- **Acceptance:** the new test and `tests/Feature/Ticketing/*` pass.

**W2-CORE-FE** · frontend
- **Owned:** `resources/js/Pages/Admin/Locations/Index.tsx`; `…/Customers/Index.tsx`; `…/CustomerAddresses/Index.tsx`; `resources/js/Components/composite/CustomerAddressEditor.tsx`; `…/Pages/Admin/Documents/Index.tsx`; `…/Subscriptions/Show.tsx`; `…/Subscriptions/Index.tsx`.
- **Change:** §5.3.
- **Second-owner:** `Subscriptions/Index.tsx`.
- **Acceptance:** typecheck, lint and build pass.

**W2-PEOPLE-FE** · frontend
- **Owned:** `resources/js/Pages/Admin/Vehicles/Index.tsx`; `…/Organizations/Index.tsx`; `…/Employees/Index.tsx`; `…/Evaluations/Index.tsx`.
- **Change:** §5.3.
- **Acceptance:** typecheck, lint and build pass.

**W2-DOCS-FE** · frontend
- **Owned:** `resources/js/Pages/Admin/Billing/Invoices/{Index,Show}.tsx`; `…/SPK/{Index,Show}.tsx`; `…/Tickets/{Index,Show}.tsx`.
- **Change:** §5.3.
- **Acceptance:** typecheck, lint and build pass.

**W3-REPORTS-BE** · backend
- **Owned:**
  - `Modules/Reporting/app/Queries/Concerns/ScopesToPackageCategory.php`;
  - `Modules/Reporting/app/Queries/{SalesKpiQuery,SlaComplianceQuery,TechnicianPerformanceQuery}.php`;
  - `Modules/Reporting/app/Queries/Sources/{BusinessSources,SlaSources,TechnicianSources}.php`;
  - `Modules/Reporting/app/Drill/{DrillRegistry,DrillKind}.php`;
  - `Modules/Reporting/app/Registry/MetricRegistry.php`;
  - NEW `tests/Feature/Reporting/ReportSnapshotStabilityTest.php`;
  - `tests/Feature/Reporting/ReportFormulaV1Test.php`.
- **Change:** §4.7.
- **Consumes:** W1-VERSIONS and the W2 snapshot writers.
- **Acceptance:** the new test, `tests/Feature/Reporting/*` and `Modules/Reporting/tests` pass.

**W3-DOCS** · backend (docs)
- **Owned:** `docs/api/mobile-v1.openapi.json`; `docs/user-guide/web.md`; `docs/architecture/database.md`; `docs/business/billing.md`; `docs/business/ticketing.md`; `CHANGELOG.md`.
- **Change:**
  - regenerate the OpenAPI spec (§4.9);
  - `database.md`: the lifecycle rules (references, hard delete only if unreferenced, reserved keys, versions, snapshots);
  - `billing.md`: package versions, change-package, the frozen invoice party;
  - `ticketing.md`: the closed-ticket snapshot and SLA from the version;
  - user guide: Nonaktifkan vs Hapus permanen, the Status filter (Dihapus, Semua), Pulihkan, package versions and Ganti paket, and the R4 warning;
  - a CHANGELOG entry (Keep a Changelog, at the top).
- **Second-owner:** `CHANGELOG.md`, if integrity W3 runs first.
- **Acceptance:** `./vendor/bin/sail artisan test --compact tests/Feature/Api/V1/OpenApiContractTest.php` passes.

**W3-E2E** · qa
- **Owned:** NEW `tests/e2e/master-lifecycle.spec.ts`; `tests/e2e/datatable-c.spec.ts`.
- **Change:** the §6 E2E flows; the param change at `datatable-c.spec.ts:260`.
- **Acceptance:** the §6 E2E command passes on `inbils_test`.

### 8.3 Ownership matrix check

| Slice | Files (NEW) | Shared-file notes |
|---|---|---|
| W1-MECH | 5 (5) | none |
| W1-UI | 5 (1) | sole owner of `DataTableActions.tsx`, `composite/index.ts`, `types/models.ts`, `statuses/common.ts` |
| W1-VERSIONS | 12 (7) | sole owner of `ServicePackage.php`, `ServiceSubscription.php`, both seeders |
| W1-SNAPSHOT-MIG | 6 (6) | none |
| W2-SERVICE-BE | 22 (1) | sole owner of `Modules/Service/routes/web.php` |
| W2-SERVICE-FE | 6 | none |
| W2-INVENTORY-BE | 26 (1) | sole owner of `Modules/Inventory/routes/web.php` |
| W2-INVENTORY-FE | 3 | none |
| W2-CORE-BE | 23 (1) | sole owner of `routes/admin.php` (also registers the people and subscription routes) |
| W2-PEOPLE-BE | 20 (1) | none |
| W2-SUBS-BE | 9 (2) | none |
| W2-NETWORK-BE | 21 (2) | sole owner of `Modules/NetworkAsset/routes/web.php` |
| W2-NETWORK-FE | 1 | none |
| W2-BILLING-BE | 15 (2) | sole owner of `Modules/Billing/routes/web.php` |
| W2-SPK-BE | 9 (1) | sole owner of `Modules/SPK/routes/web.php` |
| W2-TICKET-BE | 9 (1) | sole owner of `Modules/Ticketing/routes/web.php` |
| W2-CORE-FE | 7 | sole owner of `CustomerAddressEditor.tsx` |
| W2-PEOPLE-FE | 4 | none |
| W2-DOCS-FE | 6 | none |
| W3-REPORTS-BE | 12 (1) | none |
| W3-DOCS | 6 | none |
| W3-E2E | 2 (1) | none |

Every path appears in exactly one slice list; listing all §8.2 paths found no duplicates. Every file a contract needs has an owner: the trait, service, controller and routes; the resources that emit `deleted_at`/`references`; the version resource; `AddressLine`, `InvoiceParty` and the PDF view; and every route file that registers a restore route.

### 8.4 Cross-lane gates (`integrity-hardening`, agreed with IntegrityDesign 2026-10-18)

Integrity slices are the first owner of each file below. The master-lifecycle slice starts only after the integrity slice owning the file is accepted, and it must keep the integrity lines:
- `OptimisticLock::assert` inside locks;
- the `expected_updated_at` rule;
- the hidden field and `<ConflictBanner/>`.

Because integrity already locks inside `ServicePackageController::update`, versioning needs no controller change there.

| File | Integrity owner | Then |
|---|---|---|
| `Modules/Billing/app/Services/BillingService.php`, `Modules/Billing/app/Http/Controllers/InvoiceController.php` | integrity W1 | W2-BILLING-BE |
| `app/Http/Controllers/Admin/SubscriptionController.php`, `app/Http/Requests/Admin/UpdateSubscriptionRequest.php` | integrity W2 | W2-SUBS-BE |
| `resources/js/Pages/Admin/Subscriptions/Index.tsx` | integrity W2 | W2-CORE-FE |
| `Modules/Service/Http/Controllers/ServicePackageController.php`, `Modules/Service/Http/Requests/UpdateServicePackageRequest.php` | integrity W2 | W2-SERVICE-BE |
| `resources/js/Pages/Admin/Service/Packages/Edit.tsx` | integrity W2 | W2-SERVICE-FE |
| `Modules/Inventory/app/Http/Controllers/ProductController.php`, `Modules/Inventory/app/Http/Requests/UpdateProductRequest.php` | integrity W2 | W2-INVENTORY-BE |
| `app/Http/Controllers/Admin/CustomerController.php` | integrity W2 | W2-CORE-BE |
| `app/Http/Controllers/Admin/EmployeeController.php` | integrity W2 | W2-PEOPLE-BE |
| `Modules/SPK/app/Http/Controllers/WorkOrderController.php`, `Modules/SPK/app/Services/SpkService.php` | integrity W2 | W2-SPK-BE |
| `Modules/Ticketing/app/Http/Controllers/TicketController.php`, `Modules/Ticketing/app/Services/TicketService.php` | integrity W2 | W2-TICKET-BE |
| `CHANGELOG.md` | integrity W3 | W3-DOCS (append) |

Integrity-only files that master-lifecycle never touches: `useToast.ts`, `AdminLayout.tsx`, `bootstrap/app.php`, `HandleInertiaRequests.php`, `ResolveActiveCompany.php`, `app.tsx`, `types/index.d.ts`, `PromoBanner.php`, `Company.php`, and `CompleteSpkAction` (the SPK snapshot lives in `SpkService::approve`). Route files stay with master-lifecycle.

### 8.5 Main's verification per wave

**After W1:**
- `./vendor/bin/sail artisan test --compact tests/Feature/Lifecycle tests/Feature/Service/ServicePackageVersionTest.php tests/Feature/Service/ServicePackageCategoryMigrationTest.php tests/Feature/CustomerStatusBackfillMigrationTest.php`
- `npm run typecheck && npm run lint && npm run build`
- `./vendor/bin/sail artisan migrate --pretend`

**After W2:**
1. `./vendor/bin/sail artisan test --compact` (full suite) and `npm run build`.
2. On the dev DB: `mysqldump` first, then `./vendor/bin/sail artisan migrate`, then the §3.4 read-only queries.

**After W3:**
- the OpenAPI test;
- the Reporting tests;
- the §6 E2E command;
- `vendor/bin/pint --dirty --format agent`.

`composer audit` and `npm audit` are not needed, because no manifest changes.

This lane touches user data and permissions (customers, restore permission), so W2 needs a `security-reviewer` pass before anything is called done (hard rule 10).
