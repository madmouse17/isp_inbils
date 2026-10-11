# BACKEND: NAS router and network account HTTP surface, public isolir page, docs

Status: ready-for-agent
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
