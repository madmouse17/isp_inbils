# Hardware Panel UI Implementation Design

Lane `hardware-panel-ui` · ticket 01-design (architect) · spec `.scratch/hardware-panel-ui/spec.md` · 2026-10-18.
Skills applied: codebase-design (deep modules `AssetPanelQuery`, `AssetPanelService`, `PanelLayout`), frontend-design, antislop-ui, antislop-human, ui-ux-pro-max (tokens, 3:1 non-text contrast, keyboard, 44 px targets), writing-plans (slices with interfaces), laravel-best-practices (FormRequests, policies, no N+1, versioned cache keys).

## 1. Problem statement

The owner wants routers, NAS, OLTs, switches, ONTs, ODC/ODP and racks drawn like the physical unit: ports where the real ports are, with state a network technician reads at a glance. Today only the ODN register has ports (`odn_ports`: PON ports on an OLT, splitter outputs on an ODC/ODP), shown as tables (`Pages/Admin/Odn/Asset.tsx`, `SplitterCard.tsx`). Routers, switches and ONTs have no port records. Live data exists only as RADIUS sessions per PPPoE username (`RadiusDirectory`), never per physical port.

The design adds:

- A company-independent **port template catalogue** (`port_templates`, 14 rows seeded from JSON) and a per-asset **layout snapshot** (`network_assets.port_layout`, `template_key`), plus `rack_asset_id`, `rack_unit`, `height_u` for the rack view.
- One **port record per physical non-ODN port** (`asset_ports`) with a symmetric self-link (`linked_port_id`). PON ports and splitter outputs stay in `odn_ports`; the panel reads both, never copies.
- One read model, `AssetPanelQuery`, that builds the whole panel in at most 4 SQL statements plus one cached `radacct` IN-query, wrapped by `AssetPanelResource` (the wire contract, ≤ 30 KB for a 128-port OLT).
- One write service, `AssetPanelService` (apply model, update port, link, unlink, rack placement, delete guard).
- A lazy-loaded SVG composite: `HardwarePanel` (one `<use>` per port + one overlay `<button>` per port, roving tabindex, one shared tooltip), `RackPanel`, `TracePanel`, mounted on four pages through `AssetPanelCard` with a side `PortSheet`.

Refinements of the accepted spec decisions (each repeated with its reason in §8):

- R1 `asset_ports` has **no `subscription_id`**. No existing flow assigns a customer to a router or switch port; the ONT's PON port takes its drop from the ONT's subscription; the spec's actions only exist for ODN ports.
- R2 VLAN information goes into `asset_ports.note` (free text). No VLAN logic exists in phase 1.
- R3 Patching is a two-step "Hubungkan" in the PortSheet (pick device, pick port). Drag-to-patch is deferred: it has no keyboard equivalent and a panel shows one device at a time.
- R4 `Pages/Admin/NetworkAssets/Trace.tsx` today is an asset search (serial, MAC, IP, code, customer), not an OLT → splitter → ODP → customer list. The chain is new: a `tracePath` prop on that page.
- R5 Each asset keeps a layout snapshot, so editing a catalogue row never moves the ports of an asset that already uses it.
- R6 Admin-down applies to `asset_ports` only. PON admin-down needs an `odn_ports` column and an ODN route; deferred.
- R7 No Vitest (not installed, rule 18). Pure helpers get the repo's `node:assert` `.test.mjs` scripts; the DOM is covered by Playwright.

Non-goals (spec): SNMP/RouterOS polling, traffic per port, configuration push, auto-discovery, drag to reorder the rack, live link LEDs.

## 2. Affected modules

| Area | Existing paths (verified) | New paths (parent dir verified) |
|---|---|---|
| Schema | `Modules/NetworkAsset/database/migrations/2026_07_01_110000_create_network_assets_table.php`, `2026_10_13_100004/100005` (odn), `2026_10_22_100400_add_public_id_to_network_asset_tables.php` (latest repo migration `database/migrations/2026_10_22_200000_rewrite_notification_ids_to_public_ids.php`) | `NEW: Modules/NetworkAsset/database/migrations/2026_10_24_110000_create_port_templates_table.php`, `…110100_add_panel_columns_to_network_assets_table.php`, `…110200_create_asset_ports_table.php`, `…110300_seed_port_templates.php`; `NEW: Modules/NetworkAsset/database/data/port_templates.json` (new dir `data/` under existing `database/`) |
| Models | `Modules/NetworkAsset/app/Models/NetworkAsset.php` (casts, relations) | `NEW: Modules/NetworkAsset/app/Models/{PortTemplate,AssetPort}.php`, `NEW: Modules/NetworkAsset/database/factories/AssetPortFactory.php` |
| Layout rules | — | `NEW: Modules/NetworkAsset/app/Support/{PanelLayout,PortTemplateCatalogue,AssetPanelProps}.php` (dir holds `OdnLock.php`) |
| Write side | `Modules/NetworkAsset/app/Services/OdnService.php` (called, not edited) | `NEW: Modules/NetworkAsset/app/Services/AssetPanelService.php` |
| HTTP | `Modules/NetworkAsset/routes/web.php`, `app/Policies/NetworkAssetPolicy.php`, `app/Http/Controllers/{NetworkAssetController,OdnController,NasDeviceController}.php` | `NEW: app/Http/Controllers/{AssetPanelController,AssetPortController,AssetPortLinkController,RackPlacementController}.php`, `NEW: app/Http/Requests/{ApplyPortTemplateRequest,UpdateAssetPortRequest,LinkAssetPortRequest,UpdateRackPlacementRequest}.php` |
| Read side | `Modules/NetworkAsset/app/Queries/OdnOccupancyQuery.php` (`label()` made public), `app/Services/Radius/RadiusDirectory.php` (`onlineAmong`), `app/Jobs/ApplyNetworkAccountJob.php` (cache bump) | `NEW: Modules/NetworkAsset/app/Queries/AssetPanelQuery.php`, `NEW: Modules/NetworkAsset/app/Http/Resources/AssetPanelResource.php` |
| TS contract | `resources/js/types/network-asset.d.ts` (read only), `resources/js/lib/statuses/network.ts` (`PORT_STATE`) | `NEW: resources/js/types/hardware-panel.ts` |
| Panel composite | `resources/js/Components/ui/ChartLazy.tsx` (pattern), `Components/ui/sheet.tsx`, `Components/composite/RemoteSearchSelect.tsx`, `ConfirmRequestDialog.tsx`, `odn/*` | `NEW: resources/js/Components/composite/hardware-panel/` (dir new under existing `composite/`): `HardwarePanel.tsx`, `PanelGlyphs.tsx`, `PanelTooltip.tsx`, `PanelLegend.tsx`, `RackPanel.tsx`, `TracePanel.tsx`, `HardwarePanelLazy.tsx`, `panelGeometry.helpers.ts`, `panelGeometry.helpers.test.mjs`, `AssetPanelCard.tsx`, `PortSheet.tsx`, `PanelTemplatePicker.tsx`, `RackPlacementDialog.tsx` |
| Pages | `resources/js/Pages/Admin/NetworkAssets/Show.tsx`, `NetworkAssets/Trace.tsx`, `Odn/Asset.tsx`, `NasDevices/Show.tsx` | — |
| Tests | `tests/Traits/{BuildsIpamOdnFixtures,BuildsNetworkFixtures,InteractsWithRadius}.php`, `tests/Feature/NetworkAsset/`, `tests/e2e/`, `database/seeders/E2eFixtureSeeder.php` | `NEW: tests/Traits/BuildsPanelFixtures.php`, `NEW: tests/Feature/NetworkAsset/{AssetPanelTemplateTest,AssetPortLinkTest,RackPlacementTest,AssetPanelQueryTest,AssetPanelPagesTest}.php`, `NEW: tests/e2e/hardware-panel.spec.ts` |
| Docs | `docs/user-guide/web.md`, `docs/user-guide/pdf/web.pdf`, `scripts/docs-screenshots.json`, `docs/business/network.md`, `CHANGELOG.md`, `.scratch/hardware-panel-ui/spec.md`, `docs/plans/INBILS_OMP_BACKLOG.md` | `NEW: docs/user-guide/images/web/panel-*.png` (generated) |

No new Composer or npm dependency.

## 3. Data model / migrations

### 3.1 `port_templates` (catalogue, company-independent)

```php
Schema::create('port_templates', function (Blueprint $table) {
    $table->id();
    $table->string('key', 64)->unique();          // stable public identifier, e.g. "mikrotik-hap-ac2"
    $table->string('vendor', 64);
    $table->string('model', 128);
    $table->enum('asset_type', ['router', 'switch', 'olt', 'onu_ont', 'odc', 'odp']);
    $table->unsignedTinyInteger('height_u')->nullable(); // rack units; null = desktop or wall unit
    $table->json('layout');                       // PanelLayout, §3.4
    $table->string('source_url', 255)->nullable(); // datasheet the row was checked against
    $table->timestamps();
    $table->index('asset_type');
});
// down(): Schema::dropIfExists('port_templates');
```

No `company_id` and no `public_id`: catalogue rows are shared reference data like `settings`; `key` is the identifier the UI sends. Model `PortTemplate` (no `BelongsToCompany`), `$fillable = ['key', 'vendor', 'model', 'asset_type', 'height_u', 'layout', 'source_url']`, cast `layout => 'array'`.

"Templates are data, not code": a new unit is one INSERT (or one JSON row plus the idempotent sync, §3.9). A unit nobody catalogued is drawn from the custom builder ("N port jenis X") without any deploy.

### 3.2 `network_assets`: panel columns

```php
Schema::table('network_assets', function (Blueprint $table) {
    $table->string('template_key', 64)->nullable()->after('model');
    $table->json('port_layout')->nullable()->after('template_key');   // snapshot, §3.4
    $table->foreignId('rack_asset_id')->nullable()->after('location_id')
        ->constrained('network_assets')->restrictOnDelete();
    $table->unsignedTinyInteger('rack_unit')->nullable()->after('rack_asset_id'); // lowest U occupied, U1 = bottom
    $table->unsignedTinyInteger('height_u')->nullable()->after('rack_unit');      // device height; for a rack: its size
    $table->foreign('template_key')->references('key')->on('port_templates')->nullOnDelete();
    $table->unique(['rack_asset_id', 'rack_unit']);
});
// down(): drop the template_key FK, the rack_asset_id FK, the unique index, then the five columns.
```

- `template_key` says which catalogue row the snapshot came from (null = custom). Deleting a catalogue row nulls it; the snapshot stays.
- `port_layout` is the layout copied at apply time. The panel always draws from the snapshot, never from `port_templates`.
- A rack is a `network_assets` row with `asset_type = 'rack'`; its `height_u` is its size (e.g. 42). A mounted device has `rack_asset_id`, `rack_unit`, `height_u`.
- `unique(rack_asset_id, rack_unit)` is the cheap DB backstop; overlap is checked in `AssetPanelService::place()` under a lock on the rack row. The unique index also serves the rack-contents query.
- None of these columns is `$fillable`; `AssetPanelService` writes them with `forceFill`, so the existing store/update routes cannot set them. Casts: `port_layout => 'array'`, `rack_unit => 'integer'`, `height_u => 'integer'`. Relations: `ports(): HasMany<AssetPort>` (needed for scoped bindings), `rack(): BelongsTo<NetworkAsset, $this>` on `rack_asset_id`.

### 3.3 `asset_ports`

```php
Schema::create('asset_ports', function (Blueprint $table) {
    $table->id();
    $table->ulid('public_id')->unique();          // same final shape PublicIdColumn gives older tables
    $table->foreignId('company_id')->constrained()->restrictOnDelete();
    $table->foreignId('network_asset_id')->constrained('network_assets')->restrictOnDelete();
    $table->string('group_key', 16);              // block group of the layout: "ether", "sfp", "uplink", "lan", "pon"…
    $table->unsignedSmallInteger('number');       // 1-based within the group
    $table->enum('kind', ['rj45', 'sfp', 'sc', 'rj11']);
    $table->string('name', 32);                   // vendor name derived at apply time: "ether1", "sfp-sfpplus1", "LAN1"
    $table->string('label', 64)->nullable();      // operator label
    $table->string('note', 255)->nullable();      // free text, e.g. "VLAN 100 · ke ODC barat" (R2)
    $table->boolean('admin_down')->default(false);
    $table->unsignedBigInteger('linked_port_id')->nullable()->unique();
    $table->timestamps();

    $table->unique(['network_asset_id', 'group_key', 'number']);
    $table->index(['company_id', 'network_asset_id']);
    $table->foreign('linked_port_id')->references('id')->on('asset_ports')->restrictOnDelete();
});
// down(): Schema::dropIfExists('asset_ports');
```

- A link is stored in both rows: A.`linked_port_id` = B.id and B.`linked_port_id` = A.id. `unique(linked_port_id)` means at most one port can point at a port; the column itself means a port points at most at one. `AssetPanelService::link()` writes both rows in one transaction.
- No CHECK for self-links: MySQL refuses CHECK constraints on AUTO_INCREMENT columns. The service refuses them (§4.6) and a test pins it.
- `restrictOnDelete` on `linked_port_id` is the "restrict on delete" rule: a linked port row cannot be deleted.
- Model `AssetPort`: `BelongsToCompany`, `HasFactory`, `HasPublicId`, `LogsActivity` (same set as `OdnPort`); `$fillable = ['network_asset_id', 'group_key', 'number', 'kind', 'name', 'label', 'note', 'admin_down']` (`linked_port_id` is not fillable); casts `number => 'integer'`, `admin_down => 'boolean'`; relations `asset(): BelongsTo<NetworkAsset>` (`network_asset_id`), `linkedPort(): BelongsTo<AssetPort>` (`linked_port_id`); `newFactory(): AssetPortFactory`. Bulk inserts set `public_id => PublicId::new()` and `company_id` explicitly, like `OdnService::portRow()`.

### 3.4 Layout schema (`PanelLayout`)

A layout is chassis rows (top → bottom), each a list of blocks (left → right). The same shape is stored in `port_templates.layout`, in `network_assets.port_layout`, sent to the client and validated by one rule set (`PanelLayout::rules()` + `PanelLayout::problems()`), used by both the apply request and the catalogue sync.

```ts
type Layout = { rows: Block[][] };   // 1–8 rows, 1–16 blocks per row
type Block =
  | { group: string;                 // ^[a-z][a-z0-9]{0,15}$; same group = same kind
      kind: 'rj45' | 'sfp' | 'sc' | 'rj11';
      count: number;                 // 1–64 ports in this block
      from?: number;                 // first number of this block inside its group (default 1); lets one group span blocks
      stack?: 1 | 2;                 // port rows inside the block (default 1)
      order?: 'row' | 'column';      // stack 2: "column" = 1 top, 2 bottom, 3 top… (zigzag); "row" = top row first. Default "column"
      name?: string;                 // pattern with {port} and {slot}, ≤ 32 chars; required to contain {port} when the group has > 1 port
      first?: 0 | 1;                 // printed index of number 1 (default 1): {port} = number − 1 + first
      slot?: number;                 // printed into {slot}
      title?: string;                // block caption, ≤ 40 chars
      speed?: string;                // "1G", "10G", "GPON"; tooltip and PortSheet only
      role?: 'pon' | 'splitter' }    // pon: OLT only, backed by odn_ports; splitter: ODP catalogue only, creates a splitter
  | { fixture: 'power' | 'console' | 'usb' | 'wlan' | 'reset'; title?: string };
```

Cross-field rules (`PanelLayout::problems()`, Indonesian messages): total ports ≤ 256 (`PanelLayout::MAX_PORTS`); every (group, number) unique across blocks; one kind per group; `role: pon` only on `olt` and only with kind `sfp`; `role: splitter` only on `odp` catalogue rows with `count` in `OdnSplitter::OUTPUTS`; custom layouts of `odc`/`odp` are refused (their ports come from splitters); `rack` takes `height_u` only.

Group-key convention, so that changing model keeps links (§3.10): RJ45 data ports `ether`, SFP/SFP+ `sfp`, OLT uplinks `uplink…`, OLT PON `pon<slot>`, ONT `pon`/`lan`/`tel`. The custom builder derives keys the same way.

### 3.5 Port names and PON numbering (0- vs 1-based)

- `PanelLayout::portName(array $block, int $number)`: `name` (default per kind: `ether{port}`, `sfp{port}`, `sc{port}`, `tel{port}`, PON `PON {port}`) with `{port}` = `number − 1 + first` and `{slot}` = `slot`.
- `odn_ports.number` of a PON port stays what `OdnService::createPonPorts()` writes: a 1-based ordinal per OLT. Nothing in the ODN register changes.
- `PanelLayout::ponSlots($layout)` walks the `role: pon` blocks in reading order (rows top → bottom, blocks left → right) and gives ordinal → (group, number, name). The panel joins `odn_ports.number` to that ordinal.

| Model | Ordinal (odn number) | Group / number | Vendor name |
|---|---|---|---|
| ZTE C320 2× GTGH (`first` 1, slots 1–2) | 1 / 16 / 17 / 32 | pon1#1 / pon1#16 / pon2#1 / pon2#16 | `gpon-olt_1/1/1` / `gpon-olt_1/1/16` / `gpon-olt_1/2/1` / `gpon-olt_1/2/16` |
| Huawei MA5608T 2× GPFD (`first` 0, slots 0–1) | 1 / 16 / 17 / 32 | pon0#1 / pon0#16 / pon1#1 / pon1#16 | `0/0/0` / `0/0/15` / `0/1/0` / `0/1/15` |

The tooltip and the PortSheet show both ("`gpon-olt_1/2/1` · PON 17 di ODN"), so the ODN tables, which keep "PON 17", stay readable next to the panel.

### 3.6 How ODN maps to the panel without duplication

