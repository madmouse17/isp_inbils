# Design: flow-audit-fixes wave 2 (high-benefit small UX, 32 IDs)

Status: design for review · Spec: `.scratch/flow-audit-fixes/spec.md` (owner decisions K1-K37, fixed 2026-10-19; K15 and the "Peran dan menu" group moved to lane `tenant-roles`) · Source: `docs/plans/FLOW_AUDIT_2026-10-17.md` §5 "Gelombang 2" (lines 486-496) and "Kelompok perbaikan berakar sama" (lines 514-536) · Wave 1: `.scratch/flow-audit-fixes/design-w1.md` (architect `FixDesign-W1`; contracts agreed by message, §4.0) · Author: architect `FixDesign-W2` · 2026-10-19 · Same text as `local://design-faf-w2.md`

## 0. Summary

- **Status of the 32 wave-2 IDs** against today's code: 25 open, 6 partly settled, 1 moved to wave 1 (F12-U03). Each open part maps to exactly one slice (§1.1).
- **Groups designed here.** Four "same root" groups have no wave-1 ID, so their single change is designed in this document:
  - "Onboarding tenant baru"
  - "Data basi di aplikasi"
  - "Ketuk push tidak membuka tujuannya"
  - "Aksi yang tidak bisa ditarik tanpa konfirmasi seragam"

  The technician-app half of "Label jenis SPK mentah" rides along, because F12-D06 and F12-U08 need the same `type_label` read. Together these close 24 more IDs, fully or in part (§1.2).
- **No migration.** Every new value fits in `companies.settings` (JSON) or comes from stored data: the activity log, reset tokens and payment attempts.
- **One contract change for the apps.** Pushes become data-only messages that each app draws itself, in one group with a summary row (§4.9). The web and both apps must ship in one release (R1).
- **No new permission or package.** Privileged and dispatch users are told apart by permission, never by role name (tenant-roles rule).
- **12 slices in four batches** of 4, 4, 3 and 1:
  - Web batches A and B go first.
  - Mobile batch C waits until `MobileLook` has landed its card and login style in `packages/inbils_core`.

## 1. Problem statement

Wave 2 holds UX changes with high benefit and small effort, in seven groups: onboarding and money flow, approvals and inventory, tickets, platform support, push and apps, online payments, and RADIUS.

Five lanes shipped after the audit: `master-lifecycle`, `integrity-hardening`, `tenant-integrations`, `hardware-panel-ui` and `log-audit`. The 2026-10-18 confirmations and input suggestions also shipped. So every ID was checked again against current `main` (web) and `inbils-mobile` (mobile).

Status values:
- `settled`: nothing left.
- `partly settled`: the remainder is named.
- `open`: not started.
- `moved to W1`: designed and built in wave 1.

### 1.1 The 32 wave-2 IDs

| # | ID | Status | Evidence (current code) | Remaining work → slice |
|---|---|---|---|---|
| 1 | F1-U05 | open | `app/Services/Core/SetupWizardService.php:88` `'spk_auto_invoice' => false` (W1 removes it); `app/Http/Controllers/Admin/CompanyController.php:63` `'auto_suspend_enabled' => false`; nothing asks | One-time "Pakai saran" callout on Settings → Tagihan for W1's `auto_send_installation_invoice` and `auto_suspend_enabled`, plus the server marker → W2-SETTINGS. The checklist step itself is F1-U01 (W2-DASH) |
| 2 | F2-U11 | open | `SetupWizardService.php:61` and `app/Listeners/SeedCompanyDefaults.php:12` both run `CompanySeeder::runFor`; `database/seeders/CompanySeeder.php:401-437` seeds 10 fake-serial assets coded `AST-`; `resources/js/Pages/Platform/Companies/Create.tsx` offers only `demo_data` | "Katalog awal" or "Kosong" at tenant creation; the starter catalog has no fake assets and no fake stock → W2-CATALOG |
| 3 | F4-U18 | open | `resources/js/Pages/Admin/SPK/Show.tsx:481` shows only action buttons after approval | "Langkah berikut" card on a completed installation SPK, from W1's invoice and PPPoE account → W2-APPROVAL |
| 4 | F5-U01 | open | `resources/js/Pages/Admin/Billing/Invoices/Show.tsx:70` `useForm({ amount: '', method: 'cash', … })`; `Modules/Billing/app/Http/Requests/RecordPaymentRequest.php:17` has no `paid_at`; `Modules/Billing/app/Services/BillingService.php:264` `'paid_at' => now()` | Amount prefilled with the balance, "Lunas penuh", paid date, last method → W2-BILLING |
| 5 | F4-U07 | open | `technician/lib/features/material_returns/material_return_screen.dart:59-68` fills used, good and missing from the declared values (0 on a new case) | Used = issued, good = 0, missing computed, "Semua terpakai" → M2-TECH |
| 6 | F4-U11 | partly settled | 2026-10-18 confirmations: `SPK/Show.tsx:148` and `Approvals/components/ReviewSheet.tsx:461-462` say "…disahkan permanen."; `ReviewSheet.tsx:290-292` shows counts only | The confirmation names the impact (customer, invoice amount and send mode, PPPoE account, materials); the review sheet shows evidence, technician, check-in distance and items → W2-APPROVAL |
| 7 | F8-U02 | open | `Inventory/Requests/Show.tsx:1042-1067` approve rows without stock | "Tersedia" per line, block when over → W2-APPROVAL |
| 8 | F8-D03 | partly settled | W1: `InsufficientStockException` names product and warehouse; the generic 422 toast stops. Today: `Modules/Inventory/app/Services/InventoryRequestService.php:306` (SKU and code) | Approver side here, with F8-U02. Stock in the technician picker goes to wave 3 (F4-U10, F12-D14, F12-U06), because `Modules/Inventory/app/Http/Resources/Api/V1/TechnicianProductResource.php:12` hides stock on purpose → W2-APPROVAL |
| 9 | F8-U03 | open | `Inventory/Requests/Show.tsx:149-163` prefills only accepted good; damaged, lost and other stay at 0 | Baik, Rusak, Hilang, Lainnya prefilled from the declaration → W2-APPROVAL |
| 10 | F8-D04 | open | `Show.tsx:1468-1506` has two inputs and sends `lost_qty: 0`; server rule `MaterialReturnService.php:351-355` (issued = used + good + damaged + lost + other); `ReceiveAcceptMaterialReturnRequest.php:33-37` already accepts all four | Frontend only, same change as F8-U03 → W2-APPROVAL |
| 11 | F6-U01 | open | `customer/lib/features/tickets/new_ticket_screen.dart:35-36` empty title and description; the category has `name` (`customer/lib/features/tickets/ticket.dart:26-31`) | Title from the category, quick-answer chips → M2-CUST |
| 12 | F6-U07 | open | `technician/lib/features/home/home_screen.dart:66-75` next visit from SPKs only; `app/Http/Resources/MyWorkItemResource.php:24-45` has no response deadline | `response_due_at` in My Work, urgent-ticket card on Beranda, priority chip and countdown in the list → M2-TECH |
| 13 | F6-U09 | open | `technician/lib/features/tickets/ticket_actions.dart:289-390` comment box without quick replies | Three one-tap public quick replies, counted as first response through W1's K9 → M2-TECH |
| 14 | F6-U12 | open | `resources/js/Pages/Admin/Tickets/Index.tsx:62-115` has six columns; `Modules/Ticketing/app/Http/Controllers/TicketController.php:44` `SORTABLE` lacks `response_deadline` | Petugas and "Respons sebelum" columns; chips "Belum ditugaskan", "Hampir terlampaui", "Tiket saya"; sort by deadline → W2-TICKETS |
| 15 | F6-U13 | open | `Tickets/Show.tsx:254,302-328` static deadlines, customer name only; `Modules/Ticketing/app/Services/TicketService.php:136-142` `deadlineSource()` returns type and name | Remaining time, "2 jam ÷ 2 karena Mendesak", customer link, phone and address, Pelanggan or Petugas badge → W2-TICKETS |
| 16 | F6-U15 | open | `resources/js/Pages/Admin/Evaluations/Create.tsx:22-29` default score `'5.0'`, typed ticket; `app/Http/Controllers/Admin/EvaluationController.php:126-155` no prefill | "Evaluasi teknisi" on a Ditutup ticket opens the form with technician, ticket and an empty score → W2-TICKETS |
| 17 | F6-U18 | partly settled | W1 (K8): response-due warnings for unassigned tickets and customer-reply bells go to `ticket.assign` holders. Dashboard (`app/Queries/Admin/DashboardQuery.php:45-56`) has only "Belum ditugaskan" and "SLA terlampaui" | Cards "Hampir terlampaui (≤ 30 menit)" and "Pelanggan membalas" → W2-DASH (on W2-TICKETS scopes) |
| 18 | F11-U01 | open | `resources/js/Pages/Admin/Reports/AuditLog.tsx:146-159` `SUPPORT_ACTION` knows only the four `impersonation.*` codes | One sentence per support code, from one server dictionary → W2-PLATFORM |
| 19 | F11-D03 | open | Same; search `Modules/Reporting/app/Queries/AuditLogQuery.php:86-89` matches raw columns; export `Modules/Reporting/app/Exports/ReportExporter.php:198` writes the raw code | The same dictionary in resource, search and export → W2-PLATFORM |
| 20 | F11-U02 | open | `resources/js/Pages/Platform/Companies/components/UserActions.tsx:34` menu only with 2FA; `app/Queries/Platform/TenantDetailQuery.php:197-209` has no lock state | Menu for every user, "Terkunci 11 mnt" badge → W2-PLATFORM |
| 21 | F11-D02 | open | `app/Services/Platform/SupportToolsService.php:72-76` clears only `two-factor-user|{id}` | Also clear the `email|ip` sign-in lock of every address the security trail recorded, and the 2FA per-address keys → W2-PLATFORM |
| 22 | F12-U01 | open | `packages/inbils_core/lib/src/push/firebase_push_backend.dart:133-153` shows a push without `groupKey` or summary | Own group and a summary row that opens the inbox → M2-PUSH |
| 23 | F12-D07 | open | Same, plus `app/Services/Push/FcmSender.php:80-89` sends a `notification` block, so Android draws and auto-groups background pushes | Data-only messages, drawn by the app in foreground, background and killed state → M2-PUSH |
| 24 | F12-U02 | open | `packages/inbils_core/lib/src/routing/notification_target.dart:15-21` returns early when the screen is on show; nothing re-reads the subject | Re-read the subject on tap, with a thin loading bar → M2-PUSH |
| 25 | F12-D09 | open | Same; `technician/lib/features/work_orders/work_order_repository.dart:128-133` keeps the SPK detail | Same change → M2-PUSH |
| 26 | F12-U03 | moved to W1 | `Modules/Ticketing/app/Jobs/CheckTicketSlaBreachesJob.php:73-81` notifies `created_by`. W1 (K8) sends `ticket_sla_breached` with `kind: response|resolution` to the assignee and `ticket.assign` holders, by bell and push, never to the customer | none in W2 |
| 27 | F5-U12 | open | `Modules/Billing/app/Http/Controllers/Api/V1/Customer/InvoiceController.php:79-82` folds `exception` and `cancelled_locally` into `failed` | `payment_attempt.reason` and a banner with the next step → M2-CUST |
| 28 | F5-U13 | partly settled | integrity-hardening: `Modules/Billing/app/Notifications/PaymentAttemptExceptionNotification.php:19` bell and push; red alert `resources/js/Pages/Admin/Billing/Receivables.tsx:221`. Dashboard finance has only `overdue_invoices` (`DashboardQuery.php:226`) | Cards "Pembayaran online bermasalah" and "Pembayaran online tertunda > 1 jam" → W2-DASH (on W2-BILLING's filter) |
| 29 | F5-U14 | partly settled | `BillingService.php:283` `closeOpenPages()` already closes open Snap pages when cash is recorded | Banner in "Catat pembayaran" that names the open page and says it will close → W2-BILLING |
| 30 | F7-U20 | open | `resources/js/Pages/Admin/NasDevices/Create.tsx:149-158` typed secret only | "Buat secret acak", Salin, RouterOS command panel → W2-NETWORK |
| 31 | F7-U22 | partly settled | e5095de: the static IP reaches RADIUS as `Framed-IP-Address`. `resources/js/Pages/Admin/NetworkAccounts/Show.tsx:613-617` shows the session address only | "IP statis diharapkan" row, Statis or Dinamis badge, mismatch warning with the existing resync → W2-NETWORK |
| 32 | F7-U23 | open | `resources/js/Components/composite/ipam/AssignIpDialog.tsx:50-105` and `resources/js/Components/composite/ipam/SubscriptionIpPanel.tsx:55-75` never mention the session drop | One sentence in both dialogs → W2-NETWORK |

Count: 25 open, 6 partly settled (F4-U11, F8-D03, F6-U18, F5-U13, F5-U14, F7-U22), 1 moved to W1 (F12-U03), 32 in all. Every wave-2 ID maps to exactly one slice: 31 in W2, F12-U03 in W1.

### 1.2 Same-root groups owned here, and the extra IDs they close

Rule: a group with a wave-2 ID and no wave-1 ID is designed here, as one change.

The notification-matrix group (F4-D13, F4-D16, F5-D12, F6-D04, F6-D05, F8-D05, F12-D10, F5-D16, F5-U13) belongs to W1. As agreed with W1, its dashboard card F5-U13 is built here.

| Group (report line) | Wave-2 IDs | Extra IDs: status → slice | The one change |
|---|---|---|---|
| Onboarding tenant baru (533) | F1-U05, F2-U11 | F1-D05 open → W2-PLATFORM; F1-U01 open → W2-DASH; F1-U03, F1-U04 and F1-U06 (K24) open → W2-SETTINGS; F2-D03 open (same decision K23, same seeder) → W2-CATALOG | First-run checklist "Mulai dari sini" with the billing step, starter-catalog choice, invitation status, 2FA prompt and policy, map region from the profile, sticky save on Settings |
| Data basi di aplikasi (521) | F12-D09, F12-U02 | F5-D06 and F12-D16 open → M2-PUSH. F6-D13 partly settled: the Beranda ticket card exists (`customer/lib/features/home/home_sections.dart:79-115`); refresh → M2-PUSH. F12-D06 open → M2-PUSH (refresh) and M2-TECH (hero type label). F4-D11 open → M2-TECH (issued quantity, refresh after the user's own action), with the tab refresh from M2-PUSH | Each app lists its live providers once. Core re-reads them on resume, on a push and on a tab change, and re-reads the subject of a tapped push |
| Ketuk push tidak membuka tujuannya (522) | F12-D07, F12-U01 | F5-D07 and F6-D12 open → M2-PUSH | Data-only push, own group key and summary, one tap path |
| Konfirmasi seragam (529) | F7-U23 | F4-D13: confirm part settled (`technician/lib/features/work_orders/work_order_actions.dart:98-106`); the reviewer notification is W1's (`spk_submitted`). F5-D03 partly settled (`Invoices/Show.tsx:321-324` asks "Kirim tagihan INV-…?" without amount or channels) → W2-BILLING. F5-U09 open → W2-BILLING. F12-D13: confirm part settled; the offline banner and the doubled "Butuh koneksi internet" go to wave 3 with F12-U05 (same root: offline banner). F4-U17 settled by the rule in the last column. F6-U10: confirm settled; the check-in result snackbar → M2-TECH; the far-away reason is K10 (F6-D07, wave 3). F12-U08: confirm settled; wording by SPK type → M2-TECH. F7-D24 settled (`NetworkAccounts/Show.tsx:696-703` names the disconnect). F7-U24 open → W2-NETWORK | The rule stays as shipped on 2026-10-18: one short confirmation for every one-way action, and the text names what happens. Where the impact is a list, the shared dialogs take `details` (§4.2). "Urungkan" is not used: Berangkat and Mulai notify the customer at once, so there is nothing to take back (F4-U17, OD6) |
| Label jenis SPK mentah (518), app half only | none | F4-D10 and F12-D12 open → M2-TECH (app `type_label`, server `TripSteps` label per type). F4-D18 settled by W1 (fee line "Biaya pemasangan") | Read `type_label` everywhere in the technician app. The step "installed" reads "Selesai dipasang" for installations and "Selesai dikerjakan" otherwise |

Three extras close only in part. Their remainder stays in wave 3:
- F12-D13: the offline banner.
- F6-U10: the K10 distance threshold.
- F8-D03: stock in the technician picker.

## 2. Affected modules

The exhaustive file lists per slice are in §8.2.

| Area | Main paths (existing unless NEW) | IDs | Slice |
|---|---|---|---|
| Tickets (web) | `Modules/Ticketing/app/{Models/Ticket,Http/Controllers/TicketController,Http/Resources/TicketResource,Services/TicketService}.php`, `resources/js/Pages/Admin/Tickets/{Index,Show}.tsx`, `app/Http/Controllers/Admin/EvaluationController.php`, `resources/js/Pages/Admin/Evaluations/Create.tsx`, NEW `resources/js/lib/deadline.ts`, NEW `resources/js/hooks/useNow.ts`, NEW `resources/js/Components/composite/DeadlineBadge.tsx` | F6-U12, F6-U13, F6-U15 | W2-TICKETS |
| Billing (web) | `Modules/Billing/app/{Http/Requests/RecordPaymentRequest,Services/BillingService,Http/Controllers/InvoiceController,Models/PaymentAttempt}.php`, `resources/js/Pages/Admin/Billing/Invoices/{Show,Index}.tsx`, `resources/js/Components/composite/{ConfirmDialog,platform/ConfirmActionDialog}.tsx` | F5-U01, F5-U14, F5-D03, F5-U09 | W2-BILLING |
| Platform support (web) | `app/Services/Platform/SupportToolsService.php`, `app/Http/Requests/Auth/{LoginRequest,TwoFactorChallengeRequest}.php`, `app/Queries/Platform/TenantDetailQuery.php`, `app/Notifications/Platform/TenantAdminInvitation.php`, `resources/js/Pages/Platform/Companies/…`, NEW `app/Support/SupportActionText.php`, `Modules/Reporting/app/{Http/Resources/AuditLogResource,Queries/AuditLogQuery,Exports/ReportExporter}.php`, `resources/js/Pages/Admin/Reports/AuditLog.tsx` | F11-U01, F11-D03, F11-U02, F11-D02, F1-D05 | W2-PLATFORM |
| Starter catalog (web) | `app/Http/Requests/Platform/Tenants/StoreTenantRequest.php`, `app/Actions/Platform/ProvisionTenantAction.php`, `app/Services/Core/SetupWizardService.php`, `app/Events/CompanyCreated.php`, `app/Listeners/SeedCompanyDefaults.php`, `database/seeders/{CompanySeeder,DemoUserSeeder}.php`, `app/Jobs/Platform/SeedTenantDemoDataJob.php`, `resources/js/Pages/Platform/Companies/Create.tsx` | F2-U11, F2-D03 | W2-CATALOG |
| Network (web) | `resources/js/Pages/Admin/NasDevices/{Create,Edit,Show}.tsx`, NEW `resources/js/lib/routerosCommands.ts`, `Modules/NetworkAsset/app/Http/Controllers/NetworkAccountController.php`, `resources/js/Pages/Admin/NetworkAccounts/Show.tsx`, the IPAM dialogs, `app/Http/Controllers/Admin/SubscriptionController.php`, `resources/js/Pages/Admin/Subscriptions/Show.tsx` | F7-U20, F7-U22, F7-U23, F7-U24 | W2-NETWORK |
| Approvals and inventory (web) | `Modules/SPK/app/Http/Controllers/WorkOrderController.php`, NEW `Modules/SPK/app/Support/SpkApprovalImpact.php`, `resources/js/Pages/Admin/SPK/Show.tsx`, `app/{Http/Controllers/Admin/ApprovalInboxController,Queries/Admin/ApprovalInboxQuery}.php`, `resources/js/Pages/Admin/Approvals/…`, `Modules/Inventory/app/Http/Controllers/InventoryRequestController.php`, `resources/js/Pages/Admin/Inventory/Requests/Show.tsx` | F4-U11, F4-U18, F8-U02, F8-D03, F8-U03, F8-D04 | W2-APPROVAL |
| Dashboard (web) | `app/Queries/Admin/DashboardQuery.php`, `app/Http/Controllers/Admin/DashboardController.php`, NEW `app/Queries/Admin/OnboardingChecklistQuery.php`, `resources/js/Pages/Admin/Dashboard/…`, `resources/js/types/dashboard.ts` | F1-U01, F5-U13, F6-U18 | W2-DASH |
| Company settings and 2FA (web) | `resources/js/Pages/Admin/Company/Settings.tsx`, `app/Http/Controllers/Admin/CompanyController.php`, `app/Http/Requests/Admin/UpdateCompanySettingsRequest.php`, `app/Services/Core/{IndonesiaRegionService,CompanyService}.php`, NEW `app/Http/Middleware/EnforceCompanyTwoFactor.php`, `routes/admin.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/Layouts/AdminLayout.tsx`, NEW `resources/js/Components/composite/TwoFactorPrompt.tsx` | F1-U03, F1-U04, F1-U05, F1-U06 | W2-SETTINGS |
| Push and fresh data (mobile, web sender) | `packages/inbils_core/lib/src/{push,routing,widgets}/…`, NEW `packages/inbils_core/lib/src/push/background_push.dart`, `{customer,technician}/lib/{main.dart,app/router.dart,app/shell.dart}`, NEW `{customer,technician}/lib/app/live_refresh.dart`, `app/Services/Push/FcmSender.php` | F12-U01, F12-D07, F12-U02, F12-D09 (+ group extras) | M2-PUSH |
| Technician app (mobile, web API) | `technician/lib/features/{material_returns,materials,work,home,tickets,work_orders}/…`, `technician/lib/l10n/app_id.arb`, `app/Http/Resources/MyWorkItemResource.php`, `Modules/Inventory/app/Http/Resources/Api/V1/TechnicianInventoryRequestResource.php`, `Modules/SPK/app/Support/TripSteps.php` | F4-U07, F6-U07, F6-U09 (+ extras) | M2-TECH |
| Customer app (mobile, web API) | `customer/lib/features/{tickets/new_ticket_screen,invoices/invoice,invoices/invoice_detail_screen}.dart`, `customer/lib/l10n/app_id.arb`, `Modules/Billing/app/Http/Controllers/Api/V1/Customer/InvoiceController.php` | F6-U01, F5-U12 | M2-CUST |
| Docs and contract | `docs/api/mobile-v1.openapi.json`, `CHANGELOG.md`, `docs/user-guide/{web,teknisi,pelanggan}.md` (+ PDFs), `docs/operations/push-notifications.md`, `docs/plans/INBILS_OMP_BACKLOG.md`, `.scratch/flow-audit-fixes/issues/*` | all | W2-DOCS |

## 3. Data model / migrations

No migration in wave 2.

| New value | Where | Written by | Read by |
|---|---|---|---|
| `billing_defaults_confirmed_at` (ISO-8601 string) | `companies.settings` | `CompanyController::updateSettings` on the first successful save, on the server side; a client copy is dropped | checklist step "Atur penagihan otomatis" (W2-DASH), callout (W2-SETTINGS) |
| `require_admin_two_factor` (bool, absent = false) | `companies.settings` | Settings → Keamanan (W2-SETTINGS) | `EnforceCompanyTwoFactor` |
| platform audit row `user.invitation_failed` (`properties.user_id`, `properties.error` = exception class basename) | `activity_log`, log `platform` | `TenantAdminInvitation::failed()` | `TenantDetailQuery` invitation state |

Wave 1 adds `auto_send_installation_invoice` and `bill_installation_materials`, and removes `spk_auto_invoice`. Wave 2 only reads them.

Existing indexes serve the new reads:
- `tickets (company_id, response_deadline)`: `Modules/Ticketing/database/migrations/2026_10_14_110000_add_response_deadline_to_tickets_table.php:15`.
- `tickets (company_id, sla_deadline)`: `2026_07_01_140001_create_tickets_table.php:42`.
- `ticket_comments (ticket_id, created_at)`: `2026_07_01_140002_create_ticket_comments_table.php:22`.
- `payment_attempts (status, created_at)` and `(company_id, invoice_id, status)`: `Modules/Billing/database/migrations/2026_10_12_600000_create_payment_attempts_table.php:31-32`.

W2-DASH pastes `EXPLAIN` output for the new counts.

Deploy, as one release for the web and both apps:
1. No `migrate`.
2. Run `npm run build`.
3. Run `php artisan queue:restart`, so workers pick up the new push payload and the new invitation constructor.
4. Publish both app builds together with the web (R1).

## 4. API and change contracts

### 4.0 Wave-1 contracts consumed here (agreed with FixDesign-W1, binding)

| Contract | Value |
|---|---|
| Company settings | `auto_send_installation_invoice` (bool, default false, "Kirim tagihan pemasangan otomatis"); `bill_installation_materials` (bool, default false, "Tagihkan bahan terpakai"). Both are validated in `UpdateCompanySettingsRequest`. `spk_auto_invoice` is removed everywhere |
| Installation invoice | When an installation SPK is approved, `CompleteSpkAction` builds the invoice whenever `CreateInvoiceFromSpkAction::linesFor($wo)` is not empty. Auto-send on: the invoice is sent at once. Off: it stays a draft, with a bell to `billing.send` holders and W1's card "Tagihan draf menunggu dikirim". The fee line reads "Biaya pemasangan". Other SPK types never auto-invoice |
| Pure line builder | `CreateInvoiceFromSpkAction::linesFor(WorkOrder $wo): list<array{product_id: ?int, description: string, quantity: string, unit_price: string, tax_rate: float, line_total: string, tax_amount: string}>`. It writes nothing and uses whole rupiah. Total = Σ(`line_total` + `tax_amount`). `[]` means no invoice |
| PPPoE account | `NetworkAccountService::provisionForInstallation(WorkOrder)` runs inside `SpkService::approve` for installations, on the SPK's subscription |
| Stock refusal | `InsufficientStockException` (product_name, unit, location_name, requested, available), message "Stok {produk} di {gudang} tersisa {tersedia} {satuan}, butuh {diminta} {satuan}.". The web returns `back()->withErrors(['quantity' => …])->with('error', …)`. `useToast` drops the generic 422 toast when `flash.error` is set |
| Payment attempt statuses | `pending`, `paid`, `failed`, `expired`, `exception`, `cancelled_locally`, plus W1's `refunded` ("Dikembalikan", only from `exception`) with `resolved_at`, `resolved_by`, `resolution_reference`, `resolution_note` |
| Tickets | `tickets.first_response_source` (`reply`/`start`/null) and `tickets.reopened_at`. The first public comment by staff, from the web or the technician API, sets `first_response_at`. The customer API ticket gains `can_reply` |
| Push subjects | `subscription_isolated`, `subscription_reactivated` → `subscription` (new subject, opens Beranda). `spk_submitted` → `work_order`. `ticket_created` (staff), `ticket_customer_replied`, `ticket_reopened`, `ticket_started`, `ticket_replied`, `ticket_closed` → `ticket`. `invoice_draft_pending` → `invoice`. `ticket_sla_breached` (with `kind`) and `ticket_first_response_due` (also for unassigned tickets) go to the assignee and to `ticket.assign` holders |
| Money | Every invoice line, tax, total and balance is whole rupiah (K1) |

### 4.1 W2-TICKETS (F6-U12, F6-U13, F6-U15; predicates for F6-U18)

**Model scopes** (`Modules/Ticketing/app/Models/Ticket.php`). Query builder only, no SQL strings. "Open" means any status except `resolved` and `closed`.

```php
/** Open tickets whose unanswered first response, or whose resolution, falls due within $minutes from now. */
public function scopeDueSoon(Builder $query, int $minutes = 30): Builder;
/** Open tickets whose newest public comment was written by the ticket's own customer account. */
public function scopeAwaitingStaffReply(Builder $query): Builder;
```

- `dueSoon`: `(first_response_at IS NULL AND response_deadline BETWEEN now AND now+$minutes) OR sla_deadline BETWEEN now AND now+$minutes`.
- `awaitingStaffReply`: a non-internal `ticket_comments` row exists by the `customers.user_id` of the ticket's customer, and no non-internal comment exists after it.

**Index and export.** `GET admin.tickets.index` and `admin.tickets.export` share `TicketController::filteredQuery()`:

| Param | Rule | Query |
|---|---|---|
| `unassigned` | boolean | open and `assigned_to IS NULL` |
| `due_soon` | boolean | `->dueSoon()` |
| `awaiting_reply` | boolean | `->awaitingStaffReply()` |
| `sort_by=response_deadline` | added to `SORTABLE` | |

- "Tiket saya" uses the existing `assigned_to=<own public id>`.
- The handler scope at `TicketController.php:120-129` still applies on top, so a handler without `ticket.assign` gets an empty "Belum ditugaskan".
- The `filters` prop gains the three keys.
- The index eager-loads `media`. Today `TicketResource` calls `getMedia('attachments')` once per row.
- The export gains "Petugas" (assignee name, or `-`) and "Respons sebelum" (`response_deadline`, formatted like "Dibuat").

**Show-only additions:**
- `deadline_source` becomes `{ type: 'tier'|'category', name: string, response_hours: int|null, resolution_hours: int, urgent_halved: bool }` (`TicketService::deadlineSource()`). `computeDeadlines()` is unchanged.
- `can.evaluate` is true when the ticket is `closed`, has an assignee, and the user passes the gate that `EvaluationController::create` checks.
- `show()` eager-loads `customer.addresses` only when the user can `customer.view` (R7). `CustomerResource` already carries `phone`, `user_id` and `addresses`.
- The Pelanggan and Petugas badges come from comparing `author_id` (comments) and `uploaded_by` (attachments) with `customer.user_id`. This is frontend only; no backend change.

**Evaluations.** `GET admin.evaluations.create?ticket=<public id>`. For a ticket of this company that is `closed` and assigned, the page gets the prefill below. A foreign, open, unassigned or unknown id gives `null`, never an error.

```ts
prefill: { employee: RemoteOption; reference_type: 'Ticket'; reference: RemoteOption } | null
```

- `Create.tsx` starts `score` at `''` instead of `'5.0'`.
- `customer_rating` stays empty. App ratings arrive with K14 (F6-D03, wave 3).
- `StoreEvaluationRequest` is unchanged.

**Frontend helpers (NEW):**

```ts
// resources/js/lib/deadline.ts (+ deadline.selfcheck.mjs, like impersonationCountdown.selfcheck.mjs)
export type DeadlineTone = 'neutral' | 'warning' | 'danger';
/** "sisa 2 j 5 mnt", "sisa 41 mnt", "lewat 12 mnt", "lewat 3 j"; warning when ≤ soonMinutes (default 30) left, danger once passed. */
export function deadlineState(deadline: string, nowMs: number, soonMinutes?: number): { tone: DeadlineTone; label: string };
// resources/js/hooks/useNow.ts: one interval per page, not per badge
export function useNow(intervalMs?: number): number; // default 30_000
// resources/js/Components/composite/DeadlineBadge.tsx: Badge variant from tone; the label is text, never colour alone
export function DeadlineBadge(props: { deadline: string; now: number }): JSX.Element;
```

### 4.2 W2-BILLING (F5-U01, F5-U14, F5-D03, F5-U09; filter for F5-U13)

**Record payment.** `POST admin.invoices.payments.store` (`RecordPaymentRequest`, `billing.payment.record` as today) gains one field:

| Field | Rules | Messages |
|---|---|---|
| `paid_at` (new) | `nullable`, `date_format:Y-m-d`, not after today in the company timezone, not before the invoice `issue_date` | "Tanggal bayar tidak boleh setelah hari ini." / "Tanggal bayar tidak boleh sebelum tanggal terbit tagihan." |

- `BillingService::recordPayment(...)` gains `?CarbonInterface $paidAt = null` and stores `$paidAt ?? now()`.
- The controller turns a past `Y-m-d` into that day at the current company-timezone clock time (OD8). Today means `now()`.
- Grep every caller first. The Midtrans path keeps its own time.

**Online-pending filter.** `GET admin.invoices.index?online_pending=1`; the index and the export share `filteredQuery`. It lists invoices with an attempt that matches:

```php
// Modules/Billing/app/Models/PaymentAttempt.php
public const STALE_PENDING_MINUTES = 60;
/** Pending online payments opened more than an hour ago whose page has not expired. */
public function scopeStalePending(Builder $query): Builder; // status pending, created_at <= now - 60 min, expires_at > now
```

**Shared dialogs.** One owner for both files, because W2-NETWORK and W2-APPROVAL use them in batch B:

```ts
// resources/js/Components/composite/ConfirmDialog.tsx and resources/js/Components/composite/platform/ConfirmActionDialog.tsx
/** What the action does, one line each: a muted checklist between the description and the error. */
details?: readonly string[];
```

**`Invoices/Show.tsx`:**
- Catat pembayaran:
  - The amount is prefilled with `inv.sisa`, with the hint "Sisa tagihan Rp …".
  - "Lunas penuh" (outline, small) appears only when the amount differs from `sisa`.
  - "Tanggal bayar" is a `DatePicker`, default today, latest today.
  - The method starts from `localStorage['inbils.payment.method']` when that is a valid option, otherwise `cash`. It is saved on success.
- Open online page. When any `onlinePayments.attempts` entry is `pending`, a warning `Alert` (`role="status"`) shows:
  - Title: "Ada pembayaran online yang masih terbuka".
  - Body: "Halaman bayar {order_id} dibuat {waktu}. Mencatat pembayaran ini menutup halaman itu. Bila pelanggan tetap membayar online, pembayarannya masuk daftar Perlu dicek di Tunggakan."
  - This is exactly what `closeOpenPages()` and the exception path do.
- Kirim:
  - Title: `Kirim tagihan ${number}?`.
  - Description: "Pelanggan menerima notifikasi di aplikasi Inbils, juga lewat WhatsApp bila aktif. Item tidak bisa diubah lagi."
  - `details`: `Pelanggan: {name}`, `Jumlah: Rp {total}`, `Jatuh tempo: {due_date}`.
- `Invoices/Index.tsx`: while `online_pending=1`, a removable chip "Pembayaran online tertunda > 1 jam" shows.

### 4.3 W2-PLATFORM (F11-D02, F11-U02, F11-D03, F11-U01, F1-D05)

**Limiter keys.** One source, so support never has to guess them:

```php
// app/Http/Requests/Auth/LoginRequest.php (web and app share it)
public const ATTEMPTS_PER_ACCOUNT = 5;                                    // replaces the literal 5 in exhaustedKey()
public static function throttleKeyFor(string $email, string $ip): string; // Str::transliterate(Str::lower($email).'|'.$ip); throttleKey() calls it
// app/Http/Requests/Auth/TwoFactorChallengeRequest.php
public const ATTEMPTS_PER_ACCOUNT = 10; public const ATTEMPTS_PER_ADDRESS = 5;  // made public
public static function accountKeyFor(User|PlatformAdmin $account): string;
public static function addressKeyFor(User|PlatformAdmin $account, string $ip): string; // the private helpers delegate
```

**Support tools** (`app/Services/Platform/SupportToolsService.php`):

```php
/** K19: removes the sign-in lock of every address the security trail saw for this user, and the 2FA counters. */
public function unlockLogin(PlatformAdmin $actor, User $user): void;
/** @return array<int, array{reason: 'password'|'two_factor', seconds: int}> keyed by user id, locked users only */
public function loginLocks(Collection $users): array;
/** The one predicate behind resendInvitation() and the "Kirim ulang" button. */
public function canResendInvitation(User $user): bool;
```

- Addresses come from one query: distinct `ip_address` in `activity_log` rows with log `security`, event `login.failed`, the user as subject, within the last `SecurityTrail::WINDOW_SECONDS`.
- For each address, `unlockLogin` clears `throttleKeyFor($user->email, $ip)` and `addressKeyFor($user, $ip)`. It also clears `accountKeyFor($user)`.
- `user.login_unlocked` stays. It records `addresses` as a count, never the addresses themselves.
- `login-address|ip` is not cleared: it guards an address, not a user.
- `loginLocks` uses `RateLimiter::tooManyAttempts` and `availableIn` with the public constants.
- `canResendInvitation` keeps today's rule (`SupportToolsService.php:50`). Its role-name check belongs to lane `tenant-roles`.

**Invitation state:**

```php
// app/Notifications/Platform/TenantAdminInvitation.php
public function __construct(public readonly int $companyId, public readonly ?int $userId = null) {}
/** Laravel calls it once the queued mail has failed for good (vendor/laravel/framework/src/Illuminate/Notifications/SendQueuedNotifications.php:137-141). */
public function failed(Throwable $e): void; // platform log 'user.invitation_failed' {user_id, error: class_basename}; skipped when userId is not set (jobs queued before deploy)
```

Callers pass `$user->id`:
- `ProvisionTenantAction`. It is owned by W2-CATALOG in the same batch; PHP ignores the extra positional argument until this lands.
- `SupportToolsService::resendInvitation`.

The `TenantDetailQuery` user rows and admin rows (`PlatformCompanyUser`) are a clean cutover. `invitation_pending` is removed from the query, the type, the pages and the tests. The new fields:

```ts
login_lock: { reason: 'password' | 'two_factor'; seconds: number } | null;
invitation: { state: 'queued' | 'sent' | 'expired' | 'failed'; at: string } | null; // only for users who never signed in
can_resend_invitation: boolean;
```

State rule, in this order:
1. A `user.invitation_failed` row newer than the reset token → `failed`.
2. Otherwise a token → `sent` (at the token's `created_at`), or `expired` once the token is older than the broker's `expire`.
3. Otherwise → `queued`, at the later of `users.created_at` and the latest `user.invitation_resent` row.

Cost: one query each for tokens, invitation rows and trail addresses, then limiter reads for the users on the page only.

**UI:**
- Admin table, column "Masuk terakhir":
  - "Antre sejak 18.17" (neutral), "Terkirim 18.17" (info), "Tautan kedaluwarsa" (warning) or "Gagal terkirim 18.17" (danger).
  - A new actions column holds the same `UserActions`.
- `UserActions` always shows its menu:
  - "Buka kunci masuk" for every user. Confirmation: "Buka kunci masuk {nama}? Kunci karena salah kata sandi dan salah kode 2FA dihapus. Pengguna bisa langsung mencoba masuk lagi."
  - "Atur ulang 2FA", only when the user has 2FA.
  - "Kirim ulang undangan", when `can_resend_invitation`.
- `UsersSection`: badge "Terkunci {n} mnt" (n = ceil(seconds / 60)), or "Terkunci < 1 mnt".

**Support sentences (K20)**, NEW `app/Support/SupportActionText.php`:

```php
final class SupportActionText
{
    /** Code => what the team did; :name is the subject user's name ("pengguna" when unknown). */
    private const SENTENCES = [
        'user.two_factor_reset' => 'mengatur ulang 2FA :name',
        'user.invitation_resent' => 'mengirim ulang undangan admin ke :name',
        'user.login_unlocked' => 'membuka kunci masuk :name',
        'impersonation.started' => 'masuk sebagai :name',
        'impersonation.mode_changed' => 'mengubah mode akses saat masuk sebagai :name',
        'impersonation.write' => 'mengubah data saat masuk sebagai :name',
        'impersonation.ended' => 'keluar dari akun :name',
        'tenant.suspended' => 'menangguhkan perusahaan ini',
        'tenant.reactivated' => 'mengaktifkan kembali perusahaan ini',
        'tenant.deletion_requested' => 'mencatat permintaan penghapusan data perusahaan',
        'tenant.export_requested' => 'meminta ekspor data perusahaan',
        'tenant.export_completed' => 'menyelesaikan ekspor data perusahaan',
        'tenant.export_downloaded' => 'mengunduh ekspor data perusahaan',
    ];
    /** "Tim dukungan Inbils mengatur ulang 2FA Budi Santoso"; an unknown code reads "Akses dukungan oleh tim Inbils". */
    public static function for(Activity $row): string;
    /** @return list<string> codes whose full sentence contains $term, case-insensitive */
    public static function codesMatching(string $term): array;
}
```

- The slice first confirms which column holds the code for `support` rows (`event`, else `description`), and what the subject of `impersonation.*` rows is.
- `AuditLogResource` adds `support_text` (string or null; support rows only).
- `AuditLog.tsx` shows `row.support_text ?? row.description` and deletes `SUPPORT_ACTION` and `SUPPORT_ENTRY`, so one source remains.
- The `AuditLogQuery` search adds `log_name = 'support'` AND (the code is in `codesMatching($term)`, or the subject is a member user whose name matches). The name branch follows the causer subquery at `AuditLogQuery.php:90-101`.
- `ReportExporter.php:198` writes the sentence for support rows. Subjects load per chunk through `AuditLogResource::loadSubjects`.

### 4.4 W2-NETWORK (F7-U20, F7-U22, F7-U23, F7-U24)

**NAS secret** (`NasDevices/Create.tsx`, `Edit.tsx`):
- "Buat secret acak" fills 32 characters `[A-Za-z0-9]` from `crypto.getRandomValues`. That passes `StoreNasDeviceRequest.php:52`.
- A `CopyButton` sits next to the field.
- A live "Perintah RouterOS" card sits under the form. Inbils does not store the address the router uses to reach FreeRADIUS (OD9), so the admin types it once ("Alamat FreeRADIUS dari router"). It is kept only in `localStorage['inbils.radius.serverAddress']`.

```ts
// NEW resources/js/lib/routerosCommands.ts (+ routerosCommands.selfcheck.mjs)
export function routerOsCommands(input: { radiusServer: string; secret: string; nasAddress: string; coaPort: number }): string;
// /radius add service=ppp address={radiusServer} secret={secret} src-address={nasAddress} authentication-port=1812 accounting-port=1813
// /radius incoming set accept=yes port={coaPort}
// /ppp aaa set use-radius=yes accounting=yes interim-update=5m
```

`NasDevices/Show.tsx` shows the same panel with the secret replaced by "(secret yang Anda simpan)", plus the note "Secret hanya terlihat saat dibuat atau diganti di Ubah router." The secret stays write-only.

**Network account.** `NetworkAccountController::show` adds the first IPv4 address of `IpAssignmentService::forSubscription()`:

```php
'staticAddress' => ['address' => '10.99.0.1', 'prefix' => '10.99.0.0/26'] | null,
```

`NetworkAccounts/Show.tsx`:
- The address row gets a badge: "Statis" when `staticAddress` is set, "Dinamis" otherwise.
- A new row reads "IP statis diharapkan: {address} (IPAM {prefix})".
- When the session `framed_ip` differs from `staticAddress.address`, a warning `Alert` reads: "Sesi memakai {framed_ip}, bukan alamat statis {address}. Sinkron ulang agar router memberi alamat yang benar."
- With `can.manage`, the alert carries "Sinkron ulang dan putuskan sesi". It opens the existing resync confirmation (`Show.tsx:696-703`). No new route.

**IPAM dialogs:**
- `AssignIpDialog` adds "Bila pelanggan sedang online lewat RADIUS, sesinya diputus sebentar lalu tersambung lagi dengan alamat baru."
- The release confirmation in `SubscriptionIpPanel` adds "… lalu tersambung lagi dengan alamat dari pool dinamis."
- Both sentences are true whether RADIUS is on or off.

**Terminate.** `SubscriptionController::show` adds `terminateImpact: string[] | null`, only for users who may terminate. `Subscriptions/Show.tsx` passes it as `details` to `ConfirmActionDialog`. Each line appears only when it applies:
- "Akun PPPoE {username} dicabut dari RADIUS dan sesi yang tersambung diputus"
- "Alamat IP statis {ip} kembali ke pool IPAM"
- "Port drop {label} dibebaskan"
- "Tagihan bulanan berhenti dibuat"

Before writing the PPPoE line, the slice confirms which path removes the RADIUS rows after a terminate. `NetworkAccountProvisioner.php:70` returns nothing for a terminated subscription; the trigger is `IpAssignmentChanged` or `network:sync`. If only `network:sync` removes them, the line reads "… dicabut dari RADIUS pada sinkron berikutnya (paling lama 15 menit)".

### 4.5 W2-APPROVAL (F4-U11, F4-U18, F8-U02, F8-D03, F8-U03, F8-D04)

**Approval impact:**

```php
// NEW Modules/SPK/app/Support/SpkApprovalImpact.php
final class SpkApprovalImpact
{
    /** What approving $wo does, in the order SpkService::approve does it. @return list<string> */
    public static function for(WorkOrder $wo): array;
}
```

The slice reads `SpkService::approve` and `CompleteSpkAction` so that each line stays true:
- Installation, when the approval activates them: "Pelanggan {kode} dan langganan {kode} menjadi aktif."
- Installation invoice, from `linesFor()`, one of:
  - "Tagihan pemasangan Rp {total} dibuat dan langsung dikirim ke pelanggan." (auto-send on)
  - "Tagihan pemasangan Rp {total} dibuat sebagai draf. Kirim dari halaman tagihan." (auto-send off)
  - "Tidak ada tagihan pemasangan (tidak ada biaya yang ditagihkan)." (nothing billable)
- Installation without a network account yet: "Akun PPPoE dibuat dari kode pelanggan {kode}."
- Any type with items where `quantity_used` > 0: "Material terpakai dikurangi dari stok: {n} barang."

**`WorkOrderController::show` props:**

```ts
approvalImpact: string[] | null; // only when the user may approve and the SPK awaits approval; the approve ConfirmDialog shows it as details
nextSteps: {                     // only for a completed installation SPK
  invoice: { id: string; number: string; status: string; total: string } | null; // Invoice where work_order_id = SPK, not cancelled
  can_send_invoice: boolean;     // billing.send
  can_create_invoice: boolean;   // billing.create, when invoice is null (existing POST create-from-spk)
  network_account: { username: string } | null; // the subscription's PPPoE account
  can_manage_network_account: boolean;
} | null;
```

**"Langkah berikut" card** on `SPK/Show.tsx`, under "Tahap kunjungan". Each row is either done (check icon and link) or pending (one button):

| Case | Row text | Action |
|---|---|---|
| Invoice still a draft | "Tagihan {INV} Rp {total} masih draf" | "Kirim tagihan" (the §4.2 send confirmation with `details`) |
| Invoice sent | "Tagihan {INV} terkirim" | link |
| No invoice | "Belum ada tagihan pemasangan" | "Buat tagihan pemasangan" |
| Account exists | "Akun PPPoE {username} sudah dibuat" | "Buka akun jaringan" |
| No account | "Buat akun jaringan" | → `admin.subscriptions.network-account.show` |

**Approval inbox.** The review loads only when the sheet opens. This is the `Inertia::optional` pattern of `Modules/NetworkAsset/app/Support/AssetPanelProps.php:45`:

```php
// GET admin.approvals.index?review=<composite_id>; authorised exactly like the item (ApprovalInboxQuery eligibility + view policy); null otherwise
'review' => Inertia::optional(fn () => [
    'impact' => list<string>,  // SPK items: SpkApprovalImpact::for(); [] for inventory requests
    'evidence' => [/* ≤ 3 */ ['id' => string, 'url' => string, 'caption' => ?string]],
    'technician' => ['name' => string] | null,
    'check_in' => ['status' => string, 'distance_m' => ?int, 'accuracy_m' => ?int] | null, // FieldCheckIn::recordFor, as on SPK Show
    'items' => [['name' => string, 'quantity' => string, 'unit' => ?string, 'available' => ?string]], // used for SPK; requested and available for requests
]),
```

- On open, `ReviewSheet.tsx` calls `router.reload({ only: ['review'], data: { review: item.composite_id } })`.
- It shows a skeleton while loading and reuses the thumbnail and check-in markup of `SPK/Show.tsx:390-440`.
- Its approve confirmation (`ReviewSheet.tsx:461-462`) gets `details={review.impact}`.
- The review type stays local to the sheet.

**Inventory request.** `InventoryRequestController::show` adds the prop below. It comes from one stocks query, only while the request awaits approval and the user can approve:

```ts
stockAvailable: Record<string /* item public id */, string /* quantity − reserved_quantity */> | null;
```

The location is the one the service checks: `item.source_location_id ?? request.warehouse_location_id` (`InventoryRequestService.php:277`).

`Inventory/Requests/Show.tsx`:
- Approve dialog:
  - "Tersedia: {n} {satuan}" under each product.
  - When the approved quantity is higher, the input gets `aria-invalid` and the red text "Melebihi stok tersedia".
  - Submit is disabled with "Kurangi jumlah yang melebihi stok."
- Accept dialog:
  - Four inputs per line: Baik (`accepted_good_return_qty`), Rusak (`damaged_return_qty`), Hilang (`lost_qty`), Lainnya (`other_discrepancy_qty`).
  - Prefill: Baik = `declared_good_return_qty`. `declared_discrepancy_qty` goes to the column of its `discrepancy_code` (`damaged`, `lost`, `other`; `DeclareMaterialReturnRequest.php:38`).
  - Each line shows "Diserahkan {issued} · terpakai {used}" with a live check: "Jumlah cocok", "Kurang {x}" or "Lebih {x}".
  - Submit waits until every line meets the server rule.

### 4.6 W2-DASH (F1-U01, F5-U13, F6-U18)

**Section cards.** `DashboardQuery::sections()` gains four cards. Each count restates the predicate of its destination list, like the existing cards (`DashboardQuery.php:20-30`):

| key | group | title | count | href | severity | gate |
|---|---|---|---|---|---|---|
| `sla_due_soon` | operations | Hampir terlampaui (≤ 30 menit) | `Ticket::dueSoon()`, scoped like `breachedTickets()` | `admin.tickets.index?due_soon=1` | warning | as `sla_breached` |
| `customer_replied` | operations | Pelanggan membalas | `Ticket::awaitingStaffReply()`, same scope | `admin.tickets.index?awaiting_reply=1` | info | as `sla_breached` |
| `online_payment_exceptions` | finance | Pembayaran online bermasalah | `PaymentAttemptService::openExceptions()` for the company | `admin.billing.receivables` | danger | `billing.payment.record` |
| `online_payment_pending` | finance | Pembayaran online tertunda > 1 jam | distinct invoices with `PaymentAttempt::stalePending()` | `admin.invoices.index?online_pending=1` | warning | `billing.payment.record` |

No new bell (OD7). `resources/js/types/dashboard.ts` gets the four keys.

**Checklist** (F1-U01, which includes F1-U05's "tagihan otomatis" step):

```php
// NEW app/Queries/Admin/OnboardingChecklistQuery.php
/** @return array{steps: list<array{key: string, label: string, done: bool, href: string}>, done: int, total: int}|null  null without company.manage or once every step is done */
public static function for(User $user): ?array;
```

| key | label | done when (EXISTS, current company) | href |
|---|---|---|---|
| `profile` | Lengkapi profil perusahaan | `address` and `phone` set | `admin.company.profile.edit` |
| `map` | Atur wilayah peta | `settings.map_city_code` or `map_lat` set | `admin.company.settings.edit#peta` |
| `pop` | Tambah POP | an active `pop` location | locations create |
| `package` | Atur paket layanan | an active service package | packages index |
| `team` | Tambahkan pengguna | more than one active member user that is not a customer account | users index |
| `customer` | Daftarkan pelanggan pertama | a customer | customers create |
| `billing` | Atur penagihan otomatis | `settings.billing_defaults_confirmed_at` set | `admin.company.settings.edit#tagihan` |

- `DashboardController` passes `onboarding`.
- NEW `OnboardingChecklistCard.tsx` sits above the sections:
  - Title "Mulai dari sini", with a `Progress` bar reading "{done} dari {total} langkah selesai".
  - One row per step. Done rows show a check icon and text. Pending rows are links with 44 px targets.

### 4.7 W2-SETTINGS (F1-U03, F1-U04, F1-U05, F1-U06)

**Billing callout (F1-U05):**
- `CompanyController::updateSettings` stamps `billing_defaults_confirmed_at` when it is not stored yet. `UpdateCompanySettingsRequest::prepareForValidation` drops a client copy.
- While the marker is absent, `Settings.tsx` shows an info `Alert` at "Tagihan": "Pilih cara penagihan otomatis. Saran kami: kirim tagihan pemasangan otomatis, dan isolir langganan 7 hari setelah jatuh tempo."
- Its "Pakai saran" button sets `auto_send_installation_invoice = true`, `auto_suspend_enabled = true` and `auto_suspend_overdue_days = 7` in the form. It does not save; the admin presses Simpan.

**Sticky save and navigation (F1-U03):**
- Anchored sections `#tagihan`, `#isolir`, `#format`, `#peta`, `#whatsapp` and `#keamanan`, with `scroll-mt`.
- A sticky section nav: chips on phones, a list from `lg` up. Mimic `resources/js/Pages/Admin/Components.tsx:149`.
- A sticky bottom bar with Simpan. While `isDirty`, it reads "Ada perubahan yang belum disimpan".

**Map region (F1-U04):**
- `editSettings` adds `suggestedCity: { code: string; name: string } | null`, from NEW `IndonesiaRegionService::cityInText(?string $address): ?object`:
  - It matches city names, without "KOTA"/"KABUPATEN", in the profile address.
  - It prefers Kabupaten when the address says "Kab"/"Kabupaten", otherwise Kota.
  - The longest name wins.
- The suggestion is offered as `SuggestionChip` "{Kota Bandung} (dari alamat profil)" while `map_city_code` is empty.
- NEW `IndonesiaRegionService::displayName()` converts to title case but keeps "DKI", "DI" and "Adm.". It is used in `cities()` and in `CompanyService::mapDefault()` `city_name`.

**2FA policy (F1-U06, K24):**
- Switch "Wajib 2FA untuk admin" (`settings.require_admin_two_factor`, `sometimes|required|boolean`) under "Keamanan".
- Turning it on without one's own 2FA fails on that field: "Aktifkan 2FA di profil Anda dulu sebelum mewajibkannya."

```php
// NEW app/Http/Middleware/EnforceCompanyTwoFactor.php, appended to the admin route group in routes/admin.php
public const PRIVILEGED = ['company.manage', 'users.manage', 'roles.manage'];
// policy on + user holds any PRIVILEGED permission + no 2FA → redirect()->route('profile.edit')
//   ->with('warning', 'Perusahaan mewajibkan 2FA untuk admin. Aktifkan 2FA untuk melanjutkan.') (303 for non-GET)
```

**2FA prompt (F1-U06):**
- `HandleInertiaRequests` shares `twoFactorPrompt: boolean`. It is true for a privileged user without 2FA while the policy is off. With the policy on, the middleware enforces instead.
- `AdminLayout` renders NEW `TwoFactorPrompt.tsx` (`role="region"`, label "Keamanan akun"):
  - Text: "Lindungi akun admin dengan 2FA. Satu kata sandi yang bocor cukup untuk membuka data semua pelanggan."
  - Buttons: [Aktifkan 2FA], which links to the profile 2FA form, and [Nanti saja].
  - [Nanti saja] stores now + 7 days in `localStorage['inbils.2faPromptSnoozedUntil.{user public id}']`.

### 4.8 W2-CATALOG (F2-U11, F2-D03)

**Request and provisioning:**
- `StoreTenantRequest` gains `catalog`: `required|in:starter,empty`. `demo_data` with `empty` fails on `catalog`: "Data contoh butuh Katalog awal."
- `ProvisionTenantAction` passes `catalog` to `SetupWizardService::createFor`. It also records it in `tenant.provisioned` and notifies with `new TenantAdminInvitation($company->id, $admin->id)` (§4.3).
- `SetupWizardService::createFor` drops its direct `CompanySeeder::runFor` call (`SetupWizardService.php:61`). The synchronous `CompanyCreated` listener already seeds, so a company is seeded once, not twice.

```php
// app/Events/CompanyCreated.php
public function __construct(public Company $company, public string $catalog = CompanySeeder::CATALOG_STARTER) {}
// database/seeders/CompanySeeder.php
public const CATALOG_EMPTY = 'empty'; public const CATALOG_STARTER = 'starter'; public const CATALOG_DEMO = 'demo';
public function runFor(Company $company, string $catalog = self::CATALOG_STARTER): void;
```

| Mode | Seeds |
|---|---|
| `empty` | units, package categories, ticket categories (OD2) |
| `starter` | `empty` plus SLA tiers, Default Region/Area/POP, bandwidth and speed profiles, packages, inventory categories, products. No stock rows, no network assets (OD3) |
| `demo` | `starter` plus the stock rows and the 10 sample assets. The assets are coded through `NetworkAssetService::generateCode()` (Penomoran prefix) instead of `'AST-'.date('Y')` (`CompanySeeder.php:434`, F2-D03) |

**Who seeds which mode:**
- `SeedCompanyDefaults` passes `$event->catalog`.
- Subsidiaries (`CompanyHierarchyService.php:81`) and the setup wizard get `starter`.
- `DemoUserSeeder` (dev and E2E) passes `demo`, so `NetworkDemo::LEGACY_INSTALL` and `InventoryDemo::openingBalances` keep their inputs.
- `SeedTenantDemoDataJob` first runs `runFor($company, CATALOG_DEMO)`, then `DemoDataSeeder::seedFor`.
- Every step stays idempotent, and a test proves it.

**Create form.** `Platform/Companies/Create.tsx` gains a radio group "Isi awal". Its form type stays local, because `resources/js/types/platform.ts` belongs to W2-PLATFORM:
- "Katalog awal" (default): "Paket, profil kecepatan, tingkat SLA, kategori tiket, barang dan lokasi contoh yang bisa diubah. Tanpa stok dan tanpa aset."
- "Kosong": "Hanya satuan barang, kategori paket, dan kategori tiket."
- Ticking "Data contoh" selects and locks "Katalog awal", and the form says why.

### 4.9 M2-PUSH (groups "Ketuk push" and "Data basi")

**Server.** `app/Services/Push/FcmSender::cloudMessage()` sends a data-only message. `data` keeps `type`, `subject_type`, `subject_id`, `notification_id`, `title` and `body`, so the bell and the push still say the same thing.

```php
CloudMessage::fromArray(['data' => $message['data'], 'android' => ['priority' => 'high', 'ttl' => self::TTL]]);
```

**Core, notification group:**
- `FirebasePushBackend` posts every child with `groupKey: 'inbils'`.
- After every show and every tap, `_refreshSummary()` reads `getActiveNotifications()`:
  - With 2 or more children, it posts the summary. Fixed id, `setAsGroupSummary: true`, `GroupAlertBehavior.children`, title "{n} notifikasi baru", `InboxStyleInformation` with up to 5 child titles. Its payload names no subject, so a tap opens the inbox (the existing `openPushTap` rule).
  - Below 2 children, it cancels the summary.
- The summary rule is a pure function, `summaryFor(List<ActiveNotification>)`, so it can be tested.
- Showing a push is a static `display(plugin, message, {title})`, shared with the background entry point. The summary title is a Dart constant, because the background isolate has no `BuildContext`.

**Core, background entry point** (NEW `lib/src/push/background_push.dart`):
- Both apps' `main.dart` register it with `FirebaseMessaging.onBackgroundMessage(inbilsBackgroundPush)` before `runApp`.
- `launchTap()` keeps `getInitialMessage()` for notification messages from other senders, then reads the local launch details.

```dart
/// FCM's background entry point (data-only pushes): draws the push like the foreground does.
@pragma('vm:entry-point')
Future<void> inbilsBackgroundPush(RemoteMessage message);
```

**Core, `PushService`.** A new `Stream<PushMessage> get received` emits every foreground message before the permission check, because refreshing needs no display permission. The `onReceived` callback goes away, and the inbox refresh moves to the router.

**Core, router:**

```dart
GoRouter buildInbilsRouter(Ref ref, {
  required String home, required NotificationTarget inbox,
  required NotificationTarget? Function(NotificationSubject) targetOf,
  required List<ProviderOrFamily> live,                                     // re-read on resume and on any push
  required List<ProviderOrFamily> Function(NotificationSubject) subjectLive, // re-read, as a reload, on a tap
  List<RouteBase> routes = const [],
});
```

- `refreshLive()` invalidates `unreadNotificationCountProvider`, `notificationListProvider` and every `live` entry. It runs on `AppLifecycleListener.onResume` while signed in, and on every `received` event.
- On a tap, the router does three things in order:
  1. Invalidate `subjectLive(subject)` with `asReload: true`. Riverpod 3 `Ref.invalidate(provider, asReload:)` sets `isReloading`, verified in the Riverpod docs.
  2. Call `refreshLive()`.
  3. Call `openPushTap()`.
- As a result, a screen already on show (`notification_target.dart:20`) reloads instead of staying stale.

**Core, `AsyncBody` and exports:**
- When `value.isReloading && value.hasValue`, a 2 px `LinearProgressIndicator` (Semantics "Memuat") sits above the data. Pull-to-refresh (`isRefreshing`) is unchanged.
- `inbilsBackgroundPush` is exported, and `testing.dart` gains a fake for `received`.

**Apps, live lists.** NEW `customer/lib/app/live_refresh.dart`:
- `customerTabLive` by branch:
  - home: `homeSubscriptions`, `homeInvoices`, `homeTickets`, `homeNextVisit`, `homeFeed`
  - invoices: `invoiceList`, `paymentList`
  - help: `ticketList`
  - account: `account`
- `customerLive` = all tabs plus `invoiceDetail`, `ticketDetail`, `visits`, `visitDetail`, `subscriptions`, `subscriptionDetail`, `subscriptionConnection`.
- `customerSubjectLive`: `invoice` → `invoiceDetailProvider(id)`, `ticket` → `ticketDetailProvider(id)`, `work_order` → `visitDetailProvider(id)`, `announcement` → `announcementDetailProvider(id)`, anything else → nothing.

NEW `technician/lib/app/live_refresh.dart`:
- `technicianTabLive`:
  - home: `workList`
  - work: `workList`
  - materials: `myInventoryRequests`, `returnsToDeclare`
  - notifications: core
  - account: nothing
- `technicianLive` = `workList`, `workOrderDetailController`, `ticketDetail`, `myInventoryRequests`, `returnsToDeclare`, `materialReturnDetail`.
- `technicianSubjectLive`: `work_order`, `ticket` and `material_return` → their detail by id; `inventory_request` → `myInventoryRequests`.

**Apps, wiring:**
- Each app's `router.dart` passes its lists.
- `CustomerShell` becomes a `ConsumerWidget`.
- In both shells, `onDestinationSelected` invalidates the chosen tab's list, then calls `goBranch`.
- `TechnicianShell` drops its `WidgetsBindingObserver`, because core now refreshes the inbox on resume.

### 4.10 M2-TECH (F4-U07, F6-U07, F6-U09; extras F4-D11, F6-U10 part, F12-U08, F4-D10, F12-D12, F12-D06 label)

**Web side.** All changes are additive except the step label.

| File | Change |
|---|---|
| `app/Http/Resources/MyWorkItemResource.php` | Ticket rows: `response_due_at` = `response_deadline` (ISO) while `first_response_at` is null, else null. SPK rows: `type_label` (`WorkOrder::typeLabel()`) |
| `Modules/Inventory/app/Http/Resources/Api/V1/TechnicianInventoryRequestResource.php` | Items: `quantity_approved` (float or null), `quantity_issued` (float). `quantity` stays the requested amount |
| `Modules/SPK/app/Support/TripSteps.php` | The `installed` step reads "Selesai dipasang" for `installation` and "Selesai dikerjakan" for other types. The key is unchanged. The web, both apps and the customer API read it (R11) |

**SPK type wording:**
- `WorkOrderDetail.typeLabel` (`type_label`, already sent) replaces the raw `wo.type` (`work_order_detail_screen.dart:156`) and every other place the app shows the SPK type.
- The submit button reads "Selesai dipasang · kirim untuk ditinjau" for installations and "Pekerjaan selesai · kirim untuk ditinjau" otherwise.
- New ARB keys `actionSubmitInstallation` and `actionSubmitOther` replace `actionSubmit` (`technician/lib/l10n/app_id.arb:344`).

**Declaration (F4-U07):**
- A line with nothing declared yet starts at used = issued and good = 0.
- Selisih = issued − used − good. It is shown, not typed ("Selisih 0 m"), and sent as `declared_discrepancy_qty`.
- A negative value blocks submit: "Terpakai dan kembali melebihi yang diserahkan".
- The code and reason fields appear only when Selisih > 0.
- "Semua terpakai" covers every line.

**Materials list (F4-D11):**
- `RequestItem` gains `quantityApproved` and `quantityIssued`.
- The card reads "diserahkan {issued} dari {requested} {satuan}". Before handover it reads "disetujui {approved} dari {requested}".
- After a declaration, `material_return_screen.dart` also invalidates `returnsToDeclareProvider` and `myInventoryRequestsProvider`.

**Urgent ticket (F6-U07):**
- `WorkItem` gains `responseDueAt`.
- Work list cards show a priority chip ("Mendesak" danger, "Tinggi" warning) and "Respons sebelum 21:17 · sisa 41 mnt".
- Beranda shows, above the next visit, "Tiket mendesak · respons sebelum 21:17 (sisa 41 menit)" for the open ticket with the nearest `responseDueAt`. The card opens the ticket.
- The card reads the first page of `workListProvider(WorkDomain.ticket, null)`, because My Work cannot sort by deadline (`app/Queries/Admin/MyWorkQuery.php:14`). The code carries `shortcut: only the first page of the ticket segment, sort by response deadline once My Work can`.
- NEW `technician/lib/features/work/response_countdown.dart` builds the text. It rebuilds every 30 s.

**Quick replies (F6-U09).** Three 44 px chips sit above an empty, non-internal comment box. One tap sends a public comment through the box's existing send path, the same as a typed comment (no confirmation):
- "Laporan diterima. Saya menuju lokasi."
- "Laporan diterima. Saya periksa dari jaringan dulu."
- "Mohon nyalakan ulang modem, lalu kabari hasilnya di sini."

The chips are disabled while sending and while offline.

**Check-in result (F6-U10, part).** After a ticket start, a snackbar reads the returned `check_in`: "Check-in tercatat, {jarak} dari lokasi", or "Mulai tercatat tanpa lokasi" when there was no location.

### 4.11 M2-CUST (F6-U01, F5-U12)

**Web.** `Modules/Billing/app/Http/Controllers/Api/V1/Customer/InvoiceController::latestAttempt()` also selects `status_reason` and adds `reason`. The change is additive; the raw `status_reason` text never leaves the server.

| Attempt | `status` (unchanged) | `reason` (new) |
|---|---|---|
| pending (not expired), paid | `pending`, `settlement` | `null` |
| failed, `status_reason` contains `cancel` | `failed` | `cancelled` |
| failed, otherwise | `failed` | `declined` |
| expired, or pending past `expires_at` | `expire` | `expired` |
| exception | `failed` | `review` |
| cancelled_locally | `failed` | `closed` |
| refunded (W1) | `failed` | `refunded` |

**App, payment banner.** `PaymentAttempt.reason` lives in `customer/lib/features/invoices/invoice.dart`. When `reason != null`, `invoice_detail_screen.dart` shows a `ToneBanner` in MobileLook's card style for the latest attempt:

| `reason` | Banner text | Button |
|---|---|---|
| `declined` | "Pembayaran ditolak. Coba lagi, metode lain bisa dipilih di halaman bayar." | Bayar lagi |
| `cancelled` | "Pembayaran dibatalkan." | Bayar lagi |
| `expired` | "Waktu bayar habis." | Bayar lagi |
| `review` | "Pembayaran Anda sedang diperiksa ISP. Hubungi ISP bila saldo Anda sudah terpotong." | Hubungi ISP |
| `closed` | "Halaman bayar ditutup karena tagihan sudah dibayar atau berubah." | Bayar lagi, only while `can_pay` |
| `refunded` | "Pembayaran online Anda sudah dikembalikan ISP." | Hubungi ISP |

"Bayar lagi" opens the existing pay flow. Snap offers every method, so one button is honest. A second "Pakai metode lain" would do the same thing.

**App, new ticket** (`new_ticket_screen.dart`):
- Choosing a category, or arriving with one, fills the title with the category name. This happens only while the title is empty or still equals the previous category's name.
- `FilterChip`s under the description each add or remove one line:
  - "Gangguan sejak hari ini."
  - "Gangguan sejak kemarin."
  - "Lampu LOS di modem menyala merah."
  - "Lampu modem normal."
  - "Modem sudah dinyalakan ulang."

## 5. UI contract

### 5.1 Rules for every UI slice

- **Reuse, no new design primitives.**
  - `Components/ui`: `Badge`, `Alert`, `Button`, `Card`, `Progress`, `DatePicker`.
  - `Components/composite`: `ConfirmDialog`, `ConfirmActionDialog`, `CopyButton`, `SuggestionChip`, `EnumBadge`.
  - Lucide icons.
- **Copy and accessibility.**
  - Indonesian, no em dash, one term per thing (R6 glossary).
  - A tone always comes with text, never colour alone.
  - 44 px targets.
  - Dark mode through tokens.
  - Contrast at least 4.5:1 for text and 3:1 for borders and icons.
- **States.**
  - Loading: a skeleton, or the 2 px reload bar in the apps.
  - Empty: one sentence that says what to do next.
  - Error: inline next to the control, in Indonesian.
- **Apps.** Reuse MobileLook's new card and login style, `ToneBanner`, `showConfirmDialog` and `SkeletonLoading` from `packages/inbils_core`. Brand colours go through `ensureReadable`.

### 5.2 Pages and components

| Surface | Change | States and accessibility | Slice |
|---|---|---|---|
| `Admin/Tickets/Index` | Petugas and "Respons sebelum" (`DeadlineBadge`), three toggle chips, sort by deadline | Chips use `aria-pressed`. Each chip has its own empty text, e.g. "Tidak ada tiket yang hampir terlampaui.". Badge text: "sisa 41 mnt" or "lewat 12 mnt" | W2-TICKETS |
| `Admin/Tickets/Show` | Remaining time at both targets; target explanation; customer link, phone and address (link and address only with `customer.view`); Pelanggan or Petugas badges; "Evaluasi teknisi" on Ditutup | One `useNow` ticks every badge | W2-TICKETS |
| `Admin/Evaluations/Create` | Technician and ticket prefilled, score empty | Error: "Pilih nilai 1 sampai 5." | W2-TICKETS |
| `Admin/Billing/Invoices/Show` | Prefilled amount, Lunas penuh, Tanggal bayar, remembered method, open-page warning, send `details` | The warning has `role="status"`. Date errors show under the field | W2-BILLING |
| `Admin/Billing/Invoices/Index` | Removable `online_pending` chip | | W2-BILLING |
| `ConfirmDialog`, `ConfirmActionDialog` | Optional `details` list | It sits inside the description region, so screen readers read it | W2-BILLING |
| `Platform/Companies/Show`, `UserActions`, `UsersSection` | Invitation badges, admin actions column, unlock for every user, lock badge | Badge text carries the state. The menu stays keyboard-navigable | W2-PLATFORM |
| `Admin/Reports/AuditLog` | Support sentences from the server | | W2-PLATFORM |
| `Platform/Companies/Create` | Radio "Isi awal" | When demo data locks the choice, the form says why | W2-CATALOG |
| `Admin/NasDevices/{Create,Edit,Show}` | Random secret, copy button, RouterOS panel | `<pre>` with `select-all`. Copying announces "Disalin" | W2-NETWORK |
| `Admin/NetworkAccounts/Show` | Statis/Dinamis badge, expected-address row, mismatch warning with resync | | W2-NETWORK |
| IPAM dialogs; `Admin/Subscriptions/Show` terminate | One sentence; impact `details` | | W2-NETWORK |
| `Admin/SPK/Show` | Approve `details`; "Langkah berikut" card | Done rows show an icon and text | W2-APPROVAL |
| `Admin/Approvals` `ReviewSheet` | Lazy review: impact, thumbnails, technician, check-in, items | Skeleton while loading. Thumbnail `alt` is the caption, or "Foto bukti {n}" | W2-APPROVAL |
| `Admin/Inventory/Requests/Show` | "Tersedia" with an over-stock block; four-column accept with prefill and live sum | The offending input gets `aria-invalid` and a text message | W2-APPROVAL |
| `Admin/Dashboard/Index` | Onboarding card above the sections; four new cards | The progress bar has a text equivalent | W2-DASH |
| `Admin/Company/Settings` | Section nav, sticky save bar, billing callout, city suggestion, title-case names, "Keamanan" with the 2FA policy | The sticky bar stays clear of the phone keyboard. Anchors use `scroll-mt` | W2-SETTINGS |
| `AdminLayout` + `TwoFactorPrompt` | Dismissible 2FA prompt | `role="region"`, label "Keamanan akun" | W2-SETTINGS |
| Both apps, notification shade | Own group, summary "{n} notifikasi baru" | | M2-PUSH |
| Both apps, `AsyncBody` | 2 px reload bar | Semantics "Memuat" | M2-PUSH |
| Technician app: Beranda, Pekerjaan, ticket, SPK, declaration, materials | Urgent card, chips and countdown, quick replies, check-in snackbar, wording by type, declaration prefill, "diserahkan X dari Y" | Chips are 44 px. The countdown is text | M2-TECH |
| Customer app: new ticket, invoice detail | Title from the category, answer chips, payment banner | The banner button is 44 px | M2-CUST |

## 6. Test plan

Main runs each command once, after the batch lands. Check `uptime` first, and wait while the load is above 6.

**Commands:**
- PHP:
  - `./vendor/bin/sail artisan test --filter='<names>'` (`inbils_test`).
  - `./vendor/bin/sail bin pint --dirty --test`.
  - `./vendor/bin/sail composer analyse`.
  - Before E2E: `./vendor/bin/sail exec -T laravel.test sh -c 'find /var/www/html/storage -user root -exec chown 1000:1000 {} +'`.
- JS: `npm run typecheck && npm run lint && npm run format:check`. Run each new `*.selfcheck.mjs` with `node <path>`, the way `resources/js/Components/composite/impersonationCountdown.selfcheck.mjs` runs.
- E2E: `flock /tmp/inbils-e2e.lock sh -c 'npm run build && node scripts/e2e-setup.mjs && npm run test:e2e -- <specs> --project=chromium --workers=1'`.
- Flutter, for each package (`packages/inbils_core`, `customer`, `technician`) under `flock /tmp/inbils-build.lock`: `dart run build_runner build --delete-conflicting-outputs && flutter analyze && flutter test`.

| Slice | Tests (NEW or extended) | Command scope |
|---|---|---|
| W2-TICKETS | NEW `tests/Feature/Ticketing/TicketQueueFiltersTest.php`: `test_unassigned_filter_lists_open_tickets_without_assignee`, `test_due_soon_matches_unanswered_response_or_resolution_within_30_minutes`, `test_awaiting_reply_lists_tickets_whose_last_public_comment_is_the_customers`, `test_handler_without_assign_keeps_own_scope_under_new_filters`, `test_sort_by_response_deadline`, `test_export_has_assignee_and_response_columns`, `test_index_query_count_does_not_grow_with_rows`. Extend `tests/Feature/Ticketing/TicketDeadlinesTest.php`: `test_deadline_source_names_base_hours_and_urgent_halving`, `test_show_hides_customer_address_without_customer_view`. NEW `tests/Feature/Admin/EvaluationPrefillTest.php`: `test_closed_ticket_prefills_technician_and_ticket`, `test_open_foreign_or_unassigned_ticket_gives_no_prefill`, `test_create_has_no_default_score`. NEW `resources/js/lib/deadline.selfcheck.mjs`. Extend `tests/e2e/ticket-lifecycle.spec.ts`: chips, badge, "Evaluasi teknisi" | `TicketQueueFiltersTest|TicketDeadlinesTest|EvaluationPrefillTest`; E2E `ticket-lifecycle.spec.ts` |
| W2-BILLING | NEW `tests/Feature/Billing/RecordPaymentDateTest.php`: `test_past_paid_date_is_stored`, `test_future_paid_date_is_refused`, `test_paid_date_before_issue_date_is_refused`, `test_missing_paid_date_means_now`. NEW `tests/Feature/Billing/InvoiceOnlinePendingFilterTest.php`: `test_lists_invoices_with_pending_attempt_older_than_an_hour`, `test_recent_or_expired_pending_is_not_listed`, `test_export_uses_the_same_filter`. Extend `tests/e2e/billing-lifecycle.spec.ts`: amount prefilled, Lunas penuh, send `details` | `RecordPaymentDateTest|InvoiceOnlinePendingFilterTest|InvoiceOnlinePaymentsPanelTest`; E2E `billing-lifecycle.spec.ts` |
| W2-PLATFORM | Extend `tests/Feature/Platform/SupportActionsTest.php`: `test_unlock_clears_password_lock_of_recorded_addresses`, `test_unlock_clears_two_factor_counters`, `test_unlock_audit_row_counts_addresses_only`, `test_address_budget_is_not_cleared`. Extend `tests/Feature/Platform/TenantDetailTest.php`: `test_login_lock_reports_remaining_seconds`, `test_unlock_is_offered_without_two_factor`, `test_invitation_state_queued_sent_expired_failed`, `test_resend_offered_without_token`. NEW `tests/Feature/Platform/TenantAdminInvitationFailedTest.php`: `test_failed_mail_writes_platform_row`, `test_job_queued_before_deploy_fails_quietly`. NEW `tests/Feature/Reporting/AuditLogSupportTextTest.php`: `test_every_support_code_has_a_sentence`, `test_search_finds_support_rows_by_visible_text`, `test_search_finds_support_rows_by_subject_name`, `test_export_writes_the_sentence`, `test_other_company_rows_stay_out`. Extend `tests/e2e/platform.spec.ts` (also W2-CATALOG's "Kosong" scenario, §8.3) and `tests/e2e/reports-audit-log.spec.ts` | `SupportActionsTest|TenantDetailTest|TenantAdminInvitationFailedTest|AuditLogSupportTextTest|AuditLogTenantIsolationTest|AuthenticationTest|TwoFactorChallengeThrottleTest|LoginTest|TwoFactorLoginTest|ThrottleMatrixTest|PlatformAuthenticationTest`; E2E both specs |
| W2-CATALOG | NEW `tests/Feature/Platform/TenantStarterCatalogTest.php`: `test_starter_seeds_catalog_without_stock_or_assets`, `test_empty_seeds_units_and_categories_only`, `test_demo_data_needs_starter`, `test_company_is_seeded_once`, `test_demo_assets_use_the_numbering_prefix`, `test_subsidiary_gets_starter`, `test_running_a_mode_twice_changes_nothing`. Update `tests/Feature/Platform/TenantProvisioningTest.php:127-131` and `tests/Unit/SetupWizardServiceTest.php` | `TenantStarterCatalogTest|TenantProvisioningTest|SetupWizardServiceTest`; `node scripts/e2e-setup.mjs` must still seed (demo mode) |
| W2-NETWORK | Extend `tests/Feature/NetworkAsset/NetworkAccountHttpTest.php`: `test_show_names_expected_static_address_and_prefix`, `test_show_without_assignment_has_no_static_address`. NEW `tests/Feature/SubscriptionTerminateImpactTest.php`: `test_impact_lists_only_what_applies`, `test_impact_absent_without_terminate_permission`. NEW `resources/js/lib/routerosCommands.selfcheck.mjs` | `NetworkAccountHttpTest|SubscriptionTerminateImpactTest|NasDeviceHttpTest` |
| W2-APPROVAL | NEW `tests/Feature/SPK/SpkApprovalImpactTest.php`: `test_installation_impact_names_invoice_total_and_send_mode`, `test_nothing_billable_says_no_invoice`, `test_other_types_list_materials_only`, `test_impact_absent_without_approve_permission`. NEW `tests/Feature/SPK/WorkOrderNextStepsTest.php`: `test_completed_installation_lists_invoice_and_account`, `test_missing_invoice_offers_create_with_permission`. Extend `tests/Feature/Admin/ApprovalInboxTest.php`: `test_review_loads_only_on_request`, `test_review_of_hidden_or_foreign_item_is_null`. Extend `tests/Feature/Inventory/InventoryRequestWorkflowTest.php`: `test_show_gives_available_stock_while_awaiting_approval`. Run `node resources/js/Pages/Admin/Approvals/components/ReviewSheet.selfcheck.mjs`. Extend `tests/e2e/inventory-returns.spec.ts` (four columns prefilled, live sum) and `tests/e2e/inventory-lifecycle.spec.ts` (Tersedia, over-stock block) | `SpkApprovalImpactTest|WorkOrderNextStepsTest|ApprovalInboxTest|InventoryRequestWorkflowTest|MaterialReturnWorkflowTest`; E2E both specs |
| W2-DASH | Extend `tests/Feature/Admin/DashboardExceptionCountsTest.php`: each new card equals its destination list count. Extend `tests/Feature/Admin/DashboardPersonaTest.php`: the new cards are gated. NEW `tests/Feature/Admin/OnboardingChecklistTest.php`: `test_fresh_tenant_admin_sees_seven_steps`, `test_steps_turn_done_from_data`, `test_card_is_null_when_all_done`, `test_user_without_company_manage_gets_null`. Paste the EXPLAIN of the `dueSoon`, `awaitingStaffReply` and `stalePending` counts in the slice report | `DashboardExceptionCountsTest|DashboardPersonaTest|OnboardingChecklistTest`; E2E `dashboard-persona.spec.ts` |
| W2-SETTINGS | NEW `tests/Feature/Admin/CompanySettingsBillingMarkerTest.php`: `test_first_save_stamps_marker`, `test_client_marker_is_dropped`. NEW `tests/Feature/Admin/CompanyTwoFactorPolicyTest.php`: `test_policy_redirects_privileged_user_without_2fa`, `test_profile_and_logout_stay_reachable`, `test_non_privileged_user_is_not_redirected`, `test_turning_policy_on_needs_own_2fa`, `test_prompt_prop_follows_permission_2fa_and_policy`. Extend `tests/Unit/IndonesiaRegionGeocodeTest.php`: `test_city_in_text_prefers_kabupaten_when_named`, `test_display_name_keeps_dki_di_and_adm`. Extend `tests/Feature/Admin/CompanyMapDefaultTest.php`: `test_settings_suggest_city_from_profile`, `test_map_city_name_is_title_case`. NEW `tests/e2e/company-settings.spec.ts`: sticky Simpan, unsaved marker, "Pakai saran", 2FA prompt "Nanti saja" | `CompanySettingsBillingMarkerTest|CompanyTwoFactorPolicyTest|IndonesiaRegionGeocodeTest|CompanyMapDefaultTest|SettingsEditConflictTest`; E2E `company-settings.spec.ts` |
| M2-PUSH | Update `tests/Feature/Push/FcmChannelTest.php:70-72`: data-only, and `android` holds only priority and ttl. Core: extend `packages/inbils_core/test/push/push_service_test.dart` (`received` fires without display permission). Extend `packages/inbils_core/test/routing/notification_target_test.dart` (a screen on show reloads its subject). NEW `packages/inbils_core/test/push/push_summary_test.dart` (`summaryFor` posts with ≥ 2 children and cancels below). Extend `packages/inbils_core/test/widgets/async_body_test.dart` (bar on reload, none on refresh). Apps: extend `customer/test/push_test.dart` (a tap re-reads the subject). NEW `customer/test/live_refresh_test.dart` and `technician/test/live_refresh_test.dart` (resume re-reads the live providers; a tab change re-reads that tab) | `FcmChannelTest|AppNotificationsPushTest|PushTestApiTest`; Flutter all three packages. QA device smoke when the load allows: two pending pushes → collapsed group → the summary opens the inbox and a child opens its subject, in foreground, background and killed state; a payment push → Beranda is fresh |
| M2-TECH | Extend `tests/Feature/Api/V1/Technician/MyWorkTest.php` (`test_ticket_row_carries_response_due_until_first_response`), `tests/Feature/Api/V1/Technician/InventoryRequestsTest.php` (`test_items_carry_approved_and_issued_quantities`), `tests/Feature/Api/V1/Technician/WorkOrderTripTest.php` (`test_installed_step_label_follows_type`). Flutter: extend `technician/test/material_return_test.dart` (prefill, computed difference, Semua terpakai, negative value blocks), `technician/test/materials_notifications_test.dart` (issued out of requested; a declaration refreshes both lists), `technician/test/home_test.dart` (urgent card), `technician/test/ticket_actions_test.dart` (a quick reply sends a public comment; disabled offline; check-in snackbar), `technician/test/work_order_actions_test.dart` (button by type). NEW `technician/test/response_countdown_test.dart` | `MyWorkTest|InventoryRequestsTest|WorkOrderTripTest|WorkOrdersTest`; Flutter `technician` |
| M2-CUST | Extend `tests/Feature/Api/V1/Customer/InvoicesTest.php`: `test_latest_attempt_reason_per_status`, with a data provider for declined, cancelled, expired, review, closed, refunded and none. Flutter: extend `customer/test/invoices_payments_test.dart` (banner and button per reason) and `customer/test/tickets_test.dart` (the title follows the category until edited; chips add and remove lines) | `InvoicesTest`; Flutter `customer` |
| W2-DOCS | Docs only. Run `./vendor/bin/sail artisan scramble:export`, then `git diff --stat docs/api/mobile-v1.openapi.json` must show only the additive fields | |

**Wave gate** (Main, after batch D):
1. Full PHPUnit.
2. Full E2E (`specs --project=chromium --workers=1`).
3. The three Flutter packages.
4. A `security-reviewer` pass on W2-PLATFORM, W2-SETTINGS, W2-BILLING, W2-NETWORK and M2-CUST (auth, 2FA, payments, secrets), with `composer audit` and `npm audit`. No lock file change is expected.

## 7. Risks and open decisions

Open decisions. The recommended default applies unless the owner objects (standing "ikuti saran"):

| # | Decision | Recommended default | Why |
|---|---|---|---|
| OD1 | Data-only push cutover | Clean cutover: every token gets data-only messages from the release on, with no per-token format flag | Both apps are pre-release: `customer/pubspec.yaml:3` and `technician/pubspec.yaml:3` read `1.0.0+1`, and there are no release notes [INFERENCE: no store build exists]. If a store build does exist, add `device_tokens.push_format` and send data-only messages only to tokens of the new build |
| OD2 | What "Kosong" keeps | Units, package categories, ticket categories | Without ticket categories a customer cannot open a ticket. None of the three counts on a dashboard |
| OD3 | Stock rows in "Katalog awal" | None: products only, stock starts at 0 | This is the audit's own stated benefit: real stock never mixes with sample quantities |
| OD4 | "Pakai saran" billing defaults | Auto-send the installation invoice; isolir after 7 days | F1-U05 wants no active customer without a first invoice. 7 days is today's default |
| OD5 | Who counts as admin for K24 | Holders of `company.manage`, `users.manage` or `roles.manage` | Permission-based (tenant-roles rule), so a renamed role still counts |
| OD6 | Undo instead of confirm (F4-U17, F6-U10) | Keep the shipped confirmation | Berangkat and Mulai notify the customer and record a check-in at once, so nothing can be taken back |
| OD7 | Bell for stale pending online payments | No bell, card only | A pending page usually means a customer is still paying. The exception bell already covers money that arrived wrongly |
| OD8 | Time of a back-dated payment | The chosen date, at the company-timezone clock time of recording | Keeps per-day reports right without a time field |
| OD9 | FreeRADIUS address in the RouterOS panel | Typed once per browser; no new integration field | Inbils stores the FreeRADIUS database host, not the address that routers use |

Risks:

- **R1 Release coupling.** After the web deploy, an old app build shows no background pushes, because data-only messages need the new background handler. Ship the web and both apps together. The bell still lists everything.
- **R2 Battery savers.** Aggressive OEM battery savers (Xiaomi, Oppo) can delay or drop the background isolate that draws a data-only push. FCM `notification` messages are sturdier there. Mitigation: high priority, plus a line in `docs/operations/push-notifications.md` on turning off battery optimisation. Only the emulator is tested.
- **R3 Single seeding path.** `CompanyCreated` becomes the only way a company gets seeded. `test_company_is_seeded_once` and the E2E setup prove that nothing relied on the second run.
- **R4 Demo seeding.** `DemoUserSeeder` moves to mode `demo`, and `SeedTenantDemoDataJob` seeds the demo extras first. So `NetworkDemo.php:115-116` (`firstOrFail` on the legacy serials) keeps working. The live dev DB `inbils` is never re-seeded (owner rule).
- **R5 Back-dated payments.** They move collection figures by `paid_at`, by design. The payment row is audited as today. Validation keeps the date between the issue date and today.
- **R6 Unlock coverage.** The unlock finds addresses only through `login.failed` rows that name the account (`app/Support/SecurityTrail.php:46-50`). A lock from a typed address that matches no active account is not that user's lock, so it stays.
- **R7 Customer data on tickets.** The ticket page shows the customer's address and link only to holders of `customer.view`. The phone number is already in `CustomerResource`.
- **R8 Query cost.** `awaitingStaffReply` runs correlated subqueries over open tickets only, served by `ticket_comments (ticket_id, created_at)`. W2-DASH pastes the EXPLAIN.
- **R9 Audit search.** The audit-log search gains OR branches. It already scans with LIKE; only support rows are added.
- **R10 Deferred, not built:**
  - A FreeRADIUS address field in Pengaturan → Integrasi (OD9).
  - A manual hide for the onboarding card.
  - The check-in snackbar after SPK "Mulai".
  - Stock in the technician picker (wave 3, with F4-U10, F12-D14 and F12-U06).
  - The offline banner (wave 3, with F12-U05).
  - The K10 distance threshold (F6-D07, wave 3).
  - Sorting My Work by deadline (the shortcut in §4.10).
- **R11 Step label.** "Selesai dikerjakan" in `TripSteps` for non-installation types changes text that the web, both apps and existing tests read. M2-TECH greps every test for "Selesai dipasang". A test outside its files is reported to Main as `needs-change`.
- **R12 Shared browsers.** `localStorage` keys are per browser. The 2FA snooze key therefore carries the user's public id, so a shared office PC does not hide the prompt for another admin.

## 8. Work breakdown

### 8.1 Batches and order

Wave 2 starts after wave 1 is merged. At most 4 slices run in parallel per batch.

| Batch | Slices (parallel) | Starts when |
|---|---|---|
| A | W2-TICKETS, W2-BILLING, W2-PLATFORM, W2-CATALOG | wave 1 is merged and its gate is green |
| B | W2-NETWORK, W2-APPROVAL, W2-DASH, W2-SETTINGS | batch A is merged (B uses A's scopes, filter and `details` prop) |
| C | M2-PUSH, M2-TECH, M2-CUST | batch B is merged and `MobileLook` has landed its card and login style in `packages/inbils_core` |
| D | W2-DOCS | batch C is merged |

Slices skip builds, tests and formatters while they work. Main runs §6 once per batch.

Skills lines (owner rule 2026-10-19):
- **Web UI**: `antislop`, `antislop-ui`, `antislop-human`, `antislop-layoutmobile`, `antislop-copywriting`, `ui-ux-pro-max`, `frontend-design`, `redesign-existing-projects`, `design-system`, `tailwindcss-development`, `shadcn`, `ui-styling`, `inertia-react-development`, plus `laravel-best-practices`, `tdd`, `playwright-best-practices`, `typescript-advanced-types`, `ponytail`.
- **Flutter UI**: `antislop`, `antislop-ui`, `antislop-human`, `antislop-layoutmobile`, `antislop-copywriting`, `ui-ux-pro-max`, `frontend-design`, `design-system`, `flutter-build-responsive-layout`, `flutter-fix-layout-issues`, `flutter-add-widget-test`, plus `dart-add-unit-test`, `dart-run-static-analysis`, `laravel-best-practices` for the web API part, `tdd`, `ponytail`.
- Slices marked **security** also add `web-security-review`, and get the `security-reviewer` pass at the wave gate.

### 8.2 Slices

Owned file lists are exhaustive. NEW means the slice creates the file. Generated `*.g.dart`, `*.freezed.dart` and l10n output belong to the owner of their source.

**W2-TICKETS** · fullstack web · F6-U12, F6-U13, F6-U15, plus the predicates for F6-U18
- Skills: Web UI.
- Owned:
  - Ticketing backend: `Modules/Ticketing/app/Models/Ticket.php`, `Modules/Ticketing/app/Http/Controllers/TicketController.php`, `Modules/Ticketing/app/Http/Resources/TicketResource.php`, `Modules/Ticketing/app/Services/TicketService.php`.
  - Ticket pages: `resources/js/Pages/Admin/Tickets/Index.tsx`, `resources/js/Pages/Admin/Tickets/Show.tsx`, `resources/js/types/ticketing.d.ts`.
  - Evaluations: `app/Http/Controllers/Admin/EvaluationController.php`, `resources/js/Pages/Admin/Evaluations/Create.tsx`.
  - NEW helpers: `resources/js/lib/deadline.ts`, `resources/js/lib/deadline.selfcheck.mjs`, `resources/js/hooks/useNow.ts`, `resources/js/Components/composite/DeadlineBadge.tsx`.
  - Tests: NEW `tests/Feature/Ticketing/TicketQueueFiltersTest.php`, `tests/Feature/Ticketing/TicketDeadlinesTest.php`, NEW `tests/Feature/Admin/EvaluationPrefillTest.php`, `tests/e2e/ticket-lifecycle.spec.ts`.
- Consumes: W1's `first_response_source` and `reopened_at`, shown as they are.
- Produces: `Ticket::dueSoon()`, `Ticket::awaitingStaffReply()`, and the index params `unassigned`, `due_soon`, `awaiting_reply`.
- Depends on: wave 1.
- Acceptance: §6 green. The index renders 25 rows without one query per row. The chips round-trip in the URL.

**W2-BILLING** · fullstack web · F5-U01, F5-U14, F5-D03, F5-U09, plus the filter for F5-U13 · **security**
- Skills: Web UI + `web-security-review`.
- Owned:
  - Billing backend: `Modules/Billing/app/Http/Requests/RecordPaymentRequest.php`, `Modules/Billing/app/Services/BillingService.php`, `Modules/Billing/app/Http/Controllers/InvoiceController.php`, `Modules/Billing/app/Models/PaymentAttempt.php`.
  - Invoice pages: `resources/js/Pages/Admin/Billing/Invoices/Show.tsx`, `resources/js/Pages/Admin/Billing/Invoices/Index.tsx`, `resources/js/types/billing.d.ts`, `resources/js/types/portal.d.ts`.
  - Shared dialogs: `resources/js/Components/composite/ConfirmDialog.tsx`, `resources/js/Components/composite/platform/ConfirmActionDialog.tsx`.
  - Tests: NEW `tests/Feature/Billing/RecordPaymentDateTest.php`, NEW `tests/Feature/Billing/InvoiceOnlinePendingFilterTest.php`, `tests/Feature/Billing/InvoiceOnlinePaymentsPanelTest.php`, `tests/e2e/billing-lifecycle.spec.ts`.
- Consumes: W1's whole-rupiah `sisa` and the `refunded` status.
- Produces: `PaymentAttempt::stalePending()`, `STALE_PENDING_MINUTES`, the `online_pending` param, and the `details` prop on both dialogs.
- Depends on: wave 1.
- Acceptance: §6 green. Every caller of `BillingService::recordPayment` was grepped and still works.

**W2-PLATFORM** · fullstack web · F11-D02, F11-U02, F11-D03, F11-U01, F1-D05 · **security**
- Skills: Web UI + `web-security-review`.
- Owned:
  - Support and auth: `app/Services/Platform/SupportToolsService.php`, `app/Http/Requests/Auth/LoginRequest.php`, `app/Http/Requests/Auth/TwoFactorChallengeRequest.php`, `app/Queries/Platform/TenantDetailQuery.php`, `app/Notifications/Platform/TenantAdminInvitation.php`.
  - Platform pages: `resources/js/Pages/Platform/Companies/Show.tsx`, `resources/js/Pages/Platform/Companies/components/UserActions.tsx`, `resources/js/Pages/Platform/Companies/components/UsersSection.tsx`, `resources/js/types/platform.ts`.
  - Audit text: NEW `app/Support/SupportActionText.php`, `Modules/Reporting/app/Http/Resources/AuditLogResource.php`, `Modules/Reporting/app/Queries/AuditLogQuery.php`, `Modules/Reporting/app/Exports/ReportExporter.php`, `resources/js/Pages/Admin/Reports/AuditLog.tsx`.
  - Tests: `tests/Feature/Platform/SupportActionsTest.php`, `tests/Feature/Platform/TenantDetailTest.php`, NEW `tests/Feature/Platform/TenantAdminInvitationFailedTest.php`, NEW `tests/Feature/Reporting/AuditLogSupportTextTest.php`, `tests/e2e/platform.spec.ts`, `tests/e2e/reports-audit-log.spec.ts`.
- Consumes: `SecurityTrail::WINDOW_SECONDS` and the `security` log rows.
- Produces: the public limiter constants and key builders, `TenantAdminInvitation($companyId, $userId)`, and `support_text`.
- Depends on: wave 1.
- Acceptance: §6 green. Grepping code and tests for `invitation_pending` finds nothing.

**W2-CATALOG** · backend + one form · F2-U11, F2-D03
- Skills: Web UI.
- Owned:
  - Provisioning: `app/Http/Requests/Platform/Tenants/StoreTenantRequest.php`, `app/Actions/Platform/ProvisionTenantAction.php`, `app/Services/Core/SetupWizardService.php`, `app/Events/CompanyCreated.php`, `app/Listeners/SeedCompanyDefaults.php`.
  - Seeding: `database/seeders/CompanySeeder.php`, `database/seeders/DemoUserSeeder.php`, `app/Jobs/Platform/SeedTenantDemoDataJob.php`.
  - Form: `resources/js/Pages/Platform/Companies/Create.tsx`.
  - Tests: NEW `tests/Feature/Platform/TenantStarterCatalogTest.php`, `tests/Feature/Platform/TenantProvisioningTest.php`, `tests/Unit/SetupWizardServiceTest.php`.
- Consumes: `TenantAdminInvitation($companyId, $userId)` from W2-PLATFORM, in the same batch. The call is positional, so it stays compatible.
- Produces: `CompanySeeder::CATALOG_*` and `CompanyCreated($company, $catalog)`.
- Depends on: wave 1.
- Acceptance: §6 green. `node scripts/e2e-setup.mjs` still seeds "Default POP", "Home 10Mbps" and the legacy assets. Its E2E scenario (a "Kosong" tenant) goes as `needs-change` to W2-PLATFORM, which owns `tests/e2e/platform.spec.ts`.

**W2-NETWORK** · fullstack web · F7-U20, F7-U22, F7-U23, F7-U24 · **security** (secret handling)
- Skills: Web UI + `web-security-review`.
- Owned:
  - NAS: `resources/js/Pages/Admin/NasDevices/Create.tsx`, `resources/js/Pages/Admin/NasDevices/Edit.tsx`, `resources/js/Pages/Admin/NasDevices/Show.tsx`, NEW `resources/js/lib/routerosCommands.ts`, NEW `resources/js/lib/routerosCommands.selfcheck.mjs`.
  - Network account: `Modules/NetworkAsset/app/Http/Controllers/NetworkAccountController.php`, `resources/js/Pages/Admin/NetworkAccounts/Show.tsx`.
  - IPAM dialogs: `resources/js/Components/composite/ipam/AssignIpDialog.tsx`, `resources/js/Components/composite/ipam/SubscriptionIpPanel.tsx`.
  - Subscriptions: `app/Http/Controllers/Admin/SubscriptionController.php`, `resources/js/Pages/Admin/Subscriptions/Show.tsx`.
  - Tests: `tests/Feature/NetworkAsset/NetworkAccountHttpTest.php`, NEW `tests/Feature/SubscriptionTerminateImpactTest.php`.
- Consumes: the `details` prop of `ConfirmActionDialog` (W2-BILLING).
- Produces: `staticAddress` and `terminateImpact`.
- Depends on: batch A.
- Acceptance: §6 green. No page or prop carries a stored secret. The PPPoE line matches the verified trigger path.

**W2-APPROVAL** · fullstack web · F4-U11, F4-U18, F8-U02, F8-D03, F8-U03, F8-D04
- Skills: Web UI.
- Owned:
  - SPK: `Modules/SPK/app/Http/Controllers/WorkOrderController.php`, NEW `Modules/SPK/app/Support/SpkApprovalImpact.php`, `resources/js/Pages/Admin/SPK/Show.tsx`, `resources/js/types/spk.d.ts`.
  - Approvals: `app/Http/Controllers/Admin/ApprovalInboxController.php`, `app/Queries/Admin/ApprovalInboxQuery.php`, `resources/js/Pages/Admin/Approvals/Index.tsx`, `resources/js/Pages/Admin/Approvals/components/ReviewSheet.tsx`, `resources/js/Pages/Admin/Approvals/components/ReviewSheet.selfcheck.mjs`.
  - Inventory: `Modules/Inventory/app/Http/Controllers/InventoryRequestController.php`, `resources/js/Pages/Admin/Inventory/Requests/Show.tsx`, `resources/js/types/inventory.ts`.
  - Tests: NEW `tests/Feature/SPK/SpkApprovalImpactTest.php`, NEW `tests/Feature/SPK/WorkOrderNextStepsTest.php`, `tests/Feature/Admin/ApprovalInboxTest.php`, `tests/Feature/Inventory/InventoryRequestWorkflowTest.php`, `tests/e2e/inventory-returns.spec.ts`, `tests/e2e/inventory-lifecycle.spec.ts`.
- Consumes:
  - From W1: `linesFor()`, `provisionForInstallation`, `InsufficientStockException`, the K17 setting.
  - From W2-BILLING: the `details` prop of `ConfirmDialog`.
- Produces: `approvalImpact`, `nextSteps`, `review` and `stockAvailable`.
- Depends on: batch A.
- Acceptance: §6 green. `review` is authorised exactly like the inbox row. `resources/js/types/index.ts` is not touched.

**W2-DASH** · fullstack web · F1-U01, F5-U13, F6-U18
- Skills: Web UI.
- Owned:
  - Backend: `app/Queries/Admin/DashboardQuery.php`, `app/Http/Controllers/Admin/DashboardController.php`, NEW `app/Queries/Admin/OnboardingChecklistQuery.php`.
  - Frontend: `resources/js/Pages/Admin/Dashboard/Index.tsx`, `resources/js/Pages/Admin/Dashboard/components/SectionCard.tsx`, NEW `resources/js/Pages/Admin/Dashboard/components/OnboardingChecklistCard.tsx`, `resources/js/types/dashboard.ts`.
  - Tests: `tests/Feature/Admin/DashboardExceptionCountsTest.php`, `tests/Feature/Admin/DashboardPersonaTest.php`, NEW `tests/Feature/Admin/OnboardingChecklistTest.php`, `tests/e2e/dashboard-persona.spec.ts`.
- Consumes:
  - `Ticket::dueSoon()` and `awaitingStaffReply()` (W2-TICKETS).
  - `PaymentAttempt::stalePending()` (W2-BILLING).
  - `PaymentAttemptService::openExceptions()`.
  - W1's draft-invoice card.
  - The `billing_defaults_confirmed_at` key, read only.
- Produces: the section keys `sla_due_soon`, `customer_replied`, `online_payment_exceptions` and `online_payment_pending`, and the prop `onboarding`.
- Depends on: batch A.
- Acceptance: §6 green. Every card equals its destination list. The EXPLAIN is pasted.

**W2-SETTINGS** · fullstack web · F1-U03, F1-U04, F1-U05, F1-U06 · **security** (2FA enforcement)
- Skills: Web UI + `web-security-review`.
- Owned:
  - Settings: `resources/js/Pages/Admin/Company/Settings.tsx`, `app/Http/Controllers/Admin/CompanyController.php`, `app/Http/Requests/Admin/UpdateCompanySettingsRequest.php`, `app/Services/Core/IndonesiaRegionService.php`, `app/Services/Core/CompanyService.php`.
  - 2FA enforcement and prompt: NEW `app/Http/Middleware/EnforceCompanyTwoFactor.php`, `routes/admin.php`, `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/types/index.ts` (`twoFactorPrompt` in `PageProps`), `resources/js/Layouts/AdminLayout.tsx`, NEW `resources/js/Components/composite/TwoFactorPrompt.tsx`.
  - Tests: NEW `tests/Feature/Admin/CompanySettingsBillingMarkerTest.php`, NEW `tests/Feature/Admin/CompanyTwoFactorPolicyTest.php`, `tests/Unit/IndonesiaRegionGeocodeTest.php`, `tests/Feature/Admin/CompanyMapDefaultTest.php`, NEW `tests/e2e/company-settings.spec.ts`.
- Consumes: W1's settings keys, and `app/Http/Middleware/Platform/RequirePlatformTwoFactor.php:18-28` as the pattern.
- Produces: the marker `billing_defaults_confirmed_at`, `require_admin_two_factor` and `twoFactorPrompt`.
- Depends on: batch A.
- Acceptance: §6 green. With the policy on, an admin without 2FA can still reach the profile, the 2FA endpoints and logout.

**M2-PUSH** · mobile core and both shells, plus one web file · groups "Ketuk push" and "Data basi"
- Skills: Flutter UI.
- Owned:
  - Core push: `packages/inbils_core/lib/src/push/firebase_push_backend.dart`, `packages/inbils_core/lib/src/push/push_service.dart`, `packages/inbils_core/lib/src/push/push_backend.dart`, NEW `packages/inbils_core/lib/src/push/background_push.dart`.
  - Core routing, widgets and exports: `packages/inbils_core/lib/src/routing/notification_target.dart`, `packages/inbils_core/lib/src/routing/core_routes.dart`, `packages/inbils_core/lib/src/widgets/async_body.dart`, `packages/inbils_core/lib/inbils_core.dart`, `packages/inbils_core/lib/testing.dart`.
  - Customer app: `customer/lib/main.dart`, `customer/lib/app/router.dart`, `customer/lib/app/shell.dart`, NEW `customer/lib/app/live_refresh.dart`.
  - Technician app: `technician/lib/main.dart`, `technician/lib/app/router.dart`, `technician/lib/app/shell.dart`, NEW `technician/lib/app/live_refresh.dart`.
  - Web sender: `app/Services/Push/FcmSender.php`.
  - Tests: `tests/Feature/Push/FcmChannelTest.php`, `packages/inbils_core/test/push/push_service_test.dart`, `packages/inbils_core/test/routing/notification_target_test.dart`, NEW `packages/inbils_core/test/push/push_summary_test.dart`, `packages/inbils_core/test/widgets/async_body_test.dart`, `customer/test/push_test.dart`, NEW `customer/test/live_refresh_test.dart`, NEW `technician/test/live_refresh_test.dart`.
- Consumes: the providers named in §4.9 (read only) and W1's `subscription` subject.
- Produces: `inbilsBackgroundPush`, `PushService.received`, `buildInbilsRouter(live:, subjectLive:)` and the reload bar.
- Depends on: batch B and MobileLook.
- Acceptance: §6 green, plus the QA device smoke.

**M2-TECH** · technician app + web technician API · F4-U07, F6-U07, F6-U09 (extras: F4-D11, part of F6-U10, F12-U08, F4-D10, F12-D12, the F12-D06 label)
- Skills: Flutter UI.
- Owned:
  - Materials: `technician/lib/features/material_returns/material_return_screen.dart`, `technician/lib/features/materials/inventory_request.dart`, `technician/lib/features/materials/materials_screen.dart`.
  - Work and home: `technician/lib/features/work/my_work.dart`, `technician/lib/features/work/work_screen.dart`, NEW `technician/lib/features/work/response_countdown.dart`, `technician/lib/features/home/home_screen.dart`.
  - Tickets, SPK and copy: `technician/lib/features/tickets/ticket_actions.dart`, `technician/lib/features/work_orders/work_order.dart`, `technician/lib/features/work_orders/work_order_detail_screen.dart`, `technician/lib/features/work_orders/work_order_actions.dart`, `technician/lib/l10n/app_id.arb`.
  - Web API: `app/Http/Resources/MyWorkItemResource.php`, `Modules/Inventory/app/Http/Resources/Api/V1/TechnicianInventoryRequestResource.php`, `Modules/SPK/app/Support/TripSteps.php`.
  - Web tests: `tests/Feature/Api/V1/Technician/MyWorkTest.php`, `tests/Feature/Api/V1/Technician/InventoryRequestsTest.php`, `tests/Feature/Api/V1/Technician/WorkOrderTripTest.php`.
  - App tests: `technician/test/material_return_test.dart`, `technician/test/materials_notifications_test.dart`, `technician/test/home_test.dart`, `technician/test/ticket_actions_test.dart`, `technician/test/work_order_actions_test.dart`, NEW `technician/test/response_countdown_test.dart`.
- Consumes: W1's K9 (a public comment counts as the first response), and the existing `type_label` and `check_in` fields.
- Produces: `response_due_at`, `type_label` in My Work, `quantity_approved` and `quantity_issued`.
- Depends on: batch B and MobileLook.
- Acceptance: §6 green, with R11 handled.

**M2-CUST** · customer app + web customer API · F6-U01, F5-U12 · **security** (payment API)
- Skills: Flutter UI + `web-security-review`.
- Owned:
  - App: `customer/lib/features/tickets/new_ticket_screen.dart`, `customer/lib/features/invoices/invoice.dart`, `customer/lib/features/invoices/invoice_detail_screen.dart`, `customer/lib/l10n/app_id.arb`.
  - Web API: `Modules/Billing/app/Http/Controllers/Api/V1/Customer/InvoiceController.php`.
  - Tests: `tests/Feature/Api/V1/Customer/InvoicesTest.php`, `customer/test/invoices_payments_test.dart`, `customer/test/tickets_test.dart`.
- Consumes: W1's `refunded` status and MobileLook's card style.
- Produces: `payment_attempt.reason`.
- Depends on: batch B and MobileLook.
- Acceptance: §6 green. The API sends the `reason` key only, never `status_reason` text.

**W2-DOCS** · docs · all IDs
- Skills: `antislop-copywriting`, `antislop`.
- Owned:
  - `docs/api/mobile-v1.openapi.json` (regenerated with `php artisan scramble:export`).
  - `CHANGELOG.md`.
  - `docs/user-guide/web.md`, `docs/user-guide/teknisi.md`, `docs/user-guide/pelanggan.md`, with their PDFs regenerated the way the 2026-10-19 lanes did.
  - `docs/operations/push-notifications.md`.
  - `docs/plans/INBILS_OMP_BACKLOG.md` (row `flow-audit-fixes`).
  - `.scratch/flow-audit-fixes/issues/*`.
- Depends on: batch C.
- Acceptance:
  - A CHANGELOG entry with the deploy steps from §3.
  - The OpenAPI diff is additive only: `reason`, `response_due_at`, `type_label`, `quantity_approved`, `quantity_issued`.
  - The wave-2 tickets are set to done.

### 8.3 Ownership matrix check

| Batch | Slice | Owned entries | Overlap check |
|---|---|---|---|
| A | W2-TICKETS | 17 (8 code, 1 type, 4 NEW helpers, 4 tests) | none with BILLING, PLATFORM or CATALOG |
| A | W2-BILLING | 14 (6 code, 2 types, 2 shared dialogs, 4 tests) | the dialogs are owned only here |
| A | W2-PLATFORM | 20 (12 code, 1 type, 1 NEW, 6 tests) | `tests/e2e/platform.spec.ts` only here (CATALOG sends `needs-change`) |
| A | W2-CATALOG | 12 (9 code, 3 tests) | `ProvisionTenantAction.php` only here; the invitation class only in PLATFORM |
| B | W2-NETWORK | 13 (9 code, 2 NEW, 2 tests) | `SubscriptionController.php` and `Subscriptions/Show.tsx` only here |
| B | W2-APPROVAL | 18 (8 code, 2 types, 1 NEW, 7 tests and selfcheck) | `resources/js/types/index.ts` not touched (SETTINGS owns it) |
| B | W2-DASH | 11 (4 code, 1 type, 2 NEW, 4 tests) | reads `companies.settings`, never writes it |
| B | W2-SETTINGS | 16 (8 code, 1 type, 2 NEW, 5 tests) | `routes/admin.php`, `HandleInertiaRequests.php`, `CompanyService.php` and `types/index.ts` only here |
| C | M2-PUSH | 26 (9 core incl. 1 NEW, 8 app incl. 2 NEW, 1 web, 8 tests) | the app `router.dart` and `shell.dart` only here; no feature file touched |
| C | M2-TECH | 24 (12 app incl. 1 NEW, 3 web, 9 tests) | `material_return_screen.dart` only here (it also carries F4-D11's refresh after the user's own action) |
| C | M2-CUST | 8 (4 app, 1 web, 3 tests) | its `customer/lib/features/*` files only here |
| D | W2-DOCS | 8 | docs only |

Total: 187 owned entries, counted by hand from §8.2. Each path appears once in the whole wave, so no file has two owners in any batch.

Look-alike paths that are different files:
- `app/Http/Controllers/Admin/CompanyController.php` (SETTINGS) and `app/Http/Controllers/Platform/CompanyController.php` (not touched).
- `Modules/Billing/app/Http/Controllers/InvoiceController.php` (BILLING) and `Modules/Billing/app/Http/Controllers/Api/V1/Customer/InvoiceController.php` (CUST).

### 8.4 Cross-wave and cross-lane notes

- **Wave 1 lands first** in `Settings.tsx`, `UpdateCompanySettingsRequest.php`, `SetupWizardService.php`, `CreateInvoiceFromSpkAction.php`, `PaymentAttempt.php`, `TicketService.php`, `DashboardQuery.php`, `useToast.ts`, and possibly `CompanyService.php`. Every W2 slice re-reads its files after wave 1 lands and never reverts a W1 change.
- **Wave 3** picks up the remainders named in §1.2, plus K10 (F6-D07), K14 (F6-D03) and K12 (F6-D18). K12 may localise the seeded ticket category names, which become F6-U01's default titles.
- **Lane `tenant-roles`** owns the role-name checks that wave 2 leaves as they are: `SupportToolsService.php:50` `hasRole('admin')` and `TicketController.php:115` `hasRole('customer')`. No W2 slice adds a role-name check.

### 8.5 Main's verification per batch

1. **Batch A:** §6 rows W2-TICKETS, W2-BILLING, W2-PLATFORM and W2-CATALOG. Run PHP filters, selfchecks, typecheck and lint first, then the E2E specs one at a time.
2. **Batch B:** §6 rows W2-NETWORK, W2-APPROVAL, W2-DASH and W2-SETTINGS. Run batch A's E2E specs again too, because B builds on A's pages.
3. **Batch C:** §6 rows M2-PUSH, M2-TECH and M2-CUST. Run the PHP filters, then Flutter one package at a time, then the QA device smoke when the load allows.
4. **Batch D:** check the OpenAPI diff, then run the wave gate and the security review (§6).
