# MOBILE: customer Home (Beranda) and subscriptions

Status: ready-for-agent
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

- [ ] Widget tests:
  - each card renders loading, data, empty ("Tidak ada tagihan" when nothing is unpaid, "Tidak ada tiket aktif" when no ticket is open) and error-with-retry on its own; with one fake endpoint failing, the other two cards still show data;
  - "Bayar" appears only when the earliest invoice's `can_pay` is true;
  - pull to refresh calls all three endpoints again.
- [ ] Subscriptions list and detail render every spec field, and status is shown as text plus colour.
- [ ] After login, the app lands on Beranda, and the bottom navigation has exactly Beranda, Tagihan, Tiket, Akun, each with labels and a TalkBack semantics label.
- [ ] At 200% text scale on the AVD, every card keeps its actions reachable, nothing is clipped, and touch targets are at least 48 dp (checked with the Flutter accessibility guideline tests `androidTapTargetGuideline`, `textContrastGuideline`, `labeledTapTargetGuideline`).
- [ ] Live on the AVD against the compose stack: the seeded customer sees the subscription, the unpaid invoice and the open tickets matching the web portal. Recorded as seen.
- [ ] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments
