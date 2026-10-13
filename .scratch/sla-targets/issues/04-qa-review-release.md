# QA/REVIEW: gates, review, release

Status: done
Role: qa/reviewer
Blocked by: 01, 02, 03
Spec: `../spec.md`

## Scope

Pint, tsc, ESLint, full PHPUnit, build + full chromium E2E, security review (new permission, category write endpoints), docs (`docs/business/ticketing.md` SLA section, user guide §4.5/§3.x caveats), CHANGELOG with deploy lines (`migrate --force`, `RolePermissionSeeder`), Docker refresh, commit + push.

## Acceptance criteria

- [x] All gates green with evidence; review SIGNED; pushed.

## Comments

2026-10-14, orchestrator (QA gate):
- Static: `npx tsc --noEmit` 0; ESLint on changed TS/TSX 0; `navigation.selfcheck` 24/24; Pint passed.
- Security review (SlaSecReview): one Low finding, fixed: SLA tier hours bounded 1–720 and response ≤ resolution in `Store/UpdateSLATierRequest` with Indonesian `messages()`/`attributes()`, `max={720}` on the tier forms; `SLATierComplianceTest::test_tier_hours_are_bounded_and_response_not_longer_than_resolution` red (1 failed) then green. Everything else SIGNED.
- Full PHPUnit (with the deactivated-company fix in the same tree): 3122 passed, 1 failed (`TicketValidationTest:255`: the factory's random tier hours now drive the deadline; tier pinned in the test), rerun of that file + `SLATierComplianceTest` 12 passed.
- `npm run build` + full chromium E2E: 206 passed, 2 failed (`sla-targets` sidebar link inside a collapsed group; `datatable-ab` SLA Tiers search label is now "Cari"), both specs fixed; rerun `datatable-ab` + `sla-targets` 13 passed, 1 flaky (`datatable-ab Organizations` per_page race), then `datatable-ab --repeat-each=2` 22 passed.
- Docs: user guide §3.4 (SLA dan tenggat, Kategori Tiket), §4.5, §7.6, menu and permission tables; CHANGELOG block. Screenshots `sla-tiers.png`, `tickets-show.png` moved to `ui-consistency` 10.
