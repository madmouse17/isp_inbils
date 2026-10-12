# MOBILE: auth and session in `inbils_core` (login, 2FA, forgot password, refresh, 401), devices, customer Account tab

Status: done
Role: mobile
Blocked by: 01
Cross-lane blocked by: mobile-api 01 (auth endpoints), mobile-api 02 (customer profile)
Card: none (new lane; no Hermes card)
Skills: antislop-ui, antislop-human, antislop-layoutmobile, ui-ux-pro-max, tdd, web-security-review (token handling), ponytail, caveman

## Scope

Implements Q4 (auth, refresh and 401 interceptors), Q6, the shared auth screens and the customer Account tab.

- Core, shared by both apps:
  - `SessionStore`: `flutter_secure_storage` with the token, expiry, `app` and user id, nothing else.
  - `SessionController`: `unknown` / `signedOut(reason)` / `signedIn(Me)`. Restore = read storage, `GET me`, refresh if fewer than 7 days remain.
  - Auth repository (`auth/login`, `auth/two-factor`, `auth/forgot-password`, `auth/token/refresh`, `auth/logout`, `me`, `devices`).
  - Interceptors: bearer; single-flight refresh, where concurrent requests wait for one refresh; on 401, clear the session with a reason (`account_inactive`, expired or revoked).
  - Router guard helper.
  - Screens:
    - Login: e-mail keyboard, password visibility toggle, "Lupa kata sandi?".
    - Two-factor: 6 digits with `AutofillHints.oneTimeCode`, a toggle to "Pakai kode pemulihan", and expiry back to login.
    - Forgot password: one confirmation text for any address.
    - Legacy password sheet: `password_reset_required` → "Kirim link" → forgot-password with the same e-mail.
    - Devices: the list with the current device marked; revoking another device confirms first; revoking the current device logs out.
  - The `app` value is injected by the host app.
  - Device name: "manufacturer model" from `device_info_plus` (`AndroidDeviceInfo.manufacturer` and `.model`), falling back to "Android". `device_info_plus` is the standard plugin; the alternative is our own platform-channel code duplicated in both apps.
- Customer:
  - Wire `app=customer` and the message "Akun ini tidak terdaftar sebagai pelanggan.".
  - Routes `/splash`, `/login`, `/login/two-factor`, `/login/forgot-password`, `/account`, `/account/devices` (plus the existing `/account/about`).
  - Account tab: `GET me` + `GET customer/profile`, read-only profile and addresses with "Hubungi CS untuk mengubah data", links to Perangkat and Tentang, and Keluar (confirm; `POST auth/logout`; clear storage and navigate even when offline or on 401).
  - After login, the user lands on `/account`, until ticket 03 adds the invoice tab.

Non-goals: biometrics, 2FA management (web only), password change in the app (the reset link opens the web page), push.

## Owned files

- `../inbils-mobile/packages/inbils_core/pubspec.yaml` (`flutter_secure_storage`, `device_info_plus`), `../inbils-mobile/packages/inbils_core/lib/src/api/` (interceptors), NEW `../inbils-mobile/packages/inbils_core/lib/src/{session,auth,devices}/`, core ARB and tests for these
- `../inbils-mobile/customer/lib/main.dart`, `../inbils-mobile/customer/lib/app/router.dart`, NEW `../inbils-mobile/customer/lib/features/account/`, `../inbils-mobile/customer/lib/l10n/`, `../inbils-mobile/customer/test/`
- `../inbils-mobile/pubspec.lock` (regenerated; see the spec's shared lock-file rule)

## Acceptance criteria

- [x] Widget tests with a fake adapter cover each login outcome:
  - 200 lands on Account, with the token saved;
  - 202 leads to the code screen; a valid code lands on Account; a wrong code shows a field error; an expired challenge goes back to login with its message;
  - 422 shows "Email atau kata sandi salah.";
  - 403 `password_reset_required` opens the sheet and sends the link;
  - 403 `app_not_allowed` shows the customer message;
  - 429 shows the seconds from `Retry-After`.
- [x] Unit tests:
  - Three parallel requests with an expiring token cause exactly one refresh call, and all three retry with the new token.
  - A 401 on any request signs out once and routes to login with the reason.
  - Restore with a revoked token ends signed out.
- [x] The token never appears in logs (dev logging test) and is stored only through `flutter_secure_storage`. A grep for `SharedPreferences` in `../inbils-mobile/` finds nothing.
- [x] Devices: the list renders and the current device is flagged. Revoking another device calls `DELETE devices/{id}` with an `Idempotency-Key`. Revoking the current one signs out.
- [x] Logout clears storage and routes to login even when the request fails offline.
- [x] Live on the AVD against the compose stack (`adb reverse`, `tests/e2e/setup.php` run first): the seeded customer logs in, sees the profile, sees one device named after the emulator model, logs out. Describe it as seen, with screenshot paths.
- [x] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments

### 2026-10-12: done (agent FlutterAuth)

Commit `b07cc3b` in `inbils-mobile` (not pushed). Checks: `dart format --set-exit-if-changed .` clean; `flutter analyze` 0 issues in `inbils_core`, `customer`, `technician`; `flutter test` 219 (core), 32 (customer), 14 (technician) green.

Decisions
- `SessionState.signedIn` stays `SignedIn(StoredSession)`; the user comes from `GET me` (`meProvider`). Restore proves the token with `GET me` before leaving the splash screen: 401 signs out (reason), 403 signs out, offline/5xx keep the session.
- Refresh runs in `AuthInterceptor` before every non-`auth/` request, single-flight in `SessionController.refreshIfDue()`; one `auth/token/refresh` for three parallel requests (unit test).
- Logout forgets the session first, then revokes the token with an explicit bearer, so it works offline. Verified server side: after both logouts only the checking device remained.
- `FakeAdapter.routed` and `readStoredSession` added to `inbils_core/testing.dart`. `.gitignore`: `env/*.local.json` did not match `customer/env/`; now `**/env/*.local.json`.
- Customer profile `type` is not shown (values unknown to the app); status maps to Indonesian labels.
- `SharedPreferences` grep over the repo (sources, no build dirs): none.

Live (AVD `inbils_pixel_api35`, dev flavor): the compose stack and Sail serve the `inbils` database (compose demo customer answers `password_reset_required`; Sail `inbils` has no tables), so the check used `tests/e2e/setup.php` plus `php artisan serve --env=e2e --port=8010` under `/tmp/inbils-e2e.lock`, `adb reverse tcp:8010 tcp:8010`, `API_BASE_URL=http://127.0.0.1:8010`. Seen: login as `customer@demo.inbils.test` landed on Akun with code E2E-LINKED-CUST, name, phone, address "E2E harness street / Jakarta 12345 / Utama" and "Hubungi CS untuk mengubah data."; Perangkat listed one device "Google sdk_gphone64_x86_64" flagged "Perangkat ini"; the app restored the session on a cold start after the emulator was killed; Keluar asked "Keluar dari akun?" and returned to the login screen. Screenshots were checked and deleted; emulator killed.
