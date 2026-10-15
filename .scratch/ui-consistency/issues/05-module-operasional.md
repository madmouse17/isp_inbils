# MODULE: Operasional (Penugasan, Persetujuan, SPK, Tiket, Evaluasi, Pekerjaan Saya, Dasbor, Notifikasi)

Status: done
Role: fullstack
Blocked by: 01, 02, 03
Spec: `../spec.md`

## Scope (owned files)

Pages `Admin/Dispatch/**`, `Admin/Approvals/**`, `Admin/SPK/**`, `Admin/Tickets/**`, `Admin/Evaluations/**`, `Admin/MyWork/**`, `Admin/Dashboard/**`, `Admin/Notifications/**`; `Modules/SPK/**`, `Modules/Ticketing/**` web controllers/requests/notifications (`TicketAssignedNotification`, `TicketSlaBreachedNotification`), `app/Http/Controllers/Admin/{DispatchQueue,ApprovalInbox,MyWork,Dashboard,Evaluation,Notification}Controller.php` (after ticket 01 lands on DispatchQueue).

Specifics: SPK Create customer/subscription/location → `RemoteSearchSelect`, scheduled date → `DatePicker`; Tickets Create customer/subscription/asset → remote, location + category → `SearchSelect`; Ticket assign Handler ID → select from the existing `handlers` prop; Evaluations reference → picker per reference type, employee → `SearchSelect`; merge the three near-duplicate `getStatusLabel` utils into `lib/statuses`.
Defects (R7): Evaluasi list has no page buttons; priority "Kritis" matches no value.

E2E: `spk-lifecycle`, `ticket-lifecycle`, `dispatch-my-work`, `approval-*`, `dashboard-persona`, `notification-center`, `evaluation*`, `private-media`.

## Per-page checklist (apply to every owned page)

1. Dates → `DatePicker` / `DateTimePicker` / `MonthPicker` / `TimeInput` (spec Q1, R2); requests use `date_format` (R8).
2. Every reference to another record → `SearchSelect` (small catalogue) or `RemoteSearchSelect` + `SearchOptions` (large table) per Q3/R4; no typed ids; `Rule::exists(...)->where('company_id', …)` on the request.
3. Every user-visible string Indonesian per Q4 and the glossary R6: page title/subtitle, labels, placeholders, buttons, column headers, empty states, dialogs, flash messages in the owned controllers, FormRequest `messages()`/inline `$fail`, notification subjects/bodies, export headings.
4. Every status/type/priority value → `EnumBadge` with the domain map from `lib/statuses/*` (R5), also in filter options; delete local status maps.
5. Update the E2E specs listed below to the new text and controls; add assertions where a control changed (picker instead of input).
6. Fix the listed defects (R7) and add a regression test for each.

## Antislop findings (A1)

PE1 amber-700/red-700 (dark red-400) and ≥ 12px on Penugasan; PE2 summary counts as single-hue filter chips without icon tiles (Dispatch, Approvals, MyWork); PE3 filter cells wide enough; AP1 keep only real summary cards, shortcuts become tabs; AP2 fewer columns, no clipped actions at 1280; SP1 SPK items empty row with reason + "Tambah item", `<dl>` grid, status as badge; TK1 red only for breach; TK2 ticket dates via `formatDateTime`, localised empty check-in text; D1 dashboard counts link to their lists or go, humanised activity text, unread by weight.

## Acceptance criteria

- [x] Checklist done for every owned page; `tsc`, ESLint, copy selfcheck (no denylisted English in owned files) clean; focused PHPUnit green; owned E2E specs type-check (orchestrator runs them).

## Comments

Carried from ticket 02 Part B (2026-10-14): migrate this module's Radix `Select` filters to `NativeSelect` (or `SearchSelect` for DB lists) per G22; `Select.selfcheck.mjs` and E2E `combobox` lookups change with them. Delete `ui/Select.tsx` in the last of 04/05/06/08 to land.

Carried from ticket 02 Part A (2026-10-14): derive `TicketStatus`, `TicketPriority`, `SpkStatus` in `resources/js/types/models.ts` from `lib/statuses` maps (`keyof typeof`); delete this module's duplicate status maps listed in ticket 02 Part A comment.

