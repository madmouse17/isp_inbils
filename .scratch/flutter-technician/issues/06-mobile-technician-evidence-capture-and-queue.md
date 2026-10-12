# MOBILE: evidence capture (camera or gallery, JPEG normalisation), persistent upload queue (drift), queue screen, delete own evidence

Status: done
Role: mobile
Blocked by: 05
Cross-lane blocked by: mobile-api 05 (evidence endpoints)
Card: none (new lane; no Hermes card)
Skills: antislop-ui, antislop-human, antislop-layoutmobile, tdd, ponytail, caveman

## Scope

Implements Q2 (drift, queue table only), Q4 and Q5, plus the upload half of Q1. Every upload goes through the queue from day one, so ticket 07 adds triggers and caching without replacing anything here.

- "Ambil foto" on the SPK detail when `actions.upload_evidence`:
  1. Choose Kamera (default) or Galeri, with `image_picker` (no CAMERA permission declared; the system photo picker for the gallery).
  2. Normalise with `flutter_image_compress`: JPEG, long edge ≤1920 px, quality 80, no EXIF.
  3. Optional caption (≤255).
  4. Write the file to `<app documents>/evidence/<idempotency_key>.jpg` and insert a `pending` row.
  5. Return to the detail and attempt the upload right away.

  `retrieveLostData()` on start recovers a capture interrupted by Android killing the activity.
- drift database: the `evidence_queue` table exactly as in the spec (with `user_id`), schema version 1, plus a migration test harness.
- Uploader, one at a time, FIFO: `POST technician/work-orders/{id}/evidence` (multipart `file`, `caption`, `captured_at`), with the row's fixed `Idempotency-Key` and a longer upload timeout. The result mapping follows the spec's queue rules: `201` deletes the row and the file; network, 5xx and 429 errors stay `pending`; `403`, `404` and `422` become `failed` with the message. Automatic retry timing belongs to ticket 07: here a pending row retries on the next capture, on "Kirim sekarang", or when the screen is reopened.
- The SPK gallery merges server evidence and queued rows for this SPK, with state chips "Menunggu", "Mengunggah" and "Gagal".
- Queue screen `/uploads` (from the banner on the work list and the SPK detail): rows with thumbnail, SPK code, caption, state and error message; "Kirim sekarang"; per row "Coba lagi" or "Hapus" (confirm; deletes the file).
- Delete own uploaded evidence: on a server item with `mine` while the SPK is `in_progress`, "Hapus" (confirm) → `DELETE …/evidence/{mediaId}` with a key. On success it leaves the gallery.

Non-goals: the read cache, automatic triggers and backoff, the logout guard, user-switch wipe (all ticket 07), PDF or Office evidence, GPS stamping.

## Owned files

- NEW `../inbils-mobile/technician/lib/data/local/` (drift database, `evidence_queue` table, DAO, generated files), NEW `../inbils-mobile/technician/lib/features/evidence/` (capture, normaliser, uploader, queue screen)
- `../inbils-mobile/technician/lib/features/work_orders/` (gallery merge, "Ambil foto", delete own), `../inbils-mobile/technician/lib/features/work/` (queue banner only)
- `../inbils-mobile/technician/pubspec.yaml` (`image_picker`, `flutter_image_compress`, `drift`, `drift_dev`, `sqlite3_flutter_libs` or the drift-recommended native setup, `path_provider`), `../inbils-mobile/pubspec.lock` (regenerated; shared lock-file rule)
- `../inbils-mobile/technician/lib/app/router.dart`, `../inbils-mobile/technician/lib/l10n/`, `../inbils-mobile/technician/test/`

## Acceptance criteria

- [x] Normaliser unit tests with fixture images (PNG, a 4000 px JPEG with GPS EXIF, HEIC when the test environment can decode it, otherwise noted): the output is JPEG, the long edge ≤1920 px, and there is no EXIF GPS.
- [x] Queue unit tests (in-memory drift, fake adapter):
  - a capture inserts `pending` with a UUID key, and the file exists;
  - `201` deletes the row and the file;
  - a timeout keeps `pending` with the same key on the next attempt;
  - `422` → `failed` with the message;
  - "Coba lagi" sends the same key;
  - "Hapus" removes the row and the file;
  - two captures upload in FIFO order, one at a time.
- [x] Widget tests: "Ambil foto" appears only when `actions.upload_evidence`; the gallery shows server and queued items with state chips; deleting own evidence appears only for `mine` while `in_progress`.
- [x] Live on the AVD against the compose stack: start an SPK, take 2 photos, see them upload, and check the web SPK page shows exactly 2 new evidence files. Kill the app mid-upload (`adb shell am force-stop`), reopen and send: still exactly 2. Described as seen.
- [x] The resolved `drift`, `drift_dev`, `image_picker` and `flutter_image_compress` versions are pasted, with the generators still on one analyzer.
- [x] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments

### 2026-10-12: done (agent MobTech06)

Commit `39ce51d` in `inbils-mobile` (not pushed). `dart format --set-exit-if-changed .` clean, `flutter analyze` 0 issues, `flutter test` green: technician 102 (33 new: 14 queue, 17 screens, 2 migration), customer and core untouched. Normaliser integration test on the AVD: 5 passed.

What landed:
- `lib/data/local/app_database.dart`: drift `evidence_queue` exactly as in the spec (with `user_id`), schema version 1. `watchQueue` is hand-written (re-reads after each write) because drift's `watch()` leaves a cancel timer that fails every widget test. `drift_schemas/` + `test/generated_migrations/` + `test/migration_test.dart` are the migration harness (the file's header says how to add a v2 test); `technician/analysis_options.yaml` only excludes the generated helpers.
- `features/evidence/`: `EvidenceNormalizer` (`flutter_image_compress`: JPEG, q80, long edge ≤1920, `keepExif: false`), `EvidenceUploader` (enqueue, FIFO single-flight `drain`, `retry`, `discard`, `recover`), `EvidenceCapture` (`image_picker`, no CAMERA permission; a note file keeps the SPK across an Android kill so `retrieveLostData()` can queue the photo), "Ambil foto" sheet and caption dialog, `/uploads` screen, `UploadsBanner` (work list and SPK detail).
- Result mapping: `201` deletes row and file; offline/timeout/5xx/429/409 `idempotency_in_progress`/401 stay `pending` and stop the drain (order holds); every other 4xx becomes `failed` with the first field error or the server message. A vanished file fails with "Berkas foto hilang". Retry triggers: next capture, "Kirim sekarang", "Coba lagi", reopening the SPK detail. At start `uploading` rows go back to `pending` and orphan `.jpg` files are removed.
- SPK detail: server items and queued rows with "Menunggu"/"Mengunggah"/"Gagal" chips; "Hapus" on own evidence while `in_progress` (confirm, `DELETE` with a key reused after an undecided failure).
- Not done here: the "Tunggu N foto terkirim" submit gate. Tickets 04 and 07 both place it after the 07 queue triggers, so it is left to 07.

Packages: none added. `drift` 2.35.2, `drift_dev` 2.35.1, `drift_flutter` 0.3.1, `image_picker` 1.2.4, `flutter_image_compress` 2.5.1 (`_common` 1.1.1), `sqlite3` 3.7.0 were already in ticket 01's pubspec and lock; the lock is unchanged. One analyzer for every generator: `analyzer` 14.5.0 (`source_gen` 4.3.0, `build_runner` 2.16.2, `riverpod_generator` 4.0.9, `drift_dev` 2.35.1). Known limit: `riverpod_generator` cannot see the row class drift generates, so `queuedEvidenceProvider` is hand-written.

Normaliser tests: `integration_test/evidence_normalizer_test.dart` runs on a device (the plugin has no host implementation): a 4000x40 JPEG with GPS + maker EXIF (its GPS block is detected in the input first) → JPEG, long edge 1920, no GPS tag, no maker string; a 2500 px PNG → JPEG ≤1920; a file already within the limit keeps its size; a non-image throws and leaves no output. HEIC is not covered: no encoder is available offline to make a fixture; the AVD codec (API 35) decodes HEIC and the path is the same as the PNG one. Fixtures are base64 in `fixtures.dart` (a 4000x3000 JPEG cannot be below ~190 KB, so strips are used).

Live (AVD `inbils_pixel_api35`, dev flavor, `tests/e2e/setup.php`, a throwaway SPK LIVE-SPK-06 assigned to the demo technician, Laravel `APP_ENV=e2e` on 8011 via `adb reverse`, under `/tmp/inbils-phpunit.lock`), as seen:
1. Login → Pekerjaan → SPK "Ditugaskan" → Mulai → "Dikerjakan"; "Ambil foto" appeared. Ambil foto → sheet "Kamera"/"Galeri" → Kamera opened the emulated camera, shutter, Done → caption dialog "Keterangan foto" → "Foto kabel 1" → Simpan: the photo was uploaded at once (item "Foto kabel 1 / Anda" with "Hapus"). A second photo likewise. DB: exactly 2 media on the SPK, `image/jpeg`, 1392x1856 (the camera frame), caption and `idempotency_key` stored, no EXIF GPS; the app's `evidence/` directory was empty.
2. Offline (`adb reverse --remove`): photos 3 and 4 showed "Menunggu" in the gallery, banner "2 foto belum terkirim"; `/uploads` listed both with SPK code, caption, time, state and "Hapus".
3. Kill mid-upload: API process paused (SIGSTOP), reverse restored, "Kirim sekarang" showed photo 3 as "Mengunggah", `am force-stop`, then the API resumed. The server had stored photo 3 (response lost): 3 media. Reopened the app: both rows "Menunggu" (the stuck `uploading` row was reset); "Kirim sekarang" → "Tidak ada foto dalam antrean."; DB: 4 media, photo 3 once (same key, deduplicated), photo 4 once; `evidence/` empty.
4. "Hapus" on photo 4 → "Hapus foto ini?" → Hapus: it left the gallery, DB 3 media.
5. Galeri path: a 4000x3000 JPEG with GPS EXIF pushed to `/sdcard/Pictures`, picked through the system photo picker ("This app can only access the photos you select"), caption, Simpan: server file `image/jpeg` 1920x1440, no GPS.

Not exercised live: a `422`/`failed` row and "Coba lagi" (unit and widget tests only), HEIC, the Android activity-kill recovery of `retrieveLostData()` (code only; the capture note and the enqueue are not tested on a device). Teardown: API server, AVD, `adb reverse`, lock holder stopped; the throwaway SPK and its media deleted; `env/dev.local.json` and temp scripts deleted; no tokens recorded.

