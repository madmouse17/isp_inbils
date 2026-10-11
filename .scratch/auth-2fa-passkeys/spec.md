# Auth hardening — TOTP two-factor + recovery codes (passkeys optional)

Status: done
Ledger: none yet (new lane from the isp_ari gap analysis of 2026-10-11)
Source: `/tmp/isp_ari_gap.md` row "Two-Factor Authentication & Passkeys" — **the scout's claim that isp_ari implements it is wrong** (see Corrections).

> New third-party packages are required. Per the repo rules (generic rules 18-20) nothing is installed until the owner approves Q2. "setuju rekomendasi" approves the recommended option of every item.

Approved by owner 2026-10-11: recommended option of every decision ("setuju rekomendasi").

## Owner decisions

1. **Q1 — Scope now: TOTP + recovery codes only, or also passkeys (WebAuthn)?**
   (a) TOTP + recovery codes now, passkeys as a separate later approval. (b) Both now. (c) Passkeys only.
   **Recommend (a).** TOTP covers the phishing-light, session-takeover risk for admin/billing users at the lowest cost and works on every browser. The only first-party passkey package, `laravel/passkeys`, is still `v0.2.1` (pre-1.0; `isp_ari/composer.lock:1737-1738`, latest on Packagist 2026-05-18). Passkeys also need a decision on whether they replace or supplement the password (`isp_ari` migration `2026_07_26_231612_create_passkeys_table.php` only stores credentials). Ticket 06 is written as conditional on (b)/(c).
2. **Q2 — Library / approach.**
   (a) `laravel/fortify` as a library: `Fortify::ignoreRoutes()`, reuse its `EnableTwoFactorAuthentication`, `ConfirmTwoFactorAuthentication`, `DisableTwoFactorAuthentication`, `GenerateNewRecoveryCodes`, `TwoFactorAuthenticationProvider`; keep Breeze controllers and Inertia pages. (b) Direct `pragmarx/google2fa` (^9, MIT) + `bacon/bacon-qr-code` (^3, SVG backend, no GD needed) behind one small `TwoFactorService`, recovery codes generated with `Str::random`/`Crypt` as Fortify does. (c) Replace Breeze auth with full Fortify.
   **Recommend (b) while Q1 = (a).** Fortify 1.37 hard-requires `laravel/passkeys ^0.2.0`, which pulls `web-auth/webauthn-lib` and CBOR/PKI packages into the login path even when passkeys are off (`isp_ari/composer.lock:1446-1470`, `:1737-1760`, `:7977`); it also registers its own login routes unless ignored and its views are Blade-oriented, while inbils uses Breeze + Inertia (`routes/auth.php`, `Pages/Auth/Login.tsx`). (b) adds two small, maintained packages (`google2fa` v9.1.0 2026-08-15, `bacon-qr-code` v3.1.1 2026-04-05, both pure PHP) which Fortify itself wraps, at ~150 lines of owned code. Tradeoff: we own recovery-code generation, secret encryption and replay protection (specified below, test-pinned). If the owner picks Q1 = (b), switch to (a): the Passkeys requirement makes Fortify the sensible host. Packagist metadata checked 2026-10-11; licenses and `composer audit` result must be pasted in ticket 01 before merge (rule 11).
3. **Q3 — Who must use 2FA?** (a) Opt-in for every user in phase 1; enforcement later via a company setting. (b) Mandatory immediately for `admin`. (c) Mandatory for all staff roles. **Recommend (a).** There is no recovery path for a locked-out sole admin except Artisan; ship opt-in, prove the admin-reset flow (Q5), then enforce. Tradeoff: protection is only as good as adoption; ticket 05 reports adoption blockers.
4. **Q4 — Where is the second factor enforced?** (a) At the web login only (Breeze `AuthenticatedSessionController::store`). (b) Also gate password reset login and any future Sanctum token issuance. **Recommend (a) now, with a hard note:** inbils has no token-issuing endpoint today (`laravel/sanctum` is in `composer.json:13` but `User` has no `HasApiTokens`; `docs/MOBILE_TASK_PLAN.md` defers it), so the mobile login lane must call the same `TwoFactorService`. Tradeoff: none today.
5. **Q5 — Lost-device recovery.** (a) Recovery codes (8, single use) + admin reset of another user's 2FA under a new permission `user.two_factor.reset`, never self, audited, limited to targets the actor `administers()` (same rule as the Head Company F2 fix, `CompanyMembershipService.php:138`). (b) Recovery codes only; reset via Artisan. **Recommend (a).** Tradeoff: one more permission to seed (admin only) and re-run `RolePermissionSeeder`.
6. **Q6 — Step-up confirmation for security changes.** Enable, disable, regenerate-codes and reveal-codes require a recent password confirmation. **Recommend yes**, reusing the existing `confirm-password` flow (`routes/auth.php:44-46`, session key `auth.password_confirmed_at` set at `ConfirmablePasswordController.php:37`) via Laravel's `password.confirm` middleware (the route name exists; no route currently uses the middleware).
7. **Q7 — Customer portal users.** Customer-role accounts (created with a phone-number password, `CustomerService.php:23`) can enrol in 2FA like anyone else; no separate enforcement. **Recommend yes**; tradeoff none (same profile page).

