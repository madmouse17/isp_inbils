# BACKEND: NAS router and network account schema, encrypted models, RouterOS REST client, permissions

Status: superseded (schema and models delivered; the RouterOS client was removed by `.scratch/freeradius` ticket 04)
Role: backend
Blocked by: none (starts only after the owner approves `../spec.md` Q1-Q6, Q13 and Q15; under Q2 b/c or Q3 b this ticket is rewritten first)
Card: none (new lane; no Hermes card)

## Scope

Foundation for tickets 03 and 04: storage, the router client and its test double. No HTTP routes, no jobs.

- Lab first: against a MikroTik CHR (RouterOS 7.x, Free licence) with `www-ssl` and a certificate from a lab CA, confirm the request and response shapes of every operation below and the minimal RouterOS user-group policies that allow REST writes without `sensitive`. Record the shapes and the policy list in a `## Comments` entry of this ticket, with credentials and addresses redacted.
- Config: `config/services.php` gets a `routeros` block (`enabled` ← `ROUTEROS_PROVISIONING_ENABLED`, default false; `ca_bundle` ← `ROUTEROS_CA_BUNDLE`, empty = system CA store), following the `midtrans` block (`config/services.php:17-62`). `.env.example` gets the two names without values.
- Migrations in the NetworkAsset module, both reversible (drop table), columns exactly as `../spec.md` Implementation Decisions "Schema": `nas_devices` (unique `network_asset_id` FK restrict, tenant `company_id` FK) and `network_accounts` (unique `subscription_id` FK to `service_subscriptions`, `nas_device_id` and `provisioned_nas_device_id` FKs restrict, unique `(company_id, username)`, index `(company_id, nas_device_id, sync_status)`, ENUMs `pppoe|hotspot` and `pending|synced|failed`).
- Models `NasDevice` and `NetworkAccount`: `BelongsToCompany`; explicit `$fillable` without the `provisioned_*`, status and sync columns (written by the engine with `forceFill`); `api_password` and `password` cast `encrypted` and listed in `$hidden` (precedent `app/Models/User.php:57-58,75-76`); `unmanaged_usernames` cast to array; relations `NasDevice::networkAsset()` (same module), `NasDevice::networkAccounts()`, `NetworkAccount::subscription()` (shared Core model), `NetworkAccount::nasDevice()`, `NetworkAccount::provisionedNasDevice()`. No `LogsActivity` (the encrypted attributes would reach the activity log decrypted). Factories for both, with fake credentials only.
- `RouterOsClient` (Http client; base URL `https://<asset management_ip>:<api_port>/rest`; HTTP Basic; `connectTimeout(5)`, `timeout(10)`; `withoutRedirecting()`; `withOptions(['verify' => <ca_bundle or true>])`; refuses to send anything when `services.routeros.enabled` is false; chain modelled on `Modules/Billing/app/Services/MidtransGateway.php:164-167`). Operations, each taking the NAS router and the service type (`pppoe` → `/ppp/secret`, `/ppp/active`; `hotspot` → `/ip/hotspot/user`, `/ip/hotspot/active`):
  - `systemResource()` → version, board name, uptime;
  - `find(name)` → id, profile, disabled, comment, or null;
  - `create(fields)` → id; `update(id, fields)`; `remove(id)` (404 = already gone);
  - `activeSessions(?name)` → id, name, address; `disconnect(activeId)` (404 = already gone);
  - `listAccounts()` with `.proplist=.id,name,profile,disabled,comment`.
- `RouterOsException` with a `kind` of `unreachable|tls|auth|rejected|server` and a message limited to the RouterOS `message` field of a JSON error body (≤255 chars). Other response bodies are never echoed or logged, because the host is staff-entered.
- `RolePermissionSeeder`: add `network_asset.nas.view`, `network_asset.nas.manage`, `network_asset.account.view`, `network_asset.account.manage`, `network_asset.account.reveal` and the role mapping approved in Q4 (recommended: admin all; manager and staff `nas.view` + all `account.*`; technician none; `nas.manage` admin only). Existing assignments at `database/seeders/RolePermissionSeeder.php:72-82,198-202,219-222,266,287` stay as they are.
- NEW test trait `InteractsWithRouterOs` modelled on `tests/Traits/InteractsWithMidtrans.php:44-55`: `Http::preventStrayRequests()`, one in-memory router per host (system resource, PPP secrets, PPP profiles, PPP active sessions, hotspot users, hotspot active sessions) answering GET/PUT/PATCH/DELETE with the recorded shapes, per-host failure switches (connection refused, timeout, TLS error, 401, 400 with a RouterOS message, 500, a 302 redirect), helpers to seed and inspect router state, and fake credentials only. Tickets 03, 04 and 06 use it without editing it.

Owned files:
- NEW `Modules/NetworkAsset/database/migrations/2026_10_13_110000_create_nas_devices_table.php`
- NEW `Modules/NetworkAsset/database/migrations/2026_10_13_110001_create_network_accounts_table.php`
- NEW `Modules/NetworkAsset/app/Models/NasDevice.php`
- NEW `Modules/NetworkAsset/app/Models/NetworkAccount.php`
- NEW `Modules/NetworkAsset/database/factories/NasDeviceFactory.php`
- NEW `Modules/NetworkAsset/database/factories/NetworkAccountFactory.php`
- NEW `Modules/NetworkAsset/app/Services/RouterOsClient.php`
- NEW `Modules/NetworkAsset/app/Services/RouterOsException.php`
- `config/services.php`
- `.env.example`
- `database/seeders/RolePermissionSeeder.php`
- NEW `tests/Traits/InteractsWithRouterOs.php`
- NEW `tests/Feature/NetworkAsset/RouterOsClientTest.php`
- NEW `tests/Feature/NetworkAsset/NetworkAccessModelsTest.php`

Cross-lane: `ipam-odn` also edits `RolePermissionSeeder.php`; land serially.

Non-goals: provisioning logic, jobs, observer, sync (ticket 03); controllers, routes, requests, resources (ticket 04); UI (ticket 05); new Composer packages (none under Q3a).

## Acceptance criteria

- [ ] Shapes and policy list from the lab recorded in `## Comments` without credentials; the fake router answers with those shapes.
- [ ] Contract tests, one per operation and service type: HTTP method, path, `.proplist`, JSON body keys, Basic auth header present (asserted without printing it), certificate verification requested (`verify` is the bundle path or `true`, never `false`).
- [ ] Error mapping: connection refused and timeout → `unreachable`; TLS failure → `tls`; 401/403 → `auth`; 400 → `rejected` carrying the RouterOS message; 5xx → `server`; a redirect is not followed and maps to `server`; a non-RouterOS body is never part of the message; 404 on `remove` and `disconnect` returns normally.
- [ ] With `services.routeros.enabled` false the client sends no request at all (stray-request guard proves it).
- [ ] Models: the raw database value of both password columns differs from the plaintext; neither appears in `toArray()`/`toJson()`; queries without company context return nothing and creates abort 403; unique `(company_id, username)`, unique `subscription_id` and unique `network_asset_id` enforced by the database.
- [ ] Migrations up/down on `inbils_test`; `php artisan db:seed --class=RolePermissionSeeder` is idempotent and assigns the approved mapping.
- [ ] Grep of the repo shows no real router credential or address; Pint and Larastan clean on new and changed files.

## Commands

```
./vendor/bin/sail exec laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact tests/Feature/NetworkAsset
vendor/bin/pint --dirty --format agent
```

## Comments

### Backend agent (MikrotikBackend), 2026-10-13

- Done: migrations `2026_10_13_210000_create_nas_devices_table`, `2026_10_13_210001_create_network_accounts_table` (prefix is the lane prefix of this wave, not `110000`); models `NasDevice`, `NetworkAccount` (encrypted + hidden passwords, `BelongsToCompany`, no `LogsActivity`); factories with fake credentials and RFC 5737 addresses; `RouterOsClient` / `RouterOsException`; `services.routeros` config + `.env.example` / `.env.docker.example` names; test double `tests/Support/FakeRouterOs.php` + trait `tests/Traits/InteractsWithRouterOs.php`; `RouterOsClientTest` (contract per operation and service type, every error kind, switch off, stray requests refused) and `NetworkAccessModelsTest`.
- Schema additions beyond the spec table (additive, needed by ticket 03): `network_accounts.provisioned_service_type` (clean the old menu after a PPPoE ↔ hotspot change) and `network_accounts.provisioned_password_hash` (HMAC of the pushed password: the router never gives passwords back, so this is how a password change is seen and how a second run stays at zero writes).
- Error message: the kept text is the RouterOS `message` plus `detail` of a JSON error body (the useful part, e.g. "input does not match any value of profile", is in `detail`), capped at 255 characters; a JSON body without a string `message` and any non-JSON body are never echoed. **Confirm in the lab.**
- STILL OPEN (cannot be done without a CHR): the lab-first criterion, i.e. recording the real request/response shapes and the minimal RouterOS user-group policy list in this `## Comments`. The fake answers with the documented RouterOS 7 REST shapes (inference, see the spec "External contract").
- needs-change (lead-owned): `database/seeders/RolePermissionSeeder.php` must add `network_asset.nas.view`, `network_asset.nas.manage`, `network_asset.account.view`, `network_asset.account.manage`, `network_asset.account.reveal`; mapping admin = all five; manager and staff = `nas.view` + `account.view|manage|reveal`; technician none (`nas.manage` admin only). Tests create these permissions themselves.
- Evidence: `tests/Feature/NetworkAsset` is part of the final run `Tests: 220 passed (1667 assertions)`; Pint passed; Larastan clean.
- Not verified: `down()` rehearsal of both migrations on `inbils_test`.
