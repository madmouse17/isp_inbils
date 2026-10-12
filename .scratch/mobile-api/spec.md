# Mobile API v1 — Sanctum token API for the Inbils customer and technician apps

Status: done (tickets 01-09 done; release waits on a public HTTPS host, see the Flutter lanes)
Ledger: none yet (new lane; owner request 2026-10-11: two Android Flutter apps, built after the current web work)
Source: owner request 2026-10-11; earlier draft plan `docs/MOBILE_TASK_PLAN.md` (2026-07-14). That file is local only: `docs/*` is git-ignored (`.gitignore:36`) and it is not on the allowlist (`.gitignore:40-100`).
Consumers: `.scratch/flutter-customer/` and `.scratch/flutter-technician/`. Both lanes are blocked by this one (order in Further Notes).

> Nothing starts before the owner answers the decisions below. "setuju rekomendasi" approves the recommended option of every item. No token, key, keystore or service-account value may appear in any file, test, log or ticket comment. Line numbers were read on 2026-10-11. The WhatsApp, MikroTik and IPAM lanes are editing some of the same files (`bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `database/seeders/RolePermissionSeeder.php`, module providers), so re-locate every citation by symbol before editing.

Approved by owner 2026-10-13: recommended option of every decision ("setuju rekomendasi"); no push in v1.

## Owner decisions

1. **Q1 — Scope conflict with the readiness doc.** The readiness doc excludes the customer mobile app (`docs/ENTERPRISE_ISP_READINESS.md:7`, `:277`) and any "public/partner API beyond an approved consumer contract" (`:282`). It also asked to switch off the old module `/api/v1` scaffold (`:200-209`, `:261`). That scaffold is gone: no `Modules/*/routes/api.php` exists, every module provider maps only `web.php` (for example `Modules/SPK/app/Providers/RouteServiceProvider.php:26,36`), and `bootstrap/app.php:18-27` registers no `api` routes.
   (a) Approve a first-party API v1 with exactly two consumers, the customer app and the technician app, as the "approved consumer contract". Amend the readiness doc. (b) Keep the mobile apps excluded and close the three lanes.
   **Recommend (a).** The owner asked for both apps. Tradeoff: the API becomes production surface and needs the security review in ticket 09 and a public HTTPS host before any release.

2. **Q2 — Where the API code lives.** ADR-001 keeps domain logic in modules and controllers thin (`docs/architecture/decisions/ADR-001.md:5`). Core must not depend on modules (`docs/architecture/domain-boundaries.md`, "Forbidden: Core → Module"). The customer portal already puts customer-facing controllers inside Billing (`Modules/Billing/routes/web.php:27-38`).
   (a) Core endpoints (auth, me, devices, notifications, media, customer profile and subscriptions, technician "my work") live in `routes/api.php` with controllers under `app/`. Module endpoints live in each module's own `routes/api.php` (Billing, Ticketing, SPK, Inventory). All of them share the `/api/v1` prefix and middleware groups defined once in `bootstrap/app.php`. (b) One new `Modules/MobileApi` module that calls every other module's services. (c) Everything under `app/Http/Controllers/Api`, which breaks the Core → Module rule.
   **Recommend (a).** It respects ADR-001 and lets the module tickets run in parallel, one owner per module. Tradeoff: the `v1` prefix appears in five route registrations, so a route-list test asserts that every `api/*` URI starts with `api/v1/`.

3. **Q3 — Token model and abilities.** Sanctum v4.3.2 is installed (`composer.json:16`, `composer.lock:1832-1833`). It is not set up: no `config/sanctum.php`, no `personal_access_tokens` migration, and `User` lacks `HasApiTokens` (`app/Models/User.php:32`).
   (a) One personal access token per app install. The token is named after the device (`device_name` from the client, at most 100 characters) and carries exactly one ability, `customer` or `technician`, picked by the `app` field at login. Route groups require the ability. Fine-grained rights stay with the Spatie roles and the existing policies. (b) Fine-grained abilities that mirror permissions (`customer:invoices.pay` and so on). (c) No abilities.
   **Recommend (a).** Sanctum has no wildcard sub-abilities: `tokenCan()` matches a literal string or `*`, so `customer:*` would just be a literal string. Plain `customer`/`technician` avoid that confusion. Tradeoff: abilities only fence the app surface. A user who is both customer and technician holds two tokens, one per app.

4. **Q4 — Token lifetime and refresh.**
   (a) Each token gets an absolute expiry: 90 days for customer tokens, 30 days for technician tokens. `POST auth/token/refresh` swaps the current token for a new one with the same name and ability, and deletes the old one in the same transaction. The app refreshes when fewer than 7 days remain, and any 401 means "log in again". `sanctum:prune-expired` runs daily. (b) Tokens never expire until logout or revocation. (c) Short-lived 24-hour tokens plus refresh.
   **Recommend (a).** Tradeoff: a stolen token works until it expires unless one of the Q6 triggers revokes it. No OAuth refresh tokens and no Passport.

5. **Q5 — Who may sign in to which app.**
   (a) The customer app uses the same rule as the web portal: role `customer` plus a `customers.user_id` link (`Modules/Billing/app/Http/Middleware/EnsureCustomerPortalAccess.php:21-29`). The technician app uses the same rule as the web "My work" page, `MyWorkQuery::isEligible()` (`app/Queries/Admin/MyWorkQuery.php:18-35`): `spk.start|spk.submit` or `ticket.start|resolve|close` plus an active employee profile in the company (`:280`). Both apps also require an active user, a verified e-mail and a company, the same gates the web applies (`routes/admin.php:30`, `Modules/Billing/routes/web.php:28`). The rules are checked at login and again on every request. A failure returns 403 with the code `app_not_allowed`. (b) New permissions `api.customer.access` and `api.technician.access` seeded to roles.
   **Recommend (a).** It adds no RBAC and needs no seeder run, and each surface follows one rule shared with the web. Tradeoff: granting web My Work grants the technician app. FYI: deactivating a *customer* does not deactivate its user (`app/Http/Controllers/Admin/CustomerController.php:188-196`, `app/Services/Core/CustomerStatusRecalculator.php:39-51`; `users.is_active` is set once at `app/Services/Core/CustomerService.php:26`). Inactive or terminated customers keep portal access, and with (a) app access too, so they can still see and pay old invoices.

6. **Q6 — When tokens are revoked.** Deactivation flips `users.is_active` through `UserController::update` (`app/Http/Controllers/Admin/UserController.php:110-127`), and no code checks `is_active` per request. The only checks are at login (`app/Http/Requests/Auth/LoginRequest.php:53`) and in the 2FA challenge (`app/Services/Core/TwoFactorService.php:205-219`). A password reset fires `PasswordReset` (`app/Http/Controllers/Auth/NewPasswordController.php:54`). Enabling 2FA, disabling it, regenerating recovery codes and the admin 2FA reset all call `TwoFactorService::endOtherLogins` (`:236`), which today ends only sessions and remember-me cookies.
   (a) Revoke all of a user's tokens when the password changes by any path (reset, profile, admin edit), when `is_active` becomes false, when the user is deleted, and whenever `endOtherLogins` runs. The app itself can revoke too: logout ends the current token, and the device list can revoke any one of the user's own tokens. A per-request guard also rejects an inactive user with 401 `account_inactive` (and deletes that token), and an ineligible user with 403 `app_not_allowed`. (b) Revoke on logout only.
   **Recommend (a).** Tradeoff: changing the password on the web logs the phone out, which is intended. The `is_active` and password hooks sit on the `User` model, so every writer is covered. The remember-token column is deliberately *not* used as a signal, because every web logout rotates it.

7. **Q7 — Second factor at token issuance.** Any user can enrol in 2FA (`routes/auth.php:65-73`, `User::hasTwoFactorEnabled()` `app/Models/User.php:110-113`), customers included (auth-2fa-passkeys lane, Q7). The web keeps challenge state in the session (`TwoFactorService::startChallenge` `:189`). `verifyChallenge(User, string)` (`:117`) is stateless and handles TOTP replay and recovery codes. The 2FA lane required every future token issuer to call this same service (`.scratch/auth-2fa-passkeys/spec.md`, Q4).
   (a) Two-step login for every user with confirmed 2FA. Step one, `POST auth/login`, answers `202` with `two_factor_required: true`, a `challenge_id` and `expires_in: 300`. The challenge lives server-side in the cache (user, app, device name, attempt count) and is never a token. Step two, `POST auth/two-factor` with `challenge_id` and `code` (TOTP or recovery code), calls `verifyChallenge`. Limits and limiter keys are shared with the web challenge (`app/Http/Requests/Auth/TwoFactorChallengeRequest.php:16-23,64-93`: 5 per 60 s per IP+user, 10 per 15 min per user). The challenge dies after success, after 5 wrong codes, or after 5 minutes. (b) Challenge only staff.
   **Recommend (a).** Under (b) the app would let customers who enrolled skip their second factor. Managing 2FA (enrol, disable, recovery codes) stays on the web profile.

8. **Q8 — Legacy customer passwords.** Flagged legacy logins (`must_change_password`, `database/migrations/2026_10_12_600003_add_must_change_password_to_users_table.php:12`) are kept out of the portal until a completed reset (`Modules/Billing/app/Http/Middleware/EnsurePortalPasswordChanged.php:18-21`, `Modules/Billing/app/Services/PortalCredentials.php:17-37`).
   (a) API login refuses them with 403 `password_reset_required`. The app offers "Kirim link atur ulang kata sandi", which calls `POST auth/forgot-password`, and the reset itself happens on the existing web page reached from the e-mail link. (b) Issue tokens anyway.
   **Recommend (a).**

9. **Q9 — Company context for token requests.** `CompanyService::idFor` returns the session-switched company or `users.company_id` (`app/Services/Core/CompanyService.php:50-53`). A token request has no session, so it always gets the home company. `BelongsToCompany` fails closed when there is no company (`app/Traits/BelongsToCompany.php:19,30`).
   (a) Home company only (`users.company_id`). A technician who serves several companies switches on the web. Users without a company are refused at login with `app_not_allowed`. (b) Bind a company to each token at login: an extra column plus a membership check. (c) An `X-Company-Id` header per request, validated with `canActIn`.
   **Recommend (a).** It needs no change to `CompanyService`. Every customer has exactly one company, and almost every technician does too. Tradeoff: a technician serving two companies sees only the home company's work in the app.

10. **Q10 — Response format, error codes, pagination, message language.** No API envelope exists. `bootstrap/app.php:48-69` renders 409 as `{message, error}` and `InsufficientStockException` as JSON only under `admin/*`, and nothing forces JSON for API requests. There is no `lang/` directory (Laravel's English vendor strings apply). The compose env sets `APP_LOCALE=id` (`.env.docker.example:9`), the default env sets `en` (`.env.example:7`). Domain messages are mixed: `PaymentAttemptService::unpayableReason` answers in Indonesian (`Modules/Billing/app/Services/PaymentAttemptService.php:48-55`), while `SpkService` says 'Evidence required before submission.' (`Modules/SPK/app/Services/SpkService.php:166`).
    (a) Use Laravel's native shapes: one resource is `{data}` and a page is `{data, links, meta}` from `paginate()` (default 20, max 50). Every error is `{message, code, errors?}`. `code` is stable snake_case: per-status defaults (`unauthenticated`, `forbidden`, `not_found`, `conflict`, `validation_failed`, `unprocessable`, `too_many_requests`, `server_error`) plus the domain codes named in this spec. Every `api/*` request gets JSON, whatever its `Accept` header, and no exception text leaks when `APP_DEBUG=false`. Framework messages stay English with no `lang/id` files: the app shows its own Indonesian text for every known `code` and validates forms client-side. (b) A custom `{success, data, error}` wrapper on every response. (c) Same as (a), plus `lang/id/*.php` and an `Accept-Language` middleware for Indonesian server messages.
    **Recommend (a).** It adds no wrapper code and works with Laravel resources, pagination and the OpenAPI generator unchanged. Tradeoff: a rare server-only validation message shows in English. Under (c), any `lang/id` file also translates the web wherever `APP_LOCALE=id`, and that is a web change.

11. **Q11 — Idempotency for mutations.** Idempotency exists today only for material declarations (`Modules/Inventory/app/Http/Requests/DeclareMaterialReturnRequest.php:20`, a string of at most 64 characters; `Modules/Inventory/app/Services/MaterialReturnService.php:194-212`; unique `(company_id, idempotency_key)`) and for stock references (`Modules/Inventory/app/Services/StockService.php:601-609`). SPK start and submit, ticket create and comment, and evidence upload have none.
    (a) Every non-auth `POST/PUT/PATCH/DELETE` under `/api/v1` requires an `Idempotency-Key` header (a UUID, 36 characters). The server stores user + key → request fingerprint + response status and body in the cache (Redis) for 24 h. A replay gets the stored response. The same key with a different body gets 422 `idempotency_conflict`. A concurrent duplicate gets 409 `idempotency_in_progress`. A missing key gets 428 `idempotency_key_required`. 5xx responses are not stored, so the client may retry them with the same key. Two endpoints keep a permanent database-level guarantee on top: the material declaration passes the key on as its existing `idempotency_key`, and evidence stores the key on the media and deduplicates per SPK, so a retry after the 24 h window still cannot duplicate a photo. (b) Per-endpoint client UUIDs, for evidence only. (c) None.
    **Recommend (a).** Tradeoff: one middleware and some Redis keys. The apps must reuse the key when they retry the same action (specified in the Flutter lanes).

12. **Q12 — Evidence upload contract, and an existing authorization gap.** On the web, uploading evidence checks only same company + `spk.evidence.upload` (`Modules/SPK/app/Http/Controllers/WorkOrderController.php:350-353`), and removing it checks the same (`:371-376`). Any technician of the company can therefore add or delete evidence on any SPK in any status, including SPKs that are not theirs and completed SPKs. **[INFERENCE from code, not executed.]** The storage logic sits in the controller (`:355-366`), not in a service. Rules: at most 10 MB, `jpg,jpeg,png,pdf,doc,docx`, caption at most 255 characters (`:355-358`). Files go to the private disk (`config/media-library.php:39`) and download through `MediaController::show` (`app/Http/Controllers/Admin/MediaController.php:22-46`).
    (a) Move evidence add and remove into `SpkService`, guarded by a new `WorkOrderPolicy` rule. The assigned technician (same check as `isAssignedTechnician`, `Modules/SPK/app/Policies/WorkOrderPolicy.php:113`) may upload while the SPK is `in_progress`. Holders of `spk.update` may upload until the SPK is `completed` or `cancelled`. Only the uploader removes, and only while `in_progress`. Web and API both call this service. The API upload is multipart, one file per request, with the same file rules plus an optional `captured_at` (client time). (b) Apply the strict rule only to the API and leave the web unchanged.
    **Recommend (a).** It fixes the gap once. Tradeoff: web behaviour changes, because a technician who is not assigned can no longer upload; this is pinned by tests. Ask the security reviewer to confirm the gap first.

13. **Q13 — What "add items" means for a technician.** Technicians cannot add SPK items: `StoreWorkOrderItemRequest::authorize` needs `spk.update` (`Modules/SPK/app/Http/Requests/StoreWorkOrderItemRequest.php:14-17`), and the technician role lacks it (`database/seeders/RolePermissionSeeder.php:284-298`). Materials reach a technician through an Inventory Request that the warehouse issues (`InventoryRequestService::issue`, `Modules/Inventory/app/Services/InventoryRequestService.php:410`; self-issue is forbidden at `:421`). Issuing opens a Material Return case (`MaterialReturnService::createForIssuedRequest`, `:113-172`). The technician then declares used and returned quantities (`declareUsage` `:182`, idempotent `:194-212`), and submitting the SPK is refused until every case is declared (`SpkService.php:170`, `:324`).
    (a) The app's "materials" step is the usage declaration on the SPK's material return cases. It runs on the existing service, is idempotent and needs no new permission. (b) Give technicians `spk.update`, which also lets them edit SPK fields and breaks separation of duties. (c) A new, narrow `spk.item.add`, which brings new stock semantics.
    **Recommend (a).**

14. **Q14 — Stock pickup in v1.** Technicians hold `inventory.request.create`, `.view.own` and `.cancel.own` (`database/seeders/RolePermissionSeeder.php:290`). The warehouse moves a request through `approved_reserved → preparing → ready_for_pickup → issued` (`Modules/Inventory/app/Policies/InventoryRequestPolicy.php:89-176`). No step lets the technician confirm a pickup.
    (a) A read-only "Permintaan barang saya" list with status (including `ready_for_pickup`), plus an in-app notice when a request is ready (Q18). Creating requests stays on the web. (b) Also create, submit and cancel requests in the app. (c) Nothing.
    **Recommend (a).** The field steps (go pick up, declare usage) are covered. Creating a request needs product and stock-location pickers, so it waits for v2 after the pilot. Tradeoff: technicians still open the web to create a request.

15. **Q15 — Customer ticket attachments.** The customer role lacks `ticket.attachment.upload` (`database/seeders/RolePermissionSeeder.php:300-305`, checked at `Modules/Ticketing/app/Http/Requests/UploadTicketAttachmentRequest.php:11`), so customers cannot attach files on the web either.
    (a) No customer uploads in v1; customers see attachments that staff added, as on the web. (b) Seed `ticket.attachment.upload` to the customer role and add a multipart endpoint.
    **Recommend (a).** It matches the web. Tradeoff: no photo of the modem lights from the customer; revisit after the pilot.

16. **Q16 — Customer profile editing.** On the web, a user edits only `users.name` and `users.email` (`app/Http/Requests/ProfileUpdateRequest.php:17-29`). Customer fields (phone, addresses, contacts) are staff-only (`app/Http/Controllers/Admin/CustomerController.php:175`). The login e-mail equals the customer e-mail (`app/Services/Core/CustomerService.php:21-27`).
    (a) Read-only profile in the app. Changes go through customer service or a ticket. (b) Let customers edit phone and contact person.
    **Recommend (a).**

17. **Q17 — Push notifications.** No FCM package exists (`composer.json:11-27`) and there is no device-token store. The WhatsApp lane, now in progress, already sends invoice, payment, ticket and SPK events to customers, and SPK assignments to technicians (`.scratch/whatsapp-notifications/spec.md:21-34`).
    (a) No push in v1: an in-app inbox (Q18) plus WhatsApp. The device registry is the Sanctum tokens themselves (name, last used, expiry). (b) FCM HTTP v1 for both apps: a Firebase project, a service-account JSON on the host, a `device_tokens` table, queued sends, the Android 13 notification permission and deep links. (c) FCM for the technician app only.
    **Recommend (a).** Tradeoff: the app itself raises no instant alert; WhatsApp covers urgency. Revisit after the pilot as its own lane.

18. **Q18 — In-app notification inbox.** The `notifications` table exists (`database/migrations/2026_10_02_000001_create_notifications_table.php`), and `NotificationResource` already maps titles and messages for the web (`app/Http/Resources/NotificationResource.php`). Today the database channel serves staff only: `TicketAssignedNotification` and `TicketSlaBreachedNotification` (mail + database), plus a billing failure. Customers get nothing in the database (`app/Notifications/CustomerAccountCreated.php:16` is mail only). `InventoryRequestReadyNotification` is mail only (`Modules/Inventory/app/Notifications/InventoryRequestReadyNotification.php:23-25`), although it already has a `toArray` payload (`:40-43`). The WhatsApp notification base sends only on its channel (`app/Notifications/WhatsAppNotification.php:45-48`).
    (a) Both apps get an inbox. Technicians see their existing rows, plus `database` on `InventoryRequestReadyNotification`. Customers see the WhatsApp-lane customer events: the notification base adds `database` only for notifiables that can read it, meaning users, or customers with a linked user. Rows store the event type and subject ids only. Titles and messages come from `NotificationResource`, one mapping for web and API, and the API adds `subject {type, id}` for navigation. The customer part ships after the WhatsApp lane lands. (b) A technician inbox only. (c) No inbox.
    **Recommend (a).** Tradeoff: one more row per customer event for app or portal users. Pruning old rows is out of scope.

19. **Q19 — Returning from Midtrans to the app.** `PaymentAttemptService::openFor(Invoice, string $finishUrl)` (`Modules/Billing/app/Services/PaymentAttemptService.php:68`) passes the finish URL into the Snap callbacks (`Modules/Billing/app/Services/MidtransGateway.php:67`). The portal passes `route('portal.payments.return')` (`Modules/Billing/app/Http/Controllers/Portal/InvoiceController.php:63`), which needs a web session. An open attempt is reused whatever finish URL it was created with (`PaymentAttemptService.php:80-86`).
    (a) The API passes a new public, data-free page (`GET /pembayaran/selesai`: "Pembayaran sedang diproses. Kembali ke aplikasi Inbils.") as the finish URL. The app opens `redirect_url` in a Custom Tab and re-reads the invoice when it regains focus, so the state always comes from the server. Known limit: an attempt opened first on the web portal keeps the portal URL. (b) Reuse the portal return URL; the customer then lands on the web login inside the tab. (c) An Android App Link that reopens the app; this needs a verified domain plus `assetlinks.json` with the signing certificate, so v2.
    **Recommend (a).**

20. **Q20 — OpenAPI document.** No generator is installed (`composer.json:11-27`).
    (a) `dedoc/scramble` ^0.13 (v0.13.47 on 2026-10-11, MIT, supports Laravel 10-13, about 2.3 M downloads a month). It infers the spec from routes, FormRequests and Resources without annotations. Install it as `require-dev`, so production has no docs route. `php artisan scramble:export` writes a committed `docs/api/mobile-v1.openapi.json` (allowlisted in `.gitignore`), and a test fails when the export drifts from the code. (b) A hand-written OpenAPI YAML file. (c) `l5-swagger` with annotations on every endpoint.
    **Recommend (a).** Tradeoff: a new dev dependency (generic rules 18-20 apply: state the version and paste `composer audit`). Inferred types for complex fields may need a PHPDoc hint.

## Findings and corrections

- `docs/MOBILE_TASK_PLAN.md` planned "inbils Customer" and "inbils Field Ops" for technicians, surveyors, collectors, warehouse runners and supervisors. Only the roles `admin`, `manager`, `staff`, `technician` and `customer` exist (`database/seeders/RolePermissionSeeder.php:198-202`). The owner asked for a technician app, so the field roles stay out of scope. The plan's `outages` endpoint has no domain behind it: no outage, incident or maintenance model exists for customers.
- The readiness doc still describes the 40 scaffold `/api/v1` routes (`docs/ENTERPRISE_ISP_READINESS.md:49,200-209`). That is drift; ticket 07 fixes it.
- Evidence authorization gap: see Q12.
- Deactivation ends neither sessions nor (future) tokens, and nothing checks `is_active` per request (Q6).
- The compose stack runs `APP_ENV=production` (`.env.docker.example:2`) with `APP_URL=http://localhost:8080` (`:5`). `trustHosts` (`bootstrap/app.php:33`) is enforced outside `local` (`vendor/laravel/framework/src/Illuminate/Http/Middleware/TrustHosts.php:99-103`), so a request with host `10.0.2.2` is rejected. The Flutter dev flavor therefore uses `http://localhost:8080` through `adb reverse`, or `10.0.2.2` only when Laravel runs with `APP_ENV=local` (Flutter lanes, decision on flavors).
- The 409 renderer answers non-Inertia requests with `{message, error: 'conflict'}` (`bootstrap/app.php:48-62`), and `InsufficientStockException` becomes JSON only for `admin/*` (`:64-69`). API requests need their own mapping (ticket 01).
- Inertia scaffolding is irrelevant here (ADR-002). The API is a separate JSON surface and reuses services, policies, FormRequests and Resources, never Inertia controllers.

## Current implementation map

- Auth: Breeze-style controllers (`routes/auth.php`), `LoginRequest::validateCredentials()` without a session (`app/Http/Requests/Auth/LoginRequest.php:44-67`, throttle 5 per `email|ip` at `:76,95-97`), `TwoFactorService` (`app/Services/Core/TwoFactorService.php`: `verifyChallenge :117`, `startChallenge :189`, `endOtherLogins :236`), `users.is_active`, `users.must_change_password`.
- Tenancy: `CompanyService` (`currentId :34`, `idFor :50`, `runAs :88`), `BelongsToCompany` fail-closed.
- Customer identity: `User::customer()` (`app/Models/User.php:104-107`), portal middleware (`Modules/Billing/app/Http/Middleware/EnsureCustomerPortalAccess.php`).
- Technician identity: `User::employeeProfile()` (`app/Models/User.php:99-102`), `MyWorkQuery` eligibility and lists (`app/Queries/Admin/MyWorkQuery.php`), `MyWorkItemResource`.
- Billing: portal controller (`Modules/Billing/app/Http/Controllers/Portal/InvoiceController.php:24-79`), `PortalInvoiceResource` (customer-safe fields), `PaymentAttemptService` (`unpayableReason :48`, `openFor :68`), `MidtransGateway::isEnabled :24`.
- Ticketing: `TicketService` (`create :31`, `startWork :158`, `addComment :180`, `resolve :192`), `TicketPolicy` (`view :16`, `comment :48`, `start :94`, `resolve :102`, `ownsTicket :154`), `StoreTicketRequest` (customer rules and ownership checks `:14-150`), `StoreTicketCommentRequest` (customers forced public `:16-18`, body ≤2000 `:26`).
- SPK: `WorkOrder` (`Modules/SPK/app/Models/WorkOrder.php:69-75` fields), `SpkService` (`start :141`, `submitForReview :160`), `WorkOrderPolicy` (`view :17`, `start :75`, `submit :83`, `isAssignedTechnician :113`), evidence in `WorkOrderController :350-381`.
- Inventory: `MaterialReturnService::declareUsage :182`, `DeclareMaterialReturnRequest`, `MaterialReturnCasePolicy`, `InventoryRequestService`.
- Media: private `local` disk (`config/filesystems.php:33-40`), `MediaController::show` (`routes/admin.php:125`).
- Notifications: `notifications` table, `NotificationResource`, web routes `routes/admin.php:38-39`.
- Resources that are **not** safe to reuse for customers: `SubscriptionResource` (`ont_asset_id`, `serving_pop_id`, `notes`, `app/Http/Resources/SubscriptionResource.php:13-36`), `CustomerResource` (`tax_id`, `notes`, acquirer, WhatsApp consent, `app/Http/Resources/CustomerResource.php:14-41`), `TicketResource` (internal ids and staff fields, `Modules/Ticketing/app/Http/Resources/TicketResource.php:16-56`), `PaymentResource` (`received_by`, `notes`, `Modules/Billing/app/Http/Resources/PaymentResource.php:12-24`).
- Secrets the API must never return: network account credentials (`network_accounts.username/password`, `Modules/NetworkAsset/database/migrations/2026_10_13_210001_create_network_accounts_table.php:15-27`), the Snap token (hidden at `Modules/Billing/app/Http/Resources/PaymentAttemptResource.php:10-12`), 2FA secrets and recovery codes, token hashes.
- Tests: `RefreshDatabase` in 173 files and `LazilyRefreshDatabase` in none (follow `RefreshDatabase`); shared MySQL `inbils_test` (`tests/TestCase.php:18-56`).

## Problem Statement

Customers and technicians use Inbils on their phones through the admin web. That web is built for desktop staff work and runs on a cookie session. A customer who wants to see a bill, pay it or report a fault has to open the browser, log in and find the portal. A technician in the field has to load full Inertia pages to start an SPK, upload evidence and submit it, often on a weak signal. The owner wants two Android apps, and they cannot use the web routes: there is no token login, no JSON contract, no versioning, no protection against a retry creating the same action twice, and no way to revoke a lost phone.

## Solution

A versioned JSON API under `/api/v1` lets the two apps sign in with a per-device token. A customer token reads only that customer's profile, subscriptions, invoices, payments and tickets, and can pay an invoice through the existing Midtrans flow. A technician token reads only the technician's own work, and can start an SPK, upload evidence, declare materials, submit, and work on assigned tickets. Every action goes through the same services and policies as the web, so business rules live in one place. Tokens expire and refresh. They are revoked on logout, password change, deactivation and 2FA changes, and a user can revoke a lost phone from another device. A retried request never applies twice. An OpenAPI document describes the contract the apps are built against.

## User Stories

1. As a customer, I want to log into the customer app with the e-mail and password I use for the portal, so that I have one account.
2. As a customer who enabled two-factor authentication, I want the app to ask for my authenticator code (or a recovery code), so that my second factor protects the app too.
3. As a customer with a legacy password, I want the app to tell me to reset it and send me the reset link, so that I can get in safely.
4. As a customer who forgot my password, I want to request a reset link from the app, so that I can recover access without calling the office.
5. As a customer, I want to stay logged in for weeks without re-entering my password, so that the app is convenient.
6. As a customer, I want to see my profile (code, name, contact, installation addresses), so that I can check what the ISP has on file.
7. As a customer, I want to see each subscription with its package, monthly charge, status and next invoice date, so that I understand my service.
8. As a customer, I want to list my invoices filtered by unpaid or paid, with unpaid ones ordered by due date, so that I see what to pay first.
9. As a customer, I want to open an invoice with its items, payments and remaining balance, so that I can check the amount.
10. As a customer, I want a pay action that returns a Midtrans payment page and reuses an open one, so that I never pay twice.
11. As a customer, I want to be told clearly when an invoice cannot be paid online and why, so that I know what to do.
12. As a customer, I want to see my payment history, so that I can prove what I paid.
13. As a customer, I want to create a ticket by choosing a category and optionally a subscription, so that I can report a problem.
14. As a customer, I want to list my tickets and open one with its public comments, so that I can follow progress.
15. As a customer, I want to add a comment to my ticket, so that I can answer the support team.
16. As a customer, I want an in-app list of my notifications (new invoice, reminder, payment received, ticket updates, visit date), so that I can catch up inside the app.
17. As a customer, I want to see which devices are logged into my account and log one out, so that I can lock out a lost phone.
18. As a technician, I want to log into the technician app with my staff account (and my second factor if enabled), so that I can work in the field.
19. As a technician, I want my assigned SPKs and tickets in one list with counts, so that I can plan my day.
20. As a technician, I want an SPK's details (customer, phone, address and coordinates, items, evidence, material declarations), so that I can do the job.
21. As a technician, I want to start an assigned SPK, so that the office sees I am on site.
22. As a technician, I want to upload photos as evidence with a caption and the time they were taken, so that the work is documented even if I upload later.
23. As a technician, I want an upload retried after a timeout never to create a second copy, so that evidence stays clean.
24. As a technician, I want to delete a wrong photo I uploaded while the SPK is in progress, so that the reviewer sees the right evidence.
25. As a technician, I want to declare used and returned materials for the stock issued to me, so that I can submit the SPK.
26. As a technician, I want to submit an SPK for review and see why if it is refused, so that I can fix what is missing.
27. As a technician, I want to open an assigned ticket, start it, comment on it (internally if needed) and resolve it with a note, so that I can close faults from the field.
28. As a technician, I want to see my material requests and know when one is ready for pickup, so that I go to the warehouse at the right time.
29. As a technician, I want an in-app list of my notifications (ticket assigned, SLA breach, request ready, SPK scheduled), so that I do not miss work.
30. As a supervisor, I want only the assigned technician to add or remove evidence on an SPK in progress, so that evidence cannot be tampered with by colleagues.
31. As an admin, I want deactivating a user or changing their password to log out every phone immediately, so that a leaver or a stolen phone loses access.
32. As an admin, I want the app tokens of a customer and a technician to be useless on the other app's endpoints, so that one stolen token cannot reach the other surface.
33. As an auditor, I want token issue and revocation recorded with user, app, device name and IP but never the token, so that I can trace access.
34. As a customer of company B, I can never read or pay company A's data, and a customer can never read another customer's data.
35. As a mobile developer, I want one documented error shape with stable codes and a generated OpenAPI file, so that both apps handle errors the same way.
36. As an operator, I want every API error returned as JSON without stack traces, so that nothing leaks and the apps never get an HTML page.

## Implementation Decisions

### Platform

- Sanctum personal access tokens, set up with `install:api`, with `HasApiTokens` on `User`. `sanctum.expiration` stays null and each token carries its own `expires_at` (Q4). No stateful or cookie API, and no CORS change: native apps send no `Origin`.
- Routing: core `routes/api.php` is registered with prefix `api/v1` and route names `api.v1.*`. Each module provider maps its own `routes/api.php` with the same prefix, name and the `api` group. A route-list test asserts that every `api/*` URI starts with `api/v1/`.
- Middleware groups are defined once in the bootstrap and are the only thing route files reference:
  - `api.any`: `auth:sanctum` → active-user guard → app-access guard → `verified` → idempotency.
  - `api.customer`: `api.any` + `abilities:customer`.
  - `api.technician`: `api.any` + `abilities:technician`.
- App-access guard: a customer token goes through the customer rule (Q5a). The portal middleware moves to core under a neutral name, is shared by portal and API, and puts the resolved `Customer` on the request. A technician token goes through `MyWorkQuery::isEligible`. A failure returns 403 `app_not_allowed`.
- Active-user guard: if `is_active` is false, delete the current token and return 401 `account_inactive`.
- JSON errors: `api/*` always renders JSON. One renderer maps exceptions to `{message, code, errors?}` and runs before the existing web renderers for `api/*`. A 409 answers `conflict`, `InsufficientStockException` answers 422 `unprocessable`, `ValidationException` answers `validation_failed` with `errors`, and a 429 carries `Retry-After`.
- Rate limiters (named, defined with the existing ones):
  - `api`: 120 per minute per token.
  - Login: the web `LoginRequest` budget (5 per `email|ip`, shared with the web) plus 20 per minute per IP.
  - Two-factor: the web challenge budgets with shared keys.
  - `forgot-password`: 6 per minute per IP (web parity, `routes/auth.php:25-27`).
  - Pay: 6 per minute per user (portal parity, `Modules/Billing/routes/web.php:35`).
  - Uploads: 30 per minute per user.
- Idempotency (Q11): a middleware in `api.any` that only acts on mutating verbs. The fingerprint is a hash of method, path, user and body; for multipart requests the file's SHA-256 replaces the raw bytes. An in-flight lock prevents parallel duplicates.
- Revocation (Q6): `User` model hooks (password changed, deactivated, deleted) and `TwoFactorService::endOtherLogins` delete tokens. Each revocation is audited with log name `security`, action `api.tokens_revoked` and a reason. Token issue is audited as `api.token_issued` with app, device name and IP. Neither ever includes the token.
- Scheduler: `sanctum:prune-expired --hours=24` daily.

### Contract

All paths are relative to `/api/v1`. Mutations under `api.*` groups require `Idempotency-Key`. Success bodies use `{data}`, and lists use `{data, links, meta}` with `page` and `per_page` (≤ 50). Errors use `{message, code, errors?}`.

`Me` = `{ id, name, email, app: "customer"|"technician", two_factor_enabled, company: {id, name}, customer: {id, code, name} | null, employee: {name, phone} | null }`
`Device` = `{ id, name, app, last_used_at, created_at, expires_at, current }`
`Evidence` = `{ id, type: "photo"|"document", caption, mime_type, size, captured_at, uploaded_by: {id, name}, mine, created_at }` (file at `GET media/{id}`)

Auth and session:

| Endpoint | Guard | Request | Success | Errors | Reuses |
| --- | --- | --- | --- | --- | --- |
| `POST auth/login` | public | `email`, `password`, `device_name` (≤100), `app` (`customer`/`technician`) | 200 `{data:{token, token_type:"Bearer", expires_at, user: Me}}`; 202 `{data:{two_factor_required:true, challenge_id, expires_in:300}}` | 422 `validation_failed` (wrong password and inactive user look the same); 403 `password_reset_required` / `app_not_allowed`; 429 | `LoginRequest::validateCredentials()` (`app/Http/Requests/Auth/LoginRequest.php:44-67`), `User::hasTwoFactorEnabled()` |
| `POST auth/two-factor` | public | `challenge_id`, `code` | 200 as login | 422 `validation_failed` (wrong code) / `challenge_expired`; 429 | `TwoFactorService::verifyChallenge()` (`:117`) |
| `POST auth/forgot-password` | public | `email` | 202 `{message}` always | 429 | `Password::sendResetLink`, as `routes/auth.php:25-27` |
| `POST auth/token/refresh` | any token | — | 200 `{data:{token, token_type, expires_at}}` | 401 | Sanctum |
| `POST auth/logout` | any token | — | 204 | 401 | Sanctum |
| `GET me` | `api.any` | — | 200 `{data: Me}` | 401, 403 | — |
| `GET devices` / `DELETE devices/{id}` | `api.any` | — | 200 own tokens / 204 | 404 when the token is not the user's | Sanctum `tokens()` |

Shared:

| Endpoint | Guard | Request | Success | Errors | Reuses |
| --- | --- | --- | --- | --- | --- |
| `GET notifications` | `api.any` | `unread_only?`, `page?` | page of `{id, type, title, message, subject:{type, id}|null, read_at, created_at}` | — | `NotificationResource` + `subject` |
| `POST notifications/{id}/read`, `POST notifications/read-all` | `api.any` | — | 204 | 404 | web parity `routes/admin.php:38-39` |
| `GET media/{id}` | `api.any` | — | file stream: images inline, other types as attachment, `Cache-Control: private, no-store` | 404 (missing, foreign or not allowed) | `MediaController::show` (`app/Http/Controllers/Admin/MediaController.php:22-46`) mounted unchanged |

Customer (`api.customer`):

| Endpoint | Request | Success | Errors | Reuses |
| --- | --- | --- | --- | --- |
| `GET customer/profile` | — | `{code, name, type, email, phone, contact_person, status, addresses:[{id, address, city, postal_code, is_primary, is_installation_point}]}` | — | resolved customer on the request |
| `GET customer/subscriptions`, `GET customer/subscriptions/{id}` | — | `{id, code, status, package:{id, name}, mrc_amount, installation_address:{address, city}, activation_date, billing_day, next_invoice_date, contract_months, expiration_date}` | 404 when not own | `Customer::subscriptions()` (`app/Models/Core/Customer.php:81-84`) |
| `GET customer/invoices` | `status=unpaid\|paid\|all` | page of `PortalInvoiceResource` scalar fields. `unpaid` = `sent\|partial\|overdue` ordered by `due_date` asc, otherwise ordered by `issue_date` desc | — | portal query and `VISIBLE` (`Modules/Billing/app/Http/Controllers/Portal/InvoiceController.php:24-40`) |
| `GET customer/invoices/{id}` | — | `PortalInvoiceResource` with items, non-reversed payments and `payment:{enabled, can_pay, blocked_reason}` | 404 when not own or draft | portal `show` (`:42-58`), `unpayableReason` (`PaymentAttemptService.php:48`) |
| `POST customer/invoices/{id}/pay` | — | 200 `{data:{redirect_url, expires_at}}` | 404 `not_found` / `payment_unavailable`; 422 `invoice_not_payable` (message = blocked reason); 429 | `PaymentAttemptService::openFor()` (`:68`), Q19 finish URL, permission `portal.invoice.pay` |
| `GET customer/payments` | `page` | page of `{id, amount, method, paid_at, invoice:{id, number}}`, non-reversed only | — | portal filter `whereNull('cancelled_at')` (`InvoiceController.php:46`) |
| `GET customer/ticket-categories` | — | `[{id, name}]`, active only | — | web create form (`Modules/Ticketing/app/Http/Controllers/TicketController.php:172`) |
| `GET customer/tickets` | `status=active\|closed\|all` | page of `{id, code, title, status, priority, category:{id, name}, subscription:{id, code}\|null, created_at, updated_at, resolved_at}` | — | customer scope (`TicketController.php:148-149`) |
| `POST customer/tickets` | `category_id`, `title`, `description?`, `subscription_id?`, `priority?` | 201 ticket | 422 | `StoreTicketRequest` unchanged plus `source=customer` merged (`Modules/Ticketing/app/Http/Requests/StoreTicketRequest.php:14-150`), `TicketService::create` |
| `GET customer/tickets/{id}` | — | ticket + `description`, `resolution_note`, `comments:[{id, body, author:{name}, mine, created_at}]` (never internal), `attachments:[{id, file_name, mime_type, size}]` | 404 when not own | `TicketPolicy::view` customer branch, internal filter as on the web (`TicketController.php:208-212`) |
| `POST customer/tickets/{id}/comments` | `body` (≤2000) | 201 comment | 404, 422 | `StoreTicketCommentRequest` (forces `is_internal=false` for customers, `:16-18`), `TicketService::addComment` |

Technician (`api.technician`):

| Endpoint | Request | Success | Errors | Reuses |
| --- | --- | --- | --- | --- |
| `GET technician/my-work` | the web filters (`domain`, `status`, `search`, `sort`, `per_page`) | page of `MyWorkItemResource` + `meta.summary {total, assigned, in_progress, waiting_action}` | — | `MyWorkQuery::filtered/perPage/summary` (`app/Queries/Admin/MyWorkQuery.php:161,195,229`) |
| `GET technician/work-orders/{id}` | — | SPK detail (shape below) | 404 | `WorkOrderPolicy::view` (`Modules/SPK/app/Policies/WorkOrderPolicy.php:17`) |
| `POST technician/work-orders/{id}/start` | — | 200 detail | 403; 409/422 when the status changed | `WorkOrderPolicy::start` (`:75`), `SpkService::start` (`Modules/SPK/app/Services/SpkService.php:141`) |
| `POST technician/work-orders/{id}/evidence` | multipart `file`, `caption?`, `captured_at?` | 201 `{data: Evidence}`; a replay returns the same evidence | 403, 422, 429 | evidence service (Q12) |
| `DELETE technician/work-orders/{id}/evidence/{mediaId}` | — | 204 | 403, 404 | evidence service (Q12) |
| `POST technician/work-orders/{id}/submit` | — | 200 detail | 403; 422 (no evidence, undeclared materials) | `WorkOrderPolicy::submit` (`:83`), `SpkService::submitForReview` (`:160`) |
| `GET technician/tickets/{id}` | — | ticket detail incl. internal comments, customer `{name, phone}`, subscription and location | 404 | `TicketPolicy::view` |
| `POST technician/tickets/{id}/start` | — | 200 detail | 403, 409 | `TicketPolicy::start`, `TicketService::startWork` |
| `POST technician/tickets/{id}/resolve` | `resolution_note` | 200 detail | 403, 422 | `TicketPolicy::resolve`, `TicketService::resolve` |
| `POST technician/tickets/{id}/comments` | `body` (≤2000), `is_internal?` | 201 comment | 403, 422 | `StoreTicketCommentRequest`, `TicketService::addComment` |
| `GET technician/material-returns` | `work_order_id?`, `status?` | page of own cases | — | `MaterialReturnCasePolicy` (`Modules/Inventory/app/Policies/MaterialReturnCasePolicy.php:13-17,29-31`) |
| `GET technician/material-returns/{id}` | — | case with lines | 404 | same |
| `POST technician/material-returns/{id}/declare` | `reason?`, `lines[{id, actual_used_qty, declared_good_return_qty, declared_discrepancy_qty, discrepancy_code?, discrepancy_reason?}]` | 200 case | 403, 409, 422 | `DeclareMaterialReturnRequest` (`:19-27`), `MaterialReturnService::declareUsage` (`:182`) with the header key as `idempotency_key` |
| `GET technician/inventory-requests` | `status?` | page of own requests `{id, code, status, items:[{product_name, unit, quantity}], work_order:{id, code}\|null, updated_at}` | — | `inventory.request.view.own` |

SPK detail shape for technicians (an explicit allow-list built from `WorkOrder`, `Modules/SPK/app/Models/WorkOrder.php:69-75`):

```
{ id, code, type, title, description, status, priority, scheduled_date, started_at, rejection_reason,
  ticket: {id, code} | null,
  customer: {id, code, name, phone} | null,
  subscription: {id, code, package_name} | null,
  site: {label, address, lat, lng} | null,          // SPK location, else the installation address
  items: [{id, product_name, unit, quantity_reserved, quantity_used, note}],
  evidence: [Evidence],
  material_returns: [{id, status}],
  actions: {start, upload_evidence, submit} }       // policy results for the caller
```

### Resource rule

The API never serializes the admin resources listed in the implementation map. It reuses `PortalInvoiceResource`, `MyWorkItemResource` and `NotificationResource` (plus `subject`). It reuses `MaterialReturnCaseResource` and `InventoryRequestResource` only after the inventory ticket confirms they hold nothing beyond the technician's own data. Every other shape is a new, explicit allow-list resource. A test walks each API response and fails on the keys `password`, `snap_token`, `two_factor_secret`, `two_factor_recovery_codes`, `token`, except `token` in the auth responses, and on any network-account field.

## Testing Decisions

- Good tests go through HTTP, the highest seam, and assert status, JSON shape and the database or audit effects. They never assert internals. Endpoint tests use `Sanctum::actingAs($user, ['customer'])` (or `['technician']`). Auth tests use real tokens over `Authorization: Bearer`. Follow the repo's `RefreshDatabase` convention.
- Seams: one feature test file per endpoint group (auth, devices, notifications, media, customer profile and subscriptions, invoices and pay, payments, customer tickets, my work, work orders and evidence, technician tickets, material returns, inventory requests). Plus one unit test of the idempotency fingerprint, and the route-list test.
- Matrix that must exist:
  - Ability fence: customer token on technician routes is 403, and the reverse.
  - Other customer's data is 404. Another company's data is 404.
  - Expired, revoked and refreshed-away tokens are 401.
  - Deactivation, password reset, profile password change and admin password change each revoke all tokens.
  - 2FA: TOTP, recovery code, replayed code, 5 wrong codes, expired challenge, shared throttle.
  - Legacy password is 403. An unverified e-mail, or a user without a company, is 403.
  - Idempotency: a replay returns an identical body, a body mismatch is 422, a parallel duplicate is 409, a missing key is 428, and evidence is still deduplicated after the cache entry is gone.
  - Evidence: the assigned technician may upload while `in_progress`; another technician gets 403; a completed SPK gets 403. The same rules hold on the web route after the refactor.
  - Pay: an open attempt is reused, no Snap token appears in any payload, and the gateway being off gives 404 `payment_unavailable`.
  - Customers never see internal comments.
  - Every error under `api/*` is JSON, including an unknown route, a 405 and a 500 with debug off.
- Prior art: `tests/Feature/Billing/PortalInvoiceTest.php`, `PaymentAttemptServiceTest.php`, `tests/Feature/Ticketing/TicketCustomerScopeTest.php`, `TicketCommentPermissionTest.php`, `tests/Feature/Auth/TwoFactorLoginTest.php`, `TwoFactorChallengeThrottleTest.php`, `InactiveUserLoginTest.php`, `tests/Feature/Admin/MyWorkTest.php`, `tests/Feature/SPK/*`, `tests/Feature/Inventory/MaterialReturnWorkflowTest.php`.
- Gates: Pint and Larastan on changed files; the full isolated PHPUnit run before QA, serialized on the shared `inbils_test`; `composer audit` pasted (Scramble is new); `security-reviewer` APPROVED (generic rule 10, since this lane touches auth, uploads and payments).

## Out of Scope

- Push notifications and device push tokens (Q17), customer ticket uploads (Q15), profile editing (Q16), creating material requests in the app (Q14), managing 2FA in the app.
- Outage or maintenance information: no domain exists.
- Surveyor, collector, warehouse and supervisor features. Assigning and dispatching work (`DispatchQueueQuery`) stays a web supervisor tool.
- Network credentials (PPPoE or hotspot) for technicians. Nothing is exposed in v1; if the field needs them, a separate owner decision is required.
- Customer evaluations (`evaluation.customer.submit`), multi-company tokens (Q9), Android App Links (Q19c), `lang/id` translations (Q10c), pruning old notifications.
- Any change to the Inertia web except the shared evidence service (Q12), the moved customer middleware, the database channel on notifications (Q18) and the public finish page (Q19).

## Further Notes

- **Ticket order:** 01 → {02, 03, 04, 05, 06} in parallel → 07 → 08 → 09. Ticket 02 owns `routes/api.php` after 01 lands. The customer half of 02 (the customer inbox) also waits for the WhatsApp lane to be `done`.
- **Cross-lane order:** start this lane after the web lanes now in flight finish (owner: apps after the web work).
  - `flutter-customer` 01 (workspace, core package, scaffold) needs no API and may start once the specs are approved.
  - `flutter-customer` 02 (auth) needs ticket 01 here. Each app feature ticket needs the API ticket that serves it (listed in each Flutter ticket's `Blocked by`).
  - Both app release tickets need ticket 09 APPROVED and a public HTTPS API host.
- **Deploy:** `php artisan migrate` (the `personal_access_tokens` table), schedule `sanctum:prune-expired`, and `composer install --no-dev` keeps Scramble off production. Nothing new goes in `.env`.
- **Docs:** ticket 07 amends `docs/ENTERPRISE_ISP_READINESS.md` (Q1 plus the scaffold drift), records the contract in `docs/api/mobile-v1.openapi.json`, adds a CHANGELOG entry, and marks `docs/MOBILE_TASK_PLAN.md` as superseded by these three lanes.
- **Ledger:** add three entries to `docs/plans/INBILS_OMP_BACKLOG.md` (outside this lane's files; orchestrator).
