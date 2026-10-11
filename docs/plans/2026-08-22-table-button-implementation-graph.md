# Graph Implementasi Standardisasi Table, Export, Button, dan Action

Tanggal: 2026-08-22  
Status: planning-only; belum merupakan persetujuan implementasi/deploy  
Sumber: audit Kanban `t_28212717`, `t_fc56789e`, `t_03c2c342`, `t_b1ac1648`

## 1. Scope

- Rekonsiliasi 41 surface tabular/non-tabular, 24 kontrak export, dan seluruh pola Button/action React/Inertia yang ditemukan audit.
- Menetapkan paket implementasi kecil dengan dependency dan batas konflik file.
- Menjaga canonical contract yang sudah terbukti: server pagination, `useServerTable`, allowlist backend, tenant/RBAC server-side, export parity, `Button`, `DataTable`, `DataTableActions`, dan accessible dialog composition.
- Menetapkan validation route: focused Laravel tests, lint/typecheck/build, Playwright, browser desktop/mobile/accessibility, QA tenant/RBAC/data-integrity, lalu review final.

## 2. Out of scope

- Implementasi, deploy, perubahan schema/data, dependency baru, redesign visual, atau approval final.
- Memaksa Location tree, report/chart, dashboard, search-result, dan embedded show tables menjadi server DataTable.
- Mengubah permission model atau tenant policy bisnis.
- Mengklaim PDF/XLSX asli sebelum format output dan library diputuskan serta diuji.
- Menghapus compatibility alias/legacy primitive sebelum zero-consumer proof pada snapshot implementasi.

## 3. Assumptions, konflik, dan keputusan yang belum final

### Verified

- Canonical frontend: `resources/js/Components/composite/DataTable.tsx`, `resources/js/hooks/useServerTable.ts`, `resources/js/lib/pagination.ts`, `resources/js/Components/ui/Button.tsx`.
- Canonical backend helper: `app/Http/Controllers/Concerns/HasIndexQuery.php`; shared export: `app/Support/ExportQuery.php`.
- `HasIndexQuery` menerima alias sort lama dan baru, tetapi UI target menggunakan `sort`/`direction`; pilihan `per_page` efektif adalah 10/25/50 dengan fallback 10.
- Root `resources/js/Components/ExportMenu.tsx` adalah implementasi aktif (15 consumer) dan satu-satunya yang terbukti membawa `canExport` + params URL. Versi `ui/ExportMenu.tsx` tidak ekuivalen API; versi composite nol consumer.
- Worktree sangat dirty dan target paths bertumpang tindih dengan WIP. Setiap implementer wajib mengambil fresh status/diff dan hanya menyentuh path batch-nya.

### Conflicts preserved, not silently resolved

1. Audit awal menyebut pilihan canonical ExportMenu UI, tetapi consumer evidence menunjukkan root ExportMenu adalah kontrak server-export aktif. Default aman: **KEEP root ExportMenu**, jangan migrasikan ke versi UI sampai parity API dibuktikan.
2. Audit baseline lama menyebut `per_page` 10/25/50/100 fallback 15 pada satu ringkasan; source audit terperinci menemukan backend/DataTable 10/25/50 fallback 10. Implementer harus re-read source saat mulai; target default adalah 10/25/50 fallback 10 kecuali product contract baru disetujui.
3. Explicit DataTable error/retry state belum canonical. Jangan menambah shared abstraction pada program ini; pertahankan error handling Inertia/page yang ada dan buat follow-up product decision bila diperlukan.
4. `window.confirm` harus diganti untuk accessibility, tetapi jangan mengubah semantics delete/route/policy. Gunakan `resources/js/Components/ui/alert-dialog.tsx` yang sudah ada; jangan jadikan `composite/ConfirmDialog.tsx` kontrak baru.
5. `DataTableActions` belum cukup fleksibel untuk semua variasi tanpa verifikasi. Migrasikan hanya pola Show/Edit/Delete yang benar-benar ekuivalen; KEEP local actions bila capability/route berbeda.

## 4. Matrix rekonsiliasi

### KEEP — server table lengkap (12)

