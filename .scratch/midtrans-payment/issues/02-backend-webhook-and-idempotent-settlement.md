# BACKEND: Midtrans webhook, signature verification, idempotent payment recording

Status: done
Role: backend
Blocked by: 01
Card: none (new lane; no Hermes card)

## Scope

- `POST /webhooks/midtrans`: no auth, CSRF-exempt (explicit exception in `bootstrap/app.php`), throttled, JSON only, thin controller.
- Order: validate required fields → verify signature (403, empty body on failure, no DB access beyond the attempt lookup after a valid signature) → find attempt by `order_id` (404 if unknown) → `PaymentAttemptService::applyNotification`.
- `applyNotification` (shared with reconciliation): `lockForUpdate` the attempt; if `payment_id` is set return no-op; map `transaction_status`/`fraud_status` per spec Q9; for paid: verify `gross_amount` equals the attempt amount, then `CompanyService::runAs(attempt.company_id)` → `BillingService::recordPayment(invoice, amount, 'midtrans', order_id, notes)`; store `payment_id`, `settled_at`, Midtrans transaction id and payment type; update the attempt on pending/deny/cancel/expire; mark `exception` on amount mismatch, refund/chargeback, or any 422 from the billing service. Return 200 for every valid-signature notification (including `exception`) so Midtrans stops retrying.
- Audit every outcome (`payment_attempt` log name), ids and amounts only; store the last raw notification JSON on the attempt.
- No automatic reversal, no subscription status change (Q9, Q12).

Non-goals: Status-API polling (ticket 04), portal, UI, notifications.

## Acceptance criteria

- [x] Valid settlement notification (fake test key): exactly one `payments` row (`method=midtrans`, `reference=order_id`, `received_by` null), invoice `paid` (or `partial` if balance remains), attempt `paid` with `payment_id`; response 200.
- [x] The same notification replayed 3× and a second worker holding a stale attempt leave exactly one payment, an unchanged invoice and an attempt that stays `paid` (true parallel processes were not run; the invoice-then-attempt row locks and the unique `payments` reference are the guards).
- [x] Bad signature or missing field: 403/422 respectively, zero rows changed; unknown `order_id`: 404.
- [x] Amount differing from the attempt, invoice cancelled/already paid/draft, balance shrunk by a manual payment, `capture` with `fraud_status=challenge`, refund/chargeback: attempt `exception` (or unchanged for challenge), no payment, response 200, audit row written.
- [x] pending keeps the attempt `pending`; deny/cancel/failure/expire close it as `failed`/`expired`.
- [x] A notification can only credit the company that owns the attempt (two-company test, same invoice number in both).
- [x] Route is reachable without session/CSRF token (outside the `web` group, so no `bootstrap/app.php` exception was needed) and is not reachable via GET (405).
- [x] Pint clean; Larastan clean on new files; Billing suite green.
