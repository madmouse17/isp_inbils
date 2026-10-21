# Deployment runbook

Scope: single-company ISP production baseline for inbils. This runbook is intentionally provider-neutral; do not invent cloud, CI, or secret-store details.

## Preconditions

- Release is already reviewed and merged.
- Working tree is clean on the deployment host.
- A current backup exists before any risky database migration.
- `/up` and `/ready` are available in the target release.
- Queue worker and scheduler are managed by the host process manager, not by a shell session.

## Clean-checkout build sequence

These commands are the exact provider-neutral sequence to go from a fresh checkout to a running release. Adapt paths for your host.

```bash
# 1. Install PHP dependencies
composer install --no-dev --optimize-autoloader

# 2. Install and build frontend
npm ci
npm run build

# 3. Cache config/routes/views
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 4. Maintenance mode while migrating
php artisan down --retry=60

# 5. Create backup checkpoint before migration
# (see scripts/operations/backup.sh)
# After the backup completes and its checksum verifies, stamp backup freshness:
# php artisan ops:backup-heartbeat

# 6. Run migrations (PRODUCTION ONLY — do not run in audit)
# php artisan migrate --force

# 7. Release maintenance mode
php artisan up

# 8. Restart queue workers to load new code (PRODUCTION ONLY)
# php artisan queue:restart
```

## Production-only commands

Do not run these in the audit workspace unless the task explicitly asks for a live deployment.

```bash
php artisan migrate --force
php artisan queue:restart
php artisan schedule:run
```

## Private uploads (one-time per environment)

Ticket attachments, SPK evidence and customer documents live on the private disk (`storage/app/private`) and are served only by `GET /admin/media/{media}` after the owner's policy allows it. Raster images show in the browser; every other type, PDF included, downloads. The company logo is the one file that stays under `storage/app/public`. Files uploaded before this release are still on the public disk until they are moved:

```bash
php artisan inbils:media:privatize --dry-run   # lists what would move, writes nothing
php artisan inbils:media:privatize             # copies, verifies, flips the row, removes the public copy
php artisan inbils:media:privatize             # second run must report "Moved 0 media"
```

Only rows on the `public` disk (logo excluded) are touched, and only towards `MEDIA_DISK` (default `local`). The command refuses to start, exit 1 and nothing changed, when that disk is `public`, has public visibility, is not configured, or stores its files where the public disk does (a real run checks this with a small probe file; the dry run does not). Run it as the user that owns `storage/` (inside the container: the same user as the web process). Take the backup checkpoint first and keep `storage/app/private` in it (see `BACKUP_RESTORE.md`).

Exit 1 after the run means at least one media is still on the public disk: a `Failed <id>: ...` line names the cause, a `Missing <id>: public:<path>` line means no file exists at the path the app computes for that row (look for the file on the public disk by hand, or drop the row). Fix it and rerun; finished media are not touched again.

## Prohibited commands

These commands must never be executed in production:

```bash
php artisan migrate:fresh     # DESTRUCTIVE — drops all tables
php artisan migrate:refresh   # DESTRUCTIVE — re-runs all migrations
php artisan migrate:reset     # DESTRUCTIVE — rolls back all migrations
php artisan db:seed           # production seeding is unsupported
```

## Systemd process definitions

The repository ships example units under `deploy/systemd/`. Copy and adapt them for your host.

### Queue worker

```bash
# Copy unit
sudo cp deploy/systemd/queue-worker.service.example /etc/systemd/system/inbils-queue-worker.service

# Edit paths, user, and working directory
sudo systemctl edit inbils-queue-worker --full

# Install and start
sudo systemctl daemon-reload
sudo systemctl enable --now inbils-queue-worker

# Verify
sudo systemctl status inbils-queue-worker
sudo journalctl -u inbils-queue-worker -n 20 --no-pager
```

### Platform queue worker

The platform panel's long jobs (sample data for a new tenant, tenant exports) run on their own `platform` queue with a one-hour timeout. This worker is required once the platform panel is in use; without it those jobs wait in the `jobs` table. Details, Docker and supervisor variants: [platform-queue.md](./platform-queue.md).

```bash
sudo cp deploy/systemd/platform-queue-worker.service.example /etc/systemd/system/inbils-platform-queue-worker.service
sudo systemctl edit inbils-platform-queue-worker --full
sudo systemctl daemon-reload
sudo systemctl enable --now inbils-platform-queue-worker
sudo systemctl status inbils-platform-queue-worker
```

### Scheduler (single-owner, once per minute)

```bash
# Copy units
sudo cp deploy/systemd/scheduler.service.example /etc/systemd/system/inbils-scheduler.service
sudo cp deploy/systemd/scheduler.timer.example /etc/systemd/system/inbils-scheduler.timer

# Edit paths and user
sudo systemctl edit inbils-scheduler.service --full
sudo systemctl edit inbils-scheduler.timer --full

# Install and start
sudo systemctl daemon-reload
sudo systemctl enable --now inbils-scheduler.timer

# Verify
sudo systemctl status inbils-scheduler.timer
sudo systemctl list-timers --all | grep inbils
```

## Deploy restart procedure

After every code deploy:

```bash
# Restart queue worker (PRODUCTION ONLY)
# php artisan queue:restart

# Verify restarted workers
sudo systemctl status inbils-queue-worker
sudo systemctl status inbils-platform-queue-worker

# Verify scheduler timer is still active
sudo systemctl status inbils-scheduler.timer

# Verify readiness
curl -s http://127.0.0.1:8000/up
curl -s http://127.0.0.1:8000/ready
```

## Migration safety checklist

Before any production migration:

1. **Backup checkpoint**: create a fresh backup and verify its checksum.
2. **Preflight**: run migration status against a non-production clone first.
3. **Lock/timeout**: apply `php artisan down --retry=60` to avoid partial writes during migration.
4. **Expand-migrate-contract**: add nullable columns first, backfill, then enforce constraints — never add a constraint that can strand the migration.
5. **Rollback limit**: confirm the migration is reversible. If it is destructive (drops data, rewrites schema), prepare a restore-from-backup plan instead.
6. **Run**: `php artisan migrate --force`
7. **Verify**: run `php artisan migrate:status` and check `/ready`.
8. **Release**: `php artisan up`

## Mobile API (`/api/v1`)

- **Migration**: `php artisan migrate --force` creates `personal_access_tokens`. Rolling it back signs every phone out.
- **Schedule**: `sanctum:prune-expired --hours=24` runs daily from `routes/console.php`; it appears in `php artisan schedule:list` once the scheduler owner is running the new release.
- **Dependencies**: `composer install --no-dev` (step 1) leaves out `dedoc/scramble`, so no `docs/api` or `docs/api.json` route exists in production. Check with `php artisan route:list --path=docs`: it must print no routes.
- **Environment**: no new `.env` value. Tokens expire after 90 days (customer) or 30 days (technician), set in `config/sanctum.php`.
- **Host**: the apps need a public HTTPS host for `/api/v1`; the app never sends a token over plain HTTP.
- **Queue**: the existing workers (`default,whatsapp`) serve it; the API adds no queue.

### Mobile API contract

`docs/api/mobile-v1.openapi.json` is generated from the routes, requests and resources and committed. `tests/Feature/Api/V1/OpenApiContractTest.php` fails when a fresh export differs from it, so regenerate and commit the file in the same change as any `api/v1` route, request, resource or PHPDoc hint change.

The export reads column types from the database, so run it against a migrated one: the Sail database after `./vendor/bin/sail artisan migrate`, or the test schema (RefreshDatabase has migrated `inbils_test` after any test run):

```bash
./vendor/bin/sail artisan scramble:export
# or, against the test schema:
./vendor/bin/sail exec -e DB_DATABASE=inbils_test -e DB_USERNAME=inbils -e DB_PASSWORD=inbils_local_change_me laravel.test php artisan scramble:export

# then confirm it is stable and the contract test is green
./vendor/bin/sail exec laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact tests/Feature/Api/V1/OpenApiContractTest.php
```

The path comes from `config/scramble.php` (`export_path`). The export prints 6 known warnings (two `Rule::in(self::STATUSES)` that cannot be evaluated, the rest resources without a model); they do not mean drift. Without a migrated database it prints one warning per model and the file changes, so check `git diff` before committing. Where inference is wrong the code carries a PHPDoc hint (`@var` on an array key, `@response` on `InvoiceController::show`, `@return` on the ticket comment resources and `MyWorkItemResource`).

## Opaque ids release (lane `opaque-ids`)

- The web, the API (OpenAPI 1.1.0, string ids) and both apps ship together; there is no dual-accept period.
- `php artisan migrate --force` adds `public_id` to 59 tables with a chunked backfill and can be run again after a failure. `activity_log` is updated row by row, so on a large audit log it takes minutes: take the backup first and keep maintenance mode on until it ends.
- `TRUSTED_PROXIES` (`.env` or the container environment; comma-separated proxy addresses, CIDR ranges allowed, `*` only when the proxy is the single way in): set it behind a reverse proxy or load balancer, a TLS terminator in front of port 8080 for one. Empty, the default, trusts nobody, so every visitor is seen at the proxy's address: the per-address throttles (logins, API, WhatsApp webhook) share one budget and the audit entries carry the proxy's address. The enumeration limiter counts signed-in users only, so it does not depend on it. Other keys (defaults in `config/security.php`): `SECURITY_ENUMERATION_ENABLED`, `SECURITY_ENUMERATION_THRESHOLD` (30), `SECURITY_ENUMERATION_WINDOW` (5 minutes), `SECURITY_ENUMERATION_COOLDOWN` (10 minutes), and `SECURITY_DUPLICATE_SUBMIT_ENABLED` (default on, off in `testing`; the duplicate-submit guard, see "Integrity hardening release" below).
- Old integer links answer 404; users reopen records from the lists.

## Integrity hardening release (lane `integrity-hardening`)

- `php artisan migrate --force` runs two additive migrations in `Modules/Billing`: `2026_10_23_120000` adds `cancelled_locally` to the `payment_attempts.status` enum (metadata only on MySQL 8), and `2026_10_23_120100` adds the generated column `invoices.active_recurring_period` with the unique `invoices_active_recurring_period_unique` on `(company_id, subscription_id, active_recurring_period)`. The second migration reads first and stops with "Tagihan berulang aktif ganda per periode harus dibereskan dulu" plus the invoice numbers when two active recurring invoices share a subscription and period. Nothing has changed at that point: cancel or fix the duplicate, then run it again. Each step checks what exists, so a run that stopped halfway can be repeated.
- `SECURITY_DUPLICATE_SUBMIT_ENABLED` (default on, off when `APP_ENV=testing`; `config/security.php`, next to the `SECURITY_ENUMERATION_*` keys above) switches the duplicate-submit guard on admin web writes. Leave it on in production. The guard keeps its key in the cache for 10 seconds, so `CACHE_STORE` must be a store that outlives a request (`redis`, the `.env.example` value, or `database`). The E2E served app forces `CACHE_STORE=database` for this reason (`scripts/e2e-server-env.mjs`).
- The queue connections now use `retry_after` 180 seconds (`DB_QUEUE_RETRY_AFTER`, `REDIS_QUEUE_RETRY_AFTER`), above the longest default-queue job timeout. If the host sets either variable to a smaller value, raise it to at least 180 (see [RELIABLE_JOBS.md](./RELIABLE_JOBS.md)).
- The `scheduler` container runs `php artisan schedule:clear-cache` before `schedule:work` (`docker/app/entrypoint.sh`), so overlap locks left by a crash do not block the first runs after a restart. A host that runs `schedule:run` from cron or systemd does not get this step: the locks expire by themselves after the minutes given in `routes/console.php`.
- Billing commands now report a failing company to that company's admins only and continue with the next company; see [JOBS_NOTIFICATIONS.md](./JOBS_NOTIFICATIONS.md).

## Per-ISP integrations release (lane `tenant-integrations`)

Midtrans, WhatsApp, e-mail and RADIUS accounts move from the install-wide `.env` to one row per company in `company_integrations` (Pengaturan → Integrasi, admin only, permission `company.integrations.manage`). Firebase stays platform-wide (`FIREBASE_CREDENTIALS`).

- **Migrate**: `php artisan migrate --force` runs two additive migrations: `2026_10_26_100000` creates `company_integrations` (unique `(company_id, provider)`, index `(provider, external_id)`) and `2026_10_26_100100` creates the permission `company.integrations.manage` and gives it to the `admin` role. On a fresh database `RolePermissionSeeder` grants it. Rolling back drops the table, and with it every stored secret.
- **Import, right after `migrate`, once per company**: Midtrans and WhatsApp are OFF for a company until an admin fills its card or the import runs, and RADIUS stays off. Until then customers cannot pay online, no WhatsApp message leaves, and no account is written to FreeRADIUS. Run the import before the maintenance window ends:

  ```bash
  php artisan inbils:integrations:import-env --company=<code> --dry-run   # prints masked values, writes nothing
  php artisan inbils:integrations:import-env --company=<code>
  ```

  The command copies `MIDTRANS_SERVER_KEY`, `MIDTRANS_IS_PRODUCTION`, `MIDTRANS_ALLOW_SANDBOX_IN_PRODUCTION` and `MIDTRANS_BASE_URL`; `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_APP_SECRET` and `WHATSAPP_WEBHOOK_VERIFY_TOKEN`; and, when `RADIUS_ENABLED=true`, the `RADIUS_DB_*` connection, into that company's rows with the integration switched on. It prints values masked, never a secret. Options: `--provider=midtrans|whatsapp|radius` (repeatable; default all three), `--force` to overwrite a row that exists (without it the command refuses and exits with failure), `--dry-run`. A provider whose source values are empty is skipped. E-mail is never imported: the `MAIL_*` values stay the platform sender, which a company without its own SMTP row uses. Afterwards open Pengaturan → Integrasi and click Uji koneksi on each card; the page shows Terverifikasi or the reason.
- **Keys after the import**: the app ignores `MIDTRANS_*`, `WHATSAPP_*` and `RADIUS_*`, except `WHATSAPP_GRAPH_VERSION` and `services.radius.coa_timeout`. `MIDTRANS_NOTIFICATION_URL` and `services.midtrans.notification_url` are gone: the Snap order carries the company's own URL. Keep the old values until every company is imported, then remove them from the host `.env`.
- **Webhooks**: Midtrans posts to `/webhooks/midtrans/<company public_id>` (shown read-only on the Integrasi page). The old `/webhooks/midtrans` keeps working for payments already in flight; no removal date is set. WhatsApp keeps the one shared `/webhooks/whatsapp`, routed by `phone_number_id` and signed with each company's own app secret, so no ISP has to change its Meta callback.
- **`APP_KEY` is now data**: every integration secret is encrypted with it. Losing the key, or replacing it without `APP_PREVIOUS_KEYS`, leaves every stored secret unreadable. A card with an unreadable secret shows as Tidak diisi and its secrets must be entered again; the next save replaces the old ciphertext. Back up `APP_KEY` with the database backups, and when rotating it list the old key in `APP_PREVIOUS_KEYS` (comma-separated) until the secrets are saved again under the new one.
- **Queue**: "Uji koneksi" runs `App\Jobs\TestCompanyIntegration` on the default queue (one try, 20-second timeout), so a worker must be running for the check to finish. The test push of both apps (`POST /api/v1/me/push-test`, 3 a minute) needs `FIREBASE_CREDENTIALS` on the server; see [push-notifications.md](./push-notifications.md).
- **E2E**: `.env.e2e` needs `FIREBASE_CREDENTIALS` (the example points it at a file that does not exist) for the push test in `tests/e2e/tenant-integrations.spec.ts`; `tests/e2e/setup.php` writes the demo company's integration rows.
- **Rollback**: switching a card off is the per-company rollback. Restoring the old behaviour for a whole install means filling the cards again from the kept `.env` values (`--force`), because the previous release read `.env` directly.

### Integrations security fixes (same release train)

- A mail that carries a credential link (the password reset, e-mail verification, the invitation of a tenant administrator, the access link of a new customer account) always goes through the platform mailer (`MAIL_*`), never through a company's own SMTP: a user can belong to several companies, and one company's mail operator must not read a link that opens an account of another. The notification classes that mint such a link implement `App\Notifications\Contracts\SentByPlatform`.
- A company's mail or RADIUS host is checked every time the app opens a connection. The host is refused, and the integration behaves as "not configured", when it is a loopback, link-local or metadata address (by name or by what the name resolves to), or, outside `local`, `testing` and `e2e`, when it is the platform's own database or Redis host (`DB_HOST`, `REDIS_HOST`), or the platform's SMTP host (`MAIL_HOST`) while that one resolves to a non-public address. A public provider the platform also uses (Gmail, Mailgun, SES) stays open to every company. The platform's own addresses are cached for one minute, so a recreated container is recognised by its new address within a minute. The card shows the reason and the log gets `integration.host_refused` with the company and provider. An ISP that runs its RADIUS database on the same host name as the platform database must give it another name or address.
- `network:sync` (every 15 minutes) queues one `SyncCompanyNetworkJob` per company that has an enabled RADIUS integration, instead of sweeping every company in one process. The default queue needs a worker, and a RADIUS server that does not answer delays only its own company.

## Logs and audit release (lane `log-audit`)

What changed and what an operator must do. Details: [OBSERVABILITY.md](./OBSERVABILITY.md).

- **Migrate**: `php artisan migrate --force` runs `2026_10_27_100000_add_request_context_to_activity_log_table`. It adds three nullable columns to `activity_log` (`request_id`, `ip_address`, `user_agent`; each an INSTANT add on MySQL 8.4) and the index `(company_id, event, created_at)` (built online, the table stays writable). The index build time grows with the table: expect seconds up to about a million rows. Old rows keep null in the new columns. Rollback drops the index and the columns.
- **Set the log keys in the real `.env.docker`**: the template changed, a running deployment does not. Add or change `LOG_STACK=stderr,daily`, `LOG_DAILY_DAYS=14` and `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter` (`LOG_CHANNEL=stack` and `LOG_LEVEL=info` stay). Until then the host writes one `storage/logs/laravel.log` without rotation. That old file stays after the switch and no job prunes it: archive or delete it by hand once the new files run. A host outside Docker with `LOG_STACK=daily` needs only `LOG_DAILY_DAYS`.
- **Docker log caps**: `compose.yaml` now caps the `json-file` driver at 10 MB × 5 files per service (`app`, `queue`, `platform-queue`, `scheduler`, `mysql`, `redis`). The cap applies to a container when it is created, so run `docker compose --env-file .env.docker up -d` after the pull (it recreates only the changed services); `restart` does not apply it. With the Docker Desktop context use `DOCKER_CONTEXT=default`, as in [DOCKER.md](./DOCKER.md).
- **Client addresses**: the audit rows now keep `ip_address`. Behind a reverse proxy or load balancer set `TRUSTED_PROXIES` (section "Opaque ids release" above), or every row carries the proxy's address.
- **Scheduler**: two new entries in `routes/console.php`, no new service. `inbils:activity-log:prune` runs daily at 19:30 UTC (02:30 Jakarta) and deletes rows past 365 days, or 730 days for the `security`, `platform` and `support` logs (`config/activitylog.php`). `queue:prune-failed --hours=720` runs daily. The first prune on a large table deletes in blocks of 5 000 rows and can take minutes; it holds no long lock. Run it by hand once with `php artisan inbils:activity-log:prune` to see the `activity_log.pruned` lines.
- **Behaviour users notice**: the audit page and its export need a period of at most 92 days (7 days when none is given); a PDF export holds at most `min(5 000, 1 500 ÷ number of columns)` rows and says so under the title; an API error body and the error page show the request id.
- **Nothing to configure** for the request id, the redaction tap, the slow-query line (500 ms) or the exception throttle. Log consumers that read `context.request_id` must read `extra.request_id`.
- **Rollback**: a code rollback leaves the columns and the index in place, which the old code ignores. Do not run the migration's `down()` unless the old release has to write rows without them: the data in the three columns is lost.

