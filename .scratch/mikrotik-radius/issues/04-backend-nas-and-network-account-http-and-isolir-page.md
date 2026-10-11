# BACKEND: NAS router and network account HTTP surface, public isolir page, docs

Status: done
Role: backend
Blocked by: 02, 03
Card: none (new lane; no Hermes card)

## Scope

- Routes in `Modules/NetworkAsset/routes/web.php`, exactly the table in `../spec.md` (Implementation Decisions, "Routes and permissions"): the NAS router resource plus `check` inside the existing `auth, verified, require.has.company` / `admin.` group (`Modules/NetworkAsset/routes/web.php:6`); the network account routes under `/admin/subscriptions/{subscription}/network-account`; the public `GET /isolir/{company:code}` outside the admin group with `throttle:60,1`. `reveal` uses `throttle:10,1`, `check` and `resync` use `throttle:6,1`.
- Authorisation: `Gate::authorize('<permission>')` per action plus the tenant-scoped route binding and `ensureSameCompany`, as in `app/Http/Controllers/Admin/SubscriptionController.php:66-68`; no new policy classes.
- `NasDeviceController` (index with search/sort/pagination like the other module index pages, create, store, show, edit, update, destroy, check) delegating writes to `NasDeviceService` (create, update with "blank password keeps the stored one", delete refused with 422 while any network account references the router, audit `nas_device` `created|updated|deleted` listing changed field names and `credentials_changed` instead of any password). `check` dispatches `SyncNasDeviceJob` (ticket 03) and returns with a flash message.
- `NetworkAccountController` (show, store, update, reveal, resync) delegating to `NetworkAccountService`:
  - store/update: NAS router of the current company; subscription not terminated; its speed profile has a `radius_profile_name` (422 with a clear message otherwise); username unique per company; blank password on store → generated 12-character password, on update → keep; after save, dispatch `ApplyNetworkAccountJob`; audit `created|updated|password_changed`;
  - reveal: `Inertia::flash('network_account', ['password' => …])` once (repo pattern `app/Http/Controllers/Auth/TwoFactorAuthenticationController.php:41,50`), audit `password_revealed` without the value; store with a generated password flashes it the same way;
  - resync: dispatch `ApplyNetworkAccountJob`.
- Form Requests `StoreNasDeviceRequest`, `UpdateNasDeviceRequest`, `StoreNetworkAccountRequest`, `UpdateNetworkAccountRequest` with the rules in `../spec.md` ("Validation"); the router asset must be `asset_type=router`, of the current company, not soft-deleted, not already registered, and have a `management_ip`.
- Resources `NasDeviceResource` and `NetworkAccountResource` with exactly the props contract in `../spec.md` (never a password; `has_api_password` boolean; `online` computed as specified). Show pages also receive `isolirUrl` (`route('isolir.show', <company code>)`), `installEnabled` (`config('services.routeros.enabled')`), the POP-matched `suggested` flag and `desiredState` from `NetworkAccountProvisioner::desiredState`.
- `IsolirPageController`: resolves the company by `code` (unknown → 404), renders `Isolir/Show` with only `name`, `phone`, `email`, `website` and `loginUrl` (`route('login')`).
- Docs: `docs/business/network.md` (new section: NAS routers, network accounts, desired-state table, sync, switches, permissions, and the router runbook: RouterOS user group and policies from ticket 02, `www-ssl` certificate from the NOC CA, isolir PPP profile and hotspot user profile with address list, HTTP redirect to `/isolir/{company code}`, walled garden for the Inbils host and the Midtrans hosts, onboarding order writes-off → check → adopt → writes-on); `docs/business/service.md:124-126` (replace the "SubscriptionService calls Radius API" hook with the implemented observer design); `docs/architecture/domain-boundaries.md` (NetworkAsset depends on Service in the table at `:72-81`; add `NetworkAsset ──observe──→ Customer(Core) subscription` to the direction list); `docs/ENTERPRISE_ISP_READINESS.md:7,279` (Q1 lab-pilot decision; production per router after ticket 08); `CHANGELOG.md` entry in the existing format.

Owned files:
- NEW `Modules/NetworkAsset/app/Http/Controllers/NasDeviceController.php`
- NEW `Modules/NetworkAsset/app/Http/Controllers/NetworkAccountController.php`
- NEW `Modules/NetworkAsset/app/Http/Controllers/IsolirPageController.php`
- NEW `Modules/NetworkAsset/app/Http/Requests/StoreNasDeviceRequest.php`
- NEW `Modules/NetworkAsset/app/Http/Requests/UpdateNasDeviceRequest.php`
- NEW `Modules/NetworkAsset/app/Http/Requests/StoreNetworkAccountRequest.php`
- NEW `Modules/NetworkAsset/app/Http/Requests/UpdateNetworkAccountRequest.php`
- NEW `Modules/NetworkAsset/app/Http/Resources/NasDeviceResource.php`
- NEW `Modules/NetworkAsset/app/Http/Resources/NetworkAccountResource.php`
- NEW `Modules/NetworkAsset/app/Services/NasDeviceService.php`
- NEW `Modules/NetworkAsset/app/Services/NetworkAccountService.php`
- `Modules/NetworkAsset/routes/web.php`
- `docs/business/network.md`, `docs/business/service.md`, `docs/architecture/domain-boundaries.md`, `docs/ENTERPRISE_ISP_READINESS.md`, `CHANGELOG.md`
- NEW `tests/Feature/NetworkAsset/NasDeviceHttpTest.php`
- NEW `tests/Feature/NetworkAsset/NetworkAccountHttpTest.php`
- NEW `tests/Feature/NetworkAsset/IsolirPageTest.php`

Contracts produced: route names, permissions and Inertia props for ticket 05.

Non-goals: React pages (ticket 05), provisioning logic (ticket 03), deleting network accounts, technician access from SPK pages.

## Acceptance criteria

- [ ] Every route: 403 without its permission; 404 for another company's NAS router or subscription; throttles as listed (the 11th reveal within a minute is 429).
- [ ] NAS router: store/update validation (non-router asset, foreign asset, asset without `management_ip`, already registered asset, bad port, bad isolir profile → 422); blank password on update keeps the stored value; delete with accounts → 422, without → deleted; `check` queues exactly one sync job.
- [ ] Network account: speed profile without `radius_profile_name` → 422; terminated subscription → 422; foreign NAS router → 422; duplicate username in the company → 422, the same username in another company → allowed; generated password flashed once and stored encrypted; apply job dispatched after store, update and resync.
- [ ] No password in any Inertia prop, JSON resource, audit property or log line; it appears only in the `network_account.password` flash after store or reveal; `password_revealed` is audited with the causer.
- [ ] Inertia props match the contract in `../spec.md` (`assertInertia` on every page), including `suggested` for the router whose asset location equals `serving_pop_id`, `desiredState`, `installEnabled` and `isolirUrl`.
- [ ] Isolir page: a known company code returns 200 with only the four public fields and `loginUrl`; an unknown code returns 404; no session or customer data is exposed.
- [ ] Docs and CHANGELOG updated as listed; Pint and Larastan clean on new and changed files; `tests/Feature/NetworkAsset` green.

## Commands

```
./vendor/bin/sail exec laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact tests/Feature/NetworkAsset
vendor/bin/pint --dirty --format agent
```

## Comments

### Backend agent (MikrotikBackend), 2026-10-13

