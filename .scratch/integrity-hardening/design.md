# Design: integrity-hardening (close the races of the 2026-10-17 audit)

Status: design for review · Spec: `.scratch/integrity-hardening/spec.md` (owner decisions accepted 2026-10-17) · Evidence: `local://race-audit.md` (file:line per gap) and flow-audit F4-D06 / K6 (`docs/plans/FLOW_AUDIT_2026-10-17.md:351,904`) · Author: architect `IntegrityDesign-2` · 2026-10-18

## 0. Summary

- Scope: items 1 to 20 of the audit table, without 12 (soft-delete uniques belong to `master-lifecycle`). No new library, no new route, no schema drop. Two additive migrations.
- Three shared mechanisms, built once in wave 1 (a, b) and wave 2 (c):
  - (a) `PreventDuplicateWebSubmit`: an identical write to an `admin.*` route by the same user in the same company within 10 s is refused.
  - (b) `OptimisticLock`: every edit form sends `expected_updated_at`; the write compares it with the row it holds under `lockForUpdate`.
  - (c) Active-company header: every Inertia write carries the company the page was rendered for; `ResolveActiveCompany` refuses a mismatch.
- One refusal shape for all three, and it already exists (`bootstrap/app.php:132-146`, house assign conflicts):
  - Inertia request: redirect back, `errors.conflict = <copy>`, flash `error = <copy>` (the global toast).
  - JSON request: `409 {"message": "<copy>", "error": "conflict"}`.
  - One change: the redirect becomes 303 for PUT/PATCH/DELETE, because the company check runs before `HandleInertiaRequests` (which otherwise does that conversion).
- Lane-wide lock order (new contract, every slice follows it):
  - `Customer` → `ServiceSubscription`;
  - `WorkOrder` → `InventoryRequest` or `MaterialReturnCase` → their items or lines → `Product` → `Location` → `Stock` (the last three already in `StockService::assertProductLocation`, `StockService.php:676-682`);
  - `Invoice` → `PaymentAttempt` (existing, `PaymentAttemptService.php` class doc).
- Waves: W1 has 7 slices (mechanisms, payments, invoices, billing UI, ops, misc). W2 has 11 slices (inventory, SPK, lock adoption per module, media, tenant). W3 has 3 slices (QA, mobile repo, docs). Host limit: at most 5 agents at a time.
- Deliberate deviations from the spec wording are listed with their reason in §7 (OD-1 to OD-10).

## 1. Problem statement

The audit (`local://race-audit.md`) found 19 open gaps outside soft deletes, ranked money > stock > status > report:

- **Stock counted twice or wrong.**
  - #1: SPK approval issues material a second time when the technician app asked for it without an SPK link, when the request is handed over after the SPK closed, or when handover and approval interleave.
  - #5: an adjustment approval applies an old absolute count.
  - #6: a planned quantity that nothing reserved is released.
  - #7: SPK items change after completion; the delete takes no lock.
  - #8: web stock forms double on a double submit.
  - #14: a lock-order inversion, and no deadlock retry.
  - #15: float compares.
- **Money.**
  - #2: a gateway payment is captured but never booked when the invoice is paid or cancelled while a Snap page is open.
  - #3: a cancelled recurring invoice is issued again every night.
  - #4: a manual payment doubles on a double submit.
  - #9: one tenant's failure stops billing for all later tenants.
  - #10: a crashed scheduler keeps its locks for 24 h.
  - #17: `retry_after` is below a job timeout.
  - #20: number gaps, cache flush timing, re-sent reminders, 500 on unique races.
- **Status and data.**
  - #11: last write wins on 9 edit forms.
  - #13: terminate re-checks status outside the lock; the customer roll-up reads a stale snapshot.
  - #16: status checks on stale route-bound models; SLA deadlines ignore a priority change.
  - #18: a form saves into the company another tab switched to.
  - #19: media replace is not atomic.

Owner rule (spec): fix each red-first with a test that reproduces the interleaving. Preference order: DB constraint, then app lock, then optimistic version, then UI guard. No feature change beyond the fix.

## 2. Affected modules

| Area | Paths (existing unless NEW) | Items |
|---|---|---|
| Request pipeline | NEW `app/Http/Middleware/PreventDuplicateWebSubmit.php`, `app/Http/Middleware/Api/EnforceIdempotency.php`, `app/Http/Middleware/ResolveActiveCompany.php`, `bootstrap/app.php` | 4, 8, 18, 20e |
| Shared support | NEW `app/Support/OptimisticLock.php` | 11, 16 |
| Billing | `Modules/Billing/app/Services/{BillingService,PaymentAttemptService,MidtransGateway,OverdueSuspensionService,BillingScheduleGateway}.php`, `Modules/Billing/app/Models/PaymentAttempt.php`, `Modules/Billing/app/Console/{GenerateRecurringInvoices,CheckOverdueInvoices,SuspendOverdueSubscriptions,SendInvoiceWhatsAppReminders,ReconcileMidtransPayments}.php`, `Modules/Billing/app/Notifications/BillingScheduleFailedNotification.php`, NEW `Modules/Billing/app/Notifications/PaymentAttemptExceptionNotification.php`, NEW `Modules/Billing/app/Jobs/CancelMidtransTransactionJob.php` (new dir under existing `Modules/Billing/app/`), `Modules/Billing/app/Http/Controllers/InvoiceController.php`, `Modules/Billing/app/Http/Requests/UpdateInvoiceRequest.php`, 2 NEW migrations | 2, 3, 9, 11, 14, 16, 20a, 20c |
| Notifications | `app/Http/Resources/NotificationResource.php` | 2 |
| Inventory | `Modules/Inventory/app/Services/{StockService,InventoryRequestService,MaterialReturnService}.php`, `Modules/Inventory/app/Support/InventoryRequestSources.php`, `Modules/Inventory/app/Http/Requests/Api/V1/StoreTechnicianInventoryRequestRequest.php`, `Modules/Inventory/app/Http/Controllers/Api/V1/Technician/InventoryRequestController.php`, `Modules/Inventory/app/Http/Controllers/ProductController.php`, `Modules/Inventory/app/Http/Requests/UpdateProductRequest.php`, `Modules/Inventory/app/Http/Resources/ProductResource.php` | 1, 5, 7, 11, 14, 15 |
| SPK | `Modules/SPK/app/Actions/CompleteSpkAction.php`, `Modules/SPK/app/Services/SpkService.php`, `Modules/SPK/app/Http/Controllers/WorkOrderController.php`, `Modules/SPK/app/Http/Requests/{StoreWorkOrderItemRequest,UpdateWorkOrderRequest,StoreWorkOrderEvidenceRequest}.php` | 1, 6, 7, 11, 14, 16, 19 |
| Ticketing | `Modules/Ticketing/app/{Http/Controllers/TicketController,Services/TicketService,Http/Requests/UpdateTicketRequest}.php` | 11, 16, 19 |
| Service | `Modules/Service/Http/{Controllers/ServicePackageController,Requests/UpdateServicePackageRequest,Resources/ServicePackageResource}.php`, `Modules/Service/app/Services/{AnnouncementService,PromoBannerService,HomeFeedCache}.php`, `Modules/Service/Models/PromoBanner.php`, `Modules/Service/app/Jobs/SendAnnouncementNotifications.php` | 11, 16, 17, 19, 20b |
| Core | `app/Http/Controllers/Admin/{CustomerController,SubscriptionController,EmployeeController,CompanyController}.php`, `app/Http/Requests/Admin/{UpdateCustomerRequest,UpdateSubscriptionRequest,UpdateEmployeeRequest,UpdateCompanySettingsRequest}.php`, `app/Http/Resources/{CustomerResource,EmployeeResource}.php`, `app/Services/Core/{SubscriptionService,CustomerStatusRecalculator,CompanyService,CompanyLogoService,CompanyMembershipService}.php`, `app/Models/Core/Company.php` | 11, 13, 19, 20d |
| NetworkAsset | `Modules/NetworkAsset/app/Http/Controllers/NetworkAssetController.php`, `Modules/NetworkAsset/app/Services/{NasDeviceService,NetworkAccountService}.php` | 20a, 20d |
| Ops | `routes/console.php`, `docker/app/entrypoint.sh`, `config/queue.php` | 10, 17 |
| Frontend | `resources/js/app.tsx`, `resources/js/hooks/useToast.ts`, NEW `resources/js/Components/composite/ConflictBanner.tsx`, `resources/js/lib/statuses/payment.ts`, `resources/js/Pages/Portal/Payments/Return.tsx`, `resources/js/Pages/Admin/Billing/Receivables.tsx`, `resources/js/Pages/Admin/SPK/Show.tsx`, `resources/js/Pages/Admin/{Customers/Edit,Inventory/Products/Edit,Service/Packages/Edit,Employees/Index,Company/Settings}.tsx` | 2, 4, 6, 7, 11, 18 |
| Mobile (separate repo) | `/home/madmouse/Documents/web/inbils-mobile/technician/lib/features/materials/{new_request_screen,materials_repository}.dart`, `…/lib/features/work_orders/work_order_items.dart`, `…/lib/app/{routes,router}.dart`, `…/test/app_only_features_test.dart` | 1 |
| Tests | `tests/Traits/RunsConcurrentWorkers.php`, `tests/Traits/InteractsWithMidtrans.php`, existing tests listed per slice, NEW `tests/Feature/Integrity/*` (new dir under existing `tests/Feature/`), NEW `tests/e2e/integrity-hardening.spec.ts` | all |
| Docs | `docs/api/mobile-v1.openapi.json` (regenerated), `CHANGELOG.md`, `docs/operations/{RELIABLE_JOBS,JOBS_NOTIFICATIONS}.md`, `docs/business/{billing,inventory,spk}.md`, `docs/user-guide/{web,teknisi}.md` | all |

Not touched:
- `useInertiaForm.ts`: it has no consumer (grep: only its own definition). See OD-2.
- `HandleInertiaRequests.php`: `company.id`, the active company's public id, is already a shared prop (`HandleInertiaRequests.php:67-79`).
- Route files: their owner is `master-lifecycle`. See OD-3.
- `Invoices/Show.tsx`: the new attempt status renders through `PAYMENT_ATTEMPT_STATUS`.
- `ReceivablesQuery.php`, `InvoiceResource.php`, models other than `PaymentAttempt`, `Company` and `PromoBanner`.

## 3. Data model / migrations

Both migrations sort after the newest migration in the repo (`database/migrations/2026_10_22_200000_rewrite_notification_ids_to_public_ids.php`). They also sort after the `master-lifecycle` migrations (`2026_10_23_1000xx`) and before the `hardware-panel-ui` ones (`2026_10_24_*`).

### M1 `Modules/Billing/database/migrations/2026_10_23_120000_add_cancelled_locally_to_payment_attempts_status.php` (NEW, item 2)

- `up()`: append `cancelled_locally` to the `payment_attempts.status` enum. The enum is defined at `2026_10_12_600000_create_payment_attempts_table.php:16`. The result is `['pending', 'paid', 'failed', 'expired', 'exception', 'cancelled_locally']`, default `pending`.
  - Use a raw `ALTER TABLE … MODIFY`, as `database/migrations/2026_08_11_163004_enforce_latest_closed_values_as_enums.php:90` does.
  - Appending at the end is a metadata-only change on MySQL 8, safe on the live DB.
- `down()`:
  - `UPDATE payment_attempts SET status = 'expired' WHERE status = 'cancelled_locally'`. This forward-fix DML is needed, or the shrink would fail.
  - Then restore the old enum.
- Model: `PaymentAttempt::STATUS_CANCELLED_LOCALLY = 'cancelled_locally'`. The FE self-check `resources/js/lib/statuses.selfcheck.mjs:313-317` compares the constants with `PAYMENT_ATTEMPT_STATUS`, so both land in W1.

### M2 `Modules/Billing/database/migrations/2026_10_23_120100_add_active_recurring_period_to_invoices_table.php` (NEW, item 3)

- Column: `active_recurring_period` DATE NULL, STORED generated, with this expression:

  ```sql
  case when `type` = 'recurring' and `status` <> 'cancelled' and `deleted_at` is null then `billing_period_start` end
  ```

  - It follows the house pattern `payments.active_reference` (`2026_07_01_130002_create_payments_table.php:20,28`).
  - MySQL computes it for every existing row during the ALTER: this is the "nullable period backfilled from existing recurring invoices", and it can never drift.
  - Manual and one-time rows stay NULL.
- Index: `unique(['company_id', 'subscription_id', 'active_recurring_period'], 'invoices_active_recurring_period_unique')`. NULL never collides, so manual invoices and cancelled or deleted rows are free.
- Why not count cancelled rows in the index:
  - The nightly bug has already left (cancelled, re-issued) pairs on the dev DB.
  - A unique key over all recurring rows would fail there.
  - The app rule (cancelled counts as done, §4.8 item 3) is the stricter one; the index is the backstop against two active invoices.
- `up()` order, idempotent like `app/Support/Database/PublicIdColumn.php`:
  1. Pre-check, read only. It runs before any DDL, so a failure changes nothing:
     ```php
     $dupes = DB::table('invoices')
         ->selectRaw('company_id, subscription_id, billing_period_start, GROUP_CONCAT(number ORDER BY id) AS numbers')
         ->where('type', 'recurring')->where('status', '<>', 'cancelled')->whereNull('deleted_at')->whereNotNull('subscription_id')
         ->groupBy('company_id', 'subscription_id', 'billing_period_start')
         ->havingRaw('COUNT(*) > 1')->get();
     throw_if($dupes->isNotEmpty(), RuntimeException::class,
         'Tagihan berulang aktif ganda per periode harus dibereskan dulu: '.$dupes->pluck('numbers')->implode('; '));
     ```
  2. Add the column if missing (`Schema::hasColumn`).
  3. Add the index if missing (`Schema::hasIndex`).
- `down()`: drop the index if present, then the column.

### Live dev DB `inbils` runbook (Main, after W1 is accepted; never `migrate:fresh/refresh`, `db:wipe` or seeders)

1. Run `mysqldump` of `inbils`, like the `master-lifecycle` runbook.
2. Run the read-only pre-check through Boost `database-query`. The agent shell cannot reach `mysql`; if Boost fails the same way, use `./vendor/bin/sail mysql inbils -e "<query>"`:
   ```sql
   SELECT company_id, subscription_id, billing_period_start, COUNT(*) n, GROUP_CONCAT(number ORDER BY id) numbers
   FROM invoices WHERE type = 'recurring' AND status <> 'cancelled' AND deleted_at IS NULL AND subscription_id IS NOT NULL
   GROUP BY company_id, subscription_id, billing_period_start HAVING n > 1;
   ```
   Expected: 0 rows. Any row means STOP and report the invoice numbers to the owner (data decision, never auto-fixed).
3. Run `./vendor/bin/sail artisan migrate --pretend` and paste the output, then `./vendor/bin/sail artisan migrate`.
4. Post-checks, each must return 0:
   ```sql
   SELECT COUNT(*) FROM invoices WHERE type = 'recurring' AND status <> 'cancelled' AND deleted_at IS NULL AND active_recurring_period IS NULL;
   SELECT COUNT(*) FROM payment_attempts WHERE status NOT IN ('pending','paid','failed','expired','exception','cancelled_locally');
   ```

