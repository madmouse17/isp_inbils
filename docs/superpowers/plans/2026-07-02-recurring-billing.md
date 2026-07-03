# Recurring Billing (Pascabayar) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Automated monthly postpaid invoice generation with daily proration, per-company PPN tax, PDF download, and an AR aging (Tunggakan) page.

**Architecture:** All billing logic lives in `Modules/Billing/app/Services/BillingService.php` (existing static-method service). A new `generateForPeriod()` is the single code path for both the scheduled artisan command and the manual UI button (dry-run flag for preview). Invoice numbering switches to the existing race-safe `NumberSequenceService`. Frontend is Inertia/React pages under `resources/js/Pages/Admin/Billing/`.

**Tech Stack:** Laravel 12, Inertia + React 18 + TS, MySQL, PHPUnit 11, `barryvdh/laravel-dompdf` (new dep, approved in spec).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-07-02-recurring-billing-design.md`
- Postpaid: period billed is the PREVIOUS month by default.
- Proration daily: `(active days / days in month) × mrc_amount`, round 2 dp. Activation and termination dates both count as active days.
- Idempotent generator: re-run must never duplicate an invoice for the same subscription+period (non-cancelled `type=recurring` match on `billing_period_start`).
- Recurring invoices issue with status `sent` (not draft), `sent_at` = now.
- Company settings keys (in `companies.settings` JSON): `tax_ppn_rate` (default 11), `invoice_due_days` (default 14), `bank_account_info` (default '').
- No auto-suspend, no denda, no gateway, no email/WA (out of scope).
- All commands run via `rtk` prefix (e.g. `rtk php artisan test`).
- Multi-company safe: generator runs without Auth — always use `withoutCompany()` scopes and explicit `company_id`.
- Tests: PHPUnit (`rtk php artisan test --filter=X`), use `Tests\Traits\CreatesCompanyUser` + `RefreshDatabase`, `actingAs($user)` so `BelongsToCompany` auto-fills.

---

### Task 1: Fix `checkOverdue()` bug

Ungrouped `orWhere` at `Modules/Billing/app/Services/BillingService.php:212` marks not-yet-due `sent` invoices overdue. Also missing `withoutCompany()` (scheduler has no Auth).

**Files:**
- Modify: `Modules/Billing/app/Services/BillingService.php:212-219`
- Test: `tests/Feature/Billing/CheckOverdueTest.php` (create)

**Interfaces:**
- Consumes: `Invoice` model, `CreatesCompanyUser` trait.
- Produces: `BillingService::checkOverdue(): void` (same signature, fixed behavior). Task 5's `billing:check-overdue` command calls this.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\BillingService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class CheckOverdueTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCompanyUser;

    public function test_only_past_due_sent_or_partial_become_overdue(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);

        $pastDueSent = Invoice::factory()->create([
            'status' => 'sent', 'due_date' => now()->subDays(3), 'total' => 100, 'paid_amount' => 0,
        ]);
        $futureSent = Invoice::factory()->create([
            'status' => 'sent', 'due_date' => now()->addDays(3), 'total' => 100, 'paid_amount' => 0,
        ]);
        $pastDuePartial = Invoice::factory()->create([
            'status' => 'partial', 'due_date' => now()->subDays(3), 'total' => 100, 'paid_amount' => 50,
        ]);
        $pastDueDraft = Invoice::factory()->create([
            'status' => 'draft', 'due_date' => now()->subDays(3), 'total' => 100, 'paid_amount' => 0,
        ]);

        BillingService::checkOverdue();

        $this->assertSame('overdue', $pastDueSent->fresh()->status);
        $this->assertSame('sent', $futureSent->fresh()->status);
        $this->assertSame('overdue', $pastDuePartial->fresh()->status);
        $this->assertSame('draft', $pastDueDraft->fresh()->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php artisan test --filter=CheckOverdueTest`
Expected: FAIL — `futureSent` asserts `sent` but current buggy query flips it to `overdue`.

- [ ] **Step 3: Fix the query**

Replace `checkOverdue()` in `Modules/Billing/app/Services/BillingService.php`:

```php
    public static function checkOverdue(): void
    {
        Invoice::withoutCompany()
            ->whereIn('status', ['sent', 'partial'])
            ->whereDate('due_date', '<', now())
            ->whereColumn('paid_amount', '<', 'total')
            ->update(['status' => 'overdue']);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `rtk php artisan test --filter=CheckOverdueTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
rtk git add Modules/Billing/app/Services/BillingService.php tests/Feature/Billing/CheckOverdueTest.php
rtk git commit -m "fix(billing): checkOverdue ungrouped orWhere marked future invoices overdue"
```

---

### Task 2: Nullable `created_by` + switch numbering to NumberSequenceService

Scheduler runs unauthenticated → `created_by` must allow null. All new invoice numbers go through the race-safe per-company `NumberSequenceService` (entity `invoice`, prefix `INV`); delete the old `BillingService::generateNumber()`.

**Files:**
- Create: `Modules/Billing/database/migrations/2026_07_02_100000_make_invoices_created_by_nullable.php`
- Modify: `Modules/Billing/app/Services/BillingService.php` (remove `generateNumber()`, update `createFromSpk`)
- Modify: `Modules/Billing/app/Http/Controllers/InvoiceController.php:62` (`store` uses NumberSequenceService)
- Test: `tests/Feature/Billing/InvoiceNumberingTest.php` (create)

**Interfaces:**
- Consumes: `App\Services\Core\NumberSequenceService::generate(string $entityType, ?string $prefix = null, ?int $companyId = null): string` (existing).
- Produces: invoices numbered `INV-<year>-<00001>` per company; `invoices.created_by` nullable. Task 4 generator relies on both.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class InvoiceNumberingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCompanyUser;

    public function test_store_uses_number_sequence_and_created_by_is_nullable(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $customer = Customer::factory()->create();

        $response = $this->post(route('admin.invoices.store'), [
            'customer_id' => $customer->id,
        ]);

        $invoice = Invoice::latest('id')->first();
        $this->assertNotNull($invoice);
        $this->assertMatchesRegularExpression('/^INV-\d{4}-\d{5}$/', $invoice->number);

        // created_by nullable at DB level (scheduler path)
        $bare = Invoice::factory()->create(['created_by' => null]);
        $this->assertNull($bare->fresh()->created_by);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php artisan test --filter=InvoiceNumberingTest`
Expected: FAIL — `created_by => null` violates NOT NULL constraint.

- [ ] **Step 3: Create migration**

`Modules/Billing/database/migrations/2026_07_02_100000_make_invoices_created_by_nullable.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable(false)->change();
        });
    }
};
```

- [ ] **Step 4: Switch numbering, delete `generateNumber()`**

In `Modules/Billing/app/Services/BillingService.php`:
- Add import: `use App\Services\Core\NumberSequenceService;`
- Delete the whole `generateNumber()` method (lines 15–29).
- In `createFromSpk()`, replace `'number' => self::generateNumber(),` with:

```php
                'number' => NumberSequenceService::generate('invoice', 'INV', $wo->company_id),
```

- In `createRecurring()`, replace `$data['number'] = self::generateNumber();` with:

```php
            $data['number'] = NumberSequenceService::generate('invoice', 'INV', $data['company_id'] ?? null);
```

(Note: `createRecurring` gets superseded and deleted in Task 4 — this keeps it green meanwhile.)

In `Modules/Billing/app/Http/Controllers/InvoiceController.php` `store()`, replace `$data['number'] = BillingService::generateNumber();` with:

```php
        $data['number'] = \App\Services\Core\NumberSequenceService::generate('invoice', 'INV');
```

Verify no other callers remain: `rtk grep "generateNumber" Modules app tests` → expect zero matches.

- [ ] **Step 5: Run tests**

Run: `rtk php artisan test --filter=InvoiceNumberingTest`
Expected: PASS. Also run `rtk php artisan test --filter=CheckOverdueTest` (still PASS).

- [ ] **Step 6: Commit**

```bash
rtk git add Modules/Billing tests/Feature/Billing/InvoiceNumberingTest.php
rtk git commit -m "feat(billing): race-safe invoice numbering via NumberSequenceService, nullable created_by"
```

---

### Task 3: Per-company tax + due-days settings

Read PPN rate and due days from `companies.settings` JSON with fallback to global `SettingService`. Expose the three billing keys on the existing Company Settings page.

**Files:**
- Modify: `Modules/Billing/app/Services/BillingService.php` (add `taxRateFor()`, `dueDaysFor()`; use in `createFromSpk`)
- Modify: `app/Http/Controllers/Admin/CompanyController.php` (`editSettings` merges billing defaults)
- Test: `tests/Feature/Billing/CompanyBillingSettingsTest.php` (create)

**Interfaces:**
- Consumes: `App\Models\Core\Company` (`settings` array cast), `SettingService::get(string, mixed): mixed`.
- Produces (used by Task 4 generator and Task 7 PDF):
  - `BillingService::taxRateFor(int $companyId): float` — company setting `tax_ppn_rate`, fallback global `default_tax_ppn_rate`, final default `11.0`.
  - `BillingService::dueDaysFor(int $companyId): int` — company setting `invoice_due_days`, default `14`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Services\BillingService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class CompanyBillingSettingsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCompanyUser;

    public function test_tax_rate_prefers_company_setting_over_global(): void
    {
        $user = $this->createCompanyUser();
        $company = $user->company;

        // global seeded default_tax_ppn_rate = 11
        $this->assertSame(11.0, BillingService::taxRateFor($company->id));

        $company->update(['settings' => array_merge($company->settings ?? [], ['tax_ppn_rate' => 0])]);
        $this->assertSame(0.0, BillingService::taxRateFor($company->id));
    }

    public function test_due_days_defaults_to_14_and_reads_company_setting(): void
    {
        $user = $this->createCompanyUser();
        $company = $user->company;

        $this->assertSame(14, BillingService::dueDaysFor($company->id));

        $company->update(['settings' => array_merge($company->settings ?? [], ['invoice_due_days' => 30])]);
        $this->assertSame(30, BillingService::dueDaysFor($company->id));
    }

    public function test_settings_page_shows_billing_keys(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);

        $this->get(route('admin.company.settings.edit'))
            ->assertInertia(fn ($page) => $page
                ->has('settings.tax_ppn_rate')
                ->has('settings.invoice_due_days')
                ->has('settings.bank_account_info'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php artisan test --filter=CompanyBillingSettingsTest`
Expected: FAIL — `taxRateFor` undefined.

- [ ] **Step 3: Implement helpers**

Add to `Modules/Billing/app/Services/BillingService.php` (import `use App\Models\Core\Company;`):

```php
    public static function taxRateFor(int $companyId): float
    {
        $settings = Company::find($companyId)?->settings ?? [];

        return (float) ($settings['tax_ppn_rate'] ?? SettingService::get('default_tax_ppn_rate', 11));
    }

    public static function dueDaysFor(int $companyId): int
    {
        $settings = Company::find($companyId)?->settings ?? [];

        return (int) ($settings['invoice_due_days'] ?? 14);
    }
```

In `createFromSpk()`, replace `$taxRate = (float) SettingService::get('default_tax_ppn_rate', 0);` with:

```php
            $taxRate = self::taxRateFor($wo->company_id);
```

(Leave `createRecurring` alone — deleted next task.)

In `app/Http/Controllers/Admin/CompanyController.php` `editSettings()`, change the `'settings'` prop line to merge billing defaults so the keys render as editable inputs:

```php
            'settings' => array_merge([
                'tax_ppn_rate' => 11,
                'invoice_due_days' => 14,
                'bank_account_info' => '',
            ], $company->settings ?? []),
```

- [ ] **Step 4: Run tests**

Run: `rtk php artisan test --filter=CompanyBillingSettingsTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
rtk git add Modules/Billing/app/Services/BillingService.php app/Http/Controllers/Admin/CompanyController.php tests/Feature/Billing/CompanyBillingSettingsTest.php
rtk git commit -m "feat(billing): per-company tax_ppn_rate and invoice_due_days settings"
```

---

### Task 4: Core generator `generateForPeriod()` with daily proration

The heart of the batch. One method, used by command (Task 5) and UI (Task 6). Deletes the now-dead `createRecurring()`.

**Files:**
- Modify: `Modules/Billing/app/Services/BillingService.php` (add `generateForPeriod`, `prorationFor`; delete `createRecurring`)
- Test: `tests/Feature/Billing/RecurringBillingTest.php` (create)

**Interfaces:**
- Consumes: `ServiceSubscription` (fields `status`, `activation_date` date-cast, `terminated_at` datetime-cast, `mrc_amount`, `company_id`, relations `customer`, `servicePackage`), `NumberSequenceService::generate`, `taxRateFor`, `dueDaysFor`, `recalculate`.
- Produces:

```php
// $period = 'YYYY-MM'. Returns:
// ['created' => int, 'skipped' => int, 'rows' => array<int, array{
//    subscription_id:int, subscription_code:string, customer:string, package:string,
//    active_days:int, days_in_period:int, amount:float, tax:float, total:float }>]
BillingService::generateForPeriod(string $period, bool $dryRun = false): array
```

Selection rule: subscriptions with `status IN (active, terminated)`, `activation_date <= period end`, and (`terminated_at` null OR `terminated_at >= period start`). Suspended excluded (isolir = no service). Pending (null activation) excluded automatically by the date comparison.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Billing;

use App\Models\Core\ServiceSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\BillingService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class RecurringBillingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCompanyUser;

    private function makeSubscription(array $attrs = []): ServiceSubscription
    {
        return ServiceSubscription::factory()->create(array_merge([
            'status' => 'active',
            'activation_date' => '2026-05-10',
            'mrc_amount' => 300000,
        ], $attrs));
    }

    public function test_generates_full_month_invoice_for_active_subscription(): void
    {
        $this->actingAs($this->createCompanyUser());
        $sub = $this->makeSubscription();

        $result = BillingService::generateForPeriod('2026-06');

        $this->assertSame(1, $result['created']);
        $invoice = Invoice::where('subscription_id', $sub->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame('recurring', $invoice->type);
        $this->assertSame('sent', $invoice->status);
        $this->assertSame('2026-06-01', $invoice->billing_period_start->toDateString());
        $this->assertSame('2026-06-30', $invoice->billing_period_end->toDateString());
        $this->assertEquals(300000.00, (float) $invoice->subtotal);
        // seeded global PPN 11%
        $this->assertEquals(33000.00, (float) $invoice->tax_amount);
        $this->assertEquals(333000.00, (float) $invoice->total);
        $this->assertNull($invoice->created_by);
    }

    public function test_is_idempotent(): void
    {
        $this->actingAs($this->createCompanyUser());
        $this->makeSubscription();

        BillingService::generateForPeriod('2026-06');
        $second = BillingService::generateForPeriod('2026-06');

        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['skipped']);
        $this->assertSame(1, Invoice::where('type', 'recurring')->count());
    }

    public function test_prorates_mid_month_activation(): void
    {
        $this->actingAs($this->createCompanyUser());
        // activated June 16 → 15 active days of 30
        $sub = $this->makeSubscription(['activation_date' => '2026-06-16']);

        BillingService::generateForPeriod('2026-06');

        $invoice = Invoice::where('subscription_id', $sub->id)->first();
        $this->assertEquals(150000.00, (float) $invoice->subtotal); // 15/30 × 300000
    }

    public function test_prorates_mid_month_termination(): void
    {
        $this->actingAs($this->createCompanyUser());
        // terminated June 20 → 20 active days of 30 (termination day counts)
        $sub = $this->makeSubscription([
            'status' => 'terminated',
            'terminated_at' => '2026-06-20 10:00:00',
        ]);

        BillingService::generateForPeriod('2026-06');

        $invoice = Invoice::where('subscription_id', $sub->id)->first();
        $this->assertEquals(200000.00, (float) $invoice->subtotal); // 20/30 × 300000
    }

    public function test_skips_not_yet_active_and_terminated_before_period(): void
    {
        $this->actingAs($this->createCompanyUser());
        $this->makeSubscription(['activation_date' => '2026-07-05']); // future
        $this->makeSubscription([
            'status' => 'terminated',
            'activation_date' => '2026-01-10',
            'terminated_at' => '2026-05-20 00:00:00', // before June
        ]);
        $this->makeSubscription(['status' => 'suspended']); // isolir

        $result = BillingService::generateForPeriod('2026-06');

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, Invoice::count());
    }

    public function test_dry_run_writes_nothing_but_returns_rows(): void
    {
        $this->actingAs($this->createCompanyUser());
        $this->makeSubscription();

        $result = BillingService::generateForPeriod('2026-06', dryRun: true);

        $this->assertSame(0, $result['created']);
        $this->assertCount(1, $result['rows']);
        $this->assertEquals(300000.00, $result['rows'][0]['amount']);
        $this->assertSame(0, Invoice::count());
    }

    public function test_uses_company_tax_rate(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $user->company->update(['settings' => ['tax_ppn_rate' => 0]]);
        $sub = $this->makeSubscription();

        BillingService::generateForPeriod('2026-06');

        $invoice = Invoice::where('subscription_id', $sub->id)->first();
        $this->assertEquals(0.00, (float) $invoice->tax_amount);
        $this->assertEquals(300000.00, (float) $invoice->total);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `rtk php artisan test --filter=RecurringBillingTest`
Expected: FAIL — `generateForPeriod` undefined.

- [ ] **Step 3: Implement**

In `Modules/Billing/app/Services/BillingService.php`. Add imports:

```php
use App\Models\Core\ServiceSubscription;
use Carbon\CarbonImmutable;
```

Delete the whole `createRecurring()` method (superseded; verify no callers: `rtk grep "createRecurring" Modules app tests` → only this file). Add:

```php
    /**
     * Generate postpaid recurring invoices for a period ('YYYY-MM').
     * Idempotent; safe to re-run. $dryRun computes rows without writing.
     */
    public static function generateForPeriod(string $period, bool $dryRun = false): array
    {
        $periodStart = CarbonImmutable::createFromFormat('Y-m', $period)->startOfMonth()->startOfDay();
        $periodEnd = $periodStart->endOfMonth()->startOfDay();

        $subscriptions = ServiceSubscription::withoutCompany()
            ->with(['customer', 'servicePackage'])
            ->whereIn('status', ['active', 'terminated'])
            ->whereDate('activation_date', '<=', $periodEnd)
            ->where(fn ($q) => $q->whereNull('terminated_at')
                ->orWhereDate('terminated_at', '>=', $periodStart))
            ->get();

        $rows = [];
        $created = 0;
        $skipped = 0;

        foreach ($subscriptions as $sub) {
            $exists = Invoice::withoutCompany()
                ->where('subscription_id', $sub->id)
                ->where('type', 'recurring')
                ->where('status', '!=', 'cancelled')
                ->whereDate('billing_period_start', $periodStart)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            [$activeDays, $daysInPeriod, $amount] = self::prorationFor($sub, $periodStart, $periodEnd);

            if ($amount <= 0) {
                $skipped++;
                continue;
            }

            $taxRate = self::taxRateFor($sub->company_id);
            $tax = round($amount * $taxRate / 100, 2);

            $rows[] = [
                'subscription_id' => $sub->id,
                'subscription_code' => $sub->code,
                'customer' => $sub->customer?->name ?? '-',
                'package' => $sub->servicePackage?->name ?? '-',
                'active_days' => $activeDays,
                'days_in_period' => $daysInPeriod,
                'amount' => $amount,
                'tax' => $tax,
                'total' => round($amount + $tax, 2),
            ];

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($sub, $periodStart, $periodEnd, $activeDays, $daysInPeriod, $amount, $taxRate) {
                $invoice = Invoice::create([
                    'company_id' => $sub->company_id,
                    'number' => \App\Services\Core\NumberSequenceService::generate('invoice', 'INV', $sub->company_id),
                    'type' => 'recurring',
                    'source' => 'subscription',
                    'customer_id' => $sub->customer_id,
                    'subscription_id' => $sub->id,
                    'issue_date' => now()->toDateString(),
                    'due_date' => now()->addDays(self::dueDaysFor($sub->company_id))->toDateString(),
                    'billing_period_start' => $periodStart->toDateString(),
                    'billing_period_end' => $periodEnd->toDateString(),
                    'status' => 'sent',
                    'sent_at' => now(),
                    'created_by' => Auth::id(),
                ]);

                $label = 'MRC ' . ($sub->servicePackage?->name ?? 'Subscription')
                    . ' ' . $periodStart->toDateString() . ' s/d ' . $periodEnd->toDateString();
                if ($activeDays < $daysInPeriod) {
                    $label .= " (prorata {$activeDays}/{$daysInPeriod} hari)";
                }

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $label,
                    'quantity' => 1,
                    'unit_price' => $amount,
                    'tax_rate' => $taxRate,
                    'line_total' => $amount,
                ]);

                self::recalculate($invoice);

                AuditService::log('invoice', 'recurring_generated', ['number' => $invoice->number], $invoice);
            });

            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped, 'rows' => $rows];
    }

    /** @return array{0:int,1:int,2:float} [activeDays, daysInPeriod, amount] */
    private static function prorationFor(ServiceSubscription $sub, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $daysInPeriod = $periodStart->daysInMonth;

        $activation = CarbonImmutable::parse($sub->activation_date)->startOfDay();
        $from = $activation->gt($periodStart) ? $activation : $periodStart;

        $termination = $sub->terminated_at
            ? CarbonImmutable::parse($sub->terminated_at)->startOfDay()
            : null;
        $until = ($termination && $termination->lt($periodEnd)) ? $termination : $periodEnd;

        if ($from->gt($until)) {
            return [0, $daysInPeriod, 0.0];
        }

        $activeDays = (int) $from->diffInDays($until) + 1;
        $amount = $activeDays >= $daysInPeriod
            ? round((float) $sub->mrc_amount, 2)
            : round(($activeDays / $daysInPeriod) * (float) $sub->mrc_amount, 2);

        return [$activeDays, $daysInPeriod, $amount];
    }
```

Note: `recalculate()` recomputes tax from `tax_rate` on items, so invoice `tax_amount`/`total` land correctly.

- [ ] **Step 4: Run tests**

Run: `rtk php artisan test --filter=RecurringBillingTest`
Expected: PASS (7 tests). Also full billing group: `rtk php artisan test tests/Feature/Billing` → all PASS.

- [ ] **Step 5: Commit**

```bash
rtk git add Modules/Billing/app/Services/BillingService.php tests/Feature/Billing/RecurringBillingTest.php
rtk git commit -m "feat(billing): postpaid recurring generator with daily proration, idempotent"
```

---

### Task 5: Artisan commands + scheduler

Thin wrappers around Task 1 and Task 4 service methods.

**Files:**
- Create: `Modules/Billing/app/Console/GenerateRecurringInvoices.php`
- Create: `Modules/Billing/app/Console/CheckOverdueInvoices.php`
- Modify: `routes/console.php` (schedule both)
- Test: `tests/Feature/Billing/BillingCommandsTest.php` (create)

Check first: nwidart modules usually autoload `Modules/Billing/app/Console` commands via the module service provider (`$this->commands([...])` or auto-discovery). Open `Modules/Billing/app/Providers/BillingServiceProvider.php`; if commands aren't registered, add `$this->commands([GenerateRecurringInvoices::class, CheckOverdueInvoices::class]);` in `boot()`.

**Interfaces:**
- Consumes: `BillingService::generateForPeriod(string, bool): array`, `BillingService::checkOverdue(): void`.
- Produces: `billing:generate {--period=} {--dry-run}` (default period = previous month), `billing:check-overdue`. Task 6 UI does NOT call these (calls service directly).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Billing;

use App\Models\Core\ServiceSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class BillingCommandsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCompanyUser;

    public function test_generate_command_creates_invoices_for_given_period(): void
    {
        $this->actingAs($this->createCompanyUser());
        ServiceSubscription::factory()->create([
            'status' => 'active',
            'activation_date' => '2026-05-10',
            'mrc_amount' => 300000,
        ]);
        auth()->logout(); // command runs unauthenticated, like the scheduler

        $this->artisan('billing:generate', ['--period' => '2026-06'])
            ->expectsOutputToContain('created: 1')
            ->assertExitCode(0);

        $this->assertSame(1, Invoice::withoutCompany()->where('type', 'recurring')->count());
    }

    public function test_dry_run_creates_nothing(): void
    {
        $this->actingAs($this->createCompanyUser());
        ServiceSubscription::factory()->create([
            'status' => 'active',
            'activation_date' => '2026-05-10',
            'mrc_amount' => 300000,
        ]);
        auth()->logout();

        $this->artisan('billing:generate', ['--period' => '2026-06', '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(0, Invoice::withoutCompany()->count());
    }

    public function test_check_overdue_command_runs(): void
    {
        $this->artisan('billing:check-overdue')->assertExitCode(0);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php artisan test --filter=BillingCommandsTest`
Expected: FAIL — command not found.

- [ ] **Step 3: Implement commands**

`Modules/Billing/app/Console/GenerateRecurringInvoices.php`:

```php
<?php

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Services\BillingService;

class GenerateRecurringInvoices extends Command
{
    protected $signature = 'billing:generate {--period= : Billing period YYYY-MM (default: previous month)} {--dry-run : Compute without creating invoices}';

    protected $description = 'Generate postpaid recurring invoices for a billing period';

    public function handle(): int
    {
        $period = $this->option('period') ?: now()->subMonthNoOverflow()->format('Y-m');
        $dryRun = (bool) $this->option('dry-run');

        $result = BillingService::generateForPeriod($period, $dryRun);

        $this->table(
            ['Subscription', 'Customer', 'Package', 'Days', 'Amount', 'Tax', 'Total'],
            array_map(fn ($r) => [
                $r['subscription_code'], $r['customer'], $r['package'],
                "{$r['active_days']}/{$r['days_in_period']}",
                number_format($r['amount'], 2), number_format($r['tax'], 2), number_format($r['total'], 2),
            ], $result['rows']),
        );

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Period {$period} — created: {$result['created']}, skipped: {$result['skipped']}");

        return self::SUCCESS;
    }
}
```

`Modules/Billing/app/Console/CheckOverdueInvoices.php`:

```php
<?php

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Services\BillingService;

class CheckOverdueInvoices extends Command
{
    protected $signature = 'billing:check-overdue';

    protected $description = 'Mark past-due sent/partial invoices as overdue';

    public function handle(): int
    {
        BillingService::checkOverdue();
        $this->info('Overdue check complete.');

        return self::SUCCESS;
    }
}
```

Register in `Modules/Billing/app/Providers/BillingServiceProvider.php` `boot()` if not auto-discovered:

```php
        $this->commands([
            \Modules\Billing\Console\GenerateRecurringInvoices::class,
            \Modules\Billing\Console\CheckOverdueInvoices::class,
        ]);
```

Append to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('billing:generate')->monthlyOn(1, '02:00');
Schedule::command('billing:check-overdue')->dailyAt('02:30');
```

- [ ] **Step 4: Run tests**

Run: `rtk php artisan test --filter=BillingCommandsTest`
Expected: PASS. Sanity: `rtk php artisan billing:generate --period=2026-06 --dry-run` runs without error. `rtk php artisan schedule:list` shows both entries.

- [ ] **Step 5: Commit**

```bash
rtk git add Modules/Billing routes/console.php tests/Feature/Billing/BillingCommandsTest.php
rtk git commit -m "feat(billing): billing:generate and billing:check-overdue commands with monthly/daily schedule"
```

---

### Task 6: Manual generate UI with dry-run preview

"Generate Tagihan" button on invoice index → dialog: pick period → preview table (dry-run JSON) → confirm → generate → back with flash.

**Files:**
- Modify: `Modules/Billing/app/Http/Controllers/InvoiceController.php` (add `generatePreview`, `generate`)
- Modify: `Modules/Billing/routes/web.php` (two POST routes, BEFORE the resource route)
- Create: `resources/js/Pages/Admin/Billing/Invoices/GenerateDialog.tsx`
- Modify: `resources/js/Pages/Admin/Billing/Invoices/Index.tsx` (button + dialog)
- Test: `tests/Feature/Billing/GenerateEndpointTest.php` (create)

**Interfaces:**
- Consumes: `BillingService::generateForPeriod(string, bool): array`, existing `billing.create` permission.
- Produces: `POST admin/invoices/generate-preview` (JSON `{rows, created, skipped}` with dry-run), `POST admin/invoices/generate` (redirect back with `success` flash).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Billing;

use App\Models\Core\ServiceSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class GenerateEndpointTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCompanyUser;

    private function makeActiveSub(): void
    {
        ServiceSubscription::factory()->create([
            'status' => 'active',
            'activation_date' => '2026-05-10',
            'mrc_amount' => 300000,
        ]);
    }

    public function test_preview_returns_rows_without_creating(): void
    {
        $this->actingAs($this->createCompanyUser());
        $this->makeActiveSub();

        $response = $this->postJson(route('admin.invoices.generate-preview'), ['period' => '2026-06']);

        $response->assertOk()->assertJsonCount(1, 'rows');
        $this->assertSame(0, Invoice::count());
    }

    public function test_generate_creates_invoices(): void
    {
        $this->actingAs($this->createCompanyUser());
        $this->makeActiveSub();

        $this->post(route('admin.invoices.generate'), ['period' => '2026-06'])
            ->assertRedirect();

        $this->assertSame(1, Invoice::where('type', 'recurring')->count());
    }

    public function test_period_is_validated(): void
    {
        $this->actingAs($this->createCompanyUser());

        $this->postJson(route('admin.invoices.generate-preview'), ['period' => 'nope'])
            ->assertUnprocessable();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php artisan test --filter=GenerateEndpointTest`
Expected: FAIL — route not defined.

- [ ] **Step 3: Backend**

In `Modules/Billing/routes/web.php`, add before `Route::resource('invoices', ...)`:

```php
    Route::post('invoices/generate-preview', [InvoiceController::class, 'generatePreview'])->name('invoices.generate-preview');
    Route::post('invoices/generate', [InvoiceController::class, 'generate'])->name('invoices.generate');
```

In `InvoiceController`:

```php
    public function generatePreview(Request $request)
    {
        Gate::authorize('billing.create');
        $request->validate(['period' => ['required', 'date_format:Y-m']]);

        return response()->json(BillingService::generateForPeriod($request->input('period'), dryRun: true));
    }

    public function generate(Request $request): RedirectResponse
    {
        Gate::authorize('billing.create');
        $request->validate(['period' => ['required', 'date_format:Y-m']]);

        $result = BillingService::generateForPeriod($request->input('period'));

        return back()->with('success', "Tagihan {$request->input('period')}: {$result['created']} dibuat, {$result['skipped']} dilewati.");
    }
```

- [ ] **Step 4: Run backend tests**

Run: `rtk php artisan test --filter=GenerateEndpointTest`
Expected: PASS

- [ ] **Step 5: Frontend dialog**

`resources/js/Pages/Admin/Billing/Invoices/GenerateDialog.tsx` (follow existing UI kit imports from `@/Components/ui` — check `Index.tsx` for the modal/dialog component the project uses; adapt markup to it):

```tsx
import { useState } from 'react';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Button, Card, CardContent, CardFooter, CardHeader, CardTitle, Input } from '@/Components/ui';

interface PreviewRow {
    subscription_id: number;
    subscription_code: string;
    customer: string;
    package: string;
    active_days: number;
    days_in_period: number;
    amount: number;
    tax: number;
    total: number;
}

const previousMonth = () => {
    const d = new Date();
    d.setMonth(d.getMonth() - 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
};

const idr = (n: number) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n);

export default function GenerateDialog({ onClose }: { onClose: () => void }) {
    const [period, setPeriod] = useState(previousMonth());
    const [rows, setRows] = useState<PreviewRow[] | null>(null);
    const [skipped, setSkipped] = useState(0);
    const [loading, setLoading] = useState(false);

    const preview = async () => {
        setLoading(true);
        try {
            const { data } = await axios.post(route('admin.invoices.generate-preview'), { period });
            setRows(data.rows);
            setSkipped(data.skipped);
        } finally {
            setLoading(false);
        }
    };

    const confirm = () => {
        router.post(route('admin.invoices.generate'), { period }, { onSuccess: onClose });
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <Card className="max-h-[85vh] w-full max-w-3xl overflow-y-auto">
                <CardHeader>
                    <CardTitle>Generate Tagihan Bulanan</CardTitle>
                </CardHeader>
                <CardContent className="space-y-4">
                    <Input label="Periode (YYYY-MM)" type="month" value={period} onChange={(e) => { setPeriod(e.target.value); setRows(null); }} />
                    {rows && (
                        <>
                            <p className="text-sm text-surface-500">{rows.length} tagihan akan dibuat, {skipped} dilewati (sudah ada / nol).</p>
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left">
                                        <th className="py-1">Pelanggan</th><th>Paket</th><th>Hari</th><th className="text-right">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((r) => (
                                        <tr key={r.subscription_id} className="border-b">
                                            <td className="py-1">{r.customer}</td>
                                            <td>{r.package}</td>
                                            <td>{r.active_days}/{r.days_in_period}</td>
                                            <td className="text-right">{idr(r.total)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </>
                    )}
                </CardContent>
                <CardFooter className="justify-end gap-2">
                    <Button variant="secondary" onClick={onClose}>Batal</Button>
                    <Button onClick={preview} loading={loading}>Preview</Button>
                    {rows !== null && rows.length > 0 && <Button onClick={confirm}>Terbitkan {rows.length} Tagihan</Button>}
                </CardFooter>
            </Card>
        </div>
    );
}
```

In `Index.tsx`: add state `const [showGenerate, setShowGenerate] = useState(false);`, a `<Button onClick={() => setShowGenerate(true)}>Generate Tagihan</Button>` next to the existing create button (render only when `can.create`), and `{showGenerate && <GenerateDialog onClose={() => setShowGenerate(false)} />}`.

- [ ] **Step 6: Build + verify**

Run: `rtk npm run build`
Expected: build succeeds, no TS errors.

- [ ] **Step 7: Commit**

```bash
rtk git add Modules/Billing resources/js/Pages/Admin/Billing tests/Feature/Billing/GenerateEndpointTest.php
rtk git commit -m "feat(billing): manual generate UI with dry-run preview"
```

---

### Task 7: PDF invoice download

**Files:**
- Modify: `composer.json` via `rtk composer require barryvdh/laravel-dompdf`
- Create: `Modules/Billing/app/Support/Terbilang.php`
- Create: `resources/views/pdf/invoice.blade.php`
- Modify: `Modules/Billing/app/Http/Controllers/InvoiceController.php` (add `pdf`)
- Modify: `Modules/Billing/routes/web.php` (GET pdf route)
- Modify: `resources/js/Pages/Admin/Billing/Invoices/Show.tsx` (download button)
- Test: `tests/Feature/Billing/InvoicePdfTest.php` (create)

**Interfaces:**
- Consumes: `Invoice` with `items`, `customer`, `subscription.servicePackage`; company settings `bank_account_info`.
- Produces: `GET admin/invoices/{invoice}/pdf` → PDF download; `Terbilang::make(float): string` (Indonesian amount-in-words, e.g. `333000 → "tiga ratus tiga puluh tiga ribu rupiah"`).

- [ ] **Step 1: Install package**

Run: `rtk composer require barryvdh/laravel-dompdf`
Expected: installed, auto-discovered.

- [ ] **Step 2: Write the failing tests**

```php
<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Support\Terbilang;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCompanyUser;

    public function test_terbilang(): void
    {
        $this->assertSame('tiga ratus tiga puluh tiga ribu rupiah', Terbilang::make(333000));
        $this->assertSame('satu juta lima ratus ribu rupiah', Terbilang::make(1500000));
        $this->assertSame('sebelas rupiah', Terbilang::make(11));
        $this->assertSame('nol rupiah', Terbilang::make(0));
    }

    public function test_pdf_route_returns_pdf(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $invoice = Invoice::factory()->create(['total' => 100000]);

        $response = $this->get(route('admin.invoices.pdf', $invoice));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `rtk php artisan test --filter=InvoicePdfTest`
Expected: FAIL — Terbilang class not found.

- [ ] **Step 4: Implement Terbilang**

`Modules/Billing/app/Support/Terbilang.php`:

```php
<?php

namespace Modules\Billing\Support;

final class Terbilang
{
    private const ANGKA = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];

    public static function make(float $number): string
    {
        $n = (int) floor(abs($number));
        $words = $n === 0 ? 'nol' : self::convert($n);

        return trim(preg_replace('/\s+/', ' ', $words)) . ' rupiah';
    }

    private static function convert(int $n): string
    {
        return match (true) {
            $n < 12 => self::ANGKA[$n],
            $n < 20 => self::convert($n - 10) . ' belas',
            $n < 100 => self::convert(intdiv($n, 10)) . ' puluh ' . self::convert($n % 10),
            $n < 200 => 'seratus ' . self::convert($n - 100),
            $n < 1000 => self::convert(intdiv($n, 100)) . ' ratus ' . self::convert($n % 100),
            $n < 2000 => 'seribu ' . self::convert($n - 1000),
            $n < 1000000 => self::convert(intdiv($n, 1000)) . ' ribu ' . self::convert($n % 1000),
            $n < 1000000000 => self::convert(intdiv($n, 1000000)) . ' juta ' . self::convert($n % 1000000),
            default => self::convert(intdiv($n, 1000000000)) . ' miliar ' . self::convert($n % 1000000000),
        };
    }
}
```

- [ ] **Step 5: Route + controller + blade**

Route in `Modules/Billing/routes/web.php` (before resource):

```php
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
```

Controller method (imports: `use Barryvdh\DomPDF\Facade\Pdf;`, `use Modules\Billing\Support\Terbilang;`, `use App\Services\Core\CompanyService;`):

```php
    public function pdf(Invoice $invoice)
    {
        $this->ensureSameCompany($invoice);
        Gate::authorize('view', $invoice);

        $invoice->load(['customer', 'subscription.servicePackage', 'items']);
        $company = CompanyService::current();

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'company' => $company,
            'bankInfo' => $company->settings['bank_account_info'] ?? '',
            'terbilang' => Terbilang::make((float) $invoice->total),
        ])->download($invoice->number . '.pdf');
    }
```

`resources/views/pdf/invoice.blade.php`:

```blade
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        .header { border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 16px; }
        .company-name { font-size: 18px; font-weight: bold; }
        .row { width: 100%; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.items th, table.items td { border: 1px solid #999; padding: 6px 8px; }
        table.items th { background: #eee; text-align: left; }
        .right { text-align: right; }
        .totals td { border: none; padding: 3px 8px; }
        .terbilang { font-style: italic; margin-top: 8px; }
        .status { font-size: 14px; font-weight: bold; text-transform: uppercase; }
        .muted { color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <div class="company-name">{{ $company->name }}</div>
        <div class="muted">{{ $company->address ?? '' }}</div>
    </div>

    <table class="row">
        <tr>
            <td>
                <strong>INVOICE {{ $invoice->number }}</strong><br>
                Tanggal: {{ $invoice->issue_date->format('d/m/Y') }}<br>
                Jatuh tempo: {{ $invoice->due_date->format('d/m/Y') }}<br>
                @if ($invoice->billing_period_start)
                    Periode: {{ $invoice->billing_period_start->format('d/m/Y') }} s/d {{ $invoice->billing_period_end->format('d/m/Y') }}<br>
                @endif
                <span class="status">{{ $invoice->status }}</span>
            </td>
            <td class="right">
                <strong>Kepada:</strong><br>
                {{ $invoice->customer->name }}<br>
                @if ($invoice->subscription)
                    {{ $invoice->subscription->code }} — {{ $invoice->subscription->servicePackage?->name }}
                @endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr><th>Deskripsi</th><th class="right">Qty</th><th class="right">Harga</th><th class="right">Jumlah</th></tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="right">{{ rtrim(rtrim(number_format($item->quantity, 2, ',', '.'), '0'), ',') }}</td>
                    <td class="right">Rp {{ number_format($item->unit_price, 0, ',', '.') }}</td>
                    <td class="right">Rp {{ number_format($item->line_total, 0, ',', '.') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="row totals" style="margin-top: 8px;">
        <tr><td class="right" style="width: 80%;">Subtotal</td><td class="right">Rp {{ number_format($invoice->subtotal, 0, ',', '.') }}</td></tr>
        @if ((float) $invoice->tax_amount > 0)
            <tr><td class="right">PPN</td><td class="right">Rp {{ number_format($invoice->tax_amount, 0, ',', '.') }}</td></tr>
        @endif
        @if ((float) $invoice->discount_amount > 0)
            <tr><td class="right">Diskon</td><td class="right">- Rp {{ number_format($invoice->discount_amount, 0, ',', '.') }}</td></tr>
        @endif
        <tr><td class="right"><strong>Total</strong></td><td class="right"><strong>Rp {{ number_format($invoice->total, 0, ',', '.') }}</strong></td></tr>
        @if ((float) $invoice->paid_amount > 0)
            <tr><td class="right">Terbayar</td><td class="right">Rp {{ number_format($invoice->paid_amount, 0, ',', '.') }}</td></tr>
            <tr><td class="right"><strong>Sisa</strong></td><td class="right"><strong>Rp {{ number_format($invoice->total - $invoice->paid_amount, 0, ',', '.') }}</strong></td></tr>
        @endif
    </table>

    <p class="terbilang">Terbilang: {{ ucfirst($terbilang) }}</p>

    @if ($bankInfo)
        <p><strong>Pembayaran:</strong><br>{!! nl2br(e($bankInfo)) !!}</p>
    @endif
</body>
</html>
```

Note: check `Company` model for the address field name (`address` assumed) — adjust blade if it differs.

In `Show.tsx`, add a download button near the existing action buttons:

```tsx
<Button variant="secondary" onClick={() => window.open(route('admin.invoices.pdf', invoice.id), '_blank')}>
    Download PDF
</Button>
```

- [ ] **Step 6: Run tests**

Run: `rtk php artisan test --filter=InvoicePdfTest`
Expected: PASS. Also `rtk npm run build` → succeeds.

- [ ] **Step 7: Commit**

```bash
rtk git add composer.json composer.lock Modules/Billing resources/views/pdf resources/js/Pages/Admin/Billing/Invoices/Show.tsx tests/Feature/Billing/InvoicePdfTest.php
rtk git commit -m "feat(billing): invoice PDF download with terbilang and company letterhead"
```

---

### Task 8: AR Aging page (Tunggakan)

Outstanding invoices grouped per customer with aging buckets + per-subscription Isolir shortcut.

**Files:**
- Modify: `Modules/Billing/app/Services/BillingService.php` (add `receivables()`)
- Modify: `Modules/Billing/app/Http/Controllers/InvoiceController.php` (add `receivables` action)
- Modify: `Modules/Billing/routes/web.php` (GET route)
- Create: `resources/js/Pages/Admin/Billing/Receivables.tsx`
- Test: `tests/Feature/Billing/ReceivablesTest.php` (create)

**Interfaces:**
- Consumes: `Invoice` (company-scoped via auth), existing `admin.subscriptions.suspend` route (`POST`, body `{reason: string}`).
- Produces:

```php
// Company-scoped (call within auth context). Buckets keyed by days past due.
// Returns array<int, array{
//   customer_id:int, customer:string,
//   current:float, b1_30:float, b31_60:float, b61_90:float, b90_plus:float, total:float,
//   invoice_count:int,
//   subscriptions: array<int, array{id:int, code:string, status:string}> }>
BillingService::receivables(): array
```

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Customer;
use App\Models\Core\ServiceSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\BillingService;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class ReceivablesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCompanyUser;

    public function test_buckets_computed_per_customer(): void
    {
        $this->actingAs($this->createCompanyUser());
        $customer = Customer::factory()->create(['name' => 'Budi']);

        // not yet due → current
        Invoice::factory()->create(['customer_id' => $customer->id, 'status' => 'sent', 'due_date' => now()->addDays(5), 'total' => 100000, 'paid_amount' => 0]);
        // 10 days past due → 1-30
        Invoice::factory()->create(['customer_id' => $customer->id, 'status' => 'overdue', 'due_date' => now()->subDays(10), 'total' => 200000, 'paid_amount' => 0]);
        // 45 days past due, partially paid → 31-60, outstanding 150000
        Invoice::factory()->create(['customer_id' => $customer->id, 'status' => 'partial', 'due_date' => now()->subDays(45), 'total' => 250000, 'paid_amount' => 100000]);
        // 100 days past due → >90
        Invoice::factory()->create(['customer_id' => $customer->id, 'status' => 'overdue', 'due_date' => now()->subDays(100), 'total' => 50000, 'paid_amount' => 0]);
        // paid → excluded
        Invoice::factory()->create(['customer_id' => $customer->id, 'status' => 'paid', 'due_date' => now()->subDays(10), 'total' => 99999, 'paid_amount' => 99999]);

        $rows = BillingService::receivables();

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('Budi', $row['customer']);
        $this->assertEquals(100000.0, $row['current']);
        $this->assertEquals(200000.0, $row['b1_30']);
        $this->assertEquals(150000.0, $row['b31_60']);
        $this->assertEquals(0.0, $row['b61_90']);
        $this->assertEquals(50000.0, $row['b90_plus']);
        $this->assertEquals(500000.0, $row['total']);
        $this->assertSame(4, $row['invoice_count']);
    }

    public function test_page_renders(): void
    {
        $this->actingAs($this->createCompanyUser());

        $this->get(route('admin.billing.receivables'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Billing/Receivables'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `rtk php artisan test --filter=ReceivablesTest`
Expected: FAIL — `receivables` undefined.

- [ ] **Step 3: Implement service + controller + route**

Add to `BillingService` (import `use App\Models\Core\Customer;` not needed — go through Invoice):

```php
    public static function receivables(): array
    {
        $invoices = Invoice::query()
            ->with('customer:id,name')
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->whereColumn('paid_amount', '<', 'total')
            ->get();

        $subsByCustomer = ServiceSubscription::query()
            ->whereIn('customer_id', $invoices->pluck('customer_id')->unique())
            ->where('status', 'active')
            ->get(['id', 'code', 'status', 'customer_id'])
            ->groupBy('customer_id');

        return $invoices
            ->groupBy('customer_id')
            ->map(function ($group) use ($subsByCustomer) {
                $buckets = ['current' => 0.0, 'b1_30' => 0.0, 'b31_60' => 0.0, 'b61_90' => 0.0, 'b90_plus' => 0.0];

                foreach ($group as $invoice) {
                    $outstanding = (float) $invoice->total - (float) $invoice->paid_amount;
                    $daysPast = (int) $invoice->due_date->startOfDay()->diffInDays(now()->startOfDay(), false);

                    $key = match (true) {
                        $daysPast <= 0 => 'current',
                        $daysPast <= 30 => 'b1_30',
                        $daysPast <= 60 => 'b31_60',
                        $daysPast <= 90 => 'b61_90',
                        default => 'b90_plus',
                    };
                    $buckets[$key] += $outstanding;
                }

                $first = $group->first();

                return [
                    'customer_id' => $first->customer_id,
                    'customer' => $first->customer?->name ?? '-',
                    ...$buckets,
                    'total' => array_sum($buckets),
                    'invoice_count' => $group->count(),
                    'subscriptions' => ($subsByCustomer[$first->customer_id] ?? collect())
                        ->map(fn ($s) => ['id' => $s->id, 'code' => $s->code, 'status' => $s->status])
                        ->values()->all(),
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }
```

Controller:

```php
    public function receivables(Request $request): InertiaResponse
    {
        Gate::authorize('viewAny', Invoice::class);

        return Inertia::render('Admin/Billing/Receivables', [
            'rows' => BillingService::receivables(),
            'can' => ['suspend' => $request->user()?->can('subscription.suspend') ?? false],
        ]);
    }
```

(Check the exact suspend permission name: `rtk grep "suspend" app/Http/Controllers/Admin/SubscriptionController.php` — mirror whatever gate it uses; adjust `can.suspend` accordingly.)

Route (before resource):

```php
    Route::get('billing/receivables', [InvoiceController::class, 'receivables'])->name('billing.receivables');
```

- [ ] **Step 4: Run backend tests**

Run: `rtk php artisan test --filter=ReceivablesTest`
Expected: PASS

- [ ] **Step 5: Frontend page**

`resources/js/Pages/Admin/Billing/Receivables.tsx`:

```tsx
import { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, CardContent } from '@/Components/ui';

interface SubRef { id: number; code: string; status: string }
interface Row {
    customer_id: number;
    customer: string;
    current: number; b1_30: number; b31_60: number; b61_90: number; b90_plus: number;
    total: number;
    invoice_count: number;
    subscriptions: SubRef[];
}

const idr = (n: number) => (n > 0 ? new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n) : '-');

export default function Receivables({ rows, can }: { rows: Row[]; can: { suspend: boolean } }) {
    const [suspending, setSuspending] = useState<SubRef | null>(null);
    const [reason, setReason] = useState('');

    const suspend = () => {
        if (!suspending) return;
        router.post(route('admin.subscriptions.suspend', suspending.id), { reason }, {
            onSuccess: () => { setSuspending(null); setReason(''); },
        });
    };

    return (
        <AdminLayout title="Tunggakan">
            <div className="space-y-6">
                <h2 className="text-2xl font-bold text-surface-900 dark:text-surface-100">Tunggakan (AR Aging)</h2>
                <Card>
                    <CardContent className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left">
                                    <th className="py-2">Pelanggan</th>
                                    <th className="text-right">Belum Jatuh Tempo</th>
                                    <th className="text-right">1–30 hari</th>
                                    <th className="text-right">31–60 hari</th>
                                    <th className="text-right">61–90 hari</th>
                                    <th className="text-right">&gt;90 hari</th>
                                    <th className="text-right">Total</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((r) => (
                                    <tr key={r.customer_id} className="border-b">
                                        <td className="py-2">
                                            <Link className="text-brand-600 hover:underline" href={route('admin.invoices.index', { customer_id: r.customer_id })}>
                                                {r.customer}
                                            </Link>
                                            <span className="ml-1 text-surface-400">({r.invoice_count} invoice)</span>
                                        </td>
                                        <td className="text-right">{idr(r.current)}</td>
                                        <td className="text-right">{idr(r.b1_30)}</td>
                                        <td className="text-right">{idr(r.b31_60)}</td>
                                        <td className="text-right">{idr(r.b61_90)}</td>
                                        <td className="text-right font-semibold text-red-600">{idr(r.b90_plus)}</td>
                                        <td className="text-right font-semibold">{idr(r.total)}</td>
                                        <td className="text-right">
                                            {can.suspend && r.subscriptions.map((s) => (
                                                <Button key={s.id} variant="secondary" size="sm" onClick={() => setSuspending(s)}>
                                                    Isolir {s.code}
                                                </Button>
                                            ))}
                                        </td>
                                    </tr>
                                ))}
                                {rows.length === 0 && (
                                    <tr><td colSpan={8} className="py-6 text-center text-surface-400">Tidak ada tunggakan 🎉</td></tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>

                {suspending && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                        <Card className="w-full max-w-md">
                            <CardContent className="space-y-4 pt-6">
                                <p>Isolir subscription <strong>{suspending.code}</strong>?</p>
                                <textarea
                                    className="w-full rounded border p-2 text-sm"
                                    placeholder="Alasan isolir (wajib)"
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                />
                                <div className="flex justify-end gap-2">
                                    <Button variant="secondary" onClick={() => setSuspending(null)}>Batal</Button>
                                    <Button onClick={suspend} disabled={!reason.trim()}>Isolir</Button>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                )}
            </div>
        </AdminLayout>
    );
}
```

Add nav link: find the admin sidebar/menu component (`rtk grep "admin.invoices.index" resources/js --include=*.tsx -l` to locate the nav file) and add a "Tunggakan" item pointing to `route('admin.billing.receivables')` next to the Invoices item.

- [ ] **Step 6: Build + full test run**

Run: `rtk npm run build` → succeeds.
Run: `rtk php artisan test` → full suite PASS.

- [ ] **Step 7: Commit**

```bash
rtk git add Modules/Billing resources/js tests/Feature/Billing/ReceivablesTest.php
rtk git commit -m "feat(billing): AR aging (tunggakan) page with isolir shortcut"
```

---

## Post-plan verification

- [ ] `rtk php artisan test` — full suite green.
- [ ] `rtk php artisan billing:generate --dry-run` — table output, no writes.
- [ ] Manual smoke via `/verify` flow: settings page shows billing keys → generate preview → publish → invoice shows `sent` → PDF downloads → receivables page renders.
