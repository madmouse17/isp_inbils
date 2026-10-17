# Customer and technician app redesign: ISP-branded, informative, not flat

Status: ready-for-agent
Ledger: `docs/plans/INBILS_OMP_BACKLOG.md` → "ISP roadmap lanes (2026-10-12)" row `mobile-redesign`
Source: owner 2026-10-14 (grilling): "gunakan skill ui ux promax maupun design taste … untuk ui ux flutter biar tidak terlihat flat monoton hanya putih, dan cari referensi buat biar lebih bagus dan informatif terutama di pelanggan". Owner approved M1–M4 (M4 point 4 became lane `announcements-promo`); round 2 V1–V10 answered with "lanjutkan task semuanya sampai selesai", read as accepting every recommendation, V3 in its revised form (a).
Research: agent IspUiResearch (Oxygen.id Selfcare, MyICON+, MyBiznet seen as pixels; myIndiHome, MyRep, My FirstMedia, myXL, Starlink, Xfinity, Verizon, Spectrum, Virgin Media, Optus from text). Facts: agent FlutterUiFacts.

## Problem

Both apps reuse the web shadcn slate theme (`packages/inbils_core/lib/src/theme/inbils_colors.dart`, `inbils_theme.dart`): white surfaces, `#F8FAFC` cards with 1px borders, radius 8, no focal point. Customer Home is three equal cards (Layanan, Tagihan, Tiket aktif); the amount owed is body text; the bottom third is empty. The API already carries fields the UI ignores (next invoice date, billing day, contract, ticket priority/category), and lacks branding, contact and connection data.

## Decisions

- **M1 One app, ISP-branded after login.** Before login: neutral Inbils. After login: the ISP's logo and brand colour from the API. Admins set them on the web Company page.
- **M2 Scope and order.** Customer app first, technician app on the same design system. Runs in `inbils-mobile` after `sla-targets`; mobile tickets of other lanes (`package-categories` 04, `field-trip-status` 04/05, `opaque-ids` 05) start only after this lane's customer screens are merged, and build on them.
- **M3 Dark mode.** Follows the phone setting, manual override (Sistem / Terang / Gelap) in Akun, persisted.
- **M4 Customer Home order.** 1) hero card: service state (Aktif / Diisolir / Ditangguhkan), package name, next bill with amount, due date and a large **Bayar**; isolir explains the cause and recovery. 2) quick actions: Bayar, Laporkan gangguan, Riwayat pembayaran, Hubungi ISP. 3) "Sedang berjalan": open tickets, technician visit (visit card arrives with `field-trip-status`). 4) announcements and promo carousel (lane `announcements-promo`; this lane renders them once its API exists).
- **V1 Visual direction A "Warna ISP".** Tonal palette derived from the ISP brand colour (default Inbils blue `#2563EB`) via `ColorScheme.fromSeed` (`DynamicSchemeVariant.fidelity`, decided 2026-10-14 after ticket 03: tonal spot turned orange `#EA580C` into brown `#8E4C32`; fidelity keeps the ISP hue at ≥ 6.6:1 against white text); tinted background (`surfaceContainerLowest`/`surface` from the seed, not pure white); borderless cards on tonal surfaces; hero card radius 28 with one flat wave motif (`CustomPainter`, brand tone, no gradient/glow/glass); Figtree (bundled); numerals large (`displaySmall` for the amount due).
- **V2 Branding data.** Company page (web) gains: brand colour (8 presets + hex input, contrast warning against white text), WhatsApp number, support hours (free text ≤ 120, e.g. "Senin–Sabtu 08.00–20.00"). Logo reuses the existing company logo. `me.company` in the mobile API gains `logo_url`, `brand_color`, `phone`, `whatsapp`, `email`, `website`, `support_hours`.
- **V3 Connection status (revised, option a).** Subscription hero shows "Online sejak 08.12" or "Offline, terakhir online 07.55" only when RADIUS is enabled and the subscription has a PPPoE username; otherwise nothing (no fake data). Usage is not shown (start-time accounting would under-count long sessions; parked until production FreeRADIUS and monthly snapshots exist). The customer API returns only `status` (`online`|`offline`), `since` and `last_seen_at`; never NAS IP, framed IP or session ids. No modem restart, no speed test.
- **V4 Bill states on the hero.** Lunas: calm, "Tagihan berikutnya {tanggal}". Due within 7 days: warning tone, "Jatuh tempo {n} hari lagi". Overdue: danger tone, "Lewat {n} hari". Isolir: danger hero with cause, amount and Bayar, copy "Layanan aktif kembali otomatis setelah pembayaran diterima" (backed by `OverdueSuspensionService::reactivateIfCleared`). Every state has an icon and text, never colour alone.
- **V5 "Ada masalah?" flow.** Pick a ticket category → short tips from the category's new web field "Saran untuk pelanggan" (`ticket_categories.customer_hint`, ≤ 500, edited on Kategori Tiket) → "Masih bermasalah? Buat tiket" opens the ticket form with category and subscription prefilled.
- **V6 Icons and empty states.** Material rounded icons (built in, no new dependency); no illustration pack; empty state = large icon in a tinted circle + one sentence + one action; the wave motif only on the hero.
- **V7 Navigation.** Four tabs: Beranda, Tagihan, **Bantuan** (tickets + "Ada masalah?" + ISP contact; renamed from Tiket), Akun. Langganan opens from the hero and from Akun.
- **V8 Payment hint.** Under Bayar: one line "QRIS · VA bank · e-wallet · minimarket" with generic icons; no bank logos (Midtrans shows them).
- **V9 Launcher and splash.** Monogram "in" on Inbils blue for the launcher icon and a wordmark splash (one app for all ISPs); replaced when the owner provides a logo. After login the header shows the ISP logo.
- **V10 Technician app.** Same design system and ISP branding; Home focuses on today: next visit with distance and a navigate button, then counts per status.
- **R1 Accessibility.** Contrast AA for every tone on the seeded scheme (asserted in a widget test across the 8 presets and both modes), text scale 200 % without overflow on Home, `Semantics` on the hero and quick actions, touch targets ≥ 48.
- **R2 No new state/data packages.** Allowed new packages only if essential and reported: `flutter_launcher_icons` and `flutter_native_splash` as dev dependencies (V9). Network logos load with the SDK `Image.network` plus an error/placeholder builder.

## Testing decisions

- Theme: seeded scheme contrast across presets (light/dark); fallback to Inbils blue on a missing or invalid colour.
- Home: golden-free widget tests per hero state (paid, due soon, overdue, isolir, connection shown/hidden), quick actions present, text scale 2.0 without overflow.
- Bantuan: flow from category to prefilled ticket form; hint shown when present.
- API (web): `me.company` new fields; connection endpoint states (radius disabled → 404 or `null`, no PPPoE → `null`, online, offline with last seen) and forbidden fields absent; company form validation (hex, phone pattern, length); category hint validation.
- Device run: customer journeys on the AVD `inbils_pixel7` with screenshots of Home in light and dark mode, two brand colours.

## Tickets

01-backend-branding-connection-hint-api · 02-ui-web-branding-and-hint-fields · 03-mobile-design-system · 04-mobile-customer-home-and-screens · 05-mobile-bantuan-flow · 06-mobile-technician-app · 07-qa-device-review-release · 08-mobile-motion-and-network-icons · 09-backend-package-speed-fields
