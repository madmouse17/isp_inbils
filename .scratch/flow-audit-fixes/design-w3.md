# Design: flow-audit-fixes wave 3 (the rest: 210 IDs, named remainders, one security item)

Status: design for review · Lane `flow-audit-fixes` · Ticket 03-design-w3 · Author: architect `FixDesign-W3` · 2026-10-20 · Same text as `local://design-faf-w3.md`
Sources: `docs/plans/FLOW_AUDIT_2026-10-17.md` §5 "Gelombang 3" (lines 498-510) and "Kelompok perbaikan berakar sama" (lines 512-536); decisions `.scratch/flow-audit-fixes/spec.md` (K1-K37, fixed); remainders of `.scratch/flow-audit-fixes/design-w1.md` §7 "Deferred" and `design-w2.md` §1.2, §8.4; security finding of the wave-2 review (payload minimisation). Web `main` at c31d56a (wave 2 batch B); mobile `main` at 4485b7d with wave-2 batch C in flight in the working tree.
Preconditions (Main): lane `tenant-roles` (`.scratch/tenant-roles/design.md`) has landed (company-scoped roles, `App\Support\PermissionCatalogue`, every `hasRole(...)` replaced); wave-2 batches C and D are merged. Wave 3 adds no role name and checks permissions only.

## 0. Summary

- **210 wave-3 IDs, against today's code:** 48 settled (by waves 1-2, earlier lanes, already true, or parked by the owner), 10 moved to wave-2 batch C (in flight, judged at its gate), 4 moved to lane `tenant-roles`, 148 open or partly settled. Each of the 148 has exactly one owner slice (contributors named) (§1.2; per-slice list in §8.4).
- **Named remainders** of waves 1-2 (K10-K12, K14, K16, K22, K26, K29, K30, K32-K35, F4-D12, F6-D02, F8-D03, F12-D13, F12-U05) are wave-3 IDs and sit in §1.2; K19, K20 and K24 have nothing left (§1.3).
- **Security item SEC-1:** `WorkOrderResource`, `InvoiceResource`, `NetworkAssetResource` and `SubscriptionResource` stop embedding whole customer, subscription and user records, the way `TicketResource` does since wave 2 (§4.1). Owner slice W3-SEC.
- **Eight same-root groups are designed once** (§1.4).
- **22 slices in 8 batches** (A-E web, M1-M2 mobile, Z docs), at most 4 per batch, one owner per file per batch (§8). The `inbils-flutter-ui` baseline audit findings are folded into the mobile slices that own those files (M3-CORE, M3-CUST, M3-TECH-UI).
- **7 migrations** (4 DDL, 3 DML), additive, reversible, all DDL `INSTANT` or `INPLACE, LOCK=NONE` on MySQL 8.4 (§3). One template grant through `PermissionCatalogue::release()` (`ticket.reopen`, OD1). No new catalogue permission. One new package: `share_plus` in the technician app (the customer app already has it; OD10).
- Skills: backend slices follow `laravel-best-practices` and `mysql` (repo conventions win: Laravel `enum`, `timestamp()`, ULID `public_id` as secondary key); Flutter slices follow `inbils-flutter-ui` first, then `material-3`, `state-management-riverpod`, `flutter-performance` and the flutter-* skills (§8.1).

## 1. Problem statement

Wave 3 is "the rest" of the 2026-10-17 flow audit: 43 friction defects, 70 polish defects, 77 UX recommendations and 3 notes, plus what waves 1 and 2 named and left. Many were closed on the way by the same-root groups of waves 1 and 2, so every ID was checked again against current code (two read-only scouts plus direct reads; nothing was run). The owner accepted every audit recommendation and K1-K37 (spec); where a recommendation left a choice, this design picks one and lists it in §7 (standing "ikuti saran").

### 1.1 Status values and conventions

- `settled`: nothing left; evidence shows where it is done (or the owner parked the rest).
- `partly settled`: the remainder is named and owned.
- `open`: nothing done yet.
- `moved W2-C`: designed in `design-w2.md` §4.9-§4.10 (M2-PUSH, M2-TECH), being built now; wave 3 adds nothing; Main confirms at the wave-2 batch C gate.
- `moved TR`: owned by lane `tenant-roles` (K15 and the "Peran dan menu" group).
- Owner = the slice that delivers the user-visible result; contributors in brackets build a part the owner consumes. Paths: web repo relative; `MOB/` = `/home/madmouse/Documents/web/inbils-mobile`, `TECH/` = `MOB/technician/lib`, `CUST/` = `MOB/customer/lib`, `CORE/` = `MOB/packages/inbils_core/lib/src`.

### 1.2 Status of every wave-3 ID

Group letters: 3a friction, 3b UX high M, 3c UX medium S, 3d UX medium M, 3e polish, 3f UX low, 3g notes.

