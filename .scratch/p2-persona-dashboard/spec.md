# P2 Persona Dashboard — replacement-contract DRAFT (not approved)

Status: done
Ledger: docs/plans/INBILS_OMP_BACKLOG.md (cards `t_a5536496`, `t_0f437faa`, `t_f1c27ea9`, `t_336a4dc4`, `t_09021a86`, `t_40dc90eb`)

> **Approved by the owner 2026-10-10: the recommended option of every Owner decision below is the contract.** Where a decision lists no recommendation, the most conservative option applies and is recorded under "Approved decisions" when the lane closes. Original acceptance unrecovered.

## Owner decisions (approve in one word: "setuju rekomendasi" or pick per item)

1. **Persona model** — How is a "persona" determined?
   - A) Capability/permission-driven sections (no role-name branching) — *recommended*; tradeoff: resilient to Role persona lane changes, slightly less "designed per role".
   - B) Branch by the five seeded role names, bespoke layout per role; tradeoff: tailored but breaks with custom roles.
   - C) A) plus an optional per-user pinned order; tradeoff: needs schema/UI, scope creep.
2. **Which exception counts are in v1?**
   - A) Approvals waiting, dispatch unassigned, my work, SLA breached, overdue invoices, low stock, warehouse request queue, return queue/exceptions, unread notifications — *recommended*; tradeoff: covers every existing data source, more tests.
   - B) Only the three existing queues + SLA breached + unread; tradeoff: fast, leaves finance/warehouse unserved.
   - C) A) plus SPK overdue (`scheduled_date` past) and "SLA due in N hours"; tradeoff: needs new definitions the code doesn't have.
3. **Overdue invoice definition** — A) compute from `due_date`+balance regardless of status flip (*recommended*; tradeoff: always fresh, differs from what the invoice list's `overdue` filter shows until scheduler runs) B) count only `status = overdue`; tradeoff: matches list but stale between runs; C) A) and also fix list filter/statusOptions to match.
4. **Admin statistics (users/roles/permissions/modules)** — A) keep only for actors with users.manage/roles.manage/company.manage and drop for everyone else (*recommended*; closes the disclosure to customer/technician); B) keep for admin+manager; C) remove entirely; tradeoff: lose existing quick links.
5. **Customer / no-capability landing** — A) minimal page with ticket link + empty state, keep `dashboard.view` for all (*recommended*; tradeoff: no change to post-login redirect); B) redirect customers to Tickets; C) remove `dashboard.view` from customer (touches seeder/Role lane).
6. **SLA breach notification recipients** (currently only `created_by`) — A) leave as-is, dashboard is the surface (*recommended*; tradeoff: no scope creep) B) also notify assignee; C) assignee + users with ticket.assign.
7. **Notification feed on dashboard** — A) unread count + latest 5 using existing Resource, add titles for Inventory/MaterialReturn types (*recommended*); B) count only; tradeoff: B skips the already-known unmapped-type gap.
8. **Data freshness** — A) live queries per request, no cache (*recommended*; tradeoff: simplest/correct, query cost per visit) B) short TTL per-company cache (30–60 s); tradeoff: stale counts vs. drill-down mismatch.
9. **Scope of card chain** — A) contract covers all six cards: planner `t_a5536496` closed as superseded by this approved contract, backend/UI/tester/QA/reviewer executed (*recommended*); B) skip planner card, mark `superseded`; both leave Hermes untouched.

## Cards — (ordered chain)

`t_a5536496` (planner TODO, legacy t_724f6b3b) → `t_0f437faa` (backend, legacy t_f9783de7) → `t_f1c27ea9` (UI, legacy t_a31bc2c0) → `t_336a4dc4` (tester, legacy t_43a770fe) → `t_09021a86` (QA-ISP, legacy t_5c5165fc) → `t_40dc90eb` (reviewer, legacy t_4ce508c1).

Chain verified in export Hermes board export (read-only snapshot, removed after the audit) links (a5536496→0f437faa→f1c27ea9→336a4dc4→09021a86→40dc90eb) and ledger predecessor/successor lines (`docs/plans/INBILS_OMP_BACKLOG.md` e.g. "predecessor: `t_336a4dc4`; successor: `t_40dc90eb`"). All six are ledger `needs_recovery`, Hermes `scheduled`.

