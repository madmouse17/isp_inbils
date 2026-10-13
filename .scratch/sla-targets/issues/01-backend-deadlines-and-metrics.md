# BACKEND: deadlines from tier or category, response target, metrics

Status: ready-for-agent
Role: backend
Blocked by: none
Spec: `../spec.md`

## Scope (owned files)

- Migration: `tickets.response_deadline` (nullable datetime, index `(company_id, response_deadline)`); `ticket_categories.default_response_hours` (int, backfilled = ceil(default_sla_hours / 2)).
- `Modules/Ticketing/app/Services/TicketService.php`: replace `computeSlaDeadline` with `computeDeadlines` (spec S1, S2, D2, D3); set both on create; keep the existing `sla_deadline` column for resolution.
- `Modules/Ticketing/app/Models/{Ticket,TicketCategory}.php` casts/fillable; ticket resource fields `response_deadline`, `deadline_source` (`tier:<name>` | `category:<name>`), `is_response_breached` accessor.
- `Modules/Reporting/app/Queries/Sources/SlaSources.php` (+ `SlaComplianceQuery`, `MetricRegistry` entry for the response metric, CSV/PDF export column).
- Tests (TDD at `computeDeadlines`): spec testing decisions items 1–2.

## Acceptance criteria

- [ ] Focused PHPUnit (Ticketing, Reporting SLA, Business, Technician) green; Pint; migration up/down on `inbils_test`.

## Comments
