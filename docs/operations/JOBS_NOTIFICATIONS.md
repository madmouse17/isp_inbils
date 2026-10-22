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
| `customers:document-expiry` | daily @ 00:30 | `withoutOverlapping(120)` | Bell reminder for customer documents that enter their last 30 days (see below) |
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
| Billing command failure (per failed company, to that company's holders of `roles.manage`) | `BillingScheduleFailedNotification` | mail + database |
| Ticket assigned | `TicketAssignedNotification` | mail + database + push |
| Ticket reopened by staff (to the handler, else the holders of `ticket.assign`; the actor is left out) | `TicketStaffNotification` stage `reopened` | database + push |
| Ticket reopened by staff (to the customer) | `TicketCustomerUpdateNotification` stage `reopened` | database + push |
| Ticket SLA breach | `TicketSlaBreachedNotification` | mail + database |
| Invoice reminder sent by hand ("Kirim pengingat", once per invoice per company day) | `InvoiceDueReminderNotification` stage `manual` | WhatsApp + database + push |
| Customer document within 30 days of its expiry | `CustomerDocumentExpiringNotification` (type `customer_document_expiring`) | database, to holders of `customer.update` |
| Staff account created by an ISP administrator, or "Kirim ulang undangan" | `StaffAccountInvitation` | mail, always through the platform mailer |
| A tenant asks for a subsidiary (K34) | `SubsidiaryRequestedMail`, one mail per active platform admin | mail, platform mailer |
| An operator rejects that request | `SubsidiaryRequestRejectedMail`, to the user who asked | mail, platform mailer |
| Queue job terminal fail | `ReportFailedQueueJob` listener | log only (ExceptionReporter + warning) |
| Announcement published or scheduled start reached | `AnnouncementPublishedNotification` | database |

No SMS / WhatsApp / external pager. Bodies include safe IDs only (command name, company_id, ticket_id/code, job uuid). No stack traces in notification body.

## Failure path

1. Command/job throws.
2. The throwable is reported (`ExceptionReporter` logs redacted context, `LogRedactor`).
3. Billing commands catch per company, so one failing company does not stop the others. At the end each failed company's holders of `roles.manage` get one `BillingScheduleFailedNotification`, and the command exits 1. Admins of other companies get nothing.
4. Queue terminal failure → `JobFailed` → `App\Listeners\ReportFailedQueueJob` (registered in `AppServiceProvider`) → report + log warning. `CheckTicketSlaBreachesJob`, `SendAnnouncementNotifications` and `SendPushNotification` also write their own warning from `failed()` (`tickets.sla_check_failed`, `announcement.notify_failed`, `push.send_failed`) with ids only; see [RELIABLE_JOBS.md](./RELIABLE_JOBS.md). The row stays in `failed_jobs` for 30 days: the daily `queue:prune-failed --hours=720` deletes older rows, so retry or investigate inside that window.
5. External central reporter (`ExceptionReporter::notifyExternal`) still OWNER BLOCKER (no Sentry/etc credentials).

## Idempotency

- Recurring invoice generation: locks subscription row; skips a subscription that already has a recurring invoice for the period start in any status (a cancelled one counts). The unique `invoices_active_recurring_period_unique` is the backstop when two runs race.
- Overdue mark: pure status update on past-due rows; re-run safe.
- SLA breach: `tickets.sla_breached_notified_at` set under `lockForUpdate` after notify; re-run skips.
- Ticket assign notify: only when `assigned_to` changes.
- Ticket reopen: `TicketService::reopenByStaff` runs under the ticket row lock and refuses a ticket that is not resolved or closed (422 "Hanya tiket yang diselesaikan atau ditutup yang bisa dibuka kembali."). The queued notice carries ids only; the actor and the reason are read from the `ticket`/`reopened` audit row when it is sent.
- Manual invoice reminder: the marker `invoice-remind:{company}:{invoice}:{date}` in the cache (`Cache::add`, until the end of the company's day) lets one reminder through per invoice per company day. A second try answers 429 "Pengingat untuk {number} sudah dikirim hari ini." (a page visit gets the same text as a toast). A failed send removes the marker, so the reminder can be retried. Each sent reminder writes an `invoice`/`reminder_sent` audit row with its channels. The WhatsApp dedupe key of stage `manual` holds the day, so each day's reminder is its own message; the daily command never sends stage `manual`.
- Document expiry: `customers:document-expiry` runs per active company (`--company=<id>` limits it) and looks at customer media in the collection `documents` whose `expires_at` falls between today (company calendar day) and 30 days ahead. It writes `expiry_notified_for` on the media once the bell rows are sent, so each expiry date is announced once; a changed date is announced again when it is inside the window. The row is locked while it is checked, so two overlapping runs cannot send twice. A company with no holder of `customer.update` is skipped. `shortcut:` it scans the customer media of one company; add a generated column and index past 100k media rows.
- Midtrans callback: every call to the notification URL writes one info line `midtrans.callback` with `order_id`, `transaction_status`, `status_code`, `fraud_status` and the HTTP status the app answered, refused calls (403, 404, 422) included, so a rotated server key shows up as a run of 403 lines. Once the signature proves the sender and the attempt is known, one audit row `payment_attempt`/`callback_received` joins the line. Neither keeps the signature or the amount. The audit rows feed the invoice page's "Riwayat notifikasi Midtrans" (last 5 per attempt). The route limiter `midtrans-webhook` counts a verified callback of the company's own order per company, order and status code (10 a minute) and everything else per address (30 a minute); it is no longer unlimited.
- Announcement fan-out: `announcements.notified_at` is claimed under `lockForUpdate`; reruns skip the announcement.
- Admin web writes: `PreventDuplicateWebSubmit` refuses an identical write by the same user and company to an `admin.*` route within 10 seconds (409 "Permintaan yang sama baru saja dikirim."). `SECURITY_DUPLICATE_SUBMIT_ENABLED` switches it (default on, off in `testing`).
- Stale forms: edit forms send `expected_updated_at`; `OptimisticLock` compares it under the row lock and answers 409 "Data diubah pengguna lain; muat ulang." (`ConflictBanner` shows it with a reload button). A write from a tab that still shows another company is refused with 409 "Perusahaan aktif berubah di tab lain." through the `X-Inbils-Company` header. Details: `docs/operations/RELIABLE_JOBS.md`.

## Branded mail

Every notification mail goes through `App\Notifications\Channels\CompanyMailChannel` and carries a brand from `App\Support\MailBrand::for()`: the company name in the header, the footer and the signature, and the company logo when it is a public file (a private logo cannot be fetched by a mail client, so the name alone shows). A mail the platform sends (reset, verification, `StaffAccountInvitation`, `TenantAdminInvitation`, the access link of a new customer account, both subsidiary-request mails: every class that implements `SentByPlatform`) carries the Inbils brand and always leaves through the platform mailer, never a company's own SMTP.

- Views: `resources/views/vendor/notifications/email.blade.php`, `resources/views/vendor/mail/html/message.blade.php` and `.../text/message.blade.php`. A Laravel upgrade that changes the stock views needs them compared.
- Sender: a company with its own enabled e-mail integration (Pengaturan → Integrasi) sends from its own SMTP account and address. Every other company leaves from the platform address (`MAIL_FROM_ADDRESS`) with the ISP's name as the From name. `MAIL_FROM_ADDRESS` must be a real address in production: `/ready` reports `mail` as `fail` otherwise (see [OBSERVABILITY.md](./OBSERVABILITY.md)).
- Text typed by users (customer names, ticket titles, company names, the reason of a rejection) goes through `MailBrand::literal()` before it becomes a line, because the mail body is Markdown and a typed `[text](url)` would otherwise become a link the platform's mail vouches for.
- `TicketAssignedNotification` mail: subject "Tiket {code} ditugaskan kepada Anda", then the title, customer, priority and response deadline, and the button "Buka tiket".
- `StaffAccountInvitation`: subject "Undangan staf {company} di Inbils", button "Atur kata sandi" with a password-broker link; the link lives as long as the password-reset token (`auth.passwords.<broker>.expire`). Staff accounts are made by invitation only, with no typed password; "Kirim ulang undangan" on the user page sends a new link.
- Subsidiary requests (K34): the tenant administrator of a head company sends **Minta anak perusahaan** (Nama, Kode, Catatan, one pending request at a time). The request is a platform audit row, not a table. `SubsidiaryRequestedMail` ("Permintaan anak perusahaan dari {company}") goes to every active platform admin on demand, never queued with the typed text: the job holds the company id and the request's public id and reads the text when it is sent. **Buat anak perusahaan** on the platform page closes the request; **Tolak** with a reason closes it and sends `SubsidiaryRequestRejectedMail` ("Permintaan anak perusahaan ditolak") to the requester.

## Jobs

| Job | Tries | Backoff | Timeout | Unique |
|---|---|---|---|---|
| `CheckTicketSlaBreachesJob` | 3 | 60/300s | 60s | 300s |
| `SendAnnouncementNotifications` | 3 | 30/120/300s | 120s | 600s per announcement |
| `BuildTenantExportJob` (`platform` queue) | 1 | none | 3600s | none |
| `WriteNasDeviceJob` | 1 | none | 60s | until processing starts (lock safety 600s), per company and router |
| `CancelMidtransTransactionJob` | 3 | 10/60/300s | 30s | none |

Dispatched by `tickets:sla` (optional `--company=`), by the button **Sinkronkan ke RADIUS** on a router page (`WriteNasDeviceJob`; `POST nas-devices/{nasDevice}/sync`, permission `network_asset.nas.manage`, 6 a minute, audit `nas_device`/`sync_requested`) and by `PaymentAttemptService` (`CancelMidtransTransactionJob`).

`WriteNasDeviceJob` writes one NAS router to the company's RADIUS database at once, so the operator does not wait for the 15-minute `network:sync` sweep. A company without RADIUS gets the warning "Belum dikirim ke RADIUS karena RADIUS belum dikonfigurasi untuk perusahaan ini." and no job; otherwise the page says "Sinkronisasi ke RADIUS dijadwalkan." The job does nothing when RADIUS was switched off or the router was removed meanwhile. A failed write stays on the row (`last_error`) and is not thrown, so there is no retry: the sweep is the retry. A double click queues one run.

`CancelMidtransTransactionJob` asks Midtrans to cancel the transaction of a payment page that Inbils closed, so a customer who still holds the page cannot pay it. It runs after the commit that closed the page and is retried while Midtrans is unreachable. Three paths queue it: closing the open pages of an invoice that was paid, cancelled or whose balance shrank; the button **Batalkan di Midtrans** on the invoice page (`POST payment-attempts/{attempt}/cancel`, permission `billing.payment.record`, only for a page that is still pending, flash "Pembatalan dikirim ke Midtrans.", audit `payment_attempt`/`cancel_requested`); and a Midtrans notification that still reports a pending transaction for a page Inbils closed. The job does nothing for a page that settled or was reopened since, or when Midtrans is off for the company.

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
