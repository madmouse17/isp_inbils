# UI: tier page copy and computed columns, ticket category screen, ticket deadlines

Status: done
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

- [x] `tsc`, ESLint, selfchecks clean; E2E spec type-checks.

## Comments

2026-10-14 SlaUi. Evidence (run from the web repo):

- `npx tsc --noEmit` → no output (clean).
- `./node_modules/.bin/eslint resources/js/Pages/Admin/Service/SLATiers resources/js/Pages/Admin/Tickets/Categories resources/js/Pages/Admin/Tickets/Show.tsx resources/js/Pages/Admin/Reports/Sla.tsx resources/js/lib/navigation.ts` → no output (clean). `npx prettier --write` on all touched files → formatted.
- `node --test resources/js/lib/navigation.selfcheck.mjs` → 24 pass, 0 fail (new test: Kategori Tiket needs `ticket.category.manage`).
- `npx tsc --noEmit --skipLibCheck --strict --target es2022 --module esnext --moduleResolution bundler --types node tests/e2e/sla-targets.spec.ts tests/e2e/ticket-lifecycle.spec.ts` → no output (clean).
- Not run (rules): PHPUnit, `npm run build`, E2E, browser render.

Shipped: tier Index/Create/Edit (S3 copy, no uptime/credit, columns "Respons tepat waktu (30 hari)", "Penyelesaian tepat waktu (30 hari)", "Tiket (30 hari)", urgent note), `Admin/Tickets/Categories/Index.tsx` (list + edit modal), sidebar item + fixture + `AdminMenuAuthorizationTest::test_ticket_category_manage_alone_opens_ticket_categories_and_ticket_view_does_not`, ticket Show deadlines with source and breach badges, SLA report response card (`meta.metrics.response_rate`), `tests/e2e/sla-targets.spec.ts`; `ticket-lifecycle.spec.ts` and `datatable-ab.spec.ts` follow the new labels.

Open for others: SlaCore must register metric `response_rate` (and the other `response_*` payload keys) in `MetricRegistry` for the SLA report, otherwise the SLA page throws on `meta.metrics.response_rate.label`; then `tests/e2e/reporting-dictionary.spec.ts` (`metrics: 7`, link `SLA Compliance (7)`) must take the new count.
