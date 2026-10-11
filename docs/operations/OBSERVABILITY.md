# Observability baseline (P1-A)

Provider-neutral local baseline for inbils ISP ops. No Sentry/Bugsnag/OTel dependency is wired yet.

## Request / correlation ID

- Middleware: `App\Http\Middleware\AssignRequestId` (global append in `bootstrap/app.php`).
- Accepts safe incoming `X-Request-Id` or `X-Correlation-Id` (printable ASCII, ≤128 chars).
- Otherwise generates UUID.
- Echoes `X-Request-Id` on every response.
- Binds `request_id` into `Log::shareContext()`.

## Structured logging + redaction

- `App\Support\Observability\LogRedactor` redacts secrets/PII keys recursively:
  password, token, secret, authorization, cookie, api_key, access_token, refresh_token, credit_card, nik, npwp, phone, email.
- Values replaced with `[REDACTED]`.

## Exception reporting

- `App\Support\Observability\ExceptionReporter` logs redacted context via native Laravel log.
- Hooked from `bootstrap/app.php` `withExceptions()->reportable()`.
- Includes `request_id` when present.
- `notifyExternal()` is an empty adapter point for future central reporter.
- **Owner blocker:** choose Sentry/Bugsnag/OTel (or other) + credentials before enabling external fan-out.
- Public HTTP responses never leak exception messages/traces when `APP_DEBUG=false` (safe 500 JSON/text).

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
