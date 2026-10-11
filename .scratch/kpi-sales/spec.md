# KPI sales — customer-sales-attribution-v1 (replacement-contract DRAFT, no approval implied)

Status: done
Ledger: docs/plans/INBILS_OMP_BACKLOG.md (cards `t_40d53598`, `t_c4b6971b`, `t_6c27c12e`, `t_58229ddb`, `t_54d82ff9`)

> **Approved by the owner 2026-10-10: the recommended option of every Owner decision below is the contract.** Where a decision lists no recommendation, the most conservative option applies and is recorded under "Approved decisions" when the lane closes. Original acceptance unrecovered.

## Owner decisions (approve in one word: "setuju rekomendasi" or pick per item)

1. **KPI revenue basis** — which money does "sales" use?
   - A) Invoiced (issue_date) AND collected (paid_at, non-reversed) AND outstanding, as defined above (recommended): matches Billing's immutable payment ledger and survives reversals; tradeoff: differs from Business Metrics' `revenue_paid` (paid invoices by issue_date), so a reconciliation note is required.
   - B) Match Business Metrics exactly (paid invoices, `paid_amount`, `issue_date`): reconciles with the existing page; tradeoff: ignores partial payments and reversal timing.
   - C) Collected only (cash basis): simplest; tradeoff: hides billed-but-unpaid pipeline.
2. **Who can see what** — capability model.
   - A) New `report.sales` (admin+manager company-wide; no salesperson access) (recommended): mirrors `report.technician`, fail-closed, no new persona; tradeoff: salespeople cannot see their own KPI.
   - B) `report.sales` plus own-only view for any user with it who is not admin/manager: salesperson self-service; tradeoff: needs a sales role/persona (Role persona/SoD lane) and own-only tests.
   - C) Reuse `report.view`: zero new permission; tradeoff: all staff see every salesperson's numbers.
3. **Attribution as-of vs current** after a correction (`customer.sales.correct`).
   - A) Current attribution applies to all history (recommended): simple, one join; tradeoff: past periods' per-person numbers change after a correction (audit trail exists via base lane).
   - B) Effective-dated attribution (history table): stable history; tradeoff: base-lane schema change and larger scope.
4. **"Customers acquired" date** source.
   - A) `customers.created_at` (recommended unless base lane adds acquisition timestamp): zero schema; tradeoff: imported/backfilled customers count at import date.
   - B) New `acquired_at` set by base lane: precise; tradeoff: base-lane scope change and backfill rule.
   - C) First subscription `activation_date`: ties to service start; tradeoff: excludes customers never activated.
5. **Soft-deleted customers and cancelled-then-reissued invoices**.
   - A) Include soft-deleted customers' payments/invoices in history (`withTrashed`), exclude only soft-deleted invoices (recommended): collected cash is never silently dropped; tradeoff: deleted customers still appear under their acquirer.
   - B) Exclude soft-deleted customers entirely: simpler; tradeoff: totals can drop vs Billing.
6. **Permission naming** for base-lane capabilities consumed here: recovered text says `customers.sales.assign` / `customers.sales.correct` (plural) while repo uses singular `customer.*`.
   - A) `customer.sales.assign` / `customer.sales.correct` (recommended): follows `{module}.{action}` and existing seeder naming.
   - B) Keep plural exactly as recovered; tradeoff: inconsistent with seeder, would need exceptions.
7. **Pagination/UI shape**.
   - A) Cards + one server-side-paginated DataTable per acquirer (recommended): consistent with canonical DataTable/useServerTable; tradeoff: totals row computed separately from the page slice.
   - B) Non-paginated table (≤ ~50 acquirers assumed): simpler; tradeoff: not canonical for large companies.
8. **Replacement approval scope**: approve this draft as the replacement for all five cards (QA/reviewer gates as in Testing Decisions) and explicitly record that original rubrics remain unrecovered.
   - A) Approve as drafted (recommended).
   - B) Approve with exclusions (list items from Out of Scope to move in/out).
   - C) Defer until base lane lands and re-draft against its real column/permissions.

**One-answer shortcut**: "Approve KPI sales draft with recommended options 1A,2A,3A,4A,5A,6A,7A,8A".

## Cards — (ordered chain: backend → UI → tester → QA → reviewer)

| # | Card | Legacy | Hermes status (export) | Ledger |
|---|------|--------|-----------------------|--------|
| 1 | `t_40d53598` BACKEND REPORTING: KPI sales customer-sales-attribution-v1 | `t_7830eec9` | scheduled (assignee code-executor) | docs/plans/INBILS_OMP_BACKLOG.md:490, needs_recovery |
| 2 | `t_c4b6971b` UI REPORTING: KPI sales … | `t_3b435d95` | scheduled (ui-engineer) | :842 |
| 3 | `t_6c27c12e` TESTER: KPI reporting … | `t_07542a84` | todo, block_kind=capability, recurrences 1 (tester) | :602 |
| 4 | `t_58229ddb` QA-ISP: KPI reporting … | `t_e1b75378` | scheduled (qa-isp) | :554 |
| 5 | `t_54d82ff9` REVIEWER: KPI reporting … | `t_0f908b50` | todo, block_kind=capability (reviewer) | :538 |

Chain links in Hermes export: 40d53598→c4b6971b→6c27c12e→58229ddb→54d82ff9 (all internal; `t_40d53598` has no parent link — the cross-lane dependency on the base lane is by naming/ledger order only, see Dependencies).

## Recovery result

