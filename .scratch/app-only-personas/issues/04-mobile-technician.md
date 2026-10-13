# MOBILE: technician app — attachments, SPK items, inventory requests, profile

Status: done
Role: mobile
Blocked by: none (API contract in spec R3; message BeApi for shapes)
Spec: `../spec.md`

## Scope

Repo `/home/madmouse/Documents/web/inbils-mobile`, app `technician/` and shared `packages/inbils_core/` (profile/password screens and API client methods go in core so MobCust reuses them — you own `packages/inbils_core/lib/src/{api,screens/account,models}` additions; MobCust owns only `customer/`).

## Change

Spec R4: ticket attachment upload/delete on ticket detail (camera/gallery/file picker already used by evidence — reuse), SPK "Material" section (list, add via `GET technician/products?search=`, remove), "Permintaan Barang" tab (list, create with warehouse + items, cancel), Account → Ubah profil (name, phone) and Ganti kata sandi (then re-login because tokens are revoked). Indonesian copy, offline/empty/error states as existing screens, Idempotency-Key on writes via the existing client. Widget tests per screen; extend `integration_test/journeys_test.dart` + `fixtures.dart` for attachment upload and SPK item add.

## Acceptance criteria

- [x] `flutter analyze` and `flutter test` green in `technician/` and `packages/inbils_core/`; output pasted.
- [x] `flutter build apk --debug` succeeds.

## Comments

### MobTech, 2026-10-13 — evidence

- `packages/inbils_core`: `flutter analyze` → `No issues found!`; `flutter test` → `+246: All tests passed!` (21 new in `test/screens/profile_screens_test.dart`, 1 in `models_test.dart`).
- `technician`: `flutter analyze` → `No issues found!`; `dart format --set-exit-if-changed lib test integration_test` → 0 changed; `flutter test` → `+212: All tests passed!` (33 new in `test/app_only_features_test.dart`; the host contrast test also walks the 6 new entries of `integration_test/screens.dart`).
- `integration_test/fixture_contract_test.dart` (run through a throwaway wrapper in `test/`, because `flutter test integration_test/…` demands a device) → `+39: All tests passed!`.
- `flutter build apk --debug --flavor dev --dart-define-from-file=env/dev.json` → `✓ Built build/app/outputs/flutter-apk/app-dev-debug.apk`.

### What shipped

