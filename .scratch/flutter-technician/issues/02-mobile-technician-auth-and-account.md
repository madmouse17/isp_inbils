# MOBILE: technician auth wiring (shared core screens) and Account tab

Status: ready-for-agent
Role: mobile
Blocked by: 01
Cross-lane blocked by: flutter-customer 02 (core auth, session and devices), mobile-api 01
Card: none (new lane; no Hermes card)
Skills: antislop-ui, antislop-human, antislop-layoutmobile, tdd, ponytail, caveman

## Scope

- Wire the core session and auth with `app=technician` and the message "Akun ini tidak terdaftar sebagai teknisi.". The routes `/splash`, `/login`, `/login/two-factor` and `/login/forgot-password` come from the core screens. Also `/account`, `/account/devices` and the existing `/account/about`.
- Account tab: `GET me` (name, e-mail, `employee.phone`), links to Perangkat and Tentang, and Keluar (confirm; `POST auth/logout`; clear storage even offline). The pending-photo guard (Q10) is added by ticket 07 when the queue exists.
- After login, the user lands on `/account` until ticket 03 adds the work tab.

Non-goals: changes to core auth (report `needs-change` if one is needed), 2FA management, push.

## Owned files

- `../inbils-mobile/technician/lib/main.dart`, `../inbils-mobile/technician/lib/app/router.dart`, NEW `../inbils-mobile/technician/lib/features/account/`, `../inbils-mobile/technician/lib/l10n/`, `../inbils-mobile/technician/test/`

## Acceptance criteria

- [ ] Widget tests with a fake adapter: a technician login lands on Account; 403 `app_not_allowed` shows the technician message; 202 leads through the code screen; 401 on a later request returns to login with the reason.
- [ ] The token is stored only through core secure storage, and a customer-app session on the same phone is independent (separate app ids, so separate storage). This is stated and checked on the AVD with both apps logged in as different users.
- [ ] Live on the AVD against the compose stack (`adb reverse`, `tests/e2e/setup.php` first): the seeded technician logs in, sees their profile and devices, and logs out. A customer account trying the technician app gets the refusal message. Described as seen.
- [ ] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments
