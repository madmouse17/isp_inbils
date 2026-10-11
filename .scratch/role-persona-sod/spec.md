# Role persona & Separation-of-Duties (SoD) — DRAFT replacement contract

Status: done
Ledger: docs/plans/INBILS_OMP_BACKLOG.md (cards `t_594beec6`, `t_f4e0d462`, `t_81eccd66`, `t_3c2d67a9`)

> **Approved by the owner 2026-10-10: the recommended option of every Owner decision below is the contract.** Original acceptance unrecovered.

## Owner decisions (approve in one word: "setuju rekomendasi" or pick per item)

**D1. Which personas ship?**
- A) Keep the 5 existing roles; split duties inside them (manager = controller, staff = operator). *Recommended* — smallest blast radius, no data migration of user-role assignments, demo/e2e keep working. Tradeoff: still only one 'manager' so a one-person company cannot hold both inventory and billing control without admin.
- B) A + seed `noc` (view-only per docs/business) so existing `noc` references stop being dead. Tradeoff: sixth role, `RolePermissionSeederTest` count and e2e demo user change.
- C) A + dedicated `inventory_controller` and `billing_controller` roles (execution plan wording). Tradeoff: best SoD, but 7 roles, demo/e2e/RolePolicy protected list and user-assignment UX grow.

**D2. Stock adjustment control model?**
- A) Two-step: request (operator/manager) + approve by a different user with a new `inventory.stock.adjust.approve`, recorded requester/approver/reason/before/after. *Recommended* — satisfies plan acceptance #3 literally. Tradeoff: new table/columns, new UI affordance, adjust no longer instant.
- B) Keep single-step but restrict `inventory.stock.adjust` to admin only (break-glass, audited). Tradeoff: minimal code, but one admin can still self-adjust and ops need admin for cycle counts.
- C) Single-step allowed for manager only if adjustment ≤ a threshold quantity; above needs approval. Tradeoff: more policy surface, threshold config needed.

**D3. Admin break-glass?**
- A) Admin keeps all permissions; creator/assignee/recorder same-actor guards still bind admin; a documented, audited override reason is *not* added. *Recommended* — matches current behaviour (P0CriticalPathTest uses a second admin). Tradeoff: sole-admin tenants may be stuck on same-actor cases.
- B) Admin may override same-actor guards with a mandatory reason, written to audit log with a break-glass marker. Tradeoff: more code and a visible policy hole.
- C) Admin loses operational permissions (access-admin only). Tradeoff: strongest SoD but breaks existing admin tests, setup wizard flow and demo admin dashboards.

**D4. Invoice cancel / payment reversal scope?**
- A) Cancel requires `billing.cancel` held only by billing controller (manager) and canceller ≠ invoice creator/sender; reversal guard unchanged. *Recommended*. Tradeoff: needs a `sent_by`-style actor or reuse activity log for 'sender' (check — only `sent_at` exists; creator column availability [INFERENCE: verify `created_by` on invoices]).
- B) Only restrict by permission (staff cannot cancel/reverse; manager can, no same-actor check on cancel). Tradeoff: fastest, leaves Gap #4 open.

**D5. Role/permission editing guard?**
- A) Non-admin cannot assign `admin` or edit seeded-role permissions; saving a role that violates the conflict set is rejected unless actor is admin. *Recommended*. Tradeoff: some friction for custom role design.
- B) Warn only (flash + audit entry), no block. Tradeoff: weaker but low friction.
- C) Out of scope this lane (document as known gap). Tradeoff: leaves escalation surface.

**D6. Seeder semantics for existing databases?**
- A) Seeder `syncPermissions` on the 4 seeded non-admin roles (authoritative bundles) and never touches custom roles. *Recommended*. Tradeoff: overwrites any admin-made edits to those seeded roles.
- B) One-off additive migration that revokes the specific conflicting permissions from manager/staff only. Tradeoff: preserves other edits, but seeder and migration drift.
- C) Keep additive seeder; document manual revoke. Tradeoff: SoD not actually enforced on live data.

