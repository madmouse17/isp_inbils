# Design: roles per company (lane `tenant-roles`)

Status: draft for owner/orchestrator approval · Architect: TenantRolesDesign · 2026-10-19
Spec (decisions fixed): `.scratch/tenant-roles/spec.md` · ADR draft: Appendix A · AGENTS.md text: Appendix B

Sequencing (Main, 2026-10-19): implement this lane after wave 1 of `flow-audit-fixes` (`.scratch/flow-audit-fixes/design-w1.md`, batches B1–B4) has landed. That wave edits files this lane also touches: `AppServiceProvider`, `User`, `SetupWizardService`, `PaymentAttemptService`, `TenantExportBuilder`, and the ticket, SPK and inventory services. It neither adds nor removes role-name checks, so the inventory in §2.3 stays valid. Its recipient helper `app/Support/Notifications/StaffRecipients.php` already exists in the tree. This lane adapts that helper and no other recipient query (Q1). Line numbers below are as of 2026-10-19; each slice re-locates them with the grep in §2.3 and anchors on function names.

Terminology: the spec's `role.manage` is the existing catalogue permission `roles.manage` (`database/seeders/RolePermissionSeeder.php:16`). No second permission is created.

## 1. Problem statement

Facts in the code today:

- `spatie/laravel-permission` 6.25.0 runs with `'teams' => false` (`config/permission.php:139`). `roles` is one global table, unique `(name, guard_name)`.
- Role definitions are written only by `App\Services\Platform\PlatformRoleService`, from `/platform/roles`. `RolePermissionSeeder` calls `syncPermissions()` on the five roles `admin, manager, staff, technician, customer` on every run (`PlatformRoleService::PROTECTED`).
- A user holds one role set. It applies in every company he belongs to: his home company (`users.company_id`) plus every row of `company_user` (`CompanyMembershipService::companyIdsOf`).
- About 50 places decide business rules by role NAME (inventory in §2.3), so renaming a role breaks features.

Target, per owner decisions 1–8:

- Roles belong to a company. Permissions stay one code catalogue.
- Business rules check permissions only. Web access, the technician app and customer accounts each get an audience marker.
- A platform template set is copied into each new company and never resynced.
- Tenant `roles.manage` holders manage their own roles, with guards.
- The platform maintains the templates and repairs company roles, with an audit trail.
- The cutover keeps every user's effective permissions in every company, and a test proves it.
- A security review happens before release.

Non-goals (spec plus this design):

- Tenant-defined permissions, role hierarchies, per-field permissions, time-limited grants.
- Any mobile app change, any `api/v1` payload change.
- Renaming the `RedirectAppOnlyRoles` class or its `redirect.app.only` alias.

## 2. Affected modules

### 2.1 Paths

| Area | Existing paths changed | New paths | Slice |
|---|---|---|---|
| Permission models, config | `config/permission.php`, `app/Models/User.php`, `app/Providers/AppServiceProvider.php` | `app/Models/Role.php`, `app/Models/Permission.php`, `app/Models/RoleTemplate.php`, `app/Support/PermissionCatalogue.php` | S1 |
| Migrations | — | 6 files `database/migrations/2026_10_28_1000xx_*` (§3.3) | S1 |
| Seeders | `database/seeders/RolePermissionSeeder.php`, `database/seeders/CompanySeeder.php` | — | S1 |
| Company creation, customer accounts, memberships | `app/Services/Core/{SetupWizardService,CustomerService,CompanyMembershipService}.php` | — | S1 |
| SoD rules | `app/Rules/{NoSodConflict,NoStackedSodConflict}.php` | — | S1 |
| Role-name checks → permissions, company context, recipient helper | the S2 rows of §2.3; `app/Support/Notifications/StaffRecipients.php` | `app/Rules/HoldsPermission.php` | S2 |
| Tenant role and user-role management (backend) | `app/Http/Controllers/Admin/{RoleController,UserController}.php`, `app/Policies/{RolePolicy,UserPolicy}.php`, user FormRequests, `app/Http/Resources/{RoleResource,PermissionResource,UserResource}.php`, `app/Support/UserDeletionGuard.php`, `routes/admin.php` | `app/Services/Core/CompanyRoleService.php`, `app/Http/Controllers/Admin/UserRoleController.php`, `app/Http/Requests/Admin/Roles/*`, `app/Http/Requests/Admin/UpdateUserRolesRequest.php` | S3 |
| Platform: templates, per-company roles, repair, export | `app/Queries/Platform/TenantDetailQuery.php`, `app/Services/Platform/{SupportToolsService,TenantExportBuilder}.php`, `app/Actions/Platform/AddTenantSubsidiaryAction.php`, `routes/platform/roles.php`, platform pages | `RoleTemplateService`, `RoleTemplateController`, `CompanyRoleController` and their requests; pages `Platform/RoleTemplates/*`, `Platform/Companies/Roles/*` | S4 |
| Tenant UI | `resources/js/Pages/Admin/{Roles,Users}/*`, `Components/composite/{PermissionGroupSelector.tsx,permissionGroups.ts,permissionLabels.ts,permissionGroups.selfcheck.mjs}`, `lib/statuses/audit.ts` | `Admin/Roles/{Create,Edit}.tsx`, `Admin/Roles/components/*`, `Admin/Users/components/AssignRolesDialog.tsx` | S5 |
| Test suite, E2E, demo data, docs | §8 | §8 | T1, T2, T3, D1 |

The mobile repo `/home/madmouse/Documents/web/inbils-mobile` needs no change. A grep of `packages/inbils_core/lib`, `technician/lib` and `customer/lib` shows the Dart code reads only `app`, `customer` and `employee` from `/api/v1/me`, never roles or permissions.

### 2.2 New catalogue permissions (audience and scope markers)

