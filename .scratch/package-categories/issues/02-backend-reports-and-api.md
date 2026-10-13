# BACKEND: report category filter, mobile API field

Status: ready-for-agent
Role: backend
Blocked by: 01 (schema)
Spec: `../spec.md`

## Scope (owned files)

- `Modules/Reporting`: `service_package_category_id` filter on Sales KPI and Business (request rule company-scoped `exists`, `ReportFilters`, queries, export scope line, drill links keep the filter); report page props `packageCategories` for the select.
- API: customer subscription resources add `package.category {id, name}`; eager-load to avoid N+1; regenerate `docs/api/mobile-v1.openapi.json`; update `OpenApiContractTest` expectations.
- Tests per spec items 5–6.

## Acceptance criteria

- [ ] Focused PHPUnit (Reporting Sales/Business, Api/V1 Customer, OpenApiContract) green; Pint.

## Comments
