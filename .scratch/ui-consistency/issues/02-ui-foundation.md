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

## Acceptance criteria

- [ ] Selfchecks for the date/month/time helpers and `optionsOf`; `tsc`/ESLint clean; copy selfcheck clean on owned files; gallery shows every new component in light and dark.

## Comments
