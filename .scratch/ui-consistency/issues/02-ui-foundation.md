# UI: pickers, remote search select, EnumBadge, status maps, shared strings

Status: done
Role: ui
Blocked by: none
Spec: `../spec.md`

## Scope (owned files)

- New `Components/ui/{DatePicker,DateTimePicker,MonthPicker,TimeInput,EnumBadge}.tsx` (+ `ui/index.ts` exports), reusing `lib/dateFormat.ts`/`lib/dateRange.ts` (extend with single-date helpers) and the popover/day-picker styles from `DateRangePicker`.
- `Components/ui/SearchSelect.tsx`: keep the value while typing (Q10); new optional props `onSearch`, `loading`, `minChars`, `selected` (R4). New `Components/composite/RemoteSearchSelect.tsx` (R4 contract).
- `resources/js/lib/statuses/*.ts` for every domain in R5 (+ `optionsOf`), labels from `docs/business/*.md` state names; `index.ts` barrel.
- Shared strings to Indonesian (R6): `ui/DataTable.tsx`, `composite/DataTable.tsx`, `ui/Pagination.tsx`, `ui/ExportMenu.tsx`, `ui/SearchSelect.tsx`, `composite/FilterBar.tsx`, `composite/ConfirmRequestDialog.tsx`, `composite/DataTableActions.tsx`, `ui/Toast.tsx`, `ui/dialog.tsx`, `ui/sheet.tsx`, `ui/MapPicker.tsx`, `composite/IndonesiaRegionFields.tsx`, `composite/CustomerAcquirerField.tsx`, `ui/FileUpload.tsx`, `ui/EmptyState.tsx`, `ui/Topbar.tsx`, `ui/Sidebar.tsx`, `composite/ResourceState.tsx`, `Layouts/AdminLayout.tsx`, `Layouts/GuestLayout.tsx`, `NotificationBell`, `CompanySwitcher`.
- `resources/js/lib/copy.selfcheck.mjs` (spec testing decisions; allowlist the kept terms).
- E2E `tests/e2e/ui-pickers.spec.ts` on the Components gallery page (`Pages/Admin/Components.tsx` is yours for adding demos), plus the shared-string updates in `tests/e2e/support/*.ts` helpers ("Rows per page", "Next", …) used by many specs.

## Antislop findings (A1; audit in agent output AntislopAudit, screenshots were in /tmp/inbils-antislop)

- G01/G02 colour system: `--color-success` #15803d, `--color-warning` #b45309; `DataTableActions` row = name link + one ghost "Ubah" + overflow menu (Hapus inside, behind confirm); create CTAs use `primary`, never `success`/`warning` as action colours.
- G03 `DynamicBadge`/`RoleBadge`: one neutral tinted pill (no hash colours).
- G04 sidebar count badge contrast on the active row; G05 notification bell badge `bg-red-600`, ≥ 11px.
- G06/G07 dark tokens: `--destructive-text` (red-700 light, red-400 dark) for all destructive text; `--input` borders ≥ 3:1 (≈ #7c8aa0 light, #64748b dark); dark `--ring` = primary.
- G08 font (A4, owner 2026-10-14): self-host Figtree 400/500/600/700 as woff2 under `resources/fonts/` (OFL licence file alongside), `@font-face` with `font-display: swap` in `resources/css/app.css`, no Google Fonts or bunny CDN request.
- G09 shadows (A6, owner 2026-10-14): static controls (Button, Badge, Checkbox, IconButton, GuestLayout, Card) carry no shadow; overlays (dropdown, popover, dialog, sheet, toast) may use `shadow-md` and nothing deeper. Record this rule in AGENTS.md "Design System" (replaces the `shadow-xs` sentence).
- G10 Topbar solid (no blur); at most 2 blurred map overlays.
- G11 icon set (A5, owner 2026-10-14): Lucide only. Count on 2026-10-14: 67 files already import `lucide-react`, 30 files import `@heroicons/react` (68 distinct icons, including the sidebar mapping in `resources/js/lib/navigation.ts`, `AdminLayout`, `Sidebar`, `SearchSelect`, `Modal`, `Toast`, `StatCard`, `Breadcrumb`). Map each heroicon to its Lucide equivalent keeping the approved sidebar meaning, then remove `@heroicons/react` from `package.json` (run `npm audit` after the lock change).
- G12 `NativeSelect`: `bg-none` on the select (kills the forms-plugin chevron), width on the wrapper (`wrapperClassName`), no `mt-1` without label; DataTable pagination select fixed.
- G13 one page shell: every page uses `AdminLayout` with `title`; delete `Layouts/AppLayout.tsx`; Topbar title not a heading; `CardTitle` gets an `as` prop; one `<h1>` per page.
- G14 DataTable states: `hasFilters` → "Tidak ada {x} yang cocok" + "Reset filter"; first run → reason + create action; `error` state + "Coba lagi".
- G15 root `ErrorBoundary` (ErrorState + reload) in `app.tsx`.
- G16 Toast: icon + weight per variant; danger `role="alert"`; delete dead `ui/sonner.tsx`.
- G17/G18/G19 identity and auth shell (A3, owner 2026-10-14): text wordmark "Inbils" in Figtree 700 (component `Components/ui/Wordmark.tsx`) replaces `ApplicationLogo` until the owner supplies a logo; delete `Pages/Welcome.tsx`; Login drops the capsule and the three tiles and honours the saved theme; one pre-hydration theme script in `resources/views/app.blade.php`, delete the 7 copied effects.
- G20 no uppercase tracked labels; minimum 12px text.
- G21 touch targets: `md` controls `h-11 sm:h-9` (coarse pointer ≥ 44px).
- G22 `Badge` `variant` typed as a union; add neutral/muted/brand; collapse duplicate primitives (`ToneBadge` ×2, `Select` vs `NativeSelect`, `Modal` vs `Dialog`, `Dropdown` vs `dropdown-menu`, `EmptyState` vs `empty`, `StatCard` ×2); delete dead Breeze `AuthenticatedLayout`, `ResponsiveNavLink`.
- G23 `Alert` renders × for `onDismiss`; G24 in-app "Detail" links are Inertia `Link` without the external icon.
- G25 one `EmptyCell` helper rendering "-" (no em dash) for null values; G26 filler subtitles replaced by a one-line job statement (module tickets supply the text).
- LB4 status charts use the status palette from `lib/statuses`.

## Acceptance criteria

- [ ] Selfchecks for the date/month/time helpers and `optionsOf`; `tsc`/ESLint clean; copy selfcheck clean on owned files; gallery shows every new component in light and dark.

## Comments

### Part B (UiSystem, 2026-10-14): antislop findings, Lucide-only, Figtree, shell, Error page, E2E strings

Part A (UiPickers) is in and the ticket Status is `done`. Rendering and E2E checks of Part B stay with the orchestrator (see Not verified).

**Done**
- G01/G02 `--color-success` #15803d, `--color-warning` #b45309. Button `success`/`warning` removed (a type error now). About 90 call sites moved: create/submit to `primary`, edit to `outline`. `DataTableActions` = ghost "Ubah" (or "Lihat" when the row has no edit) + overflow menu "Aksi lainnya" (Lihat detail, Hapus behind the AlertDialog).
- G03 `DynamicBadge`/`RoleBadge` = one neutral pill. G04 sidebar count (primary-foreground pill on the active row). G05 bell `bg-red-600`, 12px.
- G06/G07 `--destructive-text` (+ `text-destructive-text`; every `text-destructive` text use is swapped, none left in `resources/js`), `--input` 3.5:1 light / 4.2:1 dark, dark `--ring` = primary, `--destructive` light = red-600 (4.62:1 with its foreground), `--muted-foreground` light 43% (4.34 to 5.01:1 on `bg-muted`), `--status-*` tokens.
- G08 Figtree self-hosted: 8 woff2 (latin + latin-ext x 400/500/600/700) and OFL.txt in `resources/fonts/figtree/`, 8 `@font-face` with `font-display: swap`; tarball fetched with `npm pack` into /tmp and deleted; no dependency added.
- G09 no shadow on Button, Badge, Checkbox, IconButton, Card, GuestLayout, Input, Textarea, NativeSelect; overlays capped at `shadow-md` (dialog, sheet, alert-dialog, menus, toast, sidebar drawer). AGENTS.md "Design System" rewritten (shadows, Figtree, Lucide, tokens, touch targets, copy).
- G10 Topbar solid; two blurred overlays are left, both in `LocationsMapView` (the Lokasi map view went from 4 to 2, `MapPicker` has none).
- G11 Lucide only: 27 files migrated, `lib/navigation.ts` typed `LucideIcon` (41 distinct icons, selfcheck passes). `@heroicons/react` and the dead `sonner` removed from package.json/lock (removal only, installed with `--legacy-peer-deps` because the tree already has an eslint peer conflict).
- G12 NativeSelect: `bg-none`, `wrapperClassName`, `mt-1` only with a label, Lucide chevron; pager select fixed.
- G13 `AdminLayout` requires `title`; 13 pages moved off `AppLayout`, which is deleted (their own `<Head>` and double padding removed); Topbar title is a `<p>`; `CardTitle as` (default h2, Dictionary cards `as="h3"`).
- G14 DataTable props `hasFilters`, `onResetFilters`, `itemLabel`, `error`, `onRetry`; first run keeps `emptyDescription`/`emptyAction`; pager hidden at 0 rows.
- G15 `composite/ErrorBoundary` in `app.tsx`. G16 Toast icon + weight per variant, danger `role="alert"`, `sonner.tsx` deleted. G23 Alert renders x for `onDismiss`. G24 four in-app detail links are Inertia `Link` without the icon.
- G17-G19 `ui/Wordmark`, `Welcome.tsx` deleted, Login rewritten (no capsule, no tiles, Indonesian), one pre-hydration theme script in `app.blade.php` (saved choice wins over the system one) + `hooks/useTheme`, the 7 copied effects deleted.
- G20 no uppercase/tracked labels and no text under 12px in the shell and in every page file that had them. G21 `ui/controlSize.ts`: `h-11 sm:h-9 sm:pointer-coarse:h-11` on Button, IconButton, Input, NativeSelect; Button `size="auto"`.
- G22 Badge variants typed (`BadgeVariant` incl. neutral/muted/brand, AA tints); both `ToneBadge` files deleted (callers use Badge); `Dropdown` and `Modal` are thin wrappers over `dropdown-menu`/`dialog`; `EmptyState` is built on `empty`; composite `StatCard`, `FilterBar` (both unused), Breeze `AuthenticatedLayout`, `NavLink`, `ResponsiveNavLink`, root `Dropdown`/`Modal`/`Checkbox`, `ApplicationLogo` deleted.
- G25 `ui/EmptyCell` ("-" announced as "Kosong") in DataTable and UtilisationBar; every `'—'` placeholder is now `'-'` (92 by ast_edit, about 45 by hand) and `code — name` separators are `code - name`. Em dashes remain only in code comments and in `lib/dateRange.ts`'s input-parsing regex.
- A3 identity, A4 font, A5 icons, A6 shadows as above. R6 strings: DataTable, Pagination, ExportMenu, DataTableActions, Toast, dialog, sheet, EmptyState, Sidebar, AdminLayout (Menu navigasi, Menu pengguna, Profil, Mode gelap/terang, Keluar), GuestLayout, SetupLayout, NotificationBell, ErrorBoundary, `Pages/Error.tsx` (rewritten: Indonesian per status, Dasbor or Masuk link, no auth props required).
- E2E updated for every changed visible string: `Baris per halaman`, `Halaman berikutnya/sebelumnya`, `Menampilkan a-b dari n`, `Sebelumnya/Berikutnya` (audit log), `Ekspor`/`Ekspor CSV|PDF|Excel`, row actions (`Aksi lainnya` > `Hapus`/`Lihat detail`, show-only rows `Lihat`), `Menu navigasi`, `Menu pengguna`, `Mode gelap`, `Keluar`, bell label, login (`Masuk`, `Kata sandi`, `Email atau kata sandi salah.`), landing now redirects, CardTitle h3 to h2 (dashboard), separators, `-` cells, gallery route status (Main), UiLocale strings (`Sekarang bekerja di`, 2FA code, validation wording).
- E2E also follows UiPickers' strings and the new SearchSelect DOM (options are `role=option` items in a popover outside dialogs; the field opens on click, typing or ArrowDown): the `Provinsi` / `Kota / Kabupaten` / `Kecamatan` / `Kelurahan / Desa` cascade in 6 specs, `Lintang / Bujur`, `Lintang`, `Bujur`, `Cari`, `Lokasi saat ini`, `Peta untuk memilih koordinat alamat`, `Diperoleh oleh` / `Alasan koreksi` / `Belum ditentukan` / `Hapus pilihan` in `customer-sales-attribution`, `Ganti perusahaan, saat ini:` in `head-company`, and `support/demo.ts` `selectSearchOption` (finds the field by role, picks `role=option`). All 45 spec files load under `npx playwright test --list` (242 tests); none was run.

**Verified** (all from the repo root)
- `npx tsc --noEmit` -> 0 errors. `eslint` on the 153 files I touched -> 0 errors, 3 warnings that were already there. `prettier --write` on them -> clean.
- `node --test resources/js/lib/*.selfcheck.mjs resources/js/Components/ui/Select.selfcheck.mjs` -> 71 pass, 0 fail.
- `npm run build` -> ok; `public/build` has 8 figtree woff2, the CSS has 8 `@font-face` (all `swap`), 0 font CDN URLs, `--destructive-text`, `--status-*`, `pointer-coarse:` variants.
- `grep -rn @heroicons resources/js` -> empty. `npm audit` -> found 0 vulnerabilities.
- Contrast (antislop `check_contrast`, same values as my own formula run):

| token or pair | light | dark |
|---|---|---|
| white on `--color-success` #15803d | 5.02 | same fill |
| white on `--color-warning` #b45309 | 5.02 | same fill |
| white on danger fill / bell #dc2626 | 4.83 | same fill |
| `--destructive-foreground` on `--destructive` | 4.62 | 9.58 |
| `--destructive-text` on page | 6.47 | 7.23 |
| `--destructive-text` on muted | 5.91 | 5.29 |
| `--input` border (3:1 non-text) | 3.50 | 4.20 |
| `--ring` (3:1 non-text) | 5.17 | 5.44 |
| `--muted-foreground` on muted | 5.01 | 5.71 |
| badge success / warning / danger | 6.65 / 6.31 / 5.66 | 8.57 / 9.84 / 6.76 |
| badge info / neutral / brand | 5.12 / 8.60 / 5.82 | 7.75 / 11.93 / 8.91 |
| badge muted text | 7.58 | 7.80 |
| sidebar count, inactive / active row | 5.82 / 4.94 | 10.14 / 4.85 |
| `--status-success|warning|danger|info|neutral` (3:1 non-text) | 5.02 / 5.02 / 4.83 / 5.17 / 4.76 | 10.41 / 11.98 / 7.23 / 7.87 / 7.80 |

**Not verified**
- No E2E, PHPUnit or rendered check of the new UI: the :8080 stack still serves the old build (login title "Log in - Laravel", capsule and tiles), so light/dark screenshots, keyboard click-through and 375px checks are for the orchestrator after a rebuild. The edited specs were not run; the likeliest breakages are the row-action menus (`datatable-actions-delete`), Radix `Modal` vs Headless UI dialogs, and `AdminLayout` pages that lost their own `<Head>`.
- `datatable-actions-delete.spec.ts:127` shows a TS2349 (`releaseDelete` narrowed to `never`) that was there before this change.

**Deferred, with reason**
- Radix `Select` vs `NativeSelect` (G22) NOT collapsed: 11 selects in 5 module pages plus `Select.selfcheck.mjs` assert the opposite design, and the E2E uses `combobox`; modules 04/05/06/08 should move them when they translate those filters.
- LB4: tokens and `toneColor` exist, `Chart.tsx` untouched; the status charts (Reports/Business, module 08) must pass `var(--status-*)`.
- G26 subtitles, `hasFilters`/`onResetFilters`/`error` wiring, name-column links, Approvals/Dispatch/MyWork SummaryCards (PE2/AP1): module tickets, as written in the ticket.

**needs-change**
- Module pages: `Inventory/Requests/Index.tsx:693` and `Reports/Sales.tsx:205` pass widths in `className` to `NativeSelect`; use `wrapperClassName`.

### Part A (UiPickers, 2026-10-14): pickers, SearchSelect, RemoteSearchSelect, EnumBadge, status maps, shared strings

**Done**
- `lib/dateRange.ts` exports `formatDay`/`parseDay`/`MIN_YEAR`/`MAX_YEAR`; new `lib/dateValue.ts` (time `HH:mm`, date-time `Y-m-d H:i`, month `Y-m`, `TIME_SLOTS`, `MONTH_NAMES`).
- `ui/DatePicker`, `DateTimePicker`, `MonthPicker`, `TimeInput` on one internal shell (`PickerField`, plus `TimeSlotList`, `pickerCell.ts`): typed in the company `date_format`, committed on blur/Enter only when real, popover like `DateRangePicker`, ISO out, `value`/`onChange(string)` with `''` for none, `label`/`id`/`error`/`hint`/`disabled`/`required`/`placeholder`. `required` is the asterisk + `aria-required`, not native `required` (no English browser tooltip). Button names carry the field label ("Buka kalender: Tanggal terbit").
- `ui/SearchSelect` rewritten as a keyboard combobox in a Radix popover (portal: no clipping, Escape closes the list before a dialog): Q10 value kept while typing, props `selected`, `onSearch`, `loading`, `minChars`; `clearValueOnSearch` removed (2 callers fixed). `composite/RemoteSearchSelect` (`field="customer"` reads `customerOptions`, sends `customer_search`; debounce 300 ms, cancels the previous reload, numeric ids from `SearchOptions` coerced to string).
- `lib/statuses/**`: `types.ts` (`BadgeTone`, `StatusMap`, `resolveStatus`, `labelOf`, `optionsOf`, `toneColor` -> `var(--status-*)`) + 17 domain files + barrel; `ui/EnumBadge` (`{ map, value }`; unknown value shows raw in neutral, none shows `-`; booleans and numbers allowed as values: `ACTIVE_STATE`, `IP_VERSION`). Shared `PRIORITY` (work order and ticket), `WORK_ITEM_STATUS` (Dispatch/My Work/Approvals rows).
- Indonesian strings and Lucide in `MapPicker`, `IndonesiaRegionFields`, `CustomerAcquirerField`, `FileUpload`, `ConfirmRequestDialog`, `ResourceState`, `CompanySwitcher`; `Pages/Admin/Components.tsx` (Lucide, Indonesian, new sections Tanggal & Jam, Pilih Data, Status); `resources/js/lib/copy.selfcheck.mjs`; `tests/e2e/ui-pickers.spec.ts` (28 tests on the gallery); barrels `ui/index.ts`, `composite/index.ts`.

**Use**
```tsx
<DatePicker label="Tanggal terbit" value={data.issue_date} onChange={(v) => setData('issue_date', v)} error={errors.issue_date} />
<EnumBadge map={TICKET_STATUS} value={row.status} />   // filters: optionsOf(TICKET_STATUS)
<RemoteSearchSelect field="customer" options={customerOptions} selected={selectedCustomer} value={data.customer_id} onChange={...} />
```

**Decisions to glance at** (docs name states in English only, so labels are mine)
- Invoice `sent` = "Belum dibayar" (the R5 `unpaid` wording; no phantom `unpaid` value), `generated` = "Menunggu penugasan", subscription/customer `terminated` = "Berhenti" neutral (spec; the pages used danger), `critical` priority dropped (never stored), location type `site` = "Site" (not "Situs"), ticket source `noc` = "NOC" (not on the kept list), acquirer field = "Diperoleh oleh".
- Only states get colour: types, sources, ownership, usage are neutral; stock movement is neutral except `adjustment` (warning).
- DateTimePicker: a day without a time starts at 00:00, a time without a day takes today. MonthPicker always shows "Oktober 2026" and also reads `10/2026`, `2026-10`, `Okt 2026`.
- Skipped on purpose: `min`/`max` on the date pickers, `HHmm` typing.

**Duplicates module tickets can now delete** (file:line from the scouts): `lib/customerStatus.tsx`, `lib/status.ts`, `lib/ipam.ts` usage*, `Pages/Admin/Approvals|Dispatch|MyWork/components/utils.ts` (status/priority/domain functions; the three disagree and offer a stored-never `critical`), `Billing/Invoices/Index.tsx` `STATUS_LABEL` (+ `InvoiceController::statusOptions` lists values that never occur), `Billing/Invoices/Show.tsx` + `billing/InvoiceShowSections.tsx` `statusVariant`/`attemptVariant`, `CustomerRelatedTables.tsx` `historyStatusVariant`/`subscriptionStatusVariant`, `Subscriptions/Index|Show.tsx` ternaries, `Tickets/Show.tsx` `statusVariant` + priority ternary, `Users/Show.tsx` `statusVariant`/`priorityVariant`, `Inventory/Requests/constants.ts` + `Index.tsx` `statusVariantMap`, `NetworkAccessBadges.tsx` maps, `NetworkAccounts/Show.tsx` `subscriptionStatusTones`, `NetworkAssets/Trace.tsx` tone chain, `Odn/Index.tsx` ("Full", "No splitter", hard-coded success), `Customers/Show.tsx` WhatsApp maps, `NumberSequences/Index.tsx` `RESET_LABELS`, `Locations/*` type options, `Reports/components/chartData.ts` status labels. Wrong TS unions to derive from the maps (`keyof typeof`): `types/models.ts` `TicketStatus`, `TicketPriority`, `SpkStatus`, `InvoiceStatus`, `NetworkAssetStatus`.

**Verified**
- `npx tsc --noEmit` -> exit 0. `./node_modules/.bin/eslint --max-warnings=0 <my files>` -> exit 0. `prettier --list-different <my files>` -> empty.
- `node --test resources/js/lib/*.selfcheck.mjs` -> 70 tests, 70 pass (new: `dateValue` 14, `statuses` 13 incl. parity of every map with the backend enums/constants it reads from the repo, `copy` 3). Date checks also pass under TZ America/Los_Angeles, Pacific/Auckland, UTC, Asia/Jayapura.
- `node --test resources/js/lib/copy.selfcheck.mjs` clean on `CONVERTED`; `COPY_SCAN=all` baseline 1397 English strings in 269 files.
- Rendered check (throwaway Vite harness outside the repo, deleted; real components, Inertia stubbed; Chromium via Playwright): 21 scenarios passed in one run, "selected day filled" and "Tab away / outside click" passed in later runs, and the in-`Dialog` scenario (SearchSelect and DatePicker inside a Radix `Dialog`) passed every step except its last Escape, sent while the calendar popover was still closing (a timing artifact of the script). Light and dark screenshots read. Covers typing in `d M Y` and `d/m/Y`, Enter vs form submit, calendar/month/time/slot picks, keyboard (Enter/Escape/Home/arrows), invalid text, clear, SearchSelect filter/keep-value/Escape/Tab/clear/arrows, remote select (no request under 2 chars, one debounced `router.reload({ only: ['demoOptions'], data: { demo_search }, replace: true })`), EnumBadge, 375px (controls 44px, popover inside the screen, no overflow). It found and fixed: the single-date calendar showed the chosen day as the same ring as today (now filled), Tab to the clear button left the list open.
- `tests/e2e/ui-pickers.spec.ts` type-checks (`tsc --ignoreConfig`, only the old `Buffer` error in `support/demo.ts`).

**Not verified**
- The e2e spec was not run (gallery route open for `e2e` is UiLocale's change). No real Inertia round trip (harness stubs `router`; contract read from Inertia 2.3.27: `reload` forces `preserveState`/`preserveScroll`, so they are not passed). No `npm run build`, no screen reader pass.
- Known test-only race: Radix registers the popover's Escape/outside-click layer one tick after it appears (<10 ms). Inside a Radix `Dialog`, an automated Escape sent in that window closes the dialog too; a person cannot hit it. Specs for lists inside dialogs should wait for the option, not just the list.

**needs-change**
- none beyond what is done: gallery route + `demoOptions` (UiLocale), `sidebar-ia.spec.ts` (UiSystem). Module tickets: use `size="auto"`/`wrapperClassName` as in Part B; QA flips `copy.selfcheck.mjs` to `COPY_SCAN=all`.
