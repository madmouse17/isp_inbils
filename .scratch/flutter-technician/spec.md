# Flutter technician app (Android) — my work, SPK execution, evidence with offline queue, tickets, materials

Status: in-progress (tickets 01-09 and 11 done; 10 release waits on the owner: upload key, HTTPS hosts, brand icons, Play answers)
Ledger: none yet (new lane; owner request 2026-10-11)
Source: owner request 2026-10-11 (a separate technician app; technicians lose signal in the field, so evidence uploads must queue); earlier draft `docs/MOBILE_TASK_PLAN.md` §4.1 and §6 (local, git-ignored).
Depends on: `.scratch/mobile-api/` (contract) and `.scratch/flutter-customer/` tickets 01, 02 and 06 (workspace, `inbils_core`, shared auth and inbox).

> Nothing starts before the owner answers the decisions below. "setuju rekomendasi" approves the recommended option of every item. Keystores, `key.properties` and tokens stay outside the repository.

**Inherited decisions** (answered once, in `.scratch/flutter-customer/spec.md`): Q1 layout (this app lives at `../inbils-mobile/technician` in the shared workspace), Q2 Riverpod 3, Q3 go_router, Q4 dio with interceptors and idempotency keys, Q5 freezed, Q6 secure token storage with backup off, Q8 Indonesian only, Q9 theme, Q10 app id `id.inbils.technician` and name "Inbils Teknisi", Q11 flavors and `adb reverse`, Q12 lints, Q13 tests, Q15 no crash SDK, Q17 toolchain pin and `targetSdk` 36. Also from `.scratch/mobile-api/spec.md`: no push (Q17), materials = usage declaration (Q13), read-only material requests (Q14), evidence rules and policy (Q12), idempotency (Q11).

Approved by owner 2026-10-13: recommended option of every decision, PLUS GPS check-in at the job site (owner answered "semua" to the offline/location question): see ticket 11. App id id.inbils.technician.

**Location (owner change):** the Flutter workspace does not live in this repository. It is its own git repository, the sibling folder `../inbils-mobile/` (`/home/madmouse/Documents/web/inbils-mobile/`). Every `../inbils-mobile/...` path in this lane is relative to the root of this (web) repository.

## Owner decisions

1. **Q1 — What works offline.** Field signal is unreliable (owner). The API makes evidence uploads permanently idempotent per SPK and material declarations idempotent by key (`.scratch/mobile-api/spec.md` Q11). SPK start and submit and ticket actions are state transitions guarded by status checks on a locked row (`Modules/SPK/app/Services/SpkService.php:141,160`, `Modules/Ticketing/app/Services/TicketService.php:158,192`).
   (a) Read-only cache of "my work" and of every SPK and ticket the technician opened, plus a persistent **evidence outbox**: photos taken offline upload later, in order, with the same idempotency key on every retry. Every other action needs a connection and says so. (b) (a) plus queued material declarations. (c) A full offline outbox for every action, with conflict resolution screens. (d) No offline support.
   **Recommend (a).** It covers the owner's stated pain (photos at a site without signal) with the smallest safe surface. Start, submit and resolve happen where there is signal (before leaving, or back on the road), and the server stays the only state machine. (b) is a small follow-up if the pilot shows declarations are made offline. (c) multiplies conflict cases (an SPK reassigned or rejected while offline).

2. **Q2 — Local storage engine.**
   (a) `drift` 2.35 (typed SQLite with migrations, transactions and reactive queries for the queue screen). Code generation shares `build_runner`, and `drift_dev` 2.35.1 accepts analyzer 14 like the other generators. The earlier plan chose it too (`docs/MOBILE_TASK_PLAN.md` §5). (b) `hive` or `hive_ce`, key-value boxes; the original `hive` is unmaintained. (c) Raw `sqflite`. (d) JSON files.
   **Recommend (a).** Tradeoff: one more generator and a schema version to migrate.

3. **Q3 — When the queue syncs.**
   (a) Only while the app is open:
   - on start (after session restore), on resume and right after a capture;
   - on pull-to-refresh and on "Kirim sekarang";
   - by a backoff timer while rows are pending: 30 s, 1 min, 2 min, then every 5 min, honouring `Retry-After`.

   No connectivity package and no background worker. (b) `workmanager` background jobs. (c) A `connectivity_plus` listener.
   **Recommend (a).** Tradeoff: queued photos wait until the technician opens the app with signal; a banner reminds them. Background execution on Android is throttled and vendor-dependent, so (b) adds failure modes without a guarantee.

