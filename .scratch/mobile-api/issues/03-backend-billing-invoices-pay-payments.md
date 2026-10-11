# BACKEND: customer invoices, pay, payment history, public payment-finished page

Status: done
Role: backend (plus one static Inertia page)
Blocked by: 01
Card: none (new lane; no Hermes card)
Skills: laravel-best-practices, tdd, web-security-review, ponytail, caveman; for the page: inertia-react-development, tailwindcss-development, antislop-ui, antislop-human

## Scope

Implements the invoice, pay and payment rows of the spec contract, plus Q19.

- `Modules/Billing/routes/api.php` (NEW), mapped by the Billing `RouteServiceProvider` under `api/v1` with names `api.v1.*` and the `api` group. Routes use `api.customer`.
- `GET customer/invoices` with `status=unpaid|paid|all`:
  - Base query = the portal query (`whereBelongsTo(customer)`, visible statuses).
  - `unpaid` = `sent|partial|overdue`, ordered by `due_date` asc, then id.
  - `paid` and `all` are ordered by `issue_date` desc, then id.
  - `paginate` (default 20, max 50); items are `PortalInvoiceResource` scalars.
- Move the visible-status list out of the portal controller (`Modules/Billing/app/Http/Controllers/Portal/InvoiceController.php:24`) to a constant on `Invoice`, used by portal and API. This is a clean cutover.
- `GET customer/invoices/{id}`: the same loading as the portal `show` (items, non-reversed payments, `:44-47`) plus `payment {enabled, can_pay, blocked_reason}` computed exactly like `:52-56`. Another customer's id or a draft is 404, never 403.
- `POST customer/invoices/{id}/pay`:
  - Middleware: `can:portal.invoice.pay` and `throttle:api-pay`.
  - Gateway off → 404 `payment_unavailable`. `PaymentAttemptService::unpayableReason` not null → 422 `invoice_not_payable` with that reason as the message.
  - Otherwise `PaymentAttemptService::openFor($invoice, route('payments.finished'))` → 200 `{data: {redirect_url, expires_at}}`.
  - Never `snap_token`, `order_id` or the raw notification.
  - Snap failures keep the status the service gives them today.
- `GET customer/payments`: the customer's non-reversed payments on visible invoices, newest first, through a NEW allow-list resource `{id, amount, method, paid_at, invoice:{id, number}}`. Never `PaymentResource`, which has `received_by` and `notes`.
- Q19: a public route `GET /pembayaran/selesai` (name `payments.finished`) in `Modules/Billing/routes/web.php`, outside the portal group, with no auth and no data. It renders a NEW static Inertia page `Portal/Payments/Finished` (next to the existing `Portal/Payments/Return.tsx`) on `GuestLayout` ("Pembayaran sedang diproses. Silakan kembali ke aplikasi Inbils untuk melihat status tagihan."). It reads no query parameter.

Non-goals: changing `PaymentAttemptService` or `MidtransGateway`, reconciliation, App Links, partial payments.

## Owned files

- NEW `Modules/Billing/routes/api.php`; `Modules/Billing/app/Providers/RouteServiceProvider.php`; `Modules/Billing/routes/web.php` (finish route only; after 01)
- `Modules/Billing/app/Models/Invoice.php` (visible-status constant), `Modules/Billing/app/Http/Controllers/Portal/InvoiceController.php` (use the constant)
- NEW `Modules/Billing/app/Http/Controllers/Api/V1/Customer/` (invoices, pay, payments), NEW `Modules/Billing/app/Http/Resources/Api/V1/CustomerPaymentResource.php`
- NEW `resources/js/Pages/Portal/Payments/Finished.tsx`
- NEW `tests/Feature/Api/V1/Customer/InvoicesTest.php`, `PayTest.php`, `PaymentsTest.php`, NEW `tests/Feature/Billing/PaymentFinishedPageTest.php`

## Acceptance criteria

- [x] The list returns only the token customer's visible invoices. `unpaid` contains only `sent|partial|overdue`, sorted by due date ascending, and `meta.total` matches the database count. Drafts never appear.
- [x] The detail matches the portal `show` for the same invoice (fields, items, non-reversed payments, `payment` block). Another customer's invoice and another company's invoice are 404.
- [x] Pay with the gateway on (`Http::fake` with the Snap shape from `tests/Feature/Billing/MidtransGatewayTest.php`) returns `redirect_url`. A second call with a new key returns the same open attempt. The same `Idempotency-Key` replays the identical body. The finish URL sent to Snap is `route('payments.finished')`.
- [x] With no server key, pay is 404 `payment_unavailable` and `payment.enabled` is false. Paid, cancelled and non-integer-rupiah invoices give 422 `invoice_not_payable` with the service's reason. A seventh pay within a minute is 429. A user without `portal.invoice.pay` is 403.
- [x] A recursive key check over every response finds no `snap_token`, `order_id`, `last_notification`, `received_by` or `notes`.
- [x] `GET /pembayaran/selesai` returns 200 without login, renders `Portal/Payments/Finished` with no props, and ignores query parameters.
- [x] The existing portal and Midtrans tests stay green (`tests/Feature/Billing/PortalInvoiceTest.php`, `PaymentAttemptServiceTest.php`, `MidtransWebhookTest.php`).
- [x] Pint, Larastan (changed files), ESLint and Prettier (the page) are clean, and `npm run build` succeeds.

## Comments

### 2026-10-11: done (backend agent MobileApiBilling)

Implemented Billing mobile API endpoints for customer invoices, invoice pay, payment history, and the public app payment-finished page. Final owned endpoint tests: 40 passed (333 assertions). Portal/Midtrans regression suites: 87 passed (578 assertions). Pint on owned files passed, Larastan on changed Billing PHP files passed, ESLint/Prettier on `Finished.tsx` passed, and `npm run build` succeeded.
