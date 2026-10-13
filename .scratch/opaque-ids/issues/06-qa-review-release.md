# QA/REVIEW: gates, security review, release (both repos)

Status: ready-for-agent
Role: qa/reviewer
Blocked by: 01–05
Spec: `../spec.md`

## Scope

Full PHPUnit, build + full chromium E2E, mobile tests + journeys, security review (route matrix, uniform 404, enumeration limiter, Midtrans order id), CHANGELOG (bookmarks break once; deploy: `migrate --force`, apps must be updated together), Docker refresh + 8080 migrate, commit + push both repos.

## Acceptance criteria

- [ ] All gates green with evidence; security review SIGNED; both repos pushed.

## Comments
