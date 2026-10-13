# UI: tier page copy and computed columns, ticket category screen, ticket deadlines

Status: ready-for-agent
Role: ui
Blocked by: none (contracts in tickets 01, 02)
Spec: `../spec.md`

## Scope (owned files)

- `resources/js/Pages/Admin/Service/SLATiers/{Index,Create,Edit}.tsx`: S3 copy, remove uptime/credit inputs and columns, add the two read-only computed columns (props from ticket 02), note on the urgent rule (D2).
- New `resources/js/Pages/Admin/Tickets/Categories/Index.tsx` (list + edit dialog, D1), sidebar item "Kategori Tiket" in `lib/navigation.ts` + fixture + `AdminMenuAuthorizationTest` + selfcheck (gate `ticket.category.manage`).
- `resources/js/Pages/Admin/Tickets/Show.tsx` (and list column if present): both deadlines with source and breach badges (D4).
- Reports SLA page: response metric card next to resolution (ticket 01 payload).
- E2E `tests/e2e/sla-targets.spec.ts` per spec; update `reporting-*` SLA assertions if they count cards.

## Acceptance criteria

- [ ] `tsc`, ESLint, selfchecks clean; E2E spec type-checks.

## Comments
