# BACKEND: company-scoped validation for report and dispatch filters (security)

Status: done
Role: backend
Blocked by: none
Spec: `../spec.md`

## Scope

Agent SecScope (running 2026-10-14): Dispatch assign (`DispatchQueueController.php:96-98`), Audit Log `user_id` + `AuditLogQuery` scope, Stock Card `product_id`/`location_id`, Stock Movements filters, Invoices `customer_id` filter, Sales `acquirer_id`. Verify, fix proven holes with company-scoped validation/eligibility, regression test each (spec Q9).

## Acceptance criteria

- [x] Per item: hole yes/no with evidence, fix, failing-then-passing test.

## Comments

- 2026-10-14 (SecScope, summarised by orchestrator): no hole in any item; no source change. Evidence:
  - Dispatch assign: `TicketService.php:125-132` and `SpkService.php:115-123` resolve the assignee inside `$item->company_id` with the same eligibility as `DispatchQueueQuery` (404 otherwise).
  - Audit log: `AuditLogQuery.php:30-92` scopes by the actor's company (foreign company 403, foreign `user_id` empty, search bound).
  - Stock Card 404s a foreign product and scopes rows; Stock Movements and Invoices query `BelongsToCompany` models; Sales 403s a foreign acquirer.
  - Regression tests added (they passed on the first run because nothing was open): `DispatchQueueTest::test_dispatch_assign_rejects_a_user_of_another_company_under_every_assignee_field`, `::test_dispatch_assign_rejects_same_company_users_the_eligible_lists_do_not_offer`, `AuditLogTenantIsolationTest::test_report_page_and_export_never_list_another_companys_activity`, `StockCardReportRouteTest::test_stock_card_location_filter_never_reaches_a_foreign_company_row`, `StockServerTableExportTest::test_movement_filters_with_foreign_ids_never_return_foreign_rows`, `InvoiceServerTableExportTest::test_customer_filter_with_a_foreign_customer_id_never_returns_foreign_rows`.
  - Parity gap (not a leak): dispatch assign validates ids as `integer` only, unlike `AssignTicketRequest`/`AssignWorkOrderRequest`. Ticket 05 adds the company-scoped `exists` rule while it rewrites that page's controls.
