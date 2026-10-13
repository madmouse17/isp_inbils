# MOBILE: customer Home hero, quick actions, bill states, connection, restyled screens

Status: done
Role: mobile
Blocked by: 03; 01 for branding/connection fields
Spec: `../spec.md`

## Scope (owned files, repo `inbils-mobile/customer`)

Home per spec M4/V3/V4/V8 (hero per subscription with page dots when several, quick actions with Hubungi ISP via `url_launcher` tel/WhatsApp, Sedang berjalan section, slots for announcements/carousel left out until `announcements-promo` lands); invoices list/detail with amount hierarchy and state chips; subscriptions list/detail with package, address, billing day, contract; notifications; Akun with theme mode and ISP contact card; bottom tabs Beranda/Tagihan/Bantuan/Akun; launcher icon + splash (V9, dev deps). Models updated for the new `me.company` and connection fields. Folded in from `package-categories` 04: subscription model gets optional `package.category {id, name}` (tolerate absence), shown as "Kategori" on subscription list and detail. Widget tests per spec (hero states, text scale 2.0).

## Acceptance criteria

- [x] `flutter analyze` clean; `flutter test` green; debug APK builds. (Screenshots on the AVD are ticket 07; host-rendered PNGs of Beranda, Tagihan, invoice, Akun, Langganan and Riwayat pembayaran were read in light and dark, several brand colours, 200 % text.)

## Comments

### MobileCustomerHome report, 2026-10-14

Repo `inbils-mobile`, nothing committed. Core: Main allowed `packages/inbils_core/lib/src/models/me.dart` (+ regenerated `me.freezed.dart`, `me.g.dart`, `test/models/models_test.dart`) for this wave: `CompanyRef` now has nullable `logoUrl`, `brandColor`, `phone`, `whatsapp`, `email`, `website`, `supportHours`. No other core file.

#### Done

- **Beranda** (`lib/features/home/`): `HeroSection` with one `HeroCard` per live subscription (swipe sideways, dots, none for a single service); status chip Aktif / Diisolir / Ditangguhkan / Menunggu; package name opens the subscription; connection line "Online sejak 08.12" / "Offline, terakhir online 07.55" only when `GET customer/subscriptions/{id}/connection` answers (404, `data: null`, unknown status, 5xx or no signal hide it; asked for active and suspended services only); bill states per V4 (Lunas: "Tagihan berikutnya {tanggal}", due in 1–7 days warning "Jatuh tempo {n} hari lagi", today, overdue danger "Lewat {n} hari", isolir with cause and "Layanan aktif kembali otomatis setelah pembayaran diterima."); large Bayar (opens the invoice, "Lihat tagihan" when it cannot be paid online) with the V8 line "QRIS · VA bank · e-wallet · minimarket" (`PayMethodsHint`, generic icons); `QuickActionGrid` Bayar / Laporkan gangguan / Riwayat pembayaran / Hubungi ISP (sheet with WhatsApp `https://wa.me/<62…>`, `tel:`, `mailto:`, website, support hours through `urlLauncherProvider`; without any channel it points to Bantuan); "Sedang berjalan" (first 3 active tickets, Lihat semua → Bantuan); every block loads/fails/retries on its own, pull to refresh reads all again.
#### Verified

- `dart format --set-exit-if-changed lib test integration_test` (customer) → 88 files, 0 changed.
- `flutter analyze` in `customer`, `packages/inbils_core`, `technician` → "No issues found!" each.
- `flutter test` in `customer` → `+240: All tests passed!`; in core (after the `me.dart` change) → `+393: All tests passed!`.
- `flutter build apk --debug --flavor dev --dart-define-from-file=env/dev.json` → `✓ Built build/app/outputs/flutter-apk/app-dev-debug.apk`.
- Host-rendered PNGs with Figtree and the Material icon font (scratch test, deleted): paid, due soon, overdue, isolir, suspended, two services (pager), empty, 200 % text on 360 dp, light and dark, blue, orange and teal brand; Tagihan, invoice detail, Akun, Langganan list and detail, Riwayat pembayaran. Two layout bugs found and fixed that way (ticket cards not full width, InfoRow columns centred).

#### Not verified / risks

