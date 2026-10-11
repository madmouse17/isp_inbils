# REVIEWER: architecture and release-readiness of the customer status rollup

Status: needs-owner-approval
Role: reviewer
Blocked by: 03
Card: none (new lane; no Hermes card)

## Scope

Read-only review of tickets 01-03 against `../spec.md`.

- Single writer of `customer_status`; no hidden coupling to `is_active`.
- Transaction/lock ordering (customer row lock taken after subscription lock) cannot deadlock with `BillingService` or `CompleteSpkAction` paths.
- Migration reversibility and rehearsal evidence on `inbils_test`/staging.
- `docs/business/customer.md` updated with the rule; ledger entry added and `.scratch` status reconciled.

## Acceptance criteria

- [ ] Verdict APPROVED or BLOCKED with file:line findings.
- [ ] Deploy notes listed: `php artisan migrate`, optional `php artisan customers:recalculate-status --company=<id>`.
- [ ] Rollback note: dropping the column is safe because nothing else reads it before the UI ships.
