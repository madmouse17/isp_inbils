# MODULE: Administrasi, autentikasi, layout

Status: done
Role: fullstack
Blocked by: 02, 03
Spec: `../spec.md`

## Scope (owned files)

Pages `Admin/Company/**`, `Admin/Organizations/**`, `Admin/Employees/**`, `Admin/Vehicles/**`, `Admin/Users/**`, `Admin/Roles/**`, `Admin/Permissions/**`, `Admin/Documents/**`, `Admin/NumberSequences/**`, `Admin/Components.tsx` (after ticket 02), `Auth/**`, `Profile/**`, `Setup/**`, `AppOnly.tsx`, `Welcome.tsx`; their controllers/requests (`app/Http/Controllers/Admin/{Company,Organization,Employee,Vehicle,User,Role,Permission,Document,NumberSequence}Controller.php`, `Profile*`, `TwoFactor*`), composite `PermissionGroupSelector` strings.

Specifics: employee hire date, vehicle purchase date → `DatePicker`; WhatsApp quiet hours → `TimeInput`; employee user → `RemoteSearchSelect`, organization/vehicle → `SearchSelect`; employee/user/vehicle status → `EnumBadge`.
Defects (R7): Employees edit dialog sends an empty user and never preloads (every save fails); Vehicles edit does not preload Purchase Date and has no pagination controls; Documents "Tambah jenis" ignores Aktif, Code edit ignored by the server (make it read-only on edit), no web caller for document upload (out of scope: note only); Organizations delete may orphan employees (block delete while employees exist, 422 with message).

E2E: `auth-roles`, `two-factor`, `head-company`, `sidebar-ia`, `number-sequences`, `map-default`, `app-only`, `datatable-d` (admin parts).

## Per-page checklist (apply to every owned page)

1. Dates → `DatePicker` / `DateTimePicker` / `MonthPicker` / `TimeInput` (spec Q1, R2); requests use `date_format` (R8).
2. Every reference to another record → `SearchSelect` (small catalogue) or `RemoteSearchSelect` + `SearchOptions` (large table) per Q3/R4; no typed ids; `Rule::exists(...)->where('company_id', …)` on the request.
3. Every user-visible string Indonesian per Q4 and the glossary R6: page title/subtitle, labels, placeholders, buttons, column headers, empty states, dialogs, flash messages in the owned controllers, FormRequest `messages()`/inline `$fail`, notification subjects/bodies, export headings.
4. Every status/type/priority value → `EnumBadge` with the domain map from `lib/statuses/*` (R5), also in filter options; delete local status maps.
5. Update the E2E specs listed below to the new text and controls; add assertions where a control changed (picker instead of input).
6. Fix the listed defects (R7) and add a regression test for each.

## Antislop findings (A1)

PF1 Company Settings: label map `{label, unit, help}` for every settings key (no raw snake_case, no "Settings JSON"), coordinates rounded to 6 decimals in display, `bank_account_info` rendered as lines; login page per ticket 02 G18.

## Acceptance criteria

- [ ] Checklist done for every owned page; `tsc`, ESLint, copy selfcheck (no denylisted English in owned files) clean; focused PHPUnit green; owned E2E specs type-check (orchestrator runs them).

## Comments

### ModAdministrasi hand-off (2026-10-14): backend done, 4 of 17 page groups done, rest still to do

Stopped at the run budget. Status stays `in-progress`: the acceptance criteria do not hold yet. Everything below is real; anything not listed as done is untouched.

**Done**
- Backend, all owned controllers: Indonesian flash and export headings/values/PDF titles (Company, Organization, Employee, Vehicle, User, Role, Document, NumberSequence, `UserTwoFactorResetController`), `StoreUserRequest`/`UpdateUserRequest` admin-role message.
- Defects: Organizations delete is blocked while employees (or children) exist, 422 with message on `organization` (`OrganizationService::delete`, its other two English messages translated); `OrganizationController` now uses the existing `Store/UpdateOrganizationRequest` (type enum, unique code per company) instead of inline rules. Documents: `is_active` honoured on create, `code` unique per company (was a DB 500), `per_page` honoured (was fixed at 10). Employees/Vehicles: resources send `hire_date`/`purchase_date` as `Y-m-d` so the edit form can preload. Users: the Create "Aktif" switch was silently ignored, now validated and saved.
- Dates/ids: `StoreEmployeeRequest`/`UpdateEmployeeRequest`/Vehicle requests use `date_format:Y-m-d`; employee `user_id` is company-scoped (home or `company_user` member), unique per profile (422 instead of a DB 500); employees index sends the optional `userOptions` prop (`SearchOptions`, field `user`) and no longer sends `users`.
- Frontend written: `Company/Profile`, `Company/Settings` (label map with unit and help per key, no "Settings JSON", `TimeInput` quiet hours, coordinates at 6 decimals, `bank_account_info` as a textarea; the 8 wizard keys no code reads are no longer shown but stay stored), `Organizations/Index`, `Employees/Index` (edit dialog preloads every field and no longer sends `user_id`; `RemoteSearchSelect` user, `SearchSelect` organisation/vehicle and organisation filter, `DatePicker`, `EnumBadge`, status and notes fields so nothing is sent hidden).
- Shared: `Components/composite/permissionLabels.ts` (Indonesian label for all 166 seeded permissions and 22 groups), `permissionGroups.ts` (`formatGroup`, `formatPermission`, "Lainnya"), `PermissionGroupSelector` strings, `permissionGroups.selfcheck.mjs` (now also fails when a seeded permission has no label; it was already red on `portal`, which has no sidebar entry since the app-only personas), `lib/statuses/people.ts` `TWO_FACTOR_STATE`.
- Tests: `EmployeeTest` (+7), `VehicleTest` (+4), `OrganizationTest` (+5), `DocumentTest` (+4), new `Admin/UserFormTest` (3); export heading assertions updated (`EmployeeServerTableExportTest`, `NumberSequenceServerTableExportTest`), `test_users_dropdown_is_company_scoped` rewritten as `test_user_options_are_company_scoped`.

**Verified**
- `flock … artisan test --compact` on EmployeeTest, VehicleTest, OrganizationTest, DocumentTest, Admin/UserFormTest, Admin/EmployeeServerTableExportTest, Admin/NumberSequenceServerTableExportTest, Tenant/TenantSafeWriteReferencesTest, CompanyProfileTest -> 77 passed, 1 failed (the old `users.data` assertion). After rewriting it: EmployeeServerTableExportTest + EmployeeTest -> 20 passed (128 assertions). Not re-run: Vehicle/Organization/Document/UserForm after Pint (they passed in the first batch).
- `vendor/bin/pint --format agent` on every touched PHP file -> passed.
- `node resources/js/Components/composite/permissionGroups.selfcheck.mjs` -> all assertions passed. `prettier --write` on the 8 TS/TSX files -> clean; `eslint` on them -> clean after one fix; `tsc --noEmit` -> no error in these files.

