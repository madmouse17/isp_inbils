# [t_4b7e2421] BACKEND: customer-sales-attribution-v1 dasar

Status: done
Role: backend
Card: `t_ae471280` (ledger `omp_status: needs_recovery`; Hermes card is a reference only, never mutated)

## Scope

Defined by `../spec.md` for the `backend` step of this lane. Acceptance is the approved spec (recommended options).

## Comments

- 2026-10-10 backend implemented per `../spec.md` (all recommended options, see "Approved decisions"). Assign and correct are served by the existing `POST /admin/customers` and `PUT /admin/customers/{id}` through the single writer `App\Actions\AssignCustomerAcquirerAction` (`onCreate`, `onUpdate`); eligibility is `App\Queries\Admin\CustomerAcquirerQuery::eligible` + rule `App\Rules\IsEligibleCustomerAcquirer`; capabilities `CustomerPolicy::{viewAcquirer,assignAcquirer,correctAcquirer}`. Migration `2026_10_10_000001_add_acquired_by_user_id_to_customers_table`. Tests: `CustomerAcquirerEligibilityTest`, `CustomerSalesAttributionTest`, `CustomerSalesAttributionVisibilityTest`, `RolePermissionSeederTest`. Status stays `in-progress` until tester, QA and reviewer pass.
