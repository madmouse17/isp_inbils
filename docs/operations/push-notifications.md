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

The process that calls `notify()` decides. `FcmChannel::for()` reads `FIREBASE_CREDENTIALS` in that process, so a button in the web app needs the key in the web process, and a notification sent from a queued job needs it in the worker; with the key missing in either one, that action leaves no push in the queue and nothing says why. The `env FIREBASE_CREDENTIALS=... sail artisan` line above covers that one command only. To try a push from the web app, put the path in the Sail `.env` and restart the worker (`sail artisan queue:restart`, or recreate the `workers` containers), so web and worker both have it.

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

Since flow-audit-fixes wave 2 a push is a **data-only** FCM message: there is no `notification` block, so Android draws nothing by itself. The app draws every push, in the foreground, in the background and when killed, so all three look alike and share one group. FCM receives string-only data:

```json
{
  "data": {
    "type": "spk_approved",
    "subject_type": "work_order",
    "subject_id": "42",
    "notification_id": "8a275b2b-5d73-49df-ae27-096db0479876",
    "title": "SPK disetujui",
    "body": "SPK SPK-2026-0042 sudah disetujui."
  },
  "android": {"priority": "high", "ttl": "86400s"}
}
```

`title` and `body` stay in `data`, because the app reads them there to draw the notification. `subject_type` and `subject_id` are empty strings when no screen can open. Android priority is high and TTL is 24 hours. The app uses the notification id as the Android tag, so a retried push replaces its copy. There is no server-side `channel_id` any more: the app creates the Android channel `inbils` (importance high) itself.

### What the apps do with it

- **Background and killed state.** Both apps register `inbilsBackgroundPush` (`packages/inbils_core/lib/src/push/background_push.dart`) with `FirebaseMessaging.onBackgroundMessage` before `runApp`. Android starts it in its own isolate for every data-only push and it draws the notification the way the foreground does. It draws nothing unless a stored, unexpired session exists on the phone: with nobody signed in there is nobody to show the push to, which also means an offline sign-out cannot leave a late push behind.
- **Group and summary.** Every push is posted with group key `inbils`. While two or more pushes wait, the app also posts one summary notification, "{n} notifikasi baru", whose inbox style lists up to five titles. The summary shows titles only, never message bodies. It names no subject, so a tap on the summary opens the notification list. With fewer than two waiting, the summary is removed.
- **Sign-out.** Signing out takes every notification of the app out of the notification shade, even when the phone is offline and the token cannot be deleted on the server. The server-side token deletion still happens when the connection allows it.
- **Tap.** A tap on one notification opens its subject. If that screen is already on show, the app re-reads the subject (with a 2 px loading bar) instead of leaving it stale.
- **Fresh data.** Each app re-reads its live lists on resume, on a tab change and on foreground pushes. Resume, tab change and tap refresh at once. Pushes are debounced: a burst of pushes causes one refresh, about 1 second after the last push of the burst. A push needs no display permission for that refresh.

### Release coupling

Data-only pushes need the new background handler. An app build older than this release draws nothing for a background push, and the bell still lists everything. Deploy the web release and publish both app builds together (`customer` and `technician`). The cutover is clean: there is no per-token format flag, because both apps were still at `1.0.0+1` and no store build is known to exist [INFERENCE: no store build was checked]. If an older build is in the field, add `device_tokens.push_format` and send data-only messages only to tokens of the new build.

Deploy steps (no migration): `npm run build`, then `php artisan queue:restart` so the workers load the new `FcmSender` payload and the new `TenantAdminInvitation` constructor, then publish the two app builds.

### Battery savers

FCM `notification` messages survive aggressive vendor battery savers (Xiaomi, Oppo and similar) better than a data-only message, because the system draws them without starting the app. With data-only pushes the vendor's battery saver can delay or drop the background isolate, so a closed app may show the push late or not at all. The server sends high priority to help. For a device that misses pushes while the app is closed, tell the user to open Settings, Apps, Inbils, Battery and choose **Unrestricted** (turn battery optimisation off, and allow auto-start if the phone offers it). Only the emulator has been tested; a real device check on at least one Xiaomi or Oppo phone is still open.

The committed `docs/api/mobile-v1.openapi.json` is the final enum of bell notification `type` values. Push-enabled types are:

- customer: `invoice_issued`, `invoice_due_soon`, `invoice_overdue`, `payment_received`, `technician_en_route`, `visit_completed`, `announcement_published` for outage/maintenance, and the ticket stages `ticket_created`, `ticket_assigned`, `ticket_started`, `ticket_replied`, `ticket_resolved`, `ticket_closed`, `ticket_reopened`;
- technician: `ticket_assigned`, `ticket_created`, `ticket_customer_replied`, `ticket_reopened`, `ticket_sla_breached`, `ticket_first_response_due`, `spk_scheduled`, `spk_approved`, `spk_rejected`, `inventory_request_ready`.
- both apps: `push_test`, the test push of Akun → Notifikasi uji (below); it goes to the requesting install only.

