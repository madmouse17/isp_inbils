# P2 Report Export Parity & Drill-down — cards (ordered chain)

Status: done
Ledger: docs/plans/INBILS_OMP_BACKLOG.md (cards `t_a71f5680`, `t_63055b4e`, `t_9de05904`, `t_3cac1cdf`, `t_a51e7983`, `t_843a2fb0`)

> **Approved by the owner 2026-10-10: the recommended option of every Owner decision below is the contract.** Original acceptance unrecovered.

## Owner decisions (approve in one word: "setuju rekomendasi" or pick per item)

1. **Lift the P1 "no new exports / aggregates unchanged" boundary for this lane?** Options: (A) yes — all six reports get export + drill; (B) row-level only: Stock Card + Audit Log export, drill for aggregate reports deferred; (C) drill-down only, no exports. **Recommend A** — matches card titles and spec; tradeoff: largest surface, mitigated by one shared export layer and structural tie-out.
2. **Include Technician Performance (declared complete/untouched in P1)?** (A) include export/drill as a read-only addition that reuses existing query/authorization unchanged; (B) exclude it. **Recommend A** — spec treats it as report #1 and manager org-scope tests exist; tradeoff: must not regress the QA-hardened LIKE-escaped scope.
3. **Export formats.** (A) CSV + PDF only (existing, Dompdf already installed); (B) add real XLSX via new dependency (spec says maatwebsite); (C) keep current TSV-as-xlsx. **Recommend A** — Enterprise plan already requires explicit approval for spreadsheet writer; tradeoff: spec's "Export Excel" stays unmet (CSV opens in Excel).
4. **Export permission.** (A) new `report.export` seeded to admin+manager + report's own gate; (B) reuse `report.view`/`report.audit.view` (no new permission); (C) per-report `report.<type>.export`. **Recommend A** — spec lists `report.export`; tradeoff: needs seeder/migration/catalogue test and role sync; interacts with Reporting V2's "granular capability" so names must be agreed with that lane.
5. **Drill-down mechanism.** (A) report-owned source-row endpoints sharing predicates with aggregates; (B) deep links to existing module lists with prefilled filters; (C) hybrid (B where the list already matches, A otherwise). **Recommend A** — existing lists lack date range on SPK/Tickets, SLA-outcome filter, multi-status invoice filter, company-wide subscription list, installation list, so B cannot reproduce metrics; tradeoff: new backend surface and refactor of aggregates to share predicates (expression results unchanged).
6. **Meaning of "reconciliation links"** (UI title only; no recovered definition). (A) links + displayed tie-out (aggregate vs source rows) and links to reconciling surfaces (Outstanding→Receivables, Stock Card→Stocks/Movements/SPK/request); (B) links only; (C) also opening/closing balance reconciliation (reverses P1 exclusion). **Recommend A** — satisfies "reconcile" without new accounting logic; tradeoff: Receivables inclusion rules vs `outstanding` need verifying and may legitimately differ.
7. **How to treat existing metric quirks in drill/export** (all-time SLA in Business, filters ignored by `by_category`/`by_location`, null deadline = non-compliant, subscription/outstanding ignoring date). (A) reproduce exactly and label scope in UI/file; (B) fix them now. **Recommend A** — preserves P1 contract; tradeoff: users may see "all-time" labels that expose odd definitions; open defects separately.
8. **Row cap / truncation.** (A) keep 5,000 with explicit truncation marker/warning; (B) reject with message above cap; (C) raise cap/async. **Recommend A** — parity with other exports; tradeoff: PDF memory at 5k rows unmeasured, consider lower PDF cap.
9. **Audit Log export content and audit of exports.** (A) CSV includes `properties` exactly as on screen (gated by `report.audit.view`), PDF omits it; no export-event logging; (B) A plus log each export (who/report/filters/row count) via activity log; (C) never export properties. **Recommend A** — parity, minimal scope; tradeoff: files may carry PII from properties; B is safer but adds scope (no export logging exists anywhere today).
10. **Subscription drill target** (no company-wide subscription list exists). (A) report-owned read-only subscription source list; (B) link only to per-customer pages; (C) skip subscription metrics. **Recommend A**; tradeoff: new read surface needing its own permission test.

`t_a71f5680` planner TODO (legacy `t_8fd13334`) → `t_63055b4e` backend (`t_377a1b09`) → `t_9de05904` UI incl. reconciliation links (`t_b08b0d54`) → `t_3cac1cdf` tester (`t_6b5db45a`) → `t_a51e7983` QA-ISP (`t_5231c581`) → `t_843a2fb0` reviewer (`t_4557c733`).

## Recovery result

**Original acceptance: NOT found.** Searched exact current+legacy IDs (all 12) across the whole repo (`grep` over `.`): hits only in `docs/plans/INBILS_OMP_BACKLOG.md` (one `### t_xxx` entry per card, each: `scope/acceptance: belum pulih; snapshot body hanya recovery warning; evidence: tidak lengkap` / `next: Kontrak hilang; pulihkan dari sumber exact legacy ID, jangan coding.`) and `docs/plans/INBILS_OMP_SOURCE.json` (title/body/link records). `docs/process/` and `docs/claude-batches/` do not exist; `docs/modules/` absent; ROADMAP.md/TASKS.md/CHANGELOG.md contain none of the IDs.

Hermes export facts (Hermes board export (read-only snapshot, removed after the audit)): all 6 cards `status: scheduled`, `created_by: restore-20260913`, `completion_contract: local-only`, null result/worker/started_at; bodies are only the generic "Recovered from pre-reinstall authoritative Kanban snapshot… Do not dispatch until…". Sole comment+run on each card (author `isp`): "SCHEDULED: Freeze pemulihan Linux: dependency graph legacy hilang; tunggu rekonstruksi dependency dan preflight runtime terverifikasi." No tester/QA verdict, no completed run. Hermes links are exactly the linear chain above and nothing links to/from outside the lane. Titles carry the only scope signal: backend = "report export parity dan drill-down source rows"; planner = "export parity dan drill-down report permission-safe"; UI = "report export, drill-down dan reconciliation links" (tester/QA/reviewer titles omit reconciliation).

**Closest non-card sources (spec, not acceptance — do not present as recovered):**
- `docs/business/reporting.md:15` "Export Excel + PDF."; `:25-26` admin and manager "view all reports + export"; `:142-143` "Export: Excel (maatwebsite/excel) untuk tabular, PDF (dompdf) untuk single-record summary."; `:164-176` permission list incl. `report.export`; `:191` `GET /admin/reports/{type}/export (Excel/PDF, same filters)`; `:229` "Manager view all + export → 200"; `:243` / `:249-250` acceptance "Export Excel (tabular) + PDF (single summary)", UI "result table/chart + export button".
- `docs/plans/ENTERPRISE_ISP_EXECUTION_PLAN.md:465` "Export decision: existing PDF dependency may serve approved reports; spreadsheet export requires explicit need/dependency approval."
- `docs/ENTERPRISE_ISP_READINESS.md:197` "export contract is absent"; `:64` "unit report/export … coverage incomplete".
- Boundary that blocks this lane today: ledger header/`phase_boundary` and Reporting contract: "No new exports", "no opening balance or reconciliation feature", "Existing SQL aggregates remain unchanged"; DataTable E closure: "E closure does not authorize … exports… no Reporting exports are authorized." Ledger also lists "P2 export" as a recovery-assessment item and (Hermes↔OMP audit section) counts this lane as 6 of the 45 `needs_recovery` cards;

## Current implementation map

