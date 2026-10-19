# Logs and audit trail: implementation design

- Lane: `log-audit`. Spec: `.scratch/log-audit/spec.md`, owner decisions 1–8 (accepted). Evidence: `local://log-audit.md`.
- Architect: LogAuditDesign-2. Standing owner rule: take the recommended option. Every open decision in §7 already carries its default.
- Skills for every slice: `skill://laravel-best-practices`, `skill://tdd`, `skill://ponytail`. Frontend work also reads `skill://inertia-react-development` and `skill://tailwindcss-development`.

## 1. Problem statement

Today the audit trail and the technical logs miss the facts an auditor or an on-call engineer needs. They are also unbounded in volume.

- **D1. Security events.** Only 2FA and API-token events are recorded. Nothing records:
  - a web login or logout, a failed login, or a lockout;
  - a reset request, a reset, or a change of the account secret;
  - a role grant or revocation;
  - a TOTP challenge that passed.
- **D2. Activity rows.**
  - No row carries a request id, an IP address or a user agent.
  - `company_id` is stored twice: in the column and as a JSON copy in `properties` (`AppServiceProvider.php:191-195`, `AuditService.php:19-25,53-58`).
  - `AuditService::log` leaves `event` empty.
- **D3. Exports.** No export is audited.
- **D4. Retention.** Nothing prunes `activity_log`. Spatie `activitylog:clean` is not scheduled, and `failed_jobs` grows forever.
- **D5. Audit page.**
  - With no date the audit page reads the whole table.
  - The dashboard widget filters on JSON (`DashboardAdminStatsQuery.php:66`).
  - No index serves `(company_id, event, created_at)`.
- **D6. PDF size.** A PDF export can exhaust memory (F9-D01): DomPDF renders up to `maxRows` rows of any width.
- **D7. Technical logs.**
  - A single file grows without limit, and Docker logs have no cap.
  - The same exception is reported unthrottled and without tenant context.
  - The request id lives only in `Log::shareContext`, so queued jobs lose it.
  - Users and API clients never see the request id, and outbound HTTP calls do not carry it.
  - Slow queries are invisible.
  - Three jobs lack `failed()`.
  - Six catches swallow operational failures. `SendPushNotification` token deletion is already fixed by TI-PUSH-API and is out of scope here.
- **D8. Personal data and secrets.**
  - Customer, contact, address and employee personal data and the company bank account are stored in clear text in activity diffs.
  - `LogRedactor` misses otp, pin, code, bearer, cookie, session, server_key and signature keys.
  - `LogRedactor` runs only inside `ExceptionReporter`.

Non-goals (spec): hash-chained or WORM audit, SIEM shipping, Telescope, Pulse or Sentry, the holding audit page (K20), and archive tables.

## 2. Affected modules

| Path | Change | Dec. | Slice |
|---|---|---|---|
| NEW `database/migrations/2026_10_27_100000_add_request_context_to_activity_log_table.php` | 3 nullable columns + 1 index | 2, 5 | LA-AUDIT-CORE |
| `app/Providers/AppServiceProvider.php` (`Activity::saving` :170-204, `boot()`) | request context stamp, drop the JSON company copy, slow-query listener, outbound `X-Request-Id` | 2, 5, 7 | LA-AUDIT-CORE |
| `app/Http/Middleware/AssignRequestId.php:17-18` | `Log::shareContext` → `Context::add('request_id', …)` | 7 | LA-AUDIT-CORE |
| `app/Exceptions/RenderApiError.php:97-104` | `request_id` in every API error body | 7 | LA-AUDIT-CORE |
| `app/Services/Core/AuditService.php:17-71` | `->event($action)`; JSON company copy removed | 2, 5 | LA-AUDIT-CORE |
| `app/Queries/Admin/DashboardAdminStatsQuery.php:66` | filter on the `company_id` column | 5 | LA-AUDIT-CORE |
| `app/Support/AuditProperties.php` | NEW `maskValues()` | 8 | LA-AUDIT-CORE |
| `app/Models/Core/{Customer,CustomerContact,CustomerAddress,EmployeeProfile,Company}.php` | `tapActivity()` masks personal data and bank columns | 8 | LA-AUDIT-CORE |
| `config/permission.php:126` | `events_enabled` → `true` | 1 | LA-SECURITY |
| NEW `app/Listeners/RecordSecurityEvents.php` | login, logout, lockout, reset and role rows | 1 | LA-SECURITY |
| NEW `app/Support/SecurityTrail.php` | failed-login counter per account+IP per 15 min | 1 | LA-SECURITY |
| `app/Http/Requests/Auth/LoginRequest.php:54-55` (also used by `Api/V1/LoginRequest`) | call `SecurityTrail::failedLogin` | 1 | LA-SECURITY |
| `app/Http/Requests/Platform/Auth/PlatformLoginRequest.php:60-64` | call `SecurityTrail::failedLogin` | 1 | LA-SECURITY |
| `app/Models/User.php:46-57` | `password.changed` row in the existing `updated` hook | 1 | LA-SECURITY |
| `app/Services/Core/TwoFactorService.php:139-145` | `two_factor.challenge_passed` on TOTP success | 1 | LA-SECURITY |
| `app/Http/Controllers/Admin/UserController.php:122-123` | role delta instead of `syncRoles` | 1 | LA-SECURITY |
| `app/Support/ExportQuery.php:321-386` | export audit hook; PDF cell cap | 3, 6 | LA-REPORTS |
| `resources/views/exports/table.blade.php:17-19` | cap message | 6 | LA-REPORTS |
| `Modules/Reporting/app/Http/Requests/ReportFilterRequest.php` | 7-day default, 92-day maximum (audit page + export) | 5 | LA-REPORTS |
| `resources/js/Pages/Admin/Reports/AuditLog.tsx` | range error, hint, default start without effects | 5 | LA-REPORTS |
| `bootstrap/app.php:50,119-180` | `prepend` request id; `dontReportDuplicates`, `throttle`, `context`; `requestId` prop on the Error page | 7 | LA-OPS |
| `resources/js/Pages/Error.tsx:70,92` | show "ID permintaan" | 7 | LA-OPS |
| `config/logging.php` channels | `tap` → `RedactLogContext` | 8 | LA-OPS |
| NEW `app/Support/Observability/RedactLogContext.php` | Monolog processor running `LogRedactor` | 8 | LA-OPS |
| `app/Support/Observability/LogRedactor.php:8-58` | more needles, exact-key list | 8 | LA-OPS |
| `compose.yaml:3,80,103` | json-file 10m × 5 for every service | 7 | LA-OPS |
| `.env.docker.example:17-19` | `LOG_STACK`, `LOG_DAILY_DAYS`, `LOG_STDERR_FORMATTER` | 7 | LA-OPS |
| `routes/console.php` (end) | prune schedule, `queue:prune-failed` | 4, 7 | LA-OPS |
| NEW `app/Console/Commands/PruneActivityLogCommand.php` | chunked prune | 4 | LA-OPS |
| `config/activitylog.php:16` | `retention_days_by_log` | 4 | LA-OPS |
| `app/Services/Core/OpenStreetMapGeocoder.php` (catch, evidence :49) | `Log::warning('geocode.failed')` | 7 | LA-OPS |
| `Modules/Ticketing/app/Jobs/CheckTicketSlaBreachesJob.php`, `Modules/Service/app/Jobs/SendAnnouncementNotifications.php` | `failed()` | 7 | LA-OPS |
| `Modules/NetworkAsset/app/Services/Radius/RadiusDirectory.php:352-367` | one warning at the source catch, which covers the 4 RADIUS catches | 7 | LA-TI-FOLLOWUP |
| `app/Jobs/SendPushNotification.php` | `failed()` | 7 | LA-TI-FOLLOWUP |
| `config/scramble.php:70-72`, `docs/**`, `CHANGELOG.md`, `resources/js/lib/statuses/audit.ts:3-6` | docs, OpenAPI regeneration, stale comment | all | LA-DOCS |

Patterns to mimic:
- Migration: `2026_09_07_000001_add_company_id_to_activity_log_table.php`.
- Listener: `app/Listeners/EndImpersonationOnPlatformLogout.php`. It is auto-discovered, like `ReportFailedQueueJob`.
- Command: `app/Console/Commands/OpsSchedulerHeartbeatCommand.php`.
- Jobs: `failed()` as in `Modules/Billing/app/Jobs/CancelMidtransTransactionJob.php:49-52`.
- FormRequest: `after()` as in `app/Http/Requests/Admin/StoreCustomerAddressRequest.php:42-54`.
- Company timezone: as in `Modules/Reporting/app/Queries/Concerns/AppliesDateRange.php:64-69`.
- Range error: as in `resources/js/Pages/Admin/Reports/Asset.tsx:103`.

## 3. Data model and migration

NEW `database/migrations/2026_10_27_100000_add_request_context_to_activity_log_table.php`. The latest migration today is `2026_10_26_100100`.

```php
return new class() extends Migration
{
    public function up(): void
    {
        // No hasTable/hasColumn guard (unlike 2026_09_07_000001): the table always exists
        // (2026_06_30_134625), and introspection selects return [] while pretending
        // (Connection.php:412), so a guard would turn `migrate --pretend` into an empty run.
        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name', 'activity_log'), function (Blueprint $table): void {
                $table->string('request_id', 128)->nullable()->instant(); // AssignRequestId accepts client ids up to 128
                $table->string('ip_address', 45)->nullable()->instant();
                $table->string('user_agent', 255)->nullable()->instant();
                $table->index(['company_id', 'event', 'created_at']);
            });
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name', 'activity_log'), function (Blueprint $table): void {
                $table->dropIndex(['company_id', 'event', 'created_at']);
                $table->dropColumn(['request_id', 'ip_address', 'user_agent']);
            });
    }
};
```

Expected `php artisan migrate --pretend --path=database/migrations/2026_10_27_100000_add_request_context_to_activity_log_table.php`. Each column is its own statement (`MySqlGrammar::compileAdd`, vendor `MySqlGrammar.php:311-319`):

```sql
alter table `activity_log` add `request_id` varchar(128) null, algorithm=instant
alter table `activity_log` add `ip_address` varchar(45) null, algorithm=instant
alter table `activity_log` add `user_agent` varchar(255) null, algorithm=instant
alter table `activity_log` add index `activity_log_company_id_event_created_at_index`(`company_id`, `event`, `created_at`)
```

`down()` compiles to:

```sql
alter table `activity_log` drop index `activity_log_company_id_event_created_at_index`
alter table `activity_log` drop `request_id`, drop `ip_address`, drop `user_agent`
```

- The column adds are INSTANT on MySQL 8.4.
- The index build is online (INPLACE), not INSTANT.
- The `company_id` foreign key keeps its own index `(company_id, created_at)` from 2026_09_07, so `down()` may drop the new one.
- There is no backfill. Old rows keep `NULL` in the new columns and their JSON `company_id` copy until they are pruned.
- The key is about 1 035 bytes, under the 3 072-byte InnoDB limit.
- Existing indexes: `log_name`, `(company_id, created_at)`, `(company_id, log_name)`, `(company_id, causer_type, causer_id)`.
- Index trigger (documented, not built now): add another index only when one audit window passes about 100 k rows.
- Partitioning and archiving are deferred until about 2 M rows (spec 4).

## 4. API and internal contracts

### 4.1 HTTP

| Method, URI (route) | Auth / permission | Change | Errors |
|---|---|---|---|
| GET `admin/reports/audit-log` (`admin.reports.audit-log`) | `auth`, `can:report.audit.view` (unchanged) | blank `date_from` → `(date_to ?: company today) − 6 days`; span `date_from → (date_to ?: company today)` ≤ 92 days inclusive; `filters.date_from` echoes the default | span > 92 → redirect back with `errors.date_from = "Periode log audit paling lama 92 hari. Persempit periode."`; JSON clients get 422 |
| GET `admin/reports/audit-log/export` (`admin.reports.audit-log.export`) | + `can:report.export` (unchanged) | same default and limit; the file writes an `export.csv`/`export.pdf` row (C4) | same as the page |
| every export through `ExportQuery` (26 call sites, including `ReportExporter::download` :226-234) | unchanged | audit row C4; PDF rows capped at `min(maxRows, intdiv(1500, columns))`; `X-Export-Row-Cap` reports that cap | — |
| every `api/*` error (`RenderApiError`) | unchanged | body `{message, code, errors?, request_id}`; `request_id` equals the `X-Request-Id` response header | — |
| browser error page (`Error` Inertia page, 403/404/419/429/500/503) | unchanged | prop `requestId: string \| null` | — |
| outbound `Http::` calls | — | header `X-Request-Id: <Context request_id>` when one is set (requests and the jobs they dispatch) | — |
| Artisan `inbils:activity-log:prune` | console | NEW, deletes past retention in 5 000-row DELETEs | exit 0; one `activity_log.pruned` info line per retention group |

### 4.2 Internal contracts

- **C1. Request id.**
  - `AssignRequestId` sets `Context::add('request_id', $id)` (string, ≤ 128 printable ASCII) for every HTTP request.
  - It keeps `$request->attributes['request_id']` and the `X-Request-Id` response header.
  - It is prepended to the global stack, so a maintenance 503 also has an id.
  - Laravel hydrates the Context into queued jobs.
  - `ContextLogProcessor` writes it to every log record as `extra.request_id`. Before, it was in `context.request_id`.
- **C2. Activity columns.**
  - `request_id`, `ip_address`, `user_agent` (§3), filled only on create in `Activity::saving`:
    - `request_id ??= Context::get('request_id')`, everywhere, jobs included;
    - only when `request()->attributes->has('request_id')`, i.e. in a real HTTP request, never in a worker or a console command:
      - `ip_address ??= request()->ip()`;
      - `user_agent ??= mb_substr(userAgent, 0, 255)`, only for log names `security`, `platform`, `support`;
      - when `request()->attributes->get('impersonation') instanceof ActiveImpersonation`, `properties.impersonated_user_id = $impersonation->user->public_id`.
  - It is a public id (string), because `AuditProperties::forDisplay` hides integer `*_id` keys.
  - The `company_id` column is always resolved as today.
  - `properties.company_id` is always removed. Before, it was copied.
- **C3. Security vocabulary.** Every row goes through `AuditService::log`, so `event` = description.

  | description | log | subject | properties | source |
  |---|---|---|---|---|
  | `login` | security | user | `{user_id, remember}` | `Login`, guard `web` only |
  | `logout` | security | user | `{user_id}` | `Logout`, guard `web` only |
  | `login.failed` | security (web, api) · platform (platform plane, OD-1) | the account found by `getLastAttempted()`, else null | `{email_hash, channel: web\|api\|platform, failures, window_minutes: 15}` | `SecurityTrail::failedLogin` |
  | `lockout` | security · platform for `platform.*` routes | `$request->user()` or the `platform` user, else null | `{route, channel, email_hash?}` | `Lockout`, once per (route, IP, email hash) per 15 min |
  | `password.reset_requested` | security | user | `{user_id}` | `PasswordResetLinkSent`; the broker fires it only for a known account (`PasswordBroker.php:109`), so no enumeration |
  | `password.reset` | security | user | `{user_id}` | `PasswordReset` (`NewPasswordController.php:54`) |
  | `password.changed` | security | user | `{user_id}` | `User::updated` when `wasChanged('password')`: profile, API `MePasswordController`, admin `UserController::update`, reset |
  | `two_factor.challenge_passed` | security · platform for admins (existing `audit()` helper) | account | `{user_id\|platform_admin_id, method: 'totp'}` | `TwoFactorService::verifyChallenge`. A recovery code already writes `recovery_code_used` |
  | `role.attached` / `role.detached` | security | model | `{user_id, roles: [names]}`; empty lists write nothing | spatie `RoleAttached` / `RoleDetached` |

  Successes already covered elsewhere stay as they are:
  - API login success: `security api.token_issued` with `via=login` (`ApiTokenService.php:229-236`). The `Login` event fired there uses guard `sanctum` and is skipped.
  - API logout: `security api.tokens_revoked` with `reason=logout` (`:121-126`).
  - Platform: `platform auth.login` (`OpensPlatformSession.php:29`) and `platform auth.logout` (`Platform/Auth/AuthenticatedSessionController.php:50`).
  - Impersonation logs in through a NullDispatcher, so it fires no `Login`.

  Both `email_hash` values are `hash_hmac('sha256', mb_strtolower(trim($email)), config('app.key'))`. The typed secret never reaches a row or a log.
- **C4. Export row.**
  - Log `export`, description `export.csv` or `export.pdf`.
  - Properties `{report: route name|null, filters: query except 'format', rows: int, truncated: bool}`.
  - Subject null; causer = current user.
  - The row is written inside the stream closure after the last row, so a download aborted mid-stream writes none (OD-3).
- **C5. Log lines** (message, context). Ids only; never bindings, exception messages, queries or hosts.
  - `db.slow_query {ms, sql, connection}`
  - `activity_log.pruned {log, days, deleted}`
  - `geocode.failed {error: class}`
  - `radius.unavailable {error: class}`
  - `tickets.sla_check_failed {company_id}`
  - `announcement.notify_failed {announcement_id}`
  - `push.send_failed {…ids per TI-PUSH-API's constructor}`
- **C6. Retention config.**
  - `activitylog.delete_records_older_than_days` = 365 (existing :16) applies to every log name not in the map, NULL included.
  - NEW `activitylog.retention_days_by_log` = `['security' => 730, 'platform' => 730, 'support' => 730]`.
  - Spatie `activitylog:clean` is never scheduled.

## 5. UI contract

### `resources/js/Pages/Admin/Reports/AuditLog.tsx`

- Read `errors` next to `auth` from `usePage<PageProps>().props` (:166).
- Read `company` with `useCompany()` (`resources/js/hooks/useCompany.ts`).
- Add one helper, with no effect and no new state:

  ```tsx
  // The audit page always has a period: no start means the 7 days up to the end, as the server does.
  const startOrDefault = (from: string, to: string) =>
      from || presetRange('last7', to || todayInZone(company?.timezone)).from;
  ```

  `presetRange` and `todayInZone` come from `@/lib/dateRange` (:54, :78), the helpers `DateRangePicker.tsx:68` uses.
- `handleFilterSubmit` (:346-362): `const from = startOrDefault(localDateFrom, localDateTo); setLocalDateFrom(from);`, then send `date_from: from`.
- `handleResetFilters` (:365-383):
  - `const from = startOrDefault('', ''); setLocalDateFrom(from); setLocalDateTo('');`
  - `router.get(route('admin.reports.audit-log'), { date_from: from }, …)` with the same options.
  - This mirrors the server default, so the picker never shows "empty" while the data covers 7 days.
- `DateRangePicker` (:720-728):
  - add `error={errors.date_from ?? errors.date_to}`;
  - below it, `<p className="mt-1 text-xs text-muted-foreground">Paling lama 92 hari. Tanpa tanggal mulai, 7 hari terakhir.</p>`.
  - Keep the label `Periode` unchanged; the E2E locators use it.
- States:
  - first load shows the server-defaulted range;
  - a refused span shows the field error, keeps the previous rows and keeps the export link on the previous valid `params`;
  - existing loading and empty states are unchanged.

### `resources/js/Pages/Error.tsx`

- The signature becomes `ErrorPage({ status, requestId = null }: { status: number; requestId?: string | null })`.
- After the `Kode galat {status}` line (:92), add:

  ```tsx
  {requestId ? (
      <p className="text-xs text-muted-foreground">
          ID permintaan: <span className="font-mono select-all">{requestId}</span>
      </p>
  ) : null}
  ```

  This uses the same token as the line above, so it adds no new contrast pair.

### `resources/views/exports/table.blade.php`

- Keep `Baris: N (dibatasi sampai M)` (:17-19).
- When `$capped`, add `<div class="meta">PDF dibatasi {{ $maxRows }} baris. Untuk data lengkap, unduh CSV atau persempit periode.</div>`.
- The 9-column audit PDF therefore caps at 166 rows.

## 6. Test plan

Before every run: `uptime`, and wait while the 1-minute load is above 6.

- PHPUnit runs as `PU <paths>`, which is `flock /tmp/inbils-e2e.lock env DOCKER_CONTEXT=default ./vendor/bin/sail exec -T -u sail laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact <paths>`.
- Pint runs on owned files only: `env DOCKER_CONTEXT=default ./vendor/bin/sail exec -T -u sail laravel.test vendor/bin/pint <owned php files>`.
- Never migrate the dev DB. Use `--pretend` only. RefreshDatabase migrates `inbils_test`.
- Never reset global event listeners. Capture logs with `Log::listen(...)`, not `Event::fake()`.

Red first: each slice writes its new tests, watches them fail for the stated reason, then implements.

### LA-AUDIT-CORE

- NEW `tests/Feature/Operations/ActivityRequestContextTest.php`:
  - `test_a_row_written_in_a_request_carries_its_request_id_and_ip_and_no_json_company_copy`. Red: columns missing.
  - `test_user_agent_is_kept_only_for_security_platform_and_support_rows`.
  - `test_a_row_written_outside_a_request_takes_the_context_request_id_and_no_ip`. Uses `Context::add` plus `AuditService::log` in console context.
  - `test_audit_service_rows_store_the_action_as_event`.
  - `test_rows_written_while_impersonating_name_the_impersonated_user_by_public_id`.
  - `test_the_migration_adds_nullable_columns_and_the_company_event_created_at_index`. Uses `Schema::getColumns` and `Schema::getIndexes`.
- NEW `tests/Feature/Operations/RequestCorrelationTest.php`:
  - `test_outbound_http_carries_the_context_request_id`. `Http::fake`, then `assertSent` with header.
  - `test_outbound_http_without_a_request_id_adds_no_header`.
  - `test_a_query_over_500_ms_logs_db_slow_query_without_bindings`. Dispatch a `QueryExecuted` with `time: 600`, capture with `Log::listen`.
  - `test_api_errors_carry_the_request_id_of_the_response_header`.
- NEW `tests/Feature/Support/AuditPiiMaskingTest.php`:
  - `test_customer_and_contact_changes_are_logged_without_their_values`;
  - `test_address_and_employee_changes_hide_personal_values`;
  - `test_company_settings_diff_hides_bank_account_info`;
  - `test_a_change_to_a_masked_column_alone_still_writes_a_row`.
- Updated tests:
  - `RequestIdMiddlewareTest.php:54-71`: the `Log::shareContext` mock becomes `test_request_id_is_put_in_the_context`, using a probe route that returns `Context::get('request_id')`.
  - `UniformNotFoundJsonTest.php:65`: exact JSON gains `request_id`.
  - `tests/Feature/Api/V1/Platform/ErrorEnvelopeTest.php:161-163`: keys gain `request_id`.
  - `Api/V1/Auth/LoginTest.php`:
    - :109 asserts the column, not JSON;
    - :128-129 compares the bodies with `Arr::except(…, ['request_id'])`, because the anti-enumeration check must still hold.
  - JSON copy removed. Assert `$row->company_id` and that `properties` lacks `company_id`:
    - `AuditLogTenantIsolationTest.php:95,111`
    - `tests/Unit/Platform/PlatformAdminTest.php:115`
    - `Api/V1/Technician/WorkOrderTripTest.php:102`
    - `Api/V1/Technician/WorkOrdersTest.php:98`
    - `Platform/TenantExportTest.php:225,229,769,868`
    - `Platform/SupportActionsTest.php:273,343`
    - `WhatsApp/WhatsAppConsentTest.php:190`
    - `WhatsApp/WhatsAppWebhookTest.php:411`
    - `Admin/CompanyIntegrationsTest.php:653`
  - `Admin/DashboardPersonaTest.php:337-353`: rows need the column, not only JSON.
