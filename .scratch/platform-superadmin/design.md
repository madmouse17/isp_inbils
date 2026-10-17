# Platform Superadmin Implementation Design

## 1. Problem statement

Inbils currently has one identity plane: tenant `App\Models\User` accounts on guard `web`. Tenant data fails closed through `CompanyService` and `BelongsToCompany`, but no operator identity may safely inspect unrelated ISP tenants. Global role definitions are also writable by top-level tenants even though `spatie/laravel-permission` uses `teams=false`.

Build an operator panel under `/platform` with a separate identity table, provider and session guard. Platform admins never become tenant users, never enter `company_user`, and never receive tenant roles. Platform access requires the platform password, mandatory TOTP 2FA, proof that the current session passed 2FA, and a 30-minute idle timeout.

The panel manages top-level tenants and subsidiaries, provisions a tenant and first admin, owns global role definitions, suspends/reactivates tenants, requests deletion, supports tenant users, exports tenant data, and keeps `activity_log` rows under `log_name=platform`.

Impersonation keeps both guards in one server-side Laravel session. `platform` is the real identity; `web` is the tenant identity whose policies and permissions run. Every impersonation starts read-only, shows a persistent banner, has a hard 30-minute limit, and records `PlatformAdmin` as the activity-log causer. Write mode is explicit and requires platform step-up plus the tenant code.

No IP allowlist is added. SaaS plans/billing, per-tenant role definitions, mobile impersonation, and public IDs remain outside this lane. Existing suspension enforcement is reused: `LoginRequest::validateCredentials`, `OpensSession`, `ResolveActiveCompany`, `RequireHasCompany`, `EnsureApiUserIsActive`, and the `Company::updated` token-revocation hook remain authoritative.

### Selected architecture

- Separate `platform_admins` identity, `platform_admins` provider, and `platform` session guard.
- Dedicated `platform` middleware group, so platform pages never execute tenant-only company-resolution or tenant Inertia sharing.
- `SetupWizardService::createFor(User $admin, array $data)` is the shared company-creation seam used by `/setup` and `ProvisionTenantAction`.
- `PlatformAuditService` exposes platform-only and tenant-visible support logging while delegating persistence to existing `AuditService`.
- `ImpersonationService` is the only writer of impersonation session keys; `EnforceImpersonation` is the only tenant-web middleware that interprets them.
- Trial sample data uses a company-explicit queued seeding path; it never reuses known DEMO credentials or creates RADIUS/WhatsApp-sendable trial data.
- Tenant exports run on a dedicated long-running `platform` queue and remain on the private `local` disk.

## 2. Affected modules

| Area | Existing paths | New paths / responsibility |
|---|---|---|
| Platform identity/auth | `config/auth.php`, `app/Services/Core/TwoFactorService.php`, `app/Http/Requests/Auth/TwoFactorChallengeRequest.php`, `bootstrap/app.php`, `bootstrap/providers.php` | `NEW: app/Models/PlatformAdmin.php`, factory/migration, `PlatformServiceProvider`, platform auth controllers/requests/middleware, create-admin command, `routes/platform.php`, `routes/platform/auth.php` |
| Route/Inertia shell | `resources/views/app.blade.php` | `NEW: config/platform.php`, `config/ziggy.php`, `HandlePlatformInertiaRequests.php` |
| Tenant management | `Company.php`, `SetupWizardService.php`, hierarchy/membership services, `CompanySeeder.php` | lifecycle migration, `ProvisionTenantAction`, platform tenant queries/controllers/requests/resources, invitation notification |
| Trial data | `DemoDataSeeder.php`, `DemoContext.php`, `FoundationDemo.php`, `CustomerDemo.php`, `WhatsAppDemo.php` | company-explicit safe `seedFor`, `SeedTenantDemoDataJob` |
| Lifecycle/purge | existing tenant migrations/tables | `TenantLifecycleService`, `TenantTableRegistry`, `TenantPurger`, purge command |
| Roles | `RolePolicy.php`, tenant `RoleController`, role requests/routes/pages | platform role controller/requests/pages; tenant role pages become read-only |
| Impersonation/audit | `AuditService.php`, tenant Inertia middleware, Reporting audit query/resource/exporter, dashboard activity query | `ImpersonationService`, `EnforceImpersonation`, controller/requests, banner |
| Support/export | login/2FA limiter keys, filesystem/queue/scheduler/worker config | `TenantExport` model/migration, export builder/job, support/export/audit controllers/queries/resources, cleanup command |
| Platform UI | current UI/composite primitives, toast/theme hooks | `PlatformLayout`, `types/platform.ts`, `Pages/Platform/**` |
| Tenant UI cutover | `AdminLayout`, `GuestLayout`, shared types, audit and role pages, user guide/screenshots | banner, support labels, read-only roles |
| Docs/release | `AGENTS.md`, `docs/SECURITY.md`, `.gitignore`, changelog, parking lot | `ADR-003-platform-superadmin.md` |

## 3. Data model / migrations

### 3.1 `platform_admins`

`NEW: database/migrations/*_create_platform_admins_table.php`

```text
id                         BIGINT UNSIGNED PK
name                       VARCHAR(255)
email                      VARCHAR(255) UNIQUE
password                   VARCHAR(255)
two_factor_secret          TEXT NULL
two_factor_recovery_codes  TEXT NULL
two_factor_confirmed_at    TIMESTAMP NULL
two_factor_last_used_step  BIGINT UNSIGNED NULL
is_active                  BOOLEAN NOT NULL DEFAULT TRUE
last_login_at              TIMESTAMP NULL
created_at / updated_at    TIMESTAMPS
index(is_active)
```

`down()` drops the table. `PlatformAdmin` extends Laravel `Authenticatable`, uses `HasFactory`, and does not use tenant roles, memberships, Sanctum tokens or remember-me. `$fillable = ['name', 'email', 'password']`. Hidden fields are password and all `two_factor_*` values. Casts mirror `User`: password `hashed`, secret `encrypted`, recovery codes `encrypted:array`, timestamps `datetime`, last-used step `integer`, active `boolean`.

### 3.2 Company lifecycle

`NEW: database/migrations/*_add_platform_lifecycle_columns_to_companies_table.php`

```text
companies.suspended_at           TIMESTAMP NULL INDEX
companies.deletion_requested_at  TIMESTAMP NULL INDEX
```

`down()` removes both columns and indexes. Neither column is fillable; `Company::$casts` gains both datetimes.

`suspended_at` is required to prove the 30-day deletion gate and to reactivate only rows that platform suspension deactivated. Tenant lifecycle always targets a top-level company plus direct children. Subsidiary targets return 404.

### 3.3 `tenant_exports`

`NEW: database/migrations/*_create_tenant_exports_table.php`

```text
id                               BIGINT UNSIGNED PK
uuid                             CHAR(36) UNIQUE
company_id                       FK companies.id RESTRICT ON DELETE
requested_by_platform_admin_id   FK platform_admins.id RESTRICT ON DELETE
status                           VARCHAR(20) NOT NULL DEFAULT 'queued'
file_path                        VARCHAR(1024) NULL
file_size                        BIGINT UNSIGNED NULL
row_counts                       JSON NULL
error_message                    TEXT NULL
started_at                       TIMESTAMP NULL
completed_at                     TIMESTAMP NULL
expires_at                       TIMESTAMP NULL
created_at / updated_at          TIMESTAMPS
index(company_id, created_at)
index(status, expires_at)
```

`App\Models\Platform\TenantExport` statuses: `queued`, `running`, `ready`, `failed`, `expired`. It does not use `BelongsToCompany`; it is platform metadata and is accessed only behind `auth:platform` or inside platform jobs/commands. `down()` drops this table before `platform_admins`.

### 3.4 Activity log

No activity migration is needed. Existing `activity_log` already has polymorphic causer, nullable `company_id`, `log_name`, `batch_uuid`, and suitable indexes.

- Internal operator events: `log_name=platform`.
- Tenant-visible support events: `log_name=support`.
- During impersonation, `CauserResolver::setCauser($platformAdmin)` records the real actor and `LogBatch::setBatch($impersonationUuid)` correlates the session.
- Tenant readers exclude `platform`; platform audit reads `platform` and `support`, and may include other rows caused by `PlatformAdmin` when filtering one impersonation batch.

No impersonation table is added. The server-side session plus start/end activity rows are the source of truth. Start rows include `expires_at`; a closed browser therefore still has a provable maximum duration.

## 4. API contracts

### 4.1 Guard/provider configuration

`config/auth.php` adds:

```php
'guards' => [
    'web' => ['driver' => 'session', 'provider' => 'users'],
    'platform' => ['driver' => 'session', 'provider' => 'platform_admins'],
],
'providers' => [
    'users' => ['driver' => 'eloquent', 'model' => App\Models\User::class],
    'platform_admins' => ['driver' => 'eloquent', 'model' => App\Models\PlatformAdmin::class],
],
```

No platform password broker is added. Lost platform credentials use controlled server-console recovery documented in Security §5.

`NEW: config/platform.php` contains only:

```php
return ['domain' => env('PLATFORM_DOMAIN')];
```

If configured, platform routes bind to that host. `bootstrap/app.php` retains APP_URL subdomains, localhost and loopback, and lazily adds the escaped platform host to trusted hosts. Default remains same-host `/platform`.

### 4.2 Platform route and middleware groups

`PlatformServiceProvider` registers a dedicated `platform` group mirroring Laravel's base web stack:

```text
EncryptCookies
AddQueuedCookiesToResponse
StartSession
ShareErrorsFromSession
ValidateCsrfToken
SubstituteBindings
HandlePlatformInertiaRequests
AddLinkHeadersForPreloadedAssets
```

Global `AssignRequestId` still applies. Platform routes do not execute `ResolveActiveCompany`, `RedirectIfNoCompany`, or tenant `HandleInertiaRequests`.

Authenticated platform routes use:

```text
auth:platform
platform.account   // is_active + 30-minute idle timeout
platform.2fa       // account enrolled and current session passed/enrolled 2FA
```

First-login enrollment routes use `auth:platform, platform.account` but omit `platform.2fa`. Guest login/challenge routes use `guest:platform`.

Session keys:

```text
platform.last_activity_at        unix timestamp
platform.two_factor_verified_at  unix timestamp set only by this session's challenge/enrolment
platform.auth.password_confirmed_at unix timestamp set by platform password confirmation
```

`platform.account` logs out inactive accounts. If idle time exceeds 1800 seconds, it records `platform auth.idle_timeout`, ends any active impersonation, logs out platform, invalidates the session, regenerates CSRF and redirects to `platform.login`. Accepted requests refresh the activity timestamp.

`platform.2fa` redirects unenrolled admins to setup. An enrolled account without `platform.two_factor_verified_at` is logged out and returned to login, so enrollment in another session never unlocks a stale session.

`routes/platform.php` applies optional domain, `/platform` prefix, `platform.` name prefix, and the `platform` middleware group. It loads sorted `routes/platform/*.php`; later tickets add owned files without editing the route shell or creating placeholders.

`bootstrap/app.php` guest/user redirects are route-aware: platform guests go to `platform.login`, authenticated platform guests go to `platform.dashboard`; tenant behavior remains unchanged.

### 4.3 Platform password confirmation

Laravel's default `password.confirm` is unsafe across these two guards because it always uses `auth.password_confirmed_at`. Platform gets its own controller and middleware:

- alias: `platform.password.confirm`;
- middleware checks `platform.auth.password_confirmed_at`, timeout 300 seconds, and redirects to `platform.password.confirm`;
- controller validates against `Auth::guard('platform')`;
- throttle key `platform-password-confirm|{admin_id}|{ip}`, 5 attempts/60 seconds, counted before validation and cleared on success;
- wrong password error: `Kata sandi tidak sesuai.`.

The UI proactively submits the password confirmation from the dangerous-action dialog, then submits the action. Sensitive endpoints also carry `platform.password.confirm` as server-side backstop. R4 endpoints separately require `confirmation_code` exactly equal to the root company's code, case-sensitive.

Coverage:

- recent platform confirmation: impersonation start, tenant-user 2FA reset, role store/update/delete;
- recent confirmation + exact company code: suspend, deletion request, export request, enable impersonation write mode;
- no confirmation: stop/downgrade impersonation, reactivate, invitation resend, throttle unlock.

### 4.4 Login and challenge throttles

`PlatformLoginRequest` normalizes email with `Str::transliterate(Str::lower(...))`:

```text
platform-login|{email}|{ip}: 5 failures / 60 seconds
platform-login-email|{email}: 20 failures / 3600 seconds
```

Both are checked before credential validation; failures hit both, success clears both. Inactive admins fail with `auth.failed`. Lockout dispatches Laravel `Lockout` without revealing existence/status.

Platform TOTP keys avoid collision with tenant `User` IDs:

```text
platform-two-factor|{admin_id}|{ip}: 5 / 60 seconds
platform-two-factor-admin|{admin_id}: 10 / 900 seconds
```

### 4.5 Generic `TwoFactorService`

Public account parameters become `User|PlatformAdmin` for status, enrollment, confirmation, challenge verification, recovery-code regeneration, pending challenge and abandonment.

- Lock via `$account->newQuery()->lockForUpdate()` instead of `User::query()`.
- Pending session key remains `login.two_factor` for `User`; platform uses `platform.login.two_factor`.
- Platform authenticator issuer is `config('app.name').' Platform'`.
- Tenant `User` retains remember-token rotation, API token revocation and DB session cleanup. Platform enrollment/regeneration only regenerates the current session because `sessions.user_id` cannot distinguish model types.
- `disable(PlatformAdmin)` aborts 403; no platform disable route exists.
- `adminReset(User|PlatformAdmin $actor, User $target)` records `actor_id` and `actor_type`.
- Platform-admin 2FA events log under `platform` with `platform_admin_id`; tenant users retain `security`/`user_id`.

### 4.6 Route table

All names below are exact. Numeric route keys remain until the later `opaque-ids` lane.

#### Auth (`routes/platform/auth.php`)

| Method | URI | Name | Middleware | Controller / page |
|---|---|---|---|---|
| GET | `/platform/login` | `platform.login` | guest platform | `AuthenticatedSessionController@create` → `Platform/Auth/Login` |
| POST | `/platform/login` | `platform.login.store` | guest platform | `...@store` |
| GET | `/platform/two-factor-challenge` | `platform.two-factor.login` | guest platform | `TwoFactorChallengeController@create` → `Platform/Auth/TwoFactorChallenge` |
| POST | `/platform/two-factor-challenge` | `platform.two-factor.login.store` | guest platform | `...@store` |
| GET | `/platform/two-factor/setup` | `platform.two-factor.setup` | auth, account | `TwoFactorEnrollmentController@show` → `Platform/Auth/TwoFactorSetup` |
| POST | `/platform/two-factor/setup` | `platform.two-factor.setup.store` | auth, account | start enrollment, flash QR/key |
| POST | `/platform/two-factor/confirm` | `platform.two-factor.confirm` | auth, account | confirm, flash recovery codes, set session marker |
| POST | `/platform/two-factor/recovery-codes` | `platform.two-factor.recovery-codes` | full stack + platform confirm | regenerate codes |
| GET | `/platform/confirm-password` | `platform.password.confirm` | full stack | `ConfirmablePasswordController@show` → `Platform/Auth/ConfirmPassword` |
| POST | `/platform/confirm-password` | `platform.password.confirm.store` | full stack | `...@store` |
| POST | `/platform/logout` | `platform.logout` | full stack | session destroy |

#### Tenants and roles

| Method | URI | Name | Page / behavior |
|---|---|---|---|
| GET | `/platform` | `platform.dashboard` | `Platform/Dashboard`, tenant metrics |
| GET | `/platform/companies/create` | `platform.companies.create` | `Platform/Companies/Create` |
| POST | `/platform/companies` | `platform.companies.store` | `ProvisionTenantAction` |
| GET | `/platform/companies/{company}` | `platform.companies.show` | `Platform/Companies/Show`, root only |
| POST | `/platform/companies/{company}/subsidiaries` | `platform.companies.subsidiaries.store` | reuse `CompanyHierarchyService::createSubsidiary`; optional validated member IDs |
| POST | `/platform/companies/{company}/suspend` | `platform.companies.suspend` | confirm + exact code + reason 5-200 |
| POST | `/platform/companies/{company}/reactivate` | `platform.companies.reactivate` | reason 5-200; clear deletion request |
| POST | `/platform/companies/{company}/deletion-request` | `platform.companies.deletion-request.store` | confirm + exact code; 30 days + post-suspension export |
| GET | `/platform/roles` | `platform.roles.index` | `Platform/Roles/Index` |
| GET | `/platform/roles/create` | `platform.roles.create` | `Platform/Roles/Create` |
| POST | `/platform/roles` | `platform.roles.store` | confirmation; global `web` role |
| GET | `/platform/roles/{role}/edit` | `platform.roles.edit` | `Platform/Roles/Edit` |
| PUT | `/platform/roles/{role}` | `platform.roles.update` | confirmation; protected names immutable |
| DELETE | `/platform/roles/{role}` | `platform.roles.destroy` | confirmation; protected roles undeletable |

#### Impersonation

| Method | URI | Name | Behavior |
|---|---|---|---|
| POST | `/platform/companies/{company}/users/{user}/impersonate` | `platform.impersonation.start` | confirmation + reason 5-200; read mode |
| POST | `/platform/impersonation/write` | `platform.impersonation.write` | confirmation + exact root code |
| POST | `/platform/impersonation/read` | `platform.impersonation.read` | privilege downgrade |
| POST | `/platform/impersonation/stop` | `platform.impersonation.stop` | always allowed while platform session valid |

Start/stop cross Inertia shells with `Inertia::location()` so the browser reloads the correct Ziggy route group.

#### Support, export and audit

| Method | URI | Name | Behavior |
|---|---|---|---|
| POST | `/platform/companies/{company}/users/{user}/two-factor/reset` | `platform.companies.users.two-factor.reset` | confirmation; membership checked |
| POST | `/platform/companies/{company}/users/{user}/invitation` | `platform.companies.users.invitation.store` | throttle 6/min per target; queued mail |
| POST | `/platform/companies/{company}/users/{user}/unlock` | `platform.companies.users.unlock` | clear `two-factor-user|{id}` |
| POST | `/platform/companies/{company}/exports` | `platform.companies.exports.store` | confirmation + exact code; one active export |
| GET | `/platform/exports` | `platform.exports.index` | `Platform/Exports/Index` |
| GET | `/platform/exports/{tenantExport}/download` | `platform.exports.download` | full platform stack + signed + record/file checks |
| GET | `/platform/audit` | `platform.audit.index` | `Platform/Audit/Index` |

### 4.7 Impersonation session contract

`ImpersonationService` is the only writer. Exact keys:

```text
impersonator_platform_admin_id int
impersonation_user_id          int
impersonation_company_id       int root tenant id
impersonation_id               UUID activity batch
impersonation_reason           5-200 character support reference
impersonation_started_at       unix timestamp
impersonation_mode             'read' | 'write'
```

Start:

1. Refuse nested impersonation or an unrelated existing `web` login with 409.
2. Validate root tenant, target membership within root/children, user active, tenant active, and `CompanyService::canEnter($target)`.
3. Clear `active_company_id`, tenant `auth.password_confirmed_at`, tenant pending 2FA and `url.intended`.
4. Suppress `Login` event while `Auth::guard('web')->login($target, false)` runs. SessionGuard still regenerates the session ID, but `last_login_at` remains the real login time.
5. Store keys, open activity batch, write `platform impersonation.started` and tenant `support impersonation.started` rows.
6. Full-load tenant dashboard; existing app-only redirect remains authoritative.

Every tenant web request:

- `EnforceImpersonation` sits immediately before `ResolveActiveCompany` in the tenant web append list.
- Verify platform guard ID/active state/session 2FA, web user ID/active state, tenant active state, and elapsed time `< 1800` seconds.
- Refresh `platform.last_activity_at`.
- Set Spatie causer to `PlatformAdmin` and batch to `impersonation_id`; reset both in `finally`.
- Always forbidden in both modes: `profile.*`, `password.*`, `two-factor.*`, `verification.*`; self-target `admin.users.update|destroy|two-factor.reset`; tenant user create/update with a nonblank login `password`. API routes are bearer-only and unaffected.
- Read mode permits GET/HEAD plus `admin.company.switch`; normal tenant logout means stop impersonation.
- Blocked Inertia mutation: 303 back with flash error. JSON/plain: 403.
- Read-only copy: `Mode baca saja: perubahan tidak diizinkan selama akses dukungan. Aktifkan mode ubah dari banner untuk melanjutkan.`
- Forbidden-security copy: `Pengaturan akun pribadi tidak tersedia selama akses dukungan.`
- Write mode logs every non-GET/HEAD attempt to both `platform` and `support`: route, method, path, status, target user, reason and batch UUID. Never log request data.

Stop/expiry:

1. Compute duration before clearing state.
2. Write platform/support end rows with reason: `manual`, `logout`, `expired`, `tenant_suspended`, `platform_session_ended`, or `target_inactive`.
3. `Auth::guard('web')->logoutCurrentDevice()` so the real user's remember token is not rotated.
4. Forget all keys plus tenant company/confirmation/intended state; regenerate session ID and CSRF.
5. Full-load root company page, or platform login when platform session ended.

### 4.8 Audit contract

`NEW: PlatformAuditService`:

```php
public function platform(string $action, array $properties = [], ?Model $subject = null): ActivityContract;
public function support(string $action, array $properties, Model $tenantSubject): ActivityContract;
```

- `platform()` writes `log_name=platform`; tenant readers exclude it.
- `support()` writes `log_name=support`; properties never contain platform email/IP/user-agent, secrets, tokens, request bodies or stack traces.
- HTTP platform actions use current platform guard causer.
- Impersonated tenant actions use middleware causer override.
- Queued jobs set stored requesting admin as causer only while logging, then reset in `finally`; cleanup is system-caused.
- Platform-only events without tenant subject keep `company_id=null`.
- Tenant actions pass a server-authoritative `Company`, `User`, or `TenantExport` subject.

Tenant `AuditLogQuery` and `DashboardAdminStatsQuery` exclude `platform`. `AuditLogResource`, `AuditLogQuery::execute`, and `ReportExporter` present `PlatformAdmin` causers as `Tim dukungan Inbils` without raw platform ID/email. UI maps `support` to `Akses dukungan`.

### 4.9 Existing suspension enforcement reused

`TenantLifecycleService::suspend()` locks root+children and saves each model rather than issuing a bulk update. Existing `Company::updated` therefore drops memoized company resolution and calls `ApiTokenService::revokeForSuspendedCompany`.

Existing trust boundaries remain:

- login: `LoginRequest::validateCredentials` checks `CompanyService::canEnter` after valid credentials;
- password/2FA race: `OpensSession` rechecks `canEnter`;
- every web request: `ResolveActiveCompany` falls back or signs out; `RequireHasCompany` remains route boundary;
- API: `EnsureApiUserIsActive` returns 403 `company_inactive`; company update revokes tokens;
- scheduled jobs already enumerate active companies.

Ticket 03 adds only platform lifecycle writes, timestamps/reasons/audit and tests.

### 4.10 Provisioning

`SetupWizardService` gains:

```php
public function createFor(User $admin, array $data): Company;
public function create(array $data): Company; // delegates with Auth::user()
```

`createFor` preserves wizard order: create Company without automatic events, store logo, assign `admin`, home user, grant default membership, audit, reset cache, run `CompanySeeder::runFor`, dispatch `CompanyCreated`. Admin exists and is homed before seeding, so no `system-seed-*` fallback. `/setup` remains one-shot.

`ProvisionTenantAction::execute(PlatformAdmin $actor, array $validated): Company` creates the first `User` with random 32-character password, verified email and active status, calls `createFor`, queues invitation after commit, dispatches sample-data job when selected, and writes platform audit.

`TenantAdminInvitation` is mail-only, queued after commit, Indonesian, and mirrors `CustomerAccountCreated`: mint `Password::broker()->createToken($notifiable)` at send time and link to existing `password.reset`. Copy states 60-minute validity and resend fallback.

`StoreTenantRequest`:

```text
name required string max:255
code required string max:50 regex /^[A-Za-z0-9][A-Za-z0-9_-]*$/ unique companies.code
logo nullable; same image/MIME/max 10 MiB rules as StoreCompanySetupRequest
timezone required in DateTimeZone::listIdentifiers()
currency required in IDR,USD,SGD,EUR
admin_name required string max:255
admin_email required email max:255 unique users.email
demo_data boolean
```

Tenant company code becomes read-only: remove it from `UpdateCompanyProfileRequest` and render it read-only in tenant Company Profile. Only platform creation establishes it, so R3's “tenants never probe codes” premise is true.

### 4.11 Trial sample data

Existing DEMO seeders are unsafe for arbitrary production tenants: fixed global emails collide, known demo passwords are loginable, factory-only alternatives need dev Faker, `NetworkAccessDemo` can reach live RADIUS, and WhatsApp consent can later send to realistic fake numbers.

`DemoDataSeeder` keeps current `run()` for code `DEMO` and adds:

```php
public function seedFor(Company $company, User $admin): void;
```

For non-DEMO tenants:

- company-qualified reserved `.test` emails and random undisclosed passwords;
- supplied real admin is the `admin` actor where required;
- customer emails and WhatsApp provider IDs include company identity;
- skip `NetworkAccessDemo`; create no `NasDevice`, `NetworkAccount`, RADIUS secret or provisionable credential;
- skip WhatsApp consent/message lane;
- retain deterministic customer, billing, SPK, ticket, inventory, evaluation and safe IPAM/ODN history;
- queue/mail/Carbon/Auth overrides live in `try/finally` and are restored;
- refuse nonempty real tenants except idempotent marker reruns.

`SeedTenantDemoDataJob`: unique per company, `platform` queue, one try, 3600-second timeout, `failed()` audit. It never runs in the provisioning HTTP request.

### 4.12 Platform-owned role definitions

Tenant `RolePolicy` keeps `viewAny/view` with `roles.manage`; create/store/update/edit/delete always return false. Remove `ownsRoleDefinitions`.

Tenant routes keep `admin.roles.index`, `admin.roles.show`, and `admin.roles.export`; write routes disappear. Tenant role assignment via `admin.users.*` and `syncRoles` remains.

Platform role CRUD always writes `guard_name=web`. Protected names `admin`, `manager`, `staff`, `technician`, `customer` are immutable and undeletable; permissions may be edited with warning that `RolePermissionSeeder` restores seeded roles at deploy. Requests validate existing web permissions and `NoSodConflict`; `admin` retains the existing break-glass exemption. Every write requires platform confirmation and logs permission before/after lists.

### 4.13 Export job

`BuildTenantExportJob`:

- `ShouldQueue`, `ShouldBeUnique`, connection/queue `platform`, `$tries=1`, `$timeout=3600`, `$failOnTimeout=true`; unique ID is root tenant ID;
- `platform` database connection has `retry_after=3660`; worker uses `--queue=platform --tries=1 --timeout=3600`;
- mark record running; root+children; temp files under private `platform/exports/tmp/{uuid}`;
- for each company, enter `CompanyService::runAs($id, fn)` and use explicit parameterized `where('company_id',$id)` on raw table queries; stream/chunk, never load unbounded lists;
- include companies, safe users, company_user, non-platform activity, every registered tenant table, private tenant media, and public logo;
- skip tenant_exports, cache/session/job/failed-job/notification/reset-token/personal-token tables;
- exclude any field named/ending in password, secret, token, remember_token or `two_factor_*`, including PPPoE/NAS/RADIUS and payment tokens; manifest lists omissions;
- use Storage streams for non-local media disks;
- manifest: IDs/codes, generation time, row counts, omitted columns, media counts; no application config/secrets;
- final private path `platform/exports/{uuid}.zip`; set ready, size/counts, completion and expiry now+24h;
- `failed()` removes partials, stores class + short message without trace and writes platform failure.

`ExpireTenantExportsCommand` runs hourly with `withoutOverlapping()`: delete expired archives/temp files and mark rows `expired` while retaining DB history.

Download requires full platform stack, valid signature, ready/unexpired record and existing file. Headers: `Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`. Link uses `URL::temporarySignedRoute(...,$export->expires_at)`. Downloads write platform + support audit.

### 4.14 Purge command

`inbils:platform:purge-company {code}`:

- interactive only, no web route or bypass flag;
- exact code, root tenant, inactive, `suspended_at <= now()-30d`, deletion requested, and export completed after suspension;
- print tenant IDs, user/per-table/media counts; require exact code;
- refuse tenant-homed users with memberships outside the tenant tree;
- record code/name/IDs/counts in system-caused `platform tenant.purge_started`;
- transaction deletes server-registry table rows child-first, tenant-visible activity, media metadata, company_user, role/permission morph pivots, notifications, personal tokens and reset tokens, then tenant-homed users, children and root;
- preserve platform audit by setting `company_id=null` and snapshotting code/name before company deletion;
- registry uses server schema, validated identifiers and parameterized queries only;
- after commit delete company directories from private/public disks and export archives; file-cleanup failure exits nonzero with exact recovery paths.

No automated purge schedule exists.

## 5. UI contract

### 5.1 Platform shared props and shell

`HandlePlatformInertiaRequests` shares:

```ts
type PlatformSharedProps = {
  platform: { admin: {
    id: number; name: string; email: string; two_factor_enabled: boolean;
  } | null };
  flash: { success?: string; error?: string; warning?: string };
  app: { name: string; locale: 'id' };
};
```

No tenant auth/company/work queues/notifications/permissions are shared.

`config/ziggy.php`:

```php
'groups' => [
    'platform' => ['platform.*'],
    'tenant' => ['!platform.*'],
],
```

`app.blade.php` loads platform group for `/platform`/`platform.*`, tenant otherwise. Cross-shell navigation full-loads.

`PlatformLayout` links only to real routes: `Perusahaan`, `Perusahaan baru`, `Peran & hak akses`, `Audit platform`, `Ekspor`; admin menu has theme toggle/logout and reuses toast primitives.

### 5.2 Pages and exact props

| Page | Props |
|---|---|
| `Platform/Auth/Login` | `status?: string` |
| `Platform/Auth/TwoFactorChallenge` | no sensitive props |
| `Platform/Auth/TwoFactorSetup` | `twoFactor {enabled,pending,recovery_codes_remaining}`; flashed setup QR/key or recovery codes once |
| `Platform/Auth/ConfirmPassword` | no props beyond errors/flash |
| `Platform/Dashboard` | paginated `tenants`; `filters {search,status,sort,direction,per_page}`; `summary {active,suspended,deletion_requested,unattributed_failed_jobs_7d}` |
| `Platform/Companies/Create` | `defaults {timezone,currency}`, `timezones`, `currencies` |
| `Platform/Companies/Show` | `tenant`, `metrics`, `admins`, `subsidiaries`, paginated `users`, `userFilters`, `recentAudit`, `failedJobs`, `failedWhatsApp`, `exports`, `lifecycle {can_suspend,can_reactivate,can_request_deletion,deletion_blockers}` |
| `Platform/Roles/Index` | paginated `roles`, `filters`, `protectedRoles` |
| `Platform/Roles/Create` | grouped `permissions` |
| `Platform/Roles/Edit` | `role`, grouped `permissions`, `protectedRoles`, `seededWarning` |
| `Platform/Audit/Index` | paginated activity, `filters {company_id,platform_admin_id,event,date_from,date_to,search,page,per_page}`, tenant/admin options |
| `Platform/Exports/Index` | paginated exports, `filters {company_id,status,page,per_page}`, `download_url` only when ready/unexpired |

Tenant dashboard row:

