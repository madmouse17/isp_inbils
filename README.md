# inbils — ISP Management System

ISP management platform built with Laravel 12 + Inertia.js + React 18 + TypeScript + Tailwind CSS v3.

## Features

### Phase 1 — Core Foundation

- [x] **Company System** — Multi-tenant ready via `BelongsToCompany` trait (global scope + auto-set `company_id`), `CompanyService` (current/setting/updateProfile/updateSettings), two-layer config (`companies.settings` JSON + `settings` table system defaults)
- [x] **Setup Wizard** — First-login bootstrap flow: `php artisan inbils:setup` creates admin user → login → 4-step wizard (Company info → System config → Initial admin → Confirmation) → company created with roles + master defaults seeded
- [x] **Authentication** — Laravel Breeze (React/Inertia), register disabled (users created via admin CRUD or bootstrap command), 3 middleware: `RedirectIfNoCompany`, `RequireNoCompany`, `RequireHasCompany`
- [x] **User Management** — Full CRUD, role assignment, activate/deactivate, self-protection (cannot delete/deactivate self), `last_login_at` tracking via Login event listener
- [x] **Role Management** — Full CRUD with permission sync, 5 default roles (admin, manager, staff, technician, customer), protected roles cannot be deleted
- [x] **Permission System** — spatie/laravel-permission, ~80+ permissions across 8 modules, read-only index, role-permission matrix per `SECURITY.md`
- [x] **Audit Log** — spatie/laravel-activitylog, `LogsActivity` trait on core models, `AuditService` wrapper for manual events
- [x] **Location Topology** — Hierarchical topology (region → area → pop → rack → site), materialized path, cycle prevention, recurse on move/rename, type validation per FK use-case
- [x] **System Settings** — Key-value `settings` table, `SettingService` with cache, `SystemSettingSeeder` defaults
- [x] **Dashboard** — Real data widgets (company info, user/role counts, recent activity log, module placeholders)
- [x] **Master Defaults Seed** — `CompanySeeder::runFor()` fires on `CompanyCreated` event: default units, ISP ticket categories, SLA tiers, sample locations

### Phase 2 — Master Data

- [x] **Service Catalog** (Modules/Service) — `ServicePackage`, `BandwidthProfile`, `SpeedProfile`, `SLATier` models with full CRUD backend + frontend pages (Index/Create/Edit/Show)
- [x] **Customer Management** — `Customer` (Individual/Company), `CustomerAddress` (multi-address, installation point guard), `CustomerContact` (multi-contact, primary guard)
- [x] **Service Subscription** — `ServiceSubscription` lifecycle: pending → active → suspended → reactivated → terminated, `SubscriptionService` with DB transactions + audit logging, MRC snapshot from package, code generation (SUB-{YEAR}-{NNNNN})
- [x] **Customer Frontend** — Index (filter/search/paginate), Create, Edit, Show (tabbed: profile + addresses + contacts + subscriptions), Address management (modal CRUD), Contact management (modal CRUD), Subscription index + create (modal), Subscription detail with lifecycle action buttons
- [x] **Service Catalog Frontend** — Service Package (Index/Create/Edit/Show), Bandwidth Profile (Index/Create/Edit), Speed Profile (Index/Create/Edit), SLA Tier (Index/Create/Edit), sidebar link
- [x] **Seeders** — CompanySeeder extended (5 bandwidth profiles, 5 speed profiles, 8 service packages, 3 SLA tiers, 5 categories, 10 products, 10 network assets), CustomerDemoSeeder (20 customers, 30 subscriptions)
- [x] **UI Design System** — 35+ `Components/ui/*` primitives + 6 `Components/composite/*` (DataTable, PageHeader, FormField, StatusBadge, MoneyInput, DateRangeFilter), dark mode (class strategy + localStorage), fully responsive

### Phase 3 — Inventory + NetworkAsset

- [x] **Inventory Module** (Modules/Inventory) — `Product`, `Category`, `Unit`, `Stock`, `StockMovement` models
- [x] **Stock Management** — `StockService` with 7 movement types (receive/issue/transfer/adjustment/reserve/release/return), DB transactions, `InsufficientStockException`, balance_after + reserved_after snapshots, multi-location support
- [x] **Inventory Frontend** — Products (Index/Create/Edit/Show with stock-per-location + movement history), Categories (inline modal CRUD), Units (inline modal CRUD), Stocks (Index + receive/issue/transfer/adjust modals), Movements (history with filter), Item Finder (search → locations + qty)
- [x] **NetworkAsset Module** (Modules/NetworkAsset) — `NetworkAsset`, `NetworkAssetInstallation` models
- [x] **Asset Lifecycle** — `NetworkAssetService`: install → remove → maintenance → resume → damage → repair → retire, `NetworkAssetInstallation` append-only history (1 active per asset), code generation (AST-{YEAR}-{NNNNN})
- [x] **NetworkAsset Frontend** — Index (filter type/status/location/search), Create, Edit, Show (detail + installation history timeline + lifecycle action buttons), Trace (search by serial/MAC/IP/customer)
- [x] **Factories** — Category, Unit, Product, Stock, StockMovement, NetworkAsset factories
- [x] **Sidebar** — Inventory + Network Assets links (permission-gated)

### Phase 4 — SPK (Surat Perintah Kerja)

