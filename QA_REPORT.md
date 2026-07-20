# QA Report — inbils

**Task reviewed:** t_61f7a63c — Review and close navigation evidence remediation
**Date:** 2026-07-15
**Reviewer:** QA-ISP
**OpenCode session:** `main` @ `2e0e5a2510c64c72d32dc8eb4a0cbe2d7cb6c554` (uncommitted shared workspace; remediation executed through authenticated Claude Code)

## Status: PASS

## Summary

Navigation-failure evidence now fails closed unless an exact `net::ERR_ABORTED` request correlates to a later lifecycle generation or teardown already active at failure. Independent policy, browser integration, environment regression, full Chromium, lifecycle cleanup, lint, typecheck, build, scoped formatting, Pint, and diff gates pass. `ERR_NO_BUFFER_SPACE` remains a hard app-origin failure.

## Requirement Compliance

| Acceptance criterion | Status | Notes |
|----------------------|--------|-------|
| Same-page app-origin abort fails closed | PASS | Policy regression passed; uncorrelated `net::ERR_ABORTED` remains `app-origin` with `shouldFail: true`. |
| Only proven navigation or explicit teardown may allow cancellation | PASS | Correlation requires lifecycle sequence after request start and no later than failure, or teardown already active at failure. |
| Real browser navigation cancellation is correlated | PASS | Browser integration creates a pending app-origin script request, performs real main-frame navigation, observes `requestfailed`, and verifies attached `framenavigated` correlation. |
| `ERR_NO_BUFFER_SPACE` remains hard failure | PASS | Focused regressions passed with navigation and teardown correlation present; classification remains `app-origin`, `shouldFail: true`. |
| Root cause fixed without broad allowlist | PASS | `Vite::prefetch(concurrency: 3)` remains enabled outside exact `e2e`; exact `e2e` suppresses fixture-only prefetch fan-out instead of suppressing request failures. |
| Focused policy suite passes | PASS | 10/10 Chromium policy tests passed in 1.4s. |
| Full application Chromium passes | PASS | 12/12 tests passed with one worker in 1.3m. |
| Owned lifecycle cleanup succeeds | PASS | `public/hot` SHA-256 remained `9b59efcb02c25aa18c8e3c79b3e0d35fce5c94f41eb3aa0892ae79cca46801b9`; Vite PID 30840 remained on 5173; port 8010 free; no stale backup. |
| Lint, typecheck, build, formatting, and diff gates | PASS | Lint, typecheck, build, scoped Prettier, scoped Pint, and `git diff --check` passed. Full frontend format check has one known unrelated dirty-workspace failure in `DataTable.tsx`. |
| Task-scoped CHANGELOG entry | PASS | Existing umbrella remediation heading includes `t_f519540a` and documents E2E harness/lifecycle work. |

## Findings

No blocking or task-scoped findings.

## Architecture

PASS. Production behavior changes only at infrastructure boundary: `app/Providers/AppServiceProvider.php:102-104` keeps Vite prefetch for every environment except exact `e2e`. Evidence policy remains centralized in shared Playwright fixture; regular app config excludes policy-only spec; no production controller, service, model, route, or frontend composition changed by this remediation.

## Database

PASS. No schema or application persistence change in remediation. Existing destructive E2E guard matrix passed all seven branches: valid `inbils_e2e` accepted; dev DB, test DB, `DB_URL`, wrong `APP_ENV`, custom config cache path, and cached config rejected before reset.

## Security Checklist

| Item | Status |
|------|--------|
| FormRequest for write routes | N/A — no application write route changed |
| Policy per resource method | N/A — no resource method changed |
| Explicit `$fillable` for new models | N/A — no model changed |
| No raw query without binding | PASS — no query added |
| No unsanitized `dangerouslySetInnerHTML` | PASS — no frontend production code added |
| File upload whitelist and regenerated filename | N/A — no upload flow |
| Activity log trait on historical models | N/A — no historical model changed |
| No secrets in code | PASS |
| Permission seed matches matrix | N/A — no permission change |
| Authorization denied-path coverage | N/A — no authorization change |
| Master/transaction models use company scope | N/A — no model/query change |
| Migration company FK/per-company uniqueness | N/A — no migration added |
| No implicit cross-company query | PASS — no application query added |
| Company identity not hardcoded | PASS |
| Destructive tooling validates effective target DB | PASS — guard matrix passed |
| App-origin browser failures fail closed | PASS — only exact correlated `ERR_ABORTED` is allowed; `ERR_NO_BUFFER_SPACE` fails |

## Testing

- `node node_modules/@playwright/test/cli.js test --config playwright.policy.config.ts`: PASS — 10 tests, including same-page fail-closed, stale generation rejection, navigation/Inertia correlation, teardown ordering, real browser navigation, and `ERR_NO_BUFFER_SPACE` hard-failure branches.
- `php artisan test --compact tests/Unit/VitePrefetchEnvironmentTest.php`: PASS — 2 tests, 2 assertions, 0.57s.
- `npm run test:e2e`: PASS — guard matrix passed; full Chromium 12/12, one worker, 1.3m.
- Coverage: no numeric report. Every new classification branch and exact environment branch has direct focused coverage.
- Missing tests: none for remediation acceptance.

## Build

- `npm run lint`: PASS.
- `npm run typecheck`: PASS.
- `npm run build`: PASS — 1,776 modules transformed; Vite build completed in 4.36s.
- Scoped E2E Prettier check: PASS — six files.
- Scoped Pint check: PASS — two files.
- `git diff --check`: PASS; only existing `.gitignore` CRLF conversion warning.
- `npm run format:check`: FAIL outside remediation scope — pre-existing `resources/js/Components/composite/DataTable.tsx` formatting issue. Remediation files pass scoped formatting.

## Reuse & Duplication

PASS. Remediation reuses existing Vite configuration, shared Playwright fixture, and existing E2E runner lifecycle. No new dependency or duplicate browser-failure policy was added.

## Fix List for OpenCode

None.

## Verdict

PASS — approve navigation evidence remediation and close review task. Fail-closed invariant, real navigation correlation, hard `ERR_NO_BUFFER_SPACE` behavior, application suite, and cleanup all verified independently.
