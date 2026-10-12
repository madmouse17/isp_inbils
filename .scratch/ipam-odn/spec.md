# IPAM and ODN port hierarchy

Status: done (tickets 01-08 done; security review APPROVED 2026-10-12)
Ledger: none yet (new lane promoted from `.scratch/isp-roadmap/spec.md` items 5 and 6)
Source: `.scratch/isp-roadmap/spec.md` rows 5 (IPAM) and 6 (ODN port hierarchy), re-verified 2026-10-11 against the working tree.

> Nothing starts before the owner answers the decisions below. "setuju rekomendasi" approves the recommended option of every item. No credentials or hardware access are needed: this lane only records network facts; it never talks to an OLT, router or RADIUS server.

Approved by owner 2026-10-12: recommended option of every decision ("setuju rekomendasi").

## Owner decisions

1. **Q1 — Scope conflict.** `docs/ENTERPRISE_ISP_READINESS.md:7` excludes "full telco OSS/BSS" and `:279-280` lists Radius/NAS automation and NMS/topology discovery as P2/YAGNI. IPAM and an ODN port register are inventory records, not OSS automation.
   (a) Approve both as **passive registers** (staff record prefixes, addresses, ports; no device polling, no config push), amend the readiness doc. (b) IPAM only. (c) ODN only. (d) Keep excluded; close the lane.
   **Recommend (a).** Both are prerequisites for the MikroTik/RADIUS lane (`.scratch/isp-roadmap/spec.md` Notes: "5 and 6 are prerequisites for sensible automation in 1-2"), and ODN is what "stops installations on full ODPs". Tradeoff: two more registers staff must keep current by hand until provisioning automation exists.

2. **Q2 — Where the code lives.** ADR-001 (`docs/architecture/decisions/ADR-001.md:5`) puts domain logic in `Modules/{Name}` and forbids module-to-module model imports. `docs/business/network.md:6-7,15-17` makes NetworkAsset the "source of truth" for physical equipment.
   (a) New module `Modules/Ipam` for prefixes, VLANs and addresses; ODN ports and splitters inside `Modules/NetworkAsset` (they are parts of physical assets: OLT, ODC, ODP). (b) Two new modules `Modules/Ipam` and `Modules/Odn`. (c) Everything inside `Modules/NetworkAsset`.
   **Recommend (a).** Addresses are logical, have their own lifecycle and a consumer (provisioning) that should depend on one small service, not on the asset module; ports are physical and need the asset rows they hang on. Tradeoff: two places to look; (c) is fewer files but bloats NetworkAsset with logical addressing that the MikroTik lane must then import.

3. **Q3 — IP library.** Facts: `composer.json` has no IP library (grep `php-ip|iptools|ip-lib|mlocati` empty; `"php": "^8.2"` at `composer.json:10`); only `Symfony\Component\HttpFoundation\IpUtils::checkIp` exists (`vendor/symfony/http-foundation/IpUtils.php:62`), which matches an address to a CIDR but cannot normalise prefixes, test prefix containment, size a range or step to the next address. Candidates (Packagist, read 2026-10-11):
   - (a) `mlocati/ip-lib` **^1.22** (1.22.1 released 2026-09-17, MIT, **no runtime dependencies, no PHP extension**, 8.4M installs). Parses and normalises IPv4/IPv6 addresses and CIDRs; `containsRange`, `getAddressAtOffset`, `getExactSize`, and `getComparableString` — a fixed-width string meant for "is this address inside a stored range" SQL range queries (README section on storing ranges in a database table).
   - (b) Native PHP (`inet_pton`, `ip2long`, `filter_var`): IPv4 arithmetic is easy; IPv6 prefix maths needs hand-written 128-bit byte arithmetic.
   - (c) `rlanvin/php-ip` v3.0.0 (last release 2022-03-02, requires `ext-gmp`).
   - (d) `s1lentium/iptools` v1.2.0 (last release 2022-08-17, requires `ext-bcmath`).
   **Recommend (a).** Maintained, zero transitive dependencies, no extension to add to the Sail image or the host, and it removes the error-prone part (IPv6 normalisation and containment). Tradeoff: one new dependency; `composer audit` must be pasted when it lands (rule 11).

4. **Q4 — Address storage and conflict prevention.**
   (a) Store each address as its normalised text plus the `ip-lib` comparable string; **DB unique `(company_id, address_cmp)`** on addresses and **unique `(company_id, cidr)`** on prefixes; prefix overlap (a new prefix may nest inside a prefix but may not partially overlap a sibling) checked in the service under a per-company `Cache::lock` (Redis is the cache store, `.env.example:40`). (b) `VARBINARY(16)` columns from `inet_pton`. (c) Per-VRF uniqueness (same private range reused in several routing tables).
   **Recommend (a).** The unique index is the conflict backstop the request asks for; comparable strings keep range queries indexable without binary handling in Eloquent/JSON. Tradeoff: no overlapping private space inside one company (VRF support is Out of Scope).

5. **Q5 — Static vs dynamic per subscription, and allocation strategy.**
   - Mode: (a) **dynamic is the default and stores nothing** in IPAM (the router/RADIUS hands out the address from a pool that is registered here only for capacity); **static** means one IPAM address row held by the subscription. (b) Store a mode column plus the chosen dynamic pool on every subscription.
   - Allocation for static IPv4: (i) lowest free usable address in the chosen pool (skip network and broadcast except /31 and /32, skip reserved rows); (ii) random; (iii) explicit address typed by staff only.
   **Recommend (a) + (i), with (iii) also allowed** (staff may type a specific free address). IPv6 in v1: prefixes and manually typed static IPv6 addresses are stored and conflict-checked; automatic IPv6 allocation and prefix delegation (/56 or /64 per customer) wait until the provisioning lane needs them. Tradeoff: no per-subscriber record of dynamic leases (needs RADIUS accounting); dynamic pools show size only. Auto-allocating IPv4 pools are limited to /16 or smaller so the lowest-free scan stays bounded.

6. **Q6 — Existing free-text asset IPs.** `network_assets.ip_address` and `management_ip` are free text (`Modules/NetworkAsset/database/migrations/2026_07_01_110000_create_network_assets_table.php:20-21`), validated only as `ip` (`Modules/NetworkAsset/app/Http/Requests/StoreNetworkAssetRequest.php:27-28`, `UpdateNetworkAssetRequest.php:28-29`), not unique, and searched with `like` in the index and trace (`Modules/NetworkAsset/app/Http/Controllers/NetworkAssetController.php:151,320`).
   (a) **Keep the columns** (index, trace, export and demo keep working) and make IPAM the conflict authority: asset create/update writes through `IpAssignmentService` in the same transaction (422 when another holder has the address), asset delete releases its rows; a one-off idempotent command `ipam:import-asset-ips {--company=} {--dry-run}` registers existing values and prints unparseable and duplicate rows without changing assets. (b) Drop the columns and read asset IPs from IPAM (clean cutover; touches trace, search, export, forms, seeders and their tests). (c) Leave asset IPs outside IPAM.
   **Recommend (a).** Tradeoff: the asset columns remain a display copy of the IPAM row (`shortcut:` comment required in code; upgrade to (b) when a second consumer reads asset IPs).

7. **Q7 — Location tree mismatch.** The brief says "region > area > POP > ODC > ODP", but `locations.type` is `region|area|pop|rack|site` (`database/migrations/2026_08_11_163004_enforce_latest_closed_values_as_enums.php:12`, parent rules `app/Services/Core/LocationService.php:18-21`). Demo data models an ODC as a `rack` location and an ODP as a `site` location (`database/seeders/Demo/LocationDemo.php:95,108`) and installs an `odc`/`odp` **network asset** at each (`database/seeders/Demo/NetworkDemo.php:84,87,128`).
   (a) Keep the location tree; ODN hangs off `network_assets` of type `olt|odc|odp`, which already carry `location_id`. (b) Add `odc` and `odp` location types (enum migration, `LocationService` parent rules, request validation, map pin colours, demo).
   **Recommend (a).** No Core change and no migration of existing locations. Tradeoff: "ODP" means an asset of type `odp`; its position is the location it is installed at.

8. **Q8 — How ODN ports are modelled.**
   (a) **One row per port** (`odn_ports`) plus one row per splitter (`odn_splitters`): an OLT owns PON ports; a splitter sits in an ODC or ODP asset, takes one upstream port as input and owns N output ports; a customer drop is an ODP splitter output holding a subscription. (b) JSON port map on the asset row. (c) Only capacity/used counters on the asset.
   **Recommend (a).** Occupancy, "one subscription per port", "one splitter per upstream port" and "no assigning a full ODP" become DB-unique columns and row locks; (b) cannot be locked or indexed and (c) cannot answer "which port is this customer on". Tradeoff: ports must be created (bulk on OLT, automatic per splitter ratio).

9. **Q9 — Where the customer drop is chosen in the installation flow.** Today onboarding creates the subscription and an installation SPK whose location is the serving POP (`app/Actions/CreateCustomerOnboardingAction.php:29,35,43`); completion installs the ONT and activates the subscription (`Modules/SPK/app/Actions/CompleteSpkAction.php:38-39,277,285-286`).
   (a) **Optional drop port picked at onboarding** (reserves the port; the picker offers only free ports on ODPs under the chosen serving POP); NOC can set or move it later from the subscription page; `CompleteSpkAction` unchanged. (b) As (a), and also refuse to complete an installation SPK without a drop port. (c) Technician chooses the port when submitting the SPK.
   **Recommend (a).** Delivers "stop installations on full ODPs" with no behaviour change to SPK completion (`tests/Feature/SPK/SpkCompletionOrchestrationTest.php` stays valid). Tradeoff: installs without a recorded port remain possible; the ODN page lists active subscriptions without a drop port so NOC can backfill. (b) is a small follow-up once backfill is done.

10. **Q10 — Release on termination.** `SubscriptionService::terminate` already optionally releases the ONT (`app/Services/Core/SubscriptionService.php:116-148`). (a) Terminate always releases the static IP(s) and the drop port in the same transaction (audited); suspend changes nothing. (b) Keep them held until staff release by hand. **Recommend (a)**; tradeoff: an address can be reused immediately (no quarantine period).

11. **Q11 — Existing data and demo.** No ports or prefixes exist anywhere (grep `pon_port|splitter|vlan|ipam|subnet|cidr` in `app Modules database` finds only the inventory product "Splitter PLC 1:8" at `database/seeders/Demo/InventoryDemo.php:33`). (a) Production data entered by staff through the UI; the demo seeder gets one customer pool per POP, PON ports on the two demo OLTs, one splitter per demo ODC and ODP, and drops for the demo's active subscriptions. (b) Also a CSV import. **Recommend (a)**; tradeoff: initial ODN entry is manual.

12. **Q12 — Loss budget.** (a) Defer. (b) Show an estimated optical loss per drop port from typical PLC splitter loss per ratio. **Recommend (a).** Without fibre length and connector counts the number misleads technicians; it becomes useful with the GIS lane (roadmap item 9).

13. **Q13 — Map / GIS.** The Locations page already has a Leaflet map tab with pins per location type (`resources/js/Components/composite/locations/LocationsMapView.tsx`, mounted in `resources/js/Pages/Admin/Locations/Index.tsx`). (a) Defer; no map changes. (b) Colour ODP pins by occupancy. **Recommend (a)**; roadmap item 9 owns maps.

14. **Q14 — Permissions** (naming `{module}.{action}` like `network_asset.view`, `database/seeders/RolePermissionSeeder.php:72-82`).
    New: `ipam.view`, `ipam.manage` (prefixes, VLANs, reservations, asset-IP import), `ipam.assign` (subscription static IP), `odn.view`, `odn.manage` (PON ports, splitters), `odn.assign` (drop port).
    Roles: `admin` all (`:205` syncs every permission except the portal pair); `manager` all six; `staff` `ipam.view`, `odn.view`, `odn.assign` (staff onboard customers, `:259-262`); `technician` `ipam.view`, `odn.view`; `customer` none.
    **Recommend as listed**; tradeoff: staff cannot hand out static IPs (manager/NOC only).

## Corrections to the brief and the roadmap

- Location types are `region|area|pop|rack|site`, not "ODC/ODP" (Q7).
- `network_assets.ip_address/management_ip` are not unique per company today; the only uniques are `(company_id, code)` and `(company_id, serial_number)` (`create_network_assets_table.php:38-39`). `docs/business/network.md:13` says "IP address unik", which the code does not enforce.
- `docs/business/network.md:171-172` says an ONT is installed at the customer premise, but `CompleteSpkAction` installs it at `work_orders.location_id`, which onboarding sets to the serving POP (`CreateCustomerOnboardingAction.php:43`). Not changed here; noted because the drop port (not the ONT location) becomes the customer's position in the ODN.
- No subscription domain events exist (`Modules/Service/app/Providers/EventServiceProvider.php:14` has an empty `$listen`); provisioning hooks need a new seam, which this lane provides for IP changes only.

## Current implementation map

- Assets: `Modules/NetworkAsset/app/Models/NetworkAsset.php` (`BelongsToCompany`, `SoftDeletes`, `LogsActivity`), `Modules/NetworkAsset/app/Models/NetworkAssetInstallation.php` (append-only), `Modules/NetworkAsset/app/Services/NetworkAssetService.php` (`install :19-100`, `remove :102-129`), `Modules/NetworkAsset/app/Http/Controllers/NetworkAssetController.php` (`store`/`update`/`destroy` save directly, no service, no transaction), routes `Modules/NetworkAsset/routes/web.php:6-19` (prefix `admin`, `auth, verified, require.has.company`), `Modules/NetworkAsset/app/Policies/NetworkAssetPolicy.php`. Tests live in `tests/Feature/NetworkAsset/*` (module `tests/` holds only `.gitkeep`). Module route providers map web routes only (`Modules/NetworkAsset/app/Providers/RouteServiceProvider.php:25-38`), matching the P0 "no module API" decision (`docs/ENTERPRISE_ISP_READINESS.md:209`).
- Asset types: `router|switch|olt|onu_ont|radio|antenna|fiber|odp|odc|rack|power|other` (`enforce_latest_closed_values_as_enums.php:20`).
- Locations: `app/Models/Core/Location.php` (adjacency `parent_id`, materialised `path` built as `parent path > code`, `lat/lng`), `app/Services/Core/LocationService.php` (`tree :174`, `recomputePath :187`).
- Subscriptions: `service_subscriptions` has `serving_pop_id` and `ont_asset_id`, no IP/PPPoE columns (`database/migrations/2026_06_30_220003_create_service_subscriptions_table.php:23-24`); statuses `pending|active|suspended|terminated` (`enforce_latest_closed_values_as_enums.php:14`); lifecycle in `app/Services/Core/SubscriptionService.php` (`create :16`, `activate :54`, `suspend :76`, `reactivate :95`, `terminate :116`); page `resources/js/Pages/Admin/Subscriptions/Show.tsx` via `app/Http/Controllers/Admin/SubscriptionController.php:65-74`.
- Onboarding: `app/Http/Controllers/Admin/CustomerController.php:61,96` → `app/Http/Requests/Admin/StoreCustomerRequest.php:64-83` (`subscription` array shape) → `app/Actions/CreateCustomerOnboardingAction.php:21-55`; page `resources/js/Pages/Admin/Customers/Create.tsx`.
- SPK: `Modules/SPK/app/Actions/CompleteSpkAction.php:25-47`; page `resources/js/Pages/Admin/SPK/Show.tsx` via `Modules/SPK/app/Http/Controllers/WorkOrderController.php:156`.
- Navigation: `resources/js/lib/navigation.ts` group `network-work-orders` (Network Assets entry `:259-262`).
- Precedents: company-scoped uniques (`database/migrations/2026_06_30_210000_create_locations_table.php:26`, `Modules/Inventory/database/migrations/2026_07_01_100003_create_stocks_table.php:20`); stored generated column + unique (`Modules/Billing/database/migrations/2026_07_01_130002_create_payments_table.php:18,28`); `DB::afterCommit` side effects (`app/Services/Core/CustomerService.php:36`); Core calling a module service (`SubscriptionService.php:11,142`); parallel-process concurrency test (`tests/Feature/SPK/SerializedAssetConcurrencySmokeTest.php`).
- Demo: `database/seeders/DemoDataSeeder.php:76-85` chain; 2 OLTs, 6 ODCs, 18 ODPs from `database/seeders/Demo/NetworkDemo.php:48-90`.

