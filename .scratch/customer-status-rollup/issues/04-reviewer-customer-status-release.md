# REVIEWER: architecture and release-readiness of the customer status rollup

Status: done
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

## Result

security-reviewer `SecReviewCustomerTicket` SIGNED (2026-10-12) covering the recalculator, command tenant scoping, mass assignment and filters; array-valued filter 500 fixed. Full PHPUnit 1623 green, full E2E green after the sidebar expectation update.