- Done: routes exactly as the spec table in `Modules/NetworkAsset/routes/web.php`; `NasDeviceController`, `NetworkAccountController`, `IsolirPageController`; the four Form Requests; `NasDeviceResource`, `NetworkAccountResource`; `NasDeviceService`, `NetworkAccountService`; tests `NasDeviceHttpTest`, `NetworkAccountHttpTest`, `IsolirPageTest`; docs `docs/business/network.md` (new section incl. runbook), `docs/business/service.md`, `docs/architecture/domain-boundaries.md`, `docs/ENTERPRISE_ISP_READINESS.md`.
- needs-change (lead-owned): `CHANGELOG.md` entry; `RolePermissionSeeder` (see ticket 02).
- UI contract: the props shapes are exactly the TS types in `../spec.md` "Props contract". Notes for ticket 05: single resources are passed unwrapped; lists are `Resource::collection($paginator)` (`{data, links, meta}`); the one-time password arrives only as Inertia flash `network_account.password` on the redirect to `admin.subscriptions.network-account.show` after `store` (generated password only) and `reveal`; validation problems of the subscription (no router profile name, terminated, already has an account) come back as validation errors under the key `subscription`; a router delete refused for accounts is a validation error under `nas_device`; the router form fields are `network_asset_id` (create only), `api_port`, `api_username`, `api_password` (blank on edit keeps), `isolir_profile`, `provisioning_enabled`; the account form fields are `nas_device_id`, `service_type`, `username`, `password`.
- Pages do not exist yet, so the tests use `withoutVite()` and never call `component()`.
- Evidence: `Tests: 220 passed (1667 assertions)` (see ticket 02 comment for the command); Pint passed; Larastan: only `NasDeviceController::SORTABLE is unused`, the same accepted pattern as `SubscriptionController` (the `HasIndexQuery` trait reads it).

### Backend agent (MikrotikBackend), security review fixes, 2026-10-13

- MIKROTIK-07-001 (high): `UpdateNetworkAssetRequest::after()` refuses (422 on `management_ip`) a change of a registered NAS router's address unless the user holds `network_asset.nas.manage`.
- MIKROTIK-07-003 (medium): `bootstrap/app.php` `dontFlash(['api_password'])`.
- MIKROTIK-07-005 (low): the one-time password waits in the session sealed with `Crypt` (`network_account.sealed_password`, flash data) and `show()` unseals it into the single response; the clear value is never written to the session. UI contract unchanged (`flash.network_account.password`).
- MIKROTIK-07-007/-008 (low): the isolir page answers 404 for inactive companies; the route uses the named limiter `isolir` (600/min per IP + code; 20/min per IP for unknown or inactive codes).
- MIKROTIK-07-010 (info): APP_KEY rotation note, adoption, endpoint and session rules added to `docs/business/network.md`.

### Backend agent (MikrotikFixes), security re-review fixes, 2026-10-14

- MIKROTIK-07-005 (low, re-review): the sealed flash still decrypted into `Inertia::flash` before Inertia's middleware checked the asset version; a GET with a stale `X-Inertia-Version` then made `onVersionChange` reflash the clear password (`session()->now()` key promoted by `reflash()`) into the stored session. `NetworkAccountController::show` now skips the unseal on exactly the request the middleware turns into a 409 (X-Inertia header, GET, version differs). Only the sealed value is reflashed; the next valid load shows the password once. UI contract unchanged (`flash.network_account.password`).
- MIKROTIK-07-011 (medium, re-review): `NetworkAccountController::resync`, `NetworkAccountService::create` and `::update` dispatch `ApplyNetworkAccountJob` with `NetworkAccountService::adoptionApproval($account)` (null without `network_asset.nas.manage`); the approval is bound to the target as saved (details in ticket 03).
- Tests: `tests/Feature/NetworkAsset/SecurityHardeningTest.php` (stale `X-Inertia-Version` after create and after reveal, sent twice: no clear password in the persisted session, then exactly one display after a valid load; delayed administrator job against a staff retarget).
- Observation, not changed (outside this lane): `app/Http/Controllers/Auth/TwoFactorAuthenticationController.php` flashes the 2FA setup key and recovery codes with `Inertia::flash` before a redirect, which Inertia's middleware reflashes into the stored session by design; with `SESSION_ENCRYPT=false` they sit in clear until the next request.
