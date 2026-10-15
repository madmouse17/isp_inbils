# MODULE: Jaringan (Lokasi, Aset Jaringan, Router NAS, Alamat IP, ODN, isolir)

Status: done
Role: fullstack
Blocked by: 02, 03
Spec: `../spec.md`

## Scope (owned files)

Pages `Admin/Locations/**`, `Admin/NetworkAssets/**`, `Admin/NasDevices/**`, `Admin/NetworkAccounts/**`, `Admin/Ipam/**`, `Admin/Odn/**`, `Isolir/**`, composites `locations/*`, `ipam/*`, `odn/*`, `NetworkAccessBadges`; `Modules/NetworkAsset/**`, `Modules/Ipam/**` web controllers/requests; `Store/UpdateLocationRequest` messages.

Specifics: asset dates → `DatePicker`; NAS router asset, ODP drop port, upstream port → `RemoteSearchSelect`; POP/VLAN/pool → `SearchSelect`; location parent → `SearchSelect`; asset type/ownership/status, ODN port, IP status → `EnumBadge`; subtitle "kode · status" → badge.
Defects (R7): Lokasi "Tabel" crash for admin (`row.children.length` on paginated rows).

E2E: `ipam-odn`, `radius-provisioning`, `stock-asset` (asset parts), `locations*`, `map-default`.

## Per-page checklist (apply to every owned page)

1. Dates → `DatePicker` / `DateTimePicker` / `MonthPicker` / `TimeInput` (spec Q1, R2); requests use `date_format` (R8).
2. Every reference to another record → `SearchSelect` (small catalogue) or `RemoteSearchSelect` + `SearchOptions` (large table) per Q3/R4; no typed ids; `Rule::exists(...)->where('company_id', …)` on the request.
3. Every user-visible string Indonesian per Q4 and the glossary R6: page title/subtitle, labels, placeholders, buttons, column headers, empty states, dialogs, flash messages in the owned controllers, FormRequest `messages()`/inline `$fail`, notification subjects/bodies, export headings.
4. Every status/type/priority value → `EnumBadge` with the domain map from `lib/statuses/*` (R5), also in filter options; delete local status maps.
5. Update the E2E specs listed below to the new text and controls; add assertions where a control changed (picker instead of input).
6. Fix the listed defects (R7) and add a regression test for each.

## Antislop findings (A1)

LK1 Leaflet zoom control bottom-right (no overlap with search/chips); LK3 map colours from tokens/CSS variables, popup in theme with Indonesian text and ≥ 4.5:1 contrast, legend shows the marker shapes.

## Acceptance criteria

- [x] Checklist done for every owned page; `tsc`, ESLint, copy selfcheck (no denylisted English in owned files) clean; focused PHPUnit green; owned E2E specs type-check (orchestrator runs them).

## Comments

Carried from ticket 02 Part B (2026-10-14): migrate this module's Radix `Select` filters to `NativeSelect` (or `SearchSelect` for DB lists) per G22; `Select.selfcheck.mjs` and E2E `combobox` lookups change with them. Delete `ui/Select.tsx` in the last of 04/05/06/08 to land.

Carried from ticket 02 Part A: derive `NetworkAssetStatus` in `types/models.ts` from `lib/statuses`; delete duplicate status maps (ticket 02 Part A comment).

**Done** (ModJaringan)
- Lokasi: `Locations/Index.tsx` rewritten (Indonesian, type through `EnumBadge`/`LOCATION_TYPE`, parent is a `SearchSelect`, type/status/parent filters are `NativeSelect`s). The "Tabel" crash is fixed at its root: delete is disabled from the `parentIds` of the `tree` prop, not from `row.children`, which paginated rows never carry (R7). `LocationsMapView.tsx`: LK1 (zoom control bottom-right, clear of the search and chips) and LK3 (marker, draft and legend colours come from theme tokens, popups are built from DOM nodes with Indonesian text, the legend draws the marker shapes, theme classes for the tile layer and popup). `LocationShowSections.tsx` deleted (no importer). `LocationController`, `Core/LocationService` (message-only, new `TYPE_LABELS`), `Admin/Store|UpdateLocationRequest` (`messages()` and `attributes()`) are Indonesian.
- Aset Jaringan: Index, Create, Edit, Show and Trace rewritten. Product and location are `RemoteSearchSelect`s (`productOptions`/`locationOptions` are `SearchOptions` props, with the `selected` option on edit; Show asks for `locationOptions` for the install dialog). Purchase and warranty dates are `DatePicker`s, the requests use `date_format:Y-m-d` and the resource sends `toDateString()`. Type, status and ownership are `EnumBadge` and `NativeSelect` filters built from `lib/statuses/network` (the `typeOptions`/`statusOptions` props are gone). Lifecycle actions, flashes, export headings and the PDF title are Indonesian (exported values stay the raw codes).
- Router NAS: Index, Create, Edit, Show rewritten. The router asset is a `RemoteSearchSelect` over the search-only `routerAssetOptions`; the option description is the management IP and picking it fills the NAS address; `hasRouterAssets` drives the empty state; `NasDeviceController::unregisteredRouters()` is the one query behind both. Requests, flashes and warnings are Indonesian.
- Akun jaringan: `NetworkAccounts/Show.tsx` and `NetworkAccessBadges.tsx` rewritten (`serviceTypeLabel`, `SyncStatusBadge`, `DesiredStateBadge`, `OnlineBadge` deleted for `EnumBadge` with the `NETWORK_*` maps; `NasStatusBadge`, `BlockedBadge`, `SubscriptionBlockModal`, `desiredStateExplanation` kept). Service, controller and requests Indonesian. Both `CopyButton`s get `copiedLabel="Disalin"`.
- Alamat IP: Prefixes Index/Show, VLAN Index and the Prefix/Vlan/AssignIp/ReserveAddress/SubscriptionIp/PrefixUtilisation composites are Indonesian; POP, VLAN and pool are `SearchSelect`s; usage, family and address status are `EnumBadge` (the usage maps in `lib/ipam.ts` are deleted; `UtilisationBar` says "tidak diukur"). Controllers, `IpAssignmentService` (new private `holderPhrase`; `describeHolder` stays English for the CLI), `IpPrefixService`, `VlanService` and the requests (`attributes()`) are Indonesian.
- ODN: Index and Asset pages and the `odn/*` composites are Indonesian. `DropPortSelect` is a `RemoteSearchSelect` (field `dropPort`, no `loading` prop) over the new `OdnOccupancyQuery::dropPortOptions` (value = port id, label "ODP-A11 · 1:8 · port 1", description = the location path; 2+ characters, 20 results, company-scoped). ModPelanggan applied it in `CustomerController::create`, `SubscriptionController::show` and `Customers/Create.tsx`. The upstream port of a splitter is a `RemoteSearchSelect` over `inputPortOptions`. `freePortOptions`, `inputOptions` and the reload of the full list are gone. "out" is "keluaran". Splitter, PON, drop requests, `OdnService`, `OdnLock`, `IpamLock` are Indonesian.
- Isolir: `Isolir/Show.tsx` was already Indonesian; untouched.
- Types and lib: `types/ipam-odn.ts` and `types/network-asset.d.ts` derive their unions from the status maps; `types/models.ts` already derives `NetworkAssetStatus` (done by ModOperasional, no message needed). No Radix `Select` import is left in my files; `ui/Select.tsx` was already deleted by ticket 05. `copy.selfcheck.mjs`: my pages and composites joined `CONVERTED`.
- Tests: new `tests/Traits/AsksInertiaForProps.php` (`partialReload()`), `NetworkAsset/NetworkAssetFormsTest.php` (form props and validation) and `Odn/OdnMessagesTest.php`; `LocationContractTest` gained 3 tests (table rows carry no `children` while `tree` does: the Tabel defect; the store answers in Indonesian; service refusals and flashes in Indonesian); `OdnOccupancyTest`, `IpamOdnLifecycleTest`, `NasDeviceHttpTest`, `NetworkAccountHttpTest`, `IpAssignmentTest`, `IpAssetWriteThroughTest` follow the new props and messages. E2E: `ipam-odn`, `radius-provisioning` and `location-map-first` updated to the new text and controls; `location-map-first` has a new regression test for the Tabel tab (`@ui-consistency the Tabel tab ...`).

**Verified** (repo root)
- `npx tsc --noEmit`: 3 errors, all in `resources/js/types/index.ts` (30-32: `StockRow`, `MovementRow`, `Movement` missing from `./inventory`, the Inventaris lane in flight); none in my files.
- `./node_modules/.bin/eslint --max-warnings=0` on `Pages/Admin/{Locations,NetworkAssets,NasDevices,NetworkAccounts,Ipam,Odn}`, `composite/{locations,ipam,odn}`, `NetworkAccessBadges.tsx`, `UtilisationBar.tsx`, `lib/ipam.ts`, `types/ipam-odn.ts`, `types/network-asset.d.ts`: clean. The three E2E specs are outside the ESLint project ("not found by the project service"), as every spec is. `npx prettier --write` on the touched files: clean.
- `node --test resources/js/lib/*.selfcheck.mjs`: 70 pass, 0 fail (the copy selfcheck reads my pages and composites as Indonesian). `COPY_SCAN=all` lists 34 English strings in other lanes' files (for example `Evaluations/*`), none in mine.
- Specs type-check: `npx tsc --noEmit --skipLibCheck --target ES2023 --lib ES2024,DOM --module ESNext --moduleResolution bundler --strict --types node tests/e2e/{radius-provisioning,location-map-first,ipam-odn}.spec.ts`: no errors.
- PHPUnit (focused, the command of the brief) on `tests/Feature/Odn tests/Feature/Ipam tests/Feature/NetworkAsset tests/Feature/IpamOdnLifecycleTest.php tests/Feature/Admin/LocationContractTest.php`: 604 passed, 1 failed (`IpAssetWriteThroughTest::test_an_asset_cannot_claim_a_reserved_address` still asserted the English "reserved (gateway)"). Fixed; `tests/Feature/Ipam/IpAssetWriteThroughTest.php` rerun: 14 passed (62 assertions).
- `vendor/bin/pint --test` on `Modules/NetworkAsset`, `Modules/Ipam`, `LocationController`, `Core/LocationService`, both Location requests and the touched tests: `pint: ok`.
- `git grep` over `tests` and `Modules/*/tests` for every English phrase my backend diff removed: no hit.

