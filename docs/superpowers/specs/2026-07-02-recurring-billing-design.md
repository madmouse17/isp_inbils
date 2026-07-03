# Recurring Billing (Pascabayar) — Design Spec

Date: 2026-07-02
Status: Approved by user
Branch target: new batch branch off `batch-1-core-erp-foundation` lineage

## Context

inbils is a mini ERP for an ISP provider (Indonesia). Billing module exists with
`Invoice`, `InvoiceItem`, `Payment`, `BillingService` — but invoices are manual
(one-by-one or from completed SPK). This batch adds automated monthly recurring
billing for subscriptions.

## Requirements (confirmed with user)

- **Billing model**: postpaid (pascabayar) — service used first, billed at period end.
- **Overdue handling**: fully manual. System provides an arrears list (AR aging);
  admin decides suspension per customer. No auto-suspend, no late fee (denda).
- **Tax**: PPN percentage configurable per company (default 11%, can be 0).
- **Distribution**: PDF download only. No email/WA in this batch.
- **Proration**: daily — mid-month activation/termination billed
  `(active days / days in month) × MRC`.
- **Payments**: manual recording (existing flow). Payment gateway stays in v2
  parking lot.
- **Trigger**: scheduled (cron, 1st of month) AND manual button in UI with
  dry-run preview. Same code path for both.

## Out of scope

Auto-suspend, denda/late fees, payment gateway (Midtrans/Xendit), email/WA
delivery, Mikrotik/RADIUS provisioning, customer portal.

## Design

### 1. Monthly invoice generator

**Artisan command**: `billing:generate --period=YYYY-MM [--dry-run]`
(default period: previous month, since postpaid bills after usage).

Logic (in `BillingService::generateForPeriod(string $period, bool $dryRun)`):

1. Collect subscriptions that were **active at any point during the period**:
   status `active`, plus those terminated mid-period (billed prorated final).
2. **Idempotent**: skip subscriptions that already have a non-cancelled
   `type=recurring` invoice whose `billing_period_start/end` matches the period.
   Safe to re-run.
3. Proration: full month if active whole period; else
   `(active days in period / days in period) × mrc_amount`, rounded to 2 dp.
   Activation date and termination date both count as active days.
4. Invoice number: use existing `NumberSequenceService` (race-safe). Replaces
   `BillingService::generateNumber()` for new invoices.
5. Issue with status `sent` directly (routine bills need no per-invoice draft
   review). `issue_date` = generation date, `due_date` = issue + N days
   (company setting `invoice_due_days`, default 14).
6. Tax: per-company PPN rate applied per line item (see §2).
7. `created_by`: make column nullable (migration) — scheduler runs without an
   authenticated user. When called from UI, use `Auth::id()`.
8. Dry-run returns the computed rows (customer, package, period, active days,
   amount, tax, total) without writing.

**Scheduler** (`routes/console.php`):
- `billing:generate` monthly on the 1st.
- `billing:check-overdue` daily (thin command wrapping `checkOverdue()`).

**Manual trigger UI**: on Billing invoices index, "Generate Tagihan" button →
period picker → server returns dry-run preview table → confirm → generate.
Controller calls the same `BillingService::generateForPeriod`.

### 2. Per-company tax setting

- Read PPN rate from `companies.settings` JSON (existing column) via
  `CompanyService`/company settings accessor; fallback to global
  `SettingService::get('default_tax_ppn_rate')`, final default 11.
- UI: PPN percentage field + `invoice_due_days` field on existing Company
  Settings page.
- Existing `createRecurring`/`createFromSpk` switch to the per-company reader.

### 3. Bug fix: `checkOverdue()`

`Modules/Billing/app/Services/BillingService.php` — current query:

```php
Invoice::where('status', 'sent')
    ->orWhere('status', 'partial')
    ->where('due_date', '<', ...)
```

Ungrouped `orWhere` marks not-yet-due `sent` invoices overdue. Fix with
`whereIn(['sent','partial'])` + grouped conditions.

### 4. PDF invoice

- Package: `barryvdh/laravel-dompdf` (lightweight, no headless browser).
- Route: `GET admin/billing/invoices/{invoice}/pdf` → download response.
- Blade template: company letterhead (name, address, logo from company),
  customer info, line items, subtotal, PPN, total, terbilang (amount in words,
  Indonesian), bank account info from company settings.
- Download button on invoice Show page.

### 5. AR Aging page (Tunggakan)

- Route: `GET admin/billing/receivables`.
- Query: outstanding invoices (`status in sent/partial/overdue`,
  `paid_amount < total`), grouped per customer with aging buckets:
  1–30, 31–60, 61–90, >90 days past due; current (not yet due) shown separately.
- Columns: customer, per-bucket amounts, total outstanding, link to invoices
  and subscription.
- Per-row "Isolir" shortcut → existing `SubscriptionService::suspend`
  (manual decision, per requirement).

### 6. Testing

Feature tests:
- Generator: creates invoice for active subscription; idempotent re-run;
  prorated activation (join mid-month); prorated termination; skips
  already-invoiced; dry-run writes nothing.
- Tax: per-company rate applied; fallback to global.
- `checkOverdue`: only past-due sent/partial become overdue.
- PDF route returns 200 with PDF content type.
- Aging: buckets computed correctly.

## Data changes

- Migration: `invoices.created_by` → nullable.
- No new tables. Company settings keys: `tax_ppn_rate`, `invoice_due_days`,
  `bank_account_info`.

## Dependencies

- New composer package: `barryvdh/laravel-dompdf` (justified: PDF generation
  cannot be done reasonably in a few lines; standard Laravel choice).
