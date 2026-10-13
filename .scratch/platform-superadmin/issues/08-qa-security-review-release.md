# QA/SECURITY: gates, security review, release

Status: ready-for-agent
Role: qa/security-reviewer
Blocked by: 01–07
Spec: `../spec.md`

## Scope

Full PHPUnit, build + full E2E, `security-reviewer` on the whole lane (guard isolation, impersonation, suspension, export, role ownership), ADR + AGENTS.md + SECURITY.md committed, CHANGELOG (deploy: `migrate --force`, `inbils:platform:admin-create`), Docker refresh, commit + push.

## Acceptance criteria

- [ ] All gates green with evidence; security review SIGNED; pushed.

## Comments