| Asset | Ports drawn from | Rule |
|---|---|---|
| OLT | `role: pon` blocks ↔ `odn_ports` (`kind = pon`) by ordinal; other blocks ↔ `asset_ports` | No `asset_ports` row is ever created for a PON block. A model slot without an `odn_ports` row is drawn dashed, state `unregistered`, `id: null`. PON rows above the model's slot count (added later on the ODN page) are drawn in an extra row titled "Port PON di luar model" (names `PON {n}`), so no register row is hidden. |
| ODC / ODP | `odn_splitters` + their `odn_ports` (`splitter_out`) | The layout is synthesized at query time: one chassis row per splitter = an `IN` pseudo-port (the splitter's `input_port_id`, `id: null`, link = upstream port) + N outputs (`stack 2` above 16). The stored snapshot only names the enclosure. ODC/ODP never get `asset_ports`. |
| ONT | `asset_ports` | The group `pon` of an `onu_ont` takes its drop from the ONT's subscription: `network_assets.subscription_id`, else the subscription whose `service_subscriptions.ont_asset_id` is this asset; then `odn_ports.subscription_id` = that subscription. |
| Router, switch | `asset_ports` | Links only. |
| Rack | `network_assets` rows with `rack_asset_id` = the rack | U slots; no ports. |

### 3.7 Existing `OdnService` paths that create or release ports

`AssetPanelService` calls the first two; nothing else in ODN changes.

| Method (`Modules/NetworkAsset/app/Services/OdnService.php`) | Effect on `odn_ports` | Panel use |
|---|---|---|
| `createPonPorts(NetworkAsset $olt, int $count)` (`:41`) | inserts `kind = pon`, numbers `highest+1 … highest+count`, 1–64 per call, under `OdnLock` | apply on an OLT: creates the missing PON rows (two calls above 64) |
| `createSplitter(NetworkAsset $host, int $outputs, …)` (`:77`) | inserts N `splitter_out` rows numbered 1…N | apply `generic-odp-*` on an ODP without splitters |
| `deleteSplitter()` (`:177`) / `deletePort()` (`:198`) | delete outputs / one PON row (refused while in use) | not called; ODN page only |
| `assignDrop()` (`:222`) / `releaseDrop()` (`:279`) / `releaseForSubscription()` (`:295`) | set / clear `subscription_id` | PortSheet calls the existing routes `admin.odn.drops.store` / `admin.odn.drops.destroy`; termination keeps releasing through the subscription flow |
| `connectSplitter()` / `updateSplitter()` | `odn_splitters.input_port_id` | not called; the `IN` pseudo-port links to the ODN page |

Lock rule: `OdnService` takes `OdnLock` itself (`Cache::lock("odn:topology:{companyId}")`, not re-entrant). `AssetPanelService::apply()` calls it **before** opening its own transaction, never inside `OdnLock::run()`.

### 3.8 Catalogue (14 rows)

| key | asset_type | height_u | Ports (groups) | Source |
|---|---|---|---|---|
| `mikrotik-hap-ac2` | router | — | 5 RJ45 `ether1–5`; fixtures power, USB, reset, WLAN | mikrotik.com/product/hap_ac2 |
| `mikrotik-rb4011igs-rm` | router | 1 | 10 RJ45 in two blocks of 5, 1 SFP+; console, power | MikroTik manual RB4011iGS+RM |
| `mikrotik-ccr2004-16g-2s` | router | 1 | 16 RJ45 in two 2×4 blocks, 2 SFP+ stacked; console, USB, 2 PSU | mikrotik.com/product/ccr2004_16g_2splus |
| `mikrotik-crs326-24g-2s-rm` | switch | 1 | 24 RJ45 in three 2×4 blocks, 2 SFP+; console, power | MikroTik manual CRS326-24G-2S+RM |
| `mikrotik-crs317-1g-16s-rm` | switch | 1 | 1 RJ45 management, 16 SFP+ in two 2×4 blocks; console, 2 PSU | mikrotik.com/product/crs317_1g_16s_rm |
| `zte-c320-gtgh` | olt | 2 | slot 1 and 2: 16 PON each (`gpon-olt_1/{slot}/{port}`); slot 3 SMXA: 2× 10GE SFP+, 2× GE SFP, 2× GE RJ45 | ZXA10 C320 datasheet (2 line-card slots, 32 GPON max, uplink 2×10GE + 2×GE optical + 2×GE electrical) |
| `zte-c320-gtgo` | olt | 2 | as above, 8 PON per slot | same |
| `huawei-ma5608t-gpfd` | olt | 2 | service slots 0 and 1: 16 PON each (`0/{slot}/{port}`, 0-based); MCUD slot 2: 4 GE uplinks `0/2/0–3`; power slot 4 | MA5608T comparison (service slots 0–1, control 2–3, power 4; GPFD 16-port) |
| `generic-ont-1pon-4lan-2pots` | onu_ont | — | 1 SC/APC `PON`, 4 RJ45 `LAN1–4`, 2 RJ11 `TEL1–2`; USB, WLAN, reset, power | — |
| `generic-switch-8` | switch | — | 8 RJ45 | — |
| `generic-switch-24` | switch | 1 | 24 RJ45 (two 2×6 blocks), 2 SFP | — |
| `generic-switch-48` | switch | 1 | 48 RJ45 (four 2×6 blocks), 4 SFP | — |
| `generic-odp-8` | odp | — | splitter 1:8 (creates it when the ODP has none) | — |
| `generic-odp-16` | odp | — | splitter 1:16 | — |

Seven vendor units (the C320 in two card variants = eight rows) plus six generic rows. Verified against the cited pages: port counts, SFP+/console/PSU presence, C320 and MA5608T slot use. Not verified: which stacked row carries odd numbers on MikroTik rack units (assumed `column`, the common odd-on-top layout) and the vendor interface names of OLT uplinks (face labels `10GE-1`, `GE-1`, `0/2/0` used instead). Both are data, fixed by editing the JSON and re-running the sync (§3.9), not code.

`Modules/NetworkAsset/database/data/port_templates.json` (verbatim):

```json
[
    {
        "key": "mikrotik-hap-ac2",
        "vendor": "MikroTik",
        "model": "hAP ac² (RBD52G-5HacD2HnD)",
        "asset_type": "router",
        "height_u": null,
        "source_url": "https://mikrotik.com/product/hap_ac2",
        "layout": {"rows": [[
            {"fixture": "power"},
            {"group": "ether", "kind": "rj45", "count": 5, "name": "ether{port}", "title": "Ethernet", "speed": "1G"},
            {"fixture": "usb"},
            {"fixture": "reset"},
            {"fixture": "wlan", "title": "WLAN 2,4/5 GHz"}
        ]]}
    },
    {
        "key": "mikrotik-rb4011igs-rm",
        "vendor": "MikroTik",
        "model": "RB4011iGS+RM",
        "asset_type": "router",
        "height_u": 1,
        "source_url": "https://help.mikrotik.com/docs/spaces/UM/pages/19136528/RB4011iGS+RM",
        "layout": {"rows": [[
            {"fixture": "console"},
            {"group": "ether", "kind": "rj45", "count": 5, "name": "ether{port}", "title": "Ethernet 1-5", "speed": "1G"},
            {"group": "ether", "kind": "rj45", "count": 5, "from": 6, "name": "ether{port}", "title": "Ethernet 6-10", "speed": "1G"},
            {"group": "sfp", "kind": "sfp", "count": 1, "name": "sfp-sfpplus{port}", "title": "SFP+", "speed": "10G"},
            {"fixture": "power"}
        ]]}
    },
    {
        "key": "mikrotik-ccr2004-16g-2s",
        "vendor": "MikroTik",
        "model": "CCR2004-16G-2S+",
        "asset_type": "router",
        "height_u": 1,
        "source_url": "https://mikrotik.com/product/ccr2004_16g_2splus",
        "layout": {"rows": [[
            {"fixture": "console"},
            {"fixture": "usb"},
            {"group": "ether", "kind": "rj45", "count": 8, "stack": 2, "order": "column", "name": "ether{port}", "title": "Ethernet 1-8", "speed": "1G"},
            {"group": "ether", "kind": "rj45", "count": 8, "from": 9, "stack": 2, "order": "column", "name": "ether{port}", "title": "Ethernet 9-16", "speed": "1G"},
            {"group": "sfp", "kind": "sfp", "count": 2, "stack": 2, "order": "column", "name": "sfp-sfpplus{port}", "title": "SFP+", "speed": "10G"},
            {"fixture": "power"},
            {"fixture": "power"}
        ]]}
    },
    {
        "key": "mikrotik-crs326-24g-2s-rm",
        "vendor": "MikroTik",
        "model": "CRS326-24G-2S+RM",
        "asset_type": "switch",
        "height_u": 1,
        "source_url": "https://help.mikrotik.com/docs/spaces/UM/pages/17956889/CRS326-24G-2S+RM",
        "layout": {"rows": [[
            {"fixture": "console"},
            {"group": "ether", "kind": "rj45", "count": 8, "stack": 2, "order": "column", "name": "ether{port}", "title": "Ethernet 1-8", "speed": "1G"},
            {"group": "ether", "kind": "rj45", "count": 8, "from": 9, "stack": 2, "order": "column", "name": "ether{port}", "title": "Ethernet 9-16", "speed": "1G"},
            {"group": "ether", "kind": "rj45", "count": 8, "from": 17, "stack": 2, "order": "column", "name": "ether{port}", "title": "Ethernet 17-24", "speed": "1G"},
            {"group": "sfp", "kind": "sfp", "count": 2, "stack": 2, "order": "column", "name": "sfp-sfpplus{port}", "title": "SFP+", "speed": "10G"},
            {"fixture": "power"}
        ]]}
    },
    {
        "key": "mikrotik-crs317-1g-16s-rm",
        "vendor": "MikroTik",
        "model": "CRS317-1G-16S+RM",
        "asset_type": "switch",
        "height_u": 1,
        "source_url": "https://mikrotik.com/product/crs317_1g_16s_rm",
        "layout": {"rows": [[
            {"fixture": "console"},
            {"group": "ether", "kind": "rj45", "count": 1, "name": "ether{port}", "title": "Manajemen", "speed": "1G"},
            {"group": "sfp", "kind": "sfp", "count": 8, "stack": 2, "order": "column", "name": "sfp-sfpplus{port}", "title": "SFP+ 1-8", "speed": "10G"},
            {"group": "sfp", "kind": "sfp", "count": 8, "from": 9, "stack": 2, "order": "column", "name": "sfp-sfpplus{port}", "title": "SFP+ 9-16", "speed": "10G"},
            {"fixture": "power"},
            {"fixture": "power"}
        ]]}
    },
    {
        "key": "zte-c320-gtgh",
        "vendor": "ZTE",
        "model": "ZXA10 C320 · 2× GTGH (32 PON)",
        "asset_type": "olt",
        "height_u": 2,
        "source_url": "http://ztegpon.cz/pdf/ZXA10_C320_Datasheet.pdf",
        "layout": {"rows": [
            [
                {"fixture": "power", "title": "Daya"},
                {"group": "pon1", "kind": "sfp", "count": 16, "role": "pon", "slot": 1, "name": "gpon-olt_1/{slot}/{port}", "title": "Slot 1 · GTGH", "speed": "GPON"}
            ],
            [
                {"group": "pon2", "kind": "sfp", "count": 16, "role": "pon", "slot": 2, "name": "gpon-olt_1/{slot}/{port}", "title": "Slot 2 · GTGH", "speed": "GPON"}
            ],
            [
                {"fixture": "console", "title": "Slot 3 · SMXA"},
                {"group": "uplink", "kind": "sfp", "count": 2, "name": "10GE-{port}", "title": "Uplink 10GE", "speed": "10G"},
                {"group": "uplinkge", "kind": "sfp", "count": 2, "name": "GE-{port}", "title": "Uplink GE", "speed": "1G"},
                {"group": "uplinkcu", "kind": "rj45", "count": 2, "name": "GE-RJ45-{port}", "title": "Uplink GE RJ45", "speed": "1G"}
            ]
        ]}
    },
    {
        "key": "zte-c320-gtgo",
        "vendor": "ZTE",
        "model": "ZXA10 C320 · 2× GTGO (16 PON)",
        "asset_type": "olt",
        "height_u": 2,
        "source_url": "http://ztegpon.cz/pdf/ZXA10_C320_Datasheet.pdf",
        "layout": {"rows": [
            [
                {"fixture": "power", "title": "Daya"},
                {"group": "pon1", "kind": "sfp", "count": 8, "role": "pon", "slot": 1, "name": "gpon-olt_1/{slot}/{port}", "title": "Slot 1 · GTGO", "speed": "GPON"}
            ],
            [
                {"group": "pon2", "kind": "sfp", "count": 8, "role": "pon", "slot": 2, "name": "gpon-olt_1/{slot}/{port}", "title": "Slot 2 · GTGO", "speed": "GPON"}
            ],
            [
                {"fixture": "console", "title": "Slot 3 · SMXA"},
                {"group": "uplink", "kind": "sfp", "count": 2, "name": "10GE-{port}", "title": "Uplink 10GE", "speed": "10G"},
                {"group": "uplinkge", "kind": "sfp", "count": 2, "name": "GE-{port}", "title": "Uplink GE", "speed": "1G"},
                {"group": "uplinkcu", "kind": "rj45", "count": 2, "name": "GE-RJ45-{port}", "title": "Uplink GE RJ45", "speed": "1G"}
            ]
        ]}
    },
    {
        "key": "huawei-ma5608t-gpfd",
        "vendor": "Huawei",
        "model": "SmartAX MA5608T · 2× GPFD (32 PON)",
        "asset_type": "olt",
        "height_u": 2,
        "source_url": "https://www.baudcom.com.cn/blog/comparison-of-huawei-gpon-olt-ma5608t-and-ma5800x2",
        "layout": {"rows": [
            [
                {"fixture": "power", "title": "Slot 4 · MPWD"},
                {"group": "pon0", "kind": "sfp", "count": 16, "role": "pon", "slot": 0, "first": 0, "name": "0/{slot}/{port}", "title": "Slot 0 · GPFD", "speed": "GPON"}
            ],
            [
                {"group": "pon1", "kind": "sfp", "count": 16, "role": "pon", "slot": 1, "first": 0, "name": "0/{slot}/{port}", "title": "Slot 1 · GPFD", "speed": "GPON"}
            ],
            [
                {"fixture": "console", "title": "Slot 2 · MCUD"},
                {"group": "uplink", "kind": "sfp", "count": 4, "slot": 2, "first": 0, "name": "0/{slot}/{port}", "title": "Uplink MCUD", "speed": "1G"}
            ]
        ]}
    },
    {
        "key": "generic-ont-1pon-4lan-2pots",
        "vendor": "Umum",
        "model": "ONT 1 PON · 4 LAN · 2 POTS · WLAN",
        "asset_type": "onu_ont",
        "height_u": null,
        "source_url": null,
        "layout": {"rows": [[
            {"group": "pon", "kind": "sc", "count": 1, "name": "PON", "title": "PON SC/APC", "speed": "GPON"},
            {"group": "lan", "kind": "rj45", "count": 4, "name": "LAN{port}", "title": "LAN", "speed": "1G"},
            {"group": "tel", "kind": "rj11", "count": 2, "name": "TEL{port}", "title": "POTS"},
            {"fixture": "usb"},
            {"fixture": "wlan", "title": "WLAN"},
            {"fixture": "reset"},
            {"fixture": "power"}
        ]]}
    },
    {
        "key": "generic-switch-8",
        "vendor": "Umum",
        "model": "Switch 8 port",
        "asset_type": "switch",
        "height_u": null,
        "source_url": null,
        "layout": {"rows": [[
            {"group": "ether", "kind": "rj45", "count": 8, "name": "{port}", "title": "Ethernet", "speed": "1G"},
            {"fixture": "power"}
        ]]}
    },
    {
        "key": "generic-switch-24",
        "vendor": "Umum",
        "model": "Switch 24 port + 2 SFP",
        "asset_type": "switch",
        "height_u": 1,
        "source_url": null,
        "layout": {"rows": [[
            {"fixture": "console"},
            {"group": "ether", "kind": "rj45", "count": 12, "stack": 2, "order": "column", "name": "{port}", "title": "Ethernet 1-12", "speed": "1G"},
            {"group": "ether", "kind": "rj45", "count": 12, "from": 13, "stack": 2, "order": "column", "name": "{port}", "title": "Ethernet 13-24", "speed": "1G"},
            {"group": "sfp", "kind": "sfp", "count": 2, "stack": 2, "order": "column", "name": "SFP{port}", "title": "SFP", "speed": "1G"},
            {"fixture": "power"}
        ]]}
    },
    {
        "key": "generic-switch-48",
        "vendor": "Umum",
        "model": "Switch 48 port + 4 SFP",
        "asset_type": "switch",
        "height_u": 1,
        "source_url": null,
        "layout": {"rows": [[
            {"fixture": "console"},
            {"group": "ether", "kind": "rj45", "count": 12, "stack": 2, "order": "column", "name": "{port}", "title": "Ethernet 1-12", "speed": "1G"},
            {"group": "ether", "kind": "rj45", "count": 12, "from": 13, "stack": 2, "order": "column", "name": "{port}", "title": "Ethernet 13-24", "speed": "1G"},
            {"group": "ether", "kind": "rj45", "count": 12, "from": 25, "stack": 2, "order": "column", "name": "{port}", "title": "Ethernet 25-36", "speed": "1G"},
            {"group": "ether", "kind": "rj45", "count": 12, "from": 37, "stack": 2, "order": "column", "name": "{port}", "title": "Ethernet 37-48", "speed": "1G"},
            {"group": "sfp", "kind": "sfp", "count": 4, "stack": 2, "order": "column", "name": "SFP{port}", "title": "SFP", "speed": "1G"},
            {"fixture": "power"}
        ]]}
    },
    {
        "key": "generic-odp-8",
        "vendor": "Umum",
        "model": "ODP 1:8",
        "asset_type": "odp",
        "height_u": null,
        "source_url": null,
        "layout": {"rows": [[
            {"group": "s1", "kind": "sc", "count": 8, "role": "splitter", "name": "{port}", "title": "Splitter 1:8"}
        ]]}
    },
    {
        "key": "generic-odp-16",
        "vendor": "Umum",
        "model": "ODP 1:16",
        "asset_type": "odp",
        "height_u": null,
        "source_url": null,
        "layout": {"rows": [[
            {"group": "s1", "kind": "sc", "count": 16, "stack": 2, "order": "row", "role": "splitter", "name": "{port}", "title": "Splitter 1:16"}
        ]]}
    }
]
```

### 3.9 Migrations, backfill and idempotency on the dev DB

| File | Kind | up() | down() |
|---|---|---|---|
| `2026_10_24_110000_create_port_templates_table.php` | DDL | §3.1 | drop table |
| `2026_10_24_110100_add_panel_columns_to_network_assets_table.php` | DDL | §3.2 | drop FKs, unique, columns |
| `2026_10_24_110200_create_asset_ports_table.php` | DDL | §3.3 | drop table |
| `2026_10_24_110300_seed_port_templates.php` | DML only | `PortTemplateCatalogue::sync()` | `DB::table('port_templates')->whereIn('key', PortTemplateCatalogue::keys())->delete()` (assets keep their snapshot; `template_key` nulls) |

- `PortTemplateCatalogue::sync(): int` reads `module_path('NetworkAsset', 'database/data/port_templates.json')`, validates every row with `PanelLayout::rules('layout', $row['asset_type'])` + `PanelLayout::problems()` (an invalid catalogue fails the migration loudly), then `DB::table('port_templates')->upsert($rows, ['key'], ['vendor', 'model', 'asset_type', 'height_u', 'layout', 'source_url', 'updated_at'])`. It never deletes, so rows an operator inserted by hand survive. Running it twice changes nothing. A later JSON edit ships as one more two-line migration calling `sync()`.
- **No backfill of existing assets.** ODC/ODP draw their panel from their splitters immediately. Every other type shows the "Pilih model perangkat" picker, with a suggested row when the asset's `vendor`/`model` text contains the catalogue model (case- and punctuation-insensitive). No guessed template is ever written.
- Dev DB `inbils`: only `./vendor/bin/sail artisan migrate` (additive) is allowed (owner rule); a second run prints "Nothing to migrate". Rollback is exercised only on `inbils_test` by `test_the_panel_migrations_roll_back_and_apply_again`.

### 3.10 Company scoping, unique constraints, restrict-delete

- `AssetPort` uses `BelongsToCompany` (fails closed without a company). Every `DB::table` query in `AssetPanelQuery` filters `company_id = CompanyService::currentId()` explicitly, like `OdnOccupancyQuery::companyId()`. Recursive CTE members also filter `company_id`.
- Route model binding goes through the global scope (another company's asset or port is a 404). Port routes use `scopeBindings()`, so a port of another asset in the URL is a 404.
- Public ids in requests are resolved with the existing `ResolvesPublicIds` concern plus `Rule::exists(...)->where('company_id', CompanyService::currentId())`.
- Cache keys carry the company id (§4.5).
- Unique: `port_templates.key`; `asset_ports.public_id`; `asset_ports(network_asset_id, group_key, number)`; `asset_ports.linked_port_id`; `network_assets(rack_asset_id, rack_unit)`. Existing ODN uniques unchanged.
- Restrict-delete: FKs `asset_ports.linked_port_id`, `asset_ports.network_asset_id`, `network_assets.rack_asset_id` are `restrictOnDelete`. Assets are soft-deleted, so `AssetPanelService::assertDeletable()` (called from `NetworkAssetController::destroy`) refuses with 422 while a port of the asset is linked ("Port aset ini masih terhubung ke port lain. Lepas sambungannya dulu.") or while a rack still holds devices ("Rak ini masih berisi perangkat. Kosongkan rak dulu."). Re-applying a model never deletes a linked port (422 naming the ports).

## 4. API / Inertia contracts

### 4.1 Page props

`AssetPanelProps::for(Request $request, NetworkAsset $asset): array` is the only thing a controller adds. It returns `[]` when the type has no panel (`PanelLayout::ASSET_TYPES = ['router','switch','olt','onu_ont','odc','odp','rack']`) or the user cannot `view` the asset.

| Prop | Type | Evaluated when |
|---|---|---|
| `panel` | `Inertia::defer(fn () => (new AssetPanelResource(AssetPanelQuery::for($asset, $withOdn)))->resolve(), 'panel')` | automatically after the first render (React `<Deferred data="panel">`) |
| `portDetail` | `Inertia::optional(fn () => AssetPanelQuery::portDetail($asset, (string) $request->query('port'), $withOdn))` | `router.reload({ only: ['portDetail'], data: { port } })` |
| `linkAssetOptions` | `SearchOptions::for($request, 'linkAsset', …)` (only with `updatePanel`) | `RemoteSearchSelect field="linkAsset"` |
| `linkPortOptions` | `Inertia::optional(fn () => AssetPanelQuery::freePortOptions($asset, $request->query('link_asset')))` (only with `updatePanel`) | `router.reload({ only: ['linkPortOptions'], data: { link_asset } })` |
| `dropSubscriptionOptions` | `SearchOptions::for($request, 'dropSubscription', …)` (ODP, `odn.view` and `odn.assign`) | `RemoteSearchSelect field="dropSubscription"` |
| `rackAssetOptions` | `SearchOptions::for($request, 'rackAsset', …)` (rack, `updatePanel`) | `RemoteSearchSelect field="rackAsset"` |

`$withOdn = $user->can('viewAny', OdnPort::class)` (`odn.view`). Without it the panel omits ODN-backed groups (PON, splitters, ONT drop state) and shows "Port PON dan splitter tampil untuk pengguna dengan akses ODN."

Option bases (all company-scoped through `BelongsToCompany`):

- `linkAsset`: `NetworkAsset::query()->whereNotNull('port_layout')->whereKeyNot($asset->id)->whereIn('asset_type', ['router','switch','olt','onu_ont'])`, search `code`, `name`; option `{value: public_id, label: "CODE · name", description: "vendor model"}`.
- `freePortOptions`: free `asset_ports` of the chosen asset (`whereNull('linked_port_id')`, ordered by group, number, ≤ 256) → `{value: public_id, label: "ether3" or "ether3 · <label>"}`; `[]` for an unknown, foreign or same asset.
- `dropSubscription`: `ServiceSubscription` with status `pending|active|suspended`, no drop yet (`whereNotExists odn_ports.subscription_id`), and `serving_pop_id` null or an ancestor location of the ODP (walk `locations.parent_id` upward, the rule `OdnService::assertUnderServingPop()` enforces); search `code` and customer name; label "SUB-0012 · Budi".
- `rackAsset`: `NetworkAsset::query()->where('asset_type', '!=', 'rack')`, search `code`, `name`.

Controller changes:

```php
// NetworkAssetController::show — only the spread is new
return Inertia::render('Admin/NetworkAssets/Show', [
    'asset' => new NetworkAssetResource($asset),
    'locationOptions' => $this->locationOptions($request),
    ...AssetPanelProps::for($request, $asset),
]);

// NetworkAssetController::trace — one new prop
'tracePath' => fn () => $request->filled('path_subscription') && $request->user()?->can('odn.view')
    ? AssetPanelQuery::tracePath((string) $request->input('path_subscription'))
    : null,

// NetworkAssetController::destroy — before $asset->delete(), inside the existing transaction
AssetPanelService::assertDeletable($asset);

// OdnController::show — spread into the existing props
...AssetPanelProps::for($request, $asset),

// NasDeviceController::show — the NAS router's own asset
...($nasDevice->networkAsset ? AssetPanelProps::for($request, $nasDevice->networkAsset) : []),
```

### 4.2 `AssetPanelQuery`: statements per panel

Interface (static, like `OdnOccupancyQuery`):

```php
final class AssetPanelQuery
{
    /** Facts for one panel. At most 4 SQL statements + 1 cached radacct query, constant in port count. */
    public static function for(NetworkAsset $asset, bool $withOdn): array;
    /** One port of this asset (asset_ports or odn_ports by public id); null when not found. */
    public static function portDetail(NetworkAsset $asset, string $portPublicId, bool $withOdn): ?array;
    /** Fibre path of one subscription of the current company; null when not found. */
    public static function tracePath(string $subscriptionPublicId): ?array;
    /** @return list<array{value: string, label: string, description: null}> */
    public static function freePortOptions(NetworkAsset $source, mixed $targetAssetPublicId): array;
    /** Bumps the company's online-set version (HomeFeedCache pattern). */
    public static function forgetOnline(int $companyId): void;
}
```

| Statement | Runs for | SQL (bindings only, no string-built input) | Index path |
|---|---|---|---|
| Q1 asset ports + links | router, switch, olt, onu_ont | `select p.public_id, p.group_key, p.number, p.kind, p.name, p.label, p.admin_down, la.code link_asset, lp.name link_port from asset_ports p left join asset_ports lp on lp.id = p.linked_port_id left join network_assets la on la.id = lp.network_asset_id where p.company_id = ? and p.network_asset_id = ?` | `ref` on `(company_id, network_asset_id)`; joins `eq_ref` PK |
| Q2 ODN ports | olt, odc, odp (with `odn.view`) | `odn_ports o` left join `odn_splitters s` (owner splitter), `odn_ports ip` + `network_assets ia` (splitter input), `odn_splitters f` on `f.input_port_id = o.id` + `network_assets fa` (fed splitter), `service_subscriptions sub`, `customers c`, `network_accounts na` on `na.subscription_id = sub.id`; `where o.company_id = ? and o.network_asset_id = ? order by o.kind, o.odn_splitter_id, o.number` | `ref` on `(company_id, network_asset_id)`; `f` via unique `input_port_id`, `na` via unique `subscription_id`, the rest PK: all `eq_ref` |
| Q3 subscribers behind | ports from Q2 that feed a splitter | recursive CTE below | `odn_splitters.input_port_id` unique, `odn_ports(odn_splitter_id, number)` unique |
| Q4 ONT drop | onu_ont (with `odn.view`) | the ONT's subscription (`asset.subscription_id`, else `service_subscriptions.ont_asset_id = ?`) joined to its `odn_ports` drop, ODP code, splitter, status, `blocked_at`, username | PK / unique `odn_ports.subscription_id` |
| Q5 rack items | rack | `select public_id, code, name, asset_type, rack_unit, height_u, port_layout from network_assets where company_id = ? and rack_asset_id = ? and deleted_at is null order by rack_unit desc` | `(rack_asset_id, rack_unit)` unique |
| Q6 picker | asset without layout (ODC/ODP: without splitters) | `select key, vendor, model, height_u from port_templates where asset_type = ? order by vendor, model` | `asset_type` index |
| R1 online set | when the panel has subscriber usernames | `radius.radacct`: `whereIn('username', $usernames)->whereNull('acctstoptime')->whereRaw('COALESCE(acctupdatetime, acctstarttime) >= ?')->distinct()->pluck('username')`, cached 15 s | stock FreeRADIUS `username` index |

Worst case per type: OLT Q1+Q2+Q3+R1, ODC Q2+Q3+R1, ODP Q2(+Q3)+R1, ONT Q1+Q4+R1, router/switch Q1, rack Q5, plus Q6 without a layout. Max 4 statements, plus up to 2 cache-store reads when `CACHE_STORE=database` (version key, online set): **≤ 6**.

Q3 (via `DB::select($sql, $bindings)`; `?` placeholders are generated from the count of integer root ids):

```sql
WITH RECURSIVE tree (root_id, port_id, depth) AS (
    SELECT p.id, p.id, 0 FROM odn_ports p WHERE p.company_id = ? AND p.id IN (?, ?, …)
    UNION ALL
    SELECT t.root_id, c.id, t.depth + 1
    FROM tree t
    JOIN odn_splitters s ON s.input_port_id = t.port_id AND s.company_id = ?
    JOIN odn_ports c ON c.odn_splitter_id = s.id AND c.company_id = ?
    WHERE t.depth < 8
)
SELECT t.root_id, sub.status, sub.blocked_at, na.username
FROM tree t
JOIN odn_ports p ON p.id = t.port_id AND p.subscription_id IS NOT NULL
JOIN service_subscriptions sub ON sub.id = p.subscription_id
LEFT JOIN network_accounts na ON na.subscription_id = sub.id
```

Depth 8 mirrors `OdnService::MAX_HOPS`. `tracePath()` walks the other way with the same technique (drop port → `odn_splitter_id` → `input_port_id` → … → PON, depth ≤ 9), joining each hop's asset (`code`, `name`, `asset_type`, `port_layout`) and splitter (`outputs`, `label`); then one statement for the CPE (`service_subscriptions.ont_asset_id`, else the `onu_ont` asset whose `subscription_id` is the subscription) and R1 for one username: ≤ 4 statements.

Isolir = `sub.status = 'suspended' OR sub.blocked_at IS NOT NULL`, the same rule as `NetworkAccountProvisioner::isolatedBecause()`; a test pins the two together.

### 4.3 `AssetPanelResource`: the wire payload

`AssetPanelQuery::for()` returns facts with full booleans; `AssetPanelResource` is the wire format and the size budget: it omits false flags and absent optional keys (`label`, `link`, `drop`, `behind`, `picker`, `rack`). `id: null` is kept.

Port state rules:

| Role | occupied | online (only when `online.known`) | isolir | admin_down | extra |
|---|---|---|---|---|---|
| `port` (asset_ports) | `linked_port_id` set; ONT `pon` group: drop exists | ONT `pon` group: drop username in the online set | ONT `pon` group: drop isolated | `admin_down` | `link` |
| `pon` | feeds a splitter | `behind.online > 0` | — | — | `link` (fed splitter), `behind` |
| `splitter_out` | drop, or feeds a splitter | drop username online, or `behind.online > 0` | drop isolated | — | `drop`, `link`, `behind` |
| `splitter_in` (`id: null`) | input connected | — | — | — | `link` (upstream "OLT-1 · PON 3", `OdnOccupancyQuery::label()`) |
| model slot without register row | — | — | — | — | `unregistered` |

Example (OLT, trimmed to one PON and one uplink):

```json
{
  "asset": {"id": "01JC5Z8Q9V3K7M2N4P6R8T0W1Y", "code": "OLT-PUSAT-01", "type": "olt"},
  "template": {"key": "zte-c320-gtgh", "vendor": "ZTE", "model": "ZXA10 C320 · 2× GTGH (32 PON)", "height_u": 2, "layout": {"rows": [[{"fixture": "power", "title": "Daya"}, {"group": "pon1", "kind": "sfp", "count": 16, "role": "pon", "slot": 1, "name": "gpon-olt_1/{slot}/{port}", "title": "Slot 1 · GTGH", "speed": "GPON"}]]}},
  "groups": [
    {"key": "pon1", "kind": "sfp", "role": "pon", "label": "Slot 1 · GTGH", "ports": [
      {"id": "01JC5Z8Q9V3K7M2N4P6R8T0W2A", "number": 4, "name": "gpon-olt_1/1/4", "state": {"occupied": true}, "link": {"asset": "ODC-UTARA-02", "port": "1:8"}, "behind": {"total": 9, "online": 0}}
    ]},
    {"key": "uplink", "kind": "sfp", "role": "port", "label": "Uplink 10GE", "ports": [
      {"id": "01JC5Z8Q9V3K7M2N4P6R8T0W3B", "number": 1, "name": "10GE-1", "label": "ke CCR-INTI", "state": {"occupied": true}, "link": {"asset": "RTR-CCR-01", "port": "sfp-sfpplus1"}}
    ]}
  ],
  "online": {"known": true}
}
```

Size: about 200 bytes per port with nulls and false flags omitted, so a 128-port OLT is about 26 KB plus about 1.5 KB of layout: under the 30 KB budget, pinned by a test (§7).

### 4.4 Port detail, trace path

`portDetail` (on click; one or two statements, plus one uncached `openSessions()` read for "online since"): see `PortDetail` in §4.7. Customer and subscription objects appear only here.

`tracePath` (Trace page): hops OLT first, customer last. Each hop carries its own layout (or a synthesized one-splitter layout for ODC/ODP) and only the lit port, so a hop costs O(1) payload; the full panel of a hop loads only when the user opens that hop (its Show page, deferred prop). `hops: []` means the subscription has no recorded drop.

### 4.5 Online state, cache and invalidation

- `RadiusDirectory::onlineAmong(array $usernames): array` (new, `list<string>` in and out): one `whereIn` query through the existing `run()` guard (switch off → `RadiusException::disabled()`, driver error → `unavailable()`). The open/not-stale filter of `sessions()` moves into a private `open(Builder $q): Builder` used by both, so the 15-minute staleness rule exists once.
- `AssetPanelQuery` reads it through `Cache::remember($key, 15, …)`, key `asset-panel:online:{companyId}:{version}:{scope}:{sha1(sorted usernames)}`, `scope` = `asset:{id}` or `sub:{id}`. A changed drop list changes the hash, so a new drop never waits 15 s. Failures are not cached: `RadiusException` is caught outside `remember()` → `online: {known: false, reason: 'disabled' | 'unavailable'}`; the page still renders, like `NetworkAccountController::liveState()`.
- Version key `asset-panel:online:{companyId}:version`, read with `Cache::get(…, '1')` and bumped by `forgetOnline()` with `Cache::forever(…, (string) Str::uuid())`: the `Modules/Service/app/Services/HomeFeedCache.php` pattern, so no cache tags are needed (`Cache::tags` is unused in the repo and the default store `database` does not support it).
- Explicit invalidation: `ApplyNetworkAccountJob::handle()` calls `AssetPanelQuery::forgetOnline($this->companyId)` right after a successful `$provisioner->apply($account)`. Block, unblock, suspend, reactivate and terminate reach that job through `ServiceSubscriptionNetworkObserver::queueApply()`; provision and resync reach it through `NetworkAccountService::create/update/resync()`. One anchor covers all of them, after the sessions were actually cut. Isolir colour is never cached (it comes from the subscription row).

### 4.6 Routes, FormRequests, policy

All routes in the existing group 1 of `Modules/NetworkAsset/routes/web.php` (`auth`, `verified`, `redirect.app.only`, `require.has.company`, prefix `admin`, name `admin.`); add `'port'` to its `whereUlid([...])`.

```php
Route::put('network-assets/{asset}/panel', [AssetPanelController::class, 'update'])->name('network-assets.panel.update');
Route::put('network-assets/{asset}/rack-placement', [RackPlacementController::class, 'update'])->name('network-assets.rack-placement.update');
Route::scopeBindings()->group(function () {
    Route::patch('network-assets/{asset}/ports/{port}', [AssetPortController::class, 'update'])->name('network-assets.ports.update');
    Route::post('network-assets/{asset}/ports/{port}/link', [AssetPortLinkController::class, 'store'])->name('network-assets.ports.link.store');
    Route::delete('network-assets/{asset}/ports/{port}/link', [AssetPortLinkController::class, 'destroy'])->name('network-assets.ports.link.destroy');
});
```

| Method, URI | Name | Request body | Success (redirect back + flash) | Errors |
|---|---|---|---|---|
| PUT `admin/network-assets/{asset}/panel` | `admin.network-assets.panel.update` | `{template_key}` or `{layout, height_u?}`; rack: `{height_u}` | "Model perangkat diterapkan." | 403; 404 foreign asset; 409 `OdnLock` busy; 422 `template_key`, `layout`, `layout.rows.*`, `height_u`, `ports` |
| PATCH `admin/network-assets/{asset}/ports/{port}` | `admin.network-assets.ports.update` | `{label, note, admin_down}` | "Port diperbarui." | 403; 404 port not of this asset; 422 |
| POST `admin/network-assets/{asset}/ports/{port}/link` | `admin.network-assets.ports.link.store` | `{target_port_id}` (public id) | "Port dihubungkan." | 403; 404; 422 `target_port_id` |
| DELETE `admin/network-assets/{asset}/ports/{port}/link` | `admin.network-assets.ports.link.destroy` | — | "Sambungan port dilepas." | 403; 404; 422 `port` ("Port ini tidak terhubung.") |
| PUT `admin/network-assets/{asset}/rack-placement` | `admin.network-assets.rack-placement.update` | `{rack_asset_id, rack_unit, height_u}`; `{rack_asset_id: null}` unmounts | "Posisi rak disimpan." / "Perangkat dilepas dari rak." | 403; 404; 422 `rack_asset_id`, `rack_unit`, `height_u` |

Reused routes: `admin.odn.drops.store` / `admin.odn.drops.destroy` (assign, release), `admin.subscriptions.block` / `unblock` (reason), `admin.network-assets.trace` (+ `path_subscription`), `admin.subscriptions.show`, `admin.customers.show`, `admin.odn.assets.show`.

Policy: one new ability on `NetworkAssetPolicy`:

```php
public function updatePanel(User $user, NetworkAsset $asset): bool
{
    return $user->can('network_asset.update');
}
```

No new permission. Viewing uses `view` (`network_asset.view`) plus `odn.view` for ODN data; ODN actions keep their own gates (`odn.assign`), block keeps `customer.subscription.block`. Current matrix (`RolePermissionSeeder`): admin and manager edit; staff and technician read (technicians see ports, drops and online flags, which they need in the field; see D4).

FormRequests (all `authorize()` = `$this->user()?->can('updatePanel', $this->route('asset'))`, Indonesian `attributes()`/messages):

- `ApplyPortTemplateRequest`: `template_key` `['nullable','string','max:64', Rule::exists('port_templates','key')->where('asset_type', $asset->asset_type), 'prohibits:layout']`; `layout` `['nullable','array','required_without_all:template_key,height_u']` + `PanelLayout::rules('layout', $asset->asset_type)`; `height_u` `[rack ? 'required' : 'nullable', 'integer', 'between:1,52']`; on a rack `template_key` and `layout` are `prohibited`; `after()` adds `PanelLayout::problems()` under `layout`. Asset types outside `PanelLayout::ASSET_TYPES` → 404 from `RecordNotFound::abortUnless`.
- `UpdateAssetPortRequest`: `label` `['nullable','string','max:64']`, `note` `['nullable','string','max:255']`, `admin_down` `['required','boolean']`.
- `LinkAssetPortRequest` (`ResolvesPublicIds`: `target_port_id => AssetPort::class`): `target_port_id` `['required','integer', Rule::exists('asset_ports','id')->where('company_id', CompanyService::currentId())]`.
- `UpdateRackPlacementRequest` (`ResolvesPublicIds`: `rack_asset_id => NetworkAsset::class`): `rack_asset_id` `['nullable','integer', Rule::exists('network_assets','id')->where('company_id', current)->where('asset_type','rack')->whereNull('deleted_at')]`; `rack_unit` `['required_with:rack_asset_id','integer','between:1,52']`; `height_u` `['required_with:rack_asset_id','integer','between:1,10']`; `after()`: rack ≠ the asset itself.

`AssetPortLinkController::destroy` authorizes with `Gate::authorize('updatePanel', $asset)`.

### 4.7 Write rules (`AssetPanelService`)

```php
final class AssetPanelService
{
    public static function apply(NetworkAsset $asset, ?string $templateKey, ?array $layout, ?int $heightU): void;
    public static function updatePort(AssetPort $port, array $data): AssetPort;   // label, note, admin_down
    public static function link(AssetPort $port, int $targetPortId): void;
    public static function unlink(AssetPort $port): void;
    public static function place(NetworkAsset $asset, ?int $rackId, ?int $unit, ?int $height): void;
    public static function assertDeletable(NetworkAsset $asset): void;
}
```

- `apply`: resolve the layout (catalogue row copied, or the validated custom one). OLT: P = PON slots; H = highest existing PON number; H > P → 422 `template_key` "OLT ini sudah punya {H} port PON terdaftar; model ini hanya {P}."; H < P → `OdnService::createPonPorts($asset, …)` in chunks of ≤ 64, before the transaction. ODP with `role: splitter` and no splitter → `OdnService::createSplitter($asset, $outputs)`. Then `DB::transaction`: lock the asset row, lock its `asset_ports` ordered by id, reconcile against `PanelLayout::assetPortRows()`: insert missing (group, number), update `kind`/`name` of kept rows (label, note, admin_down and link stay), delete rows no longer in the layout when free, refuse with 422 `ports` "Port ether3 dan ether4 masih terhubung. Lepas sambungannya dulu." when any is linked; `forceFill(['template_key', 'port_layout', 'height_u' => template height ?? $heightU ?? current])`; `AuditService::log('network_asset', 'panel_applied', [...], $asset)`. Re-applying the same model is a no-op. Rack: sets `height_u` only (refused below the highest occupied U).
- `link`: same port → 422 "Port tidak dapat dihubungkan ke dirinya sendiri."; same asset → 422 "Hubungkan ke port perangkat lain."; transaction locking both rows `whereIn(id)->orderBy('id')->lockForUpdate()`; either already linked → 422 "Port {name} di {code} sudah terhubung."; write both `linked_port_id`; audit `port_linked`. `unique(linked_port_id)` is the backstop for a race.
- `unlink`: lock the port and its partner; not linked → 422; clear both; audit `port_unlinked`.
- `place`: transaction; lock the rack row then the asset; rack without size → 422 "Rak belum punya ukuran."; `unit + height − 1 > rack.height_u` → 422 "Posisi melewati tinggi rak ({h}U)."; overlap with another item (`rack_unit <= unit + height − 1 and rack_unit + height_u − 1 >= unit`) → 422 "U{a}–U{b} sudah terisi {code}."; write; audit `rack_placed` / `rack_unplaced`.
- `assertDeletable`: §3.10.

### 4.8 TS contract (`resources/js/types/hardware-panel.ts`, verbatim)

```ts
import type { RemoteOption } from '@/Components/composite';
import type { NetworkAssetType } from '@/types/network-asset';

export const PANEL_ASSET_TYPES = ['router', 'switch', 'olt', 'onu_ont', 'odc', 'odp', 'rack'] as const satisfies readonly NetworkAssetType[];

export type PortKind = 'rj45' | 'sfp' | 'sc' | 'rj11';
export type FixtureKind = 'power' | 'console' | 'usb' | 'wlan' | 'reset';

export interface PortBlock {
    group: string;
    kind: PortKind;
    count: number;
    from?: number;
    stack?: 1 | 2;
    order?: 'row' | 'column';
    name?: string;
    first?: 0 | 1;
    slot?: number;
    title?: string;
    speed?: string;
    role?: 'pon' | 'splitter';
}
export interface FixtureBlock {
    fixture: FixtureKind;
    title?: string;
}
export type PanelBlock = PortBlock | FixtureBlock;
export interface PanelLayout {
    rows: PanelBlock[][];
}

export type PortRole = 'port' | 'pon' | 'splitter_out' | 'splitter_in';

/** Only true flags are sent. */
export interface PortState {
    occupied?: true;
    online?: true;
    isolir?: true;
    admin_down?: true;
    unregistered?: true;
}

export interface PanelPort {
    /** public_id of the asset_ports or odn_ports row; null for a model slot without a register row or a splitter input. */
    id: string | null;
    /** 1-based within the group. */
    number: number;
    /** Vendor name, e.g. "ether1", "gpon-olt_1/1/1", "0/1/0". */
    name: string;
    label?: string;
    state: PortState;
    link?: { asset: string; port: string };
    drop?: { subscription: string; customer: string };
    behind?: { total: number; online: number };
}

export interface PanelGroup {
    key: string;
    kind: PortKind;
    role: PortRole;
    label: string;
    ports: PanelPort[];
}

export interface PanelTemplate {
    key: string | null;
    vendor: string | null;
    model: string | null;
    height_u: number | null;
    layout: PanelLayout;
}

export interface PanelOnline {
    known: boolean;
    reason?: 'disabled' | 'unavailable';
}

export interface TemplateOption {
    key: string;
    label: string;
    height_u: number | null;
}

export interface RackItem {
    id: string;
    code: string;
    name: string;
    type: NetworkAssetType;
    unit: number;
    height: number;
    layout: PanelLayout | null;
}

export interface PanelPayload {
    asset: { id: string; code: string; type: NetworkAssetType };
    /** null: no model yet (picker), or a rack. */
    template: PanelTemplate | null;
    groups: PanelGroup[];
    online: PanelOnline;
    /** Only when template is null and the type has catalogue rows. */
    picker?: { options: TemplateOption[]; suggested: string | null };
    /** Only for racks. */
    rack?: { height_u: number | null; items: RackItem[] };
    /** True when ODN-backed groups were left out for lack of odn.view. */
    odn_hidden?: true;
}

export interface PortDetail {
    id: string;
    source: 'asset' | 'odn';
    name: string;
    label: string | null;
    note: string | null;
    admin_down: boolean;
    link: { asset: { id: string; code: string; name: string }; port: { id: string; name: string } } | null;
    feeds: { asset: { id: string; code: string }; splitter: string } | null;
    drop: {
        subscription: { id: string; code: string; status: 'pending' | 'active' | 'suspended' | 'terminated'; blocked: boolean };
        customer: { id: string; name: string };
        package: string | null;
        /** null = unknown (RADIUS off or unreachable). */
        session: { online: boolean; since: string | null } | null;
    } | null;
    behind: { total: number; online: number } | null;
}

export interface TraceHop {
    /** null only for the customer end without a registered ONT. */
    asset: { id: string; code: string; name: string; type: NetworkAssetType } | null;
    /** Caption under the hop: "gpon-olt_1/1/3", "ODC-01 · 1:8 · 2", the customer name. */
    label: string;
    /** null = drawn as a labelled box. */
    layout: PanelLayout | null;
    lit: { group: string; number: number } | null;
}

export interface TracePath {
    subscription: { id: string; code: string; customer: string };
    state: PortState;
    online: PanelOnline;
    /** OLT first, customer last; empty when no drop is recorded. */
    hops: TraceHop[];
}

/** Colour priority, highest first; keys of PORT_STATE in lib/statuses/network.ts. */
export type PortStateKey = 'unregistered' | 'admin_down' | 'isolir' | 'online' | 'offline' | 'occupied' | 'free';

export interface AssetPanelPageProps {
    panel?: PanelPayload;
    portDetail?: PortDetail | null;
    linkAssetOptions?: RemoteOption[];
    linkPortOptions?: RemoteOption[];
    dropSubscriptionOptions?: RemoteOption[];
    rackAssetOptions?: RemoteOption[];
    tracePath?: TracePath | null;
}
```

## 5. UI contract

### 5.1 Composite owned by frontend A (`resources/js/Components/composite/hardware-panel/`)

```ts
// HardwarePanel.tsx
export interface PortSelection {
    key: string;                       // `${group.key}:${port.number}`, stable
    group: PanelGroup;
    port: PanelPort;
    trigger: HTMLButtonElement;        // focus returns here when the sheet closes
}
export interface HardwarePanelProps {
    panel: PanelPayload;               // template !== null
    label: string;                     // accessible name, e.g. "Port OLT-PUSAT-01"
    selectedKey?: string | null;       // port whose sheet is open
    onSelectPort?: (selection: PortSelection) => void;
}
export function HardwarePanel(props: HardwarePanelProps): JSX.Element;

// RackPanel.tsx
export interface RackPanelProps {
    asset: PanelPayload['asset'];
    heightU: number;
    items: RackItem[];
    label: string;
    onSelectSlot?: (unit: number, trigger: HTMLElement) => void;     // empty U, only when editable
    onSelectItem?: (item: RackItem, trigger: HTMLElement) => void;
}
export function RackPanel(props: RackPanelProps): JSX.Element;

// TracePanel.tsx
export interface TracePanelProps {
    path: TracePath;                   // hops.length > 0
    label: string;
}
export function TracePanel(props: TracePanelProps): JSX.Element;

// HardwarePanelLazy.tsx (ChartLazy pattern: lazy(() => import('./HardwarePanel').then((m) => ({ default: m.HardwarePanel }))) + Suspense)
export function HardwarePanelLazy(props: HardwarePanelProps): JSX.Element;
export function RackPanelLazy(props: RackPanelProps): JSX.Element;
export function TracePanelLazy(props: TracePanelProps): JSX.Element;
export function PanelSkeleton(props: { label?: string }): JSX.Element;   // role="status", default label "Memuat panel perangkat"
```

Internal files: `PanelGlyphs.tsx` (`PanelDefs` with one `<symbol>` per port kind and fixture plus hatch pattern, ids prefixed with `useId()`; `PortGlyphs` = memoised `<use>` list; `MiniPanel` = static glyphs with lit ports, used by Rack and Trace; `STATE_CLASS` map), `PanelTooltip.tsx` (the only tooltip), `PanelLegend.tsx`, `panelGeometry.helpers.ts` + `.test.mjs`. `resources/js/lib/statuses/network.ts` gains (R5 status map, labels reused by legend, aria-labels and PortSheet):

```ts
/** What a panel port shows; derived from PortState, not stored. Order = colour priority. */
export const PORT_STATE = {
    unregistered: { label: 'Belum terdaftar', tone: 'neutral' },
    admin_down: { label: 'Dinonaktifkan', tone: 'danger' },
    isolir: { label: 'Diisolir', tone: 'warning' },
    online: { label: 'Online', tone: 'success' },
    offline: { label: 'Offline', tone: 'neutral' },
    occupied: { label: 'Terpakai', tone: 'info' },
    free: { label: 'Kosong', tone: 'neutral' },
} satisfies StatusMap;
```

Geometry helpers (pure, erasable TS, no `@/` runtime imports so `node` runs them; `import type` is fine):

```ts
export const PANEL_PORT_LIMIT = 256;
export interface Box { x: number; y: number; w: number; h: number }
export interface GeoPort { i: number; key: string; group: number; port: number; kind: PortKind; glyph: Box; cell: Box; labelAt: 'top' | 'bottom' }
export interface GeoFixture { kind: FixtureKind; glyph: Box; title?: string }
export interface PanelGeometry { width: number; height: number; ports: GeoPort[]; fixtures: GeoFixture[]; titles: { text: string; x: number; y: number }[]; minCell: number }
export function layoutPanel(layout: PanelLayout, groups: PanelGroup[], heightU: number | null): PanelGeometry;
export function portStateKey(port: PanelPort, online: PanelOnline): PortStateKey;
export function neighbour(geometry: PanelGeometry, from: number, key: 'ArrowLeft' | 'ArrowRight' | 'ArrowUp' | 'ArrowDown' | 'Home' | 'End'): number;
export function minWidthPx(geometry: PanelGeometry, targetPx: number): number;
export function layoutRack(heightU: number, items: RackItem[]): { width: number; height: number; slots: { unit: number; y: number }[]; items: (RackItem & { y: number; h: number })[] };
export function layoutTrace(hops: TraceHop[]): { width: number; height: number; hops: { x: number; scale: number; geometry: PanelGeometry | null; litCentre: { x: number; y: number } | null }[] };
```

`portStateKey`: `unregistered` > `admin_down` > `isolir` > `online` > `offline` (occupied, has a drop or `behind.total > 0`, online known, not online) > `occupied` > `free`.

### 5.2 Page integration owned by frontend B

```ts
// AssetPanelCard.tsx: reads AssetPanelPageProps through usePage(); renders only for PANEL_ASSET_TYPES
export interface AssetPanelCardProps {
    assetId: string;
    assetType: NetworkAssetType;
    title?: string;                    // default "Panel perangkat"
}
// PortSheet.tsx (lazy, mounted on first selection)
export interface PortSheetProps {
    asset: PanelPayload['asset'];
    selection: PortSelection;
    detail: PortDetail | null | undefined;   // undefined while loading
    onClose: () => void;
}
// PanelTemplatePicker.tsx
export interface PanelTemplatePickerProps {
    asset: PanelPayload['asset'];
    picker: NonNullable<PanelPayload['picker']> | null;  // null: no catalogue row for this type (custom only)
    canUpdate: boolean;
    current?: PanelTemplate | null;    // set when changing model
    onDone?: () => void;
}
// RackPlacementDialog.tsx
export interface RackPlacementDialogProps {
    rack: PanelPayload['asset'];
    heightU: number;
    mode: { kind: 'place'; unit: number } | { kind: 'edit'; item: RackItem };
    onClose: () => void;
}
```

Mounts:

| Page | Where | What |
|---|---|---|
| `Admin/NetworkAssets/Show.tsx` | between `PageHeader` and the "Detail aset" grid, full width | `AssetPanelCard` (panel, rack or picker) for `PANEL_ASSET_TYPES` |
| `Admin/Odn/Asset.tsx` | between `PageHeader` and the PON table / splitter cards | `AssetPanelCard`; existing tables, `PonPortsDialog`, `SplitterDialog`, `SplitterCard` stay |
| `Admin/NasDevices/Show.tsx` | after the Status/Isolir grid, before "Sesi terbuka" | `AssetPanelCard` for the NAS router asset (`title="Panel router"`) |
| `Admin/NetworkAssets/Trace.tsx` | a "Jalur fiber {code}" card above the results; a "Jalur fiber" button on result rows that have a subscription | `TracePanelLazy` from `tracePath`; the button runs `router.reload({ only: ['tracePath'], data: { path_subscription }, preserveState: true, preserveScroll: true })` |

PortSheet actions (each calls an existing or §4.6 route; buttons render only with the permission, checked with `usePermission()`):

| Port | Shown | Actions |
|---|---|---|
| any with `id` | name, label, group, state badge (`EnumBadge map={PORT_STATE}`), speed | — |
| asset port | link (asset link + port), note | "Hubungkan ke port lain" (two steps: `RemoteSearchSelect field="linkAsset"` → `SearchSelect` of `linkPortOptions` → "Hubungkan"), "Lepas sambungan" (`ConfirmRequestDialog`, DELETE), edit label / note / "Nonaktifkan port" switch (PATCH) — `network_asset.update` |
| ODP output with drop | subscription, customer, package, online since or "Status online tidak tersedia" | "Buka langganan", "Buka pelanggan", "Lacak jalur" (Trace page `path_subscription`), "Lepas port drop" (`odn.assign`), "Blokir internet" (reason) / "Buka blokir" (`customer.subscription.block`) |
| free ODP output | — | "Pasang pelanggan" (`RemoteSearchSelect field="dropSubscription"` → POST `admin.odn.drops.store`) — `odn.assign` |
| PON / ODC output | fed asset, "N pelanggan · M online" | "Buka halaman ODN" |
| `id: null` (unregistered slot, splitter input) | payload data only, no request | "Buka halaman ODN" (unregistered slot: "Port ini ada di model, tetapi belum terdaftar di ODN.") |

After a successful write: flash via the existing toast, `router.reload({ only: ['portDetail'] })`; the redirect back already re-requests the deferred `panel`. Refusals stay in the sheet as `Alert variant="danger"` (the `ConfirmRequestDialog` pattern).

### 5.3 Visual spec

Units ≈ millimetres of the real face; 1U = 44.45 units. Rack-mount devices (template `height_u` set) are at least 440 units wide (19" body) and `height_u × 44.45` tall; desktop units take their content size.

| Kind | Glyph w×h | Cell (hit area) w×h | Drawn as |
|---|---|---|---|
| `rj45` | 14×12 | 16×17 | jack body with latch notch, light opening |
| `sfp` (SFP, SFP+, OLT PON cage) | 14×9 | 16×14 | cage frame, light opening |
| `sc` (ONT PON, ODP outputs, splitter input) | 9×9 | 11×14 | square adapter with round ferrule |
| `rj11` | 10×8 | 12×13 | narrow jack |
| fixtures `power` / `console` / `usb` / `wlan` / `reset` | 9×9 / 14×12 / 12×5 / 10×10 / 4×4 | — | not interactive, `aria-hidden` |

Block gap 6, chassis padding 6×3, chassis rows separated by a 1-unit rule (card edges), block titles as SVG text under the block (one per block, not per port). Numbers sit above top-row ports and below bottom-row ports, as printed on real units.

Colour (tokens only, both themes from `resources/css/app.css`; light / dark ratios measured with the antislop contrast checker against the chassis fill `--muted`, light `#f1f5f9`, dark `#1e293b`):

| State | Class on the `<use>` | Fill / mark | Light | Dark |
|---|---|---|---|---|
| free | `text-muted-foreground` | outline only | `#5c6a80` 5.01:1 | `#94a3b8` 5.71:1 |
| occupied | `text-[var(--status-info)] [--fill:currentColor]` | solid | `#2563eb` 4.72:1 | `#60a5fa` 5.75:1 |
| online | `text-[var(--status-success)] [--fill:currentColor]` | solid | `#15803d` 4.58:1 | `#34d399` 7.61:1 |
| offline | `text-[var(--status-neutral)] [--fill:currentColor]` | solid | `#64748b` 4.34:1 | `#94a3b8` 5.71:1 |
| isolir | `text-[var(--status-warning)] [--fill:currentColor] [--hatch:1]` | solid + diagonal hatch | `#b45309` 4.58:1 | `#fbbf24` 8.76:1 |
| admin_down | `text-[var(--status-danger)] [--fill:currentColor] [--cross:1]` | solid + cross in the opening | `#dc2626` 4.41:1 | `#f87171` 5.29:1 |
| unregistered | `text-muted-foreground [--dash:3_2]` | dashed outline | 5.01:1 | 5.71:1 |
| focus ring | `focus-visible:ring-2 focus-visible:ring-ring` | 2 px ring | `#2563eb` 4.72:1 on chassis, 5.17:1 on card | `#3b82f6` 3.98:1 on chassis, 5.44:1 on card |

All glyph pairs are non-text and pass 3:1 in both themes. No text is ever drawn in a status colour (two of them, danger and neutral, are below 4.5:1 on light `--muted`): numbers, legend text and tooltip text use `foreground` / `muted-foreground` (`#0f172a` on `#f1f5f9` = 16.3:1). Every state also has a non-colour cue (fill vs outline, hatch, cross, dash) and its text label in the legend, the tooltip and the button's `aria-label`. The symbols read the state through inherited custom properties (`--fill`, `--hatch`, `--cross`, `--dash`), so one `<use>` per port carries glyph and state. No glow, no pulsing LEDs, no shadow on the chassis (static control rule).

### 5.4 Interaction, keyboard, accessibility

- DOM per port: exactly one SVG `<use>` and one overlay HTML `<button>` (absolutely positioned in % of the panel box, covering the cell, text = port number). No per-port React component.
- The overlay container is `role="group"` with `aria-label={label}` and `aria-describedby` → visually hidden hint "Tombol panah memindah port, Enter membuka detail port." Buttons: `aria-label` = "{name}{, label}: {state}{, ke {link.asset} {link.port}}{, pelanggan {drop.subscription} {drop.customer}}{, {behind.total} pelanggan, {behind.online} online}", `aria-haspopup="dialog"`, `aria-expanded` on the selected port. The tooltip duplicates the label visually and is `aria-hidden`.
- Keys: Tab enters the group at the active port (selected, else first) and leaves it; ArrowLeft/Right = nearest port in the same row band; ArrowUp/Down = nearest port above/below (stacked row or next chassis row); Home/End = first/last; Enter/Space = select (opens the PortSheet); Escape in the sheet closes it and focus returns to `selection.trigger`. Roving tabindex is applied imperatively (swap `tabIndex` on two buttons, then `focus()`), so arrow keys cause no React render.
- Hover (fine pointer) and focus show the single `PanelTooltip` (name, state, link or drop, behind count) and a single highlight ring at the port's cell; touch taps select directly (the sheet shows everything the tooltip does).
- Targets: the panel box gets `min-width` = `minWidthPx(geometry, 24)` and, with `pointer-coarse:`, `minWidthPx(geometry, 44)`, through CSS custom properties, so every cell is ≥ 24 px on fine pointers and ≥ 44 px on touch without JS resize handling.
- Motion: tooltip fade 120 ms with `motion-reduce:transition-none`; nothing else animates.
- RackPanel: one button per empty U (`"U12 kosong — pasang perangkat"`, only when editable) and one per mounted device (`"RTR-CCR-01, U13, 1U"`), roving with ArrowUp/Down. TracePanel: one link per hop (`"OLT-PUSAT-01, port gpon-olt_1/1/3"`), Tab order left to right.

### 5.5 Responsive and dark mode

- < 640 px: the panel box scrolls horizontally inside the card (`overflow-x-auto`); the legend sits outside the scroller (never scrolls sideways) and is `max-sm:sticky` below the topbar; the PortSheet opens from the bottom. ≥ 640 px: the sheet opens on the right. ≥ 1280 px: the panel card spans the full width above the existing two-column grid.
- A 128-PON OLT (8 rows × 16 cages, min cell 15 units) is about 300 × 160 units: ≈ 880 × 470 px at 44 px targets on a phone (scroll), ≈ 640 px wide at 24 px targets on desktop (no scroll).
- Dark mode comes only from tokens (`.dark` swaps `--muted`, `--status-*`, `--ring`); no hard-coded hex in components. A Playwright check reads the computed colour of an online port in both themes.

### 5.6 States and copy (Indonesian, glossary R6 words)

| State | Copy and action |
|---|---|
| loading (deferred prop pending) | `PanelSkeleton`, `role="status"`, "Memuat panel perangkat" (pulse off with reduced motion) |
| no model, can edit | title "Pilih model perangkat"; "Panel digambar dari model perangkat. Pilih dari katalog atau susun port sendiri."; `SearchSelect` of `picker.options` preselected with `suggested`, primary "Terapkan model", link button "Susun manual" (rows of jenis port, jumlah, awalan nama; total ≤ 256) |
| no model, read only | "Model perangkat belum dipilih. Minta admin memilih model agar panel tampil." |
| ODC without splitter | "Belum ada splitter. Tambah splitter di halaman ODN." + link (`odn.manage`) |
| rack without size | "Tentukan ukuran rak" + number field "Tinggi rak (U)" default 42 + "Simpan" |
| empty rack | "Rak masih kosong. Pilih slot U untuk memasang perangkat." |
| online unknown | legend note "Status online tidak tersedia: RADIUS nonaktif." / "…: RADIUS tidak dapat dihubungi." |
| no ODN access | "Port PON dan splitter tampil untuk pengguna dengan akses ODN." |
| trace without drop | "Belum ada port drop tercatat untuk {code}. Atur di halaman langganan." + link |
| change model | header button "Ganti model" → picker in a `Modal`; refusals (linked ports) inline |

### 5.7 SVG mock (viewBox 1000 × 400, light tokens with their fallbacks)

In the component, port numbers and the legend are HTML (overlay buttons, `PanelLegend`); here they are SVG text so the mock stands alone. One `<use>` per port.

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 400" role="img" aria-labelledby="mock-title mock-desc" font-family="ui-sans-serif, system-ui, sans-serif">
  <title id="mock-title">Mock panel perangkat: OLT 16 PON dan router 5 port</title>
  <desc id="mock-desc">OLT ZTE C320 dengan satu kartu GTGH (16 port PON dalam dua baris, nomor ganjil di atas) dan 4 uplink, serta router MikroTik hAP ac² dengan 5 port ethernet. Warna dari token --status-*, setiap warna punya teks di legenda. PON 4 sedang difokus dan menampilkan tooltip.</desc>
  <style>
    .chassis { fill: hsl(var(--muted, 210 40% 96.1%)); stroke: hsl(var(--border, 214.3 31.8% 91.4%)); stroke-width: 1.5; }
    .title { fill: hsl(var(--foreground, 222.2 84% 4.9%)); font-size: 13px; font-weight: 600; }
    .face { fill: hsl(var(--muted-foreground, 215.4 16.3% 43%)); font-size: 9px; font-weight: 500; }
    .num { fill: hsl(var(--muted-foreground, 215.4 16.3% 43%)); font-size: 9px; text-anchor: middle; }
    .legend { fill: hsl(var(--foreground, 222.2 84% 4.9%)); font-size: 11px; }
    .tip { fill: hsl(var(--popover, 0 0% 100%)); stroke: hsl(var(--border, 214.3 31.8% 91.4%)); }
    .tip-text { fill: hsl(var(--popover-foreground, 222.2 84% 4.9%)); font-size: 11px; }
    .port { color: hsl(var(--muted-foreground, 215.4 16.3% 43%)); }
    .st-online { color: var(--status-success, #15803d); --fill: currentColor; }
    .st-offline { color: var(--status-neutral, #64748b); --fill: currentColor; }
    .st-isolir { color: var(--status-warning, #b45309); --fill: currentColor; --hatch: 1; }
    .st-occupied { color: var(--status-info, #2563eb); --fill: currentColor; }
    .st-admin-down { color: var(--status-danger, #dc2626); --fill: currentColor; --cross: 1; }
    .st-unregistered { --dash: 3 2; }
    .ring { fill: none; stroke: hsl(var(--ring, 221.2 83.2% 53.3%)); stroke-width: 2; }
  </style>
  <defs>
    <pattern id="hatch" width="5" height="5" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
      <rect width="2" height="5" fill="#ffffff" opacity="0.6"/>
    </pattern>
    <symbol id="g-sfp" viewBox="0 0 28 18">
      <rect x="1" y="1" width="26" height="16" rx="1.5" style="fill: var(--fill, none); stroke: currentColor; stroke-width: 1.5; stroke-dasharray: var(--dash, none)"/>
      <rect x="1" y="1" width="26" height="16" rx="1.5" fill="url(#hatch)" style="opacity: var(--hatch, 0)"/>
      <rect x="5" y="5" width="18" height="8" rx="1" style="fill: hsl(var(--background, 0 0% 100%))"/>
      <path d="M6 6 L22 12 M22 6 L6 12" style="stroke: currentColor; stroke-width: 2; opacity: var(--cross, 0)"/>
    </symbol>
    <symbol id="g-rj45" viewBox="0 0 28 24">
      <path d="M1.5 1.5 H26.5 V17.5 H19 V22.5 H9 V17.5 H1.5 Z" style="fill: var(--fill, none); stroke: currentColor; stroke-width: 1.5; stroke-linejoin: round; stroke-dasharray: var(--dash, none)"/>
      <path d="M1.5 1.5 H26.5 V17.5 H19 V22.5 H9 V17.5 H1.5 Z" fill="url(#hatch)" style="opacity: var(--hatch, 0)"/>
      <rect x="6" y="5" width="16" height="9" rx="1" style="fill: hsl(var(--background, 0 0% 100%))"/>
      <path d="M7 6 L21 13 M21 6 L7 13" style="stroke: currentColor; stroke-width: 2; opacity: var(--cross, 0)"/>
    </symbol>
    <symbol id="g-console" viewBox="0 0 28 24">
      <path d="M1.5 1.5 H26.5 V17.5 H19 V22.5 H9 V17.5 H1.5 Z" style="fill: none; stroke: currentColor; stroke-width: 1.5; stroke-dasharray: 2 1.5"/>
      <path d="M7 9.5 H21" style="stroke: currentColor; stroke-width: 1.5"/>
    </symbol>
    <symbol id="g-power" viewBox="0 0 24 24">
      <circle cx="12" cy="12" r="10" style="fill: none; stroke: currentColor; stroke-width: 1.5"/>
      <circle cx="12" cy="12" r="3.5" style="fill: currentColor"/>
    </symbol>
    <symbol id="g-usb" viewBox="0 0 30 12">
      <rect x="1" y="1" width="28" height="10" rx="2" style="fill: none; stroke: currentColor; stroke-width: 1.5"/>
      <rect x="5" y="4" width="20" height="4" style="fill: currentColor"/>
    </symbol>
    <symbol id="g-reset" viewBox="0 0 12 12">
      <circle cx="6" cy="6" r="4.5" style="fill: none; stroke: currentColor; stroke-width: 1.5"/>
    </symbol>
    <symbol id="g-wlan" viewBox="0 0 24 24">
      <path d="M4 9 A11 11 0 0 1 20 9 M7 12.5 A7 7 0 0 1 17 12.5 M10 16 A3 3 0 0 1 14 16" style="fill: none; stroke: currentColor; stroke-width: 1.5; stroke-linecap: round"/>
      <circle cx="12" cy="19" r="1.5" style="fill: currentColor"/>
    </symbol>
  </defs>

  <!-- OLT: power, console, 16 PON (two rows, odd numbers on top), 4 uplinks -->
  <text class="title" x="24" y="26">OLT-PUSAT-01 · ZTE ZXA10 C320 · slot 1 GTGH (16 PON)</text>
  <rect class="chassis" x="20" y="36" width="960" height="128" rx="6"/>
  <use href="#g-power" class="port" x="34" y="84" width="26" height="26"/>
  <text class="num" x="47" y="124">PWR</text>
  <use href="#g-console" class="port" x="72" y="83" width="34" height="29"/>
  <text class="num" x="89" y="124">CON</text>

  <use href="#g-sfp" class="port st-online" x="130" y="60" width="42" height="27"/>
  <use href="#g-sfp" class="port st-online" x="130" y="104" width="42" height="27"/>
  <use href="#g-sfp" class="port st-online" x="182" y="60" width="42" height="27"/>
  <use href="#g-sfp" class="port st-offline" x="182" y="104" width="42" height="27"/>
  <use href="#g-sfp" class="port st-online" x="234" y="60" width="42" height="27"/>
  <use href="#g-sfp" class="port st-occupied" x="234" y="104" width="42" height="27"/>
  <use href="#g-sfp" class="port" x="286" y="60" width="42" height="27"/>
  <use href="#g-sfp" class="port" x="286" y="104" width="42" height="27"/>
  <use href="#g-sfp" class="port" x="338" y="60" width="42" height="27"/>
  <use href="#g-sfp" class="port" x="338" y="104" width="42" height="27"/>
  <use href="#g-sfp" class="port" x="390" y="60" width="42" height="27"/>
  <use href="#g-sfp" class="port st-unregistered" x="390" y="104" width="42" height="27"/>
  <use href="#g-sfp" class="port" x="442" y="60" width="42" height="27"/>
  <use href="#g-sfp" class="port" x="442" y="104" width="42" height="27"/>
  <use href="#g-sfp" class="port" x="494" y="60" width="42" height="27"/>
  <use href="#g-sfp" class="port" x="494" y="104" width="42" height="27"/>
  <text class="num" x="151" y="56">1</text>
  <text class="num" x="203" y="56">3</text>
  <text class="num" x="255" y="56">5</text>
  <text class="num" x="307" y="56">7</text>
  <text class="num" x="359" y="56">9</text>
  <text class="num" x="411" y="56">11</text>
  <text class="num" x="463" y="56">13</text>
  <text class="num" x="515" y="56">15</text>
  <text class="num" x="151" y="144">2</text>
  <text class="num" x="203" y="144">4</text>
  <text class="num" x="255" y="144">6</text>
  <text class="num" x="307" y="144">8</text>
  <text class="num" x="359" y="144">10</text>
  <text class="num" x="411" y="144">12</text>
  <text class="num" x="463" y="144">14</text>
  <text class="num" x="515" y="144">16</text>
  <text class="face" x="130" y="158">PON · gpon-olt_1/1/1–16</text>

  <use href="#g-sfp" class="port st-occupied" x="572" y="60" width="42" height="27"/>
  <use href="#g-sfp" class="port" x="572" y="104" width="42" height="27"/>
  <use href="#g-sfp" class="port" x="624" y="60" width="42" height="27"/>
  <use href="#g-sfp" class="port" x="624" y="104" width="42" height="27"/>
  <text class="num" x="593" y="56">1</text>
  <text class="num" x="645" y="56">3</text>
  <text class="num" x="593" y="144">2</text>
  <text class="num" x="645" y="144">4</text>
  <text class="face" x="572" y="158">Uplink 10GE · GE</text>
  <text class="face" x="964" y="56" text-anchor="end">ZXA10 C320</text>

  <!-- PON 4 focused: one shared tooltip -->
  <rect class="ring" x="178" y="100" width="50" height="35" rx="4"/>
  <rect class="tip" x="150" y="172" width="262" height="58" rx="6"/>
  <path class="tip" d="M197 173 L203 165 L209 173"/>
  <text class="tip-text" x="162" y="190" font-weight="600">gpon-olt_1/1/4 · Offline</text>
  <text class="tip-text" x="162" y="206">→ ODC-UTARA-02 · 1:8</text>
  <text class="tip-text" x="162" y="222">9 pelanggan · 0 online</text>

  <!-- Router: power, ether1–5, USB, reset, WLAN -->
  <text class="title" x="24" y="262">RTR-HAP-01 · MikroTik hAP ac²</text>
  <rect class="chassis" x="20" y="272" width="560" height="74" rx="12"/>
  <use href="#g-power" class="port" x="40" y="296" width="26" height="26"/>
  <text class="num" x="53" y="336">PWR</text>
  <use href="#g-rj45" class="port st-occupied" x="84" y="286" width="42" height="36"/>
  <use href="#g-rj45" class="port st-occupied" x="138" y="286" width="42" height="36"/>
  <use href="#g-rj45" class="port" x="192" y="286" width="42" height="36"/>
  <use href="#g-rj45" class="port" x="246" y="286" width="42" height="36"/>
  <use href="#g-rj45" class="port st-admin-down" x="300" y="286" width="42" height="36"/>
  <text class="num" x="105" y="336">ether1</text>
  <text class="num" x="159" y="336">ether2</text>
  <text class="num" x="213" y="336">ether3</text>
  <text class="num" x="267" y="336">ether4</text>
  <text class="num" x="321" y="336">ether5</text>
  <use href="#g-usb" class="port" x="362" y="298" width="36" height="14"/>
  <text class="num" x="380" y="336">USB</text>
  <use href="#g-reset" class="port" x="414" y="299" width="12" height="12"/>
  <text class="num" x="420" y="336">RST</text>
  <use href="#g-wlan" class="port" x="446" y="289" width="30" height="30"/>
  <text class="num" x="461" y="336">WLAN</text>

  <!-- Legend: every colour has its text -->
  <use href="#g-sfp" class="port st-online" x="24" y="362" width="21" height="14"/>
  <text class="legend" x="50" y="373">Online</text>
  <use href="#g-sfp" class="port st-offline" x="116" y="362" width="21" height="14"/>
  <text class="legend" x="142" y="373">Offline</text>
  <use href="#g-sfp" class="port st-isolir" x="212" y="362" width="21" height="14"/>
  <text class="legend" x="238" y="373">Diisolir</text>
  <use href="#g-sfp" class="port st-occupied" x="312" y="362" width="21" height="14"/>
  <text class="legend" x="338" y="373">Terpakai</text>
  <use href="#g-sfp" class="port" x="414" y="362" width="21" height="14"/>
  <text class="legend" x="440" y="373">Kosong</text>
  <use href="#g-sfp" class="port st-admin-down" x="506" y="362" width="21" height="14"/>
  <text class="legend" x="532" y="373">Dinonaktifkan</text>
  <use href="#g-sfp" class="port st-unregistered" x="640" y="362" width="21" height="14"/>
  <text class="legend" x="666" y="373">Belum terdaftar</text>
  <text class="face" x="790" y="373">Online = sesi RADIUS, diperbarui ≤ 15 dtk</text>
</svg>
```

### 5.8 Wireframes

Desktop, `NetworkAssets/Show` of an OLT:

```
┌ OLT-PUSAT-01 ─────────────────────────────── [Kembali] [Ubah] [Port ODN] ┐
├ Panel perangkat · ZTE ZXA10 C320 · 2× GTGH                  [Ganti model] ┤
│ ■ Online 21  ■ Offline 3  ▨ Diisolir 1  ■ Terpakai 4  □ Kosong 3  … RADIUS │
│ ┌──────────────────────────── SVG chassis ─────────────────────────────┐   │
│ │ (o) [1][3][5][7][9][11][13][15]…  Slot 1 · GTGH                       │   │
│ │     [2][4][6][8][10][12][14][16]…                                     │   │
│ │     [ … slot 2 … ]                                                    │   │
│ │ [C] [10GE-1][10GE-2] [GE-1][GE-2] [RJ-1][RJ-2]  Slot 3 · SMXA          │   │
│ └───────────────────────────────────────────────────────────────────────┘   │
│ Tombol panah memindah port · Enter membuka detail port                      │
├ Detail aset (unchanged)                  │ Siklus hidup (unchanged)         ┤
└───────────────────────────────────────── PortSheet slides in from the right ┘
```

Phone (390 px): legend sticky under the topbar; the chassis scrolls sideways with 44 px cells; tapping a port opens the PortSheet from the bottom.

## 6. Performance budget

| # | Rule | Mechanism | Owner | Acceptance |
|---|---|---|---|---|
| P1 | One panel query per page | `AssetPanelQuery::for()` called once from the deferred `panel` prop: set-based Q1–Q6 (§4.2), the route-bound asset reused | 03 | `test_the_panel_of_a_128_port_olt_runs_at_most_six_queries` (query logs of the default and `radius` connections) |
| P2 | No N+1 | statement count independent of ports | 03 | `test_the_query_count_does_not_grow_from_8_to_128_ports` (equal counts) |
| P3 | Online set fetched once per asset | `RadiusDirectory::onlineAmong()` one `whereIn` query, cached 15 s per asset key, versioned per company | 03 | `test_the_online_set_is_one_radacct_query_cached_for_15_seconds` |
| P4 | Explicit invalidation on block / unblock / provision | `forgetOnline()` after `ApplyNetworkAccountJob` applies (all three flows end there) | 03 | `test_an_apply_job_forgets_the_online_set_of_its_company` |
| P5 | Payload ≤ 30 KB for a 128-port OLT | public ids only; labels only (`link`, `drop` are label pairs); false flags and nulls omitted; details on click via `portDetail` | 03 | `test_the_128_port_olt_payload_stays_under_30_kb` (`strlen(json_encode(resource)) <= 30720`, 64 drops, all PON linked) |
| P6 | Page not blocked by RADIUS | `panel` is `Inertia::defer`; RADIUS failure → `online.known = false`, nothing cached | 03 / 05 | `test_online_is_unknown_while_radius_is_off_or_down`; Show page TTI (P11) |
| P7 | ≤ 1 SVG element per port | `<use>` of a per-kind `<symbol>`; state via class + inherited custom properties; one overlay `<button>` per port; no per-port component | 04 | E2E counts: `svg use[data-port]` = ports, `[data-port-button]` = ports |
| P8 | One tooltip, no hover re-render | `PanelTooltip` is the only stateful hover component (delegated `pointerover`/`focusin` on the overlay, `data-i`); `PortGlyphs` and the button layer are `React.memo` with stable keys `${group}:${number}`; roving tabindex applied imperatively | 04 | E2E: exactly one `[role=tooltip]`; P13 |
| P9 | Virtualise only above 256 ports | layouts are capped at 256 by validation, so phase 1 never virtualises (`shortcut:` comment in `panelGeometry.helpers.ts`: "cap 256 ports, virtualise rows when a model above 256 is catalogued") | 02 / 04 | `test_a_custom_layout_is_capped_at_256_ports` |
| P10 | Panel code paid only where used | `HardwarePanelLazy`/`RackPanelLazy`/`TracePanelLazy` (`React.lazy`, ChartLazy pattern); `PortSheet` lazy on first selection; pages import only the lazy wrappers and `AssetPanelCard` | 04 / 05 | `npm run build`: a separate `HardwarePanel-*.js` chunk; `NetworkAssets/Index-*.js` does not import it |
| P11 | TTI < 1.5 s | the above | 07 | Playwright on the 128-PON OLT Show page (dev stack, built assets): `performance.now()` at `[data-panel-ready="true"]` < 1500 |
| P12 | Panel paint < 100 ms after props | `HardwarePanel` marks `panel:props` on first render with data and `panel:painted` in `useLayoutEffect` → `requestAnimationFrame`; measure `panel:paint` | 04 / 07 | `performance.getEntriesByName('panel:paint')[0].duration < 100` |
| P13 | Hover / focus < 16 ms | delegated handler marks `panel:hover:start`; `PanelTooltip` layout effect measures `panel:hover` | 04 / 07 | 20 ArrowRight presses and 20 hovers: max `panel:hover` < 16 ms |
| P14 | Rack and trace reuse the symbols | `RackPanel`/`TracePanel` draw through `MiniPanel` with the same `PanelDefs` (one `<defs>` per composite SVG) | 04 | code review: no second glyph set |
| P15 | Trace chain loads per hop | `tracePath` (≤ 4 statements) sends each hop's layout and one lit port, never a hop's full panel; a hop's full panel loads only when the user opens that hop (its page, deferred) | 03 / 04 | `test_the_trace_path_carries_one_lit_port_per_hop` |
| P16 | Indexed plans | Q1–Q3 and the radacct query use the indexes listed in §4.2 | 02 / 03 | EXPLAIN of Q1, Q2, Q3 and R1 on the dev DB after `migrate`, pasted in the wave-1 report: no `ALL` scan on `asset_ports`, `odn_ports`, `odn_splitters`, `radacct` |

## 7. Test plan

Commands (run by Main after the slices land; PHPUnit uses `inbils_test` through `phpunit.xml`):

```bash
./vendor/bin/sail artisan test tests/Feature/NetworkAsset/AssetPanelTemplateTest.php tests/Feature/NetworkAsset/AssetPortLinkTest.php tests/Feature/NetworkAsset/RackPlacementTest.php tests/Feature/NetworkAsset/AssetPanelQueryTest.php tests/Feature/NetworkAsset/AssetPanelPagesTest.php
./vendor/bin/sail artisan test tests/Feature/Odn tests/Feature/NetworkAsset tests/Feature/IpamOdnLifecycleTest.php   # regressions
./vendor/bin/sail bin pint --test <touched php files>
./vendor/bin/sail bin phpstan analyse --memory-limit=512M <touched php files>
node resources/js/Components/composite/hardware-panel/panelGeometry.helpers.test.mjs
npm run typecheck && npm run lint && npm run build
./vendor/bin/sail exec -T laravel.test sh -c 'find /var/www/html/storage -user root -exec chown 1000:1000 {} +'   # after a PHPUnit run, before E2E
flock /tmp/inbils-e2e.lock sh -c 'npm run build && node scripts/e2e-setup.mjs && npm run test:e2e -- tests/e2e/hardware-panel.spec.ts --project=chromium --workers=1'
```

Shared fixture `tests/Traits/BuildsPanelFixtures.php` (uses `BuildsIpamOdnFixtures::bootIpamOdnWorld()`/`makeUser()`, `OdnService`, `AssetPanelService`): `panelOlt(int $ponPorts, int $drops, bool $linkAll = false): NetworkAsset` (custom 8 × 16 layout + 4 uplinks, ODC/ODP splitters, active subscriptions with network accounts and drops), `panelRouter(string $templateKey = 'mikrotik-ccr2004-16g-2s'): NetworkAsset`, `panelRack(int $heightU = 42): NetworkAsset`. RADIUS through `InteractsWithRadius::fakeRadius()` / `seedRadiusSession()`.

| File (slice 06) | Tests |
|---|---|
| `AssetPanelTemplateTest` | `test_applying_a_catalogue_template_creates_one_port_row_per_template_port_with_vendor_names`, `test_reapplying_the_same_template_changes_nothing`, `test_changing_model_keeps_links_and_labels_on_ports_present_in_both`, `test_changing_model_refuses_to_remove_a_linked_port_and_names_it`, `test_a_template_of_another_asset_type_is_refused`, `test_a_custom_layout_is_validated_and_capped_at_256_ports`, `test_an_olt_template_creates_the_missing_pon_ports_through_odn_service`, `test_existing_pon_ports_map_to_model_slots_by_number_without_duplicate_rows`, `test_an_olt_with_more_pon_ports_than_the_model_is_refused`, `test_zte_names_are_one_based_and_huawei_names_zero_based`, `test_a_deleted_middle_pon_port_is_an_unregistered_slot`, `test_pon_ports_beyond_the_model_are_drawn_in_an_extra_row`, `test_a_generic_odp_template_creates_its_first_splitter_and_leaves_existing_ones`, `test_rack_size_is_set_through_the_panel_route`, `test_every_catalogue_template_passes_the_layout_rules`, `test_the_catalogue_sync_is_idempotent_and_never_deletes`, `test_the_panel_migrations_roll_back_and_apply_again`, `test_applying_needs_network_asset_update` |
| `AssetPortLinkTest` | `test_linking_writes_both_directions_and_is_audited`, `test_a_port_of_another_company_cannot_be_linked`, `test_only_free_ports_can_be_linked`, `test_a_port_cannot_be_linked_to_itself`, `test_a_port_cannot_be_linked_to_another_port_of_the_same_asset`, `test_unlinking_clears_both_sides`, `test_unlinking_a_free_port_is_422`, `test_a_linked_port_row_cannot_be_deleted`, `test_an_asset_with_a_linked_port_cannot_be_deleted`, `test_a_port_of_another_asset_in_the_url_is_404`, `test_port_label_note_and_admin_down_are_updated`, `test_link_routes_need_network_asset_update`, `test_two_links_racing_for_one_port_leave_exactly_one` (pattern of `OdnConcurrencyTest`) |
| `RackPlacementTest` | `test_an_asset_is_placed_within_the_rack_size`, `test_overlapping_units_are_refused_and_name_the_occupant`, `test_a_placement_beyond_the_rack_top_is_refused`, `test_the_target_must_be_a_rack_of_the_same_company`, `test_unplacing_clears_rack_and_unit`, `test_a_rack_with_devices_cannot_be_deleted`, `test_a_rack_cannot_shrink_below_its_highest_device` |
| `AssetPanelQueryTest` | `test_the_olt_panel_has_pon_groups_from_odn_and_uplinks_from_asset_ports`, `test_the_odp_panel_has_one_group_per_splitter_with_drops_and_the_input_link`, `test_the_ont_pon_port_shows_the_drop_of_its_subscription`, `test_the_rack_panel_lists_mounted_assets_by_unit`, `test_pon_ports_count_subscribers_and_online_behind_two_splitter_levels`, `test_online_comes_from_open_not_stale_radacct_sessions`, `test_isolir_matches_the_provisioner_rule_for_suspended_blocked_and_active`, `test_online_is_unknown_while_radius_is_off_or_down`, `test_the_online_set_is_one_radacct_query_cached_for_15_seconds`, `test_an_apply_job_forgets_the_online_set_of_its_company`, `test_the_panel_of_a_128_port_olt_runs_at_most_six_queries`, `test_the_query_count_does_not_grow_from_8_to_128_ports`, `test_the_128_port_olt_payload_stays_under_30_kb`, `test_without_odn_view_the_panel_has_no_odn_groups`, `test_another_companys_rows_never_appear`, `test_the_trace_path_carries_one_lit_port_per_hop`, `test_the_picker_suggests_the_model_named_in_the_asset` |
| `AssetPanelPagesTest` | `test_the_show_page_defers_the_panel_and_sends_it_on_the_deferred_reload`, `test_types_without_a_panel_get_no_panel_props`, `test_the_odn_asset_page_and_the_nas_page_carry_the_panel`, `test_port_detail_is_sent_only_on_request_and_only_for_ports_of_that_asset`, `test_link_options_exclude_the_asset_itself_and_linked_ports`, `test_drop_subscription_options_hold_only_subscriptions_the_odp_can_serve`, `test_the_trace_page_sends_the_fibre_path_of_a_subscription_of_this_company`, `test_the_trace_path_is_null_without_odn_view` |

Frontend A self-check (`panelGeometry.helpers.test.mjs`, `node:assert/strict`): zigzag order (port 1 top, 2 bottom), `from` continues numbering across blocks, rack-width minimum 440, `neighbour()` for ArrowRight/Down/Home/End across stacked rows and chassis rows, `portStateKey()` priority including `offline` only when online is known, `minWidthPx()` gives ≥ 44 px cells, `layoutRack()` puts U1 at the bottom, 256-port cap.

E2E `tests/e2e/hardware-panel.spec.ts` (slice 07; fixture `E2eFixtureSeeder::seedPanelFixtures()`, idempotent like the other fixture methods): `E2E-PANEL-OLT` (custom 8 × 16 PON + 4 uplinks, 2U), `E2E-PANEL-ODC` (1:8 on PON 1), `E2E-PANEL-ODP` (1:8 on ODC output 1, under the E2E POP), subscription `E2E-PANEL-SUB` with account `e2e-panel@<realm>` and its drop on ODP output 1, `E2E-PANEL-RTR` (`mikrotik-ccr2004-16g-2s`, `sfp-sfpplus1` linked to OLT `UP1`), `E2E-PANEL-NEW` (router without model), `E2E-PANEL-RACK` (42U; OLT at U10, router at U13).

- `@smoke the 128-PON OLT panel meets the budget` — P11, P12, P13 numbers asserted and printed; P7 element counts.
- `arrows move between ports, Enter opens the port sheet, Escape returns focus`.
- `one tooltip follows hover and focus`.
- `link flow: router ether2 to OLT UP2, then unlink` (both panels show "Terpakai", then "Kosong"); refusal when the target is already linked.
- `the template picker gives an unmodelled router the hAP ac² panel`.
- `trace: OLT → ODC → ODP → customer path is lit` — CI (RADIUS off in `.env.e2e`): lit as "Terpakai" with "Status online tidak tersedia: RADIUS nonaktif."; lab describe (`E2E_RADIUS_LAB=1`, structure of `tests/e2e/radius-provisioning.spec.ts:848`): the drop shows "Online".
- `rack: the OLT and router sit at U10 and U13; an empty U opens the placement dialog`.
- `phone 390 px: the panel scrolls sideways, the legend stays visible, port buttons are at least 44 px`.
- `dark mode: an online port uses the dark --status-success` (computed colour `rgb(52, 211, 153)`), light mode `rgb(21, 128, 61)`.

## 8. Risks and open decisions

Risks:

- **Template drift when a device is replaced.** The snapshot keeps an asset's ports stable when a catalogue row changes. Swapping the hardware is "Ganti model": ports with the same (group, number) keep label, note and link because group keys are semantic (`ether`, `sfp`, `uplink`…); ports that disappear are deleted when free and refused by name when linked. Replacing the asset record itself (new serial) leaves links on the old record; soft delete is refused until they are unlinked.
- **PON numbering 0- vs 1-based.** `odn_ports.number` stays a 1-based ordinal; ZTE (`first` 1, slots 1–2) and Huawei (`first` 0, slots 0–1) names come only from the model; tooltip and sheet show both the vendor name and "PON n di ODN". A wrong `first`/`slot` in a catalogue row is a JSON fix plus sync.
- **Big OLTs (128 ports) on phones.** ≈ 880 px wide at 44 px cells: horizontal scroll, legend outside the scroller and sticky, slot titles at each row, arrow keys. Deferred: per-slot collapse and a "cari port" field.
- **Catalogue accuracy.** Odd-on-top rows for MikroTik rack units and OLT uplink names are assumptions (§3.8); data fix, not code.
- **Online freshness.** Up to 15 s stale, and RADIUS accounting can lag a Disconnect by a few seconds, so a port may show online briefly after a block. The isolir colour itself is immediate.
- **RADIUS is off in CI.** The online-lit E2E path runs only in the lab; the logic is covered by PHPUnit with the fake RADIUS DB.
- **Spec mismatch.** `Trace.tsx` has no ODN chain today; the chain is new code (R4).
- **Asset type changed after a model was applied.** The snapshot stays until "Ganti model" (the picker lists the new type's rows).
- **Rack numbering.** U1 at the bottom (EIA); racks numbered top-down read inverted.
- **Row size.** `network_assets.port_layout` adds 1–2 KB per row to `select *` on list pages (not to their payload).
- **CSS custom properties through `<use>`.** Inherited into the symbol tree in current Chromium, Firefox and Safari; without them a port still gets its colour (only hatch, cross and dash cues would be lost).
- **Cross-lane file.** `NetworkAssetController.php` is also edited by the integrity lane (`store()`); agreed order: integrity first, slice 03 after, keeping that line.

Open decisions (recommended default first; standing "ikuti saran" applies unless the owner objects):

- D1 Drop `subscription_id` from `asset_ports` (R1). Alternative: add it with a new assign flow for switch ports (scope growth).
- D2 VLAN in `note` (R2). Alternative: a `vlan` column with validation when a VLAN feature exists.
- D3 Two-step "Hubungkan" instead of drag (R3).
- D4 Online flags visible to `odn.view` holders (technicians included, who already see the customer on the port). Alternative: require `network_asset.account.view` (technicians lose them).
- D5 PON admin-down deferred (R6).
- D6 No auto-apply of models to existing assets; suggestion only (§3.9).
- D7 Racks numbered U1 at the bottom.
- D8 No Vitest (R7); adding it is a devDependency for the owner to approve.
- D9 Panel limit 256 ports, so no virtualisation code in phase 1.
- D10 "Trace loads per hop" = O(1) payload per hop, a hop's full panel only when opened (P15).
- D11 Lighthouse is not installed; Playwright timing marks are the measurement (P11–P13).

## 9. Work breakdown

Waves: **1** = 02 ∥ 03 (backend, contracts fixed in §3–4) → **2** = 04 ∥ 05 ∥ 06 (06 needs only wave 1; 04 depends only on the TS contract and may start early) → **2b** = 07 (after 04 and 05) → **3** = 08 ∥ 09.

Tickets for `.scratch/hardware-panel-ui/issues/`: 02-backend-panel-schema, 03-backend-panel-read, 04-frontend-panel-composite, 05-frontend-panel-pages, 06-qa-panel-php, 07-qa-panel-e2e, 08-docs-panel-guide, 09-security-review.

Every slice brief must load: `laravel-best-practices`, `inertia-react-development`, `tailwindcss-development`, `tdd`, `code-review`, `playwright-best-practices` (07), `shadcn`, `web-security-review` (09), `typescript-advanced-types`, `vercel-composition-patterns`, `codebase-design`, antislop family, `ui-ux-pro-max`, `ponytail`, `caveman`. No slice runs builds, tests or formatters; Main verifies once after each wave.

### 02 — backend — panel schema and write side (wave 1)

- Goal: catalogue, columns, `asset_ports`, layout rules, write service, routes.
- Owned files: `Modules/NetworkAsset/database/migrations/2026_10_24_110000_create_port_templates_table.php` (NEW), `…2026_10_24_110100_add_panel_columns_to_network_assets_table.php` (NEW), `…2026_10_24_110200_create_asset_ports_table.php` (NEW), `…2026_10_24_110300_seed_port_templates.php` (NEW), `Modules/NetworkAsset/database/data/port_templates.json` (NEW), `Modules/NetworkAsset/app/Models/PortTemplate.php` (NEW), `Modules/NetworkAsset/app/Models/AssetPort.php` (NEW), `Modules/NetworkAsset/app/Models/NetworkAsset.php`, `Modules/NetworkAsset/database/factories/AssetPortFactory.php` (NEW), `Modules/NetworkAsset/app/Support/PanelLayout.php` (NEW), `Modules/NetworkAsset/app/Support/PortTemplateCatalogue.php` (NEW), `Modules/NetworkAsset/app/Services/AssetPanelService.php` (NEW), `Modules/NetworkAsset/app/Http/Requests/ApplyPortTemplateRequest.php` (NEW), `…/UpdateAssetPortRequest.php` (NEW), `…/LinkAssetPortRequest.php` (NEW), `…/UpdateRackPlacementRequest.php` (NEW), `Modules/NetworkAsset/app/Http/Controllers/AssetPanelController.php` (NEW), `…/AssetPortController.php` (NEW), `…/AssetPortLinkController.php` (NEW), `…/RackPlacementController.php` (NEW), `Modules/NetworkAsset/routes/web.php`, `Modules/NetworkAsset/app/Policies/NetworkAssetPolicy.php`.
- Consumes: `OdnService::createPonPorts/createSplitter`, `AuditService::log`, `ResolvesPublicIds`, `PublicId::new()`, `CompanyService::currentId()`.
- Produces: §3 schema; `PanelLayout::{ASSET_TYPES, MAX_PORTS, rules, problems, assetPortRows, ponSlots, splitterBlocks, portName, portCount}`; `PortTemplateCatalogue::{sync, keys}`; `AssetPanelService` (§4.7); `NetworkAssetPolicy::updatePanel`; routes §4.6; `NetworkAsset::ports()`, `NetworkAsset::rack()`.
- Depends on: nothing.
- Acceptance: `./vendor/bin/sail artisan migrate` on the dev DB adds the 3 schema changes and 14 catalogue rows, a second run says "Nothing to migrate"; `./vendor/bin/sail artisan route:list --name=network-assets` lists the 5 routes; Pint and PHPStan clean on the files; P9 cap present.

### 03 — backend — panel read side and wiring (wave 1)

- Goal: one query, one resource, deferred props, online set, invalidation, TS contract.
- Owned files: `Modules/NetworkAsset/app/Queries/AssetPanelQuery.php` (NEW), `Modules/NetworkAsset/app/Http/Resources/AssetPanelResource.php` (NEW), `Modules/NetworkAsset/app/Support/AssetPanelProps.php` (NEW), `Modules/NetworkAsset/app/Queries/OdnOccupancyQuery.php`, `Modules/NetworkAsset/app/Services/Radius/RadiusDirectory.php`, `Modules/NetworkAsset/app/Jobs/ApplyNetworkAccountJob.php`, `Modules/NetworkAsset/app/Http/Controllers/NetworkAssetController.php` (show, trace, destroy only; after the integrity lane's `store()` edit is accepted), `Modules/NetworkAsset/app/Http/Controllers/OdnController.php`, `Modules/NetworkAsset/app/Http/Controllers/NasDeviceController.php`, `resources/js/types/hardware-panel.ts` (NEW).
- Consumes: slice 02's schema, models, `PanelLayout`, `AssetPanelService::assertDeletable`; `SearchOptions::for`, `HomeFeedCache` pattern, `NetworkAccountProvisioner::isolatedBecause` rule.
- Produces: §4.1 props, §4.2 query, §4.3 payload, §4.5 cache, §4.8 TS file (verbatim).
- Depends on: 02 (schema names only; runs in parallel).
- Acceptance: P1–P6, P15 tests pass (run by 06); EXPLAIN of Q1, Q2, Q3 and R1 on the dev DB pasted (P16); `npm run typecheck` passes with the TS file.

### 04 — frontend A — panel composite (wave 2)

- Goal: the drawing, keyboard, tooltip, legend, rack and trace renderers, lazy wrappers.
- Owned files: `resources/js/Components/composite/hardware-panel/HardwarePanel.tsx` (NEW), `…/PanelGlyphs.tsx` (NEW), `…/PanelTooltip.tsx` (NEW), `…/PanelLegend.tsx` (NEW), `…/RackPanel.tsx` (NEW), `…/TracePanel.tsx` (NEW), `…/HardwarePanelLazy.tsx` (NEW), `…/panelGeometry.helpers.ts` (NEW), `…/panelGeometry.helpers.test.mjs` (NEW), `resources/js/lib/statuses/network.ts`.
- Consumes: §4.8 types; tokens; `Skeleton` pattern of `ChartLazy`.
- Produces: §5.1 exports and props (frozen; slice 05 codes against them), `PORT_STATE`, perf marks `panel:props`, `panel:painted`, `panel:paint`, `panel:hover:start`, `panel:hover`, attributes `data-panel-ready`, `data-port`, `data-port-button`.
- Depends on: 03's TS file (contract fixed here, may start early).
- Acceptance: helper self-check passes; P7, P8, P10, P12–P14 hold; contrast table §5.3 respected in both themes; keyboard map §5.4.

### 05 — frontend B — page integration, PortSheet, link flow (wave 2)

- Goal: panels on the four pages, the sheet with existing actions, the picker, rack placement.
- Owned files: `resources/js/Components/composite/hardware-panel/AssetPanelCard.tsx` (NEW), `…/PortSheet.tsx` (NEW), `…/PanelTemplatePicker.tsx` (NEW), `…/RackPlacementDialog.tsx` (NEW), `resources/js/Pages/Admin/NetworkAssets/Show.tsx`, `resources/js/Pages/Admin/NetworkAssets/Trace.tsx`, `resources/js/Pages/Admin/Odn/Asset.tsx`, `resources/js/Pages/Admin/NasDevices/Show.tsx`.
- Consumes: §5.1 props (04), §4.1 props and §4.6 routes (02/03), `RemoteSearchSelect`, `SearchSelect`, `ConfirmRequestDialog`, `Sheet`, `Modal`, `usePermission`, `PORT_STATE`.
- Produces: §5.2 mounts and actions; copy §5.6.
- Depends on: 03 (props), 04 (props contract only; parallel).
- Acceptance: pages import only lazy wrappers (P10); every action hits the listed route and shows refusals inline; Escape returns focus; phone layout §5.5.

### 06 — qa — PHP tests (wave 2, after wave 1)

- Owned files: `tests/Traits/BuildsPanelFixtures.php` (NEW), `tests/Feature/NetworkAsset/AssetPanelTemplateTest.php` (NEW), `…/AssetPortLinkTest.php` (NEW), `…/RackPlacementTest.php` (NEW), `…/AssetPanelQueryTest.php` (NEW), `…/AssetPanelPagesTest.php` (NEW).
- Consumes: slices 02 and 03; `BuildsIpamOdnFixtures`, `BuildsNetworkFixtures`, `InteractsWithRadius`.
- Acceptance: §7 PHP table green on `inbils_test`; existing `tests/Feature/Odn`, `tests/Feature/NetworkAsset`, `IpamOdnLifecycleTest` still green.

### 07 — qa — E2E and performance (wave 2b, after 04 and 05)

- Owned files: `database/seeders/E2eFixtureSeeder.php`, `tests/e2e/hardware-panel.spec.ts` (NEW).
- Consumes: all of the above; the canonical runner and mutex.
- Acceptance: spec green on the canonical runner; P11, P12, P13 measured numbers in the report; lab describe skipped in CI.

### 08 — docs — guide and screenshots (wave 3)

- Owned files: `docs/user-guide/web.md` (section "Panel perangkat": pick a model, read colours, link ports, rack, fibre path), `docs/user-guide/pdf/web.pdf` (regenerated per `docs/user-guide/README.md`), `scripts/docs-screenshots.json` (entries for the OLT panel, phone view, router with PortSheet, rack, trace; re-shoot `network-assets-show`, `nas-devices-show`, `odn-asset`), `docs/user-guide/images/web/panel-*.png` (NEW, generated), `docs/business/network.md` (section on the port register, catalogue, naming rule, rack), `CHANGELOG.md` (`### Added - Panel perangkat …`), `.scratch/hardware-panel-ui/spec.md` (Status), `docs/plans/INBILS_OMP_BACKLOG.md` (lane row status).
- Depends on: 07 (screenshot data comes from its fixture).
- Acceptance: screenshots regenerated through `node scripts/docs-screenshots.mjs`; guide copy matches §5.6; changelog lists schema, routes, permissions (none new), deploy step `php artisan migrate --force`, non-goals.

### 09 — security-reviewer — review (wave 3, read only)

- Checks: company scoping of every new query and cache key; scoped bindings; `ResolvesPublicIds` + `exists` with `company_id`; raw SQL only with bindings (Q3, trace CTE); customer data only with `odn.view`; mass assignment (`linked_port_id`, panel columns not fillable); 422 messages leak no foreign data; `composer audit` and `npm audit` unchanged (no dependency added).
- Owned files: none (report only).

### File ownership matrix

| File | Slice |
|---|---|
| `Modules/NetworkAsset/database/migrations/2026_10_24_110000_create_port_templates_table.php` | 02 |
| `Modules/NetworkAsset/database/migrations/2026_10_24_110100_add_panel_columns_to_network_assets_table.php` | 02 |
| `Modules/NetworkAsset/database/migrations/2026_10_24_110200_create_asset_ports_table.php` | 02 |
| `Modules/NetworkAsset/database/migrations/2026_10_24_110300_seed_port_templates.php` | 02 |
| `Modules/NetworkAsset/database/data/port_templates.json` | 02 |
| `Modules/NetworkAsset/app/Models/PortTemplate.php` | 02 |
| `Modules/NetworkAsset/app/Models/AssetPort.php` | 02 |
| `Modules/NetworkAsset/app/Models/NetworkAsset.php` | 02 |
| `Modules/NetworkAsset/database/factories/AssetPortFactory.php` | 02 |
| `Modules/NetworkAsset/app/Support/PanelLayout.php` | 02 |
| `Modules/NetworkAsset/app/Support/PortTemplateCatalogue.php` | 02 |
| `Modules/NetworkAsset/app/Services/AssetPanelService.php` | 02 |
| `Modules/NetworkAsset/app/Http/Requests/ApplyPortTemplateRequest.php` | 02 |
| `Modules/NetworkAsset/app/Http/Requests/UpdateAssetPortRequest.php` | 02 |
| `Modules/NetworkAsset/app/Http/Requests/LinkAssetPortRequest.php` | 02 |
| `Modules/NetworkAsset/app/Http/Requests/UpdateRackPlacementRequest.php` | 02 |
| `Modules/NetworkAsset/app/Http/Controllers/AssetPanelController.php` | 02 |
| `Modules/NetworkAsset/app/Http/Controllers/AssetPortController.php` | 02 |
| `Modules/NetworkAsset/app/Http/Controllers/AssetPortLinkController.php` | 02 |
| `Modules/NetworkAsset/app/Http/Controllers/RackPlacementController.php` | 02 |
| `Modules/NetworkAsset/routes/web.php` | 02 |
| `Modules/NetworkAsset/app/Policies/NetworkAssetPolicy.php` | 02 |
| `Modules/NetworkAsset/app/Queries/AssetPanelQuery.php` | 03 |
| `Modules/NetworkAsset/app/Http/Resources/AssetPanelResource.php` | 03 |
| `Modules/NetworkAsset/app/Support/AssetPanelProps.php` | 03 |
| `Modules/NetworkAsset/app/Queries/OdnOccupancyQuery.php` | 03 |
| `Modules/NetworkAsset/app/Services/Radius/RadiusDirectory.php` | 03 |
| `Modules/NetworkAsset/app/Jobs/ApplyNetworkAccountJob.php` | 03 |
| `Modules/NetworkAsset/app/Http/Controllers/NetworkAssetController.php` | 03 (after the integrity lane) |
| `Modules/NetworkAsset/app/Http/Controllers/OdnController.php` | 03 |
| `Modules/NetworkAsset/app/Http/Controllers/NasDeviceController.php` | 03 |
| `resources/js/types/hardware-panel.ts` | 03 |
| `resources/js/Components/composite/hardware-panel/HardwarePanel.tsx` | 04 |
| `resources/js/Components/composite/hardware-panel/PanelGlyphs.tsx` | 04 |
| `resources/js/Components/composite/hardware-panel/PanelTooltip.tsx` | 04 |
| `resources/js/Components/composite/hardware-panel/PanelLegend.tsx` | 04 |
| `resources/js/Components/composite/hardware-panel/RackPanel.tsx` | 04 |
| `resources/js/Components/composite/hardware-panel/TracePanel.tsx` | 04 |
| `resources/js/Components/composite/hardware-panel/HardwarePanelLazy.tsx` | 04 |
| `resources/js/Components/composite/hardware-panel/panelGeometry.helpers.ts` | 04 |
| `resources/js/Components/composite/hardware-panel/panelGeometry.helpers.test.mjs` | 04 |
| `resources/js/lib/statuses/network.ts` | 04 |
| `resources/js/Components/composite/hardware-panel/AssetPanelCard.tsx` | 05 |
| `resources/js/Components/composite/hardware-panel/PortSheet.tsx` | 05 |
| `resources/js/Components/composite/hardware-panel/PanelTemplatePicker.tsx` | 05 |
| `resources/js/Components/composite/hardware-panel/RackPlacementDialog.tsx` | 05 |
| `resources/js/Pages/Admin/NetworkAssets/Show.tsx` | 05 |
| `resources/js/Pages/Admin/NetworkAssets/Trace.tsx` | 05 |
| `resources/js/Pages/Admin/Odn/Asset.tsx` | 05 |
| `resources/js/Pages/Admin/NasDevices/Show.tsx` | 05 |
| `tests/Traits/BuildsPanelFixtures.php` | 06 |
| `tests/Feature/NetworkAsset/AssetPanelTemplateTest.php` | 06 |
| `tests/Feature/NetworkAsset/AssetPortLinkTest.php` | 06 |
| `tests/Feature/NetworkAsset/RackPlacementTest.php` | 06 |
| `tests/Feature/NetworkAsset/AssetPanelQueryTest.php` | 06 |
| `tests/Feature/NetworkAsset/AssetPanelPagesTest.php` | 06 |
| `database/seeders/E2eFixtureSeeder.php` | 07 |
| `tests/e2e/hardware-panel.spec.ts` | 07 |
| `docs/user-guide/web.md` | 08 |
| `docs/user-guide/pdf/web.pdf` | 08 |
| `scripts/docs-screenshots.json` | 08 |
| `docs/user-guide/images/web/panel-*.png` | 08 |
| `docs/business/network.md` | 08 |
| `CHANGELOG.md` | 08 |
| `.scratch/hardware-panel-ui/spec.md` | 08 |
| `docs/plans/INBILS_OMP_BACKLOG.md` | 08 (row created by Main before wave 1) |

66 rows, each path once. Not edited by any slice: `OdnService.php`, `NasDeviceService.php`, `NetworkAccountService.php`, `NetworkAccountProvisioner.php`, `app/Providers/AppServiceProvider.php` (policy already registered), `RolePermissionSeeder.php` (no new permission), `resources/css/app.css` (state colours are Tailwind arbitrary values on existing tokens), `package.json`, `composer.json`.
