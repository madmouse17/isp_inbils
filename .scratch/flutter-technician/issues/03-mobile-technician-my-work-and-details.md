# MOBILE: technician My work list, SPK detail and ticket detail (read-only, online)

Status: done
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

- [x] Widget tests:
  - the segments and chips send the documented query parameters;
  - the summary renders;
  - rows route by `domain`;
  - empty, error and offline states render with retry.
- [x] SPK detail renders every key of the spec's technician detail shape from a fixture. "Buka di Maps" builds `https://www.google.com/maps/dir/?api=1&destination=<lat>,<lng>`, or the encoded address when coordinates are missing. "Telepon" builds `tel:<phone>`. Both go to a fake launcher in tests.
- [x] Evidence thumbnails request `GET media/{id}` with `Authorization`, and a failed thumbnail shows a placeholder icon with a semantics label.
- [x] Live on the AVD against the compose stack: the seeded technician's list matches the web My Work page for the same filters (codes and order), and an SPK and a ticket open with correct data. Described as seen.
- [x] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments

### 2026-10-12: done (agent MobTech03)

Commit `9e5d574` in `inbils-mobile` (not pushed). `dart format --set-exit-if-changed .` clean, `flutter analyze` 0 issues, `flutter test` 31/31 green in `technician` (new `test/work_test.dart`; `auth_account_test.dart` now expects the Pekerjaan landing then the Akun tab).

What landed: `features/work/` (my-work models, repository, `WorkList` provider with infinite scroll, Pekerjaan screen, shared `ContactActions`, `urlLauncherProvider` + `telUri` / `mapsUri`, status chip), `features/work_orders/` (SPK detail, evidence thumbnails through `GET media/{id}`), `features/tickets/` (ticket detail with the Internal badge), `app/shell.dart` + `app/routes.dart` (tabs Pekerjaan and Akun; `/work` is the home), router, ARB. Also added `technician/build.yaml` (snake_case JSON, same as customer) and `dio` to `technician/pubspec.yaml`. `url_launcher` was already declared and locked, so `pubspec.lock` is unchanged and no `<queries>` entry was needed (`launchUrl` runs without `canLaunchUrl`). Ticket and SPK attachments other than evidence images, the action bars and the offline cache are left to 04-07, as scoped.

Live (AVD `inbils_pixel_api35`, dev flavor, `tests/e2e/setup.php` then throwaway tinker seed of 2 SPKs, 2 tickets, 2 evidence files and 2 comments for the demo technician, Laravel `APP_ENV=e2e` via `env -i` on 8011 through `adb reverse`, under `/tmp/inbils-phpunit.lock`):
- Login as the technician landed on Pekerjaan with summary 4 / 2 / 2 / 1 and rows LIVE-SPK-A, LIVE-SPK-B, LIVE-TKT-A, LIVE-TKT-B. The same `MyWorkQuery::filtered` and `summary` the web My Work controller calls (run in tinker as that user) returned the identical codes, order and counts.
- Filters, app vs the web query: SPK -> B, A; SPK + Ditugaskan -> A; Tiket + Ditugaskan -> TKT-B; Tiket + Dikerjakan -> TKT-A; Semua + Dikerjakan -> SPK-B, TKT-A; chips toggle off and Semua restore the full list. The ticket row with a past `sla_deadline` shows "SLA terlewat".
- SPK-B detail: status, priority, "Jadwal", "Dimulai", rejection banner "Foto kabel kurang jelas.", customer, "Default Area" site, description, evidence with "Anda". No action buttons. The valid image rendered as a thumbnail and opened full screen; a file with image/jpeg mime but garbage bytes showed the broken-image placeholder with its semantics label. "Telepon" opened the Google dialer with `081234567890`; "Buka di Maps" on SPK-A opened Google Maps (coordinate directions).
- Ticket detail LIVE-TKT-A: category, description, customer with Telepon, location with Maps, my comment and the staff comment with the "Internal" badge.
- With the server stopped, switching to the SPK segment showed "Tidak ada koneksi internet..." and "Coba lagi"; with the server back, "Coba lagi" restored the list.

Not verified live: a network failure of a thumbnail (covered by the widget test and by the decode-failure placeholder). Teardown: servers, lock holder and AVD stopped, `adb reverse` removed, `env/dev.local.json` and temp scripts deleted, no tokens recorded.
