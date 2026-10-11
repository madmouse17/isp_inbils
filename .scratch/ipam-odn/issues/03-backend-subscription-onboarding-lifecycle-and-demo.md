# BACKEND: subscription, onboarding and SPK integration, terminate release, demo data, docs

Status: ready-for-agent
Role: backend
Blocked by: 01, 02
Card: none (new lane; no Hermes card)

## Scope

Owned files (no other ticket edits these):

- `app/Services/Core/SubscriptionService.php` — `terminate` (`:116`) calls `IpAssignmentService::releaseForSubscription` and `OdnService::releaseForSubscription` inside its existing transaction (Q10a). Suspend and reactivate unchanged. The MikroTik/RADIUS lane and `customer-status-rollup` also edit this file: land serially and re-read line numbers first.
- `app/Actions/CreateCustomerOnboardingAction.php` — `Arr::pull` the optional `odn_port_id` from the subscription data before `SubscriptionService::create` (`:29`) and call `OdnService::assignDrop` after it, inside the existing transaction (`:21`) (Q9a).
- `app/Http/Requests/Admin/StoreCustomerRequest.php` — add `odn_port_id` to the `subscription` array shape (`:64`) and rule `subscription.odn_port_id` nullable integer, company-scoped `exists` on `odn_ports`.
- `app/Http/Controllers/Admin/CustomerController.php` — `create` (`:61`) adds the lazy `dropPortOptions` prop (Inertia optional prop, filtered by query `serving_pop_id`, only for users with `odn.assign`).
- `app/Http/Controllers/Admin/SubscriptionController.php` — `show` (`:65`) adds `ipAddresses`, `staticPools` (only with `ipam.assign`), `dropPort` and lazy `dropPortOptions` per the spec shapes.
- `Modules/SPK/app/Http/Controllers/WorkOrderController.php` — `show` (`:156`) adds read-only `dropPort` for installation SPKs with a subscription.
- `database/seeders/DemoDataSeeder.php` and NEW `database/seeders/Demo/IpamOdnDemo.php` — per Q11a: one `/22` customer static pool and one dynamic pool per demo POP, management IPs of demo core assets registered via `IpAssignmentService::syncAssetAddresses`, 8 PON ports per demo OLT, one splitter per demo ODC (1:4) and ODP (1:8) wired ODC→ODP, drops for the demo's active subscriptions on ODPs under their serving POP. Register after customers exist in the chain (`DemoDataSeeder.php:76-85`).
- Docs: `docs/business/network.md` (IPAM and ODN sections, fix "IP address unik" at `:13`), `docs/ENTERPRISE_ISP_READINESS.md:7` (Q1 decision line), `CHANGELOG.md`.
- NEW `tests/Feature/IpamOdnLifecycleTest.php`.

Non-goals: React pages, `CompleteSpkAction` (unchanged, Q9a), event listeners for provisioning.

## Acceptance criteria

- [ ] Onboarding with a free `odn_port_id` under the serving POP → customer, subscription, SPK and drop created in one transaction; with an occupied, foreign-company or outside-POP port → 422 and **no** customer, subscription or SPK row (`tests/Feature/CustomerAccountCreationTest.php` stays green).
- [ ] Onboarding without `odn_port_id` behaves exactly as before.
- [ ] Terminate releases the subscription's static IPs and drop port, audits both, and dispatches `IpAssignmentChanged` after commit; suspend leaves them.
- [ ] Subscription show props match the spec shapes; `staticPools` absent without `ipam.assign`; `dropPortOptions` only evaluated on partial reload.
- [ ] SPK show includes `dropPort` for an installation SPK whose subscription has a drop, `null` otherwise; `tests/Feature/SPK/SpkCompletionOrchestrationTest.php` green unchanged.
- [ ] `tests/Feature/DemoDataSeederTest.php` green; demo has non-zero prefixes, PON ports, splitters and drops; no drop outside its serving POP.
- [ ] Docs updated; `.gitignore` docs allowlist already covers edited files (no new docs files).
- [ ] Pint and Larastan clean on changed files.

## Comments
