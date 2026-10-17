# MOBILE: technician SPK stepper, customer card, berangkat action

Status: done
Role: mobile
Blocked by: 01
Spec: `../spec.md`

## Scope

`/home/madmouse/Documents/web/inbils-mobile/technician/`: stepper on SPK detail (F3), "Saya berangkat" action with optional location fix (reuse `check_in.dart`), customer card (call, WhatsApp, Maps to installation address, separate POP link), Indonesian copy, widget tests, journey berangkat → mulai → selesai.

## Acceptance criteria

- [x] `flutter analyze`, `flutter test` green; debug APK builds.
- [ ] Journey passes on AVD `inbils_pixel7` (the journey is updated, not run: no device runs for this ticket; the orchestrator runs it).

## Comments

### 2026-10-16 MobileTechStepper: done

**What changed** (all in `/home/madmouse/Documents/web/inbils-mobile/technician/`; nothing in `packages/inbils_core` or `customer/`).

- Models (`work_order.dart`, freezed regenerated): `TripStep {key, label, at}`; `WorkOrderDetail.steps`, `.current`, `.pop`; `WorkCustomer.phoneE164`; `WorkOrderActions.enRoute`. All default to empty/null/false, so an SPK saved in the offline cache before this build still opens (no stepper until it is read again).
- `WorkOrderRepository.enRoute` -> `POST technician/work-orders/{id}/en-route`, body `{check_in: <fix>}`, same `_act` path as start/submit (Idempotency-Key, answer cached and rendered as returned).
- `trip_stepper.dart` (new): vertical `TripStepper`. Check = step passed, ring with dot = the step the visit is at, empty circle = to come; time under the name for steps that carry one; the last step, once reached, is a check (nothing follows it). Step names are the API's `steps[].label` (one source for both apps and the web), not ARB copy. One screen-reader stop per step: "Langkah 2 dari 4: Dalam perjalanan, langkah saat ini, <waktu>". `rework` (status `rejected`) puts the "Perlu perbaikan" `StatusChip` on the current step (third). No `steps` (cancelled) -> no section; `WorkStatusChip` now maps `cancelled` to "Dibatalkan".
- `work_order_actions.dart`: the bar follows `actions`. `en_route` -> filled "Saya berangkat" with a confirmation first (it tells the customer, so no accidental tap); while `en_route` is on, "Mulai" stays beside it as the outlined button (the API keeps `start` allowed, and a technician already on site must not be made to announce a trip); after the trip "Mulai" is the filled button; `submit` is labelled "Selesai dipasang · kirim untuk review" (ARB `actionSubmit`, same blockers). The location fix reuses `CheckInReader` and never blocks (permission denied / off / timeout go as `unavailable_reason`). The memo of the fix that a retry must resend is now **per action** (`_fixes` keyed by attempt): with one shared field, a fix read for an undecided "Berangkat" would have been resent as the check-in of "Mulai".
- `visit_cards.dart` (new): `CustomerCard` ("Pelanggan": name, subscription, the number as text, "Telepon" -> `tel:`, "WhatsApp" -> `https://wa.me/<phone_e164 without +>` only when `phone_e164` is set, then "Alamat pemasangan" (or "Lokasi kerja" when the SPK has no subscription) with the site and its "Buka di Maps"; without a customer only the place, titled "Lokasi kerja"). `PopCard` ("POP / gudang") when `pop` is present, with its own "Buka di Maps". Both Maps links are `mapsUri` of their own place (coordinates, else address text). `ContactActions` gained an optional `whatsapp`; the ticket screen still uses it unchanged. `whatsappUri` in `external_links.dart`.
- Beranda: the next-visit card already read `site` from the SPK detail, so it follows the server's fix (installation address) with no code change; a new test proves the distance and "Navigasi" ignore `pop`.
- ARB (`app_id.arb`, `flutter gen-l10n`): `actionEnRoute`, `enRouteConfirmTitle/Body`, `actionSubmit` (changed), `tripTitle`, `tripRework`, `tripState*`, `tripStepSemantics`, `contactWhatsApp`, `siteInstall`, `popTitle`, `statusCancelled`.
- Tests: new `test/trip_stepper_test.dart` (31 tests: times per step, reached-without-time, screen-reader labels, not begun, terminal step, rework badge on and off, cancelled; the action per step as a table; berangkat confirm / cancel / permission denied / retry reuses key and fix / Mulai does not reuse berangkat's fix / Mulai without the trip / offline / 422 reload; Pelanggan with the three links, no WhatsApp number, no phone, address without coordinates, no subscription, no customer; POP card and its own link; 200% text). `spkDetail` in `test/support/spk_harness.dart` carries `steps/current/en_route/site/pop/customer` (`tripSteps`, `currentStepOf` helpers). Existing tests moved to the new submit label; `work_test.dart` detail test renders the new keys; `home_test.dart` +1.
- Integration (written, **not run**): `journeys_test.dart` journey 1 is now login -> SPK -> **Saya berangkat** (confirm, one keyed POST with `{check_in: {unavailable_reason: no_fix}}`) -> **Mulai** -> 2 photos -> declare -> **Selesai dipasang · kirim untuk review**; `SpkServer` answers `en-route` (`en_route` pseudo-status = assigned + on the way); fixtures `spk_21_assigned` (`en_route: true`), new `spk_21_en_route`, `pop`, `phone_e164`, steps for every SPK fixture; `fixture_contract_test.dart` checks steps (only reached steps carry a time), `current`, the en-route rule, `pop`, `phone_e164`; `screens.dart` +"Detail SPK (dalam perjalanan)"; `accessibility_test.dart` bar tests (56 dp, in view) for berangkat + Mulai, Mulai after the trip, and the submit label.

**Evidence** (in `inbils-mobile/technician`, Flutter 3.47.7).

- `dart format --set-exit-if-changed .` -> `Formatted 127 files (0 changed)`. `flutter analyze` -> `No issues found!`.
- `flutter test` -> `+277: All tests passed!` (the host run includes `test/accessibility_contrast_test.dart`, which walks every top-level screen of `integration_test/screens.dart`, so the stepper and both cards pass the text-contrast guideline on "Detail SPK (Mulai)", "(dalam perjalanan)" and "(dikerjakan, 2 foto)").
- `integration_test/fixture_contract_test.dart` -> `+41: All tests passed!`, run through a temporary one-line wrapper in `test/` (deleted): with the emulator attached, plain `flutter test integration_test/...` builds for it (no flavor) and fails with "Gradle build failed to produce an .apk file".
- `flutter build apk --debug --flavor dev --dart-define-from-file=env/dev.json` -> `✓ Built build/app/outputs/flutter-apk/app-dev-debug.apk`.
- Looks: rendered the SPK detail with the real Figtree and icon fonts in a temporary golden test (deleted) at 420 dp, and at 200% text; found and fixed a connector line with zero height and a current step in a smaller type size than its neighbours.
- Tests were written after the code in the same pass: no red run captured.

**Not verified / risks.**

- The journey and the device accessibility / 200% text / docs-screenshot tests did not run (no device runs). Not run against the live API either: the models were checked against `docs/api/mobile-v1.openapi.json` and the contract in ticket 01, not against a response.
- `tel:` and `wa.me` open through the same `url_launcher` seam as the Maps link (`launchUrl`, external application; the widget tests record the URI, nothing launches). [INFERENCE] a phone with WhatsApp opens the chat and one without shows the browser page; the "Tidak ada aplikasi" snackbar appears only when nothing handles the link. Not tried on a device.
- The submit label is now "Selesai dipasang · kirim untuk review" for every SPK type (F3), also a maintenance SPK.
- Regenerated `router.g.dart` and `work_order_repository.g.dart` changed only their `_$…Hash` line (build_runner output; both apps share `packages/inbils_core`, whose export list the customer lane is editing).
- The stepper widget lives in `technician/` (the lane forbids touching core for this ticket); ticket 05 builds its own for the customer app. If the two should be one, moving it to `inbils_core` is a small follow-up.
