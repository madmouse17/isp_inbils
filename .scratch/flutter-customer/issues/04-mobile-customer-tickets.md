# MOBILE: customer tickets (list, create, detail with replies, comment)

Status: ready-for-agent
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

- [ ] Widget tests:
  - the filters send the right `status`;
  - create without a category is blocked client-side;
  - server 422 errors map to their fields;
  - a create retry reuses the same `Idempotency-Key`;
  - the subscription picker lists only active and suspended subscriptions;
  - a comment over 2000 characters is blocked;
  - the timeline marks own comments.
- [ ] Image attachments are requested from `GET media/{id}` with the `Authorization` header and render. Non-image attachments show the "Buka lewat portal web" note and trigger no request.
- [ ] Live on the AVD against the compose stack: the seeded customer creates a ticket, sees it in Aktif, adds a reply, and sees a staff reply added on the web (internal staff notes never appear). Recorded as seen.
- [ ] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments
