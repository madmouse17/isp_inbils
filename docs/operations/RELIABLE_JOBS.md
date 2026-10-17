# Reliable jobs & notifications (P1-B)

Native Laravel mail/database/log only. No SMS/WhatsApp/external provider.

## Schedules (`routes/console.php`)

| Command | Cadence | Overlap lock |
|---|---|---|
| `ops:scheduler-heartbeat` | every 5 minutes | none |
| `billing:generate` | daily @ 02:00 | withoutOverlapping() |
| `billing:check-overdue` | daily @ 03:00 | withoutOverlapping() |
| `tickets:sla` | every 15 minutes | withoutOverlapping() |
| `announcements:publish-scheduled` | every minute | withoutOverlapping() |
| `promo-banners:cleanup-images` | daily | withoutOverlapping() |
| `inbils:platform:exports:prune` | hourly | withoutOverlapping() |

Locks prevent concurrent scheduler runs of the same command.

## Billing failure notifications

- Commands: `Modules/Billing/app/Console/GenerateRecurringInvoices.php`, `CheckOverdueInvoices.php`
- On throwable: report via P1-A `ExceptionReporter` (redacted log) + notify active `admin` role users with `BillingScheduleFailedNotification` (mail + database).
- Payload: command name, optional company/job ids only — no secrets/PII.

## Ticket assignment

- `TicketService::assign` notifies new assignee only when `assigned_to` actually changes.
- Notification: `TicketAssignedNotification` (mail + database), identifiers only.

## Ticket SLA breach

- Command `tickets:sla` dispatches `CheckTicketSlaBreachesJob` (tries=3, backoff 30/120/300).
- Job optional `companyId` scopes tenant; null = all companies (scheduled path).
- Idempotency: `tickets.sla_breached_notified_at` set under `lockForUpdate` after notify; re-run skips already-notified / resolved / closed.
- Migration: `Modules/Ticketing/database/migrations/2026_07_25_180000_add_sla_breached_notified_at_to_tickets_table.php` (hasColumn-guarded).

## Announcements and promo banners

- Publishing a due outage or maintenance dispatches `SendAnnouncementNotifications` after commit. Scheduled starts use `announcements:publish-scheduled`.
- The job claims `announcements.notified_at` under a row lock, then sends one database notification per matching customer user in chunks of 500. Promos and information announcements never notify.
- `promo-banners:cleanup-images` removes private images after a banner has been ended for more than 180 days. The database row remains.

## Tenant exports

- `BuildTenantExportJob` runs on the `platform` queue with one try and a 3600-second timeout.
- `inbils:platform:exports:prune` deletes archives after their 24-hour window and fails queued/running builds older than two hours.

## Queue terminal failure

- Listener: `App\Listeners\ReportFailedQueueJob` on `JobFailed`.
- Registered in `AppServiceProvider`.
- Reports via `ExceptionReporter` (LogRedactor scrubs password/token/etc from serialised job props) + `queue.job_failed` warning with safe ids only.

## Invoice / payment / stock idempotency

- Recurring invoice: `BillingService::generateForPeriod` locks subscription row, skips existing invoice for same `subscription_id` + `billing_period_start`. Unique remains `(company_id, number)`.
- Payments: unique `(company_id, active_reference)` prevents duplicate active references.
- No finance/stock history rewrite from jobs.

## Explicit non-goals

- No SMS/WhatsApp/pager provider.
- No unique `(subscription_id, billing_period_start)` migration unless duplicate preflight proves need beyond app lock.
- No secrets in notifications or logs.
