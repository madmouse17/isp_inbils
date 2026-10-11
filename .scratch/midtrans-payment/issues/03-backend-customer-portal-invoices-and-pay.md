# BACKEND: customer portal backend — own invoices, pay endpoint, scoped authorization

Status: needs-owner-approval
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

- [ ] A customer lists and opens only their own invoices; another customer's invoice id returns 404 (never 403 with data); a user of company B cannot reach company A invoices.
- [ ] Pay on a payable invoice returns a redirect URL (fake gateway); a second call returns the same open attempt; cancelled/paid/non-integer-rupiah invoices return 422 with a clear message.
- [ ] A staff role without `customer` role gets 403 on portal routes; a customer gets 403 on `admin/*` billing routes.
- [ ] Customers with `must_change_password` are redirected to change it before any portal route works; after changing it they proceed.
- [ ] No payload contains `snap_token`, server/client keys, or other customers' data (asserted on Inertia props).
- [ ] Test (or documented finding) for the ticket-visibility question is delivered with the result.
- [ ] Pint and scoped Larastan clean.
