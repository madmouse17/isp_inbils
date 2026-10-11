# Head Company — cards (chain: backend P0 → {backend P1, UI} → tester → QA → reviewer)

Status: done
Ledger: docs/plans/INBILS_OMP_BACKLOG.md (cards `t_2cd5c5cc`, `t_25322033`, `t_c2a626e0`, `t_3e41cec6`, `t_521274c4`, `t_2aa65b96`)

> **Approved by the owner 2026-10-10: the recommended option of every Owner decision below is the contract.** Original acceptance unrecovered.

## Owner decisions (approve in one word: "setuju rekomendasi" or pick per item)

1. **Q1 — Do we activate multi-company (v2) now?** 
   (a) Yes: amend AGENTS.md/ENTERPRISE_ISP docs, execute lane. (b) No: supersede all 6 cards; keep v1 single-company; leave `company_user` as documented dormant hook. (c) Hybrid: do only fail-closed CompanyContext (P0 slice) now, defer hierarchy/switcher/consolidation. **Recommend (c)→(a) staged**: ship fail-closed context first (benefits single-company, closes the P0 readiness gap), then (a) with a signed isolation review as `ENTERPRISE_ISP_EXECUTION_PLAN.md:479` requires. Tradeoff: slower to consolidated reporting, but each step is independently releasable and reversible.
2. **Q2 — Source of truth for effective company** 
   (a) Session `active_company_id` validated against `company_user` (b) store `users.company_id` as the active company (switch = write to users row) (c) per-request header/subdomain. **Recommend (a)**: no writes to the user row on every switch, works per-browser-tab-session; tradeoff: session-bound (multi-tab shares one active company).
3. **Q3 — How is a Head represented?** (a) nullable `parent_company_id` only (Head = has children) (b) parent link + explicit `is_head` flag (c) separate `company_groups` table. **Recommend (a)**: smallest schema, one level; tradeoff: no group-level attributes (name, base currency) — add (c) later if needed.
4. **Q4 — Does Head membership imply access to descendants?** (a) No; access only via explicit membership rows (b) Yes, a Head member can switch into/see all subsidiaries. **Recommend (a) for switching + (b) only for the consolidated read-only report** (derived server-side): tradeoff: slightly more rows to manage, but write access never leaks down the tree.
5. **Q5 — Roles in multiple companies**: (a) global spatie roles apply in every member company (b) enable spatie teams for per-company roles (large migration, touches seeders/cache/tests) (c) membership row carries a role subset. **Recommend (a)** for the pilot; tradeoff: cannot be admin in A and viewer in B; revisit if the owner needs it.
6. **Q6 — How are subsidiary companies created?** (a) admin UI/service under a new permission (b) CLI/artisan only (like `inbils:setup`) (c) reuse the setup wizard. **Recommend (b)** for the pilot (smallest attack surface; `/setup` stays one-shot); tradeoff: no self-service UI.
7. **Q7 — Which report is the consolidated pilot?** (a) Business metrics (aggregates, no PII) (b) Receivables (c) both. **Recommend (a)**: easiest reconciliation, no per-row data across tenants; tradeoff: Receivables/AR consolidation deferred.
8. **Q8 — Currency/timezone differences across companies**: (a) refuse consolidation unless all currencies equal, date boundaries per-company timezone (b) convert with a fixed owner-entered rate (c) show per-currency subtotals only. **Recommend (a)**; tradeoff: blocks mixed-currency groups until FX is separately approved.
9. **Q9 — Export parity scope**: (a) CSV only (b) CSV + PDF via `ExportQuery` (c) also xlsx (current xlsx is a TSV placeholder). **Recommend (a)**; tradeoff: no PDF for consolidated until PDF layout is approved.
10. **Q10 — Fail-closed blast radius**: is it acceptable that making the scope fail closed will require updating many existing tests/seeders/commands that rely on null context (`E2eFixtureSeeder`, `SetupWizardService`, `NetworkAssetDeprovisionTest` no-company case, `TenantRecheckTest` Auth::logout flows)? (a) Yes, in one cutover (b) feature-flag/config toggle (c) staged by module. **Recommend (a)**: a flag leaves the unsafe path alive; tradeoff: larger single change gated by the full isolated suite.
11. **Q11 — Data migration for memberships**: auto-backfill one default membership per existing user from `users.company_id`? (a) yes, migration + reversible (b) backfill by artisan command run once. **Recommend (a)**; tradeoff: must be exercised only on `inbils_test`/staging first.

