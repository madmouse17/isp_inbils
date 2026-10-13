# MODULE: Keuangan & Laporan

Status: ready-for-agent
Role: fullstack
Blocked by: 01, 02, 03
Spec: `../spec.md`

## Scope (owned files)

Pages `Admin/Billing/**` (invoices, receivables, generate dialog, payment/item/cancel/reverse dialogs), `Admin/Reports/**` (all reports, dictionary, drill, audit log, stock card), `Portal/Payments/**`; `Modules/Billing/**` and `Modules/Reporting/**` web controllers/requests/exports (`ReportExporter` headings, PDF views `resources/views/pdf/invoice.blade.php`, `exports/table.blade.php` headings), `BillingScheduleFailedNotification`, `MetricRegistry` labels shown in the UI.

Specifics: invoice issue/due → `DatePicker`, period → `MonthPicker`, customer/subscription → `RemoteSearchSelect`; invoices customer filter → `RemoteSearchSelect`; Stock Card product/location and Audit Log user → pickers (keeps ticket 01's validation); invoice type/source/status, payment and payment-attempt status → `EnumBadge`.
Defects (R7): invoice Status filter lists statuses that do not exist; "Export Excel" returns CSV (either make it real xlsx with the existing exporter or drop the Excel option: drop unless an xlsx writer already exists).

E2E: `billing-lifecycle`, `midtrans-payment`, `reporting-*`, `report-charts`, `date-range`, `datatable-e`, `datatable-ab` (invoice parts).

## Per-page checklist (apply to every owned page)

1. Dates → `DatePicker` / `DateTimePicker` / `MonthPicker` / `TimeInput` (spec Q1, R2); requests use `date_format` (R8).
2. Every reference to another record → `SearchSelect` (small catalogue) or `RemoteSearchSelect` + `SearchOptions` (large table) per Q3/R4; no typed ids; `Rule::exists(...)->where('company_id', …)` on the request.
3. Every user-visible string Indonesian per Q4 and the glossary R6: page title/subtitle, labels, placeholders, buttons, column headers, empty states, dialogs, flash messages in the owned controllers, FormRequest `messages()`/inline `$fail`, notification subjects/bodies, export headings.
4. Every status/type/priority value → `EnumBadge` with the domain map from `lib/statuses/*` (R5), also in filter options; delete local status maps.
5. Update the E2E specs listed below to the new text and controls; add assertions where a control changed (picker instead of input).
6. Fix the listed defects (R7) and add a regression test for each.

## Antislop findings (A1)

TG1 invoice detail money/dates via `formatRupiah`/`formatDate`, delete the duplicate `composite/billing/InvoiceShowSections.tsx` sections the page re-implements; TG2 status casing via `EnumBadge`, one primary CTA; LB1 report figures via `formatRupiah` (compact on cards); LB2 report hub as a list (title, description, one link); LB3 drill links spaced and underlined; LB4 status donut uses the status palette, partial last month labelled "berjalan".

## Acceptance criteria

- [ ] Checklist done for every owned page; `tsc`, ESLint, copy selfcheck (no denylisted English in owned files) clean; focused PHPUnit green; owned E2E specs type-check (orchestrator runs them).

## Comments
