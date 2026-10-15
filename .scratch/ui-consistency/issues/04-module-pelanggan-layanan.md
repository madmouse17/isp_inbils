# MODULE: Pelanggan & Layanan

Status: done
Role: fullstack
Blocked by: 02, 03
Spec: `../spec.md`

## Scope (owned files)

Pages `Admin/Customers/**`, `Admin/CustomerAddresses/**`, `Admin/CustomerContacts/**`, `Admin/Subscriptions/**`, `Admin/Service/**` (packages, bandwidth, speed, SLA tiers: language only, R9), composites `CustomerRelatedTables`, `CustomerDataFields`, `CustomerAddressEditor`, `CustomerContactEditor`; controllers/requests for Customer, CustomerAddress, CustomerContact, Subscription, `Modules/Service/**`; `CustomerAccountCreated` mail.

Specifics: Customers/Create package + POP → pickers; Subscriptions package/address → `SearchSelect`; customer type Individual/Company → Perorangan/Perusahaan badge; typo "Instalation".

E2E: `customer-*`, `datatable-customers`, `customer-subscription`, `customer-sales-attribution`, `whatsapp-notifications` (customer parts), `kanban-t_49387795`.

## Per-page checklist (apply to every owned page)

1. Dates → `DatePicker` / `DateTimePicker` / `MonthPicker` / `TimeInput` (spec Q1, R2); requests use `date_format` (R8).
2. Every reference to another record → `SearchSelect` (small catalogue) or `RemoteSearchSelect` + `SearchOptions` (large table) per Q3/R4; no typed ids; `Rule::exists(...)->where('company_id', …)` on the request.
3. Every user-visible string Indonesian per Q4 and the glossary R6: page title/subtitle, labels, placeholders, buttons, column headers, empty states, dialogs, flash messages in the owned controllers, FormRequest `messages()`/inline `$fail`, notification subjects/bodies, export headings.
4. Every status/type/priority value → `EnumBadge` with the domain map from `lib/statuses/*` (R5), also in filter options; delete local status maps.
5. Update the E2E specs listed below to the new text and controls; add assertions where a control changed (picker instead of input).
6. Fix the listed defects (R7) and add a regression test for each.

## Antislop findings (A1)

PL1 filter grid wraps (no overflow at 1280/1366); PL2 merge or drop the duplicate Account column, one container; PL3 mobile card list + filter sheet below `md`, name is the link, `whitespace-nowrap` on codes; PL4 map picker buttons use `primary`.

## Acceptance criteria

- [ ] Checklist done for every owned page; `tsc`, ESLint, copy selfcheck (no denylisted English in owned files) clean; focused PHPUnit green; owned E2E specs type-check (orchestrator runs them).

## Comments

Carried from ticket 02 Part B (2026-10-14): migrate this module's Radix `Select` filters to `NativeSelect` (or `SearchSelect` for DB lists) per G22; `Select.selfcheck.mjs` and E2E `combobox` lookups change with them. Delete `ui/Select.tsx` in the last of 04/05/06/08 to land.

### ModPelanggan report (2026-10-14)

**Done**
- Pages (all in Indonesian, `EnumBadge` for type/status/active, `DataTable` `hasFilters`/`onResetFilters`/`itemLabel`/empty states, name = link, `-` via `EmptyCell`): `Customers/{Index,Show,Create,Edit}`, `CustomerAddresses/Index`, `CustomerContacts/Index`, `Subscriptions/{Index,Show}`, `Service/{Packages(Index,Show,Create,Edit),PackageCategories,BandwidthProfiles,SpeedProfiles,SLATiers}`; composites `CustomerRelatedTables`, `CustomerDataFields`, `CustomerAddressEditor`, `CustomerContactEditor`.
- Pickers: Customers/Create package and `POP pelayan` = `SearchSelect` (typo "Instalation" gone); Subscriptions package filter, package and installation address = `SearchSelect`; Customers/Index acquirer filter = `SearchSelect`; package form category/bandwidth/speed/SLA = `SearchSelect`. No remote search needed (all small catalogues, no typed ids, no dates in this module). Radix `Select` (3 filters in Packages, 1 in PackageCategories) replaced by `NativeSelect`/`SearchSelect`; `lib/customerStatus.tsx` and the dead `lib/status.ts` deleted; WhatsApp status/skip-reason maps on Customers/Show come from `lib/statuses`.
- Customer type Individual/Company = Perorangan/Perusahaan badge (`CUSTOMER_TYPE`); new `lib/statuses/service.ts` (`BANDWIDTH_TYPE`) + one barrel line (Main told).
- A1: PL1 filter row is `flex-wrap` (no fixed grid); PL2 Account column merged into Status (a muted "Akun nonaktif" badge, filter "Akun" kept) and one container (no card-in-card); PL3 Customers/Index below `md` = card list (name is the link, `whitespace-nowrap` codes) + filter sheet; filters apply on change (no "Filter" button). PL4: see needs-change.
- Backend: flash, export headings/cells/PDF titles, `withErrors`, `after()` messages, `$fail`, `abort` messages in `CustomerController`, `CustomerAddressController`, `CustomerContactController`, `SubscriptionController`, `SubscriptionService`, `Store/UpdateCustomerRequest` (`attributes()` tipe pelanggan), `StoreCustomerAddressRequest`, `StoreSubscriptionRequest`, `IsEligibleCustomerAcquirer`, `CustomerAcquirerQuery::idRule` (`validation.integer`), `Modules/Service` controllers + 6 requests with `attributes()`, onboarding SPK title/description (`Pemasangan ...`). `CustomerAccountCreated` was already Indonesian. `CustomerController::create` and `SubscriptionController::show` use `OdnOccupancyQuery::dropPortOptions` (ModJaringan contract); `packages` prop dropped from the subscription index (unused after the `SearchSelect` move).
- Incidental defects fixed: editing a dedicated bandwidth profile sent `contention_ratio: "null"` (now blank, required only for shared); package form blank `contract_min_months` failed the integer rule (default 0); CustomerAddresses pagination read the wrong props shape (never showed); stale "ONT release will be available in Phase 3" note removed from Subscriptions/Show.
- E2E updated (type-check only): `customer-edit`, `customer-sales-attribution`, `customer-subscription`, `package-categories` (customer/package parts), `sla-targets` (heading). PHPUnit: `CustomerSalesAttributionVisibilityTest` export headings (`Diperoleh oleh`, `Belum ditentukan`, `Kode`, `Perorangan`).

**Verified** (repo root)
- `npx tsc --noEmit` -> no error in any owned file (the other errors in the run belong to files other agents are still editing). `eslint --max-warnings=0` on the 27 owned TS files -> clean. `prettier --write` -> clean. `node --test resources/js/lib/statuses.selfcheck.mjs` -> 13 pass. `COPY_SCAN=all node --test resources/js/lib/copy.selfcheck.mjs` -> 0 findings in the owned files.
- `flock ... sail exec -T -u sail laravel.test ... artisan test --compact` on `CustomerSalesAttribution*`, `CustomerAccountCreation`, `CustomerPage`, `Customer{Address,Contact}Authorize`, `SubscriptionBlock`, `SubscriptionService`, `Admin/{Customer,CustomerContact,Subscription}ServerTableExport`, `Service/*`, `PhaseDResourceSmoke`, `P0CriticalPath`, `WhatsApp/WhatsAppAccessLinkNotice` -> `Tests: 189 passed (1845 assertions)`. `pint` on the touched PHP -> passed.
- `tsc` (CLI files, ES2022/bundler) on the 5 edited specs -> no error.

**Not verified**
- No E2E run, no browser check: the mobile card list and filter sheet, the 1280/1366 filter wrap, light/dark and the new `SearchSelect` flows in the specs are untested. The `customer-sales-attribution` Index test now skips the filter below 768px (cards) and uses `chooseAcquirer` for the filter.
- No new PHPUnit tests: no R7 defect belongs to this module; the new Indonesian strings are asserted only through the export test above.

**needs-change**
- Shared/other specs (post-wave fixer), new text for my pages: Create heading `Buat Pelanggan`, tabs `Data pelanggan` / `Alamat (n)` / `Kontak (n)` / `Langganan & SPK`, labels `Nama`, `Telepon`, `Alamat *`, `Jabatan`, `Tipe`, `Catatan`, comboboxes `Paket layanan` and `POP pelayan` (was a native select), submit `Buat pelanggan dan SPK`; Edit heading `Ubah Pelanggan`, button `Simpan pelanggan`, `Kembali`/`Batal`, `Tampilan baca saja`; Index: search label `Cari`, no `Filter` button, `Diperoleh oleh` is a combobox, create is a link `Buat Pelanggan`, row `Ubah` + `Aksi lainnya` (`Lihat detail`, `Hapus`); badges `Prospek`/`Aktif`/`Ditangguhkan`/`Berhenti`/`Menunggu`; Show: `Diperoleh oleh` is a `dt`. Affected: `datatable-customers`, `whatsapp-notifications`, `kanban-t_49387795`, `ipam-odn`, `number-sequences`, `map-default`, `radius-provisioning` (block flash now `Internet langganan diblokir.` / `Blokir internet langganan dibuka.`), `datatable-ab`. `tests/e2e/support/demo.ts` `createCustomer` is unused and still uses English selectors.
- `app/Queries/Admin/CustomerAcquirerQuery.php` `ensureUserDeletable` message is still English (user delete flow, ticket 09).
- Delete `ui/Select.tsx`, `Select.selfcheck.mjs` and the `Select*` exports in `ui/index.ts` in the last of 05/06/08 to land: my module no longer imports it, `Tickets/Index.tsx` and `SPK/Index.tsx` (05) still do.

**Follow-up (2026-10-14)**
- Security/TDD: added `SubscriptionInstallationAddressTest` (10 cases). Initial RED was 6 failed / 2 passed: update accepted same-company other-customer and deleted addresses; Store accepted deleted addresses; other foreign cases had inconsistent default/old messages. Security-review follow-up RED also reproduced explicit-null DB failure and cross-tenant lock-state disclosure. `StoreSubscriptionRequest` and `UpdateSubscriptionRequest` now require an integer address that exists under the effective company, own/chosen customer and `deleted_at IS NULL`, with one non-disclosing Indonesian message: `Pilih salah satu alamat milik pelanggan ini.` Update uses `sometimes|required` (omission preserves address, explicit null is 422) and returns 404 before any foreign subscription workflow query.
- PL4: `MapPicker` keeps each `Cari` button as the sole primary action in its mode. The `Alamat` / `Lintang / Bujur` mode buttons use selected `outline` vs unselected `ghost`, and `Lokasi saat ini` remains `outline`; three simultaneous primary controls in the same compact map would overuse the accent and obscure the main action.
- Copy guard: module page/component globs added to `CONVERTED`; `node --test resources/js/lib/copy.selfcheck.mjs resources/js/lib/*.selfcheck.mjs` -> `74 pass, 0 fail`.
- Verification: `npx tsc --noEmit` -> 0 errors; `eslint --max-warnings=0 MapPicker.tsx` + Prettier check -> clean. Focused tests (`SubscriptionInstallationAddressTest`, `P0CriticalPathTest`, `TenantBoundaryTest`, `SubscriptionServiceTest`, `NetworkAccessGapTest`) -> `43 passed (499 assertions)`; expected RADIUS failure-path log messages appeared in `NetworkAccessGapTest` but the suite passed. Pint passed.

**Proposed CHANGELOG bullet**
- Pelanggan & Layanan: halaman pelanggan, alamat, kontak, langganan, paket, kategori, profil bandwidth/kecepatan dan tingkat SLA berbahasa Indonesia; paket, POP, alamat pemasangan dan pemeroleh dipilih lewat kolom cari; daftar pelanggan menjadi kartu dengan lembar filter di layar kecil; label tipe/status dari satu peta status; ekspor CSV/PDF, pesan sukses/galat dan judul SPK pemasangan otomatis berbahasa Indonesia.
