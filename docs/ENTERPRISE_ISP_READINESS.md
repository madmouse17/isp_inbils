# Enterprise ISP Implementation Readiness

Status: RECOVERED AUDIT — current working tree, 2026-07-15

Target: single-company ISP operations pilot/production baseline, medium scale

Excluded: full telco OSS/BSS, multi-company activation, customer portal/mobile, Radius/NAS automation, multi-vendor NMS, payment gateway, public partner API

## 1. Executive verdict

**Verdict: DEMO-READY, not pilot-ready. Weighted readiness: 55/100.**

Application has broad ISP CRUD and useful transactional slices: company-scoped reads, RBAC permissions, customer/subscription workflow, SPK orchestration, stock row locking and DB constraints, payment reconciliation, tickets, network assets, reports, and deterministic Chromium harness. It cannot be promoted to production pilot while tenant-unsafe write references, wrong-asset auto-selection, broad SoD roles, exposed scaffold API routes, and missing production recovery/deployment controls remain.

Mandatory closure:

- **P0 application safety:** tenant-scope every write reference; make SPK asset selection explicit; enforce action-level separation for SPK approval, stock adjustment, invoice cancellation, and payment recording; disable scaffold APIs.
- **P0 operations:** production deploy/rollback runbook; supervised queue worker and scheduler; encrypted off-host backup plus successful restore drill with stated RPO/RTO.
- **P1 confidence/operations:** metrics, alerts, centralized exception reporting; complete lifecycle E2E for SPK approval side effects, ticket resolve/close, recurring billing/reconciliation, API non-exposure/contract.

## 2. Method and evidence boundary

Verdicts:

- `IMPLEMENTED`: code, authorization, integrity, user/API path, and tests support intended behavior.
- `PARTIAL`: useful slice exists, but required boundary, lifecycle, or operations evidence is incomplete.
- `DOC-ONLY`: requirement exists in active documentation without credible implementation.
- `MISSING`: neither sufficient design nor implementation exists.
- `CONTRADICTED`: exposed behavior conflicts with intended contract or architecture.

Evidence order: current non-legacy architecture/business docs; current working tree; route/module/migration inventory; current tests and latest QA report. Legacy docs provide history only.

Reproducibility warning: initial audit recorded **185 dirty paths: 164 modified and 21 untracked**. G0 inventory on 2026-07-15 found **165 modified plus 21 top-level untracked entries**; recursive inventory expands those entries to **201 untracked files**. Critical migrations, tests, Playwright setup, and orchestration action remain untracked. Findings describe this workspace, not reproducible `origin/main` state.

G0 topology evidence:

- `phpunit.xml` now has deterministic `Unit`, `Feature`, and `Modules` suites; the `Modules` suite recursively discovers `Modules/**/tests/**/*Test.php`.
- `tests/Unit/PhpUnitTopologyTest.php` parses that configuration and asserts root Unit/Feature plus Modules discovery. It passed with 4 assertions on 2026-07-15.
- `vendor/bin/phpunit --list-test-files` listed `Modules/Inventory/tests/Feature/StockServiceTest.php`; `vendor/bin/phpunit --testsuite Modules --list-tests` listed its six tests.
- `.gitignore` intentionally re-includes this readiness report and its execution plan below the existing `docs/*` rule, so G0 evidence can be reviewed rather than silently ignored.
- No migration, schema reset, or DB-heavy module test ran: `phpunit.xml` targets shared `inbils_testing` MySQL, whose ownership/isolation was not proven. G0 must not destructively alter a shared DB.

Source-of-truth defect: `AGENTS.md:6-23` still directs workers to missing old paths such as `docs/VISION.md`, `docs/ARCHITECTURE.md`, `docs/API.md`, `docs/modules/*.md`, while current files live under `docs/architecture/*` and `docs/business/*`. Changelog also describes older path truth (`CHANGELOG.md:72-105`). Treat current non-legacy files and working code as audit truth; repair root instructions before next broad implementation phase.

Runtime evidence available from latest QA closure, not rerun by this document recovery:

- Policy suite 10/10, Vite environment tests 2/2, full Chromium 12/12 (`QA_REPORT.md:23-26`, `QA_REPORT.md:64-77`).
- `npm run format:check` had one unrelated dirty-tree failure (`QA_REPORT.md:78`).
- Current module inventory: eight enabled modules. Current route inventory exposes 40 `/api/v1/*` routes.

## 3. Weighted readiness score

| Area | Weight | Score | Verdict | Reason |
|---|---:|---:|---|---|
| Tenant boundary, RBAC, audit | 14 | 8 | PARTIAL | Read scope and policies exist; many write references use unscoped `exists`; SoD incomplete. |
| Customer and service lifecycle | 8 | 6 | PARTIAL | Customer/subscription creation and status transitions exist; write references and termination side effects incomplete. |
| SPK/provisioning orchestration | 9 | 6 | PARTIAL | Review workflow and transactional completion exist; asset selection unsafe and lifecycle browser proof incomplete. |
| Inventory integrity | 9 | 7 | PARTIAL | Locks, ledger, checks, idempotency exist; adjustment SoD and tenant reference validation incomplete. |
| Billing/reconciliation | 9 | 7 | PARTIAL | Locked payment reconciliation and per-company reference uniqueness exist; SoD, reversal workflow, recurring failure operations incomplete. |
| Ticket/SLA | 7 | 4 | PARTIAL | Core transitions and ticket-to-SPK exist; escalation, notification, and full lifecycle proof missing. |
| Network asset/topology | 6 | 4 | PARTIAL | Asset lifecycle/history exists; exact assignment and concurrency invariant need closure. |
| Approval/SoD | 5 | 2 | PARTIAL | SPK approval exists; high-risk duties remain combined and durable approver fields are incomplete. |
| Queues/notifications/idempotency | 6 | 1 | MISSING | Scheduler entries exist; no application jobs/notifications or production supervision. |
| Reporting | 5 | 3 | PARTIAL | Six live query classes exist; unit report/export and metric correctness coverage incomplete. |
| API/integration contracts | 5 | 0 | CONTRADICTED | Authenticated routes expose HTML scaffold/empty writes, not JSON contracts. |
| Deploy/backup/restore/observability | 9 | 1 | MISSING | Framework `/up` exists; deploy, backup restore, supervision, metrics, alerts absent. |
| Deterministic PHP/browser E2E | 8 | 6 | PARTIAL | Fail-closed deterministic harness passes; required domain closure paths remain partial. |
| **Total** | **100** | **55** | **DEMO-READY** | P0 safety and operations block pilot. |

Operational labels:

- 0–39 prototype
- 40–59 demo-ready
- 60–74 pilot candidate only after every P0 passes
- 75–89 production-ready for bounded target after P0/P1 gates and operational drill
- 90–100 enterprise-ready for bounded target with sustained operational evidence

Score is not release gate. Any open P0 blocks pilot regardless of total.

## 4. Capability matrix

### 4.1 Tenant write boundary, RBAC, SoD, audit — PARTIAL, P0

Implemented evidence:

- `BelongsToCompany` scopes reads and auto-fills company on create (`app/Traits/BelongsToCompany.php:13-29`); explicit current-company scope fails without context (`app/Traits/BelongsToCompany.php:42-55`).
- Policies are registered across Core and modules (`app/Providers/AppServiceProvider.php:74-97`).
- High-risk endpoints call action permissions: stock adjustment (`Modules/Inventory/app/Http/Controllers/StockController.php:107-110`), payment (`Modules/Billing/app/Http/Controllers/InvoiceController.php:179-184`), ticket resolve/close (`Modules/Ticketing/app/Http/Controllers/TicketController.php:168-183`), SPK approval (`Modules/SPK/app/Http/Controllers/WorkOrderController.php:172-176`).
- Domain services emit activity events, e.g. subscription transitions (`app/Services/Core/SubscriptionService.php:23-27`, `44-46`, `59-62`, `100-103`).
- Cross-company read denial tests cover customer, inventory, invoice, SPK, and ticket (`tests/Feature/TenantBoundaryTest.php:44-115`).

