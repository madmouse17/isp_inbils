# Batch 1 — Core ERP Foundation Implementation Plan

> Branch: `batch-1-core-erp-foundation`
> Base: `7bf4921` (Batch 0 complete on main)
> Status: PLANNING — awaiting approval

## 1. Current Core Architecture Review

### What Exists (app/Models/Core/)

| Model | Table | Purpose | Traits | Relations |
|-------|-------|---------|--------|-----------|
| `Company` | `companies` | Tenant root (multi-tenant) | `LogsActivity` | HasMany User |
| `User` | `users` | Auth + basic identity | `HasRoles` (spatie) | BelongsTo Company, HasMany Activity |
| `Setting` | `settings` | System-global key-value | — | — |
| `Location` | `locations` | Topology hierarchy (region/area/pop/rack/site) | `BelongsToCompany`, `HasFactory`, `LogsActivity`, `SoftDeletes` | Self-ref parent/children |
| `Customer` | `customers` | ISP customer master | `BelongsToCompany`, `HasFactory`, `LogsActivity`, `SoftDeletes` | HasMany Address/Contact/Subscription |
| `CustomerAddress` | `customer_addresses` | Customer address | `BelongsToCompany`, `LogsActivity`, `SoftDeletes` | BelongsTo Customer |
| `CustomerContact` | `customer_contacts` | Customer contact person | `BelongsToCompany`, `LogsActivity`, `SoftDeletes` | BelongsTo Customer |
| `ServiceSubscription` | `service_subscriptions` | Subscription lifecycle | `BelongsToCompany`, `LogsActivity`, `SoftDeletes` | BelongsTo Customer/Package/Address |
| `EmployeeEvaluation` | `employee_evaluations` | Performance evaluation (polymorphic) | `BelongsToCompany`, `LogsActivity`, `SoftDeletes` | BelongsTo User (employee/evaluator) |

### What Exists (app/Services/Core/)

| Service | Methods | DB::Transaction | AuditService |
|---------|---------|-----------------|--------------|
| `CompanyService` | currentId, current, setting, updateProfile, updateSettings, resetCache | No (read-heavy) | No |
| `SettingService` | get, set, flush, flushAll | No (cache-backed) | No |
| `LocationService` | create, update, move, delete, tree, recomputePath, recurseChildrenPath, hasCycle | Yes | No |
| `SubscriptionService` | create, activate, suspend, reactivate, terminate, generateCode | Yes | Yes |
| `AuditService` | log, logModelActivity | No (wrapper) | N/A |
| `SetupWizardService` | create, isRequired | Yes | Yes |

### What Exists (app/Policies/)

`CompanyPolicy`, `UserPolicy`, `RolePolicy`, `LocationPolicy`, `CustomerPolicy`, `SubscriptionPolicy`, `EmployeeEvaluationPolicy`

### What Exists (Middleware)

| Middleware | Alias | Purpose |
|------------|-------|---------|
| `RedirectIfNoCompany` | (web stack) | Redirect to /setup if no company |
| `RequireNoCompany` | `require.no.company` | Block /setup if company exists |
| `RequireHasCompany` | `require.has.company` | Block /admin if no company |
| `HandleInertiaRequests` | (web stack) | Inertia shared props (auth, company, flash, app) |

### What Exists (Frontend)

| Layout | Purpose |
|--------|---------|
| `AdminLayout.tsx` | Sidebar + Topbar + Dark mode. Sidebar items permission-gated via `usePermission().can()` |
| `SetupLayout.tsx` | Wizard minimal layout |

### Gaps Identified (from AUDIT_REPORT.md)

1. **No organization structure** — no branch/area/unit/team hierarchy
2. **No employee profile** — User has no employee_number, skills, team, vehicle
3. **No approval engine** — high-risk actions have no approval flow
4. **No notification engine** — no in-app notification system
5. **No document attachment** — no generic polymorphic file attachment
6. **No number sequence service** — each service has its own `generateCode()`, no central control
7. **No events/jobs** — only 2 events (CompanyCreated, Login), 0 jobs

---

## 2. Proposed Database Migrations

### Batch 1A — Organization & Employee

```sql
-- organization_units: company → branch → area → unit → team
CREATE TABLE organization_units (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    company_id BIGINT NOT NULL FK→companies.id (restrict),
    parent_id BIGINT NULL FK→organization_units.id (restrict),
    code VARCHAR(50) NOT NULL,
    name VARCHAR(255) NOT NULL,
    type ENUM('company','branch','area','unit','team') NOT NULL DEFAULT 'branch',
    path VARCHAR(500) NULL,  -- materialized: "BRANCH-JKT > AREA-UTARA > UNIT-FIBER"
    address TEXT NULL,
    phone VARCHAR(50) NULL,
    email VARCHAR(255) NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP, updated_at TIMESTAMP, deleted_at TIMESTAMP NULL,
    UNIQUE(company_id, code),
    INDEX(company_id, parent_id),
    INDEX(company_id, type),
    INDEX(company_id, path)
);

-- employee_profiles: 1:1 with users
CREATE TABLE employee_profiles (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    company_id BIGINT NOT NULL FK→companies.id (restrict),
    user_id BIGINT NOT NULL UNIQUE FK→users.id (cascade),
    organization_id BIGINT NULL FK→organization_units.id (restrict),
    employee_number VARCHAR(50) NOT NULL,
    phone VARCHAR(50) NULL,
    hire_date DATE NULL,
    status ENUM('active','inactive','terminated') NOT NULL DEFAULT 'active',
    skills JSON NULL,  -- ["fiber","OLT","wireless"]
    vehicle_id BIGINT NULL FK→vehicles.id (set null),
    notes TEXT NULL,
    created_at TIMESTAMP, updated_at TIMESTAMP, deleted_at TIMESTAMP NULL,
    UNIQUE(company_id, employee_number),
    INDEX(company_id, organization_id),
    INDEX(company_id, status)
);

-- vehicles: technician transport
CREATE TABLE vehicles (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    company_id BIGINT NOT NULL FK→companies.id (restrict),
    plate_number VARCHAR(20) NOT NULL,
    type VARCHAR(50) NULL,  -- motorcycle, car, truck
    brand VARCHAR(100) NULL,
    model VARCHAR(100) NULL,
    purchase_date DATE NULL,
    is_active BOOLEAN DEFAULT TRUE,
    notes TEXT NULL,
    created_at TIMESTAMP, updated_at TIMESTAMP, deleted_at TIMESTAMP NULL,
    UNIQUE(company_id, plate_number),
    INDEX(company_id, is_active)
);
```

### Batch 1B — Number Sequence

```sql
-- number_sequences: centralized code generation
CREATE TABLE number_sequences (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    company_id BIGINT NOT NULL FK→companies.id (restrict),
    entity_type VARCHAR(50) NOT NULL,  -- 'invoice','spk','ticket','customer','subscription','network_asset'
    prefix VARCHAR(20) NOT NULL,  -- 'INV','SPK','TKT','CUS','SUB','AST'
    next_number INT NOT NULL DEFAULT 1,
    padding INT NOT NULL DEFAULT 5,
    year_suffix BOOLEAN NOT NULL DEFAULT TRUE,  -- include year in code: INV-2026-00001
    created_at TIMESTAMP, updated_at TIMESTAMP,
    UNIQUE(company_id, entity_type)
);
```

### Batch 1C — Document Attachment

```sql
-- document_attachments: polymorphic generic file attachment
CREATE TABLE document_attachments (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    company_id BIGINT NOT NULL FK→companies.id (restrict),
    attachable_type VARCHAR(255) NOT NULL,  -- 'WorkOrder','Ticket','Customer','Invoice','SPK'
    attachable_id BIGINT NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NULL,
    mime_type VARCHAR(100) NULL,
    size_bytes INT NULL,
    category VARCHAR(50) NULL,  -- 'photo','document','contract','evidence','other'
    caption VARCHAR(255) NULL,
    uploaded_by BIGINT NOT NULL FK→users.id (restrict),
    created_at TIMESTAMP, updated_at TIMESTAMP,
    INDEX(company_id, attachable_type, attachable_id),
    INDEX(attachable_type, attachable_id, category)
);
```

### Batch 1D — Notification

```sql
-- notifications: in-app notification queue
CREATE TABLE notifications (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    company_id BIGINT NOT NULL FK→companies.id (restrict),
    user_id BIGINT NOT NULL FK→users.id (cascade),
    type VARCHAR(50) NOT NULL,  -- 'spk.assigned','ticket.escalated','invoice.overdue','approval.requested'
    title VARCHAR(255) NOT NULL,
    body TEXT NULL,
    link VARCHAR(500) NULL,  -- URL to navigate to
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP, updated_at TIMESTAMP,
    INDEX(company_id, user_id, is_read),
    INDEX(company_id, user_id, created_at)
);
```

### Batch 1E — Approval Engine

```sql
-- approval_requests: generic approval workflow
CREATE TABLE approval_requests (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    company_id BIGINT NOT NULL FK→companies.id (restrict),
    approvable_type VARCHAR(255) NOT NULL,  -- 'WorkOrder','StockAdjustment','InvoiceCancel'
    approvable_id BIGINT NOT NULL,
    requested_by BIGINT NOT NULL FK→users.id (restrict),
    approver_id BIGINT NULL FK→users.id (restrict),
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    priority ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    reason TEXT NULL,  -- requester reason
    approver_note TEXT NULL,  -- approver note on approve/reject
    approved_at TIMESTAMP NULL,
    rejected_at TIMESTAMP NULL,
    created_at TIMESTAMP, updated_at TIMESTAMP,
    UNIQUE(approvable_type, approvable_id),  -- one active approval per subject
    INDEX(company_id, status),
    INDEX(company_id, approver_id, status),
    INDEX(company_id, requested_by)
);
```

---

## 3. Entity Relationship Design

```
companies
  ├── organization_units (self-ref: company→branch→area→unit→team)
  │       └── employee_profiles (organization_id FK)
  ├── users
  │       └── employee_profiles (user_id 1:1)
  │               └── vehicles (vehicle_id FK, set null on delete)
  ├── number_sequences (entity_type + prefix per company)
  ├── document_attachments (polymorphic: attachable_type + attachable_id)
  ├── notifications (user_id FK)
  └── approval_requests (polymorphic: approvable_type + approvable_id)
          ├── requested_by → users.id
          └── approver_id → users.id (nullable until assigned)
```

### Key Relationships

| From → To | Type | Cardinality | Notes |
|-----------|------|-------------|-------|
| Company → OrganizationUnit | HasMany | 1:N | Multi-tenant scoped |
| OrganizationUnit → OrganizationUnit | Self-ref | parent/children | Materialized path, cycle prevention |
| User → EmployeeProfile | HasOne | 1:1 | `user_id` UNIQUE constraint |
| EmployeeProfile → OrganizationUnit | BelongsTo | N:1 | Nullable (unassigned) |
| EmployeeProfile → Vehicle | BelongsTo | N:1 | Nullable |
| Vehicle → OrganizationUnit | BelongsTo | N:1 | Nullable (branch assignment) |
| DocumentAttachment → any Model | MorphTo | polymorphic | `attachable_type` + `attachable_id` |
| Notification → User | BelongsTo | N:1 | Cascade delete |
| ApprovalRequest → any Model | MorphTo | polymorphic | `approvable_type` + `approvable_id` |
| ApprovalRequest → User (requested_by) | BelongsTo | N:1 | Restrict delete |
| ApprovalRequest → User (approver_id) | BelongsTo | N:1 | Nullable, restrict delete |

---

## 4. File List to Change (by Sub-Batch)

### Batch 1A — Organization & Employee

| Type | File | Action |
|------|------|--------|
| Migration | `database/migrations/2026_07_01_160000_create_organization_units_table.php` | NEW |
| Migration | `database/migrations/2026_07_01_160001_create_vehicles_table.php` | NEW |
| Migration | `database/migrations/2026_07_01_160002_create_employee_profiles_table.php` | NEW |
| Model | `app/Models/Core/OrganizationUnit.php` | NEW |
| Model | `app/Models/Core/Vehicle.php` | NEW |
| Model | `app/Models/Core/EmployeeProfile.php` | NEW |
| Service | `app/Services/Core/OrganizationService.php` | NEW |
| Controller | `app/Http/Controllers/Admin/OrganizationController.php` | NEW |
| Controller | `app/Http/Controllers/Admin/EmployeeController.php` | NEW |
| Controller | `app/Http/Controllers/Admin/VehicleController.php` | NEW |
| FormRequest | `app/Http/Requests/Admin/StoreOrganizationRequest.php` | NEW |
| FormRequest | `app/Http/Requests/Admin/UpdateOrganizationRequest.php` | NEW |
| FormRequest | `app/Http/Requests/Admin/StoreEmployeeRequest.php` | NEW |
| FormRequest | `app/Http/Requests/Admin/UpdateEmployeeRequest.php` | NEW |
| FormRequest | `app/Http/Requests/Admin/StoreVehicleRequest.php` | NEW |
| FormRequest | `app/Http/Requests/Admin/UpdateVehicleRequest.php` | NEW |
| Resource | `app/Http/Resources/OrganizationResource.php` | NEW |
| Resource | `app/Http/Resources/EmployeeResource.php` | NEW |
| Resource | `app/Http/Resources/VehicleResource.php` | NEW |
| Policy | `app/Policies/OrganizationPolicy.php` | NEW |
| Policy | `app/Policies/EmployeePolicy.php` | NEW |
| Policy | `app/Policies/VehiclePolicy.php` | NEW |
| Factory | `database/factories/OrganizationUnitFactory.php` | NEW |
| Factory | `database/factories/VehicleFactory.php` | NEW |
| Factory | `database/factories/EmployeeProfileFactory.php` | NEW |
| Routes | `routes/admin.php` | MODIFY (add org/employee/vehicle routes) |
| TS Type | `resources/js/types/organization.d.ts` | NEW |
| Inertia Page | `resources/js/Pages/Admin/Organizations/Index.tsx` | NEW |
| Inertia Page | `resources/js/Pages/Admin/Employees/Index.tsx` | NEW |
| Inertia Page | `resources/js/Pages/Admin/Vehicles/Index.tsx` | NEW |
| Sidebar | `resources/js/Layouts/AdminLayout.tsx` | MODIFY (add Organization link) |
| AppServiceProvider | `app/Providers/AppServiceProvider.php` | MODIFY (register policies) |
| Test | `tests/Feature/OrganizationTest.php` | NEW |
| Test | `tests/Feature/EmployeeTest.php` | NEW |
| Test | `tests/Feature/VehicleTest.php` | NEW |

### Batch 1B — Number Sequence

| Type | File | Action |
|------|------|--------|
| Migration | `database/migrations/2026_07_01_170000_create_number_sequences_table.php` | NEW |
| Model | `app/Models/Core/NumberSequence.php` | NEW |
| Service | `app/Services/Core/NumberSequenceService.php` | NEW |
| Controller | `app/Http/Controllers/Admin/NumberSequenceController.php` | NEW |
| Resource | `app/Http/Resources/NumberSequenceResource.php` | NEW |
| Policy | `app/Policies/NumberSequencePolicy.php` | NEW |
| Factory | `database/factories/NumberSequenceFactory.php` | NEW |
| Routes | `routes/admin.php` | MODIFY (add number-sequence routes) |
| TS Type | `resources/js/types/number-sequence.d.ts` | NEW |
| Inertia Page | `resources/js/Pages/Admin/NumberSequences/Index.tsx` | NEW |
| Test | `tests/Feature/NumberSequenceTest.php` | NEW |

### Batch 1C — Document Attachment

| Type | File | Action |
|------|------|--------|
| Migration | `database/migrations/2026_07_01_180000_create_document_attachments_table.php` | NEW |
| Model | `app/Models/Core/DocumentAttachment.php` | NEW |
| Service | `app/Services/Core/DocumentAttachmentService.php` | NEW |
| Controller | `app/Http/Controllers/Admin/DocumentAttachmentController.php` | NEW |
| Resource | `app/Http/Resources/DocumentAttachmentResource.php` | NEW |
| Policy | `app/Policies/DocumentAttachmentPolicy.php` | NEW |
| Factory | `database/factories/DocumentAttachmentFactory.php` | NEW |
| Routes | `routes/admin.php` | MODIFY (add attachment routes) |
| TS Type | `resources/js/types/document-attachment.d.ts` | NEW |
| Test | `tests/Feature/DocumentAttachmentTest.php` | NEW |

### Batch 1D — Notification

| Type | File | Action |
|------|------|--------|
| Migration | `database/migrations/2026_07_01_190000_create_notifications_table.php` | NEW |
| Model | `app/Models/Core/Notification.php` | NEW |
| Service | `app/Services/Core/NotificationService.php` | NEW |
| Controller | `app/Http/Controllers/Admin/NotificationController.php` | NEW |
| Resource | `app/Http/Resources/NotificationResource.php` | NEW |
| Factory | `database/factories/NotificationFactory.php` | NEW |
| Routes | `routes/admin.php` | MODIFY (add notification routes) |
| Inertia | `app/Http/Middleware/HandleInertiaRequests.php` | MODIFY (share unread notification count) |
| Sidebar/Topbar | `resources/js/Layouts/AdminLayout.tsx` | MODIFY (add notification bell) |
| Test | `tests/Feature/NotificationTest.php` | NEW |

### Batch 1E — Approval Engine

| Type | File | Action |
|------|------|--------|
| Migration | `database/migrations/2026_07_01_200000_create_approval_requests_table.php` | NEW |
| Model | `app/Models/Core/ApprovalRequest.php` | NEW |
| Service | `app/Services/Core/ApprovalService.php` | NEW |
| Controller | `app/Http/Controllers/Admin/ApprovalController.php` | NEW |
| Resource | `app/Http/Resources/ApprovalRequestResource.php` | NEW |
| Policy | `app/Policies/ApprovalRequestPolicy.php` | NEW |
| Factory | `database/factories/ApprovalRequestFactory.php` | NEW |
| Routes | `routes/admin.php` | MODIFY (add approval routes) |
| TS Type | `resources/js/types/approval.d.ts` | NEW |
| Inertia Page | `resources/js/Pages/Admin/Approvals/Index.tsx` | NEW |
| Test | `tests/Feature/ApprovalTest.php` | NEW |

### Batch 1F — Tests, Permissions, Seeder, UI Review

| Type | File | Action |
|------|------|--------|
| Seeder | `database/seeders/RolePermissionSeeder.php` | MODIFY (add new permissions) |
| Seeder | `database/seeders/CompanySeeder.php` | MODIFY (seed org units + employees) |
| Test | `tests/Feature/RolePermissionSeederTest.php` | MODIFY (assert new permissions) |
| Test | `tests/Feature/Batch1IntegrationTest.php` | NEW (end-to-end: org→employee→approval→notification) |
| UI | `resources/js/Layouts/AdminLayout.tsx` | MODIFY (final sidebar review) |
| Report | `docs/BATCH_1_REPORT.md` | NEW |

---

## 5. Risk Analysis

