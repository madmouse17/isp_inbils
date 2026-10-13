# BACKEND: public_id columns, backfill, model trait

Status: ready-for-agent
Role: backend
Blocked by: ui-consistency and field-trip-status merged (spec D7)
Spec: `../spec.md`

## Scope (owned files)

- Inventory (commit it in this ticket's comments): every model bound in a route (`Route::` params across `routes/*.php`, `Modules/*/routes/*.php`), every model emitted as `id` by an API or Inertia resource. Freeze the list (spec D1).
- One migration per module adding `public_id` (char 26, nullable), backfilling ULIDs in chunks (`Str::ulid()`), then `unique` + NOT NULL; reversible.
- Shared trait `App\Traits\HasPublicId` (uses `HasUlids` with `uniqueIds()` = `['public_id']`, `getRouteKeyName()` = `public_id`), applied to every listed model; factories unaffected (auto-generated).
- `PaymentAttempt` order id format `INB-{ULID}` for new attempts (D5).
- Tests: backfill + uniqueness, trait behaviour, order id format.

## Acceptance criteria

- [ ] Migrations up/down on `inbils_test`; every listed table has a unique NOT NULL `public_id`; focused PHPUnit green.

## Comments
