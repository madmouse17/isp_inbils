# UI: charts and picker on Business, Sales, SLA, Technician, Asset

Status: done
Role: ui
Blocked by: none (build against the chart/series contracts; message FeCore / BeSeries for details)
Spec: `../spec.md`

## Scope (owned files)

- `Pages/Admin/Reports/{Business,Sales,Sla,Technician,Asset}.tsx` and their `components/*`: swap the two date inputs for `DateRangePicker`, add the Q5 charts via `ChartLazy` under the cards and above the tables, with `aria-label`s in Indonesian, empty state per R4, currency formatting with `formatRupiah` where the metric is money.
- `resources/js/types/reports.d.ts` (or the existing report types) for `Series`/`SeriesPoint`.
- E2E: new `tests/e2e/report-charts.spec.ts` per the spec's testing decisions; update existing report specs that fill the date inputs (`technician-performance`, `reporting-*`, `p2-*`).

## Acceptance criteria

- [x] `tsc`, ESLint clean; E2E spec written (orchestrator runs it).
- [x] 375 px and 1280 px, light and dark: no horizontal scroll, legend readable.

## Comments

- 2026-10-13 FeCharts. Pages: `Business|Sales|Sla|Technician|Asset.tsx` use `DateRangePicker` (label "Periode", `error={errors.date_from ?? errors.date_to}`), charts sit under the cards and above the tables via `components/{Business,Sales,Sla,Technician,Asset}Charts.tsx` + `ReportChartCard` (role=group named by its heading) + `chartData.ts`. Data comes from the `charts` prop (BeSeries contract) plus `data.status_distribution|by_location|by_category|spk_by_type|tickets_by_category`. `ChartLazy` has its own Suspense skeleton, so pages do not wrap another. Sales: `only` now includes `charts`; period line is `Periode: X – Y | dari X | sampai X | semua waktu` via `formatDate`. Asset status cell and donut share Indonesian labels (`codeLabel`).
- Evidence: `npx tsc --noEmit` rc 0; `eslint resources/js/Pages/Admin/Reports resources/js/types/reporting.ts` rc 0; `prettier --check` clean on every touched file; ad-hoc `tsc --strict` over `report-charts`, `technician-performance`, `reporting-sales-kpi`, `reporting-dictionary` specs and `support/charts.ts` rc 0 (`tsconfig.json` does not include `tests/`). Throwaway `vite build` into /tmp: recharts only in the lazy `Chart-*.js` chunk, page chunks 4-10 kB.
- Render check (throwaway Vite harness in /tmp, deleted): the real five page components with mock props and a stub `AdminLayout`, Chromium at 1280 and 375, light and dark, with data and empty: no document, main or card horizontal overflow, no console errors, every chart drawn, empty box on every chart without data, Run aligned with the field, Tab order Periode → clear → calendar → select → Run → summaries → charts → table.
- Specs: new `tests/e2e/report-charts.spec.ts` + `tests/e2e/support/charts.ts`; updated `reporting-sales-kpi` (half ranges, picker, Tab walk), `technician-performance` (picker, chart settle), `reporting-dictionary` (Business h3 count + 2).
- Not verified: the real app (no `npm run build`/E2E run by me); `report-charts.spec.ts` is the integration proof.
