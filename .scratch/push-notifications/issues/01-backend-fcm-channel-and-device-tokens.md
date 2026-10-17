# BACKEND: FCM channel, device tokens, opt-in notifications, test command

Status: done
Role: backend
Blocked by: platform-superadmin merged (spec N8)
Spec: `../spec.md`

## Scope (owned files)

- `composer require kreait/laravel-firebase` (pin the current major; report version + licence MIT), `config/firebase.php` reading `FIREBASE_CREDENTIALS`, `.env.example`/`.env.docker.example` keys, `compose.yaml` read-only mount of the credentials path for app/queue (documented in `docs/operations/`), never a real `.env`.
- Migration `device_tokens` (N2), model with `BelongsTo user` and `BelongsTo personalAccessToken` (cascade; no company scope: tokens follow the user), registration binds the device token to the current request's Sanctum token (`$request->user()->currentAccessToken()`), `DeviceTokenController` (`POST/DELETE /api/v1/me/device-token`, `StoreDeviceTokenRequest`: app ∈ customer|technician matching the token's app persona, token max 4096, device_name max 100), throttle like other `me` writes, OpenAPI regenerated + contract test, API matrix counts.
- `app/Notifications/Channels/FcmChannel.php` (N3/N4): payload from `toArray()` + `NotificationResource` title/message logic (reuse, do not duplicate), per-app token selection, batch send via `Messaging::sendMulticast`, invalid-token cleanup, queued job after commit, logging rules.
- Opt-in `via()` of the notifications listed in N3 (grep every class; list them in Comments).
- `inbils:push:test {user} [--app=]` command for device smoke.
- Tests per N7 with a bound fake `Messaging`.

## Acceptance criteria

- [x] Focused push PHPUnit: 55 passed (303 assertions); Pint fixed two style issues; PHPStan and wider tests are blocked by the concurrent platform route `App\\Http\\Controllers\\Platform\\AuditController` being registered as invokable before that controller is complete. `composer audit` reports CVE-2026-109413 in pre-existing `symfony/yaml` v7.4.14 (fixed v7.4.18; `composer update symfony/yaml --with-all-dependencies`).

## Comments

- Dependency: `kreait/laravel-firebase` 7.2.1, MIT, installed as `^7.2`; `kreait/firebase-php` 8.6.0 supplies FCM HTTP v1. OpenAPI regenerated (6 existing Scramble warnings).
- API: `POST`/`DELETE /api/v1/me/device-token` require bearer + `Idempotency-Key`. POST validates `token` <= 4096, app persona match, Android, device name <= 100; 201 new / 200 rebound. DELETE is own-token-only 204. Sanctum rotation rebinds registrations; revocation cascades them.
- Push data keys are string-only `type`, `subject_type`, `subject_id`, `notification_id`, `title`, `body`; Android channel/tag are `inbils`/notification id. Invalid/unregistered tokens are deleted; transient total failure retries up to 3 times.
- Customer push types: `invoice_issued`, `invoice_due_soon`, `invoice_overdue`, `payment_received`, `technician_en_route`, `visit_completed`, `announcement_published` (outage/maintenance). Technician: `ticket_assigned`, `ticket_sla_breached`, `ticket_first_response_due`, `spk_scheduled`, `spk_approved`, `spk_rejected`, `inventory_request_ready`. `invoice_reminder` remains as a legacy API type. Customer ticket updates and customer visit scheduling stay bell/WhatsApp per the scoped list.
- Added missing action notifications at the state-changing service/job: SPK approval/rejection and customer visit completion; first-response due/breached within 30 minutes, claimed once per ticket by `tickets:sla`. Invoice issued/reminders/payment reuse existing after-commit billing events/listener.
- Local credential was copied to ignored `storage/app/private/firebase-service-account.json` (mode 600) for the requested real dry run, then deleted. Laravel boot blocked the dry-run before Firebase because concurrent `routes/platform/audit.php` registers unfinished `AuditController` as invokable. FCM V1 enablement is therefore not verified here.
- Operations/setup and API/payload/smoke details: `docs/operations/push-notifications.md`.

2026-10-16, orchestrator: real FCM V1 dry run from Sail with the service account (temporary copy, deleted afterwards) against a fake device token → `customer: 0 accepted, 1 stale (kept, dry run), 0 failed`: authentication accepted, Cloud Messaging API (V1) enabled. The fake token was removed from the dev DB.
