# Folder Structure — inbils

## Backend

```
app/                          # SHARED Core (cross-cutting, bukan domain)
  Http/
    Controllers/
      Admin/                  # controller dashboard/admin
        Customer/             # CRUD customer/address/contact/subscription
        Service/              # CRUD service catalog
        NetworkAsset/         # CRUD asset + installation + topology
        Inventory/            # CRUD consumable product + stock + movement
        Spk/                  # SPK (install/maintain/upgrade/relocate)
        Billing/              # invoice + payment + recurring + suspend
        Ticketing/            # ticket (ISP categories + NOC source)
        Reporting/            # read-only report pages
        Setting/              # company profile/settings + system setting
      Auth/                   # Breeze existing
      Controller.php          # base
    Middleware/
      RedirectIfNoCompany.php
      RequireNoCompany.php
      RequireHasCompany.php
    Requests/Admin/
    Resources/
  Models/
    Core/                     # SHARED cross-cutting
                              # Company, User, Role, Permission,
                              # ActivityLog, Setting, Customer,
                              # CustomerAddress, CustomerContact,
                              # ServiceSubscription, Location,
                              # EmployeeEvaluation
    Traits/                   # BelongsToCompany
  Services/Core/              # CompanyService, SettingService,
                              # SetupWizardService, AuditService,
                              # UserService, CustomerService,
                              # SubscriptionService, LocationService,
                              # EvaluationService
  Actions/                    # cross-service multi-step actions
  Queries/                    # complex read queries (cross-module)
  Policies/

Modules/                      # DOMAIN (nwidart/laravel-modules)
  Customer/                   # CRUD around shared core models
  Service/                    # ServicePackage, Bandwidth, Speed, SLATier
  NetworkAsset/               # NetworkAsset, Installation history
  Inventory/                  # Product, Category, Unit, Stock, StockMovement
  SPK/                        # WorkOrder + items + assignments + evidence
  Billing/                    # Invoice, InvoiceItem, Payment
  Ticketing/                  # Ticket, Category, Comment, Attachment
  Reporting/                  # NO models — Query classes only

database/
  migrations/                 # shared Core migrations
  factories/
  seeders/                    # RolePermissionSeeder, SystemSettingSeeder

tests/
  Feature/
  Unit/
```

### Key decisions (A1)

- Customer/Address/Contact/ServiceSubscription/Location/
  EmployeeEvaluation in `app/Models/Core/` (shared), NOT in module.
  Reason: consumed by >=4 modules. Direct model access cleaner than
  interface ceremony.
- Modules/Customer + Modules/Service = controllers/services around
  shared core models. Module = ownership workflow, not ownership model.
- ServicePackage in `Modules/Service/Models/`: catalog master,
  FK-consumed like Product.

## Frontend

```
resources/js/
  Components/
    ui/                       # primitives — props-only, no fetch
    composite/                # business composites: DataTable, FormField,
                              # PageHeader, StatusBadge, MoneyInput, etc.
  Layouts/
    AdminLayout.tsx           # sidebar + topbar shell
  Pages/
    Admin/
      Dashboard/              # NOC view + Manager view
      Customer/               # customer + address + contact + subscription
      Service/                # service catalog
      NetworkAsset/           # asset + installation + topology
      Inventory/              # consumable product + stock + movement
      Spk/                    # SPK list/form/detail/evidence
      Billing/                # invoice + payment + recurring + suspend
      Ticketing/              # ticket (ISP categories + NOC view)
      Reporting/              # technician/unit/business reports
      Company/                # profile + settings
      Users/                  # user + role + permission
    Setup/
      Wizard.tsx              # first-login, no AdminLayout
  hooks/                      # useToast, usePermission, useFilter, useInertiaForm
  lib/
    utils.ts                  # cn helper
    format.ts                 # formatRupiah, formatDate, formatNumber, formatBandwidth
    api.ts                    # Inertia visit wrappers (typed)
  types/
    global.d.ts
    models.d.ts               # entity TS interfaces (mirror Resource)
```

### Rules

- `Components/ui/*` = primitives, no business knowledge. Props-only.
- `Components/composite/*` = compose primitives for business patterns.
  May import `lib/api.ts` for persistent actions via Inertia.
- `Pages/**` = compose composite + ui. May call Inertia `router`.
- `hooks/usePermission` = read `auth.permissions` from Inertia shared prop.
