# MOBILE: customer invoices (list, detail), pay through Midtrans in a Custom Tab, payment history

Status: ready-for-agent
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

- [ ] Widget tests: each filter sends the right `status`; pages append on scroll; the empty state shows per filter; error and offline states show retry.
- [ ] Detail renders every amount from JSON strings exactly (for example `"154600.00"` → "Rp 154.600") and shows only non-reversed payments.
- [ ] Pay test with a fake adapter and a fake launcher: a double tap sends one POST with one key; the launcher receives the exact `redirect_url` in `inAppBrowserView` mode; a simulated resume triggers two re-reads (now and after 10 s, with a fake clock); the paid and pending banners render; `invoice_not_payable` shows the server reason; `payment_unavailable` shows the copy.
- [ ] "Bayar" is absent when `can_pay` is false, and the blocked reason is visible as text, not colour only.
- [ ] Live on the AVD against the compose stack with sandbox Midtrans keys on the dev host, or the `payment_unavailable` path when keys are absent (state which):
  - Open an unpaid invoice and tap Bayar; the Midtrans sandbox page opens in a Custom Tab.
  - Complete a sandbox payment and return to the app; the invoice shows "Menunggu konfirmasi pembayaran".
  - Midtrans cannot call a localhost webhook, so run `php artisan midtrans:reconcile` and pull to refresh; the invoice shows paid.
  - Record the steps as seen, without keys or tokens.
- [ ] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments
