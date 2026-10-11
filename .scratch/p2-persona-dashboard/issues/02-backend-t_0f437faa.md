# [t_f9783de7] P2 BACKEND: persona dashboard read models dan exception counts

Status: done
Role: backend
Card: `t_0f437faa` (ledger `omp_status: needs_recovery`; Hermes card is a reference only, never mutated)
Blocked by: 01

## Scope

Defined by `../spec.md` for the `backend` step of this lane. Acceptance is the approved spec (recommended options).

## Comments

- 2026-10-10 `DashboardBackend2`: implemented, tests green, awaiting Main verification. Files: `app/Queries/Admin/DashboardQuery.php`, `app/Queries/Admin/DashboardAdminStatsQuery.php`, `app/Http/Controllers/Admin/DashboardController.php`, `app/Http/Resources/NotificationResource.php`, tests `DashboardTest`, `Admin/DashboardPersonaTest`, `Admin/DashboardExceptionCountsTest`, `Admin/NotificationCenterTest` (extended), `Traits/BuildsDashboardFixtures`. Props contract and decisions: `../spec.md` "Approved decisions" and the backend report. Blocks UI `t_f1c27ea9`: `Pages/Admin/Dashboard/Index.tsx` still reads the removed top-level props.