## Tenant roles release (lane `tenant-roles`)

Roles move from one global set to one set per company, and the application checks permissions instead of role names. Decision: `docs/architecture/decisions/ADR-004-tenant-scoped-roles.md`. The release rewrites every user's role assignments, so it needs a maintenance window, a backup and a before/after check. Nothing changes in `/api/v1` and no mobile release is needed.

- **Migrations** (six, run in order by `php artisan migrate --force`):
  1. `2026_10_29_100000_create_role_templates_tables` creates `role_templates` and `role_template_has_permissions`;
  2. `2026_10_29_100100_add_company_columns_to_roles_table` adds `roles.company_id` (nullable at first, foreign key to `companies` with cascade delete) and `roles.template_key`, and replaces the unique `(name, guard_name)` with `(company_id, name, guard_name)` and `(company_id, template_key)`;
  3. `2026_10_29_100200_add_access_permissions` creates the permissions `app.web`, `app.technician`, `portal.access`, `evaluation.evaluable` and `report.technician.team` and grants them to the global roles that held the matching duty (by their legacy names). A user who has direct permissions but no role gets `app.web` directly, as the old redirect let him in;
  4. `2026_10_29_100300_create_default_role_templates` creates every permission of the catalogue and the eight default templates (Admin, Manajer, NOC & Layanan Pelanggan, Teknisi, Gudang, Keuangan, Staf, Pelanggan) when no template has their key or name, never overwriting an edited one (`PermissionCatalogue::install()`);
  5. `2026_10_29_100400_copy_global_roles_into_companies` copies every global role into every company, remaps every user's assignments to the copies in his home company and in every company he is a member of, and adds the three missing templates (NOC & Layanan Pelanggan, Gudang, Keuangan) to each company with no users. It runs in one transaction and compares every user's permissions per company before and after; any difference rolls the whole step back with "Role cutover would change the permissions of {n} user-company pairs; nothing was changed.";
  6. `2026_10_29_100500_require_company_on_roles` makes `roles.company_id` NOT NULL, and stops with an error if a role without a company is left.
- **Names change**: the five old roles are renamed Admin, Manajer, Staf, Teknisi and Pelanggan (a role keeps its old name only when another global role already uses the new one). Tell company administrators before the window.
- **Fresh installs** run `php artisan migrate:fresh --seed`. Migration 4 already creates the catalogue and the templates; `RolePermissionSeeder` calls the same `PermissionCatalogue::install()` and finds nothing to add. A company gets its roles when it is created.
- **No `db:seed` step, now or in later releases.** Running `php artisan db:seed --class=RolePermissionSeeder --force` on a deployed instance after the migrations is harmless: it only inserts missing permissions and missing default templates, and never creates, changes or removes a company role, a role's permissions or a user's assignment. It is also useless for a release: a permission it adds is held by nobody, and it does not grant it to the company roles. It would recreate a default template the platform operator deleted (Admin and Pelanggan cannot be deleted), with the factory permissions. Never run it before `migrate`: the template tables do not exist yet. A release that adds a permission uses `PermissionCatalogue::release()` in a migration (`docs/SECURITY.md` 2.5).

Deploy, in this order:

1. Announce the window. Back up the database (see [BACKUP_RESTORE.md](./BACKUP_RESTORE.md)) and verify the backup.
2. Run the BEFORE query below and save its output. Also run the three checks after it and give the results to the owner.
3. `php artisan down --retry=60`.
4. Deploy the code: `composer install --no-dev --optimize-autoloader`, then the `npm run build` artefacts.
5. `php artisan migrate --force`. If migration 5 (the cutover) fails, stay in maintenance mode, run `php artisan migrate:rollback --step=4`, redeploy the previous release, and continue at step 8.
6. `php artisan config:cache` (the `permission.models` config changed), then `php artisan permission:cache-reset` and `php artisan queue:restart`.
7. Run the AFTER query and `diff` it against step 2: the diff must be empty. Run the audience check that follows the three checks: it must return no rows. Then check by hand that every persona can sign in, that **Peran** lists the copies, and that `/app-only` works for a technician.
8. `php artisan up`.

