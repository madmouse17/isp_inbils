# QA/REVIEW: gates, review, release

Status: done
Role: qa/reviewer
Blocked by: 01–03 (04 folded into mobile-redesign 04)
Spec: `../spec.md`

## Scope

Pint, tsc, ESLint, full PHPUnit, build + full chromium E2E, review (category CRUD permissions, tenant scope, migration backfill), CHANGELOG (deploy: `migrate --force`), Docker refresh + 8080 migrate, commit + push.

## Acceptance criteria

- [x] All gates green with evidence; review SIGNED; both repos pushed.

## Comments

2026-10-14, orchestrator (QA gate, together with ui-consistency 02/03 in the same tree):
- Full PHPUnit: 3171 passed, 6 failed → fixed: `AdminMenuAuthorizationTest` sidebar case (page missing until ticket 03 landed; 75 passed after), `SerializedAssetConcurrencySmokeTest` cleanup now deletes `service_package_categories` before the company (7 passed). Final focused run Service + Api + Reporting + AdminMenu + smoke: 673 passed, 1 failed (OpenAPI drift from an orchestrator edit, reverted; `OpenApiContractTest` 2 passed).
- Full chromium E2E after agent E2EFix: 244 passed, 4 skipped, 0 failed (two product regressions from ui-consistency 02 fixed: Modal focus return, isolir CTA height).
- Security review (WaveSecReview): SIGNED; info note applied (LIKE escaping in the category search).
- Docs: user guide §4.2 + new "Kategori Paket", report filters, menu/permission tables, `pelanggan.md`; CHANGELOG block with `migrate --force`.
