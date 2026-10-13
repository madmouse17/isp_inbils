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
