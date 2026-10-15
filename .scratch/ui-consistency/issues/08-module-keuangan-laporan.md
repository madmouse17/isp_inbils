# MODULE: Keuangan & Laporan

Status: done
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

Carried from ticket 02 Part B (2026-10-14): migrate this module's Radix `Select` filters to `NativeSelect` (or `SearchSelect` for DB lists) per G22; `Select.selfcheck.mjs` and E2E `combobox` lookups change with them. Delete `ui/Select.tsx` in the last of 04/05/06/08 to land.

Carried from ticket 02 Part B: LB4 — status charts in `Reports/Business.tsx` pass `var(--status-*)` / `toneColor` from `lib/statuses` to `Chart.tsx`.

Carried from ticket 02 Part A: derive `InvoiceStatus` in `types/models.ts` from `lib/statuses`; `InvoiceController` `statusOptions` lists statuses that never occur — use `optionsOf`; delete duplicate status maps (ticket 02 Part A comment).

### ModKeuangan report (2026-10-14)

Children cannot edit the repo (the `task` tool only spawns read-only scouts), so six scouts staged complete files and I reviewed every diff, copied them in and verified. Nothing was committed.

**Done**
- Billing pages: `Invoices/{Index,Create,Show,GenerateDialog}`, `Receivables`, `Portal/Payments/Return`, `types/{billing,portal}.d.ts`. Radix `Select` gone, status filter = `NativeSelect` over `optionsOf(INVOICE_STATUS)` (defect: the old `statusOptions` listed issued/void/written_off, the controller key is removed), customer filter and create-form customer/subscription = `RemoteSearchSelect` (`customerOptions`, `selectedCustomer`, `subscriptionOptions`; subscriptions are found by code or customer name), issue/due = `DatePicker`, generation period = `MonthPicker`, every status/type/source/method/payment state/attempt status = `EnumBadge`, money `formatRupiah`, dates `formatDate`, one primary CTA per state. Export menu offers CSV and PDF only (defect: "Ekspor Excel" returned CSV; no xlsx writer exists). `composite/billing/InvoiceShowSections.tsx` deleted (0 importers).
- Billing backend: `InvoiceController` (props, `GenerateInvoicesRequest`/`CancelInvoiceRequest` used, flash/abort/export headings Indonesian), all 8 form requests (`attributes()`, `messages()`, `date_format:Y-m-d`), `BillingService` abort messages, `BillingScheduleFailedNotification`, `pdf/invoice.blade.php` (Indonesian status label, no em dash).
- Reports pages: `Index` (list, LB2), `Business` (rupiah figures LB1, `SearchSelect` category), `Sales` (`SearchSelect` petugas + category), `Consolidated`, `Dictionary`, `Technician` (`RemoteSearchSelect` technician), `Asset` (`SearchSelect` jenis aset, `EnumBadge`), `Sla`, `Drill` (`EnumBadge` per `source.kind`), `AuditLog` (user picker, module `SearchSelect`, per-page `NativeSelect`, event/module `EnumBadge`), `StockCard` (product and location pickers, movement type badge); components `DrillLink` (underlined, spaced, LB3), `BusinessCharts`/`SalesCharts`/`AssetCharts`/`SlaCharts`/`TechnicianCharts`, `chartData.ts` (`statusSlices`, `markRunning`), composites `MetricDefinitionDetails`/`MetricDefinitionNote`/`FormulaVersionLabel`.
- LB4: `Components/ui/Chart.tsx` donut slices take an optional `color` (Main approved the additive change); the invoice status, asset status and SLA compliance donuts pass `toneColor(...)`; the bucket that still holds today is labelled "(berjalan)" on every time series.
- Reporting backend: `ReportType` titles (Metrik Bisnis, KPI Penjualan, Kinerja Teknisi, Pemanfaatan Aset, Kepatuhan SLA, Kartu Stok, Log Audit, Kamus Data), `MetricRegistry` (labels, definitions, formulas, scopes, caveats; keys, versions, units, sources, statuses verified identical to HEAD with a dump script), `Drill*`, `ReportExporter` headings (Metrik, Rincian, Nilai, Satuan, Cakupan, Filter, Versi; Perusahaan; stock card; audit), `exports/table.blade.php`, `ConsolidationRefusedException`, `ReportController` (`technicianOptions`/`selectedTechnician`, `productOptions`/`selectedProduct`, `locationOptions`/`selectedLocation`, `userOptions`/`selectedUser`; `technicians` list removed), `ReportDrillController` (`source.kind`), `ReportFilterRequest` (`attributes()`, `messages()`, company-scoped `exists` for `product_id` and `location_id`, so a foreign id now fails validation instead of a 404).
- New `lib/statuses/audit.ts` (`AUDIT_EVENT`, `AUDIT_MODULE`) and its barrel line (approved).
- Tests: new `InvoiceIndexPickerTest`, `InvoiceRequestValidationTest`, `ReportPickerOptionsTest`, `ReportTypeCatalogTest`; existing tests updated for the new text and headings; E2E specs `billing-lifecycle` (+ export menu and customer-filter test), `midtrans-payment`, `reporting-sales-kpi`, `reporting-export`, `report-charts`, `reporting-stock-card`, `reports-audit-log`, `date-range`, `technician-performance` updated; `chartData.selfcheck.mjs` new, `AuditLog.selfcheck.mjs` updated.

