# Observability baseline (P1-A)

Provider-neutral local baseline for inbils ISP ops. No Sentry/Bugsnag/OTel dependency is wired yet.

## Request id

One id follows a request from the browser or the app to the log line, the queued job, the vendor call and the audit row.

- Middleware: `App\Http\Middleware\AssignRequestId`, prepended to the global stack in `bootstrap/app.php`, so even the answer of the host check or of maintenance mode (503) carries an id.
- It accepts an incoming `X-Request-Id` or `X-Correlation-Id` of 1 to 128 characters from `[A-Za-z0-9._:-]` and otherwise generates a UUID. A client-supplied id is trusted as sent: a caller can pick the id its own requests carry, so never use it for authorization.
- It puts the id in `Context::add('request_id', …)` and in `$request->attributes`, and echoes `X-Request-Id` on every response.
- The Context reaches:
  - every log record, as `extra.request_id` (it used to be `context.request_id`: a log query or a dashboard that reads the old key must read the new one);
  - the queued jobs the request dispatches (Laravel hydrates the Context into the job), so a job's log lines and audit rows carry the id of the request that started it;
  - every outbound call made with the `Http::` facade (Midtrans, the WhatsApp Cloud API and Nominatim), as the `X-Request-Id` request header, when an id is set. Firebase push goes through Google's SDK and carries no header;
  - every `activity_log` row (column `request_id`);
  - every `api/*` error body (`request_id`, equal to the response header) and the browser error page (the line "ID permintaan").
- A user or an app that reports a problem quotes the id. `docker compose --env-file .env.docker logs app | grep <id>` (or `grep <id> storage/logs/laravel-*.log`) finds every line, and `select * from activity_log where request_id = '<id>'` finds the audit rows.

## Log files and Docker logs

- `config/logging.php` keeps Laravel's channels. The deploy template (`.env.docker.example`) sets `LOG_CHANNEL=stack`, `LOG_STACK=stderr,daily`, `LOG_DAILY_DAYS=14` and `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`: one JSON object per line on stderr (what `docker compose logs` shows) and one file per day in `storage/logs/laravel-YYYY-MM-DD.log`, 14 files kept.
- Docker side: every service in `compose.yaml` uses the `json-file` driver with `max-size: 10m` and `max-file: 5`, so a container keeps at most 50 MB of output. `OperationsArtifactsTest` fails when a service lacks the cap.
- The real `.env.docker` is not the template. An operator who already runs a deployment must set these four `LOG_*` values there; until then the host keeps writing the one `storage/logs/laravel.log` (`LOG_STACK=single`), which grows without a limit and which no job prunes. After the switch the old `laravel.log` stays on the volume and nobody rotates it: archive or delete it by hand.
- Level: `LOG_LEVEL=info` in the template, so `debug` lines stay out of production.

## Structured logging and redaction

- `App\Support\Observability\LogRedactor` masks secret and personal keys, recursively, and replaces the whole value with `[REDACTED]`. A value under a secret key goes whole: a list under `recovery_codes` would leak through subkeys that look harmless.
- Matched as a substring of the key (case-insensitive): password, token, secret, authorization, cookie, api_key, access_token, refresh_token, credit_card, nik, npwp, phone, email, bearer, session, server_key, signature.
- Matched only as the whole key, because as substrings they would hide `error_code`, `postcode` and `shipping`: `otp`, `pin`, `code`, `recovery_code`, `recovery_codes`.
- It runs on every record, not only in `ExceptionReporter`: each channel that writes somewhere carries the `tap` `App\Support\Observability\RedactLogContext` (`config/logging.php`), which masks the `context` and the `extra` of the record before a handler sees it. A new channel needs the same tap; `ExceptionHandlingTest` fails without it.
- What it does not cover: the log message and an exception's own message. A `QueryException` message embeds the SQL bindings, so a failed query can still carry a value in the `message`. Log calls in the code use ids and class names, never values (the line list below).

## Exception reporting

- `App\Support\Observability\ExceptionReporter` logs redacted context via the native Laravel log and is hooked from `bootstrap/app.php` `withExceptions()->reportable()`.
- Duplicates: `dontReportDuplicates()` logs the same exception instance once, even when two layers catch and report it.
- Throttle: one failure site is reported at most 5 times a minute. The site is the exception class at a file and line; for a `QueryException` it is the SQL statement, because the line is always the framework's. A flood of one failure (a dependency that went down) cannot bury the log. Inside one test, `Exceptions::fake()` assertions can miss the sixth repeat of the same site.
- Context on every report: `company_id`, `route`, `platform_admin_id`, `impersonation_id`, each only when it exists. The lookup runs under `rescue()`: the database that just failed may be the one the company lookup needs, and a broken context must not lose the report.
- `notifyExternal()` is an empty adapter point for a future central reporter.
- **Owner blocker:** choose Sentry/Bugsnag/OTel (or other) + credentials before enabling external fan-out.
- Public HTTP responses never leak exception messages/traces when `APP_DEBUG=false` (safe 500 JSON/text).

## Log lines worth alerting on

Context holds ids and class names only: never a binding, an exception message, a query value or a host.

| Message | Level | Context | Meaning |
|---|---|---|---|
| `db.slow_query` | warning | `ms`, `sql`, `connection` | A query took more than 500 ms. The SQL has placeholders, no bindings. |
| `activity_log.pruned` | info | `log`, `days`, `deleted` | One line per retention group of the daily prune. `deleted` of 0 for days in a row on a busy install means the prune stopped. |
| `geocode.failed` | warning | `error` (class) | The address lookup (Nominatim) failed; the empty result is cached for an hour. |
| `radius.unavailable` | warning | `error` (class) | A company's RADIUS database did not answer; the network pages show the state as unknown. |
| `tickets.sla_check_failed` | warning | `company_id` | `CheckTicketSlaBreachesJob` ran out of tries. |
| `announcement.notify_failed` | warning | `announcement_id` | `SendAnnouncementNotifications` ran out of tries. |
| `push.send_failed` | warning | ids of the push attempt | A push to FCM failed (`SendPushNotification` or `FcmSender`). |
| `integration.host_refused` | warning | `company_id`, `provider` | A mail or RADIUS host was refused at runtime (see [SECURITY.md](../SECURITY.md) section 7). |
| `queue.job_failed` | warning | safe ids | A job failed for good (`ReportFailedQueueJob`). |

## Audit trail (`activity_log`)

The audit page (Laporan, Log Audit) and the platform audit page read one table. Besides the model changes it already held, a row now says where it came from.

| Column | Content |
|---|---|
| `request_id` | The request id above, also for rows written in a queued job. Null for a console command that has none. |
| `ip_address` | The client address, only for a row written inside a web or API request, never from a worker or the console. Behind a reverse proxy set `TRUSTED_PROXIES` (see [DEPLOYMENT.md](./DEPLOYMENT.md)), or every row carries the proxy's address. |
| `user_agent` | The browser or app string (255 characters), only for the log names `security`, `platform` and `support`. |
| `event` | The model event (`created`, `updated`, `deleted`, `restored`), or the action for a row written through `AuditService::log` (`login`, `export.csv`, …). |
| `properties.impersonated_user_id` | Set while a platform operator acts as a user: the public id of that user. The row's causer stays the user the operator entered. |

The company lives in the `company_id` column only; the JSON copy `properties.company_id` is gone. Rows written before this change keep their JSON copy and have null in the three new columns until the prune removes them. The audit pages do not show the new columns yet: read them with SQL (forensics) or from the tenant export, which leaves `ip_address` and `user_agent` out.

Rows written for these models hide personal values: Customer (`email`, `phone`, `tax_id`, `contact_person`, `notes`), customer contact, customer address, employee profile (`phone`, `notes`) and the company bank account (`settings.bank_account_info`). The row says which field changed and shows `[disembunyikan]` in place of the old and the new value. A row written before this change still holds the clear value until it is pruned.

### Security rows

Log name `security`; the platform panel writes its own under `platform`.

| `description` | When |
|---|---|
| `login`, `logout` | Web sign-in and sign-out. The app login keeps `api.token_issued` and `api.tokens_revoked`. |
| `login.failed` | A wrong credential. One row per account (HMAC of the typed address), client address and 15 minutes; `properties.failures` counts the attempts. An unknown address leaves a row with no subject, readable only in the database. |
| `lockout` | An account throttle (5 failures a minute per typed address and client address) refused the client. One row per route, address and typed address per 15 minutes. The per-address budget (20 failures a minute whatever the typed address) refuses without a row. |
| `password.reset_requested`, `password.reset`, `password.changed` | Reset link sent (only for an existing account), reset completed, any change of the secret (profile, app, admin, reset). |
| `two_factor.challenge_passed` | A TOTP code passed (login and the integrations step-up). A recovery code writes `recovery_code_used`. |
| `role.attached`, `role.detached` | A role added to or removed from a user; `properties.roles` lists the names. An empty change writes nothing. |

No row holds the typed secret or the typed address. `email_hash` is `HMAC-SHA256(lowercased trimmed address, APP_KEY)`: the same address gives the same hash inside one installation, so a guessing run can be followed, and nobody can read the address back.

Exports write `export.csv` or `export.pdf` under the log name `export`, after the last row is sent: `report` (route name), `filters`, `rows` and `truncated`. A download the client aborts writes no row.

### Retention

- `php artisan inbils:activity-log:prune`, scheduled daily at 19:30 UTC (02:30 Jakarta), deletes in blocks of 5 000 rows so one statement never holds its locks for minutes.
- 365 days for every log name, rows without a name included. 730 days for `security`, `platform` and `support`. Both values are in `config/activitylog.php` (`delete_records_older_than_days`, `retention_days_by_log`).
- Spatie's own `activitylog:clean` is never scheduled.
- `queue:prune-failed --hours=720` runs daily: `failed_jobs` keeps 30 days.
- The table has the index `(company_id, event, created_at)` besides `(company_id, created_at)`, `(company_id, log_name)` and `(company_id, causer_type, causer_id)`. The audit page needs a date range (7 days when none is given, 92 days at most), so one query reads a bounded window. Trigger for another index: one audit window passes about 100 000 rows, or the page answers slower than a second. Trigger for partitioning or archive files: about 2 million rows. Neither is built.

## Slow queries

Any query over 500 ms writes `db.slow_query` with the SQL (placeholders only), the time and the connection name. The threshold is a constant in `AppServiceProvider`; the lines are warnings, so they show at the template's `LOG_LEVEL=info`.

## Health endpoints

| Endpoint | Purpose | Status values |
|---|---|---|
| `GET /up` | Liveness (Laravel default) | framework health |
| `GET /ready` | Dependency readiness | `ready` / `degraded` / `not_ready` |

`/ready` body is public-safe JSON only:

```json
{
  "status": "ready|degraded|not_ready",
  "checks": {
    "database": "ok|fail|unknown",
    "cache": "ok|fail|unknown",
    "queue": "ok|fail|unknown",
    "failed_jobs": "ok|fail|unknown",
    "scheduler": "ok|fail|unknown",
    "backup": "ok|fail|unknown"
  }
}
```

Rules:

- Database `fail` ⇒ overall `not_ready` (HTTP 503).
- Any other `fail` ⇒ overall `degraded` (HTTP 200 with degraded status).
- Never return hosts, DSNs, exception messages, or stack traces.
- Scheduler ok only if cache key `ops:scheduler:heartbeat_at` is fresh (≤300s).
- Backup probe currently returns `unknown` until an app-owned backup marker exists (do not fake green).

Implementation: `App\Support\Observability\ReadinessChecker` + `App\Http\Controllers\Operations\ReadinessController`.

## Actionable alert matrix

| Signal | Severity | Owner | Detection | Runbook |
|---|---|---|---|---|
| HTTP 5xx rate spike | Sev-1 | App/on-call | reverse-proxy / app log count of 5xx | Check `/ready`, last deploy, exception log by `request_id`; rollback if post-deploy |
| Latency p95 high | Sev-2 | App | proxy/APM latency | Identify slow route; check DB/cache; scale or fix query |
| Queue depth high | Sev-2 | App | jobs table depth / worker metrics | Ensure queue workers up; drain backlog; check failed jobs |
| Queue failure growth | Sev-1 | App | `failed_jobs` growth | Replay/inspect failed jobs; fix consumer; pause producer if needed |
| Scheduler heartbeat stale | Sev-2 | Ops | `/ready` check `scheduler=fail` | Confirm `schedule:work`/`cron`; restore heartbeat writer |
| Backup freshness unknown/stale | Sev-2 | Ops | `/ready` backup + backup scripts | Run `scripts/operations/backup.sh`; record drill in BACKUP_RESTORE.md |
| Billing failure (invoice/payment) | Sev-1 | Billing | domain logs / failed jobs / admin mail+db notification | Inspect BillingService errors; stop retries if money risk; manual invoice reconcile |
| Queue job terminal failure | Sev-1 | App | `JobFailed` → `ReportFailedQueueJob` + failed_jobs | Inspect failed_jobs uuid; fix consumer; do not re-run money/stock side effects blindly |
| Ticket SLA breach | Sev-2 | Support | `tickets:sla` + `TicketSlaBreachedNotification` | Assign/escalate ticket; confirm SLA tier; clear backlog |
| Disk free low | Sev-1 | Ops | host monitor | Free logs/backups; expand volume |
| DB unavailable | Sev-1 | Ops/DBA | `/ready` database=fail | Failover/restore connectivity; keep app in maintenance if needed |

## Local verification

Focused Operations verification must use the isolated Docker PHPUnit sandbox recipe in [`DOCKER.md`](DOCKER.md) under "Isolated PHPUnit". After Composer dependencies are installed, operators must run/add this focused command inside that recipe's subshell, where the `SANDBOX` shell variable is defined; do not run it as a standalone host command after the subshell exits:

```bash
docker exec --workdir /test/app --env APP_KEY "$SANDBOX" php vendor/bin/phpunit --testdox tests/Feature/Operations
```

The sandbox uses the `phpunit.xml`-pinned `mysql:3306/inbils_test` service user; never use the business database or `root`.

Expected: ExceptionReporting, ReadinessEndpoint, RequestIdMiddleware, OperationsArtifacts green.

## Explicit non-goals (this baseline)

- No vendor APM/error package install without owner decision.
- No secrets in repo or `.env` examples with real keys.
- No multi-tenant metrics product UI in this card.