### Shared export infrastructure (reuse, do not fork)
- `app/Support/ExportQuery.php` — fluent helper over an Eloquent `Builder`: `make()->defaultSort()->maxRows()->streamCsv($columns,$map,$file)` / `streamPdf($title,$columns,$map,$file)` / `downloadPdf` / `toResponse()` (format from `?format=`). CSV: BOM + header + `$query->cursor()` + per-cell formula-injection escaping (`escapeCsvCell`). PDF: Dompdf (`Barryvdh\DomPDF`), view `resources/views/exports/table.blade.php`, A4 landscape, `isRemoteEnabled=false`, rows materialised in an array, shows "(capped at N)" note. `normalizeMap()` expands callable row mappers per column. `applySort`/`applyListConstraints` allowlist sort. Row cap `config/exports.php` → `max_rows` 5000 (`ExportQuery::DEFAULT_MAX_ROWS` is 100 when no override).
- Defects/risks found (verified in source): (1) `xlsx` format is a TSV stream with the xlsx MIME type (`streamSpreadsheetDownload`, comment "ponytail: xlsx is TSV…"); `ExportMenu` defaults to `['csv','xlsx']` so any consumer that forgets `formats` offers a fake Excel. (2) `streamCsvFromRows` (row-array path, the only builder-less path) lacks BOM and `escapeCsvCell` formula escaping — unsafe for aggregate/summary exports as is. (3) CSV is silently truncated at the cap (only PDF prints a capped note). (4) `cursor()` ignores `with()` eager loads → likely N+1 per exported row [INFERENCE: Laravel behaviour, not measured here]. (5) PDF 5,000-row memory is unmeasured (ledger D closure says the same).
- Controllers using it: Inventory (`StockController::export/movementsExport`, Product/Category/Unit), `WorkOrderController::export` (`Gate::authorize('viewAny')` + `spk.export`), `TicketController::export` (`viewAny` + `ticket.export`), `InvoiceController::export` (`billing.export`), `NetworkAssetController::export`, Service ×4, customers/org/employee/etc. Pattern: one private `filteredQuery()` shared by index and export; handler-scope restriction (assigned-only) lives in `filteredQuery` so export inherits it. Permissions seeded in `database/seeders/RolePermissionSeeder.php`: `inventory.export`, `spk.export`, `ticket.export`, `billing.export`, `network_asset.export`, `service.export`, … **but no `report.export`** (only `report.view`, `report.technician`, `report.manage`, `report.audit.view`; admin gets all; manager `report.view`+`report.technician`; staff `report.view`; technician `report.technician`).
- UI: `resources/js/Components/ui/ExportMenu.tsx` (`exportUrl`+`params` → `window.location.assign`, or `onExport`), used by ~25 index pages with `can.export` / `can('x.export')` gating; E2E convention `Export CSV` / `Export PDF` menu items, no `Export Excel` (see `tests/e2e/datatable-d.spec.ts` `exportParity`).

### Reporting module today
- Routes (`Modules/Reporting/routes/web.php`): `reports` index, `business`, `asset`, `sla`, `stock-card` under `can:report.view`; `technician` under `can:report.technician`; `audit-log` under `can:report.audit.view`. **No export route, no drill/source route.** `ReportController` returns Inertia only; `ReportFilterRequest` validates date_from/date_to/technician_id/location_id/asset_type/category_id/product_id/user_id/log_name/event/search/page/per_page(≤100)/sort/direction and authorizes by route.
- Pages: `Reports/{Index,Business,Asset,Sla,Technician,StockCard,AuditLog}.tsx`. Only Stock Card and Audit Log use `DataTable`+`useServerTable`. `Reports/Index.tsx` shows the Audit Log card to anyone with `report.view` (only `technician` is capability-filtered) though the route is `report.audit.view` — navigation gap. `AuditLog.tsx` has a **client-side** export: CSV of the *current page's rows only* (`exportRowsAsCsv`) and an "xlsx" option that downloads JSON; `can.export` is just `report.audit.view`. `StockCard.tsx` renders no `reference_type/reference_id` although `StockMovementResource` (Reporting) returns them.
- Reporting P1 closure (done): Stock Card is a paginated/sortable ledger (`StockCardQuery`, `product` via `withTrashed()` company-checked); Audit Log guard `whereKey([])`.

### The six reports: aggregates that need drill-down (verified from Query classes)
| Report | Aggregate (key) | Existing predicate (all via `BelongsToCompany` global scope unless noted) | Existing list that could serve as source | Gap |
|---|---|---|---|---|
| Business | `mrr` | `ServiceSubscription status=active` `sum(mrc_amount)`, no date | none global (only `customers/{c}/subscriptions`, per-customer) | no company-wide subscription list |
| Business | `active/suspended/terminated_subscriptions` | `groupBy status`, no date | same | same |
| Business | `new_subscriptions` | `created_at` range, only if both dates given | same | same |
| Business | `churn` | `status=terminated`, `terminated_at` range (both dates) | same | same |
| Business | `revenue_paid/recurring/one_time` | `Invoice status=paid`, `issue_date` range, `sum(paid_amount)`, `type` | `admin.invoices.index` (type,status,date_from/date_to on issue_date) | invoice filter applies either date alone, report needs both → must align; sum is `paid_amount`, list shows `total` |
| Business | `outstanding` | `Invoice status in (sent,partial,overdue)`, `selectRaw SUM(total-paid_amount)`, no date | `admin.invoices.index` (single status only) / `admin.billing.receivables` (aging buckets via `BillingService::receivables`) | multi-status unsupported; receivables is the natural reconciliation target (inclusion rules vs report not verified) |
| Business | `asset_distribution` | `NetworkAsset groupBy status` | `admin.network-assets.index` (status,asset_type,location_id) | OK |
| Business | `sla_compliance_pct` | `Ticket resolved_at not null`; compliant `resolved_at <= sla_deadline` (null deadline ⇒ non-compliant), all-time | `admin.tickets.index` | no resolved/SLA-outcome/date filter; `sla_breached` filter = *open & past deadline* (different meaning) |
| Business | `installation_count` | `WorkOrder type=installation status=completed`, `completed_at` range | `admin.spk.index` (type,status,assigned_to,search) | no date range filter |
| SLA | `total_resolved`, `sla_compliant`, `breach_count` (`resolved_at > sla_deadline`), `avg_*` (raw `AVG(TIMESTAMPDIFF)`), `by_category` (join + `CASE`, **ignores date and category filter**) | Ticket resolved, `resolved_at` range, `category_id` | tickets index (category_id,status) | same as above; `by_category` rows use different predicate than the top counters |
| Asset | `status_distribution`, `customer_linked_count`, `retired_count`, `by_location` (join `locations`, groups by *name*), `installation_count` (`NetworkAssetInstallation.installed_at` range) | only `status_distribution` honours `location_id`/`asset_type`; others ignore filters | network-assets index; installations have no list | no installation-history list; filter-inconsistent aggregates |
| Technician | `spk_completed`, `tickets_resolved`, `active_workload`, `spk_by_type`, `tickets_by_category`, `avg_*` , `avg_score/rating` | explicit `forCompany($companyId)`, `assigned_to`, status/date; access via `ReportController::authorizeTechnicianReportActor` + `TechnicianPerformanceQuery::eligibleTechnicians/canSelect` (admin(`report.manage`) all; manager: org-path descendants with LIKE escaping; technician: self) | `admin.spk.index`/`tickets.index` (assigned_to filter; handlers restricted to own rows) | manager org scope must be reused for drill; evaluations (`EmployeeEvaluation`) have a separate index with own scope |
| Stock Card | ledger rows (already row-level); `balance_after` | company+product(+location,+date) | `stock-movements` index/export (`inventory.view`/`inventory.export`), `stocks` | route needs only `report.view`, not `inventory.view` (pre-existing); `reference_type/id` (morph: WorkOrderItem / InventoryRequestItem) not rendered or linkable |
| Audit Log | paginated rows | `AuditLogQuery::query()` (company mandatory, abort 403 cross-tenant) — reusable Builder | n/a | export is client-side page-only; properties JSON visible per `report.audit.view` |

Tenant caveat (verified): `BelongsToCompany` global scope is a no-op when `CompanyService::currentId()` is null (`BusinessMetricsQuery`, `SlaComplianceQuery`, `AssetUtilizationQuery` rely on it implicitly; `by_location`/`by_category` use unscoped joins to `locations`/`ticket_categories`). New export/drill code must use explicit `forCompany($actor->company_id)` (fail-closed, as Technician/StockCard/AuditLog do).

Policies for drill targets (verified): `InvoicePolicy` view = `billing.view`; `NetworkAssetPolicy` = `network_asset.view`; `TicketPolicy::view` additionally restricts handlers (start/resolve/close without assign) to own assigned tickets; `WorkOrderPolicy::view` same-company + scope; list `filteredQuery` for tickets/SPK restricts handlers to `assigned_to = user`.

Test prior art: `tests/Feature/Reporting/{ReportQueryTest,AuditLogTenantIsolationTest,TechnicianPerformanceAuthorizationTest,StockCardReportRouteTest,TechnicianReportCapabilityRouteTest}.php`, `tests/Feature/ReportFilterValidationTest.php`; export suites e.g. `tests/Feature/Ticketing/TicketServerTableExportTest.php` (company scope, CSV filter parity, 403 without permission, handler own-rows-only, inactive actor zero rows), `tests/Feature/SPK/WorkOrderIndexTest.php`, `tests/Feature/Inventory/StockServerTableExportTest.php` (shared PDF regression), `tests/Unit/Support/ExportQueryTest.php`, `TestDatabaseIsolationTest` (pins `inbils_test`); E2E `tests/e2e/datatable-d.spec.ts` (`page.request.get(.../export?format=csv|pdf)`, `%PDF-` signature, `text/csv`, `attachment`), `tests/e2e/reporting-stock-card.spec.ts`, `tests/e2e/reports-audit-log.spec.ts`, `tests/e2e/technician-performance.spec.ts`.

## Problem Statement

A user can read six reports but cannot take them out of the browser in a controlled way, and cannot see *which records* produce a number. Business/SLA/Asset/Technician figures are opaque aggregates; Stock Card and Audit Log are row-level but Stock Card exposes no export or links to the referenced SPK/request, and Audit Log's only export is a client-side, current-page CSV plus a mislabelled "xlsx" JSON. The shared server export path used by Inventory/SPK/Tickets is not applied to reports, there is no report-level export permission, and existing list pages cannot reproduce several report predicates (no date filter on SPK/Tickets, no SLA-outcome filter, no company-wide subscription list). Without a permission-safe design, naive drill-down would leak rows across module permissions (a `report.view` staff user seeing invoices/tickets they cannot open) or across tenant/unit scope (manager scope on Technician). Reporting P1 deliberately excluded exports and reconciliation, so this needs a new, explicitly approved contract.

## Solution

Add, inside `Modules/Reporting`, (1) server-side CSV/PDF export for each report using the existing `ExportQuery`/Dompdf path with the same filters and caps as the on-screen report, and (2) permission-safe drill-down: every aggregate that is shown gets a link to a paginated *source-row* list whose predicate is shared with the aggregate, so row count and sum tie out to the displayed number. (3) Reconciliation links: where a figure has a natural reconciling surface (Outstanding → Receivables; Stock Card reference → SPK / inventory request; Stock Card product/location → Stocks & Movements), render a link and a visible "tie-out" (aggregate vs source row total). All exports/links are gated by the report's own permission **and** a new `report.export` permission (exports) / the source module's view permission and policy scope (drill), enforced server-side; UI hides what the user cannot use but never relies on hiding. Formulas, filters and visuals of the six reports stay as they are (P1 boundary respected), except where the owner approves sharing predicates between aggregate and source query.

## User Stories