**Not verified**
- No E2E run and no rendered browser check (the orchestrator runs the specs); no `npm run build`. The map (dark-mode tiles, popup contrast of at least 4.5:1, legend shapes, zoom control position) and every new picker were never seen on screen.
- The three specs were updated without a run: `location-map-first` (the parent is now a `SearchSelect`, so it asserts the displayed path; marker names are the Indonesian type words), `radius-provisioning` (the router asset is picked by typing `E2E-ROUTER` and choosing the option; the asset id for the lab step comes from the `assets` page props) and `ipam-odn`.
- Steps that depend on ModPelanggan pages kept as they were or assumed from their message: in `ipam-odn` the customer create tabs, POP select, Activate/Terminate dialogs, the "unavailable" text and the SPK "Drop port:" line; in `radius-provisioning` the block flashes ("Internet langganan diblokir.", "Blokir internet langganan dibuka."), the buttons ("Blokir internet", "Buka blokir", "Akses jaringan", "Tangguhkan", "Aktifkan kembali"), the Subscriptions/Show rows "Alasan blokir" and "Diblokir oleh", and the Receivables buttons ("Blokir CODE", "Buka blokir CODE").
- Left in English on purpose: the RADIUS diagnostics stored in `last_error` (`RadiusException`, tests assert them), the "Company context is required." 403 aborts, the CLI (`ImportAssetIpsCommand`, `SyncNetworkDevices`, `describeHolder`).

**needs-change**
- `resources/js/Components/composite/CopyButton.tsx`: defaults are English ("Copy", "Copied", "Copy failed", "Copy failed. Select the text and copy it by hand."). Pages pass `label`/`copiedLabel`; the failed state still reads English until the component gets Indonesian defaults or a `failedLabel`.
- `tests/e2e/loading-progress.spec.ts:380`: `submit: 'Trace'` must become `'Lacak'` (the page is "Lacak Aset").
- `tests/e2e/datatable-d.spec.ts:35-43` (shared): the "Aset Jaringan" case has `sortHeader: 'Code'` (now "Kode") and filter labels `Type` (now "Tipe", a `NativeSelect`; `Status` is also a `NativeSelect`), so selection is by option value.
- `tests/e2e/stock-asset.spec.ts` (asset parts), `tests/e2e/map-default.spec.ts` (shared): not edited. `map-default` does not touch my pages; `stock-asset` only searches the asset list and follows the customer and subscription links of the asset page, which still exist.
- Deploy note: `dropPortOptions`, `inputPortOptions`, `routerAssetOptions`, `productOptions` and `locationOptions` are search-only optional props now; any other page that read the old `freePortOptions`/`inputOptions` or sent a full list must use the same `<field>_search` contract.

**Proposed CHANGELOG bullet**: Jaringan: Lokasi, Aset Jaringan, Router NAS, Akun jaringan, Alamat IP and ODN use the shared status badges, date pickers, searchable pickers (product, location, router asset, drop port, upstream port) and Indonesian copy and messages; the Lokasi "Tabel" tab no longer crashes for users who may delete; the location map keeps its zoom control clear of the search and takes its colours from the theme; drop-port, router-asset, product and location choices are searched on the server instead of sent as full lists (`dropPortOptions`, `inputPortOptions`, `routerAssetOptions`, `productOptions` and `locationOptions` are optional props filled by `<field>_search`).

**Follow-up** (Main, ticket 06): `CopyButton.tsx` defaults are Indonesian ("Salin", "Tersalin", failed button "Gagal menyalin", status "Gagal menyalin, salin manual."); the two call sites (`NasDevices/Show`, `NetworkAccounts/Show`) keep only their own `label` ("Salin URL", "Salin kata sandi") and take the default `copiedLabel`; `radius-provisioning.spec.ts` now expects "Tersalin". `loading-progress.spec.ts:380` `submit: 'Trace'` is `'Lacak'` (that line only). `CopyButton.tsx` joined `CONVERTED` in `copy.selfcheck.mjs` (my earlier entries are still there). Checked: `npx tsc --noEmit` 0 errors; ESLint `--max-warnings=0` on `CopyButton.tsx`, `NasDevices/Show.tsx`, `NetworkAccounts/Show.tsx` clean; Prettier `--check` clean; `node --test resources/js/lib/*.selfcheck.mjs` 70 pass, 0 fail; `COPY_SCAN=all` has no hit in `CopyButton`, `NasDevices`, `NetworkAccounts`; the two specs type-check with `tsc --strict --lib ES2024`. The first `needs-change` above (`CopyButton`) is closed. Not run: E2E, browser.
