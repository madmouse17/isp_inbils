# MOBILE: network iconography and simple elegant motion

Status: done
Role: mobile
Blocked by: push-notifications 02 (same customer files); 09 (speed fields in the API)
Spec: `../spec.md` (owner 2026-10-16: "apakah pada UI mobile sudah menjadi UI modern dan ada icon terkait jaringan biar lebih friendly dan modern, dan juga kalau bisa kasih animasi simple namun elegan"; assistant recommendation accepted)

## Scope

Repo `/home/madmouse/Documents/web/inbils-mobile`, both apps + `inbils_core` widgets. Packages: Flutter built-ins first; a lightweight package is allowed only where it saves real code and is reported to the owner before commit (accepted candidates: `flutter_svg` for vector illustrations, `lottie` for small JSON animations; BSD/Apache). Illustrations only from free-licensed sources (unDraw MIT, Material/Lucide icons) with a licence note in the repo. Core motion (`AnimatedSwitcher`, `AnimatedScale`, `AnimatedOpacity`, `TweenAnimationBuilder`, `Hero`, `CustomTransitionPage` + `FadeTransition` in go_router; NOT the `animations` package).

1. Network iconography (Material Symbols rounded already bundled): `wifi`/`wifi_off` on the hero service state and the online/offline line, `router` for POP/NAS, `cell_tower` for sites, `cable` for installation, `speed` for package speed, `signal_cellular_alt` for connection, `receipt_long` stays for bills; empty states use a tinted network icon; Beranda tab icon `wifi_tethering` (selected filled). Splash lockup keeps the signal glyph.
2. Motion, every animation ≤ 300 ms, standard easing, all disabled when `MediaQuery.disableAnimations` is true:
   - hero enters with fade + 12 px upward slide on first build of Beranda;
   - amount due counts up (`TweenAnimationBuilder<double>` with `formatRupiah`) on first appearance only;
   - Online dot pulses slowly (1.6 s loop, opacity 0.5–1, stops on reduce motion);
   - status chips crossfade on change (`AnimatedSwitcher`);
   - tab/page transitions fade (go_router `CustomTransitionPage` with `FadeTransition`, 200 ms);
   - primary buttons scale to 0.97 while pressed (`AnimatedScale`);
   - loading states use a skeleton shimmer built with `AnimatedBuilder` over a gradient (no `shimmer` package) instead of a spinner on Beranda, Tagihan, Kunjungan.
3. Speed hero (owner 2026-10-16: "logo besar seperti rpm ada keterangan berapa mbps"): a speedometer-style arc meter on the hero (`CustomPainter`), large download Mbps number with a small unit, a second line for upload, arrows for each direction; the needle animates from 0 to the value when Beranda opens (skipped under reduce motion); data from the new `package.download_mbps`/`upload_mbps`/`speed_label` fields (ticket 09); hidden when absent.
4. Technician app: same icon set and the same hero entrance + press feedback; no count-up.
5. Customer information completeness (same owner question): subscription card + detail show download/upload (speed meter + arrows), speed label, SLA tier promise ("Respons ≤ 4 jam, selesai ≤ 24 jam"), PPPoE username with copy button (never the password), connection status + suspension reason (not only on the hero), installation address with a static map preview via "Buka di Maps" (no map SDK), `suspended_at`; invoice detail shows billing period, per-line PPN/discount, payment reference + method label; ticket detail shows priority, created/resolved dates, "Dijawab sebelum …" / "Diselesaikan sebelum …" countdowns from the deadlines, handler first name. Data from ticket 09 part 2; every field hidden when absent.
6. Payment: invoice detail shows the latest attempt status ("Menunggu pembayaran", "Kedaluwarsa", "Lunas") and re-fetches when the app returns to the foreground after Bayar (`AppLifecycleListener`); paid invoices get "Unduh bukti bayar" (PDF from the receipt endpoint, opened with the platform share/open sheet). Payment stays in the external browser (Midtrans Snap redirect).
7. Tests: a widget test per animation asserting it completes within the budget and is skipped under reduce motion; icon tests only for the hero state mapping.

## Acceptance criteria

- [x] `flutter analyze`/`flutter test` green in core + both apps; contrast unchanged (run of 2026-10-17, see Comments).
- [x] A 10-second screen recording of Beranda (light) attached under `docs/user-guide/images/customer/redesign/motion.webm` from the AVD. Recorded 2026-10-17 on `inbils_pixel7` (10.2 s, VP9 720×1600, 428 KB: splash, skeleton shimmer, hero entrance with needle sweep and amount count-up, pulse dot, scroll); see push ticket 03 Comments for the run.

## Comments

### MobileMotion2 report, 2026-10-17

Repo `inbils-mobile`, nothing committed. The earlier agent's uncommitted work (motion widgets, `SpeedMeter`, `ConnectionLine`, `InbilsIcons`, fade routes, model fields, l10n) was kept; no checkout, stash or reset.

#### Test failures, fixed at the root

At the start: core `+472` green, technician `+286` green, customer `+402 -3`.

