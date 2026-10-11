# [t_0d86de36] UI: customer-sales-attribution-v1 pada Customer

Status: done
Role: ui
Card: `t_ffe9fc13` (ledger `omp_status: needs_recovery`; Hermes card is a reference only, never mutated)
Blocked by: 01

## Scope

Defined by `../spec.md` for the `ui` step of this lane. Acceptance is the approved spec (recommended options).

## Comments

- 2026-10-10 UI implemented per the backend contract: `Pages/Admin/Customers/{Index,Create,Edit,Show}.tsx`, composite `CustomerAcquirerField` (+ `CustomerAcquirerValue`), `types/models.ts`. E2E spec `tests/e2e/customer-sales-attribution.spec.ts` written, NOT run (fixtures appended to `E2eFixtureSeeder`). Known gaps for QA: `SearchSelect` (ui primitive, untouched) keeps its 36px height and does not link its error text via `aria-describedby`.
