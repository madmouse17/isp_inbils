# FreeRADIUS provisioning and customer block/unblock (replaces MikroTik RouterOS API provisioning)

Status: done (lab-proven; production RADIUS_ENABLED stays false until routers move one at a time)
Ledger: none yet (lane replaces `.scratch/mikrotik-radius`; add the ledger entry when work starts)
Source: owner change 2026-10-15: stop direct MikroTik RouterOS API provisioning, move to RADIUS (FreeRADIUS), add customer block/unblock.
Supersedes: `.scratch/mikrotik-radius` (Q2 "RouterOS API direct" and Q3 are reversed; its billing work, Q7, Q9, Q16 and the isolir page stay).
Lab: `~/labs/freeradius` (README.md). FreeRADIUS 3.2.10 on `127.0.0.1:11812/11813`, MySQL 8.4 on `127.0.0.1:13306` DB `radius` (stock 3.2.10 schema, `read_clients = yes` from `nas`), `nas-sim` (pyrad) with control API `127.0.0.1:13800` and CoA/Disconnect listener `127.0.0.1:13799`.

> Subscriber secrets and RADIUS shared secrets are credentials. They live only encrypted in the Inbils database, in cleartext only inside the `radius` database (FreeRADIUS needs them, see Q5), and never in a repo file, test, fixture, log, audit entry, export, screenshot or ticket comment. Lab values are read from `~/labs/freeradius/.env` at run time and never printed. Tests use obviously fake values.

## Owner decisions

Approved 2026-10-15 by lead on owner's delegation: recommended option of every decision.

1. **Q1: Where the RADIUS tables live.** Facts: Inbils has one database connection in use (`config/database.php:45-66`; the only other `DB::connection` call is `app/Services/Core/TwoFactorService.php:255`, which resolves to the default). The lab keeps the stock FreeRADIUS schema in its own MySQL database `radius` (`~/labs/freeradius/compose.yaml` service `radius-db`; `~/labs/freeradius/freeradius/mods-enabled/sql` `radius_db`, `read_clients = yes`, `client_table = "nas"`).
   (a) **Separate `radius` connection** to a separate database holding the stock FreeRADIUS 3.2 schema. Ops create the schema with FreeRADIUS' own `schema.sql`; Inbils never migrates it. Inbils' RADIUS DB user gets `SELECT, INSERT, UPDATE, DELETE` on `radcheck`, `radreply`, `radusergroup`, `radgroupreply`, `nas` and `SELECT` on `radacct` only. FreeRADIUS gets its own user and never sees Inbils tables.
   (b) The `rad*` tables inside the Inbils database. One connection, but FreeRADIUS then needs grants on the Inbils database (customer data, invoices), `migrate:fresh` in tests and e2e would wipe RADIUS state, and the tables would collide with our naming and migrations.
   **Recommend (a).** Matches the lab one to one; least privilege both ways. Tradeoff: writes to two databases are not atomic; the desired-state job (Q13) converges them.

2. **Q2: Removal scope.** Facts: RouterOS code is listed in "Removal inventory" below; production writes were never switched on (`.scratch/mikrotik-radius/issues/08-reviewer-lab-evidence-and-release.md` is not done; `config/services.php:94-97` defaults the switch off; `tests/Feature/DemoDataSeederTest.php:201-205` pins demo routers to "writes off").
   (a) **Clean cutover**: delete every RouterOS client, fake, test, the dependency `evilfreelancer/routeros-api-php` (`composer.json:12`, pulls `spatie/ssh`), `ext-sockets` (`Dockerfile:14,41`, only needed by that library, `composer.lock:943`), the `ROUTEROS_*` env keys (`.env.example:87-90`, `.env.docker.example:74-77`), router reachability probing and its notification. Keep and repurpose the NAS registry, network accounts, the apply job, `network:sync`, the subscription observer, the NAS/account pages, permissions and the isolir page.
   (b) Keep RouterOS as a fallback beside RADIUS. Two provisioning paths to test and secure; contradicts the owner change.
   **Recommend (a).** Tradeoff: existing NAS rows lose their API credentials (demo/e2e fake values only) and must get a RADIUS secret.

3. **Q3: Multi-company usernames.** Facts: FreeRADIUS looks users up by `User-Name` alone (`radcheck`/`radusergroup` keyed by `username`, `~/labs/freeradius/db/initdb/10-schema.sql:68-76,125-132`); Inbils makes usernames unique per company only (`Modules/NetworkAsset/app/Http/Requests/StoreNetworkAccountRequest.php:26-31`, unique `(company_id, username)` in `Modules/NetworkAsset/database/migrations/2026_10_13_210001_create_network_accounts_table.php`). Company `code` is globally unique, up to 50 characters (`database/migrations/2026_06_30_134628_create_companies_table.php:14`).
   (a) **Realm suffix**: the RADIUS username is `<local part>@<realm>`, realm = the company code in lower case. Staff type the local part (`[a-z0-9._-]{3,40}`); Inbils stores and shows the full username, unique across all companies. A company whose lower-cased code does not match `^[a-z0-9-]{1,24}$` cannot register NAS or accounts until its code is changed (24 so the realm also fits `nas.shortname varchar(32)`, Q4).
   (b) Globally unique free usernames. A "username taken" error tells company A that company B uses that name (cross-tenant leak).
   (c) Company id prefix (`c12-budi`). Unique, but meaningless to customers typing it into their CPE.
   **Recommend (a).** Tradeoff: customers type `budi@acme`; usernames of different companies can never collide.

4. **Q4: NAS isolation and the account ↔ NAS link.** Facts: a NAS belongs to one company today (`app/Traits/BelongsToCompany.php:16-41`, `Modules/NetworkAsset/app/Models/NasDevice.php:42`); every network account points at one NAS (`network_accounts.nas_device_id`). With RADIUS, any client in `nas` may authenticate any user unless FreeRADIUS is told otherwise. Multi-company activation is excluded from the current product scope (`docs/ENTERPRISE_ISP_READINESS.md:7`).
   (a) **Accounts are no longer bound to one NAS**: drop `nas_device_id`; a subscriber may log in on any NAS of its company. Each NAS row gets `nas.shortname = <realm>`; a short FreeRADIUS `authorize` policy rejects a user whose realm differs from the client's shortname (runbook text in `docs/business/network.md`, proven in the lab by ticket 06). `nas.nasname` is globally unique (FreeRADIUS matches clients by source address), so one physical NAS serves one company. Disconnect goes to the NAS where the session is open (`radacct.nasipaddress`), matched against that company's NAS rows.
   (b) Keep `nas_device_id` and add a per-user `NAS-IP-Address ==` check item. Works for one NAS per subscriber only; roaming between POP NAS breaks.
   **Recommend (a).** Tradeoff: cross-company enforcement lives in FreeRADIUS config (ops), not in Inbils; Inbils still validates realm and NAS ownership on every write.

5. **Q5: Credential storage.** Facts: MikroTik PPPoE negotiates PAP, CHAP, MS-CHAPv1/v2; CHAP and MS-CHAP need the cleartext on the server. The lab stores `Cleartext-Password` (`~/labs/freeradius/db/initdb/20-seed.sh:10-12`); `nas-sim` authenticates with PAP (`~/labs/freeradius/nas-sim/app.py:118`). Inbils already keeps the subscriber secret with the `encrypted` cast and `$hidden` (`Modules/NetworkAsset/app/Models/NetworkAccount.php:46-54`).
   (a) **`Cleartext-Password` in `radcheck`** (op `:=`); the encrypted copy in Inbils stays the source of truth (the RADIUS DB is a projection that `network:sync` can rebuild). The per-NAS RADIUS shared secret is stored encrypted in `nas_devices` and in cleartext in `nas.secret` (FreeRADIUS needs it; Inbils needs it to sign Disconnect-Requests).
   (b) Hashed (`SSHA2-512-Password` / `Crypt-Password`): only PAP works, every CHAP/MS-CHAP CPE fails to log in.
   **Recommend (a).** Tradeoff: the `radius` database holds cleartext credentials: separate DB user, no unencrypted backup export, network-restricted (runbook).

6. **Q6: Speed profile → `radgroupreply`.** Facts: SpeedProfile has `download_max_mbps`, `upload_max_mbps`, burst Mbps and `radius_profile_name` (`Modules/Service/database/migrations/2026_06_30_200002_create_speed_profiles_table.php:14-20`, seeds `home-20m` etc. `Modules/Service/database/seeders/ServiceCatalogSeeder.php:34-38`); no burst threshold/time columns. `Mikrotik-Rate-Limit` is `rx/tx` seen from the router, rx = client upload, and replaces the PPP profile rate when present (help.mikrotik.com RADIUS page, "Supported RADIUS Attributes"). Group names are global in FreeRADIUS.
   (a) **One group per speed profile** named `<realm>/<radius_profile_name>` with replies `Mikrotik-Group := <radius_profile_name>`, `Mikrotik-Rate-Limit := <upload_max>M/<download_max>M`, `Acct-Interim-Interval := 300`. Inbils writes the group when an account needs it and rewrites it when the speed profile changes (new logins get the new rate; online sessions keep theirs until they reconnect or staff press Resync). Burst stays out (needs threshold/time columns).
   (b) Only `Mikrotik-Group`; rates stay on each router's PPP profile, as under RouterOS. NOC keeps maintaining profiles per router.
   (c) Full burst: add threshold/time columns to SpeedProfile.
   **Recommend (a).** Tradeoff: no burst in v1; a package without `radius_profile_name` still cannot get an account.

7. **Q7: How a block reaches the subscriber.** Facts: lab pattern "Block/unblock = change `radusergroup.groupname`, then Disconnect so the NAS re-auths" (`~/labs/freeradius/README.md`; seed group `isolir` with `Mikrotik-Group`, `Mikrotik-Rate-Limit`, `Mikrotik-Address-List`, `20-seed.sh:20-28`). RouterOS supports Disconnect and CoA but cannot change address or pool by CoA (`.scratch/mikrotik-radius/spec.md` External contract); `/radius incoming` listens on port **1700** by default (help.mikrotik.com RADIUS page, `/radius incoming`, `port` default 1700).
   (a) **Group switch + Disconnect-Request**: `radusergroup` → `<realm>/isolir` (replies `Mikrotik-Group := isolir`, `Mikrotik-Address-List := isolir`, `Acct-Interim-Interval := 300`; the router's `isolir` PPP profile carries the rate and the redirect to `/isolir/{company code}`), then a Disconnect-Request per open session; the CPE redials and lands in isolir. Unblock is the same with the speed group.
   (b) CoA in place (change rate and address list without dropping). Not every attribute is CoA-capable on RouterOS, and a stale session keeps its old address list.
   **Recommend (a).** Tradeoff: the subscriber sees a short reconnect on block and unblock.

8. **Q8: Disconnect/CoA sender.** Facts: no RADIUS client in `composer.json`; the production image installs no FreeRADIUS utilities (`Dockerfile:30-42`). `dapphp/radius` 3.1.0 (LGPL-3.0-or-later, 2025-12-28) sends Disconnect-Requests over `fsockopen('udp://')` (`src/Radius.php:2845-2857,2251`).
   (a) `dapphp/radius` 3.1.0: a 2,900-line class for one packet type; LGPL; adds a dependency.
   (b) **Own RFC 5176 sender** (about 80 lines): encode Disconnect-Request (code 40) with `User-Name`, `Acct-Session-Id`, `NAS-IP-Address`; Request Authenticator = MD5(code+id+length+16 zero octets+attributes+secret); send over `stream_socket_client('udp://host:port')` with a 3 s timeout; verify the response authenticator; map 41 → ack, 42 → nak (+ `Error-Cause`), timeout → retry. No dependency, no `ext-sockets`, pure encode/decode functions unit-testable, wire proven against `nas-sim` (pyrad verifies the authenticator, `~/labs/freeradius/nas-sim/app.py:207-210`).
   (c) Shell out to `radclient`: needs `freeradius-utils` in the app image, a process per packet, and the secret on a command line.
   **Recommend (b)** (repo rule 19: no dependency for a few lines). Tradeoff: we own a small protocol encoder; the lab run is its acceptance.

9. **Q9: Online status and usage.** Facts: FreeRADIUS writes `radacct` (`10-schema.sql:17-62`); open sessions have `acctstoptime IS NULL`; a NAS reboot or a restarted `nas-sim` leaves open rows behind (`~/labs/freeradius/README.md`, nas-sim section).
   (a) **Read `radacct` live** when a page asks: the open session of a username (newest `acctstarttime`, `acctstoptime IS NULL`, `acctupdatetime` or `acctstarttime` within the last 15 minutes, which covers 3 missed interims at 300 s) and the octets of the current calendar month. Nothing is copied into Inbils; `last_online_at`/`current_address` columns go away.
   (b) Copy into `network_accounts` every 15 minutes. Stale by design and more code.
   **Recommend (a).** Tradeoff: the account page does two indexed reads on the RADIUS DB (`username`, `acctstarttime` keys exist).

10. **Q10: Manual block model.** Facts: manual Suspend exists (`app/Services/Core/SubscriptionService.php:80-99`, source `manual`) and stops invoicing (`Modules/Billing/app/Services/BillingService.php:53-56` bills `active|terminated` only). Automatic isolir writes `overdue` and payment reconnects it under Q16 (`Modules/Billing/app/Services/OverdueSuspensionService.php:63-109`, listener `Modules/Billing/app/Listeners/ReactivateSubscriptionAfterPayment.php:18-27`). Suspension reasons live only in the audit (`SubscriptionService.php:89-93`).
    (a) **A block flag on the subscription**, independent of `status`: `blocked_at`, `blocked_by`, `block_reason`. Blocked ⇒ the network account is isolated whatever the status; billing, status, auto-suspend and Q16 are untouched. Rules: auto-suspend still suspends a blocked active subscription (source `overdue`); a payment that reconnects (Q16) reactivates the status but the block keeps the subscriber isolated; unblocking a suspended subscription keeps it isolated until it is reactivated (the UI says so); terminate clears the block; the customer status roll-up ignores the block.
    (b) Block = the existing Suspend with source `manual`. No new schema, but invoicing stops while blocked, and it is already available today, so nothing new is delivered.
    **Recommend (a).** Tradeoff: two ways to isolate (suspension and block); the network state shows which one applies.

11. **Q11: Block permission, reason, audit.** Facts: permission naming `{module}.{entity}.{action}` (`database/seeders/RolePermissionSeeder.php:44-49`); manager holds `customer.subscription.*` (`RolePermissionSeeder.php:224-226`), staff none of suspend/reactivate/terminate.
    (a) **New `customer.subscription.block`** for block and unblock, admin and manager. Block needs a reason (≤500 characters, stored in `block_reason` and the audit); unblock takes an optional note (audit only). Audit `service_subscription` `blocked` / `unblocked` with code and reason, causer automatic.
    (b) Reuse `customer.subscription.suspend`. Couples two different decisions.
    **Recommend (a).** Tradeoff: one more permission to assign.

12. **Q12: FreeRADIUS learning a new NAS.** Facts: clients are read from `nas` at start; the lab needs `docker kill -s HUP radlab-freeradius` after inserting rows (`~/labs/freeradius/README.md`).
    (a) **Runbook**: after registering a NAS or changing its secret, the NOC reloads FreeRADIUS; the NAS page shows that notice. Inbils never controls the FreeRADIUS process.
    (b) FreeRADIUS dynamic clients (`dynamic_clients` virtual server). More FreeRADIUS config to own.
    **Recommend (a).** Tradeoff: one manual step per new NAS (rare).

13. **Q13: Switch, queue, rollback.** Facts: queue and worker as approved before (`.scratch/mikrotik-radius/spec.md` Q5; `ApplyNetworkAccountJob` `tries` 5, backoff `[30,120,300,900]`, `Modules/NetworkAsset/app/Jobs/ApplyNetworkAccountJob.php:40-49`).
    (a) **`RADIUS_ENABLED`** (default `false`): off means no RADIUS DB read or write and no Disconnect at all. Same desired-state job after commit; RADIUS DB errors and Disconnect timeouts retry with backoff; the account shows `pending` with the reason; a NAK `Session-Context-Not-Found` counts as done. Rollback: switch off (RADIUS keeps its last rows, sessions keep working) → routers back to local secrets by hand if ever needed → migrations down.
    (b) No switch; RADIUS always on once configured. No instant rollback.
    **Recommend (a).**

## Removal inventory

Delete (RouterOS only):
- `Modules/NetworkAsset/app/Services/RouterOsApi.php`, `RouterOsClient.php`, `RouterOsBinaryClient.php`, `RouterOsClients.php`, `ConvertsRouterOsRecords.php`, `RouterOsException.php`, `NasDeviceHealth.php`.
- `Modules/NetworkAsset/app/Jobs/SyncNasDeviceJob.php`; `Modules/NetworkAsset/app/Notifications/NasDeviceUnreachableNotification.php`; `Modules/NetworkAsset/app/Observers/NetworkAssetEndpointObserver.php` (it switched off a write flag that no longer exists; the RADIUS secret is never sent to the asset address) and the `management_ip` guard in `Modules/NetworkAsset/app/Http/Requests/UpdateNetworkAssetRequest.php:44-64`.
- Route `admin.nas-devices.check` (`Modules/NetworkAsset/routes/web.php:27`).
- Tests: `tests/Feature/NetworkAsset/RouterOsClientTest.php`, `RouterOsBinaryClientTest.php`, `RouterOsApiContractTestCase.php`, `RouterOsRestContractTest.php`, `RouterOsBinaryContractTest.php`; `tests/Support/FakeRouterOs.php`, `tests/Support/FakeBinaryRouterOs.php`; `tests/Traits/InteractsWithRouterOs.php`; `tests/e2e/mikrotik-provisioning.spec.ts`.
- Dependency `evilfreelancer/routeros-api-php` (`composer.json:12`; `spatie/ssh` leaves with it); `sockets` in `Dockerfile:14,41`.
- Env/config (ticket 01): `ROUTEROS_PROVISIONING_ENABLED`, `ROUTEROS_CA_BUNDLE` (`.env.example:87-90`, `.env.docker.example:74-77`), `services.routeros` (`config/services.php:94-97`). Ticket 04: the comment at `routes/console.php:24`.
- Frontend: `resources/js/lib/nasApiProtocol.ts`; RouterOS fields and actions on the NAS and account pages.
- Columns (forward migration, never editing the shipped ones): `nas_devices.api_protocol`, `api_port`, `api_username`, the API secret column, `isolir_profile`, `provisioning_enabled`, `routeros_version`, `last_checked_at`, `last_seen_at`, `unreachable_since`, `unmanaged_count`, `unmanaged_usernames`; `network_accounts.nas_device_id`, `provisioned_nas_device_id`, `provisioned_profile`, `provisioned_service_type`, `last_online_at`, `current_address`.

Keep and repurpose:
- NAS registry (`nas_devices`, `NasDevice`, NAS pages, `network_asset.nas.view|manage`) → RADIUS clients (`nas` rows).
- Network accounts (`network_accounts`, `NetworkAccount`, account page, `network_asset.account.view|manage|reveal`, one-time flash and audited reveal) → RADIUS credentials (`radcheck`, `radusergroup`).
- `NetworkAccountProvisioner` (desired state; writer becomes RADIUS), `ApplyNetworkAccountJob` (same queue contract), `network:sync` (same schedule, `routes/console.php:25`), `ServiceSubscriptionNetworkObserver` (also watches `blocked_at`), `pending_disconnect_at` (now "a Disconnect is owed").
- Unchanged: `billing:suspend-overdue`, `suspension_source`, `suspended_at`, Q16, `PaymentRecorded` → `ReactivateSubscriptionAfterPayment`, the public isolir page (`Modules/NetworkAsset/routes/web.php:38`, `resources/js/Pages/Isolir/Show.tsx`), Receivables "Isolir".

## Problem Statement

Inbils was built to push PPPoE secrets into every MikroTik router through the RouterOS API. The owner now wants central AAA: routers authenticate subscribers against FreeRADIUS, so Inbils must manage RADIUS users, speed groups and NAS clients instead of router secrets, and must be able to cut a session so a change takes effect at once. Staff also have no way to block a single customer's internet (abuse, fraud, customer request) without suspending the subscription, which stops invoicing and mixes with the overdue automation.

## Solution

Every subscription with a network account becomes a RADIUS user `local@realm` with its secret, placed in the speed group of its package, in the company's isolir group while it is suspended or blocked, and removed when it is terminated. Each registered NAS router becomes a FreeRADIUS client with its own shared secret. When the group changes, Inbils sends a Disconnect-Request to the NAS holding the session; the CPE redials and gets the new group. Staff with the block permission can block and unblock a subscription with a reason from the subscription page and from Receivables; the block isolates the network only, billing goes on, and payments never lift it. The account page shows whether the subscriber is online, the address and NAS of the session and this month's usage, read from RADIUS accounting.

## User Stories

1. As a NOC engineer, I want to register a NAS router as a RADIUS client with its source address and shared secret, so that FreeRADIUS accepts its requests.
2. As a NOC engineer, I want the shared secret stored encrypted and never shown again after saving, so that it cannot leak through the UI.
3. As a NOC engineer, I want the CoA/Disconnect port per router (default 1700, MikroTik's default), so that Disconnect-Requests reach routers configured differently.
4. As a NOC engineer, I want a notice to reload FreeRADIUS after adding a router or changing its secret, so that the router is not rejected silently.
5. As a NOC engineer, I want the router page to list the sessions currently open on it, so that I see who is online there.
6. As a NOC engineer, I want the router page to show whether its RADIUS client row is written and the last error, so that I know RADIUS knows it.
7. As a NOC engineer, I want each speed profile to become a RADIUS group with the rate limit of the package, so that speeds are managed in one place.
8. As a NOC engineer, I want a changed speed profile rewritten in RADIUS, so that new logins get the new speed.
9. As a NOC engineer, I want the isolir group to carry the `isolir` router profile and address list, so that the router redirects isolated subscribers to the isolir page.
10. As a NOC engineer, I want routers of one company unable to authenticate subscribers of another company, so that tenants stay apart.
11. As a customer-service agent, I want to create a RADIUS account for a subscription with a generated secret shown once, so that the technician can configure the CPE.
12. As a customer-service agent, I want the company realm appended to the username automatically, so that usernames never collide between companies.
13. As a customer-service agent, I want to reveal the subscriber secret with the action audited, so that I can help a customer reconfigure their CPE.
14. As a customer-service agent, I want to see whether the subscriber is online, their address, NAS and session start, so that I can answer "no internet" calls.
15. As a customer-service agent, I want this month's upload and download volume, so that I can answer usage questions.
16. As a customer-service agent, I want "Resync" to rewrite the account in RADIUS and drop the session, so that I can fix a stuck subscriber.
17. As a customer-service agent, I want a secret change to keep the current session, so that the subscriber is not cut while on the phone.
18. As a technician, I want the account to work while the installation is pending, so that I can test on site.
19. As a manager, I want to block a subscription's internet with a reason, so that I can stop abuse or fraud at once.
20. As a manager, I want to unblock it again, so that service resumes when the issue is solved.
21. As a manager, I want a block to keep invoicing running, so that blocking never loses revenue.
22. As a manager, I want a payment never to lift a block, so that automation does not undo a deliberate decision.
23. As a manager, I want an overdue isolir to still happen on a blocked subscription, so that billing rules stay independent.
24. As a manager, I want unblocking a suspended subscription to leave it isolated until it is reactivated, so that unpaid customers are not reconnected by accident.
25. As a manager, I want the block reason, author and time shown on the subscription page, so that colleagues know why the customer is offline.
26. As a billing clerk, I want to see on Receivables which subscriptions are blocked, so that I do not chase a customer who is already cut.
27. As a billing clerk with the block permission, I want Block and Unblock on Receivables, so that I act from the debt list.
28. As a billing clerk, I want Receivables "Isolir" to keep working as today (overdue suspension, auto-reconnect after full payment), so that Q16 is unchanged.
29. As a subscriber, I want my internet back within a minute after paying online (overdue isolir only), so that I am not left offline after paying.
30. As an isolated subscriber, I want my browser redirected to the isolir page, so that I know why and how to pay.
31. As a customer-service agent, I want terminating a subscription to remove its RADIUS user and cut its session, so that former customers cannot connect.
32. As an operator, I want billing and subscription actions to succeed while RADIUS or a NAS is down, so that network problems never block the office.
33. As an operator, I want pending RADIUS work and owed Disconnects to catch up by themselves, so that nothing is lost.
34. As an owner, I want one switch that stops all RADIUS traffic, so that I can roll back instantly.
35. As an auditor, I want every block, unblock, account change, reveal, RADIUS write and Disconnect in the activity log without any secret, so that I can trace who did what.
36. As a company admin, I want NAS rows, accounts and credentials of other companies invisible to me.
37. As a developer, I want RADIUS faked in tests (SQLite RADIUS schema, recording Disconnect sender), so that tests never touch real equipment.
38. As a tester, I want an end-to-end run against the FreeRADIUS lab and `nas-sim`, so that block, Disconnect, isolir and unblock are proven on the wire.

## Implementation Decisions

- **Vocabulary**: *RADIUS NAS* = a registered NAS router (one `nas` row); *RADIUS account* = the network account of one subscription (`radcheck` + `radusergroup`); *realm* = lower-cased company code; *speed group* = `<realm>/<radius_profile_name>`; *isolir group* = `<realm>/isolir`; *block* = staff isolation flag on the subscription; *isolated* = suspended or blocked.
- **Placement**: unchanged. RADIUS code lives in `Modules/NetworkAsset` (`app/Services/Radius/`). The block flag and its service methods live in shared Core (`SubscriptionService`, `ServiceSubscription`) because Billing (Receivables) and NetworkAsset both read it and Core never calls the module (`docs/architecture/domain-boundaries.md:40-41`); the module observes the shared model as today.
- **RADIUS connection**: `config/database.php` connection `radius` (driver `mysql`) from `RADIUS_DB_HOST`, `RADIUS_DB_PORT` (3306), `RADIUS_DB_DATABASE` (`radius`), `RADIUS_DB_USERNAME`, `RADIUS_DB_PASSWORD`. `config/services.php` `radius`: `enabled` from `RADIUS_ENABLED` (default `false`), `coa_timeout` 3 seconds. Names only in `.env.example` and `.env.docker.example`; values on the host only.
- **RadiusDirectory** (the only code touching the `radius` connection; primitives in, so it does not depend on Inbils models). Throws `RadiusException` (kinds `disabled`, `unavailable`, `rejected`) and refuses everything while `services.radius.enabled` is false:

  ```php
  // Contract shape (signatures only)
  public function putUser(string $username, string $secret, string $group): void;   // one transaction: radcheck Cleartext-Password :=, radusergroup (priority 1); other rows of that username deleted
  public function removeUser(string $username): void;                                // radcheck, radreply, radusergroup rows of that username
  public function hasUser(string $username, string $group, string $secretHash): bool; // drift check
  public function putGroup(string $group, array $replies): void;                    // radgroupreply rows of that group replaced (op :=)
  public function putNas(string $nasname, string $shortname, string $secret, string $description, ?string $previousNasname): void; // upsert by nasname, type 'other'
  public function removeNas(string $nasname): void;
  /** @return list<array{session_id:string, nas_ip:string, framed_ip:?string, started_at:string, updated_at:?string}> open, not stale (15 min) */
  public function openSessions(string $username): array;
  /** @return list<...same + username> open sessions on one NAS address, first 100 */
  public function sessionsOnNas(string $nasIp, int $limit = 100): array;
  /** @return array{input_octets:int, output_octets:int} */
  public function usageSince(string $username, \DateTimeInterface $from): array;
  ```

- **Disconnect sender** (`RadiusDisconnectClient`, own RFC 5176 code, Q8b): `disconnect(string $host, int $port, string $secret, array $attributes): DisconnectResult` with result `ack | nak(errorCause) | timeout | invalid`; attributes `User-Name`, `Acct-Session-Id`, `NAS-IP-Address`. Pure `encode()`/`decode()` plus a thin UDP send (`stream_socket_client`, `services.radius.coa_timeout`, one attempt; retries belong to the job). Refuses when the switch is off. Never logs packets or the secret.
- **Schema** (forward migrations only; each `down()` restores what it removed, data in dropped columns is lost):
  - `service_subscriptions`: `blocked_at` timestamp null, `blocked_by` FK `users` null `nullOnDelete`, `block_reason` string(500) null, index `(company_id, blocked_at)`. Not fillable; written only by `SubscriptionService::block|unblock|terminate`; cast `blocked_at` datetime.
  - `nas_devices`: drop the RouterOS columns (Removal inventory); add `nasname` string(128) unique (IPv4 address FreeRADIUS sees, `radacct.nasipaddress` is `varchar(15)`; defaults to the asset `management_ip`), `coa_port` unsigned smallint default 1700, `radius_secret` text null (`encrypted`, `$hidden`; required to save from now on), `radius_nasname` string(128) null (the `nasname` last written to RADIUS), `radius_synced_at` timestamp null; keep `last_error`. CoA target = asset `management_ip` : `coa_port`.
  - `network_accounts`: drop `nas_device_id` (FK), `provisioned_nas_device_id`, `provisioned_profile`, `provisioned_service_type`, `last_online_at`, `current_address`; add `provisioned_group` string(64) null; replace unique `(company_id, username)` by unique `username`; keep `service_type`, `provisioned_username`, the provisioned secret hash, `provisioned_at`, `pending_disconnect_at`, `sync_status`, `last_error`, `last_attempt_at`. A separate data migration appends `@<realm>` to existing usernames without it (demo and e2e data only).
- **Desired state** (one rule, derived when the job runs):

  | Subscription | RADIUS user | Group |
  | --- | --- | --- |
  | `pending` or `active`, not blocked | present | speed group |
  | `suspended`, or any non-terminated status with `blocked_at` | present | isolir group |
  | `terminated` or deleted | absent | none |

- **Apply algorithm** (`ApplyNetworkAccountJob`, unique per account until processing, after commit, `tries` 5, backoff `[30,120,300,900]`, `timeout` 60):
  1. Switch off → `pending` with the reason, nothing sent.
  2. Username changed since the last write → `removeUser(provisioned_username)` and owe a Disconnect for the old name.
  3. Absent → `removeUser`; owe a Disconnect. Present → `putGroup` for the target group, `putUser(username, secret, group)`.
  4. A Disconnect is owed when the group changed (`provisioned_group` ≠ target), the user was removed or renamed, or staff pressed Resync; a secret change alone owes none. Owed = `pending_disconnect_at` set before sending.
  5. For each open session of the affected username(s): find the company NAS whose `nasname` equals `radacct.nasipaddress`; send Disconnect. ACK or NAK `Session-Context-Not-Found` (503) → done. Timeout or other NAK → retry (`pending`); a session on an address no NAS of the company owns → recorded in `last_error`, not sent, not retried.
  6. Record `provisioned_*`, `provisioned_at`, `sync_status=synced`, clear `pending_disconnect_at` and `last_error`; audit `network_account` `provisioned` only when user, group or presence changed, `disconnected` per session (NAS label and result).
  RADIUS DB errors → retry; validation-type failures (missing speed profile name, invalid realm) → `failed` at once.
- **Triggers**: the module observer on `ServiceSubscription` queues the job when `status`, `service_package_id` or `blocked_at` changed, or on delete; saving an account and Resync queue it; a `SpeedProfile` observer rewrites that company's speed group with no Disconnect; `network:sync` every 15 minutes re-queues accounts that are `pending`, owe a Disconnect, are `failed` with `last_attempt_at` over an hour old, or drifted (`hasUser` false), and writes NAS rows whose `radius_synced_at` is null or whose `nasname` differs from `radius_nasname`.
- **NAS writes**: `NasDeviceService` saves the Inbils row, then (after commit) `putNas(nasname, realm, secret, "<asset code> <asset name>")`, removes the previous `radius_nasname` when the address changed, and on delete `removeNas(radius_nasname)`. A RADIUS failure leaves `radius_synced_at` null with `last_error` and a flash warning (never a 500); the sync retries. Inbils never touches `nas` rows it did not write (e.g. the lab's `127.0.0.1` and `172.30.77.0/24`). Deleting a NAS is allowed while sessions are open on it; the router ends them on its own terms.
- **Block service** (Core): `SubscriptionService::block(ServiceSubscription $s, string $reason): ServiceSubscription` (lock, 422 when terminated or already blocked, set `blocked_at=now()`, `blocked_by=auth id`, `block_reason`, audit `blocked`), `unblock(ServiceSubscription $s, ?string $note)` (422 when not blocked, clear the three columns, audit `unblocked`), `terminate` clears them. No customer status recalculation (block is not a status).
- **Routes** (existing groups; permission gates plus tenant-scoped binding and `ensureSameCompany`, as `app/Http/Controllers/Admin/SubscriptionController.php:155-168`):

  | Method | URI | Name | Permission | Request | Errors |
  | --- | --- | --- | --- | --- | --- |
  | POST | `/admin/subscriptions/{subscription}/block` | `admin.subscriptions.block` | `customer.subscription.block` | `reason` required string ≤500 | 403, 404 other company, 422 terminated or already blocked |
  | POST | `/admin/subscriptions/{subscription}/unblock` | `admin.subscriptions.unblock` | `customer.subscription.block` | `reason` nullable string ≤500 | 403, 404, 422 not blocked |
  | GET/POST/PUT/DELETE | `/admin/nas-devices…` (resource, names unchanged) | `admin.nas-devices.*` | `network_asset.nas.view|manage` | `network_asset_id` (router asset of the company, unregistered), `nasname` (IPv4, unique, default asset `management_ip`), `coa_port` 1-65535 (default 1700), `radius_secret` required on create, blank keeps on update, 8-60 printable non-space characters (`nas.secret varchar(60)`) | 422 when the company realm is invalid (Q3) |
  | (removed) | `/admin/nas-devices/{nasDevice}/check` | | | | |
  | GET/POST/PUT | `/admin/subscriptions/{subscription}/network-account` | unchanged | unchanged | `service_type` `pppoe|hotspot`; `username` = local part `[a-z0-9._-]{3,40}`, stored as `local@realm`, unique globally (message never says which company); secret blank (generated, 12 alphanumerics, flashed once) or 6-64 non-space characters | 422 realm invalid, no speed profile name, terminated |
  | POST | `…/network-account/reveal`, `…/resync` | unchanged | unchanged | | throttles unchanged |

- **Props contract** (resources never contain a secret):

  ```ts
  type SyncStatus = 'pending' | 'synced' | 'failed';
  type NasDevice = {
    id: number;
    network_asset: { id: number; code: string; name: string; management_ip: string | null;
                     location: { id: number; name: string; type: string } | null } | null;
    nasname: string; shortname: string; coa_port: number; has_radius_secret: boolean;
    radius_synced_at: string | null; last_error: string | null;
  };
  type RadiusSession = { session_id: string; nas_ip: string; nas_label: string | null;
                         framed_ip: string | null; started_at: string; updated_at: string | null };
  type NetworkAccount = {
    id: number; service_type: 'pppoe' | 'hotspot'; username: string; // full "local@realm"
    sync_status: SyncStatus; last_error: string | null;
    provisioned_group: string | null; provisioned_at: string | null; disconnect_pending: boolean;
  };
  // Admin/NasDevices/Index: { nasDevices: Paginated<NasDevice>; filters; can: { create: boolean } }
  // Admin/NasDevices/Create|Edit: { routerAssets: { id; code; name; management_ip; location_name }[]; nasDevice?: NasDevice; realm: string | null }
  // Admin/NasDevices/Show: { nasDevice: NasDevice; sessions: (RadiusSession & { username: string; subscription: { id: number; code: string } | null })[];
  //                          isolirUrl: string; radiusEnabled: boolean; can: { update: boolean } }
  // Admin/NetworkAccounts/Show: { subscription: { id; code; status; blocked_at: string | null; customer: { id; name };
  //                               speed_profile: { name; radius_profile_name: string | null } | null; serving_pop: { id; name } | null };
  //                               realm: string | null; account: NetworkAccount | null; session: RadiusSession | null;
  //                               usage: { month: string; input_octets: number; output_octets: number } | null;
  //                               desiredState: 'absent' | 'enabled' | 'isolated'; isolatedBecause: ('suspended' | 'blocked')[];
  //                               radiusEnabled: boolean; can: { manage: boolean; reveal: boolean } }
  //   flash `network_account.secret` (string) once after store or reveal (renamed flash key; the UI follows)
  // Admin/Subscriptions/Show: subscription resource gains blocked_at: string | null, block_reason: string | null,
  //                           blocked_by: { id: number; name: string } | null; can gains block: boolean
  // Admin/Billing/Receivables: rows[].subscriptions[] = { id; code; status; blocked_at: string | null }; can = { suspend: boolean; block: boolean }
  ```

  `session`/`sessions`/`usage` are `null`/`[]` while the switch is off or RADIUS is unreachable (the page still renders; a notice says why).
- **Audit** (`AuditService::log`, never a secret): `service_subscription` `blocked|unblocked`; `nas_device` `created|updated|deleted|secret_changed|radius_written|radius_failed`; `network_account` `created|updated|secret_changed|secret_revealed|provisioned|provision_failed|disconnected|drift_corrected`.
- **Permissions**: add `customer.subscription.block` (admin, manager). Keep `network_asset.nas.view|manage`, `network_asset.account.view|manage|reveal` and their role mapping (`RolePermissionSeeder.php:84-88`); the seeder comment says RADIUS instead of MikroTik.
- **Runbook** (`docs/business/network.md`): FreeRADIUS 3.2 with the stock MySQL schema in its own DB; Inbils DB user grants (Q1); `read_clients = yes`; reload after NAS changes (Q12); realm policy in `authorize` (Q4) with the exact snippet proven in the lab; MikroTik side: `/radius add service=ppp,hotspot address=<freeradius> secret=<per-NAS secret>`, `/ppp aaa set use-radius=yes accounting=yes interim-update=5m`, `/radius incoming set accept=yes port=1700`, PPP profile `isolir` with address list `isolir` and the redirect to `/isolir/{company code}`, walled garden for the Inbils host and Midtrans hosts.

## Testing Decisions

- A good test drives the highest seam (HTTP route, artisan command, subscription or billing service call) and asserts what an operator would see: rows in the RADIUS tables, Disconnects recorded by the fake sender, `network_accounts`/`nas_devices`/`service_subscriptions` columns, activity log entries, HTTP status and Inertia props. Never private methods or call order.
- Seams: (1) the `radius` connection, pointed by a test trait (`InteractsWithRadius`) at an in-memory SQLite database with the stock table/column names that `RadiusDirectory` uses, created per test; (2) the Disconnect sender, bound to an in-memory fake (`InteractsWithNasCoa`) that records requests and answers ACK, NAK(cause) or timeout per NAS address. Query-builder only in `RadiusDirectory`, so SQLite and MySQL behave the same; MySQL-specific behaviour is covered by the lab e2e.
- Contract tests: `RadiusDirectory` against the SQLite schema (each method, switch off refuses, other usernames untouched, foreign `nas` rows untouched); `RadiusDisconnectClient` `encode`/`decode` against fixed byte vectors (authenticator per RFC 5176, response authenticator verified, NAK `Error-Cause` parsed, a forged response rejected) and the UDP send against a local UDP socket that never answers (timeout).
- Matrix (feature): desired-state table row by row; block/unblock with each status; overdue auto-suspend of a blocked subscription; payment reconnect (Q16) leaves a blocked subscription isolated; unblock of a suspended one stays isolated; terminate clears the block and removes the user; package change → new group + Disconnect; secret change → no Disconnect; rename → old user removed + old sessions disconnected; Disconnect timeout → `pending`, retried, `network:sync` catches up; NAK 503 → done; session on a foreign address → not sent; switch off → nothing written, nothing sent; company A job never reads company B rows; usernames globally unique without telling which company; realm validation; speed profile change rewrites the group; NAS create/update/delete writes and removes only its own `nas` row; permission 403 and other-company 404 on every route; no secret in any prop, flash (except the one-time value), audit, log line or exception message.
- Prior art: `tests/Feature/NetworkAsset/ProvisioningFlowTest.php`, `NetworkSyncTest.php`, `NetworkAccountHttpTest.php`, `NasDeviceHttpTest.php`, `SecurityHardeningTest.php`, `tests/Traits/BuildsNetworkFixtures.php`, `tests/Feature/Billing/AutoReactivateOnPaymentTest.php`, `tests/Feature/Billing/OverdueSuspensionTest.php`, `tests/Feature/Billing/IsolirPolicyGapTest.php`, `tests/Feature/SubscriptionServiceTest.php`, `tests/e2e/mikrotik-provisioning.spec.ts` (page flows to port), `tests/e2e/datatable-e.spec.ts:114-139` (Receivables isolir dialog).
- Lab e2e (ticket 06, opt-in `E2E_RADIUS_LAB=1`): local `.env.e2e` (gitignored, `.gitignore:6`; the e2e server reads only `.env.e2e`, `scripts/e2e-server-env.mjs`) carries the lab RADIUS values and `QUEUE_CONNECTION=sync` (`.env.e2e:16`), so the Disconnect happens inside the click. Register the NAS (`nasname 172.30.77.20`, management IP `127.0.0.1`, CoA port `13799`), create the account, start `sim.py scenario block-unblock <user> --expect-group isolir`, Block in the UI → Disconnect reaches the sim (`sim events` ACK) → reconnect lands in group `isolir` (exit 0) → Unblock → the isolir session gets a Disconnect, `sim connect` again gets `Mikrotik-Group` = the speed profile name.
- Gates: `security-reviewer` APPROVED (repo rule 10); `composer audit` and `npm audit` pasted (rule 11; `composer.json` changes by removal); scoped Pint, Larastan, ESLint; `npm run build`; full PHPUnit under the existing flock (`./vendor/bin/sail exec laravel.test flock /tmp/inbils-phpunit.lock php -d memory_limit=1G artisan test --compact`).

## Out of Scope

- Static IP via `radreply Framed-IP-Address` (ipam-odn follow-up), IPv6 delegation, VLAN attributes.
- Burst rates (needs SpeedProfile threshold/time columns), CoA rate changes without reconnect, hotspot vouchers, prepaid, quotas, FUP.
- Managing FreeRADIUS itself (process, config, reload, dynamic clients), RADIUS accounting retention or `radacct` pruning, RadSec.
- Customer-portal or mobile API exposure of block state or usage.
- Notifications to subscribers on block/unblock.
- NAS health monitoring (reachability probe and outage notification are removed with RouterOS).
- A physical NAS shared by two companies.

## Further Notes

### Ticket order
{01, 02, 03} → 04 → 05 → 06 → 07. 01-03 are independent and additive (nothing breaks while they land). 04 is the clean cutover: it deletes RouterOS and switches every caller to RADIUS in one change. `tests/e2e/mikrotik-provisioning.spec.ts` fails between 04 and 06 (06 replaces it); do not run it in that window.

### Cross-lane
- `ipam-odn` and the WhatsApp lane also edit `app/Services/Core/SubscriptionService.php`, `database/seeders/RolePermissionSeeder.php`, `routes/console.php` or `BillingService.php`: land serially.
- `.scratch/mikrotik-radius` tickets 08 (CHR lab evidence) and 09 (RouterOS 6 binary API) are superseded by this lane.
- `resources/js/lib/navigation.ts` keeps "NAS Routers" (`:267-271`); no change, so `tests/fixtures/sidebar-destinations.json` stays valid.

### File ownership
Every path belongs to exactly one ticket. Tickets 06 and 07 own no production files.

| Path | Ticket |
| --- | --- |
| `config/database.php`, `config/services.php`, `.env.example`, `.env.docker.example` | 01 |
| NEW `Modules/NetworkAsset/app/Services/Radius/RadiusDirectory.php`, NEW `Modules/NetworkAsset/app/Services/Radius/RadiusException.php` | 01 |
| NEW `tests/Traits/InteractsWithRadius.php`, NEW `tests/Feature/NetworkAsset/RadiusDirectoryTest.php` | 01 |
| NEW `Modules/NetworkAsset/app/Services/Radius/RadiusDisconnectClient.php`, NEW `Modules/NetworkAsset/app/Services/Radius/DisconnectResult.php` | 02 |
| NEW `tests/Traits/InteractsWithNasCoa.php`, NEW `tests/Support/FakeDisconnectClient.php`, NEW `tests/Unit/RadiusDisconnectClientTest.php` | 02 |
| NEW `database/migrations/2026_10_16_100000_add_block_columns_to_service_subscriptions_table.php` | 03 |
| `app/Models/Core/ServiceSubscription.php`, `app/Services/Core/SubscriptionService.php`, `app/Http/Controllers/Admin/SubscriptionController.php`, `app/Http/Resources/SubscriptionResource.php`, `routes/admin.php` | 03 |
| `database/seeders/RolePermissionSeeder.php`, `tests/Unit/RolePermissionSeederTest.php` | 03 |
| `Modules/Billing/app/Services/BillingService.php`, `Modules/Billing/app/Http/Controllers/InvoiceController.php` | 03 |
| `docs/business/customer.md`, `docs/business/billing.md`, NEW `tests/Feature/SubscriptionBlockTest.php` | 03 |
| DELETE `Modules/NetworkAsset/app/Services/{RouterOsApi,RouterOsClient,RouterOsBinaryClient,RouterOsClients,ConvertsRouterOsRecords,RouterOsException,NasDeviceHealth}.php` | 04 |
| DELETE `Modules/NetworkAsset/app/Jobs/SyncNasDeviceJob.php`, `Modules/NetworkAsset/app/Notifications/NasDeviceUnreachableNotification.php`, `Modules/NetworkAsset/app/Observers/NetworkAssetEndpointObserver.php` | 04 |
| `Modules/NetworkAsset/app/Services/{NetworkAccountProvisioner,NasDeviceService,NetworkAccountService}.php`, `Modules/NetworkAsset/app/Jobs/ApplyNetworkAccountJob.php`, `Modules/NetworkAsset/app/Console/SyncNetworkDevices.php` | 04 |
| `Modules/NetworkAsset/app/Observers/ServiceSubscriptionNetworkObserver.php`, NEW `Modules/NetworkAsset/app/Observers/SpeedProfileRadiusObserver.php`, `Modules/NetworkAsset/app/Providers/EventServiceProvider.php`, `Modules/NetworkAsset/app/Providers/NetworkAssetServiceProvider.php` | 04 |
| `Modules/NetworkAsset/app/Models/{NasDevice,NetworkAccount}.php`, `Modules/NetworkAsset/database/factories/{NasDeviceFactory,NetworkAccountFactory}.php` | 04 |
| NEW `Modules/NetworkAsset/database/migrations/2026_10_16_110000_move_nas_devices_and_network_accounts_to_radius.php`, NEW `Modules/NetworkAsset/database/migrations/2026_10_16_110001_append_realm_to_network_account_usernames.php` | 04 |
| `Modules/NetworkAsset/app/Http/Controllers/{NasDeviceController,NetworkAccountController}.php`, `Modules/NetworkAsset/app/Http/Requests/{StoreNasDeviceRequest,UpdateNasDeviceRequest,StoreNetworkAccountRequest,UpdateNetworkAccountRequest,UpdateNetworkAssetRequest}.php`, `Modules/NetworkAsset/app/Http/Resources/{NasDeviceResource,NetworkAccountResource}.php`, `Modules/NetworkAsset/routes/web.php` | 04 |
| `composer.json`, `composer.lock`, `Dockerfile`, `routes/console.php` | 04 |
| `database/seeders/E2eFixtureSeeder.php`, `database/seeders/Demo/NetworkAccessDemo.php` | 04 |
| DELETE `tests/Feature/NetworkAsset/{RouterOsClientTest,RouterOsBinaryClientTest,RouterOsApiContractTestCase,RouterOsRestContractTest,RouterOsBinaryContractTest}.php`, `tests/Support/{FakeRouterOs,FakeBinaryRouterOs}.php`, `tests/Traits/InteractsWithRouterOs.php` | 04 |
| `tests/Feature/NetworkAsset/{NasDeviceHttpTest,NetworkAccountHttpTest,ProvisioningFlowTest,NetworkSyncTest,NetworkAccessModelsTest,NetworkAccessGapTest,SecurityHardeningTest}.php`, `tests/Feature/Billing/IsolirPolicyGapTest.php`, `tests/Traits/BuildsNetworkFixtures.php`, `tests/Feature/DemoDataSeederTest.php`, `tests/Feature/Api/V1/Matrix/BuildsMatrixWorld.php` | 04 |
| `docs/business/network.md`, `docs/business/service.md`, `docs/ENTERPRISE_ISP_READINESS.md`, `CHANGELOG.md` | 04 |
| `resources/js/Pages/Admin/NasDevices/{Index,Create,Edit,Show}.tsx`, `resources/js/Pages/Admin/NetworkAccounts/Show.tsx` | 05 |
| `resources/js/Pages/Admin/Subscriptions/Show.tsx`, `resources/js/Pages/Admin/Billing/Receivables.tsx` | 05 |
| `resources/js/types/network-asset.d.ts`, `resources/js/types/models.ts`, `resources/js/Components/composite/NetworkAccessBadges.tsx`, DELETE `resources/js/lib/nasApiProtocol.ts` | 05 |
| NEW `tests/e2e/radius-provisioning.spec.ts`, DELETE `tests/e2e/mikrotik-provisioning.spec.ts`, NEW `tests/Feature/NetworkAsset/RadiusGapTest.php`, `tests/e2e/setup.php` (only if fixtures need it) | 06 |

### Deploy and rollback
- Deploy: FreeRADIUS + its DB per runbook first; `composer install`; `php artisan migrate --force`; `php artisan db:seed --class=RolePermissionSeeder --force`; set `RADIUS_DB_*` and `RADIUS_ENABLED` on the host; enter each NAS's RADIUS secret on its NAS page, reload FreeRADIUS; `php artisan network:sync` writes groups and users; then point each router at RADIUS (one at a time, maintenance window) and check online sessions on its NAS page.
- Rollback, in order: `RADIUS_ENABLED=false` (RADIUS keeps its rows; logged-in sessions keep working; no Disconnects) → if needed, routers back to local secrets by hand → migrations down (dropping the block columns loses block state; the reshaped NAS/account columns come back empty).