## Problem Statement

NOC staff keep IP addresses and fibre ports in spreadsheets or in their heads. Two customers can be given the same static IP, a device's management IP can collide with a customer's, nobody can say how full a prefix is, and sales keep selling installations into ODPs that have no free port. When a fibre fails, staff cannot list the customers behind a PON port or splitter. The planned router/RADIUS automation has nowhere safe to get a customer's static IP from.

## Solution

Staff register their IP prefixes (IPv4 and IPv6) in a tree and mark each as a container, customer static pool, customer dynamic pool or infrastructure range, optionally tied to a POP and a VLAN. A subscription is dynamic by default; NOC can give it a static address — the lowest free one in a chosen pool, or a specific one — and the database refuses any duplicate in the company. Device IPs entered on network assets are checked against the same register. A prefix list shows how full every block is.

Staff also register the fibre distribution network: PON ports on each OLT, splitters (1:2 to 1:64) inside ODCs and ODPs connected to an upstream port, and the ODP output port each customer uses. Onboarding offers only free ports on ODPs under the chosen POP. An ODN page shows capacity, used and free ports per ODP, subscribers per PON port, full ODPs, and active subscriptions with no recorded port. Terminating a subscription frees its static IP and its port.

## User Stories

1. As a NOC engineer, I want to register an IPv4 or IPv6 prefix in CIDR form, so that the company's address space is on record.
2. As a NOC engineer, I want a new prefix placed automatically under the smallest existing prefix that contains it, so that the tree reflects the real allocation hierarchy.
3. As a NOC engineer, I want the system to refuse a prefix that partially overlaps a sibling or duplicates an existing one, so that address plans stay consistent.
4. As a NOC engineer, I want to mark a prefix as container, customer static pool, customer dynamic pool or infrastructure, so that each block is used for its purpose only.
5. As a NOC engineer, I want to tie a prefix to a POP and to a VLAN, so that I know where it is routed.
6. As a NOC engineer, I want to register VLANs (ID 1-4094, name) per POP or company-wide, so that VLAN reuse across POPs is visible and duplicates inside one POP are refused.
7. As a NOC engineer, I want to reserve a specific address with a note (gateway, router loopback), so that it is never handed to a customer.
8. As a NOC engineer, I want to give a subscription a static IPv4 address chosen automatically as the lowest free address in a pool, so that I do not hunt for free addresses.
9. As a NOC engineer, I want to type a specific static address for a subscription, so that I can honour an address the customer already uses.
10. As a NOC engineer, I want a duplicate address refused with a clear message naming the current holder, so that two customers never share an IP.
11. As a NOC engineer, I want to release a subscription's static IP, so that the address returns to the pool.
12. As a NOC engineer, I want a terminated subscription's static IP released automatically, so that dead customers do not hold addresses.
13. As a NOC engineer, I want a device IP or management IP typed on a network asset checked against the register, so that infrastructure and customer addresses never collide.
14. As an administrator, I want existing asset IPs imported into the register with a report of unparseable and duplicate values, so that I can clean up legacy data without losing it.
15. As a NOC lead, I want each prefix's size, assigned, reserved, free and percentage used, so that I can plan before a pool runs out.
16. As a NOC lead, I want dynamic pools shown with their size, so that I know the capacity the routers hand out.
17. As a provisioning integrator (MikroTik/RADIUS lane), I want one service call that returns a subscription's static IPv4 or null, and an event when it changes, so that router configuration can follow IPAM.
18. As a NOC engineer, I want to create PON ports on an OLT in bulk, so that ports exist before I connect splitters.
19. As a NOC engineer, I want to add a splitter with a ratio to an ODC or ODP and connect its input to a PON port or to another splitter's output, so that the fibre tree is recorded.
20. As a NOC engineer, I want the system to refuse connecting a splitter to a port that is already in use or to its own downstream, so that the tree stays a tree.
21. As a NOC engineer, I want to see an OLT's PON ports with the number of subscribers behind each, so that I can balance load and isolate faults.
22. As a NOC engineer, I want to see every ODP's capacity, used and free ports and its location path, so that I know where I can still connect customers.
23. As a sales or onboarding clerk, I want to pick a free drop port on an ODP under the chosen POP while onboarding a customer, so that the installation does not go to a full ODP.
24. As a sales clerk, I want full ODPs not offered at all, so that I cannot sell into them by mistake.
25. As a NOC engineer, I want to set or move a subscription's drop port from the subscription page, so that I can record what the technician actually did.
26. As a technician, I want to see the reserved ODP and port on the SPK, so that I connect the drop to the right place.
27. As a NOC lead, I want a list of active subscriptions with no recorded drop port, so that we can backfill the register.
28. As a NOC engineer, I want a terminated subscription's drop port freed automatically, so that capacity figures stay true.
29. As an auditor, I want every prefix, address, VLAN, port and splitter change in the activity log with the actor, so that changes are traceable.
30. As a user of company B, I never see or change company A's prefixes, VLANs, addresses, ports or splitters, and cannot attach company A's port or address to my subscription.
31. As a technician or staff member without manage permissions, I can view but not change the registers.

## Implementation Decisions

- **Module split (Q2a).** New nwidart module `Ipam` (web routes only, no API mapping, enabled in `modules_statuses.json`). ODN tables, models and services go into the `NetworkAsset` module. Cross-module calls go through services only (ADR-001); Core (`SubscriptionService`, `CreateCustomerOnboardingAction`, `SubscriptionController`, `CustomerController`) calls module services the way `SubscriptionService` already calls `NetworkAssetService`.
- **Dependency (Q3a).** `mlocati/ip-lib` `^1.22`; every parse and normalisation goes through it; nothing else in the app parses CIDRs.
- **Schema — `Ipam` module** (all tables `company_id` FK restrict, models `BelongsToCompany` + `LogsActivity`; reversible `down()`):
  - `vlans`: `location_id` nullable FK (POP), `vid` smallint unsigned 1-4094, `name` (64), `description` nullable; unique `(company_id, location_id, vid)`; company-wide duplicates (`location_id` null) checked in the service because MySQL treats NULLs as distinct.
  - `ip_prefixes`: `parent_id` nullable self FK restrict, `family` tinyint (4|6), `cidr` varchar(43) normalised, `range_from`/`range_to` varchar(39) `ascii_bin` (comparable strings), `prefix_length` tinyint, `usage` enum `container|customer_static|customer_dynamic|infrastructure`, `location_id` nullable FK, `vlan_id` nullable FK restrict, `description` nullable; unique `(company_id, cidr)`; index `(company_id, family, range_from)`. CIDR is immutable after create; delete refused while children or addresses exist.
  - `ip_addresses`: `ip_prefix_id` nullable FK restrict (null = outside every registered prefix, allowed for legacy asset IPs), `family`, `address` varchar(39) normalised, `address_cmp` varchar(39) `ascii_bin`, `status` enum `assigned|reserved`, `subscription_id` nullable FK restrict, `network_asset_id` nullable FK restrict, `note` nullable, `assigned_by` nullable FK users; unique `(company_id, address_cmp)`, unique `(subscription_id, family)`; at most one holder per row (service rule). Released rows are deleted; history lives in the activity log.
- **Services — `Ipam` module:**
  - `IpPrefixService::create(array)`, `update`, `delete`: parse with ip-lib, normalise, set parent = smallest containing prefix, re-parent existing prefixes that the new one contains, refuse partial overlap and duplicates; all inside `Cache::lock("ipam:prefixes:{companyId}", 10)` plus a transaction. Auto-allocating pools are IPv4 `customer_static` pools of /16 or smaller; IPv6 `customer_static` pools accept manually typed addresses only.
  - `IpAssignmentService` (the single address service): `assignToSubscription(ServiceSubscription, IpPrefix pool, ?string address)` (lowest free when `address` is null, IPv4 only), `reserve(IpPrefix, string address, ?string note)`, `release(IpAddress)`, `releaseForSubscription(ServiceSubscription)`, `syncAssetAddresses(NetworkAsset)` (write-through for `ip_address`/`management_ip`, Q6a), `forSubscription(ServiceSubscription): Collection`, `staticAddressFor(ServiceSubscription): ?string`. Every insert relies on the unique index; a duplicate-key race returns 422 naming the holder, auto-allocation retries the next free address up to 3 times. The subscription must be in the current company and not `terminated`; the address must lie inside the chosen pool.
  - `IpPrefixUtilisationQuery`: per prefix `size` (exact string for IPv6), `usable` (IPv4 minus network/broadcast except /31,/32), `assigned`, `reserved`, `free`, `percent = (assigned + reserved) / usable` (IPv4 address pools), child coverage percent (containers), size only (`customer_dynamic`).
  - Command `ipam:import-asset-ips {--company=} {--dry-run}` (idempotent; prints imported, unparseable and duplicate counts and rows; never edits assets).
- **Provisioning contract** (agreed with the MikroTik/RADIUS lane spec author, who cites this lane as optional upstream and wires static IPs in a follow-up ticket after this lane ships):
  - `Modules\Ipam\Services\IpAssignmentService::staticAddressFor(ServiceSubscription $subscription): ?string` — the subscription's static IPv4 in dotted form, or `null` (dynamic or none).
  - Event `Modules\Ipam\Events\IpAssignmentChanged` (`int $companyId`, `int $subscriptionId`), `ShouldDispatchAfterCommit`, dispatched on every assign or release of a subscription-held address (including release on terminate). No listener in this lane.
  - Not in v1: dynamic pool name per subscription, IPv6 delegated prefix (`delegatedPrefixFor()` is the reserved name). VLANs stay in this lane. The MikroTik lane adds its own tables (`nas_devices`, `network_accounts`) in `Modules/NetworkAsset` and does not alter `network_assets` or `locations` columns; this lane alters neither either.
- **Schema — `NetworkAsset` module (ODN, Q8a):**
  - `odn_splitters`: `network_asset_id` FK restrict (host asset, type `odc|odp`), `outputs` tinyint (2|4|8|16|32|64), `input_port_id` nullable unique FK `odn_ports` restrict, `label` nullable.
  - `odn_ports`: `network_asset_id` FK restrict (the OLT for `pon`; the host ODC/ODP for `splitter_out`), `odn_splitter_id` nullable FK restrict, `kind` enum `pon|splitter_out`, `number` smallint unsigned, `label` nullable, `subscription_id` nullable **unique** FK restrict; stored generated `pon_number` (= `number` when `kind='pon'`, else null) with unique `(network_asset_id, pon_number)`; unique `(odn_splitter_id, number)`; index `(company_id, network_asset_id)`.
  - A port is occupied when `subscription_id` is set or it is some splitter's `input_port_id`. ODP capacity = its splitter output ports; used = the occupied ones.
- **Services — ODN:** `OdnService::createPonPorts(NetworkAsset olt, int count)` (OLT only; numbers continue after the highest), `createSplitter(NetworkAsset host, int outputs, ?OdnPort input, ?string label)` (host `odc|odp`; creates N output ports), `connectSplitter(OdnSplitter, ?OdnPort)` (input free, same company, not its own downstream — walk up at most 8 hops), `deleteSplitter` / `deletePort` (refused while anything downstream or a subscription is attached), `assignDrop(OdnPort, ServiceSubscription)` (port is `splitter_out` on an `odp` host, free, locked `FOR UPDATE`; subscription in the same company and `pending|active|suspended`; moving releases the old port in the same transaction; when the subscription has a serving POP, the ODP asset's location `path` must start with the POP's `path` followed by `' > '`), `releaseDrop(OdnPort)`, `releaseForSubscription(ServiceSubscription)`, `dropPortFor(ServiceSubscription): ?array`. `OdnOccupancyQuery`: per ODP capacity/used/free/percent and location path; per PON port downstream subscriber count; active subscriptions without a drop port; free-port options under a POP.
- **Lifecycle hooks:** `CreateCustomerOnboardingAction` pulls the optional `subscription.odn_port_id` and calls `OdnService::assignDrop` after creating the subscription, inside the existing transaction (Q9a). `SubscriptionService::terminate` calls `IpAssignmentService::releaseForSubscription` and `OdnService::releaseForSubscription` inside its transaction (Q10a). `CompleteSpkAction` unchanged.
- **HTTP contract** (all `auth, verified, require.has.company`, prefix `admin`, names `admin.*`, Form Requests with company-scoped `exists`, policies; Inertia redirects with flash; 403 without permission, 404 cross-company, 422 on rule violations):

  | Method | URI | Name | Permission | Body / result |
  | --- | --- | --- | --- | --- |
  | GET | `/admin/ipam/prefixes` | `admin.ipam.prefixes.index` | `ipam.view` | Inertia `Admin/Ipam/Prefixes/Index`; filters `family, usage, location_id, vlan_id, search` |
  | POST | `/admin/ipam/prefixes` | `admin.ipam.prefixes.store` | `ipam.manage` | `cidr, usage, location_id?, vlan_id?, description?` |
  | GET | `/admin/ipam/prefixes/{prefix}` | `admin.ipam.prefixes.show` | `ipam.view` | Inertia `Admin/Ipam/Prefixes/Show`; children + paginated addresses (25) |
  | PUT | `/admin/ipam/prefixes/{prefix}` | `admin.ipam.prefixes.update` | `ipam.manage` | `usage, location_id?, vlan_id?, description?` (CIDR immutable) |
  | DELETE | `/admin/ipam/prefixes/{prefix}` | `admin.ipam.prefixes.destroy` | `ipam.manage` | 422 if children or addresses |
  | POST | `/admin/ipam/prefixes/{prefix}/reservations` | `admin.ipam.reservations.store` | `ipam.manage` | `address, note?` |
  | POST | `/admin/ipam/subscriptions/{subscription}/addresses` | `admin.ipam.subscription-addresses.store` | `ipam.assign` | `ip_prefix_id` and optional `address` (null = lowest free) |
  | DELETE | `/admin/ipam/addresses/{address}` | `admin.ipam.addresses.destroy` | `ipam.assign` if subscription-held, else `ipam.manage` | release; asset-held rows 422 (edit the asset instead) |
  | GET, POST, PUT, DELETE | `/admin/ipam/vlans`, `/admin/ipam/vlans/{vlan}` | `admin.ipam.vlans.index/store/update/destroy` | `ipam.view` / `ipam.manage` | Inertia `Admin/Ipam/Vlans/Index`; `vid, name, location_id?, description?`; delete 422 while a prefix uses it |
  | GET | `/admin/odn` | `admin.odn.index` | `odn.view` | Inertia `Admin/Odn/Index`; tabs ODPs / PON ports / unmapped subscriptions; filters `pop_id, min_percent, search` |
  | GET | `/admin/odn/assets/{asset}` | `admin.odn.assets.show` | `odn.view` | Inertia `Admin/Odn/Asset`; asset type `olt|odc|odp`, else 404 |
  | POST | `/admin/odn/assets/{asset}/pon-ports` | `admin.odn.pon-ports.store` | `odn.manage` | `count` 1-64 |
  | POST | `/admin/odn/assets/{asset}/splitters` | `admin.odn.splitters.store` | `odn.manage` | `outputs, input_port_id?, label?` |
  | PUT | `/admin/odn/splitters/{splitter}` | `admin.odn.splitters.update` | `odn.manage` | `input_port_id` (nullable), `label?` |
  | DELETE | `/admin/odn/splitters/{splitter}` | `admin.odn.splitters.destroy` | `odn.manage` | 422 if any output occupied |
  | DELETE | `/admin/odn/ports/{port}` | `admin.odn.ports.destroy` | `odn.manage` | PON ports only; 422 if feeding a splitter |
  | POST | `/admin/odn/ports/{port}/drop` | `admin.odn.drops.store` | `odn.assign` | `subscription_id` |
  | DELETE | `/admin/odn/ports/{port}/drop` | `admin.odn.drops.destroy` | `odn.assign` | release |

- **Page props** (backend owns controllers, UI renders; TS shapes):
  - `Admin/Ipam/Prefixes/Index`: `prefixes: {id, parent_id, depth, cidr, family, usage, location: {id, code}|null, vlan: {id, vid, name}|null, description, size: string, usable: string|null, assigned: number, reserved: number, free: string|null, percent: number|null}[]` (flat, ordered by family then `range_from`; UI indents by `depth`), `filters`, `locations: {id, code, name}[]` (POPs), `vlans: {id, vid, name, location_code}[]`, `can: {manage: boolean}`.
  - `Admin/Ipam/Prefixes/Show`: `prefix` (same row shape plus `parent: {id, cidr}|null`), `children` (row shape), `addresses: Paginated<{id, address, status, holder: {type: 'subscription'|'asset'|null, id, label, href}|null, note, assigned_at}>`, `can: {manage, assign}`.
  - `Admin/Ipam/Vlans/Index`: `vlans: Paginated<{id, vid, name, location: {id, code}|null, description, prefixes_count}>`, `locations`, `can: {manage}`.
  - `Admin/Odn/Index`: `odps: Paginated<{asset_id, code, name, location_path, capacity, used, free, percent}>`, `ponPorts: Paginated<{port_id, olt_asset_id, olt_code, number, label, subscribers}>`, `unmapped: Paginated<{subscription_id, code, customer_name, serving_pop_code, status}>`, `filters`, `pops: {id, code, name}[]`.
  - `Admin/Odn/Asset`: `asset: {id, code, name, asset_type, location_path}`, `ponPorts: {id, number, label, splitter_id: number|null, subscribers}[]` (OLT), `splitters: {id, label, outputs, input: {port_id, label}|null, ports: {id, number, label, subscription: {id, code, customer_name}|null, feeds_splitter_id: number|null}[]}[]` (ODC/ODP), `inputOptions: {port_id, label}[]` (free upstream ports in the company), `can: {manage, assign}`.
  - `Admin/Subscriptions/Show` adds `ipAddresses: {id, family, address, prefix_cidr, status}[]`, `staticPools: {id, cidr, free: string}[]` (only with `ipam.assign`), `dropPort: {port_id, number, odp_asset_id, odp_code, odp_name, location_path}|null`, lazy `dropPortOptions` (same shape as onboarding).
  - `Admin/Customers/Create` adds lazy `dropPortOptions: {port_id, label, odp_code, location_path, free_count}[]`, loaded by an Inertia partial reload with query `serving_pop_id` (ADR-002: no manual fetch). Request field `subscription.odn_port_id` nullable integer.
  - `Admin/SPK/Show` adds `dropPort` (same shape as above, read-only) for installation SPKs.
  - `Admin/NetworkAssets/Show`: no new prop; the page links to `admin.odn.assets.show` for `olt|odc|odp` assets.
- **Navigation:** group `network-work-orders` gains "IP Addresses" (`/admin/ipam/prefixes`, `anyOf: ['ipam.view']`) and "ODN" (`/admin/odn`, `anyOf: ['odn.view']`).
- **Audit:** `LogsActivity` on the five new models; `AuditService::log` for assign, release and import with subscription and holder ids.

## Testing Decisions

- Good tests assert externally visible results: HTTP status and redirect, rows in `ip_prefixes`, `ip_addresses`, `vlans`, `odn_ports`, `odn_splitters`, activity log entries, the dispatched event — not service internals. Feature tests through routes are the highest seam; services are called directly only for the onboarding and terminate hooks and the concurrency smoke.
- Matrix (each row one named test): prefix nesting auto-parent and re-parenting; exact duplicate 422; partial overlap 422; IPv6 normalisation (`2001:DB8:0::/48` → `2001:db8::/48`); delete with children 422; VLAN duplicate in same POP 422, same VID in another POP OK, company-wide duplicate 422; auto-assign lowest free skipping network/broadcast/reserved; /31 uses both addresses; full pool 422; manual duplicate 422 naming holder; same address in two companies OK; second IPv4 for one subscription 422; terminated subscription refused; release returns the address; terminate releases IP and port and dispatches `IpAssignmentChanged`; `staticAddressFor` returns dotted IPv4 or null; asset create/update with an address held by a subscription 422 and writes nothing; import dry-run changes nothing, real run is idempotent and reports duplicates; PON bulk numbering; splitter creates N outputs; connect to an occupied port 422; cycle 422; delete splitter with an occupied output 422; drop on a non-ODP or occupied port 422; moving a drop releases the old port; drop outside the serving POP 422; onboarding with `odn_port_id` reserves the port, with a full or foreign port 422 and nothing created; occupancy and PON subscriber counts; unmapped-subscription list; permissions per role (403); cross-company 404 on every route; module API not exposed.
- Concurrency: two parallel processes auto-assigning in the same /30 pool, and two parallel drop assignments to the last free port, each yield exactly one winner (prior art `tests/Feature/SPK/SerializedAssetConcurrencySmokeTest.php`).
- Prior art: `tests/Feature/NetworkAsset/NetworkAssetLifecycleRouteTest.php`, `tests/Feature/TenantBoundaryTest.php`, `tests/Feature/CustomerAccountCreationTest.php`, `tests/Feature/SPK/SpkCompletionOrchestrationTest.php` (must stay green unchanged), `tests/Feature/ApiExposureTest.php`, `tests/Feature/AdminRouteTest.php`, `tests/Feature/DemoDataSeederTest.php`; E2E `tests/e2e/customer-subscription.spec.ts`, `tests/e2e/location-map-first.spec.ts`.
- Commands: PHPUnit only via `./vendor/bin/sail exec laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact <paths>`; E2E only via `flock /tmp/inbils-e2e.lock sh -c 'npm run build && php tests/e2e/setup.php && npm run test:e2e -- <specs> --project=chromium --workers=1'`.
- Gates: scoped Pint, Larastan, ESLint/Prettier on touched files; `npm run build`; `composer audit` pasted (new dependency); security-reviewer pass (new permissions, rule 10).

## Out of Scope

- Any device interaction: OLT/ONT provisioning, SNMP, router or RADIUS config push (MikroTik/RADIUS lane), DHCP server, DNS/PTR.
- Dynamic lease tracking, CGNAT pools and port-block mapping, VRFs / overlapping private space, IPv6 auto-allocation and prefix delegation, per-subscriber VLAN/QinQ.
- Loss budget (Q12), maps and fibre routes (Q13, roadmap item 9), splice records, cable segments, port fault states, an ODP physical port count separate from splitter outputs.
- Enforcing a drop port at SPK completion (Q9b), relocation SPK automation, CSV import of ODN data.
- Changing `locations` types or the ONT installation location.

## Further Notes

- Tickets: 01 backend IPAM → 02 backend ODN → 03 backend integration (blocked by 01, 02); 04 UI IPAM (after 01); 05 UI ODN (after 02); 06 UI integration (after 03, 04, 05); 07 tester (after 03, 06); 08 reviewer (after 07). 01 runs first because it owns the permission seeder for all six permissions.
- File ownership (each file in exactly one ticket): 01 — `composer.json`, `composer.lock`, `modules_statuses.json`, `database/seeders/RolePermissionSeeder.php`, `Modules/Ipam/**`, `NetworkAssetController.php` (`store`/`update`/`destroy`), `tests/Feature/Ipam/*` except 07's file; 02 — new ODN files in `Modules/NetworkAsset/**`, `Modules/NetworkAsset/routes/web.php`, `tests/Feature/Odn/*` except 07's file; 03 — `SubscriptionService.php`, `CreateCustomerOnboardingAction.php`, `StoreCustomerRequest.php`, `CustomerController.php`, `SubscriptionController.php`, `WorkOrderController.php`, `DemoDataSeeder.php`, `Demo/IpamOdnDemo.php`, `docs/business/network.md`, `docs/ENTERPRISE_ISP_READINESS.md`, `CHANGELOG.md`, `tests/Feature/IpamOdnLifecycleTest.php`; 04 — `resources/js/Pages/Admin/Ipam/**`; 05 — `resources/js/Pages/Admin/Odn/**`; 06 — `navigation.ts`, `Subscriptions/Show.tsx`, `Customers/Create.tsx`, `SPK/Show.tsx`, `NetworkAssets/Show.tsx`; 07 — `tests/Feature/Ipam/IpamTenantAndPermissionMatrixTest.php`, `tests/Feature/Odn/OdnTenantAndPermissionMatrixTest.php`, `tests/e2e/ipam-odn.spec.ts`.
- Cross-lane (MikroTik/RADIUS, agreed 2026-10-11): that lane consumes `staticAddressFor` and `IpAssignmentChanged` only, and also edits `app/Services/Core/SubscriptionService.php` (suspension source), `database/seeders/RolePermissionSeeder.php`, `resources/js/lib/navigation.ts`, `Modules/NetworkAsset/routes/web.php` and `Modules/NetworkAsset/app/Providers/NetworkAssetServiceProvider.php`. Land those files serially, never in parallel branches; re-read line numbers before editing. `customer-status-rollup` also touches `SubscriptionService` (line numbers above are current as of 2026-10-11).
- Docs when shipped: `docs/business/network.md` (IPAM and ODN sections; correct ":13 IP address unik"), `docs/ENTERPRISE_ISP_READINESS.md:7` (Q1), CHANGELOG; any new docs file needs the `.gitignore` docs allowlist. Deploy: `composer install`, `php artisan migrate`, `php artisan db:seed --class=RolePermissionSeeder --force`, then `php artisan ipam:import-asset-ips --dry-run` per company before the real run.
