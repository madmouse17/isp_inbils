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
ipam.view                      (lihat prefix, VLAN, alamat)
ipam.manage                    (prefix, VLAN, reservasi, import IP asset)
ipam.assign                    (alamat static subscription)
odn.view                       (lihat ODN: ODP, port PON, subscription tanpa drop)
odn.manage                     (port PON, splitter)
odn.assign                     (port drop subscription)
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

## NAS router dan network account (FreeRADIUS, lab pilot)

Keputusan owner 2026-10-15 (`.scratch/freeradius/spec.md`, "rekomendasi tiap keputusan"): Inbils **tidak lagi
memprovisioning router lewat RouterOS API**. Router MikroTik mengautentikasi subscriber ke FreeRADIUS 3.2; Inbils
mengelola isi database RADIUS (user, grup kecepatan, klien NAS) dan mengirim Disconnect-Request (RFC 5176) agar
perubahan grup berlaku seketika. Ini lab pilot: tanpa NAS bersama antar company, tanpa burst, tanpa IP static
lewat RADIUS. Pendahulunya (`.scratch/mikrotik-radius`, RouterOS API) dihapus; riwayatnya ada di `CHANGELOG.md`.

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
- **Desired state** (dihitung saat job jalan): `pending|active` tidak diblokir → user ada, grup kecepatan;
  `suspended` atau status apa pun selain terminated dengan `blocked_at` → user ada, grup isolir; `terminated` atau
  dihapus → user dihapus. Suspend dan blokir adalah dua hal berbeda; halaman account menampilkan
  `isolatedBecause` (`suspended`, `blocked` atau keduanya).
- **Disconnect**: dikirim ke NAS tempat sesi terbuka (`radacct.nasipaddress` dicocokkan dengan `nasname` NAS company
  itu) bila grup berubah, user dihapus atau di-rename, atau staf menekan Resync; perubahan secret saja tidak memutus
  sesi. ACK atau NAK `Session-Context-Not-Found` (503) dianggap selesai; timeout atau NAK lain → retry (`pending`);
  sesi di alamat yang bukan NAS company → dicatat di `last_error`, tidak dikirim, tidak diulang.
- **Pemicu**: observer `ServiceSubscription` (`status`, `service_package_id`, `blocked_at`, delete; setelah commit),
  simpan atau Resync account, perubahan `SpeedProfile`, dan `network:sync {--company=}` tiap 15 menit (account
  `pending`, utang Disconnect, `failed` lebih dari sejam, drift via `hasUser`, dan baris NAS yang belum tertulis).
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
  Inbils. Saat saklar mati atau RADIUS tak terjangkau, `session`/`usage` null dan `sessions` kosong; halaman tetap tampil.
- **Saklar**: `RADIUS_ENABLED` (default `false`) mematikan semua baca/tulis database RADIUS dan semua Disconnect.
  Account tetap `pending` dengan alasannya.
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

1. **Database RADIUS terpisah** (koneksi `radius`, env `RADIUS_DB_HOST|PORT|DATABASE|USERNAME|PASSWORD`; nilai hanya di
   host): skema bawaan FreeRADIUS 3.2 (`schema.sql` dari FreeRADIUS) di database sendiri; Inbils tidak pernah
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
5. **Onboarding**: `RADIUS_ENABLED=false` → isi env koneksi `radius` → daftarkan NAS (flash peringatan sampai tertulis)
   → restart FreeRADIUS → buat account → `RADIUS_ENABLED=true` → `php artisan network:sync` → terakhir
   `auto_suspend_enabled` per company. Worker queue dan scheduler harus jalan (tanpa keduanya account tetap `pending`).
6. **Rollback**: `RADIUS_ENABLED=false` (RADIUS menyimpan baris terakhir, sesi berjalan tetap hidup, tanpa
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
  tiap assign/release alamat milik subscription. Tidak ada listener di lane ini.
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
