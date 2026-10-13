# BACKEND: uniform record-level 404, enumeration detection

Status: ready-for-agent
Role: backend
Blocked by: 02
Spec: `../spec.md`

## Scope (owned files)

- Policy-hidden records answer 404 (a `Gate::after`/response mapping for `view`/record abilities, or `abort_unless(..., 404)` in the record controllers; keep `can:` middleware 403s, K3). API technician-foreign work order → 404.
- `config/security.php` (`enumeration.enabled`, `threshold`, `window`, `cooldown`), middleware `DetectRecordEnumeration` on web admin and API groups (K4), audit `security.enumeration_suspected`, 429 response (JSON for API, Inertia error page for web, Indonesian copy).
- Tests per spec.

## Acceptance criteria

- [ ] Focused PHPUnit green; enumeration test enables the feature explicitly; default off in testing/e2e.

## Comments
