# UI: ODN pages (occupancy overview, OLT/ODC/ODP port editor)

Status: done
Role: ui
Blocked by: 02
Card: none (new lane; no Hermes card)

## Scope

Owned files (no other ticket edits these):

- NEW `resources/js/Pages/Admin/Odn/Index.tsx` — three tabs: **ODPs** (code, name, location path, capacity, used, free, utilisation bar + percent; filters POP, minimum percent, search; full ODPs visibly flagged in text, not colour only), **PON ports** (OLT code, port number/label, subscribers), **Unmapped subscriptions** (code, customer, serving POP, status, link to the subscription). Rows link to `admin.odn.assets.show`.
- NEW `resources/js/Pages/Admin/Odn/Asset.tsx` — for an OLT: PON port list with subscriber counts, "Add PON ports" dialog (`count`), delete per port (`can.manage`). For an ODC/ODP: splitter cards (label, ratio 1:N, input port select from `inputOptions`, output ports grid showing free / customer / feeds-splitter), "Add splitter" dialog (`outputs`, `input_port_id`, `label`), reconnect and delete (`can.manage`); release drop per output (`can.assign`).

Props: exactly the `Admin/Odn/*` shapes in `../spec.md`. Inertia `useForm` only (ADR-002); `resources/js/Components/ui/*` primitives; mimic `resources/js/Pages/Admin/NetworkAssets/Show.tsx` for dialogs and action buttons. 422 messages under the field. Empty states ("No PON ports yet", "No splitter in this ODP"). Dark mode; phone width with the port grid wrapping.

Non-goals: navigation entry and the drop-port picker on subscription/onboarding pages (ticket 06), loss budget, maps.

## Acceptance criteria

- [x] Both pages render with the spec props and no console errors; `npm run build`, ESLint and Prettier clean on new files.
- [x] Manage and assign controls hidden without the matching `can` flag.
- [x] Port state is conveyed by text or icon plus label, not colour alone; grid cells are reachable by keyboard and have accessible names ("Port 3, customer SUB-0012").
- [ ] Server 422 (occupied port, cycle, wrong host type) shows inline without closing the dialog. Implemented (field errors and an alert for the `asset` key) but not exercised: the pickers offer only free ports, so no UI path produces these refusals; the server side is covered by the backend tests.

## Comments

- 2026-10-13 (IpamOdnUI): `Pages/Admin/Odn/{Index,Asset}.tsx` plus `composite/odn/{SplitterCard,SplitterDialog,PonPortsDialog}.tsx`. `ipam-odn.spec.ts` adds PON ports, adds a 1:8 splitter, disconnects and reconnects its input, and checks the free-port tiles by accessible name; staff and technician see no manage control.