1. As an admin, I can export any of the six reports as CSV or PDF with the exact filters currently applied on screen, so the file matches what I see.
2. As a manager with `report.export`, I can export Business, SLA, Asset and Stock Card reports; I cannot export Audit Log unless I hold `report.audit.view`.
3. As a staff user with only `report.view`, I see report figures but no Export control, and a direct GET to an export URL returns 403.
4. As a manager, on Technician Performance I can export/drill only for technicians inside my organization unit subtree; any other technician id returns 403 (same rule as the screen).
5. As a technician, I can export/drill only my own performance rows, and the drill list contains only tickets/SPK assigned to me.
6. As an admin on Business Metrics I can click "Revenue paid" and see the paid invoices that make up the number, with a visible tie-out (N invoices, Σ paid_amount equals the figure).
7. As an admin I can click MRR / Active / Suspended / Terminated / New / Churn and see the subscriptions behind each number (company-wide), with each row linking to the customer/subscription page only if I hold `customer.subscription.view`.
8. As a finance user I can click Outstanding and reach Receivables (and/or the sent/partial/overdue invoice list) filtered consistently, and see the difference, if any, called out.
9. As an NOC user on SLA Compliance I can click Resolved / Compliant / Breached and see the tickets, including `resolved_at`, `sla_deadline`; a breached ticket count equals the row total.
10. As an NOC user on Asset Utilization I can click a status, a location row, Customer-linked, Retired or Installations and see the assets/installations.
11. As an inventory manager on Stock Card I can export the *whole filtered ledger* (not just the page), see each row's reference as a link to the originating SPK item / inventory request when I hold the target's view permission, and jump to Stocks/Movements for the same product/location.
12. As an auditor I can export the Audit Log server-side with the active filters (user/module/event/date/search); properties are included only as on screen.
13. As any exporter I am told when the file was truncated at the row cap (not silent).
14. As a user of another company I never see or receive a row from the first company in any export or drill list (A/B proven).
15. As a user without the source module's view permission, a drill link is absent and the drill URL returns 403, even though I hold `report.view`.
16. As a keyboard/320px user, export menu and drill links are reachable, named, and do not cause horizontal overflow in light/dark.

## Implementation Decisions

(No file paths; module placement = Reporting module unless noted.)
- **Permission model:** introduce `report.export` (documented in the business spec but never seeded). Export requires `report.export` AND the report's own gate (`report.view`; `report.technician` + existing technician scope; `report.audit.view`). Naming follows `{module}.{action}`. Seed to admin and manager (spec table), not staff/technician; update seeder, permission catalogue test, and ship a data migration/seed run for existing databases [INFERENCE: production roles need explicit sync].
- **Drill-down permission:** drill source endpoints are Reporting-owned, require the report gate and the source module view permission + its policy scope (ticket/SPK handler own-rows scope reused, not reimplemented loosely): subscriptions → `customer.subscription.view`; invoices → `billing.view`; tickets → `ticket.view` + TicketPolicy scope; SPK → `spk.view` + WorkOrderPolicy scope; assets/installations → `network_asset.view`; stock movements → at least current `report.view` (parity with Stock Card today) with links to inventory/SPK only when the target permission is held; audit rows → `report.audit.view`; technician drill additionally `canSelect` + controller actor authorization.
- **Query design:** each aggregate gets a named *source query* returning an Eloquent `Builder` built with explicit company scoping (`forCompany`), used by both the existing aggregate (no change to expression text/result) and the drill list, so count/sum tie-out is structural. Eloquent-only for all new code (no new raw SQL); existing aggregate raw expressions stay unchanged and listed as known non-exceptions. Quirks reproduced, not fixed, and labelled in UI (e.g. Business SLA is all-time; subscription status counts/MRR/outstanding ignore the date range; `by_category` and `by_location` ignore filters; null `sla_deadline` counts as non-compliant).
- **Export design:** a report-export layer in Reporting that (a) for row-level reports (Stock Card ledger, Audit Log, drill source lists) streams through `ExportQuery` with a Builder, mapping columns explicitly (no whole-row JSON blobs), cap `config('exports.max_rows')`; (b) for aggregate summary exports builds a metric/value table and uses the same CSV (with BOM + formula escaping) and Dompdf view. `streamCsvFromRows` must gain BOM/escaping or be avoided. Formats: CSV + PDF only; `ExportMenu` must be passed explicit `formats` (no "Export Excel"); remove the Audit Log client-side export and JSON-as-xlsx. Truncation indicator on CSV/PDF and a response signal.
- **Routes/props (Inertia):** export route per report (spec: `/admin/reports/{type}/export`), drill source routes per metric key; pages receive `can.export` plus per-metric link descriptors (route + filter params) only for metrics the actor may drill; source lists render with canonical `DataTable`/`useServerTable` (server pagination, sort allowlist, `withQueryString`). Controllers stay thin; logic in Query classes/Resources; Policy checks via existing policies or `Gate`.
- **Schema:** none expected. Optional index review for report predicates (tickets `resolved_at`, work_orders `completed_at`, invoices `issue_date`) is a follow-up, not part of this contract [INFERENCE: no query-plan evidence gathered].
- **Reconciliation links:** links + tie-out only (see decision Q6); no opening balance computation.
- **Docs:** update `docs/business/reporting.md` permission/export contract, CHANGELOG, TASKS/ledger on closure.

## Testing Decisions

- Seams: (1) Query unit/feature level — for every aggregate, `source count == aggregate`, `Σ == sum aggregate`, with date-boundary (last day inclusive), soft-deleted rows, other-company rows; (2) controller feature level — permission matrix per export/drill (admin, manager, staff, technician, handler without assign, user lacking source permission, inactive actor), tenant A/B, validation fallback, CSV content parity with the on-screen rows, PDF returns `%PDF`, truncation marker at a lowered cap, CSV formula-cell escaping; (3) `ExportQuery` unit tests for the new helper behaviour; (4) E2E (canonical `inbils_test`, serialized): per report export CSV/PDF via `page.request.get`, drill click-through with tie-out text, no `Export Excel`, 320/1280 light/dark overflow, keyboard operation — prior art `datatable-d.spec.ts`, `reporting-stock-card.spec.ts`, `reports-audit-log.spec.ts`.
- Prior art to mirror: `TicketServerTableExportTest` (handler/inactive-actor scope), `AuditLogTenantIsolationTest`, `TechnicianPerformanceAuthorizationTest` (manager org scope incl. LIKE-escaping regression), `ReportQueryTest`; DB identity gate `TestDatabaseIsolationTest`; DB `inbils_test` only.
- Red-before evidence required (project convention): e.g. report.view-only user gets 403 on export and drill; drill rows ≠ aggregate on a seeded mismatch must fail before shared predicates.

## Out of Scope

- Opening/closing balance or any new Stock Card computation (P1 exclusion stands unless Q6 says otherwise).
- Changing any existing aggregate formula, filter semantics, or visual (defects found are reported, not fixed here).
- Spreadsheet/XLSX writer or `maatwebsite/excel` dependency (unless Q3 changes).
- `UnitPerformanceQuery`/Unit report, Reporting V2 (formula versioning, granular capabilities, data dictionary), Head Company consolidated reporting, customer-sales-attribution/KPI sales reports, persona dashboards.
- Scheduled/async/emailed exports, export history UI.
- Invoice Show embedded tables and customer embedded invoice history (still excluded by ledger).
- Multi-company/Head Company context (single-company v1 stands).

## Dependencies / ordering vs other lanes

- Prerequisite satisfied: Reporting P1 (`t_316be420`…`t_9e203f31`) is done; this lane builds on its Stock Card server table and Audit Log guard.
- **Reporting V2** (`t_1d565698`, `t_d698fabf`, `t_8aa097cc`, `t_ad34cbf5`, `t_a32e031e`: formula-version, granular capability, data dictionary): shares permission design (`report.business/asset/sla/stock_card/audit_log/export` in the spec). Decide capability names once; recommend V2's capability decision first or jointly, otherwise `report.export` may be renamed. Also V2 may change formulas that drill-down predicates mirror (ties are structural, so a formula change updates both).
- **Head Company** (`t_25322033` "consolidated reporting pilot dan export parity", `t_3e41cec6` "report reconciliation"): depends on the export layer from this lane; keep single-company v1 here and run P2 export first.
- **P2 persona dashboard** (`t_a5536496`, `t_0f437faa`, `t_f1c27ea9`, `t_336a4dc4`, `t_09021a86`, `t_40dc90eb`): [INFERENCE] exception counts will want the same source-row endpoints; run after this lane.
- **Role persona/SoD** lane: seeding `report.export` per persona must follow its role matrix.
- Sequencing within lane is a strict chain (Hermes links confirm). Execution gates: serialized `inbils_test` only; single writer; independent QA before reviewer; no Hermes mutation.

