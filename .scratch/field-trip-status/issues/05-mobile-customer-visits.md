# MOBILE: customer 'Kunjungan teknisi' screen and home card

Status: done
Role: mobile
Blocked by: 02
Spec: `../spec.md`

## Scope

`/home/madmouse/Documents/web/inbils-mobile/customer/`: visits list + detail with the stepper, home card for the next open visit, notification tap opens the visit, widget tests, journey.

## Acceptance criteria

- [x] `flutter analyze`, `flutter test` green (core and customer), `dart format` clean, debug APK builds.
- [ ] Journey passes on the AVD: written (journey 9 and the two new screens in the walk), **not run on a device**; see Comments.

## Comments

### 2026-10-16 MobileCustomerVisits: done, journey not run on the AVD

**Where.** `/home/madmouse/Documents/web/inbils-mobile` on top of `a5adb87`, nothing committed.

**What changed.**

- Core `packages/inbils_core/lib/src/models/work_order_visit.dart` (+ `.freezed.dart`, `.g.dart`, exported from `inbils_core.dart`): `WorkOrderVisit {id, code, type{key,label}, scheduledDate, state (open|done|cancelled|unknown), steps[{key,label,at}], current, technician{firstName}, installationAddress{label,address,city}}`. `reachedCount` = the steps up to and including `current`, never judged by `at` (a reached step may have no time); `currentStep`. Test `test/models/work_order_visit_test.dart` (9).
- Customer `lib/features/visits/`: `visit_repository.dart` (+ `.g.dart`; `GET customer/work-orders` reads every page at `per_page=50`, `GET customer/work-orders/{id}`), `visits_screen.dart` (list, pull to refresh, empty state names the 30 days), `visit_detail_screen.dart` (status chip, type, SPK code, "Tahapan" stepper, "Rincian": day, technician first name, address label + street + city; pull to refresh), `visit_stepper.dart` (4 steps: check = past, ring + bold = the step the visit is at, hollow = to come; one semantics sentence per step: "Tahap 2 dari 4: Dalam perjalanan, tahap saat ini, 16 Okt 2026 08:30"), `visit_card.dart`, `visit_status_chip.dart`.
- Beranda: `home_repository.dart` `nextOpenVisit` + `homeNextVisit`; `home_sections.dart` `OngoingSection` puts the visit card (code, kind, step, "Jadwal 20 Okt 2026") above the tickets and it opens the detail; `home_screen.dart` pull to refresh reads the visits too.
- Routes `/visits` and `/visits/:id` (`router.dart`, `routes.dart`); notification subject `work_order` → `/visits/{id}` (`notification_subject_router.dart`); promo `_appLinks['visits']` → the list (`promo_carousel.dart`, its "has no such screen" comment removed); an **Akun row "Kunjungan teknisi"** (`account_screen.dart`).
- `app_id.arb`: 16 Indonesian keys (`visits*`, `visit*`), generated classes regenerated. Bantuan not touched.
- Tests: `test/visits_test.dart` (40: Akun row and list order/states/pages/failure/refresh, detail steps for each `current`, a reached step without a time, work sent back, cancelled, 404, retry, pull to refresh, semantics sentences, Beranda card rules and silence on failure, notification → detail incl. 404 and an old closed visit, 200 % text on 360 dp with tap-target/label/contrast guidelines); `home_logic_test.dart` `nextOpenVisit` (7); `notifications_test.dart` (`work_order` opens `/visits/55`, the "unknown subject" case now uses `wallet`); `home_feed_test.dart` (banner `visits` opens `VisitsScreen`, counted once; removed from the "only for looking at" cases).
- Integration (not run on a device): `fixtures.dart` (+5 fixtures: `work_orders`, `work_orders_in_progress`, `work_order_12`, `work_order_12_in_progress`, `notifications_en_route`), `fixture_contract_test.dart` (a parser for each), `journeys_test.dart` journey 9 (Beranda card → bell → "Teknisi dalam perjalanan" opens the steps → the technician starts, pull down → "Sedang dikerjakan" under way → Akun → Kunjungan teknisi), `screens.dart` ("Kunjungan teknisi", "Detail kunjungan" join the accessibility, text-scale and screenshot walks), `support.dart` (`scrollToText` extracted from `tapText`, same behaviour).