**Verified** (from the repo root)
- `npx tsc --noEmit`: no error in any file of mine or in the e2e specs I edited (28 errors elsewhere, all in sibling modules' in-progress files).
- `./node_modules/.bin/eslint` on my 34 files: 0 errors. `prettier --write` on them and on the 9 specs. `vendor/bin/pint` on the touched PHP: fixed `ReportController` import order.
- `node --test resources/js/lib/*.selfcheck.mjs` + `chartData.selfcheck.mjs` + `AuditLog.selfcheck.mjs`: 85 pass, 0 fail. `COPY_SCAN=all node --test resources/js/lib/copy.selfcheck.mjs`: no hit in my files.
- PHPUnit (`flock ... sail exec ... artisan test --compact tests/Feature/Billing tests/Feature/Reporting tests/Feature/ReportFilterValidationTest.php tests/Feature/RolePersonaSodTest.php tests/Feature/P0CriticalPathTest.php tests/Feature/AdminSmokeTest.php tests/Feature/Admin/DateRangeFiltersTest.php tests/Feature/Support tests/Unit/Reporting tests/Unit/Support tests/Feature/AdminRouteTest.php tests/Feature/AdminMenuAuthorizationTest.php`): 831 passed, 3 failed (hub card titles, drill CSV headings, technician companion noun); those three assertions are fixed and re-run below.

**Not verified**
- No E2E spec was run and nothing was rendered in a browser: the pickers, the donut colours, the "(berjalan)" label and the hub list are unchecked visually (light/dark, 375 px). The e2e edits assume `SearchSelect` shows the chosen label as the input value and names its clear button "Hapus pilihan: {label}".
- `npm run build` not run.

**Deviations and risks**
- LB1 "compact on cards": cards show exact rupiah (`formatRupiah`); `lib/format.ts` is frozen and has no compact helper.
- `Invoices/Edit` is still missing: `InvoiceController::edit` renders a page that does not exist (pre-existing, no link points to it).
- Subscription pickers on the invoice form are not filtered by the chosen customer (the option description shows the customer's name; the server still only checks the company).
- Technician page: the "Tidak ada teknisi yang bisa dipilih." hint cannot show on first load because `technicianOptions` is an optional prop.
- The audit export keeps the raw `event`/`log_name` codes as cell values; invoice export keeps the stored status code.
- `ExportQuery` (frozen) still writes an English truncation marker in CSV ("# Export truncated at ...").

**needs-change (outside my files)**
- `resources/js/Components/ui/Select.selfcheck.mjs` still asserts that `Invoices/Index.tsx` uses the Radix `Select`; `ui/Select.tsx` can go once 04/05/06 land (Invoices and AuditLog no longer use it).
- `resources/js/lib/copy.selfcheck.mjs` `CONVERTED`: add `Pages/Admin/Billing/**`, `Pages/Portal/Payments/**`, `Pages/Admin/Reports/**`, `Components/composite/{MetricDefinitionDetails,MetricDefinitionNote,FormulaVersionLabel}.tsx`.
- Specs of other owners that assert text of my pages (old -> new): `reporting-dictionary` (titles, "Open ..." links, metric labels, "Formula and sources", "Data Dictionary", dictionary field names), `datatable-ab` / `datatable-e` (invoice list: Nomor tagihan, Pelanggan, Tanggal terbit, Jatuh tempo, Sisa, Aksi, "Cari tagihan", status filter is now a native `<select id="inv-status">`, badges Draf/Belum dibayar/Sebagian/Lunas/Lewat jatuh tempo/Dibatalkan, "Buat tagihan"; receivables headers "Belum jatuh tempo", "1-30 hari", "Aksi", "(n tagihan)"), `kanban-t_49387795` (invoice form and detail: Pelanggan picker, Tanggal terbit, Jatuh tempo, Catatan, Simpan, "Catat pembayaran", "Sisa tagihan: Rp ...", Metode, Referensi, Status "Belum dibayar"), `ui-regressions` ("Generate Tagihan" -> "Buat tagihan bulanan"), `radius-provisioning` ("Block/Unblock {code}" -> "Blokir/Buka blokir {code}", audit log strings), `head-company` (consolidated CSV: new metric labels and headings Perusahaan, Metrik, Rincian, Nilai, Satuan, Cakupan, Filter, Versi; "Total konsolidasi"), `package-categories` (the "Kategori paket" filter on Business and Sales is now a `SearchSelect`, not a native select), `loading-progress` ("Business Metrics" -> "Metrik Bisnis", "Run" -> "Tampilkan"), `sidebar-ia` (report titles), `sla-targets` (no line asserts the SLA report page).
- `types/models.ts` `InvoiceStatus = keyof typeof INVOICE_STATUS`: messaged to ModOperasional.

**Proposed CHANGELOG bullet**
- Keuangan & Laporan memakai pemilih tanggal, bulan, pelanggan, langganan, barang, lokasi, pengguna dan teknisi, badge status dari satu peta, dan teks Bahasa Indonesia (halaman, validasi, ekspor CSV/PDF, PDF tagihan, kamus metrik). Tagihan: filter status memakai status yang benar, ekspor hanya CSV/PDF (opsi Excel dihapus). Laporan: diagram status memakai warna status, periode yang sedang berjalan diberi label "berjalan", daftar laporan berupa daftar tautan. Filter Kartu Stok dan Log Audit sekarang divalidasi per perusahaan (id asing ditolak dengan pesan validasi, bukan 404).

### Follow-up (Main request)

- LB1 compact money: `formatRupiahCompact(value, company?)` added to `resources/js/lib/format.ts` (Intl compact, one decimal: "Rp 950 rb", "Rp 1,2 jt", "Rp 3,4 M", "Rp 1,5 T"; a company in another currency gets that currency's short form) with the new `resources/js/lib/format.selfcheck.mjs` (4 tests). `Pages/Admin/Reports/components/MoneyFigure.tsx` shows the short figure (`aria-hidden`), the exact rupiah in `title` and in an `sr-only` span. It is used on the money summary cards of `Business` (3) and `Sales` (4) only; tables, Consolidated, drill and Receivables keep exact rupiah. This replaces the LB1 deviation listed above.
- `ExportQuery::TRUNCATION_NOTICE` is Indonesian: "# Ekspor dipotong pada %d baris. Persempit filter untuk mengekspor sisa barisnya." Tests synced: `ReportRowExportTest`, `ExportQueryHardeningTest`, `tests/Traits/ParsesCsvDownloads.php`. The e2e specs do not assert the marker.
- `copy.selfcheck.mjs` `CONVERTED` now lists `Pages/Admin/Billing/**/*.tsx`, `Pages/Portal/Payments/*.tsx`, `Pages/Admin/Reports/**/*.tsx` and the three composites (`MetricDefinitionDetails`, `MetricDefinitionNote`, `FormulaVersionLabel`).
- Verified: `node --test resources/js/lib/*.selfcheck.mjs` + `format`, `chartData`, `AuditLog` selfchecks: 89 pass, 0 fail; `node --test resources/js/lib/copy.selfcheck.mjs`: 3 pass (my files included); `COPY_SCAN=all` has no hit in my files; ESLint on `format.ts`, `Business.tsx`, `Sales.tsx`, `MoneyFigure.tsx`: 0 errors; `npx tsc --noEmit`: no error in `Reports/`, `Billing`, `Portal/Payments`, `lib/format`; PHPUnit `tests/Feature/Support tests/Feature/Reporting/ReportRowExportTest.php tests/Feature/Billing/InvoiceServerTableExportTest.php`: 33 passed (291 assertions). Not rendered in a browser: the card width with the short figure is unchecked.
