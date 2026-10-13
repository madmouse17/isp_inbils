# BACKEND: report category filter, mobile API field

Status: done
Role: backend
Blocked by: 01 (schema)
Spec: `../spec.md`

## Scope (owned files)

- `Modules/Reporting`: `service_package_category_id` filter on Sales KPI and Business (request rule company-scoped `exists`, `ReportFilters`, queries, export scope line, drill links keep the filter); report page props `packageCategories` for the select.
- API: customer subscription resources add `package.category {id, name}`; eager-load to avoid N+1; regenerate `docs/api/mobile-v1.openapi.json`; update `OpenApiContractTest` expectations.
- Tests per spec items 5–6.

## Acceptance criteria

- [x] Focused PHPUnit (Reporting Sales/Business, Api/V1 Customer, OpenApiContract) green; Pint.

## Comments

### Contract for ticket 03 (PkgCatBackend, 2026-10-14)

- Filter query param **`service_package_category_id`** on `admin.reports.sales` and `admin.reports.business` (and on `admin.reports.business.export`, `admin.reports.business.drill`, `...drill.export`). Rule: nullable integer, must exist in the signed-in company (422 / session error otherwise).
- Page props on both pages: `packageCategories: {id, code, name, is_active}[]` (all categories of the company, by `sort_order`, id; inactive ones stay because history keeps them) and `filters.service_package_category_id` (string or null; Business `filters` carries it only when sent).
- What it limits. Sales KPI: `subscriptions_activated`, `active_mrr`, `invoiced`, `collected`, `outstanding` count only subscriptions on packages of the category and invoices/payments of those subscriptions; `customers_acquired` is not package-bound and stays whole. Business: MRR, subscription counts (active/suspended/terminated/new/churn), `revenue_paid`, `recurring_revenue`, `one_time_revenue`, `outstanding`, `installation_count`, the invoice-status chart and the MRR series; `asset_distribution` and `sla_compliance_pct` have no package and stay whole. Invoices without a subscription (one-time invoices) fall outside any category. Suggested UI note under the select: "Aset, SLA, dan pelanggan baru tidak terpengaruh."
- Business `drill` links keep the filter (`drill.mrr` etc. carry `service_package_category_id=<id>`), drill lists and tie-outs follow it; the Receivables reconcile hint is skipped while filtered. The CSV/PDF export lists the filter in its `Filters` column and tags the unfiltered figures `Package category filter not applied` in `Scope`. Sales has no export or drill.
- Mobile API: `customer/subscriptions` and `customer/subscriptions/{id}` rows now hold `package: {id, name, category: {id, name}}`; `docs/api/mobile-v1.openapi.json` regenerated (the `category` object is required inside `package`).

### Report (PkgCatBackend, 2026-10-14)

Files
- New: `Modules/Reporting/app/Queries/Concerns/ScopesToPackageCategory.php`, `tests/Feature/Reporting/ReportPackageCategoryFilterTest.php`.
- Changed: `ReportFilters` (`packageCategoryId`, `query()`, `payload()`, `charts()`), `ReportFilterRequest` (company-scoped `exists`), `ReportController` (`business`, `sales`, `packageCategories()`), `BusinessSources` (`packageCategoryId` on every subscription/invoice/installation source, new `invoices()`), `BusinessMetricsQuery`, `SalesKpiQuery`, `DrillRegistry`, `ReportExporter` (scope line), `CustomerSubscriptionResource`, `Api/V1/Customer/SubscriptionController` (`servicePackage:id,name,service_package_category_id` + `servicePackage.category:id,name`), `docs/api/mobile-v1.openapi.json`, tests `ProfileAndSubscriptionsTest` (keys, category, one category query for 3 subscriptions) and `SalesKpiReportTest` (new `filters` key).
- The OpenAPI file also changed one unrelated line: the forgot-password message now renders in Indonesian because another lane's uncommitted `phpunit.xml`, `config/app.php` and `lang/` set the locale to `id`; the contract test exports under that locale, so it only passes with the file regenerated under `APP_LOCALE=id`. If that lane is dropped, regenerate again.

Evidence
- `ReportPackageCategoryFilterTest` (8 tests, hand-computed figures with two categories and a one-time invoice): Sales totals all/Rumahan/Bisnis, Business figures all/Rumahan/Bisnis, props + drill links, drill rows + tie-out, export scope, foreign/unknown category → 422. Green inside `Tests: 131 passed` (command in ticket 01).
- `ProfileAndSubscriptionsTest` + `OpenApiContractTest` → `Tests: 6 passed (157 assertions)`. `scramble:export` prints the 5 known warnings only.
- Pint on all touched paths → passed.

EXPLAIN (MySQL `inbils_test`, tables nearly empty, so row estimates are 1 and the optimizer rewrites the semi-joins to PK lookups):
```
-- business mrr
select sum(mrc_amount) from service_subscriptions where company_id = 1 and service_package_id in (select id from service_packages where company_id = 1 and service_package_category_id = 1) and status = 'active' and deleted_at is null
 SIMPLE service_subscriptions ref  key=service_subscriptions_company_id_code_unique rows=1 Using where
 SIMPLE service_packages      eq_ref key=PRIMARY rows=1 Using where
-- business revenue_paid / outstanding (invoices.subscription_id in (select id from service_subscriptions where company_id=1 and service_package_id in (select id from service_packages ...)))
 SIMPLE invoices              ref  key=invoices_company_id_number_unique rows=1 Using where
 SIMPLE service_subscriptions eq_ref key=PRIMARY rows=1 Using where
 SIMPLE service_packages      eq_ref key=PRIMARY rows=1 Using where
-- installations: same shape on work_orders (key=work_orders_company_id_code_unique)
-- sales customers (one pass, 6 correlated aggregates): PRIMARY customers ref key=customers_company_id_code_unique rows=1; each DEPENDENT SUBQUERY: service_subscriptions/invoices/payments ref|eq_ref (company_id or PK), service_packages eq_ref PRIMARY
-- the new category lookup itself
select id from service_packages where company_id = 1 and service_package_category_id = 1
 possible_keys ...service_packages_company_id_service_package_category_id_index (new composite index is a candidate); chosen key=service_packages_company_id_code_unique rows=1
select * from service_package_categories where company_id = 1 and is_active = 1 order by sort_order
 range key=service_package_categories_company_id_is_active_sort_order_index Using index condition
```
No full scans in any plan. With production row counts the planner decides between the composite `(company_id, service_package_category_id)` index and the others; not measured on production-size data.

Risks
- The Sales aggregates stay one customer pass; the category adds one IN (sub-select) to five of the six correlated sums, which MySQL materialises or turns into lookups; not benchmarked on a large tenant.
- `invoices` older than the subscription link (nullable `subscription_id`) are excluded when a category is chosen.