## Recovery result

**Original acceptance found: NO.**
- Hermes bodies of all six are identical boilerplate: "Recovered from pre-reinstall authoritative Kanban snapshot dated 2026-09-12 … Original detailed comments, events, dependencies, and run history may be incomplete because the canonical SQLite backup is missing." Only titles carry scope: "P2 BACKEND: persona dashboard read models dan exception counts", "UI P2: persona dashboard dan exception queues", "TESTER/QA-ISP/REVIEWER P2: … persona dashboard dan exception queues".
- Each card has exactly one comment (author `isp`): "SCHEDULED: Freeze pemulihan Linux: dependency graph legacy hilang; tunggu rekonstruksi dependency dan preflight runtime terverifikasi." Exactly one run each (status scheduled, zero execution). `result: null`, no worker/claim. Nothing was ever executed.
- Exact searches for legacy IDs (`t_724f6b3b`, `t_f9783de7`, `t_a31bc2c0`, `t_43a770fe`, `t_5c5165fc`, `t_4ce508c1`) and `t_` IDs across the whole repo hit only `docs/plans/INBILS_OMP_BACKLOG.md` (stub entries, each: "scope/acceptance: belum pulih; snapshot body hanya recovery warning; evidence: tidak lengkap") and `docs/plans/INBILS_OMP_SOURCE.json` (the same bodies). `docs/process/`, `docs/claude-batches/`, `docs/modules/` do not exist. `TASKS.md`/`ROADMAP.md` contain no card IDs.
- Ledger note (`INBILS_OMP_BACKLOG.md:~255`): P2 persona dashboard is one of nine lanes (6 cards) "each needing an owner-approved replacement contract before coding". Ledger line ~94 names "Role persona/SoD … P2 dashboard" in the post-Location recovery assessment — no lane-specific recovery note exists.
- **Only indirect, non-binding design evidence** (not acceptance for these cards):
  - `docs/business/ticketing.md:106-107`: "SLA breach: computed (sla_deadline < now AND status NOT IN (resolved, closed)) — badge di list/dashboard"; `:152-153` "badge 'SLA breached' + dashboard widget"; `:252-253` "Dashboard widget: SLA breach count"; `:290-292` "NOC dashboard (active by category + breach count + my assigned)".
  - `docs/business/inventory.md:160-161`: "saat movement: jika `quantity <= min_stock` → badge di product list + dashboard widget."
  - `docs/architecture/folder-structure.md:86-87`: "Dashboard/ # NOC view + Manager view" (the only persona-like wording).
  - `ROADMAP.md:292` (Phase 8): "Dashboard widget diperluas: chart (sales trend, ticket by status, spk per technician, SLA compliance)" — charts belong to Reporting, not this lane.
  - `docs/ui/dashboard.md:3`: "Dashboard shows actionable operational summary. Content over decoration… Respect permissions. Avoid expensive queries; use backend summaries."
  - `docs/plans/ENTERPRISE_ISP_EXECUTION_PLAN.md:16`: "P2/P3 remains unbuilt until explicit product/external dependency approval." — the `P2` label of these cards may be this product tier; an explicit owner approval (the replacement contract) is therefore required anyway.

## Current implementation map

**Dashboard (generic, not persona-aware)**
- `app/Http/Controllers/Admin/DashboardController.php` — `Gate::authorize('dashboard.view')`; returns `company`, `userCount`, `roleCount` (global `Role::query()->count()`), `activeUserCount`, `permissionCount` (global), `recentActivity` (Spatie Activity, last 10 filtered by `properties->company_id`), and 7 `modules` link cards with raw company-wide counts (Customer, Service, Inventory, Network Asset, SPK, Billing, Ticketing). All unconditional — no per-permission gating.
- `resources/js/Pages/Admin/Dashboard/Index.tsx` — StatCards (Users/Active Users/Roles/Permissions), Company card, Recent Activity, Modules link grid. Uses `Components/ui` (Card, StatCard) and `Components/composite` (PageHeader, StatusBadge).
- **Gap (verified from code + seeder):** `RolePermissionSeeder` gives `dashboard.view` to admin, manager, staff, technician AND customer (comment: "Every demo role lands on /admin/dashboard after login"). So a customer/technician sees user/role/permission totals and company-wide module counts with links they cannot open. [INFERENCE: this is an information-disclosure/UX defect the persona work should close.]
- Existing tests `tests/Feature/DashboardTest.php` assert `userCount`=1, `roleCount`=5, `has('modules', 7)`, `company.*`, customer module count 0; `tests/Feature/AdminRouteTest.php`, `AdminSmokeTest`, `P0CriticalPathTest:508-512`, `DemoUserSeederTest` only assert 200 / auth props. `tests/e2e/auth-roles.spec.ts` asserts main heading `Dashboard` visible for all five roles after login (`support/demo.ts` `loginAs`: technician logs in via `/admin/spk` intended URL).
- Routes: `routes/admin.php:27` `admin.dashboard`; `routes/web.php:19` `/dashboard` redirects there.

**Existing queue read models (`app/Queries/Admin`) — all static, tenant-scoped via `forCompany($user->company_id)`, fail-closed `whereRaw('1 = 0')`:**
- `ApprovalInboxQuery` — `isEligible` (spk.approve|reject or inventory.request.approve|reject), `summary()` → `{waiting_for_me, processed}` (request-attribute cached), `badgeCount()` (null if ineligible), maker-checker (`created_by`/`assigned_to`/`requested_by` ≠ actor). Domains: SPK `waiting_review`, Inventory Request `submitted` via `InventoryRequestApprovalAdapter` (note adapter `filtered()` uses `orderByRaw` with a sqlite/mysql CONCAT branch — existing code, do not copy). Controller `ApprovalInboxController@index` (403 if not eligible).
- `DispatchQueueQuery` — `summary()` → `{unassigned, ticket_unassigned, spk_unassigned, assigned}`; `badgeCount()`; Ticket statuses open|assigned, SPK statuses generated|draft|rejected|assigned; permissions ticket.assign/reassign, spk.assign/reassign; requires `is_active`.
- `MyWorkQuery` — `summary()` → `{total, assigned, in_progress, waiting_action}`; `badgeCount()`; requires active `employeeProfile` in same company + ticket.start/resolve/close or spk.start/submit; actor-assigned only.
- `HandleInertiaRequests::share` (lines 66-74) already shares lazy `workQueues.{myWorkEligible, approvalPending, dispatchUnassigned, myWorkPending}`, `canMyWork`, `notifications.unreadCount` on every Inertia response; sidebar badges in `AdminLayout.tsx:410-445`. `tests/Feature/Admin/ApprovalInboxTest.php:~520-568` proves badge is actor-scoped, tenant-isolated, `null` for ineligible technician — via `admin.dashboard`.
- Prior art tests: `tests/Feature/Admin/{ApprovalInboxTest,ApprovalInboxInventoryTest,DispatchQueueTest,MyWorkTest,NotificationCenterTest}.php`; E2E `tests/e2e/{dispatch-my-work,notification-center,auth-roles}.spec.ts` (dispatch-my-work mutates the shared fixture — see ledger caveats).

**Other exception sources (no dashboard use yet):**
- Ticket SLA: `tickets.sla_deadline`, `sla_breached_notified_at`, `first_response_at`; `Ticket::is_sla_breached` accessor; `TicketController` already supports `?sla_breached=1` filter (`Modules/Ticketing/.../TicketController.php:72,132`: `sla_deadline < now() AND status NOT IN (resolved, closed)`); `DispatchItemResource`/`MyWorkItemResource` expose `due_at`/`sla_breached`. `CheckTicketSlaBreachesJob` (scheduled `tickets:sla` every 15 min, `routes/console.php:12`) notifies **only `created_by`** — not assignee/dispatcher/manager [gap].
- Billing: `BillingService::checkOverdue` flips `sent|partial` with `due_date < now` and `paid_amount < total` → `overdue`; scheduled command `billing:check-overdue`; `BillingService::receivables()` aging buckets. Gap/inconsistency: `InvoiceController` `statusOptions` = draft, issued, partial, paid, void, written_off (line 62) lacks `sent`/`overdue` although the code writes `sent`/`overdue`; the `status` filter itself is free-form (line 75), so `?status=overdue` works but isn't an option [INFERENCE: drill-down links need the option list fixed or to bypass it].
- Inventory: low stock = `stocks.quantity <= products.min_stock` (`StockController.php:243`, filter `?low_stock=1`, `inventory.view`); `InventoryRequestService::getBadgeCounts` → `{my_requests, warehouse_queue}` (warehouse_queue = submitted/approved_reserved/preparing/ready_for_pickup, perms approve|prepare|issue) and `MaterialReturnService::getBadgeCounts` → `{my_pending_returns, warehouse_return_queue}` (awaiting_receipt|exception_open; inventory.return.receive|resolve). Both only reach Inventory index pages today. Material-return exception (`exception_open`) is a natural exception count.
- SPK: `work_orders.scheduled_date`, statuses draft|generated|assigned|in_progress|waiting_review|completed|rejected|cancelled (`WorkOrderController.php:62`); no overdue concept coded [INFERENCE].
- `Modules/Reporting/app/Queries` (SlaComplianceQuery, BusinessMetricsQuery…) are period aggregates with `selectRaw` — wrong shape for live queue counts; do not reuse.

**Notification center**
- Routes `admin.notifications.{index,read,read-all}` (`routes/admin.php:34-36`); `NotificationController` (actor-owned, `paginate(20)`, `additional(unreadCount)`), `NotificationResource` (LINKABLE_TYPES = ticket_assigned, ticket_sla_breached; titles only for those + billing_schedule_failed; everything else → "New notification"/"This notification is available in your inbox."; tenant+policy-checked `detail_url`), `NotificationBell` in AdminLayout topbar. Producers: TicketAssigned, TicketSlaBreached, BillingScheduleFailed, InventoryRequest{Submitted,Approved,Ready,Rejected}, MaterialReturn{Declared,Exception,Rejected}. [Gap, from NotificationResource code: Inventory/MaterialReturn types have no title/message/link mapping.] Notification Center itself is closed under its own approved replacement contract (ledger "Notification replacement closure") — do not reopen.

**Persona = seeded spatie roles (`RolePermissionSeeder`)**: admin (all), manager (approve/reject SPK+inventory, dispatch assign/reassign, billing, reports), staff (inventory prepare/issue/approve, billing create/send/payment, ticket create), technician (spk.start/submit, ticket.start/resolve, inventory.request own, report.technician), customer (ticket.view/create only). Role persona/SoD (cards `t_594beec6…t_3c2d67a9`) is a separate, still-`needs_recovery` lane that may rewrite bundles.

## Problem Statement

Every signed-in user lands on `/admin/dashboard`, which shows the same company-wide admin statistics (user/role/permission totals, module record counts) regardless of who they are. It tells a dispatcher nothing about unassigned work, a manager nothing about pending approvals or SLA breaches, a warehouse user nothing about requests to prepare or returns in exception, finance nothing about overdue invoices, and a technician nothing about their own queue. Non-admin roles, including `customer` and `technician`, see admin metrics they do not need. The data to answer these questions already exists in the queue read models, SLA/billing/stock fields and notifications, but is only reachable by opening each module.

## Solution

Replace the generic dashboard body with a capability-driven "what needs attention now" view. The backend assembles a read model of exception/queue counts, each included only when the actor holds the permission that already guards that queue, scoped to the actor's company, reusing existing query classes. The UI renders count cards with deep links into the existing destination pages (Approval Inbox, Dispatch, My Work, Tickets `?sla_breached=1`, Invoices, Stocks `?low_stock=1`, Returns) plus a short top-N exception list, a notification summary, and an empty state when the actor has no operational capabilities. The current admin statistics are kept only for actors who manage users/roles/company. No new tables, channels, or charts.

## User Stories