Blocking gaps:

1. Many write FormRequests use global unscoped `exists`, including subscription customer/package/address/location (`app/Http/Requests/Admin/StoreSubscriptionRequest.php:23-33`), ticket category/customer/subscription/asset/location (`Modules/Ticketing/app/Http/Requests/StoreTicketRequest.php:17-27`), inventory product/location (`Modules/Inventory/app/Http/Requests/StockAdjustRequest.php:15-22`), SPK customer/subscription/location (`Modules/SPK/app/Http/Requests/StoreWorkOrderRequest.php:19-24`), and billing customer/subscription (`Modules/Billing/app/Http/Requests/StoreInvoiceRequest.php:17-20`). Laravel validation queries do not inherit Eloquent tenant scopes. Cross-company foreign IDs may pass trust-boundary validation.
2. Subscription post-validation checks package company and address ownership, but base `exists` remain unscoped and customer, address company, serving POP, and complete parent-child consistency are not all proven (`app/Http/Requests/Admin/StoreSubscriptionRequest.php:47-63`).
3. Manager role combines stock adjustment, SPK approval, payment recording, and invoice cancellation (`database/seeders/RolePermissionSeeder.php:146-176`). Single-company baseline still needs maker/checker separation for money, stock, and work completion.
4. SPK records creator and assignment actors but no durable approver field in schema (`Modules/SPK/database/migrations/2026_07_01_120000_create_work_orders_table.php:29-32`). Audit log alone is useful but weaker than explicit approval evidence.

Required destination: company-aware validation rules or scoped `Rule::exists` in every write FormRequest; matching service assertions inside transactions; `RolePermissionSeeder` least-privilege split; actor/timestamp fields where durable approval/reversal evidence is required; denied-path and cross-company write tests.

### 4.2 Customer and service lifecycle — PARTIAL, P0/P1

Implemented evidence:

- Subscription creation snapshots package price and uses a transaction (`app/Services/Core/SubscriptionService.php:12-30`).
- Pending/active/suspended/terminated transitions and audit events exist (`app/Services/Core/SubscriptionService.php:33-107`).
- Code generation is company-scoped and locked (`app/Services/Core/SubscriptionService.php:109-123`).
- Browser journey creates customer, installation address, and pending subscription (`tests/e2e/customer-subscription.spec.ts:16-99`).

Gaps:

- P0 tenant-safe reference closure described above.
- P1 termination has deferred ONT release (`app/Services/Core/SubscriptionService.php:86-98`); operational deprovisioning remains manual.
- P1 browser proof stops at pending subscription, not activation, suspension, reactivation, termination, or resulting billing/asset effects (`tests/e2e/customer-subscription.spec.ts:88-99`).

### 4.3 SPK/provisioning — PARTIAL, P0

Implemented evidence:

- SPK workflow supports generate, assign, start, submit, approve, reject, cancel in transactions (`Modules/SPK/app/Services/SpkService.php:30-157`).
- Completion locks work order and orchestrates stock, asset, subscription, and invoice in one transaction (`Modules/SPK/app/Actions/CompleteSpkAction.php:18-39`).
- Focused test covers stock consumption, asset installation, subscription activation, and invoice creation (`tests/Feature/SPK/SpkCompletionOrchestrationTest.php:27-99`); cancellation releases reservation (`tests/Feature/SPK/SpkCompletionOrchestrationTest.php:102-137`).

Blocking gap:

- Installation chooses first available asset matching any work-order product (`Modules/SPK/app/Actions/CompleteSpkAction.php:109-135`). Code itself notes missing exact asset reference (`:119-120`). This can install wrong serialized ONT/device. P0 requires explicit selected `network_asset_id`, same-company validation, product compatibility, row lock, available-state guard, and conflict test.

P1 evidence gap: browser SPK test reaches `in_progress`, not evidence upload, submit, approve, and side-effect confirmation (`tests/e2e/kanban-t_49387795.spec.ts:27-59`).

### 4.4 Inventory — PARTIAL, P0/P1

Implemented evidence:

- Receive/issue use transactions, locked stock, immutable movement rows, positive quantity guards, and reference dedup (`Modules/Inventory/app/Services/StockService.php:15-92`).
- Transfer locks both rows and prevents negative available stock (`Modules/Inventory/app/Services/StockService.php:95-142`).
- DB checks enforce non-negative quantity/reservation and reservation not above quantity; unique reference index enforces idempotency (`Modules/Inventory/database/migrations/2026_07_09_000001_harden_stock_integrity_constraints.php:8-21`).
- Browser evidence covers receive and movement display (`tests/e2e/stock-asset.spec.ts:16-46`).

Gaps:

- P0 tenant-scoped product/location validation and service assertions.
- P0 stock adjustment is directly executable by manager/admin role; baseline needs maker/checker or tightly separate inventory controller role (`database/seeders/RolePermissionSeeder.php:165-176`).
- P1 transfer, issue, adjustment, reservation conflict, and rollback browser/feature coverage remains incomplete.

### 4.5 Billing and reconciliation — PARTIAL, P0/P1

Implemented evidence:

- Payment verifies company, locks fresh invoice, rejects non-positive/overpayment/duplicate reference, and updates status atomically (`Modules/Billing/app/Services/BillingService.php:240-283`).
- DB enforces per-company active payment reference uniqueness (`Modules/Billing/database/migrations/2026_07_01_130002_create_payments_table.php:11-29`).
- Tests cover partial/full payment, invalid amounts, duplicate reference, stale-balance locking, and cross-company denial (`tests/Feature/Billing/PaymentReconciliationTest.php:20-158`).
- Browser journey covers invoice line, send, record payment, paid balance (`tests/e2e/kanban-t_49387795.spec.ts:61-110`).
- Scheduler registers recurring generation and overdue check (`routes/console.php:11-12`).

Gaps:

- P0 maker/checker separation: manager can create/send/record/cancel invoice (`database/seeders/RolePermissionSeeder.php:173-176`).
- P1 durable payment reversal/cancellation actor and approval path; payment schema stores receiver but no cancellation actor (`Modules/Billing/database/migrations/2026_07_01_130002_create_payments_table.php:19-24`).
- P1 recurring generation retry/idempotency/failure alert and supervised scheduler evidence absent.

### 4.6 Ticket/SLA — PARTIAL, P1

Implemented evidence:

- Assign/start/resolve/close and ticket-to-SPK transitions are transactional and audited (`Modules/Ticketing/app/Services/TicketService.php:31-118`).
- Browser journey covers create, assign, start, comment, and SPK spawn (`tests/e2e/kanban-t_49387795.spec.ts:112-168`).

Gaps:

- Browser journey omits resolve, SLA outcome, close, and denied actor paths.
- No application Job or Notification classes were found. SLA breach alert/escalation and assignment notifications are absent.
- No reopen/escalation operational flow. Keep reopen P2 unless pilot policy requires it; breach notification is P1.

### 4.7 Network asset/topology — PARTIAL, P0/P1

Implemented evidence:

- Asset service records audited install/remove/maintenance/repair/retire transitions (`Modules/NetworkAsset/app/Services/NetworkAssetService.php:41-163`).
- Browser evidence opens asset and linked customer/subscription (`tests/e2e/stock-asset.spec.ts:48-69`).

Gaps:

