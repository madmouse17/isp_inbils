# UI: pickers, remote search select, EnumBadge, status maps, shared strings

Status: ready-for-agent
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
