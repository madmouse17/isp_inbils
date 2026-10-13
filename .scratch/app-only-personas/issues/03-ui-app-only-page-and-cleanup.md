# UI: AppOnly page, sidebar/dashboard cleanup, E2E updates

Status: done
Role: ui
Blocked by: none (props contract in ticket 02)
Spec: `../spec.md`

## Scope (owned files)

- New `resources/js/Pages/AppOnly.tsx` (GuestLayout, copy per spec R1, buttons from `Components/ui`, dark mode, keyboard focus).
- `resources/js/lib/navigation.ts` + `navigation.selfcheck.mjs` + `tests/Feature/fixtures/sidebar-destinations.json` + `tests/Feature/AdminMenuAuthorizationTest.php`: remove "Tagihan Saya" (portal invoices). Keep "Pekerjaan Saya".
- Dashboard: remove the technician and customer persona cards/branches (`Pages/Admin/Dashboard/**`, `app/Queries/Admin/DashboardQuery.php` is BeGate's? — NO: you own `DashboardQuery.php` and its tests too, since the cards are a UI concern; coordinate nothing else).
- Delete `resources/js/Pages/Portal/Invoices/**`, `Pages/Portal/Password/**`, `Layouts/PortalLayout.tsx` if unused afterwards, and their components; keep `Pages/Portal/Payments/{Return,Finished}.tsx`.
- E2E: new `tests/e2e/app-only.spec.ts`; update `support/demo.ts` (`loginAs` technician/customer expect `/app-only`), `sidebar-ia`, `dashboard-persona`, `auth-roles`, `private-media` (customer preview step → use the API or drop), `midtrans-payment` (portal pay steps → API or drop; keep the finish-page checks), `ticket-lifecycle`, `radius-provisioning` customer steps, `notification-center` if it logs in as technician. Delete specs that only exercised the removed pages.

## Acceptance criteria

- [x] `tsc`, ESLint, selfchecks, `AdminMenuAuthorizationTest` green; E2E specs type-check (orchestrator runs them).

## Comments

- `npx tsc --noEmit` clean; `eslint` + `prettier --check` clean on `Pages/AppOnly.tsx`, `Portal/Payments/Return.tsx`, `Admin/Dashboard/**`, `types/{dashboard.ts,portal.d.ts}`, `lib/navigation.ts`, `navigation.selfcheck.mjs`; `node --test resources/js/lib/navigation.selfcheck.mjs` 23/23.
- PHPUnit (sail, after BeGate middleware landed): `AdminMenuAuthorizationTest`, `DashboardPersonaTest`, `DashboardExceptionCountsTest`, `DashboardTest` → 98 passed (2560 assertions). Pint clean. Tests keep technician/customer permission matrices by giving the user a permission-less extra role `web-access` (non app-only per `RedirectAppOnlyRoles::isAppOnly`).
- E2E: eslint does not cover `tests/e2e` (not in the tsconfig project); type-checked with a throwaway tsconfig (`noUnusedLocals`): only pre-existing noise (missing `@types/node`). Not run (orchestrator). Prettier clean except `midtrans-payment.spec.ts` (dirty at HEAD too).
- Dashboard: removed the customer `tickets` link card and the `personal` group (`my_requests`, `my_returns`; technician-persona cards, also shown to admin before). `my_work`, `sla_breached`, `low_stock` stay.
- Deleted: `Pages/Portal/{Invoices/*,Password.tsx}`, `Layouts/PortalLayout.tsx`, `Components/composite/billing/PortalInvoiceStatus.tsx`, portal invoice types. `Payments/Return.tsx` now uses `GuestLayout` and no portal links.
- E2E: new `app-only.spec.ts`, `support/api.ts` (`apiLogin` mobile API client); `loginAs(technician|customer)` expects `/app-only`; technician/customer web denial branches removed; technician/customer work steps re-driven via API (`ticket-lifecycle`, `spk-lifecycle`, `kanban-t_49387795`, `dispatch-my-work`, `midtrans-payment`, `private-media`); `notification-center` recipient is now the admin (manager assigns); `technician-performance` drops the technician self case.
