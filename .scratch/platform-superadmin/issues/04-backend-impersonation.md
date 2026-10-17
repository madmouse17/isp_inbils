# BACKEND: impersonation start/stop, read-only and write modes, audit

Status: done
Role: backend
Blocked by: 02
Spec: `../spec.md`

## Scope

Start/stop/mode endpoints (platform guard), session contract from the design, read-only middleware in the tenant web stack, forbidden targets/pages, 30-minute expiry, shared Inertia prop `impersonation` for the banner, `AuditService` real-actor recording (platform + tenant logs, SA7). Tests per spec.

## Acceptance criteria

- [x] Focused PHPUnit green; Pint.

## Comments

2026-10-17, PlatformImpersonation2: implemented exact impersonation session keys, platform start/read/write/stop routes, target/tree validation, read-only and security-page enforcement, out-of-tree switch denial, 30-minute expiry, tenant/platform logout cleanup, platform-real-actor activity batches, support audit visibility/masking, and the shared tenant Inertia prop `{admin_name,user_name,company_name,company_code,mode,expires_at,reason,write_url,read_url,stop_url,confirm_url}` (urls absolute; see the follow-ups below).

Evidence: red first `tests/Feature/Platform/ImpersonationTest.php` = 1 failed (404 route), then green = 13 passed (162 assertions). Combined impersonation + tenant-audit run = 33 passed (588 assertions). Focused `tests/Feature/Auth` + `tests/Feature/Tenant` gate run completed after sibling route wiring; PHPStan level 5 passed on touched core/query/test files; Pint `--test` passed on all 16 touched PHP files. Route proof: 3 mode/stop routes plus 1 start route listed.

2026-10-17, PlatformImpersonation2 follow-ups (tests in `tests/Feature/Platform/ImpersonationTest.php`):

- Banner prop: `company_code`, `write_url`, `read_url`, `stop_url`, `confirm_url` added. The prop is `Inertia::always`, so a partial reload that did not ask for it still refreshes `confirm_url` (test red without `always`: "Property [impersonation.confirm_url] does not exist").
- Write mode in one round trip: `confirm_url` carries `?return_to=<current tenant path+query>`. `ImpersonationService::tenantPage()` accepts only `/admin…` or `/app-only…` (no host, user info, dot segment, encoded `.` `/` `\`, backslash, space, control character, fragment, or more than 2048 characters). The platform confirm `store` reads it from the confirm page URL (Referer, else the session's previous URL, so a mistyped password keeps it) and, only while a support session is active, answers with `Inertia::location` (a full page load) of that tenant page; otherwise the old redirect applies (interrupted visit, the dialog's own page, the panel dashboard). Files: `ActiveImpersonation::toProp`, `HandleInertiaRequests`, `Platform/Auth/ConfirmablePasswordController`.
- Security review Low 1: `ImpersonationService::end()` calls `$session->regenerate(destroy: true)`; the test proves the session record the support session ran under is gone after a manual stop, a tenant logout and the expiry.
- Security review Low 2: `admin.subscriptions.network-account.reveal` joins the always-forbidden branch of `EnforceImpersonation` (`SECURITY_MESSAGE` in read and write mode); the test proves 403 in both modes and no `secret_revealed` activity row.
- Security review Info: `TwoFactorService::adminReset` resolves its actor through `CauserResolver`, so a reset made inside a support session logs `actor_type=platform_admin`, `actor_id=<operator>` and the platform admin as causer (plain tenant and platform callers are unchanged).

Evidence (follow-ups): red first = 6 failed, 13 passed (210 assertions); green `ImpersonationTest` = 20 passed (332 assertions) with the partial-reload test (seen red without `always`). Regression `tests/Feature/Platform tests/Feature/Auth tests/Feature/Admin/UserTwoFactorResetTest.php tests/Feature/Reporting/AuditLogTenantIsolationTest.php tests/Feature/Api/V1/Platform/TokenRevocationTest.php tests/Feature/Tenant` = 454 passed (6352 assertions). PHPStan level 5: no errors on the 6 touched `app/` files; Pint `--test` passed on the 7 touched PHP files.
