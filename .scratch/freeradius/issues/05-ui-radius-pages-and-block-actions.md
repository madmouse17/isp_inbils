# UI: RADIUS NAS and account pages, block/unblock on Subscriptions/Show and Receivables

Status: ready-for-agent
Role: frontend
Blocked by: 03, 04
Card: none (new lane; no Hermes card)

## Scope

Follow the Props contract in `../spec.md` exactly. Reuse the existing pages and components; no new component library. Skills: inertia-react-development, tailwindcss-development, typescript-advanced-types, antislop-ui, antislop-human.

- **Types**: `resources/js/types/network-asset.d.ts` (new `NasDevice`, `NetworkAccount`, `RadiusSession`; remove `NasApiProtocol`, the router fields of `NasDeviceAccount`, `unmanaged*`), `resources/js/types/models.ts` (`ServiceSubscription` gains `blocked_at`, `block_reason`, `blocked_by`). Delete `resources/js/lib/nasApiProtocol.ts`.
- **NAS pages** (`resources/js/Pages/Admin/NasDevices/*`): form fields router asset, NAS address (`nasname`, prefilled from the asset management IP), CoA port (default 1700, helper text "MikroTik `/radius incoming` port"), RADIUS secret (masked input, blank keeps the stored one on edit, never prefilled, autocomplete off), read-only realm. Show: RADIUS status (`radius_synced_at`/`last_error`), the "reload FreeRADIUS after adding this NAS or changing its secret" notice, open sessions table (username → account link, address, start), isolir URL with copy button. Remove "Check now", write flag, RouterOS version, reachability and unmanaged UI.
- **Account page** (`resources/js/Pages/Admin/NetworkAccounts/Show.tsx`): username input with a fixed `@realm` suffix; no NAS select; one-time secret from flash key `network_account.secret`; status card: online badge, address, NAS, session start, this month's upload/download (human bytes); desired state with `isolatedBecause` (Suspended / Blocked); notice when RADIUS is off or unavailable. Keep Reveal and Resync.
- **Subscriptions/Show** (`resources/js/Pages/Admin/Subscriptions/Show.tsx:194-278`): a "Blocked" badge with reason, who and when; with `can.block`: "Block internet" (modal, required reason ≤500, posts `admin.subscriptions.block`) or "Unblock" (modal, optional note, posts `admin.subscriptions.unblock`). When suspended and blocked, the unblock modal says the service stays isolated until reactivated.
- **Receivables** (`resources/js/Pages/Admin/Billing/Receivables.tsx:72-247`): per subscription a "Blocked" badge when `blocked_at`; with `can.block`: Block/Unblock buttons next to "Isolir" using the same modal pattern; Isolir stays as is (`source: 'overdue'`).
- `resources/js/Components/composite/NetworkAccessBadges.tsx`: drop router reachability badges; keep sync and online badges for the new types.
- States: loading/empty/error per page; keyboard-reachable modals with focus return; labels on every input; dark mode; 375 px without horizontal overflow (as `tests/e2e/mikrotik-provisioning.spec.ts:512-535` checked).

Owned files: `resources/js/Pages/Admin/NasDevices/Index.tsx`, `Create.tsx`, `Edit.tsx`, `Show.tsx`, `resources/js/Pages/Admin/NetworkAccounts/Show.tsx`, `resources/js/Pages/Admin/Subscriptions/Show.tsx`, `resources/js/Pages/Admin/Billing/Receivables.tsx`, `resources/js/types/network-asset.d.ts`, `resources/js/types/models.ts`, `resources/js/Components/composite/NetworkAccessBadges.tsx`, DELETE `resources/js/lib/nasApiProtocol.ts`.

Consumes: routes and props from tickets 03 and 04.

## Acceptance criteria

- [ ] `npm run build` and ESLint on touched files clean; no `any`.
- [ ] `grep -rniE "routeros|api_protocol|provisioning_enabled|isolir_profile|unmanaged|nasApiProtocol" resources/js` → no hits.
- [ ] No secret ever rendered except the one-time flash value; secret inputs reset after submit.
- [ ] Manual check (screenshots without secrets) of the four page groups at 375 px and 1280 px, light and dark.

## Commands

```
npm run build && npx eslint resources/js/Pages/Admin/NasDevices resources/js/Pages/Admin/NetworkAccounts resources/js/Pages/Admin/Subscriptions/Show.tsx resources/js/Pages/Admin/Billing/Receivables.tsx resources/js/types resources/js/Components/composite/NetworkAccessBadges.tsx
```

## Comments
