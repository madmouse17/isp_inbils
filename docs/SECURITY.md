# Security & RBAC Policy — inbils

## 1. Principles
- Least privilege per persona.
- Separation of Duties (SoD): high-risk execution and approval/reversal/cancellation are mutually exclusive for non-admin personas.
- Multi-tenant company isolation enforced via `BelongsToCompany` and foreign key constraints.
- Backend decides first: every action is authorized by a Form Request / Policy; hidden buttons are never the only control.

## 2. RBAC Matrix & Capability Bundles

### 2.1 Seeded Personas
- **admin**: Full system administrator (break-glass role, holds all capabilities).
- **manager**: Operational controller (approver, reviewer, auditor, reversal authority).
- **staff**: Operational operator (intake, creation, execution, dispatch, collection).
- **technician**: Field technician (execution of assigned work, evidence submission).
- **customer**: External portal user (ticketing and evaluation submission only).

Seeded roles are global (not per tenant). `RolePermissionSeeder` uses `syncPermissions` on the five seeded roles, so re-running it narrows existing databases to this matrix; custom roles are never touched.

### 2.2 SoD Conflict Invariants

Permission level — no non-admin role may hold both sides. Declared once in `App\Support\SodConflicts`, asserted against the seeded roles by `RolePersonaSodTest`, and enforced when a non-admin saves a role (`NoSodConflict` on `StoreRoleRequest` / `UpdateRoleRequest`) or assigns roles to a user (`NoStackedSodConflict` on `StoreUserRequest` / `UpdateUserRequest` merges the permissions of the submitted roles, so `manager` + `staff` on one user is refused). Admin is exempt:

| Control side | Execution side |
|---|---|
| `inventory.request.approve` / `reject` | `inventory.request.prepare` / `issue`, `inventory.stock.receive` / `issue` / `transfer` |
| `inventory.stock.adjust.approve` | `inventory.stock.adjust.request`, `inventory.stock.receive` / `issue` / `transfer` |

`inventory.stock.adjust` (direct adjustment) is break-glass and admin-only: a non-admin cannot save a role that holds it.

Same-actor guards — enforced in the service layer (inside the locked transaction), and in the Policy where the UI needs the same answer, for every actor including admin:
1. **Work Orders (SPK)**: approver / rejector MUST NOT be the creator or assigned technician.
2. **Inventory Requests**: approver / rejector MUST NOT be the requester; approver MUST NOT be the preparer or issuer.
3. **Stock Adjustments**: the approver / rejector MUST NOT be the requester (`adjust.request` and `adjust.approve` are held by different personas; a user who holds both still cannot decide their own request).
4. **Billing & Invoices**: invoice canceller MUST NOT be the invoice creator. Payment reverser MUST NOT be the payment recorder.
5. **Role & User Management**: non-administrators cannot assign the `admin` role, edit seeded roles (`RolePolicy`), or edit / delete an admin user (`UserPolicy`). A user who requested or reviewed a stock adjustment cannot be deleted (`UserDeletionGuard`; the foreign keys `requested_by` / `approved_by` are `RESTRICT`), so the evidence keeps its actor.

Accepted residuals (not permission-level splits): the manager keeps `spk.create` / `assign` and `billing.create` / `send` / `payment.record` next to approval, cancel and reverse, so those duties rely on the same-actor guards above. An administrator can still stack roles on one user (break-glass); direct permissions given to a single user are not checked.

### 2.3 Permission Grants by Role
| Domain / Capability | admin | manager | staff | technician | customer |
|---|:---:|:---:|:---:|:---:|:---:|
| `customer.manage` | Yes | Yes | - | - | - |
| `customer.create` / `update` | Yes | Yes | Yes | - | - |
| `customer.sales.assign` | Yes | Yes | Yes | - | - |
| `customer.sales.correct` | Yes | Yes | - | - | - |
| `inventory.stock.receive` / `transfer` | Yes | - | Yes | - | - |
| `inventory.stock.issue` | Yes | - | Yes | Yes | - |
| `inventory.stock.adjust.request` | Yes | - | Yes | - | - |
| `inventory.stock.adjust.approve` | Yes | Yes | - | - | - |
| `inventory.stock.adjust` (direct / break-glass) | Yes | - | - | - | - |
| `inventory.request.approve` / `reject` | Yes | Yes | - | - | - |
| `inventory.request.prepare` / `issue` | Yes | - | Yes | - | - |
| `spk.approve` / `reject` | Yes | Yes | - | - | - |
| `spk.start` / `submit` | Yes | - | Yes | Yes | - |
| `billing.cancel` / `payment.reverse` | Yes | Yes | - | - | - |
| `billing.create` / `send` / `payment.record` | Yes | Yes | Yes | - | - |
| `report.sales.view` | Yes | Yes | - | - | - |
| `report.export` | Yes | Yes | - | - | - |
| `report.technician` | Yes | Yes | - | Yes | - |
| `ticket.attachment.upload` | Yes | - | Yes | Yes | Yes |
| `ticket.category.manage` | Yes | Yes | - | - | - |
| `announcement.manage` | Yes | Yes | - | - | - |
| `users.two_factor.reset` | Yes | - | - | - | - |

### 2.4 Stock adjustment workflow and break-glass
1. An operator (`inventory.stock.adjust.request`) posts `POST /admin/stocks/adjust`; a `pending` `stock_adjustment_requests` row records the requester, the quantity before and after, and the reason. Stock does not move.
2. A different user with `inventory.stock.adjust.approve` approves (`POST /admin/stocks/adjustments/{adjustment}/approve`) or rejects with a reason (`.../reject`). Approval applies the quantity under a row lock and writes the movement (`created_by` = approver) and the `stock_adjustment` audit entry with `adjustment_request_id`, `requested_by` and `approved_by`; a second decision on the same request is refused.
3. Admin break-glass: `POST /admin/stocks/adjust/direct` (`inventory.stock.adjust`, admin only) applies at once and writes the `stock_adjustment` audit entry with `break_glass: true`. It exists so a tenant with a single administrator is not blocked; every same-actor guard above still binds admin.

Audit events (log `inventory`): `stock_adjustment_requested`, `stock_adjustment` (approval or break-glass), `stock_adjustment_rejected`.

### 2.5 Deploying a persona change
Run `php artisan db:seed --class=RolePermissionSeeder --force`. It overwrites manual edits made to the five seeded roles (sync, not add) and leaves custom roles untouched; custom roles that need a new permission (for example `report.export`) are granted manually.

## 3. Two-factor authentication (TOTP)

Opt-in for every user, customer portal accounts included. Enforcing it per role or company is a later decision (`.scratch/auth-2fa-passkeys/spec.md`, Q3); until then it protects only the users who enrolled. Passkeys are out of scope.

### 3.1 Mechanism
- TOTP (RFC 6238: SHA-1, 6 digits, 30 s step) from an authenticator app, accepted one step either side of the current one, plus 8 single-use recovery codes. Code: `App\Services\Core\TwoFactorService`; libraries `pragmarx/google2fa` ^9.1 and `bacon/bacon-qr-code` ^3.1 (SVG, no GD).
- **Enrolment** (Profile → Two-Factor Authentication): a fresh secret stays *pending* and enforces nothing until the user types a valid code (spaces and non-breaking spaces in the code are ignored). The confirming code counts as spent. Enable, confirm, regenerate and disable sit behind `password.confirm` (recent password, `auth.password_timeout`, 3 h); its password check is throttled (3.6).
- **Login**: `POST /login` only checks the password and opens no session. For a user with two-factor on it parks the user id and the remember choice in the session (10 minutes, new session id) and redirects to `GET /two-factor-challenge`. `POST /two-factor-challenge` takes a TOTP code or a recovery code; only then is the user logged in (`Login` event, `last_login_at`), the session id regenerated, the company switch cleared and remember-me applied. A password reset never logs in, so it cannot bypass the challenge.
- **Brute force and replay**: every challenge attempt is counted *before* the code is checked (`RateLimiter::hit()` is an atomic increment, so a burst of parallel requests cannot all pass a check that is only counted afterwards), at two levels: 5 per minute per user and IP, and 10 per 15 minutes per user across every address. Both answer with the generic throttle message. A throttled attempt is never checked, so even the right code, or an unspent recovery code, is refused until the window passes; a passing code clears both counters. A TOTP code is accepted once: the last accepted time-step is stored in `users.two_factor_last_used_step` and checked under a row lock. A recovery code is removed under the same lock and compared with `hash_equals`.
- **Ending other logins**: turning two-factor on (confirm), regenerating recovery codes, turning it off (when it was on) and an admin reset rotate `users.remember_token`, which kills every "remember me" cookie. In the profile actions the acting session also gets a new id and its old record is destroyed, so a stolen copy of its cookie stops working. With `SESSION_DRIVER=database` (`.env.example` and the Docker env) the user's other `sessions` rows are deleted as well; file and Redis sessions cannot be listed by user (3.5).

### 3.2 Storage and what never leaves the server
- `two_factor_secret` and `two_factor_recovery_codes` use Laravel's `encrypted` casts (key: `APP_KEY`). The four `two_factor_*` columns are not fillable, hidden on serialisation and excluded from the `user` activity log (`User::getActivitylogOptions()->logExcept()`).
- The setup key, QR code and recovery codes reach the browser once, as Inertia **flash data** (not page props, so not in browser history state, and never in a query string). Pages and shared props carry only `enabled`, `pending` and the number of recovery codes left.
- Audit (log name `security`, subject = the user, properties = ids only): `two_factor.enrollment_started`, `enabled`, `disabled`, `recovery_codes_regenerated`, `recovery_code_used`, `challenge_failed`, `admin_reset`.

### 3.3 Lost device
- The user spends a recovery code at the challenge and then regenerates codes from the Profile.
- An administrator resets the user: `POST /admin/users/{user}/two-factor/reset`, permission `users.two_factor.reset` (admin only, section 2.3; named `user.two_factor.reset` before 2026-10-12, renamed by migration). Never one's own account; `UserPolicy::resetTwoFactor` applies the same rule as editing the user (only an admin may reset an admin; the actor must belong to every company the target belongs to). The user then logs in with the password alone and can enrol again.
- A locked-out sole administrator has nobody to reset them: run `php artisan tinker --execute="app(App\Services\Core\TwoFactorService::class)->disable(App\Models\User::where('email', '<email>')->firstOrFail());"`.

### 3.4 Deploying and rolling back
Run `php artisan migrate` and `php artisan db:seed --class=RolePermissionSeeder --force` (adds `users.two_factor.reset`, see 2.5). Rolling back the migration discards every secret and recovery code; users simply log in with the password again. Rotating `APP_KEY` makes enrolled secrets undecryptable: reset two-factor for those users (3.3) first.

### 3.5 Accepted residuals
- The challenge throttle is 5 per minute per user and IP plus 10 per 15 minutes per user. Someone who holds the password and rotates addresses is held to 10 guesses per 15 minutes (about 960 a day, against 3 valid codes in a million) and can keep the real user out of the challenge for up to 15 minutes; an admin reset clears two-factor, so that user can still be restored.
- With `SESSION_DRIVER=file` or `redis`, turning two-factor on does not end the user's other sessions (only remember-me cookies and, in the profile actions, the acting session); they last until idle expiry (`SESSION_LIFETIME`, 120 min by default).
- The password step still counts failures after the check (Breeze's pattern, 5 per minute per email and IP), so a burst of parallel wrong passwords can exceed it. Not changed in this lane.
- The one-time flash carrying the setup key or recovery codes sits in the session store, in clear unless `SESSION_ENCRYPT=true`, from the POST response until the following request (the redirected page load) completes; a user who never follows the redirect leaves it there until the session expires. Production deployments that keep sessions in a store others can read should turn session encryption on.
- The mobile API issues tokens only after `TwoFactorService::verifyChallenge()` passes (section 4.3).

### 3.6 Related login controls
- **Step-up throttle**: `POST /confirm-password`, the gate in front of two-factor management and password changes, allows 5 wrong passwords per minute per user and IP, counted before the password is checked: the 6th attempt is refused even with the right password, and the right password clears the count.
- **Inactive users**: `users.is_active` is part of the credentials lookup (`LoginRequest::validateCredentials`), so an inactive user gets exactly the wrong-password message and no session. A user deactivated between the password step and the challenge is sent back to the login without the code being checked. Sessions and remember-me cookies that exist when a user is deactivated are *not* ended: there is no per-request check for web sessions yet. API tokens are different (section 4.2).
- **Forgot password**: `POST /forgot-password` is limited to 6 per minute per address and always answers with the same status ("If an account exists for that email address, we have emailed a password reset link."), whether or not the address has an account, and also inside the broker's 60 s wait time. The link is sent after the response (`Illuminate\Support\defer`), so the time it takes does not say either; a mail transport failure is reported to the log, not to the visitor.

### 3.7 Tenant deactivation (`companies.is_active` false)
A suspended company is refused wherever its users act, not only in scheduled jobs and the company switcher:
- **Web login**: `LoginRequest::validateCredentials` (shared with the mobile API login) answers 422 "Akun perusahaan Anda sedang ditangguhkan. Hubungi dukungan." once the credentials are right, when the user's home company is suspended and no other active membership exists. No session and no token. A wrong credential never sees this message. A user with another active membership logs in and starts there (`active_company_id` is set by `OpensSession`).
- **Every web request**: `CompanyService::canActIn` and `idFor` honour `is_active` for the home company too. `ResolveActiveCompany` (web group) moves a session whose effective company is suspended to the first active membership, or signs it out and redirects to the login with the same message.
- **Mobile API**: `EnsureApiUserIsActive` answers 403 `company_inactive` when the home company is suspended (the apps act in the home company only). The token is not deleted: reactivating the company restores access.
- **Tokens**: on `Company::updated` with `is_active` true to false, `ApiTokenService::revokeForSuspendedCompany` deletes all tokens of users who have that company as home or membership and no other active company, audited as `api.tokens_revoked` with reason `company_suspended`. Users who keep an active company keep their tokens. A writer that skips the model (bulk update) is still caught by the 403 above.
- **Flip race**: a token minted during the flip survives the revocation but is refused with 403 `company_inactive` on every call.
- **Two-factor window**: `OpensSession` (the end of both the password and the code step) re-checks `CompanyService::canEnter`, so a company suspended between the two opens no session.
- Scheduled jobs are unchanged: they already skip inactive companies.

## 4. Mobile API (customer and technician apps)

`/api/v1`, Laravel Sanctum bearer tokens, no session and no cookies (`statefulApi()` stays off). Specification and decisions: `.scratch/mobile-api/spec.md`; contract: `docs/api/mobile-v1.openapi.json`. Nothing in this section reaches the browser UI.

### 4.1 Tokens
- One personal access token per app install, named after the device (at most 100 characters) and carrying exactly one ability, `customer` or `technician`. Routes require the ability, so a customer token never reaches a technician route. A user who holds both roles holds two tokens.
- Lifetime is absolute and set when the token is issued: 90 days for a customer token, 30 days for a technician token (`sanctum.app_token_days`). `POST /api/v1/auth/token/refresh` swaps the token for a new one in one transaction and deletes the old one; the app refreshes when fewer than 7 days remain. Any 401 means "sign in again". There are no OAuth refresh tokens.
- `sanctum:prune-expired --hours=24` runs daily (`routes/console.php`). The token text is shown once, in the login response; it is never logged, audited or stored in clear (Sanctum stores its SHA-256).
- Audit (log `activity_log`, ids and reasons only, never a token): `api.token_issued` (app, device name, IP) and `api.tokens_revoked` (reason).

### 4.2 Revocation
Every one of these deletes all of the user's tokens, so the next call is 401:
- the password changes by any path (reset, profile, admin edit);
- the user is deactivated (`is_active` false) or deleted;
- the company of the user is suspended and leaves them no active company (reason `company_suspended`, section 3.7);
- two-factor is enabled, disabled, its recovery codes are regenerated, or an admin resets it (`TwoFactorService::endOtherLogins`).

The hooks sit on the `User` model, so every writer is covered. Also: logout deletes the current token; `DELETE /api/v1/devices/{id}` deletes one of the user's own other tokens (a lost phone); an inactive user is refused on every request with 401 `account_inactive` and the token is deleted.

### 4.3 Who may use which app
- **Customer app**: the web portal rule, role `customer` plus a `customers.user_id` link (`EnsureCustomerAccess::customerFor`). Inactive or terminated customers keep access to their old invoices, as on the web.
- **Technician app**: the web "My work" rule, `MyWorkQuery::isEligible` (`spk.start|spk.submit` or `ticket.start|resolve|close`, plus an active employee profile in the company).
- Both apps also need an active user, a verified e-mail and a company. This is checked at login and on every request; a failure is 403 `app_not_allowed`. The company is always the user's home company (`users.company_id`).
- A user with `must_change_password` set is refused with 403 `password_reset_required` and resets the password on the web through the e-mailed link (`POST auth/forgot-password`, always 202, same body for every address).
- Second factor: a user with confirmed two-factor gets a server-side challenge (cache, 300 s, at most 5 attempts) from `POST auth/login` and no token until `POST auth/two-factor` verifies a TOTP or recovery code. Limits and limiter keys are shared with the web challenge (3.1). Managing two-factor stays on the web.
- Throttles: 120 requests per minute per token; 20 sign-ins per minute per IP plus the web's 5 per e-mail and IP; 6 password-reset requests per minute per IP; 6 payment starts and 30 uploads per minute per user.
- Promo banner taps have an extra limit of 30 requests per minute per customer user. The counter also deduplicates each banner, user, and company-local date.

### 4.4 Evidence policy (SPK photos and documents)
Web and API call the same `SpkService::addEvidence` and `removeEvidence`, guarded by `WorkOrderPolicy`:
- Upload: the SPK is not `completed` or `cancelled`, and the user has `spk.update`, or is the assigned active technician with `spk.evidence.upload` while the SPK is `in_progress`.
- Remove: the same status rule, and `spk.update`, or the uploader while the SPK is `in_progress`.
- One file per request, at most 10 MB, `jpg,jpeg,png,pdf,doc,docx` checked by extension and MIME type, caption at most 255 characters, optional `captured_at` between 30 days ago and 5 minutes ahead. Files go to the private disk and download through `GET /api/v1/media/{media}`, which is fenced by the token's app. A technician reads an SPK only when assigned to it.
- Promo banner images use the same private media route. The customer app receives bytes only when the caller's company and audience match a currently live banner. Other-company, non-targeted, scheduled, expired, and inactive access returns 404.
- Until 2026-10-11 any technician of the company could add or delete evidence on any SPK in any status; `tests/Feature/SPK/EvidenceAuthorizationTest.php` pins the fix.

### 4.5 Request integrity
- Every `POST`, `PUT`, `PATCH` and `DELETE` except the three public auth endpoints needs `Idempotency-Key` (a UUID). For 24 hours the response is stored per user and key: a replay returns the stored response, the key with another request is 422 `idempotency_conflict`, a parallel duplicate is 409 `idempotency_in_progress`. 5xx and 429 are not stored.
- Every error under `api/*` is JSON `{message, code, errors?}` whatever `Accept` says, with no exception class, SQL or trace even when `APP_DEBUG` is true.
- Responses use allow-list resources, never the admin ones: no payment gateway token, order id, notification payload, `received_by`, internal note or network credential appears (the recursive key check in `tests/Feature/Api/V1/Customer/PayTest.php`).

### 4.6 Dependencies
`dedoc/scramble` (MIT) is a dev dependency used only to generate the OpenAPI export. Production installs with `composer install --no-dev`, so no docs route (`docs/api`) exists there; locally Scramble's default `viewApiDocs` gate allows it in the `local` environment only.

### 4.7 What the apps write besides field work (2026-10-13, app-only personas)
The technician and customer roles no longer use the web, so the apps carry these writes. Each one is a thin call into the web's service and policy:
- **Ticket files** (`POST|DELETE {customer|technician}/tickets/{id}/attachments`): the permission `ticket.attachment.upload` (seeded to technician and customer, 2.3), plus `TicketPolicy::view` and `attach` (a customer reaches only their own tickets, 404 otherwise; a technician only the ones assigned to them). One file per request, at most 10 MB, `jpg,jpeg,png,pdf` checked by extension and MIME type, caption at most 255 characters, 30 uploads a minute per user (the evidence budget). Whoever uploaded a file may delete it while the ticket is `open`, `assigned` or `on_progress`; nobody else, nobody once it is resolved or closed, and nobody when the uploader is unknown. The web's delete route (`DELETE admin/tickets/{ticket}/attachments/{attachment}`, which has no button) calls the same `TicketService::removeAttachment`: there is no admin override, and a manager who is also a technician cannot clear other people's files with the permission the technician role brings. Until 2026-10-13 any holder of the permission could delete any file there. The resource tells the app with `mine` and `can_delete`.
- **SPK material lines** (`POST|DELETE technician/work-orders/{wo}/items`): only the technician who may submit that SPK (`WorkOrderPolicy::submit`: assigned, active employee profile, status `in_progress`). The web needs `spk.update`, which the technician role does not hold and never gets. The app records and takes back the used quantity of a plain product, nothing more. Adding sets `quantity_used` of the product's line and leaves a planned `quantity_reserved` alone; serialized assets are refused (they need a chosen unit). Deleting withdraws that use and never erases the manager's plan: a line with a reservation or a note stays with `quantity_used` 0 (the answer is 204 all the same, so the app reads the SPK again), only a line that held nothing but the technician's own use is deleted, and a serialized-asset line is refused with 422. The manager still reviews the lines when approving, and stock moves only then (`CompleteSpkAction`).
- **Inventory requests** (`POST technician/inventory-requests`, `POST .../{id}/cancel`): `InventoryRequestService` and `InventoryRequestPolicy` unchanged. A request from the app is submitted at once (no draft step); cancelling is the requester's, while `draft` or `submitted`. `GET technician/warehouses` and `GET technician/products` only feed the pickers: active locations that hold stock, active non-asset products, no prices or quantities.
- **Own account** (`PATCH me`, `POST me/password`): the name on the login (`users.name`) and the phone of the person behind it (`customers.phone`, `employee_profiles.phone`); the e-mail, the billing name and every other field stay with staff. A new password needs the current one, counted before it is checked against the web's confirm-password budget (5 wrong guesses a minute per user and address, 429 after that), and it revokes every token of the user (4.2).
- Residual: a customer can now change the phone their WhatsApp notices go to without staff. The consent (`whatsapp_opt_in_at`) stays on the customer record, as it does when staff edit the number.

### 4.8 Announcements and promo banners
- `GET /api/v1/customer/home-feed` returns allow-listed live announcements and at most five live banners. SQL audience matching uses active or suspended subscriptions, a serving POP subtree, and service package categories. A targeted item with no remaining targets matches nobody.
- `GET /api/v1/customer/announcements/{announcement}` repeats the company, period, and audience checks. `POST /api/v1/customer/promo-banners/{banner}/tap` requires the normal idempotency key and its own throttle.
- Management needs `announcement.manage`. Create, update, publish, deactivate, delete, and expired-image cleanup write the `announcement` audit log with ids only. Published rows stay as history; only drafts may be deleted.

### 4.9 Push notifications (FCM)
- Device token hidup di tabel `device_tokens`, terikat ke satu Sanctum token lewat foreign key `cascadeOnDelete` dan ke satu persona app (`customer` atau `technician`); `app` pada `POST /api/v1/me/device-token` harus sama dengan ability token bearer. `DELETE` hanya menghapus baris milik pengguna yang sedang login. Logout, pencabutan perangkat, ganti kata sandi, nonaktif user, suspend company dan prune token menghapus registrasinya (4.2); rotasi token memindahkannya ke token baru.
- Token FCM disimpan di kolom `token` (diperlukan untuk mengirim) dengan `token_hash` unik sebagai kunci pencarian; keduanya `hidden` di model dan tidak pernah dikembalikan oleh API. Log hanya memuat id notifikasi, id user, app, jumlah hasil dan 6 karakter terakhir token yang gagal; judul, isi pesan dan token penuh tidak pernah ditulis ke log. Payload push memakai judul dan pesan yang sama dengan lonceng aplikasi.
- Kredensial service account Firebase Admin SDK berada di luar repo (`FIREBASE_CREDENTIALS` berisi path, bukan isi JSON) dan tidak pernah masuk `.env`, log, screenshot atau laporan. Token yang ditolak FCM (invalid atau unregistered) dihapus setelah pengiriman. Pengaturan dan pemeriksaan: `docs/operations/push-notifications.md`.

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
- Banner tenant memakai prop Inertia bersama `impersonation` (`Inertia::always`,
  hanya ada selama session dukungan aktif):
  `{admin_name, user_name, company_name, company_code, mode: 'read'|'write',
  expires_at, reason, write_url, read_url, stop_url, confirm_url}`. Prop ini
  tidak membawa e-mail, id internal, token atau kata sandi; `confirm_url` hanya
  menerima `return_to` ke halaman `/admin` atau `/app-only` tenant.

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

## 6. Public ids, uniform 404 and enumeration limiter

Authorization (`BelongsToCompany`, policies, `can:` middleware) is the control. The rules below are a second layer against guessing ids and against reading record volume from a URL. Design: `.scratch/opaque-ids/spec.md`.

### 6.1 Public ids
- Every record that a URL, an API path, an Inertia prop or an API payload names has `public_id`, a 26-character lowercase ULID (`App\Support\PublicId::new()`, trait `App\Traits\HasPublicId`). Models bind routes by it; routes constrain the parameter with `whereUlid`; a value that is not a ULID is a 404 before any query.
- The integer `id` is the primary key and every foreign key and never leaves the server: resources and props emit `public_id` as `id` and as every `*_id` reference. Requests accept public ids and map them to integer keys (company-scoped) before the service layer; a value that names no record, an integer, or a list sent where one id belongs (`customer_id[]=5`) is refused (`ResolvesPublicIds`, `PublicIdExists`).
- `PublicId::new()` takes a fresh random part per id (not `Str::ulid()`, which counts up inside one millisecond): ids made together by a backfill, a seeder or a batch are not neighbours, so one leaked id names no sibling.
- Midtrans `order_id` of a new attempt is `INB-{ULID}`; it names no company or invoice.
- A public id is not a secret and not an authorization token. Never skip a policy because the caller "could not have guessed" the id.
- Human codes (`CUS-00001`, `INV-2026-00001`) stay visible to the owning company. A running counter in a template still shows volume; change it in Penomoran.
- Known open item: public company logo files live under `/storage/companies/<company_id>/...` and carry integer ids.

### 6.2 Uniform record-level 404 (K3)
- A record that does not exist, belongs to another company, or is hidden from the user by its policy answers the same 404 (same body). Policies return `Response::denyAsNotFound()` for scope denials; hand-written checks use `App\Support\RecordNotFound`. A comment or a file on a ticket the user's role hides (a technician who is not its handler) answers 404 as well: `TicketController` authorizes `view` before `comment` and `attach`.
- Stays 403: page-level `can:` denials, and a missing permission on a record the user may see.
- API: another technician's work order and another company's records answer 404.
- A client that asks the web for JSON (`Accept: application/json`) gets `{"message":"Not Found"}` for every 404 outside `api/*`, whatever the cause (no model class, no route in the body). The API keeps its own `{message, code}` envelope.

### 6.3 Enumeration limiter (K4)
- `App\Http\Middleware\DetectRecordEnumeration` runs on the web, platform and API groups, after the guards and before route binding. It counts record-level 404s (`ModelNotFoundException`, `RecordNotFound`, a policy denial that answers 404) per signed-in user, in a rolling window.
- Past `security.enumeration.threshold` (default 30) within `security.enumeration.window` minutes (5), it writes one `security.enumeration_suspected` audit entry (route name, ids tried, count, window, address) and answers 429 for `security.enumeration.cooldown` minutes (10). The API answers JSON with `Retry-After`.
- Nobody who is signed out is counted. Every record route sits behind the login, the isolir page (`isolir/{company:code}`) has its own limiter (20 unknown codes a minute per address), and an address is not a person: counting by it would let anyone lock `/login` and `api/v1/auth/*` out for everybody behind the same NAT or proxy.
- Behind a reverse proxy or load balancer set `TRUSTED_PROXIES` (comma-separated addresses, CIDR ranges allowed, `*` only when the proxy is the single way in). `App\Http\Middleware\TrustConfiguredProxies` reads it on every request, so the audit address, the per-address throttles and generated URLs use the real client. Default: nobody is trusted, and a forged `X-Forwarded-For` is ignored.
- 403s are never counted. Env: `SECURITY_ENUMERATION_ENABLED` (default on; off in `testing` and `e2e`), `SECURITY_ENUMERATION_THRESHOLD`, `SECURITY_ENUMERATION_WINDOW`, `SECURITY_ENUMERATION_COOLDOWN`.
