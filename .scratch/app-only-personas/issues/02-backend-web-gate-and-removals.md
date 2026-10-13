# BACKEND: app-only middleware, settings keys, portal route removals

Status: done
Role: backend
Blocked by: none
Spec: `../spec.md`

## Scope (owned files)

- New `app/Http/Middleware/RedirectAppOnlyRoles.php` + registration in `bootstrap/app.php` (alias) and the `admin` + `portal` route groups (`routes/admin.php`, `Modules/Billing/routes/web.php`); new `app/Http/Controllers/AppOnlyController.php` + route `GET /app-only` (auth, verified) rendering Inertia `AppOnly` with props `{role: 'technician'|'customer', appName, playStoreUrl: string|null}`.
- `database/seeders/SystemSettingSeeder.php` (or the existing settings seeder): keys `play_store_url_technician`, `play_store_url_customer` (empty defaults). Check the System Settings page/controller lists keys generically; if it does, nothing else; if it is an allow-list, add the two keys there.
- Delete portal invoice/password controllers/requests/routes (`Modules/Billing/app/Http/Controllers/Portal/{InvoiceController,PasswordController}.php`, `EnsurePortalPasswordChanged` if only used there, `tests/Feature/.../PortalInvoiceTest.php`), keep `PaymentReturnController` + `pembayaran/selesai`. Grep for `route('portal.invoices` / `portal.password` usages (notifications, mails, `CustomerService::sendAccessLink`) and repoint: mails that linked to the portal now say to open the app and link to the password reset page instead.
- `HandleInertiaRequests`: no change needed unless `canMyWork` must be false for app-only users (verify `MyWorkQuery::isEligible` returns false when the user never reaches the admin pages — it does not matter because the middleware redirects first).
- Tests: `tests/Feature/AppOnlyRedirectTest.php` (technician-only → 302 `/app-only` on `/admin/dashboard`, `/admin/my-work`, `/admin/tickets`; customer-only → 302 on `/admin/*` and `/portal/*`; technician+staff → 200 dashboard; admin → 200; `/app-only` for an admin → 302 `/admin/dashboard`; guest → login), removed routes 404, Midtrans pages still 200.

## Acceptance criteria

- [x] Tests above green; full `tests/Feature/Billing` + `tests/Feature/Auth` focused run green; Pint clean.

## Comments

- 2026-10-13 BeGate. Shipped: middleware `RedirectAppOnlyRoles` (alias `redirect.app.only`, `isAppOnly(User)` = roles non-empty and all in technician/customer) on every `admin` route group (routes/admin.php + Billing, Inventory, Ipam, NetworkAsset x2, Reporting, SPK, Service, Ticketing); `AppOnlyController` + `GET /app-only` (`app-only`, auth+verified) rendering `AppOnly` `{role, appName: 'Inbils Teknisi'|'Inbils Pelanggan', playStoreUrl|null}`; shared Inertia prop `appOnly: bool` (top-level) at the orchestrator's request; `SystemSettingSeeder` seeds `play_store_url_technician|customer` ('' , `firstOrCreate` so a re-seed keeps owner edits). No System Settings page exists (settings are DB/seeder only, `SettingService`), so the keys are seeded only.
- Deleted: `Portal/InvoiceController`, `Portal/PasswordController`, `EnsurePortalPasswordChanged`, `PortalInvoiceTest`; routes `portal/invoices*`, `portal/password*`. Kept: `PaymentReturnController`, `portal/payments/return`, `pembayaran/selesai`.
- Decision (deviation from spec text): `portal/payments/return` stays OUTSIDE `redirect.app.only` (still `EnsureCustomerAccess` + `can:portal.invoice.view`), so a customer-only login returning from Midtrans still sees its attempt (ticket: "Midtrans pages still 200"). `/portal/invoices*` and `/portal/password*` now 404 for everyone.
- Repointed: `InvoiceIssuedNotification`/`InvoiceDueReminderNotification` WhatsApp 5th param now `InvoiceWhatsAppNotification::appUrl()` = `play_store_url_customer` or `route('login')` (template/params count unchanged, no Meta re-approval); `CustomerAccountCreated` mail says to open the Inbils Pelanggan app after setting the password (link stays the password reset page); docs/business/billing.md updated.
- New tests: `tests/Feature/AppOnlyRedirectTest.php` (11 cases incl. a route sweep asserting every `admin/*` route carries the alias), `tests/Feature/Billing/PortalPaymentReturnTest.php` (return page cases kept from the deleted test + 404s), WhatsApp link with the setting. Adjusted: `PortalCredentialsTest`, API `InvoicesTest` (portal parity assertion removed), WhatsApp tests (`route('login')`), `PaymentReversalTest` + `InvoiceOnlinePaymentsPanelTest` (technician now redirects).
- New `tests/Traits/WithoutAppOnlyRedirect.php` (switches the redirect off via `withoutMiddleware`) added to 30 test classes that exercise technician/customer web permissions and scopes (Admin approvals/dispatch/my-work/2FA reset, Inventory, Ipam, Odn, Reporting, SPK, Ticketing, PrivateMedia, P0, SalesAttribution visibility, API Technician MyWork).
- Evidence: full `tests/Feature tests/Unit` run before the trait sweep: 119 failed / 2854 passed, all 119 from app-only redirects (listed in run output); after the sweep, the 30 repaired classes + AppOnlyRedirectTest: `1236 passed (15205 assertions)`; final `tests/Feature/Billing tests/Feature/Auth AdminMenuAuthorizationTest DashboardTest ProfileTest Api/V1/Customer`: `498 passed (4251 assertions)`; `route:list --path=portal` → only `portal/payments/return`; `route:list --path=app-only` → `GET|HEAD app-only`; `pint --dirty --test` → passed.
