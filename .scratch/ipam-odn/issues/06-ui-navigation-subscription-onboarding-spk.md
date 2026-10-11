# UI: navigation, subscription IP + drop panels, onboarding drop picker, SPK and asset links

Status: ready-for-agent
Role: ui
Blocked by: 03, 04, 05
Card: none (new lane; no Hermes card)

## Scope

Owned files (no other ticket edits these):

- `resources/js/lib/navigation.ts` — group `network-work-orders` (Network Assets entry at `:259-262`) gains "IP Addresses" (`/admin/ipam/prefixes`, `anyOf: ['ipam.view']`) and "ODN" (`/admin/odn`, `anyOf: ['odn.view']`). The MikroTik/RADIUS lane may also edit this file: land serially.
- `resources/js/Pages/Admin/Subscriptions/Show.tsx` — "IP address" panel (list `ipAddresses`; "Dynamic (assigned by router)" when empty; assign dialog with pool select from `staticPools` and optional specific address; release per row; actions only with `ipam.assign`) and "Drop port" panel (current `dropPort` with link to the ODP page; set/move dialog loading `dropPortOptions` by Inertia partial reload; release; actions only with `odn.assign`).
- `resources/js/Pages/Admin/Customers/Create.tsx` — optional "Drop port (ODP)" select under the subscription block, reloaded via `router.reload({ only: ['dropPortOptions'], data: { serving_pop_id } })` when the serving POP changes; shows "No free port under this POP" when empty; bound to `subscription.odn_port_id`; hidden without `odn.assign`.
- `resources/js/Pages/Admin/SPK/Show.tsx` — read-only "Drop port" line (ODP code/name, port number, location path) when `dropPort` is present.
- `resources/js/Pages/Admin/NetworkAssets/Show.tsx` — "ODN ports" link button to `admin.odn.assets.show` for `olt|odc|odp` assets (only with `odn.view`); no new backend prop.

Inertia `useForm`/partial reloads only (ADR-002); existing primitives; keep the current layout of each page.

Non-goals: new pages (tickets 04, 05), backend props (ticket 03).

## Acceptance criteria

- [ ] Menu shows the two entries only for users holding the permission (admin, manager, staff, technician per Q14; customer none).
- [ ] Onboarding without choosing a port submits exactly as before (`tests/e2e/customer-subscription.spec.ts` still passes); choosing a port submits `subscription.odn_port_id`.
- [ ] Changing the serving POP clears a previously chosen port and reloads options; no `fetch`/`axios` call added.
- [ ] Subscription page assign/release round-trips show the new state after redirect; 422 messages inline.
- [ ] `npm run build`, ESLint and Prettier clean on changed files; keyboard and focus behaviour of new dialogs as in ticket 04.

## Comments
