# Parking lot

Ideas and feature gaps found while another task was active (AGENTS.md: no "while here" work). Each entry needs an owner decision before it becomes a lane.

| Date | Idea | Found in | Notes |
|---|---|---|---|
| 2026-10-14 | Stok write screens: stock issue, stock transfer, admin break-glass direct adjustment | `docs/user-guide/web.md` §6.5 while writing the guide | Routes and services exist (`POST /admin/stocks/issue`, `.../transfer`, `.../adjust/direct`; `docs/SECURITY.md` §2.4). The web has no screen for them, only receive + adjustment request/approve. New write flows with permissions and audit trail, not a defect. |
| 2026-10-14 | Document upload from the web | `docs/user-guide/web.md` §8.8 | The document upload route has no web caller. |
| 2026-10-14 | Business chart: second Y axis for MRR vs revenue paid; stable donut colours per status | `date-range-charts` lane review | Revenue looks flat next to MRR on one axis; donut colours follow data order. `ui-consistency` R5 maps give stable tones per status, which can feed the donut. |
| 2026-10-14 | 47 more user-guide screenshots (dialogs, detail/create pages, report pages) | GuideWeb report | List in history of agent GuideWeb; picked up in `ui-consistency` ticket 10 where the screens exist. |
