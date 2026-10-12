# MOBILE: offline read cache, automatic queue sync with backoff, session-loss and user-switch rules, logout guard

Status: done
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

- [x] Unit tests with a fake clock, in-memory drift and a fake adapter:
  - the backoff sequence, and `Retry-After` overriding it;
  - single-flight under rapid triggers;
  - a stuck `uploading` row resets on start;
  - orphan files are cleaned;
  - a 401 pauses and a same-user login resumes, sending the same keys;
  - a different-user login wipes everything;
  - the logout guard (cancel keeps everything; confirm wipes it);
  - the 7-day purge;
  - the migration from v1 to v2 keeps queued rows.
- [x] Widget tests: the offline banner with time on the work list and SPK detail when served from cache; the "Tunggu N foto terkirim" gating; online-only actions disabled after an offline failure.
- [x] Integration test on the AVD (`integration_test`, fake adapter toggled offline and online) for the journey: open SPK → offline → capture 2 → restart the app → still queued → online → both uploaded once → submit enabled.
- [x] Live on the AVD against the compose stack, with airplane mode toggled through `adb shell cmd connectivity airplane-mode enable|disable`:
  1. List and SPK detail open offline from cache.
  2. Photos queue offline.
  3. With signal back and the app in the foreground, they upload without user action within the backoff window.
  4. The web shows each photo once.
  5. Logging out with a queued photo shows the warning.

  Described as seen.
- [x] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments

### 2026-10-12: done (agent MobTech07)

Commit `9f6b871` in `inbils-mobile` (not pushed). `dart format --set-exit-if-changed .` clean, `flutter analyze` 0 issues, `flutter test`: technician 132 (30 new: 20 unit in `sync_test.dart`, 10 widget in `offline_cache_test.dart`; `migration_test.dart` reworked for v2; 8 older tests adapted), core 224 and customer 96 untouched. `integration_test/offline_journey_test.dart` on the AVD: 1 passed.

What landed:
- `data/local/app_database.dart`: schema v2, `cache_entries(key PK, user_id, json, fetched_at)`, `onUpgrade` creates it for `from < 2`. `drift_schemas/drift_schema_v2.json` and `test/generated_migrations/schema_v2.dart` regenerated; the migration test upgrades a v1 file that holds a queued row and keeps it.
- `data/sync/`: `backoff.dart` (30 s, 1 min, 2 min, 5 min cap; `Retry-After` wins), `read_cache.dart` (read-through: online saves, offline or timeout serves the same user's saved copy and publishes `served[key] = fetchedAt`; a 4xx/5xx is never answered from the cache), `network_hint.dart` (true only after a real request failed offline), `queue_syncer.dart` (see below), `cache_keys.dart`.
- Syncer: single-flight (it joins the uploader's drain), runs after session restore or login, on foreground resume, after a capture, on pull-to-refresh of the work list and the SPK, and on "Kirim sekarang"; a timer fires at the earliest `next_attempt_at` only while in the foreground; a manual trigger ignores the backoff, the timer does not. A 401 pauses it with the rows kept; `onSession(null)` then the same id resumes it with the same keys; another id wipes cache, rows and files before any read (`ReadCache` waits for that). 7-day purge at start. Stuck `uploading` rows reset and orphan files go at start (ticket 06 code, now tested).
- Cache wired into my-work (per domain/status/page), SPK, ticket and material-return details. Action answers (start, submit, ticket start/resolve, declare) refresh the saved copy. Banner "Data offline · diperbarui HH:mm" on the work list, SPK, ticket and return screens.
- Submit gate "Tunggu N foto terkirim" (rows for this SPK, failed ones count; it replaces "Belum ada foto bukti" while photos are queued).
- Online-only actions (Mulai, Kirim untuk review, ticket Mulai/Selesaikan/comment, Kirim deklarasi, Hapus own evidence) are disabled after a request failed offline and show "Butuh koneksi internet" with "Coba lagi", which re-reads the screen; a successful read re-enables them. A timeout does not count as offline (it keeps the same-key retry path of tickets 04-06).
- Keluar (technician `account_screen.dart` has its own rows instead of the core `AccountMenu`): "N bukti belum terkirim akan dihapus. Keluar?" when rows exist; Batal keeps everything; confirm wipes rows, files and cache, then logs out. The rows now sit outside the profile's error state: before, a technician without signal could not reach Keluar at all (`GET me` is not cached).

Packages: none added; resolved versions unchanged from ticket 06.

Live (AVD `inbils_pixel_api35`, dev flavor, `tests/e2e/setup.php`, throwaway in-progress SPK LIVE-SPK-07 for the demo technician, Laravel `APP_ENV=e2e` on 8011 via `adb reverse`, under `/tmp/inbils-phpunit.lock`), as seen:
- Airplane mode does not stop `adb reverse` traffic (loopback), so "offline" = `cmd connectivity airplane-mode enable` plus `adb reverse --remove tcp:8011`; "signal back" = `airplane-mode disable` plus `adb reverse tcp:8011 tcp:8011`.
1. Online: logged in, list and SPK detail opened (saved). Offline, pull-to-refresh on the SPK: "Data offline · diperbarui 10:52" with the SPK intact, "Butuh koneksi internet" and "Coba lagi" under a disabled "Kirim untuk review". Force-stop, cold start offline: the list opened from the saved copy ("Data offline · diperbarui 10:51", counts and the SPK row), then the SPK detail opened from its copy.
2. Offline: "Ambil foto" → Kamera → shutter → Done → caption "Foto offline 1" → Simpan: banner "1 foto belum terkirim", gallery row "Foto offline 1 / Menunggu", "Tunggu 1 foto terkirim" under the disabled submit. A second photo: "2 foto belum terkirim", "Tunggu 2 foto terkirim". A reinstall plus relaunch (same app data, offline) still showed "2 foto belum terkirim".
3. Signal back with the app in the foreground on the work list, no touch: the banner vanished at 11:02:10, 4 min 15 s after reconnecting at 10:57:55 (the backoff step was at its 5 min cap after many offline attempts, so inside the window).
4. DB of the e2e server: exactly 2 media on the SPK, `image/jpeg`, captions "Foto offline 1" and "Foto offline 2", no duplicates. The SPK then showed both as "Anda" with "Hapus", and "Kirim untuk review" enabled, no "Tunggu".
5. Offline with 2 queued photos: Akun → Keluar → "Keluar dari akun?" / "2 bukti belum terkirim akan dihapus. Keluar?"; Batal kept the session and the queue. The confirm path (wipe) is covered by the widget test only, not exercised live.

Not exercised live: a different-user login wipe, the 401 pause (unit tests only), "Coba lagi" re-enabling an action (widget test only). The integration test ran before the last cosmetic move of the SPK banner (banner moved above the refresh area, rounded); widget tests ran after. Teardown: e2e API, AVD, `adb reverse`, lock holder stopped; the throwaway SPK and its media deleted; `env/dev.local.json` and temp scripts deleted; no tokens recorded.
