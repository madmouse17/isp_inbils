# Ticket 03 research notes (read-only audits, 2026-10-16, agent PlatformBackend)

Source: three read-only scout audits. Nothing here was executed against a database; re-check a fact before relying on it.
Consumers: the next owner of the open items in `03-backend-tenants-provisioning-lifecycle.md`.

## A. Trial sample data (`DemoDataSeeder::seedFor`, design §4.11)

Unsafe for a non-DEMO tenant today (file:line as of 2026-10-16):

| Where | Problem | Planned fix |
|---|---|---|
| `DemoUserSeeder` (USERS const, `PASSWORD`) | 5 fixed `@demo.inbils.test` logins with one known password; `users.email` is globally unique | `seedFor` does not call it; it creates its own users (random 32-char password, company-qualified reserved `.test` emails) |
| `FoundationDemo.php:151,163-164` | looks users up by `{key}@demo.inbils.test` | read them from `DemoContext` (`addUser` pre-registered), keep the keys `admin, manager, staff, technician, customer` |
| `FoundationDemo.php:166-170` (`PEOPLE`) | fixed emails and literal known password | qualify the email with company identity; `Str::random(32)` password |
| `FoundationDemo.php:88-104` (`companyProfile`) | writes fake Bandung address, phone, bank info into blank tenant profile fields (they print on invoices) | skip when the context says non-DEMO |
| `CustomerDemo.php:312,321` | customer email `{slug}@pelanggan.demo.inbils.test` becomes a global-unique `users.email` through `CustomerService::createWithUser` | add company identity (`{slug}.c{companyId}@...`) |
| `WhatsAppDemo.php` | real-looking phones + `SetCustomerWhatsAppConsentAction` opt-in; `provider_message_id` is globally unique | skip the lane for non-DEMO |
| `NetworkAccessDemo.php` | `nas_devices.nasname` and `network_accounts.username` are globally unique; RADIUS provisioning risk | skip the lane for non-DEMO (no NasDevice / NetworkAccount / RADIUS secret) |
| `DemoDataSeeder.php:81` | sets `allow_manual=true` on the customer number sequence and never restores it | restore in `finally` |
| `DemoDataSeeder.php:96-100` (`seedNetworkLanes`) | looks admin/manager up by demo email | skip or register from context |

Safe to keep: `IpamOdnDemo` (company-scoped CIDRs/VLANs), `NetworkDemo` core assets, inventory, tickets, billing, evaluations, notifications (written directly, nothing sent), documents.
Needs `CompanySeeder::runFor` rows first (packages `PKG-*`, location `POP-01`, units, SLA tiers, ticket categories): provisioning already runs it through `SetupWizardService::createFor`.
Minimal plan: `DemoContext` gets an `isDemoCompany` flag (`$company->code === 'DEMO'`) plus a company slug helper; `DemoDataSeeder` gets `seedFor(Company, User $admin)` that (a) asserts the admin is homed in the company and has `admin`, (b) refuses a non-empty real tenant unless the marker customer `DEMO-C001` exists (then only idempotent lanes), (c) shares ONE override block with `run()` (queue.default=sync, mail.default=array, Carbon/CarbonImmutable test now, Auth user, in try/finally), (d) creates manager/staff/technician/customer users, (e) runs `seed()` with the flag off. `run()` for code `DEMO` stays unchanged. Existing coverage: `tests/Feature/DemoDataSeederTest.php`, `DemoUserSeederTest.php`, `DemoTeamSeederTest.php`; E2E parses `DemoUserSeeder.php` USERS source with regexes: do not change that format.
`SeedTenantDemoDataJob` (design): unique per company, `platform` queue, 1 try, 3600 s timeout, `failed()` audit; never in the HTTP request.

## B. Tenant tables, purge order, files (design §4.13 / §4.14)

- `company_id` NOT NULL on every tenant table of core and modules (FK `companies.id`, RESTRICT unless noted). Exceptions: `users.company_id` nullable (home company), `activity_log.company_id` nullable with SET NULL (so snapshot code/name into the platform row BEFORE deleting the company), `company_user` (CASCADE both ways).
- Tables reached without `company_id`: pivots `announcement_location`, `announcement_service_package_category`, `location_promo_banner`, `promo_banner_service_package_category` (via announcement / banner ids); `personal_access_tokens` (tokenable User), `notifications` (User or Customer), `model_has_roles` / `model_has_permissions` (User), `media` (morph + `custom_properties.company_id`), `sessions` (user_id), `password_reset_tokens` (email).
- Global, never purge: `roles`, `permissions`, `role_has_permissions`, `settings`, `indonesia_*`, `cache*`, `jobs`, `job_batches`, `failed_jobs`, `migrations`.
- Cycles / self references (all RESTRICT): `odn_splitters.input_port_id` <-> `odn_ports.odn_splitter_id` (also CHECK `odn_ports_kind_consistent`); `locations.parent_id`, `organization_units.parent_id`, `categories.parent_id`, `ip_prefixes.parent_id`, `companies.parent_company_id`. `tickets.spawned_spk_id` -> `work_orders` (tickets before work orders). Almost every tenant FK to `users` is RESTRICT: delete tenant rows first, tenant-homed users after, children before root.
- Suggested algorithm: take tables from `Schema::getTableListing(schemaQualified: false)` minus the global list, keep those with a `company_id` column (`Schema::getColumns`), validate every identifier with `/^[a-z0-9_]+$/`, build edges from `Schema::getForeignKeys` (MySQL rows: `columns` and `foreign_columns` are comma-joined STRINGS, `on_delete` upper-case), topologically sort child-first, null out `parent_id` per self-referencing table before the delete, for the ODN cycle `UPDATE odn_splitters SET input_port_id = NULL` then delete ports then splitters. Do NOT use `disableForeignKeyConstraints`.
- Secret-bearing columns the export must omit (design §4.13 rule: any column named/ending password, secret, token, remember_token, `two_factor_*`): `users.password`, `users.remember_token`, `users.two_factor_*`, `nas_devices.radius_secret`, `network_accounts.password` and `provisioned_password_hash`, `payment_attempts.snap_token` and `redirect_url`.
- Files: media root disk `MEDIA_DISK` (default `local` = `storage/app/private`), path generator `App\Support\Media\CompanyMediaPathGenerator`: `companies/{companyId|system}/{snake_plural(model)}/{model_id}/{collection}/{mediaId}/` (company logo: `companies/{id}/company-logo/{mediaId}/` on the PUBLIC disk, `CompanyLogoService`). Purge: delete `companies/{id}` on `local` and `public` (and any other disk named by a media row) after commit; never `companies/system`.

## C. `platform` queue, worker, docs (design §4.13, slice S03B)

- Existing worker: `compose.yaml` service `queue` (`php artisan queue:work --queue=default,whatsapp --sleep=3 --tries=3 --timeout=90 --no-interaction`, healthcheck `grep -aq 'queue:work' /proc/1/cmdline`, extends anchor `x-app`); `deploy/systemd/queue-worker.service.example`; `docs/operations/examples/supervisor-laravel.conf.example` (`stopwaitsecs=3600`); NSSM example. No env var drives timeout; `retry_after` comes from `DB_QUEUE_RETRY_AFTER` (default 90). `failed_jobs` is shared by every connection.
- Add to `config/queue.php`: connection `platform` = database driver, same `jobs` table, `queue` `platform`, `retry_after` 3660 (fixed, no env), `after_commit` false. Worker: `queue:work platform --queue=platform --tries=1 --timeout=3600`; the systemd unit needs `TimeoutStopSec=3660` (default 90 s would SIGKILL a long export).
- Docs to extend: `docs/operations/{DOCKER,DEPLOYMENT,SUPERVISION}.md` (+ `RELIABLE_JOBS.md` mentions the worker). Sail has no queue service (manual `sail artisan queue:work`).
- Tests pinning docs / `/ready` queue check exist: grep `tests/` for `docs/operations` before editing.

## D. Facts found while implementing ticket 02 (useful for ticket 03 code)

- With `auth:platform` the DEFAULT guard becomes `platform`: `Auth::user()` / `request()->user()` are the `PlatformAdmin`. `CompanyService::currentId()` ignores non-`User`s (fail-closed). Any global code that type-hints `?User` on `request()->user()` breaks on platform pages (`ReportingServiceProvider` did; fixed).
- `CompanySeeder::runFor` picks the first user homed in the company as its acting user, so provisioning must create and home the admin BEFORE seeding (`SetupWizardService::createFor` does).
- Tests: `Tests\Traits\InteractsWithPlatform` (`platformAdmin`, `enrolledPlatformAdmin`, `signInPlatformAdmin`, `platformPasswordConfirmed`); a test that needs two requests with changed state calls `$this->app['auth']->forgetGuards()` (the helper already does).