| # | ID | Grp | Status | Evidence (current code) | Remaining → owner (contributors) |
|---|---|---|---|---|---|
| 1 | F0-D01 | 3a | settled | `scripts/e2e-mutex.mjs:201-204` Linux start token (boot id + `/proc/<pid>/stat` field 22) | none |
| 2 | F1-D01 | 3a | settled | `app/Support/Observability/ReadinessChecker.php:40,167-171`; `tests/Feature/Operations/ReadinessStorageLinkTest.php` | none |
| 3 | F1-D05 | 3a | settled | `app/Queries/Platform/TenantDetailQuery.php:191-261` invitation queued/sent/expired/failed | none |
| 4 | F3-D02 | 3a | open | `resources/js/lib/navigation.ts:418` "Dokumen"; `Pages/Admin/Documents/Index.tsx` manages types only; upload backend exists (`app/Http/Controllers/Admin/DocumentController.php:120-150`, `app/Services/Core/DocumentService.php:26-49`, gate `system.setting`, no mime rule) | K26 Dokumen tab, rename, expiry reminder → W3-TEAMDOCS |
| 5 | F4-D01 | 3a | moved TR | `.scratch/tenant-roles/design.md` §7.1 OD2 (NOC template) | none |
| 6 | F4-D02 | 3a | partly settled | raw 422 gone (`Modules/SPK/app/Http/Controllers/WorkOrderController.php:211-213`); no schedule in `Modules/SPK/app/Http/Requests/AssignWorkOrderRequest.php:37-43`, `app/Http/Controllers/Admin/DispatchQueueController.php:100-147`, `Pages/Admin/Dispatch/components/AssignDialog.tsx`; `admin.spk.edit` renders `Admin/SPK/Edit`, a page that does not exist (`WorkOrderController.php:217-224`) | K16 schedule in Assign and registration; SPK Edit page → W3-SPK |
| 7 | F4-D11 | 3a | moved W2-C | `TECH/features/materials/inventory_request.dart:6-15` (`quantityIssued`), design-w2 §4.10 | none |
| 8 | F4-D13 | 3a | settled | confirm `TECH/features/work_orders/work_order_actions.dart:98-106`; notice `Modules/SPK/app/Notifications/WorkOrderSubmittedForReviewNotification.php` | none |
| 9 | F4-D15 | 3a | open | `resources/js/Components/composite/CustomerRelatedTables.tsx:210-226` subscription code is plain text | link + "Kelola langganan" → W3-SPK |
| 10 | F5-D03 | 3a | settled | `Pages/Admin/Billing/Invoices/Show.tsx:392-398` confirm names customer, amount, due date | none |
| 11 | F5-D06 | 3a | moved W2-C | `CUST/app/live_refresh.dart` (M2-PUSH) | none |
| 12 | F5-D07 | 3a | moved W2-C | `CORE/push/background_push.dart` (M2-PUSH) | none |
| 13 | F5-D09 | 3a | open | `Modules/Billing/app/Http/Controllers/Api/V1/Customer/InvoiceReceiptController.php:34-41` renders `pdf.invoice` ("TAGIHAN", bank block) | kuitansi PDF → W3-PAY |
| 14 | F5-D12 | 3a | settled | `app/Notifications/SubscriptionStatusNotification.php:34`; `SubscriptionsIsolatedSummaryNotification.php` | none |
| 15 | F5-D15 | 3a | open | `Modules/Billing/app/Http/Controllers/MidtransWebhookController.php:36-110` logs only foreign/unverified; `Modules/Billing/app/Services/PaymentAttemptService.php:372-381` keeps the Status API answer as `last_notification` | one log line and one audit row per callback → W3-PAY |
| 16 | F5-D19 | 3a | settled | `compose.sail.yaml:8-20,77,81` opt-in `workers` profile | none |
| 17 | F6-D02 | 3a | partly settled | `Modules/Ticketing/app/Services/TicketService.php:354-372` `reopen()` used only by the customer reply (`:337-338`); `ticket.reopen` in `database/seeders/RolePermissionSeeder.php:176` unused; no route | staff reopen with reason → W3-TICKET |
| 18 | F6-D03 | 3a | open | `customer_rating` only staff-typed (`app/Http/Requests/Admin/StoreEvaluationRequest.php:54`); `evaluation.customer.submit` (`RolePermissionSeeder.php:191,332`) unused | K14 rating → M3-CUST (W3-TICKET) |
| 19 | F6-D04 | 3a | settled | `Modules/Ticketing/app/Notifications/TicketCustomerUpdateNotification.php:19,90-93` | none |
| 20 | F6-D07 | 3a | open | `app/Support/FieldCheckIn.php:34-62` no threshold; `Pages/Admin/Tickets/components/checkIn.ts:27-29` metres | K10 → W3-FIELD (M3-TECH-OPS) |
| 21 | F6-D08 | 3a | open | `Modules/Ticketing/app/Http/Resources/Api/V1/TechnicianTicketResource.php:26-31` location = POP; check-in site = installation address (`Modules/Ticketing/app/Http/Controllers/Api/V1/Technician/TicketController.php:36-39`) | `site` + header → M3-TECH-UI (W3-TICKET) |
| 22 | F6-D09 | 3a | partly settled | category priority used (`Pages/Admin/Tickets/Create.tsx:50-64`); lone subscription not picked, SLA target not shown | → W3-TICKET |
| 23 | F6-D10 | 3a | settled | `Pages/Admin/Tickets/Show.tsx:522-541` follow `TicketResource.php:69-76` `can` | none |
| 24 | F6-D12 | 3a | moved W2-C | M2-PUSH | none |
| 25 | F6-D13 | 3a | moved W2-C | M2-PUSH | none |
| 26 | F7-D01 | 3a | settled | `Modules/NetworkAsset/app/Http/Controllers/IsolirPageController.php:31,34`; `Pages/Isolir/Show.tsx:55-56` | none |
| 27 | F7-D02 | 3a | settled | `Modules/NetworkAsset/app/Http/Controllers/NetworkAccountController.php:118-121` | none |
| 28 | F8-D05 | 3a | settled | `Modules/Inventory/app/Notifications/InventoryRequestApprovedNotification.php:28`, `InventoryRequestSubmittedNotification.php:27`; `app/Console/Commands/SendInventoryDigestCommand.php` | none |
| 29 | F8-D06 | 3a | open | `Modules/Inventory/app/Http/Resources/InventoryRequestResource.php:77` ULID source; shown `Pages/Admin/Inventory/Requests/Index.tsx:368-371,1088-1094`, `Show.tsx:1002-1010` | `source_code` → W3-INVREQ |
| 30 | F9-D01 | 3a | settled | `app/Support/ExportQuery.php:26,373-374`; `Modules/Reporting/app/Http/Requests/ReportFilterRequest.php:29,69-70` | none |
| 31 | F9-D02 | 3a | partly settled | references labelled (`Modules/Reporting/app/Exports/ReportExporter.php:159`); raw `:152` movement type, `:268` unit, status tokens, `technician_id=ULID`, raw minutes | labels, names, durations → W3-REPORTS |
| 32 | F9-D03 | 3a | open | `resources/views/exports/table.blade.php` raw table; `ExportQuery.php:387-393` | K32 template → W3-REPORTS |
| 33 | F10-D01 | 3a | open | only `app/Http/Controllers/Platform/CompanySubsidiaryController.php`, `app/Actions/Platform/AddTenantSubsidiaryAction.php:31-55` | K34 request → W3-PLATFORM |
| 34 | F10-D02 | 3a | settled | `Modules/Reporting/app/Http/Requests/ConsolidatedReportRequest.php:35-44` redirect with message | none (button = F10-U02) |
| 35 | F11-D01 | 3a | open | `app/Http/Middleware/Platform/RequirePlatformPasswordConfirmation.php:30` stores a POST URL as intended; `app/Http/Controllers/Platform/Auth/ConfirmablePasswordController.php:73,81` `redirect()->intended` | → W3-PLATFORM |
| 36 | F12-D01 | 3a | open | `TECH/features/work/online_only.dart:8-21`; queue only `TECH/features/evidence/evidence_uploader.dart`, `TECH/data/sync/queue_syncer.dart`; server already enforces `Idempotency-Key` (`app/Http/Middleware/Api/EnforceIdempotency.php:16-31`) | action outbox → M3-TECH-OPS |
| 37 | F12-D11 | 3a | partly settled | one builder `app/Http/Resources/NotificationResource.php:89-206` feeds the push (`app/Notifications/Channels/FcmChannel.php:80-96`); invoice texts generic (`:138-143`); ISP prefix `CORE/push/push_service.dart` `_show` | → W3-NOTIFY (M3-CORE) |
| 38 | F12-D13 | 3a | partly settled | confirm settled (design-w2 §1.2); doubled "Butuh koneksi internet" (`online_only.dart:44`, `work_order_actions.dart:140`, `ticket_actions.dart:27`); no banner | → M3-TECH-OPS |
| 39 | F7-D20 | 3a | open | `Modules/NetworkAsset/app/Services/Radius/RadiusDirectory.php:317-318,328` compares RADIUS datetimes with the app clock | → W3-NETWORK |
| 40 | F7-D21 | 3a | settled | `Modules/NetworkAsset/app/Services/Radius/RadiusException.php:29,34`; `app/Http/Controllers/Admin/CompanyIntegrationController.php:163-175` | none |
| 41 | F7-D22 | 3a | settled | `Modules/NetworkAsset/app/Http/Resources/NetworkAccountResource.php:27-28`; `Pages/Admin/NetworkAccounts/Show.tsx:462-476` | none |
| 42 | F7-D24 | 3a | settled | `Pages/Admin/NetworkAccounts/Show.tsx:696-703` (e5095de) | none |
| 43 | F7-D26 | 3a | open | `Modules/Billing/app/Http/Controllers/InvoiceController.php:329-341` renders `Admin/Billing/Invoices/Edit`, which does not exist | Edit page → W3-INVOICE |
| 44 | F1-U01 | 3b | settled | `app/Queries/Admin/OnboardingChecklistQuery.php:21-29`; `Pages/Admin/Dashboard/components/OnboardingChecklistCard.tsx` | none |
| 45 | F1-U06 | 3b | settled | `app/Http/Middleware/EnforceCompanyTwoFactor.php:13-31`; `resources/js/Components/composite/TwoFactorPrompt.tsx:10-46` | none |
| 46 | F2-U01 | 3b | open | `Pages/Admin/Service/Packages/Create.tsx:44-46,113-120` plain pickers; `Service/SpeedProfiles/Create.tsx` retypes speeds | "+ Baru" in place, speed prefill → W3-CATALOG |
| 47 | F3-U01 | 3b | open | `Pages/Admin/Users/Create.tsx` no employee part; `Employees/Index.tsx` separate | → W3-TEAMDOCS |
| 48 | F3-U04 | 3b | moved TR | tenant-roles §3.6.1 templates | none |
| 49 | F4-U04 | 3b | open | `Pages/Admin/Customers/Create.tsx` no assign block; `app/Actions/CreateCustomerOnboardingAction.php:47-59` makes a `generated` SPK only | "Tugaskan sekarang" → W3-SPK |
| 50 | F4-U14 | 3b | open | `app/Notifications/CustomerAccountCreated.php:56-62` default look; `app/Http/Controllers/Auth/NewPasswordController.php:61-62` ends on the web login | → W3-NOTIFY |
| 51 | F5-U02 | 3b | open | `Pages/Admin/Billing/Invoices/Create.tsx:81` items on a second page; item dialog `Show.tsx:124` tax `'0'` | one form with items → W3-INVOICE |
| 52 | F5-U04 | 3b | settled | call, WhatsApp, app link settled with F7-D01; "Bayar tanpa login" parked by the owner (`docs/PARKING_LOT.md:49`), OD13 | none |
| 53 | F6-U04 | 3b | partly settled | outage cards on Beranda (`CUST/features/home/home_feed.dart:23-41`); none in Bantuan | → M3-CUST |
| 54 | F6-U06 | 3b | open | `CUST/features/tickets/ticket.dart:96-128` no rating; no confirm endpoint | K14 card → M3-CUST (W3-TICKET) |
| 55 | F6-U11 | 3b | open | `Pages/Admin/Tickets/Show.tsx:99,709-712` name and email; `app/Queries/Admin/DispatchQueueQuery.php:307-330` | load, suggestion, "Tugaskan ke saya" → W3-TICKET (W3-SHELL type) |
| 56 | F6-U14 | 3b | partly settled | priority from category (`Tickets/Create.tsx:54-64`); title empty (`:40`); no lone subscription, no SLA line | → W3-TICKET |
| 57 | F6-U16 | 3b | partly settled | `Modules/Reporting/app/Queries/SlaComplianceQuery.php:14-62` average first response, per category | per technician, priority, source, violators, CSAT → W3-REPORTS |
| 58 | F7-U01 | 3b | settled | = F7-D01 | none |
| 59 | F9-U01 | 3b | open | = F9-D02 + F9-D03 | → W3-REPORTS |
| 60 | F7-U21 | 3b | partly settled | restart note `Pages/Admin/NasDevices/Show.tsx:144-148`; badge `resources/js/lib/statuses/network.ts:63` "Tertulis ke RADIUS" without a live check | → W3-NETWORK |
| 61 | F1-U03 | 3c | settled | `Pages/Admin/Company/Settings.tsx:409,704-707` sticky nav and save | none |
| 62 | F1-U04 | 3c | settled | `app/Http/Controllers/Admin/CompanyController.php:85-86,125-129`; `Settings.tsx:485-488` | none |
| 63 | F2-U03 | 3c | settled | `Service/Packages/Create.tsx:141-153`, `Edit.tsx:182-195` `MoneyInput` | none |
| 64 | F2-U04 | 3c | open | `LatLngMapPicker` only in `Company/Settings.tsx:525`; `Pages/Admin/Locations/Index.tsx` types lat/lng | → W3-CATALOG |
| 65 | F2-U05 | 3c | open | `app/Services/Core/LocationService.php:17-20,245-252,276` site needs a rack | → W3-CATALOG |
| 66 | F2-U06 | 3c | open | `Locations/Index.tsx:112` `useState('map')` | → W3-CATALOG |
| 67 | F2-U07 | 3c | open | `Pages/Admin/Odn/Index.tsx:256-257` no `emptyAction` | → W3-NETWORK |
| 68 | F2-U10 | 3c | partly settled | `DataTableActions` on profile lists; `Service/PackageCategories/Index.tsx:119-143` extra Hapus; `Locations/Index.tsx:319-345` raw buttons | → W3-CATALOG |
| 69 | F3-U05 | 3c | open | `resources/js/Pages/AppOnly.tsx:19-47` no fallback; `app/Http/Controllers/AppOnlyController.php:26-29` | → W3-TEAMDOCS |
| 70 | F4-U01 | 3c | open | `Customers/Create.tsx:195` empty primary contact | → W3-SPK |
| 71 | F4-U05 | 3c | settled | `Pages/Admin/SPK/Show.tsx:428-433,699`; `WorkOrderController.php:187-192` | none |
| 72 | F4-U08 | 3c | open | `TECH/features/work_orders/work_order_detail_screen.dart:112-250` evidence last | → M3-TECH-UI |
| 73 | F4-U09 | 3c | open | `TECH/features/evidence/evidence_actions.dart:98-125` one photo per round | → M3-TECH-UI |
| 74 | F4-U10 | 3c | partly settled | stock shown (`Modules/Inventory/app/Http/Resources/Api/V1/TechnicianProductResource.php:29-47`, `TECH/features/products/product_picker.dart:197-249`); no grouping | → M3-TECH-UI (W3-INVREQ) |
| 75 | F4-U12 | 3c | open | `Modules/Inventory/app/Services/InventoryRequestService.php:120-125` counts `submitted`; reused by `app/Queries/Admin/DashboardQuery.php:203-210` | → W3-INVREQ |
| 76 | F5-U05 | 3c | open | `CUST/features/invoices/invoice_detail_screen.dart:530-531,556` text only; `CUST/features/home/home_sections.dart:179` `showContactSheet` | → M3-CUST |
| 77 | F5-U06 | 3c | partly settled | = F12-D11 invoice part | → W3-NOTIFY |
| 78 | F5-U07 | 3c | partly settled | `DashboardQuery.php:64-65,288-300,318,339` drafts and online cards; no "jatuh tempo 3 hari" | → W3-DASH |
| 79 | F5-U08 | 3c | settled | `Modules/Billing/app/Services/BillingService.php:96-102,156-163,190-198`; `Billing/Invoices/GenerateDialog.tsx:52,140,146-195` | none |
| 80 | F5-U09 | 3c | settled | = F5-D03 | none |
| 81 | F5-U10 | 3c | open | `CUST/features/invoices/invoice_repository.dart:102-107` opens Snap at once; `invoice_detail_screen.dart:540` button spinner only | → M3-CUST |
| 82 | F5-U11 | 3c | open | `Billing/Invoices/Show.tsx:588-593,636` raw reason and `payment_type` | → W3-PAY |
| 83 | F5-U15 | 3c | open | `Modules/Billing/routes/web.php:29` `Route::inertia` to a static `Pages/Portal/Payments/Finished.tsx` | → W3-PAY |
| 84 | F5-U17 | 3c | settled | `scripts/dev-payments.sh` | none |
| 85 | F6-U02 | 3c | open | customer app entry words `ticketCreate`, `quickReportIssue`, `ticketNewTitle` (`CUST/l10n/app_id.arb`) | "Lapor masalah" → M3-CUST |
| 86 | F6-U03 | 3c | open | no duplicate check anywhere | → M3-CUST |
| 87 | F6-U08 | 3c | open | = F6-D08 header | → M3-TECH-UI (W3-TICKET) |
| 88 | F6-U10 | 3c | partly settled | confirm settled; ticket start snackbar in M2-TECH (in flight); far warning missing | K10 dialog, SPK start snackbar → M3-TECH-OPS (W3-FIELD) |
| 89 | F6-U17 | 3c | partly settled | ticket texts carry the code (`NotificationResource.php:146-166`); no priority, title, deadline; resolved has no note | → W3-NOTIFY (W3-TICKET data) |
| 90 | F6-U19 | 3c | open | = F6-D18 | → W3-CATALOG |
| 91 | F6-U20 | 3c | open | skip link only in `resources/js/Layouts/PlatformLayout.tsx:95-97`; none in `AdminLayout.tsx`; `Tickets/Show.tsx` no initial focus | → W3-SHELL (W3-TICKET) |
| 92 | F7-U02 | 3c | open | `Pages/Admin/Announcements/Index.tsx:43-47` generic confirm; `Modules/Service/app/Queries/CustomerAudienceQuery.php` exists | → W3-NETWORK |
| 93 | F7-U03 | 3c | settled | `Pages/Admin/Subscriptions/Show.tsx:583-587`; `Billing/Receivables.tsx:346-350` | none |
| 94 | F7-U04 | 3c | open | = F7-D03 | → W3-NETWORK |
| 95 | F8-U06 | 3c | open | `resources/js/Components/composite/RemoteSearchSelect.tsx:47,77` `minChars=2`, nothing on open; `app/Support/SearchOptions.php:55-57` | → W3-SHELL |
| 96 | F9-U02 | 3c | open | = F9-D04 | → W3-REPORTS |
| 97 | F11-U03 | 3c | open | `resources/js/Components/composite/platform/ConfirmActionDialog.tsx:57-62,84` always asks | → W3-PLATFORM |
| 98 | F11-U04 | 3c | open | `Pages/Platform/Companies/components/ExportsSection.tsx:32-75` no download | → W3-PLATFORM |
| 99 | F11-U05 | 3c | open | `lang/id/auth.php:7` seconds | → W3-PLATFORM |
| 100 | F11-U07 | 3c | settled | `app/Http/Middleware/EnforceImpersonation.php:170-174`; `Pages/Error.tsx:128-134` | none |
| 101 | F12-U04 | 3c | partly settled | = F12-D11 | → W3-NOTIFY |
| 102 | F12-U05 | 3c | open | = F12-D13 banner | → M3-TECH-OPS |
| 103 | F12-U06 | 3c | partly settled | warehouse = last used (`TECH/features/materials/new_request_screen.dart:64-69`), not stock-aware | → M3-TECH-UI (W3-INVREQ) |
| 104 | F12-U08 | 3c | moved W2-C | `TECH/features/work_orders/work_order_actions.dart:233-235` (M2-TECH) | none |
| 105 | F7-U24 | 3c | settled | `Pages/Admin/Subscriptions/Show.tsx:563-564` `details` | none |
| 106 | F2-U08 | 3d | open | router asset only from `Pages/Admin/NetworkAssets/Create.tsx:92-122`; NAS form needs an existing asset (`Modules/NetworkAsset/app/Http/Controllers/NasDeviceController.php:67-73`) | → W3-NETWORK |
| 107 | F3-U02 | 3d | open | `Users/Create.tsx:29,85-92` typed initial password | invitation link → W3-TEAMDOCS |
| 108 | F4-U03 | 3d | partly settled | nearest POP and lowest port (`Customers/Create.tsx:449-458,886-896`); labels `Modules/NetworkAsset/app/Queries/OdnOccupancyQuery.php:200-228` lack ODP name, free ports, distance | → W3-SPK |
| 109 | F4-U15 | 3d | open | `Modules/Inventory/app/Services/MaterialReturnService.php:184` typed; `TechnicianMaterialReturnResource.php:44-56` no SPK usage | → M3-TECH-UI (W3-INVREQ) |
| 110 | F5-U03 | 3d | open | `Pages/Admin/Billing/Receivables.tsx` no search, age filter, export, reminder | → W3-INVOICE |
| 111 | F5-U16 | 3d | open | = F5-D15 | → W3-PAY |
| 112 | F6-U05 | 3d | open | `CUST/features/tickets/ticket_detail_screen.dart:173-175,259-262`; `Modules/Ticketing/app/Http/Resources/Api/V1/CustomerTicketCommentResource.php:16-23` | → M3-CUST (W3-TICKET) |
| 113 | F8-U04 | 3d | open | `Inventory/Requests/Show.tsx:1255-1367` three dialogs | → W3-INVREQ |
| 114 | F8-U05 | 3d | open | `Pages/Admin/Inventory/Stocks/Index.tsx:480-610` | → W3-STOCK |
| 115 | F9-U03 | 3d | open | = F9-D08 | → W3-AUDIT |
| 116 | F10-U01 | 3d | open | `app/Http/Middleware/HandleInertiaRequests.php:87-90` parent id only; no ribbon | ribbon → W3-SHELL |
| 117 | F11-U06 | 3d | open | `Pages/Platform/Companies/components/LifecycleActions.tsx:87-94` | → W3-PLATFORM |
| 118 | F7-U25 | 3d | open | `NetworkAccounts/Show.tsx:717`; no disconnect time stored (`Modules/NetworkAsset/app/Models/NetworkAccount.php:29-33`) | → W3-NETWORK |
| 119 | F7-U26 | 3d | settled | `NetworkAccounts/Show.tsx:462-476` "Router belum menjawab sejak", "Coba sekarang" | none |
| 120 | F0-D02 | 3e | settled | K36: `boards_raw.bin`, `create-issues.sh`, `login-modern-theme.png`, `phpunit.xml.bak` absent from the root | none |
| 121 | F1-D02 | 3e | open | `noValidate` on 4 forms only; no `setCustomValidity` | → W3-SHELL |
| 122 | F1-D03 | 3e | partly settled | `Pages/Admin/Dashboard/components/activityText.ts:98-105` misses codes; `Platform/Companies/components/ActivitySections.tsx:33-38` raw | → W3-AUDIT (W3-PLATFORM) |
| 123 | F1-D04 | 3e | open | `.env.example:1,60`, `.env.docker.example:57`, `config/mail.php:114-115`; `app/Notifications/Platform/TenantAdminInvitation.php:44-49` | K22 → W3-NOTIFY |
| 124 | F2-D01 | 3e | open | `resources/js/Components/composite/NativeSelect.tsx:24` id from label; `Locations/Index.tsx:412,485,582` | → W3-SHELL |
| 125 | F2-D02 | 3e | settled | `RadiusException.php:29`; `lib/statuses/network.ts:66` | none |
| 126 | F2-D03 | 3e | settled | `database/seeders/CompanySeeder.php:82-96,465` | none |
| 127 | F3-D03 | 3e | moved TR | tenant-roles §7.1 OD1 | none |
| 128 | F4-D03 | 3e | partly settled | double toast gone (`resources/js/hooks/useToast.ts:7-16`); "SPK SPK-…" at `DispatchQueueController.php:132,147`; no code at `WorkOrderController.php:289` | → W3-SPK |
| 129 | F4-D04 | 3e | settled | `Customers/Create.tsx:963-990` `MoneyInput` | none |
| 130 | F4-D05 | 3e | open | `app/Http/Controllers/Admin/CustomerController.php:129`, `CustomerAccessLinkController.php:30` "dikirim" while only queued | → W3-SPK |
| 131 | F4-D09 | 3e | open | `app/Http/Resources/ApprovalInboxItemResource.php:104-163` sums units; `Approvals/components/ReviewSheet.tsx:524-537`, `MobileItemCard.tsx:65` | → W3-INVREQ |
| 132 | F4-D10 | 3e | moved W2-C | `TECH/features/work_orders/work_order_detail_screen.dart:157-160` (M2-TECH) | none |
| 133 | F4-D12 | 3e | open | `InventoryRequestService.php:300-309` SKU and location code; `Modules/Inventory/app/Exceptions/InsufficientStockException.php:34-52` | → W3-INVREQ |
| 134 | F4-D14 | 3e | partly settled | = F1-D03 | → W3-AUDIT |
| 135 | F4-D18 | 3e | settled | `app/Actions/CreateInvoiceFromSpkAction.php:62-63` | none |
| 136 | F5-D08 | 3e | partly settled | = F12-D11 | → W3-NOTIFY |
| 137 | F5-D11 | 3e | open | = F5-U15 | → W3-PAY |
| 138 | F5-D13 | 3e | open | `Billing/Invoices/Index.tsx:129` stored status; `overdue` only after `billing:check-overdue` (`BillingService.php:587-588`, `routes/console.php:10`) | → W3-INVOICE |
| 139 | F5-D17 | 3e | open | = F5-U15 | → W3-PAY |
| 140 | F5-D21 | 3e | open | `Modules/Billing/app/Providers/RouteServiceProvider.php:28-37` `Limit::none()` when signed | → W3-PAY |
| 141 | F6-D14 | 3e | partly settled | web uploader badge `Tickets/Show.tsx:654-657`; caption unseen on web and customer app; no web caption input (`Modules/Ticketing/app/Http/Requests/UploadTicketAttachmentRequest.php:19-22`) | → W3-TICKET (M3-CUST) |
| 142 | F6-D15 | 3e | partly settled | dropzone jpg/png/pdf (`Tickets/Show.tsx:620-627`) but server allows doc/txt (`UploadTicketAttachmentRequest.php:20-21`); double toast `Show.tsx:275` + `TicketController.php:401`; apps cannot open a PDF | → W3-TICKET (M3-CUST, M3-TECH-UI) |
| 143 | F6-D16 | 3e | partly settled | technician sheet pads (`TECH/features/tickets/ticket_actions.dart:227-228`); customer box neither scrolls nor clears the keyboard | → M3-CUST |
| 144 | F6-D17 | 3e | open | `CUST/features/tickets/new_ticket_screen.dart:107` leaves the hint pages below | → M3-CUST |
| 145 | F6-D18 | 3e | open | `CompanySeeder.php:136-140` no `customer_hint` | K12 → W3-CATALOG |
| 146 | F6-D19 | 3e | open | `CompanySeeder.php:136-140` English names | → W3-CATALOG |
| 147 | F6-D20 | 3e | open | `TicketService.php:124-134` `addHours` | K11 → W3-FIELD (M3-CORE, M3-CUST) |
| 148 | F6-D21 | 3e | partly settled | close text `Tickets/Show.tsx:685-687`, label `:117`; dropzone still shown and `required` (`:616-629`) | → W3-TICKET |
| 149 | F6-D22 | 3e | partly settled | score checked in JS (`Pages/Admin/Evaluations/Create.tsx:52-56`); `customer_rating` `:148-155` browser bubble | → W3-SHELL |
| 150 | F6-D23 | 3e | open | `resources/js/lib/statuses/ticket.ts:3-9`; `CUST/l10n/app_id.arb:189-193`; `TECH/l10n/app_id.arb:143-150` | R6 → W3-SHELL (M3-CUST, M3-TECH-UI) |
| 151 | F6-D24 | 3e | open | `Modules/Ticketing/app/Notifications/TicketAssignedNotification.php:28-33` | → W3-NOTIFY |
| 152 | F7-D03 | 3e | open | `Modules/NetworkAsset/routes/web.php:43` resource only; `NasDevices/Show.tsx:160-163` | → W3-NETWORK |
| 153 | F7-D04 | 3e | settled | K27: `app/Http/Controllers/Admin/SubscriptionController.php:133`; `Subscriptions/Show.tsx:550-553`; `app/Services/Core/SubscriptionService.php:121-123`; `BillingService.php:178` | none |
| 154 | F7-D05 | 3e | settled | = F7-U03 | none |
| 155 | F8-D07 | 3e | partly settled | `Modules/Inventory/app/Http/Resources/MaterialReturnLineResource.php:36` unit name | → W3-INVREQ |
| 156 | F8-D08 | 3e | open | `Modules/Inventory/app/Providers/InventoryServiceProvider.php:46` `{PREFIX}-{YYYY}{MM}{DD}-{SEQ:4}` daily; title wraps | K29 → W3-INVREQ |
| 157 | F8-D09 | 3e | partly settled | receive dialog labelled (`Requests/Show.tsx:1564-1575`); declare dialog `:1456-1500` not | → W3-INVREQ |
| 158 | F8-D10 | 3e | open | `Pages/Admin/Inventory/Movements/Index.tsx:85-136` no actor, no balance; `MaterialReturnService.php:363-370` books `receive` although `Modules/Inventory/app/Services/StockService.php:425-450` `returnStock()` exists | → W3-INVREQ (W3-STOCK) |
| 159 | F8-D11 | 3e | open | `Requests/Show.tsx:374-424` no rejected or cancelled step | → W3-INVREQ |
| 160 | F8-D12 | 3e | open | `stocks.transfer` route (`Modules/Inventory/routes/web.php:55`), `StockController.php:201-203`; no UI | K30 → W3-STOCK |
| 161 | F8-D13 | 3e | partly settled | catalog modes shipped; seeds "Default Region/Area/POP" (`CompanySeeder.php:177-179`) | → W3-CATALOG |
| 162 | F9-D04 | 3e | open | `Pages/Admin/Reports/Technician.tsx:96,169` waits for one technician; cards without units | → W3-REPORTS |
| 163 | F9-D05 | 3e | partly settled | `formatPercent` on Business, SLA, Technician; raw `%` at `Reports/Sales.tsx:99`, `Reports/Consolidated.tsx:88`, `Service/SLATiers/Index.tsx:100,109` | → W3-REPORTS (W3-FIELD for SLATiers) |
| 164 | F9-D06 | 3e | partly settled | `Pages/Admin/Reports/components/DrillLink.tsx:28-31` aria-label only | → W3-REPORTS |
| 165 | F9-D07 | 3e | open | `Modules/Reporting/app/Drill/DrillList.php:102` raw column names | → W3-REPORTS |
| 166 | F9-D08 | 3e | open | `Pages/Admin/Reports/AuditLog.tsx` codes; `Modules/Reporting/app/Http/Resources/AuditLogResource.php:83-84` type + ULID | → W3-AUDIT |
| 167 | F9-D09 | 3e | open | `Billing/Receivables.tsx` no export or date filter; `Pages/Admin/Reports/StockCard.tsx:217-219` empty start | K33 → W3-INVOICE (W3-REPORTS) |
| 168 | F10-D03 | 3e | partly settled | `resources/js/Components/composite/CompanySwitcher.tsx:35-45` ignores clicks while switching; trigger not disabled | → W3-SHELL |
| 169 | F10-D04 | 3e | open | `database/seeders/DatabaseSeeder.php:23-29` seeds `E2eFixtureSeeder` (`E2E-SUB`, `database/seeders/E2eFixtureSeeder.php:59-61`) into local and development | → W3-CATALOG |
| 170 | F11-D05 | 3e | open | `app/Http/Middleware/ResolveActiveCompany.php:44-49` flashes errors into a session it just invalidated (code-read) | → W3-PLATFORM |
| 171 | F11-D06 | 3e | partly settled | sessions end only on the `database` driver (`app/Services/Core/TwoFactorService.php:287-289`); dialog promises all (`UserActions.tsx:96`) | → W3-PLATFORM |
| 172 | F11-D07 | 3e | settled | = F11-U07 | none |
| 173 | F11-D08 | 3e | open | `resources/js/Components/ui/Toast.tsx:129` top-right over `ImpersonationBanner.tsx:78` | → W3-SHELL |
| 174 | F11-D09 | 3e | open | `app/Http/Requests/Platform/Audit/AuditIndexRequest.php:37-42` | → W3-PLATFORM |
| 175 | F11-D10 | 3e | open | `RemoteSearchSelect.tsx:82-87` writes `<field>_search` into the URL | → W3-SHELL |
| 176 | F11-D11 | 3e | open | `app/Services/Platform/PlatformAuditService.php:33-45` two rows; `app/Queries/Platform/PlatformAuditQuery.php:35` | → W3-PLATFORM |
| 177 | F11-D12 | 3e | settled | `tests/Feature/Platform/ImpersonationTest.php:366-369` asserts 423; `ImpersonationBanner.tsx:82-84` | none |
| 178 | F12-D02 | 3e | open | `CORE/motion/motion.dart:36-37` reads `disableAnimations` only | → M3-CORE |
| 179 | F12-D04 | 3e | open | = F6-D23 plus SPK "Dikembalikan" vs "Ditolak", invoice "Sebagian" vs "Dibayar sebagian" | R6 → M3-TECH-UI (W3-SHELL, M3-CUST) |
| 180 | F12-D06 | 3e | moved W2-C | M2-PUSH + M2-TECH | none |
| 181 | F12-D08 | 3e | settled | `docs/operations/push-notifications.md:27` | none |
| 182 | F12-D12 | 3e | moved W2-C | M2-TECH (`TripSteps` label, `typeLabel`) | none |
| 183 | F12-D14 | 3e | partly settled | = F4-U10 (stock shown) | → M3-TECH-UI (W3-INVREQ) |
| 184 | F12-D15 | 3e | open | `TECH/features/work_orders/work_order_detail_screen.dart:143,157-160,181` | K35 → M3-TECH-UI |
| 185 | F12-D16 | 3e | moved W2-C | M2-PUSH | none |
| 186 | F12-D18 | 3e | settled | `docs/operations/push-notifications.md:152` | none |
| 187 | F12-D19 | 3e | settled | `docs/operations/push-notifications.md:153` | none |
| 188 | F7-D23 | 3e | open | `RadiusDirectory.php:318` returns `updated_at`; `NetworkAccounts/Show.tsx:717-718` shows the start only | → W3-NETWORK |
| 189 | F7-D27 | 3e | settled | `lib/statuses/network.ts:66` neutral "RADIUS belum dikonfigurasi" | none |
| 190 | F1-U02 | 3f | open | `Pages/Platform/Companies/Create.tsx:111-121` plain code | → W3-PLATFORM |
| 191 | F1-U07 | 3f | open | `OnboardingChecklistQuery.php:21-29` no prefixes | → W3-DASH |
| 192 | F2-U02 | 3f | open | no code suggestion in `Service/Packages/Create.tsx` | → W3-CATALOG |
| 193 | F2-U09 | 3f | open | `NetworkAssets/Create.tsx:92,112-122` | → W3-NETWORK |
| 194 | F3-U03 | 3f | open | `Pages/Admin/Organizations/Index.tsx:316-322`; `Pages/Admin/Vehicles/Index.tsx:269-275` | → W3-CATALOG |
| 195 | F3-U06 | 3f | moved TR | tenant-roles §7.1: tenants edit roles, button not built | none |
| 196 | F4-U02 | 3f | open | `Customers/Create.tsx:303-320`; `app/Services/Core/OpenStreetMapGeocoder.php:36-44` | → W3-SPK |
| 197 | F4-U13 | 3f | open | web "Serah Terima & Kurangi Stok" (`Requests/Show.tsx:531`), "Antrean Pengembalian" (`Index.tsx:461`) vs app "Dikeluarkan" | R6 → W3-INVREQ (M3-TECH-UI) |
| 198 | F4-U16 | 3f | open | `DashboardQuery.php:182-195` via `app/Queries/Admin/ApprovalInboxQuery.php:243-246` (maker excluded) | → W3-DASH |
| 199 | F4-U17 | 3f | settled | rule kept: design-w2 §7 OD6 (Berangkat and Mulai notify at once; no undo) | none |
| 200 | F7-U05 | 3f | settled | `Pages/Admin/NetworkAssets/Index.tsx:205-211` status filter with "Perawatan" | none |
| 201 | F10-U02 | 3f | partly settled | redirect with warning (`ConsolidatedReportRequest.php:35-44`); no switch button | → W3-REPORTS |
| 202 | F11-U08 | 3f | open | = F11-D11 | → W3-PLATFORM |
| 203 | F11-U09 | 3f | open | = F11-D08 | → W3-SHELL |
| 204 | F11-U10 | 3f | open | `resources/js/Components/ui/SearchSelect.tsx:96-141` no scroll to the selected value | → W3-SHELL |
| 205 | F12-U07 | 3f | open | = F12-D15 | → M3-TECH-UI |
| 206 | F12-U09 | 3f | open | `CORE/notifications/notifications_screen.dart:59-126` (code-read) | → M3-CORE |
| 207 | F7-U27 | 3f | partly settled | `Pages/Admin/Subscriptions/Index.tsx:66-70` preselects `is_primary` only; `resources/js/Components/composite/ipam/SubscriptionIpPanel.tsx:85` | → W3-NETWORK |
| 208 | F12-D03 | 3g | settled | info: fixed colours only in photo viewers (`CUST/features/tickets/ticket_detail_screen.dart:944`, `TECH/features/work_orders/evidence_thumbnail.dart:74,88`) | none (viewer stays black; M3-TECH-UI comments the white icon) |
| 209 | F12-D05 | 3g | open | untested: API login of a suspended tenant answers 422 `errors.email` = `CompanyService::SUSPENDED_MESSAGE` (`app/Http/Requests/Api/V1/LoginRequest.php:18` extends `app/Http/Requests/Auth/LoginRequest.php:78-79`); app display unproven | test-first → M3-CORE (W3-PLATFORM API test) |
| 210 | F12-D20 | 3g | open | unconfirmed: `CORE/push/push_service.dart` `_show` draws empty title and body | guard → M3-CORE |