1. `t_2cd5c5cc` [legacy t_885ed187] BACKEND P0 — hierarchy, membership, fail-closed CompanyContext (code-executor, Hermes status scheduled; ledger: needs_recovery)
2. `t_25322033` [legacy t_50952f43] BACKEND P1 — consolidated reporting pilot + export parity (parent: t_2cd5c5cc)
3. `t_c2a626e0` [legacy t_224483db] UI — company switcher + consolidated report context (parent: t_2cd5c5cc)
4. `t_3e41cec6` [legacy t_14a51272] TESTER — hierarchy, isolation, switcher, report reconciliation (parents: t_25322033 AND t_c2a626e0)
5. `t_521274c4` [legacy t_188f9b7e] QA-ISP — formal tenant, RBAC, data-integrity gate (parent: t_3e41cec6)
6. `t_2aa65b96` [legacy t_1b915d8c] REVIEWER — final architecture + release-readiness decision (parent: t_521274c4)

Graph is confirmed by Hermes `links` and snapshot `links` (INBILS_OMP_SOURCE.json ~L4683-4698) and ledger predecessor/successor fields.

---

## Recovery result

**Original acceptance found: NO.** Searched exact card IDs (t_2cd5c5cc, t_25322033, t_c2a626e0, t_3e41cec6, t_521274c4, t_2aa65b96) AND legacy IDs (t_885ed187, t_50952f43, t_224483db, t_14a51272, t_188f9b7e, t_1b915d8c) across the whole repo, plus `docs/**`, `ROADMAP.md`, `TASKS.md`, `CHANGELOG.md`, `AGENTS.md`, snapshot and ledger. Hits are ONLY:
- Ledger sections (`docs/plans/INBILS_OMP_BACKLOG.md`, headings `### t_2cd5c5cc …`, ~L356-380, 474, 530, 834 — line numbers drift as the ledger is edited): `scope/acceptance: belum pulih; snapshot body hanya recovery warning; evidence: tidak lengkap` / `next: Kontrak hilang; pulihkan dari sumber exact legacy ID, jangan coding.`
- Snapshot `docs/plans/INBILS_OMP_SOURCE.json` (task entries ~L626, 706, 746, 1266, 1586, 3466; links ~L4683-4698): body is just "Recovered from pre-reinstall authoritative Kanban snapshot dated 2026-09-12 … Original detailed comments, events, dependencies, and run history may be incomplete because the canonical SQLite backup is missing."
- `docs/process/`, `docs/claude-batches/`, `docs/modules/` do not exist. `docs/COMPANY_PROFILE.md` and `docs/ARCHITECTURE.md` never existed in git; AGENTS.md now points to `docs/architecture/*` and `docs/business/*`; Current docs are under `docs/architecture/*`, `docs/business/*` (confirmed by `docs/ENTERPRISE_ISP_READINESS.md:43`).
- Only informative titles survive: "hierarchy, membership, fail-closed CompanyContext", "consolidated reporting pilot dan export parity", "company switcher dan consolidated report context", "hierarchy, isolation, switcher dan report reconciliation", "formal tenant, RBAC dan data-integrity gate", "final architecture dan release-readiness decision". The behaviours below are derived from these titles only — everything else is [INFERENCE].

**Hermes facts that matter** (Hermes board export (read-only snapshot, removed after the audit)):
- t_2cd5c5cc: 1 run, status scheduled, comment "Recovered scheduled state; timing metadata unavailable." Never executed.
- t_25322033: never executed; 2026-… `isp` comment "Freeze pemulihan Linux: dependency graph legacy hilang; tunggu rekonstruksi dependency dan preflight runtime terverifikasi."
- t_c2a626e0: one run started 1789312836 then `reclaimed` ("manual_reclaim: Restore safety freeze") — no output. Never completed.
- t_3e41cec6 (tester): run 1 reclaimed; run 2 `blocked` (block_kind=capability): PHP/Composer/MySQL absent, vendor missing, docker socket denied. It also recorded frontend `npm run typecheck`/`npm run build` PASS (comment truncated in export). 2026-… `isp` audit: "gate Tester Head Company valid; dua parent … terpasang … Pertahankan blocked sampai kedua parent selesai dan runtime test DB terbukti." Latest (ts 1790830115, 2026-10-01): runtime prerequisites now verified, moved to TODO dependency-wait; **"jangan dispatch sampai seluruh parent scheduled substantif PASS dan kontrak produk dipulihkan."** i.e. the product contract is the explicit gate.
- t_521274c4, t_2aa65b96: reclaimed + scheduled freeze, no output.
- Zero code-bearing results exist for any card; there is no prior implementation to reconcile with (no branch_name, result null).
- Ledger line ~94: "Head Company perlu keputusan tenant khusus; single-company v1 tetap berlaku." — the ledger already flags this lane as needing a special tenant decision. No prior ledger recovery note beyond that.