**Original acceptance NOT found.** Evidence:
- All five Hermes bodies are the generic text "Recovered from pre-reinstall authoritative Kanban snapshot… detailed comments… may be incomplete" (Hermes board export (read-only snapshot, removed after the audit), `body` of each card). Ledger records `scope/acceptance: belum pulih` for each (docs/plans/INBILS_OMP_BACKLOG.md:490-495, 538-543, 554-559, 602-607, 842-847).
- Exact search of legacy IDs (`t_7830eec9`, `t_3b435d95`, `t_07542a84`, `t_e1b75378`, `t_0f908b50`) and card IDs across the repo found hits ONLY in `docs/plans/INBILS_OMP_BACKLOG.md` and `docs/plans/INBILS_OMP_SOURCE.json`. `docs/process`, `docs/claude-batches`, `docs/modules` do not exist; `ROADMAP.md`/`TASKS.md`/`docs/` have no `customer-sales-attribution`, `acquired_by`, `report.sales`, or `KPI sales` text. ROADMAP.md:285-286 only lists a generic "Sales (invoice paid)" report type and ROADMAP.md:292-293 a "sales trend" dashboard chart (Phase 8, not this lane).
- Hermes agent evidence confirming absence: reviewer comment on `t_54d82ff9` (export, created_at 1789312730): "no parent implementation handoff, changed-file manifest, test evidence, or recoverable acceptance criteria beyond the title; repository/session searches found no `customer-sales-attribution-v1` artifact. Current Reporting changes concern audit-log work". Snapshot comment (INBILS_OMP_SOURCE.json:4934, ui-engineer on base card): "Source currently has no sales attribution implementation/spec".
- ISP gate comments (export, created 1789477384 on `t_6c27c12e` and `t_54d82ff9`): "Pertahankan blocked sampai spec KPI authoritative, parent implementation, dan runtime `inbils_test` terbukti" / "perlu spec KPI authoritative dan handoff QA nyata". ISP comment 1790830114 (≈2026-10-01): runtime prerequisites now verified, but "jangan dispatch sampai seluruh parent scheduled substantif PASS dan kontrak produk dipulihkan".
- Hermes runs: tester `t_6c27c12e` run 1 reclaimed (restore safety), run 2 blocked ("no PHP CLI or Composer… no MySQL", frontend typecheck/build passed, lint baseline 661 problems); reviewer `t_54d82ff9` run 1 reclaimed, run 2 blocked (capability/prerequisite, no deliverable to verify). The other three cards each have one `scheduled` freeze run (Linux recovery freeze, dependency graph lost). No card ever ran implementation; no result/handoff exists.
- **Only recovered fragment bearing on this lane** (it is the *base* lane's UI acceptance, snapshot comment id 13 on `t_ffe9fc13`, docs/plans/INBILS_OMP_SOURCE.json:4969): "UI depends on backend contract B1 (nullable customers.acquired_by_user_id, server-authoritative eligible acquirer props/capabilities, correction reason/audit, resource/index/export contracts). … explicit assignment only with customers.sales.assign; correction only with customers.sales.correct plus reason; inactive historical acquirer shown but not selectable; null/noneligible as Unassigned; … no KPI/reporting/schema changes." Interpretation [INFERENCE]: KPI is the downstream consumer of B1's `customers.acquired_by_user_id`; the base lane forbids KPI work, so the KPI lane owns all report-side work.
- Ledger ordering: docs/plans/INBILS_OMP_BACKLOG.md:94 lists "Reporting V2; Sales dasar lalu KPI; Role persona/SoD…" (Sales base first, then KPI).
- No prior ledger recovery note specific to this lane exists (grep of `kpi-sales|attribution` in the ledger yields only the card entries). Nothing here contradicts the ledger.

## Current implementation map

Already exists (verified by reading files):
- **Reporting module** `Modules/Reporting`: `ReportController` (index, business, technician, asset, sla, stockCard, auditLog), queries in `app/Queries` (`BusinessMetricsQuery`, `TechnicianPerformanceQuery`, `AssetUtilizationQuery`, `SlaComplianceQuery`, `StockCardQuery`, `AuditLogQuery`), `Concerns/AppliesDateRange` (applies only when both from and to given; day-inclusive), route-aware `ReportFilterRequest` (authorize by route name: audit-log → report.audit.view, technician → report.technician, else report.view). Routes in `Modules/Reporting/routes/web.php`: `report.view` group (index/business/asset/sla/stock-card) plus standalone `reports/technician` (`can:report.technician`) and `reports/audit-log` (`can:report.audit.view`).
- **Existing sales-like metric** `BusinessMetricsQuery`: `Invoice` where `status='paid'`, date filter on `issue_date`, `sum(paid_amount)`, split by `type` recurring/one_time; `outstanding = SUM(total - paid_amount)` for statuses sent/partial/overdue (unfiltered by date); relies on `BelongsToCompany` global scope for tenancy (no explicit forCompany). Not attributed to any person.
- **Billing** `Modules/Billing`: `Invoice` (BelongsToCompany, SoftDeletes, `customer_id`, `issue_date`, `due_date`, `total`, `paid_amount`, statuses via BillingService: draft → sent → partial/paid → overdue, cancelled; `payments()` relation); `Payment` (immutable, `amount`, `paid_at`, `received_by`, `cancelled_at`; reversal only via `BillingService::reversePayment`, which recomputes invoice status sent/partial/paid); index `payments(company_id, paid_at)` and `invoices(company_id, issue_date|customer_id|status)`; `Money` helper (bc math) for decimal strings.
- **Customer** (app/ shared core, `App\Models\Core\Customer`): BelongsToCompany, SoftDeletes, `subscriptions()`; `ServiceSubscription` has `activation_date`, `mrc_amount`, `otc_installation_fee`, `terminated_at`, `status`.
- **Permissions** in `database/seeders/RolePermissionSeeder.php`: `report.view` (admin, manager, staff), `report.technician` (admin, manager, technician), `report.manage`, `report.audit.view` (admin only via givePermissionTo(all)). `tests/Unit/RolePermissionSeederTest.php` asserts admin has every permission (so any new permission must be in the master list).
- **UI**: `Pages/Admin/Reports/{Index,Business,Technician,AuditLog,StockCard,Asset,Sla}.tsx`; `Index.tsx` hard-codes the report card list and gates technician with `can('report.technician')`; `AdminLayout.tsx` has `hasFinanceReportsNavigation` (includes report.view/report.technician) and sidebar items for Reports and Technician Performance. No chart library: `recharts` is NOT in package.json and not under Components (ROADMAP mentions it, repo doesn't have it).
- **Prior-art tests**: `tests/Feature/Reporting/{ReportQueryTest,TechnicianPerformanceAuthorizationTest,TechnicianReportCapabilityRouteTest,StockCardReportRouteTest,AuditLogTenantIsolationTest}.php`; E2E `tests/e2e/{technician-performance,reporting-stock-card,reports-audit-log}.spec.ts` (canonical fixtures, 320px/dark, exact 403).

Gaps (verified absent via grep across Modules/app/database/resources/routes/tests/config):
- No `customers.acquired_by_user_id` column, relation, fillable, factory state, resource field, policy, or UI (base lane `t_ae471280`/`t_ffe9fc13` not started).
- No `customer.sales.assign` / `customer.sales.correct` / `report.sales` permissions; no sales role/persona definition; no 'eligible acquirer' rule in code.
- No sales KPI query, route, controller action, Inertia page, report card, sidebar item, tests, or E2E.
- No acquirer-change audit/history store (activity log via `LogsActivity` on Customer logs fillable only; would capture the change if column is fillable, but a reason field is not modelled).

## Problem Statement

Management cannot see how much business each salesperson/acquirer brings in. Billing already records invoices and payments per customer, and the base lane (customer-sales-attribution-v1 dasar) is meant to record who acquired each customer, but nothing aggregates acquisition → subscriptions → billed → collected per acquirer, so sales performance cannot be reviewed, compared by period, or audited, and the existing Business Metrics report cannot be sliced by person.

## Solution

Add a read-only **Sales KPI** report under Reporting, guarded by its own capability (`report.sales`), that, for a selected period and company, shows per-acquirer KPIs computed from `customers.acquired_by_user_id` joined to subscriptions, invoices and payments, plus a company total row and an explicit "Unassigned" bucket for customers without attribution. Manager/admin see all eligible acquirers in the company; an acquirer (if granted the capability) sees only themselves. Formulas are versioned and shown on the page so numbers are explainable. No exports, no schema change in this lane (the attribution column and its permissions come from the base lane), Technician Performance untouched.

## User Stories

1. As a manager, I want a Sales KPI page under Reports so that I can review sales performance without leaving Reporting.
2. As a manager, I want to filter by date range (From/To, day-inclusive like other reports) so that I can compare months.
3. As a manager, I want one row per acquirer showing customers acquired in the period so that I can see acquisition volume.
4. As a manager, I want new subscriptions activated (by `activation_date` in range) for each acquirer's customers so that I can see conversions to service.
5. As a manager, I want the active MRR portfolio of each acquirer's customers so that I can see recurring value brought in.
6. As a manager, I want total invoiced (non-draft, non-cancelled, by `issue_date`) per acquirer so that I see billed revenue.
7. As a manager, I want total collected (non-reversed payments by `paid_at`) per acquirer so that I see cash actually received.
8. As a manager, I want outstanding receivable per acquirer (as-of today) so that I see collection risk.
9. As a manager, I want a collection rate (collected ÷ invoiced for the period, null when invoiced is 0) so that I can compare acquirers fairly.
10. As a manager, I want customers without an acquirer shown as one "Unassigned" row so that totals reconcile with Business Metrics revenue.
11. As a manager, I want an inactive/historical acquirer still listed (marked inactive) if they have attributed customers so that history is not lost.
12. As an admin, I want a company total row so that I can reconcile per-person numbers with company totals.
13. As a salesperson granted `report.sales`, I want to see only my own KPI so that I cannot see colleagues' performance.
14. As a user without `report.sales`, I want a 403 on the route and no menu/card so that data is not exposed.
15. As a user in company A, I never see customers/invoices/payments of company B, even if I pass another company's acquirer id.
16. As a manager, I want the page to show the KPI formula definitions (formula version) so that I can trust the numbers.
17. As a keyboard/mobile user, I want the page usable at 320px, light/dark, with visible focus, loading/error/empty states.
18. As an auditor, I want a corrected attribution (via base lane `customer.sales.correct`) to be reflected consistently and the rule for historical numbers documented (see Decision 3).

## Implementation Decisions

(modules, interfaces, schema, permissions, props — no file paths; subject to owner decisions below)

- **Module placement**: new `SalesKpiQuery` in the Reporting module (read-only query class, same style as `TechnicianPerformanceQuery`), invoked from a new `ReportController::sales` action; `ReportFilterRequest` gets a route-aware `authorize()` branch for `admin.reports.sales` (→ `report.sales`) and a `user_id`/acquirer filter reuse (existing `user_id` rule) — no new request class. Cross-module model reads follow the documented Reporting read-only exception (BusinessMetricsQuery already imports `Modules\Billing\Models\Invoice`).
- **Inputs from base lane (consumed, not created here)**: `customers.acquired_by_user_id` (nullable FK users), an `acquiredBy`/`acquiredCustomers` relation, eligible-acquirer rule, correction audit. This lane adds NO migration. If base lane delivers an acquisition timestamp, KPI "customers acquired" uses it; else `customers.created_at` [INFERENCE — decision 4].
- **Tenancy**: every sub-query via `forCompany($companyId)` with the actor's company (not the implicit global scope alone, matching Technician query); acquirer filter validated against same-company eligible users, otherwise 403 (fail-closed, same pattern as `canSelect`). Soft-deleted invoices excluded by the model; customer soft-delete handling per decision 5.
- **Metric definitions (formula version `sales-kpi-v1`, draft)**:
  - customers_acquired = customers with acquirer = X created in range.
  - subscriptions_activated = ServiceSubscription of those customers with `activation_date` in range.
  - active_mrr = Σ `mrc_amount` of those customers' subscriptions with status `active` (snapshot, as-of today).
  - invoiced = Σ `invoices.total` for those customers with status in (sent, partial, paid, overdue) and `issue_date` in range (draft/cancelled excluded).
  - collected = Σ `payments.amount` where `cancelled_at IS NULL` and `paid_at` in range, for invoices of those customers.
  - outstanding = Σ (`total − paid_amount`) for those customers' invoices in (sent, partial, overdue), snapshot as-of now.
  - collection_rate = collected / invoiced (2-dp, null if invoiced = 0).
  All money as decimal strings using `Money` (bc), never floats.
- **Eloquent-only aggregation**: use relation aggregates (`withCount`, `withSum` with constrained closures on `User`/`Customer`→`invoices`→`payments`) or bounded Eloquent collections grouped in PHP; no new `DB::raw`/`selectRaw`/`whereRaw` (AGENTS rule for new code; note existing queries use selectRaw and are untouched). Query count must not be N+1 per acquirer [INFERENCE: verify with a query-count test].
- **Permissions**: new `report.sales` (named `{module}.{action}` consistent with `report.technician`) added to the master list in `RolePermissionSeeder`; admin gets all automatically; manager gets it in the seeder; staff/technician/customer do NOT. Own-only scope for a salesperson role needs a sales role/persona decision (decision 2); `report.manage` already means company-wide access in the technician pattern and would do the same here.
- **Routing**: `GET admin/reports/sales` named `admin.reports.sales`, standalone route with `can:report.sales` (mirrors technician), not inside the `report.view` group.
- **Inertia props**: `data` = { formula_version, period, rows[] (acquirer id/name/is_active + metrics), unassigned row, totals }, `filters` = {date_from, date_to, acquirer id}, `acquirers` = eligible acquirer options (resource collection), `can` = { select_acquirer }. Resources/Resource classes shape rows; no raw model arrays.
- **UI**: new page composed only from `Components/ui` + `Components/composite` (PageHeader, Card, DataTable/`useServerTable` if paginated, Input, Select/SearchSelect, Button, Alert); From/To form like Business; table with per-acquirer rows + totals row; definitions Card/tooltip; report card in `Reports/Index.tsx` and sidebar item gated by `report.sales`; `hasFinanceReportsNavigation` extended. No chart library is installed; v1 is tables/cards only (adding recharts requires separate approval per AGENTS 'tambah library tanpa approval').
- **No export** (ledger boundary: no new report exports), no change to Technician Performance, Business Metrics aggregates, Stock Card, Audit Log.
- **Cleanup/docs**: update CHANGELOG/docs on completion; remove scratch files.

## Testing Decisions

- **Backend seams**: `SalesKpiQuery::execute()` unit-level feature tests (RefreshDatabase, MySQL `inbils_test`) modelled on `ReportQueryTest` and `TechnicianPerformanceAuthorizationTest`: fixtures with `CustomerFactory`, `InvoiceFactory`, Payment records created through `BillingService::recordPayment` (Payment model forbids direct mutation); reversal fixture via `reversePayment` to prove reversed payments are excluded; boundary rows at `date_to 23:59:59` / next day 00:00; draft/cancelled/soft-deleted invoice exclusions; unassigned bucket; inactive acquirer; zero-invoice collection_rate null; reconciliation: Σ acquirer collected + unassigned = company collected (and vs Business revenue where bases coincide — document the difference if not).
- **Authorization/tenancy**: route-middleware test (`can:report.sales` present, `can:report.view` absent) like `test_report_routes_keep_their_distinct_permissions`; admin/manager 200, staff/customer/technician 403; cross-company acquirer id → 403; foreign company's data never counted; own-only user sees only own row (if decision 2 = option with salesperson capability).
- **UI/E2E**: new canonical Playwright spec modelled on `tests/e2e/technician-performance.spec.ts` and `reporting-stock-card.spec.ts`: seeded fixtures via app routes/`tests/e2e/setup.php`, period filter, totals reconciliation text, forbidden-user exact 403, report card/sidebar visibility by capability, 320/1280 light/dark no horizontal overflow, keyboard focus, loading/empty/error. Must run serially against `inbils_test`; reuse the Technician evidence gates (isolated PHPUnit, typecheck, focused ESLint, one build, canonical E2E, smoke).
- **Tester card (`t_6c27c12e`)**: run the above plus Pint/Larastan on changed paths; **QA (`t_58229ddb`)**: independent read-only tenant/IDOR/formula audit incl. LIKE/escaping if org-scoping used, formula-doc vs code, accessibility evidence; **Reviewer (`t_54d82ff9`)**: final read-only approval of diff, provenance, rollback (no schema change in this lane → rollback = remove route/page/query/permission).

## Out of Scope

- The attribution column/migration, Customer Create/Edit/Show/Index UI, `customer.sales.assign`/`customer.sales.correct`, correction audit (base lane `t_ae471280`/`t_ffe9fc13`/`t_b70b570d`/`t_60912019`/`t_3d697b14`).
- Reporting V2 formula-versioning framework, granular capability redesign and data-dictionary page (`t_1d565698`, `t_d698fabf`, `t_8aa097cc`, `t_ad34cbf5`, `t_a32e031e`): this lane only ships a minimal inline formula note.
- Commission/payout calculation, targets/quotas, leaderboards, forecasting [INFERENCE: not evidenced as original scope].
- Exports (Excel/CSV/PDF), dashboard widgets/charts, recharts installation, drill-down (P2 export/dashboard lanes).
- Changes to Technician Performance, Business Metrics aggregates, Stock Card, Audit Log, Billing logic, subscription lifecycle.
- Head Company / multi-company aggregation (single-company v1 stays).

## Dependencies / ordering vs other lanes

- **Hard dependency**: customer-sales-attribution-v1 base lane (`t_ae471280` backend → `t_ffe9fc13` UI → `t_b70b570d` tester → `t_60912019` QA → `t_3d697b14` reviewer) must be `done` first. KPI needs the real `customers.acquired_by_user_id`, eligible-acquirer rule, and correction audit; the base lane's recovered fragment (INBILS_OMP_SOURCE.json:4969) explicitly excludes KPI work. Ledger ordering "Sales dasar lalu KPI" at docs/plans/INBILS_OMP_BACKLOG.md:94. Hermes export shows no explicit link from `t_3d697b14` to `t_40d53598`; the dependency was lost with the legacy graph, so the OMP ledger should add it as predecessor of `t_40d53598` [INFERENCE: this is the intended order].
- **Soft dependency**: Reporting V2 (`t_1d565698`, `t_d698fabf`, `t_8aa097cc`, `t_ad34cbf5`, `t_a32e031e`: formula-version, granular capability, data dictionary). If V2 lands first, the Sales KPI should register its formula/capability in that framework; if KPI lands first, it ships a minimal inline formula note and `report.sales` and V2 later absorbs it. Recommend KPI after the base lane and independent of V2 order, but avoid parallel edits to `RolePermissionSeeder`, `ReportController`, `ReportFilterRequest`, `Reports/Index.tsx` and `AdminLayout.tsx` (shared files; single writer rule).
- **Soft dependency**: Role persona/SoD lane (decision 2B needs a sales persona); Sidebar IA lane (sidebar item placement for the new report); P2 export/drill-down and P2 persona dashboard (later consumers of KPI numbers).
- Runtime prerequisite is already verified per ISP comment 1790830114 (2026-10-01): Linux workspace, PHP DOM+pdo_mysql, Composer, mysql/inbils_test guard, worker routes, Chromium, canonical E2E. Reporting P1 (closed 2026-10-10) and Technician Performance are complete and must not be reopened; DB tests must run serially on `inbils_test`.
no files were written by this investigation.

Unverified items are marked [INFERENCE] above; everything else was read directly from the cited files.

## Approved decisions

Owner approved the recommended option of every decision (2026-10-10, "Ya, lane aman saja"):

1. **1A** Invoiced (issue_date), Collected (non-reversed payments by `paid_at`) and Outstanding, from Billing's ledger. Differs from Business Metrics `revenue_paid` by design (noted in the registry caveat).
2. **2A** New `report.sales.view` (admin and manager, company-wide; no salesperson self-view). Route middleware, route-aware `ReportFilterRequest`, hub card, sidebar item and dictionary all use it.
3. **3A** Current acquirer applies to all history; past periods change after a correction (audit trail from the base lane).
4. **4A** Customers Acquired by `customers.created_at`.
5. **5A** Soft-deleted customers' invoices and payments stay in history; soft-deleted invoices (and their payments) and reversed payments are excluded. Soft-deleted customers are not counted in Customers Acquired.
6. **6A** Singular `customer.sales.*` names consumed.
7. **7A** Canonical DataTable + `useServerTable`, one row per acquirer, totals computed over the full filtered set before pagination; sort allowlist `SalesKpiQuery::SORTABLE`, page sizes 10/25/50.
8. **8A** This contract replaces all five cards; original rubrics remain unrecovered.

Documented choices: Unassigned covers null and foreign-company acquirers; collection rate = ROUND(100 × collected / invoiced, 2), null when invoiced is 0; a lone From or To means all time (same rule as other reports); an `acquirer_id` outside the eligible/credited set returns 403. All 7 metrics are registered as `sales.v1`. Reporting gains one read-only model, `SalesCustomer` (documented in `docs/architecture/database.md` and `modules.md`), so Core `Customer` does not depend on Billing.

## Closure

Done 2026-10-10: QA PASS, reviewer APPROVED. Evidence in the ledger section "KPI sales replacement closure (2026-10-10)".
