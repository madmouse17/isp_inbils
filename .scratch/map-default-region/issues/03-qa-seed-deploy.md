# QA: gates, seed 8080 with demo and region data, release

Status: done
Role: qa
Blocked by: 01, 02
Spec: `../spec.md`

## Scope

Pint, tsc, ESLint, focused PHPUnit, build + targeted E2E (`map-default`, `customer-*`, `ipam-odn`, `datatable-*` touching maps), review, CHANGELOG, commit + push, rebuild 8080, run D8 seeders, confirm counts and that `/admin/locations` opens on Bandung for the demo company.

## Acceptance criteria

- [x] All gates green; 8080 shows demo data and region dropdowns; pushed.

## Comments

- 2026-10-14 (orchestrator):
  - Static: Pint clean, `tsc --noEmit` exit 0, ESLint 0 errors, selfchecks `mapDefault.helpers`, `latLngMapPicker.helpers` pass.
  - Full PHPUnit: 3070 passed, 2 failed (Approval Inbox query budgets: the shared `company.map` prop). MapBe cut it from 3 queries to 1 (one query for the three system defaults; none when the company has its own map) and raised both budgets by 1 with a comment.
  - Full chromium E2E: first run aborted because two specs regex-parse `DemoUserSeeder::PASSWORD` as `private` (now `public` for `DemoTeamSeeder`); regex accepts both. Rerun: 205 passed, 4 skipped, 1 failed (`map-default` asserted an English message that never matched); request now has Indonesian `messages()`, spec asserts "Lintang harus di antara -11 dan 6." → `map-default` passed.
 image rebuilt, `migrate --force`, `RolePermissionSeeder`, `SystemSettingSeeder`, `laravolt:indonesia:seed` (38/514/7285/83762), `DemoUserSeeder`, `DemoDataSeeder` (40 customers, 48 SPK, 46 tickets, 123 invoices, 116 payments, 116 assets), `CustomerDemoSeeder`, `IndonesiaRegionSeeder` (no-op), `DemoTeamSeeder` → admin/manager/staff/technician 5 active each, 20 active employee profiles, demo map `KOTA BANDUNG` (-6.9192, 107.6366, zoom 12); `/up` `/ready` 200. Browser check on 8080: Company Settings "Peta" shows Kota Bandung and the map; Customers/Create address map `data-center=-6.9192,107.6366`; 38 provinces in the region options. Dev DB (Sail): regions seeded, `DemoTeamSeeder` → 5 per role.
