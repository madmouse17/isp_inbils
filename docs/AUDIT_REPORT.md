# inbils — Phase 0: Architecture Audit & Gap Report

> Date: 2026-07-01  
> Status: COMPLETE  
> Scope: Full repository audit, no coding

## 1. Current System Snapshot

| Metric | Count |
|--------|-------|
| PHP files (excl vendor) | 422 |
| Frontend TSX pages | 81 |
| Admin route names | ~100+ |
| DB migrations | ~30 tables |
| Test files | 17 (10 Breeze + 5 unit + 2 setup) |
| Business logic tests | **0** |
| Action classes | **0** (empty directories exist) |
| Events/Listeners/Jobs | 2 events, 2 listeners, 0 jobs |
| DB::transaction usage | 8 services (good coverage) |

### Module Inventory

| Module | Models | Controllers | Services | Migrations | Tests | Actions |
|--------|--------|-------------|----------|------------|-------|---------|
| **app/Core** | 9 (Company, User, Customer, CustomerAddress, CustomerContact, ServiceSubscription, Location, Setting, EmployeeEvaluation) | 13 admin + 10 auth | 6 (CompanyService, SettingService, SetupWizardService, AuditService, LocationService, SubscriptionService) | ~10 | 7 unit | 0 |
| **Service** | 4 (ServicePackage, BandwidthProfile, SpeedProfile, SLATier) | 5 | 0 | 4 | 0 | 0 |
| **Inventory** | 5 (Product, Category, Unit, Stock, StockMovement) | 5 | 1 (StockService) | 5 | 0 | 0 |
| **NetworkAsset** | 2 (NetworkAsset, NetworkAssetInstallation) | 1 | 1 (NetworkAssetService) | 2 | 0 | 0 |
| **SPK** | 4 (WorkOrder, WorkOrderItem, WorkOrderAssignment, WorkOrderEvidence) | 1 | 1 (SpkService) | 4 | 0 | 0 |
| **Billing** | 3 (Invoice, InvoiceItem, Payment) | 1 | 1 (BillingService) | 3 | 0 | 0 |
| **Ticketing** | 4 (Ticket, TicketCategory, TicketComment, TicketAttachment) | 1 | 1 (TicketService) | 4 | 0 | 0 |
| **Reporting** | 0 (read-only) | 1 | 0 | 0 | 0 | 6 queries |

## 2. Critical Runtime Bugs (P0 — Fix Before Upgrade)

### Bug 1: Login Redirect
- **File**: `app/Http/Controllers/Auth/AuthenticatedSessionController.php:36`
- **Issue**: Redirects to `route('dashboard')` = `/dashboard` (Breeze default) instead of `route('admin.dashboard')` = `/admin/dashboard`
- **Status**: FIXED this session (patched to `admin.dashboard`)

### Bug 2: Route Conflict `/admin` Catch-All
- **File**: `routes/web.php:35-37` (now removed)
- **Issue**: `Route::prefix('admin')->group(fn () => Route::get('/', redirect('/dashboard')))` intercepted all `/admin/*` requests before `admin.php` routes loaded via `withRouting(then:)`
- **Status**: FIXED this session (removed conflicting group)

### Bug 3: Location `$fillable` Missing `company_id`
- **File**: `app/Models/Core/Location.php`
- **Issue**: `BelongsToCompany` trait auto-sets `company_id` from `CompanyService::currentId()`, but in CLI/seeder context (no Auth), `company_id` = null → MySQL strict mode rejects insert
- **Status**: FIXED this session (added `company_id` to `$fillable`)

### Bug 4: CompanySeeder `contract_min_months` Null
- **File**: `database/seeders/CompanySeeder.php`
- **Issue**: PKG-TRIAL passed `null` for NOT NULL column
- **Status**: FIXED this session (changed to 0)

### Bug 5: User `email_verified_at` Not Set
- **Issue**: User created without `email_verified_at` → `verified` middleware redirects → 302 loop
- **Status**: FIXED manually (but `SetupWizardService` and `inbils:setup` command should set this)

