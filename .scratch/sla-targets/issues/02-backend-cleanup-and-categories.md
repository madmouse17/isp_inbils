# BACKEND: drop dead SLA fields and settings; ticket category admin

Status: done
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

- [x] Focused PHPUnit green; Pint; migrations up/down.

## Comments

SlaAdmin 2026-10-14:
- Added: migrations `Modules/Service/database/migrations/2026_10_14_100000_drop_uptime_and_credit_from_sla_tiers_table.php` (down re-adds nullable) and `database/migrations/2026_10_14_100001_remove_dead_ticket_sla_settings_from_companies.php` (down = documented no-op); `Modules/Service/app/Services/SlaTierCompliance.php` (one grouped query, tickets created in last 30 days, company scope via Ticket global scope); category screen `Modules/Ticketing/app/Http/{Controllers/TicketCategoryController,Requests/UpdateTicketCategoryRequest}.php` + `Policies/TicketCategoryPolicy.php`, routes `admin.ticket-categories.{index,update}`, permission `ticket.category.manage` (admin, manager), `docs/SECURITY.md` row, policy registered in `AppServiceProvider`.
- Tier index rows: `resolution_on_time_rate_30d`, `response_on_time_rate_30d` (one decimal | null), `tickets_30d`. Rate = on time / tickets carrying target and result timestamp; null when none.
- Tests: `tests/Feature/Service/SLATierComplianceTest.php`, `tests/Feature/Ticketing/TicketCategoryAdminTest.php`, `tests/Feature/SlaCleanupMigrationTest.php`.
- `php artisan test --compact` (SLATierCompliance, TicketCategoryAdmin, SlaCleanupMigration, SLATierServerTableExport, AdminMenuAuthorization, PhaseDResourceSmoke, AdminPermissionRole, DemoDataSeeder) -> 61 passed (2449 assertions). Pint passed. `migrate --pretend` lists both new migrations.
