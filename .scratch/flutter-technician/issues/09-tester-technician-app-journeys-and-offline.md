# TESTER: technician app journeys, offline and duplicate-prevention proof, accessibility, live smoke

Status: done
Role: tester (qa)
Blocked by: 08
Cross-lane blocked by: mobile-api 08 (API matrix green)
Card: none (new lane; no Hermes card)
Skills: tdd, antislop-human, antislop-layoutmobile, ponytail, caveman

## Scope

An independent check of the finished technician app. Add tests and evidence only. A defect becomes a `needs-change` to the owning ticket, with a failing test.

- `integration_test` journeys on the AVD `inbils_pixel_api35` with the fake adapter and fixtures from `docs/api/mobile-v1.openapi.json` or recorded API responses:
  1. Login → My work → SPK → Mulai → capture 2 photos online → declare → submit.
  2. Offline capture → app restart → online → each photo uploaded once (adapter call count = 2).
  3. A timeout mid-upload → a retry with the same key.
  4. `422` on upload → failed → delete.
  5. 401 with queued photos → re-login as the same user → resumes; as another user → wiped.
  6. Ticket start → internal comment → resolve.
  7. A notification → routing for every subject type.
  8. A Material request that is "Siap diambil".
  9. Logout with queued photos → warning.
- Duplicate-prevention proof against the real API (compose stack): force-stop the app during an upload, reopen, sync. The SPK has exactly one media row per captured photo (check with a read-only database query or the web page). Record the counts. Long-delay retries are covered on the server by mobile-api ticket 05 (dedupe after the cache entry is gone); cite that test.
- Accessibility: the Flutter guideline tests on every top-level screen, at least 56 dp on the SPK action bar, a TalkBack walk of journey 1, and 200% text scale screenshots.
- Live smoke on the compose stack (run `tests/e2e/setup.php` first; `adb reverse tcp:8080 tcp:8080`), using the spec's live-smoke steps, with airplane mode toggled through `adb`.

## Owned files

- NEW `../inbils-mobile/technician/integration_test/**`

## Acceptance criteria

- [x] `flutter test integration_test -d <emulator-id> --flavor dev --dart-define-from-file=env/dev.json` is green, with the output pasted. (`+83: All tests passed!`, see the first comment.)
- [x] Every Must-have case in the spec's Testing Decisions maps to a passing test, listed in a ticket comment. (Must-have map below.)
- [x] The duplicate-prevention proof shows media count = photos captured after a force-stop mid-upload, and the API dedupe test from mobile-api ticket 05 is cited as passing. (3 photos, 3 media rows, one key sent twice; the API test is cited with the result recorded by mobile-api 08. I did not rerun web PHPUnit.)
- [x] The accessibility results and the live smoke transcript are recorded as seen, and mismatches with the web (statuses, counts) are reported as findings. (No status or count mismatch; findings F1 to F6 below.)
- [x] No app source file changed in this ticket. (Commit `9a9de41` touches only `technician/integration_test/**` and one host test file under `technician/test/`.)

## Comments

### 2026-10-12: tests, proof, accessibility and live smoke (agent QaTech09)

Commit `9a9de41` in `inbils-mobile` (not pushed): `test(technician): integration journeys, offline proof, accessibility checks and live smoke`. Status is `done`: all five criteria are met. One accessibility defect is open as a `needs-change` (F1); it does not fail the suite because the label check skips that one screen (`unlabeledRowScreens`), and a separate repro fails on the current app.

**Files** (`technician/integration_test/`): `api_fixtures.dart` (JSON fixtures; `fixtures.dart` from ticket 06 holds the image fixtures), `support.dart` (`FakeApi`, `SpkServer`, the `Tech` harness), `fixture_contract_test.dart`, `journeys_test.dart`, `accessibility_test.dart`, `text_scale_test.dart`, `screens.dart` (the 16 top-level screens), `repro_unlabeled_material_rows.dart` (not a `_test` file). Also `technician/test/accessibility_contrast_test.dart` (host, same precedent as the customer app, because the device contrast check is unreliable). The tests reuse `FakeCapture` and `FakeUploader` from `test/support/spk_harness.dart`.

