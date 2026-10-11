# BACKEND: users 2FA columns, TwoFactorService, dependencies

Status: done
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

- [x] Migration up/down run on `inbils_test`; `users` rows untouched. (`tests/Feature/Auth/TwoFactorMigrationTest.php`; migration `2026_10_12_500001`.)
- [x] Unit tests with a frozen clock: valid code accepted once; same code rejected on replay; code outside ±1 step rejected; recovery code works exactly once; regenerate invalidates old codes; unconfirmed enrolment is not "enabled". (`tests/Feature/Auth/TwoFactorServiceTest.php`.)
- [x] Updating a user's 2FA columns writes **no** secret/recovery value into `activity_log` (test reads the `properties` JSON). Mutation-checked: dropping the `two_factor_*` entries from `logExcept` fails `test_the_two_factor_columns_never_reach_the_user_activity_log` and prints the plaintext secret and codes.
- [x] `$user->toArray()`/`toJson()` and Inertia shared props contain none of the three columns.
- [x] `composer audit` output pasted; no advisory on the two new packages (or each advisory reported with package, CVE, severity, fixed version, update command). `composer audit` → "No security vulnerability advisories found."
- [x] Pint and scoped Larastan clean. (Larastan: only the pre-existing `VerifyEmailController.php:22` finding remains.)

## Notes

- Added a fourth column, `two_factor_last_used_step` (unsigned bigint, null): the replay guard needs durable per-user storage that a cache flush cannot reopen. It stays compatible with Fortify's three columns.
- Packages: `pragmarx/google2fa` v9.1.0 (MIT, 2026-08-15), `bacon/bacon-qr-code` v3.1.1 (BSD-2-Clause, 2026-04-05; needs ext-iconv and ext-xmlwriter, both loaded in the Sail container and on the host; the deploy image `dunglas/frankenphp:php8.4-bookworm` was not rebuilt here), transitive `dasprid/enum` 1.0.7 and `paragonie/constant_time_encoding` v3.1.3. Four packages in total.
