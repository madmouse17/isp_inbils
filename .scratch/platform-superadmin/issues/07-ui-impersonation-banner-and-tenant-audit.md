# UI: impersonation banner, read-only feedback, tenant audit entries, role editor removal for tenants

Status: ready-for-agent
Role: ui
Blocked by: 04
Spec: `../spec.md`

## Scope

Banner in `AdminLayout` (and `GuestLayout` pages a tenant user can see), countdown, mode, Keluar; read-only 403 toast copy; tenant Audit Log shows "Akses dukungan" entries; tenant Peran pages become read-only (assign roles to users still allowed in Pengguna). E2E `tests/e2e/impersonation.spec.ts`.

## Acceptance criteria

- [ ] `tsc`, ESLint clean; E2E spec type-checks.

## Comments
