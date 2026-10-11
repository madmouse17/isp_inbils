# MOBILE: evidence capture (camera or gallery, JPEG normalisation), persistent upload queue (drift), queue screen, delete own evidence

Status: ready-for-agent
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

- [ ] Normaliser unit tests with fixture images (PNG, a 4000 px JPEG with GPS EXIF, HEIC when the test environment can decode it, otherwise noted): the output is JPEG, the long edge ≤1920 px, and there is no EXIF GPS.
- [ ] Queue unit tests (in-memory drift, fake adapter):
  - a capture inserts `pending` with a UUID key, and the file exists;
  - `201` deletes the row and the file;
  - a timeout keeps `pending` with the same key on the next attempt;
  - `422` → `failed` with the message;
  - "Coba lagi" sends the same key;
  - "Hapus" removes the row and the file;
  - two captures upload in FIFO order, one at a time.
- [ ] Widget tests: "Ambil foto" appears only when `actions.upload_evidence`; the gallery shows server and queued items with state chips; deleting own evidence appears only for `mine` while `in_progress`.
- [ ] Live on the AVD against the compose stack: start an SPK, take 2 photos, see them upload, and check the web SPK page shows exactly 2 new evidence files. Kill the app mid-upload (`adb shell am force-stop`), reopen and send: still exactly 2. Described as seen.
- [ ] The resolved `drift`, `drift_dev`, `image_picker` and `flutter_image_compress` versions are pasted, with the generators still on one analyzer.
- [ ] `dart format`, `flutter analyze` (0 issues) and `flutter test` are green.

## Comments
