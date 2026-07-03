# Module: Network Asset Management

> Status: DRAFT v2 (ISP pivot 2026-06-30). Phase 3 eksekusi. Dependency:
> Phase 1 (Core + Location topology) + Phase 2 (Customer/Subscription
> shared). Lokasi module: `Modules/NetworkAsset/` (nwidart). Models di
> `Modules/NetworkAsset/Models/` (keputusan B1 — NetworkAsset module
> TERPISAH dari Inventory: durable tracked equipment vs consumable stock).

## Tujuan

Mengelola aset jaringan ISP (Router, Switch, OLT, ONU/ONT, Radio,
Antenna, Fiber, ODP, ODC, Rack, Power equipment) sebagai individual
tracked equipment: serial number + MAC address + IP address unik,
lokasi topologi (POP/Rack/Site), status lifecycle, installation history
(append-only), relasi ke customer + subscription. Sumber kebenaran aset
untuk NOC (troubleshoot), SPK (instalasi/maintenance), Ticketing (trace),
Reporting (asset utilization).

Inventory (general stock consumable) = module terpisah. Inventory
ItemSerial deprecated/merged ke NetworkAsset.

## User Role

| Role | Hak |
|------|-----|
| admin | full CRUD asset + installation + move + retire + location topology CRUD |
| manager | view + install/move/retire asset + location CRUD (no delete) |
| noc | view asset + location + installation history + trace (no edit) |
| staff | view only |
| technician | view asset + location (untuk SPK) + update status maintenance (limited) |

## Entity

### NetworkAsset (Modules/NetworkAsset/Models/NetworkAsset.php)
Aset jaringan individual. Bisa reference ke Product (Inventory) untuk
catalog info (model/type/spec), TAPI tracked individually via serial.
Status lifecycle. Lokasi topologi (location_id — POP/Rack/Site).
Customer + subscription link (jika installed at customer premise —
mis. ONT/Router customer). Ownership (owned/leased/customer-provided).

### NetworkAssetInstallation (Modules/NetworkAsset/Models/NetworkAssetInstallation.php)
History installation append-only (immutable, tidak soft delete). Setiap
install/move/remove = 1 row. Catat: asset + location + customer +
subscription + installed_at + removed_at + spk_id (SPK yang trigger
install). Untuk audit trail + reporting (asset movement history).

### Location (app/Models/Core/Location.php — shared, A1)
Topologi lokasi ISP: hierarchy Region > Area > POP > Rack > Site/Slot.
Adjacency list parent_id self-ref. Type enum. Materialized path untuk
fast read. NetworkAsset + Stock + ServiceSubscription + Customer
(area_coverage/serving_pop) reference location_id. Consumed by 5 module
→ shared Core.

## Database Relation

```
companies ──< locations (self-ref parent_id)
locations ──< locations (children)
companies ──< network_assets
products ──< network_assets (FK product_id nullable — catalog ref, restrict)
locations ──< network_assets (FK location_id — current placement, restrict)
customers ──< network_assets (FK customer_id nullable — if installed at premise)
service_subscriptions ──< network_assets (FK ont_asset_id nullable — ONT link, 1:1)
network_assets ──< network_asset_installations (cascade — history child)
locations ──< network_asset_installations (FK location_id)
customers ──< network_asset_installations (FK customer_id nullable)
service_subscriptions ──< network_asset_installations (FK subscription_id nullable)
work_orders ──< network_asset_installations (FK spk_id nullable — backlink)
users ──< network_asset_installations (FK installed_by)
```

Skema: `docs/DATABASE.md` Section 7 (NetworkAsset + Location).

## Entity Fields

### locations (topology — shared Core)
- id, company_id FK NOT NULL, parent_id nullable FK→locations.id (self-ref,
  restrict delete if children), code NOT NULL, name NOT NULL, type enum
  (region/area/pop/rack/site) NOT NULL, path varchar nullable
  (materialized: "REG-JKT > AREA-UTARA > POP-JKT01 > RACK-03 > SITE-S05",
  denormalized for fast read), address text nullable, lat decimal(10,7)
  nullable, lng decimal(10,7) nullable, is_active boolean default true,
  created_at, updated_at, deleted_at.
- Unique: `(company_id, code)`. Index: `(company_id, parent_id)`,
  `(company_id, type)`, `(company_id, path)`. Trait `BelongsToCompany`.
  Soft delete.
