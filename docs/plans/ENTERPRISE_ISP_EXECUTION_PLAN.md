# Enterprise ISP Readiness Execution Plan

Status: PROPOSED — implementation graph not dispatched

Input: `docs/ENTERPRISE_ISP_READINESS.md`

Target: safe single-company ISP operations pilot/production baseline

## 1. Delivery rules

1. Preserve current dirty tree. Before implementation, create reviewable isolation or explicit file allowlists. Never reset, checkout, stage, commit, or push unrelated changes.
2. No worker reads or prints `.env` or credentials. Runtime tests use existing isolated testing/E2E configuration and destructive guard.
3. Production code goes to `code-executor`; smoke/runtime verification to `tester`; formal security/business/architecture gate to `qa-isp`; closure synthesis to `reviewer` or `isp`.
4. Shared MySQL and shared worktree make DB-heavy work serialized. No parallel migration/test cards against same database.
5. Every implementation card follows implementation → tester smoke → `qa-isp` formal QA → remediation if needed → closure. `review-required` is closed only after independent diff and gate verification.
6. P0 is mandatory. P1 required before production-ready label. P2/P3 remains unbuilt until explicit product/external dependency approval.
7. Prefer disabling unsafe speculative features over building unused contracts. No new package unless native Laravel/DB/process manager cannot satisfy acceptance.

## 2. Dependency graph

```text
G0 Reproducible baseline
  |
  +--> P0-A Tenant-safe writes
  |       |
  |       +--> P0-B Action RBAC and SoD
  |
  +--> P0-C Explicit SPK asset assignment
  |
  +--> P0-D Disable scaffold APIs
  |
  +--> P0-E Production deploy and process supervision
  |       |
  |       +--> P0-F Backup/restore drill
  |
  +-------------------------------+
                                  v
                         P0-QA serialized full gate
                                  |
                                  +--> remediation loop
                                  |
                                  v
                         P0 closure / pilot rehearsal
                                  |
                 +----------------+----------------+
                 v                v                v
           P1-A Observability  P1-B Notifications  P1-C Critical E2E
                 \                |                /
                  +---------------+---------------+
                                  v
                         P1-QA production gate
                                  |
                                  v
                         final readiness rescore
```

P0-A, P0-C, P0-D, and P0-E may be coded as separate domain slices only when file scopes do not overlap. Their DB-heavy verification remains serialized. P0-B follows P0-A because denied-path tests must use finalized write boundaries. P0-F follows P0-E because restore commands and deployment topology must agree.

## 3. G0 — Reproducible baseline and source map

Priority: P0 prerequisite

Owner: `code-executor`; review: `qa-isp`

Scope:

- Inventory current dirty/untracked files. Separate prior approved critical changes from unrelated UI churn.
- Define implementation allowlist for each slice below. Do not stage or commit unless separately authorized.
- Repair active root source map in a later documentation-only P1 slice; do not mix it into P0 source diffs.
- Confirm current schema can run all existing migrations from fresh testing DB.

Acceptance:

- Critical untracked migrations/actions/tests are explicitly included in review scope.
- `git diff --check` passes.
- Fresh isolated testing migration succeeds.
- Existing focused P0 tests pass before new edits, or failure is documented as baseline blocker.

G0 implementation evidence (2026-07-15):

- `phpunit.xml` now declares root `Unit`/`Feature` suites and recursive `Modules` test discovery. Focused regression `tests/Unit/PhpUnitTopologyTest.php` passed (4 assertions); PHPUnit lists `Modules/Inventory/tests/Feature/StockServiceTest.php` and all six tests in its `Modules` suite.
- The shared tree was inventoried without reset, checkout, staging, or destructive DB commands: 165 modified paths, 21 top-level untracked entries, and 201 recursive untracked files. Critical untracked paths include `Modules/Inventory/database/migrations/2026_07_09_000001_harden_stock_integrity_constraints.php`, `Modules/Inventory/tests/Feature/StockServiceTest.php`, `Modules/SPK/app/Actions/CompleteSpkAction.php`, and Playwright/E2E harness artifacts.
- `.gitignore` explicitly re-includes `docs/ENTERPRISE_ISP_READINESS.md` and this plan after `docs/*`, making audit evidence reviewable.
- Fresh migration and DB-backed module suite remain blocked pending an explicitly owned isolated MySQL target: current PHPUnit configuration names `inbils_testing`, but G0 cannot prove it is not shared. Do not run `migrate:fresh` or DB-heavy tests against it.

Commands:

```bash
rtk git status --short
rtk git diff --check
rtk php artisan migrate:fresh --env=testing --force
rtk php artisan test --compact tests/Feature/P0CriticalPathTest.php tests/Feature/TenantBoundaryTest.php tests/Feature/SPK/SpkCompletionOrchestrationTest.php tests/Feature/Billing/PaymentReconciliationTest.php
```

Data safety: testing DB only. Guard effective DB target before `migrate:fresh`; never run destructive command against development/production DB.

## 4. P0-A — Tenant-safe write references

Priority: mandatory P0

Owner: `code-executor`; smoke: `tester`; formal QA: `qa-isp`

Destination:

- Shared rule/helper only if it removes repeated scoped validation without hiding table/company semantics: `app/Rules/BelongsToCurrentCompany.php` or explicit `Rule::exists(...)->where('company_id', CompanyService::currentId())`.
- Core requests: `app/Http/Requests/Admin/StoreSubscriptionRequest.php`, `UpdateSubscriptionRequest.php`, `StoreCustomerRequest.php`, `UpdateCustomerRequest.php`, employee/evaluation/organization requests with company-owned foreign IDs.
- Service requests: `Modules/Service/Http/Requests/*ServicePackageRequest.php`.
- Inventory requests: product/category/stock receive/issue/transfer/adjust requests.
- Network asset requests: store/update and lifecycle request validation.
- SPK requests/controllers: work order create/update, assign, items/evidence, approval boundary.
- Billing requests: invoice/item/payment/cancel references.
- Ticketing requests: ticket create/update, assign/comment/attachment/spawn-SPK references.
- Services/actions above must assert same-company parent-child consistency inside transaction before write.
- Tests: extend `tests/Feature/TenantBoundaryTest.php`; add focused module request/service tests beside existing suites.

Required rules:

- Every company-owned FK validates target `company_id = CompanyService::currentId()`.
- Child belongs to selected parent: address/customer, subscription/customer, asset/subscription/customer, ticket category/customer/subscription/asset/location, SPK item/product/location.
- Polymorphic `reference_type/reference_id` allowlist and target existence/same-company check occur before movement/evaluation write.
- Route model binding denial stays 404 for foreign company.
- Service layer rechecks trust boundary; FormRequest is not sole protection.

Acceptance tests:

1. Each foreign-company ID returns 422 or 404 and creates no rows/movements.
2. Same-company valid payload succeeds.
3. Mixed same-company objects with wrong parent relation fail.
4. Artisan/queue path without authenticated company must provide explicit company context and cannot silently query all tenants.
5. Existing read-scope and P0 critical-path tests stay green.

Commands:

```bash
rtk php artisan test --compact tests/Feature/TenantBoundaryTest.php tests/Feature/SubscriptionServiceTest.php tests/Feature/P0CriticalPathTest.php
rtk php artisan test --compact Modules/Inventory/tests/Feature/StockServiceTest.php tests/Feature/SPK/SpkCompletionOrchestrationTest.php tests/Feature/Billing/PaymentReconciliationTest.php
rtk php vendor/bin/pint --test app Modules tests
rtk php vendor/bin/phpstan analyse --memory-limit=512M
```

Migration/data safety: expected no migration. If composite FKs are proposed, first audit existing dirty data with read-only queries; use additive nullable/index/constraint sequence, cleanup transaction, then enforce. Never add constraint that can strand production migration without preflight.

## 5. P0-B — Action RBAC, maker/checker, durable actor evidence

Priority: mandatory P0

Parent: P0-A

Owner: `code-executor`; smoke: `tester`; formal QA: `qa-isp`

Destination:

- `database/seeders/RolePermissionSeeder.php`
- Policies/controllers: `Modules/Inventory/app/Http/Controllers/StockController.php`, `Modules/SPK/app/Http/Controllers/WorkOrderController.php`, `Modules/Billing/app/Http/Controllers/InvoiceController.php`, related policies and FormRequests.
- Domain services/actions for server-side same-actor guards.
- Add focused additive migrations only if explicit actor columns are missing: work-order approval/rejection actor/time; stock adjustment request/approval actor; payment reversal actor/time; invoice cancellation actor/time.
- UI action visibility in existing pages only after backend gate is correct.
- Tests: role seed, policy denial, same-actor denial, audit/actor persistence.

Minimum role contract:

- Technician can execute assigned SPK work but cannot approve own SPK.
- Inventory operator can receive/issue/transfer; stock adjustment requires distinct inventory controller/manager approval or tightly limited admin path.
- Billing operator can draft/send or record payment according to assigned duty; payment reversal and invoice cancellation require billing controller/manager, not same maker where material.
- Manager is not universal operator for stock + SPK + billing. Admin break-glass remains auditable and documented.

Acceptance tests:

1. Denied permissions return 403 from backend even if UI is manipulated.
2. Creator/technician cannot approve own SPK.
3. Stock adjustment records requester, approver, reason, before/after quantity.
4. Payment reversal and invoice cancellation record actor, reason, timestamp and maintain reconciliation invariants.
5. Seeded roles match documented matrix and menu visibility.

Commands:

```bash
rtk php artisan test --compact tests/Unit/RolePermissionSeederTest.php tests/Feature/AdminPermissionRoleTest.php tests/Feature/AdminMenuAuthorizationTest.php
rtk php artisan test --compact tests/Feature/SPK tests/Feature/Billing Modules/Inventory/tests
rtk php vendor/bin/pint --test database app Modules tests
rtk php vendor/bin/phpstan analyse --memory-limit=512M
```

Migration/data safety: additive nullable actor fields first; backfill only when reliable audit data exists, otherwise retain null as “legacy unknown”; indexes after backfill; no destructive rewrite of financial/stock history.

## 6. P0-C — Explicit serialized asset assignment in SPK

Priority: mandatory P0

Owner: `code-executor`; smoke: `tester`; formal QA: `qa-isp`

Destination:

- Add explicit `network_asset_id` to `work_order_items` or `work_orders` based on cardinality; preferred `work_order_items.network_asset_id` for item-specific serialized equipment.
- Migration under `Modules/SPK/database/migrations/` with nullable FK, company-aware service guard, and index.
- `Modules/SPK/app/Models/WorkOrderItem.php`, Resource, request/controller, and React SPK create/show workflow.
- Replace first-available query in `Modules/SPK/app/Actions/CompleteSpkAction.php:109-135`.
- `Modules/NetworkAsset/app/Services/NetworkAssetService.php` lock selected asset before installation.
- Tests: `tests/Feature/SPK/SpkCompletionOrchestrationTest.php`; browser journey in critical E2E slice.

Acceptance:

1. Installation SPK cannot complete when serialized asset is required but unselected.
2. Selected asset must be same company, compatible product/type, `available`, and not actively installed.
3. Selected row is locked; concurrent second completion loses cleanly with no stock/subscription/invoice partial side effects.
4. Completion installs exactly selected asset and stores backlink/history.
5. Maintenance SPK without install requirement remains unaffected.

Commands:

