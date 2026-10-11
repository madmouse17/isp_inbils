# customer-sales-attribution-v1 dasar — replacement-contract DRAFT

Status: done
Ledger: docs/plans/INBILS_OMP_BACKLOG.md (cards `t_ae471280`, `t_ffe9fc13`, `t_b70b570d`, `t_60912019`, `t_3d697b14`)

> **Approved by the owner 2026-10-10: the recommended option of every Owner decision below is the contract.** Where a decision lists no recommendation, the most conservative option applies and is recorded under "Approved decisions" when the lane closes. Original acceptance unrecovered.

## Owner decisions (approve in one word: "setuju rekomendasi" or pick per item)

1. **Permission names.** The recovered text says `customers.sales.assign` / `customers.sales.correct` (plural). The repo seeds only singular `customer.*` (AGENTS.md says `{module}.{action}`).
   - A: follow the repo convention, `customer.sales.assign` / `customer.sales.correct`. **Recommended.** It is consistent with `customer.subscription.manage`.
   - B: keep the recovered plural names verbatim. It matches the lost spec, but it makes a second naming style.
   - C: one combined permission. It is simpler, but it loses the assign vs correct separation.
2. **Who counts as an eligible acquirer.** Recommended: A. Tradeoff: A needs no new HR concept, but demo admins (no EmployeeProfile) become non-eligible and default to Unassigned.
   - A: an active user in the same company with an active EmployeeProfile and `customer.create`. **Recommended.**
   - B: an active user in the same company with an active EmployeeProfile, regardless of role or permission. Simple, but it includes technicians.
   - C: a dedicated 'salesperson' marker (new role or permission). Precise, but it needs seeding and HR changes.
   - D: any active user with `customer.create`, no EmployeeProfile needed. It includes admins, but it loosens the HR link.
3. **Which seeded roles get the new permissions.** Recommended: A. Tradeoff: A gives the manager correction power; the owner can revise per role later.
   - A: admin (automatic) and manager get assign and correct, and staff gets assign only. **Recommended.**
   - B: admin and manager get both; staff gets neither.
   - C: admin only. Safest, but the manager and staff attribution workflow breaks.
4. **Who sees the attribution (column, filter, export, display).** Recommended: A. Tradeoff: A is the safest default, but ordinary customer viewers lose context.
   - A: only holders of assign or correct, or a view-attribution permission folded into them. **Recommended.** Safe default, and consistent with 'unauthorized columns omitted'.
   - B: everyone with `customer.view`. Simpler, but sales ownership is exposed to technicians.
   - C: a separate third permission (`customer.sales.view`). Most granular, more seeding.
5. **Create default when the creator lacks the assign capability.** The recovered text says 'create default authenticated eligible but backend authoritative'.
   - A: the server still defaults to the creator if eligible. **Recommended**, reading 'default' literally. Tradeoff: a creator can attribute to themselves without the explicit assign permission.
   - B: default only when the creator has assign. Stricter, but staff creations are left Unassigned.
6. **FK delete behaviour if the acquirer user is deleted.**
   - A: `restrictOnDelete`, as the invoice `cancelled_by` audit-retention precedent. **Recommended**; history is preserved but deleting a user who has acquired customers is blocked.
   - B: `nullOnDelete`, as `customers.user_id` does. Deletion works but attribution silently becomes Unassigned.
7. **History storage.**
   - A: the column only, with activity-log audit rows carrying old, new and reason. **Recommended.** This matches the recovered 'correction reason/audit' and the 'no schema changes' bound.
   - B: add `acquired_at` and `acquired_assigned_by` columns. Better for point-in-time KPI, but it is more schema for v1.
   - C: an append-only `customer_acquirer_changes` table. Full history, but it is a bigger change and the KPI semantics would need agreeing first.
8. **Sorting by acquirer name on the index.**
   - A: filter only, no sort. **Recommended.** Smaller scope, and the closed SORTABLE list stays untouched.
   - B: add sort via an Eloquent subquery order. It is useful, but it adds a performance and test surface.
