# QA/REVIEW: gates, review, release

Status: ready-for-agent
Role: qa/reviewer
Blocked by: 01–10
Spec: `../spec.md`

## Scope

Pint, Larastan on changed files, tsc, ESLint, all selfchecks incl. copy selfcheck over the whole of `resources/js`, full PHPUnit on `inbils_test`, `npm run build` + full canonical chromium E2E, security review (new search endpoints/props: scope, escaping, limits), Docker refresh + `/up` `/ready`, CHANGELOG, commit + push.

## Acceptance criteria

- [ ] All gates green with evidence; review SIGNED; pushed.

## Comments
