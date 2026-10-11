# TESTER: provisioning and isolir matrix gaps, mutation checks, E2E

Status: ready-for-agent
Role: tester
Blocked by: 05
Card: none (new lane; no Hermes card)

## Scope

Tests only. Defects go back to the owning ticket as `needs-change` with a failing test; files created by tickets 01-04 stay with those tickets.

- Walk the matrix in `../spec.md` (Testing Decisions) against the suites from tickets 01-04 and close every gap in new files only. Expected gaps to check first: cross-company attempts on every route and job (company A user, company B NAS router or subscription); the observer path for `SubscriptionController::update` package changes; a mass isolir (50 subscriptions suspended by `billing:suspend-overdue` while the router is unreachable: no job hangs the worker, all accounts end `pending`, recovery re-queues them); reconnection after a Midtrans settlement end to end; a payment reversal after reconnection followed by the next automatic run; password absence in every prop, export, audit and log line.
- Mutation checks, each reverted byte for byte, with the number of failing tests recorded: remove the tenant check in the network account service; make the installation switch ignored; drop `$hidden` from `NetworkAccount`; remove the `suspension_source = 'overdue'` condition from reconnection; remove the `due_date` grace comparison.
- Playwright `tests/e2e/network-provisioning.spec.ts` with the installation switch off (server-side router calls cannot be intercepted): admin registers the demo router asset as a NAS router (writes off) → the router page shows "never checked" and the isolir URL → the network account is created on a subscription with a generated password shown once → reveal shows it again → Receivables "Isolir" isolates the subscription → the account page shows desired state "isolated" and sync `pending` with the switch reason → the public isolir page for the company code renders the company name and the login link. Run `tests/e2e/setup.php` before each run; workers 1; shared `inbils_test` serialised.
- Fixtures use fake credentials only.

Owned files:
- NEW `tests/e2e/network-provisioning.spec.ts`
- NEW `tests/Feature/NetworkAsset/NetworkAccessGapTest.php`
- NEW `tests/Feature/Billing/IsolirPolicyGapTest.php`
- `tests/e2e/setup.php` (only if the E2E needs a fixture it does not have yet)

Non-goals: production code changes, a real router (ticket 08).

## Acceptance criteria

- [ ] Every matrix row in `../spec.md` maps to a named test (list it in `## Comments`), green on MySQL `inbils_test`.
- [ ] Each mutation fails at least one test; counts recorded; every mutation reverted (diff empty afterwards).
- [ ] E2E passes on the canonical single-worker run three times in a row.
- [ ] Grep of `tests/` and the repo finds no real credential, router address or subscriber password.
- [ ] Full isolated PHPUnit run green before handing over to ticket 07 (command and summary pasted).

## Commands

```
./vendor/bin/sail exec laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact tests/Feature/NetworkAsset tests/Feature/Billing
./vendor/bin/sail exec laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact
flock /tmp/inbils-e2e.lock sh -c 'npm run build && php tests/e2e/setup.php && npm run test:e2e -- tests/e2e/network-provisioning.spec.ts tests/e2e/datatable-e.spec.ts --project=chromium --workers=1'
```

## Comments
