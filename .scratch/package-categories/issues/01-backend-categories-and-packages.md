# BACKEND: categories table, backfill, CRUD, package rules, seeders

Status: done
Role: backend
Blocked by: sla-targets merged
Spec: `../spec.md`

## Scope (owned files)

- Migration(s) in `Modules/Service/database/migrations/`: create `service_package_categories`, seed P6 rows per company, add nullable `service_package_category_id`, backfill per P7, make NOT NULL + FK `restrictOnDelete`, index `(company_id, service_package_category_id)`; reversible.
- `Modules/Service` model `ServicePackageCategory` (+ factory, policy registration), `ServicePackage` relation/fillable, `ServicePackageFactory` default category, requests (`Store/UpdateServicePackageRequest`: required, company-scoped `exists`, active unless unchanged), new `Store/UpdateServicePackageCategoryRequest`, `ServicePackageCategoryController` (index, store, update, destroy with P8 rule), routes `admin.service.package-categories.*` (match the module's existing route naming), `ServicePackageController` (category filter, column in list/export, grouped picker helper `ServicePackageOptions::grouped(?string $customerType)` returning `[{category: {id,code,name}, packages: [{value,label,description}]}]`), resources.
- Default data for new companies (P6): `CompanyHierarchyService` defaults, setup wizard default data path, `CompanySeeder`; seeders `ServiceCatalogSeeder`, `FoundationDemo` set categories.
- Pickers' props in `CustomerController::create` and `SubscriptionController` (`packageGroups`).
- Tests per spec items 1–4.

## Acceptance criteria

- [x] Focused PHPUnit green; Pint; migration up/down on `inbils_test`; backfill table test lists all seeded names (19, see Report).

## Comments

### Contract for ticket 03 (PkgCatBackend, 2026-10-14)

Route names (Pelanggan & Layanan, same `admin.` prefix as `admin.sla-tiers.*`; resource `service-package-categories`, only index/store/update/destroy, no create/edit/show pages: the UI uses a dialog):

| Name | Verb URL | Payload / result |
|---|---|---|
| `admin.service-package-categories.index` | GET `/admin/service-package-categories` | Inertia `Admin/Service/PackageCategories/Index` |
| `admin.service-package-categories.store` | POST `/admin/service-package-categories` | `{code, name, sort_order?, is_active?}` |
| `admin.service-package-categories.update` | PUT `/admin/service-package-categories/{service_package_category}` | `{name, sort_order?, is_active?}` (code is immutable: not validated, ignored if sent) |
| `admin.service-package-categories.destroy` | DELETE `/admin/service-package-categories/{service_package_category}` | see below |

- Index props: `packageCategories` = paginated resource collection `{data: [{id, code, name, sort_order, is_active, packages_count}], links, meta}` (`packages_count` counts soft-deleted packages too: it is the number the delete rule uses). `filters` = `{is_active?, search?, sort?, direction?, per_page?}` (sort keys: `code,name,sort_order,is_active,created_at`; default `sort_order`). `can` = `{create, update, delete}` booleans. Staff (`service.view`) can open the page read-only.
- store/update redirect `back()` with flash `success` ("Kategori paket dibuat." / "Kategori paket diperbarui."). Validation errors use Indonesian messages under keys `code`, `name`, `sort_order`, `is_active`. `code` is trimmed and upper-cased by the server; allowed `A-Z 0-9 _ -`, max 30, unique per company ("Kode kategori sudah dipakai."); `name` max 100; `sort_order` 0-65535.
- destroy: a category used by any package (soft-deleted included) fails with a validation error under the key **`category`**: "Kategori masih dipakai {n} paket. Nonaktifkan saja." (HTTP 422 for JSON requests; for Inertia visits it arrives as `errors.category` on the redirect-back). Deactivate with `update` `{name, is_active: false}`. Unused category: deleted, flash `success` "Kategori paket dihapus.".
- Package form (`Admin/Service/Packages/Create` and `Edit`): extra prop `packageCategories: {id, code, name, is_active}[]` (plain array ordered by `sort_order`, id): active categories only on create; on edit also the package's current category when it is inactive. Payload field **`service_package_category_id`** (required, integer; must belong to the company and be active, or already be the package's category on update).
- Package list (`Admin/Service/Packages/Index`): rows carry `service_package_category_id` and `category: {id, code, name}` (also on Show/Edit `servicePackage`); extra prop `packageCategories: {id, code, name, is_active}[]` (all categories of the company, for the filter); filter query param **`service_package_category_id`**, echoed in `filters.service_package_category_id` (string). CSV/PDF export has a "Kategori" column and honours the same filter.
- Grouped picker props, shape `packageGroups: {category: {id, code, name}, packages: {value: string, label: string, description: string}[]}[]`: `label` = package name, `description` = "CODE · Rp 150.000/bln"; only active packages in active categories; empty groups omitted; packages by name; groups by category `sort_order` then id, with the customer-type preference first (Individual: `RUMAHAN`; Company: `BISNIS` then `KORPORAT`). The existing `packages` prop stays (the customer form still needs `price_mrc`/`price_otc` for prefill).
  - `Admin/Customers/Create`: `packageGroups` is ordered for `?customer_type=Individual|Company` (partial reload `only: ['packageGroups']` with that query when the type toggles); without the param the order is plain `sort_order`. Each group carries `category.code`, so the page can also re-order client-side.
  - `Admin/Subscriptions/Index` (customer subscriptions): `packageGroups` ordered for that customer's type.
