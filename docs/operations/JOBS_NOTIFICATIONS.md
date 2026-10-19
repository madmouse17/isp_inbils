# Jobs & Notifications (P1-B)

Reliable scheduler jobs, terminal queue failure reporting, and in-app/mail notifications for billing + ticketing.

Canonical detail: `docs/operations/RELIABLE_JOBS.md`.

## Schedule

| Command | Cadence | Overlap guard (minutes) | Purpose |
|---|---|---|---|
| `ops:scheduler-heartbeat` | every 5 minutes | `none` | `/ready` probe heartbeat |
| `billing:whatsapp-reminders` | daily @ 01:00 UTC | `withoutOverlapping(120)` | WhatsApp payment reminders H-3, H-1, H+1 |
| `billing:generate` | daily @ 02:00 | `withoutOverlapping(120)` | Recurring postpaid invoices (idempotent) |
| `billing:check-overdue` | daily @ 03:00 | `withoutOverlapping(120)` | Mark past-due invoices overdue (idempotent) |
| `billing:suspend-overdue` | daily @ 08:00 | `withoutOverlapping(120)` | Isolate subscriptions past the grace days (companies with auto suspend on) |
| `tickets:sla` | every 15 minutes | `withoutOverlapping(10)` | Notify once per SLA-breached ticket |
| `midtrans:reconcile` | every 15 minutes | `withoutOverlapping(10)` | Ask Midtrans about attempts still pending after ten minutes |
| `network:sync` | every 15 minutes | `withoutOverlapping(10)` | Queue one `SyncCompanyNetworkJob` per company with an enabled RADIUS integration; the job re-queues RADIUS accounts and NAS client rows that are out of step |
| `announcements:publish-scheduled` | every minute | `withoutOverlapping(10)` | Dispatch due outage and maintenance notifications |
| `promo-banners:cleanup-images` | daily | `withoutOverlapping(120)` | Delete banner images 180 days after end |
| `inbils:platform:exports:prune` | hourly | `withoutOverlapping(50)` | Delete expired tenant export archives and fail stale builds |
| `model:prune` | daily | `none` | Prune the WhatsApp message log (12 months) and STOP receipts (30 days) |
| `sanctum:prune-expired` | daily | `none` | Drop mobile tokens expired more than 24 hours ago |
| `inbils:activity-log:prune` | daily @ 19:30 UTC | `withoutOverlapping(120)` | Delete audit rows past retention: 365 days, 730 for the `security`, `platform` and `support` logs, in blocks of 5 000 rows (`config/activitylog.php`) |
| `queue:prune-failed --hours=720` | daily | `none` | Delete `failed_jobs` rows older than 30 days |

Defined in `routes/console.php`. The overlap number is the lifetime of the lock in minutes, so a crashed run blocks the next one for at most that long. The `scheduler` container runs `php artisan schedule:clear-cache` before `schedule:work` (`docker/app/entrypoint.sh`) to drop locks a crash left behind. Run scheduler:

## Channels

| Event | Notification | Channels |
|---|---|---|
| Billing command failure (per failed company, to that company's admins) | `BillingScheduleFailedNotification` | mail + database |
| Ticket assigned | `TicketAssignedNotification` | mail + database |
| Ticket SLA breach | `TicketSlaBreachedNotification` | mail + database |
| Queue job terminal fail | `ReportFailedQueueJob` listener | log only (ExceptionReporter + warning) |
| Announcement published or scheduled start reached | `AnnouncementPublishedNotification` | database |

No SMS / WhatsApp / external pager. Bodies include safe IDs only (command name, company_id, ticket_id/code, job uuid). No stack traces in notification body.

## Failure path

1. Command/job throws.
2. The throwable is reported (`ExceptionReporter` logs redacted context, `LogRedactor`).
3. Billing commands catch per company, so one failing company does not stop the others. At the end each failed company's active `admin` role users get one `BillingScheduleFailedNotification`, and the command exits 1. Admins of other companies get nothing.
4. Queue terminal failure → `JobFailed` → `App\Listeners\ReportFailedQueueJob` (registered in `AppServiceProvider`) → report + log warning. `CheckTicketSlaBreachesJob`, `SendAnnouncementNotifications` and `SendPushNotification` also write their own warning from `failed()` (`tickets.sla_check_failed`, `announcement.notify_failed`, `push.send_failed`) with ids only; see [RELIABLE_JOBS.md](./RELIABLE_JOBS.md). The row stays in `failed_jobs` for 30 days: the daily `queue:prune-failed --hours=720` deletes older rows, so retry or investigate inside that window.
5. External central reporter (`ExceptionReporter::notifyExternal`) still OWNER BLOCKER (no Sentry/etc credentials).

## Idempotency

- Recurring invoice generation: locks subscription row; skips a subscription that already has a recurring invoice for the period start in any status (a cancelled one counts). The unique `invoices_active_recurring_period_unique` is the backstop when two runs race.
- Overdue mark: pure status update on past-due rows; re-run safe.
- SLA breach: `tickets.sla_breached_notified_at` set under `lockForUpdate` after notify; re-run skips.
- Ticket assign notify: only when `assigned_to` changes.
- Announcement fan-out: `announcements.notified_at` is claimed under `lockForUpdate`; reruns skip the announcement.
- Admin web writes: `PreventDuplicateWebSubmit` refuses an identical write by the same user and company to an `admin.*` route within 10 seconds (409 "Permintaan yang sama baru saja dikirim."). `SECURITY_DUPLICATE_SUBMIT_ENABLED` switches it (default on, off in `testing`).
- Stale forms: edit forms send `expected_updated_at`; `OptimisticLock` compares it under the row lock and answers 409 "Data diubah pengguna lain; muat ulang." (`ConflictBanner` shows it with a reload button). A write from a tab that still shows another company is refused with 409 "Perusahaan aktif berubah di tab lain." through the `X-Inbils-Company` header. Details: `docs/operations/RELIABLE_JOBS.md`.

## Jobs

| Job | Tries | Backoff | Timeout | Unique |
|---|---|---|---|---|
| `CheckTicketSlaBreachesJob` | 3 | 60/300s | 60s | 300s |
| `SendAnnouncementNotifications` | 3 | 30/120/300s | 120s | 600s per announcement |
| `BuildTenantExportJob` (`platform` queue) | 1 | none | 3600s | none |

Dispatched by `tickets:sla` (optional `--company=`).

`retry_after` is 180 seconds on the `database` and `redis` connections (`DB_QUEUE_RETRY_AFTER`, `REDIS_QUEUE_RETRY_AFTER`), above the longest default-queue timeout (120 seconds). The `platform` connection uses 3660 seconds.

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
