# [t_a31bc2c0] UI P2: persona dashboard dan exception queues

Status: done
Role: ui
Card: `t_f1c27ea9` (ledger `omp_status: needs_recovery`; Hermes card is a reference only, never mutated)
Blocked by: 02

## Scope

Defined by `../spec.md` for the `ui` step of this lane. Acceptance is the approved spec (recommended options).


- 2026-10-11 `DashboardFrontend`: implemented, e2e spec written, tests green, ready for tester. Files: `resources/js/Pages/Admin/Dashboard/Index.tsx`, `resources/js/Pages/Admin/Dashboard/components/*`, `resources/js/types/dashboard.ts`, `tests/e2e/dashboard-persona.spec.ts`. Components extracted: `DashboardRegion`, `SectionCard`, `NotificationsCard`, `AdminOverview`, `SlaTicketList`, `ToneBadge`. Dark mode, layout scaling, contrast, and focus states checked manually via throwaway Vite harness. `StatCard` padding deficiency noted as pre-existing UI lib bug; badge variants left unchanged to avoid duplicating logic.
