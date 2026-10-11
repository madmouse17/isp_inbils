# MOBILE: technician app scaffold in the shared workspace (flavors, ids, theme, About)

Status: done
Role: mobile
Blocked by: none inside this lane (starts only after the owner approves `../spec.md`)
Cross-lane blocked by: flutter-customer 01 (workspace and `inbils_core`)
Card: none (new lane; no Hermes card)
Skills: antislop-ui, antislop-human, antislop-layoutmobile, tdd, ponytail, caveman (skip the Expo skills: this is Flutter)

## Scope

The same scaffold as customer ticket 01, for `../inbils-mobile/technician`, reusing everything from `inbils_core`.

- `flutter create --platforms=android` as `inbils_technician`. `namespace` and `applicationId` `id.inbils.technician`, flavors `dev`, `staging`, `prod` (suffixes `.dev`, `.staging`; names "Inbils Teknisi Dev", "Inbils Teknisi Staging", "Inbils Teknisi").
- `env/{dev,staging,prod}.json` (dev `http://localhost:8080`), the dev-only cleartext network security config, `allowBackup=false`, `targetSdk` 36 and the default `minSdk`. All as in the customer app, including the config's https guard from core.
- Add `technician` to the workspace members in `../inbils-mobile/pubspec.yaml` (the only shared-file edit of this lane) and regenerate `../inbils-mobile/pubspec.lock`, following the shared lock-file rule.
- `MaterialApp.router` with `ProviderScope` (the core retry policy) and go_router with the real `/account/about` screen. The app-level ARB is set up.
- README with the exact commands (same pattern as the customer README).

Non-goals: auth wiring (02), any business screen, drift (06), signing (10).

## Owned files

- `../inbils-mobile/pubspec.yaml` (add the `technician` member only, after flutter-customer 01 is done), `../inbils-mobile/pubspec.lock` (regenerated)
- NEW `../inbils-mobile/technician/**` (generated project, `android/` flavors and manifests, `env/`, `l10n.yaml`, `lib/main.dart`, `lib/app/`, `lib/features/about/`, `lib/l10n/`, `test/`, `README.md`)

## Acceptance criteria

- [x] `cd ../inbils-mobile && flutter pub get` resolves all three packages with one lock file.
- [x] `dart format --set-exit-if-changed .`, `flutter analyze` (0 issues) and `flutter test` (all packages) are green.
- [x] `flutter build apk --flavor dev --dart-define-from-file=env/dev.json --debug` succeeds. On the AVD `inbils_pixel_api35`, the app installs as `id.inbils.technician.dev` next to `id.inbils.customer.dev` and shows "Tentang aplikasi" with flavor `dev` and host `localhost:8080`. Described as seen.
- [x] The prod and staging merged manifests have no cleartext permission, and `allowBackup` is false in every variant.
- [x] No file under `../inbils-mobile/packages/inbils_core` or `../inbils-mobile/customer` changed in this ticket.

## Comments

### Done: mobile agent `FlutterWorkspace`, 2026-10-11

Delivered in the same pass as `../../flutter-customer/issues/01-mobile-workspace-core-and-customer-scaffold.md`, whose comment holds the resolved package versions, the deviations (`material_ui` imports, About and splash and login placeholder in core) and the full evidence. The app is `/home/madmouse/Documents/web/inbils-mobile/technician` (`inbils_technician`) in the separate repository `../inbils-mobile/` (owner change), branch `main`, one local initial commit, no remote.

- `technician` is a member of the workspace in `../inbils-mobile/pubspec.yaml`; `flutter pub get` at the root resolves `packages/inbils_core`, `customer` and `technician` with the single `pubspec.lock`.
- The technician pubspec already installs every package the specs list for this lane: url_launcher 6.3.3, image_picker 1.2.4, flutter_image_compress 2.5.1, geolocator 14.1.1, drift 2.35.2, drift_flutter 0.3.1, drift_dev 2.35.1, path_provider 2.1.6, path 1.9.1 (plus the shared Riverpod, go_router, freezed and json set). The debug APK builds with all of them. Later tickets add no dependency for these; they only add code.
- `applicationId` and `namespace` `id.inbils.technician`; flavors `dev`, `staging`, `prod` give `id.inbils.technician.dev`, `.staging` and the plain id, labelled "Inbils Teknisi Dev", "Inbils Teknisi Staging", "Inbils Teknisi". `minSdk` 24 (Flutter default), `targetSdk` and `compileSdk` 36. Env files and the dev-only network security config match the customer app.
- `dart format --set-exit-if-changed --output=none .` gives 0 changed. `flutter analyze` in `technician` gives "No issues found!". `flutter test` in `technician` passes 7 tests (the app boots on the login placeholder, is titled "Inbils Teknisi", About shows `dev` and `localhost:8080` from `env/dev.json`, a signed out user cannot open a private route, the Figtree license is listed, and all three env files are valid configs).
- AVD `inbils_pixel_api35`, installed next to the customer app: `id.inbils.technician.dev` started without a logcat error and opens on the login placeholder "Inbils Teknisi Dev". "Tentang aplikasi" shows Aplikasi `Inbils Teknisi Dev`, Versi `1.0.0 (build 1)`, ID aplikasi `id.inbils.technician.dev`, Lingkungan `dev`, Server `localhost:8080`. Staging and prod builds also started. All six packages (customer and technician, three flavors each) were installed together, then uninstalled.
- Merged manifests (`aapt2 dump xmltree` of the six debug APKs, Gradle `process{Dev,Staging,Prod}ReleaseMainManifest` for release): `android:allowBackup="false"` in every variant; `android:networkSecurityConfig` in `dev` only; no `usesCleartextTraffic`. The manifest requests `INTERNET` only: `geolocator` declares no location permission itself, so ticket 11 must add `ACCESS_FINE_LOCATION` and `ACCESS_COARSE_LOCATION` (and nothing for background location).
- The last criterion holds by construction: the technician app needed no change in `packages/inbils_core` or `customer`. It cannot be shown from git history, because both tickets share the single initial commit.

**Risks.** `flutter_image_compress_common` applies the Kotlin Gradle plugin, and Flutter warns that a future version will fail to build with such plugins. Ticket 06 owns the camera and database code; the drift setup (`drift_flutter`, `sqlite3` 3.7.0 with build hooks, `sqlite3_flutter_libs` 0.6.0+eol as an empty compatibility package) compiled for Android in the debug build here, but nothing opens a database yet.