**Not verified / not done**
- No rendered check, no E2E, no `npm run build`. `node --test resources/js/lib/statuses.selfcheck.mjs` was not confirmed after adding `TWO_FACTOR_STATE`.
- Still to do (backend is ready for all of them): `Vehicles/Index` (DataTable + pager + `ExportMenu`, `DatePicker` purchase date preloaded, `ACTIVE_STATE`), `Documents/Index` (Indonesian, `code` read-only on edit and not sent, `onPerPageChange`), `Users/{Index,Create,Edit,Show}` (name links to show, `ACTIVE_STATE` + `TWO_FACTOR_STATE`, history tables via `INVOICE_STATUS`/`TICKET_STATUS`/`PRIORITY`/`WORK_ORDER_STATUS`/`WORK_ORDER_TYPE`, delete the local `statusVariant`/`priorityVariant`), `Roles/{Index,Create,Edit}`, `Permissions/Index` (columns Kode / Fungsi via `formatPermission` / Kelompok via `formatGroup`), `NumberSequences/Index` (replace `RESET_LABELS` with `RESET_PERIOD` + `optionsOf`), `Auth/{ForgotPassword,ResetPassword,ConfirmPassword,VerifyEmail,TwoFactorChallenge}` (move off the Breeze `TextInput`/`InputLabel`), `Profile/Edit` + 4 partials, `Setup/Wizard`, `AppOnly` (already Indonesian, review only). `Auth/Register.tsx` and `RegisteredUserController` are dead (no `register` route): delete both.
- E2E not touched: `two-factor.spec.ts` (every string is English: "Create User", "Authentication code", "Log in", "Use a recovery code", "Two-Factor Authentication", the On/Off cells, "Reset two-factor"), `app-only.spec.ts` ("Profile" heading becomes "Profil"), `auth-roles.spec.ts` (already Indonesian, check only). No regression E2E for the four defects yet. The users list now has two "Aktif" cells per row (status and 2FA): pick the 2FA cell by column.
- `copy.selfcheck.mjs` `CONVERTED` not extended; `COPY_SCAN` not run on the owned pages.

**needs-change**
- `app/Support/SodConflicts.php:45` (English "Separation of duties ... cannot be combined with ..."), `app/Support/UserDeletionGuard.php` and `app/Queries/Admin/CustomerAcquirerQuery.php:74` (English refusals) reach the Users, Roles and Profile screens.
- E2E outside my list that assert strings I changed (post-wave fixer): `map-default.spec.ts` and `whatsapp-notifications.spec.ts` ("Save Settings" -> "Simpan", "Company settings updated." -> "Pengaturan perusahaan diperbarui.", heading "Company Settings" -> "Pengaturan Perusahaan", "Lintang (latitude)"/"Bujur (longitude)" -> "Lintang"/"Bujur", quiet hours are now `TimeInput`, typed `HH:mm`, not `type=time`); `radius-provisioning.spec.ts:547,597` ("Save Profile" -> "Simpan", "Company profile updated." -> "Profil perusahaan diperbarui."); `datatable-ab` / `datatable-actions-delete` for the users, roles, permissions, employees and organizations tables ("Search" -> "Cari", headers, "Add Employee" -> "Buat Karyawan", "Add Unit" -> "Buat Unit").
- Files outside the ticket scope that I edited, for the integrator: `app/Services/Core/OrganizationService.php`, `app/Http/Resources/EmployeeResource.php`, `app/Http/Resources/VehicleResource.php` (also feeds the SPK `technicians` prop, which does not read `hire_date`).
- Latent, not fixed: a soft-deleted employee profile still holds `employee_profiles.user_id` (unique), so that user shows in the picker but a new profile is refused with "Pengguna ini sudah punya profil karyawan."; Users delete refusals only reach the generic "Data gagal disimpan" toast.

**Proposed CHANGELOG bullets**
- Admin: Organisasi tidak bisa dihapus selama masih punya karyawan; jenis dokumen menyimpan status Aktif dan kode unik; pengguna baru bisa dibuat nonaktif; edit karyawan menyimpan semua isian.
- Pengaturan perusahaan: label, satuan, dan keterangan tiap pengaturan; jam tenang WhatsApp memakai pemilih jam.

### ModAdminAuth Part 3 (2026-10-14): Auth, Profile, Setup, AppOnly, dead register, E2E

