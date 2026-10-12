# MOBILE: technician app release build (owner-held signing key, versioning, prod AAB, Play internal testing)

Status: in-progress (non-secret half done 2026-10-12; waits on the owner's upload key, HTTPS hosts, brand icons and Play answers)
Role: mobile (signing and upload steps run by the owner)
Blocked by: 09
Cross-lane blocked by: mobile-api 09 (security review APPROVED); a public HTTPS API host for staging and prod
Card: none (new lane; no Hermes card)
Skills: web-security-review (release hygiene), ponytail, caveman

## Scope

Implements Q11, plus the inherited signing and target rules (customer Q16, Q17). Never create, copy, read back or commit a keystore or its passwords.

- Release signing in `android/app/build.gradle.kts`: the same pattern as customer ticket 08. It reads `android/key.properties` only when present, and fails a release build without it, never falling back to the debug key. This app has its own upload key, separate from the customer app's.
- The owner generates the key with `keytool` outside the repo, keeps an offline backup, enables Play App Signing at the first upload, and writes `key.properties` locally. The commands are given as text; the agent does not run them.
- `version: 1.0.0+1`. `versionCode` increments with every upload.
- `env/staging.json` and `env/prod.json` get the real HTTPS hosts from the owner (the core https guard refuses `http://`).
- Launcher icons and the name "Inbils Teknisi" from the brand assets, as an adaptive icon in the `mipmap-*` folders.
- Build `flutter build appbundle --flavor prod --release --dart-define-from-file=env/prod.json`. Verify `targetSdk` 36.
- Play internal testing checklist in a ticket comment, filled with the owner's answers: the testers list (staff Google accounts), the privacy policy (cached customer data, photos, foreground location at SPK check-in), the Data safety answers (photos, customer contact data shown and cached, approximate/precise location collected in the foreground at check-in only, per ticket 11), and the rollout notes in Indonesian for technicians (how the upload queue works, and not to log out with queued photos).

Non-goals: public listing, CI, iOS, MDM.

## Owned files

- `../inbils-mobile/technician/android/app/build.gradle.kts`, `../inbils-mobile/technician/android/app/src/main/res/mipmap-*/**`, `../inbils-mobile/technician/android/app/src/main/AndroidManifest.xml` (label and icon only)
- `../inbils-mobile/technician/pubspec.yaml` (version), `../inbils-mobile/technician/env/staging.json`, `../inbils-mobile/technician/env/prod.json`, `../inbils-mobile/technician/README.md`

## Acceptance criteria

- [ ] With no `key.properties`, the release build fails with the documented message. The owner's run produces `app-prod-release.aab` signed with the technician upload certificate (verification output pasted by the owner, no secrets).
- [x] `key.properties`, `*.jks` and `*.keystore` are ignored (`git check-ignore` output), and no password value appears in the repo.
- [x] The merged release manifest shows `targetSdkVersion 36`, no cleartext permission, `allowBackup=false`, the app id `id.inbils.technician`, no CAMERA and no `ACCESS_BACKGROUND_LOCATION`. Foreground `ACCESS_FINE_LOCATION`/`ACCESS_COARSE_LOCATION` are expected since the owner-approved GPS check-in (ticket 11) and must be declared in Data safety and the privacy policy (amended 2026-10-12; the earlier "no location permission" predates ticket 11).
- [ ] The release build installed through `bundletool` passes the ticket 09 live smoke against staging, including the airplane-mode upload queue. Recorded as seen.
- [ ] The internal testing checklist is complete. Missing owner inputs are listed as blockers, not guessed.

## Comments

- 2026-10-12 (agent `ReleaseHygiene`, mobile): non-secret half, same pattern as customer ticket 08 with this app's own `android/key.properties`. Release without the file → `bundleProdRelease` fails with "Release signing needs android/key.properties … Refusing to sign a release with the debug key."; debug/profile builds still build. README `## Release` added. `git check-ignore`: `technician/android/.gitignore:12:key.properties`, `.gitignore:2:*.jks`, `.gitignore:3:*.keystore`. Merged `processProdReleaseManifest`: `id.inbils.technician`, minSdk 24, targetSdk 36, `allowBackup=false`, no cleartext, permissions `INTERNET`, `ACCESS_FINE_LOCATION`, `ACCESS_COARSE_LOCATION`, AndroidX `DYNAMIC_RECEIVER_NOT_EXPORTED_PERMISSION`; no CAMERA, no background location. `flutter analyze` clean; `flutter test` technician 173 passed.
- 2026-10-12 follow-up: same `storeFile` outside-the-repository guard as customer ticket 08 (fake `storeFile=release.jks` → `./gradlew -q help` exit 1 with the message; `/tmp` path → exit 0; fake file deleted). The only `storePassword` grep hit is the `key("storePassword")` lookup; no key files are tracked. Security review: SIGNED (twice).
- Owner blockers (not guessed): technician upload key + `key.properties` and the signed-AAB verification output; real HTTPS hosts; brand icons; testers list, privacy policy (including foreground location at check-in), Data safety answers; bundletool install + live staging smoke incl. airplane-mode upload queue.
