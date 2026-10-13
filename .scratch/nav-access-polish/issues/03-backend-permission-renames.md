# BACKEND: rename user.two_factor.reset and document.export

Status: done
Role: backend
Blocked by: none
Spec: `../spec.md`

## Scope

- Reversible migration renaming the rows in `permissions` (`user.two_factor.reset` → `users.two_factor.reset`, `document.export` → `system.document.export`), then `app(PermissionRegistrar::class)->forgetCachedPermissions()`; down reverses. No-op when a row is missing; refuse (abort) if the target name already exists.
- Update `RolePermissionSeeder`, `UserPolicy`, `DocumentController`, `tests/Traits/EnrollsTwoFactor.php`, affected tests and `tests/e2e/two-factor.spec.ts`; CHANGELOG deploy note (`migrate --force`, re-seed).

## Acceptance criteria

- [x] Migration up/down on `inbils_test`; grants survive the rename (test).
- [x] No reference to the old names remains outside migrations/history.

## Comments

- 2026-10-13 Backend: migration 2026_10_17_100000_rename_two_factor_reset_and_document_export_permissions (up/down, abort on existing target, skip missing, forget cache); tests/Feature/PermissionRenameMigrationTest runs up/down on inbils_test (RefreshDatabase) green; old names gone outside migration/test/CHANGELOG. 440 focused tests green (2FA, seeder, Reporting, permissions).
