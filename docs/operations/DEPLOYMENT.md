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
- `TRUSTED_PROXIES` (`.env` or the container environment; comma-separated proxy addresses, CIDR ranges allowed, `*` only when the proxy is the single way in): set it behind a reverse proxy or load balancer, a TLS terminator in front of port 8080 for one. Empty, the default, trusts nobody, so every visitor is seen at the proxy's address: the per-address throttles (logins, API, WhatsApp webhook) share one budget and the audit entries carry the proxy's address. The enumeration limiter counts signed-in users only, so it does not depend on it. Other keys (defaults in `config/security.php`): `SECURITY_ENUMERATION_ENABLED`, `SECURITY_ENUMERATION_THRESHOLD` (30), `SECURITY_ENUMERATION_WINDOW` (5 minutes), `SECURITY_ENUMERATION_COOLDOWN` (10 minutes).
- Old integer links answer 404; users reopen records from the lists.

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