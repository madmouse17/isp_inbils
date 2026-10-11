# Flutter customer app (Android) — invoices, online payment, tickets, notifications

Status: ready-for-agent
Ledger: none yet (new lane; owner request 2026-10-11)
Source: owner request 2026-10-11 (two separate Flutter apps, Android first, built after the web work); earlier draft `docs/MOBILE_TASK_PLAN.md` §3 and §5 (local, git-ignored).
Depends on: `.scratch/mobile-api/` (the API contract this app consumes). Sibling: `.scratch/flutter-technician/`, which inherits the shared decisions Q1-Q6 and Q8-Q17 below.

> Nothing starts before the owner answers the decisions below. "setuju rekomendasi" approves the recommended option of every item. Keystores, `key.properties`, store credentials and any token stay outside the repository and never appear in a ticket comment. This lane owns the shared Flutter workspace and the `inbils_core` package; the technician lane consumes them.

Toolchain installed on the dev machine 2026-10-11 (owner-approved, by the AndroidToolchain task): Flutter 3.47.7 stable with Dart 3.13.5 (`~/development/flutter`), JDK Temurin 17 (mise), Android SDK `~/Android/Sdk` with platforms 35 and 36 and build-tools 35.0.0, AVD `inbils_pixel_api35` (Pixel 7, API 35, google_apis x86_64). Package versions below were read on pub.dev on 2026-10-11.

Approved by owner 2026-10-13: recommended option of every decision (Riverpod 3 + riverpod_generator, go_router, dio, freezed, flutter_secure_storage, shared package `../inbils-mobile/packages/inbils_core`, app id id.inbils.customer).

**Location (owner change):** the Flutter workspace does not live in this repository. It is its own git repository, the sibling folder `../inbils-mobile/` (`/home/madmouse/Documents/web/inbils-mobile/`). Every `../inbils-mobile/...` path in this lane is relative to the root of this (web) repository.

## Owner decisions

1. **Q1 — Repository layout.** The owner wants two separate Flutter projects. The earlier plan proposed `mobile/apps/{customer,field_ops}` plus four packages, and said a package should be extracted only when both apps use it (`docs/MOBILE_TASK_PLAN.md` §5).
   (a) A separate git repository next to the web repo, `../inbils-mobile/` (owner change: not inside this repo): a Dart pub workspace with `customer`, `technician` and one shared package `packages/inbils_core`. The core package holds the API client, auth and session (login, 2FA and forgot-password screens), error mapping, shared models (`Me`, `Device`, notification, page), theme, shared widgets, and the devices and notification screens. App-specific code stays in each app. (b) Inside the web repo, two projects and no shared package; each app copies the client, auth and theme. (c) Two separate git repositories, one per app.
   **Recommend (a).** The workspace has one lock file so both apps use the same versions, and the shared code is tested once. Tradeoff: a core change affects both apps, so both test suites run, and an API change in the web repo and its app change are two commits in two repositories. (b) duplicates roughly 2,000 lines that then drift apart. (c) splits the shared code across repositories and CI.

2. **Q2 — State management.** The owner wants it easy to maintain and clean.
   (a) Riverpod 3 (`flutter_riverpod` 3.4.3) with `riverpod_generator` 4.0.9: `@riverpod` notifiers per screen and `AsyncValue` for loading, error and data. Dependency injection comes from providers, and tests swap them with `ProviderContainer` overrides. (b) `flutter_bloc`: an event, state and bloc class per feature.
   **Recommend (a).** It needs less boilerplate, loading, error and cache handling are built in, and the earlier plan already chose it. Tradeoff: a code-generation step (`build_runner`, shared with freezed). Riverpod 3 retries failed providers automatically, so the app installs a retry policy that never retries a 4xx (401/403/404/422).

3. **Q3 — Navigation.**
   (a) `go_router` 18 (published by flutter.dev) with one `redirect` guard on session state and a `StatefulShellRoute` for the bottom tabs. (b) `auto_route`. (c) Navigator 2 by hand.
   **Recommend (a).**

4. **Q4 — HTTP client.**
   (a) `dio` 5.11 with interceptors, in this order:
   - base URL from the flavor, `Accept: application/json`, and timeouts (connect 10 s, receive 30 s, longer for uploads);
   - bearer token;
   - single-flight token refresh when fewer than 7 days remain (API Q4);
   - `Idempotency-Key` on every mutation. The repository method creates the key once per user action and reuses it for retries of that action;
   - error mapping to one `ApiException` with status, `code`, message, field errors and `Retry-After`, plus an `offline` kind for connection failures;
   - 401 clears the session and the router goes to login with the reason.

   Release builds never log headers or bodies; dev builds log only method, path and status. (b) The `http` package with hand-written wrappers.
   **Recommend (a).** UUIDs come from `Random.secure()` in about ten lines of code, with no extra package.

5. **Q5 — Models.**
   (a) `freezed` 4.0.2 with `json_serializable` 6.14.1: immutable classes, `fromJson` per API resource, unknown keys ignored, and enums with an `unknown` fallback, so a new server status never crashes the app. Money arrives as strings (for example `"154600.00"`) and is only formatted, never computed with doubles. (b) Hand-written immutable classes.
   **Recommend (a).** Tradeoff: generated files. All generators (`freezed`, `riverpod_generator`, `json_serializable`, `drift_dev` in the technician app) accept analyzer 14, so they resolve together. Ticket 01 pins the set that resolves.

6. **Q6 — Token storage.**
   (a) `flutter_secure_storage` 11.2 (Android Keystore-backed) holds only the token and its expiry. The app disables Android Auto Backup (`android:allowBackup="false"`), so a token is never restored onto another phone. (b) `shared_preferences`, which is plain text. Rejected.
   **Recommend (a).**

7. **Q7 — Payment screen.** The API returns Midtrans' `redirect_url` and a data-free finish page (API Q19). Midtrans supports WebView, but only with JavaScript, DOM storage and file access switched on, plus an injected script to download QRIS, Alfamart and Indomaret codes. Midtrans also "strongly discourage[s]" custom JS injection (docs.midtrans.com/reference/webview, read 2026-10-11).
   (a) Open `redirect_url` in a Chrome Custom Tab (`url_launcher` 6.3, `LaunchMode.inAppBrowserView`). When the app returns to the foreground, it re-reads the invoice right away and once more after 10 s. Status always comes from the server. (b) An in-app WebView (`webview_flutter`) with the Midtrans settings, the download workaround and our own handling of e-wallet deep links. (c) The external browser.
   **Recommend (a).** Chrome handles e-wallet deep links and QR downloads, the address bar shows the real Midtrans domain (harder to phish), and no script goes into the payment page. Tradeoff: the customer closes the tab to come back; if Custom Tabs are unavailable, the system browser opens instead.

8. **Q8 — Language.**
   (a) Bahasa Indonesia only in v1, through `flutter gen-l10n`: ARB files with `id` as the template, no user-facing string hard-coded, and money and dates through `intl` with `id_ID` ("Rp 154.600", "11 Okt 2026"). Every API `code` maps to an Indonesian message (table in Implementation Decisions). (b) Indonesian and English now.
   **Recommend (a).** English becomes an ARB file later, with no code change.

9. **Q9 — Theme aligned with the web.** The web tokens live in `resources/css/app.css`: primary `221.2 83.2% 53.3%` = `#2563EB` (`:136`), radius `0.5rem` (`:149`), Figtree font (`:51`), brand and surface scales (`:65-87`), success, warning and danger (`:89-91`), dark palette (`:152-171`). Measured contrast (WCAG, antislop contrast tool, 2026-10-11):
   - `#F8FAFC` on `#2563EB`: 4.94:1, pass.
   - Shadcn destructive `#EF4444` with light text: 3.6:1, **fails**. `--color-danger` `#DC2626` with white: 4.83:1, pass.
   - Web success `#16A34A` and warning `#D97706` with white: 3.3:1 and 3.19:1, **fail**. `#15803D` and `#B45309`: 5.02:1 each, pass.
   - Muted text `#64748B`: 4.76:1 on white, but 4.34:1 on `#F1F5F9` (**fails**). `#475569` on `#F1F5F9`: 6.92:1.
   - Dark mode: primary `#3B82F6` with `#0F172A` text is 4.85:1, and muted `#94A3B8` on `#020817` is 7.8:1.

   (a) Material 3 `ColorScheme`s written out by hand from these tokens rather than `fromSeed`, so the colours match the web exactly. Apply the AA fixes above (danger `#DC2626` for `error`, filled success and warning chips `#15803D` and `#B45309`, muted text only on white or `#475569` on muted surfaces). Radius 8, Figtree bundled as an asset (OFL) rather than fetched at runtime, and light and dark themes that follow the system setting. Tokens live in `inbils_core`, and a unit test pins each value and pair. (b) Light only. (c) `ColorScheme.fromSeed(#2563EB)`, quicker but off-brand.
   **Recommend (a).**