## Additional defects observed (not part of the contract unless approved)

- `Reports/Index.tsx` Audit Log card lacks a `report.audit.view` guard.
- `ExportMenu` default formats include fake `xlsx`; `ExportQuery` xlsx is TSV; `streamCsvFromRows` unescaped.
- Ticket `sla_breached` filter semantics (open & past deadline) differ from SLA report breach (resolved after deadline) — do not reuse it for drill-down.
- `AssetUtilizationQuery::by_location` groups by location name (same-name locations merge) and ignores filters; `SlaComplianceQuery::by_category` ignores date/category filters.
- Business/SLA/Asset queries rely on the implicit global scope; when company context is null the scope is not applied (fail-open).

## Approved decisions

Owner approved 2026-10-10: the RECOMMENDED option of every Owner decision above is the contract. Implementation record (backend `t_63055b4e`, UI `t_9de05904`; planner `t_a71f5680` superseded by this spec):

1. **A** — P1 "no exports" boundary lifted for this lane. Export: Business, SLA, Asset, Technician, Stock Card, Audit Log. Drill: Business, SLA, Asset, Technician. Sales KPI and the Data Dictionary stay outside this contract.
2. **A** — Technician Performance included read-only. Screen authorization is reused through `TechnicianReportAccess` (admin any technician, manager own unit subtree with LIKE-escaped path, technician self).
3. **A** — CSV + PDF only, through `ExportQuery` and Dompdf. `ExportMenu` always gets `formats={['csv','pdf']}`; `format=xlsx` is refused. No dependency added.
4. **A** — `report.export` (admin, manager) AND the report's own capability (`report.business.view`, `report.sla.view`, `report.asset.view`, `report.stock_card.view`, `report.technician` + scope, `report.audit.view`). Seeded by the role/SoD lane.
5. **A** — report-owned source-row endpoints `GET /admin/reports/{business|sla|asset|technician}/drill/{metric}` (+ `/export`). Aggregates and lists read the same builders in `Modules/Reporting/app/Queries/Sources/*`; the formulas are untouched (`ReportFormulaV1Test` passes).
6. **A** — every drill list shows a tie-out (rows, sum or share, report figure, verdict). Outstanding links to Receivables and prints the difference; Stock Card rows link to the SPK or inventory request they reference and to Stocks and Movements, each only when the viewer may open the target.
7. **A** — v1 quirks reproduced and labelled: export files carry Scope, Filters and Version per figure; a drill page shows the registry definition of the figure.
8. **A** — cap `config('exports.max_rows')` (5000). CSV ends with a `# Export truncated at N rows...` marker row; every export sends `X-Export-Truncated` and `X-Export-Row-Cap`; the PDF prints its capped note. PDF memory at 5,000 rows is not measured.
9. **A** — Audit Log CSV carries `properties` exactly as on screen, the PDF omits them, no export-event logging. The client-side page-only CSV and the JSON-as-xlsx option are removed.
10. **A** — subscriptions are listed by a report-owned read-only list behind `customer.subscription.view`.

Decided while implementing (not spelled out above):

- Business keeps its 6 cards (Reporting V2 owner decision): drill links sit on those cards only. The hidden Business figures (terminated, new, churn, recurring, one-time, installations) still have drill and export routes and appear in the summary file.
- Story 5: a technician drills their own rows with `report.technician`; a file needs `report.export`, which the seeded technician role does not hold.
- Active workload is two lists (SPK, tickets) whose row counts add up to the figure.
- Ticket and SPK handlers (may start/resolve, may not assign) see only rows assigned to them, as in the module lists; their tie-out reports "limited" instead of "ties out".
- `ExportQuery` now loads relations in chunks (`lazy`) instead of `cursor()`, so an exported relation costs one query per chunk, not one per row; `streamCsvFromRows` (no callers, unescaped) is replaced by `ExportQuery::fromRows()`.

## Closure

Done 2026-10-10: QA + reviewer PASS/APPROVED after fixes. Evidence in ledger "Role persona/SoD & P2 export closures (2026-10-10)".
