# BACKEND: API foundation — Sanctum, /api/v1 routing, guards, errors, idempotency, auth endpoints, revocation

Status: done
Role: backend
Blocked by: none (starts only after the owner approves `../spec.md` Q1-Q20 and the web lanes in flight are done)
Card: none (new lane; no Hermes card)
Skills: laravel-best-practices, tdd, web-security-review, codebase-design, ponytail, caveman

## Scope

Implements spec Q1-Q11 (platform part) and the auth/session rows of the contract.

- Sanctum through `php artisan install:api`: `config/sanctum.php` (`expiration` null, no stateful domains), the `personal_access_tokens` migration, and `HasApiTokens` on `User`. Register `routes/api.php` with prefix `api/v1` and names `api.v1.*`. Do not enable `statefulApi()`.
- Middleware groups `api.any`, `api.customer`, `api.technician` exactly as in the spec, plus the aliases they need (`abilities` → Sanctum `CheckAbilities`). Module route files reference only these group names.
- Move `Modules/Billing/app/Http/Middleware/EnsureCustomerPortalAccess.php` to core as `app/Http/Middleware/EnsureCustomerAccess.php`. Behaviour and the request attribute stay the same. Expose the "customer for this user" rule as one public static method that both the middleware and the API guard call. Update every reference (`Modules/Billing/routes/web.php:8,28`, `Portal/InvoiceController.php:10,70`, `Portal/PaymentReturnController.php:10,23`) and delete the old file. This is a clean cutover with no alias.
- Write the API guards:
  - Active-user guard: inactive → delete the current token, 401 `account_inactive`.
  - App-access guard: a customer token uses the customer rule and puts the `Customer` on the request; a technician token uses `MyWorkQuery::isEligible`; either one also requires a verified e-mail and a company. A failure is 403 `app_not_allowed`.
  - Idempotency middleware (Q11): cache store, 24 h, fingerprint, in-flight lock, the codes `idempotency_key_required` (428), `idempotency_conflict` (422) and `idempotency_in_progress` (409), and no stored 5xx. Expose the validated key to controllers so that tickets 05 and 06 can persist it.
- JSON error rendering for `api/*` (Q10a): `shouldRenderJsonWhen` plus one renderer producing `{message, code, errors?}`, registered so the existing 409 and `InsufficientStockException` renderers (`bootstrap/app.php:48-69`) never answer `api/*` with a redirect. Per-status default codes, a domain exception carrying an explicit code, `Retry-After` on 429, and no exception text when `APP_DEBUG=false`.
- Named rate limiters per the spec (`api`, login IP cap, `forgot-password`, `api-pay`, `api-upload`), defined next to the existing `RateLimiter::for` calls in `app/Providers/AppServiceProvider.php`. `throttleApi('api')`.
- Auth endpoints: `POST auth/login`, `POST auth/two-factor`, `POST auth/forgot-password`, `POST auth/token/refresh`, `POST auth/logout`, `GET me`, `GET devices`, `DELETE devices/{id}`.
  - The API login FormRequest extends the web `LoginRequest`, so credentials, the `is_active` lookup and the 5-per-`email|ip` budget are shared with the web. It adds `device_name` (required, string, at most 100 characters, control characters stripped) and `app` (`customer|technician`).
  - Refuse with 403 `password_reset_required` when `must_change_password` is set (Q8), and with 403 `app_not_allowed` when the app rule fails (Q5).
  - 2FA (Q7): a cache challenge (`Str::random(64)` id; the cache key is its hash; TTL 300 s; at most 5 attempts), verified with `TwoFactorService::verifyChallenge`, with limiter keys shared with `TwoFactorChallengeRequest`.
  - Token issue (one core service used by login, two-factor and refresh): ability = `app`, expiry 90/30 days from config, `last_login_at` updated as on the web, audit `api.token_issued` (app, device name, IP).
  - Refresh rotates the token in one transaction. Logout deletes the current token.
  - `forgot-password` calls `Password::sendResetLink` and always answers 202 with the same body.
- Revocation (Q6):
  - `User` model hooks: `password` changed, `is_active` set to false, user deleted → delete all tokens.
  - `TwoFactorService::endOtherLogins` (`app/Services/Core/TwoFactorService.php:236`) also deletes all tokens.
  - Each revocation is audited as `api.tokens_revoked` with a reason. The audit never contains the token.
- Schedule `sanctum:prune-expired --hours=24` daily in `routes/console.php`.

Non-goals: every non-auth endpoint (tickets 02-06), OpenAPI (07), `lang/id`, push, managing 2FA through the API.

