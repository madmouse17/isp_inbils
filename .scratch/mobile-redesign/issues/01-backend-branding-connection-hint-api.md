# BACKEND: company branding fields, connection status API, category hint

Status: done
Role: backend
Blocked by: sla-targets merged
Spec: `../spec.md`

## Scope (owned files)

- Migration on `companies`: `brand_color` char(7) nullable, `whatsapp` varchar(50) nullable, `support_hours` varchar(120) nullable; `Company` fillable; `UpdateCompanyProfileRequest` validation (`regex:/^#[0-9A-Fa-f]{6}$/`, phone pattern as `phone`, max lengths, Indonesian messages/attributes).
- Migration on `ticket_categories`: `customer_hint` text nullable (≤ 500 validated) in `UpdateTicketCategoryRequest`; exposed in the customer ticket-category API resource.
- Mobile API `me.company`: `logo_url` (signed or public URL as the web uses), `brand_color`, `phone`, `whatsapp`, `email`, `website`, `support_hours` (allow-list resource).
- `RadiusDirectory::lastSeen(string $username): ?string` (MAX(`acctstoptime`)) and `GET /api/v1/customer/subscriptions/{subscription}/connection` → `{ data: { status: 'online'|'offline', since: ?iso, last_seen_at: ?iso } }`, `data: null` when RADIUS is disabled or the subscription has no PPPoE username; ownership enforced (other customer/company → 404); throttled; never NAS IP, framed IP, session ids.
- Customer subscription resource: `suspension_reason` ∈ {`overdue`, `manual`, null} read from the existing `service_subscriptions.suspension_source` (set by `SubscriptionService::suspend($sub, $reason, $source)`; `OverdueSuspensionService` passes `overdue`), null unless status is `suspended`. The app shows the isolir hero and the auto-reactivation promise only for `overdue` (ticket 04 `heroStatusOf`).
- Customer invoice resource: `subscription_id` (nullable) when the invoice belongs to one subscription, so each hero shows its own bill.
- OpenAPI regenerated + contract test. Tests per spec (API part).

## Acceptance criteria

- [x] Focused PHPUnit green; Pint; OpenAPI contract test green.

## Comments

2026-10-14, MobileApiBackend. Done; nothing committed. Choices that sharpen the ticket text, then the contract tickets 02 to 04 build on, then evidence.

**Contract for ticket 02 (web UI)**

- `Admin/Company/Profile` props: `company.data.brand_color` (`#RRGGBB` or null; stored as typed, either case, so compare swatches case-insensitively), `company.data.whatsapp` (null or digits and `( ) + -`, same pattern as `phone`: no spaces), `company.data.support_hours` (null or text up to 120 characters). Existing keys are unchanged; `company.data.logo` stays the relative `/storage/...` path (the app uses `me.company.logo_url`).
- Submit with the same `post(route('admin.company.profile.update'))`, `_method: 'put'`, `forceFormData: true`; fields `brand_color`, `whatsapp`, `support_hours`. An empty string clears the field (it reaches the server as null), a key that is not sent leaves the stored value alone. Errors come back as `errors.brand_color` ("Warna merek harus berupa kode warna heksadesimal seperti #2563EB."), `errors.whatsapp` ("Nomor WhatsApp hanya boleh berisi angka dan karakter ( ) + -.") and `errors.support_hours` (max 120). The `Company` type in `resources/js/types` needs the three nullable fields.
- `Admin/Tickets/Categories/Index` props: `categories[].customer_hint` (string or null). Submit with the existing `put(route('admin.ticket-categories.update', id))` and the extra field `customer_hint` (at most 500 characters, counted as characters, not bytes; empty clears it, omitted keeps it). Error key `errors.customer_hint` (attribute name "saran untuk pelanggan").

**Contract for tickets 03 and 04 (apps)**

- `me.company` (also the login `user.company`) is `{id, name, logo_url, brand_color, phone, whatsapp, email, website, support_hours}`. Every key is always present and null until the ISP fills it in (explicit null, not omitted; `CompanyRef` reads both). `logo_url` is the absolute URL `APP_URL/storage/companies/{company}/company-logo/{media}/logo.webp` of the logo on the `public` disk. The web serves the logo publicly (it is the one media file that is public by design: `config/media-library.php`, `Company::registerMediaCollections`), so no authenticated media route was added and the plain `Image.network` in `CompanyLogo` loads it with no token. The URL changes whenever the logo is replaced (the media id is in the path), so the app can cache it. An SVG logo is served as `logo.svg`, which `Image.network` cannot draw: the monogram fallback shows, and ticket 02 may want to say so on the logo field. In production `APP_URL` must be the public https address, or the app receives `http://localhost/...`.
- `GET customer/subscriptions/{id}/connection` (route `api.v1.customer.subscriptions.connection`, `{id}` numeric like `subscriptions.show`) answers `{data: {status, since, last_seen_at}}`: `online` has `since` (start of the newest open, not stale session) and `last_seen_at: null`; `offline` has `since: null` and `last_seen_at` (end of the newest finished session, null for a subscriber that never connected). Times are ISO 8601 with `+00:00` (radacct holds plain datetimes; they are read as application time, UTC, the same assumption the 15-minute staleness check already makes). It answers `{data: null}` with 200 while `services.radius.enabled` is off, while the RADIUS database cannot be reached (kind `unavailable`, no 500 and no host, user or SQL text in the body) and when the subscription has no PPPoE network account: a hotspot-type account counts as none, because a hotspot user comes and goes with the device. Another customer's subscription (same company) and another company's are 404 `not_found` before RADIUS is asked; a technician token is 403 at the ability fence. Throttle is the shared `api` limiter (120 calls a minute per token), the same as every other customer endpoint; `ThrottleMatrixTest` now walks the route. The body is exactly those three keys: no NAS address, framed address or session id, in a key or a value (asserted).
- `customer/subscriptions` (list and detail) rows carry `suspension_reason` right after `status`: `overdue` or `manual` while `status` is `suspended`, else null. A suspended row written before `suspension_source` existed (2026-10-13) has no source and answers null: the app must treat null as "not overdue" and not promise automatic reconnection.
- `customer/invoices` (list and detail) rows carry `subscription_id` (int or null): the subscription the invoice bills, null for an invoice that belongs to no single subscription. `customer/ticket-categories` rows are `{id, name, customer_hint}` (hint null when the ISP wrote none).

**Shipped**

- Migrations `database/migrations/2026_10_18_100000_add_branding_columns_to_companies_table.php` (`whatsapp` varchar 50 after `phone`, `brand_color` char 7 after `website`, `support_hours` varchar 120) and `Modules/Ticketing/database/migrations/2026_10_18_100001_add_customer_hint_to_ticket_categories_table.php` (`customer_hint` text); both nullable, both with a `down()`. `migrate --pretend` SQL: `alter table companies add whatsapp varchar(50) null after phone`, `add brand_color char(7) null after website`, `add support_hours varchar(120) null after brand_color`; `alter table ticket_categories add customer_hint text null after default_priority`.
- `Company::$fillable`, `UpdateCompanyProfileRequest` (rules, messages, attributes), `CompanyResource` (now `@mixin Company`, which also clears its 11 old PHPStan errors); `TicketCategory`, `UpdateTicketCategoryRequest`, admin `TicketCategoryController::index` select, `Api\V1\TicketCategoryResource`.
- `MeResource` company block; `CustomerSubscriptionResource::suspension_reason`; `PortalInvoiceResource::subscription_id` (its docblock no longer says subscription links are hidden) and the `@response` of `InvoiceController::show`.
- `RadiusDirectory::lastSeen()`; the connection endpoint lives in the NetworkAsset module because it needs `NetworkAccount` and `RadiusDirectory`: `Modules/NetworkAsset/routes/api.php`, `RouteServiceProvider::mapApiRoutes()` (mapped like the Billing and Ticketing API routes) and `Http/Controllers/Api/V1/Customer/SubscriptionConnectionController.php`.
- `docs/api/mobile-v1.openapi.json` regenerated (`me.company` fields with descriptions, `suspension_reason` enum, `subscription_id`, `customer_hint`, the new path); `CHANGELOG.md` entry.
- Tests: new `SubscriptionConnectionTest` (8: online, offline with last seen, stale session is offline, never connected, switched off with zero RADIUS queries, unreachable database, no PPPoE or hotspot-only with zero queries, peer and stranger 404 with an owner control) and `MeCompanyTest` (3); `RadiusDirectoryTest` +2 and `lastSeen` in the switched-off walk; `CompanyProfileTest` +5 (save and page props, clear and keep, hex, WhatsApp pattern and 120 limit, Indonesian messages); `TicketCategoryAdminTest` +3; customer `TicketsTest` +1, `ProfileAndSubscriptionsTest` +1, `InvoicesTest` +1; `LoginTest` and `TokenLifecycleTest` expect the new `company` shape; the route matrices count 51 routes, 27 id routes and 21 GET routes and map the new id route to the owner's subscription.

