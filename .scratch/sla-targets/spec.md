# SLA targets drive ticket deadlines; measurement stays automatic

Status: ready-for-agent
Ledger: `docs/plans/INBILS_OMP_BACKLOG.md` → "ISP roadmap lanes (2026-10-12)" row `sla-targets`
Source: owner question 2026-10-14 (grill-me): "kenapa pada sla bisa create manual? bukannya itu dinilai langsung atas kinerjanya?". Owner approved round 1 (S1–S6, all recommended) and asked for specs/tickets and implementation ("ya buatkan tickets atau specs … langsung gunakan skill implement"). Round-2 items (D1–D4) decided by the assistant on the owner's behalf.

## Facts (2026-10-14, agent SlaFacts)

- `sla_tiers` (`Modules/Service/Models/SLATier.php`): `name`, `uptime_pct`, `response_time_hours`, `resolution_time_hours`, `credit_pct`, `is_active`; created on `Service/SLATiers/*` by `service.create|manage` (admin, manager). Only `service_packages.sla_tier_id` (required) references a tier. Nothing reads its hours, uptime or credit.
- Ticket deadline: `TicketService.php:53` → `computeSlaDeadline` (`:68-77`) = now + `ticket_categories.default_sla_hours`, halved (ceil) for priority `urgent`. Categories are seeded (`CompanySeeder.php:97-101`, 4/8/8/12/12 h); no admin screen.
- Measurement is automatic: `first_response_at` on start (`TicketService.php:168`), `resolved_at` on resolve (`:284`); `is_sla_breached` accessor; `CheckTicketSlaBreachesJob` (every 15 min) only notifies; `SlaSources::onTime/breached` feed `SlaComplianceQuery`, `BusinessMetricsQuery`, `TechnicianPerformanceQuery`. No manual input of results anywhere.
- Settings `ticket_default_sla_hours`, `ticket_sla_mode` are written by the setup wizard and read by nothing.

## Decisions

- **S1 Deadline source.** A ticket linked to a subscription takes the resolution hours of the subscription's package tier (`subscription.service_package.sla_tier.resolution_time_hours`). A ticket without a subscription (or whose tier is inactive) takes its category's `default_sla_hours`. The urgent rule applies after either source (D2).
- **S2 Response target.** New column `tickets.response_deadline` (nullable datetime, indexed with `company_id`), set at creation from the tier's `response_time_hours` (category fallback: D3). Response happens at Mulai (`first_response_at`, existing). `SlaSources` gains `respondedOnTime`/`responseBreached`; the SLA report shows "Respons tepat waktu" next to "Penyelesaian tepat waktu"; `MetricRegistry` documents both.
- **S3 Tier page.** Creating and editing tiers stays manual (they are contract targets), for `service.create|update|manage` as today. The page says "Target layanan per paket. Hasil diukur otomatis dari tiket." and the index gets read-only columns "Respons tepat waktu (30 hari)" and "Penyelesaian tepat waktu (30 hari)" computed from tickets of subscriptions on packages of that tier (one grouped query, company-scoped).
- **S4 Remove `uptime_pct` and `credit_pct`** from forms, requests, resource, exports, seeders and the table (reversible migration: `down()` re-adds the columns nullable).
- **S5 Remove the dead settings** `ticket_default_sla_hours` and `ticket_sla_mode` from the setup wizard defaults and any settings list; a migration removes the keys from existing `companies.settings` json. Deadlines use calendar hours.
- **S6 Existing tickets keep their deadlines.** No backfill: `response_deadline` stays null on old tickets and they are excluded from the response metric (counted as "tanpa target respons").
- **D1 Ticket categories get a small admin screen** (list + edit name, `default_sla_hours`, `default_response_hours`, active) under Pelanggan & Layanan → "Kategori Tiket", permission `ticket.category.manage` (new, admin + manager). They are the fallback targets and today cannot be changed without the database. New column `ticket_categories.default_response_hours` (default = half of `default_sla_hours`, rounded up, backfilled by the migration).
- **D2 Urgent rule kept:** priority `urgent` halves both deadlines (ceil), whichever source they came from. Documented on the tier and category pages.
- **D3 Response fallback:** without a tier, `response_deadline` = now + category `default_response_hours`.
- **D4 Visibility:** web ticket detail shows both deadlines with their source ("Target Gold" / "Target kategori Gangguan") and the breach state badges; the customer app is unchanged in this lane (no API change).

## Testing decisions

- Unit/feature (TDD seam: `TicketService::computeDeadlines(Ticket): array{response: ?Carbon, resolution: Carbon}`): tier source, category fallback, inactive tier, urgent halving for each, response fallback, company scoping.
- `SlaComplianceQuery`/`SlaSources`: response on time vs breached vs no target; existing resolution figures unchanged for old tickets.
- Tier index computed columns: fixtures across two tiers and two companies.
- Category screen: policy (manager allowed, staff 403), validation (1–720 h, response ≤ resolution).
- Migration up/down for the dropped columns and the settings cleanup.
- E2E: tier list shows the computed columns; a ticket for a Gold subscriber shows the Gold deadline; category edit changes the next ticket's deadline.

## Tickets

01-backend-deadlines-and-metrics · 02-backend-cleanup-and-categories · 03-ui-tier-category-ticket · 04-qa-review-release
