# ADR-004: Roles per company, permissions in one catalogue

Status: Accepted (owner decisions of 2026-10-19, lane `tenant-roles`). Supersedes the role clause of ADR-003.

## Context

ADR-003 kept `spatie/laravel-permission` on `teams=false` and let only the platform panel define roles, because one
role definition reached every tenant. Three facts no longer fit that model:

- ISPs name and shape their duties differently (owner, 2026-10-19).
- A user who works for two companies needs different duties in each.
- About 50 business rules checked role NAMES, so renaming a role broke ticket assignment, evaluation, the technician
  report, the app-only redirect and the customer portal.

## Decision

1. **A role belongs to one company.** `roles.company_id` is NOT NULL with a foreign key; `roles.template_key` records
   provenance. `App\Models\Role` extends the spatie model and uses `BelongsToCompany`, so every role query is scoped by
   `CompanyService::currentId()` and fails closed without a company. Spatie stays `teams=false`.
2. **Permissions stay one catalogue in code** (`App\Support\PermissionCatalogue`). Companies compose roles from it and
   never create permissions.
3. **Business rules check permissions, never role names.** Audiences have markers:
   - `app.web`: the back office;
   - `app.technician`: field technician;
   - `portal.access` plus the `customers.user_id` link: customer account;
   - `evaluation.evaluable`: an employee who can be evaluated.

   `report.technician.team` scopes the technician report. A company administrator is an active member holding
   `roles.manage`. Staff notices find their recipients with `StaffRecipients::holding()`, which names the company
   explicitly.
4. **Templates.** The platform maintains them (`role_templates`) and copies them into a company once, when it is
   created. Deploys never resync company roles. A new permission reaches existing companies through its release
   migration (`PermissionCatalogue::release`), which grants it only to the templates named and to their company copies.
5. **Tenant administration.** Holders of `roles.manage` create, rename, edit and delete their company's roles, within
   these guards:
   - nobody grants a guarded permission he does not hold;
   - nobody edits a role or a user richer than himself;
   - SoD invariants hold per company;
   - the company keeps at least one active `roles.manage` holder with web access;
   - a role with users is deleted only after they are moved.

   Every change is audited in the `security` log: `role.created`, `role.updated` and `role.deleted`, with the diff.
6. **Platform.** The platform edits templates and repairs a company's roles as an audited support action, which writes
   `platform` rows and tenant-visible `support` rows. Platform admins still never hold tenant roles.
7. **Cutover.** Every global role is copied into every company. Each assignment is remapped to the copy in the user's
   home company and in every company he is a member of. A migration self-check and a test prove that each user's
   effective permissions in each company are unchanged.

## Alternatives considered

1. **Spatie teams mode (`team_id` = company id).** Rejected:
   - a role with a NULL team is global in every team, so one stray row crosses tenants;
   - `Role::query()` stays unscoped, so tenant code must filter by hand;
   - the pivot keys and the direct permissions need a wider remap.

   It offers nothing the company-scoped model lacks, because the team id would have to come from
   `CompanyService::currentId()` anyway.
2. **Keep global roles defined only on the platform (ADR-003).** Rejected by the owner: companies need their own names
   and duties.
3. **Customer accounts without roles, with permissions derived from the customer link.** Rejected: the owner keeps a
   Pelanggan template so that each company can tune what its customers may do.

## Consequences

- A permission check outside a request of the right company (app login, jobs, commands, webhooks) must run inside
  `CompanyService::runAs`, or use `User::withPermissionIn($permissions, $companyId)` or `StaffRecipients::holding()`.
  Without a company, no role matches.
- `User` drops a cached `roles` relation that was loaded for another company. The spatie permission cache stays global
  and grows with the number of company roles.
- `roles` becomes a tenant table, so tenant export and purge include it.
- Direct user permissions are not company-scoped. No code writes them; this is an accepted residual.
- Routes:
  - tenant role CRUD under `/admin/roles`;
  - roles per member under `/admin/users/{user}/roles`;
  - platform templates under `/platform/role-templates`;
  - platform repairs under `/platform/companies/{company}/roles`;
  - `/platform/roles` is removed.

## What changed in AGENTS.md Company Rules

- **Replaced:** "Role/permission tetap global (`spatie teams=false`). Definisi role ... HANYA dari platform panel ...
  `RolePolicy` menolak seluruh write definisi role." The new text covers roles per company, the catalogue in code,
  templates copied at creation, tenant administration within the guards above, and platform repair.
- **New rule:** business code checks permissions, never role names. `hasRole`, `->role()` and a role-name `whereHas`
  are forbidden in `app/` and `Modules/`, enforced by an architecture test. The audience markers above take their place.
- **New rule:** a permission check without a signed-in user of the company runs in `CompanyService::runAs`, or uses
  `withPermissionIn` or `StaffRecipients::holding`.
- **Changed:**
  - `Role` is no longer an exception to `BelongsToCompany`.
  - The initialization rule seeds the permission catalogue and the role templates, and no roles.
  - Assigning roles to a member needs membership of the current company only. Changing login fields still needs
    membership of every company of the target.
