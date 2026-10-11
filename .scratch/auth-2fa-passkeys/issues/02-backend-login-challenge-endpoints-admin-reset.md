# BACKEND: login challenge, profile endpoints, admin reset, permission

Status: needs-owner-approval
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

- [ ] User without 2FA logs in exactly as before (existing `tests/Feature/Auth/*` unchanged and green).
- [ ] User with 2FA and correct password is **not** authenticated after `POST /login`; after a valid code they are, and `session('active_company_id')` equivalent key is cleared.
- [ ] Wrong code ×5 within a minute → the 6th attempt is throttled (429/validation error), even with a correct code until the window passes.
- [ ] Recovery code logs in once and fails the second time.
- [ ] Enable/confirm/regenerate/disable without recent password confirmation redirect to `confirm-password`.
- [ ] Admin reset: 200 for admin on a target in shared membership; 403 for self, for a non-admin, and for a target the actor does not `administers()`; target's secret, codes and confirmed_at are null afterwards; audit row exists with no secret material.
- [ ] `php artisan db:seed --class=RolePermissionSeeder` adds `user.two_factor.reset`; deploy note recorded.
