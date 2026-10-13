# BACKEND: impersonation start/stop, read-only and write modes, audit

Status: ready-for-agent
Role: backend
Blocked by: 02
Spec: `../spec.md`

## Scope

Start/stop/mode endpoints (platform guard), session contract from the design, read-only middleware in the tenant web stack, forbidden targets/pages, 30-minute expiry, shared Inertia prop `impersonation` for the banner, `AuditService` real-actor recording (platform + tenant logs, SA7). Tests per spec.

## Acceptance criteria

- [ ] Focused PHPUnit green; Pint.

## Comments
