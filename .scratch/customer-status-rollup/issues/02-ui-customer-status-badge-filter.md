# UI: customer status badge and filter on Customers Index/Show

Status: needs-owner-approval
Role: ui
Blocked by: 01
Card: none (new lane; no Hermes card)

## Scope

Implements Q5 of `../spec.md`.

- `Pages/Admin/Customers/Index.tsx`: add a status column/badge (Prospective, Active, Suspended, Inactive, Terminated) and a status filter wired to the existing server filter/sort (`CustomerIndexQuery`); relabel the current Active/Inactive badge as "Account".
- `Pages/Admin/Customers/Show.tsx`: show the derived status badge beside the account badge.
- Create/Edit forms keep the `is_active` toggle, relabelled "Account active"; no `customer_status` input anywhere.
- Reuse `Components/ui` and `Components/composite` (Badge/StatusBadge, DataTable filter); dark mode, 320px layout, keyboard-operable filter, colour not the only signal (antislop-human). Indonesian copy follows the existing locale.

Non-goals: backend rule changes, reports, dashboard widgets.

## Acceptance criteria

- [ ] Index shows the correct badge for a prospective, active, suspended and terminated customer, and filtering by each value returns only those customers (server-side, pagination preserved).
- [ ] Sorting by status works through the existing sortable-column contract (server support is delivered by ticket 01).
- [ ] The Account badge/toggle still reflects `is_active` and is unaffected by subscription actions.
- [ ] `npm run typecheck`, `npm run build`, and scoped ESLint pass; no layout overflow at 320px and 1280px; both themes legible.
