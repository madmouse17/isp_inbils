# MOBILE: auth and session in `inbils_core` (login, 2FA, forgot password, refresh, 401), devices, customer Account tab

Status: ready-for-agent
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

- [ ] Widget tests with a fake adapter cover each login outcome:
  - 200 lands on Account, with the token saved;
  - 202 leads to the code screen; a valid code lands on Account; a wrong code shows a field error; an expired challenge goes back to login with its message;
  - 422 shows "Email atau kata sandi salah.";
  - 403 `password_reset_required` opens the sheet and sends the link;
  - 403 `app_not_allowed` shows the customer message;
  - 429 shows the seconds from `Retry-After`.
- [ ] Unit tests:
  - Three parallel requests with an expiring token cause exactly one refresh call, and all three retry with the new token.
  - A 401 on any request signs out once and routes to login with the reason.
  - Restore with a revoked token ends signed out.
- [ ] The token never appears in logs (dev logging test) and is stored only through `flutter_secure_storage`. A grep for `SharedPreferences` in `../inbils-mobile/` finds nothing.
- [ ] Devices: the list renders and the current device is flagged. Revoking another device calls `DELETE devices/{id}` with an `Idempotency-Key`. Revoking the current one signs out.
- [ ] Logout clears storage and routes to login even when the request fails offline.
- [ ] Live on the AVD against the compose stack (`adb reverse`, `tests/e2e/setup.php` run first): the seeded customer logs in, sees the profile, sees one device named after the emulator model, logs out. Describe it as seen, with screenshot paths.
- [ ] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments
