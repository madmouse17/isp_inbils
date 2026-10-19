# Design: flow-audit fixes, wave 1 (blockers and data integrity)

Status: design for review · Lane `flow-audit-fixes` · Ticket 01-design-w1 · Author: architect `FixDesign-W1` · 2026-10-19
Sources: `docs/plans/FLOW_AUDIT_2026-10-17.md` §5 "Gelombang 1" (30 IDs) and "Kelompok perbaikan berakar sama"; decisions `.scratch/flow-audit-fixes/spec.md` (K1-K37, fixed). Owner rulings received during this design (Main, 2026-10-19): K18 timing (§4.6); roles stay out of this lane (`.scratch/tenant-roles/spec.md`: no role seeding, no role-name checks, new permissions only as catalogue entries).
Sibling: wave 2 is designed by `FixDesign-W2` (`.scratch/flow-audit-fixes/design-w2.md`); every contract agreed with it is in §4.9.

## 1. Problem statement

The flow audit of 2026-10-17 found 5 blockers and 25 data-integrity defects (report §5, wave 1). Five lanes shipped since then (`master-lifecycle`, `integrity-hardening`, `tenant-integrations`, `hardware-panel-ui`, `log-audit`) and settled part of them. This design:

1. verifies every wave-1 ID against the current code (`settled`, `partly settled`, `open`) with file:line evidence (§1.2);
2. designs the change for every open ID, following K1-K37;
3. designs once every "same root" group (report §5) that contains a wave-1 ID, and lists the extra IDs it closes so waves 2 and 3 do not redo them (§1.1, §1.3);
4. splits the work into slices with exact file ownership, at most 4 slices per parallel batch (§8).

Result: of the 30 wave-1 IDs, 5 are settled (F4-D06, F5-D10, F8-D02, F4-U06, F2-D04), 1 needs only its gate recorded (F8-D01), 24 are open or partly settled and each has one owner slice (§8.3).

### 1.1 Scope

Wave-1 IDs (30): F4-D06, F5-D01, F5-D10, F6-D01, F8-D01 (blockers); F4-D07, F8-D02, F4-U06, F8-U01 (stock and material); F5-D02, F5-D04, F5-D05, F7-D25, F12-D17, F4-D16, F4-D17, F4-D19 (money and invoices); F5-D14, F5-D16, F5-D18, F5-D20 (Midtrans); F3-D01, F2-D04, F4-D08, F6-D06, F6-D11 (data stored or shown wrong); F6-D05, F12-D10 (SLA notices); F11-D04, F0-D03 (data across environments and tenants).

Same-root groups with a wave-1 member, designed here once:

| Group (report §5) | Wave-1 member | Extra IDs closed here | Left to later waves |
|---|---|---|---|
| Format uang dan angka | F5-D01 | F2-U03, F4-D04, F9-D05 | F9-D03 entirely (K32 needs a typed-column PDF template; it must reuse the format rule of §5.1) |
| Tombol tampil tanpa izin; penolakan berupa layar mentah | F5-D04 | F6-D10, F4-U05, F10-D02, F11-D07, raw-422 part of F4-D02 | F4-D02 schedule field (K16, wave 3) |
| Pemberitahuan yang tidak ada untuk staf atau pelanggan | F4-D16, F5-D16, F6-D05, F12-D10 | F5-D12, F6-D04, F8-D05, notice part of F4-D13 | F4-D13 confirmation (wave 2, W2); F5-U13 dashboard card (wave 2, W2; its bell already shipped) |
| Isolir dan status RADIUS mati | F5-D20 | F2-D02, F7-D01, F7-D02, F7-D05, F7-D21, F7-D22, F7-U01, F7-U03, F7-U26, F5-D12 (shared), call/WhatsApp/app-link part of F5-U04 | "Bayar tanpa login" of F5-U04 (parked, `docs/PARKING_LOT.md:49`) |
| Lingkungan dev dan alat uji | F0-D03 | F0-D01, F1-D01, F5-D19, F5-U17, F12-D08, F12-D18, F12-D19 | none |

Also closed here because the same owner decision covers them (agreed with W2): F12-U03 (K8) and the notice part of F6-U18 (K8; its two dashboard cards stay wave 2). Closed incidentally because the exact line is rewritten here: F4-D18 ("Biaya pemasangan", S2) and F7-D27 (neutral NAS badge, S10).

### 1.2 Verdict per wave-1 ID

Slice ids (S1-S14) are defined in §8.

| ID | Verdict | Evidence (current code) | Remaining change | Owner slice (contributors) |
|---|---|---|---|---|
| F4-D06 | settled | `Modules/Inventory/app/Http/Requests/Api/V1/StoreTechnicianInventoryRequestRequest.php:21,48-52` takes `work_order_id`; `Modules/Inventory/app/Http/Controllers/Api/V1/Technician/InventoryRequestController.php:55-59` stores `source_type=work_order`; `Modules/SPK/app/Actions/CompleteSpkAction.php:126-218` skips IR-covered material and takes the rest from the request warehouse | none (an uncovered quantity can still raise a stock error: that is F4-D07) | none |
| F5-D01 | open | `app/Support/Money.php:43-57` rounds to cents; `Modules/Billing/app/Services/BillingService.php:202-224` (prorata), `:380`, `:463-486` (lines, per-line tax); `Modules/Billing/app/Services/PaymentAttemptService.php:65` refuses cents; `resources/js/lib/format.ts:14` hides cents while `packages/inbils_core/lib/src/format/formatters.dart:16-17` shows them | K1 whole-rupiah rounding at build; one display rule (§5.1) | S2 (S8) |
| F5-D10 | settled | `tenant-integrations`: per-company key and `/webhooks/midtrans/{company}` (`Modules/Billing/routes/webhook.php:10-11`, `CHANGELOG.md:25-37`) | splitting merchant FHTS is the owner's Midtrans task (K2) | none |
| F6-D01 | open | `Modules/Ticketing/app/Policies/TicketPolicy.php:58-70` allows comments in any status; `Modules/Ticketing/app/Services/TicketService.php:264-273` inserts without status rule or notice; customer `ticket_detail_screen.dart:220-227` always shows the reply box; web `Tickets/Show.tsx:398-403` always shows "Tambah komentar" | K13: closed refuses replies, a customer reply reopens a resolved ticket and notifies | S1 (S9, S12) |
| F8-D01 | settled | `resources/js/Pages/Admin/Inventory/Requests/Show.tsx:286-305` uses axios (commit e5095de); `tests/e2e/inventory-returns.spec.ts` | the full E2E gate after e5095de is still unrecorded: Main's run after B4 records it | S13 (gate record) |
| F4-D07 | open | `bootstrap/app.php:172-178` answers the web with `withErrors(['quantity'])` and no flash; `resources/js/hooks/useToast.ts:16-21` replaces it with "Data gagal disimpan. Periksa input."; `Modules/Inventory/app/Exceptions/InsufficientStockException.php:9-17` names no product or place | named stock message; renderer flashes it; toast shows it | S6 (S7, S9) |
| F8-D02 | settled | request API takes `work_order_id` (see F4-D06); `Modules/Inventory/app/Http/Resources/Api/V1/TechnicianInventoryRequestResource.php:38` returns the SPK; technician `new_request_screen.dart:23-26,71,152-160` sends it | none | none |
| F4-U06 | settled | technician `work_order_items.dart:21-22,127-137` "Minta material"; `new_request_screen.dart:96-98,198-204,233-256` preselects the SPK | none | none |
| F8-U01 | partly settled | opening from the SPK works (above); `technician/lib/features/products/product_picker.dart:186-218` and `Modules/Inventory/app/Http/Resources/Api/V1/TechnicianProductResource.php:17-29` carry no stock | "Tersedia: N" per product for the chosen warehouse; warehouse preselected from the last request | S11 (S6) |
| F5-D02 | open | `Modules/Billing/app/Http/Requests/GenerateInvoicesRequest.php:17` only `date_format:Y-m`; `BillingService.php:81-92` bare `$skipped++` | future month refused; skipped list with reasons | S2 (S8) |
| F5-D04 | open | `resources/js/Pages/Admin/Billing/Invoices/Show.tsx:280-315` gates buttons by status only; `Modules/Billing/app/Http/Controllers/InvoiceController.php:396-413` needs `billing.update`, which `staff` lacks; `BillingService.php:226-242` sends a Rp 0 draft | draft editable by its maker (`billing.create`); `can` flags; send refuses no-item or Rp 0 | S2 (S8) |
| F5-D05 | open | `resources/js/Components/ui/PickerField.tsx:96-100,128-130` keeps the old value on a bad entry and does not stop the form; `resources/js/lib/dateRange.ts:118-135` reads only the company format | numeric dates accepted; a bad entry blocks the surrounding form | S8 |
| F7-D25 | open | same root as F5-D05 (`Billing/Invoices/Create.tsx:105-130`) | same change | S8 |
| F12-D17 | open | `Modules/Billing/app/Http/Requests/StoreInvoiceRequest.php:38`, `UpdateInvoiceRequest.php:17` | due date not before issue date | S2 |
| F4-D16 | open | `app/Actions/CreateInvoiceFromSpkAction.php:43-54,86-88` makes a draft and tells nobody; `app/Queries/Admin/DashboardQuery.php:44-60` has no draft card; `CompleteSpkAction.php:287-303` reads `spk_auto_invoice` | K17 switch, bell to `billing.send` holders, finance card | S2 (S8) |
| F4-D17 | open | `CompleteSpkAction.php:25-49` creates no network account; `Modules/SPK/app/Http/Resources/Api/V1/TechnicianWorkOrderResource.php:57-61` has none; create API exists at `Modules/NetworkAsset/app/Services/NetworkAccountService.php:47-80` | K18 (timing per §4.6) | S4 (S2, S11) |
| F4-D19 | open | `CreateInvoiceFromSpkAction.php:56-72` bills every used material at sell price | K7 setting, "Bahan terpakai" lines | S2 (S8) |
| F5-D14 | open | `Modules/Billing/app/Services/MidtransGateway.php:54-73` has no `credit_card.secure` | K3 | S3 |
| F5-D16 | partly settled | `integrity-hardening`: cash closes open pages (`PaymentAttemptService.php:98-110`), exceptions alert `billing.payment.record` holders (`:351-364`), Tunggakan alert (`Billing/Receivables.tsx:221-236`) | K4 "Dikembalikan" with reference and note, audited | S3 (S8) |
| F5-D18 | partly settled | per-company URL shipped; an unknown order still gets 404: `Modules/Billing/app/Http/Controllers/MidtransWebhookController.php:44-48` (legacy URL) and `:67-71` (signed, per company) | K2: 200 + `midtrans.foreign_order` | S3 |
| F5-D20 | open | `Modules/Billing/app/Services/OverdueSuspensionService.php:80-115` holds the subscription on other invoices; `app/Http/Resources/Api/V1/CustomerSubscriptionResource.php:34-35` names no invoice; customer `home_hero.dart:393-406` shows one invoice | K5 holds in API, "Lunasi juga", "Bayar semua", notice | S3 (S12) |
| F3-D01 | open | `resources/js/Pages/Admin/Employees/Index.tsx:191`, `Vehicles/Index.tsx:121` close without `reset()`; same bug `CustomerAddresses/Index.tsx:173,177`, `Documents/Index.tsx:109`; correct pattern `Organizations/Index.tsx:151-163` | reset on success and close in all four | S9 |
| F2-D04 | settled | `master-lifecycle`: `Modules/Service/Http/Controllers/BandwidthProfileController.php:79`, `SpeedProfileController.php:79`, `SLATierController.php:82` call `LifecycleService::destroyIfUnreferenced` (`app/Services/Core/LifecycleService.php:49-74`); `tests/Feature/Service/ServiceMasterLifecycleTest.php` | none | none |
| F4-D08 | open | `app/Http/Resources/ApprovalInboxItemResource.php:33-43` maps requester to the SPK creator and `submitted_at` to `created_at`; `app/Queries/Admin/ApprovalInboxQuery.php:79-81,98-106,242-262` filters by those | use `work_orders.submitted_at` and the assigned technician | S4 |
| F6-D06 | open | `TicketService.php:255` sets `first_response_at` only in `startWork`; no source column (`Modules/Ticketing/database/migrations/2026_07_01_140001_create_tickets_table.php:28`) | K9: first public staff reply counts, source stored and shown | S1 (S9) |
| F6-D11 | open | `Modules/Reporting/app/Drill/DrillKind.php:292-295` emits `toDateTimeString()` (UTC without offset) that `resources/js/lib/format.ts:60-75` reads as local; `Modules/Reporting/app/Exports/ReportExporter.php:52` writes raw UTC | ISO moments with offset (`Drill.tsx:105` already accepts them); export in company time | S1 |
| F6-D05 | open | `TicketService.php:66` notifies only the customer; `:264-273` no notice on a reply; `Modules/Ticketing/app/Jobs/CheckTicketSlaBreachesJob.php:97-133` warns only `assigned` tickets' handlers | K8 notices to dispatch and managers (`ticket.assign` holders) | S1 |
| F12-D10 | open | `CheckTicketSlaBreachesJob.php:73-82` notifies `created_by` (the customer for app tickets); `Modules/Ticketing/app/Notifications/TicketSlaBreachedNotification.php:33-36` mails; only `resolution` counted | K8: assignee + `ticket.assign` holders, bell + push, `response` counted | S1 |
| F11-D04 | partly settled | `app/Services/Platform/TenantExportBuilder.php:282-306` `mediaOwnedBy` exists but files still come from a folder scan (`:239-264`); no `media.csv`, no roles | K21: files from media rows, `media.csv`, `user_roles.csv` | S7 |
| F0-D03 | open | `config/filesystems.php:35-49` one root per disk; `phpunit.xml:24-58` and `.env.e2e.example:9` override only the database | per-environment storage suffix (§3.3) | S7 |

### 1.3 Verdict per extra ID (groups)

| ID | Verdict | Evidence | Change | Owner (contributors) |
|---|---|---|---|---|
| F2-U03 | open | `Service/Packages/Create.tsx:141-153`, `Edit.tsx:148-160` raw inputs; `Components/composite/MoneyInput.tsx:14-29` exists, unused | use MoneyInput | S8 |
| F4-D04 | open | `Customers/Create.tsx:382-383,965-990` raw "275000.00" | use MoneyInput | S8 |
| F9-D05 | open | `Reports/Business.tsx:107`, `Sla.tsx:137,154,241`, `Technician.tsx:207` write `${v}%` | `formatPercent` / `formatDecimal` | S8 |
| F9-D03 | open, not closed here | `resources/views/exports/table.blade.php:37` raw values | wave 3 (K32) | none |
| F6-D10 | open | `Tickets/Show.tsx:376-395` buttons by status; `Modules/Ticketing/app/Http/Resources/TicketResource.php:67-70` `can` lacks `resolve`, `spawn_spk`; 403 swaps the page (`bootstrap/app.php:180-200`) | `can` flags, hidden buttons, action refusal as toast | S9 (S1, S7) |
| F4-U05 | open | `SPK/Show.tsx:465-506` shows "Mulai" and "Batalkan" by status; no reassign on `assigned` | `can.start/cancel/reassign`, "Ganti teknisi" | S9 (S4) |
| F4-D02 | open (raw 422 only here) | `Modules/SPK/app/Http/Controllers/WorkOrderController.php:211-213` aborts 422 on GET; `bootstrap/app.php:180-200` has no 422 page | redirect to SPK with message; 422 error page | S4 (S7) |
| F10-D02 | partly settled | menu and hub hidden (`resources/js/lib/navigation.ts:361`, `Reports/Index.tsx:29-30`); direct URL gets generic 403 (`Modules/Reporting/app/Http/Requests/ConsolidatedReportRequest.php:21-24`) | redirect with "only in the head company" message | S7 |
| F11-D07 | open | `app/Http/Middleware/EnforceImpersonation.php:165-173` answers GET with raw text | styled error page with message and way back | S7 (S9) |
| F4-D13 | open (notice part) | `Modules/SPK/app/Services/SpkService.php:465-489` submits without notice | `spk_submitted` to `spk.approve` holders | S4 |
| F5-D12 | open | `app/Services/Core/SubscriptionService.php:81-165` audits only; no subscription types in `app/Http/Resources/NotificationResource.php:24-33` | customer bell + push on isolate and reactivate; daily staff summary | S5 (S1, S12) |
| F6-D04 | open | `Modules/Ticketing/app/Notifications/TicketCustomerUpdateNotification.php:17` stages created/assigned/resolved, no `pushesToApp`; no notice on start, reply, close | push + new stages with ticket code | S1 |
| F8-D05 | open | `Modules/Inventory/app/Notifications/*Notification.php` `via()` = `['mail']` (all but Ready); no preference storage, no digest | K28 channels, opt-in daily digest | S6 (S9) |
| F5-U13 | bell settled | `PaymentAttemptService.php:351-364` | dashboard card = wave 2 | W2 |
| F12-U03 | open | = F12-D10 | = F12-D10 | S1 |
| F6-U18 | open (notice part) | `CheckTicketSlaBreachesJob.php:103-110` `status = assigned` only | unassigned tickets warn `ticket.assign` holders; cards = W2 | S1 |
| F2-D02 | partly settled | `Modules/NetworkAsset/app/Services/Radius/RadiusException.php:29` disabled text is Indonesian, `:34` unavailable is English; `Components/composite/NetworkAccessBadges.tsx:24` shows red "Gagal ditulis" when RADIUS is off | Indonesian texts, neutral badge | S5 (S10) |
| F7-D01 | open | `Modules/NetworkAsset/app/Http/Controllers/IsolirPageController.php:19-31` passes `loginUrl`, no WhatsApp or app link; `resources/js/Pages/Isolir/Show.tsx:57-74` blames bills only | K25 page | S10 (S5) |
| F7-U01 | open | same as F7-D01 | same change | S10 |
| F5-U04 | partly (parked part) | `docs/PARKING_LOT.md:49` | call/WhatsApp/app link via F7-D01 | S10 |
| F7-D02 | partly settled | `NetworkAccountController.php:116` "Sinkron ulang dijadwalkan." even when RADIUS is off; dialogs promise disconnect (`NetworkAccessBadges.tsx:107-111`, `NetworkAccounts/Show.tsx:697-702`) | texts by RADIUS state | S5 (S10) |
| F7-D05 | open | `Subscriptions/Show.tsx:564-586`, `Billing/Receivables.tsx:296-318` say nothing of what the customer sees; app: `home_hero.dart:24-40` | helper text in the dialogs | S10 (S8) |
| F7-U03 | open | same plus block modal `NetworkAccessBadges.tsx:107-111` | same change | S10 (S8) |
| F7-D21 | partly settled | English in `NetworkAccountProvisioner.php:138,284`, `RadiusException.php:34`; `app/Http/Controllers/Admin/CompanyIntegrationController.php:80-105` never clears stale errors | Indonesian, clear on enable | S5 |
| F7-D22 | open | `NetworkAccountProvisioner.php:120-145` records the group only after a successful Disconnect; `Modules/NetworkAsset/app/Http/Resources/NetworkAccountResource.php:27` boolean only | record written group, expose `pending_disconnect_at` | S5 (S10) |
| F7-U26 | open | `Modules/NetworkAsset/app/Jobs/ApplyNetworkAccountJob.php:38-42` retries silently; no card or button | "Router belum menjawab sejak" card + "Coba sekarang" | S10 (S4, S5) |
| F0-D01 | open | `scripts/e2e-mutex.mjs:205-214` returns null on Linux; `:1053-1058` refuses to reclaim | Linux start token from `/proc` | S7 |
| F1-D01 | open (dev) | `docker/app/entrypoint.sh:20` links only in production; no Sail step or check | readiness check + docs | S7 |
| F12-D08 | partly settled | by design `app/Notifications/Channels/FcmChannel.php:33-40`; `docs/operations/push-notifications.md:143` hints | one sentence: the process that calls notify() decides, so web and worker both need the key | S7 |
| F12-D18 | open (docs) | not documented | offline test needs `adb reverse --remove tcp:8010` | S7 |
| F12-D19 | open (docs) | not documented | one AVD at a time, load check | S7 |
| F5-D19 | open | `compose.sail.yaml:10-90` no worker or scheduler; `docs/operations/DOCKER.md:34` | opt-in `workers` profile | S7 |
| F5-U17 | open | no command | `scripts/dev-payments.sh` | S7 |

## 2. Affected modules

| Area | Paths (exhaustive list per slice in §8) | What changes | Slices |
|---|---|---|---|
| Core money | `app/Support/Money.php` | `rupiah()` (half-up whole rupiah), `display()` (id-ID, cents only when present) | S2 |
| Billing | `Modules/Billing/app/{Services,Http,Policies,Notifications,Console,Models}`, `Modules/Billing/routes/web.php`, `Modules/Billing/database/migrations`, `resources/views/pdf/invoice.blade.php` | K1, K3, K4, K5, K7, K17, F5-D02, F5-D04, F12-D17, refund route | S2, S3, S5 (summary in the suspend command) |
| SPK to Billing | `app/Actions/CreateInvoiceFromSpkAction.php`, `Modules/SPK/app/Actions/CompleteSpkAction.php` | pure line builder, installation-only fee, materials setting, auto-send or draft notice, K18 safety net | S2 |
| SPK | `Modules/SPK/app/{Services,Http,Policies,Notifications}`, `Modules/SPK/routes/api.php` | K18 trigger on assign, reveal API, submit notice, `can` flags, edit redirect | S4 |
| Network | `Modules/NetworkAsset/app/{Services,Http}` | K18 provisioning method; RADIUS texts, pending disconnect, isolir page props | S4 (service), S5 (rest) |
| Approvals | `app/Queries/Admin/ApprovalInboxQuery.php`, `app/Http/Resources/ApprovalInboxItemResource.php` | F4-D08 | S4 |
| Tickets | `Modules/Ticketing/app/{Services,Http,Jobs,Notifications,Models}`, `Modules/Ticketing/database/migrations` | K8, K9, K13 reply rules, F6-D04 | S1 |
| Reporting | `Modules/Reporting/app/Drill/DrillKind.php`, `Exports/ReportExporter.php`, `Http/Requests/ConsolidatedReportRequest.php`, `Http/Controllers/ConsolidatedReportController.php` | F6-D11, F10-D02 | S1, S7 |
| Notifications core | `app/Http/Resources/NotificationResource.php`, NEW `app/Support/Notifications/StaffRecipients.php`, NEW `app/Notifications/Subscription*Notification.php` | every new type renders here; one recipient helper | S1, S5 |
| Inventory | `Modules/Inventory/app/{Exceptions,Services,Http,Notifications}` | F4-D07 message, F8-U01 stock API, K28 channels and digest | S6 |
| Core subscriptions | `app/Services/Core/SubscriptionService.php`, `app/Http/Controllers/Admin/SubscriptionController.php`, `app/Http/Resources/Api/V1/CustomerSubscriptionResource.php` | F5-D12 notices, toasts by RADIUS state, K5 holds | S5, S3 |
| Company settings and dashboard | `app/Http/Controllers/Admin/CompanyController.php`, `app/Http/Requests/Admin/UpdateCompanySettingsRequest.php`, `app/Services/Core/SetupWizardService.php`, `app/Queries/Admin/DashboardQuery.php` | K7 and K17 keys, `spk_auto_invoice` removed, draft card | S2 |
| Errors and support | `bootstrap/app.php`, `app/Http/Middleware/EnforceImpersonation.php`, `resources/js/Pages/Error.tsx`, `resources/js/hooks/useToast.ts` | action refusals as toasts, 422 page, impersonation page | S7, S9 |
| Platform and tooling | `app/Services/Platform/TenantExportBuilder.php`, `config/filesystems.php`, `phpunit.xml`, `.env.e2e.example`, `scripts/*`, `compose.sail.yaml`, `app/Support/Observability/ReadinessChecker.php`, `README.md`, `docs/operations/{DOCKER,push-notifications}.md` | K21, F0-D03, tooling group | S7 |
| Users and profile | `app/Models/User.php`, `app/Http/Controllers/ProfileController.php`, `routes/web.php`, `routes/console.php`, `database/migrations` | digest preference and command | S6 |
| Web pages | `resources/js/{lib,Components,Pages,types}` | §5 | S8, S9, S10 |
| Technician app | `inbils-mobile/technician/lib/features/{work_orders,products,materials}` | credentials card, stock in picker | S11 |
| Customer app | `inbils-mobile/customer/lib/features/{tickets,home,subscriptions,notifications}` | closed-ticket card, holds and "Bayar semua", subscription notices | S12 |
| QA and docs | `tests/e2e/*`, `CHANGELOG.md`, `docs/user-guide/*`, `docs/business/*`, `docs/api/mobile-v1.openapi.json` | specs, release notes, guides, OpenAPI | S13, S14 |

Module boundary note: `CreateInvoiceFromSpkAction` and `CompleteSpkAction` already cross SPK to Billing by design (header comment of the action); the new K18 calls from `CompleteSpkAction` and `SpkService` into `NetworkAccountService` follow the existing `NetworkAssetService::install` call in `CompleteSpkAction.php:262-268`.

## 3. Data model and migrations

All tables below already carry `company_id` and stay company-scoped; `users` is global by design. No new table. Every migration is additive and reversible.

### 3.1 Migrations (run order = file order)

| # | File (NEW) | Owner | Statement `--pretend` must show | MySQL 8.4 cost | `down()` |
|---|---|---|---|---|---|
| M1 | `Modules/Billing/database/migrations/2026_10_28_100000_add_refund_resolution_to_payment_attempts_table.php` | S3 | `ALTER TABLE payment_attempts MODIFY status ENUM('pending','paid','failed','expired','exception','cancelled_locally','refunded') NOT NULL DEFAULT 'pending'`; `ALTER TABLE payment_attempts ADD resolved_by BIGINT UNSIGNED NULL, ADD resolved_at TIMESTAMP NULL, ADD resolution_reference VARCHAR(100) NULL, ADD resolution_note VARCHAR(500) NULL`; `ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_resolved_by_foreign FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL` | enum append is metadata only (same pattern as `2026_10_23_120000_add_cancelled_locally_to_payment_attempts_status.php`); columns INSTANT; the FK rebuilds a small table | rows `refunded` back to `exception` (the money is again "Perlu dicek"), drop FK and columns, shrink enum |
| M2 | `Modules/Ticketing/database/migrations/2026_10_28_100100_add_response_source_and_reopen_to_tickets_table.php` | S1 | `ALTER TABLE tickets ADD first_response_source VARCHAR(10) NULL AFTER first_response_at, ADD reopened_at TIMESTAMP NULL AFTER resolved_at, ADD response_breached_notified_at TIMESTAMP NULL AFTER response_due_notified_at` (guarded by `Schema::hasColumn`, as `2026_10_21_100100`) | INSTANT | drop the three columns |
| M3 | `Modules/Ticketing/database/migrations/2026_10_28_100200_backfill_first_response_source_on_tickets.php` | S1 | DML only: `UPDATE tickets SET first_response_source = 'start' WHERE first_response_at IS NOT NULL AND first_response_source IS NULL`, chunked by id (1 000) | row updates; today only Mulai sets `first_response_at` (`TicketService.php:255`), so 'start' is exact | `UPDATE tickets SET first_response_source = NULL WHERE first_response_source = 'start'` |
| M4 | `database/migrations/2026_10_28_100300_add_inventory_digest_to_users_table.php` | S6 | `ALTER TABLE users ADD inventory_digest TINYINT(1) NOT NULL DEFAULT '0'` | INSTANT | drop column |

Indexes: the response-breach scan (S1) filters `company_id`, `response_deadline`, which `tickets_company_id_response_deadline_index` already serves (`Modules/Ticketing/database/migrations/2026_10_14_110000_add_response_deadline_to_tickets_table.php:15`). The draft-invoice card (S2) counts `invoices` by `company_id, status`; S2 records `EXPLAIN` of it in its notes and adds no index unless the plan scans the table. The refund lookup goes by primary key.

`./vendor/bin/sail artisan migrate --pretend` must list exactly M1-M4 with the statements above and nothing else.

### 3.2 Settings keys (JSON `companies.settings`, no migration)

| Key | Type, default | Meaning | Validation (`UpdateCompanySettingsRequest`) | Owner |
|---|---|---|---|---|
| `auto_send_installation_invoice` | bool, false | K17 "Kirim tagihan pemasangan otomatis" | `sometimes`, `required`, `boolean` | S2 |
| `bill_installation_materials` | bool, false | K7 "Tagihkan bahan terpakai" | `sometimes`, `required`, `boolean` | S2 |
| `spk_auto_invoice` | removed | replaced by "always draft on installation approval" (§4.4); stale values in old rows are ignored; the system-setting fallback read in `CompleteSpkAction.php:302` goes too | no longer read; the UI stops sending it | S2 |

### 3.3 Storage roots per environment (F0-D03)

One env key, read only in `config/filesystems.php`: `STORAGE_ENV_SUFFIX` (default empty).

- `local.root` = `storage_path('app/private'.env('STORAGE_ENV_SUFFIX', ''))`
- `public.root` = `storage_path('app/public'.env('STORAGE_ENV_SUFFIX', ''))`
- `public.url` = `rtrim(env('APP_URL', 'http://localhost'), '/').'/storage'.env('STORAGE_ENV_SUFFIX', '')`
- `phpunit.xml`: `<env name="STORAGE_ENV_SUFFIX" value="/testing"/>`; `scripts/e2e-server-env.mjs` forces `STORAGE_ENV_SUFFIX=/e2e` (next to the forced `CACHE_STORE`, `:121`); `.env.e2e.example` documents it.

Why a suffix and not a path prefix: tests and code address files as `companies/{id}/...` relative to the disk root (`tests/Feature/Platform/TenantExportTest.php:334-353`, `tests/Feature/Platform/PurgeTenantCommandTest.php:55-92`, `tests/Feature/CompanyProfileTest.php:52`); a root suffix keeps every one of them unchanged, and the public URL keeps working through the existing `public/storage` link because `/storage/e2e/...` lives under it. Dev and production keep their current roots (suffix empty), so no file moves.

## 4. API contracts

Conventions kept: ULID `public_id` for every id on the wire; web writes answer with redirects and flash; API errors use `RenderApiError` `{message, code, errors?, request_id}`; Indonesian user-facing text; no em dash in copy.

### 4.1 Web routes (new or changed)