4. **Q4 — Camera and image handling.** The server accepts `jpg,jpeg,png,pdf,doc,docx` up to 10 MB (`Modules/SPK/app/Http/Controllers/WorkOrderController.php:356`); gallery images can be HEIC or WebP.
   (a) `image_picker` 1.2 (the system camera intent, so no CAMERA permission; and the system photo picker) plus `flutter_image_compress` 2.5. Every image is normalised to JPEG: long edge 1920 px, quality 80, EXIF removed (location included). Typical size is 300-900 KB. Recover the result after Android kills the activity (`retrieveLostData`). (b) The `camera` plugin with an in-app viewfinder: the CAMERA permission and more code. (c) `image_picker` alone, which would let HEIC or WebP through for the server to refuse.
   **Recommend (a).**

5. **Q5 — Evidence types in the app.**
   (a) Photos only (camera or gallery). PDF and Office files stay on the web. (b) Also files through `file_picker`.
   **Recommend (a).** Field evidence is photos. Tradeoff: a signed paper form is photographed, not scanned to PDF.

6. **Q6 — Maps and navigation.** SPK sites have coordinates on `locations` (`database/migrations/2026_06_30_210000_create_locations_table.php:20-21`) or on the installation address (`database/migrations/2026_06_30_220001_create_customer_addresses_table.php:19-20`). The web uses Leaflet with OSM (`package.json:70`).
   (a) "Buka di Maps" opens Google Maps directions (`https://www.google.com/maps/dir/?api=1&destination=<lat>,<lng>`, or the address text when there are no coordinates) through `url_launcher`. "Telepon" uses `tel:`. No map SDK and no API key. (b) An in-app map with `flutter_map` and OSM tiles (OSM's tile policy limits heavy use without your own tile server). (c) `google_maps_flutter`, which needs an API key and billing.
   **Recommend (a).**

7. **Q7 — Device location.**
   (a) None in v1: no location permission and no GPS stamp. (b) Stamp each photo with GPS position and accuracy as proof of presence, which needs `geolocator`, a runtime permission and new evidence fields in the API.
   **Recommend (a).** Tradeoff: supervisors cannot check presence from the app. If they need to, that is a separate decision with a privacy note.

8. **Q8 — Barcode or QR scanning.** Technicians do not pick assets; staff set `network_asset_id` on SPK items (`Modules/SPK/app/Http/Requests/StoreWorkOrderItemRequest.php:25-37`).
   (a) None in v1. (b) `mobile_scanner` for asset lookup.
   **Recommend (a).**

9. **Q9 — Protecting cached customer data.** Cached SPKs hold customer names, phones and addresses.
   (a) App-private SQLite holding only the fields the screens show. Rows older than 7 days are purged. Everything is wiped on logout and when another user signs in on the same phone. No database encryption in v1. (b) An SQLCipher-encrypted database (a native library, with the key in secure storage).
   **Recommend (a).** Android sandboxes app storage and backup is off (inherited Q6). Tradeoff: a rooted or forensically imaged phone can read the cache; (b) raises the bar at the cost of a native dependency.

10. **Q10 — Pending photos at logout or session loss.**
    (a) Logout with pending photos asks "N bukti belum terkirim akan dihapus. Keluar?" and deletes them only on confirm. A 401 (revoked or expired) pauses the queue and keeps it for the same user id, and the queue resumes after that user logs in again. A different user signing in wipes it. (b) Always wipe.
    **Recommend (a).** A revoked token, for example after a password change, must not destroy a day of field photos.

11. **Q11 — Distribution.**
    (a) A Play Console internal testing track (up to 100 testers, fast updates, no public listing) for staff. Move to a closed track if the internal-track limits bite. (b) A signed APK shared through MDM or WhatsApp. (c) A public Play listing.
    **Recommend (a).** Updates arrive through Play, and nothing is sideloaded. Signing follows the customer lane Q16.

## Findings and corrections

- The earlier plan's field app ("Field Ops") had checklists, asset scanning, explicit asset selection, serial, MAC, signal, port and VLAN input, material movements and supervisor maps (`docs/MOBILE_TASK_PLAN.md` §4.1, §4.5). None of these has a technician-facing backend today: technicians cannot edit SPK items (`StoreWorkOrderItemRequest.php:14-17`), and SPKs have no checklist or test-result fields (`Modules/SPK/app/Models/WorkOrder.php:69-75`). They are out of scope until the web grows them.
- There are no SPK comments (`Modules/SPK/app/Models/WorkOrder.php` has no comment relation); a technician's notes go in the evidence caption.
- The web lets any technician of the company add or delete evidence on any SPK; the API lane fixes this in its ticket 05 (Q12 there). The app shows "Ambil foto" only when `actions.upload_evidence` is true.

## Problem Statement

A technician works from the "My work" page of the admin web on a phone. Every step (start the SPK, take photos, upload them one by one, declare materials, submit) needs a full page load and a good signal. At a customer's site the signal is often gone, so photos are uploaded hours later from memory, uploads fail half way, and SPKs wait for review because evidence is missing.

## Solution

An Android app, "Inbils Teknisi". The technician logs in and sees their assigned SPKs and tickets with counts, even offline from the last sync. They open an SPK to call the customer, navigate to the site, and start the job. They take photos that go into an upload queue: uploads happen immediately when there is signal, or later on their own, each photo exactly once. They declare the materials issued to them and submit the SPK when every photo has arrived. They start, comment on and resolve assigned tickets, see which material requests are ready to pick up, and read their notifications.

## User Stories

1. As a technician, I want to log in with my staff account (and my second factor when enabled), so that I can use the app.
2. As a technician, I want the app to refuse a login if my account is not a technician account, with a clear message, so that I know to ask the office.
3. As a technician, I want my assigned SPKs and tickets in one list with counts for assigned, in progress and waiting, so that I can plan the day.
4. As a technician, I want to filter my list to SPKs or tickets and by status, so that I find the next job fast.
5. As a technician without signal, I want to still see my list and the SPKs I opened, marked with when they were last updated, so that I can work at the site.
6. As a technician, I want an SPK screen with the customer's name and phone, the site address and the job description, so that I know what to do.
7. As a technician, I want to call the customer with one tap, so that I can confirm the visit.
8. As a technician, I want to open the site in Google Maps with one tap, so that I can drive there.
9. As a technician, I want to start an assigned SPK, so that the office sees I am working on it.
10. As a technician, I want to take photos with the camera or pick them from the gallery and add a caption, so that the work is documented.
11. As a technician without signal, I want my photos kept and uploaded later on their own, so that I do not have to remember.
12. As a technician, I want each photo uploaded exactly once even if the network drops mid-upload, so that the reviewer does not see duplicates.
13. As a technician, I want to see which photos are waiting, uploading or failed, and retry or delete a failed one, so that I stay in control.
14. As a technician, I want to delete a wrong photo I uploaded while the SPK is in progress, so that the evidence is correct.
15. As a technician, I want to declare the used, returned and missing quantities of materials issued to me, so that I can submit the SPK.
16. As a technician, I want the submit button to tell me what is missing (photos still uploading, no photo, undeclared materials), so that I fix it before submitting.
17. As a technician, I want to submit an SPK for review, so that my supervisor can approve it.
18. As a technician, I want to see the rejection reason on an SPK sent back for rework, so that I know what to redo.
19. As a technician, I want to open an assigned ticket, start it, add a comment (marked internal if needed) and resolve it with a note, so that I can close faults from the field.
20. As a technician, I want to see my material requests and which are ready for pickup, so that I go to the warehouse at the right time.
21. As a technician, I want a notification list (ticket assigned, SLA breach, request ready, SPK scheduled) that opens the related item, so that I do not miss work.
22. As a technician, I want to see the phones logged into my account and revoke a lost one, so that a stolen phone cannot be used.
23. As a technician, I want logging out with unsent photos to warn me first, so that I do not lose evidence by accident.
24. As a technician whose session expired, I want my unsent photos kept until I log in again, so that a password change does not erase my work.
25. As a technician sharing a phone, I want the previous technician's cached data and queue gone when I log in, so that I never see or send someone else's work.
26. As a technician using gloves or bright sunlight, I want large touch targets and high-contrast text, so that I can use the app outdoors.
27. As a supervisor, I want photos to arrive as compressed JPEG without GPS EXIF, so that storage stays small and customers' locations are not embedded in files.
28. As the owner, I want the app distributed only through Play's internal track, so that updates are controlled and nothing is sideloaded.

## Implementation Decisions

- Location: `../inbils-mobile/technician` (`inbils_technician`), added to the workspace members, using `inbils_core` for the client, session, auth screens, devices, inbox, theme and widgets. App id `id.inbils.technician` (+ `.dev`, `.staging`), names "Inbils Teknisi Dev", "Inbils Teknisi Staging", "Inbils Teknisi".
- Routes (code paths; labels Indonesian):
  - `/splash`, `/login…` (core)
  - Tabs: `/work` (Pekerjaan), `/materials` (Material), `/notifications` (Notifikasi), `/account` (Akun)
  - `/work-orders/:id`, `/work-orders/:id/evidence/new`, `/tickets/:id`, `/material-returns/:id`, `/uploads`, `/account/devices`, `/account/about`
  - Notification `subject` routing: `work_order` → `/work-orders/:id`, `ticket` → `/tickets/:id`, `material_return` → `/material-returns/:id`, `inventory_request` → `/materials`.
- Screens:

| Screen (label) | Data (API) | Must do |
| --- | --- | --- |
| Pekerjaan | `GET technician/my-work` (`domain`, `status`, page) + `meta.summary` | Semua / SPK / Tiket segments, Ditugaskan / Dikerjakan chips, summary counts, rows with code, title, customer, scheduled date or SLA deadline, status chip. Offline: the cached list plus "Data offline · diperbarui HH:mm". Queue banner "N bukti menunggu dikirim" → `/uploads`. |
| SPK detail | `GET technician/work-orders/{id}`; `GET media/{id}` for thumbnails | Header (code, type, status, scheduled date), rejection reason, customer with "Telepon", site with "Buka di Maps", description, items (read-only), evidence gallery merging server items and queued local ones (state chip on each), material-returns section ("Deklarasi" for `awaiting_declaration` / `correction_required`). The action bar follows `actions`: Mulai, Ambil foto, Kirim untuk review. Submit is disabled with the reason when photos for this SPK are queued, there is no evidence, or a case is undeclared. Online-only actions are disabled offline with "Butuh koneksi internet". |
| Ambil foto | local; then `POST …/evidence` via the queue | Camera (default) or gallery → normalise (Q4) → caption (≤255) → enqueue → back to the detail. The upload starts right away when online. |
| Deklarasi material | `GET`/`POST technician/material-returns/{id}[/declare]` | One block per line with product, issued and reserved quantities (as the case resource gives them), and inputs Terpakai / Kembali baik / Selisih. When there is a difference, a code (rusak / hilang / lainnya) and a reason. Client validation: numbers ≥ 0. Server 422 errors show per line. One idempotency key per submit and its retries. Online only. |
| Tiket detail | `GET technician/tickets/{id}` + start, resolve and comment | Info, customer "Telepon", location "Buka di Maps", comments with an "Internal" badge, Mulai (assigned), Selesaikan (in progress; a sheet with a required resolution note of at most 1000 characters), Komentar with a "Catatan internal" toggle. |
| Material | `GET technician/material-returns?status=…`, `GET technician/inventory-requests` | "Perlu dideklarasi" cases (tap → Deklarasi) and "Permintaan barang saya" (read-only, with a "Siap diambil" chip). |
| Antrean unggah | local queue | Rows with thumbnail, SPK code, caption, state (menunggu / mengunggah / gagal + message), "Kirim sekarang", per-row "Coba lagi" / "Hapus" (confirm). |
| Notifikasi | core inbox | Subject routing as above. |
| Akun | `GET me` | Name, e-mail, phone; Perangkat; Tentang; Keluar with the Q10 guard. |

- Local data (drift):
  - `cache_entries(key PK, user_id, json, fetched_at)` holds my-work pages per filter and every SPK, ticket and case detail opened. Entries are purged after 7 days and wiped on logout or when another user signs in.
  - `evidence_queue(id PK, user_id, work_order_id, work_order_code, idempotency_key UNIQUE, file_path, caption, captured_at, state pending|uploading|failed, attempts, next_attempt_at, last_error_code, last_error_message, created_at)`.
  - Files live in the app documents directory (`evidence/<idempotency_key>.jpg`), not the cache directory, which Android may clear. A row and its file are deleted after a `201`. Orphan files are removed at start.
- Queue rules:
  - Processing:
    - One upload at a time, FIFO.
    - `201` → done; refresh the cached SPK detail.
    - Offline, timeout, 5xx, 429 or `idempotency_in_progress` → stays `pending`, with backoff (Q3).
    - `401` → pause the queue (Q10).
    - `403`, `404` or `422` (for example, the SPK is no longer in progress) → `failed`, shown with the message; the technician retries or deletes.
  - The key is created once at capture and never changes, so a retry days later is still deduplicated by the API (`.scratch/mobile-api/spec.md` Q11).
  - `captured_at` = the device time at capture.
- Outdoor use: touch targets of at least 56 dp on the action bar (above the 48 dp minimum), contrast per the inherited Q9 fixes, and nothing conveyed by colour alone.

## Testing Decisions

- Good tests assert what the technician sees, what is sent (path, body, `Idempotency-Key` stability) and what is stored in drift. They never assert widget internals.
- Seams: drift with an in-memory database (unit), the queue syncer with a fake clock and a fake adapter (unit), screens with overridden repositories (widget), journeys on the AVD (`integration_test`) including airplane mode.
- Must-have cases:
  - The queue:
    - capture offline → row `pending` with a stable key; back online → one POST, row and file deleted;
    - a timeout mid-upload → a retry with the same key;
    - a `422` → `failed`, then retry or delete;
    - a `401` pauses, and a re-login by the same user resumes;
    - a different user wipes the queue;
    - an app kill mid-upload → the row is `pending` again on start (a stuck `uploading` row resets).
  - Cache: the offline list and the SPK detail render from cache with a timestamp; the 7-day purge; the wipe on logout.
  - Submit gating reasons; start, submit, declare and resolve disabled offline.
  - Image normalisation: a HEIC or PNG input becomes JPEG with no EXIF GPS (fixture images) and a long edge of at most 1920 px.
  - Maps and phone intents built correctly, both with coordinates and with only an address.
  - The logout guard.
- Live smoke (ticket 09) on the AVD `inbils_pixel_api35` against the compose stack (`adb reverse`; `tests/e2e/setup.php` first):
  1. Login as the seeded technician → My work → open an assigned SPK → Mulai.
  2. Airplane mode on → take 2 photos → see them queued.
  3. Airplane mode off → both upload once (verify in the web SPK page).
  4. Declare materials (when the seed has an issued request) → submit → the web shows `waiting_review`.
- Gates per ticket: `dart format --set-exit-if-changed .`, `flutter analyze` with 0 issues, `flutter test`, and a dev-flavor build. The emulator run is described as seen.

## Out of Scope

- Push, background sync, GPS stamping, in-app maps, barcode scanning, document evidence (PDF, Office), queued declarations or state changes, conflict-resolution screens, creating material requests, pickup confirmation (no backend step), checklists and test results, network credentials, SPK comments, supervisor and dispatch features, surveyor, collector and warehouse roles, iOS, CI.

## Further Notes

- **Ticket order:** 01 → 02 → 03 → 04 → 05 → 06 → 07 → 08 → 09 → 10. Tickets 02-08 run one at a time because they share the app router, ARB and the SPK detail screen.
- **Cross-lane:**
  - 01 needs flutter-customer 01.
  - 02 needs flutter-customer 02 and mobile-api 01.
  - 03 needs mobile-api 02, 04 and 05.
  - 04 needs mobile-api 05 and 06. 05 needs mobile-api 04. 06 needs mobile-api 05.
  - 08 needs flutter-customer 06 and mobile-api 06.
  - 09 needs mobile-api 08. 10 needs mobile-api 09 APPROVED and a public HTTPS API host.
- **Shared lock file:** the workspace has one `../inbils-mobile/pubspec.lock`. A ticket that adds or upgrades a dependency (here 03 for `url_launcher`, 06 for the image and drift packages) must not run at the same time as a dependency-changing ticket of the customer lane. The orchestrator serializes them.
- **Core:** this lane does not edit `../inbils-mobile/packages/inbils_core`. If it needs a core change, it reports `needs-change` to the orchestrator. The only shared-file edit is adding `technician` to the workspace members in `../inbils-mobile/pubspec.yaml` (ticket 01, after flutter-customer 01 is done).
- **Owner prerequisites before 10:** the domain for the app id (customer Q10), the Play Console account (customer Q16) with an internal testing track and the testers' Google accounts, the upload keystore for this app id (separate from the customer app's), icons, and the staging and production API URLs.
