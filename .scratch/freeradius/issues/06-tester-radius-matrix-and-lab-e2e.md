# TESTER: gap matrix, Playwright flows and lab e2e against FreeRADIUS + nas-sim

Status: ready-for-agent
Role: tester
Blocked by: 05
Card: none (new lane; no Hermes card)

## Scope

1. **Gap matrix** (`tests/Feature/NetworkAsset/RadiusGapTest.php`): rows of `../spec.md` Testing Decisions not covered by tickets 01-04 (list the mapping row → test in a comment here). Must include: blocked + overdue suspension + clearing payment (Midtrans path too) stays isolated in RADIUS; unblock of a suspended subscription keeps the isolir group; Disconnect timeout → `pending` → `network:sync` sends it once the fake answers; NAK 503 counts as done; session on an unknown NAS address is not sent and is reported; company A job never reads or writes company B usernames or NAS rows; speed profile edit rewrites only its groups; switch off: zero RADIUS queries and zero Disconnects across a full subscription lifecycle.
2. **Playwright (CI, switch off)** `tests/e2e/radius-provisioning.spec.ts`, porting the page flows of `tests/e2e/mikrotik-provisioning.spec.ts` (then delete it): register NAS with secret (never shown again), account with realm suffix and one-time secret, reveal, Block and Unblock on Subscriptions/Show (reason required, badge, audit visible on the page), Block/Unblock on Receivables next to Isolir, manager vs staff visibility (403 from the server, not only hidden), 375/1280 light/dark overflow checks, public isolir page.
3. **Lab e2e (opt-in)**: the same spec, `test.describe` skipped unless `E2E_RADIUS_LAB=1`. Preconditions in the spec header: lab up (`docker compose ps` in `~/labs/freeradius`), light reset from the lab README, local `.env.e2e` (gitignored, `.gitignore:6`) with `RADIUS_ENABLED=true` and `RADIUS_DB_*` pointing at `127.0.0.1:13306` (values typed by the operator from `~/labs/freeradius/.env`, never printed, never committed), `QUEUE_CONNECTION=sync` (already, `.env.e2e:16`). The lab secret and subscriber secret are read in the test from process env (`LAB_RADIUS_SECRET`, `LAB_USER_SECRET`) exported by the operator, never logged, never in traces (disable trace/screenshot on the steps that type them).
   Flow: register NAS `nasname 172.30.77.20` on a router asset with management IP `127.0.0.1`, CoA port `13799`; `docker kill -s HUP radlab-freeradius` (the runbook's reload); create the account `e2e-lab@<demo realm>` with the lab user secret on an active subscription whose speed profile name is `10M` (Inbils writes group `<realm>/10M` with `Mikrotik-Group=10M`; the lab seed groups `10M`/`isolir` in `~/labs/freeradius/db/initdb/20-seed.sh:20-28` stay untouched); run `python3 ~/labs/freeradius/nas-sim/sim.py scenario block-unblock <user> --expect-group isolir --timeout 60` in the background; click **Block internet** with a reason; assert `sim events` shows a Disconnect-Request with ACK; scenario exit 0 (reconnected with `Mikrotik-Group=isolir`); click **Unblock**; assert a second ACKed Disconnect, then `sim connect <user>` returns a session with `group` = `10M` and `sim list` shows it; the account page shows Online with the sim's framed IP and a non-zero usage after one `sim interim`.
   Also prove the realm policy of `../spec.md` Q4 by hand once (user of another realm via the lab NAS → Access-Reject) and paste the FreeRADIUS snippet that did it into this ticket for ticket 04's runbook (`needs-change: docs/business/network.md` if it differs).
4. Evidence: commands and summaries only; no secrets, no lab `.env` content, no packet dumps containing the user secret attribute.

Owned files: NEW `tests/Feature/NetworkAsset/RadiusGapTest.php`, NEW `tests/e2e/radius-provisioning.spec.ts`, DELETE `tests/e2e/mikrotik-provisioning.spec.ts`, `tests/e2e/setup.php` (only if fixtures need it).

## Acceptance criteria

- [ ] Gap matrix mapping in Comments; `RadiusGapTest` green.
- [ ] CI Playwright run green with the switch off.
- [ ] Lab run green with every step above evidenced (exit code of the scenario, `sim events` summary, page assertions).
- [ ] Grep of `tests/` finds no real secret, lab value or lab `.env` content.
- [ ] Full isolated PHPUnit run green before ticket 07 (command and summary pasted).

## Commands

Sail runs on docker context `default`: prefix every sail call with `DOCKER_CONTEXT=default`; never `sail up`, never the desktop-linux context.

```
DOCKER_CONTEXT=default ./vendor/bin/sail exec laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact
flock /tmp/inbils-e2e.lock sh -c 'npm run build && php tests/e2e/setup.php && npm run test:e2e -- tests/e2e/radius-provisioning.spec.ts tests/e2e/datatable-e.spec.ts --project=chromium --workers=1'
E2E_RADIUS_LAB=1 flock /tmp/inbils-e2e.lock sh -c 'php tests/e2e/setup.php && npm run test:e2e -- tests/e2e/radius-provisioning.spec.ts --project=chromium --workers=1'
```

## Comments
