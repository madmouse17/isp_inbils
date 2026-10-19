# Reliable jobs & notifications (P1-B)

Native Laravel mail/database/log only. No SMS/WhatsApp/external provider.

## Schedules (`routes/console.php`)

| Command | Cadence | Overlap lock (`withoutOverlapping`, minutes) |
|---|---|---|
| `ops:scheduler-heartbeat` | every 5 minutes | none |
| `billing:whatsapp-reminders` | daily @ 01:00 UTC | 120 |
| `billing:generate` | daily @ 02:00 | 120 |
| `billing:check-overdue` | daily @ 03:00 | 120 |
| `billing:suspend-overdue` | daily @ 08:00 | 120 |
| `tickets:sla` | every 15 minutes | 10 |
| `midtrans:reconcile` | every 15 minutes | 10 |
| `network:sync` | every 15 minutes | 10 |
| `announcements:publish-scheduled` | every minute | 10 |
| `promo-banners:cleanup-images` | daily | 120 |
| `inbils:platform:exports:prune` | hourly | 50 |
| `model:prune` (WhatsApp message log, STOP receipts) | daily | none |
| `sanctum:prune-expired` (`--hours=24`) | daily | none |
| `inbils:activity-log:prune` | daily @ 19:30 UTC | 120 |
| `queue:prune-failed` (`--hours=720`) | daily | none |

Locks prevent concurrent scheduler runs of the same command. The number is the lock lifetime: a run that dies without releasing its lock blocks the next run until it expires. The `scheduler` container therefore runs `php artisan schedule:clear-cache` in `docker/app/entrypoint.sh` before `schedule:work`, which drops the locks a crash left in the cache. A host that starts the scheduler with cron or systemd (`schedule:run`) does not get this step and waits for the lifetime to pass.

## Billing failure notifications

- Commands: `Modules/Billing/app/Console/GenerateRecurringInvoices.php`, `CheckOverdueInvoices.php`, `SuspendOverdueSubscriptions.php`, `SendInvoiceWhatsAppReminders.php`.
- Each command loops over the active companies and catches a throwable per company: the failure is reported (`report($e)`, redacted log) and the other companies still run. `billing:generate` also counts a company as failed when a single subscription failed.
- At the end `BillingScheduleFailedNotification::alertAdmins` sends one notification (mail + database) per failed company, only to the active `admin` role users of that company. The command exits 1 when any company failed.
- Payload: command name, company id and optional job id only. No secrets or PII.

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
- `failed_jobs` is pruned daily (`queue:prune-failed --hours=720`): a failed job is kept 30 days for diagnosis and retry, then deleted. Retry or investigate inside that window.
- A job that ran out of tries also writes one warning with the ids of what it was working on, next to the `queue.job_failed` line. The exception message stays out of it, because it can hold a value:

  | Job | Warning | Context |
  |---|---|---|
  | `CheckTicketSlaBreachesJob` | `tickets.sla_check_failed` | `company_id` (null for the all-companies run) |
  | `SendAnnouncementNotifications` | `announcement.notify_failed` | `announcement_id` (some customers may not have been told) |
  | `SendPushNotification` | `push.send_failed` | `notification_id`, `user_id`, `app`, `device_token_id` |

- Two catches that used to swallow an operational failure now write a warning and still return the same fallback: `geocode.failed` (Nominatim; `error` = exception class) and `radius.unavailable` (a company's RADIUS database; `error` = exception class). `integration.host_refused` (`company_id`, `provider`) marks a mail or RADIUS host the host policy refused. A line like these on a dashboard means a dependency is down or misconfigured, not that a user did something wrong.
- Every one of these lines carries `extra.request_id` of the request that started the work, because the Context travels into the job. See [OBSERVABILITY.md](./OBSERVABILITY.md).

## Invoice / payment / stock idempotency

- Recurring invoice: `BillingService::generateForPeriod` locks the subscription row and skips a subscription that already has a recurring invoice for the same `billing_period_start`, in any status. A cancelled invoice counts as issued, so the schedule never bills a cancelled period again behind a person's back.
- Backstop: unique `invoices_active_recurring_period_unique` on `(company_id, subscription_id, active_recurring_period)`. `active_recurring_period` is a generated column that holds `billing_period_start` only for a live (not cancelled, not deleted) recurring invoice, so a manual invoice or a cancelled one never collides. The migration refuses to run while two active recurring invoices share a period.
- Payments: unique `(company_id, active_reference)` prevents duplicate active references.
- No finance/stock history rewrite from jobs.

## Web writes: duplicate submit, versions, active company

Three guards protect the admin web from a double click, a stale form and a stale tab. All three answer with the same refusal: for an Inertia request a redirect back with `errors.conflict` and the flash `error` (shown as a toast), for a JSON request `409 {"message": "...", "error": "conflict"}` (`bootstrap/app.php`).

- Duplicate submit (`App\Http\Middleware\PreventDuplicateWebSubmit`): the same user in the same company sending an identical write (method, path, query and body, via `EnforceIdempotency::fingerprint`) to an `admin.*` route within 10 seconds gets 409 "Permintaan yang sama baru saja dikirim." The first request claims a `Cache::add` key `web.dup.{user}.{company}.{fingerprint}` for 10 seconds. A request that ends in 4xx or with validation errors frees its key, so an immediate retry reaches the controller. The company switch, the two "mark notification read" routes, invoice generate-preview, the network-account password reveal and the integration connection test are exempt. Switch: `SECURITY_DUPLICATE_SUBMIT_ENABLED` (default on, off in `testing`; see `docs/SECURITY.md`). The guard needs a cache store that outlives a request, so the E2E served app runs with `CACHE_STORE=database`.
- Optimistic version (`App\Support\OptimisticLock`): the edit forms of customer, product, employee, service package and company settings send `expected_updated_at` (the `updated_at` the page was rendered from, UTC, milliseconds). The write locks the row and compares. A mismatch gives 409 "Data diubah pengguna lain; muat ulang." and `ConflictBanner` offers "Muat ulang". The update requests of subscription, SPK, ticket and draft invoice require the field too, and their services compare it, but no web page edits those yet. `updated_at` has whole-second resolution, so two writes in the same second share a version.
- Active company (`ResolveActiveCompany`): every Inertia write that is not a GET carries the header `X-Inbils-Company` with the public id of the company the page was rendered for. If another tab switched company since, the write gets 409 "Perusahaan aktif berubah di tab lain." and the page reloads in the active company. A request without the header (tests, plain posts) passes. The company switch and logout are exempt.

## Queue timing

- `retry_after` of the `database` and `redis` connections is 180 seconds (`DB_QUEUE_RETRY_AFTER`, `REDIS_QUEUE_RETRY_AFTER`). It is above the longest job `timeout` on the default queue (120 seconds, `SendAnnouncementNotifications`), so a running job is never handed to a second worker. The `platform` connection uses 3660 seconds for its 3600-second jobs.
- `SendAnnouncementNotifications` is `ShouldBeUnique` per announcement with `$uniqueFor` 600 seconds: if a lock outlives a job that never reached a worker, a later dispatch is accepted after ten minutes.

## Explicit non-goals

- No SMS/WhatsApp/pager provider.
- No secrets in notifications or logs.
