# BACKEND: ODN ports, splitters, drops and occupancy in the NetworkAsset module

Status: ready-for-agent
Role: backend
Blocked by: 01
Card: none (new lane; no Hermes card)

## Scope

Owned files (no other ticket edits these):

- NEW `Modules/NetworkAsset/database/migrations/<ts>_create_odn_splitters_table.php` and `<ts>_create_odn_ports_table.php` per `../spec.md` (FK `odn_splitters.input_port_id` added after `odn_ports` exists, e.g. in the ports migration; stored generated `pon_number` with unique `(network_asset_id, pon_number)` following `Modules/Billing/database/migrations/2026_07_01_130002_create_payments_table.php:18,28`; unique `(odn_splitter_id, number)`; unique `subscription_id`; reversible `down()`).
- NEW `Modules/NetworkAsset/app/Models/OdnPort.php`, `OdnSplitter.php` (`BelongsToCompany`, `LogsActivity`) + factories.
- NEW `Modules/NetworkAsset/app/Services/OdnService.php` (all methods in the spec: `createPonPorts`, `createSplitter`, `connectSplitter`, `deleteSplitter`, `deletePort`, `assignDrop`, `releaseDrop`, `releaseForSubscription`, `dropPortFor`), NEW `Modules/NetworkAsset/app/Queries/OdnOccupancyQuery.php` (ODP rows, PON subscriber counts, unmapped subscriptions, free-port options under a POP).
- NEW controllers, Form Requests and policy for the `/admin/odn/*` rows of the spec's HTTP table, rendering `Admin/Odn/Index` and `Admin/Odn/Asset` with the spec's prop shapes.
- `Modules/NetworkAsset/routes/web.php` — add the `/admin/odn/*` routes. The MikroTik/RADIUS lane also edits this file: land serially.
- NEW `tests/Feature/Odn/` — feature tests for everything above.

Rules to enforce (each a 422 with a field message): PON ports only on `olt` assets; splitter host only `odc|odp`; `outputs` in 2,4,8,16,32,64; splitter input free, same company, not its own downstream (≤ 8 hops); delete refused while anything downstream or a subscription is attached; drop only on a free `splitter_out` port of an `odp` host, subscription in the same company and `pending|active|suspended`; drop outside the subscription's serving POP refused (ODP asset location `path` must start with POP `path` + `' > '`); moving a drop releases the old port in the same transaction; port row locked `FOR UPDATE` during assign.

Non-goals: onboarding, terminate hook, subscription/SPK page props, demo data, React pages, loss budget, maps.

## Acceptance criteria

- [ ] `php artisan route:list --path=admin/odn` lists exactly the spec's ODN routes and names; `/admin/odn/assets/{asset}` returns 404 for asset types other than `olt|odc|odp` and for another company's asset.
- [ ] Migrations up/down/up pass on `inbils_test`; duplicate PON number on one OLT and duplicate output number on one splitter are refused by the DB.
- [ ] Every rule above has a named test asserting status and no DB change.
- [ ] `createPonPorts(olt, 4)` twice yields ports 1-8; `createSplitter(odp, 8)` yields outputs 1-8.
- [ ] Occupancy: ODP with one 1:8 splitter and 3 drops → capacity 8, used 3, free 5, percent 37.5; an ODC output feeding an ODP splitter counts as used; PON port count sums subscribers through two splitter levels.
- [ ] Unmapped list shows active subscriptions without a drop and hides terminated ones.
- [ ] Parallel-process smoke: two `assignDrop` calls for the last free port with different subscriptions → exactly one succeeds.
- [ ] Permissions: staff can assign drops but gets 403 on `odn.manage` routes; technician view-only; existing `tests/Feature/NetworkAsset/*` and `tests/Feature/AdminRouteTest.php` green.
- [ ] Pint and Larastan clean on new and changed files.

## Comments
