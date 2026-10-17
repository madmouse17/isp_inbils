# ISP announcements and promo banners (web management, customer app feed)

Status: done
Ledger: `docs/plans/INBILS_OMP_BACKLOG.md` → "ISP roadmap lanes (2026-10-12)" row `announcements-promo`
Source: owner 2026-10-14 (grill-me, customer app redesign M4 point 4): "yang di m4 yang ke 4 juga tolong dikasih buat di webnya sesuaikan juga, dan kasih semacam slider atau banner yang bisa lebih dari 1 untuk kebutuhan promosi juga dari isp ke pelanggan". Owner approved B1–B8 as recommended ("ya buatkan tiket specs"). R1–R6 are the assistant's.

## Decisions

- **B1 Two content kinds.**
  - `announcements`: title (≤ 120), body (≤ 2000, plain text with line breaks), `kind` ∈ {info, outage (Gangguan), maintenance (Pemeliharaan)}, `starts_at`, `ends_at` (nullable = until removed), `published_at` (null = draft). In the app: a card on Home; outage and maintenance always first, warning tone.
  - `promo_banners`: title (≤ 80, shown as caption and screen-reader label fallback), `alt_text` (required, ≤ 200), image (B4), `link_kind` ∈ {none, app, url}, `link_target` (app route key ∈ {invoices, subscriptions, ticket_create, visits} or an `https://` URL ≤ 500), `starts_at`, `ends_at`, `sort_order`, `is_active`, `tap_count`. In the app: a carousel on Home.
- **B2 Targeting:** each item targets all customers of the company, or narrows by locations (POP/area: a selected location and its descendants by `path`) and/or package categories (lane `package-categories`). An explicit `audience` (`all` | `targeted`) decides it; a targeted item whose targets were all removed matches nobody, never everyone. Targets are pivot tables with foreign keys (ticket 01). Code lives in `Modules/Service`. A customer matches when any of their active or suspended subscriptions has a serving POP inside a targeted location subtree, or a package in a targeted category (OR within a list, AND between the two lists when both are set).
- **B3 Notifications:** publishing an outage or maintenance announcement (or its `starts_at` arriving for a scheduled one) sends one in-app database notification per targeted customer user (queued, chunked 500). Promos never notify. No WhatsApp.
- **B4 Banner image:** JPG/PNG/WebP, ≤ 2 MB, aspect 2:1 ± 5 % (validated from the uploaded image size), recommended 1200×600; private media collection `promo_banner`; served only through the authenticated media endpoint to customers of the same company who match the targeting; `alt_text` required.
- **B5 App carousel:** ≤ 5 active banners (server returns at most 5 by `sort_order`, then newest); auto-advance every 5 s, paused on touch and when the OS "reduce motion" is on; page dots; tap opens the link (app route via go_router, `https://` via `url_launcher` external browser).
- **B6 Web page "Pengumuman & Promo"** under Pelanggan & Layanan: tabs Pengumuman / Banner promo; list with status badge (Draf, Terjadwal, Tayang, Selesai, Nonaktif), period, targeting summary, tap count; create/edit form with targeting pickers, date-time pickers, image upload with crop guidance, **phone-frame live preview**; deactivate; permission `announcement.manage` (admin, manager).
- **B7 Tap counter:** `POST /api/v1/customer/promo-banners/{banner}/tap` increments `tap_count` once per customer user per banner per day (dedupe via cache key), throttled; no impression tracking.
- **B8 Lifecycle:** status computed from `published_at`/`is_active` and the period; expired items disappear from the app and stay as history; the scheduler deletes images of banners ended more than 180 days ago (row kept, image gone).
- **R1 API:** `GET /api/v1/customer/home-feed` → `{ announcements: Announcement[], banners: Banner[] }` filtered by targeting and period (one call for Home); `GET /api/v1/customer/announcements/{announcement}` for the detail screen (opened from a notification); tap endpoint (B7). Allow-list resources; banner `image_url` = the media endpoint URL. OpenAPI regenerated.
- **R2 Audit:** create, update, publish, deactivate, delete logged (`announcement` log) with ids only.
- **R3 Delete:** drafts can be deleted; published items can only be deactivated (history and notification links stay valid).
- **R4 Performance:** targeting resolved in SQL (subscriptions join locations by path prefix and packages by category) with indexes `(company_id, published_at, starts_at, ends_at)`; Home feed cached per customer 60 s, invalidated on any change to the company's items.
- **R5 Mobile rendering** lives in the `mobile-redesign` lane (Home ticket), built against R1; this lane ships the API, web and notifications.
- **R6 Order:** web after `package-categories` (targeting by category) and after `ui-consistency` 02 (date-time pickers, `EnumBadge`); runs before `field-trip-status` (both touch the sidebar and the customer API).

## Testing decisions

- Targeting matrix (location subtree, category, both, none; suspended subscription still matches; other company never).
- Period/status computation at boundaries (company timezone), draft never visible, deactivated hidden.
- Notification fan-out once per user on publish and on scheduled start, never for promos.
- Banner validation (type, size, ratio, alt), media access (matching customer 200, non-matching 404, other company 404).
- Tap dedupe per day, throttle.
- Web: policy (manager ok, staff 403), CRUD, preview props; E2E create → publish → visible in the API feed for a matching customer.

## Tickets

01-backend-models-targeting-api · 02-backend-notifications-scheduler · 03-ui-web-management-preview · 04-qa-review-release
