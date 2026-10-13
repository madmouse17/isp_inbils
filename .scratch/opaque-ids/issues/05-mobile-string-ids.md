# MOBILE: string ids in core, technician and customer apps

Status: ready-for-agent
Role: mobile
Blocked by: 02 (API contract 1.1)
Spec: `../spec.md`

## Scope

Repo `/home/madmouse/Documents/web/inbils-mobile`: Freezed/JSON models `int id` → `String id` (and every reference id), repositories, route params, fixtures (`integration_test/fixtures.dart`, `api_fixtures.dart`), widget/unit tests; idempotency keys unaffected.

## Acceptance criteria

- [ ] `flutter analyze` + `flutter test` green in core, technician, customer; integration journeys pass on AVD `inbils_pixel7` against the e2e API.

## Comments
