# BACKEND: API endpoints the apps need before the web closes

Status: done
Role: backend
Blocked by: none
Spec: `../spec.md`

## Scope (owned files)

- `routes/api.php` and module `routes/api.php` files (Ticketing, SPK, Inventory), new API controllers/requests/resources under `app/Http/Controllers/Api/V1/**` and `Modules/*/app/Http/Controllers/Api/V1/**` following the existing v1 conventions (allow-list resources, Idempotency-Key, JSON errors), services only where the web path already has one (reuse `TicketService`, `SpkService`, `InventoryRequestService`, `UserService`).
- `docs/api/mobile-v1.openapi.json` regenerated with the existing Scramble export command.
- Tests under `tests/Feature/Api/V1/**`.

## Change

Implement spec R3 exactly: ticket attachments (technician + customer), SPK items add/remove, technician product search, inventory request create/cancel, `PATCH me`, `POST me/password`. Mirror the web policies; never widen. Keep `EnsureCustomerAccess` / `MyWorkQuery::isEligible` untouched. Message MobTech/MobCust (`write agent://MobTech`, `agent://MobCust`) with the final JSON shapes as soon as resources exist.

## Acceptance criteria

- [x] Every endpoint has happy, validation, policy-403, tenant-404 and idempotency tests; focused PHPUnit green; Pint clean.
- [x] OpenAPI export committed and lists the new paths.

## Comments

Evidence (2026-10-13, BeApi):
- `sail exec -T -u sail laravel.test flock /tmp/inbils-phpunit.lock sh -c 'php artisan scramble:export; php -d memory_limit=1G artisan test --compact tests/Feature/Api/V1 tests/Unit/RolePermissionSeederTest.php tests/Feature/RolePersonaSodTest.php tests/Feature/TicketAttachmentSharedUploadTest.php'` → export wrote `docs/api/mobile-v1.openapi.json` with the 5 known warnings; `Tests: 334 passed (4662 assertions)`, including `OpenApiContractTest` (committed export equals a fresh one) and the matrix walks (50 routes, 26 id routes, 24 mutating routes, 20 GET routes).
- Pint on the touched files: `{"tool":"pint","result":"passed"}`. Larastan level 5 on the touched API paths: no error in a file of this ticket.
- New tests: `Shared/TicketAttachmentsTest`, `Shared/MeProfileTest`, `Technician/WorkOrderItemsTest`, `Technician/InventoryRequestWritesTest`, `Technician/ProductPickerTest`; `Matrix/*` extended with the new routes; `Unit/RolePermissionSeederTest` pins the new grant.
- Routes added (12): `POST|DELETE {customer|technician}/tickets/{id}/attachments[/{media}]` (upload throttle `api-upload`), `POST technician/work-orders/{wo}/items`, `DELETE .../items/{item}`, `GET technician/products`, `GET technician/warehouses`, `POST technician/inventory-requests`, `POST technician/inventory-requests/{inventoryRequest}/cancel`, `PATCH me`, `POST me/password`.
- Shapes sent to MobTech and MobCust. Differences from spec R3, all additive or forced by the code: `GET technician/warehouses` (the app had no way to list warehouses); requests from the app are submitted at once; `TicketAttachmentResource` gains `caption`, `mine`, `can_delete`; `Me` gains `phone`; `actions.edit_items` on the SPK, `actions.cancel` on the inventory request; SPK `quantity` sets `quantity_used` (one line per product, a planned `quantity_reserved` stays).
- Decision recorded: the web upload needs `ticket.attachment.upload`, which only admin and staff held, so spec R3 "same policy as the web" was dead for both app roles. Main approved seeding it to technician and customer (`RolePermissionSeeder`, `docs/SECURITY.md` 2.3 and new 4.7).
- Changelog line for ticket 08: "Mobile API: technician and customer apps upload and delete ticket files, the technician records SPK material and asks for / cancels stock, both apps edit name and phone and change the password. Deploy: `php artisan db:seed --class=RolePermissionSeeder --force` (grants `ticket.attachment.upload` to technician and customer; without it every upload is 403), then `docs/SECURITY.md` 4.7."
- Not done here: web tests of customer pages that now redirect to `/app-only` (`tests/Feature/Ticketing/TicketCustomerScopeTest.php` and friends) belong to ticket 02/03.

Follow-up from the security review (2026-10-13, BeApi, both LOW):
- `SpkService::removeMaterial` no longer deletes a manager-planned line. `DELETE technician/work-orders/{wo}/items/{item}` sets `quantity_used` to 0 and keeps the line when it has a reservation (`quantity_reserved > 0`) or a note (audit `item_usage_cleared`, once, only when the quantity changed); a line with nothing else on it is deleted (`item_removed`). Beyond the request: a line with a network asset is refused with 422, because the app can neither add nor edit one and zeroing its use would skip the stock issue at completion. Answer stays 204; MobTech told to re-read the SPK, show "Hapus" only while `quantity_used > 0` and map the 422.
- Web `TicketController::removeAttachment` now calls `TicketService::removeAttachment`: the uploader only, only while the ticket is `open`, `assigned` or `on_progress`, an unknown uploader stays. A manager who is also a technician no longer clears other people's files with the permission the technician role brings. Side effect to know: there is no admin override either (the route has no button, so nobody lost a screen). The web delete now writes the `attachment_removed` audit entry too.
- Red then green: `Technician/WorkOrderItemsTest` (3 new tests, 1 changed line helper) and the new `Ticketing/TicketAttachmentRemovalTest` (6 tests) failed 8 of 16 before the change. After: `php artisan scramble:export` then `php artisan test --compact tests/Feature/Api/V1 tests/Feature/Ticketing/TicketAttachmentRemovalTest.php tests/Feature/TicketAttachmentSharedUploadTest.php` → `Tests: 301 passed (4499 assertions)`; Pint passed; Larastan clean on the touched ranges.
- `docs/SECURITY.md` 4.7 reworded for both rules.
