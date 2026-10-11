# MOBILE: technician ticket actions (start, resolve with note, comment with internal flag)

Status: ready-for-agent
Role: mobile
Blocked by: 04
Cross-lane blocked by: mobile-api 04
Card: none (new lane; no Hermes card)
Skills: antislop-ui, antislop-human, antislop-layoutmobile, tdd, ponytail, caveman

## Scope

- Ticket detail action bar:
  - "Mulai" when the status is `assigned` → `POST technician/tickets/{id}/start`.
  - "Selesaikan" when the status is `on_progress` → a bottom sheet with a required "Catatan penyelesaian" (≤1000, the server's `ResolveTicketRequest` limit, with a counter) → `POST …/resolve`.
  - Each uses one idempotency key per action and its retries, re-renders from the response, and re-reads on `conflict`.
  - 403 shows the forbidden copy (for example, the ticket was reassigned), and the screen refreshes.
- Comment box (≤2000) with a "Catatan internal" switch (off by default; on → `is_internal: true`) → `POST …/comments`. Internal comments show an "Internal" badge, and a customer never sees them (API guarantee).
- Online only: offline disables the actions with "Butuh koneksi internet".

Non-goals: close (maker-checker, staff only), assign, spawn SPK, attachments.

## Owned files

- `../inbils-mobile/technician/lib/features/tickets/`, `../inbils-mobile/technician/lib/l10n/`, `../inbils-mobile/technician/test/`

## Acceptance criteria

- [ ] Widget tests:
  - each action appears only in its status;
  - resolve without a note, or with more than 1000 characters, is blocked;
  - a retry reuses the key;
  - `conflict` reloads;
  - the internal switch sends `is_internal: true`;
  - the 2000-character comment limit;
  - offline disables the actions.
- [ ] Live on the AVD against the compose stack: start an assigned ticket, add an internal and a public comment, and resolve with a note. On the web, the ticket is `resolved`, and the customer portal view shows only the public comment. Described as seen.
- [ ] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments
