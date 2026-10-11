# Jobs & Notifications (P1-B)

Reliable scheduler jobs, terminal queue failure reporting, and in-app/mail notifications for billing + ticketing.

Canonical detail: `docs/operations/RELIABLE_JOBS.md`.

## Schedule

| Command | Cadence | Overlap guard | Purpose |
|---|---|---|---|
| `ops:scheduler-heartbeat` | every 5 minutes | `none` | `/ready` probe heartbeat |
| `billing:generate` | daily @ 02:00 | `withoutOverlapping()` | Recurring postpaid invoices (idempotent) |
| `billing:check-overdue` | daily @ 03:00 | `withoutOverlapping()` | Mark past-due invoices overdue (idempotent) |
| `tickets:sla` | every 15 minutes | `withoutOverlapping()` | Notify once per SLA-breached ticket |

Defined in `routes/console.php`. Run scheduler:

```bash
php artisan schedule:work
# or cron: * * * * * php /path/to/artisan schedule:run
```

## Channels

| Event | Notification | Channels |
|---|---|---|
| Billing command failure | `BillingScheduleFailedNotification` | mail + database |
| Ticket assigned | `TicketAssignedNotification` | mail + database |
| Ticket SLA breach | `TicketSlaBreachedNotification` | mail + database |
| Queue job terminal fail | `ReportFailedQueueJob` listener | log only (ExceptionReporter + warning) |

No SMS / WhatsApp / external pager. Bodies include safe IDs only (command name, company_id, ticket_id/code, job uuid). No stack traces in notification body.

## Failure path

1. Command/job throws.
2. `ExceptionReporter` logs redacted context (`LogRedactor`).
3. Billing commands catch at handle-level → notify active `admin` role users → exit 1.
4. Queue terminal failure → `JobFailed` → `App\Listeners\ReportFailedQueueJob` (registered in `AppServiceProvider`) → report + log warning.
5. External central reporter (`ExceptionReporter::notifyExternal`) still OWNER BLOCKER (no Sentry/etc credentials).

## Idempotency

- Recurring invoice generation: locks subscription row; skips existing invoice for same subscription + period start.
- Overdue mark: pure status update on past-due rows; re-run safe.
- SLA breach: `tickets.sla_breached_notified_at` set under `lockForUpdate` after notify; re-run skips.
- Ticket assign notify: only when `assigned_to` changes.

## Jobs

| Job | Tries | Backoff | Timeout |
|---|---|---|---|
| `CheckTicketSlaBreachesJob` | 3 | 30/120/300s | 120s |

Dispatched by `tickets:sla` (optional `--company=`).

## Local workers

```bash
php artisan queue:work --tries=3
# MAIL_MAILER=log for local; array driver in tests
```

Windows: see `docs/operations/examples/nssm-queue-worker.ps1.example` and supervisor example.

## Owner blockers

- External SMS / WhatsApp provider + credentials.
- Central error reporter (Sentry/Bugsnag/OTel) credentials.
- Multi-server schedule: add `->onOneServer()` when cache lock driver is shared (Redis) across nodes.
