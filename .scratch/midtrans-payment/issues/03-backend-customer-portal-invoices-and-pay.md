# BACKEND: customer portal backend — own invoices, pay endpoint, scoped authorization

Status: done
Role: backend
Blocked by: 01
Card: none (new lane; no Hermes card)

## Scope

Implements spec Q2a/Q3.

- Verify first (and report to the owner): whether a `customer`-role user can today open other customers' tickets (`Modules/Ticketing/app/Policies/TicketPolicy.php:16-31` suggests yes). Write a failing test for it; do not fix tickets in this ticket unless the owner approves.
- Portal routes under `portal` prefix, middleware `auth`, `verified`, role `customer`: `GET portal/invoices`, `GET portal/invoices/{invoice}`, `POST portal/invoices/{invoice}/pay` (creates/reuses an attempt via `PaymentAttemptService`, returns the Midtrans redirect URL), `GET portal/payments/return` (finish page, reads state from the server, never trusts query params).
- Permissions `portal.invoice.view`, `portal.invoice.pay` seeded to the `customer` role only (`RolePermissionSeeder`; update `RolePermissionSeederTest`).
- Authorization: `InvoicePolicy`-style checks plus explicit match `invoice.customer_id` = the customer whose `user_id` is the authenticated user, same effective company, unpayable statuses refused; pay endpoint rate-limited (6/min/user); Pay returns 404 when the gateway is not configured.
- Resources expose only customer-safe invoice fields (no internal notes, no other customers, no attempt secrets/snap token).
- Q3 baseline: a `must_change_password` flag set on customer-created users and on existing customer users by migration; portal routes require it cleared (redirect to the existing password-update page).
- Login redirect: users whose only role is `customer` land on the portal instead of the admin shell (update `LoginRedirectTest`).

Non-goals: React pages (ticket 05), staff panel, webhook, reconciliation, fixing ticket authorization.

## Acceptance criteria

- [x] A customer lists and opens only their own invoices; another customer's invoice id returns 404 (never 403 with data); a user of company B cannot reach company A invoices.
- [x] Pay on a payable invoice returns a redirect URL (Inertia 409 + `X-Inertia-Location`, plain 302); a second call returns the same open attempt; cancelled/paid/non-integer-rupiah invoices return 422 with a clear message.
- [x] A staff role without `customer` role gets 403 on portal routes; a customer gets 403 on `admin/*` billing routes.
- [x] Customers with `must_change_password` are redirected to change it before any portal invoice route works; after changing it they proceed.
- [x] No payload contains `snap_token`, server/client keys, or other customers' data (asserted on Inertia props).
- [x] Ticket-visibility finding: confirmed real and already fixed by 8419804 (`TicketPolicy` customer scoping); covered by `tests/Feature/Ticketing/TicketCustomerScopeTest.php`, re-run green with this lane.
- [x] Pint clean; Larastan clean on new files.

## Delivered / deviations

- Security review MTP-01..04 (BLOCKED, then fixed red-first): the portal no longer changes passwords. A flagged legacy login is opened only by a completed password reset from the emailed link: `ClearLegacyPasswordGate` (on `PasswordReset`) clears the flag, rotates `remember_token` and ends the account's database sessions. `customers:rotate-legacy-passwords {--dry-run}` (deploy step) gives every flagged login a random password and queues the link; `portal.password.edit` only explains and offers `portal.password.link` (3/min). The gateway is off on `APP_ENV=production` with sandbox keys unless `MIDTRANS_ALLOW_SANDBOX_IN_PRODUCTION=true`; `MIDTRANS_BASE_URL` is honoured only in local/testing/e2e; the webhook throttle counts only unsigned posts (30/min per IP), so signed notifications are never 429.
- NOT applied, handed to the integration owner: login redirect of customer-only users to the portal (`AuthenticatedSessionController` is not in this lane; it also changes the landing page that `loginAs(page, 'customer')` expects in five E2E specs). Exact diff is in the lane report.
