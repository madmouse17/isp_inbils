# BACKEND: support actions, tenant export, platform audit page data

Status: done
Role: backend
Blocked by: 02, 03
Spec: `../spec.md`

## Scope

Reset tenant user 2FA, resend invitation, unlock throttled login (limiter keys), failed jobs/notifications per tenant, export job (R5) with signed download + cleanup schedule, platform audit page query. Tests per spec.

## Acceptance criteria

- [x] Focused PHPUnit green; Pint.

## Comments

### PlatformSupport (stopped at the request budget: tests written, NO implementation yet; status stays ready-for-agent)

State: `tests/Feature/Platform/SupportActionsTest.php` and `tests/Feature/Platform/TenantExportTest.php` exist, pass `php -l`, have NEVER been run (every route/class they use is missing, so they are red by construction). `PlatformAuditTest.php` is not written. No app file, route file, command or `routes/console.php` line exists yet. Nothing in `routes/platform.php` needs editing: it already globs `routes/platform/*.php` (S04 confirmed it adds only `routes/platform/impersonation.php`).

Decisions the tests pin (the implementer follows them or changes test and design together):
- Routes (names exact, inside the `['auth:platform','platform.account','platform.2fa']` group of the shell, `{company}` numeric, `{user}` numeric): `POST companies/{company}/users/{user}/two-factor/reset` (+ `platform.password.confirm`), `POST .../invitation` and `POST .../unlock` (no confirmation), `GET companies/{company}/failed-jobs` and `.../failed-notifications` (JSON, `JsonResource::collection` + `window_days`), `POST companies/{company}/exports` (+ `platform.password.confirm`, request extends `ConfirmedTenantRequest`), `GET exports` (`Platform/Exports/Index`), `GET exports/{tenantExport}/download` (signed, numeric id), `GET audit` (`Platform/Audit/Index`). Files: `routes/platform/{support,exports,audit}.php`.
- Reach: a target user must be HOMED in the tenant tree (`users.company_id` in head + subsidiaries). A mere member from another tenant is a 404, because the second factor is account-wide. `SupportToolsService::{resetTwoFactor,resendInvitation,unlockLogin,failedJobs,failedNotifications}`; the first three audit as a `platform` row and a `support` row (subject = the `User`; support properties are exactly `{user_id, company_id}`), actions `user.two_factor_reset|invitation_resent|login_unlocked`. The reset calls `TwoFactorService::adminReset` (which already writes the `security` row, kills tokens and DB sessions).
- Resend: only an active `admin` who never signed in (the mail copy says "admin"), refusal = validation error `invitation` that spends no budget; 6 per minute per target user through `RateLimiter` key `platform-invitation|{userId}`, over the limit = `ThrottleRequestsException` (429 + `Retry-After`).
- Unlock clears `two-factor-user|{id}` (the 15-minute shared TOTP key) only. The per-IP keys (`two-factor|{id}|{ip}`, web login `email|ip`) decay in 60 s and cannot be enumerated; a helper in `LoginRequest`/`TwoFactorChallengeRequest` (not mine) would be needed to clear them.
- Failed lists: jobs attributed by `PlatformTenantListQuery::companyOfFailedJob` over a 30-day window (shortcut: no company column on `failed_jobs`; ids collected in one pass, then one fetch of the page); notifications = `whatsapp_messages` with `status = 'failed'` over the tree. Row shapes are in the tests (`company {id, code}`, no payload, first exception line only).
- Export: `TenantExport` statuses are `queued`/`running`/`ready`/`failed`/`expired` (the model constants; "pending/building" of the brief map to queued/running). `BuildTenantExportJob(int $exportId, int $companyId)`: `ShouldBeUnique` per tenant id, connection+queue `platform`, tries 1, timeout 3600, `failOnTimeout`, public `STALE_AFTER_HOURS = 2` and `$uniqueFor` below it. One active export per tenant (queued/running younger than 2 h blocks, error key `export`). Archive = `data/{table}.csv` for every `TenantTableRegistry::tables()` table except `tenant_exports`, the 4 pivots, `data/companies.csv`, `manifest.json` (keys `export_uuid, generated_at, timezone, tenant, companies, csv, row_counts, omitted_columns, media`), and `files/{disk}/companies/{id}/...` for every tree company on `local`, `public`, the media disk and any disk a media row names (non-Flysystem-local disks read through `readStream`). CSV = BOM + header, `fputcsv(..., escape: '')`, values unchanged (no formula escaping: it is a data handover, not a spreadsheet report). Omitted columns: `users.{password,remember_token,two_factor_*,must_change_password}`, `nas_devices.radius_secret`, `network_accounts.{password,provisioned_password_hash}`, `payment_attempts.{snap_token,redirect_url,last_notification}`; any column named/ending password|secret|token|remember_token or `two_factor_*` is dropped and listed. Platform `activity_log` rows are skipped, support rows included. Build in `platform/exports/tmp/{uuid}`, final `platform/exports/{uuid}.zip` on `local`, `expires_at` = completion + 24 h. `failed()` removes partials, stores `Class: message` (<= 300 chars, no trace). Audit actions `tenant.export_requested|export_completed|export_downloaded` (platform + support), `tenant.export_failed` (platform only), `tenant.export_expired` (platform, system-caused, `reason` = `window_elapsed|file_missing`).
- Download: signed link `URL::temporarySignedRoute('platform.exports.download', $export->expires_at, ['tenantExport' => id])`; bad signature/expiry = 403; record not `ready`, past `expires_at` or file gone = 404 (file gone also marks the record `expired`). Headers `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`, `Content-Disposition` carries the lowercase tenant code. `exports.index` rows: `{id, uuid, tenant {id,name,code}, status, file_size, row_counts, error_message, requested_by, created_at, completed_at, expires_at, download_url|null}`; `filters {company_id, status, page, per_page}`. Do NOT add `download_url` to `TenantDetailQuery` (a test of S03A pins its absence).
- Cleanup `inbils:platform:exports:prune` (`PruneTenantExportsCommand`) runs hourly with `withoutOverlapping()` per design §4.13. It expires `ready` rows past `expires_at` (delete archive + tmp dir, keep row and `row_counts`), fails `queued`/`running` rows older than 2 h, and prints both counts.
- Audit page (`PlatformAuditQuery`, to write with `PlatformAuditTest`): `log_name in (platform, support)`; filters `company_id` (tenant head, includes subsidiaries), `platform_admin_id`, `event` (exact match on `description`; the `event` column is empty for these rows), `date_from/date_to` (UTC calendar days), `search` (description, causer name/email, tenant name/code), `page`, `per_page` 10/25/50; props `activity` (paginated), `filters`, `tenants` and `admins` options; causer eager-loaded (constant query count); rows whose `company_id` is NULL after a purge show `properties.tenant_code/tenant_name`. S04 row contract: actions `impersonation.started|mode_changed|write|ended`, every row of a session has `batch_uuid` = impersonation id.