## Corrections to the scout report

- **isp_ari does not run 2FA or passkeys.** It has the Fortify migrations and a `redirectUserForTwoFactorAuthentication` hook (`isp_ari/app/Providers/FortifyServiceProvider.php:40`) but `config/fortify.php:169-171` enables only `Features::updatePasswords()`, the comment at `:162-167` says "2FA dapat diaktifkan kembali pada fase hardening", and no controller/view/test under `app/`, `resources/`, `routes/` references two-factor or passkeys beyond the log redaction list (`isp_ari/app/Services/AuditLogger.php:16`). The columns/table exist, the feature does not. So this lane is new work, not parity.
- The scout also says inbils login is "Laravel Breeze": correct (`routes/auth.php`, `AuthenticatedSessionController`, `LoginRequest`).
- `docs/architecture/database.md:47` lists "2FA fields" on `users`; no such migration exists in inbils — doc drift, fix with ticket 01.

## Current implementation map

inbils
- Login: `app/Http/Controllers/Auth/AuthenticatedSessionController.php:31-39` (`$request->authenticate()` → `session()->regenerate()` → forget company switch key), `app/Http/Requests/Auth/LoginRequest.php:41-58` (`Auth::attempt`, throttle 5 tries, key `email|ip` at `:82-85`).
- `app/Models/User.php:32-38` fillable (no 2FA columns), `:45-48` hidden, `:91-100` `LogsActivity` with `logAll()` + `logExcept([...])` — **a new `two_factor_secret`/`two_factor_recovery_codes` column would be written into the activity log unless added to `logExcept`** (spatie logs dirty attributes on `updated`, `User.php:25`).
- Profile: `ProfileController.php:19-25`, `resources/js/Pages/Profile/Edit.tsx` (password/profile/delete cards) — natural home for a Security card.
- Throttle/limit: no named limiters for auth beyond `LoginRequest`; `RateLimiter` already used.
- Permissions: `database/seeders/RolePermissionSeeder.php` (global roles, admin gets all).
- Prior-art tests: `tests/Feature/Auth/*` (Breeze), `TenantBoundaryTest`, `RolePermissionSeederTest`.

isp_ari
- `composer.json:11` fortify ^1.37; `composer.lock:1446` fortify v1.37.3 requires `laravel/passkeys ^0.2.0`, `pragmarx/google2fa ^9.0`, `bacon/bacon-qr-code ^3.0`; migrations `2026_07_26_231611_add_two_factor_columns_to_users_table.php` (`two_factor_secret`, `two_factor_recovery_codes` text, `two_factor_confirmed_at`) and `2026_07_26_231612_create_passkeys_table.php`; features disabled as above. Decision record: `docs/01-decisions.md:276` (public registration off; 2FA deferred).

## Problem Statement

Anyone who learns an admin's or billing clerk's password (phishing, reuse, a customer account whose initial password is a phone number) gets full access to invoices, payments and network records. The login has no second factor and no way to recover or reset one.

## Solution

Users can enrol a TOTP authenticator app from their Profile, confirm it with a code, and receive single-use recovery codes. Once enrolled, login asks for a code (or a recovery code) after the password. Admins can reset a locked-out colleague's second factor. Passkeys are a separate, later, owner-approved step.

## User Stories

1. As any user, I want to turn on two-factor authentication from my profile, so that a stolen password is not enough to log in.
2. As a user enrolling, I want to scan a QR code (and see the setup key as text) with my authenticator app, so that I can set it up on a phone or a desktop app.
3. As a user enrolling, I want to prove the app works by entering a code before 2FA activates, so that I cannot lock myself out with a bad scan.
4. As a user, I want eight single-use recovery codes shown once at confirmation, so that I can still log in if I lose my phone.
5. As a user, I want to regenerate recovery codes (invalidating the old ones) after confirming my password, so that a leaked sheet can be revoked.
6. As a user with 2FA on, I want login to ask for my authenticator code after the correct password, so that only I can finish logging in.
7. As a user without my phone, I want to use a recovery code once at the challenge screen, so that I am not locked out.
8. As a user, I want to turn 2FA off after confirming my password, so that I can change devices on my terms.
9. As an admin, I want to reset another user's 2FA when they lost both phone and codes, so that I can restore their access without database access.
10. As an auditor, I want enable, confirm, disable, recovery-code use/regeneration, failed challenges and admin resets logged without any secret material, so that I can investigate takeovers.
11. As a security reviewer, I want the secret and recovery codes stored encrypted and never returned by any API, resource, Inertia prop or activity-log row, so that a database or log leak does not defeat 2FA.
12. As a user, I want repeated wrong codes to be rate-limited, so that the six-digit code cannot be brute-forced.
13. As a user, I want a code that was already used to be rejected within its time window, so that an intercepted code cannot be replayed.
14. As a multi-company user, I want 2FA to apply to my account regardless of which company I switch into, so that membership changes do not bypass it.
15. As a developer of the future mobile/API login, I want the same service to verify the second factor, so that tokens cannot be issued with password only.

