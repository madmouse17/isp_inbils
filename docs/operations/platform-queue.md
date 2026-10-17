# Platform queue

Owner: operations  
Related: [DEPLOYMENT.md](./DEPLOYMENT.md), [SUPERVISION.md](./SUPERVISION.md), [DOCKER.md](./DOCKER.md), [tenant-purge.md](./tenant-purge.md)

The platform panel (`/platform`) hands its slow work to a queue of its own, so an hour-long job never blocks a customer's invoice mail or WhatsApp message and a 90-second worker never kills it.

| What | Value |
|---|---|
| Queue connection | `platform` (`config/queue.php`): database driver, the same `jobs` table as the default connection |
| Queue name | `platform` |
| `retry_after` | 3660 s, fixed in `config/queue.php` (no environment variable): above the worker timeout, so a running job is never handed to a second worker |
| Worker | `php artisan queue:work platform --queue=platform --sleep=3 --tries=1 --timeout=3600` |
| Jobs on it | `SeedTenantDemoDataJob` (sample data for a new tenant); the tenant export job of the platform panel |
| Failed jobs | the shared `failed_jobs` table, like every other connection |

The default worker (`--queue=default,whatsapp`) never reads this queue, and this worker never reads the default ones. Both must run in production.

## Why it is separate

- A sample-data run builds six months of history and an export streams every table of a tenant: minutes to an hour, not the 90 seconds of the default worker.
- One try. A half-finished sample-data run rolls back as a whole, and an export that failed leaves a row in the panel; a silent retry would hide both. The operator starts it again deliberately.
- `retry_after` (3660 s) must stay above `--timeout` (3600 s). The default connection's 90 s would make a second worker pick up the same job after 90 seconds.

## Run it

**Docker (production build).** `compose.yaml` has a `platform-queue` service next to `queue`. It sets `stop_grace_period: 3660s`, because Docker stops a container after 10 seconds by default and would kill a job in the middle.

```bash
docker compose --env-file .env.docker up -d --force-recreate platform-queue
docker compose --env-file .env.docker logs --tail=100 platform-queue
```

**systemd.** Copy [`deploy/systemd/platform-queue-worker.service.example`](../../deploy/systemd/platform-queue-worker.service.example) the way [DEPLOYMENT.md](./DEPLOYMENT.md#platform-queue-worker) shows. `TimeoutStopSec=3660` is what lets `systemctl stop` wait for a running job instead of sending SIGKILL after 90 seconds.

**Supervisor.** The `inbils-platform-queue` program in [`examples/supervisor-laravel.conf.example`](./examples/supervisor-laravel.conf.example), with `stopwaitsecs=3700`.

**Windows (NSSM).** Same command line as above as a second service, with a stop timeout above one hour.

**Sail (development).** Queue workers are commands, not services:

```bash
./vendor/bin/sail artisan queue:work platform --queue=platform --tries=1 --timeout=3600
```

## Deploy

`php artisan queue:restart` tells every worker, this one included, to exit after its current job; the process manager starts the new code. A running sample-data job finishes first, so a deploy can wait up to an hour for this worker. Do not kill it to speed a deploy up: the job rolls back, and the operator must start it again.

## When something is wrong

| Symptom | Check |
|---|---|
| "Isi data contoh" was ticked but a new tenant stays empty | Is the platform worker running (`docker compose ps platform-queue`, `systemctl status inbils-platform-queue-worker`)? Rows with `queue = 'platform'` pile up in `jobs` when it is not. |
| A tenant has no sample data and the platform audit shows `tenant.demo_data_failed` | The row carries the error class and a short message. `php artisan queue:failed` has the full job. Fix the cause, then dispatch `SeedTenantDemoDataJob` again; the run is all or nothing, so a failed one left nothing behind. |
| `tenant.demo_data_failed` with "already has customers, jobs or invoices" | The tenant got data before the job ran. Sample data only goes into an empty company; this is working as designed. |
| A job ran twice | `retry_after` or `--timeout` was changed. Keep `retry_after` (config/queue.php) above `--timeout` of this worker. |
| Worker killed in the middle of a job | The stop timeout of the process manager is shorter than the job (see the table above). |

Do not raise `--tries` on this worker without a root cause: a second attempt of an export or a sample-data run is a different operation, not a retry of the first.