BEFORE query (read-only; it compares permissions per user and company, without the five new ones, which the migration adds on purpose):

```sql
SET SESSION group_concat_max_len = 1048576;
WITH m AS (
    SELECT id AS user_id, company_id FROM users WHERE company_id IS NOT NULL
    UNION
    SELECT user_id, company_id FROM company_user
),
g AS (
    -- BEFORE deploy: roles are global, so they apply in every company of the user.
    SELECT m.user_id, m.company_id, rhp.permission_id
    FROM m
    JOIN model_has_roles mhr ON mhr.model_id = m.user_id AND mhr.model_type = 'App\\Models\\User'
    JOIN role_has_permissions rhp ON rhp.role_id = mhr.role_id
    UNION
    SELECT m.user_id, m.company_id, mhp.permission_id
    FROM m
    JOIN model_has_permissions mhp ON mhp.model_id = m.user_id AND mhp.model_type = 'App\\Models\\User'
)
SELECT m.user_id, m.company_id, COUNT(p.id) AS permissions,
       SHA2(COALESCE(GROUP_CONCAT(p.name ORDER BY p.name), ''), 256) AS fingerprint
FROM m
LEFT JOIN g ON g.user_id = m.user_id AND g.company_id = m.company_id
LEFT JOIN permissions p ON p.id = g.permission_id
     AND p.name NOT IN ('app.web', 'app.technician', 'portal.access', 'evaluation.evaluable', 'report.technician.team')
GROUP BY m.user_id, m.company_id
ORDER BY m.user_id, m.company_id;
```

The AFTER query is the same, with the first branch of `g` joined through the role's company:

```sql
    JOIN model_has_roles mhr ON mhr.model_id = m.user_id AND mhr.model_type = 'App\\Models\\User'
    JOIN roles r ON r.id = mhr.role_id AND r.company_id = m.company_id
    JOIN role_has_permissions rhp ON rhp.role_id = r.id
```

Three more checks before the window. Role assignments of users with no company cannot be mapped and are dropped by the cutover (they act in no company, so they lose nothing); count them:

```sql
SELECT COUNT(*) FROM model_has_roles mhr JOIN users u ON u.id = mhr.model_id
WHERE mhr.model_type = 'App\\Models\\User' AND u.company_id IS NULL
  AND NOT EXISTS (SELECT 1 FROM company_user cu WHERE cu.user_id = u.id);
```

Users who hold the technician role together with a role that grants `evaluation.view` will now see all evaluations (access is additive, delta D1 in `docs/SECURITY.md` 2.7):

```sql
SELECT DISTINCT mhr.model_id FROM model_has_roles mhr
JOIN roles r ON r.id = mhr.role_id AND r.name = 'technician'
WHERE EXISTS (SELECT 1 FROM model_has_roles o JOIN role_has_permissions rp ON rp.role_id = o.role_id
              JOIN permissions p ON p.id = rp.permission_id AND p.name = 'evaluation.view'
              WHERE o.model_id = mhr.model_id AND o.model_type = mhr.model_type);
```

`roles.manage` changes meaning in this release. Until now it only let a user read the role and permission pages. From now on whoever holds it creates, edits and deletes every role of his company, is exempt from the stacked separation-of-duties check, and, together with `app.web` (the cutover gives that to every role except technician and customer), counts as an administrator of the company. The migration compares permission names only, so it keeps each holder as he is. List everyone who holds `roles.manage` through a role other than `admin`, or directly, and decide with the owner for each one: take it away before the window, or accept it. An empty result is the expected one.

```sql
SELECT u.id AS user_id, u.company_id, u.email, r.name AS role
FROM model_has_roles mhr
JOIN roles r ON r.id = mhr.role_id AND r.name <> 'admin'
JOIN role_has_permissions rhp ON rhp.role_id = r.id
JOIN permissions p ON p.id = rhp.permission_id AND p.name = 'roles.manage'
JOIN users u ON u.id = mhr.model_id
WHERE mhr.model_type = 'App\\Models\\User'
UNION ALL
SELECT u.id, u.company_id, u.email, '(direct permission)'
FROM model_has_permissions mhp
JOIN permissions p ON p.id = mhp.permission_id AND p.name = 'roles.manage'
JOIN users u ON u.id = mhp.model_id
WHERE mhp.model_type = 'App\\Models\\User'
ORDER BY company_id, user_id;
```