Not mine, report to the owner: `composer.json` does not declare `ext-zip` (Dockerfile installs it); `docs/operations/JOBS_NOTIFICATIONS.md` + `RELIABLE_JOBS.md` need the schedule row; CHANGELOG is S08's.

Evidence so far: only a timing baseline, `PurgeTenantCommandTest --filter=test_a_purge_deletes` = 1 passed (52 assertions), 48 s for two seeded tenants (so the two-tenant export test costs about the same).

### PlatformSupport2 (implemented 2026-10-17)

- Implemented support actions/lists, tenant ZIP builder/job/signed download/index, hourly pruning, and platform audit query/routes/controllers.
- Added `tests/Feature/Platform/PlatformAuditTest.php` and cross-tenant pivot regression coverage; ticket tests pass together: 53 tests, 1115 assertions.
- Corrected three impossible/stale test assumptions: global `TrimStrings` normalizes `ALP `, MySQL JSON normalizes property key order, and design §4.13 requires hourly rather than daily pruning.
- `composer.json` now declares `ext-zip`; operations schedule/job tables document pruning and `BuildTenantExportJob`.
- Security review hardened pivot isolation and rejected unsafe remote media paths. Residuals: machine-only CSV preserves formulas; raw first-line error text remains operator-only; archive hashing/tamper-evident logs remain parked.
