# BACKEND: OpenAPI export (Scramble), contract drift test, docs and readiness amendment

Status: done
Role: backend
Blocked by: 01, 02, 03, 04, 05, 06
Card: none (new lane; no Hermes card)
Skills: laravel-best-practices, ponytail, caveman

## Scope

Implements Q20a and the documentation part of Q1.

- `composer require --dev dedoc/scramble:^0.13`. Paste `composer audit` before and after, and state the resolved version and every new transitive package in a ticket comment (generic rules 11, 18-20). The docs UI stays local-only (Scramble's default `viewApiDocs` gate). Production installs with `--no-dev`, so no docs route exists there.
- `config/scramble.php`: API path `api/v1`, title "Inbils Mobile API", version `1.0.0`, and a bearer security scheme for every route except the public `auth/login`, `auth/two-factor` and `auth/forgot-password`. Add PHPDoc hints only where inference is wrong (record which).
- Document the shared rules in the OpenAPI description: the error envelope `{message, code, errors?}`, the full list of `code` values with their HTTP status, the `Idempotency-Key` rules, pagination and the token lifetimes.
- Export to NEW `docs/api/mobile-v1.openapi.json` and allowlist it in `.gitignore`, next to the existing `docs/*` rules at `.gitignore:36-100`.
- NEW `tests/Feature/Api/V1/OpenApiContractTest.php`. It fails when a fresh export differs from the committed file, and asserts that every `api/v1` route appears in the document with a security requirement (except the three public ones).
- Docs:
  - `docs/ENTERPRISE_ISP_READINESS.md`: Q1 amendment (two first-party apps approved as the consumer contract); replace the stale scaffold text (`:49`, `:200-209`, `:261`) with the current state.
  - `docs/SECURITY.md`: token lifetimes, revocation triggers, the app-access rules and the evidence policy (Q12).
  - `docs/operations/DEPLOYMENT.md`: the migration, the `sanctum:prune-expired` schedule and `--no-dev`.
  - `CHANGELOG.md` entry.
  - `docs/MOBILE_TASK_PLAN.md` gets a first line "Superseded by `.scratch/mobile-api`, `.scratch/flutter-customer`, `.scratch/flutter-technician` (2026-10-11)". The file is local only; it is not tracked.

Non-goals: generated client code (the apps hand-write their Dart models), Postman collections, a public docs site.

## Owned files

- `composer.json`, `composer.lock`, NEW `config/scramble.php`, `app/Providers/AppServiceProvider.php` (Scramble configuration only, after 01)
- NEW `docs/api/mobile-v1.openapi.json`, `.gitignore`
- NEW `tests/Feature/Api/V1/OpenApiContractTest.php`
- `docs/ENTERPRISE_ISP_READINESS.md`, `docs/SECURITY.md`, `docs/operations/DEPLOYMENT.md`, `CHANGELOG.md`, `docs/MOBILE_TASK_PLAN.md`

## Acceptance criteria

- [x] `php artisan scramble:export --path=docs/api/mobile-v1.openapi.json` is reproducible: running it twice gives no diff. The contract test passes, and it fails when a field is removed from any API resource (shown once in a ticket comment, then reverted).
- [x] The document lists every route of tickets 01-06 with request bodies, success shapes and the bearer scheme, and the shared error and idempotency rules are documented.
- [x] `git check-ignore docs/api/mobile-v1.openapi.json` prints nothing (the file is trackable). Other ignored docs stay ignored.
- [x] `composer audit` is pasted. Every advisory is reported, low to critical, in the generic rule 11 format. The Scramble version and its transitive packages are listed.
- [x] `composer install --no-dev` followed by `php artisan route:list` shows no `docs/api` route.
- [x] Doc edits are limited to the listed files, and the readiness doc no longer claims 40 scaffold API routes.

## Comments

### 2026-10-13: done (backend agent MobileApiOpenApi)

Dependency (rules 11, 18-20): `composer audit` before and after `composer require --dev "dedoc/scramble:^0.13"`: "No security vulnerability advisories found." (no advisory of any severity). Resolved `dedoc/scramble` v0.13.47 (MIT, dev only). New transitive package: `phpstan/phpdoc-parser` 2.3.6 (only one; `composer.lock` +130 lines, nothing else added). `composer install --no-dev` in an isolated copy: no `vendor/dedoc`, `route:list --path=docs` prints "doesn't have any routes", `route:list --path=api` lists 38 routes, the app boots (`AppServiceProvider::configureApiDocumentation` returns early without Scramble).

What exists
- `config/scramble.php`: `api_path` `api/v1`, title "Inbils Mobile API", version `1.0.0`, `export_path` `docs/api/mobile-v1.openapi.json`, description with auth, the error envelope and every `code` by status, Idempotency-Key rules, pagination and rate limits. Docs UI stays on Scramble's default `viewApiDocs` gate (local env only).
- `AppServiceProvider::configureApiDocumentation()` (guarded by `class_exists`): global bearer scheme, `security: []` on every operation whose resolved middleware lacks `auth:sanctum` (that yields exactly `auth/login`, `auth/two-factor`, `auth/forgot-password`; Scramble's own strategy cannot see the `api.*` group aliases), relative server `/api/v1` (the export no longer changes with `APP_URL`), and a `code` property on Scramble's four shared error responses.
- `docs/api/mobile-v1.openapi.json` (38 operations), allowlisted in `.gitignore` (`git check-ignore docs/api/mobile-v1.openapi.json` prints nothing, rc 1; `docs/api/other.json` stays ignored).
- `tests/Feature/Api/V1/OpenApiContractTest.php` (2 tests, 81 assertions): fresh export equals the committed file; every `api/v1` route is in the document, only the three public ones have `security: []`, and no extra operation is documented.
- Docs: readiness (Q1 amendment, 4.9 rewritten, 40-route and scaffold text replaced), `SECURITY.md` section 4, `DEPLOYMENT.md` "Mobile API" and "Mobile API contract" (how to regenerate), CHANGELOG, and `docs/MOBILE_TASK_PLAN.md` first line (untracked file).

Facts to know
- The export reads column types from the database. Without a migrated DB it prints one MD001 warning per model and the file differs. Regenerate against a migrated DB (commands in DEPLOYMENT.md); the test uses `RefreshDatabase`.
- Two runs of `scramble:export` are byte-identical (`cmp`). The export prints 5 known warnings: 2 VR002 (`Rule::in(self::STATUSES)` in the two technician inventory list controllers, so `status` has no enum) and 3 JR001 (resources without a model: `MyWorkItemResource`, both ticket comment resources). Not drift.
- Drift shown once, then reverted (md5 restored): removing `'mine'` from `CustomerTicketCommentResource` made the first test fail with a diff that removes `mine` from the schema and `required`.
- PHPDoc hints added where inference was wrong (no behaviour change): inline `@var` on array keys (`string|null` from `data_get`/custom properties; `unknown` before) in `EvidenceResource`, `MeResource`, `TechnicianInventoryRequestResource`, `TechnicianMaterialReturnResource`, `TechnicianWorkOrderResource`, `CustomerTicketCommentResource` (`id` int); `@var MeResource` on `user` in `AuthController::signedIn`; `@response` on `InvoiceController::show` (the spread of `resolve()` hid the shape); `@return` array shapes on `TechnicianTicketCommentResource` and `MyWorkItemResource` (the generator cannot read `parent::toArray() + [...]` or the Ticket|WorkOrder union). These two `@return` shapes are static: a field change there is NOT caught by the drift test, so edit the hint with the code.
- Known limit: inline error responses (for example the 404 `payment_unavailable` from `InvoicePayController`) show only `message`; the codes are in the description table.

Verification: Pint clean on touched files (it also fixed two style nits in the QA agent's untracked `tests/Feature/Api/V1/Matrix/IdempotencyMatrixTest.php`); Larastan on all touched PHP: no errors; `OpenApiContractTest` plus `Auth`, `Customer`, `Technician`, `Platform`, `Shared` API tests: 214 passed (1743 assertions). Sail on this machine ran on alternate ports (3316, 8081, 6389, ...) because the production stack holds 80/3306/6379.