Totals: settled 48, moved W2-C 10, moved TR 4, partly settled 38, open 110 (148 to build).

### 1.3 Named remainders of waves 1-2

| Remainder | Today | Where |
|---|---|---|
| K10 check-in distance (F6-D07, F6-U10) | open | §4.3, W3-FIELD + M3-TECH-OPS |
| K11 service hours (F6-D20) | open | §4.3, W3-FIELD |
| K12 category hints (F6-D18, F6-U19) | open | §3.6, W3-CATALOG |
| K13 staff reopen (F6-D02) | partly | §4.2, W3-TICKET |
| K14 customer rating (F6-D03, F6-U06) | open | §4.2, W3-TICKET + M3-CUST |
| K16 schedule (F4-D02, F4-U04) | partly | §4.4, W3-SPK |
| K19, K20, K24 | settled | `app/Services/Platform/SupportToolsService.php:87,107`, `app/Support/SupportActionText.php`, `app/Http/Middleware/EnforceCompanyTwoFactor.php` |
| K22 branded mail (F1-D04, F4-U14, F6-D24) | open | §4.8, W3-NOTIFY |
| K26 customer documents (F3-D02) | open | §4.14, W3-TEAMDOCS |
| K29 REQ numbers (F8-D08) | open | §3.1 M-4, W3-INVREQ |
| K30 transfer UI (F8-D12) | open | §4.7, W3-STOCK |
| K32 export template (F9-D03) | open | §4.10, W3-REPORTS |
| K33 receivables export (F9-D09) | open | §4.6, W3-INVOICE |
| K34 subsidiary request (F10-D01) | open | §4.12, W3-PLATFORM |
| K35 returned SPK (F12-D15) | open | §5.3, M3-TECH-UI |
| F4-D12 shortfall text | open | §4.7, W3-INVREQ |
| F8-D03 technician stock | settled | stock shown at `TechnicianProductResource.php:29-47` (design-w2 note "hides stock on purpose" is outdated) |
| F12-D13, F12-U05 offline banner | partly | §5.3, M3-TECH-OPS |
| SEC-1 payload minimisation | open | §4.1, W3-SEC |

### 1.4 Same-root groups (one fix each)

| Group | Root cause | One fix | IDs | Slice |
|---|---|---|---|---|
| G1 audit text | event codes rendered raw in three places; only the dashboard has a (client) dictionary | NEW `App\Support\AuditEventText` (server dictionary + bulk subject labels), used by every audit surface | F1-D03, F4-D14, F9-D08, F9-U03 | W3-AUDIT (W3-PLATFORM) |
| G2 R6 glossary | every surface names statuses itself | one glossary: web `lib/statuses/*`, customer and technician `app_id.arb` | F6-D23, F12-D04, F4-U13, F6-U02 | W3-SHELL, W3-INVREQ, M3-CUST, M3-TECH-UI |
| G3 push and bell text | notification data holds ids only, so the one builder has nothing to say | richer `data` + `NotificationResource` text; app shows the text as is | F12-D11, F5-U06, F5-D08, F12-U04, F6-U17 | W3-NOTIFY (W3-TICKET, M3-CORE) |
| G4 browser validation text | native bubbles follow the browser language | one capture-phase `invalid` listener with Indonesian text | F1-D02, F6-D22 | W3-SHELL |
| G5 branded mail | Laravel default views and `APP_NAME=Laravel` | published views + `MailBrand` in `CompanyMailChannel` | F1-D04, F4-U14, F6-D24 | W3-NOTIFY |
| G6 one toast source | page toasts plus server flash | flash only; toaster below the support banner | F4-D03, F6-D15, F11-D08, F11-U09 | W3-SPK, W3-TICKET, W3-SHELL |
| G7 picked, not typed | free text where data exists | pickers, suggestions, prefill | F2-U01, F2-U02, F2-U04, F2-U09, F3-U03, F4-U01, F4-U02, F4-U03, F4-U15, F1-U02, F8-U06 | W3-CATALOG, W3-SPK, W3-NETWORK, W3-PLATFORM, W3-SHELL, M3-TECH-UI |
| G8 Midtrans finish | a static page whatever happened | controller reads the whitelisted status for display | F5-U15, F5-D11, F5-D17 | W3-PAY |

## 2. Affected modules

| Area | Main paths | Slices |
|---|---|---|
| Ticketing | `Modules/Ticketing/{routes,app/Http,app/Services/TicketService.php,app/Policies,app/Notifications,database/migrations}` | W3-TICKET, W3-FIELD, W3-NOTIFY, W3-CATALOG |
| SPK and onboarding | `Modules/SPK/app/{Http,Services/SpkService.php}`, `app/Actions/CreateCustomerOnboardingAction.php`, `app/Http/Controllers/Admin/{CustomerController,DispatchQueueController}.php` | W3-SEC, W3-SPK, W3-FIELD |
| Billing and payments | `Modules/Billing/{routes,app/Http,app/Services,app/Notifications}`, `resources/views/pdf/` | W3-SEC, W3-NOTIFY, W3-PAY, W3-INVOICE |
| Inventory | `Modules/Inventory/{app,database/migrations}`, `app/Http/Resources/ApprovalInboxItemResource.php` | W3-INVREQ, W3-STOCK |
| Reporting and audit | `Modules/Reporting/app/{Exports,Queries,Drill,Http}`, `resources/views/exports/table.blade.php`, `app/Support/ExportQuery.php` | W3-AUDIT, W3-REPORTS |
| Network | `Modules/NetworkAsset/{app,routes,database/migrations}`, `Modules/Service/app/Queries/CustomerAudienceQuery.php` | W3-SEC, W3-NETWORK |
| Service catalog and locations | `Modules/Service/Http/{Controllers,Requests}`, `app/Services/Core/LocationService.php`, seeders | W3-FIELD, W3-CATALOG |
| Platform | `app/Http/{Middleware/Platform,Controllers/Platform}`, `app/Services/Platform`, `app/Queries/Platform` | W3-PLATFORM |
| Shell and shared UI | `resources/js/{app.tsx,Layouts,Components,lib,types}`, `app/Http/Middleware/HandleInertiaRequests.php`, `app/Support/SearchOptions.php` | W3-SHELL |
| Notifications and mail | `app/Http/Resources/NotificationResource.php`, `app/Notifications`, `resources/views/vendor/` (NEW) | W3-NOTIFY |
| Team and documents | `app/Http/Controllers/Admin/{DocumentController,UserController}.php`, `resources/js/Pages/Admin/{Customers/Show,Users/Create}.tsx` | W3-TEAMDOCS |
| Dashboard | `app/Queries/Admin/{DashboardQuery,OnboardingChecklistQuery}.php`, `Pages/Admin/Dashboard` | W3-DASH |
| Mobile | `CORE/{motion,push,notifications,screens,format,widgets}`, `TECH/{features,data}`, `CUST/features` | M3-CORE, M3-TECH-OPS, M3-CUST, M3-TECH-UI |
| Docs | `docs/api/mobile-v1.openapi.json`, `CHANGELOG.md`, `docs/user-guide/*`, `docs/operations/*` | W3-DOCS |

## 3. Data model and migrations

Rules (`mysql` skill, repo wins): nullable columns added at the end of the table (no `after()`), so MySQL 8.4 runs `ADD COLUMN` as `ALGORITHM=INSTANT`; new indexes run `INPLACE, LOCK=NONE`; `timestamp()` like the neighbouring columns; Laravel `enum` for a closed set; guard with `Schema::hasColumn` like `Modules/Ticketing/database/migrations/2026_10_28_100100_add_response_source_and_reopen_to_tickets_table.php`. The prefix `2026_11_02_1001xx` sorts after tenant-roles (`2026_10_28_*`).

### 3.1 Migrations

| # | File | Kind | Up (`--pretend` shows) | Down | Online DDL |
|---|---|---|---|---|---|
| M-1 | `Modules/Ticketing/database/migrations/2026_11_02_100100_add_customer_rating_to_tickets_table.php` | DDL | `alter table tickets add customer_rating tinyint unsigned null, add customer_rating_comment text null, add customer_rated_at timestamp null`; `alter table tickets add index tickets_company_id_customer_rated_at_customer_rating_index(company_id, customer_rated_at, customer_rating)` | drop index, drop the three columns | INSTANT + INPLACE |
| M-2 | `Modules/Ticketing/database/migrations/2026_11_02_100200_localise_default_ticket_categories.php` | DML | per code: `update ticket_categories set name = ?, updated_at = ? where code = ? and name = ?` (old English name) and `update ticket_categories set customer_hint = ? where code = ? and customer_hint is null` | name back where it still equals the new name; hint to null where it still equals the seeded hint | none |
| M-3 | `Modules/Service/database/migrations/2026_11_02_100300_add_calendar_to_sla_tiers_table.php` | DDL | `alter table sla_tiers add calendar enum('24h', 'business') not null default '24h'` | drop column | INSTANT |
| M-4 | `Modules/Inventory/database/migrations/2026_11_02_100400_move_inventory_request_numbers_to_yearly.php` | DML | `update number_sequences set template = '{PREFIX}-{YYYY}-{SEQ:5}', reset_period = ?, period_key = null, next_number = 1, updated_at = ? where entity_type = 'inventory_request' and template = '{PREFIX}-{YYYY}{MM}{DD}-{SEQ:4}' and reset_period = ?` (bindings `ResetPeriod::Yearly->value`, `ResetPeriod::Daily->value`) | inverse update on rows still on the new pair | none |
| M-5 | `Modules/Inventory/database/migrations/2026_11_02_100500_add_receipt_fields_to_stock_movements_table.php` | DDL | `alter table stock_movements add supplier_name varchar(150) null, add purchase_reference varchar(100) null, add unit_cost decimal(15, 2) null` | drop the three | INSTANT |
| M-6 | `Modules/NetworkAsset/database/migrations/2026_11_02_100600_add_last_disconnect_at_to_network_accounts_table.php` | DDL | `alter table network_accounts add last_disconnect_at timestamp null` | drop column | INSTANT |
| M-7 | `database/migrations/2026_11_02_100700_release_ticket_reopen_to_templates.php` | DML | `PermissionCatalogue::release('ticket.reopen', ['manager', 'noc'])` | delete the `ticket.reopen` grants of templates `manager` and `noc` and of company roles whose `template_key` is one of them; forget the permission cache | none |

Notes:
- M-1: `customer_rating` stays NULL until rated (absent is not zero). The index serves and covers the CSAT aggregate (§3.5 Q1).
- M-4: old codes `REQ-20261020-0003` and new `REQ-2026-00001` cannot collide (different shape; unique `(company_id, code)` holds). Rows a tenant customised are left alone. `tests/Feature/NumberSequenceTest.php:167` gets the new default.
- M-7 is the only grant; `ticket.reopen` already exists in the catalogue (`RolePermissionSeeder.php:176`) and was unused, so the down step removes nothing a tenant relied on. Template keys are provenance keys (tenant-roles §3.6), not role-name checks.

### 3.2 Company settings (no schema change)

Stored where `support_hours` lives today, written through `UpdateCompanySettingsRequest`:
- `checkin_max_distance_m`: integer 50..100000, default 500.
- `service_hours`: `{mon..sun: null or ["HH:MM","HH:MM"]}`; start < end; null = closed. Empty week = "not set" (24h fallback + Settings warning).

### 3.3 New audit and log events

| log_name | description | properties | Slice |
|---|---|---|---|
| ticket | `reopened` | by (staff or customer), reason, previous_status | W3-TICKET |
| ticket | `customer_confirmed` | rating, closed (bool) | W3-TICKET |
| payment_attempt | `callback_received` | transaction_status, status_code, fraud_status, http | W3-PAY |
| payment_attempt | `cancel_requested` | order_id | W3-PAY |
| invoice | `reminder_sent` | channels | W3-INVOICE |
| invoice | `draft_updated` | changed keys | W3-INVOICE |
| stock | existing receive event; supplier fields go into its properties | supplier_name, purchase_reference | W3-STOCK |
| nas_device | `sync_requested` | asset_code | W3-NETWORK |
| customer | `document_uploaded`, `document_deleted` | type, file name | W3-TEAMDOCS |
| platform audit | `tenant.subsidiary_requested`, `tenant.subsidiary_request_closed` | name, code, note, requested_by, outcome, reason | W3-PLATFORM |
| log channel | `midtrans.callback` (info, every callback) | order_id, transaction_status, status_code, fraud_status, http | W3-PAY |