### ModOperasional report (2026-10-14): partial, stopped at the request budget

**Not closed.** Dispatch, Approvals, My Work, SPK, Tiket, Dasbor and the backend contracts are done; Evaluasi Create/Show/Edit, the Evaluasi backend, the PHPUnit/E2E updates and the R7 regression tests are not.

**Done**
- `types/models.ts`: `TicketStatus`, `TicketPriority`, `SpkStatus`, `InvoiceStatus`, `NetworkAssetStatus` are `keyof typeof` the `lib/statuses` maps (ModKeuangan asked for `InvoiceStatus`). `lib/statuses/workItem.ts`: new `APPROVAL_TYPE`.
- Penugasan / Persetujuan / Pekerjaan Saya: status, priority, domain and type go through `EnumBadge`; the three `utils.ts` status/priority/domain functions are gone (`formatAge` lives once in `MyWork/components/utils.ts`); the dead `critical`/"Kritis" priority is gone (filters list `optionsOf(PRIORITY)`: the R7 defect); `SummaryCards` x3 replaced by `MyWork/components/SummaryChips.tsx` (PE2/AP1, the fake "Mendesak" and "Riwayat Kanonikal" cards are dropped); My Work columns are shared (`useMyWorkColumns.tsx`) by My Work and the Dispatch tab; Approvals table 9 to 6 columns, the code is the detail link; filter cells widened (PE3); amber-700 / `destructive-text`; assignee in the Assign dialog and the requester filter are `SearchSelect`; dates use `formatDate`/`formatDateTime` with the company format.
- SPK: Index, Create (`customer`/`subscription`/`location` are `RemoteSearchSelect`, `scheduled_date` is `DatePicker`, subscription narrows to the picked customer through `customer_id`), Show (`<dl>`, status badge, add-item with remote product and asset: `selectedProduct` is an optional prop asked with `product_id`), all Indonesian. Controller: `SearchOptions` props `customerOptions`/`subscriptionOptions`/`locationOptions` (create) and `productOptions`/`networkAssetOptions`/`selectedProduct` (show), flash and export headings, `typeOptions`/`statusOptions`/`technicians` removed from the index, request `date_format:Y-m-d`, Indonesian request/service/action messages.
- Tiket: Index, Create (customer/subscription/asset remote and narrowing, category and location `SearchSelect`), Show (`<dl>`, badges, breach badge is the only red, handler `SearchSelect` from the new `handlers` prop), all Indonesian; controller, `StoreTicketRequest`, `TicketService`, both notifications, export headings. The spawned SPK title is now "SPK dari tiket TKT-...".
- Check-in: `WorkOrderController::show` (`checkIn`) and `TicketResource` (`check_in`) now send `{status, distance_m, accuracy_m, reason}`; `Tickets/components/checkIn.ts` words it in Indonesian. `FieldCheckIn::summary()` is no longer called by the web.
- Dispatch assign: company-scoped `exists` rule on `assignee_id`/`handler_id`/`technician_id` (ticket 01's parity gap); a foreign id is now a 422 validation error, so `DispatchQueueTest::test_dispatch_assign_rejects_a_user_of_another_company_under_every_assignee_field` asserts `assertSessionHasErrors` instead of 404.
- Dasbor: humanised activity (`Dashboard/components/activityText.ts`), unread notification by weight (and an sr-only label), priorities from the map, company dates. Notifikasi page Indonesian. Evaluasi Index: Indonesian, `DataTable` + `toPagination(evaluations)` (the page buttons read `meta`, not the top level: the R7 defect), employee `SearchSelect`.
- `app/Rules/{IsActiveTechnician,HasAnyActiveRole,PolymorphicBelongsToCompany,MatchesParentAttribute}` messages are Indonesian (no ticket owned `app/Rules`; `BelongsToCompany`, `IsEligibleCustomerAcquirer` (touched by someone else), `NoSodConflict`, `NoStackedSodConflict` are still English).
- Radix `Select`: no page imports it any more, so `ui/Select.tsx`, `ui/Select.selfcheck.mjs` and the two barrel exports are deleted (carried instruction).

**Verified** (repo root)
- `npx tsc --noEmit`: 0 errors in my files; 3 errors in `types/index.ts` (`StockRow`, `MovementRow`, `Movement` missing from `types/inventory.ts`) come from the Inventaris lane.
- `eslint --max-warnings=0` on Dispatch, Approvals, MyWork, SPK, Tickets, Dashboard, Notifications, `Evaluations/Index.tsx`, `models.ts`, `workItem.ts`: clean. `prettier`: clean.
- `node --test` `statuses.selfcheck.mjs` and `AssignDialog.selfcheck.mjs`: pass. `COPY_SCAN=all` lists no English left in Dispatch, Approvals, MyWork, SPK, Tickets, Dashboard; it still lists `Evaluations/{Create,Edit,Show}.tsx`.
- `php -l` and `pint` on the touched PHP: clean.

**Not verified / not done**
- No E2E run and none edited: `spk-lifecycle`, `ticket-lifecycle`, `dispatch-my-work`, `dashboard-persona`, `notification-center`, `private-media` still carry English or old labels (buttons are now "Tugaskan", "Mulai", "Selesaikan", "Terbitkan", "Simpan", "Buat SPK", "Buat tiket", "Tandai semua dibaca", filter "Jenis" instead of "Domain", reset "Reset filter", the row "Detail" link is the code link). `ReviewSheet.selfcheck.mjs` (esbuild + Chromium) was updated for the new copy but not run.
- Evaluasi: `Create.tsx` still has typed `Reference ID` and English text; `Show`/`Edit` English; `EvaluationController` still lists employees of every company (`User` is exempt from the scope; join `company_user`), has English flash/export headings and no `SearchOptions` for the ticket/SPK reference; the Index shows `#id`, not the referenced code.
- Not done: `DashboardAdminStatsQuery::MODULES` names (Customer, Service, ...) and the English `abort` texts in `DashboardQuery`/`DashboardAdminStatsQuery`; `Tickets/Categories/Index.tsx` still uses `Badge` for active; the regression tests for the "Kritis" and Evaluasi pagination defects; `lang`-level tests for the new messages; CHANGELOG.
- `Admin/SPK/Edit` and `Admin/Tickets/Edit` are rendered by the controllers but do not exist as pages (dead before this change, left alone).

**needs-change**
- `app/Support/FieldCheckIn.php` + `tests/Unit/Support/FieldCheckInTest.php`: `summary()` has no web caller now; delete it with its cases.
- `app/Actions/SpawnSpkFromTicketAction.php`: unused duplicate of `TicketService::spawnSpk` with the English title; delete.
- `tests/e2e/kanban-t_49387795.spec.ts:212` expects "SPK from Ticket TKT-..." (now "SPK dari tiket ..."); `tests/e2e/loading-progress.spec.ts:617` expects "Attachment uploaded." (now "Lampiran berhasil diunggah.").

**Proposed CHANGELOG bullet**: Operasional: Penugasan, Persetujuan, Pekerjaan Saya, SPK, Tiket and Dasbor use the shared status badges, searchable pickers and Indonesian copy; SPK and tiket forms pick customers, subscriptions, assets, products and locations from the server instead of loading every row; assigning from Penugasan checks the assignee against the company; the Evaluasi list shows its page buttons.

### ModOperasional2 frontend part (2026-10-14): Evaluasi pages, Kategori Tiket, selfchecks, owned E2E specs

React and E2E only. The backend half (tenant leak test and scope, `SearchOptions` for employee and reference, Evaluasi flash and export copy, dashboard strings, rule messages, dead code) is with EvalBackend. Status left as it was.

**Done**
- `Pages/Admin/Evaluations/Index.tsx`: the employee filter is `RemoteSearchSelect field="employee"` (prop `employeeOptions`, search param `employee_search`, current value from `selectedEmployee`); the `employees` prop is no longer read; `useServerTable` `only` includes `selectedEmployee`; the reference column shows the code from `evaluations.referenceOptions` (`#id` only when the record is gone); the page buttons read `meta` through `toPagination` (R7).
- `Evaluations/Create.tsx`: Indonesian; employee is `RemoteSearchSelect field="employee"`; reference type is a `NativeSelect` over `optionsOf(EVALUATION_REFERENCE_TYPE)`; the reference is `RemoteSearchSelect field="ticket"` (`ticketOptions`, `ticket_search`) or `field="workOrder"` (`workOrderOptions`, `workOrder_search`) by type, and changing the type clears `reference_id`. No typed Reference ID. The form still posts `employee_id`, `reference_type` (`Ticket` or `WorkOrder`), `reference_id`, `score`, `customer_rating`, `comment`.
- `Evaluations/Show.tsx`: Indonesian `<dl>`, reference type as `EnumBadge`, reference `Kode` and `Judul` from the `referenceOption` page prop (it read `reference_code` and `reference_title` before, which nothing sends; EvalBackend confirmed `referenceOption` is the contract), `EmptyCell` for missing values, company-format dates, minutes through `formatNumber`. `Evaluations/Edit.tsx`: Indonesian, the employee is read-only text.
- `Tickets/Categories/Index.tsx`: the active state is `EnumBadge map={ACTIVE_STATE}`; no `Badge` is left in the file.
- `lib/pagination.selfcheck.mjs` (new, 2 tests): `toPagination` reads a resource collection from `meta` and a plain paginator from the top level. It pins the helper, not the page wiring; the server side stays pinned by `EvaluationServerTableExportTest` (`evaluations.meta.current_page`, `last_page`).
- `Approvals/components/ReviewSheet.selfcheck.mjs` (task item 9) repaired and green: the Inertia mocks lacked `usePage` (`useCompany` imports it), the `Link` mock rendered no `<a>`, the reload copy is sentence case now ("Muat ulang antrean", "Memuat ulang..."), the sheet title is found by text, and the SPK fixture carries `domain` and `composite_id`. Before the repair 3 of 5 tests failed.
- Owned E2E specs moved to the new text and controls (type-check only):
  - `spk-lifecycle`: Create labels `Jenis`, `Judul`, `Prioritas`, `Tanggal jadwal`, `Deskripsi`, button `Simpan`; customer, subscription and location go through `selectSearchOption` (remote pickers; the location is searched as the seeded "E2E ODP Site 01"); actions `Tambah item`, `Tugaskan`, `Mulai`, `Ajukan review`, `Setujui`, `Tolak` with their dialog titles; item form `Barang`, `Jumlah dicadangkan`, `Jumlah terpakai`, `Catatan`; evidence `Keterangan`, `Unggah`.
  - `ticket-lifecycle`: `Buat Tiket`; category and customer through `selectSearchOption`; `Simpan`; assign through the `Petugas` picker (the `resolveUserId` and typed Handler ID path is gone); `detailValue` reads `<dt>` and `<dd>`; `Tutup tiket`, `Buat SPK dari tiket`, title "SPK dari tiket".
  - `dispatch-my-work`: copy `Simpan penugasan`, `Muat ulang antrean`, `Memuat ulang...`, `Data berubah`, `Terjadi kesalahan`; the assignee is driven as the combobox it now is (`getByRole('combobox')`, `selectSearchOption`, `toHaveValue(/\S/)`), not as a native select (`getByLabel` would also hit the clear button).
  - `dashboard-persona`: card title "Tagihan jatuh tempo" (EvalBackend renamed the card in `DashboardQuery`; checked at `DashboardQuery.php:236`). `notification-center`: `Lihat detail`, `Tandai dibaca`, `Tandai semua dibaca`, `Notifikasi`; the unread marker is asserted as `/^Belum dibaca/` because the sr-only prefix ends with a colon, so an exact string would always count 0. `private-media`: ticket create and upload copy; `Open` and `Download` stay English until `MediaPreview` is translated.

**Verified** (repo root)
- `npx tsc --noEmit` → no errors. `eslint --max-warnings=0` on the four Evaluasi pages and Kategori Tiket → clean (the config ignores the `.mjs` selfchecks and does not parse `tests/e2e`). `prettier --check` on every touched file → clean.
- `node --test resources/js/lib/*.selfcheck.mjs` → 76 pass, 0 fail. `node --test resources/js/Pages/Admin/Approvals/components/ReviewSheet.selfcheck.mjs` → 5 pass, 0 fail (esbuild and Chromium).
- Owned specs: TypeScript language-server diagnostics → no issues in the six files; `npx playwright test --list` on them → exit 0, 26 tests. `tsc --noEmit` does not include `tests/e2e`.
- `npm run build` → ok (the usual chunk-size warning only); the built chunks hold the new Indonesian Evaluasi copy.
- Browser: `/admin/evaluations` only reached the login page.

**Not verified**
- No E2E run (wave rule). No authenticated render of Evaluasi (light and dark, 375px, keyboard): no login was used. The prop names were checked by reading `EvaluationController` (`employeeOptions`, `selectedEmployee`, `evaluations.referenceOptions` on Index; `employeeOptions`, `ticketOptions`, `workOrderOptions` on Create; `referenceOption` on Show), not by a request.
- The `selectSearchOption` calls on remote pickers (customer, subscription, location in a form; the assignee inside the dispatch dialog) rely on the SearchSelect DOM from ticket 02 (combobox, `role=option` in a popover); none was run.
- Show offers "Ubah" without a `can.update` prop (the controller sends none); the seeder gives manager and staff `evaluation.update`, and the technician (`evaluation.view.own` only) is app-only on the web.

**Props the pages read** (all present in `EvaluationController`): Index `employeeOptions`, `selectedEmployee`, `evaluations.referenceOptions` (keyed `Ticket:<id>` or `WorkOrder:<id>`, value `{code, title}`); Create `employeeOptions`, `ticketOptions`, `workOrderOptions`; Show `referenceOption` (`{code, title}` or null).

**needs-change**
- `tests/e2e/support/demo.ts`: `expectDetail` scans `<p>` elements and `expectSpkState` expects `SPK-... · <status code>`, but SPK and Tiket Show are now `<dl>` pages with a status badge. My specs keep the shared calls: `expectSpkState(page, '<stored status code>')` and `expectDetail(page, '<Indonesian label>', '<displayed value>')` (a badge label for statuses, for example `Ditutup`). The post-wave fixer should make the helpers match.
- `resources/js/Components/composite/MediaPreview.tsx`: "Open", "Download" and their aria-labels are English (shared, frozen); `private-media.spec.ts` asserts them as they are.

### EvalBackend backend part (2026-10-14): Evaluasi tenant leak, dashboard strings, rule messages, dead code, regression tests

Backend, tests and the two stale E2E lines Main allowed. Status is `done`: with ModOperasional and ModOperasional2 every item of this ticket is in; what stays open belongs to other owners (needs-change below).

**Done**
- **Leak (security).** `EvaluationController` Index and Create sent the `employees` prop: every active technician, staff and noc user of every company (`User` has no company scope), as `UserResource` rows with id, name, email, home company id, roles, last login and two-factor state. The pages now get nothing of the kind. The employee picker is `SearchOptions` field `employee` over `User` joined to `company_user` of the effective company (`CompanyService::currentId()`, so the company a group admin switched into counts), active, with a role in `EmployeeEvaluation::EVALUABLE_ROLES` (`technician`, `staff`, `noc`, one list for the picker and the store). `selectedEmployee` comes from the same query: `null` for a foreign, inactive or non-evaluable `employee_id`. `ticketOptions` (resolved or closed, with a handler) and `workOrderOptions` (completed, with a technician) are `SearchOptions` fields `ticket` and `workOrder` over the company-scoped models.
- `StoreEvaluationRequest`: `employee_id` must be an active member of the effective company (`company_user`, no longer the home `users.company_id`) with an evaluable role, and reports one message (`bail`); `reference_id` stays `PolymorphicBelongsToCompany` (the type picks the table, company-scoped, soft-deleted rows excluded). No per-request `messages()` or `attributes()`: `lang/id/validation.php` already names `employee_id`, `reference_type`, `reference_id`, `score`, `customer_rating`, `comment`, and `EvaluationCompanyScopeTest` pins every rendered text. `UpdateEvaluationRequest` has no tenant field.
- Evaluasi flash messages ("Evaluasi berhasil dibuat/diubah/dihapus."), export headings (Karyawan, Referensi, Nilai, Penilaian pelanggan, Penilai, Tanggal; PDF title "Evaluasi") and the `referenceOptions` / `referenceOption` props are in and tested.
- Dasbor: `DashboardAdminStatsQuery::MODULES` names (Pelanggan, Layanan, Inventaris, Aset jaringan, SPK, Tagihan, Tiket), both `abort` texts ("Konteks perusahaan diperlukan."), and the overdue card title "Tagihan jatuh tempo" (was "Invoice jatuh tempo"; `dashboard-persona.spec.ts` expects the new one).
- `app/Rules`: `BelongsToCompany`, `PolymorphicBelongsToCompany`, `HasAnyActiveRole`, `IsActiveTechnician`, `MatchesParentAttribute` speak Indonesian and name the field from the lang file. `NoSodConflict` and `NoStackedSodConflict` own no text: they pass `SodConflicts::violations()` through, which is Indonesian already (not edited).
- Deleted `app/Actions/SpawnSpkFromTicketAction.php` (it was an emptied file; grep over `app`, `Modules`, `routes`, `database`, `config`, `resources`, `tests` found no caller; `TicketService::spawnSpk` is the real path) and `FieldCheckIn::summary()` with its private `reasonLabel()` and the six unit cases (no caller; `apiSummary()` stays). `docs/business/ticketing.md` named the deleted class in five places: they now name `TicketService::spawnSpk`, and the SPK pre-fill line no longer claims the asset (the code copies customer, subscription, location). Docs were not on this assignment's list: revert that file if the wave keeps `docs/**` frozen.
- E2E (Main approved these two lines only): `kanban-t_49387795.spec.ts:212` heading `/SPK dari tiket TKT-.../`; `loading-progress.spec.ts:617` "Lampiran berhasil diunggah.".
- Tests. New: `EvaluationCompanyScopeTest` (9: page props never carry a foreign employee, employee options per effective company and role, reference options, store snapshot and flash, store in a switched company, rejected employees, rejected references, Indonesian messages, update and delete), `ValidationRuleMessagesTest` (5), `PriorityVocabularyTest` (3: ticket and SPK forms take the four priorities and refuse `critical`; the Tickets and Dispatch filters find each priority and `critical` finds nothing; the `priority` columns refuse `critical`). Rewritten: `EvaluationServerTableExportTest` (5: paginator `meta` current_page, last_page, per_page, total and nothing at the top level; reference codes on Index and Show; `selectedEmployee`; CSV headings; export permission). `DashboardPersonaTest` +2 (card titles, refusal without a company). The tenant tests that were in `EvaluationServerTableExportTest` were not green: `test_employee_options_use_the_effective_company_membership_not_the_home_company` failed on a baseline run (the admin was not a member of the company it switched into); they are replaced by the file above. The `priority` tests pin the vocabulary: production behaviour did not change, so they pass before and after.

**Verified** (repo root, Sail, `flock /tmp/inbils-e2e.lock … flock /tmp/inbils-phpunit.lock php artisan test`)
- Red then green for the leak. Red: the two Evaluasi test files against the HEAD controller and HEAD `StoreEvaluationRequest` (swapped in under both locks, restored and `cmp`-checked) → `Tests: 10 failed, 4 passed (94 assertions)`; the leak test fails with `Not to contain: Teknisi Asing` (HEAD sends the other company's technician in `props.employees`), `employeeOptions`/`selectedEmployee`/`referenceOptions` do not exist, flashes read "Evaluation created.", a switched-company employee is refused. Green on the working tree → `Tests: 14 passed (166 assertions)`.
- Rules: against the HEAD rule files `4 failed, 1 passed (9 assertions)` ("The selected pelanggan is invalid for this company."), green `5 passed (14 assertions)`. Dasbor: against the HEAD queries `DashboardPersonaTest` + `DashboardTest` → `4 failed, 41 passed (762 assertions)` (card title, module names, abort text, `Pelanggan` module); green over `DashboardPersonaTest`, `DashboardExceptionCountsTest`, `DashboardTest` → `65 passed (1215 assertions)`.
- Focused sweep after the last edit: Evaluation x2, Rules, `PriorityVocabularyTest`, Dashboard x3, `tests/Unit/Support/FieldCheckInTest.php`, `SearchOptionsTest`, `RolePersonaSodTest` → `Tests: 148 passed (1701 assertions)`. Broad sweep before it: `tests/Feature/{Admin,Ticketing,SPK,Rules,Support,TenantIsolation,Tenant}`, `DashboardTest`, `P0CriticalPathTest`, `TenantBoundaryTest`, `RolePersonaSodTest`, `tests/Unit` → `Tests: 875 passed (6793 assertions)`. Related sweep: `tests/Feature/Api/V1/{Technician,Customer}`, `InventoryPickersTest`, `ReportTechnicianScopeTest`, `TechnicianPerformanceAuthorizationTest`, `DemoDataSeederTest` → `147 passed, 1 failed`: `Api/V1/Customer/SubscriptionConnectionTest:181` hit `customer_addresses_customer_id_label_unique` because `CustomerAddressFactory` draws the label from three values (not touched here); its re-run → `8 passed (52 assertions)`.
- `vendor/bin/pint` on every touched PHP file → pass. `phpstan analyse` (level 5, Larastan) on the touched `app/` files: clean for the request, model, queries, rules and `FieldCheckIn`; `EvaluationController` keeps the errors that were there before (unused `SORTABLE` because the trait reads it, `$evaluation->employee?->name` in the export map) plus the `SearchOptions::for()` generics (see needs-change).
- `COPY_SCAN=all node --test resources/js/lib/copy.selfcheck.mjs` → English only in `Components/composite/LatLngMapPicker.tsx` and `MediaPreview.tsx`; none in the files this ticket owns.

**Not verified / risks**
- No E2E run (wave rule); no authenticated render of the Evaluasi pages.
- Found, not fixed (pre-existing, not on the assignment): a second evaluation of the same reference by the same evaluator hits `eval_ref_evaluator_unique` and answers 500 instead of a validation error (the index counts soft-deleted rows, so a rule needs a product decision); `Show` offers "Ubah" without a `can.update` prop; `App\Traits\BelongsToCompany` still aborts with the English "Company context is required." (shared trait, `LocationContractTest` and `AuditLogTenantIsolationTest` assert it).
- The membership boundary is `company_user` only. Users created through `UserController`, `CustomerService`, `SetupWizardService` and the demo seeders get their default membership; a user homed in a company without that row would not be offered.

**needs-change**
- `app/Support/SearchOptions.php`: `for()` is typed `Builder<Model>` and `Closure(Model)`, so Larastan reports `argument.type` at every caller (29 errors in six sampled files, EvaluationController included). Docblock fix: `@template TModel of Model`, `@param Builder<TModel> $base`, `@param Closure(TModel): array{…} $present`.
- `tests/e2e/loading-progress.spec.ts:378`: `{ name: 'Evaluations', …, submit: 'Filter' }` expects a "Filter" button; the new Evaluasi Index filters live and has none. Main: the post-wave E2E fixer decides (it may keep a loading assertion for live filters).
- Still open from the frontend part: `tests/e2e/support/demo.ts` helpers and the English "Open"/"Download" in `MediaPreview.tsx`.

**Proposed CHANGELOG bullet** (security): Evaluasi: the employee list is no longer sent with the page (it held the technicians, staff and noc users of every company); employees, tickets and SPK are searched on the server and limited to the company the evaluator acts in; the store accepts only an active evaluable member of that company. Flash messages, export headings, Dasbor module names and the overdue card, and the shared validation rule messages are Indonesian.
