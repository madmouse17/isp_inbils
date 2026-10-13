# Date range picker and report charts

Status: done
Ledger: `docs/plans/INBILS_OMP_BACKLOG.md` → "ISP roadmap lanes (2026-10-12)" row `date-range-charts`
Source: owner request 2026-10-13 (grill-me): "from/to" date filters should be a date range that can be picked or typed, and reports should show charts, not only text. Owner approved grilling round 1 (Q1–Q8) and asked for implementation of the recommendations.

## Audit (2026-10-13)

- 11 range filters, all two native `<input type="date">`: Reports Business, SLA, Asset, Technician, Consolidated, Sales, StockCard, AuditLog; Billing Invoices; Dispatch queue; My Work. `Components/composite/DateRangeFilter.tsx` exists and is unused.
- Presets exist only in AuditLog (inline helper). Reporting validates `date` + `date_to after_or_equal:date_from`; Invoices, Dispatch and My Work do not. `AppliesDateRange` ignores a half-set range silently.
- No chart library, no chart anywhere, no time-bucketed series: report queries return scalars, status/category maps or per-entity rows.
- Installed in this lane (owner approved the recommendation): `react-day-picker@^10.0.2` (calendar, a11y, no CSS framework), `date-fns@^4.4.0` (its dependency, used for parse/format), `recharts@^3.10.1` (SVG charts, React 18). `npm audit`: 0 vulnerabilities. Install uses `--legacy-peer-deps` as the Dockerfile does (pre-existing eslint peer mismatch).

## Decisions

Q1–Q8 approved by the owner; R1–R7 taken by the assistant.

- **Q1 DateRangePicker.** One `Components/ui/DateRangePicker.tsx`: a text input showing `from – to` in the company date format, a popover (existing `@radix-ui/react-popover`) with a two-month `react-day-picker` range calendar, a clear button. Typing is allowed in the same format; an invalid or inverted entry shows the field red with an Indonesian message and is not sent. The dead `composite/DateRangeFilter.tsx` is deleted.
- **Q2 Presets** inside the popover: Hari ini, 7 hari terakhir, 30 hari terakhir, Bulan ini, Bulan lalu, Tahun ini. Computed in the company timezone. AuditLog's inline presets are replaced.
- **Q3 Scope.** The 11 range filters move to the component. Single-date form fields are untouched.
- **Q4 Half ranges.** `from` only = from that day onward, no upper bound (future-dated rows included); `to` only = everything up to that day. The backend applies that instead of ignoring the range. Invoices, Dispatch and My Work get `date` + `date_to after_or_equal:date_from` validation.
- **Q5 Charts** on five reports, below the existing cards and above the tables (tables and numbers stay):
  - Business: line revenue paid + MRR per bucket; donut invoice status.
  - Sales: bar per acquirer (new customers, revenue); line new customers per bucket.
  - SLA: donut compliant/breached; bar per category.
  - Technician: bar SPK by type, bar tickets by category; line SPK completed per bucket.
  - Asset: donut status; bar per location.
  - Dashboard: no chart.
- **Q6 Series.** Reporting queries add buckets. Granularity from the range length: ≤ 31 days → day, ≤ 184 days → week (ISO, Monday), else → month. Buckets are computed in the company timezone and include empty buckets with 0. No range (all time) → month buckets from the earliest row to today, capped at 36 buckets (older months are folded into the first).
- **Q7 Library.** `recharts`, wrapped in `Components/ui/Chart.tsx` (`LineChart`, `BarChart`, `DonutChart` props-only wrappers). Pages never import recharts directly. Colours come from CSS variables `--chart-1`…`--chart-6` declared in `resources/css/app.css` `@theme` with light and dark values. Chart wrappers are loaded with `React.lazy` so non-report pages do not carry recharts.
- **Q8 Export.** CSV and PDF unchanged, no chart in either.
- **R1 Format.** The display and typing format is the company `date_format` (PHP tokens, e.g. `d M Y`). `lib/dateFormat.ts` maps PHP tokens `d j m n Y y M F D l` to date-fns tokens; unsupported tokens fall back to `dd/MM/yyyy`. A node selfcheck covers the mapping. Month and day names are Indonesian (`date-fns/locale/id`).
- **R2 URL contract unchanged.** Query params stay `date_from`/`date_to` as ISO `yyyy-mm-dd`. Specs, exports and drill links keep working.
- **R3 Keyboard and mobile.** The input is a normal text field (Tab, type, Enter applies). The calendar button opens the popover; inside it, react-day-picker's own arrow-key navigation applies; Escape closes and returns focus to the button. Below `sm` the popover shows one month. Minimum tap target 44 px on day cells.
- **R4 Empty states.** A chart with no data renders a muted "Tidak ada data pada periode ini" box of the same height, never an empty axis.
- **R5 A11y of charts.** `accessibilityLayer` on, an `aria-label` per chart naming what it shows, and the table below is the accessible equivalent.
- **R6 Order.** Picker first (tickets 01–03), charts second (04–05), one QA gate (06). Backend and frontend run in parallel against the contracts below.
- **R7 `formatDateTime`** gains the same `company` argument as `formatDate` so "Periode:" texts stop printing ISO. Pages that print the period use `formatDate`.

## Contracts

### Picker component (`Components/ui/DateRangePicker.tsx`)

```ts
export interface DateRange { from: string | null; to: string | null } // ISO yyyy-mm-dd
export interface DateRangePickerProps {
  value: DateRange; onChange: (range: DateRange) => void;
  label?: string; id?: string; error?: string; disabled?: boolean;
  presets?: boolean; // default true
  className?: string;
}
```
- Reads `company.settings.date_format` and `company.timezone` from `usePage().props`.
- Calls `onChange` only with a valid range (both ISO or null); never emits an inverted range.

### Series contract (Reporting)

```ts
interface SeriesPoint { bucket: string; label: string; [metric: string]: number | string } // bucket ISO start date, label per granularity
interface Series { granularity: 'day' | 'week' | 'month'; points: SeriesPoint[] }
```
PHP: `Modules\Reporting\Support\TimeBuckets` with `static function forRange(?CarbonImmutable $from, ?CarbonImmutable $to, string $tz, ?CarbonImmutable $earliest = null): TimeBuckets` exposing `granularity()`, `keys()` (ordered ISO bucket starts), `keyFor(CarbonInterface $at): string`, `label(string $key): string` (Indonesian: `13 Okt`, `W42 2026`, `Okt 2026`), and `sqlExpression(string $column): string` (MySQL `DATE_FORMAT(CONVERT_TZ(...))` with named zones, parameter-free). Queries group by the SQL expression, then fill missing keys with zeros in PHP. Month series are capped at 36 buckets.

Report payload additions live in a separate top-level Inertia prop `charts` (not inside `data.*`: the metric registry tests force every `data` key to be a registered metric, and the consolidated report would otherwise need a consolidation rule per key). `ReportFilters::charts()` dispatches per report:
- Business: `charts.series: Series` with `revenue_paid`, `mrr` (MRR at bucket end); `charts.invoice_status: Array<{status, count}>`.
- Sales: `charts.series: Series` with `new_customers` (all acquirers, unpaginated).
- SLA: `data.by_category` already; `charts.compliance: {compliant: number, breached: number}`.
- Technician: `charts.series: Series` with `spk_completed`; `data.spk_by_type` and `data.tickets_by_category` already.
- Asset: `data.by_status`, `data.by_location` already as maps; no `charts` prop.

Timezone: `AppliesDateRange` now reads From/To as calendar days of the current company's timezone (DATE columns compare plain days via `applyDateOnlyRange`), so cards, drill-downs, exports and the series buckets agree.

### Chart wrappers (`Components/ui/Chart.tsx`)

```ts
LineChart({ data: SeriesPoint[]; xKey: 'label'; lines: Array<{ key: string; name: string }>; height?: number; ariaLabel: string; valueFormat?: 'number' | 'currency' })
BarChart({ data: Array<Record<string, number|string>>; xKey: string; bars: Array<{ key: string; name: string }>; height?; ariaLabel; valueFormat? ; layout?: 'vertical' | 'horizontal' })
DonutChart({ data: Array<{ name: string; value: number }>; ariaLabel: string; height? })
```

## Testing decisions

- Unit: `TimeBuckets` (granularity by length, DST-free Jakarta boundaries, empty-fill, week labels), PHP→date-fns token mapper selfcheck, picker parse/format selfcheck (pure helpers in `lib/dateRange.ts`).
- Feature: each series query with fixtures spanning buckets; half-range semantics in `AppliesDateRange`; validation on Invoices/Dispatch/MyWork (`to < from` → 422; one date → filtered).
- E2E: `date-range.spec.ts` on Audit Log (type a range, pick with the calendar, use a preset, URL carries ISO, invalid typed value blocked, keyboard: open with Enter, Escape returns focus); `report-charts.spec.ts` on Business and Technician (chart `svg[aria-label]` present with data, empty-state text on a range with no data, dark mode screenshot at 1280 and 375 without horizontal scroll). Existing specs that fill `date_from`/`date_to` inputs are updated to the new control.

## Tickets

01-ui-date-range-picker · 02-ui-adopt-picker-pages · 03-backend-date-range-rules · 04-backend-report-series · 05-ui-report-charts · 06-qa-review-release