**Done**
- `Pages/Auth/{ForgotPassword,ResetPassword,ConfirmPassword,VerifyEmail,TwoFactorChallenge}.tsx` in Indonesian on `ui/Input`/`Alert` (no more `TextInput`/`InputLabel`/`InputError`, every field has a visible label and its error linked by `aria-describedby`); `VerifyEmail` no longer claims "signing up"; forgot/verify links and buttons are 44px on touch. `Login.tsx` and `AppOnly.tsx` already read as Indonesian and are unchanged.
- `Profile/Edit` (title "Profil") and partials: Data akun, Ganti kata sandi, Autentikasi dua langkah (`TWO_FACTOR_STATE` through `EnumBadge`, no local badge), Hapus akun. Tokens instead of `gray-*`, delete confirm uses `buttonVariants({ variant: 'destructive' })` (the hand-written `shadow-xs` red is gone), recovery codes download as `kode-pemulihan-inbils.txt`.
- `Setup/Wizard`: Indonesian; timezone is a `SearchSelect` (about 420 options, was a plain select); format hints; Enter in a field no longer posts the half-filled form (it goes to the next step; before, step 1 posted and the server answered 422); server errors are now listed on the last step (before they were invisible there).
- Deleted: `Pages/Auth/Register.tsx`, `app/Http/Controllers/Auth/RegisteredUserController.php` (no route: `routes/auth.php` has no `register`), and the orphans `Components/{TextInput,InputError,InputLabel}.tsx` (no importer left). Tests that only covered register: none removed. `Auth/RegistrationTest` and `P0CriticalPathTest::test_registration_is_unavailable...` assert `/register` is 404, which still holds and guards against re-adding the route.
- `TwoFactorService` 422 messages translated; `TwoFactorServiceTest` assertion follows ('belum aktif').
- `copy.selfcheck.mjs` CONVERTED: `Pages/Auth/*.tsx` (replaces the single Login entry), `Pages/Profile/**/*.tsx`, `Pages/Setup/Wizard.tsx`, `Pages/AppOnly.tsx`.
- E2E: `two-factor` (all login, challenge, profile and enrol strings; `createStaffUser` and the admin-reset test belong to ModAdminPeople), `app-only` ("Profil"), `head-company` (Reporting side: "Total konsolidasi", "Induk", "Metrik Konsolidasi" link, subtitle, "Periode ... sampai ...", "Tampilkan", "0 dari 0 tepat waktu", Indonesian CSV headers and metric names, Indonesian MRR definition; read from ModKeuangan's current `Consolidated.tsx`, `ReportExporter`, `MetricRegistry`), `auth-roles` already Indonesian and consistent with `lib/navigation.ts` labels (checked, unchanged).

**Verified**
- `prettier --write` + `eslint --max-warnings=0` on the 13 TSX files -> clean. `tsc --noEmit` -> no error in my files; 3 errors remain in `resources/js/types/index.ts` (`./inventory` exports `StockRow`/`MovementRow`/`Movement` missing, a sibling's work in progress).
- `node --test resources/js/lib/copy.selfcheck.mjs` -> 3 pass (CONVERTED includes my files).
- PHPUnit (`tests/Feature/Auth`, `ProfileTest`, `SetupWizardTest`, `SetupBootstrapCommandTest`, `AppOnlyRedirectTest`, `P0CriticalPathTest`) -> 144 passed, 1 failed (`TwoFactorServiceTest` asserted the English abort message); after fixing it, `TwoFactorServiceTest` -> 21 passed (134 assertions); the other 124 tests of the batch had passed.

**Not verified**
- No rendered check, no `npm run build`, no E2E (wave rules); the new specs are not type-checked with Playwright. The enrol flow now asserts `exact: true` on "Aktifkan autentikasi dua langkah" and "Nonaktifkan": confirm against the rendered page.
- `head-company` customer section (lines about 217-265, `expectCustomer`) depends on ModPelanggan's customer list text; not touched.
- No regression test for the Wizard Enter-key fix (frontend, needs E2E).

**needs-change**
- `app/Support/SodConflicts.php`, `UserDeletionGuard.php` (see Part 1) still English, reach the Profile delete flow through `UserDeletionGuard::ensureDeletable`.
- `docs/**`/`CHANGELOG.md` (frozen): guide mentions of "Register" or English Profile labels, and bullets "Profil dan halaman masuk berbahasa Indonesia; pengaturan awal tidak lagi mengirim formulir yang belum lengkap saat menekan Enter".

### ModAdminPeople Part 2 (2026-10-14): Vehicles, Documents, Users, Roles, Permissions, NumberSequences

**Done**
- `Vehicles/Index`: server `DataTable`, search/sort/pager, `ExportMenu` CSV/PDF, `ACTIVE_STATE`, Indonesian copy; edit now preloads `purchase_date` and notes; `DatePicker` replaces the native date input.
- `Documents/Index`: Indonesian copy, pager/per-page controls, CSV/PDF export, `ACTIVE_STATE`; Aktif stays in create/edit; Kode is read-only on edit and omitted from the update payload.
- `Users/{Index,Create,Edit,Show}`: Indonesian copy, name links to detail, `ACTIVE_STATE` and `TWO_FACTOR_STATE`; role validation is visible inline; Show uses shared invoice/ticket/SPK/priority/type maps, the company date/currency formats, a real audit-log link instead of the dead always-empty activity card, and an Indonesian 2FA reset flow. Delete refusals are visible above the list.
- `Roles/{Index,Create,Edit}`: Indonesian copy, neutral Bawaan marker, plain counts, Indonesian permission selector; `Permissions/Index`: Kode / Fungsi / Kelompok via `formatPermission` / `formatGroup`.
- `NumberSequences/Index`: deleted local `RESET_LABELS`; display and select use `RESET_PERIOD` + `optionsOf`. Awalan wording now agrees across the field, PHP validation and token description.
- Message-only PHP: Indonesian SoD, stock-adjustment actor and customer-acquirer deletion refusals; exact assertions updated. Organization export now offers CSV/PDF only, not a fake Excel entry.
- E2E source: translated Users portions of `two-factor`, Users labels in `loading-progress`, and new `admin-vehicles-documents.spec.ts` for vehicle date/notes/pager plus inactive document type and immutable code.
- `copy.selfcheck.mjs` CONVERTED now covers ticket 09 Company, Organizations, Employees, Vehicles, Documents, Users, Roles, Permissions, NumberSequences and `PermissionGroupSelector`.

**Verified**
- `npx tsc --noEmit` -> 0 errors.
- `npx eslint --max-warnings=0 <owned Admin pages>` -> 0 errors. `npx prettier --check <owned pages/spec>` -> all matched files formatted.
- `node --test resources/js/lib/*.selfcheck.mjs` -> 74 passed, 0 failed; `permissionGroups.selfcheck.mjs` -> all assertions passed; default copy selfcheck -> 3 passed.
- Focused PHPUnit (Vehicle, Document, User, Role, Permission, NumberSequence, SoD, acquirer and route/resource tests) -> 213 passed, 1442 assertions. Pint `--test` on touched PHP -> passed.
- Throwaway Vite/Chromium harness (deleted) rendered all owned pages plus vehicle/document dialogs, delete error, 2FA confirm, desktop/mobile and light/dark -> 0 console/page errors; visually checked date/notes preload and read-only document code.

**Not verified**
- E2E specs were type-checked only, not executed (wave rule). Main/orchestrator still needs the canonical E2E run.

**needs-change**
- `docs/**` / `CHANGELOG.md` are frozen. Update English quoted SoD/delete messages and add: "Admin: kendaraan memakai pemilih tanggal dan navigasi halaman; kode jenis dokumen terkunci setelah dibuat; pengguna, peran, hak akses, dan penomoran memakai label Indonesia dan badge status bersama."
- Post-wave fixer still owns shared `datatable-*`, `map-default`, `sidebar-ia`, `number-sequences`, `whatsapp-notifications`, `radius-provisioning` string updates.

2026-10-14, orchestrator: Parts 1 (ModAdministrasi), 2 (ModAdminPeople: pages + SodConflicts/UserDeletionGuard/CustomerAcquirerQuery messages) and 3 (ModAdminAuth) together cover the ticket; shared E2E specs go to the post-wave fixer (ticket 11). Latent soft-deleted employee `user_id` uniqueness parked.