10. **Q10 — App identity.** Application ids cannot change after the first Play upload.
    (a) `id.inbils.customer` and `id.inbils.technician`, named "Inbils" and "Inbils Teknisi". The dev and staging flavors add the suffixes `.dev` and `.staging`, so all three builds can sit side by side on one phone. Use this only if the company controls the `inbils.id` domain; otherwise reverse the domain it does own. (b) Other names or ids from the owner.
    **Recommend (a)**, once the owner confirms the domain. App icons come from the brand assets; this lane does not design a logo.

11. **Q11 — Flavors, environments, reaching Laravel from the emulator.** The compose stack serves on `${APP_PORT:-8080}` (`compose.yaml:31`), with `APP_ENV=production` and `APP_URL=http://localhost:8080` (`.env.docker.example:2,5,7`). Host checking is enforced outside `local` (`bootstrap/app.php:33`, `vendor/laravel/framework/src/Illuminate/Http/Middleware/TrustHosts.php:99-103`), so the emulator's usual `http://10.0.2.2:8080` would be rejected by host. `localhost` is trusted.
    (a) Android product flavors `dev`, `staging` and `prod`, one `main.dart`, and `--dart-define-from-file=env/<flavor>.json` holding `API_BASE_URL` and `FLAVOR` (no secrets in these files). Dev uses `http://localhost:8080` after `adb reverse tcp:8080 tcp:8080`, which works on the AVD and on a USB phone. `10.0.2.2` works only when Laravel runs with `APP_ENV=local`. Cleartext HTTP is allowed only in the dev flavor and only for `localhost`, `127.0.0.1` and `10.0.2.2`, through a dev-only network security config. Staging and prod are HTTPS only. (b) One build with a server picker at runtime. Rejected: a store build could point at a dev server.
    **Recommend (a).** Staging and prod URLs come from the owner (public HTTPS host, API Further Notes).

12. **Q12 — Lints.**
    (a) `flutter_lints` plus the analyzer's `strict-casts`, `strict-inference` and `strict-raw-types`, a zero-warning `flutter analyze`, and `dart format`. One shared `../inbils-mobile/analysis_options.yaml`. (b) `very_good_analysis`: stricter, about 200 rules, more churn on upgrades and doc-comment noise in app code.
    **Recommend (a).**

13. **Q13 — Tests.**
    (a) Unit tests for error mapping, interceptors, formatters, theme tokens and notifiers. Widget tests for every screen state (loading, empty, error with retry, offline, success, 422 field errors). `integration_test` journeys on the AVD against a fake HTTP adapter fed with JSON fixtures copied from the OpenAPI export, so they are deterministic. One manual live smoke against the local Laravel, seeded by `tests/e2e/setup.php`. Coverage is reported but not gated. (b) Widget tests only.
    **Recommend (a).** The fixtures double as a contract check: every fixture must parse with the freezed models.

14. **Q14 — Offline in the customer app.**
    (a) No local database. Each screen keeps the last loaded data in memory, shows an offline banner and a retry, and paying and writing tickets need a connection. (b) A cached read model (drift).
    **Recommend (a).** Customers are mostly connected, and payment needs the network anyway. The technician app owns the offline work.

15. **Q15 — Crash reporting.**
    (a) None in v1. Play Console's Android vitals already reports crashes and ANRs for Play-installed apps without an SDK. (b) A Sentry or Crashlytics SDK: a DSN or Firebase config plus a privacy disclosure.
    **Recommend (a).** Revisit if the pilot needs stack traces with app context.

