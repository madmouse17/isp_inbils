# TESTER: webhook/reconciliation/portal matrix and E2E

Status: needs-owner-approval
Role: tester
Blocked by: 02, 04, 05
Card: none (new lane; no Hermes card)

## Scope

Tests only (`tests/**`); defects reported as `needs-change` with a failing test.

- Close gaps in the matrix from `../spec.md` Testing Decisions not already covered by tickets 01-04: concurrency (parallel notifications), amount drift after a manual payment, cross-company, customer isolation, gateway disabled, manual `recordPayment` regression.
- Playwright `tests/e2e/customer-portal-payment.spec.ts`: customer login → forced password change → invoice list → Pay (Midtrans stubbed at the network layer) → return page; staff views the attempts panel. Run `tests/e2e/setup.php` before each run; shared `inbils_test` serialised.
- Fixtures use a fake server key and fake payloads only.

## Acceptance criteria

- [ ] Every matrix row has a named test, green on MySQL `inbils_test`.
- [ ] Mutation checks noted: removing the signature check, the `payment_id` short-circuit, or the status guard each fail at least one test.
- [ ] E2E passes on the canonical run (1 worker); commands and counts reported.
- [ ] `grep` of the test tree and fixtures shows no real key material.
