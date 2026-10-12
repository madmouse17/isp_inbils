# BACKEND: manual block/unblock per subscription (Core + Receivables data)

Status: done
Role: backend
Blocked by: none
Card: none (new lane; no Hermes card)

## Scope

`../spec.md` Q10 (a) and Q11 (a). No RADIUS code here: ticket 04 makes the network follow `blocked_at`.

- Migration `service_subscriptions`: `blocked_at` timestamp null, `blocked_by` FK `users` null `nullOnDelete`, `block_reason` string(500) null, index `(company_id, blocked_at)`. `down()` drops them.
- `ServiceSubscription`: cast `blocked_at` datetime; relation `blockedBy()`; the three columns NOT fillable (like `suspension_source`, written with `forceFill`).
- `SubscriptionService::block(ServiceSubscription $subscription, string $reason): ServiceSubscription` and `unblock(ServiceSubscription $subscription, ?string $note = null): ServiceSubscription` in the style of `suspend` (`app/Services/Core/SubscriptionService.php:80-99`): transaction, `lockForUpdate`, `abort_if` 422 (`block`: terminated or already blocked; `unblock`: not blocked), `AuditService::log('service_subscription', 'blocked'|'unblocked', ['code', 'reason'|'note'], $subscription)`. No customer-status recalculation. `terminate` clears the three columns in the same save.
- Status rules are unchanged: `suspend`, `reactivate`, `OverdueSuspensionService` and Q16 never read or write the block columns.
- `SubscriptionController::block` / `unblock` like `suspend` (`app/Http/Controllers/Admin/SubscriptionController.php:155-168`): `ensureSameCompany`, `Gate::authorize('customer.subscription.block')`, inline validation `reason` required string ≤500 (block) / nullable ≤500 (unblock), redirect back with `success`. `show` adds `can.block`.
- `routes/admin.php` next to `:95-96`: `POST subscriptions/{subscription}/block` → `subscriptions.block`, `POST subscriptions/{subscription}/unblock` → `subscriptions.unblock`.
- `SubscriptionResource`: `blocked_at`, `block_reason`, `blocked_by` (`{id, name}` or null; eager-load in `show`).
- `RolePermissionSeeder`: add `customer.subscription.block` (admin via "all", manager next to `:225-226`); staff, technician, customer: no. Update `RolePermissionSeederTest` expectations.
- Receivables: `BillingService::receivables` subscription map (`Modules/Billing/app/Services/BillingService.php:441-476`) adds `blocked_at` (select the column); `InvoiceController::receivables` (`Modules/Billing/app/Http/Controllers/InvoiceController.php:216`) adds `can.block`.
- Docs: `docs/business/customer.md` (block vs suspension, rules list from Q10a), `docs/business/billing.md` (payment never lifts a block; Receivables shows it).

Owned files: NEW `database/migrations/2026_10_16_100000_add_block_columns_to_service_subscriptions_table.php`, `app/Models/Core/ServiceSubscription.php`, `app/Services/Core/SubscriptionService.php`, `app/Http/Controllers/Admin/SubscriptionController.php`, `app/Http/Resources/SubscriptionResource.php`, `routes/admin.php`, `database/seeders/RolePermissionSeeder.php`, `tests/Unit/RolePermissionSeederTest.php`, `Modules/Billing/app/Services/BillingService.php`, `Modules/Billing/app/Http/Controllers/InvoiceController.php`, `docs/business/customer.md`, `docs/business/billing.md`, NEW `tests/Feature/SubscriptionBlockTest.php`.

Produces: columns `service_subscriptions.blocked_at|blocked_by|block_reason`; routes `admin.subscriptions.block|unblock`; permission `customer.subscription.block`; props from `../spec.md` Props contract (Subscriptions/Show and Receivables).

## Acceptance criteria

- [ ] `SubscriptionBlockTest`: block/unblock happy path with audit (reason present, causer set, no other properties); 422 cases; 403 without permission; 404 for another company's subscription; block works on `pending`, `active`, `suspended`; terminate clears the block.
- [ ] Interaction rows: `billing:suspend-overdue` still suspends a blocked active subscription (source `overdue`); a clearing payment reactivates its status and `blocked_at` stays; unblock of a suspended subscription leaves `status=suspended`; `reactivate` never clears a block.
- [ ] Subscriptions/Show props carry `blocked_at`, `block_reason`, `blocked_by`, `can.block`; Receivables rows carry `subscriptions[].blocked_at` and `can.block`.
- [ ] Existing `tests/Feature/SubscriptionServiceTest.php`, `tests/Feature/Billing/*` stay green.

## Commands

Sail runs on docker context `default`: prefix every sail call with `DOCKER_CONTEXT=default`; never `sail up`, never the desktop-linux context.

```
DOCKER_CONTEXT=default ./vendor/bin/sail exec laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact tests/Feature/SubscriptionBlockTest.php tests/Feature/SubscriptionServiceTest.php tests/Feature/Billing tests/Unit/RolePermissionSeederTest.php
```

## Comments