Customers, Users, Roles, Organizations, CustomerContacts, Documents, customer Subscriptions, Service Packages, Bandwidth Profiles, Speed Profiles, SLA Tiers, Permissions. KEEP data/query contract; hanya action/confirmation/a11y gap masuk batch terpisah.

### FIX CONTRACT (6)

- `resources/js/Pages/Admin/Billing/Invoices/Index.tsx`: legacy query keys dan index/export drift.
- `resources/js/Pages/Admin/Tickets/Index.tsx`: legacy hook/query aliases.
- `resources/js/Pages/Admin/SPK/Index.tsx`: legacy hook dan belum ada focused server-table/export suite.
- `resources/js/Pages/Admin/NetworkAssets/Index.tsx`: legacy hook/query aliases.
- `resources/js/Pages/Admin/Inventory/Movements/Index.tsx`: backend belum memakai canonical allowlist/pagination; export route tidak diekspos UI.
- `resources/js/Pages/Admin/Inventory/Stocks/Index.tsx`: broken imports, route names, layout, raw table/buttons, dan Pagination prop contract; perlakukan sebagai recovery full-stack tersendiri.

### MIGRATE — manual table, backend umumnya siap (8)

- `resources/js/Pages/Admin/Employees/Index.tsx`
- `resources/js/Pages/Admin/Evaluations/Index.tsx`
- `resources/js/Pages/Admin/Vehicles/Index.tsx`
- `resources/js/Pages/Admin/Inventory/Products/Index.tsx`
- `resources/js/Pages/Admin/Inventory/Categories/Index.tsx`
- `resources/js/Pages/Admin/Inventory/Units/Index.tsx`
- `resources/js/Pages/Admin/CustomerAddresses/Index.tsx`
- `resources/js/Pages/Admin/NumberSequences/Index.tsx`

### NOT A TABLE / KEEP embedded / DEFER

- NOT A TABLE: Locations tree; Dashboard; Reports hub/charts; Asset/SLA/StockCard reports; Trace; Inventory Find; Components gallery; form pages.
- KEEP embedded: Users Show, Customers Show/`CustomerRelatedTables`, SPK Show, Invoice Show, Product Show, NetworkAsset Show.
- DEFER: AuditLog (bounded 500, product pagination decision needed) dan Receivables (aggregate AR, product decision needed).

## 5. Prioritas gap

### P0 — security/data correctness/build blockers

- Tenant scope, authorization, and index/export parity must be proven for every changed backend contract.
- Recover Stocks page contract; do not mask baseline typecheck/build failures.
- Fix CSV mapper row-shape risks before broad export UI rollout.
- Invoice index/export drift.
- Missing focused contract suites: CustomerAddress, Stock, StockMovement, SPK.

### P1 — canonical behavior and reachable controls

- Four legacy `useTableQuery` pages and Movements canonical query/pagination contract.
- Eight manual-table migrations.
- Export controls only when authorized; endpoint remains independently protected.
- Default-sort parity for Employee and Vehicle.

### P2 — accessibility and consistency

- Replace `window.confirm` with AlertDialog composition.
- Stable accessible pagination names; reconcile stale `Next page` Playwright expectation with UI.
- Raw Buttons in Stocks; legacy Breeze Button migration.
- Empty states, keyboard focus, mobile overflow, `aria-sort`, and icon accessible names.

### P3 — cleanup after migration proof

- Normalize Button imports to `@/Components/ui` barrel only after affected batches settle.
- Remove dead composite ExportMenu only after fresh direct + barrel search is zero.
- Decide root-vs-ui ExportMenu future only after API parity and all consumers migrate.
- Remove legacy Breeze buttons only after zero consumers.
- Casing rename (`alert-dialog.tsx`) only in isolated case-safe change after zero import ambiguity; optional, not required for behavior.

## 6. Dependency graph and assignee roster

