# BACKEND: WhatsApp channel, Cloud API client, phone normaliser, message log and consent schema

Status: ready-for-agent
Role: backend
Blocked by: none (owner approval of spec Q1-Q13)
Card: none (new lane; no Hermes card)

## Scope

Foundation every other ticket consumes (spec "Implementation Decisions"). Assumes Q1a, Q2a, Q7a, Q8a, Q9a, Q11a, Q12a.

- **Verify first, record in this file under `## Comments`:** current Cloud API send-template request/response and error codes (developers.facebook.com WhatsApp Cloud API "Messages" and "Error codes"), business-initiated messaging limits for a new number, webhook payload shape and the `X-Hub-Signature-256` rule. No code before this note exists.
- Config: `config/services.php` `whatsapp` block reading `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_BUSINESS_ACCOUNT_ID`, `WHATSAPP_APP_SECRET`, `WHATSAPP_WEBHOOK_VERIFY_TOKEN`, `WHATSAPP_GRAPH_VERSION`; empty entries for those names in `.env.example` (names only; never touch real `.env*`). NEW `config/whatsapp.php`: event → `template`, `language` (`id`), ordered parameter list, `batch` flag, for the eight events of spec Q3; default quiet window `21:00`-`07:00`; default monthly cap `1000`; send rate per company per second `20`.
- `WhatsAppCloudClient` (`Http`, `timeout(10)`, `connectTimeout(5)`, bearer token; mimic `Modules/Billing/app/Services/MidtransGateway.php:163-167`): `isConfigured(): bool`, `sendTemplate(string $to, string $template, string $language, list<string> $parameters): string` (returns the provider message id; throws `WhatsAppSendRejected` on permanent 4xx errors; lets transient errors/5xx/timeouts throw for retry), `isValidSignature(string $rawBody, ?string $header): bool` (`hash_hmac('sha256', $rawBody, app secret)` + `hash_equals`). The token never appears in logs or exception messages.
- `PhoneNumber::normalizeIndonesianMobile(?string): ?string` per spec Q7a; table-driven unit test.
- `WhatsAppMessage` model (`BelongsToCompany`, `Prunable` 12 months, casts) + migration per spec schema; reversible `down()`.
- Consent migration on `customers` (`whatsapp_opt_in_at`, `whatsapp_opt_in_source`, `whatsapp_opt_in_by` FK users null-on-delete, `whatsapp_opt_out_at`); `Customer` gains `Notifiable`, casts and `hasWhatsAppConsent(): bool` (`opt_in_at` set and `opt_out_at` null); the columns are **not** in `$fillable` (ticket 02 writes them through its consent action with `forceFill`).
- `User`: WhatsApp route resolution for technicians (`employeeProfile.phone`, active user and active profile only) and customer users (linked `customer`; the channel applies the customer's consent).
- `WhatsAppChannel::send($notifiable, $notification)`: the single gate in the order of spec "Channel and client"; runs in `CompanyService::runAs($notification->companyId)`; writes/updates the `whatsapp_messages` row (`queued` → `sent` with `provider_message_id`, or `skipped` with reason, or `failed` with error code on `WhatsAppSendRejected`); an existing `(company_id, dedupe_key)` row with status other than `skipped` is a silent no-op (the unique index is the backstop for concurrent retries); stores `recipient_hash` (HMAC-SHA256 with `config('app.key')`) and `recipient_last4`, never the full number or parameters.
- `AppServiceProvider::boot` (`app/Providers/AppServiceProvider.php:80`): `RateLimiter::for('whatsapp-send', …)` keyed by company id; `RateLimiter::for('whatsapp-webhook', …)` counting only requests that fail `isValidSignature` (mimic `Modules/Billing/app/Providers/RouteServiceProvider.php:24-27`).
- Document the template wording (Bahasa Indonesia, utility, parameters in order) for the owner to submit in Meta WhatsApp Manager, and update the notifications row in `docs/ENTERPRISE_ISP_READINESS.md:63,215` to "PARTIAL: WhatsApp channel" when shipped.

Non-goals: triggers (04-06), webhook endpoint (03), UI (07), consent write surfaces (02).

## Contracts produced

- Notification side: a notification opts in by returning `WhatsAppChannel::class` from `via()` and implementing `toWhatsApp(object $notifiable): ?array` with keys `event, template, language, parameters, subject_type, subject_id, dedupe_key, batch` (spec code block); returning `null` means "nothing to send, write no row" (used by reminders whose invoice got paid). Public readonly `int $companyId`.
- Statuses `queued|sent|delivered|read|failed|skipped`; skip reasons `disabled|event_disabled|no_consent|opted_out|invalid_phone|budget_exceeded`.
- Company settings keys read by the channel: `whatsapp_enabled`, `whatsapp_events`, `whatsapp_quiet_start`, `whatsapp_quiet_end`, `whatsapp_monthly_cap` (via `Company::settings` of the notification's company, defaults from `config/whatsapp.php`).
- `WhatsAppChannel::quietDelay(int $companyId): ?\DateTimeInterface` — end of the current quiet window in company timezone, `null` outside it (consumed by batch notifications' `withDelay()`).
- `WhatsAppCloudClient::isValidSignature()` (consumed by ticket 03 and the webhook limiter).

## Acceptance criteria

- [ ] With credentials empty, any WhatsApp notification writes `skipped: disabled` and makes no HTTP call (`Http::preventStrayRequests()`).
- [ ] Faked Graph 200: exactly one request with normalised `to`, template, language `id`, parameters in order, bearer header; row `sent` with provider id.
- [ ] Each skip reason produces one `skipped` row and no HTTP call; replaying the same notification after `sent` sends nothing.
- [ ] Faked 500/timeout → job retried, one send in total; faked permanent 400 → row `failed` with the error code, no retry.
- [ ] Cap: with `whatsapp_monthly_cap = 2` and two `sent` rows this month, the third is `skipped: budget_exceeded`.
- [ ] `PhoneNumberTest` covers `0812…`, `+62 812-…`, `62812…`, `0062…`, `812…`, landline `021…`, foreign `+65…`, empty, garbage.
- [ ] No full phone, token or parameter value in the `whatsapp_messages` row, the job payload in `failed_jobs`, or log output (assert in `WhatsAppChannelTest`).
- [ ] Two companies: the channel never reads company B's settings or writes a row for B when sending for A.
- [ ] `quietDelay` returns 07:00 company time at 22:00 and `null` at 10:00 for `Asia/Jakarta`.
- [ ] Both migrations roll back cleanly; Pint clean; Larastan clean on new files.