1. As a dispatcher/manager (ticket.assign or spk.assign), I see the number of unassigned tickets and SPKs and a link to Dispatch Queue, equal to `DispatchQueueQuery::summary().unassigned`.
2. As an approver (spk.approve/reject or inventory.request.approve/reject), I see "waiting for me" equal to `ApprovalInboxQuery::summary().waiting_for_me`, excluding items I created or am assigned to (maker-checker), linking to Approval Inbox.
3. As a technician with an active employee profile, I see my assigned / in-progress / waiting-action counts equal to `MyWorkQuery::summary()` and a link to My Work; without an employee profile I see no My Work card (not a zero).
4. As anyone with ticket.view, I see SLA-breached open tickets (count + oldest N by `sla_deadline`) linking to `/admin/tickets?sla_breached=1`.
5. As finance/manager (billing.view), I see overdue invoice count and outstanding total, linking to invoices filtered to overdue.
6. As warehouse staff (inventory.request.prepare/issue/approve), I see requests in the warehouse queue (submitted → ready_for_pickup) with a link to Inventory Requests.
7. As warehouse staff with inventory.return.receive/resolve, I see returns awaiting receipt and returns in exception, linking to Returns.
8. As anyone with inventory.view, I see the number of products at or below `min_stock`, linking to Stocks `?low_stock=1`.
9. As a requester/technician, I see my own pending inventory requests (draft/ready_for_pickup) and pending return declarations.
10. As any user, I see my unread notification count and the latest few notifications, linking to the Notification Center.
11. As an admin (users.manage/roles.manage/company.manage), I still see company info, user/active-user counts and recent activity; as a non-admin I do not see user/role/permission totals or company-wide module counts.
12. As a `customer`, I see a minimal page (my tickets link / empty state) and no staff statistics.
13. As a user whose role grants no operational capability, I see a clear empty state, not a page full of zeros and not a 403 (the post-login redirect must still land).
14. As any user, a card is shown only if I could open its destination page; counts match what the destination page lists (no count I cannot drill into).
15. As any user, I never see counts or titles from another company.
16. As an operator on a phone (320px), cards stack in one column, tap targets are usable, dark mode readable, keyboard focus and screen-reader names include the count.

## Implementation Decisions

- **Placement:** a new read-model class under the existing cross-module read-model location (`app/Queries/Admin`, per AGENTS.md rule "Query Class untuk read kompleks (>30 baris atau dipakai >1 controller) taruh di app/Queries"), e.g. a `DashboardQuery` that composes existing queue queries; `DashboardController` stays thin (authorize → query → Resource → Inertia). Resource class wraps the payload (AGENTS: Resource for list/entity responses).
- **Reuse, don't duplicate:** delegate to `ApprovalInboxQuery::summary`, `DispatchQueueQuery::summary`, `MyWorkQuery::summary`, `InventoryRequestService::getBadgeCounts`, `MaterialReturnService::getBadgeCounts`. New counts (SLA breached, overdue invoices, low stock) are new Eloquent queries; if they belong to a module's data they live in that module's service/query and are called from the dashboard read model (AGENTS: no direct cross-module Model import from module code; `app/Queries/Admin` already imports module models, which is the established exception).
- **Gating rule:** each section is built only when the corresponding existing permission/eligibility check passes; missing = key omitted (not 0), mirroring `badgeCount()` returning `null`. No new permission is required for v1; reuse `dashboard.view` for access. If a new capability is wanted later it follows `{module}.{action}`.
- **Tenant scoping:** all new queries use the module's company global scope plus explicit `forCompany($actor->company_id)`; no `withoutCompany()` unless explicitly paired with `where('company_id', …)`. The `roleCount`/`permissionCount` globals stay admin-only (Spatie Role/Permission are the documented tenant exceptions).
- **Definitions (proposed, to be approved):** SLA breached = `sla_deadline < now() AND status NOT IN (resolved, closed)` (matches `docs/business/ticketing.md:106` and `TicketController:132`); overdue invoice = `status IN (sent, partial, overdue) AND due_date < today AND paid_amount < total` computed from `due_date`, not reliant on the scheduler having flipped status [INFERENCE: avoids stale count if `billing:check-overdue` has not run]; low stock = `stocks.quantity <= products.min_stock` for tracked, active products, per `StockController:243`; warehouse queue/returns per the service definitions above.
- **Props contract:** Inertia props `persona`/`panels`-style payload: ordered list of sections each `{key, title, count|null, href, items?: [...] , severity}`; plus `notifications` (shared `unreadCount` already exists; add latest N via Resource); `admin` stats block present only for admin-capable actors. Keep `company` prop. Counts are integers; times ISO strings; money as decimal string (existing Money helper).
- **Persona is a derived grouping, not a role-name branch:** sections are ordered by capability group (Operations, Approvals, Warehouse, Finance, Admin, Personal). This keeps working if the Role persona/SoD lane renames or recomposes roles. The `customer` persona is a capability-less/`ticket.view`-only case.
- **Performance:** one count query per section (aggregates, indexed columns: `(company_id, status)`, `(company_id, assigned_to)`, `(company_id, sla_deadline)` on tickets); no N+1; reuse request-attribute caching already used by `ApprovalInboxQuery`; top-N lists limited (≤5) with `select` of needed columns; no new cache layer in v1. Because `workQueues.*` shared props are lazy but still evaluated on every Inertia visit, the dashboard must not duplicate those evaluations (reuse request cache).
- **Frontend:** page uses `Components/ui` + `Components/composite` only (StatCard, Card, PageHeader, StatusBadge, ResourceState for empty state); cards are links (Inertia `Link`), semantic headings, count included in accessible name, `dark:` variants, mobile-first single column → 2/3/4 grid. No legacy auth components (AGENTS). Types added to `resources/js/types`.
- **Schema/migration:** none. [If owner chooses stored dismissals/snooze or SLA-warning-soon thresholds, a migration would be needed — out of scope by default.]
- **Fixes folded in only if owner approves (small, in lane):** add `sent`/`overdue` to invoice `statusOptions`; extend NotificationResource titles/messages for Inventory/MaterialReturn types shown in the dashboard notification feed; notify assignee (not only creator) on SLA breach. All three are separate owner decisions below.

