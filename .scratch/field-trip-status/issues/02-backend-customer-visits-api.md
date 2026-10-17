# BACKEND: customer work-order endpoints

Status: done
Role: backend
Blocked by: 01
Spec: `../spec.md`

## Scope (owned files)

`routes/api.php` customer group (or the SPK module's api routes, matching current placement), `CustomerWorkOrderController` + allow-list resource (F5), tests (own only, foreign 404, no technician phone, steps), OpenAPI.

## Acceptance criteria

- [x] Focused PHPUnit (Api/V1/Customer + matrix) green.

## Comments

### 2026-10-16 FieldTripBackend: done

**Placement.** The SPK module's own `Modules/SPK/routes/api.php` (customer group), as Billing, Ticketing and Service keep theirs: `Modules\SPK\Http\Controllers\Api\V1\Customer\WorkOrderController` (aliased `CustomerWorkOrderController` in the routes) and `Modules/SPK/app/Http/Resources/Api/V1/CustomerWorkOrderResource.php`. Nothing was added to `routes/api.php`. Both routes sit in `api.customer` (token ability `customer`, `EnsureApiAppAccess` puts the customer on the request); no other throttle than the default `api`.

**Contract for ticket 05 (customer app).**

- `GET /api/v1/customer/work-orders?page=&per_page=` (`api.v1.customer.work-orders.index`, same `PageRequest` as the other lists: `per_page` 1-50, default 20; `{data, links, meta}`), newest first (by id; the app picks the "next visit" itself: among `state === 'open'`, the nearest `scheduled_date`, nulls last).
- `GET /api/v1/customer/work-orders/{wo}` (`api.v1.customer.work-orders.show`) → `{data: …}`, the same object as a list item. 404 for anything that is not the caller's, also a non-numeric id.
- Item (exactly these keys, in this order; a test pins them):

```json
{
  "id": 12,
  "code": "SPK-2026-00012",
  "type": {"key": "installation", "label": "Instalasi"},
  "scheduled_date": "2026-10-20",
  "state": "open",
  "steps": [
    {"key": "assigned", "label": "Ditugaskan", "at": "2026-10-16T01:00:00.000000Z"},
    {"key": "en_route", "label": "Dalam perjalanan", "at": "2026-10-16T01:30:00.000000Z"},
    {"key": "in_progress", "label": "Sedang dikerjakan", "at": null},
    {"key": "installed", "label": "Selesai dipasang", "at": null}
  ],
  "current": "en_route",
  "technician": {"first_name": "Andi"},
  "installation_address": {"label": "Rumah", "address": "Jl. Mawar 1", "city": "Jakarta"}
}
```

- `type.label`: Instalasi, Perawatan, Peningkatan layanan, Pindah lokasi (the web's `WORK_ORDER_TYPE` words); an unknown type shows its key.
- `state`: `open` while the visit is under way (assigned, in progress, and also while the office sends work back), `done` once the technician has finished (`waiting_review` and `completed`; the office review is not the customer's concern), `cancelled`.
- `steps` / `current`: exactly as in ticket 01 (four steps; `current` = last step reached or null; steps up to and including `current` are reached and only they carry `at`; a reached step may have `at: null`; `installed` is terminal). A cancelled visit has `steps: []`, `current: null`, `state: 'cancelled'`: show "Dibatalkan". Work the office sent back shows `state: 'open'`, `current: 'in_progress'`, `installed.at: null`, with no reason.
- `technician`: the first name only (`{first_name}`); null when no technician is set. Never a phone, surname, id or email; a planted technician phone is asserted absent from every customer GET (`SecretKeyWalkTest`) and from both endpoints (`WorkOrdersTest`).
- `installation_address`: the subscription's installation address (`label`, street, city) or null.
- Not in the response by design: the raw `status`, `title`, `description`, `priority`, `result`, `rejection_reason`, `assigned_to`, `created_by`, `customer_id`, `subscription_id`, `location`/POP, coordinates, items, evidence, check-in (a test lists them).

**Who sees what.**

- Only SPKs of the caller's own subscriptions (`subscription_id` in the customer's subscriptions, soft-deleted ones excluded, company scope). A peer customer of the same company, a customer of another company and an SPK without a subscription are 404 on the detail and absent from the list. An SPK that has the customer's `customer_id` but no subscription (a maintenance SPK made without one) is therefore **not** shown (F5 says own subscriptions only); the existing WhatsApp `spk_scheduled_customer` database copy still points at such an SPK (`subject.type = work_order`), so the app must show a calm "kunjungan tidak ditemukan" on a 404 after a notification tap. Widening it is one `orWhere('customer_id', …)` in `WorkOrderController::visitsOf` if the owner wants it.
- Only from the assignment on: `assigned`, `in_progress`, `waiting_review`, `rejected`, `completed`, `cancelled`. `draft` and `generated` (waiting for a technician) are the ISP's own business and never listed or opened.
- The list holds the visits still under way and the closed ones (`completed`, `cancelled`) updated in the last 30 days; the detail has no age limit, so an old notification still opens its visit.

**Notification deep link.** `GET /api/v1/notifications` (customer token) carries `technician_en_route` items (written by ticket 01): `{type: 'technician_en_route', title: 'Teknisi dalam perjalanan', message: 'Teknisi Andi sedang menuju lokasi Anda.', subject: {type: 'work_order', id}, read_at: null}`. The same `subject` appears on `spk_scheduled_customer`. Tap → `GET /customer/work-orders/{subject.id}`; `WorkOrderTripTest::test_the_customer_finds_the_news_in_the_app_and_it_opens_the_visit` exercises the news end to end.

**Query.** No N+1: one query for the page, one count, plus `assignee:id,name`, the active assignment, the subscription and its installation address (four eager loads for the whole page). `EXPLAIN` of the list query on the dev database (60 SPKs, 49 subscriptions): `service_subscriptions` by `service_subscriptions_customer_id_foreign` (ref, 1 row) → `work_orders` by `work_orders_subscription_id_foreign` (ref on `service_subscriptions.id`, 1 row); the tiny temporary/filesort is the `order by id desc`.

**Evidence.** `tests/Feature/Api/V1/Customer/WorkOrdersTest.php` + `OpenApiContractTest` → `Tests: 14 passed (226 assertions)` (12 customer tests: window of 30 days with 29/31-day edges, own subscriptions only, 404 matrix, exact allow-list keys, technician first name only, no technician, steps per status with times, sent-back, cancelled, old closed opens by id, pagination and `per_page=51` → 422, technician token 403 / no token 401). The matrix (`tests/Feature/Api/V1/Matrix`, 30 tests, 2336 assertions) walks the new routes: no token 401, revoked/inactive, other app's token 403, peer/stranger 404 with nothing changed, owner reads `customer/work-orders/{id}`, list of a peer and a stranger empty, secret-key walk, idempotency (the new POST). Focused run of all suites listed in ticket 01: 407 passed; Pint and PHPStan clean on the new files; OpenAPI regenerated and contract-tested (see ticket 01).
