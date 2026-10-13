# UI: Indonesian navigation labels matching page titles, distinct icons

Status: done
Role: ui
Blocked by: none
Spec: `../spec.md`

## Scope

- Apply the spec's Translations to `resources/js/lib/navigation.ts` (group titles and item labels) and to the `PageHeader` title and `AdminLayout title` of every destination page, so label = heading.
- One distinct heroicon per item (Q7).
- Permissions item gate becomes `roles.manage` (Q2, contract with ticket 02). Technician Performance item gets flag `canTechnicianReport` on top of `report.technician` (Q5, contract with tickets 04/05); add the flag to `NavFlag`/`NavContext`.
- Update `tests/Feature/fixtures/sidebar-destinations.json`, `navigation.selfcheck.mjs`, `tests/Feature/AdminMenuAuthorizationTest.php` and every E2E spec that names a sidebar link, group or one of these page headings.

## Acceptance criteria

- [ ] Every sidebar label equals its destination heading; selfcheck, fixture parity and AdminMenuAuthorizationTest green.
- [ ] No two items share an icon.
- [ ] E2E specs referencing old labels/titles updated and green.

## Comments

- 2026-10-13 UiNav: `node --test resources/js/lib/navigation.selfcheck.mjs` 23/23 (adds one-icon-per-item, Kinerja Teknisi flag, Hak Akses `roles.manage` cases); `npx tsc --noEmit` clean; ESLint 0 errors on navigation.ts and touched pages; Prettier clean; `AdminMenuAuthorizationTest` 35 passed (new "manager without an organization" role case asserts `canTechnicianReport` false and the report link hidden/403). E2E specs updated (sidebar-ia incl. a 375px drawer test, auth-roles, technician-performance no-organization manager, datatable-*, head-company, ipam-odn, radius, reporting-*, stock/inventory, support/demo) but not run here: the canonical run is the orchestrator's.
