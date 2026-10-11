# SECURITY REVIEW: two-factor authentication release gate

Status: needs-owner-approval
Role: security-reviewer
Blocked by: 04
Card: none (new lane; no Hermes card)

## Scope

Read-only `security-reviewer` pass required by repo rule 10 (touches auth, permissions, user data). Review tickets 01-04 against `../spec.md`:

- Secret and recovery-code storage, encryption, redaction (activity log, exports, props, logs).
- Brute-force/replay: throttling keys, replay guard, timing-safe comparisons.
- Login state machine: no authenticated session between password and challenge; session fixation; remember-me behaviour; password-reset path.
- Admin reset authorisation (self, membership, permission) and audit.
- Dependency advisories: `composer audit`, `npm audit` for every severity (package, CVE, severity, affected vs fixed version, update command).

## Acceptance criteria

- [ ] Verdict APPROVED or BLOCKED with file:line findings and the fix for each.
- [ ] Advisory table present (or "none" with the command output).
- [ ] Release notes: `php artisan migrate`, `php artisan db:seed --class=RolePermissionSeeder --force`; rollback = migration down (secrets are discarded).
