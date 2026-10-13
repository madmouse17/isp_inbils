# MOBILE: Bantuan tab, Ada masalah? flow, ticket screens restyled

Status: done
Role: mobile
Blocked by: 04; 01 for customer_hint
Spec: `../spec.md`

## Scope (owned files, repo `inbils-mobile/customer`)

Bantuan tab: open tickets with stage progress and copyable number, "Ada masalah?" (category list → hint → prefilled create form), ISP contact (phone, WhatsApp, hours); ticket detail/comments restyled. Widget tests per spec.

## Acceptance criteria

- [x] `flutter analyze` clean; `flutter test` green. (Not run: integration tests, device/AVD, screenshots → ticket 07.)

## Comments

### MobileBantuan report, 2026-10-14

Repo `inbils-mobile/customer`, nothing committed.

#### Done

- **Bantuan tab** (`lib/features/tickets/tickets_screen.dart`): one scrolling list. "Ada masalah?" card → "Tiket Anda" with the Aktif / Selesai filter → ticket cards (number, title, status chip, last update, stage progress, copy button) → paging footer → "Hubungi {ISP}" card with `ContactTiles` (WhatsApp, phone, email, website, support hours; hidden when the ISP set none). "Buat tiket" button kept (integration tests and `screens.dart` use it).
- **Stage progress** (`ticket_stage_progress.dart`): Dibuka (open) → Ditangani (assigned, on_progress) → Selesai (resolved, closed); unknown status shows nothing. Read as one sentence "Tahap 2 dari 3: Ditangani". From text scale 1.5 the stages stack vertically so no label breaks mid-word.
- **Copy number** (`copy_ticket_number_button.dart`): 48 dp icon, tooltip "Salin nomor tiket TKT-…", SnackBar "Nomor tiket disalin." On the list card (beside the tap area, not inside it) and in the ticket detail app bar.
- **Ada masalah?** (`lib/features/help/`): `/help` category list (`help_categories_screen.dart`) → `/help/:id` hint screen (`help_hint_screen.dart`). Shows `customer_hint` when the category has one, otherwise a 3-step Indonesian checklist (cek lampu modem; cabut listrik modem 30 detik; cek kabel). With several offerable services a "Layanan yang bermasalah" dropdown; with one it is taken silently. "Masih bermasalah? Buat tiket" → `/tickets/new?category=&subscription=`.
- **Models/routes**: `TicketCategory.customerHint` (optional, `customer_hint`; regenerated freezed/json). `CustomerRoutes.help`, `helpCategory`, `newTicketFor`; `router.dart` has the help routes and passes the query to `NewTicketScreen(initialCategoryId, initialSubscriptionId)`. `shell.dart` unchanged.
- **New ticket form**: opens with the category and service chosen; an id the server does not offer is dropped (not shown, not sent). Create and attachment flows unchanged.
- **Ticket detail**: header card (title, status, stage progress, category, service), resolution note as a tinted card; comments and attachments as before.
- **Strings**: `app_id.arb` + regenerated `lib/l10n/generated/*`.
- Tests: `test/tickets_test.dart` (+11): hint present, hint absent (checklist), form prefilled with several services (payload `category_id`, `subscription_id`), one service not asked, stage mapping, "Tahap n dari 3" per status, copy number (clipboard + SnackBar), contact block shown / hidden, **text scale 2.0 at 360 dp** on the Bantuan list (stacked stages, scrolled to the contact block) and the help flow, no exception.
- `test/home_test.dart:1033` (not mine): "Lihat semua" test asserted `find.text('Selesai')` findsOneWidget as the Bantuan filter; the stage label is now also "Selesai", so it asserts `find.text('Tiket Anda')` instead. Same edit for the filter tap in `tickets_test.dart` (scoped to the `SegmentedButton`).

#### Verified

- `dart format --set-exit-if-changed lib test integration_test` (customer) → 92 files, 0 changed.
- `flutter analyze` (customer) → "No issues found!"
- `flutter test` (customer) → `+250: All tests passed!`
- `flutter gen-l10n`, `dart run build_runner build --delete-conflicting-outputs` ran before the tests.

#### Not verified / risks

- No device or host-rendered image of the new screens; only widget-test assertions. Look at Bantuan, the hint screen and the stacked stages at 200 % on the AVD (ticket 07).
- `customer_hint` is absent from the API today (ticket 01 pending); the hint screen shows the checklist until the web sends it. Hint text is shown as plain text, newlines kept.
- Integration tests (`integration_test/**`) were not run; their ticket flows (`Buat tiket`, `Internet mati 31`) still match the text on screen.
- Bantuan keeps the Aktif / Selesai filter, so "open tickets" are the default Aktif view; a closed ticket shows all three stages complete.
- Beranda "Hubungi ISP" sheet still routes to `/tickets` (unchanged).