---

## Current implementation map

**Exists**
- `companies` table (`database/migrations/2026_06_30_134628_create_companies_table.php`): name, code (unique), logo, address, phone, email, website, timezone, currency, settings json, is_active. **No parent_company_id / head flag / hierarchy of any kind.** (Grep for parent_company|head_company|company_group|consolidat across app/Modules/database/routes/resources/tests/docs: zero hits. The `parent_id` columns in the repo belong to `locations` and `organization_units`, both company-internal trees; `organization_units.type` has a literal `company` value but is a unit type, not a tenant link.)
- `users.company_id` nullable FK → companies, restrictOnDelete (`2026_06_30_134630_add_company_id_to_users_table.php`). `User::company()` belongsTo; `Company::users()` hasMany. `company_id` is NOT in `User::$fillable` (set via forceFill, e.g. `UserController` L67).
- **`company_user` pivot** (`2026_07_26_150000_create_company_user_table.php`): id, company_id, user_id, is_default, timestamps, unique(company_id,user_id), index(user_id). It was added as a v2 hook but is **completely unused** — no model, relation, seeder, factory, controller, test (grep `company_user|is_default` hits only the migration; the SetupWizardTest hit is just a test name).
- `CompanyService` (`app/Services/Core/CompanyService.php`): `currentId()` = `Auth::user()?->company_id ?? (testing ? $testingCompanyId : null)`; `current()` with static cache; `setting()`, `updateProfile()`, `updateSettings()`, `useCompanyForTests()`, `resetCache()`. No session/active-company, no membership check, no `runAs()` for jobs/commands.
- `BelongsToCompany` (`app/Traits/BelongsToCompany.php`): global scope `company`; **fail-OPEN**: `if ($companyId !== null) $builder->where(table.company_id, $companyId)` — with null context nothing is filtered. Creating hook auto-sets company_id only when context non-null and attribute null. Scopes: `withoutCompany()`, `forCompany($id)` (403 if null), `forCurrentCompany()` (403 if null). `tapActivity` stamps activity company. Tests lock this: `tests/Unit/BelongsToCompanyTraitTest::test_no_filter_when_current_company_is_null`.
- Used on ~28 models in app/Models/Core and Modules/* (Customer, Location, ServiceSubscription, Invoice, Payment, Product, Ticket, WorkOrder, …). Exceptions per AGENTS.md: User, Role, Permission, ActivityLog, Company.
- Direct bypass sites: ~100 `withoutCompany()` calls (services with `lockForUpdate` + explicit `where company_id`), plus Artisan commands/jobs that enumerate active companies (`billing:generate`, `billing:check-overdue`, `CheckTicketSlaBreachesJob`; `routes/console.php`; tests `TenantRecheckTest`, `SlaMultiCompanyTest`).
- Fail-closed-at-service precedent: `abort_if($companyId === null, 403, 'Company context is required.')` in LocationService, SubscriptionService, BillingService, StockService, SpkService, TicketService, NetworkAssetService, AuditLogQuery, Approval/Dispatch/MyWork controllers, validation rules `BelongsToCompany`/`PolymorphicBelongsToCompany` (`app/Rules`).
- Middleware: `require.has.company` (users.company_id null → /setup) on every admin route group incl. `Modules/Reporting/routes/web.php`; `RedirectIfNoCompany`; `RequireNoCompany` (abort 403 if `Company::count() > 0` — so a second company cannot be created via /setup).
- Inertia shared props (`HandleInertiaRequests`): `auth.user.company_id` and `company` both derive from `$user->company` (home company). Frontend: `useCompany()` hook, `AdminLayout` brand/sidebar uses it; type `Company` in `resources/js/types/index.ts`; sidebar “Company” → `/admin/company/profile` guarded by `company.manage`.
- `CompanyController`/`CompanyPolicy`: profile/settings of the *current* company only, gate `company.manage`.
- Spatie permission: `config/permission.php` `teams => false` (roles/permissions global, no per-company role assignment). Seeded perms include `company.manage`, `organization.view|manage|export`, `report.view`, `report.technician`, `report.manage`, `report.audit.view` (`database/seeders/RolePermissionSeeder.php`). No `company.switch` / consolidated-report permission.
- Reporting module: `ReportController` (index, business, technician, asset, sla, stockCard, auditLog). `business/asset/sla` rely on ambient global scope (BusinessMetricsQuery etc. — would become cross-tenant if scope is null); `stockCard`/`technician` pass `$request->user()->company_id`. Audit log: client-side CSV/JSON export only (`Pages/Admin/Reports/AuditLog.tsx`); no server-side report exports. Reporting P1 closed 2026-10 with “no exports” (CHANGELOG Reporting P1 entry). Shared server export seam: `app/Support/ExportQuery.php` (CSV/PDF; xlsx is TSV placeholder) used by Pattern-B table exports.
- Tests: isolation prior art — `tests/Feature/TenantBoundaryTest.php`, `Feature/TenantIsolation/TenantSafeWriteReferenceTest.php`, `Feature/Reporting/AuditLogTenantIsolationTest.php`, `Feature/Billing/TenantRecheckTest.php`, `Feature/Admin/LocationContractTest.php`, `Feature/DocumentTest.php`. E2E: `tests/e2e/*.spec.ts` (Playwright, 1 worker, `tests/e2e/support/demo.ts` `loginAs(page, role)`, seeded single DEMO company via `DemoUserSeeder`/`E2eFixtureSeeder`, DB `inbils_test`).

**Gaps (for these cards)**
1. No hierarchy storage (no parent link / head designation / depth rule).
2. Membership table exists but unused; no model/relation/seed; no rule linking membership ⇄ hierarchy ⇄ roles.
3. No *active company* concept: `currentId()` = home company. The same value is also read **directly** from `users.company_id` in 33+ files (e.g. `CustomerController`, `CustomerIndexQuery`, `UserIndexQuery`, `ApprovalInboxQuery`, `DispatchQueueQuery`, `MyWorkQuery`, `UserHistoryQuery`, `BillingService` L205/210/278/284, `InvoiceController` L118, `NotificationResource`, `ReportController`, `AuditService`, `RequireHasCompany`, `RedirectIfNoCompany`, Inertia share). A switcher implemented only in `CompanyService` would leave these on the home company → silent split-brain tenant (some pages show switched company, others the home company). `AuditLogQuery` even prefers `auth()->user()->company_id` over `CompanyService::currentId()`.
4. Scope is fail-OPEN on null context (the opposite of “fail-closed CompanyContext”). Many tests/seeders/commands run with null context intentionally (`Auth::logout()+resetCache()`, e.g. `NetworkAssetDeprovisionTest`, `TenantRecheckTest`, `E2eFixtureSeeder`, `SetupWizardService`).
5. Roles/permissions are global: a user cannot be admin in Company A and viewer in Company B (teams=false).
6. No server-side report export; no consolidated aggregate; `BusinessMetricsQuery` has no company-set parameter; companies have per-row `currency` and `timezone`, so cross-company sums are ill-defined unless currencies match.
7. `users.email` is globally unique (single identity across companies OK); `RequireNoCompany` blocks creating additional companies via wizard — no “create subsidiary” flow exists.
8. Activity log `company_id` is stamped from model/causer/user company → must follow the *effective* company for audit correctness.

---

### ⚠ Architecture conflict the owner must resolve (read first)

**Binding rule today** (`AGENTS.md:69-70`): “v1 = single company (1 row, dibuat Setup Wizard). v2 = multi-company (hooks ready, tidak dibangun v1).” Reinforced by `docs/ENTERPRISE_ISP_READINESS.md:7,276` (multi-company activation + cross-company administration = **excluded**), `docs/plans/ENTERPRISE_ISP_EXECUTION_PLAN.md:479` (multi-company activation allowed only after “Signed product requirement and isolation design review”), `CHANGELOG.md:297-299,398-399` (“multi-company formal = v2 (v1 single, schema ready)”), ledger ~L94 (“single-company v1 tetap berlaku”), and `AGENTS.md` rule “TIDAK boleh query cross-company tanpa `withoutCompany()` eksplisit (hanya untuk admin cross-company v2, artisan, queue job)”.

**What the 6 cards demand** is the v2 activation itself: more than one company row, parent/head hierarchy, a user belonging to several companies, switching the effective tenant at runtime, and aggregating across tenants in reports. That is not a “hook”; it changes the tenant-resolution contract for ~100 bypass sites + 33+ direct `user->company_id` readers + RBAC model + reporting + audit.

So exactly one of these must be decided before anything is coded:
- (A) **Activate v2 now**: AGENTS.md rule changed (owner-signed, per the execution plan's gate), lane executes as drafted below; or
- (B) **Keep single-company v1** and close/supersede these 6 cards (and make the existing unused `company_user` migration an explicit dead hook or drop it); or
- (C) **Hybrid**: land only the fail-closed CompanyContext hardening (valuable even in single-company: closes null-context unscoped reads, matches ENTERPRISE_ISP_READINESS P0 “Artisan/queue path without authenticated company must provide explicit company context and cannot silently query all tenants”, `ENTERPRISE_ISP_EXECUTION_PLAN.md:129`) and defer hierarchy/switcher/consolidated reporting.

Until the owner answers Q1 below, the rest of the contract is conditional on option A (and the P0 card's fail-closed slice on A or C).

---

## Problem Statement

The business wants a parent (“Head”) company that can see and report across its subsidiary companies without weakening tenant isolation. Today every tenant sees only its own rows, a user has exactly one company, there is no way to represent which company is the head of which, and the tenant scope silently stops filtering whenever the request/job has no company, which is unsafe the moment more than one company exists. The lane's original acceptance text is lost, so this contract replaces it.

## Solution

(Conditional on owner choosing option A.) Introduce a minimal, explicit tenancy hierarchy and a single effective-company authority:
1. Model a one-level Head→Subsidiary relation between companies and make membership of a user in one or more companies explicit (reuse the existing `company_user` table).
2. Make **one** component (CompanyContext, evolving `CompanyService`) the only place that decides the effective company for a request/job/command, and make every domain scope fail closed when no context exists. All current direct `user->company_id` readers move to that component.
3. Add a company switcher limited to the user's member companies; the active company is stored server-side (session) and validated on every request.
4. Add one read-only consolidated report pilot for a Head company over its subsidiaries (Business metrics), with a per-company breakdown that reconciles to the consolidated total, and export parity (what is shown == what is exported).
5. Prove it with isolation, RBAC and reconciliation tests; QA and reviewer gate the architecture.

## User Stories

1. As a platform admin I can designate one company as the Head of other companies so that group structure is stored once, centrally.
2. As a platform admin I can attach/detach a subsidiary to a Head, and the system rejects cycles, self-parenting and depth > 2 levels.
3. As a platform admin I can add/remove a user's membership in a company; a user with no membership in a company can never act in it.
4. As a user who belongs to several companies I get a default company at login (`is_default`) and only ever act in one *active* company at a time.
5. As a member user I can switch the active company from the topbar to any company I belong to, and the switch is audited.
6. As a member user I cannot switch to a company I do not belong to; a forged switch request returns 403/404 and changes nothing.
7. As a user whose membership is revoked or whose company is deactivated mid-session, my next request falls back to my default company (or to a clear no-company state) — never to stale access.
8. As a developer/operator, any code path (HTTP, queue job, Artisan, tinker, seeder) with no company context returns **no rows / 403 for tenant data** instead of all companies' data; any intentional cross-company path must say so explicitly.
9. As a Head-company manager with the consolidation permission I can open a consolidated Business report covering my Head and its subsidiaries for a date range.
10. As that manager I can see a per-company breakdown (rows per company + total) and the totals reconcile exactly (sum of per-company rows == consolidated total).
11. As that manager the consolidated report refuses (clear message) when the included companies use different currencies, rather than summing incompatible amounts.
12. As a manager I can export the same report (CSV, and PDF if already supported by ExportQuery) and the exported figures equal the on-screen figures for the same filters/company set.
13. As a user of a subsidiary (non-Head) I cannot open consolidated views or see sibling/parent data even if I guess the URL.
14. As a user in the UI I always see which company I am working in and whether a report is single-company or consolidated.
15. As an auditor, activity-log rows record the effective company that acted and the switch events; consolidated reads do not leak another company's audit rows into a company-scoped Audit Log.
16. As QA I can verify no tenant-scoped model can be read without context and that the old single-company behaviour (one company, no switcher) is unchanged for existing users.

## Implementation Decisions

(interfaces/behaviour only; [INFERENCE] unless tied to a repo fact above)

**Data model**
- Hierarchy: a nullable self-referencing parent link on `companies` (Head = a company with no parent that has ≥1 child, or an explicit flag — see Q3). Max depth 2 (Head → Subsidiary); enforce in a Service (cycle/depth/self checks) and a DB-level FK. Eloquent-only; no raw SQL.
- Membership: reuse `company_user` (company_id, user_id, is_default). Add a `CompanyMembership`/pivot-backed `User::companies()` relation and `Company::members()`. Invariants (service-enforced inside a transaction): unique pair (already DB-enforced); exactly ≤1 default per user; `users.company_id` is the home/default company and must be a member (backfill: one membership row per existing user with `is_default=true` — requires a data migration that must be reversible and run only against `inbils_test` during verification).
- `users.company_id` stays as the **home company** (login default) in v-A; it stops being the *effective* tenant.

**CompanyContext (P0 core)**
- One resolver: effective company = validated session `active_company_id` ∈ memberships (or Head-descendant if Q4 allows), else home company, else null. Exposed via `CompanyService::currentId()/current()` (keep the API; change the source), so existing callers that already use it get the switch for free.
- All 33+ direct `$user->company_id` / `Auth::user()->company_id` readers (list in Gaps #3) migrate to the resolver; no caller reads `users.company_id` as tenant except the resolver and the home-company/membership code. Clean cutover, no alias.
- Fail-closed `BelongsToCompany` scope: null context ⇒ the global scope yields an empty result set (Eloquent-only: constrain with an empty `whereIn`/`whereKey([])` — same technique as the Audit Log empty-scope guard in CHANGELOG) and the `creating` hook aborts 403 instead of silently inserting a null company_id. Explicit, greppable opt-outs remain `withoutCompany()` / `forCompany($id)`; add `CompanyService::runAs($companyId, callable)` (or equivalent) for Artisan/queue per-tenant loops and seeders so they do not need blanket `withoutCompany()`.
- `CompanyService::useCompanyForTests()` stays for tests; guard unchanged.
- Setup flow: `/setup` stays single-shot for the *first* company; subsidiary creation is a separate, permission-gated admin flow (permission `company.manage` or new `company.create-subsidiary`) — decision Q6.

**Permissions** (naming `{module}.{action}`): `company.switch` (use switcher), `company.hierarchy.manage` (attach/detach/membership), `report.consolidated.view` (consolidated pilot + export). Seeded only on admin/manager as decided in Q5; customer/technician/staff get none. Roles remain global (teams=false) — a membership grants the same roles in every member company unless the owner opts into per-company roles (Q5).

**Consolidated reporting pilot (P1 core)**
- Scope pilot: Business metrics only (MRR, active/suspended/terminated subs, revenue_paid, outstanding, SLA compliance %, installation count) — the existing `BusinessMetricsQuery` output (all scalar/aggregate; easiest to reconcile). Stock card/technician/audit are explicitly excluded.
- Query layer: a Reporting query that accepts an explicit, server-validated set of company ids (Head + descendants the actor is a member of) and returns per-company rows + total. Companies set is derived server-side from the hierarchy, never from raw request ids.
- Reconciliation invariant: Σ per-company == consolidated, asserted in tests; consolidated refuses if currencies differ (or timezone-sensitive date boundaries are evaluated per company timezone — Q8).
- Export parity: use the shared server export seam (ExportQuery) for CSV (and PDF only if already wired); export endpoint applies the same authorization + company-set + filters as the screen and must be bit-for-bit the same figures. Row cap per `ExportQuery::DEFAULT_MAX_ROWS` unless overridden.
- Inertia: new/extended Reporting page props: `context` {mode: single|consolidated, company: {id,name,currency}, companies: [{id,name,code,currency}]}, `data`, `filters`; `can` {consolidated, export}. Resource/Query classes in `Modules/Reporting`; thin controller; Policy/Gate for `report.consolidated.view`.

**UI (switcher + context)**
- Shared Inertia props: `company` = effective company (not home), plus `companies` (memberships, only when >1) and `canSwitch`. Switcher placed in AdminLayout topbar using existing `Components/ui` (`Dropdown`/`dropdown-menu`, `Badge`) and `Components/composite`; hidden for single-membership users so v1 single-company UX is unchanged. POST switch endpoint (thin controller → CompanyContext service, audited) then Inertia reload (full prop refresh). Report pages show a context banner “Consolidated: Head + N subsidiaries” vs “Company: X”; Indonesian copy follows existing locale (`app.locale = 'id'`). Keyboard-accessible, 320px layout, dark mode per antislop-human/layoutmobile rules.

**Audit**: AuditService logs `company.switched` (from, to) and hierarchy/membership changes under log_name `company`; activity `company_id` = effective company.

**Compatibility**: single-company installs (one company, one membership per user) behave exactly as before: no switcher shown, no consolidated page offered, scope results identical.

## Testing Decisions

Seams (test via public behaviour):
- Unit: `BelongsToCompany` fail-closed (invert `test_no_filter_when_current_company_is_null`; add `creating` abort); hierarchy service (cycle/depth/self); CompanyContext resolution (session vs membership vs home; revoked membership; inactive company).
- Feature (MySQL `inbils_test`, TestCase/DatabaseTransactions style as in `AuditLogTenantIsolationTest`, two+ companies, roles via RolePermissionSeeder): membership CRUD authorization; switch endpoint allowed/denied/forged id; post-switch pages (customers, billing, reports, audit log) all reflect the switched company (this is the regression net for the 33+ direct-readers); consolidated report authz (Head member vs subsidiary vs customer role), reconciliation Σ==total, currency mismatch refusal, export == screen parity; null-context commands (`billing:generate`, `tickets:sla`) still iterate companies via explicit `runAs`; null-context HTTP/tinker returns no tenant data.
- Prior art: `tests/Feature/TenantBoundaryTest.php`, `TenantIsolation/TenantSafeWriteReferenceTest.php`, `Reporting/AuditLogTenantIsolationTest.php` (incl. the null-context 403 test ~L217), `Billing/TenantRecheckTest.php`, `Admin/LocationContractTest.php` (~L858).
- E2E (Playwright, `tests/e2e/support/demo.ts`): extend E2E seeding with a second company + Head link + a multi-member user (must be added to `E2eFixtureSeeder`/demo manifest); spec `tests/e2e/head-company.spec.ts`: switcher visible only for multi-member user, switch changes brand/lists, consolidated report renders totals = breakdown, export link figures match, 320/1280 viewports, keyboard. Prior art: `technician-performance.spec.ts`, `reports-audit-log.spec.ts`, `reporting-stock-card.spec.ts`.
- Gates (runtime already verified per 2026-10-01 Hermes comment): backend focused suite + full isolated PHPUnit before QA (fail-closed change has repo-wide blast radius — many tests rely on null context; expect fixture updates), canonical E2E, `npm run build`, scoped ESLint/Pint, scoped Larastan.
- QA-ISP (t_521274c4) checklist: no tenant model readable without context; every direct `user->company_id` reader migrated (grep gate); RBAC matrix per role; membership/hierarchy invariants; no cross-tenant leakage in exports/audit/notifications; consolidated figures reconcile. Reviewer (t_2aa65b96): approves architecture vs the decision recorded in AGENTS.md + docs update; release-readiness includes rollback of the data migration.

## Out of Scope

- Customer/portal cross-company identity; per-company roles unless Q5 says so.
- Intercompany transactions/eliminations, consolidated financial statements, currency conversion/FX.
- Consolidated Stock Card, Technician, Audit Log, SLA/Asset reports beyond the Business pilot.
- Cross-company writes (a Head user creating invoices/tickets in a subsidiary other than by switching into it).
- Data movement between companies; company merge/split; billing/SLA scheduler redesign beyond explicit `runAs`.
- Any change to Hermes (`~/.hermes`) or the immutable snapshot.

## Dependencies / ordering vs other lanes

- Internal chain is strictly: `t_2cd5c5cc` (P0) → `t_25322033` ∥ `t_c2a626e0` → `t_3e41cec6` → `t_521274c4` → `t_2aa65b96`. UI and P1 both need the P0 CompanyContext API; tester needs both.
- Phase order from handoff memory/ledger: Technician Performance (done) → Phase Gate → Location Map (done per TASKS.md checkpoint) → recovery lanes; ledger ~L255 lists Head Company as one of nine lanes, order among them not fixed.
- **Reporting V2 (5 cards) and P2 export/drill-down (6 cards)** [INFERENCE — those contracts are not visible to me]: they touch the same Reporting module/export seam; consolidated pilot should consume (not fork) whatever export/Query conventions they settle on. Recommend sequencing Reporting V2 / P2 export BEFORE Head Company P1, or decide parity here explicitly (Q9).
- **P2 persona dashboard / Sidebar IA** [INFERENCE]: the switcher lives in AdminLayout topbar/sidebar; coordinate placement with Sidebar IA to avoid layout rework.
- **Role persona/SoD** [INFERENCE]: new permissions (`company.switch`, `company.hierarchy.manage`, `report.consolidated.view`) must be added to the same role matrix; SoD lane should not be seeded in parallel against the same seeder.
- **Notification Center / Dispatch/My Work** (done): read `user->company_id` directly (NotificationResource, MyWorkQuery, DispatchQueueQuery, ApprovalInboxQuery) — the P0 cutover must migrate these and re-run their suites.
- Shared MySQL `inbils_test` + worktree: DB-heavy cards must be serialized (ENTERPRISE_ISP_EXECUTION_PLAN rule 4).
- Precondition for ANY coding: owner answer to Q1 (and Q2-Q6 for P0); AGENTS.md/docs update recording the decision (the missing `docs/COMPANY_PROFILE.md` reference in AGENTS.md should be repointed to the real docs at the same time).

## Approved decisions

Owner approval 2026-10-10: the recommended option of every decision Q1-Q11. Backend cards `t_2cd5c5cc` (P0) and `t_25322033` (P1) applied them as below; the UI, tester, QA and reviewer cards inherit them.

1. **Q1: staged, shipped as one cutover (with Q10).** Fail-closed context and multi-company activation land together; AGENTS.md "Company Rules" and `docs/architecture/database.md` record the new rule.
2. **Q2: A.** Effective company = session `active_company_id`, validated against `company_user` and `companies.is_active` on every read (`CompanyService::canActIn`, memoized per request), else the home company `users.company_id`. `ResolveActiveCompany` (web group) heals the session; login clears the switch. `CompanyService::idFor($user)` is the effective company of a given user (home for any user that is not the authenticated one); `currentId()` adds `runAs()` scopes and the testing id.
3. **Q3: A.** `companies.parent_company_id` nullable self FK (RESTRICT). Head = no parent and at least one subsidiary. MySQL cannot CHECK against the auto-increment id, so self-parenting, cycles and depth are refused by `CompanyHierarchyService` only (`setParent`, `createSubsidiary`).
4. **Q4: A for switching, B for the report.** Switching needs `company.switch`, a membership row of an active company (the home company always counts), enforced by `CompanyPolicy::switchTo` and again in `CompanyMembershipService::switchTo`. The consolidated report derives Head + active subsidiaries server-side; the Head user needs no membership in them and cannot switch into them.
5. **Q5: A.** Roles stay global. `company.switch` (admin, manager) and `report.consolidated.view` (admin only) are seeded. `company.hierarchy.manage` was NOT added: Q6 keeps hierarchy and membership changes on Artisan, so no HTTP flow would consume it.
6. **Q6: B.** `inbils:company:create-subsidiary`, `inbils:company:grant-membership`, `inbils:company:revoke-membership`; `/setup` stays one-shot. A subsidiary copies the Head's currency and timezone unless overridden and gets the defaults of every new company (`CompanyCreated` listener).
7. **Q7: A.** `GET /admin/reports/consolidated` (+ `/export`): Business metrics, one row per company and a total, `BusinessMetricsQuery` unchanged for its formulas.
8. **Q8: A.** Different currencies are refused (page: HTTP 200 with `refusal` and `data: null`; file: 422). Timestamp periods (`created_at`, `terminated_at`, `completed_at`) use each company's own timezone; `issue_date` is a calendar date and is never shifted. The SLA percentage of the total is pooled from ticket counts, never an average of percentages.
9. **Q9: A.** CSV only, through `ExportQuery::fromRows` (same columns as the other summary files plus `Company`; the last block is `Consolidated`).
10. **Q10: A.** One cutover: every reader of `users.company_id` as a tenant moved to `CompanyService::currentId()` or `idFor($user)` (controllers, queries, policies, services, Reporting); `CompanySeeder`, `DemoUserSeeder` and `E2eFixtureSeeder` run inside `runAs`; `SetupWizardService` and `UserController::store` grant the home membership.
11. **Q11: A.** `2026_10_11_000003_backfill_default_company_memberships` (reversible; `down()` removes only the default home rows). `2026_10_11_000001` adds the parent column, `2026_10_11_000002` adds the `company_user` foreign keys and a one-default-per-user unique index.

Backend choices inside the contract (confirm at QA):

- **Inactive subsidiaries are left out** of the consolidation; a Head with no active subsidiary is not a Head (403).
- **A company's consolidated row can differ from its own Business page** near day edges when its timezone is not UTC: the single-company report keeps the v1 application-timezone boundaries (formulas unchanged, `ReportFormulaV1Test`).
- **User directories stay by home company** (`users.company_id`): user lists, technician and approver pickers show the users homed in the effective company.
- **The consolidated page component `Admin/Reports/Consolidated` is the UI card's**; until it exists the route has no Vite entry.
- **Deploy:** `php artisan migrate`, `php artisan db:seed --class=RolePermissionSeeder --force` (adds `company.switch` and `report.consolidated.view`).

Isolation review fixes (security review of 2026-10-11, verdict BLOCKED until these two landed):

- **F1: role definitions belong to the top-level company.** `RolePolicy` create/store/update/edit/delete also require that the actor's effective company has no parent (a Head or a standalone company); a subsidiary admin gets 403, also after switching into a subsidiary. The role list, the edit page and the export count users of the effective company only, and `can.create` follows the policy. Residual: two unrelated top-level companies on one install still share role definitions (Q5, roles are global).
- **F2: user administration needs every company of the target.** `UserPolicy` view/update/edit/delete (so email, password, roles and status) require `CompanyMembershipService::administers($actor, $target)`: the actor belongs to every company the target belongs to (home company plus memberships, active or not). Users with only the home membership are administered exactly as before; a shared user can be changed only by someone holding the same memberships.
- **Notes fixed:** `Company::isHead()` counts active subsidiaries only (a Head with only inactive subsidiaries gets 403); `CustomerService::createWithUser` grants the portal user its default `company_user` row.

## Closure

Done 2026-10-11: isolation review SIGNED (single-root install), QA/E2E green. Evidence in ledger "Head Company closure (2026-10-11)".
