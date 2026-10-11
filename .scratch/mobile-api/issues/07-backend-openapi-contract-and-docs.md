# BACKEND: OpenAPI export (Scramble), contract drift test, docs and readiness amendment

Status: ready-for-agent
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

- [ ] `php artisan scramble:export --path=docs/api/mobile-v1.openapi.json` is reproducible: running it twice gives no diff. The contract test passes, and it fails when a field is removed from any API resource (shown once in a ticket comment, then reverted).
- [ ] The document lists every route of tickets 01-06 with request bodies, success shapes and the bearer scheme, and the shared error and idempotency rules are documented.
- [ ] `git check-ignore docs/api/mobile-v1.openapi.json` prints nothing (the file is trackable). Other ignored docs stay ignored.
- [ ] `composer audit` is pasted. Every advisory is reported, low to critical, in the generic rule 11 format. The Scramble version and its transitive packages are listed.
- [ ] `composer install --no-dev` followed by `php artisan route:list` shows no `docs/api` route.
- [ ] Doc edits are limited to the listed files, and the readiness doc no longer claims 40 scaffold API routes.

## Comments
