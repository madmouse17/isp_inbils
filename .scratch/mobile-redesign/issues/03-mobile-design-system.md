# MOBILE: design system in inbils_core (seeded theme, hero, tones, empty states, brand loading)

Status: done
Role: mobile
Blocked by: sla-targets merged (no API dependency)
Spec: `../spec.md`

## Scope (owned files, repo `inbils-mobile`)

`packages/inbils_core/lib/src/theme/**`: `InbilsTheme.fromBrand(Color? seed)` via `ColorScheme.fromSeed` (tonal spot), tinted surfaces, borderless cards, radius scale (8/16/28), typography (Figtree, large numerals), component themes (NavigationBar, ListTile, Chip, FilledButton, SegmentedButton); keep `StatusColors` extension but derive tones that pass AA on the seeded surfaces. Widgets: `HeroCard` (wave `CustomPainter`), `ToneBanner`, `QuickActionGrid`, `EmptyState`, `SectionHeader`, `AmountText`; theme mode controller (Sistem/Terang/Gelap, persisted with the existing storage); brand provider fed from `me.company.brand_color` with fallback. Tests: contrast across 8 presets × 2 modes, invalid colour fallback. Read the flutter-*/dart-* skills (`flutter-build-responsive-layout`, `flutter-add-widget-test`, `dart-add-unit-test`) and `skill://ui-ux-pro-max`, `skill://antislop-ui`.

## Acceptance criteria

- [x] `flutter analyze` clean for core + both apps; `flutter test` green in core.

## Comments

### MobileDesignSystem report, 2026-10-14

Repo `inbils-mobile`, nothing committed. No new package. `customer/` and `technician/` are untouched: both apps start `InbilsApp` of core, which now applies the theme.

#### Done

- `packages/inbils_core/lib/src/theme/inbils_theme.dart` — `InbilsTheme.fromBrand(Color? seed, Brightness)` via `ColorScheme.fromSeed(dynamicSchemeVariant: tonalSpot)`, default seed `#2563EB`. `InbilsRadius.sm/md/lg` = 8/16/28, Figtree with bold tabular numerals (`displaySmall` w700 for the amount due), tinted surfaces, borderless cards on `surfaceContainerLow`, themes for NavigationBar, ListTile, Chip, Filled/Outlined/Text button, SegmentedButton, and an AppBar that takes `surfaceContainer` once content scrolls under it. `InbilsTheme.light()/dark()` and the hand-written web schemes are gone.
- `.../theme/inbils_colors.dart` — `parseBrandColor` (`#RRGGBB` only), `StatusTone` (+ `.icon`), `ToneColors` (`color`, `onColor`, `container`, `onContainer`), `StatusColors.forScheme`: success, warning, danger, info keep a fixed hue per mode (a brand colour never changes what a status means), neutral follows the scheme.
- `.../theme/brand_provider.dart` — `brandSeedProvider`: `Color?` from `me.company.brand_color` once signed in; null (Inbils blue) before login, while `GET me` has not answered, and for a missing or malformed colour.
- `.../theme/theme_mode_controller.dart` — `themeModeControllerProvider` (`ThemeMode`, starts `system`, `notifier.select(mode)`), saved in `flutter_secure_storage` under `inbils.theme_mode` (the storage the session already uses).
- `.../widgets/` new: `hero_card.dart` (`HeroCard`, `HeroTone {brand, warning, danger}`, wave `CustomPainter`), `tone_banner.dart`, `quick_action_grid.dart` (`QuickActionGrid`, `QuickAction`), `empty_state.dart`, `section_header.dart`, `amount_text.dart`. Changed: `status_chip.dart` (tinted tag on the tone container), `async_body.dart` (empty and error states now render `EmptyState`, same texts, icons, retry button).
- `.../app/inbils_app.dart` — `InbilsApp` is a `ConsumerWidget`: `fromBrand(seed, light)`, `fromBrand(seed, dark)`, `themeMode` from the controller.
- `.../models/me.dart` (+ regenerated `me.freezed.dart`, `me.g.dart`) — `CompanyRef.brandColor` only; ticket 04 adds `logo_url`, `phone`, `whatsapp`, `email`, `website`, `support_hours`. `.../screens/devices_screen.dart` imports `StatusTone` from its new file. `inbils_core.dart` exports the new files. `README.md` colour convention updated.
- Tests (core 256 → 376): `theme/theme_test.dart` (contrast matrix: 8 presets plus 8 corner colours `#000000 #FFFFFF #FF0000 #00FF00 #0000FF #FFFF00 #00FFFF #FF00FF` × light/dark; every tone's `color` on 6 surfaces, `onColor` on `color`, `onContainer` on `container`, and the scheme's own pairs, all ≥ 4.5:1, non-text ≥ 3:1; a weakened warning colour made 16 cases fail, then reverted), `theme/brand_test.dart` (parse and invalid fallback, provider states), `theme/theme_mode_test.dart` (persistence across a restart, unknown value, broken keystore, choice before restore), `theme/inbils_app_theme_test.dart` (wiring: brand, malformed, Sistem/Terang/Gelap), one widget test file per new widget (tones, 48 dp, semantics, 200 % text), `status_chip_test.dart` updated.

