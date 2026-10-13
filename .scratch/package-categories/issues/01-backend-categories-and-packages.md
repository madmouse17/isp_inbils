# BACKEND: categories table, backfill, CRUD, package rules, seeders

Status: ready-for-agent
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

- [ ] Focused PHPUnit green; Pint; migration up/down on `inbils_test`; backfill table test lists all 15 seeded names.

## Comments
