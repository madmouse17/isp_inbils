# QA/REVIEW: gates, security review, release

Status: done
Role: qa/reviewer
Blocked by: 01, 02, 03
Spec: `../spec.md`

## Scope

- Static checks: Pint, Larastan on changed files, tsc, ESLint, selfchecks.
- Tests: full PHPUnit on `inbils_test`, then the full canonical chromium E2E.
- Migration rehearsal up and down.
- Security review: mass assignment, validation of template input, tenant scoping of the taken-check, and authorisation.
- Docker image refresh, then commit and push.

## Acceptance criteria

- [x] All gates green with evidence; security review SIGNED.

## Comments

- 2026-10-13 (orchestrator):
  - Pint clean; `tsc --noEmit` exit 0; ESLint on changed `resources/js` files 0 errors; selfchecks `numberTemplate` ok, navigation 23/23, permissionGroups pass.
  - Larastan on changed PHP files: every hit is on a line already in HEAD (`nullsafe.neverNull`, `property.notFound`, `SORTABLE`), none on changed lines.
  - Full PHPUnit on `inbils_test`: 2934 passed, 2 failed in `DemoDataSeederTest` because the demo seeder passes fixed `DEMO-Cnnn` codes through the now-locked onboarding action. Fix: the seeder calls `ensureAll` and sets `allow_manual` for `customer` on the demo company (spec R6). Rerun 3/3. `tests/Feature/Inventory` 90/90 after the `min_stock` fix.
  - E2E: see ticket 03. Migration applied up on the 8080 stack (`add_template_and_reset_to_number_sequences_table DONE`); `NumberSequenceMigrationTest` covers down.
  - Security review `SeqSecReview`: SIGNED. Its three low notes are fixed: `next_number` capped at `MAX_NUMBER` after generate; rendered code longer than the column throws a clear RuntimeException; the client preview caps `{SEQ:n}` width at 10.
  - Deploy: images rebuilt, `app`/`queue`/`scheduler` healthy, `/up` 200, `/ready` 200.
