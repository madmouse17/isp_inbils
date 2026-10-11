# Customer Status Rollup — customer status derived from subscriptions (isp_ari ADR-011)

Status: done
Ledger: none yet (new lane from the isp_ari gap analysis of 2026-10-11; add a ledger entry when approved)
Source: `/tmp/isp_ari_gap.md` row "Customer Status Rollup" (verified; two corrections below)

> Nothing in this lane may start until the owner answers the decisions below. "setuju rekomendasi" approves the recommended option of every item.

Approved by owner 2026-10-11: recommended option of every decision ("setuju rekomendasi").

## Owner decisions

1. **Q1 — Where does the derived status live?**
   (a) New column `customers.customer_status` (enum, derived, not fillable), `customers.is_active` untouched. (b) Repurpose `is_active` as the derived flag (active ⇢ true, everything else false). (c) Compute on read, no column.
   **Recommend (a).** `is_active` is a staff-edited switch today (`CustomerController.php:188` writes it on update, `UpdateCustomerRequest.php:36`) and was copied once into the portal user at creation (`CustomerService.php:24`); overloading it would silently change the Customers list filter (`CustomerController.php:270`, `CustomerIndexQuery.php:27`) and lose the owner's manual off-switch. A column keeps list filtering/sorting cheap (index `(company_id, customer_status)`), which (c) cannot. Tradeoff: two status fields on one customer until the UI labels them clearly (see Q5).
2. **Q2 — Status vocabulary and aggregation rule.**
   isp_ari ADR-011 (`isp_ari/docs/01-decisions.md:141-152`, code `isp_ari/app/Services/CustomerStatusRecalculator.php:13-31`): `active` if any service active; else `suspended` if any suspended; else `prospective` if every service is still pending; else `terminated` if every service is terminated; else `inactive`. inbils subscription statuses are `pending|active|suspended|terminated` (DB ENUM, `database/migrations/2026_08_11_163004_enforce_latest_closed_values_as_enums.php:14`), so `pending` maps to isp_ari's `pending_installation`; inbils has no `scheduled`.
   **Recommend: adopt the ADR-011 rule 1:1 with `pending`→`pending_installation`**, enum `prospective|active|inactive|suspended|terminated`. Soft-deleted subscriptions are ignored (the `subscriptions()` relation already excludes them, `ServiceSubscription` uses `SoftDeletes`). Tradeoff: a customer whose subscriptions are all `terminated` plus one `pending` becomes `inactive` (ADR default branch) — intended, matches isp_ari.
3. **Q3 — Customer with zero subscriptions.** isp_ari's code returns `inactive` for an empty set (falls to `default`), but there a customer is never created without a service (`CreateCustomer.php:55-76`). inbils also always creates one at onboarding (`CreateCustomerOnboardingAction.php:29`) but seeders, factories and imports can leave none (`database/factories/CustomerFactory.php`, `E2eFixtureSeeder.php`).
   (a) `prospective` (b) `inactive` (isp_ari literal). **Recommend (a)**: a registered customer with nothing subscribed yet is a prospect; `inactive` would suggest churn. Tradeoff: deliberate deviation from the isp_ari code (not from ADR-011 text, which does not cover the empty set).
4. **Q4 — Trigger.** (a) Explicit call to one recalculation service from every subscription mutation inside the same transaction (isp_ari style: `CreateCustomer.php:76`, `AddServiceToCustomer.php:54`, `ChangeServiceStatus.php:88`). (b) Eloquent observer on `ServiceSubscription`. (c) Scheduled job only.
   **Recommend (a) + an idempotent `customers:recalculate-status` Artisan command** for backfill/drift repair. All inbils status writers already funnel through `SubscriptionService` (`create` `:16`, `activate` `:52`, `suspend` `:72`, `reactivate` `:89`, `terminate` `:108`; `SubscriptionController.php:84` strips `status` from generic updates; `CompleteSpkAction.php:291-298` calls `SubscriptionService::activate`), and the codebase uses explicit static services rather than observers. Tradeoff: direct writes (seeders, tinker, tests using factories) bypass it — repaired by the command, and the tester ticket pins the writer list.
5. **Q5 — UI exposure.** (a) Show the derived status badge + filter on Customers Index/Show, keep the existing Active/Inactive account badge relabelled "Account". (b) Backend only. **Recommend (a)**: otherwise the new field is invisible and the rollup has no user-facing value (`resources/js/Pages/Admin/Customers/Index.tsx:100-106`, `Show.tsx:101`).
6. **Q6 — Backfill.** (a) Migration adds the column and fills it with one `UPDATE … CASE` over `service_subscriptions` (no user input, reversible by dropping the column). (b) Artisan command run once at deploy. **Recommend (a)**, mirroring the Head Company membership backfill (`.scratch/head-company/spec.md`, Q11); the command from Q4 stays for repair. Rehearse on `inbils_test`/staging first.
7. **Q7 — Concurrency.** Subscription transitions lock only the subscription row (`SubscriptionService.php:55`), so two concurrent transitions of different subscriptions of one customer could each compute from a stale set. **Recommend:** recalculation locks the customer row (`lockForUpdate`) and reads subscriptions after taking the lock. Tradeoff: one extra row lock per transition; negligible.
8. **Q8 — Audit.** **Recommend** `AuditService::log('customer', 'status_changed', {from,to})` only when the derived value actually changes (isp_ari saves only on change, `CustomerStatusRecalculator.php:34-36`).

## Corrections to the scout report

- Status vocabulary: the scout wrote `candidate`; the real isp_ari value is `prospective` (`isp_ari/database/migrations/2026_07_27_000006_create_customers_table.php:27,40`, `isp_ari/app/Models/Customer.php:18`).
- "PARTIAL": accurate, but understated — inbils has **no** derived status at all; `customers.is_active` is a manually edited boolean and is never touched by subscription transitions (grep of `SubscriptionService`, `CompleteSpkAction`, `BillingService` shows no customer write).

## Current implementation map

inbils
- `app/Models/Core/Customer.php:40-44` — `is_active` fillable boolean; no status field. `subscriptions()` relation at `:60`.
- `app/Models/Core/ServiceSubscription.php:29` — `status` fillable; DB enum `pending|active|suspended|terminated` default `pending` (`2026_08_11_163004_enforce_latest_closed_values_as_enums.php:14`).
- `app/Services/Core/SubscriptionService.php` — only writer of status; every method runs in `DB::transaction` and audits.
- Callers: `SubscriptionController` (`storeForCustomer :51/:59`, `activate :110`, `suspend :120`, `reactivate :132`, `terminate :142`), `CompleteSpkAction.php:297`, `CreateCustomerOnboardingAction.php:29`.
- Direct writers (outside the service): `database/seeders/E2eFixtureSeeder.php:148,318`, factories (`ServiceSubscriptionFactory`).
- Customers UI/list: `CustomerController.php:188,234,249,270`, `CustomerIndexQuery.php:27`, `Pages/Admin/Customers/{Index,Show,Create,Edit}.tsx`.
- Docs: `docs/business/customer.md:121,145-147` (subscription lifecycle; no customer-level status rule).
- Prior-art tests: `tests/Feature/SubscriptionServiceTest.php`, `CustomerPageTest.php`, `CustomerAccountCreationTest.php`.

isp_ari
- `app/Services/CustomerStatusRecalculator.php:13-37` (sole writer of `customers.customer_status`), wired at `CreateCustomer.php:76`, `AddServiceToCustomer.php:54`, `ChangeServiceStatus.php:88`; `Customer.php:21-22` keeps the column non-fillable; ADR-011 `docs/01-decisions.md:141-152`.

## Problem Statement

Operations staff read a customer's overall state from the Customers list, but that list only shows a manual Active/Inactive switch. A customer whose every subscription is suspended or terminated still shows "Active", and a brand-new customer with only a pending installation looks identical to a paying one. Reports and follow-up lists built on the customer status are therefore wrong until someone edits the flag by hand.

## Solution

Each customer carries a derived `customer_status` computed from its subscriptions by one recalculation service and kept current by every subscription change. The Customers list and detail show it next to the existing account switch, and the list can be filtered by it.

## User Stories

