# MOBILE: technician My work list, SPK detail and ticket detail (read-only, online)

Status: ready-for-agent
Role: mobile
Blocked by: 02
Cross-lane blocked by: mobile-api 02 (my-work, media), mobile-api 04 (ticket detail), mobile-api 05 (SPK detail)
Card: none (new lane; no Hermes card)
Skills: antislop-ui, antislop-human, antislop-layoutmobile, ui-ux-pro-max, tdd, ponytail, caveman

## Scope

The read side of the spec's Pekerjaan, SPK detail and Tiket detail rows. Actions arrive in 04-06 and the offline cache in 07.

- Pekerjaan tab (`/work`), now the landing tab:
  - Semua / SPK / Tiket segments (`domain`), Ditugaskan / Dikerjakan chips (`status`, with the same values the web My Work page accepts; read them from the API ticket's tests), and infinite scroll.
  - A summary row from `meta.summary`.
  - Rows from `MyWorkItemResource`: code, title, customer name, `scheduled_date` for SPKs or `due_at` and SLA state for tickets, and a status chip. Tap → SPK or ticket detail by `domain`.
- SPK detail (`/work-orders/:id`):
  - Header, rejection reason, customer with "Telepon" (`tel:`), site with "Buka di Maps" (Q6 URL, address-text fallback, hidden when neither exists), description, read-only items.
  - Evidence gallery: thumbnails through `GET media/{id}` with the token, caption, uploader, and "Anda" when `mine`.
  - Material-returns section listing the cases with status.
  - No action bar yet. Tickets 04 (Mulai, Kirim untuk review) and 06 (Ambil foto) add their buttons, driven by `actions`, so no visible button is ever dead.
- Ticket detail (`/tickets/:id`): info, customer "Telepon", location "Buka di Maps", comments with an "Internal" badge. Actions arrive in 05.
- `url_launcher` intents: phone and maps. Add `<queries>` for `tel` and `https` only if they are needed for `launchUrl`.

Non-goals: start, submit, evidence, declare, ticket actions, the cache, notifications, the Material tab.

## Owned files

- NEW `../inbils-mobile/technician/lib/features/work/`, NEW `../inbils-mobile/technician/lib/features/work_orders/`, NEW `../inbils-mobile/technician/lib/features/tickets/`
- `../inbils-mobile/technician/pubspec.yaml` (`url_launcher`), `../inbils-mobile/pubspec.lock` (regenerated; shared lock-file rule)
- `../inbils-mobile/technician/lib/app/router.dart`, `../inbils-mobile/technician/lib/l10n/`, `../inbils-mobile/technician/test/`, `../inbils-mobile/technician/android/app/src/main/AndroidManifest.xml` (`<queries>` only)

## Acceptance criteria

- [ ] Widget tests:
  - the segments and chips send the documented query parameters;
  - the summary renders;
  - rows route by `domain`;
  - empty, error and offline states render with retry.
- [ ] SPK detail renders every key of the spec's technician detail shape from a fixture. "Buka di Maps" builds `https://www.google.com/maps/dir/?api=1&destination=<lat>,<lng>`, or the encoded address when coordinates are missing. "Telepon" builds `tel:<phone>`. Both go to a fake launcher in tests.
- [ ] Evidence thumbnails request `GET media/{id}` with `Authorization`, and a failed thumbnail shows a placeholder icon with a semantics label.
- [ ] Live on the AVD against the compose stack: the seeded technician's list matches the web My Work page for the same filters (codes and order), and an SPK and a ticket open with correct data. Described as seen.
- [ ] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments
