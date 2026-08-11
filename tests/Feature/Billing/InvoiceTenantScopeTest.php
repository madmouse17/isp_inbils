<?php

namespace Tests\Feature\Billing;

use App\Models\Core\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class InvoiceTenantScopeTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    public function test_add_item_rejects_cross_tenant_product_id_with_422(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);

        $otherCompany = Company::factory()->create();

        $otherUnit = Unit::query()->forceCreate([
            'company_id' => $otherCompany->id,
            'name' => 'Other Unit',
            'symbol' => 'OU',
        ]);

        $otherCategory = Category::query()->forceCreate([
            'company_id' => $otherCompany->id,
            'unit_id' => $otherUnit->id,
            'name' => 'Other Category',
            'code' => 'OCG',
            'description' => null,
            'is_active' => true,
        ]);

        $otherProduct = Product::query()->forceCreate([
            'company_id' => $otherCompany->id,
            'category_id' => $otherCategory->id,
            'unit_id' => $otherUnit->id,
            'sku' => 'OTHER-SKU-001',
            'name' => 'Other Product',
            'description' => null,
            'type' => 'consumable',
            'track_stock' => true,
            'sell_price' => 10000,
            'cost_price' => 5000,
            'min_stock' => 0,
            'is_active' => true,
        ]);

        $invoice = Invoice::factory()->create([
            'company_id' => $user->company_id,
            'status' => 'draft',
        ]);

        $response = $this->postJson(route('admin.invoices.items.store', $invoice), [
            'product_id' => $otherProduct->id,
            'description' => 'Cross-tenant item',
            'quantity' => 1,
            'unit_price' => 10000,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->assertDatabaseMissing('invoice_items', [
            'invoice_id' => $invoice->id,
            'product_id' => $otherProduct->id,
        ]);
    }

    public function test_add_item_accepts_same_tenant_product_id(): void
    {
        $user = $this->createCompanyUser();
        $this->actingAs($user);

        $unit = Unit::query()->forceCreate([
            'company_id' => $user->company_id,
            'name' => 'Local Unit',
            'symbol' => 'LU',
        ]);

        $category = Category::query()->forceCreate([
            'company_id' => $user->company_id,
            'unit_id' => $unit->id,
            'name' => 'Local Category',
            'code' => 'LCG',
            'description' => null,
            'is_active' => true,
        ]);

        $product = Product::query()->forceCreate([
            'company_id' => $user->company_id,
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sku' => 'LOCAL-SKU-001',
            'name' => 'Local Product',
            'description' => null,
            'type' => 'consumable',
            'track_stock' => true,
            'sell_price' => 10000,
            'cost_price' => 5000,
            'min_stock' => 0,
            'is_active' => true,
        ]);

        $invoice = Invoice::factory()->create([
            'company_id' => $user->company_id,
            'status' => 'draft',
        ]);

        $response = $this->post(route('admin.invoices.items.store', $invoice), [
            'product_id' => $product->id,
            'description' => 'Same-tenant item',
            'quantity' => 1,
            'unit_price' => 10000,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    }
}
