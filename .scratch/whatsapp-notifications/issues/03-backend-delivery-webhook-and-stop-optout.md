# BACKEND: WhatsApp delivery status webhook and STOP opt-out

Status: ready-for-agent
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