## Testing Decisions

- **Backend feature tests (MySQL `inbils_test`, serial, `RefreshDatabase`, `RolePermissionSeeder`)**: new `tests/Feature/Admin/DashboardPersonaTest.php` in the style of `ApprovalInboxTest`/`DispatchQueueTest`: per seeded role (admin/manager/staff/technician/customer) assert which sections exist (`assertInertia ->has/missing`), counts equal the destination query's own `summary()`, omitted-not-zero for ineligible, maker-checker exclusion, inactive actor, technician without employee profile; tenant isolation (second company data never counted, incl. `withoutCompany` paths); SLA/overdue/low-stock boundary cases (exactly-now, resolved, closed, paid, soft-deleted, inactive product); query-count budget assertion (`DB::enableQueryLog`) to catch N+1; update/replace `DashboardTest` assertions that encode the old contract (`roleCount`, `modules` 7).
- **Existing tests that must keep passing:** `ApprovalInboxTest` badge tests, `AdminRouteTest`, `AdminSmokeTest`, `P0CriticalPathTest:508`, `AdminMenuAuthorizationTest`, `NotificationCenterTest`.
- **E2E (Playwright, canonical `inbils_test` runner, `tests/e2e/support/demo.ts`)**: new spec modelled on `auth-roles.spec.ts` + `dispatch-my-work.spec.ts`: each demo role lands on Dashboard heading and sees only its sections; card link navigates and destination list count equals card count; empty/customer state; 320px no horizontal overflow, dark mode, keyboard tab order. Must not rely on the mutated shared dispatch/notification fixtures (ledger caveats); create owned fixtures via app routes and clean up.
- **Static gates:** `npm run typecheck`, scoped ESLint, Pint on changed PHP, Larastan level-5 on new/changed query paths, one build (as in Technician/Notification gates).
- No raw SQL in new code (Eloquent builders, `whereColumn`); `selectRaw` only if unavoidable and then justified.

## Out of Scope

- Charts/trend widgets and Reporting aggregates (ROADMAP Phase 8; Reporting V2 lane).
- New notification channels (SMS/WhatsApp/email), realtime/websocket refresh, push (ENTERPRISE_ISP_EXECUTION_PLAN §15 defers these).
- Rewriting roles/permission bundles or SoD policy (Role persona/SoD lane) and Sidebar IA rework.
- Customer portal / customer self-service dashboard (deferred P2/P3).
- Multi-company / Head Company consolidated views (single-company v1).
- Changing any existing queue's eligibility, maker-checker, or filter semantics.
- Persisted user dashboard preferences, widget drag/drop, snooze/dismiss.
- Export/drill-down from the dashboard (P2 export lane).
- Reopening Notification Center closure.

## Dependencies / ordering vs other lanes

- Hard-ish: **Role persona/SoD** (`t_594beec6` … `t_3c2d67a9`, `needs_recovery`) — dashboard sections key off permissions, so it works without it, but if that lane changes capability bundles or splits `dashboard.view`, tester/QA matrix for this lane must be re-run. Recommended order: Role persona/SoD first if both are approved; otherwise this lane proceeds on the current seeder and records the assumption.
- Soft: **Sidebar IA** (nav grouping/badges in `AdminLayout.tsx`) — touches the same shared `workQueues` props; keep prop names stable; do not run in parallel with the UI card.
- Soft: **P2 export/drill-down** — dashboard deep links should target the same filter params the export lane will standardize (`sla_breached`, `low_stock`, invoice `status`); no code dependency.
- Independent of Reporting V2/KPI/Sales lanes and Head Company (single-company v1; a Head Company switcher would require per-company dashboard scoping later).
- Runtime gates: serial MySQL `inbils_test` only; no concurrent QA/reviewer DB runs (ledger caveat); reviewer card must not execute DB tests. Phase boundary: stop after reviewer `t_40dc90eb`; any recovery cards remain `needs_recovery` until the owner approves this contract (one answer per lane: approve defaults for decisions 1–9, or amend).

## Approved decisions

Owner approval 2026-10-10: the recommended option of every decision 1-9 (header above). Backend card `t_0f437faa` applied them as below; the UI, tester, QA and reviewer cards inherit them.

1. **Persona model: A.** Capability-driven. `DashboardQuery` (`app/Queries/Admin`) gates every section on the permission or eligibility check that opens its destination; no role-name branching.
2. **Exception counts: A.** Sections, in capability-group order (operations, approvals, warehouse, finance, personal): `my_work`, `dispatch_unassigned`, `sla_breached` (or the plain `tickets` link), `approvals`, `warehouse_requests`, `return_queue` (with its exception count), `low_stock`, `overdue_invoices`, `my_requests`, `my_returns`; plus the unread-notification feed.
3. **Overdue invoice: A.** Status in `sent|partial|overdue` (the open statuses of `BillingService::receivables()`), `due_date` before today and `paid_amount < total`, computed, so it does not wait for `billing:check-overdue`. `outstanding` is the sum of the balances. The invoice list's `?status=overdue` shows the same set only once that scheduler has flipped the status. Option C (list filter and `statusOptions`) is not applied.
4. **Admin statistics: A.** The `admin` block exists only for `users.manage`, `roles.manage` or `company.manage`; its `modules` links also need each module's own view permission.
5. **Customer / no-capability landing: A.** `dashboard.view` is unchanged. A `ticket.view` actor without a ticket-handling or SLA-reading capability gets a plain `tickets` link (count null); an actor without any capability gets `sections: []` and the UI shows the empty state.
6. **SLA breach recipients: A.** Unchanged (`created_by` only).
7. **Notification feed: A.** `notifications.unreadCount` plus the latest five through `NotificationResource`, which now titles and words the seven Inventory and Material Return types. Those notifications are mail-only today (`via()` returns `['mail']`), so none reaches the feed until a `database` channel is added; that is outside this lane.
8. **Freshness: A.** Live queries per request, no cache.
9. **Card chain: A.** Planner `t_a5536496` is closed as superseded by this contract; backend, UI, tester, QA and reviewer run.

Backend choices inside the contract (confirm at QA):

- **SLA card gate.** Story 4 ("anyone with ticket.view") conflicts with story 12 (a customer sees no staff statistics) because the seeded `customer` and `staff` roles both hold only `ticket.view/create/comment`. Resolved as `ticket.view` plus `ticket.assign|reassign|start|resolve|close` or `report.sla.view`. A handler without `ticket.assign` counts only tickets assigned to them, exactly as the Tickets list does.
- **Low stock** is the set of stock rows (product x location) the Stocks list shows for `?low_stock=1`, inactive and soft-deleted products included, because the list and its tests keep them (story 14: counts match the destination). The draft wording "tracked, active products" is not applied.
- **Union counts** without a single-status filter (`return_queue`, `my_requests`, `my_returns`) link to the narrowest existing tab and can list more rows than the count.
- **Old dashboard props** (`userCount`, `roleCount`, `activeUserCount`, `permissionCount`, `recentActivity`, `modules`) moved into the `admin` block with no compatibility copy; `Pages/Admin/Dashboard/Index.tsx` must be rewritten by the UI card.

## Closure

Done 2026-10-10: QA PASS, reviewer APPROVED. E2E 13/13 passed.
