# QA/REVIEW: gates, security review, release

Status: done
Role: qa/reviewer
Blocked by: 01, 02, 03, 04, 05
Spec: `../spec.md`

## Scope

- Pint, Larastan on changed files, tsc, ESLint, selfchecks; full PHPUnit on `inbils_test`; `npm run build` + full canonical chromium E2E; `npm audit`; security review (input validation of dates, no raw SQL from input in bucket expressions); Docker image refresh; CHANGELOG; commit and push.

## Acceptance criteria

- [x] All gates green with evidence; security review SIGNED.

## Comments

- 2026-10-13 (orchestrator):
  - Static: Pint clean; `tsc --noEmit` exit 0; ESLint on changed `resources/js` files 0 errors; selfchecks `dateRange` 12/12 (4 time zones), `numberTemplate` ok, AuditLog/navigation/permissionGroups 29/29. Larastan: every finding is on a HEAD line (same count on the HEAD copies), none on changed lines.
  - Full PHPUnit on `inbils_test`: 2998 passed (32034 assertions), exit 0.
  - E2E: `npm run build` + full chromium run: 205 passed, 4 skipped, 6 failed. Causes and fixes:
    - `reporting-sales-kpi` ×4: the fixture pinned "first/last second of February" in UTC; reports now read company-local (Jakarta) days, so the UTC edges land in the wrong month. `E2eFixtureSeeder` edges moved to 17:00 UTC of the previous day (first/last second WIB). Behaviour change intended by Q4 and the timezone decision.
    - `reporting-stock-card`: asserted the old `From`/`To` inputs after reload; now asserts the picker's displayed range.
    - `technician-performance`: a real bug from this lane: the picker's `onChange` called `setData({date_from, date_to})`, which replaced the whole form and dropped `technician_id`, so Run stayed disabled. Fixed with a functional `setData`. The test is also marked `slow()` (three lazy charts + eight layout checks).
    - Reruns: sales-kpi + stock-card + technician + customer-sales-attribution 14/15 → after the Technician fix, technician + report-charts + date-range 17/17.
  - Security review `DateChartSecReview`: SIGNED (two non-blocking notes). `npm audit`: 0 vulnerabilities after adding react-day-picker, date-fns, recharts.
  - Deploy: images rebuilt, `app`/`queue`/`scheduler` healthy, `/up` 200, `/ready` 200. No new migration in this lane.