```text
G0 product/canonical decision + clean ownership snapshot
 |
 +--> B1 export security/parity core [code-executor]
 |      --> T1 focused backend tests [tester]
 |      --> Q1 tenant/RBAC/data integrity [qa-isp]
 |      --> R1 [reviewer]
 |
 +--> B2 query alias contracts (Invoice/Ticket/SPK/NetworkAsset) [code-executor]
 |      --> U2 frontend hook migration, per file [ui-engineer]
 |      --> T2 --> Q2 --> R2
 |
 +--> B3 Inventory Movements contract [code-executor]
 |      --> U3 Movements UI [ui-engineer]
 |      --> T3 --> Q3 --> R3
 |
 +--> B4 Stocks recovery [code-executor]
        --> U4 Stocks UI [ui-engineer]
        --> T4 --> Q4 --> R4

After relevant backend gates:
U5a Employees + Evaluations -----\
U5b Vehicles + NumberSequences ---+--> T5 integration/build/browser --> Q5 --> R5
U5c Products ---------------------+
U5d Categories + Units -----------+
U5e CustomerAddresses ------------/
(frontend-only lanes may run parallel only when exact files do not overlap)

After U2-U5 stable:
U6 action/AlertDialog batches by module --> T6 browser/a11y --> Q6 --> R6
U7 Auth/Profile canonical Button --------> T7 --> Q7 --> R7

Only after all zero-consumer probes:
C1 dead export/legacy cleanup --> C2 barrel/import cleanup --> C3 optional casing cleanup
                              --> type/lint/build/full focused regression --> QA --> reviewer
```

Roster: `code-executor` backend contracts; `ui-engineer` UI migration; `tester` automated/integration/build/browser evidence; `qa-isp` tenant/RBAC/data integrity/UI contract gate; `reviewer` final technical gate. User/Baginda retains product/final approval.

## 7. Implementation batches

Every batch starts with `git status --short`, a path-scoped diff, and ownership confirmation. No batch may reformat or revert unrelated WIP.

### B1 — Export correctness and coverage (FIX CONTRACT, P0)

Paths:
- `app/Support/ExportQuery.php`
- affected export controllers discovered by the 24-contract matrix, especially `Modules/Billing/app/Http/Controllers/InvoiceController.php`, `Modules/Inventory/app/Http/Controllers/StockController.php`, SPK controller handling `admin.spk.export`, and `app/Http/Controllers/Admin/CustomerAddressController.php`
- existing `tests/Feature/**/*ServerTableExportTest.php`
- new focused suites beside domain tests for CustomerAddress, Stock, StockMovement, and SPK

Minimum change: make row mapper shape match format writer; make index/export share tenant-scoped search/filter/sort; preserve max-row cap; add missing tests. Do not redesign format menu.

Protected behavior: route names, permission names, tenant source, CSV headers/order unless a proven bug, current root ExportMenu URL params.

Rollback: revert controller/helper/test batch together; never retain UI export affordance without secured backend.

Acceptance:
- Given another-company data, index and every export exclude it.
- Given missing permission, export is 403 regardless of UI.
- Given identical params, index and CSV rows have identical filter/sort semantics.
- Mapper produces rectangular rows matching headers; cap is enforced.
- Unsupported PDF/XLSX claims are either backed by real bytes/MIME tests or remain explicitly unresolved; no fake success assertion.

Validation:
- `vendor/bin/phpunit tests/Unit/Support/ExportQueryTest.php`
- focused domain `*ServerTableExportTest.php` files changed/added
- `vendor/bin/phpstan analyse --memory-limit=512M` when baseline permits

### B2 — Legacy query contract, one module per sub-batch (FIX CONTRACT, P1)

Paths by independent lane:
- Billing: `resources/js/Pages/Admin/Billing/Invoices/Index.tsx`, `Modules/Billing/app/Http/Controllers/InvoiceController.php`, `tests/Feature/Billing/InvoiceServerTableExportTest.php`
- Ticketing: `resources/js/Pages/Admin/Tickets/Index.tsx`, `Modules/Ticketing/app/Http/Controllers/TicketController.php`, `tests/Feature/Ticketing/TicketServerTableExportTest.php`
- SPK: `resources/js/Pages/Admin/SPK/Index.tsx`, SPK WorkOrder controller for index/export, new `tests/Feature/SPK/WorkOrderServerTableExportTest.php`
- NetworkAsset: `resources/js/Pages/Admin/NetworkAssets/Index.tsx`, NetworkAsset controller, `tests/Feature/NetworkAsset/NetworkAssetServerTableExportTest.php`
- shared hook only if needed: `resources/js/hooks/useTableQuery.ts`; prefer no shared edit by migrating page to `resources/js/hooks/useServerTable.ts`