- Rule: type=region → parent_id null (root). type lain → parent_id
  wajib. Cycle prevention app-level (LocationService walk ancestor,
  reject if self in ancestor chain).
- Example hierarchy:
  - Region "Jakarta" (parent null)
    - Area "Jakarta Utara" (parent=Jakarta)
      - POP "POP-JKT01" (parent=Jakarta Utara)
        - Rack "RACK-03" (parent=POP-JKT01)
          - Site/Slot "SITE-S05" (parent=RACK-03)

### network_assets
- id, company_id FK NOT NULL, code NOT NULL (internal asset code),
  product_id nullable FK→products.id (restrict — catalog ref untuk
  model/type/spec, nullable jika non-catalog), name NOT NULL,
  asset_type enum (router/switch/olt/onu_ont/radio/antenna/fiber/
  odp/odc/rack/power/other) NOT NULL, serial_number varchar nullable
  (unique per company — wajib jika tracked), mac_address varchar nullable,
  ip_address varchar nullable (IPv4/IPv6), management_ip varchar nullable,
  location_id nullable FK→locations.id (restrict — current placement,
  null jika in-storage/available), customer_id nullable FK→customers.id
  (restrict — if installed at customer premise), subscription_id nullable
  FK→service_subscriptions.id (restrict — if linked to subscription,
  mis. ONT), status enum
  (available/installed/maintenance/damaged/retired) NOT NULL default
  'available', ownership enum (owned/leased/customer_provided) NOT NULL
  default 'owned', vendor varchar nullable (Huawei/Cisco/Mikrotik/...),
  model varchar nullable, purchase_date date nullable, purchase_price
  decimal(15,2) nullable, warranty_expiry date nullable, notes nullable,
  installed_at timestamp nullable, retired_at timestamp nullable,
  created_at, updated_at, deleted_at.
- Unique: `(company_id, serial_number)` (serial unique per company,
  nullable untuk non-tracked), `(company_id, code)`. Index:
  `(company_id, asset_type)`, `(company_id, status)`,
  `(company_id, location_id)`, `(company_id, customer_id)`. Trait
  `BelongsToCompany`. Soft delete.
- Check: `status IN ('available','installed','maintenance','damaged','retired')`,
  `ownership IN ('owned','leased','customer_provided')`.

### network_asset_installations (append-only history)
- id, company_id FK NOT NULL, network_asset_id FK NOT NULL (cascade),
  location_id FK NOT NULL (restrict), customer_id nullable FK→customers.id
  (restrict), subscription_id nullable FK→service_subscriptions.id
  (restrict), spk_id nullable FK→work_orders.id (restrict — SPK trigger),
  installed_by FK→users.id (restrict), installed_at timestamp NOT NULL,
  removed_at timestamp nullable (null = currently installed here),
  removal_reason varchar nullable, created_at, updated_at.
- Index: `(company_id, network_asset_id)`, `(company_id, location_id)`,
  `(company_id, customer_id)`, `(company_id, spk_id)`,
  `(network_asset_id, removed_at)`. Trait `BelongsToCompany`.
- TIDAK soft delete (audit trail immutable, append-only).
- Rule: 1 asset max 1 active installation (removed_at=null) at a time.
  App guard at NetworkAssetService.

## Workflow

Lihat `docs/WORKFLOW.md` Section 4 (NetworkAsset + Location). Ringkas:

### Asset status lifecycle

```
[available] ──install──→ [installed] ──maintenance──→ [maintenance] ──resume──→ [installed]
                              │                                              │
                              ├──damage──→ [damaged] ──repair──→ [available]   │
                              │                                                │
                              └──remove──→ [available]                          │
                              └──retire──→ [retired]                            │
[maintenance] ──retire──→ [retired]
[damaged] ──retire──→ [retired]
```

| Dari | Ke | Trigger | Side effect |
|------|-----|---------|-------------|
| available | installed | install(asset, location, customer?, subscription?, spk?) | status=installed, location_id set, customer_id/subscription_id set (if premise asset), installed_at=now, NetworkAssetInstallation::create (removed_at=null), AuditLog. Dipanggil InstallNetworkAssetAction (from CompleteSpkAction) atau manual. |
| installed | available | remove(asset, reason) | status=available, location_id=null, customer_id=null, subscription_id=null, NetworkAssetInstallation (active).removed_at=now + removal_reason, AuditLog. |
| installed | maintenance | setMaintenance(asset, reason) | status=maintenance, AuditLog. (asset tetap di lokasi, flag maintenance) |
| maintenance | installed | resume(asset) | status=installed, AuditLog. |
| installed/maintenance | damaged | setDamaged(asset, reason) | status=damaged, AuditLog. (perlu repair sebelum available lagi) |
| damaged | available | repair(asset) | status=available, AuditLog. |
| * | retired | retire(asset, reason) | status=retired, retired_at=now, NetworkAssetInstallation active removed_at=now, release subscription link (if any), AuditLog. Terminal state. |

