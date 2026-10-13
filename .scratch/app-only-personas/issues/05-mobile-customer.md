# MOBILE: customer app — ticket attachments, profile

Status: done
Role: mobile
Blocked by: none (API contract in spec R3; profile screens from inbils_core by MobTech)
Spec: `../spec.md`

## Scope

Repo `/home/madmouse/Documents/web/inbils-mobile`, app `customer/` only. Profile/password screens come from `packages/inbils_core` (MobTech builds them; message `agent://MobTech` for the route names and wire them into the customer Account screen).

## Change

Spec R4: attachment upload/delete on ticket detail and on the new-ticket form (after create, upload each picked file); Account → Ubah profil / Ganti kata sandi entries. Indonesian copy, states as existing screens, widget tests, `journeys_test` extended for attachment upload.

## Acceptance criteria

- [x] `flutter analyze` and `flutter test` green in `customer/`; `flutter build apk --debug` succeeds; output pasted.

## Comments

2026-10-13 MobCust. Scope: `customer/` only (core untouched).

- Ticket detail: "Tambah lampiran" (camera/gallery via `image_picker`, jpg/png, optional caption) → `POST customer/tickets/{id}/attachments` multipart, one `Idempotency-Key` per picked file; "Hapus lampiran" + confirm → `DELETE .../attachments/{media}`, shown only when the attachment has `can_delete` (BeApi's final shape). 422 `file` message shown inline. Button hidden on a `closed` ticket.
- New ticket: picked photos listed with remove; after the ticket is created each is uploaded in turn; a failure still opens the ticket with a snackbar "…{n} lampiran gagal diunggah…".
- Account → "Ubah profil" / "Ganti kata sandi": come from core `AccountMenu` (MobTech); customer registers nothing. `accountProvider` watches `meProvider`, so a saved profile refreshes the tab.
- Files: `lib/features/tickets/{attachment_picker,ticket,ticket_repository,ticket_detail_screen,new_ticket_screen}.dart`, `lib/l10n/app_id.arb` (+ generated), `pubspec.yaml` (`image_picker ^1.2.4`, same as technician), tests `test/tickets_test.dart` (+10), `test/auth_account_test.dart` (+1), `integration_test/{journeys_test,support,fixtures,fixture_contract_test}.dart` (journey 4b).
- `flutter analyze` → `No issues found!`
- `flutter test` (customer/) → `All tests passed!` (124 tests)
- `flutter build apk --debug --flavor dev` → `✓ Built build/app/outputs/flutter-apk/app-dev-debug.apk`; plain `flutter build apk --debug` builds dev/staging/prod APKs but prints "failed to produce an .apk file" (flavor naming), as before.
- Fixture contract (`integration_test/fixture_contract_test.dart`, run as a host copy) → 31 passed.
- Not run: `integration_test/journeys_test.dart` journey 4b (needs the AVD; analyze-clean only). Real camera/gallery not exercised; PDF attachments cannot be added from the app (jpg/png only, per MobTech's call).
