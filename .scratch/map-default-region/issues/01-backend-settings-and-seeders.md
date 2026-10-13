# BACKEND: map settings keys, shared prop, region and demo seeders

Status: done
Role: backend
Blocked by: none
Spec: `../spec.md`

## Scope (owned files)

- `app/Http/Requests/Admin/UpdateCompanySettingsRequest.php` (D1 rules; `map_city_code` `exists:indonesia_cities,code`), `app/Services/Core/CompanyService.php` (`mapDefault()` helper, D3), `app/Http/Middleware/HandleInertiaRequests.php` (`company.map`), `app/Http/Controllers/Admin/CompanyController.php` (settings page prop `cities`: `[{code, name, province_name, lat, lng}]` ordered by province then name, only on the settings page; and current values in `settings`).
- `database/seeders/SystemSettingSeeder.php` (three `default_map_*` keys), new `database/seeders/IndonesiaRegionSeeder.php` + call in `DatabaseSeeder` (D5), `database/seeders/DemoUserSeeder.php` (D6), `database/seeders/E2eFixtureSeeder.php` (`seedIndonesiaRegions` adds Kota Surakarta 3372 and Kota Administrasi Jakarta Selatan 3174 with their provinces and `meta` lat/long from the laravolt CSV).
- Tests per the spec's testing decisions; CHANGELOG block `### Added - Default map location per company and Indonesian region data (2026-10-14)` with the deploy lines.

## Acceptance criteria

- [x] Focused PHPUnit green; Pint clean; `php artisan db:seed --class=IndonesiaRegionSeeder` twice on `inbils_test` → second run does nothing (paste counts).

## Comments

- 2026-10-14 MapBe: `tests/Feature/Admin/CompanyMapDefaultTest.php` (20 tests: fallback chain, override + city_name, cities ordering, 11 invalid inputs, inclusive bounds, demo Bandung only when absent, region seeder no-op predicate, system seeder keeps edits) and `tests/Feature/DemoTeamSeederTest.php` (4 tests, D9) green with WhatsAppCompanySettings, DemoUserSeeder, CompanyService, DemoDataSeeder suites. Pint clean.
- `db:seed --class=IndonesiaRegionSeeder` on `inbils_test` (DB_DATABASE override): before 0/0/0/0 (provinces/cities/districts/villages) → run 1 (7.1 s, laravolt seeder ran) 38/514/7285/83762 → run 2 (0.65 s, no laravolt line) 38/514/7285/83762. Region tables truncated afterwards (back to 0).
- Settings page `settings.map_*` are the company's own raw values (null follows the system default); `company.map` is the resolved value. Added Kota Bandung 3273 (prov 32) to the E2E subset as well, because DemoUserSeeder stores 3273 for the demo company.
- D9 (owner request): `DemoTeamSeeder` added, see spec.