### Install from SPK
- `CompleteSpkAction` (SPK type=installation) panggil
  `InstallNetworkAssetAction`:
  - Validate asset.status=available (atau damaged→reject).
  - Set asset location = SPK location (installation point at customer
    premise untuk ONT, atau POP untuk OLT/switch).
  - Set asset customer_id + subscription_id (if ONT/router premise).
  - Create NetworkAssetInstallation row (spk_id backlink).
  - Call SubscriptionService::activate(subscription) → subscription
    status=active, ont_asset_id set.

### Location topology CRUD (LocationService — app/Services/Core/)
- create(name, type, parent): validate parent type hierarchy (region→area
  →pop→rack→site), recompute path (parent.path + " > " + code), cycle
  check.
- update(name/code): recompute path + recurse children path.
- move(node, new_parent): cycle check (reject if new_parent is descendant),
  recompute path + recurse children.
- delete(node): reject if has children OR active network_assets placed
  OR active stocks. Soft delete if clear.

## Permission

```
network_asset.view
network_asset.create
network_asset.update
network_asset.delete
network_asset.install
network_asset.remove
network_asset.maintenance
network_asset.repair
network_asset.retire
network_asset.export
network_asset.manage          (super)
location.view
location.create
location.update
location.delete
location.move
location.manage               (super)
```

## API (Route)

Lihat `docs/API.md` Section 4 (NetworkAsset + Location group). Ringkas:

- `GET    /admin/network-assets` (list, filter: asset_type, status, location_id, customer_id, search serial/mac/ip)
- `POST   /admin/network-assets` (create — available status default)
- `GET    /admin/network-assets/{asset}` (detail + current installation + history + linked subscription/customer)
- `PUT    /admin/network-assets/{asset}` (update — no status change via PUT)
- `DELETE /admin/network-assets/{asset}` (soft delete — restrict if installed/active)
- `POST   /admin/network-assets/{asset}/install` (body: location_id, customer_id?, subscription_id?, spk_id?)
- `POST   /admin/network-assets/{asset}/remove` (body: reason)
- `POST   /admin/network-assets/{asset}/maintenance` (body: reason)
- `POST   /admin/network-assets/{asset}/resume`
- `POST   /admin/network-assets/{asset}/damage` (body: reason)
- `POST   /admin/network-assets/{asset}/repair`
- `POST   /admin/network-assets/{asset}/retire` (body: reason)
- `GET    /admin/network-assets/{asset}/installations` (history)
- `GET    /admin/network-assets/trace` (query: serial/mac/ip/customer_id/subscription_id → return asset + location path + status)
- `GET    /admin/network-assets/export`
- `GET    /admin/locations` (tree, filter: type, parent_id, search)
- `POST   /admin/locations` (create with parent)
- `GET    /admin/locations/{loc}` (detail + children + assets placed + stocks)
- `PUT    /admin/locations/{loc}` (update)
- `DELETE /admin/locations/{loc}` (soft delete — restrict if children/assets/stocks)
- `POST   /admin/locations/{loc}/move` (body: new_parent_id)

## Testing Scenario

### Location hierarchy
1. Create Region "Jakarta" (parent null, type=region). path="REG-JKT".
2. Create Area "Jakarta Utara" (parent=Jakarta, type=area).
   path="REG-JKT > AREA-UTARA".
3. Create POP "POP-JKT01" (parent=area, type=pop).
4. Create Rack "RACK-03" (parent=pop, type=rack).
5. Create Site "SITE-S05" (parent=rack, type=site).
6. Move Rack to different POP → path recompute + children path recurse.
7. Cycle: move POP to its descendant Site → 422 "cycle detected".
8. Delete POP with Rack child → 422. Delete empty Site → 200.

### Asset lifecycle
1. Create NetworkAsset: code AST-001, type=onu_ont, serial ONT-HW-12345,
   vendor Huawei, status=available, ownership=owned.
