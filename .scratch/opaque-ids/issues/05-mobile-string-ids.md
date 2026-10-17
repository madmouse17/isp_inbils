# MOBILE: string ids in core, technician and customer apps

Status: done
Role: mobile
Blocked by: 02 (API contract 1.1)
Spec: `../spec.md`

## Scope

Repo `/home/madmouse/Documents/web/inbils-mobile`: Freezed/JSON models `int id` → `String id` (and every reference id), repositories, route params, fixtures (`integration_test/fixtures.dart`, `api_fixtures.dart`), widget/unit tests; idempotency keys unaffected.

## Acceptance criteria

- [x] `flutter analyze` + `flutter test` green in core, technician, customer.
- [ ] Integration journeys pass on AVD `inbils_pixel7` against the e2e API: **not run** in this pass (no device runs were allowed); ticket 06 runs them once the web side of 02 to 04 has landed.

## Comments

### 2026-10-17, OpaqueIdsMobile - landed in `inbils-mobile` (uncommitted, on top of `fba0cc0`)

Contract used: spec D2/D3 (every `id` and `*_id` an API body carries is the record's 26-character `public_id`; paths `customer/invoices/{ULID}`, `technician/work-orders/{ULID}/evidence/{ULID}`, `devices/{ULID}`, `media/{ULID}`; requests send `product_id`, `warehouse_location_id`, `category_id`, `subscription_id` as public ids). `docs/api/mobile-v1.openapi.json` was still `1.0.0` (integer ids) when this first pass ended; the re-check against the exported `1.1.0` is the last comment below. Parsing is strict (`String`, no int fallback), so one integer id in a page fails that page.

**What changed**

- `packages/inbils_core`: `id` of `Me`, `CompanyRef`, `CustomerRef`, `Device`, `Announcement`, `PromoBanner`, `WorkOrderVisit`, `NotificationSubject` is `String`; `AuthRepository.revokeDevice(String)`; `StoredSession.userId` is `String` (a session stored by an older build with an integer `user_id` reads as no session, so that phone signs in once); `PushMessage` accepts a `subject_id` only when it is 26 letters or digits (it ends up in a route path; anything else names no subject and the tap opens the inbox); `ulid(n)` test helper in `inbils_core/testing.dart` (`ulid(21)` = `01jc0000000000000000000021`).
- `customer`: every model, repository, `CustomerRoutes.*` and `newTicketFor` takes strings; `int.parse`/`int.tryParse` and the redirect guards in `router.dart` are gone (an id is a plain path parameter; `/tickets/new` still sits before `/tickets/:id`); the banner image regex of `AnnouncementRepository` is `/api/v1/(media/[0-9A-Za-z]{26})/?$` (it was `\d+`: the one numeric-id assumption the analyzer could not see); dropdown value types `String?`.
- `technician`: same for models, repositories, routes, `CacheKeys`, `QueueSyncer`, `ReadCache`, uploader, capture note, uploads banner, ticket and material screens; `RequestWorkOrder.numericId` and its string-to-text hack are gone (the id is a string now and always opens the SPK). Drift schema 3: `user_id` and `work_order_id` of `evidence_queue` and `user_id` of `cache_entries` are text; the upgrade from 1 or 2 drops and recreates both tables (their integer ids name nothing the API can resolve; `EvidenceUploader.recover` removes the photo files no row owns). `drift_schemas/drift_schema_v3.json` and `test/generated_migrations/schema_v3.dart` are generated; `test/migration_test.dart` covers 3 and the 1 to 3 and 2 to 3 upgrades. The queue's own row id stays an integer.
- Not shown to people any more: "Pengembalian #12" (the id of a return) is now "Pengembalian #n" with n the place in the SPK's list (`returnLine(number)`), and a return with no SPK shows "Pengembalian material" instead of `#id`.
- Ordering: the two places that break a tie by id (`nextVisit`, `nextOpenVisit`) keep working, because a ULID sorts by creation time.
- Generated code regenerated with `build_runner` in the three packages, `flutter gen-l10n` in the technician. README "Conventions" documents the string id rule.
- Tests and fixtures: every JSON fixture, route and request assertion of the three packages (`test/`, `integration_test/fixtures.dart`, `api_fixtures.dart`, `support.dart`, journeys, contract parsers) uses public ids; `composite_id` of the my-work fixtures carries them too. New: `push_message_test` pins the shape check (integer id, short word, `../../me/devices/12` and a 27-character id give no subject), `session_store_test` an integer or empty `user_id` as a damaged session.

**Evidence** (Flutter 3.47.7, host)

- `dart format --set-exit-if-changed .` at the workspace root: 391 files, 0 changed.
- `flutter analyze` in `packages/inbils_core`, `customer`, `technician`: `No issues found!` each.
- `flutter test`: core `+472: All tests passed!` (same count as before), customer `+475: All tests passed!`, technician `+292: All tests passed!` (291 before, one more migration test).
- `integration_test/fixture_contract_test.dart` through a temporary one-line wrapper in `test/` (deleted): customer `+36: All tests passed!`, technician `+41: All tests passed!`.
- `flutter build apk --debug --flavor dev --dart-define-from-file=env/dev.json` in `customer` and in `technician`: `✓ Built build/app/outputs/flutter-apk/app-dev-debug.apk` each (`google-services.json` copied from `~/.config/inbils/`, ignored by git).
- Every `integration_test/*_test.dart` except `docs_screenshots_test` and `evidence_normalizer_test` through temporary wrappers on the host, run on the working tree (customer `+89 -4`, technician `+88 -21`) and, minus the contract file, on a clean copy of `fba0cc0` (customer `+53 -4`, technician `+47 -21`): exactly the same tests fail in both (customer journeys 1, 4, 4b and 8; the 21 technician ones are the offline, queue, material and attachment journeys and their accessibility and text-scale walks), so no failure is new; they need a device. Every other test in those files passes on the host with string ids.

**Not verified / for ticket 06**

- Device journeys on `inbils_pixel7` against the e2e API and a real FCM push on a phone (the OpenAPI 1.1.0 check is done, see the last comment).
- A phone that holds a v2 technician queue loses its unsent photos at the first start of this build; the apps are not published, and those photos could not be uploaded under the new API anyway.

### 2026-10-17, OpaqueIdsMobile - re-checked against OpenAPI 1.1.0

`docs/api/mobile-v1.openapi.json` is `1.1.0` now. Compared structurally with the `1.0.0` of the web repo's HEAD (paths, path parameters, every schema property and every inline request and response schema):

- No path, schema or property was added or removed. The only differences are 99 type changes (89 `integer` to `string`, 10 `integer|null` to `string|null`): every `id`, every `*_id` reference (`PortalInvoiceResource.subscription_id`, the request fields `product_id`, `warehouse_location_id`, `category_id`, `subscription_id`, `lines[].id` of the declaration), `MyWorkItemResource.assigned_to`, and the path parameters. No id-like property or path parameter is an integer any more, so no model had a shape to change.
- The three fields I flagged: `TechnicianInventoryRequestResource.work_order.id` is `string` (the app reads it as `RequestWorkOrder.id` and opens the SPK with it); `MyWorkItemResource.assigned_to` is `string|null` and the apps do not read it; the FCM `subject_id` is `(string) NotificationResource.subject.id` in `FcmChannel`, and `NotificationResource` now emits `subject` only for a stored ULID (lowercased, `Str::isUlid`) and `null` otherwise, so a push carries a 26-character id or an empty string, which `PushMessage` turns into the inbox tap.
- Parsers: all 166 JSON keys the three packages read (`json['key'] as T` in the `*.g.dart` files) are in the document with a compatible type; none is missing, none is read as a number where the document says string, and no `toInt()` on an id remains.
- Fixtures: a temporary Dart test walked every fixture of `customer/integration_test/fixtures.dart` (105 key and type pairs) and `technician/integration_test/api_fixtures.dart` (131). Every key is in the document and every `id`, `*_id` and `assigned_to` value is a string or null. The only type disagreements are the validation `errors` maps (`title`, `code`, `file` hold lists of messages), which are the error envelope, not resources.
- Aligned with the new text of the document ("26-character lowercase ULID such as `01m5441c2q1sqrw67nr4dwyakz` ... never parse or order it as a number ... a notification's `id` is a UUID"): the test ids are lowercase now (`ulid(n)` = `01jc` plus 22 digits, 704 literals in 58 files; the app accepts either case), and the two tie-breaks that compare ids (`nextVisit`, `nextOpenVisit`) compare them as text, which is not a numeric order. The document does not promise a time order, so say so if those ties should break by something else (for instance `code`). The banner image URL (`api.v1.media.show`, bound as `{media:public_id}` with `whereUlid`) ends in the public id, which the banner image regex accepts.
- Evidence after the alignment: `dart format --set-exit-if-changed .` 391 files, 0 changed; `flutter analyze` in core, customer and technician `No issues found!`; `flutter test` core `+472`, customer `+475`, technician `+292`, all passed; `fixture_contract_test` through temporary wrappers (deleted) customer `+36`, technician `+41`, all passed; both debug APKs built (`✓ Built build/app/outputs/flutter-apk/app-dev-debug.apk`).