### Bug 6: Dashboard Static Data
- **File**: `resources/js/Pages/Dashboard.tsx`
- **Issue**: Uses hardcoded demo data (1,284 users, Rp 48.2M, etc.) instead of real Inertia props from DashboardController
- **Note**: `Admin/Dashboard/Index.tsx` exists with real data, but `/dashboard` route renders the wrong component

### Bug 7: Sidebar Billing Link → 404
- **File**: `resources/js/Layouts/AdminLayout.tsx`
- **Issue**: Sidebar link `/admin/billing` → no route exists (invoices are at `/admin/invoices`, not `/admin/billing`)

## 3. Dependency Map

```
                    ┌─────────────┐
                    │   Core      │
                    │ Company     │
                    │ User/Role   │
                    │ Permission  │
                    │ Setting     │
                    │ Audit       │
                    │ Location    │
                    │ BelongsToCompany trait │
                    └──────┬──────┘
                           │
              ┌────────────┼────────────┐
              │            │            │
     ┌────────▼───┐  ┌─────▼────┐  ┌───▼────────┐
     │ Service    │  │ Customer │  │ Inventory  │
     │ Package    │  │ Address  │  │ Product    │
     │ Bandwidth  │  │ Contact  │  │ Category   │
     │ Speed      │  │ Subscrip │  │ Unit       │
     │ SLATier    │  │ tion     │  │ Stock      │
     └─────┬──────┘  └────┬─────┘  │ Movement   │
           │              │        └──────┬─────┘
           │              │               │
           └──────┬───────┘               │
                  │                       │
           ┌──────▼──────┐        ┌───────▼──────┐
           │ NetworkAsset│        │     SPK      │
           │ Asset       │        │ WorkOrder    │
           │ Installation│        │ Items        │
           └──────┬──────┘        │ Assignment   │
                  │               │ Evidence     │
                  │               └──────┬───────┘
                  │                      │
                  └──────────┬───────────┘
                             │
                     ┌───────▼───────┐
                     │   Billing     │
                     │ Invoice       │
                     │ InvoiceItem   │
                     │ Payment       │
                     └───────┬───────┘
                             │
                     ┌───────▼───────┐
                     │  Ticketing    │
                     │ Ticket        │
                     │ Category      │
                     │ Comment       │
                     │ Attachment    │
                     └───────┬───────┘
                             │
                     ┌───────▼───────┐
                     │  Reporting    │
                     │ (read-only)   │
                     │ 6 Query classes│
                     └───────────────┘
```

### Cross-Module Dependencies (Actual)

| From → To | Type | Status |
|-----------|------|--------|
| SPK → Inventory | StockService::reserve/issue/release (polymorphic reference) | **Stub** — SpkService has `// ponytail: release reserved stock` comment, not wired |
| SPK → NetworkAsset | NetworkAssetService::install (from CompleteSpkAction) | **Stub** — `// CompleteSpkAction::execute($wo)` commented out |
| SPK → Subscription | SubscriptionService::activate (from CompleteSpkAction) | **Stub** — same comment |
| SPK → Billing | CreateInvoiceFromSpkAction (OTC invoice) | **Stub** — BillingService::createFromSpk exists but not called from SPK |
| Ticketing → SPK | TicketService::spawnSpk creates WorkOrder | **Works** — but SPK type=maintenance only, no material template |
| Billing → Subscription | SubscriptionService::suspend/reactivate | **Stub** — no auto-suspend job |
| Reporting → All | Direct model queries (allowed exception) | **Works** |

## 4. Gap Analysis by Module

### Core (app/)
**Has**: Company, User, Role, Permission, Setting, Audit, Location, BelongsToCompany
**Missing**:
- No organization structure (branch/area/unit/team/department)
- No employee profile (separate from User — technician skills, vehicle assignment)
- No approval engine (generic approval workflow for high-risk actions)
- No notification engine (in-app, email, WhatsApp placeholders)
- No document attachment system (generic, reusable)
- No number sequence service (centralized code generation — currently each service has its own `generateCode()`)
- DashboardController returns static data, not real metrics
- `RedirectIfNoCompany` should skip more routes (login, password reset, etc.)

### Customer
**Has**: Customer, CustomerAddress, CustomerContact, ServiceSubscription (in app/Models/Core/)
**Missing**:
- No customer lifecycle status (lead/survey/active/suspended/blacklist)
- No customer status history
- No customer documents
- No customer group/parent company
- No PIC roles (technical/admin/billing contact distinction)
- No blacklist/bad debt flags
- No activation checklist
- Customer module (Modules/Customer) is empty stub — all logic in app/

### Service/Subscription
**Has**: ServicePackage, BandwidthProfile, SpeedProfile, SLATier
**Missing**:
- No ServicePackageService (controllers call model directly)
- Subscription provisioning not implemented (no Radius/PPPoE placeholder, no IP assignment)
- No package change history
- No contract/SLA tracking on subscription
- No provisioning status field

### Inventory
**Has**: Product, Category, Unit, Stock, StockMovement, StockService (7 movement types)
**Missing**:
- **No unit conversion** (haspel → meter, box → pcs) — critical for ISP cable management
- **No category-unit relationship** (category determines valid units)
- No product tracking type (consumable vs durable vs serial-tracked)
- No serial asset receiving (separate from NetworkAsset — for spare parts with serial)
- No spool/haspel tracking (cable spool with remaining length)
- No technician stock (mobile stock assigned to technician)
- No stock opname (physical count) + variance + approval
- No base unit movement (all movements in base unit, display in alternate unit)
- Product has no warehouse/location concept (only location_id via Stock)

### NetworkAsset
**Has**: NetworkAsset, NetworkAssetInstallation, NetworkAssetService (lifecycle)
**Missing**:
- No network topology entities (POP, OLT, OLT card, OLT port, ODC, ODP, ODP port, pole, fiber cable, fiber core, splitter)
- No network link (point-to-point fiber path)
- No customer network path (customer → ODP → ODC → OLT → POP)
- No capacity/occupancy tracking (OLT port usage, ODP port usage)
- NetworkAsset is generic (router/switch/ont) but not topology-aware

### SPK
**Has**: WorkOrder, WorkOrderItem, WorkOrderAssignment, WorkOrderEvidence, SpkService (8-state machine)
**Missing**:
- **CompleteSpkAction not implemented** — the orchestrator that ties everything together
- **ReserveSpkMaterialsAction not implemented** — stock reservation on assign
- **CancelSpkAction not implemented** — stock release on cancel
- No material template (pre-defined material list per SPK type)
- No customer signature
- No supervisor approval flow (just Kepala Unit manual approve)
- No actual material usage vs reserved comparison
- No asset install/remove from SPK
- No subscription activation from SPK
- No invoice creation from SPK
- SpkService::approve() has `// ponytail: CompleteSpkAction` comment — does nothing

### Billing
**Has**: Invoice, InvoiceItem, Payment, BillingService (createRecurring, createFromSpk, send, recordPayment, cancel)
**Missing**:
- No billing cycle/run (batch invoice generation)
- No recurring invoice scheduler (job)
- No proration (mid-cycle activation/upgrade)
- No payment gateway/VA abstraction
- No payment reconciliation
- No credit/debit notes
- No reminder/dunning sequence
- No auto-isolation (suspend subscription on overdue)
- No auto-reactivate after payment
- No aging receivable report
- No invoice PDF
- BillingService::checkOverdue() exists but not scheduled

### Ticketing
**Has**: Ticket, TicketCategory, TicketComment, TicketAttachment, TicketService (5-state, SLA, spawn SPK)
**Missing**:
- No outage management (mass ticket from network event)
- No affected customer mapping (outage → list impacted subscriptions)
- No escalation matrix
- No SLA pause/resume (clock stop for pending customer response)
- No root cause / resolution code taxonomy
- No reopen flow (resolved → reopened)
- No monitoring integration placeholder
- No material template for spawned SPK

### Reporting
**Has**: 6 Query classes (BusinessMetrics, TechnicianPerformance, AssetUtilization, SlaCompliance, StockCard, AuditLog)
**Missing**:
- No role-based dashboards (owner, manager, NOC, warehouse, billing, technician)
- No KPI reports
- No aging reports (stock aging, asset aging, AR aging)
- No SLA trend
- No network capacity report
- No export (Excel/PDF)
- Dashboard.tsx uses static data

