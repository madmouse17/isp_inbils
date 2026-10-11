# inbils — Task List for OpenCode

> Status: DRAFT. Hanya Phase 1 task dibuat (gate sebelum Phase 2).
> Approval baginda = gate sebelum eksekusi task pertama.
> Format task: ID / tujuan / module / file boleh diubah / dependency /
> aturan bisnis / acceptance / testing.

## Antrean aktif OMP

- Antrean hasil cutover Hermes ada di [`docs/plans/INBILS_OMP_BACKLOG.md`](docs/plans/INBILS_OMP_BACKLOG.md); sumber impor tetap di [`docs/plans/INBILS_OMP_SOURCE.json`](docs/plans/INBILS_OMP_SOURCE.json). Phase 1 di bawah adalah referensi historis, bukan pekerjaan yang di-reset atau otomatis diulang. DataTable C, D, dan E replacement COMPLETE. E selesai hanya di bawah kontrak Receivables-only. Reporting P1 replacement COMPLETE 2026-10-07 (kelima card done; lihat closure di bawah). Preserve existing filters, formulas, and visuals across all six report surfaces. Stock Card is only the existing movement ledger with server pagination/sorting, no opening balance/reconciliation; Audit Log keeps its canonical DataTable and only replaces the empty raw-SQL guard with Eloquent. Existing aggregates stay unchanged; no exports. Completed Technician Performance is untouched.

Untuk card migrasi, writer OMP dan bukti tool/delta/gate OMP mengganti kewajiban memanggil Claude/OpenCode serta transcript Claude. Acceptance bisnis, izin, tenant isolation, DB `inbils_test`, scope, dan review independen tetap wajib. Jangan mengubah card/konfigurasi Hermes, bypass security gate, atau menjalankan dispatcher baru.

Checkpoint 2026-10-03: Technician Performance COMPLETE, QA PASS dan reviewer `t_da288dbd` APPROVED. Full PHPUnit 760/5524, canonical Technician 1/1, general smoke 6/6, configured scoped Larastan level 5 dan build PASS; runtime app refreshed, `/up` dan `/ready` 200. Detail dan caveat ada di ledger. Tidak ada commit; Location belum dieksekusi.

Checkpoint Location 2026-10-03: COMPLETE, independent QA PASS dan reviewer `t_b64f8b75` APPROVED. Native-event diagnosis menemukan focus scroll 134px yang mengubah target klik; saved-marker pre-focus `{preventScroll:true}` memperbaikinya. Focused ESLint, tsc, build, canonical **2/2**, manual popup/no-draft/keyboard dan dark320 checks PASS. App Docker refreshed, `/up` dan `/ready` 200. Spec tidak berubah; tidak ada commit/Hermes mutation. Berhenti di phase gate, backlog recovery belum diimplementasikan.

Checkpoint Dispatch/My Work recovery 2026-10-03: assessment read-only selesai. Rantai pengganti B1 terbukti historically APPROVED (source375/build380/tester381 canonical3/3/QA382/reviewer383), sehingga ancestor archived tidak diulang. Namun QA `t_d8015197` dan reviewer `t_333b33e2` tetap `needs_recovery`: rubric asli hilang dan matrix lama lebih luas daripada spec3-case. Independent review menyetujui disposition konservatif ini. Butuh sumber acceptance asli atau approval matrix pengganti; tidak ada source/test/runtime mutation atau fresh PASS.

Checkpoint pengganti Dispatch/My Work: pengguna memilih **Gunakan matrix pengganti**. Canonical spec diperluas 3→13 skenario, **13/13 PASS**; backend isolasi/Dispatch/MyWork **44 tes / 439 assertion PASS**. QA PASS dan reviewer `t_333b33e2` APPROVED. Production code tidak berubah. Peringatan inner teardown dicatat, terminal port/marker/process checks bersih. Kedua card selesai di bawah kontrak pengganti, bukan klaim rubric lama pulih. Berikutnya Notification Center recovery, belum dieksekusi.

Checkpoint Notification Center pengganti 2026-10-03: pengguna menyetujui kontrak pengganti; rubric asli tetap belum pulih. Backend mempertahankan actor-scoped global unread count, UI mark-all memakai count global, dan View details menunggu read sukses sebelum navigasi. Penomoran ticket kini mencakup soft-deleted rows setelah fixture milik test memicu duplicate code. Isolated isolation+Notification awal **7 tes / 148 assertion PASS**; gate PHP final isolation+NotificationCenter+TicketValidation **15 tes / 175 assertion PASS (13.249s)**, termasuk soft-deleted code dan tenant case. Canonical owned lifecycle final **1/1 PASS (17.3s)**, exit 0 tanpa teardown warning. Backend/UI dan tester selesai untuk acceptance Notification pengganti; QA `t_298aaec0` dan reviewer `t_e7cc36eb` masih pending. Build, scoped page ESLint dan Pint empat file PHP PASS; ESLint spec E2E tidak diklaim PASS karena root project service mengecualikan tests. Deployment app-only rebuild/recreate selesai, Compose semua healthy, `/up` dan `/ready` 200 dengan checks OK; status backup unknown. Sesudah build hanya format test berubah, bukan kode aplikasi. Read-all sengaja menandai seluruh inbox Demo Technician pada shared `inbils_test` sebagai read; cleanup hanya menghapus owned tickets, durable notifications/read state tetap tersimpan. Existing seed classes memulihkan prasyarat `inbils_test` setelah PHP, tanpa manual DDL/reset. Owned temporary PHP launcher/extensions/packages, manual server dan sandbox/composer containers sudah dihapus; browser ditutup, report directories tidak ada. Baseline dirty tree yang diinspeksi (263 modified, 216 untracked) dipertahankan. Detail batas bukti dan riwayat kegagalan ada di ledger. Fase belum COMPLETE; berhenti sesudah reviewer Notification, tanpa menjalankan backlog berikutnya atau mengubah Hermes.

Closure Notification 2026-10-03: independent `NotificationQa` PASS (findings kosong, confidence 0.94) dan `NotificationFinalReview` APPROVED kontrak pengganti yang disetujui pengguna (findings kosong, confidence 0.95). Kelima card `t_97ad905e`, `t_d1939e94`, `t_3cfaed65`, `t_298aaec0` dan `t_e7cc36eb` done; fase Notification pengganti COMPLETE. Status pending pada checkpoint sebelumnya adalah riwayat sebelum gate final dan sudah digantikan closure ini. Bukti focused PHP/canonical/deployment di atas tetap berlaku, bukan klaim fresh full-suite atau pemulihan rubric asli. Berhenti di phase boundary; berikutnya DataTable recovery assessment setelah gate pengguna, bukan izin implementasi atau eksekusi sekarang. Hermes dan snapshot sumber tidak berubah.

Checkpoint DataTable A+B recovery 2026-10-03: pengguna meminta lanjut setelah Notification. Assessment read-only menemukan dua lane backend→UI yang bergabung ke tester→QA→reviewer, tetapi kontrak asli tujuh card tetap tidak ditemukan pada snapshot, live board dan exact-ID search dokumen. Existing canonical components serta feature suites bukan bukti acceptance pulih atau fresh PASS. Ketujuh card tetap `needs_recovery`; roster halaman dan matrix search/filter/sort/pagination/export/permission memerlukan kontrak asli atau persetujuan pengganti. Tidak ada perubahan source aplikasi/test/runtime/Hermes. Review disposition independen sedang berjalan; detail sumber dan batas scope ada di ledger.

Recovery gate: independent `DataTableRecoveryReview` PASS untuk assessment saja, tanpa temuan. Implementasi belum diotorisasi; pilihan berikutnya sumber acceptance asli atau matrix pengganti eksplisit untuk 11 index kandidat pada ledger, tanpa perluasan otomatis ke nested tables/HR tambahan/DataTable C–E.

Closure DataTable A+B pengganti 2026-10-03: independent `DataTableABQA` PASS (tanpa temuan blocking) dan `DataTableABReview` APPROVED kontrak pengganti yang disetujui pengguna (tanpa blocker; 2 advisory minor: `.omp/lsp.json` digitignore, label Edit Employees). Ketujuh card DataTable A+B done di bawah kontrak pengganti; rubric asli tetap belum pulih dan tidak diklaim pulih. Failing-before terbukti: Organization HTTP 500 (`SORTABLE`), filter tanggal/customer invoice diabaikan, `per_page=25` Service diabaikan, sel CSV invoice/employee berisi JSON baris utuh. Gate backend **125 tes / 1482 assertion PASS** pada `inbils_test` (27 suite export + Permission + isolation gate), canonical E2E `tests/e2e/datatable-ab.spec.ts` **11/11 PASS**, tsc/ESLint/Pint/build bersih. Tooling: OMP LSP untuk Laravel/PHP/TS/tailwind/json/yaml/docker dipasang per-user (`.omp/lsp.json` tidak dilacak). Berhenti di phase boundary; berikutnya DataTable C–E recovery, belum dieksekusi.

Checkpoint DataTable C–E recovery 2026-10-04: assessment read-only selesai, review independen `DataTableCERecoveryReview` PASS (assessment saja). Kontrak asli 15 card C/D/E tidak pulih (snapshot/board/docs hanya recovery text); semua tetap `needs_recovery`. Implementation map: C Inventory — Products/Categories/Units pages statis, StockController hardcoded `paginate(10)` + `whereRaw` low_stock; D — SPK tanpa `SORTABLE` (latent Error seperti Organization) + `paginate(10)`, Tickets `whereRaw`; E — Receivables statis, Invoice Show embedded tables statis. Kandidat matrix pengganti ada di ledger; butuh gate pengguna (kontrak asli atau pengganti) sebelum coding. Tidak ada perubahan source/test/runtime/Hermes.

### Closure DataTable C pengganti (2026-10-07)

- Pengguna menyetujui **Jalankan C lalu D** pada 2026-10-04. Kelima card C `t_dcf232f5`, `t_23e46c02`, `t_ee9c7127`, `t_43e33f86`, `t_39d87109` kini `done` di bawah kontrak pengganti, bukan pemulihan rubric asli. `InventoryCIndependentQA` **PASS** dan `InventoryCFinalReview` **APPROVED**, tanpa temuan tersisa. Checkpoint recovery sebelumnya adalah riwayat sebelum approval.
- Products/Categories/Units memakai canonical DataTable; Stocks/Movements memperbaiki ordering allowlist, page size, query preservation dan parity index/export. Low-stock memakai Eloquent `whereHas`/`whereColumn` dengan `withTrashed()` tetap tenant-scoped agar produk soft-deleted tidak kehilangan historical membership. Requests/Returns menyelaraskan query defaults dengan pagination server dan mempertahankan namespace masing-masing. Ekstraksi shared Requests constants/types dari page module memperbaiki entry Vite Requests yang hilang. Menu Products/Stocks/Movements menawarkan CSV/PDF secara eksplisit; tidak menambah export Requests/Returns atau Suppliers.
- Regresi awal: **4 tes / 75 assertion, 3 gagal**. Final Inventory **65 tes / 773 assertion PASS** dan focused Stock **7 tes / 394 assertion PASS**, MySQL `inbils_test` pada Docker `desktop-linux`, proyek terisolasi `inbils-c-verify`. Canonical `tests/e2e/datatable-c.spec.ts` **9/9 PASS** (`artifact://858`), memakai MySQL `inbils_test` default daemon pada host port **3307**. Ini **dua instance berbeda dengan nama DB sama**, bukan satu instance bersama.
- Browser gate mencakup default pagination Requests/Returns, namespace, menu format export, ordered off-page CSV dan respons PDF, dengan teardown bersih. CSV dibuktikan melalui HTTP attachment response, **bukan browser download event**. Screenshot ditangkap tetapi tidak dapat diinspeksi visual oleh tool; assertion viewport/overflow/theme bukan review visual screenshot. Tidak ada klaim fresh full-suite atau coverage yang tidak diukur.
- Build **PASS** (`artifact://851`), warning main chunk sekitar **809 kB** yang sudah ada tetap dicatat; scoped ESLint/Pint **PASS**. Hanya service `app` dibangun ulang/recreate pada `--context default`; Compose healthy, `/up` **200**, `/ready` **200**, backup **unknown**. Percobaan awal recreate `desktop-linux` gagal karena port 8080 sudah dilayani default daemon; runtime default yang benar kemudian diperbarui, tanpa menghapus volume.
- D selesai; tidak ada next card aktif. Stop setelah reviewer D. E dan Reporting tetap held sampai otorisasi terpisah. Hermes dan snapshot immutable tidak berubah. Bukti tiap card dan seluruh gate tersedia pada bagian DataTable D replacement closure ledger.
### Closure DataTable D pengganti (2026-10-07)

- Lima card D `t_69dd2c1a`, `t_40d41840`, `t_1b37b49b`, `t_0f7d528b`, `t_3b241cec` selesai di bawah kontrak pengganti yang disetujui pengguna; QA recheck `DQARecheck` PASS dan `DFinalReview` APPROVED. Rubric asli tetap unrecovered.
- Tiket memakai query Eloquent fail-closed dan filter priority bersama; SPK mendapat sortable allowlist, page size, dan otorisasi view serta export; Network Assets memakai fields canonical. Ketiga halaman memakai pagination server dan ekspor CSV/PDF. Shared PDF menghasilkan Dompdf nyata dari view ekspor escaped; limit 5.000 baris, perilaku memory tidak diukur.
- Failing-before WorkOrder: 1 tes / 1 assertion gagal HTTP 500 (`SORTABLE` undefined). Final isolated backend 22 tes / 617 assertion PASS pada `inbils-c-sandbox`, MySQL `inbils_test`; canonical `tests/e2e/datatable-d.spec.ts` 3/3 PASS (`artifact://965`). Tidak ada klaim full-suite. PDF attachment/signature dibuktikan; standalone `pdftotext` content smoke tidak dijalankan.
- QA awal menemukan export-only authorization bypass; regresi 200→403 diperbaiki dengan `viewAny` di export dan focused 1/1 PASS. Scoped ESLint/Pint dan build PASS (`artifact://914`, chunk warning 809.80 kB). App default Docker healthy, `/up` dan `/ready` 200; backup unknown. Tidak ada commit atau Hermes mutation.
- D phase ditutup; E dan Reporting tetap held. Detail bukti tercatat di ledger.
### Closure DataTable E pengganti (2026-10-07)

- Kelima card E `t_ef68d35a`, `t_353135bf`, `t_333772ab`, `t_9bc28da4`, `t_9df467ae` done di bawah kontrak pengganti Receivables-only yang disetujui pengguna. Original rubrics tetap unrecovered; Eloquent-only dan tanpa export. Invoice Show Items/Payments dan customer embedded invoice history tidak termasuk. Jika embedded tables disetujui secara terpisah, harus server-side. Reporting P1 kemudian disetujui secara terpisah, sesuai batas kontrak di bawah.
- Focused Receivables backend **8 tes / 131 assertion PASS**; Billing **72 tes / 456 assertion PASS**, Docker default isolated project `inbils-c-sandbox`, MySQL `inbils_test`. Canonical `tests/e2e/datatable-e.spec.ts` **4/4 PASS** (`artifact://1082`) pada default host-port-3307 MySQL `inbils_test`: 320/1280 themes, sort/page/reload, full totals, customer role 403, dan suspension modal label/reason validation/cancel/Escape. Tidak ada klaim deployment tenant DB terpisah atau full-suite.
- QA recheck PASS; final reviewer APPROVED. Build PASS (`artifact://1074`, existing 809.82 kB chunk warning); scoped ESLint/Pint PASS. Browser rendered empty-table state but tidak memaksa dataset kosong atau menjalankan mutation pada data kosong. Screenshot ditangkap tanpa inspeksi visual. Detail evidence dan batasan ada di ledger.
- E selesai; Reporting P1 replacement scope approved separately below. Hermes dan snapshot immutable tidak berubah.

### Reporting P1 replacement scope approval (2026-10-07)

- Pengguna menyetujui pengganti kontrak Reporting P1 untuk keenam report surfaces; acceptance asli tetap unrecovered. Pertahankan filter, formula, dan visual yang ada. Chain aktif berurutan: backend `t_316be420` (`in_progress`) → UI `t_fefb9a68` (`pending`) → tester `t_a7a1bda9` (`pending`) → QA `t_ecc8a59f` (`pending`) → reviewer `t_9e203f31` (`pending`). Tidak ada card yang selesai.
- Batas Stock Card: pagination/sort server pada movement ledger yang sudah ada saja, tanpa opening balance atau reconciliation. Batas Audit Log: ganti hanya empty-scope guard raw SQL dengan Eloquent, pertahankan canonical DataTable. Aggregate yang ada tetap unchanged. Tidak ada export baru.
- Technician Performance sudah complete dan dikecualikan dari perubahan kode. Ini pencatatan scope approval, bukan klaim implementasi, test, atau gate baru.

### Closure Reporting P1 pengganti (2026-10-07)

- Kelima card Reporting `t_316be420`, `t_fefb9a68`, `t_a7a1bda9`, `t_ecc8a59f`, `t_9e203f31` done di bawah kontrak pengganti yang disetujui pengguna; acceptance asli tetap unrecovered. QA PASS; reviewer APPROVED setelah recheck tiga temuan (produk soft-deleted 404, `location_id` hilang saat sort/page, loading state run pertama tidak pernah tampil) yang sudah diperbaiki.
- Stock Card: pagination/sort server pada movement ledger (allowlist `created_at`/`quantity`/`balance_after`, page size 10/25/50), produk company lain 404, produk soft-deleted company sendiri tetap tampil ledger-nya. Audit Log hanya `whereKey([])`. Tanpa export, opening balance, reconciliation, perubahan aggregate, atau perubahan Technician Performance.
- Backend `tests/Feature/Reporting` + `ReportFilterValidationTest` **42 tes / 547 assertion PASS** (`inbils-c-sandbox`, MySQL `inbils_test`); E2E `tests/e2e/reporting-stock-card.spec.ts` + `tests/e2e/reports-audit-log.spec.ts` **2/2 PASS** pada `inbils_test` (Audit Log memastikan cabang sort non-Stock-Card tetap utuh). Build, scoped ESLint, Pint PASS. Tidak ada klaim full-suite. Detail bukti ada di ledger.
- Reporting P1 selesai; pekerjaan berikutnya butuh otorisasi terpisah. Hermes dan snapshot immutable tidak berubah.

### Audit Hermes ↔ OMP (2026-10-07)

- Audit read-only board Hermes `inbils` (501 task; Hermes tidak diubah). Dari 97 task Hermes yang masih terbuka: 44 sudah `done` di OMP, 1 `superseded`, 7 micro-batch Technician Performance pasca-impor (ke-17 task TP pasca-impor terverifikasi satisfied/superseded, tidak ada yang open), dan **45 `needs_recovery`** dalam 9 lane.
- Sembilan lane menunggu persetujuan kontrak pengganti dari Owner sebelum coding: Reporting V2, Sales attribution dasar, KPI sales, Role persona/SoD, Sidebar IA, Loading/progress, Head Company, P2 export/drill-down, P2 persona dashboard. Tracker lokal mattpocock (`docs/agents/issue-tracker.md`): spec per lane di `.scratch/<lane>/spec.md` (format `to-spec`, bagian Owner decisions di awal) dan satu tiket per card di `.scratch/<lane>/issues/` (`Status: needs-owner-approval`). Detail rekonsiliasi di ledger.
- Rujukan dokumen di `AGENTS.md` diarahkan ke `docs/architecture/*`, `docs/business/*`, dan `docs/standards/*` (dokumen lama tidak pernah ada di git); blok `## Agent skills` ditambahkan ke `CLAUDE.md`.
- **Approval 2026-10-10:** Owner menyetujui opsi rekomendasi untuk enam lane: `reporting-v2`, `sales-attribution-base`, `kpi-sales`, `sidebar-ia`, `loading-progress`, `p2-persona-dashboard` (29 card → `pending`, tiket `.scratch` → `ready-for-agent`). Dikerjakan satu lane per waktu sesuai urutan itu. `head-company`, `p2-export-drilldown`, `role-persona-sod` tetap ditahan.
- **Reporting V2 selesai 2026-10-10:** kelima card done (registry metric v1, Data Dictionary, permission per laporan, definisi formula di halaman). Gate 138 tes / 3436 assertion, browser 13/13 di `inbils_test`, QA PASS, reviewer APPROVED. Deployment lama wajib menjalankan ulang `RolePermissionSeeder`. Lane berikutnya: `sales-attribution-base`.
- **Sales attribution dasar selesai 2026-10-10:** kolom `Acquired by` pada customer, permission `customer.sales.assign`/`customer.sales.correct`, audit koreksi dengan alasan. Gate 118 tes / 1213 assertion, browser 27/27 di `inbils_test`; reviewer menemukan bypass eligibility (JSON `true`) dan QA menemukan FK 500 pada hapus profil, keduanya diperbaiki red-first; recheck PASS + APPROVED. Deploy: `migrate` lalu `RolePermissionSeeder`.
- **Sidebar IA selesai 2026-10-10:** Dashboard + 6 grup, menu mengikuti hak akses server, grup kosong disembunyikan, satu item aktif. Gate 32 tes menu + 135 tes terkait, browser 22/22 di `inbils_test`, QA PASS, reviewer APPROVED. Catatan lanjutan: link Technician Performance masih 403 untuk manager tanpa organisasi (sudah ada sebelumnya).
- **KPI sales selesai 2026-10-10:** laporan Sales KPI per acquirer (customer, langganan, MRR, tagihan, terkumpul, outstanding, collection rate), permission `report.sales.view` (admin, manager), registry `sales.v1`, menu Finance/Reports. Gate 228 tes / 5396 assertion, browser 32/32 di `inbils_test`, QA PASS, reviewer APPROVED. Deploy: jalankan ulang `RolePermissionSeeder`.

## Aturan Eksekusi (baca dulu sebelum task apapun)

1. Baca `docs/` semua sebelum mulai task. Khususnya `ARCHITECTURE.md`,
   `DATABASE.md`, `SECURITY.md`, `COMPANY_PROFILE.md`, `API.md`, modul
   terkait.
2. Ikuti `AGENTS.md` (root) — UI convention + architecture rules +
   company rules.
3. Reuse existing code: model, component, service. Cari dulu sebelum
   buat baru.
4. Satu task = satu PR / satu commit batch. Tidak mixin task.
5. Tulis test: feature test untuk controller/service kritis, unit test
   untuk service murni. Coverage ≥ 80% untuk logika bisnis.
6. Update `CHANGELOG.md` setiap task (entry `### [task-id]`).
7. Tidak ubah arsitektur. Tidak tambah library tanpa approval Hermes.
8. Tidak commit langsung ke `main` kecuali disetujui. Branch
   `feat/{module}-{task-id}`.
9. Jalankan `php artisan test` + `npm run build` sebelum selesai. Lampir
   output ke ringkasan.
10. Lapor Hermes: file changed, test result, build result, risk/edge case.

## Format ID

`P{phase}-{nn}` — mis. `P1-01`, `P1-02`. Sub-task: `P1-01a`.

---

## Phase 1 — Core

> Urutan = dependency. Company system (P1-02/P1-03) jadi fondasi sebelum
> User/Role UI. Dua-layer config: `companies.settings` json (company,
> wizard isi) + `settings` table (system default, seeded saat install).
> Lihat COMPANY_PROFILE.md 1.1 (Initialization Rule) + 2.3 (two-layer).
> Modular arch: P1-00 install nwidart/laravel-modules + generate module
> stubs (Customer/Service/NetworkAsset/Inventory/SPK/Billing/Ticketing/
> Reporting) — infra fondasi sebelum domain.

### P1-00 — Install nwidart/laravel-modules + generate module stubs + module.json dependencies

**Tujuan:** Infra modular architecture. Install package, generate 8
module stub (Customer/Service/NetworkAsset/Inventory/SPK/Billing/
Ticketing/Reporting), isi `module.json` dependencies, register service
provider, verifikasi `Modules/` loaded.

**File:**
- `composer.json` (require nwidart/laravel-modules).
- `config/modules.php` (publish config).
- `Modules/Customer/module.json` (dependencies: []).
- `Modules/Service/module.json` (dependencies: []).
- `Modules/NetworkAsset/module.json` (dependencies: []).
- `Modules/Inventory/module.json` (dependencies: []).
- `Modules/SPK/module.json` (dependencies: [Inventory, NetworkAsset]).
- `Modules/Billing/module.json` (dependencies: [Inventory, SPK, Service]).
- `Modules/Ticketing/module.json` (dependencies: [SPK, NetworkAsset]).
- `Modules/Reporting/module.json` (dependencies: []).
- `Modules/*/Routes/web.php` (stub, empty group /admin).
- `app/Providers/AppServiceProvider.php` (register module routes — auto
  by package, verify).

**Aturan:**
- `composer require nwidart/laravel-modules`.
- `php artisan vendor:publish --provider="Nwidart\\Modules\\LaravelModulesServiceProvider"`.
- `php artisan module:make Customer Service NetworkAsset Inventory SPK Billing Ticketing Reporting`.
- Isi `module.json` `dependencies` per D-C2 (Customer=[], Service=[],
  NetworkAsset=[], Inventory=[], SPK=[Inventory, NetworkAsset],
  Billing=[Inventory, SPK, Service], Ticketing=[SPK, NetworkAsset],
  Reporting=[]).
- Verify: `php artisan module:list` → 8 module enabled.
- TIDAK buat domain logic di task ini (stub only). Domain di task
  module masing-masing (Phase 2/3+).
- Shared Core (Company/User/Setting/Customer entities/Location/
  EmployeeEvaluation) tetap `app/` — TIDAK pindah ke module.

**Acceptance:**
- `composer require` sukses, `config/modules.php` published.
- `php artisan module:list` show 8 module (enabled).
- `Modules/{Name}/` scaffold ada (Config/Console/Entities/Database/Http/
  Models/Services/Actions/Policies/Resources/Providers/Tests/Routes —
  nwidart default scaffold).
- `module.json` dependencies terisi per D-C2.
- `php artisan route:list` tidak error (module routes loaded, empty).
- `app/` Models/Core tetap (Company/User/Setting) — tidak terkena.

**Dependency:** none (first task Phase 1, sebelum P1-01).

### P1-01 — Install permission + activitylog package + publish migration + seed roles/permissions

**Tujuan:** Pasang spatie/laravel-permission + spatie/laravel-activitylog,
publish migration, modifikasi activity_log properties → json, seed roles
+ permissions sesuai matrix SECURITY.md Section 2.

**Module:** Core (no UI).

**File boleh diubah:**
- `composer.json` (require)
- `config/permission.php` (publish)
- `config/activitylog.php` (publish)
- `database/migrations/*_create_permission_tables.php` (publish, jangan
  rename)
- `database/migrations/*_create_activity_log_table.php` (publish, modifikasi
  kolom `properties` → json, tambah index)
- `app/Models/User.php` (tambah `HasRoles` trait)
- `database/seeders/RolePermissionSeeder.php` (baru)
- `database/seeders/DatabaseSeeder.php` (panggil RolePermissionSeeder)

**Dependency:** Phase 0 (existing).

**Aturan bisnis:**
- Roles: admin, manager, staff, technician, customer (customer inactive
  v1, seed permission saja tanpa user).
- Permissions: `{module}.{action}` per matrix SECURITY.md Section 2.
- Role-permission assignment sesuai matrix.
- Permission cache: default 86400 detik (spatie default), tidak ubah.
- ActivityLog: `properties` json, index `(log_name)`, `(subject_type,
  subject_id)`, `(causer_type, causer_id)`.
- TIDAK seed user (dibuat P1-03 bootstrap command). TIDAK seed company
  (dibuat P1-03 wizard).

**Acceptance:**
- `composer install` sukses, 2 package di `composer.json`.
- `php artisan migrate:fresh --seed` sukses, tabel `roles`, `permissions`,
  `role_has_permissions`, `model_has_roles`, `model_has_permissions`,
  `activity_log` ada.
- Seed: 5 roles, ~80+ permissions (8 module per matrix SECURITY.md
  Section 2), assignment benar.
- ActivityLog properties column = json type (cek `SHOW COLUMNS FROM
  activity_log`).
- TIDAK ada user di DB setelah seed (User::count() === 0).

**Testing:**
- Unit test `RolePermissionSeederTest`: assert role count, permission
  count, admin has all `*.manage` permission.
- Tidak perlu feature test (seeder-level).

---

### P1-02 — Company + CompanyScope + CompanyService + Setting (system) + SettingService + migration

**Tujuan:** Fondasi tenant + config two-layer. `companies` table, model
`Company`, trait `BelongsToCompany` (global scope + auto-set company_id),
`CompanyService` (current/setting/updateProfile/updateSettings),
`settings` table (system-global), model `Setting`, `SettingService`
(get/set/flush cache), `SystemSettingSeeder` (defaults), `users.company_id`
column. TIDAK buat UI di task ini (wizard = P1-03, profile/settings UI =
P1-05, system setting UI = P1-05).

**Module:** Core (tenant + config foundation).

**File boleh diubah:**
- `app/Models/Core/Company.php` (baru — lihat COMPANY_PROFILE.md 2.5)
- `app/Models/Core/Setting.php` (baru — system-global key-value)
- `app/Traits/BelongsToCompany.php` (baru — lihat COMPANY_PROFILE.md 4.4)
- `app/Services/Core/CompanyService.php` (baru — current(), currentId(),
  setting(), updateProfile(), updateSettings())
- `app/Services/Core/SettingService.php` (baru — get(key, default),
  set(key, value), flush(key), flushAll())
- `database/migrations/*_create_companies_table.php` (baru)
- `database/migrations/*_create_settings_table.php` (baru — system-global
  key-value, schema COMPANY_PROFILE.md 2.3)
- `database/migrations/*_add_company_id_to_users_table.php` (baru —
  nullable FK, karena bootstrap user pre-wizard)
- `database/seeders/SystemSettingSeeder.php` (baru — seed defaults:
  app.name, app.locale, default_currency, default_timezone,
  default_date_format, default_tax_ppn_rate, registration_disabled,
  mail.from_name, mail.from_address)
