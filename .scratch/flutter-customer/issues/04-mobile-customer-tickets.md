# MOBILE: customer tickets (list, create, detail with replies, comment)

Status: done
Role: mobile
Blocked by: 03 (the customer app router is edited by one ticket at a time)
Cross-lane blocked by: mobile-api 04, mobile-api 02 (subscriptions for the picker; media)
Card: none (new lane; no Hermes card)
Skills: antislop-ui, antislop-human, antislop-layoutmobile, ui-ux-pro-max, tdd, ponytail, caveman

## Scope

Implements the Tickets, New ticket and Ticket detail rows of the spec's screen table.

- Tickets tab (`/tickets`, label "Tiket"): Aktif / Selesai filter (`status=active|closed`), infinite scroll, and rows showing code, title, status chip and last update. FAB "Buat tiket".
- New ticket (`/tickets/new`):
  - Category (required, from `GET customer/ticket-categories`).
  - Subscription (optional). Only `active` and `suspended` subscriptions from `GET customer/subscriptions` are offered, as the web form does; preselected when exactly one.
  - Title (required, ≤255) and description (optional).
  - Client-side validation matches the server rules. 422 `errors` show under the fields.
  - Submit sends one `Idempotency-Key`, reused on retry, and on success goes to the ticket detail.
  - No priority field; the server uses the category default.
- Ticket detail (`/tickets/:id`):
  - Status chip, category, subscription code, description, resolution note when resolved.
  - A comment timeline: own comments labelled "Anda", staff comments show the author name.
  - Attachments: images (jpeg/png) load in-app from `GET media/{id}` with the bearer token and open full-screen. Other types show name and size with "Buka lewat portal web". There is no download and no hand-off to other apps in v1, so no extra plugin.
  - Reply box (≤2000, counter) that posts with an idempotency key; on success the timeline refreshes.
  - 404 shows "Data tidak ditemukan atau sudah dihapus." and goes back.

Non-goals: uploading attachments (API Q15a), downloading non-image attachments, closing or reopening tickets, priority selection, evaluations.

## Owned files

- NEW `../inbils-mobile/customer/lib/features/tickets/`
- `../inbils-mobile/customer/lib/app/router.dart`, `../inbils-mobile/customer/lib/l10n/`, `../inbils-mobile/customer/test/`

## Acceptance criteria

- [x] Widget tests:
  - the filters send the right `status`;
  - create without a category is blocked client-side;
  - server 422 errors map to their fields;
  - a create retry reuses the same `Idempotency-Key`;
  - the subscription picker lists only active and suspended subscriptions;
  - a comment over 2000 characters is blocked;
  - the timeline marks own comments.
- [x] Image attachments are requested from `GET media/{id}` with the `Authorization` header and render. Non-image attachments show the "Buka lewat portal web" note and trigger no request.
- [x] Live on the AVD against the compose stack: the seeded customer creates a ticket, sees it in Aktif, adds a reply, and sees a staff reply added on the web (internal staff notes never appear). Recorded as seen.
- [x] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments

### 2026-10-12: done (agent MobCust04)

Commit `3d3cd29` in `inbils-mobile` (not pushed). `dart format --set-exit-if-changed .` clean; `flutter analyze` 0 issues; `flutter test` customer 76 (16 new in `tickets_test.dart`), core 219 and technician 14 unchanged, all green.

Decisions
- Tabs are now Tagihan, Tiket, Akun. `/tickets/new` is declared before `/tickets/:id`; both are top-level routes. After create the app does `pushReplacement` to the ticket, so back returns to the list.
- One `Idempotency-Key` per submit (`AttemptKey`), kept for offline, timeout, 5xx, 429 and idempotency_*; a definitive 4xx ends it. If the payload changes between attempts the key changes too (the server rejects a reused key with another body). Same for replies.
- Subscription picker reads all pages of `customer/subscriptions` (`per_page=50`) and offers `active` and `suspended`; preselected when exactly one. The POST carries `category_id`, optional `subscription_id`, `title`, optional `description`; no priority, no `source` (the server sets it).
- Reply over 2000 characters: the counter reads `2001/2000`, the field shows an error and send is disabled (the field does not truncate).
- Image attachments (`image/jpeg`, `image/png`) load with `ApiClient.send` + `ResponseType.bytes` on `GET media/{id}`; the bearer token comes from the existing auth interceptor (test asserts `Authorization`). Tap opens a full-screen `InteractiveViewer`. Other types: name, size and "Buka lewat portal web", no request.
- `customer/pubspec.yaml` got `dio: ^5.11.1` (same constraint as `inbils_core`, already in the lock) for `Options`/`ResponseType`; `inbils_core` untouched.

Live (AVD `inbils_pixel_api35`, dev flavor, `tests/e2e/setup.php`, Laravel `--env=e2e` on 8011 via `adb reverse`, under `/tmp/inbils-phpunit.lock`):
- Login as the seeded customer; the Tiket tab listed 8+ tickets in Aktif (code, title, "Terbuka" chip, "Diperbarui …"), FAB "Buat tiket" clear of the last row.
- Buat tiket: the only active subscription E2E-LINKED-SUB was preselected. Submit empty showed "Pilih kategori." and "Isi judul tiket." with no request. Picked "No Internet", title "Internet mati sejak pagi", sent: opened TKT-2026-00001 (Terbuka, Kategori, Layanan, "Belum ada balasan.").
- Reply "Balasan dari app customer" showed as "Anda · 12 Okt 2026 07:29" and the counter reset.
- On the web side (tinker, `TicketService::addComment` as Demo Admin) added a public reply and an internal note. After reopening the ticket the timeline showed "Demo Admin · … Balasan staf dari web."; the internal note never appeared. Back to the list: the new ticket was first in Aktif.
- Not exercised live: an image attachment (no seeded one) and a non-image attachment; both are covered by widget tests only.
- Teardown: server, lock holder and AVD stopped; `adb reverse` removed; `env/dev.local.json` deleted; no tokens recorded.

Known: the same `Flexible` chip layout puts the status chip mid-row on the invoice list (it shares half the row with the text); not my files, the ticket list avoids it.
