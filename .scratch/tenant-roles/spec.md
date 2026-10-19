# Roles per company: each ISP names and shapes its own roles

Status: in-progress
Ledger: `docs/plans/INBILS_OMP_BACKLOG.md` → "ISP roadmap lanes (2026-10-12)" row `tenant-roles`
Source: owner 2026-10-19, during the K15 discussion of the flow audit: "bukankah role permision dikita itu sudah dinamis?", chose "Fitur berbasis hak akses + template NOC sekali buat", then "harusnya buat dinamis saja, karena penamaan atau tugas tiap company kemungkinan akan berbeda". Facts at the start: roles are global (spatie `teams=false`), written only from `/platform/roles` (`PlatformRoleService`, audited); the five built-in roles (`PlatformRoleService::PROTECTED`) are resynced by `RolePermissionSeeder` on every deploy; about 49 places check role NAMES instead of permissions (e.g. `AssignTicketRequest:42`, `EmployeeEvaluation::EVALUABLE_ROLES`, `TechnicianReportAccess`, `AppOnlyController`, `EnsureCustomerAccess`); a user holds the same roles in every company he belongs to.

This lane supersedes the AGENTS.md Company Rules bullet "Role/permission tetap global (spatie teams=false). Definisi role ... HANYA dari platform panel" and the role clause of `docs/architecture/decisions/ADR-003-platform-superadmin.md`: a new ADR records the change, and AGENTS.md and `docs/SECURITY.md` are updated in the same release.

## Owner decisions (recommended options, accepted 2026-10-19)

1. Roles belong to a company. Each company has its own set of roles, with its own names and permissions. A user who belongs to several companies holds roles per company (Admin in A, Teknisi in B).
2. Permissions stay one global catalogue defined in code. Companies compose roles from it; they never invent permissions.
3. The app checks permissions, never role names, for every business rule: ticket assignee, evaluable employee, technician report scope, approvals, pickers and so on. The audiences that are not roles get their own marker: a customer account is a user linked to a customer record (portal permissions), a technician app user holds an app permission. No feature breaks when a company renames or deletes a role.
4. Templates: a platform-maintained set of starter roles is copied into each new company when it is created (Admin, Manajer, NOC & Layanan Pelanggan, Teknisi, Gudang, Keuangan, Staf, Pelanggan). After the copy the company owns its roles: deploys never resync them. "NOC & Layanan Pelanggan" holds customer registration, complaint intake and tickets, outage announcements, network view and trace, RADIUS resync and manual isolir; no delete, no billing writes, no user or role administration (K15). Staf no longer holds `customer.create`.
5. Who manages: a tenant user holding `role.manage` (the Admin template has it) creates, renames, edits and deletes his company's roles in Pengaturan → Peran. Guards: nobody grants a permission he does not hold himself; the company always keeps at least one active user holding `role.manage` (no self-lockout); a role still assigned cannot be deleted until its users are moved; the separation-of-duties invariants of `docs/SECURITY.md` (`NoStackedSodConflict`) hold inside every company; every change is audited in the tenant audit (log `security`: `role.created`, `role.updated`, `role.deleted` with the permission diff).
6. Platform panel: the operator sees each company's roles, maintains the templates, and can repair a company's roles as an audited support action. Platform admins still never hold tenant roles.
7. Cutover keeps everyone's access exactly as today: the global roles are copied into every existing company, and every user's role assignments are remapped to the copies in each company he belongs to. A test proves that every user's effective permissions in every company are unchanged by the migration.
8. Security review (`security-reviewer`) is mandatory before release: privilege escalation, cross-company leakage of roles or assignments, permission cache per company, customer and platform separation.

## Non-goals

Custom permissions defined by tenants, role hierarchies or inheritance, per-field permissions, time-limited role grants.

## Tickets

01-design (architect: ADR + design with slices) · slices per the design · QA · security review · docs (AGENTS.md Company Rules, `docs/SECURITY.md` RBAC matrix, `docs/user-guide/web.md`, ADR).
