# QA/REVIEW: gates, security review, release (both repos)

Status: done
Role: qa/reviewer
Blocked by: 01, 02, 03, 04, 05, 06, 07
Spec: `../spec.md`

## Scope

Web: Pint, Larastan on changed files, tsc, ESLint, selfchecks, full PHPUnit on `inbils_test`, `npm run build` + full canonical chromium E2E, security review (new API endpoints: uploads, password change, policies, tenant scope, throttles; middleware), Docker image refresh, CHANGELOG, commit + push. Mobile: `flutter analyze`, `flutter test`, integration journeys on the AVD, commit + push to `madmouse17/inbils-mobile`.

## Acceptance criteria

- [x] All gates green with evidence; security review SIGNED; both repos pushed.

## Comments

- 2026-10-14 (orchestrator): web gates: full PHPUnit 3036 passed; full chromium E2E 201 passed / 4 skipped / 4 failed → fixed (app-only logout lands on `/`, report-charts technician needs `technician_id` as admin), rerun 22/22. Mobile: `flutter analyze` clean and `flutter test` green (core 256, technician 216, customer 124); docs screenshot journeys ran on AVD `inbils_pixel7` (technician 23/23, customer 18/18). Security review `AppOnlySecReview`: web SIGNED; mobile EXIF MEDIUM fixed (normalizer in core), SPK planned-line LOW fixed, web attachment delete aligned, Play Store URL guard added. Pushed: web `c69b3d8`, `6489f48`; mobile `a77184c`, `3470a40`. Deploy 8080 (2026-10-14): image rebuilt, `migrate --force`, `RolePermissionSeeder` (technician has `ticket.attachment.upload`), `SystemSettingSeeder` (Play Store keys present), `/up` `/ready` 200.
- Gap (2026-10-14, correction): the device integration journeys (`integration_test/journeys_test.dart`) were NOT run for this lane. Never run: technician journey 10 (ticket attachment upload) and customer journey 4b (attachment on a new ticket). Technician journey 7 failed through the host wrapper (unrelated, unconfirmed on a device). Only the docs-screenshot drives ran on the AVD. Run both suites on `inbils_pixel7` (reboot the emulator between apps) before the Play Store release tickets close.
