# BACKEND: platform guard, accounts, 2FA mandatory

Status: done
Role: backend
Blocked by: 01
Spec: `../spec.md`

## Scope

`platform_admins` migration/model/factory, auth guard + provider, `/platform/login` (+ throttles), mandatory 2FA enrolment (reuse `TwoFactorService` generically), `password.confirm` for the platform guard, idle timeout middleware, `inbils:platform:admin-create` command, platform audit (`log_name=platform`). Tests per spec (guard isolation, 2FA, throttles).

## Acceptance criteria

- [x] Focused PHPUnit green; Pint; `docs/SECURITY.md` platform section.

## Comments

2026-10-16, PlatformBackend: done per design §8 S02.

Built: `platform_admins` migration/model/factory (no `company_id`, no remember column, hashed password, encrypted 2FA columns); guard `platform` + provider (`config/auth.php`); `config/platform.php` (`PLATFORM_DOMAIN`, bound in `routes/platform.php`, lazily trusted in `bootstrap/app.php`); `config/ziggy.php` groups + `app.blade.php` picks `platform` or `tenant`; `PlatformServiceProvider` (`platform` middleware group, loads `routes/platform.php` which globs `routes/platform/*.php`); route-aware guest/user redirects; middleware `platform.account` (active + 30-minute idle, audited), `platform.2fa` (enrolment confinement, per-session proof), `platform.password.confirm` (own key `platform.auth.password_confirmed_at`, 300 s, 423 for JSON), all ordered before `SubstituteBindings`; `HandlePlatformInertiaRequests` (only `platform.admin`, `flash`, `app`); controllers/requests under `App\Http\{Controllers,Requests}\Platform\Auth` (login 5/min per email+IP and 20/h per email, challenge keys `platform-two-factor|{id}|{ip}` 5/60 s and `platform-two-factor-admin|{id}` 10/900 s, confirm key `platform-password-confirm|{id}|{ip}`); `TwoFactorService` + `TwoFactorChallengeRequest` generic over `User|PlatformAdmin` (own pending key, `disable(PlatformAdmin)` 403, no tenant token/session/remember-token effects, `adminReset` records `actor_id` + `actor_type`); `PlatformAuditService::platform()/support()` over `AuditService::log` (new trailing `?Model $causer`); `inbils:platform:admin-create`; `docs/SECURITY.md` §5 (text of design S0-DOC).

Evidence: red = `tests/Feature/Platform tests/Unit/Platform` with the provider switched off, 38 failed / 27 passed of 65 (routes undefined); green = same files 65 passed; final focused run `tests/Feature/Platform tests/Unit tests/Feature/Auth tests/Feature/Tenant tests/Feature/CompanyProfileTest.php tests/Feature/Admin/UserTwoFactorResetTest.php tests/Feature/TrustedHostsTest.php tests/Feature/Reporting/TechnicianReportFlagTest.php tests/Feature/Api/V1/Auth` = 502 passed (3386 assertions). Pint clean on touched files; `phpstan analyse` (level 5) on the new/changed app files: no errors.

Deviations / outside the slice (all small):
- `PlatformAuditService::platform()/support()` return `?ActivityContract` (null only when activity logging is off globally) and take an optional trailing `?PlatformAdmin $causer` (a login or a job has no guard session).
- `adminReset` audit always carries `actor_type` (`user` | `platform_admin`): `TwoFactorServiceTest` and `UserTwoFactorResetTest` were updated for the extra key.
- `Modules/Reporting/app/Providers/ReportingServiceProvider.php`: the global Inertia share `canTechnicianReport` threw a TypeError for a `PlatformAdmin` (default guard on platform pages); it now asks only a `User`. `tests/Traits/EnrollsTwoFactor.php` accepts a `PlatformAdmin`.
- `docs/SECURITY.md` §5 appended from the design text because this ticket's acceptance names it; `AGENTS.md` Company Rules, `ADR-003-platform-superadmin.md` and the `.gitignore` allowlist line (S0-DOC) are NOT applied: needs-change (note `docs/architecture/decisions/ADR-003-full-shadcn-core-ui.md` already uses number 003).
- `platform.dashboard` exists because ticket 03 started (`routes/platform/tenants.php`); redirects in S02 point at it.

Risks: the stale-session rule relies on `platform.two_factor_verified_at`, written only by the challenge and by enrolment; React pages `Platform/Auth/*` do not exist yet (ticket 06), so tests pass `false` to `->component()`.