Audience check, run after the migration (step 7): no role may hold `portal.access` together with `app.web`, because the role of customer accounts must never open the back office. The role editor, the template editor and the operator's repair refuse that combination from this release on. The cutover grants `app.web` to every global role except technician and customer, so a custom role that already held `portal.access` would be listed here: take one of the two permissions away in **Peran**. It must return no rows.

```sql
SELECT r.company_id, r.id AS role_id, r.name
FROM roles r
JOIN role_has_permissions a ON a.role_id = r.id
JOIN permissions pa ON pa.id = a.permission_id AND pa.name = 'portal.access'
JOIN role_has_permissions b ON b.role_id = r.id
JOIN permissions pb ON pb.id = b.permission_id AND pb.name = 'app.web'
ORDER BY r.company_id, r.id;
```

Notes:

- If migration 5 throws, migrations 1 to 4 stay applied, and the new code would hide every global role. Keep maintenance mode on until the rollback is done.
- The cutover uses MySQL-only SQL (temporary tables, `SHA2`), which matches the supported stack (MySQL 8.4).
- The permission cache is one global key that holds the roles of every company. It grows with the number of company roles (about 500 `role_has_permissions` rows per company with the eight templates). `permission:cache-reset` after the deploy rebuilds it.
- A later release that adds a permission does it with `PermissionCatalogue::release()` in a migration (`docs/SECURITY.md` 2.5), never with the seeder.
- **Rollback**: `php artisan down`; `php artisan migrate:rollback --step=6`; redeploy the previous release; then `config:cache`, `permission:cache-reset`, `queue:restart` and `up`. Migration 5 refuses to roll back once companies have diverged (two companies hold different permissions under one role name, a company lacks a role name another has, a user's role names differ between his companies, or a top-up role has users). It then stops with "Roles changed per company since the cutover; restore the pre-deploy backup instead." Restore the backup taken in step 1.

## Health and readiness

- `/up` is the liveness endpoint.
- `/ready` is the operational readiness endpoint.
- `/ready` must fail when the database is unavailable and must not expose exception text, hostnames, SQLSTATE, or credentials.
- Readiness includes database, cache, queue, failed jobs, scheduler heartbeat, and backup marker checks.
- The backup marker is set by `php artisan ops:backup-heartbeat` after every verified backup. If the marker is older than 25 hours, `/ready` reports backup `fail` (degraded). If the marker was never set, it reports `unknown`.

## Scheduler ownership

Use exactly one scheduler owner per environment.

Recommended pattern:

- one designated host runs the scheduler timer or cron entry
- all other hosts do not run `schedule:run`
- billing and SLA jobs remain in `php artisan schedule:list` so ownership is auditable

## Queue supervision

Queue workers must be supervised by the host process manager.

Required behavior:

- restart automatically on crash
- start on boot
- use bounded retries and timeout
- restart after deploy
- keep worker output in host logs, not ad-hoc terminals

Recommended worker settings for this app:

- `--sleep=3`
- `--tries=3`
- `--timeout=90` or tighter if the job contract allows it

The `platform` worker is the one exception: `queue:work platform --queue=platform --tries=1 --timeout=3600`, with a stop timeout above one hour (see [platform-queue.md](./platform-queue.md)).

## Validation

After a deploy, verify:

- `php artisan schedule:list` shows the expected billing and ops jobs
- `/ready` returns a success or degraded state, never a stack trace
- queue workers are running under the process manager
- the new release can serve normal traffic

## Rollback decision

Use the rollback runbook when:

- the application release is broken but the database migration is reversible
- the release is healthy but the migration introduced data loss risk
- `/ready` remains failing after a basic restart and cache clear

If the database migration is irreversible, stop and follow the incident rollback document before changing anything else.