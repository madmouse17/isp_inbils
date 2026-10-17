# BACKEND: uniform record-level 404, enumeration detection

Status: done
Role: backend
Blocked by: 02
Spec: `../spec.md`

## Scope (owned files)

- Policy-hidden records answer 404 (a `Gate::after`/response mapping for `view`/record abilities, or `abort_unless(..., 404)` in the record controllers; keep `can:` middleware 403s, K3). API technician-foreign work order → 404.
- `config/security.php` (`enumeration.enabled`, `threshold`, `window`, `cooldown`), middleware `DetectRecordEnumeration` on web admin and API groups (K4), audit `security.enumeration_suspected`, 429 response (JSON for API, Inertia error page for web, Indonesian copy).
- Tests per spec.

## Acceptance criteria

- [x] Focused PHPUnit green; enumeration test enables the feature explicitly; default off in testing/e2e.

## Comments

### 2026-10-17, OpaqueIds23 - NOT STARTED (blocked by 02, which is partial)

Nothing of ticket 03 was written by OpaqueIds23: no policy change, no `config/security.php`, no enumeration middleware, no tests (OpaqueCore took K3/K4: `App\Support\RecordNotFound`, `DetectRecordEnumeration`). Inventory facts gathered by a read-only scout (transcript `agent://OpaqueIds23.InvAuthz`): policies are registered one by one in `AppServiceProvider` via `Gate::policy(...)` (33 model policies) and no policy defines `before()`/`after()`. CORRECTION of an earlier version of this note: a central `Gate::after` mapping does NOT work (`Gate::after` cannot override a false result, checked by OpaqueCore in vendor). K3 is done per policy method instead: a policy that hides a record by scope (assignee, own, administers) returns `Response::denyAsNotFound()`, hand-written record 404s use `RecordNotFound`, and a missing permission stays `false` (403), as do class-level abilities (`viewAny`, `create`) and `can:` middleware. FormRequest `authorize()` denials (for example the new `MoveLocationRequest`) throw a plain 403 and follow the same rule. The technician-foreign work order is a 403 today (`TenancyMatrixTest` pins it).

### 2026-10-17, OpaqueCore - K3/K4 core written; focused tests written, NOT yet verified

- `App\Support\RecordNotFound` (`abort()`, `abortIf()`, `abortUnless()`; a `NotFoundHttpException`, so it renders like a binding miss). Core hand-written record 404s converted (customer address/contact/subscription/evaluation/user/two-factor controllers, `DocumentService`, `LocationService::assertCompany`, `OrganizationService`, `CreateInvoiceFromSpkAction`, `UpdateSubscriptionRequest`, company switch, media web + API, platform export download, support actions, impersonation start).
- K3 per policy, no central mapping: `UserPolicy` (a user the actor does not administer: `Response::denyAsNotFound()`; missing permission or role stays 403) and `EmployeeEvaluationPolicy::view` (a technician reading a colleague's evaluation). `LocationPolicy::sameCompany` is redundant with the company scope and was left alone.
- K4: `config/security.php` (`enumeration.enabled` default off in testing/e2e via APP_ENV, `threshold` 30, `window` 5 min, `cooldown` 10 min; env `SECURITY_ENUMERATION_*`), `App\Http\Middleware\DetectRecordEnumeration` on the web group, the platform group (`PlatformServiceProvider`) and the api group, ordered ahead of `SubstituteBindings` and after the guards (`bootstrap/app.php`). It counts a response whose exception is `ModelNotFoundException`, `RecordNotFound` or an `AuthorizationException` with status 404 (the pipeline keeps the exception on the response); one counter per minute, the window is the last N counters; the request that crosses the line writes ONE `security.enumeration_suspected` entry (route name, the ids tried, count, window, ip) and every later request of that user (address when signed out) gets 429 with `Retry-After`; the web Error page now also renders 429 (`Retry-After` kept), copy added by OpaqueIdsUi in `Error.tsx`.
- Test: `tests/Feature/PublicId/DetectRecordEnumerationTest` (off by default, threshold + one audit entry, cooldown, rolling window, 403 not counted, policy-hidden record counted, per-user budget, API JSON + `Retry-After`, signed-out visitor by address).
- Risk for the owner: a signed-out actor is counted by IP. Isolated subscribers often share one NAT address and the isolir page (`isolir/{company:code}`) answers 404 for an unknown code, so a router with a wrong code or a code scanner can lock that address out of every web page for 10 minutes. Switch off with `SECURITY_ENUMERATION_ENABLED=false` or raise the threshold if that matters.
- Module slices keep their own policies/controllers: `Response::denyAsNotFound()` for scope denials, `RecordNotFound` for hand-written checks (OpaqueOps did it for SPK/Ticketing).

2026-10-17, orchestrator: completed across slices Core/Ops/Money/Reports (+ their test agents); every directory green; see slice sections above.
