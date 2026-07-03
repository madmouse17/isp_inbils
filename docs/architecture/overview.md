# Architecture Overview — inbils

> ISP Operations Support System. Laravel 12 + Inertia.js + React 18 + TS + Tailwind v3.

## Principles

- **Layered backend:** Controller (thin) → FormRequest (validation) →
  Policy (authorization) → Service (business logic) → Model (data) →
  Migration (schema).
- **Action Class** for multi-service orchestration only (e.g.
  `CompleteSpkAction` calls InventoryService + NetworkAssetService +
  CustomerService + BillingService + ActivityLogger).
- **Query Class** for complex reads (>30 lines or >1 controller).
- **Resource** for all API/Inertia entity/list responses.
- **No business logic in Model.** Model = relation + scope +
  accessor/mutator + factory.
- **No business logic in Controller.** Parse request → service →
  Inertia/resource/redirect.
- **Frontend:** React via Inertia.js (page-level island), compose
  primitives from `Components/ui/`, state server-driven via Inertia
  props.
- Prefer simplicity. Follow YAGNI. Avoid unnecessary abstraction.

## Inertia-as-Island (Decision A)

Each route = one React page (one "island"). Sub-interactivity within a
page (modal, tab, filter, toast) = local `useState`, no server fetch.
Only persistent actions (save/delete) use `router.visit`.

- Form state: `useForm` from `@inertiajs/react`.
- UI state local: `useState` (modal, tab, filter draft).
- Global UI: Toast via `useToast`. Dark mode via `localStorage`.
- NO Redux/Zustand/Jotai. NO React Query/SWR. NO axios/fetch.

## Request Flow

```
Browser → HTTP GET /admin/network-assets
  → Laravel route (auth + verified + permission:network_asset.view)
  → NetworkAssetController@index
    → NetworkAssetQuery::paginate(filters)
    → NetworkAssetResource::collection(...)
    → Inertia::render('Admin/NetworkAsset/Index', [assets, filters, auth])
  → React AdminLayout + AssetsPage hydrate
  → User clicks "Install Asset" → router.visit('/admin/network-assets/{id}/install')
    → GET render install form
  → Submit → router.post('/admin/network-assets/{id}/install', data)
    → InstallAssetRequest (validation)
    → NetworkAssetPolicy::install (authorization)
    → NetworkAssetService::install(...)
      → DB transaction
      → NetworkAsset: status=Installed, location_id, customer_id, subscription_id
      → NetworkAssetInstallation::create (append-only history row)
      → ActivityLog::record
    → Redirect → /admin/network-assets/{id} (flash success)
```

## Transactions & Integrity

- Write operations across tables = `DB::transaction()` in Service.
- FK constraints MANDATORY in migration.
- `ON DELETE RESTRICT` for parent entities (prevent deleting parent
  with active children).
- `ON DELETE CASCADE` for child line items (`invoice_items`,
  `work_order_items`, `ticket_comments`).
- Unique index for business codes per-company: `(company_id, code)`,
  `(company_id, serial_number)`.
- Check constraint for enum-like columns (`work_orders.status`,
  `network_assets.status`, `tickets.status`, `invoices.status`).
- Soft delete ONLY for historical entities. StockMovement,
  NetworkAssetInstallation, Payment = immutable (append-only, no soft
  delete).

## Caching & Queue

- Cache: permission cache (spatie), setting cache (array driver), route
  cache (prod). Do NOT cache fast-changing business data (stock, asset
  status).
- Queue: `database` driver (dev), Redis (prod). Jobs:
  - Email (invoice sent, overdue, ticket assigned/resolved, SPK
    assigned).
  - PDF generation (invoice batch, performance report).
  - Excel import.
  - **Recurring invoice generation** (monthly job:
    `GenerateRecurringInvoices`).
  - **Auto-suspend check** (daily job: `CheckOverdueAndSuspend`).
  - **SLA breach check** (daily job: `CheckSlaBreach`).
  - Sync for v1 dev (`sync` driver) except import/export/recurring.
