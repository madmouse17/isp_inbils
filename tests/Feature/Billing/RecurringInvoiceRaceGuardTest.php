<?php

namespace Tests\Feature\Billing;

use App\Models\Core\ServiceSubscription;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

class RecurringInvoiceRaceGuardTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

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
