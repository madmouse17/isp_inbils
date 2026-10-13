# ARCHITECT: design, contracts, ADR and rule amendments

Status: ready-for-agent
Role: architect
Blocked by: field-trip-status merged (spec R8)
Spec: `../spec.md`

## Scope

Produce `.scratch/platform-superadmin/design.md` (tracked; the lane runs in a later session) and commit-ready docs: ADR-003 (decision, alternatives: users flag vs separate guard; spatie teams vs platform-owned roles; consequences), AGENTS.md "Company Rules" amendment text, `docs/SECURITY.md` section text. Design must name: `platform_admins` schema and guard/provider config, middleware stack for `/platform` (auth:platform, 2FA-enrolled, idle timeout; no IP allowlist), impersonation session contract (keys, start/stop/expiry, read-only middleware placement in the web stack, how `AuditService` records the real actor), tenant suspension enforcement points (login, `RequireHasCompany`, API `EnsureApiUserIsActive`), export job contract, every page's Inertia props, route names, and the slice/file ownership for tickets 02–07.

## Acceptance criteria

- [ ] Design doc + ADR + amendment texts reviewed by `security-reviewer` (sign-off recorded here) before tickets 02–07 start.

## Comments
