# MOBILE: customer invoices (list, detail), pay through Midtrans in a Custom Tab, payment history

Status: done
Role: mobile
Blocked by: 02
Cross-lane blocked by: mobile-api 03
Card: none (new lane; no Hermes card)
Skills: antislop-ui, antislop-human, antislop-layoutmobile, ui-ux-pro-max, tdd, ponytail, caveman

## Scope

Implements Q7 and the Invoices, Invoice detail and Payment history rows of the spec's screen table.

- Invoices tab (`/invoices`, label "Tagihan"):
  - A segmented control Belum dibayar / Lunas / Semua (`status=unpaid|paid|all`) and infinite scroll by page.
  - Each row shows number, issue and due dates, a status chip, and for unpaid invoices the remaining amount (`sisa`).
  - An empty state per filter.
  - This tab becomes the landing tab after login until ticket 05.
- Invoice detail (`/invoices/:id`): items, subtotal, tax, discount, total, paid amount, remaining amount, and payments (non-reversed).
  - If `payment.can_pay`, a "Bayar" button; otherwise `payment.blocked_reason`, or "Pembayaran online belum tersedia." when `payment.enabled` is false.
  - Pay:
    1. The button is disabled while the request is in flight. One `Idempotency-Key` serves the tap and its retries.
    2. `POST customer/invoices/{id}/pay`, then open `redirect_url` with `url_launcher` `LaunchMode.inAppBrowserView`, falling back to the external browser.
    3. On `AppLifecycleState.resumed` after a pay launch, re-read the invoice right away and once more after 10 s. Show "Pembayaran diterima" when `paid`, otherwise "Menunggu konfirmasi pembayaran" with a manual refresh.
    4. Codes `invoice_not_payable`, `payment_unavailable` and 429 use the error table.
- Payment history (`/payments`, from the Account tab and invoice detail): amount, method, date, invoice number; tap opens the invoice.
- Models: invoice summary and detail, invoice item, payment, the `payment` block, the pay result. Money stays a string and is formatted with the core formatter.

Non-goals: WebView, App Links, polling loops, partial payments, receipts or PDF download.

## Owned files

- NEW `../inbils-mobile/customer/lib/features/invoices/`, NEW `../inbils-mobile/customer/lib/features/payments/`
- `../inbils-mobile/customer/pubspec.yaml` (`url_launcher`), `../inbils-mobile/pubspec.lock` (regenerated; shared lock-file rule)
- `../inbils-mobile/customer/lib/app/router.dart`, `../inbils-mobile/customer/lib/features/account/` (link to the payment history only), `../inbils-mobile/customer/lib/l10n/`, `../inbils-mobile/customer/test/`
- `../inbils-mobile/customer/android/app/src/main/AndroidManifest.xml` (`<queries>` for browser intents, if `launchUrl` needs them)

## Acceptance criteria

- [x] Widget tests: each filter sends the right `status`; pages append on scroll; the empty state shows per filter; error and offline states show retry.
- [x] Detail renders every amount from JSON strings exactly (for example `"154600.00"` → "Rp 154.600") and shows only non-reversed payments.
- [x] Pay test with a fake adapter and a fake launcher: a double tap sends one POST with one key; the launcher receives the exact `redirect_url` in `inAppBrowserView` mode; a simulated resume triggers two re-reads (now and after 10 s, with a fake clock); the paid and pending banners render; `invoice_not_payable` shows the server reason; `payment_unavailable` shows the copy.
- [x] "Bayar" is absent when `can_pay` is false, and the blocked reason is visible as text, not colour only.
- [x] Live on the AVD against the compose stack with sandbox Midtrans keys on the dev host, or the `payment_unavailable` path when keys are absent (state which):
  - Open an unpaid invoice and tap Bayar; the Midtrans sandbox page opens in a Custom Tab.
  - Complete a sandbox payment and return to the app; the invoice shows "Menunggu konfirmasi pembayaran".
  - Midtrans cannot call a localhost webhook, so run `php artisan midtrans:reconcile` and pull to refresh; the invoice shows paid.
  - Record the steps as seen, without keys or tokens.
- [x] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments

### 2026-10-12: done (agent MobCust03)

Commit `a2005a5` in `inbils-mobile` (not pushed). `dart format --set-exit-if-changed .` clean; `flutter analyze` 0 issues; `flutter test`: core 219, customer 60 (28 new in `invoices_payments_test.dart`), technician 14, all green. `flutter build apk --debug --flavor dev` built.

Decisions
- Tabs are a `StatefulShellRoute` (Tagihan, Akun); landing is `/invoices`. Detail and `/payments` are top-level routes (full screen, back button). A deep link with `go()` has no back stack.
- "Only non-reversed payments": the API already omits reversed ones; the app renders the `payments` array as sent.
- One `Idempotency-Key` per tap, kept for offline, timeout, 5xx, 429 and idempotency_in_progress; a definitive 4xx ends the tap. Only http(s) `redirect_url` is opened. `LaunchMode.inAppBrowserView`, falling back to `externalApplication`.
- Riverpod 3 pauses providers of covered routes, so a paid invoice invalidates the lists and they refetch when you go back (tested, seen live).
- NEW `customer/build.yaml` (`field_rename: snake`, same as core) so models need no per-field `@JsonKey`. `AndroidManifest.xml` got an https `<queries>` intent for url_launcher. `url_launcher` was already in the pubspec and lock, so the lock is unchanged.

Live (AVD `inbils_pixel_api35`, dev flavor, `tests/e2e/setup.php`, Laravel `--env=e2e` on 8011 via `adb reverse`, under `/tmp/inbils-phpunit.lock`): **no real Midtrans sandbox keys were available.** `.env.e2e` has fake keys and `MIDTRANS_BASE_URL=http://127.0.0.1:18911`, so I ran a throwaway local mock of the Snap and status API there (not the real sandbox), plus the `payment_unavailable` path with the keys blanked. Seeded extra invoices with a throwaway script (deleted).
- Login restored on a cold start. Tagihan opened on Belum dibayar with 3 rows: number, dates, status chip, "Sisa Rp …" (partial invoice showed Rp 150.000 of 200.000). Lunas showed 21 invoices across 2 pages after scrolling to the end.
- Detail of an unpaid invoice: subtotal, Diskon, Pajak, Total, Sudah dibayar, item "1 × Rp 154.600", "Belum ada pembayaran.", Bayar enabled.
- Bayar opened the mock page in Chrome `CustomTabActivity` (address bar 127.0.0.1:18911). Closing the tab returned to the app with "Menunggu konfirmasi pembayaran" and "Periksa status".
- Settled the order in the mock, then `php artisan midtrans:reconcile --company=1` (first run checked 0: attempts are only reconciled after 10 minutes, so I backdated `payment_attempts.created_at` in the e2e DB; then 1 checked, 1 settled). Pull to refresh showed "Pembayaran diterima", status Lunas, "Tagihan sudah lunas." as text, no Bayar, payment "Pembayaran online … Rp 154.600".
- Riwayat pembayaran opened from the invoice and from the Akun tab; a row opened its invoice; the unpaid list no longer had the paid invoice.
- Gateway off: with the detail loaded while enabled, Bayar then showed "Pembayaran online belum tersedia. Silakan bayar lewat kantor Inbils."; after a refresh the detail showed "Pembayaran online belum tersedia. Hubungi kami untuk membayar tagihan ini." and no button.
- Teardown: servers, mock, lock holder and AVD stopped; `adb reverse` removed; no tokens or keys recorded.

Known: the pay error banner stays after a refresh until the next tap.
