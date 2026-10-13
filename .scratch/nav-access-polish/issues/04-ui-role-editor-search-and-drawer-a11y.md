# UI: role editor search, drawer accessibility, technician flag wiring

Status: done
Role: ui
Blocked by: 03
Spec: `../spec.md`

## Scope

- Role permission editor search (Q4) in `PermissionGroupSelector.tsx`/`permissionGroups.ts` (+ selfcheck); remove the `companionGroups` mapping once ticket 03 renames land (the seeder check keeps guarding).
- Mobile drawer (Q6) in `AdminLayout.tsx` and the `Components/ui` sidebar parts.
- Wire `canTechnicianReport` from shared props into the `NavContext` built in `AdminLayout.tsx`.

## Acceptance criteria

- [x] Search filters across categories; select-all affects only shown permissions; selfcheck covers it.
- [x] Closed drawer not tabbable, Escape closes, focus returns to the toggle, landmarks labelled (browser evidence).

## Comments

- UiEditor: `node resources/js/Components/composite/permissionGroups.selfcheck.mjs` passes (filter, select-all on shown permissions, no companion groups, Indonesian section titles); `npx tsc --noEmit` clean; ESLint + Prettier clean on touched files.
- Drawer: `Sidebar.tsx` marks the `<aside>` `inert` below `lg` while closed, moves focus to the first focusable on open, traps Tab, closes on Escape, restores focus to the toggle (`returnFocusRef`, `aria-expanded` on the toggle). One `<nav aria-label="Navigasi utama">` wraps the groups in `AdminLayout.tsx`; the per-section `<nav>` elements became `<div>` so there is a single labelled landmark.
- 2026-10-12 (orchestrator): browser evidence comes from the 375×812 test in `tests/e2e/sidebar-ia.spec.ts`. It checks that 12 Tab presses never reach a link in the closed drawer, that opening moves focus inside, that the `navigation` landmark "Navigasi utama" exists, and that Escape closes the drawer and returns focus to the toggle. It passed in the full chromium run: 190 passed, 4 skipped, and 3 failures not caused by this ticket (see ticket 07).