2. Install (location=customer address via installation point? NO —
   location = POP rack or customer premise site). For ONT at premise:
   location = site under POP (or customer address mapping v2). Set
   customer_id + subscription_id. status=installed, installed_at=now,
   NetworkAssetInstallation row (removed_at=null).
3. Set maintenance (reason "upgrade firmware"): status=maintenance.
4. Resume: status=installed.
5. Remove (reason "replace"): status=available, installation removed_at=now.
6. Retire (reason "EOL"): status=retired, retired_at=now. Terminal.

### Install from SPK
1. SPK type=installation, customer + subscription + location + asset
   (ONT available) assigned.
2. CompleteSpkAction → InstallNetworkAssetAction: asset.status=installed,
   location set, customer+subscription set, NetworkAssetInstallation
   (spk_id backlink), SubscriptionService::activate → subscription
   active, ont_asset_id set.

### Trace (NOC)
1. Query trace by serial ONT-HW-12345 → return asset + location path
   ("REG-JKT > AREA-UTARA > POP-JKT01 > RACK-03 > SITE-S05") + status
   installed + customer + subscription.
2. Query by customer_id → return all assets linked (ONT + router).

### Validation
1. Install asset already installed → 422 (max 1 active installation).
2. Retire already-retired → 422.
3. Delete asset installed → 422 (remove first).
4. serial_number duplicate per company → 422.

### Authorization
1. NOC view + trace → 200. NOC install → 403.
2. Technician view → 200. Technician set maintenance → 200 (limited).
   Technician retire → 403.
3. Manager install/move/retire → 200. Manager delete → 403.

## Acceptance Criteria

- [ ] Location topology CRUD (region/area/pop/rack/site) + path
  materialized + cycle prevention + recurse on move/rename.
- [ ] Asset status lifecycle (available→installed→maintenance→installed→
  damaged→available→retired) dengan side effect benar.
- [ ] NetworkAssetInstallation append-only (1 active per asset, removed_at
  set on remove/retire).
- [ ] Install from SPK: CompleteSpkAction triggers InstallNetworkAssetAction
  + SubscriptionService::activate.
- [ ] Trace endpoint: search by serial/mac/ip/customer/subscription →
  return asset + location path + status + links.
- [ ] serial_number unique per company.
- [ ] Location delete restrict if children/assets/stocks.
- [ ] Asset delete restrict if installed/active.
- [ ] Number/code generation unique + race-safe (AST + LOC).
- [ ] Policy per aksi.
- [ ] Activity log: location create/move/delete, asset create/install/
  remove/maintenance/damage/repair/retire/delete.
- [ ] Factory + seeder (3 region, 6 area, 10 pop, 20 rack, 40 site + 50
  asset: 20 available, 20 installed, 5 maintenance, 3 damaged, 2 retired).
- [ ] Feature test ≥ 80% coverage.
- [ ] UI: location tree (expandable), asset list (filter type/status/
  location/search), asset detail (current placement + history timeline +
  linked subscription/customer + lifecycle buttons), trace page (search
  form + result card).
- [ ] UI pakai Components/ui + composite (TopologyTree, AssetStatusBadge).
- [ ] Dark mode + responsive.

## Module Dependencies

- **Depends on (Phase 1):** Core (Company, User, Role, Permission,
  ActivityLog, Setting), Location (shared Core — topology CRUD via
  LocationService di app/Services/Core/).
- **Depends on (Phase 2):** Customer/Subscription (shared Core —
  NetworkAsset.customer_id + subscription_id direct FK; untuk ONT
  premise link). ServicePackage (tidak langsung — via subscription).
- **Depends on (Phase 3 peer):** Inventory (Product — network_assets
  .product_id nullable FK untuk catalog ref model/type/spec. Product di
  Modules/Inventory, FK direct seperti Product).
- **Consumed by (Phase 4+):** SPK (InstallNetworkAssetAction dari
  CompleteSpkAction), Ticketing (trace link asset_id), Reporting (asset
  utilization metrics), Billing (tidak langsung — via subscription
  ont_asset_id untuk suspend side effect ke asset v2 Radius).
- **Shared Core (A1):** Location di app/Models/Core/ — direct import
  oleh Inventory (stock placement), Customer (area_coverage/serving_pop),
  SPK (work location), Ticketing (issue location), NetworkAsset.
  LocationService di app/Services/Core/ — direct call.