| Permission | Label (`permissionLabels.ts`) | What it decides | Templates holding it | Cutover grant (M3, on the global roles before they are copied) |
|---|---|---|---|---|
| `app.web` | Masuk aplikasi web (back-office) | Every `/admin` route (`redirect.app.only`) | Admin, Manajer, NOC, Gudang, Keuangan, Staf | Every global role except `technician` and `customer`. Also a direct grant to each user with no role and at least one direct permission. |
| `app.technician` | Teknisi lapangan (aplikasi Teknisi) | Who is a subject of the technician report, and its self scope; which app the "use the app" page names | Teknisi | `technician` |
| `portal.access` | Akun pelanggan (portal dan aplikasi Pelanggan) | Customer audience: the customer app and web portal (with the `customers.user_id` link), and customer scoping of tickets | Pelanggan | `customer` |
| `evaluation.evaluable` | Dapat dinilai di Evaluasi | Evaluable employee (picker and store) | Teknisi, Staf, NOC | `technician`, `staff`, `noc` (today's `EmployeeEvaluation::EVALUABLE_ROLES`) |
| `report.technician.team` | Laporan kinerja teknisi: unit organisasi sendiri | The manager's organization-subtree scope of the technician report | Admin, Manajer | `manager`, `admin` |

`App\Support\PermissionCatalogue` declares two classes, once:

- `UNGUARDED` = `app.technician`, `evaluation.evaluable`, `portal.access`, `portal.invoice.view`, `portal.invoice.pay`.
  - These classify a person or are customer-only. None of them opens anything by itself: the customer surfaces also need the `customers.user_id` link.
  - Anyone allowed to edit roles may place them.
  - They are exempt from "hold it yourself" (G1) and left out of the Admin template.
- Every other permission is guarded.

A company administrator is an active member who holds `roles.manage`. The lock-out guard (G5) requires `roles.manage` together with `app.web`, because an admin without web access cannot reach Peran.

### 2.3 Inventory of role-name checks and their replacements

The inventory was found with this grep over `app Modules routes database/seeders database/migrations`, plus a search of `resources/js`:

```
grep -rnE "hasRole\(|hasAnyRole\(|HasAnyActiveRole|->role\(|whereHas\('roles'|EVALUABLE_ROLES|getRoleNames|syncRoles|assignRole|APP_ROLES|PROTECTED"
```

"Parity" explains why every holder of a single built-in role keeps today's outcome after the cutover grants of §2.2.

| # | File:line | Today | Replacement | Parity | Slice |
|---|---|---|---|---|---|
| B1 | `app/Http/Controllers/AppOnlyController.php:19,24` | the technician role picks the technician app, anyone else the customer app | `match (true) { can('app.technician') => 'technician', can('portal.access') => 'customer', default => null }`; `null` shows the "no web access" page | technician copies hold `app.technician`, customer copies hold `portal.access` | S2 |
| B2 | `app/Http/Middleware/RedirectAppOnlyRoles.php:17,19-24` | redirect when roles ≠ ∅ and roles ⊆ {technician, customer} | `isAppOnly(User $u) = CompanyService::currentId() !== null && ! $u->can('app.web')`; `APP_ROLES` deleted | every copy except technician and customer holds `app.web`; company-less users still reach `require.has.company` (setup wizard) | S2 |
| B3 | `app/Http/Middleware/HandleInertiaRequests.php:59,66` | shares `auth.user.roles`; `appOnly` from B2 | drop `roles` from the shared props (F1 is its only consumer); `appOnly` = B2 | — | S2 |
| B4 | `app/Http/Middleware/EnsureCustomerAccess.php:29` | `customerFor`: role customer plus the link | `CompanyService::runAs($companyId, fn () => $user->can('portal.access') ? Customer::forCompany($companyId)->where('user_id', $user->id)->first() : null)` | customer copies hold `portal.access`; `runAs` makes it work at login | S2 |
| B5 | `app/Http/Middleware/EnsureCustomerAccess.php:40` | `abort_unless(hasRole('customer'))` | `abort_unless($user?->can('portal.access'), 403)` | same | S2 |
| B6 | `app/Http/Controllers/Admin/EvaluationController.php:111` | a technician sees only his own evaluations | without `evaluation.view`: own only; otherwise the lifecycle filter | the technician copy lacks `evaluation.view`; stacked roles: delta D1 | S2 |
| B7 | `app/Http/Controllers/Admin/EvaluationController.php:254` | picker: roles in `EVALUABLE_ROLES` | `->withPermissionIn('evaluation.evaluable', $companyId)`, keeping the `company_user` and `is_active` filters | M3 grants the marker to exactly those three names | S2 |
| B8 | `app/Http/Controllers/Admin/EvaluationController.php:267` | a technician's filter lists only himself | `can('evaluation.view') ? $employees : $employees->whereKey($user->id)` | as B6 | S2 |
| B9 | `app/Http/Requests/Admin/StoreEvaluationRequest.php:49` | `HasAnyActiveRole(EVALUABLE_ROLES)` | `new HoldsPermission(['evaluation.evaluable'])` | as B7 | S2 |
| B10 | `app/Models/Core/EmployeeEvaluation.php:30` | `EVALUABLE_ROLES` constant | deleted | — | S2 |
| B11 | `app/Policies/EmployeeEvaluationPolicy.php:18` | technician → own or 404 | `can('evaluation.view') ? true : (can('evaluation.view.own') ? ($eval->employee_id === $user->id ?: Response::denyAsNotFound()) : false)` | the technician copy holds `view.own` only | S2 |
| B12 | `app/Http/Requests/Admin/StoreUserRequest.php:24,31-33` | `exists:roles,name`, global; only an admin may give `admin` | `Rule::exists('roles','name')->where('company_id', current)->where('guard_name','web')`. The `after()` hook is deleted: `CompanyRoleService::syncUserRoles` refuses any role carrying guarded permissions the actor lacks (§4.4, G1). | admin holds every guarded permission. Non-admins could not give admin before; now they cannot give any role richer than themselves. | S3 |
| B13 | `app/Http/Requests/Admin/UpdateUserRequest.php:27,34-36` | same | same | same | S3 |
| B14 | `app/Http/Controllers/Admin/UserController.php:57,75,105,122-127,185` | global role list; `syncRoles` and a diff by name; the list holds users homed here | role list = current-company roles without `portal.access`; writes go through `syncUserRoles`; the list holds members (home ∪ `company_user`) | `App\Models\Role` scopes the role list automatically | S3 |
| B15 | `app/Policies/UserPolicy.php:69` | only an admin changes an admin | `change()` refuses unless `covers($actor, …)`: the target's guarded permissions in this company ⊆ the actor's. New ability `assignRoles`. | admin covers everyone; custom `users.manage` roles: delta D3 | S3 |
| B16 | `app/Policies/RolePolicy.php:15-50` | tenant writes always denied | `viewAny/view/create/store`: `roles.manage`; `update/edit/delete`: `roles.manage` and `covers($actor, role's guarded permissions)` | — | S3 |
| B17 | `app/Rules/HasAnyActiveRole.php:14-26` | role-name rule | deleted; replaced by `App\Rules\HoldsPermission` | — | S2 |
| B18 | `app/Rules/NoStackedSodConflict.php:18,28-31` | the admin role is exempt; roles are looked up by name across all companies | exempt when `$actor->can('roles.manage')`; `Role::query()` becomes `App\Models\Role`, so it sees the current company only | admin copies hold `roles.manage`, other copies do not | S1 |
| B19 | `app/Http/Requests/Platform/Roles/StoreRoleRequest.php:21`, `UpdateRoleRequest.php:33-36` | global unique name; protected names; SoD exempt for `admin` by name | files deleted. The template and company-role requests (§4.3) exempt from SoD any set that holds `roles.manage`. | `admin` is the only role with `roles.manage` | S4 |
| B20 | `app/Services/Platform/PlatformRoleService.php:18-27,61` | `PROTECTED` names, `SEEDED_WARNING` | file deleted; replaced by `RoleTemplateService` and `CompanyRoleService` | — | S4 |
| B21 | `app/Http/Controllers/Platform/RoleController.php:49,70-71` | `protectedRoles` props | file deleted; replaced by `RoleTemplateController` and `CompanyRoleController` | — | S4 |
| B22 | `app/Models/User.php:180` | mask the name when `hasRole('customer')` | `$this->isCustomerAccount \|\| Customer::withoutCompany()->withTrashed()->where('user_id', $this->id)->exists()` | the link exists in every context; a role check would depend on the ambient company | S1 |
| B23 | `app/Services/Core/CustomerService.php:34` | `assignRole('customer')` | `assignRole(Role::forTemplate('customer') ?? throw ValidationException::withMessages(['email' => 'Peran akun pelanggan (templat Pelanggan) tidak ada di perusahaan ini.']))` | the customer copy carries `template_key = customer`, survives renames and cannot be deleted (G6) | S1 |
| B24 | `app/Services/Core/SetupWizardService.php:51` | `assignRole('admin')` | `$roles = app(CompanyRoleService::class)->copyTemplates($company)`, then `CompanyService::runAs($company->id, fn () => $user->assignRole($roles['admin']))` | the wizard and platform provisioning share `createFor` | S1 |
| B25 | `app/Services/Platform/SupportToolsService.php:50` | invitation only when `hasRole('admin')` | `CompanyService::runAs((int) $user->company_id, fn (): bool => $user->can('roles.manage'))` | admin copies | S4 |
| B26 | `app/Queries/Platform/TenantDetailQuery.php:137-138,160,198` | admins = users with the role named admin; roles eager-loaded globally | admins = users whose home-company roles hold `roles.manage` (scope removed explicitly, `whereColumn('roles.company_id','users.company_id')`). Roles are loaded `withoutGlobalScope('company')` and filtered to the home company per row. | the platform has no tenant context, so the company must be named | S4 |
| B27 | `app/Queries/Admin/DashboardAdminStatsQuery.php:63` | `Role::query()->count()` counts the roles of every company | import `App\Models\Role`: counts the current company's roles | — | S2 |
| B28 | `Modules/Billing/app/Console/RotateLegacyCustomerPasswords.php:35` | `->role('customer')` | `->withPermissionIn('portal.access', (int) $companyId)` | customer copies | S2 |
| B29 | `Modules/Billing/app/Notifications/BillingScheduleFailedNotification.php:30` | `->role('admin')` (scheduler, no company) | `StaffRecipients::holding($companyId, PermissionCatalogue::ADMINISTRATION)` | admin copies; recipients now include members (delta D6) | S2 |
| B30 | `Modules/Reporting/app/Queries/TechnicianPerformanceQuery.php:29` | manager → organization subtree | `can('report.technician.team')` | M3 grants it to manager, and to admin, whose `report.manage` branch is checked first | S2 |
| B31 | `Modules/Reporting/app/Queries/TechnicianPerformanceQuery.php:46` | technician → self | `can('app.technician')` (the route already requires `report.technician`) | technician copies | S2 |
| B32 | `Modules/Reporting/app/Queries/TechnicianPerformanceQuery.php:144` | report subjects = users with the technician role | `->withPermissionIn('app.technician', $companyId)` | technician copies only | S2 |
| B33 | `Modules/Reporting/app/Services/TechnicianReportAccess.php:44` | manager branch | as B30, with the same precedence: a `.team` holder without a valid organization path is refused, as today | same | S2 |
| B34 | `Modules/Reporting/app/Services/TechnicianReportAccess.php:59` | technician branch | as B31 | same | S2 |
| B35 | `Modules/Reporting/app/Services/TechnicianReportAccess.php:70` | default target = the actor if he is a technician | `can('app.technician') ? $actor : null` | same | S2 |
| B36 | `Modules/Ticketing/app/Http/Controllers/TicketController.php:134` | customer → own customer's tickets, fail closed | `can('portal.access')` → `where('customer_id', $user->customer?->id ?? 0)` | customer copies; a holder without a link still sees nothing | S2 |
| B37 | `Modules/Ticketing/app/Http/Controllers/TicketController.php:156` | own customer id for customers | `can('portal.access')` | same | S2 |
| B38 | `Modules/Ticketing/app/Http/Controllers/TicketController.php:218` | internal comments hidden from customers | `can('portal.access')` | same | S2 |
| B39 | `Modules/Ticketing/app/Http/Requests/AssignTicketRequest.php:42` | `HasAnyActiveRole(['admin','manager','noc','staff','technician'])` | `new HoldsPermission(['ticket.start','ticket.resolve','ticket.close'])` | `TicketService::assign` (`TicketService.php:214-219`) already refuses anyone else; delta D4 | S2 |
| B40 | `Modules/Ticketing/app/Http/Requests/StoreTicketCommentRequest.php:17` | customers comment publicly | `can('portal.access')` | customer copies | S2 |
| B41 | `Modules/Ticketing/app/Http/Requests/StoreTicketRequest.php:101` | the customer's own customer id | `can('portal.access')` | same | S2 |
| B42 | `Modules/Ticketing/app/Policies/TicketPolicy.php:199` | `isCustomerUser` = role customer | `can('portal.access')` | same | S2 |
| B43 | `database/seeders/RolePermissionSeeder.php:217-335` | creates and resyncs five global roles | the catalogue (`PermissionCatalogue::ALL`) plus missing templates; never touches roles | — | S1 |
| B44 | `database/seeders/DemoUserSeeder.php:58`, `DemoTeamSeeder.php:81`, `Demo/FoundationDemo.php:177,214`, `E2eFixtureSeeder.php:92` | assign by name; a subsidiary membership gets no roles | `copyTemplates` first, then `assignRole(Role::forTemplate($key))` inside the company; `mirrorTemplateRoles` after a subsidiary grant | the demo personas keep their template | T3 |
| B45 | `app/Policies/CompanyPolicy.php:31-35` | switching needs `company.switch` ("roles stay global") | `canActIn(...) && ((int) $company->id === (int) $user->company_id \|\| $user->can('company.switch'))`. Going home is always allowed, so a user who lacks the permission in the company he switched into is not stuck. | at cutover every membership has the same copies | S2 |
| B46 | `app/Services/Core/CompanyMembershipService.php:13,66-79` | "Roles stay global"; revoke leaves the roles | revoke runs in one transaction: lock the company, `runAs($company->id, fn () => $user->syncRoles([]))`, then `assertAdministratorRemains`. Docblock updated. | — | S1 |
| F1 | `resources/js/Pages/Admin/Reports/AuditLog.tsx:174-178` | `roles.includes('admin' \| 'super-admin')` | `permissions.includes('report.audit.view')` | admin copies hold it | S2 |
| F2 | `resources/js/Pages/Admin/Roles/Index.tsx:34-37` | `PROTECTED_ROLES` names | deleted (tenants get CRUD) | — | S5 |
| F3 | `resources/js/Pages/Platform/Roles/Index.tsx:28,106` | `protectedRoles` | page deleted (replaced by the template editor) | — | S4 |
| F4 | `resources/js/Pages/AppOnly.tsx:5` | `'technician' \| 'customer'` | `'technician' \| 'customer' \| null`, plus a "no web access" state | B1 | S2 |
| F5 | `resources/js/types/index.ts:51,126` | `auth.user.roles`; the appOnly docblock talks about roles | `roles` removed; docblock: "lacks `app.web` in the current company" | B3 | S2 |

The following permission-holder queries are already permission-based. With per-company roles, their result depends on the company context they run in:

| # | Where | Context | Action | Slice |
|---|---|---|---|---|
| Q1 | `app/Support/Notifications/StaffRecipients.php::holding()`, which already exists (flow-audit wave 1): active members (home or a `company_user` row) whose direct permissions or `roles.permissions` hold the permission. After that wave it is the single recipient query of `PaymentAttemptService::alertBillingStaff`, `InventoryRequestService`, `MaterialReturnService`, `CheckTicketSlaBreachesJob` and every new staff notice. | webhooks, `DB::afterCommit`, queued jobs, the scheduler: often nobody is signed in | Replace the `whereHas('permissions') … orWhereHas('roles.permissions')` clause with `->withPermissionIn($permission, $companyId)`. The `roles` relation follows the ambient company, so today's clause matches nobody when no company is set. Inside a request of company A it would also count a member's roles in A, not in the company being notified. Keep the membership filter, `is_active` and `$exceptUserId`. Move any recipient query that wave 1 left inline onto the helper. | S2 |
| Q2 | `app/Services/Core/ApiTokenService.php:64-77` | app login, nobody authenticated | the body of `ensureMayUse` runs in `CompanyService::runAs(CompanyService::idFor($user))`; a null id is refused, as today | S2 |
| Q3 | `app/Http/Middleware/EnsureCustomerAccess.php:22-34` | login and `MeResource` | B4 | S2 |
| Q4–Q10 | `app/Queries/Admin/CustomerAcquirerQuery.php:38-40`, `app/Queries/Admin/DispatchQueueQuery.php:319-321,347-349`, `app/Rules/IsActiveTechnician.php:25-27`, `Modules/SPK/app/Http/Controllers/WorkOrderController.php:420-422`, `Modules/SPK/app/Services/SpkService.php:181-183,787-788`, `Modules/Ticketing/app/Services/TicketService.php:214-219` | inside a request of the company they query | unchanged: the role scope equals the queried company. `RoleNameIndependenceTest` exercises them. | — |

Two historical migrations stay unchanged on purpose: on every database they run before M2. They are `database/migrations/2026_10_12_600004_flag_existing_customer_users_for_password_change.php:27-32` and `2026_10_26_100100_add_company_integrations_manage_permission.php:20`.

These imports of `Spatie\Permission\Models\Role` must become `App\Models\Role`; the unscoped parent class would leak every company's roles:

- `app/Http/Controllers/Admin/RoleController.php:18` and `UserController.php:25` (S3)
- `app/Policies/RolePolicy.php:6` (S3)
- `app/Providers/AppServiceProvider.php:110` (S1)
- `app/Queries/Admin/DashboardAdminStatsQuery.php:18` (S2)
- `app/Rules/NoStackedSodConflict.php:9` (S1)
- `database/seeders/RolePermissionSeeder.php:7` (S1)
- the platform files, which are deleted (S4)

`Spatie\Permission\Models\Permission` imports may stay, because permissions are global. `RoleNameChecksArchitectureTest` (S2) fails on any new role-name decision and on any spatie `Role` import.

## 3. Data model / migrations

### 3.1 Mechanism: company-scoped role rows (chosen) vs spatie teams

**Chosen: company-scoped role rows.** `App\Models\Role extends Spatie\Permission\Models\Role` and uses the existing `App\Traits\BelongsToCompany`; spatie stays `teams=false`. The trait's global scope is the "custom resolver": it reads `CompanyService::currentId()` on every role query. Web, API, queue and console therefore need nothing set per entry point.

Evidence from `vendor/spatie/laravel-permission` 6.25.0:

| Criterion | Spatie teams (`team_foreign_key = company_id`, resolver returning `CompanyService::currentId()`) | Company-scoped rows (chosen) |
|---|---|---|
| Schema | `company_id` on `roles` and in the primary keys of `model_has_roles` and `model_has_permissions` (`create_permission_tables.php:74-95`) | `roles.company_id` only: a role row already names its company, so the pivots need nothing |
| Team id per entry point | `setPermissionsTeamId` on web, API, queue, console and inside `runAs`, unless a custom `PermissionsTeamResolver` reads `CompanyService::currentId()` | nothing to set: the scope calls `CompanyService::currentId()`, the app's single authority (runAs, session switch, home) |
| Tenant queries on roles | `Role::query()` is unscoped (`Models/Role.php:168-181` only filters `findByParam`). `NoStackedSodConflict`, `DashboardAdminStatsQuery`, the user forms and the role pages would each need a manual company filter. | fail closed, like every tenant table: no company → `whereIn(company_id, [])`. Platform code opts out with `withoutCompany()`. |
| A role without company | teams treat `team_id NULL` as a GLOBAL role, visible and assignable in every team (`Traits/HasRoles.php:68-69`). One stray row is a cross-tenant hole. | impossible: `company_id` is NOT NULL with a foreign key (M6) |
| Multi-company users | one pivot row per (team, role, user) | one pivot row per (company role, user); the user's roles in company C are the rows whose role has `company_id = C` |
| Active-company switch | team id must follow the switch | follows `CompanyService::currentId()` automatically |
| Platform guard | platform admins have no `HasRoles` | same; per-company views name the company explicitly |
| `can()` hot path | `roles` relation filtered by pivot team and role team; registrar cache of all permissions with their roles (`PermissionRegistrar.php:197-233,337-360`) | `roles` relation filtered by `roles.company_id = ?` (indexed); the same registrar cache. `hasPermissionViaRole` = `hasRole($permission->roles)`, an Eloquent `intersect` by primary key (`HasRoles.php:298-300`), linear in the number of roles holding the permission. |
| Cached relation after a company change | stale; the spatie docs require `unsetRelation('roles')` | stale too; fixed once in `User` (§3.7) |
| Direct user permissions | become per team (needs a data remap) | stay per user (no code writes them; residual R4) |
| `syncRoles` | team-scoped | detaches only the current company's roles: with `permission.events_enabled = true`, `syncRoles` removes `$this->roles()->get()` (scoped) by id (`HasRoles.php:226-237`). A test pins this. |

Teams mode is rejected for three reasons: the scoping hole of NULL-team roles, the unscoped `Role::query()`, and the wider schema and data remap. Neither option avoids the relation-cache or registrar-cache work.

`App\Models\Permission extends Spatie\Permission\Models\Permission` overrides `roles()` as `parent::roles()->withoutGlobalScope('company')`. The registrar builds its cache with `Permission::with('roles')` (`PermissionRegistrar.php:292-295`); a scoped relation would cache only the roles of the company whose request happened to fill the cache.

### 3.2 Schema

`roles` (spatie table; existing columns `id, public_id, name, guard_name, created_at, updated_at`):

| Column / index | Type | Notes |
|---|---|---|
| `company_id` | bigint unsigned NOT NULL, FK `companies.id` ON DELETE CASCADE | nullable between M2 and M6 |
| `template_key` | varchar(32) NULL | key of the template the role was copied from. Provenance only: it survives renames and is never edited. |
| unique `(company_id, name, guard_name)` | | replaces `roles_name_guard_name_unique`; case-insensitive by collation |
| unique `(company_id, template_key)` | | NULLs may repeat; one copy per template per company |

`role_templates` (NEW). It is platform-owned and has no `company_id`, so `TenantTableRegistry` ignores it.

| Column | Type |
|---|---|
| `id` | bigint pk |
| `public_id` | ulid unique |
| `key` | varchar(32) unique, immutable: `admin`, `manager`, `noc`, `technician`, `warehouse`, `finance`, `staff`, `customer`, or a slug for operator templates |
| `name` | varchar(255) unique |
| `created_at`, `updated_at` | timestamps |

`role_template_has_permissions` (NEW): `role_template_id` FK cascade, `permission_id` FK cascade, primary key `(role_template_id, permission_id)`.

`model_has_roles`, `model_has_permissions`, `role_has_permissions` and `permissions` do not change.

Effect on existing platform tooling:

- `TenantTableRegistry` treats any table with `company_id` as a tenant table, so `roles` joins tenant export and purge automatically.
- Purge deletes the tenant's roles. The foreign keys cascade to `model_has_roles` and `role_has_permissions`, including the rows of members homed elsewhere.
- Tenant export: S4 filters the `data/user_roles.csv` that flow-audit wave 1 adds to the tenant's companies. `role_has_permissions` is still not exported (R6).

### 3.3 Migrations (order, up, down)

DDL and DML live in separate files. Every migration touches roles with `DB::table()`, never with `App\Models\Role`, whose scope hides company-less rows.

| # | File (`database/migrations/`) | Kind | up | down |
|---|---|---|---|---|
| M1 | `2026_10_28_100000_create_role_templates_tables.php` | DDL | create `role_templates` and `role_template_has_permissions` | drop both |
| M2 | `2026_10_28_100100_add_company_columns_to_roles_table.php` | DDL | add `company_id` (nullable, FK) and `template_key`; drop unique `(name, guard_name)`; add unique `(company_id, name, guard_name)` and `(company_id, template_key)` | drop the two uniques; add unique `(name, guard_name)` back (valid only after M5 down); drop the FK and both columns |
| M3 | `2026_10_28_100200_add_access_permissions.php` | DML | `findOrCreate` the five permissions of §2.2 (`web`). Grant them to the GLOBAL roles by name, as listed in §2.2 (`insertOrIgnore` into `role_has_permissions`). Give a direct `app.web` to every user with no role and at least one direct permission. Forget the permission cache. | delete the five permissions (the pivots cascade); forget the cache |
| M4 | `2026_10_28_100300_create_default_role_templates.php` | DML | `findOrCreate` every name in `PermissionCatalogue::ALL`; insert each template of `PermissionCatalogue::templates()` whose key is missing, with its permissions | delete the templates whose key is one of the eight default keys |
| M5 | `2026_10_28_100400_copy_global_roles_into_companies.php` | DML, self-checked | §3.4 | §3.4 |
| M6 | `2026_10_28_100500_require_company_on_roles.php` | DDL | `company_id` NOT NULL (fails loudly if M5 left a global row) | nullable |

Flow-audit wave 1 adds migrations with the same timestamps, but in module directories (`Modules/Billing/database/migrations/2026_10_28_100000_*`, `Modules/Ticketing/database/migrations/2026_10_28_100100_*`). Laravel orders migrations by file name across all paths. The names differ, so the order is deterministic, and none of those migrations touches roles. S1 keeps these names unless a clash appears when wave 1 lands.

On a fresh database (tests, `migrate:fresh --seed`), M3 finds no global roles, M4 creates the whole catalogue and the eight templates, and M5 finds no companies. Templates therefore exist after `migrate` alone, so tests can copy them without seeding.

### 3.4 Cutover (M5): algorithm and self-check

For every user `u` and every company `c` in `C(u) = {users.company_id} ∪ {company_user.company_id}`:

- before(u, c) = direct(u) ∪ ⋃ perms(r) over the global roles r of u
- after(u, c) = direct(u) ∪ ⋃ perms(r) over the roles r of u with `r.company_id = c`

Every global role is copied into every company with the same permissions. For every global role `u` held, `u` gets the copy in every `c ∈ C(u)`. Together these give after = before for every pair.

Assignments of a user with `C(u) = ∅` cannot be mapped and are dropped. Such a user acts in no company (`CompanyService::currentId()` is null, so every role query matches nothing), so dropping them changes nothing he can do. They are counted in the log.

`up()` runs in one transaction. There is no DDL inside, so MySQL keeps it atomic.

1. `$global` = roles where `company_id` is null. Refuse with a `RuntimeException` when `model_has_roles` has a non-`App\Models\User` row on a global role.
2. Snapshot `before` into `CREATE TEMPORARY TABLE role_cutover_before (user_id, company_id, permission_id, PRIMARY KEY (...))` with one `INSERT … SELECT DISTINCT`. Temporary tables do not commit the transaction.
3. Names: copies of `admin`, `manager`, `staff`, `technician` and `customer` take the template names `Admin`, `Manajer`, `Staf`, `Teknisi` and `Pelanggan` (OD1), unless another global role already has that name (case-insensitive). Their `template_key` is the old name. Every other global role keeps its name and gets `template_key = null`.
4. For each company (all rows, active or not, heads and subsidiaries; `orderBy('id')`): insert one copy per global role (`public_id = PublicId::new()`, timestamps now) and bulk-insert its `role_has_permissions`. Keep `map[globalRoleId][companyId] = copyId`.
5. Remap: walk the `model_has_roles` rows of global roles in `chunkById`-style batches by `model_id`, and load `C(u)` per batch. For each `c ∈ C(u)`, `insertOrIgnore` `(map[role][c], App\Models\User, u)`. Count the rows with `C(u) = ∅`.
6. Delete the global roles; the foreign keys cascade their pivots.
7. Top-up (OD2): for each company, and each row of `role_templates` whose key has no role in that company and whose name is free there, create the role from the template, with no users.
8. Snapshot `after` into `role_cutover_after`, joining `roles.company_id = membership company`.
9. Compare both ways with `NOT EXISTS`. On any difference, `throw new RuntimeException("Role cutover would change the permissions of {n} user-company pairs; nothing was changed.")`, which rolls the transaction back.
10. `Log::info('roles.cutover', ['companies' => …, 'roles_copied' => …, 'assignments' => …, 'unmapped_assignments' => …])`; drop the temporary tables; `app(PermissionRegistrar::class)->forgetCachedPermissions()`.

`down()` serves the immediate rollback window and runs in one transaction. It refuses with "Roles changed per company since the cutover; restore the pre-deploy backup instead." when any of these holds:

- two companies hold different permission sets under the same role name;
- a company lacks a role name that another company has (ignoring top-up roles: `template_key` in `noc`, `warehouse` or `finance`, with no users);
- a user's role names differ between his companies;
- a top-up role has users.

Otherwise it creates one global role per name (the legacy name of the five is restored from `template_key`), copies its permissions, gives each user that global role once, deletes the copies, and forgets the cache.

Scale: 100 companies × 6 global roles ≈ 600 role rows and ≈ 30 000 `role_has_permissions` rows, which takes seconds on MySQL 8.4.

### 3.5 Verification queries (read-only, before and after deploy)

Run these in the MySQL client of the stack and save both outputs. A `diff` of the two must be empty. The five new permissions are excluded because M3 adds them on purpose; M5's own self-check compares exactly.

```sql
SET SESSION group_concat_max_len = 1048576;
WITH m AS (
    SELECT id AS user_id, company_id FROM users WHERE company_id IS NOT NULL
    UNION
    SELECT user_id, company_id FROM company_user
),
g AS (
    -- BEFORE deploy: roles are global, so they apply in every company of the user.
    SELECT m.user_id, m.company_id, rhp.permission_id
    FROM m
    JOIN model_has_roles mhr ON mhr.model_id = m.user_id AND mhr.model_type = 'App\\Models\\User'
    JOIN role_has_permissions rhp ON rhp.role_id = mhr.role_id
    UNION
    SELECT m.user_id, m.company_id, mhp.permission_id
    FROM m
    JOIN model_has_permissions mhp ON mhp.model_id = m.user_id AND mhp.model_type = 'App\\Models\\User'
)
SELECT m.user_id, m.company_id, COUNT(p.id) AS permissions,
       SHA2(COALESCE(GROUP_CONCAT(p.name ORDER BY p.name), ''), 256) AS fingerprint
FROM m
LEFT JOIN g ON g.user_id = m.user_id AND g.company_id = m.company_id
LEFT JOIN permissions p ON p.id = g.permission_id
     AND p.name NOT IN ('app.web', 'app.technician', 'portal.access', 'evaluation.evaluable', 'report.technician.team')
GROUP BY m.user_id, m.company_id
ORDER BY m.user_id, m.company_id;
```

AFTER deploy, use the same query with the first branch of `g` joined through the role's company:

```sql
    JOIN model_has_roles mhr ON mhr.model_id = m.user_id AND mhr.model_type = 'App\\Models\\User'
    JOIN roles r ON r.id = mhr.role_id AND r.company_id = m.company_id
    JOIN role_has_permissions rhp ON rhp.role_id = r.id
```

Before deploy, also run these two and give the results to the owner. Assignments that cannot be mapped:

```sql
SELECT COUNT(*) FROM model_has_roles mhr JOIN users u ON u.id = mhr.model_id
WHERE mhr.model_type = 'App\\Models\\User' AND u.company_id IS NULL
  AND NOT EXISTS (SELECT 1 FROM company_user cu WHERE cu.user_id = u.id);
```

Users affected by delta D1 (technician together with a role that grants `evaluation.view`):

```sql
SELECT DISTINCT mhr.model_id FROM model_has_roles mhr
JOIN roles r ON r.id = mhr.role_id AND r.name = 'technician'
WHERE EXISTS (SELECT 1 FROM model_has_roles o JOIN role_has_permissions rp ON rp.role_id = o.role_id
              JOIN permissions p ON p.id = rp.permission_id AND p.name = 'evaluation.view'
              WHERE o.model_id = mhr.model_id AND o.model_type = mhr.model_type);
```

### 3.6 Templates

**Defaults.** The defaults live in code: `PermissionCatalogue::templates()` (eight entries, §3.6.1). M4 inserts the missing ones, and `RolePermissionSeeder` does the same for fresh installs. Neither ever overwrites an existing template, so operator edits survive deploys.

**Copy at company creation.** `CompanyRoleService::copyTemplates(Company $company): array<string, Role>` returns `template_key => role`. It copies only when the company has no role yet (idempotent). Callers:

- `SetupWizardService::createFor` (Setup Wizard and platform provisioning), before it assigns Admin;
- `CompanySeeder::runFor`, which covers subsidiaries (through `CompanyCreated` → `SeedCompanyDefaults`) and `DemoUserSeeder`. It is a no-op when the wizard already copied.

**After the copy.** The company owns its roles. Nothing rewrites company roles from templates. "Mulai dari templat" (tenant) and "Tambah dari templat" (platform) each create a new role.

**New permissions in later releases (decision).** The release migration calls `PermissionCatalogue::release(string $permission, list<string> $templateKeys)`. It creates the permission, adds it to those templates, and grants it to every company role whose `template_key` is in the list. Nothing is removed or resynced.

Why:

- Without the company grant, nobody in an existing company would hold the permission, and nobody could grant it (G1). The feature would stay dead until a platform repair.
- `template_key` survives renames, so the company's Admin copy is found even when it is called "Pemilik".
- Custom roles are the tenant's design. Its administrator, who now holds the permission through the Admin copy, adds it in Peran.
- A company that deleted its Admin copy is fixed by a platform repair, which is audited.

Rejected:

- Resyncing templates on deploy: breaks decision 4.
- Granting to roles that hold an "anchor" permission: a guess per permission that silently widens custom roles.

#### 3.6.1 Default templates (display order)

| Key | Name | Permissions |
|---|---|---|
| `admin` | Admin | every catalogue permission except `UNGUARDED` (equals today's admin set + `app.web` + `report.technician.team`) |
| `manager` | Manajer | today's manager set (`RolePermissionSeeder.php:226`, which includes `ticket.assign`, required by flow-audit OD7) + `app.web`, `report.technician.team` |
| `noc` | NOC & Layanan Pelanggan | see the list below the table |
| `technician` | Teknisi | today's technician set (`:312`) + `app.technician`, `evaluation.evaluable` |
| `warehouse` | Gudang | `app.web`, `dashboard.view`, `location.view`, `inventory.view`, `inventory.create`, `inventory.update`, `inventory.export`, `inventory.stock.receive`, `inventory.stock.issue`, `inventory.stock.transfer`, `inventory.stock.adjust.request`, `inventory.request.view.all`, `inventory.request.prepare`, `inventory.request.issue`, `inventory.return.view.all`, `inventory.return.receive`, `spk.view`, `report.view`, `report.stock_card.view` (execution side only, OD4) |
| `finance` | Keuangan | `app.web`, `dashboard.view`, `customer.view`, `customer.subscription.view`, `billing.view`, `billing.create`, `billing.update`, `billing.send`, `billing.payment.record`, `billing.payment.reverse` (refund marking, flow-audit OD8), `billing.pdf.view`, `billing.export`, `report.view`, `report.business.view`. No `billing.cancel`. The same-actor guard keeps reverser ≠ recorder (`docs/SECURITY.md` §2.2 item 4). |
| `staff` | Staf | today's staff set (`:284`) − `customer.create` (decision 4) + `app.web`, `evaluation.evaluable` |
| `customer` | Pelanggan | today's customer set (`:329`) + `portal.access` |

The `noc` template (NOC & Layanan Pelanggan) holds:

- Access: `app.web`, `dashboard.view`.
- Customer registration: `customer.view`, `customer.create`, `customer.update`, `customer.address.manage`, `customer.subscription.view`, `customer.subscription.manage`, `customer.sales.assign`, `service.view`, `location.view`, `odn.view`, `odn.assign`, `spk.view`, `spk.create`. The onboarding gate `CustomerController::canOnboard` needs create + address + subscription.manage + spk.create.
- Complaints and tickets: `ticket.view`, `ticket.create`, `ticket.update`, `ticket.assign` (dispatch notices, flow-audit OD7), `ticket.reassign`, `ticket.close`, `ticket.comment.create`, `ticket.comment.internal`, `ticket.attachment.upload`, `ticket.attachment.view`, `ticket.spawn_spk`.
- Outage announcements: `announcement.manage`.
- Network view and trace: `network_asset.view`, `network_asset.nas.view`, `network_asset.account.view`, `ipam.view`.
- RADIUS resync: `network_asset.account.manage` (the gate of `NetworkAccountController::resync`).
- Manual isolir: `customer.subscription.block`.
- Read-only billing: `billing.view`.
- Reports: `report.view`, `report.sla.view`.
- Marker: `evaluation.evaluable`.
- Not granted: no `*.delete`, no billing write, no `users.*` or `roles.*`.

After M3 and M5, the copies of `admin`, `manager`, `technician` and `customer` equal their templates. The `staff` copies keep `customer.create`: for existing companies, decision 7 beats decision 4. Existing companies get the Keuangan template only through the top-up (OD2), which assigns it to nobody.

### 3.7 Runtime rules

1. Role queries resolve the company through `CompanyService::currentId()`. Without a company, no role matches; direct permissions still apply.
2. Code without an authenticated user of the target company must evaluate permissions inside `CompanyService::runAs($companyId, …)`, or query holders with `User::withPermissionIn($permissions, $companyId)` (explicit company, works in any context). Staff notices go through `StaffRecipients::holding()`, which uses `withPermissionIn`. The sites fixed this way are Q1, Q2, Q3, B25, B26, B28 and B29.
3. Relation cache: `User` stores the company id its `roles` relation was loaded for (`setRelation` override). Its `loadMissing` drops the relation when `CompanyService::currentId()` differs. Spatie calls `loadMissing('roles')` before every role and permission check (`HasRoles.php:249,325,373,403`; `HasPermissions.php:342`). This covers nested `runAs`, a switch inside one request, and queue workers, where `SerializesModels` restores relations before the job enters its company.
4. Permission cache: one spatie cache key holds every permission with the ids of every company's roles that hold it. A check intersects by role id, so it stays correct because role ids are company-specific. The size grows with the total of `role_has_permissions`: about 500 rows per company with the eight templates, so a few hundred KB at 100 companies. Spatie flushes it on every role or permission write. `shortcut: one global permission cache, upgrade to per-company cache keys (custom PermissionRegistrar) past ~300 tenants or a cache entry over 2 MB.`
5. `permission.events_enabled` stays `true`; it also feeds `RecordSecurityEvents`. With it off, `syncRoles` would detach the user's roles in every company.
6. Spatie's `getStoredRole()` accepts a `Role` instance without checking its company. Only `CompanyRoleService` and the seeders assign roles, always from names or ids resolved in the current company, or from `copyTemplates()` results.
7. Mass assignment: `App\Models\Role::$fillable = ['name', 'guard_name']`. `company_id` and `template_key` are set with `forceFill`, by `CompanyRoleService` only. Writes always pass `guard_name = 'web'`, because a platform request's default guard is `platform`.
8. `App\Models\Role` uses `HasPublicId` (route key `public_id`) and is removed from the package-model `public_id` hook list in `AppServiceProvider.php:214-218`. Policy registration: `Gate::policy(App\Models\Role::class, RolePolicy::class)`.

## 4. API contracts

### 4.1 Tenant web routes (S3, `routes/admin.php`)

The routes stay inside the existing group: middleware `['auth','verified','redirect.app.only','require.has.company']`, prefix `admin`, names `admin.`, and `whereUlid([... 'user', 'role' ...])`. Lines 79-80 are replaced by:

```php
Route::get('roles/export', [RoleController::class, 'export'])->name('roles.export');
Route::resource('roles', RoleController::class)->scoped(['role' => 'public_id']);
Route::put('users/{user}/roles', UserRoleController::class)->name('users.roles.update');
```

| Method | URI | Name | Authorization | Input | Success | Errors |
|---|---|---|---|---|---|---|
| GET | `/admin/roles` | `admin.roles.index` | `RolePolicy::viewAny` (`roles.manage`) | `search, sort (name\|created_at), direction, per_page` | Inertia `Admin/Roles/Index` (§5.1) | 403 |
| GET | `/admin/roles/create` | `admin.roles.create` | `create` | — | `Admin/Roles/Create` | 403 |
| POST | `/admin/roles` | `admin.roles.store` | `StoreRoleRequest` | `name`, `permissions[]` | 303 → index, flash "Peran dibuat." | 422 on `name`, `permissions` (unheld, SoD) |
| GET | `/admin/roles/{role}` | `admin.roles.show` | `view` | — | `Admin/Roles/Show` | 404 (other company, via scope) |
| GET | `/admin/roles/{role}/edit` | `admin.roles.edit` | `update` | — | `Admin/Roles/Edit` | 403 (role richer than the actor), 404 |
| PUT | `/admin/roles/{role}` | `admin.roles.update` | `UpdateRoleRequest` | `name`, `permissions[]`, `expected_updated_at` | 303 → index, "Peran diperbarui." | 422; 409 `errors.conflict` (OptimisticLock) |
| DELETE | `/admin/roles/{role}` | `admin.roles.destroy` | `DestroyRoleRequest` | `move_to` (role public id, required when the role has users) | 303 → index, "Peran dihapus." | 422 on `move_to`, `roles` (last admin, SoD stack), `role` (portal role) |
| GET | `/admin/roles/export` | unchanged | `roles.export` | | CSV/PDF of the current company's roles | 403 |
| PUT | `/admin/users/{user}/roles` | `admin.users.roles.update` | `UpdateUserRolesRequest`, `UserPolicy::assignRoles` | `roles[]` (names of current-company roles) | 303 back, "Peran pengguna diperbarui." | 422 on `roles`; 404 (not a member) |
| POST/PUT | `/admin/users[/{user}]` | existing | existing | `roles[]` validated in the current company | existing | 422 on `roles` (G1, G4, G5) |

### 4.2 Platform routes (S4, `routes/platform/roles.php` rewritten; the route shell adds the `platform.` name prefix)

```php
Route::middleware(['auth:platform', 'platform.account', 'platform.2fa'])->group(function () {
    Route::get('role-templates', [RoleTemplateController::class, 'index'])->name('role-templates.index');
    Route::get('role-templates/create', [RoleTemplateController::class, 'create'])->name('role-templates.create');
    Route::get('role-templates/{template}/edit', [RoleTemplateController::class, 'edit'])->whereUlid('template')->name('role-templates.edit');
    Route::prefix('companies/{company}')->whereUlid('company')->group(function () {
        Route::get('roles/create', [CompanyRoleController::class, 'create'])->name('companies.roles.create'); // ?target=<company id>&template=<key>
        Route::get('roles/{role}/edit', [CompanyRoleController::class, 'edit'])->whereUlid('role')->name('companies.roles.edit');
    });
    Route::middleware('platform.password.confirm')->group(function () {
        Route::post('role-templates', [RoleTemplateController::class, 'store'])->name('role-templates.store');
        Route::put('role-templates/{template}', [RoleTemplateController::class, 'update'])->whereUlid('template')->name('role-templates.update');
        Route::delete('role-templates/{template}', [RoleTemplateController::class, 'destroy'])->whereUlid('template')->name('role-templates.destroy');
        Route::prefix('companies/{company}')->whereUlid('company')->group(function () {
            Route::post('roles', [CompanyRoleController::class, 'store'])->name('companies.roles.store');
            Route::put('roles/{role}', [CompanyRoleController::class, 'update'])->whereUlid('role')->name('companies.roles.update');
            Route::delete('roles/{role}', [CompanyRoleController::class, 'destroy'])->whereUlid('role')->name('companies.roles.destroy');
        });
    });
});
```

- `{company}` is the tenant root (existing `Company::resolveRouteBinding`).
- `{role}` is a plain string, not implicitly bound, because the company scope would hide it. The controller resolves it with `Role::withoutCompany()->wherePublicId($role)->whereIn('company_id', $tenantCompanyIds)->firstOrFail()`, so a role of another tenant answers 404.
- `{template}` uses implicit binding by `public_id`.
- Errors: 422 per field; 404 for a foreign role; a step-up redirect when the platform password confirmation is older than its window.

### 4.3 FormRequests

Tenant roles (`app/Http/Requests/Admin/Roles/`, S3):

- `StoreRoleRequest`. `authorize`: `can('create', Role::class)`. Rules:
  - `name`: `required|string|max:255` and `Rule::unique('roles','name')->where('company_id', CompanyService::currentId())->where('guard_name','web')`.
  - `permissions`: `array` and `new NoSodConflict(exempt: in_array('roles.manage', (array) $this->input('permissions'), true))`.
  - `permissions.*`: `string|distinct` and `Rule::exists('permissions','name')->where('guard_name','web')`.
- `UpdateRoleRequest`. `authorize`: `can('update', $role)`. The same rules with `->ignore($role->id)`, plus `OptimisticLock::FIELD => OptimisticLock::rules()`.
- `DestroyRoleRequest`. `authorize`: `can('delete', $role)`. `move_to` is resolved through `ResolvesPublicIds` (`['move_to' => Role::class]`), so it resolves only roles of the current company. A check in `after()` refuses the role itself as target.

Users (S3):

- `StoreUserRequest` and `UpdateUserRequest`: `roles.*` becomes `Rule::exists('roles','name')->where('company_id', CompanyService::currentId())->where('guard_name','web')`. `NoStackedSodConflict($this->user())` stays. The admin-only `withValidator` is deleted.
- `UpdateUserRolesRequest` (NEW). `authorize`: `can('assignRoles', $this->route('user'))`. Rules: `roles` as `present|array` with `NoStackedSodConflict`, and `roles.*` with the same exists rule.

Platform (S4):

- `app/Http/Requests/Platform/RoleTemplates/{StoreRoleTemplateRequest,UpdateRoleTemplateRequest}.php`:
  - `name`: `required|max:255`, unique in `role_templates` (ignoring the edited row).
  - `permissions`: same rules and SoD exemption as the tenant requests.
  - On store, the service derives the key as `Str::slug($name, '_')`, cut to 32 characters. A taken key answers 422 on `name`: "Nama templat sudah dipakai."
- `app/Http/Requests/Platform/CompanyRoles/{StoreCompanyRoleRequest,UpdateCompanyRoleRequest,DestroyCompanyRoleRequest}.php`:
  - store: `company_id` (ULID of the tenant root or one of its subsidiaries), `name` unique in that company, `permissions` as above;
  - update: the same plus `expected_updated_at`;
  - destroy: `move_to` (ULID of a role in the same company).

### 4.4 Services and models

#### `App\Services\Core\CompanyRoleService` (S3)

Every write runs in `DB::transaction`. It first locks the company row (`Company::query()->lockForUpdate()->findOrFail($companyId)`), then works inside `CompanyService::runAs($companyId, …)`.

```php
/** @return array<string, Role> keyed by template_key; copies only when the company has no role */
public function copyTemplates(Company $company): array;
public function create(User|PlatformAdmin $actor, Company $company, string $name, array $permissions): Role;
public function update(User|PlatformAdmin $actor, Role $role, string $name, array $permissions, string $expectedUpdatedAt): Role;
public function delete(User|PlatformAdmin $actor, Role $role, ?Role $moveTo): void;
/** Roles of the current company only; roles holding portal.access are neither added nor removed. */
public function syncUserRoles(User $actor, User $target, array $roleNames): void;
/** @return list<string> names granted in $to: roles of $to whose template_key matches a role $user holds in $from */
public function mirrorTemplateRoles(User $user, Company $from, Company $to): array;
/** Every guarded permission of $permissions is held by $actor in the current company. */
public function covers(User $actor, array $permissions): bool;
/** @throws ValidationException when no active member holds roles.manage and app.web in the company */
public function assertAdministratorRemains(int $companyId): void;
```

Guards. Each throws a `ValidationException` on the named key, or a 409 where noted.

- **G1 Hold it yourself** (tenant actor). Permissions added to a role, and the guarded permissions of roles added to a user, must be held by the actor (guarded ones only). Error on `permissions` or `roles`: "Anda tidak dapat memberi hak akses yang tidak Anda miliki: {label, …}."
- **G2 Edit scope** (tenant actor). The actor may update or delete only roles whose guarded permissions he covers (`RolePolicy`), and change only users whose guarded permissions in this company he covers (`UserPolicy`).
- **G3 SoD on role save.** `SodConflicts::violations()` must be empty, unless the set holds `roles.manage`. Enforced by `NoSodConflict` in the tenant, template and company-role requests.
- **G4 Stacked SoD.** The merged permissions of a user's roles in this company must have no violations. Checked on user role changes (`NoStackedSodConflict`), and in `delete()` for every user moved to the target role. Exempt: actors holding `roles.manage`. A `PlatformAdmin` is not exempt.
- **G5 Last administrator.** After the change, at least one active member of the company (home or `company_user`) must hold `roles.manage` and `app.web` there, through a role or a direct permission. Error on `roles`: "Perusahaan harus tetap punya minimal satu admin aktif yang memegang roles.manage." Checked on:
  - role update, delete and move;
  - `syncUserRoles`;
  - user deactivation (`UserController::update`);
  - user deletion (`UserDeletionGuard::ensureDeletable`, which covers `ProfileController::destroy` too);
  - membership revoke;
  - platform repairs.
- **G6 Portal role.** The role with `template_key = customer` cannot be deleted. Error on `role`: "Peran ini dipakai untuk akun pelanggan baru." Pengguna never adds or removes roles holding `portal.access`.
- **G7 Moving users.** Deleting a role that has users requires `move_to`: another role of the same company that the actor covers (G2). Users who lack the target role get it; then the role is deleted.
- **G8 Stale edit.** `update()` locks the role, calls `OptimisticLock::assert($role, $expectedUpdatedAt)`, and calls `touch()` after `syncPermissions` so the version moves.

Audit:

- A tenant actor writes `AuditService::log('security', $action, $properties, $role)`; the company comes from the role.
- A platform actor writes `PlatformAuditService::platform()` and `support()`, with the same action and properties.
- Actions:
  - `role.created` {`name`, `permissions`}
  - `role.updated` {`name_before`, `name_after`, `permissions_added`, `permissions_removed`}, only when something changed
  - `role.deleted` {`name`, `permissions`, `moved_users`, `moved_to`}
- User role changes keep `role.attached` and `role.detached` from `RecordSecurityEvents`. The names are now per company and are resolved inside the right company.

#### `App\Services\Platform\RoleTemplateService` (S4)

- `create(PlatformAdmin, string $name, array $permissions): RoleTemplate`
- `update(PlatformAdmin, RoleTemplate, string $name, array $permissions): RoleTemplate`
- `delete(PlatformAdmin, RoleTemplate): void`. Refuses the keys `admin` and `customer`: "Templat ini dipakai saat perusahaan dibuat."
- Audit in the `platform` log: `role_template.created`, `role_template.updated` (with the diff), `role_template.deleted`.
- Never touches company roles.

#### `App\Support\Notifications\StaffRecipients` (exists; S2 adapts it)

```php
/** Active members of the company (home or company_user) who hold the permission in that company, directly or through
 * one of that company's roles; works without a signed-in user. */
public static function holding(int $companyId, string $permission, ?int $exceptUserId = null): Collection; // holder clause = withPermissionIn
```

#### `App\Support\PermissionCatalogue` (S1)

```php
public const ALL = [/* every permission name, moved from RolePermissionSeeder, + the five of §2.2 */];
public const WEB = 'app.web';
public const TECHNICIAN_APP = 'app.technician';
public const PORTAL = 'portal.access';
public const EVALUABLE = 'evaluation.evaluable';
public const TECHNICIAN_TEAM_REPORT = 'report.technician.team';
public const ADMINISTRATION = 'roles.manage';
public const UNGUARDED = ['app.technician', 'evaluation.evaluable', 'portal.access', 'portal.invoice.view', 'portal.invoice.pay'];
/** @param list<string> $permissions  @return list<string> without UNGUARDED */
public static function guarded(array $permissions): array;
/** @return list<array{key: string, name: string, permissions: list<string>}> the eight defaults of §3.6.1 */
public static function templates(): array;
/** For release migrations: create the permission, add it to these templates and to their company copies. */
public static function release(string $permission, array $templateKeys): void;
```

#### Models (S1)

- `App\Models\Role`: `use BelongsToCompany, HasPublicId`; `$fillable = ['name','guard_name']`; `public static function forTemplate(string $key): ?self` (current company).
- `App\Models\Permission`: `roles()` without the company scope.
- `App\Models\RoleTemplate`: `HasPublicId`; `permissions(): BelongsToMany` through `role_template_has_permissions`.
- `App\Models\User`:
  - `scopeWithPermissionIn(Builder $q, string|array $permissions, int $companyId): Builder`: users who hold any of the permissions in that company, through its roles (scope removed, `roles.company_id = $companyId`) or directly;
  - the relation-cache guard (§3.7, rule 3);
  - the masking change of B22.

#### `App\Rules\HoldsPermission` (S2)

`__construct(array $permissions, ?int $companyId = null)`. It passes when the id names an active member of the company (home or `company_user`) who matches `withPermissionIn`. Message: ':attribute yang dipilih harus berupa pengguna aktif dengan hak akses yang sesuai.'

### 4.5 Policies

- `RolePolicy` (S3): B16.
- `UserPolicy` (S3):
  - `view`, `update`, `delete` and `resetTwoFactor` keep `administers`, because login fields reach every company of the target.
  - `change()` replaces the admin rule with `covers`.
  - New `assignRoles(User $actor, User $target)`: `can('users.manage')`; the target is a member of the current company (`Response::denyAsNotFound()` otherwise); and `covers($actor, target's permissions here)`.
- `CompanyPolicy` (S2): B45.

### 4.6 API (`/api/v1`) and mobile

- `GET /api/v1/me` (`MeResource`): the payload is unchanged; it never carried roles or permissions. `docs/api/mobile-v1.openapi.json` is unchanged.
- Customer app gate (`ApiTokenService::ensureMayUse('customer')`): `portal.access` in the user's company plus the `customers.user_id` link. Before, it was role customer plus the link; the cutover makes the same users pass.
- Technician app gate: the rule `MyWorkQuery::isEligible` (work permissions plus an active employee profile) is unchanged. It is now evaluated in `runAs(idFor($user))` at login. The token does not require `app.technician`: an admin or staff member with an employee profile keeps the app, as today.
- Token abilities, `EnsureApiAppAccess` and push audiences (`device_tokens.app`) are unchanged. No mobile release is needed.

## 5. UI contract

Rules for the UI slices (S4, S5):

- Load and list in the brief: antislop, antislop-ui, antislop-human, antislop-layoutmobile, antislop-copywriting, ui-ux-pro-max, frontend-design, redesign-existing-projects, design-system, tailwindcss-development, shadcn, ui-styling, inertia-react-development, verification-before-completion.
- Check every new colour pair with the antislop contrast tool.
- Report which rule from each skill changed the design (owner rule).
- Use `Components/ui` primitives and `Components/composite`, forms through `useForm`, and no fetch or axios (ADR-002).

### 5.1 Tenant: Administrasi → Peran (`/admin/roles`)

The spec says "Pengaturan → Peran". The sidebar entry already exists in the group "Administrasi" with the gate `roles.manage`, so the navigation does not change.

**`Admin/Roles/Index`**

```ts
interface RoleRow {
    id: string; name: string; template_key: string | null;
    permissions_count: number; users_count: number;
    can: { update: boolean; delete: boolean };
}
interface IndexProps {
    roles: Paginated<RoleRow>;
    filters: { search?: string; sort?: string; direction?: string; per_page?: string };
    moveTargets: { id: string; name: string }[]; // every role of the company, for the delete dialog
    templateNames: Record<string, string>;     // template key → template name
    can: { create: boolean; export: boolean };
}
```

- Columns: Nama (links to Show), Templat (badge "Dari templat {name}" when `template_key` is set), Hak akses (count), Pengguna (count), Aksi (Ubah, Hapus).
- The header has "Buat peran" when `can.create`; the export menu stays.
- `DeleteRoleDialog`:
  - when `users_count = 0`, a plain confirmation;
  - otherwise a required `SearchSelect`, "Pindahkan {n} pengguna ke", over `moveTargets` minus the role itself;
  - server errors on `move_to`, `roles` and `role` show inside the dialog.
- The portal role has `can.delete = false`, so its delete action is hidden.
- Deleted: the text "Definisi peran dikelola oleh operator platform." and `PROTECTED_ROLES`.

**`Admin/Roles/Create`**

Props: `permissions: PermissionOption[]` (`{ name, group, grantable }`) and `templates: { key: string; name: string; permissions: string[] }[]`.

- "Mulai dari templat" (optional select): fills the name if it is empty and the template's grantable permissions. Skipped ones are announced: "{n} hak akses tidak Anda miliki dan dilewati."
- Nama.
- `PermissionGroupSelector`, grouped by module with Indonesian labels, search, and select-all per group. A non-grantable option is disabled, with the hint "Anda tidak memegang hak akses ini" linked through `aria-describedby`.
- Submit: "Buat peran".

**`Admin/Roles/Edit`**

Props: `role: { id, name, template_key, permissions: string[], users_count, version: string }` (`version` = `OptimisticLock::version`), `permissions: PermissionOption[]`, `templateName: string | null`.

- The same form, with `expected_updated_at` hidden. Submit: "Simpan peran". A 409 shows the shared conflict toast.
- Notes:
  - a role holding `roles.manage`: "Pemegang peran ini dapat mengubah semua peran perusahaan.";
  - a role holding `portal.access`: "Peran akun pelanggan: berlaku di aplikasi Pelanggan."

**`Admin/Roles/Show`**: as today, plus `can: { update, delete }` and the user count.

**Shared components (S5)**

- `PermissionOption` gets an optional `grantable?: boolean`; absent means grantable.
- `setGroupPermissions` skips non-grantable options.
- `permissionLabels.ts`: labels for the five new permissions and the group `app`, "Akses aplikasi".
- `permissionGroups.selfcheck.mjs` reads `app/Support/PermissionCatalogue.php` instead of the seeder. The groups exempt from a sidebar section are `portal` and `app`.

**States**

- Inertia progress while loading.
- Field errors under the fields.
- 403 without `roles.manage`; 404 for a foreign role; the 409 toast on a stale edit.
- Dialogs trap focus (existing `Dialog`).
- At 320 px, the table switches to the existing DataTable card layout.
- Light and dark.

**Audit labels** (`lib/statuses/audit.ts`): `role.created` "Peran dibuat" (success), `role.updated` "Peran diubah" (info), `role.deleted` "Peran dihapus" (danger).

### 5.2 Tenant: Pengguna (`/admin/users`)

- The Index lists the members of the current company: users homed here plus `company_user` rows.
- `UserResource`:
  - `roles`: the user's role names in the current company;
  - `can` gains `assign_roles`;
  - `is_member_only` (new): the user is homed elsewhere.
- A member-only row shows the badge "Anggota" and no Show link, because login fields are edited in the home company. When `can.assign_roles` is true, "Atur peran" opens `AssignRolesDialog`: checkboxes over `roleOptions`, sent with `PUT admin.users.roles.update`.
- New Index prop `roleOptions: { name: string; grantable: boolean }[]`: the company's roles without `portal.access`. It is sent only to `users.manage` holders.
- Create and Edit take the `roles` prop in the same shape with the same filter; non-grantable options are disabled.
- A user holding a portal role shows the note "Peran pelanggan dikelola dari data pelanggan."

### 5.3 "Use the app" page (`/app-only`, S2)

Props: `role: 'technician' | 'customer' | null`, `appName: string | null`, `playStoreUrl: string | null`.

When `role` is `null`, the page shows:

- title: "Akun ini belum punya akses aplikasi web";
- body: "Minta admin perusahaan Anda memberi peran yang memuat akses web.";
- a "Keluar" button.

### 5.4 Platform (S4)

- `PlatformLayout.tsx`: the nav item "Peran & hak akses" becomes "Templat peran" → `platform.role-templates.index`.
- `Platform/RoleTemplates/Index`: rows `{ id, key, name, permissions_count, deletable }`; actions Ubah and Hapus (`ConfirmActionDialog` step-up); "Buat templat".
- `Platform/RoleTemplates/{Create,Edit}`: name and `PermissionGroupSelector` (all grantable), with the note "Perubahan templat hanya berlaku untuk perusahaan baru; peran perusahaan yang sudah ada tidak berubah."
- `Platform/Companies/Show`: a new `RolesSection` after `UsersSection`.
  - One table per company of the tenant, head first: Nama · Templat · Hak akses · Pengguna · "Perbaiki". Plus "Tambah dari templat".
  - New props from `TenantDetailQuery`:
    - `companyRoles: { company: { id, name, code }; roles: { id, name, template_key, permissions_count, users_count }[] }[]`
    - `roleTemplates: { key: string; name: string }[]`
- `Platform/Companies/Roles/{Create,Edit}`:
  - a header naming the tenant and the company; name; `PermissionGroupSelector`;
  - delete with the move-users step (Edit only);
  - step-up on submit (existing `ConfirmActionDialog` pattern);
  - banner: "Perbaikan oleh operator tercatat di audit perusahaan (support)."
- The admins card and the users list show home-company role names (B26).
- Platform audit labels (`lib/statuses/platform.ts`): new `role_template.created`, `role_template.updated`, `role_template.deleted`. Company repairs reuse the existing `role.created`, `role.updated`, `role.deleted`.

## 6. Test plan

### 6.1 Commands

Main runs these once, after all slices land. Run one DB job at a time, and check `uptime` first.

```bash
DOCKER_CONTEXT=default ./vendor/bin/sail artisan test --compact tests/Feature/Roles
DOCKER_CONTEXT=default ./vendor/bin/sail artisan test --compact
vendor/bin/pint --test && vendor/bin/phpstan analyse --memory-limit=512M
npm run typecheck && npx eslint <touched files> && npx prettier --check <touched files>
node resources/js/Components/composite/permissionGroups.selfcheck.mjs
node --test resources/js/lib/navigation.selfcheck.mjs resources/js/Pages/Admin/Reports/AuditLog.selfcheck.mjs
flock /tmp/inbils-e2e.lock sh -c 'npm run build && node scripts/e2e-setup.mjs && npm run test:e2e -- specs --project=chromium --workers=1'
```

Before E2E, run the storage `chown` fix from the runbook.

Migration round trip, on `inbils_test` only:

```bash
./vendor/bin/sail artisan migrate:fresh --seed --env=testing
./vendor/bin/sail artisan migrate:rollback --step=6 --env=testing
./vendor/bin/sail artisan migrate --env=testing
```

### 6.2 New and rewritten tests per slice

**S1**

`tests/Feature/Roles/RoleCutoverMigrationTest.php`. It follows the pattern of `tests/Feature/Auth/TwoFactorMigrationTest.php`: DDL commits, so the test calls M6 `down()`/`up()` and cleans up in `finally`.

- Fixture:
  - three companies: a head, a subsidiary, an unrelated tenant;
  - global roles `admin`, `manager`, `staff`, `technician`, `customer`, plus custom `noc` and `support-lead`;
  - users: one role each; a stacked manager+staff user; home-only; home+membership; membership-only (`company_id` null); direct-permission-only; company-less.
- Snapshot with the legacy SQL of §3.5. Assert per pair: `CompanyService::runAs($c, fn () => $u->fresh()->getAllPermissions()->pluck('name')->sort()->values()->all())`.
- Tests:
  - `test_every_user_keeps_the_same_permissions_in_every_company`
  - `test_each_company_gets_a_copy_of_every_global_role_with_its_permissions`
  - `test_assignments_follow_the_home_company_and_every_membership`
  - `test_copies_of_the_seeded_roles_get_the_template_name_and_key`
  - `test_a_custom_global_role_keeps_its_name_without_template_key`
  - `test_missing_templates_are_added_without_users`
  - `test_assignments_of_a_user_without_company_are_dropped_and_logged`
  - `test_the_self_check_refuses_a_changed_pair` (the comparison step is a protected method, fed crafted temp tables)
  - `test_down_restores_global_roles_when_no_company_diverged`
  - `test_down_refuses_after_a_company_renamed_a_role`

`tests/Feature/Roles/AccessPermissionsMigrationTest.php`:

- `test_access_permissions_follow_the_legacy_role_names`
- `test_a_user_with_only_direct_permissions_gets_web_access`
- `test_down_removes_the_access_permissions`

`tests/Feature/Roles/TemplatePermissionMatrixTest.php` (the permission matrix per template):

- `test_template_holds_exactly_its_catalogue_set` (data provider over the eight keys)
- `test_only_the_admin_template_holds_roles_manage_and_users_manage`
- `test_no_template_but_admin_breaks_separation_of_duties`
- `test_noc_holds_registration_tickets_announcements_network_resync_and_isolir_without_delete_billing_writes_or_administration`
- `test_manajer_and_noc_hold_ticket_assign_and_keuangan_holds_payment_reverse` (flow-audit OD7, OD8)
- `test_staff_template_lacks_customer_create`
- `test_only_teknisi_holds_app_technician_and_only_pelanggan_holds_portal_permissions`
- `test_every_template_except_teknisi_and_pelanggan_opens_the_web`
- `test_every_template_permission_exists_in_the_catalogue`
- `test_the_admin_template_is_the_catalogue_without_unguarded`

`tests/Feature/Roles/CompanyRoleScopeTest.php`:

- `test_roles_of_another_company_are_invisible`
- `test_no_company_context_matches_no_role`
- `test_sync_roles_in_one_company_keeps_the_roles_of_the_other`
- `test_one_user_instance_answers_per_company_across_run_as`
- `test_a_relation_restored_without_company_is_reloaded_inside_run_as`
- `test_permission_cache_holds_roles_of_every_company`
- `test_the_same_role_name_may_exist_in_two_companies_but_not_twice_in_one`
- `test_with_permission_in_names_holders_of_the_given_company_only`

`tests/Feature/Roles/TemplateCopyOnCompanyCreationTest.php`:

- `test_the_setup_wizard_copies_templates_and_makes_the_creator_admin`
- `test_platform_provisioning_copies_templates`
- `test_a_subsidiary_gets_the_templates`
- `test_copying_twice_creates_nothing`
- `test_template_edits_do_not_touch_existing_companies`
- `test_a_new_customer_account_gets_the_customer_template_role_after_a_rename`
- `test_membership_revoke_detaches_that_company_roles`

Rewritten:

- `tests/Unit/RolePermissionSeederTest.php`: the catalogue; no roles; templates created once and never overwritten.
- `tests/Feature/RolePersonaSodTest.php`: SoD per template; the `roles.manage` exemption.
- `tests/Feature/PermissionRenameMigrationTest.php`: `DB::table` and a company role.
- `tests/Feature/Platform/TenantProvisioningTest.php`, `tests/Feature/SetupWizardTest.php`, `tests/Unit/SetupWizardServiceTest.php`, `tests/Feature/CustomerAccountCreationTest.php`.

**S2**

`tests/Feature/Roles/RoleNameIndependenceTest.php`. Rename every role of a company and delete the unused ones, then:

- `test_ticket_handlers_are_validated_by_permission`
- `test_evaluation_picker_and_store_follow_the_evaluable_permission`
- `test_technician_report_scopes_follow_permissions` (admin all, manager subtree, technician self)
- `test_web_access_follows_app_web`
- `test_customer_portal_and_app_follow_portal_access_and_the_link`
- `test_an_unlinked_portal_holder_sees_no_ticket`
- `test_billing_schedule_alerts_reach_role_managers`
- `test_staff_recipients_resolve_per_company_without_a_signed_in_user` (scheduler and webhook contexts: the SLA breach job, the payment-exception alert)
- `test_staff_recipients_count_a_member_by_his_roles_in_that_company_only`
- `test_app_logins_resolve_permissions_in_the_users_company`
- `test_going_home_needs_no_company_switch`

`tests/Feature/Roles/RoleNameChecksArchitectureTest.php`: a Finder scan of `app/` and `Modules/*/app/`, using the pattern of `tests/Feature/Integrations/NoRuntimeConfigWritesTest.php:226-231`. It fails on:

- `hasRole(`, `hasAnyRole(`, `hasAllRoles(`, `hasExactRoles(`, `->role(`, `withoutRole(`;
- a `whereHas('roles'` closure that filters on `name`;
- `use Spatie\Permission\Models\Role;` outside `app/Models/Role.php`.

Rewritten: `tests/Feature/AppOnlyRedirectTest.php`, `tests/Traits/WithoutAppOnlyRedirect.php` (docblock), `tests/Feature/Rules/ValidationRuleMessagesTest.php`, `tests/Feature/Admin/EvaluationCompanyScopeTest.php`, `tests/Feature/Reporting/{TechnicianPerformanceAuthorizationTest,TechnicianReportCapabilityRouteTest,TechnicianReportFlagTest}.php`, `tests/Feature/Ticketing/{TicketCustomerScopeTest,TicketAssignScopeTest}.php`, `tests/Feature/Billing/BillingFailureNotificationTest.php`, `tests/Feature/Tenant/CompanySwitchTest.php`.

**S3**

`tests/Feature/Roles/TenantRoleManagementTest.php`:

- `test_role_managers_list_only_their_company_roles`
- `test_create_rename_and_edit_are_audited_with_the_permission_diff`
- `test_a_permission_the_actor_lacks_cannot_be_granted`
- `test_a_role_richer_than_the_actor_cannot_be_edited_or_deleted`
- `test_separation_of_duties_is_enforced_except_for_roles_holding_roles_manage`
- `test_a_role_with_users_needs_a_move_target_and_moves_them`
- `test_moving_users_cannot_stack_a_conflict`
- `test_the_customer_template_role_cannot_be_deleted`
- `test_the_last_role_manager_cannot_be_removed_by_edit_delete_or_move`
- `test_a_stale_edit_is_refused`
- `test_another_company_role_is_a_404`
- `test_actors_without_roles_manage_get_403`

`tests/Feature/Roles/UserRoleAssignmentTest.php`:

- `test_the_picker_offers_company_roles_without_portal_roles`
- `test_assigning_a_richer_role_is_refused`
- `test_a_member_homed_elsewhere_gets_roles_here_only`
- `test_portal_roles_survive_a_pengguna_save`
- `test_the_last_admin_cannot_be_demoted_deactivated_or_deleted`
- `test_a_user_richer_than_the_actor_cannot_be_changed`

Rewritten:

- `tests/Feature/AdminPermissionRoleTest.php`
- `tests/Feature/AdminMenuAuthorizationTest.php`: the role routes now exist; `web-access` becomes `app.web`.
- `tests/Feature/Admin/{RoleServerTableExportTest,PermissionServerTableTest,UserFormTest}.php`
- `tests/Feature/Auth/SecurityTrailTest.php`

**S4**

- `tests/Feature/Platform/RoleTemplateManagementTest.php` replaces `PlatformRoleManagementTest.php`, which is deleted. It covers: guest and 2FA redirects; step-up on writes; audited create, update and delete; the admin and customer templates cannot be deleted; SoD on templates; edits never touch company roles.
- `tests/Feature/Platform/CompanyRoleRepairTest.php`: the per-company listing on the tenant page; create, update and delete write `platform` and `support` rows; another tenant's role answers 404; G4, G5 and G3 apply; step-up is required.
- Rewritten:
  - `tests/Feature/Platform/Auth/PlatformAuthenticationTest.php:463`: the DELETE routes are now `platform.role-templates.destroy` and `platform.companies.roles.destroy`.
  - `tests/Feature/Platform/TenantDetailTest.php`: admins are `roles.manage` holders; roles per home company.
  - `SupportActionsTest`.
  - `TenantSubsidiaryTest`: members get template-matched roles.
  - `TenantExportTest`: `user_roles.csv` holds only roles of the tenant's companies, with the company code.
  - `PurgeTenantCommandTest`: roles are tenant rows.

**S5**

`permissionGroups.selfcheck.mjs` cases for `grantable` (select-all skips disabled options), the labels of the new permissions, and the `app` group exemption.

**T3**

- New `tests/e2e/tenant-roles.spec.ts`:
  - `@tenant-roles admin creates a role from the NOC template, renames it and assigns it in Pengguna`
  - `@tenant-roles a role with users is deleted after moving them`
  - `@tenant-roles keyboard-only create and delete`
  - `@tenant-roles 320px light and dark`
  - `@tenant-roles operator edits a template and an existing company keeps its roles`
- Updated:
  - `tests/e2e/auth-roles.spec.ts:216-239`: Peran is writable; the link is "Admin"; the heading is "Peran Admin".
  - `tests/e2e/datatable-ab.spec.ts:57-67`: `ascFirst: 'Admin'`, `descFirst: 'Teknisi'`.
  - Every other spec found by `grep -nE "'(admin|manager|staff|technician|customer)'" tests/e2e` that asserts a role name. Persona keys of `loginAs` and `apiLogin` stay.
  - Keep the `DemoUserSeeder::USERS` shape: `customer-subscription.spec.ts:9-11` parses it.
- Seeder tests: `tests/Feature/{DemoUserSeederTest,DemoTeamSeederTest,DemoDataSeederTest,CustomerDemoSeederTest}.php`.

### 6.3 How the ~4 900-test suite adapts

`tests/Traits/GrantsCompanyRoles.php` (NEW, S1) is used by `tests/TestCase.php`, so every test gets it without a `use` line:

```php
/** @return array<string, Role> template_key => role; copies the templates into the company once */
protected function companyRoles(Company|int $company): array;
/** $role is a template key or a role; the default company is the user's home */
protected function grantRole(User $user, string|Role $role, Company|int|null $company = null): User;
protected function companyRole(Company|int $company, string $name, array $permissions): Role;
/** An ad hoc role 'perms-<md5>' holding these permissions, granted in the company */
protected function grantPermissions(User $user, array $permissions, Company|int|null $company = null): User;
```

All four helpers run inside `CompanyService::runAs`, flush the permission cache after writes, and call `unsetRelation('roles')` on the user. Templates exist after `migrate` (M4). Existing `$this->seed(RolePermissionSeeder::class)` calls stay valid but are no longer required.

Mechanical rules. T1 and T2 apply them; every other slice applies them to its own files.

- **R1**: `use Spatie\Permission\Models\Role;` → `use App\Models\Role;`. The `Permission` import may stay.
- **R2**: `$u->assignRole('admin'|'manager'|'staff'|'technician'|'customer')` and `syncRoles(['x'])` → `$this->grantRole($u, 'x')`. The template keys equal the legacy names.
- **R3**: `Role::firstOrCreate/findOrCreate/create(['name' => $n, 'guard_name' => 'web'])` + `givePermissionTo/syncPermissions($p)` → `$this->companyRole($company, $n, $p)`, and `$u->assignRole($role)` → `$this->grantRole($u, $role)`.
- **R4**: `Role::findByName('x')` or `Role::where('name', 'x')` in assertions → `$this->companyRoles($company)['x']`.
- **R5**: assertions on role names (`getRoleNames()`, `roles.data.*.name`) → the template names (`Admin`, `Manajer`, `Staf`, `Teknisi`, `Pelanggan`) or `template_key`.
- **R6**: the permission-less `web-access` role that dodges the app-only redirect (`AdminMenuAuthorizationTest.php:509-513`, `BuildsDashboardFixtures.php:64-68`) → `grantPermissions($u, ['app.web'])`.
- **R7**: users with only direct permissions who hit `/admin` routes also need `app.web`.
- **R8**: a permission check outside a request needs a company: `CompanyService::useCompanyForTests($id)` or `runAs`.
- **R9**: the error text "peran yang sesuai" → "hak akses yang sesuai".

Volume, from grep: 147 files call `assignRole`/`syncRoles`, 62 create roles ad hoc, 87 import the spatie `Role`, and 21 give users direct permissions. With overlap, about 180 files. Flow-audit wave 1 adds tests of its own before this lane starts; T1 and T2 apply the same rules to them.

## 7. Risks and open decisions

### 7.1 Open decisions

Each lists the recommended default first. None blocks the slices.

- **OD1 Names of the cutover copies.** Default: rename the five seeded roles to Admin, Manajer, Staf, Teknisi and Pelanggan. This fixes flow-audit F3-D03 ("Nama peran mentah berbahasa Inggris"), which belongs to this lane. Alternative: keep `admin`, `manager` and so on; tenants can rename them.
- **OD2 Missing templates in existing companies.** Default: add NOC & Layanan Pelanggan, Gudang and Keuangan to every existing company at cutover, unassigned. This answers F3-U04 and K15 for current tenants without changing anyone's access. Alternative: tenants create them with "Mulai dari templat".
- **OD3 Delta D1.** Default: accept additive semantics, so a technician who also holds a role granting `evaluation.view` now sees all evaluations. Alternative: keep "a technician sees only his own" with a restrictive `app.technician` check. §3.5 has the query that counts affected users.
- **OD4 Gudang, Keuangan and NOC contents.** Default: as in §3.6.1, including the flow-audit grants OD7 and OD8. The operator can edit them later in the template editor.
- **OD5 Step-up for tenant role writes.** Default: none, the same as user-role assignment today. Alternative: `password.confirm` on `admin.roles.store/update/destroy`.
- **OD6 Members homed elsewhere.** Default: Pengguna lists them, with roles-only editing. Decision 1 ("Admin in A, Teknisi in B") needs a place to assign their roles.
- **F3-U06 ("Minta perubahan peran").** Resolved by this lane: tenants edit roles themselves, so the button is not built.

### 7.2 Behaviour deltas

Each is announced in the CHANGELOG and in `docs/SECURITY.md`.

- **D1**: see OD3.
- **D2**: a user whose roles in the company lack `app.web` (including a user with no role) sees the "no web access" page instead of a dashboard 403.
- **D3**: non-admin holders of `users.manage` or `roles.manage` can now change only the users and roles they cover (G1, G2). This narrows their reach.
- **D4**: assigning a ticket to a user without ticket work permissions is refused at validation (422), no longer as a 404 from the service.
- **D5**: the platform admins card and the invitation go to `roles.manage` holders, so a custom role holding `roles.manage` now counts.
- **D6**: billing schedule alerts go to the company's `roles.manage` holders through `StaffRecipients`, members included. Today only admins homed in the company receive them, so after cutover the head admins who are members of a subsidiary also receive that subsidiary's alerts.
- **D7**: subsidiary members get the subsidiary's template-matched roles. Members holding only custom roles get none; the command or the platform flash names them.
- **D8**: going back to the home company is always allowed.
- **D9**: the Staf template for new companies lacks `customer.create`. Existing staff copies keep it.

### 7.3 Risks

- **R1 Parallel lane `flow-audit-fixes`.** This lane starts after its wave 1 lands (see the header), but its wave 2 can still add catalogue permissions. A new permission then goes into `PermissionCatalogue::ALL` and the matching templates, together with a `PermissionCatalogue::release()` migration. Whichever lane lands second rebases (`RolePermissionSeeder.php` and `PermissionCatalogue.php` conflict).
- **R2 Technician-performance phase.** Active card `t_b51debaa` (`Technician.tsx`) and the next gate `t_da288dbd`. S2 edits `TechnicianReportAccess.php` and `TechnicianPerformanceQuery.php`, so S2 starts after that card or rebases.
- **R3 Registrar cache growth.** See §3.7, rule 4.
- **R4 Direct user permissions stay global** across a user's companies. No app code writes them; `givePermissionTo` on a user appears only in tests. This is listed as a residual in SECURITY.md.
- **R5 A stale `roles` relation.** Guarded in `User` (§3.7, rule 3). Display code that reads `$user->roles` as a property must load it in the current company.
- **R6 Tenant export.** After S4, the export holds the `roles` rows and a tenant-filtered `user_roles.csv`, but not `role_has_permissions`, so a restore loses the role permissions. This is a follow-up for the export spec.
- **R7 Test churn.** About 180 mechanical changes; a semantic assertion may be missed. The full-suite run gates the release.
- **R8 Failed deploy.** If M5 throws, M1–M4 stay applied under the new code and every role query hides the global roles. Keep maintenance mode on until the rollback is done (§8.4).
- **R9 MySQL-only SQL in M5** (temporary tables, `SHA2`). It matches the supported stack: MySQL 8.4, and tests run on MySQL.
- **R10 Platform DELETE surface.** Template and company-role deletes are added on purpose, behind step-up and audit.

Deferred, not in any slice: per-company permission cache keys; a "duplicate role" button; exporting roles with their permissions; requiring `app.technician` for the technician app token.

## 8. Work breakdown

Waves:

- **Precondition:** `flow-audit-fixes` wave 1 (B1–B4) has landed.
- **Wave 1:** S1, S2, S3, S4. The contracts of §4 are fixed, so these slices code against each other's signatures.
- **Wave 2:** S5, T1, T2, D1.
- **Wave 3:** T3 (needs the S5 UI for E2E).
- **Then:** Main runs §6.1; `security-reviewer` checks §8.3; fixes; release (§8.4).

At most four slices run in parallel. Every slice skips build, lint and test runs mid-flight; Main verifies once. Each slice re-reads its owned files, because wave 1 of flow-audit will have changed some of them.

### 8.1 Slices

**S1 · backend · foundation**

- **Goal:** company-scoped role model, cutover, catalogue and templates, company creation paths, SoD rules, test helpers.
- **Owned files:**
  - `config/permission.php`
  - NEW `app/Models/Role.php`, `app/Models/Permission.php`, `app/Models/RoleTemplate.php`; `app/Models/User.php`
  - NEW `app/Support/PermissionCatalogue.php`
  - `app/Providers/AppServiceProvider.php`
  - `app/Services/Core/SetupWizardService.php`, `CustomerService.php`, `CompanyMembershipService.php`
  - `app/Rules/NoSodConflict.php`, `app/Rules/NoStackedSodConflict.php`
  - NEW in `database/migrations/`: `2026_10_28_100000_create_role_templates_tables.php`, `2026_10_28_100100_add_company_columns_to_roles_table.php`, `2026_10_28_100200_add_access_permissions.php`, `2026_10_28_100300_create_default_role_templates.php`, `2026_10_28_100400_copy_global_roles_into_companies.php`, `2026_10_28_100500_require_company_on_roles.php`
  - `database/seeders/RolePermissionSeeder.php`, `database/seeders/CompanySeeder.php`
  - `tests/TestCase.php`, NEW `tests/Traits/GrantsCompanyRoles.php`
  - `tests/Traits/CreatesCompanyUser.php`, `InteractsWithMobileApi.php`, `CreatesReportingUsers.php`, `BuildsCompanyGroup.php`, `BuildsCustomerAcquirers.php`, `BuildsDashboardFixtures.php`, `BuildsIpamOdnFixtures.php`, `BuildsNetworkFixtures.php`, `EnrollsTwoFactor.php`
  - NEW in `tests/Feature/Roles/`: `RoleCutoverMigrationTest.php`, `AccessPermissionsMigrationTest.php`, `TemplatePermissionMatrixTest.php`, `CompanyRoleScopeTest.php`, `TemplateCopyOnCompanyCreationTest.php`
  - Rewritten: `tests/Unit/RolePermissionSeederTest.php`, `tests/Feature/RolePersonaSodTest.php`, `tests/Feature/PermissionRenameMigrationTest.php`, `tests/Feature/Platform/TenantProvisioningTest.php`, `tests/Feature/SetupWizardTest.php`, `tests/Unit/SetupWizardServiceTest.php`, `tests/Feature/CustomerAccountCreationTest.php`
- **Consumes:** `CompanyRoleService::copyTemplates` and `assertAdministratorRemains` (S3).
- **Produces:** `App\Models\{Role,Permission,RoleTemplate}`, `PermissionCatalogue`, `User::withPermissionIn`, the relation-cache guard, the migrations, the test helpers.
- **Depends on:** the precondition.
- **Acceptance:** the S1 tests of §6.2 pass; the `migrate:fresh --seed` / `rollback --step=6` / `migrate` round trip on `inbils_test` succeeds.

**S2 · backend · role checks → permissions**

- **Owned files:**
  - `app/Http/Controllers/AppOnlyController.php`
  - `app/Http/Middleware/RedirectAppOnlyRoles.php`, `HandleInertiaRequests.php`, `EnsureCustomerAccess.php`
  - `app/Http/Controllers/Admin/EvaluationController.php`, `app/Http/Requests/Admin/StoreEvaluationRequest.php`, `app/Models/Core/EmployeeEvaluation.php`
  - `app/Policies/EmployeeEvaluationPolicy.php`, `app/Policies/CompanyPolicy.php`
  - `app/Queries/Admin/DashboardAdminStatsQuery.php`
  - `app/Rules/HasAnyActiveRole.php` (DELETE), NEW `app/Rules/HoldsPermission.php`
  - `app/Services/Core/ApiTokenService.php`
  - `app/Support/Notifications/StaffRecipients.php`
  - `app/Console/Commands/CreateSubsidiaryCommand.php`, `app/Console/Commands/GrantCompanyMembershipCommand.php`: call `mirrorTemplateRoles` after the grant, and print the granted names or "assign roles in Pengguna".
  - `Modules/Billing/app/Console/RotateLegacyCustomerPasswords.php`, `Modules/Billing/app/Notifications/BillingScheduleFailedNotification.php`
  - `Modules/Billing/app/Services/PaymentAttemptService.php`: only if `alertBillingStaff` is not on `StaffRecipients` after wave 1.
  - `Modules/Reporting/app/Queries/TechnicianPerformanceQuery.php`, `Modules/Reporting/app/Services/TechnicianReportAccess.php`
  - `Modules/Ticketing/app/Http/Controllers/TicketController.php`
  - `Modules/Ticketing/app/Http/Requests/AssignTicketRequest.php`, `StoreTicketCommentRequest.php`, `StoreTicketRequest.php`
  - `Modules/Ticketing/app/Policies/TicketPolicy.php`
  - `resources/js/Pages/AppOnly.tsx`, `resources/js/Pages/Admin/Reports/AuditLog.tsx`, `resources/js/types/index.ts`
  - NEW in `tests/Feature/Roles/`: `RoleNameIndependenceTest.php`, `RoleNameChecksArchitectureTest.php`
  - Rewritten: `tests/Feature/AppOnlyRedirectTest.php`, `tests/Traits/WithoutAppOnlyRedirect.php`, `tests/Feature/Rules/ValidationRuleMessagesTest.php`, `tests/Feature/Admin/EvaluationCompanyScopeTest.php`, `tests/Feature/Reporting/TechnicianPerformanceAuthorizationTest.php`, `TechnicianReportCapabilityRouteTest.php`, `TechnicianReportFlagTest.php`, `tests/Feature/Ticketing/TicketCustomerScopeTest.php`, `TicketAssignScopeTest.php`, `tests/Feature/Billing/BillingFailureNotificationTest.php`, `tests/Feature/Tenant/CompanySwitchTest.php`
- **Consumes:** `PermissionCatalogue` and `User::withPermissionIn` (S1); `CompanyRoleService::mirrorTemplateRoles` (S3).
- **Produces:** `HoldsPermission`; the company-explicit `StaffRecipients::holding`; the permission-based gates of §2.3.
- **Depends on:** wave 1, contracts only. Starts after `t_b51debaa` (R2).
- **Acceptance:** every S2 row of §2.3 is replaced; `RoleNameChecksArchitectureTest` passes; the S2 tests pass.

**S3 · backend · tenant role and user-role management**

- **Owned files:**
  - NEW `app/Services/Core/CompanyRoleService.php`
  - `app/Http/Controllers/Admin/RoleController.php`, `UserController.php`, NEW `UserRoleController.php`
  - NEW in `app/Http/Requests/Admin/Roles/`: `StoreRoleRequest.php`, `UpdateRoleRequest.php`, `DestroyRoleRequest.php`
  - `app/Http/Requests/Admin/StoreUserRequest.php`, `UpdateUserRequest.php`, NEW `UpdateUserRolesRequest.php`
  - `app/Policies/RolePolicy.php`, `app/Policies/UserPolicy.php`
  - `app/Http/Resources/RoleResource.php` (adds `template_key`), `PermissionResource.php` (adds `grantable` when the request user is a tenant `User`), `UserResource.php`
  - `app/Queries/Admin/UserIndexQuery.php` (DELETE: no caller; it duplicates `UserController::filteredQuery`)
  - `app/Support/UserDeletionGuard.php`
  - `routes/admin.php`
  - NEW in `tests/Feature/Roles/`: `TenantRoleManagementTest.php`, `UserRoleAssignmentTest.php`
  - Rewritten: `tests/Feature/AdminPermissionRoleTest.php`, `tests/Feature/AdminMenuAuthorizationTest.php`, `tests/Feature/Admin/RoleServerTableExportTest.php`, `PermissionServerTableTest.php`, `UserFormTest.php`, `tests/Feature/Auth/SecurityTrailTest.php`
- **Consumes:** the S1 models and catalogue.
- **Produces:** `CompanyRoleService` (§4.4), the routes of §4.1, the props of §5.1 and §5.2.
- **Depends on:** wave 1, contracts only.
- **Acceptance:** G1–G8 are enforced and audited; the S3 tests pass.

**S4 · backend + frontend · platform** (UI skills of §5)

- **Owned files:**
  - `app/Services/Platform/PlatformRoleService.php` (DELETE), NEW `RoleTemplateService.php`
  - `app/Services/Platform/TenantExportBuilder.php`: `user_roles.csv` joins roles with `roles.company_id` in the tenant's company ids; columns: user public id, company code, role name, `template_key`.
  - `app/Http/Controllers/Platform/RoleController.php` (DELETE), NEW `RoleTemplateController.php`, NEW `CompanyRoleController.php`
  - `app/Http/Requests/Platform/Roles/StoreRoleRequest.php`, `UpdateRoleRequest.php` (DELETE)
  - NEW `app/Http/Requests/Platform/RoleTemplates/{StoreRoleTemplateRequest,UpdateRoleTemplateRequest}.php`
  - NEW `app/Http/Requests/Platform/CompanyRoles/{StoreCompanyRoleRequest,UpdateCompanyRoleRequest,DestroyCompanyRoleRequest}.php`
  - NEW `app/Http/Resources/RoleTemplateResource.php`
  - `app/Queries/Platform/TenantDetailQuery.php`, `app/Services/Platform/SupportToolsService.php`
  - `app/Actions/Platform/AddTenantSubsidiaryAction.php`: `mirrorTemplateRoles` per member.
  - `app/Support/Platform/TenantTableRegistry.php`: docblock line 21, `roles` is now a tenant table.
  - `routes/platform/roles.php`
  - `resources/js/Layouts/PlatformLayout.tsx`, `resources/js/types/platform.ts`, `resources/js/lib/statuses/platform.ts`
  - `resources/js/Pages/Platform/Roles/{Index,Create,Edit}.tsx` and `components/RoleForm.tsx` (DELETE)
  - NEW `resources/js/Pages/Platform/RoleTemplates/{Index,Create,Edit}.tsx`, `resources/js/Pages/Platform/RoleTemplates/components/TemplateForm.tsx`
  - `resources/js/Pages/Platform/Companies/Show.tsx`, NEW `resources/js/Pages/Platform/Companies/components/RolesSection.tsx`
  - NEW `resources/js/Pages/Platform/Companies/Roles/{Create,Edit}.tsx`, `resources/js/Pages/Platform/Companies/Roles/components/CompanyRoleForm.tsx`
  - `tests/Feature/Platform/PlatformRoleManagementTest.php` (DELETE); NEW `tests/Feature/Platform/RoleTemplateManagementTest.php`, `CompanyRoleRepairTest.php`
  - Rewritten: `tests/Feature/Platform/TenantDetailTest.php`, `SupportActionsTest.php`, `TenantSubsidiaryTest.php`, `TenantExportTest.php`, `PurgeTenantCommandTest.php`, `tests/Feature/Platform/Auth/PlatformAuthenticationTest.php`
- **Consumes:** `CompanyRoleService` (S3); the S1 models; `PermissionGroupSelector`, unchanged and without `grantable`.
- **Produces:** §4.2 and §5.4.
- **Depends on:** wave 1, contracts only.
- **Acceptance:** the S4 tests pass; the operator edits templates and repairs company roles, with an audit trail.

**S5 · frontend · tenant UI** (UI skills of §5)

- **Owned files:**
  - `resources/js/Pages/Admin/Roles/Index.tsx`, `Show.tsx`; NEW `Create.tsx`, `Edit.tsx`, `components/RoleForm.tsx`, `components/DeleteRoleDialog.tsx`
  - `resources/js/Pages/Admin/Users/Index.tsx`, `Create.tsx`, `Edit.tsx`, `Show.tsx`; NEW `components/AssignRolesDialog.tsx`
  - `resources/js/Components/composite/PermissionGroupSelector.tsx`, `permissionGroups.ts`, `permissionLabels.ts`, `permissionGroups.selfcheck.mjs`
  - `resources/js/lib/statuses/audit.ts`
- **Consumes:** §4.1 and the props of §5.1 and §5.2 (S3).
- **Depends on:** wave 2.
- **Acceptance:** typecheck, ESLint and the selfcheck pass; the states of §5.1 show; keyboard and 320 px checks done.

**T1 · qa · test suite A**

- **Owned files:** every file under `tests/Feature/{Admin,Api,Auth,Billing,Integrations,Integrity,Inventory,Ipam,Ledger,Lifecycle,NetworkAsset,Odn}/**` and `Modules/*/tests/**` that matches the patterns of §6.3, EXCEPT the files named in S1–S4.
- **Consumes:** the S1 helpers.
- **Depends on:** wave 2.
- **Acceptance:** no file in the glob still matches R1–R9.

**T2 · qa · test suite B**

- **Owned files:** the same rule for `tests/Feature/{Operations,Platform,PublicId,Push,Reporting,Rules,Service,SPK,Support,Tenant,TenantIsolation,Ticketing,WhatsApp}/**`, the root `tests/Feature/*.php` and `tests/Unit/**`, EXCEPT the files named in S1–S4 and T3.
- **Acceptance:** same as T1.

**T3 · qa · demo data and E2E**

- **Owned files:**
  - `database/seeders/DemoUserSeeder.php`, `DemoTeamSeeder.php`, `E2eFixtureSeeder.php`, `database/seeders/Demo/FoundationDemo.php`
  - `tests/Feature/DemoUserSeederTest.php`, `DemoTeamSeederTest.php`, `DemoDataSeederTest.php`, `CustomerDemoSeederTest.php`
  - NEW `tests/e2e/tenant-roles.spec.ts`; `tests/e2e/auth-roles.spec.ts`, `tests/e2e/datatable-ab.spec.ts`; every other `tests/e2e/*.spec.ts` that asserts a role name (grep in §6.2)
- **Consumes:** S1–S5.
- **Depends on:** wave 3.
- **Acceptance:** the demo company has the eight templates and its personas; the specs of §6.2 are written and typecheck.

**D1 · docs**

- **Owned files:**
  - `AGENTS.md`, `docs/SECURITY.md`
  - `docs/architecture/database.md`, `docs/architecture/overview.md`
  - NEW `docs/architecture/decisions/ADR-004-tenant-scoped-roles.md` (Appendix A)
  - `docs/architecture/decisions/ADR-003-platform-superadmin.md` (status line only)
  - `docs/user-guide/web.md`, `docs/operations/DEPLOYMENT.md`, `docs/business/network.md`, `CHANGELOG.md`
  - `.gitignore`: allowlist `!docs/architecture/decisions/ADR-004-tenant-scoped-roles.md`; without it the ADR is ignored (`.gitignore:53-57`).
- **Content:**
  - `AGENTS.md`: Appendix B.
  - `SECURITY.md`: §2.1–2.3 (templates, per-company matrix); §2.5 (`PermissionCatalogue::release`); §4.3 (customer gate); §5.5 (rewritten); §7.1 (new security actions); residual R4; deltas D1–D9.
  - `database.md`: lines 14-15 and 169-171.
  - `web.md`: around lines 4141 and 4926-4928 (Peran, Pengguna, Templat peran).
  - `DEPLOYMENT.md`: a "Tenant roles release" section (§8.4).
- **Depends on:** wave 2.
- **Acceptance:** no doc says roles are global; the ADR is allowlisted.

### 8.2 File-ownership matrix

Every named file has exactly one owner. T1 and T2 cover disjoint directories; their globs exclude every file named for S1–S4 and T3. Main owns the copies of this design (needs-change).

| Owner | Named files | Count |
|---|---|---|
| S1 | config; 4 models and catalogue; `AppServiceProvider`; 3 core services; 2 SoD rules; 6 migrations; 2 seeders; `TestCase` + 10 traits; 5 new + 7 rewritten tests | 43 |
| S2 | 18 app/module PHP files (1 deleted, 1 new); 3 TS files; 2 new + 11 rewritten tests | 34 |
| S3 | service; 3 controllers; 6 requests; 2 policies; 3 resources; 1 deleted query; deletion guard; `routes/admin.php`; 2 new + 6 rewritten tests | 26 |
| S4 | 3 services (1 deleted); 3 controllers; 7 requests; 1 resource; 3 platform PHP files; registry docblock; route file; 3 shared TS files; 4 deleted + 7 new pages/components; `Show.tsx`; 1 deleted + 2 new + 6 rewritten tests | 42 |
| S5 | 10 page/component files; 4 composite files; `audit.ts` | 15 |
| T1, T2 | glob rule above | — |
| T3 | 4 seeders, 4 seeder tests, E2E specs | 9+ |
| D1 | 11 docs/config files | 11 |

Cross-checks:

- Shared files have one owner each: `RoleResource` and `PermissionResource` belong to S3; `PermissionGroupSelector` belongs to S5 (S4 consumes it unchanged); `StaffRecipients` belongs to S2.
- These files are consumed unchanged and have no owner: `UsersSection.tsx`, `RecordSecurityEvents`, `TenantPurger`, `MeResource`, `EnsureApiAppAccess`, `MyWorkQuery`, `CompanyHierarchyService`, `SeedCompanyDefaults`, `PermissionController`, `bootstrap/app.php`, `CheckTicketSlaBreachesJob`, `InventoryRequestService`, `MaterialReturnService`. The last three send staff notices through `StaffRecipients` after flow-audit wave 1.

### 8.3 Security-review checklist (mandatory, `security-reviewer`)

1. **Cross-company roles.**
   - The `App\Models\Role` scope fails closed.
   - No `Spatie\Permission\Models\Role` remains in `app/` or `Modules/` (architecture test).
   - A foreign `{role}` answers 404 on tenant and platform routes.
   - `move_to` and role names resolve within the company.
   - Spatie `getStoredRole` with a model instance cannot be reached from input.
   - `StaffRecipients` and `withPermissionIn` count a member by his roles in that company only.
   - `user_roles.csv` holds only roles of the tenant's companies.
2. **Privilege escalation.**
   - G1 on roles and users; G2 on the policies.
   - Self-edit: a user cannot add an unheld permission to his own roles; removing his own is bounded by G5.
   - The markers (`UNGUARDED`) open nothing alone: check the portal (needs the link), the technician report (subject only) and the evaluation picker.
3. **SoD per company.** G3 on tenant, template and platform saves; G4 on assignments and moves; the exemption only through `roles.manage`.
4. **Lock-out.** G5 on every path listed in §4.4, under the company row lock, including two concurrent demotions.
5. **Caches.**
   - The registrar cache content across companies.
   - A flush on every write.
   - The `User` relation guard across `runAs`, a company switch, and a queued job that restores relations.
6. **API.**
   - `ensureMayUse` runs under `runAs(idFor)` at login and under auth on every request.
   - A customer token needs `portal.access` plus the link.
   - The technician rule is unchanged; no new payload fields.
7. **Customer and platform separation.**
   - Platform admins have no `HasRoles`.
   - Repairs write `platform` and `support` rows.
   - Pengguna never offers portal roles; `CustomerService` owns them.
8. **Audit.** `role.created`, `role.updated` and `role.deleted` carry the diff and never a secret. `role.attached` and `role.detached` hold names from the right company.
9. **Migration.** The M5 self-check, the single transaction, and `down()` refusing on divergence. The backup step is in the deploy note.
10. **Mass assignment and ids.** `Role::$fillable`; role ULIDs; uniform 404s; the existing enumeration limiter covers the new routes.
11. **Dependencies.** No `composer.json` or `package.json` change, so no new advisories. Still run `composer audit` and `npm audit` and report every advisory (house rule 11).

### 8.4 Deploy note (production)

1. Announce the window. Back up the database (`docs/operations/BACKUP_RESTORE.md`).
2. Run the BEFORE query of §3.5 and save its output. Also run the unmapped-assignment count and the D1 query, and report both to the owner.
3. `php artisan down --retry=60`.
4. Deploy the code: `composer install --no-dev`, then the `npm run build` artefacts.
5. `php artisan migrate --force` (M1–M6). If it fails at M5: stay in maintenance mode, run `php artisan migrate:rollback --step=4`, redeploy the previous release, and continue at step 8.
6. Run `php artisan config:cache` (the `permission.models` config changed), then `php artisan permission:cache-reset` and `php artisan queue:restart`.
7. Run the AFTER query of §3.5 and `diff` it against step 2; the diff must be empty. Spot-check that:
   - every persona can log in;
   - Peran lists the copies;
   - `/app-only` works for a technician.
8. `php artisan up`.

Do not run `db:seed`. `RolePermissionSeeder` is for fresh installs and tests only; releases use migrations. No mobile release is needed.

Rollback:

1. `php artisan down`.
2. `php artisan migrate:rollback --step=6`. M5 `down` refuses once the companies have diverged; in that case, restore the backup from step 1 of the deploy.
3. Redeploy the previous release, then run `config:cache`, `permission:cache-reset`, `queue:restart` and `up`.

---

## Appendix A — ADR draft (`docs/architecture/decisions/ADR-004-tenant-scoped-roles.md`)

```markdown
# ADR-004: Roles per company, permissions in one catalogue

Status: Proposed (2026-10-19). Supersedes the role clause of ADR-003.

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

## What changes in AGENTS.md Company Rules

- **Replaced:** "Role/permission tetap global (`spatie teams=false`). Definisi role ... HANYA dari platform panel ...
  `RolePolicy` menolak seluruh write definisi role." The new text covers roles per company, the catalogue in code,
  templates copied at creation, tenant administration within the guards above, and platform repair. The full text is in
  the design, Appendix B.
- **New rule:** business code checks permissions, never role names. `hasRole`, `->role()` and a role-name `whereHas`
  are forbidden in `app/` and `Modules/`, enforced by an architecture test. The audience markers above take their place.
- **New rule:** a permission check without a signed-in user of the company runs in `CompanyService::runAs`, or uses
  `withPermissionIn` or `StaffRecipients::holding`.
- **Changed:**
  - `Role` is no longer an exception to `BelongsToCompany`.
  - The initialization rule seeds the permission catalogue and the role templates, and no roles.
  - Assigning roles to a member needs membership of the current company only. Changing login fields still needs
    membership of every company of the target.
```

## Appendix B — AGENTS.md Company Rules text (D1 applies it)

Replace `AGENTS.md:109-112` with:

```markdown
- Role milik company: `roles.company_id` (NOT NULL) + `roles.template_key`; model `App\Models\Role` memakai
  `BelongsToCompany` (spatie tetap `teams=false`). Permission = satu katalog di kode (`App\Support\PermissionCatalogue`);
  company menyusun role dari katalog, tidak pernah membuat permission. Company baru menyalin templat platform
  (`role_templates`) saat dibuat; deploy TIDAK PERNAH menyinkronkan role company. Permission baru masuk lewat migration
  rilis `PermissionCatalogue::release()` (templat + salinannya saja).
- Pemegang `roles.manage` mengelola role company-nya di Administrasi → Peran. Guard: tidak memberi permission yang tidak
  dipegang sendiri; tidak mengubah role/user yang lebih berkuasa; SoD berlaku per company; minimal satu user aktif
  pemegang `roles.manage` + `app.web`; role yang masih dipakai dipindah dulu sebelum dihapus; semua tercatat (log
  `security`). Platform memelihara templat dan memperbaiki role company sebagai aksi support teraudit.
- Kode bisnis memeriksa permission, TIDAK PERNAH nama role (`hasRole`, `->role()`, `whereHas('roles', ... name ...)`
  dilarang di `app/` dan `Modules/`). Audiens: `app.web` (back-office), `app.technician` (teknisi lapangan),
  `portal.access` + link `customers.user_id` (akun pelanggan), `evaluation.evaluable` (karyawan yang dinilai).
  Administrator company = pemegang `roles.manage`.
- Cek permission tanpa user dari company itu (login API, job, command, webhook) WAJIB di dalam
  `CompanyService::runAs($companyId, fn)` atau memakai `User::withPermissionIn($permissions, $companyId)`; penerima
  notifikasi staf selalu lewat `StaffRecipients::holding()`. Tanpa company tidak ada role yang cocok (fail closed).
```

D1 also changes these AGENTS.md lines:

- `91-92`: drop `Role` from the exception list.
- `113-116`: add "assign role ke member cukup membership company aktif".
- `128-129`: "install hanya seed katalog permission, templat role dan system setting defaults".
- `378-381`: "(katalog permission + templat role)" and "company dibuat + role dari templat + admin".