- `customer/test/home_motion_test.dart` (2 tests): the fixture had no `GET customer/invoices/12`, so the hero never got its unpaid invoice (no `AmountText`, no Bayar). Route added; assertions untouched.
- `customer/test/push_test.dart`: **red at HEAD too** (checked in a detached worktree of `2a5834b`, removed afterwards), so not a motion regression. After a push, go_router leaves `routeInformationProvider` on the page under it (`/home`), so the old assertion could not hold. The earlier "never-answering invoice" fixture then made `pumpAndSettle` time out on the skeleton sweep. The 404 fixture is back and the assertion reads the real thing: the top match is the pushed `ImperativeRouteMatch` `/invoices/12`, with `/home` under it.
- Two assertions the earlier agent had loosened are strict again, by fixing the code, not the test: `subscriptions_test.dart` (`'Aktif'` and `'Menunggu'` were allowed twice because the detail repeated its status chip; the repeat is gone) and `home_test.dart` (the connection line has its own wifi icon, asserted on `ConnectionLine`; hidden lines have no icon and no `PulseDot`).

#### Audit against the ticket: what was missing, now done

- **Press feedback on every primary button** (item 2). Only 5 of 40 `FilledButton`s were wrapped. Now every one is in `PressScale`: core 12 (login, two-factor, forgot, reset sheet, profile, change, devices, account menu, empty state), customer 4, technician 19.
- **Bantuan skeleton**: the tickets list had its own still grey boxes; it uses `SkeletonLoading`/`SkeletonBlock` like Beranda, Tagihan and Kunjungan (those two already go through `AsyncBody`). Its empty state uses the network `InbilsIcons.empty`.
- **Chips crossfade in the real payment flow** (item 2/6): when the invoice turned paid, the due banner above left the list, every unkeyed child shifted and the chips were rebuilt, so nothing faded. The summary card and the attempt card now have keys; a test watches the old tags stay while the new ones fade in.
- **Subscription detail**: the status chip was shown twice; it is once, the "Layanan" section holds the suspension text with `suspended_at`, connection, PPPoE and SLA, and is left out when the server told none of it. The address heading was repeated; the map preview is its own image node for a screen reader. Two unused l10n keys removed, `flutter gen-l10n` rerun.
- Already in the tree and now covered by tests: SLA promise, PPPoE copy, Maps link, connection line, billing period, per-line PPN and discount, payment reference and method label, attempt status, return-to-foreground re-read (`AppLifecycleListener`), "Unduh bukti bayar" (receipt endpoint with the bearer token and `Accept: application/pdf`, then the share sheet), ticket priority, dates, deadlines with countdown, handler.

#### Tests added

Customer `+405` → `+475`: `subscriptions_test.dart` (speed, SLA, PPPoE, suspension, connection, address and Maps), `invoices_payments_test.dart` (period, PPN and discount, reference, every attempt status, pending re-read with crossfade, receipt: share, no receipt for unpaid or cancelled, in-flight tap, 500, offline, failing share sheet), `tickets_test.dart` (priority, dates, countdowns and tones, resolved and closed, handler, absence, 200 % text on 360 dp), `home_motion_test.dart` (hero entrance and needle from the first frame, amount counting up from Rp 0, reduce motion). Technician `+286` → `+291`: `home_test.dart` group "Beranda: motion" (entrance, reduce motion, press scale, none under reduce motion, no count-up). Core is unchanged at `+472`; its motion tests already assert the 300 ms budget and the reduce-motion path per widget. New tests use timestamps without an offset, so the phone's time zone cannot move them.

#### Packages

- Added: `share_plus` ^13.3.1 in `customer/pubspec.yaml` (locked 13.3.1, **BSD-3-Clause**) with its transitive `share_plus_platform_interface` 7.2.0 (**BSD-3-Clause**); the lock gained these two entries only. Why: the share and open sheet for the receipt PDF; a file URI needs a `FileProvider` and a platform channel otherwise. The merged debug manifest holds `id.inbils.customer.dev.flutter.share_provider`. Owner may veto before commit.
- Not added: `flutter_svg`, `lottie`, any illustration (so no licence note). Documented in `README.md` ("Motion and network icons").

#### Verified

- `dart format --set-exit-if-changed .` → `Formatted 389 files (0 changed)`.
- `flutter analyze` in `packages/inbils_core`, `customer`, `technician` → `No issues found!` each.
- `flutter test`: core `+472: All tests passed!`, customer `+475: All tests passed!`, technician `+291: All tests passed!`.
- `customer/integration_test/fixture_contract_test.dart` (host only, via a temporary copy under `test/`, deleted) → `+36: All tests passed!`; `dart run build_runner build` in customer left every generated file byte-identical.
- `flutter build apk --debug --flavor dev --dart-define-from-file=env/dev.json` in `customer` and in `technician` → `✓ Built build/app/outputs/flutter-apk/app-dev-debug.apk` each.

#### Not verified / risks / decisions

- **No recording and no device run** (not allowed in this run): the second acceptance box stays open for ticket 07. Share sheet and Maps are exercised through fakes; the build proves the manifest merge, not the sheet.
- The "static map preview" is a painted tile with a pin, the coordinates and "Buka di Maps": no tile server, no key, and the customer's coordinates go nowhere until the customer opens Maps. A real tile image is a separate owner decision.
- `Icons.wifi_off` (not rounded) stays on the phone-offline banners: it means "this phone has no signal", existing tests in four files assert it, and the ticket's wifi icons are for the service line.
- `PressScale` also wraps `FilledButton`s inside dialogs.
- Eight customer l10n keys were already unused before this ticket (`accountCode`, `accountName`, `accountStatus`, `invoiceRemaining`, `ticketsTitle`, `ticketAttachmentMeta`, `subscriptionPackage`, `subscriptionCategory`); left alone.

#### Needs change outside my files

- none
