# Module: Inventory

> Status: DRAFT v2 (ISP pivot 2026-06-30). Phase 3 eksekusi. Dependency:
> Phase 1 (Core + Location topology) + Phase 2 (Customer/Service). Lokasi
> module: `Modules/Inventory/` (nwidart). Keputusan B1: Inventory = GENERAL
> STOCK CONSUMABLE only (kabel/connector/sparepart). NetworkAsset (durable
> tracked equipment) = module TERPISAH (Modules/NetworkAsset). ItemSerial
> deprecated/merged ke NetworkAsset. Warehouse replaced by Location topology
> (POP/Rack/Site).

## Tujuan

Mengelola master data barang consumable (kabel, connector, sparepart,
dll), stok per lokasi topologi (POP/Rack/Site), pergerakan stok (stock
movements) sebagai audit trail immutable. Menyediakan data konsumsi untuk
SPK (instalasi/maintenance). Sumber kebenaran stok consumable untuk semua
modul.

NetworkAsset (router/OLT/ONU — durable tracked equipment) = module
terpisah, lihat `docs/modules/network-asset.md`. TIDAK bercampur dengan
consumable Inventory.

## User Role

Matriks izin resmi dan konflik pemisahan tugas (SoD) ada di
`docs/SECURITY.md` §2; tabel ini hanya ringkasan Inventory.

| Role | Hak |
|------|-----|
| admin | semua izin, termasuk `inventory.stock.adjust` (penyesuaian langsung, break-glass; hanya admin) |
| manager | controller: approve/reject permintaan barang dan approve penyesuaian stok; CRUD product (no delete); tidak receive/issue/transfer dan tidak prepare/issue permintaan |
| staff | operator: receive/issue/transfer stok, ajukan penyesuaian stok, prepare/issue permintaan barang; tidak approve apa pun |
| technician | view stock + locations; issue stok (`inventory.stock.issue`); tidak receive/transfer/adjust |

## Entity

### Product (Modules/Inventory/Models/Product.php)
Catalog barang consumable. SKU unique per company. Category + unit.
Track stock flag ( consumable selalu track_stock=true). Dynamic
attribute/variant (v2 — v1 simple flat product).

### Category (Modules/Inventory/Models/Category.php)
Kategori barang. Tree (self-ref parent). Contoh: Kabel, Connector,
Sparepart, Tools.

### Unit (Modules/Inventory/Models/Unit.php)
Satuan. pcs, meter, roll, pack, box.

### Stock (Modules/Inventory/Models/Stock.php)
Stok per (product, location_id). Location = topology (POP/Rack/Site),
BUKAN warehouse. quantity + reserved_quantity. Unique per combo.

### StockMovement (Modules/Inventory/Models/StockMovement.php)
Audit trail immutable (tidak soft delete). Setiap pergerakan stok = 1-2
row. Type: receive/issue/transfer/adjustment/reserve/release/return.
from_location_id + to_location_id. reference polymorphic (SPK/Invoice).

## Database Relation

```
companies ──< categories (self-ref parent)
companies ──< units
companies ──< products
categories ──< products
units ──< products
companies ──< stocks
products ──< stocks (FK product_id, restrict delete)
locations ──< stocks (FK location_id, restrict delete)
companies ──< stock_movements
products ──< stock_movements (FK product_id)
locations ──< stock_movements (FK from_location_id, to_location_id)
users ──< stock_movements (FK created_by)
work_orders ──< stock_movements (polymorphic reference)
invoices ──< stock_movements (polymorphic reference, rare)
```

Skema: `docs/DATABASE.md` Section 8 (Inventory).

## Entity Fields

### categories
- id, company_id FK NOT NULL, parent_id nullable FK→categories.id (self-ref,
  restrict), name NOT NULL, code NOT NULL, description nullable, is_active
  boolean default true, created_at, updated_at, deleted_at.
- Unique: `(company_id, code)`. Index: `(company_id, parent_id)`. Trait
  `BelongsToCompany`. Soft delete.

### units
- id, company_id FK NOT NULL, name NOT NULL, symbol NOT NULL, created_at,
  updated_at, deleted_at.
- Unique: `(company_id, name)`, `(company_id, symbol)`. Trait
  `BelongsToCompany`. Soft delete.

### products
- id, company_id FK NOT NULL, category_id FK NOT NULL (restrict), unit_id
  FK NOT NULL (restrict), sku NOT NULL, name NOT NULL, description
  nullable, type enum(consumable) NOT NULL default 'consumable',
  track_stock boolean default true, sell_price decimal(15,2) nullable,
  cost_price decimal(15,2) nullable, min_stock decimal(15,2) default 0,
  is_active boolean default true, created_at, updated_at, deleted_at.