16. **Q16 — Release signing and distribution.**
    (a) The owner generates the upload keystore outside the repo (for example `~/.config/inbils/keystores/`, with an offline backup) and turns on Play App Signing, so Google holds the app-signing key and a lost upload key can be reset. `android/key.properties` is git-ignored and read only when it exists. Release AABs are built locally on request. Play Console account: an **organization** account is preferred. New personal accounts must run a closed test with at least 12 testers for 14 continuous days before production (support.google.com/googleplay/android-developer/answer/14151465, read 2026-10-11). The customer app goes to closed testing first, then production. (b) A self-signed APK sent to customers. Rejected: no updates, and it teaches users to sideload.
    **Recommend (a).** The owner also provides a privacy-policy URL and completes the Data safety form before the first upload.

17. **Q17 — Toolchain pin and Android levels.** Play requires new apps and updates to target Android 16 (API 36) from 2026-08-31 (developer.android.com/google/play/requirements/target-sdk, read 2026-10-11). Platform android-36 is installed.
    (a) Pin Flutter 3.47.7 and Dart `^3.13.0` in the workspace (`environment` constraints, plus the exact version in the app README and in the ticket 01 comment). `targetSdk` = 36 (the Flutter default, if it already is). `minSdk` = the Flutter 3.47 default, raised only if a plugin needs it. Upgrades are their own ticket. (b) Track whatever stable is current.
    **Recommend (a).**

Inherited from `.scratch/mobile-api/spec.md`: no push in v1 (API Q17), no in-app profile editing (Q16), no customer uploads on tickets (Q15), the error envelope and codes (Q10), and `Idempotency-Key` on every mutation (Q11).

## Findings and corrections

- The earlier plan named the field app "Field Ops" for five field roles. The owner asked for a technician app, and those roles do not exist (`database/seeders/RolePermissionSeeder.php:198-202`). The earlier plan's "outage" screen has no backend domain, so it is out of scope.
- A dev base URL of `http://10.0.2.2` fails against the compose stack because of `trustHosts` (Q11).
- The shadcn destructive red, the web success green and the warning amber fail AA as filled backgrounds with white text (Q9). The web may want the same fix later; that is not this lane.

## Problem Statement

An Inbils customer who wants to know what they owe, pay a bill or report a fault has to open a browser, log into a portal built for desktop and find their way around it. Payment confirmation is slow, tickets are hard to follow, and the customer calls the office instead.

## Solution

An Android app, "Inbils", where the customer logs in once (with their second factor if enabled) and lands on a home screen showing their service status, their unpaid bills with the earliest due date, and their open tickets. They can open any invoice and pay it through Midtrans in a secure browser tab, then come back to see the status update. They can see their payment history, open a ticket and follow the replies, read their notifications in the app, and see which phones are logged into their account and log one out. Everything is in Bahasa Indonesia and follows the Inbils web colours.

## User Stories

1. As a customer, I want to log in with my portal e-mail and password, so that I do not need a new account.
2. As a customer with two-factor authentication, I want to enter my authenticator code or a recovery code after my password, so that my account stays protected.
3. As a customer with an old password, I want to be told to reset it and get the link by e-mail with one tap, so that I can get in.
4. As a customer who forgot my password, I want to request a reset link from the login screen, so that I can recover my account myself.
5. As a customer, I want the app to remember me for weeks and refresh my session quietly, so that I am not asked to log in every day.
6. As a customer, I want to be sent back to the login screen with a clear reason when my session ends or my account is deactivated, so that I know what happened.
7. As a customer, I want a home screen with my service status, my unpaid invoices count, the next invoice due and my open tickets, so that I see what matters at a glance.
8. As a customer, I want to pull down to refresh any list, so that I see the latest data.
9. As a customer, I want to list my invoices as unpaid, paid or all, so that I find a bill quickly.
10. As a customer, I want an invoice screen with number, dates, items, total, paid amount, remaining balance and status, so that I can check the bill.
11. As a customer, I want a "Bayar" button only when the invoice can be paid online, and a clear reason when it cannot, so that I am not confused.
12. As a customer, I want the Midtrans page to open in a secure browser tab inside the app, so that I can pay with my bank, e-wallet or QRIS.
13. As a customer, I want the invoice to refresh when I come back from paying, and a message whether it is paid or still waiting for confirmation, so that I know the outcome.
14. As a customer, I want tapping "Bayar" twice never to create two payments, so that I am safe from double charges.
15. As a customer, I want a payment history with amount, method, date and invoice number, so that I have my receipts.
16. As a customer, I want to see my subscriptions with package, monthly charge, status and next invoice date, so that I understand my service.
17. As a customer, I want to see my profile and installation addresses read-only, and know to contact customer service to change them, so that my data is right.
18. As a customer, I want to create a ticket by picking a category, an optional subscription, a title and a description, so that I can report a fault.
19. As a customer, I want my tickets listed as active or closed, and to open one with its replies, so that I can follow progress.
20. As a customer, I want to reply on my ticket, so that I can answer the support team.
21. As a customer, I want a notification list with unread markers that opens the related invoice or ticket, so that I catch up on what happened.
22. As a customer, I want to see the phones logged into my account and log one out, so that I can lock out a lost phone.
23. As a customer, I want to log out, so that someone else can use my phone safely.
24. As a customer without a signal, I want a clear offline message with a retry instead of a crash or an endless spinner, so that I know to try again later.
25. As a customer using large text or TalkBack, I want readable, labelled screens, so that I can use the app.
26. As a customer, I want the app in Bahasa Indonesia with rupiah and Indonesian dates, so that it reads naturally.
27. As a developer, I want the dev build to talk to my local Laravel through `adb reverse`, and staging and prod builds to talk only HTTPS, so that no test build hits production and no production build allows plain HTTP.
28. As the owner, I want signing keys held only by me and Play App Signing on, so that a leaked repository cannot publish an update.

## Implementation Decisions

### Workspace (shared with the technician app)

```
../inbils-mobile/
  pubspec.yaml                # workspace root: packages/inbils_core, customer, technician
  analysis_options.yaml       # shared lints (Q12)
  .gitignore                  # build outputs, key.properties, *.jks, *.keystore, env/*.local.json
  packages/inbils_core/       # api/, auth/, models/, theme/, l10n/, widgets/, devices/, notifications/
  customer/                   # lib/features/{home,invoices,payments,subscriptions,tickets,account}
  technician/                 # owned by the technician lane
```

The workspace is its own git repository (see Q1), so the web repo's `.dockerignore`, ESLint, Prettier, Pint and PHPStan never see Flutter sources or builds, and none of them changes. The workspace has its own root `.gitignore` (Flutter standard, plus `*.jks`, `*.keystore`, `key.properties`, `.env*` and `env/*.local.json`) and its own root `README.md`.

### Core package (`inbils_core`)

- `ApiClient`: dio with the Q4 interceptors. `ApiException` kinds: `offline`, `timeout`, `http(status, code, message, fieldErrors, retryAfter)`.
- Session: secure storage (token, expiry, `app`, user id). `SessionController` (Riverpod) with the states `unknown`, `signedOut(reason?)` and `signedIn(Me)`. Restore at start: read storage, call `GET me`, refresh the token if fewer than 7 days remain. The app type (`customer` or `technician`) is a constructor parameter, sent as `app` at login.
- Auth screens shared by both apps: login (e-mail, password, "Lupa kata sandi?"), two-factor (6-digit field with `AutofillHints.oneTimeCode`, a link to switch to a recovery code), forgot password (e-mail, then the same confirmation for any address). The device name sent at login is "<manufacturer> <model>" from the platform, falling back to "Android".
- Shared widgets: `AsyncBody` (loading skeleton, empty state, error with retry, offline banner), `StatusChip` (colour plus text, never colour alone), money and date formatters, `FieldErrorText`.
- Devices screen (`GET devices`, revoke with confirmation; the current device is marked and revoking it logs out). Notification inbox (`GET notifications`, mark read on open, "Tandai semua dibaca", tap → `subject` route given by the app).
- Error copy, one table in the core ARB:

| `code` / kind | Message (id) |
| --- | --- |
| offline | Tidak ada koneksi internet. Periksa sinyal, lalu coba lagi. |
| timeout, server_error, service_unavailable | Server sedang bermasalah. Coba lagi beberapa saat lagi. |
| unauthenticated | Sesi Anda berakhir. Silakan masuk lagi. |
| account_inactive | Akun Anda dinonaktifkan. Hubungi kantor Inbils. |
| app_not_allowed | (per app) Akun ini tidak terdaftar sebagai pelanggan / teknisi. |
| password_reset_required | Demi keamanan, atur ulang kata sandi lewat link yang kami kirim ke email Anda. |
| validation_failed on login | Email atau kata sandi salah. |
| challenge_expired | Kode verifikasi kedaluwarsa. Silakan masuk lagi. |
| forbidden | Anda tidak punya akses untuk tindakan ini. |
| not_found | Data tidak ditemukan atau sudah dihapus. |
| conflict | Data sudah berubah. Kami memuat ulang. |
| too_many_requests | Terlalu banyak percobaan. Coba lagi dalam {seconds} detik. |
| idempotency_in_progress, idempotency_conflict | Permintaan sebelumnya masih diproses. Coba lagi sebentar lagi. |
| payment_unavailable | Pembayaran online belum tersedia. Silakan bayar lewat kantor Inbils. |
| invoice_not_payable, unprocessable | the server `message` |

### Customer app

Routes (go_router; paths are code identifiers, labels are Indonesian):
- `/splash`, `/login`, `/login/two-factor`, `/login/forgot-password`
- Tabs: `/home` (Beranda), `/invoices` (Tagihan), `/tickets` (Tiket), `/account` (Akun)
- `/invoices/:id`, `/payments`, `/subscriptions`, `/subscriptions/:id`, `/tickets/new`, `/tickets/:id`, `/notifications`, `/account/devices`, `/account/about`

The guard sends `signedOut` sessions to `/login` and keeps signed-in users off `/login`. A notification `subject` routes as: `invoice` → `/invoices/:id`, `ticket` → `/tickets/:id`, anything else → stay in the list.

| Screen (label) | Data (API) | Must do |
| --- | --- | --- |
| Home (Beranda) | `GET customer/subscriptions`, `GET customer/invoices?status=unpaid` (first page, `meta.total`), `GET customer/tickets?status=active` (`meta.total`), unread count from `GET notifications?unread_only=1` | Requests run in parallel and one failing card does not blank the screen. The earliest-due unpaid invoice shows "Bayar" when `can_pay`. Pull to refresh. Bell icon with unread badge. |
| Invoices (Tagihan) | `GET customer/invoices?status=` | Segmented control Belum dibayar / Lunas / Semua, infinite scroll by page, status chip, remaining amount for unpaid, empty state per filter |
| Invoice detail | `GET customer/invoices/{id}` | Items, totals, payments; `payment.can_pay` → "Bayar", else `blocked_reason`. Pay: button disabled while in flight, then `POST …/pay` with one key per tap, then open the Custom Tab. On resume: re-read now and after 10 s, with a banner "Pembayaran diterima" / "Menunggu konfirmasi pembayaran" |
| Payment history (Riwayat pembayaran) | `GET customer/payments` | Amount, method, date, invoice number; tap → invoice |
| Subscriptions (Langganan) | `GET customer/subscriptions[/{id}]` | Package, monthly charge, status chip, activation date, billing day, next invoice date, address |
| Tickets (Tiket) | `GET customer/tickets?status=` | Aktif / Selesai filter, code, title, status, last update; FAB "Buat tiket" |
| New ticket (Tiket baru) | `GET customer/ticket-categories`, `GET customer/subscriptions`, `POST customer/tickets` | Category required. Subscription optional, offering only `active` and `suspended` ones as the web form does (`Modules/Ticketing/app/Http/Controllers/TicketController.php:174`), preselected when exactly one. Title required (≤255), description optional. Client-side validation mirrors the server rules, and 422 field errors show under the fields. On success go to the ticket. |
| Ticket detail | `GET customer/tickets/{id}`, `POST …/comments` | Status, category, description, resolution note, a comment timeline ("Anda" for own comments), image attachments shown in-app through `GET media/{id}` with the token (other file types: name plus "Buka lewat portal web"), a reply box (≤2000) |
| Notifications (Notifikasi) | core inbox | As specified in the core package |
| Account (Akun) | `GET me`, `GET customer/profile` | Read-only profile and addresses with the note "Hubungi CS untuk mengubah data", Perangkat, Tentang (version, flavor, API host), Keluar (confirm, `POST auth/logout`, clear storage even when offline) |

Every data screen has loading, empty, error-with-retry and offline states. Touch targets are at least 48 dp. Text scales to 200% without losing actions. Icon buttons have semantic labels. Status is never shown by colour alone.

## Testing Decisions

- Good tests check what the user sees and what is sent over HTTP (method, path, body, `Idempotency-Key` reuse), not widget internals.
- Seams: `ApiClient` with a fake `HttpClientAdapter` (unit), repositories and notifiers with `ProviderContainer` overrides (unit), screens with overridden repositories (widget), whole journeys on the AVD with the fake adapter (`integration_test`).
- Must-have cases:
  - Error mapping for every `code` in the table, plus offline, timeout and 429 with `Retry-After`.
  - Refresh is single-flight, and 401 signs out.
  - 2FA, legacy-password and forgot-password flows.
  - Pay flow: double tap gives one request with one key; the Custom Tab opens with the exact `redirect_url`; resume triggers the re-read; the paid and pending banners.
  - Ticket create 422 shows field errors.
  - Each Home card fails independently.
  - Logout clears storage even when offline.
  - Devices revoke of the current device logs out.
  - Theme token values and contrast pairs.
  - Every OpenAPI-derived fixture parses.
- Live smoke (manual, ticket 07): the AVD `inbils_pixel_api35`, dev flavor, `adb reverse tcp:8080 tcp:8080`, the compose stack seeded with `tests/e2e/setup.php` (run it right before, because PHPUnit wipes the demo users). Evidence: screenshots and the `flutter test integration_test` output.
- Gates for each ticket: `dart format --set-exit-if-changed .`, `flutter analyze` with zero issues, `flutter test`, and a dev-flavor build. The emulator run is described as seen.
- Prior art: none for Flutter in this repo. Journey naming follows `tests/e2e/*`.

## Out of Scope

- Push notifications, in-app profile editing, uploading attachments to tickets, downloading non-image attachments, outage information, plan changes and relocation requests, customer evaluations, English, iOS, tablets and landscape layouts, WebView payment, App Links, crash SDKs, CI (later).

## Further Notes

- **Ticket order:** 01 → 02 → 03 → 04 → 05 → 06 → 07 → 08. Tickets 02-06 run one at a time because they all edit the app router and ARB.
- **Cross-lane:**
  - 01 needs no API and may start right after approval.
  - 02 needs mobile-api 01 and 02. 03 needs mobile-api 03. 04 needs mobile-api 04. 05 needs mobile-api 02.
  - 06 needs mobile-api 02 (the customer inbox also needs the WhatsApp lane done).
  - 07 needs mobile-api 08. 08 needs mobile-api 09 APPROVED and a public HTTPS API host.
  - The technician lane's 01 starts after this lane's 01 (workspace and core), its auth wiring after this lane's 02, and its inbox after this lane's 06.
- **Core ownership:** `../inbils-mobile/packages/inbils_core/**`, `../inbils-mobile/pubspec.yaml`, `../inbils-mobile/analysis_options.yaml` and `../inbils-mobile/.gitignore` are owned by this lane's tickets 01, 02 and 06 (one at a time). Any later core change goes to the orchestrator as `needs-change` and is assigned to one owner at a time. The technician lane only adds `technician` to the workspace member list (its ticket 01).
- **Shared lock file:** the workspace has one `../inbils-mobile/pubspec.lock`. A ticket that adds or upgrades a dependency (here 01, 02 and 03) must not run at the same time as a dependency-changing technician ticket. The orchestrator serializes them.
- **Owner prerequisites before 08:** domain confirmation for the app ids (Q10), the Play Console account type (Q16), upload keystore generation (on the owner's machine), a privacy-policy URL, the Data safety answers, app icons, store screenshots and copy, and the staging and production API URLs.