**How the journeys run.** The API is the fake adapter with fixtures; the queue, its syncer, the SQLite file, the photo directory and the image normaliser are the real ones. A "restart" closes the app and opens it again on the same file and secure storage. The fake server answers a key it has seen with the first answer and stores nothing again (what mobile-api 05 does). `FakeApi.received` counts only requests that reached the server: an attempt made offline does not count.

**Fixtures.** The OpenAPI file has no `example` values, so the fixtures are written from its schemas (`TechnicianWorkOrderResource`, `TechnicianTicketResource`, `MyWorkItemResource`, `NotificationResource`, ...) and parsed by the app models (`fixture_contract_test.dart`: 29 fixtures and 2 rule tests). Tokens are placeholders.

**Device run** (AVD `inbils_pixel_api35`, API 35, dev flavor, `emulator-5554`):

```
$ flutter test integration_test -d emulator-5554 --flavor dev --dart-define-from-file=env/dev.json
06:22 +83: All tests passed!
```

By file: fixture contract 31, journeys 11 (journeys 1 to 9, with 2b and journey 5 as two tests), accessibility 19 (16 screens, 3 action bars), 200% text 16, plus the earlier `offline_journey_test` (1) and `evidence_normalizer_test` (4). The runner counts one extra `tearDownAll`. Host: `flutter test` in `technician` 170 passed (154 before plus 16 contrast tests); `dart format --set-exit-if-changed .` 0 changed; `flutter analyze` no issues. One of the host runs showed `evidence_queue_test.dart: upload two captures upload in FIFO order, one at a time` failing once; it passed in two single runs and in the next full run (not investigated, looks like a timing flake).

**Red checks.** Mutated fixtures (`spk_21_assigned` actions.start false, a required `title` dropped from the ticket): 5 contract tests failed, fixtures restored. A fake server that does not dedupe by key: journey 2b failed with `Actual: ['Foto 1', 'Foto 1']` (a duplicate), restored. A scratch widget with `#CCCCCC` text on white: the host contrast guideline failed with `found 1.53`, scratch file deleted. The label guideline fails on the Material screen (F1).

**Journeys** (`journeys_test.dart`; the ticket numbers):

| # | Test | What it proves |
|---|---|---|
| 1 | `1 login, My work, SPK, Mulai, 2 photos online, declare, submit` | Mulai confirm, one keyed POST, 2 photos with 2 keys, queue and files empty, submit gated by the declaration, declaration sent, one submit, "Menunggu review" |
| 2 | `2 offline capture, restart, online: each photo goes up once` | 2 captures offline stay `pending` with 2 keys, nothing reaches the server, actions off offline, restart keeps the queue and the saved copy ("Data offline"), back online `spk.posts == 2` with the captured keys, rows and files gone |
| 2b | `2b an app kill mid-upload: the row is pending again and the retry reuses its key` | the server stores the photo and never answers, the app is killed (`uploading` row), restart: the same key is sent again, the server holds 1 photo |
| 3 | `3 a timeout mid-upload, then a retry with the same key` | timeout leaves the row `pending`, "Kirim sekarang" sends the same key |
| 4 | `4 a 422 on upload shows failed, and Hapus removes the photo` | `Gagal` with the server message, not resent, Hapus removes row and file |
| 5 | `5 a 401 with queued photos ...` (2 tests) | the 401 signs out with the reason, the queue survives; same technician: resumes with the captured keys; another technician: wipes rows and files with no request |
| 6 | `6 ticket: start, internal comment, resolve` | start with `check_in`, `is_internal: true` comment, resolve note, keys |
| 7 | `7 a notification opens the screen of its subject type` | work_order, ticket, material_return open their screen; inventory_request opens Material; invoice and no subject stay in the inbox (and count as read) |
| 8 | `8 a Material request that is "Siap diambil"` | text and "Status: Siap diambil" semantics, no pickup action, SPK link |
| 9 | `9 logout with queued photos warns, Batal keeps them, Keluar wipes them` | the count in the warning, Batal keeps rows and session, Keluar wipes rows, files and storage; Keluar is reachable offline |

**Must-have map** (spec, Testing Decisions). Host tests are in `technician/test/`, device tests in `technician/integration_test/`:

| Must-have | Test |
|---|---|
| Capture offline: `pending` with a stable key; online: one POST, row and file deleted | journey 2; `evidence_queue_test.dart` (`inserts a pending row with a UUID key and the file exists`, `201 sends a multipart with the key, then deletes the row and the file`); `offline_journey_test.dart` |
| Timeout mid-upload: retry with the same key | journey 3; `evidence_queue_test.dart` (`a timeout keeps the row pending and the next attempt sends the same key`); `sync_test.dart` (`a failed upload waits by the schedule, a 429 by its Retry-After`) |
| `422`: `failed`, then retry or delete | journey 4; `evidence_queue_test.dart` (`422 becomes failed with the message and is not retried by the next drain`, `Coba lagi sends the same key`, `removes the row and the file`); `evidence_screens_test.dart` group `queue screen` |
| `401` pauses; the same user resumes | journey 5 (same technician); `sync_test.dart` (`a 401 pauses and keeps the row; the same user logging in sends the same key`) |
| A different user wipes the queue | journey 5 (another technician); `sync_test.dart` (`a different user logging in wipes the cache, the rows and the files`) |
| App kill mid-upload: row `pending` again at start | journey 2b; live force-stop (below); `sync_test.dart` (`a row stuck in uploading goes back to pending`) |
| Cache: offline list and SPK detail with a timestamp | journey 2 ("Data offline" after a restart); `offline_cache_test.dart` (`the SPK opens offline from its saved copy, with the time`, `the work list opens offline from its saved copy, with the time`); `offline_journey_test.dart` |
| Cache: 7-day purge | `sync_test.dart` (`cache entries older than 7 days are purged, newer ones stay`) |
| Cache: wipe on logout | journey 9; `offline_cache_test.dart` (`confirming deletes the queue and signs out`); `sync_test.dart` (`a wipe removes rows, files and cache`) |
| Submit gating reasons | journey 1 and 2 ("Deklarasikan material dulu", "Tunggu 2 foto terkirim"); `work_order_actions_test.dart` group `Kirim untuk review`; `offline_cache_test.dart` (`waits for the photos of this SPK that are still queued`) |
| Start, submit, declare, resolve disabled offline | journey 2 (submit off offline); `work_order_actions_test.dart` (`offline says it needs a connection and turns the button off`); `ticket_actions_test.dart` (`offline says it needs a connection and keeps the button`); `offline_cache_test.dart` (`are off after a request failed offline, and come back with a successful read`) |
| Image normalisation: HEIC or PNG to JPEG, no EXIF GPS, long edge at most 1920 | `evidence_normalizer_test.dart` (4 tests on the device, ticket 06; HEIC is not covered, as there) |
| Maps and phone intents, with coordinates and with an address only | `work_test.dart` (`Telepon and Buka di Maps go to the launcher`, `without coordinates Maps gets the encoded address`, `mapsUri prefers coordinates, falls back to the address`, `telUri drops the spaces and dashes a person types`) |
| The logout guard | journey 9; `offline_cache_test.dart` (`warns, and Batal keeps everything`) |
| Live smoke steps 1 to 4 | live smoke below |

**Duplicate-prevention proof** (real API; deviation: the ticket says the compose stack on 8080, I used the Laravel e2e server `php artisan serve --env=e2e` on 8011 with `adb reverse tcp:8011`, after `tests/e2e/setup.php`, under `/tmp/inbils-phpunit.lock`; `CACHE_STORE=array` there, so no HTTP replay cache exists and only the service-level key dedupe of mobile-api 05 can answer). SPK 14 `in_progress`, 0 media at the start:
1. Airplane mode on (`cmd connectivity airplane-mode enable`) and `adb reverse --remove tcp:8011`: 3 photos taken with the real emulated camera, captions "Dup 1" to "Dup 3": banner "3 foto belum terkirim", "Tunggu 3 foto terkirim".
2. Airplane off, `adb reverse` back, the API process paused with SIGSTOP, pull to refresh: "Dup 1" showed "Mengunggah", "Dup 2" and "Dup 3" "Menunggu". `am force-stop`, then SIGCONT: the server stored the request after the app was dead. DB: **media 1** ("Dup 1").
3. Reopened the app: the stuck row was reset, the sync ran on its own, the 3 photos showed "Anda" within 2 s. DB: **media 3** = photos captured, captions "Dup 1", "Dup 2", "Dup 3", 3 distinct `idempotency_key` values. The API log shows 4 POSTs to `work-orders/14/evidence` (one lost-answer attempt, then the retry of Dup 1 at 14:08:32 and Dup 2 and Dup 3 at 14:08:33); Dup 1 exists once.
4. The server-side proof for a retry after the cache entry is gone: `tests/Feature/Api/V1/Technician/EvidenceTest.php::test_idempotency_dedupe_survives_the_cache_window` (mobile-api ticket 05; ticket 08 records the full suite `Tests: 2807 passed (31074 assertions)` and lists this test in its idempotency row). I did not run web PHPUnit.

**Live smoke** (same server and tools; dev flavor, `pm clear`, app data cold; a throwaway seed of SPK-2026-78898 "LIVE-SPK-09 smoke" (id 13, assigned, with an issued request REQ-LIVE09 and case 12) and SPK-2026-96206 (id 14, for the proof) via `CompanyService::runAs`; screenshots in `/tmp/inbils-qa-technician/live/` outside the repo, no token on any screen). As seen:
1. Login as the seeded technician: Pekerjaan "2 Total, 1 Ditugaskan, 1 Dikerjakan, 1 Menunggu tindakan" with both SPKs. Opened the smoke SPK: "Ditugaskan", "Mulai". Mulai: "Mulai SPK ini?", then the system location dialog (shown because `pm clear` reset the permission), "While using the app": "Dikerjakan", "Dimulai 12 Okt 2026 14:00". DB `in_progress`.
2. Airplane mode on plus `adb reverse --remove`: two photos through "Ambil foto", "Kamera", the emulated camera (Shutter, Done), caption: "2 foto belum terkirim", "Tunggu 2 foto terkirim", "Butuh koneksi internet". Server media 0.
3. Airplane off and `adb reverse` back: after 94 s with no touch the 2 photos were still queued (the 5 min backoff window, as in ticket 07); one pull to refresh sent them in under 3 s: "Foto live 1 / Anda", "Foto live 2 / Anda". DB: **media 2**, no duplicates.
4. Deklarasi (Fiber Cable, "Dikeluarkan 10 ..."): Terpakai 8, Kembali baik 2, Selisih 0, "Kirim deklarasi": back on the SPK with case 12 "Menunggu penerimaan" (DB `awaiting_receipt`); "Kirim untuk review" enabled, tapped: "Menunggu review". DB: SPK `waiting_review`, media 2.

Web comparison (database after the run): SPK 13 `waiting_review`, 2 media with the captions typed, case 12 `awaiting_receipt`, SPK 14 `in_progress` with 3 media. Statuses and counts match what the app showed; I did not open the web SPK page in a browser, the database query is the read-only check the ticket allows. Not exercised live: HEIC, the 422 and 401 paths (journeys only), a real Mulai location fix (no `adb emu geo fix`).

**Accessibility.**
- Tap target (48 dp) passes on all 16 screens on the device. Labelled tap target passes on 15 screens and fails on Material (F1).
- SPK action bar: `Mulai` and `Kirim untuk review` and the ticket `Selesaikan` are at least 56 dp high and on screen without scrolling (`the SPK action bar (Mulai) is at least 56 dp`, `... (Kirim untuk review) ...`, `the ticket action bar (Selesaikan) is at least 56 dp`).
- Text contrast passes on all 16 screens on the host (`test/accessibility_contrast_test.dart`; the device guideline is not trustworthy, see the customer ticket 07).
- 200% text: `text_scale_test.dart` runs the 16 screens with text scale 2.0 and no RenderFlex overflow (16 passed). Screenshots, system font scale 2.0 taken with adb: `/tmp/inbils-qa-technician/scale200/*.png`. What they show: F2 and F3 below; everything else stays readable and reachable, and the action bar stays in view.
- TalkBack (best effort; installed on the AVD and enabled with `settings put secure enabled_accessibility_services`): it is bound and requests audio focus for speech, but its speech text is not logged and gestures sent with `adb input` do not move its focus, so a spoken walk of journey 1 was **not possible**. What was possible: the accessibility tree read with `uiautomator dump` with TalkBack on, for login, Pekerjaan, the SPK detail, the "Ambil foto" sheet: `/tmp/inbils-qa-technician/talkback/*.txt`. Buttons and rows carry labels ("Masuk", "Tampilkan kata sandi", the SPK row as one node "SPK · ... / LIVE-SPK-09 dup / Jadwal ... / Dikerjakan", tabs "Tab 1 dari 4", "Kamera", "Galeri"); the two login text fields show no label in the dump (uiautomator does not print hint text, so not conclusive). F4 comes from this tree.

**Findings.** None fixed here (no app change).
- **F1, needs-change (blocks a clean label check)** `technician/lib/features/materials/materials_screen.dart` (ticket 08, `_ReturnRow`): the `InkWell` of the "Perlu dideklarasi" and "Perlu koreksi" rows is outside the `MergeSemantics`, so TalkBack gets a tappable node with no label next to a label with no action (the customer rows had the same defect, fixed in `6ecd8d6`). Fix: wrap the `InkWell` in `MergeSemantics`. Failing test: `integration_test/repro_unlabeled_material_rows.dart` (`Expected: Tappable widgets should have a semantic label ... expected tappable node to have semantic label, but none was found`). When it passes, delete `unlabeledRowScreens` in `accessibility_test.dart` and the repro file.
- **F2 (ticket 03, `work_screen.dart`)** At 200% the summary counts break words in the middle: "Ditugas/kan", "Dikerjak/an", "Menung/gu tindakan" (`scale200/Pekerjaan.png`). Readable, but ugly; a wrap of whole words or a 2 by 2 grid would hold.
- **F3 (ticket 08, `materials_screen.dart`)** At 200% the SPK code in the "Perlu dideklarasi" rows breaks as "SPK-002 / 1" because the status chip takes the width (`scale200/Material.png`).
- **F4 (ticket 03 and 06, low)** In the SPK detail the photo buttons all read "Buka foto layar penuh" and the delete buttons all read "Hapus", with the caption in a separate node, so with 3 photos a screen reader user cannot tell which photo a button belongs to. Fix: put the caption in the labels or merge each photo's nodes.
- **F5 (info, UX)** The snackbar "Foto masuk antrean unggah." floats over the bottom action bar and the tabs for about 4 seconds after each capture and covers "Deklarasi", "Kirim untuk review" and the tabs (it hid the taps in two of my runs; the tests clear it). An anchored snackbar or a shorter duration would avoid it.
- **F6 (info)** After the airplane-mode toggle the queue did not send for 94 s without a touch (backoff at its cap, as in ticket 07); a pull to refresh sent at once. Expected by the design; a connectivity listener would shorten it, out of scope.

Teardown: emulator, API server, lock holder, `adb reverse` and the temporary `env/dev.local.json` stopped or removed; system font scale, airplane mode and the TalkBack setting were reset; the shared test database still holds the throwaway SPKs 13 and 14 (run `tests/e2e/setup.php` before the next E2E run). Host scripts (`/tmp/qa09_*.py`, `/tmp/qa09_seed.php`) stay in `/tmp`, outside the repo.

### 2026-10-12: fixes for F1 to F4 and the queue flake (agent MobTechFix09)

Commit in `inbils-mobile` (not pushed): `fix(technician): material row semantics, 200% text layout and queue test flake`.

- **F1 fixed.** `_ReturnRow` now wraps its `InkWell` in `MergeSemantics`. `unlabeledRowScreens` is gone from `accessibility_test.dart`; `repro_unlabeled_material_rows.dart` became `material_row_semantics_test.dart` and passes.
- **F2 fixed.** The work summary is a `Wrap`, so whole counts move to the next line at 200% text instead of breaking words.
- **F3 fixed.** The `_ReturnRow` code and status chip sit in a `Wrap`; the chip drops under the SPK code at 200%.
- **F4 fixed.** The open button reads "Buka foto layar penuh: <caption>" and the delete button "Hapus: <caption>". A photo with no caption keeps the plain label (not distinguishable; there is no index string in the ARB files).
- **F5 not done** (not trivial: the snackbar comes from the capture flow and needs an anchored layout). F6 unchanged.
- **Flake root cause:** the test, not the app. `pumpEventQueue()` gives a fixed number of event-loop turns, but the drain does real sqlite and file IO before each upload, so the second gate sometimes was not open yet. The test now waits until each upload has started.
- **Checks:** `dart format`, `flutter analyze` 0 issues in inbils_core, customer and technician; `flutter test`: 224, 113, 170 passed; `evidence_queue_test.dart` 5 runs all green (14 each); device suite on `emulator-5554` (dev flavor): `+84: All tests passed!`. Emulator stopped.
- Not visually re-checked at 200% on the device (the 200% overflow tests pass; the screenshots were not retaken).
