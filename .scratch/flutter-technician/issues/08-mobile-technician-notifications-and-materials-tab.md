# MOBILE: technician notifications (core inbox) and the Material tab (cases to declare, own requests read-only)

Status: done
Role: mobile
Blocked by: 07
Cross-lane blocked by: flutter-customer 06 (core inbox), mobile-api 02 (inbox), mobile-api 06 (material returns, requests, "ready" notice)
Card: none (new lane; no Hermes card)
Skills: antislop-ui, antislop-human, antislop-layoutmobile, tdd, ponytail, caveman

## Scope

Implements the Notifikasi and Material rows of the spec's screen table, and API Q14a and Q18a for technicians. The bottom navigation becomes final: Pekerjaan, Material, Notifikasi, Akun.

- Notifikasi (`/notifications`): the core inbox screen, with subject routing `work_order` → `/work-orders/:id`, `ticket` → `/tickets/:id`, `material_return` → `/material-returns/:id`, `inventory_request` → `/materials`, anything else → none. The tab shows the unread badge from the core provider, refreshed on resume.
- Material (`/materials`):
  - "Perlu dideklarasi": `GET technician/material-returns` filtered to `awaiting_declaration` and `correction_required`. Tap → the declaration screen from ticket 04.
  - "Permintaan barang saya": `GET technician/inventory-requests`, read-only rows with code, status chip (with "Siap diambil" emphasised in text, not only colour), items summary and the linked SPK code (tap → SPK).
  - Pull to refresh. The ticket 07 cache applies, as cached reads under the same rules.

Non-goals: creating, submitting or cancelling requests (API Q14a), pickup confirmation, push.

## Owned files

- NEW `../inbils-mobile/technician/lib/features/notifications/` (subject routing), NEW `../inbils-mobile/technician/lib/features/materials/`
- `../inbils-mobile/technician/lib/app/router.dart`, `../inbils-mobile/technician/lib/l10n/`, `../inbils-mobile/technician/test/`

## Acceptance criteria

- [x] Widget tests: each subject type routes correctly and an unknown type stays in the list; the badge follows `meta.total`; both Material sections render data, empty, error and offline-cache states; "Siap diambil" is readable without colour (semantics label checked).
- [x] Live on the AVD against the compose stack:
  1. Assign a ticket to the seeded technician on the web; "Tiket ditugaskan" appears in Notifikasi and opens the ticket.
  2. Move the technician's inventory request to `ready_for_pickup` on the web; the notice appears and Material shows "Siap diambil".

  Described as seen.
- [x] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments

### 2026-10-12: done (agent MobTech08)

Commit in `inbils-mobile` (not pushed): `feat(technician): notifications and materials tab`. `dart format --set-exit-if-changed .` clean, `flutter analyze` 0 issues, `flutter test`: technician 146 (14 new in `materials_notifications_test.dart`), core 224, customer 96.

What landed: bottom nav Pekerjaan, Material, Notifikasi, Akun; Notifikasi tab = core `NotificationsScreen` with `features/notifications/notification_subject_router.dart` (`work_order`, `ticket`, `material_return` push their screens, `inventory_request` goes to `/materials`, unknown or missing subject stays in the list); unread badge from `unreadNotificationCountProvider`, and the shell re-reads the count and the inbox on resume. Material tab (`features/materials/`): "Perlu dideklarasi" = `awaiting_declaration` + `correction_required` (two reads, API filters one status, cached as one body under `material-returns:to-declare`; first 50 rows), "Permintaan barang saya" = `GET technician/inventory-requests` (per_page 50, cached), read-only rows with code, items summary, SPK link and a status chip whose semantics label is "Status: Siap diambil". Each section has its own loading, error+retry, empty and offline-copy ("Data offline · diperbarui HH:mm") state.

Core fix (outside the ticket's files, needed for the badge): `NotificationRepository.list` sent `unread_only=true`; Laravel's `boolean` rule answers 422 for the string "true", so the badge never showed live (customer bell too). It now sends `1`.

Live (AVD `inbils_pixel_api35`, dev flavor, `tests/e2e/setup.php`, tinker seed: tickets TKT-LIVE-08/08B, SPK-LIVE-08, request REQ-LIVE-08 `preparing`, REQ-LIVE-08B `issued` with case #12 `awaiting_declaration`; Laravel `APP_ENV=e2e` on 8011 via `adb reverse`, under `/tmp/inbils-phpunit.lock`), as seen:
1. Ticket TKT-LIVE-08B assigned on the web (`TicketService::assign`) while the app was backgrounded; on resume the Notifikasi tab badge went 1 → 2, the inbox listed "Ticket TKT-LIVE-08B has been assigned to you."; tap opened the ticket detail (TKT-LIVE-08B), back in the inbox the badge was 1.
2. Material before: "Perlu dideklarasi" with SPK-LIVE-08 / REQ-LIVE-08B, requests REQ-LIVE-08B "Dikeluarkan" and REQ-LIVE-08 "Disiapkan". `InventoryRequestService::markReady` on the web; pull-to-refresh: REQ-LIVE-08 shows the green chip "Siap diambil" (icon + text); after resume the inbox had "Request REQ-LIVE-08 is ready for pickup." and tapping it opened Material. The case row opened "Deklarasi material"; the SPK link opened SPK-LIVE-08.
3. Airplane mode + `adb reverse --remove`, pull-to-refresh on Material: "Data offline · diperbarui 11:17" on both sections with rows intact.

Needs web change (not blocking): the API titles are English ("Ticket assigned", "Inventory request ready for pickup"; `NotificationResource::title`), the AC text says "Tiket ditugaskan". The mobile app shows the API strings as sent.

Not exercised live: error/empty states, material_return subject notice (widget tests only). Teardown: API server, AVD, `adb reverse`, lock holder stopped; `env/dev.local.json` and temp scripts deleted; seeded rows remain in `inbils_test` until the next `tests/e2e/setup.php`; no tokens recorded.
