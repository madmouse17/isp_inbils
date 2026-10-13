# BACKEND: move customer, employee, product, ticket and inventory request onto the engine

Status: done
Role: backend
Blocked by: none (builds against the contract in 01)
Spec: `../spec.md`

## Scope

- Every caller of `NumberSequenceService::generate` drops the removed `$prefix` argument:
  - InvoiceController, BillingService, CreateInvoiceFromSpkAction
  - WorkOrderController, SpkService
  - TicketService, CreateCustomerOnboardingAction, SpawnSpkFromTicketAction
  - NetworkAssetService
  - the demo seeders `BillingDemo` and `TicketDemo`
- Ticket: `TicketService::generateCode` is replaced by `NumberSequenceService::generate('ticket', $companyId)`. Remove the old generator and update its callers.
- Inventory request: `InventoryRequestService::generateCode` is replaced by `generate('inventory_request', $companyId)`. Remove the old generator.
- Customer (`customers.code`), employee (`employee_profiles.employee_number`) and product (`products.sku`):
  - Create requests: the field becomes `nullable`, with `prohibited` when `! allowsManual`, and keeps its unique rule. The service or controller stores `NumberSequenceService::resolve(...)`.
  - Update requests: when `! allowsManual` the field is `exclude`d, so the stored code never changes. Otherwise the current rules stay.
  - Every application create path goes through `resolve`: web, API, actions and imports. Grep `Customer::create`, `EmployeeProfile::create`, `Product::create` and the `new`/`fill` variants outside tests and seeders.
- Create and edit pages receive the prop `codeSequence: { manual: boolean; preview: string }`:
  - Customer Create and Edit
  - the Employees index, whose dialog both creates and edits
  - the Inventory Products Create and Edit
  - If `Pages/Admin/Products/Create.tsx` is still routed, give it the same prop. If it is dead, report it.
- Update existing tests that assert old code formats or post codes. Add tests for the manual on/off behaviour on create and update for each of the three entities.

## Acceptance criteria

- [x] A customer, employee or product created without a code gets the configured format.
- [x] A code sent while manual input is off returns 422 on create and is ignored on update.
- [x] A product SKU typed by hand is kept.
- [x] Ticket and request codes come from the engine: yearly and daily reset, and parallel-safe.
- [x] Focused PHPUnit passes.

## Comments

2026-10-13 SeqAdopt: all callers of `generate` dropped the `$prefix` argument (invoice, spk, subscription, network_asset, seeders). `TicketService::generateCode` and `InventoryRequestService::generateCode` are removed; both call `generate('ticket'|'inventory_request', $companyId)`.
- Customer: `CreateCustomerOnboardingAction` resolves the code (`CustomerService::createWithUser` stays a plain writer for tests/seeders). Employee and product controllers resolve inside `DB::transaction`. Store requests: `nullable` + `prohibited` when manual is off; update requests: `exclude` when manual is off.
- `codeSequence` prop added: Customer Create/Edit, Employees index, Inventory Products Create/Edit. `resources/js/Pages/Admin/Products/Create.tsx` is unreferenced by any controller or route (dead); not touched.
- Tests: new `tests/Traits/AllowsManualCodes.php`; new `ProductCodeSequenceTest`, `TicketCodeSequenceTest`; manual on/off cases added to `EmployeeTest` and `CustomerAccountCreationTest`; daily-reset case added to `InventoryRequestWorkflowTest`; customer-posting tests enable manual input in setUp.
- Evidence: focused PHPUnit 55 passed (batch 1) and 705 passed (batch 2: customer, WhatsApp, IPAM/ODN, P0, tenant, NetworkAsset, Ticketing, DispatchQueue, MyWork); re-run of Ticketing/IR tests 21 passed; Pint passes on touched files.
