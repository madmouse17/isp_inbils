# BACKEND: drop dead SLA fields and settings; ticket category admin

Status: ready-for-agent
Role: backend
Blocked by: none
Spec: `../spec.md`

## Scope (owned files)

- Migration dropping `sla_tiers.uptime_pct`, `sla_tiers.credit_pct` (reversible) and removing `ticket_default_sla_hours`, `ticket_sla_mode` from every `companies.settings` json (reversible no-op down is fine; note it).
- `Modules/Service/**` SLA tier model/requests/resource/controller/export: remove the two fields; index controller adds per-tier computed compliance (spec S3; one grouped query).
- Seeders: `CompanySeeder`, `ServiceCatalogSeeder`, demo seeders, `E2eFixtureSeeder` (drop the removed fields); `SetupWizardService::defaultSettings` (drop the two keys).
- Ticket categories admin (D1): controller, requests, policy, routes `admin.ticket-categories.*` under the Ticketing module, permission `ticket.category.manage` in `RolePermissionSeeder` (admin, manager) + `docs/SECURITY.md` §2.3 row; Inertia page props contract for ticket 03: `categories: {id, code, name, default_sla_hours, default_response_hours, is_active}[]`.
- Tests: spec testing decisions items 3–5.

## Acceptance criteria

- [ ] Focused PHPUnit green; Pint; migrations up/down.

## Comments
