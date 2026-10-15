# QA/REVIEW: gates, review, release

Status: done
Role: qa/reviewer
Blocked by: 01–10
Spec: `../spec.md`

## Scope

Pint, Larastan on changed files, tsc, ESLint, all selfchecks incl. copy selfcheck over the whole of `resources/js`, full PHPUnit on `inbils_test`, `npm run build` + full canonical chromium E2E, security review (new search endpoints/props: scope, escaping, limits), Docker refresh + `/up` `/ready`, CHANGELOG, commit + push.

## Acceptance criteria

- [x] All gates green with evidence; review SIGNED; pushed.

## Comments

### Security follow-up (WaveSecFix, 2026-10-15)

Answer to the NEEDS-CHANGE of ModuleWaveSecReview. Every item was written test first: the new and changed tests (11 classes) ran against the untouched code with `Tests: 31 failed, 118 passed (1653 assertions)`, then against the fix (below). No commit, no migration, no dependency change.

| # | Sev | Finding | Fix | Tests (red first) |
|---|-----|---------|-----|-------------------|
| 1 | MEDIUM | An invoice accepted a subscription of another customer (or a soft-deleted one) | `StoreInvoiceRequest`: `subscription_id` is `bail, integer`, `exists` in the company `withoutTrashed()`, then `MatchesParentAttribute` (subscription's `customer_id` = submitted `customer_id`). `bail` matters: the pair rule reads the row without the company boundary, so a foreign id would otherwise get a second, different message. `InvoiceController::create` narrows `subscriptionOptions` by `?customer_id=`; `Invoices/Create.tsx` picks the customer with the same `pickCustomer` as `SPK/Create.tsx` (clears the subscription, reloads `subscriptionOptions`). | `InvoiceSubscriptionPairTest` (6): own pair saved, other customer 422, soft-deleted 422, foreign and unknown id get the same single message, create narrows by customer, no customer = company list |
| 1b | MEDIUM | SPK checked the customer/subscription pair only for installation, upgrade, relocation; update never | `StoreWorkOrderRequest`: the pair is checked whenever both are sent, for every type; `subscription_id` is `integer` and live. `UpdateWorkOrderRequest`: same check on the pair the request would store (a side it leaves out is the SPK's current one), skipped when the request names neither. `CreateInvoiceFromSpkAction` copies the SPK pair, so it stays consistent without a change. | `WorkOrderSubscriptionPairTest` (14, incl. a 4-type dataset on create): other customer 422 for every type, soft-deleted 422, matching pair saved, maintenance without either side still possible, update full pair and one-sided pair, title-only update untouched, foreign vs unknown id |
| 2 | LOW | A request for goods named any SPK/ticket of the company, the picker offered only what the person may open | `InventoryRequestSources::workOrderBase(User)` / `ticketBase(User)` are the one visibility query; the pickers and the `source_id` rule in `StoreInventoryRequestRequest` both use them (the rule also rejects `source_id=0`, which the old `if (! $value) return;` let through). | `InventoryPickersTest::test_a_source_must_be_a_record_the_person_may_already_open`, `::test_a_person_who_cannot_view_spk_or_tickets_cannot_name_one_as_the_source` |
| 3 | LOW | A technician's Evaluasi page offered every evaluable colleague (name, e-mail) in `employeeOptions` / `selectedEmployee` | `EvaluationController::filterableEmployees()`: a technician-role actor gets `whereKey(self)`, the same predicate `filteredQuery` uses for the rows. The create page keeps the full picker. | `EvaluationTechnicianScopeTest` (4) |
| 4 | LOW | `unique` answered for a user of another company ("already has a profile": a cross-tenant existence oracle); an employee update to a user who already has a profile was a database 500 | `StoreEmployeeRequest`: `bail` before `exists`/`unique`. `UpdateEmployeeRequest`: `bail` and `Rule::unique('employee_profiles', 'user_id')->ignore($profileId)` with the Store message. | `EmployeeTest`: foreign user with a profile gets one error and not "sudah punya profil"; update to a taken user is 422 and unchanged; update with the own user passes |
| 5 | LOW | The SPK page sent `EmployeeResource::collection(technicians)` (e-mail, roles, last login, 2FA, HR notes, vehicle) to anyone who could open an SPK | `WorkOrderController::assignableTechnicians($wo)`: `[]` unless `Gate::allows('assign', $wo)`, else `{id (user to assign), name, employee_number, organization, phone}` with `user:id,name` and `organization:id,name` eager loads. Used by `show` and `edit`. `SPK/Show.tsx`: `technicians` is that plain list (type `TechRow`, option label is the user name as before). | `WorkOrderTechniciansPropTest` (4): slim list for the assigner, nothing else of the person on the page, `[]` for the technician, reassign needs `spk.reassign`, edit follows the rule |
| 6a | LOW | `SearchOptions` turned `?x_search[]=a` into a 500 (array to string) | `is_string($raw) ? trim($raw) : ''` | `SearchOptionsHttpTest` (4 shapes on the invoice customer picker give 200 and `[]`; a string still finds options) |
| 6b | LOW | `user_id` / `acquirer_id` of `ReportFilterRequest` had no company boundary | `user_id`: `exists:company_user,user_id` for the company (members, as the audit picker lists them); `acquirer_id`: `exists:users,id` of the company. Both 422 with "Pengguna tidak ditemukan." / "Petugas penjualan tidak ditemukan." and the same text for a foreign and an unknown id; the report's own 403 stays for a user of the company who is no acquirer. Page, export and drill requests inherit it. | `ReportFilterUserScopeTest` (9); five older tests that expected an empty page or a 403 for a foreign id now expect the 422 (`AuditLogTenantIsolationTest` x2, `ReportPickerOptionsTest`, `SalesKpiReportTest` x2) |
| 6c | LOW | The audit log filtered `user_id` (and the causer name search) by `users.company_id`, the picker lists `company_user` members: choosing a member homed in another company (a head admin who switched in) gave an empty list | `AuditLogQuery::query()`: the `user_id` filter and the causer search read `company_user` of the effective company. Production grants every user the home membership (`CompanyMembershipService::grant`, backfill `2026_10_11_000003`), so nothing changes for home users. | `ReportFilterUserScopeTest::test_the_audit_log_filters_by_a_member_who_is_homed_in_another_company` (page and export), `::test_the_audit_log_search_finds_the_activity_of_a_member_homed_in_another_company_by_name`; `AuditLogTenantIsolationTest` causer-search fixture now carries the memberships |

**Evidence** (repo root; tests through `flock /tmp/inbils-e2e.lock env DOCKER_CONTEXT=default ./vendor/bin/sail exec -T -u sail laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact …`)

- Red: the 11 new/changed classes before any production edit: `Tests: 31 failed, 118 passed (1653 assertions)`.
- Green: `tests/Feature/{Billing,SPK,Inventory,Reporting,Support,Admin,Tenant,TenantIsolation}` plus `EmployeeTest`, `ReportFilterValidationTest`, `AdminMenuAuthorizationTest`, `AdminRouteTest`, `IpamOdnLifecycleTest`, `PrivateMediaAccessTest`, `TenantBoundaryTest`: `Tests: 1486 passed (19368 assertions)`, 1030.73 s.
- Green again after the last edit (`AuditLogQuery` causer search, its fixture and test): `tests/Feature/Reporting`, `ReportFilterValidationTest`, `WorkOrderTechniciansPropTest`: `Tests: 338 passed (8792 assertions)`, 241.52 s.
- `./vendor/bin/pint --test` on the 13 production files and 11 test files: `pint: ok`.
- `npx tsc --noEmit` (whole frontend) and `npx eslint` on `Invoices/Create.tsx` and `SPK/Show.tsx`: no output, exit 0.
- Larastan (level 5) on the touched production files: no error on a line this change wrote; the 11 left are older ones in code not touched (`SORTABLE` unused in `EvaluationController` and `WorkOrderController`, untyped relations `Model::$name` in `EvaluationController` x2 and `WorkOrderController`, `$subscription->customer_name` and `$company?->settings` in `InvoiceController`, `$this->route('employee')?->id` in `UpdateEmployeeRequest`, `SearchOptions::matches()` return type).

**Behaviour changes to know** (the E2E agent was told): the Log Audit `?user_id=` and Penjualan `?acquirer_id=` of another company or of nobody are a 422 / back-with-errors instead of an empty page / 403; an SPK or invoice with a subscription of another customer is a 422 for every type; the invoice form offers only the picked customer's subscriptions once a customer is picked; the SPK `technicians` prop is a plain list.

**Found, not fixed (owner decision)**

- `GET /admin/spk/{id}/edit` is a 500 in the browser: `WorkOrderController::edit()` renders `Admin/SPK/Edit`, which has no page (`Unable to locate file in Vite manifest: resources/js/Pages/Admin/SPK/Edit.tsx`; the editor's route, `update` and the policy exist, nothing links to it). The method still sends unbounded customer, subscription and location lists. Either delete `edit` and its route or build the page on the pickers of `SPK/Create.tsx`.
- The causer search of the audit log, like every `like "%{$term}%"` of the older queries, does not escape `%` and `_` in the term (bound, so no injection; `SearchOptions` does escape).

**Not verified**: the two page edits in a browser (type check and lint only; the full E2E run belongs to WaveE2EFix2). `ModuleWaveSecReview` has not seen these fixes: a second pass is still needed before the review is SIGNED.

2026-10-14, orchestrator (gates):
- Pint `--dirty` passed; `npx tsc --noEmit` 0; ESLint 0 on changed TS/TSX; `node --test resources/js/lib/*.selfcheck.mjs` 76 pass / 0 fail; `COPY_SCAN=all` copy selfcheck clean (WaveE2EFix2).
- Security: ModuleWaveSecReview NEEDS-CHANGE (1 Medium, 4 Low, minors) → WaveSecFix applied all with failing-first tests (31 failed → green; 1486 passed broad batch); re-pass found one functional regression (`EvaluationController::employeeOption` mutating the shared builder) → fixed with `clone` + regression test `test_an_active_employee_filter_still_lets_the_picker_find_other_employees`; remaining minors parked in `docs/PARKING_LOT.md`. Verdict on the security items: all resolved.
- Full chromium E2E (after security fixes + hasTechnicians change): 250 passed, 4 skipped (RADIUS lab), 0 failed, 0 did not run (15.5m).
- Full PHPUnit: 3380 passed, 3 failed → `WhatsAppWebhookTest` two cases were date bombs (consent created with the real clock after the fixed STOP timestamp 2026-10-14) fixed by travelling before the consent; `CustomerSalesAttributionVisibilityTest` export asserted "Perorangan" on a factory-random type, pinned. Rerun of those files + Evaluation + CompanyProfile: 80 passed.
- Docs: ticket 10 done (screenshots 84/84, PDFs re-rendered). CHANGELOG blocks: ui-consistency (with Security bullet), mobile API, package categories.
