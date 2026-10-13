# BACKEND: Permissions page gated by roles.manage

Status: done
Role: backend
Blocked by: none
Spec: `../spec.md`

## Scope

- `PermissionController` (and route middleware if any) authorizes `roles.manage` instead of `users.manage`.
- Feature test: `roles.manage` → 200, `users.manage` only → 403, guest → login.

## Acceptance criteria

- [x] Gate changed and tested; sidebar mirror done in ticket 01.

## Comments

- 2026-10-13 Backend: PermissionController gate -> roles.manage; PermissionServerTableTest (roles.manage 200, users.manage only 403, none 403, guest -> login) green. Sidebar mirror stays ticket 01.
