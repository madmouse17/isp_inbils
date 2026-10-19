# Logs and audit trail: complete for auditors, useful for troubleshooting, cheap at volume

Status: done
Ledger: `docs/plans/INBILS_OMP_BACKLOG.md` → "ISP roadmap lanes (2026-10-12)" row `log-audit`
Source: owner 2026-10-17: "cek juga apakah log kita sudah cukup jelas, detail, informatif, untuk kebutuhan audit eksternal atau internal, dan kebutuhan troubleshoot" and "log banyak gimana biar tidak membebani server atau biar pas menampilkan tidak lemot". Evidence: `local://log-audit.md` (LogAudit, 2026-10-18: coverage matrix, measured volumes, EXPLAIN at 500k rows, ranked gaps). Owner standing instruction (2026-10-18): take the recommended option, no confirmation.

## Owner decisions (recommended options, accepted)

1. Security trail rows in `activity_log` under log name `security`: web/API/platform login success, failed login (counted per account+IP per 15 min, never the typed password, attempted email hashed), lockout, logout, password reset request/complete, password change, 2FA challenge success. Role grants/revocations via spatie permission events (`events_enabled=true`) as `security role.attached/detached`.
2. Every activity row carries `request_id`, `ip_address`, `user_agent` (UA only for security/platform/support log names) in nullable columns filled in `Activity::saving`; `TRUSTED_PROXIES` documented for deploy. During impersonation trait rows also carry `properties.impersonated_user_id`. `AuditService::log` sets `event` = action.
3. Exports are audited (`export.csv`/`export.pdf` with report, filters, row count) through one hook in `ExportQuery`/`ReportExporter`.
4. Retention: scheduled chunked prune (5 000 rows per DELETE, loop) daily off-hours; business log names 365 days, `security`/`platform`/`support` 730 days (config, documented). No partitioning now (MySQL FK limits); archive-to-file deferred until ~2 M rows.
5. Audit page and widget speed: default `date_from` = today − 6 days, mandatory range, max span 92 days (page and export); dashboard "Aktivitas terbaru" filters `company_id` column (not JSON); drop the JSON copy of `company_id` going forward; indexes only when a window passes ~100 k rows (documented trigger), plus `(company_id, event, created_at)` now since `event` becomes useful.
6. PDF export cap by cells (`min($max, intdiv(1500, count($headers)))`) with an Indonesian message pointing to CSV/narrower period (fixes F9-D01 OOM).
7. Technical logs: `LOG_STACK=stderr,daily`, `LOG_DAILY_DAYS=14`, JSON formatter on stderr in deploy; compose `logging` json-file 10 MB × 5 for every service; exception `throttle` + `dontReportDuplicates` + `context()` (company id, route, platform admin, impersonation id); `Context::add('request_id')` so it propagates to jobs; request id shown on the error page and in API error bodies; outbound HTTP sends `X-Request-Id`; `queue:prune-failed --hours=720` daily; slow query log line `db.slow_query` (>500 ms, no bindings); `failed()` on the three jobs that lack it; the 6 swallowed catches log a warning; `SendPushNotification` deletes device tokens only on FCM "unregistered", never on any Throwable.
8. PII hygiene: `logExcept` customer/contact/address/employee PII columns from properties (log "phone changed" without values), strip `bank_account_info` from company settings diffs; `LogRedactor` also masks otp/pin/code/bearer/cookie/session/server_key/signature/radius secrets, and a Monolog processor applies it to every channel.

## Non-goals

Hash-chained/WORM audit, external SIEM shipping, Telescope/Pulse/Sentry, consolidated holding audit page (owner decision K20 pending), monthly archive tables.

## Tickets

01-design (architect) · slices per the design.
