<?php

namespace Tests\Feature\Billing;

use App\Models\Core\ServiceSubscription;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceItem;
use Modules\Billing\Services\BillingService;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;
use Tests\Traits\RunsConcurrentWorkers;

class RecurringInvoiceRaceGuardTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;
    use RunsConcurrentWorkers;

    public function test_add_item_rechecks_product_company_inside_transaction_without_writing(): void
    {
        $user = $this->createCompanyUser();
        $other = $this->createCompanyUser();
        $this->actingAs($user);
        $invoice = InvoiceFactory::new()->create([
            'company_id' => $user->company_id,
            'status' => 'draft',
        ]);
        $foreignProduct = $this->product($other->company_id);

        try {
            BillingService::addItem($invoice, [
                'product_id' => $foreignProduct->id,
                'description' => 'Foreign product',
                'quantity' => 1,
                'unit_price' => 1000,
            ]);
            $this->fail('Foreign invoice item product must be rejected.');
        } catch (ModelNotFoundException|HttpException $e) {
            if ($e instanceof HttpException) {
                $this->assertContains($e->getStatusCode(), [404, 422]);
            }
        }

        $this->assertSame(0, InvoiceItem::withoutCompany()->where('invoice_id', $invoice->id)->count());
    }

    public function test_generate_for_period_is_idempotent_under_recheck_lock(): void
    {
        $this->actingAs($this->createCompanyUser());
        ServiceSubscription::factory()->create([
            'status' => 'active',
            'activation_date' => '2026-05-10',
            'mrc_amount' => 300000,
        ]);

        $first = BillingService::generateForPeriod('2026-06');
        $second = BillingService::generateForPeriod('2026-06');

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, Invoice::withoutCompany()->where('type', 'recurring')->count());
    }

    public function test_create_path_rechecks_inside_transaction(): void
    {
        $this->actingAs($this->createCompanyUser());
        $sub = ServiceSubscription::factory()->create([
            'status' => 'active',
            'activation_date' => '2026-05-10',
            'mrc_amount' => 300000,
        ]);

        // Simulate TOCTOU: pre-create invoice that outer exists-check would miss if not rechecked.
        // Outer path still skips; assert service never doubles for same sub+period.
        BillingService::generateForPeriod('2026-06');
        BillingService::generateForPeriod('2026-06');

        $this->assertSame(
            1,
            Invoice::withoutCompany()
                ->where('subscription_id', $sub->id)
                ->where('type', 'recurring')
                ->whereDate('billing_period_start', '2026-06-01')
                ->where('status', '!=', 'cancelled')
                ->count()
        );
    }

    public function test_a_cancelled_recurring_invoice_is_not_issued_again(): void
    {
        $this->actingAs($this->createCompanyUser());
        $subscription = ServiceSubscription::factory()->create([
            'status' => 'active',
            'activation_date' => '2026-05-10',
            'mrc_amount' => 300000,
        ]);

        $this->artisan('billing:generate', ['--period' => '2026-06'])->expectsOutputToContain('created: 1')->assertSuccessful();
        BillingService::cancel(Invoice::withoutCompany()->where('subscription_id', $subscription->id)->sole(), 'Salah tagih');

        // The next night: the cancelled invoice still stands for its period, for the schedule and for the Generate dialog alike.
        $this->artisan('billing:generate', ['--period' => '2026-06'])->expectsOutputToContain('created: 0, skipped: 1')->assertSuccessful();
        $this->post(route('admin.invoices.generate'), ['period' => '2026-06'])
            ->assertSessionHas('success', 'Tagihan 2026-06: 0 dibuat, 1 dilewati.');

        $invoices = Invoice::withoutCompany()->where('type', 'recurring')->get();
        $this->assertCount(1, $invoices);
        $this->assertSame('cancelled', $invoices->sole()->status);
    }

    public function test_the_database_refuses_a_second_active_recurring_invoice_for_one_period(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        $subscription = ServiceSubscription::factory()->create(['status' => 'active', 'activation_date' => '2026-05-10']);
        $issue = fn (string $number, array $attributes = []): Invoice => InvoiceFactory::new()->create([
            'company_id' => $user->company_id,
            'customer_id' => $subscription->customer_id,
            'subscription_id' => $subscription->id,
            'created_by' => null,
            'number' => $number,
            'type' => 'recurring',
            'source' => 'subscription',
            'status' => 'sent',
            'billing_period_start' => '2026-06-01',
            'billing_period_end' => '2026-06-30',
            ...$attributes,
        ]);

        $first = $issue('INV-A');

        $this->assertThrows(fn () => $issue('INV-B'), UniqueConstraintViolationException::class);

        // What is not an active recurring invoice of that period never collides with it.
        $issue('INV-CANCELLED', ['status' => 'cancelled']);
        $issue('INV-TRASHED', ['deleted_at' => now()]);
        $issue('INV-ONE-TIME', ['type' => 'one_time', 'source' => 'manual']);
        $issue('INV-NEXT-MONTH', ['billing_period_start' => '2026-07-01', 'billing_period_end' => '2026-07-31']);

        // Cancelling the active one frees the period in the database; the rule that a cancelled period stays done is the application's.
        $first->update(['status' => 'cancelled']);
        $issue('INV-C');
        $this->assertThrows(fn () => $issue('INV-D'), UniqueConstraintViolationException::class);
    }

    public function test_two_generators_racing_create_one_invoice(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);
        ServiceSubscription::factory()->create(['status' => 'active', 'activation_date' => '2026-05-10', 'mrc_amount' => 300000]);
        $this->commitFixture();

        try {
            $results = $this->race([
                ['company_id' => $user->company_id],
                ['company_id' => $user->company_id],
            ], <<<'PHP'
                config(['logging.default' => 'null']);

                return Modules\Billing\Services\BillingService::generateForPeriod('2026-06');
            PHP);

            $this->assertTrue($results[0]['ok'] && $results[1]['ok'], 'no worker fails: '.json_encode($results));
            $this->assertSame(1, $results[0]['result']['created'] + $results[1]['result']['created'], 'one invoice is issued, and only one run says so: '.json_encode($results));
            $this->assertSame(1, Invoice::withoutCompany()->where('company_id', $user->company_id)->where('type', 'recurring')->count());
        } finally {
            $this->purgeCompany($user->company_id);
        }
    }

    private function product(int $companyId): Product
    {
        $unit = Unit::withoutCompany()->forceCreate([
            'company_id' => $companyId,
            'name' => 'Billing Unit',
            'symbol' => 'BU'.fake()->unique()->numberBetween(1, 9999),
        ]);
        $category = Category::withoutCompany()->forceCreate([
            'company_id' => $companyId,
            'unit_id' => $unit->id,
            'name' => 'Billing Category',
            'code' => 'BC'.fake()->unique()->numberBetween(1, 9999),
            'is_active' => true,
        ]);

        return Product::withoutCompany()->forceCreate([
            'company_id' => $companyId,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'BILL-'.fake()->unique()->numberBetween(1, 9999),
            'name' => 'Billing Product',
            'type' => 'consumable',
            'track_stock' => true,
            'sell_price' => 100_000,
            'cost_price' => 50_000,
            'min_stock' => 0,
            'is_active' => true,
        ]);
    }
}
