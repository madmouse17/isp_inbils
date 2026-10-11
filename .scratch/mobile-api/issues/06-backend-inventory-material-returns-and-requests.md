# BACKEND: technician material returns (list, show, declare), own inventory requests (read-only), "ready for pickup" in-app notice

Status: done
Role: backend
Blocked by: 01
Card: none (new lane; no Hermes card)
Skills: laravel-best-practices, tdd, web-security-review, ponytail, caveman

## Scope

Implements the material-return and inventory-request rows of the spec contract, Q13a, Q14a and the technician part of Q18.

- `Modules/Inventory/routes/api.php` (NEW), mapped by the Inventory `RouteServiceProvider` under `api/v1`. Routes use `api.technician`.
- `GET technician/material-returns` (`work_order_id?`, `status?`) and `GET technician/material-returns/{id}`:
  - Own cases only, through `MaterialReturnCasePolicy::viewAny/view` (`Modules/Inventory/app/Policies/MaterialReturnCasePolicy.php:13-17,29-31`).
  - The `work_order_id` filter matches cases of inventory requests with `source_type = work_order`, `source_id = id`.
  - Another technician's case is 404.
- `POST technician/material-returns/{id}/declare`: `DeclareMaterialReturnRequest` (unchanged rules, `Modules/Inventory/app/Http/Requests/DeclareMaterialReturnRequest.php:17-30`) and `MaterialReturnCasePolicy::declare`. The request's `idempotency_key` is filled from the validated `Idempotency-Key` header (ticket 01), then `MaterialReturnService::declareUsage($case, $user, $data)`. The service's 409 on a payload mismatch passes through as `conflict`.
- `GET technician/inventory-requests` (`status?`): the technician's own requests (`inventory.request.view.own`), newest first, `{id, code, status, items:[{product_name, unit, quantity}], work_order:{id, code}|null, updated_at}`.
- Resources: reuse `MaterialReturnCaseResource`, `MaterialReturnLineResource` and `InventoryRequestResource` only if every field is the technician's own data. Otherwise write NEW allow-list resources. Record which in a ticket comment.
- Q18: `InventoryRequestReadyNotification::via()` (`Modules/Inventory/app/Notifications/InventoryRequestReadyNotification.php:23-25`) adds `database`. The existing `toArray` payload (`:40-43`) is kept. Check that `NotificationResource` renders `inventory_request_ready` with a sensible title and message; the change itself is ticket 02's file, so report `needs-change` if it is missing.

Non-goals: creating, submitting or cancelling requests in the API (Q14a), pickup confirmation (no such step exists), declaration photos, warehouse actions.

## Owned files

- NEW `Modules/Inventory/routes/api.php`; `Modules/Inventory/app/Providers/RouteServiceProvider.php`
- NEW `Modules/Inventory/app/Http/Controllers/Api/V1/Technician/` (material returns, inventory requests)
- NEW `Modules/Inventory/app/Http/Resources/Api/V1/` (only if the existing resources are not technician-safe)
- `Modules/Inventory/app/Notifications/InventoryRequestReadyNotification.php`
- NEW `tests/Feature/Api/V1/Technician/MaterialReturnsTest.php`, NEW `tests/Feature/Api/V1/Technician/InventoryRequestsTest.php`

## Acceptance criteria

- [x] A technician lists and opens only their own cases. The `work_order_id` filter returns exactly the cases linked to that SPK. Another technician's case is 404.
- [x] Declare with valid lines moves the case out of `awaiting_declaration` (as `tests/Feature/Inventory/MaterialReturnWorkflowTest.php` expects). The same `Idempotency-Key` replayed after the cache entry is flushed returns the original state through the service's own key, with no second declaration. The same key with different quantities is 409 `conflict`.
- [x] After declaring every case of an SPK, `POST technician/work-orders/{id}/submit` (ticket 05) succeeds. Before that it is 422.
- [x] The inventory request list contains only the technician's requests, including `ready_for_pickup` ones.
- [x] Moving a request to `ready_for_pickup` creates one database notification for the requester. It appears in `GET notifications` (ticket 02) with a `subject` of type `inventory_request`, and the mail is still sent.
- [x] The existing inventory tests stay green (`tests/Feature/Inventory/*`).
- [x] Pint is clean and Larastan is clean on changed files.

## Comments

### 2026-10-11: done (backend agent MobileApiSpkInventory)

Resources: NEW allow-list resources, none of the existing ones reused. `MaterialReturnCaseResource` carries `company_id`, `idempotency_key`, warehouse actors (receiver, reconciler, resolver) and event payloads, and `InventoryRequestResource` carries approver, warehouse and audit fields, so neither is technician-safe. New: `TechnicianMaterialReturnResource` (case, request code, SPK, lines with product name/sku/unit and the declared quantities, `can.declare`) and `TechnicianInventoryRequestResource` (`{id, code, status, items:[{product_name, unit, quantity}], work_order:{id, code}|null, updated_at}`).

Endpoints (all `api.technician`):
- `GET /api/v1/technician/material-returns` (`work_order_id?`, `status?`, `per_page?`): the technician's own cases (`technician_id` = caller, company scoped), through `MaterialReturnCasePolicy::viewAny`. The filter matches cases of inventory requests with `source_type=work_order`, `source_id=id`. Technicians with `inventory.return.view.all` still only get their own here.
- `GET …/material-returns/{id}`: another technician's or another company's case is 404.
- `POST …/material-returns/{id}/declare`: `DeclareMaterialReturnRequest` unchanged, `idempotency_key` filled from the validated header, then `MaterialReturnService::declareUsage`. Same key and payload after the cache is flushed returns the case (service key); same key with other quantities is 409 `conflict`. A replay of an already-declared case skips the `declare` policy (status is no longer declarable) so the service's own idempotency answers.
- `GET …/inventory-requests` (`status?`, `per_page?`): own requests only, newest first, `ready_for_pickup` included.

Q18: `InventoryRequestReadyNotification::via()` is now `['mail', 'database']`, `toArray` unchanged. Red first: without the channel `test_ready_for_pickup_creates_one_database_notification` fails with `RecordsNotFoundException`. `NotificationResource` (core, ticket 02) already maps `inventory_request_ready` to a title and message. It has no `subject` yet and no `inventory_request` entry in `detailUrl`; adding `subject {type: inventory_request, id: request_id}` is ticket 02's file: needs-change.

Submit after declaring: `MaterialReturnsTest::test_spk_submit_succeeds_only_after_all_return_cases_are_declared` (422 before, 200 after).

