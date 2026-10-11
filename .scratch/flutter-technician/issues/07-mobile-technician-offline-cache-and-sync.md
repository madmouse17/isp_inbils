# MOBILE: offline read cache, automatic queue sync with backoff, session-loss and user-switch rules, logout guard

Status: ready-for-agent
Role: mobile
Blocked by: 06
Card: none (new lane; no Hermes card)
Skills: antislop-ui, antislop-human, tdd, web-security-review (local data), ponytail, caveman

## Scope

Implements Q1 (read cache), Q3, Q9 and Q10 on top of ticket 06's queue.

- Read cache (drift `cache_entries`, schema version 2 with a migration from 1):
  - Store the JSON of each my-work page per filter and of every SPK, ticket and material-return detail opened, tagged with `user_id`.
  - Online: show fresh data and save it. Offline or on a network error: show the cached copy with "Data offline · diperbarui HH:mm". Without a cache: the core offline state.
  - Purge entries older than 7 days at start.
- Automatic sync (Q3):
  - The queue syncer starts after session restore and runs on app resume, after a capture, on pull-to-refresh of the work list or an SPK, and on "Kirim sekarang".
  - While rows are pending and the app is in the foreground, a timer fires at the earliest `next_attempt_at`. Backoff runs 30 s → 1 min → 2 min → 5 min cap, and `Retry-After` wins when present. The syncer is single-flight.
  - On start, rows stuck in `uploading` reset to `pending`, and orphan files with no row are deleted.
- Session rules (Q10):
  - A 401 pauses the syncer and keeps the rows. After a login by the same `user_id`, the syncer resumes.
  - A login by a different user wipes `cache_entries`, `evidence_queue` and the evidence files before the first screen.
  - Logout with rows asks for confirmation, then deletes rows and files. Logout always wipes `cache_entries`.
- Submit gating gains "Tunggu N foto terkirim" when the queue has rows for the SPK (ticket 04's gating list).
- Online-only actions (start, submit, declare, ticket actions, delete own evidence) show "Butuh koneksi internet" when the last request failed offline. Never infer connectivity from a cache read alone.

Non-goals: background workers, connectivity listeners, queued declarations or state changes, database encryption.

## Owned files

- `../inbils-mobile/technician/lib/data/local/` (`cache_entries`, migration), NEW `../inbils-mobile/technician/lib/data/sync/` (syncer, backoff, session hooks)
- `../inbils-mobile/technician/lib/features/{work,work_orders,tickets,material_returns,account,evidence}/` (cache reads, gating, logout guard)
- `../inbils-mobile/technician/lib/main.dart` (start-up hooks), `../inbils-mobile/technician/lib/l10n/`, `../inbils-mobile/technician/test/`

## Acceptance criteria

- [ ] Unit tests with a fake clock, in-memory drift and a fake adapter:
  - the backoff sequence, and `Retry-After` overriding it;
  - single-flight under rapid triggers;
  - a stuck `uploading` row resets on start;
  - orphan files are cleaned;
  - a 401 pauses and a same-user login resumes, sending the same keys;
  - a different-user login wipes everything;
  - the logout guard (cancel keeps everything; confirm wipes it);
  - the 7-day purge;
  - the migration from v1 to v2 keeps queued rows.
- [ ] Widget tests: the offline banner with time on the work list and SPK detail when served from cache; the "Tunggu N foto terkirim" gating; online-only actions disabled after an offline failure.
- [ ] Integration test on the AVD (`integration_test`, fake adapter toggled offline and online) for the journey: open SPK → offline → capture 2 → restart the app → still queued → online → both uploaded once → submit enabled.
- [ ] Live on the AVD against the compose stack, with airplane mode toggled through `adb shell cmd connectivity airplane-mode enable|disable`:
  1. List and SPK detail open offline from cache.
  2. Photos queue offline.
  3. With signal back and the app in the foreground, they upload without user action within the backoff window.
  4. The web shows each photo once.
  5. Logging out with a queued photo shows the warning.

  Described as seen.
- [ ] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments
