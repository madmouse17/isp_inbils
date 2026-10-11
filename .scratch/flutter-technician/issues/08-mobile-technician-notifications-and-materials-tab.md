# MOBILE: technician notifications (core inbox) and the Material tab (cases to declare, own requests read-only)

Status: ready-for-agent
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

- [ ] Widget tests: each subject type routes correctly and an unknown type stays in the list; the badge follows `meta.total`; both Material sections render data, empty, error and offline-cache states; "Siap diambil" is readable without colour (semantics label checked).
- [ ] Live on the AVD against the compose stack:
  1. Assign a ticket to the seeded technician on the web; "Tiket ditugaskan" appears in Notifikasi and opens the ticket.
  2. Move the technician's inventory request to `ready_for_pickup` on the web; the notice appears and Material shows "Siap diambil".

  Described as seen.
- [ ] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments
