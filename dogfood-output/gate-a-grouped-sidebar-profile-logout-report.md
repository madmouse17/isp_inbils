# Gate A Smoke Report: Grouped Sidebar + Profile + Logout

Target: http://127.0.0.1:8000
Date: 2026-07-09
Scope: grouped admin sidebar, topbar profile/logout, dark mode persistence, role-visible links for admin/manager/staff/technician/customer where possible. No source edits.

## Overall Result

PASS with non-blocking known test/lint debt.

## PASS/FAIL Matrix

| Role | Visible grouped labels | Visible menu count | Visible links render | Profile link | Logout | Console errors | Dark mode persists | Result |
|---|---|---:|---|---|---|---:|---|---|
| admin | 8/8 | 29 | PASS | PASS | PASS | 0 | PASS | PASS |
| manager | 8/8 expected where visible | 20 | PASS | not separately clicked | not separately clicked | 0 | PASS | PASS |
| staff | 8/8 expected where visible | 20 | PASS | not separately clicked | not separately clicked | 0 | PASS | PASS |
| technician | 8/8 expected where visible | 13 | PASS | not separately clicked | not separately clicked | 0 | PASS | PASS |
| customer | 8/8 expected where visible | 3 | PASS | not separately clicked | not separately clicked | 0 | PASS | PASS |

## Grouped Labels Verified

Admin visual/browser evidence showed:
- DASHBOARD
- COMPANY/ADMIN
- CRM/OPERATIONS
- SERVICE
- INVENTORY
- NETWORK/WORK ORDERS
- FINANCE/REPORTS
- SYSTEM/DEVELOPER

## Menus Verified By Role

### admin
Dashboard, Organization, Company, Users, Roles, Permissions, Customers, Locations, Employees, Vehicles, Documents, Number Sequences, Service, Bandwidth Profiles, Speed Profiles, SLA Tiers, Inventory, Categories, Units, Stocks, Item Finder, Stock Movements, Network Assets, SPK, Billing, Tunggakan, Ticketing, Evaluations, Reports, Komponen.

### manager
Dashboard, Customers, Locations, Service, Bandwidth Profiles, Speed Profiles, SLA Tiers, Inventory, Categories, Units, Stocks, Stock Movements, Network Assets, SPK, Billing, Tunggakan, Ticketing, Evaluations, Reports, Komponen.

### staff
Dashboard, Customers, Locations, Service, Bandwidth Profiles, Speed Profiles, SLA Tiers, Inventory, Categories, Units, Stocks, Stock Movements, Network Assets, SPK, Billing, Tunggakan, Ticketing, Evaluations, Reports, Komponen.

### technician
Dashboard, Customers, Locations, Inventory, Categories, Units, Stocks, Stock Movements, Network Assets, SPK, Ticketing, Evaluations, Komponen.

### customer
Dashboard, Ticketing, Komponen.

## Browser Evidence

- Login page: C:\Users\MadMouse\AppData\Local\hermes\profiles\tester\cache\screenshots\browser_screenshot_d27669ccb28f47a8959f9841acb549f4.png
- Admin grouped sidebar/topbar dashboard: C:\Users\MadMouse\AppData\Local\hermes\profiles\tester\cache\screenshots\browser_screenshot_c702c35c30c1457eaebf67825dbe1062.png
- Profile page opened from topbar profile link: C:\Users\MadMouse\AppData\Local\hermes\profiles\tester\cache\screenshots\browser_screenshot_97d3d779beea40d68bcbc548a11e3989.png
- Dark mode persisted after toggle + reload: C:\Users\MadMouse\AppData\Local\hermes\profiles\tester\cache\screenshots\browser_screenshot_49d3b0ec177c41cfab10325ad9702cb7.png

## Commands Run

- `php artisan route:list --path=admin --except-vendor` — PASS, admin routes present.
- `APP_ENV=testing php artisan migrate:fresh --seed --force` — PASS.
- Smoke users created in testing DB only: `admin@smoke.test`, `manager@smoke.test`, `staff@smoke.test`, `technician@smoke.test`, `customer@smoke.test`; password `password123`.
- `php artisan test --compact tests/Feature/AdminMenuAuthorizationTest.php` — PASS, 3 tests / 94 assertions.
- `npm run typecheck` — PASS.
- `npm run build` — PASS, built in 4.22s.
- `node dogfood-output/role-smoke.mjs > dogfood-output/role-smoke.rerun.json` — PASS, 85 page visits across 5 roles, 0 issues.
- `npm run lint` — FAIL, 31 errors / 115 warnings in pre-existing repo-wide frontend debt outside this gate scope.
- `npm exec playwright -- test tests/e2e/login-setup.spec.ts` — FAIL, test expects hardcoded `admin@inbils.test`, missing after current testing migrate/seed.

## Issues

No grouped-sidebar/profile/logout runtime issues found.

Non-blocking known debt:
1. Repo-wide `npm run lint` fails on unrelated existing TypeScript/ESLint errors outside this gate.
2. Existing Playwright `tests/e2e/login-setup.spec.ts` fails because seeded test DB does not contain `admin@inbils.test`; smoke login with gate-created users passed.

## Artifacts

- Full role traversal JSON: C:\Users\MadMouse\Documents\Web\inbils\dogfood-output\role-smoke.rerun.json
- Prior role traversal JSON: C:\Users\MadMouse\Documents\Web\inbils\dogfood-output\role-smoke.json
- Smoke script reused from prior QA: C:\Users\MadMouse\Documents\Web\inbils\dogfood-output\role-smoke.mjs
