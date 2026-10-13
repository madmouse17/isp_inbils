# UI: links, route() calls and forms use public ids

Status: ready-for-agent
Role: ui
Blocked by: 02
Spec: `../spec.md`

## Scope (owned files)

- Every page/composite that builds a URL or posts an id: `route('…', row.id)` keeps working when `id` is the ULID; fix every place that uses a non-`id` integer (e.g. `customer_id` from a nested object, numeric parsing like `Number(id)`, `parseInt`, `Number.isInteger` checks on ids, `id > 0` guards) and TS types (`id: number` → `string` for records).
- E2E helpers and specs that build URLs from ids or assert numeric ids (`/\/admin\/customers\/(\d+)$/` patterns) → ULID patterns; new spec asserting no integer record path in main list links.

## Acceptance criteria

- [ ] `tsc` clean with record ids typed as `string`; ESLint clean; E2E specs type-check.

## Comments
