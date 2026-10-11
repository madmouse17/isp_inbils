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