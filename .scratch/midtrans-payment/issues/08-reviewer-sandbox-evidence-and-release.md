# REVIEWER: sandbox end-to-end evidence and release readiness

Status: in-progress
Role: reviewer
Blocked by: 07
Card: none (new lane; no Hermes card)

## Scope

- Run one real Midtrans **sandbox** payment end to end over a public HTTPS tunnel (Midtrans cannot call localhost): create attempt in the portal, pay with a sandbox method, receive the real notification, confirm one payment and invoice status; then run `midtrans:reconcile` and confirm no change. Evidence = log/screenshot excerpts with all keys redacted.
- Confirm docs updated: `docs/ENTERPRISE_ISP_READINESS.md` (scope decision), `docs/business/billing.md` (methods, attempt state machine, portal), CHANGELOG; ledger entry added and `.scratch` status reconciled.
- Confirm deploy notes: `php artisan migrate`, `php artisan db:seed --class=RolePermissionSeeder --force`, four env values set on the host, Midtrans dashboard Payment Notification URL, scheduler running.
- Rollback note: migrations down (`payment_attempts`, `payments.method`, `received_by` NOT NULL only if no null rows exist — document the precondition).

## Acceptance criteria

- [ ] Verdict APPROVED or BLOCKED with file:line findings.
- [ ] Sandbox evidence attached without any key material.
- [ ] Release notes and rollback precondition recorded in the ledger.

## Sandbox evidence (2026-10-12, Sail, keys redacted)

- Customer `customer@demo.inbils.test` opened `/portal/invoices/12` (Rp 120.000, `sent`) and pressed Bayar: one attempt `1-12-ueimdgvdeptq` (pending), redirect to `https://app.sandbox.midtrans.com/snap/v4/redirection/<token>`.
- Paid on the hosted page with the Midtrans sandbox test card (4811 …1114): Midtrans returned to `/portal/payments/return?...&transaction_status=capture`; the page showed "Menunggu konfirmasi" (it does not trust the query string).
- Real Status API `GET https://api.sandbox.midtrans.com/v2/1-12-ueimdgvdeptq/status`: `status_code 200`, `transaction_status capture`, `fraud_status accept`, `payment_type credit_card`, `gross_amount 120000.00`, Midtrans `signature_key` present.
- That Midtrans-signed payload POSTed to `/webhooks/midtrans`: HTTP 200 → attempt `paid`, payment #128 `method=midtrans`, `amount 120000.00`, `reference=1-12-ueimdgvdeptq`, `received_by NULL`, invoice `paid` (`paid_amount 120000.00`), portal shows "Lunas". Same payload again: HTTP 200, still one payment. Payload with a forged `signature_key`: HTTP 403.
- `midtrans:reconcile` afterwards: 0 checked (no pending attempt), no change.
- Residual: delivery *by Midtrans* to a public URL not exercised (localhost cannot receive it). New `MIDTRANS_NOTIFICATION_URL` (HTTPS only, sent as `X-Override-Notification`) removes the dashboard dependency; set it to a public HTTPS tunnel or the staging host and repeat one payment to close this ticket.
