# MOBILE: customer Home (Beranda) and subscriptions

Status: done
Role: mobile
Blocked by: 04 (Home reuses the invoice and ticket repositories; router edited by one ticket at a time)
Cross-lane blocked by: mobile-api 02 (subscriptions)
Card: none (new lane; no Hermes card)
Skills: antislop-ui, antislop-human, antislop-layoutmobile, ui-ux-pro-max, design-taste-frontend (home layout), tdd, ponytail, caveman

## Scope

Implements the Home and Subscriptions rows of the spec's screen table. Home becomes the landing tab, and the bottom navigation is final: Beranda, Tagihan, Tiket, Akun. The notification bell is added by ticket 06.

- Home (`/home`). Three independent cards, each loading in parallel and each with its own error and retry, so one failure never blanks the screen:
  1. Layanan: subscriptions with package and status chip; tap opens the subscription.
  2. Tagihan: the count of unpaid invoices from `meta.total` of `GET customer/invoices?status=unpaid`, and the earliest-due one with remaining amount and due date. "Bayar" when its detail says `can_pay` (reusing the ticket 03 pay flow); otherwise the button goes to the invoice.
  3. Tiket aktif: the count from `GET customer/tickets?status=active`, and a shortcut to "Buat tiket".
  - Pull to refresh reloads all cards. The greeting uses `Me.name`.
- Subscriptions (`/subscriptions`, `/subscriptions/:id`, reached from Home and Account): package, monthly charge, status chip with text, activation date, billing day, next invoice date, contract months, expiry, installation address. Detail ids come only from the list.

Non-goals: plan changes, relocation, speed test, outage banners (no backend), any client-side money arithmetic.

## Owned files

- NEW `../inbils-mobile/customer/lib/features/home/`, NEW `../inbils-mobile/customer/lib/features/subscriptions/`
- `../inbils-mobile/customer/lib/app/router.dart`, `../inbils-mobile/customer/lib/features/account/` (link to subscriptions only), `../inbils-mobile/customer/lib/l10n/`, `../inbils-mobile/customer/test/`

## Acceptance criteria

- [x] Widget tests:
  - each card renders loading, data, empty ("Tidak ada tagihan" when nothing is unpaid, "Tidak ada tiket aktif" when no ticket is open) and error-with-retry on its own; with one fake endpoint failing, the other two cards still show data;
  - "Bayar" appears only when the earliest invoice's `can_pay` is true;
  - pull to refresh calls all three endpoints again.
- [x] Subscriptions list and detail render every spec field, and status is shown as text plus colour.
- [x] After login, the app lands on Beranda, and the bottom navigation has exactly Beranda, Tagihan, Tiket, Akun, each with labels and a TalkBack semantics label.
- [x] At 200% text scale on the AVD, every card keeps its actions reachable, nothing is clipped, and touch targets are at least 48 dp (checked with the Flutter accessibility guideline tests `androidTapTargetGuideline`, `textContrastGuideline`, `labeledTapTargetGuideline`).
- [x] Live on the AVD against the compose stack: the seeded customer sees the subscription, the unpaid invoice and the open tickets matching the web portal. Recorded as seen.
- [x] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments

### 2026-10-12: done (agent MobCust05)

Commit `71702f2` in `inbils-mobile` (not pushed). `dart format --set-exit-if-changed .` clean; `flutter analyze` 0 issues; `flutter test`: customer 92 (new `home_subscriptions_test.dart`), core 219, technician 14, all green.

Decisions
- Beranda is the first tab and landing (`/home`); tabs Beranda, Tagihan, Tiket, Akun. Home cards load independently (`homeSubscriptions`, `homeInvoices`, `homeTickets`); each has its own spinner, error text and "Coba lagi". Pull to refresh invalidates all.
- Unpaid card: `meta.total` of `customer/invoices?status=unpaid`; the server lists unpaid by `due_date` ascending, so the first row is the earliest; its detail is read for `payment.can_pay`. The button reads "Bayar" only when `can_pay` is true, otherwise "Buka"; both open the invoice detail, where the ticket 03 pay flow lives (no second copy of the pay logic).
- Subscriptions read all pages of `customer/subscriptions` (`per_page=50`); `/subscriptions/:id` is reached only from the list and Home; Account has a "Langganan" row.
- Fixed the invoice list chip: the status chip sits under the text instead of a `Flexible` in the row.
- Existing tests adapted to the new landing tab (invoice/ticket tests clear Beranda's requests, then open their tab).
- Not built: a pay-in-place button on Home (it opens the invoice).

Live (AVD `inbils_pixel_api35`, dev flavor, `tests/e2e/setup.php`, `APP_ENV=e2e php artisan serve --port 8011` via `adb reverse`, under `/tmp/inbils-phpunit.lock`):
- Login lands on Beranda: "Halo, Demo Customer", Layanan "Home 10Mbps" Aktif, Tagihan "1 tagihan belum dibayar, E2E-REC-LINKED, Jatuh tempo 27 Sep 2026, Sisa Rp 120.000" with Bayar, Tiket aktif "12 tiket aktif" with Buat tiket; this matched the API (1 subscription, 1 unpaid, 12 active tickets). Bottom bar: Beranda, Tagihan, Tiket, Akun, "Tab n dari 4".
- Service tap opened the detail: Aktif, package, Rp 150.000, activation 11 Okt 2026, "Setiap tanggal 1", next invoice 12 Nov 2026, 12 bulan, expiry "Tidak tersedia", installation address. Bayar opened the invoice detail with its Bayar button; Buat tiket opened "Tiket baru"; Akun showed "Langganan"; Tagihan rows show the chip below the text.
- Font scale 2.0 on the AVD: all three cards stay readable, scroll to the end, Bayar and Buat tiket are 126 px (48 dp at 420 dpi) high. Guideline tests (`androidTapTargetGuideline`, `labeledTapTargetGuideline`, `textContrastGuideline`) pass at 2x text in the widget test.
- Not exercised live: error and empty cards (widget tests only).
- Teardown: server, lock holder and AVD stopped, `adb reverse` removed, `env/dev.local.json` deleted, font scale reset, no tokens recorded.
