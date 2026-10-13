# BACKEND: tenant list/detail data, provisioning, suspension, deletion request, platform-owned roles

Status: ready-for-agent
Role: backend
Blocked by: 02
Spec: `../spec.md`

## Scope

Platform controllers/queries for companies (list metrics, detail), `ProvisionTenantAction` (wizard services + admin user + invitation mail + optional demo data), suspend/reactivate actions reusing the 2026-10-14 deactivated-company enforcement (login refusal, request fallback, API 403, token revocation already exist; add only the platform actions, reason and audit), deletion request (30-day rule, export required), `inbils:platform:purge-company`, `RolePolicy` change (tenant role writes denied, platform role editor endpoints). Tests per spec.

## Acceptance criteria

- [ ] Focused PHPUnit green incl. a two-tenant fixture; Pint.

## Comments