### No migration for item 6

`work_order_items.quantity_reserved` is `decimal(15,2) default 0` (`Modules/SPK/database/migrations/2026_07_01_120001_create_work_order_items_table.php:16`). Once no code writes it, inserts get 0 and the column stays for a later drop. See OD-6.

## 4. API and change contracts

### 4.1 Mechanism (a): `App\Http\Middleware\PreventDuplicateWebSubmit` (NEW)

```php
final class PreventDuplicateWebSubmit
{
    public const SECONDS = 10;
    public const MESSAGE = 'Permintaan yang sama baru saja dikirim.';

    /** Admin writes that may repeat with an identical body inside the window. Route files belong to other lanes, so the list lives here (precedent: VerifyCsrfToken::$except). */
    private const EXCEPT = [
        'admin.company.switch',                        // switching back and forth is intended (company check is exempt too)
        'admin.notifications.read', 'admin.notifications.read-all', // idempotent
        'admin.invoices.generate-preview',             // a read sent as POST (GenerateDialog.tsx:54, axios)
        'admin.subscriptions.network-account.reveal',  // a read sent as POST, audited by the controller
        'admin.company.integrations.test',             // tenant-integrations "Uji koneksi", own throttle (agreed with IntegrationsDesign-3)
    ];

    public function handle(Request $request, Closure $next): Response;
}
```

- **Applies when:**
  - the request is authenticated (`$request->user()`);
  - the method is not safe (`! $request->isMethodSafe()`);
  - the route name matches `admin.*`. Every admin page route is named `admin.`, `admin.ipam.` or `admin.odn.` (`routes/admin.php:33-36`, module `routes/web.php`);
  - the route is not in `EXCEPT`.
- **Key:** `web.dup.{user id}.{CompanyService::currentId()}.{fingerprint}`.
  - The fingerprint is `EnforceIdempotency::fingerprint($request)`. It is today's private method (`EnforceIdempotency.php:111-124`) made `public static`; the call at `:48` becomes `self::fingerprint($request)`.
  - It hashes the method, path, query and body. A multipart body is hashed by its fields plus the SHA-256 of each file, so a re-upload of the same photo matches while a new boundary does not break the match.
  - The spec's `sha1(method+path+body)` is a subset of this.
- **Claim:** `Cache::add($key, true, self::SECONDS)`. This is atomic on the array, redis and database stores, and is the same primitive as `EnforceIdempotency.php:51`.
  - If the add fails, `throw new ConflictHttpException(self::MESSAGE)`.
- **Release:** after `$next`, call `Cache::forget($key)` when `$response->getStatusCode() >= 400` or `$request->session()->has('errors')`. A refused attempt (validation, 409, 422 abort) did not happen, so an identical retry must reach the controller.
- **Registration:** `bootstrap/app.php:70-78`, in `$middleware->web(append: [...])` right after `HandleInertiaRequests::class`. It runs after `ResolveActiveCompany`, so a request refused for its company never claims a key.
- **Toast:**
  - The 409 renderer flashes `error`.
  - `AdminLayout` already mounts `useToast()` (`AdminLayout.tsx:39`), which shows `flash.error` (`useToast.ts:10-14`). No per-form code.
  - Change in `useToast.ts:16-20`: skip the generic "Data gagal disimpan. Periksa input." toast when `conflict` is the only error key, so a 409 shows one toast and not two.
- **Exemptions:**
  - GET/HEAD/OPTIONS;
  - non-`admin.*` routes (portal, platform, auth, `logout`, API, webhooks);
  - the `EXCEPT` names.
- **Covers:** items 4 (payments), 8 (`admin.stocks.receive|issue|transfer`, `Modules/Inventory/routes/web.php:45-47`) and 20(e) (draft invoice, SPK, ticket, IR, ticket comment creates).

### 4.2 Mechanism (b): `App\Support\OptimisticLock` (NEW)

```php
final class OptimisticLock
{
    public const FIELD = 'expected_updated_at';
    public const FORMAT = 'Y-m-d\TH:i:s.v\Z';             // house format: SpkService.php:660, WorkOrderResource.php:43
    public const MESSAGE = 'Data diubah pengguna lain; muat ulang.';

    /** @return list<string> FormRequest rule for FIELD */
    public static function rules(): array;               // ['required', 'string', 'date_format:'.self::FORMAT]

    /** The version a resource emits: updated_at in UTC, milliseconds. */
    public static function version(Model $model): ?string;

    /** Call with the row read under lockForUpdate, before writing it. Throws ConflictHttpException(MESSAGE) on mismatch. */
    // shortcut: timestamps() keep whole seconds, so two writes in one second share a version; add a lock_version
    // column if a machine writer ever edits these rows within a second of a person.
    public static function assert(Model $locked, string $expected): void;
}
```

Adoption rules:
- Each `Update*Request` adds `OptimisticLock::FIELD => OptimisticLock::rules()`.
- The write passes `$request->safe()->except(OptimisticLock::FIELD)`, so the field never reaches mass assignment.
- The lock and the compare sit inside one transaction, before the write.

The 9 forms:

| Form | UI that sends the field | Request | Lock + assert | Version emitted by |
|---|---|---|---|---|
| Customer | `Customers/Edit.tsx:81-93` (init), `:127-133` (submit) | `UpdateCustomerRequest` | `CustomerController::update` (`:199-230`): its existing `DB::transaction` starts with `Customer::query()->lockForUpdate()->findOrFail($customer->id)` + assert; it writes the locked row | `CustomerResource` NEW `updated_at` |
| Subscription | none (no edit page; tests only) | `UpdateSubscriptionRequest` | `SubscriptionController::update` (`:105-136`): its existing transaction locks the subscription, asserts, and computes `$packageChanged`/`$addressChanged` from the locked row | (no UI) |
| SPK | none (`Admin/SPK/Edit.tsx` missing, R-1) | `UpdateWorkOrderRequest` | NEW `SpkService::update()` under `lockedWorkOrder` | `WorkOrderResource:43` (exists) |
| Ticket | none (`Admin/Tickets/Edit.tsx` missing, R-1) | `UpdateTicketRequest` | NEW `TicketService::update()` under `lockedTicket` | `TicketResource:49` (exists) |
| Draft invoice | none (`Admin/Billing/Invoices/Edit.tsx` missing, R-1) | `UpdateInvoiceRequest` | NEW `BillingService::update()` under the invoice lock | (no UI) |
| Product | `Inventory/Products/Edit.tsx:27-37`, `:53-58` (`router.put`) | `UpdateProductRequest` | `ProductController::update` (`:95-101`): new transaction + lock + assert | `ProductResource` NEW `updated_at` |
| Employee | `Employees/Index.tsx` modal: `openEdit` `:158-173`, submit `:174-189` | `UpdateEmployeeRequest` | `EmployeeController::update` (`:94-100`): new transaction + lock + assert | `EmployeeResource` NEW `updated_at` |
| Service package | `Service/Packages/Edit.tsx:58-70`, `:84-87` | `UpdateServicePackageRequest` | `ServicePackageController::update` (`:88-94`): new transaction + lock + assert. The `master-lifecycle` versioning relies on this lock (its design §4.5 and §8.4) | `ServicePackageResource` NEW `updated_at` |
| Company settings | `Company/Settings.tsx:172-174`, `:192-197` | `UpdateCompanySettingsRequest` | `CompanyService::updateSettings(array $settings, ?string $expectedUpdatedAt = null)` (`:218-226`): transaction, `Company::lockForUpdate()`, assert when a version is given, then merge into the locked row's settings (fixes the in-memory `array_merge`). Callers without a version (tests, seeders) skip the compare | `CompanyController::editSettings` NEW prop `version` |

409 payload:
- JSON: `409 {"message": "Data diubah pengguna lain; muat ulang.", "error": "conflict"}`.
- Inertia: redirect back, `errors.conflict` and flash `error` with the same text.

UI: the reload banner `<ConflictBanner/>` (§5.2) sits on the 5 forms that exist.

### 4.3 Mechanism (c): active-company header

