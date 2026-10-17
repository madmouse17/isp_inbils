# ADR-003: Platform superadmin as a separate identity and authority

Status: Accepted

## Context

Inbils now hosts unrelated ISP tenants in one installation. Tenant users are scoped through `CompanyService` and `BelongsToCompany`, while Spatie role definitions are global because `teams=false`. Reusing tenant identities for operator access would mix two trust domains: a tenant session could become cross-tenant, company middleware would treat the operator as a tenant user, and an unrelated ISP could continue changing role definitions used by every ISP.

## Decision

Create a separate `platform_admins` table, Eloquent provider and `platform` session guard. Platform admins never belong to a company, never appear in `company_user`, never receive tenant roles, and enter through `/platform` with mandatory TOTP, a 30-minute idle timeout and explicit step-up confirmation for sensitive actions. Cross-tenant row access exists only in the platform layer. Single-tenant work uses `CompanyService::runAs()` or `forCompany()`; list metrics may use an explicit tenant-ID set and grouped `company_id` aggregates.

Impersonation keeps the platform identity as the real actor and opens the tenant `web` guard as the selected user for at most 30 minutes. It starts read-only. Write mode requires a fresh platform password and the tenant code. Tenant policies still authorize the acting user. Spatie activity rows record the `PlatformAdmin` as causer and use tenant-visible `support` entries for transparency.

Global role definitions move to the platform panel. Tenants may view seeded/custom roles and assign them to users, but cannot create, edit or delete role definitions. `spatie/laravel-permission` remains `teams=false`; per-tenant roles are a separate future decision.

## Alternatives considered

1. **`users.is_platform_admin` flag and the `web` guard.** Rejected. It combines unrelated identity domains, makes tenant middleware and session company state applicable to platform access, increases the chance of cross-tenant policy bypass, and lets password/2FA confirmation state bleed between roles.
2. **Spatie teams for per-tenant role definitions.** Rejected for this lane. It changes every role assignment/check, backfill and query in the application. Platform-owned global definitions close the current cross-tenant hole with a smaller cutover. Per-tenant roles remain parked.
3. **No impersonation; only direct platform tools.** Rejected. Complaint handling needs the exact tenant user's policy/UI view. Read-only-by-default impersonation gives that visibility while bounding writes and producing a durable audit trail.

## Consequences

- Platform authentication, TOTP challenge state, idle state and step-up confirmation are isolated from tenant authentication.
- Platform controllers must explicitly name every tenant they inspect. A missing tenant context continues to fail closed.
- Tenant role-definition routes become read-only; platform role changes affect every tenant and are audited.
- Platform and tenant sessions share Laravel session storage during impersonation. `sessions.user_id` cannot distinguish model types, so platform 2FA never deletes sessions by numeric user ID.
- `PLATFORM_DOMAIN` is optional. A separate host must be trusted and must preserve the session needed by impersonation.
- No IP allowlist, SaaS billing/plans, per-tenant roles, or mobile impersonation is included.
