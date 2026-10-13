# BACKEND: half-range semantics and validation outside Reporting

Status: done
Role: backend
Blocked by: none
Spec: `../spec.md`

## Scope (owned files)

- `app/Http/Requests/Concerns/*` or the module's existing FormRequests: add `date_from`/`date_to` rules (`nullable|date_format:Y-m-d`, `date_to after_or_equal:date_from`) to Billing invoices index (create `IndexInvoiceRequest` if the controller takes `Request`), Dispatch queue and My Work (same pattern). Indonesian messages.
- Half-range semantics (Q4) where those three apply `whereDate`: from-only → `>= from`; to-only → `<= to`.
- `Modules/Reporting/app/Support/AppliesDateRange.php` (or wherever it lives): same half-range semantics; `ReportFilterRequest` already validates.
- Tests: feature tests for the three index endpoints (`to < from` → 422; from-only filters; to-only filters) and for the Reporting half-range.

## Acceptance criteria

- [x] Focused PHPUnit green; Pint clean.

## Comments

2026-10-13 BeRules. One shared `App\Http\Requests\DateRangeIndexRequest` (`date_from` nullable `date_format:Y-m-d`, `date_to` same + `after_or_equal:date_from`, Indonesian messages) type-hinted by `InvoiceController::index`, `DispatchQueueController::index`, `MyWorkController::index`. Their queries already applied each end independently (`>= from`, `<= to`), so only validation was missing.
`AppliesDateRange::dateRange()` now returns `[?start, ?end]|null` (null only when neither end is set); `applyDateRange` applies whichever bound exists. `SalesKpiQuery::within()` handles null bounds. `BusinessSources` new_subscriptions/churn stay 0/empty only when no range at all. `ReportExporter::scope` prints `Period: from X` / `Period: up to X` / `Period: no range set`; `MetricRegistry` period caveats reworded.
Tests: new `tests/Feature/Admin/DateRangeFiltersTest.php` (422 on inverted/malformed, half and same-day ranges pass, one-end filtering on Invoices, Dispatch, My Work); half-range tests rewritten in ReportFormulaV1Test (Business, SLA, Asset), SalesKpiFormulaV1Test, SalesKpiReportTest, ReportDrillTest, ReportExportContentTest.
Evidence: `artisan test --compact tests/Feature/Admin/DateRangeFiltersTest.php tests/Feature/Reporting tests/Feature/ReportFilterValidationTest.php tests/Feature/Billing/InvoiceServerTableExportTest.php tests/Feature/Admin/MyWorkTest.php tests/Feature/Admin/DispatchQueueTest.php` → 1 failed (SalesKpiFormulaV1Test old "single date ignored"), 332 passed; after rewriting it: `SalesKpiFormulaV1Test + DateRangeFiltersTest` → 34 passed (416 assertions). `vendor/bin/pint --dirty --format agent` → passed.

2026-10-13 BeRules, follow-up (orchestrator decision): `AppliesDateRange::dateRange()` now reads From/To as calendar days of the current company's timezone (`CompanyService::current()?->timezone`, else app timezone) when no zone is passed, converted to UTC for the query. New `applyDateOnlyRange()` for DATE columns (invoice `issue_date`, subscription `activation_date`: plain `>=`/`<=`, no zone shift), used by `BusinessSources::paidInvoices`, `BusinessMetricsQuery::invoiceStatus`, `SalesKpiQuery` activated/invoiced. `AuditLogQuery` now goes through `applyDateRange` for every case. Test: `ReportQueryTest::test_the_period_is_read_as_calendar_days_of_the_company_timezone` (Jakarta, 2026-10-13 16:30 UTC = 23:30 WIB inside `date_to=2026-10-13`; boundaries 16:59:59/17:00:00; From-only and To-only). Legacy UTC-edge fixtures (ReportFormulaV1, SalesKpiFormulaV1, ReportDrill, ReportExportContent, ReportTechnicianScope, TechnicianPerformanceAuthorization, ReportQuery) pinned to `timezone => 'UTC'` companies. Evidence: `artisan test --compact tests/Feature/Reporting tests/Feature/ReportFilterValidationTest.php tests/Feature/Admin/DateRangeFiltersTest.php` → 306 passed (7324 assertions); pint passed.
