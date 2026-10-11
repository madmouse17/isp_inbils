# BACKEND: WhatsApp delivery status webhook and STOP opt-out

Status: done
Role: backend
Blocked by: 01
Card: none (new lane; no Hermes card)

## Scope

Spec Q10a and the STOP part of Q5a.

- NEW `routes/webhooks.php` with `GET` and `POST webhooks/whatsapp` → `WhatsAppWebhookController`; registered in `bootstrap/app.php` inside the `then:` closure (`:20-22`) with `Route::middleware('throttle:whatsapp-webhook')` and **without** the `web` group (no session, no CSRF), like `Modules/Billing/app/Providers/RouteServiceProvider.php:53-57`.
- `GET`: return `hub.challenge` as plain text 200 when `hub.mode=subscribe` and `hub.verify_token` equals the configured token (`hash_equals`); else 403. 404 when the feature is not configured.
- `POST`: verify `X-Hub-Signature-256` over the raw body with `WhatsAppCloudClient::isValidSignature` before parsing; 403 with empty body on failure, zero DB access. Then for each `entry[].changes[].value`:
  - `statuses[]`: find `whatsapp_messages` by `provider_message_id` (unknown → ignore); apply monotonic transition (`queued|sent → delivered → read`; `failed` only from `queued|sent`), set the matching timestamp, `error_code` from `errors[0].code`, `billable`/`pricing_category` from `pricing`. Updates run in the row's company (`CompanyService::runAs`).
  - `messages[]` of type `text` whose trimmed, case-insensitive body is `STOP` or `BERHENTI`: normalise `from`, set `whatsapp_opt_out_at` on every matching customer (compare normalised phones; one company at a time via `runAs`), audit `whatsapp` / `opted_out_by_reply` with customer id only.
  - Everything else is ignored.
- Always 200 for a valid signature (Meta retries otherwise). Never log the raw body; log message ids, statuses and error codes only.

Non-goals: inbound chat, auto-replies, read receipts in the UI beyond the stored timestamps.

## Acceptance criteria

- [ ] Verify handshake: correct token → challenge echoed; wrong token → 403; not configured → 404.
- [ ] POST without or with a wrong signature → 403, no row changed, counted by the limiter; valid signature never throttled.
- [ ] `sent → delivered → read` progression stored; an out-of-order `delivered` after `read` changes nothing; duplicate callback changes nothing; `failed` stores the error code.
- [ ] `pricing.billable` stored; unknown message id → 200, no change.
- [ ] Inbound `stop` / ` Berhenti ` from a number that matches customers in two companies opts out both, audit row per company, no reply sent; any other text changes nothing.
- [ ] Route reachable without session/CSRF; `PUT`/`DELETE` → 405.
- [ ] Raw body and phone numbers absent from logs (assert via log fake).
- [ ] Pint clean; Larastan clean on new files.

## Comments

### 2026-10-11 — Review fix WA-STOP-REPLAY (low) (WhatsappFixes)

- **Receipts.** `whatsapp_inbound_receipts` (migration `2026_10_13_300003`, `App\Models\Core\WhatsAppInboundReceipt`, company-scoped, unique `(company_id, message_hash)`): one row per company and handled STOP, holding `hash_hmac('sha256', wamid, app.key)` and nothing else (Meta ids embed the sender's number; no number, no text). Pruned after 30 days by the daily `model:prune`, which `routes/console.php` now schedules for it next to `WhatsAppMessage`. Written first in the same per-company transaction as the withdrawals (`createOrFirst`); a failing withdrawal rolls the receipt back, the webhook answers with the error and Meta delivers the reply again, and a company that already finished is skipped by its receipt. A company with no matching customer keeps no receipt. A STOP without a message id still applies (timestamp rule only).
- **Timestamp rule.** A STOP whose signed `timestamp` is older than the customer's `whatsapp_opt_in_at` is ignored (the same second applies). The matching customers are re-read with `lockForUpdate()` inside the transaction, so a staff re-consent that commits first wins over a STOP sent before it. The opt-out is still written with the receipt time.
- **Status updates.** `delivered`/`read` set `sent_at` when the row never got one (a queued row Meta delivered), so the message counts against the cap of the month it left in; a `sent` row keeps its `sent_at`.
- **Redaction.** `#[\SensitiveParameter]` on `WhatsAppWebhookHandler::handle|applyStatus|applyReply|optOutCustomers|values|items|errorCode` (phone, text and Meta's `recipient_id` live in those arrays and arguments).
- Tests: `WhatsAppWebhookTest` (redelivery after re-consent, handled reply, stale STOP, receipts per company, rollback, sent_at), `WhatsAppTraceRedactionTest`, `WhatsAppPrivacyAndTenantTest`.