- Unique: `(company_id, sku)`. Index: `(company_id, category_id)`,
  `(company_id, name)`. Trait `BelongsToCompany`. Soft delete.

### stocks
- id, company_id FK NOT NULL, product_id FK NOT NULL (cascade), location_id
  FK NOT NULL (restrict), quantity decimal(15,2) NOT NULL default 0,
  reserved_quantity decimal(15,2) NOT NULL default 0, created_at,
  updated_at.
- Unique: `(company_id, product_id, location_id)`. Index:
  `(company_id, location_id)`, `(company_id, product_id)`. Trait
  `BelongsToCompany`. TIDAK soft delete.
- Check: `quantity >= 0`, `reserved_quantity >= 0`,
  `reserved_quantity <= quantity`.

### stock_movements
- id, company_id FK NOT NULL, product_id FK NOT NULL (restrict),
  from_location_id nullable FK→locations.id (restrict), to_location_id
  nullable FK→locations.id (restrict), movement_type enum
  (receive/issue/transfer/adjustment/reserve/release/return) NOT NULL,
  quantity decimal(15,2) NOT NULL (positive untuk receive/transfer-in/
  reserve/release-remove/return, negative untuk issue/transfer-out),
  balance_after decimal(15,2) NOT NULL (snapshot qty after movement),
  reserved_after decimal(15,2) NOT NULL (snapshot reserved after),
  reference_type varchar nullable (WorkOrder/Invoice/Purchase/Manual),
  reference_id bigint unsigned nullable, note nullable, created_by
  FK→users.id NOT NULL, created_at, updated_at.
- Index: `(company_id, product_id)`, `(company_id, from_location_id)`,
  `(company_id, to_location_id)`, `(company_id, movement_type)`,
  `(reference_type, reference_id)`, `(created_at)`. Trait
  `BelongsToCompany`. TIDAK soft delete (immutable audit trail).
- Check: `movement_type IN ('receive','issue','transfer','adjustment',
  'reserve','release','return')`.

## Workflow

Lihat `docs/WORKFLOW.md` Section 5 (Inventory). Ringkas:

### Product lifecycle
```
[create] → [active] → [deactivate] → [deleted (soft)]
              │
              └─[track_stock=true] → stocks row auto-create per location saat first movement
```

### Stock movement
```
receive:     from=null, to=location, qty>0, balance_after update
issue:       from=location, to=null, qty<0, balance_after update (SPK consume)
transfer:    from=A, to=B, 2 rows (A: issue qty<0, B: receive qty>0)
adjustment:  from=to=location, delta ±, note wajib
reserve (permintaan barang disetujui): from=null, to=location, reserved_qty++ (no qty change)
release (serah terima permintaan barang): reserved_qty--, disusul issue
return:      from=null, to=location, qty>0 (SPK material return)
```

### Stok dan SPK
- Item SPK (`work_order_items`) tidak mereservasi stok. Setting
  `spk.stock_reservation_mode` tidak dibaca kode lagi, dan SPK yang ditugaskan
  atau dibatalkan tidak menggerakkan stok. SPK mengeluarkan stok hanya saat
  approve (`CompleteSpkAction::consumeStock`, `StockService::issue`).
- Reserve dan release hanya dipakai permintaan barang: persetujuan
  mereservasi (`approved_reserved`), serah terima melepas reserve lalu issue.

### Permintaan barang untuk SPK (`source_type=work_order`)
- Permintaan boleh menyebut SPK sebagai sumber (`source_type=work_order`,
  `source_id`). Dari web, SPK dipilih di form permintaan dari SPK yang belum
  `completed` atau `cancelled` dan terlihat oleh pembuatnya. Dari aplikasi
  teknisi, `POST /api/v1/technician/inventory-requests` menerima
  `work_order_id` (public id, opsional). SPK itu harus milik teknisi yang
  meminta dengan status `assigned` atau `in_progress`; selain itu 422
  "SPK harus SPK aktif (ditugaskan atau dikerjakan) milik Anda." untuk semua
  penyebab (SPK tidak ada, milik rekan, atau sudah ditutup). Tanpa
  `work_order_id` permintaan tidak punya SPK.
- Alasan yang dikosongkan diisi "Permintaan material untuk SPK {kode}".
- Serah terima mengunci SPK sumbernya lebih dulu, dan menolak (422) bila SPK
  sudah `completed` atau `cancelled`: "SPK sumber sudah selesai atau dibatalkan;
  barang tidak bisa diserahterimakan."