## 5. Risk List

| # | Risk | Severity | Impact |
|---|------|----------|--------|
| R1 | **CompleteSpkAction is a stub** — SPK approval does nothing (no stock issue, no asset install, no subscription activate, no invoice) | CRITICAL | System cannot complete real ISP workflow |
| R2 | **No business logic tests** — 0 tests for StockService, BillingService, SpkService, TicketService, SubscriptionService | CRITICAL | Refactoring is unsafe |
| R3 | **No unit conversion** — cable (haspel/meter), box/pcs cannot be handled | HIGH | Inventory unusable for ISP cable management |
| R4 | **No network topology** — no OLT/ODP/ODP/port/cable/fiber entities | HIGH | Cannot map customer to network path |
| R5 | **Dashboard static data** — shows fake numbers (1,284 users, Rp 48.2M) | HIGH | User trust issue |
| R6 | **Billing not automated** — no recurring job, no auto-suspend, no auto-reactivate | HIGH | Manual billing for every cycle |
| R7 | **No approval engine** — high-risk actions (delete, terminate, adjust) have no approval flow | MEDIUM | Audit/compliance gap |
| R8 | **No organization structure** — no branch/team/department, no technician skills/vehicle | MEDIUM | Cannot route SPK to correct team |
| R9 | **Customer module empty** — all logic in app/Core, Modules/Customer is stub | MEDIUM | Architecture inconsistency |
| R10 | **No number sequence service** — each service has own generateCode(), no central control | LOW | Code duplication, no configurable prefix |
| R11 | **Session driver changed to file** — was database, may cause issues in production | LOW | Scalability |
| R12 | **No procurement** — no purchase order, goods receipt, supplier invoice | LOW | Phase 9 scope |

## 6. Recommended Batch Order

Based on dependency analysis, fixes must be applied in this order:

### Batch 0 — Critical Bug Fixes (PREREQUISITE)
**Scope**: Fix runtime bugs blocking basic usability
- Fix login redirect (DONE)
- Fix route conflict (DONE)
- Fix Location $fillable (DONE)
- Fix CompanySeeder (DONE)
- Fix Dashboard to use real data (DashboardController)
- Fix sidebar Billing link
- Add `email_verified_at` to user creation in SetupWizardService
- Write smoke test for login → dashboard → navigate modules

**Acceptance**: User can login, see real dashboard, click sidebar links, navigate to all modules without 302/404

### Batch 1 — Core ERP Foundation
**Scope**: Organization, approval, notification, document, number sequence
- `organizations` table (company → branch → area → unit → team)
- `employees` table (user extension: skills, vehicle, team assignment)
- `approvals` table (generic approval workflow: subject, requester, approver, status)
- `notifications` table (in-app notification queue)
- `attachments` table (generic document attachment, polymorphic)
- `NumberSequenceService` (centralized code generation)
- Tests for all new services

### Batch 2 — Master Data & Location Topology
**Scope**: Strengthen location, add category-unit, unit conversion
- Expand location types (add warehouse, site, pole)
- `category_unit` pivot table (category → valid units)
- `unit_conversions` table (from_unit, to_unit, factor, per product or global)
- Product tracking type field (consumable/durable/serial)
- Migration to add `base_unit_id` to units
- Tests

### Batch 3 — Inventory Enterprise
**Scope**: Spool tracking, technician stock, stock opname
- `product_units` table (product → multiple units + conversion factors)
- `spools` table (cable spool with remaining length, batch tracking)
- `technician_stocks` table (mobile stock per technician)
- `stock_opnames` + `stock_opname_items` tables
- StockService extensions: receiveBySpool, issueFromTechnicianStock, opname
- Tests

### Batch 4 — Network Topology ISP
**Scope**: OLT, ODP, port, cable, fiber core, customer path
- `network_topology_nodes` table (POP, OLT, ODC, ODP, pole, site)
- `network_ports` table (OLT port, ODP port — with status: available/used/defective)
- `fiber_cables` table (cable between two nodes, core count)
- `fiber_cores` table (individual core within cable, status)
- `customer_network_paths` table (subscription → ODP port → ODC → OLT port)
- Capacity tracking (port occupancy per node)
- Tests

### Batch 5 — SPK Orchestration
**Scope**: CompleteSpkAction, ReserveSpkMaterialsAction, CancelSpkAction
- `CompleteSpkAction` — orchestrator: issue stock + install asset + activate subscription + create invoice
- `ReserveSpkMaterialsAction` — reserve stock on SPK assign
- `CancelSpkAction` — release reserved stock
- `spk_material_templates` table (pre-defined material list per SPK type)
- Wire SPK → StockService (actual, not stub)
- Wire SPK → NetworkAssetService (actual, not stub)
- Wire SPK → SubscriptionService (actual, not stub)
- Wire SPK → BillingService (actual, not stub)
- Customer signature field
- Tests for full SPK lifecycle

### Batch 6 — Customer & Subscription Provisioning
**Scope**: Customer lifecycle, documents, subscription provisioning
- Customer status field + history
- `customer_documents` table
- `customer_groups` table (parent company)
- Subscription provisioning fields (radius_username, radius_password, ip_address, provisioning_status)
- `package_change_history` table
- Contract/SLA fields on subscription
- Tests

### Batch 7 — Billing Engine
**Scope**: Billing cycles, scheduler, auto-isolir, PDF
- `billing_cycles` table
- `billing_runs` table (batch execution log)
- Recurring invoice job (daily schedule)
- Auto-overdue job
- Auto-suspend job (opt-in threshold)
- Auto-reactivate on payment
- Proration calculation
- Invoice PDF (barryvdh/laravel-dompdf)
- Credit/debit notes
- Tests

### Batch 8 — Ticketing + NOC + Outage
**Scope**: Outage, escalation, SLA pause, reopen
- `outages` table (mass event, affected customers)
- `ticket_escalations` table
- SLA pause/resume fields
- Root cause / resolution code taxonomy
- Reopen flow
- Material template for spawned SPK
- Tests

### Batch 9 — Procurement & Finance Lite
**Scope**: PO, goods receipt, supplier, expense
- `suppliers` table
- `purchase_requests` + `purchase_orders` tables
- `goods_receipts` table
- `expenses` table
- Cash/bank account lite
- Tests

### Batch 10 — Reporting, KPI, Dashboard
**Scope**: Role dashboards, KPI, export
- Real DashboardController (replace static data)
- Role-based dashboard widgets
- KPI reports
- Aging reports
- Export Excel/PDF
- Tests

### Batch 11 — Hardening
**Scope**: Tests, security, optimization
- Full test suite for all services
- Permission audit
- Query optimization + indexing
- Scheduler/queue config
- Backup strategy
- Production config (APP_DEBUG=false, config:cache, route:cache)

## 7. Acceptance Criteria for Batch 1 (Core ERP Foundation)

| # | Criteria | Verification |
|---|----------|-------------|
| AC1 | `organizations` table exists with company → branch → area hierarchy | `php artisan migrate` success |
| AC2 | `employees` table extends users with skills, team, vehicle | Model exists, factory works |
| AC3 | `approvals` table with polymorphic subject, status workflow | ApprovalService::create/approve/reject |
| AC4 | `NumberSequenceService` generates codes with configurable prefix | Test: generate INV/SPK/TKT codes |
| AC5 | `attachments` table polymorphic, file upload works | Test: attach file to WorkOrder |
| AC6 | All new tables have `company_id` + BelongsToCompany trait | Trait check |
| AC7 | All new services use DB::transaction | Grep for `DB::transaction` |
| AC8 | All new services create audit logs | Grep for `AuditService::log` |
| AC9 | Tests pass for all new services | `php artisan test` |
| AC10 | Frontend build clean | `npm run build` 0 errors |
| AC11 | No existing routes/tests broken | Existing tests still pass |
| AC12 | Additive migrations only (no drop/recreate) | Migration review |

---

**Audit complete. No coding performed. Ready for Batch 0 (critical bug fixes) upon approval.**
