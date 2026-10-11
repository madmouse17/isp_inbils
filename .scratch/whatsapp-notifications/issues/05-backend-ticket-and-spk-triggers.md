# BACKEND: ticket created/assigned/resolved and SPK schedule on WhatsApp

Status: done
Role: backend
Blocked by: 01
Card: none (new lane; no Hermes card)

## Scope

Ticket and SPK rows of spec Q3 (all event-driven, not batch).

- NEW `Modules/Ticketing/app/Notifications/TicketCustomerUpdateNotification(companyId, ticketId, stage)` with stage `created|assigned|resolved`; via `WhatsAppChannel` only, `afterCommit()`, queue `whatsapp`; parameters: company name, ticket code, title (truncated to 60 chars), stage-specific parameter (assigned: handler first name; resolved: resolution date). Dedupe `ticket_{stage}:{id}`. Recipient: the `Customer` of `tickets.customer_id`; nothing dispatched when null.
- Triggers in `TicketService` (`Modules/Ticketing/app/Services/TicketService.php`):
  - `create` after its audit log (`:50-53`), inside the transaction (after-commit dispatch);
  - `assign` only when `$previousHandlerId === null` (first assignment), after the audit log (`:149-152`); the existing `TicketAssignedNotification` line (`:146`) stays unchanged unless the owner adds the Findings fix;
  - `resolve` after its audit log (`:209`).
- NEW `Modules/SPK/app/Notifications/WorkOrderScheduledNotification(companyId, workOrderId, audience)` with audience `technician|customer`; via `WhatsAppChannel`, `afterCommit()`, queue `whatsapp`.
  - technician parameters: SPK code, title, scheduled date or "belum dijadwalkan", link `route('admin.spk.show', $wo)` (login required); no customer phone or address. Dedupe `spk_scheduled:{wo}:{technician user id}`.
  - customer parameters: company name, SPK code, scheduled date, technician first name; no technician phone. Dedupe `spk_scheduled_customer:{wo}`.
- Trigger in `SpkService::assign` (`Modules/SPK/app/Services/SpkService.php:62-138`) after its audit log (`:132-135`): technician message when the technician changed; customer message only when the work order had no previous technician and has `customer_id` and `scheduled_date`.
- Document the customer and technician messages in `docs/business/ticketing.md`.

Non-goals: ticket close/reopen, SLA breach, comments, SPK start/complete, reschedule (dates are not editable after assignment, `Modules/SPK/app/Http/Controllers/WorkOrderController.php:188-197`).

## Acceptance criteria

- [ ] Ticket with a consented customer: create, first assign, resolve each send exactly one message with the right stage; reassignment sends no second "assigned".
- [ ] Ticket without customer: no WhatsApp row at all.
- [ ] A ticket assignment that aborts (409 conflict) sends no WhatsApp.
- [ ] SPK first assignment with date and customer: one technician + one customer message; reassignment to another technician: one technician message only; same technician again: none.
- [ ] SPK without `scheduled_date`: technician message says "belum dijadwalkan"; no customer message.
- [ ] Technician without a valid phone or an active employee profile → `skipped: invalid_phone`/`no_consent`; the assignment still succeeds.
- [ ] Existing `TicketAssignmentNotificationTest` stays green; Pint clean; Larastan clean on touched files.
