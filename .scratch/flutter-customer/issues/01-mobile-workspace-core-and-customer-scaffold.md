# MOBILE: Flutter workspace, `inbils_core` foundation (client, errors, theme, l10n, widgets), customer app scaffold

Status: done
Role: mobile
Blocked by: none (starts only after the owner approves `../spec.md` Q1-Q17 and `../../mobile-api/spec.md`)
Card: none (new lane; no Hermes card)
Skills: antislop-ui, antislop-human, antislop-layoutmobile, ui-ux-pro-max, tdd, ponytail, caveman (skip the Expo skills: this is Flutter)

## Scope

Implements Q1-Q6, Q8, Q9, Q11, Q12 and Q17 (foundation only, no business screens).

- Workspace:
  - `../inbils-mobile/pubspec.yaml` declares the workspace (`packages/inbils_core`, `customer`; the technician lane adds `technician`), with Dart `^3.13.0`.
  - `../inbils-mobile/analysis_options.yaml` (Q12) and `../inbils-mobile/.gitignore` (Flutter outputs, `.dart_tool/`, `build/`, `**/android/key.properties`, `*.jks`, `*.keystore`, `env/*.local.json`).
  - The workspace is its own git repository (owner change): `git init -b main`, a root `.gitignore`, a root `README.md` and one local initial commit, no remote. The web repo's `.dockerignore` is not touched.
- `inbils_core`:
  - Flavor config (`API_BASE_URL`, `FLAVOR` through `String.fromEnvironment`). It fails fast when a value is missing, and when `staging` or `prod` gets a non-`https://` base URL.
  - `ApiClient` on dio: base options, timeouts, the error interceptor mapping the API envelope to `ApiException` (every code in the spec table, offline, timeout, `Retry-After`), and the logging policy (release: none; dev: method, path, status).
  - Idempotency-key helper: UUID v4 from `Random.secure()`, attached by the caller and kept for retries.
  - Theme tokens and `ThemeData` light and dark from Q9, with Figtree bundled under `assets/fonts` (OFL text included).
  - `gen-l10n` set up with the core ARB holding the error table.
  - Shared widgets: `AsyncBody`, `StatusChip`, `FieldErrorText`, money and date formatters for `id_ID`.
- Customer app:
  - `flutter create --platforms=android` as `inbils_customer`; `namespace` and `applicationId` `id.inbils.customer` (Q10, pending the domain confirmation).
  - Product flavors `dev`, `staging`, `prod` with the suffixes `.dev` and `.staging` and app names "Inbils Dev", "Inbils Staging", "Inbils".
  - `env/dev.json` (`API_BASE_URL=http://localhost:8080`, `FLAVOR=dev`), `env/staging.json` and `env/prod.json` holding `https://` placeholder hosts the owner replaces in ticket 08. These are not secrets; record them in the README.
  - A dev-only network security config allowing cleartext for `localhost`, `127.0.0.1` and `10.0.2.2`. `android:allowBackup="false"`. `targetSdk` 36, `minSdk` = the Flutter default.
  - `MaterialApp.router` with Riverpod `ProviderScope` (retry policy: never retry a 4xx `ApiException`) and `go_router` holding one real screen, `/account/about` ("Tentang aplikasi": version and build, flavor, API host). It stays in the final app.
  - Replace the generated `README.md` with the exact run commands (`adb reverse tcp:8080 tcp:8080`, `flutter run --flavor dev --dart-define-from-file=env/dev.json`, tests, builds).

Non-goals: auth and session (02), any business screen, signing (08), CI.

## Owned files

- NEW `../inbils-mobile/pubspec.yaml`, NEW `../inbils-mobile/pubspec.lock`, NEW `../inbils-mobile/analysis_options.yaml`, NEW `../inbils-mobile/.gitignore`, NEW `../inbils-mobile/README.md`
- NEW `../inbils-mobile/packages/inbils_core/**` (`pubspec.yaml`, `lib/`, `assets/fonts/`, `test/`)
- NEW `../inbils-mobile/customer/**` (generated project, `android/` flavors and manifests, `env/`, `l10n.yaml`, `lib/main.dart`, `lib/app/`, `lib/features/about/`, `lib/l10n/`, `test/`, `README.md`)

## Acceptance criteria

