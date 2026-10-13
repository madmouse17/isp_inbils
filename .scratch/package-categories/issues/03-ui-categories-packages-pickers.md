# UI: Kategori Paket page, package forms/list, grouped pickers, report filters

Status: done
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

- [x] `tsc`, ESLint, selfchecks clean; E2E spec type-checks.

## Comments

### Report (PkgCatUi, 2026-10-14)

Files
- New: `Pages/Admin/Service/PackageCategories/Index.tsx` (list, search, status filter, create/edit dialog, delete through `ConfirmRequestDialog` so the server's `errors.category` message stays in the dialog), `lib/packageGroups.ts` (`packageGroupOptions(groups, customerType?)`), `tests/e2e/package-categories.spec.ts` (5 serial tests).
- Changed: `ui/SearchSelect.tsx` (optional `group` on options: options of one group are listed together under a heading, groups in first-appearance order, keyboard index follows that order, `role=group` + `aria-labelledby`; options without `group` render as before), `lib/navigation.ts` (item "Kategori Paket", Lucide `Layers`, after Paket Layanan, `service.view|service.manage`), `tests/Feature/fixtures/sidebar-destinations.json`, `AdminMenuAuthorizationTest` (service pages case), `lib/navigation.selfcheck.mjs`, `tests/e2e/sidebar-ia.spec.ts` (`serviceLinks`), `Pages/Admin/Service/Packages/{Create,Edit,Index,Show}.tsx` (required "Kategori" select, inactive current category shown as "(nonaktif)" on edit, list column + filter `service_package_category_id`, detail line), `Pages/Admin/Customers/Create.tsx` and `Pages/Admin/Subscriptions/Index.tsx` (package `SearchSelect` fed by `packageGroups`), `Pages/Admin/Subscriptions/Show.tsx` (Kategori paket), `types/models.ts` (`package.category`), `Pages/Admin/Reports/{Sales,Business}.tsx` ("Kategori paket" select, kept after Run, note under it), E2E `support/demo.ts` (+`selectFirstSearchOption`) and the five specs that picked a package with `selectOption` (`customer-subscription`, `ipam-odn`, `number-sequences`, `whatsapp-notifications`, `kanban-t_49387795`), `reporting-sales-kpi` (new tab stop `SELECT:Kategori paket`).
- Also: `Subscriptions/Index.tsx` now calls `reset()` after a created subscription (the destructured `reset` was unused and failed ESLint).

Decisions
- Customer create re-orders the groups client-side when Type toggles (Individual: RUMAHAN; Company: BISNIS then KORPORAT, same rule as the server's `?customer_type`) instead of a partial reload: no request, no URL change, one more place that knows the codes.
- The category dialog edits `code` as disabled text (immutable on the server); no row-level "Nonaktifkan" button, deactivate is the "Aktif" switch in the dialog.
- Package pages stay English except the new parts (Kategori, filter, nonaktif): ui-consistency 04 translates the rest. Category selects are `NativeSelect` (short, fixed list), same as the page's other selects.
- Not added: category on the subscription list column (only on the detail, per scope).

Evidence
- `npx tsc --noEmit` → exit 0, no output. `npm run build` → `✓ built in 5.44s`.
- ESLint (json formatter) on all touched `resources/js` files → 0 errors, 0 warnings. `tests/e2e/**` is outside the ESLint project service, so it reports "was not found by the project service" for every spec (also for untouched ones); the specs are type-checked instead: `tsc --noEmit --skipLibCheck --strict --module esnext --moduleResolution bundler --target es2022 --lib es2023,dom --types node` over the new spec and the 7 edited specs → exit 0, no output.
- `node resources/js/lib/*.selfcheck.mjs` → all 7 print OK (copy, dateRange, dateValue, exportCsv, navigation, numberTemplate, statuses).
- Prettier `--write` on all touched files → formatted.
- Focused PHPUnit (locked sail command) `tests/Feature/AdminMenuAuthorizationTest.php tests/Feature/Service` → `Tests: 75 passed (2162 assertions)`.

Not verified
- No rendered-page check: the dev database still has `2026_10_14_200000_create_service_package_categories_table` pending (`sail artisan migrate:status`), so the new pages would 500; migrating it and running E2E were out of scope. `package-categories.spec.ts` and the five edited specs are NOT run: the picker interactions (`getByRole('group', { name })` on the grouped list, Radix Select filter on the package list, Escape closing the popover before switching Type) need one real run.
- The SearchSelect group markup (`li role=presentation` wrapping `ul role=group` inside the listbox) is not exercised by any selfcheck; the E2E spec asserts group names, keyboard order and filtered groups.
- Sales/Business page copy outside the new select is still English (ui-consistency 08).
