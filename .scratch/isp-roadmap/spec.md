# ISP roadmap — capabilities absent from BOTH inbils and isp_ari

Status: roadmap (no tickets; each item becomes its own lane + spec only when the owner picks it)
Source: `/tmp/isp_ari_gap.md` section 4, re-verified 2026-10-11 by grep of both repos (`app`, `Modules`, `database`, `routes`, `resources`). Items already covered by their own lane (Midtrans, 2FA/passkeys, customer status rollup, installation SPK) are not repeated here.

Order = suggested value for an operating ISP, not a commitment. Every item below touches `docs/ENTERPRISE_ISP_READINESS.md:7,277-279` exclusions or the P2/YAGNI list, so each needs an owner decision, credentials or hardware before a spec is written.

| # | Item | One-line value | State in inbils (evidence) | State in isp_ari (evidence) |
| - | --- | --- | --- | --- |
| 1 | MikroTik / RouterOS provisioning (PPPoE secrets, queues, isolate/un-isolate) | Removes manual NOC work: activate, change plan and suspend overdue subscribers automatically. | Only vendor strings and a docs hook: `Modules/Service/database/migrations/2026_06_30_200002_create_speed_profiles_table.php:19` (`radius_profile_name`), `docs/business/service.md:124-126`, `docs/business/network.md:331`; no RouterOS client. | ADR-007 "connection placeholder": no migration/model/endpoint (`isp_ari/docs/01-decisions.md:87-91`). |
| 2 | RADIUS / NAS integration (radcheck/radreply sync, CoA/disconnect) | Central authentication and bandwidth enforcement per package; basis for auto-suspend. | Same placeholder column as above; `docs/AUDIT_REPORT.md:170,341` lists it as a known gap. | Absent (grep empty). |
| 3 | WhatsApp notification gateway (Fonnte/Wablas/Twilio) | Bill reminders (H-3/H-0), payment receipts, technician dispatch and outage broadcasts on the channel Indonesian customers actually read. | In-app/e-mail notifications only (`Modules/*/app/Notifications/*`); log-channel placeholder `app/Listeners/ReportFailedQueueJob.php:40`. | Only a contact number field: `isp_ari/database/migrations/2026_07_27_000001_create_isp_settings_table.php:19`. |
| 4 | Customer portal extensions beyond invoices+pay (usage/speed, service request, profile, ticket thread, downloads) | Cuts call-centre load; builds on the minimal portal from `midtrans-payment`. | Customer accounts + `customer` role with ticket permissions exist (`CustomerService.php:20-33`, `RolePermissionSeeder.php:294-298`); no portal routes; Flutter customer app only planned (`docs/MOBILE_TASK_PLAN.md`). | Not present (internal back office only). |
| 5 | IPAM (subnets, CIDR pools, static-IP inventory, CGNAT pools) | Prevents address exhaustion/overlap; lets provisioning allocate IPs safely. | Free-text `ip_address`/`management_ip` on assets (`Modules/NetworkAsset/database/migrations/2026_07_01_110000_create_network_assets_table.php:20-21`); no IPAM module (grep `ipam` empty). | Absent; the scout's `static_ip`/`pppoe_username` placeholder columns **do not exist** in isp_ari (ADR-007). |
| 6 | ODN port hierarchy (OLT PON port → splitter → ODP drop ports, saturation check) | Stops installations on full ODPs; faster fault isolation. | OLT/ODP/ODC exist only as `network_assets.asset_type` enum values (`database/migrations/2026_08_11_163004_enforce_latest_closed_values_as_enums.php:20`); no port model; splitters appear only as an inventory product in demo seed data (`database/seeders/Demo/InventoryDemo.php:33`). | Absent. |
| 7 | Procurement: purchase requests, purchase orders, vendors, goods receipt | Auditable buying instead of direct `stocks/receive`; vendor price history. | Documented (`docs/business/procurement.md:3`), not coded; `purchase_order` appears only as a stock-movement `ref_type` string in a test (`Modules/Inventory/tests/Feature/StockServiceTest.php:84`). | Documented only (`isp_ari/docs/modules/inventory.md`); not coded. |
| 8 | Payroll / salary and technician SPK incentive | Pay piece-rate bonuses per completed SPK; HR close-out in one place. | `EmployeeProfile`, evaluations, technician-performance report exist; no salary/payroll code (grep `payroll` empty). | Absent. |
| 9 | GIS network route and fibre splice mapping | NOC sees cable routes, splice enclosures and OTDR distances on a map for fast fibre-cut repair. | Leaflet lat-lng picker only (`resources/js/Components/composite/LatLngMapPicker.tsx`, `resources/js/Components/ui/MapPicker.tsx`); no route/splice entities. | Raw lat/long fields only. |

## Notes

- Dependencies between items: 1 and 2 both need an owner choice of NAS stack (RouterOS API vs FreeRADIUS) and hardware access; 5 and 6 are prerequisites for sensible automation in 1-2; 3 is independent and cheap once a vendor is chosen; 4 builds on `midtrans-payment` ticket 03; 7 and 8 are back-office and independent.
- Auto-suspend on overdue and auto-reactivate on payment are documented (`docs/business/billing.md:186-187`) but implemented in neither app; they belong with item 1/2 or with a small standalone lane once the owner decides whether suspension is a status flip or a network action.
- How to promote an item: the owner names it, the agent writes `.scratch/<slug>/spec.md` (Owner decisions first) and tickets via the to-spec template, status `needs-owner-approval`.

## Corrections to the scout report affecting this list

- isp_ari has no `pppoe_username`, `static_ip`, `ont_mac_address`, `vlan_id` columns (scout row "Connection Attributes" cites a migration that, per ADR-007, deliberately has none); grep of `isp_ari/database/migrations` finds none of those names.
- "Purchase Orders & Vendor Management: Documented in `docs/business/procurement.md`" was listed under the isp_ari column; that file is in **inbils** (`docs/business/procurement.md`), isp_ari has no such file.
