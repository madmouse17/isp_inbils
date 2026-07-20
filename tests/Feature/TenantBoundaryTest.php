<?php

namespace Tests\Feature;

use App\Models\Core\Company;
use App\Models\Core\Customer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Modules\Inventory\Models\Category;
use Modules\Inventory\Models\Product;
use Modules\Inventory\Models\Unit;
use Modules\SPK\Models\WorkOrder;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketCategory;
use Tests\TestCase;

class TenantBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Company $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app()['cache']->forget('spatie.permission.cache');

        $company = Company::factory()->create(['is_active' => true]);
        $this->otherCompany = Company::factory()->create(['is_active' => true]);
        $this->admin = User::factory()->create([
            'company_id' => $company->id,
            'email_verified_at' => now(),
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    }

    public function test_customer_detail_hides_other_company_customer(): void
    {
        $customer = Customer::factory()->create(['company_id' => $this->otherCompany->id]);

        $this->actingAs($this->admin)->get(route('admin.customers.show', $customer))->assertNotFound();
    }

    public function test_inventory_detail_hides_other_company_product(): void
    {
        $product = Product::withoutCompany()->forceCreate([
            'company_id' => $this->otherCompany->id,
            'category_id' => Category::withoutCompany()->forceCreate([
                'company_id' => $this->otherCompany->id,
                'name' => 'Other Category',
                'code' => 'OTHER-CAT',
                'is_active' => true,
            ])->id,
            'unit_id' => Unit::withoutCompany()->forceCreate([
                'company_id' => $this->otherCompany->id,
                'name' => 'Other Unit',
                'symbol' => 'OU',
            ])->id,
            'sku' => 'OTHER-SKU',
            'name' => 'Other Product',
            'type' => 'consumable',
            'track_stock' => true,
            'sell_price' => 1000,
            'cost_price' => 500,
            'min_stock' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->get(route('admin.products.show', $product))->assertNotFound();
    }

    public function test_billing_detail_hides_other_company_invoice(): void
    {
        $invoice = Invoice::factory()->create(['company_id' => $this->otherCompany->id]);

        $this->actingAs($this->admin)->get(route('admin.invoices.show', $invoice))->assertNotFound();
    }

    public function test_spk_detail_hides_other_company_work_order(): void
    {
        $workOrder = WorkOrder::withoutCompany()->forceCreate([
            'company_id' => $this->otherCompany->id,
            'code' => 'SPK-XTENANT',
            'type' => 'installation',
            'title' => 'Other work order',
            'status' => 'draft',
            'source' => 'manual',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->get(route('admin.spk.show', $workOrder))->assertNotFound();
    }

    public function test_ticket_detail_hides_other_company_ticket(): void
    {
        $category = TicketCategory::withoutCompany()->forceCreate([
            'company_id' => $this->otherCompany->id,
            'name' => 'Other Support',
            'code' => 'OTHER',
            'default_sla_hours' => 24,
            'default_priority' => 'medium',
            'is_active' => true,
        ]);
        $ticket = Ticket::withoutCompany()->forceCreate([
            'company_id' => $this->otherCompany->id,
            'code' => 'TKT-XTENANT',
            'title' => 'Other ticket',
            'description' => 'Other company ticket',
            'source' => 'manual',
            'category_id' => $category->id,
            'status' => 'open',
            'priority' => 'medium',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->get(route('admin.tickets.show', $ticket))->assertNotFound();
    }
}
