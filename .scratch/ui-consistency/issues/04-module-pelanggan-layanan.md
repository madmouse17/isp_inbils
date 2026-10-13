# MODULE: Pelanggan & Layanan

Status: ready-for-agent
Role: fullstack
Blocked by: 02, 03
Spec: `../spec.md`

## Scope (owned files)

Pages `Admin/Customers/**`, `Admin/CustomerAddresses/**`, `Admin/CustomerContacts/**`, `Admin/Subscriptions/**`, `Admin/Service/**` (packages, bandwidth, speed, SLA tiers: language only, R9), composites `CustomerRelatedTables`, `CustomerDataFields`, `CustomerAddressEditor`, `CustomerContactEditor`; controllers/requests for Customer, CustomerAddress, CustomerContact, Subscription, `Modules/Service/**`; `CustomerAccountCreated` mail.

Specifics: Customers/Create package + POP → pickers; Subscriptions package/address → `SearchSelect`; customer type Individual/Company → Perorangan/Perusahaan badge; typo "Instalation".

E2E: `customer-*`, `datatable-customers`, `customer-subscription`, `customer-sales-attribution`, `whatsapp-notifications` (customer parts), `kanban-t_49387795`.

## Per-page checklist (apply to every owned page)

1. Dates → `DatePicker` / `DateTimePicker` / `MonthPicker` / `TimeInput` (spec Q1, R2); requests use `date_format` (R8).
2. Every reference to another record → `SearchSelect` (small catalogue) or `RemoteSearchSelect` + `SearchOptions` (large table) per Q3/R4; no typed ids; `Rule::exists(...)->where('company_id', …)` on the request.
3. Every user-visible string Indonesian per Q4 and the glossary R6: page title/subtitle, labels, placeholders, buttons, column headers, empty states, dialogs, flash messages in the owned controllers, FormRequest `messages()`/inline `$fail`, notification subjects/bodies, export headings.
4. Every status/type/priority value → `EnumBadge` with the domain map from `lib/statuses/*` (R5), also in filter options; delete local status maps.
5. Update the E2E specs listed below to the new text and controls; add assertions where a control changed (picker instead of input).
6. Fix the listed defects (R7) and add a regression test for each.

## Acceptance criteria

- [ ] Checklist done for every owned page; `tsc`, ESLint, copy selfcheck (no denylisted English in owned files) clean; focused PHPUnit green; owned E2E specs type-check (orchestrator runs them).

## Comments
