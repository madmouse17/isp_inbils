# TESTER: technician app journeys, offline and duplicate-prevention proof, accessibility, live smoke

Status: ready-for-agent
Role: tester (qa)
Blocked by: 08
Cross-lane blocked by: mobile-api 08 (API matrix green)
Card: none (new lane; no Hermes card)
Skills: tdd, antislop-human, antislop-layoutmobile, ponytail, caveman

## Scope

An independent check of the finished technician app. Add tests and evidence only. A defect becomes a `needs-change` to the owning ticket, with a failing test.

- `integration_test` journeys on the AVD `inbils_pixel_api35` with the fake adapter and fixtures from `docs/api/mobile-v1.openapi.json` or recorded API responses:
  1. Login → My work → SPK → Mulai → capture 2 photos online → declare → submit.
  2. Offline capture → app restart → online → each photo uploaded once (adapter call count = 2).
  3. A timeout mid-upload → a retry with the same key.
  4. `422` on upload → failed → delete.
  5. 401 with queued photos → re-login as the same user → resumes; as another user → wiped.
  6. Ticket start → internal comment → resolve.
  7. A notification → routing for every subject type.
  8. A Material request that is "Siap diambil".
  9. Logout with queued photos → warning.
- Duplicate-prevention proof against the real API (compose stack): force-stop the app during an upload, reopen, sync. The SPK has exactly one media row per captured photo (check with a read-only database query or the web page). Record the counts. Long-delay retries are covered on the server by mobile-api ticket 05 (dedupe after the cache entry is gone); cite that test.
- Accessibility: the Flutter guideline tests on every top-level screen, at least 56 dp on the SPK action bar, a TalkBack walk of journey 1, and 200% text scale screenshots.
- Live smoke on the compose stack (run `tests/e2e/setup.php` first; `adb reverse tcp:8080 tcp:8080`), using the spec's live-smoke steps, with airplane mode toggled through `adb`.

## Owned files

- NEW `../inbils-mobile/technician/integration_test/**`

## Acceptance criteria

- [ ] `flutter test integration_test -d <emulator-id> --flavor dev --dart-define-from-file=env/dev.json` is green, with the output pasted.
- [ ] Every Must-have case in the spec's Testing Decisions maps to a passing test, listed in a ticket comment.
- [ ] The duplicate-prevention proof shows media count = photos captured after a force-stop mid-upload, and the API dedupe test from mobile-api ticket 05 is cited as passing.
- [ ] The accessibility results and the live smoke transcript are recorded as seen, and mismatches with the web (statuses, counts) are reported as findings.
- [ ] No app source file changed in this ticket.

## Comments
