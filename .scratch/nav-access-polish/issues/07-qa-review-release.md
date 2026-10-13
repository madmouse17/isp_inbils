# QA/REVIEW: gates, security review, release

Status: done
Role: qa/reviewer
Blocked by: 01, 02, 03, 04, 05, 06
Spec: `../spec.md`

## Scope

- Run Pint, Larastan, tsc, ESLint, build, focused PHPUnit, canonical E2E; security review of the permission/authorization changes; Docker image refresh; commit and push.

## Acceptance criteria

- [x] All gates green with pasted output; security review SIGNED.

## Comments

- 2026-10-12 (orchestrator), gates:
  - Static checks: `vendor/bin/pint --dirty` passes. `tsc --noEmit` passes. ESLint on the touched `resources/js` files reports 0 errors and 1 old warning (`Documents/Index.tsx` `useMemo` deps). The node selfchecks pass 24/0.
  - Larastan on the changed PHP files reports 2 `classConstant.unused` (`SORTABLE`) in `DocumentController` and `PermissionController`. Both constants were already in HEAD and this change does not touch them.
  - Full PHPUnit on `inbils_test` first gave 2834 passed and 4 failed, all expected from this lane:
    - `AdminPermissionRoleTest` used a `users.manage` actor and now uses `roles.manage`.
    - The two Approval Inbox query budgets each grew by 1, because the lazy `canTechnicianReport` prop loads the manager's employee profile once per page.
    - After the fix, those 3 files pass 36/36.
  - E2E: `npm run build` followed by a full chromium run gave 190 passed, 4 skipped and 3 failed:
    - `two-factor.spec.ts` still waited for the heading "Dashboard"; it now waits for "Dasbor".
    - `private-media.spec.ts` hit EACCES on `storage/app/private/companies/*`. Those directories were root-owned because `sail exec -T` runs PHPUnit as root and the tests leave files behind. Ownership was set back to 1000:1000 inside the container.
    - Rerun of both specs: 7/7 passed. RADIUS lab `sh scripts/e2e-radius-lab.sh`: exit 0, 4 passed.
  - Security review `NavAccessSecReview` returned SIGNED with two advisories, accepted as documented:
    - Running the seeder before migrate makes the migration refuse to run, so the CHANGELOG orders migrate before seed.
    - A role with `report.manage` but without `report.technician` gets the flag set to true but the route still returns 403. The sidebar still hides the item, so nothing is widened.
  - Deploy: the images were rebuilt and `app`, `queue` and `scheduler` recreated, and all three are healthy. `/up` returned 200 and `/ready` returned 200. `migrate --force` applied the rename: `users.two_factor.reset` and `system.document.export` are present. The `RolePermissionSeeder` was not re-run on the deploy DB, because it syncs the seeded roles and would overwrite manual edits.
