# QA/SECURITY: gates, security review, release

Status: done
Role: qa/security-reviewer
Blocked by: 01–07
Spec: `../spec.md`

## Scope

Full PHPUnit, build + full E2E, `security-reviewer` on the whole lane (guard isolation, impersonation, suspension, export, role ownership), ADR + AGENTS.md + SECURITY.md committed, CHANGELOG (deploy: `migrate --force`, `inbils:platform:admin-create`), Docker refresh, commit + push.

## Acceptance criteria

- [x] All gates green with evidence; security review SIGNED; pushed.

## Comments

2026-10-17, orchestrator (combined gate for announcements-promo, field-trip-status, platform-superadmin, push-notifications 01, mobile-redesign 02/09): full PHPUnit 3781 passed (2 fixture tests updated for `device_tokens`); full chromium E2E 262 passed, 4 skipped, 0 failed (two consecutive runs; one product defect fixed: invalid `pattern` regex on the tenant code inputs); security reviews SIGNED (announcements, platform incl. impersonation re-pass fixes); `composer audit` clean after `symfony/yaml` update (Laravel 12.70.0); docs screenshots 87/87; PDFs re-rendered; real FCM V1 dry run accepted.
