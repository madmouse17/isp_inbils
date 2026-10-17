# MOBILE: firebase_messaging in both apps, permission, token registration, foreground display, tap routing

Status: done
Role: mobile
Blocked by: 01 (endpoint contract)
Spec: `../spec.md`

## Scope

Repo `/home/madmouse/Documents/web/inbils-mobile`: `packages/inbils_core` gains `firebase_core`, `firebase_messaging`, `flutter_local_notifications` (report versions/licences) and a `PushService` (init after sign-in, permission prompt on Android 13+, token fetch + refresh → `POST me/device-token`, delete on sign-out, foreground message → local notification on channel "Inbils", tap/initial message → existing subject router → mark read). Both apps: Gradle `com.google.gms.google-services` plugin, `android/app/google-services.json` from `~/.config/inbils/google-services.json` (gitignored; README documents the copy step per flavor since one file covers all package names), `POST_NOTIFICATIONS` in the manifest, default notification icon/colour resources. Widget/unit tests per N7 with faked `FirebaseMessaging`.

## Acceptance criteria

- [x] `flutter analyze`/`flutter test` green in core + both apps; debug APK builds for both.

## Comments

- Added `firebase_core` 5.0.0, `firebase_messaging` 17.0.0 and `flutter_local_notifications` 22.3.2 to `inbils_core`; each is BSD-3-Clause. Added Google Services Gradle plugin 4.5.0 (Apache-2.0), compatible with this repo's AGP 9.1.0/Kotlin 2.4.0 setup; both dev APKs built with it.
- `PushBackend` is the injected seam; `FakePushBackend` keeps widget/unit tests off Firebase. `PushService` starts only after `SignedIn`, asks only an undecided Android 13+ permission, POSTs the token and refreshes, DELETEs and invalidates it on sign-out, shows foreground heads-up notifications on high-importance channel id `inbils` (name `Inbils`) with `ISP: title`, routes warm/initial/local taps by `subject_type`/`subject_id`, then marks `notification_id` read.
- Backend contract used: `POST/DELETE me/device-token` with automatic `Idempotency-Key`; POST `{token, app, platform: android, device_name?}`; DELETE `{token}`; push data `type`, `subject_type`, `subject_id`, `notification_id`, `title`, `body`, all strings. Final mobile-push types: `invoice_issued`, `invoice_due_soon`, `invoice_overdue`, `payment_received`, `ticket_assigned`, `ticket_sla_breached`, `ticket_first_response_due`, `spk_scheduled`, `spk_approved`, `spk_rejected`, `technician_en_route`, `visit_completed`, `inventory_request_ready`, `announcement_published`; routes use their existing subject type, not event type. Unknown/missing subjects open the notifications list.
- Both apps apply `com.google.gms.google-services`, declare `POST_NOTIFICATIONS`, set the default channel/icon/colour, and include the white Inbils status-bar vector plus `#2563EB` notification colour. `flutter_local_notifications` desugaring is enabled with `desugar_jdk_libs` 2.1.4.
- Local build setup documented in root and both app READMEs: copy `~/.config/inbils/google-services.json` to each `android/app/`. `git check-ignore -v` matched both through root `.gitignore`; `git status --short` listed neither file. The combined file contains all six application ids.
- Verification: `dart format --set-exit-if-changed .`; `flutter analyze`; `flutter test` in `packages/inbils_core`, `customer`, `technician`; and `flutter build apk --debug --flavor dev --dart-define-from-file=env/dev.json` in both apps. Builds succeeded at `build/app/outputs/flutter-apk/app-dev-debug.apk` (customer 354.5s; technician 32.3s).
- Build warning: current Flutter reports `firebase_core` and existing `flutter_image_compress_common` still apply the Kotlin Gradle plugin because this repo explicitly sets `android.builtInKotlin=false`; builds pass now, but the repo's later Built-in Kotlin migration must move both together.
- Post-implementation review fixes: serialized sign-out cleanup before a replacement sign-in can register; Android local notifications now derive a stable numeric id from `notification_id` so separate tap payloads cannot collide; tap listeners run even after permission revocation; app resume rechecks OS permission and registers immediately when the user enabled notifications in Settings. Added a delayed-cleanup race test plus customer/technician initial-message widget tests.
