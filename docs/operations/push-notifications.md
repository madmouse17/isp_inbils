# Push notifications (Firebase Cloud Messaging)

Inbils sends Android push notifications to the customer and technician apps through Firebase Cloud Messaging HTTP v1. The app bell remains the source of truth: every push uses the same notification id, title, message and subject as `GET /api/v1/notifications`. Mail and WhatsApp keep their existing channels.

## Credentials

The server needs the Firebase Admin SDK service-account JSON, not `google-services.json`. Keep the key outside the repository and outside the web root. Set only its path:

```dotenv
FIREBASE_CREDENTIALS=/absolute/path/firebase-service-account.json
```

An empty value turns push off without affecting bell, mail or WhatsApp notifications. Never put JSON or private-key contents in `.env`, logs, screenshots or support reports.

### Laravel Sail

The Sail container bind-mounts this repository, but cannot read `~/.config/inbils`. Copy the development key temporarily to the ignored private storage directory:

```bash
install -m 600 ~/.config/inbils/firebase-service-account.json storage/app/private/firebase-service-account.json
env FIREBASE_CREDENTIALS=storage/app/private/firebase-service-account.json ./vendor/bin/sail artisan inbils:push:test <user-id-or-email> --dry-run
rm -f storage/app/private/firebase-service-account.json
```

`storage/app/private/*` is ignored by Git. Delete the temporary copy when the check is done.

### Production Docker

Put the host path in the ignored `.env.docker`:

```dotenv
FIREBASE_CREDENTIALS_FILE=/etc/inbils/firebase-service-account.json
```

`compose.yaml` mounts that file read-only at `/run/secrets/firebase-service-account.json` and sets `FIREBASE_CREDENTIALS` in `app`, `queue`, `platform-queue` and `scheduler` through their shared anchor. If the host variable is empty, `/dev/null` is mounted and push stays off.

Check the resolved mount without printing the key:

```bash
docker compose --env-file .env.docker config | grep -A4 -B2 firebase-service-account
docker compose --env-file .env.docker up -d --force-recreate app queue scheduler platform-queue
```

The default queue worker sends pushes. It must keep listening to `default`:

```bash
php artisan queue:work --queue=default,whatsapp --tries=3 --timeout=90
```

After deploy or key rotation, recreate the containers (config is cached in `app`) and restart queue workers.

## Device-token API

Both mutations need a bearer token and `Idempotency-Key: <uuid>`.

```http
POST /api/v1/me/device-token
Content-Type: application/json

{"token":"<FCM registration token>","app":"customer","platform":"android","device_name":"Pixel 7"}
```

A new token returns `201 {"data":{"id":1}}`; an existing token returns 200. The `app` must match the bearer token (`customer` or `technician`). The same phone signing in as another user moves its FCM token to that user.

```http
DELETE /api/v1/me/device-token
Content-Type: application/json

{"token":"<FCM registration token>"}
```

Delete always returns 204 and only removes the current user's row. The apps register after sign-in and token refresh, and delete before sign-out. Sanctum token rotation preserves the registration. Logout, remote device revocation, password change, user deactivation, company suspension and expired-token pruning delete it through the Sanctum foreign key.

## Push payload

FCM receives both a visible Android notification and string-only data:

```json
{
  "notification": {"title": "SPK disetujui", "body": "SPK SPK-2026-0042 sudah disetujui."},
  "data": {
    "type": "spk_approved",
    "subject_type": "work_order",
    "subject_id": "42",
    "notification_id": "8a275b2b-5d73-49df-ae27-096db0479876",
    "title": "SPK disetujui",
    "body": "SPK SPK-2026-0042 sudah disetujui."
  }
}
```

`subject_type` and `subject_id` are empty strings when no screen can open. Android priority is high, TTL is 24 hours, notification channel id is `inbils`, and the notification id is the Android tag so a retried push replaces its copy.

The committed `docs/api/mobile-v1.openapi.json` is the final enum of bell notification `type` values. Push-enabled types are:

- customer: `invoice_issued`, `invoice_due_soon`, `invoice_overdue`, `payment_received`, `technician_en_route`, `visit_completed`, `announcement_published` for outage/maintenance;
- technician: `ticket_assigned`, `ticket_sla_breached`, `ticket_first_response_due`, `spk_scheduled`, `spk_approved`, `spk_rejected`, `inventory_request_ready`.

`invoice_reminder` remains in the API enum for bell rows written before the split into due-soon and overdue.

## Smoke test

Send to every reachable install of a user, or one app:

```bash
php artisan inbils:push:test <user-id-or-email>
php artisan inbils:push:test <user-id-or-email> --app=customer
php artisan inbils:push:test <user-id-or-email> --dry-run
```

Dry run asks FCM HTTP v1 to validate the request and credentials without delivery. An invalid fake token normally returns an invalid-argument result, which proves credentials reached the enabled FCM v1 API. HTTP 401/403 means the credentials, IAM permission, project or API enablement is wrong.

The command reports accepted, stale and failed counts. A real send deletes tokens FCM calls invalid or unregistered; dry run keeps them.

## Troubleshooting

| Symptom | Check |
|---|---|
| `Push is off` | `FIREBASE_CREDENTIALS` is empty or config cache predates the env change. |
| `User ... has no install` | App signed in and successfully called `POST me/device-token`; Sanctum token has not expired or been revoked. |
| Bell exists, no push job | Recipient has no reachable token for that app, or credentials are unset. |
| Job fails after three tries | `queue:failed`, FCM v1 API enablement, service-account project and network access. Logs contain notification id, token suffix and exception class only. |
| Only foreground display fails | Mobile app must create Android channel `inbils` with high importance and show foreground data through its local-notification handler. |
| Tap opens nothing | Push data `subject_type`/`subject_id` must match the bell subject; the app routes by that subject, then marks `notification_id` read. |

## Security and lifecycle

- The Admin SDK key stays outside Git and mounts read-only.
- Device tokens are server data, never API output or log content. Logs contain only their final six characters.
- The unique lookup is SHA-256 (`token_hash`); the raw token is still required at rest because FCM needs it for delivery.
- Device registration is scoped to the authenticated user and current Sanctum token. Delete never reveals another user's token.
- Push jobs contain user id, app and public bell text, never a device token. They load reachable tokens when the job runs.
- Notification failure is reported and never rolls back the action that created it.