```bash
rtk php artisan test --compact tests/Feature/SPK/SpkCompletionOrchestrationTest.php tests/Feature/P0CriticalPathTest.php
rtk php artisan test --compact Modules/Inventory/tests/Feature/StockServiceTest.php tests/Feature/TenantBoundaryTest.php
rtk php vendor/bin/pint --test Modules/SPK Modules/NetworkAsset tests/Feature/SPK
rtk php vendor/bin/phpstan analyse --memory-limit=512M
```

Migration/data safety: nullable additive FK first. Existing open work orders remain valid but installation completion must require selection after deployment. Do not auto-backfill with “first available”; that recreates defect. Rollback drops FK/index/column only if no new production data depends on it.

## 7. P0-D — Disable scaffold API exposure

Priority: mandatory P0

Owner: `code-executor`; smoke: `tester`; formal QA: `qa-isp`

Destination:

- Stop mapping module API files in each `Modules/*/app/Providers/RouteServiceProvider.php`, or remove generated `Route::apiResource` registrations from `Modules/*/routes/api.php`.
- Do not modify working admin web routes/controllers.
- Add one route inventory regression test under `tests/Feature/ApiExposureTest.php`.

Acceptance:

1. `php artisan route:list --path=api --except-vendor` shows no scaffold module CRUD routes.
2. Requests to former `/api/v1/{resource}` return 404, not HTML, redirect, or successful empty write.
3. Web admin routes remain green.
4. No speculative JSON controllers, OpenAPI, token UI, or external integration is built.

Commands:

```bash
rtk php artisan route:list --path=api --except-vendor
rtk php artisan test --compact tests/Feature/ApiExposureTest.php tests/Feature/AdminRouteTest.php tests/Feature/AdminSmokeTest.php
rtk php vendor/bin/pint --test Modules tests/Feature/ApiExposureTest.php
```

Migration/data safety: none. This intentionally removes unsafe exposed contract. Any known consumer would require product decision before execution; current audit found no contract evidence.

## 8. P0-E — Production deployment, rollback, scheduler, queue supervision

Priority: mandatory P0

Owner: `code-executor` for tracked ops artifacts; smoke: `tester`; formal QA: `qa-isp`

Destination:

- `docs/operations/DEPLOYMENT.md`: prerequisites, maintenance strategy, artifact build, config/cache, migrations, health/readiness, rollback, worker restart, ownership.
- `docs/operations/INCIDENT_ROLLBACK.md`: rollback decision and data-migration caveat.
- Small deployment scripts under `scripts/operations/` only where deterministic and safer than prose.
- Supervisor/systemd/container examples under `deploy/` for `php artisan queue:work --sleep=3 --tries=3 --timeout=...` and once-per-minute `php artisan schedule:run` or `schedule:work` according to chosen host.
- Production health endpoint/check must cover process plus DB; queue freshness/readiness may be separate protected check. Keep sensitive detail out of public response.
- CI workflow only if repository deployment platform is known. Do not invent provider credentials.

Acceptance:

1. Clean checkout can install dependencies, build assets, cache config/routes/views where supported, migrate, and start web/worker/scheduler from documented commands.
2. Process manager restarts crashed worker and starts on boot.
3. Scheduler invocation is single-owner and billing schedules appear in `php artisan schedule:list`.
4. Deploy restarts workers after code change.
5. Rollback distinguishes reversible app release from irreversible DB migration; backups precede risky migration.
6. `/up` plus readiness check returns failure when DB unavailable without exposing secrets.

Safe verification commands:

```bash
rtk composer validate --strict
rtk npm run build
rtk php artisan optimize
rtk php artisan route:list --except-vendor
rtk php artisan schedule:list
rtk php artisan test --compact
```

Production-only runbook commands must be labeled and not run in development audit:

```bash
php artisan migrate --force
php artisan queue:restart
php artisan schedule:run
```

Migration/data safety: document preflight, lock/timeout expectations, backup checkpoint, expand-migrate-contract pattern, and explicit rollback limits. No `migrate:fresh`, seed, or destructive cleanup in production scripts.

## 9. P0-F — Encrypted off-host backup and restore drill

Priority: mandatory P0

Parent: P0-E

Owner: `code-executor` for scripts/docs; runtime drill: `tester`; formal QA: `qa-isp`

Destination:

- `docs/operations/BACKUP_RESTORE.md`
- `scripts/operations/backup.*`, `restore.*`, and verification wrapper using platform-native DB tooling; package addition only if chosen deployment platform lacks reliable native mechanism.
- Scheduler/supervisor integration from P0-E.
- Restore evidence template under `docs/operations/RESTORE_DRILL_TEMPLATE.md`.

Required contract:

- Scope: MySQL plus required uploaded/media files; secrets/config managed separately.
- Destination: encrypted off-host storage. Credentials supplied by deployment secret store, never repository.
- Initial target proposal pending owner approval: RPO 24h, RTO 4h, daily backups, 30-day retention, monthly restore drill. Tighten if business requires.
- Backup success/failure emits monitored result; stale backup is alertable.

Acceptance drill:

1. Create backup from non-production fixture or approved production snapshot.
2. Verify checksum and encryption.
3. Restore into isolated empty database/storage target.
4. Run migrations/status and critical read-only consistency checks.
5. Run focused auth/customer/subscription/invoice/stock queries or tests against restored target without mutating production.
6. Record duration, backup timestamp, achieved RPO/RTO, row/file checks, operator, failure notes.
7. Delete drill target after approval; never overwrite production.

Safe verification commands are platform-specific and must accept explicit source/destination arguments. Script must refuse default/blank target and production overwrite. Never embed DB password in process arguments or logs.

Migration/data safety: restore always targets new isolated DB first. Production restore requires explicit incident commander approval, maintenance window, immutable pre-restore snapshot, and rollback point.

## 10. P0 serialized full gate and closure

Priority: mandatory P0 gate

Parents: P0-A through P0-F

Owners: `tester` then `qa-isp`, closure by `reviewer`/`isp`

Order:

1. Fresh testing migration.
2. Focused security/invariant suites.
3. Full PHP suite, Pint, Larastan.
4. Frontend lint/typecheck/build/format check.
5. Playwright policy suite and full serialized E2E.
6. API route non-exposure check.
7. Deployment rehearsal in clean non-production environment.
8. Backup/restore drill.
9. Dirty scope and secrets scan.

Commands:

```bash
rtk php artisan migrate:fresh --env=testing --force
rtk php artisan test --compact
rtk php vendor/bin/pint --test
rtk php vendor/bin/phpstan analyse --memory-limit=512M
rtk npm run lint
rtk npm run typecheck
rtk npm run build
rtk npm run format:check
rtk playwright test --config playwright.policy.config.ts
rtk npm run test:e2e
rtk php artisan route:list --path=api --except-vendor
rtk git diff --check
rtk git status --short
```

Pass condition: zero open P0 findings, all relevant gates pass, restore evidence succeeds, deployment rehearsal succeeds, no unexpected files in diff. Failure creates smallest independent remediation card, then focused recheck and full affected gate. Never make remediation child depend on blocked implementation card.

## 11. P1-A — Metrics, alerts, centralized exception reporting

Priority: mandatory P1 before production-ready label

Parent: P0 closure

Destination:

- Laravel exception reporting integration in `bootstrap/app.php` or dedicated provider; provider choice requires deployment owner credential decision.
- Structured logs with request/correlation ID and redaction.
- Protected readiness endpoint/checks for DB/cache/queue freshness.
- Metrics/alerts for HTTP 5xx, latency, queue failures/depth, scheduler heartbeat, failed/stale backup, billing job failure, disk, DB availability.
- `docs/operations/OBSERVABILITY.md` with severity, owner, notification path, and runbook links.

Acceptance:

- Synthetic exception reaches non-production central reporter with secret fields redacted.
- Each P0 service dependency has actionable alert and owner.
- Queue/scheduler/backup stale condition is detectable.
- Alert test documents receipt and recovery, not only config presence.

External dependency: choose Sentry/Bugsnag/OpenTelemetry/provider and supply credentials outside repo. If decision unavailable, keep card blocked; do not fake integration.

## 12. P1-B — Domain jobs, idempotency, notifications

Priority: mandatory P1

Parent: P0 closure

Destination:

- Billing scheduled commands/services: add explicit job boundaries only when async retry is useful; retain unique invoice period constraint/query idempotency.
- Ticketing SLA breach and assignment notification jobs/listeners.
- Queue failure hooks and operator notification.
- Notification channels selected by existing mail/log infrastructure first; SMS/WhatsApp external P2.
- Tests with queue/notification fakes and retry/idempotency assertions.

Acceptance:

- Duplicate delivery does not duplicate invoice, stock movement, payment, or ticket escalation.
- Retry policy is bounded; terminal failure becomes visible alert/failed job.
- Notification contains safe identifiers, no secrets or unnecessary PII.
- Scheduler overlap lock prevents duplicate billing runs.

Migration/data safety: add unique idempotency key only after duplicate preflight/cleanup. Preserve immutable financial/stock history.

## 13. P1-C — Critical lifecycle E2E and deterministic contract proof

Priority: mandatory P1

Parent: P0 closure

Destination:

- Extend `tests/e2e/kanban-t_49387795.spec.ts` or split by bounded domain when file becomes hard to maintain.
- Extend `tests/e2e/support/*` fixtures, not page-specific duplicate login/network handlers.
- Add API non-exposure test to PHP and browser only if browser semantics matter.
- Preserve `playwright.config.ts` one-worker DB serialization and current fail-closed request policy.

Required journeys:

1. SPK installation: explicit asset selection, evidence, submit, distinct approver, completion, stock movement, asset install, subscription activation, invoice creation.
2. Billing: recurring generation, duplicate-period prevention, send, partial/full payment, denied operator, reversal/cancel approval if in P0-B scope.
3. Ticket: create, assign, first response, resolve within/after SLA, close, ticket-to-SPK backlink, denied actor.
4. Inventory: issue/transfer/adjust approval and no-negative/reservation conflict evidence.
5. API: former scaffold paths return 404.
6. Operations smoke: health/readiness and scheduler list, while destructive guard remains fail-closed.

Acceptance:

- Stable semantic selectors; no fixed DB IDs.
- Unique test data; isolated E2E DB; one worker.
- App-origin console/request failures fail test except exact proven lifecycle cancellation.
- Traces/screenshots/videos retained only on failure and cleanup restores owned lifecycle.

Commands:

```bash
rtk playwright test --config playwright.policy.config.ts
rtk npm run test:e2e
rtk npm run lint
rtk npm run typecheck
rtk npm run build
```

## 14. P1-D — Reporting correctness and source-path repair

Priority: P1

Parent: P0 closure

Reporting destination:

- Add `Modules/Reporting/app/Queries/UnitPerformanceQuery.php` only if manager unit reporting remains approved baseline.
- Add feature tests for date boundaries, tenant scope, MRR, revenue based on payment date versus invoice date decision, churn denominator, SLA, stock card.
- Export decision: existing PDF dependency may serve approved reports; spreadsheet export requires explicit need/dependency approval.

Documentation destination:

- Repair `AGENTS.md` active references to `docs/architecture/*`, `docs/business/*`, and current standards paths.
- Repair `ROADMAP.md`, `TASKS.md`, and active docs that point at missing `docs/modules/*` or old section paths.
- Historical `_legacy-*` remains non-normative.

Acceptance: no active root/current doc points at a missing or legacy normative source; reporting formulas have explicit business definitions and tests.

## 15. Optional P2/P3 backlog — do not dispatch now

