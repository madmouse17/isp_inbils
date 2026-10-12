# TESTER: customer app integration journeys, accessibility pass, live smoke on the emulator

Status: done
Role: tester (qa)
Blocked by: 06
Cross-lane blocked by: mobile-api 08 (API matrix green)
Card: none (new lane; no Hermes card)
Skills: tdd, antislop-human, antislop-layoutmobile, ponytail, caveman

## Scope

An independent check of the finished customer app. Add tests and evidence; never change app code. A defect becomes a `needs-change` to the owning ticket, with a failing test.

- `integration_test` journeys on the AVD `inbils_pixel_api35` with a fake HTTP adapter fed by JSON fixtures taken from `docs/api/mobile-v1.openapi.json` examples or recorded from the API feature tests:
  1. Login → Beranda → open the unpaid invoice → Bayar (fake launcher) → resume → paid banner.
  2. Login with 2FA, then with a recovery code, then an expired challenge.
  3. A legacy password → reset link sent.
  4. Create a ticket with a 422 then success → reply.
  5. Notifications → open invoice.
  6. Devices → revoke the current device → back at login.
  7. A token revoked mid-session (401) → login with the reason.
  8. Offline on every tab → banners, then retry recovers.
- Fixture contract check: every fixture parses with the app models (a test fails on drift).
- Accessibility: the Flutter guideline tests on every top-level screen, a TalkBack walk of the main journey, and 200% text scale screenshots.
- Live smoke on the compose stack (run `tests/e2e/setup.php` right before; `adb reverse tcp:8080 tcp:8080`):
  - login, Home, invoices, pay (sandbox or `payment_unavailable`), a ticket and reply, notifications, devices, logout;
  - screenshots stored under a path named in the ticket comment, outside the repo, with no tokens visible.

## Owned files

- NEW `../inbils-mobile/customer/integration_test/**` (journeys, fixtures, fake adapter)

## Acceptance criteria

- [x] `flutter test integration_test -d <emulator-id> --flavor dev --dart-define-from-file=env/dev.json` is green on `inbils_pixel_api35`, with the output pasted. (75 tests, below.)
- [x] Every Must-have case in the spec's Testing Decisions maps to a passing unit, widget or integration test, listed in a ticket comment. (Map below.)
- [x] The accessibility guideline tests pass on every top-level screen, and the TalkBack findings and 200% screenshots are recorded. After the fixes of commit `6ecd8d6`, `accessibility_test.dart` (tap target and labeled tap target) is green on all 17 screens on the AVD, Tagihan, Tiket and Riwayat pembayaran included; text contrast passes on the host. TalkBack findings and the 200% screenshots are recorded below.
- [x] The live smoke transcript is recorded step by step with what was seen, and any mismatch with the web portal (amounts, statuses) is reported as a finding. (No amount or status mismatch; one deviation and the findings below.)
- [x] No app source file was changed by this ticket (`git diff --stat customer/lib packages` is empty; commit `010a65d` touches only `customer/integration_test/` and `customer/test/accessibility_contrast_test.dart`).

## Comments

### 2026-10-12: tests, live smoke and findings (agent QaCust07)

Commit `010a65d` in `inbils-mobile` (not pushed): `test(customer): integration journeys, accessibility checks and live smoke`. Status stays `in-progress`: the accessibility criterion has one open gap that needs an app change (F1). Everything else is met.

**Files** (`customer/integration_test/`): `fixtures.dart` (JSON fixtures), `support.dart` (`FakeApi`, helpers), `fixture_contract_test.dart`, `journeys_test.dart`, `accessibility_test.dart`, `text_scale_test.dart`, `screens.dart` (the 17 top-level screens), `live_smoke.dart` and three `repro_*.dart` files (kept out of the suite, see F1 to F3). Also `customer/test/accessibility_contrast_test.dart` (host).

**Fixtures.** The OpenAPI file has no `example` values, so the fixtures are written from its schemas (`MeResource`, `PortalInvoiceResource`, ...), not recorded. Checked against the live API: every key of each fixture exists in the real body and the real bodies have no key the fixtures lack (a fixture-only difference is a list that was empty in the demo data). Tokens are placeholders.

**Emulator run** (AVD `inbils_pixel_api35`, API 35, dev flavor):

```
$ flutter test integration_test -d emulator-5554 --flavor dev --dart-define-from-file=env/dev.json
03:50 +75: All tests passed!
```

75 tests: fixture contract 29, journeys 12, accessibility 17, 200% text 17. Host: `flutter test` in `customer` 113 passed, `dart format --set-exit-if-changed .` 0 changed, `flutter analyze` no issues.

**Red check.** Mutated fixtures (a dropped `mrc_amount`, a wrong `sisa`): `fixture subscriptions parses with the app model`, `fixture invoice_12 parses with the app model` and journey 8 `Beranda: every card fails on its own and each retry recovers` failed (3 failed, 38 passed); fixtures restored. The three `repro_*.dart` files fail on the current app (5 failed, 1 passed).

**Journeys** (`journeys_test.dart`): 1 pay and resume (double tap sends one POST with one `Idempotency-Key`, exact URL in `LaunchMode.inAppBrowserView`, pending banner on resume, "Pembayaran diterima" after "Periksa status"); 2 two-factor code, recovery code, expired challenge; 3 `password_reset_required` and the link; 4 ticket 422 under the field, retry, reply; 5 notification opens INV-012; 6 revoke the current device; 7 a 401 mid-session; 8 offline on Tagihan, Tiket, Akun (retry recovers), Beranda cards, and the stale banner over loaded data.

**Must-have map** (spec, Testing Decisions):

| Must-have | Test |
|---|---|
| Error mapping for every `code`, offline, timeout, 429 `Retry-After` | core `api/api_client_test.dart` (code table, `a 429 carries Retry-After in seconds`, `a connect, send or receive timeout is ApiTimeoutException`, `names offline and timeout instead of a status`); core `l10n/error_messages_test.dart` (`too_many_requests shows the seconds of Retry-After`); customer `auth_account_test.dart` (`429 shows the seconds of Retry-After`, `offline shows the connection message`) |
| Refresh is single-flight, 401 signs out | core `session/session_controller_test.dart` (`three parallel requests cause one refresh and all send the new token`, group `a 401 on a real request`); journey 7 |
| 2FA, legacy password, forgot-password | customer `auth_account_test.dart` groups `two-factor`, `forgot password` and `403 password_reset_required opens the sheet`; journeys 2 and 3 |
| Pay flow: double tap one request and key, Custom Tab with the exact `redirect_url`, resume re-read, paid and pending banners | customer `invoices_payments_test.dart` group `pay` (`a double tap sends one POST with one key and opens the exact URL in a Custom Tab`, `coming back re-reads the invoice now and after 10 s, and shows the pending banner`, `a payment confirmed by the server shows "Pembayaran diterima"`); journey 1; live smoke |
| Ticket create 422 shows field errors | customer `tickets_test.dart` `server 422 errors show under their fields`; journey 4 |
| Each Home card fails independently | customer `home_subscriptions_test.dart` `$failing failing leaves the other cards and retries`; journey 8 (Beranda) |
| Logout clears storage even offline | core `session_controller_test.dart` `offline still clears storage and signs out`; customer `auth_account_test.dart` `Keluar works offline too` |
| Devices revoke of the current device logs out | customer `auth_account_test.dart` `revoking the current device signs out`; journey 6 |
| Theme token values and contrast pairs | core `theme/theme_test.dart` (`the AA fixes of decision Q9`, the contrast pair tests); customer `test/accessibility_contrast_test.dart` (text contrast of all 17 screens) |
| Every OpenAPI-derived fixture parses | `integration_test/fixture_contract_test.dart` (one test per fixture, plus `every fixture has a parser above`) |

**Accessibility.**
- Tap target (48 dp): passes on all 17 screens on the device (`accessibility_test.dart`).
- Text contrast: passes on all 17 screens on the host (`test/accessibility_contrast_test.dart`). On the device the guideline reported 1.36:1 to 1.95:1 for `#475569` text on white; measured on the real screenshot that text is 7.58:1, so the device-side guideline is not trustworthy and the contrast check runs on the host (Ahem needs a 700 dp wide view or the Notifikasi title is clipped).
- Labeled tap target: passes on 14 screens, fails on Tagihan, Tiket and Riwayat pembayaran (F1).
- 200% text: `text_scale_test.dart` runs the 17 screens with text scale 2.0 and no RenderFlex overflow. Screenshots (system font scale 2.0, taken with adb): `/tmp/inbils-qa-customer/scale200/*.png`. What they show: Notifikasi loses its title because "Tandai semua dibaca" takes the width (F4); in the Tagihan filter "Belum dibayar" wraps to two lines and touches the border (F5); everything else stays readable and reachable.
- TalkBack (best effort; TalkBack (`com.google.android.marvin.talkback`) is installed on the AVD and was switched on with `settings put secure enabled_accessibility_services`): swipe gestures sent with `adb input swipe` and `ALT+Right` key events did not advance TalkBack focus past the first control (focus highlight read back from screenshots: Kembali, then Bayar), and its speech is not logged, so a full spoken walk was **not possible**. What was possible: the accessibility tree read with `uiautomator dump` for the main journey (login, Beranda, Tagihan, invoice detail), with TalkBack on and off. Login: the two text fields expose no label in the dump (hint text is not printed by uiautomator, so not conclusive); buttons are labelled ("Tampilkan kata sandi", "Masuk"). Beranda: "Beranda", "Notifikasi" (button), the greeting, one merged node per card ("Layanan Home 10Mbps Aktif", "Tagihan 1 tagihan belum dibayar E2E-REC-LINKED ...", "Tiket aktif 12 tiket aktif"), "Bayar", "Buat tiket" and the tab bar ("Beranda Tab 1 dari 4"). Tagihan: each row is an empty clickable node followed by a separate unclickable node holding the label (this is F1). Invoice detail: "Kembali", title, merged label/value rows ("Subtotal Rp 120.000"), the Midtrans note, then "Bayar" (F6).

**Live smoke** (deviation: the ticket says the compose stack on 8080; I used the Laravel e2e server `php artisan serve --env=e2e` on 8011 with `adb reverse tcp:8011`, after `tests/e2e/setup.php`, under `/tmp/inbils-phpunit.lock`; Midtrans was a local mock of the Snap and status APIs on 18911, as in ticket 03, because there are no sandbox keys). Run by `integration_test/live_smoke.dart` against the real API with the real Custom Tab (AVD, dev flavor, cold app data). Screenshots: `/tmp/inbils-qa-customer/live/live_01..17_*.png` (no token appears on any screen). As seen:
1. Login as the seeded Demo Customer: Beranda "Halo, Demo Customer", bell badge 1, Layanan "Home 10Mbps" Aktif, Tagihan "1 tagihan belum dibayar, E2E-REC-LINKED, Jatuh tempo 27 Sep 2026, Sisa Rp 120.000" with Bayar, "12 tiket aktif" with Buat tiket.
2. Tagihan: one row E2E-REC-LINKED. Detail: Subtotal, Diskon, Pajak 0, Total Rp 120.000, Sudah dibayar Rp 0, Bayar, "Belum ada rincian item.", "Belum ada pembayaran."
3. Bayar: the Chrome Custom Tab opened the mock page (`127.0.0.1:18911`). Back returned to the app with "Menunggu konfirmasi pembayaran" and "Periksa status".
4. The order was settled in the mock and `midtrans:reconcile --company=1` ran (Checked 1, Settled 1; the attempt was backdated 15 minutes first). "Periksa status" then showed "Pembayaran diterima", Lunas, Rp 0, "Tagihan sudah lunas." and the payment "Pembayaran online Rp 120.000".
5. Tiket tab, Buat tiket: picked a category, title "Smoke QA 07 internet lambat", sent; the detail opened with "Belum ada balasan."; the reply "Balasan smoke QA 07." appeared in the timeline.
6. Notifikasi (after a throwaway `InvoiceIssuedNotification` was seeded): "Tagihan baru" opened the invoice; going back showed it read, while "Tiket dibuat" and "Pembayaran diterima" (created by steps 3 to 5) stayed unread.
7. Akun: profile, Perangkat showed "Google sdk_gphone64_x86_64" with "Perangkat ini"; Keluar and the confirmation returned to the login screen and secure storage was empty.

Web comparison (database after the run): invoice E2E-REC-LINKED `paid`, total and paid amount 120000.00, one `midtrans` payment 120000.00; customer tickets 13 (12 before, all active), the new ticket TKT-2026-00001 open with the reply; the invoice notification `read_at` set. The amounts and statuses the app showed match; I did not open the web portal in a browser. Not exercised live: error and empty states of the lists (journeys and widget tests only), a real Midtrans sandbox page, push.

**Findings.** All reproduce on the current app; none is fixed here (no app change). Repro files run by name: `flutter test integration_test/<file> -d <emulator> --flavor dev --dart-define-from-file=env/dev.json`.
- **F1, needs-change (blocks the accessibility criterion)** `customer/lib/features/invoices/invoices_screen.dart` (ticket 03), `customer/lib/features/tickets/tickets_screen.dart` (04) and `customer/lib/features/payments/payments_screen.dart` (03): the row `InkWell` is the tappable node and has no label; the text is in a `MergeSemantics` child. TalkBack gets an unlabelled actionable node followed by a text node. Fix: put `MergeSemantics` around the `InkWell` (the Notifikasi and Langganan rows already pass). Failing tests: `repro_unlabeled_rows.dart` (3 failed: `Tagihan rows have a label on the tappable node`, `Tiket rows ...`, `Riwayat pembayaran rows ...`: "Tappable widgets should have a semantic label"). When fixed, remove the `unlabeledRowScreens` set in `screens.dart` and the repro file.
- **F2, needs-change (ticket 02, with 05 for Home)** After a start without signal, "Coba lagi" on Akun never recovers when the signal returns: Beranda's `homeMe` keeps the failed `GET me` alive in `meProvider`, `accountProvider` re-reads only the profile (`GET me` is not sent again, the tab stays on the offline state). The Beranda greeting stays missing for the same reason. Fix: invalidate `meProvider` in the retry of Akun and Beranda. Failing test: `repro_account_retry.dart` (`Expected: a value greater than <2> Actual: <2>`).
- **F3, needs-change (low; tickets 03, 04, 05, 06 and core)** Pull to refresh without a signal rejects the future of its `RefreshIndicator` callback (`ref.refresh(list.future)`, `Future.wait` on Beranda), so an uncaught `ApiOfflineException` reaches the zone. The banner and data are right; no crash SDK reports it today. Failing test: `repro_pull_refresh.dart`. Journey 8 refreshes through `ProviderContainer.invalidate` instead of the pull gesture for this reason.
- **F4 (ticket 06, core notifications screen)** At 200% text the Notifikasi app bar title disappears because the action "Tandai semua dibaca" takes the width (`scale200/Notifikasi.png`).
- **F5 (tickets 03, 04)** At 200% the segmented filter label "Belum dibayar" wraps and touches the segment border (`scale200/Tagihan.png`).
- **F6 (ticket 03, low)** Invoice detail reading order: the Midtrans note comes before "Bayar", while on screen it sits below the button.
- **F7 (tickets 05, 06, low)** The Beranda bell is announced as "Notifikasi" only: the unread count in the badge is not part of its label. In the Layanan card the service row ("Home 10Mbps" and its chip) is centred, while the other cards are left aligned (`live_02_beranda.png`).
- **F8 (info)** The real profile returns `type` "Individual" and `status` "prospective" for the seeded customer; the app parsed it and Akun showed it, no change needed.

Teardown: emulator, API server, Midtrans mock, lock holder and `adb reverse` stopped; the temporary `env/dev.local.json` and system font scale/accessibility settings were reset; the host scripts live in `/tmp` only.

### 2026-10-12: findings fixed (agent MobCustFix07)

Commit `6ecd8d6` in `inbils-mobile` (not pushed): `fix(customer): row semantics, account retry, offline refresh and 200% text layout`. `dart format` 0 changed, `flutter analyze` no issues in core, customer and technician; `flutter test`: core 224, customer 113, technician 154 passed; customer integration on `emulator-5554` (dev flavor): `+80: All tests passed!` (75 before plus the 5 former repros, now regression tests). The AVD is stopped.

- **F1** `MergeSemantics` now wraps the `InkWell` in the rows of Tagihan, Tiket, Riwayat pembayaran and the technician work row. The `unlabeledRowScreens` allowlist is gone, `labeledTapTargetGuideline` runs on every screen. Test: `row_semantics_test.dart` (ex `repro_unlabeled_rows.dart`).
- **F2** "Coba lagi" and pull-to-refresh on Akun, and pull-to-refresh on Beranda, invalidate `meProvider`. Test: `account_retry_test.dart`.
- **F3** New core helper `refreshQuietly` (`packages/inbils_core/lib/src/widgets/refresh_quietly.dart`) ends every `RefreshIndicator` future without rethrowing; the error stays in the provider state (offline banner). Used in the customer and technician lists and details, Notifikasi, Perangkat and Beranda. Test: `pull_refresh_test.dart`.
- **F4** Notifikasi "Tandai semua dibaca" is an icon action (`done_all`, tooltip with the same text), so the title keeps its width at 200%. Core test finder updated.
- **F5** The Tagihan and Tiket filter segments get 12 dp vertical padding, so a wrapped label no longer touches the border.
- **F6** In the invoice detail, "Bayar" has `OrdinalSortKey(0)` and the Midtrans note `OrdinalSortKey(1)`.
- **F7** The bell tooltip reads "Notifikasi, N belum dibaca" (journey 5 asserts it); the Layanan list is start-aligned.

Not re-checked: F4 to F7 by a TalkBack walk or new 200% screenshots (the 200% layout test shows no overflow; the visual result and the F6 reading order were not read back from a screen reader).
