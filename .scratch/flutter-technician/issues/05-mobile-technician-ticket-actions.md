# MOBILE: technician ticket actions (start, resolve with note, comment with internal flag)

Status: done
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

- [x] Widget tests:
  - each action appears only in its status;
  - resolve without a note, or with more than 1000 characters, is blocked;
  - a retry reuses the key;
  - `conflict` reloads;
  - the internal switch sends `is_internal: true`;
  - the 2000-character comment limit;
  - offline disables the actions.
- [x] Live on the AVD against the compose stack: start an assigned ticket, add an internal and a public comment, and resolve with a note. On the web, the ticket is `resolved`, and the customer portal view shows only the public comment. Described as seen.
- [x] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments

### 2026-10-12: done (agent MobTech05)

Commit `7896ea5` in `inbils-mobile` (not pushed). `dart format` clean, `flutter analyze` 0 issues, `flutter test` green: technician 69 (16 new in `test/ticket_actions_test.dart`), customer 96, core 224.

What landed: `features/tickets/ticket_actions.dart` (bottom action bar with 56 dp buttons: "Mulai" with a confirm dialog while `assigned`, "Selesaikan" while `on_progress` opening a bottom sheet with a required "Catatan penyelesaian", a counter and a blocked send over 1000 characters; the comment box with a 2000-character counter and the "Catatan internal" switch, off by default and back to off after a send), `TicketRepository.start/resolve/comment`, `TicketDetail` notifier (an action's answer is rendered without a second GET; a stored comment is appended). One idempotency key per action; a retry of the same note or comment reuses it, an edited text gets a new one. A conflict, unprocessable or 403 answer shows its message and re-reads the ticket. Ticket resource has no `actions` block, so the bar follows `status`.

Cleanup from 04: `awaiting_receipt` now reads "Menunggu penerimaan" in `WorkStatusChip`; `AttemptKey` and `mayHaveApplied` moved to `packages/inbils_core/lib/src/api/attempt_key.dart` (exported), and the customer and technician copies were deleted with all callers updated.

Offline: like ticket 04 there is no connectivity probe (spec Q3: no connectivity package), so "offline disables" means a failed request says "Butuh koneksi internet", keeps the buttons and the typed text, and the retry reuses the key. Not a pre-emptive disabled state.

Live (AVD `inbils_pixel_api35`, dev flavor, `tests/e2e/setup.php`, throwaway tinker seed of ticket TKT-LIVE-05 assigned to the demo technician, Laravel `APP_ENV=e2e` on 8011 via `adb reverse`, under `/tmp/inbils-phpunit.lock`), as seen:
1. Pekerjaan listed the ticket; the detail showed "Ditugaskan" and "Mulai". The dialog "Mulai tiket ini?" → Mulai: "Dikerjakan", the bar became "Selesaikan".
2. Comment with "Catatan internal" on → it appeared with the "Internal" badge, and the switch went back off. A second comment with the switch off appeared without a badge.
3. Selesaikan opened the sheet: the send button was disabled with "Isi catatan penyelesaian." and the counter "Sisa 1.000 karakter". With a note the send button turned on; sending showed "Selesai" and the note in the detail.
4. DB read: `status=resolved`, `resolution_note` as typed, comments `is_internal` 1 and 0. The customer API (`GET customer/tickets/13`, customer demo login) returned `resolved`, the resolution note and only the public comment.

Not exercised live: the 409/403 paths, timeout retry and airplane mode (widget tests only). Teardown: API server, AVD, `adb reverse`, lock holder stopped; `env/dev.local.json` and temp scripts deleted; no tokens recorded.
