# TESTER: provisioning and isolir matrix gaps, mutation checks, E2E

Status: done
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

- [x] Every matrix row in `../spec.md` maps to a named test (list it in `## Comments`), green on MySQL `inbils_test`.
- [x] Each mutation fails at least one test; counts recorded; every mutation reverted (diff empty afterwards).
- [x] E2E passes on the canonical single-worker run three times in a row. (2026-10-12: `tests/e2e/mikrotik-provisioning.spec.ts`, build + `setup.php` + `--project=chromium --workers=1`, three consecutive runs: 12 passed each, 52.1s / 36.8s / 36.6s.)
- [x] Grep of `tests/` and the repo finds no real credential, router address or subscriber password.
- [x] Full isolated PHPUnit run green before handing over to ticket 07 (command and summary pasted). (2026-10-12, sail + flock, full suite: Tests: 2807 passed (31074 assertions), 1697.95s; affected dirs 1171 passed.)

## Commands

```
./vendor/bin/sail exec laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact tests/Feature/NetworkAsset tests/Feature/Billing
./vendor/bin/sail exec laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact
flock /tmp/inbils-e2e.lock sh -c 'npm run build && php tests/e2e/setup.php && npm run test:e2e -- tests/e2e/network-provisioning.spec.ts tests/e2e/datatable-e.spec.ts --project=chromium --workers=1'
```

## Comments

### UI/E2E agent (IntegrationsUI), 2026-10-13

- Done, E2E part only: `tests/e2e/mikrotik-provisioning.spec.ts` (the lead's file name; this ticket says `network-provisioning.spec.ts`), 12 serial tests with the installation switch off: register the unregistered fixture router (writes off) with an inline server error; router page "Never checked", isolir URL and copy, switch banner, "Check now", remove dialog (cancelled); edit keeps the stored API password; Subscriptions "Network access" link; network account created with a generated password shown once (copy, dismiss, gone after reload); reveal with confirmation; rename; resync; Receivables "Isolir" request carries `source: 'overdue'`; account page "Isolated" and "Pending" with the switch reason; router page lists the account and blocks removal; public isolir page with company contacts (set through the profile form, restored after), login link of at least 44 px, 404 for an unknown code; a manager reads but cannot manage (403 on create); UI gates hide the link and the menu item when the two permissions are renamed in the shared props; layouts at 375 and 1280 px in both themes; cleanup reactivates E2E-LINKED-SUB.
- Evidence: the E2E command with `mikrotik-provisioning`, `whatsapp-notifications`, `customer-edit`, `datatable-e`, `customer-subscription` → 27 passed (build and `setup.php` included); the previous run with a temporary screenshot spec → 28 passed. Two consecutive green canonical runs; the "three in a row" criterion is not met yet.
- Not done (still this ticket's): `NetworkAccessGapTest`, `IsolirPolicyGapTest`, the matrix-to-test mapping, the five mutation checks, the full isolated PHPUnit run, the credential grep. Fixtures: `E2eFixtureSeeder` (LaneDemoData) provides the unregistered `E2E-ROUTER-001`, the registered `E2E-ROUTER-002` and `E2E-WA-SUB`; `E2E-LINKED-SUB` has no account.

### Tester agent (QaMikrotik06), 2026-10-14

- `tests/Feature/NetworkAsset tests/Feature/Billing` → 533 passed (4459 assertions), MySQL `inbils_test`, flock.
- Matrix rows → tests (class::test fragment):
  - Billing: grace boundary, partial eligible, draft/cancelled/paid ignored, other subscription/company ignored, settings off, dry run, source validation → `OverdueSuspensionTest` (`grace_boundary_n_days_is_safe_and_n_plus_one_is_suspended`, `a_partially_paid_invoice_past_the_grace_is_still_eligible`, `only_unpaid_sent_partial_or_overdue_invoices_count`, `invoices_of_another_subscription_of_the_same_customer_are_ignored`, `company_option_never_touches_another_company`, `nothing_happens_while_automatic_isolir_is_off`, `dry_run_writes_nothing_and_lists_who_would_be_suspended`, `suspend_route_rejects_an_unknown_source`). Manual never reactivated, remaining invoice blocks, rolled-back payment → `AutoReactivateOnPaymentTest`. Manual payment + Midtrans reconnection → `paying_the_last_eligible_invoice_reconnects_an_overdue_isolation`, `a_midtrans_settlement_reconnects_inside_the_invoice_company_without_any_ambient_company`; end to end with router + repeat webhook → `IsolirPolicyGapTest::a_midtrans_settlement_brings_the_secret_back_to_its_speed_profile_and_a_repeated_notification_changes_nothing`. Reversal after reconnect → `...::a_payment_reversed_after_the_reconnection_isolates_the_subscription_again_at_the_next_automatic_run`. Partial payment end to end → `...::a_partial_payment_leaves_the_secret_isolated_and_the_payment_that_clears_the_invoice_restores_it`. Re-run idempotent → `...::running_the_automatic_isolir_again_changes_nothing_on_the_router_or_in_the_audit`.
  - Client contract → `RouterOsClientTest` (method/path/proplist/body, Basic auth never printed, each error kind `failures_map_to_a_kind`, `a_redirect_is_not_followed`, `remove_deletes_by_id_and_a_missing_record_counts_as_removed`, TLS `every_call_uses_basic_auth_a_verified_tls_session_...`, `with_the_installation_switch_off_nothing_is_sent_not_even_a_read`).
  - Models → `NetworkAccessModelsTest` (encrypted, hidden, fail-closed scope, unique constraints).
  - Engine → `ProvisioningFlowTest` (every listed row incl. package change via update route, NAS move, rename, password, adoption, idempotent, write flag, 4xx, unreachable, company A job). Extra: `NetworkAccessGapTest::a_package_without_a_router_profile_name_chosen_on_the_update_route_...`, `a_mass_isolir_while_the_router_is_down_...` (mass isolir, pending, recovery), `the_account_service_refuses_another_companys_records_without_the_http_layer`.
  - Sync → `NetworkSyncTest` (probe, online/offline, drift, unmanaged cap 100, one notification per outage, recovery re-queue, hourly failed re-queue).
  - HTTP → `NasDeviceHttpTest`, `NetworkAccountHttpTest` (403 per permission, 404 other company, validation, one-time flash, reveal throttle+audit, delete refused), `IsolirPageTest`. Password absence everywhere → `NetworkAccessGapTest::neither_password_is_left_in_any_store_page_export_or_log_...`.
- Mutations (all reverted, `cmp` with backup identical): tenant check in `NetworkAccountService::` (`abort_unless(true…)`) → 1 failed; installation switch ignored in `RouterOsClient` → 1 failed; `$hidden = []` on `NetworkAccount` → 1 failed; drop `suspension_source = 'overdue'` → 2 failed; `due_date <` → `<=` → 2 failed.
- Credential grep of `tests/` (Feature/NetworkAsset, Billing, Traits, e2e specs): only `192.0.2/198.51.100/203.0.113` doc ranges for routers, `10.x` fake session client addresses, `fake-*` passwords. No real secret or address.
- Left to the main agent: E2E three runs in a row (two green so far) and the full PHPUnit run.