- Subscription rows/detail (`SubscriptionResource.package`) now carry `category: {id, code, name}` (`servicePackage.category` is eager loaded on the customer subscription list and the subscription detail page).
- Reports (ticket 02, same contract): see ticket 02 Comments.

### Report (PkgCatBackend, 2026-10-14)

Files
- New: `Modules/Service/database/migrations/2026_10_14_200000_create_service_package_categories_table.php`, `Models/ServicePackageCategory.php` (`DEFAULTS`, `ensureDefaults(companyId)`), `database/factories/ServicePackageCategoryFactory.php`, `Policies/ServicePackageCategoryPolicy.php`, `Http/Controllers/ServicePackageCategoryController.php`, `Http/Requests/{Store,Update}ServicePackageCategoryRequest.php`, `Http/Resources/ServicePackageCategoryResource.php`, `app/Services/ServicePackageOptions.php` (`grouped(?string $customerType)`), tests `tests/Feature/Service/ServicePackageCategory{Test,MigrationTest}.php`.
- Changed: `ServicePackage` (fillable, `category()`), `ServicePackageResource`, `Store/UpdateServicePackageRequest`, `ServicePackageController` (filter, `category` eager load, export column, `packageCategories`), `ServicePackageFactory`, `Modules/Service/routes/web.php`, `AppServiceProvider` (policy), `CompanySeeder` (default categories for every new company: wizard and `createSubsidiary` both end in `CompanySeeder::runFor`), `ServiceCatalogSeeder`, `Demo/FoundationDemo`, `Admin/CustomerController::create`, `Admin/SubscriptionController` (`packageGroups`, `servicePackage.category` eager load).
- Decisions I made: category `code` is immutable after creation (the pickers find Rumahan/Bisnis/Korporat by code); deleting uses a validation error (`category` key) so it is 422 for JSON and `errors.category` for Inertia; backfill keywords match whole words (code/name split on non-alphanumerics), so "Content Pack" does not hit `ENT`; the Rumahan keyword list of P7 is the fallback (nothing else to match), so it is not coded separately.
- The ticket says "15 seeded names"; the seeders hold 19 packages (8 `ServiceCatalogSeeder`, 8 `CompanySeeder`, 3 `FoundationDemo`). The backfill test lists all 19 plus 5 edge cases (name only, code only, Korporat beats Bisnis, no keyword, whole words).

Evidence (all on MySQL `inbils_test` through the locked sail command)
- Red: with the migration file moved away, `ServicePackageCategoryMigrationTest` → `Tests: 26 failed (0 assertions)` (require of the missing migration). Green after: migration test `Tests: 24 passed (61 assertions)`; later rewritten as 3 tests (one table test over 24 rows, defaults + soft-deleted, up/down/FK RESTRICT/NOT NULL/index) and green inside the batches below.
- `tests/Feature/Service tests/Feature/Api/V1/Customer tests/Feature/Api/V1/OpenApiContractTest.php tests/Feature/Reporting/ReportPackageCategoryFilterTest.php tests/Feature/Reporting/SalesKpiReportTest.php` → `Tests: 1 failed, 131 passed`; the one failure was my N+1 test (it compared total query counts, which vary by one between requests). Reworked to assert exactly one `service_package_categories` query for three subscriptions; `ProfileAndSubscriptionsTest` + `OpenApiContractTest` → `Tests: 6 passed (157 assertions)`.
- Wider batch (Service, Api/V1/Customer, OpenApiContract, whole `Reporting`, CustomerPage, CustomerAccountCreation, SubscriptionService, SubscriptionServerTableExport, DemoDataSeeder, PhaseDResourceSmoke, AdminMenuAuthorization, `Tenant`) → `545 passed, 4 failed`: 3 were mine and fixed (Vite manifest in the new test, OpenAPI file, `SalesKpiReportTest` filters expectation); the 4th is `AdminMenuAuthorizationTest::test_developer_gallery_flag_matches_the_components_route` (200 instead of 404): another lane's uncommitted `routes/admin.php` now opens `/admin/components` in the `e2e` environment, test not updated yet. Not touched.
- Pint: `vendor/bin/pint --test` on every touched path → passed.

Risks / notes
- New migration is slow on a huge `service_packages` table only in the backfill (chunked by 500, one update per category per chunk).
- Customer create reads `customer_type` (not `type`): the form's own `type` field is a different thing.
- `admin.service-package-categories.*` is not in the sidebar yet: ticket 03 adds the item, `AdminMenuAuthorizationTest` and the dev-gallery failure above belong to that lane.
- Inertia page `Admin/Service/PackageCategories/Index` does not exist yet; my test asserts the component name with `component(..., false)` (no file check). Tighten it when ticket 03 lands.
