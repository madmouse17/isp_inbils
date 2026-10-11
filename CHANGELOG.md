# Changelog

Semua perubahan notable di project inbils. Format [Keep a Changelog](https://keepachangelog.com/id/1.1.0/),
versi mengikuti [Semantic Versioning](https://semver.org/lang/id/) (v0.x.x
selama development, v1.0.0 saat release).

### Added - Owner-approved security and product wave (2026-10-12)

- Midtrans Snap sandbox pilot and customer invoice portal (`.scratch/midtrans-payment`): customers pay invoices from `/portal/invoices` ("Tagihan Saya") through a hosted Midtrans page (`payment_attempts`, one open attempt per invoice). `POST /webhooks/midtrans` verifies the SHA-512 signature, re-fetches the Status API and books exactly one `midtrans` payment (`payments.received_by` NULL) under `CompanyService::runAs`; anything unexpected becomes an `exception` attempt. `midtrans:reconcile` runs every 15 minutes. Staff see an "Online payments" panel on the invoice page. Laravel `Http` client, no SDK. The gateway stays off on `APP_ENV=production` while `MIDTRANS_IS_PRODUCTION=false` unless `MIDTRANS_ALLOW_SANDBOX_IN_PRODUCTION=true`; `MIDTRANS_BASE_URL` works only in local/testing/e2e; the webhook throttle (30/min per IP) counts only unsigned posts. Production stays blocked until a real merchant account, public HTTPS notification URL and signed security review exist. Deploy: `migrate`, `RolePermissionSeeder`, the `MIDTRANS_*` env values, the dashboard Payment Notification URL, a running scheduler.
- Midtrans: optional `MIDTRANS_NOTIFICATION_URL` (HTTPS only) is sent as `X-Override-Notification` on every Snap order, so notifications reach the install without editing the Midtrans dashboard. Sandbox evidence (Sail): portal pay → hosted Snap page → sandbox test card capture → Midtrans-signed status payload accepted by the webhook (one `midtrans` payment, invoice paid, replay idempotent, forged signature 403); owner closed ticket 08 on this evidence; delivery by Midtrans to a public URL is checked at go-live.
- Legacy customer logins (phone-number password) are flagged `users.must_change_password` and reach the portal only after completing an emailed password reset (the flag clears on `PasswordReset`, which also rotates `remember_token` and deletes database sessions). While flagged, a login cannot change its email on the profile page, so the set-password link cannot be redirected. Deploy step, once after `migrate` and the seeder, with a queue worker and mail running: `php artisan customers:rotate-legacy-passwords --dry-run`, then without `--dry-run` (random password + queued set-password link per flagged login; accounts without email need a staff reset).
- Private media (`.scratch/private-media`): uploads (ticket attachments, SPK evidence, customer documents) are stored on the private `local` disk and served only through `GET admin/media/{media}` (`admin.media.show`), which 404s outside the effective company and runs the owner's `view` policy; raster images render inline, PDF and every other type download (`nosniff`, `private, no-store`). The company logo stays public. `inbils:media:privatize {--dry-run}` moves only rows on the `public` disk, refuses a public, unconfigured or same-storage target, lists `Missing <id>: public:<path>` and exits 1 when media stay behind. Deploy: run it once after deploying; backups must include `storage/app/private`.
- Two-factor authentication (`.scratch/auth-2fa-passkeys`, TOTP only, opt-in): enrolment with QR code and 8 single-use recovery codes on the Profile page, login challenge with throttle and replay guard, admin reset via new permission `user.two_factor.reset` (admin only). Secrets are encrypted and excluded from the activity log. Passkeys deferred. New packages `pragmarx/google2fa` ^9.1 and `bacon/bacon-qr-code` ^3.1 (owner approved).
- Customer status rollup (`.scratch/customer-status-rollup`, isp_ari ADR-011): derived `customers.customer_status` (`prospective|active|inactive|suspended|terminated`) maintained by `CustomerStatusRecalculator` from subscription transitions, backfilled by migration, repairable with `customers:recalculate-status {--company=} {--dry-run}`. Customers list: `status` filter now means the derived status; the old active/inactive filter is `account`.
- Auth hardening from the security reviews: login refuses inactive users (same message as a wrong password); `/forgot-password` is throttled (6/min) and answers uniformly whether or not the email exists; `/confirm-password` is throttled; 2FA challenge attempts are counted before the check (5/min per user+IP, 10/15 min per user); enabling, regenerating, disabling or resetting 2FA rotates `remember_token` and ends other database sessions. Trusted hosts are enforced outside `local` (APP_URL host, its subdomains and loopback for the container healthcheck), so password-reset links cannot be built from a spoofed `Host`. Production `APP_URL` must match the public host. The customer set-password token is minted at send time (never stored in `jobs`/`failed_jobs`) and no link goes to inactive accounts. Array-valued list filters no longer raise a 500.
- Customer portal account: the portal user gets a random password and a queued "account created" email with a set-password link instead of the phone number as password; staff can resend the link (`Kirim ulang link akses`, throttled).
- Ticketing: any `ticket.close` holder (manager, admin) other than the handler who resolved it may close a `resolved` ticket (maker/checker: the handler gets 403 on the route, 422 from the service, and no Close button); customer users no longer choose a location (derived from the subscription's serving POP).

### Changed - Roles

- `RolePermissionSeeder`: new `user.two_factor.reset` (admin), `portal.invoice.view` and `portal.invoice.pay` (customer only; admin explicitly excluded). Deploy: `php artisan migrate --force` then `php artisan db:seed --class=RolePermissionSeeder --force`.

### Removed

- Unreferenced `Components/composite/tickets/TicketShowSections.tsx` and `Components/composite/spk/WorkOrderShowSections.tsx` (duplicates of the Show pages with stale `/storage/` links).

### Fixed (2026-10-11)

- Ticketing IDOR: a `customer`-role user (linked to a `Customer`) could open, list and comment on tickets of other customers of the same company. `TicketPolicy::view` now limits customer users to tickets of their own customer (fail closed when no customer is linked), the ticket index/export query is scoped the same way, and new `comment`/`attach` policy abilities guard `addComment`, `uploadAttachment` and `removeAttachment`. Staff behaviour is unchanged. Tests: `tests/Feature/Ticketing/TicketCustomerScopeTest.php`.
- Ticketing: customer users no longer receive staff-only (`is_internal`) comments on the ticket page, and their own comments are always public. `StoreTicketRequest` binds a customer user's ticket to their own customer (a foreign `customer_id` is a validation error; a missing one is filled in; a user without a linked customer gets 403), rejects network assets that do not belong to them, and the create form lists only their own customer, subscriptions and assets.
- Billing: `BillingService::recordPayment` accepted payments on `draft` and `cancelled` invoices. Only `sent`, `partial` and `overdue` invoices take payments now (422 otherwise; `paid` was already blocked by the remaining-balance check). Tests: `tests/Feature/Billing/PaymentStatusGuardTest.php`.

### Added - Local development on Laravel Sail and full demo data (2026-10-11)

- Local development runs on Laravel Sail: `compose.sail.yaml` (project `inbils-sail`; PHP 8.4, MySQL 8.4, Redis 7.4, Mailpit; ports bound to 127.0.0.1), selected by `SAIL_FILES=compose.sail.yaml`. `docker/mysql/sail/10-create-test-database.sh` creates `inbils_test` and the PHPUnit user. `compose.yaml` + `Dockerfile` remain the production image (`INSTALL_DEV` build arg, default `--no-dev`). Docs: README "Local development (Laravel Sail)", `docs/operations/DOCKER.md`.
- `DemoDataSeeder` (+ `database/seeders/Demo/*`): six months of seeded, deterministic history for every module (foundation, locations, inventory ledger, network assets, customers/SPK, billing, tickets/SLA, evaluations, notifications), run through the real services and actions under `CompanyService::runAs`, SoD-consistent and idempotent. `CustomerDemoSeeder` runs under `runAs`. Wired into the `DatabaseSeeder` local branch. `DemoUserSeeder` refuses to run in production.

### Added - Head company UI (2026-10-11)

- `CompanySwitcher` in the sidebar (only with `canSwitch`), `Admin/Reports/Consolidated` page (table, cards, currency-mismatch refusal, CSV export), Reports hub card and `Consolidated Metrics` nav item behind `companyHierarchy.can_consolidate`. E2E fixture adds subsidiary `E2E-SUB`; `tests/e2e/head-company.spec.ts`.

### Added - Head company: hierarchy, membership, fail-closed company context and consolidated Business report (2026-10-11)

- Backend `t_2cd5c5cc` (P0) and `t_25322033` (P1) of lane `head-company`, built on the owner-approved recommended option of every decision Q1-Q11 (`.scratch/head-company/spec.md`, "Approved decisions"). Original acceptance remains unrecovered. Lane closed 2026-10-11: isolation review SIGNED for single-root installs, QA/E2E green.
- Multi-company is active: `companies.parent_company_id` (Head over subsidiaries, one level), membership in `company_user` (foreign keys, one default per user, backfilled and reversible), `POST /admin/company/switch` (`company.switch` + membership, audited as `company_switched`), shared props `companies`, `canSwitch`, `companyHierarchy` and the effective `company`. Subsidiaries and memberships are managed by `inbils:company:create-subsidiary`, `inbils:company:grant-membership` and `inbils:company:revoke-membership`.
- `CompanyService` is the only authority on the effective company (`currentId()`, `idFor($user)`, `canActIn()`, `runAs()`); `BelongsToCompany` now fails closed: no company context means no rows and a create without company_id aborts 403. Every reader of `users.company_id` as a tenant moved to the resolver; seeders and `CompanySeeder` run inside `runAs`.
- `GET /admin/reports/consolidated` (+ `/export` CSV, `report.consolidated.view` admin only, `report.export` for the file): Business metrics for the Head and its active subsidiaries; total = sum of the per-company rows (SLA % pooled), mixed currencies refused, periods in each company's timezone.
- Deploy: `php artisan migrate` and `php artisan db:seed --class=RolePermissionSeeder --force`.
- Isolation review fixes: role definitions can only be created, changed or deleted from a top-level company (`RolePolicy`; role list counts users of the effective company only); user administration (view, update, delete: email, password, roles, status) requires the actor to belong to every company of the target (`CompanyMembershipService::administers`); `Company::isHead()` counts active subsidiaries only; customer portal users get their default `company_user` row.

### Added - Role persona and separation of duties: controller/operator split, two-step stock adjustment (2026-10-11)

- Backend `t_594beec6` of lane `role-persona-sod`, built on the owner-approved recommended option of every Owner decision D1-D8 (`.scratch/role-persona-sod/spec.md`, section "Approved decisions"). Original acceptance remains unrecovered. Tester `t_f4e0d462`, QA `t_81eccd66` and reviewer `t_3c2d67a9` are still open.
- Personas: `RolePermissionSeeder` now runs `syncPermissions` on the five seeded roles, so reseeding narrows existing databases and never touches custom roles. Manager is the controller: it approves or rejects inventory requests and stock adjustments (SPK approval, payment reversal and invoice cancellation as before) and no longer holds stock receive/issue/transfer/adjust or request prepare/issue. Staff is the operator: stock receive/issue/transfer, adjustment request, request prepare/issue, no approvals; it loses the Approval inbox, the dashboard approvals card and cancelling other people's submitted requests. New permissions `inventory.stock.adjust.request` (admin, staff) and `inventory.stock.adjust.approve` (admin, manager); `inventory.stock.adjust` is admin-only break-glass. `report.export` (admin, manager) is added for the `p2-export-drilldown` lane.
- Stock adjustment is two steps: new table `stock_adjustment_requests` and `StockService::requestAdjustment|approveAdjustment|rejectAdjustment`. `POST /admin/stocks/adjust` stores a pending request and stock does not move; `POST /admin/stocks/adjustments/{adjustment}/approve|reject` (new `StockAdjustmentRequestPolicy` and two Form Requests) decide it; the approver must be another user, and a locked re-read refuses a second decision. `POST /admin/stocks/adjust/direct` (admin only) is the audited break-glass. Audit events: `stock_adjustment_requested`, `stock_adjustment` (with `adjustment_request_id`, `requested_by`, `approved_by`, or `break_glass`) and `stock_adjustment_rejected`.
- Same-actor guards: invoice cancellation refuses the invoice creator; an inventory request approver cannot prepare or issue it (service and `InventoryRequestPolicy`); a requester cannot decide their own adjustment. Role hardening: a non-admin cannot assign `admin`, edit a seeded role, edit or delete an admin user (`UserPolicy`), save a role that combines both sides of `App\Support\SodConflicts` (new `NoSodConflict` rule) or grants `inventory.stock.adjust`, or stack roles on one user whose merged permissions conflict (`NoStackedSodConflict`, so `manager` + `staff` is refused). `stock_adjustment_requests.requested_by` and `approved_by` are `restrictOnDelete`, and `UserDeletionGuard` refuses to delete (or self-delete) a user who requested or reviewed an adjustment.
- UI, the minimum that makes D2 usable: the Stocks page gets an "Ajukan Penyesuaian" modal and a pending-requests panel with Setujui/Tolak for reviewers (every pending request) and requesters (their own).
- Docs: new `docs/SECURITY.md` §2 (matrix, conflict set, adjustment workflow, deploy note; it needs `!docs/SECURITY.md` in `.gitignore` to be tracked) and the stale role, permission, route and scenario statements in `docs/business/inventory.md` replaced.
- Evidence (MySQL `inbils_test`, sandbox, serial): `tests/Feature/Inventory` (new `StockAdjustmentApprovalTest`; `InventoryRequestWorkflowTest`, `MaterialReturn*`, `StockRbacAuditTest`, `StockPermissionTest` updated to controller/operator actors) with `RolePersonaSodTest`, `RolePermissionSeederTest`, `AdminMenuAuthorizationTest` and `DashboardTest` 148 tests / 1927 assertions; `Billing` + `SPK` + `RolePersonaSodTest` + `RolePermissionSeederTest` 144 / 805; `tests/Feature/Admin` 253 / 3642 (`DashboardPersonaTest` and `DashboardExceptionCountsTest` updated: staff has no approvals card); top-level `tests/Feature/*Test.php` + `tests/Unit` 303 / 2568; `Tenant`, `TenantIsolation`, `Auth`, `Service` and `Modules/Inventory/tests` 73 / 571. The role-save tests fail against the previous `StoreRoleRequest`/`UpdateRoleRequest`. Pint clean on the 30 touched PHP files; Larastan level 5 clean on the new and changed classes (existing findings remain in `StockController::find|movements`); `tsc --noEmit`, ESLint and Prettier clean on `Stocks/Index.tsx`. `Ledger`, `Operations`, `NetworkAsset` and `Ticketing` pass except `ExceptionReportingTest::test_http_error_response_does_not_leak_exception_message`, which fails because the sandbox `.env` has `APP_DEBUG=true`; `tests/Feature/Reporting` was not run.
- E2E written or updated, not run: `auth-roles`, `sidebar-ia` and `dashboard-persona` (staff has no Approval link or Persetujuan group), `inventory-lifecycle` (admin adjusts through the break-glass endpoint; new journey where staff requests and a manager approves from the Stocks page).
- Deploy: `php artisan migrate`, `php artisan db:seed --class=RolePermissionSeeder --force` (overwrites manual edits to seeded roles; custom roles get new permissions by hand) and `npm run build`.
- Known gaps: the seeded manager still holds SPK create/assign and billing create/send/record, so those duties rely on same-actor guards rather than a permission split; an administrator can still stack roles and direct per-user permissions are not checked; warehouse operators are not notified when a request is approved; adjustment approvals are not in the Approval Inbox.

### Added - Persona dashboard UI and exception queues (2026-10-11)

- UI `t_f1c27ea9` of lane `p2-persona-dashboard`, completing the frontend for the capability-gated dashboard based on the owner-approved spec. Tester `t_336a4dc4`, QA `t_09021a86`, and reviewer `t_40dc90eb` remain open.
- `Pages/Admin/Dashboard/Index.tsx` completely rewritten to consume the new `sections`, `notifications`, and `admin` Inertia props. Old un-scoped statistics (user/role totals, recent activity, module lists) are now restricted to the `Administrasi` section and rendered via an extracted `AdminOverview` component.
- Types defined in `resources/js/types/dashboard.ts` using discriminated unions on the `key` field to strictly model section payloads (`my_work`, `sla_breached`, `overdue_invoices`, etc.) matching the backend shape.
- Added `DashboardRegion` component to render h2 headings for each section group (Operasional, Persetujuan, Gudang, Keuangan, Aktivitas saya, Notifikasi) matching the server's ordered output.
- Each section renders as a `SectionCard` link with severity-tinted counts, sub-breakdowns (e.g., tickets vs. SPK for dispatch, outstanding balance for overdue invoices), and matching action chips ("Segera tangani" for danger, "Perlu tindakan" for warning).
- Empty state handles actors with no capabilities using `ResourceEmpty` to instruct them to contact an administrator, while customers with `ticket.view` only see a clean placeholder link card.
- `SlaTicketList` renders the oldest SLA-breached tickets under the SLA card with explicit priority badges and relative deadlines formatted via `formatDateTime`.
- Mobile layout reflows into a single column at 320px, expanding to an auto-fit flex layout across larger viewports without grid gaps or horizontal overflow. Focus rings use the brand `primary` offset shadow which stays visible across dark/light mode switches, replacing the default `ring` color that failed WCAG contrast on dark backgrounds.
- E2E tests added in `tests/e2e/dashboard-persona.spec.ts`: verifies exact card visibility per role according to the seeder matrix, enforces that every card destination URL answers with a 200 HTTP response, checks layout reflow and overflow constraints, and tests focus styling in both themes.


### Added - Persona dashboard backend: capability-gated exception cards (2026-10-10)

- Backend `t_0f437faa` of lane `p2-persona-dashboard`, built on the owner-approved recommended option of every Owner decision (`.scratch/p2-persona-dashboard/spec.md`, section "Approved decisions"). Original acceptance remains unrecovered. UI `t_f1c27ea9`, tester, QA and reviewer are still open: until the UI card lands, `Pages/Admin/Dashboard/Index.tsx` still reads the props removed below. No route, policy, permission, migration or seeder change; no new dependency.
- `Admin/Dashboard/Index` now receives `company`, `sections`, `notifications` and, only for actors with `users.manage`, `roles.manage` or `company.manage`, `admin`. The former top-level `userCount`, `roleCount`, `activeUserCount`, `permissionCount`, `recentActivity` and `modules` moved into `admin` (no compatibility copy), so customers, technicians and every other non-admin no longer get user, role and permission totals, the audit feed or company-wide module counts; `admin.modules` also lists only the modules whose view permission the actor holds.
- New read model `DashboardQuery` (`app/Queries/Admin`) builds `sections`: one card `{key, group, title, count, href, severity}` per queue or exception, ordered by capability group (operations, approvals, warehouse, finance, personal) and present only when the actor holds the permission that opens its destination (absent, never zero). Live company-scoped counts: approvals waiting (maker-checker), dispatch unassigned, My Work (active employee profile), SLA breached with the five oldest, overdue invoices with the outstanding total, low stock, warehouse request queue, return queue with its exceptions, own pending requests and returns, and a plain tickets link for a `ticket.view` actor with no ticket-handling or SLA-reading capability. Queue cards delegate to `ApprovalInboxQuery`, `DispatchQueueQuery`, `MyWorkQuery` and the inventory badge services; `?domain=` cannot narrow the approvals card.
- Definitions: SLA breached is `sla_deadline < now` on a ticket that is not resolved or closed, scoped like the Tickets list (a handler without `ticket.assign` counts only their own). Overdue is status `sent|partial|overdue` with `due_date` before today and `paid_amount < total`, computed, so it does not wait for `billing:check-overdue`; the invoice list shows the same set only after that scheduler flips the status. Low stock is the set of stock rows the Stocks list shows for `?low_stock=1`, inactive and soft-deleted products included.
- `notifications` is the unread count plus the latest five through `NotificationResource`, which now titles and words the seven Inventory and Material Return types. Those notifications are mail-only today (`via()` returns `['mail']`), so they reach the feed only once a `database` channel is added.
- Evidence (MySQL `inbils_test`, sandbox, serial): `DashboardTest`, new `DashboardPersonaTest` and `DashboardExceptionCountsTest`, `NotificationCenterTest`, `ApprovalInboxTest`, `ApprovalInboxInventoryTest`, `DispatchQueueTest`, `MyWorkTest`, `AdminMenuAuthorizationTest`, `AdminRouteTest`, `AdminSmokeTest`, `DemoUserSeederTest` and `P0CriticalPathTest` pass, 215 tests / 3810 assertions. The new dashboard tests fail against the previous controller (50 of 62). Larastan level 5 is clean on the four changed files; Pint clean. A dashboard visit costs 58 queries for admin, 38 manager, 28 staff, 29 technician and 3 customer, including shared props; the count does not grow with row volume.

### Added - Loading and progress feedback across the admin UI (2026-10-10)

- UI `t_b18f253d` of lane `loading-progress`, built on the owner-approved recommended option of every Owner decision (`.scratch/loading-progress/spec.md`, section "Approved decisions"). Original acceptance remains unrecovered. Tester `t_b3c6843d`, QA `t_e161eb78` and reviewer `t_02ec133d` are still open. Frontend only: no route, policy, permission, migration or backend change, and no new dependency (`@radix-ui/react-progress` was already declared).
- Navigation: `AdminLayout` sets `aria-busy` on `<main>` and announces 'Memuat halaman…' in a polite status outside `<main>` once a page navigation has run for 250ms (the Inertia bar delay). List refetches, partial reloads and form posts do not count. The Inertia bar now uses the theme token `hsl(var(--primary))`: the fixed `#4B5563` measured 2.65:1 on the dark background (below the 3:1 non-text minimum), the token is 5.17:1 on light and 5.44:1 on dark.
- Tables: `DataTable` shows as many skeleton rows as the current `per_page`, clamped to 3-10, each 48px and `aria-hidden`; the table gets `aria-busy` and a polite status says 'Memuat data…' then 'Data dimuat'. `useServerTable` and `useTableQuery` share `useListVisitState`: `processing` is on from the first keystroke, debounce window included, and feeds `loading`; the new `visiting` is true only while a visit is in flight and now drives every `disabled` (Approvals, Dispatch, My Work, Audit Log, Sales KPI, Stock Card), so typing never disables the focused input. `useTableQuery` now returns both flags. The Inventory Requests index passes `loading` to its desktop and mobile tables.
- Pages that visit with an ad-hoc `router.get` (Notifications, Evaluations, Number Sequences, Item Finder, Asset Trace, Reports Asset, Business and SLA) use the new `useVisitPending`: spinning submit button, `Pagination` disabled (new `disabled` prop) and a `LoadingState` instead of the results. `LoadingState` now defaults to 'Memuat data…' as live-region text, and it is exported with `ResourceState`, `ErrorState` and `ForbiddenState` from `Components/composite`. Reports hub cards and the notification bell replace the page, so the layout state above covers them.
- Uploads: new `Components/ui/Progress` (Radix, visible label and percentage). SPK evidence and ticket attachment feed it from Inertia `onProgress`; Company Profile and Setup Wizard from `useForm().progress`.
- `prefers-reduced-motion`: `Skeleton` stops pulsing, `Spinner` slows to 2s per turn, `Progress` drops its fill transition. `Spinner` no longer forces `text-foreground`, so it takes the text color of its button. No deferred props were introduced; the rule for the first one is in the spec. The Components showcase shows `Progress` and `LoadingState`.
- `npm run typecheck`, ESLint on every touched file, Prettier, `npm run build` (existing ~810 kB chunk warning) and `node --test resources/js/lib/navigation.selfcheck.mjs` (19/19) pass. `npm run format:check` still lists `Components/composite/locations/LocationsMapView.tsx` (Location Map lane). The whole-tree `npm run lint` runs out of memory in this environment, so ESLint ran on the touched files. A throwaway Vite render in Chromium confirmed the skeleton rows, `Progress` and the reduced-motion computed styles at 1280px light, the layout at 320px dark, and the busy-state hooks fed with simulated Inertia events. `tests/e2e/loading-progress.spec.ts` (navigation status, skeleton rows, first-keystroke busy state, Inventory Requests at 320/1280, submit pages, reduced motion, 320/1280 light and dark with bar contrast, upload percentage) is written and has not been run.

### Added - Sales KPI report (2026-10-10)

- Lane `kpi-sales`, all five cards closed (`t_40d53598`, `t_c4b6971b`, `t_6c27c12e`, `t_58229ddb`, `t_54d82ff9`) under the owner-approved recommended options (`.scratch/kpi-sales/spec.md`, "Approved decisions"). Original acceptance remains unrecovered.
- New report `/admin/reports/sales`: one row per acquirer (plus Unassigned) with customers acquired (`created_at`), subscriptions activated, active MRR, invoiced (issue date), collected (non-reversed payments by `paid_at`), outstanding and collection rate; totals over the full filtered set; server sort and page size. Current acquirer applies to history. Soft-deleted customers keep their invoices and payments in history; soft-deleted invoices and reversed payments are excluded.
- New permission `report.sales.view` (admin, manager). Registry formula `sales.v1` (7 metrics) on the page and in the Data Dictionary; hub card and Finance/Reports sidebar item. Reporting gains one read-only model, `SalesCustomer`, which refuses save/delete.
- Deploy: `php artisan db:seed --class=RolePermissionSeeder --force`; custom roles need the permission granted manually.
- Evidence: 228 tests / 5396 assertions and browser 32/32 on `inbils_test`; QA PASS, reviewer APPROVED.

### Changed - Sidebar IA: capability-safe navigation in seven entries (2026-10-10)

- UI `t_81b959f1` of lane `sidebar-ia`, built on the owner-approved recommended option of every Owner decision (`.scratch/sidebar-ia/spec.md`, section "Approved decisions"). Original acceptance remains unrecovered. Tester `t_f106f2e8`, QA `t_c969a55f` and reviewer `t_77adedd5` are still open. No route, policy, permission, migration or seeder change.
- One typed definition, `resources/js/lib/navigation.ts` (groups, items, gate, queue badge), rendered by `AdminLayout` through the existing `Sidebar`, `SidebarSection` and `SidebarItem`. Every item must carry a gate (permissions, a server flag, or both); an ungated link does not compile. Six titled groups plus a standalone Dashboard link make 7 entries. `System/Developer` is gone; Documents and Number Sequences (both `system.setting`) and Komponen moved to Company/Admin. Group titles and item labels are unchanged.
- Gates now equal the server gate of the destination. Dashboard needs `dashboard.view`. Permintaan Barang needs `inventory.request.view.own|all` (it used to show for `inventory.view` alone and answer 403). The four Service pages also accept `service.manage`. Dispatch also accepts `ticket.reassign` and `spk.reassign` and needs an active user (shared `auth.user.is_active`). Komponen needs `developer.components.view` and the new shared prop `developerGalleryEnabled` (`HandleInertiaRequests`, `app()->environment(['local', 'testing'])`, the rule of the `admin.components` route), so production no longer links a 404 and non-admins no longer link a 403. That prop is the only backend change.
- A group with no visible item renders nothing (customer and technician no longer get empty headers); `SidebarSection` also returns null without children. Exactly one item is current, the longest matching href, so `/admin/reports/technician` no longer marks Reports too. The six unused `hasXNavigation` constants are deleted. Queue badges keep their rules.
- Accepted gap: Technician Performance mirrors the route gate `report.technician` only. `ReportController` also needs the technician role or, for a manager, an organization membership, so a manager without one sees a link that answers 403. Closing it needs a server-computed flag.
- Tests (sandbox `inbils-c-sandbox`, MySQL `inbils_test`): `AdminMenuAuthorizationTest` grows from 12 to 32 tests. New: a role x destination matrix for admin, manager (in an organization), staff, technician and customer that reads the shared props of a real page, applies `tests/Feature/fixtures/sidebar-destinations.json` and expects 200 for every shown link and 403 for every hidden one (302 to My Work for a work-only user at Dispatch); custom-role cases for each former mismatch; and flag/route parity for local, testing, staging, production and e2e. `php vendor/bin/phpunit tests/Feature/AdminMenuAuthorizationTest.php` **32 tests / 739 assertions PASS**; with `ComponentGalleryAuthorizationTest`, `DashboardTest`, `P0CriticalPathTest`, `Admin/MyWorkTest`, `Admin/ApprovalInboxTest`, `Admin/DispatchQueueTest`, `Admin/NotificationCenterTest`, `AdminRouteTest` and `AdminSmokeTest` **135 tests / 1890 assertions PASS**.
- `node --test resources/js/lib/navigation.selfcheck.mjs` **19/19**: the config equals the destination table, at most seven entries, each approved decision, badges and the single active item. `npm run typecheck`, scoped ESLint, Prettier, Pint and `npm run build` pass (existing 810 kB chunk warning). E2E written, not run: new `tests/e2e/sidebar-ia.spec.ts` (groups, empty headers, every visible link opens, keyboard, 320/1280 light/dark drawer), updated `auth-roles.spec.ts` and `technician-performance.spec.ts`. Under `APP_ENV=e2e` the developer gallery is closed, so Komponen is absent for every role there.
- Deployment: rebuild the frontend (`npm run build`); no migration and no seeder run.

### Added - Customer sales attribution base: "Acquired by" (2026-10-10)

- Backend `t_ae471280` and UI `t_ffe9fc13` of lane `sales-attribution-base`, built on the owner-approved recommended option of every Owner decision (`.scratch/sales-attribution-base/spec.md`, section "Approved decisions"). Original acceptance remains unrecovered. All five cards closed (tester `t_b70b570d`, QA `t_60912019`, reviewer `t_3d697b14`): 118 tests / 1213 assertions and browser 27/27 on `inbils_test`; QA PASS, reviewer APPROVED after fixing a JSON-boolean eligibility bypass and an unguarded profile self-delete. No KPI, reporting, commission, subscription or invoice attribution.
- Schema: nullable `customers.acquired_by_user_id` (FK to `users`, `restrictOnDelete`) with index `(company_id, acquired_by_user_id)`; no backfill, existing customers stay Unassigned. The column is not mass assignable; `AssignCustomerAcquirerAction` is its only writer. `UserController::destroy` and `ProfileController::destroy` refuse, with a validation message, to delete a user who acquired customers (deactivate instead). `acquired_by_user_id` accepts only an integer id or null.
- Eligible acquirer (one source, `CustomerAcquirerQuery::eligible`, shared by option lists, the `IsEligibleCustomerAcquirer` rule and the create default): active user of the company with an active employee profile of that company who holds `customer.create`. The demo admin has no profile, so it is not eligible and defaults to Unassigned. Unknown, foreign-company and ineligible ids fail with the same 422 message, so a response never reveals another tenant's users.
- New permissions `customer.sales.assign` (set the acquirer of an Unassigned customer) and `customer.sales.correct` (change or clear a set acquirer, reason required, max 500). Seeder grants: admin both, manager both, staff assign only, technician and customer none. Attribution (Index column and filter, Show row, `CustomerResource` fields, Acquired By export column) is visible only to holders of either permission (`CustomerPolicy::viewAcquirer`); for everyone else the `acquired_by` filter is ignored, so it cannot probe attribution.
- Create: an explicit `acquired_by_user_id` (`null` = Unassigned) counts only for assign holders; otherwise the server credits the creator when eligible. Update: re-sending the current value is a no-op; a user with neither permission has the keys silently ignored; a user whose permission does not fit the change (assign-only changing a set acquirer, correct-only assigning from Unassigned) gets 403 and the whole update is rolled back. Every assign or correction writes a `customer` activity-log row (`acquirer_assigned` / `acquirer_corrected`) with `old`, `new`, `reason`, `actor_id` and company.
- Index filter `acquired_by` (user id or `unassigned`, company scoped, no sort, `SORTABLE` unchanged). Former employees stay filterable and are shown by name with an Inactive marker; they are never offered as a choice. An acquirer reference to another company renders as Unassigned.
- UI: `Admin/Customers` Create (field defaults to the creator when eligible), Edit (assign or correct, with a required `Correction reason` that appears only while a set acquirer is changed or cleared), Show and Index (column and filter) use the canonical `SearchSelect`, `DataTable` and a new `CustomerAcquirerField` composite; the control is hidden when not permitted and read-only when the capability does not fit.
- **Deployment: run `php artisan migrate`, then re-run `RolePermissionSeeder`** (`php artisan db:seed --class=RolePermissionSeeder --force`, idempotent). Until it runs the two permissions do not exist, so nobody (admin included) sees or sets attribution, although the create default still credits an eligible creator. Custom roles are not updated by the seeder: grant them the two permissions in the Roles screen.
- Tests (sandbox `inbils-c-sandbox`, MySQL `inbils_test`): new `CustomerAcquirerEligibilityTest` (6), `CustomerSalesAttributionTest` (21), `CustomerSalesAttributionVisibilityTest` (11) and two seeder tests; 37 of those 49 failed before the implementation (missing column and classes). Focused gate (`tests/Feature` customer files, the three new classes, `tests/Unit/RolePermissionSeederTest.php`, `AdminMenuAuthorizationTest`, `Reporting/ReportCapabilityTest`) **122 tests / 1244 assertions PASS**. `tests/Feature` + `tests/Unit` **893 tests / 9765 assertions with 1 failure** outside this lane: `Operations/ExceptionReportingTest::test_http_error_response_does_not_leak_exception_message` fails in the sandbox because its `.env` has `APP_DEBUG=true` (debug page renders the exception); it fails the same in isolation and touches no customer code. Modules suite 18/18. `npm run typecheck`, scoped ESLint, Prettier, Pint and `npm run build` pass (existing ~810 kB chunk warning). `tests/e2e/customer-sales-attribution.spec.ts` (manager assign and correct with reason, staff assign only, technician sees nothing, 320/1280 light/dark) was added and has not been run.

### Added - Reporting V2 formula versions, per-report capabilities and data dictionary (2026-10-10)

- Backend `t_1d565698` and UI `t_d698fabf` of lane `reporting-v2`, built on the owner-approved recommended option of every Owner decision (`.scratch/reporting-v2/spec.md`, section "Approved decisions"). Original acceptance remains unrecovered. All five cards (`t_1d565698`, `t_d698fabf`, `t_8aa097cc`, `t_ad34cbf5`, `t_a32e031e`) closed: focused gate 138 tests / 3436 assertions incl. `ReportFormulaV1Test` (31/722, v1 formula characterization), browser 13/13 on `inbils_test`, QA PASS, reviewer APPROVED. Business/SLA/Asset KPI grids and the Business/SLA filter row no longer overflow at 320px.
- Code registry `Modules/Reporting/app/Registry/` (`ReportType`, `MetricDefinition`, `MetricRegistry`): 39 figures (Business 13, SLA 7, Asset 5, Technician Performance 11, Stock Card 3), each with label, definition, formula, unit, date basis, statuses, source `table.column`s, scope, known caveats and a formula version. Every version is `v1`, the formula exactly as implemented today. No query, filter, number or visual of any report changed; known quirks (snapshot vs period figures, ranges ignored unless both dates are set, issue date instead of payment date, churn as a count, SLA `by_category` ignoring filters, all-time Business SLA) are recorded as caveats, not fixed.
- Business, SLA, Asset, Stock Card and Technician Performance responses gain a `meta` prop (`report`, `formula_version`, per-metric definitions). The four report pages show the formula version in the header and, on every KPI card, a definition, a `Snapshot|Period · v1` line and an expandable formula/sources/caveats block, all read from `meta`. Business keeps its six cards (the seven returned-but-hidden metrics stay hidden); `Active Subs` and `Suspended` are now titled `Active Subscriptions` and `Suspended Subscriptions` from the registry. Technician Performance gets a registry and dictionary entry only; its query, authorization and page are untouched.
- New read-only Data Dictionary page `admin.reports.dictionary` (`/admin/reports/dictionary`, English, static registry content, no company data), gated by `report.dictionary.view`.
- New permissions `report.business.view`, `report.asset.view`, `report.sla.view`, `report.stock_card.view`, `report.dictionary.view`. `report.view` now gates only the Reports hub; each report route and `ReportFilterRequest::authorize()` use the capability the `ReportType` catalog assigns (an unknown route fails closed). `report.technician`, `report.audit.view` and `report.manage` are unchanged. Seeder grants: admin all; manager and staff the five new ones, so effective access of every seeded role is unchanged; technician and customer none.
- **Deployment: re-run `RolePermissionSeeder`** (`php artisan db:seed --class=RolePermissionSeeder --force`, idempotent, only adds permissions and grants). Until it runs, the four legacy reports and the dictionary answer 403 for everyone, admin included. Custom roles that held `report.view` are not updated by the seeder: grant them the per-report permissions in the Roles screen.
- The Reports hub now lists server-provided cards: only reports the user may open, each a real link, an empty state when none. This also stops offering the Audit Log card to users without `report.audit.view` (it used to 403 on click). The sidebar is unchanged.
- Backend `tests/Feature/Reporting` + `tests/Feature/ReportFilterValidationTest.php` + `tests/Feature/AdminMenuAuthorizationTest.php` + `tests/Unit/RolePermissionSeederTest.php` **107 tests / 2715 assertions PASS** in `inbils-c-sandbox`, MySQL `inbils_test` (60 tests / 698 assertions before the lane). New coverage: role x report allow/deny matrix, per-report custom roles, hub card lists, route/catalog alignment, registry/payload key contract and v1 versions, dictionary gating and completeness, tenant scope, seeder grants and idempotent re-run. `npm run typecheck`, scoped ESLint, Prettier, Pint and `npm run build` pass (existing ~810 kB chunk warning). `tests/e2e/reporting-dictionary.spec.ts` was added and has not been run.

### Changed - Recovery lanes approval and local tracker (2026-10-10)

- Owner approved the recommended option of every Owner decision for six recovery lanes: `reporting-v2`, `sales-attribution-base`, `kpi-sales`, `sidebar-ia`, `loading-progress`, `p2-persona-dashboard` (29 cards `needs_recovery` → `pending`). `head-company`, `p2-export-drilldown`, `role-persona-sod` remain held. Original acceptance remains unrecovered for all nine lanes.
- mattpocock skills configured with a local markdown tracker: `docs/agents/issue-tracker.md`, `docs/agents/domain.md`, `## Agent skills` block in `CLAUDE.md`; lane specs at `.scratch/<lane>/spec.md`, one ticket per card under `.scratch/<lane>/issues/`. `.gitignore` allowlists `docs/agents/`. `AGENTS.md` doc references now point to `docs/architecture/*`, `docs/business/*`, `docs/standards/*`.

### Fixed - Reporting P1 replacement acceptance (2026-10-07)

- Close all five Reporting cards `t_316be420`, `t_fefb9a68`, `t_a7a1bda9`, `t_ecc8a59f`, `t_9e203f31` under the user-approved replacement contract. Original rubric remains unrecovered.
- Stock Card now paginates and sorts the movement ledger on the server (`StockCardQuery`, new `StockMovementResource`), allowlisting `created_at`/`quantity`/`balance_after` for both `sort` and `sort_by`, with page sizes 10/25/50. A product from another company returns 404; a soft-deleted product from the same company keeps its ledger. The page uses the canonical `DataTable`, keeps the Product ID/From/To form, preserves a deep-linked `location_id` across sort/page visits, and shows empty/loading states.
- Audit Log empty-scope guard changed from `whereRaw('0 = 1')` to `whereKey([])`; its DataTable is unchanged. No exports, opening balance, reconciliation, aggregate changes, or Technician Performance changes.
- Backend `tests/Feature/Reporting` + `tests/Feature/ReportFilterValidationTest.php` **42 tests / 547 assertions PASS** in `inbils-c-sandbox`, MySQL `inbils_test`; `tests/e2e/reporting-stock-card.spec.ts` + `tests/e2e/reports-audit-log.spec.ts` **2/2 PASS** on `inbils_test` (the Audit Log spec confirms the non-Stock-Card sort rule still works). Independent QA PASS; reviewer APPROVED after recheck. Build, scoped ESLint, and Pint PASS. No full-suite claim.

### Changed - Reporting P1 replacement scope approval (2026-10-07)

- User approved the replacement contract for all six Reporting report surfaces; original acceptance remains unrecovered. Preserve each surface's existing filters, formulas, and visuals. Five-card sequence: backend `t_316be420`, UI `t_fefb9a68`, tester `t_a7a1bda9`, QA `t_ecc8a59f`, reviewer `t_9e203f31` (closed above).
- Stock Card scope is server pagination/sorting on the existing movement ledger only. No opening balance or reconciliation work. Audit Log retains the canonical DataTable; replace only its raw-SQL empty-scope guard with an Eloquent guard. Existing SQL aggregates remain unchanged.
- No new exports. Completed Technician Performance is excluded from code changes.

### Fixed - DataTable E replacement acceptance (2026-10-07)

- Close all five E cards `t_ef68d35a`, `t_353135bf`, `t_333772ab`, `t_9bc28da4`, `t_9df467ae` under the user-approved Receivables-only replacement contract. Original rubrics remain unrecovered. Receivables is Eloquent-only, with no export.
- Focused Receivables backend **8 tests / 131 assertions PASS** and Billing suite **72 tests / 456 assertions PASS** in Docker default isolated project `inbils-c-sandbox`, MySQL `inbils_test`. Canonical `tests/e2e/datatable-e.spec.ts` **4/4 PASS** (`artifact://1082`) on default host-port-3307 MySQL `inbils_test`; assertions cover 320/1280 themes, sort/page/reload, full totals, customer role 403, and suspension modal label/reason validation, cancel, and Escape. No distinct tenant-database deployment or full-suite result is claimed.
- Independent QA recheck PASS and final reviewer APPROVED. `npm run build` PASS (`artifact://1074`, existing 809.82 kB chunk warning); scoped ESLint and Pint PASS. Browser rendered empty-table state but did not force an empty dataset or mutate empty data; screenshot captured without visual inspection.
- Invoice Show embedded Items/Payments and customer embedded invoice history remain excluded. If separately authorized later, embedded tables must be server-side. Reporting remains held and unauthorized; E closure grants no export, embedded-table, or Reporting scope. Hermes and the immutable source snapshot were not changed.

### Fixed - DataTable C replacement acceptance (2026-10-07)

- Cut Products, Categories and Units over to the canonical server DataTable. Stocks and Stock Movements now honor allowlisted ordering and page size, preserve query state, and use matching index/export ordering.
- Replace raw low-stock filtering with Eloquent `whereHas`/`whereColumn`; retain tenant scoping and include soft-deleted products with `withTrashed()` to preserve historical low-stock membership.
- Align Requests/Returns query defaults with server pagination and preserve their separate query namespaces. Extract shared Requests constants/types from the page module to restore its missing Vite page entry. Products/Stocks/Movements menus explicitly offer supported CSV/PDF exports; no Requests/Returns export or Suppliers surface was added.
- Close all five C cards under the user-approved replacement contract: `t_dcf232f5`, `t_23e46c02`, `t_ee9c7127`, `t_43e33f86`, `t_39d87109`. Independent `InventoryCIndependentQA` PASS and `InventoryCFinalReview` APPROVED with no unresolved findings; original rubrics remain unrecovered.
- Record failing-before evidence: 4 tests / 75 assertions, 3 failures. Final Inventory gate passes 65 tests / 773 assertions; focused Stock gate passes 7 tests / 394 assertions. Backend verification used MySQL `inbils_test` in isolated `inbils-c-verify` on Docker `desktop-linux`.
- Canonical `tests/e2e/datatable-c.spec.ts` passes 9/9 (`artifact://858`), including Requests/Returns default pagination and namespace preservation, export menu formats, ordered off-page CSV and PDF responses, with clean teardown. Browser fixtures used the default Docker daemon's MySQL `inbils_test` on host port 3307: a distinct database instance from backend verification despite the same name.
- CSV evidence is an HTTP attachment response, not a browser download event. Screenshots were captured but could not be visually inspected in the tool; viewport/overflow/theme assertions do not establish visual screenshot review. No fresh full-suite pass or unmeasured coverage is claimed.
- Build passes (`artifact://851`) with the existing approximately 809 kB main-chunk warning; scoped ESLint and Pint pass. Rebuild/recreate only `app` with explicit `--context default`; Compose healthy, `/up` and `/ready` return 200, backup status remains unknown. Initial `desktop-linux` recreation failed because the default daemon already served port 8080; the actual default runtime was then refreshed without volume deletion.

### Fixed - DataTable D replacement acceptance (2026-10-07)

- Close all five D cards under the user-approved replacement contract: backend `t_69dd2c1a`, UI `t_40d41840`, tester `t_1b37b49b`, QA `t_0f7d528b`, reviewer `t_3b241cec`. Original rubrics remain unrecovered; E and Reporting remain held.
- Ticket filtering is Eloquent fail-closed and includes priority in shared filters. SPK has an explicit sortable allowlist, honors `per_page`, and protects both view and export authorization. Network Assets use canonical fields. All three indexes use server pagination and existing CSV/PDF exports.
- Shared export now produces actual Dompdf output through the existing escaped export view; remote resources are disabled and the query is capped at 5,000 rows. Memory behavior at the cap was not measured.
- Baseline WorkOrder regression failed: 1 test / 1 assertion, HTTP 500 from undefined `SORTABLE`. Final isolated backend gate passes **22 tests / 617 assertions** in `inbils-c-sandbox`, MySQL `inbils_test`; canonical `tests/e2e/datatable-d.spec.ts` passes **3/3** (`artifact://965`). No full-suite claim. Browser proved PDF signature/attachment; standalone `pdftotext` content smoke was not run.
- Initial QA found export-only authorization bypass. Adding `Gate::authorize('viewAny', WorkOrder::class)` made the regression's prior HTTP 200 become forbidden (403); focused regression passes 1/1. Independent QA recheck `DQARecheck` PASS; `DFinalReview` APPROVED.
- Scoped ESLint/Pint PASS; build PASS (`artifact://914`, existing 809.80 kB chunk warning). Default Docker app rebuilt/recreated, Compose app healthy, `/up` and `/ready` 200; backup status unknown. Initial startup `/up` probe returned 000, followed by successful health checks. No commit, Hermes mutation, or original rubric recovery is claimed.

### Fixed - DataTable A+B replacement acceptance (2026-10-03)

- Fix the Organization index crash (HTTP 500 from a missing `SORTABLE` allowlist) and honor the `per_page` selection, keeping sort and query preserved through pagination links.
- Share one Eloquent `filteredQuery` between the invoice index and export so date-range and customer-name searches affect both identically; honor `per_page` in the four Service catalogue controllers.
- Fix `ExportQuery::normalizeMap` so callable row mappers write one cell per labelled column for both `'field' => 'Header'` and `'Header' => 'data.path'` column shapes, memoized per row; previously every CSV/PDF cell held the whole row JSON-encoded. Covered by a new invoice CSV column regression that fails on the old implementation.
- Replace the static Employees table with the canonical server-side `DataTable` (search, sort, pagination, existing export) and remove the duplicate per-page selector on Organizations.
- Align Service catalogue page columns and sort keys with the backend allowlists and resources (`price_mrc`/`price_otc`, `download_mbps`/`upload_mbps`, `download_max_mbps`/`upload_max_mbps`, `response_time_hours`/`resolution_time_hours`), expose `created_at` in the four Service resources, and normalize the invoice page paginator from the resource meta via `toPagination`.
- Add accessible names: shared `Rows per page` per-page control and bound Active/SLA Tier filter labels on Packages.
- Verify the user-approved 11-index replacement matrix (Users, Roles, Permissions, Customers, Organizations, Employees; Invoices, Service Packages, Bandwidth Profiles, Speed Profiles, SLA Tiers) with failing-before reproductions, a 125-test / 1482-assertion backend gate on `inbils_test`, and a canonical 11/11 browser spec (`tests/e2e/datatable-ab.spec.ts`) covering server search, keyboard sort with `aria-sort`, per-page round-trip with query preservation, export parity where an endpoint exists, and 1280px/320px light/dark. Independent QA PASS and final reviewer APPROVED; original card rubrics remain unrecovered.

### Fixed - Notification Center replacement acceptance (2026-10-03)

- Preserve the actor-scoped global unread count in the inbox response so the badge and mark-all action remain correct when unread notifications are outside the visible page.
- Wait for the unread notification's read request to succeed before opening its detail link; already-read links keep direct navigation.
- Include soft-deleted tickets when generating the next ticket code, preventing duplicate codes after owned browser fixtures are cleaned up.
- Verify the approved replacement contract with isolated notification/isolation checks (7 tests, 148 assertions) and the final owned-lifecycle canonical scenario (1/1, 17.3s). Coverage includes real assignment delivery, held-read navigation, denied/deleted links, keyboard use and 1280px/320px light/dark views; the off-page UI case uses response projection, not a claim of real browser pagination setup.
- Rebuild and recreate the app service only; Compose reports all services healthy, `/up` and `/ready` return 200, and readiness checks are OK. Backup status remains unknown. No application code changed after the build; only test formatting changed.
- The browser read-all action intentionally marks the shared `inbils_test` Demo Technician inbox read. Cleanup deletes only owned tickets; durable notifications and their read state persist.
- Final focused isolation, Notification Center and ticket validation gate passes: 15 tests, 175 assertions (13.249s), including soft-deleted ticket-code and tenant cases; scoped Pint passes for four PHP files. Independent Notification QA passes and final reviewer approves the explicitly authorized replacement contract, both without findings. All five Notification cards are complete; original rubrics remain unrecovered and no fresh full-suite pass is claimed. Execution stops at this phase boundary; DataTable implementation is not authorized.

### Tested - Dispatch/My Work replacement acceptance matrix (2026-10-03)

- Extend canonical Dispatch/My Work coverage from three to thirteen scenarios: stale assignment rejection, reload success/failure/cancellation, keyboard controls and 320px mobile actions.
- Keep actual assignment concurrency rejection separate from simulated My Work conflict-response UI coverage; existing backend tests verify tenant isolation and state-race behavior.
- Cancellation exercises the installed Inertia router's real cancellation path; no production component, runner or authorization change.

### Fixed - Location saved-marker focus and click ownership (2026-10-03)

- Stop remaining DOM listeners with `stopImmediatePropagation()` in the saved-marker capture handler before clearing the draft and opening its popup.
- Focus the map without scrolling on registered saved-marker mousedown, preventing Leaflet's focus from moving the nested scroller between pointer down and up. Native marker focus and keyboard interaction remain intact.
- Canonical Location 2/2 passes after the focus remedy; locked spec unchanged. Actual browser checks confirm correct saved popup, no draft fall-through, and empty-map draft creation.

### Fixed — Technician gate and baseline regressions (2026-10-03)

- Allow underscore and percent characters in manager organization paths while escaping SQL LIKE wildcards to keep descendant report access narrow.
- Pass relation builders to customer address/subscription sorting and restore the subscription transaction facade import.
- Register request-ID middleware globally and align operational ticket detail access with list/export assignment scope.
- Reduce Approval Inbox queries without changing maker-checker or tenant boundaries; check My Work capabilities before querying employee profiles.
- Generate deterministic company seed asset codes instead of collision-prone random codes.
- Update report/ticket contract fixtures and canonical Technician browser checks for collapsed navigation, native date keyboard segments, and exact denied-response correlation.

### Fixed — Technician report test fixture (2026-10-02)

- Align the completed-SPK averaging test with the active-technician target invariant by assigning work to a company-scoped technician with an active employee profile. Authorization logic is unchanged.

### Changed — Indonesia regions, address map, and customer history (2026-08-11)

- Added `laravolt/indonesia` reference data for province, city/regency, district, and village/subdistrict selection.
- Added searchable cascading region fields immediately after each address label in customer onboarding and address management.
- Added OpenStreetMap address search that fills the latest selected address, postal code, coordinates, and matched region hierarchy without duplicating region sources.
- Fixed village loading for Laravolt district codes that use either six or seven digits and kept child region values cleared whenever a parent changes.
- Added a reusable OpenStreetMap Leaflet picker centered on Indonesia with click selection, a draggable red pin, device geolocation, and required latitude/longitude storage.
- Added region foreign keys and coordinate validation to customer addresses while preserving existing address records.
- Added permission-aware Billing, SPK, and Ticket tabs to Customer Show, including billed, paid, and outstanding invoice amounts.

### Changed — Customer server-side DataTable and unified creation (2026-08-11)

- Migrated the Customer index to the shared server-side `DataTable` with consistent colored actions and green create button.
- Combined customer data, multiple addresses, multiple contacts, subscription, and installation SPK into one tabbed create form and one database transaction.
- Added exclusive primary address, installation address, and primary contact selection while allowing the primary and installation addresses to differ.
- Added a nullable company-scoped Customer-to-User foreign key plus safe backfill for unambiguous existing email matches.
- Automatically creates the initial service subscription and generated installation SPK from the selected package and serving POP.
- Reused the restricted `PhoneInput` for customer and contact phone fields.

### Changed — User billing, ticket, and SPK history (2026-08-11)

- Added permission-aware Billing, Ticket, and SPK history tabs to User detail pages.
- Resolved customer history through the Customer-to-User foreign key and included employee activity from creator and assignee references.
- Scoped every history query to the current company and capped each tab at 50 recent records.

### Changed — Permission server-side DataTable (2026-08-11)

- Migrated the Permission index to the shared server-side `DataTable` with search, sorting, pagination, and per-page controls.
- Added a reusable deterministic dynamic badge for stable generated colors across permission groups and user roles.

### Changed — Role DataTable and grouped permission selector (2026-08-11)

- Migrated the Role index to the shared server-side `DataTable` and colored action buttons.
- Added a compact two-panel permission selector with category-level select all behavior.
- Ordered permission categories to follow the Admin sidebar menu sequence.
- Applied green create and save actions to Role forms.

### Fixed — user Inertia response types (2026-08-11)

- Corrected the User create, show, and edit controller return types to `Inertia\Response`.

### Changed — user DataTable actions and role badges (2026-08-11)

- Migrated the Users index to the shared server-side `DataTable` pattern.
- Added reusable show, edit, and delete table actions.
- Applied shared colored table actions and the green add style to Organization Units.
- Added consistent generated badge colors for built-in and custom user roles.
- Added a reusable green success button style for create and add actions.
- Muted the FilePond upload panel in dark mode to avoid a bright white surface.
- Added a reusable `PhoneInput` component restricted to numbers and `() + -` characters.

### Changed — local full demo seed (2026-07-21)

- `DatabaseSeeder` now runs demo users, customer demo, and E2E fixtures on local/dev/testing.
- `CompanySeeder` seeds sample stock quantities for trackable products.


### Fixed — inventory unit ownership on category (2026-07-21)

- Category form now requires and displays `unit_id`.
- Product create/edit unit field is read-only and inherits from selected category.
- Category unit change cascades to existing products.
- Product factory inherits unit from category.
- Additive migration hardens `categories.unit_id` (backfill + NOT NULL). `products.unit_id` kept.
- Company/E2E seeders seed category unit ownership.

### Changed â€” t_25cf4d0b: deterministic PHP test topology (2026-07-15)

- Added a PHPUnit Modules suite plus focused topology regression so root and module tests are discovered by default.
- Re-included readiness evidence documents after the repository-wide `docs/*` ignore rule and recorded dirty-tree inventory plus shared-DB verification boundary.

### Fixed â€” t_f519540a: P0 QA critical fixes (2026-07-14)

- Added scoped E2E coverage for the missing Ziggy registration-route regression with Chromium Playwright harness, destructive guard plus isolated built-assets lifecycle, and browser journeys including stock receive and network asset related navigation.
- Disabled public registration and locked stock access behind seeded permissions.
- Hardened stock integrity with non-negative quantities, reservation bounds, and scoped idempotency.
- Locked payment reconciliation with per-company reference uniqueness.
- Enforced tenant boundaries for admin routes and component gallery access.
- Orchestrated SPK completion across stock, asset, subscription, and invoice updates.
- Fixed subscription code generation to use current company context and keep codes tenant-scoped.

### Changed â€” t_4e61368f: permissions paging and grouped role permissions (2026-07-09)

- Paginated admin permissions server-side while keeping permission group metadata.
- Added focused coverage for paginated permissions and grouped role edit permission payloads.

### Changed â€” t_aa41ccea: admin sidebar and profile topbar UX (2026-07-09)

- Grouped admin sidebar navigation into dashboard, admin, operations, service, inventory, work order, finance/report, and developer sections.
- Added admin topbar profile link and logout action using existing Breeze `profile.edit` and `logout` routes.

### Fixed â€” t_44af8fb7: admin menu permission alignment (2026-07-09)

- Aligned inventory stock, stock movement, Item Finder, and report child pages to seeded read permissions.
- Added Item Finder sidebar navigation and focused authorization coverage for admin menu destinations.

### Phase 1 â€” Core Complete (2026-06-30)

- P1-00 â€” Installed `nwidart/laravel-modules` and generated 8 module stubs.
- P1-01 â€” Installed permission and activity log packages, published migrations, seeded roles and permissions.
- P1-02 â€” Added company, settings, company scope, company service, and system setting defaults.
- P1-03 â€” Added setup wizard, bootstrap command, middleware, and first company creation flow.
- P1-04 â€” Added audit service and core activity logging.
- P1-05 â€” Added company profile/settings, user, role, and permission admin UI.
- P1-06 â€” Added admin route wiring, shared Inertia props, sidebar, middleware registration, and hooks.
- P1-07 â€” Added location topology CRUD and service layer.
- P1-08 â€” Added per-company default seeder, `CompanyCreated` event, and default location seeding.
- P1-09 â€” Added composite UI components, `useInertiaForm`, and formatting helpers.
- P1-10 â€” Added dashboard controller, real dashboard widgets, module placeholders, and final Phase 1 changelog.

### Added â€” P1-00: nwidart/laravel-modules + 8 module stubs (2026-06-30)

- `composer require nwidart/laravel-modules` (v12).
- `config/modules.php` published.
- 8 module stub generated: Customer, Service, NetworkAsset, Inventory,
  SPK, Billing, Ticketing, Reporting.
- `module.json` dependencies per D-C2:
  - Customer=[], Service=[], NetworkAsset=[], Inventory=[]
  - SPK=[Inventory, NetworkAsset]
  - Billing=[Inventory, SPK, Service]
  - Ticketing=[SPK, NetworkAsset]
  - Reporting=[]
- Scaffold: Config/Console/Entities/Database/Http/Models/Services/Actions/
  Policies/Resources/Providers/Tests/Routes (nwidart default).
- `Modules/*/Routes/web.php` stub (empty group prefix /admin).
- `php artisan module:list` â†’ 8 enabled.
- `php artisan route:list` â†’ 65 routes, no error.
- `composer.json` allow-plugins: wikimedia/composer-merge-plugin=true (fix
  blocked plugin issue).
- Shared Core (app/Models/Core/) tetap â€” tidak terkena.

### Changed â€” Architecture Review Fixes â€” Opsi A (2026-06-30)

Eksekusi semua perubahan PRIORITAS 1 (Critical) + PRIORITAS 2 (High)
dari `docs/ARCHITECTURE_REVIEW.md` sebelum Phase 1. 9 batch (B1-B9)
doc consistency fixes. Executor: Hermes langsung (doc authoring = architect
work, NOT OpenCode).

- `docs/WORKFLOW.md` â€” Rewrite total: 11 section ISP (Core, Customer,
  Service, NetworkAsset, Inventory, SPK 8-state, Billing recurring+OTC,
  Ticketing 5-state on_progress+SLA, Performance, Reporting, Notification).
  Section numbering match module docs.
- `docs/API.md` â€” Rewrite total: section per module (Customer Â§2, Service
  Â§3, NetworkAsset Â§4, Inventory Â§5, SPK Â§6, Billing Â§7, Ticketing Â§8,
  Performance Â§9, Reporting Â§10). Route ISP-correct. Hapus Item/Warehouse.
- `ROADMAP.md` â€” Rewrite Phase 2-8: 8 phases (Phase 1 Core+Location,
  Phase 2 Customer+Service, Phase 3 Inventory+NetworkAsset, Phase 4 SPK,
  Phase 5 Billing, Phase 6 Ticketing, Phase 7 Performance, Phase 8
  Reporting). Location topology di Phase 1. Dependency ISP.
- `docs/ARCHITECTURE.md` â€” Hapus RecurringInvoiceSchedule (E1). Document
  exception ont_asset_id FK (D-R5). Soft delete list complete ISP.
  Folder structure Config/Console/Entities/Providers. Itemâ†’Product.
  module.json dependencies (D-C2).
- `docs/COMPANY_PROFILE.md` â€” CompanyScope list ISP (28 model). itemsâ†’
  products. Phase 8 confirm.
- `docs/SECURITY.md` â€” Role matrix expand 8 module (~80+ permissions).
  LogsActivity list ISP. itemsâ†’products. AuditLogQuery cross-tenant
  filter (D-R11).
- `docs/DATABASE.md` â€” movement_type verb form (D-H1). Ticketing Phase 6.
  Migration order SPKâ†’Billingâ†’Ticketing. Location type CHECK app-level
  (D-R10).
- `TASKS.md` â€” P1-00 8 module stub (D-C2). P1-01 ~80+ permissions.
  P1-07 Location topology (new). P1-08 Seed master defaults (new).
  P1-09 composite (renumber). P1-10 dashboard (renumber). æå‰â†’lebih awal.
- `docs/VISION.md` â€” Section 8 open questions RESOLVED (D-H3). Phase 1-8.
- `docs/TECH_STACK.md` â€” PDF Phase 4+5. Phase 1-8.
- `docs/LIBRARY_DECISION.md` â€” ItemImport/Exportâ†’Product.
- `docs/modules/*.md` â€” movement_type verb form. serial_number removed.
  sla_tiersâ†’ticket_categories FK claim removed. Billing SLA credit
  claim removed (v1 no credit). ä¼˜åŒ–â†’optimasi. seperti Itemâ†’Product.
  Cross-ref WORKFLOW Â§ + API Â§ updated. Ticketing Phase 6.

### Added â€” Company Configuration System (2026-06-30)

Sistem konfigurasi company dinamis + Setup Wizard + multi-tenant ready.
Di-request baginda, diintegrasikan ke seluruh blueprint.

- `docs/COMPANY_PROFILE.md` (NEW, source of truth) â€” Company Configuration
  Design (companies table + settings json), Setup Wizard Flow (4 step +
  bootstrap command + middleware), Tenant Strategy (v1 single, v2 multi
  hooks ready).
- `docs/ARCHITECTURE.md` â€” Section 11 (Company & Multi-Tenant): folder
  structure + CompanyScope trait + wizard request flow + tenant strategy.
  Frontend Pages: `Company/` + `Setup/Wizard.tsx`.
- `docs/DATABASE.md` â€” `companies` table + `users.company_id` + company_id
  di semua tabel master/transaksi (customers/units/categories/warehouses/
  items/stocks/stock_movements/work_orders/work_order_items/work_order_
  statuses/invoices/invoice_items/payments/ticket_categories/tickets/
  ticket_comments/ticket_attachments) + unique index per-company
  `(company_id, code)` + trait `BelongsToCompany`. ERD update (companies
  root). Dua-layer config: `settings` table (system-global, seeded saat
  install) + `companies.settings` json (company-specific, wizard isi).
  Initialization rule: TIDAK seed company data â€” seeder hanya system roles
  + permission template + system setting defaults. Company dibuat wizard.
- `docs/SECURITY.md` â€” Section 2 Company Scope (RBAC + data isolation) +
  Section 14 Multi-Tenant Isolation (CompanyScope, wizard authorization,
  cross-tenant leak prevention, v2 activation security). Checklist +
  company items.
- `docs/WORKFLOW.md` â€” Section 1 rewrite: Setup Wizard flow (bootstrap
  command â†’ login â†’ wizard â†’ company created) + Company profile/settings
  edit + User/Role rules update. Cross-modul matrix: Company settings
  baca semua modul.
- `docs/VISION.md` â€” In-scope: Company configuration dinamis. Out-of-scope:
  multi-company formal = v2 (v1 single, schema ready). Asumsi:
  single-company v1 multi-company ready. Open question (d) refine.
- `TASKS.md` â€” Phase 1 rewrite: 8 task (P1-01..P1-08). P1-01 = Install
  permission+activitylog. P1-02 = Company + CompanyScope + CompanyService
  + Setting (system) + SettingService + SystemSettingSeeder (NEW). P1-03 = Setup Wizard (bootstrap cmd + middleware
  + service + Wizard.tsx) (NEW). P1-04 = AuditService full. P1-05 =
  Company profile/settings UI + User/Role/Permission UI. P1-06 = Route
  admin.php + Inertia shared + sidebar + middleware wiring. P1-07 =
  Composite UI. P1-08 = Dashboard + finalisasi seed + CHANGELOG. Setting
  task lama dihapus (merged ke Company).
- `ROADMAP.md` â€” Phase 1 deliverable: Company system + Setup Wizard +
  bootstrap command. Exit criteria: wizard one-shot + cross-tenant
  isolation tested.
- `AGENTS.md` â€” Company Rules section (MANDATORY): trait BelongsToCompany
  wajib, company_id + unique per-company di migration, CompanyService
  baca identity (no hardcode), wizard flow wajib, Breeze register
  disabled, company config = companies.settings json. Folder structure:
  Models/Core + Traits, Pages Company/ + Setup/. Login section: bootstrap
  + wizard (no seeded admin).
- QA-ISP profile Hermes dibuat (clone isp, model glm-5.2, SOUL.md =
  no-code reviewer).

### Added â€” Modular Architecture + Inventory Redesign (2026-06-30)

Dua direktif besar baginda terintegrasi penuh ke blueprint.

**Modular Architecture (nwidart/laravel-modules):**
- Domain logic (Inventory/SPK/Billing/Ticketing) â†’ `Modules/{Name}/`,
  BUKAN `app/`. Shared Core (Company/User/Auth/Audit/Setting/Customer)
  tetap `app/`.
- Module structure: Config/Database/Entities/Http/Models/Services/Actions/
  Policies/Resources/Tests/Routes. `module.json` dependencies key.
- Module TIDAK coupling langsung â€” komunikasi via Service/Action/Event.
- Inventory â†’ SPK/Billing via polymorphic reference (no FK hard-coupling).
- Dokumentasi: `docs/ARCHITECTURE.md` Section 12 + `AGENTS.md` Modular
  Architecture Rules + folder structure update.

**Inventory Redesign (dynamic category/attribute/serial/stock movement):**
- Domain modeling rule: entity dynamic â†’ configurable master data, NO
  hardcode kategori bisnis.
- Dynamic category + attribute: `categories` â†’ `attribute_definitions`
  â†’ `product_attributes`. NO kolom per-kategori (NO router_port/router_ram).
- Product + ProductVariant + ItemSerial. `tracking_type`: serial/quantity/
  both.
- Stock movement immutable, 5 types: receive/issue/transfer/adjustment/
  return. JANGAN update stock langsung, semua via StockMovement.
- Reserve/release = `stocks.reserved_quantity` (SPK hold, NO movement).
- `items` table â†’ `products` (rename, akurat semantik).
- Dokumentasi: `docs/modules/inventory.md` rewrite (17.5KB) +
  `docs/DATABASE.md` Section 4 redesigned.

**Open question baru:** (f) nwidart install timing â€” Phase 1 awal (P1-00)
atau P0.5. (g) itemsâ†’products rename confirmed. (h) attribute type enum
6 type.

### Added â€” Blueprint & Documentation (2026-06-30)

Blueprint sistem lengkap dibuat oleh Senior Software Architect (Hermes),
menunggu approval baginda sebelum eksekusi Phase 1.

- `docs/VISION.md` â€” tujuan produk, user role, lingkup, success criteria,
  asumsi, open questions.
- `docs/ARCHITECTURE.md` â€” layered backend, Inertia-as-Island decision
  (konflik React Island vs Inertia flagged untuk approval), struktur
  folder backend + frontend, request flow, state management, naming
  convention, dependency rules, transaksi & integritas.
- `docs/DATABASE.md` â€” konvensi schema, ERD tekstual, tabel Core +
  Inventory + SPK + Billing + Ticketing, index strategy, seed strategy.
- `docs/API.md` â€” routing layout (routes/admin.php), Inertia response
  shape, pagination, file upload, export/PDF route, konvensi API publik
  v2.
- `docs/SECURITY.md` â€” auth, RBAC role matrix, validation, CSRF, SQL
  injection, XSS, file upload security, audit log, secrets, session,
  logging, PDP law, security checklist per task.
- `docs/WORKFLOW.md` â€” state machine per modul + cross-modul trigger
  (SPKâ†’Billing, Ticketâ†’SPK, Stock reserve/consume/release).
- `docs/TECH_STACK.md` â€” stack inti (existing), stack tambahan (TODO
  install), yang tidak dipakai + alasan, dev tooling, environment.
- `docs/LIBRARY_DECISION.md` â€” 10 kategori (Auth, Permission, Audit,
  API, Queue, Storage, Import/Export, PDF, Testing, UI) dengan analisa
  fungsi/masalah/alternatif/kelebihan/risiko/alasan pilih.
- `docs/modules/inventory.md` â€” spec modul Inventory (tujuan, role,
  entity, relation, workflow, permission, API, testing, acceptance).
- `docs/modules/spk.md` â€” spec modul SPK.
- `docs/modules/billing.md` â€” spec modul Billing.
- `docs/modules/ticketing.md` â€” spec modul Ticketing.
- `ROADMAP.md` â€” 7 Phase (Core, Master Data, Inventory, SPK, Billing,
  Ticketing, Report) + estimasi.
- `TASKS.md` â€” Phase 1 task list (7 task: P1-01 sampai P1-07) untuk
  OpenCode.
- `AGENTS.md` â€” extend dari UI-only ke full project guide (role split,
  architecture rules, UI convention retained, dev commands, OpenCode
  rules, QA rules).

### Keputusan Teknis Pending Approval

1. **React Island vs Inertia** (`docs/ARCHITECTURE.md` Section 2):
   rekomendasi A (pertahankan Inertia existing), alternatif B (rewrite
   pure Blade+React island, +2 minggu). Default dokumen = A.
2. **Open questions VISION.md Section 8** (6 pertanyaan bisnis: SPKâ†”Billing
   otomatis, stok keluar saat apa, customer portal, multi-cabang, PPN,
   SLA). Multi-cabang (d) sudah di-jawab design: v1 single company,
   multi-company ready (lihat COMPANY_PROFILE.md).

### Pending

- Task P1-01 sampai P1-08 belum dieksekusi (gate approval).

## [0.0.1] â€” 2026-06-30 (initial commit ec466eb)

### Added â€” Foundation (Phase 0, sudah selesai sebelum blueprint)

- Laravel 12 + Breeze (Inertia/React/TypeScript) scaffold.
- 28 UI primitives di `resources/js/Components/ui/` (Button, Input, Card,
  Table, Modal, Toast, Tabs, Switch, Select, Badge, Avatar, Dropdown,
  Tooltip, Pagination, Skeleton, Spinner, StatCard, Alert, EmptyState,
  Breadcrumb, Sidebar, Topbar, Checkbox, RadioGroup, Textarea, Label,
  IconButton, Divider) + barrel `index.ts` + `cn()` helper.
- AdminLayout (sidebar + topbar shell).
- Admin/Components gallery page (9 section demo).
- Tailwind config: token brand/surface/success/warning/danger,
  darkMode='class', Figtree font.
- Database MySQL (Laragon) + seeder admin@inbils.test.
- AGENTS.md (UI convention, mandatory).

## [0.0.1-fix] â€” 2026-06-30 (commit 41c5bb1)

### Fixed

- `tailwind.config.js`: tambah `darkMode: 'class'` (sebelumnya default
  'media' â†’ dark toggle ignore html.dark class).
- `Admin/Components.tsx`: rebuild jadi full gallery 9 section + sticky
  left nav.
- `AdminLayout.tsx`: tambah menu "Komponen".
- `app.tsx`: remove redundant page-level ToastProvider (conflict root
  provider).
- `Dashboard.tsx`: template dashboard (stats + table + recent activity).