```ts
{
  id:number; name:string; code:string;
  status:'active'|'suspended'|'deletion_requested';
  subsidiaries_count:number; users_count:number; customers_count:number;
  last_login_at:string|null; storage_bytes:number;
  failed_jobs_7d:number; failed_whatsapp_7d:number;
}
```

Company user row:

```ts
{
  id:number; name:string; email:string; company:{id:number;name:string;code:string};
  roles:string[]; is_active:boolean; last_login_at:string|null;
  two_factor_enabled:boolean; can_impersonate:boolean; invitation_pending:boolean;
}
```

Every list is server-paginated. Pages include empty/loading/error, light/dark, keyboard and mobile-overflow states using existing UI/composite primitives. No dependency is added.

### 5.3 Confirmation dialogs

Use existing Radix AlertDialog primitives. The platform confirmation form is submitted first; on success the action form submits. R4 dialogs also type company code. Password fields clear after error/success and never enter URL/history.

- suspend: reason, platform password, code;
- deletion request: platform password, code;
- export: platform password, code;
- impersonation start: reason, platform password;
- write mode: platform password, code;
- tenant 2FA reset: platform password;
- role writes: platform password.

### 5.4 Impersonation banner

> Shipped shape (2026-10-16, supersedes the type below): `impersonation: {admin_name, user_name, company_name, company_code, mode: 'read'|'write', expires_at, reason, write_url, read_url, stop_url, confirm_url}`; `Inertia::always`, so partial reloads refresh it.

Tenant shared prop:

```ts
impersonation: null | {
  id:string;
  user:{id:number;name:string};
  company:{id:number;name:string;code:string};
  platform_admin:{name:string};
  mode:'read'|'write';
  reason:string;
  remaining_seconds:number;
  stop_url:string;
  write_url:string;
  read_url:string;
};
```

Banner appears after Topbar/before main in `AdminLayout` and before the card in `GuestLayout` for app-only pages. It is `role="status"`, remains visible while content scrolls, and uses exact copy:

```text
Anda masuk sebagai {nama} ({perusahaan}) · superadmin {nama} · mode {baca|ubah} · sisa {mm:ss} · Keluar
```

Write mode uses danger status and a visible downgrade action. At zero, reload; server enforcement remains authoritative. Dialog focus is trapped and returns to trigger.

Read-only mutations surface middleware copy as danger toast. Profile/security controls and self-user credential actions are hidden during impersonation, but backend denial remains authoritative.

### 5.5 Tenant audit/roles UI

- Add `AUDIT_MODULE.support = {label: 'Akses dukungan', tone: 'warning'}`.
- Platform causers display `Tim dukungan Inbils`, never email/raw ID.
- Detail shows reason, mode, duration, target and route/method/status where applicable.
- Tenant Roles Index has no create/delete; actions open `admin.roles.show`.
- Roles Show is read-only name/global warning/permission groups/current-company user count.
- User role assignment remains unchanged.

## 6. Test plan

Stack: Laravel 12.69.3, PHP 8.5, MySQL `inbils_test`, PHPUnit 11, Inertia 2, React 18.

### Ticket 02

`PlatformAuthenticationTest`: guard isolation both ways; inactive indistinguishable; 5/min + 20/hour; first-login enrollment confinement; each session must pass 2FA; pending challenges do not collide; no disable route; 30-minute idle; custom platform confirmation does not satisfy tenant confirmation and vice versa.

`CreatePlatformAdminCommandTest`: active admin, hashed password, no tenant membership; duplicate/invalid inputs; additional admins allowed.

`PlatformTwoFactorTest`: encrypted/replay/recovery behavior; no tenant token/session deletion for colliding numeric ID; platform audit causer/log/company.

```bash
./vendor/bin/sail artisan test --compact tests/Feature/Platform/Auth tests/Unit/Platform
./vendor/bin/sail exec -T laravel.test vendor/bin/pint --format agent app config database routes tests
```

### Ticket 03

`TenantManagementTest`: two-tenant metric isolation/bounded queries; provisioning company/admin/defaults/membership/role/invitation; duplicate and logo rollback; tenant code read-only; subsidiary restrictions/member validation; suspension across tree via model events; existing inactive child remains inactive; existing web/API/token enforcement; reactivation; deletion request 30-day/export gates.

`TrialDemoDataTest`: queued only; second tenant no DEMO identity/credential/global-ID collision; no NAS/network accounts or WhatsApp consent; other tenant untouched; original DEMO path remains idempotent.

`PlatformRoleManagementTest`: all tenant writes denied; platform CRUD/protected/SoD behavior; tenant assignments remain.

`PurgeTenantCommandTest`: all preconditions/exact code/external-membership blocker; two-tenant full fixture removes target DB/files, preserves other tenant and platform audit snapshot.

Update `SetupWizardServiceTest`, `CompanyAdminAuthorityTest`, `RolePersonaSodTest`, `AdminPermissionRoleTest`.

```bash
./vendor/bin/sail artisan test --compact tests/Feature/Platform tests/Unit/SetupWizardServiceTest.php tests/Feature/Tenant/CompanyAdminAuthorityTest.php tests/Feature/RolePersonaSodTest.php tests/Feature/AdminPermissionRoleTest.php
./vendor/bin/sail exec -T laravel.test vendor/bin/pint --format agent app database routes tests
```

### Ticket 04

`ImpersonationTest`: step-up/reason; cross-tenant/inactive/suspended/no-company refusal; PlatformAdmin cannot bind; no API token; table-driven read-only writes; Inertia toast/plain 403; company switch; forbidden security/self-user routes; write confirmation/code; policies still act as target; PlatformAdmin causer + batch/model/platform/support rows; no payload secrets; expiry/suspend/deactivation/logout/stop; remember token and last_login unchanged; banner only during session; tenant excludes platform rows.

```bash
./vendor/bin/sail artisan test --compact tests/Feature/Platform/ImpersonationTest.php tests/Feature/Reporting/AuditLogTenantIsolationTest.php
./vendor/bin/sail exec -T laravel.test vendor/bin/pint --format agent app Modules/Reporting tests
```

### Ticket 05

`SupportActionsTest`: confirmed 2FA reset, target session/token cleanup and dual audit; queued Indonesian invitation/membership/throttle; unlock shared 15-minute user TOTP key.

`TenantExportTest`: two-tenant ZIP isolation, all registry tables/media, secrets omitted/manifested, chunk path, one active export, signed/auth/tamper/expiry/missing-file behavior, 24-hour cleanup retains record, sanitized failure cleanup.

`PlatformAuditTest`: root filter includes children, admin/event/date/search, pagination/eager causer, no N+1, tenant exclusion.

```bash
./vendor/bin/sail artisan test --compact tests/Feature/Platform/SupportActionsTest.php tests/Feature/Platform/TenantExportTest.php tests/Feature/Platform/PlatformAuditTest.php
./vendor/bin/sail exec -T laravel.test vendor/bin/pint --format agent app config database routes tests
```

### Tickets 06-07

Ticket 06:

```bash
npm run typecheck
npm run lint
npm run format:check
node --test resources/js/Pages/Platform/**/*.selfcheck.mjs
```

`tests/e2e/platform.spec.ts`: platform TOTP login, create tenant, suspend dedicated fixture and verify tenant login blocked, reactivate, request export.

Ticket 07:

```bash
npm run typecheck
npm run lint
npm run format:check
node --test resources/js/Pages/Admin/Reports/AuditLog.selfcheck.mjs resources/js/lib/navigation.selfcheck.mjs
```

`tests/e2e/impersonation.spec.ts`: read-only start/reason/banner/countdown, blocked write toast/no mutation, security page blocked, confirmed+code write mode, successful/audited write, downgrade and leave.

### Ticket 08 full gates

```bash
./vendor/bin/sail artisan test --compact
./vendor/bin/sail exec -T laravel.test vendor/bin/pint --format agent
npm run build
npm run lint
npm run format:check
node scripts/e2e-setup.mjs
npm run test:e2e -- specs --project=chromium --workers=1
```

No package is added. Run dependency audits only if dependency files change unexpectedly.

## 7. Risks and open decisions

### Fixed decisions

1. Add `companies.suspended_at`; audit/updated_at cannot safely prove the 30-day gate.
2. Platform password confirmation gets its own key/middleware/controller; it never reuses tenant confirmation.
3. Separate `support` log hides internal platform details while satisfying tenant transparency.
4. Tenant company code becomes read-only to make R3 true.
5. Trial data gets a safe company-explicit path; existing DEMO behavior stays.
6. Export omits secret-bearing columns by default. Plaintext credential migration remains parked.
7. Shared Laravel session during impersonation is intentional; two guard login and confirmation keys remain distinct.

### Risks

- First-login TOTP is trust-on-first-use. Create admins only when the operator is ready to enroll immediately.
- `sessions.user_id` lacks guard/type. Platform 2FA never deletes by numeric ID; tenant 2FA may still log out a colliding platform session, an availability-only fail-closed issue.
- A wholly unrelated `PLATFORM_DOMAIN` needs explicit host/session/cookie planning. Same host or sibling subdomain is recommended.
- Storage totals from media metadata may undercount legacy rows; export/purge file walking is authoritative.
- Failed-job attribution is best-effort from app-written `companyId`; unknown rows remain unattributed.
- Existing wizard double-runs idempotent CompanySeeder via direct call and CompanyCreated. This lane preserves that convention.
- Platform admin in-app lifecycle/reset, IP allowlist, SaaS billing/plans, per-tenant roles, mobile impersonation and plaintext credential exports remain parked.
- `field-trip-status` remains `ready-for-agent`; R8 blocks implementation until that lane merges.

No unresolved owner decision blocks this design. Security reviewer may reject a fixed default before ticket 02 starts; implementers must not invent alternatives.

## 8. Work breakdown

### S0-DOC, ticket 01, architect/security-reviewer

- **Goal:** materialize reviewed contracts before auth code.
- **Owned files:** `NEW: .scratch/platform-superadmin/design.md`; `NEW: docs/architecture/decisions/ADR-003-platform-superadmin.md`; `AGENTS.md`; `docs/SECURITY.md`; `.gitignore`; ticket 01.
- **Depends on:** field-trip-status merged.
- **Acceptance:** security-reviewer records SIGNED before S02-S07.

### S02, ticket 02, backend

- **Goal:** identity/guard, route shell, mandatory per-session 2FA, idle timeout, platform confirmation, audit primitives.
- **Owned files:** `config/auth.php`; `NEW: config/platform.php`; `NEW: config/ziggy.php`; `bootstrap/app.php`; `bootstrap/providers.php`; `resources/views/app.blade.php`; platform_admin migration/model/factory; `PlatformServiceProvider`; `TwoFactorService.php`; `TwoFactorChallengeRequest.php`; `AuditService.php`; `NEW: PlatformAuditService`; platform auth middleware/controllers/requests; create-admin command; `routes/platform.php`; `routes/platform/auth.php`; platform auth/unit tests and helper trait.
- **Produces:** `platform` guard, platform route group, generic 2FA, platform audit interface.
- **Depends on:** S0-DOC.
- **Acceptance:** focused guard/2FA/throttle/idle/confirmation PHPUnit and Pint.

### S03A, ticket 03, backend

- **Goal:** tenant list/detail, provisioning/subsidiaries, lifecycle and export-record foundation.
- **Owned files:** `Company.php`; lifecycle migration; `SetupWizardService.php`; tenant company profile request/page; `ProvisionTenantAction`; lifecycle/tenant queries/controllers/requests/resources; invitation notification; `NEW: tenant_exports migration/model/factory`; `routes/platform/tenants.php`; tenant management tests.
- **Produces:** tenant props for S06; lifecycle/deletion contract; `TenantExport` consumed by S05.
- **Depends on:** S02.
- **Acceptance:** two-tenant/provisioning/suspension/deletion tests and Pint.

### S03B, ticket 03, backend/devops

- **Goal:** production-safe trial data, tenant registry and purge, long-running platform queue foundation.
- **Owned files:** demo seeder/context/foundation/customer/WhatsApp files; `SeedTenantDemoDataJob`; `TenantTableRegistry`; `TenantPurger`; purge command; trial/purge tests; `config/queue.php`; `compose.yaml`; `.env.example`; `.env.docker.example`; `NEW: deploy/systemd/platform-queue-worker.service.example`; worker sections in `docs/operations/{DOCKER,DEPLOYMENT,SUPERVISION}.md`.
- **Produces:** `seedFor`, registry and `platform` queue consumed by S05.
- **Depends on:** S02 and S03A `createFor`.
- **Acceptance:** trial isolation, safe omissions, two-tenant purge, worker config/docs and Pint.

### S03C, ticket 03, backend

- **Goal:** move global role definitions to platform.
- **Owned files:** `RolePolicy.php`; tenant `RoleController`; delete tenant role write requests; `routes/admin.php`; platform role controller/requests/routes; role tests and updated existing role authority/SoD tests.
- **Produces:** platform role props/routes for S06; read-only tenant route for S07.
- **Depends on:** S02.
- **Acceptance:** all tenant writes denied; platform writes audited; assignment unchanged.

### S04, ticket 04, backend

- **Goal:** impersonation backend, read-only enforcement, real-actor tenant audit and shared banner prop.
- **Owned files:** new impersonation service/middleware/controller/requests/routes; `HandleInertiaRequests.php`; Reporting `AuditLogQuery`, `AuditLogResource`, `ReportExporter`; `DashboardAdminStatsQuery`; impersonation and audit-isolation tests.
- **Produces:** exact session keys, `impersonation` prop, support-causer presentation.
- **Depends on:** S02; root-company semantics from S03A.
- **Acceptance:** full table-driven impersonation tests and Pint.
- **needs-change:** `bootstrap/app.php` is S02-owned; S02 owner inserts `EnforceImpersonation::class` immediately before `ResolveActiveCompany::class` after S04 creates it.

### S05, ticket 05, backend

- **Goal:** support actions, tenant ZIP export/signed download/cleanup, platform audit data.
- **Owned files:** export job/builder/cleanup command; support/export/audit controllers/requests/resources/queries; `routes/platform/support.php`; `routes/console.php`; optional LoginRequest static key helper; support/export/audit tests.
- **Consumes:** S02 guard/audit, S03A TenantExport/lifecycle, S03B registry/queue, S04 support presentation.
- **Acceptance:** ZIP/signed/cleanup/support/audit tests and Pint.

### S06, ticket 06, frontend/qa

- **Goal:** complete Indonesian platform shell/pages and platform E2E.
- **Owned files:** `NEW: PlatformLayout.tsx`; `types/platform.ts`; `Pages/Platform/**`; `Pages/Error.tsx`; typed-confirm dialog; E2E fixture/setup/helper and `platform.spec.ts`.
- **Depends on:** S02, S03, S05.
- **Acceptance:** typecheck, ESLint, Prettier, selfchecks, E2E typecheck.

### S07, ticket 07, frontend/qa

- **Goal:** tenant banner/transparency/read-only roles and impersonation E2E.
- **Owned files:** impersonation banner; Admin/Guest layouts; shared types; audit status map/page/selfcheck; tenant role Index and new Show; delete obsolete role Create/Edit; navigation selfcheck if needed; `docs/user-guide/web.md`; `scripts/docs-screenshots.json`; `impersonation.spec.ts`.
- **Depends on:** S04, S03C and S06 shared E2E helper.
- **Acceptance:** typecheck, ESLint, Prettier, selfchecks, E2E typecheck.

### S08, ticket 08, QA/security/release

- **Goal:** full gates, security sign-off and release evidence.
- **Owned files:** `CHANGELOG.md`; ticket 08; authoritative backlog/scratch statuses.
- **Depends on:** S02-S07.
- **Acceptance:** full PHPUnit/build/E2E; security review SIGNED; deploy instructions. Commit/push only if explicitly requested.

### File ownership matrix

| File/group | Owner |
|---|---|
| ADR, AGENTS, SECURITY, .gitignore, scratch design/ticket 01 | S0-DOC |
| `bootstrap/app.php`, guard/config/Ziggy/root Blade/platform auth/Audit/TwoFactor | S02 |
| Company/SetupWizard/tenant queries/controllers/lifecycle/TenantExport/invitation | S03A |
| Demo seeders, registry/purger/commands, queue/compose/worker/ops docs | S03B |
| Tenant/platform role backend and `routes/admin.php` | S03C |
| Impersonation, tenant Inertia share, Reporting audit backend | S04 |
| Export/support/audit backend and `routes/console.php` | S05 |
| Platform UI/Error/E2E fixture/helper/spec | S06 |
| Tenant impersonation/audit/roles UI/user-guide/E2E | S07 |
| Changelog/release statuses | S08 |
| `resources/js/types/index.ts` | S07 only; S06 uses `types/platform.ts` |

No path has two owners. All `NEW:` parent directories were verified: `app`, `app/Models`, `app/Services`, `app/Queries`, `app/Http/{Controllers,Middleware,Requests}`, `app/Console/Commands`, `database/{migrations,factories,seeders}`, `resources/js/{Pages,Layouts,Components/composite}`, `routes`, `tests/{Feature,Unit,e2e/support}`, `docs/architecture/decisions`, `docs/operations`, and `deploy/systemd`.

## Exact fenced documents

### ADR-003

Target: `docs/architecture/decisions/ADR-003-platform-superadmin.md`

```md
# ADR-003: Platform superadmin as a separate identity and authority

Status: Accepted

## Context

Inbils now hosts unrelated ISP tenants in one installation. Tenant users are scoped through `CompanyService` and `BelongsToCompany`, while Spatie role definitions are global because `teams=false`. Reusing tenant identities for operator access would mix two trust domains: a tenant session could become cross-tenant, company middleware would treat the operator as a tenant user, and an unrelated ISP could continue changing role definitions used by every ISP.

## Decision

Create a separate `platform_admins` table, Eloquent provider and `platform` session guard. Platform admins never belong to a company, never appear in `company_user`, never receive tenant roles, and enter through `/platform` with mandatory TOTP, a 30-minute idle timeout and explicit step-up confirmation for sensitive actions. Cross-tenant row access exists only in the platform layer. Single-tenant work uses `CompanyService::runAs()` or `forCompany()`; list metrics may use an explicit tenant-ID set and grouped `company_id` aggregates.

Impersonation keeps the platform identity as the real actor and opens the tenant `web` guard as the selected user for at most 30 minutes. It starts read-only. Write mode requires a fresh platform password and the tenant code. Tenant policies still authorize the acting user. Spatie activity rows record the `PlatformAdmin` as causer and use tenant-visible `support` entries for transparency.

Global role definitions move to the platform panel. Tenants may view seeded/custom roles and assign them to users, but cannot create, edit or delete role definitions. `spatie/laravel-permission` remains `teams=false`; per-tenant roles are a separate future decision.

## Alternatives considered

1. **`users.is_platform_admin` flag and the `web` guard.** Rejected. It combines unrelated identity domains, makes tenant middleware and session company state applicable to platform access, increases the chance of cross-tenant policy bypass, and lets password/2FA confirmation state bleed between roles.
2. **Spatie teams for per-tenant role definitions.** Rejected for this lane. It changes every role assignment/check, backfill and query in the application. Platform-owned global definitions close the current cross-tenant hole with a smaller cutover. Per-tenant roles remain parked.
3. **No impersonation; only direct platform tools.** Rejected. Complaint handling needs the exact tenant user's policy/UI view. Read-only-by-default impersonation gives that visibility while bounding writes and producing a durable audit trail.

## Consequences

- Platform authentication, TOTP challenge state, idle state and step-up confirmation are isolated from tenant authentication.
- Platform controllers must explicitly name every tenant they inspect. A missing tenant context continues to fail closed.
- Tenant role-definition routes become read-only; platform role changes affect every tenant and are audited.
- Platform and tenant sessions share Laravel session storage during impersonation. `sessions.user_id` cannot distinguish model types, so platform 2FA never deletes sessions by numeric user ID.
- `PLATFORM_DOMAIN` is optional. A separate host must be trusted and must preserve the session needed by impersonation.
- No IP allowlist, SaaS billing/plans, per-tenant roles, or mobile impersonation is included.
```

### AGENTS.md Company Rules amendment

Replace the current `## Company Rules (MANDATORY — multi-tenant ready)` section through its system-global-config bullet with:

```md
## Company Rules (MANDATORY — SaaS multi-tenant)

Source of truth: ringkasan di bawah + `docs/architecture/database.md` +
`docs/architecture/decisions/ADR-003-platform-superadmin.md`. Ringkasan:

- Company identity dinamis via DB (`companies` table). TIDAK hardcode
  nama/logo/currency/timezone di kode atau `.env`.
- Satu tenant = satu company top-level (tanpa `parent_company_id`) beserta
  anak perusahaannya. Company pertama tetap dibuat lewat Setup Wizard
  one-shot. Tenant baru dan subsidiary boleh dibuat HANYA oleh platform
  admin; CLI `inbils:company:create-subsidiary` tetap tersedia untuk operasi.
- Platform admin adalah identitas terpisah di `platform_admins`, guard
  `platform`, route `/platform`. Platform admin TIDAK pernah menjadi User,
  TIDAK punya `company_id`/membership/role tenant, dan TIDAK boleh dipakai
  sebagai pengecualian policy tenant.
- Company efektif user tenant = `active_company_id` di session (divalidasi
  terhadap `company_user` + company aktif di SETIAP pembacaan) atau home
  company (`users.company_id`). DILARANG membaca `users.company_id` sebagai
  tenant: pakai `CompanyService::currentId()` / `CompanyService::idFor($user)`.
- Setiap model master/transaksi WAJIB pakai trait `BelongsToCompany`
  (auto-scope query ke `CompanyService::currentId()` + auto-set company_id
  on create). Scope FAIL-CLOSED: tanpa company context query tenant kosong
  dan create -> 403. Code tanpa user (artisan, queue job, seeder) WAJIB
  `CompanyService::runAs($companyId, fn)`. Exception: User, Role,
  Permission, ActivityLog, Company, PlatformAdmin, TenantExport.
- Setiap migration tabel master/transaksi WAJIB tambah `company_id`
  bigint unsigned FK -> `companies.id` NOT NULL + unique index per-company
  (mis. `(company_id, sku)` bukan `sku` global).
- Company identity (nama, logo, currency, timezone, settings) baca via
  `CompanyService::current()` / `CompanyService::setting($key)`. Jangan
  hardcode, jangan baca `.env` untuk company-specific.
- Query row tenant lintas company HANYA boleh di layer platform, artisan,
  queue job atau seeder, dengan tenant disebut eksplisit lewat
  `Model::forCompany($id)` / `CompanyService::runAs($id, fn)`. Query metrik
  list platform boleh memakai `withoutCompany()->whereIn('company_id',
  $explicitIds)` dan aggregate `GROUP BY company_id`; DILARANG query tanpa
  set tenant eksplisit. Controller tenant biasa tidak mendapat pengecualian.
- Head melihat subsidiary HANYA lewat laporan konsolidasi read-only
  (`report.consolidated.view`, Head + subsidiary aktif, semua currency harus
  sama); menulis di subsidiary = switch (`company.switch`) ke company yang
  di-membership-kan. Platform admin memakai route platform, bukan switch.
- Role/permission tetap global (`spatie teams=false`). Definisi role
  (buat/ubah/hapus + permission) HANYA dari platform panel. User tenant boleh
  melihat role dan assign role yang ada lewat Pengguna, tetapi `RolePolicy`
  menolak seluruh write definisi role.
- Administrasi user tenant (lihat/ubah/hapus, termasuk email, kata sandi,
  role, status) tetap menuntut aktor member SETIAP company milik target
  (`CompanyMembershipService::administers`). Platform support memakai tool
  platform atau impersonation ter-audit, bukan bypass policy.
- Impersonation web menyimpan platform admin sebagai aktor nyata, maksimal
  30 menit, default read-only, dan setiap write mode ubah tercatat di audit
  platform + tenant. DILARANG mengubah akun/2FA/kata sandi/email user yang
  sedang diimpersonate atau menerbitkan API token dari impersonation.
- Setup Wizard flow fresh install tetap: `php artisan inbils:setup` -> login
  -> wizard -> company pertama. Route `/setup` one-shot
  (`RequireNoCompany` abort 403 bila company sudah ada). Tenant berikutnya
  dibuat lewat platform; Breeze register route tetap DISABLED.
- Dua-layer config: `companies.settings` json (company-specific) + `settings`
  table (system-global defaults). Resolution: `CompanyService::setting(key)`
  -> `SettingService::get('default_'.key)` -> hardcoded default.
- **Initialization rule (MANDATORY):** install hanya seed system roles,
  permission template dan system setting defaults. Data company dibuat lewat
  Setup Wizard/platform provisioning. Demo tenant data hanya lewat job
  platform yang explicit company-scoped; tidak pernah saat production install.
- System-global infra config (APP_NAME, mail, queue, `PLATFORM_DOMAIN`) tetap
  `.env`/config. Secret tidak pernah disimpan di source/log/export.
```

### `docs/SECURITY.md` section

Append after `## 4. Mobile API`:

```md
## 5. Platform superadmin (`/platform`)

Platform superadmin adalah identity plane terpisah untuk operator Inbils. Ia
bukan role tenant. Keputusan arsitektur lengkap ada di
`docs/architecture/decisions/ADR-003-platform-superadmin.md`.

### 5.1 Identity, login and session

- Model/table `PlatformAdmin`/`platform_admins`, provider `platform_admins`,
  guard session `platform`. Platform admin tidak punya `company_id`, membership,
  role tenant, Sanctum token atau remember-me.
- Login: 5 kegagalan per menit per e-mail + IP dan 20 per jam per e-mail.
  User tidak aktif gagal dengan pesan kredensial salah yang sama. Platform
  admin dibuat dari console lewat `php artisan inbils:platform:admin-create`.
- TOTP wajib. Login pertama hanya boleh mencapai enrolment sampai satu kode
  valid mengaktifkan secret dan menampilkan 8 recovery code sekali. Setelah
  enrolment, setiap session harus menyelesaikan challenge sendiri; mengaktifkan
  akun di session lain tidak membuat session lama lolos.
- Session idle 30 menit. Aktivitas impersonation yang valid menghitung sebagai
  aktivitas platform, tetapi impersonation tetap mempunyai hard limit 30 menit.
- Tidak ada forgot-password, disable-2FA atau IP allowlist platform di v1.
  Operator yang terkunci memakai akses server untuk membuat admin baru atau
  prosedur tinker terkontrol; IP allowlist tetap di `docs/PARKING_LOT.md`.

### 5.2 Step-up actions

Konfirmasi platform memakai route/middleware terpisah dan session key
`platform.auth.password_confirmed_at`, bukan timestamp tenant
`auth.password_confirmed_at`. Kata sandi diperiksa terhadap guard `platform`,
dibatasi 5 percobaan per menit per admin + IP, dan berlaku 5 menit. Suspend,
ajukan hapus, ekspor dan masuk mode ubah juga mensyaratkan ketik ulang code
company yang sama persis. Kata sandi hanya hidup di body request, tidak masuk
URL, log, activity properties atau Inertia history.

### 5.3 Tenant suspension and deletion

- Suspend berlaku pada tenant root + subsidiary yang aktif, menulis
  `suspended_at`, `is_active=false` dan reason di audit. Aksi memakai save model
  satu per satu supaya `Company::updated` tetap mencabut API token dan membuang
  cache resolution.
- Enforcement tetap satu jalur: `LoginRequest`, `OpensSession`,
  `ResolveActiveCompany` + `RequireHasCompany`, `EnsureApiUserIsActive`, dan
  hook revocation Company. Platform action tidak membuat middleware kedua.
- Reactivate hanya mengaktifkan row yang mempunyai `suspended_at`, lalu
  membatalkan `deletion_requested_at`.
- Ajukan hapus baru boleh setelah 30 hari suspend dan ada ekspor selesai sesudah
  waktu suspend. Web tidak pernah purge. Purge hanya lewat
  `php artisan inbils:platform:purge-company {code}`, interaktif, dengan ketik
  ulang code dan preflight jumlah row/file.

### 5.4 Impersonation

- Web only. Session menyimpan platform admin id, target user id, tenant id,
  reason 5-200 karakter, waktu mulai, UUID batch dan mode. Target wajib user
  aktif dalam tenant aktif; PlatformAdmin tidak mungkin menjadi target karena
  tabel/guard terpisah.
- Default `read`: hanya GET/HEAD dan company switch yang tidak mengubah data.
  Mutation Inertia kembali dengan toast; client biasa mendapat 403. Mode `write`
  perlu kata sandi platform + ketik ulang code company. Start, perubahan mode,
  setiap write dan end tercatat di log platform dan log tenant.
- PlatformAdmin adalah causer nyata activity log; User impersonated tetap acting
  identity untuk policy dan kolom bisnis seperti `created_by`. Semua activity
  session berbagi `batch_uuid`.
- Dilarang selama impersonation: profile/security pribadi, kata sandi/e-mail/2FA
  target, melihat secret/recovery code/token, menerbitkan API token, atau akses
  tenant suspended. Logout tenant berarti keluar impersonation; remember token
  target tidak diputar.
- Hard limit 30 menit diverifikasi server pada setiap request. Banner hanya
  indikator; countdown client bukan enforcement.

### 5.5 Platform-owned roles

Spatie permission tetap `teams=false`; semua role menggunakan guard `web`.
Tenant dapat melihat role dan assign role ke user, tetapi tidak dapat membuat,
mengubah atau menghapus definisi role. CRUD definisi role hanya di platform,
dengan step-up kata sandi dan audit. Nama seeded role `admin`, `manager`, `staff`,
`technician`, `customer` immutable dan tidak boleh dihapus. Seeder deployment
masih menyinkronkan permission kelima role tersebut.

### 5.6 Tenant export

- Request ekspor perlu kata sandi platform + code company. Job berjalan di queue
  `platform`, memasuki setiap company lewat `CompanyService::runAs` dan tetap
  memberi filter `company_id` eksplisit pada query table mentah.
- ZIP berisi CSV tenant dan media folder. Password/hash, TOTP, recovery code,
  token, RADIUS/NAS/PPPoE secret dan payment token tidak diekspor; manifest
  mencatat kolom yang dihilangkan.
- Arsip disimpan di private disk `platform/exports/`. Download perlu session
  platform aktif + 2FA + signed URL maksimal 24 jam. Response `private,no-store`.
  Cleanup menghapus file tetapi mempertahankan record status `expired` sebagai
  bukti ekspor sebelum deletion request.
- Audit tenant tidak menampilkan row `log_name=platform`; access support/ekspor
  yang harus transparan ditulis sebagai `log_name=support` dengan causer aman
  `Tim dukungan Inbils` tanpa e-mail/id internal.

### 5.7 Accepted residuals

- First-login TOTP adalah trust-on-first-use: kata sandi platform yang bocor
  sebelum enrolment dapat mendaftarkan authenticator lebih dulu. Buat admin
  hanya saat operator siap langsung enrol.
- Table `sessions.user_id` tidak mempunyai guard/type. Platform 2FA tidak
  menghapus session berdasarkan angka ID; perubahan 2FA User tenant masih dapat
  mengeluarkan PlatformAdmin dengan ID sama (availability only, fail-closed).
- Attribution `failed_jobs` per tenant best-effort dari payload `companyId`; row
  tanpa company id tampil sebagai unattributed.
- Meter storage memakai metadata media `company_id`; file legacy tanpa metadata
  dapat membuat angka list lebih rendah. Ekspor/purge tetap memakai folder nyata.
```

### `.gitignore` addition

Append after `!docs/architecture/decisions/ADR-002.md`:

```gitignore
!docs/architecture/decisions/ADR-003-platform-superadmin.md
```