| Item | Why deferred | Trigger |
|---|---|---|
| Multi-company activation | v1 target single company; security surface grows sharply | Signed product requirement and isolation design review |
| Customer portal/mobile | Separate identity, privacy, support scope | Approved customer self-service roadmap |
| Payment gateway/webhooks | Credentials, reconciliation, signature/idempotency contract | Gateway/vendor selected |
| Radius/NAS provisioning | Hardware/vendor access and outage risk | Lab environment and rollback contract available |
| NMS/SNMP/topology discovery | Multi-vendor telemetry and scale work | NOC requirements and hardware credentials |
| S3/object storage | Current local media may suffice | Backup/volume/HA requirement proves need |
| Public/partner API | No current consumer contract | Approved consumer, versioning, SLA, OpenAPI scope |
| Rule-engine auto-assignment | Manual assignment safer for bounded pilot | Measured dispatch volume and policy rules |
| Omnichannel SMS/WhatsApp | External provider and privacy consent | Provider, consent, retention approved |
| Full accounting/GL | Billing/AR is current boundary | Finance product decision and accounting controls |

## 16. Proposed Kanban cards

Do not create implementation cards until this recovered plan is approved. Available grounded profiles: `code-executor`, `tester`, `qa-isp`, `reviewer`, `isp`.

For each slice use this formal chain:

```text
implementation(code-executor)
  -> smoke(tester)
  -> formal QA(qa-isp)
  -> remediation(code-executor, independent ready card if needed)
  -> recheck(tester + qa-isp)
  -> closure(reviewer/isp)
```

Suggested cards:

| Card | Assignee | Parents | Scope |
|---|---|---|---|
| G0 baseline | code-executor | recovery plan approval | Reproducible scope and preflight |
| P0-A tenant writes | code-executor | G0 | Scoped references and service assertions |
| P0-B RBAC/SoD | code-executor | P0-A | High-risk duty split and actor evidence |
| P0-C SPK asset | code-executor | G0 | Explicit selected serialized asset |
| P0-D disable API | code-executor | G0 | Remove scaffold route exposure |
| P0-E deploy/supervision | code-executor | G0 | Tracked runbooks/process definitions |
| P0-F backup/restore | code-executor | P0-E | Backup scripts and drill contract |
| P0 smoke | tester | P0-A..P0-F | Serialized focused/full runtime gates |
| P0 QA | qa-isp | P0 smoke | Formal security/business/ops verdict |
| P0 closure | reviewer | P0 QA | Verify terminal PASS and pilot rehearsal |
| P1-A observability | code-executor | P0 closure | Reporter/metrics/alerts |
| P1-B jobs/notifications | code-executor | P0 closure | Retry/idempotency/escalation |
| P1-C E2E | code-executor | P0 closure | Critical lifecycle browser coverage |
| P1-D reporting/docs | code-executor or isp for docs | P0 closure | Formula tests and source map repair |
| P1 smoke | tester | P1-A..P1-D | Full serialized gates |
| P1 QA | qa-isp | P1 smoke | Production-readiness verdict |
| Final rescore | reviewer | P1 QA | Update readiness score and release decision |

DB-heavy cards must not execute concurrently in shared worktree. Implementation can be serialized by dependency or isolated worktrees/databases; final verification always uses one clean integration state.

## 17. Completion definition

Pilot candidate only when:

- all P0 cards formally pass;
- no scaffold API routes remain;
- tenant write and SoD denied tests pass;
- explicit asset assignment passes concurrency test;
- deploy/rollback rehearsal and restore drill succeed;
- full PHP, static analysis, frontend, and serialized E2E gates pass;
- current diff is reviewable and critical artifacts are no longer accidental untracked state.

Production-ready label only after P1 observability, alerts, domain failure handling, critical E2E, and final independent rescore pass. Enterprise-ready label remains bounded to internal single-company ISP operations, not external telco integration scope.
