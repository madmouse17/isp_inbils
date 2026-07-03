# Batch 0 — Critical Foundation Fixes

> Date: 2026-07-01
> Status: COMPLETE
> Tests: 17 passed (69 assertions)
> Build: tsc + vite clean (0 errors)

## Files Changed

### Already fixed (prior session)
| File | Change |
|------|--------|
| `app/Http/Controllers/Auth/AuthenticatedSessionController.php` | Login redirect → `route('admin.dashboard')` instead of `route('dashboard')` |
| `routes/web.php` | Removed `/admin` catch-all group that intercepted all admin routes; `/dashboard` → redirect to `admin.dashboard` |
| `app/Http/Middleware/RedirectIfNoCompany.php` | Added skip for `login`, `password*` routes |
| `app/Http/Middleware/RequireHasCompany.php` | Removed debug logging |
| `app/Models/Core/Location.php` | Added `company_id` to `$fillable` (was missing, caused MySQL strict mode error in seeder/CLI context) |
| `database/seeders/CompanySeeder.php` | Fixed `contract_min_months` null → 0 for PKG-TRIAL; added `company_id` to location seed data |

### Fixed in Batch 0
| File | Change |
|------|--------|
| `resources/js/Layouts/AdminLayout.tsx` | Sidebar Billing link `/admin/billing` → `/admin/invoices` (was 404) |
| `app/Models/Core/Company.php` | Added `HasFactory` trait + `$factory` property for test support |
| `database/factories/CompanyFactory.php` | NEW — factory for Company model (was missing) |
| `database/factories/UserFactory.php` | Added `is_active` to definition (was missing) |
| `database/seeders/RolePermissionSeeder.php` | Added missing permissions: `users.manage`, `roles.manage`, `company.manage` (sidebar + policies depended on these but they were never seeded) |

### New test files
| File | Tests |
|------|-------|
| `tests/Feature/Auth/LoginRedirectTest.php` | 3 tests: login→admin.dashboard (company exists), login→setup (no company), /dashboard→admin.dashboard redirect |
| `tests/Feature/AdminRouteTest.php` | 11 tests: dashboard, customers, products, network-assets, spk, invoices, tickets, reports, evaluations, users (all 200), unauthenticated→login redirect |
| `tests/Feature/DashboardTest.php` | 3 tests: real counts (userCount=1, roleCount=5, modules=7), zero customer count on fresh DB, company info displayed |

## Bugs Fixed

1. **Login redirect** — was going to `/dashboard` (Breeze default), now goes to `/admin/dashboard`
2. **Route conflict** — `/admin` catch-all in `web.php` was intercepting all `/admin/*` routes before `admin.php` loaded
3. **Dashboard static data** — `Dashboard.tsx` had hardcoded fake numbers (1,284 users, Rp 48.2M). Login now redirects to `admin.dashboard` which uses `DashboardController` returning real DB counts
4. **Sidebar Billing link** — `/admin/billing` was 404, fixed to `/admin/invoices`
5. **Missing permissions** — `users.manage`, `roles.manage`, `company.manage` were referenced by sidebar + policies but never seeded in `RolePermissionSeeder`. Admin got 403 on Users page
6. **Location `$fillable`** — missing `company_id` caused `CompanySeeder::seedLocations()` to fail (MySQL strict mode)
7. **CompanySeeder `contract_min_months`** — PKG-TRIAL passed null for NOT NULL column
8. **Missing CompanyFactory** — tests couldn't create Company model
9. **UserFactory missing `is_active`** — tests needed to pass this explicitly

## Acceptance Criteria

| # | Criteria | Status |
|---|----------|--------|
| 1 | Login redirects correctly based on company setup state | ✅ PASS |
| 2 | /admin does not swallow module routes | ✅ PASS |
| 3 | Admin route ordering is safe | ✅ PASS |
| 4 | Dashboard does not show fake static numbers | ✅ PASS |
| 5 | Dashboard uses real database counts | ✅ PASS (DashboardController returns real counts) |
| 6 | Sidebar links only show valid routes and permission-gated modules | ✅ PASS (Billing link fixed) |
| 7 | `php artisan route:list` shows all module routes | ✅ PASS |
| 8 | `php artisan test` passes | ✅ 17 passed (69 assertions) |
| 9 | `npm run build` passes | ✅ 0 errors |
| 10 | No unrelated feature work included | ✅ |

## What Remains (for Batch 1+)

- Dashboard `Admin/Dashboard/Index.tsx` already uses real props (good), but Breeze `Dashboard.tsx` still exists with static data — should be deleted in Batch 1 cleanup
- `SESSION_DRIVER` changed to `file` for debugging — should revert to `database` for production
- No browser smoke test completed (browser tool loses session cookies between navigate calls)
- CompleteSpkAction, unit conversion, network topology — all deferred to later batches
