# Sidebar IA — cards (chain: UI → tester → QA → reviewer)

Status: done
Ledger: docs/plans/INBILS_OMP_BACKLOG.md (cards `t_81b959f1`, `t_f106f2e8`, `t_c969a55f`, `t_77adedd5`)

> **Approved by the owner 2026-10-10: the recommended option of every Owner decision below is the contract.** Where a decision lists no recommendation, the most conservative option applies and is recorded under "Approved decisions" when the lane closes. Original acceptance unrecovered.

## Owner decisions (approve in one word: "setuju rekomendasi" or pick per item)
1. **What counts as the "7 groups" and what is the shape?** (a) Keep six titled groups + standalone Dashboard (7 total, System/Developer folded into Company/Admin) — **recommended**, satisfies both strict and loose readings; (b) Keep the current seven titled groups + Dashboard as an untitled top item (7 titled / 8 entries) — least churn, fails strict count; (c) Re-cut into new domain groups (e.g., Customers, Operations, Inventory, Billing, Reports, Administration, Settings) — cleaner IA but relabels groups/E2E. Tradeoff: (a) minimal churn and meets any reading; (c) better long-term IA but breaks `Finance/Reports` selector and needs owner-supplied taxonomy.
2. **How should the Komponen (developer gallery) link behave?** (a) Show only when `developer.components.view` AND a server-computed env flag is true — **recommended**; (b) show only on `developer.components.view` (admin sees 404 link in production); (c) remove from sidebar and keep route only. Tradeoff: (a) needs one tiny shared prop in `HandleInertiaRequests` (the only backend touch); (b) pure frontend but still dead link in prod; (c) simplest, removes a dev convenience.
3. **Should Dashboard be gated client-side by `dashboard.view`?** (a) Yes, hide if missing — **recommended**, but post-login redirect to /admin/dashboard still 403s for such users (existing behavior); (b) always show (current). Tradeoff: (a) consistent with capability-safe; (b) avoids an empty sidebar for a user with no permissions.
4. **Should the lane add missing destinations (Notifications, Company Settings, Audit Log) to the sidebar?** (a) No — navigation reorganisation only — **recommended**; (b) add Company Settings (`company.manage`) and Audit Log (`report.audit.view`) under Company/Admin and Finance/Reports; (c) also add Notifications. Tradeoff: (a) matches "IA + capability-safe" with no new surface; (b)/(c) improve discoverability but expand scope and E2E.
5. **Should Permintaan Barang remain visible for `inventory.view`-only users?** (a) Mirror server: hide unless `request.view.own|all` — **recommended** (capability-safe); (b) widen the server policy to also allow `inventory.view` (behavior change, out of this lane). Tradeoff: (a) no backend change; (b) changes authorization.
6. **Service items: mirror `service.view || service.manage`?** (a) Yes — **recommended**; (b) leave `service.view` only. Tradeoff: (a) exact server parity; (b) leaves manage-only roles without the link.
7. **Where do the `system.setting` items (Documents, Number Sequences) live?** (a) Move both to Company/Admin — **recommended** (settings are administrative, makes CRM/Service groups domain-pure); (b) keep current placement. Tradeoff: (a) changes where a user finds them (E2E uses exact link names, group-agnostic); (b) no move but incoherent IA.
8. **Group open/closed persistence.** (a) Keep current `details` + default-open-if-active — **recommended**; (b) all groups open by default; (c) persist per-user in localStorage. Tradeoff: (a) no change, E2E already expands; (c) extra state and E2E interplay.

`t_81b959f1` [legacy t_17868fdc] P1 UI IA: target sidebar maksimum 7 grup dan navigation capability-safe (ui-engineer) → `t_f106f2e8` [t_7a8653a0] TESTER (tester) → `t_c969a55f` [t_934341cd] QA-ISP (qa-isp) → `t_77adedd5` [t_27cd7b68] REVIEWER final (reviewer). There is no backend card in this lane; the chain is UI → tester → QA → reviewer. Links verified in Hermes board export (read-only snapshot, removed after the audit) (links arrays) and the snapshot `docs/plans/INBILS_OMP_SOURCE.json:4655-4665`. Hermes status of all four: `scheduled`.

## Recovery result

**Original acceptance: NOT FOUND.**
- Searched exact legacy IDs `t_17868fdc|t_7a8653a0|t_934341cd|t_27cd7b68` and new IDs across `docs/` (no `docs/process`, `docs/claude-batches`, or `docs/modules` exist; docs tree = architecture, business, operations, playbooks, plans, standards, ui), `ROADMAP.md`, `TASKS.md`, `AGENTS.md`, `.opencode`, `tests/`. Matches only in `docs/plans/INBILS_OMP_BACKLOG.md` (603-617, 843-853, 946-953: each says `scope/acceptance: belum pulih; snapshot body hanya recovery warning`) and `docs/plans/INBILS_OMP_SOURCE.json` (2146-2148, 2186-2188, 3546-3548, 4186-4188, link rows 4655-4665, comments 5002, 5030). No other text.
- The only title-level facts: title of t_81b959f1 = "target sidebar maksimum 7 grup dan navigation capability-safe"; tester = "target sidebar dan navigation capability-safe"; QA = "target sidebar capability-safe"; reviewer = "final target sidebar capability-safe". All four bodies = the generic restore warning (snapshot 2026-09-12; original status todo; "canonical SQLite backup is missing").
- Hermes facts (export): t_81b959f1 had one run `reclaimed` 1789312776→1789312917 with error `manual_reclaim: Restore safety freeze: gateway dispatcher stopped…` (started once, no summary, no result), then a `scheduled` run 1789395653. The other three have only the `scheduled` freeze run. Every card has one comment from `isp`: "SCHEDULED: Freeze pemulihan Linux: dependency graph legacy hilang…". `completion_contract: local-only`. No result/summary/evidence on any card.
- Ledger line 94 lists "Sidebar IA" in the post-Location recovery assessment queue with the note that recovery assessment "bukan izin coding" and "Dependency source selalu menang".
- Closest related *approved* prior work (not this card's acceptance): `t_c2426c71` TP-UI2 (ledger 819ff / backlog line 33) added the `report.technician` direct sidebar link under Finance/Reports; E2E `tests/e2e/technician-performance.spec.ts:159-170,237-238` depends on it. Original generic acceptance for sidebar dynamism lives in `TASKS.md:558-615` (P1-06: admin sees all permitted modules, staff only modules with `*.view`, technician SPK+Ticketing+Inventory view; "Browser test (manual)") — pre-dates this card; cite only as context.
- Unrelated plan overlap: `docs/plans/INBILS_FULL_SHADCN_MIGRATION_PLAN.md:105-107` Batch 7 ("Shell") would migrate `ui/Sidebar.tsx`/AdminLayout to shadcn `sidebar` with acceptance "collapse/expand, active route, mobile drawer, permission-hidden items unchanged"; `INBILS_FULL_SHADCN_AUDIT.md:101` treats Sidebar/Topbar as a "late batch". Do not couple this lane to it.

## Current implementation map

Single source of nav: `resources/js/Layouts/AdminLayout.tsx` (600 lines). Primitives: `resources/js/Components/ui/Sidebar.tsx` (`Sidebar`, `SidebarSection` = `<details open={expanded}>` + `<summary>` title; untitled variant is a plain `<nav>`; `SidebarItem` = Inertia `Link`, `aria-current=page`, optional `badge`). Gating: `usePermission()` (`resources/js/hooks/usePermission.ts`) = `can`/`canAny` over `props.auth.user.permissions` (shared by `HandleInertiaRequests.php:47`). Extra shared props: `canMyWork` (`:75`) and `workQueues.{myWorkEligible,approvalPending,dispatchUnassigned}` (`:66-74`), typed `resources/js/types/index.ts:82-88`. Active state: `isActive(href)` = exact or prefix `href/` or `href?`. Each section's `defaultOpen` = isActive of any child route (so non-active sections render collapsed).

### Current group count: **8 titled SidebarSections** (all use `<details>`; Dashboard is a collapsible group containing one item). Total items: **35** (Dashboard 1, Company/Admin 5, CRM/Operations 5, Service 5, Inventory 7, Network/Work Orders 5, Finance/Reports 6, System/Developer 1).

### Every nav item, client gate and server gate

| # | Group | Label (E2E-visible text) | href | Sidebar gate (client) | Server gate (route/policy) | Match? |
|---|---|---|---|---|---|---|
| 1 | Dashboard | Dashboard | /admin/dashboard | **none** | `DashboardController::index` → `Gate::authorize('dashboard.view')` | **NO** (seeded roles all have it; custom roles w/o it get 403) |
| 2 | Company/Admin | Organization | /admin/organizations | `organization.view` | `OrganizationPolicy::viewAny` = `organization.view` | yes |
| 3 | Company/Admin | Company | /admin/company/profile | `company.manage` | `CompanyPolicy::viewProfile` = `company.manage` | yes |
| 4 | Company/Admin | Users | /admin/users | `canAny(['users.manage'])` | `UserPolicy::viewAny` = `users.manage` | yes (redundant canAny) |
| 5 | Company/Admin | Roles | /admin/roles | `roles.manage` | `RolePolicy::viewAny` = `roles.manage` | yes |
| 6 | Company/Admin | Permissions | /admin/permissions | `users.manage` | `PermissionController::index` → `Gate::authorize('users.manage')` | yes |
| 7 | CRM/Operations | Customers | /admin/customers | `customer.view` | `CustomerPolicy::viewAny` = `customer.view` | yes |
| 8 | CRM/Operations | Locations | /admin/locations | `location.view` | `LocationPolicy::viewAny` = `location.view` | yes |
| 9 | CRM/Operations | Employees | /admin/employees | `employee.view` | `EmployeePolicy::viewAny` = `employee.view` | yes |
| 10 | CRM/Operations | Vehicles | /admin/vehicles | `vehicle.view` | `VehiclePolicy::viewAny` = `vehicle.view` | yes |
| 11 | CRM/Operations | Documents | /admin/documents | `system.setting` | `DocumentTypePolicy::viewAny` = `system.setting` | yes (but domain-odd: a settings item under CRM) |
| 12 | Service | Number Sequences | /admin/number-sequences | `system.setting` | `NumberSequencePolicy::viewAny` = `system.setting` | yes (settings item under Service) |
| 13 | Service | Service | /admin/service-packages | `service.view` | `ServicePackagePolicy::viewAny` = `service.view \|\| service.manage` | **partial** (client omits `service.manage`) |
| 14 | Service | Bandwidth Profiles | /admin/bandwidth-profiles | `service.view` | `BandwidthProfilePolicy` = `service.view \|\| service.manage` | partial |
| 15 | Service | Speed Profiles | /admin/speed-profiles | `service.view` | `SpeedProfilePolicy` same | partial |
| 16 | Service | SLA Tiers | /admin/sla-tiers | `service.view` | `SLATierPolicy` same | partial |
| 17 | Inventory | Permintaan Barang | /admin/inventory/requests | `inventory.request.view.own \|\| inventory.request.view.all \|\| inventory.view` | `InventoryRequestPolicy::viewAny` = `request.view.own \|\| request.view.all` only | **NO** (`inventory.view` alone shows a link that 403s) |
| 18 | Inventory | Inventory (products) | /admin/products | `inventory.view` | `ProductPolicy::viewAny` = `inventory.view` | yes |
| 19 | Inventory | Categories | /admin/categories | `inventory.view` | `CategoryPolicy` = `inventory.view` | yes |
| 20 | Inventory | Units | /admin/units | `inventory.view` | `UnitPolicy` = `inventory.view` | yes |
| 21 | Inventory | Stocks | /admin/stocks | `inventory.view` | `StockController::index` `Gate::authorize('inventory.view')` | yes |
| 22 | Inventory | Item Finder | /admin/inventory/find | `inventory.view` | `StockController::find` `inventory.view` | yes |
| 23 | Inventory | Stock Movements | /admin/stock-movements | `inventory.view` | `StockController::movements` `inventory.view` | yes |
| 24 | Network/Work Orders | Network Assets | /admin/network-assets | `network_asset.view` | `NetworkAssetPolicy::viewAny` = `network_asset.view` | yes |
| 25 | Network/Work Orders | SPK | /admin/spk | `spk.view` | `WorkOrderPolicy::viewAny` = `spk.view` | yes |
| 26 | Network/Work Orders | Approval (+badge approvalPending) | /admin/approvals | `canAny(spk.approve, spk.reject, inventory.request.approve, inventory.request.reject)` | `ApprovalInboxQuery::isEligible` = same four | yes |
| 27 | Network/Work Orders | Dispatch (+badge dispatchUnassigned) | /admin/dispatch | `canAny(ticket.assign, spk.assign)` | `DispatchQueueQuery::isEligible` = `ticket.assign\|reassign` or `spk.assign\|reassign` AND `is_active`; ineligible-but-MyWork-eligible users are redirected to /admin/my-work | **partial** (client omits `*.reassign`; server also requires is_active) |
| 28 | Network/Work Orders | Pekerjaan Saya (+badge myWorkPending) | /admin/my-work | `props.canMyWork === true` (server-computed `MyWorkQuery::isEligible`: ticket.start/resolve/close or spk.start/submit + active company EmployeeProfile) | `MyWorkController` `abort_unless(MyWorkQuery::isEligible)` | yes (single source of truth) |
| 29 | Finance/Reports | Billing | /admin/invoices | `billing.view` | `InvoicePolicy::viewAny` = `billing.view` | yes |
| 30 | Finance/Reports | Tunggakan | /admin/billing/receivables | `billing.view` | [INFERENCE] `InvoiceController::receivables` — gate not read; likely `billing.view` | unverified |
| 31 | Finance/Reports | Ticketing | /admin/tickets | `ticket.view` | `TicketPolicy::viewAny` = `ticket.view` | yes |
| 32 | Finance/Reports | Evaluations | /admin/evaluations | `canAny(evaluation.view, evaluation.view.own)` | `EmployeeEvaluationPolicy::viewAny` same | yes |
| 33 | Finance/Reports | Reports | /admin/reports | `report.view` | route `can:report.view` (Reporting/routes/web.php:7) | yes |
| 34 | Finance/Reports | Technician Performance | /admin/reports/technician | `report.technician` | route `can:report.technician` + controller actor logic (`report.manage`, manager role+org, technician role) | **partial** (a user with only `report.technician` but no technician/manager role/org passes the link, 403s on page; seeded roles are fine) |
| 35 | System/Developer | Komponen | /admin/components | **none** (unconditional) | route `can:developer.components.view` AND `app()->environment(['local','testing'])` else 404 (`routes/admin.php:56-65`); permission seeded only to admin | **NO** (every user sees it; non-admin → 403; admin in production → 404) |

Not in sidebar although routed with a UI page: Notifications (`/admin/notifications`, reachable via `NotificationBell`), Company Settings (`/admin/company/settings`, page `Admin/Company/Settings.tsx` exists, `CompanyPolicy::viewSettings`), Reports children Business/Asset/SLA/Stock Card (via Reports hub, `report.view`), Audit Log (`/admin/reports/audit-log`, `report.audit.view`; [INFERENCE] reached from the Reports hub — `Reports/Index.tsx` only shows a `report.technician` filter at line 43, audit gate there not confirmed), Subscriptions (only show/update routes), Inventory Returns (`/admin/inventory/returns*` are JSON-only controllers, no page).

### Defects found (capability-safety gaps the UI card must close)
1. **Empty group headers.** `SidebarSection` always renders `<summary>` even when every child is gated off. The six `hasCompanyAdminNavigation … hasFinanceReportsNavigation` consts (`AdminLayout.tsx:84-118`) were written to hide empty groups but are **never used** (ledger line 35: "six existing unused-navigation-variable warnings"). Per seeded permissions: technician sees empty Company/Admin and Service headers; customer sees empty Company/Admin, CRM, Service, Inventory, Network/Work Orders headers.
2. **Komponen is unconditional** (item 35): customer/technician/staff/manager see a link to a 403; admin in production sees a link to a 404. The group `System/Developer` exists solely for it.
3. **Dashboard unconditional** vs server `dashboard.view` (item 1) and is itself a collapsible `<details>` group with one child.
4. **Inventory Requests gate mismatch** (item 17): `inventory.view` alone grants the link; the server needs `request.view.own|all`. No seeded role hits it (manager/staff have `.view.all`, technician `.view.own`), custom roles do.
5. **Partial mirrors**: Service items (`service.manage`), Dispatch (`*.reassign`, `is_active`), Technician Performance (role/org rule).
6. **Overlapping active state**: `isActive('/admin/reports')` is a prefix match so on `/admin/reports/technician` both "Reports" and "Technician Performance" are `aria-current=page` (E2E at technician-performance.spec.ts:170 asserts Reports link is *absent* for the technician user, which hides this).
7. Items placed under a domain-mismatched group: Documents (`system.setting`) under CRM/Operations; Number Sequences (`system.setting`) under Service; Tickets under Finance/Reports.
8. Client gates are advisory only; no test compares sidebar visibility against server access. Existing backend prior art only checks destinations (`tests/Feature/AdminMenuAuthorizationTest.php`); E2E checks label presence per demo role (`tests/e2e/auth-roles.spec.ts:30-80,185-198`).

---

## Problem Statement
The admin sidebar has 8 titled, collapsible groups (exceeds the card's 7-group cap), shows headers for groups the user cannot use, and offers links (Komponen, Dashboard, Permintaan Barang via `inventory.view`) whose destinations are denied by the server. A user should only see navigation they can actually open, in at most seven groups, without any change to server authorization.

## Solution
Restructure `AdminLayout` navigation into a declarative nav definition (groups → items, each item with its exact server-equivalent capability predicate), render only items the actor can open, hide any group with zero visible items, and cap the rendered groups at seven. Keep every route, permission name, server gate, and tenant scoping unchanged. Backend authorization stays the source of truth; the client predicate only mirrors it, and the shared-prop `canMyWork`/work-queue signals stay server-computed.

## User Stories
1. As any signed-in user, I see at most seven sidebar groups on every page and viewport.
2. As a customer, I see only Dashboard and Ticketing — no empty group headers and no developer link.
3. As a technician, I see Dashboard, Customers, Locations, Inventory entries (Requests, Inventory, Categories, Units, Stocks, Item Finder, Stock Movements), Network Assets, SPK, Pekerjaan Saya, Ticketing, Evaluations (own), Technician Performance — and no Company/Admin or Service group.
4. As a manager, I see Approval/Dispatch only because I hold approve/assign capabilities, and I do not see Users/Roles/Permissions/Employees/Vehicles/Documents.
5. As staff, I do not see Technician Performance, Audit Log, or any admin group.
6. As an admin in a local/testing environment, I can reach the component gallery; as an admin in production I do not see a link to a route that returns 404.
7. As a user whose role has only `inventory.view`, I do not see "Permintaan Barang" (server would 403).
8. As a user with `service.manage` but not `service.view`, I see the Service items the server lets me open.
9. As a user with a custom role missing `dashboard.view`, I do not see a Dashboard link that 403s.
10. As a user on `/admin/reports/technician`, exactly one of Reports / Technician Performance is marked current.
11. As a keyboard user, group summaries and items are focusable, `aria-current=page` is exposed, groups containing the active route open by default.
12. As a mobile user (320px), the drawer opens, shows the same filtered set, closes on navigation, and does not horizontally scroll.
13. As an operator, work-queue badges (approval pending, dispatch unassigned, my work pending) still appear only for eligible users and only when > 0.
14. As a tester/QA/reviewer, I can assert per-role sidebar contents from a single exported nav definition.

## Implementation Decisions
- **Scope = frontend navigation only.** No new routes, permissions, migrations, or controller changes. No raw SQL. Tenant/company scoping untouched (permissions are already per-user-per-company via shared props).
- **Nav definition module:** one typed nav config (groups[], items[] with `label`, `href` via Ziggy `route()` where a named route exists, `icon`, `visible(ctx)` predicate, `badge(ctx)`, `activeMatch` rule) consumed by `AdminLayout`. Context = `{ can, canAny, canMyWork, workQueues }`. Place under `resources/js/Layouts/` or `resources/js/lib/`; export pure helpers (`visibleGroups(ctx)`) so Node/Playwright selfchecks can test them (prior art: `*.selfcheck.mjs` files in `Pages/Admin/Approvals/components`, `Dispatch/components`, `Components/composite/permissionGroups.selfcheck.mjs`).
- **Group hiding:** compute visible items first; render a group only if ≥ 1 item visible. Delete the six unused `hasXNavigation` consts.
- **Group cap:** enforce ≤ 7 rendered groups by definition (a unit/selfcheck assertion on the config: `groups.length ≤ 7` including a standalone Dashboard entry).
- **Proposed target (draft, subject to owner Q1):** (1) Dashboard (untitled top item, not a `<details>` group); (2) Company/Admin = Organization, Company, Users, Roles, Permissions, Documents, Number Sequences, and — admin-only — Komponen gated by `developer.components.view`; (3) CRM/Operations = Customers, Locations, Employees, Vehicles; (4) Service = Service, Bandwidth Profiles, Speed Profiles, SLA Tiers; (5) Inventory = unchanged seven items; (6) Network/Work Orders = unchanged five items; (7) Finance/Reports = Billing, Tunggakan, Ticketing, Evaluations, Reports, Technician Performance. Net: the "System/Developer" group disappears; with Dashboard counted as a group the total is 7, without it 6. Keep group title strings `Finance/Reports` and item labels unchanged to preserve E2E selectors.
- **Predicates (server-equivalent):** Dashboard `dashboard.view`; Komponen `developer.components.view` (env gating cannot be known client-side — see Q2); Permintaan Barang `request.view.own|all` only; Service items `service.view|service.manage`; Dispatch `ticket.assign|reassign` or `spk.assign|reassign` (is_active assumed for any logged-in user); Technician Performance `report.technician`; all others as in the table above. Item 30 (Tunggakan) must be confirmed against `InvoiceController::receivables` before locking.
- **Active state:** longest-prefix-wins among visible items (or exclude `/admin/reports/technician` from `Reports` matching) so a single item is current.
- **No new primitives:** use existing `Components/ui` `Sidebar`/`SidebarSection`/`SidebarItem` (shadcn Sidebar migration is a separate, later batch).
- **Optional shared prop:** a server-derived `navigation` capability set is NOT required; if the owner wants Komponen env-aware, expose a boolean shared prop (e.g. `canDeveloperComponents`) computed server-side with the same env+permission rule rather than duplicating env logic in TS.

## Testing Decisions
- **Backend/feature (inbils_test, serial):** extend `tests/Feature/AdminMenuAuthorizationTest.php` (existing role-driven destination checks, `userWithRole` helper, seeded `RolePermissionSeeder`) with a table-driven "every sidebar href is openable by roles that can see it" test, and a custom-role test for each mismatch (Dashboard w/o `dashboard.view`, requests with only `inventory.view`, service with only `service.manage`). Keep `ComponentGalleryAuthorizationTest` as is.
- **Pure unit/selfcheck:** `visibleGroups(ctx)` selfcheck `.mjs` (like `permissionGroups.selfcheck.mjs`) asserting: ≤ 7 groups, no empty group, per-seeded-role expected label sets, single active item on `/admin/reports/technician`.
- **E2E:** keep `tests/e2e/auth-roles.spec.ts` role expectations (labels/exact names unchanged) and add: no `summary` for empty groups (customer, technician), no Komponen link for non-admin, group count ≤ 7 via `aside details > summary` + standalone Dashboard, 320px drawer with no horizontal overflow, keyboard Tab/Enter to an item. Keep `technician-performance.spec.ts:159-170` working (`Finance/Reports` summary text, link names). `billing-lifecycle.spec.ts:119` must still pass.
- Tester gate: `npm run typecheck`, focused eslint on AdminLayout + nav module, `npm run build`, focused PHPUnit then canonical E2E against `inbils_test` (one DB test run at a time; per ledger rule QA/reviewer must not run DB tests concurrently).
- QA (independent, read-only): per-seeded-role matrix (admin/manager/staff/technician/customer + one custom role) comparing visible links to HTTP status of each href; keyboard, dark mode, 320/768/1280 screenshots.
- Reviewer: confirm no server-side/permission/route diff, no regression of exact labels, scoped diff to AdminLayout + new nav module + tests + docs.

## Out of Scope
- Any change to routes, policies, permission names/seeds, Role/Persona/SoD work, or tenant logic.
- Migrating `ui/Sidebar.tsx` to shadcn `sidebar` (separate Batch 7 in the shadcn plan).
- Adding new destinations (Notifications, Company Settings, Audit Log, Subscriptions, Inventory Returns UI) unless the owner opts in (Q4).
- Topbar/user menu/notification bell redesign, collapse-to-icon rail, search-in-menu, favorites.
- Server-driven menu API.
- Renaming user-visible labels (would break E2E selectors).

## Dependencies / ordering vs other lanes
- Ledger execution order puts Sidebar IA in the post-Location recovery assessment queue (line 94: "…Role persona/SoD; Sidebar IA; Loading/progress; Head Company…"). **Role persona/SoD** should land first or be agreed with this lane because it may change roles/permissions the sidebar predicates mirror (e.g., which role holds `dashboard.view`, `inventory.request.view.*`, `report.technician`).
- **Reporting P1** (done) and **Technician Performance** (done) set contracts this lane must preserve: `report.technician` link and the Finance/Reports summary text used by `tests/e2e/technician-performance.spec.ts`.
- **Dispatch/My Work** and **Notification Center** lanes use `canMyWork`/`workQueues` props and the bell; do not change those shared props' shapes (type `resources/js/types/index.ts:82-91`).
- **Loading/progress** lane may also touch `AdminLayout`; schedule serially (single_writer rule; ledger header).
- **Shadcn Batch 7 (Shell)** is independent and later; do the nav-definition extraction first so the shell migration only swaps primitives.
- Chain gating inside the lane: t_81b959f1 → t_f106f2e8 → t_c969a55f → t_77adedd5; DB-touching gates serial on `inbils_test`; nothing dispatches until the owner approves one answer per decision above (the Hermes freeze comment on all four cards).

## Approved decisions

Owner approved the recommended option of every decision on 2026-10-07. Applied by UI `t_81b959f1` (2026-10-10):

1. **Shape: a.** Six titled groups (`Company/Admin`, `CRM/Operations`, `Service`, `Inventory`, `Network/Work Orders`, `Finance/Reports`) plus a standalone Dashboard link outside any `<details>`: 7 entries. `System/Developer` is gone. Group titles and item labels are unchanged. The single definition is `resources/js/lib/navigation.ts`; `AdminLayout` only renders `visibleNavGroups()`.
2. **Komponen: a.** Offered only with `developer.components.view` and the shared prop `developerGalleryEnabled` (`HandleInertiaRequests`, `app()->environment(['local', 'testing'])`, the rule of the `admin.components` route). It is the only backend change. `AdminMenuAuthorizationTest` pins flag and route together for local, testing, staging, production and e2e.
3. **Dashboard: a.** Hidden without `dashboard.view`; the post-login redirect still answers 403 for such a user (unchanged).
4. **Destinations: a.** No new destination.
5. **Permintaan Barang: a.** Offered for `inventory.request.view.own` or `inventory.request.view.all` only.
6. **Service: a.** `service.view` or `service.manage` for Service, Bandwidth Profiles, Speed Profiles and SLA Tiers.
7. **system.setting items: a.** Documents and Number Sequences sit in Company/Admin after Permissions.
8. **Persistence: a.** `<details>` keeps no stored state; a group is open by default only when it holds the active route.

Within the same contract: Dispatch also mirrors the server's `is_active` requirement (shared `auth.user.is_active`) and `*.reassign`; exactly one item is current (longest matching href, so `/admin/reports/technician` marks only Technician Performance); a group without a visible item is not rendered (`SidebarSection` also renders nothing without children); the six unused `hasXNavigation` constants are deleted; queue badges keep their rules (positive count; Pekerjaan Saya also needs `workQueues.myWorkEligible`).

Accepted gap: Technician Performance mirrors the route gate `report.technician` only. `ReportController::authorizeTechnicianReportActor` also needs the technician role or, for a manager, an active employee profile in an organization, so a manager without one sees a link that answers 403. Closing it needs a server-computed flag, outside this lane.

Verification chain: `tests/Feature/fixtures/sidebar-destinations.json` is the destination table. `resources/js/lib/navigation.selfcheck.mjs` compares it with the config, `AdminMenuAuthorizationTest` compares it with the server for every seeded role, `tests/e2e/sidebar-ia.spec.ts` checks the rendered sidebar.

## Closure

Done 2026-10-10: QA PASS, reviewer APPROVED. Evidence and known follow-ups in the ledger section "Sidebar IA replacement closure (2026-10-10)".
