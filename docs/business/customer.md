# Module: Customer Management

> Status: DRAFT v2 (ISP pivot 2026-06-30). Phase 2 eksekusi. Dependency:
> Phase 1 (Core: Company + CompanyScope + Setting + Location topology).
> Lokasi module: `Modules/Customer/` (nwidart). Models di `app/Models/Core/`
> shared (keputusan A1 — Customer consumed oleh 5 module: SPK/Ticketing/
> Billing/NetworkAsset/Reporting).

## Tujuan

Mengelola master data pelanggan ISP: Individual/Company, alamat layanan
(installation point), kontak, dan subscription (paket internet aktif per
customer). Customer + Subscription = entitas sentral ISP — direferensikan
oleh SPK (instalasi/maintenance), Ticketing (complaint), Billing
(recurring invoice + suspend), NetworkAsset (ONT terpasang), Reporting.

## User Role

| Role | Hak |
|------|-----|
| admin | full CRUD customer/address/contact/subscription + suspend/reactivate/terminate |
| manager | full CRUD + suspend/reactivate/terminate (no delete) |
| noc | view customer + subscription + address (no edit) |
| staff | view + create/edit customer/address/contact (no subscription lifecycle) |
| technician | view customer + subscription + address (untuk SPK assignment) |

## Entity

### Customer (app/Models/Core/Customer.php — shared)
Pelanggan ISP. Individual (perorangan) atau Company (badan usaha).
Code unique per company. Type menentukan field wajib (Company wajib
tax_id/NPWP + contact_person).

### CustomerAddress (app/Models/Core/CustomerAddress.php — shared)
Alamat pelanggan. Bisa multiple per customer (rumah/kantor/cabang).
Salah satu = installation point (lokasi delivery layanan, dipakai SPK
instalasi + ticketing trace). Lat/lng disimpan v1 (GIS map v2).

### CustomerContact (app/Models/Core/CustomerContact.php — shared)
Kontak person pelanggan. Multiple per customer. Untuk Company = PIC
teknis/administratif. Untuk Individual = opsional ( kontak = data
customer sendiri).

### ServiceSubscription (app/Models/Core/ServiceSubscription.php — shared)
Subscription layanan ISP aktif per customer. Link ke ServicePackage
(catalog, Modules/Service) + installation address + ONT asset (installed
at premise) + serving POP (topology location). Status lifecycle.
Billing fields (E1 — service_subscription IS the billing subscription,
no separate entity).

## Database Relation

```
companies ──< customers
customers ──< customer_addresses
customers ──< customer_contacts
customers ──< service_subscriptions
service_packages ──< service_subscriptions (FK service_package_id)
customer_addresses ──< service_subscriptions (FK installation_address_id)
locations ──< service_subscriptions (FK serving_pop_id, type=pop)
network_assets ──< service_subscriptions (FK ont_asset_id, nullable — ONT at premise)
users ──< customers (created_by)
work_orders ──< service_subscriptions (spk references subscription)
tickets ──< service_subscriptions (ticket references subscription)
invoices ──< service_subscriptions (recurring invoice source)
```

Skema: `docs/DATABASE.md` Section 5 (Customer).

## Entity Fields (ringkas)

### customers
- id, company_id FK NOT NULL, code NOT NULL, name NOT NULL, type
  enum(Individual/Company) NOT NULL, email nullable, phone nullable,
  tax_id nullable (NPWP — wajib jika Company), contact_person nullable,
  area_coverage_id nullable FK→locations.id (region/area service coverage),
  notes text nullable, is_active boolean default true, created_at,
  updated_at, deleted_at.
- Unique: `(company_id, code)`. Index: `(company_id, name)`,
  `(company_id, email)`. Trait `BelongsToCompany`. Soft delete.

### customer_addresses
- id, company_id FK NOT NULL, customer_id FK NOT NULL (cascade), label
  varchar (rumah/kantor/cabang), address text NOT NULL, city, postal_code,
  lat decimal(10,7) nullable, lng decimal(10,7) nullable,
  is_installation_point boolean default false, is_primary boolean default
  false, notes nullable, created_at, updated_at, deleted_at.
- Unique: `(customer_id, label)`. Index: `(company_id, customer_id)`.
  Trait `BelongsToCompany`. Soft delete.
- Rule: max 1 `is_installation_point=true` per customer (app guard at
  Service, set new true → clear others).

### customer_contacts
- id, company_id FK NOT NULL, customer_id FK NOT NULL (cascade), name
  NOT NULL, position nullable, phone, email, is_primary boolean default
  false, notes nullable, created_at, updated_at, deleted_at.
- Unique: `(customer_id, phone)`. Index: `(company_id, customer_id)`.
  Trait `BelongsToCompany`. Soft delete.

### service_subscriptions
- id, company_id FK NOT NULL, customer_id FK NOT NULL (restrict),
  service_package_id FK NOT NULL (restrict), installation_address_id FK
  NOT NULL (restrict), code NOT NULL, status enum
  (pending/active/suspended/terminated) NOT NULL default 'pending',
  activation_date date nullable, expiration_date date nullable,
  billing_day tinyint NOT NULL default 1 (1-28 — tanggal recurring
  invoice), next_invoice_date date nullable, ont_asset_id nullable
  FK→network_assets.id (restrict — ONT installed at premise),
  serving_pop_id nullable FK→locations.id (restrict — POP topology yang
  melayani), mrc_amount decimal(15,2) NOT NULL (snapshot dari package
  saat create, bisa override), otc_installation_fee decimal(15,2) default
  0, contract_months int nullable, notes nullable, terminated_at nullable,
  terminated_reason nullable, created_at, updated_at, deleted_at.
- Unique: `(company_id, code)`. Index: `(company_id, customer_id)`,
  `(company_id, status)`, `(company_id, serving_pop_id)`,
  `(company_id, ont_asset_id)`. Trait `BelongsToCompany`. Soft delete.
- Check constraint: `status IN ('pending','active','suspended','terminated')`,
  `billing_day BETWEEN 1 AND 28`.

## Workflow

Lihat `docs/WORKFLOW.md` Section 2 (Customer/Subscription). Ringkas:

```
[customer created] → active
  │
  └─< subscription >── [pending] ──SPK install complete──→ [active]
                                │
                                ├── billing overdue job──→ [suspended]
                                │                          │
                                │                          └──payment recorded / manual──→ [active]
                                │
                                └── manual terminate──→ [terminated]
```

### Subscription lifecycle (SubscriptionService — app/Services/Core/)

| Dari | Ke | Trigger | Side effect |
|------|-----|---------|-------------|
| pending | active | activate(subscription) | `activation_date=now`, link ont_asset (if SPK installed), `next_invoice_date = next billing_day`, AuditLog. Dipanggil CompleteSpkAction (instalation) atau manual. |
| active | suspended | suspend(subscription, reason) | `status=suspended`, stop recurring invoice generation (job skip), flag NOC/ticketing, AuditLog. Trigger: billing overdue job (auto) OR manual (admin/manager). |
| suspended | active | reactivate(subscription) | `status=active`, `next_invoice_date = next billing_day`, resume recurring, AuditLog. Trigger: payment recorded (auto if Setting `billing.auto_reactivate_on_payment`) OR manual. |
| active/suspended | terminated | terminate(subscription, reason, release_ont) | `status=terminated`, `terminated_at=now`, `terminated_reason`, stop recurring, release ont_asset (if release_ont → NetworkAsset status=Available + NetworkAssetInstallation removed_at), AuditLog. |

### Code generation
- `CUS-{YEAR}-{NNNNN}` untuk customer. `SUB-{YEAR}-{NNNNN}` untuk
  subscription. Lock + transaction per tahun.

### Area coverage
- `customers.area_coverage_id` FK→locations (region/area). Untuk filter
  pelanggan per wilayah layanan (reporting + routing tiket ke unit
  terkait). Opsional v1.

## Permission

```
customer.view
customer.create
customer.update
customer.delete
customer.address.manage      (address + contact CRUD)
customer.subscription.view
customer.subscription.manage (create/edit subscription)
customer.subscription.activate
customer.subscription.suspend
customer.subscription.reactivate
customer.subscription.terminate
customer.export
customer.manage              (super)
```

## API (Route)

Lihat `docs/API.md` Section 2 (Customer group). Ringkas:

- `GET    /admin/customers` (list, filter: type, area, is_active, search name/code/phone)
- `POST   /admin/customers` (create)
- `GET    /admin/customers/{customer}` (detail + addresses + contacts + subscriptions)
- `PUT    /admin/customers/{customer}` (update)
- `DELETE /admin/customers/{customer}` (soft delete — restrict if active subscription)
- `GET    /admin/customers/{customer}/addresses`
- `POST   /admin/customers/{customer}/addresses`
- `PUT    /admin/customers/{customer}/addresses/{address}`
- `DELETE /admin/customers/{customer}/addresses/{address}`
- `GET    /admin/customers/{customer}/contacts`
- `POST   /admin/customers/{customer}/contacts`
- `PUT    /admin/customers/{customer}/contacts/{contact}`
- `DELETE /admin/customers/{customer}/contacts/{contact}`
- `GET    /admin/customers/{customer}/subscriptions`
- `POST   /admin/customers/{customer}/subscriptions` (create pending)
- `GET    /admin/subscriptions/{sub}` (detail + package + ont + pop + invoices + spks + tickets)
- `PUT    /admin/subscriptions/{sub}` (update mrc/contract/notes — no status change via PUT)
- `POST   /admin/subscriptions/{sub}/activate`
- `POST   /admin/subscriptions/{sub}/suspend` (body: reason)
- `POST   /admin/subscriptions/{sub}/reactivate`
- `POST   /admin/subscriptions/{sub}/terminate` (body: reason, release_ont bool)
- `GET    /admin/customers/export`

## Testing Scenario

### Create customer + address + subscription
1. Create customer Company: code CUS-2026-00001, name PT Contoh, tax_id
   01.234.567.8-90.000, area_coverage=region Jakarta.
2. Add address: label "Kantor Pusat", is_installation_point=true,
   is_primary=true, lat/lng set.
3. Add contact: name Budi, position IT Manager, phone 0812, is_primary.
4. Create subscription: customer, package "Home 50Mbps", installation
   address, billing_day=5, mrc=snapshot package price 250000,
   otc_installation_fee=500000, contract_months=12. Status=pending.

### Subscription lifecycle
1. Subscription pending → activate: status=active, activation_date=today,
   next_invoice_date=5 bulan ini (atau bulan depan jika sudah lewat).
2. Suspend (reason "non-pay invoice INV-X"): status=suspended.
3. Reactivate: status=active, next_invoice_date recalculated.
4. Terminate (reason "cancel", release_ont=true): status=terminated,
   ont_asset.status=Available, NetworkAssetInstallation removed_at=now.

### Validation
1. Delete customer with active subscription → 422 "cannot delete customer
   with active subscription".
2. Set 2nd address is_installation_point=true → 1st cleared (app guard).
3. Suspend already-suspended → 422.
4. Terminate already-terminated → 422.

### Authorization
1. Staff create customer → 200. Staff suspend subscription → 403.
2. Technician view customer → 200. Technician edit customer → 403.
3. NOC view subscription → 200. NOC terminate → 403.

### Number race
1. Concurrent create 2 customer → 2 code beda, tidak duplikat.

## Acceptance Criteria

- [ ] Customer + Address + Contact + Subscription CRUD sesuai.
- [ ] Subscription lifecycle (pending→active→suspended→active→terminated)
  dengan side effect benar (ont release, recurring stop/resume).
- [ ] Max 1 installation_point per customer (app guard).
- [ ] Suspend/reactivate trigger dari billing job (auto) + manual.
- [ ] Soft delete customer restrict jika active subscription.
- [ ] Number generation unique + race-safe (CUS + SUB).
- [ ] Policy per aksi.
- [ ] Activity log: create, address add, subscription activate/suspend/
  reactivate/terminate, customer delete.
- [ ] Factory + seeder (20 customer: 12 Individual + 8 Company, 30
  subscription: 20 active, 5 suspended, 3 pending, 2 terminated).
- [ ] Feature test ≥ 80% coverage.
- [ ] UI: customer list (filter type/area/search), detail (tab: profile
  + addresses + contacts + subscriptions + tickets + invoices + spks),
  form. Subscription detail (package + ont + pop + lifecycle buttons).
- [ ] UI pakai Components/ui + composite.
- [ ] Dark mode + responsive.

## Module Dependencies

- **Depends on (Phase 1):** Core (Company, User, Role, Permission,
  ActivityLog, Setting), Location (topology — area_coverage + serving_pop).
- **Depends on (Phase 2):** Service (ServicePackage — FK untuk
  subscription). ServicePackage di Modules/Service, FK direct (catalog,
  seperti Product di Inventory).
- **Consumed by (Phase 3+):** NetworkAsset (ont_asset_id link),
  SPK (subscription reference), Ticketing (subscription link), Billing
  (recurring invoice source), Reporting (read).
- **Shared Core (A1):** Customer/CustomerAddress/CustomerContact/
  ServiceSubscription di app/Models/Core/ — direct import oleh 5 consumer
  module. SubscriptionService di app/Services/Core/ — direct call.