## Implementation Decisions

- Schema (migration on `users`): `two_factor_secret` text null, `two_factor_recovery_codes` text null, `two_factor_confirmed_at` timestamp null (same shape as the isp_ari migration so a later move to Fortify needs no data change). Not fillable. Hidden on serialisation. **Added to `User` `logExcept`.** Secret and codes use Laravel's encrypted casts.
- `TwoFactorService` (core Services layer, domain logic out of controllers): `beginEnrollment` (generate secret, return provisioning URI + inline SVG QR), `confirm` (verify code, set `two_factor_confirmed_at`, generate 8 recovery codes), `verifyChallenge` (TOTP with ±1 window and **replay guard** storing the last accepted time-step per user; or recovery code, consumed on use, constant-time compare), `regenerateRecoveryCodes`, `disable`, `adminReset`. Pending (unconfirmed) enrolment does not enforce a challenge.
- Login flow: after `Auth::attempt` succeeds for a user with confirmed 2FA, log the user out of the guard, store only the user id + remember flag in session, redirect to a challenge page; the session is regenerated and the company-switch key cleared only after the challenge succeeds (preserves `AuthenticatedSessionController.php:35-37` behaviour). Challenge failures use the existing `RateLimiter` pattern (5 per minute per user+ip) and the same generic error style as `LoginRequest`.
- Profile endpoints under the existing `auth` group: enable (POST), confirm (POST), regenerate codes (POST), disable (DELETE), all behind `password.confirm`. Responses never include secret or stored codes; the QR/setup key and fresh recovery codes are flashed once.
- Admin reset: `POST /admin/users/{user}/two-factor/reset`, permission `user.two_factor.reset` (seeded to admin), `UserPolicy` + `CompanyMembershipService::administers($actor,$target)`, never self, audited with log name `security`.
- Audit: `AuditService::log('security', 'two_factor.<event>', …)` with ids only.
- Inertia: Security card in `Profile/Edit.tsx`, new `Auth/TwoFactorChallenge.tsx`; user rows in the admin Users page show a "2FA" badge and a reset action for permitted actors. Existing `Components/ui`/`composite`, antislop-human rules (focus on code field, `autocomplete="one-time-code"`, no colour-only state).
- Docs: correct `docs/architecture/database.md:47`; add a security note to `docs/SECURITY.md`.
- Dependencies (Q2): `pragmarx/google2fa ^9.0`, `bacon/bacon-qr-code ^3.0` only; no change to Laravel/Inertia versions.

## Testing Decisions

- Good tests drive HTTP (login, challenge, profile endpoints) and assert redirects, session state and database effects; they do not assert on TOTP internals except via a fixed-clock helper.
- Seams: `TwoFactorService` (unit with frozen time), the login/challenge routes (feature), the Profile Security card and challenge page (Playwright).
- Cases: enrol/confirm happy and bad code; login with/without 2FA; challenge with valid TOTP, expired code, replayed code, recovery code (single use), 6th wrong attempt throttled; password-confirm required for enable/disable/regenerate; admin reset allowed/denied (self, out-of-membership target, non-admin); secret/codes absent from Inertia props, API/resource output and `activity_log` rows; unconfirmed enrolment does not block login; session company key cleared only after the challenge.
- Prior art: `tests/Feature/Auth/*`, `Feature/TenantBoundaryTest.php`, `Unit/RolePermissionSeederTest.php`, `tests/e2e/support/demo.ts` `loginAs` (needs a TOTP helper in the E2E support module; seed a deterministic secret only in `E2eFixtureSeeder`).
- Security gate: `security-reviewer` pass required (generic rule 10); `composer audit` + `npm audit` pasted (rule 11).

## Out of Scope

- Passkeys (unless Q1 ≠ (a); then ticket 06).
- SMS/e-mail OTP, push approval, "remember this device", per-company enforcement settings, Sanctum/mobile token issuance, forced enrolment (Q3 follow-up).
- Replacing Breeze with Fortify.

## Further Notes

- Ticket order: 01 backend schema+service → 02 backend login/endpoints/admin reset → 03 ui → 04 tester → 05 security review → 06 passkeys (conditional).
- Risk to confirm with the owner: with opt-in only (Q3a) the lane adds capability, not protection, until enforcement is approved.
