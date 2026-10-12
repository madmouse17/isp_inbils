# BACKEND: remove RouterOS provisioning and cut every caller over to RADIUS

Status: done
Role: backend
Blocked by: 01, 02, 03
Card: none (new lane; no Hermes card)

## Scope

Clean cutover per `../spec.md` Q2 (a) and the "Removal inventory". One change: after it, no RouterOS code, test, dependency, env key or column remains, and every existing caller works against RADIUS. Follow the desired-state table, apply algorithm, triggers, NAS writes, routes, validation, props and audit in `../spec.md` (Implementation Decisions) exactly.

1. **Delete** every file listed under "DELETE" for ticket 04 in `../spec.md` File ownership, the `check` route (`Modules/NetworkAsset/routes/web.php:27`) and its controller action, the `management_ip` guard in `UpdateNetworkAssetRequest::after` (`Modules/NetworkAsset/app/Http/Requests/UpdateNetworkAssetRequest.php:44-64`), the observer registration of `NetworkAssetEndpointObserver` (`Modules/NetworkAsset/app/Providers/EventServiceProvider.php:24-27`), the `routes/console.php:24` comment (keep the `network:sync` schedule at `:25`). `services.routeros` and `ROUTEROS_*` are removed by ticket 01 (it owns those files); report `needs-change` if any is left.
2. **Dependency**: `composer remove evilfreelancer/routeros-api-php` (removes `spatie/ssh` too); drop `sockets` from `Dockerfile:14` and `:41` (nothing else needs it: `composer.lock:943` is the only requirer, monolog only suggests it at `:3009`). Run `composer audit` and paste the result.
3. **Schema** (forward migrations; `down()` restores the dropped columns as nullable and the old unique key):
   - `2026_10_16_110000_move_nas_devices_and_network_accounts_to_radius.php`: `nas_devices` drops the RouterOS columns and adds `nasname` (unique, IPv4), `coa_port` (default 1700), `radius_secret` (encrypted text), `radius_nasname`, `radius_synced_at`; existing rows get `nasname` = their asset `management_ip`. `network_accounts` drops `nas_device_id`, `provisioned_nas_device_id`, `provisioned_profile`, `provisioned_service_type`, `last_online_at`, `current_address`; adds `provisioned_group`; unique `username` replaces `(company_id, username)`.
   - `2026_10_16_110001_append_realm_to_network_account_usernames.php`: data only, appends `@<lower company code>` to usernames without `@`; `down()` strips it.
4. **Models/factories**: `NasDevice` fillable `network_asset_id`, `nasname`, `coa_port`, `radius_secret`; `radius_secret` encrypted and hidden; realm accessor from the company code (`null` when it fails `^[a-z0-9-]{1,24}$`). `NetworkAccount` without the NAS relation. Factories with fake values only.
5. **Engine**: `NetworkAccountProvisioner` writes through `RadiusDirectory` and `RadiusDisconnectClient` (tickets 01, 02) following the apply algorithm; desired state isolated when suspended or `blocked_at` set (ticket 03 columns). `ApplyNetworkAccountJob` keeps its queue contract (unique until processing, after commit, `tries` 5, backoff `[30,120,300,900]`, `timeout` 60, `failed()` audits `provision_failed`); drop the adoption-approval argument (no router secrets to adopt). `network:sync {--company=}` (drop `--nas`) re-queues as specified and writes unsynced NAS rows; no job per NAS. `ServiceSubscriptionNetworkObserver` watches `status`, `service_package_id`, `blocked_at`. New `SpeedProfileRadiusObserver` (on `Modules\Service\Models\SpeedProfile` updated) rewrites that company's speed group, no Disconnect.
6. **HTTP**: NAS controller/requests/resource per the routes table and `NasDevice` props (Index, Create/Edit with `realm`, Show with `sessions` from `RadiusDirectory::sessionsOnNas` and `radiusEnabled`); NAS delete no longer checks accounts; NAS writes per "NAS writes" (after commit; failure → `radius_synced_at` null + `last_error` + flash warning, never a 500). Account controller/requests/resource per the `NetworkAccount` props: local-part input, realm appended, global unique with a neutral message ("This username is not available."), flash key `network_account.secret`, `session` and `usage` read live (null and a notice when off or unavailable), `isolatedBecause`. `NasDeviceService`/`NetworkAccountService` keep tenant checks (`ensureSameCompany`, 404 for other companies) and secret-free audit.
7. **Seeders**: `E2eFixtureSeeder.php:1141-1154` and `Demo/NetworkAccessDemo.php:56-102` create NAS rows with `nasname`/fake `radius_secret` and realm usernames; nothing reaches RADIUS (switch off).
8. **Tests**: rewrite the owned feature tests on `InteractsWithRadius` + `InteractsWithNasCoa` (same scenarios where they still apply: desired-state rows, rename, secret change keeps the session, idempotent re-run writes nothing, drift corrected, switch off sends nothing, tenant isolation, permissions, no secret anywhere); drop RouterOS-only scenarios (adoption, unmanaged list, write flag, reachability, binary API). `BuildsNetworkFixtures` builds NAS rows and accounts for one company without routers.
9. **Docs**: `docs/business/network.md` (replace the RouterOS sections with the RADIUS model and the runbook in `../spec.md`), `docs/business/service.md:124-131` (speed group instead of router profile), `docs/ENTERPRISE_ISP_READINESS.md:7,276` (RADIUS in scope as lab pilot; RouterOS API removed), `CHANGELOG.md` (removed dependency, env keys, routes; new env keys; deploy steps from `../spec.md`).

Owned files: every path marked 04 in `../spec.md` File ownership.

Consumes: tickets 01 (`RadiusDirectory`, config), 02 (`RadiusDisconnectClient`, fakes), 03 (`blocked_at`, routes, permission).
Produces: the props contract in `../spec.md` for ticket 05.

## Acceptance criteria

- [ ] `grep -rniE "routeros|RouterOs|api_protocol|provisioning_enabled|isolir_profile|unmanaged" app Modules config routes database tests resources/js composer.json Dockerfile .env.example .env.docker.example` returns only the shipped historical migrations and `CHANGELOG.md` history (note: `resources/js` is ticket 05; list any hits there as `needs-change`).
- [ ] `composer.json`/`composer.lock` without `evilfreelancer/routeros-api-php` and `spatie/ssh`; `composer audit` output pasted.
- [ ] `php artisan migrate` then `migrate:rollback --step=3` then `migrate` succeed on `inbils_test`.
- [ ] Feature tests cover the matrix in `../spec.md` Testing Decisions for engine, sync, NAS and account HTTP; Billing and Subscription suites stay green.
- [ ] No secret in props, flash (except the one-time value), audit, logs or exception messages (assert like `NetworkAccountHttpTest::test_the_..._is_never_in_any_prop_or_audit_after_a_full_cycle` at `:372`).

## Commands

Sail runs on docker context `default`: prefix every sail call with `DOCKER_CONTEXT=default`; never `sail up`, never the desktop-linux context.

```
DOCKER_CONTEXT=default ./vendor/bin/sail composer remove evilfreelancer/routeros-api-php
DOCKER_CONTEXT=default ./vendor/bin/sail composer audit
DOCKER_CONTEXT=default ./vendor/bin/sail exec laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact tests/Feature/NetworkAsset tests/Feature/Billing tests/Feature/DemoDataSeederTest.php tests/Feature/Api/V1
```

## Comments
