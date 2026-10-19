# Module: Network Asset Management

> Status: DRAFT v2 (ISP pivot 2026-06-30). Phase 3 eksekusi. Dependency:
> Phase 1 (Core + Location topology) + Phase 2 (Customer/Subscription
> shared). Lokasi module: `Modules/NetworkAsset/` (nwidart). Models di
> `Modules/NetworkAsset/Models/` (keputusan B1 — NetworkAsset module
> TERPISAH dari Inventory: durable tracked equipment vs consumable stock).

## Tujuan

Mengelola aset jaringan ISP (Router, Switch, OLT, ONU/ONT, Radio,
Antenna, Fiber, ODP, ODC, Rack, Power equipment) sebagai individual
tracked equipment: serial number + MAC address + IP address (salinan tampilan: konflik antar pemegang alamat dicek di register IPAM, lihat bagian IPAM; `ip_address` dan `management_ip` TIDAK unik di tabel asset),
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
network_assets ──< asset_ports (FK network_asset_id, restrict: satu baris per port non-ODN)
asset_ports ──< asset_ports (FK linked_port_id nullable, unik: sambungan tersimpan di kedua sisi)
network_assets ──< network_assets (FK rack_asset_id nullable, restrict: perangkat terpasang di aset bertipe rack)
port_templates ──< network_assets (FK template_key nullable: null saat baris katalog dihapus)
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
- Kolom panel (migrasi `2026_10_24_110100`): `template_key` nullable FK→port_templates.key (set null), `port_layout` json
  nullable (snapshot tata letak), `rack_asset_id` nullable FK→network_assets.id (restrict), `rack_unit` tinyint unsigned
  nullable, `height_u` tinyint unsigned nullable (tinggi perangkat; untuk rak, ukurannya). Unik `(rack_asset_id, rack_unit)`.
  Bukan `$fillable`. Lihat bagian Panel perangkat.

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
ipam.view                      (lihat prefix, VLAN, alamat)
ipam.manage                    (prefix, VLAN, reservasi, import IP asset)
ipam.assign                    (alamat static subscription)
odn.view                       (lihat ODN: ODP, port PON, subscription tanpa drop)
odn.manage                     (port PON, splitter)
odn.assign                     (port drop subscription)
```

Panel perangkat tidak menambah izin: `NetworkAssetPolicy::updatePanel` memakai `network_asset.update` (lihat bagian Panel perangkat).

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
- `GET    /admin/network-assets/trace?path_subscription={id}` (jalur fiber satu subscription; hanya pemegang `odn.view`)
- `PUT    /admin/network-assets/{asset}/panel` (body: template_key | layout, height_u? | rak: height_u)
- `PUT    /admin/network-assets/{asset}/rack-placement` (body: rack_asset_id, rack_unit, height_u | rack_asset_id: null)
- `PATCH  /admin/network-assets/{asset}/ports/{port}` (body: label, note, admin_down)
- `POST   /admin/network-assets/{asset}/ports/{port}/link` (body: target_port_id)
- `DELETE /admin/network-assets/{asset}/ports/{port}/link`
- `GET    /admin/network-assets/export`
- `GET    /admin/locations` (tree, filter: type, parent_id, search)
- `POST   /admin/locations` (create with parent)
- `GET    /admin/locations/{loc}` (detail + children + assets placed + stocks)
- `PUT    /admin/locations/{loc}` (update)
- `DELETE /admin/locations/{loc}` (soft delete — restrict if children/assets/stocks)
- `POST   /admin/locations/{loc}/move` (body: new_parent_id)

## NAS router dan network account (FreeRADIUS, lab pilot)

Keputusan owner 2026-10-15 (`.scratch/freeradius/spec.md`, "rekomendasi tiap keputusan"): Inbils **tidak lagi
memprovisioning router lewat RouterOS API**. Router MikroTik mengautentikasi subscriber ke FreeRADIUS 3.2; Inbils
mengelola isi database RADIUS (user, grup kecepatan, klien NAS) dan mengirim Disconnect-Request (RFC 5176) agar
perubahan grup berlaku seketika. Ini lab pilot: tanpa NAS bersama antar company, tanpa burst. IP static dari IPAM
ditulis sebagai `Framed-IP-Address` (lihat Model). Pendahulunya (`.scratch/mikrotik-radius`, RouterOS API) dihapus; riwayatnya ada di `CHANGELOG.md`.

### Model

- **Realm** = kode company huruf kecil. Username RADIUS selalu `local@realm` (FreeRADIUS mencari user hanya
  dari `User-Name`, jadi realm menjaga antar company tidak bertabrakan). Staf mengetik bagian lokal
  (`[a-z0-9._-]{3,40}`); Inbils menyimpan dan menampilkan username penuh, unik **lintas company**; pesan duplikat
  selalu "This username is not available." (tidak menyebut pemiliknya). Company yang kodenya tidak cocok
  `^[a-z0-9-]{1,24}$` belum bisa mendaftarkan NAS atau account (422 pada field `realm`) sampai kodenya diubah.
- **NAS router** (`nas_devices`): satu per aset `router`; `nasname` (IPv4 yang dilihat FreeRADIUS sebagai sumber
  paket, default `management_ip` aset, unik global), `coa_port` (default 1700, port `/radius incoming` MikroTik),
  `radius_secret` (shared secret per NAS, cast `encrypted`, tersembunyi, 8-60 karakter ASCII tercetak tanpa spasi
  karena `nas.secret` varchar(60); tidak pernah di resource, audit, log atau flash), bookkeeping `radius_nasname`,
  `radius_synced_at`, `last_error`. Setiap baris menjadi satu baris `nas` di database RADIUS
  (`shortname` = realm company). Alamat CoA = `management_ip` aset : `coa_port`. Hapus NAS diizinkan walau masih ada
  account atau sesi (router mengakhiri sesinya sendiri) dan menghapus baris `nas` yang pernah ditulis Inbils.
- **Network account** (`network_accounts`): user RADIUS satu subscription (`radcheck` `Cleartext-Password :=` dan
  `radusergroup`). Subscriber boleh login di NAS mana pun milik company-nya; tidak ada tautan akun ke satu NAS.
  Secret subscriber terenkripsi di Inbils (sumber kebenaran), cleartext hanya di database RADIUS (CHAP dan MS-CHAP
  butuh cleartext; database RADIUS adalah proyeksi yang bisa dibangun ulang oleh `network:sync`). Tampil sekali
  (flash `network_account.secret`) setelah dibuat atau lewat reveal beraudit; tanpa `LogsActivity`.
- **Grup kecepatan** `<realm>/<radius_profile_name>`: `Mikrotik-Group := <radius_profile_name>`,
  `Mikrotik-Rate-Limit := <upload>M/<download>M`, `Acct-Interim-Interval := 300`. Diubah saat speed profile berubah
  (login baru memakai kecepatan baru; sesi berjalan memakai yang lama sampai putus atau Resync). Paket tanpa
  `radius_profile_name` tidak bisa punya account. **Grup isolir** `<realm>/isolir`: `Mikrotik-Group := isolir`,
  `Mikrotik-Address-List := isolir`, `Acct-Interim-Interval := 300`.
- **IP static** (`Framed-IP-Address`): bila subscription punya alamat IPv4 dari IPAM (`IpAssignmentService::staticAddressFor`),
  `radreply` user itu berisi `Framed-IP-Address := <alamat>` di samping balasan grup; tanpa alamat (dynamic) tidak ada
  baris `radreply` dan NAS memberi alamat dari pool-nya. Alamat tetap dipakai di grup isolir. Alamat baru dipakai saat
  sesi dimulai, jadi alamat yang dipasang, diganti atau dilepas membuat Disconnect terutang (CPE redial dengan alamat
  baru); selisihnya dibaca langsung dari `radreply` (`RadiusDirectory::framedIpOf`), tanpa kolom di Inbils. Baris
  `Framed-IP-Address` yang hilang atau tak dikenal di RADIUS dianggap drift. Assign atau release di IPAM mengantre
  `ApplyNetworkAccountJob` lewat listener `IpAssignmentChanged` (setelah commit). IPv6 tidak ditulis.
- **Desired state** (dihitung saat job jalan): `pending|active` tidak diblokir → user ada, grup kecepatan;
  `suspended` atau status apa pun selain terminated dengan `blocked_at` → user ada, grup isolir; `terminated` atau
  dihapus → user dihapus. Suspend dan blokir adalah dua hal berbeda; halaman account menampilkan
  `isolatedBecause` (`suspended`, `blocked` atau keduanya).
- **Disconnect**: dikirim ke NAS tempat sesi terbuka (`radacct.nasipaddress` dicocokkan dengan `nasname` NAS company
  itu) bila grup atau alamat static berubah, user dihapus atau di-rename, atau staf menekan Resync; perubahan secret saja tidak memutus
  sesi. ACK atau NAK `Session-Context-Not-Found` (503) dianggap selesai; timeout atau NAK lain → retry (`pending`);
  sesi di alamat yang bukan NAS company → dicatat di `last_error`, tidak dikirim, tidak diulang.
- **Pemicu**: observer `ServiceSubscription` (`status`, `service_package_id`, `blocked_at`, delete; setelah commit),
  simpan atau Resync account, perubahan `SpeedProfile`, assign atau release alamat IPAM, dan `network:sync {--company=}` tiap 15 menit (account
  `pending`, utang Disconnect, `failed` lebih dari sejam, drift via `hasUser` dan `framedIpOf`, dan baris NAS yang belum tertulis).
  Job `ApplyNetworkAccountJob`: unik per account sampai diproses, `tries` 5, backoff 30/120/300/900 detik, `timeout`
  60. Galat database RADIUS dan timeout Disconnect → retry dan account `pending`; input yang tidak bisa disimpan
  (tanpa nama profil, realm tak valid) → `failed` seketika. Aksi bisnis tidak pernah menunggu RADIUS.
- **Simpan NAS**: baris Inbils disimpan lebih dulu, lalu (setelah transaksi) `putNas`. Kegagalan RADIUS tidak
  pernah menjadi 500: `radius_synced_at` kosong, `last_error` terisi, flash `warning`, sync mengulang.
  Alamat berubah → baris `nas` lama dihapus. Baris `nas` yang tidak ditulis Inbils (mis. `127.0.0.1` lab) tidak pernah
  disentuh. **Setelah mendaftarkan NAS atau mengganti secret-nya, NOC me-restart FreeRADIUS** (klien dibaca dari `nas`
  hanya saat start; `kill -HUP` tidak cukup); Inbils tidak mengendalikan proses FreeRADIUS.
- **Online dan pemakaian**: halaman account dan halaman NAS membaca `radacct` langsung (sesi terbuka =
  `acctstoptime IS NULL` dan update atau mulai dalam 15 menit; octet bulan kalender berjalan). Tidak ada salinan di
  Inbils. Saat integrasi RADIUS company mati atau RADIUS tak terjangkau, `session`/`usage` null dan `sessions` kosong; halaman tetap tampil.
- **Saklar**: baris integrasi `radius` company (`company_integrations`, `is_enabled`, diisi di Pengaturan → Integrasi dengan izin
  `company.integrations.manage`) mematikan semua baca/tulis database RADIUS dan semua Disconnect untuk company itu. Company tanpa baris aktif
  tidak membaca atau menulis RADIUS. Account tetap `pending` dengan alasannya ("RADIUS belum dikonfigurasi untuk perusahaan ini
  (Pengaturan → Integrasi)."). `RADIUS_ENABLED` dan `RADIUS_DB_*` di `.env` hanya sumber impor (`php artisan inbils:integrations:import-env
  --company=<kode>`), bukan saklar runtime. `services.radius.coa_timeout` berlaku untuk semua company.
- **Rahasia**: `radius_secret` tidak pernah di-flash ke session (`dontFlash`); secret satu kali disimpan tersegel
  (`Crypt`) di session dan dibuka hanya saat halaman dibangun, termasuk saat respons Inertia 409 (versi aset basi)
  me-reflash session. Rotasi `APP_KEY`: simpan kunci lama di `APP_PREVIOUS_KEYS`, kalau tidak semua secret tak terbaca.
- **Audit** (tanpa secret): `nas_device` `created|updated|deleted|secret_changed|radius_written|radius_failed`;
  `network_account` `created|updated|secret_changed|secret_revealed|provisioned|provision_failed|disconnected|drift_corrected`.
- **Permission**: `network_asset.nas.view|manage`, `network_asset.account.view|manage|reveal`; blokir pelanggan
  memakai `customer.subscription.block` (lihat `docs/business/customer.md`). Mengubah `management_ip` aset tidak
  lagi urusan kredensial: secret RADIUS tidak pernah dikirim ke alamat aset.
- **Halaman isolir publik**: `GET /isolir/{kode company}`: nama, telepon, email, website company dan link login;
  tidak ada data pelanggan. Kode tak dikenal atau company nonaktif → 404. Throttle `isolir` (limiter bernama):
  600/menit per alamat + kode, 20/menit per alamat untuk tebakan kode (hanya permintaan 404 yang menghabiskan jatah ketat itu).

### Runbook FreeRADIUS dan MikroTik

1. **Database RADIUS terpisah per ISP** (host, port, nama database, pengguna dan kata sandi diisi admin company di Pengaturan → Integrasi,
   kartu RADIUS; kata sandi terenkripsi, koneksi `radius_{company id}` dibangun dari baris itu dan ditutup setelah dipakai; "Uji koneksi"
   menjalankan `SELECT 1` dan memeriksa enam tabel; host loopback, link-local dan metadata ditolak, jaringan RFC 1918 boleh): skema bawaan FreeRADIUS 3.2 (`schema.sql` dari FreeRADIUS) di database sendiri; Inbils tidak pernah
   memigrasikannya. User database Inbils: `SELECT, INSERT, UPDATE, DELETE` pada `radcheck`, `radreply`,
   `radusergroup`, `radgroupreply`, `nas` dan `SELECT` pada `radacct` saja. FreeRADIUS memakai user sendiri dan
   tidak pernah melihat tabel Inbils. Database ini berisi password cleartext: batasi jaringan, tanpa backup tak terenkripsi.
2. **FreeRADIUS** (`mods-enabled/sql`): `read_clients = yes`, `client_table = "nas"`. Klien dibaca dari `nas` hanya saat
   proses start: setelah NAS didaftarkan atau secret-nya diganti, **restart** FreeRADIUS. Terbukti di lab (3.2.10): `kill -HUP`
   tidak memuat baris `nas` baru.
3. **Kebijakan realm** (Q4: satu company tidak boleh mengautentikasi subscriber company lain). Simpan sebagai
   `policy.d/inbils_realm` dan panggil di `authorize` pada `sites-enabled/default`. Cuplikan ini **dibuktikan di lab**
   (tiket 06, FreeRADIUS 3.2.10): `zed@acme` lewat klien dengan `shortname=demo` → Access-Reject; setelah `shortname=acme`
   → Access-Accept, dan `e2e-lab@demo` ditolak.

   ```
   # policy.d/inbils_realm
   # Tolak bila realm di User-Name berbeda dari nas.shortname (realm company) milik klien pengirim.
   inbils_realm {
       if (&User-Name =~ / /) {
           reject
       }
       if (&User-Name =~ /^[^@\s]+@([a-z0-9-]+)$/i) {
           if ("%{tolower:%{1}}" != "%{tolower:%{client:shortname}}") {
               update reply {
                   &Reply-Message := "Realm is not served by this NAS"
               }
               reject
           }
       }
       else {
           # Semua user Inbils berbentuk local@realm.
           reject
       }
   }
   ```

   Di `sites-enabled/default`, bagian `authorize { ... }`, **ganti** `filter_username` bawaan dengan `inbils_realm`: filter
   bawaan menolak `user@demo` ("Realm does not have at least one dot separator") karena realm Inbils tidak memakai titik.

   ```
   authorize {
       inbils_realm
       preprocess
       sql
       ...
   }
   ```

   `%{client:shortname}` terbaca di 3.2.10. `shortname` adalah realm company (varchar 32; realm paling panjang 24 karakter).
4. **MikroTik** (per router, secret = `radius_secret` NAS itu):
   - `/radius add service=ppp,hotspot address=<IP FreeRADIUS> secret=<secret per NAS>`
   - `/ppp aaa set use-radius=yes accounting=yes interim-update=5m`
   - `/radius incoming set accept=yes port=1700` (sama dengan `coa_port` di Inbils; Inbils mengirim Disconnect ke
     `management_ip`:`coa_port`)
   - profil PPP `isolir` dengan address list `isolir` dan redirect HTTP ke `/isolir/{kode company}`; satu profil PPP
     per `radius_profile_name` hanya bila rate tidak dikirim lewat `Mikrotik-Rate-Limit` (RADIUS menimpa rate profil PPP).
   - walled garden untuk host Inbils dan host Midtrans (halaman isolir dan pembayaran).
5. **Onboarding**: isi kartu RADIUS di Pengaturan → Integrasi dengan **Aktif** mati dan klik "Uji koneksi" (baris yang masih mati boleh diuji)
   → daftarkan NAS (flash peringatan sampai tertulis) → restart FreeRADIUS → buat account → nyalakan **Aktif** → `php artisan network:sync`
   → terakhir `auto_suspend_enabled` per company. Impor dari `.env` (`RADIUS_ENABLED=true` dan `RADIUS_DB_*`, lalu
   `php artisan inbils:integrations:import-env --company=<kode> --provider=radius`) menulis baris dengan **Aktif** sudah menyala, jadi
   daftarkan NAS dan buat account sebelum mengimpor bila urutan di atas harus dijaga. Worker queue dan scheduler harus jalan (tanpa keduanya account tetap `pending`).
6. **Rollback**: matikan **Aktif** pada kartu RADIUS company (RADIUS menyimpan baris terakhir, sesi berjalan tetap hidup, tanpa
   Disconnect) → bila perlu router kembali ke secret lokal secara manual → migrasi down (kolom yang diubah kembali
   kosong; kolom blokir hilang bersama datanya).

## IPAM dan ODN (register pasif)

Keputusan owner 2026-10-12 (`.scratch/ipam-odn/spec.md`, "setuju rekomendasi"): dua **register pasif**. Staff
mencatat prefix, alamat, VLAN, port dan splitter; sistem tidak polling perangkat dan tidak push konfigurasi.
Jalur fiber/IP untuk otomasi RADIUS dibaca dari sini lewat satu service, bukan dari asset.

### IPAM (`Modules/Ipam`, tanpa route API)

- **Prefix** (`ip_prefixes`): IPv4/IPv6 berbentuk CIDR, dinormalisasi `mlocati/ip-lib` (`2001:DB8:0::/48` →
  `2001:db8::/48`; host bits dibuang). Parent = prefix terkecil yang memuatnya; prefix baru yang memuat prefix atau
  alamat lain mengadopsinya. Overlap sebagian dan duplikat ditolak 422 (blok CIDR hanya bersarang atau terpisah, jadi
  overlap sebagian hanya mungkin pada baris non-CIDR yang rusak). CIDR tidak bisa diubah; hapus ditolak 422 selama ada
  anak atau alamat. `usage`: `container`, `customer_static`, `customer_dynamic`, `infrastructure`; opsional terikat
  POP (`location_id`) dan VLAN. Unik `(company_id, cidr)`; perubahan prefix satu company berjalan satu per satu
  (`Cache::lock`, 10 detik).
- **VLAN** (`vlans`): `vid` 1-4094, per POP atau company-wide. Duplikat di satu POP atau company-wide ditolak 422;
  VID yang sama di POP lain boleh. Hapus ditolak 422 selama dipakai prefix.
- **Alamat** (`ip_addresses`): satu baris per alamat (`address` + `address_cmp` fixed-width ascii_bin). Unik
  `(company_id, address_cmp)` dan `(subscription_id, family)` (satu IPv4 dan satu IPv6 per subscription). Pemegang:
  subscription, network asset, atau reservasi (tanpa pemegang; gateway, loopback). Baris yang dilepas dihapus
  (riwayat di activity log). CHECK DB: satu pemegang per baris, `reserved` tanpa pemegang.
- **Dynamic default**: subscription tanpa baris = dynamic (router/RADIUS yang membagi dari pool; pool dynamic hanya
  dicatat ukurannya). **Static** = satu baris dari pool `customer_static`: alamat bebas terendah (hanya IPv4, pool
  /16 atau lebih kecil; network dan broadcast dilewati kecuali /31 dan /32; ruang prefix bersarang dilewati) atau
  alamat yang diketik (IPv6 hanya diketik). Duplikat → 422 menyebut pemegang; unique index adalah backstop, alokasi
  otomatis mencoba alamat berikutnya sampai 3 kali bila kalah balapan. Subscription `terminated` ditolak.
- **Asset write-through** (Q6a): `ip_address` dan `management_ip` asset tetap tersimpan apa adanya (salinan
  tampilan, kolom tetap dipakai index/trace/export). Buat/ubah/hapus asset menulis ke register di transaksi yang
  sama; alamat yang dipegang pihak lain → 422 pada field itu dan tidak ada yang tersimpan; alamat reservasi tidak
  bisa diambil asset (hapus reservasinya dulu). Nilai yang ditulis lewat jalur lain (seeder, factory, observer)
  tidak lewat register. `php artisan ipam:import-asset-ips {--company=} {--dry-run}` mendaftarkan nilai lama
  (idempoten, tidak pernah mengubah asset, melaporkan nilai tak terbaca dan duplikat); jalankan `--dry-run` per
  company sebelum run sungguhan.
- **Utilisasi** per prefix: `size`, `usable` (IPv4 dikurangi network/broadcast kecuali /31,/32), `assigned`,
  `reserved`, `free`, `percent = (assigned + reserved) / usable` (pool IPv4); container = persen ruang yang ditutup
  prefix anak; `customer_dynamic` hanya size; IPv6 tanpa usable/free/percent.
- **Kontrak provisioning** (dibaca lane MikroTik/RADIUS): `Modules\Ipam\Services\IpAssignmentService::staticAddressFor(ServiceSubscription): ?string`
  (IPv4 bertitik atau `null` bila dynamic; tidak butuh konteks company) dan event
  `Modules\Ipam\Events\IpAssignmentChanged(int $companyId, int $subscriptionId)` (`ShouldDispatchAfterCommit`) pada
  tiap assign/release alamat milik subscription. Listener `Modules\NetworkAsset\Listeners\QueueAccountOnIpAssignmentChange`
  mengantre apply account jaringan subscription itu (lihat IP static di bagian NAS router).
- **Terminate** (Q10a): `SubscriptionService::terminate` selalu melepas alamat static dan port drop subscription di
  transaksi yang sama (diaudit); suspend dan reactivate tidak mengubahnya.
- **Route** (`auth, verified, require.has.company`): `GET/POST /admin/ipam/prefixes`, `GET/PUT/DELETE
  /admin/ipam/prefixes/{prefix}`, `POST /admin/ipam/prefixes/{prefix}/reservations`, `POST
  /admin/ipam/subscriptions/{subscription}/addresses` (`ip_prefix_id`, `address?`), `DELETE /admin/ipam/addresses/{address}`,
  `GET/POST /admin/ipam/vlans`, `PUT/DELETE /admin/ipam/vlans/{vlan}`. Lintas company = 404, tanpa izin = 403.
- **Permission**: `ipam.view`, `ipam.manage`, `ipam.assign`. Admin dan manager ketiganya; staff dan teknisi
  `ipam.view`; customer tidak ada. Staff tidak bisa memberi alamat static (NOC/manager saja).

### ODN (`Modules/NetworkAsset`)

Lokasi tetap pohon `region > area > pop > rack > site`; ODC dan ODP adalah **asset** bertipe `odc`/`odp` di lokasi
`rack`/`site` (posisi ODP = lokasi tempat asset dipasang).

- **Splitter** (`odn_splitters`): di asset `odc` atau `odp`, rasio 1:2/4/8/16/32/64, `input_port_id` (unik) = port
  hulu (port PON OLT atau output splitter lain). Membuat splitter membuat N port output.
- **Port** (`odn_ports`): `pon` (milik OLT, nomor lanjut dari yang tertinggi, unik per OLT lewat kolom generated
  `pon_number`) atau `splitter_out` (nomor unik per splitter). `subscription_id` unik: satu drop per subscription.
  Port **terpakai** bila dipegang subscription atau menjadi input splitter. Kapasitas ODP = jumlah output
  splitter-nya; used = yang terpakai.
- **Aturan** (422 dengan pesan di field): port PON hanya di `olt`; splitter hanya di `odc|odp`; input splitter harus
  bebas, satu company, bukan output/hilirnya sendiri (jalan ke atas maks 8 splitter; rantai lebih dalam ditolak);
  hapus splitter/port ditolak selama ada drop atau hilir; drop hanya di port `splitter_out` bebas milik asset `odp`,
  subscription satu company berstatus `pending|active|suspended`, dan bila subscription punya serving POP maka
  `path` lokasi ODP harus diawali `path` POP + `' > '`; memindah drop melepas port lama di transaksi yang sama;
  port dikunci `FOR UPDATE` saat assign. Perubahan graf fiber (port PON, splitter, sambungan) satu company berjalan
  satu per satu (`Cache::lock` `odn:topology:{company}`, 10 detik; transaksi dibuka setelah lock didapat, gagal
  dapat lock dalam 5 detik = 409), dan pemeriksaan loop membaca baris splitter dengan shared lock (current read,
  bukan snapshot transaksi), jadi dua penyambungan berlawanan serentak tidak bisa sama-sama commit sebuah loop A-B;
  deadlock yang masih mungkin (lock store tidak dibagi antar proses) di-retry sampai 3 kali.
- **Onboarding** (Q9a): `subscription.odn_port_id` opsional (hanya bagi pemegang `odn.assign`; bagi lainnya
  diabaikan) memesan drop di transaksi onboarding yang sama; port terpakai, milik company lain, atau di luar POP
  membatalkan seluruh onboarding (tanpa customer, subscription, atau SPK). Installasi tanpa port tetap mungkin;
  daftar "subscription aktif tanpa drop" di halaman ODN untuk backfill. `CompleteSpkAction` tidak berubah.
- **Halaman**: `/admin/odn` (ODP: kapasitas, terpakai, bebas, persen; port PON dengan jumlah subscriber lewat semua
  level splitter; subscription aktif tanpa drop; filter `pop_id`, `min_percent`, `search`), `/admin/odn/assets/{asset}`
  (OLT, ODC, ODP; tipe lain 404). Subscription dan SPK instalasi menampilkan ODP dan port drop.
- **Permission**: `odn.view`, `odn.manage` (port PON, splitter), `odn.assign` (drop). Admin dan manager ketiganya;
  staff `odn.view` + `odn.assign` (mereka melakukan onboarding); teknisi `odn.view`; customer tidak ada.
- **Di luar lingkup**: loss budget optik, peta/rute fiber, splice, port rusak, impor CSV data ODN.

## Panel perangkat (port, rak, jalur fiber)

Permintaan owner 2026-10-17 (`.scratch/hardware-panel-ui/spec.md`, rancangan teknis di `design.md` pada folder yang sama): router, switch, OLT, ONU/ONT, ODC, ODP, dan rak digambar seperti wajah fisiknya, dengan port di tempat port aslinya dan status yang terbaca sekilas. Fase 1 hanya membaca data yang sudah ada di Inbils. Inbils tidak mem-polling SNMP atau RouterOS, tidak menampilkan lalu lintas per port, tidak mengirim konfigurasi ke perangkat, dan tidak menemukan port otomatis. Panel tampil di halaman aset (`/admin/network-assets/{asset}`), halaman router NAS (untuk aset routernya), halaman ODN (`/admin/odn/assets/{asset}`), dan, untuk jalur fiber, di Lacak Aset (`/admin/network-assets/trace`). Tipe aset yang punya panel: `router`, `switch`, `olt`, `onu_ont`, `odc`, `odp`, `rack` (`PanelLayout::ASSET_TYPES`). Rute `panel` menjawab 404 untuk tipe lain.

### Model data

- **Katalog model** (`port_templates`): 14 baris dari `Modules/NetworkAsset/database/data/port_templates.json`, disalin ke tabel oleh migrasi `2026_10_24_110300_seed_port_templates.php` lewat `PortTemplateCatalogue::sync()`. Data referensi bersama semua company (tanpa `company_id` dan `public_id`, seperti `settings`). Kolom: `key` (unik, pengenal yang dikirim UI), `vendor`, `model`, `asset_type` (`router`, `switch`, `olt`, `onu_ont`, `odc`, `odp`), `height_u` (null = unit meja atau dinding), `layout` (JSON), `source_url` (datasheet yang dipakai memeriksa baris). `sync()` memvalidasi tiap baris dengan aturan `PanelLayout` (baris rusak menggagalkan migrasi), memakai upsert, dan tidak pernah menghapus: baris yang ditambah operator tetap ada, dan dua kali jalan tidak mengubah apa pun. Model baru berarti satu baris JSON plus satu migrasi yang memanggil `sync()`. Unit yang belum ada di katalog digambar lewat tata letak manual, tanpa deploy.
- **Snapshot di aset** (`network_assets`): `template_key` (baris katalog asal; null untuk tata letak manual; menghapus baris katalog mengosongkannya dan snapshot tetap), `port_layout` (JSON, salinan tata letak saat model diterapkan), serta `rack_asset_id`, `rack_unit`, `height_u` (lihat Rak). Panel selalu digambar dari `port_layout`, tidak pernah dari katalog, jadi menyunting baris katalog tidak menggeser port aset yang sudah memakainya. Kelima kolom tidak ada di `$fillable`; hanya `AssetPanelService` yang menulisnya (`forceFill`), jadi rute simpan dan ubah aset tidak bisa mengubahnya.
- **Port** (`asset_ports`): satu baris per port fisik router, switch, uplink OLT, dan ONU/ONT. Kolom: `public_id`, `company_id`, `network_asset_id`, `group_key` (grup blok di tata letak: `ether`, `sfp`, `uplink`, `lan`, `pon`, dan sebagainya), `number` (mulai 1 di dalam grup), `kind` (`rj45`, `sfp`, `sc`, `rj11`), `name` (nama vendor, lihat Penamaan port), `label` (maks 64 karakter), `note` (maks 255), `admin_down`, `linked_port_id`. Unik: `public_id`, `(network_asset_id, group_key, number)`, dan `linked_port_id`. Model `AssetPort` memakai `BelongsToCompany`, `HasPublicId`, dan `LogsActivity`; `linked_port_id` tidak ada di `$fillable`. `group_key` bersifat semantik: grup yang sama di model lama dan model baru berarti port yang sama, jadi ganti model mempertahankan label, catatan, dan sambungan. Tabel ini tidak punya `subscription_id`, karena tidak ada alur yang memasang pelanggan ke port router atau switch; port PON ONT mengambil drop dari langganan ONT-nya. Info VLAN ditulis bebas di `note`.
- **Port ODN tidak disalin.** Port PON OLT dan output splitter ODC/ODP tetap di `odn_ports` (bagian ODN di atas), dan panel membaca dua sumber sekaligus. Blok `role: pon` pada OLT dicocokkan dengan `odn_ports` jenis `pon` lewat urutan baca (`odn_ports.number` adalah ordinal 1-based per OLT dan tidak berubah); blok OLT lain, misalnya uplink, adalah baris `asset_ports`. Slot model yang belum punya baris `odn_ports` digambar putus-putus dengan status `unregistered` (Belum terdaftar). Baris PON di atas jumlah slot model muncul di baris sasis tambahan berjudul "Port PON di luar model" (nama `PON {n}`), supaya tidak ada baris register yang tersembunyi. ODC dan ODP tidak pernah punya baris `asset_ports`: tata letaknya dibuat saat query dari splitternya, satu baris sasis per splitter berisi pseudo-port `IN` (input splitter, tanpa baris port, tautannya ke port hulu) dan N output (dua tingkat port mulai 16 output).

### Katalog bawaan (14 model)

Delapan baris unit vendor (MikroTik, ZTE, Huawei) dan enam baris umum (vendor `Umum`). Rancangan mencatat bahwa jumlah port, keberadaan SFP+, console, dan PSU, serta pemakaian slot pada C320 dan MA5608T diperiksa terhadap halaman di kolom `source_url`. Rancangan juga mencatat dua hal yang belum dicocokkan dengan perangkat fisik: baris atas atau bawah yang memegang nomor ganjil pada unit MikroTik bersusun dua (diasumsikan ganjil di atas), dan nama antarmuka uplink OLT (yang dipakai adalah label di muka perangkat: `10GE-1`, `GE-1`, `0/2/0`). Keduanya data, jadi koreksinya cukup menyunting JSON dan menjalankan `sync()` lewat migrasi baru.

| `key` | Tipe | Tinggi | Port |
|---|---|---|---|
| `mikrotik-hap-ac2` | router | meja | 5 RJ45 (`ether1` sampai `ether5`) |
| `mikrotik-rb4011igs-rm` | router | 1U | 10 RJ45 (dua blok 5), 1 SFP+ (`sfp-sfpplus1`) |
| `mikrotik-ccr2004-16g-2s` | router | 1U | 16 RJ45 (dua blok 8, bersusun dua), 2 SFP+ (`sfp-sfpplus1`, `sfp-sfpplus2`) |
| `mikrotik-crs326-24g-2s-rm` | switch | 1U | 24 RJ45 (tiga blok 8, bersusun dua), 2 SFP+ |
| `mikrotik-crs317-1g-16s-rm` | switch | 1U | 1 RJ45 manajemen, 16 SFP+ (dua blok 8, bersusun dua) |
| `zte-c320-gtgh` | olt | 2U | 2 slot masing-masing 16 PON (`gpon-olt_1/{slot}/{port}`); uplink 2 SFP+ 10GE, 2 SFP GE, 2 RJ45 GE |
| `zte-c320-gtgo` | olt | 2U | 2 slot masing-masing 8 PON; uplink sama |
| `huawei-ma5608t-gpfd` | olt | 2U | 2 slot masing-masing 16 PON (`0/{slot}/{port}`, mulai dari 0); 4 uplink GE (`0/2/0` sampai `0/2/3`) |
| `generic-ont-1pon-4lan-2pots` | onu_ont | meja | 1 SC `PON`, 4 RJ45 (`LAN1` sampai `LAN4`), 2 RJ11 (`TEL1`, `TEL2`) |
| `generic-switch-8` | switch | meja | 8 RJ45 (`1` sampai `8`) |
| `generic-switch-24` | switch | 1U | 24 RJ45 (dua blok 12, bersusun dua), 2 SFP (`SFP1`, `SFP2`) |
| `generic-switch-48` | switch | 1U | 48 RJ45 (empat blok 12, bersusun dua), 4 SFP |
| `generic-odp-8` | odp | meja | splitter 1:8 |
| `generic-odp-16` | odp | meja | splitter 1:16 |

ODC tidak punya baris katalog: ODC mengikuti splitter yang dibuat di halaman ODN. Baris ODP hanya ditawarkan selama ODP belum punya splitter.

### Penamaan port

`PanelLayout::portName(block, number)` memberi nama vendor sebuah port. Polanya adalah `name` pada blok (bawaan per jenis: `ether{port}`, `sfp{port}`, `sc{port}`, `tel{port}`; port PON `PON {port}`), dengan `{port}` = `number - 1 + first` dan `{slot}` = `slot` milik blok. `first` (0 atau 1, bawaan 1) adalah nomor cetak port pertama, dan `from` (bawaan 1) membuat satu grup menyambung beberapa blok (misalnya Ethernet 1-8 dan 9-16 pada CCR2004). Pola pada grup yang punya lebih dari satu port wajib memuat `{port}`, dan pola dengan `{slot}` wajib punya `slot`. Contoh dari katalog:

| Model | Ordinal di ODN | Nama vendor |
|---|---|---|
| ZTE C320 2× GTGH (`first` 1, slot 1 dan 2) | 1, 16, 17, 32 | `gpon-olt_1/1/1`, `gpon-olt_1/1/16`, `gpon-olt_1/2/1`, `gpon-olt_1/2/16` |
| Huawei MA5608T 2× GPFD (`first` 0, slot 0 dan 1) | 1, 16, 17, 32 | `0/0/0`, `0/0/15`, `0/1/0`, `0/1/15` |

Nama port non-PON disimpan di `asset_ports.name` saat model diterapkan dan diperbarui oleh penerapan berikutnya. Nama port PON dihitung saat baca dari urutan slot model, jadi `odn_ports.number` tetap seperti ditulis `OdnService::createPonPorts()` dan tabel ODN tetap menulis "PON 17" di samping `gpon-olt_1/2/1` (tooltip panel: "PON 17 di ODN"). Tata letak manual ("Susun manual") memberi nama awalan + `{port}` dengan awalan bawaan `ether`, `sfp`, `PON`, `TEL` (ONU/ONT: `LAN` untuk RJ45), dan awalan yang diketik pengguna menggantikannya.

### Menerapkan model

`PUT /admin/network-assets/{asset}/panel` menerima satu dari tiga isi: `template_key` (model katalog untuk tipe aset itu), `layout` (tata letak manual: 1 sampai 8 baris sasis, tiap baris 1 sampai 16 blok, tiap blok 1 sampai 64 port, total paling banyak 256 port, satu jenis port per grup), atau, khusus rak, `height_u` (1 sampai 52). `template_key` dan `layout` tidak boleh dikirim bersama. Tata letak manual ditolak untuk ODC dan ODP, karena port keduanya berasal dari splitter. `AssetPanelService::apply()` (422 dengan pesan di field):

- Port lama dan baru dicocokkan lewat `(group_key, number)`. Port yang ada di kedua tata letak mempertahankan label, catatan, status nonaktif, dan sambungannya, dan hanya `kind` dan `name` yang diperbarui. Port baru ditambahkan. Port yang tidak ada lagi di tata letak dihapus bila bebas; bila masih tersambung, penerapan ditolak di field `ports`, misalnya "Port ether3 dan ether4 masih terhubung. Lepas sambungannya dulu." Menerapkan model yang sama berulang tidak mengubah apa pun dan tidak menulis audit.
- OLT: P = jumlah slot PON model, H = nomor PON tertinggi di `odn_ports`. H lebih besar dari P ditolak dengan "OLT ini sudah punya H port PON terdaftar; model ini hanya P." H lebih kecil dari P membuat sisanya lewat `OdnService::createPonPorts()` (paling banyak 64 per panggilan), jadi port PON model lahir di register ODN. ODP tanpa splitter yang diberi model katalog ODP mendapat splitternya lewat `OdnService::createSplitter()` (1:8 atau 1:16). Keduanya berjalan sebelum transaksi panel, karena `OdnService` mengambil kunci topologi `odn:topology:{company}` lebih dulu.
- Satu penerapan per aset pada satu waktu (`Cache::lock("asset-panel:{company}:{aset}", 30)`, ditunggu 5 detik). Gagal mendapat kunci menghasilkan 409 "Panel perangkat ini sedang diubah. Coba lagi."
- Tinggi U aset diambil dari baris katalog, atau dari `height_u` yang dikirim bersama tata letak manual. Aset yang sudah terpasang di rak tetap memakai tinggi pemasangannya, supaya model baru tidak mendorongnya ke perangkat di sebelahnya.
- Audit `panel_applied` (aset, `template_key`, jumlah port yang ditambah, diganti nama, dan dihapus) hanya ditulis bila ada yang berubah.

**Izin tambahan untuk OLT dan ODP.** Model OLT dan ODP menulis register ODN (port PON dan splitter), jadi `ApplyPortTemplateRequest::authorize()` meminta `odn.manage` selain `network_asset.update` (lewat `can('create', OdnPort::class)`). Tanpa `odn.manage`, server menjawab 403 saat model OLT atau ODP dipilih atau diganti. Router, switch, ONU/ONT, dan ukuran rak cukup `network_asset.update`. Pemilih model di UI hanya memeriksa `network_asset.update`, jadi pemegang izin itu tanpa `odn.manage` tetap melihat pilihannya dan baru ditolak saat menekan **Terapkan model**. Peran bawaan admin dan manager memegang keduanya.

### Sambungan port (patch)

Sambungan menghubungkan dua `asset_ports` milik dua aset dalam satu company, misalnya uplink SFP+ router ke uplink OLT. Sambungan tersimpan di kedua baris: A.`linked_port_id` = B.id dan B.`linked_port_id` = A.id. `linked_port_id` unik, jadi satu port menjadi tujuan paling banyak satu port lain, dan foreign key `restrictOnDelete` menahan penghapusan port yang tersambung. MySQL menolak CHECK pada kolom AUTO_INCREMENT, jadi "bukan dirinya sendiri" adalah aturan service.

- `AssetPanelService::link()` (422 dengan pesan di field): bukan port yang sama ("Port tidak dapat dihubungkan ke dirinya sendiri."), bukan di aset yang sama ("Hubungkan ke port perangkat lain."), kedua port bebas ("Port {nama} di {kode} sudah terhubung."), dan tujuan ada di company yang sama pada aset yang belum dihapus ("Port yang dipilih tidak ditemukan."). Kedua baris dikunci `FOR UPDATE` berurutan id dalam satu transaksi, dan `unique(linked_port_id)` adalah backstop balapan. Audit `port_linked`.
- `unlink()` mengosongkan kedua baris dalam satu transaksi. Port yang tidak tersambung ditolak ("Port ini tidak terhubung."), begitu pula port yang sambungannya baru saja berubah ("Sambungan port ini baru saja berubah. Muat ulang halaman."). Audit `port_unlinked`.
- Hanya `asset_ports` yang bisa disambung. Port PON dan output splitter tidak bisa disambung lewat panel, karena hubungan serat ada di register ODN (splitter dan drop). Pilihan perangkat tujuan di UI memuat router, switch, OLT, dan ONU/ONT yang sudah punya model, kecuali aset itu sendiri. Pilihan port memuat port bebas perangkat tujuan, paling banyak 256.
- `PATCH /admin/network-assets/{asset}/ports/{port}` mengubah `label` (maks 64), `note` (maks 255), dan `admin_down`. Tanda nonaktif hanya penanda di panel dan tidak mengubah konfigurasi perangkat. Port PON belum punya tanda ini, karena butuh kolom di `odn_ports`.

### Rak

Rak di sini adalah asset bertipe `rack`, bukan lokasi bertipe `rack` di pohon lokasi. Ukurannya `network_assets.height_u` (1 sampai 52 U), diatur lewat `PUT .../panel` dengan `height_u`. Perangkat yang terpasang punya `rack_asset_id`, `rack_unit` (U terendah yang dipakai, U1 di bawah), dan `height_u` (tinggi perangkat, 1 sampai 10 U). `PUT /admin/network-assets/{asset}/rack-placement` memasang perangkat (`rack_asset_id`, `rack_unit`, `height_u`) atau melepasnya (`rack_asset_id: null`, yang mengosongkan `rack_asset_id` dan `rack_unit`). `AssetPanelService::place()` (422 dengan pesan di field):

- Tujuan harus asset bertipe `rack` di company yang sama. Rak tidak bisa dipasang di rak ("Rak tidak dapat dipasang di dalam rak.") atau di dirinya sendiri ("Perangkat tidak dapat dipasang di dirinya sendiri.").
- Rak tanpa ukuran ditolak ("Rak belum punya ukuran."), begitu pula posisi yang melewati puncak rak ("Posisi melewati tinggi rak (42U).", dengan tinggi rak yang sebenarnya).
- U yang bertabrakan ditolak dengan menyebut U yang terisi dan kode perangkatnya, misalnya "U13 sudah terisi E2E-PANEL-RTR." Perangkat yang sudah dihapus (soft delete) tetap memegang U-nya.
- Baris rak dikunci lebih dulu, lalu aset, jadi dua pemasangan ke rak yang sama tidak bisa mengambil U yang sama. `unique(rack_asset_id, rack_unit)` adalah backstop.
- Mengecilkan rak sampai di bawah puncak isinya ditolak, misalnya "Rak berisi perangkat sampai U13; ukurannya tidak boleh lebih kecil."

Audit: `rack_placed`, `rack_unplaced`, dan `panel_applied` untuk ukuran rak.

**Hapus aset.** `NetworkAssetController::destroy` memanggil `AssetPanelService::assertDeletable()` sebelum pemeriksaan siklus hidup umum. Aset yang port-nya masih tersambung ditolak ("Port aset ini masih terhubung ke port lain. Lepas sambungannya dulu."), begitu pula rak yang masih berisi perangkat ("Rak ini masih berisi perangkat. Kosongkan rak dulu."). `NetworkAsset::lifecycleReferences()` kini juga menghitung "port perangkat" (`asset_ports.network_asset_id`) dan "perangkat di rak" (`network_assets.rack_asset_id`), jadi aset yang sudah punya baris port dan rak yang masih berisi perangkat tidak bisa dihapus permanen, dan pesan umum menyebut jumlahnya.

### Jalur fiber (trace)

`GET /admin/network-assets/trace?path_subscription={id langganan}` mengisi prop `tracePath` halaman Lacak Aset, hanya bagi pemegang `odn.view`. Tanpa izin itu, atau bila langganan tidak ditemukan di company aktif, prop bernilai `null`. `AssetPanelQuery::tracePath()` berjalan dari port drop langganan naik lewat tiap splitter sampai port PON dengan satu query rekursif (paling banyak 9 tingkat splitter, satu di atas batas rantai `OdnService` yang 8, jadi siklus pada data rusak berhenti), lalu menyusun hop dari OLT sampai pelanggan:

1. Port PON OLT, bernama dari model OLT (misalnya `gpon-olt_1/1/3`), atau `PON {n}` bila OLT belum punya model.
2. Tiap output splitter ODC atau ODP, ditulis "{kode aset} · {rasio} · {nomor}".
3. ONU/ONT langganan itu (`ont_asset_id`, atau ONU/ONT yang `subscription_id`-nya langganan itu), atau kotak berlabel nama pelanggan bila tidak ada ONT.

Tiap hop membawa tata letaknya sendiri dan satu port yang dinyalakan, jadi muatannya konstan per hop. Paling banyak 3 statement SQL dan satu query `radacct` ter-cache. Langganan tanpa port drop menghasilkan `hops` kosong, dan halaman menulis "Belum ada port drop tercatat untuk {kode}. Atur di halaman langganan." Warna jalur mengikuti status drop (Terpakai, Online, Offline, atau Diisolir).

### Status online dan warna port

- **Urutan prioritas warna** (dihitung di browser oleh `portStateKey` di `resources/js/Components/composite/hardware-panel/panelGeometry.helpers.ts`; server hanya mengirim bendera status): Belum terdaftar, Dinonaktifkan, Diisolir, Online, Offline, Terpakai, Kosong. Offline hanya muncul bila ada pelanggan di port itu (drop, atau pelanggan di bawah splitter) dan daftar online diketahui. Selain itu port berpelanggan tetap Terpakai.
- **Online** = ada sesi RADIUS terbuka (`radacct.acctstoptime` kosong) yang pembaruan terakhirnya (`COALESCE(acctupdatetime, acctstarttime)`) tidak lebih tua dari 15 menit. Aturan 15 menit ada satu kali di `RadiusDirectory::open()`, dipakai daftar sesi dan pemeriksaan online. Satu panel bertanya sekali lewat `RadiusDirectory::onlineAmong()` (satu `whereIn` username untuk semua drop dan semua pelanggan di bawahnya).
- Hasilnya di-cache 15 detik per company, versi, lingkup (`asset:{id}`, `sub:{id}`, atau `port:{id}`), dan hash username terurut, jadi drop baru tidak menunggu 15 detik. `AssetPanelQuery::forgetOnline($companyId)` menaikkan versi (lewat `DB::afterCommit`) dan dipanggil `ApplyNetworkAccountJob` setelah `apply` berhasil. Blokir, buka blokir, tangguhkan, aktifkan ulang, hentikan, provision, dan sinkron ulang semuanya berakhir di job itu.
- RADIUS nonaktif atau tidak terjangkau bukan galat: kegagalan tidak di-cache, panel tetap tampil tanpa tanda online (`online.known` false, alasan `disabled` atau `unavailable`), port berpelanggan tampil Terpakai, dan legenda menulis "Status online tidak tersedia: RADIUS nonaktif." atau "Status online tidak tersedia: RADIUS tidak dapat dihubungi."
- Isolir tidak di-cache: dibaca dari baris langganan (`suspended`, atau `blocked_at` terisi, kecuali `terminated`), aturan yang sama dengan `NetworkAccountProvisioner::isolatedBecause()`.
- "Online sejak" di sheet port memanggil `RadiusDirectory::openSessions()` langsung, tanpa cache, hanya untuk satu username.

### Izin dan route

Tidak ada izin baru. `NetworkAssetPolicy::updatePanel` (ability baru) memakai `network_asset.update`.

| Aksi | Izin |
|---|---|
| Melihat panel, rak, dan port non-ODN | `network_asset.view` |
| Melihat port PON, splitter, drop ONT, pelanggan di port, dan jalur fiber | `odn.view` (tanpa izin ini panel menyembunyikan blok PON dan splitter dan menulis "Port PON dan splitter tampil untuk pengguna dengan akses ODN.") |
| Memilih atau mengganti model, label dan catatan port, tanda nonaktif, sambung dan lepas, pasang di rak, ukuran rak | `network_asset.update` |
| Memilih atau mengganti model OLT dan ODP | `network_asset.update` dan `odn.manage` |
| Memasang pelanggan di port ODP dan melepas port drop dari sheet | `odn.assign` (route ODN yang sudah ada) |
| Blokir internet dan buka blokir dari sheet | `customer.subscription.block` (route langganan yang sudah ada) |

Peran bawaan (`RolePermissionSeeder`): admin dan manager mengubah panel; staff dan technician hanya membaca. Staff juga bisa memasang dan melepas drop (`odn.assign`), dan teknisi melihat port, drop, dan tanda online karena butuh di lapangan.

Semua route ada di grup `auth, verified, redirect.app.only, require.has.company` dengan prefiks `admin` (`whereUlid` mencakup `port`). Tiga route port memakai `scopeBindings()`, jadi port milik aset lain di URL menjawab 404, dan aset atau port company lain juga 404.

| Route | Nama | Body | Pesan sukses |
|---|---|---|---|
| `PUT admin/network-assets/{asset}/panel` | `admin.network-assets.panel.update` | `template_key`, atau `layout` (dan `height_u`), atau untuk rak `height_u` | "Model perangkat diterapkan." (rak: "Ukuran rak disimpan.") |
| `PATCH admin/network-assets/{asset}/ports/{port}` | `admin.network-assets.ports.update` | `label`, `note`, `admin_down` | "Port diperbarui." |
| `POST admin/network-assets/{asset}/ports/{port}/link` | `admin.network-assets.ports.link.store` | `target_port_id` (public id) | "Port dihubungkan." |
| `DELETE admin/network-assets/{asset}/ports/{port}/link` | `admin.network-assets.ports.link.destroy` | tanpa body | "Sambungan port dilepas." |
| `PUT admin/network-assets/{asset}/rack-placement` | `admin.network-assets.rack-placement.update` | `rack_asset_id`, `rack_unit`, `height_u`; atau `rack_asset_id: null` untuk melepas | "Posisi rak disimpan." atau "Perangkat dilepas dari rak." |

Halaman membawa data panel lewat prop Inertia yang ditambahkan `AssetPanelProps::for()`: `panel` (ditunda, jadi halaman tidak menunggu panel maupun RADIUS), `portDetail` (dimuat saat port dibuka), dan daftar pilihan (`linkAssetOptions`, `linkPortOptions`, `dropSubscriptionOptions`, `rackAssetOptions`). `AssetPanelProps::for()` mengembalikan kosong untuk tipe tanpa panel atau pengguna yang tidak boleh melihat aset.

### Di luar lingkup

Polling SNMP atau RouterOS, lalu lintas per port, konfigurasi yang dikirim ke perangkat, penemuan port otomatis, LED link langsung, urutan rak lewat seret, dan sambungan dengan seret (ditunda: tidak punya padanan keyboard, dan panel hanya menampilkan satu perangkat sekaligus). Tanda nonaktif untuk port PON (butuh kolom di `odn_ports` dan route ODN). Menghapus model dari aset: tidak ada aksi untuk itu, hanya mengganti model.

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
- **Depends on (IPAM/ODN lane):** `Modules/Ipam` — `NetworkAssetController` memanggil
  `IpAssignmentService::syncAssetAddresses` / `releaseForAsset` (write-through alamat asset); `Modules/Ipam`
  membaca `NetworkAsset` (FK `ip_addresses.network_asset_id`) dan memakai Core `ServiceSubscription`. ODN
  (`OdnService`, `OdnOccupancyQuery`) dipanggil dari Core (`SubscriptionService::terminate`,
  `CreateCustomerOnboardingAction`, halaman subscription/customer) dan SPK (halaman SPK).