1. As a customer-service agent, I want a customer's status to reflect the state of their subscriptions, so that I do not quote service to someone who is suspended.
2. As a manager, I want to filter customers by Prospective / Active / Suspended / Inactive / Terminated, so that I can run collection and win-back lists.
3. As a billing clerk, I want a customer to turn Suspended automatically when their last active subscription is suspended, so that the list matches what the NOC did.
4. As a billing clerk, I want the customer to return to Active when any subscription is reactivated, so that I do not fix it by hand.
5. As a sales user, I want a new customer with only a pending installation to show as Prospective, so that I can see who is still waiting for installation.
6. As a field supervisor, I want the customer to become Active when the installation SPK completes and activates the subscription, so that the status follows the field result.
7. As a manager, I want a customer whose subscriptions are all terminated to show Terminated, so that churn is visible.
8. As an admin, I want the manual Active/Inactive account switch to keep working independently (labelled Account), so that I can still block a customer's portal login.
9. As an admin, I do not want the derived status to be editable through the customer form or API, so that it cannot drift from the subscriptions.
10. As an auditor, I want a log entry when a customer's status changes (from → to), so that I can trace which subscription change caused it.
11. As an operator, I want a command that recomputes every customer status for a company, so that I can repair drift after a bulk import.
12. As an operator, I want the migration to fill existing customers correctly, so that the list is right on the day of release.
13. As a developer, I want one place that decides customer status, so that no controller invents its own rule.
14. As a user of company B, I never see or recompute company A's customers.

## Implementation Decisions

- Schema: `customers.customer_status` enum (`prospective|active|inactive|suspended|terminated`), default `prospective`, index `(company_id, customer_status)`. Not mass assignable; written only by the recalculator (Q1, Q2).
- One service `CustomerStatusRecalculator` in the core Services layer (static-service style like `SubscriptionService`); input a customer (id), output the new status; locks the customer row, reads non-deleted subscription statuses, applies the Q2/Q3 rule, saves only on change, audits only on change (Q7, Q8).
- `SubscriptionService::create|activate|suspend|reactivate|terminate` call the recalculator inside their existing transaction after the subscription write. Subscription soft-delete (if/when exposed) must call it too.
- Artisan command `customers:recalculate-status {--company=}` iterates active companies with `CompanyService::runAs` (same pattern as the billing commands), chunked; idempotent; `--dry-run` prints the diff.
- Customer API/resource exposes `customer_status`; `UpdateCustomerRequest`/`StoreCustomerRequest` ignore it; Customers Index gets a status filter and badge; Show gets the badge; `is_active` is labelled "Account".
- No new permission: visibility follows `customer.view`.
- No change to billing generation, SLA, or reporting formulas in this lane.

## Testing Decisions

- Test external behaviour through `SubscriptionService` and the HTTP routes, not the SQL. Seam: `SubscriptionService` transitions → `customers.customer_status`.
- Unit/feature matrix over the ADR rule: no subs, 1 pending, pending+active, active+suspended, suspended only, suspended+terminated, all terminated, terminated+pending, soft-deleted subscription ignored.
- Feature: onboarding POST leaves `prospective`; SPK completion makes it `active`; suspend/reactivate/terminate flip it; the customer update request cannot set it; tenant isolation (command in company A leaves B untouched); migration backfill on a seeded DB.
- E2E: one Playwright flow extending `tests/e2e/customer-subscription.spec.ts` (create customer → badge Prospective; activate → Active; filter by status).
- Prior art: `SubscriptionServiceTest`, `TenantBoundaryTest`, `Billing/TenantRecheckTest`, `tests/e2e/customer-subscription.spec.ts`. MySQL `inbils_test`, run `tests/e2e/setup.php` before Playwright.

## Out of Scope

- Changing `is_active` semantics or the portal user's `is_active`.
- Auto-suspending subscriptions on overdue, or auto-reactivating on payment (documented at `docs/business/billing.md:186-187` but not implemented; separate decision).
- Customer-level lifecycle actions (manual "terminate customer").
- Reporting changes that would start using `customer_status`.

## Further Notes

- Ticket order: 01 backend → 02 ui → 03 tester → 04 reviewer. Dependencies on other lanes: none; touches `SubscriptionService`, which `midtrans-payment` does not.
- Update `docs/business/customer.md` with the aggregation rule when shipped; record Q1-Q8 as ADR-NNN under `docs/architecture/decisions/` (next number) only if the owner wants it, per `docs/agents/domain.md`.