9. **Unauthorized value forged on a write.**
   - A: silently ignore the key, as the existing update test does for forged keys. **Recommended.**
   - B: return 403 when the key is present without the capability. More explicit, but it breaks the existing 'ignore forged' pattern.

Draft only. Nothing here is approved or implemented, and the repo was not modified.

## Cards — (chain per Hermes `links`)

`t_ae471280` BACKEND (code-executor) → `t_ffe9fc13` UI on Customer (ui-engineer) → `t_b70b570d` TESTER integration+security → `t_60912019` QA-ISP → `t_3d697b14` REVIEWER.

The chain is confirmed by the Hermes `links` in the export and by the ledger predecessor/successor fields (`INBILS_OMP_BACKLOG.md`). The legacy IDs are `t_4b7e2421`, `t_0d86de36`, `t_4a56f865`, `t_609ba86f` and `t_079f8070`.

## Recovery result

**No original acceptance text found in the repo.**
- I searched `docs/` (this includes `docs/plans`, `docs/business`, `docs/architecture`; `docs/process` and `docs/claude-batches` do not exist), `Modules/`, `app/`, `database/`, `routes/`, `tests/`, `resources/js`, `ROADMAP.md`, `TASKS.md`, `CHANGELOG.md` and `AGENTS.md`.
- I searched for the legacy `t_` IDs, `customer-sales-attribution`, `sales`, `salesperson`, `referral`, `acquired_by`, `acquirer` and `customers.sales`.
- The only hits are the ledger entries and snapshot bodies. The ledger says: "scope/acceptance: belum pulih; snapshot body hanya recovery warning" (`docs/plans/INBILS_OMP_BACKLOG.md`, entries at roughly lines 466, 570, 762, 810 and 1001).
- Every card body in the Hermes export is only the "Recovered from pre-reinstall…" warning.
- Nothing in the repo contains `acquired_by_user_id`.

**Partial original acceptance found in Hermes, not in the repo.** It is the `ui-engineer` comment on `t_ffe9fc13` (export Hermes board export (read-only snapshot, removed after the audit); also in `docs/plans/INBILS_OMP_SOURCE.json` around line 4969, truncated there). It says it was "recovered … from pre-reinstall session evidence". Full text:

> "UI depends on backend contract B1 (nullable customers.acquired_by_user_id, server-authoritative eligible acquirer props/capabilities, correction reason/audit, resource/index/export contracts). Required UI scope: Customer Create/Edit/Show/Index with canonical SearchSelect/DataTable; create default authenticated eligible but backend authoritative; explicit assignment only with customers.sales.assign; correction only with customers.sales.correct plus reason; inactive historical acquirer shown but not selectable; null/noneligible as Unassigned; unauthorized controls/columns/filter omitted; loading/error/empty/retry, a11y, responsive/dark; no KPI/reporting/schema changes. Original gate parent t_4b7e2421 and Tester child t_4a56f865 are absent from restored board, so dependency cannot be verified here."

This fragment gives:
- The column name `customers.acquired_by_user_id` (nullable).
- Two capabilities, written as `customers.sales.assign` and `customers.sales.correct`.
- The correction rule (a reason is required).
- The UI rules.
- The "no KPI/reporting/schema changes" bound for the UI card.

The backend contract "B1" is only named in parentheses. Its eligibility rule, audit shape, export columns and permission seeding are not recoverable. Everything below beyond the quoted fragment is a proposal.

**Hermes run and comment facts that matter**
- `t_ffe9fc13`:
  - A claim was auto-reclaimed.
  - A run then ended `blocked` because PHP/Composer/MySQL were missing, and the backend parent plus the Tester/QA/Reviewer children were absent from the restored board.
  - Later the `isp` freeze comment: "dependency graph legacy hilang".
- `t_3d697b14` (reviewer):
  - `block_kind=capability`. Its reviewer comment says Node `typecheck` and `build` passed but php/composer/mysql were missing, and that repo and session search found no `customer-sales-attribution-v1` specification.
  - An `isp` RECOVERY AUDIT comment says the reviewer gate is valid, parent QA `t_60912019` is attached, and it must stay blocked until the backend→UI→Tester→QA chain is done "serta acceptance customer-sales-attribution-v1 tersedia".
  - On 2026-10-01 the `isp` comment says the runtime prerequisites (PHP+pdo_mysql, Composer, `inbils_test` guard, worker routes, Chromium, E2E) are verified. It says: "jangan dispatch sampai seluruh parent scheduled substantif PASS dan kontrak produk dipulihkan".
- The backend, tester and QA cards have only the `isp` freeze comment and one `scheduled` run each.
- The ledger marks all five `needs_recovery`, phase `recovery`. Lines ~94 and ~255 order it "Sales dasar lalu KPI".

## Current implementation map

**Customer model and module**
- `app/Models/Core/Customer.php`:
  - Uses BelongsToCompany, SoftDeletes, InteractsWithMedia and LogsActivity (`logFillable`, `logOnlyDirty`).
  - `$fillable`: code, user_id, name, type, email, phone, tax_id, contact_person, area_coverage_id, notes, is_active.
  - Relations: addresses, contacts, subscriptions, `user()` (the customer's own portal login), `areaCoverage()`.
  - **No acquirer, created_by, sales or referral field.**
- `customers` schema (`database/migrations/2026_06_30_220000_create_customers_table.php` plus `2026_08_11_145559_add_user_id_to_customers_table.php`): no `created_by` and no acquirer column.
- `docs/business/customer.md` lists `users ──< customers (created_by)`, but that column is not in the migrations. This is a docs/schema mismatch, so do not treat it as an existing creator column.
- `Modules/Customer` is an empty nwidart scaffold:
  - Its stub controller returns views.
  - `modules_statuses.json` has `Customer: false`.
  - `docs/architecture/domain-boundaries.md` and `modules.md` say Customer is shared Core.
  - All real customer code is in `app/`.
- Routes (`routes/admin.php`):
  - `Route::resource('customers', CustomerController::class)`, `customers/export` and the address, contact and subscription sub-routes.
- `app/Http/Controllers/Admin/CustomerController.php`:
  - `index` returns `customers` (CustomerResource collection), `filters` (type, status, search, sort, direction, per_page) and `can` (create, export, update, delete).
  - `filteredQuery` is shared by index and export.
  - SORTABLE is a closed list: code, name, type, phone, is_active, created_at.
  - `store` calls `CreateCustomerOnboardingAction::execute($request->validated(), $request->user()->id)`.
  - `update` uses `safe()->only([...])` and never touches `user_id` or `company_id`. `tests/Feature/CustomerPageTest.php` asserts that forged keys are ignored.
  - `export` has columns Code, Name, Type, Email, Phone, Status and is gated by `customer.export`.
  - `show` and `edit` return `CustomerResource`; `edit` returns `can.update/address/contact`.
- `StoreCustomerRequest` authorizes `customer.create`. Onboarding also needs `customer.address.manage`, `customer.subscription.manage` and `spk.create`. `UpdateCustomerRequest` authorizes `customer.update`.
- `CustomerResource` has no acquirer fields. `CustomerPolicy` has view, create, store, update, edit, delete.
- `CreateCustomerOnboardingAction` runs in a transaction:
  - `CustomerService::createWithUser($data)` does `Customer::query()->create($data)`, so a new key in `$data` would be mass-assigned.
  - It then creates the portal user with the `customer` role, plus addresses, contacts, subscription and installation SPK, and an `AuditService::log('customer','onboarded',…)` entry.
  - `$createdBy` is used only for the SPK `created_by`.
- Frontend:
  - `resources/js/Pages/Admin/Customers/{Index,Create,Edit,Show}.tsx`.
  - `Components/composite/CustomerDataFields.tsx` holds the shared fields. `CustomerDataValue` is used by `Edit`, and `Create` has its own `CustomerForm`.
  - Index uses `useServerTable`, `DataTable`, `ExportMenu` and `can` props. The sidebar gates on `customer.view`.
  - The canonical `SearchSelect` is `Components/ui/SearchSelect.tsx`. Usage prior art: SPK technician assignment in `Pages/Admin/SPK/Show.tsx`.

**Employees / HR**
- `EmployeeProfile` (`app/Models/Core/EmployeeProfile.php`): unique `user_id`, `organization_id`, `status` (default 'active'), `employee_number`, `company_id`.
- `User` has `employeeProfile()` and `customer()`. `User` has no BelongsToCompany and no SoftDeletes. Company membership comes from `users.company_id`.
- A `company_user` pivot table exists, but eligible-user queries use `users.company_id`.
- `OrganizationUnit` has `path` and `type`. The test `TechnicianPerformanceAuthorizationTest` creates a unit with path 'SALES', but no seeded 'sales' org or role exists.
- Seeded roles: admin, manager, staff, technician, customer (`RolePermissionSeeder`).
- `DemoUserSeeder`: the admin has `employee=false` (no EmployeeProfile); manager, staff and technician have profiles.
- **No salesperson role or permission exists.**

**Eligible-user prior art**
- `DispatchQueueQuery::eligibleHandlers` / `eligibleTechnicians`: active user, same company, permission-based, plus an active EmployeeProfile for technicians.
- `IsActiveTechnician` rule, `SpkService` assign validation and `TechnicianPerformanceQuery::eligibleTechnicians`.

**Invoices and subscriptions (where attribution would hang)**
- `ServiceSubscription` (`app/Models/Core/ServiceSubscription.php`): `customer_id`, `service_package_id`, `mrc_amount` and so on. No actor/acquirer.
- `Modules/Billing` `Invoice`: `customer_id`, `subscription_id`, `work_order_id`, `created_by` (users), `cancelled_by`. Invoices carry no salesperson.
- `BusinessMetricsQuery` already computes revenue as `Invoice::where('status','paid')`.
- A later KPI lane can attribute revenue only via `customers.acquired_by_user_id` (customer-level). Subscription-level attribution would be a separate design.

**Permissions / audit**
- Existing names are singular: `customer.view`, `customer.create`, `customer.update`, `customer.delete`, `customer.address.manage`, `customer.subscription.*`, `customer.export` and `customer.manage`.
- The recovered text uses plural `customers.sales.assign` and `customers.sales.correct`. This conflicts with the repo's `customer.*` convention (AGENTS.md: `{module}.{action}`). See decision 1.
- Neither permission is seeded. Admin gets `$permissions` wholesale, so a seeded addition auto-flows to admin.
- Customer `LogsActivity` uses `logFillable`. Keeping the new column out of `$fillable` means it is not auto-logged; use `AuditService::log` with old/new/reason, company-aware.

**Gaps (everything required is absent)**
- Migration and column.
- Relation and Resource fields.
- Eligible-acquirer query.
- Permissions and seeder update.
- Assign/correct action with reason.
- Index filter, column and export column.
- UI controls.
- Tests.
- Docs.

## Problem Statement

An ISP admin cannot record which employee acquired a customer. Sales-performance reporting (the separate KPI lane) therefore has nothing to join. Customers have no sales ownership, and there is no controlled way to assign or correct it. A free-text or loosely validated user id would permit cross-tenant forgery and silent reassignment, and would corrupt future commission and KPI numbers. Past acquirers who become inactive must still be shown for history without being selectable.

## Solution

Add nullable `customers.acquired_by_user_id` as customer-level attribution only. All writes go through one server-authoritative Action in `app/`. The server computes the eligible-acquirer set, the actor capabilities, the create default and the correction audit. The UI renders only what the server returns.

- Create: the authenticated user is the default acquirer if eligible. An explicit different acquirer requires the assign capability.
- Edit: assigning when currently unassigned requires the assign capability. Changing or clearing an existing acquirer is a correction and requires the correct capability plus a reason. Both are audited.
- Resource, Index (column and filter) and export expose attribution only to users allowed to see it.
- Historical inactive or ineligible acquirers display by name (marked inactive) but cannot be newly selected. null or non-eligible shows as "Unassigned".

## User Stories

1. As an admin with the assign capability, I can set the acquiring employee on a new customer so the sale is attributed.
2. As the authenticated eligible creator, my account is pre-selected as acquirer on the create form, and the server applies the same default even if the field is absent.
3. As a user without the assign capability, I never see the acquirer control on create or edit, and a forged `acquired_by_user_id` is ignored.
4. As a user with the assign capability, I can pick only eligible acquirers from my company in a searchable select.
5. As a user with the correct capability, I can change or clear an existing acquirer only by entering a reason, and the correction is audited with old value, new value, reason, actor and company.
6. As a user without the correct capability, I cannot change an already-set acquirer (the control is hidden/disabled and the server rejects it).
7. As a viewer, a customer whose acquirer is later deactivated still shows that person's name with an "inactive" marker, and they are not offered as a choice.
8. As a viewer, a customer with a null or non-eligible acquirer shows "Unassigned".
9. As a user allowed to see attribution, I can filter the customer list by acquirer (including Unassigned) and the column appears in the DataTable.
10. As an exporter allowed to see attribution, CSV/PDF export includes the Acquired By column and respects the same filter and company scope.
11. As a tenant admin, I can never assign or read an acquirer from another company.
12. As the QA reviewer, I can verify that loading, error, empty and retry states of the acquirer SearchSelect, keyboard access, responsive layout and dark mode all work.
13. As the owner, I can see in the audit log who set or corrected an acquirer, when, and why.

## Implementation Decisions

- **Schema**
  - One migration adds `customers.acquired_by_user_id` (nullable FK to `users`) with an index `(company_id, acquired_by_user_id)`.
  - No backfill: existing customers stay Unassigned.
  - The column is excluded from `$fillable` and written only by the attribution Action.
  - The FK delete behaviour is decision 6.
- **Model**
  - Add an `acquirer()` BelongsTo to User.
  - The user lookup must never be unscoped by company.
- **Domain placement**
  - Customer is Core. New code lives in `app/`: a Query for eligible acquirers, a single Action `AssignCustomerAcquirer`, a FormRequest or validation Rule, and the policy method(s).
  - No raw SQL; Eloquent only.
- **Eligibility (single source of truth)**
  - One query/rule used by the Create/Edit props, validation and Resource display state.
  - The rule must filter explicitly by `users.company_id` (User has no global scope) and `is_active = true`.
  - The exact definition is decision 2.
  - Validation re-checks eligibility server-side. An ineligible id returns 422, a foreign-company id never validates, and the response must not leak the existence of other tenants' users.
- **Permissions**
  - Two new permissions are seeded in `RolePermissionSeeder`: assign and correct. Naming is decision 1. Role mapping is decision 3.
  - Policy methods `assignAcquirer` and `correctAcquirer` on `CustomerPolicy` (or equivalent gates) back the `can` props.
  - A visibility rule decides who sees attribution (decision 4).
  - Existing databases need the seeder re-run or a data migration to create the permissions. State that in the deployment notes.
- **Write semantics (store/update)**
  - Create: default = authenticated user if eligible, else null. An explicit value is honored only with the assign capability.
  - Update: null→value requires assign. value→other or value→null requires correct plus a reason (required, bounded length). No-op submissions write no audit row.
  - For an actor without the capability, the key is stripped (the existing `test_update_customer_ignores_forged_related_and_tenant_payload` pattern). For an authorized actor, a bad value returns 422.
  - The write runs inside the onboarding transaction on create. It must not alter the existing single-submission contract or the other `safe()->only` list.
- **Audit**
  - `AuditService::log('customer', 'acquirer_assigned'|'acquirer_corrected', [old, new, reason, actor], $customer)`. This carries the company_id automatically.
  - Whether to also add a history table is decision 7.
- **Resource / Inertia props**
  - `CustomerResource` adds `acquired_by_user_id` and an `acquirer {id,name,is_active}` summary, only when the viewer may see attribution.
  - Create/Edit receive `acquirerOptions` (eligible only), `acquirerDefaultId` (create only) and `can.assignAcquirer` / `can.correctAcquirer`. Show has the display. Index gets the column and a filter option list.
  - Index accepts a new `acquired_by` filter (id or `unassigned`) added to the `filters` prop; sorting by acquirer is decision 8.
  - Export adds the Acquired By column only for users allowed to see attribution, and uses the same filter.
  - Eager-load `acquirer` to avoid N+1 on index and export.
- **UI**
  - Reuse the `SearchSelect` primitive and the composite DataTable. Add the field to the composite customer fields (`CustomerDataFields`) only if the layout is shared; otherwise add a dedicated composite.
  - Do not inline raw elements.
  - Reason is a Textarea shown only in the correction flow.
  - Cover loading/error/empty/retry, a11y, responsive and dark mode.
  - Reuse existing `can` conventions. No KPI, reporting or further schema changes in the UI card.
- **Docs**
  - Update `docs/business/customer.md` (permissions, entity fields, the stale `created_by` line) and `CHANGELOG.md`.

## Testing Decisions

Tests use MySQL `inbils_test` with RefreshDatabase.

- **Seams**
  - The eligible-acquirer Query.
  - The assign/correct Action.
  - The policy `can` props.
  - Resource, index and export output.
- **Feature tests**
  - Style follows `tests/Feature/CustomerPageTest.php`, `tests/Feature/Admin/CustomerServerTableExportTest.php` and the cross-company assertions in `TechnicianPerformanceAuthorizationTest`.
  - Seed with `RolePermissionSeeder` and custom roles with `syncPermissions`. Use `Customer::factory`, `Company::factory`, `EmployeeProfile::factory` and `$this->actingAs($user)`.
- **Tester card `t_b70b570d` — integration + security**
  - Cross-tenant: a foreign-company user id → 422, and the foreign customer stays 404. Eligible options never leak foreign users.
  - Forged `acquired_by_user_id` without the capability is ignored; with assign but not correct, changing an existing value is 403/422.
  - Correction without a reason → 422.
  - Inactive, ineligible or `customer`-role user → 422, but is still displayed in the Resource when historically set.
  - The create default is applied when eligible; null otherwise (e.g. an admin without an EmployeeProfile).
  - Mass assignment: the column is not fillable, and onboarding's `Customer::create($data)` path cannot be abused.
  - Audit row content: old, new, reason, actor, company_id.
  - Index filter (id and unassigned) is company-scoped, the export respects the filter and permission, and there is no N+1.
  - The permission seeding assertion (extend the existing `RolePermissionSeederTest`).
  - Race: concurrent corrections [INFERENCE: optional; optimistic check only if the owner wants it].
- **UI/E2E**
  - Playwright prior art: `tests/e2e/customer-edit.spec.ts`, `customer-subscription.spec.ts`, `datatable-customers.spec.ts`, plus `fixtures.ts` and `E2eFixtureSeeder`.
  - The demo admin has no EmployeeProfile, so the E2E fixture must provide an eligible acquirer, or use the manager.
  - Add a spec for create default, assign, correct-with-reason, hidden controls for a view-only user, the index filter and the Unassigned display.
- **QA-ISP** manually verifies: loading/error/retry, keyboard focus order and labels, responsive and dark mode, export, and the inactive-historical display.
- **Reviewer** checks the diff against this contract, AGENTS.md (company scoping, thin controller, no raw SQL, naming) and the above test evidence.

## Out of Scope

- KPI/reporting (the separate lane `t_40d53598` → `t_c4b6971b`, with tester, QA and reviewer cards `t_6c27c12e`, `t_58229ddb`, `t_54d82ff9`).
- Commission or compensation calculation.
- Subscription-level or invoice-level attribution.
- Backfilling existing customers.
- Auto-assignment rules, referral codes, CRM prospects and a salesperson role or org-unit model (unless decided in decision 2).
- Bulk reassignment, import, customer self-service and the customer portal.
- Multi-company or Head Company handling.
- Changes to Hermes or `~/.hermes`.

## Dependencies / ordering vs other lanes

- Chain: backend → UI → tester → QA → reviewer, as above. The UI card needs the backend contract B1 (final props, `can` flags, resource and export shape) before starting. The restored board lacked the parent at the time, but the ledger and the `links` now carry the full chain.
- This lane must complete before the KPI sales lane (`t_40d53598`, `t_c4b6971b`, `t_6c27c12e`, `t_58229ddb`, `t_54d82ff9`). The ledger says 'Sales dasar lalu KPI'. KPI depends on the acquirer column and on the semantics decided in decision 7.
- Role persona/SoD lane (4 cards): it may restructure roles and permissions, so decision 3 should be reconciled with it. Run this lane first, or give it a note that the two new permissions exist.
- Sidebar IA, DataTable and Loading/progress lanes touch Customers navigation or the table, so expect minor conflicts. `Customers/Index.tsx` is also touched by the DataTable lanes, which are done per the ledger (DataTable C/D/E replacement drafts exist).
- The ledger orders recovery after the Location gate; the 'Phase Gate -> Location Map' sequence precedes it. [INFERENCE: coding must wait for owner approval of this contract.]
- No dependency on Reporting V2 or Technician Performance, except that the technician-performance query's eligible-user pattern is reusable prior art (it excludes the customer role).

## Approved decisions

Owner approved the recommended option of every decision on 2026-10-07. Applied by backend `t_ae471280` and UI `t_ffe9fc13` (2026-10-10):

1. **Permission names: A.** `customer.sales.assign` and `customer.sales.correct`, seeded in `RolePermissionSeeder`.
2. **Eligible acquirer: A.** Active user of the same company, with an active (not soft-deleted) EmployeeProfile of that company, who holds `customer.create` (direct or through a role). One query (`CustomerAcquirerQuery::eligible`) backs the Create/Edit option lists, the validation rule (`IsEligibleCustomerAcquirer`) and the create default.
3. **Role grants: A.** admin gets both (wholesale), manager gets assign and correct, staff gets assign only, technician and customer get none.
4. **Visibility: A.** Column, filter, filter options, Resource fields, Show display and the Acquired By export column are shown only to holders of `customer.sales.assign` or `customer.sales.correct` (`CustomerPolicy::viewAcquirer`). The `acquired_by` filter is ignored for everyone else, so it cannot be used to infer attribution.
5. **Create default: A.** When `acquired_by_user_id` is absent (or the creator cannot assign), the server sets the creator as acquirer if the creator is eligible, otherwise leaves it null. An explicit value (including `null` = Unassigned) is honored only with the assign capability.
6. **FK delete: A.** `customers.acquired_by_user_id` is a nullable FK to `users` with `restrictOnDelete`, plus index `(company_id, acquired_by_user_id)`. Deleting a user who acquired customers is refused with a validation message instead of a raw database error.
7. **History: A.** Column only. Every assign/correction writes an activity-log row (`customer` log, `acquirer_assigned` or `acquirer_corrected`) with `old`, `new`, `reason`, `actor_id` and the company id. No-op submissions write nothing.
8. **Sorting: A.** Filter only (`acquired_by` = id or `unassigned`); `SORTABLE` is unchanged.
9. **Forged value: A.** A user holding neither capability has the keys silently ignored. A user holding a capability but not the one the change needs (assign-only changing a set acquirer, correct-only assigning from null) gets 403. Ineligible ids and corrections without a reason are 422.

Deployment: re-run `php artisan db:seed --class=RolePermissionSeeder --force` after `php artisan migrate` (idempotent). Custom roles are not updated by the seeder; grant the two permissions in the Roles screen.

## Closure

Done 2026-10-10: QA PASS, reviewer APPROVED (after P1/P2 fixes). Evidence in the ledger section "Sales attribution base replacement closure (2026-10-10)".