- [x] `cd ../inbils-mobile && flutter pub get` resolves the whole workspace with one `pubspec.lock`. The resolved versions of every direct dependency are pasted in a ticket comment, all generators on one analyzer version.
- [x] `dart format --set-exit-if-changed .` passes, `flutter analyze` reports 0 issues, and `flutter test` (core and customer) is green, including:
  - error mapping for every spec code, offline, timeout and 429;
  - the idempotency key format;
  - config failing on a missing value and on `http://` for staging or prod;
  - theme token values and the Q9 contrast pairs;
  - `id_ID` money and date formatting;
  - `AsyncBody` and `StatusChip` widget states.
- [x] `flutter build apk --flavor dev --dart-define-from-file=env/dev.json --debug` succeeds. On the AVD `inbils_pixel_api35`, the app installs as `id.inbils.customer.dev` and shows "Tentang aplikasi" with flavor `dev` and host `localhost:8080`. Describe exactly what was seen, with a screenshot path.
- [x] The merged manifest of the `prod` and `staging` variants carries no cleartext permission and no network security config allowing it, and `allowBackup` is false in all variants (`aapt2`/merged-manifest output pasted).
- [x] Dev, staging and prod builds install side by side (different application ids).
- [x] `git status --short` inside `../inbils-mobile/` lists no `build/`, `.dart_tool/` or generated platform caches, and the initial commit holds no keystore, `key.properties`, token or build output.
- [x] No API base URL, host or flavor string is hard-coded in Dart outside `env/*.json`.

## Comments

### Done: mobile agent `FlutterWorkspace`, 2026-10-11

Delivered together with `../../flutter-technician/issues/01-mobile-technician-app-scaffold.md`. Everything is in the separate repository `/home/madmouse/Documents/web/inbils-mobile/` (`../inbils-mobile/`, owner change): branch `main`, one local initial commit, no remote added by this ticket.

**Resolved versions.** One `../inbils-mobile/pubspec.lock` with 175 packages. Flutter 3.47.7, Dart 3.13.5. Every generator resolves on `analyzer` 14.5.0 (`build_runner` 2.16.2, `source_gen` 4.3.0).

- `inbils_core`: flutter_riverpod 3.4.3, riverpod_annotation 4.0.7, go_router 18.0.2, dio 5.11.1, freezed_annotation 3.1.0, json_annotation 4.12.0, flutter_secure_storage 11.2.0, package_info_plus 10.2.2, device_info_plus 13.3.0, intl 0.20.3, material_ui 1.6.0. Dev: build_runner 2.16.2, freezed 4.0.2, json_serializable 6.14.1, riverpod_generator 4.0.9, flutter_lints 6.0.0.
- `customer`: the same Riverpod, go_router, freezed_annotation, json_annotation, intl and material_ui versions, plus url_launcher 6.3.3. Dev: build_runner, freezed, json_serializable, riverpod_generator, flutter_lints and `integration_test` (SDK).
- `technician` adds url_launcher 6.3.3, image_picker 1.2.4, flutter_image_compress 2.5.1, geolocator 14.1.1, drift 2.35.2, drift_flutter 0.3.1 (it pulls sqlite3 3.7.0), path_provider 2.1.6, path 1.9.1; dev: drift_dev 2.35.1.

**Deviations and additions.**

1. Import `package:material_ui/material_ui.dart`, never `package:flutter/material.dart`. Flutter 3.47 moved Material into the `material_ui` package, and `go_router` 18 finds the `MaterialApp` from that package; with the old import it falls back to pages without transitions. The generated `CoreLocalizations.localizationsDelegates` lists the SDK Material delegates, so `InbilsApp` builds its own list with `GlobalMaterialLocalizations.delegates` of `material_ui`. Every later ticket must follow this (the root README says so).
2. "Tentang aplikasi" (`AboutScreen`), the splash screen and the login placeholder are core screens (`packages/inbils_core/lib/src/screens/`), not `customer/lib/features/about/`: both apps show the same screen, and the technician ticket may not edit customer files. Each app only adds routes (`lib/app/router.dart`) and its own ARB (`appTitle`).
3. The About screen and the login placeholder are reachable while signed out, so a tester can read the version, flavor and server host before logging in. The login placeholder says "Layar masuk sedang disiapkan." and already shows why a session ended.
4. Added on the lead's request, beyond the ticket text: `SessionStore` (flutter_secure_storage, token + expiry + app + user id) and `SessionController` (unknown, signedOut(reason), signedIn; local only, no `GET me` or refresh yet), the bearer header and the 401 hook in the API client, `Me`, `Device` and `Paged<T>` models, and `package:inbils_core/testing.dart` (`FakeAdapter`, `jsonBody`, `loadEnvConfig`, `mockPackageInfo`, `mockSecureStorage`). Ticket 02 builds login, 2FA, `GET me` at restore and single-flight refresh on this.
5. `API_BASE_URL` with credentials in it is rejected (env files are committed). A `.dockerignore` entry is not needed: the workspace is not in the web repo.
6. The retry policy `apiRetry` retries only timeouts and 5xx, three times at most; a 4xx and an offline error are never retried, so the offline state shows at once instead of after about 12 s of default retries.
7. Generated code (`*.g.dart`, `*.freezed.dart`, `lib/l10n/generated/`) is committed. Re-running every generator and `dart format` after staging changed nothing.