**Evidence** (all PHPUnit under `flock /tmp/inbils-e2e.lock … sail exec -T -u sail laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact`, DB `inbils_test`)

- Red first: with the route mapping and `lastSeen` switched off, `RadiusDirectoryTest` failed 3 (`Call to undefined method RadiusDirectory::lastSeen()`) and `SubscriptionConnectionTest` failed 7 of 8 (404, no route). The 8th, the ownership test, passed vacuously on the missing route, so it now starts with an owner control (own subscription answers 200 `online`) before the foreign ones are refused. The red check was a temporary edit, reverted (no `RED-CHECK` left). The branding, hint, `suspension_reason` and `subscription_id` tests were written before their code but their first run was green-after-implementation, not red.
- Green: `tests/Feature/Api/V1 tests/Feature/CompanyProfileTest.php tests/Feature/Ticketing tests/Feature/NetworkAsset` → 634 passed (7433 assertions), including `OpenApiContractTest`, `AccessMatrixTest`, `TenancyMatrixTest`, `SecretKeyWalkTest`, `ThrottleMatrixTest`, `RadiusDirectoryTest` (18) and `SubscriptionConnectionTest`.
- `sail exec laravel.test php artisan scramble:export` against `inbils_test` → "exported … with 5 warnings" (the 5 known); the committed file equals a fresh export (`OpenApiContractTest` green).
- `vendor/bin/pint --test` on the 31 touched PHP files → PASS; `phpstan analyse` (level 5) on the touched source files → exit 0.
- `php artisan route:list --path=api/v1/customer/subscriptions -v` → `GET api/v1/customer/subscriptions/{id}/connection api.v1.customer.subscriptions.connection › Modules\NetworkAsset\…\SubscriptionConnectionController`, middleware `api`, `api.customer`.

**Not verified or open**

- Real FreeRADIUS and a real MySQL `radacct` were not exercised (the repo's SQLite stand-in is). If production's MySQL session time zone is not UTC, `since` and `last_seen_at` shift by the offset, and so does the existing 15-minute staleness check; confirm on the lab stack before ticket 07's device run.
- `last_seen_at` is `MAX(acctstoptime)` as the ticket says: a session that ended without an Accounting-Stop (a NAS reboot) does not count as "seen", so the time can be older than the real last contact.
- Each call reads RADIUS up to twice and nothing is cached; the shared 120-a-minute limiter is the only guard.
- The Flutter apps were not run against the new payloads (read-only for me); `CompanyRef` and `SubscriptionConnection` already match the field names.
- needs-change (not mine): the demo seeder has no brand colour, WhatsApp, support hours or category hints, so ticket 07's device screenshots need them set (web form from ticket 02, or the demo seeder).