Minimum change: backend accepts canonical keys while compatibility aliases remain; UI migrates to `useServerTable`; page change behavior and filter preservation remain.

Protected: deep links with old aliases during transition, filter set, default sort, Inertia preserve-state/scroll semantics, export params.

Rollback: revert each domain lane independently; retain backend aliases even if UI rollback occurs.

Acceptance: URL uses canonical keys; old aliases remain safe; changes except page reset to page 1; invalid direction/sort/per_page fall back; export parity and authorization pass.

Validation: focused domain PHPUnit; `npm run typecheck`; `npm run lint`; focused Playwright journey for changed page.

### B3 — Inventory Movements (FIX CONTRACT, P0/P1)

Paths:
- `Modules/Inventory/app/Http/Controllers/StockController.php`
- `resources/js/Pages/Admin/Inventory/Movements/Index.tsx`
- new `tests/Feature/Inventory/StockMovementServerTableExportTest.php`

Minimum change: add explicit sort allowlist and canonical bounded pagination to movements query; preserve existing filters and tenant scope; wire canonical hook and authorized ExportMenu.

Protected: stock accounting semantics and movement records; no mutation/service changes.

Rollback: revert controller/page/test as one full-stack batch.

Acceptance: tenant/filter/sort/page/export parity; unauthorized export hidden + 403; no unbounded result; existing movement values/order preserved under default request.

Validation: focused PHPUnit, typecheck/lint/build, desktop/mobile browser smoke.

### B4 — Stocks recovery (FIX CONTRACT, P0, serialized)

Paths:
- `Modules/Inventory/app/Http/Controllers/StockController.php`
- `Modules/Inventory/routes/web.php`
- `resources/js/Pages/Admin/Inventory/Stocks/Index.tsx`
- `tests/Feature/Inventory/StockPermissionTest.php`
- new `tests/Feature/Inventory/StockServerTableExportTest.php`
- focused inventory E2E only after safe isolated test DB setup

Minimum change: correct imports to existing repository modules, route names to actual `admin.stocks.*`, layout to current Admin convention, Pagination/DataTable contract, canonical Button, and permission-gated Receive. Do not restore Issue/Transfer/Adjust without proven permissions/backend contract.

Protected: Receive workflow, stock quantities, tenant constraints, service transaction boundaries.

Rollback: revert whole lane; do not partially retain route/UI mismatch.

Acceptance: typecheck errors attributed to Stocks are zero; valid route navigation works; unauthorized mutation control absent and endpoint forbidden; Receive still works once; no duplicate or negative stock side effects; index/export contract covered.

Validation: focused Inventory PHPUnit, `npm run typecheck`, `npm run lint`, `npm run build`, inventory browser smoke desktop/mobile/keyboard.

### B5 — Manual table migrations (MIGRATE, P1)

Independent conflict lanes:
- B5a: `resources/js/Pages/Admin/Employees/Index.tsx`, `resources/js/Pages/Admin/Evaluations/Index.tsx`
- B5b: `resources/js/Pages/Admin/Vehicles/Index.tsx`, `resources/js/Pages/Admin/NumberSequences/Index.tsx`
- B5c: `resources/js/Pages/Admin/Inventory/Products/Index.tsx`
- B5d: `resources/js/Pages/Admin/Inventory/Categories/Index.tsx`, `resources/js/Pages/Admin/Inventory/Units/Index.tsx`
- B5e: `resources/js/Pages/Admin/CustomerAddresses/Index.tsx`
- matching existing feature tests; add CustomerAddress suite

