# Installation SPK on onboarding — delta vs isp_ari

Status: no-gap (conclusion; nothing to approve, no tickets)
Ledger: none
Source: `/tmp/isp_ari_gap.md` row "Automatic Installation SPK" (rated PARTIAL) and backlog rank 8 — **the scout's rating is wrong; inbils already does this and more.**

## Conclusion: no gap

inbils `CreateCustomerOnboardingAction` already creates an installation SPK automatically inside the customer-creation transaction, which is exactly what isp_ari's `CreateCustomer` does. No ticket is warranted.

## Evidence

| Behaviour | isp_ari | inbils |
| --- | --- | --- |
| Trigger | `POST` create customer → `app/Actions/Customers/CreateCustomer.php:37-92` | `POST /admin/customers` → `CustomerController.php:101` → `app/Actions/CreateCustomerOnboardingAction.php:19-60` |
| One DB transaction with customer + address + service | `CreateCustomer.php:44` (`DB::transaction`) | `CreateCustomerOnboardingAction.php:21` (`DB::transaction`) |
| Initial service created | status `pending_installation` (`:70-73`) | subscription `pending` via `SubscriptionService::create` (`:29`, status default `SubscriptionService.php:38`) |
| SPK type / links | `installation`, customer + service (`:78-88`) | `type=installation`, `customer_id`, `subscription_id`, `location_id = serving_pop_id` (`:35-47`) |
| SPK initial status | `issued` (`CreateCustomer.php:87`, ADR-018 `docs/01-decisions.md:336`) | `generated` (`:40`; isp_ari's `issued` equivalent — `draft→generated→assigned→…` in `Modules/SPK/app/Services/SpkService.php:52`) |
| SPK provenance | none | `source = subscription` (`:44`), numbered via `NumberSequenceService` (`:36`), audited `customer.onboarded` (`:49-53`) |
| Adding another service to an existing customer spawns an SPK | **No** (`AddServiceToCustomer.php:31-65`, no work-order call) | **No** (`SubscriptionController::storeForCustomer :51-62` only calls `SubscriptionService::create`) — parity |
| Completing the SPK activates the service | **No**, deliberately manual (`docs/01-decisions.md:85,337`) | **Yes** — `CompleteSpkAction.php:39,291-298` activates a `pending` subscription (inbils is ahead) |
| Package/address change updates the open SPK | not applicable | yes — `SubscriptionController::update :84-110` re-points a draft/generated SPK |
| Test coverage | isp_ari tests (not audited) | `tests/Feature/CustomerAccountCreationTest.php:101-117` asserts SPK type `installation`, status `generated`, `location_id`; e2e `tests/e2e/customer-subscription.spec.ts:16-89` |

## Corrections to the scout report

- Row "Automatic Installation SPK": **PARTIAL is wrong → PRESENT.** The scout claimed SPK is "not auto-spawned unconditionally upon base customer record creation"; in inbils the only create route, `CustomerController::store`, delegates to the onboarding action (`CustomerController.php:101`), which always spawns the SPK, and the request requires the subscription block (`StoreCustomerRequest.php:64`). The scout's dependency list ("`CustomerController@store`, `SubscriptionController@store`") shows the same misreading: `CustomerController@store` already delegates to the onboarding action.
- Backlog rank 8 ("Automatic Installation SPK Generation") should be deleted from the ranked list.

## Observations beyond isp_ari parity (not a gap, not a ticket)

- Adding a second subscription to an existing customer (`storeForCustomer`) creates no installation SPK in either system. If the owner wants one in inbils, that is a new inbils-own requirement (Owner decision: auto-SPK on every new `pending` subscription vs only at onboarding); open a lane only on request.
- `docs/business/billing.md:186-187` documents auto-reactivate/auto-suspend that is not implemented — unrelated to this comparison; see `midtrans-payment` and `customer-status-rollup` out-of-scope notes.

## What was checked

Read both implementations end to end (`CreateCustomer.php` and `AddServiceToCustomer.php` in isp_ari; `CreateCustomerOnboardingAction.php`, `SubscriptionService.php`, `SubscriptionController.php`, `CompleteSpkAction.php`, `CustomerAccountCreationTest.php` in inbils). Not run: the test suites (read-only analysis per assignment).
