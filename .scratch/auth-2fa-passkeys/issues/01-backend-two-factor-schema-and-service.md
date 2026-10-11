# BACKEND: users 2FA columns, TwoFactorService, dependencies

Status: needs-owner-approval
Role: backend
Blocked by: none (starts only after owner approves `../spec.md` Q1-Q7, especially Q2 library approval)
Card: none (new lane; no Hermes card)

## Scope

- `composer require pragmarx/google2fa:^9.0 bacon/bacon-qr-code:^3.0` (only if Q2 = (b)); paste licence, maintenance, size and `composer audit` result in a ticket comment; no other dependency changes.
- Migration on `users`: `two_factor_secret` text null, `two_factor_recovery_codes` text null, `two_factor_confirmed_at` timestamp null; reversible.
- `User`: encrypted casts for secret and codes, `hidden`, **add all three columns to `logExcept`**, helper `hasTwoFactorEnabled()` (confirmed_at not null). Not fillable.
- `TwoFactorService` per spec: `beginEnrollment`, `confirm` (8 recovery codes), `verifyChallenge` (TOTP ±1 step + replay guard via last-used time-step; recovery code consumed on use, constant-time), `regenerateRecoveryCodes`, `disable`, `adminReset`.
- Correct `docs/architecture/database.md:47` and add the security note to `docs/SECURITY.md`.

Non-goals: routes/controllers/login changes (ticket 02), UI, passkeys, Fortify.

## Acceptance criteria

- [ ] Migration up/down run on `inbils_test`; `users` rows untouched.
- [ ] Unit tests with a frozen clock: valid code accepted once; same code rejected on replay; code outside ±1 step rejected; recovery code works exactly once; regenerate invalidates old codes; unconfirmed enrolment is not "enabled".
- [ ] Updating a user's 2FA columns writes **no** secret/recovery value into `activity_log` (test reads the `properties` JSON).
- [ ] `$user->toArray()`/`toJson()` and Inertia shared props contain none of the three columns.
- [ ] `composer audit` output pasted; no advisory on the two new packages (or each advisory reported with package, CVE, severity, fixed version, update command).
- [ ] Pint and scoped Larastan clean.