| Method, URI, name | Auth and permission | Request | Response | Errors | Slice |
|---|---|---|---|---|---|
| POST `admin/payment-attempts/{attempt}/refund`, `admin.payment-attempts.refund` (NEW, `whereUlid('attempt')`, inside the group of `Modules/Billing/routes/web.php:10`) | `auth`, `verified`, `redirect.app.only`, `require.has.company`; `Gate::authorize('billing.payment.reverse')`; attempt of the current company (`RecordNotFound`) | `RefundPaymentAttemptRequest`: `reference` required string max 100, `note` required string max 500 | `back()->with('success', 'Pembayaran ditandai dikembalikan.')` | 422 `refund` "Hanya pembayaran online berstatus Perlu dicek yang belum dibukukan yang bisa ditandai dikembalikan."; 404 other company | S3 |
| PATCH `profile/notification-preferences`, `profile.notifications.update` (NEW, in the `auth` group of `routes/web.php:26-30`) | `auth`; blocked during support access by `EnforceImpersonation` like every `profile.*` | `UpdateNotificationPreferencesRequest`: `inventory_digest` required boolean | `back(303)->with('success', 'Preferensi notifikasi disimpan.')` | 422 validation | S6 |
| POST `admin/invoices/{invoice}/items`, DELETE `.../items/{item}` (changed) | `Gate::authorize('update', $invoice)` instead of `billing.update`; `InvoicePolicy::update` = `billing.update`, or `billing.create` on a draft | unchanged | unchanged | 403 as today for others | S2 |
| POST `admin/invoices/{invoice}/send` (changed) | unchanged (`billing.send`) | none | unchanged | 422 "Tagihan tanpa item atau bernilai Rp 0 tidak bisa dikirim." | S2 |
| POST `admin/invoices/generate` and its preview (changed) | unchanged | `period` must be `<=` the current month in the company timezone: "Periode tagihan paling lambat bulan berjalan." | preview JSON gains `skipped_rows` and `is_current_period` (§4.7) | 422 | S2 |
| POST `admin/invoices`, PUT `admin/invoices/{invoice}` (changed) | unchanged | `due_date` `after_or_equal:issue_date` (update: against the stored issue date when absent) "Jatuh tempo tidak boleh sebelum tanggal terbit." | unchanged | 422 | S2 |
| POST `admin/invoices/{invoice}/payments` (changed) | unchanged | `amount` whole rupiah: `regex:/^\d+(\.0{1,2})?$/` "Jumlah pembayaran harus rupiah penuh, tanpa sen." | unchanged | 422 | S2 |
| GET `admin/spk/{wo}/edit` (changed) | unchanged | none | not editable: `redirect()->route('admin.spk.show', $wo)->with('error', 'SPK hanya bisa diubah saat berstatus draf atau menunggu penugasan.')` | none | S4 |
| POST `admin/network-accounts/{account}/resync` (changed) | unchanged | none | RADIUS off: `with('warning', 'Perubahan disimpan, tetapi belum dikirim ke router karena RADIUS belum dikonfigurasi untuk perusahaan ini.')`; on: `with('success', 'Sinkron ulang dimulai. Sesi pelanggan diputus setelah router menjawab.')`; an existing `pending_disconnect_at` is kept (the "sejak" time of §5.4) | unchanged | S5 (S4 keeps the timestamp in `NetworkAccountService::resync`) |
| POST block / unblock of a subscription (changed toasts) | unchanged | none | block, RADIUS on: "Blokir disimpan. Sesi pelanggan diputus setelah router menjawab."; off: "Blokir disimpan, tetapi belum dikirim ke router karena RADIUS belum dikonfigurasi." (same pattern for "Buka blokir") | unchanged | S5 |
| GET `admin/reports/consolidated` from a subsidiary (changed) | permission holder in a subsidiary context | none | `redirect()->route('admin.reports.index')->with('warning', 'Metrik Konsolidasi hanya tersedia di perusahaan induk {nama induk}. Ganti perusahaan lewat pemilih di menu samping.')` | without the permission: 403 as today | S7 |
| GET `isolir/{company:code}` (changed props, §4.8) | public, `throttle:isolir` | none | Inertia `Isolir/Show` | as today | S5 |
| Any Inertia non-GET answered 403, 404 or an `abort(422)` (changed, `bootstrap/app.php`) | n/a | n/a | `back(303 for PUT/PATCH/DELETE, else 302)->with('error', $message)`: 403 always "Anda tidak punya izin untuk tindakan ini." (no policy text leaks); 404 "Data tidak ditemukan atau sudah berubah."; 422 the abort message | `ValidationException` untouched (Inertia errors bag); 409, 419 and 5xx keep today's handling | S7 |
| Any GET answered `abort(422)` (changed) | n/a | n/a | Inertia `Error` page, status 422, prop `message` = the abort message | n/a | S7 |
| GET `profile*` during support access (changed, `EnforceImpersonation::deny`) | n/a | n/a | GET: Inertia `Error` page, status 403, `message` "Pengaturan akun pribadi tidak tersedia selama akses dukungan." (its existing text); non-GET unchanged | n/a | S7 |

`InsufficientStockException` on the web (`bootstrap/app.php:172-178`, S7): `back()->withInput()->withErrors(['quantity' => $msg])->with('error', $msg)`; JSON branch unchanged. With S9's `useToast` change the user sees exactly one toast, the named message.

### 4.2 Mobile API (all additive; `docs/api/mobile-v1.openapi.json` regenerated by S14)

| Method, URI | Auth | Request | Response | Errors | Slice |
|---|---|---|---|---|---|
| POST `api/v1/technician/work-orders/{wo}/network-credentials` (NEW, name `technician.work-orders.network-credentials`, inside the `can:assignedTo,wo` group of `Modules/SPK/routes/api.php:15-24`) | technician token; caller is `assigned_to`; status `assigned` or `in_progress`; type `installation`; `throttle:api-credentials` (NEW limiter, 5 a minute per user, beside `api-pay` in `app/Providers/AppServiceProvider.php:363`) | none | 200 `{data: {username: string, password: string}}`, header `Cache-Control: no-store`; audit `network_account.secret_revealed` with `work_order_id`, `via: technician_app` (through `NetworkAccountService::reveal`, which audits before it returns) | 404 not the assignee or other company (uniform); 409 `work_order_closed` "SPK ini sudah tidak aktif."; 422 `credentials_not_ready` "Akun PPPoE belum dibuat. Hubungi kantor."; 429 | S4 |
| GET `api/v1/technician/work-orders/{wo}` | unchanged | none | adds `network_account: {username: string, sync_status: 'pending'|'synced'|'error', can_reveal: bool} | null`; only for type installation and only when the caller is the assignee; never the secret; list endpoints return `null` | none | S4 |
| GET `api/v1/technician/products` | unchanged | optional `warehouse_id` (ULID of a location the warehouses endpoint offers) | each product adds `available: string|null` (`quantity - reserved_quantity` at that location, `"0"` without a stock row; `null` without `warehouse_id`) | 422 unknown warehouse | S6 |
| GET `api/v1/technician/warehouses` | unchanged | none | each adds `last_used: bool` (warehouse of the caller's latest inventory request) | none | S6 |
| GET `api/v1/customer/subscriptions`, `/{id}` | unchanged | none | adds `reactivation_holds: list<{id, number, due_date, sisa}>`, oldest due first; `[]` unless `status = suspended` and `suspension_reason = overdue` | none | S3 |
| GET `api/v1/customer/tickets/{id}` (and list) | unchanged | none | adds `can_reply: bool` (false when `closed`), `reopened_at: string|null` | none | S1 |
| POST `api/v1/customer/tickets/{id}/comments`, attachment upload on a customer ticket | unchanged | unchanged | resolved ticket: comment saved and ticket reopened (§4.5) | closed: 422 `ticket_closed` "Tiket ditutup. Masih bermasalah? Buat tiket baru." (`ApiException`) | S1 |
| GET `api/v1/notifications` | unchanged | none | new `type` values and subject `subscription` (§4.6); a push carries the same | none | S1 |
| POST `webhooks/midtrans/{company}` and legacy `webhooks/midtrans` | Midtrans signature (per company) | unchanged | unknown `order_id`: 200 `OK` + `Log::info('midtrans.foreign_order', ['order_id' => Str::limit(order_id, 64, ''), 'verified' => bool])`; `verified` true on the company URL (signature checked first), false on the legacy URL (no key to check; nothing written) | unchanged for known orders | S3 |

Snap payload (S3): `MidtransGateway::createSnapTransaction` adds `'credit_card' => ['secure' => true]`.

### 4.3 Billing rules

1. `Money::rupiah(string|int|float): string` returns half-up whole rupiah as a 2-decimal string ("147702.00"); it rounds from full precision with one guard digit (no double rounding through cents). `Money::display(string): string` returns "147.702" or "147.701,62" (cents only when non-zero) for Blade and PDF.
2. Every built amount is whole rupiah: `prorationFor` (`BillingService.php:219-221`), the preview `amount`, `tax`, `total` (`:95-106`), `addItem` line total (`:380`), `recalculate` line total and per-line tax (`:471-475`), `CreateInvoiceFromSpkAction::linesFor`. Totals are sums of whole values.
3. Payments: manual amounts must be whole rupiah (§4.1). `PaymentAttemptService::unpayableReason` keeps its cents guard for legacy invoices only.
4. Send guard: `BillingService::send` refuses a draft with no item or `total <= 0` (422, §4.1).
5. Generate: period `<=` current month (company timezone); dry run returns `skipped_rows` (max 50) with reasons `already_issued` "Tagihan periode ini sudah ada", `zero_amount` "Nominal Rp 0", `not_active` "Belum aktif pada periode ini" (status `pending`, or activation after the period end), `suspended` "Ditangguhkan" (status `suspended`).
6. `InvoicePolicy::update` (and `edit`): `billing.update`, or `billing.create` while `status = draft`. Controller `addItem`/`removeItem` call `Gate::authorize('update', $invoice)`.

### 4.4 Installation invoice (K7, K17) and its builder

- `CreateInvoiceFromSpkAction::linesFor(WorkOrder $wo): list<array{product_id: ?int, description: string, quantity: string, unit_price: string, tax_rate: float, line_total: string, tax_amount: string}>`: pure, no writes. Lines: when `bill_installation_materials` is on, one line per used material `"Bahan terpakai: {nama barang}"` at sell price; when the type is `installation` and the subscription's `otc_installation_fee > 0`, one line `"Biaya pemasangan"`. Whole rupiah.
- `execute(int $workOrderId): ?Invoice`: returns null when `linesFor` is empty (nothing created). Manual `BillingService::createFromSpk` turns null into 422 "SPK ini tidak punya biaya atau bahan yang bisa ditagihkan." The English aborts of the action become Indonesian: "SPK harus selesai sebelum tagihan dibuat.", "Tagihan untuk SPK ini sudah ada."
- `CompleteSpkAction::createInvoice` (on approval): only for type `installation`, always (no `spk_auto_invoice`). Invoice created and `auto_send_installation_invoice` on: `BillingService::send($invoice)` in the same transaction. Off: after commit, `InstallationInvoiceDraftNotification` to `StaffRecipients::holding($companyId, 'billing.send')`.
- `CompleteSpkAction` also calls `NetworkAccountService::provisionForInstallation($workOrder)` after `activateSubscription` (K18 safety net, §4.6).
- Dashboard (`DashboardQuery::sections`): new `draft_invoices` section, group `finance`, title "Tagihan draf menunggu dikirim", count of the company's `invoices` with `status = draft`, `href` `admin.invoices.index?status=draft`, severity `warning`, extra `total` (decimal string, sum of their totals); shown to `billing.send` holders.

### 4.5 Ticket rules (K8, K9, K13, F6-D04, F6-D11)

- K13 reply rules (`TicketService`): `addCustomerReply(Ticket, array $data, User $customerUser)` is the customer API path. Closed: refuse (422 `ticket_closed`). Resolved: save the comment, then `reopen()`: `status` = `assigned` when `assigned_to` is set, else `open`; `resolved_at` and `resolution_note` cleared (the note goes into the audit row `ticket.reopened`, properties `previous_status`, `resolution_note`, `by: customer`); `reopened_at = now()`; deadlines unchanged. Open, assigned, on_progress: save. Then notify (§4.6). Staff comments on a closed ticket are refused too: "Tiket sudah ditutup." Staff reopen with a reason is F6-D02 (wave 3) and reuses `TicketService::reopen()`. Customer attachment upload follows the same closed rule.
- K9: in the staff comment path, the first comment with `is_internal = false` sets `first_response_at = now()` and `first_response_source = 'reply'` when `first_response_at` is null; `startWork` sets them with `'start'` only when null. The customer path never sets them. The customer path is the customer API controller and everything else is staff, so no role name is checked (tenant-roles rule).
- K8 SLA job (`CheckTicketSlaBreachesJob`): (a) resolution breach: recipients = active assignee + `StaffRecipients::holding($companyId, 'ticket.assign')`, deduplicated, never `created_by`; (b) response breach (new): `response_deadline <= now()`, `first_response_at` null, status `open` or `assigned`, marker `response_breached_notified_at` claimed under the row lock, `TicketSlaBreachedNotification` with `kind = 'response'`, same recipients; (c) response due (≤ 30 minutes ahead, deadline still in the future): `assigned` → assignee; `open` → `ticket.assign` holders; marker `response_due_notified_at` as today.
- New ticket (any channel): after commit, `TicketStaffNotification('created')` to `ticket.assign` holders except the actor.
- Customer reply: `TicketStaffNotification('customer_replied')`, or `('reopened')` when it reopened the ticket, to the assignee + `ticket.assign` holders.
- F6-D04 customer notices (`TicketCustomerUpdateNotification`, `pushesToApp(): true`): stages `created`, `assigned`, `started` (new, from `startWork`), `replied` (new, a public staff comment), `resolved`, `closed` (new); WhatsApp keeps sending only the stages it sends today (no new template).
- F6-D11: `DrillKind::moment()` returns `$moment?->toIso8601String()` (offset kept; `Drill.tsx:105` already matches it and formats in the company zone); `ReportExporter` writes drill moments as `Y-m-d H:i` in `TimeBuckets::timezoneOf($companyId)` (`Modules/Reporting/app/Support/TimeBuckets.php:94`).
- Resource fields: web `TicketResource`: `can.resolve`, `can.spawn_spk` (existing policy abilities), `can.comment` (status not closed), `first_response_source`, `reopened_at`; `TechnicianTicketResource`: `first_response_source`; `CustomerTicketResource`: `can_reply`, `reopened_at`.

### 4.6 Notification matrix (the single change of the "Pemberitahuan" group) and K18

Recipient helper (NEW, S1, written first): `App\Support\Notifications\StaffRecipients::holding(int $companyId, string $permission, ?int $exceptUserId = null): Collection<int, User>` = active users of the company holding the permission (today `users.company_id` + `->permission()`, the pattern of `PaymentAttemptService.php:355-360`). It is the one place the tenant-roles lane adapts. S3 and S6 move the four existing inline queries (`PaymentAttemptService.php:355-360`, `InventoryRequestService.php:590-592`, `MaterialReturnService.php:290-292,420-426`) onto it.

| Event | Recipients | Channels | `type` (subject) | Class | Status | Slice |
|---|---|---|---|---|---|---|
| Ticket created | `ticket.assign` holders except actor | bell, push | `ticket_created` staff variant (ticket) | NEW `TicketStaffNotification('created')` | new | S1 |
| Customer reply | assignee + `ticket.assign` holders | bell, push | `ticket_customer_replied` (ticket) | `TicketStaffNotification('customer_replied')` | new | S1 |
| Customer reply reopens a resolved ticket | same | bell, push | `ticket_reopened` (ticket) | `TicketStaffNotification('reopened')` | new | S1 |
| Response due ≤ 30 min | assigned: assignee; open: `ticket.assign` holders | bell, push | `ticket_first_response_due` (ticket) | existing | changed | S1 |
| Response target missed | assignee + `ticket.assign` holders | bell, push | `ticket_sla_breached`, data `kind: response` (ticket) | existing | new case | S1 |
| Resolution target missed | assignee + `ticket.assign` holders; never the creator or customer | bell, push (mail removed) | `ticket_sla_breached`, `kind: resolution` (ticket) | existing | changed | S1 |
| Ticket created, assigned, started, replied, resolved, closed (customer) | the customer | bell, push (+ WhatsApp as today) | `ticket_created`, `ticket_assigned`, `ticket_started`, `ticket_replied`, `ticket_resolved`, `ticket_closed` (ticket) | existing `TicketCustomerUpdateNotification` | changed | S1 |
| SPK submitted for review | `spk.approve` holders except the submitter | bell, push | `spk_submitted` (work_order) | NEW `Modules/SPK/app/Notifications/WorkOrderSubmittedForReviewNotification` | new | S4 |
| Installation invoice left as draft (K17 off) | `billing.send` holders | bell, push | `invoice_draft_pending` (invoice) | NEW `Modules/Billing/app/Notifications/InstallationInvoiceDraftNotification` | new | S2 |
| Online payment cannot be booked | `billing.payment.record` holders | bell, push | `payment_attempt_exception` (invoice) | existing | settled | none |
| Payment received while other invoices still hold reactivation | the customer | bell, push, WhatsApp as today | `payment_received`, data `holding_invoice_numbers` | existing `PaymentReceivedNotification` | changed | S3 |
| Subscription isolated (overdue suspension, manual suspension, block) | the customer | bell, push | `subscription_isolated`, data `cause: overdue|manual|blocked`, `invoice_numbers` (subscription) | NEW `app/Notifications/SubscriptionStatusNotification` | new | S5 |
| Subscription active again (reactivate, unblock) | the customer | bell, push | `subscription_reactivated` (subscription) | same class | new | S5 |
| Daily auto-isolation run isolated n > 0 | `customer.subscription.suspend` holders | bell | `subscriptions_isolated_summary` (none; web link) | NEW `app/Notifications/SubscriptionsIsolatedSummaryNotification` | new | S5 |
| Inventory request submitted | `inventory.request.approve` holders | bell (mail removed) | `inventory_request_submitted` | existing | changed | S6 |
| Request approved / rejected / ready | the requesting technician | bell, push (mail removed) | `inventory_request_approved` / `_rejected` / `_ready` | existing | changed | S6 |
| Return declared / exception | `inventory.return.receive` / `inventory.return.resolve` holders | bell (mail removed) | `material_return_declared` / `_exception` | existing | changed | S6 |
| Return rejected | the technician | bell, push (mail removed) | `material_return_rejected` | existing | changed | S6 |
| Daily digest 07:00 WIB (00:00 UTC) | users with `inventory_digest = true` holding `inventory.request.approve`, `.prepare`, `.issue`, `inventory.return.receive` or `.resolve` | mail only | none | NEW `Modules/Inventory/app/Notifications/InventoryDigestNotification` (mail) sent by NEW command `inventory:send-digest` | new | S6 |

Titles and messages (`NotificationResource`, S1; the customer variant is chosen as today by `notifiable_type`):

| type | Title | Message |
|---|---|---|
| `ticket_created` (staff) | Tiket baru | "Tiket {kode} dari {pelanggan} menunggu penugasan." |
| `ticket_created` (customer) | Tiket dibuat | "Tiket {kode} sudah tercatat dan menunggu penanganan." |
| `ticket_assigned` (customer) | Petugas ditugaskan | "Petugas sudah ditugaskan untuk tiket {kode}." |
| `ticket_started` | Tiket mulai ditangani | "Petugas mulai menangani tiket {kode}." |
| `ticket_replied` | Balasan baru | "Petugas membalas tiket {kode}." |
| `ticket_resolved` | Tiket selesai | "Tiket {kode} sudah diselesaikan. Masih bermasalah? Balas tiket ini." |
| `ticket_closed` | Tiket ditutup | "Tiket {kode} sudah ditutup." |
| `ticket_customer_replied` | Pelanggan membalas | "Pelanggan membalas tiket {kode}." |
| `ticket_reopened` | Tiket dibuka kembali | "Pelanggan membalas tiket {kode} yang sudah diselesaikan, jadi tiket dibuka kembali." |
| `ticket_sla_breached` kind response | Batas respons terlewat | "Tiket {kode} belum direspons melewati batas SLA." |
| `ticket_sla_breached` kind resolution | SLA tiket terlampaui | "Tiket {kode} melewati batas penyelesaian SLA." |
| `spk_submitted` | SPK menunggu review | "{kode SPK} dari {teknisi} menunggu persetujuan." |
| `invoice_draft_pending` | Tagihan draf menunggu dikirim | "Tagihan {nomor} untuk {pelanggan} sebesar Rp {total} menunggu dikirim." |
| `payment_received` with holds | Pembayaran diterima | "Pembayaran {nomor} sudah kami terima. Layanan aktif kembali setelah {nomor lain} dilunasi." |
| `subscription_isolated` overdue | Layanan diisolir | "Layanan {kode} diisolir karena tagihan {nomor} lewat jatuh tempo. Bayar agar layanan aktif kembali." |
| `subscription_isolated` manual or blocked | Layanan dibatasi | "Layanan {kode} sedang dibatasi oleh {nama ISP}. Hubungi kami untuk informasi." |
| `subscription_reactivated` | Layanan aktif kembali | "Layanan {kode} sudah aktif kembali." |
| `subscriptions_isolated_summary` | Isolir otomatis hari ini | "{n} langganan diisolir otomatis karena tunggakan." |

Web bell links (`NotificationResource::detailUrl`, S1): ticket types above link to `admin.tickets.show` (existing check), `spk_submitted` to `admin.spk.show` when `Gate::allows('view', $wo)`, `invoice_draft_pending` to `admin.invoices.show` when `Gate::allows('view', $invoice)`, `subscriptions_isolated_summary` to `admin.billing.receivables` when the user can `billing.view`; same company check as today. The subject map gains `subscription_id → subscription`. `TYPES` and the `@var` docblock list every new value (the OpenAPI enum follows).

K18 (Main, 2026-10-19): the account is created, idempotently, when the installation SPK is first ASSIGNED (`SpkService::assign`, inside its transaction after the SPK row lock); `CompleteSpkAction` creates it only if still missing. `NetworkAccountService::provisionForInstallation(WorkOrder $wo): ?NetworkAccount`: no-op unless type `installation`, the subscription exists, is not terminated and has no account; local part from the customer code (`Str::lower`, characters outside `[a-z0-9._-]` dropped, cut to 40), suffix `-2`, `-3` when taken; `service_type = pppoe`; generated secret (existing `create`); audit `network_account.created` gains `via: installation_spk`. A guard refusal of `create` (no RADIUS profile name on the speed profile, no realm) does not block assignment or approval: it is caught, logged as `network_account.auto_create_skipped` (reason only), and the SPK page and the app show "Akun PPPoE belum dibuat" (staff create it by hand as today). Reveal rules: only the current assignee, only while `assigned` or `in_progress`, behind the explicit "Tampilkan" call, audited, rate-limited; the secret never appears in push payloads, list responses, logs or the app's offline cache; reassignment ends the previous technician's access because every check is against the current assignee.

### 4.7 Inertia props (new or changed)

| Page | Prop | Shape | Producer → consumer |
|---|---|---|---|
| `Admin/Billing/Invoices/Show` | `can` | `{update, send, cancel, record_payment, reverse_payment, refund}` (bool; `refund` = `billing.payment.reverse`) | S2 → S8 |
| same | `online_payments.attempts[]` | adds `status: 'refunded'` and `refund: {at: string, by: string|null, reference: string, note: string} | null` | S3 (resource) → S8 |
| `Admin/Billing/Invoices` preview JSON | response | adds `skipped_rows: list<{subscription_code, customer, reason, reason_label}>`, `is_current_period: bool` | S2 → S8 |
| `Admin/Billing/Receivables` | exceptions items | adds `id` (attempt ULID); page adds `can.refund: bool`, `autoReactivate: bool` | S2 (controller) → S8 |
| `Admin/Company/Settings` | `settings` | adds `auto_send_installation_invoice`, `bill_installation_materials`; drops `spk_auto_invoice` | S2 → S8 |
| `Admin/Dashboard/Index` | `sections[]` | new `{key: 'draft_invoices', group: 'finance', title, count, href, severity, total: string}` | S2 → S8 |
| `Admin/Tickets/Show` | `ticket` | `can: {start, close, resolve, spawn_spk, comment}`, `first_response_source: 'reply'|'start'|null`, `reopened_at: string|null` | S1 → S9 |
| `Admin/SPK/Show` | `can` | `{uploadEvidence, start, cancel, reassign}` (`start`: policy, only the assigned technician; `cancel`: `spk.cancel` and status not final; `reassign`: `spk.reassign` and status `assigned`/`in_progress`) | S4 → S9 |
| `Admin/NetworkAccounts/Show` | `account` | adds `pending_disconnect_at: string|null` (ISO); `radiusEnabled` exists (`NetworkAccountController.php:69`) | S5 → S10 |
| `Admin/NasDevices/Index` | `radiusEnabled` | bool (Show already has it, `NasDeviceController.php:94`) | S5 → S10 |
| `Admin/Subscriptions/Show` | `radiusEnabled` | bool | S5 → S10 |
| `Isolir/Show` | page props | `{name, phone: string|null, whatsapp_url: string|null ("https://wa.me/{digits}"), email, website, app_url: string|null (SettingService::playStoreUrl('customer'))}`; `loginUrl` removed | S5 → S10 |
| `Profile/Edit` | `notificationPreferences` | `{inventory_digest: bool, eligible: bool}` (eligible = holds approve, prepare, issue, receive or resolve) | S6 → S9 |
| `Error` | `message` | optional string (422 aborts and the impersonation denial) | S7 → S9 |

### 4.8 Isolir page (K25)

One page per company (the router redirect carries no customer), with both texts: unpaid bills (pay in the app; service returns by itself after payment) and limited by the ISP (call or WhatsApp). Buttons: Telepon (`tel:`), WhatsApp (`whatsapp_url`), "Buka aplikasi {nama ISP}" (`app_url`); each hidden when its value is missing; no login link. Router-specific variants are left out (§7, OD3).

### 4.9 Contracts agreed with wave 2 (`FixDesign-W2`)

- Settings keys of §3.2; F1-U05 (W2) asks `auto_send_installation_invoice`, `bill_installation_materials` and `auto_suspend_enabled` once at tenant creation.
- `CreateInvoiceFromSpkAction::linesFor()` (§4.4) is public and pure; W2's F4-U11 sums `line_total + tax_amount`.
- F4-D18 is closed here ("Biaya pemasangan"); W2 takes F4-D10 and F12-D12.
- The stock message of §5.6 is reused by W2's F8-D03 and F8-U02.
- F5-U01 (prefill the open balance) is W2; after W1 every balance is whole rupiah.
- Payment attempt statuses: `pending, paid, failed, expired, exception, cancelled_locally` + `refunded` ("Dikembalikan"); W2 reads them (F5-U13, F5-U14).
- Ticket fields `first_response_source`, `reopened_at`, `can_reply`; K9 counts any public staff comment, so W2's quick replies (F6-U09) count.
- Notification types and subjects of §4.6; the F6-U18 cards and the F5-U13 card are W2; F12-U03 is W1.

## 5. UI contract

House rules for every UI slice: `Components/ui` and `Components/composite` only, Lucide icons, Indonesian copy without em dash, light and dark mode, touch targets at least 44 px (48 dp in the apps), visible focus, AA contrast, no layout shift on load. Flutter slices reuse the card and empty-state style that `MobileLook` adds to `packages/inbils_core` (name per its final report) and start only after it has landed.

### 5.1 Money and number format (one rule for web, apps, PDF)

- `resources/js/lib/format.ts`: `formatRupiah(value, company)` prints "Rp 147.702" for whole values and "Rp 147.701,62" when the stored value has cents (it never rounds a stored amount). New `formatPercent(value, digits = 1)` → "63,6%"; `formatDecimal(value, digits = 1)` → "4,8"; both `Intl.NumberFormat('id-ID')`, trailing zeros trimmed, null → "-".
- Dart `packages/inbils_core/lib/src/format/formatters.dart:8-18` already follows the rule (no change). PDF uses `Money::display()` (§4.3).
- Report cards and tables (`Reports/Business.tsx:107`, `Sla.tsx:137,154,241`, `Technician.tsx:207`) use `formatPercent`/`formatDecimal` instead of template literals.
- Money fields: `Service/Packages/Create.tsx:141-153`, `Edit.tsx:148-160` (`price_mrc`, `price_otc`) and `Customers/Create.tsx:965-990` (`mrc_amount`, `otc_installation_fee`) use `Components/composite/MoneyInput` (number value; the form keeps sending a decimal string, `String(value)`); `Customers/Create.tsx:382-383` converts the package price with `Number(...)` when it fills the fields.

### 5.2 Invoices and billing (S8)

| Page / component | Change | States and copy |
|---|---|---|
| `Billing/Invoices/Show.tsx:280-315` | "Tambah item" and item delete only with `can.update`; "Kirim tagihan" only with `can.send`; "Batalkan tagihan" only with `can.cancel`; on a draft with no item or Rp 0, "Kirim tagihan" is disabled with the reason under it | "Tambahkan minimal satu item sebelum mengirim." |
| same, online payments panel (`:486-508`) | an `exception` attempt shows "Tandai dikembalikan" when `can.refund`; a `refunded` attempt shows the badge "Dikembalikan" and "Dikembalikan {waktu} oleh {nama} · Ref {referensi}" | dialog (NEW `RefundAttemptDialog`): title "Tandai pembayaran dikembalikan"; fields "Nomor referensi pengembalian" (required, max 100), "Catatan" (required, max 500); help "Isi setelah uang dikembalikan lewat dashboard Midtrans atau transfer bank. Tindakan ini dicatat di log audit."; button "Simpan"; server errors under the fields |
| `Billing/Receivables.tsx:221-236` (red alert) | each exception row gets the same action when `can.refund` | same `RefundAttemptDialog` |
| `Billing/Receivables.tsx:296-318` (Isolir dialog, F7-D05/F7-U03) | helper text above the reason | `autoReactivate` true: "Pelanggan melihat status Diisolir dengan tagihan dan tombol Bayar. Layanan aktif kembali otomatis setelah semua tagihan yang jatuh tempo dilunasi." false: "Pelanggan melihat status Diisolir dengan tagihan dan tombol Bayar. Layanan aktif kembali setelah staf mengaktifkannya kembali." |
| `Billing/Invoices/GenerateDialog.tsx:103-124` | skipped list under the summary (code, customer, reason label), collapsed after 5 rows; for the current period a warning and a required checkbox before the button | "Lihat yang dilewati"; warning "Periode berjalan belum selesai. Tagihan langsung terkirim ke pelanggan."; checkbox "Saya mengerti, buat tagihan periode berjalan" |
| `Components/ui/PickerField.tsx` + `lib/dateRange.ts` (F5-D05, F7-D25, every date field) | `parseDay` also accepts `d/M/yyyy`, `dd/MM/yyyy`, `d-M-yyyy`, `yyyy-MM-dd`; while the typed text is invalid the field stops the surrounding form's submit (a native `submit` listener on `inputRef.current.form` that calls `preventDefault()` and `stopPropagation()` and focuses the field; attached only while invalid, removed on unmount) | existing "Format tanggal tidak valid. Contoh: {contoh}." stays |
| `Company/Settings.tsx:84-104` | remove "Buat tagihan otomatis saat SPK selesai"; add two switches in the Tagihan section | "Kirim tagihan pemasangan otomatis", help "Mati: tagihan pemasangan dibuat sebagai draf saat SPK pemasangan disetujui, lalu staf penagihan diberi tahu untuk mengirimnya."; "Tagihkan bahan terpakai", help "Mati: bahan yang dipakai teknisi termasuk biaya pemasangan. Nyala: bahan ditagihkan per baris dengan label Bahan terpakai." |
| `Dashboard/components/SectionCard.tsx:40-61` + `types/dashboard.ts:34-51` | case `draft_invoices` → detail "Nilai" `formatRupiah(section.total)` | title from the server |
| `lib/statuses/payment.ts` | `refunded` → "Dikembalikan", neutral tone | - |

### 5.3 Tickets, SPK, errors, dialogs, profile (S9)

| Page / component | Change | Copy |
|---|---|---|
| `hooks/useToast.ts:16-21` | no generic toast when `flash.error` is present (the server already said why) | - |
| `Pages/Error.tsx` | shows `message` when given; adds "Kembali" (`window.history.back()`, shown when `history.length > 1`) beside "Ke dasbor" for 403, 404, 422; status 422 copy | 422 title "Tidak bisa dilanjutkan"; body = `message` |
| `Tickets/Show.tsx:376-403` | "Selesaikan" only with `can.resolve`; "Buat SPK" only with `can.spawn_spk`; "Tambah komentar" only with `can.comment`; a closed ticket shows a muted line; "Respons pertama" shows its source; a reopened ticket shows a badge with the time | "Tiket sudah ditutup. Komentar baru tidak bisa ditambahkan."; source "lewat balasan" / "lewat tombol Mulai"; badge "Dibuka kembali {waktu}" |
| `SPK/Show.tsx:455-506` | "Mulai" only with `can.start`; "Batalkan" only with `can.cancel`; "Ganti teknisi" on `assigned`/`in_progress` with `can.reassign`, opening the existing `Dispatch/components/AssignDialog.tsx` | "Ganti teknisi" |
| `Employees/Index.tsx:191`, `Vehicles/Index.tsx:121`, `CustomerAddresses/Index.tsx:173,177`, `Documents/Index.tsx:109` | one `close()` that calls `reset()` and `clearErrors()` then closes, used by `onSuccess`, the dialog's `onClose` and "Batal" (pattern `Organizations/Index.tsx:151-163`) | - |
| `Profile/Edit.tsx` + NEW `Profile/Partials/NotificationPreferencesForm.tsx` | shown only when `notificationPreferences.eligible`; one switch saved with `PATCH profile.notifications.update` | heading "Notifikasi email"; switch "Ringkasan harian permintaan barang dan retur"; help "Satu email setiap pagi berisi permintaan dan retur yang menunggu Anda. Tanpa ringkasan, pemberitahuan tetap muncul di lonceng." |

### 5.4 Network, isolir, subscription dialogs (S10)

| Page / component | Change | Copy |
|---|---|---|
| `Pages/Isolir/Show.tsx` | §4.8: heading, two short sections, three large buttons, no login link; ISP name and logo stay | heading "Layanan internet Anda sedang dibatasi"; "Ada tagihan yang belum dibayar? Bayar lewat aplikasi {nama ISP}. Layanan aktif kembali otomatis setelah pembayaran diterima."; "Dibatasi oleh {nama ISP} karena alasan lain? Hubungi kami."; buttons "Telepon", "WhatsApp", "Buka aplikasi {nama ISP}" |
| `NetworkAccounts/Show.tsx:528-605,697-702` | with `pending_disconnect_at`: amber card at the top with "Coba sekarang" (posts `resync` without the confirm dialog, the disconnect was already confirmed); the "Online" badge adds "(menunggu diputus)"; resync dialog text by `radiusEnabled` | card "Router belum menjawab sejak {HH:mm}. Pemutusan sesi dicoba lagi otomatis."; RADIUS off dialog "RADIUS belum dikonfigurasi. Perubahan disimpan dan dikirim ke router setelah RADIUS aktif." |
| `Components/composite/NetworkAccessBadges.tsx:24,107-111` | `NasStatusBadge`: RADIUS off → neutral state (also closes F7-D27); block modal text by `radiusEnabled` | badge "RADIUS belum dikonfigurasi"; modal on: "Pelanggan diisolir. Sesi yang sedang terbuka diputus setelah router menjawab. Tagihan tetap berjalan dan pembayaran tidak mencabut blokir. Di aplikasi pelanggan tertulis Dibatasi."; off: "RADIUS belum dikonfigurasi. Blokir disimpan dan dikirim ke router setelah RADIUS aktif." |
| `lib/statuses/network.ts:59-64` | new NAS state `disabled` (neutral) | "RADIUS belum dikonfigurasi" |
| `NasDevices/Index.tsx`, `NasDevices/Show.tsx` | pass `radiusEnabled` to the badge | - |
| `Subscriptions/Show.tsx:564-586` (Tangguhkan) | helper text | "Pelanggan melihat status Ditangguhkan dengan tombol Hubungi ISP. Internet diisolir. Pembayaran tidak mengaktifkan kembali; aktifkan kembali dari halaman ini." |
| `types/network-asset.d.ts` | `pending_disconnect_at`, `radiusEnabled` where missing | - |

### 5.5 Technician app (S11)

- SPK detail (`work_order_detail_screen.dart`): for an installation SPK with `network_account`, a card "Akun PPPoE" in the shared `inbils_core` card style: username always shown with "Salin"; secret masked "••••••••" with "Tampilkan" (calls the reveal API, shows the secret for 60 s or until the screen closes, then masks again; never kept in persisted state, the offline cache or logs) and "Salin". States: `null` account → "Akun PPPoE belum dibuat. Hubungi kantor." with the existing call action; `can_reveal` false → no "Tampilkan"; 409/422 → the server message; offline → "Butuh koneksi internet untuk menampilkan kata sandi." Sync chip: pending "Menunggu ditulis ke router", synced "Siap dipakai", error "Belum tertulis, hubungi kantor".
- Product picker (`product_picker.dart:186-218`): each row shows "Tersedia: {N} {satuan}" for the chosen warehouse ("Stok kosong" in the warning colour at 0); a quantity above it shows "Melebihi stok tersedia ({N} {satuan})" under the field (warning, not blocking: the warehouse may receive stock).
- New request screen (`new_request_screen.dart`): warehouse preselected from `last_used`; changing the warehouse reloads availability.
- Notifications: no new subject type for the technician; `ticket_customer_replied`, `ticket_reopened`, `ticket_sla_breached` route through the existing `ticket` subject.

### 5.6 Customer app (S12) and the stock message

- Ticket detail (`ticket_detail_screen.dart:220-227,336`): `can_reply` false → the reply box is replaced by a card "Tiket ditutup. Masih bermasalah? Buat tiket baru" with the button "Buat tiket baru" (new-ticket route with the same category and subscription, `customer/lib/app/routes.dart:28-30`). Resolved ticket: hint above the box "Membalas akan membuka kembali tiket ini." A 422 `ticket_closed` (race) shows the same card.
- Home hero (`home_hero.dart:393-406`): isolir by overdue with `reactivation_holds`: list "Lunasi tagihan berikut agar layanan aktif kembali" with number, amount and due date, "Total Rp {jumlah}", primary "Bayar semua (Rp {total})" for 2 or more, "Bayar" for 1. "Bayar semua" pays the holds one by one, oldest first (OD1): it opens the existing pay flow of the first; when the app resumes and holds remain, a sheet "Tagihan {nomor} lunas. Lanjut bayar {nomor berikut} (Rp {jumlah})" with "Lanjut bayar"; when none remain, "Semua tagihan lunas. Layanan aktif kembali dalam beberapa menit." A step label "Tagihan {i} dari {n}" stays on the hero while it runs.
- Notification routing (`notification_subject_router.dart:12-16`): subject `subscription` → Beranda.
- Stock message (S6; used by web toasts, the approval sheet and W2): `InsufficientStockException` carries `product_name`, `unit`, `location_name`, `requested`, `available` (quantities trimmed, decimal comma); message "Stok {nama barang} di {nama lokasi} tersisa {tersedia} {satuan}, dibutuhkan {diminta} {satuan}." Example: "Stok Kabel Fiber Optic Single di AUDIT POP Bandung Timur tersisa 0 m, dibutuhkan 18 m."

## 6. Test plan

Commands (run by Main once after each batch lands, host load below 6, builds and E2E under their flock):

- PHPUnit: `./vendor/bin/sail artisan test --filter='<Class>'` per slice, then the full `./vendor/bin/sail artisan test` (database `inbils_test`).
- Style and static: `./vendor/bin/sail bin pint --test <changed php files>`; PHPStan where configured.
- Web: `npx tsc --noEmit`, `npx eslint <changed files>`, `npx prettier --check <changed files>`, `node resources/js/lib/format.selfcheck.mjs`, `node resources/js/lib/dateRange.selfcheck.mjs`, `node scripts/e2e-mutex.selfcheck.mjs`.
- E2E: `flock /tmp/inbils-e2e.lock sh -c 'npm run build && node scripts/e2e-setup.mjs && npm run test:e2e -- tests/e2e/flow-audit-w1-*.spec.ts --project=chromium --workers=1'`, then the full gate `npm run test:e2e -- --project=chromium --workers=1` (also records the post-e5095de gate of F8-D01).
- Flutter (per app, after `dart run build_runner build --delete-conflicting-outputs` and `flutter gen-l10n` where models or ARB changed): `~/development/flutter/bin/flutter analyze` and `~/development/flutter/bin/flutter test` in `technician/`, `customer/`, `packages/inbils_core/`.
- Migrations: `./vendor/bin/sail artisan migrate --pretend` (§3.1).
- No `composer.json` or `package.json` change is planned, so `composer audit` and `npm audit` are not triggered (rule 11); a slice that needs a dependency stops and reports.

| Slice | Tests (NEW unless marked changed) |
|---|---|
| S1 | `tests/Feature/Ticketing/TicketReplyRulesTest.php`: `test_customer_reply_on_closed_ticket_is_refused_with_ticket_closed`, `test_customer_reply_on_resolved_ticket_reopens_to_assigned_and_notifies`, `test_customer_reply_without_assignee_reopens_to_open`, `test_staff_comment_on_closed_ticket_is_refused`, `test_customer_attachment_on_closed_ticket_is_refused`; `tests/Feature/Ticketing/TicketFirstResponseSourceTest.php`: `test_first_public_staff_comment_sets_reply_source`, `test_internal_note_does_not_count`, `test_customer_comment_does_not_count`, `test_start_sets_start_source_only_when_empty`, `test_backfill_marks_existing_responses_as_start`; `tests/Feature/Ticketing/TicketStaffNoticeTest.php`: `test_new_ticket_notifies_assign_holders_except_actor`, `test_customer_reply_notifies_assignee_and_dispatch`; changed `tests/Feature/Ticketing/TicketSlaBreachTest.php`: `test_resolution_breach_goes_to_assignee_and_dispatch_never_customer`, `test_breach_has_no_mail_channel`, `test_response_breach_is_notified_once`; changed `tests/Feature/Push/TicketFirstResponseDueTest.php`: `test_unassigned_ticket_warns_dispatch`; changed `tests/Feature/Ticketing/TicketWhatsAppNotificationTest.php`: `test_customer_stages_push_and_carry_code`, `test_new_stages_send_no_whatsapp`; changed `tests/Feature/Api/V1/Customer/TicketsTest.php` (`can_reply`, `reopened_at`); changed `tests/Feature/Api/V1/Technician/TicketsTest.php` (`first_response_source`); changed `tests/Feature/Reporting/ReportDrillTest.php`: `test_drill_moments_carry_offset`; changed `tests/Feature/Reporting/ReportExportContentTest.php`: `test_drill_export_uses_company_timezone`; `tests/Feature/Push/NotificationTypesWaveOneTest.php`: one data-provider case per new type (title, message, subject, staff and customer variant, `detail_url` gate) |
| S2 | `tests/Feature/Billing/WholeRupiahInvoiceTest.php`: `test_money_rupiah_rounds_half_up_without_double_rounding`, `test_prorata_line_and_tax_are_whole_rupiah`, `test_manual_items_recalculate_to_whole_rupiah`, `test_payment_amount_with_cents_is_refused`, `test_pdf_shows_cents_only_when_present`; `tests/Feature/Billing/InstallationInvoicePolicyTest.php`: `test_installation_approval_drafts_invoice_and_notifies_billing_send_holders`, `test_auto_send_sends_on_approval`, `test_materials_not_billed_by_default`, `test_materials_billed_as_bahan_terpakai_when_enabled`, `test_fee_line_reads_biaya_pemasangan_and_only_for_installation`, `test_no_billable_line_creates_no_invoice`, `test_lines_for_is_pure`, `test_draft_card_counts_drafts_for_billing_send_holders`; `tests/Feature/Billing/InvoiceSendGuardTest.php`: `test_empty_or_zero_draft_cannot_be_sent`, `test_creator_can_add_items_to_own_draft_without_billing_update`, `test_show_exposes_can_flags`; changed `tests/Feature/Billing/GenerateEndpointTest.php`: `test_future_period_is_refused`, `test_preview_lists_skipped_with_reasons`; changed `tests/Feature/Billing/InvoiceRequestValidationTest.php`: `test_due_date_before_issue_date_is_refused_on_store_and_update`; changed (amounts, removed key, policy) `tests/Feature/Billing/RecurringBillingTest.php`, `MoneySafetyTest.php`, `CompanyBillingSettingsTest.php`, `InvoicePdfTest.php`, `tests/Feature/SPK/SpkCompletionOrchestrationTest.php`, `tests/Feature/P0CriticalPathTest.php`, `tests/Feature/Inventory/MaterialReturnWorkflowTest.php`, `tests/Feature/DashboardTest.php` |
| S3 | changed `tests/Feature/Billing/MidtransGatewayTest.php`: `test_snap_payload_forces_3ds`; changed `MidtransWebhookTest.php`: `test_legacy_unknown_order_answers_200_and_logs_foreign_order`; changed `MidtransPerCompanyTest.php`: `test_signed_unknown_order_answers_200_and_writes_nothing`; `tests/Feature/Billing/PaymentAttemptRefundTest.php`: `test_exception_attempt_is_marked_refunded_with_reference_and_audit`, `test_booked_or_non_exception_attempt_is_refused`, `test_refund_needs_billing_payment_reverse`, `test_other_company_attempt_is_404`, `test_refunded_leaves_open_exceptions`; `tests/Feature/Billing/ReactivationHoldsTest.php`: `test_holds_list_invoices_keeping_suspension`, `test_payment_with_remaining_holds_tells_the_customer`, `test_no_holds_when_not_overdue_suspension`; changed `tests/Feature/Api/V1/Customer/ProfileAndSubscriptionsTest.php` (`reactivation_holds`), `tests/Feature/Billing/AutoReactivateOnPaymentTest.php` (shared holds query) |
| S4 | `tests/Feature/SPK/InstallationNetworkAccountTest.php`: `test_first_assignment_creates_pppoe_account_from_customer_code`, `test_reassignment_does_not_create_a_second_account`, `test_approval_creates_missing_account`, `test_username_collision_gets_suffix`, `test_guard_refusal_does_not_block_assignment`; `tests/Feature/Api/V1/Technician/NetworkCredentialsTest.php`: `test_assignee_reveals_secret_audited_with_no_store_header`, `test_previous_assignee_gets_404_after_reassignment`, `test_closed_spk_answers_409`, `test_missing_account_answers_422`, `test_rate_limited_after_five`, `test_secret_never_in_work_order_resource_or_list`; `tests/Feature/SPK/WorkOrderSubmittedNoticeTest.php`: `test_submit_notifies_approvers_except_submitter`; `tests/Feature/SPK/WorkOrderShowCapabilitiesTest.php`: `test_can_flags`, `test_edit_of_assigned_spk_redirects_with_message`; changed `tests/Feature/Admin/ApprovalInboxTest.php`: `test_spk_rows_use_submitted_at_and_technician`; changed `tests/Feature/Api/V1/Technician/WorkOrdersTest.php` (`network_account` shape) |
| S5 | changed `tests/Feature/NetworkAsset/IsolirPageTest.php` (no login link, WhatsApp URL, app URL; still nothing about any customer); `tests/Feature/NetworkAsset/RadiusStatusTextTest.php`: `test_radius_messages_are_indonesian`, `test_enabling_radius_clears_disabled_errors_and_queues_sync`, `test_resync_toast_follows_radius_state`, `test_resync_keeps_first_pending_time`; changed `ProvisioningFlowTest.php`: `test_written_group_is_recorded_while_disconnect_pending`; changed `NetworkAccountHttpTest.php`, `NasDeviceHttpTest.php` (toasts), `tests/Feature/Admin/CompanyIntegrationsTest.php` (stale errors); `tests/Feature/Billing/SubscriptionStatusNoticeTest.php`: `test_overdue_suspension_notifies_customer_with_invoice_numbers`, `test_manual_block_notifies_limited`, `test_reactivation_notifies`, `test_daily_run_sends_one_summary_per_company` |
| S6 | changed `Modules/Inventory/tests/Feature/StockServiceTest.php`: `test_insufficient_stock_message_names_product_place_and_unit`; changed `tests/Feature/Api/V1/Technician/ProductPickerTest.php`: `test_available_per_warehouse`, `test_unknown_warehouse_is_422`; `tests/Feature/Api/V1/Technician/WarehouseLastUsedTest.php`; `tests/Feature/Inventory/InventoryNoticeChannelsTest.php` (no mail; technician push; approvers bell); `tests/Feature/Inventory/InventoryDigestCommandTest.php`: `test_opted_in_user_gets_one_mail_with_counts`, `test_nothing_pending_sends_nothing`, `test_opted_out_gets_nothing`; changed `tests/Feature/ProfileTest.php`: `test_notification_preference_is_saved`, `test_preference_hidden_when_not_eligible`; changed `MaterialReturnNotificationLinkTest.php`, `InventoryRequestWorkflowTest.php` (channels) |
| S7 | changed `tests/Feature/Platform/TenantExportTest.php`: `test_files_come_from_media_rows_not_folders`, `test_stray_file_in_tenant_folder_is_not_exported`, `test_media_csv_and_user_roles_csv_are_written`; `tests/Feature/Operations/ReadinessStorageLinkTest.php`; `tests/Feature/Support/StorageRootIsolationTest.php`: `test_testing_disks_live_under_testing_suffix`; changed `tests/Feature/ErrorPagesTest.php`: `test_inertia_action_403_becomes_flash_error`, `test_inertia_action_abort_422_becomes_flash_error`, `test_get_422_renders_error_page_with_message`, `test_insufficient_stock_flashes_message`; changed `tests/Feature/Platform/ImpersonationTest.php`: `test_profile_during_support_renders_error_page`; changed `tests/Feature/Reporting/ConsolidatedBusinessReportTest.php`: `test_subsidiary_context_redirects_with_message`; `scripts/e2e-mutex.selfcheck.mjs`: Linux start-token case |
| S8 | `resources/js/lib/format.selfcheck.mjs` (cents rule, percent, decimal); `resources/js/lib/dateRange.selfcheck.mjs` (numeric formats); browser proof in S13 |
| S9, S10 | `npx tsc --noEmit`; browser proof in S13 |
| S11 | `technician/test/network_credentials_test.dart`: card states, reveal masks again, nothing in the offline cache; changed `technician/test/app_only_features_test.dart`: availability row and warning; widget runs at 360 dp, 200 % text, dark mode |
| S12 | changed `customer/test/tickets_test.dart` (closed card, resolved hint, 422 race), `customer/test/home_test.dart` (holds list, "Bayar semua" sequence), `customer/test/notifications_test.dart` (`subscription` → Beranda) |
| S13 | `tests/e2e/flow-audit-w1-billing.spec.ts`, `flow-audit-w1-tickets.spec.ts`, `flow-audit-w1-network.spec.ts`, `flow-audit-w1-ops.spec.ts` (scenarios in §8.2, S13) |

## 7. Risks and open decisions

Open decisions (the default is designed; the owner may override):

| # | Question | Default (designed) | Alternative and its cost |
|---|---|---|---|
| OD1 | How "Bayar semua" (K5) pays several invoices | one by one, oldest first, guided by the app (§5.6); the payment path hardened by `integrity-hardening` (one attempt per invoice, invoice-then-attempt lock order) stays untouched | one Snap order for all: new pivot table, split booking per invoice, bundle-aware `closeOpenFor`, reconcile and exception rules; one more backend slice and a payments security review |
| OD2 | Invoices already issued with cents | untouched; their online-pay guard stays; the deploy note lists them with a read-only query | rounding issued invoices changes documents already sent |
| OD3 | Two isolir texts without customer context | one page showing both texts | router variants: a second RADIUS address list per cause and a second redirect rule in every ISP router |
| OD4 | F8-U01 "barang yang disarankan" | warehouse suggestion = last used; no item suggestion (SPK items no longer carry planned quantities after `integrity-hardening`) | a "Sering dipakai" list from the technician's past requests |
| OD5 | Who may edit a draft invoice | `billing.update`, or `billing.create` on a draft (the maker composes it) | create-with-items in one form (wave 3 UX) |
| OD6 | Digest preference scope | one flag per user for all companies the user works in | a flag per membership |
| OD7 | "Dispatch and managers" (K8) | holders of `ticket.assign` (no role names); the `tenant-roles` templates must give it to Manajer and NOC & Layanan Pelanggan | a new catalogue permission `ticket.sla.watch` |
| OD8 | Permission to mark a refund (K4) | existing `billing.payment.reverse`; the `tenant-roles` template Keuangan should hold it | a new catalogue permission `billing.payment.refund` |
| OD9 | Where a reopened ticket goes | `assigned` with a handler, else `open`; deadlines unchanged | back to `on_progress` |
| OD10 | Comments on a closed ticket | refused for everyone; staff reopen is F6-D02 (wave 3) | allow internal notes on closed tickets |
| OD11 | `spk_auto_invoice` | removed; the installation invoice is drafted on every installation approval with a billable line | keep both switches |

New catalogue permissions: none. Reused: `billing.payment.reverse`, `ticket.assign`, `spk.approve`, `spk.reassign`, `spk.cancel`, `billing.send`, `billing.create`, `billing.payment.record`, `customer.subscription.suspend`, `inventory.request.approve`, `.prepare`, `.issue`, `inventory.return.receive`, `.resolve`.

Risks:

- R1 K18 secret in the technician app and API. Mitigation: the reveal rules of §4.6, `no-store`, audit before return, limiter, no secret in resources, push, logs or cache; `security-reviewer` pass on S4 and S11 (spec K18).
- R2 Hard rule 10: payments (S3), permissions (S2 policy widening, S7 error rendering), support access (S7 `EnforceImpersonation`), user data (S6 preference, S7 export with `user_roles.csv`) need a `security-reviewer` pass before the wave is called done.
- R3 Lock order: `SpkService::assign` locks the SPK, then `provisionForInstallation` locks the subscription through `NetworkAccountService::create`. S4 verifies that no path locks a subscription before its SPK; `CompleteSpkAction` already takes the SPK first.
- R4 `NetworkAccountProvisioner` records the written group before the Disconnect; retries still owe the Disconnect because `pending_disconnect_at` stays set (`NetworkAccountProvisioner.php:105-117`); covered by the changed `ProvisioningFlowTest`.
- R5 Notification volume: every customer reply reaches all `ticket.assign` holders; push reaches technician-app installs only (`FcmChannel.php:59-66`), so managers get bells.
- R6 Rounding: new prorata invoices change by less than Rp 1 per line; per-line tax rounding can differ from invoice-level rounding by a few rupiah on multi-line invoices (K1 rounds lines and tax, so this is intended).
- R7 `PickerField` blocks submit through a native listener; a form that submits without a submit event is not covered (none found in the billing forms; S13 covers invoices).
- R8 Behaviour changes to announce at deploy: drafts on every installation approval, materials not billed by default, no per-event inventory mails (opt-in digest), no SLA mails, closed tickets refuse replies.
- R9 `STORAGE_ENV_SUFFIX` puts `testing/` and `e2e/` subtrees inside the dev roots, so dev backups include test files; no code path reads them.
- R10 The `workers` compose profile runs `queue:work` and `schedule:work` only when a developer starts it (`COMPOSE_PROFILES=workers` or `scripts/dev-payments.sh`); nothing starts by default.
- R11 The `tenant-roles` lane changes how permissions resolve per company; `StaffRecipients` is the single place to adapt; no role name is added anywhere in this wave.
- R12 WhatsApp: the new customer ticket stages are bell and push only; no new template.
- R13 Customer subscription list: holds are computed only for subscriptions suspended for overdue (one query each), so the list stays free of per-row queries for active subscriptions.

Deferred (named, not built): staff reopen with a reason (F6-D02, wave 3, reuses `TicketService::reopen()`), visit schedule at registration and assignment (K16), PDF report template (K32, F9-D03), named stock message on the inventory-request approval path (F4-D12, wave 3, can reuse §5.6), "Bayar tanpa login" (parked).

## 8. Work breakdown

Rules for every slice: edit only the files listed; a needed change elsewhere is reported as `needs-change: <path> — <why>`; no build, test run, formatter or emulator during the slice (Main runs §6 once after each batch, under the host limits: load below 6, `flock /tmp/inbils-build.lock` and `/tmp/inbils-e2e.lock`, one AVD); no commit. A file marked NEW lives in a directory that exists today, except `app/Support/Notifications/` (new directory under the existing `app/Support/`). Generated files (freezed, json, l10n) belong to the slice that owns their source.

### 8.1 Batches and dependency order

| Batch | Slices (parallel, at most 4) | Starts when |
|---|---|---|
| B1 | S1 BE-TICKET, S2 BE-BILL, S3 BE-PAY, S4 BE-SPK | now (S1 writes `StaffRecipients` first; S2, S3, S4 code against its signature) |
| B2 | S5 BE-NET, S6 BE-INV, S7 BE-PLATFORM, S8 FE-BILL | B1 landed (S5, S6 use S1's helper and types; S8 uses S2 and S3 props) |
| B3 | S9 FE-OPS, S10 FE-NET, S11 MOB-TECH, S12 MOB-CUST | B2 landed; S11 and S12 also wait for `MobileLook` to finish its `packages/inbils_core` card and login style (if it is not done, B3 runs S9 and S10 and the two mobile slices follow as B3b) |
| B4 | S13 QA-W1, S14 DOCS-W1 | B3 (and B3b) landed |
| after | Main: §6 gates, `migrate --pretend`, `security-reviewer` pass (S2 policy, S3, S4, S6, S7, S11), ledger | B4 landed |

### 8.2 Slices

#### S1 BE-TICKET (backend, B1)
- Goal: K8, K9, K13 reply rules, F6-D04, F6-D11; the recipient helper and the renderer of every new notification type.
- Owned files: NEW `app/Support/Notifications/StaffRecipients.php`; `app/Http/Resources/NotificationResource.php`; `Modules/Ticketing/app/Services/TicketService.php`; `Modules/Ticketing/app/Models/Ticket.php`; `Modules/Ticketing/app/Http/Controllers/Api/V1/Customer/TicketCommentController.php`; `Modules/Ticketing/app/Http/Controllers/Api/V1/TicketAttachmentController.php`; `Modules/Ticketing/app/Http/Resources/TicketResource.php`; `Modules/Ticketing/app/Http/Resources/Api/V1/CustomerTicketResource.php`; `Modules/Ticketing/app/Http/Resources/Api/V1/TechnicianTicketResource.php`; `Modules/Ticketing/app/Jobs/CheckTicketSlaBreachesJob.php`; `Modules/Ticketing/app/Notifications/TicketSlaBreachedNotification.php`; `Modules/Ticketing/app/Notifications/TicketFirstResponseDueNotification.php`; `Modules/Ticketing/app/Notifications/TicketCustomerUpdateNotification.php`; NEW `Modules/Ticketing/app/Notifications/TicketStaffNotification.php`; NEW `Modules/Ticketing/database/migrations/2026_10_28_100100_add_response_source_and_reopen_to_tickets_table.php`; NEW `Modules/Ticketing/database/migrations/2026_10_28_100200_backfill_first_response_source_on_tickets.php`; `Modules/Reporting/app/Drill/DrillKind.php`; `Modules/Reporting/app/Exports/ReportExporter.php`; tests: NEW `tests/Feature/Ticketing/TicketReplyRulesTest.php`, NEW `tests/Feature/Ticketing/TicketFirstResponseSourceTest.php`, NEW `tests/Feature/Ticketing/TicketStaffNoticeTest.php`, NEW `tests/Feature/Push/NotificationTypesWaveOneTest.php`, `tests/Feature/Ticketing/TicketSlaBreachTest.php`, `tests/Feature/Push/TicketFirstResponseDueTest.php`, `tests/Feature/Ticketing/TicketWhatsAppNotificationTest.php`, `tests/Feature/Api/V1/Customer/TicketsTest.php`, `tests/Feature/Api/V1/Technician/TicketsTest.php`, `tests/Feature/Reporting/ReportDrillTest.php`, `tests/Feature/Reporting/ReportExportContentTest.php`.
- Contracts consumed: data keys of the S2, S3, S4, S5 notifications (§4.6).
- Contracts produced: `StaffRecipients::holding()`; every type, title, message, subject and `detail_url` of §4.6; ticket fields of §4.5; customer API `can_reply`, `reopened_at`, 422 `ticket_closed`.
- Depends on: none.
- Acceptance: S1 tests of §6 pass; a customer reply on a closed ticket answers 422 `ticket_closed`; an SLA breach creates no mail and nothing for the creator; `GET api/v1/notifications` renders every new type in Indonesian.
- Skills: `laravel-best-practices`, `tdd`, `ponytail`, `antislop`.

#### S2 BE-BILL (backend, B1)
- Goal: K1, K7, K17, F5-D02, F5-D04 (server), F12-D17, F4-D16, F4-D19, F4-D18 (incidental), K18 safety-net call on approval.
- Owned files: `app/Support/Money.php`; `Modules/Billing/app/Services/BillingService.php`; `Modules/Billing/app/Http/Requests/GenerateInvoicesRequest.php`; `Modules/Billing/app/Http/Requests/StoreInvoiceRequest.php`; `Modules/Billing/app/Http/Requests/UpdateInvoiceRequest.php`; `Modules/Billing/app/Http/Requests/RecordPaymentRequest.php`; `Modules/Billing/app/Policies/InvoicePolicy.php`; `Modules/Billing/app/Http/Controllers/InvoiceController.php`; `resources/views/pdf/invoice.blade.php`; `app/Actions/CreateInvoiceFromSpkAction.php`; `Modules/SPK/app/Actions/CompleteSpkAction.php`; NEW `Modules/Billing/app/Notifications/InstallationInvoiceDraftNotification.php`; `app/Services/Core/SetupWizardService.php`; `app/Http/Controllers/Admin/CompanyController.php`; `app/Http/Requests/Admin/UpdateCompanySettingsRequest.php`; `app/Queries/Admin/DashboardQuery.php`; tests: NEW `tests/Feature/Billing/WholeRupiahInvoiceTest.php`, NEW `tests/Feature/Billing/InstallationInvoicePolicyTest.php`, NEW `tests/Feature/Billing/InvoiceSendGuardTest.php`, `tests/Feature/Billing/GenerateEndpointTest.php`, `tests/Feature/Billing/InvoiceRequestValidationTest.php`, `tests/Feature/Billing/RecurringBillingTest.php`, `tests/Feature/Billing/MoneySafetyTest.php`, `tests/Feature/Billing/CompanyBillingSettingsTest.php`, `tests/Feature/Billing/InvoicePdfTest.php`, `tests/Feature/SPK/SpkCompletionOrchestrationTest.php`, `tests/Feature/P0CriticalPathTest.php`, `tests/Feature/Inventory/MaterialReturnWorkflowTest.php`, `tests/Feature/DashboardTest.php`.
- Contracts consumed: `StaffRecipients::holding()` (S1); `NetworkAccountService::provisionForInstallation()` (S4).
- Contracts produced: §4.3, §4.4, the keys of §3.2, `Invoices/Show` `can`, preview JSON, `Receivables` (`id` per exception, `can.refund`, `autoReactivate`), `Company/Settings`, dashboard `draft_invoices`, `CreateInvoiceFromSpkAction::linesFor()` (also W2).
- Depends on: none (contract only on S1, S4).
- Acceptance: S2 tests pass; a mid-month activation produces lines, tax and total ending in `.00`; a draft without items cannot be sent; `staff` adds an item to its own draft without 403; approving an installation SPK with the K17 switch off leaves one draft, one bell per `billing.send` holder and the finance card; materials appear only with "Tagihkan bahan terpakai".
- Skills: `laravel-best-practices`, `tdd`, `ponytail`, `antislop`, `web-security-review` (policy widening).

#### S3 BE-PAY (backend, B1)
- Goal: K2 (F5-D18), K3 (F5-D14), K4 (F5-D16), K5 (F5-D20 server).
- Owned files: `Modules/Billing/app/Services/MidtransGateway.php`; `Modules/Billing/app/Http/Controllers/MidtransWebhookController.php`; `Modules/Billing/app/Services/PaymentAttemptService.php`; `Modules/Billing/app/Models/PaymentAttempt.php`; NEW `Modules/Billing/database/migrations/2026_10_28_100000_add_refund_resolution_to_payment_attempts_table.php`; NEW `Modules/Billing/app/Http/Controllers/PaymentAttemptController.php`; NEW `Modules/Billing/app/Http/Requests/RefundPaymentAttemptRequest.php`; `Modules/Billing/routes/web.php`; `Modules/Billing/app/Http/Resources/PaymentAttemptResource.php`; `Modules/Billing/app/Services/OverdueSuspensionService.php`; `app/Http/Resources/Api/V1/CustomerSubscriptionResource.php`; `Modules/Billing/app/Notifications/PaymentReceivedNotification.php`; tests: `tests/Feature/Billing/MidtransGatewayTest.php`, `tests/Feature/Billing/MidtransWebhookTest.php`, `tests/Feature/Billing/MidtransPerCompanyTest.php`, NEW `tests/Feature/Billing/PaymentAttemptRefundTest.php`, NEW `tests/Feature/Billing/ReactivationHoldsTest.php`, `tests/Feature/Api/V1/Customer/ProfileAndSubscriptionsTest.php`, `tests/Feature/Billing/AutoReactivateOnPaymentTest.php`.
- Contracts consumed: `StaffRecipients::holding()` (S1) for `alertBillingStaff`.
- Contracts produced: refund route and status `refunded` (§4.1, §4.7); `reactivation_holds` (§4.2); `payment_received` data `holding_invoice_numbers` (§4.6); `OverdueSuspensionService::holdingInvoices(ServiceSubscription): Collection` (also used by `reactivateIfCleared`); Snap `credit_card.secure`.
- Depends on: none.
- Acceptance: S3 tests pass; a signed unknown order answers 200 and writes no row; the Snap payload carries `credit_card.secure = true`; marking an exception "Dikembalikan" removes it from the Tunggakan alert and writes one audit row; a subscription suspended for overdue lists the invoices that hold it.
- Skills: `laravel-best-practices`, `tdd`, `ponytail`, `antislop`, `web-security-review`.

#### S4 BE-SPK (backend, B1)
- Goal: K18 (F4-D17) per §4.6, F4-D08, notice part of F4-D13, server part of F4-U05 and F4-D02, the resync timestamp rule used by F7-U26.
- Added by Main (review, binding): an account created at assignment must not keep a pending subscription online when the installation never happens. `NetworkAccountProvisioner::stateOf()` keeps `pending` subscriptions enabled, so cancelling an installation SPK (or terminating its pending subscription) removes the account created for it (or disables it until a new installation SPK is assigned) in the same transaction as the cancel, and `network:sync` then removes it from RADIUS. Test: assign → cancel → the account is gone/disabled → `network:sync` leaves no enabled RADIUS user; reassign after cancel creates it again.
- Owned files: `Modules/SPK/app/Services/SpkService.php`; `Modules/NetworkAsset/app/Services/NetworkAccountService.php`; `Modules/SPK/app/Http/Resources/Api/V1/TechnicianWorkOrderResource.php`; NEW `Modules/SPK/app/Http/Controllers/Api/V1/Technician/WorkOrderNetworkCredentialsController.php`; `Modules/SPK/routes/api.php`; `app/Providers/AppServiceProvider.php`; NEW `Modules/SPK/app/Notifications/WorkOrderSubmittedForReviewNotification.php`; `Modules/SPK/app/Http/Controllers/WorkOrderController.php`; `Modules/SPK/app/Policies/WorkOrderPolicy.php`; `app/Queries/Admin/ApprovalInboxQuery.php`; `app/Http/Resources/ApprovalInboxItemResource.php`; tests: NEW `tests/Feature/SPK/InstallationNetworkAccountTest.php`, NEW `tests/Feature/Api/V1/Technician/NetworkCredentialsTest.php`, NEW `tests/Feature/SPK/WorkOrderSubmittedNoticeTest.php`, NEW `tests/Feature/SPK/WorkOrderShowCapabilitiesTest.php`, `tests/Feature/Admin/ApprovalInboxTest.php`, `tests/Feature/Api/V1/Technician/WorkOrdersTest.php`.
- Contracts consumed: `StaffRecipients::holding()` (S1).
- Contracts produced: `NetworkAccountService::provisionForInstallation(WorkOrder): ?NetworkAccount`; `resync()` keeps an existing `pending_disconnect_at`; reveal API and `network_account` field (§4.2); SPK Show `can` (§4.7); edit redirect; approval inbox rows with `submitted_at` and the technician; `spk_submitted`.
- Depends on: none.
- Acceptance: S4 tests pass; first assignment of an installation SPK creates exactly one PPPoE account named from the customer code; only the current assignee of an active SPK can reveal, each reveal is audited and limited, and no other response contains the secret; the approval queue shows the technician and the submit time.
- Skills: `laravel-best-practices`, `tdd`, `ponytail`, `antislop`, `web-security-review`. `security-reviewer` pass required (spec K18).

#### S5 BE-NET (backend, B2)
- Goal: F5-D12 notices; server parts of F2-D02, F7-D02, F7-D21, F7-D22; isolir props (F7-D01); F7-U26 data.
- Owned files: `Modules/NetworkAsset/app/Http/Controllers/IsolirPageController.php`; `Modules/NetworkAsset/app/Services/Radius/RadiusException.php`; `Modules/NetworkAsset/app/Services/NetworkAccountProvisioner.php`; `Modules/NetworkAsset/app/Http/Resources/NetworkAccountResource.php`; `Modules/NetworkAsset/app/Http/Controllers/NetworkAccountController.php`; `Modules/NetworkAsset/app/Http/Controllers/NasDeviceController.php`; `app/Http/Controllers/Admin/CompanyIntegrationController.php`; `app/Http/Controllers/Admin/SubscriptionController.php`; `app/Services/Core/SubscriptionService.php`; NEW `app/Notifications/SubscriptionStatusNotification.php`; NEW `app/Notifications/SubscriptionsIsolatedSummaryNotification.php`; `Modules/Billing/app/Console/SuspendOverdueSubscriptions.php`; tests: `tests/Feature/NetworkAsset/IsolirPageTest.php`, NEW `tests/Feature/NetworkAsset/RadiusStatusTextTest.php`, `tests/Feature/NetworkAsset/ProvisioningFlowTest.php`, `tests/Feature/NetworkAsset/NetworkAccountHttpTest.php`, `tests/Feature/NetworkAsset/NasDeviceHttpTest.php`, `tests/Feature/Admin/CompanyIntegrationsTest.php`, NEW `tests/Feature/Billing/SubscriptionStatusNoticeTest.php`.
- Contracts consumed: `StaffRecipients` and the subscription types rendered by S1; the `resync()` timestamp rule (S4).
- Contracts produced: isolir props (§4.7, §4.8), `pending_disconnect_at`, `radiusEnabled` on NAS index and subscription page, toasts of §4.1, Indonesian RADIUS texts (`RadiusException::unavailable()` "Basis data RADIUS tidak dapat dihubungi; dicoba lagi otomatis.", realm "Kode perusahaan tidak bisa dipakai sebagai realm RADIUS.", provisioner "Router belum menjawab permintaan pemutusan sesi; dicoba lagi otomatis." and "Sesi terbuka berasal dari router yang belum didaftarkan: {alamat}."), stale "belum dikonfigurasi" errors of that company's accounts and NAS rows cleared and `SyncCompanyNetworkJob` queued when the RADIUS card is saved enabled.
- Depends on: B1.
- Acceptance: S5 tests pass; saving the RADIUS card enabled clears the disabled message on that company's rows only; a block while the NAS is down shows the written isolir group and a pending time; an overdue isolation sends the customer one bell and push naming the invoice.
- Skills: `laravel-best-practices`, `tdd`, `ponytail`, `antislop`.

#### S6 BE-INV (backend, B2)
- Goal: F4-D07 (stock message), F8-U01 API, K28 (F8-D05) channels and opt-in digest.
- Owned files: `Modules/Inventory/app/Exceptions/InsufficientStockException.php`; `Modules/Inventory/app/Services/StockService.php`; `Modules/Inventory/app/Http/Controllers/Api/V1/Technician/ProductController.php`; `Modules/Inventory/app/Http/Resources/Api/V1/TechnicianProductResource.php`; `Modules/Inventory/app/Http/Controllers/Api/V1/Technician/WarehouseController.php`; `Modules/Inventory/app/Http/Resources/Api/V1/TechnicianWarehouseResource.php`; `Modules/Inventory/app/Notifications/InventoryRequestApprovedNotification.php`; `Modules/Inventory/app/Notifications/InventoryRequestRejectedNotification.php`; `Modules/Inventory/app/Notifications/InventoryRequestSubmittedNotification.php`; `Modules/Inventory/app/Notifications/InventoryRequestReadyNotification.php`; `Modules/Inventory/app/Notifications/MaterialReturnDeclaredNotification.php`; `Modules/Inventory/app/Notifications/MaterialReturnExceptionNotification.php`; `Modules/Inventory/app/Notifications/MaterialReturnRejectedNotification.php`; NEW `Modules/Inventory/app/Notifications/InventoryDigestNotification.php`; `Modules/Inventory/app/Services/InventoryRequestService.php`; `Modules/Inventory/app/Services/MaterialReturnService.php`; NEW `app/Console/Commands/SendInventoryDigestCommand.php` (`inventory:send-digest`); `routes/console.php` (`dailyAt('00:00')->withoutOverlapping(120)`, the TTL rule of `integrity-hardening`); NEW `database/migrations/2026_10_28_100300_add_inventory_digest_to_users_table.php`; `app/Models/User.php`; `app/Http/Controllers/ProfileController.php`; NEW `app/Http/Requests/UpdateNotificationPreferencesRequest.php`; `routes/web.php`; tests: `Modules/Inventory/tests/Feature/StockServiceTest.php`, `tests/Feature/Api/V1/Technician/ProductPickerTest.php`, NEW `tests/Feature/Api/V1/Technician/WarehouseLastUsedTest.php`, NEW `tests/Feature/Inventory/InventoryNoticeChannelsTest.php`, NEW `tests/Feature/Inventory/InventoryDigestCommandTest.php`, `tests/Feature/ProfileTest.php`, `tests/Feature/Inventory/MaterialReturnNotificationLinkTest.php`, `tests/Feature/Inventory/InventoryRequestWorkflowTest.php`; handover: `tests/Feature/Inventory/MaterialReturnWorkflowTest.php` (S2 in B1, S6 in B2 if its mail assertions change).
- Contracts consumed: `StaffRecipients` (S1); the web stock flash (S7, same batch, contract only).
- Contracts produced: stock message (§5.6), `available` and `last_used` (§4.2), K28 channels (§4.6), `notificationPreferences` and `profile.notifications.update` (§4.1, §4.7).
- Depends on: B1.
- Acceptance: S6 tests pass; approving an SPK whose uncovered material is short answers "Stok {barang} di {lokasi} tersisa ..., dibutuhkan ..."; no inventory notification keeps a mail channel; an opted-in approver gets one digest with counts and nobody else gets mail.
- Skills: `laravel-best-practices`, `tdd`, `ponytail`, `antislop`, `web-security-review` (new user field).

#### S7 BE-PLATFORM (backend and tooling, B2)
- Goal: K21 (F11-D04), F0-D03, F0-D01, F1-D01, F5-D19, F5-U17, F12-D08, F12-D18, F12-D19; the server part of the "Tombol tanpa izin" group (action refusals, 422 page, stock flash), F11-D07, F10-D02.
- Owned files: `app/Services/Platform/TenantExportBuilder.php` (files from `mediaOwnedBy` rows incl. generated conversions, `data/media.csv`, `data/user_roles.csv` = `model_has_roles` of the tenant's users joined to role names; folder scan removed; `tenantMediaPath` guard kept); `config/filesystems.php`; `phpunit.xml`; `.env.e2e.example`; `scripts/e2e-server-env.mjs`; `scripts/e2e-mutex.mjs` (Linux token `${boot_id}:${starttime}` from `/proc/sys/kernel/random/boot_id` and field 22 of `/proc/<pid>/stat`); `scripts/e2e-mutex.selfcheck.mjs`; `app/Support/Observability/ReadinessChecker.php` (check `storage_link`: `public/storage` is a link to the public root, else `fail` → `degraded`); `compose.sail.yaml` (services `queue` and `scheduler` under profile `workers`, same image, `artisan queue:work --queue=default,whatsapp` and `artisan schedule:work`); NEW `scripts/dev-payments.sh` (starts the `workers` profile; runs `cloudflared tunnel run "$INBILS_TUNNEL"` when that variable is set, else prints how to expose `/webhooks/midtrans/{company}`); `README.md`; `docs/operations/DOCKER.md`; `docs/operations/push-notifications.md` (the notify() process sentence, offline test with `adb reverse --remove tcp:8010`, one AVD and a load check); `bootstrap/app.php`; `app/Http/Middleware/EnforceImpersonation.php`; `Modules/Reporting/app/Http/Requests/ConsolidatedReportRequest.php`; `Modules/Reporting/app/Http/Controllers/ConsolidatedReportController.php`; tests: `tests/Feature/Platform/TenantExportTest.php`, NEW `tests/Feature/Operations/ReadinessStorageLinkTest.php`, NEW `tests/Feature/Support/StorageRootIsolationTest.php`, `tests/Feature/ErrorPagesTest.php`, `tests/Feature/Platform/ImpersonationTest.php`, `tests/Feature/Reporting/ConsolidatedBusinessReportTest.php`.
- Contracts consumed: none.
- Contracts produced: §3.3; action refusal and 422 page rules (§4.1); `Error` prop `message`; stock flash; consolidated redirect.
- Depends on: B1 (no code dependency; batched for host load).
- Acceptance: S7 tests pass; a PHPUnit run writes nothing under `storage/app/private/companies` or `storage/app/public/companies`; a stray file in a tenant folder is absent from its export ZIP, which contains `data/media.csv` and `data/user_roles.csv`; a dead E2E lock is reclaimed on Linux; an Inertia POST refused with 403 returns to the page with "Anda tidak punya izin untuk tindakan ini."
- Skills: `laravel-best-practices`, `tdd`, `ponytail`, `antislop`, `web-security-review`.

#### S8 FE-BILL (frontend, B2)
- Goal: UI of the format group (F2-U03, F4-D04, F9-D05), F5-D02, F5-D04, F5-D05, F7-D25, K4, K7 and K17 switches and card, Receivables helper text (share of F7-D05, F7-U03).
- Owned files: `resources/js/lib/format.ts`; `resources/js/lib/format.selfcheck.mjs`; `resources/js/lib/dateRange.ts`; `resources/js/lib/dateRange.selfcheck.mjs`; `resources/js/Components/ui/PickerField.tsx`; `resources/js/Components/composite/index.ts`; NEW `resources/js/Components/composite/RefundAttemptDialog.tsx`; `resources/js/Pages/Admin/Billing/Invoices/Show.tsx`; `resources/js/Pages/Admin/Billing/Invoices/GenerateDialog.tsx`; `resources/js/Pages/Admin/Billing/Receivables.tsx`; `resources/js/Pages/Admin/Company/Settings.tsx`; `resources/js/Pages/Admin/Dashboard/components/SectionCard.tsx`; `resources/js/types/dashboard.ts`; `resources/js/types/billing.d.ts`; `resources/js/lib/statuses/payment.ts`; `resources/js/Pages/Admin/Service/Packages/Create.tsx`; `resources/js/Pages/Admin/Service/Packages/Edit.tsx`; `resources/js/Pages/Admin/Customers/Create.tsx`; `resources/js/Pages/Admin/Reports/Business.tsx`; `resources/js/Pages/Admin/Reports/Sla.tsx`; `resources/js/Pages/Admin/Reports/Technician.tsx`.
- Contracts consumed: §4.7 rows produced by S2 and S3; §5.1, §5.2.
- Contracts produced: `formatPercent`, `formatDecimal`, the cents rule of `formatRupiah`; `RefundAttemptDialog`.
- Depends on: S2, S3.
- Acceptance: self-checks pass; typing `20/10/2026` stores 20 Oct; an unparsable date keeps the form from submitting; a `billing.create`-only user sees "Tambah item" on its own draft and no "Batalkan"; the refund dialog works from the invoice and from Tunggakan; reports print "63,6%".
- Skills: `antislop`, `antislop-ui`, `antislop-human`, `antislop-layoutmobile`, `antislop-copywriting`, `ui-ux-pro-max`, `frontend-design`, `redesign-existing-projects`, `design-system`, `tailwindcss-development`, `shadcn`, `ui-styling`, `inertia-react-development`.

#### S9 FE-OPS (frontend, B3)
- Goal: F6-D10, F4-U05, F3-D01; UI parts of F6-D01, F6-D06, F4-D07 (toast), F11-D07, F8-D05 (profile switch).
- Owned files: `resources/js/hooks/useToast.ts`; `resources/js/Pages/Error.tsx`; `resources/js/Pages/Admin/Tickets/Show.tsx`; `resources/js/types/ticketing.d.ts`; `resources/js/Pages/Admin/SPK/Show.tsx`; `resources/js/Pages/Admin/Employees/Index.tsx`; `resources/js/Pages/Admin/Vehicles/Index.tsx`; `resources/js/Pages/Admin/CustomerAddresses/Index.tsx`; `resources/js/Pages/Admin/Documents/Index.tsx`; `resources/js/Pages/Profile/Edit.tsx`; NEW `resources/js/Pages/Profile/Partials/NotificationPreferencesForm.tsx`.
- Contracts consumed: §4.7 rows of S1, S4, S6, S7; §5.3.
- Contracts produced: none.
- Depends on: B1, B2.
- Acceptance: a non-handler admin sees no "Selesaikan"; a closed ticket has no "Tambah komentar"; reopening the employee dialog after a save shows empty fields; a refused action shows one toast and keeps the page.
- Skills: same list as S8.

#### S10 FE-NET (frontend, B3)
- Goal: K25 page (F7-D01, F7-U01, part of F5-U04), F7-D05, F7-U03, F7-U26, UI parts of F2-D02, F7-D02, F7-D22, F7-D27.
- Owned files: `resources/js/Pages/Isolir/Show.tsx`; `resources/js/Pages/Admin/NetworkAccounts/Show.tsx`; `resources/js/Components/composite/NetworkAccessBadges.tsx`; `resources/js/lib/statuses/network.ts`; `resources/js/Pages/Admin/NasDevices/Index.tsx`; `resources/js/Pages/Admin/NasDevices/Show.tsx`; `resources/js/Pages/Admin/Subscriptions/Show.tsx`; `resources/js/types/network-asset.d.ts`.
- Contracts consumed: §4.7 rows of S5; §5.4.
- Contracts produced: none.
- Depends on: B2 (S5).
- Acceptance: the isolir page has no login link and shows Telepon, WhatsApp and the app button when configured; with RADIUS off no dialog promises a disconnect and NAS badges read "RADIUS belum dikonfigurasi"; a pending disconnect shows the amber card with "Coba sekarang".
- Skills: same list as S8.

#### S11 MOB-TECH (mobile, B3 or B3b)
- Goal: K18 card and reveal (app part of F4-D17), F8-U01 (owner).
- Owned files (repo `inbils-mobile`): `technician/lib/features/work_orders/work_order.dart` (+ `work_order.freezed.dart`, `work_order.g.dart`); `technician/lib/features/work_orders/work_order_repository.dart` (+ `work_order_repository.g.dart`); `technician/lib/features/work_orders/work_order_detail_screen.dart`; NEW `technician/lib/features/work_orders/network_credentials_card.dart`; `technician/lib/features/products/product.dart` (+ `product.freezed.dart`, `product.g.dart`); `technician/lib/features/products/product_picker.dart`; `technician/lib/features/products/products_repository.dart` (+ `products_repository.g.dart`); `technician/lib/features/materials/new_request_screen.dart`; `technician/lib/features/materials/warehouse.dart` (+ `warehouse.freezed.dart`, `warehouse.g.dart`); `technician/lib/l10n/app_id.arb` (+ the files `flutter gen-l10n` writes from it); tests: NEW `technician/test/network_credentials_test.dart`, `technician/test/app_only_features_test.dart`.
- Contracts consumed: §4.2 (S4, S6); the `MobileLook` card style in `packages/inbils_core`.
- Contracts produced: none.
- Depends on: S4, S6, `MobileLook`.
- Acceptance: widget tests pass at 360 dp, 200 % text, light and dark; the secret appears only after "Tampilkan", masks again after 60 s and is absent from the offline cache; the picker shows "Tersedia" for the chosen warehouse.
- Skills: `antislop`, `antislop-ui`, `antislop-human`, `antislop-layoutmobile`, `antislop-copywriting`, `ui-ux-pro-max`, `frontend-design`, `design-system`, `flutter-build-responsive-layout`, `flutter-fix-layout-issues`, `flutter-add-widget-test`. `security-reviewer` pass with S4.

#### S12 MOB-CUST (mobile, B3 or B3b)
- Goal: app parts of K13 (F6-D01), K5 (F5-D20), F5-D12 routing.
- Owned files (repo `inbils-mobile`): `customer/lib/features/tickets/ticket.dart` (+ `ticket.freezed.dart`, `ticket.g.dart`); `customer/lib/features/tickets/ticket_detail_screen.dart`; `customer/lib/features/subscriptions/subscription.dart` (+ `subscription.freezed.dart`, `subscription.g.dart`); `customer/lib/features/home/home_hero.dart`; NEW `customer/lib/features/home/pay_all_flow.dart`; `customer/lib/features/notifications/notification_subject_router.dart`; `customer/lib/l10n/app_id.arb` (+ generated); tests: `customer/test/tickets_test.dart`, `customer/test/home_test.dart`, `customer/test/notifications_test.dart`.
- Contracts consumed: §4.2, §4.6 (S1, S3, S5); the `MobileLook` card style.
- Contracts produced: none.
- Depends on: S1, S3, S5, `MobileLook`.
- Acceptance: a closed ticket shows the card instead of the reply box; the isolir hero lists the holding invoices and walks "Bayar semua" one invoice at a time; a `subscription` notification opens Beranda.
- Skills: same list as S11.

#### S13 QA-W1 (qa, B4)
- Goal: browser proof of the web changes; Main's run records the post-e5095de full gate (F8-D01).
- Owned files: NEW `tests/e2e/flow-audit-w1-billing.spec.ts` (whole-rupiah display, future period refused, typed `20/10/2026` kept, invalid date blocks Simpan, draft buttons of a `billing.create`-only user, empty draft send refused with a toast, refund dialog, draft card); NEW `tests/e2e/flow-audit-w1-tickets.spec.ts` (closed ticket without comment box, reopened badge, response source, hidden "Selesaikan", action 403 as toast); NEW `tests/e2e/flow-audit-w1-network.spec.ts` (isolir buttons and texts, resync toast with RADIUS off, neutral NAS badge, block dialog text); NEW `tests/e2e/flow-audit-w1-ops.spec.ts` (four dialogs reset, SPK approval stock toast with names, consolidated redirect message, profile page in support mode, digest switch); `tests/e2e/setup.php` (fixtures: resolved and closed tickets, a `billing.create`-only user, a draft installation invoice, an SPK whose uncovered material is short).
- Contracts consumed: everything above.
- Depends on: B1-B3.
- Acceptance: the four specs use roles and labels (no sleeps) and are listed with the §6 commands for Main.
- Skills: `playwright-best-practices`, `tdd`, `antislop`.

#### S14 DOCS-W1 (docs, B4)
- Goal: release notes, guides, OpenAPI.
- Owned files: `CHANGELOG.md`; `docs/user-guide/web.md`; `docs/user-guide/teknisi.md`; `docs/user-guide/pelanggan.md`; `docs/business/billing.md`; `docs/business/ticketing.md`; `docs/business/network.md`; `docs/business/inventory.md`; `docs/business/spk.md`; `docs/api/mobile-v1.openapi.json` (regenerated with `./vendor/bin/sail artisan scramble:export`, never hand-edited).
- Contracts consumed: everything above.
- Depends on: B1-B3.
- Acceptance: the CHANGELOG entry lists every behaviour change of R8 and the deploy note of §8.5; the guides describe K13, K17, K7, the K18 reveal, the K25 page, the K28 digest and "Bayar semua"; OpenAPI shows the new fields, route and types. Regenerating the guide PDFs is listed for Main (heavy).
- Skills: `antislop`, `antislop-copywriting`.

### 8.3 Owner slice per ID (each open ID has exactly one owner)

| Owner | IDs |
|---|---|
| S1 | F6-D01, F6-D05, F12-D10, F6-D06, F6-D11; extras F12-U03, F6-U18 (notice part), F6-D04 |
| S2 | F5-D01, F5-D02, F5-D04, F12-D17, F4-D16, F4-D19; incidental F4-D18 |
| S3 | F5-D14, F5-D16, F5-D18, F5-D20 |
| S4 | F4-D17, F4-D08; extras F4-D13 (notice part), F4-D02 (raw-422 part) |
| S5 | extras F5-D12, F2-D02, F7-D02, F7-D21, F7-D22 |
| S6 | F4-D07; extra F8-D05 |
| S7 | F11-D04, F0-D03; extras F0-D01, F1-D01, F5-D19, F5-U17, F12-D08, F12-D18, F12-D19, F10-D02, F11-D07 |
| S8 | F5-D05, F7-D25; extras F2-U03, F4-D04, F9-D05 |
| S9 | F3-D01; extras F6-D10, F4-U05 |
| S10 | extras F7-D01, F7-U01, F5-U04 (non-parked part), F7-D05, F7-U03, F7-U26; incidental F7-D27 |
| S11 | F8-U01 |
| S13 | F8-D01 (gate record only) |
| settled, no slice | F4-D06, F5-D10, F8-D02, F4-U06, F2-D04 (and the bell part of F5-U13) |
| not in wave 1 | F9-D03 (wave 3, K32) |

Count: 30 wave-1 IDs = 5 settled + 1 gate record (F8-D01) + 24 with one owner (S1: 5, S2: 6, S3: 4, S4: 2, S6: 1, S7: 2, S8: 2, S9: 1, S11: 1).

### 8.4 File-ownership matrix (files more than one slice needs)

| File | Owner (batch) | Slices that depend on it |
|---|---|---|
| `app/Support/Notifications/StaffRecipients.php` | S1 (B1) | S2, S3, S4 (B1, contract), S5, S6 (B2) |
| `app/Http/Resources/NotificationResource.php` | S1 (B1) | types of S2, S3, S4, S5, S6 |
| `Modules/NetworkAsset/app/Services/NetworkAccountService.php` | S4 (B1) | S2 (safety-net call), S5 (resync rule) |
| `Modules/SPK/app/Actions/CompleteSpkAction.php` | S2 (B1) | S4 (K18 call contract) |
| `Modules/Billing/app/Http/Controllers/InvoiceController.php` | S2 (B1) | S3 (attempt `id` in Receivables), S8 |
| `app/Queries/Admin/DashboardQuery.php` | S2 (B1) | S8; W2 later |
| `bootstrap/app.php` | S7 (B2) | S6 (stock flash), S9 |
| `app/Providers/AppServiceProvider.php` | S4 (B1) | none |
| `routes/web.php`, `routes/console.php`, `app/Models/User.php` | S6 (B2) | S9 |
| `Modules/Billing/routes/web.php` | S3 (B1) | S8 |
| `Modules/SPK/routes/api.php` | S4 (B1) | S11 |
| `resources/js/Components/composite/index.ts` | S8 (B2) | none in W1 |
| `resources/js/hooks/useToast.ts`, `resources/js/Pages/Error.tsx` | S9 (B3) | S7 (server side) |
| `tests/Feature/Inventory/MaterialReturnWorkflowTest.php` | S2 (B1), then S6 (B2) if needed | sequential handover, never two owners in one batch |
| `tests/e2e/setup.php` | S13 (B4) | none in W1 |
| `CHANGELOG.md`, `docs/api/mobile-v1.openapi.json` | S14 (B4) | none in W1 |

Check per batch, against the lists of §8.2: B1 (S1, S2, S3, S4) shares no file; B2 (S5, S6, S7, S8) shares no file; B3 (S9, S10 in the web repo; S11, S12 in separate apps) shares no file; B4 (S13, S14) shares no file. The only path listed under two slices, `MaterialReturnWorkflowTest.php`, sits in different batches.

### 8.5 Deploy note

1. Back up the database (money and ticket columns change).
2. `php artisan migrate --force` (M1-M4, additive; M3 updates existing tickets in chunks). Expected `--pretend` output: §3.1.
3. `php artisan queue:restart` (job and notification classes changed); the scheduler picks up `inventory:send-digest` (00:00 UTC).
4. `npm run build`.
5. Read-only check for OD2, to hand to each company's billing staff: `SELECT company_id, number, total FROM invoices WHERE status IN ('sent','partial','overdue') AND total <> ROUND(total)` (no update).
6. Announce R8 to every ISP admin: installation drafts, materials policy, inventory mails replaced by the opt-in digest in Profil, SLA mails replaced by bell and push, closed tickets refuse replies.
7. Dev only: if `/ready` reports `storage_link: fail`, run `./vendor/bin/sail artisan storage:link` (production links in `docker/app/entrypoint.sh:20`). Test and E2E files now live under `storage/app/{private,public}/{testing,e2e}`.
8. Apps: release the technician app (credentials card, "Tersedia") and the customer app (closed-ticket card, holds and "Bayar semua", subscription notices). The API changes are additive; older apps keep working.
9. `security-reviewer` pass (R1, R2) before the wave is called done; then Main updates the ledger row and the spec tickets.
