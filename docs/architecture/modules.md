# Module Architecture — inbils (nwidart/laravel-modules)

## Package

- `nwidart/laravel-modules` v12.
- Generate: `php artisan module:make {Name}`.
- Namespace: `Modules\{Name}\...`.
- Tables NOT prefixed (global `products`, not `inventory_products`).
- Route registration: `Modules/{Name}/Routes/web.php`.

## Module Structure (standard)

```
Modules/{Name}/
  Config/
  Database/{Migrations,Factories,Seeders}/
  Http/{Controllers,Requests}/
  Models/
  Services/
  Actions/
  Policies/
  Resources/
  Routes/{web,api}.php
  Tests/
  module.json               # dependencies: []
```

## Modules

| Module | Models | Phase |
|--------|--------|-------|
| Customer | (shared Core models) | 2 |
| Service | ServicePackage, BandwidthProfile, SpeedProfile, SLATier | 2 |
| NetworkAsset | NetworkAsset, NetworkAssetInstallation | 3 |
| Inventory | Product, Category, Unit, Stock, StockMovement | 3 |
| SPK | WorkOrder, WorkOrderItem, WorkOrderAssignment, WorkOrderEvidence | 4 |
| Billing | Invoice, InvoiceItem, Payment | 5 |
| Ticketing | Ticket, TicketCategory, TicketComment, TicketAttachment | 6 |
| Reporting | (none — Query classes only) | 8 |

## Shared Core (NOT in a module)

`app/Models/Core/`: Company, User, Role, Permission, ActivityLog,
Setting, Customer, CustomerAddress, CustomerContact,
ServiceSubscription, Location, EmployeeEvaluation.

`app/Services/Core/`: CompanyService, SettingService,
SetupWizardService, AuditService, UserService, CustomerService,
SubscriptionService, LocationService, EvaluationService.

## Rules (MANDATORY)

1. Domain logic in `Modules/`. Shared cross-cutting in `app/`.
2. Module must NOT couple directly. Use Service contract / Action class /
   Event-listener / polymorphic reference.
3. Document dependencies in `module.json` + `docs/business/{module}.md`.
4. Tables NOT prefixed.
5. New module: `php artisan module:make {Name}` + fill `module.json` +
   create `docs/business/{module}.md`.
6. Shared Core model new: `app/Models/Core/` + trait
   `BelongsToCompany` + service in `app/Services/Core/`.
