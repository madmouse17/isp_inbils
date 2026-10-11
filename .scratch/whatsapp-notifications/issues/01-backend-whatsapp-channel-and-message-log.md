# BACKEND: WhatsApp channel, Cloud API client, phone normaliser, message log and consent schema

Status: done
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

## Comments

### 2026-10-11 — Meta Cloud API verification (WhatsappBackend, before any code)

Sources read 2026-10-11 on developers.facebook.com/documentation/business-messaging/whatsapp: `reference/whatsapp-business-phone-number/message-api`, `messages/send-messages`, `templates/overview`, `support/error-codes`, `webhooks/overview`, `webhooks/create-webhook-endpoint`, `webhooks/reference/messages`, `webhooks/reference/messages/status`, `messaging-limits`; Graph changelog (docs/graph-api/changelog).

**Send template.** `POST https://graph.facebook.com/{version}/{phone-number-id}/messages`, headers `Authorization: Bearer <token>`, `Content-Type: application/json`. Body: `{"messaging_product":"whatsapp","recipient_type":"individual","to":"<digits>","type":"template","template":{"name":"<name>","language":{"code":"id"},"components":[{"type":"body","parameters":[{"type":"text","text":"…"}]}]}}`. Positional parameters are the default and must be in placeholder order. `+`, `-`, `()` and spaces are accepted in `to`; without a country code Meta prepends the *business* number's calling code (misdelivery) — we always send full `62…` digits. 200 response: `{"messaging_product":"whatsapp","contacts":[{"input","wa_id"}],"messages":[{"id":"wamid…","message_status":"accepted"}]}` — it only means *accepted*; delivery arrives by webhook. Error body: `{"error":{"message":"(#130429) Rate limit hit","type":"OAuthException","code":130429,"error_data":{"messaging_product":"whatsapp","details":"…"},"fbtrace_id":"…"}}`; Meta says to branch on `code`, not on HTTP status or title. Current Graph version: v26.0 released 2026-07-29, v25.0 available until 2028-07-29 → default `WHATSAPP_GRAPH_VERSION=v25.0`.

**Error classification used by the client** (codes from the error-codes page). Retry (transient): HTTP 5xx and network errors; auth `0`, `3`, `10`, `190`, `200-299` (token problem fixed by ops, then `queue:retry`; failing the row would block the dedupe key); throttling `4`, `80007`, `130429`, `131048`, `131056`, `131064`; server `1`, `2`, `131000`, `131016`, `131057`, `133004`. Permanent (`WhatsAppSendRejected`, row `failed`, no retry): everything else, notably `100`, `130403`, `131008`, `131009`, `131021`, `131026` (not a WhatsApp number), `131049`, `131050`, `132000` (parameter count), `132001` (template missing/unapproved), `132005`, `132007`, `132012`, `132015`, `132016`, `135000`, `368`, `130497`, `131031`, `131042`.

**Template text parameters.** Meta rejects (132012/132018) newline/tab or runs of >4 spaces inside a text parameter and empty values → the client collapses whitespace and substitutes `-` for empty values.

**Messaging limit (business-initiated).** Per *business portfolio* (shared by all its numbers): a new portfolio starts at 250 unique recipients per moving 24 h outside customer-service windows; 2 000 after business verification or 2 000 delivered high-quality template messages in 30 days; then automatic steps 10 000 → 100 000 → unlimited when ≥ half the limit was used in 7 days and quality is high. Utility templates count toward it. A new recurring batch above the limit must spread over days (reminders already spread by due date). The owner reads the current tier in WhatsApp Manager → Account tools → Messaging limits.

**Webhook.** Verification `GET <callback>?hub.mode=subscribe&hub.challenge=<n>&hub.verify_token=<token>` → compare token, answer 200 with the challenge body. Delivery `POST` with `Content-Type: application/json` and `X-Hub-Signature-256: sha256=<hex>` where hex = HMAC-SHA256 of the raw payload keyed with the **app secret**; answer 200, anything else makes Meta retry for up to 7 days (duplicates are possible → dedupe). Batches carry up to 1 000 updates; payload up to 3 MB. Status payload: `entry[].changes[].value.statuses[]` = `{id (wamid), status: sent|delivered|read|failed|played, timestamp (unix string), recipient_id, pricing?: {billable, pricing_model: PMP|CBP, type: regular|free_customer_service|free_entry_point, category}, errors?: [{code:int,title,message,error_data:{details}}]}`; `pricing` appears only on `sent` and on one of `delivered|read`; `billable` is to be deprecated in favour of `pricing.type` + `pricing.category`; a message read at once may skip `delivered`. Inbound text: `value.messages[] = {from: "<digits>", id, timestamp, type: "text", text: {body}}`.

Decisions taken from this: statuses are ranked `queued < sent < delivered < read` (a skipped `delivered` is fine); `billable` falls back to `pricing.type === 'regular'` when absent.

### 2026-10-11 — Review fixes WA-PII-TRACE-ARGS (medium) and WA-BUDGET-NONATOMIC (low) (WhatsappFixes)

- **Trace.** `#[\SensitiveParameter]` on `WhatsAppCloudClient::sendTemplate($to, $parameters)`, `isValidSignature($rawBody, $header)`, `isValidVerifyToken($token)`, `WhatsAppChannel::deliver|reserve($payload)`, `transmit($phone, $payload)` and `PhoneNumber::normalizeIndonesianMobile($phone)`: PHP records an opaque `SensitiveParameterValue` in the trace instead of the value. `docker/php/conf.d/inbils.ini` (`zend.exception_ignore_args=On`) is now copied into `/usr/local/etc/php/conf.d/` in the Dockerfile application stage. Checked on the existing `inbils-app:local` image: no php.ini, scan dir `/usr/local/etc/php/conf.d`, `zend.exception_ignore_args` = `0` (the leak); with the drop-in mounted it is `1`. The deploy image still has to be rebuilt to ship it. Sail needs nothing: its Ubuntu `/etc/php/8.4/cli/php.ini` already gives `1`. Tests do not depend on either (they set the ini at run time).
- **Cap.** `WhatsAppChannel::deliver` runs decision and claim in one `DB::transaction` that starts with `select ... from companies ... for update` (`WhatsAppSettings::lockedFor`); the lock is released before Meta is called and the queued row holds the slot meanwhile. The cap counts the messages handed to Meta since the start of the company-local month by `sent_at`, plus queued rows whose last attempt is under 2 hours old (five attempts with backoff 60/300/900/3600 s span 81 minutes); a retry does not count its own row. EXPLAIN of that count on 150 000 generated rows (50 000 for the company, 400 days): `range` on `whatsapp_messages_quota_index`, 876 rows examined. `WhatsAppSettings::usage()` attributes a message to the month of `sent_at` (created month when it was never sent); that settings-page query scans the company's log, which is cheap at the default cap and only worth an index when a company keeps more than about a million rows.
- **Access-link key.** `CustomerAccountCreated` now fixes the hour of `access_link_notice:{user}:{Y-m-d-H}` when the notice is queued (it read `now()` at run time, so a retry across the hour made a second row). Still off the `database` channel.
- Tests: `WhatsAppChannelTest` (cap by sent month, in-flight slot, own retry, two workers, lock before the log), `WhatsAppCompanySettingsTest::test_usage_counts_this_months_rows_of_this_company_only`, `WhatsAppTraceRedactionTest`, `WhatsAppPrivacyAndTenantTest`, `WhatsAppAccessLinkNoticeTest`.
