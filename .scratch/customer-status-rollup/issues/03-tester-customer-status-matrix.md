# TESTER: rollup rule matrix, wiring, tenant isolation, E2E

Status: done
Role: tester
Blocked by: 01, 02
Card: none (new lane; no Hermes card)

## Scope

Tests only (`tests/**`); defects reported as `needs-change` with a failing test.

- Feature/unit matrix of the ADR-011 rule (see `../spec.md` Testing Decisions) through `SubscriptionService` and HTTP routes.
- Wiring guard: a test (or grep gate documented in the ticket comment) proving `customer_status` is written only by the recalculator and every `SubscriptionService` transition calls it.
- Customer create/update ignores `customer_status`.
- Command `customers:recalculate-status`: dry-run, idempotence, company isolation.
- Migration backfill against a seeded fixture.
- Playwright: extend `tests/e2e/customer-subscription.spec.ts` (badge + filter); run `tests/e2e/setup.php` first.

## Acceptance criteria

- [ ] Every row of the matrix in the spec has a named test, all green on MySQL `inbils_test`.
- [ ] Mutating the recalculator rule (e.g. treat `suspended` as `active`) turns at least one test red.
- [ ] Tenant test: running the command for company A leaves company B's statuses unchanged.
- [ ] E2E passes on the canonical run (1 worker); report the exact commands and counts.