**Evidence** (all in `/home/madmouse/Documents/web/inbils-mobile`).

- `dart format --set-exit-if-changed --output=none .` gives 80 files, 0 changed.
- `flutter analyze` gives "No issues found!" in `packages/inbils_core`, `customer` and `technician`.
- `flutter test`: core 196 passed, customer 7 passed, technician 7 passed. Core covers error mapping of every code in the spec table plus offline, timeout and 429 `Retry-After`; `Idempotency-Key` format, reuse and exemptions; config failures (missing value, unknown flavor, `http://` for staging and prod, credentials); every theme token and contrast pair (WCAG formula written out in the test); `id_ID` money and dates; `AsyncBody`, `StatusChip` and `FieldErrorText` states including 200 percent text; the session store and controller; the router guard.
- `flutter build apk --debug --flavor <dev|staging|prod> --dart-define-from-file=env/<flavor>.json` succeeded for all three flavors of both apps (`build/app/outputs/flutter-apk/app-<flavor>-debug.apk`).
- AVD `inbils_pixel_api35` (API 35, headless, `adb reverse tcp:8080 tcp:8080`): `app-dev-debug.apk` installed as `id.inbils.customer.dev` and started without a logcat error. It opens on the login placeholder: "Inbils Dev", "Layar masuk sedang disiapkan.", link "Tentang aplikasi". Tapping the link shows the screen "Tentang aplikasi" with Aplikasi `Inbils Dev`, Versi `1.0.0 (build 1)`, ID aplikasi `id.inbils.customer.dev`, Lingkungan `dev`, Server `localhost:8080`, and the row "Lisensi sumber terbuka". Dark mode (`cmd uimode night yes`) shows the dark palette. The prod build shows `Inbils`, `id.inbils.customer`, `prod`, `api.inbils.example`. Screenshots were saved under `/tmp/inbils_*.png`, checked and deleted, as the lead asked; the emulator was killed afterwards.
- Side by side: `pm list packages` showed `id.inbils.customer`, `.customer.dev`, `.customer.staging`, `id.inbils.technician`, `.technician.dev` and `.technician.staging` installed together.
- Merged manifests: `aapt2 dump xmltree` of the six debug APKs and Gradle `process{Dev,Staging,Prod}ReleaseMainManifest` of the release variants. `android:allowBackup="false"` in every variant. `android:networkSecurityConfig` appears in `dev` only; staging and prod have none and no `usesCleartextTraffic`. Only `INTERNET` (plus the AGP receiver permission) is requested.
- `grep` for `http://`, `https://`, `localhost`, `10.0.2.2` and flavor literals in `lib/` finds only the `https://` hint in an `AppConfig` error message. The tests keep their own literals.
- `git status` in `../inbils-mobile`: `build/`, `.dart_tool/`, `local.properties`, `.flutter-plugins-dependencies`, `GeneratedPluginRegistrant.java`, `gradlew` and `gradle-wrapper.jar` are ignored; the staged set has no keystore, `key.properties`, `.env*` or build output.

**Risks and open items.** `flutter_image_compress_common` (technician) still applies the Kotlin Gradle plugin; Flutter warns that a future version will fail to build with such plugins. Release builds use the debug signing key until ticket 08. The launcher icon is the Flutter default until ticket 08. The staging and production hosts are `.example` placeholders. Not run here: `integration_test` on the AVD (ticket 07) and any call to the API (the apps make none yet).
