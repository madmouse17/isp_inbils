<?php

namespace Tests\Feature\Billing;

use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Database\Factories\InvoiceFactory;
use Modules\Billing\Models\InvoiceItem;
use Modules\Billing\Services\BillingService;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class MoneySafetyTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    public function test_money_add_avoids_float_rounding_drift(): void
    {
        // Classic float bug: 0.1 + 0.2 !== 0.3 in binary float.
        $this->assertNotSame(0.3, 0.1 + 0.2);
        $this->assertSame('0.30', Money::round(Money::add('0.1', '0.2')));
    }

    public function test_money_rounds_half_up_at_currency_precision(): void
    {
        $this->assertSame('1.01', Money::round('1.005'));
        $this->assertSame('1.00', Money::round('1.004'));
    }

    public function test_recalculate_rounds_each_line_to_whole_rupiah_without_float_drift(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);

        $invoice = InvoiceFactory::new()->create([
            'company_id' => $user->company_id,
            'status' => 'draft',
        ]);

        // Prices that cannot be held in a binary float: 3 x 33333.33 is 99999.99, then 66666.67 on its own line.
        InvoiceItem::create([
            'company_id' => $user->company_id,
            'invoice_id' => $invoice->id,
            'description' => 'line',
            'quantity' => 3,
            'unit_price' => 33333.33,
            'line_total' => 0,
        ]);
        InvoiceItem::create([
            'company_id' => $user->company_id,
            'invoice_id' => $invoice->id,
            'description' => 'line2',
            'quantity' => 1,
            'unit_price' => 66666.67,
            'line_total' => 0,
        ]);

        BillingService::recalculate($invoice);

        $this->assertSame(['100000.00', '66667.00'], $invoice->items()->orderBy('id')->pluck('line_total')->all());
        $this->assertSame('166667.00', (string) $invoice->fresh()->subtotal);
    }

    public function test_invoice_sisa_attribute_tracks_remaining_balance(): void
    {
        $invoice = InvoiceFactory::new()->create([
            'status' => 'partial',
            'total' => '10.10',
            'paid_amount' => '2.55',
        ]);

        $this->assertSame('7.55', $invoice->sisa);
    }

    public function test_recordpayment_rejects_overpay_using_decimal_compare(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);

        $invoice = InvoiceFactory::new()->create([
            'company_id' => $user->company_id,
            'status' => 'sent',
            'total' => '0.29',
            'paid_amount' => 0,
        ]);

        $this->expectException(HttpException::class);

        try {
            // 0.1 + 0.2 becomes 0.30000000000000004 in binary float and must still be rejected.
            BillingService::recordPayment($invoice, 0.1 + 0.2, 'cash');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            throw $e;
        }
    }

    public function test_add_item_rejects_cross_tenant_product_id(): void
    {
        $user = $this->createCompanyUser();
        $otherUser = $this->createCompanyUser();

        // Create the foreign-company product under the other tenant's own company context.
        $this->actingAs($otherUser);
        $unit = Unit::factory()->create(['company_id' => $otherUser->company_id]);
        $category = Category::factory()->create(['company_id' => $otherUser->company_id, 'unit_id' => $unit->id]);
        $foreignProduct = Product::factory()->create([
            'company_id' => $otherUser->company_id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
        ]);

        $this->actingAs($user);

        $invoice = InvoiceFactory::new()->create([
            'company_id' => $user->company_id,
            'status' => 'draft',
        ]);

        $response = $this->post(route('admin.invoices.items.store', $invoice), [
            'product_id' => $foreignProduct->public_id,
            'description' => 'x',
            'quantity' => 1,
            'unit_price' => 10,
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('product_id');
    }
}
