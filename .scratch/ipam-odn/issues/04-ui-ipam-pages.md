# UI: IPAM pages (prefixes tree, prefix detail, VLANs)

Status: done
Role: ui
Blocked by: 01
Card: none (new lane; no Hermes card)

## Scope

Owned files (no other ticket edits these):

- NEW `resources/js/Pages/Admin/Ipam/Prefixes/Index.tsx` — prefix tree (flat rows indented by depth) with family/usage/POP/VLAN filters and search; columns CIDR, usage badge, POP, VLAN, size, used/free, utilisation bar + percent (`null` → "—", dynamic pools show size only); "New prefix" dialog (`cidr`, `usage`, `location_id`, `vlan_id`, `description`) shown only with `can.manage`.
- NEW `resources/js/Pages/Admin/Ipam/Prefixes/Show.tsx` — prefix header with utilisation, children list, paginated address table (address, status, holder link, note, assigned at), "Reserve address" dialog (`can.manage`), release button per row (subscription-held rows need `can.assign`; asset-held rows show "edit asset" link instead), edit/delete actions (`can.manage`).
- NEW `resources/js/Pages/Admin/Ipam/Vlans/Index.tsx` — paginated VLAN table (VID, name, POP, prefixes count) with create/edit/delete dialogs (`can.manage`).

Props: exactly the `Admin/Ipam/*` shapes in `../spec.md` Implementation Decisions. Forms use Inertia `useForm` (ADR-002, `docs/architecture/decisions/ADR-002.md:5`); primitives from `resources/js/Components/ui/*` and existing composites; mimic `resources/js/Pages/Admin/NetworkAssets/Index.tsx` and `Show.tsx` for layout, table and dialog patterns. 422 messages render next to the field. Empty, loading and error states for every table. Dark mode and phone width (no horizontal page scroll; tables scroll inside their card).

Non-goals: navigation entry, subscription panel (ticket 06), charts, maps.

## Acceptance criteria

- [x] All three pages render with the spec props and no console errors (`npm run build` clean; ESLint/Prettier clean on new files).
- [x] Manage controls hidden without `can.manage`; release hidden without the needed permission.
- [x] A duplicate/overlap 422 from the server shows the server message under the CIDR or address field without losing typed input.
- [x] Keyboard: every dialog opens, traps focus, closes with Escape and returns focus; utilisation bar has a text equivalent.
- [x] Contrast of usage badges and utilisation bar colours ≥ 4.5:1 for text (checked with the contrast tool).

## Comments

- 2026-10-13 (IpamOdnUI): done. Files: `Pages/Admin/Ipam/{Prefixes/Index,Prefixes/Show,Vlans/Index}.tsx` plus composites `Components/composite/{UtilisationBar,ConfirmRequestDialog}.tsx`, `composite/ipam/*`, `lib/ipam.ts`, `types/ipam-odn.ts`. `tests/e2e/ipam-odn.spec.ts` covers the duplicate 422 (input kept), Escape plus focus return, reserve/release, hidden manage controls for staff. Meter colours (red-700/amber-700/emerald-700 light, 400 dark) and usage badges checked 4.58–5.91:1 light and 5.29–8.57:1 dark with the contrast tool. `IpamOdnBackend` added `locations` and `vlans` props to the prefix show page for the edit dialog (not in the spec shapes).
