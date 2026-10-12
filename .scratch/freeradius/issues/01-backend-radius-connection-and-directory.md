# BACKEND: `radius` connection, switch and RadiusDirectory (RADIUS tables read/write)

Status: done
Role: backend
Blocked by: none
Card: none (new lane; no Hermes card)

## Scope

Additive foundation for `../spec.md` Q1, Q5, Q6, Q9, Q13, plus the config/env part of the RouterOS removal (this ticket owns those files).

- `config/database.php`: connection `radius` (driver `mysql`, same options as `mysql` at `config/database.php:45-66`) from `RADIUS_DB_HOST`, `RADIUS_DB_PORT` (3306), `RADIUS_DB_DATABASE` (`radius`), `RADIUS_DB_USERNAME`, `RADIUS_DB_PASSWORD`. Driver overridable by `RADIUS_DB_DRIVER` only so tests can use SQLite (`:memory:`).
- `config/services.php`: add block `radius` with `enabled` (`RADIUS_ENABLED`, default `false`) and `coa_timeout` (3); remove the `routeros` block (`:94-97`). Without it `config('services.routeros.enabled')` is null, i.e. off, so the RouterOS code stays inert until ticket 04 deletes it (its tests switch it on at run time).
- `.env.example` (`:87-90`), `.env.docker.example` (`:74-77`): replace the `ROUTEROS_*` lines by the `RADIUS_*` names above with empty values and a one-line comment (never values, never lab values).
- `Modules\NetworkAsset\Services\Radius\RadiusDirectory`: the only class touching the `radius` connection. Method contract exactly as in `../spec.md` (Implementation Decisions, "RadiusDirectory"): `putUser`, `removeUser`, `hasUser`, `putGroup`, `putNas`, `removeNas`, `openSessions`, `sessionsOnNas`, `usageSince`. Query builder only (portable to SQLite). Each write is one transaction on the `radius` connection. `radcheck` attribute `Cleartext-Password`, op `:=`; `radusergroup` priority 1; `radgroupreply` op `:=`; `nas.type` `other`.
  - `openSessions` = `acctstoptime IS NULL` and `COALESCE(acctupdatetime, acctstarttime) >= now - 15 min`, newest first; columns `acctsessionid`, `nasipaddress`, `framedipaddress`, `acctstarttime`, `acctupdatetime` (stock schema `~/labs/freeradius/db/initdb/10-schema.sql:17-62`).
  - `usageSince` sums `acctinputoctets` and `acctoutputoctets` (bigint; the stock 3.2 queries already fold the gigawords in) of sessions with `acctstarttime >= $from`.
  - `nasipaddress` is `varchar(15)`: IPv4 only. `putNas` accepts an IPv4 `nasname` (documented limit, matches RADIUS accounting).
- `RadiusException` with kinds `disabled` (switch off: refuse before any query), `unavailable` (connection/query error; message never contains host, user, secret or SQL bindings), `rejected`.
- `tests/Traits/InteractsWithRadius.php`: points `database.connections.radius` at a fresh SQLite `:memory:` DB, creates the stock tables and the columns used (`radcheck`, `radreply`, `radusergroup`, `radgroupreply`, `radacct`, `nas`) with the Schema builder, switches `services.radius.enabled` on; helpers to seed `radacct` sessions and read rows.

Owned files: `config/database.php`, `config/services.php`, `.env.example`, `.env.docker.example`, NEW `Modules/NetworkAsset/app/Services/Radius/RadiusDirectory.php`, NEW `Modules/NetworkAsset/app/Services/Radius/RadiusException.php`, NEW `tests/Traits/InteractsWithRadius.php`, NEW `tests/Feature/NetworkAsset/RadiusDirectoryTest.php`.

Produces: the `RadiusDirectory` methods and exception kinds; config keys `database.connections.radius`, `services.radius.enabled`, `services.radius.coa_timeout`; trait `InteractsWithRadius`.

## Acceptance criteria

- [ ] `RadiusDirectoryTest` covers every method: `putUser` replaces prior rows of that username only; `removeUser` leaves other usernames; `putGroup` replaces only that group's replies; `putNas` upserts by `nasname` and never touches other rows; `removeNas`; `openSessions` ignores stopped and stale (>15 min) rows; `sessionsOnNas` capped; `usageSince` sums from the given date.
- [ ] With `services.radius.enabled=false` every method throws `RadiusException` kind `disabled` and runs no query (assert with the `radius` connection query log).
- [ ] A broken connection maps to kind `unavailable`; the message contains no host, user or secret.
- [ ] No secret value appears in any exception message or log in the tests.
- [ ] `tests/Feature/NetworkAsset` and `tests/Feature/DemoDataSeederTest.php` still green with the `routeros` config block gone.

## Commands

Sail runs on docker context `default`: prefix every sail call with `DOCKER_CONTEXT=default`; never `sail up`, never the desktop-linux context.

```
DOCKER_CONTEXT=default ./vendor/bin/sail exec laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact tests/Feature/NetworkAsset tests/Feature/DemoDataSeederTest.php
```

## Comments
