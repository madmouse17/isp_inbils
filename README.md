# Inbils

Multi-tenant ISP management SaaS. One installation serves many ISP companies (tenants): customers and subscriptions,
network assets, RADIUS and IPAM, SPK work orders for field technicians, inventory, billing with Midtrans, ticketing with
SLA, reports, and an operator panel at `/platform`. Two Flutter apps (customer and technician, repo `inbils-mobile`)
talk to `api/v1`.

| Layer | Technology |
| --- | --- |
| Backend | Laravel 13, PHP 8.5 in Sail and in the production image (`composer.json` requires `^8.4`), nwidart modules, Sanctum, Spatie permission, medialibrary, activitylog |
| Frontend | Inertia v2, React 18, TypeScript, Vite 7, Tailwind CSS v4 (CSS-first, `resources/css/app.css`) |
| Data | MySQL 8.4, Redis 7.4 (cache), Mailpit (dev mail) |
| Tests | PHPUnit 11 (with parallel runner), Playwright (chromium) |

How the code is organised and the rules to follow: **[PROJECT_GUIDE.md](PROJECT_GUIDE.md)** (read it before changing
code). History of changes: [CHANGELOG.md](CHANGELOG.md).

## 1. Prerequisites

| Need | Notes |
| --- | --- |
| Docker Engine with Compose v2 | Everything runs in containers; the host needs no PHP or Composer |
| Git | Access to the repository (`https://github.com/madmouse17/isp_inbils.git`) |
| About 6 GB free disk, ports free | 80, 3306, 6379, 1025, 8025, 5173 (all bound to `127.0.0.1`). Change them per shell, see Troubleshooting |
| Node.js and PHP on the host | **Optional.** Only the Playwright E2E suite and `npm run docs:screenshots` run on the host (Node 22+, PHP 8.4+ CLI, patched: 8.5.12 or the distribution's fix for CVE-2026-109618). `package.json` has no `engines` field; the Docker build uses Node 22, Sail ships Node 24 |

## 2. First-time setup (Laravel Sail)

Sail is the local development stack (`compose.sail.yaml`, Compose project `inbils-sail`). It is independent from the
production-style stack in `compose.yaml` (section 6).

```bash
git clone https://github.com/madmouse17/isp_inbils.git inbils
cd inbils
cp .env.example .env
```

Edit `.env` (names only; values are yours):

| Key | Do |
| --- | --- |
| `APP_KEY` | Leave empty; step below generates it |
| `WWWUSER`, `WWWGROUP` | Set to `id -u` and `id -g` of your host user if they are not 1000 |
| `DB_PASSWORD` | The committed value is a local placeholder that also initialises the Sail MySQL volume. Change it **before the first `sail up`** if you want another one; later changes do not reach an existing MySQL volume |
| `SAIL_FILES` | Keep `compose.sail.yaml` (already set) |
| `APP_PORT` | Leave unset (shared with `compose.yaml`) |
| `MAIL_*` | Defaults point to Mailpit; nothing to do |
| `FIREBASE_CREDENTIALS`, `MIDTRANS_*`, `WHATSAPP_*`, `RADIUS_*` | Optional. Leave empty (section 8) |

Install PHP dependencies. `sail build` needs `vendor/laravel/sail`, which a fresh clone does not have, so run Composer
once in a throwaway container:

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html \
    composer:2 composer install --ignore-platform-reqs
```

Start the stack and finish the install:

```bash
./vendor/bin/sail build
./vendor/bin/sail up -d
./vendor/bin/sail ps                          # laravel.test, mysql, redis, mailpit must be healthy
./vendor/bin/sail composer install            # again, inside the container, with the real PHP extensions
./vendor/bin/sail artisan key:generate        # only while APP_KEY is empty
./vendor/bin/sail artisan migrate --force
./vendor/bin/sail artisan storage:link
./vendor/bin/sail npm ci --legacy-peer-deps
./vendor/bin/sail npm run build
```

Seed the data (choose one):

| Choice | Commands | Result |
| --- | --- | --- |
| **Demo data** (quickest way to see the app) | `./vendor/bin/sail artisan db:seed` | `DatabaseSeeder` seeds the permission catalogue, role templates, system settings and Indonesian region reference data, and, because `APP_ENV=local`, a demo company `DEMO` ("Inbils Demo Company") with demo users and sample data. Slow: the region seeder loads about 80k village rows |
| **Clean install** (production-like) | `./vendor/bin/sail artisan db:seed --class=RolePermissionSeeder`, then `--class=SystemSettingSeeder`, then `--class=IndonesiaRegionSeeder` | Catalogue, templates, settings and regions only: no company and no user |

Then:

- **After demo data**: open <http://localhost/login>. Logins are `admin@demo.inbils.test`, `manager@`, `staff@`,
  `technician@`, `customer@demo.inbils.test`; the shared demo password is the constant `PASSWORD` in
  `database/seeders/DemoUserSeeder.php`. It is public by design: never seed demo data on a real server.
- **After a clean install**: create the first user, log in at <http://localhost/login>, and the Setup Wizard
  (`/setup`, one-shot) creates the first company and copies the role templates:

  ```bash
  ./vendor/bin/sail artisan inbils:setup      # asks name, email, password; says "Already bootstrapped." if any user exists
  ```

Check it works:

```bash
curl --fail http://localhost/up
curl http://localhost/ready      # JSON; "unknown" scheduler/backup is normal until heartbeats exist
```

Service addresses (loopback only):

| Service | Address |
| --- | --- |
| App | <http://localhost> |
| Mailpit (all outgoing dev mail) | <http://localhost:8025> |
| MySQL | `127.0.0.1:3306`, CLI with `./vendor/bin/sail mysql` |
| Redis | `127.0.0.1:6379` |
| Vite HMR | <http://localhost:5173> while `./vendor/bin/sail npm run dev` runs |

### Queue and scheduler

Mail, notifications, WhatsApp, Midtrans follow-ups and reports run on queues (`QUEUE_CONNECTION=database`), and
billing and SLA checks run on the scheduler. Start them when you work on those features:

```bash
COMPOSE_PROFILES=workers ./vendor/bin/sail up -d       # queue + scheduler containers next to the app
# or in two terminals:
./vendor/bin/sail artisan queue:work --queue=default,whatsapp --tries=3 --timeout=90
./vendor/bin/sail artisan schedule:work
# the platform panel's long jobs (sample data, tenant exports) need their own worker:
./vendor/bin/sail artisan queue:work platform --queue=platform --tries=1 --timeout=3600
```

A worker keeps the code it started with: run `./vendor/bin/sail artisan queue:restart` after changing a job, listener
or notification.

### Platform (operator) admin

The `/platform` panel uses a separate login that is not a tenant user. Create the first operator, then enrol two-factor
authentication at the first login:

```bash
./vendor/bin/sail artisan inbils:platform:admin-create     # asks name, email, password (12+ characters, letters and numbers)
```

Open <http://localhost/platform/login>. Tenants (companies) and subsidiaries are created there.

## 3. The test database `inbils_test`

Tests and E2E use the MySQL schema `inbils_test` and nothing else (never `inbils`). The Sail MySQL init script
`docker/mysql/sail/10-create-test-database.sh` creates the schema, the `inbils` test user and its grants (including
the `inbils_test_test_%` worker schemas used by parallel runs) **once, when the MySQL volume is first created**, so a
normal fresh setup needs nothing. If your volume already existed before that script, apply it once:

```bash
./vendor/bin/sail exec -T mysql bash -s < docker/mysql/sail/10-create-test-database.sh
```

## 4. Run the tests

`phpunit.xml` pins host `mysql`, schema `inbils_test`, user `inbils`. `Tests\TestCase` refuses to boot on any other target.

```bash
# one file or filter (serial)
./vendor/bin/sail artisan test --compact tests/Feature/Admin/DashboardTest.php
./vendor/bin/sail artisan test --compact --filter=SomeTest

# full suite in parallel (about 5400 tests, ~33 min with 3 workers; check `uptime` load first, keep it under 6)
flock /tmp/inbils-e2e.lock env DOCKER_CONTEXT=default ./vendor/bin/sail exec -T -u sail laravel.test \
  flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --parallel --processes=3 --compact
```

Max 4 processes. Never pass `--drop-databases` or `--recreate-databases` (workers reuse the schemas
`inbils_test_test_<N>`). Other gates:

```bash
./vendor/bin/sail bin pint --dirty              # PHP format
./vendor/bin/sail composer analyse              # PHPStan (larastan) level 5
./vendor/bin/sail npm run typecheck
./vendor/bin/sail npm run lint
./vendor/bin/sail npm run format:check
./vendor/bin/sail npm run build                 # tsc + vite build
```

### Playwright E2E (first run)

E2E runs on the host against a canonical server on port 8010 and the schema `inbils_test`. It needs Node, a PHP CLI
(8.4+, with `vendor/` installed; set `PHP_BINARY` to use a specific binary) and a reachable MySQL that has `inbils_test`
and the `inbils` user. One-time:

```bash
npm ci --legacy-peer-deps
npx playwright install chromium
cp .env.e2e.example .env.e2e        # fill DB_PASSWORD; `.env.e2e` is git-ignored
php artisan key:generate --env=e2e  # or ./vendor/bin/sail artisan key:generate --env=e2e
```

`.env.e2e.example` points at `127.0.0.1:3307`, the MySQL of the deploy-like stack (section 6); its `DB_PASSWORD` is
`DOCKER_DB_PASSWORD` from `.env.docker`. The Sail MySQL (`127.0.0.1:3306`) has the same schema and user too (the local test credentials are in
`docker/mysql/sail/10-create-test-database.sh` and `phpunit.xml`), so `DB_PORT=3306` is the alternative when you do not run the
8080 stack; the repo docs only describe the 3307 route.

Run (never together with PHPUnit or `docs:screenshots`; they share `inbils_test` and a mutex):

```bash
flock /tmp/inbils-e2e.lock sh -c 'npm run build && node scripts/e2e-setup.mjs && npm run test:e2e -- --project=chromium --workers=1'
```

Do not add a positional spec path: the runner forwards arguments to Playwright, which reads them as a file filter and
reports "No tests found". Use `--grep "<text>"`, or `npm run test:e2e:smoke` for the `@smoke` tests.
`node scripts/e2e-setup.mjs` resets the data; never run `tests/e2e/setup.php` directly.

## 5. Daily commands

| Task | Command |
| --- | --- |
| Start / stop (keeps data) | `./vendor/bin/sail up -d` / `./vendor/bin/sail stop` |
| Artisan, tinker | `./vendor/bin/sail artisan <cmd>` , `./vendor/bin/sail artisan tinker` |
| New migration | `./vendor/bin/sail artisan make:migration <name>` (date must sort after the newest shipped migration) |
| Migrate | `./vendor/bin/sail artisan migrate --force` |
| Frontend HMR | `./vendor/bin/sail npm run dev` (then <http://localhost>) |
| Frontend build | `./vendor/bin/sail npm run build` |
| Logs | `./vendor/bin/sail artisan pail` or `./vendor/bin/sail logs -f laravel.test` |
| Shell in the app container | `./vendor/bin/sail shell` |
| Export the mobile API contract | `./vendor/bin/sail artisan scramble:export` (commit `docs/api/mobile-v1.openapi.json`) |
| Render user-guide PDFs | `./vendor/bin/sail exec -T -u sail laravel.test php -d memory_limit=1G artisan inbils:docs:pdf` |
| Web guide screenshots | `npm run build && npm run docs:screenshots` (host, E2E mutex, resets `inbils_test`) |
| Take a PHP security release (Sail) | `./vendor/bin/sail build --no-cache laravel.test && ./vendor/bin/sail up -d`, then `./vendor/bin/sail php -v` (Ubuntu package from the Ondrej PPA; 8.5.12 fixes CVE-2026-109618 and CVE-2026-109619) |

Never run `migrate:fresh`, `db:wipe`, `sail down -v`, `docker volume rm` or `docker system prune --volumes` on data you
want to keep. `sail stop` and `sail down` keep the MySQL and Redis volumes. Do not run `sail:install` or `sail:add`.

## 6. Optional: deploy-like stack on port 8080

`compose.yaml` + `Dockerfile` build the production-style image (FrankenPHP, source baked in, separate queue, platform
queue, scheduler, MySQL on `127.0.0.1:3307`, Redis). It shares nothing with Sail. Full reference:
[docs/operations/DOCKER.md](docs/operations/DOCKER.md); release procedure: [docs/operations/DEPLOYMENT.md](docs/operations/DEPLOYMENT.md).

```bash
cp .env.docker.example .env.docker      # git-ignored; set APP_KEY and the DOCKER_DB_* passwords (the example ships APP_ENV=production)
docker run --rm dunglas/frankenphp:php8.5-bookworm php -r 'echo "base64:", base64_encode(random_bytes(32)), PHP_EOL;'   # a fresh APP_KEY value
export DOCKER_CONTEXT=default           # the root daemon; see Troubleshooting
docker compose --env-file .env.docker config > /dev/null
docker compose --env-file .env.docker build --pull
docker compose --env-file .env.docker up -d
docker compose --env-file .env.docker exec app php artisan migrate --force
docker compose --env-file .env.docker exec app php artisan db:seed --force     # production env: roles, settings, regions only
docker compose --env-file .env.docker exec app php artisan inbils:setup        # first user, then the wizard
docker compose --env-file .env.docker ps
```

Check <http://localhost:8080/up>, <http://localhost:8080/ready> and <http://localhost:8080/platform/login>. Before
updating a stack that holds data, back it up first (`scripts/operations/backup.sh`, see `docs/operations/BACKUP_RESTORE.md`).

### Optional: `*.test` names with HTTPS

`docker/devproxy/compose.yaml` gives `https://inbils.test` (Sail) and `https://deploy.inbils.test` (8080 stack). The
one-time host steps (DNS for `*.test`, trusting the local certificate) are in the "Local `*.test` domains" section of
[docs/operations/DOCKER.md](docs/operations/DOCKER.md).

```bash
DOCKER_CONTEXT=default docker compose -f docker/devproxy/compose.yaml up -d --build
```

## 7. Mobile apps

The customer and technician Flutter apps live in the separate private repo `inbils-mobile` (never inside this one).
They use `api/v1`; the contract is `docs/api/mobile-v1.openapi.json`. Push notifications need the Firebase service
account (section 8). The Android emulator reaches the host as `10.0.2.2`.

## 8. Integrations are configured per company

Midtrans, WhatsApp Cloud API, RADIUS and a company mail account are **not** read from `.env` at runtime. Each
company enters its own in the app: **Pengaturan > Integrasi** (`/admin/company/integrations`, permission
`company.integrations.manage`). The `.env` keys (`MIDTRANS_*`, `WHATSAPP_*`, `RADIUS_*`) are one-time import sources:

```bash
./vendor/bin/sail artisan inbils:integrations:import-env --company=<company-code> [--provider=midtrans|whatsapp|radius] [--dry-run] [--force]
```

- Midtrans callback URL: `<APP_URL>/webhooks/midtrans/{company}`; WhatsApp webhook (Meta app): `<APP_URL>/webhooks/whatsapp`. Both need a public address; `scripts/dev-payments.sh` starts the workers and a cloudflared tunnel (`INBILS_TUNNEL=<name> ./scripts/dev-payments.sh`).
- Firebase push: set `FIREBASE_CREDENTIALS` to the Admin SDK service-account JSON kept outside git (in Sail copy it to `storage/app/private/`, which git ignores). Empty means push is off. See [docs/operations/push-notifications.md](docs/operations/push-notifications.md).
- Platform mail sender (reset and invitation mails) is `MAIL_*` in `.env`.

## 9. Troubleshooting

| Symptom | Fix |
| --- | --- |
| `docker compose` against the deploy stack shows containers as only "Created", or an empty list | Use the root daemon: prefix with `DOCKER_CONTEXT=default` (or `export` it) |
| `sail build` says `vendor/laravel/sail` is missing | Run the throwaway-container `composer install` from section 2 first |
| Port already in use (80, 3306, 6379, 8025, 5173) | Set it for one shell: `APP_PORT=8081 ./vendor/bin/sail up -d` (keep `APP_URL` in sync), `FORWARD_DB_PORT`, `FORWARD_REDIS_PORT`, `FORWARD_MAILPIT_DASHBOARD_PORT`, `VITE_PORT`. Never put `APP_PORT` in `.env` |
| `/ready` shows `storage_link: fail` and status `degraded`, or uploaded logos are broken images | `./vendor/bin/sail artisan storage:link`; if `public/storage` is a real directory, `mv public/storage public/storage.old` first |
| `/ready` `scheduler` or `backup` is `unknown` | Normal in dev until `schedule:work` (or the `workers` profile) has run; backups only exist where you run `scripts/operations/backup.sh` |
| "Unable to locate file in Vite manifest" | `./vendor/bin/sail npm run build` (or start `npm run dev`) |
| Permission denied or EACCES on `storage/app/...` from the host or from E2E, after a run as root | Files made by `sail exec` without `-u sail` are root-owned. Fix: `./vendor/bin/sail exec -T laravel.test sh -c 'find /var/www/html/storage -user root -exec chown 1000:1000 {} +'` (use your `id -u`:`id -g` if not 1000). Run PHP tasks as `sail artisan ...` or `sail exec -u sail` |
| Test refuses to boot: "PHPUnit bootstrap safety guard refused to bootstrap Laravel." | The environment points at another database, or `bootstrap/cache/config.php` exists. Run `./vendor/bin/sail artisan config:clear`, and do not export `DB_*` or `APP_ENV` in your shell |
| Tests cannot connect to MySQL as `inbils` | The MySQL volume predates the init script: apply it once (section 3) |
| E2E login fails right after a PHPUnit run | PHPUnit wiped the demo users in the shared `inbils_test`: `node scripts/e2e-setup.mjs` resets them |
| Queue jobs, mails or bells do not appear | Start the workers (section 2) and look at Mailpit; run `queue:restart` after code changes |
| Page loads without styles behind `https://inbils.test` | `TRUSTED_PROXIES` must cover the Docker networks (see DOCKER.md); Vite HMR does not work behind the HTTPS proxy, use <http://localhost> for `npm run dev` |
| Schedule or a worker runs old code | `./vendor/bin/sail artisan queue:restart`; for the 8080 stack rebuild and recreate `app queue platform-queue scheduler` |

## 10. Where to read more

| Topic | Doc |
| --- | --- |
| Project rules, structure, multi-tenancy, permissions, API, UI, testing, mistakes to avoid | [PROJECT_GUIDE.md](PROJECT_GUIDE.md) |
| Architecture, modules, database | `docs/architecture/*.md`, ADRs in `docs/architecture/decisions/` |
| Product behaviour per domain | `docs/business/*.md` |
| Security, RBAC, platform panel, mobile API | [docs/SECURITY.md](docs/SECURITY.md) |
| Docker stacks, ports, parallel tests, `*.test` proxy | [docs/operations/DOCKER.md](docs/operations/DOCKER.md) |
| Deploy, backup, restore, incidents, observability | `docs/operations/*.md` |
| Mobile API contract | `docs/api/mobile-v1.openapi.json` |
| User guides (Indonesian) and how they are built | `docs/user-guide/README.md` |

## License

Proprietary. All rights reserved.
