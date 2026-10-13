# QA/REVIEW: gates, security review, release (both repos)

Status: in-progress
Role: qa/reviewer
Blocked by: 01, 02, 03, 04, 05, 06, 07
Spec: `../spec.md`

## Scope

Web: Pint, Larastan on changed files, tsc, ESLint, selfchecks, full PHPUnit on `inbils_test`, `npm run build` + full canonical chromium E2E, security review (new API endpoints: uploads, password change, policies, tenant scope, throttles; middleware), Docker image refresh, CHANGELOG, commit + push. Mobile: `flutter analyze`, `flutter test`, integration journeys on the AVD, commit + push to `madmouse17/inbils-mobile`.

## Acceptance criteria

- [ ] All gates green with evidence; security review SIGNED; both repos pushed.

## Comments