| # | Risk | Severity | Mitigation |
|---|------|----------|------------|
| R1 | **Multi-company scope** — OrganizationUnit/EmployeeProfile must respect company_id via BelongsToCompany trait | HIGH | Use existing `BelongsToCompany` trait (proven on Location, Customer, etc.). Global scope auto-filters. |
| R2 | **User compatibility** — EmployeeProfile is 1:1 with User. Must not break existing User CRUD, Breeze auth, or tests | HIGH | EmployeeProfile is optional (nullable). User works without it. Only admin creates EmployeeProfile. No changes to User model. |
| R3 | **Permission compatibility** — New permissions must not conflict with existing | MEDIUM | Additive only. New permission names: `organization.view`, `organization.manage`, `employee.view`, `employee.manage`, `vehicle.view`, `vehicle.manage`, `approval.view`, `approval.manage`, `notification.view`. No renames. |
| R4 | **Migration rollback** — New tables must not break existing migrations on fresh install | LOW | All additive. No modifications to existing tables. `organization_units` references `companies.id` (exists). `employee_profiles` references `users.id` (exists). |
| R5 | **UI complexity** — Organization tree view might be complex | MEDIUM | Reuse existing Location tree pattern (proven). Use recursive nested list or DataTable with path column. Keep simple for v1. |
| R6 | **NumberSequence race condition** — Concurrent code generation | MEDIUM | Use `lockForUpdate()` in transaction (same pattern as existing `generateCode()` methods). |
| R7 | **Approval deadlock** — Approval blocks a business process indefinitely | MEDIUM | Approval is optional per action. Actions without approval proceed normally. Approval has `cancelled` status for timeout. |
| R8 | **Document storage** — File upload path collision | LOW | Use `{attachable_type}/{attachable_id}/{timestamp}-{filename}` path pattern. Local disk `public`. |
| R9 | **Notification spam** — Too many notifications | LOW | NotificationService has `type` filter. Future: user preferences (Batch 1F or later). |

---

## 6. Acceptance Criteria for Batch 1A Only

| # | Criteria | Verification |
|---|----------|-------------|
| AC1 | `organization_units` table exists with self-ref parent, materialized path, company_id | `php artisan migrate` success |
| AC2 | `vehicles` table exists with plate_number unique per company | `php artisan migrate` success |
| AC3 | `employee_profiles` table exists with user_id UNIQUE, organization_id FK | `php artisan migrate` success |
| AC4 | `OrganizationUnit` model uses `BelongsToCompany`, `HasFactory`, `LogsActivity`, `SoftDeletes` | Model inspection |
| AC5 | `OrganizationService::create()` uses `DB::transaction` + `AuditService::log` | Code grep + test |
| AC6 | `OrganizationService::move()` has cycle prevention (reuses Location pattern) | Test: move node to descendant → 422 |
| AC7 | `EmployeeProfile` model: 1:1 with User, belongsTo OrganizationUnit | Test: create employee for existing user |
| AC8 | All 3 models have `company_id` in `$fillable` | Code inspection |
| AC9 | `OrganizationController` has index/store/update/move/destroy with Gate authorization | Test: 403 without permission |
| AC10 | `EmployeeController` has index/store/show/update/destroy | Test: CRUD works |
| AC11 | Sidebar shows "Organization" link when `organization.view` permission exists | UI inspection |
| AC12 | `OrganizationPolicy`, `EmployeePolicy`, `VehiclePolicy` registered in `AppServiceProvider` | Code inspection |
| AC13 | Factories exist for all 3 models | Test uses factory |
| AC14 | `OrganizationTest` — tree CRUD + cycle prevention + company scope | `php artisan test --filter=OrganizationTest` |
| AC15 | `EmployeeTest` — create employee for user, link to org, company scope | `php artisan test --filter=EmployeeTest` |
| AC16 | `VehicleTest` — CRUD + unique plate per company | `php artisan test --filter=VehicleTest` |
| AC17 | `npm run build` — 0 errors | Build clean |
| AC18 | `php artisan test` — all tests pass (existing + new) | Full test suite |
| AC19 | No existing tests broken | Regression check |
| AC20 | Additive migrations only (no drop/recreate of existing tables) | Migration review |

---

**Plan complete. No coding performed. Awaiting approval for Batch 1A.**
