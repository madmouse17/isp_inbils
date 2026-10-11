# UI: IPAM pages (prefixes tree, prefix detail, VLANs)

Status: ready-for-agent
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

- [ ] All three pages render with the spec props and no console errors (`npm run build` clean; ESLint/Prettier clean on new files).
- [ ] Manage controls hidden without `can.manage`; release hidden without the needed permission.
- [ ] A duplicate/overlap 422 from the server shows the server message under the CIDR or address field without losing typed input.
- [ ] Keyboard: every dialog opens, traps focus, closes with Escape and returns focus; utilisation bar has a text equivalent.
- [ ] Contrast of usage badges and utilisation bar colours ≥ 4.5:1 for text (checked with the contrast tool).

## Comments
