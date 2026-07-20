# Role Smoke Test Report

Scope: admin UI sidebar role smoke after menu implementation.
Environment: APP_ENV=testing, PHP built-in server http://127.0.0.1:8000, seeded smoke users in test DB only.
Evidence screenshot: C:\Users\MadMouse\AppData\Local\hermes\profiles\tester\cache\screenshots\browser_screenshot_893fb0a42e004cb78ff644b93250091e.png

## Summary

- Total issues: 0
- Browser role smoke: PASS
- Console errors: PASS, none captured during role/menu traversal
- Dark mode: PASS, class toggles, computed shell background changes, localStorage persists

## PASS/FAIL Matrix

| Role | Visible menu count | Pages visited | Missing allowed menu | Forbidden menu visible | Console errors | Dark mode | Result |
|---|---:|---:|---|---|---:|---|---|
| admin | 29 | 29 | - | - | 0 | PASS | PASS |
| manager | 20 | 20 | - | - | 0 | PASS | PASS |
| staff | 20 | 20 | - | - | 0 | PASS | PASS |
| technician | 13 | 13 | - | - | 0 | PASS | PASS |
| customer | 3 | 3 | - | - | 0 | PASS | PASS |

## Menus Tested

### admin
Dashboard, Organization, Company, Users, Roles, Permissions, Customers, Locations, Employees, Vehicles, Documents, Number Sequences, Service, Bandwidth Profiles, Speed Profiles, SLA Tiers, Inventory, Categories, Units, Stocks, Stock Movements, Network Assets, SPK, Billing, Tunggakan, Ticketing, Evaluations, Reports, Komponen

### manager
Dashboard, Customers, Locations, Service, Bandwidth Profiles, Speed Profiles, SLA Tiers, Inventory, Categories, Units, Stocks, Stock Movements, Network Assets, SPK, Billing, Tunggakan, Ticketing, Evaluations, Reports, Komponen

### staff
Dashboard, Customers, Locations, Service, Bandwidth Profiles, Speed Profiles, SLA Tiers, Inventory, Categories, Units, Stocks, Stock Movements, Network Assets, SPK, Billing, Tunggakan, Ticketing, Evaluations, Reports, Komponen

### technician
Dashboard, Customers, Locations, Inventory, Categories, Units, Stocks, Stock Movements, Network Assets, SPK, Ticketing, Evaluations, Komponen

### customer
Dashboard, Ticketing, Komponen

## Issues

None.

## Commands Run

- `APP_ENV=testing php artisan migrate:fresh --seed --force`
- smoke users created via `php artisan tinker` in testing DB only: admin, manager, staff, technician, customer
- `npm run build` — PASS
- `php artisan test tests/Feature/AdminRouteTest.php tests/Feature/AdminSmokeTest.php` — PASS, 15 tests / 30 assertions
- `php artisan test tests/Feature/AdminRouteTest.php tests/Feature/AdminSmokeTest.php tests/Feature/DashboardTest.php` — PASS, 18 tests / 82 assertions
- custom Playwright traversal `node dogfood-output/role-smoke.mjs` — PASS, 85 page visits across 5 roles, 0 issues
- existing `npm exec playwright -- test tests/e2e/login-setup.spec.ts` — FAIL, uses hardcoded `admin@inbils.test` not present in current testing DB after migrate/seed; manual/browser smoke with seeded `admin@smoke.test` passed

## Notes

- No source edits made. Generated report/script/output under `dogfood-output/` only.
- Browserbase warning about no residential proxy irrelevant for localhost.