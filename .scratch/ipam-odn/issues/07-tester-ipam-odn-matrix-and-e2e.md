# TESTER: IPAM/ODN matrix gaps, tenant boundaries and E2E

Status: done
Role: tester
Blocked by: 03, 06
Card: none (new lane; no Hermes card)

## Scope

Tests only; defects go back to the owning ticket as `needs-change` with a failing test.

Owned files:

- NEW `tests/Feature/Ipam/IpamTenantAndPermissionMatrixTest.php`, NEW `tests/Feature/Odn/OdnTenantAndPermissionMatrixTest.php` — fill every row of `../spec.md` Testing Decisions not already covered by tickets 01-03, in particular: cross-company 404 on **every** `/admin/ipam/*` and `/admin/odn/*` route, cross-company ids in request bodies (`ip_prefix_id`, `vlan_id`, `location_id`, `input_port_id`, `subscription_id`, `odn_port_id`) → 422/404 with no write, role × route 403 matrix for admin/manager/staff/technician/customer.
- NEW `tests/e2e/ipam-odn.spec.ts` — manager: create a /29 static pool → assign a static IP to a demo subscription → see it on the subscription page → release; create PON ports and a 1:8 splitter on a demo ODP → onboard a customer choosing a drop port on that ODP → ODN page shows the ODP one port fuller → terminate the subscription → port free again. Staff: sees the menu entries, cannot open manage dialogs.

Mutation checks (revert byte-for-byte after each): remove the `(company_id, address_cmp)` unique → the concurrency smoke fails; remove the drop-port `FOR UPDATE` lock → the drop smoke fails; remove the serving-POP path check → the outside-POP test fails; remove the terminate release → the lifecycle test fails. Record failing-test counts in a comment.

## Acceptance criteria

- [x] Every matrix row in the spec maps to a named, green test on MySQL `inbils_test` via the PHPUnit command in `../spec.md`. The role × route matrices (403) were already in `IpamPermissionTest` / `OdnPermissionTest`; the new files add the route-table-driven completeness, 404, permission-floor and cross-company-body tests (9 tests, 116 assertions green). A foreign `odn_port_id` at onboarding stays covered by `IpamOdnLifecycleTest::test_another_companys_port_is_refused_and_nothing_is_created`.
- [x] Mutation results recorded (docblock of `IpamTenantAndPermissionMatrixTest`): unique removed 3 failed of 12; `FOR UPDATE` removed 1 failed of 2; serving-POP check removed 4 failed of 44; terminate release removed 3 failed of 23. Every file reverted byte-for-byte (md5 checked).
- [x] E2E green on the canonical run: `ipam-odn.spec.ts` + `customer-subscription.spec.ts` + `sidebar-ia.spec.ts` in one invocation, 20 passed.
- [ ] Full isolated PHPUnit suite green before handing to ticket 08. Not run by this agent: other lanes are still landing, so the lead runs it once after every ticket is in.

## Comments

- 2026-10-13 (IpamOdnUI): E2E half delivered. `tests/e2e/ipam-odn.spec.ts` (5 tests, green): manager static pool → static IP → release, PON ports → 1:8 splitter → onboard with a drop port → ODN one port fuller → activate and terminate → port free; staff and technician read-only; role x route matrix (read, manage, assign-ip, assign-drop for admin/manager/staff/technician/customer, empty-body writes so nothing is stored). Still open: the two PHP matrix files `tests/Feature/{Ipam/IpamTenantAndPermissionMatrixTest,Odn/OdnTenantAndPermissionMatrixTest}.php`, the four mutation checks, the full isolated PHPUnit suite, and one canonical run with `ipam-odn.spec.ts` plus `customer-subscription.spec.ts` in a single invocation (they passed in separate runs).

- 2026-10-13 (IpamOdnUI): closed. `tests/Feature/{Ipam/IpamTenantAndPermissionMatrixTest,Odn/OdnTenantAndPermissionMatrixTest}.php` fail when an `admin.ipam.*` or `admin.odn.*` route has no entry, so a new route cannot ship without a tenant and permission check. Pint clean.
