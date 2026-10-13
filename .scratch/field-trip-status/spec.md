# Field trip status, customer contact and navigation on SPK

Status: ready-for-agent
Ledger: `docs/plans/INBILS_OMP_BACKLOG.md` → "ISP roadmap lanes (2026-10-12)" row `field-trip-status`
Source: owner request 2026-10-14 (verbatim): "pada pelanggan ada step dalam perjalanan atau menuju lokasi, selesai, dan di teknisi juga ada untuk mengeset jika dia dalam perjalanan atau pemasangan spk sudah selesai dipasang, kasih no hp pelanggan dibuat step list pelanggan ada no telp dan lat lng atau maps menuju kerumah pelanggan di teknisi". The owner accepted the assistant's recommendations for every open lane ("ya saya ikut saran anda … jadikan tickets dan specs semuanya baik yang ini maupun sebelumnya"); decisions F1–F9 below are those recommendations, corrected by the facts.

## Facts (2026-10-14, agent FieldTripFacts)

- SPK statuses: draft, generated, assigned, in_progress, waiting_review, completed, rejected, cancelled (`docs/business/spk.md`). Technician API: `show`, `start`, `submit`, `evidence`, `items` (`Modules/SPK/routes/api.php:8-14`). `start` (assigned → in_progress) sets `started_at` and records a `check_in` Activity (lat, lng, accuracy, `distance_m` to the site) (`SpkService.php:~180-200`, `app/Support/FieldCheckIn.php`). Timestamps: `started_at`, `completed_at`, `approved_at`, `rejected_at`; none for "on the way".
- Technician SPK resource exposes `customer {id, code, name, phone}` and `site {lat, lng, address, label}`; the app has "Telepon" and "Buka di Maps" (`mapsUri` → Google Maps directions) (`TechnicianWorkOrderResource.php:42-47,81-103`, `work_order_detail_screen.dart:130-185`).
- **Defect:** `site` and the check-in distance prefer `work_orders.location_id` over the subscription's installation address, and onboarding sets `location_id = serving_pop_id` (`CreateCustomerOnboardingAction.php:58`), so an installation SPK's Maps/distance point at the POP, not the customer's home. `location_id` is also the stock location (`CompleteSpkAction.php:62,185,246`) and must stay.
- `customer_addresses.lat/lng` exist (decimal 10,7). `employee_profiles.phone` exists.
- Customer API has no work-order endpoint; the customer app has no SPK/visit screen. Customer WhatsApp `spk_scheduled_customer` gives the technician's first name only and deliberately never the technician's phone.
- Web SPK Show lists Started/Completed/Check-in as plain rows; Dispatch is a queue with status badges; no "Dalam perjalanan" anywhere.

## Decisions

- **F1 One new event: "Berangkat" (on the way).** Column `work_orders.en_route_at` (nullable datetime). Endpoint `POST /api/v1/technician/work-orders/{wo}/en-route` (idempotent; allowed only while `assigned` and only for the assigned technician; optional `check_in` fix reusing `FieldCheckIn` rules, stored as Activity `en_route`). No new status: the state machine is unchanged, "Berangkat" is a timestamp inside `assigned`.
- **F2 Step list, same four steps for both apps and the web:** 1 Ditugaskan (`assigned`), 2 Dalam perjalanan (`en_route_at`), 3 Sedang dikerjakan (`in_progress`, `started_at`), 4 Selesai dipasang (`waiting_review` or `completed`; `submitted_at` = first transition to `waiting_review`, new nullable column set by `submit`). Rejected work goes back to step 3 ("Perlu perbaikan" badge). Cancelled shows "Dibatalkan" and no steps.
- **F3 Technician app SPK detail:** a vertical stepper (F2) with the action for the current step: "Saya berangkat" (step 2), "Mulai" (existing, step 3), "Selesai dipasang · kirim untuk review" (existing submit, step 4, same evidence rules). A "Pelanggan" card shows name, phone (tap to call, plus "WhatsApp" deep link `https://wa.me/<E.164>`), installation address and "Buka di Maps".
- **F4 Fix the site:** for SPKs with a subscription, `site` and the check-in distance use the installation address (lat/lng; address text fallback); `location` (the POP) is shown separately as "POP / gudang" with its own Maps link. SPKs without a subscription keep `location`. `location_id` semantics for stock are unchanged.
- **F5 Customer visibility:** new endpoints `GET /api/v1/customer/work-orders` (open and last 30 days, own subscriptions only, `EnsureCustomerAccess`) and `GET /api/v1/customer/work-orders/{wo}`; allow-list resource: code, type label, scheduled date, step (F2) with timestamps, technician first name only (no phone, consistent with the WhatsApp rule), installation address label. Customer app: "Kunjungan teknisi" screen (list + detail with the stepper) and a home card for the next open visit.
- **F6 Customer notification:** when "Berangkat" is set, an in-app database notification to the customer user ("Teknisi {nama depan} sedang menuju lokasi Anda") shown in the app's notifications; no new WhatsApp template (needs Meta approval; parked).
- **F7 Web:** SPK Show gets a timeline component with the F2 steps and times (plus approve/reject); Dispatch rows show an `EnumBadge` "Dalam perjalanan" when `en_route_at` is set and status is `assigned`.
- **F8 Coordinates source:** the existing `customer_addresses.lat/lng` (set on Customers/Create via the map picker, now opening on the company default map). No new columns for coordinates.
- **F9 Docs:** the three user guides get the new steps and screenshots (technician stepper, customer visit screen, web timeline).

## Order

After `ui-consistency` (ticket 05 rewrites the SPK and Dispatch pages) and before `opaque-ids` (its new API endpoints are converted there once).

## Testing decisions

- Feature: `en-route` (assigned only, assigned technician only, idempotent, foreign 404, check-in optional), `submitted_at` set once, site resolution (subscription address first, POP fallback, address text fallback), customer endpoints (own only, allow-list fields, no technician phone), notification sent once.
- Web: timeline renders from props; Dispatch badge.
- Mobile: widget tests for the stepper (each step's action), customer visit list/detail, home card; integration journey: berangkat → mulai → selesai on the AVD.
- E2E: SPK show timeline after API-driven transitions; Dispatch badge.

## Tickets

01-backend-trip-events-and-site · 02-backend-customer-visits-api · 03-ui-web-timeline · 04-mobile-technician-stepper · 05-mobile-customer-visits · 06-qa-review-release