- Core (for MobCust too): `Me.phone`; `ApiClient.patch`; `AuthRepository.updateProfile` / `changePassword`; `CoreRoutes.profileEdit` (`/account/profile`) and `CoreRoutes.changePassword` (`/account/password`) inside `coreRoutes()`; `ProfileEditScreen`, `ChangePasswordScreen`; `AccountMenu` shows "Ubah profil" and "Ganti kata sandi" first (optional `onLogout`); `SignOutReason.passwordChanged` (the login screen says so, as a confirmation); `SubmitLabel` exported.
- Technician: ticket "Lampiran" (camera/gallery, caption, JPEG ≤ 1920 px without EXIF via the evidence normaliser, retry with the same key, delete follows `can_delete`); SPK "Material" (`actions.edit_items`; picker with search and paging, quantity > 0 with ≤ 2 decimals; add replaces the line's used quantity; remove); Material tab: "Permintaan baru" (warehouse from `GET technician/warehouses`, reason, materials) and "Batalkan" (follows `actions.cancel`); Akun uses the core menu and `me.phone`.

### Decisions and open points for Main

- R4 says "Permintaan Barang tab in Materials". I kept the Material screen as it was (two sections, "Perlu dideklarasi" and "Permintaan barang saya") and added the floating "Permintaan baru" button plus "Batalkan" on each cancellable request, instead of a `TabBar`: a tab would have needed a router-level tab selection for the notification that opens the requests, and rewrites of four existing journeys. Say if the owner wants a literal tab.
- Journeys 10 (ticket attachment), 11 (SPK material) and 12 (request) are in `integration_test/journeys_test.dart`. 11 and 12 pass on the host through the wrapper; 10 needs the real file I/O of a device (fake-async host cannot finish the multipart read), so it has not run anywhere. Journey 7 also fails through the host wrapper (`Tagihan baru` not found after `tech.back()`); it does not touch anything changed here, but I did not run it on a device either.
- Not run against the live API: BeApi's endpoints were not served when I finished; the shapes are the ones BeApi messaged (warehouses, `actions.cancel`, `actions.edit_items`, attachment `can_delete`, `Me.phone`).
- Contract check after BeApi finished (docs/api/mobile-v1.openapi.json): `TechnicianInventoryRequestResource.actions.cancel`, `TechnicianWorkOrderResource.actions.edit_items`, `TicketAttachmentResource{caption,mine,can_delete}`, `MeResource.phone`, `TechnicianProductResource`, `TechnicianWarehouseResource`, `TechnicianWorkOrderItemResource` and the paths `…/inventory-requests/{id}/cancel`, `…/work-orders/{wo}/items[/{item}]`, `…/tickets/{id}/attachments[/{media}]`, `me` (PATCH), `me/password` match the Dart models and calls field for field; the multipart idempotency fingerprint hashes the file bytes, so a retry with the same key and file is accepted. Still not run against a live server. A dev database needs `php artisan db:seed --class=RolePermissionSeeder --force` once, or ticket uploads answer 403.

### MobTech, 2026-10-13 — EXIF/GPS fix (security review, MEDIUM)

The customer app uploaded ticket photos as the picker returned them, with the EXIF block (GPS position) intact. The normaliser that already protected the technician app is now shared.

- `EvidenceNormalizer` (JPEG, long edge ≤ 1920 px, quality 80, `keepExif: false`, rotation applied) and `evidenceMaxEdge` moved from `technician/lib/features/evidence/` to `packages/inbils_core/lib/src/platform/evidence_normalizer.dart`. `package:inbils_core/inbils_core.dart` exports them with `evidenceNormalizerProvider`. `flutter_image_compress` moved from `technician/pubspec.yaml` to `packages/inbils_core/pubspec.yaml` (same `^2.5.1`; `pubspec.lock` unchanged).
- The test double `CopyNormalizer` is now in `package:inbils_core/testing.dart`; the technician's own copy in `test/support/queue_fixture.dart` is gone.
- Technician: `evidence_uploader.dart` and `attachment_source.dart` use the core class; `integration_test/evidence_normalizer_test.dart` (device only, GPS assertions unchanged) imports it from core.
- Customer: MobCust's `attachment_picker.dart` already passes every picked photo through `pickAttachment(…, normalizer)`; `flutter analyze` in `customer/` → `No issues found!` against the core API. I did not run the customer tests.
- New `packages/inbils_core/test/platform/evidence_normalizer_test.dart` (10 host tests): what the normaliser asks of the plugin (JPEG, quality 80, `keepExif: false`, rotation applied; landscape, portrait, at-limit and inside-limit sizes), `not_an_image`, a plugin that returns nothing, the picker's own file left untouched, and the `CopyNormalizer` seam. It does not prove that the native plugin strips EXIF; that is still the device test.
- Evidence: `packages/inbils_core` `flutter analyze` → `No issues found!`, `flutter test` → `+256: All tests passed!`. `technician` `flutter analyze` → `No issues found!`, `dart format lib test integration_test` → 0 changed, `flutter test` → `+212: All tests passed!`, `flutter build apk --debug --flavor dev --dart-define-from-file=env/dev.json` → `✓ Built build/app/outputs/flutter-apk/app-dev-debug.apk`; `GeneratedPluginRegistrant.java` still registers `flutter_image_compress`, now through core.

### MobTech, 2026-10-13 — DELETE items/{item} now withdraws the use (BeApi, security review)

`DELETE technician/work-orders/{wo}/items/{item}` answers 204 either way: a line the office planned (`quantity_reserved > 0` or a note) stays with `quantity_used` 0, a line that held nothing but the technician's use is deleted, and a repeated DELETE on a kept line is a 204 no-op. A serialized asset line is refused with 422 `unprocessable`; 403 `forbidden` means the SPK is not in progress or not the caller's; 404 means the line is not on that SPK. The app followed:

- After a 204 the SPK is read again (`ref.refresh(workOrderDetailControllerProvider(id).future)`), so the screen shows what the server kept or deleted. `WorkOrderDetailController.removeItem` (the local removal) is deleted.
- "Hapus" shows only while `quantity_used > 0`; each line tile is keyed by the line id so its state stays with the line.
- A 422 `unprocessable` on the DELETE is shown inline under the line, in Indonesian ("Baris ini tidak bisa diubah dari aplikasi", through `FieldErrorText`); the line then offers no Hapus, nothing is read again, and the server's English text is not shown.
- Copy that would have lied for a kept line changed: dialog "Hapus pemakaian material ini?" / "Jumlah terpakai {name} di SPK ini menjadi 0.", snackbar "Pemakaian material dihapus.", button semantics "Hapus pemakaian material: {name}". The button is still "Hapus".
- Tests: `test/app_only_features_test.dart` has 5 tests for it (an added line gone after the re-read, with the 2 GETs counted; a planned line stays with "Terpakai 0 m" and no Hapus; Hapus only on the line with a use; the 422 inline; a 403 because the SPK left in progress, which says so and reads the SPK again, the existing stale path). Journey 11 in `integration_test/journeys_test.dart` now runs against a stateful fake and asserts the second GET; it passes on the host through the throwaway wrapper.
- Evidence: `technician` `flutter analyze` → `No issues found!`, `dart format --set-exit-if-changed lib test integration_test` → 0 changed, `flutter test` → `+216: All tests passed!`.
- OpenAPI: `docs/api/mobile-v1.openapi.json` lists only 204 and 404 for this DELETE on purpose (BeApi): Scramble cannot see the `Gate` and `abort_if` calls inside `SpkService`, so the 403 and 422 appear in the error table of the document description and in this operation's prose; the apps hand-write their models from the document.
