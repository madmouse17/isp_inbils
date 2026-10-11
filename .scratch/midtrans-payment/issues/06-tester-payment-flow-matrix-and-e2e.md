# TESTER: webhook/reconciliation/portal matrix and E2E

Status: done
Role: tester
Blocked by: 02, 04, 05
Card: none (new lane; no Hermes card)

## Scope

Tests only (`tests/**`); defects reported as `needs-change` with a failing test.

- Close gaps in the matrix from `../spec.md` Testing Decisions not already covered by tickets 01-04: concurrency (parallel notifications), amount drift after a manual payment, cross-company, customer isolation, gateway disabled, manual `recordPayment` regression.
- Playwright `tests/e2e/customer-portal-payment.spec.ts`: customer login → forced password change → invoice list → Pay (Midtrans stubbed at the network layer) → return page; staff views the attempts panel. Run `tests/e2e/setup.php` before each run; shared `inbils_test` serialised.
- Fixtures use a fake server key and fake payloads only.

## Acceptance criteria

- [x] Every matrix row has a named test, green on MySQL `inbils_test` (webhook, reconcile, portal, service, schema and panel suites under `tests/Feature/Billing`).
- [x] Mutation checks: removing the signature check fails 6 tests, the `payment_id` short-circuit 3, the payable-status guard 5; each mutation was reverted byte-for-byte.
- [x] E2E passes on the canonical run (1 worker): `tests/e2e/midtrans-payment.spec.ts`, 2 passed. The forced-password-change step is covered by feature tests only (no flagged E2E fixture).
- [x] Grep of the test tree, fixtures and the whole repo shows no real key material (only the obviously fake `SB-Mid-*-FAKE*` values).
