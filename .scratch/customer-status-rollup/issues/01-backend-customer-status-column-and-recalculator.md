# BACKEND: customer_status column, recalculator service, subscription wiring, backfill command

Status: needs-owner-approval
Role: backend
Blocked by: none (starts only after owner approves `../spec.md` Q1-Q8)
Card: none (new lane; no Hermes card)

## Scope

Implements Q1-Q4, Q6-Q8 of `../spec.md`.

- Migration: add `customers.customer_status` enum (`prospective|active|inactive|suspended|terminated`, default `prospective`, index `(company_id, customer_status)`); backfill existing rows with one `UPDATE … CASE` over non-deleted `service_subscriptions`; `down()` drops the column. Not in `Customer::$fillable`.
- Service `CustomerStatusRecalculator` (core Services layer): lock customer row, read non-deleted subscription statuses, apply the ADR-011 rule (`pending` = pending_installation; zero subscriptions → `prospective`), save only on change, `AuditService::log('customer', 'status_changed', {from,to})` only on change.
- Call it inside the existing transaction of `SubscriptionService::create`, `activate`, `suspend`, `reactivate`, `terminate`.
- Artisan `customers:recalculate-status {--company=} {--dry-run}`: iterate active companies via `CompanyService::runAs`, chunked, idempotent, prints changes.
- Expose `customer_status` on `CustomerResource`; `StoreCustomerRequest`/`UpdateCustomerRequest` must not accept it.
- Customers list server contract: `CustomerIndexQuery`/`CustomerController` accept a `status` filter (one of the five values) and `customer_status` as a sortable column, preserving the existing `is_active` filter.

Non-goals: UI, billing/SLA/reporting changes, touching `is_active`, auto-suspend/reactivate.

## Acceptance criteria

- [ ] Migration up/down both run on `inbils_test`; existing customers get the correct status (checked against a hand-computed set including a soft-deleted subscription).
- [ ] After each of create/activate/suspend/reactivate/terminate through `SubscriptionService` and through the existing HTTP routes, `customers.customer_status` equals the ADR-011 rule for that customer's subscriptions.
- [ ] Onboarding (`POST /admin/customers`) ends `prospective`; completing the installation SPK (`CompleteSpkAction`) ends `active`.
- [ ] `PUT /admin/customers/{id}` and `POST /admin/customers` with a `customer_status` field do not change it.
- [ ] A status audit row exists only when the value changed; no row when unchanged.
- [ ] `customers:recalculate-status --dry-run` writes nothing; a real run is idempotent (second run reports 0 changes) and does not touch another company's customers.
- [ ] Pint and scoped Larastan clean on touched files; no direct `customer_status` write outside the recalculator (grep gate in the tester ticket).
- [ ] `GET /admin/customers?status=suspended` returns only suspended customers of the current company and sorts by `customer_status` when requested.
