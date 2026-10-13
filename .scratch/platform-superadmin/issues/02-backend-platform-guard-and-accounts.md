# BACKEND: platform guard, accounts, 2FA mandatory

Status: ready-for-agent
Role: backend
Blocked by: 01
Spec: `../spec.md`

## Scope

`platform_admins` migration/model/factory, auth guard + provider, `/platform/login` (+ throttles), mandatory 2FA enrolment (reuse `TwoFactorService` generically), `password.confirm` for the platform guard, idle timeout middleware, `inbils:platform:admin-create` command, platform audit (`log_name=platform`). Tests per spec (guard isolation, 2FA, throttles).

## Acceptance criteria

- [ ] Focused PHPUnit green; Pint; `docs/SECURITY.md` platform section.

## Comments
