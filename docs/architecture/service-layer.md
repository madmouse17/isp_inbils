# Service Layer — inbils

## Pattern

```
Controller (thin)
  → FormRequest (validation + authorize)
  → Policy (authorization per model)
  → Service (business logic, DB transactions)
  → Model (data + relation + scope + accessor/mutator + factory)
  → Migration (schema)
```

## Service Responsibilities

- Business logic (create, update, delete, state transitions).
- `DB::transaction()` for multi-table writes.
- Call AuditService for activity logging.
- Call other Services via interface or direct (shared Core).
- Return Model instances or Resource collections.

## Action Class (cross-service orchestration)

Use when a single operation spans multiple services:

```php
class CompleteSpkAction
{
    public function __construct(
        private InventoryService $inventory,
        private NetworkAssetService $networkAsset,
        private SubscriptionService $subscription,
        private BillingService $billing,
        private AuditService $audit,
    ) {}

    public function execute(WorkOrder $wo): void
    {
        DB::transaction(function () use ($wo) {
            $this->inventory->issueStock($wo->items, $wo->location);
            $this->networkAsset->install($wo->network_asset, $wo->location, $wo->customer);
            $this->subscription->activate($wo->subscription);
            $this->billing->createOtcInvoice($wo);
            $this->audit->log($wo, 'completed');
        });
    }
}
```

## Query Class (complex reads)

- For queries >30 lines or used in >1 controller.
- Location: `app/Queries/` or `Modules/{Name}/Queries/`.
- Read-only. No writes.
- Example: `NetworkAssetQuery::paginate(filters)` with joins, filters,
  sorting.

## Resource (response shape)

- Every entity/list response goes through a Resource.
- Ensures frontend contract consistency.
- Example: `NetworkAssetResource::collection($assets)`.

## Forbidden in Service

- No HTTP request/response handling (controller job).
- No direct `request->input()` (FormRequest validated data passed in).
- No view rendering (controller/Inertia job).
- No `env()` call (use `config()`).
