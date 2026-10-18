# Design: tenant-integrations

Spec (decisions final): `.scratch/tenant-integrations/spec.md`. Slug `tenant-integrations`. Copies: `local://design-tenant-integrations.md`, `.scratch/tenant-integrations/design.md`.
Line numbers are from the working tree on 2026-10-26; slices that run after another lane find code by symbol.

## 1 Problem statement

One install-wide `.env` set drives every tenant:

- Midtrans: `config/services.php:53-61`.
- WhatsApp: `config/services.php:74-81`.
- RADIUS: `config/services.php:93-96` and `config/database.php:69-87`.
- Mail: `config/mail.php`.

So every ISP pays into one merchant (flow-audit F5-D10/F5-D18), sends from one WhatsApp number, writes one FreeRADIUS database and mails from the platform sender (F12-D10).

Goal (spec decisions 1-8):

- A `company_integrations` row per company for `midtrans|whatsapp|mail|radius`, resolved per request and per job.
- A settings page Pengaturan → Integrasi with "Uji koneksi".
- Per-tenant webhooks.
- A read-only platform status.
- A one-shot env import.
- A push test in both apps.

Firebase, storage, queue and cache stay platform-global.

Out of scope (spec non-goals and smallest change):

- per-ISP Firebase, SMS, quiet-hours logic, storage UI;
- deleting an integration (the "Aktif" switch covers it);
- inheritance from a holding company (each company, subsidiaries included, configures its own row);
- storing the Midtrans client key or merchant id (nothing reads them at runtime).

## 2 Affected modules

| Area | Existing paths | NEW paths | Slice |
|---|---|---|---|
| Core storage + resolution | `database/seeders/RolePermissionSeeder.php`, `app/Providers/AppServiceProvider.php`, `app/Services/Platform/TenantExportBuilder.php`, `app/Notifications/Platform/TenantAdminInvitation.php`, `routes/admin.php` | `database/migrations/2026_10_26_100000_create_company_integrations_table.php`, `database/migrations/2026_10_26_100100_add_company_integrations_manage_permission.php`, `app/Models/Core/CompanyIntegration.php`, `app/Services/Core/IntegrationConfig.php`, `app/Notifications/Channels/CompanyMailChannel.php`, `app/Http/Controllers/Admin/CompanyIntegrationController.php`, `app/Http/Requests/Admin/UpdateCompanyIntegrationRequest.php`, `app/Jobs/TestCompanyIntegration.php`, `app/Console/Commands/ImportIntegrationsFromEnvCommand.php` | TI-CORE |
| Midtrans | `Modules/Billing/app/Services/MidtransGateway.php`, `Modules/Billing/app/Http/Controllers/MidtransWebhookController.php`, `Modules/Billing/app/Providers/RouteServiceProvider.php`, `Modules/Billing/routes/webhook.php`, `Modules/Billing/app/Console/ReconcileMidtransPayments.php` | — | TI-MIDTRANS |
| WhatsApp | `app/Services/WhatsApp/WhatsAppCloudClient.php`, `app/Http/Controllers/WhatsAppWebhookController.php`, `app/Services/WhatsApp/WhatsAppWebhookHandler.php` | — | TI-WHATSAPP |
| RADIUS | `Modules/NetworkAsset/app/Services/Radius/{RadiusDirectory,RadiusDisconnectClient,RadiusException}.php`, `Modules/NetworkAsset/app/Observers/SpeedProfileRadiusObserver.php`, `Modules/NetworkAsset/app/Services/NetworkAccountProvisioner.php`, `Modules/NetworkAsset/app/Console/SyncNetworkDevices.php`, `Modules/NetworkAsset/app/Http/Controllers/{NetworkAccountController,NasDeviceController}.php`, `Modules/NetworkAsset/app/Providers/NetworkAssetServiceProvider.php` | — | TI-RADIUS |
| Push test API | `routes/api.php`, `app/Notifications/Channels/FcmChannel.php`, `app/Jobs/SendPushNotification.php`, `app/Http/Resources/NotificationResource.php`, `config/scramble.php` | `app/Http/Controllers/Api/V1/PushTestController.php`, `app/Http/Requests/Api/V1/PushTestRequest.php`, `app/Notifications/PushTestNotification.php` | TI-PUSH-API |
| Web UI | `resources/js/lib/{navigation.ts,navigation.selfcheck.mjs,statuses.selfcheck.mjs}`, `resources/js/lib/statuses/{index,audit}.ts`, `resources/js/Components/composite/{permissionLabels,permissionGroups}.ts`, `resources/js/Pages/Admin/Company/Settings.tsx` | `resources/js/Pages/Admin/Company/Integrations.tsx`, `resources/js/types/integrations.ts`, `resources/js/lib/statuses/integration.ts` | TI-WEB |
| Platform view | `app/Queries/Platform/TenantDetailQuery.php`, `resources/js/types/platform.ts`, `resources/js/Pages/Platform/Companies/Show.tsx` | — | TI-PLATFORM |
| Mobile (repo `/home/madmouse/Documents/web/inbils-mobile`) | `packages/inbils_core/lib/src/push/{push_backend,firebase_push_backend,push_service,device_token_repository}.dart`, `…/testing/fake_push_backend.dart`, `…/routing/core_routes.dart`, `…/widgets/account_menu.dart`, `…/l10n/core_id.arb` | `packages/inbils_core/lib/src/screens/push_test_screen.dart` | TI-MOBILE |
| Docs/config | `config/services.php`, `.env.example`, `docs/user-guide/{web,teknisi,pelanggan}.md` + PDFs, `docs/operations/push-notifications.md`, `CHANGELOG.md`, `docs/api/mobile-v1.openapi.json` | — | TI-DOCS |
| Tests | listed per slice in §8 | `tests/Feature/Integrations/` (new dir under existing `tests/Feature/`) | all |

No new Composer, npm or pub dependency:

- `mlocati/ip-lib` is already installed and used for the host policy.
- `flutter_local_notifications` 22.3.2 already has `AndroidFlutterLocalNotificationsPlugin.openAppNotificationSettings()`.

## 3 Data model, resolution, queue safety

### 3.1 Migration M1 — NEW `database/migrations/2026_10_26_100000_create_company_integrations_table.php`

```php
Schema::create('company_integrations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->cascadeOnDelete();
    $table->string('provider', 16);                  // midtrans | whatsapp | mail | radius
    $table->text('credentials')->nullable();         // encrypted:array, secrets only
    $table->json('settings')->nullable();            // non-secret values
    $table->string('external_id', 100)->nullable();  // whatsapp phone_number_id (webhook routing)
    $table->boolean('is_enabled')->default(false);
    $table->timestamp('verified_at')->nullable();
    $table->string('last_error', 255)->nullable();
    $table->timestamp('last_checked_at')->nullable();
    $table->timestamps();
    $table->unique(['company_id', 'provider']);
    $table->index(['provider', 'external_id']);
});
// down(): Schema::dropIfExists('company_integrations');
```

- `(provider, external_id)` is indexed but not unique. Companies of one group may share a WhatsApp number. Routing is per row and guarded by that row's HMAC secret (§4.3), so sharing a number never lets one tenant read another's callbacks.
- Size: `text` holds 64 KB. The largest row (WhatsApp, three secrets) is under 1 KB of JSON, about 3 KB after base64, IV and MAC.
- Additive only. It is safe on the dev DB `inbils` (`migrate`, never `fresh`).

### 3.2 Migration M2 — NEW `database/migrations/2026_10_26_100100_add_company_integrations_manage_permission.php`

- Pattern: `database/migrations/2026_10_17_100000_rename_two_factor_reset_and_document_export_permissions.php`.
- `up()`: `Permission::findOrCreate('company.integrations.manage', 'web')`, then give it to role `admin` when that role exists, then `app(PermissionRegistrar::class)->forgetCachedPermissions()`.
- `down()`: delete the permission, then forget the cache.
- `RolePermissionSeeder.php:18` lists the name. Admin receives every permission at `:222`, and no other role gets it.

### 3.3 Model — NEW `app/Models/Core/CompanyIntegration.php`

```php
final class CompanyIntegration extends Model
{
    use BelongsToCompany;                                   // app/Traits/BelongsToCompany.php

    public const PROVIDERS = ['midtrans', 'whatsapp', 'mail', 'radius'];
    public const STATES = ['empty', 'filled', 'verified', 'failed'];
    /** Write-only keys inside `credentials`. */
    public const SECRETS = [
        'midtrans' => ['server_key'],
        'whatsapp' => ['access_token', 'app_secret', 'webhook_verify_token'],
        'mail' => ['password'],
        'radius' => ['password'],
    ];

    protected $fillable = ['company_id', 'provider', 'credentials', 'settings', 'external_id', 'is_enabled'];
    protected $hidden = ['credentials'];
    // casts: credentials encrypted:array, settings array, is_enabled boolean, verified_at + last_checked_at datetime

    // booted(): saved + deleted → app(IntegrationConfig::class)->forget($row->company_id, $row->provider)
    public function secret(string $key): ?string;            // filled string or null
    public function setting(string $key, mixed $default = null): mixed;
    /** @return array<string, ?string> per SECRETS key: "tersimpan (••••1234)" when ≥ 12 chars, "tersimpan" when shorter, null when absent */
    public function masked(): array;
    /** @return array<string, scalar|null> only the UI fields of §5.1; never import-only keys */
    public function publicSettings(): array;
    /** no row → empty; last_error → failed; verified_at → verified; else filled. Reads no credentials. */
    public static function stateOf(?self $row): string;
    public function touchVerified(): void;                  // verified_at = last_checked_at = now, last_error = null
    public function touchFailed(string $message): void;     // verified_at = null, last_error = Str::limit($message, 255), last_checked_at = now
    /** Host policy: refuses loopback, link-local (169.254/16 incl. cloud metadata, fe80::/10), unspecified and multicast; allows RFC 1918 (ISP LAN). Loopback is allowed in local/testing/e2e. Uses mlocati/ip-lib. */
    public static function isAllowedHost(string $host): bool;
}
```

- No `LogsActivity`, because activity rows would carry ciphertext and field diffs. Audit entries are explicit (§4.7).
- `touchVerified` and `touchFailed` use `forceFill()->save()`. The status columns stay out of `$fillable`.

### 3.4 `IntegrationConfig` — NEW `app/Services/Core/IntegrationConfig.php`

```php
use Illuminate\Container\Attributes\Scoped;

#[Scoped]   // Laravel 12.70: one instance per request / per queued job
final class IntegrationConfig
{
    /** @var array<string, ?CompanyIntegration> "{companyId}:{provider}" */
    private array $rows = [];

    public function forCompany(int $companyId, string $provider): ?CompanyIntegration;   // memoised, null cached too
    public function current(string $provider): ?CompanyIntegration;                     // CompanyService::currentId() ?? null
    public function isEnabled(string $provider, ?int $companyId = null): bool;          // row?->is_enabled === true
    public function forget(int $companyId, string $provider): void;                     // unset memo; radius → DB::purge("radius_{$companyId}")
}
```

- Lookups use `CompanyIntegration::withoutCompany()->where('company_id', …)->where('provider', …)->first()`, because webhooks and workers have no ambient company.
- The `.env` fallback applies to mail only, and lives in `CompanyMailChannel` (§4.4).
- Provider adapters add their own completeness rules. Midtrans adds the server key and the production guard. Save validation guarantees that an enabled row has its required fields.
- Callers use `app(IntegrationConfig::class)`. No facade and no static wrapper.

### 3.5 Queue and request safety (no config bleed)

1. `#[Scoped]` resolution is reset before every job: the worker calls `forgetScopedInstances()` (`vendor/laravel/framework/src/Illuminate/Queue/QueueServiceProvider.php:246-266`). FrankenPHP runs the classic `php_server`, with no worker mode and no Octane, so each HTTP request boots fresh.
2. Nothing writes `config([...])` at runtime. Today `app/` and `Modules/` have 0 such writes, and TI-QA adds a guard test (§6).
3. The cache is invalidated in one place: the model's `saved`/`deleted` hooks call `forget()`. That covers the controller, the import command and test traits.
4. Jobs pick their company explicitly:
   - WhatsApp: `WhatsAppChannel.php:47` already uses `runAs`.
   - Reconcile and network sync: per-company `runAs` loops (§4.2, §4.5).
   - `TestCompanyIntegration`: `runAs($row->company_id)`.
5. RADIUS connections are named `radius_{companyId}`. They are purged on save (`forget`) and before every job (`Queue::looping` in `NetworkAssetServiceProvider`).
6. Mail builds its mailer on every send and never memoises it. `ChannelManager` keeps one channel instance across the jobs of a worker, so a memo would be stale after a save.

## 4 API contracts

### 4.1 Web routes (TI-CORE)

They go inside the existing admin group of `routes/admin.php`, so the prefix is `admin` and the names start with `admin.`. TI-CORE adds them after master-lifecycle W2-CORE-BE is accepted (§8.4).

```php
Route::middleware('can:company.integrations.manage')->group(function () {
    Route::get('company/integrations', [CompanyIntegrationController::class, 'edit'])->name('company.integrations.edit');
    Route::put('company/integrations/{provider}', [CompanyIntegrationController::class, 'update'])
        ->whereIn('provider', CompanyIntegration::PROVIDERS)->middleware('throttle:6,1')->name('company.integrations.update');
    Route::post('company/integrations/{provider}/test', [CompanyIntegrationController::class, 'test'])
        ->whereIn('provider', CompanyIntegration::PROVIDERS)->middleware('throttle:5,1')->name('company.integrations.test');
});
```

| Route | Auth | Request | Response | Errors |
|---|---|---|---|---|
| GET `admin.company.integrations.edit` | session + `company.integrations.manage` | — | Inertia `Admin/Company/Integrations`, props §5.1 | 403 without permission |
| PUT `admin.company.integrations.update` | same | `UpdateCompanyIntegrationRequest` (below) | 303 back, flash `success` "Integrasi {label} disimpan." | 422 field errors; 422 `code`; 429 |
| POST `admin.company.integrations.test` | same | — | 303 back, flash `success` "Uji koneksi {label} berjalan." or `info` "Uji koneksi {label} sedang berjalan." | 422 `provider` "Simpan pengaturan {label} dulu." when no row; 429 |

`X-Inbils-Company` is sent automatically by `app.tsx`. A switch to another company in another tab therefore gets the existing 409 "Perusahaan aktif berubah di tab lain.", and no extra field is needed. Integrity W1-MECH adds `admin.company.integrations.test` to the PreventDuplicateWebSubmit EXCEPT list. `update` stays protected.

**`UpdateCompanyIntegrationRequest`.** It always authorizes, because the route already gates it. The rules depend on `route('provider')`:

| Field | Rule |
|---|---|
| `code` (all) | required, string, max:32. Checked by `TwoFactorService::verifyChallenge($user, $code)` in `after()`. Wrong → `code` "Kode autentikasi salah atau sudah dipakai." User without 2FA → `code` "Aktifkan autentikasi dua langkah di Profil sebelum menyimpan integrasi." |
| `is_enabled` (all) | required, boolean |
| midtrans `server_key` | nullable, string, max:255, regex `/^(SB-)?Mid-server-/`. The `SB-` prefix must match `is_production`: "Server key sandbox (SB-Mid-server-) tidak bisa dipakai di mode produksi." and the reverse. |
| midtrans `is_production` | required, boolean |
| whatsapp `phone_number_id` | required_if:is_enabled,true, digits_between:5,30 (stored in `external_id`) |
| whatsapp `access_token` / `app_secret` / `webhook_verify_token` | nullable, string, max:1024 / max:255 / min:16 max:255 |
| mail `host`, `port`, `scheme`, `username`, `password`, `from_address`, `from_name` | host required_if enabled, max:255, `isAllowedHost`; port integer 1-65535; scheme in:smtp,smtps; username nullable max:255; password nullable max:255; from_address required_if enabled `email:rfc` max:255; from_name nullable max:100 |
| radius `host`, `port`, `database`, `username`, `password` | host required_if enabled, max:255, `isAllowedHost`; port integer 1-65535; database required_if enabled, regex `/^[A-Za-z0-9_]+$/`, max:64; username required_if enabled max:64; password nullable max:255 |

Save semantics in `CompanyIntegrationController::update`:

- A blank or absent secret keeps the stored value, so secrets are write-only.
- Enabling requires every required secret, stored or submitted:
  - midtrans: `server_key`;
  - whatsapp: all three.
- Settings are merged, so import-only keys survive a UI save: `allow_sandbox_in_production`, `base_url`, `transport`.
- Any change to a credential or setting sets `verified_at = null` and `last_error = null`.
- Every save writes an audit row (§4.7).

**`test`:**

1. `Cache::add("integrations:checking:{$companyId}:{$provider}", now()->timestamp, 60)`. When the key already exists, the action returns the `info` flash.
2. Otherwise it dispatches `TestCompanyIntegration($row->id, $user->id)`.

Hard rule 13 applies: no external call inside the web request. Tests and E2E run the `sync` queue, so they see the result at once.

**`TestCompanyIntegration`** — NEW `app/Jobs/TestCompanyIntegration.php`. It implements `ShouldQueue` on the default queue, with `tries = 1` and `timeout = 20`. `handle()`:

1. Load the row with `withoutCompany()`, then run inside `CompanyService::runAs($row->company_id)`.
2. Pick the check by provider:
   - `midtrans` → `app(MidtransGateway::class)->check($row)`
   - `whatsapp` → `app(WhatsAppCloudClient::class)->check($row)`
   - `radius` → `app(RadiusDirectory::class)->check($row)`
   - `mail` → `CompanyMailChannel::check($row, $user)`
3. Record the result. `null` → `touchVerified()`. A string → `touchFailed($message)`.
4. An unexpected `Throwable` is caught:
   - The row gets "Uji koneksi gagal karena galat tak terduga."
   - `Log::warning('integrations.check_crashed', ['company_id', 'provider', 'exception' => class_basename($e)])`. Never a message or a body.
5. Audit `tested` or `test_failed`.
6. A `finally` block runs `Cache::forget(...)` on the checking key.

The four check methods form a contract. TI-CORE writes the `match`; the provider slices implement the methods.

### 4.2 Midtrans (TI-MIDTRANS)

Switch table (each `config('services.midtrans.*')` read):

| Today | Becomes |
|---|---|
| `MidtransGateway.php:26` `server_key` (isEnabled) | `$row?->is_enabled && filled($row->secret('server_key'))`, where `$row = app(IntegrationConfig::class)->current('midtrans')` |
| `:31-32` `is_production` / `allow_sandbox_in_production` | `$row->setting('is_production')` / `$row->setting('allow_sandbox_in_production')` (import-only key; the UI never sets it) |
| `:47` `notification_url` | `route('webhooks.midtrans.company', $attempt->company->public_id)`; the `X-Override-Notification` header is still sent only when the URL starts with `https://` (`:52`) |
| `:169` `server_key` (isValidSignature) | current row's `server_key` even when disabled; an empty key → false (unchanged rule) |
| `:193` `server_key` (basic auth) | via `request(CompanyIntegration $row)` |
| `:201-203` `is_production`, `base_url` | row settings; `base_url` still honoured only when not production **and** env is local/testing/e2e |
| `MidtransWebhookController.php:27` global `isEnabled` | company resolved first, the body runs in `runAs` (below) |
| `:49` `PaymentAttempt::withoutCompany()` lookup | `PaymentAttempt::query()` inside `runAs`, so the attempt must belong to that company |
| `Modules/Billing/app/Providers/RouteServiceProvider.php:26-28` limiter | resolves the company the same way, then `runAs(... isValidSignature ...)`; unsigned or unknown → `perMinute(30)->by(ip)` as today |
| `Modules/Billing/routes/webhook.php:8` | the legacy route stays and the per-company route is added |
| `ReconcileMidtransPayments.php:43` | `isEnabled()` moves inside the per-company `runAs` loop (`:52-60`); companies without Midtrans are skipped; when none is enabled it prints the existing "Midtrans is not configured; nothing to reconcile." |

Unchanged callers, which now become per company through the ambient company:

- `InvoicePayController.php:21-22`: 404 `payment_unavailable` "Pembayaran online belum tersedia."
- Web `InvoiceController.php:231-232`.
- `PaymentAttemptService::openFor`.
- Integrity's `cancelTransaction()`.

Gateway shape after the change. Public signatures stay the same except for two additions:

```php
private function enabledRow(): CompanyIntegration          // current row or throw MidtransGatewayException('Midtrans is not configured.')
private function request(CompanyIntegration $row): PendingRequest   // basic auth + timeout(10)->connectTimeout(5)
private function endpoint(CompanyIntegration $row, string $host, string $path): string
private function client(): PendingRequest { return $this->request($this->enabledRow()); }            // callers unchanged
private function url(string $host, string $path): string { return $this->endpoint($this->enabledRow(), $host, $path); }
public function check(CompanyIntegration $integration): ?string                                      // §4.6
public static function companyIdForNotification(Request $request): ?int
```

`companyIdForNotification` resolves the company in this order:

1. The route parameter `company`, a ULID: `Company::query()->wherePublicId($param)->value('id')`. Subsidiaries are included. Webhook routes have no `SubstituteBindings`, so there is no implicit binding.
2. Otherwise a string `order_id` from the JSON body: `PaymentAttempt::withoutCompany()->where('order_id', $orderId)->value('company_id')`, one read on the unique index.

Routes (`Modules/Billing/routes/webhook.php`):

```php
// Legacy, for the migration period: the company comes from the attempt's order id.
Route::post('webhooks/midtrans', MidtransWebhookController::class)->name('webhooks.midtrans');
Route::post('webhooks/midtrans/{company}', MidtransWebhookController::class)->whereUlid('company')->name('webhooks.midtrans.company');
```

Controller order:

1. A missing `signature_key` string → 403.
2. Missing required string fields → 422.
3. `companyIdForNotification()` is null → 404.
4. `runAs($companyId)` and inside it:
   - `! isEnabled()` → 404;
   - `! isValidSignature()` → 403;
   - the scoped attempt is missing → 404;
   - the Status API re-fetch and `applyNotification` run as today, with 503 plus `midtrans.webhook_unverified` on failure;
   - otherwise 200 "OK".

A payload signed with company B's key and posted to company A's URL fails at the signature step with 403. An order id of B posted to A's URL gets 404.

### 4.3 WhatsApp (TI-WHATSAPP)

Spec decision 5: keep the shared `/webhooks/whatsapp` and route by `phone_number_id`. `graph_version` stays platform config (`services.whatsapp.graph_version`). Method names stay, so the limiter at `AppServiceProvider.php:233-242` needs no edit.

| Today | Becomes |
|---|---|
| `WhatsAppCloudClient.php:28` `isConfigured()` | current row enabled, `access_token` filled, `external_id` filled |
| `:111` token in `request()` | current row `access_token` |
| `:120` `phone_number_id` in `messagesUrl()` | current row `external_id` |
| `:88` `isValidSignature($raw, $header)` | `$this->verifiedCompanies($raw, $header) !== []` |
| `:100` `isValidVerifyToken($token)` | true when any enabled whatsapp row's `webhook_verify_token` matches through `hash_equals`; an empty token never matches. `shortcut: decrypts every enabled WhatsApp row per handshake; add a hashed lookup column past ~1000 tenants.` |
| `WhatsAppWebhookController.php:19` `isConfigured()` gate in `verify()` | removed; no match → 403. The existing test of a 404 while unconfigured becomes an assertion of 403. |
| `:39` signature in `receive()` | `$companies = $client->verifiedCompanies(...)`; `[]` → 403; `$handler->handle($payload, $companies)` |
| `WhatsAppWebhookHandler.php:35` `handle(array $payload)` | `handle(array $payload, array $companies)`, where `$companies` is `array<string, list<int>>`. A value whose `metadata.phone_number_id` is not a key is skipped. |
| `:73` status lookup `WhatsAppMessage::withoutCompany()->where('provider_message_id')` | adds `->whereIn('company_id', $ids)` |
| `:126-128` STOP loops over `Company::query()->pluck('id')` | loops over `$ids` only: the companies that own that number |

New method:

```php
/** @return array<string, list<int>> phone_number_id => company ids whose app_secret signs $rawBody */
public function verifiedCompanies(#[\SensitiveParameter] string $rawBody, #[\SensitiveParameter] ?string $header): array
```

1. The header must start with `sha256=`.
2. `json_decode`. Not an array → `[]`.
3. Collect the unique string values of `entry.*.changes.*.value.metadata.phone_number_id`, at most 20.
4. Load the matching rows: `CompanyIntegration::withoutCompany()->where('provider','whatsapp')->where('is_enabled',true)->whereIn('external_id',$ids)`.
5. Keep each row whose non-empty `app_secret` HMAC equals the header, compared with `hash_equals`.

Unsigned junk costs one indexed read and no write.

Behaviour changes:

- A STOP reply withdraws consent only in the companies that own the number. Before, it did so in every company.
- `whatsapp.configured` (`CompanyController.php:75`) becomes per company without an edit, because `isConfigured()` reads the current company.

### 4.4 Mail (TI-CORE)

- `config/mail.php:17` (default mailer) and `:115-118` (from) stay the platform fallback.
- NEW `app/Notifications/Channels/CompanyMailChannel.php extends MailChannel`.
- Bound in `AppServiceProvider::register()`: `$this->app->bind(MailChannel::class, CompanyMailChannel::class)`. `ChannelManager::createMailDriver()` resolves `MailChannel::class`.

```php
public function send($notifiable, Notification $notification)   // replaces parent body (vendor …/Channels/MailChannel.php:53-71); toMail() runs once
public static function mailerFor(CompanyIntegration $row): Mailer   // Mail::build([... 'timeout' => 5]) + alwaysFrom(from_address, from_name)
public static function check(CompanyIntegration $row, User $to): ?string   // §4.6
```

`send()`:

1. `$message = $notification->toMail($notifiable)`. It runs once, because `CustomerAccountCreated::toMail()` mints a reset token.
2. A `Mailable`, or a message with an explicit `->mailer(...)`, goes through the parent path.
3. Otherwise the company is resolved in this order:
   - the notification's `public int $companyId`, when that property exists;
   - `CompanyService::currentId()`;
   - `data_get($notifiable, 'company_id')`.
4. The company has an enabled `mail` row → `mailerFor($row)->send(...)`. Otherwise `$this->mailer->mailer(null)`, which is the platform default.

`shortcut: a queued notification without companyId sends a user with several memberships from the home company; add companyId to such notifications when that matters.`

`mailerFor()`:

- The transport is `smtp`, with `scheme` from the settings (`smtp` = STARTTLS when offered, `smtps` = implicit TLS).
- `settings.transport` may be `array` or `log` only when the env is local, testing or e2e. It is set only by tests or tinker, never by the UI.
- `TenantAdminInvitation::toMail()` adds `->mailer(config('mail.default'))`, so platform invitations always use the platform sender.

### 4.5 RADIUS (TI-RADIUS, cross-lane gated; contract)

Connection contract:

```php
// RadiusDirectory
public static function connectionName(int $companyId): string            // "radius_{$companyId}"
public static function connection(CompanyIntegration $row): Connection    // existing DB::connections() entry, else DB::build(array_merge(config('database.connections.radius'), [host, port, database, username, password from row], ['name' => self::connectionName($row->company_id), 'options' => [... PDO::ATTR_TIMEOUT => 5]]))
public function check(CompanyIntegration $integration): ?string          // §4.6; purges the connection afterwards
```

- `DB::build(['name' => …])` records the dynamic configuration (`DatabaseManager.php:117,218`), so a reconnect after a dropped link works. `connectUsing` alone would throw "Database connection [..] not configured." on reconnect.
- `config/database.php:69-87` stays the template for driver, charset and options. Runtime host, database and user come from the row. `RADIUS_DB_*` env values are the import source only.

| Today (`services.radius.enabled` reader) | Becomes |
|---|---|
| `RadiusDirectory.php:216` gate + `:221` `DB::connection('radius')` | `$row = app(IntegrationConfig::class)->current('radius')`; `! $row?->is_enabled` → `RadiusException::disabled()`; `$work(self::connection($row))` |
| `RadiusDisconnectClient.php:28` | `app(IntegrationConfig::class)->isEnabled('radius')`; `coa_timeout` (`:33`) stays platform config |
| `SpeedProfileRadiusObserver.php:19` | `isEnabled('radius')` |
| `NetworkAccountProvisioner.php:86` | `isEnabled('radius')` |
| `SyncNetworkDevices.php:22` | moves inside the per-company `runAs` (`:34-38`); when no company is enabled it prints "RADIUS is not configured for any company; nothing to sync." |
| `NasDeviceController.php:92`, `NetworkAccountController.php:68` (`radiusEnabled` prop) | `isEnabled('radius')` |
| `RadiusException.php:29` message | "RADIUS belum dikonfigurasi untuk perusahaan ini (Pengaturan → Integrasi)." |

Other changes:

- Purge: `NetworkAssetServiceProvider::boot()` adds `Queue::looping(fn () => collect(DB::getConnections())->keys()->filter(fn ($n) => str_starts_with($n, 'radius_'))->each(fn ($n) => DB::purge($n)))`. Saving a row purges it through `IntegrationConfig::forget()`.
- UI copy:
  - `NetworkAccounts/Show.tsx:434-440` and `NasDevices/Show.tsx:123-129` change "RADIUS dimatikan untuk instalasi ini…" to "RADIUS belum dikonfigurasi untuk perusahaan ini. Perubahan disimpan tetapi tidak dikirim ke FreeRADIUS." That is followed by the link "Atur di Pengaturan → Integrasi" (`/admin/company/integrations`), shown only when `usePermission` allows `company.integrations.manage`.
  - The E2E constants `radius-provisioning.spec.ts:68,235,298,906` follow.
- `fakeRadius()` keeps its signature, which the hardware lane uses:
  - The template becomes a SQLite file (`tempnam`), so every `radius_{id}` connection and `radiusConnection()` share one database. The file is removed in `beforeApplicationDestroyed`.
  - It writes an enabled `radius` row with `settings.database = <file>` for every existing company, and for each company created later through `Company::created`.
  - New helper `disableRadius()` replaces `config(['services.radius.enabled' => false])`.

### 4.6 "Uji koneksi" matrix

Every call has a 5 s budget. Messages never contain a secret, a response body or a phone number.

| Provider | Call | Timeout | Pass | Indonesian error (stored in `last_error`) |
|---|---|---|---|---|
| midtrans | `GET {api}/v2/INB-PING-{ULID}/status`, basic auth with the row's key, base from `endpoint()` | `timeout(5)->connectTimeout(3)` | HTTP 404, or 200 with body `status_code` `"404"` | HTTP 401 or body `"401"` → "Server key ditolak Midtrans. Periksa key dan mode: sandbox memakai awalan SB-Mid-server-, produksi Mid-server-."; `ConnectionException` → "Midtrans tidak dapat dihubungi dalam 5 detik."; ≥ 500 → "Midtrans sedang bermasalah (HTTP {status}). Coba lagi nanti."; other → "Jawaban Midtrans tidak terduga (HTTP {status})." |
| whatsapp | `GET https://graph.facebook.com/{graph_version}/{phone_number_id}?fields=display_phone_number,verified_name` with the row's token | `timeout(5)->connectTimeout(3)` | 200 and `id` equals `phone_number_id` | code 190 or HTTP 401 → "Access token ditolak Meta (kedaluwarsa atau dicabut)."; code 100 or HTTP 404 → "phone_number_id tidak ditemukan atau bukan milik token ini."; code 10/200-299 or HTTP 403 → "Token tidak punya izin whatsapp_business_messaging untuk nomor ini."; `ConnectionException` → "Meta tidak dapat dihubungi dalam 5 detik."; ≥ 500 → "Meta sedang bermasalah (HTTP {status})." |
| mail | `mailerFor($row)->raw("Email uji dari Inbils untuk {company}. Bila email ini sampai, pengaturan SMTP sudah benar.", to = the clicking admin's email, subject "Email uji Inbils")` | SMTP `timeout` 5 | the SMTP server accepted the message | connect or timeout → "Server SMTP tidak dapat dihubungi dalam 5 detik ({host}:{port})."; 535 → "Login SMTP ditolak. Periksa nama pengguna dan kata sandi."; TLS → "Gagal membuka TLS ke server SMTP. Periksa enkripsi dan port."; 550/553 on MAIL FROM → "Server SMTP menolak alamat pengirim {from_address}."; other → "Email uji gagal: server SMTP menjawab kode {code}." |
| radius | `connection($row)->select('select 1')`, then `Schema::connection(...)->hasTable()` for `radcheck`, `radreply`, `radusergroup`, `radgroupreply`, `nas`, `radacct` | PDO `ATTR_TIMEOUT` 5 | the select works and all six tables exist | refused or timeout → "Database RADIUS tidak dapat dihubungi dalam 5 detik ({host}:{port})."; SQLSTATE 28000/1045 → "Login database RADIUS ditolak. Periksa nama pengguna dan kata sandi."; 1049 → "Database {database} tidak ada di server RADIUS."; missing → "Tabel FreeRADIUS tidak lengkap: {names}." |

`isAllowedHost()` runs again in the job, after DNS resolution (`gethostbynamel`), for mail and RADIUS: "Host {host} tidak diizinkan (alamat lokal atau metadata)." Midtrans and Meta hosts are fixed.

The audit row is `AuditService::log('integration', $passed ? 'tested' : 'test_failed', ['provider' => …, 'error' => $message], $row)`. It holds the message only, never values.

### 4.7 Security

- **At rest.** `credentials` uses `encrypted:array` (APP_KEY). Rotation: move the old key into `APP_PREVIOUS_KEYS` (`config/app.php:103-106`). Old rows keep decrypting and are re-encrypted on their next save. Losing APP_KEY loses every integration secret, and admins re-enter them. DB backups hold ciphertext only.
- **Write-only.** `$hidden = ['credentials']`. Props carry `masked()` only. No API resource exposes rows. Secrets are never logged: `#[\SensitiveParameter]` on every method that takes one, Http exceptions logged by class basename only.
- **Step-up.** Every PUT needs a fresh TOTP or recovery code (`TwoFactorService::verifyChallenge`, `app/Services/Core/TwoFactorService.php:131`). The code is consumed, so a replay fails. An admin without 2FA cannot save; the page shows a link to `profile.edit`. An impersonating platform operator cannot save either, because the code is unknown.
- **Permission.** `company.integrations.manage` is given to admin only. The nav item is hidden without it.
- **Throttles:**

  | Action | Throttle |
  |---|---|
  | PUT | `throttle:6,1` |
  | test | `throttle:5,1`, plus the 60 s single-flight cache key |
  | push test | `api-push-test` 3/min per user |
  | webhooks | the existing junk limiters, made per company |

- **Audit.** Log name `integration`:

  | Action | Properties |
  |---|---|
  | `saved` | `{provider, fields: [names changed], is_enabled}` |
  | `tested` / `test_failed` | `{provider, error}` |
  | `imported_from_env` | `{provider}` |

  No values. `AUDIT_MODULE.integration` = "Integrasi".
- **Export and purge.**
  - `TenantExportBuilder::EXTRA_OMISSIONS` (`:28-31`) gains `'company_integrations' => ['credentials']`.
  - The FK cascade removes rows on purge. The table has `company_id`, so the registry finds it; `PurgeTenantCommandTest` and `TenantExportTest` confirm.
- **Platform.** Read-only states. The query selects no `credentials` column (§4.9).
- **SSRF** (OD3). Mail and RADIUS hosts must pass `isAllowedHost` at save and again at check, after DNS.
- **Webhooks.**
  - Midtrans: signature with the company's key; the attempt must belong to the company.
  - WhatsApp: HMAC per row; a payload is applied only to the verified companies of its number.
  - Unverified requests stay throttled per IP.
- Rule 10: TI-QA requests a security-reviewer pass over TI-CORE, TI-MIDTRANS, TI-WHATSAPP and TI-PUSH-API before "done".

### 4.8 Mobile push test (TI-PUSH-API)

`POST /api/v1/me/push-test`, in the `api.any` group of `routes/api.php`:

```php
Route::post('me/push-test', PushTestController::class)->middleware('throttle:api-push-test')->name('me.push-test');
```

- Auth: bearer token for either app. `Idempotency-Key` is enforced by the existing `EnforceIdempotency`: missing → 428; same key and same body → replay with `Idempotent-Replayed: true`; same key with another body → 422 `idempotency_conflict`.
- Limiter: `AppServiceProvider::registerApiRateLimiters()` (TI-CORE owns the file) adds `RateLimiter::for('api-push-test', fn (Request $r): Limit => Limit::perMinute(3)->by($perUser($r)))`.
- Request (`PushTestRequest`): `{ "token": string }`, required, max:4096 (the current FCM token of the calling install).
- Server steps:
  1. `! FcmSender::isConfigured()` → `throw new ApiException(503, 'push_unavailable', 'Notifikasi push belum aktif di server.')`. This is the same class `InvoicePayController.php:22` throws.
  2. Find the device with `DeviceToken::query()->where('user_id', $user->id)->where('token_hash', DeviceToken::hash($token))->where('personal_access_token_id', $user->currentAccessToken()->getKey())->reachable()->first()`. `null` → 409 `push_token_not_registered` "Perangkat ini belum terdaftar untuk notifikasi. Daftarkan ulang lalu coba lagi."
  3. Pick the notifiable of that app's bell: `customer` app → the user's `Customer` in the current company; `technician` → the `User`. This is the same audience rule as `FcmChannel::audience()`.
  4. `$notification = new PushTestNotification($device->id, CompanyService::current()->name)`, then `$notification->id = (string) Str::uuid()`, then `$notifiable->notify($notification)`. The notification is not queued: the database row exists before the response, and FCM goes through the queued `SendPushNotification`, so the web request makes no external call.
- 202 body:

```json
{ "data": { "notification_id": "uuid", "device": { "id": "ULID public_id", "name": "Pixel 7 | null" }, "wait_seconds": 30 } }
```

- Errors: 409 `push_token_not_registered`, 503 `push_unavailable`, 422 `validation_failed`, 428, 429 `too_many_requests` (with `Retry-After`). `config/scramble.php` lists both new codes in its error table (`:78-85`) and "3 push tests a minute per user" in its rate-limit line (`:114-117`). The OpenAPI change is additive.

NEW `app/Notifications/PushTestNotification.php`:

- `via` returns `['database', ...FcmChannel::for($notifiable)]`.
- `toArray` returns `['type' => 'push_test', 'company_name' => …]`.
- Properties `public readonly int $deviceTokenId`, `public readonly string $companyName`.

`NotificationResource`: adds `push_test` to `TYPES` and to the `@var` union. Title "Notifikasi uji", message "Notifikasi uji dari {company_name}." There is no subject.

`FcmChannel::send()` (`:44-56`) passes `$notification instanceof PushTestNotification ? $notification->deviceTokenId : null` as the new 4th argument of `SendPushNotification`.

`SendPushNotification`:

- The constructor gains `public readonly ?int $deviceTokenId = null`.
- The token query adds `->when($this->deviceTokenId, fn ($q, $id) => $q->whereKey($id))`.
- After `sent > 0`: `Cache::forever('push:last_sent_at', now()->toIso8601String())`. This is the platform-wide Firebase "last successful send" (spec decision 8). It is one key and holds no tenant data.

`inbils:push:test` (CLI) stays unchanged.

### 4.9 Platform status (TI-PLATFORM)

`TenantDetailQuery::props()` (`:48-93`) adds `integrations`:

- One query: `CompanyIntegration::withoutCompany()->whereIn('company_id', $rootAndSubsidiaryIds)->get(['company_id','provider','is_enabled','verified_at','last_error','last_checked_at'])`.
- The result is expanded to every company × provider, with `stateOf(null)` = `empty` for a missing row.

```ts
// resources/js/types/platform.ts
export interface PlatformTenantIntegration {
    company: { id: string; code: string; name: string };
    provider: IntegrationProvider;          // from '@/types/integrations'
    state: IntegrationState;
    is_enabled: boolean;
    last_checked_at: string | null;         // ISO 8601
}
// TenantDetail props gain: integrations: PlatformTenantIntegration[]
```

### 4.10 `inbils:integrations:import-env` (TI-CORE)

NEW `app/Console/Commands/ImportIntegrationsFromEnvCommand.php`. Signature:

```
inbils:integrations:import-env
    {--company= : Company code (required)}
    {--provider=* : midtrans, whatsapp or radius; default all three}
    {--force : Overwrite an existing row}
    {--dry-run : Print what would be written}
```

| Provider | Source | Row |
|---|---|---|
| midtrans | `config('services.midtrans.*')`, skipped when `server_key` is empty | credentials `{server_key}`; settings `{is_production, allow_sandbox_in_production, base_url}` |
| whatsapp | `config('services.whatsapp.*')`, skipped when the token or the phone id is empty | `external_id` = `phone_number_id`; credentials `{access_token, app_secret, webhook_verify_token}` |
| radius | only when `services.radius.enabled`; `config('database.connections.radius')` | settings `{host, port, database, username}`; credentials `{password}` |

- Imported rows get `is_enabled = true` and `verified_at = null`.
- Mail is never imported, because its env stays the platform fallback.
- An existing row without `--force` prints "{provider}: a row exists; rerun with --force to overwrite.". The other providers continue, and the exit code is FAILURE.
- Output prints masked values only ("server_key: tersimpan (••••1234)").
- Each written row gets an `imported_from_env` audit entry.
- An unknown company code → FAILURE "Company not found.".

Rollout. The owner runs it once per company, additive and never destructive:

```
./vendor/bin/sail artisan inbils:integrations:import-env --company=DEMO
./vendor/bin/sail artisan inbils:integrations:import-env --company=<AUDIT-ISP code>
```

On 8080 it is the same command through `DOCKER_CONTEXT=default docker compose --env-file .env.docker exec app php artisan …`.

`tests/e2e/setup.php` calls it for `DEMO` with `--force` right after `E2ePlatformSeeder` (`:79-84`). The `.env.e2e` Midtrans fake key and mock `base_url` therefore keep the payment specs working. The RADIUS lab runner already exports `RADIUS_*` (`scripts/e2e-radius-lab.sh:31-37`), so the lab run gets a DEMO radius row as well.

After the import, the runtime ignores `MIDTRANS_*`, `WHATSAPP_*` and `RADIUS_*` except `services.whatsapp.graph_version` and `services.radius.coa_timeout`. TI-DOCS documents this in `config/services.php` and `.env.example`.

## 5 UI contract

### 5.1 Web page — NEW `resources/js/Pages/Admin/Company/Integrations.tsx`

```ts
// NEW resources/js/types/integrations.ts
export type IntegrationProvider = 'midtrans' | 'whatsapp' | 'mail' | 'radius';
export type IntegrationState = 'empty' | 'filled' | 'verified' | 'failed';

export interface IntegrationCard {
    provider: IntegrationProvider;
    state: IntegrationState;
    is_enabled: boolean;
    checking: boolean;                       // Cache::has(integrations:checking:{company}:{provider})
    verified_at: string | null;              // ISO 8601
    last_checked_at: string | null;
    last_error: string | null;
    /** publicSettings(): midtrans {is_production}; whatsapp {phone_number_id}; mail {host, port, scheme, username, from_address, from_name}; radius {host, port, database, username} */
    settings: Record<string, string | number | boolean | null>;
    /** masked(): every SECRETS key, "tersimpan (••••1234)" | "tersimpan" | null */
    secrets: Record<string, string | null>;
}

export interface IntegrationsPageProps {
    integrations: Record<IntegrationProvider, IntegrationCard>;
    webhooks: { midtrans: string; whatsapp: string };          // route('webhooks.midtrans.company', public_id), url('/webhooks/whatsapp')
    firebase: { configured: boolean; last_sent_at: string | null };   // FcmSender::isConfigured(), Cache 'push:last_sent_at'
    mailFallback: { from_address: string | null };             // config('mail.from.address')
    twoFactorEnabled: boolean;
}
```

```ts
// NEW resources/js/lib/statuses/integration.ts (exported from statuses/index.ts)
export const INTEGRATION_STATE = {
    empty: { label: 'Tidak diisi', tone: 'neutral' },
    filled: { label: 'Terisi', tone: 'info' },
    verified: { label: 'Terverifikasi', tone: 'success' },
    failed: { label: 'Galat', tone: 'danger' },
} satisfies StatusMap;
export const INTEGRATION_PROVIDER = {
    midtrans: { label: 'Midtrans', tone: 'neutral' },
    whatsapp: { label: 'WhatsApp', tone: 'neutral' },
    mail: { label: 'Email', tone: 'neutral' },
    radius: { label: 'RADIUS', tone: 'neutral' },
} satisfies StatusMap;
```

**Layout.** The page uses `AdminLayout`.

- Page header: "Integrasi", with the description "Akun layanan milik {company}. Rahasia hanya bisa diganti, tidak bisa dilihat lagi."
- `!twoFactorEnabled` → a warning `Alert` at the top: "Aktifkan autentikasi dua langkah di Profil untuk menyimpan perubahan." It links "Buka Profil" to `profile.edit`. Every "Simpan" is disabled.
- One `Card` per provider, in the order Midtrans, WhatsApp, Email, RADIUS. Each card has:
  - A header: title, `EnumBadge` of `INTEGRATION_STATE`, and "Terakhir diuji {tanggal}" or "Belum pernah diuji".
  - `last_error` → a danger `Alert` with the text.
  - `Switch` "Aktif".
  - Secret inputs with `type="password"` and `autoComplete="new-password"`. The placeholder is the masked text or "Belum diisi", and the help text is "Kosongkan bila tidak diganti."
- Card fields:

  | Card | Fields |
  |---|---|
  | Midtrans | "Server key"; "Mode" (`NativeSelect`: Sandbox / Produksi); read-only "URL notifikasi" with `CopyButton` and the note "Dikirim otomatis ke Midtrans bila alamat aplikasi memakai https." |
  | WhatsApp | "ID nomor (phone_number_id)", "Access token", "App secret", "Verify token webhook"; read-only "URL webhook" with `CopyButton` and the note "Tempel di Meta App → WhatsApp → Configuration bersama verify token." |
  | Email | "Host SMTP", "Port", "Enkripsi" (STARTTLS / TLS), "Nama pengguna", "Kata sandi", "Alamat pengirim", "Nama pengirim". While off it shows "Selama nonaktif, email dikirim dari pengirim platform {mailFallback.from_address}." |
  | RADIUS | "Host", "Port", "Database", "Nama pengguna", "Kata sandi" |

- Card footer:
  - "Simpan" opens a `Modal` "Konfirmasi dengan kode autentikasi", with the field "Kode autentikasi" (`inputMode="numeric"`, `autoComplete="one-time-code"`) and the confirm button "Simpan". The card's `useForm` PUTs with `code`. A `code` error shows in the modal; other errors show under their fields.
  - "Uji koneksi" (secondary) is disabled when the state is `empty` or the check is running. While running it shows a spinner and "Menguji…". The Email card adds "Email uji dikirim ke alamat Anda."
- Read-only card "Notifikasi aplikasi (Firebase)":
  - "Kredensial server: Terpasang" or "Belum terpasang".
  - "Pengiriman terakhir berhasil: {tanggal}" or "Belum ada".
  - "Dikelola platform. Uji dari aplikasi: Akun → Notifikasi → Kirim notifikasi uji."

**Polling.** Inertia v2 `usePoll(2000, { only: ['integrations'] }, { autoStart: false })`. It starts when any card is `checking` and stops when none is. After 30 s it stops and shows "Uji koneksi masih berjalan; muat ulang halaman nanti."

**States:**

- empty: "Belum dikonfigurasi";
- saving: the button shows processing;
- success: flash toast;
- failure: field errors, `last_error` alert;
- checking: badge and spinner.

**Accessibility.**

- Every input has a `Label`.
- The status line of each card is `aria-live="polite"`.
- The modal traps focus (existing `Modal`).

**Copy** must pass `copy.selfcheck.mjs`, which already covers `Pages/Admin/Company/*.tsx` and `lib/statuses/*.ts`:

- "Email", "RADIUS", "Midtrans" and "WhatsApp" are kept terms.
- Avoid "Password", "Settings" and "Error". Use "Kata sandi", "Pengaturan" and "Galat".

**Navigation.** `navigation.ts` group `administration` gets, after "Perusahaan": `{ label: 'Integrasi', href: '/admin/company/integrations', icon: Plug, anyOf: ['company.integrations.manage'] }`. `permissionLabels.ts` gets `'company.integrations.manage': 'Kelola integrasi'`, and `permissionGroups.ts` puts it in the group that holds `company.manage`.

**Settings page.** `Settings.tsx:428-443` (WhatsApp alert, after integrity's edit) reads "WhatsApp belum dikonfigurasi untuk perusahaan ini." When `usePermission` allows `company.integrations.manage` it adds the link "Atur di Pengaturan → Integrasi"; otherwise "Minta admin mengisinya di Pengaturan → Integrasi." `tests/e2e/whatsapp-notifications.spec.ts:270-272` follows.

### 5.2 Platform block — `resources/js/Pages/Platform/Companies/Show.tsx`

A `Card` "Integrasi", with a table per company and provider:

| Column | Content |
|---|---|
| Perusahaan | `code` and `name` |
| Layanan | `INTEGRATION_PROVIDER` label |
| Status | `EnumBadge` `INTEGRATION_STATE` |
| Aktif | "Ya" / "Tidak" |
| Terakhir diuji | date or "Belum pernah" |

No values, no actions. When there are no rows at all, every cell reads "Tidak diisi" (the expansion of §4.9).

### 5.3 Flutter — both apps, Akun → Notifikasi

**Shared code.**

- NEW `packages/inbils_core/lib/src/screens/push_test_screen.dart` (`PushTestScreen`, Riverpod `ConsumerStatefulWidget`, pattern `devices_screen.dart`).
- `core_routes.dart`: `CoreRoutes.pushTest = '/account/notifications'`, plus a `fadeRoute` in `coreRoutes()`.
- `account_menu.dart`: a `ListTile` "Notifikasi" (`Icons.notifications_outlined`) between "Perangkat" and "Tentang aplikasi". Both apps get it through the shared menu, with no app-level change.

**Interfaces.**

```dart
// push_backend.dart
/// Opens the system notification settings of this app; false when no screen could be opened.
Future<bool> openNotificationSettings();
// firebase_push_backend.dart: _notifications.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()?.openAppNotificationSettings() ?? false
// fake_push_backend.dart: records the call; result configurable
// push_service.dart
/// Registers the current token again even when it was sent before (clears _requested, then start()).
Future<void> registerAgain();
// device_token_repository.dart
Future<PushTestResult> pushTest(String token);   // POST me/push-test {token}; Idempotency-Key by IdempotencyInterceptor
class PushTestResult { final String notificationId; final String? deviceName; final int waitSeconds; }
```

**Screen.** Title "Notifikasi". The primary button "Kirim notifikasi uji" is disabled while a run is active. There are three check rows (icon + text, `Semantics(liveRegion: true)`):

1. **"Izin notifikasi"** — `backend.permission()`:
   - `allowed` → "Diizinkan".
   - `undecided` → "Belum ditanyakan", with the button "Izinkan" (`requestPermission()`).
   - `blocked` → "Diblokir di pengaturan HP". Perbaiki: "Buka pengaturan" (`openNotificationSettings()`), re-checked on resume through `AppLifecycleListener`.
2. **"Token terdaftar di server"** — `backend.token()`, then `pushTest(token)`:
   - 202 → "Terdaftar ({deviceName})".
   - 409 `push_token_not_registered`, or no token → "Belum terdaftar". Perbaiki: "Daftarkan ulang" → `registerAgain()`, then the send button is enabled again.
   - 503 `push_unavailable` → "Notifikasi push belum aktif di server". Hint: "Hubungi admin ISP Anda: Firebase server belum dipasang."
   - 429 and offline → the existing `apiErrorMessage()` strings.
3. **"Pesan diterima"** — after 202 it listens to `backend.foregroundMessages`, filtered on `notificationId == result.notificationId`.
   - It counts down from `waitSeconds` with "Menunggu pesan… {n} dtk".
   - Received → "Diterima dalam {s} dtk". `PushService` already shows the heads-up.
   - Timeout → "Pesan tidak datang dalam 30 detik". Hint: "Periksa internet dan mode hemat baterai, lalu coba lagi. Bila tetap gagal, minta admin memeriksa status Firebase di Pengaturan → Integrasi."

Strings go in `packages/inbils_core/lib/src/l10n/core_id.arb`, keys prefixed `pushTest*`. The generated localizations are regenerated. There is no new pub dependency.

## 6 Test plan

| Slice | Tests (NEW unless "updated") | Command |
|---|---|---|
| TI-CORE | `tests/Feature/Admin/CompanyIntegrationsTest.php`: `test_the_page_needs_the_integrations_permission`, `test_the_page_never_contains_a_stored_secret`, `test_saving_requires_a_valid_authenticator_code`, `test_saving_without_two_factor_is_refused_with_a_profile_hint`, `test_a_blank_secret_keeps_the_stored_one`, `test_a_changed_field_clears_the_verification`, `test_saving_writes_an_audit_row_without_values`, `test_enabling_requires_the_required_fields` (dataset per provider), `test_the_midtrans_key_prefix_must_match_the_mode`, `test_hosts_in_blocked_ranges_are_refused`, `test_the_test_button_queues_one_check_and_marks_it_running`, `test_the_mail_check_records_success_and_failure`. `tests/Feature/Admin/IntegrationConfigTest.php`: `test_credentials_are_encrypted_at_rest`, `test_rows_are_memoised_and_forgotten_on_save`, `test_the_service_is_scoped_so_each_job_starts_fresh`, `test_masked_shows_only_the_last_four_of_long_secrets`. `tests/Feature/Admin/CompanyMailChannelTest.php`: `test_an_enabled_company_sends_from_its_own_address`, `test_without_a_row_the_platform_mailer_is_used`, `test_tenant_admin_invitation_stays_on_the_platform_mailer`, `test_to_mail_runs_once`. `tests/Feature/Admin/ImportIntegrationsFromEnvTest.php`: `test_it_imports_masked_and_audited`, `test_it_refuses_to_overwrite_without_force`, `test_dry_run_writes_nothing`, `test_mail_is_never_imported`. Updated `tests/Feature/Platform/TenantExportTest.php`: `test_integration_credentials_are_omitted`. | `./vendor/bin/sail artisan test --compact tests/Feature/Admin/CompanyIntegrationsTest.php tests/Feature/Admin/IntegrationConfigTest.php tests/Feature/Admin/CompanyMailChannelTest.php tests/Feature/Admin/ImportIntegrationsFromEnvTest.php tests/Feature/Platform/TenantExportTest.php tests/Feature/Platform/PurgeTenantCommandTest.php` |
| TI-MIDTRANS | `tests/Feature/Billing/MidtransPerCompanyTest.php`: `test_each_company_pays_into_its_own_merchant`, `test_snap_overrides_the_notification_url_with_the_company_webhook`, `test_the_company_webhook_verifies_with_that_company_key_only`, `test_the_company_webhook_cannot_touch_another_company_attempt`, `test_the_legacy_webhook_finds_the_company_from_the_order_id`, `test_a_company_without_midtrans_has_online_payment_off`, `test_the_check_passes_on_a_not_found_lookup`, `test_the_check_explains_a_rejected_key_with_the_prefix_hint`, `test_the_check_reports_a_timeout`, `test_reconcile_skips_companies_without_midtrans`, `test_the_webhook_throttle_counts_only_unverified_calls`. Updated: `MidtransGatewayTest.php` (`:106,:113,:160,:184,:187,:208,:222` become row writes; signature tests run inside `runAs`), `MidtransWebhookTest.php`, `MidtransReconcileCommandTest.php`, trait `InteractsWithMidtrans`. | `./vendor/bin/sail artisan test --compact tests/Feature/Billing tests/Feature/Api/V1/Customer` |
| TI-WHATSAPP | `tests/Feature/WhatsApp/WhatsAppPerCompanyTest.php`: `test_each_company_sends_from_its_own_number_and_token`, `test_a_company_without_a_row_is_skipped_as_disabled`, `test_the_webhook_routes_statuses_by_phone_number_id`, `test_a_payload_signed_with_another_company_secret_is_refused`, `test_a_stop_reply_opts_out_only_in_the_companies_of_that_number`, `test_the_handshake_accepts_any_company_verify_token`, `test_the_check_maps_meta_errors_to_indonesian` (dataset 190, 100, 10, 500, timeout). Updated: `WhatsAppWebhookTest.php` (`:107` 404→403, `:112`, `:147`, STOP cases `:339,:364`), `WhatsAppCloudClientTest.php` (`:34,:38,:203,:226,:239`), `WhatsAppPrivacyAndTenantTest.php` (`:400`), `WhatsAppCompanySettingsTest.php` (`:83`), trait `InteractsWithWhatsApp`. | `./vendor/bin/sail artisan test --compact tests/Feature/WhatsApp tests/Feature/Billing/InvoiceWhatsAppNotificationTest.php tests/Feature/Billing/InvoiceWhatsAppReminderCommandTest.php tests/Feature/SPK/WorkOrderWhatsAppNotificationTest.php tests/Feature/Ticketing/TicketWhatsAppNotificationTest.php` |
| TI-RADIUS | `tests/Feature/NetworkAsset/RadiusPerCompanyTest.php`: `test_each_company_writes_to_its_own_radius_connection`, `test_a_company_without_radius_is_disabled_with_the_settings_hint`, `test_saving_the_row_purges_the_open_connection`, `test_the_worker_purges_radius_connections_between_jobs`, `test_the_check_reports_missing_tables`, `test_the_check_reports_a_refused_login`. Updated (each `config(['services.radius.enabled' => …])` → `disableRadius()`): `SubscriptionConnectionTest.php:96`, `NasDeviceHttpTest.php:214,329`, `NetworkAccessGapTest.php:214`, `NetworkAccountHttpTest.php:201,250`, `NetworkSyncTest.php:274` (output text), `ProvisioningFlowTest.php:562`, `RadiusDirectoryTest.php:295`, `RadiusGapTest.php:195`, `tests/Unit/RadiusDisconnectClientTest.php:121,135`, `tests/Support/FakeDisconnectClient.php:35`, `tests/Feature/DemoDataSeederTest.php:201` (asserts there is no demo radius row). | `./vendor/bin/sail artisan test --compact tests/Feature/NetworkAsset tests/Feature/Api/V1/Customer/SubscriptionConnectionTest.php tests/Unit/RadiusDisconnectClientTest.php tests/Feature/DemoDataSeederTest.php` |
| TI-WEB | `navigation.selfcheck.mjs`: `Integrasi needs company.integrations.manage`. `statuses.selfcheck.mjs`: `INTEGRATION_STATE` keys equal `CompanyIntegration::STATES` (via `phpConstant`), and the label "Galat" is fixed. The permission and copy selfchecks run unchanged. | `npm run typecheck && npm run lint && node --test resources/js/lib/navigation.selfcheck.mjs resources/js/lib/statuses.selfcheck.mjs resources/js/lib/copy.selfcheck.mjs resources/js/Components/composite/permissionGroups.selfcheck.mjs` |
| TI-PLATFORM | Updated `tests/Feature/Platform/TenantDetailTest.php`: `test_the_tenant_page_lists_integration_states_without_values` (props have no `credentials` and no secret string; the query log has no `credentials` column). | `./vendor/bin/sail artisan test --compact tests/Feature/Platform/TenantDetailTest.php && npm run typecheck` |
| TI-PUSH-API | `tests/Feature/Push/PushTestApiTest.php`: `test_it_writes_a_bell_row_and_pushes_to_the_calling_install_only`, `test_a_token_of_another_session_is_not_registered`, `test_push_off_is_unavailable`, `test_it_allows_three_a_minute`, `test_it_needs_an_idempotency_key_and_replays_once`, `test_the_customer_app_notifies_the_customer_record`, `test_a_successful_send_records_the_last_sent_time`. Updated `FcmChannelTest.php` when the dispatched job assertion needs the 4th argument. | `./vendor/bin/sail artisan test --compact tests/Feature/Push tests/Feature/Admin/NotificationCenterTest.php` |
| TI-MOBILE | NEW `packages/inbils_core/test/screens/push_test_screen_test.dart`: permission allowed/undecided/blocked (opens settings), 202 + matching foreground message → three ok rows, 409 → "Daftarkan ulang" calls `registerAgain`, 503 → admin hint, no message → timeout after `pump(31 s)`, the AccountMenu entry opens the screen. Updated `packages/inbils_core/test/push/push_service_test.dart`: `registerAgain re-sends a token already sent`. | `cd /home/madmouse/Documents/web/inbils-mobile/packages/inbils_core && ~/development/flutter/bin/flutter analyze && ~/development/flutter/bin/flutter test && cd ../../customer && ~/development/flutter/bin/flutter test && cd ../technician && ~/development/flutter/bin/flutter test` |
| TI-QA | NEW `tests/Feature/Integrations/NoRuntimeConfigWritesTest.php`: `test_app_and_modules_never_write_config_at_runtime` (scans `app/` and `Modules/*/app` for `config([`). NEW `tests/e2e/tenant-integrations.spec.ts` (`@integrations`): a disposable admin enrols 2FA (`tests/e2e/support/totp.ts`), opens Pengaturan → Integrasi, sees DEMO's imported Midtrans "tersimpan (••••…)" but never the key, saves with a code, "Uji koneksi" → "Terverifikasi" against the mock `base_url`, a wrong code shows the modal error, the Firebase card is read-only, then the platform operator sees the states on the tenant page. | `flock /tmp/inbils-e2e.lock sh -c 'npm run build && node scripts/e2e-setup.mjs && npm run test:e2e -- specs --project=chromium --workers=1'`, then the full gate of §8.3 |
| TI-DOCS | `tests/Feature/Api/V1/OpenApiContractTest.php` green after the export | `./vendor/bin/sail artisan scramble:export --path=docs/api/mobile-v1.openapi.json && ./vendor/bin/sail artisan test --compact tests/Feature/Api/V1/OpenApiContractTest.php && ./vendor/bin/sail artisan inbils:docs:pdf` |

Test traits keep their call order working. `enableMidtrans()` and `enableWhatsApp()` write enabled rows for every existing company and register `Company::created` to write one for each later company. The 5 tests that enable before a company exists therefore need no reorder: `InvoicesTest:51`, `PayTest:47`, `PaymentsTest:46`, `PaymentFinishedPageTest:41`, `BuildsMatrixWorld:92`. `disableMidtrans()` and `disableWhatsApp()` set `is_enabled = false` on every row and stop the listener.

## 7 Risks and open decisions

### Open decisions (recommended default first)

- **OD1 "Uji koneksi" runs queued.** The default is a queued job with polling (hard rule 13: no external call in a web request). The alternative is a synchronous call with a 5 s timeout, which is simpler but breaks rule 13.
- **OD2 WhatsApp keeps the shared `/webhooks/whatsapp`**, routed by `phone_number_id` (spec decision 5). The alternative, `/webhooks/whatsapp/{company}`, would need every ISP to re-save its Meta callback.
- **OD3 Host policy.** The default blocks loopback, link-local/metadata, unspecified and multicast, and allows RFC 1918 (the ISP LAN holds RADIUS and SMTP). Loopback is allowed in local, testing and e2e.
- **OD4 TI-RADIUS runs last in wave 2 (W2b)**, after hardware-panel 03/05 and master-lifecycle W2-NETWORK-BE are accepted. Without it RADIUS stays install-wide, against spec decision 1.
- **OD5 Platform label "Galat"** replaces the spec's "error". The copy selfcheck denies "Error" (`copy.selfcheck.mjs:207`), and the state key stays `failed`.
- **OD6 Step-up on every save**, not only when a secret changes. That is one rule, and a toggle off is rare.
- **OD7 No inheritance.** A subsidiary configures its own rows.
- **OD8 Midtrans client key and merchant id are not stored.** Nothing reads them. Add them when Snap.js popup mode arrives.

### Risks

- **R1 Config bleed in workers.**
  - `#[Scoped]` service, no runtime config writes (guard test), per-job `runAs`, RADIUS purge on `Queue::looping`, mail built per send (§3.5).
  - Any future code that calls `config([...])` for a provider fails the guard test.
- **R2 Encrypted column size.** The `text` column is 64 KB against at most ~3 KB of ciphertext.
- **R3 E2E env.** Specs that rely on `MIDTRANS_*` from `.env.e2e` fail until `tests/e2e/setup.php` runs the import. TI-QA owns that line, so E2E is only meaningful after W3.
- **R4 Cross-lane gates** (§8.4). A gated slice that starts early would overwrite another lane's lines.
- **R5 WhatsApp STOP** now applies only to the companies of the number. Existing tests change on purpose.
- **R6 Messages change.** `whatsapp.configured` becomes per company, and the reconcile and sync outputs change. The "nothing to do" lines stay when no company is enabled.
- **R7 The legacy Midtrans webhook** reads one attempt row before verifying (indexed). Remove the route after the migration period; no date is set (deferred).
- **R8 Mail company resolution** for queued notifications without `companyId` uses the notifiable's home company (shortcut comment in code).
- **R9 APP_KEY loss** loses every integration secret. Rotation through `APP_PREVIOUS_KEYS` works.
- **R10 TOTP.** One code per 30 s step: a second save in the same step needs the next code.
- **R11 Unverifiable app secret.** "Uji koneksi" cannot verify the WhatsApp `app_secret`, so a wrong secret shows only as webhook 403 in the logs. A "last webhook received" timestamp is deferred.
- **R12 Shared SQLite file.** `fakeRadius()` on a SQLite file is slightly slower than `:memory:`. The hardware lane's `fakeRadius()` callers are unaffected.
- **R13 Dev and 8080 downtime.** Midtrans and WhatsApp are off on dev and 8080 from deploy until the owner runs the import (§4.10). Run it right after `migrate`.

## 8 Work breakdown

### 8.1 Waves

- **W1:** TI-CORE.
- **W2, parallel and capped at 5 agents:** TI-MIDTRANS (gated on integrity W1-BILLING), TI-WHATSAPP, TI-WEB, TI-PLATFORM, TI-PUSH-API, TI-MOBILE. TI-MOBILE needs only §4.8 and may start in W1.
- **W2b:** TI-RADIUS (gated, §8.4).
- **W3:** TI-QA, then TI-DOCS.

Every slice skips build, lint and test runs mid-flight and runs only its own acceptance command once at the end. Builds and E2E run serialized under `flock /tmp/inbils-build.lock` / `flock /tmp/inbils-e2e.lock` after checking `uptime` (load < 8).

### 8.2 Slices

**TI-CORE · backend · storage, resolution, mail, page API, checks, import**

- Owned:
  - NEW `database/migrations/2026_10_26_100000_create_company_integrations_table.php`;
  - NEW `database/migrations/2026_10_26_100100_add_company_integrations_manage_permission.php`;
  - `database/seeders/RolePermissionSeeder.php`;
  - NEW `app/Models/Core/CompanyIntegration.php`;
  - NEW `app/Services/Core/IntegrationConfig.php`;
  - NEW `app/Notifications/Channels/CompanyMailChannel.php`;
  - `app/Providers/AppServiceProvider.php`;
  - `app/Notifications/Platform/TenantAdminInvitation.php`;
  - NEW `app/Http/Controllers/Admin/CompanyIntegrationController.php`;
  - NEW `app/Http/Requests/Admin/UpdateCompanyIntegrationRequest.php`;
  - NEW `app/Jobs/TestCompanyIntegration.php`;
  - NEW `app/Console/Commands/ImportIntegrationsFromEnvCommand.php`;
  - `routes/admin.php` (the §4.1 block only);
  - `app/Services/Platform/TenantExportBuilder.php`;
  - NEW `tests/Feature/Admin/CompanyIntegrationsTest.php`;
  - NEW `tests/Feature/Admin/IntegrationConfigTest.php`;
  - NEW `tests/Feature/Admin/CompanyMailChannelTest.php`;
  - NEW `tests/Feature/Admin/ImportIntegrationsFromEnvTest.php`;
  - `tests/Feature/Platform/TenantExportTest.php`.
- Change: §3, §4.1, §4.4, §4.6 (job + mail check), §4.7, §4.10, plus the `api-push-test` limiter (§4.8).
- Produces: `CompanyIntegration` API, `IntegrationConfig`, routes and props §5.1, the `check()` contract, `CompanyMailChannel::mailerFor`, limiter `api-push-test`, permission `company.integrations.manage`.
- Consumes: the four `check()` methods, by name.
- Depends on: master-lifecycle W2-CORE-BE for `routes/admin.php`. Everything else can start at once.
- Acceptance:
  - The TI-CORE command of §6 is green.
  - `vendor/bin/pint --dirty --format agent` is clean.
  - The pasted `EXPLAIN` shows the platform/webhook lookups `where provider = ? and external_id in (…)` and `where company_id = ? and provider = ?` using `company_integrations_provider_external_id_index` and the unique key.

**TI-MIDTRANS · backend · §4.2**

- Owned:
  - `Modules/Billing/app/Services/MidtransGateway.php`;
  - `Modules/Billing/app/Http/Controllers/MidtransWebhookController.php`;
  - `Modules/Billing/app/Providers/RouteServiceProvider.php`;
  - `Modules/Billing/routes/webhook.php`;
  - `Modules/Billing/app/Console/ReconcileMidtransPayments.php`;
  - `tests/Traits/InteractsWithMidtrans.php`;
  - `tests/Feature/Billing/MidtransGatewayTest.php`;
  - `tests/Feature/Billing/MidtransWebhookTest.php`;
  - `tests/Feature/Billing/MidtransReconcileCommandTest.php`;
  - NEW `tests/Feature/Billing/MidtransPerCompanyTest.php`.
- Consumes: `IntegrationConfig`, `CompanyIntegration`.
- Produces: `MidtransGateway::check()`, `companyIdForNotification()`, route `webhooks.midtrans.company`.
- Depends on: TI-CORE; integrity W1-BILLING accepted for the gateway, reconcile, trait and reconcile test.
- Acceptance: the TI-MIDTRANS command of §6 is green.

**TI-WHATSAPP · backend · §4.3**

- Owned:
  - `app/Services/WhatsApp/WhatsAppCloudClient.php`;
  - `app/Http/Controllers/WhatsAppWebhookController.php`;
  - `app/Services/WhatsApp/WhatsAppWebhookHandler.php`;
  - `tests/Traits/InteractsWithWhatsApp.php`;
  - `tests/Feature/WhatsApp/WhatsAppWebhookTest.php`;
  - `tests/Feature/WhatsApp/WhatsAppCloudClientTest.php`;
  - `tests/Feature/WhatsApp/WhatsAppPrivacyAndTenantTest.php`;
  - `tests/Feature/WhatsApp/WhatsAppCompanySettingsTest.php`;
  - NEW `tests/Feature/WhatsApp/WhatsAppPerCompanyTest.php`.
- Produces: `WhatsAppCloudClient::check()`, `verifiedCompanies()`, `WhatsAppWebhookHandler::handle($payload, $companies)`.
- Depends on: TI-CORE.
- Acceptance: the TI-WHATSAPP command of §6 is green.

**TI-RADIUS · backend · §4.5**

- Owned:
  - `Modules/NetworkAsset/app/Services/Radius/RadiusDirectory.php`;
  - `Modules/NetworkAsset/app/Services/Radius/RadiusDisconnectClient.php`;
  - `Modules/NetworkAsset/app/Services/Radius/RadiusException.php`;
  - `Modules/NetworkAsset/app/Observers/SpeedProfileRadiusObserver.php`;
  - `Modules/NetworkAsset/app/Services/NetworkAccountProvisioner.php`;
  - `Modules/NetworkAsset/app/Console/SyncNetworkDevices.php`;
  - `Modules/NetworkAsset/app/Http/Controllers/NetworkAccountController.php`;
  - `Modules/NetworkAsset/app/Http/Controllers/NasDeviceController.php`;
  - `Modules/NetworkAsset/app/Providers/NetworkAssetServiceProvider.php`;
  - `resources/js/Pages/Admin/NetworkAccounts/Show.tsx`;
  - `resources/js/Pages/Admin/NasDevices/Show.tsx`;
  - `tests/Traits/InteractsWithRadius.php`;
  - `tests/Support/FakeDisconnectClient.php`;
  - `tests/e2e/radius-provisioning.spec.ts`;
  - `tests/Feature/Api/V1/Customer/SubscriptionConnectionTest.php`;
  - `tests/Feature/NetworkAsset/NasDeviceHttpTest.php`;
  - `tests/Feature/NetworkAsset/NetworkAccessGapTest.php`;
  - `tests/Feature/NetworkAsset/NetworkAccountHttpTest.php`;
  - `tests/Feature/NetworkAsset/NetworkSyncTest.php`;
  - `tests/Feature/NetworkAsset/ProvisioningFlowTest.php`;
  - `tests/Feature/NetworkAsset/RadiusDirectoryTest.php`;
  - `tests/Feature/NetworkAsset/RadiusGapTest.php`;
  - `tests/Unit/RadiusDisconnectClientTest.php`;
  - `tests/Feature/DemoDataSeederTest.php`;
  - NEW `tests/Feature/NetworkAsset/RadiusPerCompanyTest.php`.
- Produces: `RadiusDirectory::connectionName()`, `connection()`, `check()`; `fakeRadius()` with an unchanged signature; `disableRadius()`.
- Depends on: TI-CORE; hardware-panel 03 (RadiusDirectory, NasDeviceController) and 05 (NasDevices/Show.tsx); master-lifecycle W2-NETWORK-BE (Provisioner, Sync, NetworkAccountController).
- Acceptance:
  - The TI-RADIUS command of §6 is green.
  - `npm run typecheck`.
  - The copy selfcheck (`Pages/Admin/NasDevices/*.tsx`, `NetworkAccounts/*.tsx` are converted).

**TI-WEB · frontend · §5.1**

- Owned:
  - NEW `resources/js/Pages/Admin/Company/Integrations.tsx`;
  - NEW `resources/js/types/integrations.ts`;
  - NEW `resources/js/lib/statuses/integration.ts`;
  - `resources/js/lib/statuses/index.ts`;
  - `resources/js/lib/statuses/audit.ts`;
  - `resources/js/lib/statuses.selfcheck.mjs`;
  - `resources/js/lib/navigation.ts`;
  - `resources/js/lib/navigation.selfcheck.mjs`;
  - `resources/js/Components/composite/permissionLabels.ts`;
  - `resources/js/Components/composite/permissionGroups.ts`;
  - `resources/js/Pages/Admin/Company/Settings.tsx`;
  - `tests/e2e/whatsapp-notifications.spec.ts`.
- Consumes: §5.1 props and the §4.1 route names.
- Produces: `INTEGRATION_STATE`, `INTEGRATION_PROVIDER`, `IntegrationProvider`, `IntegrationState`.
- Depends on: the TI-CORE contract only, so it runs in parallel; integrity accepted for `Settings.tsx`.
- Acceptance: the TI-WEB command of §6 is green.

**TI-PLATFORM · backend · §4.9, §5.2**

- Owned:
  - `app/Queries/Platform/TenantDetailQuery.php`;
  - `resources/js/types/platform.ts`;
  - `resources/js/Pages/Platform/Companies/Show.tsx`;
  - `tests/Feature/Platform/TenantDetailTest.php`.
- Consumes: `CompanyIntegration::stateOf`, `INTEGRATION_STATE`, `INTEGRATION_PROVIDER`, `IntegrationProvider` (TI-WEB names; parallel).
- Depends on: TI-CORE.
- Acceptance: the TI-PLATFORM command of §6 is green.

**TI-PUSH-API · backend · §4.8**

- Owned:
  - NEW `app/Http/Controllers/Api/V1/PushTestController.php`;
  - NEW `app/Http/Requests/Api/V1/PushTestRequest.php`;
  - NEW `app/Notifications/PushTestNotification.php`;
  - `routes/api.php`;
  - `app/Notifications/Channels/FcmChannel.php`;
  - `app/Jobs/SendPushNotification.php`;
  - `app/Http/Resources/NotificationResource.php`;
  - `config/scramble.php`;
  - NEW `tests/Feature/Push/PushTestApiTest.php`;
  - `tests/Feature/Push/FcmChannelTest.php`.
- Consumes: limiter `api-push-test` (TI-CORE).
- Produces: the §4.8 endpoint and the `push:last_sent_at` cache key.
- Depends on: TI-CORE; integrity accepted for `NotificationResource.php`.
- Acceptance: the TI-PUSH-API command of §6 is green.

**TI-MOBILE · mobile · §5.3** (repo `/home/madmouse/Documents/web/inbils-mobile`; brief must name the `flutter-*`/`dart-*` skills)

- Owned:
  - NEW `packages/inbils_core/lib/src/screens/push_test_screen.dart`;
  - `packages/inbils_core/lib/src/push/push_backend.dart`;
  - `packages/inbils_core/lib/src/push/firebase_push_backend.dart`;
  - `packages/inbils_core/lib/src/push/push_service.dart`;
  - `packages/inbils_core/lib/src/push/device_token_repository.dart`;
  - `packages/inbils_core/lib/src/testing/fake_push_backend.dart`;
  - `packages/inbils_core/lib/src/routing/core_routes.dart`;
  - `packages/inbils_core/lib/src/widgets/account_menu.dart`;
  - `packages/inbils_core/lib/src/l10n/core_id.arb`;
  - `packages/inbils_core/lib/src/l10n/generated/*` (regenerated);
  - NEW `packages/inbils_core/test/screens/push_test_screen_test.dart`;
  - `packages/inbils_core/test/push/push_service_test.dart`.
- Consumes: §4.8 JSON.
- Depends on: the contract only.
- Acceptance: the TI-MOBILE command of §6 is green.

**TI-QA · qa · guard, E2E, full gate, security review**

- Owned:
  - NEW `tests/Feature/Integrations/NoRuntimeConfigWritesTest.php`;
  - NEW `tests/e2e/tenant-integrations.spec.ts`;
  - `tests/e2e/setup.php` (the §4.10 import call).
- Depends on: every W2 slice and TI-RADIUS.
- Acceptance, run once in order:
  1. `./vendor/bin/sail artisan test --compact` (full);
  2. `composer analyse`;
  3. `vendor/bin/pint --test`;
  4. `npm run typecheck && npm run lint && npm run build` (under the build lock) plus the four selfchecks;
  5. the E2E command of §6 (canonical suite plus `tenant-integrations.spec.ts`);
  6. the security-reviewer pass with findings fixed or listed.

**TI-DOCS · backend · docs, config comments, OpenAPI, guides**

- Owned:
  - `config/services.php` (comments: import source only; `graph_version`/`coa_timeout` stay runtime);
  - `.env.example`;
  - `docs/user-guide/web.md` (section "Pengaturan → Integrasi");
  - `docs/user-guide/teknisi.md` and `docs/user-guide/pelanggan.md` (section "Notifikasi uji");
  - `docs/user-guide/pdf/web.pdf`, `docs/user-guide/pdf/teknisi.pdf`, `docs/user-guide/pdf/pelanggan.pdf` (regenerated by `inbils:docs:pdf`);
  - `docs/operations/push-notifications.md` (the in-app test and the platform status card);
  - `CHANGELOG.md`;
  - `docs/api/mobile-v1.openapi.json` (regenerated).
- Depends on: TI-QA green; master-lifecycle W3-DOCS and integrity docs accepted for `web.md`, `teknisi.md`, `CHANGELOG.md` and the OpenAPI export.
- Acceptance: the TI-DOCS command of §6 is green.

### 8.3 File-ownership matrix (each path once)

| Path | Slice |
|---|---|
| NEW `database/migrations/2026_10_26_100000_create_company_integrations_table.php` | TI-CORE |
| NEW `database/migrations/2026_10_26_100100_add_company_integrations_manage_permission.php` | TI-CORE |
| `database/seeders/RolePermissionSeeder.php` | TI-CORE |
| NEW `app/Models/Core/CompanyIntegration.php` | TI-CORE |
| NEW `app/Services/Core/IntegrationConfig.php` | TI-CORE |
| NEW `app/Notifications/Channels/CompanyMailChannel.php` | TI-CORE |
| `app/Providers/AppServiceProvider.php` | TI-CORE |
| `app/Notifications/Platform/TenantAdminInvitation.php` | TI-CORE |
| NEW `app/Http/Controllers/Admin/CompanyIntegrationController.php` | TI-CORE |
| NEW `app/Http/Requests/Admin/UpdateCompanyIntegrationRequest.php` | TI-CORE |
| NEW `app/Jobs/TestCompanyIntegration.php` | TI-CORE |
| NEW `app/Console/Commands/ImportIntegrationsFromEnvCommand.php` | TI-CORE |
| `routes/admin.php` | TI-CORE |
| `app/Services/Platform/TenantExportBuilder.php` | TI-CORE |
| NEW `tests/Feature/Admin/CompanyIntegrationsTest.php` | TI-CORE |
| NEW `tests/Feature/Admin/IntegrationConfigTest.php` | TI-CORE |
| NEW `tests/Feature/Admin/CompanyMailChannelTest.php` | TI-CORE |
| NEW `tests/Feature/Admin/ImportIntegrationsFromEnvTest.php` | TI-CORE |
| `tests/Feature/Platform/TenantExportTest.php` | TI-CORE |
| `Modules/Billing/app/Services/MidtransGateway.php` | TI-MIDTRANS |
| `Modules/Billing/app/Http/Controllers/MidtransWebhookController.php` | TI-MIDTRANS |
| `Modules/Billing/app/Providers/RouteServiceProvider.php` | TI-MIDTRANS |
| `Modules/Billing/routes/webhook.php` | TI-MIDTRANS |
| `Modules/Billing/app/Console/ReconcileMidtransPayments.php` | TI-MIDTRANS |
| `tests/Traits/InteractsWithMidtrans.php` | TI-MIDTRANS |
| `tests/Feature/Billing/MidtransGatewayTest.php` | TI-MIDTRANS |
| `tests/Feature/Billing/MidtransWebhookTest.php` | TI-MIDTRANS |
| `tests/Feature/Billing/MidtransReconcileCommandTest.php` | TI-MIDTRANS |
| NEW `tests/Feature/Billing/MidtransPerCompanyTest.php` | TI-MIDTRANS |
| `app/Services/WhatsApp/WhatsAppCloudClient.php` | TI-WHATSAPP |
| `app/Http/Controllers/WhatsAppWebhookController.php` | TI-WHATSAPP |
| `app/Services/WhatsApp/WhatsAppWebhookHandler.php` | TI-WHATSAPP |
| `tests/Traits/InteractsWithWhatsApp.php` | TI-WHATSAPP |
| `tests/Feature/WhatsApp/WhatsAppWebhookTest.php` | TI-WHATSAPP |
| `tests/Feature/WhatsApp/WhatsAppCloudClientTest.php` | TI-WHATSAPP |
| `tests/Feature/WhatsApp/WhatsAppPrivacyAndTenantTest.php` | TI-WHATSAPP |
| `tests/Feature/WhatsApp/WhatsAppCompanySettingsTest.php` | TI-WHATSAPP |
| NEW `tests/Feature/WhatsApp/WhatsAppPerCompanyTest.php` | TI-WHATSAPP |
| `Modules/NetworkAsset/app/Services/Radius/RadiusDirectory.php` | TI-RADIUS |
| `Modules/NetworkAsset/app/Services/Radius/RadiusDisconnectClient.php` | TI-RADIUS |
| `Modules/NetworkAsset/app/Services/Radius/RadiusException.php` | TI-RADIUS |
| `Modules/NetworkAsset/app/Observers/SpeedProfileRadiusObserver.php` | TI-RADIUS |
| `Modules/NetworkAsset/app/Services/NetworkAccountProvisioner.php` | TI-RADIUS |
| `Modules/NetworkAsset/app/Console/SyncNetworkDevices.php` | TI-RADIUS |
| `Modules/NetworkAsset/app/Http/Controllers/NetworkAccountController.php` | TI-RADIUS |
| `Modules/NetworkAsset/app/Http/Controllers/NasDeviceController.php` | TI-RADIUS |
| `Modules/NetworkAsset/app/Providers/NetworkAssetServiceProvider.php` | TI-RADIUS |
| `resources/js/Pages/Admin/NetworkAccounts/Show.tsx` | TI-RADIUS |
| `resources/js/Pages/Admin/NasDevices/Show.tsx` | TI-RADIUS |
| `tests/Traits/InteractsWithRadius.php` | TI-RADIUS |
| `tests/Support/FakeDisconnectClient.php` | TI-RADIUS |
| `tests/e2e/radius-provisioning.spec.ts` | TI-RADIUS |
| `tests/Feature/Api/V1/Customer/SubscriptionConnectionTest.php` | TI-RADIUS |
| `tests/Feature/NetworkAsset/NasDeviceHttpTest.php` | TI-RADIUS |
| `tests/Feature/NetworkAsset/NetworkAccessGapTest.php` | TI-RADIUS |
| `tests/Feature/NetworkAsset/NetworkAccountHttpTest.php` | TI-RADIUS |
| `tests/Feature/NetworkAsset/NetworkSyncTest.php` | TI-RADIUS |
| `tests/Feature/NetworkAsset/ProvisioningFlowTest.php` | TI-RADIUS |
| `tests/Feature/NetworkAsset/RadiusDirectoryTest.php` | TI-RADIUS |
| `tests/Feature/NetworkAsset/RadiusGapTest.php` | TI-RADIUS |
| `tests/Unit/RadiusDisconnectClientTest.php` | TI-RADIUS |
| `tests/Feature/DemoDataSeederTest.php` | TI-RADIUS |
| NEW `tests/Feature/NetworkAsset/RadiusPerCompanyTest.php` | TI-RADIUS |
| NEW `resources/js/Pages/Admin/Company/Integrations.tsx` | TI-WEB |
| NEW `resources/js/types/integrations.ts` | TI-WEB |
| NEW `resources/js/lib/statuses/integration.ts` | TI-WEB |
| `resources/js/lib/statuses/index.ts` | TI-WEB |
| `resources/js/lib/statuses/audit.ts` | TI-WEB |
| `resources/js/lib/statuses.selfcheck.mjs` | TI-WEB |
| `resources/js/lib/navigation.ts` | TI-WEB |
| `resources/js/lib/navigation.selfcheck.mjs` | TI-WEB |
| `resources/js/Components/composite/permissionLabels.ts` | TI-WEB |
| `resources/js/Components/composite/permissionGroups.ts` | TI-WEB |
| `resources/js/Pages/Admin/Company/Settings.tsx` | TI-WEB |
| `tests/e2e/whatsapp-notifications.spec.ts` | TI-WEB |
| `app/Queries/Platform/TenantDetailQuery.php` | TI-PLATFORM |
| `resources/js/types/platform.ts` | TI-PLATFORM |
| `resources/js/Pages/Platform/Companies/Show.tsx` | TI-PLATFORM |
| `tests/Feature/Platform/TenantDetailTest.php` | TI-PLATFORM |
| NEW `app/Http/Controllers/Api/V1/PushTestController.php` | TI-PUSH-API |
| NEW `app/Http/Requests/Api/V1/PushTestRequest.php` | TI-PUSH-API |
| NEW `app/Notifications/PushTestNotification.php` | TI-PUSH-API |
| `routes/api.php` | TI-PUSH-API |
| `app/Notifications/Channels/FcmChannel.php` | TI-PUSH-API |
| `app/Jobs/SendPushNotification.php` | TI-PUSH-API |
| `app/Http/Resources/NotificationResource.php` | TI-PUSH-API |
| `config/scramble.php` | TI-PUSH-API |
| NEW `tests/Feature/Push/PushTestApiTest.php` | TI-PUSH-API |
| `tests/Feature/Push/FcmChannelTest.php` | TI-PUSH-API |
| NEW `packages/inbils_core/lib/src/screens/push_test_screen.dart` | TI-MOBILE |
| `packages/inbils_core/lib/src/push/push_backend.dart` | TI-MOBILE |
| `packages/inbils_core/lib/src/push/firebase_push_backend.dart` | TI-MOBILE |
| `packages/inbils_core/lib/src/push/push_service.dart` | TI-MOBILE |
| `packages/inbils_core/lib/src/push/device_token_repository.dart` | TI-MOBILE |
| `packages/inbils_core/lib/src/testing/fake_push_backend.dart` | TI-MOBILE |
| `packages/inbils_core/lib/src/routing/core_routes.dart` | TI-MOBILE |
| `packages/inbils_core/lib/src/widgets/account_menu.dart` | TI-MOBILE |
| `packages/inbils_core/lib/src/l10n/core_id.arb` | TI-MOBILE |
| `packages/inbils_core/lib/src/l10n/generated/*` | TI-MOBILE |
| NEW `packages/inbils_core/test/screens/push_test_screen_test.dart` | TI-MOBILE |
| `packages/inbils_core/test/push/push_service_test.dart` | TI-MOBILE |
| NEW `tests/Feature/Integrations/NoRuntimeConfigWritesTest.php` | TI-QA |
| NEW `tests/e2e/tenant-integrations.spec.ts` | TI-QA |
| `tests/e2e/setup.php` | TI-QA |
| `config/services.php` | TI-DOCS |
| `.env.example` | TI-DOCS |
| `docs/user-guide/web.md` | TI-DOCS |
| `docs/user-guide/teknisi.md` | TI-DOCS |
| `docs/user-guide/pelanggan.md` | TI-DOCS |
| `docs/user-guide/pdf/web.pdf` | TI-DOCS |
| `docs/user-guide/pdf/teknisi.pdf` | TI-DOCS |
| `docs/user-guide/pdf/pelanggan.pdf` | TI-DOCS |
| `docs/operations/push-notifications.md` | TI-DOCS |
| `CHANGELOG.md` | TI-DOCS |
| `docs/api/mobile-v1.openapi.json` | TI-DOCS |

There are 115 rows, and no path appears twice.

Read but never edited:

- `CompanyController.php`, `WhatsAppChannel.php`, `PaymentAttemptService.php`, `BillingService.php`, `PaymentAttempt.php`;
- `InvoicePayController.php`, `InvoiceController.php`;
- `bootstrap/app.php`, `HandleInertiaRequests.php`, `Company.php`, `config/database.php`, `config/mail.php`;
- `PushTestCommand.php`, `FcmSender.php`, `DeviceToken.php`, `DeviceTokenService.php`.

### 8.4 Cross-lane gates (first owner lands first; the TI slice keeps its lines)

| File | First owner | TI slice | TI edit only |
|---|---|---|---|
| `Modules/Billing/app/Services/MidtransGateway.php` | integrity W1-BILLING (`cancelTransaction`) | TI-MIDTRANS | config reads → row, `check()`, `companyIdForNotification()`, per-company override URL; `cancelTransaction` untouched |
| `Modules/Billing/app/Console/ReconcileMidtransPayments.php` | integrity W1-BILLING | TI-MIDTRANS | `isEnabled()` into the per-company loop |
| `tests/Traits/InteractsWithMidtrans.php`, `tests/Feature/Billing/MidtransReconcileCommandTest.php` | integrity W1-BILLING / W1-OPS | TI-MIDTRANS | rows instead of config; per-company expectations |
| `app/Http/Resources/NotificationResource.php` | integrity (`payment_attempt_exception`) | TI-PUSH-API | `push_test` type |
| `resources/js/Pages/Admin/Company/Settings.tsx` | integrity (item 18 version field) | TI-WEB | WhatsApp alert copy + link |
| `routes/admin.php` | master-lifecycle W2-CORE-BE | TI-CORE | the §4.1 block |
| `NetworkAccountProvisioner.php`, `SyncNetworkDevices.php`, `NetworkAccountController.php` | master-lifecycle W2-NETWORK-BE | TI-RADIUS | gate reads |
| `RadiusDirectory.php`, `NasDeviceController.php` | hardware-panel 03 (`onlineAmong` via `run()`) | TI-RADIUS | `run()` connection + gate, `check()`, `connectionName()`, `connection()` |
| `resources/js/Pages/Admin/NasDevices/Show.tsx` | hardware-panel 05 | TI-RADIUS | alert copy |
| `docs/user-guide/web.md`, `docs/user-guide/teknisi.md`, `CHANGELOG.md`, `docs/api/mobile-v1.openapi.json` | master-lifecycle W3-DOCS, integrity docs | TI-DOCS | own sections; export last |
| PreventDuplicateWebSubmit EXCEPT list | integrity W1-MECH | — | integrity adds `admin.company.integrations.test` |

Agreed with IntegrityDesign-2:

- TI never edits `PaymentAttemptService.php`, `BillingService.php`, `PaymentAttempt.php`, `CompanyController.php` or `Company.php`.
- Integrity never edits `MidtransWebhookController.php`, `Modules/Billing/routes/*`, `config/services.php` or `.env.example`.
