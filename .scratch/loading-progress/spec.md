# Loading & Progress Informatif — cards (ordered chain)

Status: done
Ledger: docs/plans/INBILS_OMP_BACKLOG.md (cards `t_b18f253d`, `t_b3c6843d`, `t_e161eb78`, `t_02ec133d`)

> **Approved by the owner 2026-10-10: the recommended option of every Owner decision below is the contract.** Where a decision lists no recommendation, the most conservative option applies and is recorded under "Approved decisions" when the lane closes. Original acceptance unrecovered.

## Owner decisions (approve in one word: "setuju rekomendasi" or pick per item)
**D1. What does 'seluruh halaman' cover?**
- (a) Every page that fetches data or submits a form: the gap inventory above (list/search/filter pages + upload progress) plus the already-covered ones verified (recommended). Tradeoff: widest but bounded, matches the title and the current code.
- (b) Only DataTable list pages (adds Inventory Requests only). Tradeoff: tiny change but leaves Notifications/Evaluations/Reports/Find/Trace silent.
- (c) (a) plus every redirect/navigation including Auth and Setup wizard step transitions. Tradeoff: higher test surface for little user value.

**D2. DataTable refetch behavior (sort/page/filter)?**
- (a) Keep current: replace rows with skeleton every time (recommended for minimal change; satisfies the 'don't redesign' rule). Tradeoff: layout flicker on each interaction.
- (b) First load = skeleton; refetch = keep stale rows dimmed with `aria-busy` + spinner overlay. Tradeoff: smoother, but needs DataTable internals and the Stock Card 'first-run loading' bug class must be tested.
- (c) Skeleton with row count = current `per_page` (bounded) and fixed min height. Tradeoff: stable layout, small code; pick together with (a).

**D3. Debounced search feedback?**
- (a) Show `processing`/busy from keystroke (set pending immediately, clear on finish) (recommended). Tradeoff: tiny hook change in both `useServerTable` and `useTableQuery`; must not break existing DataTable A–E tests.
- (b) Leave as is (feedback begins when the visit fires, ≥300 ms). Tradeoff: zero risk, 'dead' feel on slow typing.

**D4. Deferred props: apply anywhere now?**
- (a) No deferred props; only document the skeleton+empty-state convention (recommended). Tradeoff: zero backend risk; `AGENTS.md:505` stays dormant.
- (b) Defer heavy dashboard/report aggregates (Admin Dashboard, Reports Business/SLA/Asset) with `Inertia::defer` + `<Deferred>` skeletons. Tradeoff: real perf win but touches Reporting controllers/tests and needs a backend card.
- (c) Defer only Dashboard module cards. Tradeoff: smaller, but still a backend change and a new test.

**D5. Reduced motion & contrast policy?**
- (a) Add `motion-reduce:` variants to Skeleton/Spinner/Progress and validate bar color contrast for light/dark (recommended). Tradeoff: tiny CSS change, aligns with antislop-human.
- (b) Skip reduced-motion; only contrast. Tradeoff: faster, a11y gap remains.

**D6. Copy language for sr-only status text?**
- (a) Indonesian ('Memuat data…') to match most page copy (recommended). Tradeoff: some pages and `LoadingState` are English; align `LoadingState` default.
- (b) English. Tradeoff: matches shared primitive defaults today.
- (c) Per-page prop with a default. Tradeoff: flexible, risk of inconsistency.

**D7. Upload progress representation?**
- (a) Determinate percentage bar from Inertia `onProgress` using Radix Progress (recommended). Tradeoff: new `Progress` primitive and tests.
- (b) Indeterminate spinner only (status quo). Tradeoff: no work, not 'informatif' for large files.

**D8. Is a layout-level pending announcement in scope, or only the top bar?**
- (a) Add `aria-busy` + sr-only live status via a layout router-event listener (recommended). Tradeoff: touches `AdminLayout` (600 lines) — conflict risk with the Sidebar IA lane.
- (b) Top bar only; tune color/delay. Tradeoff: no layout edits, no AT announcement.

**D9. Approve replacement contract (one answer for the lane).** Approve drafted scope (a-recommended options above) / approve with edits / reject and search other sources. Without approval, all four cards remain `needs_recovery`.

UI `t_b18f253d` (legacy `t_9e1e9a30`, ui-engineer) → tester `t_b3c6843d` (legacy `t_fabe87ae`) → QA `t_e161eb78` (legacy `t_9c572a3c`, qa-isp) → reviewer `t_02ec133d` (legacy `t_5d1e08fb`). No backend card in this chain. Dependency chain confirmed in snapshot links (`docs/plans/INBILS_OMP_SOURCE.json` ~4748-4757) and Hermes export.

## Recovery result

**Original acceptance NOT found.**
- Exact-ID search (`t_9e1e9a30`, `t_fabe87ae`, `t_9c572a3c`, `t_5d1e08fb`, `t_b18f253d`, `t_b3c6843d`, `t_e161eb78`, `t_02ec133d`) across the entire repo returned hits only in `docs/plans/INBILS_OMP_BACKLOG.md` and `docs/plans/INBILS_OMP_SOURCE.json`. `docs/process/` and `docs/claude-batches/` do not exist; `docs/modules/`, `ROADMAP.md`, `TASKS.md` have no hit for these IDs. `ROADMAP.md`/`TASKS.md` mention loading only in unrelated Reporting closure text (`TASKS.md:64`: reviewer finding 'loading state run pertama tidak pernah tampil' on Stock Card).
- Ledger (each card): `scope/acceptance: belum pulih; snapshot body hanya recovery warning; evidence: tidak lengkap` and `next: Kontrak hilang; pulihkan dari sumber exact legacy ID, jangan coding` (`docs/plans/INBILS_OMP_BACKLOG.md` ~276-280, 778-785, 786-790, 914-918; line numbers drift as the ledger is edited). Lane is named 'Loading/progress (4)' in the post-Location recovery assessment (`INBILS_OMP_BACKLOG.md:94`) and in the 2026-10-07 reconciliation audit (`:255`).
- Hermes export (Hermes board export (read-only snapshot, removed after the audit)): all 4 cards `status: scheduled`, `completion_contract: local-only`, bodies are only 'Recovered from pre-reinstall authoritative Kanban snapshot dated 2026-09-12… canonical SQLite backup is missing'. Comments: UI card — 'SCHEDULED: Recovered scheduled state; timing metadata unavailable.'; tester/QA/reviewer — 'Freeze pemulihan Linux: dependency graph legacy hilang; tunggu rekonstruksi dependency dan preflight runtime terverifikasi.' (author `isp`). One synthetic run each (outcome `scheduled`); no worker runs, no results, no failures. Original statuses: UI `scheduled`, tester/QA/reviewer `todo`.
- Only recoverable semantic content = the titles: 'UI: tambahkan status loading dan progress yang informatif di seluruh halaman' (add informative loading and progress status across all pages), then tester / QA / reviewer of the same. Nothing says what 'informatif' or 'seluruh halaman' means in measurable terms → needs an owner-approved replacement contract.
- Related binding constraints already in repo: `AGENTS.md:505` ('When using deferred props, add an empty state with a pulsing or animated skeleton'); `docs/ui/tables.md:3` ('Support empty, loading, pagination…'); `docs/ui/design-system.md:3` (loading states as a priority); `docs/plans/2026-08-22-table-button-implementation-graph.md:283,339` (auth/profile 'loading prevents duplicate submit'; table 'loading skeleton and empty state'); `docs/plans/DATATABLE_CDE_REPLACEMENT_DRAFT.md:37` ('Pertahankan penanganan loading, empty, dan error yang ada; tidak membuat redesign atau library baru'). Precedent: Stock Card reviewer NEED FIX 'first-run loading state unreachable' was fixed under the Reporting P1 replacement contract (`TASKS.md:64`, ledger Reporting closure).

## Current implementation map

### Primitives (all in `resources/js`)
| Primitive | File | State |
|---|---|---|
| `Skeleton` | `Components/ui/Skeleton.tsx` | `animate-pulse rounded-md bg-muted`; no ARIA; exported by `ui/index.ts:53` |
| `Spinner` | `Components/ui/Spinner.tsx` | sm/md/lg SVG, `aria-hidden`; exported `ui/index.ts:88` |
| `Button loading` | `Components/ui/Button.tsx` | `loading` → `disabled`, `aria-busy`, leading `Spinner size=sm`, hides left/right icons. Used by ~all form submits (≈60 call sites across Auth, Profile, Admin Create/Edit, Show dialogs) |
| `LoadingState` (`full`/`section`/`rows`), `ResourceState`, `ErrorState`, `ForbiddenState`, `ResourceEmpty`, `PermissionGate` | `Components/composite/ResourceState.tsx` | `role="status" aria-label="Loading"` (English label; app copy is mixed ID/EN). **Not exported from `composite/index.ts`**; only consumer found: `Pages/Admin/Approvals/Index.tsx:5,449` (`<LoadingState variant="rows" rows={4}/>` for the mobile list) |
| `EmptyState` / `Empty*` | `Components/ui/EmptyState.tsx`, `ui/empty.tsx` | exist |
| Progress bar component | — | **absent**. `@radix-ui/react-progress` is in `package.json` dependencies but no `Components/ui/Progress` and no usage (grep of `resources/js` for `react-progress` = 0) |
| Toasts | `ui/Toast.tsx` (`ToastProvider`, `useToast`), `ui/sonner.tsx` | exist; `AdminLayout` calls `useToast()` |
| Showcase | `Pages/Admin/Components.tsx:409-417` | shows Spinner sizes + 3 Skeleton lines + EmptyState only; no LoadingState/ResourceState/progress demo |

### DataTable
- `Components/composite/DataTable.tsx` (re-exported by `ui/DataTable.tsx`): props `loading`/`isLoading` → `busy` (`:140`). When busy it renders **5 hard-coded skeleton rows** (`:258-272`) and **hides all data rows and the empty state** (`:273`, `:284`). No `aria-busy` on the table, no `role="status"`/sr-only text on the skeleton rows, skeleton count ignores `per_page`, and every sort/page/search swaps the whole body to skeletons (no stale-while-revalidate).
- Pages that already pass `loading` to DataTable (verified via grep): Approvals (`:439`), Billing Receivables (`:216`), Invoices (`:261`), CustomerContacts (`:207`), Customers (`:205`), Dispatch (both tables `:749`, `:845`), Documents (`:184`), Employees (`:221`), Inventory Categories (`:187`), Movements (`:207`), Products (`:153`), Stocks (`:262`), Units (`:122`), Requests **Returns tab only** (`:760`, `returnsLoading`), Locations (`:428`), MyWork (`:416`), NetworkAssets (`:228`), Organizations (`:300`), Permissions (`:71`), Reports AuditLog (`:999`), StockCard (`:110`), Roles (`:135`), SPK (`:210`), Service BandwidthProfiles/Packages/SLATiers/SpeedProfiles (`:158/:233/:157/:157`), Subscriptions (`:219`), Tickets (`:211`), Users (`:165`).
- DataTable consumers WITHOUT `loading` (gaps): `Inventory/Requests/Index.tsx` desktop table (`:994`) and mobile table (`:1010`) — `useServerTable` at `:113` does not destructure `processing` and the request list tabs have no busy feedback; `Users/Show.tsx` history tables (`:336/:346/:356`) are static props (no async → likely fine, [INFERENCE]).

### Server-table hooks
- `hooks/useServerTable.ts`: `processing` state; `visitNow` does `setProcessing(true)` + `router.get(..., {preserveState, preserveScroll, replace, only?, onFinish: setProcessing(false)})`. Search is debounced 300ms and `processing` only turns true when the visit fires, so the first 300ms of typing show no feedback. Consumers (25+ pages) include Approvals, Receivables, CustomerContacts, Customers, Dispatch, Documents, Employees, Categories, Products, Requests, Units, Locations, MyWork, Organizations, Permissions, AuditLog, StockCard, Roles, Service* (4), Subscriptions, Users.
- `hooks/useTableQuery.ts` (Pattern B twin; Tickets, SPK, and others): same `processing` semantics, same debounce, pushes to `window.location.pathname`.
- Two parallel hooks = two conventions; both expose `processing`, so a shared consumer contract is feasible without merging them.

### Inertia progress / navigation
- `resources/js/app.tsx`: `progress: { color: '#4B5563' }` only. `@inertiajs/core` 2.3.27 defaults for the app-level progress helper: `showSpinner=false`, `includeCSS=true` (`node_modules/@inertiajs/core/dist/index.js:4101-4103`); delay default not read [INFERENCE: 250ms, standard Inertia default]. The bar is the only global navigation feedback. No `router.on('start'|'finish')` listener, no layout-level pending indicator, no `aria-live`/`role=status` announcement of navigation. Radix `Progress` not wired.
- `Layouts/AdminLayout.tsx` (600 lines): sidebar + `usePage()`; no loading/busy state. `AuthenticatedLayout`, `GuestLayout`, `SetupLayout`, `AppLayout` likewise contain no loading code (grep for loading/progress/Skeleton/Spinner in `Layouts/` found nothing beyond `usePage`/`router`).
- `Pages/Dashboard.tsx` does `window.location.href='/admin/dashboard'` in an effect (renders a blank/redirect page). `Pages/Admin/Dashboard/Index.tsx` is fully server-rendered props (no deferral).

### Deferred / partial props
- Frontend: **zero** `<Deferred>`, `<WhenVisible>`, `usePoll`, `InfiniteScroll`, `usePrefetch` in `resources/js` (grep). Partial reloads via `only:` are used widely (list pages, Dispatch/Approvals `router.reload`, region/geocode lookups).
- Backend: only `Inertia::optional` (geocode results/index, `CustomerController.php:77,80,133,138`, `CustomerAddressController.php:49`); no `Inertia::defer`. So `AGENTS.md:505` is currently vacuous but would become binding the moment any card introduces a deferred prop (e.g. dashboard widgets, report aggregates).
- Local async flags with their own spinners: `CustomerAddressEditor` (`regionLoading`, `geocodeSearching`), `Customers/Create` (`geocodeSearchingIndex`), `CustomerAddresses/Index`, `Billing/Invoices/GenerateDialog` (`loading`, axios), `Inventory/Requests/Index` (`returnsLoading`, fetch) and `Requests/Show` (`returnLoading`, `actionLoading`, fetch), Approvals/Dispatch/MyWork dialogs (`isReloading`, `submitting`), SPK/Tickets Show (`uploading`), MapPicker (`searching`), delete dialogs (`deleteProcessing` + Spinner in `DataTableActions`, `CustomerAddressEditor`, `CustomerContactEditor`, `Locations/Index`).

### Pages with navigation/search/filter but NO loading feedback (beyond global bar)
Verified by grep (no `processing`/`loading`/Spinner in file):
- `Pages/Admin/Notifications/Index.tsx` — pagination `router.get` at `:140` and per-row click `router.get(url)` at `:100/:104`; no busy state.
- `Pages/Admin/Evaluations/Index.tsx` — search submit `router.get` `:54`, pagination `router.get` `:167`; no busy state (list is not DataTable).
- `Pages/Admin/NumberSequences/Index.tsx` — pagination `router.get` `:79`; no busy state.
- `Pages/Admin/Inventory/Find.tsx` (`:33`) and `Pages/Admin/NetworkAssets/Trace.tsx` (`:31`) — search submit `router.get`, no busy state.
- `Pages/Admin/Reports/Asset.tsx` (`:40`), `Business.tsx` (`:35`), `Sla.tsx` (`:29`) — filter submit `router.get` with no `processing`/button loading (Technician.tsx does use `useForm` `processing` → Run button `loading` at `:160`).
- `Pages/Admin/Reports/Index.tsx` — card click `router.get` (`:67`); `Components/composite/NotificationBell.tsx:16` — `router.get`; both rely on the global bar only.
- `Pages/Admin/Inventory/Requests/Index.tsx` — desktop + mobile DataTables (see above).
- `Pages/Admin/Vehicles/Index.tsx` — uses `useForm` with Button loading for the form; whether the list table has any loading is [INFERENCE: static props, no server-driven filters].
- Upload buttons (`SPK/Show`, `Tickets/Show`) show only a boolean spinner via Button; no percentage even though Inertia visits expose upload progress [INFERENCE from Inertia API; not verified in repo].

### Existing tests / prior art
- Playwright is the only browser test runner: `npm run test:e2e` → `scripts/e2e-canonical.mjs`; specs in `tests/e2e/` (`datatable-ab/c/d/e`, `dispatch-my-work`, `notification-center`, `reporting-stock-card`, `reports-audit-log`, `technician-performance`, `location-map-first`, `ui-regressions`, `harness`, `fixture-policy`, etc.).
- Deterministic slow-request idiom (held `page.route` + release promise): `tests/e2e/datatable-actions-delete.spec.ts:90-96` (hold DELETE until released), `tests/e2e/dispatch-my-work.spec.ts:131-142` (hold/fulfill GET reload), `tests/e2e/notification-center.spec.ts:242-249` (held read). `getByRole('status')` already asserted in `dispatch-my-work.spec.ts:463`. No existing E2E asserts a skeleton/`aria-busy`/progress-bar state (grep for `aria-busy`/`animate-pulse`/`Loading` in tests/e2e matched only unrelated hits).
- Non-browser self-checks: `*.selfcheck.mjs` beside components (e.g. `Approvals/components/ReviewSheet.selfcheck.mjs`, `Dispatch/components/AssignDialog.selfcheck.mjs`, `Components/ui/Select.selfcheck.mjs`). No vitest/jest in `package.json`.
- Gates available: `npm run typecheck`, `npm run lint` (ESLint 10), `npm run build` (`tsc && vite build`), `format:check`.

## Problem Statement
Users of the admin/auth UI get inconsistent feedback while data loads or mutations run. The shared pieces (Skeleton, Spinner, Button loading, LoadingState, DataTable skeleton rows, `processing` from table hooks) exist but are applied unevenly: a few list/search/pagination flows (Inventory Requests index, Notifications, Evaluations, Number Sequences, Inventory Find, Network Asset Trace, Reports Asset/Business/SLA) show nothing besides a thin gray top bar; the DataTable skeleton replaces all rows on every sort/page change with a fixed 5 rows and is invisible to assistive tech; there is no layout-level indication of navigation; the first 300 ms of debounced search shows no activity; long uploads show only a spinner; and no deferred-prop pattern or empty/skeleton convention (`AGENTS.md:505`) exists for future deferred data. The original card acceptance ('status loading dan progress yang informatif di seluruh halaman') is lost, so 'informative' and 'all pages' are undefined and cannot be tested.

## Solution
Define loading/progress feedback as a small, reusable UI contract and apply it to every page whose data or mutation is asynchronous — reusing existing components, no redesign, no new library:
1. **Global navigation feedback**: keep the Inertia progress bar (tuned for visibility/contrast in light & dark) and add a lightweight, accessible pending announcement (visually-hidden `role=status`/`aria-live=polite` text and `aria-busy` on the main content region) driven by Inertia router events from the layout.
2. **List/table feedback**: every page that renders the canonical DataTable and has server-driven search/sort/pagination passes `loading` from its table hook; DataTable gets `aria-busy`, an sr-only status label, and skeleton row count tied to the current page size (bounded), with behavior for first load vs. refetch decided by the owner (see decisions).
3. **Non-DataTable lists and search/filter forms** (Notifications, Evaluations, Number Sequences, Inventory Find, Network Asset Trace, Reports Asset/Business/SLA/Index, Requests index, bell navigation): adopt the same `processing` pattern (route `router.get` through `useServerTable`/`useTableQuery` or local `onStart/onFinish`) and show `LoadingState`/Button `loading` + disabled controls.
4. **Mutations/uploads**: keep Button `loading`; add percentage progress for file uploads where Inertia reports it, using a small `Progress` primitive built on the already-declared `@radix-ui/react-progress`.
5. **Deferred/optional data**: document a single convention (skeleton + empty state, `AGENTS.md:505`) via a shared `DeferredSection`-style wrapper over `LoadingState`; apply only where the owner picks a page to defer (default none).
6. Export `LoadingState`/`ResourceState` from the composite barrel and update the Components showcase so the contract is discoverable.
Tester/QA/reviewer cards then verify the contract with deterministic slow-request Playwright checks, keyboard/screen-reader/320px/light-dark checks, and a page-by-page matrix.

## User Stories
1. As an admin user, when I click a sidebar link or a card (e.g. Reports index, notification bell), I see an unambiguous navigation-in-progress indicator (top bar plus an announced busy state) until the next page renders.
2. As a user filtering a list (Customers, Tickets, SPK, Invoices, Users, Roles, Inventory Requests, etc.), I see the table body show skeleton rows while the new result loads and never a stale empty state.
3. As a user paging through Notifications, Evaluations or Number Sequences, I see a loading indication on the list and the pagination controls are disabled or marked busy until the page arrives.
4. As a user running Inventory Find, Network Asset Trace or a report filter (Asset, Business, SLA, Technician), the submit button shows a spinner and is disabled while the request runs, and the results area shows a loading placeholder.
5. As a user on Inventory Requests, switching tabs or changing filters shows loading feedback on both desktop and mobile layouts, consistent with the Returns tab.
6. As a user typing in a list search box, I get a visible 'searching' indication starting when I type (not only after the 300 ms debounce) [owner decision D3].
7. As a user uploading SPK evidence or a ticket attachment, I see a progress percentage (or at least an indeterminate bar) and the form is protected against double submit.
8. As a user submitting any form (auth, profile, create/edit, dialogs), the submit button is disabled and shows a spinner, and navigation/cancel is not corrupted while it runs (existing behavior preserved).
9. As a user of assistive tech, I hear a polite 'Loading…' / 'Memuat…' status when a table or section is loading, and I hear when it finishes; skeletons are not announced as noise.
10. As a user on a 320 px viewport or in dark mode, loading states are readable, do not cause horizontal overflow or layout shift beyond the existing table scroll container, and meet contrast (progress bar color `#4B5563` against both backgrounds needs a contrast check).
11. As a user with `prefers-reduced-motion`, skeleton pulses and spinners degrade to a static or reduced animation [owner decision D5].
12. As a user on a slow connection, I do not see the whole table collapse and re-expand on every sort/page; the previous rows or a stable-height skeleton keeps the layout steady [owner decision D2].
13. As a developer adding a new page, I find one documented loading convention (hook `processing` → DataTable `loading`; `LoadingState` for sections; Button `loading` for mutations; deferred → skeleton + empty state) with a working example in the Components showcase.
14. As a tester/QA/reviewer, I can run a page-by-page matrix that lists each page, its async actions, the expected loading element, and its status, with deterministic held-request proof.

## Implementation Decisions
- **Scope = frontend only**; no new routes, migrations, permissions, or backend behavior. No raw SQL. Tenant/RBAC unchanged. If the owner picks any deferred-prop page (D4), a controller change to `Inertia::defer` is the only backend touch and must keep Query/Resource/Policy layering and company scoping; otherwise zero PHP.
- **Reuse first**: Skeleton, Spinner, Button `loading`, `LoadingState`/`ResourceState` (composite), DataTable `loading`, `useServerTable`/`useTableQuery` `processing`. No new third-party library; `@radix-ui/react-progress` is already a dependency and may back a `Progress` primitive in `Components/ui` (shadcn-style). Do not run the `empty`/`spinner` shadcn adds (`INBILS_FULL_SHADCN_*` docs say not to force-add).
- **DataTable contract**: `loading` stays the single prop; add `aria-busy` on the table region, an sr-only live status ('Memuat data…'), skeleton row count derived from `per_page`/current row count clamped to a sane min/max (e.g. 3–10, [INFERENCE] values), and keep toolbar/pagination stable. Do not change column, sort, export, or selection semantics.
- **Global navigation**: app-level listener on Inertia router `start`/`finish` (set in the layout, not each page) toggling a visually-hidden status and `aria-busy` on `<main>`; keep `progress` config in `app.tsx`, tune color/delay only. No duplicate spinners on top of the bar.
- **Page rollout**: wire `processing` into the pages listed in the gap inventory. Pages already using `useServerTable`/`useTableQuery` only need the prop; Notifications, Evaluations, NumberSequences, Find, Trace, Reports Asset/Business/Sla/Index, NotificationBell need `router.get` options `onStart/onFinish` (or `useForm` `get`) with local pending state.
- **Uploads**: use Inertia visit `onProgress` (percentage) for `SPK/Show` and `Tickets/Show` uploads; show `Progress` primitive; keep `uploading` guard against double submit.
- **Copy/i18n**: the UI mixes Indonesian and English; LoadingState's label is English 'Loading'. Pick one label convention for sr-only text (D6) and apply it consistently; do not create a translation framework.
- **Barrel/exports**: export `LoadingState`/`ResourceState`/`ErrorState`/`ForbiddenState` from `Components/composite/index.ts` (currently not exported) and add `Progress` to `Components/ui/index.ts`; update `Pages/Admin/Components.tsx` showcase (currently only Spinner/Skeleton/EmptyState).
- **Permission naming/props**: no new permission or Inertia prop. Existing `can` props untouched.
- **Do not touch**: Technician Performance (complete), existing Reporting P1 behavior, DataTable A–E sort/pagination/export contracts, Location map code (blocked on its own lane gate).

## Testing Decisions
- **Primary seam**: Playwright specs in `tests/e2e/` using the held-request idiom (`page.route` + release promise: `datatable-actions-delete.spec.ts:90-96`, `dispatch-my-work.spec.ts:131-142`, `notification-center.spec.ts:242-249`). New spec (e.g. `loading-progress.spec.ts`, tagged canonical, owned fixtures per `tests/e2e/support/fixtures.ts`) holding a list GET and asserting: skeleton rows visible, `aria-busy=true`/status text present, no empty-state flash, then released → rows replace skeleton, `aria-busy` cleared. Repeat for one `useServerTable` page, one `useTableQuery` page, Inventory Requests (desktop and 320 px mobile), Notifications pagination, Evaluations search, a report filter page, and an upload with held POST.
- **Matrix**: tester card produces a page × action table (all pages in the gap inventory plus all current DataTable pages) with pass/fail; QA re-runs independently with light/dark, 320 px/desktop, keyboard focus (focus not lost on busy), `prefers-reduced-motion`, and an axe-style/a11y-tree check of status roles.
- **Static gates**: `npm run typecheck`, `npm run lint` (scoped to touched files), `npm run build`, `npm run format:check`; main agent runs once after landing (agents do not verify).
- **Contrast**: use the antislop contrast tool on the progress/skeleton colors in light and dark [INFERENCE: `#4B5563` may be low contrast on dark `bg-background`].
- **Backend**: no PHPUnit required unless D4 introduces `Inertia::defer`; then add a feature test asserting the deferred prop is absent from the initial response and present on the partial reload, company-scoped, on MySQL `inbils_test`.
- **Prior art to extend**: `ui-regressions.spec.ts` (shared UI), `datatable-ab/c/d/e.spec.ts` (table behaviors), `harness.spec.ts`. Self-check `.mjs` style is available for pure component assertions but there is no unit-test runner; do not add one without owner approval.
- Presence of components or earlier closures is not proof of acceptance (same rule as `DATATABLE_CDE_REPLACEMENT_DRAFT.md:38`).

## Out of Scope
- Any backend/domain change, new endpoints, DB/schema work, or queue/progress-of-job UX (no websockets/polling progress for server jobs).
- Redesign of tables, layouts, or visual identity; new UI library; replacing Inertia progress with a custom bar library.
- Introducing deferred props on pages unless the owner explicitly selects them (D4).
- Error-state redesign (ErrorState/ForbiddenState exist; not part of this contract except where a loading state can fail to resolve).
- Merging `useServerTable` and `useTableQuery` (convention cleanup is a separate lane).
- Location map tile/marker loading (belongs to Location Map lane), Technician Performance, Reporting V2, Sidebar IA, Notification Center, Dispatch/My Work behavior.
- Full i18n framework; E2E for non-targeted pages; a new unit-test runner.

## Dependencies / ordering vs other lanes
- Internal chain: UI `t_b18f253d` → tester `t_b3c6843d` → QA `t_e161eb78` → reviewer `t_02ec133d`. Source-snapshot dependencies are authoritative (`INBILS_OMP_BACKLOG.md:94`). No upstream backend card; no predecessor outside the lane (UI card predecessor: 'tidak ada').
- Execution order in the ledger: Technician Performance → Phase Gate → Location Map, then recovery lanes (`INBILS_OMP_BACKLOG.md:94`). This lane may start only after owner approval and after the Location lane gate; it must not run in parallel with Location (shared `Locations/Index.tsx` uses `loading` and `Spinner`).
- Shared-file conflict risk: `Components/composite/DataTable.tsx` (all DataTable lanes A–E, Reporting), `Layouts/AdminLayout.tsx` (Sidebar IA lane), `hooks/useServerTable.ts`/`useTableQuery.ts` (every list page), `Pages/Admin/Inventory/Requests/Index.tsx` (DataTable C), `Components/ui/index.ts` / `composite/index.ts` barrels. Per `docs/plans/2026-08-22-table-button-implementation-graph.md:369` ('shared UI blast radius: module pages first; shared DataTable changes last') land page-level wiring first and DataTable/hook/layout changes last, after DataTable C/D/E and Sidebar IA decisions are settled.
- Do not overlap with: DataTable C/D/E (they already require 'preserve existing loading handling', `DATATABLE_CDE_REPLACEMENT_DRAFT.md:37`), Notification Center (Notifications index pagination wiring), Reporting V2 (Reports pages), Dispatch/My Work (loading props already present), Location Map (Locations page).

## Approved decisions

Owner approved the recommended option of every decision on 2026-10-10. Applied by UI `t_b18f253d` (2026-10-10):

1. **D1 scope: a.** The gap-inventory pages, the already-wired DataTable pages (verified) and the four upload forms. Reports hub cards, the notification bell and every link that replaces the page rely on the layout navigation state (D8): a local flag would unmount with the page.
2. **D2 refetch: a + c.** `DataTable` still swaps rows for skeleton rows on every sort, page and filter change. The skeleton has as many rows as the current `per_page`, clamped to 3-10, each `h-12` (a row with an action button) and `aria-hidden`. The table gets `aria-busy`; a polite status says 'Memuat data…' while loading and 'Data dimuat' when it ends (nothing on first render).
3. **D3 keystroke: a.** `useListVisitState` (shared by `useServerTable` and `useTableQuery`) turns `processing` on at the first change, debounce window included, and off when the last visit finished with no newer change waiting. `visiting` is true only while a visit is in flight. `processing` feeds DataTable `loading`; every `disabled` uses `visiting` (Approvals, Dispatch, My Work, Audit Log, Sales KPI, Stock Card), so typing never disables the focused input. `useTableQuery` now returns both flags.
4. **D4 deferred props: a.** None. See the convention below.
5. **D5 motion and contrast: a.** `Skeleton` stops pulsing, `Spinner` slows to 2s per turn and the `Progress` fill drops its transition under `prefers-reduced-motion`. The Inertia bar color moved from the fixed `#4B5563` (7.56:1 on light, 2.65:1 on dark, below the 3:1 non-text minimum) to the theme token `hsl(var(--primary))`: `#2563EB` 5.17:1 on `#FFFFFF`, `#3B82F6` 5.44:1 on `#020817`. `Progress` fill on its track: 4.72:1 light, 3.98:1 dark. The `Skeleton` token `bg-muted` is 1.10:1 light and 1.37:1 dark: decorative placeholder, state is carried by `aria-busy` and the status text, unchanged.
6. **D6 copy: a.** Indonesian: 'Memuat halaman…' (layout), 'Memuat data…' and 'Data dimuat' (DataTable), `LoadingState` default label 'Memuat data…' (now real text inside its `role="status"`, not an `aria-label`). `Progress` labels are Indonesian too. Existing English page copy is untouched.
7. **D7 upload: a.** New `Components/ui/Progress` (Radix, label above the bar, `aria-labelledby`, 0-100). SPK evidence and ticket attachment pass Inertia `onProgress`; Company Profile and Setup Wizard read `useForm().progress`. The Upload buttons keep their spinner and double-submit guard.
8. **D8 layout: a.** `AdminLayout` calls `useNavigationBusy()`: after 250ms (the Inertia bar delay) `<main>` gets `aria-busy` and a polite status 'Memuat halaman…' that sits outside `<main>`, so assistive technology still announces it. Only page navigations count (sync GET, not prefetch, not state-preserving); list refetches, partial reloads and form posts keep their own feedback. A fast navigation never flips it.
9. **D9: approve drafted scope.**

### Loading convention (one rule per case)

| Case | Use |
|---|---|
| Server table (`useServerTable` or `useTableQuery`) | `processing` -> DataTable `loading`. Filters and buttons use `visiting` for `disabled`, never `processing` |
| List, search or filter page with an ad-hoc `router.get` | `const { pending, callbacks } = useVisitPending()`, spread `callbacks` into the visit options; `pending` -> Button `loading`, `Pagination disabled`, `LoadingState` in place of the results |
| Mutation | Button `loading` |
| File upload | `Progress` fed by Inertia `onProgress` (`router.post`) or `useForm().progress` |
| Link, card or button that replaces the page | Nothing local: `AdminLayout` shows the bar, `aria-busy` and the status |
| Deferred prop | None exist (D4 a). The first one renders `LoadingState` while the prop is missing and an empty state when it resolves empty, the rule `AGENTS.md` sets in its Inertia section, and ships a feature test: absent from the first response, present on the partial reload, company scoped |

Showcase: `Pages/Admin/Components.tsx`, Feedback section (`Progress`, `LoadingState`, `Spinner`, `Skeleton`).

### Delivered

- New: `Components/ui/Progress.tsx`, `hooks/useListVisitState.ts`, `hooks/useNavigationBusy.ts`, `hooks/useVisitPending.ts`, `tests/e2e/loading-progress.spec.ts`.
- Changed shared: `Skeleton`, `Spinner`, `Pagination` (`disabled`), `ui/index.ts` (+`Progress`), `composite/DataTable.tsx`, `composite/ResourceState.tsx`, `composite/index.ts` (+`LoadingState`, `ResourceState`, `ErrorState`, `ForbiddenState`), `Layouts/AdminLayout.tsx`, `app.tsx`, `useServerTable`, `useTableQuery`.
- Pages: Approvals, Dispatch, My Work, Audit Log, Sales KPI and Stock Card (`disabled` -> `visiting`); Inventory Requests index (`loading` on desktop and mobile tables); Notifications, Evaluations, Number Sequences, Item Finder, Asset Trace, Reports Asset, Business and SLA (`useVisitPending`); SPK Show, Ticket Show, Company Profile, Setup Wizard (`Progress`); Components showcase. Customer Contacts, Documents and Subscriptions indexes: Prettier only.

### Accepted gaps

- E2E has no case for Notifications or Number Sequences pagination: both need more than one page of seed data. The two pages use the same `useVisitPending` + `Pagination disabled` + `LoadingState` wiring as Evaluations, which has a case through its filter form.
- Sales KPI keeps its own `role="status"` 'Loading Sales KPI' next to the DataTable status, and Stock Card keeps its first-run status; both predate this lane and sit in closed Reporting work.
- `Components/composite/spk/WorkOrderShowSections.tsx` and `tickets/TicketShowSections.tsx` export upload cards that no page imports; the pages render their own forms. Left alone.
- `Components/composite/locations/LocationsMapView.tsx` fails `format:check`; it belongs to the Location Map lane.

### Verification chain

`node --test resources/js/lib/navigation.selfcheck.mjs` 19/19, `npm run typecheck`, ESLint on every touched file, Prettier, `npm run build`. A throwaway Vite render of `DataTable`, `Progress`, `LoadingState`, `Spinner`, `useNavigationBusy` and `useListVisitState` in Chromium confirmed: 10 `aria-hidden` skeleton rows of 48px with `aria-busy`, status 'Memuat data…' then 'Data dimuat', no horizontal overflow at 320px in dark mode, computed animation under `prefers-reduced-motion` (skeleton `none`, spinner 2s, fill transition `none`), `useNavigationBusy` fed with simulated Inertia events (a fast navigation never busy, a slow one busy after 250ms, an interrupted visit stays busy, state-preserving, async, prefetch, no-progress and POST visits never count) and `useListVisitState` (processing at the first change, `visiting` only in flight, a stale finish ignored, processing kept while a newer change waits). `tests/e2e/loading-progress.spec.ts` is written, not run; the tester card owns the page-by-page matrix.

## Closure

Done 2026-10-10: QA PASS, reviewer APPROVED. E2E 16/16 passed.
