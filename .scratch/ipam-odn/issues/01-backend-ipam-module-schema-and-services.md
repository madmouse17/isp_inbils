# BACKEND: Ipam module — schema, services, routes, asset write-through, permissions

Status: done
Role: backend
Blocked by: none (starts only after the owner approves `../spec.md` Q1-Q14)
Card: none (new lane; no Hermes card)

## Scope

Owned files (no other ticket edits these):

- `composer.json`, `composer.lock` — add `mlocati/ip-lib` `^1.22` (Q3a). Paste `composer audit` output in a comment.
- `modules_statuses.json` — `"Ipam": true`.
- NEW `Modules/Ipam/**` (generate with `php artisan module:make Ipam`, then delete the generated API route mapping, `routes/api.php`, Blade views and scaffold controller so the module maps **web routes only**, like `Modules/NetworkAsset/app/Providers/RouteServiceProvider.php:25-38`):
  - migrations `create_vlans_table`, `create_ip_prefixes_table`, `create_ip_addresses_table` exactly as `../spec.md` Implementation Decisions (company-scoped uniques, `ascii_bin` comparable columns, reversible `down()`);
  - models `Vlan`, `IpPrefix`, `IpAddress` (`BelongsToCompany`, `LogsActivity`, casts, relations) + factories;
  - `Services/IpPrefixService.php` (create/update/delete, auto-parent and re-parent, overlap refusal under `Cache::lock("ipam:prefixes:{companyId}", 10)`), `Services/VlanService.php`, `Services/IpAssignmentService.php` (all methods in the spec, including `staticAddressFor` and `syncAssetAddresses`), `Queries/IpPrefixUtilisationQuery.php`;
  - `Events/IpAssignmentChanged.php` (`ShouldDispatchAfterCommit`, `int $companyId`, `int $subscriptionId`);
  - console command `ipam:import-asset-ips {--company=} {--dry-run}` registered in the module service provider;
  - controllers, Form Requests (company-scoped `exists`), policies and `routes/web.php` for every `/admin/ipam/*` row of the spec's HTTP table, rendering `Admin/Ipam/Prefixes/Index`, `Admin/Ipam/Prefixes/Show`, `Admin/Ipam/Vlans/Index` with the prop shapes in the spec.
- `database/seeders/RolePermissionSeeder.php` — all six permissions (`ipam.view|manage|assign`, `odn.view|manage|assign`) and role grants per Q14. Land serially with the MikroTik/RADIUS lane, which also edits this file.
- `Modules/NetworkAsset/app/Http/Controllers/NetworkAssetController.php` — `store`, `update` and `destroy` only: wrap in a transaction and call `IpAssignmentService::syncAssetAddresses` (create/update) or release the asset's rows (destroy); a conflict returns 422 on `ip_address` / `management_ip` and writes nothing. Add the `shortcut:` comment from Q6a.
- NEW `tests/Feature/Ipam/` — feature tests for everything above.

Non-goals: subscription page props, onboarding, terminate hook, demo data, ODN, any React page.

## Acceptance criteria

- [ ] `php artisan route:list --path=admin/ipam` lists exactly the spec's IPAM routes with the spec's names; no `/api/*` route from `Ipam` (`tests/Feature/ApiExposureTest.php` stays green).
- [ ] Migrations up/down/up pass on `inbils_test`; `SHOW INDEX` shows `(company_id, cidr)`, `(company_id, address_cmp)`, `(subscription_id, family)` and `(company_id, location_id, vid)` uniques.
- [ ] Prefix tests: auto-parent and re-parent; duplicate and partial overlap 422; IPv6 normalised; delete with children or addresses 422; cross-company 404.
- [ ] Assignment tests: lowest-free skips network, broadcast and reserved; /31 and /32 usable; full pool 422; manual duplicate 422 naming the holder; same address in two companies OK; second IPv4 on one subscription 422; terminated subscription 422; release deletes the row and audits; `IpAssignmentChanged` dispatched after commit on assign and release (`Event::fake` set up after factories); `staticAddressFor` returns dotted IPv4 or `null`.
- [ ] Parallel-process smoke (pattern of `tests/Feature/SPK/SerializedAssetConcurrencySmokeTest.php`): two auto-assigns racing for the last address in a /30 pool → one 2xx, one 422, one row.
- [ ] Asset write-through: create/update with an address held by a subscription → 422, no asset change; destroy releases asset-held rows; existing `tests/Feature/NetworkAsset/*` green.
- [ ] Import command: `--dry-run` writes nothing; real run idempotent (second run imports 0); unparseable and duplicate rows printed; assets never modified.
- [ ] Utilisation for a /24 pool with 3 assigned and 1 reserved: usable 254, assigned 3, reserved 1, free 250, percent 1.57 (`(assigned + reserved) / usable`, 2 decimals); a dynamic pool shows size only.
- [ ] Permissions: role matrix of Q14 asserted (403 for staff on `ipam.manage` routes, technician view-only).
- [ ] Pint and Larastan clean on new and changed files.

## Comments

2026-10-13 IpamOdnBackend: module, schema, services, routes, import command, asset write-through and tests are in (tests/Feature/Ipam, 190 tests). Pending, lead-owned this wave: the six permissions and their role grants in `database/seeders/RolePermissionSeeder.php` (names and mapping are in the backend report); my tests grant them themselves via `tests/Traits/BuildsIpamOdnFixtures.php`.
