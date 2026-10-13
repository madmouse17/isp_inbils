# Technician and customer go app-only; user guides as PDF

Status: in-progress
Ledger: `docs/plans/INBILS_OMP_BACKLOG.md` → "ISP roadmap lanes (2026-10-12)" row `app-only-personas`
Source: owner request 2026-10-13 (grill-me): remove the technician and customer menus from the web, they use the Flutter apps; then write complete user documentation for the web and the apps, with pictures, as PDF. Owner approved grilling round 1 (Q1–Q8) and asked for implementation with the mattpocock skills (to-spec → tickets → implement).

## Audit (2026-10-13)

- Web technician surfaces: Pekerjaan Saya, SPK start/submit/evidence/items, ticket start/resolve/comment/attachments, inventory requests, stock lookups, Evaluasi, Kinerja Teknisi (self), master-data lookups, dashboard persona cards, profile.
- Web customer surfaces: portal invoices + Midtrans pay, tickets through the admin ticket pages, dashboard.
- Apps (repo `inbils-mobile`): technician has My Work, SPK start/submit/evidence, tickets start/resolve/comment, material returns, notifications, devices; customer has invoices, pay, payments, subscriptions, tickets (create/comment), notifications, account.
- Missing in the apps: ticket attachment upload (both), SPK material items (technician), inventory request create/cancel (technician), profile edit (both).
- Must stay on the web: login, forgot/reset password (the app's reset link lands here), email verification, 2FA enrol/manage (`docs/SECURITY.md` §4.3), Midtrans callback pages `portal/payments/return` and `pembayaran/selesai`, the public isolir page.
- `EnsureCustomerAccess` and `MyWorkQuery::isEligible` also gate the API; they stay.
- No user documentation exists. PDF tooling: `barryvdh/laravel-dompdf` ^3.1. Playwright for web screenshots. Flutter integration tests (`integration_test/screens.dart`) for app screenshots. AVD `inbils_pixel7` (android-35, google_apis, x86_64) created in this lane; KVM available.

## Decisions

Q1–Q8 approved by the owner; R1–R8 taken by the assistant.

- **Q1 App-only roles.** A user whose roles are only `technician` and/or `customer` is sent by middleware `RedirectAppOnlyRoles` (applied to the `admin` and `portal` route groups) to `GET /app-only` (Inertia page `AppOnly`), which names the app for their role, links to the Play Store listing when `SettingService::get('play_store_url_<role>')` is set, and links to `/profile` (2FA) and logout. Every admin/portal route answers 302 → `/app-only` for them (not 403, so a bookmarked link is still friendly). A user who also holds admin/manager/staff is not affected.
- **Q2 Technician features moved to API + app before the web closes:** ticket attachment upload/delete, SPK material items add/remove, inventory request create/cancel, profile edit (name, phone, password change). Not moved (web-only, for managers): Kinerja Teknisi, Evaluasi, stock lookups, master-data lookups.
- **Q3 Customer features moved:** ticket attachment upload/delete, profile edit (name, phone, password change). Customer evaluation is skipped (permission exists, no page).
- **Q4 Web removals.** Delete `Pages/Portal/Invoices/*`, `Pages/Portal/Password/*` and their routes/controllers (`portal/invoices*`, `portal/password*`); keep `portal/payments/return` and `pembayaran/selesai` (Midtrans targets) and `PaymentReturnController`. Remove sidebar items "Tagihan Saya" and the customer/technician persona cards on the dashboard. "Pekerjaan Saya" stays (dispatchers and managers use it) but its `canMyWork` flag is false for app-only users by construction. Delete E2E specs/steps that only exercise the two personas' web UI; keep API feature tests. No feature flag.
- **Q5 Documentation.** Three Indonesian PDFs: `Panduan Web Inbils` (admin/manager/staff, per sidebar menu), `Panduan Aplikasi Teknisi`, `Panduan Aplikasi Pelanggan`. Source Markdown in `docs/user-guide/{web,teknisi,pelanggan}.md`, images in `docs/user-guide/images/`, rendered by `php artisan inbils:docs:pdf` (dompdf, Blade template `resources/views/pdf/user-guide.blade.php`, Markdown parsed by `league/commonmark`, already a Laravel dependency) into `docs/user-guide/pdf/*.pdf`, which are committed. No new library.
- **Q6 Pictures.** Web: `scripts/docs-screenshots.mjs` (Playwright, demo data via `scripts/e2e-setup.mjs`, 1280×800, light theme, admin login) writes `docs/user-guide/images/web/<slug>.png`. Apps: integration test `integration_test/docs_screenshots_test.dart` in each app runs on AVD `inbils_pixel7` with the fixture server and writes `docs/user-guide/images/<app>/<slug>.png` (copied from the device via the integration-test screenshot API). Both are repeatable per release.
- **Q7 Web guide depth.** Per menu: purpose, who may use it (roles), numbered steps with screenshots, common mistakes. A "Alur kerja" chapter covers new customer → SPK → invoice → payment, and ticket → SPK. Server installation is out of scope (README, `docs/operations`).
- **Q8 Order.** Wave 1: API (web) + app features (mobile) + web gating/removals. Wave 2: screenshots + guides + PDF. One commit per wave per repo.
- **R1 App-only page copy** (Indonesian): title "Gunakan aplikasi Inbils", body "Akun {role} dipakai lewat aplikasi Android Inbils {Teknisi|Pelanggan}. Masuk di aplikasi dengan email dan kata sandi yang sama." Buttons: "Buka Play Store" (only when the URL setting exists), "Kelola akun & 2FA" (`/profile`), "Keluar". Layout `GuestLayout`, no sidebar.
- **R2 Settings keys** `play_store_url_technician`, `play_store_url_customer` in the `settings` table (system defaults, empty) editable on the existing System Settings page if it lists keys generically; otherwise seeded only.
- **R3 API contract additions (`/api/v1`, Sanctum, same throttles and Idempotency-Key rules as `docs/SECURITY.md` §4.5):**
  - `POST technician/tickets/{ticket}/attachments` and `POST customer/tickets/{ticket}/attachments` — multipart `file` (jpg/jpeg/png/pdf, ≤ 10 MB), optional `caption` ≤ 255 → 201 `{data: TicketAttachment}`; `DELETE .../attachments/{media}` → 204; same policy as the web (`TicketPolicy`, uploader may delete while the ticket is open).
  - `POST technician/work-orders/{wo}/items` — `{product_id, quantity}` → 201 `{data: WorkOrderItem}`; `DELETE technician/work-orders/{wo}/items/{item}` → 204; `WorkOrderPolicy` as on the web (assigned technician, SPK `in_progress`).
  - `GET technician/products?search=` → paginated `{id, sku, name, unit}` (active products of the company) for the item picker.
  - `POST technician/inventory-requests` — `{warehouse_location_id, reason?, items: [{product_id, quantity_requested}]}` → 201; `POST technician/inventory-requests/{id}/cancel` → 200; existing `InventoryRequestService` rules.
  - `PATCH me` — `{name?, phone?}` → 200 `{data: Me}`; `POST me/password` — `{current_password, password, password_confirmation}` → 204 and all other tokens revoked (existing `User` hook). Available to both apps.
  - `docs/api/mobile-v1.openapi.json` regenerated (Scramble) and committed.
- **R4 App screens.** Technician: attachment button on ticket detail; "Material" section on SPK detail (list, add via product search, remove); "Permintaan Barang" tab in Materials (list, new, cancel); Account → "Ubah profil" and "Ganti kata sandi". Customer: attachment button on ticket detail and on new-ticket; Account → "Ubah profil" and "Ganti kata sandi". Copy in Indonesian, offline/empty/error states as the existing screens do, widget tests for each new screen.
- **R5 Minimum app version.** None enforced; the API additions are backward compatible.
- **R6 PDF layout.** A4 portrait, cover (title, company-neutral "Inbils ISP ERP", version = git short SHA + date), table of contents from H1/H2, running footer with page numbers, images max-width 100 %, Figtree not required (dompdf default DejaVu Sans), Indonesian.
- **R7 Guide version.** The guides describe the UI as of this lane; the regeneration commands are documented in `docs/user-guide/README.md` so screenshots can be refreshed.
- **R8 Demo users on the web.** `DemoUserSeeder` keeps the technician and customer users (the apps and the API tests use them); E2E `loginAs('technician'|'customer')` now expects `/app-only`.

## Testing decisions

- Feature tests: middleware redirect for technician-only, customer-only, technician+staff (allowed), guest unaffected; `AppOnly` page props; every new API endpoint (happy, validation, policy 403, tenant 404, idempotency) in `tests/Feature/Api/V1/**`; portal routes removed (404) while the two Midtrans pages still answer; `PortalInvoiceTest` deleted; `PayTest` finish URL untouched.
- E2E: `app-only.spec.ts` (technician and customer land on `/app-only`, Play Store button hidden without setting, profile link works); update `sidebar-ia`, `dashboard-persona`, `auth-roles`, `private-media`, `midtrans-payment`, `ticket-lifecycle`, `radius-provisioning` (customer steps) accordingly; delete steps that drove the portal invoice pages.
- Mobile: widget tests per new screen; integration `journeys_test` extended for attachment upload and SPK item add; `flutter analyze` + `flutter test` per app and for `inbils_core`.
- Docs: `php artisan inbils:docs:pdf` produces three PDFs; a feature test renders the Blade with a two-heading Markdown fixture and asserts the PDF starts with `%PDF`; the screenshot scripts are run once and their output committed.

## Tickets

01-backend-api-additions · 02-backend-web-gate-and-removals · 03-ui-app-only-page-and-cleanup · 04-mobile-technician · 05-mobile-customer · 06-docs-pipeline-and-screenshots · 07-docs-guides · 08-qa-review-release
