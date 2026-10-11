# TESTER: 2FA feature, regression and E2E coverage

Status: done
Role: tester
Blocked by: 02, 03
Card: none (new lane; no Hermes card)

## Scope

Tests only (`tests/**`); defects reported as `needs-change` with a failing test.

- Fill gaps in the matrix from `../spec.md` Testing Decisions not already covered by tickets 01-02: leak checks on every serialisation path (Inertia props, `UserResource`, admin Users export, activity log, notification payloads), multi-company login (2FA applies after switching), password reset flow does not bypass the challenge.
- Playwright `tests/e2e/two-factor.spec.ts`: enrol → logout → login with code → recovery-code login → admin reset. Add a TOTP helper to `tests/e2e/support/` and a deterministic secret in `E2eFixtureSeeder` (test DB only). Run `tests/e2e/setup.php` before each run.
- Regression: full `tests/Feature/Auth/*` and `LoginRedirectTest` green.

## Acceptance criteria

- [x] Each case in the spec matrix has a named test; all pass on MySQL `inbils_test`. Notification payloads were not tested: every notification builds an explicit `toArray()` and none serialises a `User`.
- [x] Removing `logExcept` entries or the replay guard makes at least one test fail (mutation check noted in comments). Run once: logExcept removal fails `test_the_two_factor_columns_never_reach_the_user_activity_log`; ignoring the last step fails 4 tests (service ×3, login ×1).
- [x] E2E passes on the canonical run; exact commands and counts in the lane report (7 passed, 1 skipped; 3 passed with the permission granted).
- [x] No test writes real secrets; fixtures use fixed fake secrets only (random secrets from the service, the RFC 6238 vector for the Node helper).
