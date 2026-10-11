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
| `user.two_factor.reset` | Yes | - | - | - | - |

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
- An administrator resets the user: `POST /admin/users/{user}/two-factor/reset`, permission `user.two_factor.reset` (admin only, section 2.3). Never one's own account; `UserPolicy::resetTwoFactor` applies the same rule as editing the user (only an admin may reset an admin; the actor must belong to every company the target belongs to). The user then logs in with the password alone and can enrol again.
- A locked-out sole administrator has nobody to reset them: run `php artisan tinker --execute="app(App\Services\Core\TwoFactorService::class)->disable(App\Models\User::where('email', '<email>')->firstOrFail());"`.

### 3.4 Deploying and rolling back
Run `php artisan migrate` and `php artisan db:seed --class=RolePermissionSeeder --force` (adds `user.two_factor.reset`, see 2.5). Rolling back the migration discards every secret and recovery code; users simply log in with the password again. Rotating `APP_KEY` makes enrolled secrets undecryptable: reset two-factor for those users (3.3) first.

### 3.5 Accepted residuals
- The challenge throttle is 5 per minute per user and IP plus 10 per 15 minutes per user. Someone who holds the password and rotates addresses is held to 10 guesses per 15 minutes (about 960 a day, against 3 valid codes in a million) and can keep the real user out of the challenge for up to 15 minutes; an admin reset clears two-factor, so that user can still be restored.
- With `SESSION_DRIVER=file` or `redis`, turning two-factor on does not end the user's other sessions (only remember-me cookies and, in the profile actions, the acting session); they last until idle expiry (`SESSION_LIFETIME`, 120 min by default).
- The password step still counts failures after the check (Breeze's pattern, 5 per minute per email and IP), so a burst of parallel wrong passwords can exceed it. Not changed in this lane.
- The one-time flash carrying the setup key or recovery codes sits in the session store, in clear unless `SESSION_ENCRYPT=true`, from the POST response until the following request (the redirected page load) completes; a user who never follows the redirect leaves it there until the session expires. Production deployments that keep sessions in a store others can read should turn session encryption on.
- There is no token-issuing endpoint yet (`laravel/sanctum` is installed but unused). A future mobile or API login must call `TwoFactorService::verifyChallenge()` before it issues a token.

### 3.6 Related login controls
- **Step-up throttle**: `POST /confirm-password`, the gate in front of two-factor management and password changes, allows 5 wrong passwords per minute per user and IP, counted before the password is checked: the 6th attempt is refused even with the right password, and the right password clears the count.
- **Inactive users**: `users.is_active` is part of the credentials lookup (`LoginRequest::validateCredentials`), so an inactive user gets exactly the wrong-password message and no session. A user deactivated between the password step and the challenge is sent back to the login without the code being checked. Sessions and remember-me cookies that exist when a user is deactivated are *not* ended: there is no per-request check yet.
- **Forgot password**: `POST /forgot-password` is limited to 6 per minute per address and always answers with the same status ("If an account exists for that email address, we have emailed a password reset link."), whether or not the address has an account, and also inside the broker's 60 s wait time. The link is sent after the response (`Illuminate\Support\defer`), so the time it takes does not say either; a mail transport failure is reported to the log, not to the visitor.