- `database/seeders/DatabaseSeeder.php` (tambah panggil
  SystemSettingSeeder setelah RolePermissionSeeder dari P1-01)
- `app/Models/User.php` (tambah `company()` relation, TIDAK pakai trait
  BelongsToCompany — lihat SECURITY.md 14)

**Dependency:** P1-01 (activitylog untuk trait LogsActivity di Company).

**Aturan bisnis:**
- `companies` schema persis COMPANY_PROFILE.md Section 2.1. TIDAK soft
  delete. `is_active` default true.
- `companies.settings` json cast. Default keys + values di
  COMPANY_PROFILE.md Section 2.2.
- `settings` table (system-global) schema persis COMPANY_PROFILE.md 2.3.
  `Setting` model: `$fillable=[key,value,group,label,type,is_public]`,
  cast `is_public=boolean`. TIDAK pakai trait BelongsToCompany (system-
  global, lintas tenant).
- `SettingService::get(key, default)`: baca `settings` table, cache
  `setting.{key}` forever, fallback default param. `set(key, value)`:
  update + flush cache. `flush(key)` / `flushAll()`.
- Two-layer `CompanyService::setting(key, default)`: baca
  `companies.settings` json current company; kalau null → fallback
  `SettingService::get('default_'.key)`; kalau null → default param.
  Cache per-company.
- `users.company_id` nullable FK → companies.id (nullable: bootstrap
  user pre-wizard).
- Trait `BelongsToCompany`: global scope `company` (where company_id =
  auth user's company_id via CompanyService::currentId()), auto-set
  company_id on `creating`, `withoutCompany()` scope bypass, `company()`
  relation.
- `CompanyService::currentId()`: return `Auth::user()->company_id` (null
  if guest/no company). Cache per-request via static var.
- `CompanyService::current()`: return Company model (null if none).
- `CompanyService::updateProfile(data)`: update name/logo/address/contact.
  Authorization: `company.manage` (Policy).
- `CompanyService::updateSettings(data)`: merge-update settings json.
  Authorization: `company.manage`.
- `SystemSettingSeeder` seed defaults (lihat COMPANY_PROFILE.md 2.3 table).
  Idempotent (updateOrCreate by key).
- TIDAK terapkan trait ke model lain di task ini (model Inventory/SPK/etc
  datang Phase 3-6, trait sudah ready dipakai).

**Acceptance:**
- `php artisan migrate:fresh --seed` sukses, tabel `companies` ada,
  `users.company_id` column ada (nullable), tabel `settings` ada + ter-seed
  (row `app.name`, `default_currency`, dll — verifikasi `SELECT * FROM
  settings`).
- TIDAK ada row di `companies` setelah seed (dibuat wizard P1-03) —
  verifikasi `SELECT COUNT(*) FROM companies` = 0.
- `Company::class` exists, `$fillable` sesuai, casts settings=array
  is_active=boolean.
- `Setting::class` exists, `$fillable` sesuai, casts is_public=boolean.
- Trait `BelongsToCompany` exists, boot menambah global scope + creating
  listener.
- Test model dummy (temporary test model pakai trait) → query ter-filter
  by company_id, create auto-set company_id.
- `CompanyService::currentId()` return null (no auth) → trait no-op
  (tidak filter), safe untuk artisan/queue.
- `SettingService::get('app.name')` return 'inbils' (dari seed). Cache
  hit pada call kedua.
- `CompanyService::setting('currency')` return null (no company) →
  fallback `SettingService::get('default_currency')` = 'IDR'.

**Testing:**
- Unit test `CompanyServiceTest`: setting() baca json + default fallback,
  updateSettings() merge, currentId() null when no auth.
- Unit test `BelongsToCompanyTraitTest`: pakai temporary test model,
  assert global scope filter + auto-set on create + withoutCompany bypass.
- Feature test: n/a (no UI yet).

---

### P1-03 — Setup Wizard (routes + controller + service + bootstrap command + frontend)

**Tujuan:** First-login setup flow. Bootstrap command buat user pertama,
middleware redirect, wizard 4-step, `SetupWizardService::create()` DB
transaction (company + admin role + user.company_id + audit).

**Module:** Core (onboarding).

**File boleh diubah:**
- `app/Console/Commands/SetupBootstrapCommand.php` (baru — `inbils:setup`)
- `app/Http/Middleware/RedirectIfNoCompany.php` (baru)
- `app/Http/Middleware/RequireNoCompany.php` (baru)
- `app/Http/Middleware/RequireHasCompany.php` (baru)
- `app/Http/Controllers/Setup/SetupWizardController.php` (baru — index +
  store)
- `app/Http/Requests/Setup/StoreCompanySetupRequest.php` (baru)
- `app/Services/Core/SetupWizardService.php` (baru — create(),
  isRequired())
- `app/Services/Core/AuditService.php` (baru, see P1-04 — kalau
  P1-03 butuh audit log di wizard, buat minimal stub di sini, lengkap di
  P1-04. Atau reorder: P1-04 sebelum P1-03. Decision: stub AuditService
  di P1-03, full implement P1-04.)
- `bootstrap/app.php` atau `app/Providers/AppServiceProvider.php`
  (register middleware aliases)
- `routes/web.php` (tambah `/setup` route group + middleware)
- `routes/admin.php` (register RequireHasCompany middleware di group —
  file dibuat P1-06, tapi middleware siap)
- `resources/js/Pages/Setup/Wizard.tsx` (baru — 4-step form, no
  AdminLayout, centered card layout)
- `resources/js/Layouts/SetupLayout.tsx` (baru — minimal layout untuk
  wizard, logo placeholder)
- `database/seeders/DatabaseSeeder.php` (update — urutan: hanya
  RolePermissionSeeder, TIDAK seed user/company)

**Dependency:** P1-01 (roles untuk assign admin), P1-02 (Company model +
CompanyService + company_id column).

**Aturan bisnis:**
- Bootstrap command `php artisan inbils:setup`:
  - Idempotent: `User::count() > 0` → exit dengan pesan "already
    bootstrapped".
  - Interactive: prompt name, email, password (hidden).
  - Create user: `is_active=true`, `email_verified_at=now()`,
    `company_id=null`, no role. Password bcrypt.
  - Print: "Bootstrap user created. Login at /login → complete Setup
    Wizard."
- `RedirectIfNoCompany` middleware (stack `web`+`auth`):
  - If auth + (`Company::count()===0` || `user.company_id===null`) →
    redirect `/setup`.
  - Except route `/setup*`, `/logout`, `/api/*`.
- `RequireNoCompany` middleware (route `/setup`):
  - If `Company::count() > 0` → abort 403 (wizard one-shot).
- `RequireHasCompany` middleware (route `/admin`):
  - If `user.company_id === null` → redirect `/setup`.
- Wizard Step 1 (Company info): name (req), code (req, auto-suggest dari
  name uppercase + unique check), logo (file upload optional — simpan
  setelah company create di Step 4 submit), address, phone, email,
  website.
- Wizard Step 2 (System config): currency (select IDR/USD/SGD/EUR,
  default IDR), timezone (select list, default Asia/Jakarta),
  date_format (select d M Y / d/m/Y / Y-m-d), datetime_format.
- Wizard Step 3 (Initial admin): tampilkan current auth user email
  (read-only), name (editable, prefill dari auth user), role=admin
  (locked badge), permission summary (full access badge). TIDAK create
  user baru — assign admin role ke current user.
- Wizard Step 4 (Confirmation): review semua, checkbox confirm (req),
  submit → POST /setup.
- `StoreCompanySetupRequest`: validate Step 1-3 data. authorize() = true
  (user authenticated + RequireNoCompany guard).
- `SetupWizardService::create(data)`:
  - DB::transaction:
    1. create Company (name, code, logo, address, contact, timezone,
       currency, settings json dari Step 2 + defaults
       COMPANY_PROFILE.md 2.2)
    2. assign admin role to auth user (spatie `$user->assignRole('admin')`)
    3. set auth user.company_id = company.id
    4. update auth user name (kalau diubah Step 3)
    5. AuditService::log('company', 'company_created', {...})
    6. AuditService::log('auth', 'admin_role_assigned', {...})
  - Return Company.
- `SetupWizardService::isRequired()`: `Company::count() === 0`.
- Logo upload: store di disk `public` path `companies/{id}/logo.{ext}`,
  filename regenerated. Update company.logo setelah create (Step 4
  post-company-create).
- Breeze register route: disable atau gate-keep (`User::count()===0`
  only) — cek `routes/auth.php`, kalau ada register route, tambah
  middleware `RegisterOnlyWhenNoUser`. Default: disable register (install
  via bootstrap command).

**Acceptance:**
- `php artisan inbils:setup` → create user, print success, idempotent
  on re-run.
- Login bootstrap user → redirect `/setup` (RedirectIfNoCompany).
- `/setup` GET → render Wizard.tsx (SetupLayout, no AdminLayout).
- Wizard 4-step navigable (client-side state), validation per step
  (frontend), final submit POST.
- POST /setup → company created + admin role assigned + user.company_id
  set + activity_log 2 row. Redirect /dashboard + flash.
- Re-visit /setup after company exists → 403 (RequireNoCompany).
- `/admin/*` with user.company_id null → redirect /setup.
- Company.settings json berisi defaults (currency_symbol, tax_ppn_rate,
  etc.).
- CompanyService::current() return new company post-wizard.
- Breeze register route disabled (or gate-kept).

**Testing:**
- Feature test `SetupBootstrapCommandTest`: command creates user,
  idempotent.
- Feature test `SetupWizardTest`: 
  - GET /setup 200 (no company), 403 (company exists).
  - POST /setup valid → company + role + user.company_id, redirect.
  - POST /setup invalid → 422 + errors.
  - RedirectIfNoCompany: login no-company → redirect /setup.
  - RequireHasCompany: user.company_id null + /admin → redirect.
- Unit test `SetupWizardServiceTest`: create() transaction, audit log
  rows.

---

### P1-04 — AuditService full + trait LogsActivity di model core

**Tujuan:** Lengkapi `AuditService` (stub dari P1-03) + integrasi
spatie/laravel-activitylog trait di User + Company + Setting(n/a) model.

**Module:** Core.

**File boleh diubah:**
- `app/Services/Core/AuditService.php` (lengkapi dari stub P1-03)
- `app/Models/User.php` (tambah `LogsActivity` trait + `activitylogOptions`
  method)
- `app/Models/Core/Company.php` (tambah trait + options)

**Dependency:** P1-01 (activitylog terpasang), P1-02 (Company model),
P1-03 (stub AuditService).

**Aturan bisnis:**
- Trait `LogsActivity` di User + Company.
- Log event: created, updated, deleted (soft).
- `properties`: before/after diff.
- `causer`: auto auth user.
- Tidak log: password attribute (config `activitylog.dont_log` atau
  attribute hidden di `LogOptions`).
- `AuditService::log(string $logName, string $description, array
  $properties = [])`: manual log untuk event non-model (login, role
  assign, company_created, export). Dipakai P1-03 wizard + listener
  Login event (last_login_at update + log).
- `AuditService::logModelActivity(Model $model, string $event)`:
  helper untuk manual model event log kalau trait tidak cukup.

**Acceptance:**
- Update User (change name) → activity_log row baru, properties
  before/after berisi diff.
- Create Company (via wizard) → activity_log row "created" (sudah
  berjalan dari P1-03 stub, verify).
- AuditService::log('auth', 'login', ['user_id' => 1]) → row di
  activity_log, log_name='auth'.
- Password change → tidak log password value (mask atau dont_log).

**Testing:**
- Feature test: update user → assert activity_log row + properties diff.
- Unit test: AuditService::log creates row, password masked.

---

### P1-05 — Company profile/settings UI + User + Role + Permission management UI

**Tujuan:** Admin UI: (1) Company profile + settings edit (post-wizard),
(2) User CRUD + assign role, (3) Role CRUD + sync permission, (4)
Permission index read-only. Merge task karena Company profile UI kecil +
User/Role/Permission UI besar.

**Module:** Core.

**File boleh diubah:**
- `app/Http/Controllers/Admin/CompanyController.php` (baru — index/edit
  profile + settings)
- `app/Http/Controllers/Admin/UserController.php`
- `app/Http/Controllers/Admin/RoleController.php`
- `app/Http/Controllers/Admin/PermissionController.php`
- `app/Http/Requests/Admin/UpdateCompanyProfileRequest.php`
- `app/Http/Requests/Admin/UpdateCompanySettingsRequest.php`
- `app/Http/Requests/Admin/StoreUserRequest.php`
- `app/Http/Requests/Admin/UpdateUserRequest.php`
- `app/Http/Requests/Admin/StoreRoleRequest.php`
- `app/Http/Requests/Admin/UpdateRoleRequest.php`
- `app/Policies/CompanyPolicy.php`
- `app/Policies/UserPolicy.php`
- `app/Policies/RolePolicy.php`
- `app/Http/Resources/CompanyResource.php`
- `app/Http/Resources/UserResource.php`
- `app/Http/Resources/RoleResource.php`
- `app/Http/Resources/PermissionResource.php`
- `app/Models/User.php` (tambah `is_active`, `last_login_at` migration
  kalau belum — cek P1-03, mungkin sudah. Kalau belum: migration
  terpisah)
- `database/migrations/*_add_is_active_last_login_to_users_table.php`
  (kalau belum ada)
- `app/Listeners/UpdateLastLoginAt.php` (baru — listen Login event)
- `resources/js/Pages/Admin/Company/Profile.tsx` (edit profile)
- `resources/js/Pages/Admin/Company/Settings.tsx` (edit settings json)
- `resources/js/Pages/Admin/Users/Index.tsx` (+ Create/Edit/Show)
- `resources/js/Pages/Admin/Roles/Index.tsx` (+ Create/Edit)
- `resources/js/Pages/Admin/Permissions/Index.tsx`

**Dependency:** P1-01 (role/permission), P1-02 (CompanyService +
company_id), P1-03 (company exists post-wizard), P1-06 (composite belum
ada — boleh pakai ui/ langsung untuk task ini, composite datang P1-09,
refactor setelahnya).

**Aturan bisnis:**
- Company profile: edit name, code (read-only post-create? — decision:
  editable tapi reseed warning, code unique global), logo, address,
  phone, email, website. Authorization `company.manage` (admin only).
- Company settings: edit keys di companies.settings json (currency_symbol,
  tax_ppn_rate, spk_auto_invoice, etc.). Form per key, type-aware
  (boolean → Switch, number → Input, string → Input). Authorization
  `company.manage`.
- User: name, email, password (hash, optional on update), is_active,
  company_id (assign — admin pilih company, v1 hanya 1 company jadi
  default current), roles (sync). Email unique. Cannot delete self.
  Cannot deactivate self. Cannot remove own admin role.
- Role: name unique, permissions sync. Default roles (admin, manager,
  staff, technician, customer) tidak bisa delete (hardcode list di
  RolePolicy `protectedRoles`).
- Permission: read-only index, tidak create/update/delete (permission
  di-code via matrix, tidak dinamis).
- User create: set email_verified_at = now (admin-created, skip
  verification).
- User last_login_at: update saat login (listener `Login` event →
  UpdateLastLoginAt).
- Policy: `users.manage` (admin) untuk user action. `roles.manage` untuk
  role. `company.manage` untuk company profile/settings.
- User management company scope: UserService filter
  `where('company_id', auth->company_id)` manual (User tidak pakai trait).

**Acceptance:**
- Company profile + settings edit via UI, save → companies row update +
  CompanyService cache flush.
- CRUD User + assign role via UI.
- CRUD Role + sync permission via UI.
- Permission index read-only.
- Cannot delete self / deactivate self / remove own admin → 422 or 403.
- Default role tidak bisa delete.
- last_login_at ter-update saat login.
- Activity log: user create/update/delete, role create/update/delete,
  role permission sync, company profile/settings update.

**Testing:**
- Feature test: CRUD user + role + company profile/settings,
  authorization matrix, self-protection rules, last_login update.
- Unit test: RoleService sync permission, UserService company scope.

---

### P1-06 — Route admin.php + Inertia shared props + sidebar menu + middleware wiring

**Tujuan:** File `routes/admin.php` baru, register di bootstrap, Inertia
shared props (auth.user + roles + permissions + company, flash, app),
sidebar menu AdminLayout dinamis berdasarkan permission. Wiring middleware
P1-03 (RedirectIfNoCompany, RequireHasCompany) ke stack + admin group.

**Module:** Core (infrastruktur).

**File boleh diubah:**
- `routes/admin.php` (baru)
- `routes/web.php` (update — /setup group sudah di P1-03, pastikan
  konsisten)
- `bootstrap/app.php` (require admin.php + middleware aliases + middleware
  stack: web → RedirectIfNoCompany)
- `app/Http/Middleware/HandleInertiaRequests.php` (extend share method)
- `resources/js/Layouts/AdminLayout.tsx` (sidebar dinamis via
  usePermission + company display di topbar)
- `resources/js/types/global.d.ts` (Inertia page props type + company
  type)

**Dependency:** P1-01 (permission untuk menu), P1-03 (company +
middleware), P1-05 (route group untuk controllers).

**Aturan bisnis:**
- Route group `admin` prefix, middleware `auth + verified +
  RequireHasCompany`.
- Route name `admin.{module}.{action}`.
- Inertia shared: `auth.user` (id, name, email, roles, permissions,
  company_id), `company` (id, name, code, logo, currency, timezone),
  `flash` (success/error/warning lazy), `app` (name, locale).
- Sidebar: section per module, item visible if `usePermission().can(...)`.
  - Dashboard: always.
  - Company (profile/settings): `company.manage`.
  - Users/Roles/Permissions: `users.manage` or `roles.manage`.
  - Inventory: `inventory.view`.
  - SPK: `spk.view`.
  - Billing: `billing.view`.
  - Ticketing: `ticketing.view`.
  - Customers: `customers.view` (master, Phase 2).
  - Components gallery (dev): always (atau hide di prod via env).
- Topbar: company name + logo display (baca dari Inertia `company` prop,
  bukan hardcoded).

**Acceptance:**
- Login admin post-wizard → sidebar semua module visible (kalau
  permission ada).
- Login staff → sidebar hanya module dengan `*.view`.
- Login technician → sidebar SPK + Ticketing (assigned) + Inventory view.
- Login user no-company → redirect /setup (RedirectIfNoCompany).
- `route('admin.users.index')` resolve via Ziggy di TS.
- Flash message render via `useToast` setiap redirect.
- Topbar display company name/logo dinamis.

**Testing:**
- Feature test: route 200 untuk user with permission, 403 untuk tanpa,
  redirect /setup untuk no-company user.
- Browser test (manual): sidebar dynamic per role, topbar company display.

---

### P1-07 — Location topology (shared Core — model + migration + service + policy + route)

**Tujuan:** Bangun Location topology CRUD (region/area/pop/rack/site)
sebagai shared Core (A1). Customer.md Phase 2 butuh (area_coverage +
serving_pop), network-asset.md Phase 3 butuh (placement), inventory.md
Phase 3 butuh (stock placement). Phase 1 eksekusi.

**Module:** Core (shared, app/Models/Core/Location.php + app/Services/
Core/LocationService.php).

**File boleh diubah:**
- `app/Models/Core/Location.php` (baru — topology, self-ref parent,
  materialized path, type enum)
- `database/migrations/*_create_locations_table.php` (baru —
  company_id, parent_id self-ref, code, name, type enum, path varchar,
  lat/lng, is_active, soft delete + index)
- `app/Services/Core/LocationService.php` (baru — create/update/move/
  delete + path recompute + cycle prevention + recurse children)
- `app/Http/Controllers/Admin/LocationController.php` (baru — index
  tree + CRUD + move)
- `app/Http/Requests/Admin/StoreLocationRequest.php` (baru)
- `app/Http/Requests/Admin/UpdateLocationRequest.php` (baru)
- `app/Http/Resources/LocationResource.php` (baru)
- `app/Policies/LocationPolicy.php` (baru)
- `routes/admin.php` (tambah `/admin/locations` routes: tree + CRUD +
  move)
- `resources/js/Pages/Admin/Locations/Index.tsx` (baru — tree view
  expandable)
- `database/factories/LocationFactory.php` (baru)

**Dependency:** P1-02 (Company + BelongsToCompany trait), P1-06
(routes/admin.php + Inertia shared props).

**Aturan bisnis:**
- Location type: region (root, parent_id null), area (parent=region),
  pop (parent=area), rack (parent=pop), site (parent=rack).
- Materialized path: `path` = parent.path + " > " + code (denormalized
  for fast read). Recompute on move/rename + recurse children.
- Cycle prevention: LocationService walk ancestor chain, reject if self
  in ancestor.
- Delete: reject if has children OR active network_assets placed OR
  active stocks. Soft delete if clear.
- **Location type CHECK = app-level (D-R10):** DB tidak enforce type
  per FK use-case. LocationService + FormRequest wajib validate type
  per FK use-case (customers.area_coverage=region/area, dll). Test
  cross-type.

**Acceptance:**
- CRUD location topology (5 type) + path materialized + cycle
  prevention + recurse on move/rename.
- Tree endpoint return hierarchy.
- Delete restrict if children/assets/stocks.
- Location type validation per FK use-case (test cross-type).
- `php artisan route:list` show `/admin/locations` routes.

---

### P1-08 — Seed master defaults (CompanySeeder::runFor)

**Tujuan:** Seed per-company master defaults saat company created
(wizard Step 4 atau CompanyCreated event). Seed: default units,
ticket_categories ISP, sla_tiers sample, locations sample.

**Module:** Core (seeder, app-level).

**File boleh diubah:**
- `database/seeders/CompanySeeder.php` (baru — `runFor(Company $company)`)
- `app/Services/Core/SetupWizardService.php` (update — call
  `CompanySeeder::runFor($company)` di `create()` Step 4, OR fire
  `CompanyCreated` event)
- `app/Listeners/SeedCompanyDefaults.php` (baru — listen
  `CompanyCreated` event, call `CompanySeeder::runFor`)
- `app/Providers/EventServiceProvider.php` (register listener)

**Dependency:** P1-02 (Company model), P1-03 (SetupWizardService),
P1-07 (Location model for locations sample).

**Aturan bisnis:**
- Seed per-company (company_id):
  - Units: pcs, meter, roll, box.
  - ticket_categories ISP: no_internet (4h, urgent), slow_connection
    (8h, high), packet_loss (8h, high), device_issue (12h, medium),
    fiber_issue (12h, high).
  - sla_tiers sample: Bronze (99% 24h), Silver (99.5% 8h), Gold
    (99.9% 4h).
  - locations sample: 1 region, 1 area, 1 pop (minimal topology).
- Idempotent: cek existing sebelum insert (skip if already seeded).

**Acceptance:**
- `php artisan inbils:setup` + wizard complete → company + master
  defaults seeded (units, ticket_categories, sla_tiers, locations).
- Re-run wizard (blocked, but if forced) → idempotent (no duplicate).
- `ticket_categories` table has 5 ISP categories per company.
- `units` table has 4 default units per company.

---

### P1-09 — Composite UI primitives + hooks

**Tujuan:** Bangun composite reusable untuk Phase 2-6. Bukan business
logic, susunan primitives.

**Module:** Core (UI foundation).

**File boleh diubah:**
- `resources/js/Components/composite/PageHeader.tsx`
- `resources/js/Components/composite/DataTable.tsx` (sort + pagination
  + optional filter slot)
- `resources/js/Components/composite/FormField.tsx` (Label + Input +
  error text)
- `resources/js/Components/composite/StatusBadge.tsx` (variant per status
  enum)
- `resources/js/Components/composite/MoneyInput.tsx` (IDR format, baca
  currency dari company prop)
- `resources/js/Components/composite/DateRangeFilter.tsx`
- `resources/js/Components/composite/index.ts` (barrel)
- `resources/js/hooks/usePermission.ts`
- `resources/js/hooks/useInertiaForm.ts` (wrapper useForm + toast on
  success/error)
- `resources/js/hooks/useCompany.ts` (baca company dari Inertia props)
- `resources/js/lib/format.ts` (formatRupiah, formatDate, formatNumber —
  baca currency/date_format dari company settings)
- `resources/js/types/models.d.ts` (entity TS interfaces, include
  Company interface)

**Dependency:** P1-06 (Inertia shared props untuk usePermission +
useCompany).

**Aturan bisnis:**
- Composite = susunan `Components/ui/*`. Tidak import `lib/api.ts` di
  composite (kecuali DataTable boleh pakai `router` untuk pagination
  visit). Aksi persisten = di Page via `router`.
- `usePermission`: baca `auth.permissions` dari `usePage().props`,
  return `{ can(permission): boolean, canAny([...]): boolean }`.
- `useCompany`: baca `company` dari `usePage().props`, return company
  object (id, name, code, logo, currency, settings).
- `formatRupiah(1234567.89)` → baca company currency + symbol → format
  (default "Rp 1.234.567,89" id-ID untuk IDR).
- `formatDate(date)` → baca company date_format → format.
- `StatusBadge` variant: success/warning/danger/muted/info, map enum
  status → variant di pemakaian (bukan di component).
- `DataTable`: props `columns` (key, label, sortable, render), `data`,
  `pagination` (Laravel paginator shape), `onSort(key, direction)`,
  `onPageChange(page)`. Pakai `Table` ui + `Pagination` ui.
- Refactor P1-05 pages: ganti raw Table → DataTable, raw form →
  FormField (minimal refactor, bukan full rewrite).

**Acceptance:**
- Composite terpakai di P1-05 pages (refactor minimal).
- TypeScript strict: no `any`, props typed.
- Dark mode + responsive (DataTable: horizontal scroll di mobile, atau
  card fallback).
- Barrel export `composite/index.ts`.
- formatRupiah/formatDate baca company settings dinamis (bukan hardcode
  IDR/d M Y).

**Testing:**
- Tidak ada unit test (presentational).
- Build `tsc && vite build` clean.
- Manual browser: DataTable sort/pagination, FormField error render,
  MoneyInput format, DateRangeFilter, StatusBadge variant, formatRupiah
  per company currency.

---

### P1-10 — Dashboard widget + finalisasi seed + CHANGELOG

**Tujuan:** Dashboard admin menampilkan statistik core (company info,
user count, role count, activity log recent, quick link per module).
Finalisasi DatabaseSeeder (urutan: RolePermissionSeeder only; company +
user via wizard + bootstrap). Update CHANGELOG Phase 1.

**Module:** Core.

**File boleh diubah:**
- `app/Http/Controllers/Admin/DashboardController.php`
- `resources/js/Pages/Admin/Dashboard/Index.tsx` (rebuild dari template
  existing jadi real widget)
- `database/seeders/DatabaseSeeder.php` (finalisasi urutan: hanya
  RolePermissionSeeder. TIDAK seed user/company.)
- `CHANGELOG.md`

**Dependency:** P1-05, P1-09 (composite untuk StatCard + PageHeader).

**Aturan bisnis:**
- Dashboard data: company info (name, code, currency), user count, role
  count, active user count, activity log 10 recent, permission count.
- Per module placeholder card (Phase 2-6 akan isi): "Inventory — coming
  Phase 3" dengan count 0 / disabled.
- StatCard ui (existing) untuk angka.
- PageHeader untuk title "Dashboard" + company name subtitle.
- Recent activity list pakai DataTable composite (atau simple list).

**Acceptance:**
- Dashboard render real data dari DB (bukan hardcoded).
- Company info display (dinamis dari CompanyService, bukan hardcoded).
- StatCard pakai composite (kalau ada) atau ui/StatCard.
- Recent activity list.
- Quick link per module (tombol ke index page, nanti aktif saat Phase
  modul jadi).
- CHANGELOG entry `## [P1] — Phase 1 Core complete` dengan list
  deliverable (8 task).

**Testing:**
- Feature test: dashboard 200, props berisi company info + counts +
  recent activity.
- Manual: login admin post-wizard → dashboard real data + company name.

---

## Phase 2+ — task dibuat setelah Phase 1 QA pass

Setiap Phase mendapat task list baru di file ini (atau `docs/tasks/P{N}.md`
terpisah kalau file ini terlalu panjang). Gate = approval baginda + QA-ISP
pass Phase sebelumnya.

Phase 2 (Master Data: Customer + Service Catalog) — semua model
WAJIB pakai trait `BelongsToCompany` (P1-02), migration WAJIB
`company_id` + unique per-company. Seed default (units, ticket_categories,
sla_tiers, locations) via event CompanyCreated listener atau
`CompanySeeder::runFor($company)` dipanggil wizard P1-03 (P1-08 task).

- **Loading/progress & Persona Dashboard selesai 2026-10-10:** Semua 6 lane yang disetujui Owner (`reporting-v2`, `sales-attribution-base`, `kpi-sales`, `sidebar-ia`, `loading-progress`, `p2-persona-dashboard`) telah selesai lengkap dengan unit/feature test dan Playwright E2E di `inbils_test`. Semua QA & review PASS/APPROVED.

- **Role persona/SoD & P2 export selesai 2026-10-10:** pemisahan tugas manager/staff, approval penyesuaian stok dua langkah, guard pembatalan invoice, export CSV/PDF + drill-down semua laporan dengan permission `report.export`. Test backend & E2E hijau, QA/review APPROVED. Deploy: migrate lalu `RolePermissionSeeder`.

- **Head Company selesai 2026-10-11:** konteks company fail-closed, membership + subsidiary (CLI), switcher company, laporan konsolidasi Business untuk Head. Review isolasi SIGNED (instalasi satu root). Semua 9 lane recovery Hermes kini selesai.

- **isp_ari gap lanes (2026-10-11), semua `needs-owner-approval`, belum dikerjakan:** `customer-status-rollup` (4 tiket), `auth-2fa-passkeys` (6 tiket, satu kondisional), `midtrans-payment` (8 tiket), `installation-spk-delta` (0 tiket, kesimpulan no-gap), `isp-roadmap` (roadmap, 0 tiket). Spec + tiket di `.scratch/<slug>/`; keputusan Owner ada di bagian "Owner decisions" tiap spec.
