# BACKEND: deadlines from tier or category, response target, metrics

Status: done
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

- [x] Focused PHPUnit (Ticketing, Reporting SLA, Business, Technician) green; Pint; migration up/down on `inbils_test`.

## Comments

2026-10-14, SlaCore. Contract choices that differ from the ticket text: `deadline_source` is `{type: 'tier'|'category', name}` (shared contract), emitted by `TicketResource` on `admin.tickets.show` only (a list row would load the tier per row); `ticket_categories.default_response_hours` is `NOT NULL DEFAULT (CEIL(default_sla_hours / 2))` (MySQL expression default), which backfills existing rows and still lets `DB::table` seeders and the 20+ tests that omit the column insert.

Shipped:
- `Modules/Ticketing/database/migrations/2026_10_14_110000_add_response_deadline_to_tickets_table.php`, `..._110001_add_default_response_hours_to_ticket_categories_table.php`.
- `TicketService::computeDeadlines(Ticket): array{response: ?CarbonImmutable, resolution: CarbonImmutable}` (tier of the subscription's package when active, else category; urgent halves both, rounded up) and `deadlineSource(Ticket)`; `create()` stores both deadlines; `computeSlaDeadline` removed (its test moved into `TicketDeadlinesTest`).
- `Ticket::$is_response_breached` (answered late, or unanswered past the target); `response_deadline` and `default_response_hours` fillable and cast.
- SLA report: `SlaSources::respondedOnTime|responseBreached|responseJudged|withoutResponseTarget`; payload keys `response_on_time`, `response_breached`, `response_without_target`, `response_rate` (null when nothing is judged); 4 `MetricRegistry` entries (SLA report now 11 metrics, registry 50); 4 drill lists (`response_on_time`, `response_breached`, `response_without_target`, `response_rate` as a Share); ticket drill rows and their CSV/PDF export gain `Response deadline` and `Response` (On time / Breached / No target / No response).
- Old tickets keep `response_deadline = null` and count as "without target" (S6); resolution figures are untouched.

Evidence (all under `flock /tmp/inbils-e2e.lock … flock /tmp/inbils-phpunit.lock`, DB `inbils_test`):
- Red first: `TicketDeadlinesTest` failed with `Call to undefined method TicketService::computeDeadlines()` (3 failed), then the tier tests failed on the category hours (2 failed) before the tier path existed; a weak urgent-tier fixture that passed by coincidence was replaced.
- Mutation check: making `respondedOnTime` exclusive (`<`) failed the response test in `ReportFormulaV1Test` and `ReportDrillTest`; reverted.
- `tests/Feature/Ticketing tests/Feature/Reporting` → 401 passed (8232 assertions), including `TicketDeadlinesTest` (tier, category fallback, inactive tier, urgent, company scoping, `create()` stores both deadlines and loads the tier once, ticket page props, response state), `ResponseTargetMigrationTest` (down/up of both migrations, backfill 7→4, 4→2, 1→1, new row 9→5), `ReportFormulaV1Test` (response figures, every old SLA expectation now pins `response_without_target`), `ReportDrillTest` (response lists tie out), `MetricRegistryTest` (11 / 50), `ReportDictionaryTest`.
- Broader regression batch (`P0CriticalPathTest`, `TenantBoundaryTest`, `CustomerPageTest`, ticket media/attachment tests, `DemoDataSeederTest`, `NumberSequenceTest`, `ApiExposureTest`, `tests/Feature/{Api,Admin,TenantIsolation,WhatsApp,SPK}`, `tests/Unit`) → 1195 passed (13910 assertions).
- Final re-run `TicketDeadlinesTest` + `ResponseTargetMigrationTest` → 20 passed (46 assertions).
- `vendor/bin/pint --format agent <touched files>` → `{"result":"passed"}`.
- `phpstan analyse` (level 5, touched app files) → no new error class: remaining 10 are the existing `DrillSource::$onTime` closure-type pattern (4, one is the new `response_rate` line copying it) and the existing `by_category` row properties in `SlaComplianceQuery` (6).
- `DB::pretend` of both migrations on `inbils_test`: `alter table tickets add response_deadline timestamp null after sla_deadline`, `alter table tickets add index tickets_company_id_response_deadline_index(company_id, response_deadline)`, `alter table ticket_categories add default_response_hours int unsigned not null default (CEIL(default_sla_hours / 2)) after default_sla_hours`.
- `tests/e2e/reporting-dictionary.spec.ts`: SLA `metrics: 11`, link `SLA Compliance (11)` (not run).
