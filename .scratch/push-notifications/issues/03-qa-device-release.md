# QA: device smoke with real FCM, review, release

Status: ready-for-agent
Role: qa
Blocked by: 01, 02
Spec: `../spec.md`

## Scope

AVD `inbils_pixel7` with the real Firebase project: log in, allow notifications, `inbils:push:test` from the web → heads-up shown in foreground, shown in background, tap opens the right screen and marks it read; token deleted on logout; invalid-token cleanup exercised by deleting the app and sending again. Security review (token ownership, no PII in logs, credentials outside repo), full PHPUnit + E2E, CHANGELOG with deploy (`composer install`, `migrate --force`, `FIREBASE_CREDENTIALS` mount, queue restart), commit + push both repos, 8080 refresh.

## Acceptance criteria

- [ ] Device evidence (screenshots) + gates green; pushed.

## Comments