- **Shared prop:** `company.id`, already shared (the active company's public id, `HandleInertiaRequests.php:68`). No new prop.
- **"Hidden field":**
  - `resources/js/app.tsx` passes `defaults.visitOptions` to `createInertiaApp`. This is the documented global visit hook, `@inertiajs/core` 2.3.27 `index.esm.js:2763-2786`.
  - Every non-GET visit gets the header `X-Inbils-Company: <company.id of the rendered page>`.
  - The id is refreshed from `router.on('navigate')` and `router.on('success')`, and seeded from `setup({ props })`. `navigate` covers history pages; `success` covers same-URL redirects, which do not fire `navigate` (`index.esm.js:1017-1019`).
  - One change reaches all ~140 `useForm` callers and every `router.post/put/patch/delete` (OD-1).
- **Check:** in `ResolveActiveCompany::handle` (`ResolveActiveCompany.php:21-50`), after the session healing and before `$next`:
  ```php
  public const HEADER = 'X-Inbils-Company';
  public const MOVED_MESSAGE = 'Perusahaan aktif berubah di tab lain.';
  private const TOKEN_EXEMPT = ['logout', 'admin.company.switch'];

  $claimed = $request->header(self::HEADER);
  if ($user !== null && is_string($claimed) && ! $request->isMethodSafe() && ! $request->routeIs(...self::TOKEN_EXEMPT)
      && $claimed !== CompanyService::current()?->public_id) {
      throw new ConflictHttpException(self::MOVED_MESSAGE);
  }
  ```
  - A request without the header passes: classic posts, tests, and the two axios writes (`ImpersonationBanner.tsx:73` is a platform route; `GenerateDialog.tsx:54` is a read). `shortcut:` noted in OD-1.
- **Copy:** "Perusahaan aktif berubah di tab lain." in the toast. The redirect re-renders the page in the active company, so the user sees the switch.

### 4.4 409 rendering (`bootstrap/app.php:132-146`)

The only change is `return back()->withErrors(...)` → `return back(in_array($request->method(), ['PUT', 'PATCH', 'DELETE'], true) ? 303 : 302)->withErrors(...)`.

- This is the same rule `HandleInertiaRequests` applies.
- A conflict thrown by `ResolveActiveCompany`, which runs before that middleware, would otherwise answer 302 to a PUT, and the browser would repeat the PUT on the previous URL.
- POST keeps 302, so `tests/Feature/Admin/DispatchQueueTest.php:615` (`assertStatus(302)` on a POST) and `tests/Feature/Admin/MyWorkTest.php:497-594` (POSTs) stay green.

### 4.5 Technician API (item 1, additive)

`POST /api/v1/technician/inventory-requests`. Route `technician.inventory-requests.store`, `Modules/Inventory/routes/api.php:10-17`, group `api.technician`, `Idempotency-Key` required.

| Field | Type | Rule | Meaning |
|---|---|---|---|
| `work_order_id` (NEW) | string (ULID) or null, optional | `ResolvesPublicIds` maps it (`publicIdFields()` adds `'work_order_id' => WorkOrder::class`), then `['nullable', 'integer', Rule::exists('work_orders', 'id')->where('company_id', $companyId)->where('assigned_to', $this->user()->id)->whereIn('status', ['assigned', 'in_progress'])->whereNull('deleted_at')]`, with a `/** @var string\|null */` docblock for Scramble (house: same file `:28,34`) | The caller's own active SPK (the same statuses as `MyWorkQuery::spkBase`). Links the request (`source_type=work_order`, `source_id`) |

Other fields are unchanged (`StoreTechnicianInventoryRequestRequest.php:26-47`).

- Controller (`Api/V1/Technician/InventoryRequestController.php:47-52`): `work_order_id` becomes `source_type => 'work_order', source_id => id` before `InventoryRequestService::create()`. That method already stores the source (`:158-159`) and words a blank reason as "Permintaan material untuk SPK {code}".
- 422:
  - an unknown or foreign id: `PublicIdExists` (uniform);
  - a colleague's SPK, a closed SPK or the wrong status: `errors.work_order_id = ["SPK harus SPK aktif (ditugaskan atau dikerjakan) milik Anda."]`.
- 201: unchanged `TechnicianInventoryRequestResource`. Its `work_order: {id, code}` (`:21-38`) is now filled.
- OpenAPI: `docs/api/mobile-v1.openapi.json`, POST `:743-785`, schema `StoreTechnicianInventoryRequestRequest` `:6685-6735`.
  - It gains `work_order_id: string|null` through `scramble:export` (W3-DOCS).
  - Additive: an old app that omits the field still gets 201.
- Mobile contract (W3-MOBILE, separate repo):
  - `MaterialsRepository.create()` gains `String? workOrderId` → payload `'work_order_id': ?workOrderId`.
  - The idempotency hash list (`new_request_screen.dart:122-129`) includes it.
  - The form gets an SPK picker. It defaults to the SPK it was opened from, else the active SPK `nextVisit(...)` (`technician/lib/features/home/next_visit.dart`), with the option "Tanpa SPK".
  - A "Minta material" button in `MaterialItems` (`work_order_items.dart:19`, shown at `work_order_detail_screen.dart:195`) opens the form for an `assigned` or `in_progress` SPK. This is UX recommendation F4-U06.
  - The 422 text shows under the picker.

### 4.6 Online payment contracts (item 2)

- **Status** `cancelled_locally`: the page was closed by Inbils because the invoice was paid, cancelled, or its balance shrank. Label "Ditutup", tone neutral.
- **`PaymentAttemptService::closeOpenFor(Invoice $invoice, ?string $exceptOrderId = null): void`** (NEW, instance method):
  - It runs inside the caller's invoice lock and locks the invoice's `pending` attempts (`lockForUpdate`, order invoice → attempt).
  - It skips `$exceptOrderId`, the attempt being settled.
  - Each closed attempt goes through `record()` with status `cancelled_locally` and reason "Tagihan dibayar atau dibatalkan saat halaman pembayaran masih terbuka.". The audit event is `cancelled_locally`.
  - Each closed attempt also dispatches `CancelMidtransTransactionJob::dispatch($companyId, $attemptId)->afterCommit()`.
  - Callers:
    - `BillingService::recordPayment` (`:211-257`), after the payment is created: `app(PaymentAttemptService::class)->closeOpenFor($invoice, $method === 'midtrans' ? $reference : null)`;
    - `BillingService::cancel` (`:259-286`): `closeOpenFor($invoice)`.
- **`MidtransGateway::cancelTransaction(PaymentAttempt $attempt): void`** (NEW):
  - Calls `POST {api host}/v2/{order_id}/cancel` through the existing `client()` and `url('api', …)` (`MidtransGateway.php:162-182`), so the tenant-integrations config swap reaches it.
  - HTTP 404, or a body `status_code` of 404/412 (no transaction, or already settled, expired or cancelled), returns quietly.
  - A transport error or any other non-2xx throws `MidtransGatewayException`.
  - Reference: Midtrans docs, "Cancel Transaction".
- **`Modules\Billing\Jobs\CancelMidtransTransactionJob`** (NEW, `ShouldQueue`; pattern `Modules/NetworkAsset/app/Jobs/ApplyNetworkAccountJob.php`):
  - Constructor `(int $companyId, int $attemptId)`; ids only.
  - `$tries = 3`, `$backoff = [10, 60, 300]`, `$timeout = 30`.
  - `handle()` runs inside `CompanyService::runAs`. It does nothing unless the attempt is still `cancelled_locally`, then calls `cancelTransaction()`.
  - `failed()` logs `midtrans.cancel_failed` with the order id only.
  - It keeps the HTTP call out of the web request (hard rule 13).
- **`applyNotification`** (`:100-125`) for an attempt in `cancelled_locally`:
  - PAID → `settle()` as today: booked if the invoice can still take it, else `exception`.
  - REVERSED → `exception`.
  - FAILED, EXPIRED, PENDING, CHALLENGE → record the payload but keep `cancelled_locally`. PENDING also re-dispatches the cancel job, because the customer opened a VA after the first cancel found no transaction.
  - For other statuses the behaviour is unchanged.
- **Staff alert:**
  - In `record()` (`:237-272`), where `midtrans.attempt_exception` is logged on a change to `exception`, add `DB::afterCommit(fn () => Notification::send($staff, new PaymentAttemptExceptionNotification($companyId, $attemptId)))`.
  - `$staff = User::where('company_id', $companyId)->where('is_active', true)->permission('billing.payment.record')->get()` (OD-5; house: `InventoryRequestService.php:558-560`).
  - Every cause of `exception` alerts: unpayable invoice, amount mismatch, reversal. Each needs a person.
- **`Modules\Billing\Notifications\PaymentAttemptExceptionNotification`** (NEW; same shape as `Modules/SPK/app/Notifications/WorkOrderApprovedNotification.php`: `ShouldQueue`, `Queueable`, ids in the constructor):
  - `via`: `['database', ...FcmChannel::for($notifiable)]`.
  - `toArray`: `{type: 'payment_attempt_exception', invoice_id: <public id>, invoice_number, order_id, amount, reason, company_id}`.
- **`NotificationResource`:**
  - Add `payment_attempt_exception` to `TYPES` (`:20-29`) and to the `@var` union (OpenAPI enum, additive).
  - `title()`: "Pembayaran online perlu dicek".
  - `message()`: "Pembayaran {order_id} sebesar Rp {amount} untuk tagihan {invoice_number} tidak bisa dibukukan otomatis. Periksa di menu Tunggakan.". The amount is formatted `number_format($amount, 0, ',', '.')`.
  - The subject resolves through `invoice_id`, as today. No detail URL.
- **Open exception (`PaymentAttemptService::openExceptions(): Builder`, NEW static):** status `exception` AND one of:
  - `payment_id` null AND no active payment uses `order_id` as its `reference` AND `last_notification->transaction_status` is null or not in (`refund`, `chargeback`). This is money captured and not booked; booking it by hand with the order id, or a full refund, closes it.
  - `payment_id` set AND that payment is not cancelled. This is booked money returned; reversing the payment closes it.

  It uses the index `payment_attempts(status, created_at)` (create migration `:32`) plus the tenant scope. Paste the `EXPLAIN`.
- **Receivables prop** (`InvoiceController::receivables`, `:254-269`): NEW `paymentExceptions: { count: int, items: list<{invoice_id: string, invoice_number: string, order_id: string, amount: string, reason: ?string, updated_at: string}> }`, newest 10, from `openExceptions()`.
- **Reconcile** (`ReconcileMidtransPayments::pendingAttempts`, `:65-72`):
  - Also revisit `cancelled_locally` attempts while `expires_at > now() - 1 day`, so a lost late webhook still lands as `exception` and an alert.
  - A `cancelled_locally` attempt that Midtrans does not know stays as it is.

### 4.7 Other server contracts

| Contract | Value |
|---|---|
| SPK item edit statuses (item 7) | `SpkService::ITEM_EDITABLE_STATUSES = ['draft', 'generated', 'assigned', 'in_progress', 'rejected']` (OD-4). Outside them: 422 "Item SPK tidak bisa diubah saat SPK menunggu review, selesai, atau dibatalkan." |
| IR handover of a closed SPK (item 1b) | 422 "SPK sumber sudah selesai atau dibatalkan; barang tidak bisa diserahterimakan." |
| Stale adjustment (item 5) | 409 "Stok berubah sejak dihitung; hitung ulang." |
| Web evidence key (item 19) | `StoreWorkOrderEvidenceRequest::idempotencyKey(): string = hash('sha256', hash_file('sha256', file) . "\n" . caption)`. Same file and caption on one SPK means one row (`SpkService::addEvidence` already dedupes by key under the WO lock, `:283-324`) |
| Scheduler lock TTL (item 10) | `withoutOverlapping(10)` every minute and every 15 minutes; `withoutOverlapping(50)` hourly; `withoutOverlapping(120)` daily |
| Queue (item 17) | `retry_after` default 180 (database `config/queue.php:43`, redis `:83`) > longest timeout 120 (`SendAnnouncementNotifications.php:29`); `SendAnnouncementNotifications::$uniqueFor = 600` |
| Billing failure alert (item 9) | `BillingScheduleFailedNotification::recipients(int $companyId): Collection` = active admins of that company: `User::where('company_id', $companyId)->where('is_active', true)->role('admin')->get()` |
| Deadlock retry (item 14) | `DB::transaction($cb, 3)` (house `IpamLock.php:25`) on every transaction in `StockService`, `InventoryRequestService`, `MaterialReturnService`, `SpkService` (not `addEvidence`: it writes a file), `CompleteSpkAction`, `BillingService`, and `PaymentAttemptService::applyNotification/markExpired` (not `openFor`: it calls Snap inside) |

### 4.8 Per-item changes

Legend for the tests:
- **Lock probe**: `waitsForRowLock()` (§4.9). A second connection holds `SELECT … FOR UPDATE` on one row; the code under test runs with `innodb_lock_wait_timeout = 1`. A 1205 proves it reads that row under lock.
- **Race**: `RunsConcurrentWorkers::race()`, two PHP processes on the committed fixture. Ordering is set with `usleep` inside the scenario.
- **Replay**: the same HTTP request twice in one test.

Every red-first test fails on today's code for the stated reason.

---

**Item 1: SPK approval issues the same material twice (P1).** Slices W2-INVENTORY, W2-SPK, W3-MOBILE, W3-QA, W3-DOCS.

- **Files:**
  - `StoreTechnicianInventoryRequestRequest.php`, `Api/V1/Technician/InventoryRequestController.php`;
  - `InventoryRequestService.php` (`issue` `:417-485`);
  - `InventoryRequestSources.php` (`workOrderBase` `:24-36`);
  - `CompleteSpkAction.php` (`consumeStock` `:164-242`);
  - the OpenAPI file and the mobile files of §4.5.
- **Change:**
  - **(a) Link.** API `work_order_id` (§4.5).
  - **(b) Closed SPK.** `InventoryRequestService::issue`:
    - First read the request's `source_type/source_id` with a plain read; the source never changes.
    - When it is a work order, `WorkOrder::withoutCompany()->where('company_id', …)->lockForUpdate()->find($id)` and refuse with 422 (§4.7) when it is missing, `completed` or `cancelled`.
    - Only then the existing header lock (`:422`). The lock order is now WorkOrder → IR → items → stock.
    - `InventoryRequestSources::workOrderBase` adds `whereNotIn('status', ['completed', 'cancelled'])`. This closes the web source picker (`:66`) and `StoreInventoryRequestRequest.php:56` too.
  - **(c) Read under lock + K6.** `consumeStock`:
    - Read coverage with two locking reads: the issued IR headers of this WO (`lockForUpdate()->pluck('warehouse_location_id', 'id')`), then their items (`lockForUpdate()`, columns `inventory_request_id, product_id, source_location_id, quantity_issued`).
    - Sum per product with `Money::add`. Each product remembers the source location of its last covering item (`source_location_id ?? warehouse_location_id`).
    - Covered quantity is never issued again (as today).
    - The uncovered rest of an IR-backed product is issued from that IR location, not `$workOrder->location_id`. This is K6: "konsumsi SPK memakai gudang asal permintaan". Products without coverage keep the SPK location.
    - Min/max is done with `Money::compare/sub`.
    - The reserved-release block (`:184-204`) is removed (item 6).
- **Red-first tests:**
  - `InventoryRequestWritesTest::test_a_request_for_the_technicians_active_spk_is_linked_to_it`. Replay of the API POST with `work_order_id`. Red: `source_type` is null and `work_order` is null in the 201.
  - `InventoryRequestWritesTest::test_a_request_naming_another_technicians_or_a_closed_spk_is_refused` (colleague's SPK, `completed` SPK, foreign company id → 422) and `InventoryRequestWritesTest::test_a_request_without_an_spk_still_works` (old clients).
  - `IssueAgainstClosedWorkOrderTest::test_a_request_for_a_completed_or_cancelled_spk_cannot_be_handed_over`. Red: issued, warehouse stock down.
  - `IssueAgainstClosedWorkOrderTest::test_handover_waits_for_the_work_order_lock`. Lock probe on `work_orders#wo`. This is interleaving (c): approval holds the WO. Red: no wait.
  - `SpkConsumptionCoverageTest::test_approval_reads_issued_requests_under_lock`. Lock probe on `inventory_requests#ir` around `SpkService::approve`. Red: the plain read does not wait, so an issue committing after the snapshot is missed.
  - `SpkConsumptionCoverageTest::test_usage_above_the_handed_over_quantity_is_taken_from_the_request_warehouse`. IR 10 from W, used 12, POP empty. Red: approval issues 2 at the POP → `InsufficientStockException`.
  - `SpkConsumptionCoverageTest::test_handed_over_material_is_never_issued_again_at_approval` (regression).
  - W3: `AppRequestToApprovalTest::test_material_requested_in_the_app_for_an_spk_is_deducted_once`, end to end: API request → approve → prepare → ready → issue → declare → receive → submit → approve.
- **Acceptance:**
  - One `issue` movement per handed-over quantity, at the warehouse.
  - No movement at the POP, and approval passes with an empty POP.
  - A closed SPK refuses handover.
  - OpenAPI lists `work_order_id`; the app sends it.

**Item 2: gateway money captured but not booked (P2).** Slices W1-PAY, W1-INVOICE, W1-BILLING-FE.

- **Files:** §4.6 plus `PaymentAttempt.php`, M1, `Receivables.tsx`, `payment.ts`, `Return.tsx`.
- **Change:** §4.6.
- **Red-first tests (`PaymentAttemptLocalCancelTest`):**
  - `test_recording_cash_closes_the_open_snap_page_and_queues_a_midtrans_cancel`. Replay: open attempt, POST cash payment. Red: the attempt stays `pending`, no job.
  - `test_cancelling_the_invoice_closes_its_open_pages`.
  - `test_a_partial_cash_payment_closes_the_page_whose_amount_no_longer_matches`.
  - `test_settling_one_page_closes_the_others_but_not_itself`.
  - `test_the_cash_payment_is_booked_even_when_midtrans_cannot_be_reached` (the job fails, the payment stays).
  - `test_a_late_settlement_on_a_closed_page_becomes_an_exception_and_alerts_billing_staff_of_that_company_only`. Webhook after cash. `Notification::fake()`; recipients have `billing.payment.record` in that company; the payload has `order_id` and `amount`. Red: no notification.
  - `test_a_pending_notice_keeps_a_closed_page_closed_and_cancels_it_again`.
  - `test_reconcile_revisits_a_closed_page_until_a_day_after_it_expires`.
  - `test_receivables_lists_open_exceptions_and_hides_booked_or_refunded_ones`.
  - Race `test_cash_and_online_settlement_on_one_invoice_book_once`. Worker A runs `recordPayment(cash, full)`, worker B runs `applyNotification(settlement)`, released together. Exactly one payment. The loser is either a cash 422 or an attempt `exception` plus one alert.
- **Acceptance:**
  - No attempt stays `pending` after a payment or a cancel.
  - The cancel runs from the queue, after commit.
  - Every move to `exception` alerts billing staff (database + FCM).
  - The receivables badge counts open exceptions only.

**Item 3: a cancelled recurring invoice is re-issued nightly (P2).** Slice W1-INVOICE.

- **Files:** `BillingService.php` (`generateForPeriod` `:45-167`), M2, `RecurringInvoiceRaceGuardTest.php`, `InvoiceController::generate` (`:152`).
- **Change:**
  - Delete `->where('status', '!=', 'cancelled')` from both exists-checks (`:68-73`, `:112-119`), so any recurring invoice for (subscription, period) counts as done, for the scheduled run and the manual Generate (OD-7).
  - Inside the per-subscription catch (item 9), `catch (UniqueConstraintViolationException)` counts as skipped.
  - M2 is the backstop.
- **Red-first tests:**
  - `RecurringInvoiceRaceGuardTest::test_a_cancelled_recurring_invoice_is_not_issued_again`. Replayed scheduled run: generate, cancel, generate. Red: `created = 1` again.
  - `RecurringInvoiceRaceGuardTest::test_the_database_refuses_a_second_active_recurring_invoice_for_one_period`. Raw insert → `UniqueConstraintViolationException`; one cancelled plus one active coexist; a one-time row with the same period coexists.
  - Race `RecurringInvoiceRaceGuardTest::test_two_generators_racing_create_one_invoice`. Both workers `generateForPeriod` the same company and period; created sum is 1; no worker fails.
  - `RecurringPeriodUniqueMigrationTest::test_it_stops_before_any_change_when_active_duplicates_exist` and `RecurringPeriodUniqueMigrationTest::test_it_fills_the_period_of_existing_recurring_rows` (pattern `tests/Feature/PublicId/PublicIdMigrationsTest.php:80-104`).
- **Acceptance:**
  - The second run creates 0 invoices.
  - The DB refuses two active recurring rows.
  - The migration aborts cleanly on duplicates.

**Items 4, 8, 20(e): duplicate web submit (P2).** Slices W1-MECH, W1-UI, W2-INVENTORY (stock test).

- **Files:** §4.1, `useToast.ts`.
- **Red-first tests:**
  - `PreventDuplicateWebSubmitTest::test_a_second_identical_payment_within_ten_seconds_is_refused`. Replay of `admin.invoices.payments.store` with `X-Inertia` → redirect back with `errors.conflict` + flash `error`; one payment. Red: two payments, invoice `paid` at half the cash.
  - `PreventDuplicateWebSubmitTest::test_the_same_post_after_ten_seconds_goes_through` (`travel(11)->seconds()`).
  - `PreventDuplicateWebSubmitTest::test_a_refused_attempt_frees_its_key` (422, then the identical retry gets 422 again, not 409).
  - `PreventDuplicateWebSubmitTest::test_a_json_client_gets_409_json`.
  - `PreventDuplicateWebSubmitTest::test_an_inertia_put_conflict_redirects_with_303`.
  - `PreventDuplicateWebSubmitTest::test_get_and_exempt_routes_are_never_refused`.
  - `PreventDuplicateWebSubmitTest::test_keys_are_per_user_and_per_company`.
  - `PreventDuplicateWebSubmitTest::test_the_same_file_twice_is_refused_and_another_file_passes`.
  - `PreventDuplicateWebSubmitTest::test_a_double_click_on_create_makes_one_ticket` (20e).
  - `StockFormReplayTest::test_receiving_issuing_or_transferring_twice_moves_stock_once` (item 8).
- **Acceptance:** one row per double submit; one toast "Permintaan yang sama baru saja dikirim."

**Item 5: adjustment approval applies the old count (P2).** Slice W2-INVENTORY.

- **File:** `StockService.php` (`adjust` `:183-226`, `approveAdjustment` `:271-304`).
- **Change:**
  - `adjust(..., array $evidence = [], ?string $expectedQuantity = null)`. Under `lockedStock`, `abort_if($expectedQuantity !== null && Money::compare($stock->quantity, $expectedQuantity) !== 0, 409, 'Stok berubah sejak dihitung; hitung ulang.')`.
  - `approveAdjustment` passes `(string) $pending->old_quantity`.
  - Direct adjust (`StockController::adjustDirect`) is unchanged.
- **Red-first tests:**
  - `AdjustmentApprovalStaleCountTest::test_approval_refuses_a_count_taken_before_the_stock_moved`. Request 100 → 95, issue 10, approve → 409; stock 90; request stays `pending`. Red: stock 95.
  - `AdjustmentApprovalStaleCountTest::test_approval_applies_when_the_stock_did_not_move`.
  - Race `AdjustmentApprovalStaleCountTest::test_an_issue_racing_the_approval_never_leaves_the_old_count`. Final stock is in {85, 90}, never 95.
- **Acceptance:** an approval never writes a count older than the stock it locked.

**Item 6: planned SPK quantity released though never reserved (P2).** Slices W2-SPK, W2-SPK-FE, W3-DOCS.

- **Files:** `CompleteSpkAction.php`, `SpkService.php`, `StoreWorkOrderItemRequest.php`, `SPK/Show.tsx`.
- **Change:**
  - Delete `CompleteSpkAction::releaseReservedStock` (`:52-82`) and its calls (`SpkService.php:465` reject, `:528` cancel).
  - Delete the release block in `consumeStock` (`:184-204`).
  - Drop rule `quantity_reserved` (`StoreWorkOrderItemRequest.php:51`) and `$data['quantity_reserved'] ??= 0` (`SpkService.php:44`).
  - UI: remove the "Jumlah dicadangkan" input (`SPK/Show.tsx:771-773`), the "Dicadangkan" column (`:555`) and the form state (`:68, :166, :206, :265`).
  - Kept:
    - API resources (`TechnicianWorkOrderItemResource.php:27`): no mobile change; the value is 0 for new rows;
    - `removeMaterial`'s legacy check (`SpkService.php:379`);
    - `ApprovalInboxItemResource.php:109`.
  - No migration (OD-6).
- **Red-first tests:**
  - `SpkPlannedQuantityRemovalTest::test_cancelling_or_rejecting_an_spk_with_a_legacy_planned_quantity_succeeds`. Red: `InsufficientStockException` (`StockService.php:396`).
  - `SpkPlannedQuantityRemovalTest::test_approval_leaves_another_requests_reservation_alone`. An IR reserved 5; approval → still 5. Red: 3.
  - `SpkPlannedQuantityRemovalTest::test_the_item_form_ignores_a_planned_quantity`.
- **Acceptance:** cancel and reject never fail on a planned quantity; no foreign reservation moves.

**Item 7: SPK items editable on any status; delete without a lock (P2).** Slices W2-SPK, W2-SPK-FE, W2-INVENTORY.

- **Files:** `SpkService.php`, `WorkOrderController.php` (`addItem` `:356-363`, `removeItem` `:365-374`), `MaterialReturnService.php` (`receiveAccept` `:309-445`, `resolveException` `:507-560`), `SPK/Show.tsx:223`.
- **Change:**
  - `SpkService::addItem` checks `ITEM_EDITABLE_STATUSES` under its lock.
  - NEW `SpkService::removeItem(WorkOrder $wo, WorkOrderItem $item): void`: `lockedWorkOrder`, company, status, `RecordNotFound::abortUnless($item->work_order_id === $wo->id)`, delete, audit `item_removed`. The controller calls it.
  - `MaterialReturnService`: one private `workOrderIdOf(MaterialReturnCase $case): ?int` replaces the two copies of the WO lookup (`:418-424`, `:548-554`). Both methods lock that WO before the case lock, then reconcile with the locked WO.
  - `SPK/Show.tsx:223`: `canChange` becomes `['draft','generated','assigned','in_progress','rejected'].includes(w.status)`.
- **Red-first tests:**
  - `WorkOrderItemLockTest::test_items_cannot_change_while_waiting_for_review_completed_or_cancelled`. Red: `updateOrCreate` and delete succeed.
  - `WorkOrderItemLockTest::test_removing_an_item_waits_for_the_work_order_lock`. Lock probe on `work_orders`. Red: no wait.
  - `MaterialReturnWorkOrderLockTest::test_receiving_and_resolving_a_return_wait_for_the_work_order_lock`. Lock probe. Red: no wait, so the reconcile interleaves with `addMaterial` or approval.
- **Acceptance:** a completed SPK's items match its ledger; reconcile and approval serialize.

**Item 9: one tenant's failure stops every later tenant (P2).** Slice W1-INVOICE.

- **Files:** `GenerateRecurringInvoices.php:24-61`, `CheckOverdueInvoices.php:22-45`, `SuspendOverdueSubscriptions.php:22-52`, `SendInvoiceWhatsAppReminders.php:46-49`, `BillingService::generateForPeriod`, `OverdueSuspensionService::suspendOverdue` (`:50-56`), `BillingScheduleGateway.php` (docblock), `BillingScheduleFailedNotification.php`, `InvoiceController::generate`.
- **Change:**
  - Each command catches `Throwable` per company (pattern `SendInvoiceWhatsAppReminders.php:30-45`): `report($e)`, remember the company, continue, alert `BillingScheduleFailedNotification::recipients($companyId)` with `companyId`, return `FAILURE` at the end.
  - The reminders command gets the same recipients (today it alerts every company's admins).
  - `generateForPeriod` wraps each subscription in try/catch: `report`, count in a NEW additive `failed` key.
  - The command treats `failed > 0` as a company failure.
  - The manual Generate flash adds ", {n} gagal" when `failed > 0`.
  - `suspendOverdue` catches per subscription and, after the loop, throws one `RuntimeException` listing the failed codes. This keeps its list return type for the command and tests.
  - `OverdueSuspensionService::suspendIfStillOverdue` locks the customer row before the subscription (lock order, item 13).
- **Red-first tests:**
  - `BillingScheduleIsolationTest::test_a_failing_company_does_not_stop_the_next_one`. The gateway throws for company 1; company 2 gets its invoices; FAILURE; alert only to company 1's admins. Red: company 2 skipped; every admin alerted.
  - `BillingScheduleIsolationTest::test_one_bad_subscription_does_not_stop_its_company`.
  - `BillingScheduleIsolationTest::test_check_overdue_and_suspend_continue_past_a_failing_company`.
  - `BillingScheduleIsolationTest::test_one_failing_suspension_does_not_stop_the_others`.
- **Acceptance:** one failure costs only its own row or company, and only that company's admins are alerted.

**Item 10: scheduler locks survive a crash for 24 h (P2).** Slice W1-OPS.

- **Files:** `routes/console.php:9-39`, `docker/app/entrypoint.sh`.
- **Change:**
  - `withoutOverlapping(10)` on `tickets:sla`, `midtrans:reconcile`, `network:sync`, `announcements:publish-scheduled`.
  - `withoutOverlapping(50)` on `inbils:platform:exports:prune` (OD-8).
  - `withoutOverlapping(120)` on `billing:generate`, `billing:check-overdue`, `billing:suspend-overdue`, `billing:whatsapp-reminders`, `promo-banners:cleanup-images`.
  - `entrypoint.sh`, before `exec "$@"`: `if [ "${1:-}" = "php" ] && [ "${2:-}" = "artisan" ] && [ "${3:-}" = "schedule:work" ]; then php artisan schedule:clear-cache; fi`. This matches `compose.yaml:72`.
- **Red-first tests:**
  - `SchedulerLockTest::test_every_overlap_lock_expires_before_it_can_skip_a_run`. Each event has `withoutOverlapping === true` and the TTL above. Red: 1440.
  - `SchedulerLockTest::test_the_scheduler_container_clears_stale_locks_on_boot`. File assertion on `docker/app/entrypoint.sh`.
- **Acceptance:** a crashed scheduler skips at most 10 min, 50 min or 2 h of runs.

**Item 11: lost updates on 9 forms (P2).** Slices W1-MECH, W1-UI, W1-INVOICE, W2-SPK, W2-LOCK-CUSTOMER, W2-LOCK-PEOPLE, W2-LOCK-CATALOG, W2-LOCK-TICKET, W2-LOCK-FE.

- **Files and change:** the §4.2 table.
- **Red-first tests:**
  - Replay per form, two PUTs carrying the same version: the second is refused, and the DB keeps the first's values. Red: the second overwrites.
    - `CustomerEditConflictTest::test_a_save_from_an_older_copy_is_refused_and_changes_nothing`;
    - `CustomerEditConflictTest::test_a_subscription_save_from_an_older_copy_is_refused`;
    - `SettingsEditConflictTest::test_two_sections_saved_from_one_copy_never_drop_each_others_keys`;
    - `SettingsEditConflictTest::test_an_employee_save_from_an_older_copy_is_refused`;
    - `CatalogEditConflictTest::test_product_and_package_saves_from_an_older_copy_are_refused`;
    - `WorkOrderUpdateLockTest::test_an_spk_save_from_an_older_copy_is_refused`;
    - `TicketUpdateLockTest::test_a_ticket_save_from_an_older_copy_is_refused`;
    - `InvoiceDraftLockTest::test_a_draft_save_from_an_older_copy_is_refused`.
  - Race `InvoiceDraftLockTest::test_two_saves_racing_on_one_draft_let_exactly_one_win`. Two workers call `BillingService::update` with the same version: one ok, one 409. Same race in `WorkOrderUpdateLockTest` and `TicketUpdateLockTest`.
  - `OptimisticLockTest`: version format, assert passes and throws, `rules()` refuses a non-canonical string.
- **Acceptance:** a stale form never writes; the user sees the toast and the banner with "Muat ulang".

**Item 13: terminate re-check and stale customer roll-up (P3).** Slice W2-LOCK-CUSTOMER.

- **Files:** `SubscriptionService.php` (`terminate` `:168-235`; transitions with roll-up at `:50, :72, :96, :119, :219`), `CustomerStatusRecalculator.php` (`recalculate` `:35-50`).
- **Change:**
  - `terminate` moves `abort_if(status === 'terminated')` from `:170` to after the locked read.
  - `create`, `activate`, `suspend`, `reactivate` and `terminate` lock the customer row first (`Customer::query()->whereKey($customerId)->lockForUpdate()->first()`), then the subscription. Two transitions of one customer then queue at their first statement and never wait in opposite order.
  - `recalculate` reads the statuses with `$customer->subscriptions()->lockForUpdate()->pluck('status')`, a current read: the remedy of `OverdueSuspensionService.php:121-133`.
- **Red-first tests:**
  - Race `SubscriptionTransitionRaceTest::test_a_second_terminate_racing_the_first_is_refused`. One ok, one 422 "Langganan sudah berhenti."; `terminated_reason` is the winner's; one audit row. Red: both succeed and the second overwrites.
  - `SubscriptionTransitionRaceTest::test_the_roll_up_reads_subscriptions_under_lock`. Lock probe on `service_subscriptions#s2` around `recalculate`. Red: no wait.
  - Race `SubscriptionTransitionRaceTest::test_two_subscriptions_of_one_customer_suspended_together_leave_it_suspended`. Both ok, no 1213, `customer_status = suspended`.
- **Acceptance:** one terminate wins; the roll-up matches its subscriptions after concurrent transitions.

**Item 14: lock-order inversion and no deadlock retry (P3).** Slices W2-INVENTORY, W2-SPK, W1-INVOICE, W1-PAY.

- **Files:** `InventoryRequestService::approve` (pre-lock `:291-304`), the transactions listed in §4.7.
- **Change:**
  - Remove `->lockForUpdate()` from the pre-read of the stock row. The plain read keeps today's friendly message (SKU, location, available, requested), and `StockService::reserve` re-checks under the Product → Location → Stock locks (OD-9).
  - Add `, 3` to the listed transactions.
- **Red-first test:** `InventoryLockOrderTest::test_request_approval_locks_product_and_location_before_any_stock_row`. `DB::enableQueryLog()` around `approve`; the first `products … for update` comes before the first `stocks … for update`. Red: the stock row is first.
- **Acceptance:** one lock order everywhere; `grep -n "DB::transaction(" <files>` shows `, 3)` on each listed site.

**Item 15: float stock compares (P3).** Slice W2-INVENTORY.

- **File:** `StockService.php` `:81-84, :126-129, :192-194, :356-359, :394-396, :504-507`.
- **Change:** `Money::compare(Money::sub($stock->quantity, $stock->reserved_quantity), $quantity) < 0` (scale 2, `app/Support/Money.php`). The exception messages get `(float)` values.
- **Red-first tests:**
  - `StockQuantityPrecisionTest::test_issuing_exactly_the_available_fraction_passes`. Stock 100.10, reserved 100.00, issue 0.10. Red: `InsufficientStockException`.
  - The same for transfer, reserve, release, stock movement and the adjust floor.
- **Acceptance:** boundary quantities pass; one cent over fails.

**Item 16: status checks on stale route models; SLA deadlines on a priority change (P3).** Slices W1-INVOICE, W2-SPK, W2-LOCK-TICKET, W2-MEDIA.

- **Files:** `InvoiceController::update/destroy` (`:284-304`), `WorkOrderController::update/destroy` (`:219-237`), `TicketController::update/destroy` (`:246-266`), `AnnouncementService::publish` (`:43-63`), `TicketService::computeDeadlines` (`:77-92`).
- **Change:** NEW service methods, each re-reading the row with `lockForUpdate` and checking status inside the lock, with today's messages:
  - `BillingService::update(Invoice, array, string $expected)`, which also recalculates inside the transaction, and `BillingService::destroyDraft(Invoice)`;
  - `SpkService::update(WorkOrder, array, string $expected)` (statuses `draft, generated`) and `SpkService::destroy(WorkOrder)` (`draft, cancelled`);
  - `TicketService::update(Ticket, array, string $expected)` (`open`) and `TicketService::destroy(Ticket)` (`closed`).

  The other changes:
  - `TicketService::update` recomputes `sla_deadline/response_deadline` when `priority` is dirty.
  - `computeDeadlines` counts from `$ticket->created_at ?? now()` instead of `now()` (`:81`), so creation is unchanged (`TicketDeadlinesTest` uses unsaved tickets) and a later change does not extend the target.
  - `AnnouncementService::publish` locks the row and re-checks `published_at` and `ends_at` inside the transaction.
- **Red-first tests:**
  - Race `InvoiceDraftLockTest::test_an_update_that_waited_for_send_is_refused`. Worker P locks the draft, sends it and holds 800 ms; worker U loads the draft first, starts after 200 ms and calls `BillingService::update`. 422; totals unchanged. Red: the method does not exist; the old controller path writes a discount on a sent invoice.
  - The same shape in `WorkOrderUpdateLockTest::test_an_update_that_waited_for_assignment_is_refused` and `TicketUpdateLockTest::test_an_update_that_waited_for_assignment_is_refused`.
  - `TicketUpdateLockTest::test_raising_priority_on_an_open_ticket_moves_its_deadlines_from_creation`. Red: deadlines unchanged.
  - Race `AnnouncementPublishRaceTest::test_publishing_one_draft_twice_at_once_publishes_once`. One ok, one 422 "Pengumuman sudah diterbitkan."; one `published` audit row.
- **Acceptance:** no write lands on a row whose status changed after the request bound it.

**Item 17: `retry_after` below a job timeout; unique lock without TTL (P3).** Slice W1-OPS.

- **Files:** `config/queue.php:43,83`, `SendAnnouncementNotifications.php`.
- **Change:** §4.7. `.env.example` and `.env.docker.example` do not set `DB_QUEUE_RETRY_AFTER`, so the default applies.
- **Red-first tests:**
  - `QueueRetryAfterTest::test_retry_after_outlasts_the_longest_job_timeout`. Red: 90 < 120.
  - `QueueRetryAfterTest::test_the_announcement_lock_expires`. Red: no `uniqueFor`.
- **Acceptance:** both pass.

**Item 18: a form saves into the company another tab switched to (P3).** Slices W2-TENANT-BE, W2-TENANT-FE, W1-MECH (303 rule).

- **Files:** §4.3, §4.4.
- **Red-first tests:**
  - `ActiveCompanyTokenTest::test_a_create_from_a_tab_still_on_company_a_is_refused_after_switching_to_b`. Replay: a user with memberships A and B, session on B, `POST admin.units.store` with `X-Inbils-Company: <A>` → 302 back, `errors.conflict`; no unit in B. Red: the unit is saved into B.
  - `ActiveCompanyTokenTest::test_an_update_from_a_stale_tab_redirects_with_303`.
  - `ActiveCompanyTokenTest::test_the_active_company_or_no_header_passes`.
  - `ActiveCompanyTokenTest::test_switching_company_is_never_refused`.
  - `ActiveCompanyTokenTest::test_reads_ignore_the_header`.
  - W3 E2E: two tabs.
- **Acceptance:** a stale tab never writes into another company.

**Item 19: media replace not atomic; orphan files; double upload (P3).** Slices W2-MEDIA, W2-SPK, W2-LOCK-TICKET.

- **Files:** `CompanyLogoService.php:11-19`, `Company.php:93-98`, `PromoBannerService.php` (`create` `:16-28`, `update` `:31-43`, `storeImage` `:107-117`), `PromoBanner.php:86-91`, `SpkService::addEvidence`, `TicketService::addAttachment` (`:276-306`), `StoreWorkOrderEvidenceRequest.php`, `WorkOrderController::uploadEvidence` (`:376-382`).
- **Change:**
  - Remove `->singleFile()` from the `logo` and `promo_banner` collections. Spatie's single-file add deletes the old file at once, inside the transaction.
  - Replace becomes: compute the new content first (`webpContents` before any write); in a transaction, lock the owner row (company or banner); `$old = getMedia(...)`; add the new media; `DB::afterRollBack(fn () => $new->delete())`; `DB::afterCommit(fn () => $old->each->delete())`.
  - `addEvidence` and `addAttachment` register `DB::afterRollBack(fn () => $media->delete())` after `toMediaCollection`.
  - The web evidence passes `$request->idempotencyKey()` as the 4th argument (§4.7).
  - `DocumentService` is unchanged: verified, `DocumentController::uploadMedia` calls it outside any transaction (`DocumentController.php:130`) and it never replaces.
- **Red-first tests:**
  - `MediaReplaceTest::test_a_logo_that_cannot_be_converted_keeps_the_current_logo`. Red: cleared first (`CompanyLogoService.php:13`).
  - `MediaReplaceTest::test_a_rolled_back_logo_or_banner_replace_keeps_the_old_file_and_drops_the_new_one`. `Storage::fake('public')`, throw after the store. Red: the old file is deleted.
  - `MediaReplaceTest::test_the_old_file_goes_only_after_commit`.
  - `WorkOrderEvidenceIdempotencyTest::test_the_same_file_uploaded_twice_from_the_web_is_stored_once` (`travel(11)` between, past the 10-s guard). Red: two media.
  - `WorkOrderEvidenceIdempotencyTest::test_a_rolled_back_evidence_upload_leaves_no_file`.
  - `TicketUpdateLockTest::test_a_rolled_back_attachment_upload_leaves_no_file`.
- **Acceptance:** an error never leaves no logo, two logos or an orphan file.

**Item 20: consistency (P3).** Slices W1-INVOICE (a, c), W1-MISC (a, b, d).

- **(a) Number gaps.** `InvoiceController::store` (`:159-175`) wraps number + create in `DB::transaction`. `NetworkAssetController::store` (`:172-186`) moves `NetworkAssetService::generateCode()` into the existing transaction closure (`:182`); its own inner transaction becomes a savepoint.
  - Tests: `InvoiceNumberGapTest::test_a_failed_manual_invoice_create_gives_its_number_back` and `NetworkAssetCodeGapTest::test_a_failed_asset_create_gives_its_code_back`. Red: the number is burned (`NumberSequenceService.php:30` gives it back only inside the caller's transaction).
- **(b) Cache flush timing.** `HomeFeedCache::flush` (`:17-20`) body becomes `DB::afterCommit(fn () => Cache::forever(...))`. One edit covers all 10 call sites; with no open transaction it runs at once.
  - Test: `HomeFeedCacheCommitTest::test_the_feed_version_changes_only_after_commit_and_not_on_rollback`. Red: it changes inside the transaction.
- **(c) Re-sent reminders.** `SendInvoiceWhatsAppReminders::remind` (`:80-82`) skips an invoice whose customer already has a database notification of `InvoiceDueReminderNotification` with `data->invoice_id = <public id>` and `data->stage = <stage>`. That is one indexed query per invoice (`notifications(notifiable_type, notifiable_id)`); paste the `EXPLAIN`. WhatsApp keeps its own dedupe key.
  - Test: `InvoiceWhatsAppReminderCommandTest::test_a_manual_rerun_the_same_day_does_not_ring_the_bell_or_push_twice`. Red: two bell rows.
- **(d) 500 on unique races.**
  - `NasDeviceService::create` (`:25-33`), `NetworkAccountService::create` (`:51-59`): `catch (UniqueConstraintViolationException)` around the insert. Re-run the existing pre-check: when it now finds the row, `abort(422, <today's message>)`; otherwise the clash was the other unique column (`nasname`, `username`), so `abort(422, <that FormRequest's existing message>)`.
  - `CompanyMembershipService::grant` (`:45-56`): on the catch, when the membership now exists, treat it as existing; else rethrow.
  - Test: race `UniqueRaceMessagesTest::test_two_creates_racing_on_one_unique_answer_422_not_500` (NAS device, network account, membership). Red: one worker exits with `UniqueConstraintViolationException`.
- **(e)** Mechanism (a).

### 4.9 Test infrastructure (W1-MECH, `tests/Traits/RunsConcurrentWorkers.php`)

- The worker script (`:120-143`) catches `Symfony\Component\HttpKernel\Exception\HttpExceptionInterface` and writes `{"ok": false, "status": <code>, "message": "<text>"}`. Today any `abort()` makes the worker exit 1 and fails `finishRaceWorker`.
- NEW helper:
  ```php
  /**
   * True when $operation, run on the default connection, has to wait for the row lock a second connection holds on
   * $table#$id (that connection runs SELECT … FOR UPDATE; the default one gets innodb_lock_wait_timeout 1, so a waiting
   * operation fails with 1205). Other exceptions propagate. The fixture must be committed first (commitFixture()).
   */
  protected function waitsForRowLock(string $table, int $id, Closure $operation): bool
  ```
  - The peer connection is a runtime clone, `config(['database.connections.mysql_peer' => config('database.connections.mysql')])`, purged in `finally`.
  - The timeout is reset to 50 in `finally`.
  - Every caller cleans up with `purgeCompany()` in `finally`, as `tests/Feature/Ipam/IpamConcurrencyTest.php:45-64` does.
  - With `, 3` retries one probe costs about 3 s.

## 5. UI contract

### 5.1 Global (W1-UI, W2-TENANT-FE)

- `resources/js/hooks/useToast.ts:16-20`: the generic toast fires only when an error key other than `conflict` exists.
- `resources/js/app.tsx`:
  - `createInertiaApp({ defaults: { visitOptions: (_href, options) => renderedCompanyId && options.method && options.method !== 'get' ? { headers: { ...options.headers, 'X-Inbils-Company': renderedCompanyId } } : {} }, ... })`.
  - `renderedCompanyId` is kept by `router.on('navigate')`, `router.on('success')` and seeded in `setup` from `props.initialPage.props.company?.id`.
  - A one-line comment gives the why.

### 5.2 `ConflictBanner` (NEW `resources/js/Components/composite/ConflictBanner.tsx`, W1-UI)

```tsx
/** The 409 an edit form got back (errors.conflict), with a reload, so a stale copy is never saved over a newer one. */
export function ConflictBanner(): JSX.Element | null;
```

- It reads `usePage().props.errors.conflict`; Inertia types `errors` on every page.
- When the key is absent it renders nothing.
- Otherwise it renders `<Alert variant="warning" role="alert">` (`resources/js/Components/ui/Alert.tsx`):
  - `<AlertTitle>{conflict}</AlertTitle>`;
  - `<AlertDescription>Muat ulang halaman untuk melihat data terbaru, lalu ulangi perubahan Anda.</AlertDescription>`;
  - `<Button type="button" variant="outline">Muat ulang</Button>`, which calls `window.location.reload()` (house: `Error.tsx:98-100`). A full reload drops the stale form state; `router.reload` keeps it.
- It is imported by path, not through `composite/index.ts`, which `master-lifecycle` W1-UI owns.
- It is keyboard reachable and announced by `role="alert"`.

### 5.3 Pages

| Page | Change | Slice |
|---|---|---|
| `Customers/Edit.tsx` | `expected_updated_at: c.updated_at` in the `useForm` init (`:81-93`). Prop type `Customer & { whatsapp: CustomerWhatsApp; updated_at: string }` (local; `types/models.ts` belongs to `master-lifecycle`). `<ConflictBanner/>` above the form. A save goes to the index, so no refresh is needed | W2-LOCK-FE |
| `Inventory/Products/Edit.tsx` | Field in the init (`:27-37`); it flows through `router.put` (`:57`). Type `Product & { updated_at: string }`. Banner | W2-LOCK-FE |
| `Service/Packages/Edit.tsx` | Field in the init (`:58-70`), `PkgData.updated_at`. Banner | W2-LOCK-FE |
| `Employees/Index.tsx` | `EmpRow.updated_at`. The empty form has `expected_updated_at: ''` (the store request ignores it). `openEdit` (`:158-173`) sets it from the row. Banner inside the edit modal | W2-LOCK-FE |
| `Company/Settings.tsx` | NEW prop `version: string`. Init `{ settings, expected_updated_at: version }`. The `transform` (`:194`) returns `{ settings: next, expected_updated_at: data.expected_updated_at }`, because today's transform drops other keys. `put(..., { onSuccess: (page) => setData('expected_updated_at', (page.props as SettingsProps).version) })`: the save redirects back to the same page, and without this the next save would conflict with the user's own save. Banner | W2-LOCK-FE |
| `Admin/SPK/Show.tsx` | Item 6 removals (`:68, :166, :206, :265, :555, :771-773`); item 7 `canChange` (`:223`) | W2-SPK-FE |
| `Admin/Billing/Receivables.tsx` | Prop `paymentExceptions` (§4.6). When `count > 0`, an `Alert` (danger) above the table: title "{count} pembayaran online perlu dicek"; a list row per item: invoice number as a link (`route('admin.invoices.show', item.invoice_id)`), "Rp {amount}" (`formatRupiah`), `order_id`, reason; a footer "Catat pembayarannya dengan referensi order ID tersebut, atau refund di Midtrans." | W1-BILLING-FE |
| `lib/statuses/payment.ts` | `cancelled_locally: { label: 'Ditutup', tone: 'neutral' }`; doc comment updated | W1-BILLING-FE |
| `Portal/Payments/Return.tsx` | `outcomes` is a `Record<PaymentAttemptStatus, …>` (`:15-18`), so it needs `cancelled_locally`: title "Pembayaran tidak diteruskan", body "Tagihan ini sudah dibayar atau dibatalkan sebelum pembayaran online selesai. Bila saldo Anda terpotong, hubungi kami.", the neutral icon and tone of `expired` | W1-BILLING-FE |

States:
- loading: unchanged (`processing`);
- conflict: toast + banner;
- duplicate: toast, plus the banner on the 5 forms;
- empty receivables exceptions: nothing rendered.

## 6. Test plan (per slice; Main runs each command once after the wave lands)

Every NEW test class uses `RefreshDatabase`, the repo default (335 files). Tests that commit fixtures (race, lock probe) clean up with `purgeCompany()`.

| Slice | Tests (NEW unless marked) | Command |
|---|---|---|
| W1-MECH | `tests/Feature/Integrity/PreventDuplicateWebSubmitTest.php` (9 tests, §4.8 items 4/8/20e); `tests/Feature/Integrity/OptimisticLockTest.php`; regression: `tests/Feature/Ipam/IpamConcurrencyTest.php`, `tests/Feature/Odn/OdnConcurrencyTest.php` (trait), `tests/Feature/Admin/DispatchQueueTest.php`, `tests/Feature/Admin/MyWorkTest.php` (409 rendering) | `./vendor/bin/sail artisan test --compact tests/Feature/Integrity/PreventDuplicateWebSubmitTest.php tests/Feature/Integrity/OptimisticLockTest.php tests/Feature/Ipam/IpamConcurrencyTest.php tests/Feature/Odn/OdnConcurrencyTest.php tests/Feature/Admin/DispatchQueueTest.php tests/Feature/Admin/MyWorkTest.php` |
| W1-UI | none (no Vitest; behaviour covered by W3 E2E) | `npm run typecheck && npm run lint` |
| W1-PAY | `tests/Feature/Billing/PaymentAttemptLocalCancelTest.php` (10 tests); updated: `PaymentAttemptServiceTest.php` (`test_a_balance_that_changed_gets_a_new_attempt_and_leaves_the_old_one_untouched` becomes "…closes the old one"), `MidtransWebhookTest.php`, `MidtransReconcileCommandTest.php` | `./vendor/bin/sail artisan test --compact tests/Feature/Billing/PaymentAttemptLocalCancelTest.php tests/Feature/Billing/PaymentAttemptServiceTest.php tests/Feature/Billing/MidtransWebhookTest.php tests/Feature/Billing/MidtransReconcileCommandTest.php` + `EXPLAIN` of `openExceptions()` |
| W1-INVOICE | `RecurringPeriodUniqueMigrationTest.php`, `BillingScheduleIsolationTest.php`, `InvoiceDraftLockTest.php`, `InvoiceNumberGapTest.php` (all `tests/Feature/Billing/`); updated: `RecurringInvoiceRaceGuardTest.php`, `BillingFailureNotificationTest.php`, `InvoiceRequestValidationTest.php` (sends the version), `InvoiceWhatsAppReminderCommandTest.php`, `OverdueSuspensionTest.php` (failure recipients; the 3 settings PUTs at `:423,:435,:440` send `expected_updated_at`), and `RecurringBillingTest.php`/`BillingCommandsTest.php` only if the additive `failed` key breaks them | `./vendor/bin/sail artisan test --compact tests/Feature/Billing` + `./vendor/bin/sail artisan migrate --pretend` (M2 SQL) + `EXPLAIN` of the reminder dedupe query |
| W1-BILLING-FE | none | `npm run typecheck && npm run lint && node resources/js/lib/statuses.selfcheck.mjs` |
| W1-OPS | updated `tests/Feature/Operations/SchedulerLockTest.php` (+2 tests); NEW `tests/Feature/Operations/QueueRetryAfterTest.php`; regression of the schedule assertions in `tests/Feature/Billing/{MidtransReconcileCommandTest,OverdueSuspensionTest,InvoiceWhatsAppReminderCommandTest}.php`, `tests/Feature/NetworkAsset/NetworkSyncTest.php` | `./vendor/bin/sail artisan test --compact tests/Feature/Operations tests/Feature/Billing/MidtransReconcileCommandTest.php tests/Feature/Billing/OverdueSuspensionTest.php tests/Feature/Billing/InvoiceWhatsAppReminderCommandTest.php tests/Feature/NetworkAsset/NetworkSyncTest.php && sh -n docker/app/entrypoint.sh` |
| W1-MISC | `tests/Feature/Integrity/UniqueRaceMessagesTest.php`, `tests/Feature/Integrity/HomeFeedCacheCommitTest.php`, `tests/Feature/NetworkAsset/NetworkAssetCodeGapTest.php` | `./vendor/bin/sail artisan test --compact tests/Feature/Integrity/UniqueRaceMessagesTest.php tests/Feature/Integrity/HomeFeedCacheCommitTest.php tests/Feature/NetworkAsset tests/Feature/Service` |
| W2-INVENTORY | `AdjustmentApprovalStaleCountTest.php`, `StockQuantityPrecisionTest.php`, `InventoryLockOrderTest.php`, `StockFormReplayTest.php`, `IssueAgainstClosedWorkOrderTest.php`, `MaterialReturnWorkOrderLockTest.php` (all `tests/Feature/Inventory/`); updated `tests/Feature/Api/V1/Technician/InventoryRequestWritesTest.php` (+3), `Modules/Inventory/tests/Feature/StockServiceTest.php` (if the compare or signature change breaks it) | `./vendor/bin/sail artisan test --compact tests/Feature/Inventory tests/Feature/Api/V1/Technician Modules/Inventory/tests` |
| W2-SPK | `SpkConsumptionCoverageTest.php`, `SpkPlannedQuantityRemovalTest.php`, `WorkOrderItemLockTest.php`, `WorkOrderUpdateLockTest.php`, `WorkOrderEvidenceIdempotencyTest.php` (all `tests/Feature/SPK/`); updated for item 6, item 7 or the version: `tests/Feature/P0CriticalPathTest.php`, `tests/Feature/SPK/SpkCompletionOrchestrationTest.php`, `tests/Feature/SPK/WorkOrderItemAssignmentValidationTest.php`, `tests/Feature/SPK/WorkOrderSubscriptionPairTest.php`, `tests/Feature/Inventory/MaterialReturnWorkflowTest.php`, `tests/Feature/Tenant/TenantSafeWriteReferencesTest.php`, `tests/Feature/Api/V1/Technician/WorkOrderItemsTest.php`, `tests/Feature/Api/V1/Technician/WorkOrdersTest.php` | `./vendor/bin/sail artisan test --compact tests/Feature/SPK tests/Feature/P0CriticalPathTest.php tests/Feature/Inventory/MaterialReturnWorkflowTest.php tests/Feature/Tenant/TenantSafeWriteReferencesTest.php tests/Feature/Api/V1/Technician` |
| W2-SPK-FE | none | `npm run typecheck && npm run lint` |
| W2-LOCK-CUSTOMER | `tests/Feature/Integrity/CustomerEditConflictTest.php`, `tests/Feature/Integrity/SubscriptionTransitionRaceTest.php`; updated (send the version): `CustomerAccountCreationTest.php`, `CustomerPageTest.php`, `CustomerSalesAttributionTest.php`, `CustomerStatusRollupTest.php`, `Admin/SubscriptionInstallationAddressTest.php`, `NetworkAsset/NetworkAccessGapTest.php` | `./vendor/bin/sail artisan test --compact tests/Feature/Integrity/CustomerEditConflictTest.php tests/Feature/Integrity/SubscriptionTransitionRaceTest.php tests/Feature/CustomerAccountCreationTest.php tests/Feature/CustomerPageTest.php tests/Feature/CustomerSalesAttributionTest.php tests/Feature/CustomerStatusRollupTest.php tests/Feature/Admin/SubscriptionInstallationAddressTest.php tests/Feature/NetworkAsset/NetworkAccessGapTest.php` |
| W2-LOCK-PEOPLE | `tests/Feature/Integrity/SettingsEditConflictTest.php`; updated: `EmployeeTest.php`, `Admin/CompanyMapDefaultTest.php`, `WhatsApp/WhatsAppCompanySettingsTest.php` | `./vendor/bin/sail artisan test --compact tests/Feature/Integrity/SettingsEditConflictTest.php tests/Feature/EmployeeTest.php tests/Feature/Admin/CompanyMapDefaultTest.php tests/Feature/WhatsApp/WhatsAppCompanySettingsTest.php tests/Feature/Billing/OverdueSuspensionTest.php` |
| W2-LOCK-CATALOG | `tests/Feature/Integrity/CatalogEditConflictTest.php`; updated: `Inventory/ProductCodeSequenceTest.php`, `Service/ServicePackageCategoryTest.php` | `./vendor/bin/sail artisan test --compact tests/Feature/Integrity/CatalogEditConflictTest.php tests/Feature/Inventory/ProductCodeSequenceTest.php tests/Feature/Service/ServicePackageCategoryTest.php` |
| W2-LOCK-TICKET | `tests/Feature/Ticketing/TicketUpdateLockTest.php` (5 tests) | `./vendor/bin/sail artisan test --compact tests/Feature/Ticketing` |
| W2-LOCK-FE | none | `npm run typecheck && npm run lint` |
| W2-MEDIA | `tests/Feature/Integrity/MediaReplaceTest.php`, `tests/Feature/Service/AnnouncementPublishRaceTest.php` | `./vendor/bin/sail artisan test --compact tests/Feature/Integrity/MediaReplaceTest.php tests/Feature/Service` |
| W2-TENANT-BE | `tests/Feature/Integrity/ActiveCompanyTokenTest.php`; regression `tests/Feature/Tenant/CompanySwitchTest.php` | `./vendor/bin/sail artisan test --compact tests/Feature/Integrity/ActiveCompanyTokenTest.php tests/Feature/Tenant/CompanySwitchTest.php` |
| W2-TENANT-FE | none | `npm run typecheck && npm run lint` |
| W3-QA | NEW `tests/e2e/integrity-hardening.spec.ts`: (1) two tabs record the same cash payment, the second gets the toast and one payment row; (2) two tabs edit one customer, the second gets the banner, "Muat ulang" shows the first's value; (3) tab A on company A, tab B switches to B, tab A creates a unit, toast "Perusahaan aktif berubah di tab lain.", no unit in B; (4) Receivables shows the exception alert from a seeded exception attempt. Updated: `tests/e2e/technician-performance.spec.ts:64` (the PUT sends `expected_updated_at`), `tests/e2e/customer-edit.spec.ts` (double click now ends with one save and at most one duplicate toast). NEW `tests/Feature/Integrity/AppRequestToApprovalTest.php` | `./vendor/bin/sail artisan test --compact tests/Feature/Integrity/AppRequestToApprovalTest.php`; `flock /tmp/inbils-e2e.lock sh -c 'npm run build && node scripts/e2e-setup.mjs && npm run test:e2e -- tests/e2e/integrity-hardening.spec.ts tests/e2e/technician-performance.spec.ts tests/e2e/customer-edit.spec.ts tests/e2e/head-company.spec.ts tests/e2e/midtrans-payment.spec.ts tests/e2e/datatable-actions-delete.spec.ts --project=chromium --workers=1'` (first chown root-owned storage dirs, as in the memory note) |
| W3-MOBILE | `technician/test/app_only_features_test.dart`: "a request opened from an SPK carries that SPK"; "the active SPK is preselected and Tanpa SPK sends none"; the existing "a new request…" test (`:1069-1135`) is updated for the payload | `cd /home/madmouse/Documents/web/inbils-mobile/technician && ~/development/flutter/bin/flutter analyze && ~/development/flutter/bin/flutter test test/app_only_features_test.dart` |
| W3-DOCS | OpenAPI regenerated | `./vendor/bin/sail artisan scramble:export --path=docs/api/mobile-v1.openapi.json && ./vendor/bin/sail artisan test --compact tests/Feature/Api/V1/OpenApiContractTest.php` |

## 7. Risks and open decisions

Open decisions. The recommended default is applied unless the owner objects (standing "ikuti saran"):

- **OD-1 Header instead of a hidden field (item 18).**
  - About 140 `useForm` callers and many `router.*` calls exist, and a per-form field would touch every one of them.
  - `defaults.visitOptions` reaches all of them in one file and is the documented Inertia 2.3 hook.
  - Default: header; a missing header is accepted (`shortcut:` comment: "axios writes and classic forms carry no company; add the header to axios defaults if a tenant write moves to axios").
- **OD-2 `useInertiaForm` is not the carrier (items 4, 11).**
  - It has 0 consumers. The toast is the global flash toast every admin page mounts, and the banner is a component on the 5 forms.
  - Default: leave the hook untouched (deleting it is out of scope).
- **OD-3 Exemption list inside the middleware instead of `->withoutMiddleware` in route files (item 4).**
  - The 5 exempt routes live in 3 route files owned by `master-lifecycle` plus one tenant-integrations file.
  - Default: `EXCEPT` with route names, a single convention.
- **OD-4 Item 7 status set.**
  - The spec says `draft/assigned/in_progress`.
  - The audit recommendation the owner accepted says `draft/generated/assigned/in_progress/rejected`.
  - Planning items on a `generated` SPK and fixing a `rejected` one are allowed today in the UI (`SPK/Show.tsx:223`), so blocking them would be a feature change.
  - Default: the audit set.
- **OD-5 Who gets the exception alert.**
  - Default: active users of the attempt's company with `billing.payment.record`.
  - Alternative: `billing.payment.reverse` (narrower).
- **OD-6 Item 6 migration.**
  - The spec mentions keeping the column nullable. With no writer left, inserts take the default 0, so no migration is needed and nothing is dropped.
  - Default: no migration; a later lane drops the column.
- **OD-7 Manual Generate after a cancel.**
  - The exists-check now counts cancelled rows for the manual run too, so the Generate dialog skips a cancelled period.
  - Default: re-issue a disputed period as a manual one-time invoice, the existing feature.
- **OD-8 Hourly prune TTL.** The spec names only per-minute, 15-minute and daily commands. Default: 50 min for `inbils:platform:exports:prune`.
- **OD-9 Item 14 pre-lock.** Default: drop the lock and keep the plain read for the friendly message; `reserve()` stays the authority. The spec says "drop the pre-lock".
- **OD-10 Item 1 `work_order_id` optional.** Old app builds and van-stock requests still work. The app preselects the active SPK.
  - Default: optional on the API, preselected in the app.

Risks:
- **R-1 Missing edit pages.** `InvoiceController::edit`, `WorkOrderController::edit` and `TicketController::edit` render pages that do not exist (`Admin/Billing/Invoices/Edit`, `Admin/SPK/Edit`, `Admin/Tickets/Edit`). The edit GET answers 500. This is pre-existing and out of scope (no feature change). Their update endpoints still get the version check. Main may open a ticket.
- **R-2 Tests that post an identical request twice in one test.**
  - They now get 409 from mechanism (a). Owners fix them with `$this->travel(11)->seconds()` between posts.
  - Main's full-suite run after W1 lists them; owners report them as needs-change.
- **R-3 OpenAPI test.** `OpenApiContractTest` is red from W1-PAY (new notification type) and W2-INVENTORY (`work_order_id`) until W3-DOCS regenerates. `master-lifecycle` has the same note (its R10). The file is generated, so the last lane to finish regenerates.
- **R-4 Same-second version.** `updated_at` has whole seconds (`timestamps()` on all 9 tables). Two writes inside one second share a version (`shortcut:` in `OptimisticLock::assert`). Upgrade path: a `lock_version` column.
- **R-5 Reminder dedupe and queue lag.** `InvoiceDueReminderNotification` is queued, so a manual re-run before the worker wrote the bell row can still double. A re-run after the queue drained is deduped.
- **R-6 Deadlocks outside the new order.** A path that locks a subscription before its customer and is not converted here could still deadlock with item 13's order. Converted: `SubscriptionService`, `OverdueSuspensionService::suspendIfStillOverdue`. The loser gets 1213, and a scheduled caller retries the next day.
- **R-7 F4-D07 is not in this lane.** The SPK approval toast still says "Data gagal disimpan" for a stock error.
- **R-8 Stale docs images.** `docs/user-guide/images/web/spk-add-item.png` and `images/technician/permintaan-baru.png` show the old forms. W3-DOCS fixes the text and alt text only; regenerating screenshots (`npm run docs:screenshots`) is deferred, as in `master-lifecycle` R11.
- **R-9 Security review (hard rule 10).** This lane touches payments (W1-PAY), a request guard (W1-MECH), tenant writes (W2-TENANT-BE) and a technician API (W2-INVENTORY). A `security-reviewer` pass on those slices is required before the lane is done.
- **R-10 Host limit.** At most about 5 agents at a time. Builds and E2E run under `flock /tmp/inbils-build.lock` and `/tmp/inbils-e2e.lock`. Main batches the waves (§8.1).
- **R-11 Tenant-integrations swaps Midtrans config after W1.** Agreed: `cancelTransaction` keeps calling `client()` and `url()`, and they pass `$attempt->company_id` there.

Deferred (not built):
- a `lock_version` column;
- per-form resolve actions for exceptions (a refund or a booking by reference closes them);
- an F4-D07 message fix;
- an invoice detail link in the bell;
- regenerating screenshots and PDFs.

## 8. Work breakdown

### 8.1 Waves and order

- **W1** (7 slices, after this design is approved): W1-MECH, W1-UI, W1-PAY, W1-INVOICE, W1-BILLING-FE, W1-OPS, W1-MISC.
  - Batch A: MECH, PAY, INVOICE, OPS, MISC. Batch B: UI, BILLING-FE.
  - W1-INVOICE consumes `OptimisticLock` (W1-MECH) and `closeOpenFor`/`openExceptions` (W1-PAY) by the contracts in §4; W1 is verified as one unit.
  - After acceptance, Main runs the live-DB runbook (§3).
- **W2** (11 slices, after W1 is accepted): W2-INVENTORY, W2-SPK, W2-SPK-FE, W2-LOCK-CUSTOMER, W2-LOCK-PEOPLE, W2-LOCK-CATALOG, W2-LOCK-TICKET, W2-LOCK-FE, W2-MEDIA, W2-TENANT-BE, W2-TENANT-FE.
  - Batches: (INVENTORY, SPK, SPK-FE, TENANT-BE, TENANT-FE), (LOCK-CUSTOMER, LOCK-PEOPLE, LOCK-CATALOG, LOCK-TICKET, LOCK-FE), (MEDIA).
  - W2-INVENTORY and W2-SPK share the lock-order contract; tests that need both sides run in W3-QA.
- **W3** (after W2 is accepted): W3-QA and W3-MOBILE in parallel; W3-DOCS last (OpenAPI needs every API change).

Skills each brief must carry:
- backend: `skill://laravel-best-practices`, `skill://tdd`, `skill://ponytail`;
- frontend: `skill://inertia-react-development`, `skill://tailwindcss-development`, `skill://antislop-ui`, `skill://antislop-copywriting`, `skill://ponytail`;
- QA: `skill://playwright-best-practices`, `skill://e2e`;
- mobile: the `flutter-*`/`dart-*` skills (`flutter-add-widget-test`, `dart-add-unit-test`, `flutter-apply-architecture-best-practices`), `skill://ponytail`;
- docs: `skill://antislop-copywriting`.

No slice runs builds, tests or formatters mid-flight; Main runs the commands of §6 once per wave.

### 8.2 Slices (owned files are exhaustive; NEW = the slice creates it)

**W1-MECH · backend · mechanisms (a) and (b), 409 rendering, test infrastructure**
- Owned:
  - NEW `app/Http/Middleware/PreventDuplicateWebSubmit.php`;
  - NEW `app/Support/OptimisticLock.php`;
  - `app/Http/Middleware/Api/EnforceIdempotency.php`;
  - `bootstrap/app.php`;
  - `tests/Traits/RunsConcurrentWorkers.php`;
  - NEW `tests/Feature/Integrity/PreventDuplicateWebSubmitTest.php`;
  - NEW `tests/Feature/Integrity/OptimisticLockTest.php`.
- Change: §4.1, §4.2 (class only), §4.4, §4.9.
- Consumes: nothing. Produces: `PreventDuplicateWebSubmit::MESSAGE`, `OptimisticLock::{FIELD,FORMAT,MESSAGE,rules,version,assert}`, the 303 rule, `waitsForRowLock()`, the HTTP-exception worker result.
- Acceptance: the W1-MECH command of §6 passes.

**W1-UI · frontend · global conflict UI**
- Owned: `resources/js/hooks/useToast.ts`; NEW `resources/js/Components/composite/ConflictBanner.tsx`.
- Change: §5.1 (toast), §5.2.
- Produces: `ConflictBanner`.
- Acceptance: `npm run typecheck && npm run lint`.

**W1-PAY · backend · item 2 (attempts side), item 14 (attempt transactions)**
- Owned:
  - `Modules/Billing/app/Services/PaymentAttemptService.php`;
  - `Modules/Billing/app/Services/MidtransGateway.php`;
  - `Modules/Billing/app/Models/PaymentAttempt.php`;
  - `Modules/Billing/app/Console/ReconcileMidtransPayments.php`;
  - NEW `Modules/Billing/app/Jobs/CancelMidtransTransactionJob.php`;
  - NEW `Modules/Billing/app/Notifications/PaymentAttemptExceptionNotification.php`;
  - `app/Http/Resources/NotificationResource.php`;
  - NEW M1 `Modules/Billing/database/migrations/2026_10_23_120000_add_cancelled_locally_to_payment_attempts_status.php`;
  - `tests/Traits/InteractsWithMidtrans.php` (only for a cancel-response fake);
  - `tests/Feature/Billing/PaymentAttemptServiceTest.php`;
  - `tests/Feature/Billing/MidtransWebhookTest.php`;
  - `tests/Feature/Billing/MidtransReconcileCommandTest.php`;
  - NEW `tests/Feature/Billing/PaymentAttemptLocalCancelTest.php`.
- Change: §3 M1, §4.6 (except the BillingService calls and the receivables prop).
- Produces: `PaymentAttempt::STATUS_CANCELLED_LOCALLY`, `closeOpenFor()`, `openExceptions()`, `cancelTransaction()`, notification type `payment_attempt_exception`.
- Acceptance: the W1-PAY command of §6 and the pasted `EXPLAIN`.

**W1-INVOICE · backend · items 3, 9, 11/16 (draft invoice), 14 (BillingService), 20(a) invoice, 20(c); item 2 call sites**
- Owned:
  - `Modules/Billing/app/Services/BillingService.php`;
  - `Modules/Billing/app/Services/OverdueSuspensionService.php`;
  - `Modules/Billing/app/Services/BillingScheduleGateway.php`;
  - `Modules/Billing/app/Console/GenerateRecurringInvoices.php`;
  - `Modules/Billing/app/Console/CheckOverdueInvoices.php`;
  - `Modules/Billing/app/Console/SuspendOverdueSubscriptions.php`;
  - `Modules/Billing/app/Console/SendInvoiceWhatsAppReminders.php`;
  - `Modules/Billing/app/Notifications/BillingScheduleFailedNotification.php`;
  - `Modules/Billing/app/Http/Controllers/InvoiceController.php`;
  - `Modules/Billing/app/Http/Requests/UpdateInvoiceRequest.php`;
  - NEW M2 `Modules/Billing/database/migrations/2026_10_23_120100_add_active_recurring_period_to_invoices_table.php`;
  - `tests/Feature/Billing/RecurringInvoiceRaceGuardTest.php`;
  - `tests/Feature/Billing/BillingFailureNotificationTest.php`;
  - `tests/Feature/Billing/InvoiceRequestValidationTest.php`;
  - `tests/Feature/Billing/InvoiceWhatsAppReminderCommandTest.php`;
  - `tests/Feature/Billing/OverdueSuspensionTest.php`;
  - `tests/Feature/Billing/RecurringBillingTest.php`;
  - `tests/Feature/Billing/BillingCommandsTest.php`;
  - NEW `tests/Feature/Billing/RecurringPeriodUniqueMigrationTest.php`;
  - NEW `tests/Feature/Billing/BillingScheduleIsolationTest.php`;
  - NEW `tests/Feature/Billing/InvoiceDraftLockTest.php`;
  - NEW `tests/Feature/Billing/InvoiceNumberGapTest.php`.
- Change: §3 M2, §4.8 items 3, 9, 20(a)(c), the invoice rows of items 11 and 16, `closeOpenFor` calls in `recordPayment`/`cancel`, the `paymentExceptions` prop, `, 3` on BillingService transactions.
- Consumes: W1-MECH `OptimisticLock`; W1-PAY `closeOpenFor()`, `openExceptions()`.
- Produces: `BillingService::update()`, `BillingService::destroyDraft()`, `BillingScheduleFailedNotification::recipients()`, prop `paymentExceptions`.
- Acceptance: the W1-INVOICE command of §6, the M2 `--pretend` SQL and the `EXPLAIN` are pasted.

**W1-BILLING-FE · frontend · item 2 UI**
- Owned: `resources/js/lib/statuses/payment.ts`; `resources/js/Pages/Portal/Payments/Return.tsx`; `resources/js/Pages/Admin/Billing/Receivables.tsx`.
- Change: §5.3 rows.
- Consumes: `cancelled_locally`, `paymentExceptions`.
- Acceptance: the W1-BILLING-FE command of §6.

**W1-OPS · devops · items 10, 17**
- Owned:
  - `routes/console.php`;
  - `docker/app/entrypoint.sh`;
  - `config/queue.php`;
  - `Modules/Service/app/Jobs/SendAnnouncementNotifications.php`;
  - `tests/Feature/Operations/SchedulerLockTest.php`;
  - NEW `tests/Feature/Operations/QueueRetryAfterTest.php`.
- Change: §4.8 items 10, 17.
- Acceptance: the W1-OPS command of §6.

**W1-MISC · backend · item 20(a) asset, (b), (d)**
- Owned:
  - `Modules/NetworkAsset/app/Http/Controllers/NetworkAssetController.php`;
  - `Modules/Service/app/Services/HomeFeedCache.php`;
  - `app/Services/Core/CompanyMembershipService.php`;
  - `Modules/NetworkAsset/app/Services/NasDeviceService.php`;
  - `Modules/NetworkAsset/app/Services/NetworkAccountService.php`;
  - NEW `tests/Feature/Integrity/UniqueRaceMessagesTest.php`;
  - NEW `tests/Feature/Integrity/HomeFeedCacheCommitTest.php`;
  - NEW `tests/Feature/NetworkAsset/NetworkAssetCodeGapTest.php`.
- Change: §4.8 item 20.
- Acceptance: the W1-MISC command of §6.

**W2-INVENTORY · backend · items 1 (inventory side), 5, 7 (returns), 8 (test), 14, 15**
- Owned:
  - `Modules/Inventory/app/Services/StockService.php`;
  - `Modules/Inventory/app/Services/InventoryRequestService.php`;
  - `Modules/Inventory/app/Services/MaterialReturnService.php`;
  - `Modules/Inventory/app/Support/InventoryRequestSources.php`;
  - `Modules/Inventory/app/Http/Requests/Api/V1/StoreTechnicianInventoryRequestRequest.php`;
  - `Modules/Inventory/app/Http/Controllers/Api/V1/Technician/InventoryRequestController.php`;
  - `tests/Feature/Api/V1/Technician/InventoryRequestWritesTest.php`;
  - `Modules/Inventory/tests/Feature/StockServiceTest.php`;
  - NEW `tests/Feature/Inventory/AdjustmentApprovalStaleCountTest.php`;
  - NEW `tests/Feature/Inventory/StockQuantityPrecisionTest.php`;
  - NEW `tests/Feature/Inventory/InventoryLockOrderTest.php`;
  - NEW `tests/Feature/Inventory/StockFormReplayTest.php`;
  - NEW `tests/Feature/Inventory/IssueAgainstClosedWorkOrderTest.php`;
  - NEW `tests/Feature/Inventory/MaterialReturnWorkOrderLockTest.php`.
- Change: §4.5 (server), §4.8 items 1(a)(b), 5, 7 (returns), 8, 14, 15.
- Consumes: W1-MECH `waitsForRowLock`, mechanism (a).
- Produces: the API field `work_order_id`; WO-first locking in `issue`, `receiveAccept` and `resolveException`.
- Acceptance: the W2-INVENTORY command of §6.

**W2-SPK · backend · items 1 (consumption), 6, 7 (SPK side), 11/16 (SPK), 14, 19 (evidence)**
- Owned:
  - `Modules/SPK/app/Actions/CompleteSpkAction.php`;
  - `Modules/SPK/app/Services/SpkService.php`;
  - `Modules/SPK/app/Http/Controllers/WorkOrderController.php`;
  - `Modules/SPK/app/Http/Requests/StoreWorkOrderItemRequest.php`;
  - `Modules/SPK/app/Http/Requests/UpdateWorkOrderRequest.php`;
  - `Modules/SPK/app/Http/Requests/StoreWorkOrderEvidenceRequest.php`;
  - `tests/Feature/P0CriticalPathTest.php`;
  - `tests/Feature/SPK/SpkCompletionOrchestrationTest.php`;
  - `tests/Feature/SPK/WorkOrderItemAssignmentValidationTest.php`;
  - `tests/Feature/SPK/WorkOrderSubscriptionPairTest.php`;
  - `tests/Feature/Inventory/MaterialReturnWorkflowTest.php`;
  - `tests/Feature/Tenant/TenantSafeWriteReferencesTest.php`;
  - `tests/Feature/Api/V1/Technician/WorkOrderItemsTest.php`;
  - `tests/Feature/Api/V1/Technician/WorkOrdersTest.php`;
  - NEW `tests/Feature/SPK/SpkConsumptionCoverageTest.php`;
  - NEW `tests/Feature/SPK/SpkPlannedQuantityRemovalTest.php`;
  - NEW `tests/Feature/SPK/WorkOrderItemLockTest.php`;
  - NEW `tests/Feature/SPK/WorkOrderUpdateLockTest.php`;
  - NEW `tests/Feature/SPK/WorkOrderEvidenceIdempotencyTest.php`.
- Change: §4.8 items 1(c), 6, 7 (SPK side), the SPK rows of 11 and 16, 14, 19 (evidence).
- Consumes: W1-MECH; the W2-INVENTORY lock order (contract only).
- Produces: `SpkService::{update,destroy,removeItem,ITEM_EDITABLE_STATUSES}`.
- Acceptance: the W2-SPK command of §6.

**W2-SPK-FE · frontend · items 6, 7 UI**
- Owned: `resources/js/Pages/Admin/SPK/Show.tsx`.
- Change: §5.3 row.
- Acceptance: `npm run typecheck && npm run lint`.

**W2-LOCK-CUSTOMER · backend · item 11 (customer, subscription), item 13**
- Owned:
  - `app/Http/Controllers/Admin/CustomerController.php`;
  - `app/Http/Requests/Admin/UpdateCustomerRequest.php`;
  - `app/Http/Resources/CustomerResource.php`;
  - `app/Http/Controllers/Admin/SubscriptionController.php`;
  - `app/Http/Requests/Admin/UpdateSubscriptionRequest.php`;
  - `app/Services/Core/SubscriptionService.php`;
  - `app/Services/Core/CustomerStatusRecalculator.php`;
  - `tests/Feature/CustomerAccountCreationTest.php`;
  - `tests/Feature/CustomerPageTest.php`;
  - `tests/Feature/CustomerSalesAttributionTest.php`;
  - `tests/Feature/CustomerStatusRollupTest.php`;
  - `tests/Feature/Admin/SubscriptionInstallationAddressTest.php`;
  - `tests/Feature/NetworkAsset/NetworkAccessGapTest.php`;
  - NEW `tests/Feature/Integrity/CustomerEditConflictTest.php`;
  - NEW `tests/Feature/Integrity/SubscriptionTransitionRaceTest.php`.
- Change: the §4.2 rows for customer and subscription; §4.8 item 13.
- Produces: `CustomerResource.updated_at`.
- Acceptance: the W2-LOCK-CUSTOMER command of §6.

**W2-LOCK-PEOPLE · backend · item 11 (employee, company settings)**
- Owned:
  - `app/Http/Controllers/Admin/EmployeeController.php`;
  - `app/Http/Requests/Admin/UpdateEmployeeRequest.php`;
  - `app/Http/Resources/EmployeeResource.php`;
  - `app/Http/Controllers/Admin/CompanyController.php`;
  - `app/Http/Requests/Admin/UpdateCompanySettingsRequest.php`;
  - `app/Services/Core/CompanyService.php`;
  - `tests/Feature/EmployeeTest.php`;
  - `tests/Feature/Admin/CompanyMapDefaultTest.php`;
  - `tests/Feature/WhatsApp/WhatsAppCompanySettingsTest.php`;
  - NEW `tests/Feature/Integrity/SettingsEditConflictTest.php`.
- Change: the §4.2 rows for employee and company settings.
- Produces: `EmployeeResource.updated_at`, the Settings prop `version`.
- Acceptance: the W2-LOCK-PEOPLE command of §6.

**W2-LOCK-CATALOG · backend · item 11 (product, package)**
- Owned:
  - `Modules/Inventory/app/Http/Controllers/ProductController.php`;
  - `Modules/Inventory/app/Http/Requests/UpdateProductRequest.php`;
  - `Modules/Inventory/app/Http/Resources/ProductResource.php`;
  - `Modules/Service/Http/Controllers/ServicePackageController.php`;
  - `Modules/Service/Http/Requests/UpdateServicePackageRequest.php`;
  - `Modules/Service/Http/Resources/ServicePackageResource.php`;
  - `tests/Feature/Inventory/ProductCodeSequenceTest.php`;
  - `tests/Feature/Service/ServicePackageCategoryTest.php`;
  - NEW `tests/Feature/Integrity/CatalogEditConflictTest.php`.
- Change: the §4.2 rows for product and package.
- Produces: `ProductResource.updated_at`, `ServicePackageResource.updated_at`, and the transaction plus lock in `ServicePackageController::update`, which `master-lifecycle` versioning relies on.
- Acceptance: the W2-LOCK-CATALOG command of §6.

**W2-LOCK-TICKET · backend · items 11/16 (ticket), 19 (attachment)**
- Owned:
  - `Modules/Ticketing/app/Http/Controllers/TicketController.php`;
  - `Modules/Ticketing/app/Services/TicketService.php`;
  - `Modules/Ticketing/app/Http/Requests/UpdateTicketRequest.php`;
  - NEW `tests/Feature/Ticketing/TicketUpdateLockTest.php`.
- Change: the ticket rows of items 11, 16 and 19.
- Produces: `TicketService::{update,destroy}`.
- Acceptance: the W2-LOCK-TICKET command of §6.

**W2-LOCK-FE · frontend · item 11 forms**
- Owned:
  - `resources/js/Pages/Admin/Customers/Edit.tsx`;
  - `resources/js/Pages/Admin/Inventory/Products/Edit.tsx`;
  - `resources/js/Pages/Admin/Service/Packages/Edit.tsx`;
  - `resources/js/Pages/Admin/Employees/Index.tsx`;
  - `resources/js/Pages/Admin/Company/Settings.tsx`.
- Change: §5.3 rows.
- Consumes: the `updated_at` resource fields, the `version` prop, `ConflictBanner`.
- Acceptance: `npm run typecheck && npm run lint`.

**W2-MEDIA · backend · item 19 (logo, banner), item 16 (announcement publish)**
- Owned:
  - `app/Services/Core/CompanyLogoService.php`;
  - `app/Models/Core/Company.php`;
  - `Modules/Service/app/Services/PromoBannerService.php`;
  - `Modules/Service/Models/PromoBanner.php`;
  - `Modules/Service/app/Services/AnnouncementService.php`;
  - NEW `tests/Feature/Integrity/MediaReplaceTest.php`;
  - NEW `tests/Feature/Service/AnnouncementPublishRaceTest.php`.
- Change: the item 19 rows (logo, banner) and the item 16 row (publish).
- Acceptance: the W2-MEDIA command of §6.

**W2-TENANT-BE · backend · item 18 server**
- Owned: `app/Http/Middleware/ResolveActiveCompany.php`; NEW `tests/Feature/Integrity/ActiveCompanyTokenTest.php`.
- Change: §4.3 check.
- Acceptance: the W2-TENANT-BE command of §6.

**W2-TENANT-FE · frontend · item 18 client**
- Owned: `resources/js/app.tsx`.
- Change: §5.1 header.
- Acceptance: `npm run typecheck && npm run lint`.

**W3-QA · qa · cross-slice and E2E**
- Owned: NEW `tests/e2e/integrity-hardening.spec.ts`; `tests/e2e/technician-performance.spec.ts`; `tests/e2e/customer-edit.spec.ts`; NEW `tests/Feature/Integrity/AppRequestToApprovalTest.php`.
- Change: §6 W3-QA row. Other failing specs go back to their owner as needs-change.
- Acceptance: the W3-QA commands of §6.

**W3-MOBILE · mobile · item 1 app side (repo `/home/madmouse/Documents/web/inbils-mobile`, its own commit)**
- Owned:
  - `technician/lib/features/materials/new_request_screen.dart`;
  - `technician/lib/features/materials/materials_repository.dart`;
  - `technician/lib/features/materials/materials_repository.g.dart` (regenerated only if the provider signature changes);
  - `technician/lib/features/work_orders/work_order_items.dart`;
  - `technician/lib/app/routes.dart`;
  - `technician/lib/app/router.dart`;
  - `technician/test/app_only_features_test.dart`.
- Change: the §4.5 mobile contract. The route `/materials/new-request` takes an optional `workOrder` query parameter (public id); `TechnicianRoutes.newRequest` is at `routes.dart:12`, routed at `router.dart:81-83`.
- Consumes: §4.5 (server deployed first; the field is optional, so either order is safe).
- Acceptance: the W3-MOBILE command of §6.

**W3-DOCS · backend (docs)**
- Owned:
  - `docs/api/mobile-v1.openapi.json`;
  - `CHANGELOG.md`;
  - `docs/operations/RELIABLE_JOBS.md`;
  - `docs/operations/JOBS_NOTIFICATIONS.md`;
  - `docs/business/billing.md`;
  - `docs/business/inventory.md`;
  - `docs/business/spk.md`;
  - `docs/user-guide/web.md`;
  - `docs/user-guide/teknisi.md`.
- Change:
  - Regenerate the OpenAPI document.
  - CHANGELOG: entry "### Fixed - Integrity hardening …" at the top, Keep a Changelog.
  - `RELIABLE_JOBS.md`, `JOBS_NOTIFICATIONS.md` (schedules `:5`/`:7`, failure notifications `:19`, idempotency `:46`/`:54`): lock TTLs, `schedule:clear-cache`, per-company alerts, `retry_after` 180, the web duplicate guard, optimistic versions, the company header.
  - `billing.md`: recurring generation `:138` counts cancelled periods; open payment pages close on cash or cancel; exception alert and the Tunggakan badge.
  - `inventory.md`: the reservation-mode text `:156-159` (SPK items do not reserve); the stale-count rule; SPK-linked app requests; consumption from the request warehouse.
  - `spk.md`: remove the reservation claims (`:114, :156, :163, :297, :303, :324`); add the item edit statuses.
  - `web.md`: SPK item form (`:518, :570, :575`); errors `:423, :435, :634-635`; Tunggakan badge; the 3 conflict messages.
  - `teknisi.md`: request form (`:382-401`), SPK picker, "Minta material".
- Acceptance: the W3-DOCS command of §6.

### 8.3 Ownership matrix check

| Slice | Files (NEW) | Note |
|---|---|---|
| W1-MECH | 7 (4) | sole owner of `bootstrap/app.php`, `RunsConcurrentWorkers.php` |
| W1-UI | 2 (1) | |
| W1-PAY | 13 (4) | sole owner of `NotificationResource.php` |
| W1-INVOICE | 22 (5) | |
| W1-BILLING-FE | 3 | |
| W1-OPS | 6 (1) | |
| W1-MISC | 8 (3) | |
| W2-INVENTORY | 14 (6) | |
| W2-SPK | 19 (5) | |
| W2-SPK-FE | 1 | |
| W2-LOCK-CUSTOMER | 15 (2) | |
| W2-LOCK-PEOPLE | 10 (1) | |
| W2-LOCK-CATALOG | 9 (1) | |
| W2-LOCK-TICKET | 4 (1) | |
| W2-LOCK-FE | 5 | |
| W2-MEDIA | 7 (2) | |
| W2-TENANT-BE | 2 (1) | |
| W2-TENANT-FE | 1 | |
| W3-QA | 4 (2) | |
| W3-MOBILE | 7 | separate repo |
| W3-DOCS | 9 | |

Total 168 entries. Listing every §8.2 path found no path twice. Every file a contract needs has an owner:
- the middleware and its registration;
- the helper and its 9 adopters, with each version source (4 resources, the settings prop, 2 existing resources);
- the header writer and reader;
- the migrations and the model constant with its FE mirror (`payment.ts`, `Return.tsx`);
- the job, the notification and its resource entry;
- the receivables prop and page;
- the API field and its OpenAPI regeneration;
- the lock helper used by the W2 tests.

### 8.4 Cross-lane split

**`master-lifecycle`** (`.scratch/master-lifecycle/design.md` §8.4, agreed with the previous IntegrityDesign run).

Integrity is the first owner. The `master-lifecycle` slice starts after the integrity slice is accepted and keeps the integrity lines:
- `OptimisticLock::assert` inside locks;
- the `expected_updated_at` rule;
- the `updated_at` resource field;
- `<ConflictBanner/>`;
- WO-first and customer-first locks.

`master-lifecycle` owns `destroy/restore` + index filters + lifecycle routes; integrity owns `update` + services.

| File | Integrity owner | Then (`master-lifecycle`) |
|---|---|---|
| `Modules/Billing/app/Services/BillingService.php`, `Modules/Billing/app/Http/Controllers/InvoiceController.php` | W1-INVOICE | W2-BILLING-BE (already listed) |
| `Modules/Billing/app/Models/PaymentAttempt.php` | W1-PAY | W2-BILLING-BE (**NEW row**) |
| `app/Http/Controllers/Admin/SubscriptionController.php`, `app/Http/Requests/Admin/UpdateSubscriptionRequest.php` | W2-LOCK-CUSTOMER | W2-SUBS-BE (listed) |
| `app/Services/Core/SubscriptionService.php` | W2-LOCK-CUSTOMER | W2-SUBS-BE (**NEW row**) |
| `app/Http/Controllers/Admin/CustomerController.php` | W2-LOCK-CUSTOMER | W2-CORE-BE (listed) |
| `app/Http/Resources/CustomerResource.php` | W2-LOCK-CUSTOMER | W2-CORE-BE (**NEW row**) |
| `app/Http/Controllers/Admin/EmployeeController.php` | W2-LOCK-PEOPLE | W2-PEOPLE-BE (listed) |
| `app/Http/Resources/EmployeeResource.php` | W2-LOCK-PEOPLE | W2-PEOPLE-BE (**NEW row**) |
| `Modules/Service/Http/Controllers/ServicePackageController.php`, `Modules/Service/Http/Requests/UpdateServicePackageRequest.php` | W2-LOCK-CATALOG | W2-SERVICE-BE (listed) |
| `Modules/Service/Http/Resources/ServicePackageResource.php` | W2-LOCK-CATALOG | W2-SERVICE-BE (**NEW row**) |
| `Modules/Inventory/app/Http/Controllers/ProductController.php`, `Modules/Inventory/app/Http/Requests/UpdateProductRequest.php` | W2-LOCK-CATALOG | W2-INVENTORY-BE (listed) |
| `Modules/Inventory/app/Http/Resources/ProductResource.php` | W2-LOCK-CATALOG | W2-INVENTORY-BE (**NEW row**) |
| `Modules/SPK/app/Http/Controllers/WorkOrderController.php`, `Modules/SPK/app/Services/SpkService.php` | W2-SPK | W2-SPK-BE (listed) |
| `Modules/Ticketing/app/Http/Controllers/TicketController.php`, `Modules/Ticketing/app/Services/TicketService.php` | W2-LOCK-TICKET | W2-TICKET-BE (listed) |
| `resources/js/Pages/Admin/Service/Packages/Edit.tsx` | W2-LOCK-FE | W2-SERVICE-FE (listed) |
| `resources/js/Pages/Admin/Employees/Index.tsx` | W2-LOCK-FE | W2-PEOPLE-FE (**NEW row**) |
| `resources/js/Pages/Admin/SPK/Show.tsx` | W2-SPK-FE | W2-DOCS-FE (**NEW row**) |
| `Modules/NetworkAsset/app/Http/Controllers/NetworkAssetController.php` | W1-MISC | W2-NETWORK-BE (**NEW row**) and `hardware-panel-ui` slice 03 |
| `Modules/NetworkAsset/app/Services/NetworkAccountService.php` | W1-MISC | W2-NETWORK-BE (**NEW row**) |
| `CHANGELOG.md`, `docs/business/billing.md`, `docs/user-guide/web.md` | W3-DOCS | W3-DOCS (append) |
| `docs/api/mobile-v1.openapi.json` | W3-DOCS | W3-DOCS (generated; the later lane regenerates) |

- **Drop** `resources/js/Pages/Admin/Subscriptions/Index.tsx` from the `master-lifecycle` table: integrity no longer touches it, because subscriptions have no edit form.
- Integrity-only files, which `master-lifecycle` never touches: `useToast.ts`, `ConflictBanner.tsx`, `app.tsx`, `bootstrap/app.php`, `ResolveActiveCompany.php`, `PreventDuplicateWebSubmit.php`, `OptimisticLock.php`, `Company.php`, `PromoBanner.php`, `CompleteSpkAction.php`, `CompanyService.php`, `CustomerStatusRecalculator.php`.
- Not touched by integrity: `HandleInertiaRequests.php`, `AdminLayout.tsx`, route files.

**`hardware-panel-ui`**: `NetworkAssetController.php`, integrity first (W1-MISC, `store()` only), then slice 03. Already in their design §7 and matrix.

**`tenant-integrations`** (agreed with `IntegrationsDesign-3` on 2026-10-18). Integrity is first; tenant-integrations follows after acceptance:

| File | Integrity owner | Tenant-integrations change after acceptance |
|---|---|---|
| `MidtransGateway.php` | W1-PAY | config lookups; `check()`; per-company notification URL; `client()`/`url()` take the company |
| `ReconcileMidtransPayments.php` | W1-PAY | `isEnabled()` per company |
| `NotificationResource.php` | W1-PAY | their push-test type |
| `CompanyController.php` | W2-LOCK-PEOPLE | the per-company `whatsapp.configured` prop |
| `Company/Settings.tsx` | W2-LOCK-FE | WhatsApp credential alert copy |

They never touch `PaymentAttemptService`, `BillingService` or `PaymentAttempt`. `admin.company.integrations.test` is in `EXCEPT`.

### 8.5 Main's verification per wave

- **After W1:**
  - the W1 commands of §6;
  - `./vendor/bin/sail artisan test --compact` (full suite once; `OpenApiContractTest` is expected red, R-3; double-post failures go to their owners, R-2);
  - `npm run typecheck && npm run lint && npm run build && node resources/js/lib/statuses.selfcheck.mjs`;
  - `./vendor/bin/sail pint --test` and `./vendor/bin/sail composer analyse` on touched files;
  - the live-DB runbook of §3.
- **After W2:** the same, with the W2 commands.
- **After W3:**
  - E2E under `flock /tmp/inbils-e2e.lock` (§6 W3-QA);
  - `OpenApiContractTest` green;
  - the `security-reviewer` pass of R-9;
  - mobile `flutter analyze` and `flutter test`;
  - the ledger row `integrity-hardening` in `docs/plans/INBILS_OMP_BACKLOG.md` and the `.scratch/integrity-hardening/issues/` tickets, one per slice (Main).
