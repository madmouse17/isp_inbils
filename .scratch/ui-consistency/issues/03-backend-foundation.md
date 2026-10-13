# BACKEND: Indonesian locale, lang files, SearchOptions helper

Status: done
Role: backend
Blocked by: none
Spec: `../spec.md`

## Scope (owned files)

- `lang/id/{validation,auth,passwords,pagination}.php`, `lang/id.json` (R3), attribute names for common fields (`attributes` array: email, password → kata sandi, name → nama, …).
- `config/app.php` locale/fallback `id`; `.env.example` and `.env.docker.example` `APP_LOCALE=id`, `APP_FALLBACK_LOCALE=id` (never real `.env*`; deploy note in CHANGELOG).
- New `app/Support/SearchOptions.php` per R4 with a feature test (min chars, limit 20, escaping, company scope, selected value included).
- Breeze auth controllers' flash/status strings (`app/Http/Controllers/Auth/*`), `CompanySwitchController`, `SetupWizardController` messages, `app/Http/Requests/Auth/*`.
- Update PHPUnit tests that assert English framework validation or auth text (grep `The .* field`, `These credentials`, `Too many login attempts`, `passwords.`).

## Antislop findings (A1)

- G15 backend: render 403/404/419/500/503 through Inertia `Error` page (`bootstrap/app.php` exception renderer), Indonesian copy.
- G17 `routes/web.php` `/` redirects to login or dashboard; `VITE_APP_NAME=Inbils` in `.env.example` and `.env.docker.example`.
- PE4 age bug: `DispatchItemResource.php:40,81`, `ApprovalInboxItemResource.php:43,79`, `MyWorkItemResource.php:37,76` use `$created_at->diffInSeconds(now())` (Carbon 3 is signed); regression test asserting a 3-day-old item reports 259200 s.
- PL4 `lang/id` `attributes` include nested keys (`addresses.*.label`, `addresses.*.lat`, `contacts.*.name`, …).

## Acceptance criteria

- [x] Focused PHPUnit green after the locale switch (full suite is run by the orchestrator); `SearchOptions` test green; Pint clean.

## Comments

### UiLocale report (2026-10-14)

**Done**
- `lang/id/{validation,auth,passwords,pagination}.php`, `lang/id.json` (framework mails, 2FA/forgot-password JSON keys); `validation.attributes` has flat keys incl. nested `addresses.*.*`, `contacts.*.*`, `check_in.*`, `settings.*`, `subscription.*`, `lines.*.*`, `items.*.*` (PL4).
- `config/app.php` locale and fallback `id`; `.env.example` and `.env.docker.example` `APP_LOCALE=id`, `APP_FALLBACK_LOCALE=id`, `VITE_APP_NAME=Inbils`; `phpunit.xml` pins `APP_LOCALE`/`APP_FALLBACK_LOCALE=id` (real `.env` has `en`; tests no longer depend on it). `.env.e2e` has no locale key, so the E2E server uses the new default `id`.
- `app/Support/SearchOptions.php`: `SearchOptions::for($request, $field, $scopedBase, $searchColumns, $present, $selected = null)` returns `Inertia::optional`; ≥ 2 chars after trim, LIKE `\ % _` escaped, ≤ 20 total, selected option first and not repeated, caller-scoped base query only (foreign selected key never revealed).
- Flash/status strings Indonesian: `CompanySwitchController`, `SetupWizardController`, `TwoFactorAuthenticationController`; auth/reset/throttle text via `lang`. `LoginRequest` logic untouched.
- `bootstrap/app.php` `respond()` renders `Inertia::render('Error', ['status' => n])` for 403/404/419/500/503; skipped for `expectsJson()`, `api/*`, `webhooks/*`, `up`, `ready`, and 500 while `APP_DEBUG=true`. `routes/web.php` `/` redirects to `login` or `admin.dashboard`.
- PE4: six `age_seconds` sites (Dispatch, MyWork, ApprovalInbox ×2 each domain) now `max(0, (int) $created->diffInSeconds(now()))`. Failing-first: `WorkItemAgeSecondsTest` was 7 failed (0 and 60.0) before the fix.
- Main's extra item: `/admin/components` open in `local|testing|e2e`, passes `demoOptions` (`SearchOptions`, field `demo`, Location name/code); tests for e2e open, staging 404, optional prop and company scope. `developerGalleryEnabled` unchanged.
- Tests: new `WorkItemAgeSecondsTest`, `Support/SearchOptionsTest`, `ErrorPagesTest`; `ExampleTest` now asserts the `/` redirects; removed `RegistrationTest` Welcome test; English auth/2FA/throttle/forgot-password assertions (including `Api/V1/Auth/ForgotPasswordTest`) now Indonesian.
- CHANGELOG entry with deploy note.

**Verified**
- `flock … sail exec -T -u sail laravel.test … artisan test --compact tests/Feature/Auth tests/Feature/Tenant tests/Feature/Admin/{WorkItemAgeSecondsTest,ApprovalInboxTest,ApprovalInboxInventoryTest,MyWorkTest,DispatchQueueTest}.php tests/Feature/Support/SearchOptionsTest.php tests/Feature/ErrorPagesTest.php tests/Feature/ExampleTest.php tests/Feature/ComponentGalleryAuthorizationTest.php tests/Feature/Api/V1/Auth` → `Tests: 354 passed (3289 assertions)`.
- `grep -rnE "The .* field|These credentials|Too many" tests --include=*.php` → no hits.
- `./vendor/bin/pint --test` on every touched PHP file and `lang` → `pint: ok`.

**Not verified**
- Full PHPUnit suite and E2E (orchestrator runs them). Other suites asserting English framework text outside `tests/Feature/Auth`, `Tenant`, `Api/V1/Auth` were found only by grep (none), not by running them.
- `public/build` is stale: the Error page renders through the existing `Pages/Error.tsx` (still English copy, UiSystem owns the Indonesian rewrite).

**needs-change / notes for UiSystem (messaged)**
- E2E: "Now working in X." → "Sekarang bekerja di X." (`head-company.spec.ts`), `two-factor.spec.ts` invalid code → "Kode autentikasi dua langkah tidak valid.", auth-roles "landing" test (Welcome gone; `/` lands on login), English login/validation texts.
- The mobile API now answers validation and forgot-password text in Indonesian (locale is global); Q6 said API labels stay unchanged, so say so if the Android apps need English.

2026-10-14, orchestrator: locale fixed to `id` in `config/app.php` (real `.env` files still say `en` and must not be edited by agents); removed the now-dead `APP_LOCALE`/`APP_FALLBACK_LOCALE` lines from `.env.example`, `.env.docker.example` and `phpunit.xml`; CHANGELOG deploy line updated. API validation messages are Indonesian too (global locale); the apps show server messages verbatim, only their mock fixtures carry English text, so no app change is needed.

- Follow-up: `AdminMenuAuthorizationTest::environments()` now carries (env, nav flag, route open); `e2e` expects the route open and `developerGalleryEnabled` false. `AdminMenuAuthorizationTest` + `ComponentGalleryAuthorizationTest` → 43 passed (1397 assertions); Pint ok.
- Orchestrator: page titles use the fixed product name "Inbils" in `app.tsx` (real `.env` files carry `VITE_APP_NAME=Laravel`); `VITE_APP_NAME` removed from the env examples.
