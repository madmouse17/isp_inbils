# Remaining Pattern B Sorting — Binding Implementation Matrix

**Kanban:** `t_d88f5c39`  
**Snapshot:** `feat/datatable-server-export-foundation`, current shared working tree, 2026-07-27  
**Binding scope:** the 20 `useServerTable` consumers other than Customers and Users. Current WIP is evidence only; this matrix defines the target.

## Scope

- Add server sorting only for visible columns backed by a direct database column listed below.
- Every accepted sort key must be allowlisted before it reaches `orderBy`.
- Wire each applicable `DataTable` with `sortable: true`, `sortKey`, `sortDirection`, and `onSort={(key, direction) => table.visit({ sort: key, direction })}`.
- Keep the same filtered query for index and export so tenant scope, filters, RBAC, and ordering remain aligned.
- Preserve each page's current default order when no valid sort is requested.

## Out of scope

- Sorting relationship labels via joins/subqueries (`customer.name`, package name, location name, assignee name, and similar).
- Sorting accessors, counts, badges, action cells, or composite display keys.
- DataTable redesign, new dependencies, schema/index changes, export feature expansion, or unrelated UI cleanup.
- Adding the currently absent Bandwidth Profile, SLA Tier, or Speed Profile export routes/UI. Their controller methods exist, but no route is registered; sorting must not broaden into export remediation.

## Assumptions and dependencies

- `app/Http/Controllers/Concerns/HasIndexQuery.php` is the canonical allowlist behavior: unsupported keys fall back to the declared default; direction is normalized to `asc|desc`; supported page sizes are unchanged.
- `resources/js/hooks/useServerTable.ts` remains the query-state transport and resets changed filters/sorts to page 1.
- Tenant isolation remains model-driven through `App\Traits\BelongsToCompany`; parent-scoped pages additionally retain explicit same-company checks.
- Spatie roles are intentionally system-global and are the only table in this matrix without `BelongsToCompany`.
- Existing route middleware (`auth`, `verified`, `require.has.company`) and all controller `Gate::authorize(...)` calls remain unchanged.

## Matrix A — core/admin pages (allowlists already exist)

| Page | Frontend table | Backend endpoint/query | Current backend allowlist; default | Visible columns that SHALL be sortable | Visible columns that SHALL remain unsortable | Existing allowlist? |
|---|---|---|---|---|---|---|
| Employees | `resources/js/Pages/Admin/Employees/Index.tsx` | `app/Http/Controllers/Admin/EmployeeController.php` — `index`, `filteredQuery`, `export` | `employee_number`, `status`, `hire_date`, `created_at`; default `employee_number asc` | `employee_number`, `status` | `name` (user relation), `organization` (relation), `vehicle` (relation), `actions` | Yes |
| Number Sequences | `resources/js/Pages/Admin/NumberSequences/Index.tsx` | `app/Http/Controllers/Admin/NumberSequenceController.php` — `index`, `filteredQuery`, `export` | `entity_type`, `prefix`, `next_number`, `created_at`; default `entity_type asc` | `entity_type`, `prefix`, `next_number` | `padding`, `year_suffix` (direct columns but intentionally not supported by current allowlist) | Yes |
| Roles | `resources/js/Pages/Admin/Roles/Index.tsx` | `app/Http/Controllers/Admin/RoleController.php` — `index`, `filteredQuery`, `export` | `name`, `created_at`; default `name asc` | `name` | `permissions`/`permissions_count`, `users_count` (aggregates), `actions` | Yes |
| Vehicles | `resources/js/Pages/Admin/Vehicles/Index.tsx` | `app/Http/Controllers/Admin/VehicleController.php` — `index`, `filteredQuery`, `export` | `plate_number`, `type`, `brand`, `model`, `created_at`; default `plate_number asc` | `plate_number`, `type`, `brand`, `model` | `is_active` (not in current allowlist), `actions` | Yes |
| Customer Subscriptions | `resources/js/Pages/Admin/Subscriptions/Index.tsx` | `app/Http/Controllers/Admin/SubscriptionController.php` — `indexForCustomer`, `filteredQuery`, `export` | `code`, `status`, `created_at`; default `created_at desc` | `code`, `status` | `package` (relation), `mrc_amount`, `billing_day` (not in current allowlist), `actions` | Yes |
| Organizations | `resources/js/Pages/Admin/Organizations/Index.tsx` | `app/Http/Controllers/Admin/OrganizationController.php` — `index`, `filteredQuery`, `export` | `code`, `name`, `type`, `created_at`; default `code asc` | `code`, `name`, `type` | rendered parent detail, `path`, `children_count`, `is_active`, `actions` | Yes |
| Document Types | `resources/js/Pages/Admin/Documents/Index.tsx` | `app/Http/Controllers/Admin/DocumentController.php` — `index`, `filteredQuery`, `export` | `name`, `code`, `applies_to`, `created_at`; default `name asc` | `name`, `code`, `applies_to` | `is_required`, `expiry_days`, `is_active`, `actions` | Yes |
| Evaluations | `resources/js/Pages/Admin/Evaluations/Index.tsx` | `app/Http/Controllers/Admin/EvaluationController.php` — `index`, `filteredQuery`, `export` | `evaluated_at`, `score`, `reference_type`, `created_at`; default `evaluated_at desc` | `score`, `evaluated_at` | `employee`, composite `reference`, `customer_rating`, `evaluator`, `actions` | Yes |
| Customer Addresses | `resources/js/Pages/Admin/CustomerAddresses/Index.tsx` | `app/Http/Controllers/Admin/CustomerAddressController.php` — `index`, `filteredQuery`, `export` | `label`, `city`, `postal_code`, `created_at`; default `created_at desc` | `label`, `city`, `postal_code` | `address`, `is_installation_point`, `is_primary`, `actions` | Yes |
| Customer Contacts | `resources/js/Pages/Admin/CustomerContacts/Index.tsx` | `app/Http/Controllers/Admin/CustomerContactController.php` — `index`, `filteredQuery`, `export` | `name`, `position`, `phone`, `email`, `created_at`; default `name asc` | `name`, `position`, `phone`, `email` | `is_primary`, `actions` | Yes |

Current-tree note: these ten pages already contain most frontend wiring from prior WIP. The binding delta includes `postal_code` on Customer Addresses; do not reduce any target column because an earlier patch omitted it.

## Matrix B — module pages (real endpoints; allowlists still required)

The earlier “stub/unsupported” classification is stale. All ten pages below currently have real routes, controllers, tenant-scoped models, filtered pagination, and at least one legitimate direct-column sort.

| Page | Frontend table | Backend endpoint/query | Current ordering | Backend allowlist to add; preserved default | Visible columns that SHALL be sortable | Visible columns that SHALL remain unsortable | Existing allowlist? |
|---|---|---|---|---|---|---|---|
| Invoices | `resources/js/Pages/Admin/Billing/Invoices/Index.tsx` | `Modules/Billing/app/Http/Controllers/InvoiceController.php` — `index`, `filteredQuery`, `export` | fixed `issue_date desc` | `number`, `type`, `status`, `total`, `paid_amount`, `issue_date`, `created_at`; default `issue_date desc` | `number`, `type`, `status`, `total`, `paid_amount`, `issue_date` | `customer` relation, `actions` | No |
| Tickets | `resources/js/Pages/Admin/Tickets/Index.tsx` | `Modules/Ticketing/app/Http/Controllers/TicketController.php` — `index`, `filteredQuery`, `export` | fixed `created_at desc` | `code`, `title`, `source`, `status`, `priority`, `created_at`; default `created_at desc` | `code`, `title`, `source`, `status`, `priority` | `category` relation, computed `sla`, `actions` | No |
| SPK / Work Orders | `resources/js/Pages/Admin/SPK/Index.tsx` | `Modules/SPK/app/Http/Controllers/WorkOrderController.php` — `index`, `filteredQuery`, `export` | fixed `created_at desc` | `code`, `title`, `type`, `status`, `priority`, `created_at`; default `created_at desc` | `code`, `title`, `type`, `status`, `priority` | `customer` relation, `assignee` relation, `actions` | No |
| Network Assets | `resources/js/Pages/Admin/NetworkAssets/Index.tsx` | `Modules/NetworkAsset/app/Http/Controllers/NetworkAssetController.php` — `index`, `filteredQuery`, `export` | fixed `created_at desc` | `code`, `name`, `asset_type`, `serial_number`, `status`, `created_at`; default `created_at desc` | `code`, `name`, `asset_type`, `serial_number`, `status` | `location` relation, `actions` | No |
| Bandwidth Profiles | `resources/js/Pages/Admin/Service/BandwidthProfiles/Index.tsx` | `Modules/Service/Http/Controllers/BandwidthProfileController.php` — `index`, `filteredQuery`; latent unrouted `export` | fixed `name asc` | `name`, `download_mbps`, `upload_mbps`, `type`, `contention_ratio`, `is_active`, `created_at`; default `name asc` | `name`, `download_mbps`, `upload_mbps`, `type`, `contention_ratio`, `is_active` | `actions` | No |
| Service Packages | `resources/js/Pages/Admin/Service/Packages/Index.tsx` | `Modules/Service/Http/Controllers/ServicePackageController.php` — `index`, `filteredQuery`, `export` | fixed `name asc` | `code`, `name`, `price_mrc`, `price_otc`, `is_active`, `created_at`; default `name asc` | `code`, `name`, `price_mrc`, `price_otc`, `is_active` | `bandwidth_profile`, `speed_profile`, `sla_tier` relations; `actions` | No |
| SLA Tiers | `resources/js/Pages/Admin/Service/SLATiers/Index.tsx` | `Modules/Service/Http/Controllers/SLATierController.php` — `index`, `filteredQuery`; latent unrouted `export` | fixed `name asc` | `name`, `uptime_pct`, `response_time_hours`, `resolution_time_hours`, `credit_pct`, `is_active`, `created_at`; default `name asc` | `name`, `uptime_pct`, `response_time_hours`, `resolution_time_hours`, `credit_pct`, `is_active` | `actions` | No |
| Speed Profiles | `resources/js/Pages/Admin/Service/SpeedProfiles/Index.tsx` | `Modules/Service/Http/Controllers/SpeedProfileController.php` — `index`, `filteredQuery`; latent unrouted `export` | fixed `name asc` | `name`, `download_max_mbps`, `upload_max_mbps`, `burst_download_mbps`, `burst_upload_mbps`, `radius_profile_name`, `is_active`, `created_at`; default `name asc` | `name`, `download_max_mbps`, `upload_max_mbps`, `burst_download_mbps`, `burst_upload_mbps`, `radius_profile_name`, `is_active` | `actions` | No |
| Stock Movements | `resources/js/Pages/Admin/Inventory/Movements/Index.tsx` | `Modules/Inventory/app/Http/Controllers/StockController.php` — `movements`, `filteredMovementsQuery`, `movementsExport` | fixed `created_at desc` | movement-specific: `movement_type`, `quantity`, `balance_after`, `created_at`; default `created_at desc` | `movement_type`, `quantity`, `balance_after`, `created_at` | `product`, `from_location`, `to_location` relations; free-text `note` | No |
| Stocks | `resources/js/Pages/Admin/Inventory/Stocks/Index.tsx` | `Modules/Inventory/app/Http/Controllers/StockController.php` — `index`, `filteredQuery`, `export` | fixed `created_at desc` | stock-specific: `quantity`, `reserved_quantity`, `created_at`; default `created_at desc` | `quantity`, `reserved_quantity` | `product`, `location`, `path` relations; computed accessor `available` | No |

`StockController` owns two different query shapes. Do not use one broad shared constant for both. Use separate stock/movement allowlists or a helper that accepts the allowlist explicitly.

## Explicit “no legitimate sortable columns” classification

**None (0 of 20).** Every page has at least one visible direct database column with meaningful ordering. Relationship labels and computed values remain unsupported, but they do not make the whole page unsupported.

## Cross-cutting behavior that must be preserved

### Tenant scope

- All matrix models except Spatie `Role` use `App\Traits\BelongsToCompany`, whose global scope applies `table.company_id = CompanyService::currentId()`.
- Addresses, contacts, and subscriptions must continue querying through the already validated parent customer relation; retain `ensureSameCompany()` and child-parent ownership checks.
- Evaluation technician visibility (`employee_id = current user`) and SPK technician visibility (`assigned_to = current user`) must stay inside the same shared filtered query used by sorting and exports.
- Do not call `withoutCompany()` and do not replace relation-rooted queries with unscoped base queries.

### RBAC and routes

- Retain route middleware `auth`, `verified`, `require.has.company`.
- Retain index policy checks (`viewAny` or domain permission) and export permission checks.
- Sorting parameters are data, not authorization; they must never bypass policy/Gate checks.
- Registered export paths to preserve: all core/admin pages; Invoices; Tickets; SPK; Network Assets; Service Packages; Stocks; Stock Movements.
- Bandwidth Profiles, SLA Tiers, and Speed Profiles currently have export controller methods but no registered export route or frontend export control. Leave that gap unchanged in this task.

### Filters, pagination, and export parity

- Add `sort` and `direction` to each affected Inertia `filters` prop; retain all existing search/status/type/customer/location/etc. keys.
- Keep `paginate(10)->withQueryString()` (or existing `perPage()` behavior) unchanged.
- `useServerTable.visit({ sort, direction })` must reset to page 1 while later page visits retain sort/filter query state.
- Index and export must continue to originate from the same filtered query method. Do not duplicate filter clauses in export.
- Export links already copy `table.filters` and remove only `page`/`per_page`; retain `sort`/`direction` so exported row order matches the table.
- Unsupported sort identifiers must fall back to each row’s declared default and must never be interpolated directly into SQL.

## Implementation sequence

1. **Backend allowlists first — `code-executor`:** add minimal allowlisted sorting to the ten module controllers; expose `sort`/`direction` in Inertia filters; keep defaults and shared filtered queries. Use distinct allowlists for Stocks and Stock Movements.
2. **Frontend wiring — `ui-engineer`:** apply only the matrix’s “SHALL be sortable” keys across all 20 pages; add current sort props and `onSort`; do not mark relationship/computed/action keys.
3. **Focused feature tests — `code-executor` / `tester`:** cover an allowed ascending/descending sort, unsupported-key fallback, sort retained across page 2, export order parity, tenant isolation, and RBAC. Extend existing `*ServerTableExportTest` files and add focused SPK/Inventory/Service-profile coverage where no server-table test exists.
4. **Browser smoke — `tester`:** use deterministic seeded rows to prove one core page and one module page can sort, filter, then paginate without losing query state.
5. **Independent review — `reviewer`, then `qa-isp`:** compare changed frontend keys and backend allowlists against this matrix; reject broader or relation/computed sorting.

## Acceptance criteria

1. All 20 pages expose sorting for every matrix-approved visible key and no others.
2. Every accepted backend key is checked against an explicit allowlist; invalid keys safely use the page default.
3. Default ordering is unchanged when `sort` is absent or invalid.
4. Sorting retains filters and survives pagination; changing sort returns to page 1.
5. Index and registered CSV/PDF exports use the same tenant-scoped, RBAC-protected filtered query and preserve requested supported order.
6. Cross-company records never appear in index or export; technician restrictions remain effective on Evaluations and SPK.
7. No relation label, computed accessor/count, status badge abstraction, free-text note, or action cell is marked sortable unless this matrix explicitly lists its underlying direct column.
8. Bandwidth/SLA/Speed export routes are not added as a side effect.
9. Typecheck, build, scoped lint, focused feature tests, and deterministic browser smoke pass.

## Validation requirements

Run from the repository root and report exact exit status/output summary:

```text
npm run typecheck
npm run lint
npm run build
php artisan test tests/Feature/Admin/*ServerTableExportTest.php \
  tests/Feature/Billing/InvoiceServerTableExportTest.php \
  tests/Feature/Ticketing/TicketServerTableExportTest.php \
  tests/Feature/NetworkAsset/NetworkAssetServerTableExportTest.php \
  tests/Feature/Service/ServicePackageServerTableExportTest.php \
  <new focused SPK/Inventory/Service-profile sorting tests>
npx playwright test tests/e2e/datatable-customers.spec.ts --project=chromium
```

Required assertions, not just HTTP 200:

- allowed `asc` and `desc` row order;
- unsupported key falls back without SQL error;
- page 2 metadata and deterministic row order under a supported sort;
- filtered export contains only matching current-company rows in the same requested order;
- export forbidden without permission;
- technician sees only assigned/self rows after sorting.

## Risks

- **SQL injection:** passing client keys directly to `orderBy`; mitigate with exact allowlists.
- **Export divergence:** adding sort only to `index`; mitigate by sorting inside the shared filtered query used by both index and export.
- **Default-order regression:** replacing `latest()`/`orderBy('name')`; preserve the defaults in Matrix B.
- **Tenant/RBAC regression:** rebuilding queries outside model scopes or role restrictions; modify ordering only after existing filters/scopes.
- **Misleading UI:** sorting a displayed relation/accessor by its foreign key or a different raw column; leave those columns unsortable.
- **Concurrent WIP drift:** the shared worktree already contains partial sorting changes; implementation must diff against this matrix, not assume those changes are complete.

## Rollback

- Revert only per-page `sortable`, `sortKey`, `sortDirection`, and `onSort` additions.
- Revert controller allowlist/sort handling and restore each Matrix B fixed default ordering.
- Do not roll back shared `DataTable`, `useServerTable`, tenant traits, filters, pagination, exports, routes, or unrelated WIP.
- No database rollback is required.

## Recommended assignees

- Backend allowlists/tests: `code-executor`
- Frontend table wiring: `ui-engineer`
- Feature/browser validation: `tester`
- Security/scope review: `reviewer`
- Final independent quality gate: `qa-isp`

## Exact files inspected

Shared infrastructure and routing:

- `app/Http/Controllers/Concerns/HasIndexQuery.php`
- `app/Support/ExportQuery.php`
- `app/Traits/BelongsToCompany.php`
- `resources/js/hooks/useServerTable.ts`
- `routes/admin.php`
- `Modules/Billing/routes/web.php`
- `Modules/Ticketing/routes/web.php`
- `Modules/SPK/routes/web.php`
- `Modules/NetworkAsset/routes/web.php`
- `Modules/Service/routes/web.php`
- `Modules/Inventory/routes/web.php`

Core/admin frontend + backend:

- `resources/js/Pages/Admin/Employees/Index.tsx`
- `app/Http/Controllers/Admin/EmployeeController.php`
- `resources/js/Pages/Admin/NumberSequences/Index.tsx`
- `app/Http/Controllers/Admin/NumberSequenceController.php`
- `resources/js/Pages/Admin/Roles/Index.tsx`
- `app/Http/Controllers/Admin/RoleController.php`
- `resources/js/Pages/Admin/Vehicles/Index.tsx`
- `app/Http/Controllers/Admin/VehicleController.php`
- `resources/js/Pages/Admin/Subscriptions/Index.tsx`
- `app/Http/Controllers/Admin/SubscriptionController.php`
- `resources/js/Pages/Admin/Organizations/Index.tsx`
- `app/Http/Controllers/Admin/OrganizationController.php`
- `resources/js/Pages/Admin/Documents/Index.tsx`
- `app/Http/Controllers/Admin/DocumentController.php`
- `resources/js/Pages/Admin/Evaluations/Index.tsx`
- `app/Http/Controllers/Admin/EvaluationController.php`
- `resources/js/Pages/Admin/CustomerAddresses/Index.tsx`
- `app/Http/Controllers/Admin/CustomerAddressController.php`
- `resources/js/Pages/Admin/CustomerContacts/Index.tsx`
- `app/Http/Controllers/Admin/CustomerContactController.php`

Module frontend + backend:

- `resources/js/Pages/Admin/Billing/Invoices/Index.tsx`
- `Modules/Billing/app/Http/Controllers/InvoiceController.php`
- `resources/js/Pages/Admin/Tickets/Index.tsx`
- `Modules/Ticketing/app/Http/Controllers/TicketController.php`
- `resources/js/Pages/Admin/SPK/Index.tsx`
- `Modules/SPK/app/Http/Controllers/WorkOrderController.php`
- `resources/js/Pages/Admin/NetworkAssets/Index.tsx`
- `Modules/NetworkAsset/app/Http/Controllers/NetworkAssetController.php`
- `resources/js/Pages/Admin/Service/BandwidthProfiles/Index.tsx`
- `Modules/Service/Http/Controllers/BandwidthProfileController.php`
- `resources/js/Pages/Admin/Service/Packages/Index.tsx`
- `Modules/Service/Http/Controllers/ServicePackageController.php`
- `resources/js/Pages/Admin/Service/SLATiers/Index.tsx`
- `Modules/Service/Http/Controllers/SLATierController.php`
- `resources/js/Pages/Admin/Service/SpeedProfiles/Index.tsx`
- `Modules/Service/Http/Controllers/SpeedProfileController.php`
- `resources/js/Pages/Admin/Inventory/Movements/Index.tsx`
- `resources/js/Pages/Admin/Inventory/Stocks/Index.tsx`
- `Modules/Inventory/app/Http/Controllers/StockController.php`
- `Modules/Inventory/app/Models/Stock.php`
- `Modules/Inventory/app/Models/StockMovement.php`
- `Modules/Service/Models/ServicePackage.php`

Representative existing tests inspected:

- `tests/Feature/Admin/EmployeeServerTableExportTest.php`
- `tests/Feature/Admin/CustomerServerTableExportTest.php`
- `tests/Feature/Billing/InvoiceServerTableExportTest.php`
- `tests/Feature/Ticketing/TicketServerTableExportTest.php`
- `tests/Feature/NetworkAsset/NetworkAssetServerTableExportTest.php`
- `tests/Feature/Service/ServicePackageServerTableExportTest.php`
- `tests/e2e/datatable-customers.spec.ts`

No production code was edited by this planning task.
