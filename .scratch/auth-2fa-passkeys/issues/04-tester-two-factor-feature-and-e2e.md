# TESTER: 2FA feature, regression and E2E coverage

Status: needs-owner-approval
Role: tester
Blocked by: 02, 03
Card: none (new lane; no Hermes card)

## Scope

Tests only (`tests/**`); defects reported as `needs-change` with a failing test.

- Fill gaps in the matrix from `../spec.md` Testing Decisions not already covered by tickets 01-02: leak checks on every serialisation path (Inertia props, `UserResource`, admin Users export, activity log, notification payloads), multi-company login (2FA applies after switching), password reset flow does not bypass the challenge.
- Playwright `tests/e2e/two-factor.spec.ts`: enrol → logout → login with code → recovery-code login → admin reset. Add a TOTP helper to `tests/e2e/support/` and a deterministic secret in `E2eFixtureSeeder` (test DB only). Run `tests/e2e/setup.php` before each run.
- Regression: full `tests/Feature/Auth/*` and `LoginRedirectTest` green.

## Acceptance criteria

- [ ] Each case in the spec matrix has a named test; all pass on MySQL `inbils_test`.
- [ ] Removing `logExcept` entries or the replay guard makes at least one test fail (mutation check noted in comment).
- [ ] E2E passes on the canonical run; exact commands and counts reported.
- [ ] No test writes real secrets; fixtures use fixed fake secrets only.
