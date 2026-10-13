# Service package categories (Rumahan / Bisnis / Korporat …)

Status: done
Ledger: `docs/plans/INBILS_OMP_BACKLOG.md` → "ISP roadmap lanes (2026-10-12)" row `package-categories`
Source: owner question 2026-10-14 (grill-me): "pada layanan kenapa tidak ada kategori, seperti ini untuk home/company/yang lainnya?". Owner approved P1–P10, all recommended.

## Facts (2026-10-14, agent PackageCategoryFacts)

- `service_packages`: code, name, bandwidth/speed/SLA tier FKs (`restrictOnDelete`), `price_mrc`, `price_otc`, `contract_min_months`, description, `is_active`; `unique(company_id, code)`. No category/segment/type column. Filters: active, SLA tier, search.
- Segment lives only in seeded names and code prefixes: `ServiceCatalogSeeder.php:46-53` (PKG-HOME, -BIZ, -ENT, -SOHO), `CompanySeeder.php:216-223` (Home, Business, Trial, Promo, Gold), `FoundationDemo.php:72-74` (Home, Gamer, Kos dan Asrama).
- `customers.type` ∈ {Individual, Company}; drives only `tax_id`/`contact_person` validation and the demo static IP; not linked to packages.
- Packages appear in: customer onboarding (`CustomerController.php:75-78`), subscriptions (`SubscriptionController.php:48,258`), invoice line labels (`BillingService.php:54,138`), mobile API `package {id, name}` (`Api/V1/Customer/SubscriptionController.php:23,41`, OpenAPI `:4109-4125`), report drill-downs (`DrillKind.php:181,204`).
- Tax: one company-wide PPN rate (`BillingService::taxRateFor`); no per-package tax.

## Decisions

- **P1 Managed list per company.** New table `service_package_categories` (`company_id` FK, `code` string 30, `name` string 100, `sort_order` unsigned smallint default 0, `is_active` bool, timestamps; `unique(company_id, code)`, `index(company_id, is_active, sort_order)`), model `ServicePackageCategory` (`BelongsToCompany`), page "Kategori Paket" under Pelanggan & Layanan.
- **P2 Exactly one, required.** `service_packages.service_package_category_id` FK `restrictOnDelete`, NOT NULL after backfill (P7).
- **P3 No restriction by customer type.** Package pickers group options by category; the category matching the customer type comes first (Perorangan → Rumahan, Perusahaan → Bisnis then Korporat), resolved by `code` (`RUMAHAN`, `BISNIS`, `KORPORAT`), not by name.
- **P4 Visibility:** package list column + filter; grouped pickers on customer create and subscriptions; subscription detail on the web; customer app subscription detail; filter "Kategori paket" on the Sales KPI and Business reports (subscriptions/revenue limited to packages of that category).
- **P5 Tax unchanged:** one company PPN rate for every category.
- **P6 Seeded categories per company:** `RUMAHAN` Rumahan (1), `BISNIS` Bisnis (2), `KORPORAT` Korporat (3). Created for every existing company by the migration and for new companies wherever default company data is created today (`CompanyHierarchyService::createSubsidiary` defaults, setup wizard path, `CompanySeeder`).
- **P7 Backfill by code and name** (case-insensitive): `HOME|RUMAH|KOS|ASRAMA|GAMER|TRIAL|PROMO` → Rumahan; `BIZ|BUSINESS|BISNIS|SOHO|UMKM` → Bisnis; `ENT|ENTERPRISE|DEDICATED|KORPORAT|CORPORATE` → Korporat; first match by that order of precedence Korporat → Bisnis → Rumahan (so "Business 100Mbps Dedicated" lands in Korporat); no match → Rumahan. Seeders (`ServiceCatalogSeeder`, `CompanySeeder`, `FoundationDemo`) and `ServicePackageFactory` set the category explicitly.
- **P8 Management rules:** permissions as packages (`service.manage|create|update|delete`: admin, manager); a category used by any package (soft-deleted included) cannot be deleted (422 "Kategori masih dipakai {n} paket. Nonaktifkan saja."), only deactivated; inactive categories are hidden from pickers for new packages and from the package form unless already selected; `sort_order` orders pickers and lists; audit via activity log like other service masters.
- **P9 Mobile API:** customer subscription resources add `package.category` `{id, name}` (additive; OpenAPI regenerated); the customer app shows the category name on subscription detail and list.
- **P10 Order:** runs after `sla-targets` is merged and before `ui-consistency` ticket 04 (same Service pages). New pages and copy are Indonesian from the start and use `EnumBadge`-ready patterns where available.

## Testing decisions

- Migration: categories created for every company; backfill table-driven test over the seeded names (each of the 15 seeded/demo package names → expected category); `down()` drops the FK and table.
- Category CRUD: policy (manager ok, staff 403), unique code per company, delete blocked while used (incl. soft-deleted packages), deactivate hides from picker props.
- Package store/update: category required, must exist in the company and be active (or already the package's category on update).
- Pickers: grouped option props ordered by category `sort_order`, customer-type preference.
- Reports: Sales KPI and Business filter by category (fixtures with two categories).
- API: `package.category` present; OpenAPI contract test.
- E2E: create a category, assign a package, filter the package list, grouped picker on customer create; customer app widget test for the category label.

## Tickets

01-backend-categories-and-packages · 02-backend-reports-and-api · 03-ui-categories-packages-pickers · 04-mobile-customer-category · 05-qa-review-release