- Commands:
  - `PU tests/Feature/Operations tests/Feature/Support tests/Feature/Reporting tests/Feature/Platform tests/Unit/Platform tests/Feature/Api tests/Feature/WhatsApp tests/Feature/Admin tests/Feature/PublicId tests/Feature/Billing`
  - `env DOCKER_CONTEXT=default ./vendor/bin/sail exec -T -u sail laravel.test php artisan migrate --pretend --path=database/migrations/2026_10_27_100000_add_request_context_to_activity_log_table.php`. The output must equal §3.

### LA-SECURITY

- NEW `tests/Feature/Auth/SecurityTrailTest.php`:
  - `test_a_web_login_and_logout_are_recorded`;
  - `test_failed_logins_write_one_row_per_account_and_address_per_window_and_count_the_rest`. Three failures give one row with `failures = 3`. `email_hash` is the HMAC. The typed secret appears nowhere in `properties`.
  - `test_a_failed_login_for_an_unknown_email_has_no_subject`;
  - `test_an_api_login_failure_is_recorded_with_channel_api`;
  - `test_a_platform_login_failure_goes_to_the_platform_log`;
  - `test_a_lockout_is_recorded_once_per_window`;
  - `test_a_reset_request_and_a_reset_are_recorded_and_an_unknown_email_writes_nothing`;
  - `test_a_changed_secret_is_recorded_for_the_profile_and_the_admin_form`;
  - `test_a_totp_challenge_that_passes_is_recorded`;
  - `test_role_grants_and_revocations_are_recorded_and_an_unchanged_edit_records_none`.
- Updated tests:
  - `TwoFactorEnrollmentTest.php:228-230`: filter `description like 'two_factor.%'`, because the fixture's `assignRole` now writes `role.attached`.
  - `TwoFactorServiceTest.php:283-299` and `PlatformTwoFactorTest.php:208-216` should stay green. Their flows pass no TOTP challenge and create no roles. Edit them only if red.
- Command: `PU tests/Feature/Auth tests/Feature/Platform tests/Feature/Api/V1/Auth tests/Feature/PublicId tests/Feature/Admin`.

### LA-REPORTS

- NEW `tests/Feature/Reporting/AuditLogDateRangeTest.php`:
  - `test_the_page_defaults_to_the_last_seven_company_days`. Uses `travelTo` near midnight in the company timezone.
  - `test_date_to_alone_anchors_the_default_seven_days`;
  - `test_a_span_over_92_days_is_refused_on_the_page_and_the_export`;
  - `test_92_days_inclusive_is_accepted`;
  - `test_other_reports_keep_open_ranges`.
- NEW `tests/Feature/Support/ExportQueryAuditTest.php`:
  - `test_a_csv_export_writes_one_export_row_with_report_filters_and_row_count`;
  - `test_a_truncated_export_records_truncated_true`;
  - `test_a_pdf_export_is_capped_by_cells_and_says_so`. Nine columns cap at 166; the rendered view contains "Untuk data lengkap".
- Updated: `ReportExportContentTest.php:186-199`, only if red.
- E2E `tests/e2e/date-range.spec.ts:50-61`:
  - "a half range is sent as it is" becomes "an open end stays open; a missing start becomes the 7 days before the end";
  - it uses relative dates `companyToday(page, -10)` and `companyToday(page, -3)`, then expects from = `companyToday(page, -9)`.
  - The fixed 2026-09 from-only date would pass 92 days on 2026-12-06.
- Commands:
  - `PU tests/Feature/Reporting tests/Feature/Support tests/Feature/ReportFilterValidationTest.php tests/Feature/AdminMenuAuthorizationTest.php`
  - `npm run lint && npm run typecheck`
  - `flock /tmp/inbils-e2e.lock sh -c 'flock /tmp/inbils-build.lock npm run build && node scripts/e2e-setup.mjs && npm run test:e2e -- tests/e2e/date-range.spec.ts tests/e2e/reports-audit-log.spec.ts tests/e2e/reporting-export.spec.ts --project=chromium --workers=1'`

### LA-OPS

- NEW `tests/Feature/Operations/PruneActivityLogCommandTest.php`:
  - `test_business_rows_past_365_days_go_and_security_platform_support_keep_730`;
  - `test_rows_without_a_log_name_follow_the_default_retention`;
  - `test_it_deletes_in_chunks_until_nothing_is_left` (5 001 rows);
  - `test_it_logs_one_pruned_line_per_retention_group`.
- `SchedulerLockTest.php`:
  - add `'inbils:activity-log:prune' => 120` to the TTL map (:44-55);
  - NEW `test_the_activity_log_prune_runs_at_02_30_jakarta`;
  - NEW `test_failed_jobs_are_pruned_after_30_days`.
- NEW `tests/Feature/Operations/ExceptionHandlingTest.php`:
  - `test_the_same_exception_instance_is_reported_once`;
  - `test_one_failure_site_is_reported_at_most_five_times_a_minute`;
  - `test_reports_carry_company_route_platform_admin_and_impersonation_context`;
  - `test_every_handler_channel_applies_the_redaction_tap`.
- `ErrorPagesTest.php`: NEW `test_the_error_page_carries_the_request_id`.
- `OperationsArtifactsTest.php`:
  - NEW `test_every_compose_service_caps_its_json_logs`;
  - NEW `test_the_deploy_env_logs_json_to_stderr_and_daily_files`.
- `LogRedactorTest.php`:
  - NEW `test_masks_otp_pin_code_bearer_cookie_session_server_key_signature_and_radius_secret`;
  - NEW `test_exact_short_keys_do_not_hide_error_code_postcode_or_shipping`.
- NEW `tests/Unit/Support/Observability/RedactLogContextTest.php`: `test_the_tap_redacts_context_and_extra`. Uses a Monolog `TestHandler` wrapped in `Illuminate\Log\Logger`.
- NEW `tests/Feature/Operations/SwallowedFailureLoggingTest.php`:
  - `test_a_geocoder_failure_logs_a_warning_without_the_query`;
  - `test_the_sla_and_announcement_jobs_log_their_final_failure`.
- Commands:
  - `PU tests/Feature/Operations tests/Unit/Support/Observability tests/Feature/ErrorPagesTest.php tests/Feature/Ticketing tests/Feature/Service tests/Feature/Billing tests/Feature/WhatsApp`. The last two hold the `Exceptions::fake()` users, re-checked under the throttle.
  - `npm run lint && npm run typecheck`
  - `docker compose -f compose.yaml config --quiet`

### LA-TI-FOLLOWUP

- `tests/Feature/NetworkAsset/RadiusDirectoryTest.php`: NEW `test_an_unreachable_radius_logs_one_warning_without_host_or_secret`.
- NEW `tests/Feature/Push/PushJobFailedTest.php`: `test_a_final_push_failure_logs_a_warning_and_keeps_the_device_tokens`.
- Command: `PU tests/Feature/NetworkAsset tests/Feature/Push tests/Feature/Api/V1/Customer/SubscriptionConnectionTest.php`.

### LA-DOCS

- Regenerate the OpenAPI export with the test-schema command in `docs/operations/DEPLOYMENT.md:183-191`. Do not paste its credentials.
- Then `PU tests/Feature/Api/V1/OpenApiContractTest.php tests/Feature/Operations/OperationsArtifactsTest.php`.
- Then `flock /tmp/inbils-build.lock env DOCKER_CONTEXT=default ./vendor/bin/sail exec -T -u sail laravel.test php artisan inbils:docs:pdf --only=web`.

## 7. Risks and open decisions

| # | Decision | Default (taken) | Alternative |
|---|---|---|---|
| OD-1 | Platform-plane `login.failed` / `lockout` log name | `platform`: next to the existing `platform auth.login`, visible on the platform audit page; tenant pages never show platform rows | literal `security` with a null company, visible nowhere |
| OD-2 | `bank_account_info` and personal data | mask the value (`[disembunyikan]`), keep the key: one helper, and the row still says what changed | strip the key |
| OD-3 | When the export row is written | after streaming, with the real row count | before streaming: no count, and an extra COUNT query |
| OD-4 | Audit page default on the client | `startOrDefault` in the two handlers (no effect) | `preserveState: false`, which also resets sort and selection |
| OD-5 | Outbound `X-Request-Id` | every vendor (Midtrans, Meta, FCM, Nominatim); the value is a UUID or a vetted client id | allow-list of hosts |
| OD-6 | `two_factor.challenge_passed` | written also for the TI integrations step-up (same call) | only in the login controllers (3 places) |
| OD-7 | `AssignRequestId` position | `prepend`, so a maintenance 503 has an id | keep `append` |
| OD-8 | Lockout dedupe | one row per (route, IP, email hash) per 15 min | one row per blocked request (flood risk) |

Risks:
- A client-supplied `X-Request-Id` is trusted (evidence P3, out of scope).
- `QueryException` messages still embed bindings. Redaction is key-based and the message is not redacted.
- The geocoder still caches `[]` for 1 h after a failure.
- An aborted download leaves no export row.
- Rows written before this lane keep their personal data and the JSON `company_id` until pruned (365/730 days).
- With `events_enabled`, `PermissionAttached`/`PermissionDetached` also fire. Nothing listens to them.
- Seeders and fixtures now write `role.attached` rows. Tests that list exact `security` rows must filter.
- `AuditService` rows now show their action in the `event` badge. EnumBadge falls back to the raw value, so explicit rows show the action twice; labelling them is out of scope. The comment in `resources/js/lib/statuses/audit.ts:3-6` becomes stale and is fixed in LA-DOCS.
- The exception throttle (5 a minute per site) can hide repeats from `Exceptions::fake()` assertions within one test.
- Tests that assert exact outbound header arrays under `Http::fake` gain `X-Request-Id` when a Context id is set.
- Tests that mock `Log` strictly could see an unexpected `warning` if a query passes 500 ms.
- Log consumers must read `extra.request_id`, no longer `context.request_id`. This is documented in OBSERVABILITY.md.
- Unknown-email web failures have a null company. They exist only in the database, for forensic queries.

## 8. Work breakdown

Run order:
- **Wave A**, at most 4 in parallel: LA-SECURITY, LA-REPORTS and LA-OPS start now. LA-AUDIT-CORE starts when its gate opens.
- **Wave B**: LA-TI-FOLLOWUP, when its gates open.
- **Wave C**: LA-DOCS, then the security-reviewer pass.

Cross-lane files, with the first owner landing first:

1. `bootstrap/app.php`: integrity W1-MECH (accepted), then LA-OPS. TI never edits it (TI design §8.3).
2. `app/Providers/AppServiceProvider.php`: TI-CORE, then LA-AUDIT-CORE after TI-CORE is accepted (the `api-push-test` limiter at :308 shows TI-CORE landed).
3. `routes/console.php`: integrity W1-OPS (accepted, `withoutOverlapping` TTLs), then LA-OPS.
4. `app/Support/ExportQuery.php`: LA-REPORTS, then the future K32/F9-D03 PDF-template lane.

Also sequenced:
- `app/Models/Core/Company.php`: integrity (accepted), then LA-AUDIT-CORE.
- `SendAnnouncementNotifications.php` and `SchedulerLockTest.php`: integrity W1-OPS (accepted), then LA-OPS.
- TI-CORE tests `TenantExportTest.php` and `CompanyIntegrationsTest.php`, and TI-WHATSAPP's `WhatsAppWebhookTest.php`: then LA-AUDIT-CORE.
- `RadiusDirectory.php` and `RadiusDirectoryTest.php`: TI-RADIUS, then LA-TI-FOLLOWUP.
- `SendPushNotification.php` and `config/scramble.php`: TI-PUSH-API, then LA-TI-FOLLOWUP and LA-DOCS.
- Shared docs and `resources/js/lib/statuses/audit.ts`: TI-DOCS, IH W3-DOCS and TI-WEB, then LA-DOCS.

### LA-AUDIT-CORE · backend

- **Goal.** D2, the D5 widget, D8 personal data, and the D7 request-id plumbing.
- **Depends on.** TI-CORE and TI-WHATSAPP accepted.
- **Consumes.** Nothing new.
- **Produces.** C1, C2; `event` on every `AuditService` row; `AuditProperties::maskValues`.
- **Owned files.**
  - NEW `database/migrations/2026_10_27_100000_add_request_context_to_activity_log_table.php`
  - `app/Providers/AppServiceProvider.php`
  - `app/Http/Middleware/AssignRequestId.php`
  - `app/Exceptions/RenderApiError.php`
  - `app/Services/Core/AuditService.php`
  - `app/Queries/Admin/DashboardAdminStatsQuery.php`
  - `app/Support/AuditProperties.php`
  - `app/Models/Core/Customer.php`
  - `app/Models/Core/CustomerContact.php`
  - `app/Models/Core/CustomerAddress.php`
  - `app/Models/Core/EmployeeProfile.php`
  - `app/Models/Core/Company.php`
  - `tests/Feature/Operations/RequestIdMiddlewareTest.php`
  - `tests/Feature/PublicId/UniformNotFoundJsonTest.php`
  - `tests/Feature/Api/V1/Platform/ErrorEnvelopeTest.php`
  - `tests/Feature/Api/V1/Auth/LoginTest.php`
  - `tests/Feature/Reporting/AuditLogTenantIsolationTest.php`
  - `tests/Unit/Platform/PlatformAdminTest.php`
  - `tests/Feature/Api/V1/Technician/WorkOrderTripTest.php`
  - `tests/Feature/Api/V1/Technician/WorkOrdersTest.php`
  - `tests/Feature/Platform/TenantExportTest.php`
  - `tests/Feature/Platform/SupportActionsTest.php`
  - `tests/Feature/WhatsApp/WhatsAppConsentTest.php`
  - `tests/Feature/WhatsApp/WhatsAppWebhookTest.php`
  - `tests/Feature/Admin/CompanyIntegrationsTest.php`
  - `tests/Feature/Admin/DashboardPersonaTest.php`
  - NEW `tests/Feature/Operations/ActivityRequestContextTest.php`
  - NEW `tests/Feature/Operations/RequestCorrelationTest.php`
  - NEW `tests/Feature/Support/AuditPiiMaskingTest.php`
- **Target.** The files above. Non-goals: no backfill, no audit page change, no Scramble change (LA-DOCS).
- **Change.**
  1. Migration as in §3.
  2. `AssignRequestId.php:18`: `Context::add('request_id', $id)` replaces `Log::shareContext`, and the `Log` import goes.
  3. `Activity::saving` (`AppServiceProvider.php:170-204`):
     - keep the company resolution;
     - always `setAttribute('company_id', $resolved)` and drop `properties.company_id`;
     - then, only when `! $activity->exists`, stamp C2.
  4. `boot()`:
     - `DB::listen` with `$query->time > 500` → `Log::warning('db.slow_query', ['ms' => $query->time, 'sql' => $query->sql, 'connection' => $query->connectionName])`. Comment: bindings can hold personal data.
     - `Http::globalRequestMiddleware(fn (RequestInterface $r) => is_string($id = Context::get('request_id')) ? $r->withHeader('X-Request-Id', $id) : $r)`.
  5. `RenderApiError::json()` (:97-104): append `+ (is_string($id = Context::get('request_id')) ? ['request_id' => $id] : [])`.
  6. `AuditService`:
     - delete the `properties['company_id']` set/unset in `log()` (:19-25) and `logModelActivity()` (:53-58);
     - keep the column `tap`;
     - add `->event($action)` in `log()`.
  7. `DashboardAdminStatsQuery.php:66`: `->where('company_id', $companyId)`.
  8. `AuditProperties::maskValues(ActivityContract $activity, array $keys): void`:
     - for `attributes` and `old`, when `Arr::has($section, $key)` (dot keys reach into array columns), `Arr::set($section, $key, '[disembunyikan]')`;
     - add the const `MASKED`.
  9. Each model gets `public function tapActivity(ActivityContract $activity, string $eventName): void { AuditProperties::maskValues($activity, [...]); }`:
     - Customer: `email`, `phone`, `tax_id`, `contact_person`, `notes`;
     - CustomerContact: `phone`, `email`, `notes`;
     - CustomerAddress: the address line, coordinate and `notes` columns, names taken from its `$fillable`;
     - EmployeeProfile: `phone`, `notes`;
     - Company: `settings.bank_account_info`.
  10. Update the tests listed in §6.
- **Acceptance.** Every LA-AUDIT-CORE test is green under the §6 command. The pretend output equals §3. Pint is clean on the owned PHP files.

### LA-SECURITY · backend

- **Goal.** D1.
- **Depends on.** Nothing.
- **Consumes.** `AuditService::log` as it is today. Rows gain `event` once LA-AUDIT-CORE lands; the tests do not assert `event`.
- **Produces.** C3.
- **Owned files.**
  - `config/permission.php`
  - NEW `app/Listeners/RecordSecurityEvents.php`
  - NEW `app/Support/SecurityTrail.php`
  - `app/Http/Requests/Auth/LoginRequest.php`
  - `app/Http/Requests/Platform/Auth/PlatformLoginRequest.php`
  - `app/Models/User.php`
  - `app/Services/Core/TwoFactorService.php`
  - `app/Http/Controllers/Admin/UserController.php`
  - `tests/Feature/Auth/TwoFactorEnrollmentTest.php`
  - `tests/Feature/Auth/TwoFactorServiceTest.php` (only if red)
  - `tests/Feature/Platform/Auth/PlatformTwoFactorTest.php` (only if red)
  - NEW `tests/Feature/Auth/SecurityTrailTest.php`
- **Target.** The files above. Non-goals:
  - no new API login row (`api.token_issued` covers it);
  - the 7 `Lockout` dispatchers stay untouched (`LoginRequest:86`, `PlatformLoginRequest:90`, `Auth/ConfirmablePasswordController:37`, `Platform/Auth/ConfirmablePasswordController:43`, `Api/V1/ChangePasswordRequest:50`, `Api/V1/TwoFactorRequest:67`, `Auth/TwoFactorChallengeRequest:88`).
- **Change.**
  1. `config/permission.php:126`: `'events_enabled' => true`.
  2. `SecurityTrail`:
     - `WINDOW_SECONDS = 900`;
     - `emailHash(string): string` (C3);
     - `failedLogin(string $email, string $channel, ?Model $account): void`:
       - key `security:login-failed:{hash}|{ip}|{intdiv(time(), 900)}`;
       - `Cache::add($key, 0, 900)`, then `$failures = Cache::increment($key)`;
       - the first failure writes the row via `AuditService::log(channel === 'platform' ? PlatformAuditService::PLATFORM_LOG : 'security', 'login.failed', [...], $account)` and stores its id under `$key.':row'`;
       - later failures run `Activity::whereKey($id)->update(['properties->failures' => $failures])`.
  3. `LoginRequest.php:55`, before `RateLimiter::hit`: `SecurityTrail::failedLogin((string) $this->input('email'), $this->is('api/*') ? 'api' : 'web', $guard->getLastAttempted());`.
  4. `PlatformLoginRequest.php:61`: the same call with `'platform'`.
  5. `RecordSecurityEvents` gets `handleLogin`, `handleLogout`, `handleLockout`, `handlePasswordReset`, `handleResetLinkSent`, `handleRoleAttached` and `handleRoleDetached`, per C3. Role ids resolve through `app(PermissionRegistrar::class)->getRoleClass()::whereKey($ids)->orderBy('name')->pluck('name')`. Lockout dedupe: `Cache::add('security:lockout:'.sha1(route.'|'.ip.'|'.hash), true, 900)`.
  6. `User::booted` `static::updated` (:46-57): when `wasChanged('password')`, `AuditService::log('security', 'password.changed', ['user_id' => $user->id], $user)`. Keep the token revoke.
  7. `TwoFactorService::verifyChallenge` (:139-145): `else { $this->audit('challenge_passed', $account, ['method' => $method]); }`.
  8. `UserController::update` (:122-123): `$current = $user->getRoleNames()->all();` before the update, then `$user->removeRole(...array_diff($current, $roles)); $user->assignRole(array_diff($roles, $current));`. Comment: `syncRoles` detaches and re-attaches every role, which the trail would record as changes. `store` keeps `syncRoles` (:75).
- **Acceptance.** The §6 command is green. Grep the new rows for the typed secret and find nothing (asserted in the tests). Pint is clean.

### LA-REPORTS · backend + frontend

- **Goal.** D3, the D5 page limit, D6.
- **Depends on.** Nothing.
- **Consumes.** `AuditService::log`.
- **Produces.** C4; the §4.1 range contract; the §5 AuditLog and PDF view contracts.
- **Owned files.**
  - `app/Support/ExportQuery.php`
  - `resources/views/exports/table.blade.php`
  - `Modules/Reporting/app/Http/Requests/ReportFilterRequest.php`
  - `resources/js/Pages/Admin/Reports/AuditLog.tsx`
  - `tests/e2e/date-range.spec.ts`
  - `tests/Feature/Reporting/ReportExportContentTest.php` (only if red)
  - NEW `tests/Feature/Reporting/AuditLogDateRangeTest.php`
  - NEW `tests/Feature/Support/ExportQueryAuditTest.php`
- **Target.** The files above. Non-goals: other reports' ranges; `ReportExporter`, which is covered through ExportQuery.
- **Change.**
  1. `ExportQuery`:
     - private `auditExport(string $format, int $rows, bool $truncated)` uses `$this->request ?? request()` for `report` (route name) and `filters` (`Arr::except($request->query(), ['format'])`);
     - CSV (:330-358): track `$truncated` at the cap break, then call it after `fclose`;
     - PDF (:360-386): `private const PDF_MAX_CELLS = 1500;` and `$max = min($this->maxRowsLimit(), intdiv(self::PDF_MAX_CELLS, max(1, count($headers))))`, which feeds `downloadHeaders` and the view; call `auditExport('pdf', count($rows), $capped)` after the output. Comment: DomPDF holds the whole table (F9-D01).
  2. Blade: the §5 message.
  3. `ReportFilterRequest`:
     - constants `AUDIT_DEFAULT_DAYS = 7`, `AUDIT_MAX_DAYS = 92`;
     - `prepareForValidation()`, only when `routeIs('admin.reports.audit-log', 'admin.reports.audit-log.export')` and `date_from` is blank: anchor = `date_to` when `Carbon::canBeCreatedFromFormat($to, 'Y-m-d')`, else `now($tz)` with `$tz = CompanyService::current()?->timezone ?: config('app.timezone')`; merge `date_from = anchor − 6 days`;
     - `after()` for the same routes, skipped when the date fields already have errors: `(int) $from->diffInDays($to, true) + 1 > 92` → `date_from` error, using the §4.1 message.
  4. `AuditLog.tsx` and the E2E spec per §5 and §6.
- **Acceptance.** The §6 commands are green, E2E included.

### LA-OPS · backend + devops

