# TESTER: customer app integration journeys, accessibility pass, live smoke on the emulator

Status: ready-for-agent
Role: tester (qa)
Blocked by: 06
Cross-lane blocked by: mobile-api 08 (API matrix green)
Card: none (new lane; no Hermes card)
Skills: tdd, antislop-human, antislop-layoutmobile, ponytail, caveman

## Scope

An independent check of the finished customer app. Add tests and evidence; never change app code. A defect becomes a `needs-change` to the owning ticket, with a failing test.

- `integration_test` journeys on the AVD `inbils_pixel_api35` with a fake HTTP adapter fed by JSON fixtures taken from `docs/api/mobile-v1.openapi.json` examples or recorded from the API feature tests:
  1. Login → Beranda → open the unpaid invoice → Bayar (fake launcher) → resume → paid banner.
  2. Login with 2FA, then with a recovery code, then an expired challenge.
  3. A legacy password → reset link sent.
  4. Create a ticket with a 422 then success → reply.
  5. Notifications → open invoice.
  6. Devices → revoke the current device → back at login.
  7. A token revoked mid-session (401) → login with the reason.
  8. Offline on every tab → banners, then retry recovers.
- Fixture contract check: every fixture parses with the app models (a test fails on drift).
- Accessibility: the Flutter guideline tests on every top-level screen, a TalkBack walk of the main journey, and 200% text scale screenshots.
- Live smoke on the compose stack (run `tests/e2e/setup.php` right before; `adb reverse tcp:8080 tcp:8080`):
  - login, Home, invoices, pay (sandbox or `payment_unavailable`), a ticket and reply, notifications, devices, logout;
  - screenshots stored under a path named in the ticket comment, outside the repo, with no tokens visible.

## Owned files

- NEW `../inbils-mobile/customer/integration_test/**` (journeys, fixtures, fake adapter)

## Acceptance criteria

- [ ] `flutter test integration_test -d <emulator-id> --flavor dev --dart-define-from-file=env/dev.json` is green on `inbils_pixel_api35`, with the output pasted.
- [ ] Every Must-have case in the spec's Testing Decisions maps to a passing unit, widget or integration test, listed in a ticket comment.
- [ ] The accessibility guideline tests pass on every top-level screen, and the TalkBack findings and 200% screenshots are recorded.
- [ ] The live smoke transcript is recorded step by step with what was seen, and any mismatch with the web portal (amounts, statuses) is reported as a finding.
- [ ] No app source file was changed by this ticket (`git diff --stat ../inbils-mobile/customer/lib ../inbils-mobile/packages` is empty for this ticket's changes).

## Comments
