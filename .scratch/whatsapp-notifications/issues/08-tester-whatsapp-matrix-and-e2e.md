# TESTER: WhatsApp privacy/tenant matrix, E2E and live test-number evidence

Status: ready-for-agent
Role: tester
Blocked by: 02, 03, 04, 05, 06, 07
Card: none (new lane; no Hermes card)

## Scope

Cross-cutting checks the per-ticket tests do not own (spec "Testing Decisions").

- NEW `tests/Feature/WhatsApp/WhatsAppPrivacyAndTenantTest.php`:
  - run every event end-to-end with `Http::fake` and a failing provider, then assert the full phone, rendered parameters and the access token appear in none of: `whatsapp_messages`, `failed_jobs.payload`, `jobs.payload`, log output (log fake), activity log;
  - same phone number on customers of company A and B: each event for A writes only A rows and sends A's company name; A's customer show props never include B rows;
  - STOP reply from that phone opts out both, later events for both are `skipped: opted_out`.
- NEW `tests/e2e/whatsapp-notifications.spec.ts` (no live Meta calls; feature unconfigured in E2E env): staff ticks consent on customer edit and sees "Setuju sejak"; customer show WhatsApp card empty state; company admin toggles WhatsApp settings and sees the "kredensial" banner; a read-only role sees disabled controls. Add a fixture to `tests/e2e/setup.php` only if needed.
- Full runs through the repo lock commands only: isolated PHPUnit (`./vendor/bin/sail exec laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact`), then E2E (`flock /tmp/inbils-e2e.lock sh -c 'npm run build && php tests/e2e/setup.php && npm run test:e2e -- --project=chromium --workers=1'`).
- **Live evidence (needs owner prerequisites from spec "Further Notes")**: with a Meta test number and approved templates on a staging host reachable over public HTTPS, send one message per event to a test recipient and record webhook `delivered`/`read` transitions plus one STOP opt-out; evidence as screenshots/log excerpts with numbers masked and no tokens. If prerequisites are missing, record exactly which and stop there.
- `CHANGELOG.md` entry when green.

## Acceptance criteria

- [ ] Privacy/tenant test green; it fails if any channel or notification starts storing a full phone (prove once by a temporary local mutation, not committed).
- [ ] E2E spec green with `--workers=1`; full E2E suite green.
- [ ] Full PHPUnit green; counts pasted.
- [ ] Live evidence attached or the missing prerequisites listed verbatim.
- [ ] CHANGELOG updated.
