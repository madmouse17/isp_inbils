# BACKEND: core endpoints — notification inbox, media stream, customer profile and subscriptions, technician my-work

Status: done
Role: backend
Blocked by: 01. The customer half of the inbox is also blocked by `.scratch/whatsapp-notifications` reaching `done`.
Card: none (new lane; no Hermes card)
Skills: laravel-best-practices, tdd, web-security-review, ponytail, caveman

## Scope

Implements the "Shared" rows, the customer profile and subscription rows, and `technician/my-work` from the spec contract, plus Q18.

- `GET notifications`, `POST notifications/{id}/read`, `POST notifications/read-all` (`api.any`).
  - A technician token reads `$user->notifications()`. A customer token reads the resolved `Customer`'s notifications, which needs the WhatsApp lane's `Notifiable` on `Customer`; if that is missing at start, report `needs-change` instead of adding it.
  - Rows are rendered by `NotificationResource`, which gains `subject {type, id}` derived from payload ids: `ticket_id` → ticket, `work_order_id` → work_order, `invoice_id` → invoice, `request_id` → inventory_request, `case_id` → material_return; otherwise null. Titles and messages for the new customer event types are added to the same resource. One mapping serves web and API.
- Q18 customer events: `app/Notifications/WhatsAppNotification.php` `via()` adds `database` when the notifiable is a `User`, or a `Customer` with `user_id`. `toArray` stores only `type` (the event key) and subject ids, never the phone, name, amount or template. The access-link notice never goes to the database (it concerns passwords).
- `GET media/{id}`: mount the existing `App\Http\Controllers\Admin\MediaController::show` under `api.any`. Do not copy it. Its owner policy and company check already work with the token user (`app/Http/Controllers/Admin/MediaController.php:24-30`).
- `GET customer/profile`, `GET customer/subscriptions`, `GET customer/subscriptions/{id}` (`api.customer`).
  - New allow-list resources with exactly the spec fields. Never reuse `CustomerResource` or `SubscriptionResource`.
  - Subscriptions are read through the resolved customer's relation; another customer's id is 404.
  - The package name comes from `servicePackage`; ticket 07 records the speed label in the OpenAPI doc if the package exposes one.
- `GET technician/my-work` (`api.technician`): `MyWorkQuery::filtered($request)` paginated with `MyWorkQuery::perPage`, items rendered by `MyWorkItemResource`, and `meta.summary` = `MyWorkQuery::summary($request)`. Pass through the same query parameters the web page accepts and document them in the test. Add nothing new to the query.
- Append these routes to `routes/api.php` (owned here after 01 lands).

Non-goals: push, notification pruning, new notification types beyond the WhatsApp-lane events and `inventory_request_ready` (added in ticket 06), and any change to `MyWorkQuery`.

## Owned files

- `routes/api.php` (after 01)
- `app/Http/Resources/NotificationResource.php`, `app/Notifications/WhatsAppNotification.php` (after the WhatsApp lane is `done`)
- NEW `app/Http/Controllers/Api/V1/NotificationController.php`, NEW `app/Http/Controllers/Api/V1/Customer/` (profile, subscriptions), NEW `app/Http/Controllers/Api/V1/Technician/MyWorkController.php`
- NEW `app/Http/Resources/Api/V1/CustomerProfileResource.php`, NEW `app/Http/Resources/Api/V1/CustomerSubscriptionResource.php`
- NEW `tests/Feature/Api/V1/Shared/`, NEW `tests/Feature/Api/V1/Customer/ProfileAndSubscriptionsTest.php`, NEW `tests/Feature/Api/V1/Technician/MyWorkTest.php`

## Acceptance criteria

- [x] A technician sees only their own database notifications. Read and read-all change only their own rows; another user's notification id is 404.
- [x] A WhatsApp-lane customer event for a customer with a linked user creates exactly one database row, with type and ids only. A customer without `user_id` gets no row. The customer sees it through `GET notifications`, and `subject` points at the invoice or ticket.
- [x] The web dashboard and the notification pages still render (`tests/Feature/Admin/DashboardPersonaTest.php` and the notification tests stay green).
- [x] `GET media/{id}` streams a ticket attachment to its owning customer and an SPK evidence file to a technician allowed by `WorkOrderPolicy::view`. It is 404 for another company, another customer's ticket and a missing file. Headers match the web (`Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`).
- [x] The profile and subscription JSON contain exactly the spec keys. A recursive key check finds none of `tax_id`, `notes`, `area_coverage_id`, `ont_asset_id`, `serving_pop_id`, the WhatsApp consent fields or any network-account field. Another customer's subscription id is 404.
- [x] `technician/my-work` returns the same items in the same order as `admin.my-work.index` for the same filters (asserted side by side), with `meta.summary`. A customer token gets 403. A technician without an active employee profile gets 403 `app_not_allowed`.
- [x] Pint is clean and Larastan is clean on changed files.

## Comments
2026-10-12: Verified. Shared, ProfileAndSubscriptions, MyWork, SPK WorkOrderWhatsApp (29 pass); DashboardPersonaTest + NotificationCenterTest + tests/Feature/Api 267 pass, 1 fail in Customer/PaymentsTest (ticket 03 query-count, not this ticket). Pint and Larastan clean. SPK test already expects the Q18 channel list.
