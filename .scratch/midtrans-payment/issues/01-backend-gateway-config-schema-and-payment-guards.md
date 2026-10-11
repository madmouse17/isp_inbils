# BACKEND: Midtrans config, gateway client, payment_attempts schema, payment-service guards

Status: needs-owner-approval
Role: backend
Blocked by: none (starts only after owner approves `../spec.md` Q1-Q13)
Card: none (new lane; no Hermes card)

## Scope

- `config/services.php` `midtrans` block reading `MIDTRANS_MERCHANT_ID`, `MIDTRANS_CLIENT_KEY`, `MIDTRANS_SERVER_KEY`, `MIDTRANS_IS_PRODUCTION` (names only; no values anywhere in the repo); gateway-enabled predicate = server key present; base URLs from the production flag (sandbox default).
- `MidtransGateway` in the Billing module (plain `Http` client, 10 s timeout, no retry on create): `createSnapTransaction`, `transactionStatus`, `isValidSignature` (`hash('sha512', order_id.status_code.gross_amount.server_key)` + `hash_equals`). Confirm the Snap/Status request and response shapes and the `order_id` length limit against the sandbox first and record them in a ticket comment (no keys in the comment). No `midtrans/midtrans-php` (Q4b).
- Migrations: `payment_attempts` table per spec Q6 (`BelongsToCompany`, unique `order_id`, nullable unique `payment_id` FK to `payments`, status enum `pending|paid|failed|expired|exception`, indexes `(company_id, invoice_id, status)`); `payments.received_by` made nullable (Q8a); `payments.method` ENUM gains `midtrans`. Both reversible; rehearse on `inbils_test` only.
- `BillingService::recordPayment`: payable-status guard (`sent|partial|overdue` only, 422 otherwise) and no assumption of an authenticated user (`received_by` null allowed). `RecordPaymentRequest` still accepts only `cash|transfer|cheque|other`.
- `PaymentAttempt` model + factory; `PaymentAttemptService` create-or-reuse (one open attempt per invoice, amount = `Invoice::sisa`, whole rupiah only, expiry 24 h) — attempt creation only; settlement is ticket 02.
- Update the `Payment` docblock/test expectations that assumed NOT NULL `received_by`.

Non-goals: webhook, portal, reconciliation, UI, per-company keys.

## Acceptance criteria

- [ ] With no server key configured, the gateway-enabled predicate is false and no outbound call is possible; with a fake key plus `Http::fake`, `createSnapTransaction` returns a token and redirect URL and sends HTTP Basic auth derived from the key (asserted without printing it).
- [ ] `isValidSignature` accepts a payload signed with the fake test key and rejects altered `order_id`, `status_code`, `gross_amount` or signature, in constant time.
- [ ] Migrations up/down pass on `inbils_test`; existing payments keep their `received_by`.
- [ ] `recordPayment` on `draft` or `cancelled` invoices now returns 422; on `sent|partial|overdue` it works with and without an authenticated user; existing Billing tests updated and green.
- [ ] `PaymentAttemptService` reuses an open attempt, refuses a non-payable invoice and a non-integer-rupiah balance, and produces `order_id`s unique across two companies with the same invoice number.
- [ ] No Midtrans key, Authorization header or full secret appears in logs or test output; `grep -ri midtrans` finds only env names, config reads and the gateway.
- [ ] Pint and scoped Larastan clean.