#### API for tickets 04–06

- `InbilsTheme.fromBrand(seed, brightness)`, `StatusColors.of(context).forTone(StatusTone)` → `ToneColors`, `tone.icon`, `InbilsRadius`, `minTouchTarget`.
- `HeroCard(tone:, semanticsLabel:, child:)`: fill is `primary` / warning / danger; text, icons and a `FilledButton` inside invert to the fill, so build styles from the context under the card. `AmountText('154600.00')` shrinks to one line; a screen reader hears "154.600 rupiah". `StatusChip` inside a `Row` needs `Flexible` when its label is long.
- `QuickActionGrid(actions: [QuickAction(icon:, label:, onTap:)])`: 2 columns on a phone, 4 from 480 dp, one per row above 130 % text.
- `ToneBanner(message:, tone:, title:, actionLabel:, onAction:)`, `EmptyState(icon:, message:, tone:, actionLabel:, actionIcon:, onAction:)`, `SectionHeader(title:, actionLabel:, onAction:)`.
- Theme mode row of Akun: `ref.watch(themeModeControllerProvider)`, `ref.read(themeModeControllerProvider.notifier).select(ThemeMode.dark)`. The labels Sistem/Terang/Gelap are not in the core ARB yet.

#### Verified

- `dart format --set-exit-if-changed lib test` (core) → 115 files, 0 changed.
- `flutter analyze` in `packages/inbils_core`, `customer`, `technician` → "No issues found!" each.
- `flutter test` in `packages/inbils_core` → `+376: All tests passed!`; `customer` → `+124: All tests passed!`; `technician` → `+216: All tests passed!` (includes the existing text-contrast guideline tests on every top-level screen with the new theme).
- Host-rendered screens (Figtree and Material Icons loaded, scratch test since deleted): Home-like page with hero, quick actions, cards, tone banners, empty states, chips, segmented button, nav bar, in light and dark, brand colours blue, teal, orange, pink, and warning and danger heroes. Read as PNG; the quick action tiles were reworked after it showed "pembayara/n" breaking mid-word.

#### Not verified / risks

- No device run on the AVD (ticket 07). The existing screens are not restyled; they only pick up the new theme.
- **Tonal spot desaturates the brand colour** (chroma capped at 36). Light `primary` for the presets: `#2563EB`→`#4B5C92`, `#0F766E`→`#006A63`, `#7C3AED`→`#67548E`, `#DB2777`→`#8C4A5F`, `#EA580C`→`#8E4C32` (brown), `#16A34A`→`#35693E`, `#0891B2`→`#07677F`, `#334155`→`#3A608F`. With `DynamicSchemeVariant.fidelity` the same seeds give `#004AC6`, `#005C55`, `#630ED4`, `#B7005E`, `#A33900`, `#006B2C`, `#00647C`, `#1D2B3E`, all above 4.5:1 with white. Kept tonal spot as specified; the switch is one argument in `InbilsTheme.fromBrand`, but the theme and hero tests would have to be run again.
- The brand colour is not kept between launches: a cold start shows Inbils blue until `GET me` answers (`// shortcut:` comment in `brand_provider.dart`). The saved theme mode has the same few-frame window on the splash screen.
- A signed-in start asks `GET me` twice (the restore in `SessionController` throws its answer away, then `meProvider` fetches). Seeding `meProvider` from the restore would save one request.
- A phone display setting sits in Keystore-backed secure storage: no new package, at the cost of async reads. Swap `ThemeModeStore` if `shared_preferences` is ever accepted.

#### Needs decision or change outside my files

- needs-decision: tonal spot (spec V1, as built) or `fidelity` for the ISP colour; see the numbers above.
- needs-change (ticket 04 and 06 share it): both Akun tabs need the same Sistem/Terang/Gelap `SegmentedButton` with 3 strings; core is the place for one `ThemeModeSelector` plus ARB strings, instead of two copies.

### Follow-up, 2026-10-14: fidelity and ThemeModeSelector (orchestrator decision)

- `InbilsTheme.fromBrand` now builds `ColorScheme.fromSeed(dynamicSchemeVariant: DynamicSchemeVariant.fidelity)`: the ISP colour keeps its hue and chroma. Light `primary` of the presets: `#004AC6`, `#005C55`, `#630ED4`, `#B7005E`, `#A33900`, `#006B2C`, `#00647C`, `#1D2B3E` (tonal spot gave `#4B5C92`, `#006A63`, `#67548E`, `#8C4A5F`, `#8E4C32`, ...). Hero, quick action icons, the nav indicator and links now carry the brand colour; the hero is a solid `primary` with the wave in a shade of it.
- Contrast: the matrix passed under fidelity without changing a tone and without touching an assertion, because the luminance of every surface and of the status tones is fixed by their tone, not by the seed. I added a sweep of the whole colour wheel to `theme_test.dart` (36 hues × vivid, dark, light and washed out, light and dark mode: the status tones on 6 surfaces plus the scheme pairs the widgets use), next to the 8 presets and 8 corner colours.
- One derivation changed, for meaning and not for AA: `StatusColors.neutral` took `secondaryContainer`, which under fidelity is as loud as the brand (salmon for orange). It now takes `surfaceContainerHighest` with `onSurface`, a tinted grey; `theme_test.dart` asserts its colour spread stays under 0.2 for every seed.
- New `ThemeModeSelector` in core (`widgets/theme_mode_selector.dart`, exported): a `SegmentedButton<ThemeMode>` with Sistem / Terang / Gelap and an icon each, reading and setting `themeModeControllerProvider`. ARB `themeModeSystem`, `themeModeLight`, `themeModeDark` in `core_id.arb`, `core_localizations*.dart` regenerated with `flutter gen-l10n`. One row, filling the width, when the labels fit (measured with the real text style and scale); stacked, full-width 48 dp segments when they do not (200 % text, narrow phone). Tickets 04 and 06 only place `ThemeModeSelector()` under their own heading; the heading ("Tampilan") is not in the core ARB.
- Tests: `theme/theme_test.dart` (wheel sweep, neutral grey), `widgets/theme_mode_selector_test.dart` (11: labels and icons, saved choice, tap applies and saves, follows another writer, semantics as a single-choice group, 48 dp, one row versus stacked, 200 % on 320 dp).
- Verified after the change: `dart format --set-exit-if-changed lib test` (core) → 117 files, 0 changed; `flutter analyze` in core, customer, technician → "No issues found!" each; `flutter test` core `+390`, customer `+124`, technician `+216`, all passed.
- Seen on host-rendered PNGs (real Figtree and icons, scratch test deleted): 13 variants, among them blue, orange, pink, teal, violet, green, slate and a pure yellow seed, light and dark; the selector in one row at 360 dp, stacked at 130 % and 200 % text and on 320 dp. Left for the device review (07): selected states of `SegmentedButton` and `FilterChip` use Material's `secondaryContainer`, which is vivid under fidelity (periwinkle for blue, salmon for orange); and the page carries a lot of the brand colour at once (hero, four icon tiles, nav indicator, links), which is bold for a saturated brand colour.