- No device run (ticket 07); integration tests and `technician` tests not run by me.
- The icon was drawn twice: the first version (white "in" on a blue rounded square) is **LinkedIn's logo**. The shipped one adds the HeroCard wave in a darker blue; checked by pixel sampling and by reading the first version, **not read visually after the wave** (the image reader kept returning the old file). Owner decision on the monogram, and look at it on the AVD.
- **Isolir is derived**: the customer API has no suspension source, so a suspension counts as isolir when an unpaid invoice is overdue; a manual suspension while an unrelated invoice is overdue shows "Diisolir" with the auto-reactivation promise, which is false for it. `needs-change: web customer subscription resource — expose the suspension source (or an isolir flag)`; `heroStatusOf` is the single place to switch.
- **Invoices carry no subscription id** in the customer API, so with several services every hero shows the same earliest unpaid invoice (with its number); paid heroes use their own next bill date.
- Package category is not on the hero, only on Langganan list and detail (ticket text).
- The dev dependencies pulled `xml` 7.1.0 → 6.6.1 (and `cli_util` 0.6.0 → 0.4.2) in the shared `pubspec.lock`, which `technician` and core resolve too.
- Pager pages keep their own height (top aligned) so large text never clips a hero.
- Selected `SegmentedButton` / nav indicator colours stay the design-system ones (design-system note: vivid under fidelity).

### Follow-up, 2026-10-14: launcher mark replaced (Main's decision)

The "in" monogram is gone (LinkedIn look-alike); the wave version is gone with it. New mark, no letters: a white broadband signal, a dot with three concentric quarter arcs opening up-right, round caps. The earlier note "the image reader kept returning the old file" was a race of mine (the read ran in the same parallel batch as the script that wrote the file), not a cache; this time every file was read in a separate step.

- `customer/assets/branding/`: `icon_legacy.png` (Inbils blue `#2563EB` rounded square, glyph stroke 9 % of the canvas = 92 px), `icon_foreground.png` and `icon_monochrome.png` (glyph inside the 66 dp safe circle of the 108 dp canvas; `flutter_launcher_icons` insets the foreground 16 % per side, so it is drawn 1/0.68 larger and ends at a stroke of 7.1 %), `splash_icon.png` (Android 12), `splash_lockup.png` and `splash_lockup_dark.png` (glyph above "Inbils", Figtree 700, `primary` of the light and dark theme). Adaptive background is the colour `#2563EB`. Old `icon_background.png` and `splash_wordmark*.png` removed.
- `customer/pubspec.yaml` config updated, `dart run flutter_launcher_icons` and `dart run flutter_native_splash:create` rerun (same two dev deps), the stale `ic_launcher_background.png` drawables of the previous design deleted. `customer/README.md` "Launcher icon and splash" rewritten: geometry in stroke units, sizes, colours, why the foreground is drawn larger.
- Seen: a contact sheet built from the shipped resources (mipmap `ic_launcher`, adaptive foreground over the blue colour with the tool's 16 % inset under a circle and a squircle mask at 240 and 48 px, the themed monochrome icon, the Android 12 disc, the light and dark splash lockups on `#FAF8FF` and `#11131B`), rendered to new `/tmp` names and deleted afterwards. The glyph is whole inside both masks; the dot and arcs stay distinct at 48 px.
- `heroStatusOf` stays the single switch point for the isolir flag and the invoice subscription id (web ticket 01).
- Verified: `technician`: `flutter analyze` → "No issues found!", `flutter test` → `+244: All tests passed!` (with the shared lock that downgraded `xml`). `flutter analyze` on my 14 paths (home, branding, invoices, payments, subscriptions, account, shell, clock, six test files) → "No issues found!". `./gradlew :app:processDevDebugResources` (customer/android) → `BUILD SUCCESSFUL`: the new resources link.
- **Not verified after the icon change: `flutter test` and `flutter build apk --debug --flavor dev` in `customer`.** Another agent (ticket 05, Bantuan) is editing `customer/` right now and its files do not compile (`lib/features/help/help_hint_screen.dart` and `lib/features/tickets/ticket.dart` use `customerHint` that `TicketCategory` does not declare yet; `lib/features/tickets/tickets_screen.dart:56` calls `hasContactInfo` without importing `branding/company_contact.dart`), so the whole package fails analysis and every test that starts the app. Last green run, before those edits: `flutter test` `+240: All tests passed!`, `flutter build apk --debug --flavor dev` built the APK (the icon change touches Android resources only, no Dart). Rerun both once that agent's files compile.