- [x] **SPK Module** (Modules/SPK) — `WorkOrder`, `WorkOrderItem`, `WorkOrderAssignment`, `WorkOrderEvidence` models
- [x] **8-State Machine** — draft → generated → assigned → in_progress → waiting_review → completed, rejected, cancelled
- [x] **SpkService** — generate, assign (with assignment history), start, submitForReview (evidence guard), approve, reject, cancel
- [x] **4 SPK Types** — installation, maintenance, upgrade_service, relocation
- [x] **Evidence Upload** — photo/document, file storage, required before submit (app guard)
- [x] **Assignment History** — append-only WorkOrderAssignment (re-assign tracked)
- [x] **Items** — consumable line items (product + quantity_reserved + quantity_used)
- [x] **Code Generation** — SPK-{YEAR}-{NNNNN}, race-safe with lockForUpdate
- [x] **Policy** — technician self-limit (view assigned only), Kepala Unit assign/approve/reject
- [x] **Frontend** — Index (filter type/status/technician/search), Create, Show (detail + lifecycle buttons + items table + evidence upload + assignment history)
- [x] **Factory** — WorkOrderFactory

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 12, PHP 8.2+ |
| Frontend | React 18, TypeScript, Inertia.js |
| Styling | Tailwind CSS v3 + @tailwindcss/forms |
| Build | Vite |
| Database | MySQL 8+ |
| Auth | Laravel Breeze (Inertia/React) |
| Roles/Permissions | spatie/laravel-permission |
| Audit Log | spatie/laravel-activitylog |
| Modules | nwidart/laravel-modules |
| Icons | heroicons/react/24/outline |

## Requirements

- PHP 8.2+
- MySQL 8+ (or MariaDB 10.6+)
- Node.js 18+
- Composer 2+
- npm 9+

## Installation

### 1. Clone & install dependencies

```bash
git clone https://github.com/madmouse17/isp_inbils.git
cd isp_inbils
composer install
npm install
```

### 2. Configure environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```env
APP_NAME=inbils
APP_ENV=local
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=inbils
DB_USERNAME=root
DB_PASSWORD=
```

### 3. Create databases

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS inbils;"
mysql -u root -e "CREATE DATABASE IF NOT EXISTS inbils_testing;"
```

### 4. Migrate & seed

```bash
php artisan migrate:fresh --seed
```

This seeds:
- 5 system roles (admin, manager, staff, technician, customer)
- ~80+ permissions across 8 modules
- System setting defaults (app.name, default_currency, default_timezone, etc.)

No company or user is seeded — use the bootstrap command.

### 5. Build frontend

```bash
npm run build
# or for development:
npm run dev
```

### 6. Bootstrap admin user

```bash
php artisan inbils:setup
```

This interactively creates the first admin user (name, email, password). This user has no company yet.

### 7. Start the server

```bash
php artisan serve
```

Visit `http://localhost:8000/login`, log in with the bootstrap credentials, and complete the Setup Wizard to create your company.

## Development

### Dev commands

```bash
php artisan serve          # Backend dev server (port 8000)
npm run dev                # Vite HMR frontend
php artisan test           # Run test suite
npm run build              # Production build (tsc + vite)
composer dump-autoload     # Regenerate classmap
```

### Testing

```bash
php artisan test
```

Note: 11 Breeze tests fail due to `RedirectIfNoCompany` middleware (expected — factory users have `company_id=null`). This is by design.

### Project structure

```
app/
  Console/Commands/     # SetupBootstrapCommand
  Http/
    Controllers/Admin/  # Dashboard, Company, User, Role, Permission, Location, Customer, Subscription
    Middleware/          # RedirectIfNoCompany, RequireNoCompany, RequireHasCompany
    Requests/Admin/      # Form requests (validation + authorization)
    Resources/           # API resources (JSON response shape)
  Models/Core/           # Company, User, Setting, Location, Customer, CustomerAddress, CustomerContact, ServiceSubscription
  Services/Core/         # CompanyService, SettingService, SetupWizardService, AuditService, SubscriptionService
  Policies/              # CustomerPolicy, SubscriptionPolicy, etc.
  Traits/                # BelongsToCompany
database/
  migrations/            # All schema
  seeders/               # RolePermissionSeeder, SystemSettingSeeder, CompanySeeder
  factories/             # Model factories
modules/
  Service/               # ServicePackage, BandwidthProfile, SpeedProfile, SLATier
  Inventory/             # Product, Category, Unit, Stock, StockMovement, StockService
  NetworkAsset/          # NetworkAsset, NetworkAssetInstallation, NetworkAssetService
  SPK/                   # WorkOrder, WorkOrderItem, WorkOrderAssignment, WorkOrderEvidence, SpkService
resources/js/
  Components/ui/         # 35+ primitives (Button, Input, Table, Card, Modal, etc.)
  Components/composite/  # DataTable, PageHeader, FormField, StatusBadge, MoneyInput, DateRangeFilter
  Pages/Admin/           # Dashboard, Users, Roles, Permissions, Company, Locations, Customers, Subscriptions, Service, Inventory, NetworkAssets, SPK
  Layouts/               # AdminLayout (sidebar + topbar + dark mode)
  hooks/                 # usePermission, useCompany, useToast
  types/                 # TypeScript interfaces (models, inventory, network-asset)
docs/                    # Architecture, database, security, workflow specs
```

## Architecture

- **Thin controllers** → FormRequest (validation) → Policy (authorization) → Service (business logic) → Model (data + relations)
- **Multi-tenant** via `BelongsToCompany` trait (global scope + auto-set `company_id`)
- **Modular** domain logic in `Modules/` (nwidart/laravel-modules), shared cross-cutting in `app/`
- **Inertia-as-Island** — Laravel routes drive pages, React renders per-route islands, no SPA/axios
- **UI convention** — all pages compose `Components/ui/*` primitives, never inline raw HTML elements

## License

Proprietary. All rights reserved.
