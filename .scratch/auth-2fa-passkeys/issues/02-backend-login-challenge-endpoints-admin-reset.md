# BACKEND: login challenge, profile endpoints, admin reset, permission

Status: done
Role: backend
Blocked by: 01
Card: none (new lane; no Hermes card)

## Scope

- Login: when the authenticated user has confirmed 2FA, `AuthenticatedSessionController::store` does not complete login; it stores user id + remember flag in the session, redirects to `GET /two-factor-challenge`; `POST /two-factor-challenge` verifies a TOTP or recovery code via `TwoFactorService`, then logs in, regenerates the session and clears the company-switch session key (current behaviour of `store`). Rate limit 5/min per user+ip with the existing `RateLimiter` pattern; generic error message.
- Profile endpoints (auth group, `password.confirm` middleware): `POST /user/two-factor` (begin), `POST /user/two-factor/confirm`, `POST /user/two-factor/recovery-codes`, `DELETE /user/two-factor`. Secret/QR and fresh codes flashed once; never in later responses.
- Admin reset: `POST /admin/users/{user}/two-factor/reset`, permission `user.two_factor.reset` (seed to admin in `RolePermissionSeeder`; update `RolePermissionSeederTest`), `UserPolicy` method using `CompanyMembershipService::administers`, never self.
- Audit every event with `AuditService` log name `security`, ids only.
- Form Requests for all inputs; thin controllers.

Non-goals: Blade/Inertia pages (ticket 03), enforcement policy, Sanctum, passkeys.

## Acceptance criteria

- [x] User without 2FA logs in exactly as before (existing `tests/Feature/Auth/*` unchanged and green).
- [x] User with 2FA and correct password is **not** authenticated after `POST /login`; after a valid code they are, and `session('active_company_id')` equivalent key is cleared. (`TwoFactorLoginTest`)
- [x] Wrong code ×5 within a minute → the 6th attempt is throttled (validation error on `code`), even with a correct code until the window passes.
- [x] Recovery code logs in once and fails the second time.
- [x] Enable/confirm/regenerate/disable without recent password confirmation redirect to `confirm-password`. (`TwoFactorEnrollmentTest`)
- [x] Admin reset: success (redirect back with a flash, the Inertia equivalent of 200) for admin on a target in shared membership; 403 for self, for a non-admin, and for a target the actor does not `administers()`; target's secret, codes and confirmed_at are null afterwards; audit row exists with no secret material. (`tests/Feature/Admin/UserTwoFactorResetTest.php`)
- [x] `php artisan db:seed --class=RolePermissionSeeder` adds `user.two_factor.reset`; deploy note recorded. Seeder line and `test_two_factor_reset_permission_is_admin_only` applied by the integration owner; deploy note in `docs/SECURITY.md` §3.4.

## Security review round 1 (SecReviewTwoFactor, signed with findings) — fixed red-first

Red on the old code: 23 of the 30 new tests failed; green after the change (30 passed; Auth + Profile + Admin + Tenant batch 417 passed).

- [x] MEDIUM 2FA-THROTTLE-RACE: every challenge attempt is counted before it is checked (`RateLimiter::hit()`, an atomic increment) at 5/min per user+IP and 10/15 min per user across addresses; a throttled attempt is never checked; a pass clears both. `TwoFactorChallengeThrottleTest`.
- [x] LOW 2FA-NO-SESSION-REVOCATION: confirm, regenerate codes, disable (when on) and admin reset rotate `remember_token`; the acting session gets a new id with the old record destroyed; with `SESSION_DRIVER=database` the user's other `sessions` rows are deleted. File/Redis sessions cannot be listed by user (residual in `docs/SECURITY.md` §3.5). `TwoFactorSessionRevocationTest`.
- [x] LOW 2FA-STEPUP-UNTHROTTLED: `POST /confirm-password` allows 5 wrong passwords per minute per user+IP, counted first. `PasswordConfirmationThrottleTest`.
- [x] INFO 2FA-CONFIRM-WHITESPACE: spaces (including non-breaking and thin) are ignored in the confirm and challenge codes. `TwoFactorCodeFormatTest`.
- [x] Pre-existing, taken over: `users.is_active` enforced at the password step and at the challenge step (generic error); `POST /forgot-password` throttled 6/min and answers uniformly. `InactiveUserLoginTest`, `ForgotPasswordTest`.
- [ ] Not done: INFO 2FA-FLASH-PLAINTEXT-SESSION (needs `dontFlash(['code'])` in `bootstrap/app.php` and `SESSION_ENCRYPT=true` in production); the password step's own check-then-count throttle (Breeze); ending sessions and remember cookies of a user deactivated later (no per-request `is_active` check exists).
