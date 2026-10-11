# MikroTik RouterOS provisioning and isolir (PPPoE / hotspot): lab pilot

Status: ready-for-agent
Ledger: none yet (new lane promoted from the ISP roadmap; add the ledger entry when the owner approves)
Source: `.scratch/isp-roadmap/spec.md` rows 1 "MikroTik / RouterOS provisioning" and 2 "RADIUS / NAS integration", plus its note that auto-suspend on overdue and auto-reactivate on payment belong with items 1-2.
Optional upstream: `.scratch/ipam-odn/` (static IPv4 per subscription). Not needed for v1, see Further Notes.

> Router API passwords and subscriber PPPoE/hotspot passwords are secrets. They exist only encrypted in the database and on the routers, never in a repo file, test, log, export, screenshot or ticket comment. Tests and lab notes use obviously fake values. Nothing starts before the owner answers the decisions below; "setuju rekomendasi" approves the recommended option of every item.

Approved by owner 2026-10-12: recommended option of every decision ("setuju rekomendasi"). Q3 (RouterOS version): owner does not know yet; build the RouterOS 7.1+ REST client first and track a RouterOS 6 API fallback as a separate follow-up ticket.

## Owner decisions

1. **Q1: Scope conflict and the lab gate.** `docs/ENTERPRISE_ISP_READINESS.md:7` excludes "Radius/NAS automation" and `:279` lists "Radius/NAS provisioning and suspension automation" as P2/YAGNI; `docs/plans/ENTERPRISE_ISP_EXECUTION_PLAN.md:482` defers it until a "Lab environment and rollback contract available".
   (a) Approve as a **lab pilot**: build and accept against a MikroTik CHR virtual router (the Free licence runs indefinitely with all features and a 1 Mbps upload limit per interface, see External contract), then one production router in a maintenance window. Rollback contract: an installation switch `ROUTEROS_PROVISIONING_ENABLED` (default `false`, no router traffic at all while off), a per-router write flag (default off), and routers keep their last configuration when Inbils stops writing. Amend the readiness doc.
   (b) Keep it excluded and close the lane.
   **Recommend (a).** Tradeoff: production writes start router by router, only after the lab evidence in ticket 08. Ticket 01 (billing auto-isolir and reconnection) is a status flip with no router and can ship on its own.

2. **Q2: Integration mode.**
   (a) **RouterOS API direct.** Inbils writes the PPP secret or hotspot user on the subscriber's router, isolates by switching its profile and dropping the active session, and reads status through the same API. No new server, immediate effect, one channel for writes and status. Cost: Inbils must reach every router's API over the management network, router credentials live in Inbils, manual router edits drift (the sync corrects them).
   (b) **FreeRADIUS SQL.** Inbils writes the standard FreeRADIUS tables (`radcheck` with `Cleartext-Password`, `radusergroup`, `radgroupreply` with `Mikrotik-Rate-Limit` / `Framed-Pool`, `nas`) in a separate database; routers authenticate against FreeRADIUS; an immediate isolir needs a Disconnect-Message to the router's `/radius/incoming` (RouterOS supports Disconnect and CoA, not POD, and cannot change the address or pool by CoA). Gains: central AAA, accounting (online/usage history), scale across many NAS. Costs: a new FreeRADIUS service with UDP 1812/1813 and its own DB grants, cleartext subscriber passwords in `radcheck` (PPPoE CHAP/MS-CHAPv2 needs them), a plaintext RADIUS secret per router in `nas`, usernames unique across all companies (the FreeRADIUS tables have no `company_id`), and every router reconfigured to authenticate by RADIUS.
   (c) Both (RADIUS for AAA, API for session drops and status). Most moving parts.
   **Recommend (a).** Tradeoff: no usage accounting. Revisit RADIUS when the owner needs usage accounting or one subscriber must log in on several routers. The schema uses neutral names (NAS router, network account) so a RADIUS writer can be added later without renames.

3. **Q3: Client library and transport.**
   (a) RouterOS **REST** API over HTTPS through Laravel's `Http` client (RouterOS 7.1 or later, `www-ssl` service, HTTP Basic, JSON). No new dependency; faked with `Http::fake` the same way as Midtrans (`tests/Traits/InteractsWithMidtrans.php:44-55`); outbound-HTTP prior art `Modules/Billing/app/Services/MidtransGateway.php:164-167`.
   (b) `evilfreelancer/routeros-api-php` 1.7.1 (MIT, released 2026-06-08, binary API on 8728/8729, RouterOS v6.43+ and v7). Requires `ext-sockets`, which the production image does not install (`Dockerfile:32-42`), and pulls in `spatie/ssh`; tests then need an interface plus an in-memory fake (no fake TCP server exists in `tests/`).
   (c) Our own socket implementation of the binary protocol. Rejected.
   **Recommend (a) if every NAS router runs RouterOS 7.1 or later** (the owner confirms the fleet versions); choose (b) if any router must stay on v6.
   RADIUS sub-choice, only relevant if Q2 is (b) or (c): use the standard FreeRADIUS MySQL schema (v3.2.x `schema.sql`) in its own database, not our own tables, because FreeRADIUS' default queries read exactly those tables; send Disconnect-Messages with `dapphp/radius` 3.1.0 (LGPL-3.0-or-later, released 2025-12-28, implements RFC 5176 CoA/Disconnect).

4. **Q4: Secrets, transport security, who may see a subscriber password.**
   - Storage: (a) Laravel `encrypted` cast (APP_KEY) on the subscriber password and the router API password, hidden from serialisation, never in resources, exports, audit properties or logs (precedent: `two_factor_secret`, `app/Models/User.php:57-58,75-76,127-128`); (b) plaintext, rejected; (c) hashing, impossible (the router needs the cleartext for PPPoE CHAP/MS-CHAP and for the API login). **Recommend (a).** Note: rotating APP_KEY needs `APP_PREVIOUS_KEYS` or re-entering the secrets.
   - Transport: HTTPS only, redirects not followed, and the router certificate is always verified, against the system CA store or the NOC's internal CA given in `ROUTEROS_CA_BUNDLE`. There is no "skip verification" switch, not even for the lab (the lab CHR gets a certificate from a lab CA). The router API user sits in a dedicated RouterOS group with only the policies REST writes need, without `sensitive`, because Inbils never reads router passwords back (exact policy set confirmed in the lab, ticket 02).
   - Display: a generated password is shown once after creation; later views need a one-time, rate-limited, audited reveal (the repo's one-time secret pattern is `Inertia::flash`, `app/Http/Controllers/Auth/TwoFactorAuthenticationController.php:41,50`).
   - Permissions (naming `{module}.{entity}.{action}` like `customer.subscription.suspend`, `database/seeders/RolePermissionSeeder.php:40-52`): `network_asset.nas.view`, `network_asset.nas.manage`, `network_asset.account.view`, `network_asset.account.manage`, `network_asset.account.reveal`. **Recommend:** admin all; manager and staff `nas.view` + all three `account.*` (customer service hands credentials to customers and technicians); technician none in v1 (technicians have no subscription page today); `nas.manage` (router credentials) admin only. Tradeoff: technicians keep getting credentials from customer service, as today.

5. **Q5: Queue, retry, idempotency.** Facts: database queue (`config/queue.php:16`, `.env.example:38`), one worker `queue:work --tries=3 --timeout=90` (`compose.yaml:39-48`, command `:42`), `retry_after` 90 (`config/queue.php:43`), `after_commit` false (`config/queue.php:44`), one job of prior art (`Modules/Ticketing/app/Jobs/CheckTicketSlaBreachesJob.php:19-36`).
   (a) **Desired-state convergence.** The database is the truth (subscription status plus network account). One queued job per account recomputes the router state when it runs and applies it idempotently (find by name, then create, update or delete, drop the session when the profile changed). Dispatched after commit; unique per account until processing starts and never processed twice at once; `tries` 5, `backoff` [30, 120, 300, 900], `timeout` 60 (below the worker timeout and `retry_after`); a RouterOS 4xx answer (unknown profile, bad name) fails at once without retries; `failed()` marks the account `failed` and audits it; a 15-minute `network:sync` re-queues pending work and corrects drift.
   (b) Call the router inside the web request. Rejected (repo rule 13; a slow or dead router would block staff and payments).
   (c) Fire and forget without reconciliation. Rejected.
   **Recommend (a)** on the existing database queue (no Redis queue or Horizon change). Tradeoff: seconds between a status change and the router normally, up to 15 minutes after a router outage.

6. **Q6: Multi-company isolation per router.**
   (a) A NAS router belongs to exactly one company (`BelongsToCompany`, fail-closed, `app/Traits/BelongsToCompany.php:16-41`). A network account may only point at a NAS router of its subscription's company (validated and re-checked in the service). Jobs carry the company id and run inside `CompanyService::runAs` (`app/Services/Core/CompanyService.php:88`). Usernames are unique per company. A physical router shared by two companies is **not supported**: only one company can register and provision it.
   (b) Shared routers with per-company username prefixes. Rejected for v1: two tenants writing one device, and a cross-tenant uniqueness check that leaks whether another company uses a router.
   **Recommend (a).** Tradeoff: subscribers of a second company on a shared router stay manual.

7. **Q7: Isolir policy (who, when, grace days, how).**
   - Who and when: (a) a manual suspend (Subscriptions page, Receivables "Isolir") always isolates on the router, **and** an automatic daily isolir per company, opt-in; (b) manual only; (c) automatic only. **Recommend (a).**
   - Rule, reusing the names already written in `docs/business/billing.md:183-187` as company settings next to `invoice_due_days` (`app/Http/Controllers/Admin/CompanyController.php:56-60`): `auto_suspend_enabled` (default false) and `auto_suspend_overdue_days` (default 7). A new `billing:suspend-overdue` runs daily at **08:00** (office hours, so customer service can take the calls; after `billing:check-overdue` at 03:00, `routes/console.php:7`) and suspends an `active` subscription that has an unpaid invoice (`sent|partial|overdue`, `paid_amount < total`) whose `due_date` is more than N days ago. The rule reads `due_date`, not `status`, because a partial payment turns an overdue invoice back into `partial` (`Modules/Billing/app/Services/BillingService.php:235-240`) until the next 03:00 run.
   - Reason source: new `service_subscriptions.suspension_source` (`manual|overdue`). Receivables "Isolir" and the automatic run write `overdue`; Suspend on the Subscriptions page writes `manual`. Today the reason exists only in the audit entry (`app/Services/Core/SubscriptionService.php:84-87`).
   - On the router: switch the secret or hotspot user to the router's isolir profile (name per router, default `isolir`, created once by the NOC with an address list, a redirect and a walled garden) and drop the active session. Isolir never deletes the secret.
   - Consequence to confirm: suspended subscriptions are not invoiced (`generateForPeriod` bills only `active|terminated`, `Modules/Billing/app/Services/BillingService.php:54`), so automatic isolir also stops their recurring invoices.
   Tradeoff: automatic isolir is off until each company switches it on.

8. **Q8: Isolir redirect page.**
   (a) Public Inbils page `GET /isolir/{company code}` (company `code` is unique, `database/migrations/2026_06_30_134628_create_companies_table.php:14`): company name, phone and e-mail, a sentence saying the service is isolated because of an unpaid bill, and a link to the login that leads to the customer portal invoices and Midtrans Pay (`Modules/Billing/routes/web.php:28-38`). Throttled; no customer data. Each router redirects isolated subscribers there; the walled garden allows the Inbils host and the Midtrans hosts.
   (b) Redirect straight to `/login`: no new page, but no explanation and no contact details.
   (c) A static page on each router or NOC web server: no Inbils work, one HTML copy per router to maintain.
   **Recommend (a).** Tradeoff: one public route; a customer without a portal login (no e-mail) can only call the number shown.

9. **Q9: Reconnection.**
   (a) A manual reactivate always restores the speed profile on the router; **automatic** reactivation follows a payment when company setting `auto_reactivate_on_payment` is on (default true), the subscription was suspended with source `overdue` (never `manual`), and no invoice of that subscription still meets the Q7 rule. The hook runs after commit of `BillingService::recordPayment` (`Modules/Billing/app/Services/BillingService.php:208-247`), the single path for manual and Midtrans payments (`Modules/Billing/app/Services/PaymentAttemptService.php:197-204`), inside `CompanyService::runAs`.
   (b) Manual only, as today (`.scratch/midtrans-payment/spec.md:31`, Q12).
   **Recommend (a).** Tradeoff: overrides midtrans Q12 ("paying does not change the subscription status"); a payment reversed later leaves the subscription active until the next automatic isolir run.

10. **Q10: Bandwidth mapping.** A package carries a BandwidthProfile (commercial: Mbps, shared/dedicated, contention) and a SpeedProfile (technical: max and burst Mbps plus `radius_profile_name`) (`Modules/Service/database/migrations/2026_06_30_200004_create_service_packages_table.php:16-17`, `Modules/Service/database/migrations/2026_06_30_200002_create_speed_profiles_table.php:14-20`; seeded names such as `home-20m`, `Modules/Service/database/seeders/ServiceCatalogSeeder.php:34-38`).
    (a) The router profile is the SpeedProfile's `radius_profile_name`. The NOC keeps one PPP profile (and hotspot user profile where hotspot runs) per name on every router with its rate limit, burst and queue settings. Inbils sets the name, drops the session on a change and reports "profile missing" errors.
    (b) Inbils creates and updates router profiles from the Mbps numbers. Needs burst threshold/time columns that do not exist and moves QoS design into Inbils.
    (c) One simple queue per subscriber. Rejected (thousands of queues, NOC-specific design).
    **Recommend (a).** Tradeoff: the NOC creates the profiles on each new router; a package whose speed profile has no `radius_profile_name` cannot get a network account.

11. **Q11: When does the router get the secret?** Activation happens when a reviewer approves the installation SPK (`Modules/SPK/app/Services/SpkService.php:189-210` → `Modules/SPK/app/Actions/CompleteSpkAction.php:292-298`), after the technician has submitted evidence (`Modules/SPK/app/Services/SpkService.php:175-179`; evidence is mandatory, `Modules/SPK/app/Actions/CompleteSpkAction.php:32`).
    (a) Only on activation, as briefed: the customer stays offline from the technician's visit until the approval, and the technician cannot test on site.
    (b) As soon as the network account exists while the subscription is `pending` or `active`, enabled with the package profile; activation is then a no-op on the network; a cancelled installation is cleaned up by terminating the pending subscription (`terminate` accepts `pending`, `app/Services/Core/SubscriptionService.php:116-118`).
    (c) Tie it to the SPK start (`Modules/SPK/app/Services/SpkService.php:151-152`). Rejected: NetworkAsset may not read SPK models (`docs/architecture/domain-boundaries.md:67-68`).
    **Recommend (b).** Tradeoff: service can run a few days before billing starts (proration starts at `activation_date`).

12. **Q12: Router unreachable.**
    (a) Business first. No subscription, invoice or payment action waits for or rolls back because of a router. The account shows `pending` or `failed` with the reason. Transport errors retry with backoff. A router that refused a connection is marked `unreachable_since`, and jobs for it skip the call for 2 minutes (no worker starvation during a mass isolir). The 15-minute sync probes it and re-queues everything pending once it answers. Users who may manage that company's routers get one notification (mail + database, like `Modules/Billing/app/Notifications/BillingScheduleFailedNotification.php:21-24`) per reachable → unreachable transition, none while it stays down.
    (b) Block the transition until the router confirms. Rejected.
    **Recommend (a).** Tradeoff: an unpaid subscriber keeps browsing while their router is unreachable; a paying subscriber waits up to about 15 minutes after the router returns.

13. **Q13: Test strategy.**
    (a) Every feature test runs against a **fake RouterOS at the HTTP layer**: a test trait (modelled on `tests/Traits/InteractsWithMidtrans.php`) keeps an in-memory router per host (secrets, hotspot users, profiles, active sessions, system resource) behind `Http::fake` with `preventStrayRequests`. Tests go through the highest seam (subscription service or HTTP route → `sync` queue in tests, `phpunit.xml:38` → router state). Contract tests pin each REST request and response shape with payloads recorded once from the lab CHR (no credentials). A lab smoke run (ticket 08) uses a CHR VM and a PPPoE client. No router in CI.
    (b) Interface plus in-memory fake (required under Q3b).
    (c) Dockerised CHR in CI. Rejected (QEMU/KVM on CI, licence, flakiness).
    **Recommend (a).**

14. **Q14: Secrets that already exist on routers.** Today every PPPoE secret is created by hand.
    (a) **Adopt by username.** Staff enter the existing username and password on the network account; the first apply finds the secret by name and takes it over (profile, comment `inbils:<company code>:<subscription code>`, enabled). The sync lists router secrets that no network account manages (count plus the first 100 names) so the NOC can link or delete them.
    (b) An import wizard that reads all secrets and matches subscriptions. Larger, and it needs the `sensitive` policy to read router passwords.
    (c) Manage new secrets only. Leaves every existing subscriber outside isolir.
    **Recommend (a).** Tradeoff: linking takes one form per existing subscriber; a typo creates a second secret, which then shows in the unmanaged list.

15. **Q15: Hotspot.**
    (a) A network account is either PPPoE (`/ppp/secret`) or hotspot (`/ip/hotspot/user`), with the same lifecycle and the same isolir profile name. No vouchers, no prepaid.
    (b) PPPoE only in v1.
    **Recommend (a)**, as briefed. Tradeoff: routers that run hotspot need a hotspot user profile with the isolir name as well.

## Corrections to the source and the brief

- The brief describes the location tree as region > area > POP > ODC > ODP. `locations.type` is `region|area|pop|rack|site` (`database/migrations/2026_08_11_163004_enforce_latest_closed_values_as_enums.php:12`, hierarchy `app/Services/Core/LocationService.php:17-22`); ODC and ODP are network asset types (`database/migrations/2026_08_11_163004_enforce_latest_closed_values_as_enums.php:20`). A router reaches its POP through the router asset's `location_id` (`Modules/NetworkAsset/database/migrations/2026_07_01_110000_create_network_assets_table.php:22`); a subscription reaches its POP through `serving_pop_id` (`database/migrations/2026_06_30_220003_create_service_subscriptions_table.php:24`).
- "Bandwidth from the speed/bandwidth profile": only the SpeedProfile has a router hook (`radius_profile_name`); the BandwidthProfile is commercial (`docs/business/service.md:35-37`). See Q10.
- Isolir exists today only as a status flip: Receivables "Isolir" posts `{ reason }` to `admin.subscriptions.suspend` (`resources/js/Pages/Admin/Billing/Receivables.tsx:81-83,157-171`); nothing reaches the network, and the reason is not stored on the subscription.
- `docs/business/service.md:124-126` plans "SubscriptionService calls the Radius API". That is a Core → Module call, forbidden by `docs/architecture/domain-boundaries.md:40-41`. This design inverts it: the NetworkAsset module observes the shared Core subscription model.
- Auto-reactivation was put out of scope by the midtrans lane (`.scratch/midtrans-payment/spec.md:31`) and auto-suspend by the customer-status-rollup lane (`.scratch/customer-status-rollup/spec.md:94`). This lane takes both (Q7, Q9).
- `docs/AUDIT_REPORT.md:341` suggested provisioning columns (`radius_username`, `radius_password`, `ip_address`, `provisioning_status`) on the subscription. This design uses a separate table so secrets never ride on the widely serialised subscription row (resource, exports).
- "Provisioning on activation" would leave a freshly installed customer offline until the SPK review (Q11).
- The roadmap calls IPAM/ODN prerequisites for sensible automation. v1 works without them: PPPoE takes its address from the router's own pool; static addresses come later through the ipam-odn contract.

## Current implementation map

inbils
- Subscription lifecycle (shared Core) `app/Services/Core/SubscriptionService.php`: `create :16-51` (always `pending`), `activate :54-73` (guard `:58`), `suspend :76-92` (guard `:80`; reason only in the audit entry `:84-87`), `reactivate :95-113` (guard `:99`), `terminate :116-159` (from pending, active or suspended, `:118`). No events and no observers (grep `SubscriptionStatusChanged|ServiceSubscription::observe` in `app Modules` → 0). Every transition recalculates the customer status (`:48,70,89,110,156`). The service already imports NetworkAsset and Service module classes (`:10-12`), an existing exception to `docs/architecture/domain-boundaries.md:40-41`. Status enum `pending|active|suspended|terminated` (`database/migrations/2026_08_11_163004_enforce_latest_closed_values_as_enums.php:14`).
- Subscription row: `service_package_id :15`, `ont_asset_id :23`, `serving_pop_id :24` in `database/migrations/2026_06_30_220003_create_service_subscriptions_table.php`; model `app/Models/Core/ServiceSubscription.php` (`$fillable :24-42`, `servingPop() :72-75`). No PPPoE, IP or RADIUS column (grep `pppoe|radius|username|password` → 0).
- Callers: `app/Http/Controllers/Admin/SubscriptionController.php` `activate :110-117`, `suspend :120-130` (inline `reason` validation `:125`), `reactivate :132-139`, `terminate :142-153`; `update :77-107` changes `service_package_id` with a plain `$subscription->update($data)` (`:89`), bypassing the service. Routes `routes/admin.php:94-97`, permissions `customer.subscription.*` (`database/seeders/RolePermissionSeeder.php:40-52`). SPK approval activates (`Modules/SPK/app/Services/SpkService.php:189-210`, `Modules/SPK/app/Actions/CompleteSpkAction.php:292-298`).
- Manual isolir today: `resources/js/Pages/Admin/Billing/Receivables.tsx:157-171`, gated by `customer.subscription.suspend` (`Modules/Billing/app/Http/Controllers/InvoiceController.php:218`).
- Billing `Modules/Billing/app/Services/BillingService.php`: due days from company settings (`:31-36`), `generateForPeriod` bills `active|terminated` only (`:54`), `recordPayment :208-247` (payable guard `:211`, status math `:235-240`, audit `:242-244`, no event), `checkOverdue :405-416` (sets `overdue`; no grace, no subscription effect). Scheduler `routes/console.php:7` (`billing:check-overdue` 03:00), `:11` (`tickets:sla`, 15 min), `:16` (`midtrans:reconcile`, 15 min). Command pattern `Modules/Billing/app/Console/CheckOverdueInvoices.php:20,30,38-41,56` with seam `Modules/Billing/app/Services/BillingScheduleGateway.php:19-22`; commands registered in `Modules/Billing/app/Providers/BillingServiceProvider.php:32-37`. Midtrans settlement ends in `recordPayment` inside `runAs` (`Modules/Billing/app/Services/PaymentAttemptService.php:197-204`). Auto-suspend and auto-reactivate are documented (`docs/business/billing.md:183-194`, `docs/business/customer.md:156-157`), not coded (grep `auto_suspend|auto_reactivate` in `app Modules` → 0).
- Company settings: flat keys in `companies.settings` (`app/Http/Controllers/Admin/CompanyController.php:56-60`), untyped validation (`app/Http/Requests/Admin/UpdateCompanySettingsRequest.php:18-19`), a generic page that renders every key as a switch or input (`resources/js/Pages/Admin/Company/Settings.tsx:59-90`).
- Network inventory: `Modules/NetworkAsset/database/migrations/2026_07_01_110000_create_network_assets_table.php` (`ip_address :20`, `management_ip :21`, `location_id :22`, no credential columns); `asset_type` includes `router` (`database/migrations/2026_08_11_163004_enforce_latest_closed_values_as_enums.php:20`); `Modules/NetworkAsset/app/Models/NetworkAsset.php` (`location() :88-91`); routes `Modules/NetworkAsset/routes/web.php:6-17`; permissions `network_asset.*` (`database/seeders/RolePermissionSeeder.php:72-82`); module dependencies empty (`Modules/NetworkAsset/module.json`); provider without commands (`Modules/NetworkAsset/app/Providers/NetworkAssetServiceProvider.php`); demo router asset `database/seeders/Demo/NetworkDemo.php:27`.
- Locations: tree `app/Models/Core/Location.php:43-51`; types and hierarchy as in Corrections.
- Service catalogue: see Q10; `radius_profile_name` is dormant (only CRUD, search and seeds use it).
- Platform: `CompanyService::currentId :34`, `runAs :88`; queue and worker facts in Q5; failed-job reporter `app/Listeners/ReportFailedQueueJob.php:13`; audit `app/Services/Core/AuditService.php:16`; tests use the `sync` queue (`phpunit.xml:38`); sessions are database-backed and unencrypted (`.env.example:30,32`); config precedent `config/services.php:53-61` (Midtrans block); no RouterOS or RADIUS package (`composer.json`).
- Navigation: "Network Assets" under "Network/Work Orders" (`resources/js/lib/navigation.ts:252-262`).

## External contract (read 2026-10-11)

- RouterOS REST API (https://help.mikrotik.com/docs/spaces/ROS/pages/47579162/REST+API): since 7.1beta4; `www-ssl` serves `https://<router>/rest` (`www`/HTTP since 7.9, not advised); HTTP Basic with a router user; every JSON value is a string; GET = print (property filters such as `?name=`, `.proplist`), PUT = add (returns the record), PATCH = set by `.id`, DELETE = remove (a missing id answers 404 `{"error":404,"message":"Not Found"}`), POST = any console command. Menus used: `/system/resource`, `/ppp/secret`, `/ppp/active`, `/ppp/profile`, `/ip/hotspot/user`, `/ip/hotspot/active`. **[INFERENCE, confirm in the lab in ticket 02]** hotspot active entries name the login `user`; DELETE on `/ppp/active/{id}` drops the session; the minimal user-group policies for REST writes without `sensitive`.
- RouterOS RADIUS client (https://manual.mikrotik.com/docs/authentication-authorization-accounting/radius): `Framed-Pool`, `Framed-IP-Address`, `Mikrotik-Rate-Limit` (rx/tx seen from the router), `/radius/incoming` default port 1700, Disconnect-Message and CoA supported, POD not; CoA cannot change the address, pool or routes. Only relevant under Q2 (b)/(c).
- FreeRADIUS 3.2 MySQL schema (https://raw.githubusercontent.com/FreeRADIUS/freeradius-server/v3.2.x/raddb/mods-config/sql/main/mysql/schema.sql): `radacct`, `radcheck`, `radgroupcheck`, `radgroupreply`, `radreply`, `radusergroup`, `radpostauth`, `nas`, `nasreload`.
- Packages (Packagist, read 2026-10-11): `evilfreelancer/routeros-api-php` 1.7.1 (2026-06-08, MIT, `php ^7.4|^8.0`, `ext-sockets`, `spatie/ssh ^1.8`); `dapphp/radius` 3.1.0 (2025-12-28, LGPL-3.0-or-later, `php ^7.3 || ^8.0`, RFC 5176).
- CHR licensing (https://manual.mikrotik.com/docs/getting-started/routeros-licensing/chr/chr-licensing): the Free level runs indefinitely with a 1 Mbps upload limit per interface and all other features.

## Problem Statement

Network staff create every PPPoE secret by hand on the MikroTik routers when a customer is installed, change profiles by hand when a customer changes package, and isolate unpaid customers by hand. The "Isolir" button in Inbils only changes the subscription status; the customer keeps browsing. After payment, someone has to remember to reconnect them. Isolations are forgotten (lost revenue), reconnections are forgotten (paying customers stay offline), speeds drift away from the package sold, and nobody can tell who changed what on a router.

## Solution

Each subscription gets one network account: a PPPoE secret or hotspot user on the router that serves its POP. Inbils pushes it to that MikroTik router through the RouterOS API: enabled with the package's speed profile while the subscription is pending or active, moved to the router's isolir profile when the subscription is suspended (by staff or automatically after the company's grace days), restored when it is reactivated (by staff or automatically after payment), and removed when it is terminated. Isolated subscribers are redirected to a public Inbils page that tells them why and how to pay. A 15-minute sync checks every router, corrects drift, shows who is online and lists secrets Inbils does not manage. Router and subscriber passwords are stored encrypted, every change is audited, and nothing touches a router unless both the installation switch and the router's write flag are on.

## User Stories

1. As a NOC engineer, I want to register a MikroTik router as a NAS router linked to its `router` network asset, so that inventory and provisioning share one record.
2. As a NOC engineer, I want the router's address and POP to come from that asset, so that I do not maintain them twice.
3. As a NOC engineer, I want the router API password stored encrypted and never shown again after saving, so that router credentials cannot leak through the UI.
4. As a NOC engineer, I want a "check now" action that shows reachability, RouterOS version and the last error, so that I can verify the connection before enabling writes.
5. As a NOC engineer, I want writes off by default per router while reads still work, so that I can onboard a router without changing it.
6. As a NOC engineer, I want to see which secrets on a router no network account manages, so that I can link or remove leftovers.
7. As a NOC engineer, I want manual edits to a managed secret corrected by the next sync, so that the router matches billing.
8. As a NOC engineer, I want one notification when a router becomes unreachable, so that I can act without being flooded.
9. As a NOC engineer, I want the exact isolir URL for my company shown on the router page, so that I configure the redirect correctly.
10. As a NOC engineer, I want a package whose speed profile has no router profile name rejected for network accounts, so that a subscriber never lands on a wrong or default profile.
11. As a NOC engineer, I want moving a subscription to another router to remove the secret from the old router and create it on the new one, so that no stale access remains.
12. As a customer-service agent, I want to create the network account (PPPoE or hotspot) for a subscription with a generated password shown once, so that the technician can configure the customer's device.
13. As a customer-service agent, I want the router that serves the subscription's POP suggested, so that I pick the right router.
14. As a customer-service agent, I want to enter an existing username and password for a subscriber who already has a secret on the router, so that Inbils adopts it instead of creating a duplicate.
15. As a customer-service agent, I want to reveal a subscriber's password with the action audited, so that I can help a customer reconfigure their router.
16. As a customer-service agent, I want to see whether the subscriber is online, their current address and the last sync result, so that I can answer "no internet" calls.
17. As a customer-service agent, I want a "resync" action, so that I can push the account again after a router problem.
18. As a technician, I want the secret to work while the installation is still pending, so that I can test the connection on site before submitting the SPK.
19. As a billing clerk, I want "Isolir" on the receivables page to move the subscriber to the isolir profile on the router, so that isolation really happens.
20. As a billing clerk, I want subscriptions isolated for non-payment reconnected automatically once paid, so that I do not have to remember.
21. As an owner, I want automatic isolir per company after N days past due (default 7), off until I enable it, so that I decide when automation starts.
22. As an owner, I want automatic isolir to run during office hours, so that customer service can answer the calls.
23. As an owner, I want a manual suspension (abuse, customer request) never lifted by a payment, so that automation does not undo a deliberate decision.
24. As a manager, I want a dry run of the automatic isolir, so that I can see who would be isolated before enabling it.
25. As an isolated subscriber, I want my browser redirected to a page that tells me why and how to pay, so that I can restore the service myself.
26. As a subscriber, I want my internet back within minutes after paying online, so that I am not left offline after paying.
27. As a subscriber, I want a partial payment not to switch my service on and off, so that the behaviour is predictable.
28. As a subscriber upgrading my package, I want the new speed applied right away, so that I get what I pay for.
29. As a customer-service agent, I want terminating a subscription to remove its secret from the router, so that former customers cannot connect.
30. As an operator, I want billing and subscription actions to succeed while a router is down, so that network problems never block the office.
31. As an operator, I want queued network work to catch up by itself when a router comes back, so that nothing is lost.
32. As an auditor, I want every network account change, reveal, provisioning result, isolir, reconnection and router outage in the activity log without any password, so that I can trace who did what.
33. As a company admin in a multi-company installation, I want my routers, network accounts and credentials invisible to other companies, and a network account never pointing at another company's router.
34. As an owner, I want one installation switch that stops all router traffic, so that I can roll back instantly.
35. As a developer, I want all router traffic faked in tests with stray requests refused, so that tests never touch real equipment.

## Implementation Decisions

- **Vocabulary** (candidates for the glossary): *NAS router* = a MikroTik router registered for provisioning, one per `router` network asset; *network account* = the PPPoE secret or hotspot user of one subscription; *isolir* = suspended on the network (isolir profile plus dropped session); *unmanaged secret* = a secret on a router that no network account manages.
- **Placement.** NAS routers and network accounts live in the NetworkAsset module: router inventory is network inventory, the NAS ↔ asset link stays inside one module, and no new module is needed (ADR-001 unchanged). NetworkAsset declares a module dependency on Service because it reads the package's speed profile through the shared Core subscription relations; the domain-boundaries table is updated. The billing policy (automatic isolir and reconnection) stays in Billing, which may already suspend subscriptions (`docs/architecture/domain-boundaries.md:61`). Core never calls the module: the module registers an observer on the shared `ServiceSubscription` model, which shared Core models allow (`docs/architecture/domain-boundaries.md:93-96`).
- **Schema** (every migration reversible by dropping what it adds):
  - `service_subscriptions.suspension_source` nullable ENUM `manual|overdue`, not fillable, written only by the subscription service; `reactivate` and `terminate` clear it.
  - `nas_devices`: `company_id` (tenant), `network_asset_id` (unique FK, must be a `router` asset of the same company with a `management_ip`), `api_port` (default 443), `api_username`, `api_password` (encrypted, hidden), `isolir_profile` (default `isolir`), `provisioning_enabled` (write flag, default false), `routeros_version`, `last_checked_at`, `last_seen_at`, `unreachable_since`, `last_error` (≤255 chars), `unmanaged_count`, `unmanaged_usernames` (JSON, first 100), timestamps. No soft delete; delete refused while accounts reference it. The router address is the asset's `management_ip`, never a copy.
  - `network_accounts`: `company_id`, `subscription_id` (unique FK), `nas_device_id` (FK), `service_type` ENUM `pppoe|hotspot` (default `pppoe`), `username` (unique per company), `password` (encrypted, hidden), `provisioned_nas_device_id`, `provisioned_username`, `provisioned_profile` (null = absent on the router), `provisioned_at`, `sync_status` ENUM `pending|synced|failed` (default `pending`), `last_error`, `last_attempt_at`, `last_online_at`, `current_address`, timestamps; index `(company_id, nas_device_id, sync_status)`. No soft delete and no delete action in v1.
  - No `LogsActivity` on either model (the encrypted attributes would reach the activity log decrypted); audit is explicit.
- **Desired router state**, derived when the job runs, never stored:

  | Subscription | Router state | Profile |
  | --- | --- | --- |
  | `pending` | enabled (Q11b; absent under Q11a) | speed profile `radius_profile_name` |
  | `active` | enabled | speed profile `radius_profile_name` |
  | `suspended` | isolated | NAS router `isolir_profile` |
  | `terminated` or deleted | absent | none |

- **Triggers.** (1) The module observer on `ServiceSubscription` (handled after commit) queues the apply job when `status` or `service_package_id` changed, or the subscription was deleted, and a network account exists; this also catches package changes made by `SubscriptionController::update` outside the service. (2) Saving a network account (create, NAS, username, password or type change) and "resync" queue the same job. (3) The sync re-queues pending work and drift.
- **Apply algorithm** (one code path for all triggers):
  1. Skip with `sync_status=pending` and a reason when the installation switch is off, the NAS router's write flag is off, or the router was found unreachable less than 2 minutes ago.
  2. If the secret was provisioned on another NAS router or under another username, delete it there (lookup by `provisioned_username`) and drop its sessions.
  3. Desired absent: find by username, delete if present, drop sessions.
  4. Desired enabled or isolated: find by username; create when missing (name, password, `service=pppoe` for PPP, profile, comment `inbils:<company code>:<subscription code>`, enabled), otherwise update password, profile, comment and enabled; drop the active session only when the profile changed.
  5. Record `provisioned_*`, `provisioned_at`, `sync_status=synced`, clear `last_error`; audit `provisioned` only when router, username, profile or state changed.
  Error kinds: `unreachable` (connect failure or timeout; also sets `unreachable_since`), `tls`, `server` (5xx or an unexpected redirect) → retry with backoff; `auth` (401/403) and `rejected` (4xx, with the RouterOS `message`) → fail at once. Only the RouterOS `message` field of a JSON error body is kept (capped at 255 chars); other response bodies are never echoed (the host is staff-entered).
- **RouterOS client** (REST, `https://<management_ip>:<api_port>/rest`, HTTP Basic, connect timeout 5 s, total 10 s, redirects not followed, certificate verification always on with `ROUTEROS_CA_BUNDLE` or the system store). Operations: read system resource; find one secret or hotspot user by name; create; update by id; remove by id (404 = already gone); list active sessions (optionally by name); drop a session by id (404 = already gone); list secrets or users with `.proplist`. PPPoE uses `/ppp/secret` and `/ppp/active` (key `name`); hotspot uses `/ip/hotspot/user` and `/ip/hotspot/active` (key `user`, to confirm in the lab).
- **Switches.** Installation switch off → no router traffic at all, reads included. NAS router write flag off → probes and reads (status, online, unmanaged list) only.
- **Automatic isolir rule** (decision-rich shape; evaluated per company in `runAs`, N = `auto_suspend_overdue_days`):

  ```sql
  -- eligible: suspend with source 'overdue' when auto_suspend_enabled
  service_subscriptions.status = 'active'
  AND EXISTS (SELECT 1 FROM invoices
              WHERE invoices.subscription_id = service_subscriptions.id
                AND invoices.company_id = service_subscriptions.company_id
                AND invoices.deleted_at IS NULL
                AND invoices.status IN ('sent', 'partial', 'overdue')
                AND invoices.paid_amount < invoices.total
                AND invoices.due_date < :today - INTERVAL N DAY)
  -- reconnect after a payment when auto_reactivate_on_payment:
  -- status = 'suspended' AND suspension_source = 'overdue' AND NOT EXISTS (same subquery)
  ```

- **Commands and schedule.** `billing:suspend-overdue {--company=} {--dry-run}` daily at 08:00 with `withoutOverlapping()`, same structure as `billing:check-overdue` (active companies, `runAs`, schedule gateway seam, failure notification). `network:sync {--company=} {--nas=}` every 15 minutes with `withoutOverlapping()`, dispatching one sync job per NAS router of each active company (like `tickets:sla` dispatching per tenant).
- **Sync job per NAS router.** Probe the system resource (version, `last_checked_at`, `last_seen_at`; set or clear `unreachable_since`; notify on the reachable → unreachable transition and audit both transitions). If reachable: read secrets or users and active sessions with `.proplist`; set `last_online_at` and `current_address` for online accounts and clear `current_address` for the rest in bulk updates; queue the apply job for accounts that are `pending`, drifted (missing, wrong profile, disabled) or `failed` with `last_attempt_at` older than one hour; store `unmanaged_count` and the first 100 unmanaged names. Writes nothing to the router itself.
- **Reconnection hook.** After commit of `BillingService::recordPayment`, for an invoice with a subscription, `reactivateIfCleared` runs inside `CompanyService::runAs($invoice->company_id)` and calls `SubscriptionService::reactivate` when the Q9 conditions hold. Payment math and guards are untouched.
- **Company settings** next to `invoice_due_days`: `auto_suspend_enabled` (bool, default false), `auto_suspend_overdue_days` (int 1-60, default 7), `auto_reactivate_on_payment` (bool, default true); typed validation; the generic settings page shows them without UI work.
- **Config.** `config/services.php` `routeros` block: `enabled` from `ROUTEROS_PROVISIONING_ENABLED` (default false), `ca_bundle` from `ROUTEROS_CA_BUNDLE` (path, empty = system CA store). Names only in `.env.example`.
- **Routes and permissions** (admin routes under the existing `auth, verified, require.has.company` group with the `admin.` prefix; authorisation by permission gates plus the tenant-scoped route binding, as in `app/Http/Controllers/Admin/SubscriptionController.php:66-68`; no new policy classes):

  | Method | URI | Name | Permission | Notes |
  | --- | --- | --- | --- | --- |
  | GET | `/admin/nas-devices` | `admin.nas-devices.index` | `network_asset.nas.view` | paginated |
  | GET | `/admin/nas-devices/create` | `admin.nas-devices.create` | `network_asset.nas.manage` | router assets without a NAS router |
  | POST | `/admin/nas-devices` | `admin.nas-devices.store` | `network_asset.nas.manage` | |
  | GET | `/admin/nas-devices/{nasDevice}` | `admin.nas-devices.show` | `network_asset.nas.view` | status, accounts, unmanaged names, isolir URL |
  | GET | `/admin/nas-devices/{nasDevice}/edit` | `admin.nas-devices.edit` | `network_asset.nas.manage` | |
  | PUT | `/admin/nas-devices/{nasDevice}` | `admin.nas-devices.update` | `network_asset.nas.manage` | blank password keeps the stored one |
  | DELETE | `/admin/nas-devices/{nasDevice}` | `admin.nas-devices.destroy` | `network_asset.nas.manage` | 422 while accounts reference it |
  | POST | `/admin/nas-devices/{nasDevice}/check` | `admin.nas-devices.check` | `network_asset.nas.manage` | throttle 6/min; queues the sync job |
  | GET | `/admin/subscriptions/{subscription}/network-account` | `admin.subscriptions.network-account.show` | `network_asset.account.view` | create form when none exists |
  | POST | `/admin/subscriptions/{subscription}/network-account` | `admin.subscriptions.network-account.store` | `network_asset.account.manage` | blank password = generated, flashed once |
  | PUT | `/admin/subscriptions/{subscription}/network-account` | `admin.subscriptions.network-account.update` | `network_asset.account.manage` | blank password keeps the stored one |
  | POST | `/admin/subscriptions/{subscription}/network-account/reveal` | `admin.subscriptions.network-account.reveal` | `network_asset.account.reveal` | throttle 10/min, audited, `Inertia::flash` |
  | POST | `/admin/subscriptions/{subscription}/network-account/resync` | `admin.subscriptions.network-account.resync` | `network_asset.account.manage` | throttle 6/min |
  | GET | `/isolir/{company:code}` | `isolir.show` | public | throttle 60/min; unknown code 404 |

  `admin.subscriptions.suspend` gains an optional `source` (`manual|overdue`, default `manual`).
- **Validation.** NAS router: router asset of the current company with a `management_ip`, not already registered; port 1-65535; username ≤64; password required on create, ≤128; isolir profile `[A-Za-z0-9._-]{1,64}`. Network account: NAS router of the current company; type `pppoe|hotspot`; username `[A-Za-z0-9._@-]{3,64}` unique per company; password blank or 6-64 characters without whitespace (blank = generated, 12 characters); the subscription is not terminated and its speed profile has a `radius_profile_name`.
- **Props contract** (resources never contain a password):

  ```ts
  type NasDevice = {
    id: number;
    network_asset: { id: number; code: string; name: string; management_ip: string | null;
                     location: { id: number; name: string; type: string } | null };
    api_port: number; api_username: string; has_api_password: boolean;
    isolir_profile: string; provisioning_enabled: boolean;
    routeros_version: string | null; last_checked_at: string | null; last_seen_at: string | null;
    unreachable_since: string | null; last_error: string | null;
    unmanaged_count: number; accounts_count: number; accounts_failed_count: number;
  };
  type NetworkAccount = {
    id: number; nas_device_id: number; nas_label: string; service_type: 'pppoe' | 'hotspot';
    username: string; sync_status: 'pending' | 'synced' | 'failed'; last_error: string | null;
    provisioned_profile: string | null; provisioned_at: string | null;
    online: boolean; last_online_at: string | null; current_address: string | null;
  };
  // Admin/NasDevices/Index: { nasDevices: Paginated<NasDevice>; filters; can: { create: boolean } }
  // Admin/NasDevices/Create|Edit: { routerAssets: { id; code; name; management_ip; location_name }[]; nasDevice?: NasDevice }
  // Admin/NasDevices/Show: { nasDevice: NasDevice; accounts: Paginated<NetworkAccount & { subscription: { id; code; customer_name } }>;
  //                          unmanagedUsernames: string[]; isolirUrl: string; installEnabled: boolean; can: { update: boolean; check: boolean } }
  // Admin/NetworkAccounts/Show: { subscription: { id; code; status; customer: { id; name };
  //                               speed_profile: { name; radius_profile_name: string | null } | null; serving_pop: { id; name } | null };
  //                               account: NetworkAccount | null; desiredState: 'absent' | 'enabled' | 'isolated';
  //                               nasOptions: { id; label; suggested: boolean }[]; installEnabled: boolean;
  //                               can: { manage: boolean; reveal: boolean } }
  //   flash `network_account.password` (string) once after store or reveal
  // Isolir/Show (public): { company: { name; phone: string | null; email: string | null; website: string | null }; loginUrl: string }
  ```

  `online` = `last_online_at` not older than the NAS router's `last_seen_at` minus one minute. `suggested` = the NAS router whose asset `location_id` equals the subscription's `serving_pop_id`.
- **Audit** (`AuditService::log`, never a password or a full router response): `nas_device` `created|updated|deleted|unreachable|recovered` (updates list changed field names; a new password appears only as `credentials_changed`); `network_account` `created|updated|password_changed|password_revealed|provisioned|provision_failed|drift_corrected`; `service_subscription` `suspended` gains `source`. System runs have no causer.
- **Notifications.** `NasDeviceUnreachable` (mail + database) to users who hold `network_asset.nas.manage` and can act in the router's company (`CompanyService::canActIn`), once per transition.

## Testing Decisions

- A good test drives the highest seam (HTTP route, artisan command, or the subscription/billing service call) and asserts what an operator would see: router state in the fake router, `network_accounts` and `nas_devices` columns, subscription status and source, invoices, activity log entries, notifications, HTTP status and Inertia props. It never asserts private methods or call order inside the provisioner.
- One external seam: RouterOS REST faked at the HTTP layer by a test trait with an in-memory router per host and `Http::preventStrayRequests()`. Failure modes are switchable per host: connection refused, timeout, TLS failure, 401, 400 with a RouterOS message, 500, redirect.
- Matrix:
  - Billing: grace boundary (N days → no, N+1 → yes); partial payment stays eligible; `draft|cancelled|paid` invoices ignored; another subscription's or company's invoices ignored; settings off → no-op; dry run writes nothing; manual-source suspension never auto-reactivated; reconnection through manual payment and through the Midtrans path; remaining eligible invoice blocks reconnection; rolled-back payment does not reconnect; `source` validation.
  - Client contract: method, path, `.proplist`, body keys and the presence of Basic auth (never printed) per operation; each error kind; redirect not followed; 404 on remove or drop treated as gone; certificate verification always requested; installation switch off sends nothing.
  - Models: raw database value ≠ plaintext; password absent from arrays, JSON and resources; fail-closed tenant scope; unique constraints.
  - Engine: create on active; pending enabled (Q11b); suspend → isolir profile + session dropped; reactivate → speed profile + session dropped; terminate → removed; package change through `SubscriptionController::update` → new profile + session dropped; NAS move → removed on the old router, created on the new; username rename; password change → password updated, session kept; adoption of an existing secret; idempotent re-run → zero writes; write flag off → reads only; 4xx → `failed` without retry; unreachable → `pending` + `unreachable_since` + skip window; a company A job never touches company B rows.
  - Sync: probe fields; online/offline bulk update; drift corrected and audited; unmanaged list capped at 100; one notification per outage; recovery re-queues pending accounts; failed accounts re-queued at most hourly.
  - HTTP: every route returns 403 without its permission and 404 for another company's record; validation errors; password only in the one-time flash after store or reveal; reveal throttled and audited; NAS router delete refused while referenced; isolir page shows only public company fields, unknown code 404.
- Prior art: `tests/Feature/Billing/MidtransGatewayTest.php` and `tests/Traits/InteractsWithMidtrans.php` (HTTP fakes, stray-request guard), `tests/Feature/Billing/CheckOverdueTest.php`, `tests/Feature/Billing/BillingCommandsTest.php`, `tests/Feature/Billing/PaymentStatusGuardTest.php`, `tests/Feature/Billing/MidtransWebhookTest.php`, `tests/Feature/SubscriptionServiceTest.php`, `tests/Feature/CustomerStatusRollupTest.php`, `tests/Feature/NetworkAsset/NetworkAssetLifecycleRouteTest.php`, `tests/Feature/TenantIsolation/TenantSafeWriteReferenceTest.php`, `tests/e2e/midtrans-payment.spec.ts`, `tests/e2e/datatable-e.spec.ts:114-139` (Receivables isolir dialog).
- E2E runs with the installation switch off (Playwright cannot intercept server-side router calls): NAS router form, network account form with the one-time password, reveal, Receivables isolir, account page showing `pending` with the switch reason, public isolir page.
- Lab evidence (ticket 08) replaces production proof: a CHR VM with RouterOS 7.x and a PPPoE client.
- Gates: `security-reviewer` APPROVED (repo rule 10: credentials and subscriber data); `composer audit` and `npm audit` pasted (rule 11; no new package under Q3a); scoped Pint, Larastan and ESLint; `npm run build`; full isolated PHPUnit before QA; shared `inbils_test` serialised with the flock commands.

## Out of Scope

- FreeRADIUS, RADIUS accounting and CoA (unless the owner picks Q2 b/c, which reshapes tickets 02-04).
- Static IPv4/IPv6 assignment, VLANs, DHCP or static-IP subscribers without PPPoE/hotspot (ipam-odn lane), IPv6 prefix delegation.
- Hotspot vouchers, prepaid, quotas, fair-use policies, usage reports.
- Inbils-managed router profiles or queues (Q10b/c), router configuration backup, firmware management, NMS/SNMP, OLT/ONT provisioning (TR-069).
- One subscriber on several routers, automatic router choice beyond the POP suggestion, shared routers across companies.
- WhatsApp or e-mail isolir/reconnection notices to subscribers (the WhatsApp lane decides; it adds none in its v1).
- Technician access to credentials from SPK pages, a bulk import wizard, deleting network accounts.
- Per-company schedule times or timezones for the automatic isolir.

## Further Notes

### Ticket order
{01, 02} → 03 → 04 → 05 → 06 → 07 → 08. Ticket 01 has no router dependency and can ship alone. Ticket 03 waits for 01 only because both add a schedule entry to `routes/console.php`.

### Cross-lane
- `ipam-odn` also edits `app/Services/Core/SubscriptionService.php` and `database/seeders/RolePermissionSeeder.php`: land serially. Its fixed contract `Modules\Ipam\Services\IpAssignmentService::staticAddressFor(ServiceSubscription $subscription): ?string` plus event `Modules\Ipam\Events\IpAssignmentChanged(int $companyId, int $subscriptionId)` (after commit) is consumed by one follow-up ticket after both lanes ship: write the address as PPP `remote-address` and re-queue the account on the event.
- The WhatsApp lane also edits `BillingService::recordPayment` (after the audit at `:242-244`) and `routes/console.php`: land serially.
- Midtrans Q12 is superseded by Q9: ticket 01 adds one sentence to "Pembayaran online" (`docs/business/billing.md:255`) saying a settled payment can reconnect an overdue-isolated subscription.
- `customer-status-rollup` is unaffected: every transition still recalculates the customer status.
- `resources/js/lib/navigation.ts` is shared with other UI lanes: land serially.

### File ownership
Every path below belongs to exactly one ticket, except `routes/console.php`, which ticket 01 edits first and ticket 03 (blocked by 01) edits second. Tickets 07 and 08 own no project files.

| Path | Ticket |
| --- | --- |
| NEW `database/migrations/2026_10_13_100000_add_suspension_source_to_service_subscriptions_table.php` | 01 |
| `app/Services/Core/SubscriptionService.php` | 01 |
| `app/Http/Controllers/Admin/SubscriptionController.php` | 01 |
| `app/Http/Controllers/Admin/CompanyController.php` | 01 |
| `app/Http/Requests/Admin/UpdateCompanySettingsRequest.php` | 01 |
| `Modules/Billing/app/Services/BillingService.php` | 01 |
| NEW `Modules/Billing/app/Services/OverdueSuspensionService.php` | 01 |
| `Modules/Billing/app/Services/BillingScheduleGateway.php` | 01 |
| NEW `Modules/Billing/app/Console/SuspendOverdueSubscriptions.php` | 01 |
| `Modules/Billing/app/Providers/BillingServiceProvider.php` | 01 |
| `routes/console.php` | 01, then 03 |
| `docs/business/billing.md`, `docs/business/customer.md` | 01 |
| NEW `tests/Feature/Billing/OverdueSuspensionTest.php`, NEW `tests/Feature/Billing/AutoReactivateOnPaymentTest.php` | 01 |
| `tests/Feature/SubscriptionServiceTest.php` | 01 |
| NEW `Modules/NetworkAsset/database/migrations/2026_10_13_110000_create_nas_devices_table.php` | 02 |
| NEW `Modules/NetworkAsset/database/migrations/2026_10_13_110001_create_network_accounts_table.php` | 02 |
| NEW `Modules/NetworkAsset/app/Models/NasDevice.php`, NEW `Modules/NetworkAsset/app/Models/NetworkAccount.php` | 02 |
| NEW `Modules/NetworkAsset/database/factories/NasDeviceFactory.php`, NEW `Modules/NetworkAsset/database/factories/NetworkAccountFactory.php` | 02 |
| NEW `Modules/NetworkAsset/app/Services/RouterOsClient.php`, NEW `Modules/NetworkAsset/app/Services/RouterOsException.php` | 02 |
| `config/services.php`, `.env.example` | 02 |
| `database/seeders/RolePermissionSeeder.php` | 02 |
| NEW `tests/Traits/InteractsWithRouterOs.php` | 02 |
| NEW `tests/Feature/NetworkAsset/RouterOsClientTest.php`, NEW `tests/Feature/NetworkAsset/NetworkAccessModelsTest.php` | 02 |
| NEW `Modules/NetworkAsset/app/Services/NetworkAccountProvisioner.php` | 03 |
| NEW `Modules/NetworkAsset/app/Jobs/ApplyNetworkAccountJob.php`, NEW `Modules/NetworkAsset/app/Jobs/SyncNasDeviceJob.php` | 03 |
| NEW `Modules/NetworkAsset/app/Console/SyncNetworkDevices.php` | 03 |
| NEW `Modules/NetworkAsset/app/Observers/ServiceSubscriptionNetworkObserver.php` | 03 |
| NEW `Modules/NetworkAsset/app/Notifications/NasDeviceUnreachableNotification.php` | 03 |
| `Modules/NetworkAsset/app/Providers/EventServiceProvider.php`, `Modules/NetworkAsset/app/Providers/NetworkAssetServiceProvider.php`, `Modules/NetworkAsset/module.json` | 03 |
| NEW `tests/Feature/NetworkAsset/ProvisioningFlowTest.php`, NEW `tests/Feature/NetworkAsset/NetworkSyncTest.php` | 03 |
| NEW `Modules/NetworkAsset/app/Http/Controllers/NasDeviceController.php`, `NetworkAccountController.php`, `IsolirPageController.php` | 04 |
| NEW `Modules/NetworkAsset/app/Http/Requests/StoreNasDeviceRequest.php`, `UpdateNasDeviceRequest.php`, `StoreNetworkAccountRequest.php`, `UpdateNetworkAccountRequest.php` | 04 |
| NEW `Modules/NetworkAsset/app/Http/Resources/NasDeviceResource.php`, `NetworkAccountResource.php` | 04 |
| NEW `Modules/NetworkAsset/app/Services/NasDeviceService.php`, `NetworkAccountService.php` | 04 |
| `Modules/NetworkAsset/routes/web.php` | 04 |
| `docs/business/network.md`, `docs/business/service.md`, `docs/architecture/domain-boundaries.md`, `docs/ENTERPRISE_ISP_READINESS.md`, `CHANGELOG.md` | 04 |
| NEW `tests/Feature/NetworkAsset/NasDeviceHttpTest.php`, `NetworkAccountHttpTest.php`, `IsolirPageTest.php` | 04 |
| NEW `resources/js/Pages/Admin/NasDevices/Index.tsx`, `Create.tsx`, `Edit.tsx`, `Show.tsx` | 05 |
| NEW `resources/js/Pages/Admin/NetworkAccounts/Show.tsx`, NEW `resources/js/Pages/Isolir/Show.tsx` | 05 |
| `resources/js/Pages/Admin/Subscriptions/Show.tsx`, `resources/js/Pages/Admin/Billing/Receivables.tsx` | 05 |
| `resources/js/lib/navigation.ts`, `resources/js/types/network-asset.d.ts` | 05 |
| NEW `tests/e2e/network-provisioning.spec.ts`, NEW `tests/Feature/NetworkAsset/NetworkAccessGapTest.php`, NEW `tests/Feature/Billing/IsolirPolicyGapTest.php`, `tests/e2e/setup.php` (only if fixtures need it) | 06 |

### Deploy and rollback
- Deploy: `php artisan migrate --force`; `php artisan db:seed --class=RolePermissionSeeder --force`; set `ROUTEROS_PROVISIONING_ENABLED` and `ROUTEROS_CA_BUNDLE` on the host only; queue worker and scheduler running (`compose.yaml:39-59`). Router preparation per the runbook added to `docs/business/network.md` in ticket 04: API user group, `www-ssl` certificate from the NOC CA, isolir PPP profile (and hotspot user profile where needed) with address list, redirect to `/isolir/{company code}`, walled garden for the Inbils host and the Midtrans hosts. Then per router: register with writes off → check → review the unmanaged list → link existing subscribers (adopt) → enable writes. Enable `auto_suspend_enabled` per company last.
- Rollback, in order: write flag off per router (routers keep their last state) → installation switch off (no traffic) → the NOC restores isolated subscribers by hand if needed (accounts whose `provisioned_profile` equals the isolir profile) → migrations down. Dropping `network_accounts` destroys the stored subscriber passwords (export them first or accept resetting them); dropping `suspension_source` loses the reconnection marker.
- Docs updated when shipped: `docs/business/billing.md`, `docs/business/customer.md` (ticket 01); `docs/business/network.md`, `docs/business/service.md` (replaces the v2 hook at `:124-126`), `docs/architecture/domain-boundaries.md` (module dependency table and direction), `docs/ENTERPRISE_ISP_READINESS.md` (Q1), `CHANGELOG.md` (ticket 04). No new ADR.