W3-AUDIT puts every event above into `AuditEventText` in batch B, so later slices do not touch that file.

### 3.4 Notification data (no schema change)

| Class | New `data` keys |
|---|---|
| `Modules/Billing/app/Notifications/InvoiceWhatsAppNotification.php` (base of issued, due reminder, payment received) via `WhatsAppNotification::bell()` | `invoice_number`, `total`, `outstanding`, `due_date` (Y-m-d), `stage` (reminders) |
| `TicketAssignedNotification` | `priority`, `title`, `response_due_local` (company time "21.17" or "08.30 besok") |
| `TicketCustomerUpdateNotification` | stage `resolved`: `resolution_excerpt` (80 chars); new stage `reopened` |
| `TicketStaffNotification` | event `reopened`: `by`, `actor_name`, `reason` |
| NEW `App\Notifications\CustomerDocumentExpiringNotification` | type `customer_document_expiring`: `customer_id`, `customer_code`, `customer_name`, `document_type`, `expires_at` |
| `InvoiceDueReminderNotification` | stage `manual` |

Old rows lacking the keys fall back to today's generic text.

### 3.5 Hot queries and expected EXPLAIN (MySQL 8.4)

| Q | Query | Index | Expected EXPLAIN |
|---|---|---|---|
| Q1 CSAT | `select avg(customer_rating), count(*) from tickets where company_id = ? and customer_rated_at between ? and ?` | M-1 `(company_id, customer_rated_at, customer_rating)` | `type=range`, key = M-1 index, `Extra: Using where; Using index` |
| Q2 handler load | `select assigned_to, count(*) from tickets where company_id = ? and assigned_to in (...) and status in ('assigned','on_progress') group by assigned_to`; same on `work_orders` | existing `(company_id, assigned_to)` (`tickets` migration :40, `work_orders` :38) | `type=range`, key `..._company_id_assigned_to_index`, `Using index condition; Using where`; no filesort |
| Q3 receivable invoices | `select ... from invoices where company_id = ? and status in ('sent','partial','overdue') and due_date < ? order by due_date limit 25 offset ?` | existing `(company_id, status)` | `type=range`, `Using index condition; Using filesort` over the tenant's open invoices only (hundreds). `shortcut:` comment: add `(company_id, status, due_date)` when one tenant passes 50k open invoices |
| Q4 due in 3 days | `select count(*) from invoices where company_id = ? and status in ('sent','partial') and due_date between ? and ?` | `(company_id, status)` | `type=range`, rows = open invoices |
| Q5 callback trail | `select ... from activity_log where subject_type = ? and subject_id in (...) and description = 'callback_received' order by id desc` once per invoice page | spatie `subject` morph index | `type=range`, key `subject`; grouping to 5 per attempt in PHP |
| Q6 audit subject labels | one `whereIn('id', ...)` per subject type on the page | primary key | `type=range`, key PRIMARY |
| Q7 technician ranking | one grouped query per measure (`group by assigned_to`) over the period | `(company_id, assigned_to)` / `(company_id, status)` | no query per technician; `Using temporary` acceptable on the period |
| Q8 RADIUS last seen | `select max(acctupdatetime) from radacct where nasipaddress = ?`; ages as `TIMESTAMPDIFF(SECOND, acctupdatetime, NOW())` inside the RADIUS DB | FreeRADIUS `nasipaddress` index | `type=ref`; no app-clock comparison |
| Q9 document expiry | media of `Customer` whose `custom_properties->'$.expires_at'` is between today and +30 days | `model` morph index narrows; JSON filter per row | scan of customer media; `shortcut:` comment: add a generated column + index past 100k media rows |

Slices with a new hot query run `EXPLAIN` through the Boost `database-query` tool (read-only) on `inbils_test` after a PHPUnit seed and paste it in the report.

### 3.6 Seeders

- `CompanySeeder::seedTicketCategories` (codes unchanged): no_internet "Internet mati", slow_connection "Koneksi lambat", packet_loss "Koneksi putus-sambung", device_issue "Masalah perangkat", fiber_issue "Kabel fiber bermasalah", each with `customer_hint`:
  - no_internet: "Periksa lampu modem. Cabut adaptor 10 detik lalu pasang lagi. Bila lampu LOS merah, kirim laporan ini."
  - slow_connection: "Coba dengan satu perangkat di dekat modem. Hentikan unduhan besar. Bila tetap lambat, sebutkan jam kejadiannya."
  - packet_loss: "Catat jam saat koneksi terputus dan berapa lama. Hidupkan ulang modem sekali sebelum melapor."
  - device_issue: "Sebutkan perangkat yang bermasalah (modem atau router) dan lampu yang menyala."
  - fiber_issue: "Jangan menarik atau menekuk kabel. Kirim foto kabel yang rusak bila bisa."
- Seeded locations: "Default Region/Area/POP" → "Wilayah Utama", "Area Utama", "POP Utama" (names only; codes kept).
- `DatabaseSeeder` runs `E2eFixtureSeeder` only when `app()->environment('testing')`; `tests/e2e/setup.php:79-82` keeps calling it explicitly.
- The dev DB `inbils` keeps its old names and `E2E-SUB` rows (no reseed, owner rule; OD11). M-2 renames only unchanged English rows.

## 4. API contracts

### 4.1 SEC-1 payload minimisation (W3-SEC)

Shapes: customer summary `{id, code, name}` (NEW static `CustomerResource::summary(?Customer): ?array`); subscription summary `{id, code}` (NEW `SubscriptionResource::summary(?Subscription): ?array`); user summary `{id, name}` built inline.

| Resource | Today | After |
|---|---|---|
| `Modules/SPK/app/Http/Resources/WorkOrderResource.php` | whole customer, subscription, assignee (`UserResource`), evidence everywhere | customer summary; subscription summary; assignee and creator `{id, name}`; `evidence` only on `admin.spk.show` |
| `Modules/Billing/app/Http/Resources/InvoiceResource.php` | whole customer, subscription | summaries |
| `Modules/NetworkAsset/app/Http/Resources/NetworkAssetResource.php` | whole customer, subscription | summaries |
| `app/Http/Resources/SubscriptionResource.php` | whole customer | customer summary |

Pages read only these keys today (`resources/js/types/spk.d.ts:23-26`, `billing.d.ts:28-29`, `network-asset.d.ts:51-52`, `models.ts:58`); W3-SEC narrows the types so `npm run typecheck` proves no reader is left. Out of scope here: `WorkOrderController::edit` (W3-SPK switches it to `createOptions`), `TicketResource` embeds (W3-TICKET applies the same summaries).

### 4.2 Tickets (W3-TICKET)

| Method, URI | Name | Auth | Request | Response | Errors |
|---|---|---|---|---|---|
| POST `admin/tickets/{ticket}/reopen` | `admin.tickets.reopen` | `TicketPolicy::reopen`: `ticket.reopen`, same company, status resolved or closed | NEW `ReopenTicketRequest`: `reason` required string 5..500 | redirect back, flash "Tiket {code} dibuka kembali." | 403; 422 `status` "Hanya tiket yang diselesaikan atau ditutup yang bisa dibuka kembali." |
| POST `admin/tickets/{ticket}/assign-self` | `admin.tickets.assign-self` | `TicketPolicy::assign` and the actor is an eligible handler | none | redirect, flash "Tiket {code} ditugaskan ke Anda." | 403, 409 stale |
| GET `admin/tickets/sla-preview` | `admin.tickets.sla-preview` | `ticket.create` | `category_id`, `subscription_id` (optional, tenant-owned) | JSON `{response_due, resolution_due, source, calendar}` from `TicketService::deadlineSource` on an unsaved ticket | 422 |
| POST `api/v1/customer/tickets/{ticket}/confirmation` | `api.v1.customer.tickets.confirmation` | `auth:sanctum`, `can:evaluation.customer.submit`, own ticket (policy), `Idempotency-Key` | NEW `ConfirmCustomerTicketRequest`: `rating` required int 1..5, `comment` nullable string ≤1000 | 200 `CustomerTicketResource` | 409 "Tiket ini sudah dinilai."; 422 `ticket_not_resolved` "Tiket belum diselesaikan." |

Service: `TicketService::reopen(Ticket, string $by, ?string $reason = null)` also clears `closed_at`; deadlines and rating unchanged; audit §3.3; notify `TicketStaffNotification('reopened')` to the handler (else `ticket.assign` holders via `StaffRecipients::holding()`), actor excluded; customer stage `reopened` only when `by = staff`. `TicketService::confirmByCustomer(Ticket, int $rating, ?string $comment)`: resolved → shared private close routine (extracted from `close()`, no `assertCloser`) + rate; closed and unrated → rate only.
Resources: `TicketResource` adds `customer_rating {value, comment, rated_at}`, `can.reopen`, `can.assign_self`, subscription and network asset summaries; `CustomerTicketResource` adds `rating`, `can_confirm` (resolved, unrated), `can_rate` (closed, unrated); `TechnicianTicketResource` adds `site {address, lat, lng, source: 'installation' or 'pop'}`; `TicketAttachmentResource` adds `caption`, `uploaded_at`, `uploader_first_name`.
Uploads: `UploadTicketAttachmentRequest` mimes `jpg,jpeg,png,pdf`, max as today, NEW `caption` nullable ≤200; the API `StoreTicketAttachmentRequest` same mimes. Double toast: drop the page toast at `Tickets/Show.tsx:275` (flash stays).
Handler load: `DispatchQueueQuery::eligibleHandlers/eligibleTechnicians` add `load` (open tickets + active SPKs, Q2) and drop `email`; the option type `QueueUserOption` (`resources/js/types/index.ts:312`) gains `load: number` and loses `email` (W3-SHELL owns `types/index.ts` in batch A).
Evaluation prefill: `EvaluationController::ticketPrefill` fills `customer_rating` from the ticket.

### 4.3 Field rules (W3-FIELD)

- K10: `FieldCheckIn::rules()` adds `check_in.far_reason` nullable string ≤300. At start only (`SpkService::start`, technician `TicketController::start`): distance to the site above `checkin_max_distance_m` without a reason → 422 on `check_in.far_reason`: "Anda berjarak {jarak} dari lokasi pelanggan. Tulis alasannya untuk melanjutkan." The stored check-in gains `far`, `far_reason`. NEW `FieldCheckIn::pageSummary(?array): ?array` `{distance_m, far, far_reason}` used at `TicketResource.php:73`, `WorkOrderController.php:198`, `ApprovalInboxQuery.php:616`.
- K11: NEW pure `App\Support\ServiceHours::add(CarbonImmutable $from, int $minutes, array $hours, string $tz): CarbonImmutable` (counts open minutes only; starts at the next opening; end exclusive; empty week → flat add; 14-day guard → flat add). `TicketService::computeDeadlines/deadlineSource` use it when the tier `calendar = business`; `deadlineSource` returns `calendar`. Categories stay 24h. SLA tier requests accept `calendar` in `24h,business`.

### 4.4 SPK and registration (W3-SPK)

- `scheduled_date` nullable date `after_or_equal:today` in `AssignWorkOrderRequest`, `DispatchQueueController::assign`, `StoreCustomerRequest` (`subscription.scheduled_date`); message "Tanggal kunjungan tidak boleh sebelum hari ini." `SpkService::assign(WorkOrder $wo, int $technicianId, int $assignedBy, mixed $expectedAssignedTo = ..., mixed $expectedUpdatedAt = ..., ?string $scheduledDate = null)` (new last parameter; every caller grepped).
- Registration: `StoreCustomerRequest` adds `assign_technician_id` (nullable, `IsActiveTechnician`, prohibited unless the actor holds `spk.assign`); `CreateCustomerOnboardingAction` assigns in the same transaction.
- `admin.spk.edit` renders NEW `Admin/SPK/Edit` with `createOptions` (no full customer list).
- Flash: "{code} ditugaskan ke {nama}." (`WorkOrderController::assign`, `DispatchQueueController::assign`); "Tautan akses akan dikirim ke {email}." (`CustomerController.php:129`, `CustomerAccessLinkController.php:30`).
- ODP options (`OdnOccupancyQuery`) add `odp_name`, `free_ports`, `total_ports`, `lat`, `lng`; the page sorts by distance.
- Geocoder results add `street` (road + house number) in `OpenStreetMapGeocoder::search`.

### 4.5 Payments (W3-PAY, security)

| Method, URI | Name | Auth | Notes |
|---|---|---|---|
| GET `admin/invoices/{invoice}/receipt` | `admin.invoices.receipt` | `billing.pdf.view`, same company, status paid | NEW `resources/views/pdf/receipt.blade.php` ("KUITANSI PEMBAYARAN", "KWT/{number}", payments table, no bank block); 422 "Kuitansi hanya untuk tagihan yang sudah lunas." |
| GET existing customer receipt API | unchanged | unchanged | renders `pdf.receipt` |
| POST `admin/payment-attempts/{attempt}/cancel` | `admin.payment-attempts.cancel` | `billing.payment.record`, same company, status pending | dispatches existing `CancelMidtransTransactionJob`; audit `cancel_requested`; flash "Pembatalan dikirim ke Midtrans."; 422 "Hanya pembayaran yang masih menunggu yang bisa dibatalkan." |
| GET `pembayaran/selesai` | existing name kept | public | NEW invokable controller; reads `transaction_status` from a whitelist; no DB lookup; never echoes `order_id` |

Callback trail: `Log::info('midtrans.callback', ...)` on every callback; audit row per known attempt; `PaymentAttemptResource` adds `reason_label`, `method_label`, `callbacks` (last 5, Q5). Signed replay limiter: `Limit::perMinute(10)->by(order_id.'|'.transaction_status)` instead of `Limit::none()`.

### 4.6 Invoices and receivables (W3-INVOICE, security)

- F5-U02: `StoreInvoiceRequest` takes `items[]` (`description`, `quantity` > 0, `unit_price` ≥ 0, `product_id` nullable tenant-owned) and `due_date` `after_or_equal:issue_date`; tax rate from `BillingService::taxRateFor`; one form.
- F7-D26: `admin.invoices.edit` renders NEW `Admin/Billing/Invoices/Edit` for drafts (due date, notes, discount; `UpdateInvoiceRequest`); non-draft → redirect with "Hanya tagihan draf yang bisa diubah."
- F5-U03 + K33: Receivables page gets a tab "Per tagihan" (default; NEW `Modules/Billing/app/Queries/ReceivableInvoicesQuery.php`, Q3) beside the existing "Per pelanggan" (`BillingService::receivables()` contract unchanged; callers `ReceivablesQuery.php:17`, `DrillRegistry.php:106,220`). Filters `search`, `bucket` (1-30, 31-60, 61-90, >90), `due_from`, `due_to`.
- GET `admin/billing/receivables/export` (`admin.billing.receivables.export`, `billing.export`, K32 template).
- POST `admin/invoices/{invoice}/remind` (`admin.invoices.remind`, `billing.send`): `Cache::add("invoice-remind:{company}:{invoice}:{date}")`, else 429 "Pengingat untuk {number} sudah dikirim hari ini."; `InvoiceDueReminderNotification` stage `manual`; audit `reminder_sent`; flash "Pengingat dikirim untuk {number}."
- Invoices index accepts `due_within=3` (consumed by W3-DASH).

### 4.7 Inventory (W3-INVREQ, W3-STOCK)

- F4-D12: shortfall via `InsufficientStockException::forShortfall` with product and warehouse names: "Stok {barang} di {gudang} tidak cukup: tersedia {n} {satuan}, diminta {m} {satuan}." Same at `MaterialReturnService.php:355` (product name, not ULID).
- F4-U12: warehouse count = requests the actor can act on; waiting ones show "Menunggu persetujuan Manajer/Admin".
- F8-D06 `source_code`; F8-D07 unit symbol; F8-D10 return booked via `StockService::returnStock()`.
- F8-U04: the existing prepare route performs prepare + mark ready in one action ("Siapkan barang"); handover keeps its confirm.
- F4-D09: `ApprovalInboxItemResource` material impact per unit (`[{product, quantity, unit}]`), no cross-unit sum.
- Technician API: `TechnicianProductResource` adds `category {id, name}`; `TechnicianWarehouseResource` adds `available_products` (count with stock > 0, one grouped query); `TechnicianMaterialReturnResource` lines add `recorded_used_qty` (from the linked SPK items, null without SPK).
- K30 (W3-STOCK): "Pindahkan stok" modal posting to existing `stocks.transfer` (`inventory.stock.transfer`); receive adds `supplier_name`, `purchase_reference`, `unit_cost` (M-5) in `StockReceiveRequest`; adjust shows system stock; `StockMovementResource` adds `actor {id, name}` (eager `creator`), `balance_after` (already stored, `StockMovement::$fillable`), receipt fields.

### 4.8 Notifications and mail (W3-NOTIFY, security)

Text in `NotificationResource::title()/message()` (the push uses the same):
- Issued: "Tagihan {number}" / "Tagihan {number} sebesar Rp {total}, jatuh tempo {tgl}."
- Reminder H-3, H-1, manual: "{number} jatuh tempo {3 hari lagi, besok, tgl}" / "Sisa Rp {outstanding}. Bayar lewat aplikasi."
- Overdue H+1: "{number} lewat jatuh tempo" / "Sisa Rp {outstanding} belum dibayar sejak {tgl}."
- Payment received: "Pembayaran diterima" / "Rp {amount} untuk {number} sudah kami terima. Terima kasih."
- Ticket assigned: "Tiket {code} · {prioritas}" / "{title}. Respons sebelum {response_due_local}."
- Customer resolved: "Tiket {code} diselesaikan" / "{resolution_excerpt} Sudah beres?"
- Ticket reopened (staff): "Tiket {code} dibuka kembali" / "Oleh {actor_name}: {reason}"; customer stage: "Tiket {code} dibuka kembali dan akan ditangani lagi."
- Document expiring: "Dokumen {document_type} {customer_name} segera habis" / "Berlaku sampai {tgl}."
Mail: NEW `App\Support\MailBrand::for(object $notifiable, Notification $notification): array{name: string, logo_url: ?string}` (Inbils for `SentByPlatform`, else company name and public logo URL; name only when the logo is private); `CompanyMailChannel` passes `brand` and sets the From name to the ISP on the platform mailer. Published views: NEW `resources/views/vendor/notifications/email.blade.php`, `resources/views/vendor/mail/html/message.blade.php`, `resources/views/vendor/mail/text/message.blade.php`. `TicketAssignedNotification::toMail`: subject "Tiket {code} ditugaskan kepada Anda", lines title, customer, priority, response deadline, action "Buka tiket". `.env.example` and `.env.docker.example`: `APP_NAME=Inbils`, `MAIL_FROM_NAME="${APP_NAME}"`, `MAIL_FROM_ADDRESS=no-reply@inbils.local`; `config/mail.php` defaults the same. `ReadinessChecker` adds `mail`: fails in production when the from address ends `example.com` or `.local`. F4-U14: after set-password a customer (holds `portal.access`) lands on login with status "Kata sandi tersimpan. Masuk lewat aplikasi Inbils." plus "Buka aplikasi Inbils" (`SettingService::playStoreUrl('customer')` or the Android intent for `id.inbils.customer`) and the email prefilled.

### 4.9 Audit text (W3-AUDIT)

`AuditEventText::describe(string $logName, string $description, array $properties): string` and `AuditEventText::subjects(Collection $rows): array<int, string>` (Q6), modeled on `app/Support/SupportActionText.php`. Used by `AuditLogResource` (`text`, `subject_label`), `AuditLogQuery` search (the dictionary's reverse map turns Indonesian words into codes), `ReportExporter` audit rows, `DashboardAdminStatsQuery` recent activity (`text`); `activityText.ts` is deleted. Unknown pairs fall back to "{log name}: {description}" with underscores as spaces. One timezone: the company's.

### 4.10 Reports (W3-REPORTS)

- K32: `table.blade.php` header (logo, company, report title, period, filters, "Dicetak {tanggal} oleh {nama}"), typed columns (money "Rp 1.250.000", dates "18 Okt 2026", durations "2 j 15 m"), page numbers; `ExportQuery::streamPdf` enables DomPDF `isFontSubsettingEnabled` (no `config/dompdf.php` exists); CSV stays one header row.
- F9-D02: `ReportExporter` labels for movement types, units, statuses, technician names, durations.
- F9-D04, F9-U02: Technician page lists all technicians when none is picked (Q7), units on cards.
- F6-U16: SLA breakdowns per technician, priority, source; top violators; CSAT (Q1).
- F9-D06 visible link text; F9-D07 `DrillList.php:102` labels; F9-D05 `Sales.tsx:99`, `Consolidated.tsx:88`.
- F10-U02: `ConsolidatedReportRequest` flashes `parent {id, name}`; Reports index shows "Pindah ke {induk}" posting to the existing company switch.
- K33 part: Kartu Stok empty start lists the last 8 moved products (one query).

### 4.11 Network (W3-NETWORK)

- F7-D20, F7-D23: `RadiusDirectory` computes ages in the RADIUS DB (Q8); returns `last_seen_at` and `stale` (> 15 min).
- F7-U25: disconnect stores `last_disconnect_at`; the resource adds it; the page polls with Inertia `usePoll(5000)` while waiting.
- F7-U21: `NasDeviceResource` adds `radius_state` (`not_synced`, `awaiting_restart`, `live`) from the last accounting packet of the NAS against `radius_synced_at`, plus `last_accounting_at`; "Uji koneksi" reloads the prop. No RADIUS client library (OD5).
- F7-D03, F7-U04: POST `nas-devices/{nasDevice}/sync` (`network_asset.nas.manage`), audit `sync_requested`, flash "Sinkronisasi ke RADIUS dijadwalkan."
- F2-U08: NAS create accepts `new_asset {name, management_ip}` to create the router asset in the same transaction (`StoreNasDeviceRequest`).
- F7-U02: the publish confirm shows the recipient count (`CustomerAudienceQuery`); the editor gets "Simpan & Terbitkan".
- F2-U07, F2-U09, F7-U27 are page changes (§5.1).

### 4.12 Platform and tenant (W3-PLATFORM, security)

- F11-D01: the middleware stores `intended` for GET only; for POST the controller returns to the referring GET page.
- F11-D05: `ResolveActiveCompany` flashes after `invalidate()` and `regenerateToken()`.
- F11-D06: dialog text by session driver (database: "Semua sesi pengguna diakhiri."; other drivers: "Sesi berakhir saat pengguna memuat halaman berikutnya.").
- F11-D09: `AuditIndexRequest` errors next to the filters; page clamped to the last page.
- F11-D11, F11-U08: one row per action (`PlatformAuditQuery` groups by `batch_uuid`), default source "Platform".
- F11-U03: skip the password field when confirmed within `PlatformSession` PASSWORD_CONFIRM_SECONDS (300); prop `passwordConfirmedUntil`; copy "Terkonfirmasi sampai {jam}".
- F11-U04: download link in the company Exports section (existing platform download route).
- F11-U05: `lang/id/auth.php` throttle "Terlalu banyak percobaan. Coba lagi dalam :minutes menit." (all 8 callers already pass `minutes`).
- F11-U06: suspend dialog lists users, active sessions, app tokens, open invoices (`TenantDetailQuery`).
- F1-U02: code suggestion from the name + GET `platform/companies/code-check?code=` (NEW invokable `CompanyCodeCheckController`) answering `{available: bool}`.
- K34: POST `admin/company/subsidiary-requests` (`admin.company.subsidiary-requests.store`, `company.manage`, head company only, one pending at a time), NEW controller + request (`name` 3..100, `code` suggested, `note` ≤500); platform audit row; on-demand mail NEW `SubsidiaryRequestedMail` to active platform admins; the platform company page and dashboard list pending requests with "Buat anak perusahaan" (the existing action closes the request) and "Tolak" (reason, mail to the requester).
- W3-PLATFORM also registers, in `routes/admin.php`, POST `admin/users/{user}/invitation` → `UserController::resendInvitation` (`admin.users.invitation.resend`) for W3-TEAMDOCS.
- F12-D05 contributor: API test of the suspended-tenant login (§6).

### 4.13 Catalog (W3-CATALOG)

- F2-U01: `BandwidthProfile`, `SpeedProfile` and `SLATier` store endpoints answer 201 `{data: {id, label}}` only to a request with the header `X-Inline-Create` (the "+ Baru" dialog; `AnswersInlineCreate`), never to `Accept: application/json` alone, which keeps the redirect; package form "+ Baru" dialogs select the new row; the speed profile prefills from the chosen bandwidth profile.
- F2-U02 package code suggestion (client); F3-U03 organisation code suggestion; vehicle type picker (Motor, Mobil, Pikap; a stored other value stays allowed on edit).
- F2-U04 `LatLngMapPicker` on locations; F2-U05 `LocationService` allows a site under POP or Area; F2-U06 table tab when no location has coordinates, plus "{n} lokasi belum punya koordinat"; F2-U10 `DataTableActions` everywhere.
- K12, F6-D19, F8-D13, F10-D04 per §3.6; the category page shows a hint preview "Pelanggan melihat:".

### 4.14 Team and documents (W3-TEAMDOCS, security)

- K26: Customers Show tab "Dokumen" (upload, view, delete, expiry); upload gate → customer `update` policy; `DocumentController::uploadMedia` mimes `pdf,jpg,jpeg,png`, max 10240 KB; menu "Jenis Dokumen"; NEW command `customers:document-expiry` daily in `routes/console.php`, bell `customer_document_expiring` to `customer.update` holders via `StaffRecipients::holding()` (Q9).
- F3-U01: the user form gets an optional employee part (`StoreUserRequest` `employee.*`); `UserProfileService` creates the profile.
- F3-U02: no typed password; NEW `StaffAccountInvitation` (company brand, password-broker link "Atur kata sandi"); "Kirim ulang undangan" on Users Show (route from W3-PLATFORM).
- F3-U05: `/app-only` shows the store link when set, else "Minta tautan aplikasi ke admin ISP Anda."

### 4.15 Dashboard (W3-DASH)

`DashboardQuery` finance card "Jatuh tempo ≤ 3 hari" (Q4, links `invoices?due_within=3`); approvals card grey line "{n} SPK menunggu admin (Anda pembuatnya)"; `OnboardingChecklistQuery` adds `prefixes` ("Tiket TKT, Tagihan INV, SPK SPK").

### 4.16 Shell (W3-SHELL)

`HandleInertiaRequests` `companyHierarchy` adds `parent {id, name, can_switch}`; `SearchOptions` returns the 10 most recent when the term is empty and `recent=1`; `QueueUserOption` gets `load`, loses `email`; the rest is UI (§5.1).

## 5. UI contract

All copy Indonesian, no em dash, Lucide icons, dark mode, 44 px targets on web, 48 dp in apps.

### 5.1 Web

| Page or component | Change and copy |
|---|---|
| `lib/nativeValidity.ts` + `app.tsx` | capture `invalid` on `document`: "Wajib diisi.", "Format tidak sesuai.", "Nilai paling besar {max}.", "Nilai paling kecil {min}.", "Paling sedikit {n} karakter." |
| `NativeSelect`, `Input`, `Checkbox`, `FileUpload`, `Textarea`, `radio-group` | `useId()` when no `id`; labels stay linked |
| `RemoteSearchSelect`, `SearchSelect` | 10 recent on open under "Terakhir dipakai"; `preserveUrl: true`; scroll to the selected value |
| `Toast.tsx`, `ImpersonationBanner.tsx` | toaster offset by the banner height; never covers banner buttons |
| `AdminLayout` | skip link "Langsung ke konten"; ribbon "Sedang di anak perusahaan {nama}" + "Kembali ke induk" |
| `CompanySwitcher` | disabled + `aria-busy` while switching |
| statuses | SPK rejected "Dikembalikan"; invoice partial "Dibayar sebagian" |
| Tickets Show | "Buka kembali" with dialog "Buka kembali tiket {code}?" and reason; "Tugaskan ke saya"; handler options "{nama} · {n} pekerjaan aktif", suggestion "Paling sedikit pekerjaan"; "Penilaian pelanggan: 4 dari 5"; close dialog "Tutup tiket {code}? Pelanggan tidak bisa membalas lagi; tiket masuk laporan." with button "Tutup tiket"; no dropzone when closed; "Lampiran" without asterisk; caption "Keterangan (opsional)"; initial focus on the title |
| Tickets Create | lone subscription preselected; title from the category until edited; line "Target respons {jam}, selesai {jam}" from sla-preview |
| SPK Edit (NEW), SPK Show, AssignDialog | "Ubah" on draft and generated; "Tanggal kunjungan" |
| Customers Create | "Sama dengan data pelanggan"; street from the geocoder; ODP options "{ODP} · {free}/{total} port · {jarak}"; block "Tugaskan sekarang" (technician, date) |
| `CustomerRelatedTables` | subscription code links + "Kelola langganan" |
| Invoices Show | "Unduh kuitansi" (paid); "Riwayat notifikasi Midtrans"; "Batalkan di Midtrans" with confirm; reason and method labels; "Ubah" on drafts |
| Invoices Create, Edit (NEW), Index | items in one form; due quick picks "7 hari", "14 hari", "Akhir bulan"; "Lewat jatuh tempo" for sent or partial past due |
| Receivables | tabs "Per tagihan", "Per pelanggan"; search, age bucket, due range, "Ekspor", "Kirim pengingat" |
| Portal Payments Finished | "Pembayaran berhasil", "Menunggu pembayaran", "Pembayaran tidak berhasil. Coba lagi dari aplikasi."; "Buka aplikasi Inbils" |
| Inventory Requests Show, Index | "Menunggu persetujuan Manajer/Admin"; "Siapkan barang"; "Serah terima", "Diserahkan"; "Pengembalian material"; terminal step "Ditolak" or "Dibatalkan"; labelled declare dialog; titles never wrap |
| ReviewSheet, MobileItemCard | materials listed per unit |
| Stocks, Movements | "Pindahkan stok" (Dari, Ke, Barang, Jumlah); receive "Pemasok", "No. faktur", "Harga satuan"; adjust "Stok sistem: {n}"; columns "Oleh", "Saldo setelah" |
| Audit log, dashboard activity, platform ActivitySections | server text |
| Reports | headers, labels, technician ranking, SLA breakdowns, "Kepuasan pelanggan 4,3 dari 5 ({n} penilaian)", "Pindah ke {induk}", Kartu Stok "Barang terakhir bergerak" |
| SLA tiers form | "Hitungan tenggat": "24 jam" or "Jam layanan"; percent via `formatPercent` |
| Company Settings | "Jarak check-in maksimal (meter)"; "Jam layanan" per day with "Tutup"; warning "Jam layanan belum diisi; tenggat dihitung 24 jam." |
| `checkIn.ts` | "119,3 km" from 1000 m; "Jauh dari lokasi: {alasan}" |
| Network pages | "Sinkronkan ke RADIUS"; "Menunggu FreeRADIUS dimulai ulang", "Aktif, paket terakhir {jam}", "Uji koneksi"; "Terakhir terlihat {jam}" + "Data sesi lebih dari 15 menit"; "Sesi diputus {jam}, menunggu router menyambung ulang"; ODN empty "Daftarkan ODP"; NAS "Router belum terdaftar? Buat aset router di sini"; searchable asset type; "IP manajemen" with a hint; lone address preselected; "Langganan ini belum punya POP." |
| Announcements | "Terbitkan untuk {n} pelanggan?"; "Simpan & Terbitkan" |
| Platform | code suggestion + "Kode sudah dipakai"; "Terkonfirmasi sampai {jam}"; suspend impact; Exports "Unduh"; audit filter errors |
| Company Profile | "Minta anak perusahaan" (Nama, Kode, Catatan); pending "Permintaan {nama} sedang ditinjau operator." |
| Catalog pages | "+ Baru" dialogs; map picker; vehicle type; hint preview |
| Customers Show "Dokumen" | empty "Belum ada dokumen. Unggah KTP atau kontrak pelanggan."; "Berlaku sampai {tgl}"; badge "Segera habis" |
| Users Create, Show | employee part; "Kirim undangan"; "Kirim ulang undangan" |
| AppOnly | store link or "Minta tautan aplikasi ke admin ISP Anda." |
| Dashboard | "Jatuh tempo ≤ 3 hari"; maker line; checklist prefixes |
| Login | status after set-password + "Buka aplikasi Inbils" |

### 5.2 Customer app (M3-CUST)

- Resolved ticket card "Masalah sudah beres?": "Sudah beres" opens the sheet "Beri nilai" (5 stars, "Ceritakan pengalaman Anda (opsional)", "Kirim nilai"); success "Terima kasih. Tiket ditutup."; "Masih bermasalah" focuses the reply box (a reply reopens). Closed and unrated: "Beri nilai".
- "Lapor masalah" everywhere; duplicate card "Anda sudah punya tiket aktif {code} ({status}). Lihat tiket / Tetap buat baru."; outage card in Bantuan "Ada gangguan di area Anda sejak {jam}. Perkiraan pulih {jam}." with "Ikuti pembaruan".
- One reply box with a clip icon; attachments in the timeline with sender and caption; photo full screen; PDF opens via download + `share_plus`; the box scrolls above the keyboard; after creating a ticket, back goes to the list.
- Invoice: "Hubungi ISP" opens `showContactSheet`; "Menyiapkan pembayaran" screen (amount, due date, progress) before Snap.
- Relative deadline "Dijawab sebelum 08.30 besok".
- R6 arb: Terbuka, Ditugaskan, Dikerjakan, Diselesaikan, Ditutup.
- `inbils-flutter-ui` baseline: `BorderRadius.circular(8)` → `InbilsRadius.sm` at `ticket_detail_screen.dart:795,919` and `new_ticket_screen.dart:412`; `page_dots.dart:39` `circular(4)` → `InbilsRadius.sm`; `company_logo.dart:26` `fontSize: size * 0.4` kept with a one-line reason (the letter scales with the logo box); photo viewer `Colors.black` (`ticket_detail_screen.dart:944`) stays.

### 5.3 Technician app

- M3-TECH-OPS: Drift outbox (schema v4) for en route, start, submit, ticket start, resolve, comment, material return declaration, inventory request; FIFO per target; the same `Idempotency-Key` for an identical retry, a new key when the payload changes; 4xx other than 408 and 429 → "Gagal dikirim: {alasan}" with "Coba lagi" and "Buang"; marker "Menunggu kirim"; one banner "Anda sedang luring. Aksi disimpan dan dikirim otomatis saat online." + "Coba lagi"; K10 dialog "Jauh dari lokasi pelanggan" with an "Alasan" field, resent with `far_reason`; SPK start snackbar like the ticket one.
- M3-TECH-UI: K35 returned SPK "Dikembalikan ke kantor. Menunggu penugasan ulang." + "Telepon kantor", "WhatsApp kantor" (`/me` company phone and WhatsApp); F4-U08 evidence and materials above customer and POP while in progress + chip "Foto {n}/{min}"; F4-U09 photos queue at once, caption optional on the card, "Ambil lagi"; ticket header with site address, priority chip, response countdown, WhatsApp; product picker grouped by category with "Stok {n} {satuan}" and a warning above stock; default warehouse = last used when it has stock, else the one with most `available_products`; return screen prefilled from `recorded_used_qty` ("Dari catatan SPK"); PDF open via `share_plus`; R6 arb (Terbuka, Diselesaikan, Diserahkan; SPK and ticket "done" keys split).
- `inbils-flutter-ui` baseline: `evidence_thumbnail.dart:52` → `InbilsRadius.sm`; `:88` white icon on the fixed black viewer kept with a one-line reason (fixed dark stage in both themes); `network_credentials_card.dart:458` → `base?.copyWith(fontFamily: 'monospace', fontWeight: FontWeight.w600, letterSpacing: 0.2)` without `fontSize:`.

### 5.4 Core (M3-CORE)

`InbilsMotion.reduced` = `disableAnimations || accessibleNavigation`; the notifications list keeps its scroll (`PageStorageKey`); the ISP name moves from the heads-up title to Android `subText`; empty title and body → no local notification; login shows the suspended message under the email field; NEW `formatDueRelative(DateTime due, {DateTime? now})` in `format/formatters.dart` ("21.17", "08.30 besok", "Sen 08.30", "18 Okt 08.30", past "terlewat"); baseline: `screens/auth_layout.dart:106` raw `Card` → `InbilsCard(elevation: 3)` after adding an optional `elevation` (default unchanged) to `widgets/inbils_card.dart`, with a widget test.

## 6. Test plan

Commands: `./vendor/bin/sail artisan test --filter=<Class>`; `./vendor/bin/sail bin pint --dirty --test`; `./vendor/bin/sail composer analyse`; `npm run typecheck && npm run lint && npm run format:check`; `node resources/js/lib/statuses.selfcheck.mjs`; storage chown before E2E: `./vendor/bin/sail exec -T laravel.test sh -c 'find /var/www/html/storage -user root -exec chown 1000:1000 {} +'`; E2E `flock /tmp/inbils-e2e.lock sh -c 'npm run build && node scripts/e2e-setup.mjs && npm run test:e2e -- <specs> --project=chromium --workers=1'`; Flutter per package `flock /tmp/inbils-build.lock sh -c 'dart run build_runner build --delete-conflicting-outputs && dart format --set-exit-if-changed . && flutter analyze && flutter test'` with `uptime` load < 6.

### 6.1 Per slice

| Slice | PHPUnit (NEW unless "extend") | E2E / Flutter |
|---|---|---|
| W3-SEC | `tests/Feature/SPK/WorkOrderPayloadPrivacyTest.php` (`test_index_and_show_embed_customer_summary_only`, `test_evidence_only_on_show`); `tests/Feature/Billing/InvoicePayloadPrivacyTest.php`; `tests/Feature/NetworkAsset/NetworkAssetPayloadPrivacyTest.php`; `tests/Feature/Admin/SubscriptionPayloadPrivacyTest.php` (exact key sets, no email, phone or address) | typecheck |
| W3-NOTIFY | `tests/Feature/Push/NotificationTextTest.php` (invoice, ticket, reopened, document; old rows fall back); extend `tests/Feature/Admin/CompanyMailChannelTest.php` (`test_brand_and_from_name`, `test_platform_mail_keeps_inbils`); `tests/Feature/Operations/ReadinessMailTest.php`; `tests/Feature/Auth/CustomerPasswordSetHandoffTest.php`; extend `tests/Feature/Ticketing/TicketAssignmentNotificationTest.php` (branded mail) | none |
| W3-SHELL | `tests/Feature/Admin/SearchOptionsRecentTest.php`; extend `tests/Feature/Admin/SuggestionPropsTest.php` (parent prop) | `ui-regressions.spec.ts` (Indonesian bubble, skip link, toaster vs banner, picker URL), `head-company.spec.ts` (ribbon, switcher busy), `ui-pickers.spec.ts`, `billing-lifecycle.spec.ts`, `integrity-hardening.spec.ts` (labels); self-check |
| W3-TICKET | `tests/Feature/Ticketing/TicketStaffReopenTest.php` (permission, status, reason, notify, closed_at cleared, rating kept); `tests/Feature/Api/V1/Customer/TicketConfirmationTest.php` (resolved closes, closed rates, 409, 422, idempotent, other customer 404); `tests/Feature/Ticketing/TicketSlaPreviewTest.php`; `tests/Feature/Api/V1/Technician/TicketSiteTest.php`; `tests/Feature/Ticketing/TicketAttachmentRulesTest.php`; `tests/Feature/Ticketing/TicketReopenReleaseTest.php` (M-7 up and down); extend `TicketPayloadPrivacyTest`, `tests/Feature/Admin/EvaluationPrefillTest.php`, `tests/Feature/Admin/DispatchQueueTest.php` (load, no N+1) | `ticket-lifecycle.spec.ts`, `flow-audit-w1-tickets.spec.ts` |
| W3-SPK | `tests/Feature/SPK/WorkOrderScheduleTest.php`; `tests/Feature/Admin/CustomerOnboardingAssignTest.php`; `tests/Feature/SPK/WorkOrderEditPageTest.php`; `tests/Feature/NetworkAsset/OdpOptionsTest.php` | `spk-lifecycle.spec.ts`, `dispatch-my-work.spec.ts` |
| W3-PAY | `tests/Feature/Billing/ReceiptPdfTest.php`; `tests/Feature/Billing/MidtransCallbackTrailTest.php`; `tests/Feature/Billing/PaymentFinishPageTest.php` (no order id echo); `tests/Feature/Billing/PaymentAttemptCancelTest.php` | `midtrans-payment.spec.ts` |
| W3-INVREQ | `tests/Feature/Inventory/ShortfallMessageTest.php`; `tests/Feature/Inventory/MaterialReturnBookingTest.php`; `tests/Feature/Inventory/RequestNumberYearlyTest.php` (M-4); `tests/Feature/Api/V1/Technician/MaterialsPickerTest.php`; extend `tests/Feature/NumberSequenceTest.php`, `tests/Feature/Admin/ApprovalInboxInventoryTest.php` | `inventory-lifecycle.spec.ts`, `inventory-returns.spec.ts` |
| W3-AUDIT | `tests/Feature/Reporting/AuditEventTextTest.php` (every §3.3 event, bulk labels, fallback, search); extend `tests/Feature/Reporting/AuditLogSupportTextTest.php` | `reports-audit-log.spec.ts`, `dashboard-persona.spec.ts` |
| W3-FIELD | `tests/Unit/ServiceHoursTest.php`; `tests/Feature/Ticketing/BusinessHoursDeadlineTest.php`; `tests/Feature/SPK/CheckInDistanceTest.php` (far with and without reason, en route free, setting); extend `tests/Feature/Ticketing/TicketDeadlinesTest.php` | `sla-targets.spec.ts`, `company-settings.spec.ts` |
| W3-INVOICE | `tests/Feature/Billing/InvoiceItemsFormTest.php`; `tests/Feature/Billing/InvoiceDraftEditTest.php`; `tests/Feature/Billing/ReceivableInvoicesTest.php`; `tests/Feature/Billing/InvoiceManualReminderTest.php` (once a day, audit, permission); extend `tests/Feature/Billing/ReceivablesTest.php` | `flow-audit-w1-billing.spec.ts` |
| W3-STOCK | `tests/Feature/Inventory/StockReceiveDetailsTest.php`; `tests/Feature/Inventory/StockMovementListTest.php` (actor, balance, no N+1) | `stock-asset.spec.ts` |
| W3-REPORTS | `tests/Feature/Reporting/ExportTemplateTest.php`; `tests/Feature/Reporting/ExportLabelsTest.php`; `tests/Feature/Reporting/SlaBreakdownTest.php` (with CSAT); `tests/Feature/Reporting/TechnicianRankingTest.php` (fixed query count); `tests/Feature/Reporting/ConsolidatedSwitchHintTest.php` | `reporting-export.spec.ts`, `technician-performance.spec.ts`, `reporting-stock-card.spec.ts` |
| W3-NETWORK | `tests/Feature/NetworkAsset/RadiusClockTest.php`; `tests/Feature/NetworkAsset/NasSyncActionTest.php`; `tests/Feature/NetworkAsset/NasRadiusStateTest.php`; `tests/Feature/NetworkAsset/NasCreateRouterTest.php`; `tests/Feature/Service/AnnouncementAudienceCountTest.php` | `radius-provisioning.spec.ts`, `announcements.spec.ts`, `ipam-odn.spec.ts` |
| W3-PLATFORM | `tests/Feature/Platform/PasswordConfirmIntendedTest.php`; `tests/Feature/Platform/AuditFiltersTest.php`; `tests/Feature/Platform/SubsidiaryRequestTest.php`; `tests/Feature/Platform/CompanyCodeCheckTest.php`; `tests/Feature/Platform/SuspendImpactTest.php`; `tests/Feature/Api/V1/Shared/SuspendedTenantLoginTest.php` (422 `errors.email`) | `platform.spec.ts`, `impersonation.spec.ts` |
| W3-CATALOG | `tests/Feature/Service/InlineCreateJsonTest.php`; extend `tests/Feature/Admin/LocationContractTest.php` (site under POP), `tests/Feature/Platform/TenantStarterCatalogTest.php` (names, hints); `tests/Feature/Ticketing/LocaliseCategoriesMigrationTest.php` (M-2); `tests/Feature/DatabaseSeederEnvironmentTest.php` | `customer-subscription.spec.ts`, `kanban-t_49387795.spec.ts`, `number-sequences.spec.ts`, `private-media.spec.ts`, `sla-targets.spec.ts`, `whatsapp-notifications.spec.ts`, `package-categories.spec.ts`, `location-map-first.spec.ts`, `ui-pickers.spec.ts`, `ticket-lifecycle.spec.ts` |
| W3-TEAMDOCS | extend `tests/Feature/DocumentTest.php`; `tests/Feature/Admin/CustomerDocumentsTabTest.php`; `tests/Feature/Admin/DocumentExpiryReminderTest.php`; extend `tests/Feature/Admin/UserFormTest.php` (employee part, invitation) | `admin-vehicles-documents.spec.ts`, `app-only.spec.ts` |
| W3-DASH | extend `tests/Feature/Admin/DashboardPersonaTest.php`, `tests/Feature/Admin/OnboardingChecklistTest.php` | `dashboard-persona.spec.ts` |
| M3-CORE | `inbils_core` tests: reduced motion, push guard and subText, login suspended message, relative formatter, scroll keep, `InbilsCard` elevation | `flutter test` core |
| M3-TECH-OPS | outbox replay (same key, FIFO, failure), banner, far dialog | `flutter test` technician |
| M3-CUST | confirm card, duplicate card, outage card, reply box, PDF, contact, preparing screen, arb | extend `customer/test/tickets_test.dart`, `customer/test/invoices_payments_test.dart` |
| M3-TECH-UI | returned SPK, layout chip, multi-shot, grouped picker, warehouse default, prefill, header | `flutter test` technician |
| W3-DOCS | `./vendor/bin/sail artisan scramble:export`; `git diff --stat docs/api/mobile-v1.openapi.json` shows additive fields only | |

Every Flutter slice also proves `inbils-flutter-ui` §7: contrast on seeds `#2563EB`, `#FFE45C`, `#0B2A6F` in light and dark, 200 % text at 360 dp with a 280 dp keyboard inset, reduced motion, 48 dp targets, every data state; and re-runs the §5 audit greps (each hit fixed or carrying a stated reason).

### 6.2 Gates (Main)

Per web batch: `./vendor/bin/sail artisan migrate --pretend` matches §3.1 (A: M-1, M-7; B: M-4; C: M-3, M-5; D: M-2, M-6); slice filters; pint; analyse; JS checks; the listed E2E specs; `security-reviewer` on W3-SEC, W3-NOTIFY, W3-TICKET, W3-PAY, W3-INVOICE, W3-FIELD, W3-PLATFORM, W3-TEAMDOCS and M3-TECH-OPS, with `composer audit` and `npm audit` reported in full; EXPLAIN pasted for every Q1-Q9 touched in the batch. Mobile batches: package checks under the build lock; AVD screenshots light and dark (MemAvailable ≥ 4 GB, load < 6, `-memory 2048`, no build running). Wave end: full PHPUnit, full E2E, OpenAPI diff, ledger.

## 7. Risks and open decisions

Open decisions (the default applies unless the owner says otherwise):
- OD1 `ticket.reopen` to templates `manager` and `noc` (M-7).
- OD2 the rating survives a reopen; no staff notification for ratings.
- OD3 F6-U11 without distance (no technician position is stored): load, suggestion, "Tugaskan ke saya".
- OD4 the finish page trusts `transaction_status` for display only.
- OD5 F7-U21 checks the last accounting packet instead of RADIUS Status-Server.
- OD6 mail from address default `no-reply@inbils.local`; production must set a real domain (readiness fails until then).
- OD7 offline actions carry the device capture time in the payload; server timestamps stay arrival time.
- OD8 K11 applies to tiers only; categories stay 24h.
- OD9 the free-text `support_hours` stays for customers; Settings hints to keep it equal to `service_hours`.
- OD10 the technician app adds `share_plus: ^13.3.1` (as the customer app).
- OD11 the dev DB keeps old names and `E2E-SUB`; the owner renames or removes them by hand.
- OD12 subsidiary requests live as platform audit rows, not a table.
- OD13 "Bayar tanpa login" stays parked.
- OD14 manual reminder at most once per invoice per day.
- OD15 staff accounts by invitation only; the typed password goes.

Risks:
- tenant-roles must land first: touched files still hold role checks today (`TicketController.php:141,163,225`, `TechnicianPerformanceQuery.php:29,46`, `StoreUserRequest.php:32`, `AppOnlyController.php:19`, `Reports/AuditLog.tsx`). `DispatchQueueQuery` uses `whereHas('roles.permissions')`; W3-TICKET adapts to the tenant-roles helper.
- Mobile M1 starts only after wave-2 batch C is merged (same files).
- Label changes break text selectors; owners update the specs listed in §8.
- Offline replay can meet office changes (409); the outbox shows the reason and never drops silently.
- Published mail views must match the Laravel version in `composer.lock`.
- Private company logos cannot appear in mail; the name is used.
- `enum('24h','business')`: a new value must be appended later (INPLACE).
- Old app versions keep working: every API field is additive; the confirmation route is new.
- Q3 and Q9 carry `shortcut:` comments with the upgrade path.
- The technician photo viewer keeps `Colors.white` on its fixed black stage with a stated reason (skill §5 allows it); Main may prefer a themed icon colour instead.

## 8. Work breakdown

### 8.1 Batches, order, skills

| Batch | Slices | Depends on |
|---|---|---|
| A | W3-SEC, W3-NOTIFY, W3-SHELL, W3-TICKET | tenant-roles, wave 2 |
| B | W3-SPK, W3-PAY, W3-INVREQ, W3-AUDIT | A |
| C | W3-FIELD, W3-INVOICE, W3-STOCK, W3-REPORTS | B |
| D | W3-NETWORK, W3-PLATFORM, W3-CATALOG, W3-TEAMDOCS | C |
| E | W3-DASH | D (W3-INVOICE filter, W3-INVREQ count, W3-AUDIT text) |
| M1 | M3-CORE, M3-TECH-OPS, M3-CUST | web A-C deployed to dev; wave-2 C and D merged |
| M2 | M3-TECH-UI | M1 |
| Z | W3-DOCS | all |

Skills lines:
- **Web UI**: `antislop`, `antislop-ui`, `antislop-human`, `antislop-layoutmobile`, `antislop-copywriting`, `ui-ux-pro-max`, `frontend-design`, `redesign-existing-projects`, `design-system`, `tailwindcss-development`, `shadcn`, `ui-styling`, `inertia-react-development`, plus `laravel-best-practices`, `mysql`, `tdd`, `playwright-best-practices`, `typescript-advanced-types`, `ponytail`, `verification-before-completion`; colour pairs through the antislop contrast tool.
- **Backend**: `laravel-best-practices`, `mysql`, `tdd`, `ponytail`.
- **Flutter**: `inbils-flutter-ui` first (`~/.omp/agent/managed-skills/inbils-flutter-ui/SKILL.md`), then `material-3`, `state-management-riverpod`, `flutter-performance`, the antislop family, `ui-ux-pro-max`, `frontend-design`, `design-system`, `flutter-build-responsive-layout`, `flutter-fix-layout-issues`, `flutter-add-widget-test`, `dart-add-unit-test`, `dart-run-static-analysis`, `tdd`, `ponytail`, `verification-before-completion`. Repo wins: `@riverpod` codegen, existing `DateTime.now()`, no `ban-legacy-providers.sh` gate.
- **Security** slices add `web-security-review`. **Docs**: `antislop-copywriting`, `antislop`.
- Every UI slice reports which rule from each skill changed the design. Agents do not run builds or tests mid-flight; Main runs §6.2.

### 8.2 Slices

Owned lists are exhaustive. NEW = the slice creates the file. Generated `*.g.dart`, `*.freezed.dart` and l10n output belong to the owner of the source. Every slice: depends on its batch order (§8.1); acceptance = its §6.1 tests green, the §5 copy visible, every caller of a changed method grepped and still working, no role-name check added, and the report lists the skill rules applied.

**W3-SEC** · backend · SEC-1 · security · Skills: Backend + `web-security-review`
- Owned: `Modules/SPK/app/Http/Resources/WorkOrderResource.php`, `Modules/Billing/app/Http/Resources/InvoiceResource.php`, `Modules/NetworkAsset/app/Http/Resources/NetworkAssetResource.php`, `app/Http/Resources/SubscriptionResource.php`, `app/Http/Resources/CustomerResource.php`, `resources/js/types/spk.d.ts`, `resources/js/types/billing.d.ts`, `resources/js/types/network-asset.d.ts`, `resources/js/types/models.ts`, NEW `tests/Feature/SPK/WorkOrderPayloadPrivacyTest.php`, NEW `tests/Feature/Billing/InvoicePayloadPrivacyTest.php`, NEW `tests/Feature/NetworkAsset/NetworkAssetPayloadPrivacyTest.php`, NEW `tests/Feature/Admin/SubscriptionPayloadPrivacyTest.php`.
- Produces: `CustomerResource::summary()`, `SubscriptionResource::summary()` (§4.1). Acceptance adds: typecheck green with the narrowed types.

**W3-NOTIFY** · backend + one page · F12-D11, F5-U06, F5-D08, F12-U04, F6-U17, F1-D04, F4-U14, F6-D24 · security · Skills: Backend + `web-security-review` (+ Web UI for Login)
- Owned: `app/Http/Resources/NotificationResource.php`, `app/Notifications/WhatsAppNotification.php`, `Modules/Billing/app/Notifications/InvoiceWhatsAppNotification.php`, `Modules/Billing/app/Notifications/InvoiceIssuedNotification.php`, `Modules/Billing/app/Notifications/PaymentReceivedNotification.php`, `Modules/Ticketing/app/Notifications/TicketAssignedNotification.php`, `app/Notifications/CustomerAccountCreated.php`, `app/Notifications/Channels/CompanyMailChannel.php`, `app/Notifications/Platform/TenantAdminInvitation.php`, NEW `app/Support/MailBrand.php`, NEW `resources/views/vendor/notifications/email.blade.php`, NEW `resources/views/vendor/mail/html/message.blade.php`, NEW `resources/views/vendor/mail/text/message.blade.php`, `app/Support/Observability/ReadinessChecker.php`, `app/Http/Controllers/Auth/NewPasswordController.php`, `resources/js/Pages/Auth/Login.tsx`, `.env.example`, `.env.docker.example`, `config/mail.php`, NEW `tests/Feature/Push/NotificationTextTest.php`, `tests/Feature/Admin/CompanyMailChannelTest.php`, NEW `tests/Feature/Operations/ReadinessMailTest.php`, NEW `tests/Feature/Auth/CustomerPasswordSetHandoffTest.php`, `tests/Feature/Ticketing/TicketAssignmentNotificationTest.php`.
- Consumes: W3-TICKET data keys (§3.4). Produces: texts for `customer_document_expiring` and stage `manual`; `MailBrand`.

**W3-SHELL** · frontend · F1-D02, F6-D22, F2-D01, F8-U06, F11-D10, F11-U10, F11-D08, F11-U09, F10-D03, F6-U20, F10-U01, F6-D23 (web) · Skills: Web UI
- Owned: NEW `resources/js/lib/nativeValidity.ts`, `resources/js/app.tsx`, `resources/js/Components/composite/NativeSelect.tsx`, `resources/js/Components/ui/Input.tsx`, `resources/js/Components/ui/Checkbox.tsx`, `resources/js/Components/ui/FileUpload.tsx`, `resources/js/Components/ui/Textarea.tsx`, `resources/js/Components/ui/radio-group.tsx`, `resources/js/Components/composite/RemoteSearchSelect.tsx`, `resources/js/Components/ui/SearchSelect.tsx`, `app/Support/SearchOptions.php`, `resources/js/Components/ui/Toast.tsx`, `resources/js/Components/composite/ImpersonationBanner.tsx`, `resources/js/Components/composite/CompanySwitcher.tsx`, `resources/js/Layouts/AdminLayout.tsx`, `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/types/index.ts` (batch A), `resources/js/lib/statuses/workOrder.ts`, `resources/js/lib/statuses/invoice.ts` (batch A), `resources/js/lib/statuses.selfcheck.mjs`, NEW `tests/Feature/Admin/SearchOptionsRecentTest.php`, `tests/Feature/Admin/SuggestionPropsTest.php`, `tests/e2e/ui-regressions.spec.ts`, `tests/e2e/head-company.spec.ts`, `tests/e2e/ui-pickers.spec.ts` (batch A), `tests/e2e/billing-lifecycle.spec.ts`, `tests/e2e/integrity-hardening.spec.ts`, `tests/e2e/support/demo.ts`.
- Produces: `parent` prop, `recent=1` search mode, `QueueUserOption.load` (no `email`). Acceptance adds: grep shows no E2E selector on a label-derived `#id`.

**W3-TICKET** · fullstack · F6-D02, F6-D09, F6-U11, F6-U14, F6-D14, F6-D15, F6-D21 (+ K14 server, F6-D08 site, F6-U05 attachments, F6-U20 focus) · security · Skills: Web UI + `web-security-review`
- Owned: `Modules/Ticketing/routes/web.php`, `Modules/Ticketing/routes/api.php`, `Modules/Ticketing/app/Http/Controllers/TicketController.php`, `Modules/Ticketing/app/Http/Controllers/Api/V1/Customer/TicketController.php`, `Modules/Ticketing/app/Http/Controllers/Api/V1/TicketAttachmentController.php`, NEW `Modules/Ticketing/app/Http/Requests/ReopenTicketRequest.php`, NEW `Modules/Ticketing/app/Http/Requests/Api/V1/ConfirmCustomerTicketRequest.php`, `Modules/Ticketing/app/Http/Requests/UploadTicketAttachmentRequest.php`, `Modules/Ticketing/app/Http/Requests/Api/V1/StoreTicketAttachmentRequest.php`, `Modules/Ticketing/app/Policies/TicketPolicy.php`, `Modules/Ticketing/app/Services/TicketService.php` (batch A), `Modules/Ticketing/app/Models/Ticket.php`, `Modules/Ticketing/app/Http/Resources/TicketResource.php` (batch A), `Modules/Ticketing/app/Http/Resources/Api/V1/CustomerTicketResource.php`, `Modules/Ticketing/app/Http/Resources/Api/V1/TechnicianTicketResource.php`, `Modules/Ticketing/app/Http/Resources/Api/V1/TicketAttachmentResource.php`, `Modules/Ticketing/app/Notifications/TicketCustomerUpdateNotification.php`, `Modules/Ticketing/app/Notifications/TicketStaffNotification.php`, NEW `Modules/Ticketing/database/migrations/2026_11_02_100100_add_customer_rating_to_tickets_table.php`, NEW `database/migrations/2026_11_02_100700_release_ticket_reopen_to_templates.php`, `app/Http/Controllers/Admin/EvaluationController.php`, `app/Queries/Admin/DispatchQueueQuery.php`, `resources/js/Pages/Admin/Tickets/Show.tsx` (batch A), `resources/js/Pages/Admin/Tickets/Create.tsx`, `resources/js/Pages/Admin/Dispatch/components/AssignDialog.tsx` (batch A), `resources/js/types/ticketing.d.ts`, NEW tests of §6.1 W3-TICKET, `tests/Feature/Ticketing/TicketPayloadPrivacyTest.php`, `tests/Feature/Admin/EvaluationPrefillTest.php`, `tests/Feature/Admin/DispatchQueueTest.php`, `tests/e2e/ticket-lifecycle.spec.ts` (batch A), `tests/e2e/flow-audit-w1-tickets.spec.ts`.
- Consumes: `CustomerResource::summary()`, `SubscriptionResource::summary()` (W3-SEC, same batch, §4.1 signatures); `QueueUserOption.load` (W3-SHELL). Produces: §4.2 routes and fields, M-1 columns, §3.4 ticket data keys.

**W3-SPK** · fullstack · F4-D02, F4-D15, F4-U04, F4-U01, F4-U03, F4-D03, F4-D05, F4-U02 · Skills: Web UI
- Owned: `Modules/SPK/app/Http/Requests/AssignWorkOrderRequest.php`, `Modules/SPK/app/Http/Controllers/WorkOrderController.php` (batch B), `Modules/SPK/app/Services/SpkService.php` (batch B), `app/Http/Controllers/Admin/DispatchQueueController.php`, `app/Http/Requests/Admin/StoreCustomerRequest.php`, `app/Actions/CreateCustomerOnboardingAction.php`, `app/Http/Controllers/Admin/CustomerController.php` (batch B), `app/Http/Controllers/Admin/CustomerAccessLinkController.php`, `app/Services/Core/OpenStreetMapGeocoder.php`, `Modules/NetworkAsset/app/Queries/OdnOccupancyQuery.php`, `resources/js/Pages/Admin/Customers/Create.tsx`, NEW `resources/js/Pages/Admin/SPK/Edit.tsx`, `resources/js/Pages/Admin/SPK/Show.tsx` (batch B), `resources/js/Pages/Admin/Dispatch/components/AssignDialog.tsx` (batch B), `resources/js/Components/composite/CustomerRelatedTables.tsx`, `resources/js/Pages/Admin/Subscriptions/Show.tsx`, NEW tests of §6.1 W3-SPK, `tests/e2e/spk-lifecycle.spec.ts`, `tests/e2e/dispatch-my-work.spec.ts`.
- Produces: `SpkService::assign(..., ?string $scheduledDate = null)`; ODP option fields.

**W3-PAY** · fullstack · F5-D09, F5-D15, F5-U16, F5-U11, F5-U15, F5-D11, F5-D17, F5-D21 · security · Skills: Web UI + `web-security-review`
- Owned: NEW `resources/views/pdf/receipt.blade.php`, `Modules/Billing/app/Http/Controllers/Api/V1/Customer/InvoiceReceiptController.php`, `Modules/Billing/app/Http/Controllers/InvoiceController.php` (batch B), `Modules/Billing/routes/web.php` (batch B), NEW `Modules/Billing/app/Http/Controllers/Portal/PaymentFinishController.php`, `resources/js/Pages/Portal/Payments/Finished.tsx`, `Modules/Billing/app/Http/Controllers/MidtransWebhookController.php`, `Modules/Billing/app/Services/PaymentAttemptService.php`, `Modules/Billing/app/Providers/RouteServiceProvider.php`, `Modules/Billing/app/Http/Resources/PaymentAttemptResource.php`, `Modules/Billing/app/Http/Controllers/PaymentAttemptController.php`, NEW `Modules/Billing/app/Http/Requests/CancelPaymentAttemptRequest.php`, `resources/js/Pages/Admin/Billing/Invoices/Show.tsx` (batch B), NEW tests of §6.1 W3-PAY, `tests/e2e/midtrans-payment.spec.ts`.

**W3-INVREQ** · fullstack · F8-D06, F4-U12, F8-U04, F4-D09, F4-D12, F8-D07, F8-D08, F8-D09, F8-D10, F8-D11, F4-U13 · Skills: Web UI
- Owned: `Modules/Inventory/app/Services/InventoryRequestService.php`, `Modules/Inventory/app/Services/MaterialReturnService.php`, `Modules/Inventory/app/Exceptions/InsufficientStockException.php`, `Modules/Inventory/app/Providers/InventoryServiceProvider.php`, NEW `Modules/Inventory/database/migrations/2026_11_02_100400_move_inventory_request_numbers_to_yearly.php`, `Modules/Inventory/app/Http/Controllers/InventoryRequestController.php`, `Modules/Inventory/app/Http/Resources/InventoryRequestResource.php`, `Modules/Inventory/app/Http/Resources/MaterialReturnLineResource.php`, `Modules/Inventory/app/Http/Resources/Api/V1/TechnicianProductResource.php`, `Modules/Inventory/app/Http/Resources/Api/V1/TechnicianWarehouseResource.php`, `Modules/Inventory/app/Http/Resources/Api/V1/TechnicianMaterialReturnResource.php`, `Modules/Inventory/app/Http/Controllers/Api/V1/Technician/WarehouseController.php`, `app/Http/Resources/ApprovalInboxItemResource.php`, `resources/js/Pages/Admin/Approvals/components/ReviewSheet.tsx`, `resources/js/Pages/Admin/Approvals/components/MobileItemCard.tsx`, `resources/js/types/index.ts` (batch B), `resources/js/types/inventory.ts` (batch B), `resources/js/Pages/Admin/Inventory/Requests/Show.tsx`, `resources/js/Pages/Admin/Inventory/Requests/Index.tsx`, `tests/Feature/NumberSequenceTest.php`, `tests/Feature/Admin/ApprovalInboxInventoryTest.php`, NEW tests of §6.1 W3-INVREQ, `tests/e2e/inventory-lifecycle.spec.ts`, `tests/e2e/inventory-returns.spec.ts`.
- Produces: technician API fields `category`, `available_products`, `recorded_used_qty`; the actionable count reused by `DashboardQuery`.

**W3-AUDIT** · fullstack · F1-D03, F4-D14, F9-D08, F9-U03 · Skills: Web UI
- Owned: NEW `app/Support/AuditEventText.php`, `Modules/Reporting/app/Http/Resources/AuditLogResource.php`, `Modules/Reporting/app/Queries/AuditLogQuery.php`, `Modules/Reporting/app/Exports/ReportExporter.php` (batch B), `app/Queries/Admin/DashboardAdminStatsQuery.php`, `resources/js/Pages/Admin/Dashboard/components/AdminOverview.tsx`, `resources/js/Pages/Admin/Dashboard/components/activityText.ts` (DELETE), `resources/js/types/dashboard.ts` (batch B), `resources/js/Pages/Admin/Reports/AuditLog.tsx`, NEW `tests/Feature/Reporting/AuditEventTextTest.php`, `tests/Feature/Reporting/AuditLogSupportTextTest.php`, `tests/e2e/reports-audit-log.spec.ts`, `tests/e2e/dashboard-persona.spec.ts` (batch B).
- Produces: `AuditEventText` covering every §3.3 event.

**W3-FIELD** · fullstack · F6-D07 (K10), F6-D20 (K11) · security · Skills: Web UI + `web-security-review`
- Owned: `app/Support/FieldCheckIn.php`, NEW `app/Support/ServiceHours.php`, `Modules/SPK/app/Services/SpkService.php` (batch C), `Modules/Ticketing/app/Http/Controllers/Api/V1/Technician/TicketController.php`, `Modules/Ticketing/app/Services/TicketService.php` (batch C), `Modules/Ticketing/app/Http/Resources/TicketResource.php` (batch C), `Modules/SPK/app/Http/Controllers/WorkOrderController.php` (batch C), `app/Queries/Admin/ApprovalInboxQuery.php`, NEW `Modules/Service/database/migrations/2026_11_02_100300_add_calendar_to_sla_tiers_table.php`, `Modules/Service/Models/SLATier.php`, `Modules/Service/Http/Requests/StoreSLATierRequest.php`, `Modules/Service/Http/Requests/UpdateSLATierRequest.php`, `Modules/Service/Http/Controllers/SLATierController.php` (batch C), `resources/js/Pages/Admin/Service/SLATiers/Index.tsx`, `resources/js/Pages/Admin/Service/SLATiers/Create.tsx`, `resources/js/Pages/Admin/Service/SLATiers/Edit.tsx`, `app/Http/Requests/Admin/UpdateCompanySettingsRequest.php`, `app/Http/Controllers/Admin/CompanyController.php`, `resources/js/Pages/Admin/Company/Settings.tsx`, `resources/js/Pages/Admin/Tickets/components/checkIn.ts`, `resources/js/Pages/Admin/Tickets/Show.tsx` (batch C), `resources/js/Pages/Admin/SPK/Show.tsx` (batch C), NEW tests of §6.1 W3-FIELD, `tests/Feature/Ticketing/TicketDeadlinesTest.php`, `tests/e2e/sla-targets.spec.ts` (batch C), `tests/e2e/company-settings.spec.ts`.
- Produces: 422 `check_in.far_reason` contract and `far`, `far_reason` fields (M3-TECH-OPS), `calendar` in `deadlineSource` (M3-CORE formatter shows it).

**W3-INVOICE** · fullstack · F7-D26, F5-U02, F5-U03, F5-D13, F9-D09 · security · Skills: Web UI + `web-security-review`
- Owned: `Modules/Billing/app/Http/Requests/StoreInvoiceRequest.php`, `Modules/Billing/app/Http/Requests/UpdateInvoiceRequest.php`, `Modules/Billing/app/Services/BillingService.php`, NEW `Modules/Billing/app/Queries/ReceivableInvoicesQuery.php`, `Modules/Billing/app/Http/Controllers/InvoiceController.php` (batch C), `Modules/Billing/routes/web.php` (batch C), `Modules/Billing/app/Notifications/InvoiceDueReminderNotification.php`, `resources/js/Pages/Admin/Billing/Invoices/Create.tsx`, NEW `resources/js/Pages/Admin/Billing/Invoices/Edit.tsx`, `resources/js/Pages/Admin/Billing/Invoices/Show.tsx` (batch C), `resources/js/Pages/Admin/Billing/Invoices/Index.tsx`, `resources/js/Pages/Admin/Billing/Receivables.tsx`, `resources/js/lib/statuses/invoice.ts` (batch C), `resources/js/types/billing.d.ts` (batch C), `tests/Feature/Billing/ReceivablesTest.php`, NEW tests of §6.1 W3-INVOICE, `tests/e2e/flow-audit-w1-billing.spec.ts`.
- Produces: `due_within` filter, receivables export route, stage `manual`.

**W3-STOCK** · fullstack · F8-U05, F8-D12 · Skills: Web UI
- Owned: `Modules/Inventory/app/Http/Controllers/StockController.php`, `Modules/Inventory/app/Http/Requests/StockReceiveRequest.php`, `Modules/Inventory/app/Http/Requests/StockTransferRequest.php`, `Modules/Inventory/app/Services/StockService.php`, `Modules/Inventory/app/Models/StockMovement.php`, `Modules/Inventory/app/Http/Resources/StockMovementResource.php`, NEW `Modules/Inventory/database/migrations/2026_11_02_100500_add_receipt_fields_to_stock_movements_table.php`, `resources/js/Pages/Admin/Inventory/Stocks/Index.tsx`, `resources/js/Pages/Admin/Inventory/Movements/Index.tsx`, `resources/js/types/inventory.ts` (batch C), NEW tests of §6.1 W3-STOCK, `tests/e2e/stock-asset.spec.ts`.

**W3-REPORTS** · fullstack · F9-D02, F9-D03, F6-U16, F9-U01, F9-U02, F9-D04, F9-D05, F9-D06, F9-D07, F10-U02 · Skills: Web UI
- Owned: `Modules/Reporting/app/Exports/ReportExporter.php` (batch C), `resources/views/exports/table.blade.php`, `app/Support/ExportQuery.php`, `Modules/Reporting/app/Queries/SlaComplianceQuery.php`, `Modules/Reporting/app/Queries/Sources/SlaSources.php`, `Modules/Reporting/app/Queries/TechnicianPerformanceQuery.php`, `Modules/Reporting/app/Http/Controllers/ReportController.php`, `Modules/Reporting/app/Drill/DrillList.php`, `Modules/Reporting/app/Http/Requests/ConsolidatedReportRequest.php`, `resources/js/Pages/Admin/Reports/Technician.tsx`, `resources/js/Pages/Admin/Reports/Sla.tsx`, `resources/js/Pages/Admin/Reports/Sales.tsx`, `resources/js/Pages/Admin/Reports/Consolidated.tsx`, `resources/js/Pages/Admin/Reports/Index.tsx`, `resources/js/Pages/Admin/Reports/StockCard.tsx`, `resources/js/Pages/Admin/Reports/components/DrillLink.tsx`, `resources/js/types/reporting.ts`, NEW tests of §6.1 W3-REPORTS, `tests/e2e/reporting-export.spec.ts`, `tests/e2e/technician-performance.spec.ts`, `tests/e2e/reporting-stock-card.spec.ts`.
- Consumes: M-1 rating columns (CSAT); K32 template is used by W3-INVOICE's export in the same batch through `ExportQuery` (signature unchanged).

**W3-NETWORK** · fullstack · F7-D20, F7-U21, F2-U07, F7-U02, F7-U04, F2-U08, F7-U25, F7-D03, F7-D23, F2-U09, F7-U27 · Skills: Web UI
- Owned: `Modules/NetworkAsset/app/Services/Radius/RadiusDirectory.php`, `Modules/NetworkAsset/app/Http/Resources/NetworkAccountResource.php`, `Modules/NetworkAsset/app/Http/Controllers/NetworkAccountController.php`, `Modules/NetworkAsset/app/Models/NetworkAccount.php`, NEW `Modules/NetworkAsset/database/migrations/2026_11_02_100600_add_last_disconnect_at_to_network_accounts_table.php`, `Modules/NetworkAsset/routes/web.php`, `Modules/NetworkAsset/app/Http/Controllers/NasDeviceController.php`, `Modules/NetworkAsset/app/Services/NasDeviceService.php`, `Modules/NetworkAsset/app/Http/Requests/StoreNasDeviceRequest.php`, `Modules/NetworkAsset/app/Http/Resources/NasDeviceResource.php`, `Modules/Service/Http/Controllers/AnnouncementController.php`, `Modules/Service/app/Queries/CustomerAudienceQuery.php`, `resources/js/Pages/Admin/Announcements/Index.tsx`, `resources/js/Pages/Admin/Announcements/components/EditorDialog.tsx`, `resources/js/Pages/Admin/NasDevices/Show.tsx`, `resources/js/Pages/Admin/NasDevices/Create.tsx`, `resources/js/Pages/Admin/NetworkAccounts/Show.tsx`, `resources/js/Pages/Admin/Odn/Index.tsx`, `resources/js/Pages/Admin/NetworkAssets/Create.tsx`, `resources/js/Pages/Admin/Subscriptions/Index.tsx`, `resources/js/Components/composite/ipam/SubscriptionIpPanel.tsx`, `resources/js/lib/statuses/network.ts`, `resources/js/types/network-asset.d.ts` (batch D), NEW tests of §6.1 W3-NETWORK, `tests/e2e/radius-provisioning.spec.ts`, `tests/e2e/announcements.spec.ts`, `tests/e2e/ipam-odn.spec.ts` (also applies W3-CATALOG's renamed seeds, line 25).

**W3-PLATFORM** · fullstack · F10-D01, F11-D01, F11-U03, F11-U04, F11-U05, F11-U06, F11-D05, F11-D06, F11-D09, F11-D11, F1-U02, F11-U08 (+ F1-D03 platform part, F12-D05 API test) · security · Skills: Web UI + `web-security-review`
- Owned: `app/Http/Middleware/Platform/RequirePlatformPasswordConfirmation.php`, `app/Http/Controllers/Platform/Auth/ConfirmablePasswordController.php`, `app/Support/PlatformSession.php`, `app/Http/Middleware/ResolveActiveCompany.php`, `resources/js/Pages/Platform/Companies/components/UserActions.tsx`, `app/Http/Requests/Platform/Audit/AuditIndexRequest.php`, `app/Services/Platform/PlatformAuditService.php`, `app/Queries/Platform/PlatformAuditQuery.php`, `resources/js/Pages/Platform/Audit/Index.tsx`, `resources/js/Components/composite/platform/ConfirmActionDialog.tsx`, `resources/js/Pages/Platform/Companies/components/ExportsSection.tsx`, `lang/id/auth.php`, `resources/js/Pages/Platform/Companies/components/LifecycleActions.tsx`, `resources/js/Pages/Platform/Companies/components/ActivitySections.tsx`, `app/Queries/Platform/TenantDetailQuery.php`, `resources/js/Pages/Platform/Companies/Create.tsx`, NEW `app/Http/Controllers/Platform/CompanyCodeCheckController.php`, `routes/platform.php`, NEW `app/Http/Controllers/Admin/SubsidiaryRequestController.php`, NEW `app/Http/Requests/Admin/StoreSubsidiaryRequestRequest.php`, NEW `app/Notifications/Platform/SubsidiaryRequestedMail.php`, `routes/admin.php`, `resources/js/Pages/Admin/Company/Profile.tsx`, `app/Http/Controllers/Platform/CompanySubsidiaryController.php`, `resources/js/Pages/Platform/Companies/Show.tsx`, `resources/js/Pages/Platform/Dashboard.tsx`, `app/Http/Controllers/Platform/DashboardController.php`, `resources/js/types/platform.ts`, NEW tests of §6.1 W3-PLATFORM, `tests/e2e/platform.spec.ts`, `tests/e2e/impersonation.spec.ts`.
- Consumes: `AuditEventText` (W3-AUDIT). Produces: route `admin.users.invitation.resend` → `UserController::resendInvitation` (method built by W3-TEAMDOCS, same batch).

**W3-CATALOG** · fullstack · F2-U01, F2-U02, F2-U04, F2-U05, F2-U06, F2-U10, F3-U03, F6-D18, F6-U19, F6-D19, F8-D13, F10-D04 · Skills: Web UI
- Owned: `Modules/Service/Http/Controllers/BandwidthProfileController.php`, `Modules/Service/Http/Controllers/SpeedProfileController.php`, `Modules/Service/Http/Controllers/SLATierController.php` (batch D), `Modules/Service/Http/Controllers/ServicePackageController.php`, `resources/js/Pages/Admin/Service/Packages/Create.tsx`, `resources/js/Pages/Admin/Service/Packages/Edit.tsx`, NEW `resources/js/Pages/Admin/Service/Packages/components/QuickCreateDialog.tsx` (new `components/` folder under the existing `Packages/`), `resources/js/Pages/Admin/Service/SpeedProfiles/Create.tsx`, `resources/js/Pages/Admin/Service/PackageCategories/Index.tsx`, `app/Services/Core/LocationService.php`, `resources/js/Pages/Admin/Locations/Index.tsx`, `resources/js/Pages/Admin/Organizations/Index.tsx`, `resources/js/Pages/Admin/Vehicles/Index.tsx`, `app/Http/Requests/Admin/StoreVehicleRequest.php`, `app/Http/Requests/Admin/UpdateVehicleRequest.php`, `database/seeders/CompanySeeder.php`, `database/seeders/DatabaseSeeder.php`, NEW `Modules/Ticketing/database/migrations/2026_11_02_100200_localise_default_ticket_categories.php`, `resources/js/Pages/Admin/Tickets/Categories/Index.tsx`, `tests/Feature/Admin/LocationContractTest.php`, `tests/Feature/Platform/TenantStarterCatalogTest.php`, NEW tests of §6.1 W3-CATALOG, `tests/e2e/customer-subscription.spec.ts`, `tests/e2e/kanban-t_49387795.spec.ts`, `tests/e2e/number-sequences.spec.ts`, `tests/e2e/private-media.spec.ts`, `tests/e2e/sla-targets.spec.ts` (batch D), `tests/e2e/whatsapp-notifications.spec.ts`, `tests/e2e/package-categories.spec.ts`, `tests/e2e/location-map-first.spec.ts`, `tests/e2e/ui-pickers.spec.ts` (batch D), `tests/e2e/ticket-lifecycle.spec.ts` (batch D).
- Acceptance adds: grep of `tests/` for "Default Region", "Default Area", "Default POP" and the five English category names finds nothing.

**W3-TEAMDOCS** · fullstack · F3-D02, F3-U01, F3-U02, F3-U05 · security · Skills: Web UI + `web-security-review`
- Owned: `resources/js/Pages/Admin/Customers/Show.tsx`, `app/Http/Controllers/Admin/CustomerController.php` (batch D), `app/Http/Controllers/Admin/DocumentController.php`, `app/Services/Core/DocumentService.php`, `resources/js/lib/navigation.ts`, `resources/js/Pages/Admin/Documents/Index.tsx`, NEW `app/Console/Commands/SendCustomerDocumentExpiryCommand.php`, NEW `app/Notifications/CustomerDocumentExpiringNotification.php`, `routes/console.php`, `resources/js/Pages/Admin/Users/Create.tsx`, `resources/js/Pages/Admin/Users/Show.tsx`, `app/Http/Requests/Admin/StoreUserRequest.php`, `app/Http/Controllers/Admin/UserController.php`, `app/Services/Core/UserProfileService.php`, NEW `app/Notifications/StaffAccountInvitation.php`, `resources/js/Pages/AppOnly.tsx`, `app/Http/Controllers/AppOnlyController.php`, `resources/js/types/document.d.ts`, `tests/Feature/DocumentTest.php`, `tests/Feature/Admin/UserFormTest.php`, NEW tests of §6.1 W3-TEAMDOCS, `tests/e2e/admin-vehicles-documents.spec.ts`, `tests/e2e/app-only.spec.ts`.
- Consumes: route `admin.users.invitation.resend` (W3-PLATFORM), bell text `customer_document_expiring` (W3-NOTIFY), `MailBrand`.

**W3-DASH** · fullstack · F5-U07, F4-U16, F1-U07 · Skills: Web UI
- Owned: `app/Queries/Admin/DashboardQuery.php`, `app/Queries/Admin/OnboardingChecklistQuery.php`, `resources/js/Pages/Admin/Dashboard/components/SectionCard.tsx`, `resources/js/Pages/Admin/Dashboard/components/OnboardingChecklistCard.tsx`, `resources/js/Pages/Admin/Dashboard/Index.tsx`, `resources/js/types/dashboard.ts` (batch E), `tests/Feature/Admin/DashboardPersonaTest.php`, `tests/Feature/Admin/OnboardingChecklistTest.php`, `tests/e2e/dashboard-persona.spec.ts` (batch E).
- Consumes: `due_within` (W3-INVOICE), the actionable inventory count (W3-INVREQ), `AuditEventText` (W3-AUDIT).

**M3-CORE** · mobile · F12-D02, F12-U09, F12-D05, F12-D20 (+ F12-D11 prefix, K11 formatter, baseline) · Skills: Flutter
- Owned: `CORE/motion/motion.dart`, `CORE/notifications/notifications_screen.dart`, `CORE/push/push_service.dart`, `CORE/push/firebase_push_backend.dart`, `CORE/screens/login_screen.dart`, `CORE/format/formatters.dart`, `CORE/screens/auth_layout.dart`, `CORE/widgets/inbils_card.dart`, `MOB/packages/inbils_core/lib/inbils_core.dart`, new tests under `MOB/packages/inbils_core/test/`.
- Produces: `formatDueRelative`, `InbilsCard.elevation`. Acceptance adds: `inbils-flutter-ui` §5 greps re-run on `packages/inbils_core/lib`; rubric 10/10 in the report.

**M3-TECH-OPS** · mobile · F12-D01, F12-D13, F12-U05, F6-U10 · security · Skills: Flutter + `web-security-review`
- Owned: NEW `TECH/data/sync/action_outbox.dart`, `TECH/data/local/app_database.dart`, `TECH/data/sync/queue_syncer.dart`, `TECH/features/work/online_only.dart`, `TECH/features/work/check_in.dart`, `TECH/features/work/cache_banner.dart`, `TECH/features/work_orders/work_order_actions.dart`, `TECH/features/tickets/ticket_actions.dart`, `TECH/features/material_returns/material_return_screen.dart` (M1), `TECH/features/materials/new_request_screen.dart` (M1), `TECH/l10n/app_id.arb` (M1), new tests under `MOB/technician/test/`.
- Consumes: W3-FIELD 422 contract; existing `Idempotency-Key` middleware. Acceptance adds: Drift migration v3 → v4 test keeps queued evidence; §5 greps re-run on touched files.

**M3-CUST** · mobile · F6-D03, F6-U04, F6-U06, F5-U05, F5-U10, F6-U02, F6-U03, F6-U05, F6-D16, F6-D17 (+ baseline) · Skills: Flutter
- Owned: `CUST/features/tickets/ticket.dart`, `CUST/features/tickets/ticket_repository.dart`, `CUST/features/tickets/ticket_detail_screen.dart`, `CUST/features/tickets/new_ticket_screen.dart`, `CUST/features/tickets/tickets_screen.dart`, `CUST/features/tickets/ticket_status_chip.dart`, NEW `CUST/features/tickets/ticket_confirm_card.dart`, `CUST/features/help/help_categories_screen.dart`, NEW `CUST/features/home/outage_card.dart`, `CUST/features/home/home_feed.dart`, `CUST/features/home/home_sections.dart`, `CUST/features/home/page_dots.dart`, `CUST/features/branding/company_logo.dart`, `CUST/features/invoices/invoice_detail_screen.dart`, `CUST/features/invoices/invoice_repository.dart`, `CUST/l10n/app_id.arb`, `MOB/customer/test/tickets_test.dart`, `MOB/customer/test/invoices_payments_test.dart`.
- Consumes: §4.2 confirmation route and fields, `formatDueRelative` (M3-CORE, same batch, signature in §5.4). Acceptance adds: §5 greps on `customer/lib` show only the reasoned photo-viewer and logo hits.

**M3-TECH-UI** · mobile · F6-D08, F6-U08, F4-U08, F4-U09, F4-U10, F12-D14, F12-U06, F4-U15, F12-D04, F12-D15, F12-U07 (+ baseline) · Skills: Flutter
- Owned: `TECH/features/work_orders/work_order_detail_screen.dart`, `TECH/features/work_orders/work_order.dart`, `TECH/features/work_orders/evidence_thumbnail.dart`, `TECH/features/work_orders/network_credentials_card.dart`, `TECH/features/evidence/evidence_actions.dart`, `TECH/features/evidence/evidence_capture.dart`, `TECH/features/products/product.dart`, `TECH/features/products/product_picker.dart`, `TECH/features/materials/warehouse.dart`, `TECH/features/materials/new_request_screen.dart` (M2), `TECH/features/material_returns/material_return.dart`, `TECH/features/material_returns/material_return_screen.dart` (M2), `TECH/features/tickets/ticket.dart`, `TECH/features/tickets/ticket_detail_screen.dart`, `TECH/features/tickets/ticket_attachments.dart`, `TECH/features/work/contact_actions.dart`, `TECH/l10n/app_id.arb` (M2), `MOB/technician/pubspec.yaml`, new tests under `MOB/technician/test/`.
- Consumes: §4.2 `site`, §4.7 technician fields. Acceptance adds: §5 greps on `technician/lib` show only the reasoned photo-viewer hits.

**W3-DOCS** · docs · all · Skills: Docs
- Owned: `docs/api/mobile-v1.openapi.json` (regenerated), `CHANGELOG.md`, `docs/user-guide/web.md`, `docs/user-guide/teknisi.md`, `docs/user-guide/pelanggan.md` (+ PDFs as the 2026-10-19 lanes did), `docs/operations/push-notifications.md`, `docs/operations/DEPLOYMENT.md`, `docs/operations/JOBS_NOTIFICATIONS.md`. Main keeps `docs/plans/INBILS_OMP_BACKLOG.md` and `.scratch/flow-audit-fixes/issues/*`.

### 8.3 Ownership across batches

Files owned in more than one batch (one owner per batch; a later owner starts from the merged tree):
- `TicketService.php`, `TicketResource.php`, `Tickets/Show.tsx`: A W3-TICKET, C W3-FIELD.
- `WorkOrderController.php`, `SpkService.php`, `SPK/Show.tsx`: B W3-SPK, C W3-FIELD.
- `AssignDialog.tsx`: A W3-TICKET, B W3-SPK.
- `CustomerController.php`: B W3-SPK, D W3-TEAMDOCS.
- `InvoiceController.php`, Billing `routes/web.php`, `Invoices/Show.tsx`: B W3-PAY, C W3-INVOICE.
- `ReportExporter.php`: B W3-AUDIT, C W3-REPORTS.
- `SLATierController.php`: C W3-FIELD, D W3-CATALOG.
- `types/index.ts`: A W3-SHELL, B W3-INVREQ. `types/billing.d.ts`: A W3-SEC, C W3-INVOICE. `lib/statuses/invoice.ts`: A W3-SHELL, C W3-INVOICE. `types/network-asset.d.ts`: A W3-SEC, D W3-NETWORK. `types/inventory.ts`: B W3-INVREQ, C W3-STOCK. `types/dashboard.ts`, `dashboard-persona.spec.ts`: B W3-AUDIT, E W3-DASH.
- `sla-targets.spec.ts`: C W3-FIELD, D W3-CATALOG. `ui-pickers.spec.ts`: A W3-SHELL, D W3-CATALOG. `ticket-lifecycle.spec.ts`: A W3-TICKET, D W3-CATALOG.
- `TECH/l10n/app_id.arb`, `new_request_screen.dart`, `material_return_screen.dart`: M1 M3-TECH-OPS, M2 M3-TECH-UI.
Within each batch no path appears twice (checked slice by slice against §8.2).

### 8.4 IDs per slice (148)

| Slice | IDs | n |
|---|---|---|
| W3-NOTIFY | F12-D11, F4-U14, F5-U06, F6-U17, F12-U04, F1-D04, F5-D08, F6-D24 | 8 |
| W3-SHELL | F6-U20, F8-U06, F10-U01, F1-D02, F2-D01, F6-D22, F6-D23, F10-D03, F11-D08, F11-D10, F11-U09, F11-U10 | 12 |
| W3-TICKET | F6-D02, F6-D09, F6-U11, F6-U14, F6-D14, F6-D15, F6-D21 | 7 |
| W3-SPK | F4-D02, F4-D15, F4-U04, F4-U01, F4-U03, F4-D03, F4-D05, F4-U02 | 8 |
| W3-PAY | F5-D09, F5-D15, F5-U11, F5-U15, F5-U16, F5-D11, F5-D17, F5-D21 | 8 |
| W3-INVREQ | F8-D06, F4-U12, F8-U04, F4-D09, F4-D12, F8-D07, F8-D08, F8-D09, F8-D10, F8-D11, F4-U13 | 11 |
| W3-AUDIT | F9-U03, F1-D03, F4-D14, F9-D08 | 4 |
| W3-FIELD | F6-D07, F6-D20 | 2 |
| W3-INVOICE | F7-D26, F5-U02, F5-U03, F5-D13, F9-D09 | 5 |
| W3-STOCK | F8-U05, F8-D12 | 2 |
| W3-REPORTS | F9-D02, F9-D03, F6-U16, F9-U01, F9-U02, F9-D04, F9-D05, F9-D06, F9-D07, F10-U02 | 10 |
| W3-NETWORK | F7-D20, F7-U21, F2-U07, F7-U02, F7-U04, F2-U08, F7-U25, F7-D03, F7-D23, F2-U09, F7-U27 | 11 |
| W3-PLATFORM | F10-D01, F11-D01, F11-U03, F11-U04, F11-U05, F11-U06, F11-D05, F11-D06, F11-D09, F11-D11, F1-U02, F11-U08 | 12 |
| W3-CATALOG | F2-U01, F2-U04, F2-U05, F2-U06, F2-U10, F6-U19, F6-D18, F6-D19, F8-D13, F10-D04, F2-U02, F3-U03 | 12 |
| W3-TEAMDOCS | F3-D02, F3-U01, F3-U05, F3-U02 | 4 |
| W3-DASH | F5-U07, F1-U07, F4-U16 | 3 |
| M3-CORE | F12-D02, F12-U09, F12-D05, F12-D20 | 4 |
| M3-TECH-OPS | F12-D01, F12-D13, F6-U10, F12-U05 | 4 |
| M3-CUST | F6-D03, F6-U04, F6-U06, F5-U05, F5-U10, F6-U02, F6-U03, F6-U05, F6-D16, F6-D17 | 10 |
| M3-TECH-UI | F6-D08, F4-U08, F4-U09, F4-U10, F6-U08, F12-U06, F4-U15, F12-D04, F12-D14, F12-D15, F12-U07 | 11 |
| W3-SEC | SEC-1 (not an audit ID) | 0 |
| Total | | 148 |

### 8.5 Deploy note

1. Deploy tenant-roles first (its migrations `2026_10_28_*`).
2. `php artisan migrate` runs M-1..M-7 (additive; DDL INSTANT or INPLACE, LOCK=NONE); then `php artisan queue:restart` and `npm run build`. The scheduler picks up the new daily command.
3. Set `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME` in production before the deploy, or readiness fails.
4. Release both apps after the server; old apps keep working (additive fields only).
5. Dev DB `inbils`: additive `migrate` only; no reseed and no cleanup (owner rule).
