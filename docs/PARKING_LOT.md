# Parking lot

Ideas and feature gaps found while another task was active (AGENTS.md: no "while here" work). Each entry needs an owner decision before it becomes a lane.

| Date | Idea | Found in | Notes |
|---|---|---|---|
| 2026-10-14 | Stok write screens: stock issue, stock transfer, admin break-glass direct adjustment | `docs/user-guide/web.md` §6.5 while writing the guide | Routes and services exist (`POST /admin/stocks/issue`, `.../transfer`, `.../adjust/direct`; `docs/SECURITY.md` §2.4). The web has no screen for them, only receive + adjustment request/approve. New write flows with permissions and audit trail, not a defect. |
| 2026-10-14 | Document upload from the web | `docs/user-guide/web.md` §8.8 | The document upload route has no web caller. |
| 2026-10-14 | Business chart: second Y axis for MRR vs revenue paid; stable donut colours per status | `date-range-charts` lane review | Revenue looks flat next to MRR on one axis; donut colours follow data order. `ui-consistency` R5 maps give stable tones per status, which can feed the donut. |
| 2026-10-14 | 47 more user-guide screenshots (dialogs, detail/create pages, report pages) | GuideWeb report | List in history of agent GuideWeb; picked up in `ui-consistency` ticket 10 where the screens exist. |
| 2026-10-14 | IP allowlist for the `/platform` panel (`PLATFORM_ALLOWED_IPS`, CIDRs via `mlocati/ip-lib`) | `platform-superadmin` grilling SA4 | Owner removed it from v1 ("tidak usah ada dulu untuk allowed ip"). |
| 2026-10-14 | Per-company roles (spatie teams) | `platform-superadmin` R2 | v1 makes role definitions platform-owned instead. |
| 2026-10-14 | SaaS billing and plans (the platform invoicing ISPs) | `platform-superadmin` SA8 | Out of v1. |
| 2026-10-14 | Customer usage per month in the app (monthly octet snapshots of open RADIUS sessions) | `mobile-redesign` V3 | Start-time accounting under-counts long PPPoE sessions; needs production FreeRADIUS. |
| 2026-10-14 | Inventory request Approve dialog has no warehouse picker (a request saved without a warehouse cannot be approved from the web) | `ui-consistency` 07 | Found by ModInventaris; needs a warehouse SearchSelect in the approve dialog + server rule. |
| 2026-10-14 | Dead `edit` actions render missing pages: `Admin/Invoices/Edit`, `Admin/SPK/Edit`, `Admin/Tickets/Edit` | `ui-consistency` 05/08 | Nothing links to them; remove the routes or build the pages. |
| 2026-10-14 | Invoice create: subscription options are not filtered by the chosen customer | `ui-consistency` 08 | Server still validates ownership; UX only. |
| 2026-10-14 | A soft-deleted employee profile keeps `employee_profiles.user_id` (unique), so that user shows in the picker but a new profile is refused | `ui-consistency` 09 | Exclude trashed holders from the picker or free the user on delete. |
| 2026-10-14 | `OdnOccupancyQuery` POP tree walk runs on every Subscriptions/Show load for `odn.assign` holders even when drop-port options are not requested | security re-pass | Performance only; compute lazily inside the optional prop. |
| 2026-10-14 | Material-return rejection mail links a technician to an admin page (technicians are app-only) | security re-pass | Point technicians to the app or drop the link. |
| 2026-10-14 | A maintenance SPK can carry `subscription_id` with `customer_id` null; creating its invoice then 500s (invoices.customer_id NOT NULL) | security re-pass | Derive the customer from the subscription or block invoicing. |
| 2026-10-14 | `GET /admin/spk/{id}/edit` renders a missing `Admin/SPK/Edit` page (500) and sends unbounded lists | WaveSecFix | Remove the route/method or build the page. |
| 2026-10-16 | Per-company push notification event toggles / in-app opt-out | `push-notifications` N6 | v1 relies on OS notification settings. |
| 2026-10-16 | Platform: shared-origin session means a tenant-page XSS during impersonation could call platform routes without step-up (design §7 residual) | PlatformSecReview | Add CSP and/or `platform.password.confirm` on provisioning/reactivate, or set `PLATFORM_DOMAIN`. |