## Owned files

- NEW `config/sanctum.php`, NEW `database/migrations/<timestamp>_create_personal_access_tokens_table.php`, NEW `routes/api.php`
- `bootstrap/app.php`, `routes/console.php`, `app/Providers/AppServiceProvider.php`, `app/Models/User.php`, `app/Services/Core/TwoFactorService.php`
- NEW `app/Http/Middleware/EnsureCustomerAccess.php`; DELETE `Modules/Billing/app/Http/Middleware/EnsureCustomerPortalAccess.php`; `Modules/Billing/routes/web.php`, `Modules/Billing/app/Http/Controllers/Portal/InvoiceController.php`, `Modules/Billing/app/Http/Controllers/Portal/PaymentReturnController.php` (import and constant references only)
- NEW `app/Http/Middleware/Api/` (active-user guard, app-access guard, idempotency)
- NEW `app/Exceptions/` (API domain exception carrying `code`)
- NEW `app/Services/Core/ApiTokenService.php` (issue, rotate, revoke, 2FA challenge store)
- NEW `app/Http/Controllers/Api/V1/` (auth, me, devices), NEW `app/Http/Requests/Api/V1/` (login, two-factor, forgot-password), NEW `app/Http/Resources/Api/V1/` (`Me`, `Device`)
- NEW `tests/Feature/Api/V1/Platform/` and `tests/Feature/Api/V1/Auth/`

## Acceptance criteria

- [x] `php artisan route:list --path=api` shows only `api/v1/*` routes, and a test asserts this. (`RouteRegistrationTest`; the command lists exactly the 8 auth/me/devices routes.)
- [x] Login with valid credentials returns a token with the requested ability and the right expiry; wrong password and inactive user return the same 422; the 6th attempt for one `email|ip` returns 429 with `Retry-After`, and that budget is shared with the web login. (`Auth/LoginTest`)
- [x] A user with confirmed 2FA gets 202 and a `challenge_id`. A valid TOTP returns a token. A replayed TOTP, a reused recovery code and an expired challenge are refused. The 6th wrong code within 60 s returns 429. No token is issued before the challenge passes. (`Auth/TwoFactorLoginTest`)
- [x] `must_change_password` returns 403 `password_reset_required`. A customer without a linked customer, a non-technician on `app=technician`, an unverified e-mail and a user without a company each return 403 `app_not_allowed`. (`Auth/LoginTest`, `Platform/ApiGuardsTest`)
- [x] `forgot-password` returns the same 202 body for a known address, an unknown address and a broker-throttled address, and the known address receives the standard reset mail. (`Auth/ForgotPasswordTest`)
- [x] Refresh returns a new token and the old one is 401. Logout makes the current token 401. `DELETE devices/{id}` works only on the user's own tokens and is 404 otherwise. (`Auth/TokenLifecycleTest`)
- [x] Each of these leaves zero tokens for the user, and the next API call is 401: password reset, profile password update, admin password change, deactivation, deletion, 2FA enable, disable, regenerate and admin reset. (`Platform/TokenRevocationTest`)
- [x] On a probe route registered only in the test, under `api.customer`: a technician token gets 403, an inactive user gets 401 `account_inactive` and the token is deleted, and the resolved `Customer` is on the request. The existing portal tests (`tests/Feature/Billing/PortalInvoiceTest.php`, `PortalCredentialsTest.php`) stay green after the middleware move. (`Platform/ApiGuardsTest`; portal tests re-run green)
- [x] Idempotency: a replay returns a byte-identical body and status, a different body is 422 `idempotency_conflict`, a parallel duplicate is 409, a missing key on a mutation is 428, GET needs no key, and 5xx is not cached. (`Platform/IdempotencyTest`)
- [x] Every error under `api/*` is JSON `{message, code}`: an unknown route, 405, 401, 403, 404, 409, 422 (with `errors`), 429, and 500 with `APP_DEBUG=false`. No stack trace, exception class or SQL appears. (`Platform/ErrorEnvelopeTest`)
- [x] `activity_log` has `api.token_issued` and `api.tokens_revoked` rows without any token text. A grep for the plaintext token in `storage/logs` after the test run finds nothing. (`TokenRevocationTest`, `LoginTest`; the log grep found no `id|token` string)
- [x] `sanctum:prune-expired` is scheduled daily (`php artisan schedule:list`). (`Platform/TokenHousekeepingTest`)
- [x] Pint is clean and Larastan is clean on changed files. Migration up and down passes on `inbils_test`. (`TokenHousekeepingTest::test_the_tokens_table_rolls_back_and_forward`)

## Comments

### 2026-10-13: done (backend agent MobileApiFoundation)

Red first: 131 tests existed and 130 failed (1 passed) before any implementation; three more were added while reviewing (two `Sanctum::actingAs` compatibility tests, one stale-token refresh). Final: the 134 tests of `tests/Feature/Api` plus `ApiExposureTest`, the two portal suites, `tests/Feature/Auth`, `UserTwoFactorResetTest` and `ProfileTest`: 306 passed. A wider run over billing, admin, tenant and WhatsApp suites had 12 failures, all in `WhatsApp/WhatsAppPrivacyAndTenantTest` (a data provider with the wrong arity: WhatsApp lane work in flight, not this ticket).

Contract for tickets 02-06

- Module route files: `Route::middleware('api')->prefix('api/v1')->name('api.v1.')->group(module_path($this->name, '/routes/api.php'))` in the module's `RouteServiceProvider`. Core routes of ticket 02 go in `routes/api.php`, which is already mapped the same way in `bootstrap/app.php`.
- Route files name only the groups: `api.any` (signed-in app user, either app), `api.customer`, `api.technician` (same, fenced to one app by the token ability). `api.session` is the same guards without `Idempotency-Key`, used by `auth/token/refresh` only. Run order: `auth:sanctum`, `throttle:api`, active-user guard, ability fence, app-access guard, idempotency, then route model binding.
- Customer routes get the resolved `Customer` from `$request->attributes->get(App\Http\Middleware\EnsureCustomerAccess::CUSTOMER_ATTRIBUTE)`, the same attribute the web portal uses. The company of a token request is the user's home company.
- Mutations under `api.any|customer|technician` need `Idempotency-Key` (UUID). The validated lower-case key is `$request->attributes->get(App\Http\Middleware\Api\EnforceIdempotency::KEY_ATTRIBUTE)` (tickets 05 and 06 persist it). Replays carry `Idempotent-Replayed: true`. 4xx are stored and replayed, 5xx and 429 are not.
- Limiters to attach on routes: `throttle:api-pay` (6 a minute per user) and `throttle:api-upload` (30 a minute per user). `api` (120 a minute per token) is already in the group.
- Errors: throw `App\Exceptions\ApiException($status, $code, $message)` for a stable domain code; `abort()`, validation, `ModelNotFoundException`, policies and `ConflictHttpException` already map to `{message, code, errors?}`. A domain exception that means "well-formed but refused" and is not an `HttpException` becomes a 422 `unprocessable` only when listed in `new RenderApiError([...])` in `bootstrap/app.php` (today `InsufficientStockException`); otherwise throw `ApiException(422, 'unprocessable', ...)`.
- Success bodies: `JsonResource` (`{data}`) and `paginate()` (`{data, links, meta}`); never the admin resources.
- Endpoint tests: `Sanctum::actingAs($user, ['customer'|'technician'])` passes every guard (the user still has to be eligible). Several requests in one test with different bearer tokens must go through `Tests\Traits\InteractsWithMobileApi::api()`, which forgets the cached Sanctum guard between requests; the trait also has `customerLogin()`, `technicianLogin()` and `issueToken()`.

Decisions taken inside the ticket

- The web `verified` middleware never blocks, because `User` does not implement `MustVerifyEmail`. The API therefore checks `email_verified_at` itself, in the app-access guard, as the acceptance criteria require. A user who changed their e-mail on the web (it clears the flag) is `app_not_allowed` until the link in the verification mail is opened.
- `must_change_password` is enforced on every request too (403 `password_reset_required`), the web portal's rule for money screens.
- A user with confirmed 2FA gets the challenge before anything about app access is checked, so a password alone reveals nothing; access is re-checked when the code passes.
- Five wrong codes kill a challenge, but lazily: after the throttle (so the 6th attempt inside 60 s is a 429) and before the code is looked at (so a later attempt is `challenge_expired` even with the right code).
- `TwoFactorChallengeRequest` keeps its limits private, so `Api\V1\TwoFactorRequest` repeats the keys and numbers; tests pin that both budgets are shared.
- Sanctum is configured with `guard => []` (a web session user must not pass for a token with every ability), `stateful => []` and `routes => false` (no csrf-cookie route). Lifetimes are `sanctum.app_token_days`.
- The ability fence answers 403 `forbidden`; `app_not_allowed` is the app rule. Messages for 404 and 5xx are fixed, so no model class or exception text leaks.
- The device list hides tokens that already expired. Refresh locks the old token row, so two refreshes racing on one token cannot both succeed.
