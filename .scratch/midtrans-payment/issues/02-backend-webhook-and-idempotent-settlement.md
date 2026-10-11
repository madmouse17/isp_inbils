# BACKEND: Midtrans webhook, signature verification, idempotent payment recording

Status: needs-owner-approval
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

- [ ] Valid settlement notification (fake test key): exactly one `payments` row (`method=midtrans`, `reference=order_id`, `received_by` null), invoice `paid` (or `partial` if balance remains), attempt `paid` with `payment_id`; response 200.
- [ ] The same notification replayed 3× and two concurrent identical notifications leave exactly one payment and an unchanged invoice.
- [ ] Bad signature or missing field: 403/422 respectively, zero rows changed; unknown `order_id`: 404.
- [ ] Amount differing from the attempt, invoice cancelled/already paid, `capture` with `fraud_status=challenge`, refund/chargeback: attempt `exception` (or unchanged for challenge), no payment, response 200, audit row written.
- [ ] pending keeps the attempt `pending`; deny/cancel/expire close it as `failed`/`expired`.
- [ ] A notification can only credit the company that owns the attempt (two-company test).
- [ ] Route is reachable without session/CSRF token and is not reachable via GET.
- [ ] Pint and scoped Larastan clean; focused Billing suite green.
