# MOBILE + BACKEND: GPS check-in at the job site

Status: done
Role: mobile (app) + backend (API field)
Blocked by: 04 (start/submit), mobile-api 05
Card: none (owner added 2026-10-13)

## Scope

- On Start of an SPK or ticket, the technician app asks for foreground location permission (`geolocator`, while-in-use only), reads one fix (timeout 15 s, accuracy reported) and sends `{lat, lng, accuracy_m, captured_at}` with the start action.
- API (mobile-api 05/04): start endpoints accept the optional check-in, store it on the work order/ticket activity (audit log properties, no new PII table), and compute the distance to the job location when the customer address or location has coordinates. No hard block on distance in v1: the web SPK/ticket page shows "check-in 120 m from site" or "no location".
- Permission denied or no fix: start still works, check-in recorded as `unavailable` with the reason.
- No background location, no tracking between jobs.

## Acceptance criteria

- [x] (API side) Start with permission granted stores lat/lng/accuracy and the computed distance; denied stores `unavailable`. Mobile capture done.
- [x] Android manifest declares only ACCESS_FINE_LOCATION/ACCESS_COARSE_LOCATION (no background location).
- [x] (API side) API feature test for the stored check-in and the distance. Widget tests for the permission-denied path (SPK and ticket) and reader unit tests are in.
- [x] Web SPK/ticket show displays the check-in summary (read-only check: SPK `checkInSummary` built by `FieldCheckIn::summary` in WorkOrderController and shown in SPK/Show.tsx; ticket `check_in` in Tickets/Show.tsx, fallback `no location`; no web edit needed).

## Comments

- API done: `check_in` on `POST technician/work-orders/{wo}/start` and `technician/tickets/{id}/start`, stored as activity log (`check_in`) properties, no new table. Technician work order and ticket resources expose `check_in` {status: recorded|unavailable|none, distance_m, accuracy_m, captured_at, reason}. Web props: SPK `checkInSummary` (string), ticket `check_in` (string) already rendered. Open: mobile capture, manifest, widget test.

Mobile done, commit `7328014` in `inbils-mobile` (not pushed). `CheckInReader` (`technician/lib/features/work/check_in.dart`): checkPermission, one requestPermission when undecided, service check, one `getCurrentPosition` (high accuracy, 15 s `timeLimit`); reasons `permission_denied`, `permission_denied_forever`, `location_disabled`, `timeout`, `no_fix`; it never throws, so a start is never blocked. Read once per start attempt and kept until the attempt settles, so a retry with the same idempotency key sends the same body. Sent as `{check_in: {...}}` on SPK and ticket start. Manifest: FINE and COARSE only. Added dev dependency `geolocator_platform_interface` (fake in reader tests). `dart format` clean, `flutter analyze` 0 issues, `flutter test` 154 passed in technician (7 reader/start tests new).

Live (AVD `inbils_pixel_api35`, dev flavor, `tests/e2e/setup.php`, two throwaway SPKs, Laravel `APP_ENV=e2e` on 8011 via `adb reverse`, under `/tmp/inbils-phpunit.lock`): granted — Mulai showed the system dialog, "While using the app", SPK went Dikerjakan; with `adb emu geo fix 106.8166667 -6.2013` the activity_log row was `recorded`, accuracy 5, `distance_m` 144. Denied — permission revoked with `pm revoke`, "Don't allow": SPK still started, row `unavailable`, reason `permission_denied_forever` (Android reports a repeat refusal as permanent). `dumpsys package` lists only the two location permissions. Web page not opened in a browser; display checked by code only. Teardown: emulator, servers, lock holder, `adb reverse`, `env/dev.local.json` and temp files removed; seeded rows remain in `inbils_test` until the next `tests/e2e/setup.php`.
