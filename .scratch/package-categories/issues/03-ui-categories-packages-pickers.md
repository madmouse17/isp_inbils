# UI: Kategori Paket page, package forms/list, grouped pickers, report filters

Status: ready-for-agent
Role: ui
Blocked by: 01, 02 (props contracts)
Spec: `../spec.md`

## Scope (owned files)

- New `resources/js/Pages/Admin/Service/PackageCategories/Index.tsx` (list + create/edit dialog + deactivate; delete shows the server's 422 message), sidebar item "Kategori Paket" (`lib/navigation.ts` + fixture + `AdminMenuAuthorizationTest` + selfcheck), Indonesian copy.
- `Pages/Admin/Service/Packages/{Create,Edit,Index}.tsx`: category select (required), list column + filter.
- Grouped package picker in `Pages/Admin/Customers/Create.tsx` and `Pages/Admin/Subscriptions/Index.tsx` (optgroup-style groups in `SearchSelect`; extend `SearchSelect` with optional `group` on options if needed, backwards compatible), preferred group first per customer type.
- Subscription detail shows the category; Reports Sales and Business get the "Kategori paket" filter.
- E2E `tests/e2e/package-categories.spec.ts` per spec; update specs that create packages or pick packages (`customer-subscription`, `datatable-*` package parts, `reporting-sales-kpi` filter count if any).

## Acceptance criteria

- [ ] `tsc`, ESLint, selfchecks clean; E2E spec type-checks.

## Comments