`invoice_reminder` remains in the API enum for bell rows written before the split into due-soon and overdue.

`customer_document_expiring` is in the enum too, but it is a web bell row for staff who may update customers (channel `database` only). It is never pushed.

### Texts of the bell and the push

`App\Http\Resources\NotificationResource` builds `title` and `message` once, for the bell list and for the push (`FcmChannel` calls it), so the two always read the same. Wave 3 made the invoice and ticket texts say what happened. The text comes from keys the notification stores in its `data`; a row written before those keys existed keeps the generic text of the old release, so nothing breaks.

| `type` | Title | Message | Keys read from `data` |
|---|---|---|---|
| `invoice_issued` | Tagihan {number} | Tagihan {number} sebesar Rp {total}, jatuh tempo {tanggal}. | `invoice_number`, `total`, `due_date` |
| `invoice_due_soon` (stage H-3, H-1 or `manual`) | {number} jatuh tempo 3 hari lagi, besok, or the date | Sisa Rp {outstanding}. Bayar lewat aplikasi. | `invoice_number`, `outstanding`, `due_date`, `stage` |
| `invoice_overdue` | {number} lewat jatuh tempo | Sisa Rp {outstanding} belum dibayar sejak {tanggal}. | `invoice_number`, `outstanding`, `due_date` |
| `payment_received` | Pembayaran diterima | Rp {amount} untuk {number} sudah kami terima. Terima kasih. | `invoice_number`, `amount` |
| `ticket_assigned` (technician) | Tiket {code} · {prioritas} | {title}. Respons sebelum {jam}. | `ticket_code`, `priority`, `title`, `response_due_local` |
| `ticket_resolved` (customer) | Tiket {code} diselesaikan | {first 80 characters of the resolution} Sudah beres? | `ticket_code`, `resolution_excerpt` |
| `ticket_reopened` (staff, by staff) | Tiket {code} dibuka kembali | Oleh {nama}: {alasan} | `ticket_code`, `by`, `actor_name`, `reason` |
| `ticket_reopened` (customer) | Tiket dibuka kembali | Tiket {code} dibuka kembali dan akan ditangani lagi. | `ticket_code` |
| `customer_document_expiring` (bell only) | Dokumen {jenis} {pelanggan} segera habis | Berlaku sampai {tanggal}. | `document_type`, `customer_name`, `expires_at` |

Notes: `invoice_due_soon` with stage `manual` is the reminder staff send with **Kirim pengingat** on the tab **Per tagihan** of Tunggakan; the type becomes `invoice_overdue` when the due date is already behind the company's today. `invoice_reminder` keeps its old generic text. `response_due_local` is the response deadline in the company's time ("21.17", or "08.30 besok" on the next day). The reason in the staff `ticket_reopened` text is cut at 140 characters; the audit row keeps all of it. Staff reopening sends the customer stage `reopened`. A customer's own reply on a resolved ticket also reopens it, but then only staff are told (generic text "Pelanggan membalas tiket {code} yang sudah diselesaikan, jadi tiket dibuka kembali.").

## Smoke test

Send to every reachable install of a user, or one app:

```bash
php artisan inbils:push:test <user-id-or-email>
php artisan inbils:push:test <user-id-or-email> --app=customer
php artisan inbils:push:test <user-id-or-email> --dry-run
```

Dry run asks FCM HTTP v1 to validate the request and credentials without delivery. An invalid fake token normally returns an invalid-argument result, which proves credentials reached the enabled FCM v1 API. HTTP 401/403 means the credentials, IAM permission, project or API enablement is wrong.

The command reports accepted, stale and failed counts. A real send deletes tokens FCM calls invalid or unregistered; dry run keeps them.

## In-app test (Akun → Notifikasi uji)

Both apps have a screen that tells a user why a push does not arrive, without a console. Akun → **Notifikasi uji** shows three checks and a button **Kirim notifikasi uji**:

1. **Izin notifikasi**: the Android permission (Diizinkan, Belum ditanyakan with an **Izinkan** button, or Diblokir di pengaturan HP with **Buka pengaturan**). The screen reads it again when the app resumes.
2. **Token terdaftar di server**: the server knows this install's FCM token for this sign-in (Terdaftar with the device name, Belum terdaftar with **Daftarkan ulang**, or "Notifikasi push belum aktif di server").
3. **Pesan diterima**: the test message arrives in the foreground within the wait the server names (30 s), with a live countdown.

A failing check shows a **Perbaiki** hint. The button is off until the permission is allowed, the token is known and no wait or 429 hold is running.

```http
POST /api/v1/me/push-test
Content-Type: application/json

{"token":"<FCM registration token>"}
```

- `202 {"data":{"notification_id":"<uuid>","device":{"id":"<ulid>","name":"Pixel 7"},"wait_seconds":30}}`: a `push_test` bell row exists ("Notifikasi uji", "Notifikasi uji dari {company}.") and the push is queued to that install only, never to the user's other phones. The app matches the push to `notification_id`.
- `409 push_token_not_registered`: the server has no reachable token with that hash for this sign-in, or the install has no bell to write to (a customer-app install whose user has no customer record). The app offers **Daftarkan ulang** (`POST me/device-token`, then retry).
- `503 push_unavailable`: `FIREBASE_CREDENTIALS` is empty on the server. The app tells the user to contact the ISP admin.
- `429` at 3 requests a minute per user (limiter `api-push-test`); the app keeps the button off for the `Retry-After` it receives.

The bell row is written before the answer, the FCM send goes through the queue (`SendPushNotification`), so a worker listening on `default` must be running. The same notification appears in `GET /api/v1/notifications` with type `push_test`.

### Platform status card

Pengaturan → Integrasi shows a read-only card **Notifikasi aplikasi (Firebase)** to every company admin: **Kredensial server** (Terpasang when `FIREBASE_CREDENTIALS` is set, Belum terpasang when empty; it does not prove the file is valid) and **Pengiriman terakhir berhasil** (the time of the last push FCM accepted, kept in the cache key `push:last_sent_at`, or Belum ada). Only the successful send proves the project works. The card never shows the key or its path. The Firebase project belongs to the platform and is shared by every company; per-ISP Firebase projects are out of scope.

### E2E

`tests/e2e/tenant-integrations.spec.ts` calls the push-test API and expects `202`, so `.env.e2e` must set `FIREBASE_CREDENTIALS` (`.env.e2e.example` points it at `storage/app/e2e-firebase-missing.json`, a file that does not exist: the push job fails at once and nothing calls Google).

### Emulator and device checks (dev)

- **Offline mode.** The dev setup runs `adb reverse tcp:8010 tcp:8010` (and `tcp:80`) so the app on the emulator reaches the host server. The reverse keeps the app online even in airplane mode, which hides the offline queue: an "offline" test then books the action as if nothing were wrong. For a real offline test use airplane mode and `adb reverse --remove tcp:8010`, and set the reverse again afterwards.
- **One AVD at a time.** An emulator takes several GB of RAM; two of them next to a browser and a language server ran the host out of memory and the AVD was killed three times (F12-D19). Check `uptime` before you boot one, do not boot it while a build or an E2E run holds `/tmp/inbils-build.lock` or `/tmp/inbils-e2e.lock`, and close what you do not need.

## Troubleshooting

| Symptom | Check |
|---|---|
| `Push is off` | `FIREBASE_CREDENTIALS` is empty or config cache predates the env change. |
| `User ... has no install` | App signed in and successfully called `POST me/device-token`; Sanctum token has not expired or been revoked. |
| Bell exists, no push job | Recipient has no reachable token for that app, or credentials are unset. |
| Job fails after three tries | `queue:failed`, FCM v1 API enablement, service-account project and network access. Logs contain notification id, token suffix and exception class only. |
| Only foreground display fails | Mobile app must create Android channel `inbils` with high importance and show the data through its local-notification handler. |
| Background push never shows, foreground works | The app build is older than wave 2 (no `inbilsBackgroundPush`), or a vendor battery saver stopped the app (see Battery savers). |
| Tap opens nothing | Push data `subject_type`/`subject_id` must match the bell subject; the app routes by that subject, then marks `notification_id` read. |
| Test screen says "Notifikasi push belum aktif di server" | `POST me/push-test` returned 503: `FIREBASE_CREDENTIALS` is empty or the config cache predates it. The Integrasi page shows Kredensial server as Belum terpasang. |
| Test screen: token Belum terdaftar | `409 push_token_not_registered`: the install never called `POST me/device-token` for this sign-in, or the token went stale. Tap **Daftarkan ulang**; check Akun → Perangkat. |
| Test push never arrives, checks 1 and 2 pass | A worker on `default` is not running, or FCM rejects the send. Check `queue:failed` and whether **Pengiriman terakhir berhasil** on the Integrasi page moves. On the phone, battery saver can hold a push for a closed app. |

## Security and lifecycle

- The Admin SDK key stays outside Git and mounts read-only.
- Device tokens are server data, never API output or log content. Logs contain only their final six characters.
- The unique lookup is SHA-256 (`token_hash`); the raw token is still required at rest because FCM needs it for delivery.
- Device registration is scoped to the authenticated user and current Sanctum token. Delete never reveals another user's token.
- Push jobs contain user id, app and public bell text, never a device token. They load reachable tokens when the job runs.
- Notification failure is reported and never rolls back the action that created it.
