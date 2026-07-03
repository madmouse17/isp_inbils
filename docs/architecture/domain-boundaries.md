# Domain Boundaries & Module Dependencies — inbils

## Naming Convention

| Artifact | Pattern |
|----------|---------|
| Migration | `create_{table}_table` / `add_{col}_to_{table}` |
| Model | Singular PascalCase |
| Table | snake_case plural |
| FK | `{singular}_id` unsigned |
| Polymorphic | `{name}_id` + `{name}_type` |
| Pivot | `{a}_{b}` alphabetical |
| Controller | `{Entity}Controller` resource methods |
| Service | `{Domain}Service` |
| Action | `{Verb}{Entity}Action` |
| Policy | `{Model}Policy` |
| Resource | `{Model}Resource` |
| Route name | `admin.{module}.{action}` |
| Permission | `{module}.{action}` |

## Dependency Rules

Allowed direction:
```
Controller → Service → Model → DB
Controller → FormRequest (validation)
Controller → Policy (authz)
Controller → Resource (response shape)
Service → Model
Service → Action (cross-domain)
Service → Service (domain) via interface OR direct (shared Core)
Action → Service (many)
Model → Model (relation only, no service call)
```

Forbidden:
- Model → Service (model does not know business logic).
- Controller → Model directly (bypass service). Exception: simple read
  via Query class.
- Core → Module (inverted dependency). Shared Core models must NOT FK
  to Module models.
- `Components/ui/*` → `Components/composite/*` (primitive does not know
  composite).
- Module A → Module B Model directly (use Service contract or
  polymorphic reference).

**Documented exception (D-R5):** `service_subscriptions.ont_asset_id`
FK → `network_assets.id` (Core → Module, inverted). Tolerated because
ONT link is ISP-essential. This is a denormalized backlink (NOT a
dependency call — Core code does not import NetworkAsset model).

## Module Dependency Direction (ISP)

```
SPK        ──issue──→    Inventory       (consume consumable stock)
SPK        ──install──→  NetworkAsset    (completion → install asset)
SPK        ──activate──→ Customer(Core)  (completion → activate subscription)
SPK        ──trigger──→  Billing         (completed → OTC invoice)
Ticketing  ──spawn──→    SPK             (ticket → generate work order)
Ticketing  ──link──→     Customer/NetworkAsset/Subscription (shared Core)
Billing    ──suspend──→  Customer(Core)  (subscription status)
Billing    ──issue──→    Inventory       (invoice stock issue, rare)
NetworkAsset──link──→    Customer/Subscription/Location (shared Core)
Reporting  ──read──→     ALL             (read-only Query, no write)
```

NOT allowed: Inventory/NetworkAsset import SPK/Billing model. Reverse
direction only via polymorphic reference or shared Core model.

## module.json Dependencies

| Module | Dependencies |
|--------|-------------|
| Customer | [] (models shared Core) |
| Service | [] (catalog) |
| NetworkAsset | [] (depends Core only) |
| Inventory | [] |
| SPK | [Inventory, NetworkAsset] |
| Billing | [Inventory, SPK, Service] |
| Ticketing | [SPK, NetworkAsset] |
| Reporting | [] (read-only exception) |

## Cross-Module Communication

1. **Service interface / contract** — Module A calls Service B contract.
2. **Action class** — Cross-module orchestration (e.g.
   `CompleteSpkAction` calls InventoryService + NetworkAssetService +
   SubscriptionService + BillingService).
3. **Event/listener** — Loose coupling for non-critical.
4. **Polymorphic reference** — `stock_movements.reference` (string
   type, no FK hard-coupling).

**Exception:** SHARED Core models (Customer/CustomerAddress/
CustomerContact/ServiceSubscription/Location/EmployeeEvaluation in
`app/Models/Core/`) may be direct-imported from any module — these are
shared, not owned by one module.