- **Goal.** D4, the rest of D7, D8 redaction.
- **Depends on.** Integrity accepted (done).
- **Consumes.** `$request->attributes['request_id']`, which exists today.
- **Produces.** C5 (except radius and push); C6; the Error `requestId` prop.
- **Owned files.**
  - `bootstrap/app.php`
  - `resources/js/Pages/Error.tsx`
  - `config/logging.php`
  - NEW `app/Support/Observability/RedactLogContext.php`
  - `app/Support/Observability/LogRedactor.php`
  - `tests/Unit/Support/Observability/LogRedactorTest.php`
  - NEW `tests/Unit/Support/Observability/RedactLogContextTest.php`
  - `compose.yaml`
  - `.env.docker.example`
  - `routes/console.php`
  - NEW `app/Console/Commands/PruneActivityLogCommand.php`
  - `config/activitylog.php`
  - `tests/Feature/Operations/SchedulerLockTest.php`
  - `app/Services/Core/OpenStreetMapGeocoder.php`
  - `Modules/Ticketing/app/Jobs/CheckTicketSlaBreachesJob.php`
  - `Modules/Service/app/Jobs/SendAnnouncementNotifications.php`
  - `tests/Feature/ErrorPagesTest.php`
  - `tests/Feature/Operations/OperationsArtifactsTest.php`
  - NEW `tests/Feature/Operations/PruneActivityLogCommandTest.php`
  - NEW `tests/Feature/Operations/ExceptionHandlingTest.php`
  - NEW `tests/Feature/Operations/SwallowedFailureLoggingTest.php`
- **Target.** The files above. Non-goals: no scheduler `onFailure` alert; no geocoder cache change.
- **Change.**
  1. `bootstrap/app.php`:
     - :50 `append` → `prepend`;
     - at the top of `withExceptions` (:119), add:
       - `$exceptions->dontReportDuplicates();`
       - `$exceptions->throttle(fn (Throwable $e) => Limit::perMinute(5)->by($e instanceof QueryException ? 'sql:'.$e->getSql() : $e::class.'@'.$e->getFile().':'.$e->getLine()));`
       - `$exceptions->context(fn (): array => rescue(fn (): array => array_filter(['company_id' => CompanyService::currentId(), 'route' => request()->route()?->getName(), 'platform_admin_id' => Auth::guard('platform')->id(), 'impersonation_id' => request()->attributes->get('impersonation')?->id]), [], report: false));`. Comment: the handler does not guard context callbacks (Handler.php:535-547).
     - :171: `Inertia::render('Error', ['status' => $status, 'requestId' => $request->attributes->get('request_id')])`.
  2. `Error.tsx` per §5.
  3. `RedactLogContext::__invoke(Illuminate\Log\Logger $logger)`: `$logger->pushProcessor(fn (LogRecord $r) => $r->with(context: LogRedactor::redact($r->context), extra: LogRedactor::redact($r->extra)))`. Add `'tap' => [RedactLogContext::class]` to `single`, `daily`, `slack`, `papertrail`, `stderr`, `syslog` and `errorlog`. The stack merges its children's processors (`LogManager.php:282-288`).
  4. `LogRedactor`:
     - substring needles plus `bearer`, `cookie`, `session`, `server_key`, `signature` (`secret` already covers `radius_secret`);
     - `SENSITIVE_EXACT = ['otp', 'pin', 'code', 'recovery_code', 'recovery_codes']`, matched only as the whole key. Comment: as substrings they would hide `error_code`, `postcode`, `shipping`.
  5. `compose.yaml`: `x-logging: &logging {driver: json-file, options: {max-size: "10m", max-file: "5"}}` above `x-app`, then `logging: *logging` in `x-app` (app, queue, platform-queue, scheduler) and in `mysql` and `redis`.
  6. `.env.docker.example:17-19`: `LOG_CHANNEL=stack`, `LOG_STACK=stderr,daily`, `LOG_DAILY_DAYS=14`, `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`, `LOG_LEVEL=info`. `TRUSTED_PROXIES` is already documented at :8-11.
  7. `config/activitylog.php`: add `retention_days_by_log` (C6) with a comment.
  8. `PruneActivityLogCommand` (`inbils:activity-log:prune`, `CHUNK = 5000`):
     - default group: `where(fn ($q) => $q->whereNull('log_name')->orWhereNotIn('log_name', array_keys($map)))`;
     - then each mapped log;
     - each group loops `Activity::query()->…->where('created_at', '<', now()->subDays($days))->orderBy('id')->limit(5000)->delete()` while 5 000 rows come back;
     - then `Log::info('activity_log.pruned', …)`.
  9. `routes/console.php`, appended:
     - `Schedule::command('inbils:activity-log:prune')->dailyAt('19:30')->withoutOverlapping(120);` with the comment "19:30 UTC is 02:30 in Jakarta";
     - `Schedule::command('queue:prune-failed', ['--hours' => 720])->daily();`.
  10. Geocoder catch: `catch (Throwable $e) { Log::warning('geocode.failed', ['error' => $e::class]); return []; }`. Comment: the message holds the searched address.
  11. `CheckTicketSlaBreachesJob::failed(Throwable $e)` → `Log::warning('tickets.sla_check_failed', ['company_id' => $this->companyId])`. `SendAnnouncementNotifications::failed` → `Log::warning('announcement.notify_failed', ['announcement_id' => $this->announcementId])`.
- **Acceptance.** The §6 commands are green and `docker compose config` is quiet.

### LA-TI-FOLLOWUP · backend

- **Goal.** The last 4 swallowed RADIUS catches and the push `failed()`.
- **Depends on.** TI-RADIUS and TI-PUSH-API accepted.
- **Produces.** C5 `radius.unavailable` and `push.send_failed`.
- **Owned files.**
  - `Modules/NetworkAsset/app/Services/Radius/RadiusDirectory.php`
  - `app/Jobs/SendPushNotification.php`
  - `tests/Feature/NetworkAsset/RadiusDirectoryTest.php`
  - NEW `tests/Feature/Push/PushJobFailedTest.php`
- **Change.**
  - `RadiusDirectory::run()`, now :364-366: `catch (Throwable $e) { Log::warning('radius.unavailable', ['error' => $e::class]); throw RadiusException::unavailable(); }`. One line covers `NasDeviceController:159`, `NetworkAccountController:135`, `SubscriptionConnectionController:46`, `SpeedProfileRadiusObserver:27` and the panel/sync callers. Class only, because the message names the host.
  - `SendPushNotification::failed(Throwable $e)`: `Log::warning('push.send_failed', [ids only])`. Token deletion stays as TI-PUSH-API left it.
- **Acceptance.** The §6 command is green.

### LA-DOCS · docs

- **Goal.** Document every decision; regenerate the generated files.
- **Depends on.** All LA slices, TI-DOCS, IH W3-DOCS and TI-WEB accepted.
- **Owned files.**
  - `config/scramble.php`: the error line becomes `{"message", "code", "errors"?, "request_id"}`.
  - `docs/api/mobile-v1.openapi.json` (regenerated)
  - `docs/operations/OBSERVABILITY.md`: log stack, `extra.request_id`, slow query, throttle and context, redaction, retention, index trigger.
  - `docs/operations/DEPLOYMENT.md`: LOG_* values, compose caps.
  - `docs/operations/DOCKER.md`
  - `docs/operations/RELIABLE_JOBS.md` (:18-21): `failed()` warnings.
  - `docs/operations/JOBS_NOTIFICATIONS.md` (:20-25): `queue:prune-failed`.
  - `docs/user-guide/web.md`: audit page 7/92 days, PDF cap, security rows, "ID permintaan".
  - `docs/user-guide/pdf/web.pdf` (regenerated)
  - `CHANGELOG.md`
  - `resources/js/lib/statuses/audit.ts`: the comment at :3-6 only.
- **Acceptance.** The §6 LA-DOCS commands are green.

### Security review (security-reviewer, no files)

After LA-DOCS, review:
- the C3 rows: no typed secret, HMAC email hash, no enumeration;
- the C2 impersonation and user-agent scope;
- D8 masking and redaction;
- the request id surfaces and the outbound header;
- export filters stored in the audit;
- prune scope and retention;
- error page and API body leakage.