- Saat SPK disetujui, barang yang sudah diserahkan lewat permintaan `issued`
  untuk SPK itu tidak dikeluarkan lagi (dicatat `stock_consumption_skipped_ir_backed`).
  Selisih jumlah terpakai di atas jumlah yang diserahkan dikeluarkan dari
  lokasi sumber item permintaan (atau gudang permintaan), bukan dari lokasi SPK.
  Barang yang tidak pernah diminta keluar dari lokasi SPK.

### Aturan hitung ulang (penyesuaian stok)
- Permintaan penyesuaian menyimpan `old_quantity`, stok saat hitungan diambil.
  Saat disetujui, `StockService::adjust` membaca stok di bawah kunci dan
  membandingkannya dengan `old_quantity`. Beda berarti 409 "Stok berubah sejak
  dihitung; hitung ulang." dan permintaan tetap `pending`. Penyesuaian
  langsung oleh admin (`/admin/stocks/adjust/direct`) tidak membawa
  `old_quantity` dan tidak dibandingkan.

### Low stock alert
- Threshold: `products.min_stock`. Saat movement: jika
  `quantity <= min_stock` → badge di product list + dashboard widget.

### Stok constraint
- `stocks.quantity >= 0` (DB check).
- `reserved_quantity <= quantity` (DB check).
- Reserve gagal kalau stok tidak cukup → `InsufficientStockException`.

### Item Finder (search consumable)
- `ItemFinderQuery`: input name/sku/category → output (product, location,
  location_path, quantity, available). Route `GET /admin/inventory/find`.
- Berbeda dari NetworkAsset trace (individual asset by serial).

## Permission

```
inventory.view
inventory.create
inventory.update
inventory.delete
inventory.stock.view
inventory.stock.receive
inventory.stock.issue
inventory.stock.transfer
inventory.stock.adjust          (langsung / break-glass, hanya admin)
inventory.stock.adjust.request  (ajukan penyesuaian; staff operator)
inventory.stock.adjust.approve  (setujui/tolak penyesuaian; manager controller)
inventory.movement.view
inventory.export
inventory.manage        (super)
```

## API (Route)

Lihat `docs/API.md` Section 5 (Inventory group). Ringkas:

- `GET    /admin/products` (list, filter: category, is_active, search sku/name)
- `POST   /admin/products`
- `GET    /admin/products/{product}` (detail + stocks per location + recent movements)
- `PUT    /admin/products/{product}`
- `DELETE /admin/products/{product}` (soft delete — restrict if stock > 0)
- `GET    /admin/categories` (+ POST/PUT/DELETE tree)
- `GET    /admin/units` (+ POST/PUT/DELETE)
- `GET    /admin/stocks` (filter: location_id, product_id, low_stock)
- `POST   /admin/stocks/receive` (body: product_id, location_id, quantity, note)
- `POST   /admin/stocks/issue` (body: product_id, location_id, quantity, reference_type?, reference_id?, note)
- `POST   /admin/stocks/transfer` (body: product_id, from_location_id, to_location_id, quantity, note)
- `POST   /admin/stocks/adjust` (body: product_id, location_id, new_quantity, note wajib) — membuat permintaan penyesuaian `pending`, stok belum berubah (`inventory.stock.adjust.request`)
- `POST   /admin/stocks/adjustments/{adjustment}/approve` — menerapkan penyesuaian; approver wajib orang lain dari pemohon (`inventory.stock.adjust.approve`)
- `POST   /admin/stocks/adjustments/{adjustment}/reject` (body: rejection_reason) — approver ≠ pemohon (`inventory.stock.adjust.approve`)
- `POST   /admin/stocks/adjust/direct` (body sama dengan adjust) — penyesuaian langsung, audit log bertanda `break_glass` (`inventory.stock.adjust`, hanya admin)
- `GET    /admin/stock-movements` (history, filter: product, location, type, date range, reference)
- `GET    /admin/inventory/find` (Item Finder: search → locations + qty)
- `GET    /admin/products/export`

## Testing Scenario

### Receive + issue + transfer
1. Create Product: sku KBL-UTP-CAT6, name "Kabel UTP Cat6", category
   Kabel, unit meter, track_stock=true.
2. Receive 100 meter at POP-JKT01/RACK-03: stocks row create, quantity=100,
   movement_type=in.
3. Transfer 30 meter to POP-BDG01: from=POP-JKT01, to=POP-BDG01. 2 rows.
   POP-JKT01 quantity=70, POP-BDG01 quantity=30.
4. Issue 20 meter (SPK reference): POP-JKT01 quantity=50, movement_type=out.

### Reserve/release (permintaan barang)
1. Permintaan barang 15 meter disetujui, gudang POP-JKT01: reserved_quantity=15
   (quantity tetap 70). movement_type=reserve.
2. Serah terima: reserve dilepas (reserved_quantity=0, movement_type=release),
   lalu issue: quantity=55 (70-15), movement_type=issue qty=-15.
3. Permintaan yang dibatalkan hanya saat draft atau submitted, sebelum ada
   reserve, jadi tidak ada release.

### SPK dan stok
1. SPK dengan item kabel 20 meter ditugaskan: tidak ada reserve, stok tidak
   berubah.
2. Teknisi meminta 15 meter lewat aplikasi untuk SPK itu (`work_order_id`);
   permintaan disetujui dan diserahkan seperti di atas (quantity 70 menjadi 55).
3. SPK disetujui dengan item kabel terpakai 20 meter: 15 meter tercakup
   permintaan barang dan tidak dikeluarkan lagi; 5 meter dikeluarkan dari
   gudang permintaan itu (quantity 55 menjadi 50).

### Validation
1. Issue 200 meter (stock 70) → 422 InsufficientStockException.
2. Reserve 80 (stock 70, reserved 0) → 422.
3. Adjust quantity 60 (from 55, note "physical count"): staff mengajukan →
   request `pending` dengan `old_quantity` 55, stok tetap 55; manager lain
   menyetujui → movement_type=adjustment, delta +5, created_by = approver,
   note = alasan pemohon. Bila stok sudah berubah sejak dihitung (mis. 55
   menjadi 50 karena issue lain) → 409 "Stok berubah sejak dihitung; hitung
   ulang.", request tetap `pending`, stok tidak berubah; pemohon mengajukan
   hitungan baru.
4. Delete product with stock > 0 → 422.

### Item Finder
1. Search "kabel" → return products + locations with qty + path.

### Authorization
1. Technician view stock → 200. Technician issue stock → 200 (`inventory.stock.issue`); technician receive/transfer/adjust → 403.
2. Staff receive/issue/transfer → 200. Staff ajukan adjust → 200 (request pending). Staff approve/adjust langsung → 403.
3. Manager receive/issue/transfer atau ajukan adjust → 403. Manager approve/reject adjust milik staff → 200; approve miliknya sendiri → 403. Manager delete product → 403.
4. Admin adjust langsung (`/admin/stocks/adjust/direct`) → 200, audit `break_glass`; admin approve request sendiri → 403.

## Acceptance Criteria

- [ ] CRUD product/category/unit sesuai.
- [ ] Stock movement 7 type (receive/issue/transfer/adjustment/reserve/
  release/return) dengan from/to location + balance_after snapshot.
- [ ] Reserve/release/consume flow: reserve saat permintaan barang disetujui, release + issue saat serah terima; SPK tidak reserve.
- [ ] Stok constraint (quantity >= 0, reserved <= quantity) DB check.
- [ ] InsufficientStockException saat reserve/issue melebihi available.
- [ ] Item Finder search consumable → locations + qty.
- [ ] Low stock badge (quantity <= min_stock).
- [ ] Soft delete product restrict if stock > 0.
- [ ] Number/code generation unique per company (SKU + category code).
- [ ] Policy per aksi.
- [ ] Activity log: product create/update, receive/issue/transfer/adjust/
  reserve/release/return.
- [ ] Factory + seeder (5 category, 5 unit, 20 product consumable, 30
  stock row, 60 movement).
- [ ] Feature test ≥ 80% coverage.
- [ ] UI: product list (filter category/search), product detail (stock
  per location card + movement timeline), stock receive/issue/transfer/
  adjust forms, movement history (filter), Item Finder page.
- [ ] UI pakai Components/ui + composite.
- [ ] Dark mode + responsive.

## Module Dependencies

- **Depends on (Phase 1):** Core (Company, User, Role, Permission,
  ActivityLog, Setting), Location (shared Core — stocks per location_id).
- **Consumed by (Phase 4):** SPK (CompleteSpkAction consume saat approve lewat
  StockService::issue, hanya untuk bagian yang belum dikeluarkan permintaan
  barang `source_type=work_order`; SPK tidak reserve dan tidak release;
  stock_movements.reference polymorphic WorkOrderItem). Kode Billing tidak
  memanggil StockService.
- **Product cross-module:** network_assets.product_id nullable FK→products.id
  (NetworkAsset references Product untuk catalog model/spec info — mirror
  Product relation). Allowed (catalog FK like ServicePackage).
- **TIDAK import:** SPK/Billing/Ticketing/NetworkAsset model. Reverse
  direction only via polymorphic reference (string type, no FK hard-coupling).
