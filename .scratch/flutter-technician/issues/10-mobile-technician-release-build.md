# MOBILE: technician app release build (owner-held signing key, versioning, prod AAB, Play internal testing)

Status: ready-for-agent
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
- Play internal testing checklist in a ticket comment, filled with the owner's answers: the testers list (staff Google accounts), the privacy policy (cached customer data, photos), the Data safety answers (photos, customer contact data shown and cached, no location collected), and the rollout notes in Indonesian for technicians (how the upload queue works, and not to log out with queued photos).

Non-goals: public listing, CI, iOS, MDM.

## Owned files

- `../inbils-mobile/technician/android/app/build.gradle.kts`, `../inbils-mobile/technician/android/app/src/main/res/mipmap-*/**`, `../inbils-mobile/technician/android/app/src/main/AndroidManifest.xml` (label and icon only)
- `../inbils-mobile/technician/pubspec.yaml` (version), `../inbils-mobile/technician/env/staging.json`, `../inbils-mobile/technician/env/prod.json`, `../inbils-mobile/technician/README.md`

## Acceptance criteria

- [ ] With no `key.properties`, the release build fails with the documented message. The owner's run produces `app-prod-release.aab` signed with the technician upload certificate (verification output pasted by the owner, no secrets).
- [ ] `key.properties`, `*.jks` and `*.keystore` are ignored (`git check-ignore` output), and no password value appears in the repo.
- [ ] The merged release manifest shows `targetSdkVersion 36`, no cleartext permission, `allowBackup=false`, the app id `id.inbils.technician`, and no CAMERA or location permission.
- [ ] The release build installed through `bundletool` passes the ticket 09 live smoke against staging, including the airplane-mode upload queue. Recorded as seen.
- [ ] The internal testing checklist is complete. Missing owner inputs are listed as blockers, not guessed.

## Comments
