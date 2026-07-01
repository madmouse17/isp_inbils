#!/usr/bin/env bash
# Create GitHub issues for inbils feature checklists
# Run AFTER: gh auth login

set -e

REPO="madmouse17/isp_inbils"

# Issue 1: Phase 1 — Core Foundation
gh issue create --repo "$REPO" \
  --title "Phase 1 — Core Foundation" \
  --label "phase-1,enhancement" \
  --body "## Phase 1 — Core Foundation

### Company System
- [x] \`companies\` table + \`Company\` model
- [x] \`BelongsToCompany\` trait (global scope + auto-set company_id)
- [x] \`CompanyService\` (current/setting/updateProfile/updateSettings)
- [x] Two-layer config (companies.settings JSON + settings table)
- [x] \`users.company_id\` column

### Setup Wizard
- [x] \`php artisan inbils:setup\` bootstrap command
- [x] 3 middleware (RedirectIfNoCompany, RequireNoCompany, RequireHasCompany)
- [x] 4-step wizard (Company info → System config → Initial admin → Confirmation)
- [x] \`SetupWizardService::create()\` DB transaction
- [x] \`Setup/Wizard.tsx\` frontend

### User Management
- [x] Full CRUD + role assignment
- [x] Activate/deactivate
- [x] Self-protection (cannot delete/deactivate self)
- [x] \`last_login_at\` tracking

### Role Management
- [x] Full CRUD + permission sync
- [x] 5 default roles (admin, manager, staff, technician, customer)
- [x] Protected roles cannot be deleted

### Permission System
- [x] spatie/laravel-permission
- [x] ~80+ permissions across 8 modules
- [x] Read-only permission index

### Audit Log
- [x] spatie/laravel-activitylog
- [x] \`LogsActivity\` trait on core models
- [x] \`AuditService\` wrapper

### Location Topology
- [x] Hierarchical (region → area → pop → rack → site)
- [x] Materialized path
- [x] Cycle prevention
- [x] Recurse on move/rename

### System Settings
- [x] \`settings\` table + \`Setting\` model
- [x] \`SettingService\` with cache
- [x] \`SystemSettingSeeder\` defaults

### Dashboard
- [x] Real data widgets (company info, counts, activity log)

### Master Defaults
- [x] \`CompanySeeder::runFor()\` on CompanyCreated event
- [x] Default units, ticket categories, SLA tiers, sample locations
"

echo "Issue 1 created."

# Issue 2: Phase 2 — Master Data
gh issue create --repo "$REPO" \
  --title "Phase 2 — Master Data (Customer + Service Catalog)" \
  --label "phase-2,enhancement" \
  --body "## Phase 2 — Master Data

### Service Catalog (Modules/Service)
- [x] \`ServicePackage\` model + migration + CRUD
- [x] \`BandwidthProfile\` model + migration + CRUD
- [x] \`SpeedProfile\` model + migration + CRUD
- [x] \`SLATier\` model + migration + CRUD

### Customer Management
- [x] \`Customer\` model (Individual/Company)
- [x] \`CustomerAddress\` (multi-address, installation point guard)
- [x] \`CustomerContact\` (multi-contact, primary guard)
- [x] Per-company unique code generation (CUS-{YEAR}-{NNNNN})
- [x] Soft delete with active subscription restriction

### Service Subscription
- [x] \`ServiceSubscription\` model + migration
- [x] \`SubscriptionService\` lifecycle (activate/suspend/reactivate/terminate)
- [x] DB transactions + audit logging
- [x] MRC snapshot from package
- [x] Code generation (SUB-{YEAR}-{NNNNN})
- [x] Status: pending → active → suspended → reactivated → terminated

### Frontend
- [x] Customer index (filter/search/paginate)
- [x] Customer create/edit forms
- [x] Customer show (tabbed: profile + addresses + contacts + subscriptions)
- [x] Address management (modal CRUD)
- [x] Contact management (modal CRUD)
- [x] Subscription index + create modal
- [x] Subscription detail with lifecycle action buttons
- [x] Dark mode + responsive

### Factories
- [x] CustomerFactory, CustomerAddressFactory, CustomerContactFactory, ServiceSubscriptionFactory
"

echo "Issue 2 created."

# Issue 3: UI Design System
gh issue create --repo "$REPO" \
  --title "UI Design System (Components + Composite)" \
  --label "ui,enhancement" \
  --body "## UI Design System

### Primitives (Components/ui/)
- [x] Button (5 variants: primary, secondary, ghost, danger, outline)
- [x] IconButton
- [x] Input, Textarea, Select
- [x] Label
- [x] Checkbox, Switch, RadioGroup
- [x] Badge
- [x] Card (Header, Title, Description, Content, Footer)
- [x] StatCard
- [x] Alert
- [x] EmptyState
- [x] Breadcrumb
- [x] Pagination
- [x] Sidebar, SidebarItem, SidebarSection
- [x] Topbar
- [x] Tabs (TabList, Tab, TabPanel)
- [x] Modal
- [x] Dropdown (DropdownItem, DropdownTrigger, DropdownSeparator)
- [x] Tooltip
- [x] Toast (ToastProvider, useToast)
- [x] Table (THead, TBody, TR, TH, TD)
- [x] Spinner, Skeleton, Divider, Avatar

### Composite (Components/composite/)
- [x] DataTable (sort + pagination + filter slot)
- [x] PageHeader (title + subtitle + breadcrumbs + actions)
- [x] FormField (label + input + error)
- [x] StatusBadge (variant per status)
- [x] MoneyInput
- [x] DateRangeFilter

### Layout
- [x] AdminLayout (sidebar + topbar + dark mode toggle)
- [x] SetupLayout (wizard minimal layout)

### Hooks
- [x] usePermission (can, canAny)
- [x] useCompany
- [x] useToast

### Theme
- [x] Dark mode (class strategy + localStorage persistence)
- [x] Responsive (mobile sidebar, table scroll)
- [x] Token-based colors (brand/surface/success/warning/danger)
"

echo "Issue 3 created."

# Issue 4: Roadmap
gh issue create --repo "$REPO" \
  --title "Roadmap — Phase 3-8" \
  --label "roadmap" \
  --body "## Roadmap

### Phase 3 — Inventory + NetworkAsset
- [ ] Product + Category + Unit CRUD
- [ ] StockMovement (immutable, 7 types)
- [ ] Reserve/release/transfer
- [ ] NetworkAsset CRUD + lifecycle
- [ ] NetworkAssetInstallation append-only history
- [ ] Location topology finalization
- [ ] Trace endpoint (search by serial/mac/ip)

### Phase 4 — SPK (Surat Perintah Kerja)
- [ ] 4 SPK types (installation/maintenance/upgrade/relocation)
- [ ] 8-state machine
- [ ] Assignment with suggestions
- [ ] CompleteSpkAction orchestrator
- [ ] Evidence upload
- [ ] PDF print

### Phase 5 — Billing
- [ ] Recurring MRC job
- [ ] One-time OTC from SPK
- [ ] Invoice state machine
- [ ] Payment (immutable)
- [ ] Overdue + auto-suspend
- [ ] Tax (PPN 11%)
- [ ] PDF invoice

### Phase 6 — Ticketing
- [ ] 5-state machine
- [ ] SLA + breach detection
- [ ] Auto-routing
- [ ] Spawn SPK from ticket
- [ ] Comment + attachment
- [ ] Customer role (v2)

### Phase 7 — Performance
- [ ] Employee evaluation CRUD
- [ ] Polymorphic reference (WorkOrder/Ticket)
- [ ] Snapshot FRT/resolution

### Phase 8 — Reporting
- [ ] 7 report types
- [ ] Charts (recharts)
- [ ] Audit log cross-tenant filter
- [ ] Excel export

### v1.0.0 Release
- [ ] All phases complete
- [ ] Production config (APP_DEBUG=false, HTTPS, route:cache)
- [ ] README update
- [ ] Tag v1.0.0
"

echo "Issue 4 created."
echo "All issues created successfully!"
