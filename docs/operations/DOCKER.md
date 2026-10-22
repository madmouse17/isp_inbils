# Docker Operations

The production-build stack (`compose.yaml` + `Dockerfile`) uses FrankenPHP (PHP 8.4), MySQL 8.4, Redis, two Laravel queue workers (`queue` for the default and WhatsApp queues, `platform-queue` for the platform panel's hour-long jobs, see [platform-queue.md](./platform-queue.md)), and a Laravel scheduler. Laravel storage and MySQL and Redis data use named volumes; `bootstrap/cache` is container-local and is not shared through a named volume. Local development uses Laravel Sail instead (next section).

## Local development (Laravel Sail)

Laravel Sail is the **local** development runtime. `compose.yaml` + `Dockerfile` (everything below this section) stay the **production** build and deploy path. They are independent Compose projects with no shared containers, networks, volumes, or ports.

| | Sail (local) | Production build |
|---|---|---|
| Compose file | `compose.sail.yaml`, selected by `SAIL_FILES` in `.env` | `compose.yaml` |
| Project / network | `inbils-sail` / `inbils-sail_sail` | `inbils` / `inbils_inbils` |
| App runtime | `sail-8.4/app` (PHP 8.4, Node 24, `artisan serve`, source bind-mounted) | FrankenPHP image `inbils-app:local`, source baked in |
| Env file | `.env` (`.env.example` holds the Sail defaults) | `.env.docker` via `--env-file` |
| App | <http://localhost> | <http://localhost:8080> |
| MySQL | `127.0.0.1:3306`, user `sail`, schema `inbils` | `127.0.0.1:3307`, user `inbils` |
| Redis / Mailpit | `127.0.0.1:6379` / <http://localhost:8025> | Redis internal only / none |
| Named volumes | `inbils-sail_sail-mysql`, `inbils-sail_sail-redis` | `inbils_mysql_data`, `inbils_redis_data`, `inbils_app_storage` |

First run, ports, and everyday commands are in [README.md](../../README.md#local-development-laravel-sail). Local verification:

```bash
./vendor/bin/sail up -d                  # builds the runtime image on first use
./vendor/bin/sail ps                     # laravel.test, mysql, redis, mailpit must be healthy
curl --fail http://localhost/up
curl --fail http://localhost/ready       # database, cache, queue and storage-link checks
```

- The source is bind-mounted: no rebuild after code edits. Run `./vendor/bin/sail npm run build` after frontend changes.
- Plain `docker compose` still resolves to `compose.yaml`. `compose.sail.yaml` is deliberately not an auto-discovered file name: a `docker-compose.yml` beside `compose.yaml` makes every `docker compose` call print "Found multiple config files" warnings.
- Every Sail port is published on `127.0.0.1` only (`APP_DEBUG=true`, public demo credentials, unauthenticated Redis).
- `composer install` and `npm` run inside the Sail container against the bind-mounted source. The rule below against `composer install` in the active app, queue, or scheduler containers concerns the production containers only.
- `sail artisan test` follows `phpunit.xml` (host `mysql`, schema `inbils_test`, user `inbils`) and runs on the Sail MySQL, whose init script `docker/mysql/sail/10-create-test-database.sh` creates that schema, user, and grants on first volume initialisation. It never touches the production stack's `inbils_test`, so it cannot race the isolated PHPUnit recipe below.
- Parallel PHPUnit (owner-approved exception to the one-schema rule, AGENTS.md "Test Database Rule"): `artisan test --parallel` (brianium/paratest, dev dependency) starts N worker processes; each runs whole test classes. A class with a database trait (`RefreshDatabase`, `DatabaseTransactions`, ...) is moved through `config()` to the worker's own schema `inbils_test_test_<token>` (token 1..N, maximum 4 processes); a class without one stays on the base `inbils_test`. Laravel creates a worker schema on first use and reuses it: **never** pass `--drop-databases` or `--recreate-databases` (the `inbils` user holds only ``GRANT ALL PRIVILEGES ON `inbils\_test\_test\_%`.*``, set by `docker/mysql/sail/10-create-test-database.sh`; an older `sail-mysql` volume needs that one grant added once as root). `Tests\TestCase` also gives each worker its own storage tree `storage/app/{private,public}/testing_<token>` and makes race children join the schema and tree of the test that starts them. Every worker rebuilds its schema with `migrate:fresh` once per run. Standard command, always as user `sail`, with `uptime` load1 < 6 first:

  ```bash
  flock /tmp/inbils-e2e.lock env DOCKER_CONTEXT=default ./vendor/bin/sail exec -T -u sail laravel.test \
    flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --parallel --processes=3 --compact
  ```

  Use parallel for the full suite (5409 tests). Use plain serial `artisan test --compact [--filter=…|path]` for a single file, a short subset, or when debugging an order-dependent failure: it runs on `inbils_test` and costs no schema rebuild. Measured 2026-10-21 on this host (12 CPUs, 15.5 GiB, every run green, 5409 tests): serial 4947 s (82 min, earlier count 5399), `--processes=3` 33 to 35 min wall (1983 to 2075 s), `--processes=4` 28 min (1680 s; load1 peaked 7.6, php RSS 1.8 GiB in total, MemAvailable never below 5 GiB). `--processes=3` is the default because load1 stays below 6.6 and other work keeps running; use 4 only on a quiet host. The assertion total differed by 3 between runs (60325 and 60328); every run passed.
- Queue and scheduler are commands, or opt-in services. By hand: `sail artisan queue:work --queue=default,whatsapp --tries=3` and `sail artisan schedule:work` in their own terminals, or detached with `sail exec -d -u sail laravel.test php artisan queue:work --queue=default,whatsapp --tries=3`. As containers: the `queue` and `scheduler` services of `compose.sail.yaml` sit under the profile `workers` and start only with `COMPOSE_PROFILES=workers ./vendor/bin/sail up -d` (or `scripts/dev-payments.sh`); they take the Sail image, the same bind mount and the same `.env`, with `restart: unless-stopped`. A worker keeps the code it booted with: after a change to a job, listener or notification run `sail artisan queue:restart` and the container restarts the worker. Stop them with `COMPOSE_PROFILES=workers ./vendor/bin/sail stop queue scheduler`. Without a worker running, the bell, the reactivation after a payment and `midtrans:reconcile` wait.
- The platform panel's long jobs (sample data, exports) need a second worker in Sail too: `sail artisan queue:work platform --queue=platform --tries=1 --timeout=3600` (not part of the `workers` profile). See [platform-queue.md](./platform-queue.md).
- Real Midtrans callbacks in dev: `scripts/dev-payments.sh` starts the app and the `workers` profile and, when `INBILS_TUNNEL` names a cloudflared tunnel, runs `cloudflared tunnel run "$INBILS_TUNNEL"`. Without the variable it prints how to expose `/webhooks/midtrans/{company}`; the app must be opened through the public https address, because the notification URL of an order is built from it.
- `public/storage` must be a link to `storage/app/public`. Production does it at start (`docker/app/entrypoint.sh`); Sail does not: run `sail artisan storage:link` once. `/ready` answers `storage_link: fail` and status `degraded` while the link is missing, points elsewhere or is a real directory (a copy made once hides every uploaded file; move it aside with `mv public/storage public/storage.old` first). A PHPUnit run reports `storage_link: unknown` because it never serves a file.
- Storage roots per environment: `STORAGE_ENV_SUFFIX` (read only in `config/filesystems.php`; empty, or one directory name with a leading slash) is appended to both local disk roots and to the public URL. The dev site and production leave it empty and keep `storage/app/{private,public}`; `phpunit.xml` pins `/testing` and `scripts/e2e-server-env.mjs` pins `/e2e`, so PHPUnit and E2E files live in `storage/app/{private,public}/{testing,e2e}` and a company id reused by another database never mixes with a file of the dev site. The public link is unchanged: `/storage/e2e/...` is a folder under the one directory it points to. A value that is not one plain directory name stops the application at boot.
- `sail stop` and `sail down` keep both volumes. The production volume rule applies unchanged: no `down -v` or `docker volume rm` without explicit approval to destroy local data.
- Do not run `php artisan sail:install` or `sail:add`: they edit `compose.yaml`.

## First startup

```bash
cp .env.docker.example .env.docker
# Replace APP_KEY and both database passwords before any shared or production deployment.
docker compose --env-file .env.docker config
docker compose --env-file .env.docker build
docker compose --env-file .env.docker up -d
# Preserve data: do not add --volumes and do not run docker compose --env-file .env.docker down -v.
docker compose --env-file .env.docker ps
```

Generate a unique application key without putting the value in shell history:

```bash
docker run --rm dunglas/frankenphp:php8.4-bookworm php -r 'echo "base64:", base64_encode(random_bytes(32)), PHP_EOL;'
```

Put the generated value in `.env.docker`; this file is ignored by Git. The committed `.env.docker.example` contains development-only placeholders and no production secret.

## Database migration and application setup

Run normal, volume-preserving migrations and seed only system roles, permissions, and defaults:

```bash
docker compose --env-file .env.docker exec app php artisan migrate --force
docker compose --env-file .env.docker exec app php artisan db:seed --force
```

Do not use `migrate:fresh` against persistent or shared data. On a fresh install, create the bootstrap user interactively, then finish the one-time company wizard in the browser:

```bash
docker compose --env-file .env.docker exec app php artisan inbils:setup
```

The MySQL init script also creates the mandatory automated-test database `inbils_test` on the first initialization of a new MySQL volume. Existing volumes are never reinitialized automatically.

## Isolated PHPUnit

The runtime image `inbils-app:local` contains production dependencies installed with `--no-dev`. **Never run `composer install` in the active app, queue, or scheduler containers. Never share their compiled cache or storage with a test container.**

The verified safe path uses a separately named `inbils-test-sandbox` container on the `inbils_inbils` network. It has a 30-minute sleep entrypoint and no host bind mounts, production `env_file`, `--volumes-from`, or shared volumes. Only the allowlisted source paths below are copied into `/test/app`; `bootstrap/cache` and test storage are created empty there.

`phpunit.xml` currently pins the development service connection: host `mysql`, port `3306`, schema `inbils_test`, and service user `inbils`. These credentials are development-specific and are not portable to arbitrary deployments. If the pinned credentials are wrong or `inbils_test` is absent, stop and ask an authorized operator; do not add broad grants or run `drop`, `migrate:fresh`, or similar destructive repair commands.

Run this recipe from the repository root. The name guards and `set -eu` ensure a failed prerequisite or isolation test cannot proceed to later tests:

```bash
(
set -eu

SANDBOX=inbils-test-sandbox
COMPOSER_HELPER=inbils-test-composer
NETWORK=inbils_inbils

for container in "$SANDBOX" "$COMPOSER_HELPER"; do
    if docker container inspect "$container" >/dev/null 2>&1; then
        printf 'Container name %s is already in use; stop and choose a free name.\n' "$container" >&2
        exit 1
    fi
done

docker network inspect "$NETWORK" >/dev/null

docker run -d \
    --name "$SANDBOX" \
    --network "$NETWORK" \
    --entrypoint sleep \
    inbils-app:local 1800 >/dev/null

docker exec "$SANDBOX" mkdir -p \
    /test/app/bootstrap/cache \
    /test/app/public \
    /test/app/storage/app/public \
    /test/app/storage/framework/cache/data \
    /test/app/storage/framework/sessions \
    /test/app/storage/framework/testing \
    /test/app/storage/framework/views \
    /test/app/storage/logs

for path in app config database Modules routes resources tests; do
    docker cp "$path" "${SANDBOX}:/test/app/"
done

for path in artisan composer.json composer.lock phpunit.xml modules_statuses.json; do
    docker cp "$path" "${SANDBOX}:/test/app/$path"
done

docker cp bootstrap/app.php "${SANDBOX}:/test/app/bootstrap/app.php"
docker cp bootstrap/providers.php "${SANDBOX}:/test/app/bootstrap/providers.php"
docker cp public/build "${SANDBOX}:/test/app/public/"

docker create \
    --name "$COMPOSER_HELPER" \
    --entrypoint /bin/true \
    composer:2 >/dev/null

umask 077
COMPOSER_TMP="$(mktemp -d)"
trap 'rm -f "$COMPOSER_TMP/composer.phar"; rmdir "$COMPOSER_TMP"' EXIT
docker cp "${COMPOSER_HELPER}:/usr/bin/composer" "$COMPOSER_TMP/composer.phar"
docker cp "$COMPOSER_TMP/composer.phar" "${SANDBOX}:/tmp/composer.phar"
rm -f "$COMPOSER_TMP/composer.phar"
rmdir "$COMPOSER_TMP"
trap - EXIT

docker exec \
    --workdir /test/app \
    --env COMPOSER_HOME=/tmp/composer-home \
    "$SANDBOX" \
    php /tmp/composer.phar install \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --no-progress

export APP_KEY="base64:$(openssl rand -base64 32)"

docker exec --workdir /test/app --env APP_KEY "$SANDBOX" \
    php vendor/bin/phpunit --testdox tests/Feature/Operations/TestDatabaseIsolationTest.php

docker exec --workdir /test/app --env APP_KEY "$SANDBOX" \
    php vendor/bin/phpunit --testdox tests/Feature/Operations/ReadinessEndpointTest.php

docker exec --workdir /test/app --env APP_KEY "$SANDBOX" \
    php vendor/bin/phpunit --testdox tests/Unit/ExampleTest.php

docker exec --workdir /test/app --env APP_KEY "$SANDBOX" \
    php vendor/bin/phpunit --testdox tests/Unit/PhpUnitTopologyTest.php
)
```

For the full suite, also copy `docs/` and `scripts/` into `/test/app/`; operations artifact checks read those paths. Run `php -d memory_limit=512M vendor/bin/phpunit` in the sandbox (the verified 760-test run peaked at 145 MiB, above PHP's default 128 MiB). Keep all PHPUnit, QA, and browser fixture runs serial: separate containers still share `inbils_test`, so concurrent `RefreshDatabase` runs can race during migrations. For Larastan, also copy `phpstan.neon` so the configured level 5 and extension are used; a no-config PHPStan run is not this gate.

Every test that extends `Tests\TestCase` validates the process and server target and rejects compiled configuration before Laravel bootstraps. Child subprocesses use an allowlisted, sanitized environment with the same pinned test target. In this documented focused recipe, the database isolation test is invoked first and verifies the selected database identity. Default or filtered suite safety relies on the `Tests\TestCase` pre-bootstrap target validation rather than suite order. The topology tests verify the configuration and guard behavior, include a read-only worker identity query, and perform no schema mutation. The generated `APP_KEY` exists only in the recipe's subshell, is not printed, and is passed to `docker exec` only for test processes; no real `.env` is required.

`docker cp` transfers through the Docker API, so this method still works when the CLI's `/tmp` is private and the Docker daemon cannot see a bind-mount source path.

After testing, remove only the separately named sandbox and stopped Composer helper. Do not add `-v` or `--volumes`, stop any live Compose service, or delete any volume:

```bash
docker rm -f inbils-test-sandbox
docker rm -f inbils-test-composer
```

## Application and health access

Defaults:

- Application: <http://localhost:8080>
- Liveness: <http://localhost:8080/up>
- Readiness: <http://localhost:8080/ready>

Override the host binding or port in `.env.docker`, for example `APP_BIND_ADDRESS=127.0.0.1` and `APP_PORT=8081`, then recreate the app service:

```bash
docker compose --env-file .env.docker up -d --force-recreate app
```

## Local `*.test` domains (like Laragon)

`docker/devproxy/compose.yaml` gives the two local stacks a name with HTTPS, development only:

| Address | Goes to |
| --- | --- |
| <https://inbils.test> | Sail (`laravel.test:80`) |
| <https://deploy.inbils.test> | the 8080 stack (`app:8080`) |

Two containers: `caddy` (HTTPS on `127.0.0.1:443` with certificates from its own local authority; port 80 stays Sail's, so there is no HTTP redirect) and `dns` (dnsmasq on `127.0.0.2:53`, answering every `*.test` name with `127.0.0.1` and nothing else). Both join the Sail and deploy networks as external networks, so start Sail and the 8080 stack first.

```bash
DOCKER_CONTEXT=default docker compose -f docker/devproxy/compose.yaml up -d --build
```

Both `.env` and `.env.docker` set `TRUSTED_PROXIES=172.16.0.0/12` (Docker networks), so the app keeps the `https` scheme behind Caddy; without it the page loads its assets over `http` and the browser blocks them. On a real server name the real proxy instead. `APP_URL` stays `http://localhost` and `http://localhost:8080`, so mail and queued links, the E2E suite and the healthchecks are unchanged; both stacks run with `APP_ENV=local`, where Laravel does not check the host. A stack switched to `production` must get the `.test` name as its `APP_URL`, or the host check refuses it.

One-time steps on the host (done on the owner's machine 2026-10-22, no sudo needed):

1. Send `.test` lookups to the `dns` container through a NetworkManager dummy interface (the desktop user may add connections, and systemd-resolved then routes only `~test` there; every other name keeps the normal resolver):

   ```bash
   nmcli connection add type dummy ifname inbils-dns con-name inbils-dns connection.autoconnect yes \
     ipv4.method manual ipv4.addresses 10.254.53.1/32 ipv4.never-default yes \
     ipv4.dns 127.0.0.2 ipv4.dns-search "~test" ipv6.method disabled
   resolvectl query inbils.test   # 127.0.0.1
   ```

   Without NetworkManager, the same with sudo: a drop-in `/etc/systemd/resolved.conf.d/inbils-test.conf` with `[Resolve]`, `DNS=127.0.0.2`, `Domains=~test`, then `sudo systemctl restart systemd-resolved`.

2. Trust the local certificate authority for Chrome and Brave (both read the user's NSS store on Linux), then restart the browser, which reads the store only when it starts:

   ```bash
   DOCKER_CONTEXT=default docker compose -f docker/devproxy/compose.yaml cp caddy:/data/caddy/pki/authorities/local/root.crt ~/inbils-local-ca.crt
   mkdir -p ~/.pki/nssdb && [ -f ~/.pki/nssdb/cert9.db ] || certutil -d sql:$HOME/.pki/nssdb -N --empty-password
   certutil -d sql:$HOME/.pki/nssdb -A -t "C,," -n "Inbils local CA" -i ~/inbils-local-ca.crt
   ```

   Command-line tools (`curl`) need the system store too: `sudo cp ~/inbils-local-ca.crt /usr/local/share/ca-certificates/ && sudo update-ca-certificates`. Firefox keeps its own list: Settings, Privacy & Security, Certificates, View Certificates, Authorities, Import. The authority can sign a certificate for any name, and its key is in the `caddy_data` volume: never copy that volume off this machine, and keep the volume, or every browser must trust a new authority. A browser whose secure DNS is set to a fixed provider (Google, Cloudflare) skips the local resolver: set it to the current service provider, or off.

Limits: `npm run dev` (Vite HMR on `http://localhost:5173`) does not work behind `https://inbils.test`, because the browser blocks the plain-HTTP script; use <http://localhost> for HMR, or `npm run build`. The Android emulator cannot resolve `*.test`, so the apps keep `10.0.2.2`. Caddy reaches the stacks by their service names on their networks; if a `*.test` address answers 502 after Sail or the 8080 stack was taken down and up again, reattach the proxy with `DOCKER_CONTEXT=default docker compose -f docker/devproxy/compose.yaml up -d --force-recreate`. To remove it all: `docker compose -f docker/devproxy/compose.yaml down`, `nmcli connection delete inbils-dns` and `certutil -d sql:$HOME/.pki/nssdb -D -n "Inbils local CA"`.

## Local MySQL CLI

The safe default publishes MySQL only on host loopback at `127.0.0.1:3307`:

```bash
mysql --protocol=TCP -h 127.0.0.1 -P 3307 -u inbils -p inbils
```

Or avoid publishing-path dependencies and use the container client:

```bash
docker compose --env-file .env.docker exec mysql mysql -u inbils -p inbils
```

## Remote or LAN MySQL access

Remote access is opt-in. Set a specific trusted LAN address in `.env.docker`, not a wildcard when avoidable:

```dotenv
MYSQL_BIND_ADDRESS=192.168.1.10
MYSQL_HOST_PORT=3307
```

Recreate only MySQL while preserving its named volume:

```bash
docker compose --env-file .env.docker up -d --force-recreate mysql
```

Connect remotely with `mysql -h 192.168.1.10 -P 3307 -u inbils -p inbils`.

### Firewall and security

- Keep `MYSQL_BIND_ADDRESS=127.0.0.1` unless remote database access is required.
- Prefer a VPN or SSH tunnel over exposing MySQL directly.
- If LAN access is required, allow TCP 3307 only from explicit trusted source IPs in the host/cloud firewall; deny all other sources.
- Never expose MySQL to `0.0.0.0` on an Internet-facing host without network ACLs, TLS, restricted users, and rotated strong credentials.
- Replace all example credentials and generate a unique `APP_KEY`; never commit `.env.docker`.
- Do not publish Redis. It is intentionally reachable only on the Compose network.

## Logs

- Every service in `compose.yaml` (`app`, `queue`, `platform-queue`, `scheduler`, `mysql`, `redis`) shares one `logging` block: driver `json-file`, `max-size: "10m"`, `max-file: "5"`. A container keeps at most 50 MB of output; Docker drops the oldest file. A new service needs the same line (`logging: *logging`), and `tests/Feature/Operations/OperationsArtifactsTest.php` fails without it.
- The cap is read when a container is created. After a pull that changes it, `docker compose --env-file .env.docker up -d` recreates the services whose definition changed. `restart` does not apply it.
- The app writes to the same output through its own channels: with `LOG_STACK=stderr,daily` and `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter` (the `.env.docker.example` values), `docker compose --env-file .env.docker logs app` shows one JSON object per line, each with `extra.request_id`. The files `storage/logs/laravel-YYYY-MM-DD.log` live in the `inbils_app_storage` volume, 14 days (`LOG_DAILY_DAYS`). Set these keys in the real `.env.docker`: a copy made before this release still has `LOG_STACK=single`, whose `laravel.log` has no limit and is not pruned. See [OBSERVABILITY.md](./OBSERVABILITY.md) and [DEPLOYMENT.md](./DEPLOYMENT.md).
- Search one request: `docker compose --env-file .env.docker logs --since 1h app queue | grep <request id>`. The queue worker logs a job with the id of the request that dispatched it.

## Rebuild and update without deleting volumes

Implementation changes must rebuild/recreate affected services and verify health:

```bash
docker compose --env-file .env.docker build app queue platform-queue scheduler
docker compose --env-file .env.docker up -d --force-recreate app queue platform-queue scheduler
docker compose --env-file .env.docker ps
curl --fail http://localhost:${APP_PORT:-8080}/ready
```

For an image refresh and full safe update:

```bash
docker compose --env-file .env.docker pull mysql redis
docker compose --env-file .env.docker build --pull app queue platform-queue scheduler
docker compose --env-file .env.docker up -d --force-recreate
docker compose --env-file .env.docker exec app php artisan migrate --force
docker compose --env-file .env.docker ps
```

Stop or remove containers while preserving named volumes:

```bash
docker compose --env-file .env.docker stop
docker compose --env-file .env.docker down
```

Never use `docker compose --env-file .env.docker down -v`, `docker volume rm`, `docker system prune --volumes`, or destructive migration commands unless data destruction is explicitly approved and a verified backup exists.

Useful diagnostics:

```bash
docker compose --env-file .env.docker ps
docker compose --env-file .env.docker logs --tail=200 app queue platform-queue scheduler mysql redis
docker compose --env-file .env.docker exec app php artisan about
docker compose --env-file .env.docker exec mysql mysqladmin ping -u inbils -p
```
