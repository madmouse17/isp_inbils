# BACKEND: en_route_at, submitted_at, en-route endpoint, site fix

Status: done
Role: backend
Blocked by: ui-consistency merged (spec Order)
Spec: `../spec.md`

## Scope (owned files)

- Migration: `work_orders.en_route_at`, `work_orders.submitted_at` (nullable datetime).
- `Modules/SPK`: `SpkService::markEnRoute` + `submit` sets `submitted_at` once; technician API route + controller action + request (F1); `TechnicianWorkOrderResource` adds `steps` (F2 shape: `[{key, label, at}]`, `current`), `site` from the installation address first and `pop` separately (F4), `customer.phone_e164` for the WhatsApp link; `FieldCheckIn::site()` argument order fixed for subscription SPKs.
- Web SPK show props: `steps` for ticket 03; Dispatch query exposes `en_route_at`.
- Customer notification `TechnicianEnRouteNotification` (database channel only, F6).
- Tests per spec (backend items).

## Acceptance criteria

- [x] Focused PHPUnit green; Pint; OpenAPI regenerated.

## Comments

### 2026-10-16 FieldTripBackend: done

**What changed.** Migration `Modules/SPK/database/migrations/2026_10_20_100000_add_trip_timestamps_to_work_orders_table.php` (`en_route_at`, `submitted_at`: nullable `timestamp` like `started_at`, reversible, no backfill: an SPK that was already past those moments keeps them null, so a reached step with a null time is normal). Support classes `Modules/SPK/app/Support/TripSteps.php` (the four steps, one place) and `WorkOrderSite.php` (where to go). `SpkService::markEnRoute`; `submitForReview` sets `submitted_at ??= now()`; `start` measures the check-in distance to `WorkOrderSite::site()`; `assign` clears `en_route_at`, `started_at` and `submitted_at` (see "Beyond the spec"). Policy `enRoute` (= `start`: assigned technician, status `assigned`, permission `spk.start`; no new permission). `EnRouteWorkOrderRequest` (`FieldCheckIn::rules()`, authorizes before validating). `TechnicianEnRouteNotification` (database only, one row per trip). `NotificationResource` words `technician_en_route`. `FieldCheckIn::site()` now also takes plain arrays.

**Technician API** (`TechnicianWorkOrderResource`, so also the answers of `start`, `submit`, `en-route`):

- `POST /api/v1/technician/work-orders/{wo}/en-route` (`api.v1.technician.work-orders.en-route`, `Idempotency-Key` like every POST). Body optional: the same `check_in` object as `start`. 200 = the SPK resource, status still `assigned`. 403 for anyone but the assigned technician and for any status but `assigned`; 404 for another company; 422 for a bad fix. Asking twice is 200 and changes nothing (no second timestamp, activity or notification). The fix is kept as activity `en_route` (properties as the `check_in` record: `status`, `lat`, `lng`, `accuracy_m`, `captured_at`, `distance_m` / `reason`), never as `check_in`; the transition itself is always logged as `en_route`.
- `steps`: always four `{key, label, at}` in this order: `assigned` "Ditugaskan", `en_route` "Dalam perjalanan", `in_progress` "Sedang dikerjakan", `installed` "Selesai dipasang"; `at` is ISO-8601 UTC with microseconds (`2026-10-16T01:30:00.000000Z`) or null. `[]` for a cancelled SPK.
- `current`: key of the last step reached, or null (draft, generated, cancelled). **Rule for every client:** steps up to and including `current` are reached; only they carry `at`; a reached step can have `at: null` (the technician never pressed "Berangkat", or a legacy SPK). `installed` is terminal (all four reached; it is `waiting_review` and `completed`). `rejected` shows `current: in_progress` with `installed.at: null` (the app words "Perlu perbaikan" from `status`). An `assigned` SPK after a rework shows no time of the earlier visit.
- Step times: `assigned` = `assigned_at` of the active assignment, `en_route` = `en_route_at`, `in_progress` = `started_at` (the top-level `started_at` is the same value: it is null again after a rework or a new technician until the restart), `installed` = `submitted_at`.
- `actions.en_route` is true while the SPK is `assigned`, the caller may go, and `en_route_at` is still null (then `actions.start` is the next one).
- `customer.phone_e164`: `+62…` or null when the stored phone is not an Indonesian mobile (landline, foreign, garbage; the same normaliser as WhatsApp). The WhatsApp link is `https://wa.me/` + `phone_e164` without the `+`; `customer.phone` stays as stored for "Telepon".
- `site`: `{label, address, lat, lng}` = the customer's installation address for an SPK with a subscription (`address` = street + city; `lat`/`lng` null when the address has no coordinates: Maps then searches the text), else the SPK's `location`. `pop`: the same shape for the POP (`location`) when `site` is the installation address, else null. The distance of the `start` check-in (and of the en-route fix) is measured to `site`; an address without coordinates gives `distance_m: null`, never the POP's distance.
- `type_label`: the SPK type in Indonesian next to the raw `type` (Instalasi, Perawatan, Peningkatan layanan, Pindah lokasi; a type nobody named keeps its key). Show the label, never `type`; the customer app already gets `type: {key, label}` from the same list (`WorkOrder::TYPE_LABELS`).

**Web props contract for ticket 03.**

- `GET /admin/spk/{wo}` (`Admin/SPK/Show`) gets two new top-level props: `steps`: `Array<{ key: 'assigned'|'en_route'|'in_progress'|'installed'; label: string; at: string | null }>` and `currentStep`: `'assigned'|'en_route'|'in_progress'|'installed'|null`. Same rule as above (reached = up to and including `currentStep`; `at` only on reached steps; `[]` when `workOrder.status === 'cancelled'`: show "Dibatalkan" from the status; `generated`/`draft`: four steps, `currentStep: null`; `rejected`: `currentStep: 'in_progress'`, show the "Perlu perbaikan" badge from `workOrder.status`). The prop needs no extra query: `assignments` is already loaded there.
- Dispatch (`Admin/Dispatch/Index`, `items.data[]` with `domain: 'spk'`): new `en_route_at: string | null` (ISO-8601 with microseconds, like `submitted_at`; note the row's `submitted_at` means "created", unrelated to the new `work_orders.submitted_at`). Badge "Dalam perjalanan" when `status === 'assigned' && en_route_at !== null`. Tickets rows have no such key.
- Nothing else of the web `WorkOrderResource` changed.

**Beyond the spec, on purpose.**

- `assign` clears `en_route_at`, `started_at` and `submitted_at` when the technician changes or the SPK was `rejected` (Main's decisions, 2026-10-16, the second after device QA): the trip, the start and the finish belong to one technician's one run of the work, so the customer is never told about a trip somebody else made and a reworked visit shows nothing of its earlier run above a stepper that begins at "Ditugaskan" again; the earlier start and finish stay in the `started` and `submitted_for_review` activities. Pinned by `WorkOrderEnRouteResetTest` (a new technician, a rework by the same technician, a rejected SPK given to another technician; the same technician again while `assigned` keeps the trip) and `WorkOrderTripTest::test_a_reworked_visit_starts_every_step_over_and_finishes_after_it_restarted` (real flow: berangkat, mulai, selesai, reject, reassign, mulai, selesai).
- Customer notification goes to the customer of the SPK's **subscription** and only when that customer has a login (`user_id`), the same rule as the WhatsApp database copy; an SPK without a subscription notifies nobody (the app shows subscription visits only, F5, ticket 02). It is written inside the same transaction as the timestamp, so a rollback sends nothing. One en-route event makes one row: `TechnicianEnRouteNotification` uses a deterministic UUIDv5 of `work_order_id + en_route_at` (seconds), and the sender finds that id before insert; the same event adds nothing, a later trip has another id. Data: `{type: technician_en_route, work_order_id, company_id, technician_first_name}`; `GET /api/v1/notifications` presents it as title "Teknisi dalam perjalanan", message "Teknisi {nama depan} sedang menuju lokasi Anda.", `subject: {type: 'work_order', id}`; first name only, no phone.

**Known limits.**

- `Modules/Ticketing/.../Technician/TicketController.php:39` still calls `FieldCheckIn::site($started->location, $started->subscription?->installationAddress)` (location first); not touched: a ticket check-in has the same defect.
- Not done here (not owned): CHANGELOG (`migrate --force` for the two columns), `docs/business/spk.md`, demo seeders that create completed SPKs without `en_route_at`/`submitted_at`, user guides (ticket 06).

**Evidence** (all through `flock /tmp/inbils-e2e.lock env DOCKER_CONTEXT=default ./vendor/bin/sail exec -T -u sail laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact <paths>`).

- Red, tests first: `tests/Unit/SPK tests/Unit/Support/FieldCheckInTest.php tests/Feature/Api/V1/Technician/WorkOrderTripTest.php tests/Feature/Api/V1/Customer/WorkOrdersTest.php tests/Feature/SPK/WorkOrderEnRouteResetTest.php tests/Feature/SPK/WorkOrderStepsPropTest.php` → `Tests: 58 failed, 29 passed (87 assertions)` (`Class "Modules\SPK\Support\TripSteps" not found`, `Unknown column 'en_route_at'` / `'submitted_at'`, `Expected response status code [200] but received 404` on the three new routes, `FieldCheckIn::site()` TypeError for an array). The Dispatch test, the matrix counts and the `NotificationResource` words were written in the same pass but their red run was not captured on its own; they first ran after the implementation.
- Green, same command → 86 passed, 1 failed because my rework simulation updated a stale model (test fixed); then `WorkOrderTripTest` + the Dispatch test → `Tests: 24 passed (117 assertions)`; `tests/Feature/Api/V1/Matrix` → `Tests: 30 passed (2336 assertions)` (counts bumped 54→57 routes, 29→31 id routes, 23→25 GET routes, 25→26 mutating routes; fixtures `customer_work_order`, `SECRET_TECHNICIAN_PHONE` planted and asserted absent from every customer GET).
- Focused: `tests/Unit/SPK tests/Unit/Support tests/Feature/SPK tests/Feature/Api/V1/Technician tests/Feature/Api/V1/Customer tests/Feature/Api/V1/Matrix tests/Feature/Api/V1/OpenApiContractTest.php tests/Feature/Api/V1/Shared/NotificationsTest.php tests/Feature/Admin/DispatchQueueTest.php tests/Feature/Admin/MyWorkTest.php tests/Feature/IpamOdnLifecycleTest.php` → `Tests: 407 passed (5210 assertions)`; neighbours `tests/Feature/P0CriticalPathTest.php TenantBoundaryTest.php Inventory/MaterialReturnWorkflowTest.php Reporting/ReportTechnicianScopeTest.php PrivateMediaAccessTest.php WhatsApp/WhatsAppPrivacyAndTenantTest.php Api/V1/Platform Api/V1/Shared` → `Tests: 203 passed (2991 assertions)`.
- `vendor/bin/pint --test` on the 32 touched files → `PASS`. `vendor/bin/phpstan analyse` on the new and changed files (not the web `WorkOrderController`, which already had 2 older errors at lines 49 and 165) → `[OK] No errors`.
- OpenAPI: `sail exec -e DB_DATABASE=inbils_test -e DB_USERNAME=inbils -e DB_PASSWORD=inbils_local_change_me laravel.test php artisan scramble:export`; structural diff against the previous file: 3 operations added (`GET /customer/work-orders`, `GET /customer/work-orders/{wo}`, `POST /technician/work-orders/{wo}/en-route`), schemas `CustomerWorkOrderResource` and `EnRouteWorkOrderRequest` added, `TechnicianWorkOrderResource` and `NotificationResource` (new title in the enum) changed, nothing removed; the export has 6 warnings, none from these files; `OpenApiContractTest` green.

### 2026-10-17 device QA follow-up

- Medium: `assign` now also clears `started_at` with `en_route_at` and `submitted_at` on a new technician/rework; right after reassign `started_at` and `steps.in_progress.at` are both null, while the old start stays in the `started` activity. Dev still has one pre-fix row needing an owner-approved data correction: `work_orders.id=61`, `SPK-2026-00049`, `status=assigned`, stale `started_at=2026-10-16 16:07:28` before its current `en_route_at=2026-10-16 16:24:09`; this agent did not mutate dev.
- Low: technician detail adds `type_label` beside raw `type`; both apps now read one `WorkOrder::TYPE_LABELS` source (Instalasi, Perawatan, Peningkatan layanan, Pindah lokasi; unknown keeps key), and OpenAPI requires the new string field.
- Low: en-route database notification id is deterministic per `work_order_id + en_route_at`; feature coverage proves replaying the same event at the same second keeps one row and a later trip adds another, while unit coverage proves stable/distinct valid UUIDs.
- TDD: new/changed tests red → `15 failed, 26 passed (140 assertions)` (stale `started_at`, missing `type_label`/`typeLabel()`, no deterministic id/dedupe), then focused new/changed files → `72 passed (333 assertions)`; customer API directory → `78 passed (782 assertions)`; matrix → `30 passed` (the first combined run had only peer receipt/device-token drift); OpenAPI regenerated after those routes stabilized and `OpenApiContractTest` → `2 passed (125 assertions)`; Pint 11 files PASS; PHPStan on SPK service/model/notification/resources/support/controllers → `[OK] No errors` after fixing a root-owned `/tmp/phpstan` cache.
