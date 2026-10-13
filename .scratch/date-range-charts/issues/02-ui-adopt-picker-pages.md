# UI: adopt DateRangePicker on the 11 range filters

Status: done
Role: ui
Blocked by: none (build against the picker contract; message FeCore for details)
Spec: `../spec.md`

## Scope (owned files)

- Pages: `Pages/Admin/Reports/{Consolidated,StockCard,AuditLog}.tsx`, `Pages/Admin/Billing/Invoices/Index.tsx`, `Pages/Admin/Dispatch/components/FilterBar.tsx`, `Pages/Admin/MyWork/components/FilterBar.tsx` (dedupe: move the shared bar to `Components/composite/WorkFilterBar.tsx` only if both are byte-identical except props; otherwise leave two files).
- The five chart reports (`Business`, `Sales`, `Sla`, `Technician`, `Asset`) are owned by ticket 05, which also swaps their date inputs. Do not touch them.
- AuditLog: remove the inline `handleQuickDate`/`toDateInputString` presets (the picker has them).
- "Periode:" texts use `formatDate(value, company)`.
- E2E: new `tests/e2e/date-range.spec.ts` (spec testing decisions) and update every existing spec that fills `date_from`/`date_to` inputs (grep `date_from|Dari Tanggal|inv-from|getByLabel\(/From|/To`), including `support/*.ts` helpers.

## Acceptance criteria

- [x] Every listed page uses `DateRangePicker`; URL params remain `date_from`/`date_to` ISO.
- [x] `tsc`, ESLint clean; E2E spec written (orchestrator runs it).

## Comments

- FeAdopt 2026-10-13: six pages adopt `DateRangePicker` (label "Periode"; Consolidated/StockCard keep local state + Run, Invoices/Dispatch/MyWork push via `set`/`setMany`, validation `errors.date_from ?? errors.date_to` shown on the last three). Dispatch and My Work bars differ (status options, ids, copy), so no shared `WorkFilterBar`. AuditLog: inline presets, `toDateInputString`, inverted-range alert removed; `AuditLog.selfcheck.mjs` updated (5/5 pass).
- Consolidated "Period:" text: both/from-only/to-only/all-time variants via `formatDate(value, company)`.
- E2E (not run, orchestrator runs): new `tests/e2e/date-range.spec.ts` (8 tests on Audit Log), helper `tests/e2e/support/dateRange.ts`; updated `head-company`, `reporting-stock-card`, `reports-audit-log` specs. Chart-page specs (`reporting-sales-kpi`, `technician-performance`) belong to ticket 05.
- Evidence: `npx tsc --noEmit` shows no error in touched files (remaining errors: missing `Chart`/`ChartLazy` modules from FeCore in flight); eslint on the 6 pages clean; prettier clean; e2e files type-check with `tsc --noEmit --strict --skipLibCheck --moduleResolution bundler --types node <4 specs>`.
