# Module: Service Management

> Status: DRAFT v2 (ISP pivot 2026-06-30). Phase 2 eksekusi. Dependency:
> Phase 1 (Core). Lokasi module: `Modules/Service/` (nwidart). Models di
> `Modules/Service/Models/` (catalog, FK-consumed seperti Product di Inventory
> — keputusan A1: catalog master module-owned, bukan shared Core).

## Tujuan

Mengelola katalog layanan ISP: internet package (paket internet),
bandwidth profile, speed profile, SLA tier. Catalog = master data yang
dipilih saat create ServiceSubscription (Customer module). Dipakai
Billing (pricing MRC/OTC snapshot), SPK (instalasi reference), Reporting
(service distribution metrics).

## User Role

| Role | Hak |
|------|-----|
| admin | full CRUD catalog |
| manager | view only (catalog = admin-managed) |
| noc | view only |
| staff | view only |
| technician | view only |

## Entity

### ServicePackage (Modules/Service/Models/ServicePackage.php)
Paket layanan internet. Contoh: "Home 50Mbps", "Business 100Mbps
Dedicated", "Enterprise 500Mbps". Link ke BandwidthProfile +
SpeedProfile + SLATier. Harga MRC (Monthly Recurring Charge) + OTC
(One-Time Charge instalasi). is_active untuk soft-deprecate (tidak bisa
pilih baru, existing subscription tetap jalan).

### BandwidthProfile (Modules/Service/Models/BandwidthProfile.php)
Profil bandwidth: download/upload Mbps, dedicated vs shared (ratio),
contention ratio. Contoh: "50M/10M Shared 1:8", "100M/100M Dedicated".

### SpeedProfile (Modules/Service/Models/SpeedProfile.php)
Profil kecepatan: tier kecepatan + burst. Contoh: "Up to 50Mbps",
"Guaranteed 100Mbps". Bisa map ke Radius profile name (v2 auto-provisioning).

### SLATier (Modules/Service/Models/SLATier.php)
Tier SLA: response time guarantee + resolution time guarantee + uptime
guarantee. Contoh: "Bronze 99% 24h", "Silver 99.5% 8h", "Gold 99.9% 4h".
Dipakai Ticketing (default SLA per tier). v1: SLA breach = reporting
metric + flag (reporting.md). Billing credit v2.

## Database Relation

```
companies ──< service_packages
companies ──< bandwidth_profiles
companies ──< speed_profiles
companies ──< sla_tiers
bandwidth_profiles ──< service_packages (FK bandwidth_profile_id)
speed_profiles ──< service_packages (FK speed_profile_id)
sla_tiers ──< service_packages (FK sla_tier_id)
service_packages ──< service_subscriptions (Customer module)
```

Skema: `docs/DATABASE.md` Section 6 (Service).

## Entity Fields

### bandwidth_profiles
- id, company_id FK NOT NULL, name NOT NULL, download_mbps int NOT NULL,
  upload_mbps int NOT NULL, type enum(shared/dedicated) NOT NULL default
  'shared', contention_ratio int nullable (wajib jika shared, mis. 8),
  is_active boolean default true, created_at, updated_at, deleted_at.
- Unique: `(company_id, name)`. Trait `BelongsToCompany`. Soft delete.
- Check: `download_mbps > 0`, `upload_mbps > 0`,
  `(type='dedicated' OR contention_ratio IS NOT NULL)`.

### speed_profiles
- id, company_id FK NOT NULL, name NOT NULL, download_max_mbps int
  NOT NULL, upload_max_mbps int NOT NULL, burst_download_mbps int
  nullable, burst_upload_mbps int nullable, radius_profile_name varchar
  nullable (v2 auto-provisioning hook), is_active boolean default true,
  created_at, updated_at, deleted_at.
- Unique: `(company_id, name)`. Trait `BelongsToCompany`. Soft delete.

### sla_tiers
- id, company_id FK NOT NULL, name NOT NULL, response_time_hours int NOT NULL,
  resolution_time_hours int NOT NULL, is_active boolean default true,
  created_at, updated_at, deleted_at.
- Unique: `(company_id, name)`. Trait `BelongsToCompany`. Soft delete.
- Check: `response_time_hours > 0`, `resolution_time_hours > 0`.
- Target kontrak, diisi manual. Hasilnya tidak diinput: indeks tier menghitung
  "respons / penyelesaian tepat waktu (30 hari)" dari tiket langganan pada
  paket tier itu. `uptime_pct` dan `credit_pct` dihapus (tidak pernah dibaca).

### service_packages
- id, company_id FK NOT NULL, code NOT NULL, name NOT NULL,
  bandwidth_profile_id FK NOT NULL (restrict), speed_profile_id FK NOT NULL
  (restrict), sla_tier_id FK NOT NULL (restrict), price_mrc decimal(15,2)
  NOT NULL, price_otc decimal(15,2) NOT NULL default 0, contract_min_months
  int default 0 (0 = no contract), description text nullable, is_active
  boolean default true, created_at, updated_at, deleted_at.
- Unique: `(company_id, code)`. Index: `(company_id, name)`,
  `(company_id, is_active)`. Trait `BelongsToCompany`. Soft delete.

## Workflow

Catalog = master data, lifecycle sederhana:

```
[create] → [active] → [deactivate] (is_active=false) → [deleted (soft)]
```

- `deactivate`: is_active=false. Existing subscription tetap jalan (snapshot
  mrc di subscription). Tidak bisa pilih untuk subscription baru.
- `delete` (soft): restrict jika ada subscription active/pending
  referencing. Allow jika semua subscription terminated.

### Pricing snapshot rule
- Saat create ServiceSubscription: `subscription.mrc_amount = package.price_mrc`
  (snapshot, bukan FK live). Alasan: harga package bisa berubah, subscription
  lama harga tetap. Override mrc_amount manual di subscription allowed
  (negotiated price).
- Saat package price update: TIDAK auto-update existing subscription.
  Bulk update via Reporting/Action terpisah (v2: `RepriceSubscriptionsAction`).

### Radius profile (speed group)
- `speed_profiles.radius_profile_name` names the RADIUS speed group of the package. Inbils writes one group
  per company and profile, `<realm>/<radius_profile_name>` (realm = lower-cased company code), with
  `Mikrotik-Group := <radius_profile_name>`, `Mikrotik-Rate-Limit := <upload_max>M/<download_max>M` and
  `Acct-Interim-Interval := 300`, and rewrites it when the speed profile changes (new logins get the new rate; online
  sessions keep theirs until they reconnect or staff press Resync). Burst values are not sent. The NetworkAsset
  module observes the shared `ServiceSubscription` model (`ServiceSubscriptionNetworkObserver`, after commit) and
  queues `ApplyNetworkAccountJob` when a subscription's status, package or `blocked_at` changes or it is deleted and a
  network account exists. Core never calls the module. A package whose speed profile has no
  `radius_profile_name` cannot get a network account. Details: `docs/business/network.md` ("NAS router dan
  network account").

### Katalog awal tenant baru (flow-audit-fixes gelombang 2)
- Platform memilih `catalog` saat membuat perusahaan (`StoreTenantRequest`, wajib `starter` atau `empty`; `demo_data` dengan `empty` ditolak). `CompanyCreated($company, $catalog)` adalah satu-satunya jalur seeding: `SetupWizardService::createFor` tidak lagi memanggil `CompanySeeder::runFor` sendiri, jadi perusahaan tidak lagi diisi dua kali.
- `CompanySeeder::runFor($company, $catalog)`: `empty` mengisi satuan, kategori paket dan kategori tiket (tanpa kategori tiket pelanggan tidak bisa membuka tiket). `starter` menambah tingkat SLA, Region/Area/POP bawaan, profil bandwidth dan kecepatan, paket, kategori barang dan barang, tanpa baris stok dan tanpa aset jaringan. `demo` menambah baris stok dan 10 aset contoh, yang kodenya dibuat `NetworkAssetService::generateCode()` (awalan Penomoran), bukan `AST-` tetap. Anak perusahaan dan setup wizard memakai `starter`; `DemoUserSeeder` memakai `demo`; `SeedTenantDemoDataJob` menjalankan `demo` lebih dulu, lalu `DemoDataSeeder`. Setiap langkah idempoten.

## Permission

```
service.view
service.create
service.update
service.delete
service.manage    (super — full catalog)
```

## API (Route)

Lihat `docs/API.md` Section 3 (Service group). Ringkas:

- `GET    /admin/service-packages` (list, filter: is_active, sla_tier, search)
- `POST   /admin/service-packages` (create)
- `GET    /admin/service-packages/{pkg}` (detail + bandwidth + speed + sla)
- `PUT    /admin/service-packages/{pkg}` (update)
- `DELETE /admin/service-packages/{pkg}` (soft delete — restrict if active subscription)
- `POST   /admin/service-packages/{pkg}/deactivate`
- `GET    /admin/bandwidth-profiles` (+ POST/PUT/DELETE resource)
- `GET    /admin/speed-profiles` (+ POST/PUT/DELETE resource)
- `GET    /admin/sla-tiers` (+ POST/PUT/DELETE resource)

## Testing Scenario

### Create catalog + package
1. Create BandwidthProfile: 50M/10M shared, contention 8.
2. Create SpeedProfile: up to 50Mbps, radius_profile="home-50m".
3. Create SLATier: Silver 99.5%, response 8h, resolution 24h, credit 10%.
4. Create ServicePackage: code PKG-HOME-50M, name "Home 50Mbps",
   bandwidth+speed+sla link, price_mrc=250000, price_otc=500000,
   contract_min_months=0.

### Validation
1. Create BandwidthProfile shared tanpa contention_ratio → 422.
2. Delete ServicePackage dengan active subscription → 422.
3. Deactivate package → is_active=false. Existing subscription tetap
   jalan, mrc snapshot tetap 250000.

### Pricing snapshot
1. Package price_mrc=250000. Create subscription → subscription.mrc_amount=250000.
2. Update package price_mrc=275000. Subscription mrc_amount tetap 250000.

### Authorization
1. Admin create package → 200. Manager create → 403.
2. Staff view → 200. Staff create → 403.

## Acceptance Criteria

- [ ] CRUD catalog (Bandwidth/Speed/SLA + Package) sesuai.
- [ ] Pricing snapshot di subscription (package price change tidak
  affect existing subscription).
- [ ] Deactivate package → existing subscription tetap jalan.
- [ ] Soft delete restrict jika active/pending subscription.
- [ ] Validation: shared bandwidth wajib contention_ratio.
- [ ] Number/code generation unique per company.
- [ ] Policy per aksi.
- [ ] Activity log: create, update, deactivate, delete.
- [ ] Factory + seeder (5 bandwidth, 5 speed, 4 sla tier, 8 package).
- [ ] Feature test ≥ 80% coverage.
- [ ] UI: package list (filter is_active/sla), detail (bandwidth+speed+sla
  card), form. Catalog management (bandwidth/speed/sla) separate pages.
- [ ] UI pakai Components/ui + composite.
- [ ] Dark mode + responsive.

## Module Dependencies

- **Depends on (Phase 1):** Core (Company, User, Role, Permission,
  ActivityLog, Setting).
- **Consumed by (Phase 2):** Customer (ServiceSubscription FK
  service_package_id — direct FK, catalog like Product), Billing (pricing
  snapshot dari package.price_mrc/otc saat create subscription), SPK
  (package reference for installation), Ticketing (sla_tier default SLA
  per category, optional), Reporting (service distribution metrics).
- **FK cross-module:** service_subscriptions.service_package_id →
  service_packages.id (direct FK, restrict delete). Allowed karena
  ServicePackage = catalog master (mirrors products di Inventory consumed
  by work_orders/invoices).
