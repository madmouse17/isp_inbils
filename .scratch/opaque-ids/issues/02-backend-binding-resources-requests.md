# BACKEND: route constraints, resources emit public ids, requests accept them

Status: ready-for-agent
Role: backend
Blocked by: 01
Spec: `../spec.md`

## Scope (owned files)

- Route files: `whereUlid` on every record parameter; remove `whereNumber` on API record params.
- Every API resource and Inertia resource/prop: `id` → `public_id`; reference fields → referenced `public_id` (D2). Includes `SearchOptions` values, notification `data` URLs, Ziggy-built links in PHP.
- `App\Rules\PublicIdExists` + request helper (D3); every FormRequest that takes a reference id switches to it; services keep integer keys.
- Migration rewriting stored notification URLs (D6).
- OpenAPI regenerated, version `1.1`, string ids.
- Tests: route matrix (integer → 404, foreign → 404), `PublicIdExists`, `TenancyMatrixTest`/`OpenApiContractTest` updates, every test that posted integer ids.

## Acceptance criteria

- [ ] Full PHPUnit green; no integer id in any Inertia page props or API response (a test walks the main pages' props and asserts every `id`/`*_id` value is a ULID).

## Comments
