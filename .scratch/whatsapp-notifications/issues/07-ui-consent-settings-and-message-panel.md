# UI: WhatsApp consent on customer forms, message panel and company WhatsApp settings

Status: ready-for-agent
Role: ui
Blocked by: 02
Card: none (new lane; no Hermes card)

## Scope

Consume ticket 02's props exactly (types in `02-backend-consent-and-company-settings.md`). House components only (`resources/js/Components/ui/*`); no new dependency. Copy in Bahasa Indonesia like the surrounding pages.

- `resources/js/Pages/Admin/Customers/Create.tsx` and `Edit.tsx`: checkbox "Pelanggan setuju menerima notifikasi WhatsApp" bound to `whatsapp_opt_in` (Edit: initial value `customer.whatsapp.opted_in`), helper text naming what is sent and that replying STOP stops it; inline warning when the entered phone will not be reachable is out of scope (server decides) — on Edit show a static warning when `customer.whatsapp.reachable` is false.
- `resources/js/Pages/Admin/Customers/Show.tsx`: "WhatsApp" card next to the phone line (`:79-80`): consent badge (Setuju sejak {date} / Berhenti sejak {date} / Belum setuju), reachability warning, and a table of `whatsappMessages` (event label, subject, status badge, skip reason / error code, masked `••••{last4}`, time). Empty state "Belum ada pesan WhatsApp". Status badge text plus colour (never colour only).
- `resources/js/Pages/Admin/Company/Settings.tsx`: "WhatsApp" section: switch `whatsapp_enabled`, one checkbox per event, quiet hours start/end time inputs, monthly cap number input, read-only "Kredensial terkonfigurasi: Ya/Tidak" and this month's usage counts; disabled controls when `can.update` is false; banner when `whatsapp.configured` is false ("Notifikasi tidak akan terkirim sampai kredensial diatur di server").

Non-goals: portal pages, resend buttons, template editing.

## Acceptance criteria

- [ ] Consent checkbox round-trips on create and edit (Inertia form, validation errors shown inline).
- [ ] Show card renders all six statuses and the empty state; no full phone number rendered anywhere in the WhatsApp card.
- [ ] Settings section saves through the existing settings form; read-only users see values but cannot change them.
- [ ] Keyboard: every control reachable and labelled; status badges readable without colour; contrast AA (check with the antislop contrast tool).
- [ ] Mobile width 375 px: table scrolls horizontally inside its card, no page overflow.
- [ ] `npm run build` and ESLint clean on touched files.
