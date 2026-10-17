# UI: links, route() calls and forms use public ids

Status: done
Role: ui
Blocked by: 02
Spec: `../spec.md`

## Scope (owned files)

- Every page/composite that builds a URL or posts an id: `route('…', row.id)` keeps working when `id` is the ULID; fix every place that uses a non-`id` integer (e.g. `customer_id` from a nested object, numeric parsing like `Number(id)`, `parseInt`, `Number.isInteger` checks on ids, `id > 0` guards) and TS types (`id: number` → `string` for records).
- E2E helpers and specs that build URLs from ids or assert numeric ids (`/\/admin\/customers\/(\d+)$/` patterns) → ULID patterns; new spec asserting no integer record path in main list links.

## Acceptance criteria

- [x] `tsc` clean with record ids typed as `string`; ESLint clean; E2E specs type-check.

## Comments

### 2026-10-17, OpaqueIdsUi - landed (UI, shared types and E2E); the contract below is what the UI now assumes

Nothing committed; 144 files modified plus the new `tests/e2e/opaque-ids.spec.ts`. Work was done by sub-agents through a path-restricted edit tool (only `resources/js/**` and `tests/e2e/**`, never `resources/js/types/**`); every diff was read by the integration owner.

**What changed**

- `resources/js/types/**` (16 files): every record `id` and every reference field `number` → `string`: all `*_id`, the raw user FKs `assigned_to`, `created_by`, `uploaded_by`, `requested_by`, `approved_by`, `prepared_by`, `ready_by`, `issued_by`, `declared_by`, `received_by`, `reconciled_by`, `rejected_by`, `resolved_by`, `assigned_by`, `installed_by`, `blocked_by`, `acquired_by_user_id`, the polymorphic `reference_id` / `source_id` / `subject_id`, and `PortOption.value`. The three `Id` aliases are `string`. `PlatformFailedJob` has no `id` (keyed by `uuid`); permission rows and `PermissionOption` have no `id` (keyed by `name`).
- Pages, shared components, lib (73 + 19 + 3 files): page-local types converted by inspection (the compiler cannot find them: they are independent of the shared types); `useState<string | null>` for edit/reverse ids; `Record<string, number>` keyed by item id (Inventory request approve); the Stocks receive/adjust forms use `''` instead of `0` for 'nothing chosen'; no `Number()` left on `acquired_by_user_id`, `odn_port_id`, `input_port_id`, `company_id` (switch), `assignee_id` (dispatch), `customerId` props, `route('admin.odn.drops.store', portId)`; custody checks compare ULIDs (`returnCase.technician_id === currentUserId`, `received_by`); `RemoteOption.value` is `string`; `placeInCarousel` (lib/announcements.ts) orders newest first by ULID string comparison (a ULID sorts by creation time; the backfill is monotonic in id order); failed-job tables use `rowKey="uuid"` (`FailedListCard` got an optional `rowKey`), the permissions table `rowKey="name"`.
- Left on purpose: `Components/ui/Toast.tsx` (client counter id), `DataTable` `rowKey`/`getRowId`/`selectedKeys` (`string | number`, compared through `String()`), `vid`, quantities, page counters, map settings, `String(id)` wrappers and `route('x', row.id)` calls (all valid for strings).
- E2E: `support/demo.ts` and `support/platform.ts` regexes, 32 specs: every `\d+` id regex → `[0-9A-HJKMNP-TV-Z]{26}` with the `i` flag (ids are lowercase: `PublicId` = `strtolower(Str::ulid())`), numeric helper types and `0` sentinels → `string` / `''`, crafted unknown ids (`999_999_999`, `999999999`, `product_id=1`) → a well-formed unknown ULID so the 'unknown record' path still runs. 6 pre-existing type errors fixed (`datatable-actions-delete` narrowing to `never`; `fixture-policy` literal widening and `never`); `fixture-policy.spec.ts` URL strings (`/admin/users/123`) are synthetic matcher inputs and stay.
- New `tests/e2e/opaque-ids.spec.ts` (`@opaque-ids`, 3 tests): the 14 main lists have no `/admin` link or URL-like prop with an integer path segment (rendered `main a[href]` and the Inertia props; five seeded lists must show a ULID record link); GET 404 for integer `1` and for an unknown lowercase ULID on 16 record routes, with a 200 control on the seeded customer; a customer opens through the ULID in its list link.
- Pre-existing stale selfcheck repaired: `Pages/Admin/Reports/AuditLog.selfcheck.mjs` asserted `<EnumBadge map={AUDIT_MODULE}` while the page renders `map={MODULES}` (a spread of it); it failed at HEAD.
- `Pages/Error.tsx` (requested by OpaqueCore for K4): a 429 entry in `copy` ("Terlalu banyak permintaan", generic text because the access-link and isolir throttles render the same page, retry button) for the enumeration lockout, and the docblock lists 429. Checked with `npx tsc --noEmit` (0), ESLint (0) and Prettier on the file.

**Contract assumed from the backend (cross-check for 02, 05 and 06)**

- Every Inertia prop and API payload `id` of a record and every reference above is a ULID string or null; `auth.user.id`, `auth.user.company_id`, `auth.user.home_company_id`, `company.id`, `companies[*].id`, `companyHierarchy.parent_company_id` too; every `*Options` item and SearchOptions `value` is a string (the dev gallery closure in `routes/admin.php` included: `ui-pickers.spec.ts` expects a ULID there).
- Request bodies and filters carry ULIDs: location_id(s), product_id, customer_id, parent_id, new_parent_id (locations/organizations move), service_package_category_id(s), service_package_id, technician_id, subscription_id, category_id, acquirer_id, acquired_by_user_id, network_asset_id, user_id, company_id (switch and platform filters), platform_admin_id, sla/speed/bandwidth profile ids, organization_id, vehicle_id, employee_id, unit_id, warehouse_location_id, source_id, reference_id, inventory_request_id, input_port_id, feeds_splitter_id, odn_port_id, vlan_id (a record), ip_prefix_id, installation_address_id, serving_pop_id, pop_id, handler_id, splitter_id, `member_ids[]` (platform subsidiary dialog), dispatch assign `id` + `assignee_id` + `expected_assigned_to`.
- Company switch with an unknown or foreign ULID stays 403 (`head-company.spec.ts`); a record URL with an integer id or an unknown ULID is 404 (`opaque-ids.spec.ts`).

**Evidence (all on the shared tree, 2026-10-17)**

- `npx tsc --noEmit` → no output (0 errors; baseline 0). `npx eslint resources/js` → `ESLint: No issues found`. `npx prettier --check` on the 141 touched `.ts`/`.tsx` files → all formatted (7 files needed `--write` after the agents' edits; all were clean at HEAD).
- All 20 `*.selfcheck.mjs` / `*.test.mjs` under `resources/js` exit 0 (`for f in $(find resources/js -name '*.selfcheck.mjs' -o -name '*.test.mjs'); do node $f; done`).
- `npm run build` → `✓ built in 8.22s` (`tsc && vite build`).
- E2E type-check (no tsconfig covers `tests/e2e`; baseline 6 errors): `npx tsc --noEmit --module esnext --moduleResolution bundler --target esnext --strict --skipLibCheck --esModuleInterop --isolatedModules --types node tests/e2e/*.ts tests/e2e/support/*.ts` → 0 errors, new spec included.
- Audit greps over `resources/js`: no `id`, `*_id`, `*Id`, `*Ids`, `assigned_to` or `*_by` typed `number` except the Toast counter; no `Number(`, `parseInt`, `Number.is*`, `|| 0`, `?? 0` or `> 0` on an id; the remaining `Number(` calls parse coordinates, quantities, pages and settings. Over `tests/e2e`: the remaining `\d+` regexes match tab counts, ports, dates, money and `SPK-`/`TKT-` codes, never an id.

**Not verified**

- No E2E or browser run (orchestrator owns it) and no render against the live backend: ticket 02 was still landing resources, requests and routes while this was done, so the new spec and every converted spec are checked against the contract, not the running app.
- Depends on the rest of ticket 02 (its Comments list what is still integer: receivables `customer_id` / `subscriptions[].id`, material-return event `actor_id`, the dispatch assign body and the Dispatch/MyWork/Approval queries, dashboard and history queries, reporting filters and options, platform tenant/audit/support queries, notification subject ids, invoice/stock/ODN/ticket/SPK option lists, the dev gallery closure, API `{id}` lookups). Until they land, the pages built on them (and their E2E specs) get integers where the UI now types and compares ULID strings; the first red spec after 02 points at the missing conversion, not at the UI.
- `ReviewSheet.selfcheck.mjs` (headless Chromium) failed once in a final batch run and passed in all 7 later runs, alone and under ESLint load; no id-related cause found, treat as a Chromium start flake.
- Risk for 02/06: `company.logo` (`HandleInertiaRequests` ~72, `CompanyResource`) is `/storage/companies/<company_id>/company-logo/<media id>/<file>` (`CompanyMediaPathGenerator`), a public static path that carries the integer company id and the integer media id; the new spec ignores `/storage/` on purpose. Decide in the security review whether the logo path needs an opaque name (the private files are already served through `/admin/media/{ulid}`).
- Cosmetic: audit rows and the platform audit dialog show `Entitas #<ULID>` / `<type> #<ULID>` for a subject (they showed `#<integer>` before; no human code is in those payloads).