- P0 explicit serialized asset selection and lock in SPK completion.
- P1 DB/app proof of one active installation under concurrency and complete move/remove lifecycle tests.
- NMS polling, topology discovery, and hardware telemetry remain external P2/P3.

### 4.8 Reporting — PARTIAL, P1

Implemented evidence:

- Six query classes exist: business, technician, asset, SLA, stock card, audit log.
- Controller gates report access and serves live query results (`Modules/Reporting/app/Http/Controllers/ReportController.php:20-93`).
- Business metrics aggregate subscriptions, invoices, assets, tickets, and SPK (`Modules/Reporting/app/Queries/BusinessMetricsQuery.php:14-70`).

Gaps:

- Current business spec calls for seven reports including unit performance and exports (`docs/business/reporting.md:31-110`, `164-191`); no `UnitPerformanceQuery` found and export contract is absent.
- Metric formula/date-boundary tests are insufficient for decision-grade reporting. Reporting can remain P1 after transactional P0 closure.

### 4.9 API/integrations — CONTRADICTED, P0

- Every module provider maps `/api` routes (`Modules/Billing/app/Providers/RouteServiceProvider.php:46-49` representative).
- Eight module route files register authenticated `apiResource`; Billing example: `Modules/Billing/routes/api.php:6-8`.
- Route inventory exposes 40 `/api/v1/*` endpoints.
- Scaffold controllers return HTML module views and contain empty writes; Customer example `Modules/Customer/app/Http/Controllers/CustomerController.php:13-55`, Billing example `Modules/Billing/app/Http/Controllers/BillingController.php:13-55`, Reporting example `Modules/Reporting/app/Http/Controllers/ReportingController.php:13-55`.

`auth:sanctum` provides authentication only. It does not make these JSON contracts. No API Resources/FormRequests/policies/versioned error schema/OpenAPI/idempotency/contract tests support exposed routes.

**P0 decision:** disable module API route mapping for pilot. Build real public/partner API later only after approved consumer contract. Do not repair eight speculative CRUD APIs now.

### 4.10 Queues, notifications, resilience — MISSING/PARTIAL, P0/P1

- Two billing schedules exist (`routes/console.php:11-12`).
- Development Composer command runs `queue:listen`, not production supervision (`composer.json:55-58`).
- No application `*Job.php` or `*Notification.php` files found.
- No retry/backoff/dead-letter/failed-job operational policy found.

P0: supervisor/systemd/container process definitions for `queue:work` and `schedule:run`, restart/timeout/tries policy, failed-job handling, deploy restart steps. P1: domain notifications and failure alerts with idempotent queued handlers.

### 4.11 Deploy, backup, restore, observability — MISSING, P0/P1

- Framework liveness route exists at `/up` (`bootstrap/app.php:8-16`). This proves process liveness only.
- No tracked CI/deployment manifest, production runbook, rollback procedure, queue/scheduler supervisor, backup configuration, restore drill, RPO/RTO, metrics, alert rules, or centralized exception reporting found.
- Backup/health packages are mentioned only in old planning history; they are absent from current Composer dependencies (`composer.json:8-32`).

P0: deployment/runbook, migration safety and rollback, off-host encrypted backup, retention, restore script/runbook, successful restore drill, ownership and RPO/RTO. P1: readiness checks for DB/cache/queue, metrics/alerts, centralized error reporting, log retention/redaction.

### 4.12 Deterministic E2E — PARTIAL, P1

Implemented evidence:

- Playwright is serialized with one worker and retained failure artifacts (`playwright.config.ts:5-18`).
- E2E scripts include destructive guard matrix and isolated runner (`package.json:13-14`).
- Latest QA records full Chromium 12/12 and fail-closed network evidence (`QA_REPORT.md:18-26`, `64-77`).
- Current untracked E2E additionally includes SPK, payment, and ticket journeys (`tests/e2e/kanban-t_49387795.spec.ts:22-168`), reconciling parent audit’s earlier “missing billing/SPK/ticket E2E” finding. Coverage now exists, but remains partial because critical completion/approval/resolve/close and failure paths are absent.

