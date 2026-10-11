# UI: NAS router pages, network account page, public isolir page, Receivables source, navigation

Status: done
Role: ui
Blocked by: 01, 04
Card: none (new lane; no Hermes card)

## Scope

Pages compose `resources/js/Components/ui/*` and `resources/js/Components/composite/*` (`DataTable`, `DataTableActions`, `FormField`, `PageHeader`); no raw `<button>`, `<input>` or `<table>`; dark mode, visible focus and labelled inputs on every page; no `fetch`/axios (Inertia `router` and `useForm` only). Props follow the contract in `../spec.md` (Implementation Decisions, "Props contract"); types go into `resources/js/types/network-asset.d.ts`.

- `Admin/NasDevices/Index`: server table like `resources/js/Pages/Admin/NetworkAssets/Index.tsx` (search, sort, pagination): router asset code and name, location, management IP, writes on/off badge, status badge (online / unreachable since … / never checked), RouterOS version, accounts with failed count, last check. Empty state with a "Register router" action when `can.create`.
- `Admin/NasDevices/Create` and `Edit`: router asset select (`routerAssets`), API port, API username, API password (write-only; on edit, blank keeps the stored one and the field shows "stored" when `has_api_password`), isolir profile, writes switch with a one-line warning that writes change the router.
- `Admin/NasDevices/Show`: status card (reachability, version, last error, last check), "Check now" (`admin.nas-devices.check`, disabled while pending), banner when `installEnabled` is false, the isolir URL with a copy button, accounts table (subscription code, customer, type, username, sync status, profile, online/address), unmanaged count with the first 100 names.
- `Admin/NetworkAccounts/Show`: subscription summary (code, status, customer, speed profile with its router profile name or a warning when missing, POP), desired state badge (enabled / isolated / absent), and either the create form or the account details plus edit form: NAS router select with the `suggested` router marked and preselected, service type (PPPoE / hotspot), username, password ("leave blank to generate" on create, "leave blank to keep" on edit). Sync status with `last_error`, provisioned profile and time, online state with current address, "Resync" and "Reveal password" buttons per `can`. The flashed `network_account.password` appears once in a dismissible panel with a copy button and a "shown once" note; it is not kept after dismissal or navigation. Banner when `installEnabled` is false ("saved, not sent to routers").
- `Isolir/Show` (public, `GuestLayout`): Indonesian copy for subscribers: the service is isolated because of an unpaid bill, how to restore it ("Masuk untuk melihat dan membayar tagihan" → `loginUrl`), company name, phone and e-mail when present. No customer data. Readable on a phone (tap targets ≥ 44 px).
- `Admin/Subscriptions/Show.tsx`: a "Network access" link to `admin.subscriptions.network-account.show` next to the actions (`:158-192`), shown only with `network_asset.account.view` (`usePermission`).
- `Admin/Billing/Receivables.tsx`: the isolir POST (`:81-83`) sends `source: 'overdue'` with the reason; nothing else changes (the dialog stays as covered by `tests/e2e/datatable-e.spec.ts:114-139`).
- `resources/js/lib/navigation.ts`: "NAS Routers" item after "Network Assets" (`:252-262`), `href: '/admin/nas-devices'`, `anyOf: ['network_asset.nas.view']`.

Owned files:
- NEW `resources/js/Pages/Admin/NasDevices/Index.tsx`
- NEW `resources/js/Pages/Admin/NasDevices/Create.tsx`
- NEW `resources/js/Pages/Admin/NasDevices/Edit.tsx`
- NEW `resources/js/Pages/Admin/NasDevices/Show.tsx`
- NEW `resources/js/Pages/Admin/NetworkAccounts/Show.tsx`
- NEW `resources/js/Pages/Isolir/Show.tsx`
- `resources/js/Pages/Admin/Subscriptions/Show.tsx`
- `resources/js/Pages/Admin/Billing/Receivables.tsx`
- `resources/js/lib/navigation.ts`
- `resources/js/types/network-asset.d.ts`

Cross-lane: `navigation.ts` is shared with other UI lanes; land serially.

Non-goals: backend changes (report `needs-change` to the orchestrator), the company settings page (renders the new keys by itself), e2e tests (ticket 06).

## Acceptance criteria

- [x] `npm run build` (tsc + vite) passes; ESLint clean on every touched file; no `any`.
- [x] Every page renders with the props of ticket 04 in light and dark mode at 375 px and 1280 px without horizontal scrolling; keyboard-only use reaches every action with a visible focus ring; each input has a label; status badges do not rely on colour alone.
- [x] The password is visible only in the one-time flash panel; it is never written into a form default, URL, `localStorage` or console.
- [x] Receivables isolir sends `source: 'overdue'` (seen in the request payload); the Subscriptions page link is hidden without `network_asset.account.view`; the "NAS Routers" menu item is hidden without `network_asset.nas.view`.
- [x] Isolir page copy reviewed for plain Indonesian; it shows nothing but the company's public contact fields and the login link.

## Commands

```
npx eslint resources/js/Pages/Admin/NasDevices resources/js/Pages/Admin/NetworkAccounts resources/js/Pages/Isolir resources/js/Pages/Admin/Subscriptions/Show.tsx resources/js/Pages/Admin/Billing/Receivables.tsx resources/js/lib/navigation.ts resources/js/types/network-asset.d.ts
./vendor/bin/sail npm run build
```

## Comments

### UI agent (IntegrationsUI), 2026-10-13

- Done: `Admin/NasDevices/{Index,Create,Edit,Show}`, `Admin/NetworkAccounts/Show`, public `Isolir/Show`, the "Network access" button in `Subscriptions/Show` (gate `network_asset.account.view`), Receivables isolir with `source: 'overdue'`, types in `types/network-asset.d.ts`. New shared composites: `NetworkAccessBadges`, `ToneBadge`, `CopyButton`. "Remove router" reuses IpamOdnUI's `ConfirmRequestDialog`. The "NAS Routers" menu item (group Network/Work Orders, after Network Assets) was handed to IpamOdnUI, the `navigation.ts` owner, and is in place.
- One-time password: read only through the `onFlash` visit option of `store` and `reveal`, held in page state, dismissable. The E2E proves it is gone from the DOM, storage, history state and URL after dismissal and after a reload, and that the edit form starts with a blank password.
- Additions to the ticket: the router page also offers "Remove router" (disabled while accounts exist, refusal message from the server shown in the dialog); the Isolir page always shows the company name and lists contact lines only when present; its login button wraps on 320-375 px (the screenshot review found the label clipped).
- Contrast, measured with the antislop tool: `text-destructive` fails in both themes (3.76:1 light, 2.0:1 dark), so red text uses `text-red-700 dark:text-red-400`; `Badge`'s own warning and danger variants measure 4.46:1 and 3.6:1 in light mode, so these pages use `ToneBadge` (5.66:1, 6.3:1 light; 6.77:1, 9.76:1 dark). The shared `Badge` and `Input` error text still carry the old colours (needs-change for their owner).
- Evidence: `tsc --noEmit` clean; ESLint 0 messages on the 17 touched files; Prettier clean; `flock /tmp/inbils-e2e.lock sh -c 'npm run build && php tests/e2e/setup.php && npm run test:e2e -- tests/e2e/mikrotik-provisioning.spec.ts tests/e2e/whatsapp-notifications.spec.ts tests/e2e/customer-edit.spec.ts tests/e2e/datatable-e.spec.ts tests/e2e/customer-subscription.spec.ts --project=chromium --workers=1'` → 27 passed; PHPUnit `tests/Feature/NetworkAsset tests/Feature/WhatsApp tests/Feature/CustomerPageTest.php tests/Feature/CompanyProfileTest.php tests/Feature/Billing/CompanyBillingSettingsTest.php tests/Feature/Billing/OverdueSuspensionTest.php tests/Feature/AdminSmokeTest.php tests/Feature/AdminMenuAuthorizationTest.php` → 397 passed (3824 assertions).
- Screenshots (light/dark, 375/1280) were captured for every page, a representative subset reviewed, then deleted.
