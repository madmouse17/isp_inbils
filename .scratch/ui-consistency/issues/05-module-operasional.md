# MODULE: Operasional (Penugasan, Persetujuan, SPK, Tiket, Evaluasi, Pekerjaan Saya, Dasbor, Notifikasi)

Status: ready-for-agent
Role: fullstack
Blocked by: 01, 02, 03
Spec: `../spec.md`

## Scope (owned files)

Pages `Admin/Dispatch/**`, `Admin/Approvals/**`, `Admin/SPK/**`, `Admin/Tickets/**`, `Admin/Evaluations/**`, `Admin/MyWork/**`, `Admin/Dashboard/**`, `Admin/Notifications/**`; `Modules/SPK/**`, `Modules/Ticketing/**` web controllers/requests/notifications (`TicketAssignedNotification`, `TicketSlaBreachedNotification`), `app/Http/Controllers/Admin/{DispatchQueue,ApprovalInbox,MyWork,Dashboard,Evaluation,Notification}Controller.php` (after ticket 01 lands on DispatchQueue).

Specifics: SPK Create customer/subscription/location → `RemoteSearchSelect`, scheduled date → `DatePicker`; Tickets Create customer/subscription/asset → remote, location + category → `SearchSelect`; Ticket assign Handler ID → select from the existing `handlers` prop; Evaluations reference → picker per reference type, employee → `SearchSelect`; merge the three near-duplicate `getStatusLabel` utils into `lib/statuses`.
Defects (R7): Evaluasi list has no page buttons; priority "Kritis" matches no value.

E2E: `spk-lifecycle`, `ticket-lifecycle`, `dispatch-my-work`, `approval-*`, `dashboard-persona`, `notification-center`, `evaluation*`, `private-media`.

## Per-page checklist (apply to every owned page)

1. Dates → `DatePicker` / `DateTimePicker` / `MonthPicker` / `TimeInput` (spec Q1, R2); requests use `date_format` (R8).
2. Every reference to another record → `SearchSelect` (small catalogue) or `RemoteSearchSelect` + `SearchOptions` (large table) per Q3/R4; no typed ids; `Rule::exists(...)->where('company_id', …)` on the request.
3. Every user-visible string Indonesian per Q4 and the glossary R6: page title/subtitle, labels, placeholders, buttons, column headers, empty states, dialogs, flash messages in the owned controllers, FormRequest `messages()`/inline `$fail`, notification subjects/bodies, export headings.
4. Every status/type/priority value → `EnumBadge` with the domain map from `lib/statuses/*` (R5), also in filter options; delete local status maps.
5. Update the E2E specs listed below to the new text and controls; add assertions where a control changed (picker instead of input).
6. Fix the listed defects (R7) and add a regression test for each.

## Antislop findings (A1)

PE1 amber-700/red-700 (dark red-400) and ≥ 12px on Penugasan; PE2 summary counts as single-hue filter chips without icon tiles (Dispatch, Approvals, MyWork); PE3 filter cells wide enough; AP1 keep only real summary cards, shortcuts become tabs; AP2 fewer columns, no clipped actions at 1280; SP1 SPK items empty row with reason + "Tambah item", `<dl>` grid, status as badge; TK1 red only for breach; TK2 ticket dates via `formatDateTime`, localised empty check-in text; D1 dashboard counts link to their lists or go, humanised activity text, unread by weight.

## Acceptance criteria

- [ ] Checklist done for every owned page; `tsc`, ESLint, copy selfcheck (no denylisted English in owned files) clean; focused PHPUnit green; owned E2E specs type-check (orchestrator runs them).

## Comments

Carried from ticket 02 Part B (2026-10-14): migrate this module's Radix `Select` filters to `NativeSelect` (or `SearchSelect` for DB lists) per G22; `Select.selfcheck.mjs` and E2E `combobox` lookups change with them. Delete `ui/Select.tsx` in the last of 04/05/06/08 to land.

Carried from ticket 02 Part A (2026-10-14): derive `TicketStatus`, `TicketPriority`, `SpkStatus` in `resources/js/types/models.ts` from `lib/statuses` maps (`keyof typeof`); delete this module's duplicate status maps listed in ticket 02 Part A comment.
