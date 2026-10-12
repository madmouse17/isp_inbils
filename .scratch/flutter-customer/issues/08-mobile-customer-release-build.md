# MOBILE: customer app release build (signing from an owner-held key, versioning, prod AAB, Play prerequisites)

Status: in-progress (non-secret half done 2026-10-12; waits on the owner's upload key, HTTPS hosts, brand icons and Play answers)
Role: mobile (signing steps run by the owner)
Blocked by: 07
Cross-lane blocked by: mobile-api 09 (security review APPROVED); a public HTTPS API host for staging and prod
Card: none (new lane; no Hermes card)
Skills: web-security-review (release hygiene), ponytail, caveman

## Scope

Implements Q10, Q16 and Q17 for release. Never create, copy, read back or commit a keystore or its passwords.

- Release signing in `android/app/build.gradle.kts`:
  - It reads `android/key.properties` (`storeFile`, `storePassword`, `keyAlias`, `keyPassword`) only when the file exists, and `storeFile` points outside the repo.
  - Without the file, a release build fails with a clear message instead of signing with the debug key, so an unsigned or debug-signed release AAB can never be produced.
- The owner (not the agent) generates the upload key with `keytool` outside the repo, keeps an offline backup, enables Play App Signing at the first upload, and writes `key.properties` locally. The ticket gives the exact commands as text; the agent does not run them.
- Versioning: `version: 1.0.0+1` in the app's `pubspec.yaml`. `versionCode` increments with every upload (documented in the README).
- `env/staging.json` and `env/prod.json` get the real HTTPS hosts from the owner. The ticket 01 config already refuses `http://` for these flavors.
- Launcher icons and the app name from the owner's brand assets, placed into the `mipmap-*` folders (adaptive icon), with no generator dependency.
- Build: `flutter build appbundle --flavor prod --release --dart-define-from-file=env/prod.json`. R8 stays on (the Flutter default). Verify `targetSdk` 36 and the minSdk value.
- Play prerequisites checklist in a ticket comment, filled with the owner's answers: account type (Q16), privacy-policy URL, Data safety, content rating, closed-testing plan (12 testers × 14 days when the account is personal), store listing text in Indonesian and screenshots from ticket 07.

Non-goals: CI signing, iOS, uploading to Play on the owner's behalf.

## Owned files

- `../inbils-mobile/customer/android/app/build.gradle.kts`, `../inbils-mobile/customer/android/app/src/main/res/mipmap-*/**`, `../inbils-mobile/customer/android/app/src/main/AndroidManifest.xml` (label and icon only)
- `../inbils-mobile/customer/pubspec.yaml` (version), `../inbils-mobile/customer/env/staging.json`, `../inbils-mobile/customer/env/prod.json`, `../inbils-mobile/customer/README.md`

## Acceptance criteria

- [ ] With no `key.properties`, `flutter build appbundle --flavor prod --release …` fails with the documented message. With the owner's file present, the owner's run produces `app-prod-release.aab`, and `jarsigner -verify` (or `apksigner` on a universal APK) shows the upload certificate, not the debug one. The owner pastes the output; no secret goes in the comment.
- [x] `git status` and `git check-ignore` confirm `key.properties`, `*.jks` and `*.keystore` are ignored, and a grep for `storePassword` with a value finds nothing in the repo.
- [x] The merged release manifest shows `targetSdkVersion 36`, no cleartext permission, `allowBackup=false` and the release app id `id.inbils.customer`.
- [ ] The release AAB installs on the AVD through `bundletool` (universal APK) and the smoke flow from ticket 07 passes against staging. Recorded as seen.
- [ ] The Play prerequisites checklist is complete, and items still waiting on the owner are listed as blockers, not guessed.

## Comments

- 2026-10-12 (agent `ReleaseHygiene`, mobile): non-secret half. `android/app/build.gradle.kts` reads `android/key.properties` only when present; without it a `gradle.taskGraph.whenReady` check fails every `bundle|assemble|package|sign|validateSigning…Release…` task with "Release signing needs android/key.properties (owner-held upload key, see README). Refusing to sign a release with the debug key." (`flutter build appbundle --flavor prod --release --dart-define-from-file=env/prod.json` → exit 1 with that message). Debug and profile builds still build without a key. README `## Release`: owner `keytool` commands as text, key stored outside the repo, offline backup, Play App Signing, versionCode rule, bundletool smoke. `version: 1.0.0+1` was already set. `git check-ignore`: `customer/android/.gitignore:12:key.properties`, `.gitignore:2:*.jks`, `.gitignore:3:*.keystore`. Merged `processProdReleaseManifest`: `id.inbils.customer`, minSdk 24, targetSdk 36, `allowBackup=false`, no cleartext, permissions `INTERNET` + AndroidX `DYNAMIC_RECEIVER_NOT_EXPORTED_PERMISSION`. `flutter analyze` clean; `flutter test` customer 113, core 224 passed.
- 2026-10-12 follow-up: `storeFile` is canonicalized and must lie outside the repository (scope line 15); a temporary fake `key.properties` with `storeFile=release.jks` makes `./gradlew -q help` exit 1 with "android/key.properties storeFile must point outside the repository (see README).", a path under `/tmp` configures (exit 0); the fake file was deleted. `git grep -nE 'storePassword\s*=\s*\S'` matches only `storePassword = key("storePassword")` in `build.gradle.kts` (a property lookup, no value); `git ls-files` tracks no `key.properties`, `*.jks` or `*.keystore`. Security review of the signing changes: SIGNED (twice).
- Owner blockers (not guessed): upload key + `key.properties` and the signed-AAB verification output; real HTTPS hosts for `env/staging.json` and `env/prod.json` (prod is still `https://api.inbils.example`); brand icons; Play account type, privacy-policy URL, Data safety, content rating, store listing; bundletool install + staging smoke after the host exists.