**D7. Does the lane include any UI?** (no UI card exists)
- A) No UI: backend + existing UI permission gates only. *Recommended* — matches the four-card chain. Tradeoff: stock adjust approval has no dedicated screen unless D2=B.
- B) Add one UI card limited to the adjustment request/approve state and the matrix-driven button visibility. Tradeoff: new card/gate and canonical-UI QA.

**D8. Matrix source of truth?**
- A) Create `docs/SECURITY.md` §2 as the generated/verified matrix and delete stale statements in docs/business tables. *Recommended*. Tradeoff: documentation churn.
- B) Keep docs/business/*.md as the matrices and only add a short SoD section. Tradeoff: two sources drift (already drifted for noc).

> Draft only. No approval implied. Read-only investigation; nothing in repo or ~/.hermes was changed.

## Cards — (ordered chain)

1. `t_594beec6` — [t_491d8cb9] P1 BACKEND (assignee code-executor)
2. `t_f4e0d462` — [t_3a02ef92] TESTER P1 (tester) — parent: t_594beec6
3. `t_81eccd66` — [t_b75b5af6] QA-ISP P1 (qa-isp) — parent: t_f4e0d462
4. `t_3c2d67a9` — [t_27017017] REVIEWER P1 final (reviewer) — parent: t_81eccd66

There is **no UI card** in this lane (unlike Technician/Location). Any UI work (sidebar/menu/role-edit page) would be outside the four cards unless the owner adds it (see Decision D7). Links in export confirm the linear chain t_594beec6 → t_f4e0d462 → t_81eccd66 → t_3c2d67a9; none have predecessors outside the lane.

## Recovery result

- **Original acceptance: NOT found.** Searched exact IDs (`t_594beec6`, `t_f4e0d462`, `t_81eccd66`, `t_3c2d67a9` and legacy `t_491d8cb9`, `t_3a02ef92`, `t_b75b5af6`, `t_27017017`) across `docs/` (docs/process, docs/claude-batches, docs/modules do not exist), `ROADMAP.md`, `TASKS.md`, `CHANGELOG.md`, tests, Modules, app, database, resources. Hits only in `docs/plans/INBILS_OMP_BACKLOG.md` and `docs/plans/INBILS_OMP_SOURCE.json`.
  - Ledger (`docs/plans/INBILS_OMP_BACKLOG.md:442-447, 562-566, 626-631, 969-973`): each card says "scope/acceptance: belum pulih; snapshot body hanya recovery warning; evidence: tidak lengkap", next "Kontrak hilang; pulihkan dari sumber exact legacy ID, jangan coding".
  - Snapshot JSON (`INBILS_OMP_SOURCE.json:1106-1108, 1746-1748, 2226-2228, 4266-4268`) bodies are only the generic recovery boilerplate ("Recovered from pre-reinstall authoritative Kanban snapshot dated 2026-09-12 … Original detailed comments … may be incomplete …").
- **Hermes export facts** (Hermes board export (read-only snapshot, removed after the audit)): all four cards `scheduled`, priority 0, `completion_contract: local-only`, no runs other than a single `scheduled` run each with summary "Freeze pemulihan Linux: dependency graph legacy hilang; tunggu rekonstruksi dependency dan preflight runtime terverifikasi." (author `isp`, 1789395654-71). Never started/claimed; 0 failures; no results; no skills/model overrides. Titles carry legacy IDs; bodies say "Original status: todo" and "Do not dispatch until the Linux project, PHP/Composer, MySQL inbils_test, and worker routes are verified." Links preserved.
- **Closest surrogate (NOT the original, must not be passed off as it):**
  - `docs/plans/ENTERPRISE_ISP_EXECUTION_PLAN.md:143-181` §5 P0-B "Action RBAC, maker/checker, durable actor evidence": *"Technician can execute assigned SPK work but cannot approve own SPK. Inventory operator can receive/issue/transfer; stock adjustment requires distinct inventory controller/manager approval or tightly limited admin path. Billing operator can draft/send or record payment according to assigned duty; payment reversal and invoice cancellation require billing controller/manager, not same maker where material. Manager is not universal operator for stock + SPK + billing. Admin break-glass remains auditable and documented."* Acceptance tests there: (1) denied permissions 403 even if UI manipulated; (2) creator/technician cannot approve own SPK; (3) stock adjustment records requester, approver, reason, before/after; (4) payment reversal & invoice cancellation record actor, reason, timestamp & keep reconciliation invariants; (5) seeded roles match documented matrix and menu visibility.
  - `docs/ENTERPRISE_ISP_READINESS.md:96` "Manager role combines stock adjustment, SPK approval, payment recording, and invoice cancellation (`RolePermissionSeeder.php:146-176`)"; `:142`, `:157`, `:260`.
  - The words "persona" and "capability bundle" appear nowhere else in docs/code except the unrelated P2 'persona dashboard' lane cards.
- Verdict: titles + the P0-B plan are the only evidence; treat this draft as an owner-approved *replacement*, not recovery.

## Current implementation map

**Roles** (the only ones; `RolePermissionSeeder` creates exactly 5; `RolePermissionSeederTest::test_creates_five_roles` asserts it): `admin` (all permissions via `givePermissionTo($permissions)`), `manager`, `staff`, `technician`, `customer`. No newer seeded roles. Custom roles possible via Roles UI (`RoleController`, `roles.manage`). `DemoUserSeeder` creates one user per role (`*@demo.inbils.test`); `SetupWizardService` assigns `admin`. Roles are **global** (`config/permission.php` `teams=false`), users are tenant-scoped.

**Role → permission summary (verified from seeder)**

| Duty | admin | manager | staff | technician | customer |
|---|---|---|---|---|---|
| Access admin (`users.manage`, `roles.manage`, `company.manage`, `system.setting`, `developer.components.view`) | ✔ | – | – | – | – |
| Stock receive / issue | ✔ | ✔ | ✔ (both) | issue only | – |
| Stock transfer | ✔ | ✔ | – | – | – |
| **Stock adjust** | ✔ | ✔ | – | – | – |
| Inventory request create / cancel.own | ✔ | – | – | ✔ | – |
| Inventory request approve + reject | ✔ | ✔ | **✔** | – | – |
| Inventory request prepare + issue | ✔ | ✔ | **✔** | – | – |
| Return declare / receive / resolve | ✔ | receive+resolve | receive | declare | – |
| SPK create / assign / reassign | ✔ | create+assign+reassign | create | – | – |
| SPK start / submit | ✔ | – | start+submit | start+submit | – |
| **SPK approve + reject** | ✔ | **✔** | – | – | – |
| Billing create / send | ✔ | ✔ | ✔ | – | – |
| **Billing payment.record** | ✔ | ✔ | ✔ | – | – |
| **Billing payment.reverse / cancel** | ✔ | ✔ | – | – | – |
| Ticket start/resolve | ✔ | – | – | ✔ | – |
| Ticket assign/close/spawn_spk | ✔ | ✔ | – | – | – |
| Reports: view / technician / audit.view | all | view+technician | view | technician | – |

**Existing SoD controls (already implemented, do not redo)**
- SPK: `SpkService::approve/reject` (Modules/SPK/app/Services/SpkService.php:188-228) — capability checked before and inside the transaction, creator and assigned technician rejected with 422; `approved_by/approved_at/rejected_by/rejected_at` columns (migration `2026_08_14_120001`); tests `SpkSelfApprovalGuardTest` (creator/assignee/cross-tenant/persistence), `ApprovalInboxTest` (maker-checker parity, stale double-approval, cross-tenant 404). Approval Inbox (`ApprovalInboxQuery`, `ApprovalInboxItemResource`, `Approvals/Index.tsx`, `ReviewSheet.tsx`) already surfaces 'Maker-checker' guard.
- Payments: `BillingService::reversePayment` (…/BillingService.php:271-308) — recorder cannot reverse (422), `cancelled_by/cancelled_at/cancel_reason` (migration `2026_08_14_130003`), audit log; `PaymentReversalTest` (10 AC incl. same-actor, cross-tenant, staff/technician 403). `cancel()` stores `cancelled_by` but has **no same-actor guard**; it only requires `billing.cancel` and no active payments.
- Inventory request: requester cannot approve/reject/issue own request (service `InventoryRequestService.php:250,420,489`, policy `InventoryRequestPolicy.php`, adapter for Approval Inbox); MaterialReturn technician/receiver guards; tests `InventoryRequestWorkflowTest`, `ApprovalInboxInventoryTest`, `MaterialReturnCapabilityTest`.
- Stock: `StockService::adjust` (Modules/Inventory/app/Services/StockService.php:179-215) requires note, writes `StockMovement.created_by` and activity `stock_adjustment` (old/new/delta/note). Tests `StockRbacAuditTest`, `StockPermissionTest`.
- Admin-only developer gallery (fail-closed) and `RolePolicy::delete` protection of 5 seeded roles.

**Gaps vs the P0-B contract / SoD hazards (all verified in source)**
1. **Manager is the universal operator**: stock receive/issue/transfer/**adjust** + SPK create/assign/**approve** + billing create/send/**record/reverse/cancel** + inventory request approve/prepare/issue. Readiness doc names this as the P0 blocker.
2. **Staff** holds both the *control* (`inventory.request.approve/reject`) and the *execution* (`prepare`, `issue`, `stock.receive/issue`) halves of the inventory-request chain, plus `billing.create/send/payment.record`. Only the *requester* is barred; nothing prevents approver == preparer == issuer.
3. **Stock adjustment**: no requester/approver split; actor is only `created_by` + activity causer. Plan acceptance #3 ("requester, approver, reason, before/after") is unmet (no approver column; no request/approval workflow).
4. **Invoice cancellation**: no maker guard (creator/sender/recorder can cancel). Payment *record* vs *reverse* is guarded; *create/send* vs *cancel* is not.
5. **Seeder is additive**: `givePermissionTo` never revokes. Narrowing a bundle in the seeder will NOT remove permissions from existing DBs; demo/e2e DBs reseeded only gain permissions. A migration/sync strategy is required.
6. **Privilege-escalation surface**: `UpdateUserRequest`/`StoreUserRequest` accept any `roles.*` that `exists:roles,name`; `RolePolicy::update/edit` permits editing `admin`/`manager`/… permissions for any `roles.manage` holder (only delete is protected). Today only admin holds `users.manage`/`roles.manage`, so it is latent, but a custom role could be created that edits itself.
7. **Roles are global across tenants** (`teams=false`); editing a role via UI affects all companies. v1 is single-company per ledger, so tolerable but must be stated.
8. **`noc` ghost role**: referenced in `AssignTicketRequest` (`HasAnyActiveRole(['admin','manager','noc','staff','technician'])`), `EvaluationController` (employees filter), ticket `source=noc`, and in docs/business/{billing,customer,inventory,network,ticketing}.md matrices — but never seeded. Business docs describe `noc` with view-only rights.
9. **Documented matrix missing**: `AGENTS.md:36`, `TASKS.md:72,157,178,191`, `ROADMAP.md:27`, `CHANGELOG` all point at `docs/SECURITY.md` §2 RBAC matrix — **that file does not exist** (docs/ listing). Plan acceptance #5 ("seeded roles match documented matrix") has no doc to match; docs/business/*.md tables are stale vs seeder (e.g. billing.md says staff cannot suspend — ok — but says manager 'cancel' etc.; inventory.md omits request/return workflows).
10. Test fixtures that encode the current manager/staff bundles and will need updating: `RolePermissionSeederTest` (5 roles; manager has `stock.adjust`), `StockRbacAuditTest::test_manager_can_adjust`, `PaymentReversalTest` (uses 'manager' as the reverser, admin as recorder), `AdminMenuAuthorizationTest` (staff reports, manager approval inbox), `ApprovalInboxInventoryTest`, `TechnicianPerformanceAuthorizationTest` (manager), `tests/e2e/auth-roles.spec.ts` roleExpectations (manager sees Billing/Reports; staff Inventory/Billing; technician hides Billing).
11. `P0CriticalPathTest` and `SpkCompletionOrchestrationTest` already comment 'P0-B B2' for SPK approver ≠ creator — partial P0-B evidence; no CHANGELOG/ledger entry declares P0-B closed.

## Problem Statement

A single seeded `manager` (and, for inventory requests, `staff`) can create, execute, approve, reverse and cancel the same business object, and the stock-adjustment and invoice-cancellation duties have no maker/checker at all. The original lane acceptance was lost, so nobody can currently say what 'persona' bundles or SoD invariants are required, the documented RBAC matrix (`docs/SECURITY.md`) is missing, and narrowing permissions in an additive seeder would not reach existing databases.

## Solution

Define named **capability bundles** (groups of existing `{module}.{action}` permissions) and compose each seeded role persona from non-conflicting bundles, so that no non-admin persona holds both the *execute* and the *approve/reverse/cancel* side of the same high-risk flow; add the missing same-actor guards (stock adjustment approver, invoice cancellation, inventory request approver≠preparer/issuer) with durable actor evidence; make the seeder idempotently converge existing roles to the bundle definition; and publish the matrix as the single documented source that tests assert against. Admin stays break-glass with a documented, audited override; no new modules, no UI redesign.

## User Stories

1. As an owner, I want each role defined as a set of named capability bundles, so I can reason about who can do what without reading ~190 permission names.
2. As a field technician, I can start/submit assigned SPK, upload evidence, declare returns, request materials and resolve my tickets, but never approve/reject any SPK, receive my own return, or adjust stock.
3. As a technician, I cannot approve or reject an SPK I created or am assigned to (existing guard stays green).
4. As a dispatcher/staff, I can create and assign SPK, take customer complaints and record payments, but not approve SPK or reverse/cancel financial documents.
5. As an inventory operator, I can receive, issue, transfer stock and prepare/issue approved requests, but cannot approve my own preparation, adjust stock, or approve inventory requests.
6. As an inventory controller (manager persona), I can approve/reject inventory requests and approve stock adjustments, but cannot also prepare/issue the same request or perform the receive/issue that I am approving.
7. As a billing operator, I can draft, send and record payment on invoices, but cannot reverse a payment or cancel an invoice.
8. As a billing controller (manager persona), I can reverse payments and cancel invoices, but not for payments I recorded or invoices I created/sent.
9. As an SPK reviewer, I can approve/reject only SPK I neither created nor am assigned to, and the approver/rejector, timestamp and reason persist.
10. As a requester of a stock adjustment, I propose old→new quantity with a reason; a different authorised user approves; the movement records requester, approver, reason and before/after quantity.
11. As an auditor, every SoD-relevant action (approve, reject, adjust, reverse, cancel, role/permission change) has an actor and timestamp I can read in the existing audit log.
12. As an admin using break-glass, I can still perform a blocked action, and it is recorded with an explicit break-glass marker and reason (if D3 option A/B chosen).
13. As an admin, I cannot accidentally lock the company out of its only approver: seeded roles and demo users still log in and reach `/admin/dashboard`.
14. As a security reviewer, I can open one document (the RBAC matrix) and see roles × bundles × SoD conflicts that exactly match what the seeder produces.
15. As a deployer upgrading an existing database, I run the seeder/migration once and existing `manager`/`staff` roles are narrowed to the new bundles without wiping admin-created custom roles.
16. As a user without a capability, I receive a backend 403 (or the established 422 for same-actor) even if I forge the request; hidden buttons are never the only control.
17. As a manager of company A, I cannot approve, reverse or cancel anything belonging to company B (404, existing behaviour preserved).
18. As someone with `roles.manage`/`users.manage`, I cannot grant myself or others an SoD-conflicting combination on the seeded roles without it being rejected or audited (per D5).

## Implementation Decisions

- **Capability bundles** live as named permission groups in the Core seeder/domain (single source), e.g. `field_execution`, `dispatch_intake`, `inventory_operator`, `inventory_controller`, `billing_operator`, `billing_controller`, `work_review`, `ticket_desk`, `reporting_reader`, `access_admin`. Roles are unions of bundles. Permission names stay `{module}.{action}`; add new permissions only where required (e.g. stock-adjustment request/approve: `inventory.stock.adjust.request`, `inventory.stock.adjust.approve`; keep `inventory.stock.adjust` only if option chosen) — each must be `Permission::firstOrCreate` and granted to admin.
- **SoD conflict set** (declared once, used by seeder test and docs): {spk.approve|reject} ⊥ {spk.create|assign|start|submit} for non-admin personas; {inventory.request.approve|reject} ⊥ {inventory.request.prepare|issue, inventory.stock.receive|issue|transfer}; {billing.payment.reverse|billing.cancel} ⊥ {billing.create|send|payment.record}; {inventory.stock.adjust.approve} ⊥ {inventory.stock.adjust.request, stock receive/issue/transfer}.
- **Same-actor guards** (service layer, inside the locked transaction, mirrored in Policy for Approval Inbox/Inertia `can`): invoice cancel — canceller ≠ invoice creator/sender (and ≠ any recorder of a now-reversed payment, per D4); inventory request — approver ≠ preparer ≠ issuer; stock adjustment — approver ≠ requester. Existing guards (SPK creator/assignee, payment recorder, requester ≠ approver/issuer, return receiver/resolver) must keep their current status codes (422 vs 403 as today).
- **Durable actor evidence**: additive, nullable columns only — stock adjustment requester/approver/approved_at/reason (either a small `stock_adjustment_requests` table or columns on movement, per D2); invoice `cancelled_by` exists already (reuse); payment `cancelled_by` exists; SPK approver columns exist; no backfill unless audit data is reliable, otherwise null = legacy-unknown; non-destructive down().
- **Seeder convergence**: seeder must be idempotent AND able to remove permissions from seeded roles (sync for `manager`/`staff`/`technician`/`customer`; admin = all). Because Roles UI lets admins edit seeded roles, decide D6 on overwrite vs additive.
- **Role management hardening**: block non-admin from assigning `admin` (and from editing `admin`/protected roles' permissions); reject saving a role whose permission set violates the SoD conflict set unless the actor is admin (D5).
- **Break-glass**: admin retains all permissions; same-actor guards still apply to admin for creator≠approver, but an explicit, audited override path is optional (D3). Any override writes an audit entry with reason.
- **Inertia props / UI**: existing `can`/permission props drive Stock adjust button, Invoice cancel/reverse, Approval Inbox. Backend decides first; UI changes are limited to showing the adjust-request/approve states and maker-checker hints already used by ReviewSheet (only if D7 includes UI).
- **Company scope & guard**: all new queries Eloquent, `BelongsToCompany`, tenant check before authorization side effects; no raw SQL; permissions are global so seeder changes affect all tenants (single-company v1).
- **Docs**: create `docs/SECURITY.md` §2 RBAC matrix (referenced by AGENTS.md/TASKS.md) generated from/verified against the bundle definition; update docs/business role tables; CHANGELOG entry.

## Testing Decisions

- **Seams**: (a) bundle definition ⇄ seeded Spatie roles (unit, `RolePermissionSeederTest` style, `RefreshDatabase`, MySQL `inbils_test`); (b) service-layer same-actor guards (`BillingService`, `StockService`/new adjustment service, `InventoryRequestService`); (c) HTTP 403/422/404 matrix with real seeded roles (feature); (d) sidebar/menu & Approval Inbox visibility (feature + e2e).
- **Prior art to extend**: `tests/Unit/RolePermissionSeederTest.php`; `tests/Feature/AdminMenuAuthorizationTest.php`; `tests/Feature/AdminPermissionRoleTest.php`; `tests/Feature/SPK/SpkSelfApprovalGuardTest.php`; `tests/Feature/Admin/ApprovalInboxTest.php` + `ApprovalInboxInventoryTest.php`; `tests/Feature/Billing/PaymentReversalTest.php`; `tests/Feature/Inventory/StockRbacAuditTest.php`, `StockPermissionTest.php`, `InventoryRequestWorkflowTest.php`, `MaterialReturnCapabilityTest.php`; `tests/Feature/TenantBoundaryTest.php`; `tests/e2e/auth-roles.spec.ts` (+ `fixtures.ts`, `demo.ts`); `tests/Traits/CreatesCompanyUser.php`.
- **New/changed tests required**: seeded-role conflict test (for every non-admin role, no pair from the conflict set); admin has all; upgrade test (pre-existing manager with old permissions converges after seeder run; custom role untouched per D6); denied-path 403 per bundle boundary using real role fixtures (not ad-hoc `givePermissionTo`); same-actor tests for invoice cancel, stock adjust approval, request approver≠preparer≠issuer; actor/timestamp/reason persistence; cross-company 404; Approval Inbox parity (list == summary == badge) after changes; role/user assignment escalation tests; update fixtures that rely on manager = universal operator (list in Gaps #10).
- **E2E**: update `auth-roles.spec.ts` roleExpectations to new visibility; keep demo users working (login redirect to dashboard still gated by `dashboard.view`); serialized on `inbils_test`, DB identity gate first.
- **Chain gating**: tester runs focused feature suites + full relevant modules + Pint/Larastan on changed paths; QA-ISP independently verifies tenant/RBAC/data integrity and the matrix doc; reviewer final approves against this contract only (not against lost original). Follow existing DB identity gate and single-writer rules in ledger header.

## Out of Scope

- Head Company/multi-tenant super-admin and per-tenant role customisation (ledger: single-company v1 stays).
- Persona **dashboards**, exception queues and counts (separate P2 'persona dashboard' lane: t_0f437faa, t_f1c27ea9, t_336a4dc4, t_09021a86, t_40dc90eb, t_a5536496).
- Sidebar IA redesign (separate lane), Dispatch/My Work, Notification Center, Technician Performance (done).
- Customer self-service portal rights beyond today's `customer` persona; NOC persona implementation unless D1 includes it.
- Org-hierarchy-scoped access (manager sees only descendant unit) beyond what Technician Performance already enforces.
- Payment gateway, recurring billing, reconciliation redesign; SPK state machine changes; API v1 routes.
- Backfilling historical actors; rewriting stock/financial history.
- Raw SQL, new UI component libraries, permission-cache tuning.

## Dependencies / ordering vs other lanes

- Ledger sequence: Technician Performance (done) → Phase Gate → Location Map (done) → recovery lanes; this lane's cards `t_594beec6` has no predecessor; chain strictly backend → tester → QA → reviewer.
- Execution plan says P0-B follows P0-A (tenant-safe write references): `tests/Feature/TenantIsolation/TenantSafeWriteReferenceTest.php` and `tests/Feature/Tenant/TenantSafeWriteReferencesTest.php` exist (INFERENCE: P0-A largely landed; verify before dispatch).
- Must land **before** P2 persona dashboard lane (its read models/exception counts depend on persona/capability definitions), and before/with Sidebar IA (menu visibility derives from bundles; coordinate to avoid reworking `AdminLayout` gating twice).
- Interacts with Dispatch/My Work and Approval Inbox (already done): permission changes must keep `canAny([...approve/reject])` flows and `ApprovalInboxQuery` capability checks working.
- Independent of Head Company, Reporting V2, Sales/KPI, Loading/progress, P2 export (but export permissions `*.export` are not rebundled here).
- Runtime prerequisites from the Hermes freeze: verified Linux project, PHP/Composer, MySQL `inbils_test` identity gate, serialized DB runs, single-writer check on the dirty shared worktree.

## Approved decisions

Owner approval 2026-10-10: the recommended option (A) of every decision D1-D8. Backend card `t_594beec6` applied them as below; the tester, QA and reviewer cards inherit them.

1. **D1: A.** The five seeded roles stay; manager is the controller persona and staff the operator persona. No `noc`, no extra role. Seeder bundles: manager = approve/reject inventory requests, approve stock adjustments, approve/reject SPK, reverse/cancel billing, no stock receive/issue/transfer and no request prepare/issue; staff = stock receive/issue/transfer, adjust request, request prepare/issue, no approvals. Staff therefore loses the Approval inbox and the dashboard `approvals` card, and cannot cancel other people's submitted requests (cancel needs `inventory.request.approve`).
2. **D2: A.** Two-step stock adjustment. `stock_adjustment_requests` (migration `2026_10_10_172410`) holds requester, quantity before/after, delta, reason, status, approver, `approved_at`, rejection reason. `StockService::requestAdjustment|approveAdjustment|rejectAdjustment`; new permissions `inventory.stock.adjust.request` (admin, staff) and `inventory.stock.adjust.approve` (admin, manager). Approval re-reads the request under a row lock, re-checks pending and requester != approver, then applies the requested quantity (the movement delta is computed against the stock at approval time, `created_by` = approver, `note` = reason). Audit events `stock_adjustment_requested`, `stock_adjustment` (with `adjustment_request_id`, `requested_by`, `approved_by`) and `stock_adjustment_rejected`. A rejection stores the reviewer in `approved_by`/`approved_at` (shortcut, commented in code).
3. **D3: A.** Admin keeps every permission and every same-actor guard still binds admin; no override reason is added. The single override is the admin-only direct adjustment `inventory.stock.adjust` at `POST /admin/stocks/adjust/direct`, audited with `break_glass: true` (the "tightly limited admin path" of the execution plan), so a one-admin tenant is not blocked on stock.
4. **D4: A.** `billing.cancel` and `billing.payment.reverse` are held by admin and manager only; `BillingService::cancel` refuses the invoice creator (`created_by`, 422; batch-created invoices with a null creator stay cancellable); the payment reversal guard is unchanged.
5. **D5: A.** `StoreUserRequest`/`UpdateUserRequest` reject the `admin` role unless the actor is admin; `RolePolicy::update|edit` limit the five seeded roles to admin; `NoSodConflict` on `StoreRoleRequest`/`UpdateRoleRequest` rejects a non-admin role save that combines both sides of `App\Support\SodConflicts::SETS` or grants `inventory.stock.adjust`. Admin is exempt.
6. **D6: A.** `RolePermissionSeeder` runs `syncPermissions` on the five seeded roles and never touches custom roles; it overwrites manual edits to seeded roles. Deploy: `php artisan db:seed --class=RolePermissionSeeder --force`.
7. **D7: A, with one orchestrator-requested addition.** No new UI card, route family or page. To make D2 usable the backend card adds the minimum to the existing Stocks page: an "Ajukan Penyesuaian" modal, and a pending-requests panel with Setujui / Tolak for reviewers (every pending request) and requesters (their own). The direct break-glass endpoint has no UI.
8. **D8: A.** `docs/SECURITY.md` §2 is the matrix (bundles, SoD conflict set, grants by role, stock adjustment workflow, deploy note). Stale role, permission, route and scenario statements in `docs/business/inventory.md` are replaced; the `noc` rows in the other `docs/business` files are untouched. `docs/SECURITY.md` is ignored by `.gitignore` (`docs/*`); it needs `!docs/SECURITY.md` to be tracked.

Backend choices inside the contract (confirm at QA):

- **Permission-level conflict set is partial.** The spec text also lists `{spk.approve|reject} ⊥ {spk.create|assign|start|submit}` and `{billing.payment.reverse|billing.cancel} ⊥ {billing.create|send|payment.record}`. The seeded manager still holds `spk.create|assign` and `billing.create|send|payment.record`, because splitting them would remove dispatch and collection from the controller persona and break Dispatch, PaymentReversal and the demo/e2e flows. Those duties rely on the same-actor service guards (SPK creator/assignee, payment recorder, invoice creator). `SodConflicts::SETS` holds the two inventory sets, which the seeded roles satisfy. Owner or QA decides whether to split SPK and billing too.
- **Roles stack, user rules.** `NoStackedSodConflict` on `StoreUserRequest`/`UpdateUserRequest` merges the permissions of the submitted roles and refuses a non-admin actor any set that breaks `SodConflicts` (so `manager` + `staff` on one user); admin is exempt. `UserPolicy` denies edit, update and delete of an admin user to a non-admin. `requested_by` and `approved_by` on `stock_adjustment_requests` are `restrictOnDelete`, and `UserDeletionGuard` (user delete and profile self-delete) refuses a user referenced by an adjustment request. Direct per-user permissions are not checked.
- **Notifications.** A submitted inventory request notifies the users with `inventory.request.approve` (manager, admin) only; warehouse operators find approved requests in the warehouse queue and its badge. Nothing new notifies them on approval.
- **Adjustment approvals live on the Stocks page**, not in the Approval Inbox.
- **`report.export`** (admin, manager) was added at the request of the `p2-export-drilldown` lane; it is not part of D1-D8.

## Closure

Done 2026-10-10: QA + reviewer PASS/APPROVED after fixes. Evidence in ledger "Role persona/SoD & P2 export closures (2026-10-10)".
