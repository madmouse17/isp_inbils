# Process Supervision

Owner: operations  
Related: [DEPLOYMENT.md](./DEPLOYMENT.md), examples under [examples/](./examples/)

## Processes

| Process | Command | Notes |
|---|---|---|
| HTTP | php-fpm / Apache / `php artisan serve` (local only) | Host-specific |
| Scheduler | once per minute `php artisan schedule:run` **or** long-running `php artisan schedule:work` | **Single owner only** — never double-run |
| Queue worker | `php artisan queue:work --sleep=3 --tries=3 --timeout=90` | Restart after every deploy |
| Platform queue worker | `php artisan queue:work platform --queue=platform --tries=1 --timeout=3600` | Needs a stop timeout above 3600 s (systemd `TimeoutStopSec=3660`, supervisor `stopwaitsecs=3700`); see [platform-queue.md](./platform-queue.md) |

Tune `--timeout` above longest expected job. Billing schedules today are artisan commands on the scheduler, not queue jobs; still supervise workers when queues are introduced.

## Billing schedules (app)

Defined in `routes/console.php`:

- `billing:generate` — monthly on day 1 at 02:00
- `billing:check-overdue` — daily at 02:30

Verify:

```bash
php artisan schedule:list
php artisan ops:print-schedule
```

## Restart policy

- On deploy: stop old workers, start new ones (or `queue:restart` then ensure supervisor brings processes back).
- On crash: supervisor/NSSM/Task Scheduler restarts with backoff.
- Failed jobs: inspect `failed_jobs` when queue driver supports it; do not silently raise `--tries` without root-cause.

## Host examples

This repository ships systemd examples under `deploy/systemd/`. Additional examples:

- [examples/supervisor-laravel.conf.example](./examples/supervisor-laravel.conf.example) — Linux supervisor
- [examples/nssm-queue-worker.ps1.example](./examples/nssm-queue-worker.ps1.example) — Windows NSSM
- [examples/windows-task-scheduler-schedule-run.xml.example](./examples/windows-task-scheduler-schedule-run.xml.example) — Windows Task Scheduler for `schedule:run`
- [examples/ci-ops-self-check.yml.example](./examples/ci-ops-self-check.yml.example) — CI self-check of scripts/docs

Copy and adapt; paths and users are host-local.

## Health after restart

```bash
bash scripts/operations/health-check.sh --base-url http://127.0.0.1:8000
```

Expect `/up` 200 and `/ready` 200 with `{"status":"ready"}` when DB is up.