**Decisions to review.**

- The ticket names no way into the list, so Akun got a row next to Langganan / Riwayat pembayaran; without it only a promo banner with the key `visits` reached the list. Beranda's "Lihat semua" still goes to the tickets.
- Step words are the server's (`steps[].label`, the chip shows the label of `current`); only "Dibatalkan" is the app's, because a cancelled visit has no steps. A finished visit's chip reads "Selesai dipasang" whatever the type (the server's word).
- "Next visit" = among `state == open` the earliest `scheduled_date`, no date last, the older id first on a tie; an open visit from a day gone by comes first. Done, cancelled and unknown never.
- The Beranda card is an extra like the feed: while loading, after a failure or a 404 there is no card and no error, a failed refresh keeps the last card. With a card and no tickets the "Tidak ada tiket aktif" card is dropped (it would read as "nothing going on" beside a visit).
- Beranda and the list/detail read separately (autoDispose), so a quiet Beranda failure never leaves a cached error on the list and each opening of the list reads again. Like the other Beranda blocks, the card follows only Beranda's own refresh, not a refresh done on the detail.
- Work sent back shows "Sedang dikerjakan" under way and "Selesai dipasang" hollow, no reason (as the API). Technician: first name only; no phone anywhere.
- No resume refresh on Beranda for the visit (only the bell has one): a customer who comes back to the app pulls down, or opens the notification, which reads the detail.

**Evidence** (`/home/madmouse/Documents/web/inbils-mobile`, Flutter 3.47.7, `export PATH=$PATH:$HOME/development/flutter/bin`).

- `cd packages/inbils_core && flutter analyze` → `No issues found!`; `flutter test` → `+418: All tests passed!`.
- `cd customer && flutter analyze` → `No issues found!`; `flutter test` → `+368: All tests passed!` (`test/visits_test.dart` alone: `+40`).
- `dart format --set-exit-if-changed` on every touched file (`lib`, `test`, `integration_test`, core model + test) → `Formatted 34 files (0 changed)`.
- `cd customer && flutter build apk --debug --flavor dev --dart-define-from-file=env/dev.json` → `✓ Built build/app/outputs/flutter-apk/app-dev-debug.apk`.
- Host dry runs of the integration code through temporary wrappers in `test/` (deleted): `fixture_contract_test.dart` main → `+36: All tests passed!` (5 new fixtures parse with the app model); a copy of journey 9 on the fake API with the default 800x600 view → passed; the two new entries of `screens.dart` with the tap-target and label guidelines and at 200 % text → `+4: All tests passed!`.
- Layout seen once in throwaway golden renders (Ahem font, deleted): the stepper's line joins the icons, wraps cleanly at 200 % text on 390 dp.

**Not verified.** The journeys and the walks on the AVD (the orchestrator runs them: `flutter test integration_test/journeys_test.dart -d emulator-5554 --flavor dev --dart-define-from-file=env/dev.json`, and `accessibility_test`, `text_scale_test`, `docs_screenshots_test` pick the two new screens up by themselves); the real API; real fonts and the screenshots for the user guide (ticket 06). **Trap:** the header of `fixture_contract_test.dart` says `flutter test integration_test/fixture_contract_test.dart` needs no device, but with `emulator-5554` connected the tool installs on it: I ran it once by mistake, it failed at install (`adb uninstall id.inbils.customer` → `DELETE_FAILED_INTERNAL_ERROR`, the prod id, not installed), and `id.inbils.customer.dev` and `id.inbils.technician.dev` are still installed. Nothing else touched the emulator.