Minimum change: replace manual Table/Pagination wiring with canonical DataTable + `useServerTable` + `toPagination`; use root ExportMenu when route/capability exists; preserve columns, filters, links, statuses, and action visibility. Backend changes only for demonstrated default-sort/export drift.

Protected: domain fields, create/edit/delete routes, filter values, row labels, permissions, tenant source.

Rollback: per lane/page; backend default-sort fix must travel with its focused test.

Acceptance: no full dataset client load; canonical URL keys and page reset; same visible columns/filters/actions; empty state; horizontal scroll; exports only when authorized; Employee/Vehicle default sort matches export.

Validation per lane: matching `*ServerTableExportTest.php`; typecheck/lint; build after fan-in; browser search/sort/per-page/pagination/deep-link/back-forward.

### B6 — Actions and destructive confirmation (MIGRATE, P2)

Module-sized lanes, no shared-component edit in parallel:
- Admin: Documents, CustomerContacts, CustomerAddresses, Vehicles
- Inventory: Products, Categories, Units
- Service: Packages, SLATiers, SpeedProfiles, BandwidthProfiles
- composites: `resources/js/Components/composite/CustomerAddressEditor.tsx`, `CustomerContactEditor.tsx`
- shared last: `resources/js/Components/composite/DataTableActions.tsx`, `resources/js/Components/ui/alert-dialog.tsx` only if API deficiency is proven

Minimum change: compose existing AlertDialog for delete; use DataTableActions only for equivalent capability shape; replace variant class overrides with defined variants where appearance remains acceptable.

Protected: delete endpoint/method, success/error handling, capability visibility, link semantics, loading/disabled behavior.

Rollback: per module; shared component change lands only after all intended consumers are known.

Acceptance: cancel never sends request and returns focus; confirm sends one request; dialog has role/title/description/cancel/confirm accessible names; keyboard-only operation works; unauthorized action absent; backend still rejects unauthorized direct requests.

Validation: focused mutation authorization tests; Playwright dialog cancel/confirm; keyboard/focus smoke at 1280px and 375px.

### B7 — Auth/Profile canonical Button (MIGRATE, P2)

Paths:
- Auth pages importing `resources/js/Components/PrimaryButton.tsx`
- Profile partials importing Primary/Secondary/Danger buttons, especially `resources/js/Pages/Profile/Partials/DeleteUserForm.tsx`
- `resources/js/Components/PrimaryButton.tsx`, `SecondaryButton.tsx`, `DangerButton.tsx` only during later cleanup

Minimum change: imports/props to canonical Button with default/secondary/destructive variants; verify the actual Modal API (`show` vs `open`) without broad Modal refactor.

Protected: submit type, processing disabled/loading, focus behavior, password confirmation, auth routes.

Rollback: revert consumer imports; retain legacy files until zero consumers.

Acceptance: all auth/profile submissions preserve payload/navigation/errors; loading prevents duplicate submit; delete confirmation remains keyboard/focus safe.

Validation: typecheck/lint, focused auth/profile feature tests, browser login/register/profile/delete smoke where safe.

### B8 — Pagination and shared a11y (FIX CONTRACT, P2, serialized)

Paths:
- `resources/js/Components/composite/DataTable.tsx`
- relevant Pagination primitive under `resources/js/Components/ui/`
- `tests/e2e/datatable-customers.spec.ts`

Minimum change: stable accessible names (`Previous page`, `Next page`), boundary disabled states, preserve visible copy if desired. Do not weaken E2E locator merely to pass.

Protected: pagination callback/page math and responsive layout.

Acceptance: E2E accessible names match rendered controls; keyboard focus visible; current/last page boundaries cannot navigate outside range; `aria-sort` remains correct.

Validation: focused Playwright DataTable test at desktop and mobile; typecheck/lint/build.

### C1-C3 — Cleanup (P3, only after proof)

C1 candidates:
- `resources/js/Components/composite/ExportMenu.tsx`
- legacy Button files after B7
- `resources/js/Components/ui/ExportMenu.tsx` only after explicit root-vs-ui decision and zero barrel/direct consumers

C2: normalize direct `@/Components/ui/Button` imports to `@/Components/ui` in isolated module batches; do not mix with behavior changes.

C3 optional casing: `resources/js/Components/ui/alert-dialog.tsx`. Case-only rename must use a two-step Git rename on Windows and update all imports atomically.

Required preflight for each deletion/rename:
- search direct path imports;
- search named/default symbol use;
- inspect `resources/js/Components/ui/index.ts` and `resources/js/Components/composite/index.ts`;
- run typecheck/lint/build;
- run focused browser inventory.

Rollback: restore file/export/import atomically. No cleanup shares a commit with contract behavior.

## 8. Validation package

### Automated gates

1. Backend per batch: focused PHPUnit file(s), including tenant A/B, permission allowed/forbidden, validation fallback, sort/filter/page, and index/export parity.
2. Frontend per batch: `npm run typecheck`, `npm run lint`, and `npm run format:check` (not mutating `npm run format`).
3. Fan-in: `npm run build` only after typecheck/lint pass.
4. Browser inventory: `npx playwright test tests/e2e/datatable-customers.spec.ts --list`.
5. Runtime E2E only on isolated `inbils_e2e` after explicit test-DB safety confirmation; repository scripts run `migrate:fresh --seed` and must never target shared/dev/prod DB.

### Browser smoke checklist per changed page

Desktop 1280x800 and mobile 375x812:
- open authenticated page with allowed role;
- search debounce, clear search, filter, sortable header asc/desc;
- per-page and pagination; refresh/deep-link/back-forward retain state;
- no page-level horizontal overflow; table scroll container usable; actions not clipped;
- loading skeleton and empty state;
- export URL includes active params and download is tenant-filtered;
- denied role does not see create/edit/delete/export; direct endpoint returns 403;
- keyboard Tab/Shift+Tab/Enter/Space; visible focus; correct `aria-sort`; accessible icon/button names;
- AlertDialog opens, cancel returns focus, confirm sends exactly one request;
- dark mode and status meaning not color-only.

### QA-ISP gate

- Tenant A never sees/exports/mutates Tenant B data.
- RBAC UI visibility agrees with server response; hidden UI is not the only control.
- Index/export row identity/order and money/date formatting are consistent.
- No N+1 regression on relation-backed CSV mapper (query count or bounded evidence).
- Stock movement/receive integrity and transaction behavior remain intact.
- UI contract KEEP surfaces have no regression.

### Reviewer gate

- Review exact diff and tests; reject shared-file drive-by changes.
- Confirm every batch includes rollback and evidence.
- Confirm cleanup has fresh zero-consumer proof.
- Final approval remains with user/Baginda; reviewer gate is technical, not product approval.

## 9. Risks and rollback considerations

- Dirty shared worktree: highest conflict risk. Serialize all shared primitives, hooks, controllers, tests, and E2E files; use path-scoped diffs and do not reset unrelated changes.
- Tenant/RBAC leak: backend-first, deny tests before UI affordance.
- Query alias/deep-link regression: retain aliases until all consumers and browser tests migrate.
- Export format deception: never label plain text as real PDF/XLSX without byte/MIME proof.
- N+1 export: relation-backed cursor mappers require eager loading/query-count evidence.
- Shared UI blast radius: module pages first; shared DataTable/DataTableActions changes last.
- Rollback unit is the smallest module lane: controller/resource/request/page/test together for full-stack; page/test together for frontend-only. Never rollback shared unrelated WIP.

## 10. Completion criteria for the program

- All 12 KEEP surfaces remain behaviorally stable.
- Six FIX CONTRACT surfaces pass canonical query/tenant/RBAC/export tests; Stocks no longer blocks typecheck/build.
- Eight MIGRATE surfaces use canonical server-table wiring without losing columns, filters, permissions, or actions.
- Deferred/non-table surfaces remain untouched.
- All changed destructive flows use accessible confirmation and retain server authorization.
- Four missing export suites exist and pass; index/export parity is proven.
- Typecheck, lint, format check, build, focused PHPUnit, focused Playwright, QA-ISP, and reviewer gates pass on the implementation snapshot.
- Cleanup occurs only with recorded zero-consumer evidence.
