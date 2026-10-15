# MODULE: Inventaris

Status: done
Role: fullstack
Blocked by: 01, 02, 03
Spec: `../spec.md`

## Scope (owned files)

Pages `Admin/Inventory/**` (Requests incl. returns tab, Products, Categories, Units, Stocks, Find, Movements); `Modules/Inventory/**` web controllers/requests/notifications (after ticket 01 lands on StockController filters).

Specifics: request needed date → `DatePicker`, source SPK/ticket → `RemoteSearchSelect` by `source_type`; request item product, Stocks product/location (filter, receive, adjust) → `RemoteSearchSelect`; category parent → `SearchSelect`; product category → `SearchSelect`; Movements product/location filters rendered; Requests list "Tanggal Dibutuhkan" range filter (Q11); movement type, adjustment request status → `EnumBadge`.
Defects (R7): product detail "Recent Movements" always empty; deleting a unit does not check categories; Cari Barang subtitle vs query. Not in scope: Stok issue/transfer/break-glass screens (parked, `docs/PARKING_LOT.md`).

E2E: `inventory-lifecycle`, `stock-asset`, `datatable-c`, `reporting-stock-card` (filters only if shared), `inventory-*`.

## Per-page checklist (apply to every owned page)

1. Dates → `DatePicker` / `DateTimePicker` / `MonthPicker` / `TimeInput` (spec Q1, R2); requests use `date_format` (R8).
2. Every reference to another record → `SearchSelect` (small catalogue) or `RemoteSearchSelect` + `SearchOptions` (large table) per Q3/R4; no typed ids; `Rule::exists(...)->where('company_id', …)` on the request.
3. Every user-visible string Indonesian per Q4 and the glossary R6: page title/subtitle, labels, placeholders, buttons, column headers, empty states, dialogs, flash messages in the owned controllers, FormRequest `messages()`/inline `$fail`, notification subjects/bodies, export headings.
4. Every status/type/priority value → `EnumBadge` with the domain map from `lib/statuses/*` (R5), also in filter options; delete local status maps.
5. Update the E2E specs listed below to the new text and controls; add assertions where a control changed (picker instead of input).
6. Fix the listed defects (R7) and add a regression test for each.

## Antislop findings (A1)

ST1 quantities via `formatNumber` everywhere; pending-adjustments panel without card-in-card.

## Acceptance criteria

- [x] Checklist done for every owned page; `tsc`, ESLint, copy selfcheck (no denylisted English in owned files) clean; focused PHPUnit green; owned E2E specs type-check (orchestrator runs them).

## Comments

### ModInventaris report (2026-10-14)

**Done**
- Pickers (R4): new `Modules/Inventory/app/Support/InventoryOptions.php` (`productOptions`, `locationOptions`, `selectedProduct`, `selectedLocation`; label `SKU - nama (satuan)` / `kode - nama`, description category / path) and `InventoryRequestSources.php` (`workOrderOptions`, `ticketOptions`, only what the person may open: `spk.view`/`ticket.view`, technician or handler without assign sees own assignments). Stocks and Movements stopped sending the unbounded `products`/`locations`; Stocks filter + Terima Barang + Ajukan Penyesuaian, Movements filters, Requests Create items (stable row `uid`) use `RemoteSearchSelect`; Requests Create warehouse, Categories parent + unit, Products category (filter, create, edit) use `SearchSelect`.
- Dates (R2/R8): Requests Create `Tanggal Dibutuhkan` is a `DatePicker`, `needed_at` is `date_format:Y-m-d` with an Indonesian message; the resource now sends `needed_at` as `Y-m-d` (it is a day, so no time zone shift). Requests list has the Q11 `Tanggal Dibutuhkan` `DateRangePicker` (`needed_from`/`needed_to`).
- Badges (R5): `EnumBadge` with `INVENTORY_REQUEST_STATUS`, `MATERIAL_RETURN_STATUS`, `STOCK_MOVEMENT_TYPE`, `STOCK_ADJUSTMENT_STATUS` (new Status column), `ACTIVE_STATE`, `INVENTORY_REQUEST_SOURCE` label; filter options through `optionsOf`; `Requests/constants.ts` and `status_label` (resource, type) deleted; `lib/statuses/inventory.ts` unchanged.
- Copy (Q4/R6): all 12 pages, controller flash/errors, export headings (CSV/PDF title, movement type label, Ya/Tidak), `StoreInventoryRequestRequest` messages/`attributes()`, 5 notifications, `StockService`/`InventoryRequestService`/`InsufficientStockException` messages (also reach the technician API; tests that asserted the English text updated). Quantities through `formatNumber`, dates through `formatDate(x, company)`, empty cells `EmptyCell`, DataTable `hasFilters`/`onResetFilters`/`itemLabel`/`emptyAction`, row actions with name link, pending adjustments panel without card-in-card (A1 ST1).
- Defects (R7): product detail "Recent Movements" (`ProductResource` never sent `movements`; now `Mutasi Terbaru`, newest first by id); Unit delete now also refuses a unit used by a category; Cari Barang now matches its subtitle (query restricted to `type = consumable`, `docs/business/inventory.md` "search consumable"; before it also listed assets and said "consumable"). Regression tests for each.
- Tests added: `tests/Feature/Inventory/InventoryPickersTest.php` (15 tests: pickers, company scope, source visibility, date/source validation, category options, the 3 defects, export headings). Updated: `InventoryRequestWorkflowTest` (picker contract, new wording), `ErrorEnvelopeTest`, `RolePersonaSodTest`, `Modules/Inventory/tests/Feature/StockServiceTest`, `InventoryRequestWritesTest` (service message wording).
- E2E: `inventory-lifecycle.spec.ts` (ids through the pickers with a partial reload, picker receive, Cari mutasi, "tidak mencukupi", 2 new tests: request form pickers, movements filter), `loading-progress.spec.ts` (Item Finder button `Cari`).
- `resources/js/types/inventory.ts` (not named in the scope, used only by these pages) rewritten to the resource shapes; dead generic types removed.

**Verified**
- `npx tsc --noEmit` -> no error in `Pages/Admin/Inventory`, `types/inventory.ts` (errors that remain are other modules' files in flight). `./node_modules/.bin/eslint --max-warnings=0 resources/js/Pages/Admin/Inventory resources/js/types/inventory.ts` -> exit 0. `prettier --list-different` on the same + the spec -> empty after `--write`.
- `node --test resources/js/lib/*.selfcheck.mjs` -> 70 pass, 0 fail. `COPY_SCAN=all node --test resources/js/lib/copy.selfcheck.mjs | grep Pages/Admin/Inventory` -> no hit.
- `tsc --noEmit --target es2022 --module esnext --moduleResolution bundler --strict tests/e2e/inventory-lifecycle.spec.ts` -> no error.
- `vendor/bin/pint --format agent <touched PHP>` -> passed.
- `flock … sail exec … artisan test --compact tests/Feature/Inventory tests/Feature/Api/V1/Platform/ErrorEnvelopeTest.php tests/Feature/RolePersonaSodTest.php tests/Feature/Api/V1/Technician/{InventoryRequestWritesTest,MaterialReturnsTest,InventoryRequestsTest}.php Modules/Inventory/tests/Feature/StockServiceTest.php tests/Feature/Admin/{ApprovalInboxInventoryTest,DashboardExceptionCountsTest,PhaseDResourceSmokeTest}.php` -> 221 passed, 1 failed (`aset` wording assert, fixed); that test re-run -> 1 passed.

**Not verified**
- No E2E run, no rendered check or screenshot of any page (the :8080 stack serves the old build). Risk spots: `SearchSelect` inside the Terima Barang dialog, Requests Create row pickers, `DateRangePicker` filter `setMany`, mobile 375px of the Requests cards.
- Regression tests were written after the fix, so a red run was not observed.
- `MaterialReturnService` messages ("return case", "Invariant gagal ... issued (...) != used (...)", `awaiting_receipt` codes) and the English `Company context is required.` in `StockService` are still not Indonesian (not in the ticket's named scope; two other tests assert the latter).
- The notification mails link to `/admin/inventory/returns/{id}`, a JSON route (`MaterialReturnController::show`), not a page.
- Requests list/detail show the source as `SPK #id` (no code); the Approve dialog has no warehouse picker, so a request created without a warehouse cannot be approved from the web ("Lokasi gudang sumber wajib ditentukan").
- The 8-value request status filter stays a `NativeSelect` (Q3 allows 7); the 6-value return status filter too.

**needs-change**
- Shared specs for the post-wave fixer: `datatable-c` (Stocks filters `Location`/`Product` are now `SearchSelect` `Lokasi`/`Barang`; labels `Search` -> `Cari stok`/`Cari mutasi`/`Cari barang`/`Cari kategori`; headers `Quantity` -> `Jumlah`, `Qty` -> `Jumlah`, `Name` -> `Nama`; Movements type is `Jenis`, applies at once, no `Filter` button; Products `Category` -> `Kategori` (SearchSelect), `Active` -> `Status` (`Aktif`/`Nonaktif`); Requests/Returns filters unchanged), `stock-asset` (dialog `Produk` -> `Barang` SearchSelect: pick with `selectSearchOption`, not `selectFirstRealOption`), `number-sequences` (Products create `Name` -> `Nama`).
- `reporting-stock-card.spec.ts` (08, told): `/admin/stocks` no longer sends `locations`; read locations from `stocks.data[].location`.
- Frozen: `docs/user-guide/web.md` caveats for the three defects and the new labels (ticket 10); SPK/Ticketing could own a `visibleTo($user)` scope so the source pickers and `WorkOrderController`/`TicketController` share one rule (Inventory imports `Modules\SPK` and `Modules\Ticketing` models, as `StoreInventoryRequestRequest` already did).

**Proposed CHANGELOG bullets**
- Inventaris: barang, lokasi, SPK dan tiket dipilih dari daftar (pencarian di server) di Stok, Mutasi Stok dan Permintaan Barang; tanggal dibutuhkan memakai pemilih tanggal dan filter rentang; semua teks, ekspor, notifikasi dan status memakai Bahasa Indonesia dan lencana status.
- Perbaikan: Mutasi Terbaru di detail barang tampil, satuan yang masih dipakai kategori tidak bisa dihapus, Cari Barang hanya menampilkan barang habis pakai sesuai subjudulnya.

### Follow-up ModInventaris (2026-10-14)

- `MaterialReturnService`: seluruh pesan yang terlihat pengguna sekarang Bahasa Indonesia (kasus pengembalian, konflik idempotensi, aset berserial, ketidaksesuaian jumlah, penerimaan, penolakan dan penyelesaian selisih). `Company context is required.` sengaja tetap.
- Email `MaterialReturn{Declared,Exception,Rejected}Notification` tidak lagi membuka rute JSON `/admin/inventory/returns/{id}`: tautan membuka detail Permintaan Barang yang menampilkan pengembalian; kasus lama tanpa `inventory_request_id` membuka tab `?tab=returns`. Payload notifikasi sekarang menyimpan `inventory_request_id`; `MaterialReturnNotificationLinkTest` memastikan setiap URL merender halaman Inertia.
- `Pages/Admin/Inventory/**/*.tsx` ditambahkan ke `CONVERTED` dalam `resources/js/lib/copy.selfcheck.mjs`.
- Keputusan Main: dialog Setujui belum punya pemilih gudang, sehingga permintaan tanpa gudang tidak dapat disetujui dari web. Celah ini diparkir, bukan diperbaiki dalam follow-up ini.