No lockfile changes, so `composer audit` and `npm audit` run only as the routine check.

### File-ownership matrix

| File | Slice |
|---|---|
| NEW `database/migrations/2026_10_27_100000_add_request_context_to_activity_log_table.php` | LA-AUDIT-CORE |
| `app/Providers/AppServiceProvider.php` | LA-AUDIT-CORE |
| `app/Http/Middleware/AssignRequestId.php` | LA-AUDIT-CORE |
| `app/Exceptions/RenderApiError.php` | LA-AUDIT-CORE |
| `app/Services/Core/AuditService.php` | LA-AUDIT-CORE |
| `app/Queries/Admin/DashboardAdminStatsQuery.php` | LA-AUDIT-CORE |
| `app/Support/AuditProperties.php` | LA-AUDIT-CORE |
| `app/Models/Core/Customer.php` | LA-AUDIT-CORE |
| `app/Models/Core/CustomerContact.php` | LA-AUDIT-CORE |
| `app/Models/Core/CustomerAddress.php` | LA-AUDIT-CORE |
| `app/Models/Core/EmployeeProfile.php` | LA-AUDIT-CORE |
| `app/Models/Core/Company.php` | LA-AUDIT-CORE |
| `tests/Feature/Operations/RequestIdMiddlewareTest.php` | LA-AUDIT-CORE |
| `tests/Feature/PublicId/UniformNotFoundJsonTest.php` | LA-AUDIT-CORE |
| `tests/Feature/Api/V1/Platform/ErrorEnvelopeTest.php` | LA-AUDIT-CORE |
| `tests/Feature/Api/V1/Auth/LoginTest.php` | LA-AUDIT-CORE |
| `tests/Feature/Reporting/AuditLogTenantIsolationTest.php` | LA-AUDIT-CORE |
| `tests/Unit/Platform/PlatformAdminTest.php` | LA-AUDIT-CORE |
| `tests/Feature/Api/V1/Technician/WorkOrderTripTest.php` | LA-AUDIT-CORE |
| `tests/Feature/Api/V1/Technician/WorkOrdersTest.php` | LA-AUDIT-CORE |
| `tests/Feature/Platform/TenantExportTest.php` | LA-AUDIT-CORE |
| `tests/Feature/Platform/SupportActionsTest.php` | LA-AUDIT-CORE |
| `tests/Feature/WhatsApp/WhatsAppConsentTest.php` | LA-AUDIT-CORE |
| `tests/Feature/WhatsApp/WhatsAppWebhookTest.php` | LA-AUDIT-CORE |
| `tests/Feature/Admin/CompanyIntegrationsTest.php` | LA-AUDIT-CORE |
| `tests/Feature/Admin/DashboardPersonaTest.php` | LA-AUDIT-CORE |
| NEW `tests/Feature/Operations/ActivityRequestContextTest.php` | LA-AUDIT-CORE |
| NEW `tests/Feature/Operations/RequestCorrelationTest.php` | LA-AUDIT-CORE |
| NEW `tests/Feature/Support/AuditPiiMaskingTest.php` | LA-AUDIT-CORE |
| `config/permission.php` | LA-SECURITY |
| NEW `app/Listeners/RecordSecurityEvents.php` | LA-SECURITY |
| NEW `app/Support/SecurityTrail.php` | LA-SECURITY |
| `app/Http/Requests/Auth/LoginRequest.php` | LA-SECURITY |
| `app/Http/Requests/Platform/Auth/PlatformLoginRequest.php` | LA-SECURITY |
| `app/Models/User.php` | LA-SECURITY |
| `app/Services/Core/TwoFactorService.php` | LA-SECURITY |
| `app/Http/Controllers/Admin/UserController.php` | LA-SECURITY |
| `tests/Feature/Auth/TwoFactorEnrollmentTest.php` | LA-SECURITY |
| `tests/Feature/Auth/TwoFactorServiceTest.php` | LA-SECURITY |
| `tests/Feature/Platform/Auth/PlatformTwoFactorTest.php` | LA-SECURITY |
| NEW `tests/Feature/Auth/SecurityTrailTest.php` | LA-SECURITY |
| `app/Support/ExportQuery.php` | LA-REPORTS |
| `resources/views/exports/table.blade.php` | LA-REPORTS |
| `Modules/Reporting/app/Http/Requests/ReportFilterRequest.php` | LA-REPORTS |
| `resources/js/Pages/Admin/Reports/AuditLog.tsx` | LA-REPORTS |
| `tests/e2e/date-range.spec.ts` | LA-REPORTS |
| `tests/Feature/Reporting/ReportExportContentTest.php` | LA-REPORTS |
| NEW `tests/Feature/Reporting/AuditLogDateRangeTest.php` | LA-REPORTS |
| NEW `tests/Feature/Support/ExportQueryAuditTest.php` | LA-REPORTS |
| `bootstrap/app.php` | LA-OPS |
| `resources/js/Pages/Error.tsx` | LA-OPS |
| `config/logging.php` | LA-OPS |
| NEW `app/Support/Observability/RedactLogContext.php` | LA-OPS |
| `app/Support/Observability/LogRedactor.php` | LA-OPS |
| `tests/Unit/Support/Observability/LogRedactorTest.php` | LA-OPS |
| NEW `tests/Unit/Support/Observability/RedactLogContextTest.php` | LA-OPS |
| `compose.yaml` | LA-OPS |
| `.env.docker.example` | LA-OPS |
| `routes/console.php` | LA-OPS |
| NEW `app/Console/Commands/PruneActivityLogCommand.php` | LA-OPS |
| `config/activitylog.php` | LA-OPS |
| `tests/Feature/Operations/SchedulerLockTest.php` | LA-OPS |
| `app/Services/Core/OpenStreetMapGeocoder.php` | LA-OPS |
| `Modules/Ticketing/app/Jobs/CheckTicketSlaBreachesJob.php` | LA-OPS |
| `Modules/Service/app/Jobs/SendAnnouncementNotifications.php` | LA-OPS |
| `tests/Feature/ErrorPagesTest.php` | LA-OPS |
| `tests/Feature/Operations/OperationsArtifactsTest.php` | LA-OPS |
| NEW `tests/Feature/Operations/PruneActivityLogCommandTest.php` | LA-OPS |
| NEW `tests/Feature/Operations/ExceptionHandlingTest.php` | LA-OPS |
| NEW `tests/Feature/Operations/SwallowedFailureLoggingTest.php` | LA-OPS |
| `Modules/NetworkAsset/app/Services/Radius/RadiusDirectory.php` | LA-TI-FOLLOWUP |
| `app/Jobs/SendPushNotification.php` | LA-TI-FOLLOWUP |
| `tests/Feature/NetworkAsset/RadiusDirectoryTest.php` | LA-TI-FOLLOWUP |
| NEW `tests/Feature/Push/PushJobFailedTest.php` | LA-TI-FOLLOWUP |
| `config/scramble.php` | LA-DOCS |
| `docs/api/mobile-v1.openapi.json` | LA-DOCS |
| `docs/operations/OBSERVABILITY.md` | LA-DOCS |
| `docs/operations/DEPLOYMENT.md` | LA-DOCS |
| `docs/operations/DOCKER.md` | LA-DOCS |
| `docs/operations/RELIABLE_JOBS.md` | LA-DOCS |
| `docs/operations/JOBS_NOTIFICATIONS.md` | LA-DOCS |
| `docs/user-guide/web.md` | LA-DOCS |
| `docs/user-guide/pdf/web.pdf` | LA-DOCS |
| `CHANGELOG.md` | LA-DOCS |
| `resources/js/lib/statuses/audit.ts` | LA-DOCS |

Every path appears once. A slice that turns a non-owned test red reports `needs-change: <path>` to Main and does not edit it. Known candidates: `tests/Feature/Platform/ImpersonationTest.php` and the `Exceptions::fake()` tests.
