# Integrity hardening: close the remaining races found by the 2026-10-17 audit

Status: ready-for-agent
Ledger: `docs/plans/INBILS_OMP_BACKLOG.md` → "ISP roadmap lanes (2026-10-12)" row `integrity-hardening`
Source: owner 2026-10-17 ("ada ga data race … biar web aplikasi kita stable" → "ya" to the recommendation). Evidence: `local://race-audit.md` (RaceAudit, read-only, file:line per gap) and flow-audit findings F4-D06, F5-D16.

## Owner decisions (accepted)

Fix every P1/P2 and the cheap P3 items below, each red-first with a test that reproduces the interleaving (two transactions, or a replayed request), DB constraint preferred over app lock, app lock over optimistic version, optimistic version over UI guard. No feature changes beyond the fix. #12 (soft-delete uniques, restore) belongs to `master-lifecycle`, not here.

## Items (numbers = race-audit table)

| # | Fix | Files (owner) |
|---|---|---|
| 1 | Technician-app inventory request carries `source_type=work_order`/`source_id` of the active SPK (API contract: `work_order_id` in the request payload, resolved by public id, must be the technician's own active SPK); `CompleteSpkAction::consumeStock` consumes from the issued IR's source location when covered, reads coverage under `lockForUpdate`; `InventoryRequestService::issue` locks the source WO and refuses `completed/cancelled`. | Modules/SPK `CompleteSpkAction`, Modules/Inventory `InventoryRequestService`, `StoreInventoryRequestRequest`, API controller for technician requests, OpenAPI (additive), mobile technician app request form (separate repo slice) |
| 2 | `BillingService::recordPayment`/`cancel` expire open `payment_attempts` (status `cancelled_locally`, Snap page closed via Midtrans `cancel` when a transaction exists); settle path that hits an unpayable invoice creates an `exception` AND notifies billing staff (database + FCM) with the amount and order id; receivables page shows a badge for open exceptions. | Modules/Billing `BillingService`, `PaymentAttemptService`, `MidtransGateway` (cancel endpoint), one notification, Invoices/Show + Receivables UI |
| 3 | Recurring generation exists-check includes `cancelled`; DB unique `(company_id, subscription_id, period)` on invoices for recurring rows (nullable for manual); migration additive + duplicate check on the dev DB before adding. | `BillingService`, migration, `RecurringInvoiceRaceGuardTest` |
| 4, 8 | `PreventDuplicateWebSubmit` middleware for the `web` POST/PUT/PATCH/DELETE on admin routes: `Cache::add("web.dup.{user}.{sha1(method+path+body)}", 1, 10)` → 409 with Indonesian toast "Permintaan yang sama baru saja dikirim"; skip idempotent GET and routes flagged `->withoutMiddleware`. | `app/Http/Middleware`, `bootstrap/app.php`, toast handling in `useInertiaForm`, test |
| 5 | Adjustment approval compares live `stock.quantity` with `old_quantity` under the stock lock → 409 "Stok berubah sejak dihitung; hitung ulang." | `StockService`, UI message |
| 6 | Remove "Jumlah dicadangkan" from SPK items (field, request rule, release path) since nothing reserves it; migration keeps the column nullable (no drop on live DB). | Modules/SPK, `SPK/Show.tsx` |
| 7 | SPK items editable only in `draft/assigned/in_progress`; delete under WO lock; `reconcileUsedQuantitiesFromMaterialReturns` under the WO lock. | `WorkOrderController`, `SpkService`, `MaterialReturnService` |
| 9 | Per-tenant and per-subscription try/catch in `billing:generate`, `billing:check-overdue`, `billing:suspend-overdue` (pattern of `SendInvoiceWhatsAppReminders`); failure alert goes to the admins of that company only. | Modules/Billing console commands |
| 10 | `withoutOverlapping(10)` for per-minute/15-minute commands, `120` daily; `php artisan schedule:clear-cache` in `docker/app/entrypoint.sh` before `schedule:work`. | `routes/console.php`, entrypoint |
| 11 | `expected_updated_at` on every edit form (customer, subscription, SPK, ticket, draft invoice, product, employee, package, company settings): FormRequest rule + service compare under lock → 409 "Data diubah pengguna lain; muat ulang." UI: `useInertiaForm` sends the prop and shows a reload banner. | one shared `OptimisticLock` trait/helper + per-module adoption |
| 13 | `terminate` re-checks status inside the lock; `CustomerStatusRecalculator` reads subscriptions `lockForUpdate`. | `SubscriptionService`, recalculator |
| 14 | Drop the pre-lock in `InventoryRequestService::approve` (:291-297); `DB::transaction(fn, 3)` on inventory/billing/SPK write paths. | Inventory, Billing, SPK services |
| 15 | Stock availability compare via bcmath (`Money::compare`), not floats. | `StockService` |
| 16 | Status checks moved into the service under lock for invoice update/destroy, SPK update, ticket update (+ recompute SLA deadlines when priority changes on an open ticket), announcement publish. | respective services |
| 17 | `retry_after` 180 > longest job timeout; `$uniqueFor` on `SendAnnouncementNotifications`. | `config/queue.php`, job |
| 18 | Active company public id in Inertia shared props → hidden field on every form → `ResolveActiveCompany` compares → 409 "Perusahaan aktif berubah di tab lain." | middleware, `AdminLayout`/`useInertiaForm` |
| 19 | Media replace: store new, delete old in `DB::afterCommit`; evidence upload idempotency key on web. | `CompanyLogoService`, `PromoBannerService`, `SpkService`, `DocumentService` |
| 20 | (a) number generation inside the create transaction; (b) feed cache flush after commit; (c) bell/push reminder dedupe per invoice+stage; (d) check-then-insert 422 not 500. | as listed in the audit |

## Non-goals

No schema drops on the live DB; no new libraries; no UI redesign beyond messages/banners.

## Tickets

01-design (architect): slice the table into waves with one owner per file; then slices.
