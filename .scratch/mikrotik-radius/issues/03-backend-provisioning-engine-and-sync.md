# BACKEND: provisioning engine (desired state, apply job, subscription observer) and the 15-minute router sync

Status: ready-for-agent
Role: backend
Blocked by: 01, 02 (01 only because both edit `routes/console.php`)
Card: none (new lane; no Hermes card)

## Scope

- `NetworkAccountProvisioner` (NetworkAsset module): `desiredState(NetworkAccount)` per the table in `../spec.md` (pending/active → enabled with the speed profile's `radius_profile_name`, read through `subscription → servicePackage → speedProfile`; suspended → the NAS router's `isolir_profile`; terminated or deleted → absent; pending follows the owner's Q11 answer) and `apply(NetworkAccount)` implementing the five-step apply algorithm in `../spec.md` (clean-up after a router or username change, adopt an existing secret by name, comment `inbils:<company code>:<subscription code>`, drop the session only when the profile changed, record `provisioned_*`, audit `provisioned` only on a real change).
- `ApplyNetworkAccountJob(int $companyId, int $accountId)`: `ShouldQueue` + `ShouldBeUniqueUntilProcessing` (unique id = account id) + `WithoutOverlapping` per account; `tries` 5, `backoff` [30, 120, 300, 900], `timeout` 60 (below `retry_after` 90, `config/queue.php:43`, and the worker's `--timeout=90`, `compose.yaml:42`); runs everything inside `CompanyService::runAs($companyId)`; skips with `sync_status=pending` and a reason when the installation switch is off, the write flag is off, or `unreachable_since` is less than 2 minutes old; `unreachable`, `tls` and `server` errors are retried (a connection failure also sets the NAS router's `unreachable_since`); `auth` and `rejected` call `$this->fail()`; `failed()` stores `sync_status=failed`, `last_error`, `last_attempt_at` and audits `provision_failed`. Follow `Modules/Ticketing/app/Jobs/CheckTicketSlaBreachesJob.php:19-36` for structure.
- `ServiceSubscriptionNetworkObserver`, registered through `$observers` in the module's `EventServiceProvider`, implements `ShouldHandleEventsAfterCommit`: on `updated` with `status` or `service_package_id` changed, and on `deleted`, it dispatches the apply job when the subscription has a network account (looked up for the subscription's own company). It makes no router call. This also covers the package change in `app/Http/Controllers/Admin/SubscriptionController.php:89`, which bypasses the service.
- `SyncNasDeviceJob(int $companyId, int $nasDeviceId)`: probe (`systemResource`) → `routeros_version`, `last_checked_at`, `last_seen_at`, set or clear `unreachable_since`, audit `unreachable`/`recovered` and send `NasDeviceUnreachableNotification` on the reachable → unreachable transition only. When reachable: `listAccounts` + `activeSessions` per service type in use; bulk-update `last_online_at`/`current_address` (online) and clear `current_address` (offline); queue the apply job for accounts that are `pending`, drifted (missing on the router, other profile, disabled) or `failed` with `last_attempt_at` older than one hour (audit `drift_corrected` when the apply fixes drift); store `unmanaged_count` and the first 100 unmanaged names (no matching account and no `inbils:` comment). Reads only when the write flag is off; nothing at all when the installation switch is off.
- Command `network:sync {--company=} {--nas=}` (`SyncNetworkDevices`): active companies, `runAs`, one `SyncNasDeviceJob` per NAS router (only the given one with `--nas`), like `Modules/Ticketing/app/Console/CheckTicketSla.php:27-39`; registered in `NetworkAssetServiceProvider::$commands`; scheduled `Schedule::command('network:sync')->everyFifteenMinutes()->withoutOverlapping();` in `routes/console.php` after ticket 01's entry.
- `NasDeviceUnreachableNotification` (mail + database, like `Modules/Billing/app/Notifications/BillingScheduleFailedNotification.php:21-24`) to users who hold `network_asset.nas.manage` and can act in the router's company (`CompanyService::canActIn`); the text names the router asset code and the last error kind, never credentials.
- `Modules/NetworkAsset/module.json`: `"dependencies": ["Service"]` (the provisioner reads the package's speed profile).

Owned files:
- NEW `Modules/NetworkAsset/app/Services/NetworkAccountProvisioner.php`
- NEW `Modules/NetworkAsset/app/Jobs/ApplyNetworkAccountJob.php`
- NEW `Modules/NetworkAsset/app/Jobs/SyncNasDeviceJob.php`
- NEW `Modules/NetworkAsset/app/Console/SyncNetworkDevices.php`
- NEW `Modules/NetworkAsset/app/Observers/ServiceSubscriptionNetworkObserver.php`
- NEW `Modules/NetworkAsset/app/Notifications/NasDeviceUnreachableNotification.php`
- `Modules/NetworkAsset/app/Providers/EventServiceProvider.php`
- `Modules/NetworkAsset/app/Providers/NetworkAssetServiceProvider.php`
- `Modules/NetworkAsset/module.json`
- `routes/console.php` (second editor, after ticket 01)
- NEW `tests/Feature/NetworkAsset/ProvisioningFlowTest.php`
- NEW `tests/Feature/NetworkAsset/NetworkSyncTest.php`

Contracts consumed: models, client and fake router from ticket 02; `suspension_source` from ticket 01 (not needed for the desired state). Contracts produced: the two job classes and their constructor signatures (ticket 04 dispatches them for "check now" and "resync").

Non-goals: controllers, routes, account CRUD service, isolir page (ticket 04); static IP (`ipam-odn` follow-up); RADIUS.

## Acceptance criteria

- [ ] Flow tests through the highest seam (subscription service or route, `sync` queue, fake router): account on an active subscription → secret created with the speed profile and the `inbils:` comment; pending subscription → enabled (Q11b) or absent (Q11a); suspend → isolir profile + session dropped; reactivate → speed profile + session dropped; terminate → secret removed + session dropped; package change through the subscription update route → new profile + session dropped; NAS router change → removed on the old router, created on the new; username change → old name removed; password change → password updated, session kept; an existing secret with the same name is adopted, not duplicated.
- [ ] Idempotency: running the job twice in a row produces zero router writes the second time and no second `provisioned` audit entry.
- [ ] Switches: installation switch off → zero requests, account `pending` with the reason; write flag off → zero writes, sync still reads.
- [ ] Failures: 400 with a RouterOS message → `failed` at once with that message and no retry; connection refused → retried, `unreachable_since` set, other jobs for that router skip the call for 2 minutes; `failed()` stores the status and audits without a password or response body.
- [ ] Sync: probe fields updated; online and offline accounts updated in bulk; drift corrected and audited; unmanaged list capped at 100; one notification per outage (none on the second failed probe), `recovered` audited; recovery re-queues pending accounts; a `failed` account is re-queued at most once per hour.
- [ ] Tenant: a job built for company A never reads or writes company B rows; the observer resolves the account in the subscription's own company.
- [ ] Pint and Larastan clean on new and changed files; `tests/Feature/NetworkAsset` and `tests/Feature/SubscriptionServiceTest.php` green.

## Commands

```
./vendor/bin/sail exec laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact tests/Feature/NetworkAsset tests/Feature/SubscriptionServiceTest.php tests/Feature/CustomerStatusRollupTest.php
vendor/bin/pint --dirty --format agent
```

## Comments
