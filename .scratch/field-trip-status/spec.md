# Field trip status, customer contact and navigation on SPK

Status: needs-owner-approval
Ledger: `docs/plans/INBILS_OMP_BACKLOG.md` → "ISP roadmap lanes (2026-10-12)" row `field-trip-status` (add when the lane starts)
Source: owner request 2026-10-14 (verbatim): "pada pelanggan ada step dalam perjalanan atau menuju lokasi, selesai, dan di teknisi juga ada untuk mengeset jika dia dalam perjalanan atau pemasangan spk sudah selesai dipasang, kasih no hp pelanggan dibuat step list pelanggan ada no telp dan lat lng atau maps menuju kerumah pelanggan di teknisi"

## What the owner wants (as understood)

1. Technician app: on an SPK the technician sets a trip status: "Dalam perjalanan" (menuju lokasi) and "Selesai dipasang" (installation done), in addition to the existing Mulai / Kirim untuk review.
2. Customer app: the customer sees the same progress as a step list (e.g. Dijadwalkan → Teknisi dalam perjalanan → Sedang dikerjakan → Selesai dipasang), with the technician's phone number.
3. Technician app: customer phone number shown as a step/contact row (call button already exists on SPK detail: "Telepon"), plus the customer's lat/lng with an "open in Maps" navigation link to the customer's home (the SPK detail already has "Buka di Maps" for the work location; verify it uses the customer address coordinates).

## Facts to gather before grilling (scout)

- Current SPK status machine (`docs/business/spk.md`, `Modules/SPK/app/Enums|Models/WorkOrder`), the existing `check_in` field on technician SPK/ticket resources (committed 2026-10-12: what does it store? lat/lng + timestamp?), customer address coordinates (`customer_addresses.lat/lng`?), `Work order` `location_id` vs customer address, WhatsApp notification hooks (`docs/business/whatsapp`), the customer API `subscriptions`/`work-orders` exposure (does the customer app see SPKs at all today? audit says customer app has invoices, payments, subscriptions, tickets, notifications: no SPK screen).
- Technician app SPK detail (`technician/lib/features/work_orders/work_order_detail_screen.dart`): existing "Telepon" and "Buka di Maps" buttons and their data source.

## Grilling round 1 (draft questions, each with a recommended answer; ask the owner)

- Q1 Trip states: add two technician-settable events on an SPK, `en_route` (Dalam perjalanan) and `installed` (Selesai dipasang), stored as timestamped events on the work order (`work_order_events` or new columns `en_route_at`, `installed_at`), without changing the approval state machine (`in_progress` → `waiting_review` stays the gate for review). Recommended: timestamped columns on `work_orders`, set by new API endpoints `POST technician/work-orders/{wo}/en-route` and `.../installed`; web SPK show displays them on the timeline.
- Q2 Customer visibility: new customer API `GET customer/work-orders` + `/{wo}` (only SPKs for the customer's own subscriptions, allow-list fields: code, type, scheduled date, status step, technician name + phone, timestamps) and a new "Kunjungan teknisi" screen in the customer app with a 4-step list; home screen shows the next visit. WhatsApp "teknisi dalam perjalanan" message optional (consent + template approval needed): recommended defer.
- Q3 Technician phone to customer: show the assigned technician's `employee_profiles.phone` (verify field) only while the SPK is active (assigned → installed), never after completion. Recommended: yes, active only.
- Q4 Navigation: technician SPK detail gets a "Rumah pelanggan" row with phone (tap to call) and address + lat/lng; "Buka di Maps" uses the customer address coordinates when present, else the work location. Recommended: use `geo:` intent / Google Maps URL `https://www.google.com/maps/dir/?api=1&destination=lat,lng`.
- Q5 Where lat/lng come from: `customer_addresses` already has coordinates? If not, add `latitude`/`longitude` to customer addresses, editable on the web customer form (map picker exists? Leaflet is in the bundle for Locations) and visible in the customer app profile. Recommended: reuse Locations map picker pattern.
- Q6 Web: SPK show page timeline shows en-route/installed times; Penugasan board shows a "Dalam perjalanan" badge. Recommended: yes, minimal.
- Q7 Docs: update `docs/user-guide/{teknisi,pelanggan,web}.md` and re-shoot the affected screenshots.

## Tickets

Not written yet; write after the owner answers round 1.