Mandatory P1 additions: SPK evidence-submit-approve with stock/asset/subscription/invoice assertions; ticket resolve/SLA/close; recurring invoice generation and duplicate-period prevention; API route non-exposure; non-admin denied high-risk actions.

## 5. Ranked risks

| ID | Priority | Risk | Consequence |
|---|---|---|---|
| R1 | P0 | Unscoped write references | Cross-company foreign-key injection and data contamination. |
| R2 | P0 | First-available asset auto-install | Wrong serialized customer equipment assigned during SPK completion. |
| R3 | P0 | Exposed scaffold APIs | Authenticated callers receive HTML/empty writes from routes presented as CRUD contracts. |
| R4 | P0 | No restore-tested backup | Data loss cannot be recovered within known RPO/RTO. |
| R5 | P0 | No deploy/rollback or worker/scheduler supervision | Recurring billing and queued work silently stop or unsafe migration blocks recovery. |
| R6 | P0 | Broad manager high-risk permissions | Same actor can adjust stock, approve work, and create/record/cancel money movements. |
| R7 | P1 | No metrics/alerts/central exception reporting | Failures remain invisible until user complaint or accounting discrepancy. |
| R8 | P1 | Incomplete critical lifecycle E2E | UI and orchestration regressions can pass component/feature suites. |
| R9 | P1 | No domain notifications/escalation | SLA breach, assignment, overdue, and failed automation lack operational response. |
| R10 | P1 | Dirty/untracked critical artifacts | Passing local state cannot be reproduced from repository history. |

## 6. Mandatory baseline versus optional backlog

### Mandatory P0 before pilot

1. Tenant-safe write references and service-level same-company assertions across Core, Service, Inventory, NetworkAsset, SPK, Billing, Ticketing.
2. Explicit SPK serialized asset selection, compatibility check, row lock, unique active installation invariant.
3. Action-level RBAC plus maker/checker separation and durable actor evidence for stock adjustment, SPK approval, payment/reversal, invoice cancellation.
4. Disable all scaffold `/api/v1/*` module routes.
5. Production deployment/rollback/migration runbook and supervised scheduler/queue workers.
6. Encrypted off-host backup, retention, restore procedure, successful restore drill, stated RPO/RTO.
7. Reconcile critical untracked artifacts into reviewable repository changes before release.

### Mandatory P1 before production-ready label

1. Metrics, alerts, central exception reporting, log retention/redaction.
2. Domain failure handling and notifications for billing schedule, SLA breach, assignment, queue failure.
3. Critical lifecycle E2E and denied-path coverage described in §4.12.
4. Reporting formula tests and missing unit/export decisions.
5. Active source-path documentation repair.

### P2/P3, YAGNI, or external dependency

- Multi-company activation and cross-company administration.
- Customer portal/mobile app.
- Payment gateway and webhook contract.
- Radius/NAS provisioning and suspension automation.
- Multi-vendor NMS, SNMP polling, topology discovery, outage correlation.
- S3/object storage migration unless backup/attachment volume requires it.
- Public/partner API beyond an approved consumer contract.
- Rule-engine auto-assignment, omnichannel messaging, full accounting/general ledger.

These require product decision, credentials, vendor/hardware access, or measured scale. None blocks bounded internal pilot once P0/P1 gates pass.

## 7. Release gate

Current decision: **NO-GO for production pilot.**

Proceed only through dependency-ordered plan in `docs/plans/ENTERPRISE_ISP_EXECUTION_PLAN.md`. After every P0 implementation, run focused tests, full PHP gates, serialized E2E, formal `qa-isp` review, backup restore drill, and deployment rehearsal. Re-score only from current verified workspace evidence.
