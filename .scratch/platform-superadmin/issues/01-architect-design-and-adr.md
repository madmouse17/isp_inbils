# ARCHITECT: design, contracts, ADR and rule amendments

Status: done
Role: architect
Blocked by: field-trip-status merged (spec R8)
Spec: `../spec.md`

## Scope

Produce `.scratch/platform-superadmin/design.md` (tracked; the lane runs in a later session) and commit-ready docs: ADR-003 (decision, alternatives: users flag vs separate guard; spatie teams vs platform-owned roles; consequences), AGENTS.md "Company Rules" amendment text, `docs/SECURITY.md` section text. Design must name: `platform_admins` schema and guard/provider config, middleware stack for `/platform` (auth:platform, 2FA-enrolled, idle timeout; no IP allowlist), impersonation session contract (keys, start/stop/expiry, read-only middleware placement in the web stack, how `AuditService` records the real actor), tenant suspension enforcement points (login, `RequireHasCompany`, API `EnsureApiUserIsActive`), export job contract, every page's Inertia props, route names, and the slice/file ownership for tickets 02–07.

## Acceptance criteria

- [ ] Design doc + ADR + amendment texts reviewed by `security-reviewer` (sign-off recorded here) before tickets 02–07 start.

## Comments

2026-10-15, orchestrator: design written by PlatformArchitect2 and copied to `.scratch/platform-superadmin/design.md` (1035 lines: data model, API/route contracts, UI contract, test plan, risks, work breakdown for tickets 02–07, fenced ADR-003 / AGENTS.md Company Rules / SECURITY.md texts). Trial data decision: separate company-explicit queued `DemoDataSeeder::seedFor` with random undisclosed credentials; never `DemoUserSeeder` fixed accounts, no `NetworkAccessDemo`, no WhatsApp consent. Security sign-off pending (ticket 08 reviewer reads design first).
