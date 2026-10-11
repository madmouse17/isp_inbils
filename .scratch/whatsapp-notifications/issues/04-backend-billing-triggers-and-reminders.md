# BACKEND: invoice issued, payment received and H-3/H-1/H+1 reminders on WhatsApp

Status: done
Role: backend
Blocked by: 01
Card: none (new lane; no Hermes card)

## Scope

Billing rows of spec Q3; quiet hours Q6a; cost rules Q12a.

- NEW notifications in `Modules/Billing/app/Notifications/` (pattern `Modules/Ticketing/app/Notifications/TicketAssignedNotification.php`: `ShouldQueue`, ids only), each via `WhatsAppChannel` only, `afterCommit()`, queue `whatsapp`:
  - `InvoiceIssuedNotification(companyId, invoiceId)` — batch; parameters: company name, invoice number, total, due date (company date format), portal link `route('portal.invoices.show', $invoice)` (`Modules/Billing/routes/web.php:34`). Dedupe `invoice_issued:{id}`.
  - `InvoiceDueReminderNotification(companyId, invoiceId, stage)` with stage `H-3|H-1|H+1` — batch; parameters: company name, number, remaining balance (`Invoice::sisa`), due date, portal link. At send time the channel payload re-checks the invoice still has a balance and status `sent|partial|overdue`; otherwise nothing is sent and no row is written (paid between scheduling and sending). Dedupe `invoice_reminder:{id}:{stage}:{due_date}`.
  - `PaymentReceivedNotification(companyId, paymentId)` — not batch; parameters: company name, invoice number, amount, remaining balance, payment date. Dedupe `payment_received:{id}`.
- Triggers in `BillingService` (`Modules/Billing/app/Services/BillingService.php`), recipient = the invoice's customer:
  - recurring insert, inside the existing transaction after the audit line (`:154`); `afterCommit` makes it fire only if the row commits;
  - `send()` after its audit line (`:197`);
  - `recordPayment()` after its audit log (`:242-244`) — covers staff (`Modules/Billing/app/Http/Controllers/InvoiceController.php:267-275`) and Midtrans (`Modules/Billing/app/Services/PaymentAttemptService.php:197-203`) without touching either caller;
  - not on `draft` creation (`InvoiceController.php:124`, `app/Actions/CreateInvoiceFromSpkAction.php:50`), cancel or reversal.
- NEW `billing:whatsapp-reminders {--company=} {--dry-run}` (`Modules/Billing/app/Console/SendInvoiceWhatsAppReminders.php`, mimic `Modules/Billing/app/Console/CheckOverdueInvoices.php` for company iteration and failure notification): per active company in `CompanyService::runAs`, "today" in `companies.timezone`; select invoices `whereIn('status', ['sent','partial','overdue'])`, `paid_amount < total`, `due_date` in {today+3, today+1, today−1}, `customer` eager-loaded, `chunkById`; dispatch one reminder per invoice/stage; `--dry-run` prints counts only. Register in `Modules/Billing/app/Providers/BillingServiceProvider.php`; schedule in `routes/console.php`: `Schedule::command('billing:whatsapp-reminders')->dailyAt('01:00')->withoutOverlapping();` and `Schedule::command('model:prune', ['--model' => [WhatsAppMessage::class]])->daily();`.
- Document the three messages, reminder stages and quiet hours in `docs/business/billing.md`.

Non-goals: e-mail versions, invoice PDF attachment, reminders for `draft`/`cancelled`, auto-suspend.

## Acceptance criteria

- [ ] `billing:generate` for a consented customer sends exactly one "invoice issued" Graph request after commit; a forced rollback after the insert sends none; re-running generation sends nothing new.
- [ ] `send()` on a draft sends one; creating a draft sends none.
- [ ] Manual `recordPayment` and a Midtrans settlement each send one "payment received" with the correct remaining balance; a 422-rejected payment sends none.
- [ ] Reminder command on a fixed clock: invoices due today+3, today+1, today−1 each get the matching stage; due today+2 gets none; paid or cancelled invoices get none; running twice sends once.
- [ ] `Asia/Jakarta` boundary: at 17:30 UTC the command treats the next calendar day as "today" for a WIB company.
- [ ] Quiet hours: an invoice issued at 22:00 company time is delayed to 07:00 (assert job delay); a payment receipt at 22:00 is not delayed.
- [ ] Two companies with identical invoice numbers: each customer gets only their company's reminder.
- [ ] Billing suite green; Pint clean; Larastan clean on touched files.
