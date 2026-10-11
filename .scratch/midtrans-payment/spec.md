# Midtrans Snap (sandbox) — online invoice payment

Status: done
Ledger: none yet (new lane from the isp_ari gap analysis of 2026-10-11)
Source: `/tmp/isp_ari_gap.md` backlog rank 2 "Automated Payment Gateway Integration" (see Corrections: this is **not** a parity gap — isp_ari has no gateway and no billing code either).

> Credentials live only in `.env` (names `MIDTRANS_MERCHANT_ID`, `MIDTRANS_CLIENT_KEY`, `MIDTRANS_SERVER_KEY`, `MIDTRANS_IS_PRODUCTION`; placeholders already in `.env.example:67-71`). No value may appear in any file, test, log or ticket comment. Nothing starts before the owner answers the decisions below; "setuju rekomendasi" approves the recommended option of every item.

Approved by owner 2026-10-11: recommended option of every decision ("setuju rekomendasi").

## Owner decisions

1. **Q1 — Scope conflict.** `docs/ENTERPRISE_ISP_READINESS.md:7` and `:277-278` list "payment gateway and webhook contract" and "customer portal/mobile app" as excluded / P2-YAGNI ("require product decision, credentials, vendor access"). (a) Approve as a **sandbox pilot** (`MIDTRANS_IS_PRODUCTION=false`), amend the readiness doc, production go-live needs a separate approval. (b) Keep excluded; close the lane. **Recommend (a)** — the owner supplied sandbox credentials and asked for it; tradeoff: production use stays blocked until a real merchant account, public HTTPS URL and a signed security review exist.
2. **Q2 — Where does the customer pay?** There is no customer portal for invoices today. Facts: customers get a user (`CustomerService.php:20-33`) with role `customer` holding only `dashboard.view`, `ticket.view`, `ticket.create`, `ticket.comment.create`, `evaluation.customer.submit` (`RolePermissionSeeder.php:294-298`); the initial password is the customer's phone number (`CustomerService.php:23`); `docs/business/billing.md:320` marks "customer portal invoice list" as v2; `docs/MOBILE_TASK_PLAN.md` plans a Flutter customer app later.
   (a) Minimal dedicated web portal: "My invoices" list, invoice detail, Pay button, payment-return page, scoped to the logged-in customer. (b) Staff generate a payment link on the invoice and send it to the customer (public signed URL, no login). (c) Both. (d) Admin-side only (staff clicks Pay for a customer) — rejected, no customer value.
   **Recommend (a).** It is what the owner described ("customer portal pay button") and the `customers.user_id` link already exists. Tradeoff: (a) needs its own customer-scoped authorization (ticket 03) instead of reusing admin permissions, and Q3 below must be settled first. (b) is the smaller fallback if the owner wants payment before a portal.
3. **Q3 — Portal credential hygiene (blocks Q2a).** A customer can log in today with their phone number as password and then reaches the admin shell. Money screens on top of that need a stronger baseline. (a) Force a password change at first portal login (flag on the user) and send existing customers through password reset; (b) accept as is. **Recommend (a)**; tradeoff: one more small backend change and an announcement to customers. Pairs with the `auth-2fa-passkeys` lane (customers may enrol in 2FA).
4. **Q4 — Library.** (a) `midtrans/midtrans-php` (2.6.2, last release 2025-03-18, MIT, requires `ext-curl`, PHP >=5.4; configured through global static properties such as `Config::$serverKey`). (b) Laravel's `Http` client behind one `MidtransGateway` class (precedent for outbound HTTP: `app/Services/Core/OpenStreetMapGeocoder.php:22`). **Recommend (b).** Only two calls are needed (create Snap transaction, read status), the SHA-512 signature check is one `hash` call, and the SDK's global static config does not fit a multi-company app or Http::fake-based tests; no new dependency, no `composer audit` surface. Tradeoff: we maintain two small request shapes ourselves — pinned by tests against recorded sandbox payloads.
5. **Q5 — Credential scope.** (a) One install-wide merchant from `.env` (`config/services.php` `midtrans` block, which does not exist yet). (b) Per-company keys in company settings. **Recommend (a)** for the pilot; tradeoff: with multi-company active (Head Company lane), all companies pay into one merchant; order ids must therefore be unique across companies (next decision). Per-company keys later need encrypted settings and a webhook route per company.
6. **Q6 — Order id and data model.** Invoice numbers are unique per company only (`invoices` unique `(company_id, number)`, `Modules/Billing/database/migrations/2026_07_01_130000_create_invoices_table.php:38`), Midtrans requires one `order_id` per merchant. **Recommend:** new table `payment_attempts` (company_id, invoice_id, `order_id` globally unique, amount, status, snap token, redirect URL, Midtrans transaction id, payment type, last raw notification JSON, expires_at, settled_at, nullable unique `payment_id` FK) and `order_id = {company_id}-{invoice_id}-{random suffix}` kept under Midtrans' length limit (verify the limit in the sandbox, ticket 01). The immutable `payments` ledger is only written on settlement. Tradeoff: one extra table vs polluting `payments`, which is immutable by design (`Payment.php:54-80`).
7. **Q7 — What amount can be paid?** (a) Always the invoice's full remaining balance (`Invoice::sisa`, `Invoice.php:99`), one open attempt per invoice, reused until it expires; whole rupiah only (refuse otherwise). (b) Partial amounts. **Recommend (a)**; tradeoff: no part-payments online; manual partial payments stay available to staff.
8. **Q8 — How the webhook records the payment (idempotency + existing service).** `BillingService::recordPayment` (`BillingService.php:203-247`) is the single place that updates `invoices.paid_amount/status`, so the gateway must go through it, but it has four constraints the webhook violates today:
   - it needs a company context and a user: `CompanyService::currentId()` (`:205`, `:210`) and `received_by => Auth::id()` (`:229`) — the webhook is unauthenticated → run inside `CompanyService::runAs($invoice->company_id, …)` (`CompanyService.php:88`);
   - `payments.received_by` is `NOT NULL` FK to users (`Modules/Billing/database/migrations/2026_07_01_130002_create_payments_table.php:20`) while the model docblock says nullable (`Payment.php:23`) → (a) make it nullable by migration (honest "no human received this"; maker-checker `reversePayment` still works, `BillingService.php:281,287`), or (b) a per-company system user. **Recommend (a).**
   - `payments.method` is a DB ENUM `cash|transfer|cheque|other` (`database/migrations/2026_08_11_163004_enforce_latest_closed_values_as_enums.php:36`) → migration adds `midtrans`; `RecordPaymentRequest` (`:19`) keeps staff limited to the manual four;
   - a repeated `reference` aborts 422 (`BillingService.php:212-219`) instead of being a no-op → the webhook never relies on that: it locks the attempt row, returns 200 immediately when `payment_id` is already set, and otherwise records with `reference = order_id`; the DB unique index on `(company_id, active_reference)` (`create_payments_table.php:25`) is the last backstop.
   - **Existing gap, also fixed here:** `recordPayment` has no invoice status guard — a `draft` or `cancelled` invoice with a positive balance accepts a payment (`BillingService.php:203-247` checks only amount ≤ `sisa`). The documented flow is draft→sent→payment (`docs/business/billing.md:123-125`). **Recommend** payable statuses `sent|partial|overdue` only, for manual and gateway alike; the gateway path turns a rejection into an attempt `exception` (money received for a non-payable invoice needs a human). Tradeoff: manual payment on `draft` becomes 422 (behaviour change to confirm with the owner).
9. **Q9 — Which statuses count as paid, and refunds.** Per Midtrans notification guidance (docs.midtrans.com "HTTP(S) Notification / Webhooks": check `status_code` 200, `fraud_status` accept when present, be idempotent by `order_id`): paid = `transaction_status` `settlement`, or `capture` with `fraud_status=accept`. `pending` keeps the attempt open; `deny|cancel|expire|failure` close it; `refund|partial_refund|chargeback` mark the attempt `exception` and alert — **no automatic payment reversal** (reversal is a maker-checker human action, `BillingService.php:277-283`). **Recommend as stated**; tradeoff: refunds are manual.
10. **Q10 — Reconciliation.** (a) Scheduled command `midtrans:reconcile` (every 15 min, like `tickets:sla`, `routes/console.php`) polls the Status API for attempts still `pending` past a grace period and settles/expires them through the same code path as the webhook, plus a read-only "Online payments" panel on the staff invoice page. (b) CLI only. (c) Full reconciliation report page. **Recommend (a)**; tradeoff: no standalone report until the pilot shows the need.
11. **Q11 — Redirect vs embedded Snap.** (a) Server creates the Snap transaction and the browser is redirected to Midtrans' hosted `redirect_url` (no `snap.js`, no client key in the page, no CSP change). (b) Embedded `snap.js` popup with `MIDTRANS_CLIENT_KEY`. **Recommend (a)**; tradeoff: customer leaves the portal during payment and returns via the finish URL.
12. **Q12 — Auto-reactivation after payment.** Documented (`docs/business/billing.md:186-187,273-274`, `docs/business/customer.md:146`) but **not implemented** (grep for `auto_reactivate` finds nothing in code). **Recommend:** out of scope; paying does not change the subscription status, staff reactivate manually. The `customer-status-rollup` lane then keeps the customer status correct when they do.
13. **Q13 — Rule-13 exception.** Creating a Snap transaction is a user-initiated external call inside a web request (the user waits for the redirect URL); webhook handling is a short local transaction. **Recommend** accepting both inline with a 10 s HTTP timeout and no retries on create (user retries by clicking again); reconciliation covers lost notifications.

## Corrections to the scout report

- isp_ari has **no** billing, invoices or gateway code (billing "documented only", `isp_ari/docs/modules/billing-and-payments.md`), only a static QRIS image path and a WhatsApp number on the settings row (`isp_ari/database/migrations/2026_07_27_000001_create_isp_settings_table.php:19,51`). Midtrans is therefore an owner-requested new capability, not a gap vs isp_ari.
- The scout's dependency "MikroTik un-suspend action" is wrong for this lane: no automation exists in either app and auto-reactivate is unimplemented (Q12).
- The scout's "no customer-facing login or web view exists" is half right: customers have accounts and a `customer` role with ticket permissions (`CustomerService.php:20-33`, `RolePermissionSeeder.php:294-298`) but nothing for invoices. **[INFERENCE — not executed]** `TicketPolicy::view` returns true for any same-company ticket once the user has `ticket.view` and no handler permissions (`Modules/Ticketing/app/Policies/TicketPolicy.php:16-31`), so a `customer`-role user may be able to open other customers' tickets today; ticket 03 must verify and the owner should be told before any money screen ships.
- The scout did not mention that the readiness doc excludes this feature (Q1).

## Current implementation map

inbils
- Billing: `Modules/Billing/app/Services/BillingService.php` (`recordPayment :203`, `cancel :249`, `reversePayment :277`, `checkOverdue :404`), `Models/Payment.php` (immutable ledger, reversal only via service), `Models/Invoice.php` (`sisa :99`, statuses `draft|sent|partial|paid|overdue|cancelled`), `Http/Controllers/InvoiceController.php:261-275` (`recordPayment` behind `billing.payment.record`), routes `Modules/Billing/routes/web.php` (all under `auth, verified, require.has.company`, prefix `admin`).
- Schema: `payments` (`company_id`, `invoice_id`, `amount` decimal(15,2), `method` string→ENUM, `reference`, `active_reference` stored generated + unique per company, `received_by` NOT NULL, `cancelled_*`); `invoices` unique `(company_id, number)`.
- Tenant context: `CompanyService::runAs` (`:88`), `currentId()`; `BelongsToCompany` fail-closed after the Head Company lane.
- Env/config: `.env.example:67-71` has the four `MIDTRANS_*` names; `config/services.php` has no midtrans entry; no code reads them (grep `midtrans` in `app Modules config routes` is empty).
- CSRF: `bootstrap/app.php:28-40` registers no CSRF exception; a public webhook route needs one.
- Scheduler: `routes/console.php` (billing, SLA, heartbeat).
- UI: `resources/js/Pages/Admin/Billing/Invoices/Show.tsx` (payments timeline, record-payment dialog).
- Docs: `docs/ENTERPRISE_ISP_READINESS.md:7,277-279`, `docs/business/billing.md:123-125,168-173,186-187,320`, `docs/MOBILE_TASK_PLAN.md:38-39,53` (idempotency + customer payments endpoint planned).
- Prior-art tests: `tests/Feature/Billing/*` (`TenantRecheckTest`), `Modules/Billing` tests, `tests/Feature/Admin/*`.

isp_ari
- Nothing to reuse (see Corrections).

External contract (Midtrans docs, read 2026-10-11, https://docs.midtrans.com/docs/https-notification-webhooks)
- Notification is `POST` JSON with `order_id`, `status_code`, `gross_amount` (string such as `"154600.00"`), `signature_key`, `transaction_status`, `fraud_status`, `transaction_id`, `payment_type`.
- Signature: `SHA512(order_id + status_code + gross_amount + ServerKey)`, compared with `signature_key`.
- Must be reachable from the public Internet (not localhost, VPN or behind auth); handler must be idempotent by `order_id`.
- Snap create (`POST https://app.sandbox.midtrans.com/snap/v1/transactions`, HTTP Basic with the server key as username) and Status (`GET https://api.sandbox.midtrans.com/v2/{order_id}/status`) — endpoint details to be confirmed against the sandbox in ticket 01 before coding the client.

## Problem Statement

Customers can only pay by transferring money and telling staff, who then type the payment into the invoice by hand. Payments are slow to reach the books, invoices stay overdue after the customer has already paid, and staff time goes into matching transfers.

## Solution

A customer logs into a small portal, sees their own invoices, and presses "Pay" on an unpaid one. They are sent to Midtrans' hosted payment page (sandbox). When Midtrans confirms the payment, the system verifies the notification, records exactly one payment against the invoice through the normal billing service, and the invoice turns paid or partial. Lost or late notifications are caught by a scheduled reconciliation. Staff see each online payment attempt on the invoice.

## User Stories

1. As a customer, I want to see only my own invoices with their status and balance, so that I know what I owe.
2. As a customer, I want a Pay button on an unpaid invoice, so that I can pay without contacting staff.
3. As a customer, I want to choose among the payment methods Midtrans offers, so that I can use my bank or e-wallet.
4. As a customer, I want to return to the portal after paying and see the invoice status, so that I know it worked.
5. As a customer, I want a second click on Pay to reuse my open payment page instead of creating a duplicate charge, so that I do not pay twice.
6. As a customer, I want to be told clearly when an invoice cannot be paid online (cancelled, already paid, not whole rupiah), so that I know what to do.
7. As a billing clerk, I want the payment to appear automatically in the invoice's payment list with the Midtrans reference, so that I do not key it in.
8. As a billing clerk, I want the invoice status to move to paid or partial correctly, so that receivables and overdue lists are right.
9. As a billing clerk, I want to see every online attempt on the invoice (pending, paid, failed, expired, exception) with the Midtrans transaction id, so that I can answer customer disputes.
10. As a billing clerk, I want a clear exception when money arrives for a cancelled or already-paid invoice, so that I can refund or reallocate by hand.
11. As an operator, I want a duplicate or replayed Midtrans notification to change nothing, so that no payment is double-counted.
12. As an operator, I want a forged notification (bad signature) rejected without any database change, so that nobody can mark invoices paid.
13. As an operator, I want a notification whose amount differs from the attempt to be quarantined as an exception, so that underpayment cannot close an invoice.
14. As an operator, I want a scheduled reconciliation that settles attempts whose webhook never arrived, so that no customer pays and stays overdue.
15. As an owner, I want the feature to be off when no server key is configured and to use the sandbox unless explicitly told otherwise, so that nothing hits production by accident.
16. As an auditor, I want every attempt, notification outcome, recorded payment and exception in the activity log without any key material, so that I can trace the money.
17. As a customer of company B, I never see or pay company A's invoices, and a Midtrans notification can only ever credit the company that created the attempt.
18. As a developer, I want the payment recorded through `BillingService::recordPayment` only, so that invoice math lives in one place.

## Implementation Decisions

- Config: `config/services.php` `midtrans` block reading the four `.env` names; a gateway-enabled predicate (server key present); base URLs chosen by `is_production`; keys never logged or serialised.
- `MidtransGateway` (Billing module Services; plain `Http` client, timeout 10 s, no retries on create): `createSnapTransaction(attempt)` → token + redirect URL; `transactionStatus(orderId)`; `isValidSignature(payload)` using `hash('sha512', order_id.status_code.gross_amount.server_key)` and `hash_equals`.
- `PaymentAttempt` model (`BelongsToCompany`, immutable financial columns, status machine `pending → paid | failed | expired | exception`), migration per Q6; `PaymentAttemptService` owns create-or-reuse (one open attempt per invoice, whole rupiah, amount = `sisa`, expiry 24 h) and `applyNotification(payload)` shared by webhook and reconciliation.
- Settlement path (single code path): lock attempt row → if `payment_id` set, return → map status (Q9) → verify amount equals attempt amount → `CompanyService::runAs(attempt.company_id)` → `BillingService::recordPayment(invoice, amount, 'midtrans', order_id, notes)` → store `payment_id`, `settled_at` → audit. Any 422/abort from the service becomes attempt `exception` + audit, HTTP 200 to Midtrans.
- `BillingService::recordPayment` changes: payable-status guard (Q8), tolerate no authenticated user (`received_by` null). Migrations: `payments.received_by` nullable, `payments.method` ENUM gains `midtrans`.
- Webhook: `POST /webhooks/midtrans`, no auth, CSRF-exempt, throttled, JSON only; order: signature → find attempt by `order_id` → apply. Responses: bad/missing signature 403 (no body detail), unknown `order_id` 404, valid 200. No IP allow-list (Midtrans publishes ranges that change).
- Portal: routes under a `portal` prefix, middleware `auth`, `verified`, role `customer`; permissions `portal.invoice.view`, `portal.invoice.pay` seeded to the `customer` role (naming `{module}.{action}`); controllers resolve the customer from `customers.user_id = auth user` and the effective company; `InvoicePolicy`-style checks plus an explicit customer match; the pay endpoint is rate-limited (6/min/user). Customers are redirected to the portal at login instead of the admin shell.
- Staff: Show page gets a read-only "Online payments" panel (permission `billing.view`); no staff action can mark an attempt paid.
- Reconciliation: `midtrans:reconcile {--company=} {--dry-run}` per active company via `runAs`, scheduled every 15 minutes with `withoutOverlapping()`; uses the same `applyNotification` path with the Status API response (its `signature_key` is verified the same way).
- Audit log names `payment_attempt` and `invoice`; never log the server key, authorization header, or full raw payload at info level.
- Env gating: gateway disabled (portal hides Pay, endpoint 404) when the server key is empty.

## Testing Decisions

- Good tests assert externally visible results: HTTP status, invoice `status/paid_amount`, `payments` rows, attempt state, activity log; they build notifications with a signature helper using a **fake test key set in the test environment**, never the real one; Midtrans HTTP is faked with `Http::fake` using shapes copied from the Midtrans docs.
- Seams: `POST /webhooks/midtrans` (feature, highest seam), the portal pay endpoint, `midtrans:reconcile`, `BillingService::recordPayment` guards.
- Matrix: valid settlement; replay ×3 (1 payment); bad signature; missing fields; unknown order; amount mismatch; settlement for cancelled/paid invoice (exception, no payment); `capture` with `fraud_status=challenge` (no payment); pending/deny/expire; concurrency (two parallel notifications → one payment); partial balance changed by a manual payment between create and settle (exception); cross-company (notification for company A attempt cannot touch company B); customer cannot pay another customer's invoice; Pay hidden/404 with no server key; reconciliation settles a missed webhook and expires an old attempt; manual `recordPayment` on cancelled/draft now 422.
- Prior art: `tests/Feature/Billing/` (`MoneySafetyTest`, `InvoiceTenantScopeTest`, `CheckOverdueTest`, `TenantRecheckTest`), `tests/Feature/TenantBoundaryTest.php`, `tests/e2e/*billing*`.
- Sandbox evidence (QA ticket): one real sandbox payment end-to-end over a public tunnel (Midtrans cannot call localhost); screenshot/log excerpt without keys.
- Gates: `security-reviewer` APPROVED (rule 10), `composer audit`/`npm audit` pasted (rule 11; no new dependency under Q4b), scoped Pint/Larastan/ESLint, `npm run build`, full isolated PHPUnit before QA, shared `inbils_test` serialised.

## Out of Scope

- Production go-live, per-company merchant keys, partial online payments, installment/recurring/auto-debit, refunds and chargebacks (manual), card tokenisation, e-wallet deep links, snap.js embedding.
- Auto-reactivate/auto-suspend, WhatsApp/e-mail payment receipts, push notifications, Flutter customer app.
- A full reconciliation report page and accounting export.
- Fixing customer-role authorization for tickets beyond verifying it (Corrections) — report to the owner.

## Further Notes

- Ticket order: 01 → {02, 03} → 04 → 05 → 06 → 07 → 08. Tickets 02 and 03 both edit `BillingService`-adjacent code only through ticket 01's guard; they are otherwise independent.
- Cross-lane: `auth-2fa-passkeys` (customer enrolment) and `customer-status-rollup` (customer status after manual reactivation) are independent but complementary; do not seed `RolePermissionSeeder` for three lanes in parallel.
- Update `docs/ENTERPRISE_ISP_READINESS.md` (Q1), `docs/business/billing.md` (payment methods, attempt state machine, portal) and CHANGELOG when shipped. Deploy: `php artisan migrate`, `php artisan db:seed --class=RolePermissionSeeder --force`, set the four env values on the target host, configure the Midtrans dashboard "Payment Notification URL" to the public HTTPS `/webhooks/midtrans`.
