# UI: WhatsApp consent on customer forms, message panel and company WhatsApp settings

Status: done
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

- [x] Consent checkbox round-trips on create and edit (Inertia form, validation errors shown inline).
- [x] Show card renders all six statuses and the empty state; no full phone number rendered anywhere in the WhatsApp card.
- [x] Settings section saves through the existing settings form; read-only users see values but cannot change them.
- [x] Keyboard: every control reachable and labelled; status badges readable without colour; contrast AA (check with the antislop contrast tool).
- [x] Mobile width 375 px: table scrolls horizontally inside its card, no page overflow.
- [x] `npm run build` and ESLint clean on touched files.

## Comments

### UI agent (IntegrationsUI), 2026-10-13

- Done: consent checkbox in `Customers/Create` and `Edit` (Edit sends `whatsapp_opt_in` only when changed, so a stale form cannot re-opt-in a customer who replied STOP), "WhatsApp" card in `Customers/Show`, WhatsApp section in `Company/Settings` inside the existing form and Save button (the generic list skips `whatsapp_*` keys), types in `types/whatsapp.d.ts`. The monthly cap is kept as text and converted on submit, so an emptied field is a validation error, never a silent `0` (send nothing).
- Evidence: `tests/e2e/whatsapp-notifications.spec.ts` (7 tests) in the 27-test E2E run, plus tsc, ESLint (0 messages) and Prettier clean; PHPUnit (397 passed) as listed in ticket 05. The E2E fixtures cover the statuses sent, delivered and skipped; queued, read and failed render through the same status map (code review only). No role can open the settings page or the customer edit page without also being able to update, so the read-only state is shown by serving the same page with `can.update` false; the server answer (403) is asserted for the manager and the technician.
- Contrast with the antislop tool: status badges use `ToneBadge` (warning and danger tones 5.66:1 to 9.76:1 in both themes); the consent and unreachable alerts use the `Alert` warning variant.
- Screenshots (light/dark, 375/1280) of the customer page, edit form and settings were captured, a representative subset reviewed, then deleted.
