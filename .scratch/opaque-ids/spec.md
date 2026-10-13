# Opaque public ids in URLs and the API; uniform 404; enumeration detection

Status: ready-for-agent
Ledger: `docs/plans/INBILS_OMP_BACKLOG.md` → "ISP roadmap lanes (2026-10-12)" row `opaque-ids`
Source: owner request 2026-10-14 (grill-me): "pada id itu apakah bisa diubah jadi encrypt atau gimana biar tidak terjadi information disclosure ketika ada yang menebak-nebak atau nulis parameter manual susah". Owner approved K1–K4 as recommended (with the advisory tightening of K3/K4) and chose the widest scope ("di semua saja"). D1–D8 are the assistant's.

## Facts (2026-10-14, agent IdExposureFacts)

- ~150 web routes and 27 API routes bind business records by auto-increment id (`routes/admin.php:40-124`; module `web.php`/`api.php` files). No `HasUlids`/`HasUuids`/`getRouteKeyName` anywhere; no uuid/ulid column on business tables (only `media.uuid`, `notifications.id`).
- Authorization already holds: another company's id → 404 (web and API, `BelongsToCompany`, `TenancyMatrixTest`); users of another company → 404 (`UserController::ensureSameCompany`, `:168-171`); same-company record without permission → 403 (policies); API media turns 403 into 404. No unauthenticated endpoint takes an enumerable record id (webhooks signed, isolir by company code, reset/verify tokens).
- Leaks: sequential ids in every URL and API path/payload reveal volume and invite guessing; Midtrans `order_id` = `{companyId}-{invoiceId}-{12 random}` (`PaymentAttempt.php:86-89`) exposes both ids to the gateway; `Role` ids are global; no detection of id scanning (no 403/404 audit, admin GETs unthrottled).
- Mobile apps store integer ids (`inbils_core` models), so an API id change is a contract change.
- Human codes (`CUS-00001`, `INV-2026-00001`, …) stay visible to the owning company by design.

## Decisions

- **K1 Threats:** all three (no login, other company, same-company low permission). Authorization stays the control; opaque ids are a second layer against guessing and volume disclosure.
- **K2 ULID `public_id`** (Laravel `HasUlids` with `uniqueIds(): ['public_id']`, 26 chars, no new library) on every model that appears in a URL, a route parameter, an API path or an API payload id. Integer `id` stays the primary key and every foreign key. `getRouteKeyName(): 'public_id'`, so `route('…', $model)` and Ziggy links switch automatically; route parameters are constrained with `whereUlid`.
- **K3 Uniform record-level 404:** a record that does not exist, belongs to another company, or is hidden by its policy answers the same 404 (same body, same timing path). Page-level permission denials (`can:` middleware, "your role cannot open this page") stay 403 (they are pinned by `AdminMenuAuthorizationTest`, `PermissionServerTableTest`, the Sales KPI forbidden tests and the E2E permission specs). The API's one 403-vs-404 difference (another technician's work order, `TenancyMatrixTest.php:102-106`) becomes 404.
- **K4 Enumeration detection:** middleware counts record-level 404s from route-model binding misses and policy-hidden records per user (per IP when logged out) in a rolling window; above `security.enumeration.threshold` (default 30 in 5 minutes) it writes one `security.enumeration_suspected` audit entry (ids and route names only) and answers 429 for `security.enumeration.cooldown` (default 10 minutes). Permission 403s are not counted. Disabled when `config('security.enumeration.enabled')` is false, which is the default in `testing` and `e2e`; a dedicated test enables it.
- **D1 Scope ("di semua"):** every `BelongsToCompany` model plus `User`, `Role`, `Company` and media. List frozen in ticket 01 from a grep of route bindings, API resources and Inertia resources; anything bound in a route or emitted as an `id` to the browser or the apps is in.
- **D2 Outgoing ids:** every API and Inertia resource emits `id` = `public_id` (string). Integer ids never leave the server. Fields that reference another record (`customer_id`, `subscription_id`, …) in props and API payloads carry the referenced record's `public_id`.
- **D3 Incoming ids:** form requests and API requests accept public ids for every reference field; a shared rule `PublicIdExists(Model::class)` validates (company-scoped) and a request helper maps them to integer ids before the service layer, so services and queries stay on integer keys.
- **D4 Mobile transition:** the API switches to string ids in one release (OpenAPI version bump `v1.1`, `id` type `string`); the apps are updated in the same lane (Freezed models `int id` → `String id`, fixtures, tests) and released together. No dual-accept period: the apps are not on the Play Store yet (release tickets still open), so there is no installed base to break.
- **D5 Midtrans `order_id`:** `INB-{ULID}` (no company or invoice id); lookups go through `payment_attempts.order_id` as today; existing attempts keep their old ids.
- **D6 Existing links:** old integer URLs stop resolving (404). Notifications stored in the database that hold integer links are rewritten by the migration (`data->url`) where they point at a moved route; e-mailed/WhatsApp links never carried ids (fact). Users' bookmarks break once; acceptable, noted in CHANGELOG.
- **D7 Order:** after `ui-consistency` (its `SearchOptions` returns `value` = resource `id`, so pickers follow automatically) and after `field-trip-status` (so its new API endpoints are built once and converted here). It touches every page, so no other lane runs in parallel with tickets 02–04.
- **D8 Exports and URLs in CSV/PDF:** exports keep human codes, never ids (already the case per audit); no change unless the grep finds an id column.

## Testing decisions

- Unit: `PublicIdExists` rule (other company → fails, unknown → fails, integer → fails), request mapping helper.
- Feature (table-driven over every bound route, like the existing route matrix tests): integer id → 404; foreign public id → 404; hidden-by-policy record → 404 (same JSON/HTML body as unknown); page-level permission → 403 unchanged.
- Enumeration middleware: threshold, cooldown, 429, audit entry, counter ignores 403, disabled in testing by default.
- API: OpenAPI contract test with string ids; `TenancyMatrixTest` updated; technician-foreign work order now 404.
- Migration: backfill `public_id` for every row, unique index per table, NOT NULL after backfill; notification URL rewrite.
- E2E: full canonical suite green (helpers that build URLs from ids read them from props, never hardcode integers); a spec asserting that no `/\/\d+(\/|$)/` record path appears in links on the main list pages.
- Mobile: `flutter analyze` + `flutter test` in all three packages with string ids; integration journeys on the AVD.

## Tickets

01-backend-public-id-columns · 02-backend-binding-resources-requests · 03-backend-uniform-404-and-enumeration · 04-ui-links-and-forms · 05-mobile-string-ids · 06-qa-review-release

## Residual (stated to the owner)

ULIDs hide record counts in URLs and API ids only. Human codes stay visible to the owning company and still show volume where their template has a running counter (`CUS-00001`, `SUB-…`, yearly `INV-YYYY-NNNNN`); the owner can switch those templates on Penomoran (for example `{PREFIX}-{YYYY}{MM}-{SEQ:4}` with a monthly reset). Authorization, not id opacity, remains the control: cross-company ids already answer 404 and nothing is reachable without login.
