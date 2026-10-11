# TESTER: IPAM/ODN matrix gaps, tenant boundaries and E2E

Status: ready-for-agent
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

- [ ] Every matrix row in the spec maps to a named, green test on MySQL `inbils_test` via the PHPUnit command in `../spec.md`.
- [ ] Mutation results recorded.
- [ ] E2E green on the canonical run: `flock /tmp/inbils-e2e.lock sh -c 'npm run build && php tests/e2e/setup.php && npm run test:e2e -- tests/e2e/ipam-odn.spec.ts tests/e2e/customer-subscription.spec.ts --project=chromium --workers=1'`.
- [ ] Full isolated PHPUnit suite green before handing to ticket 08.

## Comments
