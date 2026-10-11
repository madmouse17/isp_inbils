# BACKEND: provisioning engine (desired state, apply job, subscription observer) and the 15-minute router sync

Status: done
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

### Backend agent (MikrotikBackend), 2026-10-13

- Done: `NetworkAccountProvisioner` (desired state, apply, skip rules), `NasDeviceHealth` (one audited + notified transition per outage, shared by apply and sync), `ApplyNetworkAccountJob`, `SyncNasDeviceJob`, `network:sync`, `ServiceSubscriptionNetworkObserver` (registered through `$observers`), `NasDeviceUnreachableNotification`, `module.json` dependency `Service`, schedule `network:sync` every 15 minutes in `routes/console.php`.
- Differences from the ticket text: (1) the observer reads `wasChanged()` while the event fires and queues the job with `DB::afterCommit` (`ApplyNetworkAccountJob::dispatchAfterCommit`) instead of `ShouldHandleEventsAfterCommit`, because after commit the model may already have been saved again and `wasChanged()` would lie (covered by a test); (2) retryable router errors `release()` with the backoff instead of throwing, so a sync-queue environment never turns a down router into an exception for the caller; (3) `unreachable_since` is the start of the outage and `last_checked_at` the last attempt: jobs skip a router for 2 minutes after its last failed attempt; (4) a secret is deleted for an absent account only when Inbils manages it (`provisioned_profile` set or comment `inbils:`), never a stray one with the same name.
- Evidence: `tests/Feature/NetworkAsset/ProvisioningFlowTest.php` + `NetworkSyncTest.php` (flow matrix, idempotency, switches, failures, outage notification once, drift, unmanaged cap 100, hourly re-queue, tenant isolation) are part of `Tests: 220 passed (1667 assertions)` and of the wider run `tests/Feature/NetworkAsset tests/Feature/Billing tests/Feature/SubscriptionServiceTest.php tests/Feature/CustomerStatusRollupTest.php` → `502 passed, 2 failed` (the 2 failures were wrong assertions in the ticket 04 HTTP tests, fixed and re-run green). Pint passed; Larastan clean.
- Scale limit: `SyncNasDeviceJob` loads every account of one router at once (fine for a pilot; chunk it beyond a few thousand accounts per router).

### Backend agent (MikrotikBackend), security review fixes, 2026-10-13

- MIKROTIK-07-004 (high): the session drop follows the recorded state, and `provisioned_*` advances only after the drop succeeded (`NetworkAccountProvisioner::apply`), so a failure between the router write and the drop is finished by the retry (isolate, terminate, package change; pppoe and hotspot).
- MIKROTIK-07-006 (medium): `RouterOsClient::find/listAccounts` return `service` (PPPoE `.proplist` gains `,service`); adopting a secret without our `inbils:` comment refuses non-PPPoE services, narrows `any` to `pppoe`, and needs `ApplyNetworkAccountJob($company, $account, mayAdopt: true)` which only an HTTP save or resync by a user holding `network_asset.nas.manage` sets; otherwise the account stays `pending` ("An administrator ... adopts it with Resync") and the router is untouched.
- MIKROTIK-07-001 (high): `NetworkAssetEndpointObserver` switches the NAS router's write flag off and audits `nas_device` `endpoint_changed` whenever its asset's `management_ip` changes.
- Tests: `tests/Feature/NetworkAsset/SecurityHardeningTest.php`; `tests/Support/FakeRouterOs.php` gained `downOn([path prefixes])`.

### Backend agent (MikrotikFixes), security re-review fixes, 2026-10-14

- MIKROTIK-07-004 (high, re-review: drift correction, first adoption and DELETE of an unrecorded secret still lost the drop): `network_accounts.pending_disconnect_at` (NEW migration `2026_10_14_100000_add_pending_disconnect_at_to_network_accounts_table`; not fillable, in no resource or props) is the pending-disconnect intent. `NetworkAccountProvisioner::apply` decides before any router write whether a session drop is owed (secret removed, profile change, record differs, first contact into isolir, or the marker is already set) and persists the marker first; the marker is cleared in the same save that advances `provisioned_*`, after every drop succeeded. A retry therefore finishes the drop when recorded, desired and router profiles already match, when `provisioned_profile` is null, and after the DELETE of an `inbils:` secret nothing recorded. `cleanUp` (router, username or type change) clears it because its own drop settles the old router. `SyncNasDeviceJob::needsApply` re-queues an account carrying the marker first, whatever its status (also a `failed` one inside the hourly wait).
- MIKROTIK-07-011 (medium, re-review: a queued administrator approval was reused for a retargeted account): `ApplyNetworkAccountJob` takes `?string $adoptionApproval` instead of `bool $mayAdopt`: an HMAC (APP_KEY) of router id, service type, username and password (`ApplyNetworkAccountJob::adoptionApprovalFor`), issued by `NetworkAccountService::adoptionApproval` only to a user holding `network_asset.nas.manage` (create, update, resync; `actorMayAdopt()` is gone). `handle()` recomputes it from the account as it is when the job runs: equal means adopt, otherwise the run is a plain one (account `pending`, "administrator adopts it with Resync", router untouched). The unique id carries the hash, so an approval for a new target queues next to the stale one. Payload: ids and the opaque hash. After an APP_KEY rotation a queued approval no longer matches (Resync again).
- Tests (`tests/Feature/NetworkAsset/SecurityHardeningTest.php`, red first): failure after PATCH for isolated drift correction and for first adoption, failure after DELETE of an unrecorded secret (pppoe and hotspot each); the sync re-queues a `failed` account that owes a drop; moving an account settles the debt on the old router; a delayed administrator job against a staff retarget of username, router, service type and password leaves every unmanaged secret untouched; unique id and payload.
- Known limit: the marker has no target. An account that was never recorded and is retargeted while a drop is owed does not get the old target's sessions dropped (a recorded one does, through `cleanUp`). Upgrade to a target-bearing marker if that case matters.
